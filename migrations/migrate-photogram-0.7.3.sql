-- ============================================================================
-- SNAPSMACK — Photogram Skin Isolation Migration
-- Version: Alpha v0.7.3 "Bedpan"
-- Date: 2026-03-12
--
-- Problem:
--   snapsmack_apply_skin_settings() only overwrites a bare key when a
--   skin-prefixed version of that key exists in snap_settings. If the user
--   has never saved Photogram-specific settings, no `photogram__custom_css_public`
--   row exists. The bare `custom_css_public` then retains the desktop skin's
--   compiled CSS blob (e.g. Impact Printer with #page-wrapper padding rules),
--   which loads AFTER style.css and creates visible gutters around #pg-app.
--
-- Fix:
--   Seed `photogram__custom_css_public` with an empty string. The overlay
--   function will copy '' over the bare key, effectively disabling the desktop
--   skin's compiled CSS when Photogram is active.
--
--   INSERT IGNORE — safe to re-run. If the admin has already saved Photogram
--   settings and a real value exists, this is a no-op.
--
-- Run in phpMyAdmin against the pixhellated.ca database.
-- ============================================================================

INSERT IGNORE INTO snap_settings (setting_key, setting_val)
VALUES ('photogram__custom_css_public', '');

-- ============================================================================
-- Record migration
-- ============================================================================

INSERT IGNORE INTO snap_migrations (migration)
VALUES ('migrate-photogram-0.7.3.sql');
