<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The legacy archive must never print the whole library again.
 *
 * archive.php selected every published photograph and rendered all of them.
 * foundtextures.ca answered /archive.php with 1,592 images in one 1.18 MB
 * document, and would have done the same at ten thousand. It was not a
 * regression: the same query, with no LIMIT, is there at tag v0.7.603D.
 *
 * Source assertions rather than a rendered page, because this one needs a
 * database. What is pinned is the shape: a bounded query, a count that
 * survives the filter joins, and links that carry the filters with them.
 */

$root = dirname(__DIR__);
$fail = [];
$check = static function (bool $ok, string $what) use (&$fail): void {
    if (!$ok) $fail[] = $what;
};

$archive = (string)file_get_contents($root . '/archive.php');

// ── The listing is bounded ──────────────────────────────────────────────────
$check(str_contains($archive, 'LIMIT " . (int)$per_page . " OFFSET " . (int)$offset'),
    'the archive query is unbounded again -- it will print the whole library');
$check(preg_match('/\$per_page\s*=\s*max\(\s*12\s*,\s*min\(\s*500\s*,/', $archive) === 1,
    'the page size is not clamped, so a bad setting can unbound the page');

// ── The count has to agree with the listing ─────────────────────────────────
// The filter joins multiply rows, which is why the listing selects DISTINCT.
// COUNT(*) over those joins would overcount and invent pages that are empty.
$check(str_contains($archive, 'SELECT COUNT(DISTINCT i.id)'),
    'the total is counted without DISTINCT, so the page count will not match the listing');
$check(!preg_match('/SELECT COUNT\(\*\)/', $archive),
    'a COUNT(*) crept back into the archive');

// ── Links carry the reader's filters ────────────────────────────────────────
// Every control on this page works by reloading with query parameters. A
// pager that dropped them would answer page two with the unfiltered archive.
$check(str_contains($archive, '$qs = $_GET;'),
    'the pager does not start from the current query string');
$check(str_contains($archive, "http_build_query(\$qs)"),
    'the pager does not rebuild the query string it was given');
$check(str_contains($archive, "\$_GET['page'] ?? \$_GET['p'] ?? 1"),
    'the archive reads neither ?page= nor the historical ?p=');

// ── And it tells the reader where they are ──────────────────────────────────
$check(str_contains($archive, 'archive-paging'),
    'the paging row is gone from the markup');
$paging_css = (string)file_get_contents($root . '/assets/css/page-archive.css');
$check(str_contains($paging_css, '.archive-paging {'),
    'the paging row has no styling, so it renders as bare browser links');

if ($fail) {
    fwrite(STDERR, "FAIL: archive paging\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "PASS: the archive takes one page at a time, counted honestly, filters intact.\n";
// ===== SNAPSMACK EOF =====
