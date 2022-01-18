<?php

defined('ABSPATH') || exit;

class SearchaniseAdmin
{
    private $lang_code = '';

    public function __construct($lang_code = null)
    {
        $this->lang_code = $lang_code ? $lang_code : ApiSe::getInstance()->getLocale();

        add_action('admin_init', array($this, 'init'));
        add_action('wp_loaded', array($this, 'register'));
    }

    /**
     * Admin init. Performs basic check
     */
    public function init()
    {
        if (!is_admin()) {
            return;
        }

        if (!is_plugin_active('woocommerce/woocommerce.php') && !is_plugin_active_for_network('woocommerce/woocommerce.php')) {
            add_action('admin_notices', function() {
                echo '<div class="notice-warning notice"><p>' . esc_html__('Searchanise: WooCommerce plugin should be enabled to work correctly.', 'woocommerce-searchanise') . '</p></div>';
            });

            if (current_user_can('activate_plugins')) {
                deactivate_plugins(SE_ABSPATH . DIRECTORY_SEPARATOR . 'woocommerce-searchanise.php');

                add_action('admin_notices', function() {
                    echo '<div class="notice-warning notice"><p>' . esc_html__('Searchanise: Plugin was deactivated.', 'woocommerce-searchanise') . '</p></div>';
                });

                if (isset($_GET['activate'])) {
                    unset($_GET['activate']);
                }
            }
        }
    }

    /**
     * Register backend scripts
     */
    public function register()
    {
        if (!is_admin()) {
            return;
        }

        // Network activation, try to install pluging
        if (is_multisite() && ApiSe::getInstance()->getModuleStatus() != 'Y') {
            // Network activation, try to install pluging
            SeCron::unregister();

            if (SeInstaller::install()) {
                // Register searchanise info page
                add_rewrite_rule('^searchanise/info', 'index.php?is_searchanise_page=1&post_type=page', 'top');
                flush_rewrite_rules();
            } else {
                add_action('admin_notices', function() {
                    echo '<div class="notice-warning notice"><p>' . esc_html__(
                        sprintf(
                            'Searchanise: Unable to register plugin. Please, contact Searchanise <a href="mailto:%s">%s</a> technical support',
                            SE_SUPPORT_EMAIL,
                            SE_SUPPORT_EMAIL
                        ),
                        'woocommerce-searchanise'
                    ) . '</p></div>';
                });
            }
        }

        if (ApiSe::getInstance()->getModuleStatus() != 'Y') {
            return;
        }

        if (!SeUpgrade::isUpdated()) {
            if (SeUpgrade::processUpgrade()) {
                $text_notification = sprintf(
                    __('Searchanise was successfully updated. Catalog indexation in process. <a href="%s">Searchanise Admin Panel</a>.', 'woocommerce-searchanise'),
                    ApiSe::getInstance()->getAdminUrl()
                );
            }
        } elseif (ApiSe::getInstance()->checkAutoInstall()) {
            $text_notification = sprintf(
                __('Searchanise was successfully installed. Catalog indexation in process. <a href="%s">Searchanise Admin Panel</a>.', 'woocommerce-searchanise'),
                ApiSe::getInstance()->getAdminUrl()
            );
        } elseif (ApiSe::getInstance()->getIsNeedReindexation()) {
            // Full reindexation, usually used after addon activating
            $text_notification = sprintf(
                __('Searchanise was successfully activated. Catalog indexation in process. <a href="%s">Searchanise Admin Panel</a>.', 'woocommerce-searchanise'),
                ApiSe::getInstance()->getAdminUrl()
            );
            ApiSe::getInstance()->setIsNeedReindexation(false);
        }

        if (!empty($text_notification)) {
            if (ApiSe::getInstance()->signup(null, false) == true) {
                ApiSe::getInstance()->queueImport(null, false);
                ApiSe::getInstance()->addAdminNotitice($text_notification, 'success');

            } else {
                ApiSe::getInstance()->addAdminNotitice(
                    sprintf(
                        __('Searchanise: Something is wrong in plugin registration. Please contact Searchanise <a href="mailto:%s">%s</a> technical support', 'woocommerce-searchanise'),
                        SE_SUPPORT_EMAIL,
                        SE_SUPPORT_EMAIL
                    ),
                    'error'
                );
            }

        } else {
            ApiSe::getInstance()->showNotificationAsyncCompleted();
        }

        add_action('admin_menu', array($this, 'adminMenu'));
        add_filter('admin_footer_text', array($this, 'adminFooterText'), 999999);
        add_filter('plugin_action_links_' . SE_PLUGIN_BASENAME, array($this, 'adminSettingsLink'));
        add_action('admin_notices', array($this, 'displayAdminNotices'));
    }

