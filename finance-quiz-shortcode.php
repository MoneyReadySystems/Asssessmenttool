<?php
/**
 * Money Ready – Financial Education Content Recommender
 *
 * Features:
 *  - WCAG 2.1 AA compliant
 *  - Dynamic content library pulled from WordPress (auto-updates on publish)
 *  - Social posts pending: Buffer sync + Claude classification (not built)
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
// Learning Hub articles are the `library` custom post type, tagged with the
// `topic` taxonomy. The prototype had 'post' and 'library-topic' — both wrong,
// and either alone was enough to make the article query return nothing.
// 'library-topic' is the query-string name the Learning Hub filter uses in
// URLs; the theme translates it to `topic` itself (see the theme's
// class-honeycom3-library.php). There has never been a taxonomy of that name.
if ( ! defined( 'FQ_POST_TYPE' ) )          define( 'FQ_POST_TYPE',         'library' );
if ( ! defined( 'FQ_TOPIC_TAXONOMY' ) )     define( 'FQ_TOPIC_TAXONOMY',    'topic' );
if ( ! defined( 'FQ_TOPICS_FILE' ) )        define( 'FQ_TOPICS_FILE',       'topics.json' );
if ( ! defined( 'FQ_PROMPT_FILE' ) )        define( 'FQ_PROMPT_FILE',       'prompt.md' );
// Server-side wait for the Anthropic call, and the browser's own abort. The
// browser must allow longer than the server, or it gives up on a request that
// would have succeeded. Measured on the local dev stack: ~6s of that is the
// API, the rest WordPress overhead, which is far slower on Local than on real
// hosting. Re-measure on staging before tightening these.
if ( ! defined( 'FQ_API_TIMEOUT' ) )        define( 'FQ_API_TIMEOUT',       30 );
if ( ! defined( 'FQ_CLIENT_TIMEOUT' ) )     define( 'FQ_CLIENT_TIMEOUT',    35 );
if ( ! defined( 'FQ_CACHE_KEY' ) )          define( 'FQ_CACHE_KEY',         'fq_content_library' );
if ( ! defined( 'FQ_CACHE_DURATION' ) )     define( 'FQ_CACHE_DURATION',    HOUR_IN_SECONDS );
if ( ! defined( 'FQ_ANSWER_CACHE_TTL' ) )   define( 'FQ_ANSWER_CACHE_TTL',  DAY_IN_SECONDS );
if ( ! defined( 'FQ_RATE_LIMIT_MAX' ) )     define( 'FQ_RATE_LIMIT_MAX',    10 );
if ( ! defined( 'FQ_RATE_LIMIT_WINDOW' ) )  define( 'FQ_RATE_LIMIT_WINDOW', 10 * MINUTE_IN_SECONDS );
if ( ! defined( 'FQ_MIN_TIME' ) )           define( 'FQ_MIN_TIME',          8 );
if ( ! defined( 'FQ_TIME_SECRET' ) )        define( 'FQ_TIME_SECRET',       AUTH_KEY );
// 10 was set when descriptions were effectively noise. They are now real
// summaries, so it is worth giving the model more of them: 50 items at ~25
// words is a few thousand tokens, well inside a sensible prompt budget.
if ( ! defined( 'FQ_DESC_WORDS' ) )         define( 'FQ_DESC_WORDS',        25 );
// Cap on the description stored in the library and shown on a card. Without
// it, articles whose description falls back to the ACF body put a wall of
// text on the card — the first rendered test showed a ~120-word paragraph.
if ( ! defined( 'FQ_DESC_MAX_WORDS' ) )     define( 'FQ_DESC_MAX_WORDS',    40 );
if ( ! defined( 'FQ_DB_TABLE' ) )           define( 'FQ_DB_TABLE',          'fq_events' ); // without WP prefix — added automatically
if ( ! defined( 'FQ_DB_VERSION' ) )         define( 'FQ_DB_VERSION',        '1.0' ); // bump when the fq_events schema changes
if ( ! defined( 'FQ_GA4_MEASUREMENT_ID' ) ) define( 'FQ_GA4_MEASUREMENT_ID','G-XXXXXXXXXX' ); // ⚠️ Replace with your GA4 Measurement ID

// ============================================================
// COMPANION FILES
//
// social-posts.php must deploy alongside this file, as topics.json and
// prompt.md do. Loaded defensively so a partial deployment degrades to
// "no social content" rather than a fatal error on every page.
// ============================================================
foreach ( [ 'social-posts.php', 'buffer-sync.php' ] as $fq_companion ) {
    if ( is_readable( __DIR__ . '/' . $fq_companion ) ) {
        require_once __DIR__ . '/' . $fq_companion;
    } else {
        error_log( sprintf(
            '[finance-quiz] %s is missing — social content will be unavailable.', $fq_companion
        ) );
    }
}
unset( $fq_companion );

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

/**
 * The recommendation prompt template, read from prompt.md.
 *
 * Everything in that file above the `--- PROMPT ---` marker is guidance for
 * whoever edits it and is stripped here, so notes can be kept alongside the
 * prompt without being sent to Claude or billed for.
 *
 * Falls back to a built-in template if the file is missing, unreadable, or has
 * lost its {{LIBRARY}} placeholder — without the library Claude would have
 * nothing to choose from, which is worse than ignoring the file.
 */
