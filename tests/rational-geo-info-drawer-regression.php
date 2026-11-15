<?php
declare(strict_types=1);

/**
 * RATIONAL GEO's info tray — what a CSS grep can honestly pin, and what it cannot.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 *
 * WHY THIS CHANGED. Until now this test required the CSS to contain
 * `position: absolute` and `bottom: var(--infobox-height, 50px)` — it pinned
 * the tray as an upward overlay. Sean rejected that behaviour: the tray must
 * open DOWNWARD, in flow, the way IMPACT PRINTER's does, so it adds scroll
 * instead of covering the photograph. SS-1322.
 *
 * So this file was holding the rejected design in place. Codex measured the
 * corrected behaviour in a browser and could not commit it, because this test
 * failed on the fix. A test that blocks the repair it was written to protect
 * is worse than no test.
 *
 * AND THE DEEPER FAULT IS THE INSTRUMENT. Searching a stylesheet for the
 * string "position: absolute" never proved which way a tray opens. It proved
 * that two words appear in a file. Direction is geometry: it needs the
 * rendered page. This file keeps only what reading the source can settle.
 *
 * SS-1345: THERE IS NO RENDERED CHECK ON DIRECTION AT PRESENT. This comment
 * used to point at tools/browser-tests/rationalgeo-tray-direction.cjs and say
 * that check owned the direction. That file measured the in-flow tray SS-1322
 * introduced, which broke Sean's live post — with INFO open the photograph
 * left the viewport entirely — so the revert deleted it, correctly. Nothing
 * replaced it. Saying so is better than leaving a comment that points at a
 * guard which no longer exists; Muse caught exactly that. SS-1346's successor
 * work needs a rendered direction check at 1920, 1440 and 1024, and until one
 * exists, direction is unguarded.
 *
 * DELIBERATELY NOT ASSERTED HERE: anything about position or offset. If this
 * file ever names a layout mechanic again, it will block the next repair the
 * same way.
 */

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/skins/rational-geo/style.css');
$manifest = json_decode((string) file_get_contents($root . '/skins/rational-geo/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

// The tray must still exist and still scroll its own overflow. Both are true
// whichever direction it opens, so neither can block the repair.
foreach (['.rg-single > #footer', 'overflow-y: auto'] as $hook) {
    if (!str_contains($css, $hook)) {
        throw new RuntimeException("RATIONAL GEO lost its info tray hook: {$hook}");
    }
}

// The article-date presentation this ticket also fixed. Typography, not layout.
foreach (['.rg-single .rg-description', 'margin-bottom: 0', 'font-size: var(--font-scale-lg)', 'color: var(--rg-text-secondary)', 'margin: 0.65rem auto 0'] as $hook) {
    if (!str_contains($css, $hook)) {
        throw new RuntimeException("RATIONAL GEO lost its article-date presentation hook: {$hook}");
    }
}

// DIRECTION IS NOT GUARDED FROM HERE, AND SAYING SO IS THE POINT.
// It is not guarded ANYWHERE at the moment. The browser test that used to
// measure it, tools/browser-tests/rationalgeo-tray-direction.cjs, asserted the
// in-flow tray SS-1322 introduced and the SS-1345 revert deleted it. A
// replacement has to measure the rendered page at 1920, 1440 and 1024, and it
// belongs with the successor work, not here: this file reads source, and
// reading source is what could not settle direction in the first place.

if (version_compare((string)($manifest['version'] ?? '0'), '2.1.40', '<')) {
    throw new RuntimeException('RATIONAL GEO version was not advanced for the info-tray repair.');
}

echo "rational geo info-drawer regression: ok\n";
// ===== SNAPSMACK EOF =====
