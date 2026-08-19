<?php
/** Shared pre-decode policy for locally hosted photographs. */

const SNAPSMACK_LOCAL_IMAGE_LONG_EDGE = 3840;
const SNAPSMACK_LOCAL_IMAGE_SHORT_EDGE = 2160;
const SNAPSMACK_LOCAL_IMAGE_PIXEL_BUDGET = 8294400; // 3840 x 2160

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

