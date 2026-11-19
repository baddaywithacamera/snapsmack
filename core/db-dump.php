<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SNAPSMACK — database dump to a file, streamed.
 *
 * WHY THIS FILE EXISTS. SS-1425 needs a pre-restore snapshot of the live
 * database, taken before the kit's dump runs. The working implementation of
 * exactly that already existed in core/updater.php as the pre-update backup,
 * and the rule here is to copy working code rather than improvise a second
 * one. But the engine cannot CALL it: updater_create_backup() reads
 * `global $pdo`, and including core/updater.php drags in release URLs, a
 * direct-access guard and the signing-key machinery that a restore has no
 * business loading.
 *
 * So the loop lives here, on its own, with no state and no includes.
 *
 * WHAT IS NOT DONE, SAID PLAINLY: core/updater.php:899 still has its own copy
 * of this loop. Folding it onto this function is the right end state and it is
 * NOT part of SS-1425 — that function is the one the updater's rollback
 * depends on, and changing it to deliver a snapshot feature would put the
 * fleet's update path at risk for no gain to this ticket. Filed as a follow-up
 * instead. If you are reading this because you are about to change one of the
 * two, change both or fold them.
 *
 * NOT REDACTED, DELIBERATELY. core/export-engine.php's dump applies
 * redactSecrets() because a kit leaves the machine. A snapshot never does: it
 * exists so the owner can get back to the state they were in, and a rollback
 * that loses the site's own fleet keys is not a rollback. That is also why
 * where it lands matters, and why the caller writes a deny file beside it.
 *
 * MySQL/MariaDB only. SHOW TABLES and SHOW CREATE TABLE are not portable, so
 * on any other driver this reports the failure rather than writing half a
 * file. A caller that cannot take a snapshot must refuse the restore.
 */

if (!function_exists('snap_dump_database')) {

/**
 * Dump every table in the connected database to $destFile.
 *
 * Streams: one batch of rows in memory at a time, never the whole table.
 *
 * @param  PDO    $pdo        the live connection
 * @param  string $destFile   file to create; overwritten if present
 * @param  string $headerNote first comment line, e.g. 'pre-restore DB snapshot'
 * @param  string $error      set to why it failed
 * @return int|false          bytes written, or false
 */
function snap_dump_database(PDO $pdo, string $destFile, string $headerNote,
                            string &$error = ''): int|false {
    @set_time_limit(300);

    $fh = @fopen($destFile, 'wb');
    if (!$fh) {
        $error = 'Could not open ' . $destFile . ' for writing.';
        return false;
    }

    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tables)) {
            fclose($fh);
            @unlink($destFile);
            $error = 'No tables found in the database, so there is nothing to snapshot.';
            return false;
        }

        $version = defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : 'unknown';
        fwrite($fh, "-- SnapSmack {$headerNote}\n");
        fwrite($fh, "-- Version: {$version}  Date: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, '-- Tables: ' . implode(', ', $tables) . "\n\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $quoted = "`{$table}`";

            $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM);
            fwrite($fh, "DROP TABLE IF EXISTS {$quoted};\n");
            fwrite($fh, $create[1] . ";\n\n");

            $offset = 0;
            $batch  = 200;
            $cols   = null;

            while (true) {
                $rows = $pdo->query("SELECT * FROM {$quoted} LIMIT {$batch} OFFSET {$offset}")
                            ->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) break;

                if ($cols === null) {
                    $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                }

                $values = [];
                foreach ($rows as $row) {
                    $escaped = array_map(
                        static fn ($v) => $v === null ? 'NULL' : $pdo->quote($v),
                        array_values($row)
                    );
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                fwrite($fh, "INSERT INTO {$quoted} ({$cols}) VALUES\n");
                fwrite($fh, implode(",\n", $values) . ";\n\n");

                $offset += $batch;
                if (count($rows) < $batch) break;
                usleep(50000);   // 50ms between batches — shared-host I/O limits
            }
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        clearstatcache(true, $destFile);
        $bytes = @filesize($destFile);
        if ($bytes === false || $bytes <= 0) {
            $error = 'The dump file came out empty.';
            return false;
        }
        return (int)$bytes;

    } catch (Throwable $e) {
        // A half-written dump is worse than none: it would read as a usable
        // rollback. Delete it and say what happened.
        if (is_resource($fh)) fclose($fh);
        @unlink($destFile);
        $error = 'Dump failed: ' . $e->getMessage();
        return false;
    }
}

/**
 * The backups directory, created if missing, with web access denied.
 *
 * The root .htaccess that install.php:1723 writes already denies *.sql by
 * extension, which is what protects the updater's pre-update dumps today. This
 * adds a deny file in the directory itself, as the PM asked: belt and braces,
 * and it survives a root .htaccess being replaced. The form is copied from
 * core/diaglog.php, which covers Apache 2.2 and 2.4 both.
 *
 * NEITHER OF THOSE IS ANYTHING ON NGINX. A self-hoster on nginx needs an
 * equivalent location block; that note belongs in the support tab, and Codex
 * has it.
 *
 * @return string|null the directory, or null if it cannot be used
 */
function snap_backup_dir_secure(string $dir): ?string {
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }

    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents(
            $ht,
            "Require all denied\n"
            . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
        );
    }
    $idx = $dir . '/index.html';
    if (!is_file($idx)) {
        @file_put_contents($idx, "");
    }

    return is_writable($dir) ? $dir : null;
}

/**
 * Keep only the $keep newest files matching $prefix in $dir; delete the rest.
 *
 * SAME POLICY AS THE UPDATER, not a new one. updater_prune_backups() keeps 3
 * and is called from smack-update.php after a successful update; the PM's
 * instruction was that retention follows whatever the updater does, so it is
 * 3 here too and the number is not ours to pick.
 */
function snap_prune_dumps(string $dir, string $prefix, int $keep = 3): int {
    $files = glob($dir . '/' . $prefix . '*.sql') ?: [];
    if (count($files) <= $keep) return 0;

    usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));

    $removed = 0;
    foreach (array_slice($files, $keep) as $old) {
        if (@unlink($old)) $removed++;
    }
    return $removed;
}

}
// ===== SNAPSMACK EOF =====
