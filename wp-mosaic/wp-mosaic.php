<?php
/**
 * Plugin Name: WP Mosaic
 * Plugin URI:  https://github.com/seanmorris/wp-mosaic
 * Description: Justified image mosaic galleries via shortcode. Build tiled photo grids from the WordPress Media Library — no cropping, clean rows, responsive layout.
 * Version:     1.0.0
 * Author:      Sean Morris
 * Author URI:  https://foundtextures.ca
 * License:     GPL-2.0-or-later
 * Text Domain: wp-mosaic
 *
 * Originally the Mosaic engine from SnapSmack, adapted for WordPress.
 */

if (!defined('ABSPATH')) exit;

define('WPM_VERSION',    '1.0.0');
define('WPM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPM_PLUGIN_URL', plugin_dir_url(__FILE__));

// ─────────────────────────────────────────────────────────────
// ACTIVATION — create the mosaics table
// ─────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, 'wpm_activate');

function wpm_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . 'mosaics';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title       VARCHAR(255)    NOT NULL DEFAULT 'Untitled Mosaic',
        image_ids   LONGTEXT        NOT NULL COMMENT 'JSON array of WP attachment IDs',
        gap         TINYINT UNSIGNED NOT NULL DEFAULT 4,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('wpm_db_version', WPM_VERSION);
}

// ─────────────────────────────────────────────────────────────
// FRONT-END ASSETS
// ─────────────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', 'wpm_enqueue_frontend');

function wpm_enqueue_frontend() {
    // Only load on singular posts/pages (shortcode will be there)
    // We register unconditionally so the shortcode can wp_enqueue_script later,
    // but the CSS is cheap enough to always include.
    wp_register_script(
        'wpm-mosaic-engine',
        WPM_PLUGIN_URL . 'assets/js/mosaic-engine.js',
        [],
        WPM_VERSION,
        true
    );
    wp_register_style(
        'wpm-mosaic-engine',
        WPM_PLUGIN_URL . 'assets/css/mosaic-engine.css',
        [],
        WPM_VERSION
    );
}

// ─────────────────────────────────────────────────────────────
// SHORTCODE: [mosaic id="X"]
// ─────────────────────────────────────────────────────────────
add_shortcode('mosaic', 'wpm_shortcode');

function wpm_shortcode($atts) {
    $atts = shortcode_atts(['id' => 0], $atts, 'mosaic');
    $mosaic_id = absint($atts['id']);
    if (!$mosaic_id) return '<!-- mosaic: no id -->';

    global $wpdb;
    $table = $wpdb->prefix . 'mosaics';
    $mosaic = $wpdb->get_row($wpdb->prepare(
        "SELECT image_ids, gap FROM {$table} WHERE id = %d LIMIT 1",
        $mosaic_id
    ));

    if (!$mosaic) return '<!-- mosaic ' . $mosaic_id . ' not found -->';

    $image_ids = json_decode($mosaic->image_ids, true);
    if (empty($image_ids)) return '<!-- mosaic ' . $mosaic_id . ' has no images -->';

    // Build image data from WP attachments
    $images = [];
    foreach ($image_ids as $att_id) {
        $att_id = absint($att_id);
        $src    = wp_get_attachment_image_url($att_id, 'large');
        if (!$src) continue;

        $meta = wp_get_attachment_metadata($att_id);
        $w    = $meta['width']  ?? 800;
        $h    = $meta['height'] ?? 600;
        $alt  = get_post_meta($att_id, '_wp_attachment_image_alt', true);

        $images[] = [
            'src'    => esc_url($src),
            'width'  => (int) $w,
            'height' => (int) $h,
            'alt'    => esc_attr($alt ?: get_the_title($att_id)),
            'id'     => $att_id,
        ];
    }

    if (empty($images)) return '<!-- mosaic ' . $mosaic_id . ' has no valid images -->';

    // Enqueue assets now that we know we need them
    wp_enqueue_script('wpm-mosaic-engine');
    wp_enqueue_style('wpm-mosaic-engine');

    $gap  = absint($mosaic->gap ?: 4);
    $json = esc_attr(wp_json_encode($images));

    return '<div class="wp-mosaic" data-mosaic="' . $json . '" data-gap="' . $gap . '"></div>';
}

