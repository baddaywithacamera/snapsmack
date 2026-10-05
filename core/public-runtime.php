<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/public-controller.php';

/** Map an entry-point request into the central public controller's bounded vocabulary. */
function snapsmack_public_runtime_request(array $input): array
{
    if (trim((string)($input['query'] ?? '')) !== '') return ['route' => 'search', 'query' => $input['query']];
    if (trim((string)($input['tag'] ?? '')) !== '') return ['route' => 'hashtag', 'slug' => $input['tag'], 'page' => $input['page'] ?? 1];
    $view = strtolower(trim((string)($input['view'] ?? '')));
    $routes = ['archive', 'albums', 'collections', 'collection', 'photo', 'post', 'page', 'blogroll'];
    if (in_array($view, $routes, true)) return ['route' => $view, 'slug' => $input['slug'] ?? '', 'id' => $input['id'] ?? 0, 'page' => $input['page'] ?? 1, 'fragment' => $input['fragment'] ?? false];
    $slug = trim((string)($input['slug'] ?? ''));
    if ($slug !== '') return ['route' => 'resolve', 'slug' => $slug, 'fragment' => $input['fragment'] ?? false];
    if ((int)($input['id'] ?? 0) > 0) return ['route' => 'photo', 'id' => $input['id'], 'fragment' => $input['fragment'] ?? false];
    return ['route' => 'landing', 'page' => $input['page'] ?? 1];
}

function snapsmack_public_runtime(PDO $pdo, array $input, array $settings): array
{
    $request = snapsmack_public_parse_request(snapsmack_public_runtime_request($input));
    $response = snapsmack_public_controller(new SnapPublicRepository($pdo), $request, $settings);
    require_once __DIR__ . '/trusted-html.php';
    $trustItem = static function (array $item): array {
        foreach (['content', 'description', 'img_description'] as $field) {
            if (isset($item[$field]) && is_string($item[$field])) $item[$field] = snapsmack_trusted_html($item[$field]);
        }
        $exif = json_decode((string)($item['img_exif'] ?? ''), true);
        $item['exif'] = is_array($exif) ? $exif : [];
        $lat = filter_var($item['exif']['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($item['exif']['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $item['geo_url'] = ($lat !== false && $lon !== false && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180)
            ? 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string)$lat) . '&mlon=' . rawurlencode((string)$lon)
            : '';
        unset($item['img_exif']);
        return $item;
    };
    if (is_array($response['item'] ?? null)) $response['item'] = $trustItem($response['item']);
    foreach (['items', 'photographs'] as $group) {
        if (!is_array($response[$group] ?? null)) continue;
        $response[$group] = array_map(static fn($item) => is_array($item) ? $trustItem($item) : $item, $response[$group]);
    }
    return $response;
}
// ===== SNAPSMACK EOF =====
