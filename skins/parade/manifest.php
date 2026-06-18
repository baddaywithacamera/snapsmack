<?php
/**
 * SNAPSMACK - PARADE Skin Manifest
 * v1.0.0
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
    'version'     => '1.0.0',
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
            'default' => 'white',
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
            'label'    => 'Busyness',
            // Integer launches/sec — the admin range widget is integer-only.
            'default'  => '3',
            'min'      => '1',
            'max'      => '8',
            'step'     => '1',
            'unit'     => '/s',
            'hint'     => 'How many rockets launch per second. Real-time, so a busy sky is independent of the slow-motion.',
            // PHP-handled → data-pa-rate.
        ],
        'pa_explode' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Motion (slow-motion)',
            // Integer PERCENT; skin-profile.php divides by 100 → burst-sim clock.
            'default'  => '18',
            'min'      => '5',
            'max'      => '100',
            'step'     => '1',
            'unit'     => '%',
            'hint'     => 'Speed of the drifting particles. Lower = dreamier near-freeze; higher = livelier.',
            // PHP-handled → data-pa-explode.
        ],
        'pa_intensity' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Burst Size',
            'default'  => '74',
            'min'      => '20',
            'max'      => '160',
            'step'     => '2',
            'unit'     => 'particles',
            'hint'     => 'Particles per burst.',
            // PHP-handled → data-pa-intensity.
        ],
        'pa_soft' => [
            'section'  => 'PARADE',
            'type'     => 'range_numeric',
            'label'    => 'Softness (pastel)',
            'default'  => '84',
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
            'label'    => 'Burst Spread',
            // Integer; skin-profile.php divides by 1000 → burst radius.
            'default'  => '45',
            'min'      => '10',
            'max'      => '120',
            'step'     => '5',
            'hint'     => 'How wide each burst opens.',
            // PHP-handled → data-pa-spread.
        ],
        'pa_launch' => [
            'section'  => 'FIREWORKS DETAIL',
            'type'     => 'range_numeric',
            'label'    => 'Rocket Speed',
            'default'  => '60',
            'min'      => '20',
            'max'      => '120',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'How fast rockets rise before they burst.',
            // PHP-handled → data-pa-launch.
        ],
        'pa_streamer' => [
            'section'  => 'FIREWORKS DETAIL',
            'type'     => 'range_numeric',
            'label'    => 'Streamer Width',
            'default'  => '100',
            'min'      => '30',
            'max'      => '250',
            'step'     => '5',
            'unit'     => '%',
            'hint'     => 'Thickness of the particle trails.',
            // PHP-handled → data-pa-streamer.
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
