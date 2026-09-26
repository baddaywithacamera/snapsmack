<?php
/**
 * Longform cover selection must make image shape explicit and filterable.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$page = file_get_contents($root . '/smack-post-long.php');
$js = file_get_contents($root . '/assets/js/smack-longform-gallery-picker.js');
$gallery = file_get_contents($root . '/smack-gallery.php');
$failures = [];

function cover_shape_check(bool $ok, string $label): void {
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $failures[] = $label;
}

cover_shape_check(str_contains($page, 'data-orientation="PORTRAIT"')
    && str_contains($page, 'data-orientation="LANDSCAPE"')
    && str_contains($page, 'data-orientation="SQUARE"'),
    'cover chooser exposes portrait, landscape, and square filters');
cover_shape_check(str_contains($page, 'id="long-cover-btn" class="btn-smack btn-sm btn-mt-0"'),
    'select-cover action uses the standard SnapSmack button');
cover_shape_check(str_contains($page, 'smack-longform-gallery-picker.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>'),
    'cover chooser script is versioned for browser cache invalidation');
cover_shape_check(str_contains($js, "orientationFilter !== 'ALL'")
    && str_contains($js, "'&orientation='")
    && str_contains($js, "badge.textContent = orientation"),
    'chooser requests the selected orientation and labels each result');
cover_shape_check(str_contains($gallery, "\$orientation === 'portrait'")
    && str_contains($gallery, "\$orientation === 'landscape'")
    && str_contains($gallery, "\$orientation === 'square'")
    && str_contains($gallery, 'GREATEST(i.img_width, i.img_height) * 0.02'),
    'Gallery endpoint filters all three shapes with the same square tolerance');

if ($failures) exit(1);
echo "PASS: longform cover orientation regression\n";
// ===== SNAPSMACK EOF =====
