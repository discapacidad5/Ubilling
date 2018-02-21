<?php

/**
 * MikroTik/UBNT signal monitoring class
 */
class MTsigmon {

    /**
     * User login
     *
     * @var string
     */
    protected $userLogin = '';

    /**
     * User assigned switch ID
     *
     * @var array
     */
    protected $userSwitch = '';

    /**
     * Data DEVICE id and his array mac data
     *
     * @var array
     */
    protected $deviceIdUsersMac = array();

    /**
     * All users MAC
     *
     * @var array
     */
    protected $allUsermacs = array();

    /**
     * All users Data
     *
     * @var array
     */
    protected $allUserData = array();

    /**
     * All available MT devices
     *
     * @var array
     */
    protected $allMTDevices = array();

    /**
     * OLT devices snmp data as id=>snmp data array
     *
     * @var array
     */
    protected $allMTSnmp = array();

    /**
     * UbillingCache object placeholder
     *
     * @var object
     */
    protected $cache = '';

    /**
     * Comments caching time
     *
     * @var int
     */
    protected $cacheTime = 2592000; //month by default

    /**
     * Contains system mussages object placeholder
     *
     * @var object
     */
    protected $messages = '';

    /**
     * Contains value of MTSIGMON_QUICK_AP_LINKS from alter.ini
     *
     * @var bool
     */
    protected $EnableQuickAPLinks = false;

    /**
     * Placeholder for UbillingConfig object instance
     *
     * @var object
     */
    protected $ubillingConfig = null;

    /**
     * Is WCPE module enabled?
     *
     * @var bool
     */
    protected $WCPEEnabled = false;

    const URL_ME = '?module=mtsigmon';
    const CACHE_PREFIX = 'MTSIGMON_';
    const CPE_SIG_PATH = 'content/documents/wifi_cpe_sig_hist/';

    public function __construct() {
        $this->ubillingConfig = new UbillingConfig();
        $alter_config = $this->ubillingConfig->getAlter();
        $this->EnableQuickAPLinks = ( empty($alter_config['MTSIGMON_QUICK_AP_LINKS']) ) ? false : true;
        $this->WCPEEnabled = $alter_config['WIFICPE_ENABLED'];

        $this->LoadUsersData();
        $this->initCache();
        if (wf_CheckGet(array('username'))) {
            $this->initLogin(vf($_GET['username']));
        }
        $this->getMTDevices();
        $this->initSNMP();
    }

    /**
     * Creates single instance of SNMPHelper object
     * 
     * @return void
     */
    protected function initSNMP() {
        $this->snmp = new SNMPHelper();
    }

    /**
     * If get login set $userLogin
     * 
     * @return void
     */
    protected function initLogin($login) {
        $this->userLogin = $login;
        $this->getMTidByUserMac();
    }

    /**
     * Initalizes system cache object for further usage
     * 
     * @return void
     */
    protected function initCache() {
        $this->cache = new UbillingCache();
    }

    /**
     * If get login set $userSwitch
     * 
     * @return void
     */
    protected function getMTidByUserMac() {
        $usermac = strtolower($this->allUsermacs[$this->userLogin]);
        $MT_fdb_arr = $this->cache->get(self::CACHE_PREFIX . 'MTID_UMAC', $this->cacheTime);
        if (!empty($MT_fdb_arr) and isset($usermac)) {
            foreach ($MT_fdb_arr as $mtid => $fdb_arr) {
                if (in_array($usermac, $fdb_arr)) {
                    $this->userSwitch = $mtid;
                    break;
                }
            }
        }
    }

