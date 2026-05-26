-- SNAPSMACK — Network Alert (Layer 2) settings
-- Adds opt-in settings for participation in the SC global network alert system.
-- Layer 2 only — entirely separate from Layer 1 (hub/spoke RED, smackback.php).
-- All defaults are OFF. Admin must explicitly enable both send and receive.

INSERT INTO snap_settings (setting_key, setting_val) VALUES
    ('network_alert_send',         '0'),
    ('network_alert_receive',      '0'),
    ('network_alert_status',       'green'),
    ('network_alert_message',      ''),
    ('network_alert_since',        ''),
    ('network_alert_last_checked', ''),
    ('network_alert_sc_url',       'https://snapsmack.ca')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
-- ===== SNAPSMACK EOF =====
