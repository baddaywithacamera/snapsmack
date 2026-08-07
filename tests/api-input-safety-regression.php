<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * SECAUDIT 040 findings C and D — containment for values a desktop client sends
 * that get STORED and acted on later by unrelated code.
 *
 *   C: img_file / img_thumb_* land in snap_images and are handed to unlink() by
 *      snap_manage_delete_image() when the owner deletes the image.
 *   D: author_url lands in snap_community_comments.guest_url and is rendered as
 *      a link href by core/community-component.php.
 *
 * Both are post-authentication (they need a live key AND an open step-up
 * window), which is why they were rated LOW — but the whole point is that the
 * server must not extend the reach of a tampered client, so the containment is
 * pinned here rather than trusted to stay put.
 */

require_once __DIR__ . '/../core/api-input-safety.php';

$failures = [];
$checks   = 0;
function api_test(bool $ok, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $message;
}

// ── Finding C: paths that must be ACCEPTED ──────────────────────────────────
// Exactly the shapes flkrfckr/upload returns.
foreach ([
    'img_uploads/2026/08/20260806_142233_a1b2c3.jpg',
    'img_uploads/2026/08/thumbs/t_20260806_142233_a1b2c3.jpg',
    'img_uploads/2026/08/thumbs/a_20260806_142233_a1b2c3.jpg',
    'img_uploads/2019/01/photo-with-dashes.webp',
    'img_uploads/2026/12/UPPER_and_lower.PNG',
] as $good) {
    api_test(snap_api_safe_upload_path($good), "legitimate upload path rejected: {$good}");
}

// ── Finding C: paths that must be REJECTED ──────────────────────────────────
// The traversal cases are the actual finding; the rest close the neighbouring
// doors so a later "small" relaxation cannot quietly reopen it.
foreach ([
    '../../../../etc/passwd'                        => 'traversal out of the install',
    'img_uploads/../../core/db.php'                 => 'traversal via an img_uploads prefix',
    'img_uploads/2026/../../../core/constants.php'  => 'deep traversal',
    'core/db.php'                                   => 'inside the root but not an upload',
    'config.php'                                    => 'site config',
    '.htaccess'                                     => 'server config',
    '/etc/passwd'                                   => 'absolute unix path',
    'C:/Windows/System32/drivers/etc/hosts'         => 'drive-letter path',
    'c:windows/system.ini'                          => 'drive-letter without slash',
    '\\\\server\\share\\file.jpg'                   => 'UNC path',
    'img_uploads\\2026\\08\\x.jpg'                  => 'backslash separators',
    "img_uploads/2026/08/x.jpg\0.txt"               => 'NUL truncation',
    'img_uploads//2026/08/x.jpg'                    => 'empty path segment',
    'img_uploads/./2026/08/x.jpg'                   => 'single-dot segment',
    'img_uploads/2026/08/x x.jpg'                   => 'space in filename',
    "img_uploads/2026/08/x\njpg"                    => 'newline in filename',
    'img_uploads/2026/08/$(rm -rf ~).jpg'           => 'shell metacharacters',
    "img_uploads/2026/08/'; DROP TABLE--.jpg"       => 'quote characters',
    ''                                              => 'empty string',
    'img_uploads/'                                  => 'prefix only, no file',
    'img_uploadsevil/x.jpg'                         => 'prefix-lookalike directory',
    'IMG_UPLOADS/2026/08/x.jpg'                     => 'wrong-case prefix',
] as $bad => $why) {
    api_test(!snap_api_safe_upload_path((string)$bad), "accepted a bad path ({$why})");
}

// Length bound.
api_test(!snap_api_safe_upload_path('img_uploads/' . str_repeat('a', 600) . '.jpg'),
         'accepted an over-long path');

// ── Finding D: links that must be ACCEPTED ──────────────────────────────────
foreach ([
    'https://www.flickr.com/people/196612229@N04/',
    'http://example.com/~user/page.html',
    'https://example.com:8443/path?q=1&r=2#frag',
] as $good) {
    api_test(snap_api_safe_link($good) === $good, "legitimate link rejected: {$good}");
}
api_test(snap_api_safe_link('  https://example.com/  ') === 'https://example.com/',
         'surrounding whitespace not trimmed');