    /**
     * Returns array of monitored MikroTik devices with MTSIGMON label and enabled SNMP
     * 
     * @return array
     */
    protected function getMTDevices() {
        $query_where = ($this->userLogin and ! empty($this->userSwitch)) ? "AND `id` ='" . $this->userSwitch . "'" : '';
        $query = "SELECT `id`,`ip`,`location`,`snmp` from `switches` WHERE `desc` LIKE '%MTSIGMON%'" . $query_where;
        $alldevices = simple_queryall($query);
        if (!empty($alldevices)) {
            foreach ($alldevices as $io => $each) {
                $this->allMTDevices[$each['id']] = $each['ip'] . ' - ' . $each['location'];
                if (!empty($each['snmp'])) {
                    $this->allMTSnmp[$each['id']]['ip'] = $each['ip'];
                    $this->allMTSnmp[$each['id']]['community'] = $each['snmp'];
                }
            }
        }
    }

    /**
     * Load user data, mac, adress
     * 
     * @return array
     */
    protected function LoadUsersData() {
        $this->allUsermacs = zb_UserGetAllMACs();
        $this->allUserData = zb_UserGetAllDataCache();
    }

    /**
     * Performs available MT devices polling. Use only in remote API.
     * 
     * @param bool $quiet
     * @param string $apid
     *
     * @return void
     */
    public function MTDevicesPolling($quiet = false, $apid = '') {
        if (!empty($this->allMTDevices)) {
            if (empty($apid)) {
                foreach ($this->allMTDevices as $mtid => $each) {
                    if (!$quiet) {
                        print('POLLING:' . $mtid . ' ' . $each . "\n");
                    }

                    $this->deviceQuery($mtid);
                }
            } else {
                $this->deviceQuery($apid);
            }

            // Set cache for Device fdb table
            if (empty($this->userLogin) or ( !empty($this->userLogin) and empty($this->userSwitch))) {
                $this->cache->set(self::CACHE_PREFIX . 'MTID_UMAC', $this->deviceIdUsersMac, $this->cacheTime);
                $this->cache->set(self::CACHE_PREFIX . 'DATE', date("Y-m-d H:i:s"), $this->cacheTime);
            }
        }
    }

    /**
     * Performs getting string representation of AP/CPE devices signal levels from cache.
     * Can re-poll the devices, before taking data from cache, to get the most fresh values.
     * IP and SNMP community for AP is taken from APs dictionary.
     * For an individual CPE - IP and SNMP community must be given as a parameter
     *
     * @param string $WiFiCPEMAC
     * @param string $WiFiAPID
     * @param string $WiFiCPEIP
     * @param string $WiFiCPECommunity
     * @param bool $GetFromAP
     * @param bool $Repoll
     *
     * @return string
    */
    public function getCPESignalData($WiFiCPEMAC, $WiFiAPID = '', $WiFiCPEIP = '', $WiFiCPECommunity = 'public', $GetFromAP = false, $Repoll = false) {
        if ( empty($WiFiCPEMAC) or (empty($WiFiAPID) and empty($WiFiCPEIP)) ) {
            return array();
        }

        $BillCfg = $this->ubillingConfig->getBilling();

        if ($GetFromAP) {
            $HistoryFile = self::CPE_SIG_PATH . md5($WiFiCPEMAC) . '_AP';

            if ($Repoll and !empty($WiFiAPID)) { $this->MTDevicesPolling(false, $WiFiAPID); }

        } else {
            $HistoryFile = self::CPE_SIG_PATH . md5($WiFiCPEMAC) . '_CPE';

            if ($Repoll and !empty($WiFiCPEIP)) { $this->deviceQuery(0, $WiFiCPEIP, $WiFiCPEMAC, $WiFiCPECommunity); }
        }

        if (file_exists($HistoryFile)) {
            //$GREPString = ( empty($GREPBy) ) ? '' : ' | ' . $BillCfg['GREP'] . ' ' . $GREPBy;
            //$RawDataLastLine = strstr(shell_exec($GetDataCmd), "\n", true);

            $GetDataCmd = $BillCfg['TAIL'] . ' -n 1 ' . $HistoryFile;
            $RawDataLastLine = shell_exec($GetDataCmd);
            $LastLineArray = explode(',', trim($RawDataLastLine));

            $LastPollDate = $LastLineArray[0];
            $SignalRX = $LastLineArray[1];
            $SignalTX = (empty($LastLineArray[2])) ? '' : ' / ' . $LastLineArray[2];
            $SignalLevel = $SignalRX . $SignalTX;

            if ($SignalLevel < -79) {
                $SignalLevel = wf_tag('font', false, '', 'color="#900000" style="font-weight: 700"') . $SignalLevel. wf_tag('font', true);
            } elseif ($SignalLevel > -80 and $SignalLevel < -74) {
                $SignalLevel = wf_tag('font', false, '', 'color="#FF5500" style="font-weight: 700"') . $SignalLevel. wf_tag('font', true);
            } else {
                $SignalLevel = wf_tag('font', false, '', 'color="#006600" style="font-weight: 700"') . $SignalLevel. wf_tag('font', true);
            }

            //return ( wf_CheckGet(array('cpeMAC')) ) ? array("LastPollDate" => $LastPollDate, "SignalLevel" => $SignalLevel) : array($LastPollDate, $SignalLevel);
            return ( $Repoll ) ? array("LastPollDate" => $LastPollDate, "SignalLevel" => $SignalLevel) : array($LastPollDate, $SignalLevel);
        }
    }

