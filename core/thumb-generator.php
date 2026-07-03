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
    int    $asp_max  = 600,
    int    $focus_x  = 50,
    int    $focus_y  = 50,
    int    $zoom     = 100
): array|false {
    // $focus_x / $focus_y: square-crop focal point, 0-100 (% position of the
    //   crop window across the spare axis). 50/50 = centre.
    // $zoom: 100-300. Crop window = min(w,h)/(zoom/100), so 200 = half the
    //   short edge (2× tighter). Defaults (50/50/100) == the old centre crop.

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

    // ── Square thumbnail (t_) — focal-point + zoom crop ───────────────────
    $zoom     = max(100, min(300, $zoom));
    $crop_dim = (int)round(min($w, $h) / ($zoom / 100));
    if ($crop_dim < 1) $crop_dim = 1;
    $crop_x   = (int)round(($w - $crop_dim) * (max(0, min(100, $focus_x)) / 100));
    $crop_y   = (int)round(($h - $crop_dim) * (max(0, min(100, $focus_y)) / 100));
    $crop_x   = max(0, min($w - $crop_dim, $crop_x));
    $crop_y   = max(0, min($h - $crop_dim, $crop_y));
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

/**
 * Generate the FEDIVERSE BAKE (p_) — the square render that federates to
 * Pixelfed/Mastodon so remote grids mirror the blog's curated look
 * (Sean + Opus decision: the curated feed is the artwork; it's what ships).
 *
 * Unframed image (size_pct=100, border_px=0): the same focal-point + zoom
 * square crop as the t_ thumb, at federation resolution (default 1080²,
 * Instagram-standard).
 *
 * Framed image: recreates the Grid tile — matte canvas in bg_color, the
 * full image CONTAINED at size_pct% of the canvas, a border_px border in
 * border_color hugging the image. border_px is scaled from the 400px tile
 * reference up to bake resolution.
 *
 * Output: thumbs/p_<basename>.jpg (always JPEG regardless of source type —
 * the .jpg extension keeps the federated mediaType honest).
 *
 * @param array $style ['size_pct'=>int, 'border_px'=>int,
 *                      'border_color'=>'#rrggbb', 'bg_color'=>'#rrggbb']
 * @return string|false relative path to the bake, or false on failure.
 */
function snapsmack_generate_fedi_bake(
    string $img_file,
    string $base_dir,
    array  $style   = [],
    int    $focus_x = 50,
    int    $focus_y = 50,
    int    $zoom    = 100,
    int    $size    = 1080
): string|false {
    $rel  = ltrim($img_file, '/');
    $full = rtrim($base_dir, '/') . '/' . $rel;
    if (!file_exists($full) || !is_readable($full)) return false;

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
    $dir           = dirname($rel);
    $thumb_dir_rel = $dir . '/thumbs';
    $thumb_dir     = rtrim($base_dir, '/') . '/' . $thumb_dir_rel;
    if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);
    $out_rel  = $thumb_dir_rel . '/p_' . pathinfo($rel, PATHINFO_FILENAME) . '.jpg';
    $out_full = rtrim($base_dir, '/') . '/' . $out_rel;

    $sz_pct = max(10, min(100, (int)($style['size_pct']  ?? 100)));
    $bpx    = max(0,  min(50,  (int)($style['border_px'] ?? 0)));
    $framed = ($sz_pct < 100 || $bpx > 0);

    $hex = function (string $c, array $fallback) {
        return preg_match('/^#([0-9a-fA-F]{6})$/', $c, $m)
            ? [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))]
            : $fallback;
    };

    $dst = imagecreatetruecolor($size, $size);

    if (!$framed) {
        // Same crop math as the t_ thumb, at bake resolution.
        $zoom     = max(100, min(300, $zoom));
        $crop_dim = (int)round(min($w, $h) / ($zoom / 100));
        if ($crop_dim < 1) $crop_dim = 1;
        $crop_x = (int)round(($w - $crop_dim) * (max(0, min(100, $focus_x)) / 100));
        $crop_y = (int)round(($h - $crop_dim) * (max(0, min(100, $focus_y)) / 100));
        $crop_x = max(0, min($w - $crop_dim, $crop_x));
        $crop_y = max(0, min($h - $crop_dim, $crop_y));
        imagecopyresampled($dst, $src, 0, 0, $crop_x, $crop_y, $size, $size, $crop_dim, $crop_dim);
    } else {
        // Matte canvas + contained image + hugging border — the Grid tile look.
        [$br, $bg_, $bb] = $hex($style['bg_color'] ?? '#ffffff', [255, 255, 255]);
        imagefilledrectangle($dst, 0, 0, $size - 1, $size - 1,
            imagecolorallocate($dst, $br, $bg_, $bb));

        $bake_b = (int)round($bpx * $size / 400);              // 400px tile reference
        $avail  = $size - 2 * $bake_b;
        $long   = min((int)round($size * $sz_pct / 100), $avail);
        if ($long < 1) $long = 1;
        if ($w >= $h) { $iw = $long; $ih = max(1, (int)round($h * $long / $w)); }
        else          { $ih = $long; $iw = max(1, (int)round($w * $long / $h)); }
        $cx = (int)round(($size - $iw) / 2);
        $cy = (int)round(($size - $ih) / 2);

        if ($bake_b > 0) {
            [$rr, $rg, $rb] = $hex($style['border_color'] ?? '#000000', [0, 0, 0]);
            imagefilledrectangle($dst, $cx - $bake_b, $cy - $bake_b,
                $cx + $iw + $bake_b - 1, $cy + $ih + $bake_b - 1,
                imagecolorallocate($dst, $rr, $rg, $rb));
        }
        imagecopyresampled($dst, $src, $cx, $cy, 0, 0, $iw, $ih, $w, $h);
    }

    $ok = imagejpeg($dst, $out_full, 88);
    imagedestroy($dst);
    imagedestroy($src);
    return $ok ? $out_rel : false;
}
// ===== SNAPSMACK EOF =====
