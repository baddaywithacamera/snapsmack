<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line must be the canonical SNAPSMACK EOF HTML comment.
-->

# SnapSmack Minimum Compliance Standard (SMCS) — v1.1

**Companion to Audit 049 · 2026-08-17 · Method + Part D added by Audit 050, 2026-08-21**

---

## What this is

A short, written list of **minimum requirements** — the floor, not the ceiling — for
SnapSmack's CMS architecture and image handling, drawn from accepted practice in the
wider software world. It exists so "it looks fine" is never again the only test.

**Use it as a pre-flight checklist.** For each item mark **MEETS** / **GAP** / **FAILS**
/ **N/A** (with a reason). Run it against every release, and against any new feature that
touches uploads, the database, logins, or admin pages. Confirm against the **live
server** (php.ini, Apache vs nginx) before trusting a "MEETS" — the running config can
change the real answer. Can't confirm? Mark it "unconfirmed", don't call it MEETS.

**Severity:** CRITICAL = a stranger could take over the site or steal all data ·
HIGH = damage data / hijack a logged-in user / run code · MEDIUM = needs some access
or luck · LOW = hardening / defense-in-depth.

---

## Method — how a review is actually run (added v1.1, from Audit 050)

Audit 050 exposed a process failure, not just a code gap: asked to *compare the
architecture against similar platforms*, the review answered from internal knowledge,
found nothing, and called the system sound — while the CMS had no post object at all,
a thing every mainstream CMS has. A checklist written from the same internal knowledge
carries the same blind spot. These rules force external grounding and make skipping it
visible.

- **M1 · A comparative review produces a cited MATRIX, not a verdict · CRITICAL
  (process).** When the ask is "compare our architecture to similar platforms / where do
  we deviate from best practice," the deliverable is a table: **rows** = architecture
  dimensions (content unit, media model, taxonomy, revisions/history, routing, auth &
  capability model, federation); **columns** = *named* comparators (e.g. WordPress,
  Ghost, Drupal, Koken, Pixelfed); **cells** = how each does it *with a source*, how we
  do it, the deviation, and whether the deviation is justified. **No matrix = the task is
  not done.** Do not answer from memory — pull the comparators' own docs/schema and cite
  them.
- **M2 · Deviation register + done-gate · CRITICAL (process).** Every departure from the
  mainstream pattern is logged and labelled **deliberate / accepted-debt / defect**, with
  a reason. A comparative or architecture review may **not** be marked complete — and may
  **not** report "code is clean" — until the matrix and register exist and every deviation
  is dispositioned. A code review answers *is it correct?*; an architecture review must
  answer *is the model even defined, and where do we deviate from how mature platforms
  build this?*
- **M3 · Honesty flag · HIGH (process).** If a review did not consult external sources, it
  must say so — that reads as **INCOMPLETE**, not "clean." New checklist items (Parts
  A–D) are seeded from what the comparison shows mature platforms guarantee, so this
  standard grows from evidence, not from the reviewer's assumptions.

---

## Part A — CMS architecture & web security

- **A1 · Bound DB parameters · CRITICAL.** Every query hands user text to the database
  separately from the command (prepared statements). No user value is glued into SQL.
- **A2 · Output escaping · HIGH.** Everything a user typed is escaped when printed into a
  page (`htmlspecialchars`). Look hardest at comments, forum posts, captions, alt text,
  usernames, and any URL built from `HTTP_HOST`/`REQUEST_URI`.
- **A3 · CSRF on state changes · HIGH.** Every action that changes data checks a secret
  per-session token. Any endpoint that turns the check off (`csrf_exempt`) must be a real
  cross-origin/API endpoint, not an admin form.
- **A4 · Auth + capability on admin pages · CRITICAL.** Each admin page checks, at the
  top, that you are (a) logged in and (b) allowed to do *this specific thing*. Editors
  cannot reach admin-only actions or set their own role.
