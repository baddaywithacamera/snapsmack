<?php
/**
 * SNAPSMACK - Shared image ingest pipeline
 *
 * Single source of truth for turning an uploaded image file into a
 * snap_images record: orientation correction, conditional resize, EXIF
 * preservation + copyright embedding, square + aspect thumbnail generation,
 * checksum, and palette extraction.
 *
 * Extracted from smack-post-solo.php so the Media Gallery uploader
 * (smack-gallery.php) can create post images the exact same way the photo
 * post editor does. snap_images == POST images (the Gallery). This is NOT
 * the reusable-asset Library (snap_assets / smack-media.php).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/palette-extract.php';

/**
 * Extract the raw EXIF APP1 segment bytes from a JPEG file.
 * GD strips EXIF on every imagejpeg() save, so we grab it before processing
 * and put it back after. Returns null if no EXIF APP1 is found.
 *
 * function_exists-guarded so this file can coexist with smack-post-solo.php,
 * which still defines its own copies until it is migrated onto this include.
 */
if (!function_exists('snap_exif_extract')) {
function snap_exif_extract(string $path): ?string {
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    $soi = fread($fh, 2);
    if ($soi !== "\xFF\xD8") { fclose($fh); return null; }
    while (!feof($fh)) {
        $marker = fread($fh, 2);
        if (strlen($marker) < 2 || $marker[0] !== "\xFF") break;
        $len_bytes = fread($fh, 2);
        if (strlen($len_bytes) < 2) break;
        $len = (ord($len_bytes[0]) << 8) | ord($len_bytes[1]);
        $payload = fread($fh, $len - 2);
        if ($marker[1] === "\xE1" && substr($payload, 0, 6) === "Exif\x00\x00") {
            fclose($fh);
            return $marker . $len_bytes . $payload; // full APP1 segment
        }
        if ($marker[1] === "\xDA") break; // start of scan — no more headers
    }
    fclose($fh);
    return null;
}
}

/**
 * Write Artist and Copyright tags into an EXIF APP1 binary segment.
 * Relocates IFD0 to the end of the TIFF, appending new Artist/Copyright
 * strings. Handles both Intel (LE) and Motorola (BE) byte orders. Returns
 * the original APP1 unchanged on any parse error.
 */
