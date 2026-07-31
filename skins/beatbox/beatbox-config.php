<?php
/**
 * SNAPSMACK — BEATBOX Palette & EQ Registry
 * skins/beatbox/beatbox-config.php
 *
 * Data-driven config for the GRAMOFSMACK BEATBOX skin. Colour palettes, EQ band
 * boundaries, and VU thresholds live here and ONLY here — adding or tuning a
 * palette is a single entry, no JS or template change. skin-profile.php reads the
 * active selection and emits these onto the [data-beatbox] carrier as data-bb-*
 * attributes + the matching .bb-pal-<name> class; the three ss-engine-beatbox*.js
 * engines consume them from there.
 *
 * Inactive colours ALWAYS derive from active (~8% luminance) — never set
 * independently (spec). The hex pairs below are the pre-derived inactive values.
 *
 * Returned shape:
 *   [
 *     'palettes'   => [ NAME => ['label'=>.., 'g'=>[on,off],'y'=>[on,off],'r'=>[on,off], 'viz'=>[c,c,c]] ],
 *     'band_hz'    => [[lo,hi] x5]   // highs → bass
 *     'thresholds' => [green, yellow, red]
 *     'defaults'   => [ .. skin-admin defaults, mirrors spec tables .. ]
 *   ]
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

return [
    'palettes' => [
        'classic' => [
            'label' => 'Classic LED — green / gold / red',
            'g' => ['#00FF41', '#003B0F'],
            'y' => ['#FFD700', '#3B2F00'],
            'r' => ['#FF2020', '#3B0000'],
            'viz' => ['#00FF41', '#FFD700', '#FF2020'],
        ],
        'neon' => [
            'label' => 'Neon — cyan / magenta / violet',
            'g' => ['#00E5FF', '#04313A'],
            'y' => ['#FF00E5', '#3A0034'],
            'r' => ['#7C4DFF', '#1B1140'],
            'viz' => ['#00E5FF', '#FF00E5', '#7C4DFF'],
        ],
        'fire' => [
            'label' => 'Fire — gold / orange / red',
            'g' => ['#FFE24D', '#3A3300'],
            'y' => ['#FF8A00', '#3A2000'],
            'r' => ['#FF1E1E', '#3A0000'],
            'viz' => ['#FFE24D', '#FF8A00', '#FF1E1E'],
        ],
        'ice' => [
            'label' => 'Ice — pale cyan / cyan / blue',
            'g' => ['#CFFAFE', '#0B3A40'],
            'y' => ['#67E8F9', '#083A44'],
            'r' => ['#3B82F6', '#0A1E4A'],
            'viz' => ['#CFFAFE', '#67E8F9', '#3B82F6'],
        ],
    ],

    // Starting values — a real-music tuning pass across genres is required during
    // build (spec open question). highs, hi-mid, mid, lo-mid, bass.
    'band_hz' => [[4000, 20000], [800, 4000], [250, 800], [80, 250], [20, 80]],

    // VU segment thresholds: green / yellow / red (spec Tile Segment Logic).
    'thresholds' => [0.25, 0.55, 0.80],

    // Skin-admin defaults — mirror the spec's settings tables.
    'defaults' => [
        'intensity'         => 3,      // 0..10
        'intensity_cap'     => 10,     // owner cap 0..10
        'palette'           => 'classic',
        'border_width'      => 2,      // px, 0..4
        'bg_mode'           => 'off',  // off | viz | collage | both
        'viz_mode'          => 'spectrum',
        'viz_intensity'     => 3,
        'reaction_mode'     => 'simultaneous',
        'ripple_speed'      => 'medium',
        'collage_opacity'   => 15,     // %, 5..40
        'collage_sat'       => 20,     // %, 0..50
        'scale_on_hit'      => 105,    // %, 100..115
        'loop'              => 1,
        'player_position'   => 'bottom',
        'tile_gap'          => 3,      // px, 2..12
        'bg_color'          => '#111111',
    ],
];
// ===== SNAPSMACK EOF =====
