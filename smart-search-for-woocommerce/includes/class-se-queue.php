<?php

defined('ABSPATH') || exit;

class SeQueue
{
    const NO_DATA               = 'N';
    const PHRASE                = 'phrase';

    // Queue actions
    const UPDATE_PAGES          = 'update_pages';
    const UPDATE_PRODUCTS       = 'update_products';
    const UPDATE_ATTRIBUTES     = 'update_attributes';
    const UPDATE_CATEGORIES     = 'update_categories';

    const DELETE_PAGES          = 'delete_pages';
    const DELETE_PAGES_ALL      = 'delete_pages_all';
    const DELETE_PRODUCTS       = 'delete_products';
    const DELETE_PRODUCTS_ALL   = 'delete_products_all';
    const DELETE_FACETS         = 'delete_facets';
    const DELETE_FACETS_ALL     = 'delete_facets_all';
    const DELETE_ATTRIBUTES     = 'delete_attributes';
    const DELETE_ATTRIBUTES_ALL = 'delete_attributes_all';
    const DELETE_CATEGORIES     = 'delete_categories';
    const DELETE_CATEGORIES_ALL = 'delete_categories_all';

    const PREPARE_FULL_IMPORT   = 'prepare_full_import';
    const START_FULL_IMPORT     = 'start_full_import';
    const GET_INFO              = 'update_info';
    const END_FULL_IMPORT       = 'end_full_import';

    public static $mainActionTypes = array(
        self::PREPARE_FULL_IMPORT,
        self::START_FULL_IMPORT,
        self::END_FULL_IMPORT,
    );

    public static $actionTypes = array(
        self::UPDATE_PAGES,
        self::UPDATE_PRODUCTS,
        self::UPDATE_CATEGORIES,
        self::UPDATE_ATTRIBUTES,

        self::DELETE_PAGES,
        self::DELETE_PAGES_ALL,
        self::DELETE_ATTRIBUTES,
        self::DELETE_ATTRIBUTES_ALL,
        self::DELETE_CATEGORIES,
        self::DELETE_CATEGORIES_ALL,
        self::DELETE_FACETS,
        self::DELETE_FACETS_ALL,
        self::DELETE_PRODUCTS,
        self::DELETE_PRODUCTS_ALL,

        self::PREPARE_FULL_IMPORT,
        self::START_FULL_IMPORT,
        self::END_FULL_IMPORT,
    );

    // Queue statues
    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';

    public static $statusTypes = array(
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
    );

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
     * Checks if update action
     * 
     * @param string $action
     * @return boolean
     */
    public static function isUpdateAction($action)
    {
        return in_array($action, array(
            self::UPDATE_ATTRIBUTES,
            self::UPDATE_CATEGORIES,
            self::UPDATE_PAGES,
            self::UPDATE_PRODUCTS,
        ));
    }

    /**
     * Checks if delete action
     * 
     * @param string $action
     * @return boolean
     */
    public static function isDeleteAction($action)
    {
        return in_array($action, array(
            self::DELETE_ATTRIBUTES,
            self::DELETE_CATEGORIES,
            self::DELETE_FACETS,
            self::DELETE_PAGES,
            self::DELETE_PRODUCTS,
        ));
    }

    /**
     * Checks if delete all action
     * 
     * @param string $action
     * @return boolean
     */
    public static function isDeleteAllAction($action)
    {
        return in_array($action, array(
            self::DELETE_ATTRIBUTES_ALL,
            self::DELETE_CATEGORIES_ALL,
            self::DELETE_FACETS_ALL,
            self::DELETE_PAGES_ALL,
            self::DELETE_PRODUCTS_ALL,

        ));
    }

    /**
     * Return Se Api type by action
     * 
     * @param string $action
     * @return string
     */
    public static function getAPITypeByAction($action)
    {
        switch($action) {
            case self::DELETE_PRODUCTS:
            case self::DELETE_PRODUCTS_ALL:
                return 'items';
                
            case self::DELETE_CATEGORIES:
            case self::DELETE_CATEGORIES_ALL:
                return 'categories';

            case self::DELETE_PAGES:
            case self::DELETE_PAGES_ALL:
                return 'pages';

            case self::DELETE_FACETS:
            case self::DELETE_FACETS_ALL:
                return 'facets';

            default:
                return '';
        }
    }

