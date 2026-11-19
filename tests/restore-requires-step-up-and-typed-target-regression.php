<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1430 — the Recovery Kit import is the most destructive action in the
 * product and it had the weakest gate.
 *
 * It overwrites the database and the files and cannot be undone from the
 * page. Before this it required a CSRF token, which every form on that page
 * carries, and a browser confirm() dialog, which is a line of JavaScript any
 * client can decline to run. A scripted POST, or a mis-clicked button on a
 * page left open all afternoon, reached the SQL.
 *
 * Two controls now, BOTH enforced server-side:
 *   1. reauth_verify() -- password plus TOTP, the shared mechanism every
 *      other consequential action uses, so it inherits the SS-1399 attempt
 *      budget rather than needing its own.
 *   2. A typed confirmation matching this install's site_url. Step-up proves
 *      WHO you are; it cannot prove you meant THIS site. An owner with
 *      several installs has the same password and authenticator for all of
 *      them, and restoring one site's kit over another is the accident with
 *      no undo.
 *
 * WHAT THIS FILE CAN AND CANNOT DO. smack-disaster.php is an admin page: it
 * authenticates a session, sends headers and renders. It cannot be included
 * here and driven. So this reads the handler's SOURCE and asserts the shape
 * of the gate, and separately EXECUTES the two decision functions the gate
 * is built from. That is weaker than driving the endpoint and is called out
 * rather than dressed up; a real POST against a running install is the only
 * thing that would close the gap, and that is owner work.
 *
 * Run: php tests/restore-requires-step-up-and-typed-target-regression.php
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

$src = (string)file_get_contents($root . '/smack-disaster.php');

// The handler only: everything from the import_recovery branch to the end of
// that if block. Asserting against the whole file would let a match in the
// FORM satisfy a claim about the SERVER, which is the exact confusion this
// ticket is about.
$start = strpos($src, "=== 'import_recovery'");
$handler_raw = '';
if ($start !== false) {
    $after = substr($src, $start);
    $end = strpos($after, "\n}\n");
    $handler_raw = $end === false ? $after : substr($after, 0, $end);
}

/**
 * The handler with its comments removed.
 *
 * EVERY code assertion below runs against this, not the raw text. My first
 * version searched the raw handler for 'confirm(' to assert the browser
 * dialog is no longer the only control -- and matched the COMMENT that
 * explains the browser dialog used to be the only control. The check failed
 * on a correct file, because of the sentence documenting the fix.
 *
 * That is the second time today a source assertion of mine could not tell an
 * instruction from a note about an instruction; the first was a build script.
 * So this uses the tokeniser rather than stripping lines that begin with a
 * slash, and it is done ONCE for every check rather than patched at the one
 * that happened to trip.
 */
function handler_code(string $fragment): string {
    if ($fragment === '') return '';
    $out = '';
    foreach (@token_get_all('<?php ' . $fragment) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
            continue;
        }
        $out .= $t;
    }
    return $out;
}
$handler = handler_code($handler_raw);

echo "SS-1430 — restore requires step-up and a typed target, server-side\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the handler was located (nothing below is meaningful otherwise)\n";
// ─────────────────────────────────────────────────────────────────────────────
check('the import_recovery handler was found in the source',
      $handler_raw !== '' && strlen($handler_raw) > 200, $failures);
check('and it is the handler, not the whole file',
      $handler_raw !== '' && strlen($handler_raw) < strlen($src), $failures);
check('the handler does NOT contain the form markup',
      !str_contains($handler_raw, 'type="file"'), $failures);
