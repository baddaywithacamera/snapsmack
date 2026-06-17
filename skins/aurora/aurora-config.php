<?php
/**
 * SNAPSMACK - AURORA Palette & Sky Registry
 *
 * Data-driven config for the AURORA skin's two animation layers. Adding a new
 * aurora-family palette or sky base is a single entry here — no JS or template
 * changes. skin-profile.php reads the active selection (au_palette / au_sky)
 * and emits it onto the .au-aurora-bg element; aurora-wave.js + the Layer-1 CSS
 * consume it from there.
 *
 * Returned shape:
 *   ['palettes' => [slug => ['label'=>..., 'colors'=>[hex,...]]],
 *    'skies'    => [hex => label]]
 *
 * Colour cycles are written so the last stop returns to (or harmonises with)
 * the first, keeping the breathing background seamless.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

return [
    'palettes' => [
        'aurora' => [
            'label'  => 'Aurora — green-dominant, teal/blue/yellow/red flares',
            // From the canonical prototype (_spec/aurora-prototype.html): green
            // dominates (every other stop), with brief teal/blue/yellow/red flares
            // that always return to green. The engine HSL-interpolates between
            // stops, so this reads as a living aurora, not a flat colour wheel.
            'colors' => [
                '#56e86a', '#7df06a', '#56e86a', '#2fe6a0', '#56e86a', '#39b6f0',
                '#56e86a', '#9bf25a', '#56e86a', '#2f7fe0', '#56e86a', '#f2d24a',
                '#56e86a', '#ff5566', '#56e86a', '#46c0c0',
            ],
        ],
        'borealis-ice' => [
            'label'  => 'Borealis Ice — cool greens & blues',
            'colors' => ['#7CFFCB', '#00cec9', '#4899f0', '#9bd7ff', '#7CFFCB'],
        ],
        'solar' => [
            'label'  => 'Solar Storm — green into red aurora',
            'colors' => ['#61e96e', '#d6f15a', '#f0b429', '#f0653e', '#d6336c', '#61e96e'],
        ],
    ],
    'skies' => [
        '#000000' => 'Deep black',
        '#0a0a1a' => 'Deep navy',
        '#0d0d2b' => 'Deep indigo',
    ],
];
// ===== SNAPSMACK EOF =====
