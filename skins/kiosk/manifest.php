<?php
/**
 * SNAPSMACK - Configuration manifest for the kiosk skin
 * Alpha v0.7.8
 *
 * Defines layout options, pimpotron engine configuration, and customization controls.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




// Load system inventory for available fonts and features
$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'          => 'Kiosk',
    'version'       => '0.3',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'What is black and white and read all over? Blogs, motherfucker! Also this skin, except we mean red too. Well, both. Shut up.',
    'status'        => 'development',
    
    'features' => [
        'supports_wall' => true,
        'has_landing'   => false,
        'post_modes'    => ['image'],
        'instagram_mode' => false,
        'carousel'      => false,
        'community'     => [],
    ],

    // Feature engines enabled for this skin
    'engines' => [
        'pimpotron' => true,
    ],

    // THE HANDSHAKE: Request specific engines from the core inventory
    'require_scripts' => [
        'smack-footer', 
        'smack-lightbox', 
        'smack-keyboard',
        'smack-glitch',
        'smack-pimpotron',
        'smack-logo',
        'smack-image-fade-load',
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
           SECTION 3: TYPOGRAPHY
           --------------------------------------------------------------------- */

        'header_font_family' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Header Font (Text Logo)',
            'default'  => 'Playfair Display',
            'options'  => $fonts,
            'selector' => '.logo-area a',
            'property' => 'font-family'
        ],

        'static_heading_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Heading Font',
            'default'  => 'Helvetica Neue',
            'options'  => $fonts,
            'selector' => '.static-page-title, .photo-title-footer',
            'property' => 'font-family'
        ],

        'static_body_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body Font',
            'default'  => 'Georgia',
            'options'  => $fonts,
            'selector' => '.static-content, .description',
            'property' => 'font-family'
        ],

        'footer_font_family' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Footer Font',
            'default'  => 'Inter',
            'options'  => $fonts,
            'selector' => '#system-footer .inside, #system-footer p, #sig-text',
            'property' => 'font-family'
        ],

        /* ---------------------------------------------------------------------
           SECTION 4: HEADER & NAV
           --------------------------------------------------------------------- */

        'header_font_size' => [
            'section'  => 'HEADER & NAV',
            'type'     => 'range',
            'label'    => 'Header Font Size (px)',
            'default'  => '50',
            'min'      => '40',
            'max'      => '100',
            'selector' => '.site-title-text',
            'property' => 'font-size'
        ],
        'header_text_transform' => [
            'section'  => 'HEADER & NAV',
            'type'     => 'select',
            'label'    => 'Site Title Case',
            'default'  => 'uppercase',
            'options'  => [
                'uppercase'  => 'UPPERCASE',
                'lowercase'  => 'lowercase',
                'capitalize' => 'Capitalize Each Word',
                'none'       => 'As Entered (No Transform)',
            ],
            'selector' => '.site-title-text',
            'property' => 'text-transform',
        ],

        /* ---------------------------------------------------------------------
           SECTION 5: VERTICAL LOCKS
           --------------------------------------------------------------------- */

        'static_section_spacing' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Static Page Top Spacing (px)',
            'default'  => '40',
            'min'      => '10',
            'max'      => '120',
            'selector' => '.static-content',
            'property' => 'margin-top'
        ],

        /* ---------------------------------------------------------------------
           SECTION 6: FOOTER
           --------------------------------------------------------------------- */

        'footer_font_size' => [
            'section'  => 'FOOTER',
            'type'     => 'range',
            'label'    => 'Footer Font Size (px)',
            'default'  => '11',
            'min'      => '8',
            'max'      => '18',
            'selector' => '#system-footer p, #sig-text',
            'property' => 'font-size'
        ],

        /* ---------------------------------------------------------------------
           SECTION 4: BLOGROLL (Layout, Columns & Display Toggles)
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
// ===== SNAPSMACK EOF =====
