<!-- SNAPSMACK_EOF_HEADER: the last non-empty line must be the canonical HTML EOF marker. -->

# SECAUDIT 048 — Live penetration test @ 0.7.527 (527D)

**Date:** 2026-08-15
**Target:** Live site **smackmeup.photoblogs.fyi** — a throwaway GRAMOFSMACK (carousel) install running tag `v0.7.527D` (SNAPSMACK_VERSION `Alpha 0.7.527`), the live **dev** track. Not connected to the hub.
**Method:** First **actual live penetration test** (SECAUDIT 036–047 were code review). Real attacks against the running server — unauthenticated (curl) and authenticated as a real **editor** account (`claude`, credentialed / assumed-breach). Where Cloudflare blocked the datacenter test IP from the login path, the editor session was driven through a real residential browser — which is also how a real attacker with the editor's password would arrive.
**Fixes:** all remediation shipped in **0.7.528** (tag `v0.7.528D`, commit `42c3cf1f`), except two LOW items deferred to 0.7.529.

> Plain-English summary: I tried to break into a real copy of the site the way a stranger — and a logged-in editor — actually would. I got in two serious ways that the earlier code review had missed, both now fixed. Most of what the last audit fixed held up under real attack.

## Severity roll-up

| # | Sev | Title | Proven | File(s) |
|---|-----|-------|--------|---------|
| 1 | CRITICAL | Leftover installer is an unauthenticated recovery/patch console | LIVE | install.php |
| 2 | CRITICAL | Bootstrap deployer re-arms after install.php deletion / redeploys over live site | LIVE | setup.php |
| 3 | HIGH | Stored XSS via post caption/ALT/title/tags in JSON-LD block (also reflected via request URL) | LIVE (executed) | core/meta.php |
| 4 | MED | Editor → admin: three config/repair pages missing from admin-only denylist | LIVE | core/auth-smack.php (smack-audit.php, smack-backfill.php, smack-stats.php) |
| 5 | LOW | No HSTS header | — | core/constants.php |
| 6 | LOW | No Content-Security-Policy | — | core/constants.php |
| 7 | LOW | Editor dashboard discloses server/infra info (PHP version, server software, disk, cron path, load) | LIVE | smack-admin.php |
| 8 | LOW | Password-reset request form has no CSRF token | LIVE | password-reset.php |

## What held up under live attack (verified, not assumed)

These are real attacks that **failed** — the defenses (mostly from SECAUDIT 047) worked against a live adversary:

- **Admin page auth:** every admin page (settings, users, media, disaster, post, 2FA) redirects an unauthenticated visitor to login. No content leak.
- **Sensitive files:** `.git`, `.env`, `*.sql`, `core/constants.php`, `migrations/`, schema dumps — all blocked (403/404). No directory listing.
- **Path traversal / LFI:** `?page=../../etc/passwd` and variants — all blocked.
- **Upload webshell (RCE):** a `.php` upload was **rejected**; a JPEG-header + PHP **polyglot** was stored but served `image/jpeg` and the PHP **did not execute** (confirmed by fetching the stored file). SECAUDIT 047 upload allowlist + forced extension + `media_assets/.htaccess` PHP-deny all hold.
- **Download links:** `download.php` uses `HMAC-SHA256(id, per-install download_salt)` with `hash_equals`, serves published images only; every forged/tampered token → 403; salt not leaked. SECAUDIT 047 `download_salt` fix holds.
- **CSRF on destructive actions:** media purge and post delete links carry a `t=` token — confirmed live.
- **Session cookie:** HttpOnly confirmed (invisible to JavaScript), so an XSS cannot steal the session.
- **Login:** rejects bad credentials cleanly; Cloudflare edge blocks the login path from datacenter IPs; direct `login.php` access blocked at Apache (forces the `/snap-in` route).
- **Password reset:** rate-limited and gives an identical response for a real vs. bogus email — no user enumeration.
- **Self-registration:** closed (community sign-in only, no public signup).
- **IDOR / horizontal access:** not applicable — single-actor install; the "users" are admin/editor operators, not content tenants; DMs are blog↔fediverse only (federation off, none stored).

## Not tested this pass
- **Federation inbox signature forgery** — federation is off on this empty site; the actor is not discoverable (webfinger 404). Re-test once a post is federated. (The keyId-origin binding fix from SECAUDIT 047 is code-verified.)

## Remediation status (fixed in 0.7.528)

| # | Sev | Status | Fix |
|---|-----|--------|-----|
| 1 | CRITICAL | FIXED | install.php: once installed (snap_settings populated), refuse ALL actions. `?mode=recovery` and `?action=patch_schema` bypasses forced off; locked page directs to the login-gated admin Disaster page. Fresh-blank-server restore path preserved (only runs when no site exists). |
| 2 | CRITICAL | FIXED | setup.php: refuse whenever `core/parser.php` OR `core/db.php` exists (was: only if BOTH install.php AND parser.php existed, so deleting install.php re-armed the deployer). Returns 403 locked page. |
| 3 | HIGH | FIXED | core/meta.php: JSON-LD emitted with `JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT` (dropped `JSON_UNESCAPED_SLASHES`), so `</script>` in any user field cannot break out. SMACK_CONFIG inline JSON hardened the same way. |
| 4 | MED | FIXED | core/auth-smack.php: `smack-audit.php`, `smack-backfill.php`, `smack-stats.php` added to the admin-only denylist. Backup tool (SYBU) unaffected — it authenticates as admin. |
| 5 | LOW | FIXED | core/constants.php: `Strict-Transport-Security: max-age=31536000; includeSubDomains`. |
| 6 | LOW | FIXED | core/constants.php: conservative CSP (`object-src 'none'; base-uri 'self'; frame-ancestors 'self'`) — does not restrict img/script/form sources, so federated images and remote-follow keep working. Stricter script/img policy deferred pending live testing vs federated content. |
| 7 | LOW | DEFERRED (0.7.529) | Hide server/infra panel from the editor dashboard. |
| 8 | LOW | DEFERRED (0.7.529) | Add a CSRF token to the password-reset request form (low: only triggers a reset email, already rate-limited). |

All 2 CRITICAL, 1 HIGH, and 1 MED fixed and shipped in 0.7.528. Two LOW items deferred to 0.7.529. Every code fix is tagged `SECAUDIT 2026-08-15` inline; all changed files pass `php -l` and retain their EOF markers.

## Post-remediation verification

- **install.php / setup.php:** logic re-read on the 527D source; installed-state now dies before any recovery/patch/deploy path. `php -l` clean.
- **JSON-LD XSS:** the fix changes only the encoding flags on the existing sink; output remains valid JSON-LD (search engines/AI parse `<` fine). The live reflected + stored break-out both depended on raw `<`/`</script>`, which `JSON_HEX_TAG` neutralizes.
- **Editor→admin denylist:** the three pages join 55 existing admin-only entries; the central gate (`!smack_is_admin()` → `smack_require_admin()`) already blocks the listed pages, confirmed live against `smack-settings.php`.
- Full changed set: install.php, setup.php, core/meta.php, core/auth-smack.php, core/constants.php, CHANGELOG.md — all `php -l` clean, `check-eof` all-clear (1067 files, 0 failed).

## Deployment note

Tagging does not deploy. 0.7.528D must be built + signed in the **BITCHIN' Release Packager** (dev track), after which smackmeup clicks update. Until then the live site remains on 527D and every LIVE finding above is still exploitable.

<!-- ===== SNAPSMACK EOF ===== -->
