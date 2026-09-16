<?php
/**
 * Money Ready – Financial Education Content Recommender
 *
 * Features:
 *  - WCAG 2.1 AA compliant
 *  - Dynamic content library pulled from WordPress (auto-updates on publish)
 *  - Manual social media posts merged in
 *  - Library transient caching (1 hour)
 *  - Answer combination caching (24 hours)
 *  - Topic pre-filtering before the AI call
 *  - Truncated descriptions in prompt (~10 words) to reduce token usage
 *  - Server-side Anthropic API proxy
 *  - Hardcoded fallback recommendations
 *  - Bot protection: rate limiting, honeypot, timing check
 *  - Animated loading messages
 *  - Prefetch recommendation links on results display
 *  - GA4 event tracking (quiz completions + card clicks)
 *  - Async WordPress database logging (quiz answers + card clicks)
 *  - Admin dashboard with date filtering + CSV export
 */
 
// ============================================================
// ⚙️  CONFIGURATION
//
// Every constant is guarded with `defined()` so that wp-config.php — which
// loads before themes and plugins — can set any of them without triggering a
// "constant already defined" notice. Credentials belong in wp-config.php,
// which is git-ignored. The values below are placeholders and defaults only.
// ============================================================
if ( ! defined( 'FQ_API_KEY' ) )            define( 'FQ_API_KEY',           'YOUR_API_KEY_HERE' );
if ( ! defined( 'FQ_MODEL' ) )              define( 'FQ_MODEL',             'claude-opus-5' );
if ( ! defined( 'FQ_POST_TYPE' ) )          define( 'FQ_POST_TYPE',         'post' );
if ( ! defined( 'FQ_TOPIC_TAXONOMY' ) )     define( 'FQ_TOPIC_TAXONOMY',    'library-topic' );
if ( ! defined( 'FQ_TOPICS_FILE' ) )        define( 'FQ_TOPICS_FILE',       'topics.json' );
if ( ! defined( 'FQ_CACHE_KEY' ) )          define( 'FQ_CACHE_KEY',         'fq_content_library' );
if ( ! defined( 'FQ_CACHE_DURATION' ) )     define( 'FQ_CACHE_DURATION',    HOUR_IN_SECONDS );
if ( ! defined( 'FQ_ANSWER_CACHE_TTL' ) )   define( 'FQ_ANSWER_CACHE_TTL',  DAY_IN_SECONDS );
if ( ! defined( 'FQ_RATE_LIMIT_MAX' ) )     define( 'FQ_RATE_LIMIT_MAX',    10 );
if ( ! defined( 'FQ_RATE_LIMIT_WINDOW' ) )  define( 'FQ_RATE_LIMIT_WINDOW', 10 * MINUTE_IN_SECONDS );
if ( ! defined( 'FQ_MIN_TIME' ) )           define( 'FQ_MIN_TIME',          8 );
if ( ! defined( 'FQ_TIME_SECRET' ) )        define( 'FQ_TIME_SECRET',       AUTH_KEY );
if ( ! defined( 'FQ_DESC_WORDS' ) )         define( 'FQ_DESC_WORDS',        10 );
if ( ! defined( 'FQ_DB_TABLE' ) )           define( 'FQ_DB_TABLE',          'fq_events' ); // without WP prefix — added automatically
if ( ! defined( 'FQ_DB_VERSION' ) )         define( 'FQ_DB_VERSION',        '1.0' ); // bump when the fq_events schema changes
if ( ! defined( 'FQ_GA4_MEASUREMENT_ID' ) ) define( 'FQ_GA4_MEASUREMENT_ID','G-XXXXXXXXXX' ); // ⚠️ Replace with your GA4 Measurement ID

// ============================================================
// TOPIC REGISTRY — single source of truth
//
// Reads topics.json (sitting alongside this file) and serves three consumers:
// the quiz checkboxes, the content library filter, and — in future — the
// Claude classification prompt for synced social posts.
//
// Add a topic by editing topics.json. Nothing here needs changing.
// ============================================================
function fq_get_topics() {
    static $topics = null;
    if ( $topics !== null ) return $topics;

    $path = __DIR__ . '/' . FQ_TOPICS_FILE;
    $raw  = is_readable( $path ) ? file_get_contents( $path ) : false;
    $data = ( $raw !== false ) ? json_decode( $raw, true ) : null;

    if ( ! is_array( $data ) || empty( $data['topics'] ) || ! is_array( $data['topics'] ) ) {
        error_log( sprintf(
            '[finance-quiz] Topic registry unreadable or invalid at %s — falling back to the built-in list. The quiz still works but topics.json is being ignored.',
            $path
        ) );
        return $topics = fq_get_fallback_topics();
    }

    $topics = [];
    foreach ( $data['topics'] as $t ) {
        if ( empty( $t['slug'] ) || empty( $t['label'] ) ) continue;
        // "planned" topics are declared ahead of their content and stay hidden.
        if ( ( $t['status'] ?? 'live' ) !== 'live' ) continue;

        $topics[] = [
            'slug'           => (string) $t['slug'],
            'label'          => (string) $t['label'],
            'emoji'          => (string) ( $t['emoji'] ?? '' ),
            'description'    => (string) ( $t['description'] ?? '' ),
            'taxonomy_terms' => array_values( array_filter( array_map(
                'strval', (array) ( $t['taxonomy_terms'] ?? [] )
            ) ) ),
        ];
    }

    if ( empty( $topics ) ) {
        error_log( '[finance-quiz] Topic registry contained no live topics — falling back to the built-in list.' );
        $topics = fq_get_fallback_topics();
    }
    return $topics;
}

/**
 * Safety net for a missing or malformed topics.json. Deliberately mirrors the
 * seven topics the original prototype could actually map, so a broken file
 * degrades to previous behaviour rather than an empty quiz. Not a substitute
 * for the file — fix the file.
 */
function fq_get_fallback_topics() {
    $fallback = [
        ['banking',   'Banking & financial products', '🏦', 'banking'],
        ['borrowing', 'Borrowing & debt',             '💳', 'borrowing'],
        ['budgeting', 'Budgeting',                    '💰', 'budgeting'],
        ['income',    'Income & side hustles',        '🚀', 'earning'],
        ['saving',    'Saving & investing',           '📈', 'saving'],
        ['spending',  'Spending smartly',             '🛍️', 'spending'],
        ['scams',     'Staying safe from scams',      '🔐', 'staying-safe'],
    ];
    return array_map( function( $t ) {
        return [
            'slug'           => $t[0],
            'label'          => $t[1],
            'emoji'          => $t[2],
            'description'    => '',
            'taxonomy_terms' => [ $t[3] ],
        ];
    }, $fallback );
}

/** WordPress taxonomy slug => quiz topic slug. Replaces the old $topic_map. */
function fq_get_taxonomy_map() {
    $map = [];
    foreach ( fq_get_topics() as $topic ) {
        foreach ( $topic['taxonomy_terms'] as $term ) {
            $map[ $term ] = $topic['slug'];
        }
    }
    return $map;
}

/**
 * Library cache key, fingerprinted with the current topic mapping so that
 * editing topics.json takes effect immediately instead of waiting out the
 * hourly transient. Both the reader and the save_post invalidator use this.
 */
function fq_cache_key() {
    return FQ_CACHE_KEY . '_' . substr( md5( wp_json_encode( fq_get_taxonomy_map() ) ), 0, 8 );
}

