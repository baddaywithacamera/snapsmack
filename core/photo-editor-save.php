<?php
/**
 * SNAPSMACK - Photo Editor Save Endpoint
 *
 * Receives a canvas blob from ss-engine-photo-editor.js, overwrites the
 * web-size image, and regenerates square + aspect thumbnails. The original
 * full-resolution upload is not touched — only the web copy and thumbs.
 */

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$post_id = (int)($_POST['post_id'] ?? 0);
if (!$post_id) {
    echo json_encode(['ok' => false, 'error' => 'Missing post_id']);
    exit;
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No image uploaded']);
    exit;
}

// Look up the image record
$stmt = $pdo->prepare("SELECT id, img_file, img_width, img_height FROM snap_images WHERE post_id = ? LIMIT 1");
$stmt->execute([$post_id]);
$img = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$img) {
    echo json_encode(['ok' => false, 'error' => 'Image not found']);
    exit;
}

$dest_path = $img['img_file'];
$dir       = dirname($dest_path);
$base      = basename($dest_path);
$thumb_dir = $dir . '/thumbs';

// ── SAVE WEB COPY ────────────────────────────────────────────────────────
$tmp = $_FILES['image']['tmp_name'];
$src = imagecreatefromstring(file_get_contents($tmp));

if (!$src) {
    echo json_encode(['ok' => false, 'error' => 'Invalid image data']);
    exit;
}

$new_w = imagesx($src);
$new_h = imagesy($src);

// Read the max image size from settings (respects user's configured limit)
$max_stmt = $pdo->prepare("SELECT setting_value FROM snap_settings WHERE setting_key = 'max_image_size'");
$max_stmt->execute();
$max_size = (int)($max_stmt->fetchColumn() ?: 1900);

// Scale down if larger than the configured max
if ($new_w > $max_size || $new_h > $max_size) {
    if ($new_w >= $new_h) {
        $scale = $max_size / $new_w;
    } else {
        $scale = $max_size / $new_h;
    }
    $scaled_w = (int)round($new_w * $scale);
    $scaled_h = (int)round($new_h * $scale);
    $scaled = imagecreatetruecolor($scaled_w, $scaled_h);
    imagecopyresampled($scaled, $src, 0, 0, 0, 0, $scaled_w, $scaled_h, $new_w, $new_h);
    imagedestroy($src);
    $src = $scaled;
    $new_w = $scaled_w;
    $new_h = $scaled_h;
}

// Save as JPEG
imagejpeg($src, $dest_path, 92);

// ── REGENERATE THUMBNAILS ────────────────────────────────────────────────
if (!is_dir($thumb_dir)) {
    mkdir($thumb_dir, 0755, true);
}

// Square thumbnail (t_)
$sq_size = 300;
$sq_thumb = imagecreatetruecolor($sq_size, $sq_size);
$crop_dim = min($new_w, $new_h);
$crop_x   = (int)(($new_w - $crop_dim) / 2);
$crop_y   = (int)(($new_h - $crop_dim) / 2);
imagecopyresampled($sq_thumb, $src, 0, 0, $crop_x, $crop_y, $sq_size, $sq_size, $crop_dim, $crop_dim);
imagejpeg($sq_thumb, $thumb_dir . '/t_' . $base, 85);
imagedestroy($sq_thumb);

// Aspect thumbnail (a_)
$asp_max = 600;
if ($new_w >= $new_h) {
    $asp_w = $asp_max;
    $asp_h = (int)round($new_h * ($asp_max / $new_w));
} else {
    $asp_h = $asp_max;
    $asp_w = (int)round($new_w * ($asp_max / $new_h));
}
$asp_thumb = imagecreatetruecolor($asp_w, $asp_h);
imagecopyresampled($asp_thumb, $src, 0, 0, 0, 0, $asp_w, $asp_h, $new_w, $new_h);
imagejpeg($asp_thumb, $thumb_dir . '/a_' . $base, 85);
imagedestroy($asp_thumb);

imagedestroy($src);

// ── UPDATE DB ────────────────────────────────────────────────────────────
$pdo->prepare("UPDATE snap_images SET img_width = ?, img_height = ?, img_thumb_square = ?, img_thumb_aspect = ? WHERE id = ?")
    ->execute([$new_w, $new_h, $thumb_dir . '/t_' . $base, $thumb_dir . '/a_' . $base, $img['id']]);

echo json_encode([
    'ok'     => true,
    'path'   => $dest_path,
    'width'  => $new_w,
    'height' => $new_h,
]);
// EOF
