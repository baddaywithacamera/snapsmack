-- SNAPSMACK MIGRATION: add URL fields to both comment tables
-- comment_url on snap_comments (legacy system, used for counts)
-- guest_url on snap_community_comments (displayed in the overlay)
ALTER TABLE snap_comments
    ADD COLUMN IF NOT EXISTS `comment_url` varchar(500)
        COLLATE utf8mb4_unicode_ci DEFAULT NULL
        AFTER `comment_author`;

ALTER TABLE snap_community_comments
    ADD COLUMN IF NOT EXISTS `guest_url` varchar(500)
        COLLATE utf8mb4_unicode_ci DEFAULT NULL
        AFTER `guest_email`;
-- ===== SNAPSMACK EOF =====
