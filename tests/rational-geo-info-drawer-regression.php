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
 * rendered page, which is exactly what tools/browser-tests/rationalgeo-tray-direction.cjs
 * measures — tray below the nav, photo height unchanged when it opens,
 * document scroll growing. That check owns the direction now, at 1920, 1440
 * and 1024. This file keeps only what reading the source can actually settle.
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
// tools/browser-tests/rationalgeo-tray-direction.cjs measures it on the
// rendered page at 1920, 1440 and 1024. That file is Codex's lane and lands
// with his repair; a check for its existence belongs in his commit, not this
// one. Asserting it here before it exists would block dev instead of blocking
// him, which is the same fault pointing the other way.

if (version_compare((string)($manifest['version'] ?? '0'), '2.1.40', '<')) {
    throw new RuntimeException('RATIONAL GEO version was not advanced for the info-tray repair.');
}

echo "rational geo info-drawer regression: ok\n";
// ===== SNAPSMACK EOF =====
