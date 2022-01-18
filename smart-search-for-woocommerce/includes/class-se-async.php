<?php

defined('ABSPATH') || exit;

class SeAsync
{
    const COMPRESS_RATE = 5;

    // Attribute weights
    const WEIGHT_SHORT_TITLE          = 100;
    const WEIGHT_SHORT_DESCRIPTION    = 40;
    const WEIGHT_DESCRIPTION          = 40;
    const WEIGHT_DESCRIPTION_GROUPED  = 30;

    const WEIGHT_CATEGORIES           = 60;
    const WEIGHT_TAGS                 = 60;

    const WEIGHT_META_TITLE           = 80;
    const WEIGHT_META_KEYWORDS        = 100;
    const WEIGHT_META_DESCRIPTION     = 40;

    const WEIGHT_SELECT_ATTRIBUTES    = 60;
    const WEIGHT_TEXT_ATTRIBUTES      = 60;
    const WEIGHT_TEXT_AREA_ATTRIBUTES = 40;

    // Image sizes
    const IMAGE_SIZE     = 300;
    const THUMBNAIL_SIZE = 70;

    // Async statuses
    const STATUS_ASYNC_DISABLED   = 'disabled';
    const STATUS_ASYNC_PROCESSING = 'processing';
    const STATUS_ASYNC_ERROR_LANG = 'lang_error';
    const STATUS_ASYNC_OK         = 'OK';

    // Async request flags
    const FL_SHOW_STATUS_ASYNC     = 'show_status';
    const FL_SHOW_STATUS_ASYNC_KEY = 'Y';
    const FL_IGNORE_PROCESSING     = 'ignore_processing';
    const FL_IGNORE_PROCESSING_KEY = 'Y';
    const FL_DISPLAY_ERRORS        = 'display_errors';
    const FL_DISPLAY_ERRORS_KEY    = 'Y';
    const FL_LANG_CODE             = 'lang_code';

    const SEND_VARIATIONS             = false;
    const STRIP_POST_CONTENT          = true;  // set to false if you want to import full content of pages/posts including all html tags
    const IMPORT_ALSO_BOUGHT_PRODUCTS = true;

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

    /**
     * Async initialization
     */
    public static function init()
    {
        if (ApiSe::getInstance()->getModuleStatus() != 'Y') {
            // Do not run async if module is not installed
            return;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            add_action('wp_ajax_nopriv_se_async', array(__CLASS__, 'ajaxAsync'));
            add_action('wp_ajax_se_async', array(__CLASS__, 'ajaxAsync'));
            add_action('wp_ajax_nopriv_searchanise_rated', array(__CLASS__, 'rated'));
            add_action('wp_ajax_searchanise_rated', array(__CLASS__, 'rated'));

        } elseif (
            !defined('DOING_CRON')
            && (
                ApiSe::getInstance()->checkAjaxAsyncEnabled()
                || ApiSe::getInstance()->checkObjectAsyncEnabled()
            )
        ) {
            $lang_code = ApiSe::getInstance()->checkStartAsync();

            if ($lang_code != false) {
                $async_url = admin_url('admin-ajax.php');

                if (ApiSe::getInstance()->checkObjectAsyncEnabled()) {
                    if (is_admin()) {
                        add_action('admin_footer', function() use ($async_url, $lang_code) {
                            self::addAsyncObjects($async_url, $lang_code);
                        }, 100);
                    } else {
                        add_action('wp_footer', function() use ($async_url, $lang_code) {
                            self::addAsyncObjects($async_url, $lang_code);
                        }, 100);
                    }

                } elseif (ApiSe::getInstance()->checkAjaxAsyncEnabled()) {
                    self::addJQueryObjects($async_url, $lang_code);
                }
            }
        } //endif async

        // Check export status
        if (!defined('DOING_AJAX') && !defined('DOING_CRON')) {
            ApiSe::getInstance()->checkExportStatusIsDone(ApiSe::getInstance()->getLocale());
        }
    }