    /**
     * Adds plugin links.
     *
     * @param array $links
     * @return array $links with additional links
     */
    public function adminSettingsLink($links)
    {
        $links[] = '<a href="' . get_admin_url(null, '/edit.php?post_type=product&page=searchanise') . '">' . __('Searchanise Admin Panel', 'woocommerce-searchanise') . '</a>';
        $links[] = '<a href="' . get_admin_url(null, 'options-general.php?page=searchanise_settings') . '">' . __('Searchanise Settings', 'woocommerce-searchanise') . '</a>';

        return $links;
    }

    /**
     * Add the Searchanise Admin Panel menu items.
     */
    public function adminMenu()
    {
        $admin_page = add_submenu_page(
            'edit.php?post_type=product',
            __('Searchanise', 'woocommerce-searchanise'),
            __('Searchanise', 'woocommerce-searchanise'),
            'manage_product_terms',
            'searchanise',
            array($this, 'searchaniseManage')
        );

        $settings_page = add_options_page(
            __('Searchanise', 'woocommerce-searchanise'),
            __('Searchanise', 'woocommerce-searchanise'),
            'manage_options',
            'searchanise_settings',
            array($this, 'searchaniseSettings')
        );

        add_action('load-' . $settings_page, array($this, 'loadSettings'));
        add_action('load-' . $admin_page, array($this, 'loadDashboard'));
    }

    /**
     * Display stored admin notice
     */
    public function displayAdminNotices()
    {
        $admin_notices = ApiSe::getInstance()->getAdminNotices();

        if (!empty($admin_notices)) {
            foreach ($admin_notices as $notice) {
                $class = !empty($notice['type']) ? 'notice-' . $notice['type'] : '';
                $message = $notice['message'];
                echo "<div class=\"notice {$class} is-dismissible\">"
                    . "<p>{$message}</p>"
                    . '</div>';  
            }
        }

        return $this;
    }

