<?php
/**
 * SNAPSMACK - INSTANT CAMERA Skin Manifest
 *
 * A skew of The Grid for instant-film photographers (Polaroid, Instax, …).
 * GRAMOFSMACK 3-across grid + trigrams, but the tile aspect is CONFIGURABLE to
 * match the user's print format (not square) and the scanned print is shown
 * UNCROPPED — the baked-in border IS the presentation; the skin adds only a
 * drop shadow. Default background is ORGANIZED MAYHEM (ambient, behind a white
 * scrim). Demo: fauxlaroid.fyi. Spec: _spec/instant-camera-spec-v0.2.docx.
 *
 * Cloned from The Grid (which is locked); keeps the tg- class namespace + shared
 * Grid engines, diverging via this manifest, skin-profile.php and style.css.
 * Activates the GramOfSmack posting interface (post_page => gram).
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
    'name'        => 'INSTANT CAMERA',
    'version'     => '1.0.22',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'For instant-film photographers. A GRAMOFSMACK 3-across grid whose tile aspect you set to match your format (Polaroid, Instax Mini/Wide/Square, or custom) so prints show UNCROPPED — the scanned border is the frame, the skin just adds a drop shadow. Drifting Organized Mayhem tabletop behind a white scrim. Prints on a table, which is exactly what instant photography is.',
    'status'      => 'beta',
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
        'smack-organized-mayhem', // ambient drifting-tabletop background (behind the scrim)
        'smack-progressive-reveal',  // shared grow-as-you-scroll (grid tiles + justified rows)
        'smack-tag-infinite',     // shared prefix-derived hashtag infinite scroll
        'smack-image-fade-load',  // reveals lightbox/asset images on static pages
                                  // (public-base.css sets img[data-lightbox-src]{opacity:0};
                                  //  this engine fades them to opacity:1 on load)
        'smack-lightbox',         // click-to-zoom for content/asset images
                                  // (img[data-lightbox-src] + cursor:zoom-in)
        'smack-gram-search',      // bottom-left magnifier → expanding search dock
    ],

    'community_comments'  => true,
    'community_likes'     => true,
    'community_reactions' => false,

    'options' => [

        // ---- INSTANT CAMERA (format + background) ---------------------------
        'ic_format' => [
            'section' => 'INSTANT CAMERA',
            'type'    => 'select',
            'label'   => 'Print Format (tile aspect)',
            'default' => 'instax_square',
            'options' => [
                'polaroid'      => 'Polaroid 600 / OneStep — portrait',
                'sx70'          => 'Polaroid SX-70 — square image',
                'go'            => 'Polaroid Go — portrait',
                'instax_mini'   => 'Instax Mini — landscape',
                'instax_wide'   => 'Instax Wide — wide landscape',
                'instax_square' => 'Instax Square — near-square',
                'custom'        => 'Custom (set ratio below)',
            ],
            'hint'    => 'Sets the tile aspect to match your print so scans show uncropped. The trigram cover is derived automatically (3 wide × 1 tall).',
            // PHP-handled: skin-profile.php maps this to --ic-tile-aspect.
        ],
        'ic_custom_ratio' => [
            'section' => 'INSTANT CAMERA',
            'type'    => 'text',
            'label'   => 'Custom Ratio (width:height)',
            'default' => '1:1',
            'hint'    => 'Used only when Print Format = Custom. e.g. 79:97 (portrait) or 3:2 (landscape).',
        ],
        'ic_bg_mode' => [
            'section' => 'INSTANT CAMERA',
            'type'    => 'select',
            'label'   => 'Background',
            'default' => 'mayhem',
            'options' => [
                'mayhem'    => 'Organized Mayhem (drifting tabletop)',
                'racetrack' => 'RACETRACK (photos drifting past each other)',
                'rainfall'  => 'RAINFALL (rain on the window)',
                'static'    => 'Static image (set under Treatment)',
                'cycle'     => 'Cycle all (timed crossfade)',
            ],
            'hint'    => 'Organized Mayhem scatters your photos on a slow-drifting table behind the grid. RACETRACK sends those same photos gliding past each other in every direction, depth-layered like Frogger traffic; RAINFALL streaks rain down the glass. Static uses the Treatment background image below. Cycle all rotates through every background on a timer with a 2-second crossfade. Single modes are mutually exclusive — only the chosen engine loads.',
            // PHP-handled: skin-profile.php emits the matching carrier(s); skin-footer.php loads the chosen engine(s).
        ],
        'ic_cycle_secs' => [
            'section'   => 'INSTANT CAMERA',
            'type'      => 'range_numeric',
            'label'     => 'Cycle — Seconds Per Background',
            'default'   => '15', 'min' => '5', 'max' => '60', 'step' => '1',
            'unit'      => 's',
            'show_when' => ['ic_bg_mode' => 'cycle'],
            'hint'      => 'Cycle mode only. How long each background holds before the 2-second crossfade to the next. The Static image is included as a stop when you have set a Treatment image below.',
        ],

        // ---- RACETRACK (background engine — MAYHEM photos, Frogger drift) ---
        'ic_rt_speed' => [
            'section'   => 'RACETRACK',
            'type'      => 'range_numeric',
            'label'     => 'Drift Speed',
            'default'   => '40', 'min' => '1', 'max' => '100', 'step' => '1',
            'unit'      => '',
            'show_when' => ['ic_bg_mode' => 'racetrack'],
            'hint'      => 'RACETRACK mode only. Slow cruise → full send.',
        ],
        'ic_rt_count' => [
            'section'   => 'RACETRACK',
            'type'      => 'range_numeric',
            'label'     => 'Photos on Screen',
            'default'   => '14', 'min' => '3', 'max' => '40', 'step' => '1',
            'unit'      => '',
            'show_when' => ['ic_bg_mode' => 'racetrack'],
            'hint'      => 'How many of your photos drift past each other at once.',
        ],
        'ic_rt_size' => [
            'section'   => 'RACETRACK',
            'type'      => 'range_numeric',
            'label'     => 'Photo Size',
            'default'   => '180', 'min' => '60', 'max' => '400', 'step' => '10',
            'unit'      => 'px',
            'show_when' => ['ic_bg_mode' => 'racetrack'],
            'hint'      => 'Base print width. Nearer photos scale up from here, farther ones down — the depth layering.',
        ],
        'ic_rt_opacity' => [
            'section'   => 'RACETRACK',
            'type'      => 'range_numeric',
            'label'     => 'Opacity',
            'default'   => '70', 'min' => '5', 'max' => '100', 'step' => '5',
            'unit'      => '%',
            'show_when' => ['ic_bg_mode' => 'racetrack'],
            'hint'      => 'The white scrim above still applies — dial both for taste.',
        ],

        // ---- RAINFALL (background engine — rain on the window) --------------
        'ic_rf_density' => [
            'section'   => 'RAINFALL',
            'type'      => 'range_numeric',
            'label'     => 'Rain Density',
            'default'   => '45', 'min' => '1', 'max' => '100', 'step' => '1',
            'unit'      => '',
            'show_when' => ['ic_bg_mode' => 'rainfall'],
            'hint'      => 'Drizzle → downpour.',
        ],
        'ic_rf_speed' => [
            'section'   => 'RAINFALL',
            'type'      => 'range_numeric',
            'label'     => 'Fall Speed',
            'default'   => '50', 'min' => '1', 'max' => '100', 'step' => '1',
            'unit'      => '',
            'show_when' => ['ic_bg_mode' => 'rainfall'],
        ],
        'ic_rf_angle' => [
            'section'   => 'RAINFALL',
            'type'      => 'range_numeric',
            'label'     => 'Wind Angle',
            'default'   => '-12', 'min' => '-45', 'max' => '45', 'step' => '1',
            'unit'      => '°',
            'show_when' => ['ic_bg_mode' => 'rainfall'],
            'hint'      => '0 = straight down. Negative leans left, positive leans right.',
        ],
        'ic_rf_thickness' => [
            'section'   => 'RAINFALL',
            'type'      => 'range_numeric',
            'label'     => 'Drop Thickness',
            'default'   => '2', 'min' => '1', 'max' => '8', 'step' => '1',
            'unit'      => 'px',
            'show_when' => ['ic_bg_mode' => 'rainfall'],
        ],
        'ic_rf_color' => [
            'section'   => 'RAINFALL',
            'type'      => 'color',
            'label'     => 'Rain Colour',
            'default'   => '#6fa8dc',
            'show_when' => ['ic_bg_mode' => 'rainfall'],
        ],
        'ic_rf_opacity' => [
            'section'   => 'RAINFALL',
            'type'      => 'range_numeric',
            'label'     => 'Rain Opacity',
            'default'   => '50', 'min' => '5', 'max' => '100', 'step' => '5',
            'unit'      => '%',
            'show_when' => ['ic_bg_mode' => 'rainfall'],
            'hint'      => 'The white scrim above still applies — dial both for taste.',
        ],
        'ic_scrim' => [
            'section'  => 'INSTANT CAMERA',
            'type'     => 'range_numeric',
            'label'    => 'White Scrim Opacity',
            'default'  => '60',
            'min'      => '10',
            'max'      => '90',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'White layer between the background and the grid. 10% = background bold; 90% = prints float on near-white. PHP-handled → --ic-scrim.',
        ],

        // ---- TEXT GLOW (readability over the drifting tabletop) -------------
        // Ported from AURORA / PARADE. Halos behind the profile text and the nav
        // links so they stay legible over the Organized Mayhem background.
        'ic_glow_color' => [
            'section' => 'TEXT GLOW',
            'type'    => 'color',
            'label'   => 'Text Glow Colour',
            'default' => '#000000',
            'hint'    => 'Halo behind the profile title, tagline, and bio. Black for a dark halo; white for a light one. PHP-handled → --profile-text-glow.',
        ],
        'ic_glow_size' => [
            'section'  => 'TEXT GLOW',
            'type'     => 'range_numeric',
            'label'    => 'Text Glow Size',
            'default'  => '0',
            'min'      => '0',
            'max'      => '40',
            'step'     => '2',
            'unit'     => 'px',
            'hint'     => '0 = use the built-in readability halo. Increase for a wider custom halo.',
        ],
        'ic_glow_opacity' => [
            'section'  => 'TEXT GLOW',
            'type'     => 'range_numeric',
            'label'    => 'Text Glow Opacity',
            'default'  => '0',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'Applies to the title + tagline. The bio has its own control below.',
        ],

        // ---- BIO GLOW (independent of the title/tagline Text Glow) ----------
        // Separate halo for the bio line ONLY, so you can glow the title/tagline
        // and leave the bio clean (or vice-versa). 0/0 = no glow on the bio.
        'ic_bio_glow_color' => [
            'section' => 'BIO GLOW',
            'type'    => 'color',
            'label'   => 'Bio Glow Colour',
            'default' => '#000000',
            'hint'    => 'Halo behind the bio text only. PHP-handled → --bio-text-glow.',
        ],
        'ic_bio_glow_size' => [
            'section'  => 'BIO GLOW',
            'type'     => 'range_numeric',
            'label'    => 'Bio Glow Size',
            'default'  => '0',
            'min'      => '0', 'max' => '40', 'step' => '2', 'unit' => 'px',
            'hint'     => '0 = no glow on the bio.',
        ],
        'ic_bio_glow_opacity' => [
            'section'  => 'BIO GLOW',
            'type'     => 'range_numeric',
            'label'    => 'Bio Glow Opacity',
            'default'  => '0',
            'min'      => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
        ],
        'ic_nav_glow_color' => [
            'section' => 'TEXT GLOW',
            'type'    => 'color',
            'label'   => 'Nav Glow Colour',
            'default' => '#000000',
            'hint'    => 'Outer glow behind the menu links (Home / Blogroll / pages). PHP-handled → --nav-text-glow.',
        ],
        'ic_nav_glow_size' => [
            'section'  => 'TEXT GLOW',
            'type'     => 'range_numeric',
            'label'    => 'Nav Glow Size',
            'default'  => '0',
            'min'      => '0',
            'max'      => '40',
            'step'     => '2',
            'unit'     => 'px',
            'hint'     => '0 = no nav glow.',
        ],
        'ic_nav_glow_opacity' => [
            'section'  => 'TEXT GLOW',
            'type'     => 'range_numeric',
            'label'    => 'Nav Glow Opacity',
            'default'  => '45',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
        ],

        // ---- PANEL (all pages) ---------------------------------------------
        // ONE backing panel behind the content column on EVERY page — landing,
        // About/static, archive, hashtag. Tinted backing so text + prints stay
        // readable over the animated Organized Mayhem background. Full content
        // width, reaches the top, bleeds out by Extend each side. Colour +
        // opacity + extend admin-tunable. (Replaces the old separate Landing
        // Panel / Page Panel sets — one control to rule them all.)
        'ic_panel_color' => [
            'section' => 'PANEL',
            'type'    => 'color',
            'label'   => 'Panel Colour',
            'default' => '#ffffff',
            'hint'    => 'Backing colour behind the content column on every page so it reads over the moving background. PHP-handled → --panel-bg.',
        ],
        'ic_panel_opacity' => [
            'section'  => 'PANEL',
            'type'     => 'range_numeric',
            'label'    => 'Panel Opacity',
            'default'  => '0',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => '0 = transparent (the tabletop shows straight through). Raise it until text + prints are comfortable to read.',
        ],
        'ic_panel_extend' => [
            'section'  => 'PANEL',
            'type'     => 'range_numeric',
            'label'    => 'Panel Extend (gutters)',
            'default'  => '0',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => 'px',
            'hint'     => 'How far the panel bleeds out past the content each side. 0 = flush, 100 = 100px gutters.',
        ],

        // ---- SOLO PAGE -----------------------------------------------------
        // Backdrop behind the print on the single-post (solo) view. Defaults to
        // solid black; drop the opacity to let the Organized Mayhem tabletop
        // show through behind the photo.
        'ic_solo_bg_color' => [
            'section' => 'SOLO PAGE',
            'type'    => 'color',
            'label'   => 'Solo Background Colour',
            'default' => '#000000',
            'hint'    => 'Backdrop colour behind the photo on the single-post view. PHP-handled → --post-bg.',
        ],
        'ic_solo_bg_opacity' => [
            'section'  => 'SOLO PAGE',
            'type'     => 'range_numeric',
            'label'    => 'Solo Background Opacity',
            'default'  => '100',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => '100 = solid. Lower it to let the drifting tabletop show through behind the print.',
        ],

        // ---- STICKY NAVBAR -------------------------------------------------
        // The sticky nav floats over the tabletop. Transparent by default (the
        // tabletop shows through); dial opacity up for a solid bar.
        'ic_nav_color' => [
            'section' => 'NAVBAR',
            'type'    => 'color',
            'label'   => 'Navbar Colour',
            'default' => '#ffffff',
            'hint'    => 'Background colour of the sticky nav bar. PHP-handled → --ic-nav-bg.',
        ],
        'ic_nav_opacity' => [
            'section'  => 'NAVBAR',
            'type'     => 'range_numeric',
            'label'    => 'Navbar Opacity — Landing',
            'default'  => '0',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'Landing page only. 0 = fully transparent (the tabletop shows through). Raise it for a solid bar.',
        ],
        'ic_nav_opacity_inner' => [
            'section'  => 'NAVBAR',
            'type'     => 'range_numeric',
            'label'    => 'Navbar Opacity — Other Pages',
            'default'  => '',
            'min'      => '0',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'Archive / post / static pages, where content sits under the bar and usually wants it more solid than the landing hero. Leave blank to match the Landing value.',
        ],

        // ---- "POSTS" LABEL -------------------------------------------------
        'ic_posts_color' => [
            'section' => 'POSTS LABEL',
            'type'    => 'color',
            'label'   => 'Posts Label Colour',
            'default' => '#777777',
            'hint'    => 'Colour of the "posts" count word in the profile header. PHP-handled → --posts-color.',
        ],
        'ic_posts_glow_color' => [
            'section' => 'POSTS LABEL',
            'type'    => 'color',
            'label'   => 'Posts Glow Colour',
            'default' => '#000000',
        ],
        'ic_posts_glow_size' => [
            'section'  => 'POSTS LABEL',
            'type'     => 'range_numeric',
            'label'    => 'Posts Glow Size',
            'default'  => '0',
            'min'      => '0', 'max' => '40', 'step' => '2', 'unit' => 'px',
            'hint'     => '0 = no glow.',
        ],
        'ic_posts_glow_opacity' => [
            'section'  => 'POSTS LABEL',
            'type'     => 'range_numeric',
            'label'    => 'Posts Glow Opacity',
            'default'  => '0',
            'min'      => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
        ],

        // ---- NAV LINES -----------------------------------------------------
        'ic_navline_color' => [
            'section' => 'NAV LINES',
            'type'    => 'color',
            'label'   => 'Nav Line Colour',
            'default' => '#e0e0e0',
            'hint'    => 'Colour of the nav divider line. PHP-handled → --ic-navline-color.',
        ],
        'ic_navline_opacity' => [
            'section'  => 'NAV LINES',
            'type'     => 'range_numeric',
            'label'    => 'Nav Line Opacity',
            'default'  => '100',
            'min'      => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
            'hint'     => 'Opacity of the nav divider line. PHP-handled → --ic-navline-opacity.',
        ],
        'ic_navline_shadow_color' => [
            'section' => 'NAV LINES',
            'type'    => 'color',
            'label'   => 'Nav Line Shadow Colour',
            'default' => '#000000',
        ],
        'ic_navline_shadow_size' => [
            'section'  => 'NAV LINES',
            'type'     => 'range_numeric',
            'label'    => 'Nav Line Shadow Size',
            'default'  => '0',
            'min'      => '0', 'max' => '3', 'step' => '1', 'unit' => 'px',
            'hint'     => '0 = no shadow. Capped at 3px, always cast down-and-right for consistency.',
        ],
        'ic_navline_shadow_opacity' => [
            'section'  => 'NAV LINES',
            'type'     => 'range_numeric',
            'label'    => 'Nav Line Shadow Opacity',
            'default'  => '40',
            'min'      => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
        ],

        // ---- GRID APPEARANCE -----------------------------------------------
        'ic_gap' => [
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
        'ic_bg_color' => [
            'section'  => 'GRID',
            'type'     => 'color',
            'label'    => 'Grid Background / Gap Colour',
            'default'  => '#ffffff',
            'selector' => ':root',
            'property' => '--grid-bg',
        ],
        // (Tile Border Radius control removed — INSTANT CAMERA is square-cornered;
        //  style.css hardcodes --tile-radius:0 so the print edges stay sharp.)
        'ic_carousel_indicator' => [
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
        'ic_hover_overlay' => [
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
        'ic_profile_header' => [
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
        'ic_blog_title_font' => [
            'section'       => 'PROFILE HEADER',
            'type'          => 'select',
            'label'         => 'Blog Title Font',
            'default'       => 'inherit',
            'options'       => array_merge(['inherit' => 'Same as Body Font'], $fonts),
            'selector'      => ':root',
            'property'      => '--blog-title-font',
            'is_font'       => true,   // enables preview block + per-option font-family style
            'no_size_slider'=> true,   // suppress built-in size slider; ic_blog_title_size handles it
        ],
        'ic_blog_title_size' => [
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
        'ic_blog_title_weight' => [
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
        'ic_show_tagline' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Show Tagline (Site Description)',
            'default'  => '1',
            'options'  => ['1' => 'Show', '0' => 'Hide'],
        ],
        'ic_tagline_font' => [
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
        'ic_tagline_size' => [
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
        'ic_tagline_weight' => [
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
        'ic_tagline_color' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'color',
            'label'    => 'Tagline Colour',
            'default'  => '#8e8e8e',
            'selector' => ':root',
            'property' => '--tagline-color',
        ],
        'ic_nav_case' => [
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
        'ic_post_bg_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Image Page Background',
            'default'  => '#000000',
            'selector' => ':root',
            'property' => '--post-bg',
        ],
        'ic_bg_primary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Page Background',
            'default'  => '#ffffff',
            'selector' => ':root',
            'property' => '--bg-primary',
        ],
        'ic_text_primary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Primary Text',
            'default'  => '#262626',
            'selector' => ':root',
            'property' => '--text-primary',
        ],
        'ic_text_secondary' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Secondary Text',
            'default'  => '#8e8e8e',
            'selector' => ':root',
            'property' => '--text-secondary',
        ],
        'ic_bio_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Description / Bio Text',
            'default'  => '#8e8e8e',
            'selector' => ':root',
            'property' => '--bio-color',
            'hint'     => 'Colour of the bio paragraph under the profile. Independent of Secondary Text (matches AURORA/PARADE).',
        ],
        'ic_accent' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Accent / Link Colour',
            'default'  => '#0095f6',
            'selector' => ':root',
            'property' => '--accent-color',
        ],
        'ic_border_color' => [
            'section'  => 'COLOURS',
            'type'     => 'color',
            'label'    => 'Border / Divider Colour',
            'default'  => '#dbdbdb',
            'selector' => ':root',
            'property' => '--border-color',
        ],

        // ---- TYPOGRAPHY ----------------------------------------------------
        'ic_font_body' => [
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
        // ic_customize_level controls which style resolution path is active.
        //   per_grid     — one style for all images, set here in Skin Admin.
        //   per_carousel — each post defines its own style (stored on snap_posts).
        //   per_image    — each photo has its own style (stored on snap_post_images).
        'ic_customize_level' => [
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
        'ic_frame_size_pct' => [
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
        'ic_frame_border_px' => [
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
        'ic_frame_border_color' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'color',
            'label'   => 'Border Colour',
            'default' => '#000000',
        ],
        'ic_frame_bg_color' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'color',
            'label'   => 'Frame Background Colour',
            'default' => '#ffffff',
        ],
        'ic_frame_shadow' => [
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
        'ic_max_width' => [
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
        'ic_gutter' => [
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
        'ic_treatment_mode' => [
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
        'ic_treatment_image' => [
            'section'    => 'TREATMENT',
            'type'       => 'image',
            'label'      => 'Treatment Image',
            'default'    => '',
            'accept'     => 'image/jpeg,image/png,image/webp',
            'min_width'  => 1920,
            'min_height' => 1080,
            'hint'       => 'Used when Treatment = Background image. Minimum 1920×1080px.',
        ],
        'ic_treatment_position' => [
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
        'ic_treatment_color' => [
            'section' => 'TREATMENT',
            'type'    => 'color',
            'label'   => 'Treatment Colour',
            'default' => '#ffffff',
            'hint'    => 'Used when Treatment = Solid colour.',
        ],
        'ic_treatment_overlay' => [
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

        // ---- SEARCH DOCK (bottom-left magnifier) ---------------------------
        // Shared keys read by core/gram-search-dock.php. DISC = the round pill
        // behind the magnifier; GLASS = the magnifier icon. Colour + opacity so
        // the dock can be matched to the background.
        'gsd_disc_color' => [
            'section' => 'SEARCH DOCK',
            'type'    => 'color',
            'label'   => 'Dock Circle Colour',
            'default' => '#ffffff',
            'hint'    => 'Colour of the round dock behind the magnifier. PHP-handled → --gsd-disc-bg.',
        ],
        'gsd_disc_opacity' => [
            'section'  => 'SEARCH DOCK',
            'type'     => 'range_numeric',
            'label'    => 'Dock Circle Opacity',
            'default'  => '100', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
            'hint'     => 'Transparency of the dock circle so it can blend into the background.',
        ],
        'gsd_glass_color' => [
            'section' => 'SEARCH DOCK',
            'type'    => 'color',
            'label'   => 'Dock Magnifier Colour',
            'default' => '#262626',
            'hint'    => 'Colour of the magnifying-glass icon. PHP-handled → --gsd-glass-color.',
        ],

        // ---- FOOTER (bar background — colour + user opacity slider) ---------
        // Shared keys read by core/footer.php → --footer-bg. Lets the footer bar
        // be dialled independently of Panel Opacity. Defaults reproduce the
        // current footer look; the opacity slider is the user control.
        'footer_bg_color' => [
            'section' => 'FOOTER',
            'type'    => 'color',
            'label'   => 'Footer Bar Colour',
            'default' => '#ffffff',
            'hint'    => 'Background colour of the footer bar. PHP-handled → --footer-bg.',
        ],
        'footer_bg_opacity' => [
            'section'  => 'FOOTER',
            'type'     => 'range_numeric',
            'label'    => 'Footer Bar Opacity',
            'default'  => '65', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
            'hint'     => 'Transparency of the footer bar so it can be matched to the background — independent of Panel Opacity.',
        ],

    ],
];
// ===== SNAPSMACK EOF =====
