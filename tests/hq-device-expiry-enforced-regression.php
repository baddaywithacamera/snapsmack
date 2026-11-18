<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1418 — SNAP HQ device expiry is enforced, AND THE OWNER IS NOT LOCKED OUT.
 *
 * Muse's finding (K9LqMC5p): the server authenticated a device by status,
 * signature and nonce and never looked at expires_at or grace_ends_at, while
 * status/renew reissued a full three-month term anchored to the moment of
 * asking -- so a device could not actually expire, and the expiry controls
 * did not do what they were documented to do.
 *
 * THE DANGER IN FIXING IT is the reason this file leads with the lockout
 * cases rather than the security ones. status and renew are reached THROUGH
 * da_auth_device(). An expiry check that refuses there would make renewal
 * unreachable by the only device that ever needs it, and the owner -- who
 * lives in these tools -- would open SNAP HQ to find the fix had locked him
 * out of his own site. The PM's instruction was explicit: a device that is
 * expired under the corrected rules gets a clear renew prompt and a grace
 * period, never a hard stop. STAGE 1 is that case.
 *
 * WHAT IS EXECUTED AND WHAT IS NOT, stated plainly:
 *   - EXECUTED: da_term_state(), da_next_expiry(), da_in_remediation() and
 *     da_enforcement_began(), against real values and a real SQLite settings
 *     table. These hold the whole decision.
 *   - NOT EXECUTED: the HTTP endpoint itself. core/desktop-auth.php sends
 *     headers and dispatches at include time, and da_out() is `: never` --
 *     it exits -- so a 403 cannot be caught in-process. The wiring that
 *     connects the decisions to the endpoint is pinned by reading the
 *     source instead, in STAGE 5. That is a weaker check than running it,
 *     and it is called out rather than dressed up.
 *   - NOT CHECKED anywhere here: a live site, a real device, an installed
 *     build, and MariaDB semantics (da_set() uses ON DUPLICATE KEY UPDATE,
 *     which SQLite does not have; see the note in STAGE 4).
 *
 * Run: php tests/hq-device-expiry-enforced-regression.php
 */

$root = dirname(__DIR__);
$failures = [];

function check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

// ── Load the helpers WITHOUT running the endpoint ────────────────────────────
// Everything above the dispatch is function definitions. Take that prefix,
// drop the two require_once lines and the header() calls (db.php wants a live
// connection; headers are meaningless on the CLI), and define the rest.
$src = (string)file_get_contents($root . '/core/desktop-auth.php');
$cut = strpos($src, "\nda_ensure(\$pdo);");
if ($cut === false) {
    fwrite(STDERR, "SS-1418 FAILED — core/desktop-auth.php no longer dispatches with da_ensure(\$pdo);\n"
                 . "The loader below depends on that line to separate definitions from the endpoint.\n");
    exit(1);
}
$defs = substr($src, 0, $cut);
$defs = preg_replace('/^\s*require_once .*$/m', '', $defs);
$defs = preg_replace('/^\s*header\(.*$/m', '', $defs);
$defs = preg_replace('/^<\?php/', '', (string)$defs, 1);
eval((string)$defs);

$DAY = 86400;
$NOW = 1795000000;

/** A device row as the table holds it. */
function device(int $expires_at, int $grace_ends_at, string $status = 'active'): array {
    return ['id' => 'dev-1', 'status' => $status,
            'expires_at'    => gmdate('Y-m-d H:i:s', $expires_at),
            'grace_ends_at' => gmdate('Y-m-d H:i:s', $grace_ends_at)];
}

echo "SS-1418 — HQ device expiry enforced, owner not locked out\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE OWNER IS NOT LOCKED OUT (the PM's hard requirement)\n";
// ─────────────────────────────────────────────────────────────────────────────
// Sean's own device, on the morning this lands. Three cases, and none of them
// may end in a hard stop.

// (a) Past expiry, inside the signed grace window: still authorized, still
//     able to renew itself without a password.
$in_grace = device($NOW - 3 * $DAY, $NOW + 11 * $DAY);
$t = da_term_state($in_grace, $NOW);
check('grace: a device one day past expiry is in GRACE, not expired',
      $t['state'] === 'grace', $failures);
