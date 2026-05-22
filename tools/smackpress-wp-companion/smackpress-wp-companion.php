<?php
/**
 * Plugin Name: SmackPress WP Companion
 * Plugin URI:  https://snapsmack.ca
 * Description: Disposable bridge for SmackPress migration. Exposes a minimal REST API
 *              so the SmackPress desktop tool can pull posts from WordPress and mark
 *              migrated posts as private. Authenticate with a WordPress Application Password.
 *              Delete this plugin once migration is complete.
 * Version:     1.0.0
 * Author:      SnapSmack
 * License:     GPL-2.0-or-later
 *
 * SNAPSMACK_EOF_HEADER
 *     # ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

// # ===== SNAPSMACK EOF =====
// (This file uses PHP comment style; see bottom of file for the real marker.)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -----------------------------------------------------------------------
 * Constants
 * --------------------------------------------------------------------- */

define( 'SMACKPRESS_COMPANION_VERSION', '1.0.0' );
define( 'SMACKPRESS_NS',  'smackpress/v1' );
define( 'SMACKPRESS_META', 'smackpress_migrated_to' );

/* -----------------------------------------------------------------------
 * Bootstrap
 * --------------------------------------------------------------------- */

add_action( 'rest_api_init', 'smackpress_register_routes' );
add_action( 'init',          'smackpress_register_meta' );

/* -----------------------------------------------------------------------
 * Meta registration
 * Registering with show_in_rest:true lets the WP REST API read/write it
 * without custom code — SmackPress writes it via the /hide endpoint.
 * --------------------------------------------------------------------- */

function smackpress_register_meta() {
    register_post_meta( 'post', SMACKPRESS_META, [
        'type'         => 'string',
        'description'  => 'SnapSmack post URL this WP post was migrated to.',
        'single'       => true,
        'show_in_rest' => true,
        'auth_callback' => '__return_true',
    ] );
}

/* -----------------------------------------------------------------------
 * Route registration
 * --------------------------------------------------------------------- */

function smackpress_register_routes() {

    // GET /wp-json/smackpress/v1/posts
    // GET /wp-json/smackpress/v1/posts?page=2&per_page=20&status=publish,private
    register_rest_route( SMACKPRESS_NS, '/posts', [
        'methods'             => 'GET',
        'callback'            => 'smackpress_get_posts',
        'permission_callback' => 'smackpress_auth',
        'args'                => [
            'page'     => [ 'default' => 1,    'sanitize_callback' => 'absint' ],
            'per_page' => [ 'default' => 20,   'sanitize_callback' => 'absint' ],
            'status'   => [ 'default' => 'publish', 'sanitize_callback' => 'sanitize_text_field' ],
            'search'   => [ 'default' => '',   'sanitize_callback' => 'sanitize_text_field' ],
            'category' => [ 'default' => 0,    'sanitize_callback' => 'absint' ],
        ],
    ] );

    // GET /wp-json/smackpress/v1/posts/{id}
    register_rest_route( SMACKPRESS_NS, '/posts/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'smackpress_get_post',
        'permission_callback' => 'smackpress_auth',
        'args'                => [
            'id' => [ 'validate_callback' => function( $v ) { return is_numeric( $v ); } ],
        ],
    ] );

    // POST /wp-json/smackpress/v1/posts/{id}/hide
    register_rest_route( SMACKPRESS_NS, '/posts/(?P<id>\d+)/hide', [
        'methods'             => 'POST',
        'callback'            => 'smackpress_hide_post',
        'permission_callback' => 'smackpress_auth',
        'args'                => [
            'id'              => [ 'validate_callback' => function( $v ) { return is_numeric( $v ); } ],
            'migrated_to_url' => [ 'default' => '', 'sanitize_callback' => 'esc_url_raw' ],
        ],
    ] );

    // GET /wp-json/smackpress/v1/categories
    register_rest_route( SMACKPRESS_NS, '/categories', [
        'methods'             => 'GET',
        'callback'            => 'smackpress_get_categories',
        'permission_callback' => 'smackpress_auth',
    ] );

    // GET /wp-json/smackpress/v1/status
    register_rest_route( SMACKPRESS_NS, '/status', [
        'methods'             => 'GET',
        'callback'            => 'smackpress_get_status',
        'permission_callback' => 'smackpress_auth',
    ] );
}

/* -----------------------------------------------------------------------
 * Auth: require a valid WordPress Application Password
 * --------------------------------------------------------------------- */