- **A5 · Modern login handling · CRITICAL.** Passwords hashed with bcrypt/argon (never
  md5/sha1); login throttled against guessing; new session ID on login; cookies
  HttpOnly + Secure + SameSite; reset tokens long, random, expiring, single-use, and
  stored hashed.
- **A6 · Per-install secrets · HIGH.** Keys and salts are generated randomly per install,
  not committed to source or shared across installs. No hardcoded credentials.
- **A7 · Signed updates · CRITICAL.** Release packages are cryptographically
  signature-verified before being applied, over HTTPS with certificate validation. The
  check fails closed (a bad or unsigned package is rejected, not accepted).
- **A8 · No error info disclosure · MEDIUM.** Visitors never see PHP errors, stack
  traces, SQL, or file paths. `display_errors` off in production; no endpoint echoes raw
  exception messages.
- **A9 · Direct-access guards on includes · MEDIUM.** Include-only files refuse to run
  when hit directly (a `defined('SNAPSMACK')` guard in the PHP itself), not relying only
  on a web-server rule that a misconfigured/nginx host may ignore.
- **A10 · Security headers · MEDIUM.** `X-Frame-Options`, `X-Content-Type-Options:
  nosniff`, `Referrer-Policy`, `Strict-Transport-Security` (HSTS), and a
  `Content-Security-Policy`, delivered in a way that reaches upgraded installs.
- **A11 · No dangerous functions on user input · CRITICAL.** `eval`, `system`, `exec`,
  `shell_exec`, `passthru`, `popen`, `proc_open`, `assert`, `create_function`,
  `unserialize` on user data, `extract` on request data — absent, or provably never fed
  attacker-controlled input.
- **A12 · Protected-paths list · HIGH.** The updater never overwrites config, keys,
  uploads, backups, or `.htaccess`. New secret-bearing files are added to the list **and**
  guarded with `if (!defined())` in a file that actually ships (protected files never
  reach existing installs).
- **A13 · Security event logging · LOW.** Logins (success + failure), bans, and admin
  setting changes leave an audit trail.

## Part B — Image handling

- **B1 · Content-based type check · CRITICAL.** Type is confirmed from the file's real
  bytes (`getimagesize`/`finfo`), never the filename extension or the browser's claim.
- **B2 · Type allowlist · HIGH.** Only an allowlist of real image types is accepted;
  everything else rejected. No blocklists.
- **B3 · No execution in upload dirs · CRITICAL.** A file in an upload folder can never be
  run as code — every upload dir (`img_uploads/`, `media_assets/`, `assets/img/`) has a
  PHP-execution guard written by the installer **and** Repair **and** recovery, using
  modern syntax; or files are stored outside the web root.
- **B4 · Filename sanitise / no traversal · HIGH.** Stored names are regenerated (slug/
  MIME-derived), no client path or `../` survives, no double-extension.
- **B5 · Pre-decode size AND dimension limits · HIGH.** File size *and* pixel count are
  checked before the image is decoded, so a small file claiming huge dimensions can't
  exhaust memory (decompression bomb).
- **B6 · SVG rejected or sanitised · HIGH.** If SVG is accepted at all, it's sanitised of
  scripts and never served in a way a browser will execute (no direct-navigable inline
  `image/svg+xml`).
- **B7 · Correct serve type + nosniff · MEDIUM.** Images served with the right
  `Content-Type` and `X-Content-Type-Options: nosniff`.
- **B8 · ImageMagick policy (if used) · MEDIUM.** If ImageMagick is used, dangerous
  coders (MSL, MVG, URL, ghostscript) are disabled. N/A if GD-only.
- **B9 · EXIF/GPS preservation is by design · N/A.** Keeping embedded metadata is a
  deliberate product decision for a photographer's archive — **never** flag it as a bug
  or strip it by default. Any "strip location" option is offered as an explicit
  photographer choice, never forced.
- **B10 · Safe derivative generation · LOW.** Thumbnails/resizes are re-encoded through
  GD (which drops hidden payloads), and a failed generation never leaves a broken or
  mis-typed file served as an image.

