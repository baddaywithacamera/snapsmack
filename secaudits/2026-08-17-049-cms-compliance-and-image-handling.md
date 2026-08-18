# SnapSmack Security Audit 049 — CMS compliance & image handling

**Target:** `dev` branch, **v0.7.533** ("PHOTO FRIYAY") · **Date:** 2026-08-17
**Method:** local static review by three parallel reviewers (image pipeline · web-app
primitives · infrastructure), scored against a written minimum-requirements standard
(companion file `2026-08-17-049-cms-compliance-standard.md`).

---

## Read this first

- This is a **local code review of current dev (0.7.533)** — the live-current code, not
  a stale branch. Items that depend on server config (Apache vs nginx, php.ini) are
  marked **[confirm live]**.
- **Nothing was changed.** Audit only.
- **Headline:** the code itself is in good shape. Audits 035/039/040/**047/048** clearly
  did their job — SQL injection, XSS, CSRF, login/session, admin permissions, update
  signing, secret handling, and upload safety all **pass** on dev. The single biggest
  problem this audit found is **not in the code at all — it's in delivery** (Finding 1).

---

## The one that matters most

### Finding 1 · Stable track frozen — DOWNGRADED to LOW / process-risk (fleet confirmed on dev) · [Sean-confirmed 2026-08-17]

> **CORRECTED (Sean, 2026-08-17) — my first read was wrong.** Sean runs **26 live
> production sites, all on the dev track.** "All on dev" does NOT mean "safe." It means:
> (a) delivery is not guaranteed — the live header check below proves it, foundtextures.ca
> sends no HSTS/CSP while photofri.day does; and (b) every finding that IS delivered on dev
> is **live across all 26 production sites right now** — chiefly the `display_errors` + raw
> error leak (Finding 2, delivered inline so it runs fleet-wide), the pixel-bomb (Finding
> 3), and per-site header gaps. So the real exposure is NOT the abandoned stable track
> (moot — nobody's on it); it's that **26 production sites ride the fast-moving, unvetted
> dev track and carry the delivered findings fleet-wide, delivery proven uneven.** This is
> an ACTIVE posture, not a process footnote. Actions: (1) sweep security headers across all
> 26 sites; (2) ship the safe fixes — the error leak first — to the whole fleet; (3) decide
> the posture: 26 production sites on the unvetted dev track is itself a risk (add a
> stabilize-and-verify gate before fleet rollout, or accept dev-as-prod with tight
> finding-closure). The frozen `stable`/`main` build (77 versions behind) remains a
> separate buildable landmine — guard against building/checking-out from it.

- **Dev track** (`latest-dev.json`): **0.7.533D**, today.
- **Stable track** (`latest.json` / "BORING"): **0.7.456**, from **2026-07-30**.

Every hardening fix from audits 046/047/048 — the editor→admin lockout, the
`download_salt` fix that protects saved FTP passwords, enforced update signing, the
HSTS/CSP headers, the `media_assets` execution guard — shipped as **dev (D) builds
only**. They publish to `latest-dev.json`. A site on the **stable** track only sees
`latest.json`, which is frozen at 0.7.456 (July 30) — so it has received **none** of
it, while its updater truthfully reports "up to date."

**Why this is the top finding:** a fix that never reaches the install is not a fix. In
compliance terms this is a broken **patch-delivery pipeline** — the most-hardened code
in the world doesn't matter if production runs a 77-version-old build. If any
internet-facing install is on the stable track, it is currently exposed to
already-fixed CRITICAL issues (editor escalation, plaintext-recoverable FTP creds).

**What to confirm live:** which real installs are on `stable` vs `dev`
(`update_track`). If any live/public site is on stable → this is **CRITICAL** for that
site. If everything real is already on dev → it drops to a process risk.

**The fix is an operational decision, not code — and it defers to Sean's standing call to
retire dual-track and keep the whole fleet on the single live (dev) track.** So the remedy
is NOT a permanent both-tracks policy (an earlier draft said that; corrected). Instead:
(a) confirm whether every real install is already on dev; (b) if any spoke is stuck on the
abandoned stable track, bring it onto the live one with a **single bridge build**, then
retire the stale track. The compliance principle is track-agnostic: *no install may
silently run unpatched code under a green "up to date" light.* Severity gates on the live
fact — if the whole fleet is already on dev, this collapses to a process risk.
*(Correction credited to web-Claude's 049 review: my first draft baked a both-tracks
mandate into standard C1, which reversed Sean's documented decision.)*

---

## Scorecard — 23 minimum requirements

Verdict: **MEETS** ✅ · **GAP** ⚠️ · **FAILS** ❌ · **N/A**. Full definitions in the
companion standard.

| ID  | Requirement                        | Sev | Verdict | Note (dev 0.7.533) |
|-----|------------------------------------|-----|---------|--------------------|
| A1  | Bound DB parameters                | CRIT | ✅ MEETS | Real prepared stmts, `EMULATE_PREPARES=false`; no concatenated user SQL |
| A2  | Output escaping / XSS              | HIGH | ✅ MEETS | Comments/forum/meta all escaped; canonical URL fixed in 047 |
| A3  | CSRF on state changes              | HIGH | ✅ MEETS | Strong engine; GET-variant added (047); 3 exempt callers all justified (Bearer APIs) |
| A4  | Auth + capability on admin pages   | CRIT | ✅ MEETS | Two-layer gate: central denylist + `smack_require_admin()` (047). Editor can't escalate |
| A5  | Modern login handling              | CRIT | ✅ MEETS | bcrypt-12, `session_regenerate_id`, 5/10min→7-day ban, hashed single-use reset tokens |
| A6  | Per-install secrets                | HIGH | ✅ MEETS | `download_salt` randomly minted + self-heal migration (047); no hardcoded secrets |
| A7  | Signed updates                     | CRIT | ✅ MEETS | Ed25519 enforced, fails closed; HTTPS w/ peer verify; key-rotation root-signed |
| A8  | No error info disclosure           | MED  | ❌ FAILS | Public pages force `display_errors` on AND echo `$e->getMessage()` (Finding 2) |
| A9  | Direct-access guards on includes   | MED  | ⚠️ GAP  | Only `smackback.php` has a PHP guard; rest rely on `.htaccess` allowlist (Finding 6) |
| A10 | Security headers (+HSTS +CSP)      | MED  | ❌ FAILS fleet-wide (swept 26 sites 2026-08-17) | **Only 9/26 sites send HSTS+CSP; 17/26 send NEITHER** — including the hub (foundtextures.ca) and photoblogs.fyi. Code exists but header delivery (skin-routed) is patchy — the 048 delivery-defect class, now measured. Fix: HSTS via Cloudflare edge (fast, fleet-wide); CSP app/skin-side (find why 9 emit it and 17 don't). Full table in appendix |
| A11 | No dangerous funcs on user input   | CRIT | ✅ MEETS | No eval/system/unserialize/extract on request data; shell only in offline build tools |
| A12 | Protected-paths list               | HIGH | ✅ MEETS | Present; new controls routed via skin-manifest to reach frozen paths |
| A13 | Security event logging             | LOW  | ✅ MEETS | Login fails, bans, admin actions logged |
| B1  | Content-based type check           | CRIT | ✅ MEETS | finfo/getimagesize on primary paths; one path uses ext-allowlist (Finding 5, mitigated) |
| B2  | Type allowlist                     | HIGH | ✅ MEETS | Raster-only allowlists everywhere |
| B3  | No execution in upload dirs        | CRIT | ⚠️ GAP  | Guards exist but coverage uneven + legacy Apache-2.2 syntax (Finding 4) |
| B4  | Filename sanitise / no traversal   | HIGH | ✅ MEETS | Names regenerated from MIME; no client path used; no double-extension |
| B5  | Pre-decode size/dimension limits   | HIGH | ❌ FAILS | Byte-size capped but **no pixel-count cap** before GD decode (Finding 3) |
| B6  | SVG rejected or sanitised          | HIGH | ⚠️ GAP  | SVG logo/favicon stored unsanitised (admin-only) (Finding 5) |
| B7  | Correct serve type + nosniff       | MED  | ✅ MEETS | `download.php` sets real type + nosniff + attachment; global nosniff |
| B8  | ImageMagick policy (if used)       | MED  | ✅ N/A   | GD only — no ImageMagick anywhere |
| B9  | EXIF preservation = by design      | N/A  | ✅ MEETS | Deliberate; re-encode paths explicitly preserve it — correct, not a defect |
| B10 | Safe derivative generation         | LOW  | ✅ MEETS | Thumbnails re-encoded through GD (polyglot-defence) |

**Tally: 17 MEETS · 3 GAP · 1 [confirm live] · 2 FAILS · (1 N/A).** Plus the delivery gap
(Finding 1), which sits outside the code scorecard but is the most important item.
*(A10 moved MEETS → [confirm live] after web-Claude's review — see A10 note.)*

---

## Findings (code) — worst first

### Finding 2 · Public pages leak errors to visitors — MEDIUM
Every public entry point forces `display_errors` on and then prints the raw exception
in its catch block:
`index.php:21-22` + `:485` (`die("GATEWAY_HALT: ".$e->getMessage())`); `archive.php:17-18`+`:531`;
`albums.php:27-28`+`:161`; `blogroll.php:17-18`+`:103`; `page.php:17-18`+`:84`;
`privacy-policy.php:18-19`+`:56`; `process-comment.php:17-18`+`:127`.
A DB hiccup or malformed request shows a stranger raw SQL, table names, and absolute
server paths — reconnaissance. **Not closed by 046–048.** Fix: default `display_errors`
off (gate behind a debug flag), and return a generic message instead of `getMessage()`.

### Finding 3 · No decompression-bomb / pixel limit before decode — MEDIUM
No path checks width×height against a pixel budget before `imagecreatefrom*`. A ~1 MB
30000×30000 PNG passes the byte-size gate, then GD allocates gigabytes → worker crash
(one-request DoS). `core/image-ingest.php:305` (and it raises `memory_limit` to 512M at
:219), `smack-post-solo.php:362`, `core/photo-editor-save.php:55`, `pixelfed-api.php:211`,
`smack-swap.php:91`, `core/thumb-generator.php:76`, `backfill-thumbs.php:92`.
Broadest reach: the **pixelfed-api media endpoint** (any write-scoped OAuth token) and
`smackpress-api.php:268` — the rest are admin-only. Fix: after `getimagesize`, reject
`w*h > ~40MP` before decoding. **Set the cap high enough for your real files** (medium-
format 100MP+ exists) — agree the number rather than guessing.

### Finding 4 · Upload-dir execution guards are uneven — LOW · [confirm live]
Defense-in-depth only (no path writes a `.php` name anymore, so low risk):
- `media_assets/.htaccess` is written only by `smack-media.php` on page load — the
  installer, Maintenance→Repair, and recovery-engine write only `img_uploads/.htaccess`.
  Fresh install = `media_assets/` unguarded until the Media Library is first opened.
- `img_uploads/` guard uses legacy Apache-2.2 syntax (`Order Deny,Allow`), ineffective
  on Apache 2.4 without `mod_access_compat`. `smack-media.php` uses modern `Require all
  denied` + `php_flag engine off` — standardize on that.
- `assets/img/` is never guarded. All guards are Apache-only (nginx ignores `.htaccess`).

### Finding 5 · SVG logo/favicon stored unsanitised — LOW/MEDIUM · [confirm live]
`smack-globalvibe.php:117-155` accepts `.svg` for favicon + masthead, stores verbatim in
`assets/img/`, no sanitisation. Rendered safely in `<img>`/`<link>`, but the file is
directly reachable — navigating to `/assets/img/logo.svg` runs any embedded `<script>`
(stored XSS). Admin-only uploader, so it's effectively admin self-XSS, but an admin
could plant a link that fires in another admin's session. CSP doesn't set `script-src`,
so it wouldn't block this. Fix: sanitise SVG on upload, or serve it with
`Content-Disposition: attachment` / a restrictive CSP, or convert to PNG.
*(Related, minor: `core/image-ingest.php:222` gates on client extension not content —
mitigated by re-encode + safe stored name; align with its finfo-using siblings.)*

### Finding 6 · Core includes rely on `.htaccess`, not a PHP guard — LOW · [confirm live]
Only `core/smackback.php` has a `defined('SNAPSMACK')` guard; the rest depend on the
`.htaccess` deny-by-name list. On nginx or any host ignoring `.htaccess`, that list
evaporates. Real exposure is low (`db.php` doesn't echo), but it's a defense-in-depth
gap. Long-standing architecture choice, not a regression.

### Finding 7 · Some tokens stored plaintext at rest — LOW
Community session tokens (`core/community-session.php:129`) and multisite mesh keys
(`core/multisite-api.php:133`) are stored plaintext, unlike the (hashed) admin reset
and TOTP-trust tokens. Mesh keys are plaintext by design (presented outbound). A
DB-read would expose live community sessions — consider hashing those at rest.

### Info notes
- `smack-edit-user.php:67` writes `$_POST['user_role']` with no `['admin','editor']`
  whitelist — admin-gated so not an escalation, just a robustness nit.
- `pixelfed-api.php`/`smack-media.php`/`smack-swap.php` store raw original bytes (only
  thumbnails re-encoded) — non-executable given forced extension + PHP-deny; EXIF/GPS
  preservation here is deliberate and correct.

---

## What passes (protect it)
SQL injection (real prepared statements), output escaping, CSRF (engine + GET variant +
justified exemptions), login/session (bcrypt-12, regen, brute-force ban, hashed
single-use reset tokens), admin authorization (two-layer gate — **editor→admin is
blocked**), API keys (SHA-256 hashed, scoped, expiring), update signing (Ed25519
enforced, fails closed, HTTPS-verified), `download_salt` (minted + self-healing),
security-header *code* present (HSTS + CSP — but delivered-and-live is UNCONFIRMED, see
A10), no dangerous functions on user input, filename
sanitisation, content-based type checks, GD re-encode polyglot defence, recovery-kit
path guard, correct serving headers. Clear evidence of the 047/048 hardening throughout.

---

## Fix order (revised — fleet is on dev; 26 live sites)
1. **Header gap (A10) — measured, fleet-wide:** 17/26 sites send no HSTS/CSP. HSTS =
   fast Cloudflare-edge fix across all zones; CSP = find why 9 sites emit it and 17 don't
   (likely a skin recompile / re-save — the header emission is skin-routed and the 17 are
   mostly older, high-post sites on skins compiled before the header code landed).
2. **Finding 2 (error leak):** live on all 26. `display_errors` off by default + generic
   error messages on the 7 public pages. Small, safe, high value — ship to the fleet.
3. **Finding 3 (pixel cap):** cap before decode — agree the megapixel limit first.
4. **Findings 4–7:** defense-in-depth — standardize upload-dir guards (modern syntax,
   written by installer/repair/recovery, cover `media_assets/` + `assets/img/`), sanitise
   SVG, add PHP guards to core includes, hash community session tokens.
5. **Delivery posture:** decide whether 26 production sites should ride the unvetted dev
   track, and add a version-drift guard against the frozen `stable`/`main` landmine.

---

## Appendix — live header sweep, all 26 sites (2026-08-17, from curl)
All returned HTTP 200. `YES` = header present in the live response.

**HSTS + CSP PRESENT (9):** allinthewrist.photoblogs.fyi, dithering.pixhellated.ca,
iswa.ca, crapshoot.photoblogs.fyi, squared.pixhellated.ca, someshitifound.photoblogs.fyi,
swirlybokeh.photoblogs.fyi, usedcarparts.photoblogs.fyi, photofri.day.

**HSTS + CSP MISSING (17):** foundtextures.ca (hub), acolourlesslife.ca,
baddaywithacamera.ca, craptasti.ca, foreverphotograph.ing, hekeepsdroningon.ca,
hockneyjoiner.com, fauxlaroid.fyi, lightafterdark.ca, photowalk.ing, pixhellated.ca,
squaredstraight.ca, strathmore.pics, theschoolofhardnocks.ca, unzucked.ca,
wateronthebrain.ca, photoblogs.fyi (static/unfinished — treat separately).

*Observation:* the 9 present skew toward newer/empty (0-post) sites; the 17 missing skew
toward older, high-post sites — consistent with the header emission being **skin-compiled**
and the established sites never re-saved since the header code landed. Confirm before fix.

*Companion: `2026-08-17-049-cms-compliance-standard.md` (the reusable yardstick).
Local review; live header sweep is real, other [confirm live] items still pending.*

<!-- SNAPSMACK EOF -->
