<!--
SNAPSMACK_EOF_HEADER
    <!-- ===== SNAPSMACK EOF ===== -->
Last non-empty line of this file MUST match the marker named above.
Missing or different = truncated/corrupted. Restore before saving.
-->

# SHOTS FIRED — Changelog

## 0.1.0 — 2026-08-14

### Added
- First cut of SHOTS FIRED: the fleet-wide scheduled-post calendar.
- Loads the fleet from the shared cross-tool profile store (`snap_profiles`),
  read-only — a blog set up in SYBU / COLD SNAP appears here automatically.
- Pulls each site's upcoming FUTURE-dated posts and renders them as a
  day-grouped agenda, colour-coded per site.
- MOVE control reschedules a post by writing a new `img_date` back to its spoke.
- Calls two intended server routes and degrades gracefully when they 404
  ("no scheduling API yet"): `smack-schedule.php?action=list` and
  `?action=set_date`. Both are MUST-ADD server endpoints — see
  `docs/shots-fired-spec.md`.

---

<!-- ===== SNAPSMACK EOF ===== -->
