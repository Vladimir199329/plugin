<?php

defined('ABSPATH') || exit;

class SeCron
{
    const CRON_RESYNC_EVENT = 'se_cron_resync';
    const CRON_INDEX_EVENT  = 'se_index_resync';

    /**
     * Unregister cron jobs
     */
    public static function unregister()
    {
        wp_clear_scheduled_hook(self::CRON_INDEX_EVENT);
        wp_clear_scheduled_hook(self::CRON_RESYNC_EVENT);
    }

    /**
     * Adds custom intervals
     */
    public static function addIntervals($schedules)
    {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => 'Every minute'
        );

        return $schedules;
    }

    /**
     * Register cron jobs
     */
    public static function activate()
    {
        if (!wp_next_scheduled(self::CRON_INDEX_EVENT)) {
            wp_schedule_event(time(), ApiSe::getInstance()->getIndexInterval(), self::CRON_INDEX_EVENT);
        }

        if (!wp_next_scheduled(self::CRON_RESYNC_EVENT)) {
            wp_schedule_event(time(), ApiSe::getInstance()->getResyncInterval(), self::CRON_RESYNC_EVENT);
        }
    }

    /**
     * Indexer action
     */
    public static function indexer()
    {
        if (defined('DOING_CRON') && DOING_CRON && ApiSe::getInstance()->checkCronAsyncEnabled()) {
            $lang_code = ApiSe::getInstance()->checkStartAsync();

            if ($lang_code != false) {
                SeAsync::getInstance()->async($lang_code);
            }
        }
    }

    /**
     * Re-importer action
     */
    public static function reimporter()
    {
        if (defined('DOING_CRON') && DOING_CRON && ApiSe::getInstance()->isPeriodicSyncMode()) {
            ApiSe::getInstance()->queueImport(null, false);
        }
    }
}

add_filter('cron_schedules', array('SeCron', 'addIntervals'));
add_action('wp', array('SeCron', 'activate'));
add_action(SeCron::CRON_INDEX_EVENT, array('SeCron', 'indexer'));
add_action(SeCron::CRON_RESYNC_EVENT, array('SeCron', 'reimporter'));
