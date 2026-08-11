<?php
/* This file is part of Jeedom.
 *
 * Client API pour Izypower Cloud (Materfrance).
 * Reconstruit à partir de la logique de l'intégration Home Assistant
 * "izypower_cloud" (StefanPlizga), endpoints identiques.
 *
 * Portée : lecture seule (login, stations, station info, report energie,
 * component power, device page, wifi, battery links).
 */

class IzypowerApi {

    const BASE_URL = 'http://application.izypowercloud.fr/photo_voltaic';

    const LOGIN_URL            = self::BASE_URL . '/api/login';
    const STATIONS_URL         = self::BASE_URL . '/api/powerStations/page';
    const STATION_INFO_URL     = self::BASE_URL . '/api/v3/powerStations/info/%s';
    const REPORT_URL           = self::BASE_URL . '/api/report/v2/powerStations/data/%s?timeType=%s&dataFlag=energy&searchTime=%s';
    const COMPONENT_URL        = self::BASE_URL . '/api/component/%s?searchTime=%s';
    const DEVICE_PAGE_URL      = self::BASE_URL . '/api/device/page?powerId=%s&deviceType=%s&page=%s&limit=%s';
    const DEVICE_WIFI_URL      = self::BASE_URL . '/api/v3/device/wifi/%s';
    const BATTERY_LINKS_URL    = self::BASE_URL . '/izy/v2/battery/%s';
    const DEVICE_TEMP_URL      = self::BASE_URL . '/api/report/device/data/%s?searchTime=%s&timeType=day&dataFlag=temp';
    const DEVICE_UPGRADE_URL   = self::BASE_URL . '/api/v3/device/upgrade/%s';
    const LAYOUT_POWER_URL     = self::BASE_URL . '/api/report/layoutPower/%s?searchTime=%s&isV2=%s';

    const TOKEN_HEADER    = 'x-tts-access-token';
    const APP_PLATFORM    = 'izy';

    private $username;
    private $password;
    private $token = null;
    private $tokenExpiry = 0;
    private $lang;

    /**
     * @param string $username
     * @param string $password
     * @param string $lang 'fr' ou 'en'
     */
    public function __construct($username, $password, $lang = 'fr') {
        $this->username = $username;
        $this->password = $password;
        $this->lang = ($lang === 'fr') ? 'fr' : 'en';
    }

    /* ===================== AUTHENTIFICATION ===================== */

    /**
     * Authentifie et stocke le token JWT + son expiration.
     * @throws Exception
     */
    public function login() {
        $body = json_encode(array(
            'username' => $this->username,
            'password' => $this->password,
        ));

        $headers = array(
            'Content-Type: application/json',
            'Accept-Language: ' . $this->lang,
            'app-platform: ' . self::APP_PLATFORM,
        );

        $result = $this->httpRequest('POST', self::LOGIN_URL, $headers, $body, false);

        $data = json_decode($result, true);
        if (!is_array($data) || !isset($data['data']['token'])) {
            log::add('izypower', 'error', 'Login Izypower : réponse invalide ou token manquant');
            throw new Exception('Izypower : échec de connexion (token manquant)');
        }

        $this->token = $data['data']['token'];
        $exp = $this->decodeJwtExpiry($this->token);
        $this->tokenExpiry = $exp ? $exp : (time() + 600);

        log::add('izypower', 'debug', 'Connexion Izypower réussie, token expire à ' . date('Y-m-d H:i:s', $this->tokenExpiry));
    }

