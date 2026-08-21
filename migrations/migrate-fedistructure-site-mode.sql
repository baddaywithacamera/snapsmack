-- SNAPSMACK_EOF_HEADER: this file MUST end with the canonical .sql EOF marker.
-- 0.7.545D: FEDISTRUCTURE becomes durable install mode 4.0. Installs done BEFORE
-- 545D recorded site_mode='photoblog' (the old disguise), so the relay/hub gates
-- (which require site_mode='fedistructure') would stay dormant on already-deployed
-- network sites. Re-stamp ONLY genuine FEDISTRUCTURE installs, guarded by the
-- install's own distribution marker so a normal photoblog / gram / longform site
-- is never touched. Idempotent: only the old 'photoblog' value is flipped, so a
-- re-run is a no-op.
--
-- Uses a self-join (not a subquery on the target table) so MySQL never raises
-- error 1093 "can't specify target table for update in FROM".
UPDATE snap_settings AS s
  JOIN snap_settings AS d
    ON d.setting_key = 'distribution'
   AND d.setting_val = 'fedistructure'
   SET s.setting_val = 'fedistructure'
 WHERE s.setting_key = 'site_mode'
   AND s.setting_val = 'photoblog';
-- ===== SNAPSMACK EOF =====