check('grace: it may still renew itself',
      in_array($t['state'], ['current', 'grace'], true), $failures);

// (b) Past grace, but it got there before enforcement existed. This is the
//     case the PM named. It must get a window, not a refusal.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)");
$enforcement_began = $NOW;      // the moment this code first ran on his site
$pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('desktop_auth_expiry_enforced_from', ?)")
    ->execute([(string)$enforcement_began]);

$long_dead = device($NOW - 400 * $DAY, $NOW - 386 * $DAY);
$t_dead = da_term_state($long_dead, $NOW);
check('stranded: a device whose grace ended a year ago reads as EXPIRED',
      $t_dead['state'] === 'expired', $failures);
check('stranded: but it IS inside the remediation window on day one',
      da_in_remediation($pdo, $t_dead, $NOW) === true, $failures);
check('stranded: and still inside it 13 days later',
      da_in_remediation($pdo, $t_dead, $NOW + 13 * $DAY) === true, $failures);

// (c) The renewal it is then given has to actually reach the future. Anchoring
//     to a year-dead expiry would hand it a term that is still expired -- a
//     renew button that changes nothing is the lockout wearing a different hat.
$anchor  = max($t_dead['expires_at'], $enforcement_began);
$renewed = da_next_expiry($anchor, $NOW);
check('stranded: renewing actually moves the expiry into the future',
      $renewed > $NOW, $failures);
