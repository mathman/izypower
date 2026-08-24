<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';
require_once __DIR__  . '/../../core/php/izypower.inc.php';

class izypower extends eqLogic {

    /* ===================== CRON / SYNCHRO ===================== */

    /**
     * Appelé par le cron toutes les minutes (cf plugin_info/info.json).
     * Parcourt tous les équipements "centrale" actifs et met à jour leurs commandes.
     */
    public static function cron() {
        foreach (self::byType('izypower', true) as $eqLogic) {
            // Seules les centrales (device_type=station) déclenchent un pull complet.
            // Les onduleurs et compteurs sont mis à jour en cascade par pullDevices()
            // depuis leur centrale — les appeler directement provoquerait des erreurs
            // 500 car l'API n'accepte pas leur numéro de série comme ID de centrale.
            $deviceType = $eqLogic->getConfiguration('device_type');
            if ($deviceType === 'inverter' || $deviceType === 'meter') {
                continue;
            }
            try {
                $eqLogic->pull();
            } catch (Exception $e) {
                log::add('izypower', 'error', 'Erreur synchro centrale "' . $eqLogic->getName() . '" : ' . $e->getMessage());
            }
        }
    }

    /**
     * Construit un client API à partir de la configuration du plugin
     * (identifiants Izypower communs à toutes les centrales du compte).
     */
    public static function getApiClient() {
        $username = config::byKey('username', 'izypower');
        $password = config::byKey('password', 'izypower');

        if ($username == '' || $password == '') {
            throw new Exception('Identifiants Izypower non configurés (Plugins > Izypower > Configuration)');
        }

        $lang = (strtolower(substr(config::byKey('language', 'core', 'fr_FR'), 0, 2)) == 'fr') ? 'fr' : 'en';
        return new IzypowerApi($username, $password, $lang);
    }

    /**
     * Récupère la liste des centrales du compte et crée/MAJ les eqLogic correspondants.
     * Appelée depuis la configuration du plugin ("Synchroniser les centrales").
     */
    public static function synchronizeStations() {
        $api = self::getApiClient();
        $result = $api->getStations(1, 100);
        log::add('izypower', 'debug', 'Retour API getStations : ' . json_encode($result));
        $records = isset($result['data']['records']) ? $result['data']['records'] : array();

        $created = 0;
        foreach ($records as $record) {
            $stationId = isset($record['stationsId']) ? $record['stationsId'] : null;
            if ($stationId === null) {
                continue;
            }
            $stationName = isset($record['stationName']) ? $record['stationName'] : ('Centrale ' . $stationId);
            $stationName = $stationName . ' (Izypower)';

            $eqLogic = self::byLogicalId($stationId, 'izypower');
            $isNewStation = !is_object($eqLogic);
            if ($isNewStation) {
                $eqLogic = new izypower();
                $eqLogic->setEqType_name('izypower');
                $eqLogic->setLogicalId($stationId);
                $eqLogic->setIsEnable(1);
                $eqLogic->setIsVisible(1);
                $eqLogic->setConfiguration('device_type', 'station');
                // Le nom n'est posé qu'à la création : un renommage manuel ultérieur
                // par l'utilisateur ne sera plus jamais écrasé par les synchros suivantes.
                $eqLogic->setName($stationName);
                $created++;
            }
            $eqLogic->save();
            $eqLogic->buildStationCommands();

            // Crée les onduleurs/compteurs manquants pour cette centrale.
            try {
                $eqLogic->syncDevices($stationId, $api);
            } catch (Exception $e) {
                log::add('izypower', 'error', 'Erreur synchro équipements de la centrale "' . $eqLogic->getName() . '" : ' . $e->getMessage());
            }
        }

        log::add('izypower', 'info', 'Synchronisation terminée : ' . count($records) . ' centrale(s) trouvée(s), ' . $created . ' créée(s)');
        return count($records);
    }

    /**
     * Crée les eqLogic manquants (onduleurs / compteurs) pour cette centrale et
     * leurs commandes fixes, à partir de la liste des équipements retournée par
     * l'API. Crée aussi les commandes de puissance par chaîne PV manquantes,
     * pour les onduleurs déjà connus comme pour les nouveaux (une nouvelle
     * chaîne PV peut apparaître sur un onduleur existant).
     */
    public function syncDevices($stationId, $api) {
        $devicePage = $api->getDevicePage($stationId, 'all', 1, 100);
        log::add('izypower', 'debug', 'Retour API getDevicePage (centrale ' . $stationId . ') : ' . json_encode($devicePage));
        $deviceRecords = isset($devicePage['data']['records']) ? $devicePage['data']['records'] : array();

        // Noms des chaînes PV par onduleur (pvData), pour créer leurs commandes
        $component = array();
        try {
            $component = $api->getComponent($stationId, date('Y-m-d'));
        } catch (Exception $e) {
            log::add('izypower', 'debug', 'Component indisponible pour centrale ' . $stationId . ' : ' . $e->getMessage());
        }
        $pvDataList = isset($component['pvData']) && is_array($component['pvData']) ? $component['pvData'] : array();

        // Pinces CT (layoutPower) : un seul appel pour toute la centrale, servant
        // à détecter les noms de CT (CT2, CT3, ...) rattachés aux compteurs.
        $ctItems = array();
        try {
            $layoutPower = $api->getLayoutPower($stationId, date('Y-m-d'));
            $ctItems = self::extractLatestLayoutExtra($layoutPower);
        } catch (Exception $e) {
            log::add('izypower', 'debug', 'LayoutPower indisponible pour centrale ' . $stationId . ' : ' . $e->getMessage());
        }

        $created = 0;
        foreach ($deviceRecords as $deviceRecord) {
            $deviceSn = isset($deviceRecord['sn']) ? $deviceRecord['sn'] : (isset($deviceRecord['serialNumber']) ? $deviceRecord['serialNumber'] : null);
            if ($deviceSn === null || $deviceSn === '') {
                continue;
            }

            $deviceType = isset($deviceRecord['deviceType']) ? $deviceRecord['deviceType'] : 'vm';
            $deviceEq = self::byLogicalId($deviceSn, 'izypower');
            $isNewDevice = !is_object($deviceEq);

            // ID interne numérique (deviceId), distinct du numéro de série.
            $numericDeviceId = isset($deviceRecord['deviceId']) ? $deviceRecord['deviceId'] : null;

            if ($isNewDevice) {
                $deviceName = isset($deviceRecord['deviceName']) ? $deviceRecord['deviceName'] : $deviceSn;

                $deviceEq = new izypower();
                $deviceEq->setEqType_name('izypower');
                $deviceEq->setLogicalId($deviceSn);
                $deviceEq->setIsEnable(1);
                $deviceEq->setIsVisible(1);
                $deviceEq->setConfiguration('device_type', $deviceType === 'meter' ? 'meter' : 'inverter');
                $deviceEq->setName($deviceName . ' (Izypower)');
                $deviceEq->setObject_id($this->getObject_id());
                $deviceEq->save();
                $created++;
            }

            if ($numericDeviceId !== null && $deviceEq->getConfiguration('device_id') != $numericDeviceId) {
                $deviceEq->setConfiguration('device_id', $numericDeviceId);
                $deviceEq->save();
            }

            // Commandes fixes communes (online_state, wifi...), et température
            // pour les onduleurs 'vm' uniquement.
            $deviceEq->buildDeviceCommands($deviceType === 'vm');

            if ($deviceType === 'meter') {
                // Noms des pinces CT rattachées à ce compteur (CT2, CT3, ...),
                // transmis à buildMeterCommands() qui crée les commandes CT
                // correspondantes si besoin.
                $ctNames = array();
                foreach ($ctItems as $item) {
                    $itemPv = isset($item['pv']) ? strtolower($item['pv']) : '';
                    if (strpos($itemPv, 'ct') !== 0) {
                        continue;
                    }
                    $itemSn = isset($item['deviceSn']) ? $item['deviceSn'] : (isset($item['deviceSN']) ? $item['deviceSN'] : null);
                    if ($itemSn !== $deviceSn) {
                        continue;
                    }
                    $ctNames[] = strtoupper($itemPv);
                }
                // Rappelée à chaque synchro (idempotente) pour détecter une
                // pince CT apparaissant après coup sur un compteur existant.
                $deviceEq->buildMeterCommands($ctNames);
            } else {
                $pvNames = array();
                foreach ($pvDataList as $pvData) {
                    if ((isset($pvData['sn']) ? $pvData['sn'] : null) !== $deviceSn) {
                        continue;
                    }
                    $pvNames[] = isset($pvData['pv']) ? strtoupper($pvData['pv']) : 'PV';
                }
                $deviceEq->buildPvCommands($pvNames);
            }
        }

        if ($created > 0) {
            log::add('izypower', 'info', $created . ' équipement(s) créé(s) pour la centrale "' . $this->getName() . '"');
        }
    }

