<?php
declare(strict_types=1);

/**
 * SECAUDIT 064 — a breached site refuses API writes.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 *
 * THE GAP. smackback_is_breach() is called in two places: core/smackback.php
 * and smack-admin.php:34. Measured across the whole API surface, no write
 * endpoint checked it. A tampered site kept accepting posts, comments,
 * mosaics, skin reinstalls and enrollments through the API while its own
 * dashboard said BREACH.
 *
 * MOST OF THIS FILE RUNS THE GATE, IT DOES NOT READ IT. The three states are
 * exercised against a real PDO — an in-memory SQLite database, built four
 * different ways — and the decision function is called with every combination
 * of inputs. Only the wiring assertions at the end read source, because where
 * a call sits in a file is a fact about the file.
 *
 * THE STATE THAT MATTERS MOST IS 'unknown'. The PM's condition was that
 * "cannot read" must not be implemented as "row not found". Those are opposite
 * answers: a site that has never been checked must keep working, and a site
 * whose integrity state cannot be read must not be trusted with writes.
 * smackback_is_breach() returns a bool and cannot tell them apart. Proved here
 * against a database with no table at all versus a table with no row.
 */

$root  = dirname(__DIR__);
$fails = 0;

function s64(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

require_once $root . '/core/breach-write-gate.php';

// ── THE CONTRACT ───────────────────────────────────────────────────────────
// Approved by the PM on 2026-11-11. Desktop tools key on the string, so it is
// fixed the moment it ships; a later change breaks installed GYSS and SYBU.
s64('the refusal status is 423',  SNAP_BREACH_GATE_STATUS === 423);
s64('the machine code is breach_write_lock',
    SNAP_BREACH_GATE_CODE === 'breach_write_lock');
s64('423 is not 403 — the site is locked, not the caller forbidden',
    SNAP_BREACH_GATE_STATUS !== 403);
s64('the human reason names the two things that still work',
    str_contains(SNAP_BREACH_GATE_REASON, 'Read')
    && str_contains(SNAP_BREACH_GATE_REASON, 'signed update'));

// ── THE THREE STATES, AGAINST A REAL DATABASE ──────────────────────────────
function s64_pdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// 1. No table at all: the read THROWS. This is the bricking case.
$no_table = s64_pdo();
$state_unreadable = snap_breach_write_state($no_table);
s64("a database that cannot be read is 'unknown'", $state_unreadable === 'unknown');

// 2. Table present, no row: an uninitialised site. Never checked, not breached.
$empty = s64_pdo();
$empty->exec('CREATE TABLE snap_settings (setting_key TEXT, setting_val TEXT)');
$state_norow = snap_breach_write_state($empty);
s64("a site with no row is 'clear'", $state_norow === 'clear');

// 3. THE ASSERTION THE WHOLE RULING TURNS ON.
s64(
    "'cannot read' and 'row not found' are DIFFERENT states",
    $state_unreadable !== $state_norow
);
s64(
    "and they produce OPPOSITE decisions: unreadable refuses, missing allows",
    snap_breach_gate_refuses($state_unreadable) === true
    && snap_breach_gate_refuses($state_norow) === false
);

// 4. The row says breach.
$breached = s64_pdo();
$breached->exec('CREATE TABLE snap_settings (setting_key TEXT, setting_val TEXT)');
$breached->exec("INSERT INTO snap_settings VALUES ('smackback_status','breach')");
s64("a breached site is 'breach'", snap_breach_write_state($breached) === 'breach');
s64('and it refuses', snap_breach_gate_refuses('breach') === true);

// 5. The row says something else. Only 'breach' means breach.
foreach (['clean', 'ok', '', 'BREACHED', 'warn'] as $value) {
    $other = s64_pdo();
    $other->exec('CREATE TABLE snap_settings (setting_key TEXT, setting_val TEXT)');
    $st = $other->prepare('INSERT INTO snap_settings VALUES (?,?)');
    $st->execute(['smackback_status', $value]);
    s64(
        "setting_val '" . $value . "' is not a breach",
        snap_breach_write_state($other) === 'clear'
    );
}

// ── THE DECISION, EVERY COMBINATION ────────────────────────────────────────
// snap_breach_gate_decides_refusal(state, method, authorization, exempt)

// Reads are never gated, in any state. SMACK CENTRAL informs; locking reads
// blinds the thing that tells the owner they have a problem.
foreach (['GET', 'HEAD', 'OPTIONS', 'get'] as $method) {
    s64(
        'a ' . strtoupper($method) . ' is never refused, even in breach',
        snap_breach_gate_decides_refusal('breach', $method, true, false) === false
    );
}

// Writes in breach, authorised, not exempt: refuse. The whole point.
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    s64(
        'a ' . $method . ' write in breach is refused',
        snap_breach_gate_decides_refusal('breach', $method, true, false) === true
    );
}
s64(
    'a write whose state is unreadable is refused',
    snap_breach_gate_decides_refusal('unknown', 'POST', true, false) === true
);
s64(
    'a write on a clear site is allowed',
    snap_breach_gate_decides_refusal('clear', 'POST', true, false) === false
);

