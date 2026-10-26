<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/public-controller.php';

$item = [
    'img_file' => '/media/full.jpg',
    'img_thumb_square' => '/media/square-crop.jpg',
    'img_thumb_aspect' => '/media/whole-print.jpg',
];
if (snapsmack_grid_presentation_image($item, 'instant-camera') !== '/media/whole-print.jpg') {
    throw new RuntimeException('INSTANT CAMERA received a square crop instead of the whole print scan.');
}
if (snapsmack_grid_presentation_image($item, 'the-grid') !== '/media/square-crop.jpg') {
    throw new RuntimeException('The ordinary grid crop contract changed while repairing INSTANT CAMERA.');
}

echo "Instant Camera whole-print regression passed.\n";
// ===== SNAPSMACK EOF =====
