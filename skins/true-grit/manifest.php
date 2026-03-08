<?php
/**
 * SNAPSMACK - Configuration manifest for the true-grit skin
 * Alpha v0.7
 *
 * Found-texture photography skin. 50 Shades chassis with photographic wall
 * textures, opacity overlay, and archival frame styles from New Horizon.
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'          => 'True Grit',
    'version'       => '1.0',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'Found-texture photography skin. Photographic wall backgrounds with opacity overlay, archival framing, justified grid, and floating photo wall. Built for foundtextures.ca.',
    'status'        => 'stable',

    'features' => [
        'supports_wall'   => true,
        'archive_layouts' => ['square', 'cropped', 'masonry'],
    ],

    'variants' => [
        'dark'  => 'Dark (Charcoal Base)',
        'light' => 'Light (Warm Grey Base)',
    ],
    'default_variant' => 'dark',

    'require_scripts' => [
        'smack-footer',
        'smack-lightbox',
        'smack-keyboard',
        'smack-justified-lib',
        'smack-justified'
    ],

    'options' => [

        /* ============================================================
           SECTION 1: WALL TEXTURE
           Photographic found-texture backgrounds with opacity overlay.
           Uses background-size: cover (no tiling).
           ============================================================ */

        'htbs_wall_texture' => [
            'section'  => 'WALL TEXTURE',
            'type'     => 'select',
            'label'    => 'Background Texture',
            'default'  => 'paintedconcrete',
            'selector' => 'body',
            'property' => 'custom-wall-texture',
            'options'  => [
                'paintedconcrete'          => ['label' => 'Painted Concrete',           'css' => "{ background-image: url('skins/true-grit/textures/paintedconcrete.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'knottywood'               => ['label' => 'Knotty Wood',                'css' => "{ background-image: url('skins/true-grit/textures/knottywood.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'somewood01'               => ['label' => 'Some Wood I',                'css' => "{ background-image: url('skins/true-grit/textures/somewood01.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'somewood02'               => ['label' => 'Some Wood II',               'css' => "{ background-image: url('skins/true-grit/textures/somewood02.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'somewood03'               => ['label' => 'Some Wood III',              'css' => "{ background-image: url('skins/true-grit/textures/somewood03.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'rust-blueandgold'         => ['label' => 'Blue & Gold (Rust)',         'css' => "{ background-image: url('skins/true-grit/textures/blueandgold.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'rust-streakedauto'        => ['label' => 'Streaked Auto Paint',        'css' => "{ background-image: url('skins/true-grit/textures/streakedautopaint.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'rust-skungyauto'          => ['label' => 'Skungy Auto Paint',          'css' => "{ background-image: url('skins/true-grit/textures/skungyautopaint.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'rust-faintpaintedauto'    => ['label' => 'Faint Painted Auto',         'css' => "{ background-image: url('skins/true-grit/textures/faintpaintedauto.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'rust-yellowcab'           => ['label' => 'Yellow Cab',                 'css' => "{ background-image: url('skins/true-grit/textures/yellowcab.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'rust-usedtobeblue'        => ['label' => 'Used to be Blue',            'css' => "{ background-image: url('skins/true-grit/textures/usedtobeblue.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'rust-notablackpink'       => ['label' => 'Not a Blackpink Concert',    'css' => "{ background-image: url('skins/true-grit/textures/notablackpinkconcert.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'rust-twolayers'           => ['label' => 'Two Layers of Paint vs None','css' => "{ background-image: url('skins/true-grit/textures/twolayersofpaintvsnone.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'rust-seeingbottom'        => ['label' => 'Seeing the Bottom Layer',    'css' => "{ background-image: url('skins/true-grit/textures/seeingthebottomlayer.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'rust-alittleofall'        => ['label' => 'A Little of Everything',     'css' => "{ background-image: url('skins/true-grit/textures/alittleofeverything.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'wallpaper-stainedpastel'  => ['label' => 'Stained Pastel Wallpaper',   'css' => "{ background-image: url('skins/true-grit/textures/stainedpastelwallpaper.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'wallpaper-tea'            => ['label' => 'Tea Wallpaper',              'css' => "{ background-image: url('skins/true-grit/textures/teawallpaper.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'wallpaper-layers'         => ['label' => 'Wallpaper Layers',           'css' => "{ background-image: url('skins/true-grit/textures/wallpaperlayers.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'wallpaper-planty'         => ['label' => 'Some Sort of Plant Wallpaper','css' => "{ background-image: url('skins/true-grit/textures/somesortofplantwallpaper.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'leafygoodness'            => ['label' => 'Leafy Goodness',             'css' => "{ background-image: url('skins/true-grit/textures/leafygoodness.jpg'); background-size: cover; background-repeat: no-repeat; background-attachment: fixed; }"],
                'solid'                    => ['label' => 'Solid Colour (No Texture)',   'css' => '{ background-image: none; }'],
            ]
        ],

        'htbs_wall_color' => [
            'section'  => 'WALL TEXTURE',
            'type'     => 'color',
            'label'    => 'Wall Fallback Colour',
            'default'  => '#2a2a2a',
            'selector' => ':root',
            'property' => '--wall-bg',
        ],

        'wall_overlay_opacity' => [
            'section'  => 'WALL TEXTURE',
            'type'     => 'range',
            'label'    => 'Overlay Opacity (%)',
            'default'  => '40',
            'min'      => '0',
            'max'      => '100',
            'unit'     => '',
            'selector' => ':root',
            'property' => '--overlay-opacity'
        ],

        'wall_overlay_color' => [
            'section'  => 'WALL TEXTURE',
            'type'     => 'color',
            'label'    => 'Overlay Colour',
            'default'  => '#1a1a1a',
            'selector' => ':root',
            'property' => '--overlay-color'
        ],

        'header_bg_color' => [
            'section'  => 'WALL TEXTURE',
            'type'     => 'color',
            'label'    => 'Header Background',
            'default'  => '#1a1a1a',
            'selector' => '#tg-header',
            'property' => 'background-color'
        ],

        'footer_bg_color' => [
            'section'  => 'WALL TEXTURE',
            'type'     => 'color',
            'label'    => 'Footer Background',
            'default'  => '#1e1e1e',
            'selector' => '#system-footer',
            'property' => 'background-color'
        ],

        /* ============================================================
           SECTION 2: CANVAS LAYOUT
           ============================================================ */

        'main_canvas_width' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'range',
            'label'    => 'Main Canvas Width',
            'default'  => '1280',
            'min'      => '800',
            'max'      => '1920',
            'selector' => '.tg-header-inside, .tg-photo-wrap, #system-footer .inside, #browse-grid, #justified-grid',
            'property' => 'max-width'
        ],

        'gutter_padding' => [
            'section'  => 'CANVAS LAYOUT',
            'type'     => 'range',
            'label'    => 'Outer Gutter Padding',
            'default'  => '40',
            'min'      => '0',
            'max'      => '200',
            'selector' => '.tg-header-inside, .tg-photo-wrap, #browse-grid',
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
            'selector' => '.tg-header-inside',
            'property' => 'flex-direction'
        ],

        /* ============================================================
           SECTION 3: ARCHIVE GRID
           ============================================================ */

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

        'archive_aspect_ratio' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'select',
            'label'    => 'Thumbnail Aspect Ratio Lock',
            'default'  => 'auto',
            'selector' => '.square-grid .thumb-link, .cropped-grid .thumb-link',
            'property' => 'custom-aspect-ratio',
            'options'  => [
                'auto'      => ['label' => 'Natural (No Lock)',               'css' => '{ aspect-ratio: auto; overflow: visible; }'],
                '1-1'       => ['label' => '1:1 Square',                      'css' => '{ aspect-ratio: 1 / 1; overflow: hidden; }'],
                '4-3'       => ['label' => '4:3 Landscape',                   'css' => '{ aspect-ratio: 4 / 3; overflow: hidden; }'],
                '3-2'       => ['label' => '3:2 Landscape',                   'css' => '{ aspect-ratio: 3 / 2; overflow: hidden; }'],
                '16-9'      => ['label' => '16:9 Landscape (Cinematic)',      'css' => '{ aspect-ratio: 16 / 9; overflow: hidden; }'],
                '3-4'       => ['label' => '3:4 Portrait',                    'css' => '{ aspect-ratio: 3 / 4; overflow: hidden; }'],
                '2-3'       => ['label' => '2:3 Portrait',                    'css' => '{ aspect-ratio: 2 / 3; overflow: hidden; }'],
            ]
        ],

        /* ============================================================
           SECTION 4: FRAMING
           New Horizon archival frame styles.
           ============================================================ */

        'image_frame_style' => [
            'section'  => 'FRAMING',
            'type'     => 'select',
            'label'    => 'Hero Image Frame',
            'default'  => 'shadow_float',
            'selector' => 'img.post-image, img.tg-image, .inline-asset, .static-transmission .description .align-left',
            'property' => 'custom-framing',
            'options'  => [
                'shadow_float' => [
                    'label' => 'Shadow Float (No Border)',
                    'css'   => '{ border: none !important; box-shadow: 0 8px 40px rgba(0,0,0,0.5), 0 2px 10px rgba(0,0,0,0.3) !important; }'
                ],
                'revival_double' => [
                    'label' => 'Revival Double Line (Grey/Black/Grey)',
                    'css'   => '{ border: 5px solid #666666 !important; box-shadow: 0 0 0 15px #000000, 0 0 0 16px #666666 !important; }'
                ],
                'classic_white' => [
                    'label' => 'Classic Horizon (Thick White)',
                    'css'   => '{ border: 20px solid #ffffff !important; box-shadow: 0 0 0 1px #333333 !important; }'
                ],
                'gallery_mat' => [
                    'label' => 'Gallery Multi-Mat (White / Med-Grey / White)',
                    'css'   => '{ border: 3px solid #ffffff !important; box-shadow: 0 0 0 15px #666666, 0 0 0 16px #ffffff !important; }'
                ],
                'minimal_bevel' => [
                    'label' => 'Minimal Bevel (Thin White / Black / White)',
                    'css'   => '{ border: 1px solid #ffffff !important; box-shadow: 0 0 0 8px #000000, 0 0 0 9px #ffffff !important; }'
                ],
                'obsidian' => [
                    'label' => 'Obsidian (Dark Grey / Black / Dark Grey)',
                    'css'   => '{ border: 1px solid #333333 !important; box-shadow: 0 0 0 20px #111111, 0 0 0 21px #333333 !important; }'
                ],
                'none' => [
                    'label' => 'No Frame',
                    'css'   => '{ border: none !important; box-shadow: none !important; }'
                ]
            ]
        ],

        'archive_frame_style' => [
            'section'  => 'FRAMING',
            'type'     => 'select',
            'label'    => 'Archive Thumb Frame',
            'default'  => 'shadow_soft',
            'selector' => '.square-grid .thumb-link, .cropped-grid .thumb-link, .justified-item',
            'property' => 'custom-framing',
            'options'  => [
                'shadow_soft' => [
                    'label' => 'Soft Shadow',
                    'css'   => '{ border: none !important; box-shadow: 0 4px 20px rgba(0,0,0,0.4) !important; }'
                ],
                'thin_grey' => [
                    'label' => 'Thin Grey (1px)',
                    'css'   => '{ border: 1px solid #666666 !important; box-shadow: none !important; }'
                ],
                'thin_white' => [
                    'label' => 'Thin White (1px)',
                    'css'   => '{ border: 1px solid #ffffff !important; box-shadow: none !important; }'
                ],
                'medium_grey' => [
                    'label' => 'Medium Grey (3px)',
                    'css'   => '{ border: 3px solid #666666 !important; box-shadow: none !important; }'
                ],
                'none' => [
                    'label' => 'No Frame',
                    'css'   => '{ border: none !important; box-shadow: none !important; }'
                ]
            ]
        ],

        /* ============================================================
           SECTION 5: TYPOGRAPHY
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

        'header_font_size' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Header Font Size (px)',
            'default'  => '50',
            'min'      => '12',
            'max'      => '120',
            'selector' => '.site-title-text',
            'property' => 'font-size'
        ],

        'header_text_transform' => [
            'section'  => 'TYPOGRAPHY',
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
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Header Letter Spacing (px)',
            'default'  => '3',
            'min'      => '-2',
            'max'      => '15',
            'selector' => '.site-title-text',
            'property' => 'letter-spacing'
        ],

        'header_font_weight' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Header Font Weight',
            'default'  => '300',
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

        'footer_font_size' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Footer Font Size (px)',
            'default'  => '11',
            'min'      => '8',
            'max'      => '18',
            'selector' => '#system-footer p, #sig-text',
            'property' => 'font-size'
        ],

        /* ============================================================
           SECTION 6: VERTICAL LOCKS
           ============================================================ */

        'optical_lift' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Optical Vertical Lift (px)',
            'default'  => '50',
            'min'      => '0',
            'max'      => '150',
            'selector' => '#tg-photobox',
            'property' => 'padding-bottom'
        ],

        'header_height' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Header Height Lock (px)',
            'default'  => '80',
            'min'      => '40',
            'max'      => '150',
            'selector' => '#tg-header',
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

        'footer_height' => [
            'section'  => 'VERTICAL LOCKS',
            'type'     => 'range',
            'label'    => 'Footer Height Lock (px)',
            'default'  => '32',
            'min'      => '16',
            'max'      => '80',
            'selector' => '#system-footer',
            'property' => 'padding-top, padding-bottom'
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
           SECTION 7: WALL SPECIFIC (Floating Gallery Physics)
           ============================================================ */

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

        /* ============================================================
           SECTION 8: BLOGROLL
           ============================================================ */

        'blogroll_columns' => [
            'section'  => 'BLOGROLL',
            'type'     => 'select',
            'label'    => 'Column Layout',
            'default'  => '1',
            'selector' => '.blogroll-grid',
            'property' => 'custom-cols',
            'options'  => [
                '1' => ['label' => '1 Column (Single / Reading)', 'css' => '{ grid-template-columns: 1fr; }'],
                '2' => ['label' => '2 Columns',                   'css' => '{ grid-template-columns: repeat(2, 1fr); }'],
                '3' => ['label' => '3 Columns',                   'css' => '{ grid-template-columns: repeat(3, 1fr); }'],
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
