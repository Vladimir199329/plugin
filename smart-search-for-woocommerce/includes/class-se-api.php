<?php

defined('ABSPATH') || exit;

class ApiSe
{
    // Export statuses
    const EXPORT_STATUS_NONE          = 'none';
    const EXPORT_STATUS_QUEUED        = 'queued';
    const EXPORT_STATUS_START         = 'start';
    const EXPORT_STATUS_PROCESSING    = 'processing';
    const EXPORT_STATUS_SENT          = 'sent';
    const EXPORT_STATUS_DONE          = 'done';
    const EXPORT_STATUS_SYNC_ERROR    = 'sync_error';

    // Addon statuses
    const ADDON_STATUS_ENABLED        = 'enabled';
    const ADDON_STATUS_DISABLED       = 'disabled';
    const ADDON_STATUS_DELETED        = 'deleted';

    // Sync Modes
    const SYNC_MODE_REALTIME          = 'realtime';
    const SYNC_MODE_PERIODIC          = 'periodic';
    const SYNC_MODE_MANUAL            = 'manual';

    // Default values
    const DEFAULT_SEARCH_FIELD_ID     = "#search,form input[name=\"s\"]";
    const DEFAULT_SEARCH_RESULTS_PAGE = 'search-results';
    const DEFAULT_COLOR_NAME          = 'color';
    const DEFAULT_SIZE_NAME           = 'size';

    // Min versions
    const MIN_WOOCOMMERCE_VERSION             = '3.0.0';
    const MIN_WORDPRESS_VERSION               = '4.0.0';
    const MIN_WORDPRESS_VERSION_FOR_WP_JQUERY = '5.6';

    const OPTION_PREFIX = 'se_';
    const LABEL_FOR_PRICES_USERGROUP = 'se_price_';
    const USERGROUP_GUEST = 'guest';

    const SUGGESTIONS_MAX_RESULTS = 1;

    static private $instance = null;

    protected $wpdb;

    public function __construct()
    {
        global $wpdb;

        $this->wpdb = $wpdb;
    }