// ── Finding D: links that must become NULL ──────────────────────────────────
// htmlspecialchars() at the render site stops attribute breakout but NOT a
// javascript: scheme — escaping is not scheme validation.
foreach ([
    'javascript:alert(document.cookie)'      => 'javascript scheme',
    'JaVaScRiPt:alert(1)'                    => 'mixed-case javascript scheme',
    'data:text/html;base64,PHNjcmlwdD4='     => 'data URI',
    'vbscript:msgbox(1)'                     => 'vbscript scheme',
    'file:///etc/passwd'                     => 'file scheme',
    "java\nscript:alert(1)"                  => 'newline-split scheme',
    "https://example.com/\r\nSet-Cookie: x"  => 'header injection via CRLF',
    'not a url at all'                       => 'free text',
    ''                                       => 'empty string',
    '//example.com/protocol-relative'        => 'protocol-relative URL',
] as $bad => $why) {
    api_test(snap_api_safe_link((string)$bad) === null, "accepted a bad link ({$why})");
}
api_test(snap_api_safe_link('https://example.com/' . str_repeat('a', 600)) === null,
         'accepted an over-long link');

// ── The handler must actually USE them ──────────────────────────────────────
$api = file_get_contents(__DIR__ . '/../core/flkrfckr-api.php');
api_test(str_contains($api, "require_once __DIR__ . '/api-input-safety.php'"),
         'flkrfckr-api.php no longer includes the containment helpers');
api_test(substr_count($api, 'snap_api_safe_upload_path(') >= 3,
         'img_file and both thumbnail paths are not all validated');
api_test(str_contains($api, 'snap_api_safe_link($author_url)'),
         'author_url is no longer scheme-validated before storage');

// ── threeacross adopted the shared rule ─────────────────────────────────────
// It had a partial check already (SECAUDIT 2026-06-25 finding 2: no leading '/',
// no '..', no NUL) which missed drive letters, backslash paths, UNC, and any
// relative path that stays inside the install while naming something that is not
// an upload. Compatibility verified before tightening: both threeacross upload
// endpoints return 'img_uploads/YYYY/MM/...' and sanitise filenames to
// [a-z0-9_.-], a strict subset of the accepted charset.
$three = file_get_contents(__DIR__ . '/../core/threeacross-api.php');
api_test(str_contains($three, "require_once __DIR__ . '/api-input-safety.php'"),
         'threeacross-api.php no longer includes the containment helper');
api_test(str_contains($three, 'snap_api_safe_upload_path($img_path)'),
         'threeacross-api.php no longer validates the client-supplied image path');

// The paths those endpoints hand back must satisfy the rule they are checked
// against — otherwise tightening the check silently breaks Unzucker and SYBU.
foreach ([
    'img_uploads/2026/08/20260806142233_a1b2c3d4.jpg',
    'img_uploads/2026/08/thumbs/t_20260806142233_a1b2c3d4.jpg',
    'img_uploads/2026/08/thumbs/a_20260806142233_a1b2c3d4.jpg',
] as $shipped) {
    api_test(snap_api_safe_upload_path($shipped),
             "a path the upload endpoint actually returns is rejected: {$shipped}");
}

// ── Handlers that legitimately need NO path validation ──────────────────────
// Recorded so a later sweep does not "helpfully" add checks they do not need, or
// flag their absence as a gap. gyss/ohsnap/smackpress only ever SELECT img_file
// out of the database — none of them accept a path from the client.
foreach (['gyss-api.php', 'ohsnap-api.php', 'smackpress-api.php'] as $handler) {
    $src = file_get_contents(__DIR__ . '/../core/' . $handler);
    api_test(!preg_match('#\$_?(POST|body)\s*\[\s*[\'"]img_file[\'"]#', $src),
             "{$handler} now accepts a client-supplied img_file and needs snap_api_safe_upload_path()");
}

// ── Finding B lives in the shared step-up client (Python) ───────────────────
// Pinned here so a PHP-only test run still fails loudly if it is removed.
$stepup = file_get_contents(__DIR__ . '/../tools/_shared/snap_stepup.py');
api_test(str_contains($stepup, '_insecure_reason'),
         'snap_stepup.py lost its HTTPS check — password + TOTP could go out in clear');
api_test(substr_count($stepup, '_insecure_reason(base_url)') >= 2,
         'the HTTPS check must run in BOTH request_authorization and authorize_interactive');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: API input-safety regression suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
