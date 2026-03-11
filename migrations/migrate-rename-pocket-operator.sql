-- SNAPSMACK Migration: Rename pocket-operator → pocket-rocket, set Photogram as mobile default
--
-- Run this on any existing install that was using pocket-operator.
-- Safe to run on installs that never had pocket-operator active (UPDATE affects 0 rows).

-- If the active skin was pocket-operator, switch it to pocket-rocket
UPDATE snap_settings
SET setting_val = 'pocket-rocket'
WHERE setting_key = 'active_skin'
  AND setting_val = 'pocket-operator';

-- If the mobile skin override was explicitly stored in DB, update it
-- (normally this is a PHP constant, but belt-and-suspenders)
UPDATE snap_settings
SET setting_val = 'photogram'
WHERE setting_key = 'mobile_skin_override'
  AND setting_val = 'pocket-operator';