    public static function getInstance()
    {
        if (self::$instance == null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getParentPrivateKey()
    {
        return $this->getSetting('parent_private_key');
    }

    public function checkParentPrivateKey()
    {
        $parent_private_key = $this->getParentPrivateKey();

        return !empty($parent_private_key);
    }

    public function setParentPrivateKey($parent_private_key)
    {
        $this->setSetting('parent_private_key', '', $parent_private_key);
    }

    public function setPrivateKey($api_key, $lang_code)
    {
        $this->setSetting('private_key', $lang_code, $api_key);
    }

    public function getPrivateKey($lang_code)
    {
        static $private_keys = array();

        if (!isset($private_keys[$lang_code])) {
            $private_keys[$lang_code] = $this->getSetting('private_key', $lang_code);
        }

        return isset($private_keys[$lang_code]) ? $private_keys[$lang_code] : '';
    }

    public function getPrivateKeys()
    {
        static $private_keys = array();

        if (empty($private_keys)) {
            $keys = $this->wpdb->get_results("SELECT value, lang_code FROM {$this->wpdb->prefix}wc_se_settings WHERE name = 'private_key'", ARRAY_A);

            foreach ($keys as $k) {
                $k['lang_code'] = $this->getLocale($k['lang_code']);
                $private_keys[$k['lang_code']] = $k['value'];
            }
        }

        return $private_keys;
    }

    public function checkPrivateKey($lang_code)
    {
        $private_key = $this->getPrivateKey($lang_code);

        return !empty($private_key);
    }

    public function getApiKeys()
    {
        static $api_keys;

        if (empty($api_keys)) {
            $keys = $this->wpdb->get_results("SELECT value, lang_code FROM {$this->wpdb->prefix}wc_se_settings WHERE name = 'api_key'", ARRAY_A);

            foreach ($keys as $k) {
                $k['lang_code'] = $this->getLocale($k['lang_code']);
                $api_keys[$k['lang_code']] = $k['value'];
            }
        }

        return $api_keys;
    }

    public function setApiKey($api_key, $lang_code)
    {
        $this->setSetting('api_key', $lang_code, $api_key);
    }

    public function getApiKey($lang_code)
    {
        static $api_keys = array();

        if (!isset($api_keys[$lang_code])) {
            $api_keys[$lang_code] = $this->getSetting('api_key', $lang_code);
        }

        return isset($api_keys[$lang_code]) ? $api_keys[$lang_code] : '';
    }

    public function getExportStatuses()
    {
        $statuses = array();

        if (empty($statuses)) {
            $keys = $this->wpdb->get_results("SELECT value, lang_code FROM {$this->wpdb->prefix}wc_se_settings WHERE name = 'export_status'", ARRAY_A);

            foreach ($keys as $k) {
                $k['lang_code'] = $this->getLocale($k['lang_code']);
                $statuses[$k['lang_code']] = $k['value'];
            }
        }

        return $statuses;
    }

    public function getExportStatus($lang_code)
    {
        return $this->getSetting('export_status', $lang_code);
    }

    public function checkExportStatus($lang_code)
    {
        return $this->getExportStatus($lang_code) == self::EXPORT_STATUS_DONE;
    }

    public function checkExportStatusIsDone($lang_code = null, $skip_time_check = false)
    {
        $engines_data = $this->getEngines($lang_code);

        foreach ($engines_data as $engine_data) {
            if ($engine_data['export_status'] != self::EXPORT_STATUS_SENT) {
                continue;
            }

            if (
                (time() - $this->getLastRequest($lang_code)) > 10
                || ($this->getLastRequest($lang_code) - 10) > time()
                || $skip_time_check
            ) {
                try {
                    $response = ApiSe::getInstance()->sendRequest('/api/state/get/json', $engine_data['private_key'], array('status' => '', 'full_import' => ''), true);

                } catch (SeException $e) {
                    $response = array();
                }

                $variables = isset($response['variable']) ? $response['variable'] : array();
                if (!empty($variables) && isset($variables['status'])) {
                    if ($variables['status'] == 'normal' && $variables['full_import'] == 'done') {
                        $this->setExportStatus(self::EXPORT_STATUS_DONE, $engine_data['lang_code']);
                        $skip_time_check = true;

                    } elseif ($variables['status'] == 'disabled') {
                        $this->setExportStatus(self::EXPORT_STATUS_NONE, $engine_data['lang_code']);
                    }
                }
            }
        }
    }

    public function setExportStatus($status, $lang_code)
    {
        $this->setSetting('export_status', $lang_code, $status);
    }

    public function setSetting($name, $lang_code, $value)
    {
        $this->wpdb->replace("{$this->wpdb->prefix}wc_se_settings", array(
            'name'      => $name,
            'lang_code' => $this->getLocaleSettings($lang_code),
            'value'     => $value
        ));
    }

    public function getSetting($name, $lang_code = '')
    {
        $lang_code = $this->getLocaleSettings($lang_code);
        return $this->wpdb->get_var("SELECT value FROM {$this->wpdb->prefix}wc_se_settings WHERE name = '{$name}' AND lang_code = '{$lang_code}'"); 
    }

    public function getSystemSetting($name, $default = false)
    {
        return get_option(self::OPTION_PREFIX . $name, $default);
    }

    public function setSystemSetting($name, $value)
    {
        update_option(self::OPTION_PREFIX . $name, $value);
    }

    public function echoProgress($progress)
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }

        echo $progress;
    }

    public function getLastRequest($lang_code = '')
    {
        return $this->getSetting('last_request', '');
    }

    public function setLastRequest($time, $lang_code = '')
    {
        $this->setSetting('last_request', $lang_code, $time);
    }

    public function getLastResync($lang_code)
    {
        return $this->getSetting('last_resync', '');
    }

    public function setLastResync($lang_code, $time)
    {
        $this->setSetting('last_resync', '', $time);
    }

    public function getLastRequests()
    {
        $requests = array();

        if (empty($requests)) {
            $keys = $this->wpdb->get_results("SELECT value, lang_code FROM {$this->wpdb->prefix}wc_se_settings WHERE name = 'last_request'", ARRAY_A);

            foreach ($keys as $k) {
                $requests[$k['lang_code']] = $this->formatDate($k['value']);
            }
        }

        return $requests;
    }

    public function getLastResyncs()
    {
        $resyncs = array();

        if (empty($resyncs)) {
            $keys = $this->wpdb->get_results("SELECT value, lang_code FROM {$this->wpdb->prefix}wc_se_settings WHERE name = 'last_resync'", ARRAY_A);

            foreach ($keys as $k) {
                $resyncs[$k['lang_code']] = $this->formatDate($k['value']);
            }
        }

        return $resyncs;
    }