    /* ===================== COMMANDES (CAPTEURS) ===================== */

    /**
     * Définition des capteurs de puissance instantanée (champs directs de station_info).
     * clé logique => [field_name dans station_info, libellé, unité]
     */
    public static function getPowerFields() {
        return array(
            'production_power'  => array('power',             'Puissance Production PV',   'W'),
            'grid_power'        => array('grid_power',         'Puissance Réseau',          'W'),
            'consumption_power' => array('consumption',        'Puissance Consommation',    'W'),
        );
    }

    /**
     * Définition des capteurs d'énergie (issus du report : timeType + field_name).
     * clé logique => [timeType, field_name dans report, libellé]
     */
    public static function getEnergyFields() {
        return array(
            // Production / consommation totale
            'consumption_day'         => array('day',   'total_consumption', 'Consommation Jour'),
            'consumption_month'       => array('month', 'total_consumption', 'Consommation Mois'),
            'consumption_year'        => array('year',  'total_consumption', 'Consommation Année'),
            'consumption_total'       => array('all',   'total_consumption', 'Consommation Total'),

            // Consommation depuis PV
            'consumption_from_pv_day'   => array('day',   'consumption', 'Consommation depuis PV Jour'),
            'consumption_from_pv_month' => array('month', 'consumption', 'Consommation depuis PV Mois'),
            'consumption_from_pv_year'  => array('year',  'consumption', 'Consommation depuis PV Année'),
            'consumption_from_pv_total' => array('all',   'consumption', 'Consommation depuis PV Total'),

            // Réseau import / export
            'grid_day_import'    => array('day',   'meter_energy_p', 'Import Réseau Jour'),
            'grid_day_export'    => array('day',   'meter_energy_n', 'Export Réseau Jour'),
            'grid_month_import'  => array('month', 'meter_energy_p', 'Import Réseau Mois'),
            'grid_month_export'  => array('month', 'meter_energy_n', 'Export Réseau Mois'),
            'grid_year_import'   => array('year',  'meter_energy_p', 'Import Réseau Année'),
            'grid_year_export'   => array('year',  'meter_energy_n', 'Export Réseau Année'),
            'grid_total_import'  => array('all',   'meter_energy_p', 'Import Réseau Total'),
            'grid_total_export'  => array('all',   'meter_energy_n', 'Export Réseau Total'),
        );
    }

    /**
     * Définition des informations "diagnostic" de la centrale (champs directs
     * de station_info > data, ex. capacité installée, statut, dernière mise à jour).
     * clé logique => [field_name dans station_info['data'], libellé, unité|null]
     */
    public static function getStationInfoFields() {
        return array(
            'installed_capacity' => array('installedCapacity', 'Capacité Installée',      'W'),
            'device_count'       => array('deviceCount',       'Nombre d\'Équipements',    null),
            'station_status'     => array('status',            'Statut',                  null),
            'last_update'        => array('lastUpdate',        'Dernière Mise à Jour',    null),
        );
    }

    /**
     * Taux/ratios calculés par l'API et renvoyés directement dans le rapport
     * (getReport), à la racine de la réponse comme les champs d'énergie.
     * clé logique => [timeType, field_name dans report, libellé].
     */
    public static function getRateFields() {
        $result = array();
        $ratios = array(
            'cover_rate'          => 'cover_rate',
            'consumption_rate'    => 'consumption_rate',
            'grid_import_rate'    => 'meter_energy_p_rate',
            'grid_export_rate'    => 'meter_energy_n_rate',
        );
        $labels = array(
            'cover_rate'       => 'Taux de Couverture PV',
            'consumption_rate' => 'Taux Consommation depuis PV',
            'grid_import_rate' => 'Taux Import Réseau',
            'grid_export_rate' => 'Taux Export Réseau',
        );
        $periods = array(
            'day'   => array('day',   'Jour'),
            'month' => array('month', 'Mois'),
            'year'  => array('year',  'Année'),
            'all'   => array('total', 'Total'),
        );
        foreach ($ratios as $prefix => $field) {
            foreach ($periods as $timeType => $periodDef) {
                list($suffix, $periodLabel) = $periodDef;
                $result[$prefix . '_' . $suffix] = array($timeType, $field, $labels[$prefix] . ' ' . $periodLabel);
            }
        }
        return $result;
    }

