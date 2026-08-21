<?php
/**
 * SNAPSMACK — authenticated remote-image privacy proxy.
 * Remote servers see the blog server, never the reader's IP address.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/core/auth-smack.php';
require_once __DIR__ . '/core/smackverse.php';

$proxy_settings = $pdo->query("SELECT setting_key,setting_val FROM snap_settings")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$encoded = trim((string)($_GET['u'] ?? ''));
$expires = (int)($_GET['e'] ?? 0);
$provided = strtolower(trim((string)($_GET['s'] ?? '')));
$private = (string)($proxy_settings['smackverse_private_key'] ?? '');
if ($encoded === '' || $expires < time() || $expires > time() + 604800 || $private === '') {
    http_response_code(403); exit;
}
$secret = hash('sha256', "snapsmack-media-proxy\n" . $private, true);
$expected = hash_hmac('sha256', $encoded . "\n" . $expires, $secret);
if (!hash_equals($expected, $provided)) { http_response_code(403); exit; }
$pad = strlen($encoded) % 4;
$url = base64_decode(strtr($encoded . ($pad ? str_repeat('=', 4 - $pad) : ''), '-_', '+/'), true);
if (!is_string($url) || stripos($url, 'https://') !== 0) { http_response_code(400); exit; }
$resolved = sv_resolve_public($url);
if ($resolved === null || !function_exists('curl_init')) { http_response_code(400); exit; }

$cache_dir = __DIR__ . '/cache/fedi-media';
$cache_key = hash('sha256', $url);
$cache_body = $cache_dir . '/' . $cache_key . '.bin';
$cache_meta = $cache_dir . '/' . $cache_key . '.json';
$body = false; $ctype = '';
if (is_file($cache_body) && is_file($cache_meta) && filemtime($cache_body) >= time() - 86400) {
    $meta = json_decode((string)@file_get_contents($cache_meta), true);
    if (is_array($meta) && isset($meta['type'])) {
        $body = @file_get_contents($cache_body);
        $ctype = (string)$meta['type'];
    }
}
if (!is_string($body)) {
    $data = '';
    $too_large = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE => $resolved['pin'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/png,image/jpeg,image/gif',
            'User-Agent: SnapSmack-MediaProxy/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0') . ' (+https://snapsmack.ca)',
            'Referer:', 'Cookie:', 'Authorization:',
        ],
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$data, &$too_large): int {
            if (strlen($data) + strlen($chunk) > 15728640) { $too_large = true; return 0; }
            $data .= $chunk; return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    $error = curl_errno($ch);
    curl_close($ch);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/avif'];
    if ($too_large || $error !== 0 || $code < 200 || $code >= 300 || !in_array($ctype, $allowed, true)) {
        http_response_code(502); exit;
    }
    $info = @getimagesizefromstring($data);
    $detected = is_array($info) && isset($info[2]) ? image_type_to_mime_type((int)$info[2]) : '';
    if (!is_array($info) || !in_array($detected, $allowed, true)
        || $detected !== $ctype || ($info[0] * $info[1]) > 50000000) {
        http_response_code(415); exit;
    }
    $body = $data;
    if ((is_dir($cache_dir) || @mkdir($cache_dir, 0750, true)) && is_writable($cache_dir)) {
        if (random_int(1, 100) === 1) {
            foreach (glob($cache_dir . '/*.{bin,json}', GLOB_BRACE) ?: [] as $old) {
                if (is_file($old) && filemtime($old) < time() - 604800) @unlink($old);
            }
        }
        @file_put_contents($cache_body, $body, LOCK_EX);
        @file_put_contents($cache_meta, json_encode(['type'=>$ctype]), LOCK_EX);
    }
}

header('Content-Type: ' . $ctype);
header('Content-Length: ' . strlen($body));
header('Cache-Control: private, max-age=86400');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
echo $body;
// ===== SNAPSMACK EOF =====
