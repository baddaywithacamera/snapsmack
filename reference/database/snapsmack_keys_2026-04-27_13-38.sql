-- SnapSmack Disaster Recovery
-- Type: USER CREDENTIALS
-- Date: 2026-04-27 13:38:08

DROP TABLE IF EXISTS `snap_users`;
CREATE TABLE `snap_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'editor',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_skin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'default-dark',
  `recovery_code_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT '0',
  `totp_secret` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `totp_recovery_json` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `snap_users` (`id`, `username`, `password_hash`, `user_role`, `email`, `preferred_skin`, `recovery_code_hash`, `force_password_change`, `totp_secret`, `totp_enabled`, `totp_recovery_json`) VALUES ('1', 'sean', '$2y$12$TY60fhaND9C19b21TZ3JfeWQc3zmw0eLs8mIxXM5fwu53IzXtcRny', 'admin', 'sean@baddaywithacamera.ca', 'caribbean-blue', NULL, '0', NULL, '0', NULL);
INSERT INTO `snap_users` (`id`, `username`, `password_hash`, `user_role`, `email`, `preferred_skin`, `recovery_code_hash`, `force_password_change`, `totp_secret`, `totp_enabled`, `totp_recovery_json`) VALUES ('3', 'noah', '$2y$12$Dyz4REuVWio4OdlovHRPaeZymPR4mcJLFTyjK5WLrwsrHjELuvcWO', 'admin', 'noahgrey@gmail.com', '50-shades-of-greymatter', NULL, '0', NULL, '0', NULL);
INSERT INTO `snap_users` (`id`, `username`, `password_hash`, `user_role`, `email`, `preferred_skin`, `recovery_code_hash`, `force_password_change`, `totp_secret`, `totp_enabled`, `totp_recovery_json`) VALUES ('4', 'david', '$2y$12$tCe3TbhknnzSOLHeBMvNC.RpSQGNr8SrPtqzidQy42JoE9jj0C.B6', 'admin', 'david@something.com', 'default-dark', NULL, '0', NULL, '0', NULL);