if (!function_exists('snap_exif_write_copyright')) {
function snap_exif_write_copyright(string $app1, string $artist, string $copyright): string {
    if ($artist === '' && $copyright === '') return $app1;
    if (strlen($app1) < 10) return $app1;
    if (substr($app1, 0, 2) !== "\xFF\xE1") return $app1;
    if (substr($app1, 4, 6) !== "Exif\x00\x00") return $app1;

    $tiff = substr($app1, 10); // TIFF data (from II/MM byte-order mark onward)
    $bo   = substr($tiff, 0, 2);
    if ($bo !== 'II' && $bo !== 'MM') return $app1;
    $le = ($bo === 'II');

    $u16 = function(string $d, int $o) use ($le): int {
        if (strlen($d) < $o + 2) return 0;
        return $le
            ? (ord($d[$o]) | (ord($d[$o+1]) << 8))
            : ((ord($d[$o]) << 8) | ord($d[$o+1]));
    };
    $u32 = function(string $d, int $o) use ($le): int {
        if (strlen($d) < $o + 4) return 0;
        return $le
            ? (ord($d[$o]) | (ord($d[$o+1]) << 8) | (ord($d[$o+2]) << 16) | (ord($d[$o+3]) << 24))
            : ((ord($d[$o]) << 24) | (ord($d[$o+1]) << 16) | (ord($d[$o+2]) << 8) | ord($d[$o+3]));
    };
    $p16 = function(int $v) use ($le): string {
        return $le
            ? chr($v & 0xFF) . chr(($v >> 8) & 0xFF)
            : chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
    };
    $p32 = function(int $v) use ($le): string {
        return $le
            ? chr($v & 0xFF) . chr(($v >> 8) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 24) & 0xFF)
            : chr(($v >> 24) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
    };

    $ifd0_off   = $u32($tiff, 4);
    if ($ifd0_off + 2 > strlen($tiff)) return $app1;
    $entry_cnt  = $u16($tiff, $ifd0_off);
    $entries    = [];

    for ($i = 0; $i < $entry_cnt; $i++) {
        $ep  = $ifd0_off + 2 + ($i * 12);
        if ($ep + 12 > strlen($tiff)) break;
        $tag = $u16($tiff, $ep);
        $entries[$tag] = [
            'tag'  => $tag,
            'type' => $u16($tiff, $ep + 2),
            'cnt'  => $u32($tiff, $ep + 4),
            'raw'  => substr($tiff, $ep + 8, 4),
        ];
    }

    $next_ifd_raw = substr($tiff, $ifd0_off + 2 + ($entry_cnt * 12), 4) ?: "\x00\x00\x00\x00";

    $TAG_ARTIST    = 0x013B;
    $TAG_COPYRIGHT = 0x8298;
    $new_strings   = [];

    if ($artist !== '') {
        $str = $artist . "\x00";
        $entries[$TAG_ARTIST] = ['tag' => $TAG_ARTIST, 'type' => 2, 'cnt' => strlen($str), 'raw' => null];
        $new_strings[$TAG_ARTIST] = $str;
    }
    if ($copyright !== '') {
        $str = $copyright . "\x00";
        $entries[$TAG_COPYRIGHT] = ['tag' => $TAG_COPYRIGHT, 'type' => 2, 'cnt' => strlen($str), 'raw' => null];
        $new_strings[$TAG_COPYRIGHT] = $str;
    }

    ksort($entries);
    $new_entry_cnt  = count($entries);

    $new_ifd0_pos   = strlen($tiff);
    $new_ifd0_size  = 2 + ($new_entry_cnt * 12) + 4;

    $val_cursor     = $new_ifd0_pos + $new_ifd0_size;

    $ifd0_bytes     = $p16($new_entry_cnt);
    $val_data       = '';

    foreach ($entries as $e) {
        $tag  = $e['tag'];
        $type = $e['type'];
        $cnt  = $e['cnt'];
        $raw  = $e['raw'];

        if (isset($new_strings[$tag])) {
            $str = $new_strings[$tag];
            if (strlen($str) <= 4) {
                $raw = str_pad($str, 4, "\x00");
            } else {
                $raw = $p32($val_cursor);
                $val_data  .= $str;
                $val_cursor += strlen($str);
            }
        }

        $ifd0_bytes .= $p16($tag) . $p16($type) . $p32($cnt) . $raw;
    }

    $ifd0_bytes .= $next_ifd_raw;

    $magic  = $le ? "\x2A\x00" : "\x00\x2A";
    $header = $bo . $magic . $p32($new_ifd0_pos);
    $new_tiff = $header . substr($tiff, 8) . $ifd0_bytes . $val_data;

    $payload    = "Exif\x00\x00" . $new_tiff;
    $seg_len    = strlen($payload) + 2;
    return "\xFF\xE1" . chr(($seg_len >> 8) & 0xFF) . chr($seg_len & 0xFF) . $payload;
}
}

/**
 * Inject a raw EXIF APP1 segment into a JPEG, replacing any existing APP1.
 * Call this after imagejpeg() to restore EXIF that GD stripped.
 */
if (!function_exists('snap_exif_inject')) {
function snap_exif_inject(string $path, string $app1): void {
    $jpeg = @file_get_contents($path);
    if (!$jpeg || substr($jpeg, 0, 2) !== "\xFF\xD8") return;
    $rest = substr($jpeg, 2);
    if (strlen($rest) >= 4 && $rest[0] === "\xFF" && $rest[1] === "\xE1") {
        $seg_len = (ord($rest[2]) << 8) | ord($rest[3]);
        $rest = substr($rest, 2 + $seg_len);
    }
    file_put_contents($path, "\xFF\xD8" . $app1 . $rest);
}
}

/**
 * Ingest one uploaded image file into snap_images (a POST image / Gallery item).
 *
 * Mirrors the smack-post-solo.php pipeline exactly so Gallery-uploaded images
 * are indistinguishable from images created through the photo post editor.
 *
 * @param PDO   $pdo       Live database handle.
 * @param array $settings  snap_settings key=>val map (max dims, jpeg quality, exif artist/copyright).
 * @param array $file      One entry from $_FILES (keys: name, tmp_name, error, ...).
 * @param array $opts      Optional overrides:
 *                           title (string)        default: filename without extension
 *                           status (string)       'published' | 'draft'  default: 'published'
 *                           description (string)  default: ''
 *                           img_date (string)     SQL datetime  default: now
 *                           allow_comments (int)  default: 1
 *                           allow_download (int)  default: 0
 *                           tags (string)         extra manual tags  default: ''
 *
 * @return array ['ok'=>true,'id'=>int,'thumb'=>string,'title'=>string]
 *               or ['ok'=>false,'error'=>string]
 */
