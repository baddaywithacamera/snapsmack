CREATE TABLE IF NOT EXISTS `snap_game_on_scores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `initials` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `best_ms` int unsigned NOT NULL,
  `solved_count` int unsigned NOT NULL DEFAULT 1,
  `score_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_go_fastest` (`best_ms`,`solved_count`),
  KEY `idx_go_most` (`solved_count`,`best_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
