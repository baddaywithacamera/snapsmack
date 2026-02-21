<?php
/**
 * SNAPSMACK Skin Manifest: New Horizon Dark
 * Version: 4.1 - Structural Baseline
 * -------------------------------------------------------------------------
 * HOUSEKEEPING:
 * - Standardized Header: Removed legacy session/versioning notes.
 * - System Inventory: Pulls tactical typography from core.
 * - ADDED: Canvas Layout Controls (Width, Gutter Padding, Header Flip).
 * -------------------------------------------------------------------------
 */

// PULL SYSTEM FONT INVENTORY FROM CORE
$fonts = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');

return [
    'name'          => 'New Horizon Dark',
    'version'       => '4.1',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'High-contrast dark mode with archival framing and tactical layout controls.',
    
    'features' => [
        'supports_wall' => true, 
    ],

    'options' => [
        
        /* ---------------------------------------------------------------------
           SECTION 1: CANVAS LAYOUT (Width, Padding, Orientation)
           --------------------------------------------------------------------- */
        
        'main_canvas_width' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'range',
            'label'    => 'Main Canvas Width',
            'default'  => '1280',
            'min'      => '800',
            'max'      => '1920',
            'selector' => '#header .inside, #system-footer .inside, #browse-grid',
            'property' => 'max-width'
        ],

        'gutter_padding' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'range',
            'label'    => 'Outer Gutter Padding',
            'default'  => '40',
            'min'      => '0',
            'max'      => '150',
            'selector' => '#header .inside, #browse-grid',
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
            'selector' => '#header .inside',
            'property' => 'flex-direction'
        ],

        /* ---------------------------------------------------------------------
           SECTION 2: VERTICAL LOCKS & FRAMING
           --------------------------------------------------------------------- */
           
        'header_height' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Header Height Lock',
            'default'  => '80',
            'min'      => '40',
            'max'      => '150',
            'selector' => '#header',
            'property' => 'height'
        ],

        'infobox_height' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Infobox/Nav Height',
            'default'  => '60',
            'min'      => '40',
            'max'      => '120',
            'selector' => '#infobox',
            'property' => 'height'
        ],

        'optical_lift' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Optical Vertical Lift',
            'default'  => '50',
            'min'      => '0',
            'max'      => '150',
            'selector' => '#photobox',
            'property' => 'padding-bottom'
        ],

        'image_frame_style' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'select',
            'label'    => 'Main Image Frame Style',
            'default'  => 'revival_double',
            'selector' => 'img.post-image, .thumb-link, .inline-asset, .static-transmission .description .align-left',
            'property' => 'custom-framing',
            'options'  => [
                'revival_double' => [
                    'label' => 'Revival Double Line (Grey/Black/Grey)',
                    'css'   => '{ border: 5px solid #666666 !important; box-shadow: 0 0 0 15px #000000, 0 0 0 16px #666666 !important; }'
                ],
                'classic_white'  => [
                    'label' => 'Classic Horizon (Thick White)',
                    'css'   => '{ border: 20px solid #ffffff !important; box-shadow: 0 0 0 1px #333333 !important; }'
                ],
                'gallery_mat'    => [
                    'label' => 'Gallery Multi-Mat (White / Med-Grey / White)',
                    'css'   => '{ border: 3px solid #ffffff !important; box-shadow: 0 0 0 15px #666666, 0 0 0 16px #ffffff !important; }'
                ],
                'minimal_bevel'  => [
                    'label' => 'Minimal Bevel (Thin White / Black / White)',
                    'css'   => '{ border: 1px solid #ffffff !important; box-shadow: 0 0 0 8px #000000, 0 0 0 9px #ffffff !important; }'
                ],
                'obsidian'       => [
                    'label' => 'Obsidian (Dark Grey / Black / Dark Grey)',
                    'css'   => '{ border: 1px solid #333333 !important; box-shadow: 0 0 0 20px #111111, 0 0 0 21px #333333 !important; }'
                ]
            ]
        ],

        'header_font_family' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'select',
            'label'    => 'Header Typography (Text Logo)',
            'default'  => 'Playfair Display',
            'options'  => $fonts, 
            'selector' => '.logo-area a', 
            'property' => 'font-family'
        ],

        'header_font_size' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Header Font Size',
            'default'  => '50',
            'min'      => '40',
            'max'      => '100',
            'selector' => '.site-title-text',
            'property' => 'font-size'
        ],

        /* ---------------------------------------------------------------------
           SECTION 3: STATIC PAGE STYLING
           --------------------------------------------------------------------- */

        'static_heading_font' => [
            'section'  => 'STATIC PAGE STYLING',
            'type'     => 'select',
            'label'    => 'Heading Typography',
            'default'  => 'Helvetica Neue',
            'options'  => $fonts,
            'selector' => '.static-page-title, .photo-title-footer',
            'property' => 'font-family'
        ],

        'static_body_font' => [
            'section'  => 'STATIC PAGE STYLING',
            'type'     => 'select',
            'label'    => 'Body Typography',
            'default'  => 'Georgia',
            'options'  => $fonts,
            'selector' => '.static-content, .description',
            'property' => 'font-family'
        ],

        'static_section_spacing' => [
            'section'  => 'STATIC PAGE STYLING',
            'type'     => 'range',
            'label'    => 'Vertical Section Spacing',
            'default'  => '40',
            'min'      => '10',
            'max'      => '120',
            'selector' => '.static-content',
            'property' => 'margin-top'
        ],

        /* ---------------------------------------------------------------------
           SECTION 4: WALL SPECIFIC (3D Engine Physics & Visuals)
           --------------------------------------------------------------------- */

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
            'default'  => 'Playfair Display',
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
        ]
    ],

    /* -------------------------------------------------------------------------
       ADMIN INTERFACE STYLING
       ------------------------------------------------------------------------- */
    'admin_styling' => "
        .metadata-selector-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-top: -15px; 
            margin-bottom: 50px; 
        }
        .skin-switcher-form { display: flex; align-items: center; gap: 10px; }
        .skin-switcher-form label { margin: 0 !important; }
        .control-group-flex { display: flex; align-items: center; gap: 20px; }
        .control-group-flex input { flex: 1; }
        .active-val { width: 50px; text-align: right; font-family: monospace; }
        .hex-display { font-family: monospace; }
    "
];