// ============================================================
// DATABASE — create table on plugin activation / theme setup
// ============================================================
function fq_create_table() {
    global $wpdb;
    $table   = $wpdb->prefix . FQ_DB_TABLE;
    $charset = $wpdb->get_charset_collate();
 
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type     VARCHAR(20)  NOT NULL,          -- 'quiz_complete' or 'card_click'
        event_time     DATETIME     NOT NULL,
        topics         VARCHAR(255) DEFAULT NULL,       -- comma-separated topic values
        confidence     VARCHAR(20)  DEFAULT NULL,
        goal           VARCHAR(30)  DEFAULT NULL,
        format_pref    VARCHAR(20)  DEFAULT NULL,
        result_type    VARCHAR(10)  DEFAULT NULL,       -- 'ai', 'cached', 'fallback'
        article_title  VARCHAR(255) DEFAULT NULL,
        article_url    VARCHAR(512) DEFAULT NULL,
        article_topic  VARCHAR(50)  DEFAULT NULL,
        article_format VARCHAR(20)  DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_event_time  (event_time),
        KEY idx_event_type  (event_type),
        KEY idx_article_url (article_url(191))
    ) {$charset};";
 
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * Runs fq_create_table() only when the schema version on record differs from
 * the one in code.
 *
 * The previous version hooked fq_create_table() straight onto `init`, which
 * meant a require_once of wp-admin/includes/upgrade.php plus a CREATE TABLE
 * on every single front-end page load. This keeps the same guarantee — the
 * table is created without anyone having to remember to activate anything —
 * but the cost on a normal request is one autoloaded option read.
 *
 * Bump FQ_DB_VERSION whenever the CREATE TABLE statement above changes, and
 * dbDelta will apply the difference on the next load.
 */
function fq_maybe_create_table() {
    if ( get_option( 'fq_db_version' ) === FQ_DB_VERSION ) return;
    fq_create_table();
    update_option( 'fq_db_version', FQ_DB_VERSION );
}
add_action( 'after_switch_theme', 'fq_maybe_create_table' );
add_action( 'init',               'fq_maybe_create_table' );
 
// ============================================================
// ⚙️  SOCIAL MEDIA POSTS
// ============================================================
function fq_get_social_posts() {
    return [
        ["title" => "How to avoid overspending online",                          "url" => "https://youtube.com/shorts/emnKwcmmm1E?si=LBxYzsQMKlTZpCod",        "description" => "Have you ever bought something online you don't really need? Top five tips to avoid overspending online.",                                                                                    "topics" => ["spending"],  "format" => "video",  "level" => "all"],
        ["title" => "Nobody tells you this stuff before getting your first mortgage", "url" => "https://www.instagram.com/p/DU5-lu0DxMS/",                       "description" => "From surprise upfront costs to the emotional side of it all, here's the stuff nobody tells you before getting your first mortgage.",                                                             "topics" => ["property"],  "format" => "social", "level" => "beginner"],
        ["title" => "Choosing the right savings account",                        "url" => "https://www.instagram.com/p/DO6GeiHDM11/?img_index=1",               "description" => "Not all savings accounts are the same, and choosing the right one can make a real difference to your financial goals.",                                                                          "topics" => ["banking"],   "format" => "social", "level" => "all"],
        ["title" => "How parents can teach kids financial smarts",               "url" => "https://www.instagram.com/p/DIyLyc0t6ww/?img_index=1",               "description" => "Talking about money and teaching kids how to manage it will have significant benefits on their future financial wellbeing.",                                                                       "topics" => ["saving"],    "format" => "social", "level" => "beginner"],
        ["title" => "What is phishing?",                                         "url" => "https://www.instagram.com/p/DMaK5JgOC2i/",                           "description" => "How to recognise the warning signs, protect your personal information, and keep your money safe from scammers.",                                                                                  "topics" => ["scams"],     "format" => "video",  "level" => "all"],
    ];
}
 
// ============================================================
// ⚙️  FALLBACK RECOMMENDATIONS
// ============================================================
function fq_get_fallbacks() {
    return [
        ["title" => "How do I make a basic budget for myself?",   "url" => "https://moneyready.org/library/how-do-i-make-a-basic-budget-for-myself/",   "description" => "A step-by-step guide to building your first personal budget.",        "format" => "article", "reason" => "A great place to start your financial journey"],
        ["title" => "What are the pros and cons of borrowing?",   "url" => "https://moneyready.org/library/pros-and-cons-of-borrowing/",                 "description" => "A balanced look at when borrowing makes sense.",                       "format" => "article", "reason" => "Essential reading for financial health"],
        ["title" => "What is a credit score?",                    "url" => "https://moneyready.org/library/what-is-a-credit-score/",                     "description" => "What makes up your credit score and how to improve it.",              "format" => "article", "reason" => "One of our most popular Learning Hub articles"],
        ["title" => "How can I make my money go further?",        "url" => "https://moneyready.org/library/make-my-money-go-further/",                   "description" => "Simple ways to stretch every pound a little further.",                 "format" => "article", "reason" => "Popular with our readers aged 16–40"],
    ];
}
 
// ============================================================
// DYNAMIC LIBRARY
// ============================================================
function fq_get_content_library() {
    $cache_key = fq_cache_key();
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) return $cached;
 
    $posts = get_posts([
        'post_type'      => FQ_POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => 500,
        'tax_query'      => [[ 'taxonomy' => FQ_TOPIC_TAXONOMY, 'operator' => 'EXISTS' ]],
    ]);
 
    // Taxonomy slug => quiz topic slug, from topics.json rather than a
    // hardcoded array that silently covered only 7 of the 12 quiz topics.
    $topic_map = fq_get_taxonomy_map();
 
    $library = [];
    foreach ( $posts as $post ) {
        $terms = wp_get_post_terms( $post->ID, FQ_TOPIC_TAXONOMY, ['fields' => 'slugs'] );
        if ( is_wp_error($terms) || empty($terms) ) continue;
        $mapped = array_values( array_unique( array_filter(
            array_map( fn($s) => $topic_map[$s] ?? null, $terms )
        )));
        if ( empty($mapped) ) continue;
        $format   = function_exists('get_field') ? (get_field('fq_format',$post->ID) ?: 'article') : 'article';
        $level    = function_exists('get_field') ? (get_field('fq_level', $post->ID) ?: 'beginner') : 'beginner';
        $raw_desc = has_excerpt($post->ID)
            ? get_the_excerpt($post)
            : wp_trim_words(strip_shortcodes($post->post_content), 30, '');
        $library[] = [
            'title'       => $post->post_title,
            'url'         => get_permalink($post->ID),
            'description' => $raw_desc,
            'topics'      => $mapped,
            'format'      => $format,
            'level'       => $level,
        ];
    }
    $library = array_merge($library, fq_get_social_posts());
    set_transient($cache_key, $library, FQ_CACHE_DURATION);
    return $library;
}
 
add_action('save_post', function($post_id, $post) {
    if ($post->post_type === FQ_POST_TYPE && $post->post_status === 'publish') {
        delete_transient(fq_cache_key());
    }
}, 10, 2);
 
// ============================================================
// BOT PROTECTION
// ============================================================
/**
 * Fixed-window rate limit, keyed on IP.
 *
 * The window's expiry is set once, when the window opens, and is never
 * extended. The previous version rewrote the full TTL on every request, which
 * turned this into "10 requests with no gap longer than the window" rather
 * than "10 requests per window" — a steady trickle of requests just under the
 * window length accumulated toward a lockout indefinitely, and the lockout
 * then outlasted its intended duration.
 *
 * Note: REMOTE_ADDR is the immediate peer. If DigitalFootprints front the site
 * with a CDN or reverse proxy, this may be the proxy's address rather than the
 * visitor's, which would pool all visitors into one bucket. Worth confirming
 * against their infrastructure before relying on this for abuse protection.
 */
function fq_check_rate_limit() {
    $ip  = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = 'fq_rate_' . md5($ip);
    $now = time();

    $bucket = get_transient($key);
    if ( ! is_array($bucket)
         || ! isset($bucket['start'], $bucket['count'])
         || ($now - (int) $bucket['start']) >= FQ_RATE_LIMIT_WINDOW ) {
        $bucket = ['start' => $now, 'count' => 0];
    }

    if ($bucket['count'] >= FQ_RATE_LIMIT_MAX) return false;

    $bucket['count']++;
    $remaining = FQ_RATE_LIMIT_WINDOW - ($now - (int) $bucket['start']);
    set_transient($key, $bucket, max(1, $remaining));
    return true;
}
function fq_check_honeypot($data)  { return empty($data['fq_website']); }
function fq_get_time_token() {
    $time = time();
    return base64_encode($time . '|' . hash_hmac('sha256', $time, FQ_TIME_SECRET));
}
function fq_check_timing($token) {
    $decoded = base64_decode($token ?? '');
    if (!$decoded || substr_count($decoded, '|') !== 1) return false;
    [$time, $sig] = explode('|', $decoded, 2);
    if (!hash_equals(hash_hmac('sha256', $time, FQ_TIME_SECRET), $sig)) return false;
    return (time() - (int)$time) >= FQ_MIN_TIME;
}
 
