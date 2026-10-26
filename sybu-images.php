<?php
/**
 * SNAPSMACK - SYBU Images Endpoint (COLD STORAGE)
 *
 * JSON endpoint for the desktop tools' offline image store. Serves the site's
 * Gallery (snap_images) to a sybu-keyed tool so COLD SNAP's COLD STORAGE tab
 * can mirror it locally, and accepts field-scoped metadata updates so AI-assisted
 * ALT/title/caption/colour edits made offline can land back on the image row.
 *
 * GET  ?page=1&per=100        — paged listing, newest first.
 * POST id=… [title=…] [description=…] [alt=…] [color_mode=…]
 *                             — update ONLY the provided fields on one image.
 *                               Metadata only: never the file, never status,
 *                               never deletion. Hashtag resync is deliberately
 *                               NOT run here (an edited caption's #tags do not
 *                               add/remove snap_tags rows from this door).
 *
 * Authentication: 'sybu' scoped Bearer key (or admin session), photoblog +
 * carousel modes — the same gate as sybu-data.php.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$GLOBALS['SNAP_API_KEY_TYPES']    = ['sybu'];
$GLOBALS['SNAP_API_REQUIRE_MODE'] = ['photoblog', 'carousel'];
require_once 'core/api-auth.php';
require_once 'core/alt-text.php';

header('Content-Type: application/json; charset=utf-8');

// img_alt / img_color_mode are lazily-added columns on older installs — same
// idempotent ALTERs smack-post-solo.php and image-ingest.php already run.
$pdo->exec("ALTER TABLE snap_images ADD COLUMN IF NOT EXISTS img_alt VARCHAR(500) NULL");
$pdo->exec("ALTER TABLE snap_images ADD COLUMN IF NOT EXISTS img_color_mode VARCHAR(10) NOT NULL DEFAULT ''");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ── Field-scoped metadata update ─────────────────────────────────────────
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'id required']);
        exit;
    }
    $sets = [];
    $params = [];
    if (array_key_exists('title', $_POST)) {
        $sets[] = 'img_title = ?';
        $params[] = trim((string)$_POST['title']);
    }
    if (array_key_exists('description', $_POST)) {
        $sets[] = 'img_description = ?';
        $params[] = trim((string)$_POST['description']);
    }
    if (array_key_exists('alt', $_POST)) {
        $sets[] = 'img_alt = ?';
        $params[] = snap_sanitize_alt((string)$_POST['alt']);
    }
    if (array_key_exists('color_mode', $_POST)) {
        $sets[] = 'img_color_mode = ?';
        $params[] = snap_normalize_color_mode((string)$_POST['color_mode']);
    }
    if (!$sets) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'no updatable field given']);
        exit;
    }
    $params[] = $id;
    $stmt = $pdo->prepare('UPDATE snap_images SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) {
        // Distinguish "no such image" from "same values" so the tool is honest.
        $exists = $pdo->prepare('SELECT 1 FROM snap_images WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'image not found']);
            exit;
        }
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── Paged listing, newest first ──────────────────────────────────────────────
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = (int)($_GET['per'] ?? 100);
$per  = max(1, min(200, $per));
$offset = ($page - 1) * $per;

$total = (int)$pdo->query('SELECT COUNT(*) FROM snap_images')->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT id, img_title, img_description, img_alt, img_color_mode, img_status,
            img_date, modified_at, img_file, img_thumb_square, img_thumb_aspect,
            img_width, img_height, img_checksum
     FROM snap_images
     ORDER BY id DESC
     LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $per, PDO::PARAM_INT);    // integer binds — native prepared
$stmt->bindValue(2, $offset, PDO::PARAM_INT); // statements reject string LIMITs
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$page_ids = array_map(static function ($row) { return (int)$row['id']; }, $rows);
$wanted = array_fill_keys($page_ids, true);
$buckets_by_image = [];
$placements_by_image = [];
$mosaics_by_image = [];

// A bucket is only a post's working set.  A placement is a photograph actually
// referenced by a post (lead/signature/inline or through a MOSAIC).  Report both
// so the desktop can identify honest "bucket only" and unused states.
if ($page_ids) {
    require_once __DIR__ . '/core/bucket.php';
    snap_bucket_ensure($pdo);
    $ph = implode(',', array_fill(0, count($page_ids), '?'));
    $bucket_stmt = $pdo->prepare(
        "SELECT b.image_id,p.id AS post_id,p.title,p.status
           FROM snap_bucket_items b JOIN snap_posts p ON p.id=b.post_id
          WHERE b.image_id IN ($ph)
       ORDER BY p.created_at DESC,p.id DESC,b.position ASC"
    );
    $bucket_stmt->execute($page_ids);
    foreach ($bucket_stmt->fetchAll(PDO::FETCH_ASSOC) as $membership) {
        $iid = (int)$membership['image_id'];
        $buckets_by_image[$iid][] = [
            'post_id' => (int)$membership['post_id'],
            'title' => (string)$membership['title'],
            'status' => (string)$membership['status'],
        ];
    }

    $add_placement = static function (int $iid, array $post, string $kind,
                                      ?int $mosaic_id = null) use (&$placements_by_image, $wanted): void {
        if (empty($wanted[$iid])) return;
        $key = (int)$post['id'] . ':' . $kind . ':' . (int)$mosaic_id;
        if (isset($placements_by_image[$iid][$key])) return;
        $entry = ['post_id' => (int)$post['id'], 'title' => (string)$post['title'],
                  'status' => (string)$post['status'], 'kind' => $kind];
        if ($mosaic_id) $entry['mosaic_id'] = $mosaic_id;
        $placements_by_image[$iid][$key] = $entry;
    };

    $posts = $pdo->query(
        "SELECT id,title,status,content,featured_image_id,signature_image_id
           FROM snap_posts WHERE post_type='longform'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $mosaic_posts = [];
    foreach ($posts as $post) {
        $lead = (int)($post['featured_image_id'] ?? 0);
        $signature = (int)($post['signature_image_id'] ?? 0);
        if ($lead) $add_placement($lead, $post, 'lead');
        if ($signature) $add_placement($signature, $post, 'signature');
        if (preg_match_all('/\[img:\s*g?\s*(\d+)/i', (string)$post['content'], $matches)) {
            foreach ($matches[1] as $iid) $add_placement((int)$iid, $post, 'inline');
        }
        if (preg_match_all('/\[mosaic:\s*(\d+)\s*\]/i', (string)$post['content'], $matches)) {
            foreach ($matches[1] as $mid) $mosaic_posts[(int)$mid][] = $post;
        }
    }

    try {
        $mosaic_rows = $pdo->query("SELECT id,title,asset_ids FROM snap_mosaics")
                           ->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $mosaic_rows = [];
    }
    foreach ($mosaic_rows as $mosaic) {
        $mid = (int)$mosaic['id'];
        $used_by = $mosaic_posts[$mid] ?? [];
        $used_summary = array_map(static function ($post) {
            return ['post_id' => (int)$post['id'], 'title' => (string)$post['title'],
                    'status' => (string)$post['status']];
        }, $used_by);
        $ids = json_decode((string)$mosaic['asset_ids'], true);
        if (!is_array($ids)) continue;
        foreach ($ids as $iid) {
            $iid = (int)$iid;
            if (empty($wanted[$iid])) continue;
            $mosaics_by_image[$iid][] = [
                'id' => $mid, 'title' => (string)$mosaic['title'],
                'used_by' => $used_summary,
            ];
            foreach ($used_by as $post) $add_placement($iid, $post, 'mosaic', $mid);
        }
    }
}

$images = [];
foreach ($rows as $row) {
    $iid = (int)$row['id'];
    $images[] = [
        'id'           => $iid,
        'title'        => (string)$row['img_title'],
        'description'  => (string)($row['img_description'] ?? ''),
        'alt'          => (string)($row['img_alt'] ?? ''),
        'color_mode'   => (string)($row['img_color_mode'] ?? ''),
        'status'       => (string)$row['img_status'],
        'img_date'     => (string)$row['img_date'],
        'modified_at'  => (string)$row['modified_at'],
        'file'         => (string)$row['img_file'],
        'thumb_square' => (string)($row['img_thumb_square'] ?? ''),
        'thumb_aspect' => (string)($row['img_thumb_aspect'] ?? ''),
        'width'        => (int)($row['img_width'] ?? 0),
        'height'       => (int)($row['img_height'] ?? 0),
        'checksum'     => (string)($row['img_checksum'] ?? ''),
        'usage_known'  => true,
        'buckets'      => array_values($buckets_by_image[$iid] ?? []),
        'placements'   => array_values($placements_by_image[$iid] ?? []),
        'mosaics'      => array_values($mosaics_by_image[$iid] ?? []),
    ];
}

echo json_encode([
    'ok'     => true,
    'total'  => $total,
    'page'   => $page,
    'per'    => $per,
    'images' => $images,
], JSON_UNESCAPED_SLASHES);

// ===== SNAPSMACK EOF =====
