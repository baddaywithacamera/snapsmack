<?php
/**
 * SNAPSMACK - PARADE Skin Manifest
 * v1.0.4
 *
 * Desktop GRAMOFSMACK skin — AURORA's high-key twin. A classic 3-across
 * square-tile grid (The Grid's proven column architecture) over a single
 * animation layer:
 *   Layer 1 — slow-motion particle FIREWORKS on a high-key white field,
 *             painted in the identity-flag palette the admin picks
 *             (assets/js/ss-engine-parade-fireworks.js + skin-profile.php).
 * Built as a real show of support: the photographer flies the flag that
 * represents them or their community. The photography is always the content;
 * the fireworks are always the atmosphere.
 *
 * Desktop-only: declared incompatible with mobile/tablet. PHOTOGRAM handles
 * mobile. Palette/background options are data-driven via parade-config.php.
 * Layer 2 (tile border-wave) is intentionally deferred (spec v0.2 §8) — clean
 * tiles for the Layer-1 sign-off.
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
    'name'        => 'PARADE',
    'version'     => '1.2.5',
    'author'      => 'Sean McCormick',
    'support'     => 'sean@baddaywithacamera.ca',
    'description' => 'High-key desktop skin — AURORA\'s daylight twin. A classic 3-across square grid over slow-motion fireworks on a bright white field, painted in the identity-flag palette you choose. A real show of support, built so the photos are still why you came.',
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
        // Shared Grid-family chrome engines + PARADE's own fireworks engine
        // (all registered in core/manifest-inventory.php, all in /assets/js):
        'smack-grid-modal',
        'smack-grid-lightbox',
        'smack-grid-nav',
        'smack-parade-fireworks',
        'smack-aurora-wave',      // shared prefix-derived tile-border WAVE (Layer 2)
        'smack-aurora-reveal',    // shared prefix-derived progressive grid reveal (lazy)
        'smack-thomas',           // Thomas the Bear Easter egg — required in every fork
    ],

    'community_comments'  => true,
    'community_likes'     => true,
    'community_reactions' => false,

    'options' => [

        // ---- PARADE (Layer 1 — fireworks atmosphere) -----------------------
        'pa_palette' => [
            'section' => 'PARADE',
            'type'    => 'select',
            'label'   => 'Flag Palette',
            'default' => 'rainbow',
            'options' => [
                'rainbow'    => 'Rainbow — six-stripe Pride',
                'progress'   => 'Progress Pride — rainbow + chevron',
                'trans'      => 'Trans — blue / pink / white',
                'bi'         => 'Bisexual — magenta / purple / blue',
                'nonbinary'  => 'Non-Binary — yellow / white / purple / black',
                'two-spirit' => 'Two-Spirit — rainbow base',
            ],
            'hint'    => 'The flag the fireworks are painted in. Each burst samples across the whole flag. Palettes are defined in parade-config.php.',
            // PHP-handled: emitted as data-pa-palette on .pa-parade-bg by skin-profile.php.
        ],
        'pa_background' => [
            'section' => 'PARADE',
            'type'    => 'select',
            'label'   => 'Background',
            'default' => 'warm',
            'options' => [
                'white' => 'Pure white',
                'soft'  => 'Soft white',
                'warm'  => 'Warm white',
                'wash'  => 'Palette wash (faint tint of the active flag)',
            ],
            'hint'    => 'High-key only — palette-matched presets, never a generic colour. The field shows through the fading fireworks trails.',
            // PHP-handled → --pa-bg (skin-profile.php).
        ],
        'pa_bg_mode' => [
            'section' => 'PARADE',
            'type'    => 'select',
            'label'   => 'Background Mode',
            'default' => 'fireworks',
            'options' => [
                'fireworks' => 'Fireworks (default)',
                'flag'      => 'Waving Flag (full-screen)',
            ],
            'hint'    => 'Fireworks paints the chosen Flag Palette as slow-motion fireworks. Waving Flag flies that same flag full-screen behind the grid. Mutually exclusive — only one engine loads.',
            // PHP-handled: skin-profile.php emits the matching carrier; skin-footer.php loads only the chosen engine.
        ],
        'pa_flag_speed' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Flag — Wave Speed',
            'default'  => '30', 'min' => '1', 'max' => '100', 'step' => '1',
            'unit'      => '',
            'show_when' => ['pa_bg_mode' => 'flag'],
            'hint'     => 'Waving Flag mode only. Slow drift → active ripple.',
        ],
        'pa_flag_amplitude' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Flag — Wave Amplitude',
            'default'  => '40', 'min' => '1', 'max' => '100', 'step' => '1',
            'unit'      => '',
            'show_when' => ['pa_bg_mode' => 'flag'],
            'hint'     => 'Waving Flag mode only. How deep the ripples run.',
        ],
        'pa_flag_opacity' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Flag — Opacity',
            'default'  => '100', 'min' => '0', 'max' => '100', 'step' => '5',
            'unit'      => '%',
            'show_when' => ['pa_bg_mode' => 'flag'],
            'hint'     => 'Waving Flag mode only. Default full/bold; dial back if your photos need it.',
        ],
        'pa_rate' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Busyness (launches)',
            // Prototype unit: slider 1–40, ÷3 = launches/sec. Default 8 ≈ 2.7/s.
            'default'  => '8',
            'min'      => '1',
            'max'      => '40',
            'step'     => '1',
            'unit'      => '',
            'show_when' => ['pa_bg_mode' => 'fireworks'],
            'hint'     => 'Rocket launch rate (slider ÷ 3 = launches per second). Real-time, independent of the slow-motion.',
            // PHP-handled → data-pa-rate.
        ],
        'pa_explode' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Explosion speed',
            // Prototype unit: slider 3–150, ÷100 = burst-sim speed. Default 21 = 0.21×.
            'default'  => '21',
            'min'      => '3',
            'max'      => '150',
            'step'     => '1',
            'unit'      => '',
            'show_when' => ['pa_bg_mode' => 'fireworks'],
            'hint'     => 'Speed of the drifting burst particles. Lower = bursts hang in the air; higher = livelier.',
            // PHP-handled → data-pa-explode.
        ],
        'pa_intensity' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Burst intensity (particles)',
            'default'  => '105',
            'min'      => '20',
            'max'      => '300',
            'step'     => '5',
            'unit'     => 'particles',
            'show_when' => ['pa_bg_mode' => 'fireworks'],
            'hint'     => 'Particles per burst.',
            // PHP-handled → data-pa-intensity.
        ],
        'pa_soft' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Softness (pastel)',
            'default'  => '100',
            'min'      => '0',
            'max'      => '100',
            'step'     => '1',
            'unit'     => '%',
            'show_when' => ['pa_bg_mode' => 'fireworks'],
            'hint'     => 'How far the flag colours are softened toward pastel so they read against white. Flag hues stay true.',
            // PHP-handled → data-pa-soft.
        ],

        // ---- FIREWORKS — FINE TUNING --------------------------------------
        'pa_spread' => [
            'section'  => 'FIREWORKS DETAIL',
            'type'     => 'range_numeric',
            'label'    => 'Burst size (spread)',
            // Prototype unit: slider 10–120, ÷1000 = burst radius. Default 45 = 0.045.
            'default'  => '45',
            'min'      => '10',
            'max'      => '120',
            'step'     => '1',
            'unit'      => '',
            'show_when' => ['pa_bg_mode' => 'fireworks'],
            'hint'     => 'How wide each burst opens.',
            // PHP-handled → data-pa-spread.
        ],
        'pa_launch' => [
            'section'  => 'FIREWORKS DETAIL',
            'type'     => 'range_numeric',
            'label'    => 'Launch speed',
            // Prototype unit: slider 5–150, ÷100 = rocket-rise speed. Default 32 = 0.32×.
            'default'  => '32',
            'min'      => '5',
            'max'      => '150',
            'step'     => '1',
            'unit'      => '',
            'show_when' => ['pa_bg_mode' => 'fireworks'],
            'hint'     => 'How fast rockets rise before they burst.',
            // PHP-handled → data-pa-launch.
        ],
        'pa_streamer' => [
            'section'  => 'FIREWORKS DETAIL',
            'type'     => 'range_numeric',
            'label'    => 'Streamer width',
            // Prototype unit: slider 2–40, ÷10 = streamer width ×. Default 4 = 0.4×.
            'default'  => '4',
            'min'      => '2',
            'max'      => '40',
            'step'     => '1',
            'unit'      => '',
            'show_when' => ['pa_bg_mode' => 'fireworks'],
            'hint'     => 'Thickness of the particle trails.',
            // PHP-handled → data-pa-streamer.
        ],

        // ---- BORDER WAVE (Layer 2 — the waving flag on tile borders) -------
        'pa_border_style' => [
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
            'hint'    => 'How the flag colour travels around the tile borders. Black/brown flag stops are lifted toward grey so the border never goes hard black.',
            // PHP-handled → data-pa-border-style (ss-engine-aurora-wave.js).
        ],
        'pa_border_dir' => [
            'section' => 'BORDER WAVE',
            'type'    => 'select',
            'label'   => 'Wave Direction',
            'default' => 'dtlbr',
            'options' => [
                'dtlbr' => 'Diagonal down-right',
                'dbrtl' => 'Diagonal up-left',
                'ltr'   => 'Left to right',
                'rtl'   => 'Right to left',
                'ttb'   => 'Top to bottom',
                'btt'   => 'Bottom to top',
            ],
        ],
        'pa_border_rhythm' => [
            'section' => 'BORDER WAVE',
            'type'    => 'select',
            'label'   => 'Wave Rhythm',
            'default' => 'breath',
            'options' => [
                'breath'   => 'Breathe — slow / fast / slow',
                'constant' => 'Constant slow',
            ],
        ],
        'pa_border_width' => [
            'section'  => 'BORDER WAVE',
            'type'     => 'range_numeric',
            'label'    => 'Border Width',
            'default'  => '5',
            'min'      => '1',
            'max'      => '10',
            'step'     => '1',
            'unit'     => 'px',
        ],
        'pa_border_opacity' => [
            'section'  => 'BORDER WAVE',
            'type'     => 'range_numeric',
            'label'    => 'Border Opacity',
            'default'  => '100',
            'min'      => '10',
            'max'      => '100',
            'step'     => '5',
            'unit'     => '%',
        ],
        'pa_tile_corners' => [
            'section' => 'BORDER WAVE',
            'type'    => 'select',
            'label'   => 'Tile Corners',
            'default' => 'auto',
            'options' => [
                'auto'    => 'Round with thickness',
                'square'  => 'Square',
                'rounded' => 'Rounded',
            ],
        ],

        // ---- NAV (dual 1px divider lines) ----------------------------------
        'pa_nav_line_mode' => [
            'section' => 'NAV',
            'type'    => 'select',
            'label'   => 'Nav Divider Lines',
            'default' => 'track',
            'options' => [
                'track' => 'Track the flag colour (live)',
                'fixed' => 'Fixed colour',
            ],
            'hint'    => 'The menu is bracketed by dual 1px lines. Track rides the live border-wave colour; Fixed uses the colour below.',
        ],
        'pa_nav_line_color' => [
            'section'  => 'NAV',
            'type'     => 'color',
            'label'    => 'Nav Line Colour (fixed mode)',
            'default'  => '#750787',
            // PHP-handled → --pa-nav-line when mode = fixed.
        ],

        // ---- PROFILE HEADER ------------------------------------------------
        'pa_profile_header' => [
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
        'pa_show_tagline' => [
            'section'  => 'PROFILE HEADER',
            'type'     => 'select',
            'label'    => 'Show Tagline (Site Description)',
            'default'  => '1',
            'options'  => ['1' => 'Show', '0' => 'Hide'],
        ],

        // ---- TEXT (legibility over the bright field — default DARK) --------
        'pa_text_color' => [
            'section'  => 'TEXT',
            'type'     => 'color',
            'label'    => 'Primary Text Colour',
            'default'  => '#1a1a1a',
            // PHP-handled → --pa-text (skin-profile.php).
        ],
        'pa_muted_color' => [
            'section'  => 'TEXT',
            'type'     => 'color',
            'label'    => 'Muted Text Colour',
            'default'  => '#5b5b66',
            // PHP-handled → --pa-muted (skin-profile.php).
        ],
        'pa_accent_color' => [
            'section'  => 'TEXT',
            'type'     => 'color',
            'label'    => 'Accent Colour (links / active nav)',
            'default'  => '#750787',
            // PHP-handled → --pa-accent (skin-profile.php).
        ],

        // ════════════════════════════════════════════════════════════════════
        //  RESTORED FROM AURORA — PARADE is AURORA minus the background engine
        //  and the flag palette, so it carries the same content/layout/type
        //  controls. High-key defaults where AURORA ran dark. (Re-added after a
        //  prior fork stripped them from the manifest UI.)
        // ════════════════════════════════════════════════════════════════════

        // ---- GRID ----------------------------------------------------------
        'pa_gap' => [
            'section'  => 'GRID',
            'type'     => 'range_numeric',
            'label'    => 'Image Gap',
            'default'  => '2', 'min' => '0', 'max' => '20', 'step' => '1', 'unit' => 'px',
            'selector' => ':root', 'property' => '--grid-gap',
        ],
        'pa_carousel_indicator' => [
            'section'  => 'GRID',
            'type'     => 'select',
            'label'    => 'Carousel Indicator Style',
            'default'  => 'icon',
            'options'  => ['icon' => 'Layered squares icon', 'count' => 'Image count badge', 'none' => 'No indicator'],
        ],
        'pa_hover_overlay' => [
            'section'  => 'GRID',
            'type'     => 'select',
            'label'    => 'Hover Overlay',
            'default'  => 'dark',
            'options'  => ['dark' => 'Darken only', 'title' => 'Show post title', 'count' => 'Show image count', 'none' => 'No overlay'],
        ],

        // ---- IMAGE FRAME ---------------------------------------------------
        'pa_customize_level' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'select',
            'label'   => 'Customisation Level',
            'default' => 'per_grid',
            'options' => ['per_grid' => 'Site-wide (one style for all images)', 'per_carousel' => 'Per Post (each post defines its own style)', 'per_image' => 'Per Image (each photo has its own style)'],
        ],
        'pa_frame_size_pct' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'select',
            'label'   => 'Image Size Within Tile',
            'default' => '100',
            'options' => ['100' => '100% — edge to edge', '95' => '95%', '90' => '90%', '85' => '85%', '80' => '80%', '75' => '75%'],
        ],
        'pa_frame_border_px' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'select',
            'label'   => 'Border Thickness',
            'default' => '0',
            'options' => ['0' => 'None', '1' => '1px', '2' => '2px', '3' => '3px', '5' => '5px', '8' => '8px', '10' => '10px', '15' => '15px', '20' => '20px'],
        ],
        'pa_frame_border_color' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'color',
            'label'   => 'Border Colour',
            'default' => '#ffffff',
        ],
        'pa_frame_bg_color' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'color',
            'label'   => 'Frame Background Colour',
            'default' => '#ffffff',
        ],
        'pa_frame_shadow' => [
            'section' => 'IMAGE FRAME',
            'type'    => 'select',
            'label'   => 'Drop Shadow on Image',
            'default' => '0',
            'options' => ['0' => 'None', '1' => 'Soft', '2' => 'Medium', '3' => 'Heavy'],
        ],

        // ---- LAYOUT --------------------------------------------------------
        'pa_max_width' => [
            'section'  => 'LAYOUT',
            'type'     => 'range_numeric',
            'label'    => 'Grid Max Width',
            'default'  => '935', 'min' => '600', 'max' => '1600', 'step' => '5', 'unit' => 'px',
            'selector' => ':root', 'property' => '--grid-max-width',
        ],

        // ---- TITLE & TAGLINE -----------------------------------------------
        'pa_blog_title_font' => [
            'section' => 'TITLE & TAGLINE', 'type' => 'select', 'label' => 'Blog Title Font',
            'default' => 'inherit', 'options' => array_merge(['inherit' => 'Same as Body Font'], $fonts),
            'selector' => ':root', 'property' => '--blog-title-font', 'is_font' => true, 'no_size_slider' => true,
        ],
        'pa_blog_title_size' => [
            'section' => 'TITLE & TAGLINE', 'type' => 'range_numeric', 'label' => 'Blog Title Size',
            'default' => '20', 'min' => '12', 'max' => '48', 'step' => '1', 'unit' => 'px',
            'selector' => ':root', 'property' => '--blog-title-size',
        ],
        'pa_blog_title_weight' => [
            'section' => 'TITLE & TAGLINE', 'type' => 'select', 'label' => 'Blog Title Weight',
            'default' => '600', 'options' => ['300' => 'Light', '400' => 'Regular', '500' => 'Medium', '600' => 'Semibold', '700' => 'Bold'],
            'selector' => ':root', 'property' => '--blog-title-weight',
        ],
        'pa_blog_title_color' => [
            'section' => 'TITLE & TAGLINE', 'type' => 'color', 'label' => 'Blog Title Colour',
            'default' => '#1a1a1a', 'selector' => ':root', 'property' => '--blog-title-color',
        ],
        'pa_tagline_font' => [
            'section' => 'TITLE & TAGLINE', 'type' => 'select', 'label' => 'Tagline Font',
            'default' => 'inherit', 'options' => array_merge(['inherit' => 'Same as Body Font'], $fonts),
            'selector' => ':root', 'property' => '--tagline-font', 'is_font' => true, 'no_size_slider' => true,
        ],
        'pa_tagline_size' => [
            'section' => 'TITLE & TAGLINE', 'type' => 'range_numeric', 'label' => 'Tagline Size',
            'default' => '16', 'min' => '10', 'max' => '36', 'step' => '1', 'unit' => 'px',
            'selector' => ':root', 'property' => '--tagline-size',
        ],
        'pa_tagline_weight' => [
            'section' => 'TITLE & TAGLINE', 'type' => 'select', 'label' => 'Tagline Weight',
            'default' => '400', 'options' => ['300' => 'Light', '400' => 'Regular', '500' => 'Medium', '600' => 'Semibold', '700' => 'Bold'],
            'selector' => ':root', 'property' => '--tagline-weight',
        ],
        'pa_tagline_color' => [
            'section' => 'TITLE & TAGLINE', 'type' => 'color', 'label' => 'Tagline Colour',
            'default' => '#5b5b66', 'selector' => ':root', 'property' => '--tagline-color',
        ],
        'pa_bio_size' => [
            'section' => 'TITLE & TAGLINE', 'type' => 'range_numeric', 'label' => 'Description / Bio Size',
            'default' => '14', 'min' => '10', 'max' => '28', 'step' => '1', 'unit' => 'px',
            'selector' => ':root', 'property' => '--bio-size',
        ],

        // ---- TEXT GLOW (legibility over the bright field) ------------------
        'pa_glow_color' => [
            'section' => 'TEXT GLOW', 'type' => 'color', 'label' => 'Text Glow Colour',
            'default' => '#750787',
            'hint'    => 'Halo behind title/tagline/bio. Pick a colour that CONTRASTS with the bright field — a white glow is invisible on PARADE\'s high-key background.',
            // PHP-handled → --profile-text-glow (skin-profile.php).
        ],
        'pa_glow_size' => [
            'section' => 'TEXT GLOW', 'type' => 'range_numeric', 'label' => 'Text Glow Size',
            'default' => '0', 'min' => '0', 'max' => '40', 'step' => '2', 'unit' => 'px',
            'hint'    => '0 = no glow.',
        ],
        'pa_glow_opacity' => [
            'section' => 'TEXT GLOW', 'type' => 'range_numeric', 'label' => 'Text Glow Opacity',
            'default' => '0', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
        ],

        // ---- MENU / NAV (font, case, link colour, glow) -------------------
        'pa_nav_case' => [
            'section' => 'NAV', 'type' => 'select', 'label' => 'Nav Link Case',
            'default' => 'none',
            'options' => ['none' => 'As typed', 'uppercase' => 'ALL CAPS', 'capitalize' => 'First Letter', 'lowercase' => 'all lowercase'],
            'selector' => ':root', 'property' => '--nav-text-transform',
        ],
        'pa_nav_font' => [
            'section' => 'NAV', 'type' => 'select', 'label' => 'Nav Font',
            'default' => 'inherit', 'options' => array_merge(['inherit' => 'Same as Body Font'], $fonts),
            'selector' => ':root', 'property' => '--nav-font', 'is_font' => true, 'no_size_slider' => true,
        ],
        'pa_nav_color' => [
            'section' => 'NAV', 'type' => 'color', 'label' => 'Nav Link Colour',
            'default' => '#5b5b66', 'selector' => ':root', 'property' => '--nav-color',
        ],
        'pa_nav_line_opacity' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Nav Line Opacity',
            'default' => '100', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
            // PHP-handled → --nav-line-opacity (skin-profile.php).
        ],
        'pa_nav_glow_color' => [
            'section' => 'NAV', 'type' => 'color', 'label' => 'Nav Glow Colour',
            'default' => '#750787',
            'hint'    => 'Outer glow behind the menu links.',
            // PHP-handled → --nav-text-glow (skin-profile.php).
        ],
        'pa_nav_glow_size' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Nav Glow Size',
            'default' => '0', 'min' => '0', 'max' => '40', 'step' => '2', 'unit' => 'px',
            'hint'    => '0 = no glow.',
        ],
        'pa_nav_glow_opacity' => [
            'section' => 'NAV', 'type' => 'range_numeric', 'label' => 'Nav Glow Opacity',
            'default' => '45', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
        ],

        // ---- FOOTER (text glow) -------------------------------------------
        'pa_footer_glow_color' => [
            'section' => 'FOOTER', 'type' => 'color', 'label' => 'Footer Glow Colour',
            'default' => '#750787',
            'hint'    => 'Outer glow behind the footer text.',
            // PHP-handled → --footer-text-glow (skin-profile.php).
        ],
        'pa_footer_glow_size' => [
            'section' => 'FOOTER', 'type' => 'range_numeric', 'label' => 'Footer Glow Size',
            'default' => '0', 'min' => '0', 'max' => '40', 'step' => '2', 'unit' => 'px',
            'hint'    => '0 = no glow.',
        ],
        'pa_footer_glow_opacity' => [
            'section' => 'FOOTER', 'type' => 'range_numeric', 'label' => 'Footer Glow Opacity',
            'default' => '0', 'min' => '0', 'max' => '100', 'step' => '5', 'unit' => '%',
        ],

        // ---- COLOURS -------------------------------------------------------
        'pa_post_bg_color' => [
            'section' => 'COLOURS', 'type' => 'color', 'label' => 'Image Page Background',
            'default' => '#ffffff', 'selector' => ':root', 'property' => '--post-bg',
        ],
        'pa_border_color' => [
            'section' => 'COLOURS', 'type' => 'color', 'label' => 'Border / Divider Colour',
            'default' => '#e2e2e2', 'selector' => ':root', 'property' => '--border-color',
        ],
        'pa_bio_color' => [
            'section' => 'COLOURS', 'type' => 'color', 'label' => 'Description / Bio Text',
            'default' => '#5b5b66', 'selector' => ':root', 'property' => '--bio-color',
        ],

        // ---- TYPOGRAPHY ----------------------------------------------------
        'pa_font_body' => [
            'section' => 'TYPOGRAPHY', 'type' => 'select', 'label' => 'Body / UI Font',
            'default' => '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
            'options' => array_merge(['"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif' => 'System Default (Segoe UI / Roboto)'], $fonts),
            'selector' => ':root', 'property' => '--font-body',
        ],

        // ---- TREATMENT (full-page background behind a centred content card) -
        'pa_gutter' => [
            'section' => 'TREATMENT', 'type' => 'range_numeric', 'label' => 'Side Gutter (margin over background)',
            'default' => '0', 'min' => '0', 'max' => '400', 'step' => '4', 'unit' => 'px',
            'selector' => ':root', 'property' => '--grid-gutter',
        ],
        'pa_treatment_mode' => [
            'section' => 'TREATMENT', 'type' => 'select', 'label' => 'Background Treatment',
            'default' => 'none',
            'options' => ['none' => 'None (fireworks field shows through)', 'image' => 'Background image', 'color' => 'Solid colour'],
            'hint'    => 'Optional. Sits in front of the fireworks layer — leave on None to let the flag fireworks show.',
        ],
        'pa_treatment_image' => [
            'section' => 'TREATMENT', 'type' => 'image', 'label' => 'Treatment Image',
            'default' => '', 'accept' => 'image/jpeg,image/png,image/webp',
            'min_width' => 1920, 'min_height' => 1080,
            'hint'    => 'Used when Treatment = Background image. Minimum 1920×1080px.',
        ],
        'pa_treatment_position' => [
            'section' => 'TREATMENT', 'type' => 'select', 'label' => 'Image Anchor (when it overshoots)',
            'default' => 'center',
            'options' => ['center' => 'Centre', 'top' => 'Snap to top', 'bottom' => 'Snap to bottom'],
        ],
        'pa_treatment_color' => [
            'section' => 'TREATMENT', 'type' => 'color', 'label' => 'Treatment Colour',
            'default' => '#ffffff', 'hint' => 'Used when Treatment = Solid colour.',
        ],
        'pa_treatment_overlay' => [
            'section' => 'TREATMENT', 'type' => 'range_numeric', 'label' => 'Overlay  (left darkens · right lightens)',
            'default' => '0', 'min' => '-100', 'max' => '100', 'step' => '5', 'unit' => '%',
            'hint'    => 'Centre = none.',
        ],
    ],
];
// ===== SNAPSMACK EOF =====
