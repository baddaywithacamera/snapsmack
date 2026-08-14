<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# SMACK YOUR MOUTH — Changelog

Offline fleet comment moderation + replies. The inbound twin of COLD SNAP.

## 0.1.0 — 2026-08-14

### Added
- First build. Desktop shell for moderating the SnapSmack fleet's comments
  offline and syncing decisions + replies back on the next connection.
- **Fleet from the shared stores** — loads every site THE HUB discovered
  (snap_profiles + snap_creds), read-only. Falls back to a one-off single-site
  connection when there is no shared home.
- **Resumable sessions** — pulled comments and the local decision/reply for each
  are stored as one JSON file per comment under `mouth_sessions/`, so the work
  survives a closed laptop and short connection windows.
- **Offline moderation** — approve / delete / mark-spam and write a reply, all
  with no network. Big decision targets and an explicit SAVE REPLY (no fragile
  focus-out auto-save) for forgiving, mis-click-safe operation.
- **Store-and-forward sync with positive verification** — a decision or reply is
  marked synced only after the live comment is pulled back and the change is
  confirmed on the server; a reply is posted before an approve so the thread
  stays intact, and a delete can never carry a reply.
- **Thumb-drive export / import** — a self-contained, versioned batch folder with
  a RECOVERY.txt, so a moderation session can move between machines and keep its
  sync state.

### Notes
- Reply, mark-spam, read-back-by-id, and the approved+pending history list
  landed server-side in core **0.7.523** (`core/multisite-api.php`): the
  `comments/reply`, `comments/get`, `comments/list` routes and the `spam` verb on
  `comments/action`, backed by a new non-destructive `snap_comments.is_spam`
  column. The client keeps its graceful fallbacks, so it still degrades cleanly
  against an older spoke. See `docs/smack-your-mouth-spec.md` → "Server API".

---

<!-- ===== SNAPSMACK EOF ===== -->
