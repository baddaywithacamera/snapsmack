<?php
/**
 * SNAPSMACK - SYBU Data Endpoint
 *
 * JSON endpoint consumed by Smack Your Batch Up at connect time.
 * Returns categories (with descriptions), albums (with descriptions),
 * and a combined tag list for Gemini prompt enrichment.
 *
 * Authentication: standard session cookie (same as all admin pages).
 * Method: GET
 * Response: application/json
 */

require_once 'core/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ── Categories ────────────────────────────────────────────────────────────────

$cats_raw = $pdo->query(
    "SELECT id, cat_name, cat_description
     FROM snap_categories
     ORDER BY cat_name ASC"
)->fetchAll();

$categories = [];
foreach ($cats_raw as $row) {
    $categories[] = [
        'id'          => (int) $row['id'],
        'name'        => $row['cat_name'],
        'description' => $row['cat_description'] ?? '',
    ];
}

// ── Albums ────────────────────────────────────────────────────────────────────

$albums_raw = $pdo->query(
    "SELECT id, album_name, album_description
     FROM snap_albums
     ORDER BY album_name ASC"
)->fetchAll();

$albums = [];
foreach ($albums_raw as $row) {
    $albums[] = [
        'id'          => (int) $row['id'],
        'name'        => $row['album_name'],
        'description' => $row['album_description'] ?? '',
    ];
}

// ── Tags ──────────────────────────────────────────────────────────────────────
// Union of top 50 by popularity and top 50 by recency, deduplicated.
// The slug is the normalised hashtag form (already lowercase, no spaces).

$tags_raw = $pdo->query(
    "(SELECT tag, use_count, created_at FROM snap_tags ORDER BY use_count DESC LIMIT 50)
     UNION
     (SELECT tag, use_count, created_at FROM snap_tags ORDER BY created_at DESC LIMIT 50)"
)->fetchAll();

// Deduplicate (UNION already does this for identical rows, but just in case)
$seen = [];
$tags = [];
foreach ($tags_raw as $row) {
    $key = strtolower($row['tag']);
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        // Normalise: ensure leading # and lowercase
        $tag = $row['tag'];
        if ($tag[0] !== '#') $tag = '#' . $tag;
        $tags[] = strtolower($tag);
    }
}
sort($tags);

// ── Response ──────────────────────────────────────────────────────────────────

echo json_encode([
    'categories' => $categories,
    'albums'     => $albums,
    'tags'       => $tags,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
