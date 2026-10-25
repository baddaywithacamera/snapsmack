<?php
/**
 * clean_avatar_url() — the forum must only ever serve an avatar that lives on
 * the blog which owns the account.
 *
 * This matters more than it looks. An avatar URL is fetched by the browser of
 * every person reading the forum. An install free to point it at a host it
 * does not own could use it to log the IP of every reader, or swap the image
 * for something else long after a moderator approved the post it sits on.
 *
 * index.php is a router: including it would try to serve a request. So the
 * function is lifted out of the real file and evaluated here, which keeps the
 * test honest -- it runs the shipped source, not a copy that can drift.
 *
 * Run: php projects/forum-server/tests/test_avatar_url.php   (exit 0 = pass)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

// The server runs with mbstring; a bare PHP build may not have it. Every URL
// in this test is ASCII, where strlen and mb_strlen agree exactly, so a shim
// here changes nothing about what is being checked.
if (!function_exists('mb_strlen')) {
    function mb_strlen($value, $encoding = null) { return strlen($value); }
}

$source = file_get_contents(__DIR__ . '/../api/forum/index.php');
$start  = strpos($source, 'function clean_avatar_url');
if ($start === false) {
    fwrite(STDERR, "FAIL: clean_avatar_url is gone from index.php\n");
    exit(1);
}
$end = strpos($source, "\n}\n", $start);
eval(substr($source, $start, $end - $start + 3));

class AvatarRefused extends Exception {}

function api_error(int $status, string $code, string $message) {
    throw new AvatarRefused($code);
}

$failed = 0;

function check(bool $ok, string $message) {
    global $failed;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $failed++;
}

function refused(string $url, string $domain): string {
    try {
        clean_avatar_url($url, $domain);
        return '';                 // no refusal at all
    } catch (AvatarRefused $e) {
        return $e->getMessage();
    }
}

$site = 'baddaywithacamera.ca';

// --- what must be allowed ----------------------------------------------------
check(clean_avatar_url('https://baddaywithacamera.ca/uploads/skin-avatars/tilez--header_logo.png', $site)
      === 'https://baddaywithacamera.ca/uploads/skin-avatars/tilez--header_logo.png',
      'the blog\'s own https avatar is kept');
check(clean_avatar_url('', $site) === '', 'empty means no avatar, not an error');
check(clean_avatar_url('   ', $site) === '', 'whitespace only is treated as empty');
check(clean_avatar_url('https://BADDAYWITHACAMERA.CA/a.png', $site)
      === 'https://BADDAYWITHACAMERA.CA/a.png',
      'host comparison ignores case');

// --- what must be refused ----------------------------------------------------
check(refused('https://evil.example/pixel.png', $site) === 'AVATAR_WRONG_HOST',
      'another host is refused — this is the tracking-pixel case');
check(refused('http://baddaywithacamera.ca/a.png', $site) === 'AVATAR_NOT_HTTPS',
      'plain http is refused even on the right host');
check(refused('https://foreverphotograph.ing/a.png', $site) === 'AVATAR_WRONG_HOST',
      'one blog cannot borrow another blog\'s avatar');
check(refused('/uploads/a.png', $site) === 'AVATAR_INVALID',
      'a path with no host is refused');
check(refused('javascript:alert(1)', $site) === 'AVATAR_INVALID',
      'a javascript: URL is refused');
check(refused('data:image/png;base64,AAAA', $site) === 'AVATAR_INVALID',
      'a data: URL is refused');
check(refused('https://baddaywithacamera.ca.evil.example/a.png', $site) === 'AVATAR_WRONG_HOST',
      'a lookalike host that merely starts with the domain is refused');
check(refused('https://baddaywithacamera.ca/' . str_repeat('a', 500), $site) === 'AVATAR_TOO_LONG',
      'an over-long URL is refused before anything else');

echo "\n" . ($failed ? "FAILED: $failed" : "ALL PASS") . "\n";
exit($failed ? 1 : 0);

// ===== SNAPSMACK EOF =====
