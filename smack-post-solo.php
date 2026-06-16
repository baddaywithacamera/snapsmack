<?php
/**
 * SNAPSMACK - SMACKONEOUT single-image post upload and processing
 *
 * Handles image uploads, automatic EXIF extraction and metadata handling,
 * orientation correction, and thumbnail generation in multiple formats.
 * This is the SMACKONEOUT personality posting page (one photo, one post).
 * GRAMOFSMACK uses smack-post-gram.php; SmackTalk uses smack-post-long.php.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// SYBU scoped key + photoblog-only gate (see sybu-data.php). This is the solo
// posting endpoint SYBU writes through; the gate only affects TOOL access, the
// browser admin page is unaffected. Additive — legacy auth still works.
$GLOBALS['SNAP_API_KEY_TYPES']    = ['sybu'];
$GLOBALS['SNAP_API_REQUIRE_MODE'] = 'photoblog';
require_once 'core/api-auth.php';
require_once 'core/palette-extract.php';
require_once 'core/snap-tags.php';
require_once 'core/ai-provider.php';

/**
 * Extract the raw EXIF APP1 segment bytes from a JPEG file.
 * GD strips EXIF on every imagejpeg() save, so we grab it before processing
 * and put it back after. Returns null if no EXIF APP1 is found.
 */
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

/**
 * Write Artist and Copyright tags into an EXIF APP1 binary segment.
 *
 * Strategy: "relocate IFD0 to end of TIFF". The TIFF spec allows IFD0 to
 * live anywhere in the file — we copy all existing IFD0 entries verbatim
 * (their inline values and offset-based values all remain valid since we
 * never touch the original TIFF body), append the new Artist/Copyright
 * strings after the new IFD0, then update the TIFF header's IFD0 pointer.
 * The old IFD0 becomes unreferenced but harmless.
 *
 * Handles both Intel (little-endian) and Motorola (big-endian) byte orders.
 * Returns the original APP1 unchanged on any parse error.
 */
