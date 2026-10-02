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

foreach (['content', 'colophon', 'signature_image_id', 'featured_image_id'] as $required) {
    if (!str_contains($lookups, $required)) {
        throw new RuntimeException("Public post lookup lost required field: {$required}");
    }
}

foreach (['cover_pos_x', 'cover_pos_y', 'cover_zoom', 'user_id,is_sensitive,content_warning'] as $optional) {
    if (str_contains($lookups, $optional)) {
        throw new RuntimeException("Public post lookup still requires unrelated optional field(s): {$optional}");
    }
}

echo "PASS: public post lookup does not require unrelated optional post columns.\n";

// ===== SNAPSMACK EOF =====
