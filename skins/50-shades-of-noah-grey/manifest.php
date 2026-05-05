<?php
/**
 * SNAPSMACK - Configuration manifest for the 50-shades-of-noah-grey skin
 * Alpha v0.7.9c
 *
 * Defines layout options, features, and customization controls with three greyscale variants.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'          => '50 Shades of Noah Grey',
    'version'       => '1.1',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'Pure greyscale photography skin. Three monochrome variants with zero colour accents.',
    'status'        => 'stable',

    'features' => [
        'supports_wall'        => true,
        'has_landing'          => false,
        'post_modes'           => ['image'],
        'instagram_mode'       => false,
        'carousel'             => false,
        'community'            => ['likes', 'comments'],
        'archive_layouts'      => ['square', 'cropped', 'masonry', 'croppedwithcalendar'],
    ],

    // Variant system for different greyscale themes
    'variants' => [
        'dark'   => 'Dark (Near Black)',
        'medium' => 'Medium (Mid Grey)',
        'light'  => 'Light (Off White)',
    ],
    'default_variant' => 'dark',

    // Load required JavaScript libraries and controllers
    'require_scripts' => [
        'smack-footer',
        'smack-image-fade-load',
        'smack-lightbox',
        'smack-justified-lib',
        'smack-justified',
        'smack-community',
        'smack-keyboard',
        'smack-calendar',
    ],

    'options' => [

        /* ============================================================
           SECTION 1: CANVAS LAYOUT
           ============================================================ */

        'main_canvas_width' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'range',
            'label'    => 'Main Canvas Width',
            'default'  => '1280',
            'min'      => '800',
            'max'      => '1920',
            'selector' => '.fsog-header-inside, .fsog-photo-wrap, #system-footer .inside, .fsog-archive-grid, #justified-grid',
            'property' => 'max-width'
        ],

        'gutter_padding' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'range',
            'label'    => 'Outer Gutter Padding',
            'default'  => '40',
            'min'      => '0',
            'max'      => '200',
            'selector' => '.fsog-header-inside, .fsog-photo-wrap, .fsog-archive-grid',
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

        /* ============================================================
           SECTION 2: TYPOGRAPHY (Fonts Only)
           ============================================================ */

        'header_font_family' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Header Font (Text Logo)',
            'default'  => 'Raleway',
            'options'  => $fonts,
            'selector' => '.site-title-text, .logo-area a',
            'property' => 'font-family'
        ],

        'static_heading_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Page Heading Font',
            'default'  => 'Raleway',
            'options'  => $fonts,
            'selector' => '.static-page-title, .photo-title-footer',
            'property' => 'font-family'
        ],

        'static_body_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body / Description Font',
            'default'  => 'DM Sans',
            'options'  => $fonts,
            'selector' => '.static-content, .description',
            'property' => 'font-family'
        ],

        'footer_font_family' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Footer Font',
            'default'  => 'Raleway',
            'options'  => $fonts,
            'selector' => '#system-footer .inside, #system-footer p, #sig-text',
            'property' => 'font-family'
        ],

        /* ============================================================
           SECTION 4: COLOURS
           ============================================================ */

        'stage_bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Static Page Stage (Behind Card)',
            'default'  => '#1a1a1a',
            'selector' => '.static-transmission #scroll-stage',
            'property' => 'background-color'
        ],

        'card_bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Static Page Card (Content Area)',
            'default'  => '#242424',
            'selector' => '.static-content',
            'property' => 'background-color'
        ],

        /* ============================================================
           SECTION 5: HEADER & NAV
           ============================================================ */

        'header_font_size' => [
            'section'  => 'HEADER & NAV',
            'type'     => 'range',
            'label'    => 'Header Font Size (px)',
            'default'  => '50',
            'min'      => '12',
            'max'      => '120',
            'selector' => '.site-title-text',
            'property' => 'font-size'
        ],

        'header_text_transform' => [
            'section'  => 'HEADER & NAV',
            'type'     => 'select',
            'label'    => 'Header Text Case',
            'default'  => 'uppercase',
            'options'  => [
                'uppercase'  => 'UPPERCASE',
                'lowercase'  => 'lowercase',
                'capitalize' => 'Capitalize Each Word',
                'none'       => 'As Entered (No Transform)',
            ],
            'selector' => '.site-title-text',
            'property' => 'text-transform'
        ],

        'header_letter_spacing' => [
            'section'  => 'HEADER & NAV',
            'type'     => 'range',
            'label'    => 'Header Letter Spacing (px)',
            'default'  => '3',
            'min'      => '-2',
            'max'      => '15',
            'selector' => '.site-title-text',
            'property' => 'letter-spacing'
        ],

        'header_font_weight' => [
            'section'  => 'HEADER & NAV',
            'type'     => 'select',
            'label'    => 'Header Font Weight',
            'default'  => '400',
            'options'  => [
                '300' => 'Light (300)',
                '400' => 'Regular (400)',
                '500' => 'Medium (500)',
                '600' => 'Semi-Bold (600)',
                '700' => 'Bold (700)',
                '900' => 'Black (900)',
            ],
            'selector' => '.site-title-text',
            'property' => 'font-weight'
        ],

        /* ============================================================
           SECTION 6: FOOTER
           ============================================================ */

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

        /* ============================================================
           SECTION 4: VERTICAL LOCKS
           ============================================================ */

        'image_frame_style' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'select',
            'label'    => 'Hero Image Frame',
            'default'  => 'border_thin',
            'selector' => 'img.post-image, img.fsog-image, .inline-asset, .snap-inline-frame, .static-transmission .description .align-left',
            'property' => 'custom-framing',
            'options'  => [
                'border_thin' => [
                    'label' => 'Thin Grey Border (1px)',
                    'css'   => '{ border: 1px solid #555555 !important; box-shadow: none !important; }'
                ],
                'border_medium' => [
                    'label' => 'Medium Grey Border (3px)',
                    'css'   => '{ border: 3px solid #555555 !important; box-shadow: none !important; }'
                ],
                'border_heavy' => [
                    'label' => 'Heavy Grey Border (5px)',
                    'css'   => '{ border: 5px solid #666666 !important; box-shadow: none !important; }'
                ],
                'shadow_soft' => [
                    'label' => 'Soft Shadow',
                    'css'   => '{ border: none !important; box-shadow: 0 4px 24px rgba(0,0,0,0.4), 0 1px 6px rgba(0,0,0,0.2) !important; }'
                ],
                'shadow_heavy' => [
                    'label' => 'Heavy Shadow',
                    'css'   => '{ border: none !important; box-shadow: 0 8px 40px rgba(0,0,0,0.6), 0 2px 10px rgba(0,0,0,0.3) !important; }'
                ],
                'none' => [
                    'label' => 'No Frame',
                    'css'   => '{ border: none !important; box-shadow: none !important; }'
                ]
            ]
        ],

        'archive_frame_style' => [
            'section'  => 'ARCHIVE',
            'type'     => 'select',
            'label'    => 'Archive Thumb Frame',
            'default'  => 'border_thin',
            'selector' => '.fsog-archive-item .fsog-thumb, .justified-item',
            'property' => 'custom-framing',
            'options'  => [
                'border_thin' => [
                    'label' => 'Thin Grey Border (1px)',
                    'css'   => '{ border: 1px solid #555555 !important; box-shadow: none !important; }'
                ],
                'border_medium' => [
                    'label' => 'Medium Grey Border (3px)',
                    'css'   => '{ border: 3px solid #555555 !important; box-shadow: none !important; }'
                ],
                'none' => [
                    'label' => 'No Frame',
                    'css'   => '{ border: none !important; box-shadow: none !important; }'
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

        /* ============================================================
           SECTION 5: BLOGROLL
           ============================================================ */

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
    ",

    'community_comments'  => '1',
    'community_likes'     => '1',
    'community_reactions' => '0',
];
// ===== SNAPSMACK EOF =====
