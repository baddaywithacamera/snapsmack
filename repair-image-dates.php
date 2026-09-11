<?php
/**
 * SNAPSMACK - Repair image dates from EXIF (one-off, CLI only)
 *
 * An import that arrives without a usable date gets stamped with the import
 * time, so a batch of old photographs lands at the TOP of a newest-first feed
 * (foreverphotograph.ing, 2026-09-11: 1980s–2000s scans all dated
 * 2026-09-11 05:58). This puts the photograph's own EXIF date back.
 *
 * DRY RUN by default: prints what WOULD change and touches nothing.
 * Run once per site, on that site's box, from the site root:
 *
 *   php repair-image-dates.php --since="2026-09-11 00:00" [--until="2026-09-11 23:59"] [--source=flickr]
 *   php repair-image-dates.php --since="2026-09-11 00:00" --source=flickr --apply
 *
 *   --since / --until   only rows whose CURRENT img_date is inside this window
 *   --source=flickr     only rows the FLKR FCKR importer wrote (img_source_file 'flickr:…')
 *   --apply             write the changes (otherwise dry run)
 *
 * Reads DateTimeOriginal, then DateTimeDigitized, then DateTime from the JPEG.
 * Rows with no EXIF date are listed and left alone. sort_order is never touched,
 * so manual drag order in Manage Posts is unaffected.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (!function_exists('exif_read_data')) {
    fwrite(STDERR, "PHP exif extension is not available on this box; nothing to read dates from.\n");
    exit(1);
}

$base = dirname(__FILE__);
require_once $base . '/core/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection did not come up (core/db.php).\n");
    exit(1);
}

$opts   = getopt('', ['since:', 'until::', 'source::', 'apply']);
$since  = trim((string)($opts['since'] ?? ''));
$until  = trim((string)($opts['until'] ?? ''));
$source = trim((string)($opts['source'] ?? ''));
$apply  = array_key_exists('apply', $opts);
if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $since)) {
    fwrite(STDERR, "Give the window: --since=\"YYYY-MM-DD HH:MM\" (the wrong dates are the IMPORT time).\n");
    exit(1);
}

$sql    = "SELECT id, img_file, img_date, img_source_file FROM snap_images WHERE img_date >= ?";
$params = [$since];
if ($until !== '') { $sql .= " AND img_date <= ?"; $params[] = $until; }
if ($source !== '') { $sql .= " AND img_source_file LIKE ?"; $params[] = $source . ':%'; }
$sql .= " ORDER BY id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function repair_exif_date(string $path): ?string {
    if (!is_file($path) || !preg_match('/\.jpe?g$/i', $path)) return null;
    $exif = @exif_read_data($path, 'EXIF,IFD0', true);
    if (!$exif) return null;
    foreach ([['EXIF', 'DateTimeOriginal'], ['EXIF', 'DateTimeDigitized'], ['IFD0', 'DateTime']] as [$sec, $key]) {
        $v = trim((string)($exif[$sec][$key] ?? ''));
        // EXIF writes 'YYYY:MM:DD HH:MM:SS'; some cameras write '0000:00:00 00:00:00' for "unknown".
        if (preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}:\d{2}:\d{2})$/', $v, $m) && (int)$m[1] >= 1900) {
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}";
        }
    }
    return null;
}

$plan = []; $no_exif = []; $missing = [];
foreach ($rows as $r) {
    $rel  = ltrim(str_replace('\\', '/', (string)$r['img_file']), '/');
    $path = $base . '/' . $rel;
    if (!is_file($path)) { $missing[] = $r; continue; }
    $date = repair_exif_date($path);
    if ($date === null) { $no_exif[] = $r; continue; }
    if (substr($date, 0, 16) === substr((string)$r['img_date'], 0, 16)) continue;   // already right
    $plan[] = ['id' => (int)$r['id'], 'file' => $rel, 'from' => $r['img_date'], 'to' => $date];
}

printf("%s — %d row(s) in window%s; %d to change, %d without an EXIF date, %d file(s) missing.\n\n",
    $apply ? 'APPLY' : 'DRY RUN', count($rows), $source !== '' ? " (source $source)" : '', count($plan), count($no_exif), count($missing));
foreach ($plan as $p) printf("  #%-6d %s  %s  ->  %s\n", $p['id'], substr($p['from'], 0, 16), str_pad(basename($p['file']), 42), $p['to']);
if ($no_exif) { echo "\nNo EXIF date (left alone):\n"; foreach ($no_exif as $r) printf("  #%-6d %s\n", $r['id'], basename((string)$r['img_file'])); }
if ($missing) { echo "\nFile missing on disk (left alone):\n"; foreach ($missing as $r) printf("  #%-6d %s\n", $r['id'], $r['img_file']); }

if (!$apply) {
    echo "\nDry run only. Re-run with --apply to write these " . count($plan) . " date(s).\n";
    exit(0);
}
$upd = $pdo->prepare("UPDATE snap_images SET img_date = ? WHERE id = ?");
$pdo->beginTransaction();
foreach ($plan as $p) $upd->execute([$p['to'], $p['id']]);
$pdo->commit();
// The public page cache holds the old order until it expires; drop it now.
if (is_file($base . '/core/page-cache.php')) {
    require_once $base . '/core/page-cache.php';
    if (function_exists('page_cache_purge_all')) { page_cache_purge_all(); echo "Page cache purged.\n"; }
}
echo "Done: " . count($plan) . " image date(s) repaired. sort_order untouched.\n";
// ===== SNAPSMACK EOF =====
