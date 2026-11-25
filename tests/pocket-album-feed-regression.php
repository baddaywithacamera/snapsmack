<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// POCKET ALBUM, SOURCE SIDE: one album's photographs, publicly readable.
//
// SS-1575. Sean asked weeks ago to show an album from one of his sites on
// another. baddaywithacamera.ca/images has shown nothing in public since, and
// that site is FEATURED on snapsmack.ca as a working TILEZ showcase. In his
// words: "it keeps getting parked."
//
// WHY IT COULD NOT BE BUILT UNTIL NOW, measured on the live source site
// rather than read out of the code:
//
//   /?view=archive&album=191&format=json   HTML (that wire is PHOTOGRAM's)
//   /rss?album=191                         the SITE feed, byte-for-byte
//                                          identical to /rss -- album ignored
//   /api/albums/191                        404
//
// There was no machine-readable album anywhere, so the display side had
// nothing to read. That is the real reason this sat: not a disagreement about
// the block, a missing feed.
//
// Run:  php tests/pocket-album-feed-regression.php

$root = dirname(__DIR__);
require_once $root . '/core/pocket-album-feed.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

/**
 * A repository that records what it was asked and holds 250 photographs.
 *
 * It implements the SAME signature as SnapPublicRepository::archivePhotographs,
 * because the point of this feed is that it asks the real repository rather
 * than carrying its own SQL -- a second copy of that query is how the feed and
 * the album page drift into disagreeing about what is published.
 */
final class PocketAlbumFakeRepository
{
    public array $calls = [];
    public int $total = 250;

    public function archivePhotographs(int $limit, int $offset = 0, int $categoryId = 0,
                                       int $albumId = 0, string $search = ''): array {
        $this->calls[] = ['limit' => $limit, 'offset' => $offset,
                          'category' => $categoryId, 'album' => $albumId];
        $rows = [];
        for ($i = 0; $i < $limit && ($offset + $i) < $this->total; $i++) {
            $n = $offset + $i + 1;
            $rows[] = [
                'id' => $n, 'img_slug' => 'photo-' . $n, 'img_title' => 'Photo ' . $n,
                'img_alt' => 'Alt ' . $n, 'img_file' => '/media/p' . $n . '.jpg',
                'img_thumb_aspect' => '/media/p' . $n . '-a.jpg',
                'img_thumb_square' => '/media/p' . $n . '-s.jpg',
                'img_width' => 1200, 'img_height' => 800, 'img_date' => '2026-11-01',
            ];
        }
        return $rows;
    }

    // publicAlbum() IS THE REAL METHOD NAME on SnapPublicRepository
    // (core/public-repository.php:1000). This fake offered albumById() when it
    // was first written, which is a name the live repository does not have --
    // so every check here passed while the real site would have served a blank
    // album name. A fake that answers to a name production does not use proves
    // nothing about production.
    public function publicAlbum(int $id): ?array {
        return ['id' => $id, 'album_name' => 'Found Textures',
                'view_count' => 12, 'photograph_count' => 250];
    }
}

$SOURCE = 'https://foreverphotograph.ing';
echo "POCKET ALBUM source feed\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "IT ASKS THE REPOSITORY, AND ASKS IT FOR THE RIGHT ALBUM\n";
// ─────────────────────────────────────────────────────────────────────────────

$repo = new PocketAlbumFakeRepository();
$page1 = snapsmack_pocket_album_payload($repo, 191, 1, POCKET_ALBUM_PAGE_SIZE, $SOURCE);

$check('it calls archivePhotographs', $repo->calls !== []);
$check('  scoped to the album asked for', ($repo->calls[0]['album'] ?? 0) === 191);
$check('  with no category filter, so the whole album is in scope',
       ($repo->calls[0]['category'] ?? -1) === 0);
$check('  from the start of the album on page one', ($repo->calls[0]['offset'] ?? -1) === 0);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nONE HUNDRED PER LOAD, AND next_page IS KNOWN RATHER THAN GUESSED\n";
// ─────────────────────────────────────────────────────────────────────────────
// It asks for ONE MORE than it serves. A count-based guess goes wrong the
// moment a photograph is unpublished between the two queries, and an endless
// scroll that fetches a page which turns out to be empty looks broken.

$check('a page carries 100 photographs', count($page1['photographs']) === 100);
$check('  but the repository was asked for 101',
       ($repo->calls[0]['limit'] ?? 0) === POCKET_ALBUM_PAGE_SIZE + 1);
$check('  and next_page points at the second page', $page1['next_page'] === 2);

$page3 = snapsmack_pocket_album_payload($repo, 191, 3, POCKET_ALBUM_PAGE_SIZE, $SOURCE);
$check('the last page carries the remainder', count($page3['photographs']) === 50);
$check('  and next_page is null, so the scroll stops', $page3['next_page'] === null);