    public function formatDate($timestamp)
    {
        if (empty($timestamp)) {
            return '';
        }

        $date_format = get_option('date_format');
        $time_format = get_option('time_format');

        return date_i18n($date_format, $timestamp) . ' ' . date_i18n($time_format, $timestamp);
    }

    public function checkAutoInstall()
    {
        return $this->getSetting('auto_installed') != 'Y';
    }

    public function setAutoInstall($value = false)
    {
        $this->setSetting('auto_installed', '', $value == true ? 'Y' : 'N');
    }

    public function getResyncInterval()
    {
        return $this->getSystemSetting('resync_interval', 'daily');
    }

    public function getIndexInterval()
    {
        return 'every_minute';
    }

    public function checkCronAsyncEnabled()
    {
        return $this->getSystemSetting('cron_async_enabled', 'N') == 'Y';
    }

    public function checkAjaxAsyncEnabled()
    {
        return $this->getSystemSetting('ajax_async_enabled', 'N') == 'Y';
    }

    public function checkObjectAsyncEnabled()
    {
        return $this->getSystemSetting('object_async_enabled', 'Y') == 'Y';
    }

    public function getSyncMode()
    {
        return $this->getSystemSetting('sync_mode', self::SYNC_MODE_REALTIME);
    }

    public function isRealtimeSyncMode()
    {
        return $this->getSyncMode() == self::SYNC_MODE_REALTIME;
    }

    public function isPeriodicSyncMode()
    {
        return $this->getSyncMode() == self::SYNC_MODE_PERIODIC;
    }

    public function isManualSyncMode()
    {
        return $this->getSyncMode() == self::SYNC_MODE_MANUAL;
    }

    public function getSuggestionsMaxResults()
    {
        return self::SUGGESTIONS_MAX_RESULTS;
    }

    public function getSearchInputSelector()
    {
        return $this->getSystemSetting('search_input_selector', htmlentities(stripslashes(self::DEFAULT_SEARCH_FIELD_ID)));
    }

    public function useDirectImageLinks()
    {
        return $this->getSystemSetting('use_direct_image_links', 'N') == 'Y';
    }

    public function importBlockPosts()
    {
        return $this->getSystemSetting('import_block_posts', 'N') == 'Y';
    }

    public function isShowAnalyticsOnDashboard()
    {
        return $this->getSystemSetting('show_analytics_on_dashboard', 'N') == 'Y';
    }

    public function isUseWpJquery()
    {
        return $this->getSystemSetting('use_wp_jquery', 'N') == 'Y';
    }

    public function importAlsoBoughtProducts()
    {
        return SeAsync::IMPORT_ALSO_BOUGHT_PRODUCTS;
    }

    public function setResultWidgetEnabled($status, $lang_code)
    {
        $this->setSetting('result_widget_enabled', $lang_code, $status);
    }

    public function isResultWidgetEnabled($lang_code)
    {
        return $this->getSetting('result_widget_enabled', $lang_code) == 'Y';
    }

    public function getSearchResultsPage()
    {
        return $this->getSystemSetting('search_result_page', self::DEFAULT_SEARCH_RESULTS_PAGE);
    }

    public function getAsyncMemoryLimit()
    {
        return SE_MEMORY_LIMIT;
    }

    public function getMaxProcessingTime()
    {
        return SE_MAX_PROCESSING_TIME;
    }

    public function getMaxErrorCount()
    {
        return SE_MAX_ERROR_COUNT;
    }

    public function getProductsPerPass()
    {
        return SE_PRODUCTS_PER_PASS;
    }

    public function getCategoriesPerPass()
    {
        return SE_CATEGORIES_PER_PASS;
    }

    public function getPagesPerPass()
    {
        return SE_PAGES_PER_PASS;
    }

    public function setNotificationAsyncCompleted($status = true)
    {
        $this->setSystemSetting('notification_async_complete', $status);
    }

    public function checkNotificatonAsyncCompleted()
    {
        return $this->getSystemSetting('notification_async_complete') == true;
    }

    public function getColorAttributes()
    {
        $attributes = $this->getSystemSetting('color_attribute');
        return array_map('trim', explode(',', $attributes));
    }

    public function getSizeAttributes()
    {
        $attributes = $this->getSystemSetting('size_attribute');
        return array_map('trim', explode(',', $attributes));
    }

    public function getIsRated()
    {
        return $this->getSystemSetting('admin_footer_text_rated') == 'Y';
    }

    public function setIsRated()
    {
        $this->setSystemSetting('admin_footer_text_rated', 'Y');
    }

