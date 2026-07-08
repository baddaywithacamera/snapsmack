<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with the canonical PHP EOF marker
// (// ===== SNAPSMACK EOF =====). Missing marker on read = treat as truncated.
/**
 * Minimal pure-PHP Blurhash encoder (no dependencies) + a lazy cache helper.
 *
 * Blurhash is the compact string Pixelfed/Mastodon use to paint a blurred
 * colour placeholder while a photo loads. SnapSmack computes it ON DEMAND the
 * first time an image federates (snapsmack_ensure_image_blurhash) and caches it
 * in snap_images.blurhash, so there is ONE code path and no need to touch every
 * thumbnail/import call site. Absence is harmless — the field is simply omitted
 * from the AP attachment.
 *
 * Algorithm follows the reference Blurhash (Wolt): sRGB<->linear + a small DCT.
 * Kept cheap by encoding a 32x32 downsample, not the full image.
 */

if (!function_exists('snapsmack_bh_srgb_to_linear')) {

function snapsmack_bh_srgb_to_linear(int $v): float {
    $x = $v / 255.0;
    return ($x <= 0.04045) ? $x / 12.92 : pow(($x + 0.055) / 1.055, 2.4);
}

function snapsmack_bh_linear_to_srgb(float $v): int {
    $x = max(0.0, min(1.0, $v));
    return ($x <= 0.0031308)
        ? (int)round($x * 12.92 * 255 + 0.5)
        : (int)round((1.055 * pow($x, 1 / 2.4) - 0.055) * 255 + 0.5);
}

function snapsmack_bh_sign_pow(float $val, float $exp): float {
    return ($val < 0 ? -1.0 : 1.0) * pow(abs($val), $exp);
}

function snapsmack_bh_encode83(int $value, int $length): string {
    $chars  = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';
    $result = '';
    for ($i = 1; $i <= $length; $i++) {
        $digit   = (int)floor($value / pow(83, $length - $i)) % 83;
        $result .= $chars[$digit];
    }
    return $result;
}

/**
 * Encode a GD image resource to a Blurhash string. $x/$y are component counts
 * (1..9). Returns '' on any failure.
 */
function snapsmack_blurhash_encode($image, int $componentsX = 4, int $componentsY = 3): string {
    $componentsX = max(1, min(9, $componentsX));
    $componentsY = max(1, min(9, $componentsY));
    $width  = @imagesx($image);
    $height = @imagesy($image);
    if (!$width || !$height) return '';

    $factors = [];
    for ($cy = 0; $cy < $componentsY; $cy++) {
        for ($cx = 0; $cx < $componentsX; $cx++) {
            $norm = ($cx === 0 && $cy === 0) ? 1 : 2;
            $r = 0.0; $g = 0.0; $b = 0.0;
            for ($i = 0; $i < $width; $i++) {
                for ($j = 0; $j < $height; $j++) {
                    $basis = $norm
                        * cos((M_PI * $cx * $i) / $width)
                        * cos((M_PI * $cy * $j) / $height);
                    $rgb = imagecolorat($image, $i, $j);
                    $r += $basis * snapsmack_bh_srgb_to_linear(($rgb >> 16) & 0xFF);
                    $g += $basis * snapsmack_bh_srgb_to_linear(($rgb >> 8) & 0xFF);
                    $b += $basis * snapsmack_bh_srgb_to_linear($rgb & 0xFF);
                }
            }
            $scale     = 1.0 / ($width * $height);
            $factors[] = [$r * $scale, $g * $scale, $b * $scale];
        }
    }

    $dc = $factors[0];
    $ac = array_slice($factors, 1);

    $dcValue = (snapsmack_bh_linear_to_srgb($dc[0]) << 16)
             + (snapsmack_bh_linear_to_srgb($dc[1]) << 8)
             +  snapsmack_bh_linear_to_srgb($dc[2]);

    if (count($ac) > 0) {
        $actualMax = 0.0;
        foreach ($ac as $f) { foreach ($f as $c) { $actualMax = max($actualMax, abs($c)); } }
        $quantisedMax = max(0, min(82, (int)floor($actualMax * 166 - 0.5)));
        $maximumValue = ($quantisedMax + 1) / 166;
        $acQuant      = $quantisedMax;
    } else {
        $maximumValue = 1.0;
        $acQuant      = 0;
    }

    $hash  = snapsmack_bh_encode83(($componentsX - 1) + ($componentsY - 1) * 9, 1);
    $hash .= snapsmack_bh_encode83($acQuant, 1);
    $hash .= snapsmack_bh_encode83($dcValue, 4);
    foreach ($ac as $f) {
        $r = max(0, min(18, (int)floor(snapsmack_bh_sign_pow($f[0] / $maximumValue, 0.5) * 9 + 9.5)));
        $g = max(0, min(18, (int)floor(snapsmack_bh_sign_pow($f[1] / $maximumValue, 0.5) * 9 + 9.5)));
        $b = max(0, min(18, (int)floor(snapsmack_bh_sign_pow($f[2] / $maximumValue, 0.5) * 9 + 9.5)));
        $hash .= snapsmack_bh_encode83($r * 19 * 19 + $g * 19 + $b, 2);
    }
    return $hash;
}

/**
 * Return the cached blurhash for an image row, computing + storing it on first
 * use. Best-effort: returns '' (and never throws) if the file can't be loaded
 * or GD is unavailable. Loads the original off disk, downsamples to 32x32 so the
 * DCT stays cheap, caches the result to snap_images.blurhash.
 */
function snapsmack_ensure_image_blurhash(PDO $pdo, array $img): string {
    if (!empty($img['blurhash'])) return (string)$img['blurhash'];
    $id   = (int)($img['id'] ?? 0);
    $file = (string)($img['img_file'] ?? '');
    if ($id <= 0 || $file === '' || !function_exists('imagecreatetruecolor')) return '';

    $path = dirname(__DIR__) . '/' . ltrim($file, '/');
    if (!is_file($path)) return '';

    // Guard against OOM: decoded size is width*height*4 bytes regardless of the
    // compressed file size, so skip very large images (getimagesize is cheap and
    // does not decode). 30 MP ceiling. A skipped image simply gets no blurhash.
    $dim = @getimagesize($path);
    if (is_array($dim) && ($dim[0] * $dim[1]) > 30000000) return '';

    try {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $src = false;
        if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($path);
        elseif ($ext === 'png'  && function_exists('imagecreatefrompng'))  $src = @imagecreatefrompng($path);
        elseif ($ext === 'gif'  && function_exists('imagecreatefromgif'))  $src = @imagecreatefromgif($path);
        elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($path);
        if ($src === false) return '';

        $sw = imagesx($src); $sh = imagesy($src);
        $small = imagecreatetruecolor(32, 32);
        imagecopyresampled($small, $src, 0, 0, 0, 0, 32, 32, $sw, $sh);
        imagedestroy($src);

        $hash = snapsmack_blurhash_encode($small, 4, 3);
        imagedestroy($small);
        if ($hash === '') return '';

        $pdo->prepare("UPDATE snap_images SET blurhash = ? WHERE id = ?")->execute([$hash, $id]);
        return $hash;
    } catch (\Throwable $e) {
        return '';
    }
}

} // function_exists guard
// ===== SNAPSMACK EOF =====
