-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
-- SnapSmack: community session and email-link tokens are stored hashed
-- (SECAUDIT 049/050, 2026-09-26). From this release the database keeps only the
-- SHA-256 of each token, so rows written before it hold the raw token and can
-- never match again. Remove them: community members sign in once more, and any
-- verification or password-reset link sent before the update must be requested
-- again. Rows written before this release were also too long for the column, so
-- few if any worked.

DELETE FROM `snap_community_sessions`;
DELETE FROM `snap_community_tokens`;

-- ===== SNAPSMACK EOF =====
