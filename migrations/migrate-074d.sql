-- SNAPSMACK Migration 0.7.4d
-- Adds sort_order column to snap_images for manual drag-and-drop ordering.
-- Initialises values from current img_date DESC order so existing
-- archives maintain their current display sequence.

ALTER TABLE snap_images
    ADD COLUMN sort_order INT NOT NULL DEFAULT 0
    COMMENT 'Manual display order. Lower = earlier in feed. 0 = unset (falls back to img_date DESC).';

-- Seed sort_order from current date order so nothing jumps on first load.
SET @row := 0;
UPDATE snap_images
SET sort_order = (@row := @row + 1)
ORDER BY img_date DESC;