// No Authorization header: say nothing. This is the oracle rule — a caller
// with no key must not be able to learn the site is breached, and one with a
// key is already trusted enough to be told why it was refused.
s64(
    'a write with NO Authorization header is not refused by this gate',
    snap_breach_gate_decides_refusal('breach', 'POST', false, false) === false
);
s64(
    'the same request WITH an Authorization header is refused',
    snap_breach_gate_decides_refusal('breach', 'POST', true, false) === true
);

// Exempt beats state.
s64(
    'an exempt action is allowed even in breach',
    snap_breach_gate_decides_refusal('breach', 'POST', true, true) === false
);
s64(
    'an exempt action is allowed even when the state is unreadable',
    snap_breach_gate_decides_refusal('unknown', 'POST', true, true) === false
);

// ── THE AUTHORIZATION LOOKUP ───────────────────────────────────────────────
// nginx and PHP-FPM strip HTTP_AUTHORIZATION, which is why multisite already
// falls back. A gate reading only the first key would be silent on the hosts
// the fleet actually runs on.
s64('an Authorization header is seen',
    snap_breach_gate_authorization_present(['HTTP_AUTHORIZATION' => 'Bearer abc']) === true);
s64('the nginx REDIRECT_ fallback is seen',
    snap_breach_gate_authorization_present(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc']) === true);
s64('an empty header is not a header',
    snap_breach_gate_authorization_present(['HTTP_AUTHORIZATION' => '   ']) === false);
s64('no header at all is no header',
    snap_breach_gate_authorization_present([]) === false);

// ── THE FRONT-DOOR EXEMPTIONS ──────────────────────────────────────────────
// Default is GATED: every write route is refused unless named. A surface added
// next year is then protected by default rather than forgotten.
foreach (['multisite', 'multisite/posts/create', 'stats', 'stats/dwell'] as $route) {
    s64('front door defers or exempts: ' . $route,
        snap_breach_gate_front_door_exempt($route) === true);
}
foreach (['gyss', 'smackpress', 'bloggerflogger', 'flkrfckr', 'ohsnap',
          'smackthemup', 'threeacross', 'unzucker', 'tyswy', 'desktop-auth',
          'something-invented-next-year'] as $route) {
    s64('front door gates: ' . $route,
        snap_breach_gate_front_door_exempt($route) === false);
}

// ── desktop-auth, ONE ASSERTION PER ACTION ─────────────────────────────────
// PM ruling 2026-11-11. The four actions are not alike: two keep an existing
// device alive, two grant a new one access. core/desktop-auth.php:218 puts
// status and renew in one branch; :223 is where status renews the term inside
// the last 30 days, which is the reason the exemption exists at all.
//
// EXEMPT -- the keep-alive path. Gating these lapses the signed entitlement
// and SNAP SLAPPER with it, within days for a device near term end.
foreach (['status', 'renew'] as $action) {
    s64(
        'desktop-auth/' . $action . ' is EXEMPT (keep-alive for a trusted device)',
        snap_breach_gate_desktop_auth_exempt('desktop-auth/' . $action) === true
    );
    s64(
        'and the front door agrees for ' . $action,
        snap_breach_gate_front_door_exempt('desktop-auth/' . $action) === true
    );
}

// GATED -- new device access on a tampered site.
foreach (['activate', 'enroll'] as $action) {
    s64(
        'desktop-auth/' . $action . ' is GATED (new device access)',
        snap_breach_gate_desktop_auth_exempt('desktop-auth/' . $action) === false
    );
    s64(
        'and the front door agrees for ' . $action,
        snap_breach_gate_front_door_exempt('desktop-auth/' . $action) === false
    );
}

// The dispatcher lowercases the action (:131-132). If the gate did not, a
// request to /desktop-auth/STATUS would be refused here and handled as
// `status` there -- the gate and the code it guards disagreeing about one
// request, which is worse than either answer on its own.
foreach (['STATUS', 'Status', 'ReNeW'] as $action) {
    s64(
        'case does not change the answer: desktop-auth/' . $action,
        snap_breach_gate_desktop_auth_exempt('desktop-auth/' . $action) === true
    );
}
s64(
    'a mixed-case WRITE action is still gated',
    snap_breach_gate_desktop_auth_exempt('desktop-auth/ACTIVATE') === false
);

// The bare prefix exempts nothing: no action means the dispatcher 404s anyway,
// and a blanket desktop-auth exemption would let activate and enroll through.
s64('the bare desktop-auth prefix is NOT exempt',
    snap_breach_gate_desktop_auth_exempt('desktop-auth') === false);
s64('and a lookalike route is not caught',
    snap_breach_gate_desktop_auth_exempt('gyss/status') === false);

// ── THE MULTISITE EXEMPTIONS ───────────────────────────────────────────────
// run-crons: SS-1055. multisite-api.php:530 is a bare resource check with NO
// method, so this must match by resource or it is missed entirely.
foreach (['POST', 'GET', 'PUT', 'DELETE'] as $method) {
    s64(
        'run-crons is exempt whatever the method (' . $method . ')',
        snap_breach_gate_multisite_exempt('run-crons', '') === true
    );
}
s64('the signed update is exempt — it is the way out',
    snap_breach_gate_multisite_exempt('updates', 'trigger') === true
    && snap_breach_gate_multisite_exempt('updates', 'finalize') === true
    && snap_breach_gate_multisite_exempt('updates', 'track') === true);
s64('a spoke can still raise the alarm',
    snap_breach_gate_multisite_exempt('smackback', 'report') === true);

// Everything else multisite writes IS gated. These are the actions an attacker
// would want on a site already known to be tampered with.
foreach ([
    ['posts', 'create'], ['comments', 'action'], ['comments', 'reply'],
    ['blogroll', 'sync'], ['maintenance', 'set'], ['skins', 'reinstall'],
    ['skins', 'reinstall-all'], ['jobs', 'run'], ['handshake', ''],
    ['fediverse', 'relay-join'], ['fediverse', 'relay-admit'],
    ['backup', 'report'],
] as [$resource, $sub]) {
    s64('multisite gates: ' . $resource . '/' . $sub,
        snap_breach_gate_multisite_exempt($resource, $sub) === false);
}
// 'smackback' is only exempt for report, not wholesale.
s64('smackback is not exempt wholesale',
    snap_breach_gate_multisite_exempt('smackback', 'anything-else') === false);

// ── THE WIRING ─────────────────────────────────────────────────────────────
$api = (string) @file_get_contents($root . '/api.php');
s64('api.php is readable', $api !== '');

$gate_at  = strpos($api, 'snap_breach_write_gate(');
$first_surface = strpos($api, "require_once 'core/");
s64('api.php calls the front-door gate', $gate_at !== false);
s64(
    'and it runs BEFORE any surface is required',
    $gate_at !== false && $first_surface !== false && $gate_at < $first_surface
);

$ms = (string) @file_get_contents($root . '/core/multisite-api.php');
s64('multisite-api.php calls the action-aware gate',
    str_contains($ms, 'snap_breach_write_gate_multisite('));
$ms_gate = strpos($ms, 'snap_breach_write_gate_multisite(');
$ms_route = strpos($ms, "if (\$resource === 'handshake'");
s64(
    'and it runs before the first multisite action',
    $ms_gate !== false && $ms_route !== false && $ms_gate < $ms_route
);

// THE PLACEMENT CONSTRAINT: smack-update.php deliberately bypasses the
// maintenance lock and is how an owner repairs a breached install. If the gate
// ever reached it, a breached site could not be updated — the lock would close
// the only door out.
$upd = (string) @file_get_contents($root . '/smack-update.php');
s64('smack-update.php is readable', $upd !== '');
s64(
    'smack-update.php does NOT load the gate',
    $upd !== '' && !str_contains($upd, 'breach-write-gate')
);
s64(
    'and it does not go through api.php either',
    $upd !== '' && !preg_match("#require(_once)?[^;]*['\"]api\.php['\"]#", $upd)
);

// The gate must not be a PDO or write interceptor. tyswy writes three times
// inside its own auth path, all swallowing failure; a write-level gate would
// break them silently.
$gate_src = (string) @file_get_contents($root . '/core/breach-write-gate.php');
s64(
    'the gate does not wrap PDO',
    !preg_match('/extends\s+PDO|class\s+\w*PDO/i', $gate_src)
);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): the breach write gate does not refuse what it "
        . "must, or refuses what must keep working. The three exemptions are the signed "
        . "update, the spoke's breach report and run-crons; removing any of them locks an "
        . "owner out of repairing their own site, or takes fleet cron down.\n");
    exit(1);
}
echo "SECAUDIT 064 breach write gate: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