    /**
     * Renders signal graphs for specified CPE if there are some history data already
     * Returns ready-to-use piece of HTML
     *
     * @param string $WiFiCPEMAC
     * @param bool $FromAP
     * @param bool $ShowTitle
     * @param bool $ShowXLabel
     * @param bool $ShowYLabel
     * @param bool $ShowRangeSelector
     * @return string
     */
    public function renderSignalGraphs ($WiFiCPEMAC, $FromAP = false, $ShowTitle = false, $ShowXLabel = false, $ShowYLabel = false, $ShowRangeSelector = false) {
        $result = '';
        $BillCfg = $this->ubillingConfig->getBilling();

        if ($FromAP) {
            // get signal data on AP for this CPE
            $HistoryFile = self::CPE_SIG_PATH . md5($WiFiCPEMAC) . '_AP';
            $HistoryFileMonth = self::CPE_SIG_PATH . md5($WiFiCPEMAC) . '_AP_month';
        } else {
            // get signal data for this CPE itself
            $HistoryFile = self::CPE_SIG_PATH . md5($WiFiCPEMAC) . '_CPE';
        }

        if (file_exists($HistoryFile )) {
            $curdate = curdate();
            $curmonth = curmonth() . '-';
            $getMonthDataCmd = $BillCfg['CAT'] . ' ' . $HistoryFile . ' | ' . $BillCfg['GREP'] . ' ' . $curmonth;
            $rawData = shell_exec($getMonthDataCmd);
            $result .= wf_delimiter();

            $todaySignal = '';
            if (!empty($rawData)) {
                $todayTmp = explodeRows($rawData);
                if (!empty($todayTmp)) {
                    foreach ($todayTmp as $io => $each) {
                        if (ispos($each, $curdate)) {
                            $todaySignal .= $each . "\n";
                        }
                    }
                }
            }

            $GraphTitle  = ($ShowTitle)  ? __('Today') : '';
            $GraphXLabel = ($ShowXLabel) ? __('Time') : '';
            $GraphYLabel = ($ShowYLabel) ? __('Signal') : '';
            $result .= wf_Graph($todaySignal, '800', '300', false, $GraphTitle, $GraphXLabel, $GraphYLabel, $ShowRangeSelector);
            $result .= wf_delimiter(2);

            //current month signal levels
            $monthSignal = '';
            $curmonth = curmonth();
            if (!empty($rawData)) {
                $monthTmp = explodeRows($rawData);
                if (!empty($monthTmp)) {
                    foreach ($monthTmp as $io => $each) {
                        if (ispos($each, $curmonth)) {
                            $monthSignal .= $each . "\n";
                        }
                    }
                }
            }

            $GraphTitle  = ($ShowTitle)  ? __('Monthly graph') : '';
            $GraphXLabel = ($ShowXLabel) ? __('Date') : '';
            if ($FromAP) {
                file_put_contents($HistoryFileMonth, $monthSignal);
                $result .= wf_GraphCSV($HistoryFileMonth, '800', '300', false, $GraphTitle, $GraphXLabel, $GraphYLabel, $ShowRangeSelector);
            } else {
                $result .= wf_Graph($monthSignal, '800', '300', false, $GraphTitle, $GraphXLabel, $GraphYLabel, $ShowRangeSelector);
            }

            $result .= wf_delimiter(2);

            //all time signal history
            $GraphTitle  = ($ShowTitle)  ? __('All time graph') : '';
            $result .= wf_GraphCSV($HistoryFile, '800', '300', false, $GraphTitle, $GraphXLabel, $GraphYLabel, $ShowRangeSelector);
            $result .= wf_delimiter();
        }

        return $result;
    }

