<?php
/**
 * SnapSmack - System Inventory
 * Version: 2.3 - Glitch Enable/Disable Control
 * -------------------------------------------------------------------------
 * This file serves as the single source of truth for all available 
 * system resources. Skins "request" assets from this list via their 
 * individual manifest.php files.
 * -------------------------------------------------------------------------
 */

return [
    /* --- TYPOGRAPHY LIBRARY --- */
    'fonts' => [
        'Inter'              => 'Inter (Clean Modern Sans)',
        'Montserrat'         => 'Montserrat (Modern Geometric)',
        'Playfair Display'   => 'Playfair Display (Classy Serif)',
        'Cinzel'             => 'Cinzel (Architectural Serif)',
        'Bebas Neue'         => 'Bebas Neue (Bold Industrial)',
        'Cormorant Garamond' => 'Cormorant Garamond (Fine Serif)',
        'Roboto Mono'        => 'Roboto Mono (Technical Mono)',
        'JetBrains Mono'     => 'JetBrains Mono (Developer Mono)',
        'Oswald'             => 'Oswald (Condensed Bold)',
        'Fira Sans'          => 'Fira Sans (Highly Readable Sans)',
        'Work Sans'          => 'Work Sans (Optimized for Web)',
        'Space Grotesk'      => 'Space Grotesk (Quirky Tech)',
        'Syncopate'          => 'Syncopate (Ultra-Wide Modern)',
        'Michroma'           => 'Michroma (Futuristic/Tech)',
        'Libre Baskerville'  => 'Libre Baskerville (Classic Print Serif)',
        'Source Code Pro'    => 'Source Code Pro (Clean Mono)',
        'Syne'               => 'Syne (Artistic/Bold)',
        'Unbounded'          => 'Unbounded (Wide Variable)',
        'Space Mono'         => 'Space Mono (Retro-Tech)',
        'Archivo Black'      => 'Archivo Black (Heavy Impact)',
        'Fraunces'           => 'Fraunces (Soft Serif)',
        'Stix Two Text'      => 'STIX Two (Scientific Serif)',
        'Outfit'             => 'Outfit (Geometric/Professional)',
        'Plus Jakarta Sans'  => 'Plus Jakarta (Soft Tech)',
        'Lexend'             => 'Lexend (Maximum Readability)',
        'DM Sans'            => 'DM Sans (Classic Professional)',
        'Inconsolata'        => 'Inconsolata (Slab Mono)',
        'Rubik'              => 'Rubik (Friendly Rounded)',
        'Quicksand'          => 'Quicksand (Light/Airy)',
        'Kanit'              => 'Kanit (Thai-Inspired Industrial)'
    ],

    /* --- JAVASCRIPT ENGINE LIBRARY --- */
    'scripts' => [
        'smack-footer' => [
            'label'        => 'SnapSmack Interactive Footer',
            'path'         => 'assets/js/ss-engine-footer.js',
            'has_settings' => false
        ],
        'smack-lightbox' => [
            'label'        => 'Core Lightbox Engine',
            'path'         => 'assets/js/ss-engine-lightbox.js',
            'has_settings' => true,
            'controls'     => [
                'lightbox_opacity' => [
                    'type'    => 'range',
                    'label'   => 'Backdrop Opacity',
                    'default' => '0.8',
                    'min'     => '0.1',
                    'max'     => '1.0',
                    'step'    => '0.1'
                ]
            ]
        ],
        'smack-glitch' => [
            'label'        => 'Visual Glitch FX',
            'path'         => 'assets/js/ss-engine-glitch.js',
            'css'          => 'assets/css/ss-engine-glitch.css',
            'has_settings' => true,
            'controls'     => [
                'glitch_enabled' => [
                    'type'    => 'select',
                    'label'   => 'Chaos Engine',
                    'default' => '1',
                    'options' => [
                        '1' => 'Enabled',
                        '0' => 'Disabled'
                    ]
                ],
                'glitch_intensity' => [
                    'type'    => 'range',
                    'label'   => 'Displacement Intensity',
                    'default' => '10',
                    'min'     => '0',
                    'max'     => '50'
                ],
                'glitch_speed' => [
                    'type'    => 'range',
                    'label'   => 'Refresh Speed (ms)',
                    'default' => '200',
                    'min'     => '50',
                    'max'     => '1000'
                ]
            ]
        ],
        'smack-keyboard' => [
            'label'        => 'Hotkey/Comms Engine',
            'path'         => 'assets/js/ss-engine-comms.js',
            'css'          => 'assets/css/ss-engine-comms.css',
            'has_settings' => false
        ]
    ]
];
