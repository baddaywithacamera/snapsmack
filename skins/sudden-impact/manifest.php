<?php
/**
 * SNAPSMACK - The Grid Skin Manifest
 * Alpha v0.7.196
 *
 * Classic Instagram-style 3-column photo grid skin.
 * Activates the GramOfSmack posting interface (post_page => gram)
 * and the carousel edit interface (edit_page => carousel).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$_mf_inv = dirname(__DIR__, 2) . '/core/manifest-inventory.php';
$inventory = file_exists($_mf_inv) ? (include $_mf_inv) : [];
$fonts = is_array($inventory) ? ($inventory['fonts'] ?? []) : [];
foreach (is_array($inventory) ? ($inventory['local_fonts'] ?? []) : [] as $_k => $_f) $fonts[$_k] = $_f['label'];
unset($_mf_inv);

return [
    'name'        => 'SUDDEN IMPACT',
    'version'     => '1.0.2',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'Instagram in dot matrix. The Grid\'s 3-column carousel feed rendered as a 1985 Okidata 192 thermal printout — DotMatrix type throughout, dithered halftone photos on plain continuous-feed paper, dashed dot-matrix double-rule nav and footer. An abomination, on purpose. GramOfSmack mode.',
    'status'      => 'stable',
    'modes'       => ['carousel'],

    // Activate GramOfSmack posting and carousel editing interfaces
    'post_page'   => 'gram',
    'edit_page'   => 'carousel',

    'features' => [
        'supports_wall'      => false,
        'masonry_supported'  => false,
        'archive_layouts'    => ['square'],
        'supports_slider'  => false,
        'has_landing'      => true,
        'post_modes'       => ['image'],
        'instagram_mode'   => true,
        'carousel'         => true,
        'community'        => ['likes', 'comments'],
    ],

    'require_scripts' => [
        'smack-keyboard',
        'smack-community',
        'smack-slider',
        'smack-carousel-view',
        'smack-grid-nav',
        'smack-grid-modal',
        'smack-grid-lightbox',
        'smack-progressive-reveal',  // shared grow-as-you-scroll (grid tiles + justified rows)
        'smack-tag-infinite',     // shared prefix-derived hashtag infinite scroll
        'smack-image-fade-load',  // reveals lightbox/asset images on static pages
                                  // (public-base.css sets img[data-lightbox-src]{opacity:0};
                                  //  this engine fades them to opacity:1 on load)
        'smack-lightbox',         // click-to-zoom for content/asset images
                                  // (img[data-lightbox-src] + cursor:zoom-in)
        'smack-gram-search',      // bottom-left magnifier → expanding search dock
                                  // (gated on search_enabled; CSS + JS)
    ],

    'community_comments'  => true,
    'community_likes'     => true,
    'community_reactions' => false,

    'options' => [

        // ---- GRID --------------------------------------------------
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
            'default'  => 'dark',
            'options'  => [
                'dark'  => 'Darken only',
                'title' => 'Show post title',
                'count' => 'Show image count',
                'none'  => 'No overlay',
            ],
        ],

        // ---- PROFILE HEADER ----------------------------------------
        'tg_profile_header' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Show Profile Header',
            'default'  => '1',
            'options'  => ['1' => 'Enabled', '0' => 'Disabled'],
        ],
        'skin_avatar' => [
            'section' => 'PROFILE HEADER',
            'type'    => 'image',
            'label'   => 'Profile Avatar',
            'default' => '',
            'accept'  => 'image/jpeg,image/png,image/webp,image/gif',
            'hint'    => 'Square image recommended. Displayed as a circle, ~77px.',
        ],
        'tg_show_tagline' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Show Tagline (Site Description)',
            'default'  => '1',
            'options'  => ['1' => 'Show', '0' => 'Hide'],
        ],

        // ---- IMAGE FRAME -------------------------------------------
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

        // ---- TREATMENT ---------------------------------------------
        'tg_treatment_mode' => [
            'section' => 'TREATMENT',
            'type'    => 'select',
            'label'   => 'Background Treatment',
            'default' => 'none',
            'options' => [
                'none'  => 'None (flat page background)',
                'image' => 'Background image',
                'color' => 'Solid colour',
            ],
            'hint'    => 'Adds a full-page background behind a centred content card on every page.',
        ],
        'tg_treatment_image' => [
            'section'    => 'TREATMENT',
            'type'       => 'image',
            'label'      => 'Treatment Image',
            'default'    => '',
            'accept'     => 'image/jpeg,image/png,image/webp',
            'min_width'  => 1920,
            'min_height' => 1080,
            'hint'       => 'Used when Treatment = Background image. Minimum 1920×1080px.',
        ],
        'tg_treatment_position' => [
            'section' => 'TREATMENT',
            'type'    => 'select',
            'label'   => 'Image Anchor (when it overshoots)',
            'default' => 'center',
            'options' => [
                'center' => 'Centre',
                'top'    => 'Snap to top',
                'bottom' => 'Snap to bottom',
            ],
            'hint'    => 'Which edge the background image hugs when it is taller than the screen.',
        ],
        'tg_treatment_color' => [
            'section' => 'TREATMENT',
            'type'    => 'color',
            'label'   => 'Treatment Colour',
            'default' => '#ffffff',
            'hint'    => 'Used when Treatment = Solid colour.',
        ],
        'tg_treatment_overlay' => [
            'section'  => 'TREATMENT',
            'type'     => 'range_numeric',
            'label'    => 'Overlay  (left darkens · right lightens)',
            'default'  => '0',
            'min'      => '-100',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'Centre = none. Drag left to darken the background, right to lighten it.',
            // No selector/property — handled in skin-profile.php (sign decides dark vs light).
        ],

    ],
];
// ===== SNAPSMACK EOF =====
