<?php
/**
 * Money Ready – social post storage
 *
 * Registers the `fq_social_post` custom post type: one entry per distinct
 * piece of social content, however many channels it was published to.
 *
 * Why a post type rather than a custom table: it gets a WordPress admin
 * screen for the review queue for free, it can carry the same `topic`
 * taxonomy terms the Learning Hub articles use, and the library filter can
 * then treat articles and social posts identically instead of merging two
 * different shapes.
 *
 * Shape decisions, all taken from measurements against real Buffer data
 * (see CLAUDE.md "Buffer: what the real data looks like"):
 *
 *  - Channels are a SET, not a single link. 60% of items that classify as
 *    learning content were published to two or more channels, and one ran on
 *    five. Storing one URL would throw the rest away, and retrofitting later
 *    would mean a schema change and a full resync.
 *  - Identity is a fingerprint of the text, not the URL. The same content has
 *    a different URL on every channel, and the copy is edited per platform.
 *  - Nothing reaches the recommendation pool without a status of `classified`.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const FQ_SOCIAL_POST_TYPE = 'fq_social_post';

/** Meta keys. Underscore-prefixed so they stay out of the Custom Fields box. */
const FQ_META_STATUS      = '_fq_status';
const FQ_META_CHANNELS    = '_fq_channels';
const FQ_META_FINGERPRINT = '_fq_fingerprint';
const FQ_META_BUFFER_IDS  = '_fq_buffer_ids';
const FQ_META_SENT_AT     = '_fq_sent_at';
const FQ_META_REVIEWED_BY = '_fq_reviewed_by';
const FQ_META_REVIEWED_AT = '_fq_reviewed_at';
const FQ_META_REASON      = '_fq_classifier_reason';

/** The three states. Only `classified` can be recommended. */
function fq_social_statuses() {
    return [
        'classified'   => 'Approved',
        'needs_review' => 'Needs review',
        'excluded'     => 'Excluded',
    ];
}

// ============================================================
// REGISTRATION
// ============================================================

add_action( 'init', 'fq_register_social_post_type' );
function fq_register_social_post_type() {
    register_post_type( FQ_SOCIAL_POST_TYPE, [
        'labels' => [
            'name'               => 'Social posts',
            'singular_name'      => 'Social post',
            'menu_name'          => 'Social posts',
            'all_items'          => 'Social posts',
            'edit_item'          => 'Review social post',
            'search_items'       => 'Search social posts',
            'not_found'          => 'No social posts yet. They arrive from the nightly Buffer sync.',
            'not_found_in_trash' => 'No social posts in the bin.',
        ],
        // Not public: these are not pages on the site. A card links out to the
        // social platform, so there is nothing to give a permalink to.
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'show_in_menu'        => 'fq-analytics',   // groups with Quiz Analytics
        'show_in_rest'        => false,
        'supports'            => [ 'title', 'editor' ],
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
    ] );

    // Share the Learning Hub's topic vocabulary rather than inventing a second
    // one. `topic` is registered through ACF, so attach to it here rather than
    // trying to redeclare it.
    if ( taxonomy_exists( FQ_TOPIC_TAXONOMY ) ) {
        register_taxonomy_for_object_type( FQ_TOPIC_TAXONOMY, FQ_SOCIAL_POST_TYPE );
    }
}

// ============================================================
// IDENTITY — fingerprinting
// ============================================================

/**
 * A stable identity for a piece of social content.
 *
 * Copy is edited per platform, so raw text does not match across channels:
 * different @mentions, different hashtags, curly vs straight apostrophes,
 * different emoji. Measured over 314 real posts, raw matching collapsed 84
 * duplicates while this collapsed 102.
 *
 * 20 words is deliberate. Fewer over-merges — at 12 words four different job
 * adverts (Wales, South Wales, North East x2) became one item, because the
 * distinguishing words come later in the text.
 */
