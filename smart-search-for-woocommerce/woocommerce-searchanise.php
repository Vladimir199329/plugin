<?php
/**
 * Plugin Name: Smart WooCommerce Search by Searchanise
 * Plugin URI: http://start.searchanise.com/
 * Description: Searchanise shows product previews, relevant categories, pages, and search suggestions as you type.
 * Version: 1.0.9
 * Author: Searchanise
 * Author URI: http://start.searchanise.com/
 * Donate-Link: http://start.searchanise.com/
 * License: GPLv3
 * WC requires at least: 3.0.0
 * WC tested up to: 5.1.0
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once dirname(__FILE__) . '/init.php';

// WooCommerce is enabled, load scripts
$routes = array(
    'class-abstract-extension.php',
    'class-se-exceptions.php',
    'class-se-logger.php',
    'class-se-profiler.php',
    'class-se-installer.php',
    'class-se-upgrade.php',
    'class-se-cron.php',
    'class-se-api.php',
    'class-se-queue.php',
    'class-se-hooks.php',
    'class-se-searchanise.php',
    'class-se-recommendations.php',
    'class-se-async.php',
    'class-se-info.php',
    'class-se-search.php',
    'class-se-dashboard.php',
);

// Init backend / frontend routes
if (is_admin() && !defined('DOING_AJAX')) {
    $routes[] = 'class-se-admin.php';
}

// Makes sure the plugin is defined before trying to use it
if (!function_exists('is_plugin_active')) {
    require_once(ABSPATH . '/wp-admin/includes/plugin.php');
}

if (file_exists(dirname(__FILE__) . '/local_conf.php')) {
    include dirname(__FILE__) . '/local_conf.php';
}

// Init
fn_se_define_constants();
fn_se_init_routes($routes);
fn_se_load_extensions();

add_action('init', 'fn_se_load_plugin_textdomain');
