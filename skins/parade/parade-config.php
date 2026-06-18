<?php
/**
 * SNAPSMACK - PARADE Palette & Background Registry
 *
 * Data-driven config for the PARADE skin. Adding a flag palette or a high-key
 * background is a single entry here — no JS or template changes. skin-profile.php
 * reads the active selection (pa_palette / pa_bg) and emits it onto the
 * .pa-parade-bg element; ss-engine-parade-fireworks.js (Layer 1) and the reused
 * ss-engine-aurora-wave.js (Layer 2 tile borders) consume it from there.
 *
 * PARADE is high-key: white/warm default, flag palettes rendered soft on a bright
 * field. NO hue-rotate — flag colours stay true (HSL interpolation in the engine).
 *
 * Returned shape:
 *   ['palettes'    => [slug => ['label'=>..., 'colors'=>[hex,...]]],
 *    'backgrounds' => [key  => ['label'=>..., 'css'=>...]]]
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

return [
    'palettes' => [
        'rainbow' => [
            'label'  => 'Rainbow — six-stripe Pride',
            'colors' => ['#e40303', '#ff8c00', '#ffed00', '#008026', '#004dff', '#750787'],
        ],
        'progress' => [
            'label'  => 'Progress Pride — rainbow + chevron',
            'colors' => ['#e40303', '#ff8c00', '#ffed00', '#008026', '#004dff', '#750787', '#ffafc7', '#74d7ee', '#613915', '#000000'],
        ],
        'trans' => [
            'label'  => 'Trans — blue / pink / white',
            'colors' => ['#55cdfc', '#f7a8b8', '#ffffff', '#f7a8b8', '#55cdfc'],
        ],
        'bi' => [
            'label'  => 'Bisexual — magenta / purple / blue',
            'colors' => ['#d60270', '#9b4f96', '#0038a8'],
        ],
        'nonbinary' => [
            'label'  => 'Non-Binary — yellow / white / purple / black',
            'colors' => ['#fcf434', '#ffffff', '#9c59d1', '#2c2c2c'],
        ],
        'two-spirit' => [
            'label'  => 'Two-Spirit — rainbow base',
            'colors' => ['#e40303', '#ff8c00', '#ffed00', '#008026', '#004dff', '#750787'],
        ],
    ],
    // High-key backgrounds only — palette-matched presets, NEVER a generic picker.
    // 'wash' resolves to a faint tint of the active palette in skin-profile.php.
    'backgrounds' => [
        'warm'  => ['label' => 'Warm white',   'css' => '#fffdf6'],
        'white' => ['label' => 'Pure white',   'css' => '#ffffff'],
        'soft'  => ['label' => 'Soft white',   'css' => '#f7f6fb'],
        'wash'  => ['label' => 'Palette wash', 'css' => ''],
    ],
];
// ===== SNAPSMACK EOF =====