function fq_social_fingerprint( $text ) {
    $t = mb_strtolower( (string) $text );
    $t = preg_replace( '#https?://\S+#u', ' ', $t );       // links differ per channel
    $t = preg_replace( '/[@#]\S+/u', ' ', $t );            // mentions and hashtags differ
    $t = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $t );    // emoji, punctuation, quote styles
    $t = trim( preg_replace( '/\s+/', ' ', $t ) );

    if ( $t === '' ) return '';
    return implode( ' ', array_slice( explode( ' ', $t ), 0, 20 ) );
}

/**
 * Read a meta value that should be a list.
 *
 * An unset meta key returns '' from get_post_meta(), and `(array) ''` in PHP
 * is `['']` — a one-element array containing an empty string, not an empty
 * array. Merging that in left a phantom entry that grew with every sync.
 */
function fq_meta_array( $post_id, $key ) {
    $value = get_post_meta( $post_id, $key, true );
    return is_array( $value ) ? $value : [];
}

/** Find an existing entry by fingerprint. Returns a post ID or 0. */
function fq_find_social_post( $fingerprint ) {
    if ( $fingerprint === '' ) return 0;

    $found = get_posts( [
        'post_type'        => FQ_SOCIAL_POST_TYPE,
        'post_status'      => 'any',
        'posts_per_page'   => 1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
        'meta_query'       => [ [ 'key' => FQ_META_FINGERPRINT, 'value' => $fingerprint ] ],
    ] );
    return $found ? (int) $found[0] : 0;
}

// ============================================================
// STORAGE
// ============================================================

/**
 * Create or update one social item.
 *
 * $data: text, channels (list of ['service'=>, 'url'=>]), sent_at,
 *        buffer_ids, status, topics (slugs), reason.
 *
 * A human decision is never overwritten. Once someone has set a status by
 * hand, later syncs update the channels and text but leave the status alone —
 * otherwise the nightly job would silently undo the review queue.
 */
function fq_upsert_social_post( array $data ) {
    $text = trim( (string) ( $data['text'] ?? '' ) );
    if ( $text === '' ) return 0;

    $fingerprint = fq_social_fingerprint( $text );
    if ( $fingerprint === '' ) return 0;

    $existing = fq_find_social_post( $fingerprint );

    // A readable title for the admin list; the full text lives in the editor.
    $title = wp_trim_words( $text, 12, '…' );

    $postarr = [
        'post_type'    => FQ_SOCIAL_POST_TYPE,
        'post_title'   => $title,
        'post_content' => $text,
        'post_status'  => 'publish',   // "published into the store", not onto the site
    ];
    if ( ! empty( $data['sent_at'] ) ) {
        $postarr['post_date']     = $data['sent_at'];
        $postarr['post_date_gmt'] = get_gmt_from_date( $data['sent_at'] );
    }

    if ( $existing ) {
        $postarr['ID'] = $existing;
        $id = wp_update_post( $postarr, true );
    } else {
        $id = wp_insert_post( $postarr, true );
    }
    if ( is_wp_error( $id ) || ! $id ) return 0;

    update_post_meta( $id, FQ_META_FINGERPRINT, $fingerprint );

    // Merge channels rather than replacing, so an item picked up on Instagram
    // today and TikTok tomorrow accumulates both links.
    $channels = fq_normalise_channels( $data['channels'] ?? [] );
    if ( $existing ) {
        $channels = fq_normalise_channels(
            array_merge( fq_meta_array( $id, FQ_META_CHANNELS ), $channels )
        );
    }
    update_post_meta( $id, FQ_META_CHANNELS, $channels );

    if ( ! empty( $data['buffer_ids'] ) ) {
        $ids = array_values( array_unique( array_filter( array_merge(
            fq_meta_array( $id, FQ_META_BUFFER_IDS ),
            (array) $data['buffer_ids']
        ) ) ) );
        update_post_meta( $id, FQ_META_BUFFER_IDS, $ids );
    }
    if ( ! empty( $data['sent_at'] ) ) {
        update_post_meta( $id, FQ_META_SENT_AT, $data['sent_at'] );
    }

    // Never override a human. The review queue would be pointless otherwise.
    $reviewedBy = get_post_meta( $id, FQ_META_REVIEWED_BY, true );
    if ( $reviewedBy !== 'human' ) {
        if ( ! empty( $data['status'] ) && isset( fq_social_statuses()[ $data['status'] ] ) ) {
            update_post_meta( $id, FQ_META_STATUS, $data['status'] );
            update_post_meta( $id, FQ_META_REVIEWED_BY, 'claude' );
            update_post_meta( $id, FQ_META_REVIEWED_AT, current_time( 'mysql' ) );
        }
        if ( isset( $data['reason'] ) ) {
            update_post_meta( $id, FQ_META_REASON, sanitize_text_field( $data['reason'] ) );
        }
        if ( isset( $data['topics'] ) ) {
            fq_set_social_topics( $id, (array) $data['topics'] );
        }
    }

    return (int) $id;
}

