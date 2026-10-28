-- SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
-- Apply once, with workers stopped and a verified backup, BEFORE enabling
-- conversation_catch_up on an existing MariaDB store. Existing message IDs,
-- contents and hash chains remain unchanged. Do not run against SQLite.
ALTER TABLE troll_messages
    ADD COLUMN member_sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
    ADD INDEX troll_member_room_sequence(room_id, member_sequence);
-- ===== SNAPSMACK EOF =====
