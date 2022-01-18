<?php defined('ABSPATH') || exit; ?>

<?php
global $wp_version;

$product_filters = SeAsync::getInstance()->getAttributeFilters(ApiSe::getInstance()->getLocale());
$product_tags = SeAsync::getInstance()->getProductTags(ApiSe::getInstance()->getLocale());
$pages = $this->getAllPages(ApiSe::getInstance()->getLocale());
$categories = $this->getAllCategories(ApiSe::getInstance()->getLocale());

wc_enqueue_js(
    "jQuery('#sync_mode').on('change', function() {
        if (this.value == 'periodic') {
            jQuery('#resync_interval').closest('tr').show();
        } else {
            jQuery('#resync_interval').closest('tr').hide();
        }
    });"
);
?>

<div class="wrap woocommerce">
    <h1><?php echo esc_html(__('Searchanise Settings', 'woocommerce-searchanise')); ?></h1>

    <form name="searchanise-settings" method="post" action="">
        <table class="form-table">
            <tr>
                <th scope="row"><label for="search_input_selector"><?php _e('Search input jQuery selector', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-text">
                    <input type="text" name="se_settings[search_input_selector]" id="search_input_selector" value="<?= ApiSe::getInstance()->getSearchInputSelector(); ?>" />
                    <p class="description"><?php _e('Important: Edit only if your custom theme changes the default search input ID!', 'woocommerce-searchanise') ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="search_result_page"><?php _e('Search results page', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-text">
                    <input type="text" name="se_settings[search_result_page]" id="search_result_page" value="<?= ApiSe::getInstance()->getSearchResultsPage(); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="enabled_searchanise_search"><?php _e('Use Searchanise for Full-text search', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-select">
                    <select class="wc-enhanced-select" name="se_settings[enabled_searchanise_search]" id="enabled_searchanise_search">
                        <option value="Y" <?= ApiSe::getInstance()->getEnabledSearchaniseSearch() ? ' selected="selected"' : '' ?>><?= _e('Yes', 'woocommerce-searchanise'); ?></option>
                        <option value="N" <?= !ApiSe::getInstance()->getEnabledSearchaniseSearch() ? ' selected="selected"' : '' ?>><?= _e('No', 'woocommerce-searchanise'); ?></option>
                    </select>
                    <p class="description"><?php _e('Disable in case of invalid search operation. The instant search widget will <b>remain active</b>.', 'woocommerce-searchanise') ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="sync_mode"><?php _e('Sync catalog', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-select">
                    <select class="wc-enhanced-select" name="se_settings[sync_mode]" id="sync_mode">
                        <option value="<?= ApiSe::SYNC_MODE_REALTIME ?>" <?= ApiSe::getInstance()->isRealtimeSyncMode() ? ' selected="selected"' : '' ?>><?php _e('When catalog updates', 'woocommerce-searchanise') ?></option>
                        <option value="<?= ApiSe::SYNC_MODE_PERIODIC ?>" <?= ApiSe::getInstance()->isPeriodicSyncMode() ? ' selected="selected"' : '' ?>><?php _e('Periodically via cron', 'woocommerce-searchanise') ?></option>
                        <option value="<?= ApiSe::SYNC_MODE_MANUAL ?>" <?= ApiSe::getInstance()->isManualSyncMode() ? ' selected="selected"' : '' ?>><?php _e('Manually', 'woocommerce-searchanise') ?></option>
                    </select>
                    <p class="description"><?php _e('Select <strong>When catalog updates</strong> to keep track of catalog changes and index them automatically.<br>Select <strong>Periodically via cron</strong> to index catalog changes according to "Cron resync interval" setting.<br>Select <strong>Manually</strong> to index catalog changes manually by clicking <i>FORCE RE-INDEXATION</i> button in the Searchanise control panel(<i>Products → Searchanise</i>).', 'woocommerce-searchanise') ?></p>
                </td>
            </tr>
            <tr <?php echo !ApiSe::getInstance()->isPeriodicSyncMode() ? 'style="display: none"' : '' ?>>
                <th scope="row"><label for="resync_interval"><?php _e('Cron resync interval', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-select">
                    <select class="wc-enhanced-select" name="se_settings[resync_interval]" id="resync_interval">
                        <option value="hourly" <?= ApiSe::getInstance()->getResyncInterval() == 'hourly' ? ' selected="selected"' : '' ?>><?php _e('Hourly', 'woocommerce-searchanise') ?></option>
                        <option value="twicedaily" <?= ApiSe::getInstance()->getResyncInterval() == 'twicedaily' ? ' selected="selected"' : '' ?>><?php _e('Twice in day', 'woocommerce-searchanise') ?></option>
                        <option value="daily" <?= ApiSe::getInstance()->getResyncInterval() == 'daily' ? ' selected="selected"' : '' ?>><?php _e('Daily', 'woocommerce-searchanise') ?></option>
                    </select>
                    <p class="description"><?php _e('Valid only if "Sync catalog" is set to "Periodically via cron"!', 'woocommerce-searchanise') ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="use_direct_image_links"><?php _e('Use direct images links', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-select">
                    <select class="wc-enhanced-select" name="se_settings[use_direct_image_links]" id="use_direct_image_links">
                        <option value="Y" <?= ApiSe::getInstance()->useDirectImageLinks() ? ' selected="selected"' : '' ?>><?= _e('Yes', 'woocommerce-searchanise'); ?></option>
                        <option value="N" <?= !ApiSe::getInstance()->useDirectImageLinks() ? ' selected="selected"' : '' ?>><?= _e('No', 'woocommerce-searchanise'); ?></option>
                    </select>
                    <p class="description"><?php _e('Note: Catalog reindexation will start automatically when value changed.', 'woocommerce-searchanise') ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="import_block_posts"><?php _e('Import blog posts', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-select">
                    <select class="wc-enhanced-select" name="se_settings[import_block_posts]" id="import_block_posts">
                        <option value="Y" <?= ApiSe::getInstance()->importBlockPosts() ? ' selected="selected"' : '' ?>><?= _e('Yes', 'woocommerce-searchanise'); ?></option>
                        <option value="N" <?= !ApiSe::getInstance()->importBlockPosts() ? ' selected="selected"' : '' ?>><?= _e('No', 'woocommerce-searchanise'); ?></option>
                    </select>
                    <p class="description"><?php _e('Select "Yes" if you want Searchanise search by block posts as pages.</br>Note: Catalog reindexation will start automatically when value changed.', 'woocommerce-searchanise') ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="color_attribute"><?php _e('Color attribute', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-text">
                    <input type="hidden" name="se_settings[color_attribute]" value="" />
                    <select id="color_attribute" multiple="multiple" class="multiselect wc-enhanced-select" name="se_settings[color_attribute][]">
                        <?php foreach ($product_filters as $filter) { ?>
                            <option value="<?= $filter['name'] ?>" <?= in_array($filter['name'], (explode(',', ApiSe::getInstance()->getSystemSetting('color_attribute')))) ? ' selected="selected"' : '' ?>><?= $filter['label'] ?></option>
                        <?php } ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="size_attribute"><?php _e('Size attribute', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-text">
                    <input type="hidden" name="se_settings[size_attribute]" value="" />
                    <select id="size_attribute" multiple="multiple" class="multiselect wc-enhanced-select" name="se_settings[size_attribute][]">
                        <?php foreach ($product_filters as $filter) { ?>
                            <option value="<?= $filter['name'] ?>" <?= in_array($filter['name'], (explode(',', ApiSe::getInstance()->getSystemSetting('size_attribute')))) ? ' selected="selected"' : '' ?>><?= $filter['label'] ?></option>
                        <?php } ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="excluded_tags"><?php _e('Exclude products with these tags', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-text">
                    <input type="hidden" name="se_settings[excluded_tags]" value="" />
                    <select id="excluded_tags" multiple="multiple" class="multiselect wc-enhanced-select" name="se_settings[excluded_tags][]">
                        <?php foreach ($product_tags as $tag) { ?>
                            <option value="<?= $tag->slug ?>" <?= in_array($tag->slug, (explode(',', ApiSe::getInstance()->getSystemSetting('excluded_tags')))) ? ' selected="selected"' : '' ?>><?= $tag->name; ?></option>
                        <?php } ?>
                    </select>
                    <p class="description"><?php _e('Product with these tags will be excluded from indexation.<br />Note: Catalog reindexation will start automatically when value changed.', 'woocommerce-searchanise') ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="excluded_pages"><?php _e('Exclude these pages', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-text">
                    <input type="hidden" name="se_settings[excluded_pages]" value="" />
                    <select id="excluded_pages" multiple="multiple" class="multiselect wc-enhanced-select" name="se_settings[excluded_pages][]">
                        <?php foreach ($pages as $slug => $title) { ?>
                            <option value="<?= $slug ?>" <?= in_array($slug, (explode(',', ApiSe::getInstance()->getSystemSetting('excluded_pages')))) ? ' selected="selected"' : '' ?>><?= $title; ?></option>
                        <?php } ?>
                    </select>
                    <p class="description"><?php _e('These pages will be excluded from indexation and will not be displayed in search results.<br />Note: Catalog reindexation will start automatically when value changed.', 'woocommerce-searchanise') ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="excluded_categories"><?php _e('Exclude these categories', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-text">
                    <input type="hidden" name="se_settings[excluded_categories]" value="" />
                    <select id="excluded_categories" multiple="multiple" class="multiselect wc-enhanced-select" name="se_settings[excluded_categories][]">
                        <?php foreach ($categories as $slug => $title) { ?>
                            <option value="<?= $slug ?>" <?= in_array($slug, (explode(',', ApiSe::getInstance()->getSystemSetting('excluded_categories')))) ? ' selected="selected"' : '' ?>><?= $title; ?></option>
                        <?php } ?>
                    </select>
                    <p class="description"><?php _e('These categories will be excluded from indexation and will not be displayed in search results.<br />Note: Catalog reindexation will start automatically when value changed.', 'woocommerce-searchanise') ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="show_analytics_on_dashboard"><?php _e('Show Smart Search dashboard widget', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-select">
                    <select class="wc-enhanced-select" name="se_settings[show_analytics_on_dashboard]" id="show_analytics_on_dashboard">
                        <option value="Y" <?= ApiSe::getInstance()->isShowAnalyticsOnDashboard() ? ' selected="selected"' : '' ?>><?= _e('Yes', 'woocommerce-searchanise'); ?></option>
                        <option value="N" <?= !ApiSe::getInstance()->isShowAnalyticsOnDashboard() ? ' selected="selected"' : '' ?>><?= _e('No', 'woocommerce-searchanise'); ?></option>
                    </select>
                    <p class="description"><?php _e('Select "Yes" to display "Smart Search Analytics" widget on dashboard page', 'woocommerce-searchanise') ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="use_wp_jquery"><?php _e('Use WordPress integrated jQuery version', 'woocommerce-searchanise') ?></label></th>
                <td class="forminp forminp-select">
                    <select class="wc-enhanced-select" name="se_settings[use_wp_jquery]" id="use_wp_jquery" <?php if (version_compare($wp_version, ApiSe::MIN_WORDPRESS_VERSION_FOR_WP_JQUERY, "<")) : ?>disabled="disabled" <?php endif; ?>>
                        <option value="Y" <?= ApiSe::getInstance()->isUseWpJquery() ? ' selected="selected"' : '' ?>><?= _e('Yes', 'woocommerce-searchanise'); ?></option>
                        <option value="N" <?= !ApiSe::getInstance()->isUseWpJquery() ? ' selected="selected"' : '' ?>><?= _e('No', 'woocommerce-searchanise'); ?></option>
                    </select>
                    <p class="description">
                        <?php _e('Select "Yes" to use WordPress integrated jQuery version instead of Searchanise CDN version on the frontend of your website. It reduces the traffic and makes the website a little faster.', 'woocommerce-searchanise') ?><br />
                        <?php if (version_compare($wp_version, ApiSe::MIN_WORDPRESS_VERSION_FOR_WP_JQUERY, "<")) : ?>
                            <?php _e('NOTE: Available only for WordPress ' . ApiSe::MIN_WORDPRESS_VERSION_FOR_WP_JQUERY . ' version and later', 'woocommerce-searchanise') ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
