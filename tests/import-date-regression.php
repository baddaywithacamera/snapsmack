<?php
/**
 * Imported photographs must carry their own date, not the import time
 * (foreverphotograph.ing, 2026-09-11: old scans on top of a newest-first feed).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
function id_check(string $label, bool $ok): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}
$api = file_get_contents(__DIR__ . '/../core/flkrfckr-api.php');
id_check('import API reads EXIF before stamping now', strpos($api, "flkrfckr_exif_date(dirname(__DIR__) . '/' . ltrim(\$img_file, '/')) ?: date('Y-m-d H:i:s')") !== false);
id_check('EXIF helper prefers DateTimeOriginal and rejects 0000 dates', strpos($api, "['EXIF', 'DateTimeOriginal'], ['EXIF', 'DateTimeDigitized'], ['IFD0', 'DateTime']") !== false && strpos($api, "(int)\$m[1] >= 1900") !== false);
$rep = file_get_contents(__DIR__ . '/../repair-image-dates.php');
id_check('repair script is CLI only', strpos($rep, "php_sapi_name() !== 'cli'") !== false);
id_check('repair script is dry-run unless --apply', strpos($rep, "\$apply  = array_key_exists('apply', \$opts);") !== false && strpos($rep, 'Dry run only. Re-run with --apply') !== false);
id_check('repair script never touches sort_order', strpos($rep, 'sort_order') !== false && strpos($rep, "UPDATE snap_images SET img_date = ? WHERE id = ?") !== false && strpos($rep, 'SET sort_order') === false);
id_check('repair script requires a window', strpos($rep, 'Give the window: --since=') !== false);
$sl = file_get_contents(__DIR__ . '/../skins/slickr/landing.php');
id_check('SLICKR stream: manual order, then photo date, newest first', strpos($sl, "ORDER BY sort_order ASC, img_date DESC, id DESC") !== false);
echo "PASS: import date regression\n";
// ===== SNAPSMACK EOF =====