    /**
     * Crée les commandes manquantes pour cet eqLogic (idempotent).
     */
    public function buildStationCommands() {
        foreach (self::getPowerFields() as $logicalId => $def) {
            list($field, $label, $unit) = $def;
            $this->buildSensorCommand($logicalId, $label, $unit, 'power');
        }

        foreach (self::getEnergyFields() as $logicalId => $def) {
            list($timeType, $field, $label) = $def;
            $this->buildSensorCommand($logicalId, $label, 'kWh', 'energy');
        }

        foreach (self::getStationInfoFields() as $logicalId => $def) {
            list($field, $label, $unit) = $def;
            $isString = ($logicalId !== 'installed_capacity');
            $this->buildSensorCommand($logicalId, $label, $unit, 'info', $isString);
        }

        foreach (self::getRateFields() as $logicalId => $def) {
            list($timeType, $field, $label) = $def;
            $this->buildSensorCommand($logicalId, $label, '%', 'info');
        }

        $this->buildSensorCommand('upgrade_available', 'Mise à Jour Disponible', null, 'info');

        $this->save();
    }

    /* ===================== COMMANDES ONDULEUR (équipement device_type=inverter) ===================== */

    /**
     * Définition des commandes fixes (hors PV, créées dynamiquement) pour un onduleur.
     * clé logique => [libellé, unité|null, type Jeedom: 'string'|'numeric']
     */
    public static function getDeviceFields() {
        return array(
            'online_state'      => array('En Ligne',                null,   'numeric'),
            'software_version'  => array('Version Logicielle',      null,   'string'),
            'wifi_signal'       => array('Signal Wi-Fi',             'dBm',  'numeric'),
            'wifi_network'      => array('Réseau Wi-Fi',             null,   'string'),
            'ip_address'        => array('Adresse IP',               null,   'string'),
            'upgrade_available' => array('Mise à Jour Disponible',   null,   'numeric'),
        );
    }

    /**
     * Crée/met à jour les commandes fixes communes à tous les équipements
     * (onduleurs et compteurs) : online_state, software_version, wifi...
     * (idempotent). $includeTemperature n'est à true que pour les onduleurs
     * de type 'vm' (seuls à remonter une température).
     */
    public function buildDeviceCommands($includeTemperature = false) {
        foreach (self::getDeviceFields() as $logicalId => $def) {
            list($label, $unit, $type) = $def;
            $genericType = ($logicalId === 'online_state') ? 'PRESENCE' : null;
            $this->buildSensorCommand($logicalId, $label, $unit, 'info', $type !== 'numeric', $genericType);
        }
        if ($includeTemperature) {
            $this->buildSensorCommand('temperature', 'Température', '°C', 'info', false, 'TEMPERATURE');
        }
        $this->save();
    }

    /**
     * Crée/met à jour une commande de puissance par chaîne PV détectée (PV1, PV2, ...).
     */
    public function buildPvCommands($pvNames) {
        foreach ($pvNames as $pvName) {
            $logicalId = 'pv_power_' . strtolower($pvName);
            $this->buildSensorCommand($logicalId, 'Puissance ' . $pvName, 'W', 'info');
        }
        if (!empty($pvNames)) {
            $this->save();
        }
    }

    /* ===================== COMMANDES COMPTEURS (équipement device_type=meter) ===================== */

    /**
     * Crée les commandes spécifiques à un équipement compteur (meter) :
     * tension, fréquence, énergie +/-, contrôle d'injection réseau.
     * Crée aussi, le cas échéant, les commandes de pince CT détectées sur ce compteur.
     */
    public function buildMeterCommands($ctNames = array()) {
        // Commandes spécifiques compteur.
        $meterCmds = array(
            'meter_voltage'           => array('Tension Réseau',            'V',   'numeric'),
            'meter_frequency'         => array('Fréquence Réseau',          'Hz',  'numeric'),
            'meter_energy_p'          => array('Énergie Importée',          'kWh', 'numeric'),
            'meter_energy_n'          => array('Énergie Exportée',          'kWh', 'numeric'),
            'meter_power'             => array('Puissance Réseau',          'W',   'numeric'),
            'injection_control_state' => array('Contrôle Injection Actif',  null,  'numeric'),
            'injection_limit_state'   => array('Seuil Injection Autorisé',  'W',   'numeric'),
        );
        foreach ($meterCmds as $logicalId => $def) {
            list($label, $unit) = $def;
            $this->buildSensorCommand($logicalId, $label, $unit, 'info');
        }

        $this->buildActionCommand('injection_control_on', 'Activer le Contrôle d\'Injection', 'other');
        $this->buildActionCommand('injection_control_off', 'Désactiver le Contrôle d\'Injection', 'other');
        $this->buildActionCommand('injection_limit_set', 'Seuil d\'Injection Réseau', 'slider', 'W', 0, 36000, 50);

        if (!empty($ctNames)) {
            foreach ($ctNames as $ctName) {
                $logicalId = 'ct_power_' . strtolower($ctName);
                $this->buildSensorCommand($logicalId, 'Puissance ' . strtoupper($ctName), 'W', 'info');
            }
        }
        $this->save();
    }

    /**
     * Crée/met à jour une commande capteur (idempotent). Fonction générique
     * utilisée par tous les points du plugin qui créent des commandes 'info'
     * (station, onduleur, compteur, PV, CT...).
     */
    private function buildSensorCommand($logicalId, $name, $unit, $generic_type_suffix, $isString = false, $genericType = null) {
        $cmd = $this->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            $cmd = new izypowerCmd();
            $cmd->setLogicalId($logicalId);
            $cmd->setEqLogic_id($this->getId());
            $cmd->setName($name);
        }
        $cmd->setType('info');
        $cmd->setSubType($isString ? 'string' : 'numeric');
        $cmd->setUnite($unit);
        $cmd->setIsVisible(1);

        // Génériques Jeedom standards quand pertinents (pour compatibilité
        // avec les widgets et le tableau de bord énergie de Jeedom)
        if ($genericType !== null) {
            $cmd->setGeneric_type($genericType);
        } elseif ($logicalId === 'production_power') {
            $cmd->setGeneric_type('POWER');
        } elseif (in_array($logicalId, array('consumption_total', 'grid_total_import', 'grid_total_export'))) {
            $cmd->setGeneric_type('CONSO_TOTAL');
        }

