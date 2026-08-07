<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * REBUILD EXIF (smack-maintenance.php) merge rules.
 *
 * The pass is FILL-ONLY and must stay that way: it runs over every image in the
 * library, and smack-edit.php lets the operator hand-enter camera/lens/film that
 * exists nowhere in the file (a scanned film frame has no EXIF at all). A
 * regression here silently destroys metadata that was typed in by hand, on every
 * photo, with no undo. Hence a permanent test rather than a one-off check.
 *
 * Background: SECAUDIT 040 section 7 — FLKR FCKR wrote Pillow's raw tag names
 * (Model, FNumber, ISOSpeedRatings…) while the skins read core's lowercase ones
 * (camera, aperture, iso…), so imported photos showed a blank INFO panel.
 */

require_once __DIR__ . '/../core/image-ingest.php';

$failures = [];
$checks   = 0;
function exif_test(bool $ok, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $message;
}

// What snap_exif_display_from_file() returns for a JPEG that carries EXIF.
$file_exif = [
    'camera' => 'NIKON D850', 'lens' => 'NIKKOR Z 24-70mm f/2.8 S',
    'focal' => '24mm', 'iso' => '400', 'aperture' => 'f/2.8',
    'shutter' => '1/125', 'flash' => 'No',
];

// A FLKR-FCKR-imported row exactly as stored: Pillow key names, ExposureTime
// already rounded to death (1/125 became 0.01), plus Flickr-supplied geo.
$imported = [
    'Model' => 'Canon EOS 5D Mark IV', 'LensModel' => 'EF24-70mm f/2.8L II USM',
    'FNumber' => 2.8, 'ExposureTime' => 0.01, 'ISOSpeedRatings' => '800',
    'FocalLength' => 35.0, 'Flash' => '16', 'DateTimeOriginal' => '2019:06:01 10:22:04',
    'latitude' => 50.0405, 'longitude' => -110.6764,
];

// ── Legacy migration when the file yields nothing (PNG/WebP original) ────────
$r = snap_exif_fill_missing($imported, []);
exif_test(($r['camera']   ?? '') === 'CANON EOS 5D MARK IV', 'legacy camera not migrated/uppercased');
exif_test(($r['lens']     ?? '') === 'EF24-70mm f/2.8L II USM', 'legacy lens not migrated');
exif_test(($r['focal']    ?? '') === '35mm',  'legacy focal not migrated as mm');
exif_test(($r['iso']      ?? '') === '800',   'legacy ISO not migrated');
exif_test(($r['aperture'] ?? '') === 'f/2.8', 'legacy FNumber not recovered as f/2.8');
exif_test(($r['flash']    ?? '') === 'No',    'legacy Flash bitmask not decoded');

// The one value that must NEVER be reconstructed: round(1/125, 2) = 0.01, so the
// precision is gone. Printing "1/100" from it would be inventing a number.
exif_test(!isset($r['shutter']), 'shutter was invented from a rounded ExposureTime');

// ── Nothing is ever removed ──────────────────────────────────────────────────
exif_test(($r['latitude']  ?? null) === 50.0405,   'latitude was dropped');
exif_test(($r['longitude'] ?? null) === -110.6764, 'longitude was dropped');
exif_test(isset($r['Model'], $r['DateTimeOriginal']), 'legacy keys were deleted');

// ── The file wins over the legacy value, and recovers the true shutter ───────
$r = snap_exif_fill_missing($imported, $file_exif);
exif_test(($r['camera']  ?? '') === 'NIKON D850', 'file camera did not win over legacy');
exif_test(($r['shutter'] ?? '') === '1/125',      'real shutter not recovered from the file');
exif_test(($r['latitude'] ?? null) === 50.0405,   'latitude was dropped on the file path');