    /**
     * Returns array of MAC=>Signal data for some MikroTik/UBNT device
     * 
     * @param string $ip
     * @param string $community
     * @return array
     */
    protected function deviceQuery($mtid, $WiFiCPEIP = '', $WiFiCPEMAC = '', $WiFiCPECommunity = 'public') {
        if ( isset($this->allMTSnmp[$mtid]['community']) or (!empty($WiFiCPEIP) and !empty($WiFiCPEMAC)) ) {
            $ip = ( empty($WiFiCPEIP) ) ? $this->allMTSnmp[$mtid]['ip'] : $WiFiCPEIP;
            $community = ( empty($WiFiCPEIP) ) ? $this->allMTSnmp[$mtid]['community'] : $WiFiCPECommunity;

            $oid  = '.1.3.6.1.4.1.14988.1.1.1.2.1.3';    // - RX Signal Strength
            $oid2 = '.1.3.6.1.4.1.14988.1.1.1.2.1.19';  // - TX Signal Strength
            $mask_mac = false;
            $ubnt_shift = 0;
            $result = array();
            $rawsnmp = array();
            $rawsnmp2 = array();
            $result_fdb = array();

            $this->snmp->setBackground(false);
            $this->snmp->setMode('native');
            $tmpSnmp = $this->snmp->walk($ip, $community, $oid, false);
            $tmpSnmp2 = $this->snmp->walk($ip, $community, $oid2, false);

            // Returned string '.1.3.6.1.4.1.14988.1.1.1.2.1.3 = '
            // in AirOS 5.6 and newer
            if ($tmpSnmp === "$oid = ") {
                $oid = '.1.3.6.1.4.1.41112.1.4.7.1.3.1';
                $tmpSnmp = $this->snmp->walk($ip, $community, $oid, false);
                $ubnt_shift = 1;
            }

            // For Ligowave DLB 2-90
            if ($tmpSnmp === "$oid = ") {
                $oid = '.1.3.6.1.4.1.32750.3.10.1.3.2.1.5.5';
                $tmpSnmp = $this->snmp->walk($ip, $community, $oid, false);
            }

            if (!empty($tmpSnmp) and ( $tmpSnmp !== "$oid = ")) {
                $explodeData = explodeRows($tmpSnmp);
                if (!empty($explodeData)) {
                    foreach ($explodeData as $io => $each) {
                        $explodeRow = explode(' = ', $each);
                        if (isset($explodeRow[1])) {
                            $rawsnmp[$explodeRow[0]] = $explodeRow[1];
                        }
                    }
                }
            }

            if (!empty($tmpSnmp2) and ( $tmpSnmp2 !== "$oid2 = ")) {
                $explodeData = explodeRows($tmpSnmp2);
                if (!empty($explodeData)) {
                    foreach ($explodeData as $io => $each) {
                        $explodeRow = explode(' = ', $each);
                        if (isset($explodeRow[1])) {
                            $rawsnmp2[$explodeRow[0]] = $explodeRow[1];
                        }
                    }
                }
            }

            $rssi  = '';
            $rssi2 = '';
            $TXoid = '';

            if (!empty($rawsnmp)) {
                if (is_array($rawsnmp)) {
                    foreach ($rawsnmp as $indexOID => $rssi) {
                        $TXoid = (!empty($rawsnmp2)) ? str_replace($oid, $oid2, $indexOID) : '';

                        $oidarray = explode(".", $indexOID);
                        $end_num = sizeof($oidarray) + $ubnt_shift;
                        $mac = '';

                        for ($counter = 2; $counter < 8; $counter++) {
                            $temp = sprintf('%02x', $oidarray[$end_num - $counter]);

                            if (($counter < 5) && $mask_mac)
                                $mac = ":xx$mac";
                            else if ($counter == 7)
                                $mac = "$temp$mac";
                            else
                                $mac = ":$temp.$mac";
                        }

                        $mac = str_replace('.', '', $mac);
                        $mac = trim($mac);
                        $rssi = str_replace('INTEGER:', '', $rssi);
                        $rssi = trim($rssi);

                        if (!empty($TXoid)) {
                            $rssi2 = $rawsnmp2[$TXoid];
                            $rssi2 = str_replace('INTEGER:', '', $rssi2);
                            $rssi2 = trim($rssi2);
                            $rssi2 = ' / ' . $rssi2;
                        }

                        if ( empty($WiFiCPEIP) ) {
                            $result[$mac] = $rssi . $rssi2;
                            $result_fdb[] = $mac;

                            $HistoryFile = self::CPE_SIG_PATH . md5($mac) . '_AP';
                        } else { $HistoryFile = self::CPE_SIG_PATH . md5($WiFiCPEMAC) . '_CPE'; }

                        file_put_contents($HistoryFile, curdatetime() . ',' . $rssi . ',' . mb_substr($rssi2, 3) . "\n", FILE_APPEND);
                    }
                }
            }

            if ( empty($WiFiCPEIP) ) {
                if ($this->userLogin and $this->userSwitch) {
                    $this->cache->set(self::CACHE_PREFIX . $mtid, $result, $this->cacheTime);
                } else {
                    $this->cache->set(self::CACHE_PREFIX . $mtid, $result, $this->cacheTime);
                    $this->deviceIdUsersMac[$mtid] = $result_fdb;
                }
            }
        }
    }

