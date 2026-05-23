-- SnapSmack migration: SMACKBACK Phase 2 — hub/spoke breach correlation
-- Adds smackback columns to snap_multisite_nodes so the hub can cache
-- each spoke's SMACKBACK status from heartbeat and push reports.

ALTER TABLE `snap_multisite_nodes`
    ADD COLUMN IF NOT EXISTS `smackback_status`
        VARCHAR(20) NOT NULL DEFAULT 'unknown'
        COMMENT 'Cached from heartbeat + push: clean|breach|unknown'
        AFTER `maintenance_mode`,

    ADD COLUMN IF NOT EXISTS `smackback_breach_at`
        DATETIME NULL
        COMMENT 'When breach was first detected on this spoke'
        AFTER `smackback_status`,

    ADD COLUMN IF NOT EXISTS `smackback_breach_files`
        MEDIUMTEXT NULL
        COMMENT 'JSON: affected files from last breach report'
        AFTER `smackback_breach_at`;

-- ===== SNAPSMACK EOF =====