// ── NEVER clobber a value that is already there ──────────────────────────────
$r = snap_exif_fill_missing(['camera' => 'HASSELBLAD 500CM', 'shutter' => '1/60'], $file_exif);
exif_test(($r['camera']  ?? '') === 'HASSELBLAD 500CM', 'hand-entered camera was overwritten');
exif_test(($r['shutter'] ?? '') === '1/60',             'hand-entered shutter was overwritten');
exif_test(($r['lens'] ?? '') === 'NIKKOR Z 24-70mm f/2.8 S', 'blank field was not filled');

// A film scan: hand-entered, no EXIF in the file. Must come back untouched.
$film = ['camera' => 'LEICA M6', 'lens' => 'SUMMICRON 35', 'iso' => 'N/A', 'film' => 'HP5+'];
exif_test(snap_exif_fill_missing($film, []) === null, 'film scan was modified');

// ── Idempotence — re-running the pass must be a no-op ────────────────────────
$once = snap_exif_fill_missing($imported, $file_exif);
exif_test(snap_exif_fill_missing($once, $file_exif) === null, 'second pass was not a no-op');
exif_test(snap_exif_fill_missing($file_exif, $file_exif) === null, 'complete row did not return null');
exif_test(snap_exif_fill_missing([], []) === null, 'empty row with no file EXIF did not return null');

// ── Reader: unreadable/unsupported input degrades quietly, never fatals ──────
exif_test(snap_exif_display_from_file('') === [], 'empty path did not return []');
exif_test(snap_exif_display_from_file(__DIR__ . '/does-not-exist.jpg') === [], 'missing file did not return []');
exif_test(snap_exif_display_from_file(__FILE__) === [], 'non-image extension did not return []');

// ext-exif is absent on some shared hosts, and an undefined function is a FATAL
// that '@' does not suppress — it would kill a 10,000-image run mid-pass.
$ingest = file_get_contents(__DIR__ . '/../core/image-ingest.php');
exif_test(str_contains($ingest, "function_exists('exif_read_data')"), 'missing ext-exif guard');

// The handler must stay fill-only and must contain img_file to the install root
// (img_file arrives from an API client unvalidated — SECAUDIT 040 finding C).
$maint = file_get_contents(__DIR__ . '/../smack-maintenance.php');
exif_test(str_contains($maint, 'snap_exif_fill_missing'), 'maintenance pass no longer uses the fill-only merge');
exif_test(str_contains($maint, "strpos(\$rel_norm, '..') !== false"), 'img_file containment check is missing');

// ── Editors must MERGE onto stored img_exif, never rebuild from the form ─────
// Rebuilding from the form deleted latitude/longitude on every save. Location is
// preserved by policy (SECAUDIT 040 section 6), so this is pinned in all three
// editors — smack-edit and smack-swap both had the bug; the carousel editor
// never did.
$geo  = ['camera' => 'CANON EOS 5D MARK IV', 'latitude' => 50.0405, 'longitude' => -110.6764, 'film' => 'HP5+'];
$form = ['camera' => 'LEICA M6', 'lens' => 'SUMMICRON 35', 'iso' => 'N/A'];
$m    = snap_exif_merge_edit($geo, $form);
exif_test(($m['latitude']  ?? null) === 50.0405,   'edit merge dropped latitude');
exif_test(($m['longitude'] ?? null) === -110.6764, 'edit merge dropped longitude');
exif_test(($m['film']      ?? '')   === 'HP5+',    'edit merge dropped film');
exif_test(($m['camera']    ?? '')   === 'LEICA M6', 'edit merge did not apply the form value');
exif_test(($m['lens']      ?? '')   === 'SUMMICRON 35', 'edit merge did not add a new form field');

foreach (['smack-edit.php', 'smack-swap.php', 'smack-edit-carousel.php'] as $editor) {
    $src = file_get_contents(__DIR__ . '/../' . $editor);
    $ok  = str_contains($src, 'snap_exif_merge_edit')      // the named rule, or
        || str_contains($src, 'array_merge($existing_exif'); // the carousel's inline original
    exif_test($ok, "{$editor} rebuilds img_exif from the form — GPS will be discarded on save");
}

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: REBUILD EXIF merge regression suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