check('stripping the comments left the code behind, rather than eating it',
      strlen($handler) > 400 && strlen($handler) < strlen($handler_raw), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — both controls are enforced in the handler\n";
// ─────────────────────────────────────────────────────────────────────────────
check('step-up: the handler calls reauth_verify',
      str_contains($handler, 'reauth_verify('), $failures);
check('step-up: it reads the password and TOTP from the POST',
      str_contains($handler, "\$_POST['reauth_password']")
      && str_contains($handler, "\$_POST['reauth_totp']"), $failures);
check('step-up: a failed verification refuses',
      preg_match('/!\$ra\[.ok.\]/', $handler) === 1, $failures);

check('typed target: the handler reads confirm_target from the POST',
      str_contains($handler, "\$_POST['confirm_target']"), $failures);
check('typed target: it is compared with hash_equals, not ==',
      str_contains($handler, 'hash_equals'), $failures);
check('typed target: the comparison is against site_url',
      str_contains($handler, "'site_url'"), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — the destructive call is behind BOTH, and fails closed\n";
// ─────────────────────────────────────────────────────────────────────────────
// The ordering is the control. A gate that refuses after the restore has run
// is not a gate, and this is the one property a reader cannot eyeball.
$import_at   = strpos($handler, 'importRecoveryKit(');
$reauth_at   = strpos($handler, 'reauth_verify(');
$compare_at  = strpos($handler, 'hash_equals');
check('importRecoveryKit is called AFTER reauth_verify',
      $import_at !== false && $reauth_at !== false && $reauth_at < $import_at,
      $failures);
check('importRecoveryKit is called AFTER the typed-target comparison',
      $import_at !== false && $compare_at !== false && $compare_at < $import_at,
      $failures);
check('an empty recorded site address refuses rather than skipping the check',
      preg_match('/\$restore_target === \'\'/', $handler) === 1, $failures);
check('the handler code depends on no browser dialog',
      substr_count($handler, 'confirm(') === 0, $failures);
check('every refusal says nothing was restored',
      substr_count($handler, 'NOTHING WAS RESTORED') >= 3, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — the comparison itself, executed\n";
// ─────────────────────────────────────────────────────────────────────────────
// The decision the gate turns on is a string comparison. Run it rather than
// trust that hash_equals appearing in the file means it behaves.
$target = 'https://photowalk.ing';
$cases = [
    ['https://photowalk.ing',            true,  'the exact address'],
    ['  https://photowalk.ing  ',        true,  'the address with surrounding spaces'],
    ['https://photowalk.ing/',           false, 'a trailing slash'],
    ['http://photowalk.ing',             false, 'the wrong scheme'],
    ['https://Photowalk.ing',            false, 'different capitalisation'],
    ['https://photowalk.ingx',           false, 'an extra character'],
    ['photowalk.ing',                    false, 'the bare host'],
    ['',                                 false, 'nothing typed'],
    ['https://someshitifound.photoblogs.fyi', false, 'another of the owner sites'],
];
foreach ($cases as [$typed, $want, $label]) {
    $got = hash_equals($target, trim($typed));
    check(($want ? 'accepts ' : 'refuses ') . $label, $got === $want, $failures);
}

// A deliberate note on the trailing slash and the capitalisation: both are
// refused. That is stricter than a URL comparison would be, and it is the
// intent -- the owner is confirming a target by copying what the page shows
// them, so an exact match is the signal. A forgiving comparison would accept
// a near miss, and a near miss is precisely what restoring the wrong site
// looks like.

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — the form offers the controls (reachability, not enforcement)\n";
// ─────────────────────────────────────────────────────────────────────────────
foreach (['confirm_target', 'reauth_password', 'reauth_totp'] as $field) {
    check("the form posts {$field}",
          preg_match('/name="' . preg_quote($field, '/') . '"/', $src) === 1, $failures);
}
check('the typed field shows the owner which address to type',
      str_contains($src, '$restore_target_display'), $failures);
check('the display value is read outside the POST handler, so a GET renders it',
      substr_count($src, "'site_url'") >= 2, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1430 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "SS-1430 PASS — the restore requires password, TOTP and the site's own\n";
echo "address typed out, all enforced server-side and all before the SQL runs.\n";
// ===== SNAPSMACK EOF =====
