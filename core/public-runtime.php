<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/public-controller.php';
require_once __DIR__ . '/parser.php';

/** Expand CMS-owned static-page shortcodes before the presentation-only skin sees them. */
function snapsmack_public_parse_page_content(PDO $pdo, array $response): array
{
    if (($response['kind'] ?? '') !== 'page' || !is_array($response['item'] ?? null)) return $response;
    $source = (string)($response['item']['content'] ?? '');
    // Strict public skins do not include core/meta.php. Static pages still use
    // the CMS-owned shortcode parser below, so carry the parser's existing
    // presentation assets through the registered-asset path. Without these,
    // [columns] emits the correct grid markup but browsers render it as plain
    // stacked divs (the failure seen on PIXHELLATED's Cameras page).
    $response['required_assets']['styles'] = array_values(array_unique(array_merge(
        (array)($response['required_assets']['styles'] ?? []),
        ['asset:public:page-static', 'asset:public:shortcodes', 'asset:public:columns']
    )));
    $response['required_assets']['scripts'] = array_values(array_unique(array_merge(
        (array)($response['required_assets']['scripts'] ?? []),
        ['smack-lightbox']
    )));
    if (stripos($source, '[board]') !== false || stripos($source, '[prompts]') !== false) {
        // A shortcode may require audited CMS assets, but its generated HTML
        // still passes through the normal sanitizer. Never allow page content
        // to smuggle arbitrary <link> elements into the document.
        $response['required_assets']['styles'] = array_values(array_unique(array_merge(
            $response['required_assets']['styles'], [
                'asset:public:photochallenge-board-layouts',
                'asset:public:photochallenge-board-embed',
            ]
        )));
    }
    $parser = new SnapSmack($pdo);
    $response['item']['content'] = $parser->parseContent($source);
    return $response;
}

/** Map an entry-point request into the central public controller's bounded vocabulary. */
function snapsmack_public_runtime_request(array $input): array
{
    if (trim((string)($input['query'] ?? '')) !== '') return ['route' => 'search', 'query' => $input['query']];
    if (trim((string)($input['tag'] ?? '')) !== '') return ['route' => 'hashtag', 'slug' => $input['tag'], 'page' => $input['page'] ?? 1];
    $view = strtolower(trim((string)($input['view'] ?? '')));
    $routes = ['archive', 'albums', 'collections', 'collection', 'photo', 'post', 'page', 'blogroll'];
    if (in_array($view, $routes, true)) return ['route' => $view, 'slug' => $input['slug'] ?? '', 'id' => $input['id'] ?? 0,
        'page' => $input['page'] ?? 1, 'category_id' => $input['category_id'] ?? 0,
        'album_id' => $input['album_id'] ?? 0, 'fragment' => $input['fragment'] ?? false];
    $slug = trim((string)($input['slug'] ?? ''));
    $aliases = is_array($input['route_aliases'] ?? null) ? $input['route_aliases'] : [];
    if ($slug !== '' && isset($aliases[$slug]) && in_array($aliases[$slug], $routes, true)) {
        return ['route' => $aliases[$slug], 'page' => $input['page'] ?? 1,
            'category_id' => $input['category_id'] ?? 0, 'album_id' => $input['album_id'] ?? 0,
            'fragment' => $input['fragment'] ?? false];
    }
    if ($slug !== '') return ['route' => 'resolve', 'slug' => $slug, 'fragment' => $input['fragment'] ?? false];
    if ((int)($input['id'] ?? 0) > 0) return ['route' => 'photo', 'id' => $input['id'], 'fragment' => $input['fragment'] ?? false];
    return ['route' => 'landing', 'page' => $input['page'] ?? 1];
}

