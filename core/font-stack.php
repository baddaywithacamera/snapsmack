<?php
/**
 * SNAPSMACK — Font-family generic fallback (shared)
 *
 * One home for the category-aware generic fallback that follows a chosen
 * font family in a compiled font-family declaration. A monospace face
 * (DotMatrix, Courier, Tiny5, Anonymous, anything "Mono") must fall back to a
 * monospace stack, not to sans-serif: when the custom face is unavailable the
 * text must still read as monospace, which is the whole point of choosing it.
 *
 * This logic lived inline and IDENTICAL in core/skin-css-recompile.php and
 * core/skin-compile-mobile.php, and was MISSING from core/skin-presentation.php,
 * so the presentation builder emitted `"DotMatrix", sans-serif` while the
 * recompiler emitted `"DotMatrix", 'Courier New', monospace` for the same font.
 * That drift is the IMPACT PRINTER footer pixel-parity failure (SS-1214). The
 * three emitters now share this one definition so they cannot drift again.
 *
 * Behaviour is deliberately unchanged from the two existing inline copies:
 * monospace faces get 'Courier New', monospace; everything else gets sans-serif.
 */

if (!function_exists('snapsmack_font_family_generic_fallback')) {
    /**
     * The generic fallback that follows a chosen family in a font-family value.
     * Returns a CSS fragment with no leading comma, e.g. "'Courier New', monospace"
     * or "sans-serif".
     */
    function snapsmack_font_family_generic_fallback(string $family): string
    {
        if (stripos($family, 'DotMatrix') !== false || stripos($family, 'Mono') !== false
            || stripos($family, 'Courier') !== false || stripos($family, 'Tiny5') !== false
            || stripos($family, 'Anonymous') !== false) {
            return "'Courier New', monospace";
        }
        return 'sans-serif';
    }
}

// ===== SNAPSMACK EOF =====
