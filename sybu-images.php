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
 * Authentication: 'sybu' scoped Bearer key (or admin session), photoblog,
 * carousel and smacktalk modes — the same list as sybu-data.php.
 *
 * WHY smacktalk IS ON THAT LIST, and what it costs, because this is the one
 * door in this file that widened. Until 30 October 2026 the list was
 * ['photoblog', 'carousel'] and a smacktalk site answered 409 — which is
 * api-auth.php working exactly as designed, not a bug. The owner was running
 * COLD STORAGE against a longform site with 3,423 images and every sync
 * failed on it (SS-1019).
 *
 * The 409 was right to exist and the reason is at api-auth.php:58-66: on
 * 6 August 2026 a tool changed fauxlaroid.fyi's mode, every gram skin then
 * hid itself from the gallery, and activating a gram skin was the only
 * supported way back, so the site locked itself out of its own repair path.
 * That guard is untouched here. The mode is still never auto-flipped on a
 * site with content; only the list of modes a key-holder may work on grew.
 *
 * NOT THE SAME CASE AS sybu-data.php, said plainly rather than leaning on it
 * as precedent. That endpoint's own comment justifies smacktalk by being
 * "read-only metadata". This one is not read-only: the POST branch below
 * updates title, description, alt and colour on an image row. So this widens
 * a WRITE surface, and the control that remains is the scoped sybu Bearer
 * key plus the field list — not the mode. A key-holder can now edit image
 * metadata on a longform site. That is the trade, it was the owner's call,
 * and it is written here so nobody has to reconstruct it later.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$GLOBALS['SNAP_API_KEY_TYPES']    = ['sybu'];
$GLOBALS['SNAP_API_REQUIRE_MODE'] = ['photoblog', 'carousel', 'smacktalk'];
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

$images = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $images[] = [
        'id'           => (int)$row['id'],
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