    /**
     * Returns default list controls
     * 
     * @return string
     */
    public function controls() {
        // Load only when using web module
        $this->messages = new UbillingMessageHelper();
        $result = '';
        $cache_date = $this->cache->get(self::CACHE_PREFIX . 'DATE', $this->cacheTime);
        if ($this->userLogin) {
            $result.= wf_BackLink('?module=userprofile&username=' . $this->userLogin);
            $result.= wf_Link(self::URL_ME . '&forcepoll=true' . '&username=' . $this->userLogin, wf_img('skins/refresh.gif') . ' ' . __('Force query'), false, 'ubButton');
        } else {
            $result.= wf_Link(self::URL_ME . '&forcepoll=true', wf_img('skins/refresh.gif') . ' ' . __('Force query'), false, 'ubButton');
        }
        if (!empty($cache_date)) {
            $result.= $this->messages->getStyledMessage(__('Cache state at time') . ': ' . wf_tag('b', false) . @$cache_date . wf_tag('b', true), 'info');
        } else {
            $result.= $this->messages->getStyledMessage(__('Devices are not polled yet'), 'warning');
        }

        $result .= wf_tag('script', false, '', 'type="text/javascript"');
        $result .= 'function APIndividualRefresh(APID, JQAjaxTab) {                        
                        $.ajax({
                            type: "GET",
                            url: "?module=mtsigmon",
                            data: {IndividualRefresh:true, apid:APID},
                            success: function(result) {
                                        if ($.type(JQAjaxTab) === \'string\') {
                                            $("#"+JQAjaxTab).DataTable().ajax.reload();
                                        } else {
                                            $(JQAjaxTab).DataTable().ajax.reload();
                                        }
                                     }
                        });
                    };
                    ';

