<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once dirname(__DIR__) . '/core/public-controller.php';

$source = (string)file_get_contents(dirname(__DIR__) . '/core/public-controller.php');
foreach (['$_GET', '$_POST', '$_REQUEST', '$_SERVER', 'header(', 'http_response_code(', 'setcookie(', '$pdo'] as $forbidden) {
    if (str_contains($source, $forbidden)) throw new RuntimeException("Public controller owns ambient authority: {$forbidden}");
}
foreach (['landing', 'photo', 'post', 'archive', 'page', 'blogroll', 'search', 'hashtag', 'albums', 'collections', 'collection'] as $route) {
    if (!str_contains($source, "'{$route}'")) throw new RuntimeException("Public route is not centralized: {$route}");
}

$parsed = snapsmack_public_parse_request([
    'route' => ['not-a-string'], 'slug' => '../../etc/passwd', 'id' => -4,
    'page' => PHP_INT_MAX, 'query' => str_repeat('x', 500),
]);
if ($parsed['route'] !== 'landing' || $parsed['slug'] !== '' || $parsed['id'] !== 0 || $parsed['page'] !== 100000) {
    throw new RuntimeException('Request parser did not fail closed and bound numeric input.');
}
if (strlen($parsed['query']) > 200) throw new RuntimeException('Search input is unbounded.');
$missing = snapsmack_public_parse_request(['route' => 'made-up']);
if ($missing['route'] !== 'not_found') throw new RuntimeException('Unknown route did not become not_found.');
$valid = snapsmack_public_parse_request(['route' => 'post', 'slug' => 'hello-world', 'id' => '7', 'page' => '2']);
if ($valid !== ['route' => 'post', 'slug' => 'hello-world', 'id' => 7, 'page' => 2, 'query' => '']) {
    throw new RuntimeException('Valid request normalization changed unexpectedly.');
}
$entry = (string)file_get_contents(dirname(__DIR__) . '/index.php');
if (!str_contains($entry, "'/style.css?v=' . rawurlencode(\$_active_skin_version)")) {
    throw new RuntimeException('Strict skin stylesheet URL is not versioned by the installed skin manifest.');
}
if (!str_contains($entry, "\$_GET['slug'] ?? (\$_GET['s'] ?? '')")) {
    throw new RuntimeException('Legacy ?s=<slug> image links no longer enter the strict public controller.');
}
if (!str_contains($entry, "in_array(\n            'smack-progressive-reveal'")
    || str_contains($entry, "if (\$active_skin === 'instant-camera')")) {
    throw new RuntimeException('Full progressive feed is not granted by declared shared-engine capability.');
}
foreach (['game-on', 'instant-camera'] as $skin) {
    $manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/skins/' . $skin . '/manifest.json'), true);
    if (!in_array('smack-progressive-reveal', $manifest['require_scripts'] ?? [], true)) {
        throw new RuntimeException("{$skin} no longer declares the shared progressive-reveal capability.");
    }
}
$repository = (string)file_get_contents(dirname(__DIR__) . '/core/public-repository.php');
if (!str_contains($repository, '[max(1, min(5000, $limit)), max(0, $offset)]')) {
    throw new RuntimeException('Public repository collapses a controller-approved progressive feed below 5,000.');
}
echo "Public service/controller boundary regression passed.\n";
// ===== SNAPSMACK EOF =====
