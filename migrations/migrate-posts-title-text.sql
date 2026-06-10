-- SNAPSMACK_EOF_HEADER: this file must end with -- ===== SNAPSMACK EOF =====
-- SnapSmack: widen snap_posts.title from VARCHAR(500) to TEXT
--
-- Instagram captions used as post titles can exceed 500 characters.
-- TEXT removes the length cap without affecting any existing data.

ALTER TABLE `snap_posts`
    MODIFY `title` TEXT COLLATE utf8mb4_unicode_ci NOT NULL;

-- ===== SNAPSMACK EOF =====
