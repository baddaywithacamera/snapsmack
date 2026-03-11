<?php
/**
 * SNAPSMACK - Configuration manifest for the pocket-operator skin
 * Alpha v0.7
 *
 * Mobile-first skin. Doomscroll feed, hamburger nav, swipe drawers.
 * Medium grey palette, 1px borders, no floating gallery, no justified,
 * no cropped thumbs. Aspect-preserved thumbnails only.
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'          => 'Pocket Rocket',
    'version'       => '1.0',
    'author'        => 'Sean McCormick',
    'support'       => 'sean@baddaywithacamera.ca',
    'description'   => 'Mobile-first skin for phone screens. Doomscroll feed, hamburger nav, swipe drawers for info and signals. No floating gallery, no justified grid, no cropped thumbs. Gen Z approved, boomer built.',
    'status'        => 'stable',

    'features' => [
        'supports_wall'   => false,
        'archive_layouts' => ['square'],
    ],

    // No variants — one grey, one truth
    'variants' => [
        'default' => 'Pocket Grey',
    ],
    'default_variant' => 'default',

    // Minimal script loading — no lightbox, no justified, no wall physics
    'require_scripts' => [
        'smack-footer',
        'smack-community'
    ],

    // --- CUSTOMIZATION OPTIONS ---
    'options' => [

        // === CANVAS ===
        'main_canvas_width' => [
            'section'  => 'CANVAS',
            'type'     => 'range',
            'label'    => 'Max Content Width',
            'default'  => '480',
            'min'      => '320',
            'max'      => '640',
            'selector' => '.po-wrap',
            'property' => 'max-width',
        ],

        // === TYPOGRAPHY ===
        'body_font' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'select',
            'label'    => 'Body Font',
            'default'  => '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'selector' => 'body',
            'property' => 'font-family',
            'options'  => array_merge(
                ['-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' => 'System Default'],
                $fonts
            ),
        ],

        'body_size' => [
            'section'  => 'TYPOGRAPHY',
            'type'     => 'range',
            'label'    => 'Body Font Size (px)',
            'default'  => '14',
            'min'      => '12',
            'max'      => '18',
            'selector' => 'body',
            'property' => 'font-size',
        ],
    ],


    'community_comments'  => '1',
    'community_likes'     => '1',
    'community_reactions' => '0',
];