function fq_get_prompt_template() {
    static $template = null;
    if ( $template !== null ) return $template;

    $path = __DIR__ . '/' . FQ_PROMPT_FILE;
    $raw  = is_readable( $path ) ? file_get_contents( $path ) : false;

    if ( $raw !== false ) {
        // Keep only what follows the marker, if the marker is present.
        $parts = preg_split( '/^---\s*PROMPT\s*---\s*$/m', $raw, 2 );
        $body  = trim( $parts[1] ?? $parts[0] ?? '' );

        if ( $body !== '' && strpos( $body, '{{LIBRARY}}' ) !== false ) {
            return $template = $body;
        }

        error_log( sprintf(
            '[finance-quiz] %s is missing its {{LIBRARY}} placeholder — ignoring it and using the built-in prompt. Edits to that file will have no effect until this is fixed.',
            $path
        ) );
    } else {
        error_log( sprintf( '[finance-quiz] Could not read %s — using the built-in prompt.', $path ) );
    }

    return $template = fq_get_fallback_prompt();
}

/** Safety net if prompt.md is missing or malformed. Keep in step with it. */
function fq_get_fallback_prompt() {
    return "You are recommending financial education content for Money Ready, a UK\n"
         . "financial education charity.\n\n"
         . "{{COUNT_INSTRUCTION}} from the library below, based on what this person told us.\n\n"
         . "WHAT THEY TOLD US\n"
         . "- Topics: {{TOPICS}}\n"
         . "- How confident they feel with money: {{EXPERIENCE}}\n"
         . "- Their main goal: {{GOAL}}\n"
         . "- How they prefer to learn: {{FORMAT}}\n\n"
         . "CHOOSING\n"
         . "- Only recommend items from the library. Copy each url exactly. Never invent one.\n"
         . "- Lead with what best matches their goal.\n\n"
         . "THE REASON, one per item\n"
         . "- One short sentence, speaking to them as \"you\".\n"
         . "- Say why it suits what they told us, not what the item covers.\n"
         . "- Warm and plain, never patronising. UK English.\n\n"
         . "LIBRARY\n{{LIBRARY}}";
}