    public function setIsNeedReindexation($value)
    {
        $this->setSystemSetting('need_reindexation', $value == true ? 'Y' : 'N');
    }

    public function getIsNeedReindexation()
    {
        return $this->getSystemSetting('need_reindexation') == 'Y';
    }

    public function deleteKeys($lang_code = null)
    {
        $engines = $this->getEngines($lang_code);

        foreach ($engines as $engine) {
            $this->addonStatusRequest(self::ADDON_STATUS_DELETED, $engine['lang_code']);
            SeQueue::getInstance()->clearActions($engine['lang_code']);

            $this->setApiKey('', $lang_code);
            $this->setPrivateKey('', $lang_code);
            $this->setExportStatus(self::EXPORT_STATUS_NONE, $engine['lang_code']);
        }
    }

    public function getCurLabelForPricesUsergroup()
    {
        $current_user = wp_get_current_user();

        if (!empty($current_user->roles)) {
            return self::LABEL_FOR_PRICES_USERGROUP . $current_user->roles[0];
        } else {
            return '';
        }
    }

    public function getCurrentUsergroupIds()
    {
        $current_user = wp_get_current_user();
        $default_usergroup = array(self::USERGROUP_GUEST);

        return array_merge($default_usergroup, $current_user->roles);
    }

    /**
     * Returns Searchanise addon options
     * 
     * @return array
     */
    public function getAddonOptions()
    {
        global $wp_version, $wp_db_version, $wp_local_package;

        $ret = array();

        $ret['parent_private_key']      = $this->getParentPrivateKey();
        $ret['private_key']             = $this->getPrivateKeys();
        $ret['api_key']                 = $this->getApiKeys();
        $ret['export_status']           = $this->getExportStatuses();

        $ret['last_request']            = $this->getLastRequests();
        $ret['last_resync']             = $this->getLastResyncs();

        $ret['addon_status']            = $this->getModuleStatus() == 'Y' ? 'enabled' : 'disabled';
        $ret['addon_version']           = $this->getSystemSetting('version');

        $ret['php_verison']             = PHP_VERSION;

        // Get WP version
        $ret['wordpress_version']       = $wp_version;
        $ret['wordpress_db_version']    = $wp_db_version;
        $ret['wordpress_local_package'] = $wp_local_package;
        $ret['wordpress_path']          = ABSPATH;

        // Get WooCommerce version
        $ret['woocommerce'] = get_plugin_data(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'woocommerce/woocommerce.php');

        $ret['plugins'] = get_plugins();

        foreach ($ret['plugins'] as $plugin_key => $plugin) {
            $ret['plugins'][$plugin_key]['Status'] = is_plugin_active($plugin_key) ? 'A' : 'D';
        }

        return (array)apply_filters('se_addon_options', $ret);
    }

    public function getEnabledSearchaniseSearch()
    {
        return $this->getSystemSetting('enabled_searchanise_search', 'Y') == 'Y';
    }

    /**
     * Returns store name by lang_code
     * 
     * @param string $lang_code  Lang code
     * @return string Store name
     */
    public function getStoreName($lang_code)
    {
        if (!function_exists('wp_get_available_translations')) {
            require_once(ABSPATH . 'wp-admin/includes/translation-install.php');
        }

        static $names = array();

        if (!isset($names[$lang_code])) {
            $available_translations = wp_get_available_translations();
            $names[$lang_code]      = $lang_code == 'en_US' ? 'English' : $available_translations[$lang_code]['english_name'];
        }

        return $names[$lang_code];
    }

    /**
     * Returns ISO language name by lang_code
     * 
     * @param string $lang_code  Lang code
     * @return string ISO name
     */
    public function getIsoLangName($lang_code)
    {
        if (!function_exists('wp_get_available_translations')) {
            require_once(ABSPATH . 'wp-admin/includes/translation-install.php');
        }

        static $names = array();

        if (!isset($names[$lang_code])) {
            $available_translations = wp_get_available_translations();

            if (isset($available_translations[$lang_code])) {
                $names[$lang_code] = current($available_translations[$lang_code]['iso']);
            } else {
                $names[$lang_code] = 'en';
            }
        }

        return $names[$lang_code];
    }

    /**
     * Check module status
     * 
     * @return boolean
     */
    public function getModuleStatus()
    {
        return
            SeInstaller::isSearchaniseInstalled()
            && (is_plugin_active('woocommerce/woocommerce.php') || is_plugin_active_for_network('woocommerce/woocommerce.php')) ? 'Y' : 'N';
    }

