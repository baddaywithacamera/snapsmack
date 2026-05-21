<?php
/**
 * SNAPSMACK - Chaplin Skin Manifest
 *
 * Silent-film-era skin. Near-black canvas, Art Deco ornament frame,
 * sepia or B&W photo treatment, film scratch animation, gate-slip flicker.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$all_fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $all_fonts[$_k] = $_f['label'];

// Filter to vintage-appropriate typefaces only.
// Whitelist matches against key OR label (case-insensitive).
$vintage_keywords = [
    'cinzel','playfair','cormorant','garamond','baskerville','old standard',
    'fell','palatino','goudy','bodoni','caslon','crimson','spectral','cardo',
    'lora','libre','merriweather','sorts','uncial','blackletter','didone',
    'alligator','flott','night','antic','poiret',
    'josefin','century','clarendon','cheltenham','bookman','optima',
];
$vintage_fonts = array_filter($all_fonts, function ($label, $key) use ($vintage_keywords) {
    $haystack = strtolower($key . ' ' . $label);
    foreach ($vintage_keywords as $kw) {
        if (strpos($haystack, $kw) !== false) return true;
    }
    return false;
}, ARRAY_FILTER_USE_BOTH);
if (empty($vintage_fonts)) $vintage_fonts = $all_fonts; // safety fallback

return [
    'name'        => 'Chaplin',
    'version'     => '2.1',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'Silent film era. Near-black canvas, Art Deco ornament frame, sepia or B&W photo treatment. Film scratches animate behind the image; the frame flickers and occasionally slips.',
    'status'      => 'stable',

    'features' => [
        'supports_wall'    => false,
        'archive_layouts'  => ['square', 'masonry'],
        'supports_slider'  => false,
        'has_landing'      => true,
        'post_modes'       => ['image'],
        'instagram_mode'   => false,
        'carousel'         => false,
        'community'        => ['likes', 'comments'],
    ],

    'require_scripts' => [
        'smack-footer',
        'smack-image-fade-load',
        'smack-lightbox',
        'smack-keyboard',
        'smack-community',
        'smack-overlay',
    ],

    'options' => [

        // ── FILM TONE ─────────────────────────────────────────────────────────
        'chap_tone' => [
            'section' => 'FILM TONE',
            'type'    => 'select',
            'label'   => 'Tone',
            'default' => 'sepia',
            'options' => [
                'sepia' => 'Dark Sepia (Warm / Aged)',
                'bw'    => 'Black & White (Neutral)',
            ],
        ],
        'chap_grain_intensity' => [
            'section'  => 'FILM TONE',
            'type'     => 'range',
            'label'    => 'Static Grain Intensity',
            'default'  => '4',
            'min'      => '0',
            'max'      => '12',
            'selector' => ':root',
            'property' => '--chap-grain-opacity',
            'unit'     => '',
        ],
        'chap_vignette' => [
            'section' => 'FILM TONE',
            'type'    => 'select',
            'label'   => 'Vignette',
            'default' => '1',
            'options' => ['1' => 'On', '0' => 'Off'],
        ],
        'chap_flicker' => [
            'section' => 'FILM TONE',
            'type'    => 'select',
            'label'   => 'Load Flicker',
            'default' => '1',
            'options' => ['1' => 'On', '0' => 'Off'],
        ],
        'chap_scratch_freq' => [
            'section' => 'FILM TONE',
            'type'    => 'select',
            'label'   => 'Film Scratch Frequency',
            'default' => 'normal',
            'options' => [
                'off'    => 'Off',
                'sparse' => 'Sparse',
                'normal' => 'Normal',
                'heavy'  => 'Heavy',
            ],
        ],

        // ── DECO FRAME ────────────────────────────────────────────────────────
        'chap_deco_style' => [
            'section' => 'DECO FRAME',
            'type'    => 'select',
            'label'   => 'Frame Line Style',
            'default' => 'corners',
            'options' => [
                'corners'    => 'Corners Only',
                'mid-breaks' => 'Corners + Mid Breaks',
                'full'       => 'Full Border',
            ],
        ],
        'chap_ornament_style' => [
            'section' => 'DECO FRAME',
            'type'    => 'select',
            'label'   => 'Ornament Style',
            'default' => 'A',
            'options' => [
                'none' => 'None',
                'A'    => 'A — Stepped Sunburst',
                'B'    => 'B — Minimal Chevrons',
                'C'    => 'C — Heavy Deco Fan',
                'D'    => 'D — Geometric Modernist',
            ],
        ],
        'chap_line_count' => [
            'section' => 'DECO FRAME',
            'type'    => 'select',
            'label'   => 'Number of Rules',
            'default' => '1',
            'options' => [
                '1' => 'Single Rule',
                '2' => 'Double Rule',
                '3' => 'Triple Rule',
            ],
        ],
        'chap_line_1_width' => [
            'section'  => 'DECO FRAME',
            'type'     => 'range',
            'label'    => 'Rule 1 Thickness (px)',
            'default'  => '2',
            'min'      => '1',
            'max'      => '5',
            'selector' => '',
            'property' => '',
        ],
        'chap_line_2_width' => [
            'section'  => 'DECO FRAME',
            'type'     => 'range',
            'label'    => 'Rule 2 Thickness (px)',
            'default'  => '1',
            'min'      => '1',
            'max'      => '5',
            'selector' => '',
            'property' => '',
        ],
        'chap_line_3_width' => [
            'section'  => 'DECO FRAME',
            'type'     => 'range',
            'label'    => 'Rule 3 Thickness (px)',
            'default'  => '1',
            'min'      => '1',
            'max'      => '5',
            'selector' => '',
            'property' => '',
        ],
        'chap_line_gap' => [
            'section'  => 'DECO FRAME',
            'type'     => 'range',
            'label'    => 'Gap Between Rules (vb units)',
            'default'  => '8',
            'min'      => '4',
            'max'      => '24',
            'selector' => '',
            'property' => '',
        ],
        'chap_line_color' => [
            'section'  => 'DECO FRAME',
            'type'     => 'color',
            'label'    => 'Line & Ornament Colour',
            'default'  => '#ece6d4',
            'selector' => ':root',
            'property' => '--chap-deco-color',
        ],

        // ── GALLERY WALL ──────────────────────────────────────────────────────
        'chap_wall_color' => [
            'section'  => 'GALLERY WALL',
            'type'     => 'color',
            'label'    => 'Wall Colour',
            'default'  => '#100e0b',
            'selector' => ':root',
            'property' => '--wall-bg',
        ],

        // ── PICTURE FRAMES ────────────────────────────────────────────────────
        'chap_frame_color' => [
            'section'  => 'PICTURE FRAMES',
            'type'     => 'color',
            'label'    => 'Frame Colour',
            'default'  => '#1a1410',
            'selector' => ':root',
            'property' => '--frame-color',
        ],
        'chap_frame_width' => [
            'section'  => 'PICTURE FRAMES',
            'type'     => 'range',
            'label'    => 'Frame Width (px)',
            'default'  => '10',
            'min'      => '3',
            'max'      => '22',
            'selector' => ':root',
            'property' => '--frame-width',
            'unit'     => 'px',
        ],
        'chap_mat_color' => [
            'section'  => 'PICTURE FRAMES',
            'type'     => 'color',
            'label'    => 'Mat Colour',
            'default'  => '#f5efdf',
            'selector' => ':root',
            'property' => '--mat-color',
        ],
        'chap_mat_width' => [
            'section'  => 'PICTURE FRAMES',
            'type'     => 'range',
            'label'    => 'Mat Width (px)',
            'default'  => '28',
            'min'      => '8',
            'max'      => '72',
            'selector' => ':root',
            'property' => '--mat-width',
            'unit'     => 'px',
        ],
        'chap_bevel_style' => [
            'section' => 'PICTURE FRAMES',
            'type'    => 'select',
            'label'   => 'Bevel Style',
            'default' => 'single',
            'options' => [
                'none'   => 'No Bevel',
                'single' => 'Single Bevel',
                'double' => 'Double Bevel',
            ],
        ],

        // ── HEADER ────────────────────────────────────────────────────────────
        'chap_header_bg' => [
            'section'  => 'HEADER',
            'type'     => 'color',
            'label'    => 'Header Background',
            'default'  => '#0a0806',
            'selector' => '.chap-header',
            'property' => 'background-color',
        ],
        'chap_nav_color' => [
            'section'  => 'HEADER',
            'type'     => 'color',
            'label'    => 'Nav Link Colour',
            'default'  => '#c8bfaa',
            'selector' => '.chap-header .nav-menu a',
            'property' => 'color',
        ],
        'chap_nav_hover' => [
            'section'  => 'HEADER',
            'type'     => 'color',
            'label'    => 'Nav Link Hover',
            'default'  => '#f5efdf',
            'selector' => '.chap-header .nav-menu a:hover',
            'property' => 'color',
        ],

        // ── FOOTER ────────────────────────────────────────────────────────────
        'chap_footer_bg' => [
            'section'  => 'FOOTER',
            'type'     => 'color',
            'label'    => 'Footer Background',
            'default'  => '#0a0806',
            'selector' => '.chap-footer, footer',
            'property' => 'background-color',
        ],
        'chap_footer_text' => [
            'section'  => 'FOOTER',
            'type'     => 'color',
            'label'    => 'Footer Text',
            'default'  => '#7a6e5c',
            'selector' => '.chap-footer, footer',
            'property' => 'color',
        ],
        'chap_footer_link' => [
            'section'  => 'FOOTER',
            'type'     => 'color',
            'label'    => 'Footer Link',
            'default'  => '#c8860a',
            'selector' => '.chap-footer a, footer a',
            'property' => 'color',
        ],

        // ── INTERTITLE CARD ───────────────────────────────────────────────────
        'chap_card_style' => [
            'section' => 'INTERTITLE CARD',
            'type'    => 'select',
            'label'   => 'Caption Style',
            'default' => 'card',
            'options' => [
                'card'    => 'Intertitle Card (Full)',
                'minimal' => 'Minimal (Title Only)',
                'hidden'  => 'Hidden',
            ],
        ],
        'chap_card_bg' => [
            'section'  => 'INTERTITLE CARD',
            'type'     => 'color',
            'label'    => 'Card Background',
            'default'  => '#0d0b08',
            'selector' => ':root',
            'property' => '--chap-card-bg',
        ],
        'chap_card_text' => [
            'section'  => 'INTERTITLE CARD',
            'type'     => 'color',
            'label'    => 'Card Text',
            'default'  => '#f0e8d4',
            'selector' => ':root',
            'property' => '--chap-card-text',
        ],

        // ── ARCHIVE GRID ──────────────────────────────────────────────────────
        'chap_archive_cols' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'range',
            'label'    => 'Grid Columns',
            'default'  => '4',
            'min'      => '2',
            'max'      => '6',
            'selector' => '.chap-archive-grid',
            'property' => '--grid-cols',
        ],
        'chap_archive_max_width' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'range',
            'label'    => 'Grid Max Width (px)',
            'default'  => '1600',
            'min'      => '900',
            'max'      => '2400',
            'selector' => '.chap-archive-grid',
            'property' => 'max-width',
            'unit'     => 'px',
        ],
        'chap_archive_gap' => [
            'section'  => 'ARCHIVE GRID',
            'type'     => 'range',
            'label'    => 'Grid Gap (px)',
            'default'  => '20',
            'min'      => '4',
            'max'      => '60',
            'selector' => '.chap-archive-grid',
            'property' => 'gap',
            'unit'     => 'px',
        ],
        'chap_show_titles' => [
            'section' => 'ARCHIVE GRID',
            'type'    => 'select',
            'label'   => 'Show Titles on Grid',
            'default' => '1',
            'options' => ['1' => 'Yes', '0' => 'No'],
            'selector' => ':root',
            'property' => '--chap-show-titles',
        ],

        // ── LAYOUT ────────────────────────────────────────────────────────────
        'chap_header_height' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Header Height (px)',
            'default'  => '56',
            'min'      => '40',
            'max'      => '100',
            'selector' => ':root',
            'property' => '--header-height',
            'unit'     => 'px',
        ],

        // ── COLOURS ───────────────────────────────────────────────────────────
        'chap_accent' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Accent (Projector Amber)',
            'default'  => '#c8860a',
            'selector' => ':root',
            'property' => '--chap-amber',
        ],
        'chap_text_primary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Primary Text',
            'default'  => '#e8e0cc',
            'selector' => ':root',
            'property' => '--chap-ink',
        ],
        'chap_text_secondary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Secondary Text',
            'default'  => '#8b7355',
            'selector' => ':root',
            'property' => '--chap-sepia',
        ],

        // ── TYPOGRAPHY ────────────────────────────────────────────────────────
        'chap_title_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Site Title Font',
            'default'  => 'Cinzel',
            'options'  => $vintage_fonts,
            'selector' => '.chap-header .site-title-text',
            'property' => 'font-family',
        ],
        'chap_title_size' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Site Title Size (px)',
            'default'  => '26',
            'min'      => '12',
            'max'      => '100',
            'selector' => '.chap-header .site-title-text',
            'property' => 'font-size',
            'unit'     => 'px',
        ],
        'chap_title_color' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'color',
            'label'    => 'Site Title Colour',
            'default'  => '#f5efdf',
            'selector' => '.chap-header .site-title-text',
            'property' => 'color',
        ],
        'chap_heading_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Intertitle / Heading Font',
            'default'  => 'ColdNightForAlligators',
            'options'  => $vintage_fonts,
            'selector' => '.chap-intertitle-title, .chap-card-heading',
            'property' => 'font-family',
        ],
        'chap_body_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body Font',
            'default'  => 'FlottFlott',
            'options'  => $vintage_fonts,
            'selector' => 'body, .description, .meta, .chap-intertitle-body',
            'property' => 'font-family',
        ],

        // ── PRESETS ───────────────────────────────────────────────────────────
        'chap_presets' => [
            'section' => 'PRESETS',
            'type'    => 'hidden',
            'label'   => 'Saved Presets (JSON)',
            'default' => '[]',
        ],

    ],

    'admin_styling' => "
        .control-group-flex { display: flex; align-items: center; gap: 20px; }
        .control-group-flex input { flex: 1; }
        .active-val { width: 50px; text-align: right; font-family: monospace; }
    ",

    'community_comments'  => '1',
    'community_likes'     => '1',
    'community_reactions' => '0',
];
// ===== SNAPSMACK EOF =====
