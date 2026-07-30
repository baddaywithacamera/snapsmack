<?php
/**
 * SNAPSMACK - Font Loader
 *
 * Provides snapsmack_emit_font_tags() — a shared helper for any skin that
 * has font pickers. Replaces per-skin hardcoded Google Fonts <link> tags
 * with a dynamic loader that responds to what the user actually selected.
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
 *   - Google Fonts (inventory['fonts']):        emits a single Google Fonts
 *                  API request covering all selected Google families.
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
    static $google_fonts = null;

    if ($local_fonts === null) {
        $inv         = include __DIR__ . '/manifest-inventory.php';
        $local_fonts  = $inv['local_fonts'] ?? [];
        $google_fonts = $inv['fonts']        ?? [];
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
    $google_to_load = [];   // [] of family name strings

    foreach ($clean as $fk) {
        if (isset($local_fonts[$fk])) {
            $local_to_load[$fk] = $local_fonts[$fk];
        } elseif (isset($google_fonts[$fk])) {
            $google_to_load[] = $fk;
        }
        // else: system font (monospace, Georgia, serif…) — no loading needed
    }

    // ── Google Fonts ──────────────────────────────────────────────────────────
    // One request covers all selected Google Font families.
    // Axis spec: wght@300;400;500;600;700;900 — broad enough for all skins.
    // The API silently skips weights a given family doesn't have.
    if (!empty($google_to_load)) {
        $parts = [];
        foreach ($google_to_load as $family) {
            $parts[] = 'family=' . urlencode($family) . ':wght@300;400;500;600;700;900';
        }
        $gf_url = 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap';
        ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="<?php echo htmlspecialchars($gf_url, ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet">
        <?php
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
