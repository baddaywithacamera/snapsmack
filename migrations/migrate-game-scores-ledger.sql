-- SNAPSMACK_EOF_HEADER
-- Last non-empty line MUST be: -- ===== SNAPSMACK EOF =====

CREATE TABLE IF NOT EXISTS `snap_game_scores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `game_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `initials` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `metric_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `score_value` bigint unsigned NOT NULL,
  `score_meta` text COLLATE utf8mb4_unicode_ci NULL,
  `session_key` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `score_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_game_metric_period` (`game_key`,`metric_key`,`score_date`,`score_value`),
  KEY `idx_game_session` (`game_key`,`session_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `snap_game_scores` (`game_key`,`initials`,`metric_key`,`score_value`,`score_meta`,`session_key`,`score_date`,`created_at`)
SELECT 'game-on', `initials`, 'fastest_solve', `best_ms`, CONCAT('{"best_ms":',`best_ms`,',"solved_count":',`solved_count`,'}'), MD5(CONCAT('legacy-',`id`)), `score_date`, `created_at`
FROM `snap_game_on_scores`;

INSERT INTO `snap_game_scores` (`game_key`,`initials`,`metric_key`,`score_value`,`score_meta`,`session_key`,`score_date`,`created_at`)
SELECT 'game-on', `initials`, 'session_solved', `solved_count`, CONCAT('{"best_ms":',`best_ms`,',"solved_count":',`solved_count`,'}'), MD5(CONCAT('legacy-',`id`)), `score_date`, `created_at`
FROM `snap_game_on_scores`;
-- ===== SNAPSMACK EOF =====
