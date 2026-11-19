<?php
/**
 * SNAPSMACK - Recovery Engine
 *
 * Handles site restoration from a SQL dump and image files. Used by the
 * installer's recovery mode. Can relocate images from a flat recovery
 * directory to the correct img_uploads/YYYY/MM/ structure, regenerate
 * missing thumbnails, compute checksums, and restore branding assets.
 *
 * No external dependencies — vanilla PHP 8.3+ with GD.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


class SnapSmackRecovery {

    private PDO $pdo;
    private string $baseDir;

    public function __construct(PDO $pdo, string $baseDir) {
        $this->pdo = $pdo;
        $this->baseDir = rtrim($baseDir, '/');
    }

    /**
     * SS-1433. Every second factor in the restored database is revoked.
     *
     * A Recovery Kit's dump carries snap_users verbatim, so it carries TOTP
     * secrets, the TOTP recovery codes and the account recovery-code hash. Two
     * consequences, and the second is the one the ticket is really about:
     *
     *   - the kit is a reusable second factor. Anyone holding a year-old kit
     *     holds the TOTP seed that still generates valid codes today.
     *   - restoring an OLDER kit silently REVIVES credentials revoked since it
     *     was taken. The owner rotated a recovery code after a scare; the kit
     *     predates that; the restore puts the old one back and nothing says so.
     *
     * So this runs after every import, unconditionally, and it does not look
     * for a redaction marker. Marker-clearing (clearRedactedCredentials) only
     * helps kits made by a build that redacts. EVERY KIT ALREADY IN EXISTENCE
     * carries the real values, and those kits are the whole problem: an export
     * change cannot reach a file somebody made last March.
     *
     * PASSWORD HASHES ARE DELIBERATELY KEPT. The export engine's own note says
     * it and it is right: a kit that restores a site nobody can log into is not
     * a backup. force_password_change is set instead, so the owner gets in and
     * is then made to choose a new one.
     *
     * totp_enabled IS CLEARED WITH THE SECRET, and that is not optional. A row
     * left with totp_enabled = 1 and no secret is an account demanding a second
     * factor that nothing can satisfy -- the owner locked out of their own site
     * by a security fix. Clearing both is what "2FA re-enrolment" means.
     *
     * @return array<string,int> column => rows affected, for the report
     */
    private function revokeRestoredSecondFactors(): array {
        $revoked = [];

        // AN EXPLICIT LIST HERE, AND THE OPPOSITE OF SS-1412'S LESSON ON
        // PURPOSE. There, a hand-written list of node key columns WAS the
        // defect, because every secret-shaped column on that table had to go
        // and a list goes stale. On snap_users one credential column must
        // SURVIVE -- password_hash -- so a generic sweep of secret-shaped
        // columns would empty the one thing a restore needs. The names are
        // therefore deliberate, and the test pins that password_hash is not
        // among them.
        $clear = [
            'totp_secret'        => null,
            'totp_recovery_json' => null,
            'recovery_code_hash' => null,
        ];
        foreach ($clear as $col => $to) {
            try {
                $st = $this->pdo->prepare(
                    "UPDATE snap_users SET {$col} = ? WHERE {$col} IS NOT NULL"
                );
                $st->execute([$to]);
                if ($st->rowCount() > 0) $revoked['snap_users.' . $col] = $st->rowCount();
            } catch (PDOException $e) {
                // Column absent on this install's schema -- nothing to revoke.
            }
        }

        foreach (['totp_enabled' => 0, 'force_password_change' => 1] as $col => $to) {
            try {
                $st = $this->pdo->prepare("UPDATE snap_users SET {$col} = ?");
                $st->execute([$to]);
                if ($st->rowCount() > 0) $revoked['snap_users.' . $col] = $st->rowCount();
            } catch (PDOException $e) {
                // Column absent -- nothing to set.
            }
        }

        return $revoked;
    }

    // =================================================================
    // PRE-RESTORE SNAPSHOT (SS-1425)
    // =================================================================

    /**
     * Take a snapshot of the live database before anything is overwritten.
     *
     * WHY THERE IS NO TRANSACTION HERE, so nobody adds one later thinking it
     * was an oversight. The obvious fix for "a failed restore leaves a mixed
     * old-and-new database" is to wrap importSqlDump() in beginTransaction()
     * and rollBack(). It would roll back NOTHING. The kit's dump carries DDL
     * -- core/export-engine.php:458 writes DROP TABLE IF EXISTS and the output
     * of SHOW CREATE TABLE for every table -- and in MySQL and MariaDB a DDL
     * statement causes an IMPLICIT COMMIT. Every DROP TABLE in that dump
     * commits the transaction out from under you, so a failure half way
     * through leaves exactly the mixed state the ticket describes, while the
     * code reads as if it were protected. That is worse than the honest
     * position, which is: there is no rollback, so take a copy first.
     *
     * A staged swap -- import into a temp schema and rename the tables in -- is
     * the genuinely atomic option. It is a much larger change, it is not this
     * ticket, and it is filed as a WANTED follow-up.
     *
     * FAIL CLOSED. The caller must refuse the restore if this returns an
     * error. A restore with no way back is the fault being repaired, so "could
     * not take a copy" has to stop the operation, not sit as a warning above
     * it.
     *
     * @return array{file: ?string, bytes: int, pruned: int, error: ?string}
     */
    public function snapshotBeforeRestore(): array {
        $out = ['file' => null, 'bytes' => 0, 'pruned' => 0, 'error' => null];

        require_once __DIR__ . '/db-dump.php';
        if (!function_exists('snap_dump_database')) {
            $out['error'] = 'The database dumper is unavailable, so no pre-restore '
                . 'copy can be taken. Refusing to overwrite this site with no way back.';
            return $out;
        }

        // The same directory the updater's pre-update dumps go to, so there is
        // one place an owner looks for "the copy from before", and one deny
        // rule covering both.
        $dir = defined('UPDATER_BACKUP_DIR')
            ? UPDATER_BACKUP_DIR
            : $this->baseDir . '/backups';

        $secure = snap_backup_dir_secure($dir);
        if ($secure === null) {
            $out['error'] = 'Could not create or write to ' . $dir . ', so no '
                . 'pre-restore copy can be taken. Refusing to overwrite this site '
                . 'with no way back.';
            return $out;
        }

        // .sql ON PURPOSE. The root .htaccess install.php:1723 writes denies
        // *.sql by extension, and that is what has been protecting the
        // updater's unredacted pre-update dumps all along. A snapshot named
        // anything else would sit in the webroot uncovered.
        $version = defined('SNAPSMACK_VERSION_SHORT') ? (string)SNAPSMACK_VERSION_SHORT : 'unknown';
        $safe    = preg_replace('/[^a-zA-Z0-9._-]/', '', $version);
        $file    = $secure . '/pre-restore_' . $safe . '_' . date('Y-m-d_H-i-s') . '.sql';

        $error = '';
        $bytes = snap_dump_database($this->pdo, $file, 'pre-restore DB snapshot', $error);
        if ($bytes === false) {
            $out['error'] = 'The pre-restore copy of this database could not be '
                . 'written: ' . $error . '. Refusing to overwrite this site with no '
                . 'way back.';
            return $out;
        }

        $out['file']   = $file;
        $out['bytes']  = $bytes;
        $out['pruned'] = snap_prune_dumps($secure, 'pre-restore_', 3);
        return $out;
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

        // SS-1417: a dump written by an older build carries the public literal
        // __REDACTED__ in the fleet key columns, and importSqlDump() execs the
        // dump verbatim, so the literal lands in an active node row. Fixing the
        // export side does nothing for dumps already written to disk, and those
        // are exactly the ones a recovery restores. Clear them here.
        $cleared = $this->clearRedactedCredentials();

        return ['imported' => $imported, 'errors' => $errors, 'cleared_placeholders' => $cleared];
    }

    /**
     * Replace restored redaction placeholders with empty values.
     *
     * Empty is the honest state: the credential was stripped at export and has
     * not been re-provisioned yet. The literal is NOT honest — it reads as a
     * configured key and, before SS-1417 hardened the gates, authenticated as
     * one. Returns the number of rows cleared, per table, for the restore log.
     *
     * Each statement is guarded: a partially migrated site may not have every
     * column, and a recovery must not abort because one is absent.
     */
    private function clearRedactedCredentials(): array {
        // Upper-cased here because the statements below compare UPPER(col)
        // against it. Writing the literal in upper case would work today
        // and break silently the first time somebody re-cases it.
        $marker  = strtoupper('__REDACTED__');
        $cleared = [];

        foreach (['api_key_local', 'api_key_remote', 'api_key_backup'] as $col) {
            try {
                // UPPER() on both sides: SS-1424. A dump can carry any
                // casing of the marker, and SQLite compares case-sensitively
                // where MariaDB does not, so an exact match would clear the
                // variants on a live site and leave them on a restore run
                // anywhere else. Normalise and the two behave alike.
                $st = $this->pdo->prepare(
                    "UPDATE snap_multisite_nodes SET {$col} = '' WHERE UPPER({$col}) = ?"
                );
                $st->execute([$marker]);
                if ($st->rowCount() > 0) $cleared['snap_multisite_nodes.' . $col] = $st->rowCount();
            } catch (PDOException $e) {
                // Column or table absent on this install — nothing to clear.
            }
        }

        try {
            $st = $this->pdo->prepare(
                "UPDATE snap_settings SET setting_val = '' WHERE UPPER(setting_val) = ?"
            );
            $st->execute([$marker]);
            if ($st->rowCount() > 0) $cleared['snap_settings.setting_val'] = $st->rowCount();
        } catch (PDOException $e) {
            // Table absent — nothing to clear.
        }

        return $cleared;
    }

    /**
     * Is this database-supplied relative path safe to write inside baseDir?
     *
     * CODEX'S SS-1426/1431 FAIL, AND THE FAULT IS MINE. restoreImages(),
     * restoreMediaAssets() and restoreBranding() have always joined
     * baseDir . '/' . <value from the database> and copied, with no
     * containment check. That was survivable while their ONLY caller was
     * install.php, where the rows come from a fresh install.
     *
     * I wired all three into the kit-import path. The rows then come from the
     * KIT'S OWN SQL DUMP, which is attacker-controlled. Codex proved it: a
     * snap_images.img_file of "../escaped.php" plus a matching file beside the
     * manifest writes a PHP file OUTSIDE the site root and reports
     * restored: 1 with no errors. I reproduced it before changing anything.
     *
     * I took a helper that was safe in its context and made it unsafe by
     * handing it hostile input -- and I did it four lines from the manifest
     * phase's own SECAUDIT 047 containment check, which I had read and quoted
     * while studying that code. Seeing a guard is not the same as noticing it
     * is missing next door.
     *
     * THE RULES ARE THE MANIFEST PHASE'S, deliberately, rather than a second
     * opinion about what is dangerous: no empty value, no NUL, no "..", no
     * absolute or drive-lettered path, no dotfile, no executable extension in
     * ANY dot-separated token, and the resolved parent directory must sit
     * under baseDir -- which is what catches a symlink the string tests miss.
     *
     * @param string $rel the stored relative path
     * @param string $why set to a reason when the answer is false
     */
    private function safeRelativeTarget(string $rel, string &$why = ''): bool {
        $why = '';
        $norm = str_replace('\\', '/', trim($rel));
        if ($norm === '') { $why = 'empty path'; return false; }
        if (strpos($norm, "\0") !== false) { $why = 'NUL byte in path'; return false; }
        if (strpos($norm, '..') !== false) { $why = 'parent-directory traversal'; return false; }
        if ($norm[0] === '/' || preg_match('#^[a-zA-Z]:#', $norm)) {
            $why = 'absolute path'; return false;
        }

        $baseName = strtolower(basename($norm));
        if ($baseName === '' || $baseName[0] === '.') { $why = 'dotfile'; return false; }

        // EVERY dot-separated token, not just the final extension: Apache's
        // AddHandler matches ".php" anywhere in the name, so "shell.php.jpg"
        // would otherwise execute. Same list as the manifest phase.
        $blockedExt = ['php','php3','php4','php5','php7','php8','phtml','pht',
                       'phar','phps','cgi','pl','asp','aspx','jsp','sh','htaccess'];
        if (array_intersect(explode('.', $baseName), $blockedExt)) {
            $why = 'executable file extension'; return false;
        }

        // Defence in depth: the resolved parent must stay under baseDir. Only
        // checked when the parent already exists -- a path that passes the
        // string tests and whose parent is yet to be created cannot escape,
        // because ".." is already refused above.
        $baseReal   = realpath($this->baseDir);
        $parentReal = realpath(dirname($this->baseDir . '/' . $norm));
        if ($baseReal !== false && $parentReal !== false) {
            $needle = rtrim($baseReal, '/\\') . DIRECTORY_SEPARATOR;
            if (strncmp($parentReal . DIRECTORY_SEPARATOR, $needle, strlen($needle)) !== 0) {
                $why = 'resolves outside the site directory';
                return false;
            }
        }
        return true;
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
        $blocked  = 0;
        $errors   = [];

        $images = $this->pdo->query("SELECT id, img_file FROM snap_images ORDER BY id")->fetchAll();

        foreach ($images as $img) {
            $rel_path = $img['img_file'];

            // SS-1426/1431 security repair. These rows come from the kit's own
            // SQL dump on the admin import path, so the value is attacker
            // controlled. Checked BEFORE any join, mkdir, file_exists or copy.
            $why = '';
            if (!$this->safeRelativeTarget((string)$rel_path, $why)) {
                $blocked++;
                $errors[] = "Blocked unsafe image path ({$why}): " . $rel_path;
                $this->streamProgress('BLOCKED unsafe image path (' . $why . '): '
                    . htmlspecialchars((string)$rel_path), 'error');
                continue;
            }

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

        return compact('restored', 'in_place', 'missing', 'blocked', 'errors');
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
        $blocked  = 0;

        try {
            $assets = $this->pdo->query("SELECT id, asset_path FROM snap_assets ORDER BY id")->fetchAll();
        } catch (PDOException $e) {
            $this->streamProgress("snap_assets table not found — skipping media assets.", 'warn');
            return compact('restored', 'in_place', 'missing', 'blocked');
        }

        foreach ($assets as $asset) {
            // SS-1426/1431: same attacker-controlled source as the images.
            $why = '';
            if (!$this->safeRelativeTarget((string)$asset['asset_path'], $why)) {
                $blocked++;
                $this->streamProgress('BLOCKED unsafe asset path (' . $why . '): '
                    . htmlspecialchars((string)$asset['asset_path']), 'error');
                continue;
            }
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

        return compact('restored', 'in_place', 'missing', 'blocked');
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
        $blocked  = 0;

        $keys = ['header_logo_url', 'favicon_url', 'site_logo'];

        foreach ($keys as $key) {
            $val = $this->pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = " . $this->pdo->quote($key))->fetchColumn();
            if (empty($val)) continue;

            $rel_path = ltrim($val, '/');

            // SS-1426/1431: a restored SETTING value, equally kit-controlled.
            $why = '';
            if (!$this->safeRelativeTarget($rel_path, $why)) {
                $blocked++;
                $this->streamProgress('BLOCKED unsafe branding path for ' . $key
                    . ' (' . $why . '): ' . htmlspecialchars($rel_path), 'error');
                continue;
            }

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

        return compact('restored', 'in_place', 'missing', 'blocked');
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
     * @return array{sql_imported: int, files_restored: int, checksum_ok: int, checksum_fail: int, missing: int, not_bundled: int, errors: string[]}
     */
    public function importRecoveryKit(string $archivePath): array {
        $result = [
            'sql_imported'   => 0,
            'files_restored' => 0,
            'checksum_ok'    => 0,
            'checksum_fail'  => 0,
            'missing'        => 0,
            'not_bundled'    => 0,
            'errors'         => [],
            'warnings'       => [],
            'schema_sync'    => null,
            'snapshot'       => null,
            'media'          => null,   // SS-1426/1431, filled at step 3c
            'thumbs_generated' => 0,
            'second_factors_revoked' => [],  // SS-1433
            'remaining'      => [],     // SS-1427, what is still outstanding
            'complete'       => false,  // SS-1427, refused until step 5 says otherwise
            // REFUSED UNTIL PROVEN OTHERWISE. Every `return $result` above the
            // SQL import happens before a single destructive statement, so
            // every one of them is a refusal: this site is unchanged. Starting
            // at true and clearing it at the point the overwriting begins means
            // a NEW early return is a refusal without anybody remembering to
            // say so, which is the opposite of how the SS-1430 fault worked.
            'refused'        => true,
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

        // --- 0. PREFLIGHT, BEFORE ANYTHING IS OVERWRITTEN ---
        // SS-1428 + SS-1429. Until now the two lines above were the whole of
        // it: the kit's version and site were PRINTED and never checked, and
        // validateManifest() had no caller anywhere in the codebase.
        $here = [];
        try {
            $here = $this->pdo->query(
                "SELECT setting_key, setting_val FROM snap_settings
                  WHERE setting_key IN ('installed_version','site_mode','site_url','site_uuid')"
            )->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (PDOException $e) {
            // A site with no readable settings cannot be compared against. Say
            // so rather than treating unknown as compatible.
            $result['errors'][] = 'Could not read this site settings to check kit '
                . 'compatibility: ' . $e->getMessage();
            if ($needsCleanup) $this->recursiveDelete($extractDir);
            return $result;
        }

        $pre = $this->preflightKit($manifest, $here);
        foreach ($pre['warnings'] as $w) {
            $this->streamProgress($w, 'warn');
            $result['warnings'][] = $w;
        }
        if (!empty($pre['refusals'])) {
            foreach ($pre['refusals'] as $r) {
                $result['errors'][] = $r;
                $this->streamProgress($r, 'error');
            }
            $this->streamProgress('NOTHING WAS CHANGED. The kit was not applied.', 'error');
            if ($needsCleanup) $this->recursiveDelete($extractDir);
            return $result;
        }

        // --- 0b. SNAPSHOT, BEFORE ANY DESTRUCTIVE STATEMENT (SS-1425) ---
        //
        // This is the last point at which this site is still itself. The next
        // step runs DROP TABLE. See snapshotBeforeRestore() for why there is
        // no transaction wrapping that, and why this fails closed.
        $this->streamProgress('Taking a copy of this database before anything is '
            . 'overwritten...', 'info');
        $snapshot = $this->snapshotBeforeRestore();
        $result['snapshot'] = $snapshot;
        if ($snapshot['error'] !== null) {
            $result['errors'][] = $snapshot['error'];
            $this->streamProgress($snapshot['error'], 'error');
            $this->streamProgress('NOTHING WAS CHANGED. The kit was not applied.', 'error');
            if ($needsCleanup) $this->recursiveDelete($extractDir);
            return $result;
        }
        $kb = (int)round($snapshot['bytes'] / 1024);
        $this->streamProgress('Copy written: ' . $snapshot['file'] . ' (' . $kb
            . ' KB). If this restore goes wrong, that file is the way back.', 'ok');
        $result['warnings'][] = 'A copy of this database as it was before the restore '
            . 'is at ' . $snapshot['file'] . ' (' . $kb . ' KB). There is no automatic '
            . 'rollback: restoring it is a deliberate act.';

        // FROM HERE ON, THIS SITE IS BEING CHANGED. Anything that goes wrong
        // below is a half-done restore, not a refusal, and the owner must be
        // told the difference: the snapshot above is their way back.
        $result['refused'] = false;

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

            // Manifest-only entries: files inventoried but not bundled in the
            // archive (media, branding, skins). These need to be restored via
            // FTP, Cloud Backup, or manual upload — not from this kit.
            if (isset($meta['bundled']) && $meta['bundled'] === false) {
                $result['not_bundled']++;
                continue;
            }

            // --- SECAUDIT 047: constrain the restore target ---
            // A recovery kit's manifest is fully attacker-controllable, and the
            // only file a legitimate kit bundles is database.sql (handled above).
            // Without this guard a crafted kit could set restores_to to "pwn.php"
            // (or "../../x") and drop executable code into the web root => RCE.
            // Block traversal, absolute/drive paths, NUL, executable extensions,
            // and dotfiles; then confine the resolved parent under baseDir.
            $relTarget = str_replace('\\', '/', (string)$restoreTo);
            $relSource = str_replace('\\', '/', (string)$manifestKey);
            $baseName  = strtolower(basename($relTarget));
            $blockedExt = ['php','php3','php4','php5','php7','php8','phtml','pht',
                           'phar','phps','cgi','pl','asp','aspx','jsp','sh','htaccess'];
            // Inspect EVERY dot-separated token, not just the final extension:
            // Apache's AddHandler matches ".php" anywhere in the name, so a
            // double extension like "shell.php.jpg" would otherwise execute.
            $nameTokens = explode('.', $baseName);
            if (str_starts_with($relTarget, 'skins/') || str_starts_with($relSource, 'skins/')) {
                $result['errors'][] = "Rejected skin code in recovery archive: {$restoreTo}";
                $this->streamProgress("BLOCKED skin code restore: {$restoreTo}", 'warn');
                continue;
            }
            if ($relTarget === '' || $relSource === ''
                || strpos($relTarget, "\0") !== false || strpos($relSource, "\0") !== false
                || strpos($relTarget, '..') !== false || strpos($relSource, '..') !== false
                || $relTarget[0] === '/' || preg_match('#^[a-zA-Z]:#', $relTarget)
                || strpos($relSource, '/') === 0
                || array_intersect($nameTokens, $blockedExt)
                || $baseName === '' || $baseName[0] === '.') {
                $result['errors'][] = "Rejected unsafe restore target: {$restoreTo}";
                $this->streamProgress("BLOCKED unsafe restore target: {$restoreTo}", 'warn');
                continue;
            }

            $sourcePath = $kitRoot . '/' . $manifestKey;
            $targetPath = $this->baseDir . '/' . $restoreTo;

            // Defense-in-depth: the resolved target directory must stay under
            // baseDir (catches symlink tricks that the string checks miss).
            $baseReal = realpath($this->baseDir);
            $parentReal = realpath(dirname($targetPath));
            if ($baseReal !== false && $parentReal !== false) {
                $needle = rtrim($baseReal, '/\\') . DIRECTORY_SEPARATOR;
                if (strncmp($parentReal . DIRECTORY_SEPARATOR, $needle, strlen($needle)) !== 0) {
                    $result['errors'][] = "Restore target escapes base dir: {$restoreTo}";
                    $this->streamProgress("BLOCKED path escape: {$restoreTo}", 'warn');
                    continue;
                }
            }

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

        // --- 3b. BRING THE SCHEMA UP TO THIS BUILD ---
        // SS-1429, the other half. A kit from an older build carries an older
        // schema, and the preflight above deliberately ALLOWS that because it
        // is the ordinary recovery case: the dump is the data you still have.
        // What made it dangerous was that nothing afterwards closed the gap,
        // so the site came back running this build's code over last month's
        // tables -- missing columns, missing indexes, and every guarded reader
        // in core/ quietly degrading because that is what they are built to
        // do. The restore looked like it worked.
        //
        // snap_schema_sync() is the same function the updater runs at
        // core/updater.php:1618, which is the one place this has always been
        // done properly. Borrowed rather than reimplemented.
        //
        // It runs AFTER the SQL and BEFORE the caller is told this succeeded,
        // and a failure here is an error on the result rather than an
        // exception: the data is already in: telling the owner the restore
        // exploded would be less true than telling them the schema still
        // needs a pass.
        try {
            require_once __DIR__ . '/schema-sync.php';
            if (function_exists('snap_schema_sync')) {
                $this->streamProgress('Synchronising the database schema to this build...', 'info');
                $sync = snap_schema_sync($this->pdo);
                $result['schema_sync'] = $sync;
                $added = count($sync['columns_added'] ?? []);
                $syncErrors = $sync['errors'] ?? [];
                $this->streamProgress("Schema sync: {$added} change(s) applied, "
                    . count($syncErrors) . ' error(s).', $syncErrors ? 'warn' : 'ok');
                foreach ($syncErrors as $se) {
                    $result['errors'][] = "Schema sync: {$se}";
                }
            } else {
                $result['errors'][] = 'Schema sync unavailable: the restored data may '
                    . 'be on an older schema than this build expects.';
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'Schema sync failed after the data was restored: '
                . $e->getMessage() . '. The data is in; the schema still needs a pass.';
        }

        // --- 3b-ii. REVOKE EVERY RESTORED SECOND FACTOR (SS-1433) ---
        //
        // After the schema sync, because install.php:280 lists totp_secret,
        // totp_recovery_json, recovery_code_hash and force_password_change
        // among the columns the sync adds: on a kit from a build that predates
        // them the UPDATEs would find no columns at all and silently revoke
        // nothing. Before the media phase, because this is about who can log
        // in and that matters more than thumbnails.
        try {
            $revoked = $this->revokeRestoredSecondFactors();
            $result['second_factors_revoked'] = $revoked;
            if ($revoked !== []) {
                $this->streamProgress('Second factors revoked: every account must set a new '
                    . 'password and re-enrol 2FA. A kit carries the old ones, and an older '
                    . 'kit carries ones you had already replaced.', 'warn');
                $result['warnings'][] = 'Every account on this site must set a new password '
                    . 'and re-enrol two-factor authentication. The kit carried the second '
                    . 'factors as they were when it was made, so they have been revoked '
                    . 'rather than restored. Passwords still work for the first sign-in.';
            }
        } catch (Throwable $e) {
            // Fail LOUD: a restore that could not revoke them has put live
            // second factors back, and the owner must know that specifically.
            $result['errors'][] = 'Could not revoke the second factors this kit restored: '
                . $e->getMessage() . '. Treat every TOTP secret and recovery code on this '
                . 'site as compromised and reset them by hand.';
        }

        // --- 3c. THE MEDIA, WHICH THE KIT PATH NEVER RECONCILED (SS-1426 + SS-1431) ---
        //
        // THE DIAGNOSIS, CORRECTED. I had this down as "three helpers with no
        // callers". They have callers: install.php:3146-3179 runs
        // ensureDirectories, restoreImages, regenerateAndChecksum,
        // restoreMediaAssets and restoreBranding as five numbered phases, and
        // that path works. What was missing is that the ADMIN kit-import path
        // never called any of it. A kit import restored the database and the
        // bundled files, synced the schema, and left every photograph,
        // thumbnail, asset and branding file unreconciled -- so snap_images
        // rows pointed at files that may not be on disk, and nothing counted
        // them. SS-1431 says it plainly: reuse the established installer path.
        //
        // So this is the installer's sequence, copied, not a second
        // implementation of it. The helpers are unchanged.
        //
        // ONE DELIBERATE DEVIATION FROM THE INSTALLER'S ORDER, and the reason
        // matters. The installer regenerates thumbnails at its phase 3, before
        // assets and branding. Here the whole media phase runs AFTER the
        // schema sync above, because regenerateAndChecksum() writes
        // img_thumb_square, img_thumb_aspect and img_checksum -- columns a kit
        // from an older build may not have until snap_schema_sync() adds them.
        // The installer never meets that case: its schema is this build's.
        // Running it before the sync would fail on exactly the kits this
        // ticket is about.
        //
        // THE FLAT DIRECTORY IS THE KIT ROOT, and what that does and does not
        // reach is worth stating. The helpers look for basename() in ONE
        // directory, so media sitting beside manifest.json is found and media
        // nested deeper is not. A kit that only INVENTORIES its media (the
        // ordinary case -- see not_bundled above) therefore restores nothing
        // here and reports an honest missing count instead, which is the
        // combined result SS-1426 asks for. Making the search recursive is a
        // change to the helpers and to the installer's behaviour with them,
        // and it is not this ticket.
        $this->streamProgress('Reconciling photographs, thumbnails, assets and '
            . 'branding against the restored database...', 'info');

        // EACH PHASE'S COUNTS FOLD IN AS IT FINISHES, not all four at the end.
        //
        // My first version did them at the end, inside one try. Writing the
        // test found what that costs: regenerateAndChecksum() reaches
        // imagecreatefromjpeg() through loadImage(), which is a FATAL Error on
        // a box without the GD extension rather than a handled failure. So on
        // such a box the photographs were copied to disk and then the throw
        // skipped the accounting, and the owner was told 0 files restored
        // while the files were sitting there. Reporting less than happened is
        // the same class of lie as reporting more.
        $media = ['images' => null, 'thumbs' => null, 'assets' => null, 'branding' => null];
        $phases = [
            'images'   => fn(): array => $this->restoreImages($kitRoot),
            'thumbs'   => fn(): array => $this->regenerateAndChecksum(),
            'assets'   => fn(): array => $this->restoreMediaAssets($kitRoot),
            'branding' => fn(): array => $this->restoreBranding($kitRoot),
        ];
        $labels = ['images' => 'Images', 'thumbs' => 'Thumbnails',
                   'assets' => 'Assets', 'branding' => 'Branding'];

        foreach ($phases as $name => $run) {
            try {
                $r = $run();
            } catch (Throwable $e) {
                // The data is already in, and so is whatever earlier phases
                // restored. Name the phase that failed instead of reporting
                // the whole restore as exploded.
                $result['errors'][] = $labels[$name] . ' could not be reconciled after '
                    . 'the data was restored: ' . $e->getMessage() . '. The database is '
                    . 'in, and so is anything listed as restored above; this part still '
                    . 'needs a pass.';
                $this->streamProgress($labels[$name] . ' FAILED: ' . $e->getMessage(), 'error');
                continue;
            }

            $media[$name] = $r;
            foreach (($r['errors'] ?? []) as $e) {
                $result['errors'][] = $labels[$name] . ": {$e}";
            }
            // THE COUNTS THE OWNER READS. files_restored and missing are what
            // the page already prints, so the media totals fold into them
            // rather than hiding in a key nothing renders.
            $result['files_restored'] += (int)($r['restored'] ?? 0);
            $result['missing']        += (int)($r['missing'] ?? 0);
            // SS-1426/1431: a BLOCKED row is not missing and not restored. It
            // is a kit trying to write outside the site, and it is an error
            // rather than a count, because the owner needs to know the kit
            // attempted it -- not merely that a file did not arrive.
            $blocked = (int)($r['blocked'] ?? 0);
            if ($blocked > 0) {
                $result['errors'][] = $labels[$name] . ': ' . $blocked . ' path(s) in this '
                    . 'kit pointed outside your site and were refused. A kit should never '
                    . 'do that; treat this kit as untrustworthy and check where it came '
                    . 'from.';
            }
            if ($name === 'thumbs') {
                $result['thumbs_generated'] = (int)($r['generated'] ?? 0);
            }
            $this->streamProgress($labels[$name] . ': ' . (int)($r['restored'] ?? 0)
                . ' restored, ' . (int)($r['in_place'] ?? 0) . ' already in place, '
                . (int)($r['missing'] ?? 0) . ' missing'
                . ($name === 'thumbs' ? ', ' . (int)($r['generated'] ?? 0) . ' generated' : ''),
                ((int)($r['missing'] ?? 0) > 0) ? 'warn' : 'ok');
        }
        $result['media'] = $media;

        // --- 4. CLEANUP ---
        if ($needsCleanup) {
            $this->recursiveDelete($extractDir);
        }

        $this->streamProgress("Recovery complete. Files: {$result['files_restored']} restored, "
            . "{$result['checksum_ok']} verified, {$result['checksum_fail']} checksum failures, "
            . "{$result['missing']} missing.", 'info');

        if ($result['not_bundled'] > 0) {
            $this->streamProgress("{$result['not_bundled']} files inventoried but not bundled — restore via FTP or Cloud Backup.", 'info');
        }

        // --- 5. IS THIS A COMPLETE RECOVERY? (SS-1427) ---
        //
        // "Success must require the full declared recovery result, and partial
        // completion must name what remains missing."
        //
        // Until now the page decided success as empty($errors), and a missing
        // photograph is not an error. So a kit that brought the database back
        // and left four hundred photographs behind rendered RECOVERY KIT
        // IMPORTED SUCCESSFULLY, which is the last member of the family this
        // week's tickets have been working through: a restore reporting
        // something it did not do.
        //
        // THE ENGINE DECIDES, for the same reason it decides 'refused': the
        // page cannot tell a complete recovery from a partial one by looking
        // at an error list, because a partial one has no errors in it.
        //
        // A PARTIAL RECOVERY IS NOT A FAILURE AND MUST NOT READ AS ONE. The
        // ordinary kit inventories its media rather than bundling it -- see
        // not_bundled above -- so on a rebuilt site the ordinary outcome is
        // "the database is back, the photographs are not here yet, fetch them
        // from your backup". Calling that "COMPLETED WITH ERRORS" would be a
        // lie in the other direction and would teach the owner to ignore the
        // word error. So this is its own outcome with its own words, and
        // 'remaining' says what is outstanding in the owner's terms rather
        // than leaving them to read four counters.
        $remaining = [];
        if ($result['missing'] > 0) {
            $remaining[] = $result['missing'] . ' file(s) the database expects are not on '
                . 'disk and were not in the kit. Photographs, media and branding come back '
                . 'from your own backup -- FTP, Cloud Backup or a manual upload -- not from '
                . 'this kit.';
        }
        if ($result['not_bundled'] > 0) {
            $remaining[] = $result['not_bundled'] . ' file(s) were listed in the kit but not '
                . 'carried inside it, which is normal for a kit: it inventories your media '
                . 'rather than copying it. Restore those the same way.';
        }
        if ($result['checksum_fail'] > 0) {
            $remaining[] = $result['checksum_fail'] . ' restored file(s) did not match the '
                . 'checksum the kit recorded. They are on disk and they are not the files '
                . 'the kit described; treat them as suspect.';
        }

        $result['remaining'] = $remaining;
        $result['complete']  = empty($result['errors']) && $remaining === [];

        if (!$result['complete'] && empty($result['errors'])) {
            $this->streamProgress('The database is restored. Some files are not here yet '
                . '-- see the list above.', 'warn');
        }

        return $result;
    }

    /**
     * Validates a recovery kit manifest. Returns array of issues (empty = valid).
     */
    /**
     * EVERYTHING THAT MUST BE TRUE BEFORE ANY DESTRUCTIVE WORK.
     *
     * SS-1428 and SS-1429. validateManifest() existed as a public method with
     * no caller anywhere -- a validator nobody ran -- and the import printed
     * the kit's version and site name without ever comparing them to this
     * install. A kit from a different site, a different mode, or a newer
     * build was applied exactly like the right one.
     *
     * WHAT IS REFUSED, and why only these:
     *
     *   A NEWER KIT. A kit from a later SnapSmack carries a later schema.
     *   Applying it to an older install leaves tables this build does not
     *   understand and columns its queries do not know about. The reverse --
     *   an older kit into this build -- is the ordinary recovery case and
     *   must keep working, which is what the schema sync below is for.
     *
     *   A MODE MISMATCH. A SMACKTALK kit into a photoblog install, or the
     *   reverse, produces a site whose content and whose renderer disagree
     *   about what it is. Nothing downstream reconciles that.
     *
     * WHAT IS REPORTED BUT NOT REFUSED, and this is the part worth reading:
     *
     *   A DIFFERENT site_uuid OR site_url. My first instinct was to refuse
     *   these and it would have broken the product's whole purpose. In a real
     *   disaster recovery the database is GONE: you install fresh, which
     *   mints a NEW site_uuid, and then import the kit. The kit's identity
     *   never matches the install's -- that is the normal path, not the
     *   exception, and refusing it would mean the recovery tool refuses to
     *   recover.
     *
     *   The thing that distinguishes the right target from the wrong one is
     *   the operator's intent, and that is captured a layer up: SS-1430 makes
     *   smack-disaster.php require this site's own address typed out before
     *   it calls in here. So a mismatch is surfaced loudly and the import
     *   proceeds. What happens to identity AFTER the restore -- a clone
     *   inheriting the original's uuid and joining the fleet as it -- is
     *   SS-1434 and is not settled here.
     *
     * @return array{refusals:string[],warnings:string[]}
     */
    public function preflightKit(array $manifest, array $settings): array {
        $refusals = $this->validateManifest($manifest);
        $warnings = [];

        // snap_version_compare() is LOADED, not hoped for. My first version
        // wrote `function_exists('snap_version_compare') && ...`, which means
        // that in any context where constants.php has not been included the
        // version guard silently passes everything -- a check that does
        // nothing when its dependency is missing, which is precisely the
        // SS-1428 fault this method exists to fix. If the comparator cannot
        // be loaded the kit is refused, because an unanswerable question
        // about schema compatibility is not a yes.
        if (!function_exists('snap_version_compare')) {
            @require_once __DIR__ . '/constants.php';
        }
        $kitVersion = trim((string)($manifest['snapsmack_version'] ?? ''));

        // WHICH VERSION THIS SITE IS, AND WHAT IF WE CANNOT TELL.
        //
        // Codex's SS-1428/1429 review FAIL. My first version read only
        // $settings['installed_version'] and then wrote
        // `$here !== '' && snap_version_compare(...)`, so on a site where
        // that row is absent or blank the whole version comparison was
        // skipped and a NEWER kit sailed through. He reproduced it: kit
        // 0.7.1000D, matching mode and identity, no installed_version, and
        // the result was {"refusals":[],"warnings":[]}.
        //
        // That contradicted the rule written four lines below it in the same
        // method -- an unanswerable compatibility question is not a yes. I
        // had guarded the case where the COMPARATOR is missing and left the
        // case where its INPUT is missing wide open: half a fail-closed
        // check, which reads as a whole one.
        //
        // WHICH SOURCE IS AUTHORITATIVE. Ruled by the PM, and it is the right
        // way round: the question here is whether the kit carries a schema
        // newer than the CODE that will query it, so the truth is the code's
        // own version, SNAPSMACK_VERSION_SHORT. The snap_settings row is a
        // record of what was installed and can lag -- mid-update, or after a
        // file-level restore that moved the code and not the database. My
        // first version read the row first and fell back to the constant,
        // which gets this backwards on exactly the sites that need the check
        // most.
        //
        // The stored value is still read, for one purpose: when the two
        // disagree, say so. That disagreement is itself worth knowing before
        // a destructive operation.
        $here = defined('SNAPSMACK_VERSION_SHORT')
            ? trim((string)SNAPSMACK_VERSION_SHORT) : '';
        $recorded = trim((string)($settings['installed_version'] ?? ''));
        if ($here === '') {
            $here = $recorded;          // no constant: the record is all there is
        } elseif ($recorded !== '' && $recorded !== $here) {
            $warnings[] = "This site runs SnapSmack {$here} but its settings still "
                . "record {$recorded}. The running code decides whether a kit's schema "
                . 'can be read, so the check below uses ' . $here . '.';
        }
        if ($here === '') {
            $refusals[] = 'This site does not report which SnapSmack version it '
                . 'runs, so the kit cannot be checked for schema compatibility. '
                . 'Refusing rather than guessing.';
        }

        if (!function_exists('snap_version_compare')) {
            $refusals[] = 'The version comparator is unavailable, so this kit cannot '
                . 'be checked against this build. Refusing rather than guessing.';
        } elseif ($kitVersion === '') {
            $refusals[] = 'The kit does not say which SnapSmack version produced it.';
        } elseif ($here !== '' && snap_version_compare($kitVersion, $here, '>')) {
            // $here !== '' is kept only so this branch is skipped when the
            // refusal above has already fired; it is no longer the thing
            // deciding whether the check runs at all.
            $refusals[] = "This kit was made by SnapSmack {$kitVersion} and this site runs "
                . "{$here}. A kit cannot be restored into an older build: it carries a "
                . 'schema this one does not understand. Update this site first.';
        }

        $kitMode = trim((string)($manifest['site_mode'] ?? ''));
        $hereMode = trim((string)($settings['site_mode'] ?? ''));
        if ($kitMode === '') {
            $refusals[] = 'The kit does not say which site mode it came from.';
        } elseif ($hereMode !== '' && $kitMode !== $hereMode) {
            $refusals[] = "This kit is from a '{$kitMode}' site and this install is "
                . "'{$hereMode}'. Restoring it would leave the content and the renderer "
                . 'disagreeing about what this site is.';
        }

        // Identity: reported, never refused. See the note above.
        $kitUuid  = trim((string)($manifest['site_uuid'] ?? ''));
        $hereUuid = trim((string)($settings['site_uuid'] ?? ''));
        if ($kitUuid !== '' && $hereUuid !== '' && $kitUuid !== $hereUuid) {
            $warnings[] = 'This kit came from a DIFFERENT installation. That is normal '
                . 'for a rebuild after a failure, and wrong if you meant to restore this '
                . "site's own kit. After the restore this site will carry the original "
                . "site's identity.";
        }
        $kitUrl  = rtrim(trim((string)($manifest['site_url'] ?? '')), '/');
        $hereUrl = rtrim(trim((string)($settings['site_url'] ?? '')), '/');
        if ($kitUrl !== '' && $hereUrl !== '' && $kitUrl !== $hereUrl) {
            $warnings[] = "This kit was exported from {$kitUrl} and this site is "
                . "{$hereUrl}.";
        }

        return ['refusals' => $refusals, 'warnings' => $warnings];
    }

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

        // SS-1428. core/export-engine.php writes every one of these into the
        // manifest of a recovery kit, unconditionally. A kit missing any of
        // them was not produced by this product, or was edited by hand, and
        // either way the compatibility checks in preflightKit() cannot be
        // made. Absent is refused rather than skipped: a guard that quietly
        // does nothing when its input is missing is the shape of SS-1428
        // itself.
        foreach (['snapsmack_version', 'site_mode', 'site_url', 'site_uuid'] as $field) {
            if (!array_key_exists($field, $manifest)) {
                $issues[] = "Manifest has no {$field}, so this kit cannot be checked "
                    . 'for compatibility with this site.';
            }
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
// ===== SNAPSMACK EOF =====
