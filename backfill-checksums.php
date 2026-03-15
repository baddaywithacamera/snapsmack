<?php
/**
 * SNAPSMACK - Recovery metadata backfill utility
 * Alpha v0.7.4
 *
 * Populates img_thumb_square, img_thumb_aspect, and img_checksum columns
 * for existing images that predate the recovery schema enrichment.
 * Also backfills asset_checksum for media library assets.
 *
 * Run once after upgrading to Alpha 0.7. Safe to re-run — only touches
 * records with NULL values. Requires authentication.
 */

require_once 'core/auth.php';

if (!isset($_SESSION['user_id'])) {
    die("Access Denied.");
}

// --- PROGRESS UI ---
echo "<!DOCTYPE html><html><head><title>SnapSmack Checksum Backfill</title>";
echo "<style>body{background:#1a1a1a;color:#ccc;font-family:monospace;padding:20px;font-size:13px;line-height:1.6;}";
echo ".success{color:#39FF14;} .info{color:#00bfff;} .warn{color:#ffaa00;} .error{color:#ff6b6b;}";
echo "h2{color:#a0ff90;letter-spacing:2px;} h3{color:#eee;margin-top:24px;} hr{border-color:#333;margin:16px 0;}</style></head><body>";
echo "<h2>RECOVERY METADATA BACKFILL</h2>";
echo "<p class='info'>Populating thumbnail paths and SHA-256 checksums for disaster recovery.</p><hr>";
flush();

// =====================================================================
// PHASE 1: snap_images — thumb paths + checksums
// =====================================================================
echo "<h3>PHASE 1: IMAGE RECORDS</h3>";

$stmt = $pdo->query("SELECT id, img_file, img_thumb_square, img_thumb_aspect, img_checksum FROM snap_images");
$images = $stmt->fetchAll();
$total_images = count($images);

$img_updated = 0;
$img_skipped = 0;
$img_missing = 0;
$batch_counter = 0;
$batch_size = 25; // Throttle: pause after every N hash computations

$update_stmt = $pdo->prepare("UPDATE snap_images SET img_thumb_square = ?, img_thumb_aspect = ?, img_checksum = ? WHERE id = ?");

echo "<p class='info'>Processing {$total_images} images in batches of {$batch_size}...</p>";
flush();

foreach ($images as $idx => $img) {
    // Skip records that already have all three fields populated.
    if ($img['img_thumb_square'] !== null && $img['img_thumb_aspect'] !== null && $img['img_checksum'] !== null) {
        $img_skipped++;
        continue;
    }

    $file = $img['img_file'];

    // Verify source file exists.
    if (!file_exists($file)) {
        echo "<span class='warn'>MISSING:</span> " . htmlspecialchars($file) . " (id:{$img['id']})<br>";
        $img_missing++;
        flush();
        continue;
    }

    // Derive thumbnail paths from main image path.
    $path_info = pathinfo($file);
    $thumb_sq  = $path_info['dirname'] . '/thumbs/t_' . $path_info['basename'];
    $thumb_a   = $path_info['dirname'] . '/thumbs/a_' . $path_info['basename'];

    // Compute SHA-256 of the main image file.
    $checksum = hash_file('sha256', $file);

    // Only store thumb paths if the physical files exist.
    $db_sq = file_exists($thumb_sq) ? $thumb_sq : null;
    $db_a  = file_exists($thumb_a)  ? $thumb_a  : null;

    $update_stmt->execute([$db_sq, $db_a, $checksum, $img['id']]);

    $status_parts = [];
    if ($db_sq) $status_parts[] = 't_';
    if ($db_a)  $status_parts[] = 'a_';
    $status_parts[] = 'sha256';

    $progress = $idx + 1;
    echo "<span class='success'>UPDATED:</span> " . htmlspecialchars($path_info['basename']) . " [" . implode(' + ', $status_parts) . "] ({$progress}/{$total_images})<br>";
    $img_updated++;
    $batch_counter++;
    flush();

    // Throttle: let the server breathe after every batch of hash computations
    if ($batch_counter >= $batch_size) {
        echo "<span class='info'>— cooling down (1s pause) —</span><br>";
        flush();
        sleep(1);
        $batch_counter = 0;
    }
}

echo "<hr>";
echo "<p class='info'>Images — Updated: {$img_updated} | Already done: {$img_skipped} | Missing files: {$img_missing}</p>";
flush();

// =====================================================================
// PHASE 2: snap_assets — checksums
// =====================================================================
echo "<h3>PHASE 2: MEDIA ASSETS</h3>";

// Check if snap_assets table exists (may not on older installations).
try {
    $asset_stmt = $pdo->query("SELECT id, asset_path, asset_checksum FROM snap_assets");
    $assets = $asset_stmt->fetchAll();
} catch (PDOException $e) {
    echo "<span class='warn'>snap_assets table not found — skipping media asset backfill.</span><br>";
    echo "<p class='info'>Run the installer or manually create the snap_assets table to enable this feature.</p>";
    $assets = [];
}

$asset_updated = 0;
$asset_skipped = 0;
$asset_missing = 0;

// Check if asset_checksum column exists before trying to update.
$has_checksum_col = false;
if (!empty($assets)) {
    try {
        $pdo->query("SELECT asset_checksum FROM snap_assets LIMIT 1");
        $has_checksum_col = true;
    } catch (PDOException $e) {
        echo "<span class='warn'>asset_checksum column not found — run ALTER TABLE or reinstall to add it.</span><br>";
    }
}

if ($has_checksum_col && !empty($assets)) {
    $asset_update = $pdo->prepare("UPDATE snap_assets SET asset_checksum = ? WHERE id = ?");
    $asset_batch = 0;

    foreach ($assets as $asset) {
        if ($asset['asset_checksum'] !== null) {
            $asset_skipped++;
            continue;
        }

        if (!file_exists($asset['asset_path'])) {
            echo "<span class='warn'>MISSING:</span> " . htmlspecialchars($asset['asset_path']) . " (id:{$asset['id']})<br>";
            $asset_missing++;
            flush();
            continue;
        }

        $checksum = hash_file('sha256', $asset['asset_path']);
        $asset_update->execute([$checksum, $asset['id']]);

        echo "<span class='success'>UPDATED:</span> " . htmlspecialchars(basename($asset['asset_path'])) . " [sha256]<br>";
        $asset_updated++;
        $asset_batch++;
        flush();

        if ($asset_batch >= $batch_size) {
            echo "<span class='info'>— cooling down (1s pause) —</span><br>";
            flush();
            sleep(1);
            $asset_batch = 0;
        }
    }
}

echo "<hr>";
echo "<p class='info'>Assets — Updated: {$asset_updated} | Already done: {$asset_skipped} | Missing files: {$asset_missing}</p>";

// =====================================================================
// COMPLETION
// =====================================================================
echo "<hr>";
echo "<h3>BACKFILL COMPLETE.</h3>";
echo "<p class='info'>Your database now contains recovery metadata for all located files.</p>";
echo "<p class='warn'>You can safely delete this file from your server after running it.</p>";
echo "</body></html>";
?>
