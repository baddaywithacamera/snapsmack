#!/usr/bin/env php
<?php
/**
 * SNAPSMACK - Colour Palette Backfill Utility
 * Alpha v0.7.1
 *
 * Extracts colour palettes from all images that lack palette data
 * and backfills the img_display_options JSON column.
 *
 * Usage:
 *   php tools/backfill-palettes.php
 *
 * Processes images in configurable batches with throttling to avoid
 * CPU spikes on shared hosting environments.
 */

// Determine base directory for relative includes
$base_dir = dirname(__DIR__);

// Load database connection
require_once $base_dir . '/core/db.php';

// Load palette extraction function
require_once $base_dir . '/core/palette-extract.php';

// Configuration
$batch_size = 50;
$batch_delay = 2; // seconds between batches

// Statistics
$processed = 0;
$skipped = 0;
$errors = 0;

// Console output helper
function log_message($status, $message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] [$status] $message\n";
}

log_message('INFO', 'Starting palette backfill utility');

// Query for images needing palette data
// Either img_display_options is NULL or it doesn't contain "palette"
try {
    $query = "SELECT id, img_file, img_slug, img_display_options
              FROM snap_images
              WHERE img_display_options IS NULL
                 OR img_display_options NOT LIKE '%\"palette\"%'
              ORDER BY id ASC";

    $stmt = $pdo->query($query);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($images);

    log_message('INFO', "Found $total images needing palette extraction");

} catch (PDOException $e) {
    log_message('ERROR', 'Database query failed: ' . $e->getMessage());
    die(1);
}

if (empty($images)) {
    log_message('INFO', 'No images require palette backfill. Exiting.');
    exit(0);
}

// Calculate number of batches
$batches = ceil($total / $batch_size);
log_message('INFO', "Processing in $batches batch(es) of $batch_size images");

// Prepare update statement
try {
    $update_stmt = $pdo->prepare(
        "UPDATE snap_images SET img_display_options = ? WHERE id = ?"
    );
} catch (PDOException $e) {
    log_message('ERROR', 'Failed to prepare update statement: ' . $e->getMessage());
    die(1);
}

// Process images
$batch_num = 0;
foreach (array_chunk($images, $batch_size) as $batch) {
    $batch_num++;
    log_message('INFO', "Processing batch $batch_num/$batches");

    foreach ($batch as $image) {
        $image_id = $image['id'];
        $image_file = $image['img_file'];
        $image_slug = $image['img_slug'];
        $existing_options = $image['img_display_options'];

        // Verify file exists
        if (!file_exists($image_file)) {
            log_message('SKIP', "$image_slug - file not found");
            $skipped++;
            continue;
        }

        try {
            // Extract palette from image
            $palette = snapsmack_extract_palette($image_file, 5);

            if (empty($palette)) {
                log_message('SKIP', "$image_slug - could not extract palette");
                $skipped++;
                continue;
            }

            // Build JSON payload
            $palette_json = json_encode(['palette' => $palette], JSON_UNESCAPED_SLASHES);

            // Merge with existing display options if present
            $display_options = $palette_json;

            if (!empty($existing_options)) {
                try {
                    $existing_data = json_decode($existing_options, true);
                    if (is_array($existing_data)) {
                        $existing_data['palette'] = $palette;
                        $display_options = json_encode($existing_data, JSON_UNESCAPED_SLASHES);
                    }
                } catch (Exception $e) {
                    // If existing JSON is malformed, just use palette
                    log_message('WARN', "$image_slug - existing JSON malformed, replacing");
                }
            }

            // Update database
            $update_stmt->execute([$display_options, $image_id]);

            log_message('OK', "$image_slug - " . count($palette) . ' colours');
            $processed++;

        } catch (Exception $e) {
            log_message('ERROR', "$image_slug - " . $e->getMessage());
            $errors++;
        }
    }

    // Add delay between batches (except after the last batch)
    if ($batch_num < $batches) {
        log_message('INFO', "Cooling down for $batch_delay second(s)...");
        sleep($batch_delay);
    }
}

// Final summary
log_message('INFO', '========================================');
log_message('INFO', 'Done. Processed: ' . $processed . ', Skipped: ' . $skipped . ', Errors: ' . $errors);
log_message('INFO', '========================================');

exit($errors > 0 ? 1 : 0);
?>
