<?php
/**
 * SNAPSMACK - AURORA Skin Manifest
 * v1.0.17
 *
 * Desktop GRAMOFSMACK skin. A classic 3-across square-tile grid (The Grid's
 * proven column architecture) overlaid with a two-layer animation system:
 *   Layer 1 — a slow CSS aurora that breathes the active palette behind the
 *             photography (skins/aurora/style.css + skin-profile.php).
 *   Layer 2 — a colour wave that travels across the tile borders, driven by
 *             skins/aurora/assets/js/aurora-wave.js.
 * The photography is always the content; the animation is always the atmosphere.
 *
 * Desktop-only: declared incompatible with mobile/tablet contexts. PHOTOGRAM and
 * TELEGRAM handle mobile. Palette/sky options are data-driven via aurora-config.php.
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
    'name'        => 'AURORA',
    'version'     => '1.0.34',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'Northern-lights desktop skin. A classic 3-across square grid under a slow aurora that breathes colour behind the photography, with a configurable colour wave rippling across the tile borders. Dark, dramatic, and built so the photos are why you came.',
    'status'      => 'beta',
    'modes'       => ['carousel'],

    // Desktop-only — never offered on mobile-primary installs. Propagated to the
    // catalog JSON by the SC Skin Packager.
    'incompatible' => ['mobile', 'tablet'],

    // Activate GramOfSmack posting and carousel editing interfaces
    'post_page'   => 'gram',
    'edit_page'   => 'carousel',

    'features' => [
        'supports_wall'      => false,
        'masonry_supported'  => false,
        'archive_layouts'    => ['square'],
        'supports_slider'    => false,
        'has_landing'        => true,
        'post_modes'         => ['image'],
        'instagram_mode'     => true,
        'carousel'           => true,
        'mobile_only'        => false,
        'community'          => ['likes', 'comments'],
    ],

    'require_scripts' => [
        'smack-keyboard',
        'smack-community',
        'smack-slider',
        'smack-carousel-view',
        'smack-image-fade-load',  // reveals lightbox/asset images on static pages
        'smack-lightbox',         // click-to-zoom for content/asset images
        // Shared Grid-family engines + AURORA's own atmosphere engines
        // (all registered in core/manifest-inventory.php, all in /assets/js):
        'smack-grid-modal',
        'smack-grid-lightbox',
        'smack-grid-nav',
        'smack-aurora-bg',
        'smack-aurora-wave',
        'smack-progressive-reveal',
        'smack-tag-infinite',     // shared prefix-derived hashtag infinite scroll
        'smack-gram-search',      // bottom-left magnifier → expanding search dock
    ],

    'community_comments'  => true,
    'community_likes'     => true,
    'community_reactions' => false,

    'options' => [

        // ---- AURORA ------------------------------------------------
        'au_palette' => [
            'section' => 'AURORA',
            'type'    => 'select',
            'label'   => 'Colour Palette',
            'default' => 'aurora',
            'options' => [
                'aurora'       => 'Aurora — green-dominant, teal/blue/yellow/red flares',
                'borealis-ice' => 'Borealis Ice — cool greens & blues',
                'solar'        => 'Solar Storm — green into red aurora',
            ],
            'hint'    => 'Drives both the background aurora and the border wave. Palettes are defined in aurora-config.php.',
            // PHP-handled: emitted onto .au-aurora-bg by skin-profile.php.
        ],
        'au_sky' => [
            'section' => 'AURORA',
            'type'    => 'select',
            'label'   => 'Sky Base Colour',
            'default' => '#000000',
            'options' => [
                '#000000' => 'Deep black',
                '#0a0a1a' => 'Deep navy',
                '#0d0d2b' => 'Deep indigo',
            ],
            // PHP-handled (skin-profile.php).
        ],
        'au_l1_opacity' => [
            'section'  => 'AURORA',
            'type'     => 'range_numeric',
            'label'    => 'Background Opacity',
            // Integer PERCENT — the admin range widget is integer-only; skin-profile.php
            // divides by 100 and feeds it to the canvas curtains as their alpha.
            'default'  => '50',
            'min'      => '5',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'How present the aurora feels behind the grid.',
            // PHP-handled → data-au-opacity (aurora-bg.js).
        ],
        'au_cycle_time' => [
            'section'  => 'AURORA',
            'type'     => 'range_numeric',
            'label'    => 'Aurora Cycle Time',
            'default'  => '240',
            'min'      => '15',
            'max'      => '240',
            'step'     => '5',
            'unit'     => 's',
            'hint'     => 'Seconds for one full pass through the palette. Higher = slower. Default is deliberately geological.',
            // PHP-handled → --au-cycle on .au-aurora-bg.
        ],

        // ---- BORDER WAVE -------------------------------------------
        'au_wave_direction' => [
            'section' => 'BORDER WAVE',
            'type'    => 'select',
            'label'   => 'Wave Direction',
            'default' => 'ltr',
            'options' => [
                'ltr'   => 'Left to right',
                'rtl'   => 'Right to left',
                'ttb'   => 'Top to bottom',
                'btt'   => 'Bottom to top',
                'dtlbr' => 'Diagonal ↘ (top-left to bottom-right)',
                'dbrtl' => 'Diagonal ↖ (bottom-right to top-left)',
            ],
            // PHP-handled → data-au-border-dir; read by aurora-wave.js.
        ],
        'au_border_style' => [
            'section' => 'BORDER WAVE',
            'type'    => 'select',
            'label'   => 'Border Style',
            'default' => 'circle',
            'options' => [
                'circle' => 'Circle each tile',
                'sweep'  => 'Circle + sweep across',
                'across' => 'Wave across grid',
                'pulse'  => 'Scatter pulse',
            ],
            // PHP-handled → data-au-border-style; read by aurora-wave.js.
        ],
        'au_wave_rhythm' => [
            'section' => 'BORDER WAVE',
            'type'    => 'select',
            'label'   => 'Wave Rhythm',
            'default' => 'breath',
            'options' => [
                'breath'   => 'Slow–fast–slow breath',
                'constant' => 'Constant slow',
            ],
            // PHP-handled → data-au-border-rhythm.
        ],
        'au_wave_speed' => [
            'section'  => 'BORDER WAVE',
            'type'     => 'range_numeric',
            'label'    => 'Wave Speed',
            'default'  => '160',
            'min'      => '40', 'max' => '400', 'step' => '5',
            'hint'     => 'Border-wave clock, independent of the sky cycle. Lower = faster shimmer; higher = slower. 160 is the original counterpoint pace.',
            // PHP-handled → data-au-border-cycle (read by ss-engine-aurora-wave.js).
        ],
        'au_border_width' => [
            'section'  => 'BORDER WAVE',
            'type'     => 'range_numeric',
            'label'    => 'Tile Border Width',
            'default'  => '2',
            'min'      => '1',
            'max'      => '10',
            'step'     => '1',
            'unit'     => 'px',
            // PHP-handled → --tile-bw; also drives the 'auto' corner radius.
        ],
        'au_border_opacity' => [
            'section'  => 'BORDER WAVE',
            'type'     => 'range_numeric',
            'label'    => 'Border Opacity',
            'default'  => '100',
            'min'      => '10',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'Strength of the tile border ring.',
            // PHP-handled → --ring-op.
        ],

        // ---- GRID --------------------------------------------------
        'au_tile_corners' => [
            'section'  => 'GRID',
            'type'     => 'select',
            'label'    => 'Tile Corners',
            'default'  => 'auto',
            'options'  => [
                'auto'    => 'Round with border thickness',
                'square'  => 'Square',
                'rounded' => 'Rounded',
            ],
            // PHP-handled → --tile-radius (skin-profile.php derives it from corners + width).
        ],
        'au_carousel_indicator' => [
            'section'  => 'GRID',
            'type'     => 'select',
            'label'    => 'Carousel Indicator Style',
            'default'  => 'icon',
            'options'  => [
                'icon'  => 'Layered squares icon',
                'count' => 'Image count badge',
                'none'  => 'No indicator',
            ],
        ],
        'au_hover_overlay' => [
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

        // ---- NAV ---------------------------------------------------
        'au_nav_line_color' => [
            'section' => 'NAV',
            'type'    => 'color',
            'label'   => 'Nav Line Colour',
            'default' => '#6ff0a0',
            'hint'    => 'Colour of the divider lines above/below the sticky nav. Ignored when Nav Line Mode follows the aurora wave.',
        ],
        'au_nav_underline' => [
            'section' => 'NAV',
            'type'    => 'select',
            'label'   => 'Nav Line Under-line',
            'default' => '1',
            'options' => [
                '0' => 'Off',
                '1' => 'On — dark indigo line tucked under each nav line',
            ],
            'hint'    => 'The decorative second line under each nav divider.',
        ],
        'au_navbar_color' => [
            'section' => 'NAV', 'type' => 'color', 'label' => 'Navbar Colour',
            'default' => '#0a0e1a',
            'hint'    => 'Background colour of the sticky nav bar. PHP-handled → --au-navbar-bg.',
        ],
        'au_navbar_opacity' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Navbar Opacity — Landing',
            'default' => '0', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
            'hint'    => 'Landing page only. 0 = transparent. Raise for a solid bar over the aurora.',
        ],
        'au_navbar_opacity_inner' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Navbar Opacity — Other Pages',
            'default' => '', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
            'hint'    => 'Archive / post / static pages. Leave blank to match the Landing value.',
        ],
        'au_navline_shadow_color' => [
            'section' => 'NAV', 'type' => 'color', 'label' => 'Nav Line Shadow Colour',
            'default' => '#000000',
        ],
        'au_navline_shadow_size' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Nav Line Shadow Size',
            'default' => '0', 'min' => '0', 'max' => '3', 'step' => '1', 'unit' => 'px',
            'hint'    => '0 = no shadow. Capped 3px, always down-and-right.',
        ],
        'au_navline_shadow_opacity' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Nav Line Shadow Opacity',
            'default' => '40', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
        ],
        'au_nav_line_mode' => [
            'section'  => 'NAV',
            'type'     => 'select',
            'label'    => 'Nav Border Lines',
            'default'  => 'static',
            'options'  => [
                'static' => 'Static (uses Border / Divider colour)',
                'aurora' => 'Aurora wave (shifts with the tile borders)',
            ],
            'hint'     => 'Aurora mode tracks the live border wave colour.',
            // PHP-handled — skin-profile.php sets --nav-line-color.
        ],
        'au_nav_line_opacity' => [
            'section'  => 'NAV',
            'type'     => 'range_numeric',
            'label'    => 'Nav Line Opacity',
            'default'  => '100',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'Opacity of the nav divider lines (both the bright rule and its companion).',
            // PHP-handled → --nav-line-opacity (skin-profile.php).
        ],
        'au_nav_glow_color' => [
            'section' => 'NAV',
            'type'    => 'color',
            'label'   => 'Nav Glow Colour',
            'default' => '#61e96e',
            'hint'    => 'Outer glow behind the menu links (home / blogroll / pages).',
            // PHP-handled → --nav-text-glow (skin-profile.php).
        ],
        'au_nav_glow_size' => [
            'section'  => 'NAV',
            'type'     => 'range_numeric',
            'label'    => 'Nav Glow Size',
            'default'  => '8',
            'min'      => '0',
            'max'      => '40',
            'step'     => '2',
            'unit'     => 'px',
            'hint'     => '0 = no glow.',
            // PHP-handled → --nav-text-glow.
        ],
        'au_nav_glow_opacity' => [
            'section'  => 'NAV',
            'type'     => 'range_numeric',
            'label'    => 'Nav Glow Opacity',
            'default'  => '45',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            // PHP-handled → --nav-text-glow.
        ],

        // ---- POSTS LABEL -------------------------------------------
        'au_posts_glow_color' => [
            'section' => 'POSTS LABEL', 'type' => 'color', 'label' => 'Posts Glow Colour',
            'default' => '#000000',
        ],
        'au_posts_glow_size' => [
            'section' => 'POSTS LABEL', 'type' => 'range_numeric', 'label' => 'Posts Glow Size',
            'default' => '0', 'min' => '0', 'max' => '40', 'step' => '2', 'unit' => 'px',
            'hint'    => '0 = no glow (falls back to the profile text glow).',
        ],
        'au_posts_glow_opacity' => [
            'section' => 'POSTS LABEL', 'type' => 'range_numeric', 'label' => 'Posts Glow Opacity',
            'default' => '0', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
        ],

        // ---- PANEL -------------------------------------------------
        'au_panel_color' => [
            'section' => 'PANEL', 'type' => 'color', 'label' => 'Panel Colour',
            'default' => '#0a0e1a',
            'hint'    => 'Backing colour behind the content column on every page so it reads over the aurora. PHP-handled → --panel-bg.',
        ],
        'au_panel_opacity' => [
            'section' => 'PANEL', 'type' => 'range_numeric', 'label' => 'Panel Opacity',
            'default' => '0', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
            'hint'    => '0 = transparent (the aurora shows through). Raise until text is comfortable to read.',
        ],
        'au_panel_extend' => [
            'section' => 'PANEL', 'type' => 'range_numeric', 'label' => 'Panel Extend (gutters)',
            'default' => '0', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => 'px',
            'hint'    => 'How far the panel bleeds out past the content each side. 0 = flush, 100 = 100px gutters.',
        ],

        // ---- PROFILE HEADER ----------------------------------------
        'au_profile_header' => [
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
        'au_show_tagline' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Show Tagline (Site Description)',
            'default'  => '1',
            'options'  => ['1' => 'Show', '0' => 'Hide'],
        ],

        // ---- TEXT GLOW ---------------------------------------------
        'au_glow_color' => [
            'section' => 'TEXT GLOW',
            'type'    => 'color',
            'label'   => 'Text Glow Colour',
            'default' => '#000000',
            'hint'    => 'Halo colour behind title, tagline, and bio. Black for dark glow; white for light glow.',
            // PHP-handled → --profile-text-glow (skin-profile.php).
        ],
        'au_glow_size' => [
            'section'  => 'TEXT GLOW',
            'type'     => 'range_numeric',
            'label'    => 'Text Glow Size',
            'default'  => '0',
            'min'      => '0',
            'max'      => '40',
            'step'     => '2',
            'unit'     => 'px',
            'hint'     => '0 = no glow. Increase for a wider halo.',
            // PHP-handled → --profile-text-glow.
        ],
        'au_glow_opacity' => [
            'section'  => 'TEXT GLOW',
            'type'     => 'range_numeric',
            'label'    => 'Text Glow Opacity',
            'default'  => '0',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            // PHP-handled → --profile-text-glow.
        ],

        // ---- IMAGE FRAME -------------------------------------------
        'au_customize_level' => [
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
        'au_frame_size_pct' => [
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
        ],
        'au_frame_border_px' => [
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
        'au_frame_border_color' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'color',
            'label'   => 'Border Colour',
            'default' => '#000000',
        ],
        'au_frame_bg_color' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'color',
            'label'   => 'Frame Background Colour',
            'default' => '#000000',
        ],
        'au_frame_shadow' => [
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
        'au_treatment_mode' => [
            'section' => 'TREATMENT',
            'type'    => 'select',
            'label'   => 'Background Treatment',
            'default' => 'none',
            'options' => [
                'none'  => 'None (aurora background shows through)',
                'image' => 'Background image',
                'color' => 'Solid colour',
            ],
            'hint'    => 'Optional. A treatment sits in front of the aurora layer — leave on None to let the aurora show.',
        ],
        'au_treatment_image' => [
            'section'    => 'TREATMENT',
            'type'       => 'image',
            'label'      => 'Treatment Image',
            'default'    => '',
            'accept'     => 'image/jpeg,image/png,image/webp',
            'min_width'  => 1920,
            'min_height' => 1080,
            'hint'       => 'Used when Treatment = Background image. Minimum 1920×1080px.',
        ],
        'au_treatment_position' => [
            'section' => 'TREATMENT',
            'type'    => 'select',
            'label'   => 'Image Anchor (when it overshoots)',
            'default' => 'center',
            'options' => [
                'center' => 'Centre',
                'top'    => 'Snap to top',
                'bottom' => 'Snap to bottom',
            ],
        ],
        'au_treatment_color' => [
            'section' => 'TREATMENT',
            'type'    => 'color',
            'label'   => 'Treatment Colour',
            'default' => '#000000',
            'hint'    => 'Used when Treatment = Solid colour.',
        ],
        'au_treatment_overlay' => [
            'section'  => 'TREATMENT',
            'type'     => 'range_numeric',
            'label'    => 'Overlay  (left darkens · right lightens)',
            'default'  => '0',
            'min'      => '-100',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'Centre = none. Drag left to darken the background, right to lighten it.',
        ],

    ],
];
// ===== SNAPSMACK EOF =====