// ============================================================
// ANSWER CACHE + PROMPT HELPERS
// ============================================================
function fq_answer_cache_key($answers) {
    $topics = $answers['topics'] ?? [];
    sort($topics);
    return 'fq_ans_' . md5(json_encode([
        'topics'     => $topics,
        'experience' => $answers['experience'] ?? '',
        'goal'       => $answers['goal']       ?? '',
        'format'     => $answers['format']     ?? '',
    ]));
}
function fq_truncate_for_prompt($library) {
    return array_map(function($item) {
        $item['description'] = wp_trim_words($item['description'], FQ_DESC_WORDS, '…');
        return $item;
    }, $library);
}
 
// ============================================================
// ASYNC DATABASE LOGGING ENDPOINT
// Receives events from the browser after results are shown/clicked.
// Fires asynchronously — zero impact on user experience.
// ============================================================
add_action('wp_ajax_fq_log',        'fq_log_handler');
add_action('wp_ajax_nopriv_fq_log', 'fq_log_handler');
 
function fq_log_handler() {
    check_ajax_referer('fq_nonce', 'nonce');
 
    global $wpdb;
    $table = $wpdb->prefix . FQ_DB_TABLE;
    $raw   = json_decode(file_get_contents('php://input'), true);
    $type  = sanitize_text_field($raw['event_type'] ?? '');
 
    if (!in_array($type, ['quiz_complete', 'card_click'], true)) {
        wp_send_json_error('Invalid event type', 400);
    }
 
    $data = [
        'event_type'  => $type,
        'event_time'  => current_time('mysql'),
    ];
 
    if ($type === 'quiz_complete') {
        $topics = array_map('sanitize_text_field', (array)($raw['topics'] ?? []));
        $data['topics']      = implode(', ', $topics);
        $data['confidence']  = sanitize_text_field($raw['confidence']   ?? '');
        $data['goal']        = sanitize_text_field($raw['goal']         ?? '');
        $data['format_pref'] = sanitize_text_field($raw['format_pref']  ?? '');
        $data['result_type'] = sanitize_text_field($raw['result_type']  ?? '');
    }
 
    if ($type === 'card_click') {
        $topics = array_map('sanitize_text_field', (array)($raw['topics'] ?? []));
        $data['topics']         = implode(', ', $topics);          // quiz topics at time of click
        $data['confidence']     = sanitize_text_field($raw['confidence']    ?? '');
        $data['goal']           = sanitize_text_field($raw['goal']          ?? '');
        $data['format_pref']    = sanitize_text_field($raw['format_pref']   ?? '');
        $data['result_type']    = sanitize_text_field($raw['result_type']   ?? '');
        $data['article_title']  = sanitize_text_field($raw['article_title'] ?? '');
        $data['article_url']    = esc_url_raw($raw['article_url']           ?? '');
        $data['article_topic']  = sanitize_text_field($raw['article_topic'] ?? '');
        $data['article_format'] = sanitize_text_field($raw['article_format']?? '');
    }
 
    $wpdb->insert($table, $data);
    wp_send_json_success();
}
 
// ============================================================
// SERVER-SIDE PROXY
// ============================================================
add_action('wp_ajax_fq_recommend',        'fq_proxy_handler');
add_action('wp_ajax_nopriv_fq_recommend', 'fq_proxy_handler');
 
