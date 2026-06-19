<?php

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
    'name' => '52 Card Pickup',
    'version' => '1.4.0',
    'author' => 'Sean McCormick',
    'support' => 'sean@baddaywithacamera.ca',
    'description' => 'The landing IS the table. An infinite, pannable tabletop of scattered photo prints (the Organized Mayhem engine) — drag to roam, scroll to zoom, hover to lift a print, click to pick it up and read it, ESC to drop it back. Nav and footer stay hidden under the pile until you reach for the screen edges. Named for the card trick where someone throws a deck on the floor.',
    'status' => 'available',

    'features' => [
        'supports_wall' => false,
        'archive_layouts' => ['square', 'masonry'],
        'supports_slider' => false,
        'has_landing'     => true,
        'post_modes'      => ['image'],
        'instagram_mode'  => false,
        'carousel'        => false,
        'community'       => ['likes', 'comments'],
    ],

    'require_scripts' => [
        'smack-footer',
        'smack-image-fade-load',
        'smack-lightbox',
        'smack-keyboard',
        'smack-organized-mayhem',
        'smack-52-pickup',
        'smack-community',
        'smack-overlay',
    ],

    'options' => [

        // BACKGROUND section
        'htbs_bg_color' => [
            'section' => 'BACKGROUND',
            'type' => 'color',
            'label' => 'Canvas Background',
            'default' => '#F5F0E8',
            'selector' => 'body',
            'property' => 'background-color',
        ],

        // HEADER section
        'htbs_header_bg_color' => [
            'section' => 'HEADER',
            'type' => 'color',
            'label' => 'Header Background',
            'default' => '#F5F0E8',
            'selector' => '.pickup-header',
            'property' => 'background-color',
        ],
        'htbs_nav_color' => [
            'section' => 'HEADER',
            'type' => 'color',
            'label' => 'Nav Link Colour',
            'default' => '#555555',
            'selector' => '.pickup-header .nav-menu a',
            'property' => 'color',
        ],
        'htbs_nav_hover_color' => [
            'section' => 'HEADER',
            'type' => 'color',
            'label' => 'Nav Link Hover',
            'default' => '#222222',
            'selector' => '.pickup-header .nav-menu a:hover',
            'property' => 'color',
        ],

        // FOOTER section
        'htbs_footer_bg_color' => [
            'section' => 'FOOTER',
            'type' => 'color',
            'label' => 'Footer Background',
            'default' => '#F5F0E8',
            'selector' => '.pickup-footer, footer',
            'property' => 'background-color',
        ],
        'htbs_footer_text_color' => [
            'section' => 'FOOTER',
            'type' => 'color',
            'label' => 'Footer Text Colour',
            'default' => '#999999',
            'selector' => '.pickup-footer, footer',
            'property' => 'color',
        ],

        // COLOURS section
        'htbs_text_primary' => [
            'section' => 'COLOURS',
            'type' => 'color',
            'label' => 'Primary Text',
            'default' => '#333333',
            'selector' => ':root',
            'property' => '--text-primary',
        ],
        'htbs_text_secondary' => [
            'section' => 'COLOURS',
            'type' => 'color',
            'label' => 'Secondary Text',
            'default' => '#777777',
            'selector' => ':root',
            'property' => '--text-secondary',
        ],
        'htbs_accent_color' => [
            'section' => 'COLOURS',
            'type' => 'color',
            'label' => 'Accent Colour',
            'default' => '#8B7355',
            'selector' => ':root',
            'property' => '--accent-color',
        ],

        // TYPOGRAPHY section
        'htbs_title_font' => [
            'section' => 'TYPOGRAPHY',
            'type' => 'select',
            'label' => 'Site Title Font',
            'default' => 'Georgia',
            'options' => $fonts,
            'selector' => '.pickup-header .site-title-text',
            'property' => 'font-family',
        ],
        'htbs_title_size' => [
            'section' => 'TYPOGRAPHY',
            'type' => 'range',
            'label' => 'Site Title Size (px)',
            'default' => '20',
            'min' => '12',
            'max' => '48',
            'selector' => '.pickup-header .site-title-text',
            'property' => 'font-size',
            'unit' => 'px',
        ],
        'htbs_title_color' => [
            'section' => 'TYPOGRAPHY',
            'type' => 'color',
            'label' => 'Site Title Colour',
            'default' => '#333333',
            'selector' => '.pickup-header .site-title-text',
            'property' => 'color',
        ],
        'header_text_transform' => [
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
            'selector' => '.pickup-header .site-title-text',
            'property' => 'text-transform',
        ],
        'htbs_body_font' => [
            'section' => 'TYPOGRAPHY',
            'type' => 'select',
            'label' => 'Body Font',
            'default' => 'Georgia',
            'options' => $fonts,
            'selector' => 'body, .description, .meta',
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
    'community_reactions'  => '0',
];
// ===== SNAPSMACK EOF =====