    /**
     * Check and test enviroments
     *
     * @param boolean $display_errors
     */
    public function checkEnviroments($display_errors = true)
    {
        if (
            defined('DOING_AJAX') && DOING_AJAX
            || defined('DOING_CRON') && DOING_CRON
            || !is_admin()
        ) {
            return;
        }

        $errors = array();

        if (!ApiSe::getInstance()->testConnect()) {
            $errors[] = sprintf(__('Searchanise: There is no connection to Searchanise server! For Searchanise to work properly, the store server must be able to access %s. Please contact Searchanise <a href="mailto: feedback@searchanise.com">feedback@searchanise.com</a> technical support or your system administrator.', 'woocommerce-searchanise'), SE_SERVICE_URL);
        }

        if (!SeQueue::getInstance()->getQueueStatus()) {
            $errors[] = __('Searchanise: We found an issue with the export of changes in your store catalog. To resolve the issue please contact Searchanise <a href="mailto:feedback@searchanise.com">feedback@searchanise.com</a> technical support.', 'woocommerce-searchanise');
        }

        if (!empty($errors) && $display_errors == true) {
            foreach ($errors as $error) {
                add_action('admin_notices', function() use ($error) {
                    echo '<div class="error notice"><p>' . $error . '</p></div>';
                });
            }
        }

        return $errors;
    }

    /**
     * Get Searchanise admin url
     * 
     * @param string $mode  Searchanise admin mode
     * @return string
     */
    public function getAdminUrl($mode = '')
    {
        return get_admin_url(null, 'edit.php?post_type=product&page=searchanise' . ($mode != '' ? '&mode=' . $mode : ''));
    }

    /**
     * Get frontend url with parameters
     * 
     * @string $lang_code Lang code
     * @param array $params Query parameters
     * @return string
     */
    public function getFrontendUrl($lang_code, $params = array()) {
        $site_url = get_site_url();
        $separator = strpos($site_url, '?') === false ? '?' : '&';

        if (!empty($params)) {
            $query = http_build_query($params);
        }

        $url = $site_url . (!empty($query) ? $separator . $query : '');

        /**
         * Filters frontend url
         * 
         * @param string $url Frontend url
         * @param string $lang_code Lang code
         * @param array $params Url params
         */
        return apply_filters('se_get_frontend_url', $url, $lang_code, $params);
    }

    /**
     * Get engines data
     * 
     * @param $lang_code  Engine lang_code
     * @return array
     */
    public function getEngines($lang_code = null, $use_cache = true)
    {
        static $engines_data = array();

        if (!empty($lang_code)) {
            $active_languages = array($lang_code);

        } else {
            $active_languages = array($this->getLocale());
            $active_languages = (array)apply_filters('se_get_active_languages', $active_languages, $lang_code);
        }

        $engines = array();
        foreach ($active_languages as $lang_code) {
            if ($use_cache && !empty($engines_data[$lang_code])) {
                $engines[$lang_code] = $engines_data[$lang_code];

            } else {
                $engines[$lang_code] = $engines_data[$lang_code] = array(
                    'lang_code'          => $lang_code,
                    'status'             => 'A',
                    'language_name'      => $this->getStoreName($lang_code),
                    'url'                => $this->getFrontendUrl($lang_code),
                    'api_key'            => $this->getApiKey($lang_code),
                    'private_key'        => $this->getPrivateKey($lang_code),
                    'parent_private_key' => $this->getParentPrivateKey(),
                    'export_status'      => $this->getExportStatus($lang_code),
                );
            }
        }

        return (array)apply_filters('se_get_engines', $engines, $lang_code);
    }

    public function parseResponse($response, $show_notification = false)
    {
        $data = json_decode($response, true);

        if (empty($data)) {
            return false;
        }

        if (!empty($data['errors']) && is_array($data['errors'])) {
            if ($show_notification == true) {
                foreach ($data['errors'] as $e) {
                    $this->addAdminNotitice(__('Searchanise') . ' :' . (string)$e, 'error');
                }
            } else {
                throw new SeException(implode(',', $data['errors']));
            }

            return false;
        } elseif ($data === 'ok') {
            return true;
        } else {
            return $data;
        }
    }

