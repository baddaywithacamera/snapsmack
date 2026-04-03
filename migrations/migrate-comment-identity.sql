-- migrate-comment-identity.sql
-- SnapSmack Alpha 0.7.8
--
-- This migration was superseded by schema-sync.php (0.7.8), which creates
-- and maintains the snap_community_comments table structure idempotently,
-- including the user_id column with the correct unsigned int type.
--
-- This file is a no-op stub. Its sole purpose is to be recorded in
-- snap_migrations so the updater does not treat it as pending on future runs.

SELECT 1;