function fq_proxy_handler() {
    check_ajax_referer('fq_nonce', 'nonce');
    $raw     = json_decode(file_get_contents('php://input'), true);
    $answers = $raw['answers'] ?? [];
 
    if (!fq_check_honeypot($raw))                     { wp_send_json_success(['recs' => fq_get_fallbacks(), 'fallback' => true]); }
    if (!fq_check_timing($raw['fq_time_token'] ?? '')) { wp_send_json_success(['recs' => fq_get_fallbacks(), 'fallback' => true]); }
    if (!fq_check_rate_limit())                        { wp_send_json_error(['message' => 'Too many requests. Please try again later.'], 429); }
 
    $topic_list = array_map('sanitize_text_field', (array)($answers['topics'] ?? []));
    $experience = sanitize_text_field($answers['experience'] ?? '');
    $goal       = sanitize_text_field($answers['goal']       ?? '');
    $format     = sanitize_text_field($answers['format']     ?? '');
    $topics     = implode(', ', $topic_list);
 
    if (!$topics && !$experience && !$goal && !$format) {
        wp_send_json_error(['message' => 'No answers received.'], 400);
    }
 
    $ans_cache_key = fq_answer_cache_key($answers);
    $cached_recs   = get_transient($ans_cache_key);
    if ($cached_recs !== false) {
        wp_send_json_success(['recs' => $cached_recs, 'fallback' => false, 'cached' => true]);
    }
 
    $library = fq_get_content_library();

    // Topic pre-filter.
    //
    // The previous version had two faults that compounded each other. It
    // counted `level: all` items as topic matches, which hid genuine no-match
    // cases; and when it found fewer than 6 items it discarded the filter
    // entirely and passed the WHOLE library to Claude. Someone selecting only
    // "Pensions" therefore received confident recommendations chosen from a
    // pool containing nothing about pensions.
    //
    // Now: real matches are identified on their own, a thin set is padded with
    // general-interest content without displacing the real matches, and a
    // genuine empty result is reported honestly instead of being papered over.
    if (!empty($topic_list)) {
        $matched = array_values(array_filter($library, function($item) use ($topic_list) {
            return !empty(array_intersect((array)($item['topics'] ?? []), $topic_list));
        }));

        if (empty($matched)) {
            wp_send_json_success([
                'recs'     => fq_get_fallbacks(),
                'fallback' => true,
                'no_match' => true,
            ]);
        }

        if (count($matched) < 6) {
            $matched_urls = array_column($matched, 'url');
            $general = array_values(array_filter($library, function($item) use ($matched_urls) {
                return ($item['level'] ?? '') === 'all'
                    && !in_array($item['url'], $matched_urls, true);
            }));
            $matched = array_merge($matched, array_slice($general, 0, 6 - count($matched)));
        }

        $library = $matched;
    }
 
    $prompt_library = fq_truncate_for_prompt($library);

    // Ask for a number the library can actually support. Requesting "3–5" from
    // a shortlist of 2 invites the model to pad with irrelevant items.
    $max_recs = max(1, min(5, count($prompt_library)));
    $min_recs = min(3, $max_recs);
    $how_many = ($min_recs === $max_recs)
        ? "Recommend the {$max_recs} most relevant item(s)"
        : "Recommend between {$min_recs} and {$max_recs} of the MOST relevant items";

    $prompt = "You are a financial education content recommender for Money Ready, a UK financial education charity.\n"
            . "{$how_many} from the library below, based on the user's quiz answers.\n"
            . "Only recommend items that appear in the library. Never invent a title or a URL.\n"
            . "Copy each url exactly as it appears in the library.\n\n"
            . "USER ANSWERS:\n- Topics: {$topics}\n- Knowledge level: {$experience}\n- Goal: {$goal}\n- Preferred format: {$format}\n\n"
            . "CONTENT LIBRARY:\n" . wp_json_encode($prompt_library);

    // Structured output. The prototype asked for JSON in prose, stripped
    // markdown fences with a regex and hoped the result parsed; a schema makes
    // malformed output a non-issue. Only `reason` is actually used from the
    // response — title, description, format and topics are re-injected from the
    // library below, so they cannot drift from what we hold.
    $schema = [
        'type'       => 'object',
        'properties' => [
            'recommendations' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'url'    => ['type' => 'string', 'description' => 'Must exactly match a url from the library.'],
                        'title'  => ['type' => 'string', 'description' => 'The library title for that url.'],
                        'reason' => ['type' => 'string', 'description' => 'One short sentence on why this suits the user.'],
                    ],
                    'required'             => ['url', 'title', 'reason'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['recommendations'],
        'additionalProperties' => false,
    ];

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 20,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => FQ_API_KEY,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode([
            'model'         => FQ_MODEL,
            'max_tokens'    => 2000,
            'output_config' => [
                // Picking a few items from a shortlist is not hard reasoning,
                // and a visitor is watching a spinner against a 20s timeout.
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            'messages'      => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);

    if (is_wp_error($response)) {
        error_log('[finance-quiz] Anthropic request failed: ' . $response->get_error_message());
        wp_send_json_success(['recs' => fq_get_fallbacks(), 'fallback' => true]);
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        error_log(sprintf('[finance-quiz] Anthropic API returned HTTP %d: %s', $status, wp_remote_retrieve_body($response)));
        wp_send_json_success(['recs' => fq_get_fallbacks(), 'fallback' => true]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (($body['stop_reason'] ?? '') === 'max_tokens') {
        error_log('[finance-quiz] Anthropic response hit max_tokens — JSON likely truncated. Consider raising max_tokens.');
    }

    // Find the first text block rather than assuming content[0]. Thinking is on
    // by default on current models, so content[0] may be a thinking block —
    // the prototype's `$body['content'][0]['text']` would have returned null.
    $text = '';
    foreach ((array)($body['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') { $text = (string)($block['text'] ?? ''); break; }
    }
    $parsed = json_decode($text, true);
    $recs   = is_array($parsed) ? ($parsed['recommendations'] ?? null) : null;

    if (!is_array($recs) || count($recs) === 0) {
        error_log('[finance-quiz] Could not read recommendations from the Anthropic response.');
        wp_send_json_success(['recs' => fq_get_fallbacks(), 'fallback' => true]);
    }

    // Re-key every field against the library. This does three jobs at once:
    // drops any recommendation whose URL we do not hold (a hallucinated link
    // would otherwise be rendered as a real card), restores the untruncated
    // description, and supplies `topics` and `format` — which the prototype
    // never returned, leaving article_topic empty in every analytics row.
    $url_map = array_column($library, null, 'url');
    $recs = array_values(array_filter(array_map(function($rec) use ($url_map) {
        $url = (string)($rec['url'] ?? '');
        if (!isset($url_map[$url])) return null;
        $src = $url_map[$url];
        return [
            'title'       => $src['title'],
            'url'         => $src['url'],
            'description' => $src['description'],
            'format'      => $src['format'] ?? 'article',
            'topics'      => (array)($src['topics'] ?? []),
            'reason'      => (string)($rec['reason'] ?? ''),
        ];
    }, $recs)));

    if (empty($recs)) {
        error_log('[finance-quiz] Every recommended URL was absent from the library — discarding and serving fallbacks.');
        wp_send_json_success(['recs' => fq_get_fallbacks(), 'fallback' => true]);
    }

    set_transient($ans_cache_key, $recs, FQ_ANSWER_CACHE_TTL);
    wp_send_json_success(['recs' => $recs, 'fallback' => false]);
}
 
add_action('wp_ajax_fq_fallback',        'fq_fallback_handler');
add_action('wp_ajax_nopriv_fq_fallback', 'fq_fallback_handler');
function fq_fallback_handler() {
    check_ajax_referer('fq_nonce', 'nonce');
    wp_send_json_success(['recs' => fq_get_fallbacks()]);
}
 
// ============================================================
// ADMIN DASHBOARD
// ============================================================
add_action('admin_menu', function() {
    add_menu_page(
        'Quiz Analytics',
        'Quiz Analytics',
        'manage_options',
        'fq-analytics',
        'fq_analytics_page',
        'dashicons-chart-bar',
        30
    );
});
 
function fq_analytics_page() {
    global $wpdb;
    $table = $wpdb->prefix . FQ_DB_TABLE;
 
    // ── CSV export ──────────────────────────────────────────
    if (isset($_GET['fq_export']) && $_GET['fq_export'] === 'full' && current_user_can('manage_options')) {
        check_admin_referer('fq_export');
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY event_time DESC", ARRAY_A);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="quiz-analytics-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
 
    if (isset($_GET['fq_export']) && $_GET['fq_export'] === 'summary' && current_user_can('manage_options')) {
        check_admin_referer('fq_export');
        $clicks = $wpdb->get_results("
            SELECT article_title, article_url, article_topic, article_format, COUNT(*) as clicks
            FROM {$table} WHERE event_type = 'card_click'
            GROUP BY article_url ORDER BY clicks DESC", ARRAY_A);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="quiz-summary-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Article Title','URL','Topic','Format','Total Clicks']);
        foreach ($clicks as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }
 
    // ── Date filter ─────────────────────────────────────────
    $days  = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
 
    // ── Stats ───────────────────────────────────────────────
    $total_completions = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE event_type = 'quiz_complete' AND event_time >= %s", $since));
    $total_clicks = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE event_type = 'card_click' AND event_time >= %s", $since));
    $ctr = $total_completions > 0 ? round(($total_clicks / $total_completions) * 100) : 0;
 
    $top_articles = $wpdb->get_results($wpdb->prepare("
        SELECT article_title, article_url, article_topic, article_format, COUNT(*) as clicks
        FROM {$table} WHERE event_type = 'card_click' AND event_time >= %s
        GROUP BY article_url ORDER BY clicks DESC LIMIT 10", $since), ARRAY_A);
 
    $top_topics = $wpdb->get_results($wpdb->prepare("
        SELECT topics, COUNT(*) as count
        FROM {$table} WHERE event_type = 'quiz_complete' AND event_time >= %s
        GROUP BY topics ORDER BY count DESC LIMIT 10", $since), ARRAY_A);
 
    $top_goals = $wpdb->get_results($wpdb->prepare("
        SELECT goal, COUNT(*) as count
        FROM {$table} WHERE event_type = 'quiz_complete' AND event_time >= %s AND goal != ''
        GROUP BY goal ORDER BY count DESC", $since), ARRAY_A);
 
    $result_types = $wpdb->get_results($wpdb->prepare("
        SELECT result_type, COUNT(*) as count
        FROM {$table} WHERE event_type = 'quiz_complete' AND event_time >= %s
        GROUP BY result_type", $since), ARRAY_A);
 
    $export_url_full    = wp_nonce_url(admin_url('admin.php?page=fq-analytics&fq_export=full'),    'fq_export');
    $export_url_summary = wp_nonce_url(admin_url('admin.php?page=fq-analytics&fq_export=summary'), 'fq_export');
 
    $goal_labels = [
        'save-more'=>'Save more each month','get-out-debt'=>'Get on top of debt',
        'start-investing'=>'Start investing','buy-home'=>'Buy or rent a home',
        'earn-more'=>'Earn more / side income','general'=>'General wellbeing',
    ];
    // 'no_match' surfaces content gaps: a high count here means visitors are
    // asking for topics the library cannot answer yet. Useful for planning.
    $result_labels = ['ai'=>'AI generated','cached'=>'Cached','fallback'=>'Fallback','no_match'=>'No matching content'];
 
    ?>
    <div class="wrap">
      <h1 style="display:flex;align-items:center;gap:10px">
        <span style="background:#8F0425;color:#fff;border-radius:8px;padding:6px 14px;font-size:1rem">Money Ready</span>
        Quiz Analytics
      </h1>
 
      <!-- Date filter -->
      <div style="margin:16px 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <strong>Show last:</strong>
        <?php foreach ([7,30,90,365] as $d): ?>
          <a href="<?php echo esc_url(admin_url('admin.php?page=fq-analytics&days='.$d)); ?>"
             style="padding:5px 14px;border-radius:99px;text-decoration:none;font-weight:600;font-size:.85rem;
                    background:<?php echo $days===$d ? '#8F0425' : '#f0f0f0'; ?>;
                    color:<?php echo $days===$d ? '#fff' : '#333'; ?>">
            <?php echo $d; ?> days
          </a>
        <?php endforeach; ?>
        <span style="color:#888;font-size:.82rem;margin-left:8px">Since <?php echo date('j M Y', strtotime("-{$days} days")); ?></span>
      </div>
 
      <!-- Summary cards -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px">
        <?php
        $cards = [
            ['Quiz completions', $total_completions, '#8F0425'],
            ['Card clicks',      $total_clicks,      '#1C466B'],
            ['Click-through rate', $ctr.'%',         '#595959'],
        ];
        foreach ($cards as [$label,$val,$col]): ?>
          <div style="background:#fff;border-radius:10px;padding:18px 20px;box-shadow:0 2px 8px rgba(0,0,0,.07);border-top:4px solid <?php echo $col; ?>">
            <div style="font-size:1.8rem;font-weight:800;color:<?php echo $col; ?>"><?php echo esc_html($val); ?></div>
            <div style="font-size:.82rem;color:#595959;margin-top:4px"><?php echo esc_html($label); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
 
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px">
 
        <!-- Top clicked articles -->
        <div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.07)">
          <h2 style="font-size:1rem;margin:0 0 14px">Top clicked articles</h2>
          <?php if (empty($top_articles)): ?>
            <p style="color:#888;font-size:.85rem">No click data yet.</p>
          <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.83rem">
              <thead>
                <tr style="border-bottom:2px solid #f0f0f0">
                  <th style="text-align:left;padding:6px 8px;color:#595959">Article</th>
                  <th style="text-align:left;padding:6px 8px;color:#595959">Topic</th>
                  <th style="text-align:right;padding:6px 8px;color:#595959">Clicks</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($top_articles as $i => $row): ?>
                  <tr style="border-bottom:1px solid #f5f5f5;background:<?php echo $i%2===0?'#fafafa':'#fff'; ?>">
                    <td style="padding:7px 8px">
                      <a href="<?php echo esc_url($row['article_url']); ?>" target="_blank" style="color:#8F0425;text-decoration:none;font-weight:600">
                        <?php echo esc_html($row['article_title']); ?>
                      </a>
                      <span style="display:block;font-size:.75rem;color:#888"><?php echo esc_html($row['article_format']); ?></span>
                    </td>
                    <td style="padding:7px 8px;color:#595959"><?php echo esc_html($row['article_topic']); ?></td>
                    <td style="padding:7px 8px;text-align:right;font-weight:700;color:#8F0425"><?php echo (int)$row['clicks']; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
 
        <!-- Top topic combinations -->
        <div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.07)">
          <h2 style="font-size:1rem;margin:0 0 14px">Most selected topic combinations</h2>
          <?php if (empty($top_topics)): ?>
            <p style="color:#888;font-size:.85rem">No quiz data yet.</p>
          <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.83rem">
              <thead>
                <tr style="border-bottom:2px solid #f0f0f0">
                  <th style="text-align:left;padding:6px 8px;color:#595959">Topics selected</th>
                  <th style="text-align:right;padding:6px 8px;color:#595959">Count</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($top_topics as $i => $row): ?>
                  <tr style="border-bottom:1px solid #f5f5f5;background:<?php echo $i%2===0?'#fafafa':'#fff'; ?>">
                    <td style="padding:7px 8px;color:#1a1a2e"><?php echo esc_html($row['topics']); ?></td>
                    <td style="padding:7px 8px;text-align:right;font-weight:700;color:#1C466B"><?php echo (int)$row['count']; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
 
        <!-- Top goals -->
        <div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.07)">
          <h2 style="font-size:1rem;margin:0 0 14px">Most common goals</h2>
          <?php if (empty($top_goals)): ?>
            <p style="color:#888;font-size:.85rem">No quiz data yet.</p>
          <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.83rem">
              <thead>
                <tr style="border-bottom:2px solid #f0f0f0">
                  <th style="text-align:left;padding:6px 8px;color:#595959">Goal</th>
                  <th style="text-align:right;padding:6px 8px;color:#595959">Count</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($top_goals as $i => $row): ?>
                  <tr style="border-bottom:1px solid #f5f5f5;background:<?php echo $i%2===0?'#fafafa':'#fff'; ?>">
                    <td style="padding:7px 8px;color:#1a1a2e"><?php echo esc_html($goal_labels[$row['goal']] ?? $row['goal']); ?></td>
                    <td style="padding:7px 8px;text-align:right;font-weight:700;color:#1C466B"><?php echo (int)$row['count']; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
 
        <!-- Result types -->
        <div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.07)">
          <h2 style="font-size:1rem;margin:0 0 14px">Result types</h2>
          <?php if (empty($result_types)): ?>
            <p style="color:#888;font-size:.85rem">No quiz data yet.</p>
          <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.83rem">
              <thead>
                <tr style="border-bottom:2px solid #f0f0f0">
                  <th style="text-align:left;padding:6px 8px;color:#595959">Type</th>
                  <th style="text-align:right;padding:6px 8px;color:#595959">Count</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($result_types as $i => $row): ?>
                  <tr style="border-bottom:1px solid #f5f5f5;background:<?php echo $i%2===0?'#fafafa':'#fff'; ?>">
                    <td style="padding:7px 8px;color:#1a1a2e"><?php echo esc_html($result_labels[$row['result_type']] ?? $row['result_type']); ?></td>
                    <td style="padding:7px 8px;text-align:right;font-weight:700;color:#1C466B"><?php echo (int)$row['count']; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
 
      </div>
 
      <!-- Export buttons -->
      <div style="background:#fff;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.07)">
        <h2 style="font-size:1rem;margin:0 0 6px">Export data</h2>
        <p style="color:#595959;font-size:.85rem;margin:0 0 14px">Downloads cover all time, not just the selected date range above.</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <a href="<?php echo esc_url($export_url_full); ?>"
             style="padding:10px 20px;background:#8F0425;color:#fff;border-radius:99px;text-decoration:none;font-weight:700;font-size:.88rem">
            ⬇ Full export (CSV)
          </a>
          <a href="<?php echo esc_url($export_url_summary); ?>"
             style="padding:10px 20px;background:#1C466B;color:#fff;border-radius:99px;text-decoration:none;font-weight:700;font-size:.88rem">
            ⬇ Summary export (CSV)
          </a>
        </div>
      </div>
 
    </div>
    <?php
}
 
// ============================================================
// SHORTCODE
// ============================================================
function finance_quiz_shortcode() {
    $ajax_url   = admin_url('admin-ajax.php');
    $nonce      = wp_create_nonce('fq_nonce');
    $time_token = fq_get_time_token();
    $ga4_id     = FQ_GA4_MEASUREMENT_ID;
    ob_start(); ?>
    <div id="fq-wrap">
    <style>
      #fq-wrap *{box-sizing:border-box}
      #fq-wrap{font-family:'Segoe UI',system-ui,sans-serif;max-width:620px;margin:0 auto;border-radius:16px;overflow:hidden;box-shadow:0 6px 32px rgba(0,0,0,.10)}
      #fq-wrap *:focus-visible{outline:3px solid #8F0425 !important;outline-offset:3px !important}
      #fq-wrap .fq-input-option input[type=checkbox]:focus-visible + label,
      #fq-wrap .fq-input-option input[type=radio]:focus-visible + label{outline:3px solid #8F0425;outline-offset:3px;border-radius:8px}
      #fq-wrap [tabindex="-1"]:focus{outline:none}
      #fq-wrap .fq-input-option{position:relative}
      #fq-wrap .fq-input-option input{position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer;z-index:1}
      .fq-header{background:#8F0425;padding:28px 32px 26px;color:#fff}
      .fq-header img{height:26px;filter:brightness(0) invert(1);margin-bottom:14px;display:block}
      .fq-header h1{font-size:1.4rem;font-weight:800;margin:0 0 6px;line-height:1.25;color:#fff}
      .fq-header p{font-size:.87rem;opacity:.9;margin:0;line-height:1.55}
      .fq-progress-meta{display:flex;justify-content:space-between;font-size:.71rem;opacity:.85;margin-bottom:7px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-top:20px}
      .fq-progress-track{background:rgba(0,0,0,0.25);border-radius:99px;height:5px}
      .fq-progress-fill{background:#fff;height:100%;border-radius:99px;transition:width .4s ease}
      .fq-body{background:#fff;padding:28px 32px 32px}
      .fq-step{display:none}.fq-step.fq-active{display:block}
      .fq-step legend{font-size:1.05rem;font-weight:700;color:#1a1a2e;margin:0 0 18px;line-height:1.4;display:block;width:100%;padding:0;float:left}
      .fq-step legend + *{clear:both}
      .fq-hint{font-size:.78rem;color:#595959;margin:-10px 0 13px}
      .fq-opts{display:grid;gap:8px}
      .fq-opts.fq-2col{grid-template-columns:1fr 1fr}
      .fq-opt-label{display:block;border:2px solid #949494;border-radius:8px;padding:11px 14px;background:#fff;cursor:pointer;font-size:.86rem;font-weight:500;color:#1a1a2e;transition:all .15s;line-height:1.4}
      .fq-opt-label:hover{border-color:#8F0425;background:#fdf0f3}
      .fq-opt-label.fq-checked{border-color:#8F0425;background:#fdf0f3;color:#8F0425;font-weight:700}
      .fq-nav{display:flex;justify-content:space-between;align-items:center;margin-top:26px;gap:12px}
      .fq-btn{padding:10px 24px;border-radius:99px;font-size:.9rem;font-weight:700;cursor:pointer;border:none;transition:all .15s}
      .fq-back{background:#fff;color:#595959;border:2px solid #949494 !important}.fq-back:hover{background:#f8f8f8}
      .fq-next,.fq-submit{background:#8F0425;color:#fff}.fq-next:hover,.fq-submit:hover{background:#6e0319}
      .fq-next:disabled,.fq-submit:disabled{background:#e5e5e5;color:#595959;cursor:not-allowed}
      .fq-submit{width:100%;flex:1}
      .fq-loading{text-align:center;padding:48px 0}
      .fq-spinner{width:44px;height:44px;border:4px solid #e5e5e5;border-top-color:#8F0425;border-radius:50%;animation:fqspin .85s linear infinite;margin:0 auto 18px}
      @keyframes fqspin{to{transform:rotate(360deg)}}
      .fq-loading-msg{color:#1a1a2e;font-weight:700;font-size:.97rem;margin-bottom:5px;transition:opacity .4s ease}
      .fq-loading-sub{color:#595959;font-size:.82rem;margin:0}
      #fq-results h2{font-size:1.08rem;font-weight:800;color:#1a1a2e;margin:0 0 4px}
      .fq-sub{color:#595959;font-size:.85rem;margin:0 0 20px;line-height:1.5}
      .fq-fallback-note{background:#fff8f0;border:1px solid #f9c5a0;border-radius:8px;padding:10px 14px;font-size:.82rem;color:#7a3200;margin-bottom:16px}
      .fq-cards{display:grid;gap:11px}
      .fq-card{display:block;border:1.5px solid #e5e5e5;border-left:4px solid #8F0425;border-radius:8px;padding:14px 16px;text-decoration:none;color:#1a1a2e;transition:all .15s;background:#fff}
      .fq-card:hover{background:#fdf0f3;transform:translateY(-2px);box-shadow:0 4px 16px rgba(143,4,37,.10)}
      .fq-card-reason{font-size:.71rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#8F0425;margin-bottom:4px}
      .fq-card-title{font-weight:700;font-size:.94rem;margin-bottom:4px}
      .fq-card-desc{font-size:.82rem;color:#595959;line-height:1.45}
      .fq-card-fmt{display:inline-block;font-size:.72rem;font-weight:600;color:#fff;background:#1C466B;border-radius:99px;padding:3px 10px;margin-top:9px}
      .fq-cta{margin-top:20px;padding:16px 18px;background:#1C466B;border-radius:10px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
      .fq-cta p:first-child{font-size:.85rem;font-weight:700;color:#fff;margin:0 0 2px}
      .fq-cta p:last-child{font-size:.78rem;color:rgba(255,255,255,.75);margin:0}
      .fq-cta a{padding:9px 18px;border-radius:99px;font-size:.82rem;font-weight:700;background:#fff;color:#1C466B;text-decoration:none;white-space:nowrap;flex-shrink:0}
      .fq-restart{margin-top:12px;background:#fff;color:#595959;border:2px solid #949494;display:block;width:100%;padding:10px;border-radius:99px;cursor:pointer;font-size:.85rem;font-weight:600;transition:all .15s}
      .fq-restart:hover{background:#f8f8f8}
      .fq-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
      @media(max-width:480px){.fq-opts.fq-2col{grid-template-columns:1fr}.fq-header,.fq-body{padding-left:18px;padding-right:18px}}
    </style>
 
    <div class="fq-header">
      <img src="https://moneyready.org/wp-content/themes/honeycom3/assets/images/logo.png" alt="Money Ready" />
      <h1>Find your perfect content</h1>
      <p>Answer 4 quick questions and we'll point you to the content that's most useful for you right now.</p>
      <div id="fq-progress-wrap">
        <div class="fq-progress-meta">
          <span id="fq-step-label">Question 1 of 4</span>
          <span aria-hidden="true" id="fq-step-count">1 of 4</span>
        </div>
        <div class="fq-progress-track" role="progressbar" aria-valuenow="1" aria-valuemin="1" aria-valuemax="4" aria-labelledby="fq-step-label">
          <div class="fq-progress-fill" id="fq-progress" style="width:0%"></div>
        </div>
      </div>
    </div>
 
    <div class="fq-body">
      <div id="fq-quiz">
 
        <fieldset class="fq-step fq-active" data-step="1">
          <legend id="fq-q-1" tabindex="-1">Which money topics would you like to learn more about?</legend>
          <p id="fq-hint-1" class="fq-hint">Select all that apply</p>
          <div class="fq-opts fq-2col" aria-describedby="fq-hint-1">
            <?php
            // Checkboxes come from topics.json — the same source the library
            // filter and the classification prompt read. Display order follows
            // the order of that file.
            foreach (fq_get_topics() as $topic):
              $val   = $topic['slug'];
              $label = trim($topic['emoji'] . ' ' . $topic['label']);
            ?>
              <div class="fq-input-option">
                <input type="checkbox" id="fq-topic-<?php echo esc_attr($val); ?>" name="fq-topics" value="<?php echo esc_attr($val); ?>" />
                <label for="fq-topic-<?php echo esc_attr($val); ?>" class="fq-opt-label"><?php echo esc_html($label); ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="fq-nav"><span></span><button class="fq-btn fq-next" id="fq-n1" disabled aria-disabled="true" onclick="fqNext(1)">Next →</button></div>
        </fieldset>
 
        <fieldset class="fq-step" data-step="2">
          <legend id="fq-q-2" tabindex="-1">How confident do you feel about managing your money?</legend>
          <div class="fq-opts" role="radiogroup" aria-labelledby="fq-q-2">
            <?php foreach (['beginner'=>'🌱 Not very — I\'m still figuring things out','some'=>'📚 A bit — I know some basics but want to learn more','confident'=>'💪 Pretty confident — I want to go deeper'] as $val=>$label): ?>
              <div class="fq-input-option">
                <input type="radio" id="fq-exp-<?php echo esc_attr($val); ?>" name="fq-experience" value="<?php echo esc_attr($val); ?>" onchange="fqRadio(this,2)" />
                <label for="fq-exp-<?php echo esc_attr($val); ?>" class="fq-opt-label"><?php echo esc_html($label); ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="fq-nav">
            <button class="fq-btn fq-back" onclick="fqPrev(2)">← Back</button>
            <button class="fq-btn fq-next" id="fq-n2" disabled aria-disabled="true" onclick="fqNext(2)">Next →</button>
          </div>
        </fieldset>
 
        <fieldset class="fq-step" data-step="3">
          <legend id="fq-q-3" tabindex="-1">What's your biggest money goal right now?</legend>
          <div class="fq-opts" role="radiogroup" aria-labelledby="fq-q-3">
            <?php foreach (['save-more'=>'🐖 Save more each month','get-out-debt'=>'🆓 Get on top of debt','start-investing'=>'🌱 Start investing for my future','buy-home'=>'🏠 Buy or rent a home','earn-more'=>'💼 Earn more or build extra income','general'=>'🗺️ General financial wellbeing'] as $val=>$label): ?>
              <div class="fq-input-option">
                <input type="radio" id="fq-goal-<?php echo esc_attr($val); ?>" name="fq-goal" value="<?php echo esc_attr($val); ?>" onchange="fqRadio(this,3)" />
                <label for="fq-goal-<?php echo esc_attr($val); ?>" class="fq-opt-label"><?php echo esc_html($label); ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="fq-nav">
            <button class="fq-btn fq-back" onclick="fqPrev(3)">← Back</button>
            <button class="fq-btn fq-next" id="fq-n3" disabled aria-disabled="true" onclick="fqNext(3)">Next →</button>
          </div>
        </fieldset>
 
        <fieldset class="fq-step" data-step="4">
          <legend id="fq-q-4" tabindex="-1">How do you like to learn?</legend>
          <div class="fq-opts" role="radiogroup" aria-labelledby="fq-q-4">
            <?php foreach (['reading'=>'📖 Reading articles','video'=>'▶️ Watching videos','social'=>'📱 Quick social media posts','mix'=>'🎯 A mix of everything'] as $val=>$label): ?>
              <div class="fq-input-option">
                <input type="radio" id="fq-fmt-<?php echo esc_attr($val); ?>" name="fq-format" value="<?php echo esc_attr($val); ?>" onchange="fqRadio(this,4)" />
                <label for="fq-fmt-<?php echo esc_attr($val); ?>" class="fq-opt-label"><?php echo esc_html($label); ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="fq-nav">
            <button class="fq-btn fq-back" onclick="fqPrev(4)">← Back</button>
            <button class="fq-btn fq-submit" id="fq-sub" disabled aria-disabled="true" onclick="fqSubmit()">Show my recommendations 🎉</button>
          </div>
        </fieldset>
 
      </div>
 
      <div id="fq-results" style="display:none">
        <div class="fq-loading" id="fq-loading" role="status" aria-live="polite" aria-label="Loading your recommendations">
          <div class="fq-spinner" aria-hidden="true"></div>
          <p class="fq-loading-msg" id="fq-loading-msg">Searching our library…</p>
          <p class="fq-loading-sub">Just a moment while we personalise your recommendations.</p>
        </div>
        <div id="fq-res-content" style="display:none">
          <h2 id="fq-results-heading" tabindex="-1">Your personalised picks 🎉</h2>
          <p class="fq-sub">Based on your answers, here's where we'd suggest you start:</p>
          <div id="fq-fallback-note" class="fq-fallback-note" role="alert" style="display:none">
            ⚡ We had trouble connecting right now, so here are our top picks for you. Try again later for fully personalised recommendations!
          </div>
          <div id="fq-nomatch-note" class="fq-fallback-note" role="alert" style="display:none">
            📭 We don't have anything on those topics just yet — we're adding new content all the time. In the meantime, here are some of our most popular guides.
          </div>
          <div class="fq-cards" id="fq-cards" aria-live="polite"></div>
          <div class="fq-cta">
            <div>
              <p>Want to explore more?</p>
              <p>Browse all our free resources in the Learning Hub.</p>
            </div>
            <a href="https://moneyready.org/learning-hub/" target="_blank" rel="noopener">
              Learning Hub →<span class="fq-sr-only"> (opens in a new tab)</span>
            </a>
          </div>
          <button class="fq-restart" onclick="fqRestart()">↩ Start again</button>
        </div>
      </div>
    </div>
    </div>
 
    <script>
    (function(){
      const AJAX       = <?php echo json_encode($ajax_url); ?>;
      const NONCE      = <?php echo json_encode($nonce); ?>;
      const TIME_TOKEN = <?php echo json_encode($time_token); ?>;
      const GA4_ID     = <?php echo json_encode($ga4_id); ?>;
      const STEPS      = 4;
      const LOAD_MSGS  = ['Searching our library…','Matching to your goals…','Checking your preferences…','Almost there…'];
      let ans = {topics:[], experience:null, goal:null, format:null};
      let resultType = null;
      let loadTimer  = null;
      let loadIdx    = 0;
 
      // ── GA4 helper — fires only if gtag is available ──
      function ga4Event(name, params) {
        if (typeof gtag === 'function') {
          gtag('event', name, Object.assign({ send_to: GA4_ID }, params));
        }
      }
 
      // ── Async DB log — fire and forget, zero UX impact ──
      function dbLog(payload) {
        fetch(AJAX, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(Object.assign({ action: 'fq_log', nonce: NONCE }, payload)),
          keepalive: true  // ensures request completes even if user navigates away
        }).catch(()=>{}); // silently ignore any errors
      }
 
      // ── Checkbox topic selection ──
      document.querySelectorAll('input[name="fq-topics"]').forEach(cb=>{
        cb.addEventListener('change', ()=>{
          cb.nextElementSibling.classList.toggle('fq-checked', cb.checked);
          ans.topics = [...document.querySelectorAll('input[name="fq-topics"]:checked')].map(x=>x.value);
          const btn = document.getElementById('fq-n1');
          btn.disabled = ans.topics.length === 0;
          btn.setAttribute('aria-disabled', ans.topics.length === 0 ? 'true' : 'false');
        });
      });
 
      // ── Radio selection ──
      window.fqRadio = (input, step) => {
        input.closest('.fq-opts').querySelectorAll('.fq-opt-label').forEach(l=>l.classList.remove('fq-checked'));
        input.nextElementSibling.classList.add('fq-checked');
        const keys = ['','topics','experience','goal','format'];
        ans[keys[step]] = input.value;
        const nxt = document.getElementById('fq-n' + step);
        if(nxt){ nxt.disabled=false; nxt.setAttribute('aria-disabled','false'); }
        if(step===4){ const s=document.getElementById('fq-sub'); s.disabled=false; s.setAttribute('aria-disabled','false'); }
      };
 
      // ── Progress ──
      function setProgress(step) {
        document.getElementById('fq-progress').style.width = ((step-1)/STEPS*100)+'%';
        document.getElementById('fq-step-label').textContent = 'Question '+step+' of '+STEPS;
        document.getElementById('fq-step-count').textContent = step+' of '+STEPS;
        document.querySelector('.fq-progress-track').setAttribute('aria-valuenow', step);
      }
 
      // ── Step navigation ──
      window.fqNext = s => {
        document.querySelector('[data-step="'+s+'"]').classList.remove('fq-active');
        const next = document.querySelector('[data-step="'+(s+1)+'"]');
        next.classList.add('fq-active');
        setProgress(s+1);
        const legend = next.querySelector('legend');
        if(legend) legend.focus();
      };
      window.fqPrev = s => {
        document.querySelector('[data-step="'+s+'"]').classList.remove('fq-active');
        const prev = document.querySelector('[data-step="'+(s-1)+'"]');
        prev.classList.add('fq-active');
        setProgress(s-1);
        const legend = prev.querySelector('legend');
        if(legend) legend.focus();
      };
 
      // ── Loading messages ──
      function startLoading() {
        loadIdx = 0;
        document.getElementById('fq-loading-msg').textContent = LOAD_MSGS[0];
        loadTimer = setInterval(()=>{
          const el = document.getElementById('fq-loading-msg');
          el.style.opacity='0';
          setTimeout(()=>{ loadIdx=(loadIdx+1)%LOAD_MSGS.length; el.textContent=LOAD_MSGS[loadIdx]; el.style.opacity='1'; },400);
        },2500);
      }
      function stopLoading() { clearInterval(loadTimer); }
 
      // ── URL guard ──
      // Card hrefs originate in model output. Anything that isn't a plain
      // http(s) URL is rejected rather than rendered — a `javascript:` href
      // would otherwise be a clickable link in the results list.
      function safeHttpUrl(raw) {
        try {
          const u = new URL(String(raw||''), window.location.origin);
          return (u.protocol === 'http:' || u.protocol === 'https:') ? u.href : null;
        } catch(_) { return null; }
      }

      // ── Render cards + GA4 + DB log + prefetch ──
      // Built with DOM APIs rather than innerHTML. The previous version
      // interpolated title, description, reason and url straight into a markup
      // string, so model output reached the DOM as markup; `textContent`
      // cannot execute, which removes the whole class of problem instead of
      // trying to escape each field correctly.
      function renderCards(recs, isFallback, isNoMatch) {
        const fmt  = {article:'📖 Article', video:'▶️ Video', social:'📱 Social post'};
        const wrap = document.getElementById('fq-cards');
        wrap.textContent = '';

        const rendered = [];

        (recs||[]).forEach(r=>{
          const href = safeHttpUrl(r.url);
          if(!href) return;   // drop anything that isn't a real web link

          const title  = String(r.title       || ''),
                reason = String(r.reason      || ''),
                desc   = String(r.description || ''),
                format = String(r.format      || ''),
                topic  = String((r.topics && r.topics[0]) || '');

          const card = document.createElement('a');
          card.className = 'fq-card';
          card.href      = href;
          card.target    = '_blank';
          card.rel       = 'noopener';
          card.setAttribute('aria-label', title + ' — ' + reason + ' — opens in a new tab');
          card.dataset.title  = title;
          card.dataset.url    = href;
          card.dataset.topic  = topic;
          card.dataset.format = format;

          const part = (tag, cls, text, hidden)=>{
            const el = document.createElement(tag);
            el.className   = cls;
            el.textContent = text;
            if(hidden) el.setAttribute('aria-hidden','true');
            return el;
          };
          card.appendChild(part('div',  'fq-card-reason', reason,               true));
          card.appendChild(part('div',  'fq-card-title',  title,               false));
          card.appendChild(part('div',  'fq-card-desc',   desc,                false));
          card.appendChild(part('span', 'fq-card-fmt',    fmt[format]||format, true));

          // Click tracking, attached at creation rather than by re-querying
          // the document afterwards.
          card.addEventListener('click', ()=>{
            ga4Event('fq_card_click', {
              article_title:  title,
              article_url:    href,
              article_topic:  topic,
              article_format: format,
              quiz_topics:    ans.topics.join(', '),
              quiz_goal:      ans.goal,
              result_type:    resultType,
            });

            dbLog({
              event_type:     'card_click',
              topics:         ans.topics,
              confidence:     ans.experience,
              goal:           ans.goal,
              format_pref:    ans.format,
              result_type:    resultType,
              article_title:  title,
              article_url:    href,
              article_topic:  topic,
              article_format: format,
            });
          });

          wrap.appendChild(card);
          rendered.push(href);
        });

        // A no-match is not a connection problem — saying "we had trouble
        // connecting" there would be untrue. Show the honest note instead.
        if(isNoMatch)          document.getElementById('fq-nomatch-note').style.display='block';
        else if(isFallback)    document.getElementById('fq-fallback-note').style.display='block';

        // Every URL failed the guard — don't present an empty results list.
        if(rendered.length === 0) {
          const p = document.createElement('p');
          p.style.cssText  = 'color:#595959;font-size:.9rem';
          p.textContent    = 'We\'re having trouble loading recommendations right now. Please visit our Learning Hub for our latest content.';
          wrap.appendChild(p);
          document.getElementById('fq-fallback-note').style.display='block';
        }

        // Prefetch, using only the URLs that passed the guard
        rendered.forEach(u=>{ const l=document.createElement('link'); l.rel='prefetch'; l.href=u; document.head.appendChild(l); });

        stopLoading();
        document.getElementById('fq-loading').style.display='none';
        document.getElementById('fq-res-content').style.display='block';
        document.getElementById('fq-results-heading').focus();
      }
 
      // ── Submit ──
      window.fqSubmit = async () => {
        document.getElementById('fq-quiz').style.display='none';
        document.getElementById('fq-progress-wrap').style.display='none';
        document.getElementById('fq-results').style.display='block';
        document.getElementById('fq-loading').style.display='block';
        document.getElementById('fq-res-content').style.display='none';
        document.getElementById('fq-fallback-note').style.display='none';
        document.getElementById('fq-nomatch-note').style.display='none';
        startLoading();
 
        const controller = new AbortController();
        const timer = setTimeout(()=>controller.abort(), 22000);
 
        try {
          const res = await fetch(AJAX,{
            method:'POST', signal:controller.signal,
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'fq_recommend',nonce:NONCE,answers:ans,fq_website:'',fq_time_token:TIME_TOKEN})
          });
          clearTimeout(timer);
          const data = await res.json();
          if(data.success && data.data?.recs?.length) {
            resultType = data.data.no_match ? 'no_match'
                       : data.data.fallback ? 'fallback'
                       : data.data.cached   ? 'cached' : 'ai';
            renderCards(data.data.recs, data.data.fallback===true, data.data.no_match===true);
 
            // GA4: quiz completion
            ga4Event('fq_quiz_complete', {
              quiz_topics:    ans.topics.join(', '),
              quiz_confidence:ans.experience,
              quiz_goal:      ans.goal,
              quiz_format:    ans.format,
              result_type:    resultType,
            });
 
            // Async DB log: quiz completion
            dbLog({
              event_type:  'quiz_complete',
              topics:      ans.topics,
              confidence:  ans.experience,
              goal:        ans.goal,
              format_pref: ans.format,
              result_type: resultType,
            });
          } else { throw new Error('empty'); }
        } catch(e) {
          clearTimeout(timer); stopLoading();
          resultType = 'fallback';
          try {
            const fb = await fetch(AJAX,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'fq_fallback',nonce:NONCE})});
            const fbData = await fb.json();
            renderCards(fbData.data?.recs||[], true);
          } catch(_) {
            document.getElementById('fq-loading').style.display='none';
            document.getElementById('fq-res-content').style.display='block';
            document.getElementById('fq-fallback-note').style.display='block';
            document.getElementById('fq-cards').innerHTML='<p style="color:#595959;font-size:.9rem">We\'re having trouble loading recommendations right now. Please visit our <a href="https://moneyready.org/learning-hub/">Learning Hub</a> for our latest content.</p>';
            document.getElementById('fq-results-heading').focus();
          }
        }
      };
 
      // ── Restart ──
      window.fqRestart = () => {
        ans={topics:[],experience:null,goal:null,format:null};
        resultType=null;
        document.querySelectorAll('#fq-wrap input[type=checkbox], #fq-wrap input[type=radio]').forEach(i=>{ i.checked=false; });
        document.querySelectorAll('.fq-opt-label').forEach(l=>l.classList.remove('fq-checked'));
        ['fq-n1','fq-n2','fq-n3','fq-sub'].forEach(id=>{
          const el=document.getElementById(id);
          if(el){ el.disabled=true; el.setAttribute('aria-disabled','true'); }
        });
        document.getElementById('fq-results').style.display='none';
        document.getElementById('fq-quiz').style.display='block';
        document.getElementById('fq-progress-wrap').style.display='block';
        document.querySelectorAll('.fq-step').forEach(s=>s.classList.remove('fq-active'));
        const first = document.querySelector('[data-step="1"]');
        first.classList.add('fq-active');
        setProgress(1);
        const legend = first.querySelector('legend');
        if(legend) legend.focus();
      };
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('finance_quiz', 'finance_quiz_shortcode');
 
