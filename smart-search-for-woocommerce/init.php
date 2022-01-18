<?php

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Initialized Searchanise variables
 */
function fn_se_define_constants()
{
    $upload_dir = wp_upload_dir(null, false);

    fn_se_define('SE_DEBUG_LOG', false);     // Log debug messages
    fn_se_define('SE_ERROR_LOG', false);     // Log error messages
    fn_se_define('SE_DEBUG', false);         // Print debug & error messages

    fn_se_define('SE_REQUEST_TIMEOUT', 30);  // API request timeout

    fn_se_define('SE_PRODUCTS_PER_PASS', 100);
    fn_se_define('SE_CATEGORIES_PER_PASS', 500);
    fn_se_define('SE_PAGES_PER_PASS', 100);

    fn_se_define('SE_VERSION', '1.3');
    fn_se_define('SE_PLUGIN_VERSION', '1.0.9');
    fn_se_define('SE_MEMORY_LIMIT', 512);
    fn_se_define('SE_MAX_ERROR_COUNT', 3);
    fn_se_define('SE_MAX_PROCESSING_TIME', 720);
    fn_se_define('SE_MAX_SEARCH_REQUEST_LENGTH', 8000);
    fn_se_define('SE_SERVICE_URL', 'http://searchserverapi.com');
    fn_se_define('SE_PLATFORM', 'woocommerce');
    fn_se_define('SE_SUPPORT_EMAIL', 'feedback@searchanise.com');

    fn_se_define('SE_ABSPATH', dirname(__FILE__));
    fn_se_define('SE_PLUGIN_BASENAME', plugin_basename(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'woocommerce-searchanise.php'));
    $wp_plugin_dir = str_replace(array('\\', '/'), DIRECTORY_SEPARATOR, WP_PLUGIN_DIR);
    fn_se_define('SE_BASE_DIR', str_replace($wp_plugin_dir, '', dirname(__FILE__)));
    fn_se_define('SE_LOG_DIR', $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'se_logs');
}

function fn_se_define($name, $val)
{
    if (!defined($name)) {
        define($name, $val);
    }
}

/**
 * Init Searchanise routes
 * 
 * @param array Routes list
 */
function fn_se_init_routes(array $routes)
{
    $base_dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'includes';

    foreach ($routes as $route) {
        if (file_exists($base_dir . DIRECTORY_SEPARATOR . $route)) {
            require_once($base_dir . DIRECTORY_SEPARATOR . $route);
        }
    }
}

/**
 * Load extensions
 * 
 * @param string $ext_dir Extensions directory
 * @return boolean
 */
function fn_se_load_extensions($ext_dir = SE_ABSPATH . DIRECTORY_SEPARATOR. 'extensions')
{
    $files = scandir($ext_dir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && file_exists($ext_dir . DIRECTORY_SEPARATOR . $file) && is_readable($ext_dir . DIRECTORY_SEPARATOR . $file) && preg_match('/\.php$/', $file)) {
            include_once $ext_dir . DIRECTORY_SEPARATOR . $file;
        }
    }

    return true;
}

/**
 * Loads localization files from:
 *    - WP_LANG_DIR/woocommerce-searchanise/woocommerce-searchanise-LOCALE.mo
 *    - WP_LANG_DIR/plugins/woocommerce-searchanise-LOCALE.mo
 */
function fn_se_load_plugin_textdomain()
{
    $locale = apply_filters('se_locale', get_locale(), 'woocommerce-searchanise');
    load_textdomain('woocommerce-searchanise', WP_LANG_DIR . DIRECTORY_SEPARATOR . 'woocommerce-searchanise' . DIRECTORY_SEPARATOR . 'woocommerce-searchanise-' . $locale . '.mo' );
    load_plugin_textdomain('woocommerce-searchanise', false, plugin_basename(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'i18n');
}
