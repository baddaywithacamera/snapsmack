<?php
/**
 * SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
 * Shared pre-decode policy for locally hosted photographs.
 */

const SNAPSMACK_LOCAL_IMAGE_LONG_EDGE = 3840;
const SNAPSMACK_LOCAL_IMAGE_SHORT_EDGE = 2160;
const SNAPSMACK_LOCAL_IMAGE_PIXEL_BUDGET = 8294400; // 3840 x 2160

const SNAPSMACK_HEIC_REJECTION = 'HEIC/HEIF images are not supported because they commonly rely on patent-encumbered HEVC technology. Convert the image to JPEG, PNG or WebP before uploading.';

/**
 * HEIC/HEIF is a project-wide hard rejection. Check both the client filename
 * and detected content type: hosts do not identify every HEIF brand the same
 * way, and neither signal is sufficient by itself.
 */
function snapsmack_upload_value_contains_heic_name(mixed $value): bool {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (snapsmack_upload_value_contains_heic_name($item)) return true;
        }
        return false;
    }
    return is_string($value) && preg_match('/\.(?:heic|heif)$/i', trim($value)) === 1;
}

function snapsmack_upload_value_contains_heic_mime(mixed $value): bool {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (snapsmack_upload_value_contains_heic_mime($item)) return true;
        }
        return false;
    }
    if (!is_string($value) || $value === '' || !is_file($value)) return false;

    $mime = '';
    if (class_exists('finfo')) {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($value);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($value);
    }
    if (in_array(strtolower($mime), [
        'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence',
    ], true)) {
        return true;
    }

    // Some shared hosts return application/octet-stream for HEIC. Inspect the
    // ISO-BMFF file-type brands as a narrow fallback so renaming .heic to .jpg
    // cannot bypass the policy. These are HEVC-backed HEIC brands; AVIF brands
    // are deliberately not included.
    $head = (string)@file_get_contents($value, false, null, 0, 64);
    if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') return false;
    $brands = substr($head, 8);
    foreach (str_split($brands, 4) as $brand) {
        if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs'], true)) {
            return true;
        }
    }
    return false;
}

function snapsmack_request_contains_heic_upload(array $files): bool {
    foreach ($files as $file) {
        if (!is_array($file)) continue;
        if (snapsmack_upload_value_contains_heic_name($file['name'] ?? null)
            || snapsmack_upload_value_contains_heic_mime($file['tmp_name'] ?? null)) {
            return true;
        }
    }
    return false;
}

/** Stop HEIC/HEIF before any route-specific handler can save or inspect it. */
function snapsmack_reject_heic_upload_request(bool $json = false): void {
    if (!snapsmack_request_contains_heic_upload($_FILES ?? [])) return;

    http_response_code(415);
    header('Cache-Control: no-store');
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => SNAPSMACK_HEIC_REJECTION], JSON_UNESCAPED_SLASHES);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo SNAPSMACK_HEIC_REJECTION;
    }
    exit;
}

/**
 * Validate image dimensions using header metadata only; this must run before GD.
 */
function snapsmack_local_image_within_4k(string $path, ?string &$error = null): bool {
    $error = null;
    if ($path === '' || !is_file($path)) {
        $error = 'The uploaded image could not be read.';
        return false;
    }

    $dimensions = @getimagesize($path);
    $width = (int)($dimensions[0] ?? 0);
    $height = (int)($dimensions[1] ?? 0);
    if ($width < 1 || $height < 1) {
        $error = 'The uploaded file is not a readable image.';
        return false;
    }

    $long = max($width, $height);
    $short = min($width, $height);
    $pixels = $width * $height;
    if ($long > SNAPSMACK_LOCAL_IMAGE_LONG_EDGE
        || $short > SNAPSMACK_LOCAL_IMAGE_SHORT_EDGE
        || $pixels > SNAPSMACK_LOCAL_IMAGE_PIXEL_BUDGET) {
        $error = 'Local images may be no larger than 3840 x 2160 (4K), in either orientation. '
               . 'Use an external download link for a higher-resolution original.';
        return false;
    }

    return true;
}

// ===== SNAPSMACK EOF =====
