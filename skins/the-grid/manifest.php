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

        // ---- IMAGE FRAME ---------------------------------------------------
        // tg_customize_level controls which style resolution path is active.
        //   per_grid     — one style for all images, set here in Skin Admin.
        //   per_carousel — each post defines its own style (stored on snap_posts).
        //   per_image    — each photo has its own style (stored on snap_post_images).
        'tg_customize_level' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'select',
            'label'   => 'Customisation Level',
            'default' => 'per_grid',
            'options' => [
                'per_grid'     => 'Site-wide (one style for all images)',
                'per_carousel' => 'Per Post (each post defines its own style)',
                'per_image'    => 'Per Image (each photo has its own style)',
            ],
        ],
        'tg_frame_size_pct' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'select',
            'label'   => 'Image Size Within Tile',
            'default' => '100',
            'options' => [
                '100' => '100% — edge to edge',
                '95'  => '95%',
                '90'  => '90%',
                '85'  => '85%',
                '80'  => '80%',
                '75'  => '75%',
            ],
            // No selector/property: PHP applies this inline in landing.php / layout.php.
        ],
        'tg_frame_border_px' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'select',
            'label'   => 'Border Thickness',
            'default' => '0',
            'options' => [
                '0'  => 'None',
                '1'  => '1px',
                '2'  => '2px',
                '3'  => '3px',
                '5'  => '5px',
                '8'  => '8px',
                '10' => '10px',
                '15' => '15px',
                '20' => '20px',
            ],
        ],
        'tg_frame_border_color' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'color',
            'label'   => 'Border Colour',
            'default' => '#000000',
        ],
        'tg_frame_bg_color' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'color',
            'label'   => 'Frame Background Colour',
            'default' => '#ffffff',
        ],
        'tg_frame_shadow' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'select',
            'label'   => 'Drop Shadow on Image',
            'default' => '0',
            'options' => [
                '0' => 'None',
                '1' => 'Soft',
                '2' => 'Medium',
                '3' => 'Heavy',
            ],
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