    /**
     * Adds jquery async requests
     * 
     * @param string $async_url
     * @param array $lang_codes_to_processed
     */
    public static function addJQueryObjects($async_url, $lang_codes_to_processed = array())
    {
        foreach ((array)$lang_codes_to_processed as $lang_code) {
            wc_enqueue_js(
                "jQuery.ajax({
                    method: 'get',
                    url: '{$async_url}',
                    data: {
                        action: 'se_async',
                        lang_code: '{$lang_code}'
                    },
                    async: true
                });"
            );
        }
    }

    /**
     * Adds object async requests
     * 
     * @param string $async_url
     * @param array $lang_codes_to_processed
     */
    public static function addAsyncObjects($async_url, $lang_codes_to_processed = array())
    {
        foreach ((array)$lang_codes_to_processed as $lang_code) {
            echo "<object data=\"{$async_url}?action=se_async&lang_code={$lang_code}\" width=\"0\" height=\"0\" type=\"text/html\"></object>";
        }
    }

    /**
     * Process async queue
     * 
     * @param string $lang_code
     * @param boolean $fl_ignore_processing
     * 
     * @return string
     */
    public function async($lang_code = null, $fl_ignore_processing = false)
    {
        @ignore_user_abort(true);
        @set_time_limit(0);

        $async_memory_limit = ApiSe::getInstance()->getAsyncMemoryLimit();
        if (substr(ini_get('memory_limit'), 0, -1) < $async_memory_limit) {
            @ini_set('memory_limit', $async_memory_limit . 'M');
        }

        $locale_switched = false;
        if (!empty($lang_code) && ApiSe::getInstance()->getLocale() != $lang_code) {
            if (switch_to_locale($lang_code) == true) {
                $locale_switched = true;
            } else {
                return self::STATUS_ASYNC_ERROR_LANG;
            }
        }

        SeProfiler::startBlock('async');
        ApiSe::getInstance()->echoProgress('.');
        // Read first element from queue
        $q = SeQueue::getInstance()->getNextQueue(null, $lang_code);

        while(!empty($q)) {
            SeLogger::getInstance()->debug($q);

            $data_for_send = array();
            $status        = false;
            $error         = '';

            $engines = ApiSe::getInstance()->getEngines($q->lang_code);
            $engine  = current($engines);
            $header  = $this->getHeader($q->lang_code);

            $data = $q->data;
            if ($data != SeQueue::NO_DATA) {
                $data = unserialize($data);
            }

            $private_key = $engine['private_key'];

            if (empty($private_key)) {
                SeQueue::getInstance()->deleteQueueById($q->queue_id);
                $q = array();
                continue;
            }

            if (SeQueue::isQueueRunning($q)) {
                if (!$fl_ignore_processing) {
                    return self::STATUS_ASYNC_PROCESSING;
                }
            }

            if (SeQueue::isQueueHasError($q)) {
                ApiSe::getInstance()->setExportStatus(ApiSe::EXPORT_STATUS_SYNC_ERROR, $engine['lang_code']);
                return self::STATUS_ASYNC_DISABLED;
            }

            // Set queue to processing state
            SeQueue::getInstance()->setQueueStatusProcessing($q->queue_id);

            try {
                SeProfiler::startBlock($q->action . ':' . $q->queue_id);

                if ($q->action == SeQueue::PREPARE_FULL_IMPORT) {
                    SeQueue::getInstance()->prepareFullImport($engine['lang_code']);

                    SeQueue::getInstance()->insertData(array(
                        'data'      => SeQueue::NO_DATA,
                        'action'    => SeQueue::START_FULL_IMPORT,
                        'lang_code' => $engine['lang_code'],
                    ));

                    SeQueue::getInstance()->insertData(array(
                        'data'      => SeQueue::NO_DATA,
                        'action'    => SeQueue::GET_INFO,
                        'lang_code' => $engine['lang_code'],
                    ));

                    SeQueue::getInstance()->insertData(array(
                        'data'      => SeQueue::NO_DATA,
                        'action'    => SeQueue::DELETE_FACETS_ALL,
                        'lang_code' => $engine['lang_code'],
                    ));

                    $this->addTaskByChunk($engine['lang_code'], SeQueue::UPDATE_PRODUCTS, true);
                    SeQueue::getInstance()->insertData(array(
                        'data'      => SeQueue::NO_DATA,
                        'action'    => SeQueue::UPDATE_ATTRIBUTES,
                        'lang_code' => $engine['lang_code'],
                    ));

                    $this->addTaskByChunk($engine['lang_code'], SeQueue::UPDATE_CATEGORIES, true);
                    $this->addTaskByChunk($engine['lang_code'], SeQueue::UPDATE_PAGES, true);

                    SeQueue::getInstance()->insertData(array(
                        'data'      => SeQueue::NO_DATA,
                        'action'    => SeQueue::END_FULL_IMPORT,
                        'lang_code' => $engine['lang_code'],
                    ));

                    $status = true;

                } elseif ($q->action == SeQueue::START_FULL_IMPORT) {
                    $status = ApiSe::getInstance()->sendRequest('/api/state/update/json', $private_key, array('full_import' => ApiSe::EXPORT_STATUS_START), true);

                    if ($status == true) {
                        ApiSe::getInstance()->setExportStatus(ApiSe::EXPORT_STATUS_PROCESSING, $engine['lang_code']);
                    }

                } elseif ($q->action == SeQueue::GET_INFO) {
                    $info = ApiSe::getInstance()->sendRequest('/api/state/info/json', $private_key, array(), true);

                    if (!empty($info['result_widget_enabled'])) {
                        ApiSe::getInstance()->setResultWidgetEnabled($info['result_widget_enabled'], $engine['lang_code']);
                    }

                    $status = true;

                } elseif ($q->action == SeQueue::END_FULL_IMPORT) {
                    $export_done = ApiSe::getInstance()->sendRequest('/api/state/update/json', $private_key, array('full_import' => ApiSe::EXPORT_STATUS_DONE), true);

                    if ($export_done == true) {
                        ApiSe::getInstance()->setExportStatus(ApiSe::EXPORT_STATUS_SENT, $engine['lang_code']);
                        ApiSe::getInstance()->setLastResync($engine['lang_code'], time());
                    }

                    // Update search results page
                    if (ApiSe::getInstance()->isResultWidgetEnabled($engine['lang_code'])) {
                        SeInstaller::createSearchResultsPage(array(), true);
                    } else {
                        SeInstaller::deleteSearchResultsPage();
                    }

                    $status = true;

                } elseif (SeQueue::isDeleteAllAction($q->action)) {
                    $type = SeQueue::getAPITypeByAction($q->action);

                    if (!empty($type)) {
                        $status = ApiSe::getInstance()->sendRequest("/api/{$type}/delete/json", $private_key, array('all' => true), true);
                    }

                } elseif (SeQueue::isUpdateAction($q->action)) {
                    $data_for_send = array();

                    switch($q->action) {
                        case SeQueue::UPDATE_PRODUCTS:
                            $data_for_send = $this->getProductsData($data, $engine['lang_code'], true);
                            break;

                        case SeQueue::UPDATE_CATEGORIES:
                            $data_for_send = $this->getCategoriesData($data, $engine['lang_code']);
                            break;

                        case SeQueue::UPDATE_PAGES:
                            $data_for_send = $this->getPagesData($data, $engine['lang_code']);
                            break;

                        case SeQueue::UPDATE_ATTRIBUTES:
                            $facets = array();
                            $product_filters = $this->getProductFilters($engine['lang_code']);
                            foreach ($product_filters as $filter) {
                                $facets[] = $this->prepareFacetData($filter, $engine['lang_code']);
                            }

                            if (!empty($facets)) {
                                $data_for_send = array('schema' => $facets);

                            } else {
                                $status = true;
                            }

                            break;
                    }

                    if (!empty($data_for_send)) {
                        $data_for_send = json_encode(array_merge($header, $data_for_send));

                        if (function_exists('gzcompress')) {
                            $data_for_send = gzcompress($data_for_send, self::COMPRESS_RATE);
                        }

                        $status = ApiSe::getInstance()->sendRequest("/api/items/update/json", $private_key, array('data' => $data_for_send), true);
                    }

                } elseif (SeQueue::isDeleteAction($q->action)) {
                    $type = SeQueue::getAPITypeByAction($q->action);

                    if (!empty($type)) {
                        foreach ($data as $item_id) {
                            $data_for_send = array();

                            if ($q->action == SeQueue::DELETE_FACETS) {
                                $data_for_send['attribute'] = $item_id;
                            } else {
                                $data_for_send['id'] = $item_id;
                            }

                            $status = ApiSe::getInstance()->sendRequest("/api/{$type}/delete/json", $private_key, $data_for_send, true);
                            ApiSe::getInstance()->echoProgress('.');

                            if ($status == false) {
                                break;
                            }
                        }
                    }

                } elseif ($q->action == SeQueue::PHRASE) {
                    foreach ($data as $phrase) {
                        $status = ApiSe::getInstance()->sendRequest("/api/phrases/update/json", $private_key, array('phrase' => $phrase), true);
                        ApiSe::getInstance()->echoProgress('.');

                        if ($status == false) {
                            break;
                        }
                    }

                } else {
                    // Unknown action name
                    throw new SeException(__('Unknown queue action', 'woocommerce-searchanise'));
                } // End if

                // Check for database errors
                if ($this->wpdb->last_error != '') {
                    throw new SeException(__('SQL Error', 'woocommerce-searchanise') . ' ' . $this->wpdb->last_error . '. Query: ' . $this->wpdb->last_query);
                }

                SeProfiler::endBlock($q->action . ':' . $q->queue_id);

            } catch (\SeException $e) {
                SeProfiler::endBlock($q->action . ':' . $q->queue_id);
                $status = false;
                $error = $e->getMessage();
                SeLogger::getInstance()->error(array(
                    'q'     => $q,
                    'error' => $error,
                ));
            }

            SeLogger::getInstance()->debug(array('status' => $status));

            if ($status == true) {
                SeQueue::getInstance()->deleteQueueById($q->queue_id);
                $q = SeQueue::getInstance()->getNextQueue($q->queue_id, $lang_code);

            } else {
                $next_started_time = time() - ApiSe::getInstance()->getMaxProcessingTime() + $q->attempts * 60;
                SeQueue::getInstance()->setQueueErrorById($q->queue_id, $next_started_time, $error);

                // Try again later
                break;
            }
        } // End while

        ApiSe::getInstance()->echoProgress('.');
        SeProfiler::endBlock('async');

        // Restore locale if it was switched
        if ($locale_switched == true) {
            restore_previous_locale();
        }

        $info = SeProfiler::getBlocksInfo();
        SeLogger::getInstance()->debug($info);

        return self::STATUS_ASYNC_OK;
    }

    /**
     * Generate request header
     * 
     * @param string $lang_code
     * @return array
     */
    public function getHeader($lang_code)
    {
        $shop_url = get_permalink(wc_get_page_id('shop'));

        if (empty($shop_url)) {
            $shop_url = home_url('/shop');
        }

        $header = array(
            'header' => array(
                'id'      => $shop_url,
                'updated' => date('c'),
            ),
        );

        /**
         * Filters header data for Searchanise
         * 
         * @param array $header      Header data
         * @param string $lang_code  Lang code
         */
        return (array)apply_filters('se_get_header', $header, $lang_code);
    }

    /**
     * Adds task to queue
     * 
     * @param string $lang_code
     * @param string $action
     * @param boolean $isOnlyActive
     * 
     * @return boolean
     */
    private function addTaskByChunk($lang_code, $action, $isOnlyActive = true)
    {
        $i = 0;
        $step = 50;
        $start = 0;
        $max = 0;

        switch($action) {
            case SeQueue::UPDATE_PRODUCTS:
                $step = ApiSe::getInstance()->getProductsPerPass() * 50;
                list($start, $max) = $this->getMinMaxProductId($lang_code, $isOnlyActive);
                break;
            
            case SeQueue::UPDATE_CATEGORIES:
                $step = ApiSe::getInstance()->getCategoriesPerPass() * 50;
                list($start, $max) = $this->getMinMaxCategoryId($lang_code);
                break;
            
            case SeQueue::UPDATE_PAGES:
                $step = ApiSe::getInstance()->getPagesPerPass() * 50;
                list($start, $max) = $this->getMinMaxPageId($lang_code);
                break;

            default:
                return false;
        }

        do {
            $chunk_item_id = null;

            switch($action) {
                case SeQueue::UPDATE_PRODUCTS:
                    $chunk_item_id = $this->getProductsIdsFromRange($start, $max, $step, $lang_code, $isOnlyActive);
                    break;

                case SeQueue::UPDATE_CATEGORIES:
                    $chunk_item_id = $this->getCategoriesIdsFromRange($start, $max, $step, $lang_code);
                    break;

                case SeQueue::UPDATE_PAGES:
                    $chunk_item_id = $this->getPagesIdsFromRange($start, $max, $step, $lang_code);
                    break;
            }

            if (empty($chunk_item_id)) {
                break;
            }

            $end = max($chunk_item_id);
            $start = $end + 1;

            $chunk_item_id = array_chunk($chunk_item_id, ApiSe::getInstance()->getProductsPerPass());

            foreach ($chunk_item_id as $item_ids) {
                $queue_data = array(
                    'data'      => serialize($item_ids),
                    'action'    => $action,
                    'lang_code' => $lang_code,
                );

                SeQueue::getInstance()->insertData($queue_data);
                unset($queue_data); // For memory safe
            }

        } while ($end <= $max);

        return true;
    }

    /**
     * Returns min and max product ids
     * 
     * @param string $lang_code
     * @param boolean $isOnlyActive
     * 
     * @return array
     */
    public function getMinMaxProductId($lang_code, $isOnlyActive = true)
    {
        $visibility_condition = '';
        if ($isOnlyActive) {
            $visibility_condition .= " AND post_status IN ('publish')";
        }

        $min_max = $this->wpdb->get_row("SELECT
                MIN(ID) AS min,
                MAX(ID) AS max 
            FROM {$this->wpdb->prefix}posts
            WHERE 1 {$visibility_condition}
            AND post_type = 'product'",
            ARRAY_A
        );

        return array((int) $min_max['min'], (int) $min_max['max']);
    }

    /**
     * Calculates products count for import
     * 
     * @param string $lang_code
     * @param bool   $isOnlyActive
     * 
     * @return int
     */
    public function getProductsCount($lang_code, $isOnlyActive)
    {
        $visibility_condition = '';
        if ($isOnlyActive) {
            $visibility_condition .= " AND post_status IN ('publish')";
        }

        $count = (int) $this->wpdb->get_var("SELECT
                COUNT(ID)
            FROM {$this->wpdb->prefix}posts
            WHERE 1 {$visibility_condition}
            AND post_type = 'product'"
        );

        return $count;
    }

    /**
     * Returns min and max category ids
     * 
     * @param string $lang_code
     * 
     * @return array
     */
    private function getMinMaxCategoryId($lang_code)
    {
        $min_max = $this->wpdb->get_row("SELECT
                MIN(t.term_id) AS min,
                MAX(t.term_id) AS max
            FROM {$this->wpdb->prefix}terms as t
            INNER JOIN {$this->wpdb->prefix}term_taxonomy AS tt ON tt.term_id = t.term_id AND taxonomy = 'product_cat'",
            ARRAY_A
        );

        return array((int) $min_max['min'], (int) $min_max['max']);
    }

    /**
     * Returns min and max page ids
     * 
     * @param string $lang_code
     * @return array
     */
    private function getMinMaxPageId($lang_code)
    {
        $types = implode("','", self::getPostTypes());
        $min_max = $this->wpdb->get_row("SELECT
                MIN(ID) AS min,
                MAX(ID) AS max 
            FROM {$this->wpdb->prefix}posts
            WHERE post_status = 'publish'
            AND post_type IN ('{$types}')",
            ARRAY_A
        );

        return array((int) $min_max['min'], (int) $min_max['max']);
    }

    /**
     * Return valid product ids from range
     * 
     * @param int $start
     * @param int $end
     * @param int $step
     * @param string lang_code
     * @param boolean $isOnlyActive
     * 
     * @return array
     */
    public function getProductsIdsFromRange($start, $end, $step, $lang_code, $isOnlyActive)
    {
        $visibility_condition = '';
        if ($isOnlyActive) {
            $visibility_condition .= " AND post_status IN ('draft', 'pending', 'private', 'publish')";
        }

        $ids = $this->wpdb->get_col("SELECT
                ID
            FROM {$this->wpdb->prefix}posts
            WHERE
                1 {$visibility_condition}
                AND ID >= {$start} 
                AND ID <= {$end}
                AND post_type = 'product'
                ORDER BY ID
                LIMIT {$step}"
        );

        /**
         * Filters product_ids from given range
         * 
         * @param array $ids        Product ids
         * @param int $start        Start product id
         * @param int $end          End product id
         * @param int $step         Maximum products count
         * @param string $lang_code Lang code
         */
        return (array)apply_filters('se_get_products_ids_from_range', $ids, $start, $end, $step, $lang_code, $isOnlyActive);
    }

    /**
     * Return valid category ids from range
     * 
     * @param int $start
     * @param int $end
     * @param int $step
     * @param string lang_code
     * 
     * @return array
     */
    private function getCategoriesIdsFromRange($start, $end, $step, $lang_code)
    {
        $ids = $this->wpdb->get_col("SELECT
                t.term_id
            FROM {$this->wpdb->prefix}terms as t
            INNER JOIN {$this->wpdb->prefix}term_taxonomy AS tt ON tt.term_id = t.term_id AND taxonomy = 'product_cat'
            WHERE 
                t.term_id >= {$start}
                AND t.term_id <= {$end}
            ORDER BY t.term_id
            LIMIT {$step}"
        );

        /**
         * Filters category_ids from given range
         * 
         * @param array $ids        Category ids
         * @param int $start        Start category id
         * @param int $end          End category id
         * @param int $step         Maximum categories count
         * @param string $lang_code Lang code
         */
        return (array)apply_filters('se_get_categories_ids_from_range', $ids, $start, $end, $step, $lang_code);
    }

    /**
     * Return valid page ids from range
     * 
     * @param int $start
     * @param int $end
     * @param int $step
     * @param string lang_code
     * 
     * @return array
     */
    private function getPagesIdsFromRange($start, $end, $step, $lang_code)
    {
        $types = implode("','", self::getPostTypes());
        $ids = $this->wpdb->get_col("SELECT
                ID
            FROM {$this->wpdb->prefix}posts
            WHERE 
                ID >= {$start} 
                AND ID <= {$end}
                AND post_status = 'publish'
                AND post_type IN ('{$types}')
                ORDER BY ID
                LIMIT {$step}"
        );

        /**
         * Filters page_ids from given range
         * 
         * @param array $ids        Page ids
         * @param int $start        Start page id
         * @param int $end          End page id
         * @param int $step         Maximum pages count
         * @param string $lang_code Lang code
         */
        return (array)apply_filters('se_get_pages_ids_from_range', $ids, $start, $end, $step, $lang_code);
    }

    /**
     * Get products filters
     * 
     * @param string lang_code
     * @return array
     */
    public function getProductFilters($lang_code)
    {
        $filters = array();

        $filters[] = array(
            'name'     => 'price',
            'label'    => __('Price', 'woocommerce'),
            'type'     => 'slider',
            'position' => 5,
        );

        $filters[] = array(
            'name'     => 'stock_status',
            'label'    => __('Stock status', 'woocommerce'),
            'type'     => 'select',
            'position' => 10,
        );

        if (ApiSe::getInstance()->isResultWidgetEnabled($lang_code)) {
            $filters[] = array(
                'name'        => 'categories',
                'label'       => __('Categories', 'woocommerce'),
                'type'        => 'select',
                'text_search' => 'Y',
                'weight'      => self::WEIGHT_CATEGORIES,
                'position'    => 15,
            );
            $filters[] = array(
                'name'        => 'category_ids',
                'label'       => __('Categories', 'woocommerce') . ' - IDs',
                'weight'      => 0,
                'text_search' => 'N',
                'facet'       => 'N',
            );
        } else {
            $filters[] = array(
                'name'        => 'category_ids',
                'label'       => __('Categories', 'woocommerce') . ' - IDs',
                'type'        => 'select',
                'text_search' => 'N',
                'weight'      => 0,
                'position'    => 15,
            );
            $filters[] = array(
                'name'        => 'categories',
                'label'       => __('Categories', 'woocommerce'),
                'text_search' => 'N',
                'weight'      => 0,
                'facet'       => 'N',
            );
        }

        $filters[] = array(
            'name'     => 'tags',
            'label'    => __('Product tags', 'woocommerce'),
            'type'     => 'select',
            'position' => 20,
        );

        $filters = array_merge($filters, $this->getAttributeFilters($lang_code, 25));

        /**
         * Filters available product filters
         * 
         * @param array $filters     Product filters
         * @param string $lang_code  Lang code
         */
        return (array)apply_filters('se_get_get_product_filters', $filters, $lang_code);
    }

    /**
     * Generate product attribute filters
     * 
     * @param string $lang_code Lang code
     * @param int $position     Start filter position
     * @return array
     */
    public function getAttributeFilters($lang_code, $position = 30)
    {
        $filters = array();
        $attributes = wc_get_attribute_taxonomies();

        foreach ($attributes as $attr) {
            $filters[] = self::generateFilterFromAttribute($attr, $lang_code, 'select', $position);
            $position += 5;
        }

        return $filters;
    }

    /**
     * Generate Searchanise filter data from WC attribute
     * 
     * @param object $attr  WC attribute data
     * @param string        $lang_code
     * @param string $type  Facet type
     * @param int $position Facet position
     * @return array
     */
    public static function generateFilterFromAttribute($attr, $lang_code, $type = 'select', $position = null)
    {
        $filter = array(
            'name'        => self::getTaxonomyId($attr),
            'label'       => $attr->attribute_label,
            'text_search' => 'Y',
            'type'        => $type,
        );

        if ($position !== null) {
            $filter['position'] = $position;
        }

        /**
         * Filters generated facet data
         * 
         * @param array $filter           Facet data
         * @param object $attr            Original taxonomy attribute
         * @param $lang_code              Lang code
         */
        return (array)apply_filters('se_generate_filter_from_attribute', $filter, $attr, $lang_code);
    }

    /**
     * Return all product tags
     * 
     * @param string $lang_code
     * @return array
     */
    public function getProductTags($lang_code)
    {
        $product_tags = get_terms(array(
            'taxonomy'   => 'product_tag',
            'hide_empty' => false,
        ));

        /**
         * Filters product tags
         * 
         * @param array $product_tags Product tags
         * @param string $lang_code   Lang code
         */
        return (array)apply_filters('se_get_product_tags', $product_tags, $lang_code);
    }

    /**
     * Prepare facet data from filter
     * 
     * @param array $filter
     * @param string $lang_code
     * @return array
     */
    public function prepareFacetData($filter, $lang_code)
    {  
        $entry = array();
        static $color_attributes = array();
        static $size_attributes = array();

        if (empty($color_attributes)) {
            $color_attributes = ApiSe::getInstance()->getColorAttributes();
        }

        if (empty($size_attributes)) {
            $size_attributes = ApiSe::getInstance()->getSizeAttributes();
        }

        if (!empty($filter['name'])) {
            $entry['name'] = $filter['name'];

            if (isset($filter['text_search'])) {
                $entry['text_search'] = $filter['text_search'];
            }

            if (isset($filter['weight'])) {
                $entry['weight'] = $filter['weight'];
            }

            if (!isset($filter['facet']) || $filter['facet'] != 'N') {
                $entry['facet']['title']      = isset($filter['label']) ? $filter['label'] : $filter['name'];
                $entry['facet']['position']   = isset($filter['position']) ? $filter['position'] : '';
                $entry['facet']['type']       = isset($filter['type']) ? $filter['type'] : 'select';
                $entry['facet']['appearance'] = 'default';

                if (ApiSe::getInstance()->isResultWidgetEnabled($lang_code)) {
                    if (in_array(strtolower($entry['name']), $color_attributes)) {
                        $entry['facet']['appearance'] = 'color';
                    }

                    if (in_array(strtolower($entry['name']), $size_attributes)) {
                        $entry['facet']['appearance'] = 'size';
                    }
                }

            } else {
                $entry['facet'] = 'N';
            }
        }

        return $entry;
    }

    /**
     * Return product ids excluded from indexation
     * 
     * @return array
     */
    public function getExcludedProductIds()
    {
        static $excluded_product_ids = array();

        $excluded_tags = ApiSe::getInstance()->getSystemSetting('excluded_tags');
        if (!empty($excluded_tags) && empty($excluded_product_ids)) {
            $excluded_product_ids = wc_get_products(array(
                'tag'     => explode(',', $excluded_tags),
                'limit'   => -1,
                'for_searchanise' => true,
                'return' => 'ids',
            ));
        }

        return $excluded_product_ids;
    }

    /**
     * Returns related product ids
     * 
     * @param WC_Product $product Product data
     * @param int limit           Maximum related products
     * 
     * @return array
     */
    public function getRelatedProductIds($product, $limit = 100)
    {
        return wc_get_related_products($product->get_id(), $limit, $this->getExcludedProductIds());
    }

    /**
     * Get products data for Se request
     * 
     * @param array $product_ids
     * @param string $lang_code
     * @param boolean $lf_echo
     */
    public function getProductsData($product_ids, $lang_code, $lf_echo = true)
    {
        $products = $schema = $items = array();
        $product_ids = array_diff((array)$product_ids, $this->getExcludedProductIds());

        if (!empty($product_ids)) {
            $products = wc_get_products(array(
                'include' => $product_ids,
                'limit'   => -1,
                'for_searchanise' => true,
            ));
        }

        if ($lf_echo) {
            ApiSe::getInstance()->echoProgress('.');
        }

        if (!empty($products)) {
            $this->getProductsAdditional($products);

            foreach ($products as $product) {
                $item = array();
                $data = $this->prepareProductData($product, $lang_code);

                if (empty($data)) {
                    continue;
                }

                foreach ($data as $name => $d) {
                    if (!empty($d['name'])) {
                        $name = $d['name'];
                    } else {
                        $d['name'] = $name;
                    }

                    if (isset($d['value'])) {
                        $item[$name] = $d['value'];
                        unset($d['value']);
                    }

                    if (!empty($d)) {
                        $schema[$name] = $d;
                    }
                }
                $items[] = $item;
            }
        }

        return array(
            'schema' => $schema,
            'items'  => $items,
        );
    }

    /**
     * Returns sortable attributes
     * 
     * @return array
     */
    public function getSortableAttributes()
    {
        $sortable_attributes = array(
            'title',
            'sales_amount',
            'created',
            'modified',
            'price',
            'menu_order',
            'stock_status',
        );

        /**
         * Filters sortable attributes
         * 
         * @param array $sortable_attributes Sortable attributes list
         */
        return (array)apply_filters('se_get_sortable_attributes', $sortable_attributes);
    }

    /**
     * Get product image url
     * 
     * @param int $image_id
     * @param int $size
     * 
     * @return string
     */
    private function getProductImage($image_id, $size)
    {
        $image_url = '';

        /**
         * Pre filter product image data
         *
         * @param string   $image_url Product image url
         * @param int|null $image_id  Attachment ID
         * @param int      $size      Image size
         */
        $image_url = apply_filters('se_get_product_image_pre', $image_url, $image_id, $size);

        if (empty($image_url) && !empty($image_id) && !empty($size)) {
            if (ApiSe::getInstance()->useDirectImageLinks()) {
                $image = wc_get_product_attachment_props($image_id);

                if (!empty($image['url'])) {
                    $image_url = $image['url'];
                }
            } else {
                $image_src = wp_get_attachment_image_src($image_id, array($size, $size), true);

                if (!empty($image_src)) {
                    $image_url = $image_src[0];
                }
            }
        }

        /**
         * Post filter product image data
         *
         * @param string   $image_url Product image url
         * @param int|null $image_id  Attachment ID
         * @param int      $size      Image size
         */
        return apply_filters('se_get_product_image_post', $image_url, $image_id, $size);
    }

    /**
     * Trim and remove empty values from list
     * 
     * @param array $values
     * @return array
     */
    private function filterGroupedValues(array $values)
    {
        return array_unique(array_filter(array_map('trim', $values), function($v) {
            return !empty($v);
        }));
    }

    /**
     * Returns available usergroups
     * 
     * @return array
     */
    public function getUserGroups()
    {
        static $user_groups = array();

        if (empty($user_groups)) {
            $user_groups = array_keys(wp_roles()->roles);
        }

        /**
         * Filters available usergroups
         * 
         * @param array $user_groups User groups
         */
        return (array)apply_filters('se_get_usergroups', $user_groups);
    }

    /**
     * Checks if usergroup prices functionality is available
     * 
     * @return boolean
     */
    public function isUsergroupPricesAvailable()
    {
        $is_usergroup_prices_available = false;

        /**
         * Filters usergroup price availability for Searchanise
         * 
         * @param bool $is_usergroup_prices_available Usergroup price availability
         */
        return (bool)apply_filters('se_is_usergroup_prices_available', $is_usergroup_prices_available);
    }

    /**
     * Generate product prices for usergroups
     * 
     * @param array $entry
     * @param WC_Product $product_data
     * @param array $children_products
     * @param string $lang_code
     * 
     * @return boolean
     */
    public function generateUsergroupProductPrices(&$entry, $product_data, $children_products = array(), $lang_code)
    {
        if (empty($product_data)) {
            return false;
        }

        // Clean up user roles
        $current_user        = wp_get_current_user();
        $curent_user_roles   = $current_user->roles;
        $current_user->roles = array();

        // General common prices
        $prices = $this->generateProductPrices($product_data, $children_products, $lang_code);
        $entry['price'] = array(
            'value' => (float) $prices['price'],
            'title' => __('Price', 'woocommerce'),
            'type'  => 'float',
        );
        $entry['list_price'] = array(
            'value' => (float) $prices['regular_price'],
            'title' => __('Regular price', 'woocommerce'),
            'type'  => 'float',
        );
        $entry['sale_price'] = array(
            'value' => (float) $prices['sale_price'],
            'title' => __('Sale price', 'woocommerce'),
            'type'  => 'float',
        );
        $entry['max_price'] = array(
            'value' => (float) $prices['max_price'],
            'title' => __('Max price', 'woocommerce'),
            'type'  => 'float',
        );

        if ($prices['max_discount']) {
            $entry['discount'] = array( 
                'value' => (int) round($prices['max_discount']),
                'title' => __('Discount', 'woocommerce'),
                'type'  => 'int',
            );
        }

        // Generate usergroup prices if plugin is active
        if ($this->isUsergroupPricesAvailable()) {
            foreach ($this->getUserGroups() as $role) {
                // Set user role and generate price for it
                $current_user->roles = array($role);
                $prices = $this->generateProductPrices($product_data, $children_products, $lang_code);

                $entry[ApiSe::LABEL_FOR_PRICES_USERGROUP . $role] = array(
                    'value' => (float)$prices['price'],
                    'title' => __('Price for ', 'woocommerce-searchanise') . $role,
                    'type'  => 'float',
                );
            }
        }

        // Restore original roles
        $current_user->roles = $curent_user_roles;

        return true;
    }

    /**
     * Generate product prices for current usergroup
     * 
     * @param WC_Product $product_data
     * @param array $children_products
     * @param string $lang_code
     * 
     * @return array
     */
    public function generateProductPrices($product_data, $children_products = null, $lang_code)
    {
        // Fix for the "Booster for WooCommerce" plugin
        // Prevent using wrong currency while indexing, caused by plugin's Multicurrency module.
        if (function_exists('WCJ') && function_exists('wcj_remove_change_price_hooks')) {
            $wcj_multicurrency = WCJ()->modules['multicurrency'];
            wcj_remove_change_price_hooks($wcj_multicurrency, $wcj_multicurrency->price_hooks_priority);
        }

        if ($product_data instanceof WC_Product_Variable) {
            // Variable product
            $prices = $this->getVariationProductPrices($product_data);

        } elseif ($product_data instanceof WC_Product_Grouped) {
            // Grouped product
            $child_prices = $child_regular_prices = $child_sale_prices = $discounts = array();
            $children = !empty($children_products) ? $children_products : $this->getChildrenProducts($product_data);

            foreach ($children as $child) {
                $_child_prices = $this->generateProductPrices($child, null, $lang_code);

                if (!empty($_child_prices['price'])) {
                    $child_prices[] = $_child_prices['price'];
                }

                if (!empty($_child_prices['regular_price'])) {
                    $child_regular_prices[] = $_child_prices['regular_price'];
                }

                if (!empty($_child_prices['sale_price'])) {
                    $child_sale_prices[] = $_child_prices['sale_price'];
                }

                if (!empty($_child_prices['max_discount'])) {
                    $discounts[] = $_child_prices['max_discount'];
                }
            }

            if (!empty($child_prices) || !empty($child_regular_prices) || !empty($child_sale_prices)) {
                $min_price = min(array_merge($child_prices, $child_regular_prices, $child_sale_prices));

                if (!empty($child_sale_prices)) {
                    $max_price = max($child_sale_prices);
                } elseif (!empty($child_regular_prices)) {
                    $max_price = max($child_regular_prices);
                } else {
                    $max_price = max($child_prices);
                }

            } else {
                $min_price = $max_price = 0;
            }

            $price         = $min_price;
            $regular_price = $min_price;
            $sale_price    = $min_price;

            $prices = array(
                'price'         => $price,
                'regular_price' => $regular_price,
                'sale_price'    => $sale_price,
                'max_price'     => $max_price,
            );

            if (!empty($discounts)) {
                $prices['max_discount'] = max($discounts);
            }

        } else {
            // Simple product
            $prices = $this->getSimpleProductPrices($product_data);
        }

        /**
         * Filters generated usergroup prices
         * 
         * @param array $prices            Usergroup prices data
         * @param WC_Product $product_data Product data
         * @param array $children_products Product children (for grouped product)
         * @param string $lang_code        Lang code
         */
        return (array)apply_filters('se_generate_product_prices', $prices, $product_data, $children_products, $lang_code);
    }

    /**
     * Generates product prices for Variable product
     *
     * @param WC_Product_Variable $product_data
     *
     * @return array
     */
    public function getVariationProductPrices($product_data)
    {
        // Variable product
        $variations = $product_data->get_available_variations();
        $discounts = array();

        foreach ($variations as $v) {
            if (
                ($v['is_in_stock'] || 'yes' !== get_option('woocommerce_hide_out_of_stock_items')) &&
                !empty($v['display_regular_price']) &&
                $v['display_price'] < $v['display_regular_price']
            ) {
                $discounts[] = (1.0 - (float) $v['display_price'] / (float) $v['display_regular_price']) * 100;
            }
        }

        $prices = array(
            'price'         => $product_data->get_variation_price('min', true),
            'regular_price' => $product_data->get_variation_regular_price('min', true),
            'sale_price'    => $product_data->get_variation_sale_price('min', true),
            'max_price'     => $product_data->get_variation_price('max', true),
        );

        if (!empty($discounts)) {
            $prices['max_discount'] = max($discounts);
        }

        return $prices;
    }

    /**
     * Get simple product's prices array
     *
     * @param WC_Product $product_data
     *
     * @return array
     */
    public function getSimpleProductPrices($product_data)
    {
        if ($product_data->is_on_sale()) {
            $price = $max_price = wc_get_price_to_display($product_data, array('price' => $product_data->get_sale_price()));
        } else {
            $price = $max_price = wc_get_price_to_display($product_data);
        }

        $regular_price      = wc_get_price_to_display($product_data, array('price' => $product_data->get_regular_price()));
        $sale_price         = wc_get_price_to_display($product_data, array('price' => $product_data->get_sale_price()));

        $prices = array(
            'price'         => $price,
            'regular_price' => $regular_price,
            'sale_price'    => $sale_price,
            'max_price'     => $max_price,
        );

        if (!empty($regular_price)) {
            $prices['max_discount'] = (1.0 - $price / $regular_price) * 100;
        }

        return $prices;
    }

    /**
     * Returns available usergroups for product
     * 
     * @param WC_Product $product_data
     * @param string $lang_code
     * 
     * @return array
     */
    public function getProductsUsergroupIds($product_data, $lang_code)
    {
        $usergroup_ids = array(ApiSe::USERGROUP_GUEST);

        /**
         * Filters product usergroup ids
         * 
         * @param array $usergroup_ids     Product usergroup ids
         * @param WC_product $product_data Product data
         * @param $lang_code               Lang code
         */
        return (array)apply_filters('se_product_usergroup_ids', $usergroup_ids, $product_data, $lang_code);
    }

    /**
     * Returns add to cart url for product
     * 
     * @param WC_Product $product_data Product data
     * 
     * @return string
     */
    public function getAddToCartProductUrl($product_data)
    {
        if (
            empty($product_data)
            || !$product_data instanceof WC_Product
            || in_array($product_data->get_type(), array('grouped', 'external'))
        ) {
            return '';
        }

        return admin_url('admin-ajax.php?' . http_build_query(array(
            'action'     => 'se_ajax_add_to_cart',
            'product_id' => $product_data->get_id())
        ));
    }

    /**
     * Returns children products for group
     * 
     * @param WC_Product_Grouped $product_data
     *
     * @return array
     */
    public function getChildrenProducts($product_data)
    {
        $children = array_map('wc_get_product', $product_data->get_children());
        $children = array_filter($children, function($c) {
            return $c instanceof WC_Product;
        });

        foreach ($children as $k => $child) {
            if ($this->getProductQuantity($child) == 0 && 'yes' === get_option('woocommerce_hide_out_of_stock_items')) {
                unset($children[$k]);
            }
        }

        return $children;
    }

    /**
     * Generate product data
     * 
     * @param WC_Product $product_data
     * @param $lang_code
     * 
     * @return array
     */
    public function prepareProductData($product_data, $lang_code)
    {
        // Fix for gift-wrapper-for-woocommerce module
        if (class_exists('GTW_Frontend', false)) {
            global $product;
            $product = $product_data;
        }

        $entry = array(
            'id' => array(
                'value' => $product_data->get_id(),
                'title' => __('product Id', 'woocommerce-searchanise'),
            ),
            'title' => array(
                'value'  => $product_data->get_name(),
                'title'  => __('Product Title', 'woocommerce'),
                'weight' => self::WEIGHT_SHORT_TITLE,
            ),
            'slug' => array(
                'value' => $product_data->get_slug(),
                'title' => __('Slug', 'woocommerce'),
            ),
            'summary' => array(
                'value' => $product_data->get_short_description() != '' ? $product_data->get_short_description() : $product_data->get_description(),
                'title' => __('Summary', 'woocommerce-searchanise'),
            ),
            'product_type' => array(
                'value' => $product_data->get_type(),
                'title' => __('Product Type', 'woocommerce'),
            ),
            'link' => array(
                'value' => $product_data->get_permalink(),
                'title' => __('Product URL', 'woocommerce-searchanise'),
            ),
            'product_code' => array(
                'value'  => $product_data->get_sku(),
                'title'  => __('SKU', 'woocommerce'),
                'weight' => self::WEIGHT_SHORT_TITLE,
            ),
            'visibility' => array(
                'value' => $product_data->get_catalog_visibility(), // visible | catalog | search | hidden
                'title' => __('Visibility', 'woocommerce'),
            ),
            'status' => array(
                'value' => $product_data->get_status(), // published, trash, private, ...
                'title' => __('Status', 'woocommerce'),
            ),
            'image_link' => array(
                'title' => __('Image link', 'woocommerce-searchanise'),
            ),
            'needs_shipping' => array(
                'value' => $product_data->needs_shipping() ? 'N' : 'Y',
                'title' => __('Free shipping', 'woocommerce'),
            ),
            'sold_individually' => array(
                'value' => $product_data->get_sold_individually() ? 'Y' : 'N',
                'title' => __('Sold individually', 'woocommerce')
            ),
            'virtual' => array(
                'value' => $product_data->get_virtual() ? 'Y' : 'N',
                'title' => __('Virutal', 'woocommerce'),
            ),
            'downloadable' => array(
                'value' => $product_data->get_downloadable() ? 'Y' : 'N',
                'title' => __('Downloadable', 'woocommerce'),
            ),
            'menu_order' => array(
                'value' => $product_data->get_menu_order(),
                'title' => __('Menu order', 'woocommerce'),
                'type'  => 'int'
            ),
            'weight' => array(
                'value' => (float)$product_data->get_weight(),
                'title' => __('Weight', 'woocommerce'),
                'type'  => 'float',
            ),
            'length' => array(
                'value' => (float)$product_data->get_length(),
                'title' => __('Length', 'woocommerce'),
                'type'  => 'float',
            ),
            'width' => array(
                'value' => (float)$product_data->get_width(),
                'title' => __('Width', 'woocommerce'),
                'type'  => 'float',
            ),
            'height' => array(
                'value' => (float)$product_data->get_height(),
                'title' => __('Height', 'woocommerce'),
                'type'  => 'float',
            ),
        );

        if ($product_data instanceof WC_Product_Variable) {
            // Variable product
            $variations = $product_data->get_available_variations();
            $variants = array();
            $variants_skus = $variants_descriptions = array();

            foreach ($variations as $v) {
                $variant = array(
                    'product_code' => $v['sku'],
                    'variation_id' => $v['variation_id'],
                    'price'        => (float)$v['display_price'],
                    'list_price'   => (float)$v['display_regular_price'],
                    'is_in_stock'  => $v['is_in_stock'] ? 'Y' : 'N', // TODO: Need additional check for variation manage stock 
                    'description'  => $v['variation_description'],
                    'active'       => $v['variation_is_active'] ? 'Y' : 'N',
                    'visible'      => $v['variation_is_visible'] ? 'Y' : 'N',
                    'image_link'   => '',
                );

                // Generate image link
                if (ApiSe::getInstance()->isResultWidgetEnabled($lang_code)) {
                    $image_url = $this->getProductImage($v['image_id'], self::IMAGE_SIZE);
                } else {
                    $image_url = $this->getProductImage($v['image_id'], self::THUMBNAIL_SIZE);
                }
                $variant['image_link'] = !empty($image_url) ? $image_url : '';

                // Adds attributes
                if (!empty($v['attributes'])) {
                    foreach ($v['attributes'] as $attr_name => $attr_val) {
                        $parsed_attr_name = str_replace('attribute_pa_', '', $attr_name);
                        $variant['attributes'][$parsed_attr_name] = $attr_val;
                    }
                }

                if (!empty($variant['product_code'])) {
                    $variants_skus[] = $variant['product_code'];
                }

                if (!empty($variant['description'])) {
                    $variants_descriptions[] = $variant['description'];
                }

                $variants[] = $variant;
            }

            if (!empty($variants) && self::SEND_VARIATIONS) {
                $entry['woocommerce_variants'] = array(
                    'name'  => 'woocommerce_variants',
                    'title' => __('WooCommerce variants', 'woocommerce-searchanise'),
                    'value' => $variants,
                );
            }

            // Grouped data
            if (!empty($variants_skus)) {
                $entry['se_grouped_product_code'] = array(
                    'title'       => __('SKU', 'woocommerce') . ' - Grouped',
                    'weight'      => self::WEIGHT_SHORT_TITLE,
                    'value'       => $this->filterGroupedValues($variants_skus),
                    'text_search' => 'Y',
                );
            }

            if (!empty($variants_descriptions)) {
                $entry['se_grouped_short_description'] = array(
                    'title'       => __('Product short description', 'woocommerce') . ' - Grouped',
                    'weight'      => self::WEIGHT_DESCRIPTION_GROUPED,
                    'value'       => $this->filterGroupedValues($variants_descriptions),
                    'text_search' => 'Y',
                );
            }

        } elseif ($product_data instanceof WC_Product_Grouped) {
            // Grouped product
            $children = $this->getChildrenProducts($product_data);

            foreach ($children as $child) {
                $child_skus[]               = $child->get_sku();
                $child_short_descriptions[] = $child->get_short_description();
                $child_full_descriptions[]  = $child->get_description();
            }

            // Grouped data
            if (!empty($child_skus)) {
                $entry['se_grouped_product_code'] = array(
                    'title'       => __('SKU', 'woocommerce') . ' - Grouped',
                    'weight'      => self::WEIGHT_SHORT_TITLE,
                    'value'       => $this->filterGroupedValues($child_skus),
                    'text_search' => 'Y',
                );
            }

            if (!empty($child_short_descriptions)) {
                $entry['se_grouped_short_description'] = array(
                    'title'       => __('Product short description', 'woocommerce') . ' - Grouped',
                    'weight'      => self::WEIGHT_DESCRIPTION_GROUPED,
                    'value'       => $this->filterGroupedValues($child_short_descriptions),
                    'text_search' => 'Y',
                );
            }

            if (!empty($child_full_descriptions)) {
                $entry['se_grouped_full_description'] = array(
                    'title'       => __('Product description', 'woocommerce') . ' - Grouped',
                    'weight'      => self::WEIGHT_DESCRIPTION_GROUPED,
                    'value'       => $this->filterGroupedValues($child_full_descriptions),
                    'text_search' => 'Y',
                );
            }

        } else {
            // Simple product
            // No actions
        }

        // Generate usergroup prices
        $this->generateUsergroupProductPrices($entry, $product_data, isset($children) ? $children : array(), $lang_code);

        // Generate image link
        if (ApiSe::getInstance()->isResultWidgetEnabled($lang_code)) {
            $image_url = $this->getProductImage($product_data->get_image_id(), self::IMAGE_SIZE);
        } else {
            $image_url = $this->getProductImage($product_data->get_image_id(), self::THUMBNAIL_SIZE);
        }

        if (!empty($image_url)) {
            $entry['image_link']['value'] = htmlspecialchars($image_url);
        }

        // Generate image gallery
        if (ApiSe::getInstance()->isResultWidgetEnabled($lang_code)) {
            $gallery_image_ids = $product_data->get_gallery_image_ids();

            if (!empty($gallery_image_ids)) {
                $gallery_images = array();

                foreach ($gallery_image_ids as $image_id) {
                    $image_url = $this->getProductImage($image_id, self::IMAGE_SIZE);

                    if (!empty($image_url)) {
                        $gallery_images[] = htmlspecialchars($image_url);
                    }
                }

                if (!empty($gallery_images)) {
                    $entry['woocommerce_images'] = array(
                        'value' => $gallery_images,
                        'title' => __('Product images', 'woocommerce'),
                    );
                }
            }
        }

        // Adds full description if needed
        if ($product_data->get_short_description() != '' && $product_data->get_description() != '') {
            $entry['full_description'] = array(
                'name'        => 'full_description',
                'title'       => __('Product description', 'woocommerce'),
                'text_search' => 'Y',
                'weight'      => self::WEIGHT_DESCRIPTION,
                'value'       => $product_data->get_description(),
            );
        }

        // Adds stock data
        $entry['quantity'] = array(
            'value' => $this->getProductQuantity($product_data, isset($children) ? $children : array()),
            'title' => __('Stock quantity', 'woocommerce'),
            'type'  => 'int',
        );
        $entry['stock_status'] = array(
            'name'  => 'stock_status',
            'title' => __('Stock status', 'woocommerce'),
            'value' => $this->getStockStatus($product_data, $lang_code),
        );
        $entry['is_in_stock'] = array(
            'name'  => 'is_in_stock',
            'value' => $entry['quantity']['value'] > 0 ? 'Y' : 'N',
        );

        // Adds product attributes
        $attributes = $product_data->get_attributes();
        if (!empty($attributes)) {
            foreach ($attributes as $attr_name => $attr) {
                $this->generateProductAttribute($entry, $attr, $lang_code);
            }
        }

        // Add dates
        $created = $product_data->get_date_created();
        $modified = $product_data->get_date_modified();

        if ($created instanceof WC_DateTime) {
            $entry['created'] = array(
                'value' => $created->getTimestamp(),
                'title' => __('Created at', 'woocommerce'),
                'type'  => 'int',
            );
        }

        if ($modified instanceof WC_DateTime) {
            $entry['modified'] = array(
                'value' => $modified->getTimestamp(),
                'title' => __('Updated at', 'woocommerce'),
                'type'  => 'int',
            ); 
        }

        // Adds tags
        $tag_ids = $product_data->get_tag_ids();
        if (!empty($tag_ids)) {
            $entry['tags'] = array(
                'title'       => __('Product tags', 'woocommerce'),
                'type'        => 'text',
                'text_search' => 'Y',
                'weight'      => self::WEIGHT_TAGS,
                'value'       => $this->getProductTerms($tag_ids, 'product_tag', $lang_code),
            );
        }

        // Adds review data
        if ('yes' === get_option('woocommerce_enable_reviews', 'yes') && $product_data->get_reviews_allowed()) {
            $entry['total_reviews'] = array(
                'value' => (int)$product_data->get_review_count(),
                'title' => __('Total reviews', 'woocommerce-searchanise'),
            );
            $entry['reviews_average_score'] = array(
                'value' => (float)round($product_data->get_average_rating(), 1),
                'title' => __('Average reviews score', 'woocommerce-searchanise'),
            );
        }

        // Adds category data
        $category_ids = $product_data->get_category_ids();
        if (!empty($category_ids)) {
            $entry['category_ids'] = array(
                'name'        => 'category_ids',
                'title'       => __('Categories', 'woocommerce') . ' - IDs',
                'value'       => $category_ids,
                'weight'      => 0,
                'text_search' => 'N',
                'type'        => 'text',
            );

            $entry['categories'] = array(
                'value'       => $this->getProductTerms($category_ids, 'product_cat', $lang_code),
                'title'       => __('Categories', 'woocommerce'),
                'text_search' => 'Y',
                'weight'      => self::WEIGHT_CATEGORIES,
                'type'        => 'text',
            );
        }

        // Adds sales data
        $entry['sales_amount'] = array(
            'name'       => 'sales_amount',
            'title'      => __('Sales amount', 'woocommerce'),
            'text_search' => 'N',
            'type'       => 'int',
            'value'      => (int)get_post_meta($product_data->get_id(), 'total_sales', true),
        );
        // TODO: sales_total

        // Adds usergroup for visibility
        $usergroup_ids = $this->getProductsUsergroupIds($product_data, $lang_code);
        if (!empty($usergroup_ids)) {
            $entry['usergroup_ids'] = array(
                'name'       => 'usergroup_ids',
                'title'      => __('User role', 'woocommerce') . ' - IDs',
                'text_search' => 'N',
                'value'      => $usergroup_ids,
            );
        }

        // Adds meta data
        $entry = array_merge($entry, $this->prepareProductMetaData($product_data, $lang_code));

        // Add to cart functionality
        $entry['add_to_cart_url'] = array(
            'name'        => 'add_to_cart_url',
            'title'       => __('Add to cart url', 'woocommerce'),
            'text_search' => 'N',
            'sorting'     => 'N',
            'filter_type' => 'none',
            'value'       => $this->getAddToCartProductUrl($product_data),
        );

        // Adds upsell & crossell & related products for Recommendations
        $entry['cross_sell_product_ids'] = array(
            'name'        => 'cross_sell_product_ids',
            'title'       => __('Cross-Sell Products', 'woocommerce') . ' - IDs',
            'filter_type' => 'none',
            'value'       => $product_data->get_cross_sell_ids(),
        );

        $entry['up_sell_product_ids'] = array(
            'name'        => 'up_sell_product_ids',
            'title'       => __('Up-Sell Products', 'woocommerce') . ' - IDs',
            'filter_type' => 'none',
            'value'       => $product_data->get_upsell_ids(),
        );

        $entry['related_product_ids'] = array(
            'name'        => 'related_product_ids',
            'title'       => __('Related Products', 'woocommerce') . ' - IDs',
            'filter_type' => 'none',
            'value'       => $this->getRelatedProductIds($product_data),
        );

        // Adds also bought products for Recommendations
        $entry['also_bought_product_ids'] = array(
            'name'        => 'also_bought_product_ids',
            'title'       => __('Also bought product', 'woocommerce') . ' - IDs',
            'filter_type' => 'none',
            'value'       => implode(',', $product_data->also_bought_product_ids),
        );

        /**
         * Adds additional attributes in format array({attr_name} => {type})
         */
        $additional_attributes = array(
        );

        foreach ($additional_attributes as $name => $type) {
            $method = 'get_' . $name;
            if (method_exists($product_data, $method)) {
                $value = call_user_func(array($product_data, $method));

                if ($value !== '') {
                    $entry[$name] = array(
                        'name'  => $name,
                        'title' => __($name, 'woocommerce-searchanise'),
                        'type'  => $type,
                        'value' => call_user_func(array($product_data, $method)),
                    );
                }
            }
        }

        // Check sorting attributes
        $sortable_attributes = $this->getSortableAttributes();
        foreach ($entry as $name => &$v) {
            if (in_array($name, $sortable_attributes)) {
                $v['sorting'] = 'Y';
            }
        }
        
        /**
         * Filters prepared product data for Searchanise
         * 
         * @param array $entry      Prepared product data
         * @param WC_Product        Original product data
         * @param string $lang_code Lang code
         */
        return (array)apply_filters('se_prepare_product_data', $entry, $product_data, $lang_code);
    }

    /**
     * Prepare product meta data
     * 
     * @param WC_Product $product_data Product data
     * @param string $lang_code Lang code
     * 
     * @return array
     */
    private function prepareProductMetaData($product_data, $lang_code)
    {
        $seometa_themes = array(
            // alphabatized
            'Builder'      => array(
                'meta_title'       => '_builder_seo_title',
                'meta_description' => '_builder_seo_description',
                'meta_keywords'    => '_builder_seo_keywords',
            ),
            'Catalyst'     => array(
                'meta_title'       => '_catalyst_title',
                'meta_description' => '_catalyst_description',
                'meta_keywords'    => '_catalyst_keywords',
            ),
            'Frugal'       => array(
                'meta_title'       => '_title',
                'meta_description' => '_description',
                'meta_keywords'    => '_keywords',
            ),
            'Genesis'      => array(
                'meta_title'       => '_genesis_title',
                'meta_description' => '_genesis_description',
                'meta_keywords'    => '_genesis_keywords',
            ),
            'Headway'      => array(
                'meta_title'       => '_title',
                'meta_description' => '_description',
                'meta_keywords'    => '_keywords',
            ),
            'Hybrid'       => array(
                'meta_title'  => 'Title',
                'meta_description' => 'Description',
                'meta_keywords'    => 'Keywords',
            ),
            'Thesis 1.x'   => array(
                'meta_title'       => 'thesis_title',
                'meta_description' => 'thesis_description',
                'meta_keywords'    => 'thesis_keywords',
            ),
            'WooFramework' => array(
                'meta_title'       => 'seo_title',
                'meta_description' => 'seo_description',
                'meta_keywords'    => 'seo_keywords',
            ),
        );

        $seometa_plugins = array(
            // alphabatized
            'Add Meta Tags' => array(
                'meta_title'       => '_amt_title',
                'meta_description' => '_amt_description',
                'meta_keywords'    => '_amt_keywords',
            ),
            'All in One SEO Pack'          => array(
                'meta_title'       => '_aioseop_title',
                'meta_description' => '_aioseop_description',
                'meta_keywords'    => '_aioseop_keywords',
            ),
            'Greg\'s High Performance SEO' => array(
                'meta_title'       => '_ghpseo_secondary_title',
                'meta_description' => '_ghpseo_alternative_description',
                'meta_keywords'    => '_ghpseo_keywords',
            ),
            'Headspace2'                   => array(
                'meta_title'      => '_headspace_page_title',
                'meta_description' => '_headspace_description',
                'meta_keywords'    => '_headspace_keywords',
            ),
            'Infinite SEO'                 => array(
                'meta_title'       => '_wds_title',
                'meta_description' => '_wds_metadesc',
                'meta_keywords'    => '_wds_keywords',
            ),
            'Jetpack'                => array(
                'meta_description' => 'advanced_seo_description',
            ),
            'Meta SEO Pack'                => array(
                'meta_description' => '_msp_description',
                'meta_keywords'    => '_msp_keywords',
            ),
            'Platinum SEO'                 => array(
                'meta_title'       => 'title',
                'meta_description' => 'description',
                'meta_keywords'    => 'keywords',
            ),
            'SEOpressor'                 => array(
                'meta_title'       => '_seopressor_meta_title',
                'meta_description' => '_seopressor_meta_description',
            ),
            'SEO Title Tag'                => array(
                'meta_title'       => 'title_tag',
                'meta_description' => 'meta_description',
            ),
            'SEO Ultimate'                 => array(
                'meta_title'       => '_su_title',
                'meta_description' => '_su_description',
                'meta_keywords'    => '_su_keywords',
            ),
            'Yoast SEO'                    => array(
                'meta_title'       => '_yoast_wpseo_title',
                'meta_description' => '_yoast_wpseo_metadesc',
                'meta_keywords'    => '_yoast_wpseo_metakeywords',
            ),
        );

        $seometa_platforms = array_merge($seometa_themes, $seometa_plugins);

        // Get meta values
        $meta_title = $meta_description = $meta_keywords = array();
        foreach ($seometa_platforms as $platform => $schema) {
            $_metas = get_post_meta($product_data->get_id());

            foreach ($schema as $name => $field) {
                if (isset($_metas[$field])) {
                    if (is_array($_metas[$field])) {
                        foreach ($_metas[$field] as $k => $v) {
                            ${$name} = array_merge(${$name}, $name == 'meta_keywords' ? explode(',', $v) : array($v));
                        }
                    } else {
                        ${$name} = array_merge(${$name}, $name == 'meta_keywords' ? explode(',', $_metas[$field]) : array($_metas[$field]));
                    }
                }
            }
        }

        // Filter for Yoast SEO
        $meta_title = str_replace(array('%%title%%', '%%sep%%', '%%sitename%%', '%%page%%'), array('', '', '', ''), $meta_title);
        $meta_description = str_replace(array('%%title%%', '%%sep%%', '%%sitename%%', '%%page%%'), array('', '', '', ''), $meta_description);

        /**
         * Filters product metadata
         * 
         * @param array $meta_data Product metadata
         * @param string $lang_code Lang code
         */
        $meta_data = apply_filters('se_prepare_product_meta_data', compact('meta_title', 'meta_description', 'meta_keywords'), $lang_code);

        // Prepare data
        $entry = array();
        if (!empty($meta_data['meta_title'])) {
            $entry['meta_title'] = array(
                'value'       => array_map('trim', array_unique($meta_data['meta_title'])),
                'title'       => __('Meta title', 'woocommerce-searchanise'),
                'text_search' => 'Y',
                'weight'      => self::WEIGHT_META_TITLE,
            );
        }

        if (!empty($meta_data['meta_description'])) {
            $entry['meta_description'] = array(
                'value'       => array_map('strip_tags', array_map('trim', array_unique($meta_data['meta_description']))),
                'title'       => __('Meta description', 'woocommerce-searchanise'),
                'text_search' => 'Y',
                'weight'      => self::WEIGHT_META_DESCRIPTION,
            );
        }

        if (!empty($meta_data['meta_keywords'])) {
            $entry['meta_keywords'] = array(
                'value'       => array_map('trim', array_unique($meta_data['meta_keywords'])),
                'title'       => __('Meta keywords', 'woocommerce-searchanise'),
                'text_search' => 'Y',
                'weight'      => self::WEIGHT_META_KEYWORDS,
            );
        }

        return $entry;
    }

    /**
     * Generate product attribute
     * 
     * @param array  $entity Searchanise data
     * @param object $attr Attribute
     * @param string $lang_code
     */
    public function generateProductAttribute(&$entry, $attr, $lang_code)
    {
        if ($attr->is_taxonomy()) {
            $taxonomy_object = $attr->get_taxonomy_object();
            $terms = $attr->get_terms();
            $variants = array();

            foreach ($terms as $term) {
                if (ApiSe::getInstance()->isResultWidgetEnabled($lang_code)) {
                    $variants[] = $term->name;
                } else {
                    $variants[] = $term->slug;
                }
            }

            $attribute_data = array(
                'title'       => __($taxonomy_object->attribute_label, 'woocommerce-searchanise'),
                'type'        => 'text',
                'text_search' => 'Y',
                'weight'      => self::WEIGHT_SELECT_ATTRIBUTES,
                'value'       => $variants,
            );

            /**
             * Filters data for generated taxonomy atrribute
             * 
             * @param array  $attribute_data Taxonomy attribute data
             * @param object $attr           Taxonomy attribute
             * @param string $lang_code      Lang code
             */
            $attribute_data = (array)apply_filters('se_generate_taxonomy_attribute', $attribute_data, $attr, $lang_code);
            $entry[self::getTaxonomyId($taxonomy_object)] = $attribute_data;

        } else {
            $attribute_data = array(
                'title'       => $attr->get_name(),
                'type'        => 'text',
                'text_search' => $attr->get_visible() ? 'Y' : 'N',
                'weight'      => $attr->get_visible() ? self::WEIGHT_TEXT_ATTRIBUTES : 0,
                'value'       => $attr->get_options(),
            );
            $attribute_id = self::getAttributeId($attr);

            if (!empty($attribute_id)) {
                /**
                 * Filters simple attribute data
                 * 
                 * @param array  $attribute_data Attribute data
                 * @param object $attr           Attribute
                 * @param string $lang_code      Lang code
                 */
                $entry[$attribute_id] = (array)apply_filters('se_generate_simple_attribute', $attribute_data, $attr, $lang_code);
            }
        }
    }

    /**
     * Returns identifier in Searchanise for taxonomy object
     * 
     * @param object $taxonomy Taxonomy attribute
     * 
     * @return string
     */
    public static function getTaxonomyId($taxonomy)
    {
        if (preg_match('/^[a-zA-Z_-][0-9a-zA-Z_-]+$/i', $taxonomy->attribute_name)) {
            return $taxonomy->attribute_name;
        } else {
            return 'custom_taxonomy_' . md5($taxonomy->attribute_name);
        }
    }

    /**
     * Returns identifier in Searchanise for attribute
     * 
     * @param object Attribute
     * 
     * @return string
     */
    public static function getAttributeId($attr)
    {
        $attribute_name = $attr->get_name();

        if (!empty($attribute_name)) {
            $attribute_name = 'custom_attribute_' . md5(strtolower($attribute_name));
        }

        return $attribute_name;
    }

    /**
     * Get stock product status
     * 
     * @param WC_Product
     * @param string Lang code
     * @return sting
     */
    public function getStockStatus($product, $lang_code)
    {
        $stock_status = $product->get_stock_status();

        if (ApiSe::getInstance()->isResultWidgetEnabled($lang_code)) {
            $statuses = wc_get_product_stock_status_options();
            $stock_status = $statuses[$product->get_stock_status()];
        }

        /**
         * Filters available stock statuses
         * 
         * @param string $stock_status Stock status name
         * @param WC_Product           Product data
         */
        return apply_filters('se_get_stock_status', $stock_status, $product);
    }

    /**
     * Get stock product quantity
     * 
     * @param WC_Product $product          Product data
     * @param array      $united_products  United product data
     *
     * @return int
     */
    public function getProductQuantity(WC_Product $product, $united_products = array())
    {
        $quantity = 1;

        if (get_option('woocommerce_manage_stock') == 'yes' && $product->get_manage_stock()) {
            $out_of_stock_amount = (int)get_option('woocommerce_notify_no_stock_amount');
            $quantity = max(0, $product->get_stock_quantity() - $out_of_stock_amount);

            if ($quantity <= 0) {
                if (in_array($product->get_backorders(), array('yes', 'notify'))) {
                    $quantity = 1;
                }
            }

            if (!empty($united_products)) { // TODO: Check if really needed
                foreach ($united_products as $_product) {
                    $quantity += $this->getProductQuantity($_product);
                }
            }
        } else {
            $quantity = in_array($product->get_stock_status(), array('instock', 'onbackorder')) ? 1 : 0;
        }

        // Limits quantity in rage -1, 0, -1
        $quantity = max(-1, min(1, $quantity));

        /**
         * Filters product quanity
         * 
         * @param int         $quantity  Product quantity
         * @param  WC_Product $product   Product data
         */
        return (int)apply_filters('se_get_product_quanity', $quantity, $product);
    }

    /**
     * Get additional data for products
     * 
     * @param array $products Products list
     */
    public function getProductsAdditional(array &$products)
    {
        if (empty($products)) {
            return;
        }

        $all_product_ids = $also_bought_data = array();
        foreach ($products as $product) {
            $all_product_ids[] = $product->get_id();
        }

        if (ApiSe::getInstance()->importAlsoBoughtProducts()) {
            $also_bought_data = $this->getAlsoBoughtProducts($all_product_ids);
        }

        foreach ($products as &$product) {
            $product_id = $product->get_id();

            $product->also_bought_product_ids = array();
            if (isset($also_bought_data[$product_id])) {
                $product->also_bought_product_ids = $also_bought_data[$product_id];
            }
        }

        $products = (array)apply_filters('se_get_products_additional', $products);
    }

    /**
     * Get Product Term
     * 
     * @param array $terms_ids
     * @param string $type Term type
     * @return array Terms list
     */
    private function getProductTerms($terms_ids, $type, $lang_code)
    {
        $terms = array();

        if (!empty($terms_ids)) {
            $terms_list = get_terms(array(
                'taxonomy'   => $type,
                'include'    => $terms_ids,
                'hide_empty' => false,
            ));

            if ($terms_list && !is_wp_error($terms_list)) {
                foreach ($terms_list as $term) {
                    $terms[] = (string)apply_filters('se_get_product_term_name', wp_specialchars_decode($term->name), $term, $lang_code);
                }
            }
        }

        return $terms;
    }

    /**
     * Fetch also bought product ids
     * 
     * @param array $product_ids Product identifiers
     * @param int   $limit_days  Limit order interval in days
     * 
     * @return array
     */
    public function getAlsoBoughtProducts(array $product_ids, $limit_days = 180)
    {
        global $wpdb, $table_prefix;

        $results = array();
        $pid = implode(',', $product_ids);

        // Fetch all order for products
        $orders_sql = "SELECT
            order_id,
            product_id
        FROM {$table_prefix}wc_order_product_lookup
        WHERE
            product_id IN ($pid)
            AND date_created > DATE_SUB(NOW(), INTERVAL {$limit_days} DAY)";
        $_all_orders = $wpdb->get_results($orders_sql, ARRAY_A);

        $all_orders = $all_orders_ids = array();
        foreach ($_all_orders as $data) {
            $all_orders[$data['product_id']][] = $data['order_id'];
            $all_orders_ids[] =  $data['order_id'];
        }
        unset($_all_orders);

        if (!empty($all_orders)) {
            $all_orders_str = implode(',', $all_orders_ids);

            // Fetch all order items for selected orders
            $order_products_sql = "SELECT
                product_id,
                order_id
            FROM {$table_prefix}wc_order_product_lookup
            WHERE
                order_id IN ($all_orders_str)
                ORDER BY order_id DESC";

            $all_orders_products = array();
            $_all_orders_products = $wpdb->get_results($order_products_sql, ARRAY_A);
            foreach ($_all_orders_products as $data) {
                $all_orders_products[$data['order_id']][] = $data['product_id'];
            }
            unset($_all_orders_products);

            // Assemble bought products data
            foreach ($all_orders as $product_id => $order_ids) {
                $results[$product_id] = isset($results[$product_id]) ? $results[$product_id] : array();

                foreach ($order_ids as $order_id) {
                    if (isset($all_orders_products[$order_id])) {
                        $results[$product_id] = array_merge($results[$product_id], $all_orders_products[$order_id]);

                        // Remove self
                        $self_index = array_search($product_id, $results[$product_id]);
                        if ($self_index !== false) {
                            unset($results[$product_id][$self_index]);
                        }
                    }
                }
            }

            $results = array_map('array_unique', $results);
        }

        return $results;
    }

    /**
     * Generate categories data
     * 
     * @param array $category_ids
     * @param string $lang_code
     * 
     * @return array
     */
    public function getCategoriesData($category_ids, $lang_code)
    {
        $categories = $data = array();

        if (!empty($category_ids)) {
            $categories = get_terms('product_cat', array(
                'include'    => (array)$category_ids,
                'hide_empty' => false,
            ));
        }

        foreach ($categories as $cat) {
            if (in_array($cat->slug, $this->getExcludedCategories())) {
                continue;
            }

            $image_url = '';
            // TODO: Get categories images using one loop in future
            $thumbnail_id = get_term_meta($cat->term_id, 'thumbnail_id');

            if (!empty($thumbnail_id)) {
                $thumbnail_id = reset($thumbnail_id);
                $image_url    = $this->getProductImage($thumbnail_id, self::THUMBNAIL_SIZE);
            }

            $category_data = array(
                'id'            => $cat->term_id,
                'parent_id'     => $cat->parent,
                'link'          => get_term_link($cat),
                'title'         => $cat->name,
                'summary'       => $cat->description,
                'image_link'    => $image_url,
            );

            /**
             * Filters categories data for Searchanise
             * 
             * @param array $category_data Prepared category data
             * @param object $cat          Original category
             * @param string $lang_code    Lang code
             */
            $data[] = (array)apply_filters('se_get_category_data', $category_data, $cat, $lang_code);
        }

        return array('categories' => $data);
    }

    /**
     * Generate pages data
     * 
     * @param array $page_ids
     * @param string $lang_code
     * 
     * @return array
     */
    public function getPagesData($page_ids, $lang_code)
    {
        $pages = $data = array();

        if (!empty($page_ids)) {
            $pages = get_posts(array(
                'include'     => (array)$page_ids,
                'post_type'   => self::getPostTypes(),
            ));
        }

        $excluded_pages = array_merge($this->getExcludedPages(), array(
            ApiSe::getInstance()->getSystemSetting('search_result_page'),
        ));

        foreach ($pages as $post) {
            if (
                $post->post_status == 'publish'
                && !in_array($post->post_name, $excluded_pages)
            ) {
                $page_data = array(
                    'id'      => $post->ID,
                    'link'    => get_permalink($post),
                    'title'   => $post->post_title,
                    'summary' => self::STRIP_POST_CONTENT ? wp_strip_all_tags($post->post_content) : $post->post_content,
                );

                /**
                 * Filters prepared page data for Searchanise
                 * 
                 * @param array $page_data  Preapared page data
                 * @param WP_Post $post     Page data
                 * @param string $lang_code Lang code
                 */
                $data[] = (array)apply_filters('se_get_page_data', $page_data, $post, $lang_code);
            }
        }

        return array('pages' => $data);
    }

    /**
     * Returns post types to be indexed as pages
     * 
     * @return array
     */
    public static function getPostTypes()
    {
        $types = array('page');

        if (ApiSe::getInstance()->importBlockPosts()) {
            $types[] = 'post';
        }

        return $types;
    }

    /**
     * Returns excluded pages from indexation
     * 
     * @return array
     */
    public function getExcludedPages()
    {
        $excluded_pages = explode(',', ApiSe::getInstance()->getSystemSetting('excluded_pages'));

        return (array) apply_filters('se_get_excluded_pages', $excluded_pages);
    }

    /**
     * Returns excluded categories from indexation
     * 
     * @return array
     */
    public function getExcludedCategories()
    {
        $excluded_categories = explode(',', ApiSe::getInstance()->getSystemSetting('excluded_categories'));

        return (array) apply_filters('se_get_excluded_categories', $excluded_categories);
    }

    /**
     * Rates ajax request action
     */
    public static function rated()
    {
        ApiSe::getInstance()->setIsRated();
        wp_die("OK");
    }

    /**
     * Async ajax request action
     */
    public static function ajaxAsync()
    {
        if (ApiSe::getInstance()->getModuleStatus() != 'Y') {
            wp_die(__('Searchanise module not enabled', 'woocommerce-searchanise'));
        }

        $lang_code = !empty($_REQUEST[self::FL_LANG_CODE]) ? $_REQUEST[self::FL_LANG_CODE] : ApiSe::getInstance()->getLocale();
        if (!ApiSe::getInstance()->checkPrivateKey($lang_code)) {
            wp_die(__('Invalid private key', 'woocommerce-searchanise'));
        }

        if (!empty($_REQUEST[self::FL_DISPLAY_ERRORS]) && $_REQUEST[self::FL_DISPLAY_ERRORS] == self::FL_DISPLAY_ERRORS_KEY) {
            @error_reporting(E_ALL | E_STRICT);
            @ini_set('display_errors', 1);
            @ini_set('display_startup_errors', 1);
        } else {
            @error_reporting(0);
            @ini_set('display_errors', 0);
            @ini_set('display_startup_errors', 0);
        }
    
        $fl_ignore_processing = !empty($_REQUEST[self::FL_IGNORE_PROCESSING]) && $_REQUEST[self::FL_IGNORE_PROCESSING] == self::FL_IGNORE_PROCESSING_KEY;
        $fl_show_status       = !empty($_REQUEST[self::FL_SHOW_STATUS_ASYNC]) && $_REQUEST[self::FL_SHOW_STATUS_ASYNC] == self::FL_SHOW_STATUS_ASYNC_KEY;
    
        $status = SeAsync::getInstance()->async($lang_code, $fl_ignore_processing);
    
        if ($fl_show_status) {
            echo sprintf(__('Searchanise status sync: %s', 'woocommerce-searchanise'), $status);
        }
    
        wp_die();
    }
}

// Init Searchanise Async
add_action('plugins_loaded', array('SeAsync', 'init'));
