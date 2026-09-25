<?php
/**
 * Plugin Name:       Money Ready Quiz
 * Plugin URI:        https://github.com/MoneyReadySystems/Asssessmenttool
 * Description:       A short quiz that recommends Learning Hub articles and approved social posts, matched to a visitor's interests, confidence and goals. Adds the [finance_quiz] shortcode.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Money Ready
 * Author URI:        https://moneyready.org
 * License:           GPL-2.0-or-later
 * Text Domain:       money-ready-quiz
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A PLUGIN AND NOT PART OF THE THEME
 *
 *  1. It survives a theme change or update. As a theme include, a single
 *     theme update could remove the whole tool.
 *  2. Activation and deactivation hooks work properly. As a theme include the
 *     table creation and cron scheduling had to be approximated on `init`,
 *     which meant doing work on every page load.
 *  3. It can be switched off without taking the site's theme down with it.
 *  4. Theme files are editable through Appearance → Theme File Editor;
 *     plugins can be locked down separately.
 *
 * Note for the record: moving to a plugin does NOT improve API key security.
 * The keys live in wp-config.php either way. See "Credentials" below.
 *
 * ---------------------------------------------------------------------------
 * CREDENTIALS — none are stored in this plugin
 *
 * Add these to wp-config.php, each on its own line, ABOVE the
 * "That's all, stop editing" comment and NOT inside a comment block:
 *
 *     define( 'FQ_API_KEY',      'sk-ant-...' );   // Anthropic
 *     define( 'FQ_BUFFER_TOKEN', '...'        );   // Buffer (see below)
 *     define( 'FQ_GA4_MEASUREMENT_ID', 'G-...' );  // optional
 *
 * The Buffer token should come from a dedicated limited team account, not an
 * owner's. Buffer's API is GraphQL: one endpoint that both reads and writes,
 * with no read-only scope, so the account is the main control available.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FQ_PLUGIN_VERSION', '1.0.0' );
define( 'FQ_PLUGIN_FILE',    __FILE__ );
define( 'FQ_PLUGIN_DIR',     plugin_dir_path( __FILE__ ) );
define( 'FQ_DATA_DIR',       FQ_PLUGIN_DIR . 'data/' );

/**
 * Load order matters: quiz.php defines the topic registry and cache helpers
 * that the others call.
 */
foreach ( [ 'quiz.php', 'social-posts.php', 'buffer-sync.php', 'classify.php' ] as $fq_module ) {
    $fq_path = FQ_PLUGIN_DIR . 'includes/' . $fq_module;
    if ( is_readable( $fq_path ) ) {
        require_once $fq_path;
    } else {
        error_log( sprintf( '[money-ready-quiz] Missing module: %s', $fq_path ) );
    }
}
unset( $fq_module, $fq_path );

// ============================================================
// ACTIVATION
// ============================================================

register_activation_hook( __FILE__, 'fq_activate' );
function fq_activate() {
    // Create or update the events table now, rather than checking on every
    // page load forever.
    if ( function_exists( 'fq_create_table' ) ) {
        fq_create_table();
        update_option( 'fq_db_version', FQ_DB_VERSION );
    }

    // Register the post type before flushing, so its (absent) rewrite rules
    // are accounted for.
    if ( function_exists( 'fq_register_social_post_type' ) ) {
        fq_register_social_post_type();
    }
    flush_rewrite_rules();

    // The scheduled jobs. Both functions no-op if already scheduled or if
    // the relevant credential is missing.
    if ( function_exists( 'fq_schedule_buffer_sync' ) )   { fq_schedule_buffer_sync(); }
    if ( function_exists( 'fq_schedule_classification' ) ) { fq_schedule_classification(); }

    // Surface a first-run notice so nobody wonders why there is no content.
    set_transient( 'fq_just_activated', 1, 60 );
}

// ============================================================
// DEACTIVATION
//
// Unschedule the cron events. Without this they stay in the schedule after
// the plugin is switched off, firing hooks that no longer exist — the most
// common way a deactivated plugin keeps making noise in the logs.
//
// Nothing is deleted here. Deactivation is often temporary, and losing the
// review queue or analytics because someone toggled the plugin off would be
// a nasty surprise. Removal is handled in uninstall.php.
// ============================================================

register_deactivation_hook( __FILE__, 'fq_deactivate' );
function fq_deactivate() {
    foreach ( [ 'fq_buffer_sync_event', 'fq_classify_event' ] as $hook ) {
        $timestamp = wp_next_scheduled( $hook );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
            $timestamp = wp_next_scheduled( $hook );
        }
    }
    flush_rewrite_rules();
}

// ============================================================
// HEALTH CHECK
//
// One place that answers "is this thing actually going to work?", shown on
// the analytics screen. Most of the failures on this project were silent —
// a missing key, a stale cron, a data file that had lost a placeholder —
// and each cost a session to find.
// ============================================================

