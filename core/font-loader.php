<?php
/**
 * SNAPSMACK - Font Loader
 *
 * Provides snapsmack_emit_font_tags() — a shared helper for any skin that
 * has font pickers. Replaces per-skin hardcoded Google Fonts <link> tags
 * with a local-only loader that responds to what the user actually selected.
 *
 * Usage in a skin's skin-header.php:
 *
 *   require_once dirname(__DIR__, 2) . '/core/font-loader.php';
 *   snapsmack_emit_font_tags([
 *       $settings['skin_title_font']   ?? 'Cinzel',
 *       $settings['skin_heading_font'] ?? 'Cinzel',
 *       $settings['skin_body_font']    ?? 'Cormorant Garamond',
 *       $settings['skin_footer_font']  ?? '',
 *   ], BASE_URL);
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


/**
 * Emit HTML font-loading tags for the given set of selected font family names.
 *
 * Checks each key against the system font inventory:
 *   - Local fonts  (inventory['local_fonts']): emits a @font-face <style> block.
 *                  The font file is served from BASE_URL, so the TTF ships with
 *                  the install (assets/fonts/).
 *   - Remote font names: ignored. Public rendering never contacts a font CDN.
 *   - System fonts (monospace, serif, Georgia, etc.): silently skipped —
 *                  no loading needed.
 *
 * The inventory is cached in a static variable; calling this function multiple
 * times in one request loads manifest-inventory.php only once.
 *
 * @param array  $font_keys  Flat array of font family name strings, as stored
 *                           in $settings (e.g. 'Cinzel', 'FlottFlott', '').
 *                           Duplicates and empty values are handled internally.
 * @param string $base_url   The install's BASE_URL constant (for @font-face src).
 */
function snapsmack_emit_font_tags(array $font_keys, string $base_url): void {

    // ── Load inventory once per request ──────────────────────────────────────
    static $local_fonts = null;

    if ($local_fonts === null) {
        $inv         = include __DIR__ . '/manifest-inventory.php';
        $local_fonts  = $inv['local_fonts'] ?? [];
    }

    // ── Normalise input: de-dupe, drop empty, drop font stack suffixes ────────
    // $settings values are plain family names ('Cinzel', 'FlottFlott', etc.)
    // but guard against accidental full stack strings just in case.
    $clean = [];
    foreach ($font_keys as $k) {
        $k = trim((string)$k);
        if ($k === '') continue;
        // If someone passed a full CSS stack ('Cinzel, serif'), take only the first token.
        if (strpos($k, ',') !== false) {
            $k = trim(explode(',', $k)[0], " '\"");
        }
        $clean[] = $k;
    }
    $clean = array_unique($clean);
    if (empty($clean)) return;

    // ── Classify each font key ────────────────────────────────────────────────
    $local_to_load  = [];   // key => $local_fonts[$key]
    foreach ($clean as $fk) {
        if (isset($local_fonts[$fk])) {
            $local_to_load[$fk] = $local_fonts[$fk];
        }
        // else: system font (monospace, Georgia, serif…) — no loading needed
    }

    // ── Local fonts (@font-face) ──────────────────────────────────────────────
    // Each local font file lives at BASE_URL . $font_data['file'].
    if (!empty($local_to_load)) {
        ?>
<style>
        <?php foreach ($local_to_load as $family => $fd): ?>
@font-face {
  font-family: '<?php echo htmlspecialchars($family, ENT_QUOTES, 'UTF-8'); ?>';
  src: url('<?php echo htmlspecialchars(rtrim($base_url, '/') . '/' . ltrim($fd['file'], '/'), ENT_QUOTES, 'UTF-8'); ?>') format('<?php echo htmlspecialchars($fd['format'], ENT_QUOTES, 'UTF-8'); ?>');
  font-weight: <?php echo htmlspecialchars($fd['weight'], ENT_QUOTES, 'UTF-8'); ?>;
  font-style: <?php echo htmlspecialchars($fd['style'], ENT_QUOTES, 'UTF-8'); ?>;
  font-display: swap;
}
        <?php endforeach; ?>
</style>
        <?php
    }
}
// ===== SNAPSMACK EOF =====
