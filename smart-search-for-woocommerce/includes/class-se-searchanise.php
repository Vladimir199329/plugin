<?php

defined('ABSPATH') || exit;

class Searchanise
{
    const ADD_TO_CART_STATUS_SUCCESS = 'OK';
    const ADD_TO_CART_BAD_REQUEST    = 'BAD_REQUEST';
    const ADD_TO_CART_FAILED         = 'FAILED';

    /**
     * @var string
     *
     * Current language code
     */
    private $lang_code;

    /**
     * @var int
     *
     * Search results page id
     */
    private $search_results_page_id;

    /**
     * @var true
     *
     * True, if current page is Searchanise search results page
     */
    private $is_search_results_page = false;

    public function __construct($lang_code = '')
    {
        if (!empty($lang_code)) {
            $this->lang_code = ApiSe::getInstance()->getLocale();
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            add_action('wp_ajax_se_ajax_add_to_cart', array($this, 'ajaxAddToCart'));
            add_action('wp_ajax_nopriv_se_ajax_add_to_cart', array($this, 'ajaxAddToCart'));
        }

        if (
            is_admin()
            || (bool) filter_input(INPUT_GET, 'uxb_iframe') // hack for Flatsome theme UX builder
            || ApiSe::getInstance()->getModuleStatus() != 'Y'
            || empty($this->lang_code)
            || ApiSe::getInstance()->getApiKey($this->lang_code) == ''
            || !ApiSe::getInstance()->isSearchAllowed($this->lang_code)
        ) {
            return false;
        }

        add_action('wp_loaded', function() {
            global $post;

            // Check search results page
            if (is_object($post)) {
                $post_id = $post->ID;
            } else {
                $post_id = url_to_postid($_SERVER['REQUEST_URI']);
            }

            $this->search_results_page_id = SeInstaller::createSearchResultsPage();
            $this->is_search_results_page = $post_id && $post_id == $this->search_results_page_id;

            if ($this->is_search_results_page) {
                wc_enqueue_js(<<<SE_SPINNER
(function(window, undefined) {
    var sXpos = 0, sIndex = 0, sInterval = null;

    if (document.getElementById('snize_results').innerHTML != '') {
        return;
    }

    document.getElementById('snize_results').innerHTML = '<div id="snize-preload-spinner"></div>';
    sInterval = setInterval(function() {
        var spinner = document.getElementById('snize-preload-spinner');
        if (spinner) {
            document.getElementById('snize-preload-spinner').style.backgroundPosition = (- sXpos) + 'px center';
        } else {
            clearInterval(sInterval);
        }

        sXpos  += 32;
        sIndex += 1;

        if (sIndex >= 12) {
            sXpos  = 0;
            sIndex = 0;
        }
    }, 30);
}(window));
SE_SPINNER
                );
            }
        });

        add_action('wp_enqueue_scripts', array($this, 'loadSearchWidget'));

        if (ApiSe::getInstance()->isResultWidgetEnabled($this->lang_code)) {
            add_filter('redirect_canonical', array($this, 'disablePagedCanonicalRedirect'), 1, 10);
            add_filter('pre_handle_404', array($this, 'preHandle404'), 2, 10);
        }
        add_action('pre_get_posts', array($this, 'excludeResultWidgetFromSearch'));
        add_filter('body_class', array($this, 'bodyClass'));
    }

    /**
     * Filters whether to short-circuit default header status handling.
     *
     * Returning a non-false value from the filter will short-circuit the handling
     * and return early.
     *
     * @since 4.5.0
     *
     * @param bool     $preempt  Whether to short-circuit default header status handling. Default false.
     * @param WP_Query $wp_query WordPress Query object.
     *
     * @return bool
     */
    public function preHandle404($preempt, $wp_query)
    {
        $page_num = (int)filter_input(INPUT_GET, 'page');

        // Wordpress doesn't support the <!--nextpage--> pagination for posts and generates 404 error.
        // So, we have to skip this error for search results page
        return $this->is_search_results_page && $page_num > 0 ? true : $preempt;
    }

