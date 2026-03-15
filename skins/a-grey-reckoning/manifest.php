<?php
/**
 * SNAPSMACK - Configuration manifest for the grey-expectations skin
 * Alpha v0.7.3
 *
 * Recreates the quiet, reverent aesthetic of Noah Grey's 2006-era photography
 * websites: greyexpectations.com and noahgrey.com. Solid dark backgrounds,
 * thin borders, small-caps typography with generous letter-spacing, and
 * nothing between the viewer and the photograph.
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'          => 'A Grey Reckoning',
    'version'       => '1.0',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'A quiet, reverent photoblog skin inspired by the 2006-era photography websites of Noah Grey. Dark backgrounds, thin borders, small-caps navigation, and nothing between the viewer and the photograph.',
    'status'        => 'stable',

    'features' => [
        'supports_wall'   => false,
        'archive_layouts' => ['square'],
    ],

    // No variants — the skin is dark by nature
    'variants' => [],

    'require_scripts' => [
        'smack-footer',
        'smack-lightbox',
        'smack-community'
    ],

    'options' => [

        /* ============================================================
           SECTION 1: CANVAS LAYOUT
           ============================================================ */

        'main_canvas_width' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'range',
            'label'    => 'Main Canvas Width',
            'default'  => '960',
            'min'      => '600',
            'max'      => '1400',
            'selector' => '.ge-canvas',
            'property' => 'max-width'
        ],

        'image_max_height' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'range',
            'label'    => 'Image Max Height (vh)',
            'default'  => '85',
            'min'      => '40',
            'max'      => '95',
            'unit'     => 'vh',
            'selector' => '.ge-photo img',
            'property' => 'max-height'
        ],

        /* ============================================================
           SECTION 2: ARCHIVE GRID
           ============================================================ */

        'browse_cols' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'range',
            'label'    => 'Grid Columns',
            'default'  => '4',
            'min'      => '2',
            'max'      => '6',
            'selector' => '.ge-archive-grid',
            'property' => '--grid-cols'
        ],

        'archive_gap' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'range',
            'label'    => 'Grid Gap (px)',
            'default'  => '16',
            'min'      => '4',
            'max'      => '40',
            'selector' => '.ge-archive-grid',
            'property' => 'gap'
        ],

        'archive_border_width' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'range',
            'label'    => 'Thumbnail Border Width (px)',
            'default'  => '4',
            'min'      => '0',
            'max'      => '12',
            'selector' => '.ge-archive-thumb img',
            'property' => 'border-width'
        ],

        /* ============================================================
           SECTION 3: TYPOGRAPHY
           ============================================================ */

        'heading_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Site Title Font',
            'default'  => 'Raleway',
            'options'  => $fonts,
            'selector' => '.ge-site-title, .ge-title-bar .ge-title-text',
            'property' => 'font-family'
        ],

        'heading_font_size' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Site Title Size (px)',
            'default'  => '14',
            'min'      => '10',
            'max'      => '28',
            'selector' => '.ge-site-title',
            'property' => 'font-size'
        ],

        'heading_letter_spacing' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Title Letter Spacing (px)',
            'default'  => '4',
            'min'      => '0',
            'max'      => '12',
            'selector' => '.ge-site-title, .ge-title-bar .ge-title-text, .ge-nav a',
            'property' => 'letter-spacing'
        ],

        'nav_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Navigation Font',
            'default'  => 'Raleway',
            'options'  => $fonts,
            'selector' => '.ge-nav, .ge-title-bar, .ge-caption',
            'property' => 'font-family'
        ],

        'nav_font_size' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Navigation Font Size (px)',
            'default'  => '11',
            'min'      => '8',
            'max'      => '16',
            'selector' => '.ge-nav a, .ge-nav .sep',
            'property' => 'font-size'
        ],

        'body_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body / Caption Font',
            'default'  => 'Cormorant Garamond',
            'options'  => $fonts,
            'selector' => '.ge-caption, .description, .static-content',
            'property' => 'font-family'
        ],

        'body_font_size' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Caption Font Size (px)',
            'default'  => '15',
            'min'      => '11',
            'max'      => '22',
            'selector' => '.ge-caption',
            'property' => 'font-size'
        ],

        /* ============================================================
           SECTION 4: COLOURS
           ============================================================ */

        'bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Background Colour',
            'default'  => '#000000',
            'selector' => 'body',
            'property' => 'background-color'
        ],

        'text_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Primary Text Colour',
            'default'  => '#999999',
            'selector' => 'body',
            'property' => 'color'
        ],

        'link_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Link Colour',
            'default'  => '#bbbbbb',
            'selector' => '.ge-nav a, .ge-title-bar a',
            'property' => 'color'
        ],

        'border_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Border / Rule Colour',
            'default'  => '#333333',
            'selector' => '.ge-title-bar, .ge-nav, .ge-photo img, .ge-archive-thumb img',
            'property' => 'border-color'
        ],

        'photo_border_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Photo Border Colour',
            'default'  => '#ffffff',
            'selector' => '.ge-archive-thumb img',
            'property' => 'border-color'
        ],

        /* ============================================================
           SECTION 5: IMAGE FRAME
           ============================================================ */

        'image_frame_style' => [
            'section'  => 'IMAGE FRAME',
            'type'     => 'select',
            'label'    => 'Single Image Frame',
            'default'  => 'border_thin',
            'selector' => '.ge-photo img',
            'property' => 'custom-framing',
            'options'  => [
                'border_thin' => [
                    'label' => 'Thin Grey Border (1px)',
                    'css'   => '{ border: 1px solid #333333 !important; }'
                ],
                'border_white' => [
                    'label' => 'White Border (3px)',
                    'css'   => '{ border: 3px solid #ffffff !important; }'
                ],
                'border_white_heavy' => [
                    'label' => 'White Border (6px)',
                    'css'   => '{ border: 6px solid #ffffff !important; }'
                ],
                'shadow_soft' => [
                    'label' => 'Soft Shadow',
                    'css'   => '{ border: none !important; box-shadow: 0 4px 24px rgba(0,0,0,0.6) !important; }'
                ],
                'none' => [
                    'label' => 'No Frame',
                    'css'   => '{ border: none !important; }'
                ]
            ]
        ],

        /* ============================================================
           SECTION 6: LANDING PAGE
           ============================================================ */

        'landing_layout' => [
            'section'  => 'LANDING PAGE',
            'type'     => 'select',
            'label'    => 'Landing Layout',
            'default'  => 'split',
            'options'  => [
                'split'  => 'Split (Menu Left / Photo Right)',
                'hero'   => 'Hero (Full-Width Photo + Overlay Nav)',
            ],
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
                '1' => [ 'label' => '1 Column (Single / Reading)', 'css' => '{ grid-template-columns: 1fr; }' ],
                '2' => [ 'label' => '2 Columns',                   'css' => '{ grid-template-columns: repeat(2, 1fr); }' ],
            ]
        ],

        'blogroll_max_width' => [
            'section'  => 'BLOGROLL',
            'type'     => 'range',
            'label'    => 'Content Max Width (px)',
            'default'  => '700',
            'min'      => '500',
            'max'      => '1200',
            'selector' => '.blogroll-canvas',
            'property' => 'max-width'
        ],

    ],

    'admin_styling' => "
        .control-group-flex { display: flex; align-items: center; gap: 20px; }
        .control-group-flex input { flex: 1; }
        .active-val { width: 50px; text-align: right; font-family: monospace; }
        .hex-display { font-family: monospace; }
    ",

    'community_comments'  => '1',
    'community_likes'     => '1',
    'community_reactions' => '0',
];
