<?php
/**
 * Same-origin provider for the audited shared font inventory.
 *
 * Release packages deliberately omit the 22 MB font library so the installer
 * stays small on shared hosting. A site pulls the faces it actually uses from
 * Smack Central once, into its own data directory, and serves them itself
 * from then on.
 *
 * The fetching and the cache location live in core/font-provider.php, shared
 * with the skin installer and the settings save path.
 */

require_once __DIR__ . '/core/font-provider.php';

$family = trim((string)($_GET['family'] ?? ''));
$relative = $family === '' ? '' : snapsmack_font_relative_path($family);

if ($relative === '') {
    http_response_code(404);
    exit;
}

$path = snapsmack_font_local_copy($family);
if ($path === '' && snapsmack_font_fetch($family)) {
    $path = snapsmack_font_local_copy($family);
}

if ($path === '') {
    // Smack Central could not supply it. Say so plainly rather than serving
    // an empty body the browser would treat as a broken face.
    http_response_code(502);
    header('Cache-Control: no-store');
    exit;
}

$types = [
    'ttf' => 'font/ttf',
    'otf' => 'font/otf',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
];
$extension = strtolower((string)pathinfo($relative, PATHINFO_EXTENSION));

header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);
// ===== SNAPSMACK EOF =====