/** Fill the template's placeholders. Values are substituted literally. */
function fq_build_prompt( array $vars ) {
    $template = fq_get_prompt_template();
    foreach ( $vars as $key => $value ) {
        $template = str_replace( '{{' . $key . '}}', (string) $value, $template );
    }
    return $template;
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
// SOCIAL POSTS — removed, pending the Buffer sync (decision 2)
//
// Five posts used to be hardcoded here and merged into the library. They
// have been removed rather than carried forward, because:
//
//   - They were a maintenance trap: a fixed list that silently went stale,
//     with no owner and no way for the content team to update it.
//   - One ("How to avoid overspending online") duplicated a YouTube video
//     that also reaches the pool through the Learning Hub, so the same item
//     could be recommended twice on one results page.
//   - One was tagged `property`, a topic now hidden as `planned`, so no
//     answer combination could ever surface it.
//
// Their replacement is the nightly Buffer sync writing to an
// `fq_social_post` custom post type, with Claude classifying each item
// before it can enter the pool. Until that exists the library is Learning
// Hub articles only.
//
// Do not reintroduce a hardcoded array here. If social content is needed
// before the sync is ready, add it as draft `fq_social_post` entries so it
// goes through the same review path as everything else.
// ============================================================
 
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
// LIBRARY ITEM FIELDS
//
// Both functions below exist because the obvious sources are empty. Verified
// against the real content on 2026-09-22 — do not "simplify" these back to
// reading post_content or an ACF field without re-checking.
// ============================================================

/**
 * Best available description for a library item.
 *
 * `library` posts store their body as a single ACF block, which in the
 * database is an HTML *comment* containing JSON. `wp_strip_all_tags()`
 * discards comments wholesale, so the prototype's
 * `wp_trim_words( strip_shortcodes( $post->post_content ), 30 )` returned an
 * empty string for every article — measured at 34,875 characters in, 0 out.
 * Every article would have reached Claude with a blank description.
 *
 * Preference order puts deliberate summaries ahead of raw body text:
 *   1. Yoast meta description — purpose-written, present on 31 of 52
 *   2. The excerpt — none exist today, but it outranks body text if added
 *   3. The ACF `content` field, tags stripped — present on 51 of 52, but often
 *      opens with author attribution, so it is a fallback rather than a pick
 *   4. The title, so nothing is ever described as nothing
 */
/**
 * Plain text from HTML, with block boundaries preserved as spaces.
 *
 * `wp_strip_all_tags()` alone concatenates across them, turning
 * "…a big moment.</p><p>Now comes…" into "…a big moment.Now comes…". Visible
 * on the rendered cards before this was added.
 */
/** Trim to a readable card length. */
function fq_cap_words( $text ) {
    return wp_trim_words( $text, FQ_DESC_MAX_WORDS, '…' );
}

function fq_text_from_html( $html ) {
    $spaced = preg_replace( '#<(br|/p|/h[1-6]|/li|/div|/td|/tr)\b[^>]*>#i', ' $0', (string) $html );
    return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $spaced ) ) );
}

function fq_resolve_description( $post ) {
    $yoast = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
    if ( is_string( $yoast ) && trim( $yoast ) !== '' ) {
        return fq_cap_words( fq_text_from_html( $yoast ) );
    }

    if ( has_excerpt( $post->ID ) ) {
        $excerpt = fq_text_from_html( get_the_excerpt( $post ) );
        if ( $excerpt !== '' ) return fq_cap_words( $excerpt );
    }

    $acf = get_post_meta( $post->ID, 'content', true );
    if ( is_string( $acf ) ) {
        $acf = fq_text_from_html( $acf );

        // Some articles open their body with the title as a heading, so the
        // extracted text would just repeat the title back to the model and
        // waste the description slot. Drop that prefix where real text follows
        // it — but keep the title where nothing does, rather than returning an
        // empty description. Measured 2026-09-25: 9 items echo their title,
        // and for 8 of them (the Car Ready series) the heading is all there is.
        if ( $acf !== '' && stripos( $acf, $post->post_title ) === 0 ) {
            $remainder = trim( ltrim(
                mb_substr( $acf, mb_strlen( $post->post_title ) ),
                " \t\n\r.:–—-"
            ) );
            $acf = ( str_word_count( $remainder ) >= 5 ) ? $remainder : '';
        }

        if ( $acf !== '' ) return fq_cap_words( $acf );
    }

    return $post->post_title;
}