    /**
     * Décode l'expiration (exp) d'un JWT sans vérifier la signature
     * (on n'a pas besoin de la valider, juste de lire 'exp').
     */
    private function decodeJwtExpiry($jwt) {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }
        $payload = $parts[1];
        $payload = str_pad($payload, strlen($payload) % 4 === 0 ? strlen($payload) : strlen($payload) + (4 - strlen($payload) % 4), '=');
        $payload = strtr($payload, '-_', '+/');
        $decoded = base64_decode($payload);
        $json = json_decode($decoded, true);
        if (is_array($json) && isset($json['exp'])) {
            return (float) $json['exp'];
        }
        return null;
    }

    private function tokenIsValid() {
        return $this->token !== null && time() < ($this->tokenExpiry - 10);
    }

    private function ensureLoggedIn() {
        if (!$this->tokenIsValid()) {
            $this->login();
        }
    }

    /* ===================== APPELS API ===================== */

    /**
     * Liste paginée des centrales (stations).
     */
    public function getStations($page = 1, $limit = 100) {
        $url = self::STATIONS_URL . '?page=' . $page . '&limit=' . $limit;
        return $this->authenticatedGet($url);
    }

    /**
     * Infos détaillées d'une centrale (puissances directes, extraData, etc.)
     */
    public function getStationInfo($stationId) {
        $url = sprintf(self::STATION_INFO_URL, $stationId);
        return $this->authenticatedGet($url);
    }

    /**
     * Rapport d'énergie pour une centrale.
     * @param string $timeType 'day' | 'month' | 'year' | 'all'
     * @param string $searchTime format dépend du timeType (Y-m-d / Y-m / Y)
     */
    public function getReport($stationId, $timeType, $searchTime) {
        $url = sprintf(self::REPORT_URL, $stationId, $timeType, $searchTime);
        return $this->authenticatedGet($url);
    }

    /**
     * Données "component" : puissances PV par chaîne (pvData).
     */
    public function getComponent($stationId, $searchTime) {
        $url = sprintf(self::COMPONENT_URL, $stationId, $searchTime);
        return $this->authenticatedGet($url);
    }

    /**
     * Liste des équipements (onduleurs, etc.) d'une centrale.
     */
    public function getDevicePage($stationId, $deviceType = 'all', $page = 1, $limit = 100) {
        $url = sprintf(self::DEVICE_PAGE_URL, $stationId, $deviceType, $page, $limit);
        return $this->authenticatedGet($url);
    }

    /**
     * Infos Wi-Fi d'un équipement (par numéro de série).
     */
    public function getDeviceWifi($serialNumber) {
        $url = sprintf(self::DEVICE_WIFI_URL, $serialNumber);
        return $this->authenticatedGet($url);
    }

    /**
     * Données batterie (SOC, énergie, modules liés).
     */
    public function getBatteryLinks($serialNumber) {
        $url = sprintf(self::BATTERY_LINKS_URL, $serialNumber);
        return $this->authenticatedGet($url);
    }

    /**
     * Température de l'équipement (onduleurs "vm" uniquement).
     * @param string $searchTime format Y-m-d
     */
    public function getDeviceTemp($serialNumber, $searchTime) {
        $url = sprintf(self::DEVICE_TEMP_URL, $serialNumber, $searchTime);
        return $this->authenticatedGet($url);
    }

    /**
     * Liste des mises à jour disponibles pour les équipements d'une centrale
     * (data[].sn / data[].needUpgrade).
     */
    public function getDeviceUpgrade($stationId) {
        $url = sprintf(self::DEVICE_UPGRADE_URL, $stationId);
        return $this->authenticatedGet($url);
    }

    /**
     * Données "layoutPower" d'une centrale : sert à extraire les capteurs CT
     * (pinces ampèremétriques ct2/ct3) via le noeud 'extra' de la dernière ligne.
     */
    public function getLayoutPower($stationId, $searchTime, $isV2 = true) {
        $url = sprintf(self::LAYOUT_POWER_URL, $stationId, $searchTime, $isV2 ? 'true' : 'false');
        return $this->authenticatedGet($url);
    }

    /* ===================== COEUR HTTP ===================== */

    /**
     * GET authentifié avec gestion du re-login automatique sur 401
     * et retry avec backoff exponentiel sur erreurs serveur / réseau.
     */
    private function authenticatedGet($url, $maxAttempts = 3) {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $this->ensureLoggedIn();

                $headers = array(
                    self::TOKEN_HEADER . ': ' . $this->token,
                    'Accept-Language: ' . $this->lang,
                    'app-platform: ' . self::APP_PLATFORM,
                );

                list($status, $body) = $this->httpRequestWithStatus('GET', $url, $headers, null);

                if ($status === 401) {
                    log::add('izypower', 'debug', 'Token expiré (401), reconnexion (tentative ' . $attempt . '/' . $maxAttempts . ')');
                    $this->token = null;
                    $this->ensureLoggedIn();
                    continue;
                }

                if ($status >= 500 && $status < 600) {
                    throw new Exception('Izypower : serveur indisponible (HTTP ' . $status . ')');
                }

                if ($status !== 200) {
                    throw new Exception('Izypower : HTTP ' . $status . ' sur ' . $url);
                }

                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Izypower : réponse JSON invalide');
                }

                return $data;

            } catch (Exception $e) {
                $lastException = $e;
                log::add('izypower', 'debug', 'Erreur appel API (tentative ' . $attempt . '/' . $maxAttempts . ') : ' . $e->getMessage());
                if ($attempt < $maxAttempts) {
                    // Backoff exponentiel + gigue, plafonné pour rester raisonnable en PHP synchrone
                    $wait = min(5, (1 * pow(2, $attempt - 1)) + (mt_rand(0, 500) / 1000));
                    usleep((int) ($wait * 1000000));
                }
            }
        }

        throw $lastException;
    }

    /**
     * Requête HTTP simple, retourne uniquement le corps (utilisé pour le login).
     */
    private function httpRequest($method, $url, $headers, $body, $authenticated = true) {
        list($status, $responseBody) = $this->httpRequestWithStatus($method, $url, $headers, $body);
        if ($status < 200 || $status >= 300) {
            throw new Exception('Izypower : HTTP ' . $status . ' sur ' . $url);
        }
        return $responseBody;
    }

    /**
     * Requête HTTP cURL, retourne [status, body].
     */
    private function httpRequestWithStatus($method, $url, $headers, $body) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception('Izypower : erreur réseau cURL (' . $errno . ') ' . $error);
        }

        return array($status, $response);
    }
}
