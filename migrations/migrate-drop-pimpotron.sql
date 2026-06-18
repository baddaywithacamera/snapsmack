-- SNAPSMACK migration — drop retired Pimpotron / KIOSK tables
--
-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
--
-- The KIOSK skin and its Pimpotron sequencer were cut (mission creep). The engine,
-- admin page, payload endpoint, manifest entry and admin nav are removed in 0.7.267
-- (secaudit 026). These two tables are now orphaned. Authorised for drop by Sean
-- ("permission to drop; we can build a new one if we need to"). DESTRUCTIVE — slideshow
-- data is not recoverable. Drop child (slides) before parent (slideshows).

DROP TABLE IF EXISTS `snap_pimpotron_slides`;
DROP TABLE IF EXISTS `snap_pimpotron_slideshows`;

-- ===== SNAPSMACK EOF =====
