# Secaudit 049 — Steps Forward (DRAFT for review)

**Status:** DRAFT v0.2 — reconciled by Claude Code (terminal) + web-Claude. Awaiting
**Codex** (independent leg) and **Sean** (live facts + the dual-track call). Nothing
committed.
**Date:** 2026-08-17 · **Basis:** audit 049 against `dev` @ 0.7.533.

> **JOINT VERDICT (Claude Code + web-Claude), before Codex/Sean:**
> The audit's code review holds and it caught the delivery gap that matters. **Three
> corrections were agreed and already applied to the 049 files:**
> 1. **Standard C1 reworded track-agnostic.** The original baked a permanent "publish to
>    BOTH tracks" mandate into the reusable standard — that reversed Sean's documented
>    decision to *retire dual-track and put the whole fleet on the live track*. C1 now
>    reads: "no install may silently run unpatched code under a green up-to-date light,"
>    satisfied by one live track. Finding 1's remedy changed from "cut a stable catch-up
>    + both-tracks policy" to "converge the fleet onto the live track; if a spoke is stuck,
>    one bridge build then retire the stale track."
> 2. **A10 (HSTS/CSP) downgraded MEETS → [confirm live].** It was met on *code presence*,
>    which is exactly Finding 1's trap: 048 left the post-529D live header check open, no
>    record it passed, CHANGELOG still says "CSP deferred." Code ≠ delivered-and-live.
> 3. **Cover-note overstatements corrected** — the files (B3 = GAP, A10 = unconfirmed) are
>    the source of truth.
> **Not a sign-off.** web-Claude and I are the *same model* — one set of blind spots.
> **Codex on the two real (corrected) files is the independent leg.**
> **Four live facts gate the rest (Sean's to confirm):** (1) is the whole fleet already on
> dev? (2) did the post-529D live HSTS/CSP header check ever pass? (3) is every live origin
> Apache 2.4 without `mod_access_compat`? (4) how low is the bar to a write-scoped OAuth
> token (open client registration)? **One code fix ships regardless:** the `img_uploads/`
> guard's legacy `Order Deny,Allow` is dead on Apache 2.4 w/o mod_access_compat — move to
> `Require all denied` + `php_flag engine off`.

**How to use this doc:** each item has a **Proposal**, its **Risk**, how it would
**Ship**, and a **Decision/So-far** line. Reviewers: add your initials + a note under
each — **agree / disagree / amend / needs-info**. Sean routes it between us. Web Claude
and Codex don't have to agree with me; the point is to converge.

---

## 0. Ground rules we should all hold to
- **Verify live before acting.** Two facts must be confirmed on the running server first:
  (a) which real installs are on the **stable** track vs **dev**; (b) **Apache vs nginx**
  at the origin. Several findings change severity based on these.
- **Delivery model:** fixes ship via **release + updater only** (Sean can't FTP). Any new
  secret-bearing file must be added to `protected_paths.json` **and** guarded with
  `if (!defined())` in a file that actually ships (protected files never reach existing
  installs).
- **Do no harm:** prefer additive fixes that copy patterns already in the tree; hold
  anything that could lock Sean out or lose data until it's designed + reviewed.

---

## 1. THE PRIORITY — stable track is frozen (delivery gap) · HIGH (maybe CRITICAL)
**What:** `latest-dev.json` = 0.7.533D (today); `latest.json` (stable/BORING) = **0.7.456,
2026-07-30**. Every 046/047/048 hardening fix shipped **dev-only**. Stable-track installs
have received **none** of it while reporting "up to date."
**Proposal (revised per joint verdict — supersedes the old both-tracks wording):**
(a) confirm whether every real install is already on the **dev/live** track; (b) if any
spoke is stuck on the abandoned stable track, bring it onto the live track with a **single
bridge build**, then **retire dual-track** (Sean's standing decision); (c) do **not** adopt
a permanent both-tracks policy. The principle is track-agnostic: no install silently runs
unpatched code under a green "up to date" light.
**Risk:** if a bridge build is needed, blessing 0.7.533 as that build needs confidence
it's release-worthy — but if the fleet is already all on dev, no build is needed at all.
**Ship:** at most one bridge release in the Release Packager, then single-track going fwd.
**Open questions for the group:**
- Straight promotion of 0.7.533 to stable, or a curated security-only subset onto the
  0.7.456 base?
- Version numbering for the stable cut?
- Is there a reason stable was intentionally held at 0.7.456 (feature gating?) we should
  know before advancing it?
**Decision/So-far:** _unset — needs Sean (live inventory) + reviewer input._

---

## 2. `display_errors` + raw `getMessage()` on 7 public pages · MEDIUM
**What:** index/archive/albums/blogroll/page/privacy-policy/process-comment force
`display_errors` on and print the raw exception in catch blocks → visitors can see SQL,
paths, table names.
**Proposal:** default `display_errors` **off** (gate behind a debug flag, e.g.
`SNAPSMACK_DEBUG`), and return a generic message instead of `$e->getMessage()`.
**Risk:** very low; production should never show errors. Confirm live php.ini isn't
already overriding (if it is, this drops to LOW).
**Ship:** normal dev release.
**Decision/So-far:** _proposed; looks safe to land on dev now._

---

## 3. No decompression-bomb / pixel cap before decode · MEDIUM
**What:** no path checks width×height before `imagecreatefrom*`; a small file claiming
huge dimensions can OOM a worker. Broadest exposure: the **pixelfed-api** media endpoint
(any write-scoped OAuth token) + smackpress-api; the rest are admin-only.
**Proposal:** after `getimagesize`, reject `w*h > N` megapixels before decoding.
**Risk:** **must not reject Sean's real photos** — he shoots high-res; a low cap breaks
legitimate uploads. **Decision needed: the real max megapixel count** before we set N.
**Ship:** dev release, once N is agreed.
**Decision/So-far:** _blocked on Sean's real max-MP number._

---

## 4. SVG logo/favicon stored unsanitized · LOW (admin-only)
**What:** `smack-globalvibe.php` accepts SVG for logo/favicon, stores raw; direct
navigation to the file runs any embedded script (admin self-XSS; no `script-src` in CSP).
**Proposal (pick one):** sanitize SVG on upload · serve with
`Content-Disposition: attachment` · or convert to PNG on upload.
**Risk:** rejecting/altering SVG could break an admin's chosen logo — that's why it's a
choice, not a silent change.
**Decision/So-far:** _need the group's pick among the three options._

---

## 5. Upload-dir execution guards are uneven · LOW (defense-in-depth)
**What:** `media_assets/.htaccess` only written by the Media Library on page load (not
installer/repair/recovery); `img_uploads/` guard uses legacy Apache-2.2 syntax; `assets/img/`
never guarded. All Apache-only.
**Proposal:** standardize on modern syntax (`Require all denied` + `php_flag engine off`),
write it from installer **and** Maintenance→Repair **and** recovery-engine, and cover
`media_assets/` + `assets/img/`.
**Risk:** low, additive.
**Decision/So-far:** _proposed for dev._

---

## 6. Core includes rely on `.htaccess`, not a PHP guard · LOW
**What:** only `core/smackback.php` has a `defined('SNAPSMACK')` guard; the rest lean on
the `.htaccess` deny list (evaporates on nginx).
**Proposal:** add the guard to include-only `core/*.php`. **Caution:** several `core/*.php`
are *intentionally* direct endpoints (multisite-api, ohsnap-api, etc.) — those must NOT
get the guard. Needs a careful file-by-file pass, not a blanket sed.
**Decision/So-far:** _proposed, but flagged as needing care; maybe defer._

---

## 7. Community session tokens stored plaintext at rest · LOW
**Proposal:** hash community session tokens at rest (like the admin reset/TOTP tokens).
Mesh keys are plaintext by design (presented outbound) — leave those.
**Decision/So-far:** _proposed; low priority._

---

## Proposed sequence (revised per joint verdict)
1. **Live-confirm the four facts** (fleet-on-dev?; post-529D header check passed?; Apache
   2.4 w/o mod_access_compat?; OAuth-token bar). *(Sean)*
2. **Codex** independently reviews the two corrected 049 files (the independent leg).
3. **Delivery gap:** converge any stale-track spoke onto the live track (one bridge build
   if needed), then **retire dual-track** — not a standing both-tracks policy.
4. **Land the safe code fixes** on the live track: #2 (errors), the `img_uploads/`
   legacy-syntax dead-guard (ships regardless), #5 (guard coverage), #7 (tokens), #4 (SVG
   once option chosen).
5. **#3 pixel cap** once Sean gives the max-MP number.
6. **#6** only after a careful per-file pass (or defer). **A10** stays unconfirmed until a
   live header curl.

## Open questions for Codex (the independent leg)
- Challenge any GREEN scorecard item — Claude Code + web-Claude are one model and did NOT
  independently re-derive A1/A5/A6/A7/A11/B1/B4 from source; those are plausible-but-unblessed.
- Is the delivery-gap framing right now that C1 is track-agnostic and the remedy is
  "converge + retire dual-track"?
- Any finding we're missing, or severity we've mis-set (esp. B5 requirement=HIGH vs
  Finding-3 instance=MEDIUM)?

---

### Reviewer notes
- **web-Claude:** Reviewed the real bytes. Verdict = strong audit, three edits before
  trustworthy (C1 reword, A10 un-MEET, cover-note fixes) — all now applied. NOT a sign-off;
  same model as Claude Code. Killed two predecessor worries: Finding 3 is authed (not
  pre-auth), Finding 5 SVG is `<img>`-served (not inlined). Full verdict in the project note
  `session-continuity-2026-08-17-049-review-verdict.md`.
- **Claude Code:** Agreed all three edits and applied them. Aligned with web-Claude on the
  two self-corrections. Awaiting Codex + Sean.
- **Codex:** _pending — please review the two corrected 049 files directly._

<!-- SNAPSMACK EOF -->
