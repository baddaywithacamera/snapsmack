<?php
/**
 * SnapSmack Skin Manifest: Fifty Shades of Grey
 * Version: 2.0
 * -------------------------------------------------------------------------
 * NHD structure. fsog- selectors. Grey palette. No colour.
 * -------------------------------------------------------------------------
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];

return [
    'name'          => 'Fifty Shades of Grey',
    'version'       => '2.0',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'Pure greyscale photography skin. Three monochrome variants with zero colour accents.',

    'features' => [
        'supports_wall' => true,
    ],

    // VARIANT SYSTEM
    'variants' => [
        'dark'   => 'Dark (Near Black)',
        'medium' => 'Medium (Mid Grey)',
        'light'  => 'Light (Off White)',
    ],
    'default_variant' => 'dark',

    // THE HANDSHAKE
    'require_scripts' => [
        'smack-footer',
        'smack-lightbox',
        'smack-glitch'
    ],

    'options' => [

        /* SECTION 1: CANVAS LAYOUT */

        'main_canvas_width' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'range',
            'label'    => 'Main Canvas Width',
            'default'  => '1280',
            'min'      => '800',
            'max'      => '1920',
            'selector' => '.fsog-header-inside, #system-footer .inside, #browse-grid',
            'property' => 'max-width'
        ],

        'gutter_padding' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'range',
            'label'    => 'Outer Gutter Padding',
            'default'  => '40',
            'min'      => '0',
            'max'      => '150',
            'selector' => '.fsog-header-inside, #browse-grid',
            'property' => 'padding-left, padding-right'
        ],

        'header_layout_flip' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'select',
            'label'    => 'Header Orientation (The Flip)',
            'default'  => 'row',
            'options'  => [
                'row'         => 'Title Left / Nav Right',
                'row-reverse' => 'Nav Left / Title Right'
            ],
            'selector' => '.fsog-header-inside',
            'property' => 'flex-direction'
        ],

        /* SECTION 2: VERTICAL LOCKS */

        'image_frame_style' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'select',
            'label'    => 'Main Image Frame Style',
            'default'  => 'shadow_soft',
            'selector' => '.fsog-image, .thumb-link, .inline-asset, .static-transmission .description .align-left',
            'property' => 'custom-framing',
            'options'  => [
                'shadow_soft' => [
                    'label' => 'Soft Shadow (Default)',
                    'css'   => '{ border: none !important; box-shadow: 0 4px 24px rgba(0,0,0,0.4), 0 1px 6px rgba(0,0,0,0.2) !important; }'
                ],
                'shadow_heavy' => [
                    'label' => 'Heavy Shadow',
                    'css'   => '{ border: none !important; box-shadow: 0 8px 40px rgba(0,0,0,0.6), 0 2px 10px rgba(0,0,0,0.3) !important; }'
                ],
                'border_thin' => [
                    'label' => 'Thin Grey Border',
                    'css'   => '{ border: 1px solid #555555 !important; box-shadow: none !important; }'
                ],
                'none' => [
                    'label' => 'No Frame',
                    'css'   => '{ border: none !important; box-shadow: none !important; }'
                ]
            ]
        ],

        'header_font_family' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'select',
            'label'    => 'Header Typography (Text Logo)',
            'default'  => 'Raleway',
            'options'  => $fonts,
            'selector' => '.logo-area a',
            'property' => 'font-family'
        ],

        'header_font_size' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Header Font Size (px)',
            'default'  => '50',
            'min'      => '40',
            'max'      => '100',
            'selector' => '.site-title-text',
            'property' => 'font-size'
        ],

        'optical_lift' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Optical Vertical Lift (px)',
            'default'  => '50',
            'min'      => '0',
            'max'      => '150',
            'selector' => '#fsog-photobox',
            'property' => 'padding-bottom'
        ],

        'header_height' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Header Height Lock (px)',
            'default'  => '80',
            'min'      => '40',
            'max'      => '150',
            'selector' => '#fsog-header',
            'property' => 'height'
        ],

        'infobox_height' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Infobox Height Lock (px)',
            'default'  => '50',
            'min'      => '30',
            'max'      => '100',
            'selector' => '#infobox',
            'property' => 'height'
        ],

        /* SECTION 3: STATIC PAGE STYLING */

        'static_heading_font' => [
            'section'  => 'STATIC PAGE STYLING',
            'type'     => 'select',
            'label'    => 'Heading Typography',
            'default'  => 'Raleway',
            'options'  => $fonts,
            'selector' => '.static-page-title, .photo-title-footer',
            'property' => 'font-family'
        ],

        'static_body_font' => [
            'section'  => 'STATIC PAGE STYLING',
            'type'     => 'select',
            'label'    => 'Body Typography',
            'default'  => 'DM Sans',
            'options'  => $fonts,
            'selector' => '.static-content, .description',
            'property' => 'font-family'
        ],

        'static_section_spacing' => [
            'section'  => 'STATIC PAGE STYLING',
            'type'     => 'range',
            'label'    => 'Vertical Section Spacing (px)',
            'default'  => '40',
            'min'      => '10',
            'max'      => '120',
            'selector' => '.static-content',
            'property' => 'margin-top'
        ],

        /* SECTION 4: WALL SPECIFIC */

        'wall_friction' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'number',
            'label'    => 'Wall Friction (0.1 - 0.99)',
            'default'  => '0.96',
            'selector' => ':root',
            'property' => '--wall-friction'
        ],

        'wall_dragweight' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'number',
            'label'    => 'Drag Weight',
            'default'  => '2.5',
            'selector' => ':root',
            'property' => '--wall-drag-weight'
        ],

        'wall_shadow_intensity' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'select',
            'label'    => 'Shadow Intensity',
            'default'  => 'heavy',
            'options'  => [
                'none'  => 'No Shadow',
                'light' => 'Light / Subtle',
                'heavy' => 'Heavy / Deep Glow'
            ],
            'selector' => '.wall-item img',
            'property' => 'filter'
        ],

        'wall_font_ref' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'select',
            'label'    => 'Title Typography',
            'default'  => 'Raleway',
            'options'  => $fonts,
            'selector' => '.wall-title',
            'property' => 'font-family'
        ],

        'wall_theme' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'color',
            'label'    => 'Wall Background Color',
            'default'  => '#000000',
            'selector' => 'body.is-wall',
            'property' => 'background-color'
        ],

        'wall_text_color' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'color',
            'label'    => 'Floating Title Color',
            'default'  => '#808080',
            'selector' => '.wall-title',
            'property' => 'color'
        ],

        'wall_shadow_color' => [
            'section'  => 'WALL SPECIFIC',
            'type'     => 'color',
            'label'    => 'Shadow/Glow Color',
            'default'  => '#000000',
            'selector' => '.wall-item img',
            'property' => '--glow-color'
        ],

        /* SECTION 5: BLOGROLL */

        'blogroll_columns' => [
            'section'  => 'BLOGROLL',
            'type'     => 'select',
            'label'    => 'Column Layout',
            'default'  => '1',
            'selector' => '.blogroll-grid',
            'property' => 'custom-cols',
            'options'  => [
                '1' => [ 'label' => '1 Column (Single / Reading)', 'css' => '{ grid-template-columns: 1fr; }' ],
                '2' => [ 'label' => '2 Columns',                   'css' => '{ grid-template-columns: repeat(2, 1fr); }' ],
                '3' => [ 'label' => '3 Columns',                   'css' => '{ grid-template-columns: repeat(3, 1fr); }' ],
            ]
        ],

        'blogroll_max_width' => [
            'section'  => 'BLOGROLL',
            'type'     => 'range',
            'label'    => 'Content Max Width (px)',
            'default'  => '900',
            'min'      => '600',
            'max'      => '1400',
            'selector' => '.blogroll-canvas',
            'property' => 'max-width'
        ],

        'blogroll_col_gap' => [
            'section'  => 'BLOGROLL',
            'type'     => 'range',
            'label'    => 'Column Gutter (px)',
            'default'  => '60',
            'min'      => '20',
            'max'      => '120',
            'selector' => '.blogroll-grid',
            'property' => 'gap'
        ],

        'blogroll_show_desc' => [
            'section'  => 'BLOGROLL',
            'type'     => 'select',
            'label'    => 'Show Peer Description',
            'default'  => 'block',
            'selector' => '.blogroll-peer-desc',
            'property' => 'display',
            'options'  => [
                'block' => 'Yes — Show Description',
                'none'  => 'No — Hide Description',
            ]
        ],

        'blogroll_show_url' => [
            'section'  => 'BLOGROLL',
            'type'     => 'select',
            'label'    => 'Show Peer URL',
            'default'  => 'block',
            'selector' => '.blogroll-peer-url',
            'property' => 'display',
            'options'  => [
                'block' => 'Yes — Show URL',
                'none'  => 'No — Hide URL',
            ]
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
