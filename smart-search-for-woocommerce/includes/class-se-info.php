<?php

defined('ABSPATH') || exit;

class SearchaniseInfo
{
    const RESYNC             = 'resync';
    const OUTPUT             = 'visual';
    const PROFILER           = 'profiler';
    const LANG_CODE          = 'lang_code';
    const DISPLAY_ERRORS     = 'display_errors';
    const PRODUCT_ID         = 'product_id';
    const PRODUCT_IDS        = 'product_ids';
    const CATEGORY_ID        = 'category_id';
    const CATEGORY_IDS       = 'category_ids';
    const PAGE_ID            = 'page_id';
    const PAGE_IDS           = 'page_ids';
    const PARENT_PRIVATE_KEY = 'parent_private_key';

    /**
     * Adds searchanise information page to frontend
     */
    public static function init()
    {
        add_rewrite_rule('^searchanise/info', 'index.php?is_searchanise_page=1&post_type=page', 'top');
        add_action('query_vars', function($vars) {
            $vars[] = 'is_searchanise_page';
            return $vars;
        });
        add_filter('template_include', function($template) {
            if (get_query_var('is_searchanise_page')) {
                $template = SE_ABSPATH . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'searchanise_info.php';
            }

            return $template;
        }, 1000, 1);

        return true;
    }

    /**
     * Display Searchanise information
     */
    public static function display()
    {
        global $searchanise;

        $visual = self::getParam(self::OUTPUT, false);

        if (!$searchanise->checkPrivateKey()) {
            $addon_options = ApiSe::getInstance()->getAddonOptions();
            $options = array(
                'status'  => $addon_options['addon_status'],
                'api_key' => $addon_options['api_key'],
            );

            if ($visual) {
                ApiSe::getInstance()->printR($options);
            } else {
                print(json_encode($options));
            }
        } else {
            $resync        = self::getParam(self::RESYNC, 'N');
            $displayErrors = self::getParam(self::DISPLAY_ERRORS, 'N');
            $langCode      = self::getParam(self::LANG_CODE, ApiSe::getInstance()->getLocale());
            $productId     = self::getParam(self::PRODUCT_ID, false);
            $productIds    = self::getParam(self::PRODUCT_IDS, false);
            $categoryId    = self::getParam(self::CATEGORY_ID, false);
            $categoryIds   = self::getParam(self::CATEGORY_IDS, false);
            $pageId        = self::getParam(self::PAGE_ID, false);
            $pageIds       = self::getParam(self::PAGE_IDS, false);

            if ($displayErrors == 'Y') {
                @error_reporting(E_ALL | E_STRICT);
                @ini_set('display_errors', 1);
                @ini_set('display_startup_errors', 1);
            } else {
                @error_reporting(0);
                @ini_set('display_errors', 0);
                @ini_set('display_startup_errors', 0);
            }

            $productIds = $productId ? $productId : ($productIds ? explode(',', $productIds) : 0);
            $categoryIds = $categoryId ? $categoryId : ($categoryIds ? explode(',', $categoryIds) : 0);
            $pageIds = $pageId ? $pageId : ($pageIds ? explode(',', $pageIds) : 0);

            if ($resync == 'Y') {
                ApiSe::getInstance()->queueImport(null, false);

            } elseif (!empty($productIds) || !empty($pageIds) || !empty($categoryIds)) {
                switch_to_locale($langCode); // Emulate language

                $products_data = SeAsync::getInstance()->getProductsData($productIds, $langCode, false);
                $categories    = SeAsync::getInstance()->getCategoriesData($categoryIds, $langCode);
                $pages         = SeAsync::getInstance()->getPagesData($pageIds, $langCode);

                $feed = array(
                    'header'     => SeAsync::getInstance()->getHeader($langCode),
                    'items'      => $products_data['items'],
                    'schema'     => $products_data['schema'],
                    'categories' => $categories['categories'],
                    'pages'      => $pages['pages'],
                );

                if ($visual) {
                    ApiSe::getInstance()->printR($feed);
                } else {
                    print(json_encode($feed));
                }

            } else {
                $options = self::getInfo($langCode);

                if ($visual) {
                    ApiSe::getInstance()->printR($options);
                } else {
                    print(json_encode($options));
                }
            }
        }
    }

    /**
     * Gets Searchanise plugin info
     * 
     * @return array
     */
    public static function getInfo($langCode)
    {
        $options = ApiSe::getInstance()->getAddonOptions();
        $options = array_merge($options, array(
            'api_key' => array(),
        ));

        $options['log_dir']                  = SE_LOG_DIR;
        $options['next_queue']               = SeQueue::getInstance()->getNextQueue();
        $options['total_items_in_queue']     = SeQueue::getInstance()->getTotalItems();
        $options['queue_status']             = SeQueue::getInstance()->getQueueStatus() ? 'Y' : 'N';

        $options['search_input_selector']    = html_entity_decode(ApiSe::getInstance()->getSearchInputSelector());
        $options['search_enabled']           = ApiSe::getInstance()->getEnabledSearchaniseSearch() ? 'Y' : 'N';

        $options['sync_mode']                = ApiSe::getInstance()->getSyncMode();
        $options['cron_async_enabled']       = ApiSe::getInstance()->checkCronAsyncEnabled() ? 'Y' : 'N';
        $options['ajax_async_enabled']       = ApiSe::getInstance()->checkAjaxAsyncEnabled() ? 'Y' : 'N';

        $options['max_execution_time']       = ini_get('max_execution_time');
        @set_time_limit(0);
        $options['max_execution_time_after'] = ini_get('max_execution_time');

        $options['ignore_user_abort']        = ini_get('ignore_user_abort');
        @ignore_user_abort(1);
        $options['ignore_user_abort_after']  = ini_get('ignore_user_abort_after');

        $options['memory_limit'] = ini_get('memory_limit');
        $asyncMemoryLimit = ApiSe::getInstance()->getAsyncMemoryLimit();
        if (substr(ini_get('memory_limit'), 0, -1) < $asyncMemoryLimit) {
            @ini_set('memory_limit', $asyncMemoryLimit . 'M');
        }
        $options['memory_limit_after']       = ini_get('memory_limit');

        list($start, $max) = SeAsync::getInstance()->getMinMaxProductId($langCode, true);
        $options['products']['min'] = $start;
        $options['products']['max'] = $max;
        $options['products']['count'] = SeAsync::getInstance()->getProductsCount($langCode, true);

        return $options;
    }

    /**
     * Returns param from request
     * 
     * @param string $name Param name
     * @param string $default Default value
     */
    private static function getParam($name, $default = '')
    {
        return isset($_REQUEST[$name]) ? $_REQUEST[$name] : $default;
    }
}

add_action('wp_ajax_nopriv_se_info', array('SearchaniseInfo', 'display'));
add_action('wp_ajax_se_info', array('SearchaniseInfo', 'display'));
add_action('init', array('SearchaniseInfo', 'init'));
