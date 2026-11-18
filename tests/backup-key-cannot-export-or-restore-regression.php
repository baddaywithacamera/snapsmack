<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * A BACKUP KEY BACKS UP. IT DOES NOT EXPORT THE DATABASE OR RESTORE OVER THE
 * SITE. SS-1414.
 *
 * smack-disaster.php accepted the 'suyb' backup key as a Bearer token.
 * core/api-auth.php returns the moment an accepted key validates, before
 * session auth runs at all, so THE KEY PATH WAS MORE PERMISSIVE THAN THE
 * BROWSER PATH: a browser needed an admin session (the page is in the
 * admin-only list), a key needed only the key. With one, anybody holding an
 * active backup key could pull a full Recovery Kit -- the entire database
 * including snap_users password hashes and any 2FA secrets, plus media and
 * skin -- and could drive import_recovery, which restores over the live site.
 * csrf_exempt() sat at the top of the file for that path's benefit, which took
 * the CSRF token off the import as well.
 *
 * WHY IT WAS SAFE TO CUT, checked rather than assumed: SUYB builds its
 * Recovery Kit CLIENT-SIDE from the SQL dumps and media it fetches through
 * suyb-data.php (backup_engine.py: "Recovery kit built client-side"). Its
 * download_recovery_kit() -- the only thing in the tool that ever posted to
 * this page -- is defined and never called. Nothing else in the repository
 * posts here either.
 *
 * These are SOURCE assertions and say so: the page needs a session, a
 * database and a POST to execute. What they prove is structural -- that no
 * key can reach the page, that the token is required, and that the key still
 * works where backups actually happen, so this narrowed the key rather than
 * breaking it.
 */

$root = dirname(__DIR__);
$failures = [];

$disaster = (string)@file_get_contents($root . '/smack-disaster.php');
if ($disaster === '') {
    throw new RuntimeException('could not read smack-disaster.php');
}
// Comments explain the fix and name the things being removed, so every match
// below is against CODE. php_strip_whitespace keeps string literals.
$code = (string)php_strip_whitespace($root . '/smack-disaster.php');

// ── 1. NO KEY PATH AT ALL ────────────────────────────────────────────────
if (str_contains($code, 'SNAP_API_KEY_TYPES')) {
    $failures[] = 'smack-disaster.php declares an accepted API key type again. '
        . 'Any key it names can export the whole database and restore over the '
        . 'live site without an admin session.';
}
if (preg_match('#api-auth\.php#', $code)) {
    $failures[] = 'smack-disaster.php includes core/api-auth.php, which returns '
        . 'before session auth whenever a key validates';
}
if (str_contains($code, 'csrf_exempt(')) {
    $failures[] = 'csrf_exempt() is back on smack-disaster.php. It existed only '
        . 'for the keyed export; with it the destructive import loses its token.';
}
if (str_contains($code, 'SNAP_API_AUTH')) {
    $failures[] = 'smack-disaster.php still branches on SNAP_API_AUTH, so a key '
        . 'path is being catered for somewhere in it';
}

// ── 2. THE SESSION GATE IS THE FIRST THING THAT RUNS ─────────────────────
$authAt = strpos($code, 'auth-smack.php');
if ($authAt === false) {
    $failures[] = 'smack-disaster.php no longer requires core/auth-smack.php, so '
        . 'nothing checks that the caller is an admin';
}
foreach (['export', 'import_recovery'] as $action) {
    $at = strpos($code, "'" . $action . "'");
    if ($at === false) {
        $failures[] = "the '{$action}' handler is gone from smack-disaster.php; "
            . 'this test no longer covers what it claims to';
        continue;
    }
    if ($authAt !== false && $authAt > $at) {
        $failures[] = "the '{$action}' handler runs before the admin check";
    }
}
if (!str_contains($code, 'csrf_verify()')) {
    $failures[] = 'the import no longer verifies a CSRF token';
}
if (preg_match('/if\s*\(\s*!\s*defined\s*\(\s*[\'"]SNAP_API_AUTH/', $code)) {
    $failures[] = 'the CSRF verify is conditional again. It was only conditional '
        . 'because the keyed export could not supply a token; there is no such '
        . 'caller now, so the token is simply required.';
}

// ── 3. THE PAGE IS STILL ADMIN-ONLY FOR BROWSERS ─────────────────────────
// The session half of the gate lives in the denylist, not in this file. If it
// were dropped, removing the key path would have moved the hole rather than
// closed it.
$auth = (string)@file_get_contents($root . '/core/auth-smack.php');
if (!str_contains($auth, "'smack-disaster.php'")) {
    $failures[] = 'smack-disaster.php has fallen out of the admin-only list in '
        . 'core/auth-smack.php, so an editor session can now reach it';
}
if (!str_contains($auth, 'csrf_check();')) {
    $failures[] = 'core/auth-smack.php no longer runs csrf_check() for admin '
        . 'POSTs, which is what protects the export now';
}

