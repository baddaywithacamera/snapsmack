<?php
/**
 * SNAPSMACK - PARADE Palette & Background Registry
 *
 * Data-driven config for the PARADE skin. Adding a flag palette or a high-key
 * background is a single entry here — no JS or template changes. skin-profile.php
 * reads the active selection (pa_palette / pa_bg) and emits it onto the
 * .pa-parade-bg element; the shared flag and tile-border engines consume it
 * from there.
 *
 * PARADE is high-key: white/warm default beneath the full-screen flag.
 * NO hue-rotate — flag colours stay true.
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
        'pan' => [
            'label'  => 'Pansexual — pink / yellow / blue',
            'colors' => ['#ff218c', '#ffd800', '#21b1ff'],
        ],
        'lesbian' => [
            'label'  => 'Lesbian — orange / white / pink',
            'colors' => ['#d52d00', '#ff9a56', '#ffffff', '#d362a4', '#a30262'],
        ],
        'asexual' => [
            'label'  => 'Asexual — black / grey / white / purple',
            'colors' => ['#000000', '#a3a3a3', '#ffffff', '#800080'],
        ],
        'aromantic' => [
            'label'  => 'Aromantic — green / white / grey / black',
            'colors' => ['#3da542', '#a7d379', '#ffffff', '#a9a9a9', '#000000'],
        ],
        'genderfluid' => [
            'label'  => 'Genderfluid — pink / white / purple / black / blue',
            'colors' => ['#ff76a4', '#ffffff', '#c011d7', '#000000', '#2f3cbe'],
        ],
        'genderqueer' => [
            'label'  => 'Genderqueer — lavender / white / green',
            'colors' => ['#b57edc', '#ffffff', '#4a8123'],
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
