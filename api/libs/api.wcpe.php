<?php

class WifiCPE {

    /**
     * Contains system alter config as key=>value
     *
     * @var array
     */
    protected $altCfg = array();

    /**
     * Contains all available APs as id=>APdata
     *
     * @var array
     */
    protected $allAP = array();

    /**
     * Contains available AP SSIDs if exists as id=>ssid
     *
     * @var array
     */
    protected $allSSids = array();

    /**
     * Contains all available devices models as modelid=>name
     *
     * @var array
     */
    protected $deviceModels = array();

    /**
     * Contains all available CPEs as id=>CPEdata
     *
     * @var array
     */
    protected $allCPE = array();

    /**
     * Messages helper object placeholder
     *
     * @var object
     */
    protected $messages = '';

    /**
     * Base module URL
     */
    const URL_ME = '?module=testing';

    public function __construct() {
        $this->loadConfigs();
        $this->initMessages();
        $this->loadDeviceModels();
        $this->loadAps();
        $this->loadCPEs();
    }

    /**
     * Loads system alter config to protected property for further usage
     * 
     * @global object $ubillingConfig
     * 
     * @return void
     */
    protected function loadConfigs() {
        global $ubillingConfig;
        $this->altCfg = $ubillingConfig->getAlter();
    }

    /**
     * Initalizes system message helper instance
     * 
     * @return void
     */
    protected function initMessages() {
        $this->messages = new UbillingMessageHelper();
    }

    /**
     * Loads all available AP devices from switches directory
     * 
     * @return void
     */
    protected function loadAps() {
        $query = "SELECT * from `switches` WHERE `desc` LIKE '%AP%';";
        $all = simple_queryall($query);
        if (!empty($all)) {
            foreach ($all as $io => $each) {
                $this->allAP[$each['id']] = $each;
                $apSsid = $this->extractSsid($each['desc']);
                if (!empty($apSsid)) {
                    $this->allSSids[$each['id']] = $apSsid;
                }
            }
        }
    }

    /**
     * Ectracts SSID if exists from AP description
     * 
     * @param string $desc
     * 
     * @return string
     */
    protected function extractSsid($desc) {
        $result = '';
        if (!empty($desc)) {
            $rawDesc = explode(' ', $desc);
            if (!empty($rawDesc)) {
                foreach ($rawDesc as $io => $each) {
                    if (ispos($each, 'ssid:')) {
                        $result = $each;
                    }
                }
            }
        }
        return ($result);
    }

    /**
     * Loads all available CPE to protected property
     * 
     * @return void
     */
    protected function loadCPEs() {
        $query = "SELECT * from `wcpedevices`;";
        $all = simple_queryall($query);
        if (!empty($all)) {
            foreach ($all as $io => $each) {
                $this->allCPE[$each['id']] = $each;
            }
        }
    }

    /**
     * Loads available device models from database
     * 
     * @return void
     */
    protected function loadDeviceModels() {
        $this->deviceModels = zb_SwitchModelsGetAllTag();
    }

    /**
     * Creates new CPE in database
     * 
     * @param int $modelId
     * @param string $ip
     * @param string $mac
     * @param string $location
     * @param bool $bridgeMode
     * @param int $uplinkApId
     * @param string $geo
     * 
     * @return void/string on error
     */
    public function createCPE($modelId, $ip, $mac, $location, $bridgeMode = false, $uplinkApId, $geo) {
        $result = '';
        $modelId = vf($modelId, 3);
        $ipF = mysql_real_escape_string($ip);
        $mac = strtolower_utf8($mac);
        $macF = mysql_real_escape_string($mac);
        $loactionF = mysql_real_escape_string($location);
        $bridgeMode = ($bridgeMode) ? 1 : 0;
        $uplinkApId = vf($uplinkApId, 3);
        $geoF = mysql_real_escape_string($geo);

        if (isset($this->deviceModels[$modelId])) {
            if (empty($macF)) {
                $macCheckFlag = true;
            } else {
                $macCheckFlag = check_mac_format($macF);
            }
            if ($macCheckFlag) {
                $query = "INSERT INTO `wcpedevices` (`id`, `modelid`, `ip`, `mac`, `location`, `bridge`, `uplinkapid`, `uplinkcpeid`, `geo`) "
                        . "VALUES (NULL, '" . $modelId . "', '" . $ipF . "', '" . $macF . "', '" . $loactionF . "', '" . $bridgeMode . "', '" . $uplinkApId . "', NULL, '" . $geoF . "');";
                nr_query($query);
                $newId = simple_get_lastid('wcpedevices');
                log_register('WCPE CREATE [' . $newId . ']');
            } else {
                $result.=$this->messages->getStyledMessage(__('This MAC have wrong format'), 'error');
            }
        } else {
            $result.=$this->messages->getStyledMessage(__('Strange exeption') . ': MODELID_NOT_EXISTS', 'error');
        }
        return ($result);
    }