## Part C — Delivery (added by Audit 049)

- **C1 · Fixes must actually reach installs — and be confirmed live · HIGH.** A security
  fix is not "done" until it is **delivered and live**. The requirement is track-agnostic:
  *no install may silently run unpatched code under a green "up to date" light.* This is
  satisfied by keeping the whole fleet on **one** live update track that actually receives
  security builds — **not** by maintaining parallel tracks (Sean's standing decision is to
  retire dual-track and move the fleet to the live track). Know which track each install
  is on; if any lags on an abandoned track, bring it onto the live one and retire the
  stale one. **Corollary (learned from 048/049):** never mark a header or fix as met on
  *code presence* — code shipped into a protected file the updater doesn't deliver, or a
  header never confirmed with a live request, is not "live." Confirm on the running server.

## Part D — Data model & content invariants (added by Audit 050)

- **D1 · One publishable-unit model · CRITICAL (architecture).** There is a single
  enforced definition of "a published item." Enumerate **every** write path that creates
  published content — solo (SMACKONEOUT), gram (GRAMOFSMACK), longform, import
  (Unzucker/FLKR/…), and the API — and confirm they all converge on that one model. No
  entity may be stored two ways (e.g. a photo as a bare `snap_images` row in one path and
  a `snap_posts` record in another). *This is the check that would have caught the
  missing post object.*
- **D2 · One keying scheme per relationship · HIGH.** Engagement and identity — comments,
  likes, reactions, collections, federation/actor id — each key to exactly one thing, and
  it is documented. A relationship is never keyed to the image id in one place and the
  post id in another.
- **D3 · Integrity checks model real reads · HIGH.** Any orphan / consistency / migration
  check must define "valid" the way the code actually **reads** the table, not how you
  assume it is keyed; state the read path it mirrors. *(Learned from the 050 orphan sweep,
  which mis-flagged ~1,904 valid comments under a posts-only assumption.)*
- **D4 · Deviations logged, not discovered later · HIGH.** Every place the model departs
  from the comparative baseline (see **Method / M2**) is in the deviation register,
  labelled deliberate / accepted-debt / defect, with a reason.

---

## Scoring template (copy per release)

| ID | Requirement | Sev | Verdict | Notes |
|----|-------------|-----|---------|-------|
| A1 | Bound DB parameters | CRIT | | |
| A2 | Output escaping | HIGH | | |
| A3 | CSRF | HIGH | | |
| A4 | Admin auth + capability | CRIT | | |
| A5 | Modern login | CRIT | | |
| A6 | Per-install secrets | HIGH | | |
| A7 | Signed updates | CRIT | | |
| A8 | No error disclosure | MED | | |
| A9 | Direct-access guards | MED | | |
| A10 | Security headers | MED | | |
| A11 | No dangerous funcs | CRIT | | |
| A12 | Protected paths | HIGH | | |
| A13 | Security logging | LOW | | |
| B1 | Content type check | CRIT | | |
| B2 | Type allowlist | HIGH | | |
| B3 | No exec in upload dirs | CRIT | | |
| B4 | Filename sanitise | HIGH | | |
| B5 | Pre-decode size+dimension | HIGH | | |
| B6 | SVG safe | HIGH | | |
| B7 | Serve type + nosniff | MED | | |
| B8 | ImageMagick policy | MED | | |
| B9 | EXIF by design | N/A | | |
| B10 | Safe derivatives | LOW | | |
| C1 | Fixes reach installs | HIGH | | |
| M1 | Comparative matrix (cited) | CRIT | | |
| M2 | Deviation register + done-gate | CRIT | | |
| M3 | External-sources honesty flag | HIGH | | |
| D1 | One publishable-unit model | CRIT | | |
| D2 | One keying scheme per relationship | HIGH | | |
| D3 | Integrity checks model real reads | HIGH | | |
| D4 | Deviations logged | HIGH | | |

*Living standard — bump the version and date when requirements change.*

<!-- ===== SNAPSMACK EOF ===== -->
