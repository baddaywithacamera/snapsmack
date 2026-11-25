<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * POCKET ALBUM, the SOURCE side: one album's photographs, publicly readable.
 *
 * SS-1575. Sean asked weeks ago to show an album from one of his sites on
 * another, and it kept getting parked. baddaywithacamera.ca/images has shown
 * nothing in public since, and that site is FEATURED on snapsmack.ca as a
 * working TILEZ showcase.
 *
 * WHY THIS FILE HAD TO EXIST BEFORE ANYTHING ELSE COULD. I checked what the
 * source site already serves, on the live site rather than from the code:
 *
 *   /?view=archive&album=191&format=json   returns HTML (that wire is gated
 *                                          to PHOTOGRAM's phone feed)
 *   /rss?album=191                         returns the SITE feed, byte for
 *                                          byte identical to /rss -- the
 *                                          album parameter is ignored
 *   /api/albums/191                        404
 *
 * So there was no machine-readable album anywhere. The display side could not
 * be written at all, which is the real reason this sat: not a disagreement
 * about the block, a missing feed.
 *
 * IT WRITES NO SQL. The repository already scopes published images to an album
 * -- archivePhotographs(limit, offset, categoryId, albumId, search), which is
 * what /archive?album=N already uses. Reusing it means this feed cannot
 * disagree with the album page a visitor sees, and cannot accidentally widen
 * what counts as published. Copying the working query into a second place is
 * how those two drift apart.
 *
 * WHAT IS PUBLIC HERE AND WHY THAT IS SAFE. Everything this returns is already
 * on the public album page: the photograph, its title, its link. No owner
 * fields, no counts, no settings, no e-mail. SS-1575 says display needs no
 * authentication of any kind because the source data is already public, and
 * this returns strictly less than the HTML page beside it.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: it does not fetch anything, cache anything
 * or render anything. Those belong to the DISPLAY side, which reads this over
 * HTTP and caches on a cron the way core/photoblogs-feed.php already does, so
 * no visitor ever waits on an outbound request.
 */

/** Photographs are served in pages of this size. SS-1575 says 100 per load. */
const POCKET_ALBUM_PAGE_SIZE = 100;

/** Hard ceiling, so a caller cannot ask for the whole archive in one request. */
const POCKET_ALBUM_MAX_PAGE_SIZE = 100;

/**
 * Build the public payload for one album.
 *
 * @param object $repository anything exposing archivePhotographs() and
 *                           albumById(); the real SnapPublicRepository does.
 * @return array{ok:bool,album:array,photographs:list<array>,page:int,next_page:int|null}
 */
function snapsmack_pocket_album_payload(
    $repository,
    int $albumId,
    int $page = 1,
    int $perPage = POCKET_ALBUM_PAGE_SIZE,
    string $baseUrl = ''
): array {
    if ($albumId <= 0) {
        return ['ok' => false, 'error' => 'an album id is required'];
    }
    $perPage = max(1, min(POCKET_ALBUM_MAX_PAGE_SIZE, $perPage));
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;

    // ASK FOR ONE MORE THAN WE SERVE. That is how next_page is known to be
    // real: a count-based guess goes wrong the moment a photograph is
    // unpublished between the two queries, and an endless scroll that asks
    // for a page which turns out to be empty looks broken to the visitor.
    $rows = $repository->archivePhotographs($perPage + 1, $offset, 0, $albumId);
    $rows = is_array($rows) ? array_values($rows) : [];
    $hasMore = count($rows) > $perPage;
    if ($hasMore) { $rows = array_slice($rows, 0, $perPage); }

    $base = rtrim($baseUrl, '/');
    $absolute = static function (string $path) use ($base): string {
        $path = trim($path);
        if ($path === '') { return ''; }
        if (preg_match('#^https?://#i', $path)) { return $path; }
        return $base . '/' . ltrim($path, '/');
    };

    $photographs = [];
    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }
        $slug = (string)($row['img_slug'] ?? '');
        // THE DISPLAY SITE MUST GET ABSOLUTE URLS. A relative path would
        // resolve against the DISPLAYING site and 404, and the photographs
        // are meant to stay on the server that owns them -- the same promise
        // photoblogs-feed makes by hotlinking each blog's own origin.
        $photographs[] = [
            'id' => (int)($row['id'] ?? 0),
            'title' => (string)($row['img_title'] ?? ''),
            'alt' => (string)($row['img_alt'] ?? $row['img_title'] ?? ''),
            'page_url' => $slug !== '' ? $absolute(rawurlencode($slug)) : '',
            'image_url' => $absolute((string)($row['img_file'] ?? '')),
            'thumb_url' => $absolute((string)($row['img_thumb_aspect'] ?? $row['img_file'] ?? '')),
            'square_url' => $absolute((string)($row['img_thumb_square'] ?? '')),
            // The renderer needs real dimensions or it cannot lay the gallery
            // out without guessing, which is what put 300x150 letterboxes on
            // the found textures archive.
            'width' => max(0, (int)($row['img_width'] ?? 0)),
            'height' => max(0, (int)($row['img_height'] ?? 0)),
            'date' => (string)($row['img_date'] ?? ''),
        ];
    }

    $album = ['id' => $albumId, 'name' => ''];
    if (method_exists($repository, 'albumById')) {
        $found = $repository->albumById($albumId);
        if (is_array($found)) {
            $album['name'] = (string)($found['album_name'] ?? $found['name'] ?? '');
        }
    }

    return [
        'ok' => true,
        'album' => $album,
        'photographs' => $photographs,
        'page' => $page,
        'per_page' => $perPage,
        'next_page' => $hasMore ? $page + 1 : null,
    ];
}

/**
 * Emit the payload as JSON with the headers a cross-site reader needs.
 *
 * Access-Control-Allow-Origin is * because this is public data and the point
 * is for another site to read it. It carries no cookie and no credential, so
 * there is no session for a hostile page to ride: a browser reading this
 * learns exactly what it would learn by opening the album page.
 */
function snapsmack_pocket_album_respond(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: public, max-age=300');
    if (empty($payload['ok'])) { http_response_code(404); }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

// ===== SNAPSMACK EOF =====
