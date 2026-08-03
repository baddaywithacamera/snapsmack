-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
-- SnapSmack migration: convert legacy emoji reactions into heart likes.
-- migrate-reactions-to-likes.sql
--
-- The emoji reaction system was removed; SnapSmack standardized on a single HEART
-- like (matching the Fediverse). This preserves past engagement by turning every
-- existing reaction into a like. INSERT IGNORE dedups against snap_likes'
-- UNIQUE(post_id, user_id), so a user who both reacted AND liked keeps a single like.
--
-- NON-DESTRUCTIVE: snap_reactions is left in place. Confirm the like tallies look
-- right, then drop snap_reactions in a later migration. snap_likes and the federated
-- (Fediverse) likes table are untouched.

INSERT IGNORE INTO snap_likes (post_id, user_id, guest_hash, created_at)
SELECT post_id, user_id, guest_hash, created_at
FROM snap_reactions;

-- ===== SNAPSMACK EOF =====