/**
 * Best available format for a library item.
 *
 * The `fq_format` ACF field the prototype read is not set on a single post,
 * and the `library-type` taxonomy holds only abandoned placeholder terms
 * ("Type A", "Type C", zero posts each). There is nothing authoritative to
 * read, so format is inferred from an embedded video — true of 6 of the 52
 * library posts at time of verification.
 *
 * If a real format field is added later, read it here in preference.
 */
function fq_resolve_format( $post ) {
    $haystack = $post->post_content . ' ' . (string) get_post_meta( $post->ID, 'content', true );
    return preg_match( '#youtube\.com|youtu\.be|vimeo\.com|<video[\s>]#i', $haystack )
        ? 'video'
        : 'article';
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
        // No `level` here. The fq_level field it came from is set on zero
        // posts, so every article claimed to be "beginner" — a value that
        // carried no information and misled the prompt. Social posts keep
        // their own level, where it is set deliberately.
        $library[] = [
            'title'       => $post->post_title,
            'url'         => get_permalink($post->ID),
            'description' => fq_resolve_description($post),
            'topics'      => $mapped,
            'format'      => fq_resolve_format($post),
        ];
    }
    // Approved social posts, from the fq_social_post store. Empty until the
    // Buffer sync runs; nothing reaches here without a `classified` status.
    if ( function_exists( 'fq_get_social_library_items' ) ) {
        $library = array_merge( $library, fq_get_social_library_items() );
    }

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
/**
 * Last-line guard against showing the same thing twice on one results page.
 *
 * The sync already de-duplicates on a 20-word fingerprint, but a handful of
 * items still slip through — measured at 13 near-duplicate pairs in 255
 * groups. The cause is multi-word @mentions: "@Darren Collins" has "@Darren"
 * stripped and leaves "Collins" behind, while "@mrcollinsunbound" disappears
 * entirely, so every following word shifts by one and the fingerprints differ.
 *
 * This is deliberately NOT fixed by loosening the sync's matching. At the
 * similarity levels needed to catch those pairs, two genuinely different job
 * adverts ("Wales team" and "South Wales team") also merge — word-set
 * similarity cannot tell that "South" is the entire distinction.
 *
 * So storage stays permissive and presentation is strict. Comparing three to
 * five already-chosen items is cheap, and the worst case is dropping one of
 * two near-identical cards, which is the desired behaviour anyway.
 */
function fq_drop_near_duplicates($recs, $threshold = 0.9) {
    $words = function ($text) {
        $t = mb_strtolower(wp_strip_all_tags((string) $text));
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t);
        return array_unique(array_filter(explode(' ', trim(preg_replace('/\s+/', ' ', $t)))));
    };

    $kept = [];
    $keptWords = [];
    foreach ($recs as $rec) {
        $w = $words($rec['title'] ?? '');
        if (!$w) { $kept[] = $rec; $keptWords[] = $w; continue; }

        $duplicate = false;
        foreach ($keptWords as $seen) {
            if (!$seen) continue;
            $union = count(array_unique(array_merge($w, $seen)));
            $sim   = $union ? count(array_intersect($w, $seen)) / $union : 0;
            if ($sim >= $threshold) { $duplicate = true; break; }
        }
        if ($duplicate) {
            error_log(sprintf('[finance-quiz] Dropped a near-duplicate recommendation: %s', $rec['title'] ?? '?'));
            continue;
        }
        $kept[] = $rec;
        $keptWords[] = $w;
    }
    return array_values($kept);
}

/**
 * Trim the library down to what the model needs to choose well: title, url,
 * topics, format and a shortened description. `level` is deliberately dropped
 * — it is absent on articles and only set on hardcoded social posts, so
 * including it fed the prompt an inconsistent field with no signal in it.
 */
function fq_truncate_for_prompt($library) {
    return array_map(function($item) {
        return [
            'title'       => $item['title'],
            'url'         => $item['url'],
            'topics'      => (array)($item['topics'] ?? []),
            'format'      => $item['format'] ?? 'article',
            'description' => wp_trim_words($item['description'] ?? '', FQ_DESC_WORDS, '…'),
        ];
    }, $library);
}
 