check('stranded: and gives the full documented term, not a stub',
      $renewed === $NOW + DA_TERM_SECONDS, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — expiry is read at all (the finding)\n";
// ─────────────────────────────────────────────────────────────────────────────
$current = device($NOW + 40 * $DAY, $NOW + 54 * $DAY);
check('current: inside the term reads as current',
      da_term_state($current, $NOW)['state'] === 'current', $failures);
check('expired: past expiry AND past grace reads as expired',
      da_term_state(device($NOW - 30 * $DAY, $NOW - 16 * $DAY), $NOW)['state'] === 'expired',
      $failures);
check('boundary: the last second of the term is still current',
      da_term_state(device($NOW, $NOW + 14 * $DAY), $NOW)['state'] === 'current', $failures);
check('boundary: the last second of grace is still grace',
      da_term_state(device($NOW - $DAY, $NOW), $NOW)['state'] === 'grace', $failures);
check('boundary: one second past grace is expired',
      da_term_state(device($NOW - $DAY, $NOW - 1), $NOW)['state'] === 'expired', $failures);

// A row whose grace ends before its own expiry is malformed. It must not
// shorten the owner's term; give the documented grace from the real expiry.
$weird = da_term_state(device($NOW + 10 * $DAY, $NOW - 50 * $DAY), $NOW);
check('malformed row: grace before expiry does not strand the device',
      $weird['state'] === 'current'
      && $weird['grace_ends_at'] === $NOW + 10 * $DAY + DA_GRACE_SECONDS, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — renewal is anchored and capped (the second finding)\n";
// ─────────────────────────────────────────────────────────────────────────────
// The old line was: $exp = strtotime('+3 months'), whatever the prior term.
// So checking in reset the clock, and expiry could never arrive.
$prior = $NOW + 80 * $DAY;          // renewing early, 80 days still to run
check('early renewal cannot stockpile term beyond the documented window',
      da_next_expiry($prior, $NOW) === $NOW + DA_TERM_SECONDS, $failures);

// WHICH OF THE TWO BINDS, AND WHEN. I first asserted that an ordinary
// renewal lands on prior + term, and it does not: for any device that has
// not yet expired, prior > now, so the cap at now + term is the smaller of
// the two and wins. That is correct and is the documented behaviour -- "keep
// an actively checking installation licensed three months ahead" -- and it
// is also why the cap is the half of this that does the security work. The
// anchor binds only where prior < now, which is precisely the expired device
// the old code was resurrecting.
$nearly_due = $NOW + 5 * $DAY;      // the ordinary case: renewing near the end
check('an ordinary renewal is capped at the documented window from now',
      da_next_expiry($nearly_due, $NOW) === $NOW + DA_TERM_SECONDS, $failures);
check('and it never shortens a term the owner already holds',
      da_next_expiry($nearly_due, $NOW) >= $nearly_due, $failures);

$dead = $NOW - 400 * $DAY;          // what the old code silently resurrected
check('an expired term cannot be renewed into the future by anchoring alone',
      da_next_expiry($dead, $NOW) < $NOW, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — the remediation window is ONE window, not a renewable one\n";
// ─────────────────────────────────────────────────────────────────────────────
check('remediation has closed once the window has run',
      da_in_remediation($pdo, $t_dead, $NOW + DA_GRACE_SECONDS + 1) === false, $failures);

// A device that expires AFTER enforcement began had its fair warning, so it
// never gets the window at all. Otherwise every device would get one forever.
$expired_later = da_term_state(device($enforcement_began + 5 * $DAY,
                                      $enforcement_began + 19 * $DAY),
                               $enforcement_began + 40 * $DAY);
check('a device that expired AFTER enforcement began gets no remediation',
      da_in_remediation($pdo, $expired_later, $enforcement_began + 40 * $DAY) === false,
      $failures);
check('and a device that is merely in grace is never in remediation',
      da_in_remediation($pdo, da_term_state($in_grace, $NOW), $NOW) === false, $failures);

// The stamp is read, never rewritten, so the window cannot be restarted.
$stamp_before = $pdo->query("SELECT setting_val FROM snap_settings
                             WHERE setting_key='desktop_auth_expiry_enforced_from'")->fetchColumn();
da_in_remediation($pdo, $t_dead, $NOW + 3 * $DAY);
$stamp_after = $pdo->query("SELECT setting_val FROM snap_settings
                            WHERE setting_key='desktop_auth_expiry_enforced_from'")->fetchColumn();
check('the enforcement stamp is never moved by a later check',
      $stamp_before === $stamp_after, $failures);
// NOTE, not a check: the first-write branch of da_enforcement_began() calls
// da_set(), which uses ON DUPLICATE KEY UPDATE -- MySQL and MariaDB only. It
// cannot run here, so only the read path is executed. Codex: that branch
// wants a MariaDB run.

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — the endpoint is wired to these decisions (source, not runtime)\n";
// ─────────────────────────────────────────────────────────────────────────────
check('da_auth_device consults the term state',
      str_contains($src, 'da_term_state($d, $now)'), $failures);
check('da_auth_device refuses an expired device by default',
      preg_match('/term\[.state.\]\s*===\s*.expired.\s*&&\s*!\$allow_expired/', $src) === 1,
      $failures);
check('...and tells the client to renew rather than just refusing',
      str_contains($src, "'renew_required'=>true"), $failures);
check('status/renew pass allow_expired, so renewal stays reachable',
      str_contains($src, 'da_auth_device($pdo,$raw,true)'), $failures);
check('renewal no longer anchors the new term to now',
      !str_contains($src, "\$exp=date('Y-m-d H:i:s',strtotime('+3 months'))"), $failures);
check('renewal goes through da_next_expiry',
      str_contains($src, 'da_next_expiry($anchor, $now)'), $failures);
check('the response reports the term state to the desktop',
      str_contains($src, "'term_state'=>\$final['state']"), $failures);

// The desktop half: clock rollback, checked where the decision is made.
$py = (string)file_get_contents($root . '/tools/_shared/snap_device_auth.py');
$dstart = strpos($py, 'def decision(');
$dbody  = $dstart === false ? '' : substr($py, $dstart);
check('decision() reads last_clock, not only _verify_and_store()',
      str_contains($dbody, 'last_clock'), $failures);
check('decision() refuses a backwards clock',
      str_contains($dbody, 'clock_rollback'), $failures);
check('refresh() drops the cached entitlement on an explicit 403',
      preg_match('/response\.status_code == 403.*?_write_state/s', $py) === 1, $failures);
check('...and only on a 403, so a dropped connection is not a revocation',
      substr_count($py, 'response.status_code == 403') === 1, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1418 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "SS-1418 PASS — expiry and grace are read and enforced, renewal is anchored\n";
echo "to the previous term and capped, a device stranded by the change gets one\n";
echo "clear window to renew itself, and the owner is never hard-stopped.\n";
// ===== SNAPSMACK EOF =====