    /**
     * Checks if queue already running
     */
    public static function isQueueRunning($q)
    {
        return !empty($q)
            && $q->status == SeQueue::STATUS_PROCESSING
            // NOTE: $q['started'] can be in future
            && $q->started + ApiSe::getInstance()->getMaxProcessingTime() > time();
    }

    /**
     * Checks if queue has errors
     * 
     * @return boolean
     */
    public static function isQueueHasError($q)
    {
        return !empty($q) && $q->attempts >= ApiSe::getInstance()->getMaxErrorCount();
    }

    /**
     * Get total items in Se Queue
     */
    public function getTotalItems()
    {
        return $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wc_se_queue");
    }

    /**
     * Clear queue for full import
     * 
     * @param string $lang_code
     */
    public function prepareFullImport($lang_code)
    {
        if (empty($lang_code)) {
            return false;
        }

        $lang_code = ApiSe::getInstance()->getLocaleSettings($lang_code);

        return $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wc_se_queue WHERE lang_code = '{$lang_code}' AND action <> 'prepare_full_import'");
    }

    /**
     * Clear all actions in queue
     * 
     * @param array $lang_code
     */
    public function clearActions($lang_code = null)
    {
        if (empty($lang_code)) {
            $this->wpdb->query("TRUNCATE {$this->wpdb->prefix}wc_se_queue");
        } else {
            $lang_code = ApiSe::getInstance()->getLocaleSettings($lang_code);
            $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wc_se_queue WHERE lang_code = '{$lang_code}'");
        }

        return $this;
    }

    /**
     * Adds action to queue
     * 
     * @param string $action
     * @param mixed $data
     * @param string $lang_code
     * 
     * @return boolean
     */
    public function addAction($action, $data = null, $lang_code = null)
    {
        if (
            !in_array($action, self::$actionTypes)
            || !ApiSe::getInstance()->checkParentPrivateKey()
            || (!ApiSe::getInstance()->isRealtimeSyncMode() && !in_array($action, self::$mainActionTypes))
        ) {
            return false;
        }

        $data = serialize($data);
        $data = array($data);

        $engines = ApiSe::getInstance()->getEngines($lang_code);

        if ($action == self::PREPARE_FULL_IMPORT && !empty($lang_code)) {
            $this->clearActions($lang_code);
        }

        foreach ($data as $d) {
            foreach ($engines as $engine) {
                if (ApiSe::getInstance()->getModuleStatus() != 'Y' && !in_array($action, self::$mainActionTypes)) {
                    continue;
                }

                $lang_code = ApiSe::getInstance()->getLocaleSettings($engine['lang_code']);

                if ($action != self::PHRASE) {
                    // Remove duplicated actions
                    $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wc_se_queue WHERE action = '{$action}' AND lang_code = '{$lang_code}' AND status = 'pending' AND data = '{$d}'");
                }

                $this->insertData(array(
                    'data'      => $d,
                    'action'    => $action,
                    'lang_code' => $engine['lang_code'],
                ));
            }
        }

        return true;
    }

    /**
     * Insert direct data to queue
     * 
     * @param array $data
     */
    public function insertData($data)
    {
        if (isset($data['lang_code'])) {
            $data['lang_code'] = ApiSe::getInstance()->getLocaleSettings($data['lang_code']);
        }

        return $this->wpdb->insert("{$this->wpdb->prefix}wc_se_queue", $data);
    }

