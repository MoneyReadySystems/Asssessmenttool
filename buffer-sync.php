<?php
/**
 * Money Ready – Buffer sync
 *
 * Pulls sent posts from Buffer once a day, groups them by content fingerprint
 * and writes them into the fq_social_post store. Classification is a separate
 * step: items arrive here with no status, and nothing without a status of
 * `classified` can ever be recommended.
 *
 * SECURITY — read this before changing anything in here.
 *
 * Buffer's personal access token has no read-only scope, and the API is
 * GraphQL: there is exactly one endpoint, it is a POST, and the same endpoint
 * that reads posts can also create and delete them. The original plan of
 * "only call one GET endpoint" is not available as a control.
 *
 * What protects us instead:
 *   1. The query is a constant (FQ_BUFFER_QUERY). It is never built, never
 *      concatenated, and never accepts a fragment from a caller.
 *   2. Everything variable travels as a typed GraphQL variable, so no value
 *      can alter the shape of the query.
 *   3. fq_buffer_request() is the only function in the codebase that talks to
 *      Buffer, and it accepts variables only — never a query.
 *
 * Do not add a $query parameter to fq_buffer_request(). If a second query is
 * ever needed, add a second constant and a second narrow function.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'FQ_BUFFER_ENDPOINT' ) )  define( 'FQ_BUFFER_ENDPOINT',  'https://api.buffer.com' );
if ( ! defined( 'FQ_BUFFER_ORG_ID' ) )    define( 'FQ_BUFFER_ORG_ID',    '65e74b698f9c10d39cc7392c' );
if ( ! defined( 'FQ_BUFFER_PAGE_SIZE' ) ) define( 'FQ_BUFFER_PAGE_SIZE', 50 );
// Safety ceiling so a bad cutoff can never walk the entire history.
if ( ! defined( 'FQ_BUFFER_MAX_PAGES' ) ) define( 'FQ_BUFFER_MAX_PAGES', 20 );
// How far back a routine sync looks. Generous, because content is sometimes
// cross-posted to another channel days after it first went out, and we want
// to pick up the extra link when it does.
if ( ! defined( 'FQ_BUFFER_SYNC_DAYS' ) ) define( 'FQ_BUFFER_SYNC_DAYS',  45 );

const FQ_BUFFER_LAST_RUN = 'fq_buffer_last_run';
const FQ_SYNC_HOOK       = 'fq_buffer_sync_event';

/**
 * The one query this integration sends. A constant, deliberately.
 * Only $org, $first and $after are variable, and all three are typed.
 */
