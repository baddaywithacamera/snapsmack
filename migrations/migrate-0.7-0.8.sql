-- SNAPSMACK Migration: v0.7 → v0.8
-- Adds per-image display options for frame customisation and palette storage.
-- Safe to run multiple times (uses IF NOT EXISTS pattern via column check).

-- Add img_display_options column to snap_images
-- Stores JSON: {"frame_color":"#2c2017","frame_width":8,"mat_color":"#f5f0eb","mat_width":24,"bevel":"single","palette":["#hex",...]}
ALTER TABLE snap_images ADD COLUMN img_display_options TEXT DEFAULT NULL;

-- Update installed version
UPDATE snap_settings SET setting_val = '0.8.0-alpha' WHERE setting_key = 'installed_version';
