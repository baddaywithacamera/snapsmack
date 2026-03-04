<?php
/**
 * SNAPSMACK - Configuration manifest for the picasa-web-albums skin
 * v1.0
 *
 * Clean, white-space-rich gallery skin inspired by Google's Picasa Web Albums.
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];

return [
    'name'          => 'Picasa Web Albums',
    'version'       => '1.0',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'Clean, white-space-rich gallery skin inspired by Google\'s Picasa Web Albums. Light and dark variants. Album grid landing, filmstrip viewer, slideshow mode.',
    'status'        => 'stable',

    'variants' => [
        'light' => 'Light',
        'dark'  => 'Dark',
    ],
    'default_variant' => 'light',

    'features' => [
        'supports_wall'   => false,
        'supports_slider' => true,
        'archive_layouts' => ['square'],
    ],

    'require_scripts' => [
        'smack-footer',
        'smack-lightbox',
        'smack-keyboard',
        'smack-slider',
    ],

    'options' => [

        /* -----------------------------------------------------------------
           SECTION 1: LAYOUT
           ----------------------------------------------------------------- */

        'landing_mode' => [
            'section' => 'LAYOUT',
            'type'    => 'select',
            'label'   => 'Landing Mode',
            'default' => 'albums-grid',
            'options' => [
                'albums-grid' => 'Album Grid',
                'flat-grid'   => 'Flat Thumbnail Grid',
            ],
        ],

        'grid_columns_album' => [
            'section' => 'LAYOUT',
            'type'    => 'range',
            'label'   => 'Album Grid Columns',
            'default' => '4',
            'min'     => '3',
            'max'     => '5',
        ],

        'grid_columns_interior' => [
            'section' => 'LAYOUT',
            'type'    => 'range',
            'label'   => 'Interior Grid Columns',
            'default' => '5',
            'min'     => '4',
            'max'     => '6',
        ],

        'show_sidebar' => [
            'section' => 'LAYOUT',
            'type'    => 'select',
            'label'   => 'Show Sidebar',
            'default' => '0',
            'options' => ['1' => 'Enabled', '0' => 'Disabled'],
        ],

        'show_header_nav' => [
            'section' => 'LAYOUT',
            'type'    => 'select',
            'label'   => 'Show Header Navigation',
            'default' => '1',
            'options' => ['1' => 'Enabled', '0' => 'Disabled'],
        ],

        /* -----------------------------------------------------------------
           SECTION 2: APPEARANCE
           ----------------------------------------------------------------- */

        'background_color' => [
            'section'  => 'APPEARANCE',
            'type'     => 'color',
            'label'    => 'Background Color',
            'default'  => '#ffffff',
            'selector' => 'body',
            'property' => 'background-color',
        ],

        'accent_color' => [
            'section'  => 'APPEARANCE',
            'type'     => 'color',
            'label'    => 'Accent Color (Links & Highlights)',
            'default'  => '#4d90fe',
            'selector' => ':root',
            'property' => '--pwa-accent',
        ],

        'border_color' => [
            'section'  => 'APPEARANCE',
            'type'     => 'color',
            'label'    => 'Border Color',
            'default'  => '#e5e5e5',
            'selector' => ':root',
            'property' => '--pwa-border',
        ],

        'thumbnail_border_radius' => [
            'section'  => 'APPEARANCE',
            'type'     => 'range',
            'label'    => 'Thumbnail Border Radius (px)',
            'default'  => '4',
            'min'      => '0',
            'max'      => '12',
            'selector' => ':root',
            'property' => '--pwa-thumb-radius',
        ],

        'thumbnail_shadow' => [
            'section' => 'APPEARANCE',
            'type'    => 'select',
            'label'   => 'Thumbnail Shadow',
            'default' => '1',
            'options' => ['1' => 'Enabled', '0' => 'Disabled'],
        ],

        /* -----------------------------------------------------------------
           SECTION 3: LIGHTBOX / VIEWER
           ----------------------------------------------------------------- */

        'lightbox_background' => [
            'section' => 'LIGHTBOX',
            'type'    => 'select',
            'label'   => 'Viewer Background',
            'default' => 'white',
            'options' => [
                'white'      => 'White',
                'light-grey' => 'Light Grey',
                'dark'       => 'Dark',
            ],
        ],

        'lightbox_filmstrip' => [
            'section' => 'LIGHTBOX',
            'type'    => 'select',
            'label'   => 'Filmstrip',
            'default' => '1',
            'options' => ['1' => 'Show', '0' => 'Hide'],
        ],

        'lightbox_show_exif' => [
            'section' => 'LIGHTBOX',
            'type'    => 'select',
            'label'   => 'Show EXIF Data',
            'default' => '0',
            'options' => ['1' => 'Show', '0' => 'Hide (Behind Details Toggle)'],
        ],

        /* -----------------------------------------------------------------
           SECTION 4: SLIDESHOW
           ----------------------------------------------------------------- */

        'slideshow_interval' => [
            'section' => 'SLIDESHOW',
            'type'    => 'range',
            'label'   => 'Slideshow Interval (seconds)',
            'default' => '5',
            'min'     => '3',
            'max'     => '10',
        ],

        'slideshow_transition' => [
            'section' => 'SLIDESHOW',
            'type'    => 'select',
            'label'   => 'Slideshow Transition',
            'default' => 'crossfade',
            'options' => ['crossfade' => 'Crossfade', 'slide' => 'Slide'],
        ],

        /* -----------------------------------------------------------------
           SECTION 5: SINGLE IMAGE
           ----------------------------------------------------------------- */

        'single_show_description' => [
            'section' => 'SINGLE IMAGE',
            'type'    => 'select',
            'label'   => 'Show Description',
            'default' => '1',
            'options' => ['1' => 'Show', '0' => 'Hide'],
        ],

        'single_show_signals' => [
            'section' => 'SINGLE IMAGE',
            'type'    => 'select',
            'label'   => 'Show Signals (Comments)',
            'default' => '1',
            'options' => ['1' => 'Show', '0' => 'Hide'],
        ],

        'single_show_download' => [
            'section' => 'SINGLE IMAGE',
            'type'    => 'select',
            'label'   => 'Show Download Button',
            'default' => '1',
            'options' => ['1' => 'Show', '0' => 'Hide'],
        ],

        /* -----------------------------------------------------------------
           SECTION 6: TYPOGRAPHY
           ----------------------------------------------------------------- */

        'heading_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Heading Font',
            'default'  => 'Open Sans',
            'options'  => $fonts,
            'selector' => '.pwa-header-logo, .pwa-album-title, .pwa-image-title',
            'property' => 'font-family',
        ],

        'body_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body Font',
            'default'  => 'Open Sans',
            'options'  => $fonts,
            'selector' => 'body',
            'property' => 'font-family',
        ],
    ],
];
