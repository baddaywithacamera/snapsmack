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
    'name'        => 'The Grid',
    'version'     => '1.3.19',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'Classic Instagram-style 3-column square-thumbnail photo grid. Carousel and panorama post support. Clean, minimal UI keeps the focus on the photographs.',
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
        'smack-image-fade-load',  // reveals lightbox/asset images on static pages
                                  // (public-base.css sets img[data-lightbox-src]{opacity:0};
                                  //  this engine fades them to opacity:1 on load)
        'smack-lightbox',         // click-to-zoom for content/asset images
                                  // (img[data-lightbox-src] + cursor:zoom-in)
    ],

    'community_comments'  => true,
    'community_likes'     => true,
    'community_reactions' => false,

    'options' => [

        // ---- GRID APPEARANCE -----------------------------------------------
        'tg_gap' => [
            'section'  => 'GRID',
            'type'     => 'range_numeric',
            'label'    => 'Image Gap',
            'default'  => '2',
            'min'      => '0',
            'max'      => '20',
            'step'     => '1',
            'unit'     => 'px',
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
            'default'  => 'dark',
            'options'  => [
                'dark'  => 'Darken only',
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
        'skin_avatar' => [
            'section' => 'PROFILE HEADER',
            'type'    => 'image',
            'label'   => 'Profile Avatar',
            'default' => '',
            'accept'  => 'image/jpeg,image/png,image/webp,image/gif',
            'hint'    => 'Square image recommended. Displayed as a circle, ~77px.',
        ],
        'tg_blog_title_font' => [
            'section'       => 'PROFILE HEADER',
            'type'          => 'select',
            'label'         => 'Blog Title Font',
            'default'       => 'inherit',
            'options'       => array_merge(['inherit' => 'Same as Body Font'], $fonts),
            'selector'      => ':root',
            'property'      => '--blog-title-font',
            'is_font'       => true,   // enables preview block + per-option font-family style
            'no_size_slider'=> true,   // suppress built-in size slider; tg_blog_title_size handles it
        ],
        'tg_blog_title_size' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'range_numeric',
            'label'    => 'Blog Title Size',
            'default'  => '20',
            'min'      => '12',
            'max'      => '48',
            'step'     => '1',
            'unit'     => 'px',
            'selector' => ':root',
            'property' => '--blog-title-size',
        ],
        'tg_blog_title_weight' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Blog Title Weight',
            'default'  => '300',
            'options'  => [
                '300' => 'Light',
                '400' => 'Regular',
                '500' => 'Medium',
                '600' => 'Semibold',
                '700' => 'Bold',
            ],
            'selector' => ':root',
            'property' => '--blog-title-weight',
        ],
        'tg_show_tagline' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Show Tagline (Site Description)',
            'default'  => '1',
            'options'  => ['1' => 'Show', '0' => 'Hide'],
        ],
        'tg_tagline_font' => [
            'section'       => 'PROFILE HEADER',
            'type'          => 'select',
            'label'         => 'Tagline Font',
            'default'       => 'inherit',
            'options'       => array_merge(['inherit' => 'Same as Body Font'], $fonts),
            'selector'      => ':root',
            'property'      => '--tagline-font',
            'is_font'       => true,
            'no_size_slider'=> true,
        ],
        'tg_tagline_size' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'range_numeric',
            'label'    => 'Tagline Size',
            'default'  => '16',
            'min'      => '10',
            'max'      => '36',
            'step'     => '1',
            'unit'     => 'px',
            'selector' => ':root',
            'property' => '--tagline-size',
        ],
        'tg_tagline_weight' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Tagline Weight',
            'default'  => '300',
            'options'  => [
                '300' => 'Light',
                '400' => 'Regular',
                '500' => 'Medium',
                '600' => 'Semibold',
                '700' => 'Bold',
            ],
            'selector' => ':root',
            'property' => '--tagline-weight',
        ],
        'tg_tagline_color' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'color',
            'label'    => 'Tagline Colour',
            'default'  => '#8e8e8e',
            'selector' => ':root',
            'property' => '--tagline-color',
        ],
        'tg_nav_case' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Nav Link Case',
            'default'  => 'none',
            'options'  => [
                'none'       => 'As typed',
                'uppercase'  => 'ALL CAPS',
                'capitalize' => 'First Letter',
                'lowercase'  => 'all lowercase',
            ],
            'selector' => ':root',
            'property' => '--nav-text-transform',
        ],

        // ---- COLOURS -------------------------------------------------------
        'tg_post_bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Image Page Background',
            'default'  => '#000000',
            'selector' => ':root',
            'property' => '--post-bg',
        ],
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
            'default'  => '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
            'options'  => array_merge(
                ['"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif' => 'System Default (Segoe UI / Roboto)'],
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
            'type'     => 'range_numeric',
            'label'    => 'Grid Max Width',
            'default'  => '935',
            'min'      => '600',
            'max'      => '1600',
            'step'     => '5',
            'unit'     => 'px',
            'selector' => ':root',
            'property' => '--grid-max-width',
        ],
        'tg_gutter' => [
            'section'  => 'TREATMENT',
            'type'     => 'range_numeric',
            'label'    => 'Side Gutter (white margin over background)',
            'default'  => '0',
            'min'      => '0',
            'max'      => '400',
            'step'     => '4',
            'unit'     => 'px',
            'selector' => ':root',
            'property' => '--grid-gutter',
        ],

        // ---- TREATMENT (full-page background behind a centred content card) -
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
