<?php
/**
 * Money Ready – social post classification
 *
 * Decides which synced social posts are learning content. Runs after the
 * nightly Buffer sync, over items that have no status yet.
 *
 * This step is load-bearing rather than a safeguard. Measured over 40 real
 * items, only about a quarter were learning content; the rest were
 * fundraising, awards, job adverts, event recaps and media appearances.
 * Without it a visitor asking about budgeting could be shown a job vacancy.
 *
 * A person's decision is never overwritten — see fq_upsert_social_post().
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'FQ_CLASSIFY_PROMPT_FILE' ) ) define( 'FQ_CLASSIFY_PROMPT_FILE', 'classify-prompt.md' );
// Items per API call. 40 measured at ~7.3k in / 2.6k out, comfortably inside
// the token budget while keeping the number of calls low.
if ( ! defined( 'FQ_CLASSIFY_BATCH' ) )       define( 'FQ_CLASSIFY_BATCH', 40 );
// Ceiling per run, so a large backfill cannot run away with time or spend.
if ( ! defined( 'FQ_CLASSIFY_MAX_BATCHES' ) ) define( 'FQ_CLASSIFY_MAX_BATCHES', 15 );
// A nightly batch, so judgement matters more than latency.
if ( ! defined( 'FQ_CLASSIFY_EFFORT' ) )      define( 'FQ_CLASSIFY_EFFORT', 'medium' );
// How much of each post the classifier sees. Raised from 400 after a real run
// where it twice said it could not decide because the text was cut off
// ("truncated so unclear whether actual tips follow"). A batch of 40 measured
// at ~7.3k input tokens at 400 chars, so this stays comfortably in budget.
if ( ! defined( 'FQ_CLASSIFY_TEXT_CHARS' ) )  define( 'FQ_CLASSIFY_TEXT_CHARS', 900 );

const FQ_CLASSIFY_LAST_RUN = 'fq_classify_last_run';
const FQ_CLASSIFY_HOOK     = 'fq_classify_event';

/** The prompt template, from classify-prompt.md, with its editor notes stripped. */
function fq_get_classify_prompt() {
    static $template = null;
    if ( $template !== null ) return $template;

    $path = FQ_DATA_DIR . FQ_CLASSIFY_PROMPT_FILE;
    $raw  = is_readable( $path ) ? file_get_contents( $path ) : false;

    if ( $raw !== false ) {
        $parts = preg_split( '/^---\s*PROMPT\s*---\s*$/m', $raw, 2 );
        $body  = trim( $parts[1] ?? '' );
        if ( $body !== '' && strpos( $body, '{{POSTS}}' ) !== false && strpos( $body, '{{TOPICS}}' ) !== false ) {
            return $template = $body;
        }
        error_log( sprintf(
            '[finance-quiz] %s is missing {{POSTS}} or {{TOPICS}} — using the built-in prompt. Edits to that file will have no effect until this is fixed.',
            $path
        ) );
    } else {
        error_log( sprintf( '[finance-quiz] Could not read %s — using the built-in prompt.', $path ) );
    }

    return $template = "You are triaging Money Ready's social media posts for a financial education\n"
        . "content recommender.\n\n"
        . "Classify each post as \"classified\" (teaches something useful about money),\n"
        . "\"excluded\" (about the organisation rather than money education), or\n"
        . "\"needs_review\" (genuinely borderline).\n\n"
        . "Assign topics ONLY from this list, never invent one:\n{{TOPICS}}\n\n"
        . "Give a short reason for each decision.\n\nPOSTS:\n{{POSTS}}";
}

/**
 * Tidy a generated title.
 *
 * The prompt asks for a complete phrase and the schema describes one, but a
 * title is visitor-facing and a stray ellipsis or trailing comma reads as a
 * bug, so it is worth the few lines to be certain.
 */
function fq_clean_title( $title ) {
    $t = trim( wp_strip_all_tags( (string) $title ) );
    $t = preg_replace( '/\s+/', ' ', $t );
    // Drop trailing ellipses (real or three dots) and dangling punctuation.
    $t = preg_replace( '/[\s\.,;:\x{2026}]+$/u', '', $t );
    // Keep a legitimate closing question or exclamation mark.
    if ( preg_match( '/[?!]$/u', trim( (string) $title ) ) ) { $t .= substr( trim( (string) $title ), -1 ); }
    return mb_substr( $t, 0, 120 );
}

