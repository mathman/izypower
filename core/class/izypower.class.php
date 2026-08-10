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
            $eqLogic->buildCommands();

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

        $created = 0;
        foreach ($deviceRecords as $deviceRecord) {
            $deviceSn = isset($deviceRecord['sn']) ? $deviceRecord['sn'] : (isset($deviceRecord['serialNumber']) ? $deviceRecord['serialNumber'] : null);
            if ($deviceSn === null || $deviceSn === '') {
                continue;
            }

            $deviceType = isset($deviceRecord['deviceType']) ? $deviceRecord['deviceType'] : 'vm';
            $deviceEq = self::byLogicalId($deviceSn, 'izypower');
            $isNewDevice = !is_object($deviceEq);

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

                if ($deviceType === 'meter') {
                    $deviceEq->buildMeterCommands();
                } else {
                    $deviceEq->buildDeviceCommands();
                }
                $created++;
            }

            if ($deviceType !== 'meter') {
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
     * Crée les commandes manquantes pour cet eqLogic (idempotent).
     */
    public function buildCommands() {
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

        $this->save();
    }

    private function buildSensorCommand($logicalId, $name, $unit, $generic_type_suffix, $isString = false) {
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
        if ($logicalId === 'production_power') {
            $cmd->setGeneric_type('POWER');
        } elseif (in_array($logicalId, array('consumption_total', 'grid_total_import', 'grid_total_export'))) {
            $cmd->setGeneric_type('CONSO_TOTAL');
        }

        $cmd->save();
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

            if ($deviceType === 'meter') {
                // Valeurs spécifiques compteur depuis dataDtos
                $dataDtos = isset($deviceRecord['dataDtos']) && is_array($deviceRecord['dataDtos']) ? $deviceRecord['dataDtos'] : array();
                $deviceEq->updateMeterValues($dataDtos);
                // Puissance réseau instantanée depuis getStationInfo (grid_power)
                $gridPower = isset($stationInfo['grid_power']) ? $stationInfo['grid_power'] : null;
                if ($gridPower !== null) {
                    $deviceEq->updateCmdValue('meter_power', $gridPower);
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
            }
			$deviceEq->refreshWidget();
        }
    }

    protected function updateCmdValue($logicalId, $value) {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) {
            $cmd->event($value);
        }
    }

    /* ===================== COMMANDES ONDULEUR (équipement device_type=inverter) ===================== */

    /**
     * Définition des commandes fixes (hors PV, créées dynamiquement) pour un onduleur.
     * clé logique => [libellé, unité|null, type Jeedom: 'string'|'numeric']
     */
    public static function getDeviceFields() {
        return array(
            'online_state'     => array('En Ligne',           null,   'numeric'),
            'software_version' => array('Version Logicielle', null,   'string'),
            'wifi_signal'      => array('Signal Wi-Fi',        'dBm',  'numeric'),
            'wifi_network'     => array('Réseau Wi-Fi',        null,   'string'),
            'ip_address'       => array('Adresse IP',          null,   'string'),
        );
    }

    /**
     * Crée les commandes fixes manquantes pour cet équipement onduleur (idempotent).
     * Les commandes de puissance par chaîne PV sont gérées séparément par
     * buildPvCommands(), car leur nombre varie selon l'onduleur.
     */
    public function buildDeviceCommands() {
        foreach (self::getDeviceFields() as $logicalId => $def) {
            list($label, $unit, $type) = $def;
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new izypowerCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($label);
            }
            $cmd->setType('info');
            $cmd->setSubType($type === 'numeric' ? 'numeric' : 'string');
            $cmd->setUnite($unit);
            $cmd->setIsVisible(1);

            if ($logicalId === 'online_state') {
                $cmd->setGeneric_type('PRESENCE');
            }

            $cmd->save();
        }
        $this->save();
    }

    /**
     * Crée uniquement une commande de puissance par chaîne PV détectée (PV1, PV2, ...).
     */
    public function buildPvCommands($pvNames) {
        $created = 0;
        foreach ($pvNames as $pvName) {
            $logicalId = 'pv_power_' . strtolower($pvName);
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd)) {
                continue;
            }
            $cmd = new izypowerCmd();
            $cmd->setLogicalId($logicalId);
            $cmd->setEqLogic_id($this->getId());
            $cmd->setName('Puissance ' . $pvName);
            $cmd->setType('info');
            $cmd->setSubType('numeric');
            $cmd->setUnite('W');
            $cmd->setIsVisible(1);
            $cmd->save();
            $created++;
        }
        if ($created > 0) {
            $this->save();
        }
    }

    /**
     * Met à jour la valeur des commandes de puissance par chaîne PV déjà
     * existantes : appelée depuis pullDevices() à chaque cron.
     * $pvPowers est un tableau ['PV1' => 350, 'PV2' => 280, ...]. Une chaîne
     * dont la commande n'existe pas encore est ignorée (elle sera créée à la
     * prochaine synchronisation des centrales).
     */
    public function updatePvValues($pvPowers) {
        foreach ($pvPowers as $pvName => $pvPower) {
            $this->updateCmdValue('pv_power_' . strtolower($pvName), $pvPower);
        }
    }

    /**
     * Crée les commandes fixes pour un équipement compteur (meter).
     * Commandes communes (online_state, software_version, wifi...) + commandes
     * spécifiques compteur (tension, fréquence, énergie +/-).
     */
    public function buildMeterCommands() {
        // Commandes communes avec les onduleurs
        foreach (self::getDeviceFields() as $logicalId => $def) {
            list($label, $unit, $type) = $def;
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new izypowerCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($label);
            }
            $cmd->setType('info');
            $cmd->setSubType($type === 'numeric' ? 'numeric' : 'string');
            $cmd->setUnite($unit);
            $cmd->setIsVisible(1);
            if ($logicalId === 'online_state') {
                $cmd->setGeneric_type('PRESENCE');
            }
            $cmd->save();
        }
        // Commandes spécifiques compteur
        $meterCmds = array(
            'meter_voltage'    => array('Tension Réseau',    'V',   'numeric'),
            'meter_frequency'  => array('Fréquence Réseau',  'Hz',  'numeric'),
            'meter_energy_p'   => array('Énergie Importée',  'kWh', 'numeric'),
            'meter_energy_n'   => array('Énergie Exportée',  'kWh', 'numeric'),
            'meter_power'      => array('Puissance Réseau',  'W',   'numeric'),
        );
        foreach ($meterCmds as $logicalId => $def) {
            list($label, $unit, $type) = $def;
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new izypowerCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($label);
            }
            $cmd->setType('info');
            $cmd->setSubType('numeric');
            $cmd->setUnite($unit);
            $cmd->setIsVisible(1);
            $cmd->save();
        }
        $this->save();
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

            // Ratio d'autoconsommation du jour (consommation couverte par le PV)
            $consDay = floatval($get('consumption_day', 0));
            $consPvDay = floatval($get('consumption_from_pv_day', 0));
            $replace['#autoconso_pct#'] = ($consDay > 0) ? min(100, round(($consPvDay / $consDay) * 100)) : 0;

            // Le réseau importe ou exporte actuellement ? (grid_power > 0 = import, classique sur ce type d'API)
            $gridPower = floatval($get('grid_power', 0));
            $replace['#grid_label#'] = ($gridPower >= 0) ? 'Import réseau' : 'Export réseau';
            $replace['#grid_class#'] = ($gridPower >= 0) ? 'izy-grid-import' : 'izy-grid-export';

            $producing = floatval($get('production_power', 0)) > 0;
            $replace['#sun_class#'] = $producing ? 'izy-sun-active' : '';

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

    public function execute($_options = array()) {
        // Lecture seule : aucune action exécutable depuis Jeedom dans cette version.
        return null;
    }

    public function dontRemoveCmd() {
        return false;
    }
}