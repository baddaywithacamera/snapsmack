-- SNAPSMACK_EOF_HEADER: this file must end with -- ===== SNAPSMACK EOF =====
-- SnapSmack: add trigram_type column; make source/cut fields nullable
--
-- Adds soft-trigram support (type='group') for pre-sliced imports (Unzucker).
-- Hard trigrams (type='slice') retain existing NOT NULL source_path/cut_a/cut_b.
-- Soft trigrams (type='group') have those three fields NULL — the panorama was
-- sliced externally before upload.

ALTER TABLE `snap_trigrams`
    ADD COLUMN IF NOT EXISTS `trigram_type`
        ENUM('slice','group') NOT NULL DEFAULT 'slice'
        COMMENT 'slice=GD/Imagick cut in SnapSmack; group=pre-sliced external import'
        AFTER `id`;

-- Make source/cut fields nullable to accommodate group-type trigrams.
-- Existing slice rows are unaffected (values remain populated).
ALTER TABLE `snap_trigrams`
    MODIFY `source_path` VARCHAR(500) NULL
        COMMENT 'Original uploaded source image — NULL for group type',
    MODIFY `cut_a` SMALLINT UNSIGNED NULL
        COMMENT 'First cut point px — NULL for group type',
    MODIFY `cut_b` SMALLINT UNSIGNED NULL
        COMMENT 'Second cut point px — NULL for group type';

-- ===== SNAPSMACK EOF =====
