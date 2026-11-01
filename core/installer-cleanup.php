<?php
// SNAPSMACK_EOF_HEADER — preserve the canonical EOF marker below.
if (PHP_SAPI !== 'cli' && !empty($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

/** One-shot files are never retained on a configured installation. */
function snapsmack_installer_artifacts(string $root): array {
    if (!is_file($root . '/core/db.php')) return [];
    return array_values(array_filter(
        ['install.php', 'setup.php', 'fedup.php', 'core/.snapsmack-installer-state.php'],
        static fn(string $path): bool => file_exists($root . '/' . $path) || is_link($root . '/' . $path)
    ));
}

/** Log intent BEFORE removal; failures remain visible through the disk warning. */
function snapsmack_cleanup_installers(string $root, ?PDO $pdo): array {
    $files = snapsmack_installer_artifacts($root);
    if (!$files) return [];
    if (!$pdo) return ['Installer cleanup needs the site database to preserve its audit record.'];
    try {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO snap_smackback_log (detected_at, affected_files, file_count, resolution) VALUES (?, ?, ?, ?)')
            ->execute([$now, json_encode($files), count($files), 'installer_cleanup_started']);
        $id = $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('Installer cleanup audit write failed: ' . $e->getMessage());
        return ['Installer cleanup was not attempted: its SMACKBACK audit record could not be written.'];
    }
    $errors = [];
    foreach ($files as $path) {
        $absolute = $root . '/' . $path;
        @unlink($absolute);
        clearstatcache(true, $absolute);
        if (file_exists($absolute) || is_link($absolute)) {
            $errors[] = 'Could not remove installer artifact: ' . $path;
        } elseif (function_exists('opcache_invalidate')) {
            @opcache_invalidate($absolute, true);
        }
    }
    try {
        $pdo->prepare('UPDATE snap_smackback_log SET resolved_at = ?, resolution = ? WHERE id = ?')
            ->execute([$errors ? null : $now, $errors ? 'installer_cleanup_failed' : 'installer_cleanup', $id]);
    } catch (Throwable $e) {
        $errors[] = 'Installer cleanup completed but its audit result could not be written.';
        error_log($e->getMessage());
    }
    return $errors;
}

/** Advisory only: this does not set breach status or deny access. */
function snapsmack_render_installer_warning(): void {
    $files = snapsmack_installer_artifacts(dirname(__DIR__));
    if (!$files) return;
    echo '<div class="alert alert-warning" role="alert"><strong>SMACKBACK warning: installer cleanup incomplete.</strong> '
        . 'One-shot installation files remain on this configured site: '
        . htmlspecialchars(implode(', ', $files), ENT_QUOTES, 'UTF-8')
        . '. This warning is separate from file-integrity breach status. Remove these files through the approved maintenance process; an update will attempt logged cleanup.</div>';
}
// ===== SNAPSMACK EOF =====