function snap_exif_write_copyright(string $app1, string $artist, string $copyright): string {
    if ($artist === '' && $copyright === '') return $app1;
    if (strlen($app1) < 10) return $app1;
    if (substr($app1, 0, 2) !== "\xFF\xE1") return $app1;
    if (substr($app1, 4, 6) !== "Exif\x00\x00") return $app1;

    $tiff = substr($app1, 10); // TIFF data (from II/MM byte-order mark onward)
    $bo   = substr($tiff, 0, 2);
    if ($bo !== 'II' && $bo !== 'MM') return $app1;
    $le = ($bo === 'II');

    // Unpack/pack helpers that respect the image's byte order.
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

    // Read IFD0.
    $ifd0_off   = $u32($tiff, 4);
    if ($ifd0_off + 2 > strlen($tiff)) return $app1;
    $entry_cnt  = $u16($tiff, $ifd0_off);
    $entries    = []; // keyed by tag — [tag, type, count, raw_4_bytes]

    for ($i = 0; $i < $entry_cnt; $i++) {
        $ep  = $ifd0_off + 2 + ($i * 12);
        if ($ep + 12 > strlen($tiff)) break;
        $tag = $u16($tiff, $ep);
        $entries[$tag] = [
            'tag'  => $tag,
            'type' => $u16($tiff, $ep + 2),
            'cnt'  => $u32($tiff, $ep + 4),
            'raw'  => substr($tiff, $ep + 8, 4), // either inline value or offset bytes, keep as-is
        ];
    }

    // Keep next-IFD pointer (usually 0x00000000 for single-image JPEGs).
    $next_ifd_raw = substr($tiff, $ifd0_off + 2 + ($entry_cnt * 12), 4) ?: "\x00\x00\x00\x00";

    // Prepare our new string values (null-terminated per EXIF/ASCII spec).
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

    // Sort IFD0 entries by tag (EXIF/TIFF spec requirement).
    ksort($entries);
    $new_entry_cnt  = count($entries);

    // New IFD0 lives at the end of the original TIFF data.
    $new_ifd0_pos   = strlen($tiff);
    $new_ifd0_size  = 2 + ($new_entry_cnt * 12) + 4; // count + entries + next_ifd

    // Value strings start immediately after the new IFD0.
    $val_cursor     = $new_ifd0_pos + $new_ifd0_size;

    // Build IFD0 entry bytes and collect value data to append.
    $ifd0_bytes     = $p16($new_entry_cnt);
    $val_data       = '';

    foreach ($entries as $e) {
        $tag  = $e['tag'];
        $type = $e['type'];
        $cnt  = $e['cnt'];
        $raw  = $e['raw'];

        if (isset($new_strings[$tag])) {
            // Our new string: value > 4 bytes goes at $val_cursor; <= 4 fits inline.
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

    // Rebuild TIFF: updated 8-byte header + original body + new IFD0 + value strings.
    $magic  = $le ? "\x2A\x00" : "\x00\x2A";
    $header = $bo . $magic . $p32($new_ifd0_pos);
    $new_tiff = $header . substr($tiff, 8) . $ifd0_bytes . $val_data;

    // Rewrap into APP1 segment: \xFF\xE1 + 2-byte-length + "Exif\x00\x00" + TIFF.
    $payload    = "Exif\x00\x00" . $new_tiff;
    $seg_len    = strlen($payload) + 2; // length field includes itself
    return "\xFF\xE1" . chr(($seg_len >> 8) & 0xFF) . chr($seg_len & 0xFF) . $payload;
}

/**
 * Inject a raw EXIF APP1 segment into a JPEG, replacing any existing APP1.
 * Call this after imagejpeg() to restore EXIF that GD stripped.
 */
function snap_exif_inject(string $path, string $app1): void {
    $jpeg = @file_get_contents($path);
    if (!$jpeg || substr($jpeg, 0, 2) !== "\xFF\xD8") return;
    // Strip any existing APP1 at position 2
    $rest = substr($jpeg, 2);
    if (strlen($rest) >= 4 && $rest[0] === "\xFF" && $rest[1] === "\xE1") {
        $seg_len = (ord($rest[2]) << 8) | ord($rest[3]);
        $rest = substr($rest, 2 + $seg_len);
    }
    file_put_contents($path, "\xFF\xD8" . $app1 . $rest);
}

// Extend limits for high-resolution image processing.
set_time_limit(300);
ini_set('memory_limit', '512M');

$msg = "";

// Load site settings for image engine configuration.
$settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// --- FORM SUBMISSION HANDLER ---
// Processes file uploads, generates thumbnails, extracts EXIF data,
// and stores the image record with metadata in the database.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['img_file'])) {

    $title = trim($_POST['title'] ?? 'Untitled Transmission');
    $desc = trim($_POST['desc'] ?? '');
    $status = $_POST['img_status'] ?? 'published';

    // HTML5 datetime-local inputs use 'T' separator; convert to SQL-compatible space.
    $raw_date = $_POST['img_date'] ?? '';
    $custom_date = !empty($raw_date) ? str_replace('T', ' ', $raw_date) : date('Y-m-d H:i:s');

    $allow_comments = (int)($_POST['allow_comments'] ?? 1);
    $allow_download = (int)($_POST['allow_download'] ?? 0);
    $download_url   = trim($_POST['download_url'] ?? '');
    $source_file    = trim($_POST['source_file'] ?? '');

    // Validate: if require_download_link is on, any published post must have a URL.
    // The previous check gated on $allow_download, which meant batch-poster posts
    // (allow_download=0, download_url='') could slip through even with the setting on.
    if (($settings['download_link_required'] ?? '0') === '1'
        && $status === 'published'
        && empty($download_url)) {
        $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($is_xhr) {
            echo "A download URL is required for published posts on this site.";
            exit;
        }
        $post_error = "A download URL is required for published posts on this site.";
    }
    $selected_cats        = $_POST['cat_ids']        ?? [];
    $selected_albums      = $_POST['album_ids']      ?? [];
    $selected_collections = $_POST['collection_ids'] ?? [];
    $manual_tags = trim($_POST['tags'] ?? '');

    // Film stock field supports explicit "N/A" via checkbox override.
    $film_val = $_POST['film_stock'] ?? '';
    if (isset($_POST['film_na'])) {
        $film_val = 'N/A';
    }

    // Abort if validation failed (non-XHR path only — XHR already exited above)
    if (!empty($post_error)) {
        goto render_form;
    }

    $rel_dir = 'img_uploads/' . date('Y') . '/' . date('m');
    $full_dir = __DIR__ . '/' . $rel_dir;
    $thumb_full = $full_dir . '/thumbs';
    if (!is_dir($full_dir)) {
        mkdir($full_dir, 0755, true);
    }
    if (!is_dir($thumb_full)) {
        mkdir($thumb_full, 0755, true);
    }

    $file_ext = strtolower(pathinfo($_FILES['img_file']['name'], PATHINFO_EXTENSION));
    $_slug_base = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title));
    $_slug_base = preg_replace('/-{2,}/', '-', $_slug_base); // collapse runs of hyphens
    $_slug_base = trim($_slug_base, '-');                     // strip leading/trailing hyphens
    if ($_slug_base === '') $_slug_base = 'untitled';
    $slug = $_slug_base . '-' . time();
    unset($_slug_base);
    $new_file_name = $slug . '.' . $file_ext;
    $target_path = $full_dir . '/' . $new_file_name;
    $db_path = $rel_dir . '/' . $new_file_name;

    if (move_uploaded_file($_FILES['img_file']['tmp_name'], $target_path)) {

        // --- EXIF METADATA EXTRACTION ---
        // Reads camera and shot settings from JPEG EXIF data where available.
        // User-provided values take precedence over auto-detected metadata.
        $camera = $lens = $focal = $iso = $aperture = $shutter = "";
        $flash = "No";

        if (in_array($file_ext, ['jpg', 'jpeg'])) {
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
            'camera'   => !empty($_POST['camera_model'])  ? strtoupper(trim($_POST['camera_model']))  : strtoupper($camera),
            'lens'     => !empty($_POST['lens_info'])      ? trim($_POST['lens_info'])                 : $lens,
            'focal'    => !empty($_POST['focal_length'])   ? trim($_POST['focal_length'])              : $focal,
            'iso'      => !empty($_POST['iso_speed'])      ? trim($_POST['iso_speed'])                 : $iso,
            'aperture' => !empty($_POST['aperture'])       ? trim($_POST['aperture'])                  : $aperture,
            'shutter'  => !empty($_POST['shutter_speed'])  ? trim($_POST['shutter_speed'])             : $shutter,
            'flash'    => !empty($_POST['flash_fire'])     ? $_POST['flash_fire']                      : $flash
        ]);

        // --- IMAGE PROCESSING ENGINE ---
        // Multi-stage pipeline: orientation correction, conditional resizing,
        // and thumbnail generation. Generates two thumbnail variants:
        //   t_ = 400x400 center-cropped square
        //   a_ = 400px long side, aspect-ratio preserved

        list($orig_w, $orig_h) = getimagesize($target_path);
        $mime = mime_content_type($target_path);
        $max_w = (int)($settings['max_width_landscape'] ?? 2500);
        $max_h = (int)($settings['max_height_portrait'] ?? 1850);
        $jpeg_q = (int)($settings['jpeg_quality'] ?? 85);

        $src = null;
        $db_thumb_square = null;
        $db_thumb_aspect = null;
        $db_checksum     = null;
        $display_options_json = null;

        // Preserve EXIF before GD processing — GD strips it on every imagejpeg() save.
        $preserved_exif = ($mime === 'image/jpeg') ? snap_exif_extract($target_path) : null;

        if ($mime == 'image/jpeg') { $src = imagecreatefromjpeg($target_path); }
        elseif ($mime == 'image/png') { $src = imagecreatefrompng($target_path); }
        elseif ($mime == 'image/webp') { $src = imagecreatefromwebp($target_path); }

        if ($src) {
            // --- EXIF ORIENTATION CORRECTION ---
            // GD library drops EXIF rotation on save. Detect and apply rotation
            // before any resizing to ensure pixel data matches original orientation.
            $exif_orientation = 1;
            if ($mime == 'image/jpeg' && function_exists('exif_read_data')) {
                $exif_orient = @exif_read_data($target_path, 'IFD0');
                if ($exif_orient !== false) {
                    $exif_orientation = $exif_orient['Orientation'] ?? $exif_orient['orientation'] ?? 1;
                }
            }

            if ($exif_orientation == 3) {
                $src = imagerotate($src, 180, 0);
            } elseif ($exif_orientation == 6) {
                $src = imagerotate($src, -90, 0);
            } elseif ($exif_orientation == 8) {
                $src = imagerotate($src, 90, 0);
            }

            // Recalculate dimensions after rotation.
            $orig_w = imagesx($src);
            $orig_h = imagesy($src);

            // Persist the corrected orientation by saving the GD resource.
            if ($mime === 'image/jpeg') { imagejpeg($src, $target_path, $jpeg_q); }
            elseif ($mime === 'image/png') { imagepng($src, $target_path, 8); }
            else { imagewebp($src, $target_path, $jpeg_q); }

            // Reload the corrected file.
            imagedestroy($src);
            if ($mime == 'image/jpeg') { $src = imagecreatefromjpeg($target_path); }
            elseif ($mime == 'image/png') { $src = imagecreatefrompng($target_path); }
            else { $src = imagecreatefromwebp($target_path); }

            $thumb_dir = $thumb_full . '/';

            // --- CONDITIONAL RESIZE ---
            // If the image exceeds config limits, scale it down while preserving aspect ratio.
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

                // Replace original with resized version.
                if ($mime === 'image/jpeg') { imagejpeg($d_img, $target_path, $jpeg_q); }
                elseif ($mime === 'image/png') { imagepng($d_img, $target_path, 8); }
                else { imagewebp($d_img, $target_path, $jpeg_q); }
                imagedestroy($d_img);

                // Reload for thumbnail generation.
                imagedestroy($src);
                if ($mime == 'image/jpeg') { $src = imagecreatefromjpeg($target_path); }
                elseif ($mime == 'image/png') { $src = imagecreatefrompng($target_path); }
                else { $src = imagecreatefromwebp($target_path); }

                $orig_w = $d_w;
                $orig_h = $d_h;
            }

            // Restore EXIF that GD stripped during orientation correction / resize.
            // If copyright embedding is configured, write Artist and Copyright tags
            // into the preserved APP1 before injecting it back.
            if ($preserved_exif !== null) {
                $exif_artist    = trim($settings['exif_artist']    ?? '');
                $exif_copyright = trim($settings['exif_copyright'] ?? '');
                if ($exif_artist !== '' || $exif_copyright !== '') {
                    $preserved_exif = snap_exif_write_copyright($preserved_exif, $exif_artist, $exif_copyright);
                }
                snap_exif_inject($target_path, $preserved_exif);
            }

            // --- SQUARE THUMBNAIL (t_ prefix) ---
            // 400x400 center-cropped square for grid display.
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

            if ($mime === 'image/jpeg') { imagejpeg($sq_thumb, $thumb_dir . 't_' . $new_file_name, 82); }
            elseif ($mime === 'image/png') { imagepng($sq_thumb, $thumb_dir . 't_' . $new_file_name, 8); }
            else { imagewebp($sq_thumb, $thumb_dir . 't_' . $new_file_name, 78); }
            imagedestroy($sq_thumb);

            // --- ASPECT-PRESERVED THUMBNAIL (a_ prefix) ---
            // 400px on the long side, native aspect ratio for masonry layouts.
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

            if ($mime === 'image/jpeg') { imagejpeg($a_thumb, $thumb_dir . 'a_' . $new_file_name, 82); }
            elseif ($mime === 'image/png') { imagepng($a_thumb, $thumb_dir . 'a_' . $new_file_name, 8); }
            else { imagewebp($a_thumb, $thumb_dir . 'a_' . $new_file_name, 78); }
            imagedestroy($a_thumb);

            imagedestroy($src);

            // --- RECOVERY METADATA ---
            // Compute SHA-256 checksum and store relative thumb paths for disaster recovery.
            $db_thumb_square = $rel_dir . '/thumbs/t_' . $new_file_name;
            $db_thumb_aspect = $rel_dir . '/thumbs/a_' . $new_file_name;
            $db_checksum     = hash_file('sha256', $target_path);

            // --- COLOUR PALETTE EXTRACTION ---
            // Extract dominant colours from the image for Galleria frame customisation.
            $palette = snapsmack_extract_palette($target_path, 5);
            $display_opts = !empty($palette) ? ['palette' => $palette] : [];

            // Merge AI-supplied hex codes (from Smack Your Batch Up / Gemini) if present.
            $ai_colors_raw = trim($_POST['img_ai_colors'] ?? '');
            if ($ai_colors_raw !== '') {
                $ai_hexes = array_values(array_filter(
                    explode(' ', $ai_colors_raw),
                    fn($h) => preg_match('/^#[0-9A-Fa-f]{6}$/', $h)
                ));
                if (!empty($ai_hexes)) {
                    $display_opts['ai_colors'] = $ai_hexes;
                }
            }

            $display_options_json = !empty($display_opts) ? json_encode($display_opts) : null;
        }

        // --- ORIENTATION DETECTION ---
        // Classifies image as landscape, portrait, or square for archive display.
        // User override takes precedence; otherwise auto-detect from final dimensions.
        $orient_override = $_POST['orientation_override'] ?? 'auto';
        if ($orient_override !== 'auto') {
            $auto_orientation = (int)$orient_override;
        } else {
            $auto_orientation = 0; // landscape
            if ($orig_w == $orig_h) {
                $auto_orientation = 2; // square
            } elseif ($orig_h > $orig_w) {
                $auto_orientation = 1; // portrait
            }
        }

        // --- DATABASE RECORD CREATION ---
        // Stores image metadata, dimensions, processing flags, and category/album mappings.
        $stmt = $pdo->prepare("
            INSERT INTO snap_images (
                img_title,
                img_slug,
                img_file,
                img_source_file,
                img_description,
                img_film,
                img_exif,
                img_status,
                img_date,
                img_orientation,
                img_width,
                img_height,
                allow_comments,
                allow_download,
                download_url,
                img_thumb_square,
                img_thumb_aspect,
                img_checksum,
                img_display_options
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $title,
            $slug,
            $db_path,
            $source_file ?: null,
            $desc,
            $film_val,
            $exif_json,
            $status,
            $custom_date,
            $auto_orientation,
            $orig_w,
            $orig_h,
            $allow_comments,
            $allow_download,
            $download_url,
            $db_thumb_square ?? null,
            $db_thumb_aspect ?? null,
            $db_checksum ?? null,
            $display_options_json ?? null
        ]);

        $new_img_id = $pdo->lastInsertId();

        // Associate image with selected categories.
        foreach ($selected_cats as $cid) {
            $pdo->prepare("INSERT INTO snap_image_cat_map (image_id, cat_id) VALUES (?, ?)")->execute([$new_img_id, (int)$cid]);
        }

        // Associate image with selected albums.
        foreach ($selected_albums as $aid) {
            $pdo->prepare("INSERT INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)")->execute([$new_img_id, (int)$aid]);
        }

        // Associate image with selected collections (item_type='post').
        foreach ($selected_collections as $cid) {
            $max = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM snap_collection_items WHERE collection_id=?");
            $max->execute([(int)$cid]);
            $pdo->prepare("INSERT IGNORE INTO snap_collection_items (collection_id, item_type, item_id, sort_order) VALUES (?, 'post', ?, ?)")
                ->execute([(int)$cid, $new_img_id, (int)$max->fetchColumn()]);
        }

        // Sync hashtags from title + description + manual tags field.
        snap_sync_tags($pdo, (int)$new_img_id, $title . ' ' . $desc . ' ' . $manual_tags);

        // New content is live — flush the page cache so it appears immediately.
        require_once __DIR__ . '/core/page-cache.php';
        page_cache_purge_all();

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo "success";
            exit;
        }
        header("Location: smack-manage.php?msg=TRANSMISSION_LIVE");
        exit;
    }
}

