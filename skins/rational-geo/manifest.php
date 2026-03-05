<?php
/**
 * SNAPSMACK - Manifest for the Rational Geo skin
 * v1.0
 *
 * An homage to the world's best magazine. For anyone who has read it
 * and loved it or dreamed of having their work published in it.
 *
 * @author Sean McCormick
 */

// Load font picker options from inventory
$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = [];
if (isset($inventory['fonts'])) {
    foreach ($inventory['fonts'] as $key => $meta) {
        $fonts[$key] = $meta['label'] ?? $key;
    }
}

return [
    'name'            => 'Rational Geo',
    'version'         => '1.0',
    'author'          => 'Sean McCormick',
    'author_email'    => 'sean@baddaywithacamera.ca',
    'description'     => 'An homage to the world\'s best magazine. Editorial serif typography, the iconic yellow accent, light and dark variants. For anyone who has read it and loved it or dreamed of having their work published in it.',
    'status'          => 'beta',

    'variants' => [
        'light' => 'Light (Magazine Interior)',
        'dark'  => 'Dark (Cover Shot)',
    ],
    'default_variant' => 'light',

    'features' => [
        'supports_wall'    => false,
        'supports_slider'  => false,
        'archive_layouts'  => ['cropped', 'masonry'],
    ],

    'require_scripts' => [
        'smack-footer',
        'smack-lightbox',
        'smack-keyboard',
        'smack-justified-lib',
        'smack-justified',
    ],

    'options' => [

        /* ============================================================
           SECTION 1: LAYOUT
           ============================================================ */

        'main_canvas_width' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Content Width (px)',
            'default'  => '1200',
            'min'      => '800',
            'max'      => '1600',
            'selector' => '.rg-header-inside, .rg-photo-wrap, .rg-drawer-inner, #browse-grid, #justified-grid, #system-footer .inside',
            'property' => 'max-width',
        ],

        'optical_lift' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Image Bottom Spacing (px)',
            'default'  => '40',
            'min'      => '0',
            'max'      => '120',
            'selector' => '#rg-photobox',
            'property' => 'padding-bottom',
        ],

        'header_height' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Header Height (px)',
            'default'  => '70',
            'min'      => '50',
            'max'      => '120',
            'selector' => '#rg-header',
            'property' => 'height',
        ],

        /* ============================================================
           SECTION 2: IMAGE PRESENTATION
           ============================================================ */

        'image_border_color' => [
            'section'  => 'IMAGE PRESENTATION',
            'type'     => 'select',
            'label'    => 'Image Border Colour',
            'default'  => 'yellow',
            'options'  => [
                'yellow' => 'NatGeo Yellow',
                'white'  => 'White',
                'black'  => 'Black',
                'grey'   => 'Grey',
                'none'   => 'No Border',
            ],
        ],

        'hero_border_width' => [
            'section'  => 'IMAGE PRESENTATION',
            'type'     => 'range',
            'label'    => 'Hero Image Border (px)',
            'default'  => '8',
            'min'      => '0',
            'max'      => '20',
        ],

        'thumb_border_width' => [
            'section'  => 'IMAGE PRESENTATION',
            'type'     => 'range',
            'label'    => 'Thumbnail Border (px)',
            'default'  => '2',
            'min'      => '0',
            'max'      => '8',
        ],

        'show_map_background' => [
            'section'  => 'IMAGE PRESENTATION',
            'type'     => 'select',
            'label'    => 'Map Background Pattern',
            'default'  => '1',
            'options'  => [
                '1' => 'Enabled',
                '0' => 'Disabled',
            ],
        ],

        /* ============================================================
           SECTION 3: ARCHIVE GRID
           ============================================================ */

        'browse_cols' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'range',
            'label'    => 'Grid Columns',
            'default'  => '4',
            'min'      => '2',
            'max'      => '6',
            'selector' => '#browse-grid',
            'css_var'  => '--grid-cols',
        ],

        'justified_row_height' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'range',
            'label'    => 'Justified Row Height (px)',
            'default'  => '260',
            'min'      => '150',
            'max'      => '500',
            'css_var'  => '--justified-row-height',
        ],

        'archive_default_layout' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'select',
            'label'    => 'Default Gallery Layout',
            'default'  => 'cropped',
            'options'  => [
                'cropped'  => 'Cropped Thumbnails',
                'masonry'  => 'Justified / Masonry',
            ],
        ],

        /* ============================================================
           SECTION 4: TYPOGRAPHY
           ============================================================ */

        'masthead_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Masthead / Title Font',
            'default'  => 'Playfair Display',
            'options'  => $fonts,
            'selector' => '.rg-masthead, .rg-photo-title, .rg-drawer-title',
            'property' => 'font-family',
        ],

        'body_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body / Caption Font',
            'default'  => 'Source Serif 4',
            'options'  => $fonts,
            'selector' => '.rg-description, .rg-drawer-inner, .rg-comment-text',
            'property' => 'font-family',
        ],

        'exif_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'EXIF / Data Font',
            'default'  => 'DM Mono',
            'options'  => $fonts,
            'selector' => '.rg-exif-table, .rg-signal-date',
            'property' => 'font-family',
        ],

        /* ============================================================
           SECTION 5: SINGLE IMAGE
           ============================================================ */

        'single_show_description' => [
            'section'  => 'SINGLE IMAGE',
            'type'     => 'select',
            'label'    => 'Show Description',
            'default'  => '1',
            'options'  => ['1' => 'Enabled', '0' => 'Disabled'],
        ],

        'single_show_signals' => [
            'section'  => 'SINGLE IMAGE',
            'type'     => 'select',
            'label'    => 'Show Signals (Comments)',
            'default'  => '1',
            'options'  => ['1' => 'Enabled', '0' => 'Disabled'],
        ],

        /* ============================================================
           SECTION 6: BLOGROLL
           ============================================================ */

        'blogroll_columns' => [
            'section'  => 'BLOGROLL',
            'type'     => 'select',
            'label'    => 'Blogroll Columns',
            'default'  => '1',
            'options'  => ['1' => 'Single Column', '2' => 'Two Columns', '3' => 'Three Columns'],
        ],

        'blogroll_max_width' => [
            'section'  => 'BLOGROLL',
            'type'     => 'range',
            'label'    => 'Blogroll Max Width (px)',
            'default'  => '900',
            'min'      => '600',
            'max'      => '1400',
            'selector' => '.blogroll-canvas',
            'property' => 'max-width',
        ],
    ],
];
