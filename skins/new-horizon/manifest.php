<?php
/**
 * SNAPSMACK - Configuration manifest for the new-horizon skin
 * Alpha v0.7.9
 *
 * Defines layout options, features, and customization controls.
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
    'name'          => 'New Horizon',
    'version'       => '1.1',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'High-contrast photography skin with archival framing, tactical layout controls, and full JS library support. Light and dark variants.',
    'status'        => 'stable',

    'variants' => [
        'dark'  => 'Dark',
        'light' => 'Light',
    ],
    'default_variant' => 'dark',

    'features' => [
        'supports_wall'   => true,
        'archive_layouts' => ['square', 'cropped', 'masonry'],
        'has_landing'     => false,
        'post_modes'      => ['image'],
        'instagram_mode'  => false,
        'carousel'        => false,
        'community'       => ['likes', 'comments'],
    ],

    // Load required JavaScript libraries and controllers
    'require_scripts' => [
        'smack-footer',
        'smack-image-fade-load',
        'smack-lightbox',
        'smack-keyboard',
        'smack-justified-lib',
        'smack-justified',
        'smack-community'
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
            'selector' => '#header .inside, #system-footer .inside, #browse-grid, #justified-grid',
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

        'archive_layout' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'select',
            'label'    => 'Archive Layout Mode',
            'default'  => 'square',
            'options'  => [
                'square'  => 'Square Grid',
                'cropped' => 'Cropped Grid (Natural Aspect)',
                'masonry' => 'Justified / Masonry (Flickr Style)',
            ],
        ],

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
            'selector' => '#justified-grid',
            'property' => '--justified-row-height'
        ],

        /* ---------------------------------------------------------------------
           SECTION 3: FRAMING
           --------------------------------------------------------------------- */

        'image_frame_style' => [
            'section'  => 'FRAMING',
            'type'     => 'select',
            'label'    => 'Main Image Frame Style',
            'default'  => 'revival_double',
            'selector' => 'img.post-image, .inline-asset, .snap-inline-frame, .static-transmission .description .align-left',
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

        'archive_frame_style' => [
            'section'  => 'FRAMING',
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

        /* ---------------------------------------------------------------------
           SECTION 4: TYPOGRAPHY (Fonts Only)
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
           SECTION 5: COLOURS
           --------------------------------------------------------------------- */

        'page_bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Static Page Background',
            'default'  => '#111111',
            'selector' => '.static-content, .static-transmission #scroll-stage',
            'property' => 'background-color'
        ],

        /* ---------------------------------------------------------------------
           SECTION 6: HEADER & NAV
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

        /* ---------------------------------------------------------------------
           SECTION 7: CONTENT STYLING
           --------------------------------------------------------------------- */

        'content_line_height' => [
            'section'  => 'CONTENT STYLING',
            'type'     => 'range',
            'label'    => 'Leading (Line Spacing)',
            'default'  => '1.6',
            'min'      => '1',
            'max'      => '3',
            'step'     => '0.1',
            'selector' => ':root',
            'property' => '--content-lh'
        ],

        'content_letter_spacing' => [
            'section'  => 'CONTENT STYLING',
            'type'     => 'range',
            'label'    => 'Tracking (Letter Spacing)',
            'default'  => '0',
            'min'      => '-2',
            'max'      => '10',
            'selector' => '.static-content, .description',
            'property' => 'letter-spacing'
        ],

        'dropcap_style' => [
            'section'  => 'CONTENT STYLING',
            'type'     => 'select',
            'label'    => 'First Letter Dropcap',
            'default'  => 'none',
            'selector' => '.description p:first-of-type::first-letter, .static-content p:first-of-type::first-letter, span.dropcap',
            'property' => 'custom-framing',
            'options'  => [
                'none' => [
                    'label' => 'None',
                    'css'   => '{ float: none; font-size: inherit; margin: 0; padding: 0; line-height: inherit; }'
                ],
                'simple' => [
                    'label' => 'Simple Bold (Large)',
                    'css'   => '{ float: left; font-size: 3.5em; line-height: 0.8; padding-top: 4px; padding-right: 8px; font-weight: bold; }'
                ],
                'tactical' => [
                    'label' => 'Tactical Block (White on Grey)',
                    'css'   => '{ float: left; font-size: 2.2em; line-height: 1; margin: 4px 10px 0 0; padding: 10px 15px; background: #333; color: #fff; font-family: monospace; }'
                ]
            ]
        ],

        /* ---------------------------------------------------------------------
           SECTION 8: VERTICAL LOCKS
           --------------------------------------------------------------------- */

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

        'static_section_spacing' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Static Page Top Spacing (px)',
            'default'  => '40',
            'min'      => '10',
            'max'      => '120',
            'selector' => '.static-content, #pane-info',
            'property' => 'margin-top'
        ],

        /* ---------------------------------------------------------------------
           SECTION 9: FOOTER
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
           SECTION 5: BLOGROLL (Layout, Columns & Display Toggles)
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
    ",

    'community_comments'  => '1',
    'community_likes'     => '1',
    'community_reactions' => '0',

    /* -------------------------------------------------------------------------
       OH SNAP! CSS VARIABLE MAP
       Declares every CSS custom property that Oh Snap! can drive.
       Groups become panels in the Colours tab. Each var entry specifies:
         type     — 'color' | 'range'
         label    — human-readable name shown in the control
         default  — the dark-variant default (Oh Snap! uses dark by default)
         min/max/step — range only; 'unit' appended to the value (optional)
       ------------------------------------------------------------------------- */
    'css_variables' => [

        'BACKGROUNDS' => [
            'label' => 'Backgrounds',
            'vars'  => [
                '--bg-page'      => ['label' => 'Page Background',      'type' => 'color', 'default' => '#000000'],
                '--bg-secondary' => ['label' => 'Secondary Background', 'type' => 'color', 'default' => '#131313'],
                '--bg-tertiary'  => ['label' => 'Tertiary Background',  'type' => 'color', 'default' => '#222222'],
                '--bg-chrome'    => ['label' => 'Chrome / UI Surface',  'type' => 'color', 'default' => '#393939'],
                '--input-bg'     => ['label' => 'Input Background',     'type' => 'color', 'default' => '#222222'],
            ],
        ],

        'TEXT' => [
            'label' => 'Text',
            'vars'  => [
                '--text-bright'    => ['label' => 'Bright (Headings)',    'type' => 'color', 'default' => '#ffffff'],
                '--text-primary'   => ['label' => 'Primary (Body)',       'type' => 'color', 'default' => '#cccccc'],
                '--text-secondary' => ['label' => 'Secondary (Captions)', 'type' => 'color', 'default' => '#aaaaaa'],
                '--text-dim'       => ['label' => 'Dim (Meta)',           'type' => 'color', 'default' => '#888888'],
                '--text-muted'     => ['label' => 'Muted (Subtle)',       'type' => 'color', 'default' => '#555555'],
                '--text-faint'     => ['label' => 'Faint (Placeholders)', 'type' => 'color', 'default' => '#444444'],
                '--text-link'      => ['label' => 'Link',                 'type' => 'color', 'default' => '#eeeeee'],
            ],
        ],

        'BORDERS' => [
            'label' => 'Borders',
            'vars'  => [
                '--border-primary' => ['label' => 'Primary Border',  'type' => 'color', 'default' => '#333333'],
                '--border-accent'  => ['label' => 'Accent Border',   'type' => 'color', 'default' => '#666666'],
                '--border-dim'     => ['label' => 'Dim Border',      'type' => 'color', 'default' => '#222222'],
            ],
        ],

        'TYPOGRAPHY' => [
            'label' => 'Typography',
            'vars'  => [
                '--content-lh' => [
                    'label'   => 'Line Height',
                    'type'    => 'range',
                    'default' => '1.6',
                    'min'     => '1.0',
                    'max'     => '3.0',
                    'step'    => '0.1',
                ],
            ],
        ],

        'masonry_border_width' => [
            'section'  => 'ARCHIVE',
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
            'section'  => 'ARCHIVE',
            'type'     => 'color',
            'label'    => 'Masonry Border Colour',
            'default'  => '#ffffff',
            'selector' => ':root',
            'property' => '--masonry-border-color',
        ],,
    ],
];
// ===== SNAPSMACK EOF =====
