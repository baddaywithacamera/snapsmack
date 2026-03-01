<?php
/**
 * SNAPSMACK - System Inventory
 * Version: 2026.2 - Expanded Font Library (DotMatrix + 90 Google Fonts)
 * Last changed: 2026-02-26
 * -------------------------------------------------------------------------
 * Single source of truth for all available system resources.
 * Skins "request" assets from this list via their individual manifest.php.
 *
 * LOCAL FONTS: Declared in local_fonts[]. Output as @font-face blocks
 *              automatically. Paths relative to site root.
 *              Skin manifests may declare allowed_fonts[] to restrict
 *              the font picker to a curated subset (e.g. impact-printer).
 *
 * GOOGLE FONTS: Declared in fonts[]. Loaded on demand via Google CDN.
 *               Key   = exact Google Fonts family name (used in API URL).
 *               Value = friendly label shown in the font picker UI.
 *               Grouped by category for maintainability.
 *               NOTE: Inter and Roboto deliberately excluded — they are
 *               everywhere and add nothing distinctive to a photoblog.
 * -------------------------------------------------------------------------
 */

return [

    /* =========================================================
       LOCAL FONT LIBRARY
       Fonts hosted in assets/fonts/ on this server.
       DotMatrix fonts are for the impact-printer skin only —
       use allowed_fonts in that manifest to restrict the picker.
       ========================================================= */
    'local_fonts' => [

        // ---- BlackCasper ------------------------------------------------
        'BlackCasper' => [
            'label'  => 'BlackCasper (Physical Damage)',
            'file'   => 'assets/fonts/blackcasper.regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- DotMatrix Core ---------------------------------------------
        'DotMatrix' => [
            'label'  => 'DotMatrix (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Bold' => [
            'label'  => 'DotMatrix (Bold)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Bold.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'normal',
        ],
        'DotMatrix-Italic' => [
            'label'  => 'DotMatrix (Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Italic.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'italic',
        ],
        'DotMatrix-BoldItalic' => [
            'label'  => 'DotMatrix (Bold Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-BoldItalic.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'italic',
        ],

        // ---- DotMatrix Condensed ----------------------------------------
        'DotMatrix-Condensed' => [
            'label'  => 'DotMatrix Condensed (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Condensed.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Condensed-Bold' => [
            'label'  => 'DotMatrix Condensed (Bold)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Condensed-Bold.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'normal',
        ],
        'DotMatrix-Condensed-Italic' => [
            'label'  => 'DotMatrix Condensed (Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Condensed-Italic.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'italic',
        ],
        'DotMatrix-Condensed-BoldItalic' => [
            'label'  => 'DotMatrix Condensed (Bold Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Condensed-BoldItalic.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'italic',
        ],

        // ---- DotMatrix Expanded -----------------------------------------
        'DotMatrix-Expanded' => [
            'label'  => 'DotMatrix Expanded (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Expanded.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Expanded-Bold' => [
            'label'  => 'DotMatrix Expanded (Bold)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Expanded-Bold.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'normal',
        ],
        'DotMatrix-Expanded-Italic' => [
            'label'  => 'DotMatrix Expanded (Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Expanded-Italic.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'italic',
        ],
        'DotMatrix-Expanded-BoldItalic' => [
            'label'  => 'DotMatrix Expanded (Bold Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Expanded-BoldItalic.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'italic',
        ],

        // ---- DotMatrix Quad ---------------------------------------------
        'DotMatrix-Quad' => [
            'label'  => 'DotMatrix Quad (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Quad.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Quad-Bold' => [
            'label'  => 'DotMatrix Quad (Bold)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Quad-Bold.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'normal',
        ],
        'DotMatrix-Quad-Italic' => [
            'label'  => 'DotMatrix Quad (Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Quad-Italic.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'italic',
        ],
        'DotMatrix-Quad-BoldItalic' => [
            'label'  => 'DotMatrix Quad (Bold Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Quad-BoldItalic.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'italic',
        ],

        // ---- DotMatrix Variable -----------------------------------------
        'DotMatrix-Var' => [
            'label'  => 'DotMatrix Var (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Var.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Var-Condensed' => [
            'label'  => 'DotMatrix Var (Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Var-Condensed.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Var-Expanded' => [
            'label'  => 'DotMatrix Var (Expanded)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Var-Expanded.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Var-UltraCondensed' => [
            'label'  => 'DotMatrix Var (Ultra Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Var-UltraCondensed.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- DotMatrix VarDuo -------------------------------------------
        'DotMatrix-VarDuo' => [
            'label'  => 'DotMatrix VarDuo (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-VarDuo.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-VarDuo-Condensed' => [
            'label'  => 'DotMatrix VarDuo (Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-VarDuo-Condensed.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-VarDuo-Expanded' => [
            'label'  => 'DotMatrix VarDuo (Expanded)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-VarDuo-Expanded.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-VarDuo-UltraCondensed' => [
            'label'  => 'DotMatrix VarDuo (Ultra Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-VarDuo-UltraCondensed.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- DotMatrix Duo ----------------------------------------------
        'DotMatrix-Duo' => [
            'label'  => 'DotMatrix Duo (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Duo.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Duo-Condensed' => [
            'label'  => 'DotMatrix Duo (Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Duo-Condensed.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Duo-Expanded' => [
            'label'  => 'DotMatrix Duo (Expanded)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Duo-Expanded.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Duo-UltraCondensed' => [
            'label'  => 'DotMatrix Duo (Ultra Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-Duo-UltraCondensed.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

    ],


    /* =========================================================
       GOOGLE FONTS LIBRARY
       ~90 fonts across 7 categories.
       Inter and Roboto deliberately omitted — ubiquitous and
       actively harmful to a distinctive photoblog aesthetic.
       ========================================================= */
    'fonts' => [

        // ----------------------------------------------------------------
        // SANS-SERIF: UI & BODY — clean, legible, screen-optimised
        // Best for: navigation, descriptions, body copy, metadata labels
        // ----------------------------------------------------------------

        'DM Sans'               => 'DM Sans (Geometric — Picasa UI body)',
        'Figtree'               => 'Figtree (Friendly Geometric — contemporary)',
        'Outfit'                => 'Outfit (Geometric / Professional)',
        'Plus Jakarta Sans'     => 'Plus Jakarta Sans (Soft Contemporary)',
        'Nunito'                => 'Nunito (Rounded / Approachable)',
        'Nunito Sans'           => 'Nunito Sans (Rounded / Slightly firmer)',
        'Rubik'                 => 'Rubik (Friendly Rounded)',
        'Lexend'                => 'Lexend (Maximum Readability / Dyslexia-aware)',
        'Work Sans'             => 'Work Sans (Optimised for Screens)',
        'Fira Sans'             => 'Fira Sans (Highly Readable / Mozilla DNA)',
        'Lato'                  => 'Lato (Warm & Professional / Summer)',
        'Manrope'               => 'Manrope (Refined Contemporary)',
        'Albert Sans'           => 'Albert Sans (Scandinavian Geometric)',
        'Onest'                 => 'Onest (Humanist / Very Screen-Readable)',
        'IBM Plex Sans'         => 'IBM Plex Sans (Neutral Grotesk / IBM DNA)',
        'Public Sans'           => 'Public Sans (US Web Design System / Precise)',
        'Quicksand'             => 'Quicksand (Light / Airy / Rounded)',
        'Poppins'               => 'Poppins (Geometric / Friendly Headlines)',
        'Jost'                  => 'Jost (Futura-Inspired / Very Clean)',
        'Open Sans'             => 'Open Sans (Humanist Workhorse / Neutral)',

        // ----------------------------------------------------------------
        // SANS-SERIF: DISPLAY & EDITORIAL — strong personality at scale
        // Best for: photo titles, site name, section headers
        // ----------------------------------------------------------------

        'Raleway'               => 'Raleway (Elegant Display — Picasa titles)',
        'Montserrat'            => 'Montserrat (Urban Geometric / Buenos Aires DNA)',
        'Oswald'                => 'Oswald (Condensed Gothic / Newspaper energy)',
        'Bebas Neue'            => 'Bebas Neue (Bold Industrial / All Caps only)',
        'Archivo Black'         => 'Archivo Black (Heavy Impact)',
        'Anton'                 => 'Anton (Advertising Bold / Commanding presence)',
        'Barlow'                => 'Barlow (Grotesk / California highway DNA)',
        'Barlow Condensed'      => 'Barlow Condensed (Tight / High impact headlines)',
        'Syne'                  => 'Syne (Artistic / Paris design-school vibe)',
        'Unbounded'             => 'Unbounded (Wide Variable / Future-facing)',
        'Syncopate'             => 'Syncopate (Ultra-Wide / Architectural)',
        'Krona One'             => 'Krona One (Scandinavian Retro / Poster type)',
        'Big Shoulders Display' => 'Big Shoulders Display (Compressed / Industrial Chicago)',
        'Exo 2'                 => 'Exo 2 (Sci-Fi / Technical sans)',
        'Rajdhani'              => 'Rajdhani (South Asian Geometric / Angular)',
        'Kanit'                 => 'Kanit (Thai-Inspired Industrial)',
        'Saira'                 => 'Saira (Condensed / Latin American origins)',
        'Saira Condensed'       => 'Saira Condensed (Very Tight / Extreme headline)',

        // ----------------------------------------------------------------
        // SANS-SERIF: TECH & QUIRKY — distinct character, use deliberately
        // Best for: sci-fi skins, retro-tech, hacker aesthetic
        // ----------------------------------------------------------------

        'Space Grotesk'         => 'Space Grotesk (Quirky Tech / Space Mono cousin)',
        'Michroma'              => 'Michroma (Futuristic / Hard geometric edges)',
        'Orbitron'              => 'Orbitron (Pure Sci-Fi / Use boldly or not at all)',
        'Chakra Petch'          => 'Chakra Petch (Square / Tapered corners / Thai+Latin)',
        'Oxanium'               => 'Oxanium (Geometric / Gaming aesthetic)',
        'Audiowide'             => 'Audiowide (Electronic / Smooth circuit feel)',
        'Share Tech Mono'       => 'Share Tech Mono (Terminal / Hacker aesthetic)',

        // ----------------------------------------------------------------
        // SERIF: EDITORIAL & LITERARY — authority, elegance, tradition
        // Best for: literary skins, editorial layouts, formal photo titles
        // ----------------------------------------------------------------

        'Playfair Display'      => 'Playfair Display (High Contrast / Enlightenment elegance)',
        'Cormorant Garamond'    => 'Cormorant Garamond (Fine Serif / Pairs with Cinzel)',
        'Cormorant'             => 'Cormorant (Display version — even more dramatic)',
        'Cinzel'                => 'Cinzel (Roman Inscriptions / Architectural)',
        'Cinzel Decorative'     => 'Cinzel Decorative (With flourishes / Very formal)',
        'Libre Baskerville'     => 'Libre Baskerville (Classic Print / Book serif)',
        'Lora'                  => 'Lora (Warm / Modern-Traditional blend)',
        'Merriweather'          => 'Merriweather (Screen-Optimised / Long reads)',
        'Fraunces'              => 'Fraunces (Soft / Optical optical serifs / Unique)',
        'DM Serif Display'      => 'DM Serif Display (High Contrast / Luxury editorial)',
        'DM Serif Text'         => 'DM Serif Text (Body-weight companion to DM Serif Display)',
        'Stix Two Text'         => 'STIX Two Text (Scientific / Mathematical typography)',
        'EB Garamond'           => 'EB Garamond (Classic Garamond / Timeless book type)',
        'Newsreader'            => 'Newsreader (Long-Form Reading / Newspaper DNA)',
        'Spectral'              => 'Spectral (Screen Serif / Google-commissioned)',
        'Instrument Serif'      => 'Instrument Serif (Elegant / Figma-era editorial)',
        'Crimson Pro'           => 'Crimson Pro (Book Quality / Refined long text)',
        'Philosopher'           => 'Philosopher (Literary / Old-World Serif)',

        // ----------------------------------------------------------------
        // SERIF: DISPLAY ONLY — drama at large sizes, not body text
        // Best for: hero text, bold artistic statements
        // ----------------------------------------------------------------

        'Abril Fatface'         => 'Abril Fatface (Massive / Victorian poster energy)',
        'Ultra'                 => 'Ultra (Slab Serif / Extreme weight / Display only)',
        'Alfa Slab One'         => 'Alfa Slab One (Heavy Slab / Display only)',
        'Yeseva One'            => 'Yeseva One (Russian Constructivist DNA)',

        // ----------------------------------------------------------------
        // SLAB SERIF — structural, photographic, rugged
        // Best for: outdoors skins, documentary, landscape photography
        // ----------------------------------------------------------------

        'Roboto Slab'           => 'Roboto Slab (Material Design Slab / Pairs with DM Sans)',
        'Zilla Slab'            => 'Zilla Slab (Mozilla Firefox DNA / Editorial)',
        'Arvo'                  => 'Arvo (Geometric Slab / Very readable)',
        'Crete Round'           => 'Crete Round (Rounded Slab / Warm)',
        'Josefin Slab'          => 'Josefin Slab (Art Deco Slab / Vintage poster)',

        // ----------------------------------------------------------------
        // MONOSPACE — technical readouts, EXIF data, code, terminals
        // Best for: EXIF panels, photo metadata, code, industrial skins
        // ----------------------------------------------------------------

        'DM Mono'               => 'DM Mono (EXIF / Data Display — Picasa metadata)',
        'JetBrains Mono'        => 'JetBrains Mono (Developer / Ligatures / Very legible)',
        'Source Code Pro'       => 'Source Code Pro (Adobe / Clean code)',
        'Roboto Mono'           => 'Roboto Mono (Material / Technical data)',
        'Fira Mono'             => 'Fira Mono (Mozilla / Readable code)',
        'Inconsolata'           => 'Inconsolata (Humanist Mono / Print-quality)',
        'Space Mono'            => 'Space Mono (Retro-Tech / Quirky personality)',
        'IBM Plex Mono'         => 'IBM Plex Mono (Corporate Terminal / Precise)',
        'Courier Prime'         => 'Courier Prime (Screenplay / Typewriter Heritage)',
        'Anonymous Pro'         => 'Anonymous Pro (Hacker Mono / Bitmap DNA)',
        'Overpass Mono'         => 'Overpass Mono (Highway signage DNA)',

        // ----------------------------------------------------------------
        // DISPLAY: PERSONALITY & ART DIRECTION — use intentionally
        // Best for: themed skins where the font IS the statement
        // ----------------------------------------------------------------

        'Josefin Sans'          => 'Josefin Sans (Art Deco / 1920s Geometric)',
        'Poiret One'            => 'Poiret One (Decorative / Art Nouveau Geometric)',
        'Federo'                => 'Federo (Art Nouveau / Hand-lettered feel)',
        'Gruppo'                => 'Gruppo (Wide / Minimal Decorative Caps)',
        'Teko'                  => 'Teko (Condensed / South Asian Geometric)',
        'Stalinist One'         => 'Stalinist One (Soviet Propaganda — use knowingly)',

        // ----------------------------------------------------------------
        // HANDWRITING & SCRIPT — accent use only, never body text
        // Best for: quotes, pull-text, themed skins with handmade aesthetic
        // ----------------------------------------------------------------

        'Dancing Script'        => 'Dancing Script (Casual Script / Friendly)',
        'Pacifico'              => 'Pacifico (Surf / California Retro)',
        'Sacramento'            => 'Sacramento (Thin Calligraphic / Elegant)',
        'Great Vibes'           => 'Great Vibes (Formal Calligraphic / Wedding-adjacent)',
        'Caveat'                => 'Caveat (Handwritten / Personal / Informal)',
        'Kalam'                 => 'Kalam (Handwritten / South Asian origins)',

    ],


    /* =========================================================
       JAVASCRIPT ENGINE LIBRARY
       ========================================================= */
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
                    'options' => ['1' => 'Enabled', '0' => 'Disabled']
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
                'logo_glitch_enabled'   => ['type' => 'select', 'label' => 'Logo Glitch',               'default' => '1',      'options' => ['1' => 'Enabled', '0' => 'Disabled']],
                'logo_frequency'        => ['type' => 'select', 'label' => 'Frequency',                 'default' => 'normal', 'options' => ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'chaos' => 'Chaos']],
                'logo_split_position'   => ['type' => 'range',  'label' => 'Colour Split Position (%)', 'default' => '50',     'min' => '10', 'max' => '90'],
                'logo_split_drift'      => ['type' => 'select', 'label' => 'Split Drift on Hit',        'default' => '1',      'options' => ['1' => 'Enabled', '0' => 'Disabled']],
                'logo_font_blackcasper' => ['type' => 'select', 'label' => 'BlackCasper Hits',          'default' => '1',      'options' => ['1' => 'Enabled', '0' => 'Disabled']],
                'logo_font_courier'     => ['type' => 'select', 'label' => 'Courier Intrusion Hits',    'default' => '1',      'options' => ['1' => 'Enabled', '0' => 'Disabled']],
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
        ],
        'smack-thomas' => [
            'label'        => 'Thomas the Bear (Easter Egg)',
            'path'         => 'assets/js/ss-engine-thomas.js',
            'css'          => 'assets/css/ss-engine-thomas.css',
            'has_settings' => false
        ],
        'smack-justified-lib' => [
            'label'        => 'fjGallery Library (Flickr Justified Layout)',
            'path'         => 'assets/js/fjGallery.min.js',
            'css'          => 'assets/css/fjGallery.css',
            'has_settings' => false
        ],
        'smack-justified' => [
            'label'        => 'Justified Grid Engine',
            'path'         => 'assets/js/ss-engine-justified.js',
            'has_settings' => false
        ],
    ]
];
