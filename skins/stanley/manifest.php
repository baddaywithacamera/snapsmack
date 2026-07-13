<?php
/**
 * SNAPSMACK - Manifest for the STANLEY skin
 * v1.0.0
 *
 * A SMACKTALK longform skin: a faithful re-imagining of the classic WordPress
 * "Kubrick" default theme (the blue-sky-banner era) rebuilt on the SnapSmack
 * longform pipeline — SnapSmack shortcodes, the menu system, and the shared
 * slot-bar footer. Named STANLEY for Stanley Kubrick, the theme's namesake.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');
$fonts = $inventory['fonts'] ?? [];
foreach ($inventory['local_fonts'] ?? [] as $_k => $_f) $fonts[$_k] = $_f['label'];

return [
    'name'            => 'STANLEY',
    'version'         => '1.0.0',
    'author'          => 'Sean McCormick',
    'author_email'    => 'sean@baddaywithacamera.ca',
    'description'     => 'A SMACKTALK longform skin: a faithful re-imagining of the classic WordPress Kubrick default theme — blue sky banner, rounded page frame, right sidebar, and a clean serif reading column. Rebuilt on SnapSmack shortcodes, the menu system, and the shared footer. Named for Stanley Kubrick.',
    'status'          => 'stable',

    // STANLEY is SMACKTALK only. preload.php handles longform routing before
    // index.php reaches its image logic.
    'modes'           => ['smacktalk'],
    'skin_preload'    => 'preload.php',

    'variants' => [
        'default' => 'STANLEY (Classic Blue)',
    ],
    'default_variant' => 'default',

    // SMACKTALK post-cover frame shape (CSS aspect-ratio). Pan/zoom crops within it.
    'cover_aspect'   => '4/3',

    'features' => [
        'supports_wall'  => false,
        'has_landing'    => false,
        'post_modes'     => ['longform'],
        'instagram_mode' => false,
        'carousel'       => false,
        'community'      => ['comments'],
    ],

    // Reuse the proven SMACKTALK engines (nav toggle, footer, community).
    'require_scripts' => [
        'smack-alfred-nav',
        'smack-footer',
        'smack-community',
    ],

    'community_comments'  => '1',
    'community_likes'     => '0',
    'community_reactions' => '0',

    'options' => [

        /* ===== HEADER ===== */
        'header_image' => [
            'section' => 'HEADER',
            'type'    => 'image',
            'label'   => 'Header Banner Image',
            'default' => '',
            'accept'  => 'image/jpeg,image/png,image/webp',
            'help'    => 'Optional photo for the blue banner (the classic Kubrick sky-and-hills spot). Recommended 960x220px. Leave blank for the default blue gradient.',
            'hint'    => 'Recommended 960x220px. Leave blank for the default blue gradient.',
        ],
        'header_logo' => [
            'section' => 'HEADER',
            'type'    => 'image',
            'label'   => 'Custom Logo',
            'default' => '',
            'accept'  => 'image/jpeg,image/png,image/webp,image/svg+xml',
            'help'    => 'Upload a logo to replace the site title text in the banner. Transparent PNG recommended.',
            'hint'    => 'Transparent PNG recommended.',
        ],
        'show_tagline' => [
            'section' => 'HEADER',
            'type'    => 'select',
            'label'   => 'Show Tagline',
            'default' => '1',
            'options' => ['1' => 'Show', '0' => 'Hide'],
            'help'    => 'Hide the tagline under the banner (useful when your logo already includes it). Still used in RSS and social/SEO meta.',
        ],

        /* ===== COLOURS ===== */
        'accent_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Accent / Banner Colour',
            'default'  => '#2e6da4',
            'selector' => ':root',
            'property' => '--stanley-accent',
            'help'     => 'Banner gradient base, links, and header bars.',
        ],

        /* ===== LAYOUT ===== */
        'show_sidebar' => [
            'section' => 'LAYOUT',
            'type'    => 'select',
            'label'   => 'Show Sidebar',
            'default' => '1',
            'options' => ['1' => 'Yes (Kubrick two-column)', '0' => 'No (single column)'],
            'help'    => 'The right-hand sidebar with recent posts and pages, as in the original.',
        ],
        'post_content_width' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Content Width (px)',
            'default'  => '640',
            'min'      => '480',
            'max'      => '900',
            'selector' => '.post-inner',
            'property' => 'width',
            'unit'     => 'px',
        ],
        'posts_per_page' => [
            'section' => 'LAYOUT',
            'type'    => 'range',
            'label'   => 'Posts Per Page',
            'default' => '10',
            'min'     => '4',
            'max'     => '40',
        ],

    ],
];
// ===== SNAPSMACK EOF =====
