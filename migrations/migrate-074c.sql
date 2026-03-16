-- Migration: 0.7.4c — Hex colour tag support
--
-- Adds color_family column to snap_tags so that images tagged with hex colour
-- codes (e.g. #007a8b, #c25e31, #8c7d70) can be found by colour-family search
-- (e.g. searching "teal" returns images tagged with teal hex codes).
--
-- Values stored: red, orange, yellow, green, teal, blue, purple, pink, grey, black, white
-- NULL = tag is not a hex colour code.
--
-- Idempotent: the SnapSmack migration runner catches MySQL errno 1060
-- (column already exists) and 1061 (index already exists) so re-runs are safe.

ALTER TABLE snap_tags ADD COLUMN color_family VARCHAR(20) DEFAULT NULL AFTER use_count;
ALTER TABLE snap_tags ADD INDEX idx_tags_color_family (color_family);