// ─────────────────────────────────────────────────────────────
// ADMIN MENU
// ─────────────────────────────────────────────────────────────
add_action('admin_menu', 'wpm_admin_menu');

function wpm_admin_menu() {
    add_menu_page(
        'Mosaics',
        'Mosaics',
        'edit_posts',
        'wp-mosaic',
        'wpm_admin_page',
        'dashicons-grid-view',
        26
    );
}

// ─────────────────────────────────────────────────────────────
// ADMIN ASSETS
// ─────────────────────────────────────────────────────────────
add_action('admin_enqueue_scripts', 'wpm_admin_assets');

function wpm_admin_assets($hook) {
    if ($hook !== 'toplevel_page_wp-mosaic') return;

    // WP Media Library uploader
    wp_enqueue_media();

    // Our engine for the live preview
    wp_enqueue_script('wpm-mosaic-engine', WPM_PLUGIN_URL . 'assets/js/mosaic-engine.js', [], WPM_VERSION, true);
    wp_enqueue_style('wpm-mosaic-engine',  WPM_PLUGIN_URL . 'assets/css/mosaic-engine.css', [], WPM_VERSION);
    wp_enqueue_style('wpm-admin',          WPM_PLUGIN_URL . 'assets/css/mosaic-admin.css', [], WPM_VERSION);
    wp_enqueue_script('wpm-admin',         WPM_PLUGIN_URL . 'assets/js/mosaic-admin.js', ['jquery', 'jquery-ui-sortable', 'wpm-mosaic-engine'], WPM_VERSION, true);

    wp_localize_script('wpm-admin', 'wpmData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('wpm_nonce'),
    ]);
}

// ─────────────────────────────────────────────────────────────
// AJAX HANDLERS
// ─────────────────────────────────────────────────────────────
add_action('wp_ajax_wpm_save_mosaic',   'wpm_ajax_save');
add_action('wp_ajax_wpm_delete_mosaic', 'wpm_ajax_delete');

