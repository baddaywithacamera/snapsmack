<?php
/**
 * SNAPSMACK - Migration 045: Login Protection
 *
 * Creates snap_ip_bans table for temporary IP bans issued by the
 * brute-force detection system in snap-in.php.
 */

$migration_name = '045_login_protection';

// Already applied?
$check = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration_name = ?");
$check->execute([$migration_name]);
if ($check->fetchColumn()) return;

// Create the IP bans table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `snap_ip_bans` (
      `id`         int unsigned NOT NULL AUTO_INCREMENT,
      `ip`         varchar(45)  COLLATE utf8mb4_unicode_ci NOT NULL,
      `reason`     varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto:brute_force',
      `banned_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `expires_at` datetime NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_ip` (`ip`),
      KEY `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$ins = $pdo->prepare("INSERT IGNORE INTO snap_migrations (migration_name, applied_at) VALUES (?, NOW())");
$ins->execute([$migration_name]);
