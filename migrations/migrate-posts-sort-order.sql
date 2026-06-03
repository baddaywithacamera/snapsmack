-- SNAPSMACK_EOF_HEADER: this file must end with -- ===== SNAPSMACK EOF =====
-- SnapSmack: add sort_order to snap_posts
-- Enables manual feed reordering in smack-lt-gram (Grid lighttable).
-- Default 0 = unset; landing queries fall back to created_at DESC when 0.

ALTER TABLE `snap_posts`
    ADD COLUMN IF NOT EXISTS `sort_order` INT NOT NULL DEFAULT 0
        COMMENT 'Manual feed order. 0 = unset (falls back to created_at DESC).';

-- ===== SNAPSMACK EOF =====
