<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * Run snapsmack_public_paging_urls() with the ABSOLUTE BASE_URL a live install
 * produces, and print the result as JSON.
 *
 * This exists because BASE_URL is a constant. tests/paging-urls-keep-the-filter
 * -regression.php defines it as '/', and under '/' relativising a url changes
 * nothing -- so that file passed even with the relativisation removed from the
 * runtime. A test that cannot fail is not measuring anything.
 *
 * index.php builds BASE_URL from site_url, so on every real site it carries a
 * scheme and a host. That is the condition the contract has to hold under, so
 * that is the condition this probe creates.
 *
 * It is a fixture, not a test: it asserts nothing and exits 0. Its caller does
 * the asserting.
 */

define('BASE_URL', 'https://photowalk.ing/');
$root = dirname(__DIR__, 2);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/public-runtime.php';

$out = snapsmack_public_paging_urls([
    'kind' => 'archive',
    'page' => 3,
    'next_page' => 4,
    'previous_page' => 2,
    'taxonomy' => ['album_id' => 7],
    'total_pages' => 9,
]);

echo json_encode([
    'base_url' => BASE_URL,
    'next_url' => $out['next_url'] ?? null,
    'previous_url' => $out['previous_url'] ?? null,
], JSON_UNESCAPED_SLASHES), "\n";

// ===== SNAPSMACK EOF =====
