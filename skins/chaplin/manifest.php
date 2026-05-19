<?php
/**
 * SNAPSMACK - Chaplin Skin Manifest
 *
 * Silent-film-era skin. Deep black, aged paper mats, film grain overlay,
 * intertitle card captions, and every image processed through a film stock
 * filter. Square crop. Built for squaredstraight.ca.
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
    'name'        => 'Chaplin',
    'version'     => '1.3',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'Silent film era. Deep black, aged paper mats, film grain, and every photograph run through a film stock filter. Square crop. It flickers.',
    'status'      => 'stable',

    'features' => [
        'supports_wall'    => false,
        'archive_layouts'  => ['square', 'masonry'],
        'supports_slider'  => true,
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
        'smack-slider',
        'smack-community',
        'smack-overlay',
    ],

    'options' => [

        // ── FILM STOCK ────────────────────────────────────────────────────────
        'chap_film_stock' => [
            'section' => 'FILM STOCK',
            'type'    => 'select',
            'label'   => 'Film Stock',
            'default' => 'nitrate',
            'options' => [
                'ortho'   => 'Orthochromatic (High-Contrast B&W)',
                'nitrate' => 'Nitrate Print (Warm / Aged)',
                'panchro' => 'Panchromatic (Standard B&W)',
                'color'   => 'Color (No Filter)',
            ],
            // No selector/property — handled by PHP conditional CSS in skin-header.php
        ],
        'chap_grain_intensity' => [
            'section' => 'FILM STOCK',
            'type'    => 'range',
            'label'   => 'Grain Intensity',
            'default' => '4',
            'min'     => '0',
            'max'     => '12',
            'selector' => ':root',
            'property' => '--chap-grain-opacity',
            'unit'    => '', // stored as integer, divided by 100 in CSS via calc()
        ],
        'chap_vignette' => [
            'section' => 'FILM STOCK',
            'type'    => 'select',
            'label'   => 'Vignette',
            'default' => '1',
            'options' => ['1' => 'On', '0' => 'Off'],
            // Handled by PHP conditional in skin-header.php
        ],
        'chap_flicker' => [
            'section' => 'FILM STOCK',
            'type'    => 'select',
            'label'   => 'Load Flicker',
            'default' => '1',
            'options' => ['1' => 'On', '0' => 'Off'],
            // Handled by PHP conditional in skin-header.php
        ],
        // ── FILM DAMAGE ───────────────────────────────────────────────────────
        'chap_film_damage' => [
            'section' => 'FILM DAMAGE',
            'type'    => 'select',
            'label'   => 'Film Damage Overlay',
            'default' => '1',
            'options' => ['1' => 'On', '0' => 'Off'],
            // Handled by PHP in skin-header.php — loads ss-engine-film-damage.js
        ],
        'chap_damage_intensity' => [
            'section'  => 'FILM DAMAGE',
            'type'     => 'range',
            'label'    => 'Damage Intensity',
            'default'  => '5',
            'min'      => '1',
            'max'      => '10',
            'selector' => '',
            'property' => '',
            // Passed as JS init option — no CSS selector needed
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
            // Handled by PHP in skin-header.php
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
                'card'    => 'Intertitle Card (Full Silent Film Style)',
                'minimal' => 'Minimal (Title Only)',
                'hidden'  => 'Hidden',
            ],
            // Handled by PHP in skin-header.php
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

        // ── SLIDER ────────────────────────────────────────────────────────────
        'chap_slider_per_view' => [
            'section' => 'SLIDER',
            'type'    => 'select',
            'label'   => 'Images Per View',
            'default' => '2',
            'options' => ['1' => '1 Image', '2' => '2 Images', '3' => '3 Images'],
            'selector' => ':root',
            'property' => '--slider-per-view',
        ],
        'chap_slider_speed' => [
            'section'  => 'SLIDER',
            'type'     => 'range',
            'label'    => 'Transition Speed (ms)',
            'default'  => '1000',
            'min'      => '400',
            'max'      => '2000',
            'selector' => ':root',
            'property' => '--slider-speed',
            'unit'     => 'ms',
        ],
        'chap_slider_auto' => [
            'section' => 'SLIDER',
            'type'    => 'select',
            'label'   => 'Auto-Advance',
            'default' => '0',
            'options' => ['0' => 'Off', '1' => 'On'],
            'selector' => ':root',
            'property' => '--slider-auto',
        ],
        'chap_slider_loop' => [
            'section' => 'SLIDER',
            'type'    => 'select',
            'label'   => 'Loop',
            'default' => '1',
            'options' => ['0' => 'Off', '1' => 'On'],
            'selector' => ':root',
            'property' => '--slider-loop',
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
            'default'  => 'BlackCasper',
            'options'  => $fonts,
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
            'options'  => $fonts,
            'selector' => '.chap-intertitle-title, .chap-card-heading',
            'property' => 'font-family',
        ],
        'chap_body_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body Font',
            'default'  => 'FlottFlott',
            'options'  => $fonts,
            'selector' => 'body, .description, .meta, .chap-intertitle-body',
            'property' => 'font-family',
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