function snapsmack_public_runtime(PDO $pdo, array $input, array $settings): array
{
    $request = snapsmack_public_parse_request(snapsmack_public_runtime_request($input));
    $repository = new SnapPublicRepository($pdo);
    $response = snapsmack_public_controller($repository, $request, $settings);
    $response = snapsmack_public_parse_page_content($pdo, $response);
    // Comments are one feature with TWO switches: global_comments_enabled in
    // Settings, and community_comments_enabled on the community engine. Only
    // the first was consulted here, so turning the other one off hid the
    // comments while leaving the COMMENTS link in the photo navigation and
    // its "2" hotkey live -- a link that opens a pane the site will not fill.
    // If either switch says no, comments are off.
    //
    // It also only answered for a photograph. A post and a collection carry
    // the same navigation bar and were never gated at all.
    if (in_array(($response['kind'] ?? ''), ['photo', 'post', 'collection'], true)) {
        $response['comments_enabled'] = (($settings['global_comments_enabled'] ?? '1') === '1')
            && (($settings['community_comments_enabled'] ?? '1') === '1')
            && (($response['item']['allow_comments'] ?? '1') == '1');
    }
    // Profile chrome is shared across every strict public page, not just the
    // landing response. GAME ON also needs its puzzle field on About,
    // Blogroll and other secondary pages so those pages retain the skin.
    if (!isset($response['photo_count']) && (($settings['site_mode'] ?? '') !== 'smacktalk')) {
        $response['photo_count'] = $repository->publishedPhotographCount();
    }
    // The other two numbers in the profile stats row. They have been blank on
    // every photoblog's mobile view since the conversion, because the skin
    // asked for fields the bounded response did not carry. Supplied
    // pre-formatted as well as raw: a template may not compute, so
    // number_format has to happen on this side or the thousands separator in
    // Sean's reference ("9,975") can never come back.
    if (!isset($response['comment_count']) && (($settings['site_mode'] ?? '') !== 'smacktalk')) {
        $response['comment_count'] = $repository->approvedCommentCount();
        $response['like_count']    = $repository->likeCount();
    }
    foreach (['photo_count', 'comment_count', 'like_count'] as $_countKey) {
        if (isset($response[$_countKey])) {
            $response[$_countKey . '_display'] = number_format((int)$response[$_countKey]);
        }
    }
    if (($settings['active_skin'] ?? '') === 'game-on' && !isset($response['puzzle_items'])) {
        $response['puzzle_items'] = array_map(
            'snapsmack_game_on_focus_item',
            $repository->gameOnPuzzlePhotographs()
        );
    }
    require_once __DIR__ . '/trusted-html.php';
    $trustItem = static function (array $item) use ($settings): array {
        foreach (['content', 'description', 'img_description'] as $field) {
            if (isset($item[$field]) && is_string($item[$field])) $item[$field] = snapsmack_trusted_html($item[$field]);
        }
        // EXIF / Technical Specs is switched off in Solo Image Appearance.
        // Until 0.7.877D only core/meta.php honoured it, a file no strict
        // skin runs. The first repair emptied item['exif'] earlier in this
        // function -- and THIS line rebuilt it from the raw column a moment
        // later, so the table kept rendering. The switch has to be read where
        // the field is made, not before it.
        $exifOff = (($settings['exif_display_enabled'] ?? '1') !== '1');
        $exif = $exifOff ? [] : json_decode((string)($item['img_exif'] ?? ''), true);
        $item['exif'] = is_array($exif) ? $exif : [];
        if ($exifOff) { $item['technical'] = []; $item['img_exif'] = ''; }

        // A readable date. Twelve templates printed the raw database column,
        // so a photograph was stamped "2026-09-11 03:05:00" -- a timestamp
        // with seconds, on a photoblog -- and the site's own Date Format
        // setting was read by nothing on the public side at all. Formatted
        // once here; img_date stays untouched for the datetime attribute,
        // which must remain machine-readable.
        $stamp = (string)($item['img_date'] ?? $item['created_at'] ?? '');
        $when = $stamp !== '' ? strtotime($stamp) : false;
        $item['date_display'] = $when === false ? ''
            : date((string)($settings['date_format'] ?? 'F j, Y'), $when);
        $lat = filter_var($item['exif']['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($item['exif']['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $item['geo_url'] = ($lat !== false && $lon !== false && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180)
            ? 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string)$lat) . '&mlon=' . rawurlencode((string)$lon)
            : '';
        if (array_key_exists('image_asset', $item)) {
            $item['image_size'] = in_array(($item['image_size'] ?? ''), ['full', 'medium', 'small'], true)
                ? (string)$item['image_size'] : 'full';
            $item['image_align'] = in_array(($item['image_align'] ?? ''), ['left', 'center', 'right'], true)
                ? (string)$item['image_align'] : 'center';
            $item['image_shadow'] = !empty($item['image_shadow']);
        }
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
