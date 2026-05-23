-- migrate-spoke-maintenance-mode.sql
-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.

-- Adds maintenance_mode column to snap_multisite_nodes so the hub can cache
-- each spoke's current maintenance state from heartbeat data, and display it
-- in the Multisite Management dashboard.

ALTER TABLE snap_multisite_nodes
    ADD COLUMN IF NOT EXISTS `maintenance_mode` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Cached from heartbeat: 1 = spoke is currently in maintenance mode';

-- ===== SNAPSMACK EOF =====