function fq_health_report() {
    $checks = [];

    $checks[] = [
        'label' => 'Anthropic API key',
        'ok'    => defined( 'FQ_API_KEY' ) && FQ_API_KEY && FQ_API_KEY !== 'YOUR_API_KEY_HERE',
        'fix'   => "Add define( 'FQ_API_KEY', '...' ); to wp-config.php, on its own line and not inside a comment.",
    ];
    $checks[] = [
        'label' => 'Buffer token',
        'ok'    => defined( 'FQ_BUFFER_TOKEN' ) && FQ_BUFFER_TOKEN,
        'fix'   => "Add define( 'FQ_BUFFER_TOKEN', '...' ); to wp-config.php. Without it, social posts cannot sync.",
    ];
    $checks[] = [
        'label' => 'Topic registry (data/topics.json)',
        'ok'    => is_readable( FQ_DATA_DIR . 'topics.json' ) && count( fq_get_topics() ) > 0,
        'fix'   => 'The file is missing or invalid, so a built-in fallback list is being used. Check the error log.',
    ];
    $checks[] = [
        'label' => 'Content taxonomy',
        'ok'    => taxonomy_exists( FQ_TOPIC_TAXONOMY ),
        'fix'   => sprintf( 'The "%s" taxonomy does not exist on this site, so no articles can be matched.', FQ_TOPIC_TAXONOMY ),
    ];
    $checks[] = [
        'label' => 'Events table',
        'ok'    => (bool) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
                        'SHOW TABLES LIKE %s', $GLOBALS['wpdb']->prefix . FQ_DB_TABLE ) ),
        'fix'   => 'Deactivate and reactivate the plugin to create it.',
    ];
    $checks[] = [
        'label' => 'Weekly Buffer sync scheduled',
        'ok'    => (bool) wp_next_scheduled( 'fq_buffer_sync_event' ),
        'fix'   => 'Not scheduled. Usually means the Buffer token was added after activation — deactivate and reactivate.',
    ];
    $checks[] = [
        'label' => 'Weekly classification scheduled',
        'ok'    => (bool) wp_next_scheduled( 'fq_classify_event' ),
        'fix'   => 'Not scheduled. Usually means the API key was added after activation — deactivate and reactivate.',
    ];
    $checks[] = [
        'label' => 'WP-Cron enabled',
        'ok'    => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
        'fix'   => 'DISABLE_WP_CRON is set. The scheduled jobs will not run unless a real cron job calls wp-cron.php.',
    ];

    return $checks;
}

/** Health panel for the analytics screen. */
function fq_render_health_panel() {
    $checks = fq_health_report();
    $bad    = array_filter( $checks, fn( $c ) => ! $c['ok'] );

    echo '<div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.07);margin-bottom:20px">';
    printf(
        '<h2 style="font-size:1rem;margin:0 0 10px">Setup check %s</h2>',
        $bad
            ? '<span style="color:#B32D00;font-size:.85rem;font-weight:400">— ' . count( $bad ) . ' need attention</span>'
            : '<span style="color:#0C7A3E;font-size:.85rem;font-weight:400">— all good</span>'
    );

    echo '<ul style="margin:0;font-size:.86rem">';
    foreach ( $checks as $c ) {
        printf(
            '<li style="margin:0 0 6px;list-style:none"><span style="color:%s;font-weight:700">%s</span> %s%s</li>',
            $c['ok'] ? '#0C7A3E' : '#B32D00',
            $c['ok'] ? '✓' : '✕',
            esc_html( $c['label'] ),
            $c['ok'] ? '' : '<br><span style="color:#767676;margin-left:18px">' . esc_html( $c['fix'] ) . '</span>'
        );
    }
    echo '</ul></div>';
}

add_action( 'admin_notices', 'fq_activation_notice' );
function fq_activation_notice() {
    if ( ! get_transient( 'fq_just_activated' ) ) return;
    delete_transient( 'fq_just_activated' );
    printf(
        '<div class="notice notice-info is-dismissible"><p><strong>Money Ready Quiz activated.</strong> '
        . 'Add the <code>[finance_quiz]</code> shortcode to a page, then check '
        . '<a href="%s">Quiz Analytics</a> to confirm the API keys are set and run a first Buffer sync.</p></div>',
        esc_url( admin_url( 'admin.php?page=fq-analytics' ) )
    );
}

/** Settings-style links on the Plugins screen. */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'fq_plugin_action_links' );
function fq_plugin_action_links( $links ) {
    array_unshift( $links, sprintf(
        '<a href="%s">Analytics &amp; setup</a>',
        esc_url( admin_url( 'admin.php?page=fq-analytics' ) )
    ) );
    return $links;
}
