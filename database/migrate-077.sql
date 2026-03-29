-- ============================================================
-- SNAPSMACK — Migration 0.7.7
-- ============================================================
-- Adds two columns to snap_images:
--
--   sort_order      INT — manual display order set in Smack Manage.
--                        Lower numbers appear first. Defaults to 0
--                        (all existing images sort by img_date as before).
--
--   img_source_file VARCHAR(255) — original camera filename as posted by
--                        Smack Your Batch Up (e.g. 20260318_153727.jpg).
--                        NULL for images posted via the web interface or
--                        before this column existed.
--
-- Both statements are idempotent — safe to run more than once.
-- ============================================================

ALTER TABLE snap_images
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0
        COMMENT 'Manual display order; lower = earlier. 0 = unsorted (falls back to img_date)';

ALTER TABLE snap_images
    ADD COLUMN IF NOT EXISTS img_source_file VARCHAR(255) DEFAULT NULL
        COMMENT 'Original camera filename at upload time (from Smack Your Batch Up)';