	/**
	 * Filters the canonical redirect URL.
	 *
	 * Returning false to this filter will cancel the redirect.
	 *
	 * @since 2.3.0
	 *
	 * @param string $redirect_url  The redirect URL.
	 * @param string $requested_url The requested URL.
     *
     * @return string
	 */
    public function disablePagedCanonicalRedirect($redirect_url, $requested_url)
    {
        if ($this->is_search_results_page) {
            $page_num = (int)filter_input(INPUT_GET, 'page');

            if ($page_num > 0) {
                // Do not perform any redirection if page param in query
                return false;
            }
        }

        return $redirect_url;
    }

    /**
     * Adds Searchanise class to body tag in search results page
     */
    public function bodyClass($classes)
    {
        $classes = (array) $classes;

        if (ApiSe::getInstance()->isResultWidgetEnabled($this->lang_code)) {
            if ($this->is_search_results_page) {
                $classes[] = 'searchanise-search-results-page';
            }
        }

        return array_unique($classes);
    }

    /**
     * Add to cart ajax controller
     */
    public function ajaxAddToCart()
    {
        $response = array();
        $product_id = apply_filters('woocommerce_add_to_cart_product_id', (int)$_REQUEST['product_id']);
        $quantity = empty($_REQUEST['quantity']) ? 1 : wc_stock_amount($_REQUEST['quantity']);
        $variation_id = 0;

        if (!empty($product_id) && !empty($quantity)) {
            $product_data = wc_get_product($product_id);

            if ($product_data instanceof WC_Product) {
                // Select first available product variant
                if ($product_data instanceof WC_Product_Variable) {
                    $variations = $product_data->get_available_variations();

                    foreach ($variations as $v) {
                        if ($v['is_in_stock']) {
                            $variation_id = $v['variation_id'];
                            break;
                        }
                    }
                }

                $passed_validation = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity);
                $product_status = get_post_status($product_id);

                if ($passed_validation && $product_status == 'publish' && WC()->cart->add_to_cart($product_id, $quantity, $variation_id)) {
                    do_action('woocommerce_ajax_added_to_cart', $product_id);

                    $response = array(
                        'status'   => self::ADD_TO_CART_STATUS_SUCCESS,
                        'redirect' => wc_get_cart_url(),
                    );

                } else {
                    $response = array(
                        'status' => self::ADD_TO_CART_FAILED,
                    );
                }

            } else {
                $response = array(
                    'status' => self::ADD_TO_CART_BAD_REQUEST,
                );
            }

        } else {
            // Incorrect parameters
            $response = array(
                'status' => self::ADD_TO_CART_BAD_REQUEST
            );
        }

        // Redirect to product page if add to cart not possible
        if ($response['status'] != self::ADD_TO_CART_STATUS_SUCCESS) {
            $response['redirect'] = apply_filters('woocommerce_cart_redirect_after_error', get_permalink($product_id), $product_id);
        }