// ============================================================
// ASYNC DATABASE LOGGING ENDPOINT
// Receives events from the browser after results are shown/clicked.
// Fires asynchronously — zero impact on user experience.
// ============================================================
/**
 * Decode the JSON payload from a quiz AJAX request.
 *
 * Requests are form-encoded, carrying their payload as a single JSON field,
 * because WordPress routes on `$_REQUEST['action']` (admin-ajax.php line 31)
 * and `check_ajax_referer()` reads `$_REQUEST['nonce']` — and PHP never
 * populates `$_POST` from a JSON request body.
 *
 * The prototype sent `Content-Type: application/json` with `action` and
 * `nonce` inside the JSON. admin-ajax.php therefore rejected every request
 * with HTTP 400 before any handler ran — recommendations, event logging and
 * the fallback endpoint alike. Verified empirically against this WordPress:
 * a JSON body returns 400 "0"; the same request form-encoded returns 200.
 *
 * Do not "tidy" this back to reading php://input with a JSON content type.
 */
function fq_read_payload() {
    $raw  = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
    $data = json_decode( (string) $raw, true );
    return is_array( $data ) ? $data : [];
}

add_action('wp_ajax_fq_log',        'fq_log_handler');
add_action('wp_ajax_nopriv_fq_log', 'fq_log_handler');
 