        // making an event binding for "DelUserAssignment" button("red cross" near user's login) on "CPE create&assign form"
        // to be able to create "CPE create&assign form" dynamically and not to put it's content to every "Create CPE" button in JqDt tables
        // creating of "CPE create&assign form" dynamically reduces the amount of text and page weight dramatically
        $result.= '$(document).on("click", ".__UsrDelAssignButton", function(evt) {
                            $("[name=assignoncreate]").val("");
                            $(\'.__UsrAssignBlock\').html("' . __('Do not assign WiFi equipment to any user') . '");
                            evt.preventDefault();
                            return false;
                    });
                    
                    ';

        // making an event binding for "CPE create&assign form" 'Submit' action to be able to create "CPE create&assign form" dynamically
        $result .= '$(document).on("submit", ".__CPEAssignAndCreateForm", function(evt) {                        
                            evt.preventDefault();                            
                            
                            //var FrmAction = \'"\' + $(".__CPEAssignAndCreateForm").attr("action") + \'"\';                            
                            var FrmAction = $(".__CPEAssignAndCreateForm").attr("action");
                            //alert(FrmAction);
                            if ( $(".__CPEAACFormNoRedirChck").is(\':checked\') ) {                                                            
                                $.ajax({
                                    type: "POST",
                                    url: FrmAction,
                                    data: $(".__CPEAssignAndCreateForm").serialize(),
                                    success: function() {
                                                if ( $(".__CPEAACFormPageReloadChck").is(\':checked\') ) { location.reload(); }
                                                
                                                $( \'#\'+$(".__CPEAACFormReplaceCtrlID").val() ).replaceWith(\'' . web_ok_icon() . '\');                                                
                                                $( \'#\'+$(".__CPEAACFormModalWindowID").val() ).dialog("close");
                                            }
                                });
                            } else {                                
                                $(this).submit();
                            }
                        });
                        ';
        $result .= wf_tag('script', true);

        $result .= wf_delimiter();

        return ($result);
    }

    /**
     * Renders available ONU JQDT list container
     * 
     * @return string
     */
    public function renderMTList() {
        $result = '';
        $columns = array();
        $opts = '"order": [[ 0, "desc" ]]';
        $columns[] = ('Login');
        $columns[] = ('Address');
        $columns[] = ('Real Name');
        $columns[] = ('Tariff');
        $columns[] = ('IP');
        $columns[] = ('MAC');
        $columns[] = __('Signal') . ' (' . __('dBm') . ')';

        if ($this->WCPEEnabled) { $columns[] = __('Actions'); }

        if (empty($this->allMTDevices) and ! empty($this->userLogin) and empty($this->userSwitch)) {
            $result.= show_window('', $this->messages->getStyledMessage(__('User MAC not found on devises'), 'warning'));
        } elseif (!empty($this->allMTDevices) and ! empty($this->userLogin) and ! empty($this->userSwitch)) {
            $result .= show_window(wf_img('skins/wifi.png') . ' ' . __(@$this->allMTDevices[$this->userSwitch]), wf_JqDtLoader($columns, '' . self::URL_ME . '&ajaxmt=true&mtid=' . $this->userSwitch . '&username=' . $this->userLogin, false, __('results'), 100, $opts));
        } elseif (!empty($this->allMTDevices) and empty($this->userLogin)) {
            foreach ($this->allMTDevices as $MTId => $eachMT) {
                $MTsigmonData = $this->cache->get(self::CACHE_PREFIX . $MTId, $this->cacheTime);
                if (! empty($MTsigmonData)) {
                    foreach ($MTsigmonData as $eachmac => $eachsig) {
                        if (strpos($eachsig, '/') !== false) {
                            $columns[6] = __('Signal') . ' RX / TX (' . __('dBm') . ')';
                        } else {
                            $columns[6] = __('Signal') . ' (' . __('dBm') . ')';
                        }

                        break;
                    }
                }
                                
                $AjaxURLStr     = '' . self::URL_ME . '&ajaxmt=true&mtid=' . $MTId . '';
                $JQDTId         = 'jqdt_' . md5($AjaxURLStr);
                $APIDStr        = 'APID' . $MTId;
                $QuickAPDDLName = 'QuickAPDDL_' . wf_InputId();
                $QuickAPLinkID  = 'QuickAPLinkID_' . wf_InputId();
                $QuickAPLink    = wf_tag('a', false, '', 'id="' . $QuickAPLinkID . '" href="#' . $MTId . '"') .
                                  wf_img('skins/wifi.png') . wf_tag('a', true);

                // to prevent changing the keys order of $this->allMTDevices we are using "+" opreator and not all those "array_merge" and so on
                $QickAPsArray   = array(-9999 => '') + $this->allMTDevices;

                $refresh_button = wf_tag('a', false, '', 'href="#" id="' . $APIDStr . '" title="' . __('Refresh data for this AP') . '"');
                $refresh_button .= wf_img('skins/refresh.gif');
                $refresh_button .= wf_tag('a', true);
                $refresh_button .= wf_tag('script', false, '', 'type="text/javascript"');
                $refresh_button .= '$(\'#' . $APIDStr . '\').click(function(evt) {
                                        APIndividualRefresh(' . $MTId . ', ' . $JQDTId . ');                                        
                                        evt.preventDefault();
                                        return false;                
                                    });';
                $refresh_button .= wf_tag('script', true);

                if ($this->EnableQuickAPLinks) {
                    $QuickAPLinkInput =  wf_tag('div', false, '', 'style="width: 100%; text-align: right; margin-top: 15px; margin-bottom: 20px"') .
                                        wf_tag('font', false, '', 'style="font-weight: 600"') . __('Go to AP') . wf_tag('font', true) .
                                        '&nbsp&nbsp' . wf_Selector($QuickAPDDLName, $QickAPsArray, '', '', true) .
                                        wf_tag('script', false, '', 'type="text/javascript"') .
                                        '$(\'[name="' . $QuickAPDDLName . '"]\').change(function(evt) {                                            
                                                            var LinkIDObjFromVal = $(\'a[href="#\'+$(this).val()+\'"]\');                                            
                                                            //$(\'body,html\').animate( { scrollTop: $(LinkIDObjFromVal).offset().top - 30 }, 4500 );
                                                            $(\'body,html\').scrollTop( $(LinkIDObjFromVal).offset().top - 25 );
                                                       });' .
                                        wf_tag('script', true) .
                                        wf_tag('div', true);
                } else {$QuickAPLinkInput = '';}

                $result .= show_window( $refresh_button . '&nbsp&nbsp&nbsp&nbsp' . $QuickAPLink . '&nbsp&nbsp' .
                                        __(@$eachMT), wf_JqDtLoader($columns, $AjaxURLStr, false, __('results'), 100, $opts) .
                                        $QuickAPLinkInput

                );
            }
        } else {
            $result.= show_window('', $this->messages->getStyledMessage(__('No devices for signal monitoring found'), 'warning'));
        }
        $result.= wf_delimiter();
        return ($result);
    }

    /**
     * Renders MTSIGMON list container
     * 
     * @return string
     */
    public function renderMTsigmonList($MTid) {
        // Get MTSigmon cache gtom stroage by MT id
        $MTsigmonData = $this->cache->get(self::CACHE_PREFIX . $MTid, $this->cacheTime);
        $json = new wf_JqDtHelper();
        if (!empty($MTsigmonData)) {
            $data = array();

            foreach ($MTsigmonData as $eachmac => $eachsig) {
                //signal coloring
                if ($eachsig < -79) {
                    $displaysig = wf_tag('font', false, '', 'color="#900000"') . $eachsig . wf_tag('font', true);
                } elseif ($eachsig > -80 and $eachsig < -74) {
                    $displaysig = wf_tag('font', false, '', 'color="#FF5500"') . $eachsig . wf_tag('font', true);
                } else {
                    $displaysig = wf_tag('font', false, '', 'color="#006600"') . $eachsig . wf_tag('font', true);
                }

                $login = in_array($eachmac, array_map('strtolower', $this->allUsermacs)) ? array_search($eachmac, array_map('strtolower', $this->allUsermacs)) : '';
                //user search highlight
                if ((!empty($this->userLogin)) AND ( $this->userLogin == $login)) {
                    $hlStart = wf_tag('font', false, '', 'color="#0045ac"');
                    $hlEnd = wf_tag('font', true);
                } else {
                    $hlStart = '';
                    $hlEnd = '';
                }
                
                $userLink = $login ? wf_Link('?module=userprofile&username=' . $login, web_profile_icon() . ' ' . @$this->allUserData[$login]['login'] . '', false) : '';
                $userLogin = $login ? @$this->allUserData[$login]['login'] : '';
                $userRealnames = $login ? @$this->allUserData[$login]['realname'] : '';
                $userTariff = $login ? @$this->allUserData[$login]['Tariff'] : '';
                $userIP = $login ? @$this->allUserData[$login]['ip'] : '';

                if ($this->WCPEEnabled) {
                    $WCPE = new WifiCPE();

                    // check if CPE with such MAC exists and create appropriate control
                    $WCPEID = $WCPE->getCPEIDByMAC($eachmac);
                    if (!empty($WCPEID)) {
                        $ActionLnk = wf_link($WCPE::URL_ME . '&editcpeid=' . $WCPEID, web_edit_icon());
                    } else {
                        $LnkID = wf_InputId();
                        $ActionLnk = wf_tag('a', false, '', 'id="' . $LnkID . '" href="#" title="' . __('Create new CPE') . '"');
                        $ActionLnk .= web_icon_create();
                        $ActionLnk .= wf_tag('a', true);
                        $ActionLnk .= wf_tag('script', false, '', 'type="text/javascript"');
                        $ActionLnk .= '
                                        $(\'#' . $LnkID . '\').click(function(evt) {
                                            $.ajax({
                                                type: "GET",
                                                url: "' . $WCPE::URL_ME . '",
                                                data: { 
                                                        renderCreateForm:true,
                                                        renderDynamically:true, 
                                                        renderedOutside:true,
                                                        userLogin:"' . $userLogin . '", 
                                                        wcpeMAC:"' . $eachmac . '",
                                                        wcpeIP:"' . $userIP . '",
                                                        wcpeAPID:"' . $MTid . '",                                                        
                                                        ModalWID:"dialog-modal_' . $LnkID . '", 
                                                        ModalWBID:"body_dialog-modal_' . $LnkID . '",
                                                        ActionCtrlID:"' . $LnkID . '"
                                                       },
                                                success: function(result) {
                                                            $(document.body).append(result);
                                                            $(\'#dialog-modal_' . $LnkID . '\').dialog("open");
                                                         }
                                            });
                    
                                            evt.preventDefault();
                                            return false;
                                        });
                                      ';
                        $ActionLnk .= wf_tag('script', true);
                    }
                }

                $data[] = $userLink;
                $data[] = $hlStart . @$this->allUserData[$login]['fulladress'] . $hlEnd;
                $data[] = $hlStart . $userRealnames . $hlEnd;
                $data[] = $hlStart . $userTariff . $hlEnd;
                $data[] = $hlStart . $userIP . $hlEnd;
                $data[] = $hlStart . $eachmac . $hlEnd;
                $data[] = $displaysig;

                if ($this->WCPEEnabled) { $data[] = $ActionLnk; }

                $json->addRow($data);
                unset($data);
            }
        }
        $json->getJson();
    }

}

?>