<?php
/**
 * SNAPSMACK — Font-family fallback (shared)
 *
 * One home for the tail that follows a chosen font family in a compiled
 * font-family declaration, shared by the three emitters so they cannot drift:
 * core/skin-css-recompile.php, core/skin-compile-mobile.php and
 * core/skin-presentation.php. That drift was SS-1214, where the presentation
 * builder emitted `"DotMatrix", sans-serif` while the recompiler emitted
 * `"DotMatrix", 'Courier New', monospace` for the same font.
 *
 * SS-1519: IT USED TO ANSWER sans-serif FOR EVERYTHING THAT WAS NOT
 * MONOSPACE. A serif face therefore compiled to `"Source Serif 4",
 * sans-serif`, so a reader whose browser had not got the webfont read a
 * serif-designed page in a sans-serif. That was live on a real site, and it
 * reached 48 of the 61 declared font controls in the product: most offer
 * seventeen serif faces, and six skins ship a serif default.
 *
 * It also replaced the skin's own stack outright, so ALFRED's
 * `'Droid Serif', Georgia, serif` became `"Droid Serif", sans-serif` — losing
 * the Georgia step and the serif generic together.
 *
 * THREE SOURCES, IN THIS ORDER (the PM's ruling, 2026-11-22):
 *
 *   1. What the option DECLARES. A manifest option may carry a "fallback",
 *      which is the skin's own stack: ALFRED declares "Georgia, serif" and
 *      keeps it. Declared beats inferred, always, because the skin author
 *      knows what the rule said and this file cannot.
 *   2. A table of families we actually offer, classified by hand below.
 *   3. NOTHING. An unknown face with nothing declared emits the family on its
 *      own. A guess is what this ticket is about: no generic is worse than a
 *      right one and far better than a wrong one, and the repair is to
 *      declare the stack rather than to improve the guessing.
 */

