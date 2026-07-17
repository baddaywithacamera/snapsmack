-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
-- migrate-network-alert-push.sql
-- Adds snap_settings keys for opted-in push notification subscriptions.
-- Safe to run multiple times (INSERT IGNORE).

INSERT IGNORE INTO `snap_settings` (`setting_key`, `setting_val`) VALUES
    ('network_alert_push_enabled',            '0'),
    ('network_alert_push_token',              ''),
    ('network_alert_push_registered',         '0'),
    ('network_alert_push_unregister_pending', '0');

-- ===== SNAPSMACK EOF =====
