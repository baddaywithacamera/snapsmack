<?php
/**
 * Social dock ↔ community heart alignment (0.7.643D) — the right-bottom dock
 * column's resting position mirrors the heart (bottom 84px / inset 30px), and
 * the JS clamp may only RAISE the dock above that to clear the bottom nav,
 * never drop it below. Harness-verified 2026-09-05 (heart bottom == last icon
 * bottom, exact).
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$css  = file_get_contents($root . '/assets/css/ss-engine-social-dock.css');
$js   = file_get_contents($root . '/assets/js/ss-engine-social-dock.js');
$comm = file_get_contents($root . '/assets/css/ss-community.css');

$fail = 0;
function sd_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

sd_test(preg_match('/\.dock-right-bottom \{[^}]*right: 30px;[^}]*bottom: 84px;/s', $css) === 1,
    'dock-right-bottom rests at right 30 / bottom 84 — mirrors the heart');
sd_test(preg_match('/\.ss-cdock-bottom-left \{[^}]*bottom: 84px;[^}]*left: 30px;/s', $comm) === 1,
    'the heart it mirrors is still at left 30 / bottom 84 (change BOTH or neither)');
sd_test(str_contains($js, 'var restingBottom'),
    'JS captures the CSS resting bottom before any inline override');
sd_test(str_contains($js, 'Math.max(restingBottom, vp - footerTop + EDGE_GAP)'),
    'the clamp only ever raises the dock above its resting position');

echo $fail === 0 ? "ALL PASS\n" : ("{$fail} FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
