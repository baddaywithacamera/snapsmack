-- SNAPSMACK_EOF_HEADER
-- Last non-empty line of this file MUST be: -- ===== SNAPSMACK EOF =====
-- Missing or different = truncated/corrupted. Restore before saving.
--
-- Repair ss_forum_categories.thread_count and reply_count from the rows that
-- actually exist.
--
-- WHY THIS EXISTS
--
-- Deleting a thread decremented the board's thread_count but not its
-- reply_count, so a board went on counting replies to a thread nobody could
-- reach. foundtextures.ca showed "Getting Started - 0 THREADS / 1 REPLY",
-- which is a count of something that is not there.
--
-- The code path is fixed (api/forum/index.php, DELETE /threads/{id}), but a
-- fix forward does not repair a number that already drifted. This does.
--
-- SAFE TO RUN ANY TIME. It only writes the two counters, it derives them from
-- ss_forum_threads and ss_forum_replies, and running it twice does nothing the
-- first run did not. It does not touch a thread, a reply or a post.
--
--   mysql -u <user> -p <database> < forum-recount-boards.sql
--
-- Soft-deleted rows are excluded on purpose: the boards show what a reader can
-- open, and a deleted thread is not that.

UPDATE `ss_forum_categories` c
SET
    c.`thread_count` = (
        SELECT COUNT(*)
        FROM `ss_forum_threads` t
        WHERE t.`category_id` = c.`id`
          AND t.`is_deleted` = 0
    ),
    c.`reply_count` = (
        SELECT COUNT(*)
        FROM `ss_forum_replies` r
        JOIN `ss_forum_threads` t2 ON t2.`id` = r.`thread_id`
        WHERE t2.`category_id` = c.`id`
          AND t2.`is_deleted` = 0
          AND r.`is_deleted`  = 0
    );

-- What the boards say afterwards. Every row should be the real count.
SELECT `id`, `slug`, `name`, `thread_count`, `reply_count`
FROM `ss_forum_categories`
ORDER BY `sort_order`, `id`;

-- ===== SNAPSMACK EOF =====
