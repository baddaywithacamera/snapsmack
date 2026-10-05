<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Render every manifest-declared route of each strict public skin with a
 * deterministic marker. This is a read-only recovery diagnostic: a route is
 * considered covered only when its own marker survives into the rendered HTML
 * and the skin does not render its not-found presentation instead.
 *
 * Usage:
 *   php tools/_build/audit-public-skin-route-coverage.php [output.json]
 */

$root = dirname(__DIR__, 2);
$outputPath = $argv[1] ?? ($root . '/outputs/public-skin-route-coverage-audit.json');

if (!defined('BASE_URL')) define('BASE_URL', '/');
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/asset-registry.php';
require_once $root . '/core/skin-presentation.php';

/** @return array<string,mixed> */
function route_fixture(string $kind, string $marker, string $image): array
{
    $item = [
        'id' => 101,
        'title' => $marker,
        'name' => $marker,
        'label' => $marker,
        'slug' => 'route-marker',
        'img_title' => $marker,
        'img_slug' => 'route-marker',
        'img_alt' => $marker,
        'img_file' => $image,
        'img_thumb_aspect' => $image,
        'img_thumb_square' => $image,
        'url' => '/route-marker',
        'content' => snapsmack_trusted_html('<p>' . $marker . '</p>'),
        'description' => $marker,
        'img_description' => snapsmack_trusted_html('<p>' . $marker . '</p>'),
        'width' => 1600,
        'height' => 1000,
        'image_count' => 2,
    ];

    return [
        'status' => $kind === 'not_found' ? 404 : 200,
        'kind' => $kind,
        'page_title' => $marker,
        'query' => $marker,
        'slug' => 'route-marker',
        'item' => $item,
        'items' => [$item],
        'photographs' => [$item],
        'posts' => [$item],
        'tiles' => [['title' => $marker, 'thumb' => $image, 'full' => $image, 'width' => 1600, 'height' => 1000]],
        'results' => ['photographs' => [$item], 'posts' => [$item]],
        'groups' => [['label' => $marker, 'description' => $marker, 'url' => '/route-marker', 'cover_url' => $image, 'photograph_count' => 1]],
        'blogroll_groups' => [['label' => $marker, 'items' => [['name' => $marker, 'url' => 'https://example.invalid/', 'description' => $marker]]]],
        'navigation' => [['label' => 'Home', 'url' => '/']],
        'page' => 1,
        'total_pages' => 1,
        'photo_count' => 1,
        'word_count' => 1,
        'rendered_content' => snapsmack_trusted_html('<p>' . $marker . '</p>'),
        'post' => ['title' => $marker, 'created_at' => '2026-10-05'],
        'comments' => [],
        'comments_enabled' => false,
    ];
}

$routes = ['landing', 'photo', 'post', 'archive', 'page', 'blogroll', 'search', 'hashtag', 'albums', 'collections', 'collection', 'not_found'];
$report = [
    'schema_version' => 1,
    'generated_at_utc' => gmdate(DATE_ATOM),
    'head' => trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD')),
    'method' => 'render each route with a unique marker and reject not-found fallthrough',
    'warning' => 'Route marker coverage proves bounded content reaches the skin; it does not prove visual parity or interaction behavior.',
    'skins' => [],
];

foreach (glob($root . '/skins/*', GLOB_ONLYDIR) ?: [] as $skinDirectory) {
    $slug = basename($skinDirectory);
    $manifestPath = $skinDirectory . '/manifest.json';
    if (!is_file($manifestPath)) continue;
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['schema_version'] ?? null) !== 2 || ($manifest['cms_controller'] ?? '') !== 'public') continue;

    $skinResult = ['covered_count' => 0, 'declared_count' => 0, 'routes' => []];
    $image = '/skins/' . $slug . '/screenshot-landing.png';
    $site = [
        'site_name' => 'ROUTE AUDIT',
        'owner_name' => 'Route Auditor',
        'tagline' => 'Deterministic route coverage fixture',
        'site_description' => 'Deterministic route coverage fixture',
        'avatar_url' => '',
        'base_url' => '/',
        'language' => 'en',
        'direction' => 'ltr',
        'skin_style_url' => '/skins/' . $slug . '/style.css',
        'skin_slug' => $slug,
        'skin_presentation' => snapsmack_skin_presentation([], $slug),
        'skin_custom_style' => snapsmack_trusted_html(''),
        'search_dock' => ['enabled' => false],
        'registered_assets' => snapsmack_skin_declared_assets($manifest),
        'footer' => [],
    ];

    foreach ($routes as $kind) {
        $template = (string)($manifest['templates'][$kind] ?? '');
        if ($template === '') continue;
        $skinResult['declared_count']++;
        $marker = 'ROUTE_MARKER_' . strtoupper(str_replace('-', '_', $slug)) . '_' . strtoupper($kind);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        ob_start();
        try {
            $rendered = snapsmack_render_strict_skin_template(
                $skinDirectory,
                $template,
                snapsmack_build_skin_view(route_fixture($kind, $marker, $image), $site)
            );
            $html = (string)ob_get_clean();
        } catch (Throwable $error) {
            $html = (string)ob_get_clean();
            $rendered = false;
            $warnings[] = get_class($error) . ': ' . $error->getMessage();
        } finally {
            restore_error_handler();
        }

        $markerPresent = str_contains($html, $marker);
        $notFoundFallthrough = $kind !== 'not_found'
            && (preg_match('/class=["\'][^"\']*not-found/i', $html) === 1
                || preg_match('/>\s*Not found\s*</i', $html) === 1);
        $covered = $rendered && $markerPresent && !$notFoundFallthrough;
        if ($covered) $skinResult['covered_count']++;
        $skinResult['routes'][$kind] = [
            'declared_template' => $template,
            'covered' => $covered,
            'marker_present' => $markerPresent,
            'not_found_fallthrough' => $notFoundFallthrough,
            'warning_count' => count($warnings),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
    $report['skins'][$slug] = $skinResult;
}

ksort($report['skins'], SORT_STRING);
$report['skin_count'] = count($report['skins']);
$report['declared_route_total'] = array_sum(array_column($report['skins'], 'declared_count'));
$report['covered_route_total'] = array_sum(array_column($report['skins'], 'covered_count'));

$outputDirectory = dirname($outputPath);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create output directory: ' . $outputDirectory);
}
file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
echo 'Wrote ' . $outputPath . PHP_EOL;
echo 'Covered ' . $report['covered_route_total'] . ' of ' . $report['declared_route_total'] . ' declared routes across ' . $report['skin_count'] . ' public skins.' . PHP_EOL;

// ===== SNAPSMACK EOF =====