function snap_ingest_image(PDO $pdo, array $settings, array $file, array $opts = []): array {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Upload failed or no file received.'];
    }

    // Extend limits for high-resolution image processing (per-request, safe to repeat).
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $orig_name = $file['name'] ?? 'upload';
    $file_ext  = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $allowed   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($file_ext, $allowed, true)) {
        return ['ok' => false, 'error' => 'Unsupported file type: .' . $file_ext];
    }

    $title = trim($opts['title'] ?? '');
    if ($title === '') {
        $title = pathinfo($orig_name, PATHINFO_FILENAME) ?: 'Untitled Transmission';
    }
    $desc           = trim($opts['description'] ?? '');
    $status         = in_array($opts['status'] ?? '', ['published', 'draft'], true) ? $opts['status'] : 'published';
    $custom_date    = !empty($opts['img_date']) ? $opts['img_date'] : date('Y-m-d H:i:s');
    $allow_comments = (int)($opts['allow_comments'] ?? 1);
    $allow_download = (int)($opts['allow_download'] ?? 0);
    $manual_tags    = trim($opts['tags'] ?? '');

    $rel_dir    = 'img_uploads/' . date('Y') . '/' . date('m');
    $full_dir   = dirname(__DIR__) . '/' . $rel_dir;
    $thumb_full = $full_dir . '/thumbs';
    if (!is_dir($full_dir))   { @mkdir($full_dir, 0755, true); }
    if (!is_dir($thumb_full)) { @mkdir($thumb_full, 0755, true); }

    $_slug_base = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title));
    $_slug_base = preg_replace('/-{2,}/', '-', $_slug_base);
    $_slug_base = trim($_slug_base, '-');
    if ($_slug_base === '') $_slug_base = 'untitled';
    // uniqid() suffix guarantees uniqueness even for multiple files posted in the same second.
    $slug          = $_slug_base . '-' . time() . '-' . substr(uniqid(), -4);
    $new_file_name = $slug . '.' . $file_ext;
    $target_path   = $full_dir . '/' . $new_file_name;
    $db_path       = $rel_dir . '/' . $new_file_name;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        return ['ok' => false, 'error' => 'Could not store uploaded file (check img_uploads permissions).'];
    }

    // --- EXIF METADATA EXTRACTION ---
    $camera = $lens = $focal = $iso = $aperture = $shutter = "";
    $flash = "No";
    if (in_array($file_ext, ['jpg', 'jpeg'], true)) {
        $exif_data = @exif_read_data($target_path);
        if ($exif_data) {
            $camera   = $exif_data['Model'] ?? "";
            $focal    = $exif_data['FocalLength'] ?? "";
            $iso      = $exif_data['ISOSpeedRatings'] ?? "";
            $aperture = $exif_data['COMPUTED']['ApertureFNumber'] ?? "";
            $shutter  = $exif_data['ExposureTime'] ?? "";
            if (isset($exif_data['Flash'])) {
                $flash = ($exif_data['Flash'] & 1) ? "Yes" : "No";
            }
        }
    }
    $exif_json = json_encode([
        'camera'   => strtoupper($camera),
        'lens'     => $lens,
        'focal'    => $focal,
        'iso'      => $iso,
        'aperture' => $aperture,
        'shutter'  => $shutter,
        'flash'    => $flash,
    ]);

    // --- IMAGE PROCESSING ENGINE ---
    list($orig_w, $orig_h) = getimagesize($target_path);
    $mime   = mime_content_type($target_path);
    $max_w  = (int)($settings['max_width_landscape'] ?? 2500);
    $max_h  = (int)($settings['max_height_portrait'] ?? 1850);
    $jpeg_q = (int)($settings['jpeg_quality'] ?? 85);

    $src = null;
    $db_thumb_square = null;
    $db_thumb_aspect = null;
    $db_checksum     = null;
    $display_options_json = null;

    $preserved_exif = ($mime === 'image/jpeg') ? snap_exif_extract($target_path) : null;

    if ($mime == 'image/jpeg')      { $src = imagecreatefromjpeg($target_path); }
    elseif ($mime == 'image/png')   { $src = imagecreatefrompng($target_path); }
    elseif ($mime == 'image/webp')  { $src = imagecreatefromwebp($target_path); }

    if ($src) {
        // --- EXIF ORIENTATION CORRECTION ---
        $exif_orientation = 1;
        if ($mime == 'image/jpeg' && function_exists('exif_read_data')) {
            $exif_orient = @exif_read_data($target_path, 'IFD0');
            if ($exif_orient !== false) {
                $exif_orientation = $exif_orient['Orientation'] ?? $exif_orient['orientation'] ?? 1;
            }
        }
        if ($exif_orientation == 3)      { $src = imagerotate($src, 180, 0); }
        elseif ($exif_orientation == 6)  { $src = imagerotate($src, -90, 0); }
        elseif ($exif_orientation == 8)  { $src = imagerotate($src, 90, 0); }

        $orig_w = imagesx($src);
        $orig_h = imagesy($src);

        if ($mime === 'image/jpeg')     { imagejpeg($src, $target_path, $jpeg_q); }
        elseif ($mime === 'image/png')  { imagepng($src, $target_path, 8); }
        else                            { imagewebp($src, $target_path, $jpeg_q); }

        imagedestroy($src);
        if ($mime == 'image/jpeg')      { $src = imagecreatefromjpeg($target_path); }
        elseif ($mime == 'image/png')   { $src = imagecreatefrompng($target_path); }
        else                            { $src = imagecreatefromwebp($target_path); }

        $thumb_dir = $thumb_full . '/';

        // --- CONDITIONAL RESIZE ---
        $needs_resize = false;
        $d_w = $orig_w;
        $d_h = $orig_h;
        if ($orig_w >= $orig_h) {
            if ($orig_w > $max_w) {
                $d_w = $max_w;
                $d_h = round($orig_h * ($max_w / $orig_w));
                $needs_resize = true;
            }
        } else {
            if ($orig_h > $max_h) {
                $d_h = $max_h;
                $d_w = round($orig_w * ($max_h / $orig_h));
                $needs_resize = true;
            }
        }

        if ($needs_resize) {
            $d_img = imagecreatetruecolor($d_w, $d_h);
            if ($mime == 'image/png' || $mime == 'image/webp') {
                imagealphablending($d_img, false);
                imagesavealpha($d_img, true);
            }
            imagecopyresampled($d_img, $src, 0, 0, 0, 0, $d_w, $d_h, $orig_w, $orig_h);
            if ($mime === 'image/jpeg')     { imagejpeg($d_img, $target_path, $jpeg_q); }
            elseif ($mime === 'image/png')  { imagepng($d_img, $target_path, 8); }
            else                            { imagewebp($d_img, $target_path, $jpeg_q); }
            imagedestroy($d_img);

            imagedestroy($src);
            if ($mime == 'image/jpeg')      { $src = imagecreatefromjpeg($target_path); }
            elseif ($mime == 'image/png')   { $src = imagecreatefrompng($target_path); }
            else                            { $src = imagecreatefromwebp($target_path); }

            $orig_w = $d_w;
            $orig_h = $d_h;
        }

        // Restore EXIF (with optional copyright embedding).
        if ($preserved_exif !== null) {
            $exif_artist    = trim($settings['exif_artist']    ?? '');
            $exif_copyright = trim($settings['exif_copyright'] ?? '');
            if ($exif_artist !== '' || $exif_copyright !== '') {
                $preserved_exif = snap_exif_write_copyright($preserved_exif, $exif_artist, $exif_copyright);
            }
            snap_exif_inject($target_path, $preserved_exif);
        }

        // --- SQUARE THUMBNAIL (t_ prefix): 400x400 center-cropped ---
        $sq_size = 400;
        $sq_thumb = imagecreatetruecolor($sq_size, $sq_size);
        $min_dim = min($orig_w, $orig_h);
        $off_x = ($orig_w - $min_dim) / 2;
        $off_y = ($orig_h - $min_dim) / 2;
        if ($mime == 'image/png' || $mime == 'image/webp') {
            imagealphablending($sq_thumb, false);
            imagesavealpha($sq_thumb, true);
        }
        imagecopyresampled($sq_thumb, $src, 0, 0, $off_x, $off_y, $sq_size, $sq_size, $min_dim, $min_dim);
        if ($mime === 'image/jpeg')     { imagejpeg($sq_thumb, $thumb_dir . 't_' . $new_file_name, 82); }
        elseif ($mime === 'image/png')  { imagepng($sq_thumb, $thumb_dir . 't_' . $new_file_name, 8); }
        else                            { imagewebp($sq_thumb, $thumb_dir . 't_' . $new_file_name, 78); }
        imagedestroy($sq_thumb);

        // --- ASPECT-PRESERVED THUMBNAIL (a_ prefix): 400px long side ---
        $aspect_long = 400;
        if ($orig_w >= $orig_h) {
            $a_w = $aspect_long;
            $a_h = round($orig_h * ($aspect_long / $orig_w));
        } else {
            $a_h = $aspect_long;
            $a_w = round($orig_w * ($aspect_long / $orig_h));
        }
        if ($orig_w < $aspect_long && $orig_h < $aspect_long) {
            $a_w = $orig_w;
            $a_h = $orig_h;
        }
        $a_thumb = imagecreatetruecolor($a_w, $a_h);
        if ($mime == 'image/png' || $mime == 'image/webp') {
            imagealphablending($a_thumb, false);
            imagesavealpha($a_thumb, true);
        }
        imagecopyresampled($a_thumb, $src, 0, 0, 0, 0, $a_w, $a_h, $orig_w, $orig_h);
        if ($mime === 'image/jpeg')     { imagejpeg($a_thumb, $thumb_dir . 'a_' . $new_file_name, 82); }
        elseif ($mime === 'image/png')  { imagepng($a_thumb, $thumb_dir . 'a_' . $new_file_name, 8); }
        else                            { imagewebp($a_thumb, $thumb_dir . 'a_' . $new_file_name, 78); }
        imagedestroy($a_thumb);

        imagedestroy($src);

        $db_thumb_square = $rel_dir . '/thumbs/t_' . $new_file_name;
        $db_thumb_aspect = $rel_dir . '/thumbs/a_' . $new_file_name;
        $db_checksum     = hash_file('sha256', $target_path);

        // --- COLOUR PALETTE EXTRACTION ---
        $palette = snapsmack_extract_palette($target_path, 5);
        $display_opts = !empty($palette) ? ['palette' => $palette] : [];
        $display_options_json = !empty($display_opts) ? json_encode($display_opts) : null;
    }

    // --- ORIENTATION DETECTION ---
    $auto_orientation = 0; // landscape
    if ($orig_w == $orig_h)      { $auto_orientation = 2; } // square
    elseif ($orig_h > $orig_w)   { $auto_orientation = 1; } // portrait

    // --- DATABASE RECORD CREATION ---
    $stmt = $pdo->prepare("
        INSERT INTO snap_images (
            img_title, img_slug, img_file, img_description, img_film, img_exif,
            img_status, img_date, img_orientation, img_width, img_height,
            allow_comments, allow_download, download_url,
            img_thumb_square, img_thumb_aspect, img_checksum, img_display_options
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $title,
        $slug,
        $db_path,
        $desc,
        '',
        $exif_json,
        $status,
        $custom_date,
        $auto_orientation,
        $orig_w,
        $orig_h,
        $allow_comments,
        $allow_download,
        '',
        $db_thumb_square,
        $db_thumb_aspect,
        $db_checksum,
        $display_options_json,
    ]);

    $new_img_id = (int)$pdo->lastInsertId();

    // Sync hashtags from title + description + manual tags field.
    if (function_exists('snap_sync_tags')) {
        snap_sync_tags($pdo, $new_img_id, $title . ' ' . $desc . ' ' . $manual_tags);
    }

    return [
        'ok'    => true,
        'id'    => $new_img_id,
        'thumb' => $db_thumb_square ?: $db_path,
        'title' => $title,
    ];
}
// ===== SNAPSMACK EOF =====
