<?php
/**
 * SNAPSMACK - Shared Thumbnail Generator
 *
 * Extracted from core/photo-editor-save.php so smack-maintenance.php can
 * call the same logic for batch regeneration without duplicating code.
 *
 * Generates two derivative files for a given source image:
 *   t_filename.jpg  — 300×300px square centre-crop   (img_thumb_square)
 *   a_filename.jpg  — max 600px aspect-preserving    (img_thumb_aspect)
 *
 * Thumbs land in a /thumbs/ subdirectory alongside the source file.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


/**
 * Generate square + aspect thumbnails for a given source image path.
 *
 * @param string $img_file  Relative or absolute path to the source image (as
 *                          stored in img_file — e.g. "uploads/2024/01/photo.jpg")
 * @param string $base_dir  Filesystem root to resolve relative paths against.
 *                          Typically __DIR__ of the calling script's repo root.
 * @param int    $sq_size   Square thumbnail dimension. Default 300.
 * @param int    $asp_max   Aspect thumbnail longest-edge limit. Default 600.
 *
 * @return array|false  On success: [
 *                        'sq_path'  => relative path to square thumb,
 *                        'asp_path' => relative path to aspect thumb,
 *                        'width'    => source image width (px),
 *                        'height'   => source image height (px),
 *                      ]
 *                      On failure: false  (caller should log/skip)
 */
function snapsmack_generate_thumbs(
    string $img_file,
    string $base_dir,
    int    $sq_size  = 300,
    int    $asp_max  = 600
): array|false {

    // Resolve full path
    $rel  = ltrim($img_file, '/');
    $full = rtrim($base_dir, '/') . '/' . $rel;

    if (!file_exists($full) || !is_readable($full)) {
        return false;
    }

    // Load source — support JPEG, PNG, WebP
    $info = @getimagesize($full);
    if (!$info) return false;

    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($full); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($full);  break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($full); break;
        default: return false;
    }
    if (!$src) return false;

    $w = imagesx($src);
    $h = imagesy($src);
    $base     = basename($rel);
    $dir      = dirname($rel);
    $thumb_dir_rel = $dir . '/thumbs';
    $thumb_dir     = rtrim($base_dir, '/') . '/' . $thumb_dir_rel;

    if (!is_dir($thumb_dir)) {
        mkdir($thumb_dir, 0755, true);
    }

    // ── Square thumbnail (t_) ─────────────────────────────────────────────
    $crop_dim = min($w, $h);
    $crop_x   = (int)(($w - $crop_dim) / 2);
    $crop_y   = (int)(($h - $crop_dim) / 2);
    $sq_img   = imagecreatetruecolor($sq_size, $sq_size);
    imagecopyresampled($sq_img, $src, 0, 0, $crop_x, $crop_y, $sq_size, $sq_size, $crop_dim, $crop_dim);
    $sq_rel = $thumb_dir_rel . '/t_' . $base;
    imagejpeg($sq_img, rtrim($base_dir, '/') . '/' . $sq_rel, 85);
    imagedestroy($sq_img);

    // ── Aspect thumbnail (a_) ─────────────────────────────────────────────
    if ($w >= $h) {
        $asp_w = $asp_max;
        $asp_h = (int)round($h * ($asp_max / $w));
    } else {
        $asp_h = $asp_max;
        $asp_w = (int)round($w * ($asp_max / $h));
    }
    $asp_img = imagecreatetruecolor($asp_w, $asp_h);
    imagecopyresampled($asp_img, $src, 0, 0, 0, 0, $asp_w, $asp_h, $w, $h);
    $asp_rel = $thumb_dir_rel . '/a_' . $base;
    imagejpeg($asp_img, rtrim($base_dir, '/') . '/' . $asp_rel, 85);
    imagedestroy($asp_img);

    imagedestroy($src);

    return [
        'sq_path'  => $sq_rel,
        'asp_path' => $asp_rel,
        'width'    => $w,
        'height'   => $h,
    ];
}
// ===== SNAPSMACK EOF =====
