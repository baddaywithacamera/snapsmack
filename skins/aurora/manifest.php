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
    'version'     => '1.0.19',
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
    ],

    'community_comments'  => true,
    'community_likes'     => true,
    'community_reactions' => false,

    'options' => [

        // ---- AURORA (Layer 1 — atmospheric background) ---------------------
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

        // ---- BORDER WAVE (Layer 2) ----------------------------------------
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

        // ---- GRID APPEARANCE -----------------------------------------------
        'au_gap' => [
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
        // NOTE: no "grid background / gap colour" option — unlike The Grid, AURORA
        // shows the live aurora through the gaps between tiles (the grid container
        // is transparent), so a gap colour would just paint over the effect.
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

        // ---- PROFILE HEADER ------------------------------------------------
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
        'au_blog_title_font' => [
            'section'        => 'TITLE & TAGLINE',
            'type'           => 'select',
            'label'          => 'Blog Title Font',
            'default'        => 'inherit',
            'options'        => array_merge(['inherit' => 'Same as Body Font'], $fonts),
            'selector'       => ':root',
            'property'       => '--blog-title-font',
            'is_font'        => true,
            'no_size_slider' => true,
        ],
        'au_blog_title_size' => [
            'section'  => 'TITLE & TAGLINE',
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
        'au_blog_title_weight' => [
            'section'  => 'TITLE & TAGLINE',
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
        'au_blog_title_color' => [
            'section'  => 'TITLE & TAGLINE',
            'type'     => 'color',
            'label'    => 'Blog Title Colour',
            'default'  => '#eaeaea',
            'selector' => ':root',
            'property' => '--blog-title-color',
        ],
        'au_show_tagline' => [
            'section'  => 'TITLE & TAGLINE',
            'type'     => 'select',
            'label'    => 'Show Tagline (Site Description)',
            'default'  => '1',
            'options'  => ['1' => 'Show', '0' => 'Hide'],
        ],
        'au_tagline_font' => [
            'section'        => 'TITLE & TAGLINE',
            'type'           => 'select',
            'label'          => 'Tagline Font',
            'default'        => 'inherit',
            'options'        => array_merge(['inherit' => 'Same as Body Font'], $fonts),
            'selector'       => ':root',
            'property'       => '--tagline-font',
            'is_font'        => true,
            'no_size_slider' => true,
        ],
        'au_tagline_size' => [
            'section'  => 'TITLE & TAGLINE',
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
        'au_tagline_weight' => [
            'section'  => 'TITLE & TAGLINE',
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
        'au_tagline_color' => [
            'section'  => 'TITLE & TAGLINE',
            'type'     => 'color',
            'label'    => 'Tagline Colour',
            'default'  => '#8a8a8a',
            'selector' => ':root',
            'property' => '--tagline-color',
        ],
        'au_bio_size' => [
            'section'  => 'TITLE & TAGLINE',
            'type'     => 'range_numeric',
            'label'    => 'Description / Bio Size',
            'default'  => '14',
            'min'      => '10',
            'max'      => '28',
            'step'     => '1',
            'unit'     => 'px',
            'selector' => ':root',
            'property' => '--bio-size',
        ],
        // ---- TEXT GLOW (readability over shifting aurora background) ----------
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

        // ---- NAV -----------------------------------------------------------
        'au_nav_case' => [
            'section'  => 'MENU / NAV',
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
        'au_nav_font' => [
            'section'        => 'MENU / NAV',
            'type'           => 'select',
            'label'          => 'Nav Font',
            'default'        => 'inherit',
            'options'        => array_merge(['inherit' => 'Same as Body Font'], $fonts),
            'selector'       => ':root',
            'property'       => '--nav-font',
            'is_font'        => true,
            'no_size_slider' => true,
        ],
        'au_nav_color' => [
            'section'  => 'MENU / NAV',
            'type'     => 'color',
            'label'    => 'Nav Link Colour',
            'default'  => '#8a8a8a',
            'selector' => ':root',
            'property' => '--nav-color',
        ],
        'au_nav_line_mode' => [
            'section'  => 'MENU / NAV',
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
            'section'  => 'MENU / NAV',
            'type'     => 'range_numeric',
            'label'    => 'Nav Line Opacity',
            'default'  => '100',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'Opacity of the dark companion line under each divider.',
            // PHP-handled → --nav-line-opacity (skin-profile.php).
        ],
        'au_nav_glow_color' => [
            'section' => 'MENU / NAV',
            'type'    => 'color',
            'label'   => 'Nav Glow Colour',
            'default' => '#61e96e',
            'hint'    => 'Outer glow behind the menu links (home / blogroll / pages).',
            // PHP-handled → --nav-text-glow (skin-profile.php).
        ],
        'au_nav_glow_size' => [
            'section'  => 'MENU / NAV',
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
            'section'  => 'MENU / NAV',
            'type'     => 'range_numeric',
            'label'    => 'Nav Glow Opacity',
            'default'  => '45',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            // PHP-handled → --nav-text-glow.
        ],

        // ---- COLOURS -------------------------------------------------------
        'au_post_bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Image Page Background',
            'default'  => '#000000',
            'selector' => ':root',
            'property' => '--post-bg',
        ],
        'au_bg_primary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Page Background',
            'default'  => '#000000',
            'selector' => ':root',
            'property' => '--bg-primary',
        ],
        'au_text_primary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Primary Text',
            'default'  => '#eaeaea',
            'selector' => ':root',
            'property' => '--text-primary',
        ],
        'au_text_secondary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Secondary Text',
            'default'  => '#8a8a8a',
            'selector' => ':root',
            'property' => '--text-secondary',
        ],
        'au_accent' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Accent / Link Colour',
            'default'  => '#61e96e',
            'selector' => ':root',
            'property' => '--accent-color',
        ],
        'au_border_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Border / Divider Colour',
            'default'  => '#242424',
            'selector' => ':root',
            'property' => '--border-color',
        ],
        'au_bio_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Description / Bio Text',
            'default'  => '#8a8a8a',
            'selector' => ':root',
            'property' => '--bio-color',
            'hint'     => 'Colour of the bio paragraph under the profile. Independent of Secondary Text.',
        ],

        // ---- TYPOGRAPHY ----------------------------------------------------
        'au_font_body' => [
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

        // ---- LAYOUT --------------------------------------------------------
        'au_max_width' => [
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
        'au_gutter' => [
            'section'  => 'TREATMENT',
            'type'     => 'range_numeric',
            'label'    => 'Side Gutter (margin over background)',
            'default'  => '0',
            'min'      => '0',
            'max'      => '400',
            'step'     => '4',
            'unit'     => 'px',
            'selector' => ':root',
            'property' => '--grid-gutter',
        ],

        // ---- TREATMENT (full-page background behind a centred content card) -
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