    /**
     * Deletes existing CPE from database
     * 
     * @param int $cpeId
     * 
     * @return void/string
     */
    public function deleteCPE($cpeId) {
        $result = '';
        $cpeId = vf($cpeId, 3);
        if (isset($this->allCPE[$cpeId])) {
            $query = "DELETE from `wcpedevices` WHERE `id`='" . $cpeId . "';";
            nr_query($query);
            log_register('WCPE DELETE [' . $cpeId . ']');
        } else {
            $result.=$this->messages->getStyledMessage(__('Strange exeption') . ': CPEID_NOT_EXISTS', 'error');
        }
        return ($result);
    }

    /**
     * Renders CPE creation form
     * 
     * @return string
     */
    public function renderCPECreateForm() {
        $result = '';
        if (!empty($this->deviceModels)) {
            $apTmp = array('' => __('No'));

            if (!empty($this->allAP)) {
                foreach ($this->allAP as $io => $each) {
                    $apTmp[$each['id']] = $each['location'] . ' - ' . $each['ip'] . ' ' . @$this->allSSids[$each['id']];
                }
            }

            $inputs = wf_HiddenInput('createnewcpe', 'true');
            $inputs.= wf_Selector('newcpemodelid', $this->deviceModels, __('Model'), '', true);
            $inputs.= wf_CheckInput('newcpebridge', __('Bridge mode'), true, false);
            $inputs.= wf_TextInput('newcpeip', __('IP'), '', true, 15);
            $inputs.= wf_TextInput('newcpemac', __('MAC'), '', true, 15);
            $inputs.= wf_TextInput('newcpelocation', __('Location'), '', true, 25);
            $inputs.= wf_TextInput('newcpegeo', __('Geo location'), '', true, 25);
            $inputs.= wf_Selector('newcpeuplinkapid', $apTmp, __('Connected to AP'), '', true);
            $inputs.= wf_Submit(__('Create'));

            $result = wf_Form('', 'POST', $inputs, 'glamour');
        } else {
            $result = $this->messages->getStyledMessage(__('No') . ' ' . __('Equipment models'), 'error');
        }
        return ($result);
    }

    /**
     * Renders available CPE list container
     * 
     * @return string
     */
    public function renderCPEList() {
        $result = '';
        if (!empty($this->allCPE)) {
            $columns = array('ID', 'Model', 'IP', 'MAC', 'Location', 'Geo location', 'Connected to AP', 'Bridge mode', 'Actions');
            $opts = '"order": [[ 0, "desc" ]]';
            $result = wf_JqDtLoader($columns, self::URL_ME . '&ajcpelist=true', false, __('CPE'), 100, $opts);
        } else {
            $result = $this->messages->getStyledMessage(__('Nothing to show'), 'info');
        }
        return ($result);
    }

    /**
     * Renders JSON data of available CPE devices
     * 
     * @return void
     */
    public function getCPEListJson() {
        $json = new wf_JqDtHelper();
        if (!empty($this->allCPE)) {
            foreach ($this->allCPE as $io => $each) {
                $data[] = $each['id'];
                $data[] = @$this->deviceModels[$each['modelid']];
                $data[] = $each['ip'];
                $data[] = $each['mac'];
                $data[] = $each['location'];
                $data[] = $each['geo'];
                $data[] = @$this->allAP[$each['uplinkapid']]['ip'] . ' - ' . @$this->allSSids[$each['uplinkapid']];
                $data[] = web_bool_led($each['bridge']);
                $actLinks = wf_JSAlert(self::URL_ME . '&deletecpeid=' . $each['id'], web_delete_icon(), $this->messages->getDeleteAlert());
                $data[] = $actLinks;
                $json->addRow($data);
                unset($data);
            }
        }
        $json->getJson();
    }

}

?>