const FQ_BUFFER_QUERY = <<<'GRAPHQL'
query FqSyncSentPosts($org: OrganizationId!, $first: Int!, $after: String) {
  posts(first: $first, after: $after, input: {
    organizationId: $org
    sort: [{ field: dueAt, direction: desc }]
    filter: { status: sent }
  }) {
    edges {
      node {
        id
        text
        externalLink
        channelService
        sentAt
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GRAPHQL;

/**
 * The only outbound call to Buffer. Takes variables, never a query.
 * Returns [ 'ok' => bool, 'data' => array|null, 'error' => string ].
 */
function fq_buffer_request( array $variables ) {
    if ( ! defined( 'FQ_BUFFER_TOKEN' ) || ! FQ_BUFFER_TOKEN ) {
        return [ 'ok' => false, 'data' => null, 'error' => 'FQ_BUFFER_TOKEN is not set in wp-config.php' ];
    }

    $res = wp_remote_post( FQ_BUFFER_ENDPOINT, [
        'timeout' => 45,
        'headers' => [
            'Authorization' => 'Bearer ' . FQ_BUFFER_TOKEN,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode( [
            'query'     => FQ_BUFFER_QUERY,
            'variables' => $variables,
        ] ),
    ] );

    if ( is_wp_error( $res ) ) {
        return [ 'ok' => false, 'data' => null, 'error' => 'network: ' . $res->get_error_message() ];
    }

    $code = (int) wp_remote_retrieve_response_code( $res );
    $body = json_decode( wp_remote_retrieve_body( $res ), true );

    if ( $code === 429 ) {
        return [ 'ok' => false, 'data' => null, 'error' => 'rate limited by Buffer (HTTP 429)' ];
    }
    if ( $code === 401 || $code === 403 ) {
        return [ 'ok' => false, 'data' => null, 'error' => "Buffer rejected the token (HTTP {$code}) — it may have been revoked or rotated" ];
    }
    if ( $code !== 200 ) {
        return [ 'ok' => false, 'data' => null, 'error' => "HTTP {$code}: " . substr( wp_remote_retrieve_body( $res ), 0, 200 ) ];
    }
    if ( ! empty( $body['errors'] ) ) {
        $msgs = array_map( fn( $e ) => $e['message'] ?? 'unknown', $body['errors'] );
        return [ 'ok' => false, 'data' => null, 'error' => 'GraphQL: ' . implode( '; ', $msgs ) ];
    }

    return [ 'ok' => true, 'data' => $body['data'] ?? [], 'error' => '' ];
}

/**
 * Is this post worth storing at all?
 *
 * Measured across 400 real posts: 72 had empty text, 22 were stories, and 11
 * had no permalink. Roughly a fifth of everything Buffer returns is unusable.
 */
function fq_buffer_post_is_usable( array $node ) {
    if ( trim( (string) ( $node['text'] ?? '' ) ) === '' ) {
        return false;   // nothing for the classifier to read
    }
    $link = (string) ( $node['externalLink'] ?? '' );
    if ( $link === '' ) {
        return false;   // nowhere to send a visitor
    }
    if ( stripos( $link, '/stories/' ) !== false ) {
        return false;   // stories expire after 24h and would become dead links
    }
    return true;
}

/**
 * Run the sync.
 *
 * @param bool $full Ignore the recent-window cutoff and walk back as far as
 *                   FQ_BUFFER_MAX_PAGES allows. Used for the first run.
 * @return array Summary, also stored for the admin screen.
 */
function fq_sync_buffer( $full = false ) {
    $started = microtime( true );
    $summary = [
        'ok' => false, 'error' => '', 'ran_at' => current_time( 'mysql' ),
        'pages' => 0, 'fetched' => 0, 'skipped' => 0,
        'items' => 0, 'created' => 0, 'updated' => 0, 'seconds' => 0,
    ];

    // Only look at recent history on a routine run; a first run has no
    // recorded cutoff and walks back to the page ceiling.
    $last   = get_option( FQ_BUFFER_LAST_RUN );
    $cutoff = ( $full || empty( $last['ok'] ) )
        ? null
        : gmdate( 'Y-m-d\TH:i:s\Z', time() - ( FQ_BUFFER_SYNC_DAYS * DAY_IN_SECONDS ) );

    $nodes = [];
    $after = null;

    for ( $page = 0; $page < FQ_BUFFER_MAX_PAGES; $page++ ) {
        $r = fq_buffer_request( [
            'org'   => FQ_BUFFER_ORG_ID,
            'first' => FQ_BUFFER_PAGE_SIZE,
            'after' => $after,
        ] );

        if ( ! $r['ok'] ) {
            // Keep whatever earlier pages gave us rather than throwing the
            // run away; a partial sync is better than none.
            $summary['error'] = $r['error'];
            error_log( '[finance-quiz] Buffer sync: ' . $r['error'] );
            break;
        }

        $summary['pages']++;
        $edges = $r['data']['posts']['edges'] ?? [];
        $reachedCutoff = false;

        foreach ( $edges as $edge ) {
            $node = $edge['node'] ?? [];
            $summary['fetched']++;

            if ( $cutoff !== null && ! empty( $node['sentAt'] ) && $node['sentAt'] < $cutoff ) {
                $reachedCutoff = true;   // sorted desc, so everything after is older
                continue;
            }
            if ( ! fq_buffer_post_is_usable( $node ) ) {
                $summary['skipped']++;
                continue;
            }
            $nodes[] = $node;
        }

        $info = $r['data']['posts']['pageInfo'] ?? [];
        if ( $reachedCutoff || empty( $info['hasNextPage'] ) || empty( $info['endCursor'] ) ) {
            break;
        }
        $after = $info['endCursor'];
    }

    // Group by fingerprint so one piece of content becomes one item carrying
    // every channel it ran on.
    $groups = [];
    foreach ( $nodes as $node ) {
        $fp = fq_social_fingerprint( $node['text'] );
        if ( $fp === '' ) { $summary['skipped']++; continue; }

        if ( ! isset( $groups[ $fp ] ) ) {
            $groups[ $fp ] = [ 'text' => $node['text'], 'channels' => [], 'buffer_ids' => [], 'sent_at' => null ];
        }
        $groups[ $fp ]['channels'][]   = [ 'service' => $node['channelService'] ?? '', 'url' => $node['externalLink'] ];
        $groups[ $fp ]['buffer_ids'][] = $node['id'] ?? '';

        // Keep the earliest send date — when the content first appeared.
        $sent = ! empty( $node['sentAt'] ) ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $node['sentAt'] ) ) ) : null;
        if ( $sent && ( $groups[ $fp ]['sent_at'] === null || $sent < $groups[ $fp ]['sent_at'] ) ) {
            $groups[ $fp ]['sent_at'] = $sent;
        }
        // Prefer the longest version of the copy — platform edits often
        // truncate, and the classifier should see the fullest text available.
        if ( mb_strlen( $node['text'] ) > mb_strlen( $groups[ $fp ]['text'] ) ) {
            $groups[ $fp ]['text'] = $node['text'];
        }
    }
    $summary['items'] = count( $groups );

    foreach ( $groups as $fp => $g ) {
        $existing = fq_find_social_post( $fp );
        $id = fq_upsert_social_post( [
            'text'       => $g['text'],
            'channels'   => $g['channels'],
            'buffer_ids' => array_filter( $g['buffer_ids'] ),
            'sent_at'    => $g['sent_at'],
            // No status. Classification is a separate step, and an item with
            // no status cannot be recommended.
        ] );
        if ( ! $id ) continue;
        $existing ? $summary['updated']++ : $summary['created']++;
    }

    if ( $summary['created'] || $summary['updated'] ) {
        delete_transient( fq_cache_key() );
    }

    $summary['ok']      = ( $summary['error'] === '' );
    $summary['seconds'] = round( microtime( true ) - $started, 1 );
    update_option( FQ_BUFFER_LAST_RUN, $summary, false );

    error_log( sprintf(
        '[finance-quiz] Buffer sync %s: %d fetched, %d skipped, %d items, %d new, %d updated, %ss%s',
        $summary['ok'] ? 'complete' : 'FAILED',
        $summary['fetched'], $summary['skipped'], $summary['items'],
        $summary['created'], $summary['updated'], $summary['seconds'],
        $summary['error'] ? ' — ' . $summary['error'] : ''
    ) );

    return $summary;
}

// ============================================================
// SCHEDULE
// ============================================================

add_action( FQ_SYNC_HOOK, 'fq_sync_buffer' );

add_action( 'init', 'fq_schedule_buffer_sync' );
function fq_schedule_buffer_sync() {
    if ( wp_next_scheduled( FQ_SYNC_HOOK ) ) return;
    if ( ! defined( 'FQ_BUFFER_TOKEN' ) || ! FQ_BUFFER_TOKEN ) return;   // nothing to sync with

    // First run at the next 03:00 site time — quiet, and well clear of the
    // hourly library cache so a sync is visible soon after it finishes.
    $next = strtotime( 'tomorrow 03:00', current_time( 'timestamp' ) );
    wp_schedule_event( $next - ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', FQ_SYNC_HOOK );
}

// ============================================================
// ADMIN — run it by hand, and see how the last run went
// ============================================================

add_action( 'admin_post_fq_sync_buffer', 'fq_handle_manual_sync' );
function fq_handle_manual_sync() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to run the Buffer sync.' );
    }
    check_admin_referer( 'fq_sync_buffer' );

    $full    = ! empty( $_GET['full'] );
    $summary = fq_sync_buffer( $full );

    wp_safe_redirect( add_query_arg(
        [ 'page' => 'fq-analytics', 'fq_synced' => $summary['ok'] ? 1 : 0 ],
        admin_url( 'admin.php' )
    ) );
    exit;
}

/** Button + last-run status, shown on the Quiz Analytics screen. */
function fq_render_sync_panel() {
    $last = get_option( FQ_BUFFER_LAST_RUN );
    $next = wp_next_scheduled( FQ_SYNC_HOOK );
    $configured = defined( 'FQ_BUFFER_TOKEN' ) && FQ_BUFFER_TOKEN;

    echo '<div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.07);margin-bottom:20px">';
    echo '<h2 style="font-size:1rem;margin:0 0 6px">Social posts from Buffer</h2>';

    if ( ! $configured ) {
        echo '<p style="color:#B32D00;font-size:.85rem;margin:0 0 12px">'
           . 'FQ_BUFFER_TOKEN is not set in wp-config.php, so the sync cannot run.</p>';
    }

    if ( ! empty( $last ) ) {
        printf(
            '<p style="color:%s;font-size:.85rem;margin:0 0 4px">Last run %s — %s</p>',
            empty( $last['ok'] ) ? '#B32D00' : '#0C7A3E',
            esc_html( $last['ran_at'] ?? '?' ),
            empty( $last['ok'] )
                ? 'failed: ' . esc_html( $last['error'] )
                : sprintf(
                    '%d posts fetched, %d skipped, %d unique items, %d new, %d updated (%ss)',
                    $last['fetched'], $last['skipped'], $last['items'],
                    $last['created'], $last['updated'], $last['seconds']
                  )
        );
    } else {
        echo '<p style="color:#595959;font-size:.85rem;margin:0 0 4px">Has not run yet.</p>';
    }

    if ( $next ) {
        printf(
            '<p style="color:#595959;font-size:.82rem;margin:0 0 14px">Next automatic run %s</p>',
            esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'j M Y, H:i' ) )
        );
    }

    $url  = wp_nonce_url( admin_url( 'admin-post.php?action=fq_sync_buffer' ), 'fq_sync_buffer' );
    $full = wp_nonce_url( admin_url( 'admin-post.php?action=fq_sync_buffer&full=1' ), 'fq_sync_buffer' );

    printf(
        '<a href="%s" class="button button-primary"%s>Sync now</a> '
        . '<a href="%s" class="button"%s>Full resync</a> '
        . '<span style="color:#767676;font-size:.8rem;margin-left:10px">'
        . '"Sync now" covers the last %d days. A full resync walks back further and takes longer.</span>',
        esc_url( $url ),   $configured ? '' : ' disabled aria-disabled="true"',
        esc_url( $full ),  $configured ? '' : ' disabled aria-disabled="true"',
        FQ_BUFFER_SYNC_DAYS
    );
    echo '</div>';
}

add_action( 'admin_notices', 'fq_sync_admin_notice' );
function fq_sync_admin_notice() {
    if ( ! isset( $_GET['fq_synced'] ) ) return;
    $last = get_option( FQ_BUFFER_LAST_RUN );
    printf(
        '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
        empty( $_GET['fq_synced'] ) ? 'error' : 'success',
        empty( $_GET['fq_synced'] )
            ? 'Buffer sync failed: ' . esc_html( $last['error'] ?? 'unknown error' )
            : sprintf(
                'Buffer sync complete: %d unique items, %d new, %d updated. New items need classifying before they can be recommended.',
                (int) ( $last['items'] ?? 0 ), (int) ( $last['created'] ?? 0 ), (int) ( $last['updated'] ?? 0 )
              )
    );
}
