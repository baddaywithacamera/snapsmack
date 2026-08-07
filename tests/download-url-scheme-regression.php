<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * SECAUDIT 041 — download_url scheme regression.
 *
 * `snap_images.download_url` is written by desktop importers over a scoped API
 * key and later rendered as an `href` on the public photo page. It was stored
 * with only `trim()` and a length cap, and length is not validation:
 * `javascript:alert(1)` is short and contains no whitespace.
 *
 * The subtle half is the render side. `core/download-overlay.php` ran the value
 * through `htmlspecialchars()`, which looks like escaping and IS escaping — but
 * it escapes `< > & " '`, and a `javascript:` URL contains none of them. Escaping
 * is not scheme validation, and the two are constantly confused. Meanwhile
 * `core/social-dock.php` re-extracted the same href with a regex and echoed it
 * with no escaping at all.
 *
 * So this pins BOTH halves: the value cannot be stored, and cannot be rendered,
 * without its scheme being checked.
 */

require_once __DIR__ . '/../core/api-input-safety.php';

$failures = [];
$checks   = 0;
function d_ok(bool $ok, string $msg): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $msg;
}

// ── The validator itself ────────────────────────────────────────────────────
$hostile = [
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',              // scheme match must be case-insensitive
    "java\nscript:alert(1)",            // control character smuggling
    "java\tscript:alert(1)",
    ' javascript:alert(1)',             // leading whitespace
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
    'JAVASCRIPT:void(0)',
];
foreach ($hostile as $u) {
    d_ok(snap_api_safe_link($u) === null,
         'snap_api_safe_link accepted a hostile download URL: ' . str_replace(["\n", "\t"], '\\n', $u));
}

// Real download URLs must still work — a validator that rejects everything is
// just an outage with good intentions.
$legit = [
    'https://drive.google.com/file/d/abc123/view',
    'https://drive.google.com/uc?export=download&id=abc123',
    'https://1drv.ms/u/s!AbCdEf',
    'https://example.com/photo.jpg',
    'http://example.com/photo.jpg',
];
foreach ($legit as $u) {
    d_ok(snap_api_safe_link($u) === $u, 'a legitimate download URL was rejected: ' . $u);
}

// ── Write paths: neither may store an unchecked value ───────────────────────
$three = (string)file_get_contents(__DIR__ . '/../core/threeacross-api.php');
d_ok((bool)preg_match('/\$dl_url\s*=\s*snap_api_safe_link\(/', $three),
     'the gram import API stores download_url without checking its scheme');

$backfill = (string)file_get_contents(__DIR__ . '/../smack-backfill.php');
d_ok((bool)preg_match('/\$download_url\s*=\s*snap_api_safe_link\(/', $backfill),
     'the Drive backfill endpoint stores download_url without checking its scheme');
d_ok(str_contains($backfill, "require_once 'core/api-input-safety.php'"),
     'smack-backfill.php no longer loads the input-safety helper');
// This endpoint also switches the download button ON, which is what turns a
// stored bad URL into a rendered one.
d_ok(str_contains($backfill, 'global_downloads_enabled'),
     'backfill no longer enables downloads — if that moved, re-check this finding');

// ── Render paths: neither may emit an unchecked value ───────────────────────
$overlay = (string)file_get_contents(__DIR__ . '/../core/download-overlay.php');
d_ok((bool)preg_match('/snap_api_safe_link\(\(string\)\(\$img\[.download_url.\]/', $overlay),
     'download-overlay.php builds an href from download_url without a scheme check');

$dock = (string)file_get_contents(__DIR__ . '/../core/social-dock.php');
d_ok((bool)preg_match('/href="<\?php echo htmlspecialchars\(\$_dl_href/', $dock),
     'the social dock echoes the download href unescaped');
d_ok(!preg_match('/href="<\?php echo \$_dl_href; \?>"/', $dock),
     'the social dock still contains the raw unescaped download href');

// The dock's identity links were the other half of this surface and are already
// scheme-checked by the IndieWeb helper — assert it stays that way.
d_ok(str_contains($dock, 'snapsmack_indieweb_url((string)($settings[ $_platform[\'key\'] ] ?? \'\'))'),
     'the social dock no longer scheme-checks its identity links');

// ── rel=me identity URLs ────────────────────────────────────────────────────
require_once __DIR__ . '/../core/indieweb.php';
foreach ($hostile as $u) {
    d_ok(snapsmack_indieweb_url($u) === '',
         'snapsmack_indieweb_url accepted a hostile identity URL: ' . str_replace(["\n", "\t"], '\\n', $u));
}
d_ok(snapsmack_indieweb_url('https://example.com/@me') === 'https://example.com/@me',
     'a legitimate rel=me URL was rejected');

// A disabled Social Dock is an owner decision not to publish those profiles.
// Upgrading must never start publishing them.
d_ok(snapsmack_indieweb_identity_urls(['social_dock_enabled' => '0',
                                       'social_dock_mastodon' => 'https://example.com/@me']) === [],
     'a disabled Social Dock still publishes rel=me identity URLs');
d_ok(snapsmack_indieweb_identity_urls(['social_dock_enabled' => '1',
                                       'social_dock_mastodon' => 'javascript:alert(1)']) === [],
     'a hostile identity URL reached the rel=me list');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: download_url scheme regression suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
