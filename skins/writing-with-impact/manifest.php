<?php
/**
 * SNAPSMACK - Manifest for the WRITING WITH IMPACT skin
 * v1.0.0
 *
 * A SMACKTALK longform skin in the dot-matrix idiom of IMPACT PRINTER and
 * SUDDEN IMPACT — continuous tractor-feed paper, perforated edges, Tiny5 /
 * DotMatrix headline type, ribbon-ink body — but built for reading: a single
 * clean column for essays and long-form writing. Runs on the SnapSmack longform
 * pipeline, shortcodes, menu system, and shared footer.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$inventory = include(dirname(__DIR__, 2) . '/core/manifest-inventory.php');

// Restrict the font picker to the dot-matrix family, like IMPACT PRINTER.
$dm_keys = array_filter(array_keys($inventory['local_fonts'] ?? []), function ($k) {
    return strpos($k, 'DotMatrix') === 0 || strpos($k, 'Tiny5') === 0;
});
$fonts = [];
foreach ($dm_keys as $k) {
    $fonts[$k] = $inventory['local_fonts'][$k]['label'];
}

return [
    'name'            => 'WRITING WITH IMPACT',
    'version'         => '1.0.0',
    'author'          => 'Sean McCormick',
    'author_email'    => 'sean@baddaywithacamera.ca',
    'description'     => 'A SMACKTALK longform skin in the dot-matrix idiom of IMPACT PRINTER and SUDDEN IMPACT: continuous tractor-feed paper with perforated edges, Tiny5 / DotMatrix headlines, and ribbon-ink body type — but built for reading, a single clean column for essays and long-form writing. Two paper stocks: plain white and green-bar ledger.',
    'status'          => 'stable',

    // WRITING WITH IMPACT is SMACKTALK only. preload.php handles longform routing.
    'modes'           => ['smacktalk'],
    'skin_preload'    => 'preload.php',

    'variants' => [
        'default' => 'WRITING WITH IMPACT (Plain Paper)',
    ],
    'default_variant' => 'default',

    'features' => [
        'supports_wall'  => false,
        'has_landing'    => false,
        'post_modes'     => ['longform'],
        'instagram_mode' => false,
        'carousel'       => false,
        'community'      => ['comments'],
    ],

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
            'type'    => 'asset',
            'label'   => 'Header Image (optional)',
            'default' => '',
            'help'    => 'Optional masthead image printed above the title. Leave blank for a pure dot-matrix nameplate.',
        ],
        'header_logo' => [
            'section' => 'HEADER',
            'type'    => 'asset',
            'label'   => 'Custom Logo',
            'default' => '',
            'help'    => 'Upload a logo to replace the dot-matrix site title. Transparent PNG recommended.',
        ],

        /* ===== PAPER ===== */
        'paper_style' => [
            'section' => 'PAPER',
            'type'    => 'select',
            'label'   => 'Paper Stock',
            'default' => 'plain',
            'options' => ['plain' => 'Plain White', 'greenbar' => 'Green-Bar Ledger'],
            'help'    => 'Continuous-feed stationery under the writing.',
        ],

        /* ===== COLOURS ===== */
        'ink_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Ribbon Ink Colour',
            'default'  => '#2b2b2b',
            'selector' => ':root',
            'property' => '--wwi-ink',
            'help'     => 'The colour of the printed text, links, and rules.',
        ],

        /* ===== LAYOUT ===== */
        'post_content_width' => [
            'section'  => 'LAYOUT',
            'type'     => 'range',
            'label'    => 'Reading Width (px)',
            'default'  => '680',
            'min'      => '520',
            'max'      => '900',
            'selector' => '.post-inner',
            'property' => 'width',
            'unit'     => 'px',
        ],
        'posts_per_page' => [
            'section' => 'LAYOUT',
            'type'    => 'range',
            'label'   => 'Posts Per Page',
            'default' => '8',
            'min'     => '4',
            'max'     => '40',
        ],

    ],
];
// ===== SNAPSMACK EOF =====
