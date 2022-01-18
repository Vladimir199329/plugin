<?php

defined('ABSPATH') || exit;

class SeInstaller
{
    /**
     * Install Searchanise data
     */
    public static function install()
    {
        // Recreate search results page
        self::deleteSearchResultsPage();
        self::createSearchResultsPage();

        if (!self::isSearchaniseInstalled()) {
            $tables_result = self::createTables();
            $settings_result = self::setDefaultSettings();

            return !empty($tables_result) && !empty($settings_result);
        }

        return true;
    }

    /**
     * Uninstall Searchanise data
     */
    public static function uninstall()
    {
        self::deleteSearchResultsPage();
    }

    /**
     * Returns true if Searchanise was installed.
     * 
     * @return boolean true if Searchanise is installed.
     */
    public static function isSearchaniseInstalled()
    {
        global $wpdb;

        $wc_se_settings_row = $wpdb->get_row("SELECT * FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = '{$wpdb->prefix}wc_se_settings'", ARRAY_A);

        return !empty($wc_se_settings_row) && ApiSe::getInstance()->getSystemSetting('version') != '';
    }

    /**
     * Returns true if Searchanse was installed and successfully registered
     * 
     * @return boolean
     */
    public static function isSearchaniseRegistered()
    {
        global $wpdb;

        $registered = false;

        if (self::isSearchaniseInstalled()) {
            $registered = (bool)$wpdb->get_var("SELECT count(*) FROM {$wpdb->prefix}wc_se_settings WHERE name = 'parent_private_key'");
        }

        return $registered;
    }

    /**
     * Create Searchanise search result page
     * 
     * @param array $update_params Additional page params
     * @param boolean $force_update If true, page content will be updated
     * 
     * @return int $page_id
     */
    public static function createSearchResultsPage(array $update_params = array(), $force_update = false)
    {
        global $wpdb;
        static $post_id = null;

        $can_edit_post = current_user_can('edit_posts');

        if ($post_id == null) {
            $page_name = ApiSe::getInstance()->getSystemSetting('search_result_page');
            $post_id = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE '{$page_name}' OR post_name LIKE '{$page_name}__trashed' LIMIT 1");
        }

        if ($can_edit_post && (empty($post_id) || $force_update)) {
            $content = <<< JS
<!-- wp:html -->
<!-- Do NOT edit this page. Searchanise shows the search results here -->
<div class="snize" id="snize_results"></div>
<!-- /wp:html -->

<!-- wp:paragraph -->
<p></p>
<!-- /wp:paragraph -->
JS;
            $post_data = array(
                'post_title'     => __('Search results', 'woocommerce-searchanise'),
                'comment_status' => 'closed',
                'post_excerpt'   => '',
                'post_content'   => $content,
                'post_status'    => 'publish',
                'post_author'    => get_current_user_id(),
                'post_name'      => $page_name,
                'post_type'      => 'page',
                'page_template'  => 'template-fullwidth.php',
            );

            $post_data = array_merge($post_data, $update_params);
            if ($force_update && !empty($post_id)) {
                $post_data['ID'] = $post_id;
            }

            if (!defined('WP_POST_REVISIONS')) {
                define('WP_POST_REVISIONS', true);
            }

            $post_id = wp_insert_post($post_data);

            if (is_wp_error($post_id)) {
                $post_id = 0;
            }
        }

        return $post_id;
    }