    /**
     * Adds rating request to footer
     * 
     * @param string $footer_text Original footer text
     * @return string modified footer text
     */
    public function adminFooterText($footer_text)
    {
        $current_screen = get_current_screen();

        if (isset($current_screen->id) && in_array($current_screen->id, array('settings_page_searchanise_settings', 'product_page_searchanise'))) {
            if (!ApiSe::getInstance()->getIsRated()) {
                $footer_text = sprintf(
                    __('If you like %1$s please leave us a %2$s rating. A huge thanks in advance!', 'woocommerce-searchanise'),
                    sprintf('<strong>%s</strong>', esc_html__('Searchanise', 'woocommerce-searchanise')),
                    '<a href="https://wordpress.org/support/plugin/smart-search-for-woocommerce/reviews?rate=5#new-post" target="_blank" class="se-rating-link" data-rated="' . esc_attr__('Thanks :)', 'woocommerce-searchanise') . '">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
                );
                wc_enqueue_js(
                    "jQuery('a.se-rating-link').click( function() {
                        jQuery.get('" . admin_url('admin-ajax.php') . "', {action: 'searchanise_rated'});
                        jQuery(this).parent().text(jQuery(this).data('rated'));
                    });"
                );

            } else {
				$footer_text = __('Thank you for using <strong>Searchanise</strong>.', 'woocommerce-searchanise');
			}
        }

        return $footer_text;
    }

    /**
     * Load assets
     */
    public function loadSettings()
    {
        // Adds page to allow loads woocommerce scripts / css on them
        add_filter('woocommerce_screen_ids', function($screen_ids) {
            return array_merge($screen_ids, array(
                'settings_page_searchanise_settings',
            ));
        });
        add_filter('woocommerce_display_admin_footer_text', function($result) {
            $current_screen = get_current_screen();

            if ($current_screen->id == 'settings_page_searchanise_settings') {
                return false;
            }

            return $result;
        });

        return $this;
    }

    /**
     * Load Searchanise Admin Widget
     */
    public function loadDashboard()
    {
        ApiSe::getInstance()->checkEnviroments();

        $addon_options = ApiSe::getInstance()->getAddonOptions();
        $last_request = ApiSe::getInstance()->getLastRequest($this->lang_code);
        $last_resync = ApiSe::getInstance()->getLastResync($this->lang_code);
        $service_url = is_ssl() ? str_replace('http://', 'https://', SE_SERVICE_URL) : SE_SERVICE_URL;
        $platform_version = !empty($addon_options['woocommerce']) ? $addon_options['woocommerce']['Version'] : '';

        $se_admin_widgets_file_path = SE_BASE_DIR . '/assets/js/se-admin-widgets.js';
        $se_options = array(
            'version'               => SE_PLUGIN_VERSION,
            'status'                => 'enabled',
            'platform'              => SE_PLATFORM,
            'platform_edition'      => '',
            'platform_version'      => $platform_version,
            'host'                  => $service_url,
            'private_key'           => ApiSe::getInstance()->getPrivateKey($this->lang_code),
            'parent_private_key'    => ApiSe::getInstance()->getParentPrivateKey(),
            'connect_link'          => ApiSe::getInstance()->getAdminUrl('signup'),
            're_sync_link'          => ApiSe::getInstance()->getAdminUrl('reindex'),
            'last_request'          => ApiSe::getInstance()->formatDate($last_request),
            'last_resync'           => ApiSe::getInstance()->formatDate($last_resync),
            'lang_code'             => $this->lang_code,
            'name'                  => ApiSe::getInstance()->getStoreName($this->lang_code),
            'symbol'                => get_woocommerce_currency_symbol(),
            'decimals'              => wc_get_price_decimals(),
            'decimals_separator'    => wc_get_price_decimal_separator(),
            'thousands_separator'   => wc_get_price_thousand_separator(),
            'api_key'               => ApiSe::getInstance()->getApiKey($this->lang_code),
            'export_status'         => ApiSe::getInstance()->getExportStatus($this->lang_code),
            's_engines'             => array_values(ApiSe::getInstance()->getEngines()),
        );

        $se_admin_widgets_file_path = apply_filters('se_admin_widgets_file_path', $se_admin_widgets_file_path);
        $se_options = apply_filters('se_load_admin_widgets', $se_options);

        wp_register_script('se_admin_widget', plugins_url($se_admin_widgets_file_path), array('jquery'), SE_PLUGIN_VERSION, true);
        wp_localize_script('se_admin_widget', 'SeOptions', $se_options);
        wp_register_script('se_link', $service_url . '/js/init.js', array('se_admin_widget'), SE_PLUGIN_VERSION, true);

        wp_enqueue_style('se_admin_css', plugins_url(SE_BASE_DIR . '/assets/css/se-admin.css'), array(), false, false);

        return $this;
    }

    /**
     * Searchanise manage controller
     */
    public function searchaniseManage()
    {
        if (!current_user_can('manage_product_terms')) {
            wp_die(__('Access denied.', 'woocommerce-searchanise'));
        }

        $mode = filter_input(INPUT_GET, 'mode', FILTER_SANITIZE_STRING);

        if (!empty($mode)) {
            $action = 'action' . ucfirst($mode);

            if (method_exists($this, $action)) {
                call_user_func_array(array($this, $action), array());
                wp_redirect(ApiSe::getInstance()->getAdminUrl());
            }
        }

        wp_enqueue_script('se_admin_widget');
        wp_enqueue_script('se_link');

        echo '<div class="wrap"><h1>' . __('Searchanise Admin Panel', 'woocommerce-searchanise') . '</h1><div class="snize" id="snize_container"></div></div>';

        return $this;
    }

    /**
     * Signup controller action
     */
    private function actionSignup()
    {
        if (ApiSe::getInstance()->getModuleStatus() == 'Y' && ApiSe::getInstance()->signup()) {
            ApiSe::getInstance()->queueImport();
        }

        return $this;
    }

    /**
     * Reindex controller action
     */
    private function actionReindex()
    {
        if (ApiSe::getInstance()->getModuleStatus() == 'Y' && ApiSe::getInstance()->signup()) {
            ApiSe::getInstance()->queueImport();
        }

        return $this;
    }

    /**
     * Returns settings list for reindex
     * 
     * @param string $name Setting name
     * @return boolean
     */
    public function needSettingReIndexation($name)
    {
        return in_array($name, array(
            'use_direct_image_links',
            'import_block_posts',
            'excluded_tags',
            'excluded_pages',
            'excluded_categories',
        ));
    }

    /**
     * Settings controller
     */
    public function searchaniseSettings()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Access denied.', 'woocommerce-searchanise'));
        }

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $post = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);
            $se_settings = isset($post['se_settings']) ? $post['se_settings'] : array();

            if (!empty($se_settings)) {
                $need_reindexation = false;

                foreach($se_settings as $name => $val) {
                    if (is_array($val)) {
                        $val = implode(',', $val);
                    }

                    if ($this->needSettingReIndexation($name)) {
                        $old_value = ApiSe::getInstance()->getSystemSetting($name);
                        $need_reindexation |= $old_value != $val;
                    }

                    if (in_array($name, array('color_attribute', 'size_attribute'))) {
                        $old_value = ApiSe::getInstance()->getSystemSetting($name);

                        if ($old_value != $val) {
                            // Need attribute reindexation
                            SeQueue::getInstance()->addActionUpdateAttributes();
                        }
                    }

                    if ($name == 'search_result_page') {
                        SeInstaller::createSearchResultsPage(array('post_name' => $val), true);
                    }

                    ApiSe::getInstance()->setSystemSetting($name, $val);
                }

                if ($need_reindexation) {
                    ApiSe::getInstance()->queueImport();
                }
            }

            flush_rewrite_rules();
            ApiSe::getInstance()->addAdminNotitice(__('Settings saved successfully.', 'woocommerce-searchanise'), 'success');
            wp_redirect(get_admin_url(null, 'admin.php?page=searchanise_settings'));
        }

        require_once SE_ABSPATH . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'searchanise_settings.php';

        return $this;
    }

    /**
     * Returns all pages for system settings
     * 
     * @param string $lang_code Lanuage code
     * 
     * @return array
     */
    public function getAllPages($lang_code)
    {
        $pages = array();

        $posts = get_posts(array(
            'post_type'   => SeAsync::getPostTypes(),
            'numberposts' => -1,
        ));

        foreach ($posts as $post) {
            $pages[$post->post_name] = $post->post_title;
        }

        return (array) apply_filters('se_get_all_pages', $pages, $lang_code);
    }

    /**
     * Returns all categories for system settings
     * 
     * @param string $lang_code Lanuage code
     * 
     * @return array
     */
    public function getAllCategories($lang_code)
    {
        $categories = array();

        $terms = get_terms('product_cat');

        foreach ($terms as $term) {
            $categories[$term->slug] = $term->name;
        }

        return (array) apply_filters('se_get_all_categories', $categories, $lang_code);
    }
}

if (is_admin()) {
    add_action('plugins_loaded', function() {
        $GLOBALS['SearchaniseAdmin'] = new SearchaniseAdmin();
    });
}
