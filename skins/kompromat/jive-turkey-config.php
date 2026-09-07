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
        'OH THAT SUCKS' => [
            'label'  => 'OH THAT SUCKS — blood red / bone / gold',
            'cream'  => '#efe3cd',
            'colors' => ['#c0201a', '#7d1618', '#e8c14f'],
            'centre' => '#e8c14f',
            'dark'   => '#5a0f0c',
        ],
        'UGH' => [
            'label'  => 'UGH — oxblood / cream / brass',
            'cream'  => '#e8dcc0',
            'colors' => ['#8b1a1a', '#5c1010', '#c9a227'],
            'centre' => '#c9a227',
            'dark'   => '#2a0a08',
        ],
    ],
];
// ===== SNAPSMACK EOF =====
