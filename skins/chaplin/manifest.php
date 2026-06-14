<?php
/**
 * SNAPSMACK - Chaplin Skin Manifest v0.2.7
 *
 * Silent-film-era B&W skin. RG base structure, Cinzel/Cormorant typography,
 * CSS outline+box-shadow border system, full-black intertitle overlay,
 * horizontal filmstrip, Art Deco ornament placement.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$all_fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $all_fonts[$_k] = $_f['label'];

// Filter to vintage-appropriate typefaces only.
$vintage_keywords = [
    'cinzel','playfair','cormorant','garamond','baskerville','old standard',
    'fell','palatino','goudy','bodoni','caslon','crimson','spectral','cardo',
    'lora','libre','merriweather','sorts','uncial','blackletter','didone',
    'flott','antic','poiret',
    'josefin','century','clarendon','cheltenham','bookman','optima',
];
$vintage_fonts = array_filter($all_fonts, function ($label, $key) use ($vintage_keywords) {
    $haystack = strtolower($key . ' ' . $label);
    foreach ($vintage_keywords as $kw) {
        if (strpos($haystack, $kw) !== false) return true;
    }
    return false;
}, ARRAY_FILTER_USE_BOTH);
if (empty($vintage_fonts)) $vintage_fonts = $all_fonts;

return [
    'name'        => 'Chaplin',
    'version'     => '0.2.10',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'Silent-film era. Near-black canvas, B&W photo treatment, Art Deco border system with ornament placement, animated film effects. Full-screen intertitle overlay for info and signals.',
    'status'      => 'dev',

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
        // smack-footer  — REMOVED: Chaplin uses its own overlay controller in skin-header.php;
        //   including ss-engine-footer.js overwrites window.smackdown.toggleFooter,
        //   breaking keyboard shortcuts and the smackdown bridge.
        // smack-overlay — REMOVED: targets #htbs-info-overlay (Galleria); doesn't exist in
        //   Chaplin. Including it overwrites smackdown.toggleFooter with a null-overlay
        //   version, making keyboard info/comments shortcuts non-functional.
        'smack-image-fade-load',
        'smack-lightbox',
        'smack-keyboard',
        'smack-community',
        'smack-archive-toggle',
        'smack-chaplin-film',
        'smack-chaplin-overlay',
    ],

    'options' => [

        // ── FILM EFFECTS ──────────────────────────────────────────────────────
        'chap_grain_intensity' => [
            'section' => 'FILM EFFECTS',
            'type'    => 'range',
            'label'   => 'Static Grain Intensity',
            'default' => '4',
            'min'     => '0',
            'max'     => '12',
            'unit'    => '',
            // NOTE: No selector/property. skin-header.php converts raw int (0-12)
            // to opacity fraction (÷100) before emitting --chap-grain-opacity.
            // smack-skin.php must NOT emit this var directly — it would output the
            // raw integer (e.g. "2") which browsers clamp to opacity:1, blocking
            // everything below the grain overlay.
        ],
        'chap_flicker' => [
            'section' => 'FILM EFFECTS',
            'type'    => 'select',
            'label'   => 'Load Flicker',
            'default' => '1',
            'options' => ['1' => 'On', '0' => 'Off'],
        ],
        'chap_scratch_freq' => [
            'section' => 'FILM EFFECTS',
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

        // ── BORDER ────────────────────────────────────────────────────────────
        'chap_line_count' => [
            'section' => 'BORDER',
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
            'section'  => 'BORDER',
            'type'     => 'range',
            'label'    => 'Rule 1 Thickness (px)',
            'default'  => '2',
            'min'      => '1',
            'max'      => '8',
            'selector' => '',
            'property' => '',
        ],
        'chap_line_2_width' => [
            'section'  => 'BORDER',
            'type'     => 'range',
            'label'    => 'Rule 2 Thickness (px)',
            'default'  => '1',
            'min'      => '1',
            'max'      => '6',
            'selector' => '',
            'property' => '',
        ],
        'chap_line_3_width' => [
            'section'  => 'BORDER',
            'type'     => 'range',
            'label'    => 'Rule 3 Thickness (px)',
            'default'  => '1',
            'min'      => '1',
            'max'      => '6',
            'selector' => '',
            'property' => '',
        ],
        'chap_line_gap' => [
            'section'  => 'BORDER',
            'type'     => 'range',
            'label'    => 'Gap Between Rules (px)',
            'default'  => '8',
            'min'      => '4',
            'max'      => '24',
            'selector' => '',
            'property' => '',
        ],
        'chap_frame_gap' => [
            'section'  => 'BORDER',
            'type'     => 'range',
            'label'    => 'Photo-to-Frame Gap (px)',
            'default'  => '8',
            'min'      => '0',
            'max'      => '60',
            'selector' => '',
            'property' => '',
        ],

        // ── ORNAMENTS ─────────────────────────────────────────────────────────
        'chap_ornament_style' => [
            'section' => 'ORNAMENTS',
            'type'    => 'select',
            'label'   => 'Ornament Style',
            'default' => 'A',
            'options' => [
                'none' => 'None (Lines Only)',
                'A'    => 'A — Stepped Sunburst',
                'B'    => 'B — Minimal Chevrons',
                'C'    => 'C — Heavy Deco Fan',
                'D'    => 'D — Geometric Modernist',
            ],
        ],
        'chap_corner_ornaments' => [
            'section' => 'ORNAMENTS',
            'type'    => 'select',
            'label'   => 'Corner Ornaments',
            'default' => '1',
            'options' => ['1' => 'On', '0' => 'Off'],
        ],
        'chap_mid_top_bot' => [
            'section' => 'ORNAMENTS',
            'type'    => 'select',
            'label'   => 'Mid Top & Bottom',
            'default' => '0',
            'options' => ['1' => 'On', '0' => 'Off'],
        ],
        'chap_mid_left_right' => [
            'section' => 'ORNAMENTS',
            'type'    => 'select',
            'label'   => 'Mid Left & Right',
            'default' => '0',
            'options' => ['1' => 'On', '0' => 'Off'],
        ],
        'chap_ornament_gap' => [
            'section'  => 'ORNAMENTS',
            'type'     => 'range',
            'label'    => 'Decoration Spacing (px)',
            'default'  => '8',
            'min'      => '0',
            'max'      => '20',
            'selector' => '',
            'property' => '',
        ],

        // ── TITLE ─────────────────────────────────────────────────────────────
        'chap_title_position' => [
            'section' => 'TITLE',
            'type'    => 'select',
            'label'   => 'Title Position',
            'default' => 'below_photo',
            'options' => [
                'below_photo' => 'Below Photo',
                'info_tray'   => 'Info Tray Only',
                'hidden'      => 'Hidden',
            ],
        ],
        'chap_card_style' => [
            'section' => 'TITLE',
            'type'    => 'select',
            'label'   => 'Info Tray Detail',
            'default' => 'card',
            'options' => [
                'card'    => 'Full (Title + Date)',
                'minimal' => 'Title Only',
                'hidden'  => 'Hidden',
            ],
        ],

        // ── LAYOUT ────────────────────────────────────────────────────────────
        'chap_photo_max_width' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Content Max Width (px)',
            'default'  => '1600',
            'min'      => '800',
            'max'      => '2560',
            'step'     => '40',
            'selector' => ':root',
            'property' => '--chap-photo-max-width',
            'unit'     => 'px',
        ],
        'chap_photo_pad_v' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Photo Vertical Padding (px)',
            'default'  => '56',
            'min'      => '0',
            'max'      => '120',
            'step'     => '4',
            'selector' => '',
            'property' => '',
        ],
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
        'single_show_description' => [
            'section' => 'LAYOUT',
            'type'    => 'select',
            'label'   => 'Show Description in Info Tray',
            'default' => '1',
            'options' => ['1' => 'Yes', '0' => 'No'],
        ],

        // ── TYPOGRAPHY ────────────────────────────────────────────────────────
        'chap_title_font' => [
            'section'         => 'TYPOGRAPHY',
            'type'            => 'select',
            'label'           => 'Masthead Font',
            'default'         => 'Cinzel',
            'options'         => $vintage_fonts,
            'selector'        => ':root',
            'property'        => '--chap-title-font',
            'is_font'         => true,
            'sz_key_override' => 'chap_title_size',
            'size'            => ['min' => '6', 'max' => '30', 'default' => '11', 'step' => '1', 'unit' => '×0.1REM'],
        ],
        'chap_title_case' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Site Title Case',
            'default'  => 'uppercase',
            'options'  => [
                'uppercase'  => 'UPPERCASE',
                'lowercase'  => 'lowercase',
                'capitalize' => 'Capitalize Each Word',
                'none'       => 'As Entered (No Transform)',
            ],
            'selector' => '.rg-masthead',
            'property' => 'text-transform',
        ],
        'chap_heading_font' => [
            'section'         => 'TYPOGRAPHY',
            'type'            => 'select',
            'label'           => 'Heading / Intertitle Font',
            'default'         => 'Cinzel',
            'options'         => $vintage_fonts,
            'selector'        => ':root',
            'property'        => '--chap-heading-font',
            'is_font'         => true,
            'sz_key_override' => 'chap_heading_size',
            'size'            => ['min' => '6', 'max' => '24', 'default' => '9', 'step' => '1', 'unit' => '×0.1REM'],
        ],
        'chap_body_font' => [
            'section'         => 'TYPOGRAPHY',
            'type'            => 'select',
            'label'           => 'Body / Description Font',
            'default'         => 'Cormorant Garamond',
            'options'         => $vintage_fonts,
            'selector'        => ':root',
            'property'        => '--chap-body-font',
            'is_font'         => true,
            'sz_key_override' => 'chap_body_size',
            'size'            => ['min' => '8', 'max' => '16', 'default' => '10', 'step' => '1', 'unit' => '×0.1REM'],
        ],
        'chap_footer_font' => [
            'section'         => 'TYPOGRAPHY',
            'type'            => 'select',
            'label'           => 'Footer Font',
            'default'         => 'monospace',
            'options'         => $vintage_fonts,
            'selector'        => ':root',
            'property'        => '--chap-footer-font',
            'is_font'         => true,
            'sz_key_override' => 'chap_footer_size',
            'size'            => ['min' => '5', 'max' => '14', 'default' => '7', 'step' => '1', 'unit' => '×0.1REM'],
        ],

        // ── PAGE TITLE ───────────────────────────────────────────────────────
        // Controls h1 on static pages (blogroll, archive, contact, etc.).
        // Separate from the masthead — see naming convention in continuity notes.
        'chap_page_title_font' => [
            'section'         => 'TYPOGRAPHY',
            'type'            => 'select',
            'label'           => 'Page Title Font',
            'default'         => 'Cinzel',
            'options'         => $vintage_fonts,
            'selector'        => ':root',
            'property'        => '--chap-page-title-font',
            'is_font'         => true,
            'sz_key_override' => 'chap_page_title_size',
            'size'            => ['min' => '8', 'max' => '24', 'default' => '12', 'step' => '1', 'unit' => '×0.1REM'],
        ],

        // ── NAV ───────────────────────────────────────────────────────────────
        'chap_nav_font' => [
            'section'         => 'TYPOGRAPHY',
            'type'            => 'select',
            'label'           => 'Nav Font',
            'default'         => 'Cinzel',
            'options'         => $vintage_fonts,
            'selector'        => ':root',
            'property'        => '--chap-nav-font',
            'is_font'         => true,
            'sz_key_override' => 'chap_nav_size',
            'size'            => ['min' => '5', 'max' => '12', 'default' => '7', 'step' => '1', 'unit' => '×0.1REM'],
        ],
        'chap_nav_color' => [
            'section'       => 'TYPOGRAPHY',
            'type'          => 'color',
            'label'         => 'Nav Color',
            'default'       => '#d4d4d4',
            'selector'      => ':root',
            'property'      => '--chap-nav-color',
            'is_greyscale'  => true,
        ],
        'chap_footer_color' => [
            'section'       => 'TYPOGRAPHY',
            'type'          => 'color',
            'label'         => 'Footer Color',
            'default'       => '#8a8579',
            'selector'      => ':root',
            'property'      => '--chap-footer-color',
            'is_greyscale'  => true,
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
