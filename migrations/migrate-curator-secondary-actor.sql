-- Preserve @relay while letting queued curator work retain its signing identity.
ALTER TABLE `snap_ap_deliveries`
  ADD COLUMN `actor_role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'primary' AFTER `dedupe_key`;
-- ===== SNAPSMACK EOF =====
