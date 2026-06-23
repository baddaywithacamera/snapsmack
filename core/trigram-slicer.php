<?php
/**
 * SNAPSMACK - Trigram / Triptych Image Slicer
 *
 * Shared GD engine that cuts one wide (or tall) source image into THREE square
 * images. Used by two callers:
 *   - Trigram admin tool  → slices become grid COVERS, written to trigrams/,
 *     assigned to three existing posts via snap_posts.trigram_id.
 *   - Triptych tool       → slices become post IMAGES, written to uploads/,
 *     three new draft posts created.
 *
 * Spec: _spec/the-grid.md (Trigrams §, Triptych §). Data model: snap_trigrams
 * (source_path, orientation h|v, cut_a, cut_b, post_id_1/2/3).
 *
 * The cut points are pixel positions along the cut axis (x for horizontal /
 * L-M-R, y for vertical / T-M-B). For a clean 3:1 source the even-thirds
 * defaults (axis/3, 2*axis/3) already produce three square thirds; non-square
 * sources are centre-cropped to a square within each third (cover fit), which
 * upscales an under-size third rather than letterboxing it.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


if (!function_exists('trigram_default_cuts')) {
    /**
     * Even-thirds cut points for a source of the given length along the cut axis.
     *
     * @return array{0:int,1:int} [cut_a, cut_b]
     */
    function trigram_default_cuts(int $axis_len): array
    {
        $axis_len = max(3, $axis_len);
        return [(int) round($axis_len / 3), (int) round($axis_len * 2 / 3)];
    }
}

if (!function_exists('trigram_slice_image')) {
    /**
     * Slice a source image into three square JPEGs.
     *
     * @param string $src_path    Absolute path to the source (jpg/png/webp).
     * @param string $orientation 'h' = vertical cuts → L/M/R; 'v' = horizontal cuts → T/M/B.
     * @param int    $cut_a       First cut point (px along the cut axis). <=0 = even thirds.
     * @param int    $cut_b       Second cut point (px along the cut axis). <=0 = even thirds.
     * @param int    $out_size    Output square edge in px (e.g. 1080).
     * @param string $dest_dir    Absolute directory to write into (created if missing).
     * @param string $prefix      Filename prefix, e.g. "trigram-12" → trigram-12-L.jpg.
     * @param int    $quality     JPEG quality 1-100 (default 88).
     * @return array{ok:bool, files?:array<string,string>, slots?:array<int,string>, error?:string}
     *         On success: files keyed by slot label (L/M/R or T/M/B) → written filename;
     *         slots is the ordered [1=>label,2=>label,3=>label] map.
     */
    function trigram_slice_image(
        string $src_path,
        string $orientation,
        int $cut_a,
        int $cut_b,
        int $out_size,
        string $dest_dir,
        string $prefix,
        int $quality = 88
    ): array {
        if (!function_exists('imagecreatetruecolor')) {
            return ['ok' => false, 'error' => 'GD extension not available'];
        }
        if (!is_file($src_path)) {
            return ['ok' => false, 'error' => 'source image not found'];
        }

        $info = @getimagesize($src_path);
        if (!$info) {
            return ['ok' => false, 'error' => 'source is not a readable image'];
        }
        [$w, $h] = $info;
        $mime = $info['mime'] ?? '';

        switch ($mime) {
            case 'image/jpeg':
                $src = @imagecreatefromjpeg($src_path);
                break;
            case 'image/png':
                $src = @imagecreatefrompng($src_path);
                break;
            case 'image/webp':
                $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src_path) : false;
                break;
            default:
                return ['ok' => false, 'error' => 'unsupported image type: ' . $mime];
        }
        if (!$src) {
            return ['ok' => false, 'error' => 'failed to decode source image'];
        }

        $orientation = ($orientation === 'v') ? 'v' : 'h';
        $axis_len    = ($orientation === 'v') ? $h : $w;

        // Default to even thirds when caller passes non-positive cut points.
        if ($cut_a <= 0 || $cut_b <= 0) {
            [$cut_a, $cut_b] = trigram_default_cuts($axis_len);
        }
        // Clamp + order so the three regions are always positive width/height.
        $cut_a = max(1, min($axis_len - 2, $cut_a));
        $cut_b = max($cut_a + 1, min($axis_len - 1, $cut_b));

        if ($orientation === 'v') {
            $labels  = ['T', 'M', 'B'];
            // [x, y, w, h]
            $regions = [
                [0, 0,      $w, $cut_a],
                [0, $cut_a, $w, $cut_b - $cut_a],
                [0, $cut_b, $w, $h - $cut_b],
            ];
        } else {
            $labels  = ['L', 'M', 'R'];
            $regions = [
                [0,      0, $cut_a,        $h],
                [$cut_a, 0, $cut_b - $cut_a, $h],
                [$cut_b, 0, $w - $cut_b,    $h],
            ];
        }

        if (!is_dir($dest_dir)) {
            @mkdir($dest_dir, 0755, true);
        }
        if (!is_dir($dest_dir) || !is_writable($dest_dir)) {
            imagedestroy($src);
            return ['ok' => false, 'error' => 'destination directory not writable: ' . $dest_dir];
        }

        $out_size = max(64, $out_size);
        $files    = [];
        $slots    = [];

        foreach ($regions as $idx => [$rx, $ry, $rw, $rh]) {
            if ($rw < 1 || $rh < 1) {
                imagedestroy($src);
                return ['ok' => false, 'error' => 'degenerate slice region (cut points too close to an edge)'];
            }

            // Centre square-crop within the region (cover fit), then resample to
            // out_size. min() picks the limiting dimension so we crop, never pad;
            // upscaling happens automatically when the square < out_size.
            $sq    = min($rw, $rh);
            $off_x = $rx + (int) (($rw - $sq) / 2);
            $off_y = $ry + (int) (($rh - $sq) / 2);

            $dst = imagecreatetruecolor($out_size, $out_size);
            if (!$dst) {
                imagedestroy($src);
                return ['ok' => false, 'error' => 'could not allocate output canvas'];
            }
            // Flatten any source transparency onto white (covers are opaque JPEGs).
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $out_size, $out_size, $white);
            imagecopyresampled($dst, $src, 0, 0, $off_x, $off_y, $out_size, $out_size, $sq, $sq);

            $fname = $prefix . '-' . $labels[$idx] . '.jpg';
            $ok    = imagejpeg($dst, rtrim($dest_dir, '/') . '/' . $fname, max(1, min(100, $quality)));
            imagedestroy($dst);

            if (!$ok) {
                imagedestroy($src);
                return ['ok' => false, 'error' => 'failed to write slice: ' . $fname];
            }
            $files[$labels[$idx]] = $fname;
            $slots[$idx + 1]      = $labels[$idx];
        }

        imagedestroy($src);
        return ['ok' => true, 'files' => $files, 'slots' => $slots];
    }
}
// ===== SNAPSMACK EOF =====
