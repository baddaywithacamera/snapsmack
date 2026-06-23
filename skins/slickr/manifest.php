<?php
/**
 * SNAPSMACK - Slickr Skin Manifest
 * v1.0.0
 *
 * Flickr-idiom skin for migrated Flickr archives. Justified masonry landing,
 * classic solo view with sidebar metadata, albums directory, about and blogroll.
 *
 * @author Sean McCormick
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

return [
    'name'         => 'Slickr',
    'version'      => '1.0.3',
    'author'       => 'Sean McCormick',
    'author_email' => 'sean@baddaywithacamera.ca',
    'description'  => 'Flickr, the way it was. Justified masonry archive, classic solo view with EXIF sidebar, albums directory. Built for migrated Flickr archives — includes an optional Flickr provenance badge on imported images.',
    'status'       => 'stable',

    'features' => [
        'supports_wall'     => false,
        'supports_slider'   => false,
        'has_landing'       => true,    // Flickr-profile landing (landing.php)
        'post_modes'        => ['image'],
        'instagram_mode'    => false,
        'carousel'          => false,
        'community'         => ['likes', 'comments'],
        'archive_layouts'   => ['justified', 'cropped'],
    ],

    'require_scripts' => [
        'smack-footer',
        'smack-image-fade-load',
        'smack-justified-lib',
        'smack-justified',
        'smack-community',
        'smack-keyboard',
        'smack-archive-toggle',
    ],

    'community_comments'  => '1',
    'community_likes'     => '1',
    'community_reactions' => '0',

    'options' => [

        // ── LAYOUT ───────────────────────────────────────────────────────────
        'main_canvas_width' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Content Width (px)',
            'default'  => '1400',
            'min'      => '800',
            'max'      => '1920',
            'selector' => ':root',
            'property' => '--sl-canvas-width',
            'unit'     => 'px',
        ],

        'justified_row_height' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Justified Row Height (px)',
            'default'  => '240',
            'min'      => '120',
            'max'      => '480',
            'selector' => ':root',
            'property' => '--justified-row-height',
            'unit'     => 'px',
        ],

        'archive_layout' => [
            'section' => 'LAYOUT',
            'type'    => 'select',
            'label'   => 'Default Archive Layout',
            'default' => 'justified',
            'options' => [
                'justified' => 'Justified Masonry (Flickr-style)',
                'cropped'   => 'Cropped Grid',
            ],
        ],

        // ── SINGLE IMAGE ─────────────────────────────────────────────────────
        'solo_bg_color' => [
            'section'  => 'SINGLE IMAGE',
            'type'     => 'color',
            'label'    => 'Image Background Colour',
            'default'  => '#1a1a1a',
            'selector' => '#sl-photobox',
            'property' => 'background-color',
        ],

        'single_show_description' => [
            'section' => 'SINGLE IMAGE',
            'type'    => 'select',
            'label'   => 'Show Description',
            'default' => '1',
            'options' => ['1' => 'Enabled', '0' => 'Disabled'],
        ],

        'show_exif_panel' => [
            'section' => 'SINGLE IMAGE',
            'type'    => 'select',
            'label'   => 'Show EXIF / Technical Details',
            'default' => '1',
            'options' => ['1' => 'Enabled', '0' => 'Disabled'],
        ],

        'show_geo_link' => [
            'section' => 'SINGLE IMAGE',
            'type'    => 'select',
            'label'   => 'Show Map Link (OpenStreetMap)',
            'default' => '1',
            'options' => ['1' => 'Enabled', '0' => 'Disabled'],
        ],

        'show_provenance_footer' => [
            'section' => 'SINGLE IMAGE',
            'type'    => 'select',
            'label'   => 'Show Flickr Migration Badge',
            'default' => '1',
            'options' => ['1' => 'Show on Flickr-imported images', '0' => 'Hidden'],
        ],

        // ── BLOGROLL ─────────────────────────────────────────────────────────
        'blogroll_columns' => [
            'section' => 'BLOGROLL',
            'type'    => 'select',
            'label'   => 'Blogroll Columns',
            'default' => '1',
            'options' => ['1' => 'Single Column', '2' => 'Two Columns', '3' => 'Three Columns'],
        ],

    ],
];
// ===== SNAPSMACK EOF =====