/** Keep one URL per service, drop anything that is not a real web link. */
function fq_normalise_channels( $channels ) {
    $out = [];
    foreach ( (array) $channels as $c ) {
        $service = sanitize_key( $c['service'] ?? '' );
        $url     = esc_url_raw( $c['url'] ?? '' );
        if ( $service === '' || $url === '' ) continue;
        if ( ! preg_match( '#^https?://#i', $url ) ) continue;
        $out[ $service ] = [ 'service' => $service, 'url' => $url ];
    }
    ksort( $out );
    return array_values( $out );
}

/**
 * Apply topic terms, ignoring anything not in topics.json.
 *
 * The classifier is told never to invent topic names and did not during
 * calibration, but this is the backstop: an unknown slug would otherwise
 * create a stray taxonomy term that appears in the Learning Hub filter.
 */
function fq_set_social_topics( $post_id, array $slugs ) {
    $valid = [];
    foreach ( fq_get_topics() as $t ) {
        foreach ( $t['taxonomy_terms'] as $term ) { $valid[ $t['slug'] ] = $term; }
    }

    $terms = [];
    foreach ( $slugs as $slug ) {
        $slug = sanitize_key( $slug );
        if ( isset( $valid[ $slug ] ) ) {
            $terms[] = $valid[ $slug ];
        } elseif ( $slug !== '' ) {
            error_log( sprintf(
                '[finance-quiz] Classifier returned topic "%s", which is not in %s. Ignored.',
                $slug, FQ_TOPICS_FILE
            ) );
        }
    }
    wp_set_object_terms( $post_id, array_values( array_unique( $terms ) ), FQ_TOPIC_TAXONOMY, false );
}

// ============================================================
// READING — what the recommender sees
// ============================================================

/**
 * Approved social items, in the same shape as a Learning Hub article so the
 * library filter can treat them identically.
 *
 * `channels` is extra: the card uses the first as the destination and can
 * offer the rest as "also on".
 */
function fq_get_social_library_items() {
    $posts = get_posts( [
        'post_type'      => FQ_SOCIAL_POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => 300,
        'meta_query'     => [ [ 'key' => FQ_META_STATUS, 'value' => 'classified' ] ],
    ] );

    $map = fq_get_taxonomy_map();
    $out = [];

    foreach ( $posts as $p ) {
        $channels = fq_normalise_channels( fq_meta_array( $p->ID, FQ_META_CHANNELS ) );
        if ( ! $channels ) continue;   // nothing to link to

        $terms = wp_get_post_terms( $p->ID, FQ_TOPIC_TAXONOMY, [ 'fields' => 'slugs' ] );
        if ( is_wp_error( $terms ) ) { $terms = []; }
        $topics = array_values( array_unique( array_filter(
            array_map( fn( $t ) => $map[ $t ] ?? null, $terms )
        ) ) );
        if ( ! $topics ) continue;     // unreachable without a mapped topic

        $out[] = [
            // Backstop: a classified item should already carry a written
            // title, but never show a visitor one that trails off.
            'title'       => function_exists( 'fq_clean_title' )
                                ? fq_clean_title( $p->post_title )
                                : $p->post_title,
            'url'         => $channels[0]['url'],
            'description' => fq_cap_words( wp_strip_all_tags( $p->post_content ) ),
            'topics'      => $topics,
            'format'      => fq_social_format( $channels ),
            'channels'    => $channels,
        ];
    }
    return $out;
}

