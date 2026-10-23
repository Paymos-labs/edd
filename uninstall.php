<?php
/** Remove only Paymos EDD configuration; retain native purchase history. */
defined('WP_UNINSTALL_PLUGIN') || exit;

$paymos_edd_cleanup = static function () {
    global $wpdb;
    foreach (array('paymos_edd_credentials_v1', 'paymos_edd_connect_state_v1', 'paymos_edd_event_schema_version') as $option) {
        delete_option($option);
    }
    $settings = get_option('edd_settings', array());
    if (is_array($settings)) {
        foreach (array('paymos_title', 'paymos_mode', 'paymos_debug_logging') as $key) {
            unset($settings[$key]);
        }
        update_option('edd_settings', $settings);
    }
    wp_clear_scheduled_hook('paymos_edd_cleanup_events');
    // No order metadata or other integration's event table is touched.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall removes only this integration's prepared table identifier; native orders are retained.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'paymos_edd_webhook_events'));
};

if (is_multisite()) {
    $paymos_edd_offset = 0;
    do {
        $paymos_edd_blogs = get_sites(array('fields' => 'ids', 'number' => 100, 'offset' => $paymos_edd_offset));
        foreach ($paymos_edd_blogs as $paymos_edd_blog_id) {
            switch_to_blog((int) $paymos_edd_blog_id);
            try {
                $paymos_edd_cleanup();
            } finally {
                restore_current_blog();
            }
        }
        $paymos_edd_offset += count($paymos_edd_blogs);
    } while (count($paymos_edd_blogs) === 100);
} else {
    $paymos_edd_cleanup();
}
unset($paymos_edd_cleanup, $paymos_edd_offset, $paymos_edd_blogs, $paymos_edd_blog_id);
