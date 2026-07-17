-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
-- SNAPSMACK migration: give api_key_remote a DEFAULT '' so INSERTs that
-- don't supply the column (roster sync, peer discovery) don't blow up on
-- MySQL strict mode installs.
--
-- Safe to run multiple times — MODIFY on an already-defaulted column is a no-op.

ALTER TABLE `snap_multisite_nodes`
    MODIFY COLUMN `api_key_remote` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
        COMMENT 'Key we use to call the remote site';

-- ===== SNAPSMACK EOF =====
