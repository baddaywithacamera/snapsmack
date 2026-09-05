-- Durable retry work for relay Announces whose original object was temporarily unavailable.
CREATE TABLE IF NOT EXISTS `snap_relay_ingest_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `relay_actor_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_id` varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `status` enum('queued','shelved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `next_try_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_relay_ingest` (`relay_actor_url`(150),`object_id`(191)),
  KEY `idx_relay_ingest_due` (`status`,`next_try_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== SNAPSMACK EOF =====