    /**
     * Returns current or next queue
     * 
     * @param int $queue_id
     * @return array
     */
    public function getNextQueue($queue_id = null, $lang_code = null)
    {
        $condition = '';

        if (!empty($queue_id)) {
            $condition .= " AND queue_id > '{$queue_id}'";
        }

        if (!empty($lang_code)) {
            $lang_code = ApiSe::getInstance()->getLocaleSettings($lang_code);
            $condition .= " AND lang_code = '{$lang_code}'";
        }

        $queue = $this->wpdb->get_row("SELECT * FROM {$this->wpdb->prefix}wc_se_queue
            WHERE 1 {$condition}
            ORDER BY priority DESC, queue_id ASC
            LIMIT 1
        ");

        if (!empty($queue)) {
            $queue->lang_code = ApiSe::getInstance()->getLocale($queue->lang_code);
        }

        return !empty($queue) ? $queue : array();
    }

    /**
     * Delete queue row
     * 
     * @param int $queue_id
     */
    public function deleteQueueById($queue_id)
    {
        return $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wc_se_queue WHERE queue_id = '{$queue_id}'");
    }

    /**
     * Set queue status
     * 
     * @param int $queue_id
     * @param string $status
     */
    public function setQueueStatusProcessing($queue_id)
    {
        $sql = $this->wpdb->prepare(
            "UPDATE {$this->wpdb->prefix}wc_se_queue SET status = %s, started = %d, attempts = attempts + 1 WHERE queue_id = %d",
            array(self::STATUS_PROCESSING, time(),  $queue_id)
        );
        return $this->wpdb->query($sql);
    }

    /**
     * Set error in queue record
     * 
     * @param int $queue_id
     * @param int $next_try_time
     * @param string $error
     */
    public function setQueueErrorById($queue_id, $next_try_time, $error = '')
    {
        $sql = $this->wpdb->prepare("UPDATE {$this->wpdb->prefix}wc_se_queue SET error = %s WHERE queue_id = %d", array($error, $queue_id));
        return $this->wpdb->query($sql);
    }

    /**
     * Adds update products to queue to queue
     * 
     * @param array $product_ids
     */
    public function addActionUpdateProducts($product_ids)
    {
        if (empty($product_ids)) {
            return false;
        }

        $chunks = array_chunk((array)$product_ids, SE_PRODUCTS_PER_PASS);

        foreach ($chunks as $p_ids) {
            $this->addAction(self::UPDATE_PRODUCTS, $p_ids);
        }

        return true;
    }

    /**
     * Adds delete product action to quque
     * 
     * @param int $product_ids
     */
    public function addActionDeleteProducts($product_ids)
    {
        if (!empty($product_ids)) {
            $this->addAction(self::DELETE_PRODUCTS, (array)$product_ids);
        }

        return true;
    }

    /**
     * Adds update page action to queue
     * 
     * @param array $page_ids
     */
    public function addActionUpdatePages($page_ids)
    {
        if (empty($page_ids)) {
            return false;
        }

        $chunks = array_chunk((array)$page_ids, SE_PAGES_PER_PASS);

        foreach ($chunks as $p_ids) {
            $this->addAction(self::UPDATE_PAGES, $p_ids);
        }

        return true;
    }

    /**
     * Adds delete page action to quque
     * 
     * @param int $page_ids
     */
    public function addActionDeletePages($page_ids)
    {
        if (!empty($page_ids)) {
            $this->addAction(self::DELETE_PAGES, (array)$page_ids);
        }

        return true;
    }

    /**
     * Adds update category action to queue
     * 
     * @param array $category_ids
     */
    public function addActionUpdateCategory($category_ids)
    {
        if (empty($category_ids)) {
            return false;
        }

        $chunks = array_chunk((array)$category_ids, SE_CATEGORIES_PER_PASS);
        foreach ($chunks as $p_ids) {
            $this->addAction(self::UPDATE_CATEGORIES, $p_ids);
        }

        return true;
    }

    /**
     * Adds delete category action to quque
     * 
     * @param int $category_ids
     */
    public function addActionDeleteCategories($category_ids)
    {
        if (!empty($category_ids)) {
            $this->addAction(self::DELETE_CATEGORIES, (array)$category_ids);
        }
    }

    /**
     * Adds update attribute action to queue
     */
    public function addActionUpdateAttributes()
    {
        $this->addAction(self::UPDATE_ATTRIBUTES);
    }

    /**
     * Adds delete facet action to queue
     * 
     * @param string $names
     */
    public function addActionDeleteFacets($names)
    {
        if (!empty($names)) {
            $this->addAction(self::DELETE_FACETS, (array)$names);
        }
    }

    /**
     * Check if queue is OK
     * 
     * @return boolean
     */
    public function getQueueStatus()
    {
        $q = $this->getNextQueue();

        if (empty($q)) {
            return true;
        }

        if (self::isQueueHasError($q)) {
            // Maximum attemps reached
            $status = false;
        } elseif ($q->started > 0 && $q->started + HOUR_IN_SECONDS < time()) {
            // Queue item processed more than one hour
            $status = false;
        } else {
            $status = true;
        }

        return $status;
    }
}