    /**
     * Test connect to Searchanise
     * 
     * @return boolean
     */
    public function testConnect($timeout = 5)
    {
        $passed = true;

        $result = wp_remote_get(SE_SERVICE_URL . '/api/test', array(
            'timeout' => $timeout,
        ));

        if (!is_wp_error($result)) {
            $response = wp_remote_retrieve_body($result);
            $passed = $response == 'OK';
        } else {
            $passed = false;
        }

        return $passed;
    }

    /**
     * Send api request
     * 
     * @param string $url Api url
     * @param string $private_key Engine private key
     * @param array $data Data for send
     * @param boolean $onlyHttp Using http or https
     * 
     * @return string Response data
     */
    public function sendRequest($url, $private_key, $data = array(), $onlyHttp = true)
    {
        $response =  false;
        $params = array('private_key' => $private_key) + $data;

        SeLogger::getInstance()->debug(array_merge(array(
            'url'      => SE_SERVICE_URL . $url,
            'timeout'  => SE_REQUEST_TIMEOUT,
            'method'   => 'post',
            'onlyHttp' => $onlyHttp,
        ), $params));

        if (!empty($params['private_key'])) {
            $result = wp_remote_post(SE_SERVICE_URL . $url, array(
                'timeout' => SE_REQUEST_TIMEOUT,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'
                ),
                'body'    => $params,
            ));

            if (!is_wp_error($result)) {
                $response_body = wp_remote_retrieve_body($result);

                if (!empty($response_body)) {
                    try {
                        $response = $this->parseResponse($response_body);
                    } catch (SeException $e) {
                        SeLogger::getInstance()->error(array(
                            'error_message' => $e->getMessage()
                        ));
                    }
                }

                SeLogger::getInstance()->debug(array(
                    'response_body' => $response_body,
                ));

            } else {
                $message = sprintf(__('Searchanise: Error occurs during http request: %s'), $result->get_error_message());
                $this->addAdminNotitice($message);
                SeLogger::getInstance()->error(array(
                    'error_message' => $result->get_error_message(),
                ));
            }
                
            $this->setLastRequest(time());

        } else {
            SeLogger::getInstance()->debug(array(
                'error_message' => __('Empty private key', 'woocommerce-searchanise'),
            ));
        }

