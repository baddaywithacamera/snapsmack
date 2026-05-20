<?php
/**
 * SNAPSMACK - Migration 059: Multisite node tagline + blogroll desc
 *
 * Adds site_tagline and blogroll_desc columns to snap_multisite_nodes.
 * site_tagline is populated from each spoke's heartbeat response.
 * blogroll_desc is a hub-admin override for the "My Blogs" category description.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('migration_059')) {
    function migration_059(PDO $pdo): void {

        // site_tagline: populated automatically from heartbeat
        $col = $pdo->query("SHOW COLUMNS FROM snap_multisite_nodes LIKE 'site_tagline'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE snap_multisite_nodes ADD COLUMN site_tagline VARCHAR(500) DEFAULT NULL AFTER site_name");
        }

        // blogroll_desc: hub-admin override for the My Blogs entry per spoke
        $col2 = $pdo->query("SHOW COLUMNS FROM snap_multisite_nodes LIKE 'blogroll_desc'")->fetch();
        if (!$col2) {
            $pdo->exec("ALTER TABLE snap_multisite_nodes ADD COLUMN blogroll_desc VARCHAR(500) DEFAULT NULL AFTER site_tagline");
        }
    }
}
// ===== SNAPSMACK EOF =====