// ── 4. NO OTHER PAGE OFFERS THE SAME THING TO A KEY ──────────────────────
// Closing one door is not the job; the job is that no keyed endpoint exports
// the database or restores a kit. Every page that accepts a key type is
// checked for the two recovery entry points.
// THIS SWEEP FOUND A SECOND ENDPOINT MUSE'S REPORT DID NOT NAME:
// suyb-export.php?type=kit reached exportRecoveryKit on the same key. A
// two-file repair is not product-wide verification, so the rule is the sweep,
// not the named file.
$offenders = [];
$swept = 0;
foreach ((array)glob($root . '/*.php') as $file) {
    $stripped = (string)php_strip_whitespace($file);
    if (!str_contains($stripped, 'SNAP_API_KEY_TYPES')) continue;
    $swept++;
    foreach (['exportRecoveryKit', 'importRecoveryKit'] as $entry) {
        $at = strpos($stripped, $entry);
        if ($at === false) continue;
        // Reaching it is allowed only if the file refuses key auth SOMEWHERE
        // before it. Deliberately a coarse rule: it is the net that catches a
        // NEW keyed endpoint wired straight to recovery with no guard at all.
        // The exact placement in the one file that is allowed to reach it is
        // checked on its own below, which is the precise half.
        $guard = strpos($stripped, 'SNAP_API_AUTH');
        if ($guard === false || $guard > $at) {
            $offenders[] = basename($file) . ' -> ' . $entry;
        }
    }
}
if ($swept < 3) {
    $failures[] = "only {$swept} keyed endpoints were swept, so this check is "
        . 'not reading the repository root properly rather than there being '
        . 'almost none';
}
if ($offenders) {
    $failures[] = 'a keyed endpoint reaches recovery export or restore with no '
        . 'refusal of key auth in front of it: ' . implode(', ', $offenders);
}

// And the one that is allowed must still refuse, in so many words.
$export = (string)php_strip_whitespace($root . '/suyb-export.php');
if (!preg_match('/\$type\s*===\s*.kit./', $export)) {
    $failures[] = 'suyb-export.php no longer has a type=kit branch; this check '
        . 'no longer covers what it claims';
} elseif (!preg_match('/defined\s*\(\s*.SNAP_API_AUTH.\s*\)/', $export)) {
    $failures[] = 'suyb-export.php serves type=kit to a backup key again';
}
// The backup itself must keep working through the same endpoint. Checked
// against the ACCEPTED-TYPE ALLOWLIST, not the whole file: 'full' and 'file'
// appear in the dump code regardless, so searching the file at large passed
// happily with the allowlist gutted. I only found that by trying it.
$allowAt = strpos($export, 'in_array($type');
$allowList = $allowAt === false ? '' : substr($export, $allowAt, 160);
if ($allowList === '') {
    $failures[] = 'suyb-export.php no longer validates $type against a list, so '
        . 'this check cannot tell what it serves';
}
foreach (['full', 'schema', 'inventory', 'file'] as $kept) {
    if (!str_contains($allowList, "'" . $kept . "'")) {
        $failures[] = "suyb-export.php no longer accepts type={$kept}, which the "
            . 'backup tool depends on. SS-1414 narrows the backup key; it must '
            . 'not take the backup away.';
    }
}

// ── 5. THE KEY STILL WORKS WHERE BACKUPS HAPPEN ──────────────────────────
// This is the half that keeps the repair from being a regression. SUYB's
// backup runs through suyb-data.php with the same key; if that stopped
// accepting it, the fleet would quietly stop backing up and the first anyone
// would know is when a restore was needed.
foreach (['suyb-data.php', 'suyb-complete.php'] as $endpoint) {
    $body = (string)@file_get_contents($root . '/' . $endpoint);
    if ($body === '') {
        $failures[] = "could not read {$endpoint}";
        continue;
    }
    if (!preg_match('/SNAP_API_KEY_TYPES.{0,80}[\'"]suyb[\'"]/s', $body)) {
        $failures[] = "{$endpoint} no longer accepts the 'suyb' key. SS-1414 was "
            . 'supposed to narrow that key to backups, not take its backups away.';
    }
}

// ── 6. THE CLAIM THIS REPAIR RESTS ON, RECORDED ──────────────────────────
// "SUYB does not need the server-side export" is the reason it was safe to
// remove. If someone later wires the tool's dead download_recovery_kit() into
// the backup flow, this fails and points at the right file.
$engine = (string)@file_get_contents($root . '/tools/smack-up-your-backup/backup_engine.py');
if ($engine === '') {
    $failures[] = 'could not read SUYB backup_engine.py to confirm the backup '
        . 'path does not need the server-side export';
} else {
    if (!str_contains($engine, 'Recovery kit built client-side')) {
        $failures[] = 'SUYB no longer reports building the kit client-side; the '
            . 'assumption behind SS-1414 needs re-checking before trusting it';
    }
    // Defined is fine. CALLED is not: the endpoint will refuse it.
    $calls = preg_match_all('/download_recovery_kit\s*\(/', $engine, $m);
    $defs = preg_match_all('/def\s+download_recovery_kit\s*\(/', $engine, $m2);
    if ($calls > $defs) {
        $failures[] = 'SUYB now CALLS download_recovery_kit(), which posts to '
            . 'smack-disaster.php. That page refuses keys since SS-1414, so the '
            . 'backup would fail. Fetch the kit through suyb-data.php or build '
            . 'it client-side as the rest of the flow does.';
    }
}

if ($failures) {
    throw new RuntimeException("A backup key can still export or restore:\n  - "
        . implode("\n  - ", $failures));
}

echo "Backup key: no recovery export, no restore, CSRF required, backups "
    . "unaffected: passed.\n";
// ===== SNAPSMACK EOF =====
