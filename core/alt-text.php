<?php
/**
 * SNAPSMACK — ALT text (accessibility) helpers.
 *
 * ONE audited home for the two security-critical operations on image ALT text,
 * so every write path and every render path shares the same behaviour instead
 * of re-implementing it (and drifting).
 *
 *   snap_sanitize_alt()  — STORE side. Untrusted input (human AND AI) → DB.
 *                          Trims, collapses whitespace, strips control chars,
 *                          hard-caps to the column length. Stores RAW otherwise
 *                          (legit ALT may contain <, &, quotes — do NOT lossy-
 *                          sanitize on input; escape on OUTPUT instead).
 *   snap_alt_attr()      — OUTPUT side. Resolves ALT with an img_title/asset_name
 *                          fallback and escapes for HTML ATTRIBUTE context with
 *                          ENT_QUOTES (neutralises attribute breakout — the only
 *                          real ALT XSS vector). Use everywhere ALT hits an
 *                          alt="" attribute in a PHP template.
 *
 * AI output is treated as hostile input: a prompt-injection baked into an image
 * or its EXIF can make the vision model emit a breakout string on purpose, so
 * AI-authored ALT gets the SAME sanitize-on-store / escape-on-output as text a
 * stranger typed. There is no "the model wrote it, so trust it" path.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!defined('SNAPSMACK_ALT_MAXLEN')) {
    // Matches img_alt / asset_alt varchar(500). Defined with a shipping fallback
    // (never delivered via constants.php — see CLAUDE.md protected-path note).
    define('SNAPSMACK_ALT_MAXLEN', 500);
}

if (!function_exists('snap_sanitize_alt')) {
    /**
     * Clean ALT text for storage. Returns a trimmed, control-char-stripped,
     * length-capped string, or null when nothing usable remains (so callers can
     * store NULL and let render-time fall back to title/name).
     *
     * @param mixed $raw  Raw client/AI value.
     * @param int   $max  Hard length cap (defaults to the column length).
     */
    function snap_sanitize_alt($raw, int $max = SNAPSMACK_ALT_MAXLEN): ?string
    {
        $s = (string)$raw;
        // Strip C0/C1 control chars (keep ordinary whitespace, collapsed below).
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        if ($s === null) return null; // preg failure on invalid UTF-8
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        if ($s === '') return null;
        // Server-enforced cap — never trust the client field limit.
        return mb_substr($s, 0, max(1, $max));
    }
}

if (!function_exists('snap_alt_attr')) {
    /**
     * Resolve ALT for an alt="" attribute: use $alt when non-blank, otherwise the
     * $fallback (img_title / asset_name), then escape for attribute context.
     * ENT_QUOTES is mandatory — it is what closes the attribute-breakout vector.
     */
    function snap_alt_attr(?string $alt, ?string $fallback = ''): string
    {
        $v = trim((string)$alt);
        if ($v === '') $v = (string)$fallback;
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('snap_alt_value')) {
    /**
     * Resolve ALT with fallback WITHOUT escaping — for contexts that do their own
     * encoding (JSON via json_encode → JS element.alt, ActivityPub JSON-LD).
     * NEVER feed the result straight into HTML; use snap_alt_attr() for that.
     */
    function snap_alt_value(?string $alt, ?string $fallback = ''): string
    {
        $v = trim((string)$alt);
        return $v !== '' ? $v : (string)$fallback;
    }
}
// ===== SNAPSMACK EOF =====