        $cmd->save();
        return $cmd;
    }

    /**
     * Crée une commande action (idempotent). Pour les sliders, min/max/step
     * sont posés en configuration de la commande (lus par le widget standard
     * Jeedom pour afficher le curseur).
     */
    private function buildActionCommand($logicalId, $name, $subType, $unit = null, $min = null, $max = null, $step = null) {
        $cmd = $this->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            $cmd = new izypowerCmd();
            $cmd->setLogicalId($logicalId);
            $cmd->setEqLogic_id($this->getId());
            $cmd->setName($name);
        }
        $cmd->setType('action');
        $cmd->setSubType($subType);
        if ($unit !== null) {
            $cmd->setUnite($unit);
        }
        $cmd->setIsVisible(1);
        if ($subType === 'slider') {
            $cmd->setConfiguration('minValue', $min);
            $cmd->setConfiguration('maxValue', $max);
            $cmd->setConfiguration('step', $step);
        }
        $cmd->save();
        return $cmd;
    }

    /* ===================== SYNCHRO DES VALEURS ===================== */

    /**
     * Récupère les données à jour pour CETTE centrale et met à jour les commandes.
     * Ne fait rien si appelé sur un équipement onduleur (device_type = 'inverter') :
     * ces équipements sont mis à jour en cascade par pullDevices() de leur centrale.
     */
    public function pull() {
        $deviceType = $this->getConfiguration('device_type');
        if ($deviceType === 'inverter' || $deviceType === 'meter') {
            log::add('izypower', 'debug', 'pull() ignoré pour "' . $this->getName() . '" (type=' . $deviceType . ', mis à jour via sa centrale)');
            return;
        }

        $api = self::getApiClient();
        $stationId = $this->getLogicalId();

        // 1) Puissances instantanées + battery_soc (champs à la racine de la réponse,
        // PAS dans station_info['data'] qui ne contient que les métadonnées de la centrale)
        $stationInfo = $api->getStationInfo($stationId);
        log::add('izypower', 'debug', 'Retour API getStationInfo (centrale ' . $stationId . ') : ' . json_encode($stationInfo));
        $info = is_array($stationInfo) ? $stationInfo : array();

        foreach (self::getPowerFields() as $logicalId => $def) {
            $field = $def[0];
            $value = isset($info[$field]) ? $info[$field] : 0;
            $this->updateCmdValue($logicalId, $value);
        }

        // Infos diagnostic de la centrale : ces champs sont dans la sous-clé
        // 'data' de la réponse (métadonnées), contrairement aux puissances ci-dessus.
        $stationData = isset($info['data']) && is_array($info['data']) ? $info['data'] : array();
        foreach (self::getStationInfoFields() as $logicalId => $def) {
            $field = $def[0];
            $value = isset($stationData[$field]) ? $stationData[$field] : null;
            $this->updateCmdValue($logicalId, $value);
        }

        // 2) Rapports d'énergie (day / month / year / all)
        // Les totaux (total_consumption, meter_energy_p, storage_in, etc.) sont
        // à la racine de la réponse ; 'data' ne contient que les séries temporelles
        // (courbes de production), qu'on n'utilise pas ici.
        $searchTimes = array(
            'day'   => date('Y-m-d'),
            'month' => date('Y-m'),
            'year'  => date('Y'),
            'all'   => date('Y-m-d'),
        );

        $reports = array();
        foreach (array('day', 'month', 'year', 'all') as $timeType) {
            try {
                $report = $api->getReport($stationId, $timeType, $searchTimes[$timeType]);
                log::add('izypower', 'debug', 'Retour API getReport (centrale ' . $stationId . ', timeType=' . $timeType . ') : ' . json_encode($report));
                $reports[$timeType] = is_array($report) ? $report : array();
            } catch (Exception $e) {
                log::add('izypower', 'debug', 'Rapport ' . $timeType . ' indisponible pour centrale ' . $stationId . ' : ' . $e->getMessage());
                $reports[$timeType] = array();
            }
        }

        foreach (self::getEnergyFields() as $logicalId => $def) {
            list($timeType, $field) = $def;
            $value = isset($reports[$timeType][$field]) ? $reports[$timeType][$field] : 0;
            $this->updateCmdValue($logicalId, $value);
        }

        // Taux/ratios renvoyés directement par l'API (mêmes rapports que ci-dessus)
        foreach (self::getRateFields() as $logicalId => $def) {
            list($timeType, $field) = $def;
            $value = isset($reports[$timeType][$field]) ? $reports[$timeType][$field] : null;
            $this->updateCmdValue($logicalId, self::extractRateValue($value));
        }

        $productionPower = isset($info['power']) ? $info['power'] : 0;
        log::add('izypower', 'info', 'Centrale "' . $this->getName() . '" mise à jour : production ' . $productionPower . ' W');

        // 3) Onduleurs / équipements individuels de cette centrale
        try {
            $this->pullDevices($api, $stationId, $info);
        } catch (Exception $e) {
            log::add('izypower', 'error', 'Erreur synchro onduleurs de la centrale "' . $this->getName() . '" : ' . $e->getMessage());
        }
		$this->refreshWidget();
    }

    /**
     * Récupère la liste des onduleurs de cette centrale et met à jour les valeurs
     * de l'équipement correspondant à chaque onduleur/compteur déjà
     * existant (Wi-Fi, état en ligne, puissance par chaîne PV).
     */
    private function pullDevices($api, $stationId, $stationInfo = array()) {
        $devicePage = $api->getDevicePage($stationId, 'all', 1, 100);
        log::add('izypower', 'debug', 'Retour API getDevicePage (centrale ' . $stationId . ') : ' . json_encode($devicePage));
        $deviceRecords = isset($devicePage['data']['records']) ? $devicePage['data']['records'] : array();

        // Puissances par chaîne PV (pvData), pour les onduleurs uniquement
        $component = array();
        try {
            $component = $api->getComponent($stationId, date('Y-m-d'));
            log::add('izypower', 'debug', 'Retour API getComponent (centrale ' . $stationId . ') : ' . json_encode($component));
        } catch (Exception $e) {
            log::add('izypower', 'debug', 'Component indisponible pour centrale ' . $stationId . ' : ' . $e->getMessage());
        }
        $pvDataList = isset($component['pvData']) && is_array($component['pvData']) ? $component['pvData'] : array();

        // Mises à jour disponibles : un seul appel pour toute la centrale,
        // réutilisé pour l'agrégat centrale et pour chaque équipement.
        $upgradeEntries = array();
        try {
            $upgradeData = $api->getDeviceUpgrade($stationId);
            log::add('izypower', 'debug', 'Retour API getDeviceUpgrade (centrale ' . $stationId . ') : ' . json_encode($upgradeData));
            $upgradeEntries = isset($upgradeData['data']) && is_array($upgradeData['data']) ? $upgradeData['data'] : array();
        } catch (Exception $e) {
            log::add('izypower', 'debug', 'Mises à jour indisponibles pour centrale ' . $stationId . ' : ' . $e->getMessage());
        }

        $stationUpgradeAvailable = 0;
        foreach ($upgradeEntries as $entry) {
            if (!empty($entry['needUpgrade'])) {
                $stationUpgradeAvailable = 1;
                break;
            }
        }
        $this->updateCmdValue('upgrade_available', $stationUpgradeAvailable);

        // Capteurs CT (pinces ampèremétriques) : dernière ligne exploitable
        // du rapport layoutPower du jour, un seul appel pour toute la centrale.
        $ctItems = array();
        try {
            $layoutPower = $api->getLayoutPower($stationId, date('Y-m-d'));
            log::add('izypower', 'debug', 'Retour API getLayoutPower (centrale ' . $stationId . ') : ' . json_encode($layoutPower));
            $ctItems = self::extractLatestLayoutExtra($layoutPower);
        } catch (Exception $e) {
            log::add('izypower', 'debug', 'LayoutPower indisponible pour centrale ' . $stationId . ' : ' . $e->getMessage());
        }

        foreach ($deviceRecords as $deviceRecord) {
            $deviceSn = isset($deviceRecord['sn']) ? $deviceRecord['sn'] : (isset($deviceRecord['serialNumber']) ? $deviceRecord['serialNumber'] : null);
            if ($deviceSn === null || $deviceSn === '') {
                continue;
            }
            $deviceType  = isset($deviceRecord['deviceType']) ? $deviceRecord['deviceType'] : 'vm';
            $onlineState = isset($deviceRecord['onlineState']) ? $deviceRecord['onlineState'] : 0;
            $swVersion   = isset($deviceRecord['softwareVersion']) ? $deviceRecord['softwareVersion'] : null;
            $onlineLabel = ($onlineState == 1) ? 'en ligne' : 'hors ligne';

            $deviceEq = self::byLogicalId($deviceSn, 'izypower');
            if (!is_object($deviceEq)) {
                log::add('izypower', 'debug', 'Équipement ' . $deviceSn . ' inconnu, ignoré (relancez "Synchroniser les centrales" pour le créer)');
                continue;
            }

            $deviceEq->updateCmdValue('online_state', ($onlineState == 1) ? 1 : 0);
            $deviceEq->updateCmdValue('software_version', $swVersion);

            // Wi-Fi (commun à tous les types)
            try {
                $wifi = $api->getDeviceWifi($deviceSn);
                log::add('izypower', 'debug', 'Retour API getDeviceWifi (sn=' . $deviceSn . ') : ' . json_encode($wifi));
                $wifiData = is_array($wifi) ? $wifi : array();
                if (isset($wifiData['data']) && is_array($wifiData['data'])) {
                    $wifiData = $wifiData['data'];
                }
                $deviceEq->updateCmdValue('wifi_signal', isset($wifiData['rssi']) ? $wifiData['rssi'] : null);
                $deviceEq->updateCmdValue('wifi_network', isset($wifiData['wifi']) ? $wifiData['wifi'] : null);
                $deviceEq->updateCmdValue('ip_address', isset($wifiData['ip']) ? $wifiData['ip'] : null);
            } catch (Exception $e) {
                log::add('izypower', 'debug', 'Wi-Fi indisponible pour ' . $deviceSn . ' : ' . $e->getMessage());
            }

            // Mise à jour disponible pour cet équipement précis
            $deviceUpgradeAvailable = 0;
            foreach ($upgradeEntries as $entry) {
                $entrySn = isset($entry['sn']) ? $entry['sn'] : null;
                if ($entrySn === $deviceSn && !empty($entry['needUpgrade'])) {
                    $deviceUpgradeAvailable = 1;
                    break;
                }
            }
            $deviceEq->updateCmdValue('upgrade_available', $deviceUpgradeAvailable);

            if ($deviceType === 'meter') {
                // Capteurs CT rattachés à ce compteur (ct2, ct3, ...).
                $ctPowers = array();
                foreach ($ctItems as $item) {
                    $itemPv = isset($item['pv']) ? strtolower($item['pv']) : '';
                    if (strpos($itemPv, 'ct') !== 0) {
                        continue;
                    }
                    $itemSn = isset($item['deviceSn']) ? $item['deviceSn'] : (isset($item['deviceSN']) ? $item['deviceSN'] : null);
                    if ($itemSn !== $deviceSn) {
                        continue;
                    }
                    $ctPowers[strtoupper($itemPv)] = isset($item['dataVal']) ? $item['dataVal'] : 0;
                }
                if (!empty($ctPowers)) {
                    $deviceEq->updateCtValues($ctPowers);
                }

                // Valeurs spécifiques compteur depuis dataDtos
                $dataDtos = isset($deviceRecord['dataDtos']) && is_array($deviceRecord['dataDtos']) ? $deviceRecord['dataDtos'] : array();
                $deviceEq->updateMeterValues($dataDtos);
                // Puissance réseau instantanée depuis getStationInfo (grid_power)
                $gridPower = isset($stationInfo['grid_power']) ? $stationInfo['grid_power'] : null;
                if ($gridPower !== null) {
                    $deviceEq->updateCmdValue('meter_power', $gridPower);
                }

                // État du contrôle d'injection réseau (anti-retour), lu via
                // l'ID interne de l'équipement (deviceId).
                $meterDeviceId = $deviceEq->getConfiguration('device_id');
                if ($meterDeviceId) {
                    try {
                        $baseInfo = $api->getMeterBaseInfo($meterDeviceId);
                        log::add('izypower', 'debug', 'Retour API getMeterBaseInfo (device ' . $meterDeviceId . ') : ' . json_encode($baseInfo));
                        $meterExtra = self::extractMeterExtra($baseInfo);
                        $deviceEq->updateCmdValue('injection_control_state', !empty($meterExtra['isControl']) ? 1 : 0);
                        if (isset($meterExtra['feedThreshold']) && is_numeric($meterExtra['feedThreshold'])) {
                            $deviceEq->updateCmdValue('injection_limit_state', abs(intval($meterExtra['feedThreshold'])));
                        }
                    } catch (Exception $e) {
                        log::add('izypower', 'debug', 'Contrôle injection indisponible pour ' . $deviceSn . ' : ' . $e->getMessage());
                    }
                }

                log::add('izypower', 'info', 'Compteur "' . $deviceEq->getName() . '" mis à jour : ' . $onlineLabel);
            } else {
                // Puissance par chaîne PV pour les onduleurs
                $pvPowers = array();
                foreach ($pvDataList as $pvData) {
                    if ((isset($pvData['sn']) ? $pvData['sn'] : null) !== $deviceSn) {
                        continue;
                    }
                    $pvName  = isset($pvData['pv']) ? strtoupper($pvData['pv']) : 'PV';
                    $pvPower = isset($pvData['pvPower']) ? $pvData['pvPower'] : 0;
                    $pvPowers[$pvName] = $pvPower;
                }
                $deviceEq->updatePvValues($pvPowers);
                $totalPvPower = array_sum($pvPowers);
                log::add('izypower', 'info', 'Onduleur "' . $deviceEq->getName() . '" mis à jour : ' . $onlineLabel . ', ' . $totalPvPower . ' W (PV cumulé)');

                // Seulement si l'équipement est en ligne (sinon l'API ne renvoie rien).
                if ($deviceType === 'vm' && $onlineState == 1) {
                    try {
                        $temp = $api->getDeviceTemp($deviceSn, date('Y-m-d'));
                        log::add('izypower', 'debug', 'Retour API getDeviceTemp (sn=' . $deviceSn . ') : ' . json_encode($temp));
                        $deviceEq->updateCmdValue('temperature', self::extractLastTempValue($temp));
                    } catch (Exception $e) {
                        log::add('izypower', 'debug', 'Température indisponible pour ' . $deviceSn . ' : ' . $e->getMessage());
                    }
                }
            }
			$deviceEq->refreshWidget();
        }
    }
    
    /**
     * Met à jour la valeur des commandes de puissance par chaîne PV déjà
     * existantes : appelée depuis pullDevices() à chaque cron.
     * $pvPowers est un tableau ['PV1' => 350, 'PV2' => 280, ...].
     */
    public function updatePvValues($pvPowers) {
        foreach ($pvPowers as $pvName => $pvPower) {
            $this->updateCmdValue('pv_power_' . strtolower($pvName), $pvPower);
        }
    }

    /**
     * Met à jour la valeur des commandes de pince CT déjà existantes.
     * $ctPowers est un tableau ['CT2' => 120, 'CT3' => -45, ...].
     */
    public function updateCtValues($ctPowers) {
        foreach ($ctPowers as $ctName => $ctPower) {
            $this->updateCmdValue('ct_power_' . strtolower($ctName), $ctPower);
        }
    }

    /**
     * Met à jour les commandes spécifiques du compteur depuis les dataDtos
     * (tableau de {key, value} renvoyé dans le device_record).
     * Les valeurs sont des strings avec unité ("58.5V", "50.01Hz", "0.93kWh") —
     * on extrait la partie numérique.
     */
    public function updateMeterValues($dataDtos) {
        // Mapping clé dataDtos => logicalId de commande
        $keyMap = array(
            'v_ac_all'     => 'meter_voltage',
            'freq'         => 'meter_frequency',
            'energy_p_all' => 'meter_energy_p',
            'energy_n_all' => 'meter_energy_n',
        );
        foreach ($dataDtos as $dto) {
            $key   = isset($dto['key'])   ? $dto['key']   : null;
            $value = isset($dto['value']) ? $dto['value'] : null;
            if ($key === null || $value === null || !isset($keyMap[$key])) {
                continue;
            }
            // Extraire la valeur numérique (ex: "58.5V" → 58.5, "0.93kWh" → 0.93)
            $numericValue = floatval(preg_replace('/[^0-9.\-]/', '', $value));
            $this->updateCmdValue($keyMap[$key], $numericValue);
        }
    }

    protected function updateCmdValue($logicalId, $value) {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) {
            $cmd->event($value);
        }
    }

    /**
     * Normalise une valeur de taux/ratio renvoyée par l'API : parfois une
     * chaîne suffixée par '%' (ex. "42.5%"), parfois un nombre. Retourne un
     * float arrondi, ou null si absent/non numérique.
     */
    private static function extractRateValue($value) {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = rtrim($value, '%');
        }
        return is_numeric($value) ? round(floatval($value), 2) : null;
    }

    /**
     * Extrait le dernier noeud 'extra' non vide de la réponse getLayoutPower()
     * (data['data'][n]['extra'][]), en partant de la fin (lignes les plus
     * récentes du rapport journalier). C'est ce noeud qui contient les
     * mesures des pinces CT (pv='ct2'/'ct3', deviceSn, dataVal).
     */
    private static function extractLatestLayoutExtra($layoutPower) {
        $rows = isset($layoutPower['data']['data']) && is_array($layoutPower['data']['data']) ? $layoutPower['data']['data'] : array();
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $extra = isset($rows[$i]['extra']) ? $rows[$i]['extra'] : array();
            if (is_array($extra) && !empty($extra)) {
                return $extra;
            }
        }
        return array();
    }

    /**
     * Extrait le noeud data.meter_extra de la réponse getMeterBaseInfo()
     * (isControl / feedThreshold), qui décrit l'état du contrôle d'injection
     * réseau du compteur.
     */
    private static function extractMeterExtra($baseInfo) {
        $data = isset($baseInfo['data']) && is_array($baseInfo['data']) ? $baseInfo['data'] : array();
        return isset($data['meter_extra']) && is_array($data['meter_extra']) ? $data['meter_extra'] : array();
    }

    /**
     * Extrait la dernière valeur de température de la réponse
     * getDeviceTemp() : data['data'][0]['data'][-1]['val'].
     * Retourne null si la structure est absente/vide (ex. équipement hors ligne).
     */
    private static function extractLastTempValue($temp) {
        $outer = is_array($temp) && isset($temp['data']) && is_array($temp['data']) ? $temp['data'] : array();
        if (empty($outer) || !isset($outer[0]['data']) || !is_array($outer[0]['data'])) {
            return null;
        }
        $inner = $outer[0]['data'];
        if (empty($inner)) {
            return null;
        }
        $last = end($inner);
        return isset($last['val']) ? $last['val'] : null;
    }

    /**
     * Active/désactive le contrôle d'injection réseau (anti-retour) de ce
     * compteur et fixe le seuil d'injection autorisé.
     */
    public function setMeterInjectionControl($isControl, $requestedLimit = null) {
        $serialNumber = $this->getLogicalId();

        if ($requestedLimit !== null) {
            $requestedLimit = abs(intval($requestedLimit));
        } else {
            $limitStateCmd = $this->getCmd(null, 'injection_limit_state');
            $requestedLimit = (is_object($limitStateCmd) && is_numeric($limitStateCmd->execCmd())) ? abs(intval($limitStateCmd->execCmd())) : 300;
        }

        $api = self::getApiClient();
        // feedThreshold est négatif côté API (puissance exportée autorisée).
        $api->setMeterControl($serialNumber, $isControl, -$requestedLimit);
        log::add('izypower', 'info', 'Contrôle injection réseau "' . $this->getName() . '" : actif=' . ($isControl ? 'oui' : 'non') . ', seuil=' . $requestedLimit . ' W');

        $this->updateCmdValue('injection_control_state', $isControl ? 1 : 0);
        $this->updateCmdValue('injection_limit_state', $requestedLimit);
    }

    /* ===================== WIDGET DASHBOARD ===================== */

    /**
     * Génère le widget dashboard. Le rendu diffère selon device_type
     * (station / inverter / meter), chacun avec son propre fichier
     * core/template/dashboard/<station|inverter|meter>.html.
     */
    public function toHtml($_version = 'dashboard') {
        if ($this->getIsEnable() != 1) {
            return '';
        }

        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }

        $version = jeedom::versionAlias($_version);
        $deviceType = $this->getConfiguration('device_type', 'station');

        $get = function ($logicalId, $default = '-') {
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                return $default;
            }
            $val = $cmd->execCmd();
            return ($val === '' || $val === null) ? $default : $val;
        };

        $hist = function ($logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                return array('id' => '0', 'class' => '');
            }
            return array(
                'id' => $cmd->getId(),
                'class' => ($cmd->getIsHistorized() == 1 ? 'cursor history' : ''),
            );
        };

        $replace['#name_display#'] = $this->getName();

        /* ---------- STATION ---------- */
        if ($deviceType === 'station') {
            $numericFields = array_merge(array_keys(self::getPowerFields()), array_keys(self::getEnergyFields()));
            foreach ($numericFields as $k) {
                $val = $get($k, 0);
                $decimals = in_array($k, array('production_power', 'grid_power', 'consumption_power')) ? 0 : 1;
                $replace['#' . $k . '#'] = is_numeric($val) ? round(floatval($val), $decimals) : $val;
                $h = $hist($k);
                $replace['#' . $k . '_id#'] = $h['id'];
                $replace['#' . $k . '_history_class#'] = $h['class'];
            }

            foreach (array_keys(self::getStationInfoFields()) as $k) {
                $replace['#' . $k . '#'] = $get($k, '-');
                $h = $hist($k);
                $replace['#' . $k . '_id#'] = $h['id'];
                $replace['#' . $k . '_history_class#'] = $h['class'];
            }

            foreach (array_keys(self::getRateFields()) as $k) {
                $val = $get($k, null);
                $replace['#' . $k . '#'] = is_numeric($val) ? round(floatval($val), 1) : '-';
                $h = $hist($k);
                $replace['#' . $k . '_id#'] = $h['id'];
                $replace['#' . $k . '_history_class#'] = $h['class'];
            }

            // Ratio de couverture PV du jour : valeur officielle de l'API si
            // disponible (cover_rate_day), sinon repli sur un calcul local.
            $coverRateDay = $get('cover_rate_day', null);
            if (is_numeric($coverRateDay)) {
                $replace['#autoconso_pct#'] = min(100, round(floatval($coverRateDay)));
            } else {
                $consDay = floatval($get('consumption_day', 0));
                $consPvDay = floatval($get('consumption_from_pv_day', 0));
                $replace['#autoconso_pct#'] = ($consDay > 0) ? min(100, round(($consPvDay / $consDay) * 100)) : 0;
            }

            // Le réseau importe ou exporte actuellement ? (grid_power > 0 = import, classique sur ce type d'API)
            $gridPower = floatval($get('grid_power', 0));
            $replace['#grid_label#'] = ($gridPower >= 0) ? 'Import réseau' : 'Export réseau';
            $replace['#grid_class#'] = ($gridPower >= 0) ? 'izy-grid-import' : 'izy-grid-export';

            $producing = floatval($get('production_power', 0)) > 0;
            $replace['#sun_class#'] = $producing ? 'izy-sun-active' : '';

            $replace['#upgrade_badge#'] = (intval($get('upgrade_available', 0)) == 1) ? ' · ⬆️ MàJ dispo' : '';

            return template_replace($replace, getTemplate('core', $version, 'station', 'izypower'));
        }

        /* ---------- METER ---------- */
        if ($deviceType === 'meter') {
            foreach (array('online_state', 'software_version', 'wifi_signal', 'wifi_network', 'ip_address',
                            'meter_voltage', 'meter_frequency', 'meter_energy_p', 'meter_energy_n', 'meter_power') as $k) {
                $replace['#' . $k . '#'] = $get($k, ($k === 'online_state') ? 0 : '-');
                $h = $hist($k);
                $replace['#' . $k . '_id#'] = $h['id'];
                $replace['#' . $k . '_history_class#'] = $h['class'];
            }

            $online = (intval($get('online_state', 0)) == 1);
            $replace['#online_class#'] = $online ? 'izy-online' : 'izy-offline';
            $replace['#online_label#'] = $online ? 'En ligne' : 'Hors ligne';
            $replace['#meter_power#'] = round(floatval($get('meter_power', 0)));
            $replace['#upgrade_badge#'] = (intval($get('upgrade_available', 0)) == 1) ? ' · ⬆️ MàJ dispo' : '';

            // Capteurs CT rattachés à ce compteur, détectés dynamiquement (ct_power_ct2, ...)
            $replace['#ct_rows#'] = $this->buildCtRowsHtml();

            // Contrôle d'injection réseau (anti-retour) : état + actions
            // (bascule actif/inactif, réglage du seuil), reliées aux commandes
            // d'action injection_control_on/off et injection_limit_set.
            $injectionStateCmd = $this->getCmd(null, 'injection_control_state');
            if (is_object($injectionStateCmd)) {
                $injectionActive = intval($get('injection_control_state', 0)) == 1;
                $injectionLimit = $get('injection_limit_state', null);
                $injectionLimitVal = is_numeric($injectionLimit) ? round(floatval($injectionLimit)) : 300;
                $h = $hist('injection_control_state');

                $toggleCmd = $this->getCmd(null, $injectionActive ? 'injection_control_off' : 'injection_control_on');
                $toggleId = is_object($toggleCmd) ? $toggleCmd->getId() : '0';
                $toggleLabel = $injectionActive ? 'Désactiver' : 'Activer';
                $toggleIcon = $injectionActive ? 'fa-stop' : 'fa-play';

                $limitCmd = $this->getCmd(null, 'injection_limit_set');
                $limitCmdId = is_object($limitCmd) ? $limitCmd->getId() : '0';

                $replace['#injection_row#'] =
                    '<div class="izy-injection">'
                    . '<span class="' . ($injectionActive ? 'izy-injection-active' : 'izy-injection-inactive') . ' ' . $h['class'] . '" data-cmd_id="' . $h['id'] . '">'
                    . '🚫⚡ Anti-injection ' . ($injectionActive ? 'active' : 'inactive')
                    . '</span>'
                    . '<span>' . $injectionLimitVal . ' W max</span>'
                    . '</div>'
                    . '<div class="izy-injection-actions">'
                    . '<span class="cmd izy-injection-toggle cursor" data-cmd_id="' . $toggleId . '">'
                    . '<i class="fas ' . $toggleIcon . '"></i> ' . $toggleLabel
                    . '</span>'
                    . '<span class="izy-injection-limit-group">'
                    . '<input type="number" class="izy-injection-limit-input" min="0" max="36000" step="50" value="' . $injectionLimitVal . '">'
                    . '<span class="cmd izy-injection-limit-apply cursor" data-cmd_id="' . $limitCmdId . '" title="Appliquer le seuil"><i class="fas fa-check"></i></span>'
                    . '</span>'
                    . '</div>';
            } else {
                $replace['#injection_row#'] = '';
            }

            return template_replace($replace, getTemplate('core', $version, 'meter', 'izypower'));
        }

        /* ---------- INVERTER (défaut) ---------- */
        foreach (array('online_state', 'software_version', 'wifi_signal', 'wifi_network', 'ip_address') as $k) {
            $replace['#' . $k . '#'] = $get($k, ($k === 'online_state') ? 0 : '-');
            $h = $hist($k);
            $replace['#' . $k . '_id#'] = $h['id'];
            $replace['#' . $k . '_history_class#'] = $h['class'];
        }

        $online = (intval($get('online_state', 0)) == 1);
        $replace['#online_class#'] = $online ? 'izy-online' : 'izy-offline';
        $replace['#online_label#'] = $online ? 'En ligne' : 'Hors ligne';
        $replace['#upgrade_badge#'] = (intval($get('upgrade_available', 0)) == 1) ? ' · ⬆️ MàJ dispo' : '';

        // Température : uniquement présente sur les onduleurs ('vm')
        $tempCmd = $this->getCmd(null, 'temperature');
        if (is_object($tempCmd)) {
            $tempVal = $get('temperature', '-');
            $tempDisplay = is_numeric($tempVal) ? round(floatval($tempVal), 1) : $tempVal;
            $h = $hist('temperature');
            $replace['#temperature_row#'] = '<div class="izy-k">🌡️ Température</div>'
                . '<div class="izy-v ' . $h['class'] . '" data-cmd_id="' . $h['id'] . '">' . $tempDisplay . ' °C</div>';
        } else {
            $replace['#temperature_row#'] = '';
        }

        // Commandes de puissance par chaîne PV, détectées dynamiquement (pv_power_pv1, pv_power_pv2, ...)
        $pvRows = '';
        $totalPv = 0;
        foreach ($this->getCmd('info') as $cmd) {
            if (strpos($cmd->getLogicalId(), 'pv_power_') !== 0) {
                continue;
            }
            $val = floatval($cmd->execCmd());
            $totalPv += $val;
            $label = strtoupper(str_replace('pv_power_', '', $cmd->getLogicalId()));
            $histClass = ($cmd->getIsHistorized() == 1) ? 'cursor history' : '';
            $pvRows .= '<div class="izy-k">' . $label . '</div>'
                     . '<div class="izy-v ' . $histClass . '" data-cmd_id="' . $cmd->getId() . '">' . round($val) . ' W</div>';
        }
        $replace['#pv_rows#'] = $pvRows;
        $replace['#pv_total#'] = round($totalPv);

        return template_replace($replace, getTemplate('core', $version, 'inverter', 'izypower'));
    }

    /**
     * Construit le HTML des lignes de la grille du widget meter pour les
     * commandes de pince CT dynamiques (ct_power_ct2, ct_power_ct3, ...).
     */
    private function buildCtRowsHtml() {
        $rows = '';
        foreach ($this->getCmd('info') as $cmd) {
            if (strpos($cmd->getLogicalId(), 'ct_power_') !== 0) {
                continue;
            }
            $val = floatval($cmd->execCmd());
            $label = strtoupper(str_replace('ct_power_', '', $cmd->getLogicalId()));
            $histClass = ($cmd->getIsHistorized() == 1) ? 'cursor history' : '';
            $rows .= '<div class="izy-k">🔌 ' . $label . '</div>'
                   . '<div class="izy-v ' . $histClass . '" data-cmd_id="' . $cmd->getId() . '">' . round($val) . ' W</div>';
        }
        return $rows;
    }

    public function preInsert() {
    }

    public function postInsert() {
    }

    public function preSave() {
    }

    public function postSave() {
    }

    public function preRemove() {
    }

    public function postRemove() {
    }
}

class izypowerCmd extends cmd {
    /*     * *************************Attributs****************************** */

    /*
    public static $_widgetPossibility = array();
    */

    /*     * ***********************Methode static*************************** */


    /*     * *********************Methode d'instance************************* */

    /*
    * Permet d'empêcher la suppression des commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
    public function dontRemoveCmd() {
      return true;
    }
    */

    // Exécution d'une commande
    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
            throw new Exception(__('Equipement désactivé, impossible d\'exécuter la commande : ' . $this->getHumanName(), __FILE__));
        }
        log::add('izypower', 'debug', 'command: ' . $this->getLogicalId() . ' parameters: ' . json_encode($_options));

        switch ($this->getLogicalId()) {
            case 'injection_control_on':
                $eqLogic->setMeterInjectionControl(true, null);
                return true;
            case 'injection_control_off':
                $eqLogic->setMeterInjectionControl(false, null);
                return true;
            case 'injection_limit_set':
                $eqLogic->setMeterInjectionControl(true, isset($_options['slider']) ? $_options['slider'] : null);
                return true;
            default:
                // Toutes les autres commandes du plugin sont des capteurs en
                // lecture seule (rien à exécuter).
                return false;
        }
    }

    /*     * **********************Getteur Setteur*************************** */
}