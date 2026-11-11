<?php
/**
 * SS-1198 — the bootstrap deployer must say what failed, where, and why.
 *
 * setup.php downloads the signed release and writes it to disk. When that write
 * failed it reported only "check that the web server has write permission to
 * <directory>". That is wrong as often as it is right: a read-only mount, a
 * missing directory, a full disk, open_basedir and SELinux all land on the same
 * line and not one of them is fixed by chmod.
 *
 * It matters more here than anywhere else in the product. The person reading it
 * is standing in a browser on an empty directory with no SnapSmack installed —
 * so no logs, no admin, nowhere else to look. The message is all they have.
 *
 * WHAT IS PINNED:
 *   - the failing OPERATION is named, not just a symptom;
 *   - the exact FULL TARGET appears, not merely its directory;
 *   - the filesystem REASON PHP gave is included;
 *   - "Permission denied" adds an ownership/ACL hint, because mode is the thing
 *     people try first and the thing that is usually already correct;
 *   - a query string is REDACTED (a stream URL in an error message carries the
 *     signed-URL token or credential);
 *   - markup in the path or the reason is ESCAPED, since this is printed into a
 *     page and the path is attacker-influenced on a shared host;
 *   - the failure happens BEFORE checksum, signature, extraction and
 *     install-state, and a SUCCESSFUL write still returns a byte count and
 *     continues down the unchanged path.
 *
 * setup.php cannot be included: it renders a page and refuses when core/ is
 * absent. The function is extracted and evaluated the same way
 * tests/bootstrap-signature-refusal-regression.php already does for
 * setup_verify(), so the real shipped code is exercised rather than a copy.
 *
 * SNAPSHOT_EOF_NOTE: this file keeps the canonical marker last.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function sw(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

$setup = (string) @file_get_contents($root . '/setup.php');
sw('setup.php is readable', $setup !== '');
if ($setup === '') {
    fwrite(STDERR, "\nSS-1198: setup.php missing — cannot check.\n");
    exit(1);
}

// ── The call site: captured immediately, and nothing runs after a failure ──

sw(
    'the old directory-only message is gone',
    !preg_match('/check that the web server has write permission to/i', $setup)
);

sw(
    'the last error is cleared before the write, so a stale warning cannot be blamed',
    (bool) preg_match('/error_clear_last\(\)\s*;\s*\}?\s*\R\s*\$written\s*=\s*@?file_put_contents/s', $setup)
);

sw(
    'error_get_last() is passed straight into the message, captured at the failure',
    (bool) preg_match('/setup_write_failure_message\(\s*\$zip_file\s*,\s*error_get_last\(\)\s*\)/', $setup)
);

// The write must not run its own warning into the page.
sw(
    'the write is warning-suppressed so the raw notice does not reach the page',
    (bool) preg_match('/@file_put_contents\(\s*\$zip_file/', $setup)
);

// Failure must stop before the later stages. They are each guarded by !$error,
// and the write sets $error, so assert that structure still holds.
$write_at    = strpos($setup, 'setup_write_failure_message($zip_file');
$verify_at   = strpos($setup, 'setup_verify($zip_file');
$extract_at  = strpos($setup, 'new ZipArchive');
sw('the checksum/signature step comes after the write', $write_at !== false && $verify_at !== false && $verify_at > $write_at);
sw('the extraction step comes after the write',         $write_at !== false && $extract_at !== false && $extract_at > $write_at);
sw(
    'the checksum/signature step is still gated on no prior error',
    (bool) preg_match('/if\s*\(\s*!\$error\s*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*\$verify\s*=\s*setup_verify/s', $setup)
);

// A successful write must keep returning the byte count for the existing path.
sw(
    'a successful write still yields the byte count in $written',
    (bool) preg_match('/\$written\s*=\s*@?file_put_contents\(\s*\$zip_file\s*,\s*\$zip_data\s*\)/', $setup)
);

// ── The message itself, exercised for real ─────────────────────────────────

if (!preg_match('/(function\s+setup_write_failure_message\s*\(.*?\n\})/s', $setup, $fn)) {
    sw('setup_write_failure_message() could be extracted', false);
    fwrite(STDERR, "\nSS-1198: the diagnostic function is missing from setup.php.\n");
    exit(1);
}
sw('setup_write_failure_message() could be extracted', true);
eval($fn[1]);
sw('setup_write_failure_message() is callable', function_exists('setup_write_failure_message'));

$target = '/var/www/example.com/snapsmack-install.zip';

// 1. Permission denied: operation, target, reason, and the ownership hint.
$denied = setup_write_failure_message($target, [
    'type'    => E_WARNING,
    'message' => 'file_put_contents(' . $target . '): Failed to open stream: Permission denied',
    'file'    => 'setup.php',
    'line'    => 277,
]);
sw('names the failing operation', str_contains($denied, 'file_put_contents()'));
sw('names the full target, not just its directory', str_contains($denied, $target));
sw('includes the filesystem reason PHP gave', str_contains($denied, 'Permission denied'));
sw('adds an ownership/ACL hint on permission denied', stripos($denied, 'ownership') !== false && stripos($denied, 'ACL') !== false);
sw(
    'does not repeat the path twice by leaving PHP\'s own call prefix in',
    substr_count($denied, $target) === 1
);

// 2. A different reason must NOT get the permission hint.
$nospace = setup_write_failure_message($target, [
    'message' => 'file_put_contents(' . $target . '): Failed to open stream: No space left on device',
]);
sw('includes a non-permission reason verbatim', str_contains($nospace, 'No space left on device'));
sw('does not give an ownership hint for a full disk', stripos($nospace, 'ownership') === false);

// 3. No reason at all is itself reported, with the two causes chmod cannot fix.
$silent = setup_write_failure_message($target, null);
sw('says so when PHP gave no reason', stripos($silent, 'no reason') !== false);
sw('points at open_basedir/SELinux when there is no reason',
    stripos($silent, 'open_basedir') !== false && stripos($silent, 'SELinux') !== false);
sw('still names the target when there is no reason', str_contains($silent, $target));
$empty = setup_write_failure_message($target, ['message' => '']);
sw('an empty message is treated as no reason', stripos($empty, 'no reason') !== false);

// 4. A query string is where a token lives, so it must never survive — and the
//    URL can appear in two places. Both are checked, because the two are
//    handled by different lines and only one of them is a redaction.

// 4a. Inside PHP's own call prefix: the whole prefix is dropped, so the secret
//     goes with it. Nothing is left to label, and that is the right outcome.
$tokenInPrefix = setup_write_failure_message($target, [
    'message' => 'file_put_contents(https://releases.example.com/pkg.zip?token=SECRET123&sig=abc): Failed to open stream',
]);
sw('a token in the call prefix does not survive', !str_contains($tokenInPrefix, 'SECRET123'));
sw('nor does the signature parameter',            !str_contains($tokenInPrefix, 'sig=abc'));
sw('the reason itself is still reported',         str_contains($tokenInPrefix, 'Failed to open stream'));

// 4b. In the body of the reason: the URL is kept because it is diagnostic, but
//     the query is replaced with a visible marker rather than vanishing, so
//     nobody wonders whether the message was truncated.
$tokenInBody = setup_write_failure_message($target, [
    'message' => 'Failed to open stream: https://releases.example.com/pkg.zip?token=SECRET123&sig=abc is refused',
]);
sw('a token in the reason body is redacted',      !str_contains($tokenInBody, 'SECRET123'));
sw('nor does its signature parameter survive',    !str_contains($tokenInBody, 'sig=abc'));
sw('the redaction is labelled, not silent',       str_contains($tokenInBody, '[redacted]'));
sw('the host and path are kept, being diagnostic', str_contains($tokenInBody, 'releases.example.com/pkg.zip'));

// 5. Markup in either input must be escaped, not rendered.
$evilTarget = '/var/www/"><script>alert(1)</script>/snapsmack-install.zip';
$escaped = setup_write_failure_message($evilTarget, [
    'message' => 'Failed to open stream: <img src=x onerror=alert(2)>',
]);
sw('escapes markup in the target',  !str_contains($escaped, '<script>'));
sw('escapes markup in the reason',  !str_contains($escaped, '<img src=x'));
sw('escapes the quote that would break out of an attribute', !str_contains($escaped, '"><'));
sw('still conveys the target after escaping', str_contains($escaped, 'snapsmack-install.zip'));

// 6. No stack trace, and no package bytes.
sw('carries no stack trace', stripos($denied, '#0 ') === false && stripos($denied, 'Stack trace') === false);
$withBytes = setup_write_failure_message($target, ['message' => "Failed to open stream\x00\x01\x02binary"]);
sw('does not fall over on binary in the reason', is_string($withBytes) && $withBytes !== '');

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): a failed release-package write does not say what failed, "
        . "where, and why — or it leaks a secret or unescaped markup while doing it.\n");
    exit(1);
}
echo "SS-1198 package-write diagnostic: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