function wpm_ajax_save() {
    check_ajax_referer('wpm_nonce', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mosaics';

    $id        = absint($_POST['mosaic_id'] ?? 0);
    $title     = sanitize_text_field($_POST['title'] ?? 'Untitled Mosaic');
    $image_ids = json_decode(wp_unslash($_POST['image_ids'] ?? '[]'), true);
    $gap       = max(0, min(20, absint($_POST['gap'] ?? 4)));

    if (empty($image_ids)) {
        wp_send_json_error('No images selected');
    }

    // Sanitize: ensure all IDs are integers
    $image_ids = array_map('absint', $image_ids);
    $json_ids  = wp_json_encode(array_values($image_ids));

    if ($id > 0) {
        $wpdb->update($table, [
            'title'     => $title,
            'image_ids' => $json_ids,
            'gap'       => $gap,
        ], ['id' => $id], ['%s', '%s', '%d'], ['%d']);
    } else {
        $wpdb->insert($table, [
            'title'     => $title,
            'image_ids' => $json_ids,
            'gap'       => $gap,
        ], ['%s', '%s', '%d']);
        $id = (int) $wpdb->insert_id;
    }

    wp_send_json_success([
        'id'        => $id,
        'shortcode' => '[mosaic id="' . $id . '"]',
    ]);
}

function wpm_ajax_delete() {
    check_ajax_referer('wpm_nonce', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permission denied');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mosaics';
    $id    = absint($_POST['mosaic_id'] ?? 0);

    if ($id > 0) {
        $wpdb->delete($table, ['id' => $id], ['%d']);
    }

    wp_send_json_success();
}

// ─────────────────────────────────────────────────────────────
// ADMIN PAGE RENDER
// ─────────────────────────────────────────────────────────────
function wpm_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'mosaics';

    $editing = null;
    if (!empty($_GET['edit'])) {
        $editing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            absint($_GET['edit'])
        ));
    }

    $mosaics = $wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC");
    ?>
    <div class="wrap">

        <?php if ($editing || isset($_GET['new'])): ?>
        <?php
            $mosaic_id    = $editing->id ?? 0;
            $mosaic_title = esc_attr($editing->title ?? 'Untitled Mosaic');
            $mosaic_ids   = $editing ? json_decode($editing->image_ids, true) : [];
            $mosaic_gap   = $editing->gap ?? 4;
        ?>

        <h1><?php echo $mosaic_id ? 'Edit Mosaic #' . $mosaic_id : 'New Mosaic'; ?></h1>
        <p><a href="<?php echo admin_url('admin.php?page=wp-mosaic'); ?>">&larr; Back to list</a></p>

        <table class="form-table">
            <tr>
                <th><label for="wpm-title">Title</label></th>
                <td><input type="text" id="wpm-title" class="regular-text" value="<?php echo $mosaic_title; ?>" placeholder="Give this mosaic a name"></td>
            </tr>
            <tr>
                <th><label for="wpm-gap">Gap (px)</label></th>
                <td>
                    <input type="number" id="wpm-gap" class="small-text" value="<?php echo $mosaic_gap; ?>" min="0" max="20">
                    <span id="wpm-shortcode" class="description" style="margin-left:12px;cursor:pointer;" title="Click to copy">
                        <?php echo $mosaic_id ? '<code>[mosaic id="' . $mosaic_id . '"]</code>' : '<em>(save to get shortcode)</em>'; ?>
                    </span>
                </td>
            </tr>
        </table>

        <h3>Selected Images <small class="description">— drag to reorder</small></h3>
        <div id="wpm-selected" class="wpm-selected-images" data-ids="<?php echo esc_attr(wp_json_encode($mosaic_ids)); ?>">
            <!-- Populated by JS -->
        </div>

        <h3>Live Preview</h3>
        <div id="wpm-preview" class="wp-mosaic wpm-preview-area">
            <p class="description">Add images to see preview</p>
        </div>

        <p class="submit">
            <button id="wpm-save" class="button button-primary" data-mosaic-id="<?php echo $mosaic_id; ?>">Save Mosaic</button>
            <button id="wpm-add-images" class="button">+ Add Images</button>
        </p>

        <?php else: ?>

        <h1>Mosaics <a href="<?php echo admin_url('admin.php?page=wp-mosaic&new=1'); ?>" class="page-title-action">Add New</a></h1>

        <?php if (empty($mosaics)): ?>
            <p>No mosaics yet. Create one to embed tiled image grids in your posts and pages.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:50px;">ID</th>
                        <th>Title</th>
                        <th style="width:80px;">Images</th>
                        <th style="width:180px;">Shortcode</th>
                        <th style="width:130px;">Updated</th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($mosaics as $m):
                    $ids   = json_decode($m->image_ids, true);
                    $count = is_array($ids) ? count($ids) : 0;
                ?>
                    <tr>
                        <td><?php echo $m->id; ?></td>
                        <td><strong><?php echo esc_html($m->title); ?></strong></td>
                        <td><?php echo $count; ?></td>
                        <td><code>[mosaic id="<?php echo $m->id; ?>"]</code></td>
                        <td><?php echo date('M j, Y', strtotime($m->updated_at)); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=wp-mosaic&edit=' . $m->id); ?>">Edit</a> |
                            <a href="#" class="wpm-delete" data-id="<?php echo $m->id; ?>" style="color:#b32d2e;">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php endif; ?>
    </div>
    <?php
}
