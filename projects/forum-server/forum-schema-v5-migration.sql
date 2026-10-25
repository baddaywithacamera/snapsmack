-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.


-- ============================================================
-- SNAPSMACK FORUM — v5 Migration  (the blog's own avatar)
-- Adds: avatar_url on installs.
-- Run this ONCE on existing installs that already have the v4 schema.
-- Fresh installs: use forum-schema.sql instead (already includes this).
-- The Smack Central self-updater applies forum-schema.sql idempotently, so
-- installs updated through Smack Central get this automatically.
-- Requires MySQL 8.0+ (ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- The blog's avatar, so a post carries the face the blog already uses
-- everywhere else instead of a generated initials circle.
--
-- A URL, never an image: the forum stores no files for this and copies
-- nothing. The blog reports the address of its own avatar when it checks in.
--
-- The API will only write an https address on the install's own domain, and
-- that restriction is the point rather than tidiness. This URL is fetched by
-- the browser of every person reading the forum. An install free to aim it at
-- a host it does not own could use it to log the IP of everyone reading, or
-- swap the image for something else long after a moderator approved the post
-- it sits beside. Enforced in clean_avatar_url() in api/forum/index.php and
-- covered by tests/test_avatar_url.php.
--
-- No backfill. An install supplies its own avatar on its next check-in, and
-- an empty value simply means "no avatar", which falls back to initials.
-- ------------------------------------------------------------
ALTER TABLE ss_forum_installs
    ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(500) NOT NULL DEFAULT '' AFTER display_name;
-- ===== SNAPSMACK EOF =====