        return $response;
    }

    /**
     * Send addon status to Searchanise
     * 
     * @param string $status Addon status
     * @param string $lang_code Lang Code
     * 
     * @return boolean
     */
    public function addonStatusRequest($status, $lang_code)
    {
        $private_key = $this->getPrivateKey($lang_code);

        if (!empty($private_key)) {
            $result = wp_remote_post(SE_SERVICE_URL . '/api/state/update/json', array(
                'timeout' => SE_REQUEST_TIMEOUT,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'
                ),
                'body' => array(
                    'private_key'  => $private_key,
                    'addon_status' => $status
                )
            ));

            return !is_wp_error($result);
        }

        return false;
    }

    /**
     * Signup
     * 
     * @param string $lang_code Lang code for signup
     * @param boolean $showNotifications If true notifications will be shown
     * @param boolean $flSendRequest If true, addon status will be sent to Searchanise
     * 
     * @return string Signup status
     */
    public function signup($lang_code = null, $showNotifications = true, $flSendRequest = true)
    {
        if (php_sapi_name() == 'cli' && !defined('PHPUNIT_SEARCHANISE')) {
            return false;
        }

        @ignore_user_abort(true);
        @set_time_limit(3600);

        if ($this->checkAutoInstall()) {
            $this->setAutoInstall(true);
        }

        $connected = false;
        $current_user = wp_get_current_user();

        if (!empty($current_user)) {
            if (!defined('PHPUNIT_SEARCHANISE')) {
                $email = $current_user->user_email;
            } else {
                $email = 'admin@example.com'; // email for unit tests
            }
        }

        if (!empty($email)) {
            $engines_data       = $this->getEngines($lang_code, false);
            $parent_private_key = $this->getParentPrivateKey();

            foreach ($engines_data as $engine_data) {
                $lang_code   = $engine_data['lang_code'];
                $private_key = $engine_data['private_key'];

                if (!empty($private_key)) {
                    if ($flSendRequest) {
                        $this->addonStatusRequest(self::ADDON_STATUS_ENABLED, $lang_code);
                    }

                    continue;
                }

                if ($showNotifications == true) {
                    $this->echoProgress('Connecting to Searchanise..');
                }

                $request = wp_remote_post(SE_SERVICE_URL . '/api/signup/json', array(
                    'timeout' => SE_REQUEST_TIMEOUT,
                    'headers' => array(
                        'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'
                    ),
                    'body' => array(
                        'url'                => $engine_data['url'],
                        'email'              => $email,
                        'language'           => $lang_code,
                        'parent_private_key' => $parent_private_key,
                        'version'            => SE_PLUGIN_VERSION,
                        'platform'           => SE_PLATFORM,
                    ),
                ));

                if (!is_wp_error($request)) {
                    $response = wp_remote_retrieve_body($request);
                }

                if ($showNotifications == true) {
                    $this->echoProgress('.');
                }

                if (!empty($response)) {
                    $response = $this->parseResponse($response, $showNotifications);

                    if (!empty($response['keys']['api']) && !empty($response['keys']['private'])) {
                        $api_key = (string)$response['keys']['api'];
                        $private_key = (string)$response['keys']['private'];

                        if (empty($api_key) || empty($private_key)) {
                            return false;
                        }

                        if (empty($parent_private_key)) {
                            $this->setParentPrivateKey($private_key);
                            $parent_private_key = $private_key;
                        }

                        $this->setApiKey($api_key, $lang_code);
                        $this->setPrivateKey($private_key, $lang_code);

                        $connected = true;
                    }
                } else {
                    if ($showNotifications == true) {
                        $this->echoProgress(' Error<br />');
                    }

                    return false;
                }

                $this->setExportStatus(self::EXPORT_STATUS_NONE, $lang_code);
            }
        } else {
            // Empty email
            return false;
        }

        if ($connected) {
            if ($showNotifications == true) {
                $this->echoProgress('Done<br />');
                $this->addAdminNotitice(__('Congratulations, you\'ve just connected to Searchanise'), 'success');
            }
        }

        return true;
    }

    /**
     * Start full import
     * 
     * @param string $lang_code Lang code fro queue import. If null, all engines will be imported
     * @param boolean $showNotifications If true, notification will be shown
     * 
     * @return boolean
     */
    public function queueImport($lang_code = null, $showNotifications = true)
    {
        if (!$this->checkParentPrivateKey()) {
            return false;
        }

        $this->setNotificationAsyncCompleted(false);

        SeQueue::getInstance()
            ->clearActions($lang_code)
            ->addAction(SeQueue::PREPARE_FULL_IMPORT, SeQueue::NO_DATA, $lang_code);

        $engines = $this->getEngines($lang_code, false);
        foreach ($engines as $engine) {
            $this->setExportStatus(self::EXPORT_STATUS_QUEUED, $engine['lang_code']);
        }

        $this->sendAddonVersion();

        if ($showNotifications) {
            $this->addAdminNotitice(__('The product catalog is queued for syncing with Searchanise', 'woocommerce-searchanise'), 'success');
        }

        return true;
    }

    /**
     * Check if import is completed and display a message
     */
    public function showNotificationAsyncCompleted()
    {
        if (!$this->checkNotificatonAsyncCompleted()) {
            $all_stores_done = true;

            $engines = $this->getEngines();

            foreach ($engines as $engine) {
                if (!$this->checkExportStatus($engine['lang_code'])) {
                    $all_stores_done = false;
                    break;
                }
            }

            if ($all_stores_done) {
                $this->addAdminNotitice(sprintf(__('Catalog indexation is complete. Configure Searchanise via the <a href="%s">Admin Panel</a>.', 'woocommerce-searchanise'), $this->getAdminUrl('')), 'success');
                $this->setNotificationAsyncCompleted(true);
            }
        }

        return true;
    }

    /**
     * Adds admin notice to queue
     */
    public function addAdminNotitice($message, $type = 'notice')
    {
        $admin_notices = $this->getSystemSetting('admin_notices');
        $admin_notices = is_array($admin_notices) ? $admin_notices : array();
        $admin_notices[] = compact('type', 'message');
        $this->setSystemSetting('admin_notices', $admin_notices);
    }

    /**
     * Get all admin notices
     * 
     * @param bool $clear  If true, notices will be erased
     * @return array
     */
    public function getAdminNotices($clear = true)
    {
        $admin_notices = $this->getSystemSetting('admin_notices');

        if ($clear == true) {
            $this->setSystemSetting('admin_notices', array());
        }

        return is_array($admin_notices) ? $admin_notices : array();
    }

    /**
     * Check if search is allowed for Engine
     * 
     * @param sting $lang_code Lang code
     * @return boolean
     */
    public function isSearchAllowed($lang_code)
    {
        return in_array($this->getExportStatus($lang_code), array(
            self::EXPORT_STATUS_QUEUED,
            self::EXPORT_STATUS_START,
            self::EXPORT_STATUS_PROCESSING,
            self::EXPORT_STATUS_SENT,
            self::EXPORT_STATUS_DONE,
        ));
    }

    /**
     * Checks if async needed
     * 
     * @param string $lang_code
     * @return boolean|string
     */
    public function checkStartAsync($lang_code = null)
    {
        $ret = false;
        $q = SeQueue::getInstance()->getNextQueue($lang_code);

        if (!empty($q)) {
            if (SeQueue::isQueueRunning($q)) {
                // Nothing

            } elseif (SeQueue::isQueueHasError($q)) {
                $status = $this->getExportStatus($q->lang_code);

                if ($status != self::EXPORT_STATUS_SYNC_ERROR) {
                    $this->setExportStatus(self::EXPORT_STATUS_SYNC_ERROR, $q->lang_code);
                }

            } else {
                $ret = $q->lang_code;
            }
        }

        return $ret;
    }

     /**
     * Returns currency rate
     * 
     * @return float
     */
    public function getCurrencyRate()
    {
        $currency_rate = 1.0;

        return apply_filters('se_get_currency_rate', $currency_rate);
    }

    /**
     * Print_r function custom wrapper
     */
    public function printR()
    {
        $args = func_get_args();
        echo '<ol style="font-family: Courier; font-size: 12px; border: 1px solid #dedede; background-color: #efefef; float: left; padding-right: 20px;">';
        foreach ($args as $v) {
            echo '<li><pre>' . htmlspecialchars(print_r($v, true)) . "\n" . '</pre></li>';
        }
        echo '</ol><div style="clear:left;"></div>';
    }

    /**
     * Test if locale is default
     * 
     * @param string $lang_code Lang code
     *
     * @return bool
     */
    public function isDefaultLocale($lang_code)
    {
        return $lang_code == $this->getDefaultLocale();
    }

    /**
     * Returns default db locale
     * 
     * @return string Locale or false
     */
    public function getDefaultLocale()
    {
        $locale = false;

        if (is_multisite()) {
            if (wp_installing()) {
                $ms_locale = get_site_option('WPLANG');
            } else {
                $ms_locale = get_option('WPLANG');

                if (false === $ms_locale) {
                    $ms_locale = get_site_option('WPLANG');
                }
            }

            if ($ms_locale !== false) {
                $locale = $ms_locale;
            }
        } else {
            $db_locale = get_option('WPLANG');

            if ($db_locale !== false) {
                $locale = $db_locale;
            }
        }

        if (empty($locale)) {
            $locale = 'en_US';
        }

        return $locale;
    }

    /**
     * Returns current active locale
     * 
     * @return string
     */
    public function getLocale($lang_code = null)
    {
        if ($lang_code == 'default') {
            $lang_code = $this->getDefaultLocale();
        }

        if (empty($lang_code)) {
            $lang_code = get_locale();
        }

        return $lang_code;
    }

    /**
     * Returns locale name for settings
     * 
     * @param string $lang_code
     * @return string
     */
    public function getLocaleSettings($lang_code = '')
    {
        return $this->isDefaultLocale($lang_code) ? 'default' : $lang_code;
    }

    /**
     * Escape string for JavaScript
     * 
     * @param string $str   Input string
     * @return string
     */
    public function escapeJavascript($str)
    {
        $str = html_entity_decode($str);
        // remove carriage return
        $str = str_replace("\r", '', (string) $str);
        // escape all characters with ASCII code between 0 and 31
        $str = addcslashes($str, "\0..\37'\\");
        // escape double quotes
        $str = str_replace('"', '\"', $str);
        // replace \n with double quotes
        $str = str_replace("\n", '\n', $str);

        return $str;
    }

    /**
     * Sends addon version to Searchanise
     *
     * @return bool
     */
    public function sendAddonVersion()
    {
        $result = false;
        $parent_private_key = $this->getParentPrivateKey();

        if (!empty($parent_private_key)) {
            $addon_options = $this->getAddonOptions();
            $result = $this->sendRequest('/api/state/update/json', $parent_private_key, array(
                'addon_version'    => $addon_options['addon_version'],
                'platform_edition' => '',
                'platform_version' => !empty($addon_options['woocommerce']) ? $addon_options['woocommerce']['Version'] : '',
            ), true);
        }

        return $result;
    }
}
