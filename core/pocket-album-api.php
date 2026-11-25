<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * POCKET ALBUM, the SOURCE endpoint: GET /api/pocket-album?album=N&page=1
 *
 * SS-1575. core/pocket-album-feed.php builds the payload; this is the door it
 * is served through, and without it the feed was unreachable code. A measured
 * fact rather than a guess: before this file existed, the source site answered
 *
 *   /?view=archive&album=191&format=json   with HTML (that wire is PHOTOGRAM's)
 *   /rss?album=191                         with the SITE feed, byte for byte
 *                                          identical to /rss
 *   /api/albums/191                        with 404
 *
 * READ ONLY, AND GET ONLY. There is no write here and no key: the data is
 * already on the public album page, and SS-1575 says display needs no
 * authentication for exactly that reason. A POST is refused rather than
 * ignored, so a mistake is visible instead of silent.
 *
 * Reached through api.php, so the site-scope and breach gates at that front
 * door have already run. Neither gates a read, which is correct here.
 */

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'no database']);
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'this surface is read-only']);
    return;
}

require_once __DIR__ . '/pocket-album-feed.php';
require_once __DIR__ . '/public-repository.php';

// The album page's own URLs are built from site_url, so the feed's absolute
// URLs are built from the same value. Anything else and the display site would
// be sent to a host this site does not consider itself to be at.
$pa_settings = $settings ?? [];
if (!is_array($pa_settings)) { $pa_settings = []; }
if ($pa_settings === []) {
    try {
        foreach ($pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pa_row) {
            $pa_settings[(string)$pa_row['setting_key']] = (string)$pa_row['setting_val'];
        }
    } catch (Throwable $e) {
        $pa_settings = [];
    }
}
$pa_base = rtrim((string)($pa_settings['site_url'] ?? ''), '/');

// SHARING IS OFF UNTIL THE OWNER TURNS IT ON.
//
// Sean's standing rule is that access describes what you CAN do, not what you
// MAY: the fact that an album is publicly readable on its own page is not by
// itself permission for other sites to pull it as data. The setting defaults
// absent, which means off, so this endpoint is dark on all 25 sites until the
// owner of a site switches it on. foreverphotograph.ing is the site that needs
// it on; nothing else changes anywhere.
$pa_enabled = (string)($pa_settings['pocket_album_share'] ?? '') === 'on';
if (!$pa_enabled) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'album sharing is not enabled on this site']);
    return;
}

$pa_album = (int)($_GET['album'] ?? 0);
$pa_page = (int)($_GET['page'] ?? 1);
$pa_payload = snapsmack_pocket_album_payload(
    new SnapPublicRepository($pdo),
    $pa_album,
    $pa_page,
    POCKET_ALBUM_PAGE_SIZE,
    $pa_base
);
$pa_payload['source'] = $pa_base;
snapsmack_pocket_album_respond($pa_payload);

// ===== SNAPSMACK EOF =====
