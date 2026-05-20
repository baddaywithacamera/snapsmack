<?php
/**
 * Migration 066 — Per-user UI mode (Big Wheel / Pimpmobile)
 *
 * Moves the ui_mode preference from snap_settings (site-wide) to snap_users
 * (per-user). Each admin can independently choose their sidebar layout.
 */
if (!isset($pdo)) die('Run via migration runner only.');

$pdo->exec("ALTER TABLE `snap_users`
    ADD COLUMN IF NOT EXISTS `ui_mode` VARCHAR(20) NOT NULL DEFAULT 'bigwheel' AFTER `preferred_skin`");
// ===== SNAPSMACK EOF =====
