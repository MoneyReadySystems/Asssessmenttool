<?php
/**
 * Runs when the plugin is deleted through the WordPress admin.
 *
 * DELIBERATELY CONSERVATIVE. This removes settings and caches only. It does
 * NOT remove:
 *
 *   - the wp_fq_events table (quiz completions and card clicks)
 *   - fq_social_post entries, including human review decisions
 *
 * WordPress convention says an uninstall should clean up after itself, and
 * for most settings that is right. But someone deleting a plugin to reinstall
 * a fresh copy, or removing it to test something, would silently lose months
 * of analytics and every review decision a colleague has made. That is not a
 * recoverable mistake, and the cost of leaving the data is a table and some
 * hidden posts.
 *
 * To remove the data as well, do it deliberately before deleting the plugin:
 *
 *   DROP TABLE {prefix}_fq_events;
 *   -- and delete the Social posts from the admin, or:
 *   DELETE FROM {prefix}_posts WHERE post_type = 'fq_social_post';
 *   DELETE pm FROM {prefix}_postmeta pm
 *     LEFT JOIN {prefix}_posts p ON p.ID = pm.post_id WHERE p.ID IS NULL;
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

// Settings and run records.
foreach ( [ 'fq_db_version', 'fq_buffer_last_run', 'fq_classify_last_run' ] as $option ) {
    delete_option( $option );
}

// Caches: the library transient is keyed by a hash of the topic mapping, and
// answer caches by a hash of the answers, so both need a pattern match.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
      WHERE option_name LIKE '_transient_fq_content_library%'
         OR option_name LIKE '_transient_timeout_fq_content_library%'
         OR option_name LIKE '_transient_fq_ans_%'
         OR option_name LIKE '_transient_timeout_fq_ans_%'
         OR option_name LIKE '_transient_fq_rate_%'
         OR option_name LIKE '_transient_timeout_fq_rate_%'
         OR option_name = '_transient_fq_just_activated'
         OR option_name = '_transient_timeout_fq_just_activated'"
);

// Scheduled jobs, in case deactivation did not run.
foreach ( [ 'fq_buffer_sync_event', 'fq_classify_event' ] as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    while ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
        $timestamp = wp_next_scheduled( $hook );
    }
}
