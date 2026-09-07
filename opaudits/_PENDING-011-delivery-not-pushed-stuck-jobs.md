<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->

# PENDING OPAUDIT 011 — Delivery cron: published posts stuck at "not pushed"

**Status:** NOT YET A PUBLISHED REPORT. Live incident, Codex is fixing the cron
(2026-09-07). Do not publish until root cause + fix are known and Sean confirms.
Sean fed this in "for the reports" — capture now, write up once it's understood.

## What a human saw (from Sean's delivery log, foundtextures.ca, 2026-09-07)
- `smack-sv-delivery-log.php` → RECENT POSTS — DID THEY GO OUT?
- Every recent published, federation-ON post (~#1568–#1592) shows **✗ not pushed**,
  Likes 0, Boosts 0, Stuck jobs —. "Published, federation on, but never reached anyone."
- Followers panel shows 3 followers with delivery addresses and a ✓ Check
  (baddaywithacamera@pixelfed.ca, mccormickphoto@pixelfed.social, participate@photofri.day).
- So: followers resolve fine, posts are published + federation-enabled, yet nothing pushes.

## Likely area (DO NOT assume — for the writer, verify with Codex's fix)
- Delivery cron not draining the queue (the CLI delivery job that OPAUDIT 003 moved
  all paced work into). "cron is fucked again" — Sean. Recurs; the word "again" matters.
- Cross-check against OPAUDIT 003 (web-cron removed; work now only in CLI cron) and
  OPAUDIT 004 (Wrong Door — stale inbox = false delivered). This one is the opposite of
  004: nothing is even marked pushed.

## State when written
- **BLEW UP / OPEN** at time of observation. Move to FIXED — CONFIRMING once Codex's
  fix lands, then WATCHED WORKING only when Sean watches a post actually push + land.

## Note
- Codex has landed parallel "operational audit" commits on dev
  (df84214f "Add remaining federation operational audits", 903d7f24 "Add COLD SNAP
  metadata operational audit"). Reconcile these with the OPAUDIT NNN series before
  publishing 011 so we don't end up with two competing operability-audit formats.

<!-- ===== SNAPSMACK EOF ===== -->
