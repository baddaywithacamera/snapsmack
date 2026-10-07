<?php
/**
 * Same-origin provider for the audited shared font inventory.
 *
 * Release packages deliberately omit the 20 MB font library. Public pages
 * therefore fetch an allow-listed face through their own hostname while this
 * endpoint retrieves and briefly caches the canonical Smack Central asset.
 */

$inventory = include __DIR__ . '/core/manifest-inventory.php';
$fonts = is_array($inventory['local_fonts'] ?? null) ? $inventory['local_fonts'] : [];
$family = trim((string)($_GET['family'] ?? ''));

if ($family === '' || !isset($fonts[$family]) || !is_array($fonts[$family])) {
    http_response_code(404);
    exit;
}

$font = $fonts[$family];
$relative = (string)($font['file'] ?? '');
if (!preg_match('#^assets/fonts/[A-Za-z0-9 ._-]+/[A-Za-z0-9 ._-]+\.(?:ttf|otf|woff2?)$#i', $relative)) {
    http_response_code(404);
    exit;
}

$segments = array_map('rawurlencode', explode('/', $relative));
$sourceUrl = 'https://snapsmack.ca/sc-assets/'
    . preg_replace('#^assets/#', '', implode('/', $segments));
$extension = strtolower((string)pathinfo($relative, PATHINFO_EXTENSION));
$types = [
    'ttf' => 'font/ttf',
    'otf' => 'font/otf',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
];
$contentType = $types[$extension] ?? 'application/octet-stream';
$cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'snapsmack-font-' . hash('sha256', $sourceUrl) . '.' . $extension;
$bytes = false;

if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 86400) {
    $bytes = file_get_contents($cacheFile);
}

if ($bytes === false && function_exists('curl_init')) {
    $ch = curl_init($sourceUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'SnapSmack font provider',
    ]);
    $candidate = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status === 200 && is_string($candidate) && strlen($candidate) > 0) {
        $bytes = $candidate;
        @file_put_contents($cacheFile, $bytes, LOCK_EX);
    }
}

if ($bytes === false && (bool)ini_get('allow_url_fopen')) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'follow_location' => 0,
            'user_agent' => 'SnapSmack font provider',
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $candidate = @file_get_contents($sourceUrl, false, $context);
    $statusLine = is_array($http_response_header ?? null) ? (string)($http_response_header[0] ?? '') : '';
    if (is_string($candidate) && strlen($candidate) > 0 && preg_match('#\\s200(?:\\s|$)#', $statusLine)) {
        $bytes = $candidate;
        @file_put_contents($cacheFile, $bytes, LOCK_EX);
    }
}

if ($bytes === false) {
    http_response_code(502);
    header('Cache-Control: no-store');
    exit;
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: public, max-age=86400, immutable');
header('X-Content-Type-Options: nosniff');
echo $bytes;
// ===== SNAPSMACK EOF =====
