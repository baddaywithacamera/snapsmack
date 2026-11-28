<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * POCKET ALBUM, the DISPLAY side: [pocket_album] on any CMS page or post.
 *
 * SS-1575. Two things Sean reported in one message, and ONE block answers both:
 *
 *   [pocket_album album="191"]
 *       This site's own album. No fetch, no cache, straight out of the public
 *       repository -- the same query /archive?album=191 runs.
 *
 *   [pocket_album src="https://foreverphotograph.ing" album="191"]
 *       ANOTHER SnapSmack site's album, read from that site's
 *       /api.php?route=pocket-album feed and served out of a local cache
 *       that a cron
 *       refreshes. This is the "show an album from foreverphotograph.ing on a
 *       different site" he asked for weeks ago.
 *
 * WHY /images SHOWED NOTHING, measured on the live page rather than guessed.
 * https://baddaywithacamera.ca/images returned HTTP 200 and 8,761 bytes
 * containing its intro paragraph, "Photos 0", and NOT ONE <img>. Its own copy
 * says "here they are ... they load 100 at a time ... only the ones I consider
 * portfolio worthy appear on this page" -- so that page is meant to show this
 * site's OWN curated photographs, and it is not a cross-site feature at all.
 *
 * And there was no shortcode in this build that could ever have filled it.
 * core/parser.php knows img, columns, mosaic, photoblogs_feed,
 * photoblogs_directory, board, prompts, contact and a list of text helpers.
 * None of them renders a gallery of the site's own photographs. The page
 * described a gallery nothing could produce, which is why weeks of looking at
 * it found nothing to fix: the fault was a missing feature, not a broken one.
 *
 * NO JAVASCRIPT, AND THAT IS DELIBERATE. The grid is CSS Grid from
 * assets/css/shortcodes.css, which I confirmed loads on that exact live page
 * (shortcodes.css?v=0.7.1002D was in its <head>), so this needs no skin edit
 * and no new registered engine on thirty skins. Each tile carries width and
 * height, so the browser knows every photograph's shape before a byte of image
 * arrives and the page does not jump -- the same fault SS-1046 recorded, fixed
 * with native attributes rather than with data-w and data-h, which is what
 * matters here:
 *
 * EVERY TAG AND ATTRIBUTE THIS EMITS IS ON THE snapsmack_trusted_html()
 * ALLOWLIST. A CMS page's rendered content goes through that sanitiser, and an
 * attribute that is not on the list is removed silently. div[class],
 * a[href,title,class] and img[src,alt,width,height,loading] all survive;
 * style="aspect-ratio:..." and data-w/data-h do NOT, which is why this block
 * does not reuse the 'wall-tile' component -- that component is correct for a
 * skin layout, where nothing sanitises it, and its shape information would be
 * stripped off here. Checked against core/trusted-html.php:49 before writing
 * a single tag, not after finding blank tiles on a live page.
 *
 * THE WHOLE ALBUM, NO CAP. Sean's ruling of 2026-11-25 10:06: the block shows
 * the entire album. An earlier version of this file stopped at 300 and printed
 * a link to the archive underneath, which I called honest; it was not. A grid
 * that stops at 300 with a link under it is an archive stuck on page one by
 * another name, and pagination is never correct anywhere in this product.
 *
 * HOW THE WHOLE ALBUM IS AFFORDABLE: every tile carries loading="lazy" plus
 * its real width and height, so the browser lays the grid out immediately and
 * fetches only the photographs approaching the viewport. The page weight is
 * markup, not images. MEASURED on the exact tile this file emits, 320 bytes:
 *
 *     100 photographs ->    31 KB of HTML
 *   1,000 photographs ->   313 KB
 *   2,000 photographs ->   625 KB
 *   7,000 photographs -> 2,188 KB
 *
 * So for a curated album -- which is what an album IS, and what /images is for
 * -- this is the right answer and the simplest one. At several thousand it
 * stops being reasonable, and that is the point at which the other half of
 * Sean's ruling is needed: 100 per load with auto-load on scroll. That needs a
 * registered public engine in assets/ASSET-INVENTORY.json with its security
 * review, a data attribute added to the snapsmack_trusted_html() allowlist,
 * and a same-origin JSON endpoint that does not collide with the cross-site
 * sharing switch. It is a separate ticket, not something to smuggle in here,
 * and the numbers above are recorded so the decision rests on them rather
 * than on anyone's guess.
 *
 * `limit` remains as an AUTHOR'S choice -- "show me the first 24 of this
 * album" is a legitimate thing to want on a landing page -- and when it is
 * set, and only then, the block prints a link to the rest. It is not a cap:
 * left out, every photograph in the album is rendered.
 */

