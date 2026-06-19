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
    'version'     => '1.0.4',
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
        'pa_rate' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Busyness (launches)',
            // Prototype unit: slider 1–40, ÷3 = launches/sec. Default 8 ≈ 2.7/s.
            'default'  => '8',
            'min'      => '1',
            'max'      => '40',
            'step'     => '1',
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
    ],
];
// ===== SNAPSMACK EOF =====
