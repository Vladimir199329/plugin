<?php

// if uninstall.php is not called by WordPress, die
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once dirname(__FILE__) . '/init.php';

$routes = array(
    'se-core-functions.php',
    'se-upgrade-functions.php',
    'class-abstract-extension.php',
    'class-se-exceptions.php',
    'class-se-installer.php',
    'class-se-cron.php',
    'class-se-api.php',
    'class-se-queue.php',
);

fn_se_define_constants();
fn_se_init_routes($routes);

$engines = ApiSe::getInstance()->getEngines();
foreach ($engines as $engine) {
    ApiSe::getInstance()->addonStatusRequest(ApiSe::ADDON_STATUS_DELETED, $engine['lang_code']);
}

SeQueue::getInstance()->clearActions();
SeCron::unregister();
SeInstaller::uninstall();