/** YouTube reads as a video; everything else reads as a social post. */
function fq_social_format( array $channels ) {
    foreach ( $channels as $c ) {
        if ( $c['service'] === 'youtube' ) return 'video';
    }
    return 'social';
}

// ============================================================
// ADMIN — the review queue
// ============================================================

add_filter( 'manage_' . FQ_SOCIAL_POST_TYPE . '_posts_columns', 'fq_social_columns' );
function fq_social_columns( $cols ) {
    return [
        'cb'         => $cols['cb'] ?? '',
        'title'      => 'Post',
        'fq_status'  => 'Status',
        'fq_topics'  => 'Topics',
        'fq_chans'   => 'Published to',
        'fq_reason'  => 'Why',
        'date'       => 'Sent',
    ];
}

add_action( 'manage_' . FQ_SOCIAL_POST_TYPE . '_posts_custom_column', 'fq_social_column_content', 10, 2 );
function fq_social_column_content( $col, $post_id ) {
    switch ( $col ) {
        case 'fq_status':
            $status = get_post_meta( $post_id, FQ_META_STATUS, true );
            $by     = get_post_meta( $post_id, FQ_META_REVIEWED_BY, true );
            $colour = [ 'classified' => '#0C7A3E', 'needs_review' => '#B35C00', 'excluded' => '#767676' ];
            printf(
                '<strong style="color:%s">%s</strong><br><span style="color:#767676;font-size:.85em">%s</span>',
                esc_attr( $colour[ $status ] ?? '#767676' ),
                esc_html( fq_social_statuses()[ $status ] ?? 'Unclassified' ),
                esc_html( $by === 'human' ? 'set by a person' : ( $by === 'claude' ? 'set by Claude' : '' ) )
            );
            break;

        case 'fq_topics':
            $terms = wp_get_post_terms( $post_id, FQ_TOPIC_TAXONOMY, [ 'fields' => 'names' ] );
            echo is_wp_error( $terms ) || ! $terms
                ? '<span style="color:#767676">—</span>'
                : esc_html( implode( ', ', $terms ) );
            break;

        case 'fq_chans':
            $channels = fq_normalise_channels( fq_meta_array( $post_id, FQ_META_CHANNELS ) );
            if ( ! $channels ) { echo '<span style="color:#767676">—</span>'; break; }
            $links = array_map(
                fn( $c ) => sprintf(
                    '<a href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url( $c['url'] ), esc_html( $c['service'] )
                ),
                $channels
            );
            echo wp_kses_post( implode( ' · ', $links ) );
            break;

        case 'fq_reason':
            $reason = get_post_meta( $post_id, FQ_META_REASON, true );
            printf(
                '<span style="color:#595959;font-size:.9em">%s</span>',
                esc_html( $reason ?: '—' )
            );
            break;
    }
}

/** Filter the list by status — the review queue is "show me needs_review". */
add_action( 'restrict_manage_posts', 'fq_social_status_filter' );
function fq_social_status_filter( $post_type ) {
    if ( $post_type !== FQ_SOCIAL_POST_TYPE ) return;
    $current = isset( $_GET['fq_status'] ) ? sanitize_key( $_GET['fq_status'] ) : '';
    echo '<select name="fq_status"><option value="">All statuses</option>';
    foreach ( fq_social_statuses() as $value => $label ) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr( $value ), selected( $current, $value, false ), esc_html( $label )
        );
    }
    echo '</select>';
}

add_action( 'pre_get_posts', 'fq_social_apply_status_filter' );
function fq_social_apply_status_filter( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) return;
    if ( ( $query->get( 'post_type' ) ?: '' ) !== FQ_SOCIAL_POST_TYPE ) return;
    if ( empty( $_GET['fq_status'] ) ) return;

    $query->set( 'meta_query', [ [
        'key'   => FQ_META_STATUS,
        'value' => sanitize_key( $_GET['fq_status'] ),
    ] ] );
}

