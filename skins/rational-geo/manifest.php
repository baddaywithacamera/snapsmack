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

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




// Load font picker options from inventory
$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'            => 'RATIONAL GEO',
    'version'         => '2.1.4',
    'author'          => 'Sean McCormick',
    'author_email'    => 'sean@baddaywithacamera.ca',
    'description'     => 'An homage to the world\'s best magazine. Editorial serif typography, the iconic yellow accent, light and dark variants. For anyone who has read it and loved it or dreamed of having their work published in it.',
    'status'          => 'stable',

    'variants' => [
        'light' => 'Light (Magazine Interior)',
        'dark'  => 'Dark (Cover Shot)',
    ],
    'default_variant' => 'light',

    'features' => [
        'supports_wall'        => true,
        'supports_slider'      => false,
        'has_landing'          => false,
        'post_modes'           => ['image'],
        'instagram_mode'       => false,
        'carousel'             => false,
        'community'            => ['likes', 'comments'],
        'archive_layouts'      => ['square', 'cropped', 'masonry', 'croppedwithcalendar'],
    ],

    'require_scripts' => [
        'smack-footer',
        'smack-image-fade-load',
        'smack-lightbox',
        'smack-keyboard',
        'smack-justified-lib',
        'smack-justified',
        'smack-community',
        'smack-calendar',
        'smack-archive-toggle',
    ],

    'community_comments'  => '1',
    'community_likes'     => '1',
    'community_reactions' => '0',

    'options' => [

        /* ============================================================
           SECTION 1: LAYOUT
           ============================================================ */

        'main_canvas_width' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Content Width (px)',
            'default'  => '1400',
            'min'      => '800',
            'max'      => '1920',
            'selector' => ':root',
            'property' => '--rg-canvas-width',
            'unit'     => 'px',
        ],

        'optical_lift' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Image Padding (vw)',
            'default'  => '3',
            'min'      => '1',
            'max'      => '8',
            'step'     => '0.5',
            'selector' => '.rg-photo-wrap',
            'property' => 'padding',
            'unit'     => 'vw',
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

        'infobox_height' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Infobox Height (px)',
            'default'  => '50',
            'min'      => '30',
            'max'      => '100',
            'css_var'  => '--infobox-height',
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
            'default'  => '20',
            'min'      => '0',
            'max'      => '30',
        ],

        'masonry_border_width' => [
            'section'  => 'IMAGE PRESENTATION',
            'type'     => 'range',
            'label'    => 'Masonry Border Width (px)',
            'default'  => '0',
            'min'      => '0',
            'max'      => '6',
            'unit'     => 'px',
            'selector' => ':root',
            'property' => '--masonry-border-width',
        ],

        'masonry_border_color' => [
            'section'  => 'IMAGE PRESENTATION',
            'type'     => 'color',
            'label'    => 'Masonry Border Colour',
            'default'  => '#FFCC00',
            'selector' => ':root',
            'property' => '--masonry-border-color',
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

        'map_opacity' => [
            'section'  => 'IMAGE PRESENTATION',
            'type'     => 'range',
            'label'    => 'Map Intensity (%)',
            'default'  => '30',
            'min'      => '5',
            'max'      => '80',
            'unit'     => '',
            'selector' => ':root',
            'property' => '--rg-map-pct',
        ],

        /* ============================================================
           SECTION 3: TYPOGRAPHY
           One box per font role — picker + inline size slider per card.
           ============================================================ */

        'masthead_font' => [
            'section'  => 'MASTHEAD TYPE',
            'type'     => 'select',
            'label'    => 'Masthead / Title Font',
            'default'  => 'Marcellus',
            'options'  => $fonts,
            'selector' => '.rg-masthead, .rg-photo-title, .rg-drawer-title',
            'property' => 'font-family',
            'size'     => ['default' => '1.6', 'min' => '0.8', 'max' => '3.0', 'step' => '0.1'],
        ],
        'header_text_transform' => [
            'section'  => 'MASTHEAD TYPE',
            'type'     => 'select',
            'label'    => 'Site Title Case',
            'default'  => 'uppercase',
            'options'  => [
                'uppercase'  => 'UPPERCASE',
                'lowercase'  => 'lowercase',
                'capitalize' => 'Capitalize Each Word',
                'none'       => 'As Entered (No Transform)',
            ],
            'selector' => '.rg-masthead',
            'property' => 'text-transform',
        ],

        'body_font' => [
            'section'  => 'BODY TYPE',
            'type'     => 'select',
            'label'    => 'Body / Caption Font',
            'default'  => 'Source Serif 4',
            'options'  => $fonts,
            'selector' => '.rg-description, .rg-drawer-inner',
            'property' => 'font-family',
            'size'     => ['default' => '1.0', 'min' => '0.7', 'max' => '1.6', 'step' => '0.05'],
        ],

        'exif_font' => [
            'section'  => 'EXIF TYPE',
            'type'     => 'select',
            'label'    => 'EXIF / Data Font',
            'default'  => 'DM Mono',
            'options'  => $fonts,
            'selector' => '.rg-exif-table, .rg-signal-date',
            'property' => 'font-family',
            'size'     => ['default' => '0.85', 'min' => '0.6', 'max' => '1.2', 'step' => '0.05'],
        ],

        'comment_font' => [
            'section'  => 'COMMENT TYPE',
            'type'     => 'select',
            'label'    => 'Comment Text Font',
            'default'  => 'Source Serif 4',
            'options'  => $fonts,
            'selector' => '.rg-comment-text',
            'property' => 'font-family',
            'size'     => ['default' => '1.0', 'min' => '0.7', 'max' => '1.4', 'step' => '0.05'],
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
// ===== SNAPSMACK EOF =====
