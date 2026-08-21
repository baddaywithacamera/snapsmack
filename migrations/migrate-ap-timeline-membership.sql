-- SNAPSMACK_EOF_HEADER: this file MUST end with the canonical .sql EOF marker.
-- 0.7.545D: backfill normalized feed membership from the legacy source enum.
INSERT IGNORE INTO snap_ap_timeline_membership
    (timeline_id, feed, discovered_via_actor, first_seen_at)
SELECT id, source, actor_url, fetched_at FROM snap_ap_timeline;
-- ===== SNAPSMACK EOF =====