if (!function_exists('snapsmack_font_family_generic_fallback')) {
    /**
     * The tail that follows a chosen family in a font-family value, with no
     * leading comma — "serif", "'Courier New', monospace", "Georgia, serif",
     * or '' when nothing can be said.
     *
     * $declared is the option's own "fallback", and wins when it is set.
     */
    function snapsmack_font_family_generic_fallback(string $family, string $declared = ''): string
    {
        $declared = trim($declared);
        if ($declared !== '') {
            // The skin states its stack once, and the chosen face is prepended
            // to it by the caller. IMPACT PRINTER's stack begins with the base
            // DotMatrix, so choosing plain DotMatrix would otherwise compile to
            // "DotMatrix", 'DotMatrix', 'Courier New', monospace. Harmless to a
            // browser, but it reads like a mistake, so the chosen face is
            // dropped from its own tail.
            $wanted = strtolower(trim($family, " \t\n\r\0\x0B\"'"));
            $kept = [];
            foreach (explode(',', $declared) as $part) {
                $bare = strtolower(trim($part, " \t\n\r\0\x0B\"'"));
                if ($bare !== '' && $bare === $wanted) continue;
                if (trim($part) !== '') $kept[] = trim($part);
            }
            return implode(', ', $kept);
        }

        $key = strtolower(trim($family));
        if ($key === '') return '';

        // THE HOUSE MONOSPACE FACES, by name. This is the one inference kept
        // from before SS-1519, for two reasons: it only ever asserts
        // monospace, which is the category a wrong answer is least likely to
        // be, and it is the shipped behaviour SS-1214 settled. DotMatrix
        // alone has thirty width and weight variants, all one typeface.
        if (stripos($family, 'DotMatrix') !== false || stripos($family, 'Mono') !== false
            || stripos($family, 'Courier') !== false || stripos($family, 'Tiny5') !== false
            || stripos($family, 'Anonymous') !== false) {
            return "'Courier New', monospace";
        }

        // Every family any skin offers today, classified by hand. Each entry
        // is a judgement about a typeface and any of them can be corrected;
        // a family that is NOT here deliberately gets no generic at all.
        // Left out on purpose: BlackCasper, ColdNightForAlligators,
        // EvenBadderMofo, Victorious — house display faces whose category I
        // cannot state with confidence, and a wrong generic on a display face
        // is exactly the fault being repaired here.
        static $known = [
            'abril fatface'                    => "serif",
            'albert sans'                      => "sans-serif",
            'alfa slab one'                    => "serif",
            'americanstencil'                  => "sans-serif",
            'anonymous pro'                    => "'Courier New', monospace",
            'antic didone'                     => "serif",
            'anton'                            => "sans-serif",
            'archivo black'                    => "sans-serif",
            'arvo'                             => "serif",
            'audiowide'                        => "sans-serif",
            'barlow'                           => "sans-serif",
            'barlow condensed'                 => "sans-serif",
            'bebas neue'                       => "sans-serif",
            'big shoulders display'            => "sans-serif",
            'caveat'                           => "cursive",
            'chakra petch'                     => "sans-serif",
            'cinzel'                           => "serif",
            'cinzel decorative'                => "serif",
            'cormorant'                        => "serif",
            'cormorant garamond'               => "serif",
            'courier prime'                    => "'Courier New', monospace",
            'crete round'                      => "serif",
            'crimson pro'                      => "serif",
            'dancing script'                   => "cursive",
            'dm mono'                          => "'Courier New', monospace",
            'dm sans'                          => "sans-serif",
            'dm serif display'                 => "serif",
            'dm serif text'                    => "serif",
            // Not offered by any control yet; it is ALFRED's hardcoded
            // reading face and will be offered when ALFRED gets its control.
            'droid serif'                      => "serif",
            'eb garamond'                      => "serif",
            'exo 2'                            => "sans-serif",
            'federo'                           => "sans-serif",
            'figtree'                          => "sans-serif",
            'fira mono'                        => "'Courier New', monospace",
            'fira sans'                        => "sans-serif",
            'flottflott'                       => "cursive",
            'fraunces'                         => "serif",
            'georgia'                          => "serif",
            'great vibes'                      => "cursive",
            'gruppo'                           => "sans-serif",
            'helvetica neue'                   => "sans-serif",
            'ibm plex mono'                    => "'Courier New', monospace",
            'ibm plex sans'                    => "sans-serif",
            'im fell dw pica'                  => "serif",
            'inconsolata'                      => "'Courier New', monospace",
            'instrument serif'                 => "serif",
            'inter'                            => "sans-serif",
            'jetbrains mono'                   => "'Courier New', monospace",
            'josefin sans'                     => "sans-serif",
            'josefin slab'                     => "serif",
            'jost'                             => "sans-serif",
            'kalam'                            => "cursive",
            'kanit'                            => "sans-serif",
            'keybinds'                         => "'Courier New', monospace",
            'krona one'                        => "sans-serif",
            'lato'                             => "sans-serif",
            'lexend'                           => "sans-serif",
            'libre baskerville'                => "serif",
            'linuxbiolinumkeyboard'            => "'Courier New', monospace",
            'lora'                             => "serif",
            'manrope'                          => "sans-serif",
            'marcellus'                        => "serif",
            'merriweather'                     => "serif",
            'michroma'                         => "sans-serif",
            'monospace'                        => "'Courier New', monospace",
            'montserrat'                       => "sans-serif",
            'newsreader'                       => "serif",
            'nunito'                           => "sans-serif",
            'nunito sans'                      => "sans-serif",
            'onest'                            => "sans-serif",
            'open sans'                        => "sans-serif",
            'orbitron'                         => "sans-serif",
            'oswald'                           => "sans-serif",
            'outfit'                           => "sans-serif",
            'overpass mono'                    => "'Courier New', monospace",
            'oxanium'                          => "sans-serif",
            'pacifico'                         => "cursive",
            'philosopher'                      => "sans-serif",
            'playfair display'                 => "serif",
            'plus jakarta sans'                => "sans-serif",
            'poiret one'                       => "sans-serif",
            'poppins'                          => "sans-serif",
            'public sans'                      => "sans-serif",
            'quicksand'                        => "sans-serif",
            'rajdhani'                         => "sans-serif",
            'raleway'                          => "sans-serif",
            'roboto mono'                      => "'Courier New', monospace",
            'roboto slab'                      => "serif",
            'rubik'                            => "sans-serif",
            'sacramento'                       => "cursive",
            'saira'                            => "sans-serif",
            'saira condensed'                  => "sans-serif",
            'share tech mono'                  => "'Courier New', monospace",
            'source code pro'                  => "'Courier New', monospace",
            'source serif 4'                   => "serif",
            'space grotesk'                    => "sans-serif",
            'space mono'                       => "'Courier New', monospace",
            'special elite'                    => "serif",
            'spectral'                         => "serif",
            'sprayme'                          => "cursive",
            'squaresanserif7'                  => "sans-serif",
            'stalinist one'                    => "sans-serif",
            'stix two text'                    => "serif",
            'syncopate'                        => "sans-serif",
            'syne'                             => "sans-serif",
            'teko'                             => "sans-serif",
            'tiny5'                            => "'Courier New', monospace",
            'tiny5-matrix'                     => "'Courier New', monospace",
            'ultra'                            => "serif",
            'unbounded'                        => "sans-serif",
            'work sans'                        => "sans-serif",
            'yeseva one'                       => "serif",
            'zilla slab'                       => "serif",
        ];

        return $known[$key] ?? '';
    }
}

// ===== SNAPSMACK EOF =====
