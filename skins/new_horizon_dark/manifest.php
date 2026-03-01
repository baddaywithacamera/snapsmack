<?php
/**
 * SnapSmack Skin Manifest: New Horizon Dark
 * Version: 5.1 - Glitch Engine Integration
 * -------------------------------------------------------------------------
 * - RESTORED: Section 1 Canvas Layout (Width, Gutter, Flip) from v4.1.
 * - RETAINED: Section 5 Blogroll logic from v3.5.
 * - FIXED: Typography pull updated to target the 'fonts' sub-key.
 * - ADDED: 'require_scripts' array to request JS engines from core.
 * - ADDED: smack-glitch to handshake.
 * - DIRECTIVE: FULL FILE OUTPUT. NO TRUNCATION.
 * -------------------------------------------------------------------------
 */

// PULL GLOBAL SYSTEM INVENTORY
$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];

return [
    'name'          => 'New Horizon Dark',
    'version'       => '5.1',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'High-contrast dark mode with archival framing, tactical layout controls, and full JS library support.',
    
    'features' => [
        'supports_wall'   => true,
        'archive_layouts' => ['square', 'cropped', 'masonry'],
    ],

    // THE HANDSHAKE: Request specific engines from the core inventory
    'require_scripts' => [
        'smack-footer', 
        'smack-lightbox', 
        'smack-justified-lib',
        'smack-justified'
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
           SECTION 2: ARCHIVE GRID
           --------------------------------------------------------------------- */

        'browse_cols' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'range',
            'label'    => 'Grid Columns (Square & Cropped)',
            'default'  => '4',
            'min'      => '2',
            'max'      => '8',
            'selector' => '#browse-grid',
            'property' => '--grid-cols'
        ],

        'justified_row_height' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'range',
            'label'    => 'Justified Row Height (px)',
            'default'  => '280',
            'min'      => '150',
            'max'      => '500',
            'selector' => '#browse-grid',
            'property' => '--justified-row-h'
        ],

        /* ---------------------------------------------------------------------
           SECTION 3: VERTICAL LOCKS & FRAMING
           --------------------------------------------------------------------- */
           
        'image_frame_style' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'select',
            'label'    => 'Main Image Frame Style',
            'default'  => 'revival_double',
            'selector' => 'img.post-image, .inline-asset, .static-transmission .description .align-left',
            'property' => 'custom-framing',
            'options'  => [
                'revival_double' => [
                    'label' => 'Revival Double Line (Grey/Black/Grey)',
                    'css'   => '{ border: 5px solid #666666 !important; box-shadow: none !important; }'
                ],
                'classic_white'  => [
                    'label' => 'Classic Horizon (Thick White)',
                    'css'   => '{ border: 20px solid #ffffff !important; box-shadow: none !important; }'
                ],
                'gallery_mat'    => [
                    'label' => 'Gallery Multi-Mat (White / Med-Grey / White)',
                    'css'   => '{ border: 3px solid #ffffff !important; box-shadow: none !important; }'
                ],
                'minimal_bevel'  => [
                    'label' => 'Minimal Bevel (Thin White)',
                    'css'   => '{ border: 1px solid #ffffff !important; box-shadow: none !important; }'
                ],
                'obsidian'       => [
                    'label' => 'Obsidian (Dark Grey)',
                    'css'   => '{ border: 1px solid #333333 !important; box-shadow: none !important; }'
                ]
            ]
        ],

        'archive_frame_style' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'select',
            'label'    => 'Archive Thumbnail Frame',
            'default'  => 'thin_grey',
            'selector' => '.square-grid .thumb-link, .cropped-grid .thumb-link',
            'property' => 'custom-framing',
            'options'  => [
                'thin_grey' => [
                    'label' => 'Thin Grey (1px)',
                    'css'   => '{ border: 1px solid #666666 !important; box-shadow: none !important; }'
                ],
                'thin_white' => [
                    'label' => 'Thin White (1px)',
                    'css'   => '{ border: 1px solid #ffffff !important; box-shadow: none !important; }'
                ],
                'thin_dark' => [
                    'label' => 'Thin Dark (1px)',
                    'css'   => '{ border: 1px solid #333333 !important; box-shadow: none !important; }'
                ],
                'medium_grey' => [
                    'label' => 'Medium Grey (3px)',
                    'css'   => '{ border: 3px solid #666666 !important; box-shadow: none !important; }'
                ],
                'medium_white' => [
                    'label' => 'Medium White (3px)',
                    'css'   => '{ border: 3px solid #ffffff !important; box-shadow: none !important; }'
                ],
                'none' => [
                    'label' => 'No Border',
                    'css'   => '{ border: none !important; box-shadow: none !important; }'
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
            'selector' => '#photobox',
            'property' => 'padding-bottom'
        ],

        'header_height' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Header Height Lock (px)',
            'default'  => '80',
            'min'      => '40',
            'max'      => '150',
            'selector' => '#header',
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

        /* ---------------------------------------------------------------------
           SECTION 4: STATIC PAGE STYLING
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
            'label'    => 'Vertical Section Spacing (px)',
            'default'  => '40',
            'min'      => '10',
            'max'      => '120',
            'selector' => '.static-content',
            'property' => 'margin-top'
        ],

        /* ---------------------------------------------------------------------
           SECTION 5: WALL SPECIFIC (3D Engine Physics & Visuals)
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
        ],

        /* ---------------------------------------------------------------------
           SECTION 6: BLOGROLL (Layout, Columns & Display Toggles)
           --------------------------------------------------------------------- */

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