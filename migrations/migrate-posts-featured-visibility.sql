-- SNAPSMACK_EOF_HEADER: this file must end with -- ===== SNAPSMACK EOF =====
-- SnapSmack: separate a SMACKTALK display image from the in-post banner.

ALTER TABLE `snap_posts`
    ADD COLUMN IF NOT EXISTS `show_featured_image` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Whether the featured image is rendered inside the single post. It remains available to listings and social previews when hidden.';

-- ===== SNAPSMACK EOF =====
