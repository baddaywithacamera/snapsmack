<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be the canonical PHP EOF marker.
 */

$source = (string)file_get_contents(__DIR__ . '/../core/public-repository.php');
$start = strpos($source, 'public function postBySlug');
$end = strpos($source, 'public function photographsForPost');
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('Could not locate public post lookup methods.');
}
$lookups = substr($source, $start, $end - $start);

foreach (['content', 'colophon', 'signature_image_id', 'featured_image_id', 'user_id'] as $required) {
    if (!str_contains($lookups, $required)) {
        throw new RuntimeException("Public post lookup lost required field: {$required}");
    }
}

foreach (['description', 'updated_at', 'allow_download', 'download_url', 'panorama_rows', 'show_featured_image', 'trigram_id', 'cover_pos_x', 'cover_pos_y', 'cover_zoom', 'is_sensitive,content_warning'] as $optional) {
    if (str_contains($lookups, $optional)) {
        throw new RuntimeException("Public post lookup still requires unrelated optional field(s): {$optional}");
    }
}

$controller = (string)file_get_contents(__DIR__ . '/../core/smacktalk-public-controller.php');
$singleStart = strpos($controller, 'function snapsmack_smacktalk_single');
$singleEnd = strpos($controller, 'function snapsmack_smacktalk_feed');
$single = substr($controller, $singleStart, $singleEnd - $singleStart);
if (!str_contains($single, 'photographPathById') || preg_match('/featured\s*=.*photographById/', $single)) {
    throw new RuntimeException('Story routing still expands a cover through the optional full photograph schema.');
}

echo "PASS: public post lookup does not require unrelated optional post columns.\n";

// ===== SNAPSMACK EOF =====