        echo wp_send_json($response);
        wp_die();
    }

    /**
     * Exclude search result page from search
     * 
     * @param mixed $query Search query
     */
    public function excludeResultWidgetFromSearch($query)
    {
        if (
            ApiSe::getInstance()->isResultWidgetEnabled($this->lang_code)
            && !$query->is_admin
            && $query->is_search
            && $query->is_main_query()
        ) {
            $query->set('post__not_in', array($this->search_results_page_id));
        }
    }

    /**
     * Returns search results page url
     * 
     * @return string
     */
    private function getSearchResultPageUrl()
    {
        if (!ApiSe::getInstance()->isResultWidgetEnabled($this->lang_code)) {
            return '';
        }

        $se_searchanise_url = '';

        if (!empty($this->search_results_page_id)) {
            $se_page = get_post($this->search_results_page_id);

            if (!empty($se_page)) {
                $se_searchanise_url = get_permalink($se_page->ID);
            }
        }

        /**
         * Filters Searchanise results page
         * 
         * @param string $se_searchanise_url Page url
         * @param string $lang_code Lang code
         */
        return apply_filters('se_get_search_results_page_url', $se_searchanise_url, $this->lang_code);
    }

    /**
     * Returns fallback url for search results
     * 
     * @return string
     */
    private function getFallbackUrl()
    {
        return ApiSe::getInstance()->getFrontendUrl($this->lang_code, array(
            'post_type' => 'product',
            's'         => '',
        ));
    }

    /**
     * Returns ajax add to cart url
     */
    private function getAddToCartUrl()
    {
        return admin_url('admin-ajax.php');
    }

    /**
     * Returns currency position
     * 
     * @return boolean
     */
    private function getCurrencyPositionAfter()
    {
        $currency_pos = get_option('woocommerce_currency_pos');

        switch($currency_pos) {
            case 'left':
            case 'left_space':
                return false;

            case 'right':
            case 'right_space':
                return true;

            default:
                return false;
        }
    }

    /**
     * Loads search widget assets
     */
    public function loadSearchWidget()
    {
        $se_searchanise_url = $this->getSearchResultPageUrl();
        $se_widgets_file_path = SE_BASE_DIR . '/assets/js/se-widgets.js';

        $se_options = array(
            'version'                       => SE_VERSION,
            'host'                          => is_ssl() ? str_replace('http://', 'https://', SE_SERVICE_URL) : SE_SERVICE_URL,
            'api_key'                       => ApiSe::getInstance()->getApiKey($this->lang_code),
            'decimals'                      => apply_filters('se_decimals', wc_get_price_decimals()),
            'decimals_separator'            => apply_filters('se_decimals_separator', wc_get_price_decimal_separator()),
            'thousands_separator'           => apply_filters('se_thousands_separator', wc_get_price_thousand_separator()),
            'symbol'                        => apply_filters('se_currency_symbol', get_woocommerce_currency_symbol()),
            'rate'                          => ApiSe::getInstance()->getCurrencyRate(),
            'currency_position_after'       => $this->getCurrencyPositionAfter(),
            'search_input'                  => ApiSe::getInstance()->getSearchInputSelector(),
            'results_form_path'             => $se_searchanise_url,
            'results_fallback_url'          => $this->getFallbackUrl(),
            'results_add_to_cart_url'       => $this->getAddToCartUrl(),
            'hide_out_of_stock_products'    => 'yes' === get_option('woocommerce_hide_out_of_stock_items') ? 'Y' : 'N',
            'cur_label_for_usergroup'       => ApiSe::getInstance()->getCurLabelForPricesUsergroup(),
            'usergroup_ids'                 => implode('|', ApiSe::getInstance()->getCurrentUsergroupIds()),
            'use_wp_jquery'                 => ApiSe::getInstance()->isUseWpJquery(),
        );

        // Do not include search in admin toolbar
        wc_enqueue_js('jQuery("#wpadminbar").find("' . ApiSe::getInstance()->escapeJavascript(ApiSe::getInstance()->getSearchInputSelector()) . '").addClass("snize-exclude-input")');

        // Loading css
        wp_enqueue_style('se_styles', plugins_url(SE_BASE_DIR . '/assets/css/se-styles.css'), array(), false, false);

        // Loading searchanise widgets
        $se_options = apply_filters('se_load_search_widgets', $se_options);
        wp_register_script('se-widgets', plugins_url($se_widgets_file_path), array('jquery'), SE_PLUGIN_VERSION, true);
        wp_localize_script('se-widgets', 'SeOptions', $se_options);
        wp_enqueue_script('se-widgets');

        // Refresh shopping cart
        wc_enqueue_js(
            "jQuery(document).on('Searchanise.AddToCartSuccess', function() {
                jQuery(document.body).trigger('wc_fragment_refresh');
            });"
        );
    }

    /**
     * Check private key from request
     * 
     * @return boolean
     */
    public function checkPrivateKey()
    {
        static $check = null;
 
        if ($check === null) {
            $parent_private_key = !empty($_REQUEST['parent_private_key']) ? $_REQUEST['parent_private_key'] : '';

            if (
                empty($parent_private_key)
                || ApiSe::getInstance()->getParentPrivateKey() != $parent_private_key
            ) {
                $check == false;
            } else {
                $check = true;
            }
        }

        return $check;
    }
}

add_action('plugins_loaded', function() {
    $GLOBALS['searchanise'] = new Searchanise(ApiSe::getInstance()->getLocale());
});