    /**
     * Delete Searchanise Result Page
     */
    public static function deleteSearchResultsPage()
    {
        global $wpdb;

        $page_name = ApiSe::getInstance()->getSystemSetting('search_result_page');
        if (is_multisite() && is_plugin_active_for_network(SE_ABSPATH)) {
            $database_tables = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT table_name AS name FROM information_schema.TABLES WHERE table_schema = %s ORDER BY name ASC;", DB_NAME
                )
            );
            foreach ($database_tables as $table) {
                if (strpos($table->name, '_posts')) {
                    $id = $wpdb->get_var("SELECT ID FROM {$table->name} WHERE post_name = '{$page_name}'");

                    if (!empty($id)) {
                        $result = $wpdb->delete($table->name, array('ID' => $id));
                    }
                }
            }

        } else {
            $id = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_name = '{$page_name}'");

            if (!empty($id)) {
                wp_delete_post($id, true);
            }
        }

        return true;
    }

    /**
     * Create Searchanise tables
     */
    public static function createTables()
    {
        global $wpdb;

        $collate = '';
        $result = true;

        if ($wpdb->has_cap('collation')) {
            $collate = $wpdb->get_charset_collate();
        }

        $tables = array(
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_se_settings ("
            . " name varchar(32) NOT NULL default '',"
            . " lang_code char(8) NOT NULL default 'default',"
            . " value varchar(255) NOT NULL default '',"
            . ' PRIMARY KEY (name, lang_code)'
            . ") $collate;",
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_se_queue ("
            . ' queue_id mediumint NOT NULL auto_increment,'
            . ' data text,'
            . " action varchar(32) NOT NULL default '',"
            . " lang_code char(8) NOT NULL default '',"
            . " started int(11) NOT NULL DEFAULT 0,"
            . " status enum('pending', 'processing') default 'pending',"
            . " priority int(2) NOT NULL DEFAULT 1,"
            . " attempts int(2) NOT NULL DEFAULT 0,"
            . " error MEDIUMTEXT NULL DEFAULT NULL,"
            . ' PRIMARY KEY (queue_id),'
            . ' KEY status (`status`),'
            . ' KEY StoreAction (`lang_code`,`action`)'
            . ") $collate;",
        );

        foreach ($tables as $table) {
            if ($wpdb->query($table) === false) {
                $result = false;
                $wpdb->print_error();
            }
        }

        return $result;
    }

    /**
     * Set default settings
     */
    static function setDefaultSettings()
    {
        global $wp_version;

        ApiSe::getInstance()->setSystemSetting('version', SE_PLUGIN_VERSION);
        ApiSe::getInstance()->setSystemSetting('search_input_selector', htmlentities(stripslashes(ApiSe::DEFAULT_SEARCH_FIELD_ID)));
        ApiSe::getInstance()->setSystemSetting('search_result_page', ApiSe::DEFAULT_SEARCH_RESULTS_PAGE);
        ApiSe::getInstance()->setSystemSetting('sync_mode', ApiSe::SYNC_MODE_REALTIME);
        ApiSe::getInstance()->setSystemSetting('use_direct_image_links', 'N');
        ApiSe::getInstance()->setSystemSetting('enabled_searchanise_search', 'Y');
        ApiSe::getInstance()->setSystemSetting('resync_interval', 'daily');
        ApiSe::getInstance()->setSystemSetting('cron_async_enabled', 'Y');
        ApiSe::getInstance()->setSystemSetting('ajax_async_enabled', 'N');
        ApiSe::getInstance()->setSystemSetting('object_async_enabled', 'Y');
        ApiSe::getInstance()->setSystemSetting('color_attribute', ApiSe::DEFAULT_COLOR_NAME);
        ApiSe::getInstance()->setSystemSetting('size_attribute', ApiSe::DEFAULT_SIZE_NAME);
        ApiSe::getInstance()->setSystemSetting('import_block_posts', 'Y');
        ApiSe::getInstance()->setSystemSetting('admin_footer_text_rated', 'N');
        ApiSe::getInstance()->setSystemSetting('need_reindexation', 'N');

        if (version_compare($wp_version, ApiSe::MIN_WORDPRESS_VERSION_FOR_WP_JQUERY, ">=")) {
            ApiSe::getInstance()->setSystemSetting('use_wp_jquery', 'Y');
        } else {
            ApiSe::getInstance()->setSystemSetting('use_wp_jquery', 'N');
        }

        return true;
    }
}