/** Items awaiting a decision: stored, but with no status yet. */
function fq_get_unclassified_posts( $limit ) {
    return get_posts( [
        'post_type'      => FQ_SOCIAL_POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => FQ_META_STATUS, 'compare' => 'NOT EXISTS' ],
            [ 'key' => FQ_META_STATUS, 'value' => '' ],
        ],
    ] );
}

/**
 * Classify everything waiting.
 *
 * @return array Summary, also stored for the admin screen.
 */
function fq_classify_pending() {
    $started = microtime( true );
    $summary = [
        'ok' => true, 'error' => '', 'ran_at' => current_time( 'mysql' ),
        'batches' => 0, 'seen' => 0, 'classified' => 0, 'excluded' => 0,
        'needs_review' => 0, 'failed' => 0, 'seconds' => 0,
    ];

    if ( ! defined( 'FQ_API_KEY' ) || ! FQ_API_KEY || FQ_API_KEY === 'YOUR_API_KEY_HERE' ) {
        $summary['ok']    = false;
        $summary['error'] = 'FQ_API_KEY is not set in wp-config.php';
        update_option( FQ_CLASSIFY_LAST_RUN, $summary, false );
        return $summary;
    }

    $vocab = [];
    foreach ( fq_get_topics() as $t ) { $vocab[] = "- {$t['slug']}: {$t['description']}"; }
    $vocabText = implode( "\n", $vocab );

    for ( $batch = 0; $batch < FQ_CLASSIFY_MAX_BATCHES; $batch++ ) {
        $pending = fq_get_unclassified_posts( FQ_CLASSIFY_BATCH );
        if ( ! $pending ) break;

        $items = [];
        foreach ( $pending as $i => $p ) {
            $items[] = [
                'index' => $i,
                'text'  => mb_substr( wp_strip_all_tags( $p->post_content ), 0, FQ_CLASSIFY_TEXT_CHARS ),
            ];
        }

        $prompt = str_replace(
            [ '{{TOPICS}}', '{{POSTS}}' ],
            [ $vocabText, wp_json_encode( $items ) ],
            fq_get_classify_prompt()
        );

        $result = fq_classify_request( $prompt );
        $summary['batches']++;

        if ( ! $result['ok'] ) {
            // Stop rather than hammering the API; the remaining items keep
            // their unclassified state and are picked up next run.
            $summary['ok']      = false;
            $summary['error']   = $result['error'];
            $summary['failed'] += count( $pending );
            error_log( '[finance-quiz] Classification failed: ' . $result['error'] );
            break;
        }

        // Index every decision so an incomplete reply cannot silently shift
        // results onto the wrong posts.
        $byIndex = [];
        foreach ( $result['results'] as $r ) {
            if ( isset( $r['index'] ) ) { $byIndex[ (int) $r['index'] ] = $r; }
        }

        foreach ( $pending as $i => $p ) {
            $summary['seen']++;

            if ( ! isset( $byIndex[ $i ] ) ) {
                // No decision came back for this one. Park it for a human
                // rather than leaving it to be re-sent every night forever.
                fq_set_social_status( $p->ID, 'needs_review', 'claude' );
                update_post_meta( $p->ID, FQ_META_REASON, 'No decision returned by the classifier.' );
                $summary['needs_review']++;
                $summary['failed']++;
                continue;
            }

            $decision = $byIndex[ $i ];
            $status   = $decision['status'] ?? 'needs_review';
            if ( ! isset( fq_social_statuses()[ $status ] ) ) { $status = 'needs_review'; }

            fq_set_social_status( $p->ID, $status, 'claude' );
            update_post_meta( $p->ID, FQ_META_REASON, sanitize_text_field( $decision['reason'] ?? '' ) );
            fq_set_social_topics( $p->ID, (array) ( $decision['topics'] ?? [] ) );

            // Social copy has no title of its own. Without a written one the
            // card shows the first few words of the post and an ellipsis.
            $title = fq_clean_title( $decision['title'] ?? '' );
            if ( $title !== '' && $title !== $p->post_title ) {
                wp_update_post( [ 'ID' => $p->ID, 'post_title' => $title ] );
            }

            // An approved item with no usable topic can never be matched, so
            // it is not really approved. Send it for review instead.
            if ( $status === 'classified' ) {
                $terms = wp_get_post_terms( $p->ID, FQ_TOPIC_TAXONOMY, [ 'fields' => 'ids' ] );
                if ( is_wp_error( $terms ) || ! $terms ) {
                    fq_set_social_status( $p->ID, 'needs_review', 'claude' );
                    update_post_meta( $p->ID, FQ_META_REASON,
                        'Approved but no usable topic was assigned, so it could never be matched. ' . ( $decision['reason'] ?? '' ) );
                    $summary['needs_review']++;
                    continue;
                }
            }
            $summary[ $status ]++;
        }
    }

    if ( $summary['classified'] ) { delete_transient( fq_cache_key() ); }

    $summary['seconds'] = round( microtime( true ) - $started, 1 );
    update_option( FQ_CLASSIFY_LAST_RUN, $summary, false );

    error_log( sprintf(
        '[finance-quiz] Classification %s: %d seen, %d approved, %d excluded, %d for review, %d failed, %ss%s',
        $summary['ok'] ? 'complete' : 'INCOMPLETE',
        $summary['seen'], $summary['classified'], $summary['excluded'],
        $summary['needs_review'], $summary['failed'], $summary['seconds'],
        $summary['error'] ? ' — ' . $summary['error'] : ''
    ) );

    return $summary;
}

