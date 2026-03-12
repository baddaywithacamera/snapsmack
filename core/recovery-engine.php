<?php
/**
 * SNAPSMACK - Recovery Engine
 * Alpha v0.7.3
 *
 * Handles site restoration from a SQL dump and image files. Used by the
 * installer's recovery mode. Can relocate images from a flat recovery
 * directory to the correct img_uploads/YYYY/MM/ structure, regenerate
 * missing thumbnails, compute checksums, and restore branding assets.
 *
 * No external dependencies — vanilla PHP 8.0+ with GD.
 */

class SnapSmackRecovery {

    private PDO $pdo;
    private string $baseDir;

    public function __construct(PDO $pdo, string $baseDir) {
        $this->pdo = $pdo;
        $this->baseDir = rtrim($baseDir, '/');
    }

    // =================================================================
    // SQL DUMP IMPORT
    // =================================================================

    /**
     * Imports a SQL dump file line-by-line, executing each statement.
     * Handles multi-line statements terminated by semicolons.
     *
     * @return array{imported: int, errors: string[]}
     */
    public function importSqlDump(string $filePath): array {
        $imported = 0;
        $errors = [];
        $buffer = '';
        $in_block_comment = false;

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['imported' => 0, 'errors' => ['Could not open SQL file.']];
        }

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);

            // Handle multi-line block comments
            if ($in_block_comment) {
                if (str_contains($trimmed, '*/')) {
                    $in_block_comment = false;
                }
                continue;
            }

            // Skip single-line comments and empty lines
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            // Start of block comment
            if (str_starts_with($trimmed, '/*')) {
                if (!str_contains($trimmed, '*/')) {
                    $in_block_comment = true;
                }
                continue;
            }

            $buffer .= $line;

            // Execute when we hit a semicolon at the end of a line
            if (str_ends_with($trimmed, ';')) {
                try {
                    $this->pdo->exec($buffer);
                    $imported++;
                } catch (PDOException $e) {
                    // Skip "table already exists" errors during recovery
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        $errors[] = substr($buffer, 0, 80) . '... → ' . $e->getMessage();
                    }
                }
                $buffer = '';
            }
        }

        fclose($handle);
        return ['imported' => $imported, 'errors' => $errors];
    }

    // =================================================================
    // IMAGE FILE RESTORATION
    // =================================================================

    /**
     * Processes all snap_images records, locating files either in-place
     * or in a flat recovery directory, moving them to the correct location.
     *
     * @param string|null $flatDir Path to flat recovery directory (null = files already in place)
     * @return array{restored: int, in_place: int, missing: int, errors: string[]}
     */
    public function restoreImages(?string $flatDir = null): array {
        $restored = 0;
        $in_place = 0;
        $missing  = 0;
        $errors   = [];

        $images = $this->pdo->query("SELECT id, img_file FROM snap_images ORDER BY id")->fetchAll();

        foreach ($images as $img) {
            $rel_path = $img['img_file'];
            $abs_path = $this->baseDir . '/' . $rel_path;

            // Already in the correct location?
            if (file_exists($abs_path)) {
                $in_place++;
                $this->streamProgress("IN PLACE: " . basename($rel_path), 'ok');
                continue;
            }

            // Try to find it in the flat recovery directory
            if ($flatDir) {
                $basename = basename($rel_path);
                $flat_file = rtrim($flatDir, '/') . '/' . $basename;

                if (file_exists($flat_file)) {
                    // Create target directory structure
                    $target_dir = dirname($abs_path);
                    if (!is_dir($target_dir)) {
                        if (!@mkdir($target_dir, 0755, true)) {
                            $errors[] = "Cannot create directory: {$target_dir}";
                            continue;
                        }
                    }

                    // Copy file to correct location (copy, not move — preserve source)
                    if (copy($flat_file, $abs_path)) {
                        $restored++;
                        $this->streamProgress("RESTORED: " . basename($rel_path) . " → " . dirname($rel_path), 'ok');
                    } else {
                        $errors[] = "Failed to copy: {$basename}";
                    }
                    continue;
                }
            }

            $missing++;
            $this->streamProgress("MISSING: " . htmlspecialchars($rel_path) . " (id:{$img['id']})", 'warn');
        }

        return compact('restored', 'in_place', 'missing', 'errors');
    }

    // =================================================================
    // THUMBNAIL REGENERATION
    // =================================================================

    /**
     * Regenerates missing thumbnails for all images and updates DB records
     * with thumb paths and checksums.
     *
     * @return array{generated: int, skipped: int, errors: string[]}
     */
    public function regenerateAndChecksum(): array {
        $generated = 0;
        $skipped   = 0;
        $errors    = [];
        $batch_counter = 0;
        $batch_size    = 25; // Throttle: pause after every N GD operations

        $images = $this->pdo->query("SELECT id, img_file FROM snap_images ORDER BY id")->fetchAll();
        $total  = count($images);
        $update = $this->pdo->prepare("UPDATE snap_images SET img_thumb_square = ?, img_thumb_aspect = ?, img_checksum = ? WHERE id = ?");

        $this->streamProgress("Processing {$total} images in batches of {$batch_size}...", 'info');

        foreach ($images as $idx => $img) {
            $file = $this->baseDir . '/' . $img['img_file'];

            if (!file_exists($file)) {
                $skipped++;
                continue;
            }

            $pi = pathinfo($img['img_file']);
            $thumb_dir_rel  = $pi['dirname'] . '/thumbs';
            $thumb_dir_abs  = $this->baseDir . '/' . $thumb_dir_rel;
            $sq_rel  = $thumb_dir_rel . '/t_' . $pi['basename'];
            $asp_rel = $thumb_dir_rel . '/a_' . $pi['basename'];
            $sq_abs  = $this->baseDir . '/' . $sq_rel;
            $asp_abs = $this->baseDir . '/' . $asp_rel;

            $needs_gen = !file_exists($sq_abs) || !file_exists($asp_abs);

            if ($needs_gen) {
                if (!is_dir($thumb_dir_abs)) {
                    @mkdir($thumb_dir_abs, 0755, true);
                }

                $dimensions = @getimagesize($file);
                if (!is_array($dimensions) || $dimensions[0] <= 0 || $dimensions[1] <= 0) {
                    $errors[] = "Cannot read dimensions: " . basename($file);
                    $skipped++;
                    continue;
                }
                list($w, $h) = $dimensions;

                $mime = mime_content_type($file);
                $src = $this->loadImage($file, $mime);
                if (!$src) {
                    $errors[] = "Unsupported format: " . basename($file);
                    $skipped++;
                    continue;
                }

                // --- Square thumbnail (t_) ---
                if (!file_exists($sq_abs)) {
                    $sq_size = 400;
                    $min_dim = min($w, $h);
                    $off_x = ($w - $min_dim) / 2;
                    $off_y = ($h - $min_dim) / 2;

                    $t_dst = imagecreatetruecolor($sq_size, $sq_size);
                    $this->prepAlpha($t_dst, $mime);
                    imagecopyresampled($t_dst, $src, 0, 0, $off_x, $off_y, $sq_size, $sq_size, $min_dim, $min_dim);
                    $this->saveImage($t_dst, $sq_abs, $mime);
                    imagedestroy($t_dst);
                }

                // --- Aspect-preserved thumbnail (a_) ---
                if (!file_exists($asp_abs)) {
                    $long = 400;
                    if ($w >= $h) {
                        $a_w = $long;
                        $a_h = round($h * ($long / $w));
                    } else {
                        $a_h = $long;
                        $a_w = round($w * ($long / $h));
                    }
                    if ($w < $long && $h < $long) {
                        $a_w = $w;
                        $a_h = $h;
                    }

                    $a_dst = imagecreatetruecolor($a_w, $a_h);
                    $this->prepAlpha($a_dst, $mime);
                    imagecopyresampled($a_dst, $src, 0, 0, 0, 0, $a_w, $a_h, $w, $h);
                    $this->saveImage($a_dst, $asp_abs, $mime);
                    imagedestroy($a_dst);
                }

                imagedestroy($src);
                $generated++;
                $batch_counter++;
                $this->streamProgress("REGENERATED: t_ + a_ → " . $pi['basename'] . " [" . ($idx + 1) . "/{$total}]", 'ok');

                // Throttle: let the server breathe after every batch
                if ($batch_counter >= $batch_size) {
                    $this->streamProgress("Cooling down (1s pause)...", 'info');
                    flush();
                    sleep(1);
                    $batch_counter = 0;
                }
            }

            // Compute checksum and update DB
            $checksum = hash_file('sha256', $file);
            $db_sq  = file_exists($sq_abs)  ? $sq_rel  : null;
            $db_asp = file_exists($asp_abs) ? $asp_rel : null;
            $update->execute([$db_sq, $db_asp, $checksum, $img['id']]);

            flush();
        }

        return compact('generated', 'skipped', 'errors');
    }

    // =================================================================
    // MEDIA ASSET RESTORATION
    // =================================================================

    /**
     * Restores media library assets from a flat directory.
     *
     * @return array{restored: int, in_place: int, missing: int}
     */
    public function restoreMediaAssets(?string $flatDir = null): array {
        $restored = 0;
        $in_place = 0;
        $missing  = 0;

        try {
            $assets = $this->pdo->query("SELECT id, asset_path FROM snap_assets ORDER BY id")->fetchAll();
        } catch (PDOException $e) {
            $this->streamProgress("snap_assets table not found — skipping media assets.", 'warn');
            return compact('restored', 'in_place', 'missing');
        }

        foreach ($assets as $asset) {
            $abs_path = $this->baseDir . '/' . $asset['asset_path'];

            if (file_exists($abs_path)) {
                $in_place++;
                continue;
            }

            if ($flatDir) {
                $flat_file = rtrim($flatDir, '/') . '/' . basename($asset['asset_path']);
                if (file_exists($flat_file)) {
                    $target_dir = dirname($abs_path);
                    if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
                    if (copy($flat_file, $abs_path)) {
                        $restored++;
                        $this->streamProgress("RESTORED ASSET: " . basename($asset['asset_path']), 'ok');
                        continue;
                    }
                }
            }

            $missing++;
            $this->streamProgress("MISSING ASSET: " . htmlspecialchars($asset['asset_path']), 'warn');
        }

        // Backfill checksums for restored assets
        if ($restored > 0) {
            try {
                $assets = $this->pdo->query("SELECT id, asset_path FROM snap_assets WHERE asset_checksum IS NULL")->fetchAll();
                $upd = $this->pdo->prepare("UPDATE snap_assets SET asset_checksum = ? WHERE id = ?");
                foreach ($assets as $a) {
                    if (file_exists($this->baseDir . '/' . $a['asset_path'])) {
                        $upd->execute([hash_file('sha256', $this->baseDir . '/' . $a['asset_path']), $a['id']]);
                    }
                }
            } catch (PDOException $e) {
                // asset_checksum column may not exist on old schemas
            }
        }

        return compact('restored', 'in_place', 'missing');
    }

    // =================================================================
    // BRANDING RESTORATION
    // =================================================================

    /**
     * Checks and restores branding assets (logo, favicon, site logo).
     *
     * @return array{restored: int, in_place: int, missing: int}
     */
    public function restoreBranding(?string $flatDir = null): array {
        $restored = 0;
        $in_place = 0;
        $missing  = 0;

        $keys = ['header_logo_url', 'favicon_url', 'site_logo'];

        foreach ($keys as $key) {
            $val = $this->pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = " . $this->pdo->quote($key))->fetchColumn();
            if (empty($val)) continue;

            $rel_path = ltrim($val, '/');
            $abs_path = $this->baseDir . '/' . $rel_path;

            if (file_exists($abs_path)) {
                $in_place++;
                continue;
            }

            if ($flatDir) {
                $flat_file = rtrim($flatDir, '/') . '/' . basename($rel_path);
                if (file_exists($flat_file)) {
                    $target_dir = dirname($abs_path);
                    if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
                    if (copy($flat_file, $abs_path)) {
                        $restored++;
                        $this->streamProgress("RESTORED BRANDING: {$key} → " . basename($rel_path), 'ok');
                        continue;
                    }
                }
            }

            $missing++;
            $this->streamProgress("MISSING BRANDING: {$key} → " . htmlspecialchars($rel_path), 'warn');
        }

        return compact('restored', 'in_place', 'missing');
    }

    // =================================================================
    // DIRECTORY STRUCTURE
    // =================================================================

    /**
     * Ensures the essential upload directory structure exists.
     */
    public function ensureDirectories(): void {
        $dirs = [
            $this->baseDir . '/img_uploads',
            $this->baseDir . '/assets/img',
            $this->baseDir . '/media_assets',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        // Block PHP execution in uploads
        $htaccess = $this->baseDir . '/img_uploads/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "<FilesMatch \"\\.php$\">\n    Order Deny,Allow\n    Deny from all\n</FilesMatch>\n");
        }
    }

    // =================================================================
    // RECOVERY KIT IMPORT (eats our own dog food)
    // =================================================================

    /**
     * Imports a SnapSmack Recovery Kit archive.
     * Reads manifest.json, imports SQL, restores all files to their
     * documented locations. Full round-trip with the export engine.
     *
     * @param string $archivePath  Path to .tar.gz file OR extracted directory
     * @return array{sql_imported: int, files_restored: int, checksum_ok: int, checksum_fail: int, missing: int, errors: string[]}
     */
    public function importRecoveryKit(string $archivePath): array {
        $result = [
            'sql_imported'   => 0,
            'files_restored' => 0,
            'checksum_ok'    => 0,
            'checksum_fail'  => 0,
            'missing'        => 0,
            'errors'         => [],
        ];

        // If it's a .tar.gz, extract it first
        $extractDir = $archivePath;
        $needsCleanup = false;

        if (is_file($archivePath)) {
            $extractDir = sys_get_temp_dir() . '/snapsmack_recovery_' . time();
            if (!is_dir($extractDir)) {
                mkdir($extractDir, 0755, true);
            }

            try {
                $phar = new PharData($archivePath);
                $phar->extractTo($extractDir, null, true);
                $needsCleanup = true;
                $this->streamProgress("Archive extracted to temp directory.", 'ok');
            } catch (Exception $e) {
                $result['errors'][] = 'Failed to extract archive: ' . $e->getMessage();
                return $result;
            }
        }

        // Find the manifest — it may be in a subdirectory (the archive prefix)
        $manifestPath = $this->findFileInTree($extractDir, 'manifest.json');
        if (!$manifestPath) {
            $result['errors'][] = 'manifest.json not found in archive.';
            return $result;
        }

        $kitRoot = dirname($manifestPath);
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest || !isset($manifest['export_type'])) {
            $result['errors'][] = 'Invalid or corrupt manifest.json.';
            return $result;
        }

        $this->streamProgress("Recovery Kit: {$manifest['site_name']} — exported {$manifest['export_date']}", 'info');
        $this->streamProgress("SnapSmack version: {$manifest['snapsmack_version']}", 'info');

        // --- 1. IMPORT SQL DUMP ---
        $sqlPath = $kitRoot . '/database.sql';
        if (file_exists($sqlPath)) {
            $this->streamProgress("Importing database...", 'info');
            $sqlResult = $this->importSqlDump($sqlPath);
            $result['sql_imported'] = $sqlResult['imported'];
            if (!empty($sqlResult['errors'])) {
                foreach ($sqlResult['errors'] as $err) {
                    $result['errors'][] = "SQL: {$err}";
                }
            }
            $this->streamProgress("SQL: {$sqlResult['imported']} statements imported.", 'ok');
        } else {
            $this->streamProgress("No database.sql found — skipping SQL import.", 'warn');
        }

        // --- 2. RESTORE FILES FROM MANIFEST ---
        $files = $manifest['files'] ?? [];
        foreach ($files as $manifestKey => $meta) {
            // Skip the SQL dump — already handled
            if ($manifestKey === 'database.sql') continue;

            $restoreTo = $meta['restores_to'] ?? null;
            if (!$restoreTo) continue;

            $sourcePath = $kitRoot . '/' . $manifestKey;
            $targetPath = $this->baseDir . '/' . $restoreTo;

            if (!file_exists($sourcePath)) {
                $result['missing']++;
                $this->streamProgress("MISSING IN KIT: {$manifestKey}", 'warn');
                continue;
            }

            // Create target directory
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                if (!@mkdir($targetDir, 0755, true)) {
                    $result['errors'][] = "Cannot create directory: {$targetDir}";
                    continue;
                }
            }

            // Copy file
            if (copy($sourcePath, $targetPath)) {
                $result['files_restored']++;

                // Verify checksum
                $expectedChecksum = $meta['checksum'] ?? '';
                if (str_starts_with($expectedChecksum, 'sha256:')) {
                    $expected = substr($expectedChecksum, 7);
                    $actual = hash_file('sha256', $targetPath);
                    if ($actual === $expected) {
                        $result['checksum_ok']++;
                    } else {
                        $result['checksum_fail']++;
                        $this->streamProgress("CHECKSUM MISMATCH: {$restoreTo}", 'warn');
                    }
                }

                $this->streamProgress("RESTORED: {$restoreTo}", 'ok');
            } else {
                $result['errors'][] = "Failed to copy: {$manifestKey} → {$restoreTo}";
            }
        }

        // --- 3. ENSURE DIRECTORIES ---
        $this->ensureDirectories();

        // --- 4. CLEANUP ---
        if ($needsCleanup) {
            $this->recursiveDelete($extractDir);
        }

        $this->streamProgress("Recovery complete. Files: {$result['files_restored']} restored, "
            . "{$result['checksum_ok']} verified, {$result['checksum_fail']} checksum failures, "
            . "{$result['missing']} missing.", 'info');

        return $result;
    }

    /**
     * Validates a recovery kit manifest. Returns array of issues (empty = valid).
     */
    public function validateManifest(array $manifest): array {
        $issues = [];

        if (empty($manifest['export_type'])) {
            $issues[] = 'Missing export_type field.';
        } elseif ($manifest['export_type'] !== 'recovery-kit') {
            $issues[] = "Unexpected export_type: {$manifest['export_type']}";
        }

        if (empty($manifest['export_date'])) {
            $issues[] = 'Missing export_date field.';
        }

        if (empty($manifest['files'])) {
            $issues[] = 'No files listed in manifest.';
        }

        if (!isset($manifest['files']['database.sql'])) {
            $issues[] = 'No database.sql in file manifest (SQL dump missing).';
        }

        return $issues;
    }

    /**
     * Searches a directory tree for a specific filename.
     */
    private function findFileInTree(string $dir, string $filename): ?string {
        // Check current directory first
        if (file_exists($dir . '/' . $filename)) {
            return $dir . '/' . $filename;
        }

        // Check one level of subdirectories (archive prefix creates one level)
        $entries = @scandir($dir);
        if ($entries) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $path = $dir . '/' . $entry;
                if (is_dir($path) && file_exists($path . '/' . $filename)) {
                    return $path . '/' . $filename;
                }
            }
        }

        return null;
    }

    /**
     * Recursively deletes a directory and its contents.
     */
    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($dir);
    }

    // =================================================================
    // HELPERS
    // =================================================================

    private function loadImage(string $path, string $mime) {
        return match($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };
    }

    private function prepAlpha($resource, string $mime): void {
        if ($mime !== 'image/jpeg') {
            imagealphablending($resource, false);
            imagesavealpha($resource, true);
        }
    }

    private function saveImage($resource, string $path, string $mime): void {
        match($mime) {
            'image/jpeg' => imagejpeg($resource, $path, 82),
            'image/png'  => imagepng($resource, $path, 8),
            'image/webp' => imagewebp($resource, $path, 78),
            default      => imagejpeg($resource, $path, 82),
        };
    }

    /**
     * Outputs a progress message with HTML formatting and flushes the buffer.
     */
    public function streamProgress(string $message, string $status = 'info'): void {
        $class = match($status) {
            'ok'    => 'success',
            'warn'  => 'warn',
            'error' => 'error',
            default => 'info',
        };
        echo "<span class='{$class}'>" . strtoupper($status) . ":</span> {$message}<br>";
        flush();
    }
}
