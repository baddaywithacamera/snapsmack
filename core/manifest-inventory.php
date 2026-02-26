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
    /* --- LOCAL FONT LIBRARY ---
       Fonts hosted in assets/fonts/ on this server.
       These are output as @font-face blocks in meta.php for every public page.
       Add new local fonts here — they become available everywhere automatically.
    */
    'local_fonts' => [
        'BlackCasper' => [
            'label'  => 'BlackCasper (Physical Damage)',
            'file'   => 'assets/fonts/blackcasper.regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
    ],

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
        'Kanit'              => 'Kanit (Thai-Inspired Industrial)',
        'Stalinist One'      => 'Stalinist One (Soviet Propaganda)'
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
        ],
        'smack-logo' => [
            'label'        => 'Logo Glitch Engine',
            'path'         => 'assets/js/ss-engine-logo.js',
            'css'          => 'assets/css/ss-engine-logo.css',
            'has_settings' => true,
            'controls'     => [
                'logo_glitch_enabled' => ['type' => 'select', 'label' => 'Logo Glitch', 'default' => '1', 'options' => ['1' => 'Enabled', '0' => 'Disabled']],
                'logo_frequency'      => ['type' => 'select', 'label' => 'Frequency', 'default' => 'normal', 'options' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'chaos' => 'Chaos']],
                'logo_split_position' => ['type' => 'range',  'label' => 'Colour Split Position (%)', 'default' => '50', 'min' => '10', 'max' => '90'],
                'logo_split_drift'    => ['type' => 'select', 'label' => 'Split Drift on Hit', 'default' => '1', 'options' => ['1' => 'Enabled', '0' => 'Disabled']],
                'logo_font_blackcasper' => ['type' => 'select', 'label' => 'BlackCasper Hits', 'default' => '1', 'options' => ['1' => 'Enabled', '0' => 'Disabled']],
                'logo_font_courier'   => ['type' => 'select', 'label' => 'Courier Intrusion Hits', 'default' => '1', 'options' => ['1' => 'Enabled', '0' => 'Disabled']],
            ]
        ],
        'smack-pimpotron' => [
            'label'        => 'Pimpotron Sequencer',
            'path'         => 'assets/js/ss-engine-pimpotron.js',
            'css'          => 'assets/css/ss-engine-pimpotron.css',
            'has_settings' => true,
            'controls'     => [
                'pimpotron_slideshow_id' => [
                    'type'    => 'number',
                    'label'   => 'Slideshow ID',
                    'default' => '1'
                ],
                'pimpotron_stage_id' => [
                    'type'    => 'text',
                    'label'   => 'Stage Element ID',
                    'default' => 'pimpotron-sequencer'
                ]
            ]
        ]
    ]
];
