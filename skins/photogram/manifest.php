<?php
/**
 * SNAPSMACK - Photogram Skin Manifest
 * Alpha v0.7.4
 *
 * Mobile-first photo feed skin. Reproduces the Pixelfed / classic Instagram
 * phone-app experience: 3-column square archive grid, full-aspect post view,
 * inline likes, and a bottom-sheet comment system.
 *
 * Phase 1: Works with current snap_images schema. People and Post (+) tabs hidden.
 * Phase 2: Carousels, Good Gram-mer post tab, full Discover feed (on snap_posts migration).
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'        => 'Photogram',
    'version'     => '1.0',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'A shadow of what a photo-sharing app used to be. Phone-native layout: 3-column archive grid, full-aspect post view, inline likes, bottom-sheet comments. Reproduces the Pixelfed / classic Instagram experience in a self-hosted blog.',
    'status'      => 'stable',

    'features' => [
        'supports_wall'    => false,
        'archive_layouts'  => ['square'],
        'supports_slider'  => false,
    ],

    'require_scripts' => [
        'smack-community',
        'smack-photogram',
    ],

    'options' => [

        // ── GRID ─────────────────────────────────────────────────────────
        'pg_grid_gap' => [
            'section' => 'GRID',
            'type'    => 'select',
            'label'   => 'Grid Gap',
            'default' => 'none',
            'options' => [
                'none'     => ['label' => 'None (flush)',    'css' => ':root { --pg-grid-gap: 0px; }'],
                'hairline' => ['label' => 'Hairline (1px)', 'css' => ':root { --pg-grid-gap: 1px; }'],
                'small'    => ['label' => 'Small (2px)',    'css' => ':root { --pg-grid-gap: 2px; }'],
            ],
            'selector' => ':root',
            'property' => 'custom-pg-grid-gap',
        ],

        // ── PROFILE ───────────────────────────────────────────────────────
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
            'label'    => 'Background',
            'default'  => '#FFFFFF',
            'selector' => ':root',
            'property' => '--pg-bg',
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

    // Community component flags
    'community_comments'  => '1',
    'community_likes'     => '1',
    'community_reactions' => '0',
];