$page9 = snapsmack_pocket_album_payload($repo, 191, 9, POCKET_ALBUM_PAGE_SIZE, $SOURCE);
$check('a page past the end is empty rather than an error',
       $page9['ok'] === true && $page9['photographs'] === [] && $page9['next_page'] === null);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEVERY URL IS ABSOLUTE, ON THE SITE THAT OWNS THE PHOTOGRAPH\n";
// ─────────────────────────────────────────────────────────────────────────────
// A relative path would resolve against the DISPLAYING site and 404. The
// photographs stay on the server that owns them, which is the same promise
// core/photoblogs-feed.php keeps by hotlinking each blog's own origin.

$one = $page1['photographs'][0];
foreach (['page_url', 'image_url', 'thumb_url', 'square_url'] as $field) {
    $check("  {$field} is absolute and on the source site",
           str_starts_with((string)$one[$field], $SOURCE . '/'));
}
$check('  and none of them points at the displaying site',
       !str_contains(json_encode($page1), 'baddaywithacamera'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nIT CARRIES REAL DIMENSIONS, BECAUSE THE RENDERER NEEDS THEM\n";
// ─────────────────────────────────────────────────────────────────────────────
// A gallery that has to guess a shape is how the found textures archive ended
// up drawing every photograph as a 300x150 letterbox.

$check('width and height are present', $one['width'] === 1200 && $one['height'] === 800);
$check('  and a photograph with no stored size reports 0 rather than inventing one',
       (static function () use ($SOURCE) {
           $bare = new class {
               public function archivePhotographs(int $limit, int $offset = 0, int $categoryId = 0,
                                                   int $albumId = 0, string $search = ''): array {
                   return [['id' => 1, 'img_slug' => 's', 'img_file' => '/media/a.jpg']];
               }
           };
           $payload = snapsmack_pocket_album_payload($bare, 191, 1, 100, $SOURCE);
           return $payload['photographs'][0]['width'] === 0;
       })());

// ─────────────────────────────────────────────────────────────────────────────
echo "\nIT RETURNS LESS THAN THE PUBLIC ALBUM PAGE ALREADY SHOWS\n";
// ─────────────────────────────────────────────────────────────────────────────
// SS-1575: display needs no authentication because the source data is already
// public. That holds only while this returns nothing the album page does not.

$flat = json_encode($page1);
foreach (['email', 'password', 'token', 'setting', 'user_id', 'owner', 'secret'] as $leak) {
    $check("  no {$leak} field", !str_contains(strtolower($flat), $leak));
}
$check('  each photograph carries exactly the display fields and no more',
       array_keys($one) === ['id', 'title', 'alt', 'page_url', 'image_url',
                             'thumb_url', 'square_url', 'width', 'height', 'date']);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nREFUSALS\n";
// ─────────────────────────────────────────────────────────────────────────────

$check('no album id is refused',
       snapsmack_pocket_album_payload($repo, 0)['ok'] === false);
$check('a negative album id is refused',
       snapsmack_pocket_album_payload($repo, -5)['ok'] === false);
$check('a caller cannot ask for the whole archive in one request',
       snapsmack_pocket_album_payload($repo, 191, 1, 9999)['per_page']
           === POCKET_ALBUM_MAX_PAGE_SIZE);
$check('  nor for zero per page', snapsmack_pocket_album_payload($repo, 191, 1, 0)['per_page'] >= 1);
$check('page 0 is treated as page one',
       snapsmack_pocket_album_payload($repo, 191, 0, 100, $SOURCE)['page'] === 1);
$check('the album name comes back from publicAlbum, the real method',
       $page1['album']['name'] === 'Found Textures');
$check('  and the published count comes with it, recomputed from the join',
       $page1['album']['photograph_count'] === 250);
$check('a repository exposing only the old albumById name still works',
       (static function () use ($SOURCE) {
           $old = new class {
               public function archivePhotographs(int $l, int $o = 0, int $c = 0,
                                                   int $a = 0, string $s = ''): array { return []; }
               public function albumById(int $id): ?array { return ['album_name' => 'Legacy']; }
           };
           return snapsmack_pocket_album_payload($old, 191, 1, 100, $SOURCE)['album']['name'] === 'Legacy';
       })());
$check('a repository with no album lookup at all still works',
       (static function () use ($SOURCE) {
           $noName = new class {
               public function archivePhotographs(int $l, int $o = 0, int $c = 0,
                                                   int $a = 0, string $s = ''): array { return []; }
           };
           return snapsmack_pocket_album_payload($noName, 191, 1, 100, $SOURCE)['ok'] === true;
       })());

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — the source feed serves one album, publicly, 100 at a time.\n";
echo "NOT DONE YET, and this feed is useless alone: the DISPLAY side -- the\n";
echo "page block, the cron-refreshed cache, and the masonry render -- is the\n";
echo "other half. Nothing shows on baddaywithacamera.ca until that lands AND\n";
echo "both sites run a build carrying it.\n";
exit(0);

// ===== SNAPSMACK EOF =====
