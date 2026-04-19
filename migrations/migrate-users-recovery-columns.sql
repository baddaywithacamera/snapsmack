-- migrate-users-recovery-columns.sql
-- Adds recovery_code_hash and force_password_change to snap_users.
-- Both columns were introduced with the 2FA / password recovery system.
-- Safe to run multiple times — ALTER TABLE errors on duplicate column (1060)
-- are tolerated by the updater.

ALTER TABLE `snap_users`
    ADD COLUMN `recovery_code_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL;

ALTER TABLE `snap_users`
    ADD COLUMN `force_password_change` tinyint(1) NOT NULL DEFAULT 0;
