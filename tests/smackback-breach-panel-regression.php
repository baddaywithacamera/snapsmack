<?php
/**
 * The breach panel must be usable: visible password / 2FA fields with labels,
 * and buttons that are not four stacked full-width slabs (Sean, 2026-09-11:
 * "cannot see where i enter 2fa/pass").
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
function bp_check(string $label, bool $ok): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}
$php = file_get_contents(__DIR__ . '/../smack-back.php');
$css = file_get_contents(__DIR__ . '/../assets/css/admin-theme-geometry-master.css');

bp_check('both step-up fields carry a visible label',
    strpos($php, '<label for="sb-reauth-password">') !== false && strpos($php, '<label for="sb-reauth-totp">') !== false);
bp_check('the step-up fields get an edge on every theme',
    strpos($css, '.sb-stepup-row input[type="password"],') !== false
    && strpos($css, 'border: 1px solid rgba(255, 255, 255, 0.5);') !== false);
bp_check('the authorize gate is inline, not a full-width slab',
    strpos($css, '.sb-stepup-row .btn-smack {') !== false && strpos($css, '.sb-stepup-row') < strpos($css, '.sb-action-row'));
bp_check('per-file actions are compact row actions',
    strpos($css, '.sb-file-actions .btn-smack {') !== false && strpos($php, 'class="sb-file-actions"') !== false);
bp_check('the two closing actions sit side by side',
    strpos($css, '.sb-action-row {') !== false && strpos($php, '<div class="sb-action-row">') !== false);
bp_check('no inline flex styles left on the three panel rows',
    strpos($php, 'style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;"') === false
    && strpos($php, 'style="display:flex;gap:6px;justify-content:flex-end;"') === false);
echo "PASS: smackback breach panel regression\n";
// ===== SNAPSMACK EOF =====