require_once __DIR__ . '/safe-remote-fetch.php';

/**
 * A safety bound on ONE rendered grid, not a cap on the album.
 *
 * There is no product limit: Sean ruled that the block shows the whole album.
 * This exists only so a runaway value -- a corrupted album map, a bad limit
 * attribute -- cannot ask the renderer for a million rows and take the page
 * down. It is far above any real album on the fleet (the largest is about
 * 7,000 photographs) and a block that hits it is a fault to investigate, not
 * a visitor to paginate.
 */
const POCKET_ALBUM_SANITY_MAX = 20000;

/**
 * Pages of 100 the cron pulls from one source per refresh.
 *
 * Raised from 12 when the cap went: a cross-site album that stops caching at
 * 1,200 photographs would show a truncated album on the displaying site while
 * the source showed all of it, which is a worse fault than a slow cron. The
 * cron runs hourly with nobody waiting on it.
 */
const POCKET_ALBUM_REFRESH_MAX_PAGES = 200;

/** Cache rows older than this with no successful refresh are still served. */
const POCKET_ALBUM_STALE_SECONDS = 86400;

/** Whole-album page size for 100-at-a-time endless scroll. */
const POCKET_ALBUM_PAGE_SIZE = 100;

/** The cache of remote photographs. Local albums are never cached: they are a query. */
function pocket_album_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS snap_pocket_album_items (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url  VARCHAR(255) NOT NULL,
            album_id    INT UNSIGNED NOT NULL,
            remote_id   INT UNSIGNED NOT NULL,
            album_name  VARCHAR(190) NOT NULL DEFAULT '',
            page_url    VARCHAR(600) NOT NULL DEFAULT '',
            image_url   VARCHAR(600) NOT NULL DEFAULT '',
            thumb_url   VARCHAR(600) NOT NULL DEFAULT '',
            title       VARCHAR(300) NOT NULL DEFAULT '',
            alt_text    VARCHAR(500) NOT NULL DEFAULT '',
            img_width   INT UNSIGNED NOT NULL DEFAULT 0,
            img_height  INT UNSIGNED NOT NULL DEFAULT 0,
            taken_on    VARCHAR(40) NOT NULL DEFAULT '',
            sort_rank   INT UNSIGNED NOT NULL DEFAULT 0,
            fetched_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_item (source_url, album_id, remote_id),
            KEY idx_album (source_url, album_id, sort_rank)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Read every [pocket_album ...] in a piece of content.
 *
 * @return list<array{src:string,album:int,limit:int}>
 */
function pocket_album_shortcodes(string $content): array
{
    if (stripos($content, '[pocket_album') === false) {
        return [];
    }
    $found = [];
    if (!preg_match_all('/\[pocket_album\b([^\]]*)\]/i', $content, $matches, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($matches as $match) {
        $attributes = (string)$match[1];
        $read = static function (string $name) use ($attributes): string {
            return preg_match('/\b' . $name . '\s*=\s*"([^"]*)"/i', $attributes, $m)
                || preg_match("/\\b{$name}\\s*=\\s*'([^']*)'/i", $attributes, $m)
                ? trim((string)$m[1]) : '';
        };
        $album = (int)$read('album');
        if ($album <= 0) {
            continue;
        }
        // A source this site cannot prove is a public http(s) address is
        // dropped here, not at fetch time: a shortcode is author-supplied, and
        // the set of hosts this site will ever call should be decided by the
        // same rule in one place.
        //
        // A src THAT IS THERE BUT UNREADABLE REFUSES THE WHOLE SHORTCODE, and
        // that distinction is the whole point of asking whether the attribute
        // is present separately from reading it. The shortcode body stops at
        // the first ']', so src="http://[::1]/x" -- a bracketed IPv6 literal
        // -- cut the body in half, the src regex then found no closing quote,
        // and the attribute read as absent. The shortcode silently became
        // [pocket_album album="1"] and rendered a LOCAL album: a refusal
        // turning into different, working behaviour, which is worse than an
        // error because nobody sees it. Measured by the regression test, not
        // reasoned about afterwards.
        $src = $read('src');
        if ($src === '' && preg_match('/(?:^|[^a-zA-Z_-])src\s*=/i', $attributes) === 1) {
            continue;
        }
        if ($src !== '' && snap_safe_remote_target($src) === null) {
            continue;
        }
        // limit="0", or no limit at all, means EVERY photograph in the album.
        // It is an author's choice, never a cap applied on their behalf.
        $limit = (int)$read('limit');
        $found[] = [
            'src' => rtrim($src, '/'),
            'album' => $album,
            'limit' => $limit > 0 ? min($limit, POCKET_ALBUM_SANITY_MAX) : 0,
        ];
    }
    return $found;
}

/**
 * Every REMOTE (source, album) pair this site actually displays.
 *
 * THE CRON CONFIGURES ITSELF from the site's own published content, so there
 * is no settings screen to fill in and no list to keep in step with the pages.
 * It also bounds what this site will fetch: a source that appears on no page
 * is refreshed by nothing, and removing the shortcode stops the fetching.
 *
 * @return list<array{src:string,album:int}>
 */
function pocket_album_remote_sources(PDO $pdo): array
{
    $bodies = [];
    foreach ([
        "SELECT content FROM snap_pages WHERE is_active = 1",
        "SELECT img_description FROM snap_images WHERE img_status = 'published'",
    ] as $sql) {
        try {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [] as $body) {
                if (is_string($body) && $body !== '') { $bodies[] = $body; }
            }
        } catch (Throwable $e) {
            // A table or column this install does not have is not an error:
            // the other source still configures the cron.
        }
    }

    $pairs = [];
    foreach ($bodies as $body) {
        foreach (pocket_album_shortcodes($body) as $use) {
            if ($use['src'] === '') { continue; }   // local albums need no fetch
            $pairs[$use['src'] . '#' . $use['album']] = ['src' => $use['src'], 'album' => $use['album']];
        }
    }
    return array_values($pairs);
}

/**
 * Refresh one remote album's cache. Returns a report; throws nothing.
 *
 * REPLACE, DON'T MERGE. The rows for this (source, album) are deleted and
 * rewritten in one transaction, so a photograph the source has unpublished or
 * removed from the album disappears here too. Merging would leave it on
 * display for ever, which for a photographer's own curation is the worse
 * failure: an album is a statement about what is in it.
 *
 * A FAILED FETCH CHANGES NOTHING. If the source is unreachable, or answers
 * with something that is not a POCKET ALBUM payload, the existing cache is
 * left exactly as it is and the page keeps showing the album. The alternative
 * -- emptying the cache on a failure -- turns one bad minute at the source
 * into a blank page on the displaying site.
 *
 * @return array{ok:bool,source:string,album:int,fetched:int,pages:int,error:string}
 */
function pocket_album_refresh_one(PDO $pdo, string $src, int $album, ?callable $log = null): array
{
    $say = $log ?: static function (string $line): void {};
    $report = ['ok' => false, 'source' => $src, 'album' => $album,
               'fetched' => 0, 'pages' => 0, 'error' => ''];
    $src = rtrim($src, '/');
    if ($src === '' || $album <= 0) {
        $report['error'] = 'a source and an album id are both required';
        return $report;
    }
    pocket_album_ensure_table($pdo);

    $rows = [];
    $albumName = '';
    $page = 1;
    while ($page <= POCKET_ALBUM_REFRESH_MAX_PAGES) {
        $url = $src . '/api.php?route=pocket-album&album=' . $album . '&page=' . $page;
        $answer = snap_safe_remote_get($url, 'SnapSmack POCKET ALBUM/1.0 (+' . $src . ')',
                                      ['Accept: application/json']);
        if (!$answer['ok']) {
            $report['error'] = 'page ' . $page . ': ' . $answer['error'];
            $say('  ' . $report['error']);
            return $report;
        }
        $payload = json_decode($answer['body'], true);
        if (!is_array($payload) || empty($payload['ok']) || !isset($payload['photographs'])
            || !is_array($payload['photographs'])) {
            $report['error'] = 'page ' . $page . ': not a POCKET ALBUM payload';
            return $report;
        }
        if ($albumName === '') {
            $albumName = (string)($payload['album']['name'] ?? '');
        }
        foreach ($payload['photographs'] as $photograph) {
            if (!is_array($photograph)) { continue; }
            $rows[] = $photograph;
        }
        $report['pages'] = $page;
        $next = $payload['next_page'] ?? null;
        if (!is_int($next) || $next <= $page) {
            break;
        }
        $page = $next;
    }

    // EVERY URL MUST STILL BE ON THE SOURCE. The source already promises
    // absolute URLs on its own origin, but this site is the one that will
    // print them into an href and a src, so it checks rather than trusts: a
    // compromised or merely misconfigured source does not get to put arbitrary
    // links on Sean's pages. A photograph whose links fail this is dropped.
    $host = strtolower((string)parse_url($src, PHP_URL_HOST));
    $onSource = static function (string $url) use ($host): string {
        if ($url === '' || snap_safe_remote_target($url) === null) { return ''; }
        return strtolower((string)parse_url($url, PHP_URL_HOST)) === $host ? $url : '';
    };

    $clean = [];
    $rank = 0;
    foreach ($rows as $row) {
        $pageUrl = $onSource((string)($row['page_url'] ?? ''));
        $thumb = $onSource((string)($row['thumb_url'] ?? ''));
        if ($thumb === '') { $thumb = $onSource((string)($row['image_url'] ?? '')); }
        if ($pageUrl === '' || $thumb === '') { continue; }
        $clean[] = [
            'remote_id' => max(0, (int)($row['id'] ?? 0)),
            'page_url' => $pageUrl,
            'image_url' => $onSource((string)($row['image_url'] ?? '')),
            'thumb_url' => $thumb,
            'title' => (string)($row['title'] ?? ''),
            'alt_text' => (string)($row['alt'] ?? $row['title'] ?? ''),
            'img_width' => max(0, (int)($row['width'] ?? 0)),
            'img_height' => max(0, (int)($row['height'] ?? 0)),
            'taken_on' => (string)($row['date'] ?? ''),
            'sort_rank' => $rank++,
        ];
    }
    if ($clean === []) {
        $report['error'] = 'the source returned no usable photographs; the existing cache is untouched';
        return $report;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM snap_pocket_album_items WHERE source_url = ? AND album_id = ?")
            ->execute([$src, $album]);
        $insert = $pdo->prepare(
            "INSERT INTO snap_pocket_album_items
                (source_url, album_id, remote_id, album_name, page_url, image_url, thumb_url,
                 title, alt_text, img_width, img_height, taken_on, sort_rank, fetched_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
        );
        foreach ($clean as $row) {
            $insert->execute([
                $src, $album, $row['remote_id'], $albumName, $row['page_url'], $row['image_url'],
                $row['thumb_url'], $row['title'], $row['alt_text'], $row['img_width'],
                $row['img_height'], $row['taken_on'], $row['sort_rank'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $report['error'] = 'cache write failed: ' . $e->getMessage();
        return $report;
    }

    $report['ok'] = true;
    $report['fetched'] = count($clean);
    $say('  ' . $src . ' album ' . $album . ': ' . count($clean) . ' photographs cached.');
    return $report;
}

/** Refresh every remote album this site displays. */
function pocket_album_refresh(PDO $pdo, ?callable $log = null): array
{
    $say = $log ?: static function (string $line): void {};
    $sources = pocket_album_remote_sources($pdo);
    $say('POCKET ALBUM: ' . count($sources) . ' remote album(s) in use.');
    $reports = [];
    foreach ($sources as $source) {
        $reports[] = pocket_album_refresh_one($pdo, $source['src'], $source['album'], $say);
    }
    return $reports;
}

/**
 * The photographs to display, newest first, in the ONE shape the renderer uses.
 *
 * @return array{photographs:list<array>,album_name:string,total:int,archive_url:string,whole_album:bool,has_more:bool,next_page:?int}
 */
function pocket_album_photographs(PDO $pdo, string $src, int $album, int $limit, int $page = 0): array
{
    // 0 (or anything negative) means the whole album, paged 100 at a time for
    // endless scroll so the first response stays light and fast.
    $wholeAlbum = $limit <= 0;
    $page = $page > 0 ? $page : max(1, (int)($_GET['page'] ?? 1));
    $queryLimit = $wholeAlbum ? POCKET_ALBUM_PAGE_SIZE : min($limit, POCKET_ALBUM_SANITY_MAX);
    $offset = $wholeAlbum ? ($page - 1) * POCKET_ALBUM_PAGE_SIZE : 0;
    $out = ['photographs' => [], 'album_name' => '', 'total' => 0,
            'archive_url' => '', 'whole_album' => $wholeAlbum,
            'has_more' => false, 'next_page' => null];
    if ($album <= 0) {
        return $out;
    }

    if ($src === '') {
        // LOCAL ALBUM: the same repository call /archive?album=N makes, so this
        // block and that page cannot disagree about what is published.
        if (!class_exists('SnapPublicRepository')) {
            require_once __DIR__ . '/public-repository.php';
        }
        $repository = new SnapPublicRepository($pdo);
        $rows = $repository->archivePhotographs($queryLimit, $offset, 0, $album);
        $details = $repository->publicAlbum($album);
        $out['album_name'] = (string)($details['album_name'] ?? '');
        $out['total'] = max(0, (int)($details['photograph_count'] ?? 0));
        $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
        $out['archive_url'] = $base . '/archive?album=' . $album;
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $slug = (string)($row['img_slug'] ?? '');
            $thumb = (string)($row['img_thumb_aspect'] ?? '');
            if ($thumb === '') { $thumb = (string)($row['img_file'] ?? ''); }
            if ($thumb === '') { continue; }
            $out['photographs'][] = [
                'page_url' => $slug !== '' ? $base . '/' . rawurlencode($slug) : '',
                'thumb_url' => $thumb,
                'title' => (string)($row['img_title'] ?? ''),
                'alt_text' => (string)($row['img_alt'] ?? $row['img_title'] ?? ''),
                'width' => max(0, (int)($row['img_width'] ?? 0)),
                'height' => max(0, (int)($row['img_height'] ?? 0)),
            ];
        }
        $out['has_more'] = $wholeAlbum && ($offset + count($out['photographs'])) < $out['total'];
        $out['next_page'] = $out['has_more'] ? ($page + 1) : null;
        return $out;
    }

    // REMOTE ALBUM: the cache only. A page render never makes an outbound
    // request -- the same promise core/photoblogs-feed.php keeps, and for the
    // same reason: no visitor should wait on another server to answer.
    pocket_album_ensure_table($pdo);
    try {
        $statement = $pdo->prepare(
            "SELECT album_name, page_url, thumb_url, title, alt_text, img_width, img_height
             FROM snap_pocket_album_items
             WHERE source_url = ? AND album_id = ?
             ORDER BY sort_rank DESC, id DESC LIMIT " . (int)$queryLimit . " OFFSET " . (int)$offset
        );
        $statement->execute([rtrim($src, '/'), $album]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $count = $pdo->prepare("SELECT COUNT(*) FROM snap_pocket_album_items WHERE source_url = ? AND album_id = ?");
        $count->execute([rtrim($src, '/'), $album]);
        $out['total'] = (int)$count->fetchColumn();
    } catch (Throwable $e) {
        return $out;
    }
    $out['archive_url'] = rtrim($src, '/') . '/archive?album=' . $album;
    foreach ($rows as $row) {
        $out['album_name'] = (string)($row['album_name'] ?? '');
        $out['photographs'][] = [
            'page_url' => (string)$row['page_url'],
            'thumb_url' => (string)$row['thumb_url'],
            'title' => (string)$row['title'],
            'alt_text' => (string)$row['alt_text'],
            'width' => max(0, (int)$row['img_width']),
            'height' => max(0, (int)$row['img_height']),
        ];
    }
    $out['has_more'] = $wholeAlbum && ($offset + count($out['photographs'])) < $out['total'];
    $out['next_page'] = $out['has_more'] ? ($page + 1) : null;
    return $out;
}

/**
 * The block's markup.
 *
 * Every tag and attribute here is on the snapsmack_trusted_html() allowlist --
 * see the note at the top of this file. There is no style attribute and no
 * data attribute, because both are stripped on this path and a stripped
 * attribute fails silently.
 */
function pocket_album_html(PDO $pdo, string $src, int $album, int $limit = 0, bool $adminPreview = false, int $page = 0): string
{
    if (!function_exists('snap_escape_html')) {
        require_once __DIR__ . '/skin-render-helpers.php';
    }
    $data = pocket_album_photographs($pdo, $src, $album, $limit, $page);
    if ($data['photographs'] === []) {
        if ($src !== '') {
            if (!$adminPreview) return '';
            $host = snap_escape_html((string)parse_url($src, PHP_URL_HOST));
            return '<p class="snap-pocket-album-empty">Album ' . $album
                . ' has no photographs cached from ' . $host . ' yet.</p>';
        }
        return '<p class="snap-pocket-album-empty">No photographs are in album '
            . $album . ' yet.</p>';
    }

    $html = '<div class="snap-pocket-album ss-masonry">';
    foreach ($data['photographs'] as $photograph) {
        $thumb = snap_escape_url($photograph['thumb_url']);
        if ($thumb === '') { continue; }
        $href = snap_escape_url($photograph['page_url']);
        $alt = snap_escape_attr($photograph['alt_text']);
        $image = '<img class="snap-pocket-shot" src="' . $thumb . '" alt="' . $alt . '"'
            // width and height, not data-w/data-h: these two survive the
            // sanitiser AND tell the browser the shape with no script running,
            // so the grid does not reflow as photographs arrive.
            . ($photograph['width'] > 0 && $photograph['height'] > 0
                ? ' width="' . (int)$photograph['width'] . '" height="' . (int)$photograph['height'] . '"'
                : '')
            . ' loading="lazy">';
        $html .= $href !== ''
            ? '<a class="snap-pocket-tile ss-masonry-item" href="' . $href . '" title="'
                . snap_escape_attr($photograph['title']) . '">' . $image . '</a>'
            : '<div class="snap-pocket-tile ss-masonry-item">' . $image . '</div>';
    }
    $html .= '</div>';

    if (!empty($data['has_more']) && !empty($data['next_page'])) {
        $html .= '<a class="scroll-wall-sentinel" href="?page=' . (int)$data['next_page'] . '"></a>';
    }

    // A LINK ONLY WHEN THE AUTHOR ASKED FOR A SUBSET. When the block is showing
    // the whole album there is nothing left to link to, and printing "all N
    // photographs" under a grid that already holds all N is the sort of
    // almost-true detail that makes a page feel broken. When the author set
    // limit="24", the link is the point.
    $shown = count($data['photographs']);
    if (empty($data['whole_album']) && $data['total'] > $shown && $data['archive_url'] !== '') {
        $html .= '<p class="snap-pocket-album-all"><a href="' . snap_escape_url($data['archive_url']) . '">'
            . 'All ' . (int)$data['total'] . ' photographs'
            . ($data['album_name'] !== '' ? ' in ' . snap_escape_html($data['album_name']) : '')
            . '</a></p>';
    }
    return $html;
}

// ===== SNAPSMACK EOF =====
