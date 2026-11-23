<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# SS-1510 — per-person forum moderator keys

Owner approval was given directly on 23 November 2026: build the complete key maker with full repository access. This records the implemented boundary and the decisions that security review must check.

## Authority and lifecycle

Only an authenticated Smack Central owner session can issue, list or revoke a key. Each key has a required person-or-purpose label, a random public key ID and a random 256-bit bearer secret. The complete bearer token is shown once from response memory and is not put in the session. The database stores the key ID and an HMAC-SHA256 digest, never the bearer secret. The HMAC pepper is derived from the server's existing owner key, so a forum-database copy alone cannot validate guesses or mint a usable digest.

Revocation is immediate and permanent. Revoked rows are retained so the record survives. Keys do not expire silently; the owner revokes them explicitly. The global `FORUM_MOD_KEY` remains an owner-only break-glass credential and is not handed to moderators. Rotating that owner key intentionally invalidates every per-person key because it changes the HMAC pepper; new keys must then be issued.

Issue and revoke forms carry a session-bound CSRF token. Both operations and all later moderation actions write the append-only moderation log. Audit rows contain the key ID and label, but never the bearer secret or stored digest.

## Fixed moderation-only scope

A per-person key may read boards, thread lists, individual threads, search results and tags. It may edit, classify, move, pin, lock or soft-delete threads; edit or soft-delete replies; accept or unaccept answers; add or remove tags; and hide or restore attached images.

It may not create or change boards, manage installs or roles, issue or revoke keys, create threads or replies, react, upload images, follow threads, mark read state, read notifications, or alter an install profile. The `/me` response reports `post=false`, `moderate=true`, and `administer=false` so clients do not have to infer the boundary.

## Delivery and rollback

The v7 forum migration creates the key table and permits a null editor install ID because a per-person key is deliberately not an install. Smack Central applies the canonical forum schema before copying the matching API files. Rolling back the API makes all per-person tokens inert; their hashed rows and audit evidence remain available. No existing install or owner key changes.

## Verification gate

Before shipment: run the executable key-authentication regression, the source scope regression, the existing moderation-log regression, PHP lint, schema checks, and an independent security review. Installed-build verification and owner acceptance remain distinct from source verification.

<!-- ===== SNAPSMACK EOF ===== -->