/** One classification call. Schema-constrained, so the reply cannot be malformed. */
function fq_classify_request( $prompt ) {
    $schema = [
        'type' => 'object',
        'properties' => [ 'results' => [ 'type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => [
                'index'  => [ 'type' => 'integer' ],
                'status' => [ 'type' => 'string', 'enum' => [ 'classified', 'excluded', 'needs_review' ] ],
                'topics' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                'title'  => [ 'type' => 'string', 'description' => 'Six to nine words, sentence case, a complete phrase, never ending in an ellipsis.' ],
                'reason' => [ 'type' => 'string' ],
            ],
            'required'             => [ 'index', 'status', 'topics', 'title', 'reason' ],
            'additionalProperties' => false,
        ] ] ],
        'required'             => [ 'results' ],
        'additionalProperties' => false,
    ];

    $res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 180,   // a batch of 40 measured at ~28s; generous headroom
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => FQ_API_KEY,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode( [
            'model'         => FQ_MODEL,
            'max_tokens'    => 16000,
            'output_config' => [
                'effort' => FQ_CLASSIFY_EFFORT,
                'format' => [ 'type' => 'json_schema', 'schema' => $schema ],
            ],
            'messages' => [ [ 'role' => 'user', 'content' => $prompt ] ],
        ] ),
    ] );

    if ( is_wp_error( $res ) ) {
        return [ 'ok' => false, 'results' => [], 'error' => 'network: ' . $res->get_error_message() ];
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    if ( $code !== 200 ) {
        return [ 'ok' => false, 'results' => [],
                 'error' => "HTTP {$code}: " . substr( wp_remote_retrieve_body( $res ), 0, 200 ) ];
    }

    $body = json_decode( wp_remote_retrieve_body( $res ), true );
    if ( ( $body['stop_reason'] ?? '' ) === 'max_tokens' ) {
        return [ 'ok' => false, 'results' => [],
                 'error' => 'response hit max_tokens — lower FQ_CLASSIFY_BATCH' ];
    }

    // Thinking is on by default, so content[0] may not be the text block.
    $text = '';
    foreach ( (array) ( $body['content'] ?? [] ) as $b ) {
        if ( ( $b['type'] ?? '' ) === 'text' ) { $text = (string) ( $b['text'] ?? '' ); break; }
    }
    $parsed = json_decode( $text, true );
    if ( ! is_array( $parsed ) || ! isset( $parsed['results'] ) ) {
        return [ 'ok' => false, 'results' => [], 'error' => 'could not read results from the response' ];
    }

    return [ 'ok' => true, 'results' => $parsed['results'], 'error' => '' ];
}

// ============================================================
// SCHEDULE — runs shortly after the sync
// ============================================================

add_action( FQ_CLASSIFY_HOOK, 'fq_classify_pending' );