/** Bulk approve / exclude, so a review queue can be cleared quickly. */
add_filter( 'bulk_actions-edit-' . FQ_SOCIAL_POST_TYPE, 'fq_social_bulk_actions' );
function fq_social_bulk_actions( $actions ) {
    $actions['fq_approve'] = 'Approve for recommendations';
    $actions['fq_exclude'] = 'Exclude from recommendations';
    return $actions;
}

add_filter( 'handle_bulk_actions-edit-' . FQ_SOCIAL_POST_TYPE, 'fq_social_handle_bulk', 10, 3 );
function fq_social_handle_bulk( $redirect, $action, $post_ids ) {
    $status = [ 'fq_approve' => 'classified', 'fq_exclude' => 'excluded' ][ $action ] ?? null;
    if ( ! $status ) return $redirect;

    foreach ( $post_ids as $id ) {
        if ( ! current_user_can( 'edit_post', $id ) ) continue;
        fq_set_social_status( (int) $id, $status, 'human' );
    }
    return add_query_arg( 'fq_bulk_done', count( $post_ids ), $redirect );
}

add_action( 'admin_notices', 'fq_social_bulk_notice' );
function fq_social_bulk_notice() {
    if ( empty( $_GET['fq_bulk_done'] ) ) return;
    printf(
        '<div class="notice notice-success is-dismissible"><p>Updated %d social post(s). The recommendation library refreshes within the hour.</p></div>',
        (int) $_GET['fq_bulk_done']
    );
}

/** Single place that writes a status, so the cache is always invalidated. */
function fq_set_social_status( $post_id, $status, $by = 'human' ) {
    if ( ! isset( fq_social_statuses()[ $status ] ) ) return false;
    update_post_meta( $post_id, FQ_META_STATUS, $status );
    update_post_meta( $post_id, FQ_META_REVIEWED_BY, $by );
    update_post_meta( $post_id, FQ_META_REVIEWED_AT, current_time( 'mysql' ) );
    delete_transient( fq_cache_key() );   // an approval should show up promptly
    return true;
}

/** Review box on the edit screen. */
add_action( 'add_meta_boxes', 'fq_social_meta_box' );
function fq_social_meta_box() {
    add_meta_box(
        'fq_social_review',
        'Recommendation status',
        'fq_social_meta_box_html',
        FQ_SOCIAL_POST_TYPE,
        'side',
        'high'
    );
}

function fq_social_meta_box_html( $post ) {
    wp_nonce_field( 'fq_social_review', 'fq_social_review_nonce' );
    $status = get_post_meta( $post->ID, FQ_META_STATUS, true );
    $reason = get_post_meta( $post->ID, FQ_META_REASON, true );
    $by     = get_post_meta( $post->ID, FQ_META_REVIEWED_BY, true );

    echo '<p style="color:#595959">Only approved posts can be recommended to visitors.</p>';
    foreach ( fq_social_statuses() as $value => $label ) {
        printf(
            '<label style="display:block;margin:6px 0"><input type="radio" name="fq_status" value="%s"%s> %s</label>',
            esc_attr( $value ), checked( $status, $value, false ), esc_html( $label )
        );
    }
    if ( $reason ) {
        printf(
            '<p style="margin-top:12px;color:#595959;font-size:.9em"><strong>Claude\'s reasoning:</strong><br>%s</p>',
            esc_html( $reason )
        );
    }
    if ( $by === 'human' ) {
        echo '<p style="color:#0C7A3E;font-size:.9em">Set by a person. The nightly sync will not change it.</p>';
    }
}

add_action( 'save_post_' . FQ_SOCIAL_POST_TYPE, 'fq_social_save_meta_box', 10, 2 );
function fq_social_save_meta_box( $post_id, $post ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['fq_social_review_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['fq_social_review_nonce'], 'fq_social_review' ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( ! isset( $_POST['fq_status'] ) ) return;

    fq_set_social_status( $post_id, sanitize_key( $_POST['fq_status'] ), 'human' );
}
