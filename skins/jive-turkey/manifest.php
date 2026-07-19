<?php
/**
 * SNAPSMACK - JIVE TURKEY Skin Manifest
 * v1.0.17
 *
 * Desktop GRAMOFSMACK skin. A classic 3-across square-tile grid (The Grid's
 * proven column architecture) overlaid with a two-layer animation system:
 *   Layer 1 — a slow CSS jive-turkey that breathes the active palette behind the
 *             photography (skins/jive-turkey/style.css + skin-profile.php).
 *   Layer 2 — a colour wave that travels across the tile borders, driven by
 *             skins/jive-turkey/assets/js/jive-turkey-wave.js.
 * The photography is always the content; the animation is always the atmosphere.
 *
 * Desktop-only: declared incompatible with mobile/tablet contexts. PHOTOGRAM and
 * TELEGRAM handle mobile. Palette/sky options are data-driven via jive-turkey-config.php.
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
    'name'        => 'JIVE TURKEY',
    'version'     => '0.1.18', // 0.1.18: SCROLLS travels in all 8 directions (up/down/left/right + 4 diagonals, or random over 8). // 0.1.17: JIVE TURKEY border restored to OUTWARD box-shadow band (outside the photo, image never resizes) with the tile spacing that worked; image corner now matches the band corner (no corner gap); new Transition Length control. // 0.1.16: SCROLLS background mode (recoloured 70s ribbon-scroll tile; direction v/h/diag/random; colour drift fade/blink/off) + tile corner radius nests inside the ring. 0.1.15: SOLO IMAGE presentation — backdrop (skin|image) + scrim (colour@opacity) + card (colour/padding/text primary+secondary); supersedes 0.1.14 scrim-only; 0.1.4: border rides .jt-ring (no photo resize / dark corners); DAISY clears panel; crisp bg, % units, regrouped settings
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'Deliberately loud 70s GRAMOFSMACK skin. A 3-across square grid over an animated flat-graphic background — kaleidoscope, flower field, racing-stripe ribbons, sunburst daisy, Bauhaus shuffle — that never sits still, with SURPRISE rolling a fresh look every visit and a colour border cycling across the tiles. Maximalist on purpose; the photos still win.',
    'status'      => 'stable',
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
        // Shared Grid-family engines + JIVE TURKEY's own atmosphere engines
        // (all registered in core/manifest-inventory.php, all in /assets/js):
        'smack-grid-modal',
        'smack-grid-lightbox',
        'smack-grid-nav',
        'smack-jive-turkey-bg',
        'smack-jive-border',
        'smack-progressive-reveal',
        'smack-tag-infinite',     // shared prefix-derived hashtag infinite scroll
        'smack-gram-search',      // bottom-left magnifier → expanding search dock
    ],

    'community_comments'  => true,
    'community_likes'     => true,
    'community_reactions' => false,

    'options' => [

        // ---- BACKGROUND (Layer 1 — 70s animated background) --------------
        'jt_palette' => [
            'section' => 'BACKGROUND',
            'type'    => 'select',
            'label'   => 'Colourway',
            'default' => 'HARVEST',
            'options' => [
                'BARF'    => 'BARF — piss yellow / avocado / earth brown',
                'BLECH'   => 'BLECH — purple / burnt orange / shag gold',
                'GROOVY'  => 'GROOVY — purple / hot pink / blue',
                'HARVEST' => 'HARVEST — harvest gold / burnt orange / brown',
            ],
            'hint'    => 'The 70s colourway. Drives the background AND the tile borders. Ignored when Both Barrels is on. Colourways live in jive-turkey-config.php.',
        ],
        'jt_mode' => [
            'section' => 'BACKGROUND',
            'type'    => 'select',
            'label'   => 'Background Mode',
            'default' => 'surprise',
            'options' => [
                'surprise' => 'SURPRISE — random mode each visit',
                'cycle'    => 'CYCLE — rotate through the modes',
                'scope'    => 'SCOPE — kaleidoscope',
                'bloom'    => 'BLOOM — flower field',
                'flow'     => 'FLOW — racing-stripe ribbons',
                'daisy'    => 'DAISY — sunburst + smiley daisy',
                'reels'    => 'REELS — Bauhaus shuffle grid',
                'scrolls'  => 'SCROLLS — striped ribbons + spiral scrolls',
                'solid'    => 'SOLID — plain background colour, no animation',
            ],
            'hint'    => 'Pick one look, or let SURPRISE / CYCLE rotate them.',
        ],
        'jt_scrolls_axis' => [
            'section' => 'BACKGROUND',
            'type'    => 'select',
            'label'   => 'Scrolls Direction',
            'default' => 'down',
            'options' => [
                'down'   => 'Down ↓',
                'up'     => 'Up ↑',
                'left'   => 'Left ←',
                'right'  => 'Right →',
                'dr'     => 'Down-right ↘',
                'dl'     => 'Down-left ↙',
                'ur'     => 'Up-right ↗',
                'ul'     => 'Up-left ↖',
                'random' => 'Random — a different one of the 8 each visit',
            ],
            'hint'    => 'Only affects SCROLLS: which way the striped ribbons travel — any of 8 directions (up/down/left/right and the four diagonals), or let RANDOM pick one each visit. The scrolls slide off one edge and reappear on the other.',
        ],
        'jt_scrolls_colour' => [
            'section' => 'BACKGROUND',
            'type'    => 'select',
            'label'   => 'Scrolls Colour Drift',
            'default' => 'fade',
            'options' => [
                'fade'  => 'Fade — colours drift slowly through the palette',
                'blink' => 'Blink — colours switch on a beat',
                'off'   => 'Off — hold one colourway',
            ],
            'hint'    => 'Only affects SCROLLS: let the ribbon colours cycle through the colourway. Fade crossfades gracefully; Blink switches on a beat; Off holds still. The field and the pale centre-line stay put either way.',
        ],
        'jt_random_colour' => [
            'section' => 'BACKGROUND',
            'type'    => 'select',
            'label'   => 'Both Barrels',
            'default' => '1',
            'options' => [
                '1' => 'On — random colourway each visit too',
                '0' => 'Off — use the chosen colourway',
            ],
            'hint'    => 'With SURPRISE / CYCLE, also randomise the colourway each visit. The tile borders follow automatically.',
        ],
        'jt_speed' => [
            'section'  => 'BACKGROUND',
            'type'     => 'range_numeric',
            'label'    => 'Background Speed',
            'default'  => '45',
            'min'      => '1', 'max' => '100', 'step' => '1',
            'unit'     => '%',
            'hint'     => 'Background animation speed — affects ALL modes: SCOPE, BLOOM, FLOW, DAISY, REELS.',
        ],
        'jt_cycle_time' => [
            'section'  => 'BACKGROUND',
            'type'     => 'range_numeric',
            'label'    => 'Cycle Dwell',
            'default'  => '14',
            'min'      => '6', 'max' => '60', 'step' => '1',
            'unit'     => 's',
            'hint'     => 'Seconds each mode holds before CYCLE moves on.',
        ],

        // ---- TILE BORDER (Layer 2 — colour wave across the tiles) --------
        'jt_border_on' => [
            'section' => 'TILE BORDER',
            'type'    => 'select',
            'label'   => 'Tile Borders',
            'default' => '1',
            'options' => [ '1' => 'On — colour border', '0' => 'Off' ],
            'hint'    => 'A colour band OUTSIDE each tile, in the gutter (never over the photo, image never resizes). It shrinks in to nothing, flips to the next colourway colour, then pops back out — staggered across the grid as a wave.',
        ],
        'jt_border_width' => [
            'section'  => 'TILE BORDER',
            'type'     => 'range_numeric',
            'label'    => 'Border Width',
            'default'  => '12',
            'min'      => '5', 'max' => '15', 'step' => '1',
            'unit'     => 'px',
            'hint'     => 'Full width of the colour band (it grows outward into the tile gutter, never over the photo). Reserved as grid padding so the grid stays within the standard Instagram column width. The band shrinks to 0 and back on each colour change.',
        ],
        'jt_border_speed' => [
            'section'  => 'TILE BORDER',
            'type'     => 'range_numeric',
            'label'    => 'Colour-Change Speed',
            'default'  => '60',
            'min'      => '0', 'max' => '100', 'step' => '5',
            'unit'     => '%',
            'hint'     => 'How fast the border cycles colour. Higher = faster.',
        ],
        'jt_border_wave' => [
            'section'  => 'TILE BORDER',
            'type'     => 'range_numeric',
            'label'    => 'Wave Stagger',
            'default'  => '45',
            'min'      => '0', 'max' => '100', 'step' => '5',
            'unit'     => '%',
            'hint'     => 'How much the colour change staggers across the grid as a travelling wave.',
        ],
        'jt_border_trans' => [
            'section'  => 'TILE BORDER',
            'type'     => 'range_numeric',
            'label'    => 'Transition Length',
            'default'  => '35',
            'min'      => '0', 'max' => '100', 'step' => '5',
            'unit'     => '%',
            'hint'     => 'How long the shrink-in / pop-out takes on each colour change. Higher = slower and easier to see; the band always rests at full width between changes.',
        ],
        'jt_border_dir' => [
            'section' => 'TILE BORDER',
            'type'    => 'select',
            'label'   => 'Wave Direction',
            'default' => 'dtlbr',
            'options' => [
                'ltr'   => 'Left to right',
                'rtl'   => 'Right to left',
                'ttb'   => 'Top to bottom',
                'btt'   => 'Bottom to top',
                'dtlbr' => 'Diagonal (top-left to bottom-right)',
                'dbrtl' => 'Diagonal (bottom-right to top-left)',
            ],
        ],

        // ---- GRID --------------------------------------------------------
        'jt_gap' => [
            'section'  => 'GRID',
            'type'     => 'range_numeric',
            'label'    => 'Tile Spacing',
            'default'  => '2',
            'min'      => '0', 'max' => '20', 'step' => '1',
            'unit'     => 'px',
            'hint'     => 'Pure background gap between tiles, measured border-edge to border-edge. The colour border sits INSIDE each tile (the photo shrinks to make room), so this spacing is exactly what you set: 20px spacing is always 20px of background, whatever the border width. The grid stays within the standard Instagram column width.',
        ],
        'jt_nav_tile_gap' => [
            'section'  => 'GRID',
            'type'     => 'range_numeric',
            'label'    => 'Nav-to-Tiles Gap',
            'default'  => '2',
            'min'      => '0', 'max' => '40', 'step' => '1',
            'unit'     => 'px',
            'hint'     => 'Vertical space between the sticky nav bar and the top row of tiles.',
        ],
        'jt_carousel_indicator' => [
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
        'jt_hover_overlay' => [
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

        // ---- FOOTER ------------------------------------------------------
        'jt_footer_gap' => [
            'section'  => 'FOOTER',
            'type'     => 'range_numeric',
            'label'    => 'Space Above Footer',
            'default'  => '40',
            'min'      => '0', 'max' => '120', 'step' => '2',
            'unit'     => 'px',
            'hint'     => 'Vertical gap between the bottom row of tiles and the footer.',
        ],
        'jt_footer_color' => [
            'section'  => 'FOOTER',
            'type'     => 'color',
            'label'    => 'Footer Background Colour',
            'default'  => '#0a0e1a',
            'hint'     => 'Footer bar background. Shows only when Footer Opacity is above 0; otherwise the footer follows the panel / background.',
        ],
        'jt_footer_opacity' => [
            'section'  => 'FOOTER',
            'type'     => 'range_numeric',
            'label'    => 'Footer Opacity',
            'default'  => '0',
            'min'      => '0', 'max' => '100', 'step' => '5',
            'unit'     => '%',
            'hint'     => '0 = follow the panel / background (default). Raise for a solid footer bar.',
        ],

        // ---- NAV — sticky nav bar, links, divider lines + glow (all nav settings in one place) ---
        'jt_navbar_color' => [
            'section' => 'NAV', 'type' => 'color', 'label' => 'Navbar Colour',
            'default' => '#0a0e1a',
            'hint'    => 'Background colour of the sticky nav bar. PHP-handled → --jt-navbar-bg.',
        ],
        'jt_navbar_opacity' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Navbar Opacity — Landing',
            'default' => '0', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
            'hint'    => 'Landing page only. 0 = transparent. Raise for a solid bar over the jive-turkey.',
        ],
        'jt_navbar_opacity_inner' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Navbar Opacity — Other Pages',
            'default' => '', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
            'hint'    => 'Archive / post / static pages. Leave blank to match the Landing value.',
        ],
        'jt_nav_line_color' => [
            'section' => 'NAV',
            'type'    => 'color',
            'label'   => 'Nav Line Colour',
            'default' => '#6ff0a0',
            'hint'    => 'Colour of the divider lines above and below the sticky nav. Ignored when Nav Line Mode follows the wave.',
        ],
        'jt_nav_line_mode' => [
            'section'  => 'NAV',
            'type'     => 'select',
            'label'    => 'Nav Border Lines',
            'default'  => 'static',
            'options'  => [
                'static' => 'Static (uses Border / Divider colour)',
                'jive-turkey' => 'Jive Turkey wave (shifts with the tile borders)',
            ],
            'hint'     => 'Jive Turkey mode tracks the live border wave colour.',
            // PHP-handled — skin-profile.php sets --nav-line-color.
        ],
        'jt_nav_line_opacity' => [
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
        'jt_nav_underline' => [
            'section' => 'NAV',
            'type'    => 'select',
            'label'   => 'Nav Line Under-line',
            'default' => '0',
            'options' => [
                '0' => 'Off',
                '1' => 'On — dark indigo line tucked under each nav line',
            ],
            'hint'    => 'The decorative second line under each nav divider. Off by default.',
        ],
        'jt_navline_shadow_color' => [
            'section' => 'NAV', 'type' => 'color', 'label' => 'Nav Line Shadow Colour',
            'default' => '#000000',
        ],
        'jt_navline_shadow_size' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Nav Line Shadow Size',
            'default' => '0', 'min' => '0', 'max' => '3', 'step' => '1', 'unit' => 'px',
            'hint'    => '0 = no shadow. Capped 3px, always down-and-right.',
        ],
        'jt_navline_shadow_opacity' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Nav Line Shadow Opacity',
            'default' => '40', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
        ],
        'jt_nav_glow_color' => [
            'section' => 'NAV',
            'type'    => 'color',
            'label'   => 'Nav Glow Colour',
            'default' => '#61e96e',
            'hint'    => 'Outer glow behind the menu links (home / blogroll / pages).',
            // PHP-handled → --nav-text-glow (skin-profile.php).
        ],
        'jt_nav_glow_size' => [
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
        'jt_nav_glow_opacity' => [
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

        // ---- POSTS LABEL -------------------------------------------------
        'jt_posts_color' => [
            'section' => 'POSTS LABEL', 'type' => 'color', 'label' => 'Posts Colour',
            'default' => '#8a8a8a',
            'hint'    => 'Colour of the post-count number and the "posts" label. PHP-handled -> --post-count-color.',
        ],
        'jt_posts_glow_color' => [
            'section' => 'POSTS LABEL', 'type' => 'color', 'label' => 'Posts Glow Colour',
            'default' => '#000000',
        ],
        'jt_posts_glow_size' => [
            'section' => 'POSTS LABEL', 'type' => 'range_numeric', 'label' => 'Posts Glow Size',
            'default' => '0', 'min' => '0', 'max' => '40', 'step' => '2', 'unit' => 'px',
            'hint'    => '0 = no glow (falls back to the profile text glow).',
        ],
        'jt_posts_glow_opacity' => [
            'section' => 'POSTS LABEL', 'type' => 'range_numeric', 'label' => 'Posts Glow Opacity',
            'default' => '0', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
        ],

        // ---- PANEL (readability panel behind text) -----------------------
        'jt_panel_color' => [
            'section' => 'PANEL', 'type' => 'color', 'label' => 'Panel Colour',
            'default' => '#0a0e1a',
            'hint'    => 'Backing colour behind the content column on every page so it reads over the jive-turkey. PHP-handled → --panel-bg.',
        ],
        'jt_panel_opacity' => [
            'section' => 'PANEL', 'type' => 'range_numeric', 'label' => 'Panel Opacity',
            'default' => '0', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
            'hint'    => '0 = transparent (the jive-turkey shows through). Raise until text is comfortable to read.',
        ],
        'jt_panel_extend' => [
            'section' => 'PANEL', 'type' => 'range_numeric', 'label' => 'Panel Extend (gutters)',
            'default' => '0', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => 'px',
            'hint'    => 'How far the panel bleeds out past the content each side. 0 = flush, 100 = 100px gutters.',
        ],

        // ---- PROFILE HEADER ----------------------------------------------
        'jt_profile_header' => [
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
        'jt_show_tagline' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Show Tagline (Site Description)',
            'default'  => '1',
            'options'  => ['1' => 'Show', '0' => 'Hide'],
        ],

        // ---- TITLE & TAGLINE ---------------------------------------
        'jt_blog_title_font' => [
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
        'jt_blog_title_size' => [
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
        'jt_blog_title_weight' => [
            'section'  => 'TITLE & TAGLINE',
            'type'     => 'select',
            'label'    => 'Blog Title Weight',
            'default'  => '700',
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
        'jt_blog_title_color' => [
            'section'  => 'TITLE & TAGLINE',
            'type'     => 'color',
            'label'    => 'Blog Title Colour',
            'default'  => '#eaeaea',
            'selector' => ':root',
            'property' => '--blog-title-color',
        ],
        'jt_tagline_font' => [
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
        'jt_tagline_size' => [
            'section'  => 'TITLE & TAGLINE',
            'type'     => 'range_numeric',
            'label'    => 'Tagline Size',
            'default'  => '20',
            'min'      => '10',
            'max'      => '36',
            'step'     => '1',
            'unit'     => 'px',
            'selector' => ':root',
            'property' => '--tagline-size',
        ],
        'jt_tagline_weight' => [
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
        'jt_tagline_color' => [
            'section'  => 'TITLE & TAGLINE',
            'type'     => 'color',
            'label'    => 'Tagline Colour',
            'default'  => '#8a8a8a',
            'selector' => ':root',
            'property' => '--tagline-color',
        ],
        'jt_bio_size' => [
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

        // ---- TYPOGRAPHY --------------------------------------------
        'jt_font_body' => [
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

        // ---- COLOURS -----------------------------------------------
        'jt_bg_primary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Page Background',
            'default'  => '#000000',
            'selector' => ':root',
            'property' => '--bg-primary',
        ],
        'jt_text_primary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Primary Text',
            'default'  => '#eaeaea',
            'selector' => ':root',
            'property' => '--text-primary',
        ],
        'jt_text_secondary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Secondary Text',
            'default'  => '#8a8a8a',
            'selector' => ':root',
            'property' => '--text-secondary',
        ],
        'jt_accent' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Accent / Link Colour',
            'default'  => '#61e96e',
            'selector' => ':root',
            'property' => '--accent-color',
        ],
        'jt_border_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Border / Divider Colour',
            'default'  => '#242424',
            'selector' => ':root',
            'property' => '--border-color',
        ],
        'jt_bio_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Description / Bio Text',
            'default'  => '#8a8a8a',
            'selector' => ':root',
            'property' => '--bio-color',
            'hint'     => 'Colour of the bio paragraph under the profile. Independent of Secondary Text.',
        ],
        'jt_post_bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Image Page Background',
            'default'  => '#000000',
            'selector' => ':root',
            'property' => '--post-bg',
        ],

        // ---- SOLO IMAGE (single-post view presentation) -----------------
        // Three stacked layers, owner-tunable (spec: solo-image-presentation-v0.1):
        //   1 BACKDROP  — skin background OR an owner image
        //   2 SCRIM     — a colour layer at variable opacity over the backdrop
        //                 (100% = solid colour; 0% = backdrop fully shows through)
        //   3 CARD      — the modal the photo sits in: colour, image padding
        //                 (caption panel reflows), + primary/secondary text.
        // All PHP-handled → --jt-solo-* vars (skin-profile.php). Defaults
        // reproduce the previous look exactly (backdrop=skin, scrim #000 @ 80%).
        'jt_solo_backdrop_source' => [
            'section' => 'SOLO IMAGE',
            'type'    => 'select',
            'label'   => 'Backdrop Source',
            'default' => 'skin',
            'options' => [
                'skin'  => 'Skin — the animated background shows through',
                'image' => 'Image — your own background image',
            ],
            'hint'    => 'What sits behind the open post. The Scrim (below) tints or covers it.',
        ],
        'jt_solo_backdrop_image' => [
            'section'    => 'SOLO IMAGE',
            'type'       => 'image',
            'label'      => 'Backdrop Image',
            'default'    => '',
            'accept'     => 'image/jpeg,image/png,image/webp',
            'min_width'  => 1920,
            'min_height' => 1080,
            'hint'       => 'Used when Backdrop Source = Image. Minimum 1920×1080px.',
        ],
        'jt_solo_backdrop_image_pos' => [
            'section' => 'SOLO IMAGE',
            'type'    => 'select',
            'label'   => 'Backdrop Image Anchor',
            'default' => 'center',
            'options' => [
                'center' => 'Centre',
                'top'    => 'Snap to top',
                'bottom' => 'Snap to bottom',
            ],
        ],
        'jt_solo_scrim_color' => [
            'section' => 'SOLO IMAGE',
            'type'    => 'color',
            'label'   => 'Scrim Colour',
            'default' => '#000000',
            'hint'    => 'The colour layer over the backdrop. Pair with Scrim Opacity.',
        ],
        'jt_solo_scrim_opacity' => [
            'section'  => 'SOLO IMAGE',
            'type'     => 'range_numeric',
            'label'    => 'Scrim Opacity',
            'default'  => '80',
            'min'      => '0', 'max' => '100', 'step' => '5',
            'unit'     => '%',
            'hint'     => '0 = backdrop fully visible; 100 = solid Scrim Colour behind the photo. Default 80%.',
        ],
        'jt_solo_card_color' => [
            'section' => 'SOLO IMAGE',
            'type'    => 'color',
            'label'   => 'Card Colour',
            'default' => '',
            'hint'    => 'The modal colour around the image and behind the caption panel. Blank = follow Image Page Background.',
        ],
        'jt_solo_pad' => [
            'section'  => 'SOLO IMAGE',
            'type'     => 'range_numeric',
            'label'    => 'Image Padding',
            'default'  => '0',
            'min'      => '0', 'max' => '120', 'step' => '4',
            'unit'     => 'px',
            'hint'     => 'Space around the image inside the card. The caption panel reflows to fit.',
        ],
        'jt_solo_text_color' => [
            'section' => 'SOLO IMAGE',
            'type'    => 'color',
            'label'   => 'Card Text — Primary',
            'default' => '',
            'hint'    => 'Caption, username, EXIF values. Blank = follow Primary Text.',
        ],
        'jt_solo_text_secondary_color' => [
            'section' => 'SOLO IMAGE',
            'type'    => 'color',
            'label'   => 'Card Text — Secondary',
            'default' => '',
            'hint'    => 'EXIF labels, date, meta. Blank = follow Secondary Text.',
        ],

                // ---- TEXT GLOW ---------------------------------------------------
        'jt_glow_color' => [
            'section' => 'TEXT GLOW',
            'type'    => 'color',
            'label'   => 'Text Glow Colour',
            'default' => '#000000',
            'hint'    => 'Halo colour behind title, tagline, and bio. Black for dark glow; white for light glow.',
            // PHP-handled → --profile-text-glow (skin-profile.php).
        ],
        'jt_glow_size' => [
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
        'jt_glow_opacity' => [
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

        // ---- IMAGE FRAME -------------------------------------------------
        'jt_customize_level' => [
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
        'jt_frame_size_pct' => [
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
        'jt_frame_border_px' => [
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
        'jt_frame_border_color' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'color',
            'label'   => 'Border Colour',
            'default' => '#000000',
        ],
        'jt_frame_bg_color' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'color',
            'label'   => 'Frame Background Colour',
            'default' => '#000000',
        ],
        'jt_frame_shadow' => [
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

        // ---- TREATMENT (post-page background treatment) ------------------
        'jt_treatment_mode' => [
            'section' => 'TREATMENT',
            'type'    => 'select',
            'label'   => 'Background Treatment',
            'default' => 'none',
            'options' => [
                'none'  => 'None (jive-turkey background shows through)',
                'image' => 'Background image',
                'color' => 'Solid colour',
            ],
            'hint'    => 'Optional. A treatment sits in front of the jive-turkey layer — leave on None to let the jive-turkey show.',
        ],
        'jt_treatment_image' => [
            'section'    => 'TREATMENT',
            'type'       => 'image',
            'label'      => 'Treatment Image',
            'default'    => '',
            'accept'     => 'image/jpeg,image/png,image/webp',
            'min_width'  => 1920,
            'min_height' => 1080,
            'hint'       => 'Used when Treatment = Background image. Minimum 1920×1080px.',
        ],
        'jt_treatment_position' => [
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
        'jt_treatment_color' => [
            'section' => 'TREATMENT',
            'type'    => 'color',
            'label'   => 'Treatment Colour',
            'default' => '#000000',
            'hint'    => 'Used when Treatment = Solid colour.',
        ],
        'jt_treatment_overlay' => [
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
