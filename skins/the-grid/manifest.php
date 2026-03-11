<?php
/**
 * SNAPSMACK - The Grid Skin Manifest
 * Alpha v0.7.1
 *
 * Classic Instagram-style 3-column photo grid skin.
 * Activates the carousel posting interface (post_page => carousel)
 * and the carousel edit interface (edit_page => carousel).
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'        => 'The Grid',
    'version'     => '1.0',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'Classic Instagram-style 3-column square-thumbnail photo grid. Carousel and panorama post support. Clean, minimal UI keeps the focus on the photographs.',
    'status'      => 'development',

    // Activate carousel posting and editing interfaces
    'post_page'   => 'carousel',
    'edit_page'   => 'carousel',

    'features' => [
        'supports_wall'    => false,
        'archive_layouts'  => ['square'],
        'supports_slider'  => false,
    ],

    'require_scripts' => [
        'smack-keyboard',
        'smack-community',
        'smack-slider',
    ],

    'community_comments'  => true,
    'community_likes'     => true,
    'community_reactions' => false,

    'options' => [

        // ---- GRID APPEARANCE -----------------------------------------------
        'tg_gap' => [
            'section'  => 'GRID',
            'type'     => 'select',
            'label'    => 'Grid Gap',
            'default'  => '2',
            'options'  => [
                '0' => '0px (borderless)',
                '1' => '1px',
                '2' => '2px (classic Instagram)',
                '3' => '3px',
                '5' => '5px',
            ],
            'selector' => ':root',
            'property' => '--grid-gap',
        ],
        'tg_bg_color' => [
            'section'  => 'GRID',
            'type'     => 'color',
            'label'    => 'Grid Background / Gap Colour',
            'default'  => '#ffffff',
            'selector' => ':root',
            'property' => '--grid-bg',
        ],
        'tg_border_radius' => [
            'section'  => 'GRID',
            'type'     => 'select',
            'label'    => 'Tile Border Radius',
            'default'  => '0',
            'options'  => [
                '0'  => '0px (sharp)',
                '2'  => '2px',
                '4'  => '4px',
                '8'  => '8px (rounded)',
            ],
            'selector' => ':root',
            'property' => '--tile-radius',
        ],
        'tg_carousel_indicator' => [
            'section'  => 'GRID',
            'type'     => 'select',
            'label'    => 'Carousel Indicator Style',
            'default'  => 'icon',
            'options'  => [
                'icon'  => 'Layered squares icon',
                'count' => 'Image count badge',
                'none'  => 'No indicator',
            ],
            // No selector/property: handled by PHP in landing.php via $settings
        ],
        'tg_hover_overlay' => [
            'section'  => 'GRID',
            'type'     => 'select',
            'label'    => 'Hover Overlay',
            'default'  => 'title',
            'options'  => [
                'title' => 'Show post title',
                'count' => 'Show image count',
                'none'  => 'No overlay',
            ],
        ],

        // ---- PROFILE HEADER ------------------------------------------------
        'tg_profile_header' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Show Profile Header',
            'default'  => '1',
            'options'  => ['1' => 'Enabled', '0' => 'Disabled'],
        ],

        // ---- COLOURS -------------------------------------------------------
        'tg_bg_primary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Page Background',
            'default'  => '#ffffff',
            'selector' => ':root',
            'property' => '--bg-primary',
        ],
        'tg_text_primary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Primary Text',
            'default'  => '#262626',
            'selector' => ':root',
            'property' => '--text-primary',
        ],
        'tg_text_secondary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Secondary Text',
            'default'  => '#8e8e8e',
            'selector' => ':root',
            'property' => '--text-secondary',
        ],
        'tg_accent' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Accent / Link Colour',
            'default'  => '#0095f6',
            'selector' => ':root',
            'property' => '--accent-color',
        ],
        'tg_border_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Border / Divider Colour',
            'default'  => '#dbdbdb',
            'selector' => ':root',
            'property' => '--border-color',
        ],

        // ---- TYPOGRAPHY ----------------------------------------------------
        'tg_font_body' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body / UI Font',
            'default'  => 'system',
            'options'  => array_merge(
                ['system' => 'System Default (San Francisco / Segoe UI)'],
                $fonts
            ),
            'selector' => ':root',
            'property' => '--font-body',
        ],

        // ---- LAYOUT --------------------------------------------------------
        'tg_max_width' => [
            'section'  => 'LAYOUT',
            'type'     => 'select',
            'label'    => 'Content Max Width',
            'default'  => '935',
            'options'  => [
                '735'  => '735px (narrow)',
                '935'  => '935px (classic Instagram)',
                '1080' => '1080px (wide)',
            ],
            'selector' => ':root',
            'property' => '--content-max-width',
        ],

    ],
];
