<?php
/**
 * SNAPSMACK - Migration 047: Patch .htaccess named routes
 *
 * Checks the root .htaccess for required named route rules and injects any
 * that are missing. Does NOT replace the file — existing customisations are
 * preserved. Inserts missing rules immediately before the catch-all slug rule
 * so routing priority is correct.
 *
 * Required routes (added as SnapSmack has grown):
 *   snap-in   — login page (added 0.7.27; .htaccess is protected so older
 *               installs that updated via the in-admin updater never got it)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$migration_name = '047_patch_htaccess';

try {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_migrations WHERE migration_name = ?"
    );
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    $htaccess_path = dirname(__DIR__) . '/.htaccess';

    if (!file_exists($htaccess_path)) {
        // No .htaccess at all — nothing to patch, not our problem here
        $pdo->prepare(
            "INSERT INTO snap_migrations (migration_name, applied_at) VALUES (?, NOW())"
        )->execute([$migration_name]);
        return ['status' => 'ok', 'message' => '.htaccess not found — skipped patching.'];
    }

    $content = file_get_contents($htaccess_path);
    if ($content === false) {
        return ['status' => 'error', 'message' => 'Could not read .htaccess.'];
    }

    // Named routes that must be present, in the order they should appear.
    // Each entry: [ 'pattern' to detect presence, 'rule' line to inject if absent ]
    $required_routes = [
        [
            'detect' => 'RewriteRule ^snap-in$',
            'rule'   => 'RewriteRule ^snap-in$ snap-in.php [L,QSA]',
        ],
    ];

    // Anchor point: inject missing rules just before the catch-all slug rule.
    // If the anchor isn't found, append to end of RewriteEngine block as fallback.
    $anchor = 'RewriteRule ^([a-zA-Z0-9_-]+)$';

    $patched = false;
    foreach ($required_routes as $route) {
        if (strpos($content, $route['detect']) !== false) {
            continue; // already present
        }

        if (strpos($content, $anchor) !== false) {
            $content = str_replace(
                $anchor,
                $route['rule'] . "\n" . $anchor,
                $content
            );
        } else {
            $content .= "\n" . $route['rule'] . "\n";
        }
        $patched = true;
    }

    if ($patched) {
        $tmp = $htaccess_path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $content) === false) {
            return ['status' => 'error', 'message' => 'Could not write temporary .htaccess patch.'];
        }
        if (!rename($tmp, $htaccess_path)) {
            @unlink($tmp);
            return ['status' => 'error', 'message' => 'Could not replace .htaccess — check file permissions.'];
        }
    }

    $pdo->prepare(
        "INSERT INTO snap_migrations (migration_name, applied_at) VALUES (?, NOW())"
    )->execute([$migration_name]);

    return [
        'status'  => 'ok',
        'message' => $patched ? '.htaccess patched with missing named routes.' : '.htaccess already up to date.',
    ];

} catch (PDOException $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
