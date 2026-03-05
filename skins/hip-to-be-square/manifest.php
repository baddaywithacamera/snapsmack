<?php
$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];

return [
    'name' => 'Hip to be Square',
    'version' => '1.0',
    'author' => 'Sean McCormick',
    'support' => 'sean@baddaywithacamera.ca',
    'description' => "It's hip to be square. Photorealistic gallery frames with a bold teal palette, bright yellow and cyan accents, and every image cropped to a perfect square — because sometimes it's cool to be a little unconventional. Fore!",
    'status' => 'stable',

    'features' => [
        'supports_wall' => false,
        'archive_layouts' => ['square'],
        'supports_slider' => true,
    ],

    'require_scripts' => [
        'smack-footer',
        'smack-lightbox',
        'smack-keyboard',
        'smack-slider',
    ],

    'options' => [
        // GALLERY WALL section
        'htbs_wall_texture' => [
            'section' => 'GALLERY WALL',
            'type' => 'select',
            'label' => 'Wall Texture',
            'default' => 'concrete',
            'options' => [
                'smooth-plaster' => 'Smooth Plaster',
                'linen' => 'Linen Canvas',
                'concrete' => 'Concrete',
                'solid' => 'Solid Colour',
            ],
            'selector' => ':root',
            'property' => '--wall-texture',
        ],
        'htbs_wall_color' => [
            'section' => 'GALLERY WALL',
            'type' => 'color',
            'label' => 'Wall Background Colour',
            'default' => '#1a2e38',
            'selector' => 'body',
            'property' => 'background-color',
        ],

        // PICTURE FRAMES section
        'htbs_frame_color' => [
            'section' => 'PICTURE FRAMES',
            'type' => 'color',
            'label' => 'Default Frame Colour',
            'default' => '#0d1f29',
            'selector' => ':root',
            'property' => '--frame-color',
        ],
        'htbs_frame_width' => [
            'section' => 'PICTURE FRAMES',
            'type' => 'range',
            'label' => 'Frame Width (px)',
            'default' => '8',
            'min' => '3',
            'max' => '22',
            'selector' => ':root',
            'property' => '--frame-width',
            'unit' => 'px',
        ],
        'htbs_mat_color' => [
            'section' => 'PICTURE FRAMES',
            'type' => 'color',
            'label' => 'Default Mat Colour',
            'default' => '#14333f',
            'selector' => ':root',
            'property' => '--mat-color',
        ],
        'htbs_mat_width' => [
            'section' => 'PICTURE FRAMES',
            'type' => 'range',
            'label' => 'Mat Width (px)',
            'default' => '24',
            'min' => '8',
            'max' => '72',
            'selector' => ':root',
            'property' => '--mat-width',
            'unit' => 'px',
        ],
        'htbs_bevel_style' => [
            'section' => 'PICTURE FRAMES',
            'type' => 'select',
            'label' => 'Bevel Style',
            'default' => 'single',
            'options' => [
                'none' => 'No Bevel',
                'single' => 'Single Bevel',
                'double' => 'Double Bevel',
            ],
            // No selector/property — handled by PHP conditional CSS in skin-header.php
        ],
        'htbs_wood_grain' => [
            'section' => 'PICTURE FRAMES',
            'type' => 'select',
            'label' => 'Wood Grain',
            'default' => 'none',
            'options' => [
                'natural' => 'Natural Grain',
                'none' => 'Smooth (No Grain)',
            ],
            // No selector/property — handled by PHP conditional CSS in skin-header.php
        ],

        // LAYOUT section
        'htbs_header_height' => [
            'section' => 'LAYOUT',
            'type' => 'range',
            'label' => 'Header Height (px)',
            'default' => '60',
            'min' => '40',
            'max' => '100',
            'selector' => ':root',
            'property' => '--header-height',
            'unit' => 'px',
        ],
        'htbs_footer_padding' => [
            'section' => 'LAYOUT',
            'type' => 'range',
            'label' => 'Footer Padding (px)',
            'default' => '20',
            'min' => '8',
            'max' => '50',
            'selector' => ':root',
            'property' => '--footer-padding',
            'unit' => 'px',
        ],

        // SLIDER section
        'htbs_slider_per_view' => [
            'section' => 'SLIDER',
            'type' => 'select',
            'label' => 'Images Per View',
            'default' => '2',
            'options' => ['1' => '1 Image', '2' => '2 Images', '3' => '3 Images'],
            'selector' => ':root',
            'property' => '--slider-per-view',
        ],
        'htbs_slider_speed' => [
            'section' => 'SLIDER',
            'type' => 'range',
            'label' => 'Transition Speed (ms)',
            'default' => '800',
            'min' => '400',
            'max' => '1500',
            'selector' => ':root',
            'property' => '--slider-speed',
            'unit' => 'ms',
        ],
        'htbs_slider_auto' => [
            'section' => 'SLIDER',
            'type' => 'select',
            'label' => 'Auto-Advance',
            'default' => '0',
            'options' => ['0' => 'Off', '1' => 'On'],
            'selector' => ':root',
            'property' => '--slider-auto',
        ],
        'htbs_slider_loop' => [
            'section' => 'SLIDER',
            'type' => 'select',
            'label' => 'Loop',
            'default' => '1',
            'options' => ['0' => 'Off', '1' => 'On'],
            'selector' => ':root',
            'property' => '--slider-loop',
        ],

        // ARCHIVE GRID section
        'htbs_archive_cols' => [
            'section' => 'ARCHIVE GRID',
            'type' => 'range',
            'label' => 'Grid Columns',
            'default' => '4',
            'min' => '2',
            'max' => '6',
            'selector' => '.htbs-archive-grid',
            'property' => '--grid-cols',
        ],
        'htbs_archive_max_width' => [
            'section' => 'ARCHIVE GRID',
            'type' => 'range',
            'label' => 'Grid Max Width (px)',
            'default' => '1600',
            'min' => '900',
            'max' => '2400',
            'selector' => '.htbs-archive-grid',
            'property' => 'max-width',
            'unit' => 'px',
        ],
        'htbs_archive_padding' => [
            'section' => 'ARCHIVE GRID',
            'type' => 'range',
            'label' => 'Side Padding (px)',
            'default' => '60',
            'min' => '10',
            'max' => '120',
            'selector' => '.htbs-archive-grid',
            'property' => 'padding-left, padding-right',
            'unit' => 'px',
        ],
        'htbs_archive_miniframes' => [
            'section' => 'ARCHIVE GRID',
            'type' => 'select',
            'label' => 'Thumbnail Style',
            'default' => '1',
            'options' => ['1' => 'Mini Frames (scaled from hero)', '0' => 'Plain Border (3px frame colour)'],
            'selector' => ':root',
            'property' => '--archive-frames',
        ],

        // SINGLE IMAGE VIEW section
        'htbs_show_filmstrip' => [
            'section' => 'SINGLE IMAGE',
            'type' => 'select',
            'label' => 'Show Filmstrip',
            'default' => '1',
            'options' => ['1' => 'Yes', '0' => 'No'],
            'selector' => ':root',
            'property' => '--show-filmstrip',
        ],
        'htbs_plaque_style' => [
            'section' => 'SINGLE IMAGE',
            'type' => 'select',
            'label' => 'Plaque Style',
            'default' => 'classic',
            'options' => [
                'minimal' => 'Minimal',
                'classic' => 'Classic',
                'hidden' => 'Hidden',
            ],
            'selector' => ':root',
            'property' => '--plaque-style',
        ],

        // NO IMAGE CROP section — always square, enforced in skin-header.php

        // TYPOGRAPHY section
        'htbs_title_font' => [
            'section' => 'TYPOGRAPHY',
            'type' => 'select',
            'label' => 'Site Title Font',
            'default' => 'Bebas Neue',
            'options' => $fonts,
            'selector' => '.htbs-header .site-title-text',
            'property' => 'font-family',
        ],
        'htbs_title_size' => [
            'section' => 'TYPOGRAPHY',
            'type' => 'range',
            'label' => 'Site Title Size (px)',
            'default' => '22',
            'min' => '12',
            'max' => '36',
            'selector' => '.htbs-header .site-title-text',
            'property' => 'font-size',
            'unit' => 'px',
        ],
        'htbs_title_color' => [
            'section' => 'TYPOGRAPHY',
            'type' => 'color',
            'label' => 'Site Title Colour',
            'default' => '#00e5ff',
            'selector' => '.htbs-header .site-title-text',
            'property' => 'color',
        ],
        'htbs_heading_font' => [
            'section' => 'TYPOGRAPHY',
            'type' => 'select',
            'label' => 'Plaque / Heading Font',
            'default' => 'Bebas Neue',
            'options' => $fonts,
            'selector' => '.plaque-title',
            'property' => 'font-family',
        ],
        'htbs_body_font' => [
            'section' => 'TYPOGRAPHY',
            'type' => 'select',
            'label' => 'Body Font',
            'default' => 'DM Sans',
            'options' => $fonts,
            'selector' => 'body, .description, .meta',
            'property' => 'font-family',
        ],
    ],

    'admin_styling' => "
        .metadata-selector-row { display: flex; justify-content: space-between; align-items: center; margin-top: -15px; margin-bottom: 50px; }
        .skin-switcher-form { display: flex; align-items: center; gap: 10px; }
        .skin-switcher-form label { margin: 0 !important; }
        .control-group-flex { display: flex; align-items: center; gap: 20px; }
        .control-group-flex input { flex: 1; }
        .active-val { width: 50px; text-align: right; font-family: monospace; }
        .hex-display { font-family: monospace; }
    "
];