render_form:
// Load categories, albums, and collections for form selectors.
$all_cats         = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
$all_albums       = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();
$all_collections  = $pdo->query("SELECT * FROM snap_collections ORDER BY title ASC")->fetchAll();

$page_title = "Initialize Smack";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row header-row--ruled">
        <h2>INITIALIZE NEW TRANSMISSION</h2>
    </div>

    <?php if (!empty($post_error)): ?>
        <div class="alert" style="background:rgba(204,68,68,0.15);border:1px solid rgba(204,68,68,0.4);color:#cc4444;padding:12px 16px;border-radius:4px;margin-bottom:16px;">
            <?php echo htmlspecialchars($post_error); ?>
        </div>
    <?php endif; ?>

    <form id="smack-form-post" method="POST" enctype="multipart/form-data">

        <div class="box">
            <div class="post-layout-grid">
                
                <div class="post-col-left">
                    <div class="lens-input-wrapper">
                        <label>IMAGE TITLE</label>
                        <input type="text" name="title" placeholder="Transmission Identifier..." required autofocus>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>REGISTRY (CATEGORIES)</label>
                        <div class="custom-multiselect">
                            <div class="select-box" onclick="toggleDropdown('cat-items')">
                                <span id="cat-label">Select Categories...</span><span class="arrow">▼</span>
                            </div>
                            <div class="dropdown-content" id="cat-items">
                                <div class="dropdown-search-wrapper"><input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'cat-list-box')"></div>
                                <div class="dropdown-list" id="cat-list-box">
                                    <?php foreach($all_cats as $c): ?>
                                        <label class="multi-cat-item">
                                            <input type="checkbox" name="cat_ids[]" value="<?php echo $c['id']; ?>" onchange="updateLabel('cat')">
                                            <span class="cat-name-text"><?php echo htmlspecialchars($c['cat_name']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>MISSIONS (ALBUMS)</label>
                        <div class="custom-multiselect">
                            <div class="select-box" onclick="toggleDropdown('album-items')">
                                <span id="album-label">Select Albums...</span><span class="arrow">▼</span>
                            </div>
                            <div class="dropdown-content" id="album-items">
                                <div class="dropdown-search-wrapper"><input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'album-list-box')"></div>
                                <div class="dropdown-list" id="album-list-box">
                                    <?php foreach($all_albums as $a): ?>
                                        <label class="multi-cat-item">
                                            <input type="checkbox" name="album_ids[]" value="<?php echo $a['id']; ?>" onchange="updateLabel('album')">
                                            <span class="cat-name-text"><?php echo htmlspecialchars($a['album_name']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>COLLECTIONS</label>
                        <?php if (empty($all_collections)): ?>
                            <p style="font-size:0.8rem;color:var(--text-muted,#888);margin:4px 0 0;">No collections yet — create one under <a href="smack-collections.php" style="color:var(--accent);">Collections</a>.</p>
                        <?php else: ?>
                        <div class="custom-multiselect">
                            <div class="select-box" onclick="toggleDropdown('collection-items')">
                                <span id="collection-label">Select Collections...</span><span class="arrow">▼</span>
                            </div>
                            <div class="dropdown-content" id="collection-items">
                                <div class="dropdown-search-wrapper"><input type="text" placeholder="Filter..." onkeyup="filterRegistry(this, 'collection-list-box')"></div>
                                <div class="dropdown-list" id="collection-list-box">
                                    <?php foreach($all_collections as $col): ?>
                                        <label class="multi-cat-item">
                                            <input type="checkbox" name="collection_ids[]" value="<?php echo $col['id']; ?>" onchange="updateLabel('collection')">
                                            <span class="cat-name-text"><?php echo htmlspecialchars($col['name']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="lens-input-wrapper post-description-wrap">
                        <label>DESCRIPTION / STORY</label>
                        <div class="sc-toolbar" data-target="desc">
                            <div class="sc-row">
                                <button type="button" class="sc-btn" data-action="bold" title="Bold (Ctrl+B)">B</button>
                                <button type="button" class="sc-btn" data-action="italic" title="Italic (Ctrl+I)">I</button>
                                <button type="button" class="sc-btn" data-action="underline" title="Underline (Ctrl+U)">U</button>
                                <button type="button" class="sc-btn" data-action="link" title="Insert Link (Ctrl+K)">LINK</button>
                                <span class="sc-sep"></span>
                                <button type="button" class="sc-btn" data-action="h2" title="Heading 2">H2</button>
                                <button type="button" class="sc-btn" data-action="h3" title="Heading 3">H3</button>
                                <button type="button" class="sc-btn" data-action="blockquote" title="Blockquote">BQ</button>
                                <button type="button" class="sc-btn" data-action="hr" title="Horizontal Rule">HR</button>
                                <span class="sc-sep"></span>
                                <button type="button" class="sc-btn" data-action="ul" title="Bullet List">UL</button>
                                <button type="button" class="sc-btn" data-action="ol" title="Numbered List">OL</button>
                            </div>
                            <div class="sc-row">
                                <button type="button" class="sc-btn" data-action="img" title="Insert Image Shortcode">IMG</button>
                                <button type="button" class="sc-btn" data-action="col2" title="2-Column Layout">COL 2</button>
                                <button type="button" class="sc-btn" data-action="col3" title="3-Column Layout">COL 3</button>
                                <button type="button" class="sc-btn" data-action="dropcap" title="Dropcap">DROP</button>
                                <button type="button" class="sc-btn" data-action="spacer" title="Vertical Spacer (1-100px)">SPACER</button>
                                <button type="button" class="sc-btn sc-btn-preview" data-action="preview" title="Preview in New Tab">PREVIEW</button>
                                <?php if (snap_ai_configured()): ?>
                                <span class="sc-sep"></span>
                                <button type="button" class="sc-btn sc-btn-ai" id="btn-spellcheck" title="Check spelling and grammar with AI">SP/GR</button>
                                <button type="button" class="sc-btn sc-btn-ai" id="btn-ai-assist" title="AI Writing Assistant">AI ASSIST</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <textarea id="desc" name="desc" placeholder="Plain text. Blank lines become paragraph breaks."></textarea>


                    </div>

                    <div class="lens-input-wrapper">
                        <label>TAGS</label>
                        <input type="text" name="tags" placeholder="#concrete #rust #peeling — space-separated hashtags">
                    </div>
                </div>

                <div class="post-col-right">
                    <div class="lens-input-wrapper">
                        <label>PUBLICATION STATUS</label>
                        <select name="img_status" class="full-width-select">
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>INTERNAL TIMESTAMP</label>
                        <input type="datetime-local" name="img_date" class="full-width-select edit-timestamp" 
                               onclick="this.showPicker()"
                               value="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ORIENTATION OVERRIDE</label>
                        <select name="orientation_override" class="full-width-select">
                            <option value="auto">AUTO-DETECT FROM IMAGE</option>
                            <option value="0">LANDSCAPE</option>
                            <option value="1">PORTRAIT</option>
                            <option value="2">SQUARE</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ALLOW PUBLIC SIGNALS?</label>
                        <select name="allow_comments" class="full-width-select">
                            <option value="1">ENABLED</option>
                            <option value="0">DISABLED</option>
                        </select>
                    </div>

                    <?php $dl_default = ($settings['download_default_mode'] ?? 'per_post') === 'all_posts' ? '1' : '0'; ?>
                    <div class="lens-input-wrapper">
                        <label>ALLOW DOWNLOAD?</label>
                        <select name="allow_download" id="allow-download-select" class="full-width-select">
                            <option value="0" <?php echo $dl_default === '0' ? 'selected' : ''; ?>>DISABLED</option>
                            <option value="1" <?php echo $dl_default === '1' ? 'selected' : ''; ?>>ENABLED</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>DOWNLOAD URL (EXTERNAL)<?php if (($settings['download_link_required'] ?? '0') === '1'): ?> <span style="color:var(--danger, #cc4444);">*</span><?php endif; ?></label>
                        <input type="text" name="download_url" id="download-url-input" placeholder="Google Drive, Dropbox, etc. Leave blank for local file."
                               <?php if (($settings['download_link_required'] ?? '0') === '1'): ?>data-required="1"<?php endif; ?>>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>SOURCE ASSET (.JPG, .PNG, .WEBP)</label>
                        <div class="file-upload-wrapper">
                            <div class="file-custom-btn">INJECT ASSET</div>
                            <span id="post-file-name" class="file-name-display">No file selected...</span>
                            <input type="file" name="img_file" id="post-file-input" accept="image/*" class="file-input-hidden">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (($settings['exif_display_enabled'] ?? '1') !== '0'): ?>
        <div class="box">
            <h3>TECHNICAL SPECIFICATIONS (EXIF OVERRIDES)</h3>
            <p class="dim exif-hint">JPEG EXIF is auto-extracted on upload. Manual entries below override auto-detected values. Leave blank to use auto-detected data.</p>
            
            <div class="meta-grid">
                <div class="lens-input-wrapper">
                    <label>CAMERA MODEL</label>
                    <input type="text" name="camera_model" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>LENS INFO</label>
                    <div class="input-control-row">
                        <input type="text" name="lens_info" id="meta-lens" placeholder="Auto-detected from EXIF...">
                        <label class="built-in-label"><input type="checkbox" id="fixed-lens-check"> Built-in</label>
                    </div>
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FOCAL LENGTH</label>
                    <input type="text" name="focal_length" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FILM STOCK</label>
                    <div class="input-control-row">
                        <input type="text" name="film_stock" id="meta-film" placeholder="e.g. Kodak Portra 400">
                        <label class="built-in-label"><input type="checkbox" name="film_na" id="film-na-check"> N/A</label>
                    </div>
                </div>
                
                <div class="lens-input-wrapper">
                    <label>ISO</label>
                    <input type="text" name="iso_speed" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>APERTURE</label>
                    <input type="text" name="aperture" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>SHUTTER SPEED</label>
                    <input type="text" name="shutter_speed" placeholder="Auto-detected from EXIF...">
                </div>
                
                <div class="lens-input-wrapper">
                    <label>FLASH FIRED</label>
                    <select name="flash_fire" class="full-width-select">
                        <option value="">Auto-detect</option>
                        <option value="No">No</option>
                        <option value="Yes">Yes</option>
                    </select>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div id="progress-container" class="progress-container">
            <div id="progress-bar" class="progress-bar"></div>
        </div>

        <div class="form-action-row">
            <button type="submit" class="master-update-btn">SMACK THAT @#$% UP!</button>
        </div>

    </form>
</div>

<script src="assets/js/ss-engine-admin-ui.js?v=<?php echo time(); ?>"></script>
<script>
    window.addEventListener('DOMContentLoaded', () => {
        if(typeof updateLabel === "function") {
            updateLabel('cat');
            updateLabel('album');
            updateLabel('collection');
        }
    });
</script>
<script src="assets/js/shortcode-toolbar.js"></script>
<?php if (snap_ai_configured()): ?>
<!-- AI Assist Modal -->
<div id="ai-assist-overlay" class="ai-assist-overlay" style="display:none;" aria-modal="true" role="dialog" aria-label="AI Writing Assistant">
    <div id="ai-assist-modal" class="ai-assist-modal">
        <div class="ai-assist-header">
            <span>AI WRITING ASSISTANT</span>
            <button type="button" id="ai-assist-close" class="ai-assist-close-btn">✕</button>
        </div>
        <div id="ai-assist-messages" class="ai-assist-messages"></div>
        <div class="ai-assist-input-row">
            <input type="text" id="ai-assist-input"
                   placeholder="Rephrase this, define a word, improve the opening paragraph…">
            <button type="button" id="ai-assist-send" class="sc-btn">SEND</button>
        </div>
        <div class="ai-assist-actions">
            <button type="button" id="ai-assist-dump" class="sc-btn" style="display:none;">
                ↓ DUMP TO EDITOR
            </button>
            <span class="ai-assist-hint">Or select text in the response and copy/paste manually.</span>
        </div>
    </div>
</div>
<script src="assets/js/ss-engine-ai.js?v=<?php echo time(); ?>"></script>
<?php endif; ?>
<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
