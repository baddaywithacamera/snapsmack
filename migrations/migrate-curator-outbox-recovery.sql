ALTER TABLE snap_curator_directory
    ADD COLUMN last_outbox_check_at datetime DEFAULT NULL AFTER last_checked_at;