function smackpress_auth( WP_REST_Request $request ) {
    // WP Application Passwords set current_user_id during REST auth.
    // require_once is already handled by WP REST bootstrap.
    $user = wp_get_current_user();
    if ( ! $user || ! $user->exists() ) {
        return new WP_Error(
            'smackpress_unauthorized',
            'SmackPress: valid Application Password required.',
            [ 'status' => 401 ]
        );
    }
    // Must have edit_posts capability (i.e., Editor or Administrator).
    if ( ! $user->has_cap( 'edit_posts' ) ) {
        return new WP_Error(
            'smackpress_forbidden',
            'SmackPress: insufficient capability.',
            [ 'status' => 403 ]
        );
    }
    return true;
}

/* -----------------------------------------------------------------------
 * GET /posts  — paginated list with expanded galleries
 * --------------------------------------------------------------------- */

function smackpress_get_posts( WP_REST_Request $request ) {
    $page     = max( 1, $request->get_param( 'page' ) );
    $per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
    $search   = $request->get_param( 'search' );
    $category = $request->get_param( 'category' );

    // Allow comma-separated status list, e.g. "publish,private"
    $raw_status = $request->get_param( 'status' );
    $statuses   = array_map( 'sanitize_key', explode( ',', $raw_status ) );
    $allowed    = [ 'publish', 'private', 'draft', 'pending', 'future', 'trash', 'any' ];
    $statuses   = array_values( array_intersect( $statuses, $allowed ) );
    if ( empty( $statuses ) ) {
        $statuses = [ 'publish' ];
    }

    $args = [
        'post_type'      => 'post',
        'post_status'    => $statuses,
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    if ( $search !== '' ) {
        $args['s'] = $search;
    }
    if ( $category > 0 ) {
        $args['cat'] = $category;
    }

    $query = new WP_Query( $args );
    $total       = (int) $query->found_posts;
    $total_pages = (int) $query->max_num_pages;

    $posts = [];
    foreach ( $query->posts as $post ) {
        $posts[] = smackpress_shape_post( $post, false );
    }
    wp_reset_postdata();

    return rest_ensure_response( [
        'posts'       => $posts,
        'total'       => $total,
        'total_pages' => $total_pages,
        'page'        => $page,
        'per_page'    => $per_page,
    ] );
}

/* -----------------------------------------------------------------------
 * GET /posts/{id}  — single post, full gallery expansion
 * --------------------------------------------------------------------- */

function smackpress_get_post( WP_REST_Request $request ) {
    $id   = (int) $request->get_param( 'id' );
    $post = get_post( $id );

    if ( ! $post || $post->post_type !== 'post' ) {
        return new WP_Error( 'smackpress_not_found', 'Post not found.', [ 'status' => 404 ] );
    }

    return rest_ensure_response( smackpress_shape_post( $post, true ) );
}

/* -----------------------------------------------------------------------
 * POST /posts/{id}/hide
 * Sets the WP post to private and records the SnapSmack URL it migrated to.
 * --------------------------------------------------------------------- */

function smackpress_hide_post( WP_REST_Request $request ) {
    $id          = (int) $request->get_param( 'id' );
    $migrated_to = $request->get_param( 'migrated_to_url' );

    $post = get_post( $id );
    if ( ! $post || $post->post_type !== 'post' ) {
        return new WP_Error( 'smackpress_not_found', 'Post not found.', [ 'status' => 404 ] );
    }

    // Set to private
    $result = wp_update_post( [
        'ID'          => $id,
        'post_status' => 'private',
    ], true );

    if ( is_wp_error( $result ) ) {
        return new WP_Error(
            'smackpress_update_failed',
            $result->get_error_message(),
            [ 'status' => 500 ]
        );
    }

    // Record where it went
    if ( $migrated_to !== '' ) {
        update_post_meta( $id, SMACKPRESS_META, $migrated_to );
    }

    return rest_ensure_response( [
        'id'           => $id,
        'status'       => 'private',
        'migrated_to'  => $migrated_to ?: null,
    ] );
}

/* -----------------------------------------------------------------------
 * GET /categories
 * --------------------------------------------------------------------- */

function smackpress_get_categories( WP_REST_Request $request ) {
    $terms = get_terms( [
        'taxonomy'   => 'category',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );

    if ( is_wp_error( $terms ) ) {
        return new WP_Error( 'smackpress_terms_error', $terms->get_error_message(), [ 'status' => 500 ] );
    }

    $categories = array_map( function( $term ) {
        return [
            'id'     => $term->term_id,
            'name'   => $term->name,
            'slug'   => $term->slug,
            'count'  => $term->count,
            'parent' => $term->parent,
        ];
    }, $terms );

    return rest_ensure_response( [ 'categories' => $categories ] );
}

/* -----------------------------------------------------------------------
 * GET /status  — connectivity check, returns plugin + site info
 * --------------------------------------------------------------------- */

function smackpress_get_status( WP_REST_Request $request ) {
    return rest_ensure_response( [
        'plugin_version' => SMACKPRESS_COMPANION_VERSION,
        'site_url'       => get_site_url(),
        'site_name'      => get_bloginfo( 'name' ),
        'wp_version'     => get_bloginfo( 'version' ),
        'user'           => wp_get_current_user()->user_login,
    ] );
}

/* -----------------------------------------------------------------------
 * Helper: shape a post object for the API response
 *
 * $full = true  → expand gallery shortcodes, resolve all image URLs (single post)
 * $full = false → skip gallery expansion (list view, faster)
 * --------------------------------------------------------------------- */

function smackpress_shape_post( WP_Post $post, bool $full ): array {
    // Tags and categories
    $tags = get_the_tags( $post->ID );
    $tags = $tags ? array_map( fn( $t ) => $t->name, $tags ) : [];

    $cats = get_the_category( $post->ID );
    $cats = $cats ? array_map( fn( $c ) => [ 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug ], $cats ) : [];

    // Featured image
    $featured_image = null;
    $thumb_id = get_post_thumbnail_id( $post->ID );
    if ( $thumb_id ) {
        $featured_image = smackpress_image_data( $thumb_id );
    }

    // Migrated-to meta
    $migrated_to = get_post_meta( $post->ID, SMACKPRESS_META, true ) ?: null;

    $shaped = [
        'id'             => $post->ID,
        'date'           => $post->post_date,
        'date_gmt'       => $post->post_date_gmt,
        'slug'           => $post->post_name,
        'status'         => $post->post_status,
        'title'          => $post->post_title,
        'excerpt'        => smackpress_clean_excerpt( $post ),
        'comment_count'  => (int) $post->comment_count,
        'categories'     => $cats,
        'tags'           => $tags,
        'featured_image' => $featured_image,
        'migrated_to'    => $migrated_to,
        'link'           => get_permalink( $post->ID ),
    ];

    if ( $full ) {
        // Raw content + expanded version
        $raw     = $post->post_content;
        $expanded = smackpress_expand_content( $raw, $post->ID );

        $shaped['content_raw']      = $raw;
        $shaped['content_expanded'] = $expanded['content'];
        $shaped['images']           = $expanded['images'];  // all resolved image objects
    }

    return $shaped;
}

/* -----------------------------------------------------------------------
 * Gallery expansion
 * Replaces [gallery ids="1,2,3"] and [gallery] shortcodes with inline
 * image data so the Python client doesn't need to make N extra calls.
 * Also collects every image attachment referenced in the post.
 * --------------------------------------------------------------------- */

function smackpress_expand_content( string $content, int $post_id ): array {
    $images = [];   // keyed by attachment ID to avoid dupes

    // Find all gallery shortcodes
    $content = preg_replace_callback(
        '/\[gallery([^\]]*)\]/',
        function( $matches ) use ( &$images, $post_id ) {
            $atts_raw = $matches[1];
            $ids = [];

            // Try ids="..." attribute first
            if ( preg_match( '/ids=["\']([^"\']+)["\']/', $atts_raw, $m ) ) {
                $ids = array_map( 'absint', explode( ',', $m[1] ) );
            }

            // Fallback: all image attachments of this post
            if ( empty( $ids ) ) {
                $attachments = get_posts( [
                    'post_type'      => 'attachment',
                    'post_mime_type' => 'image',
                    'post_parent'    => $post_id,
                    'posts_per_page' => -1,
                    'orderby'        => 'menu_order',
                    'order'          => 'ASC',
                    'fields'         => 'ids',
                ] );
                $ids = $attachments ?: [];
            }

            $block_lines = [];
            foreach ( $ids as $att_id ) {
                if ( ! isset( $images[ $att_id ] ) ) {
                    $images[ $att_id ] = smackpress_image_data( $att_id );
                }
                $img = $images[ $att_id ];
                // Replace shortcode with a line the Python client can parse
                $block_lines[] = '[smackpress-image id="' . $att_id . '" url="' . $img['url'] . '"]';
            }

            return implode( "\n", $block_lines );
        },
        $content
    );

    // Also catch bare <img> tags that point to media library uploads
    $upload_dir = wp_upload_dir();
    $base_url   = $upload_dir['baseurl'];

    preg_match_all( '/<img[^>]+src=["\'](' . preg_quote( $base_url, '/' ) . '[^"\']+)["\'][^>]*>/i', $content, $img_matches );
    foreach ( $img_matches[1] as $src_url ) {
        $att_id = smackpress_url_to_attachment_id( $src_url );
        if ( $att_id && ! isset( $images[ $att_id ] ) ) {
            $images[ $att_id ] = smackpress_image_data( $att_id );
        }
    }

    return [
        'content' => $content,
        'images'  => array_values( $images ),
    ];
}

/* -----------------------------------------------------------------------
 * Build a normalised image data object from an attachment ID.
 * Includes: id, url (full), thumbnail url, width, height, alt, caption,
 * filename, filesize (if readable), and basic EXIF subset.
 * --------------------------------------------------------------------- */

function smackpress_image_data( int $att_id ): array {
    $full   = wp_get_attachment_image_src( $att_id, 'full' );
    $thumb  = wp_get_attachment_image_src( $att_id, 'thumbnail' );
    $meta   = wp_get_attachment_metadata( $att_id );
    $post   = get_post( $att_id );
    $alt    = get_post_meta( $att_id, '_wp_attachment_image_alt', true );

    $url    = $full  ? $full[0]  : '';
    $width  = $full  ? (int) $full[1] : 0;
    $height = $full  ? (int) $full[2] : 0;

    // Physical file path for filesize
    $filepath = get_attached_file( $att_id );
    $filesize = ( $filepath && file_exists( $filepath ) ) ? filesize( $filepath ) : null;

    // EXIF subset (date taken, camera, focal length, aperture, ISO)
    $exif = [];
    if ( ! empty( $meta['image_meta'] ) ) {
        $m = $meta['image_meta'];
        if ( ! empty( $m['created_timestamp'] ) && $m['created_timestamp'] > 0 ) {
            $exif['date_taken'] = date( 'Y-m-d H:i:s', (int) $m['created_timestamp'] );
        }
        foreach ( [ 'camera', 'focal_length', 'aperture', 'iso', 'shutter_speed' ] as $key ) {
            if ( ! empty( $m[ $key ] ) ) {
                $exif[ $key ] = $m[ $key ];
            }
        }
    }

    return [
        'id'          => $att_id,
        'url'         => $url,
        'thumbnail'   => $thumb ? $thumb[0] : $url,
        'width'       => $width,
        'height'      => $height,
        'alt'         => $alt ?: '',
        'caption'     => $post ? wp_strip_all_tags( $post->post_excerpt ) : '',
        'title'       => $post ? $post->post_title : '',
        'filename'    => $meta['file'] ?? '',
        'filesize'    => $filesize,
        'mime_type'   => get_post_mime_type( $att_id ) ?: '',
        'exif'        => $exif,
    ];
}

/* -----------------------------------------------------------------------
 * Convert an image URL back to an attachment ID (cached via WP object cache)
 * --------------------------------------------------------------------- */

function smackpress_url_to_attachment_id( string $url ): int {
    global $wpdb;
    $cache_key = 'smackpress_att_' . md5( $url );
    $cached    = wp_cache_get( $cache_key );
    if ( $cached !== false ) {
        return (int) $cached;
    }

    // Strip size suffix (-150x150) before querying
    $clean = preg_replace( '/-\d+x\d+(\.[a-z]+)$/i', '$1', $url );
    $id    = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE guid = %s
               AND post_type = 'attachment'
             LIMIT 1",
            $clean
        )
    );

    // Fall back to _wp_attached_file meta
    if ( ! $id ) {
        $upload_dir = wp_upload_dir();
        $relative   = str_replace( trailingslashit( $upload_dir['baseurl'] ), '', $clean );
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wp_attached_file'
                   AND meta_value = %s
                 LIMIT 1",
                $relative
            )
        );
    }

    $id = $id ? (int) $id : 0;
    wp_cache_set( $cache_key, $id, '', 3600 );
    return $id;
}

/* -----------------------------------------------------------------------
 * Clean excerpt: return post excerpt or auto-generate from content
 * --------------------------------------------------------------------- */

function smackpress_clean_excerpt( WP_Post $post ): string {
    if ( $post->post_excerpt ) {
        return wp_strip_all_tags( $post->post_excerpt );
    }
    $text = wp_strip_all_tags( $post->post_content );
    $words = explode( ' ', $text );
    if ( count( $words ) > 55 ) {
        return implode( ' ', array_slice( $words, 0, 55 ) ) . '...';
    }
    return $text;
}

// ===== SNAPSMACK EOF =====
