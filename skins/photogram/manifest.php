<?php
/**
 * SNAPSMACK - Photogram Skin Manifest
 * Alpha v0.7.9
 *
 * Mobile-first photo feed skin. Reproduces the Pixelfed / classic Instagram
 * phone-app experience: 3-column square archive grid, full-aspect post view,
 * inline likes, and a bottom-sheet comment system.
 *
 * Phase 1: Works with current snap_images schema. People and Post (+) tabs hidden.
 * Phase 2: Carousels, Good Gram-mer post tab, full Discover feed (on snap_posts migration).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$_inventory_path = dirname(__DIR__, 2) . '/core/manifest-inventory.php';
$inventory = (is_file($_inventory_path)) ? include($_inventory_path) : [];
if (!is_array($inventory)) $inventory = [];
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'        => 'Photogram',
    'version'     => '2.0.7',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'A shadow of what a photo-sharing app used to be. Phone-native layout: 3-column archive grid, full-aspect post view, inline likes, bottom-sheet comments. Reproduces the Pixelfed / classic Instagram experience in a self-hosted blog.',
    'status'      => 'stable',

    'features' => [
        'supports_wall'      => false,
        'masonry_supported'  => false,
        'archive_layouts'    => ['square'],
        'supports_slider'  => false,
        'has_landing'      => true,
        'post_modes'       => ['image'],
        'instagram_mode'   => true,
        'carousel'         => true,
        'community'        => ['likes', 'comments'],
        'mobile_only'      => true,   // auto-served to phones; never shown in gallery
        'archive_filter'   => false,  // feed-style skin has its own chrome — no unified filter panel
    ],

    'require_scripts' => [
        'smack-community',
        'smack-photogram',
        'smack-photogram-feed',
        'smack-image-fade-load',
        'smack-slider',
    ],

    'options' => [

        // ── LAYOUT ───────────────────────────────────────────────────────
        'pg_column_width' => [
            'section' => 'LAYOUT',
            'type'    => 'select',
            'label'   => 'Desktop Width',
            'default' => 'phone',
            'options' => [
                'phone'     => ['label' => 'Phone Column (480px)', 'css' => ':root { --pg-column-width: 480px; }'],
                'wide'      => ['label' => 'Wide (900px)',         'css' => ':root { --pg-column-width: 900px; }'],
                'fullwidth' => ['label' => 'Full Width',          'css' => ':root { --pg-column-width: 100%; }'],
            ],
            'selector' => ':root',
            'property' => 'custom-pg-column-width',
        ],
        'pg_grid_gutter' => [
            'section' => 'LAYOUT',
            'type'    => 'select',
            'label'   => 'Side Gutter',
            'default' => 'none',
            'options' => [
                'none'   => ['label' => 'None (edge to edge)', 'css' => ':root { --pg-grid-gutter: 0px; }'],
                'small'  => ['label' => 'Small (16px)',        'css' => ':root { --pg-grid-gutter: 16px; }'],
                'medium' => ['label' => 'Medium (32px)',       'css' => ':root { --pg-grid-gutter: 32px; }'],
                'large'  => ['label' => 'Large (64px)',        'css' => ':root { --pg-grid-gutter: 64px; }'],
            ],
            'selector' => ':root',
            'property' => 'custom-pg-grid-gutter',
        ],

        // ── GRID ─────────────────────────────────────────────────────────
        'pg_grid_gap' => [
            'section' => 'GRID',
            'type'    => 'select',
            'label'   => 'Tile Spacing',
            'default' => 'none',
            'options' => [
                'none'     => ['label' => 'None',   'css' => ':root { --pg-grid-gap: 0px; }'],
                'hairline' => ['label' => '1px',    'css' => ':root { --pg-grid-gap: 1px; }'],
                'small'    => ['label' => '2px',    'css' => ':root { --pg-grid-gap: 2px; }'],
                'medium'   => ['label' => '4px',    'css' => ':root { --pg-grid-gap: 4px; }'],
                'large'    => ['label' => '8px',    'css' => ':root { --pg-grid-gap: 8px; }'],
            ],
            'selector' => ':root',
            'property' => 'custom-pg-grid-gap',
        ],
        'pg_tile_border_width' => [
            'section' => 'GRID',
            'type'    => 'select',
            'label'   => 'Tile Border',
            'default' => 'none',
            'options' => [
                'none'  => ['label' => 'None', 'css' => ':root { --pg-tile-border-width: 0px; }'],
                'thin'  => ['label' => '1px',  'css' => ':root { --pg-tile-border-width: 1px; }'],
                'thick' => ['label' => '2px',  'css' => ':root { --pg-tile-border-width: 2px; }'],
            ],
            'selector' => ':root',
            'property' => 'custom-pg-tile-border-width',
        ],
        'pg_tile_border_color' => [
            'section'  => 'GRID',
            'type'     => 'color',
            'label'    => 'Tile Border Colour',
            'default'  => '#000000',
            'selector' => ':root',
            'property' => '--pg-tile-border-color',
        ],

        // ── PROFILE ───────────────────────────────────────────────────────
        // NOTE: Photogram has no avatar upload of its own — it is the mobile half
        // of the active desktop skin and inherits THAT skin's Profile Avatar
        // (resolved in layout/feed/landing). Configure the avatar on your desktop
        // skin's settings page; it shows here automatically.
        'pg_avatar_shape' => [
            'section' => 'PROFILE',
            'type'    => 'select',
            'label'   => 'Avatar Shape',
            'default' => 'circle',
            'options' => [
                'circle'  => ['label' => 'Circle',          'css' => ':root { --pg-avatar-radius: 50%; }'],
                'rounded' => ['label' => 'Rounded Square',  'css' => ':root { --pg-avatar-radius: 12px; }'],
            ],
            'selector' => ':root',
            'property' => 'custom-pg-avatar-shape',
        ],

        // ── COLOURS ───────────────────────────────────────────────────────
        'pg_accent_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Accent Colour',
            'default'  => '#3D6B9E',
            'selector' => ':root',
            'property' => '--pg-accent',
        ],
        'pg_bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Column Background',
            'default'  => '#FFFFFF',
            'selector' => ':root',
            'property' => '--pg-bg',
        ],
        'pg_outer_bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Page Background',
            'default'  => '#EFEFEF',
            'selector' => ':root',
            'property' => '--pg-outer-bg',
        ],

        // ── COMMENTS ──────────────────────────────────────────────────────
        'pg_sheet_speed' => [
            'section' => 'COMMENTS',
            'type'    => 'select',
            'label'   => 'Sheet Animation Speed',
            'default' => 'normal',
            'options' => [
                'fast'   => ['label' => 'Fast (200ms)',   'css' => ':root { --pg-sheet-speed: 200ms; }'],
                'normal' => ['label' => 'Normal (280ms)', 'css' => ':root { --pg-sheet-speed: 280ms; }'],
                'slow'   => ['label' => 'Slow (400ms)',   'css' => ':root { --pg-sheet-speed: 400ms; }'],
            ],
            'selector' => ':root',
            'property' => 'custom-pg-sheet-speed',
        ],

        // ── NAVIGATION ────────────────────────────────────────────────────
        'pg_show_discover' => [
            'section' => 'NAVIGATION',
            'type'    => 'select',
            'label'   => 'Show Discover Tab',
            'default' => '1',
            'options' => ['1' => 'Yes', '0' => 'No'],
            // No selector/property — PHP conditional in skin-footer.php
        ],
        // ── TYPOGRAPHY ────────────────────────────────────────────────────
        'pg_body_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body Font',
            'default'  => 'Inter',
            'options'  => $fonts,
            'selector' => 'body',
            'property' => 'font-family',
        ],

    ],

    'community_comments'  => '1',
    'community_likes'     => '1',
    'community_reactions' => '0',
];
// ===== SNAPSMACK EOF =====
