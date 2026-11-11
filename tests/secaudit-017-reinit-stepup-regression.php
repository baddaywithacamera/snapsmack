<?php
/**
 * SECAUDIT 017 hardening — RE-INITIALISE BASELINE needs the step-up window.
 *
 * smack-back.php guards its two narrow trust actions behind a password + 2FA
 * window: RE-BLESS ONE FILE and REMOVE ONE FILE both check
 * reauth_window_active('smackback_files') and refuse without it.
 *
 * RE-INITIALISE BASELINE FROM DISK did not. It took whatever was on disk at
 * that moment and declared it trustworthy for every monitored file at once —
 * several hundred of them — behind nothing but a browser confirm box.
 *
 * That is the most powerful control on the page and it was the cheapest to
 * reach. An attacker who got as far as the admin could launder tampered files
 * into the trusted baseline permanently, and unlike switching the monitor off
 * it leaves the dashboard green afterwards, so nothing announces it later.
 * Re-blessing ONE file demanded a password; re-blessing EVERY file demanded a
 * click.
 *
 * The panel even warned "Do not use this if a breach is active — it would
 * bless the tampered files", while the handler carried a dedicated branch for
 * exactly that case. Advice in a paragraph is not a control.
 *
 * Also pinned: the attempt is written to the incident log BEFORE the baseline
 * is replaced, with the files that were flagged at the time. Previously the
 * only trace was a single word on the breach row, so a baseline that had
 * absorbed tampered files left no record of which files those were.
 *
 * Static source assertions — no DB, no session, no site.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function sa017(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$src = (string) @file_get_contents($root . '/smack-back.php');
sa017('smack-back.php is readable', $src !== '');
if ($src === '') {
    fwrite(STDERR, "\nSECAUDIT 017: smack-back.php missing — cannot check.\n");
    exit(1);
}

// Locate the three handlers by their POST keys so the checks follow the code
// if it moves, rather than depending on line numbers.
function sa017_handler(string $src, string $postKey): string
{
    $at = strpos($src, "\$_POST['" . $postKey . "']");
    if ($at === false) return '';
    // From the handler's own condition to the exit that ends it.
    $start = strrpos(substr($src, 0, $at), 'if ($_SERVER[');
    if ($start === false) $start = max(0, $at - 400);
    // To the START OF THE NEXT HANDLER, not to the first exit. A gated handler
    // contains an early exit in its refusal branch, and slicing there would cut
    // the slice off before the work the gate protects — which would make every
    // assertion about that work fail for the wrong reason.
    $end = strpos($src, "\nif (\$_SERVER[", $at);
    return $end === false ? substr($src, $start) : substr($src, $start, $end - $start);
}

$reinit  = sa017_handler($src, 'reinit_baseline');
$rebless = sa017_handler($src, 'rebless_file');
$remove  = sa017_handler($src, 'remove_file');

sa017('the re-initialise handler was found', $reinit  !== '');
sa017('the re-bless handler was found',      $rebless !== '');
sa017('the remove-file handler was found',   $remove  !== '');

// ── The reference behaviour: the two narrow actions are already gated ───────
// Asserted so this test fails loudly if the gate they are modelled on is ever
// removed, instead of quietly comparing re-init against nothing.
sa017(
    're-bless one file still requires the step-up window',
    (bool) preg_match("/reauth_window_active\(\s*'smackback_files'\s*\)/", $rebless)
);
sa017(
    'remove one file still requires the step-up window',
    (bool) preg_match("/reauth_window_active\(\s*'smackback_files'\s*\)/", $remove)
);

// ── The fix: the broad action is gated the same way ─────────────────────────
sa017(
    'RE-INITIALISE BASELINE requires the step-up window',
    (bool) preg_match("/reauth_window_active\(\s*'smackback_files'\s*\)/", $reinit)
);
sa017(
    'it loads the reauth helper before checking',
    (bool) preg_match("/require_once\s+'core\/reauth\.php'/", $reinit)
);

// The gate must come BEFORE the baseline is replaced, or it gates nothing.
$gate_at  = strpos($reinit, 'reauth_window_active');
$apply_at = strpos($reinit, 'smackback_init_from_disk');
sa017(
    'the step-up check runs before the baseline is replaced',
    $gate_at !== false && $apply_at !== false && $gate_at < $apply_at
);

// And it must refuse, not merely warn.
sa017(
    'without the window it exits instead of continuing',
    (bool) preg_match('/reauth_window_active[^}]*\{[^}]*exit;/s', $reinit)
);

// ── The attempt is recorded, before the evidence is overwritten ─────────────
sa017(
    'the re-initialise writes an incident-log row',
    (bool) preg_match('/INSERT INTO snap_smackback_log/', $reinit)
);
sa017(
    'the row distinguishes a re-init during an active breach',
    str_contains($reinit, 'reinit_during_breach') && str_contains($reinit, 'reinit_clean')
);

$log_at = strpos($reinit, 'snap_smackback_log');
sa017(
    'the record is written BEFORE the baseline is replaced',
    $log_at !== false && $apply_at !== false && $log_at < $apply_at
);

sa017(
    'the record names the files that were flagged at the time',
    (bool) preg_match('/SELECT file_path FROM snap_file_manifest/', $reinit)
);

// A failed audit write must not lock the owner out of his own recovery path.
sa017(
    'a failed audit write is logged, not fatal',
    (bool) preg_match('/catch\s*\(\s*PDOException[^}]*error_log/s', $reinit)
);

// ── The panel text matches what the code does ──────────────────────────────
sa017(
    'the panel tells the owner it needs password + 2FA',
    (bool) preg_match('/RE-INITIALISE BASELINE.{0,900}password \+ 2FA/s', $src)
);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): the baseline can be replaced wholesale without step-up "
        . "auth, or without leaving a record of what was blessed.\n");
    exit(1);
}
echo "SECAUDIT 017 re-initialise step-up: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
