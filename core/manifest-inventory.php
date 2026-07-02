<?php
/**
 * SNAPSMACK - System Inventory
 *
 * Single source of truth for all available system resources: local fonts,
 * Google Fonts, and JavaScript engines. Skins request assets from this list
 * via their individual manifest.php.
 *
 * LOCAL FONTS: Hosted in assets/fonts/ on the server. Output automatically
 *              as @font-face blocks. Skin manifests may declare allowed_fonts[]
 *              to restrict the font picker to a curated subset.
 *
 * GOOGLE FONTS: Loaded on demand via Google CDN. Key = exact Google Fonts
 *               family name (used in API URL). Value = friendly label shown
 *               in the UI. Inter and Roboto deliberately excluded—they are
 *               everywhere and add nothing distinctive to a photoblog.
 *
 * SCRIPTS: JavaScript engines (lightbox, glitch, keyboard, etc.) that skins
 *          can declare via require_scripts[].
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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
            'file'   => 'assets/fonts/BlackCasper/blackcasper.regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- FlottFlott -------------------------------------------------
        'FlottFlott' => [
            'label'  => 'FlottFlott (Calligraphic Script)',
            'file'   => 'assets/fonts/FlottFlott/flottflott.regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- Tiny5 (Dot Matrix Headers) ---------------------------------
        'Tiny5' => [
            'label'  => 'Tiny5 (Bold / Dot Matrix Headers)',
            'file'   => 'assets/fonts/Tiny5/tiny5.bold.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'normal',
        ],
        'Tiny5-Matrix' => [
            'label'  => 'Tiny5 Matrix (Bold / Dot Matrix Display)',
            'file'   => 'assets/fonts/Tiny5/tiny5.matrix-bold.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
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
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-CondensedRegular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Condensed-Bold' => [
            'label'  => 'DotMatrix Condensed (Bold)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-CondensedBold.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'normal',
        ],
        'DotMatrix-Condensed-Italic' => [
            'label'  => 'DotMatrix Condensed (Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-CondensedItalic.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'italic',
        ],
        'DotMatrix-Condensed-BoldItalic' => [
            'label'  => 'DotMatrix Condensed (Bold Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-CondensedBoldItalic.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'italic',
        ],

        // ---- DotMatrix Expanded -----------------------------------------
        'DotMatrix-Expanded' => [
            'label'  => 'DotMatrix Expanded (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-ExpandedRegular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Expanded-Bold' => [
            'label'  => 'DotMatrix Expanded (Bold)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-ExpandedBold.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'normal',
        ],
        'DotMatrix-Expanded-Italic' => [
            'label'  => 'DotMatrix Expanded (Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-ExpandedItalic.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'italic',
        ],
        'DotMatrix-Expanded-BoldItalic' => [
            'label'  => 'DotMatrix Expanded (Bold Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrix-ExpandedBoldItalic.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'italic',
        ],

        // ---- DotMatrix Quad ---------------------------------------------
        'DotMatrix-Quad' => [
            'label'  => 'DotMatrix Quad (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixQuad-Regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Quad-Bold' => [
            'label'  => 'DotMatrix Quad (Bold)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixQuad-Bold.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'normal',
        ],
        'DotMatrix-Quad-Italic' => [
            'label'  => 'DotMatrix Quad (Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixQuad-Italic.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'italic',
        ],
        'DotMatrix-Quad-BoldItalic' => [
            'label'  => 'DotMatrix Quad (Bold Italic)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixQuad-BoldItalic.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'italic',
        ],

        // ---- DotMatrix Variable -----------------------------------------
        'DotMatrix-Var' => [
            'label'  => 'DotMatrix Var (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixVar-Regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Var-Condensed' => [
            'label'  => 'DotMatrix Var (Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixVar-CondensedRegular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Var-Expanded' => [
            'label'  => 'DotMatrix Var (Expanded)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixVar-ExpandedRegular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        // DotMatrix Var UltraCondensed intentionally omitted — the font
        // package does not include that width for the non-Duo variant.
        // Use DotMatrix-VarDuo-UltraCondensed instead.

        // ---- DotMatrix VarDuo -------------------------------------------
        'DotMatrix-VarDuo' => [
            'label'  => 'DotMatrix VarDuo (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixVarDuo-Regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-VarDuo-Condensed' => [
            'label'  => 'DotMatrix VarDuo (Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixVarDuo-CondensedRegular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-VarDuo-Expanded' => [
            'label'  => 'DotMatrix VarDuo (Expanded)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixVarDuo-ExpandedRegular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-VarDuo-UltraCondensed' => [
            'label'  => 'DotMatrix VarDuo (Ultra Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixVarDuo-UltraCondensedRegular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- DotMatrix Duo ----------------------------------------------
        'DotMatrix-Duo' => [
            'label'  => 'DotMatrix Duo (Regular)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixDuo-Regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Duo-Condensed' => [
            'label'  => 'DotMatrix Duo (Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixDuo-CondensedRegular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Duo-Expanded' => [
            'label'  => 'DotMatrix Duo (Expanded)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixDuo-ExpandedRegular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],
        'DotMatrix-Duo-UltraCondensed' => [
            'label'  => 'DotMatrix Duo (Ultra Condensed)',
            'file'   => 'assets/fonts/DotMatrix/DotMatrixDuo-UltraCondensedRegular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- Square Sans Serif 7 ----------------------------------------
        'SquareSanSerif7' => [
            'label'  => 'Square Sans Serif 7 (Blocky / Digital Clock)',
            'file'   => 'assets/fonts/SquareSanSerif7/square-sans-serif-7.regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- KeyBinds ----------------------------------------------------
        'KeyBinds' => [
            'label'  => 'KeyBinds (Keyboard Key Caps)',
            'file'   => 'assets/fonts/KeyBinds/keybinds.regular.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- Linux Biolinum Keyboard -------------------------------------
        'LinuxBiolinumKeyboard' => [
            'label'  => 'Linux Biolinum Keyboard (Framed Key Glyphs)',
            'file'   => 'assets/fonts/LinuxBiolinum/linux-biolinum.keyboard.ttf',
            'format' => 'truetype',
            'weight' => 'bold',
            'style'  => 'normal',
        ],

        // ---- American Stencil ----------------------------------------------
        'AmericanStencil' => [
            'label'  => 'American Stencil (Military / Cargo Crate)',
            'file'   => 'assets/fonts/American Stencil/AmericanStencil.otf',
            'format' => 'opentype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- Cold Night for Alligators -------------------------------------
        'ColdNightForAlligators' => [
            'label'  => 'Cold Night for Alligators (Horror / Scratched)',
            'file'   => 'assets/fonts/Cold Night for Alligators/coldnightforalligators.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- Even Badder Mofo ----------------------------------------------
        'EvenBadderMofo' => [
            'label'  => 'Even Badder Mofo (Grunge / Distressed)',
            'file'   => 'assets/fonts/Even Badder Mofo/Even Badder Mofo.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- Spray.ME ------------------------------------------------------
        'SprayME' => [
            'label'  => 'Spray.ME (Stencil / Spray Paint)',
            'file'   => 'assets/fonts/Spray.ME/sprayme.ttf',
            'format' => 'truetype',
            'weight' => 'normal',
            'style'  => 'normal',
        ],

        // ---- Victorious ----------------------------------------------------
        'Victorious' => [
            'label'  => 'Victorious (Bold / Rough Brush)',
            'file'   => 'assets/fonts/Victorious/Victorious.ttf',
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

        'DM Sans'               => 'DM Sans (Geometric / Clean UI body)',
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

        'Raleway'               => 'Raleway (Elegant Display / Thin-to-Heavy)',
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
        'Marcellus'             => 'Marcellus (Roman Capitals / NatGeo masthead feel)',
        'Philosopher'           => 'Philosopher (Literary / Old-World Serif)',
        'IM Fell DW Pica'       => 'IM Fell DW Pica (Hand-Press / Old-Style Roughness)',

        // ----------------------------------------------------------------
        // SERIF: DISPLAY ONLY — drama at large sizes, not body text
        // Best for: hero text, bold artistic statements
        // ----------------------------------------------------------------

        'Abril Fatface'         => 'Abril Fatface (Massive / Victorian poster energy)',
        'Ultra'                 => 'Ultra (Slab Serif / Extreme weight / Display only)',
        'Alfa Slab One'         => 'Alfa Slab One (Heavy Slab / Display only)',
        'Yeseva One'            => 'Yeseva One (Russian Constructivist DNA)',
        'Antic Didone'          => 'Antic Didone (High-Contrast / Silent Film Display)',

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

        'DM Mono'               => 'DM Mono (EXIF / Data Display / Monospace)',
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
        'Special Elite'         => 'Special Elite (Typewriter Stamp / Period Utility)',

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
       FLAG LIBRARY (stock for the Flag Wave engine, smack-flag-wave)
       The central, reusable stock of flags. A skin curates which it
       offers (e.g. PARADE's pa_palette list); the chosen flag's stripe
       definition is emitted to the engine as data-stripes/-orientation.
       'o' = stripe orientation (h|v). stripes = [[hex, weight], …];
       weight is the relative stripe size (bi = 40/20/40 → 2/1/2).
       LGBT set only for now — extend with national/other flags as needed.
       ========================================================= */
    'flags' => [
        'rainbow'   => ['label' => 'Rainbow Pride (6-stripe)',  'o' => 'h',
                        'stripes' => [['#E40303', 1], ['#FF8C00', 1], ['#FFED00', 1], ['#008026', 1], ['#004DFF', 1], ['#750787', 1]]],
        'bi'        => ['label' => 'Bisexual',                  'o' => 'h',
                        'stripes' => [['#D60270', 2], ['#9B4F96', 1], ['#0038A8', 2]]],
        'trans'     => ['label' => 'Transgender',              'o' => 'h',
                        'stripes' => [['#55CDFC', 1], ['#F7A8B8', 1], ['#FFFFFF', 1], ['#F7A8B8', 1], ['#55CDFC', 1]]],
        'nonbinary' => ['label' => 'Non-Binary',               'o' => 'h',
                        'stripes' => [['#FCF434', 1], ['#FFFFFF', 1], ['#9C59D1', 1], ['#2C2C2C', 1]]],
        'pan'       => ['label' => 'Pansexual',                'o' => 'h',
                        'stripes' => [['#FF218C', 1], ['#FFD800', 1], ['#21B1FF', 1]]],
        'lesbian'   => ['label' => 'Lesbian (5-stripe)',       'o' => 'h',
                        'stripes' => [['#D52D00', 1], ['#FF9A56', 1], ['#FFFFFF', 1], ['#D362A4', 1], ['#A30262', 1]]],
        'asexual'   => ['label' => 'Asexual',                  'o' => 'h',
                        'stripes' => [['#000000', 1], ['#A3A3A3', 1], ['#FFFFFF', 1], ['#800080', 1]]],
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
        'smack-matrix-rain' => [
            'label'        => 'Matrix Rain background effect (canvas)',
            'path'         => 'assets/js/ss-engine-matrix-rain.js',
            'has_settings' => false,
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
        'smack-ascii-borders' => [
            'label'        => 'ASCII Border Frame Engine',
            'path'         => 'assets/js/ss-engine-ascii-borders.js',
            'has_settings' => false
        ],
        'smack-justified' => [
            'label'        => 'Justified Grid Engine',
            'path'         => 'assets/js/ss-engine-justified.js',
            'has_settings' => false
        ],
        'fsog-layout-toggle' => [
            'label'        => '50 Shades Archive Layout Toggle',
            'path'         => 'assets/js/ss-engine-fsog-layout-toggle.js',
            'has_settings' => false
        ],
        'smack-slider' => [
            'label'        => 'Gallery Slider Engine',
            'path'         => 'assets/js/ss-engine-slider.js',
            'css'          => 'assets/css/ss-engine-slider.css',
            'has_settings' => true,
            'controls'     => [
                'slider_per_view' => [
                    'type'    => 'select',
                    'label'   => 'Images Per View',
                    'default' => '2',
                    'options' => ['1' => '1', '2' => '2', '3' => '3']
                ],
                'slider_speed' => [
                    'type'    => 'range',
                    'label'   => 'Transition Speed (ms)',
                    'default' => '800',
                    'min'     => '400',
                    'max'     => '1500'
                ],
                'slider_auto_advance' => [
                    'type'    => 'select',
                    'label'   => 'Auto Advance',
                    'default' => '0',
                    'options' => ['0' => 'Disabled', '1' => 'Enabled']
                ],
                'slider_loop' => [
                    'type'    => 'select',
                    'label'   => 'Loop Slides',
                    'default' => '1',
                    'options' => ['1' => 'Enabled', '0' => 'Disabled']
                ]
            ]
        ],
        'smack-fingerprint' => [
            'label'        => 'Browser Fingerprint Engine (passive — required for ban system)',
            'path'         => 'assets/js/ss-engine-fingerprint.js',
            'has_settings' => false,
        ],
        'smack-community' => [
            'label'        => 'Community Engine (Likes, Comments, Reactions)',
            'path'         => 'assets/js/ss-engine-community.js',
            'css'          => 'assets/css/ss-community.css',
            'has_settings' => false,
        ],

        'smack-photogram' => [
            'label'        => 'Photogram Engine (Bottom sheet, double-tap like, nav)',
            'path'         => 'assets/js/ss-engine-photogram.js',
            'has_settings' => false,
        ],
        'smack-photogram-feed' => [
            'label'        => 'Photogram Feed (landing infinite-scroll grid)',
            'path'         => 'assets/js/ss-engine-photogram-feed.js',
            'has_settings' => false,
        ],
        'smack-mosaic' => [
            'label'        => 'Mosaic Engine (Tiled image panels via [mosaic:ID] shortcode)',
            'path'         => 'assets/js/ss-engine-mosaic.js',
            'css'          => 'assets/css/ss-engine-mosaic.css',
            'has_settings' => false,
        ],
        'smack-carousel-post' => [
            'label'        => 'Carousel Post Engine (Multi-image upload strip with drag-reorder)',
            'path'         => 'assets/js/ss-engine-carousel-post.js',
            'has_settings' => false,
        ],
        'smack-scan-align' => [
            'label'        => 'Scan Align (posting-interface ±5° rotate + crop-to-fill; INSTANT CAMERA)',
            'path'         => 'assets/js/ss-engine-scan-align.js',
            'has_settings' => false,
        ],
        'smack-overlay' => [
            'label'        => 'Center-Expand Overlay (Info/Comments panel)',
            'path'         => 'assets/js/ss-engine-overlay.js',
            'has_settings' => false,
        ],
        'smack-organized-mayhem' => [
            'label'        => 'Organized Mayhem (infinite pannable tabletop of scattered prints)',
            'path'         => 'assets/js/ss-engine-organized-mayhem.js',
            'has_settings' => true,
            'controls'     => [
                'mayhem_initial_count' => [
                    'type'    => 'range',
                    'label'   => 'Initial Photos on the Table',
                    'default' => '120',
                    'min'     => '40',
                    'max'     => '400',
                    'step'    => '10'
                ],
                'mayhem_max_width' => [
                    'type'    => 'range',
                    'label'   => 'Max Print Width (px)',
                    'default' => '300',
                    'min'     => '120',
                    'max'     => '500',
                    'step'    => '10'
                ],
                'mayhem_overlap_max' => [
                    'type'    => 'range',
                    'label'   => 'Max Overlap (%)',
                    'default' => '85',
                    'min'     => '40',
                    'max'     => '95',
                    'step'    => '5'
                ],
                'mayhem_drift' => [
                    'type'    => 'select',
                    'label'   => 'Idle Cinematic Drift',
                    'default' => '1',
                    'options' => ['1' => 'Enabled', '0' => 'Disabled']
                ],
                'mayhem_warp' => [
                    'type'    => 'select',
                    'label'   => 'Paper Warp (3D skew)',
                    'default' => '1',
                    'options' => ['1' => 'Enabled', '0' => 'Disabled']
                ],
            ]
        ],
        'smack-52-pickup' => [
            'label'        => '52 PICKUP interaction layer (hover-lift, click-to-expand, ghost chrome, ESC return)',
            'path'         => 'assets/js/ss-engine-52-pickup.js',
            'css'          => 'assets/css/ss-engine-52-pickup.css',
            'has_settings' => false,
        ],
        'smack-anaglyph' => [
            'label'        => 'Anaglyph 3D Engine (Red/Cyan stereoscopic)',
            'path'         => 'assets/js/ss-engine-anaglyph.js',
            'css'          => 'assets/css/ss-engine-anaglyph.css',
            'has_settings' => true,
            'controls'     => [
                'anaglyph_text_depth' => [
                    'type'    => 'range',
                    'label'   => 'Text Depth (px)',
                    'default' => '3',
                    'min'     => '1',
                    'max'     => '8',
                ],
                'anaglyph_frame_depth' => [
                    'type'    => 'range',
                    'label'   => 'Frame Depth (px)',
                    'default' => '4',
                    'min'     => '1',
                    'max'     => '12',
                ],
                'anaglyph_animation' => [
                    'type'    => 'select',
                    'label'   => 'Animation Mode',
                    'default' => 'none',
                    'options' => [
                        'none'   => 'Static',
                        'pulse'  => 'Depth Pulse (breathing)',
                        'drift'  => 'Channel Drift (wandering)',
                        'glitch' => 'Glitch (random snaps)',
                    ],
                ],
            ],
        ],
        'smack-drawer' => [
            'label'        => 'Dual Drawer Controller (Top/Bottom drawers)',
            'path'         => 'assets/js/ss-engine-drawer.js',
            'has_settings' => false,
        ],
        'smack-carousel-view' => [
            'label'        => 'Carousel View Engine (EXIF panel sync)',
            'path'         => 'assets/js/ss-engine-carousel-view.js',
            'has_settings' => false,
        ],
        'smack-grid-nav' => [
            'label'        => 'Grid Sticky Nav (profile-aware sticky nav for The Grid)',
            'path'         => 'assets/js/ss-engine-grid-nav.js',
            'has_settings' => false,
        ],
        'smack-grid-modal' => [
            'label'        => 'The Grid post modal overlay (IG-style popover)',
            'path'         => 'assets/js/ss-engine-grid-modal.js',
            'has_settings' => false,
        ],
        'smack-grid-lightbox' => [
            'label'        => 'Grid-family avatar lightbox (shared)',
            'path'         => 'assets/js/ss-engine-grid-lightbox.js',
            'has_settings' => false,
        ],
        'smack-aurora-bg' => [
            'label'        => 'AURORA Layer 1 background curtains (canvas)',
            'path'         => 'assets/js/ss-engine-aurora-bg.js',
            'has_settings' => false,
        ],
        'smack-aurora-wave' => [
            'label'        => 'AURORA Layer 2 tile border colour wave',
            'path'         => 'assets/js/ss-engine-aurora-wave.js',
            'has_settings' => false,
        ],
        'smack-progressive-reveal' => [
            'label'        => 'Progressive reveal — grid tiles + justified rows (grow-as-you-scroll)',
            'path'         => 'assets/js/ss-engine-progressive-reveal.js',
            'has_settings' => false,
        ],
        'smack-tag-infinite' => [
            'label'        => 'Hashtag infinite scroll (prefix-derived, Grid-family)',
            'path'         => 'assets/js/ss-engine-tag-infinite.js',
            'has_settings' => false,
        ],
        'smack-archive-grid-switch' => [
            'label'        => 'Archive grid switch (thumbs/masonry responder)',
            'path'         => 'assets/js/ss-engine-archive-grid-switch.js',
            'has_settings' => false,
        ],
        'smack-parade-fireworks' => [
            'label'        => 'PARADE Layer 1 slow-motion fireworks (canvas)',
            'path'         => 'assets/js/ss-engine-parade-fireworks.js',
            'has_settings' => false,
        ],
        'smack-flag-wave' => [
            'label'        => 'Flag Wave (full-viewport waving flag background, canvas; data-driven flags)',
            'path'         => 'assets/js/ss-engine-flag-wave.js',
            'has_settings' => false,
        ],
        'smack-racetrack' => [
            'label'        => 'RACETRACK (long-exposure light trails lapping a circuit, canvas)',
            'path'         => 'assets/js/ss-engine-racetrack.js',
            'has_settings' => false,
        ],
        'smack-rainfall' => [
            'label'        => 'RAINFALL (falling rain streaks + splashes, canvas)',
            'path'         => 'assets/js/ss-engine-rainfall.js',
            'has_settings' => false,
        ],
        'smack-bg-cycle' => [
            'label'        => 'Background cycle crossfader (rotates stacked bg layers on a timer)',
            'path'         => 'assets/js/ss-engine-bg-cycle.js',
            'has_settings' => false,
        ],
        'smack-calendar' => [
            'label'        => 'Archive Calendar Sidebar (Sliding date panel)',
            'path'         => 'assets/js/ss-engine-calendar.js',
            'css'          => 'assets/css/ss-engine-calendar.css',
            'has_settings' => true,
            'admin_page'   => 'archive',
            'controls'     => [
                'calendar_months' => [
                    'type'    => 'select',
                    'label'   => 'Months to Show',
                    'default' => '1',
                    'options' => ['1' => '1 Month', '2' => '2 Months', '3' => '3 Months']
                ],
                'calendar_post_count' => [
                    'type'    => 'range',
                    'label'   => 'Recent Posts Listed',
                    'default' => '10',
                    'min'     => '5',
                    'max'     => '20',
                    'step'    => '1'
                ],
                'calendar_side' => [
                    'type'    => 'select',
                    'label'   => 'Panel Side',
                    'default' => 'left',
                    'options' => ['left' => 'Slide From Left', 'right' => 'Slide From Right']
                ],
            ]
        ],
        'smack-archive-toggle' => [
            'label'        => 'Archive Layout Toggle (in-place T/M switch + hotkeys)',
            'path'         => 'assets/js/ss-engine-archive-toggle.js',
            'has_settings' => false,
            'admin_page'   => 'archive',
        ],
        'smack-alfred-nav' => [
            'label'        => 'Alfred skin mobile navigation toggle',
            'path'         => 'skins/alfred/assets/js/alfred-nav.js',
            'has_settings' => false,
        ],
        'smack-chaplin-film' => [
            'label'        => 'Chaplin Film Effects Engine (scratches, flicker, grain)',
            'path'         => 'skins/chaplin/assets/js/ss-engine-chaplin-film.js',
            'has_settings' => false,
        ],
        'smack-chaplin-overlay' => [
            'label'        => 'Chaplin Overlay Controller + Film Init',
            'path'         => 'skins/chaplin/assets/js/ss-engine-chaplin-overlay.js',
            'has_settings' => false,
        ],
        'smack-lazyload' => [
            'label'        => 'Lazy Loading Engine (Progressive image loading)',
            'path'         => 'assets/js/ss-engine-lazyload.js',
            'has_settings' => true,
            'controls'     => [
                'lazy_root_margin' => [
                    'type'    => 'select',
                    'label'   => 'Preload Distance',
                    'default' => '200px',
                    'options' => ['100px' => 'Near (100px)', '200px' => 'Normal (200px)', '400px' => 'Far (400px)', '800px' => 'Very Far (800px)']
                ],
                'lazy_fade_duration' => [
                    'type'    => 'range',
                    'label'   => 'Fade-In Speed (ms)',
                    'default' => '300',
                    'min'     => '0',
                    'max'     => '800'
                ]
            ]
        ],
        'smack-fullscreen' => [
            'label'        => 'Fullscreen Engine (Distraction-free image viewing)',
            'path'         => 'assets/js/ss-engine-fullscreen.js',
            'has_settings' => false,
        ],
        'smack-image-fade-load' => [
            'label'        => 'Image Fade-Load Engine (Graceful image fade-in on load)',
            'path'         => 'assets/js/ss-engine-image-fade-load.js',
            'has_settings' => false,
        ],
        'smack-photo-editor' => [
            'label'        => 'Photo Editor (Crop, Rotate, Adjust, Sharpen)',
            'path'         => 'assets/js/ss-engine-photo-editor.js',
            'css'          => 'assets/css/ss-engine-photo-editor.css',
            'has_settings' => false,
        ],
        'smack-scroll-top' => [
            'label'        => 'Scroll-to-Top Button',
            'path'         => 'assets/js/ss-engine-scroll-top.js',
            'has_settings' => true,
            'controls'     => [
                'scroll_top_threshold' => [
                    'type'    => 'range',
                    'label'   => 'Show After Scrolling (px)',
                    'default' => '400',
                    'min'     => '100',
                    'max'     => '1000'
                ],
                'scroll_top_position' => [
                    'type'    => 'select',
                    'label'   => 'Button Position',
                    'default' => 'right',
                    'options' => ['right' => 'Bottom Right', 'left' => 'Bottom Left']
                ]
            ]
        ],
        'smack-film-damage' => [
            'label'        => 'Film Damage Overlay (scratches, dust, hair, gate weave)',
            'path'         => 'assets/js/ss-engine-film-damage.js',
            'has_settings' => false,
        ],
        'smack-gram-search' => [
            'label'        => 'Floating Search Dock (bottom-left magnifier → expanding search box; gated on search_enabled)',
            'path'         => 'assets/js/ss-engine-gram-search.js',
            'css'          => 'assets/css/ss-engine-gram-search.css',
            'has_settings' => false,
        ],
    ]
];
// ===== SNAPSMACK EOF =====
