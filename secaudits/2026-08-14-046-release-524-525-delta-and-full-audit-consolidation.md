<!-- SNAPSMACK_EOF_HEADER: the last non-empty line must be the canonical HTML EOF marker. -->

# Security Audit 046 — 524→525 release-delta review + full-audit consolidation

- **Date:** 2026-08-14
- **Auditor:** Claude (Opus 4.8), multi-agent + manual review
- **Scope of record:** everything from this session's security work, in one report:
  - **Part A** — line-by-line review of the actual shipped delta between `v0.7.524D` and `v0.7.525D` (the real release surface).
  - **Part B** — the broader 8-dimension audit that was run this session, with an important caveat about the branch it targeted.
- **Release this attaches to:** 0.7.526.

---

## Part A — 524 → 525 delta review (the release surface)

### Method
Reviewed the complete code diff `git diff v0.7.524D v0.7.525D` (649 lines across 21 code files, excluding marketing pages under `projects/snapsmack-ca/`, `smack-help.php`, and tests). Each non-cosmetic hunk was traced from input to sink.

### Result: **NO FINDINGS**

Roughly 95% of the delta is a cosmetic label rename — **SMACKVERSE → "Fediverse"** — across page titles, headings, status pills, and flash messages. Pure display strings; no logic, routing, auth, or query changes. The internal state key stays `smackverse` (confirmed by the sidebar comment "internal key stays 'smackverse' for JS/state"), so no data or route behavior shifts.

The only substantive code changes, each reviewed and cleared:

| Change | Files | Assessment |
|--------|-------|------------|
| `snapsmack_gram_ensure_post_columns()` — `ALTER TABLE snap_posts ADD COLUMN IF NOT EXISTS` schema self-heal for the `fedi_*` / sensitivity columns | `core/gram-client-authoring.php`, called from `core/threeacross-api.php` and `pixelfed-api.php` | **Safe.** Column definitions are hardcoded literals — no user input reaches the DDL, so no injection. Runs behind the same auth/write-scope gates as the endpoints that call it (`px_require_scope($token,'write')` on the Pixelfed path; the gram/post gate on threeacross). Correctly placed *before* `beginTransaction()` because ALTER implicit-commits. Requires the DB user to hold ALTER — a pre-existing self-healing-schema design choice, not introduced here. |
| `unbury_batch` admin action — `UPDATE snap_posts SET sort_order=0 WHERE sort_order>0 AND (slug LIKE 'sob-%' OR slug LIKE 'pixelix-%')` | `smack-lt-gram.php` | **Safe.** Prepared statement with hardcoded LIKE literals (no user input in SQL). Admin page — POST is covered by the global `csrf_check()` in `core/auth.php` + the auto-injected CSRF field. The reflected `?unburied=` value is `(int)`-cast before echo → no XSS. Idempotent maintenance action. |
| `sort_order` seating of new gram posts changed from `1` back to `0` | `core/gram-client-authoring.php` | Pure feed-ordering fix (the "new posts not at top" regression). No security impact. |

**Conclusion (Part A):** the 524→525 release introduced no security-relevant defects. The delta is a rename plus two safe DB self-heal calls and a feed-order fix.

---

## Part B — Full 8-dimension audit (context + caveat)

Earlier this session an 8-dimension multi-agent audit (auth/session, updater/signing, public-input injection, upload/RCE, multisite key custody, access control, core DB/crypto, skins/tools) with adversarial verification was run. **It was performed against branch `claude/audit-or-security-test-41d53b`, which sits on the 0.7.124 lineage — ~400 versions behind live `dev` (0.7.525), with divergent history from the `recovered-dev`/`recovered-main` incident.**

Because of that, its output **does not describe the running codebase** and must not be treated as live findings:

- Several items are **already fixed on dev** — e.g. the multisite `sso-token` endpoint is role-gated to the hub on 0.7.525 (dev even carries a comment noting an earlier duplicate unguarded handler was removed).
- The files it patched are structurally different on dev (e.g. `smack-media.php` line numbers and surrounding logic differ substantially).
- The fixes it produced were committed on the stale branch only and **cannot be released** (they would revert ~400 versions; the version they carried, 0.7.125, is long shipped).

### Findings from that pass, as a re-verification backlog against dev
These are worth re-checking against 0.7.525 — some may survive, some are already closed. **None are confirmed against live code:**

- Multisite: hub/spoke trust on the symmetric `api_key_local`; roster distribution of peer keys; `backup/export` and `posts/create` authorization; SSRF in cross-post fetch. (sso-token already gated on dev.)
- Auth: parity of brute-force / IP-ban protection between `login.php` and `snap-in.php`; 2FA online-guessing resistance.
- Upload/RCE: media-library extension allowlisting and `media_assets/` PHP execution; SVG handling.
- Crypto: whether `download_salt` is provisioned per-site (FTP/cloud credential encryption key) or falls back to a shipped constant.
- Access control: role enforcement on user-management pages; CSRF on GET-based delete/suspend actions.
- Updater/skins: signing enforced-vs-advisory by default; skin-signature fail-open.
- Housekeeping: `.gitignore` covering tool config by wildcard vs per-filename; installer self-deletion robustness.

### Recommendation
The correct next security pass is a **focused re-verification of the backlog above against `origin/dev`** (or against each future release delta, as done in Part A — which is the cheaper, higher-signal cadence). Auditing per-release-delta going forward keeps the surface small and the findings real.

---

## Bottom line
- **Shipping surface (524→525): clean, no findings.** Safe to release 0.7.526.
- The stale-branch audit is retained here as a re-verification checklist only, explicitly **not** live findings.
- Going forward, run security review on each release delta rather than against snapshots of unknown currency.

<!-- ===== SNAPSMACK EOF ===== -->