function fq_log_handler() {
    check_ajax_referer('fq_nonce', 'nonce');
 
    global $wpdb;
    $table = $wpdb->prefix . FQ_DB_TABLE;
    $raw   = fq_read_payload();
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
    $raw     = fq_read_payload();
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

        // No padding. An earlier version topped up a thin result set with
        // `level: all` items, but that field is set on zero WordPress articles
        // — so the padding could only ever have pulled in hardcoded social
        // posts, and topping up with off-topic content is what produced the
        // original bug. A genuine match set of three is a better answer than
        // three matches plus three unrelated items; $how_many below adapts to
        // however many there actually are.
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

    // The prompt lives in prompt.md so its wording and tone can be changed
    // without a code change or a deploy. See that file for what is editable.
    $prompt = fq_build_prompt([
        'COUNT_INSTRUCTION' => $how_many,
        'TOPICS'            => $topics,
        'EXPERIENCE'        => $experience,
        'GOAL'              => $goal,
        'FORMAT'            => $format,
        'LIBRARY'           => wp_json_encode($prompt_library),
    ]);

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
        'timeout' => FQ_API_TIMEOUT,
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

    $recs = fq_drop_near_duplicates($recs);

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
 
      <?php if ( function_exists( 'fq_render_sync_panel' ) ) { fq_render_sync_panel(); } ?>

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
      /* ----------------------------------------------------------------
         Styling follows the volunteer portal's conventions so the two feel
         like one product: 16px card radius, 4px button radius, the same
         two-layer shadow with a hover lift, and Open Sans for body text.
         Tokens mirror money-ready-volunteer-portal/assets/css.

         Selectors are scoped under #fq-wrap and several carry !important.
         That is not laziness — the honeycom3 theme's own label and button
         rules otherwise win, which is what made the options collapse to
         `display:inline` and shrink-wrap instead of filling the grid.
         ---------------------------------------------------------------- */
      #fq-wrap{
        --mr-pinkey:#8F0425; --mr-pinkey-dark:#6e031c; --mr-pinkey-5:#F9F2F4;
        --mr-fiver:#1C466B; --mr-vault:#767676; --mr-fine-print:#0C0C0C;
        --mr-silver:#F2F2F2; --mr-white:#fff;
        --fq-shadow:0 2px 8px rgba(0,0,0,.06), 0 8px 24px rgba(0,0,0,.08);
        --fq-shadow-hover:0 4px 12px rgba(0,0,0,.08), 0 12px 32px rgba(0,0,0,.12);
        --font-headline:'Forma DJR Display','forma-djr-display',Arial,sans-serif;
        --font-body:'Open Sans',Arial,sans-serif;
      }
      #fq-wrap *{box-sizing:border-box}
      #fq-wrap{font-family:var(--font-body);max-width:620px;margin:0 auto;border-radius:16px;overflow:hidden;box-shadow:var(--fq-shadow)}
      #fq-wrap *:focus-visible{outline:3px solid var(--mr-pinkey) !important;outline-offset:3px !important}
      #fq-wrap .fq-input-option input[type=checkbox]:focus-visible + label,
      #fq-wrap .fq-input-option input[type=radio]:focus-visible + label{outline:3px solid var(--mr-pinkey);outline-offset:3px;border-radius:8px}
      #fq-wrap [tabindex="-1"]:focus{outline:none}
      /* width:100% is load-bearing. Without it these grid items shrink-wrap
         to their text, so every option ends up a different width. The label's
         own width:100% then resolves against that shrunken box, which is why
         forcing the label alone does nothing — it has to be the wrapper. */
      #fq-wrap .fq-input-option{position:relative;display:block !important;width:100% !important}
      #fq-wrap .fq-input-option input{position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer;z-index:1}

      #fq-wrap .fq-header{background:var(--mr-pinkey);padding:26px 32px 24px;color:#fff}
      #fq-wrap .fq-header h1{font-family:var(--font-headline);font-size:1.4rem;font-weight:700;margin:0 0 6px;line-height:1.25;color:#fff}
      #fq-wrap .fq-header p{font-size:.87rem;opacity:.92;margin:0;line-height:1.55}
      #fq-wrap .fq-progress-meta{display:flex;justify-content:flex-start;font-size:.71rem;opacity:.85;margin:20px 0 7px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
      #fq-wrap .fq-progress-meta span{margin:0 !important}
      #fq-wrap .fq-progress-track{background:rgba(0,0,0,.25);border-radius:99px;height:6px;overflow:hidden}
      /* margin:0 is load-bearing. The theme applies `margin: 0 auto` to this,
         which centred the fill inside the track instead of anchoring it left,
         so the bar appeared to grow outwards from the middle. */
      #fq-wrap .fq-progress-fill{background:#fff;height:100%;width:25%;border-radius:99px;margin:0 !important;transition:width .4s ease}

      #fq-wrap .fq-body{background:#fff;padding:28px 32px 32px}
      #fq-wrap .fq-step{display:none;border:0;padding:0;margin:0}
      #fq-wrap .fq-step.fq-active{display:block}
      #fq-wrap .fq-step legend{font-family:var(--font-headline);font-size:1.12rem;font-weight:700;color:var(--mr-fine-print);margin:0 0 18px;line-height:1.35;display:block;width:100%;padding:0;float:left}
      #fq-wrap .fq-step legend + *{clear:both}
      #fq-wrap .fq-hint{font-size:.78rem;color:var(--mr-vault);margin:-10px 0 13px}

      #fq-wrap .fq-opts{display:grid;gap:10px}
      #fq-wrap .fq-opts.fq-2col{grid-template-columns:1fr 1fr}
      /* display:block !important — the theme's label rule sets `inline`,
         which collapsed these to shrink-wrapped, centred pills. */
      #fq-wrap .fq-opt-label{
        display:block !important;width:100% !important;text-align:left !important;
        border:1.5px solid #D9D9D9;border-radius:8px;padding:12px 14px;background:#fff;
        cursor:pointer;font-family:var(--font-body);font-size:.88rem;font-weight:400;
        color:var(--mr-fine-print);line-height:1.4;margin:0;
        transition:border-color .15s ease, background .15s ease, box-shadow .15s ease;
      }
      #fq-wrap .fq-opt-label:hover{border-color:var(--mr-pinkey);background:var(--mr-pinkey-5)}
      #fq-wrap .fq-opt-label.fq-checked{border-color:var(--mr-pinkey);background:var(--mr-pinkey-5);color:var(--mr-pinkey);font-weight:700}

      #fq-wrap .fq-nav{display:flex;justify-content:space-between;align-items:center;margin-top:26px;gap:12px}
      #fq-wrap .fq-btn{
        display:inline-block;padding:9px 20px;border-radius:4px;
        font-family:var(--font-body);font-size:.85rem;font-weight:700;line-height:1.4;
        cursor:pointer;border:none;text-align:center;transition:all .15s ease;
      }
      #fq-wrap .fq-back{background:var(--mr-silver);color:var(--mr-fine-print)}
      #fq-wrap .fq-back:hover{background:#e6e6e6}
      #fq-wrap .fq-next,#fq-wrap .fq-submit{background:var(--mr-pinkey);color:#fff}
      #fq-wrap .fq-next:hover,#fq-wrap .fq-submit:hover{background:var(--mr-pinkey-dark)}
      #fq-wrap .fq-next:disabled,#fq-wrap .fq-submit:disabled{background:#E3E3E3;color:#8A8A8A;cursor:not-allowed}
      #fq-wrap .fq-submit{flex:1}

      #fq-wrap .fq-loading{text-align:center;padding:48px 0}
      #fq-wrap .fq-spinner{width:44px;height:44px;border:4px solid var(--mr-silver);border-top-color:var(--mr-pinkey);border-radius:50%;animation:fqspin .85s linear infinite;margin:0 auto 18px}
      @keyframes fqspin{to{transform:rotate(360deg)}}
      #fq-wrap .fq-loading-msg{font-family:var(--font-headline);color:var(--mr-fine-print);font-weight:700;font-size:1rem;margin-bottom:5px;transition:opacity .4s ease}
      #fq-wrap .fq-loading-sub{color:var(--mr-vault);font-size:.82rem;margin:0}

      #fq-wrap #fq-results h2{font-family:var(--font-headline);font-size:1.2rem;font-weight:700;color:var(--mr-fine-print);margin:0 0 6px}
      #fq-wrap .fq-sub{color:var(--mr-vault);font-size:.85rem;margin:0 0 20px;line-height:1.5}
      #fq-wrap .fq-fallback-note{background:#FFF6EF;border:1px solid #F3C9A6;border-radius:8px;padding:11px 14px;font-size:.82rem;color:#7A3200;margin-bottom:16px}

      /* Cards: no left bar, no border. One shadow treatment everywhere, the
         same as the volunteer portal's. */
      #fq-wrap .fq-cards{display:grid;gap:14px}
      #fq-wrap .fq-card{
        display:block;background:#fff;border:none;border-radius:16px;
        padding:18px 20px;text-decoration:none;color:var(--mr-fine-print);
        box-shadow:var(--fq-shadow);
        transition:box-shadow .25s ease, transform .25s ease;
      }
      #fq-wrap .fq-card:hover{box-shadow:var(--fq-shadow-hover);transform:translateY(-2px)}
      /* Two levels, not three: the title leads, the reason supports it. */
      #fq-wrap .fq-card-title{font-family:var(--font-headline);font-weight:700;font-size:1.02rem;line-height:1.3;margin:0 0 6px;color:var(--mr-fine-print)}
      #fq-wrap .fq-card-reason{font-family:var(--font-body);font-size:.86rem;font-weight:400;line-height:1.5;color:#4A4A4A;margin:0}
      #fq-wrap .fq-card-fmt{display:inline-block;font-family:var(--font-body);font-size:.72rem;font-weight:700;color:#fff;background:var(--mr-fiver);border-radius:4px;padding:4px 10px;margin-top:12px}

      #fq-wrap .fq-cta{margin-top:22px;padding:18px 20px;background:var(--mr-fiver);border-radius:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
      #fq-wrap .fq-cta p:first-child{font-family:var(--font-headline);font-size:.92rem;font-weight:700;color:#fff;margin:0 0 2px}
      #fq-wrap .fq-cta p:last-child{font-size:.8rem;color:rgba(255,255,255,.8);margin:0}
      #fq-wrap .fq-cta a{padding:9px 20px;border-radius:4px;font-size:.85rem;font-weight:700;background:#fff;color:var(--mr-fiver);text-decoration:none;white-space:nowrap;flex-shrink:0}
      #fq-wrap .fq-restart{margin-top:14px;background:var(--mr-silver);color:var(--mr-fine-print);border:none;display:block;width:100%;padding:11px;border-radius:4px;cursor:pointer;font-family:var(--font-body);font-size:.85rem;font-weight:700;transition:all .15s ease}
      #fq-wrap .fq-restart:hover{background:#e6e6e6}

      #fq-wrap .fq-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
      @media(max-width:480px){
        #fq-wrap .fq-opts.fq-2col{grid-template-columns:1fr}
        #fq-wrap .fq-header,#fq-wrap .fq-body{padding-left:18px;padding-right:18px}
      }
    </style>
 
    <div class="fq-header">
      <!-- No logo here: the site header already carries one directly above,
           so a second is redundant. -->
      <h1>Find your perfect content</h1>
      <p>Answer 4 quick questions and we'll point you to the content that's most useful for you right now.</p>
      <div id="fq-progress-wrap">
        <div class="fq-progress-meta">
          <span id="fq-step-label">Question 1 of 4</span>
        </div>
        <div class="fq-progress-track" role="progressbar" aria-valuenow="1" aria-valuemin="1" aria-valuemax="4" aria-labelledby="fq-step-label">
          <div class="fq-progress-fill" id="fq-progress" style="width:25%"></div>
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
      // Must exceed the server's own API timeout, or the browser abandons a
      // request that would have come back.
      const CLIENT_TIMEOUT_MS = <?php echo (int) FQ_CLIENT_TIMEOUT * 1000; ?>;
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
 
      // ── AJAX helper ──
      // Form-encoded, with the payload as one JSON field. WordPress routes on
      // $_REQUEST['action'] and check_ajax_referer() reads $_REQUEST['nonce'],
      // and PHP does not populate $_POST from a JSON request body — so posting
      // application/json here gets rejected with HTTP 400 before the handler
      // runs. That is what the prototype did, on every request.
      function postAjax(action, payload, opts) {
        return fetch(AJAX, Object.assign({
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: new URLSearchParams({
            action:  action,
            nonce:   NONCE,
            payload: JSON.stringify(payload || {})
          })
        }, opts || {}));
      }

      // ── Async DB log — fire and forget, zero UX impact ──
      function dbLog(payload) {
        // keepalive lets the request finish even if the user navigates away
        postAjax('fq_log', payload, { keepalive: true }).catch(()=>{});
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
        // step/STEPS, not (step-1)/STEPS. The old formula left the bar
        // completely empty on question 1 and stopped at 75% on question 4,
        // so it never looked like it was working or finishing.
        document.getElementById('fq-progress').style.width = (step/STEPS*100)+'%';
        document.getElementById('fq-step-label').textContent = 'Question '+step+' of '+STEPS;
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
          // Two levels of information, not three. The title leads; the reason
          // supports it. The library description is deliberately not shown:
          // it duplicated the title on articles with no summary text, ran to a
          // wall of text on those that fell back to body copy, and said
          // roughly what the reason already says — better.
          card.appendChild(part('div', 'fq-card-title',  title,  false));
          if (reason) card.appendChild(part('div', 'fq-card-reason', reason, false));
          card.appendChild(part('span', 'fq-card-fmt', fmt[format]||format, true));

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
        const timer = setTimeout(()=>controller.abort(), CLIENT_TIMEOUT_MS);
 
        try {
          const res = await postAjax('fq_recommend', {
            answers:       ans,
            fq_website:    '',
            fq_time_token: TIME_TOKEN
          }, { signal: controller.signal });
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
            const fb = await postAjax('fq_fallback', {});
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
 
