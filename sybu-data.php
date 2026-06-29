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

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// SYBU holds a least-privilege 'sybu' scoped key and only operates on photoblog
// (SMACKONEOUT) sites. Declared before api-auth: the typed-Bearer branch accepts
// the key; the mode gate refuses non-photoblog TOOL access (browser sessions are
// exempt). Field-level write scope (chunk 3) is PENDING Sean's confirm. Legacy
// X-Snap-Key + admin session still work (additive).
$GLOBALS['SNAP_API_KEY_TYPES']    = ['sybu'];
// Read-only metadata (cats/albums/tags/titles) — valid on photo modes alike.
// Allow gram (carousel) as well as photoblog so SUMNABATCH can connect to The
// Grid sites, not just solo photoblogs.
$GLOBALS['SNAP_API_REQUIRE_MODE'] = ['photoblog', 'carousel'];
require_once 'core/api-auth.php';

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

// ── Titles ────────────────────────────────────────────────────────────────────
// All existing post titles — used by SYBU to prevent Gemini generating duplicates.

$titles_raw = $pdo->query(
    "SELECT img_title FROM snap_images ORDER BY id ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$titles = array_values(array_filter(array_map('trim', $titles_raw)));

// ── Response ──────────────────────────────────────────────────────────────────

echo json_encode([
    'categories' => $categories,
    'albums'     => $albums,
    'tags'       => $tags,
    'titles'     => $titles,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
// ===== SNAPSMACK EOF =====