add_action( 'init', 'fq_schedule_classification' );
function fq_schedule_classification() {
    if ( wp_next_scheduled( FQ_CLASSIFY_HOOK ) ) return;
    if ( ! defined( 'FQ_API_KEY' ) || ! FQ_API_KEY || FQ_API_KEY === 'YOUR_API_KEY_HERE' ) return;

    // Weekly, matching the sync, an hour later so there is something to
    // classify and the two jobs never overlap.
    $next = strtotime( 'next monday 04:00', current_time( 'timestamp' ) );
    wp_schedule_event( $next - ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'weekly', FQ_CLASSIFY_HOOK );
}

// ============================================================
// ADMIN
// ============================================================

add_action( 'admin_post_fq_classify', 'fq_handle_manual_classify' );
function fq_handle_manual_classify() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to run classification.' );
    }
    check_admin_referer( 'fq_classify' );

    $summary = fq_classify_pending();
    wp_safe_redirect( add_query_arg(
        [ 'page' => 'fq-analytics', 'fq_classified' => $summary['ok'] ? 1 : 0 ],
        admin_url( 'admin.php' )
    ) );
    exit;
}

/** Panel for the Quiz Analytics screen. */
function fq_render_classify_panel() {
    $last    = get_option( FQ_CLASSIFY_LAST_RUN );
    $pending = count( fq_get_unclassified_posts( 500 ) );
    $review  = (int) ( new WP_Query( [
        'post_type'      => FQ_SOCIAL_POST_TYPE,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => FQ_META_STATUS, 'value' => 'needs_review' ] ],
    ] ) )->found_posts;

    echo '<div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.07);margin-bottom:20px">';
    echo '<h2 style="font-size:1rem;margin:0 0 6px">Classifying social posts</h2>';
    echo '<p style="color:#595959;font-size:.85rem;margin:0 0 10px">Only approved posts are ever recommended. Nothing is approved without either Claude\'s confidence or a person\'s sign-off.</p>';

    printf(
        '<p style="font-size:.88rem;margin:0 0 4px"><strong>%d</strong> waiting to be classified · <strong>%d</strong> waiting for a person</p>',
        $pending, $review
    );

    if ( ! empty( $last ) ) {
        printf(
            '<p style="color:%s;font-size:.85rem;margin:0 0 12px">Last run %s — %s</p>',
            empty( $last['ok'] ) ? '#B32D00' : '#0C7A3E',
            esc_html( $last['ran_at'] ?? '?' ),
            empty( $last['ok'] )
                ? 'stopped early: ' . esc_html( $last['error'] )
                : sprintf(
                    '%d seen, %d approved, %d excluded, %d sent for review (%ss)',
                    $last['seen'], $last['classified'], $last['excluded'],
                    $last['needs_review'], $last['seconds']
                  )
        );
    }

    if ( $review ) {
        printf(
            '<p style="margin:0 0 12px"><a href="%s">Review the %d post(s) waiting →</a></p>',
            esc_url( admin_url( 'edit.php?post_type=' . FQ_SOCIAL_POST_TYPE . '&fq_status=needs_review' ) ),
            $review
        );
    }

    $url = wp_nonce_url( admin_url( 'admin-post.php?action=fq_classify' ), 'fq_classify' );
    printf(
        '<a href="%s" class="button button-primary"%s>Classify now</a>'
        . '<span style="color:#767676;font-size:.8rem;margin-left:10px">Up to %d items per run.</span>',
        esc_url( $url ),
        $pending ? '' : ' disabled aria-disabled="true"',
        FQ_CLASSIFY_BATCH * FQ_CLASSIFY_MAX_BATCHES
    );
    echo '</div>';
}

add_action( 'admin_notices', 'fq_classify_admin_notice' );
function fq_classify_admin_notice() {
    if ( ! isset( $_GET['fq_classified'] ) ) return;
    $last = get_option( FQ_CLASSIFY_LAST_RUN );
    printf(
        '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
        empty( $_GET['fq_classified'] ) ? 'error' : 'success',
        empty( $_GET['fq_classified'] )
            ? 'Classification stopped early: ' . esc_html( $last['error'] ?? 'unknown error' )
            : sprintf(
                'Classified %d posts: %d approved, %d excluded, %d need a person to look.',
                (int) ( $last['seen'] ?? 0 ), (int) ( $last['classified'] ?? 0 ),
                (int) ( $last['excluded'] ?? 0 ), (int) ( $last['needs_review'] ?? 0 )
              )
    );
}
