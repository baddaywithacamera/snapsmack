<?php
/**
 * SNAPSMACK - JIVE TURKEY Colourway Registry
 *
 * Data-driven config for the JIVE TURKEY skin's two animation layers. Adding or
 * tuning a 70s colourway is a single entry here — no JS or template changes.
 * skin-profile.php reads the active selection (jt_palette) and emits the full
 * colourway map onto the .jt-jive-turkey-bg carrier; ss-engine-jive-turkey.js
 * (Layer 1 background, all modes + SURPRISE) and ss-engine-jive-border.js
 * (Layer 2 tile borders) consume it from there.
 *
 * Colour tokens are the ONLY place colour lives. Each colourway:
 *   cream  — the field/base the flat 70s graphics sit on
 *   colors — the 3 saturated colourway hues (rays, ribbons, flowers, borders)
 *   centre — the daisy centre (DAISY mode)
 *   dark   — the dark field REELS paints on
 *
 * Returned shape:
 *   ['colourways' => [NAME => ['label'=>..., 'cream'=>hex, 'colors'=>[hex,hex,hex],
 *                              'centre'=>hex, 'dark'=>hex]]]
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

return [
    'colourways' => [
        'BARF' => [
            'label'  => 'BARF — piss yellow / avocado / earth brown',
            'cream'  => '#efe7cf',
            'colors' => ['#c9b23a', '#6e7f39', '#6b4a2a'],
            'centre' => '#c9b23a',
            'dark'   => '#40301c',
        ],
        'BLECH' => [
            'label'  => 'BLECH — purple / burnt orange / shag gold',
            'cream'  => '#efe3cd',
            'colors' => ['#6a3b86', '#dd7328', '#c39a3f'],
            'centre' => '#c39a3f',
            'dark'   => '#33223e',
        ],
        'GROOVY' => [
            'label'  => 'GROOVY — purple / hot pink / blue',
            'cream'  => '#f2e7d6',
            'colors' => ['#7b3f9e', '#e368a4', '#3f7cc4'],
            'centre' => '#e368a4',
            'dark'   => '#2b2340',
        ],
        'HARVEST' => [
            'label'  => 'HARVEST — harvest gold / burnt orange / brown',
            'cream'  => '#f2e2c0',
            'colors' => ['#d99a2b', '#bd4e1f', '#6b3f24'],
            'centre' => '#d99a2b',
            'dark'   => '#38220f',
        ],
    ],
];
// ===== SNAPSMACK EOF =====
