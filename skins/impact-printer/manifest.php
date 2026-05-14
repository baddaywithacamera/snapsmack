<?php
/**
 * SNAPSMACK - Configuration manifest for the impact-printer skin
 * Alpha v0.7.7
 *
 * Defines layout options, features, dot-matrix typography, and customization controls.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');

// Build restricted font picker for dot-matrix typography
$dm_keys = array_filter(array_keys($inventory['local_fonts'] ?? []), function($k) {
    return strpos($k, 'DotMatrix') === 0 || strpos($k, 'Tiny5') === 0;
});
$picker_fonts = [];
foreach ($dm_keys as $k) {
    $picker_fonts[$k] = $inventory['local_fonts'][$k]['label'];
}

return [
    'name'          => 'Impact Printer',
    'version'       => '1.4',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'Designed for photographers who enjoy using instant printer cameras that output on thermal paper. Continuous-feed tractor-feed paper textures, ASCII character borders, dithered halftone image rendering, faded ribbon ink. Two paper stocks: green-bar ledger and plain white.',
    'status'        => 'stable',
    'demo_url'      => 'https://pixhellated.ca',

    'features' => [
        'supports_wall'   => true,
        'archive_layouts' => ['square', 'cropped'],
        'has_landing'     => false,
        'post_modes'      => ['image'],
        'instagram_mode'  => false,
        'carousel'        => false,
        'community'       => ['likes', 'comments'],
    ],

    // VARIANT SYSTEM — paper stocks
    'variants' => [
        'greenbar'  => 'Green Bar Ledger Paper',
        'plain'     => 'Plain White Continuous',
    ],
    'default_variant' => 'greenbar',

    // Restrict font picker to manifest fonts only
    'allowed_fonts' => array_keys($picker_fonts),

    // THE HANDSHAKE — checked out from CMS library
    // No glitch, no justified, no wall
    'require_scripts' => [
        'smack-footer',
        'smack-image-fade-load',
        'smack-lightbox',
        'smack-keyboard',
        'smack-community'
    ],

    'options' => [

        /* ============================================================
           SECTION 1: PAPER MARGINS
           Controls content width within the tractor-feed paper texture.
           The background image has sprocket holes at fixed positions —
           these sliders keep content off the sprocket strips.
           ============================================================ */

        'main_canvas_width' => [
            'section'  => 'PAPER MARGINS',
            'type'     => 'range',
            'label'    => 'Paper Width (Content Area)',
            'default'  => '1100',
            'min'      => '700',
            'max'      => '1600',
            'selector' => '.ip-header-inside, #system-footer .inside, .ip-photo-wrap, #browse-grid, #justified-grid, .blogroll-canvas, .static-content, .static-page-title, .photo-title-footer, .description, .meta, .comment-form, #pane-comments, .nav-links',
            'property' => 'max-width'
        ],

        'margin_left' => [
            'section'  => 'PAPER MARGINS',
            'type'     => 'range',
            'label'    => 'Left Margin (clear sprocket strip)',
            'default'  => '55',
            'min'      => '20',
            'max'      => '120',
            'selector' => '#page-wrapper',
            'property' => 'padding-left'
        ],

        'margin_right' => [
            'section'  => 'PAPER MARGINS',
            'type'     => 'range',
            'label'    => 'Right Margin (clear sprocket strip)',
            'default'  => '55',
            'min'      => '20',
            'max'      => '120',
            'selector' => '#page-wrapper',
            'property' => 'padding-right'
        ],

        'header_layout_flip' => [
            'section'  => 'PAPER MARGINS',
            'type'     => 'select',
            'label'    => 'Header Orientation',
            'default'  => 'row',
            'options'  => [
                'row'         => 'Title Left / Nav Right',
                'row-reverse' => 'Nav Left / Title Right'
            ],
            'selector' => '.ip-header-inside',
            'property' => 'flex-direction'
        ],

        /* ============================================================
           SECTION 2: PRINT HEAD (IMAGE FRAME)
           Hero image uses ASCII character borders via JS engine.
           Archive thumbs: ASCII box hardcoded in style.css (no picker).
           Inline images: ASCII box with weight/padding controls below.
           ============================================================ */

        'image_frame_style' => [
            'section'  => 'PRINT HEAD',
            'type'     => 'select',
            'label'    => 'Hero ASCII Border Style',
            'default'  => 'box',
            'selector' => '.ip-ascii-frame-inner',
            'property' => 'custom-framing',
            'options'  => [
                'box'    => [
                    'label' => 'ASCII Box     +----|----+',
                    'css'   => "{ border-style: solid; border-color: transparent; border-image: url('skins/impact-printer/textures/border-box.svg') 12 repeat; }"
                ],
                'plus'   => [
                    'label' => 'ASCII Plus    + + + + + +',
                    'css'   => "{ border-style: solid; border-color: transparent; border-image: url('skins/impact-printer/textures/border-plus.svg') 12 repeat; }"
                ],
                'equals' => [
                    'label' => 'ASCII Equals  = = = = = =',
                    'css'   => "{ border-style: solid; border-color: transparent; border-image: url('skins/impact-printer/textures/border-equals.svg') 12 repeat; }"
                ],
                'slash'  => [
                    'label' => 'ASCII Slash   / / / / / /',
                    'css'   => "{ border-style: solid; border-color: transparent; border-image: url('skins/impact-printer/textures/border-slash.svg') 12 repeat; }"
                ],
                'none'   => [
                    'label' => 'No Frame (Raw Print)',
                    'css'   => '{ border: none !important; }'
                ],
            ]
        ],

        'image_frame_weight' => [
            'section'  => 'PRINT HEAD',
            'type'     => 'range',
            'label'    => 'Border Weight (px)',
            'default'  => '18',
            'min'      => '8',
            'max'      => '40',
            'selector' => '.ip-ascii-frame-inner',
            'property' => 'border-width'
        ],

        'image_frame_padding' => [
            'section'  => 'PRINT HEAD',
            'type'     => 'range',
            'label'    => 'Border Padding (px)',
            'default'  => '15',
            'min'      => '0',
            'max'      => '40',
            'selector' => '.ip-ascii-frame-inner',
            'property' => 'padding'
        ],

        'inline_frame_style' => [
            'section'  => 'PRINT HEAD',
            'type'     => 'select',
            'label'    => 'Inline Image Frame Style',
            'default'  => 'box',
            'selector' => '.snap-inline-frame .ip-ascii-frame-inner',
            'property' => 'custom-framing',
            'options'  => [
                'box'    => [
                    'label' => 'ASCII Box     +----|----+',
                    'css'   => "{ border-style: solid; border-color: transparent; border-image: url('skins/impact-printer/textures/border-box.svg') 12 repeat; }"
                ],
                'plus'   => [
                    'label' => 'ASCII Plus    + + + + + +',
                    'css'   => "{ border-style: solid; border-color: transparent; border-image: url('skins/impact-printer/textures/border-plus.svg') 12 repeat; }"
                ],
                'equals' => [
                    'label' => 'ASCII Equals  = = = = = =',
                    'css'   => "{ border-style: solid; border-color: transparent; border-image: url('skins/impact-printer/textures/border-equals.svg') 12 repeat; }"
                ],
                'slash'  => [
                    'label' => 'ASCII Slash   / / / / / /',
                    'css'   => "{ border-style: solid; border-color: transparent; border-image: url('skins/impact-printer/textures/border-slash.svg') 12 repeat; }"
                ],
                'none'   => [
                    'label' => 'No Frame',
                    'css'   => '{ border: none !important; }'
                ],
            ]
        ],

        'inline_frame_weight' => [
            'section'  => 'PRINT HEAD',
            'type'     => 'range',
            'label'    => 'Inline Image Border Weight (px)',
            'default'  => '9',
            'min'      => '4',
            'max'      => '24',
            'selector' => '.snap-inline-frame .ip-ascii-frame-inner',
            'property' => 'border-width'
        ],

        'inline_frame_padding' => [
            'section'  => 'PRINT HEAD',
            'type'     => 'range',
            'label'    => 'Inline Image Border Padding (px)',
            'default'  => '8',
            'min'      => '0',
            'max'      => '24',
            'selector' => '.snap-inline-frame .ip-ascii-frame-inner',
            'property' => 'padding'
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
            'max'      => '8',
            'selector' => '#browse-grid',
            'property' => '--grid-cols'
        ],

        /* ============================================================
           SECTION 4: TYPOGRAPHY
           Restricted to DotMatrix family + monospace companions.
           ============================================================ */

        'header_font_family' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Header Font (Text Logo)',
            'default'  => 'DotMatrix-Expanded-Bold',
            'options'  => $picker_fonts,
            'selector' => '.site-title-text, .logo-area a',
            'property' => 'font-family'
        ],

        'header_font_size' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Header Font Size (px)',
            'default'  => '42',
            'min'      => '24',
            'max'      => '80',
            'selector' => '.site-title-text',
            'property' => 'font-size'
        ],

        'body_font_family' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body / Description Font',
            'default'  => 'DotMatrix',
            'options'  => $picker_fonts,
            'selector' => '.description, .static-content',
            'property' => 'font-family'
        ],

        'static_heading_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Page Heading Font',
            'default'  => 'DotMatrix-Bold',
            'options'  => $picker_fonts,
            'selector' => '.static-page-title, .photo-title-footer',
            'property' => 'font-family'
        ],

        /* FOOTER FONTS */

        'footer_font_family' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Footer Font',
            'default'  => 'DotMatrix',
            'options'  => $picker_fonts,
            'selector' => '#system-footer .inside, #system-footer p, #sig-text',
            'property' => 'font-family'
        ],

        'footer_font_size' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Footer Font Size (px)',
            'default'  => '10',
            'min'      => '8',
            'max'      => '18',
            'selector' => '#system-footer p, #sig-text',
            'property' => 'font-size'
        ],

        /* ============================================================
           SECTION 5: VERTICAL LOCKS
           ============================================================ */

        'optical_lift' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Optical Vertical Lift (px)',
            'default'  => '40',
            'min'      => '0',
            'max'      => '150',
            'selector' => '#ip-photobox',
            'property' => 'padding-bottom'
        ],

        'header_height' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Header Height Lock (px)',
            'default'  => '90',
            'min'      => '40',
            'max'      => '150',
            'selector' => '#ip-header',
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

        /* ============================================================
           SECTION 6: INK QUALITY
           ============================================================ */

        'ink_darkness' => [
            'section'  => 'INK QUALITY',
            'type'     => 'select',
            'label'    => 'Ribbon Darkness',
            'default'  => 'faded',
            'selector' => ':root',
            'property' => '--ip-ink-opacity',
            'options'  => [
                'fresh'  => ['label' => 'Fresh Ribbon (Heavy / Streaky)', 'css' => '{ --ip-ink-opacity: 1.0; --ip-ink-bleed: 0.8px 0 1px currentColor, -0.5px 0 1px currentColor, 1.6px 0.1px 0.5px currentColor, -1.1px -0.1px 0.5px currentColor, 0.3px 0.6px 1.2px rgba(0,0,0,0.35); --ip-ink-weight: 900; }'],
                'normal' => ['label' => 'Normal Wear',                    'css' => '{ --ip-ink-opacity: 0.82; --ip-ink-bleed: 0.4px 0 0.6px currentColor, -0.2px 0 0.6px currentColor; --ip-ink-weight: bold; }'],
                'faded'  => ['label' => 'Faded Ribbon',                   'css' => '{ --ip-ink-opacity: 0.65; --ip-ink-bleed: none; --ip-ink-weight: normal; }'],
                'dying'  => ['label' => 'Nearly Dead',                    'css' => '{ --ip-ink-opacity: 0.45; --ip-ink-bleed: none; --ip-ink-weight: normal; }'],
            ]
        ],

        /* ============================================================
           SECTION 7: BLOGROLL
           ============================================================ */

        'blogroll_columns' => [
            'section'  => 'BLOGROLL',
            'type'     => 'select',
            'label'    => 'Column Layout',
            'default'  => '1',
            'selector' => '.blogroll-grid',
            'property' => 'custom-cols',
            'options'  => [
                '1' => ['label' => '1 Column', 'css' => '{ grid-template-columns: 1fr; }'],
                '2' => ['label' => '2 Columns', 'css' => '{ grid-template-columns: repeat(2, 1fr); }'],
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
            'options'  => ['block' => 'Yes', 'none' => 'No']
        ],

        'blogroll_show_url' => [
            'section'  => 'BLOGROLL',
            'type'     => 'select',
            'label'    => 'Show Peer URL',
            'default'  => 'block',
            'selector' => '.blogroll-peer-url',
            'property' => 'display',
            'options'  => ['block' => 'Yes', 'none' => 'No']
        ],
    ],

    'admin_styling' => "
        .metadata-selector-row { display: flex; justify-content: space-between; align-items: center; margin-top: -15px; margin-bottom: 50px; }
        .skin-switcher-form { display: flex; align-items: center; gap: 10px; }
        .skin-swit