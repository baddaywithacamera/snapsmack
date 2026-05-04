<?php
/**
 * Migration 032 — Rename satellite → spoke in multisite tables.
 *
 * Changes the role enum in snap_multisite_nodes from
 * enum('hub','satellite') to enum('hub','spoke') and updates
 * any existing rows. Also updates the multisite_role setting key.
 *
 * Idempotent: checks current enum values before altering.
 */

require_once __DIR__ . '/../core/db.php';

try {
    // ── 1. Check current enum values on snap_multisite_nodes.role ──────────
    $col = $pdo->query("SHOW COLUMNS FROM snap_multisite_nodes LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "032: snap_multisite_nodes.role column not found — skipping.\n";
        return;
    }

    $type = $col['Type'] ?? '';

    // Already migrated?
    if (str_contains($type, "'spoke'") && !str_contains($type, "'satellite'")) {
        echo "032: Already migrated (role enum already uses 'spoke'). Skipping.\n";
        return;
    }

    // ── 2. ALTER the enum to include both values temporarily ───────────────
    $pdo->exec("ALTER TABLE snap_multisite_nodes MODIFY COLUMN `role` enum('hub','satellite','spoke') NOT NULL");

    // ── 3. UPDATE existing 'satellite' rows to 'spoke' ────────────────────
    $updated = $pdo->exec("UPDATE snap_multisite_nodes SET role = 'spoke' WHERE role = 'satellite'");
    echo "032: Updated {$updated} node(s) from 'satellite' to 'spoke'.\n";

    // ── 3b. Fix any rows with empty/blank role (inserted before enum was expanded)
    $fixed = $pdo->exec("UPDATE snap_multisite_nodes SET role = 'spoke' WHERE role = '' AND site_url != ''");
    if ($fixed > 0) {
        echo "032: Fixed {$fixed} node(s) with blank role → 'spoke'.\n";
    }

    // ── 4. DROP the old value from the enum ────────────────────────────────
    $pdo->exec("ALTER TABLE snap_multisite_nodes MODIFY COLUMN `role` enum('hub','spoke') NOT NULL");

    // ── 5. Update the multisite_role setting if it says 'satellite' ───────
    $pdo->exec("UPDATE snap_settings SET setting_val = 'spoke' WHERE setting_key = 'multisite_role' AND setting_val = 'satellite'");

    // ── 6. Same treatment for snap_multisite_queue.target_role if exists ───
    $queue_col = $pdo->query("SHOW COLUMNS FROM snap_multisite_queue LIKE 'target_role'")->fetch(PDO::FETCH_ASSOC);
    if ($queue_col) {
        $qt = $queue_col['Type'] ?? '';
        if (str_contains($qt, "'satellite'")) {
            $pdo->exec("ALTER TABLE snap_multisite_queue MODIFY COLUMN `target_role` enum('hub','satellite','spoke','all') NOT NULL DEFAULT 'all'");
            $pdo->exec("UPDATE snap_multisite_queue SET target_role = 'spoke' WHERE target_role = 'satellite'");
            $pdo->exec("ALTER TABLE snap_multisite_queue MODIFY COLUMN `target_role` enum('hub','spoke','all') NOT NULL DEFAULT 'all'");
        }
    }

    echo "032: Migration complete — satellite → spoke.\n";

} catch (PDOException $e) {
    echo "032: Error — " . $e->getMessage() . "\n";
}
// EOF
