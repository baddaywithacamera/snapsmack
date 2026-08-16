<!-- SNAPSMACK_EOF_HEADER: the last non-empty line must be the canonical HTML EOF marker. -->

# SECAUDIT 047 — Full multi-dimension audit @ 0.7.525 (525D)

| Field | Value |
|---|---|
| Date | 2026-08-14 |
| Scope | SnapSmack core, whole application: authentication, authorization, SQL, path/LFI, file upload/RCE, federation (ActivityPub) & SSRF, CSRF, secrets & updater, XSS. Hub (Smack Central) login included. |
| Baseline | Core at tag `v0.7.525D` (`SNAPSMACK_VERSION` = `Alpha 0.7.525`), the live **dev** track. The previous pass had run against a stale **stable** checkout (0.7.124, ~400 versions behind); this audit was reset onto `v0.7.525D` first, so every finding is confirmed present in the shipping code. |
| Method | 9 parallel single-dimension reviewers, each reading the real source and confirming reachability (not just pattern-matching). Findings then re-checked by two adversarial verifiers against the patched tree. |
| Status | **All 4 CRITICAL, 7 HIGH, 3 MED and 8/9 LOW fixed and shipped in 0.7.526.** One LOW (updater fail-open) deliberately deferred. Every fix tagged `SECAUDIT 047` inline; all changed files `php -l` clean, EOF markers intact. |
| Positive controls | SQL is consistently PDO-parameterized; passwords use `password_hash`; secrets use `random_bytes`; no hardcoded credentials in source; the normal image-upload and skin-install paths were already well defended (finfo MIME, GD re-encode, zip-slip guard, Ed25519 package signing). |
| Disclosure | No exploitation known. Pre-fix, the editor→admin, recovery-RCE, federation-takeover and hub-brute-force findings were reachable by a low-privilege or remote actor; the download-salt default was globally shared. |

## 1. Executive result

0.7.525 had sound outer architecture — one AP actor per install, PDO everywhere, a package updater with Ed25519 signatures — but the **authorization layer was effectively absent** and several trust boundaries were unenforced. The single most important root cause: admin pages decided access page-by-page, and almost none actually checked the caller's role. That one gap turned a content **editor** into a latent full administrator (findings #1, #2, #5).

Alongside it, the audit found a recovery-kit path that wrote attacker-controlled files into the web root (RCE), a federation signature check that never bound the signing key to the claimed author (actor takeover), a download-link secret that was a shared hardcoded default, a media uploader that accepted any extension (webshell), and a broad class of state-changing GET links with no CSRF token. All are remediated in 0.7.526; the four residual gaps caught by the re-audit (V1–V4 below) are also fixed.

## 2. Method and dimensions

Nine reviewers ran in parallel, one per dimension, each required to (a) locate the concrete code, (b) prove a real caller could reach it, and (c) propose a minimal fix:

`auth` · `authz` · `sql-injection` · `path/LFI` · `upload/RCE` · `federation/SSRF` · `csrf` · `secrets/updater` · `xss`

Two further reviewers re-attacked the patched tree adversarially (results in §7).

## 3. Trust-boundary map

```text
unauthenticated network
  -> login (snap-in)                 password + optional TOTP
  -> password-reset request          email-gated
  -> download.php?id&t                HMAC-signed, published images only
  -> ActivityPub inbox               HTTP-signature verified
  -> RSS/blogroll fetch (outbound)   server-initiated

editor session  (role: "editor", "content only")
  -> all content tools               posting, media, galleries, comments, DMs, pages
  -> MUST NOT reach                   settings, users, secrets, backup, federation control, fleet

admin session   (role: admin/administrator/owner)
  -> everything                      site config, accounts, recovery, updater, hub

Smack Central hub
  -> sc-login                        separate credential store (sc_db)
```

The **editor→admin** step is the load-bearing boundary this release repaired: an editor is a content role and must never cross into configuration, accounts, secrets, the updater, or federation control.

## 4. Severity roll-up

| # | Sev | Title | File(s) |
|---|-----|-------|---------|
| 1 | CRITICAL | Editor → admin self-promotion | smack-edit-user.php |
| 2 | CRITICAL | Editor can create/delete admins | smack-users.php |
| 3 | CRITICAL | Recovery-kit import = arbitrary file write → RCE | core/recovery-engine.php, smack-disaster.php |
| 4 | CRITICAL | Federation actor spoofing / takeover (keyId not origin-bound) | core/smackverse.php `sv_verify_signature` |
| 5 | HIGH | Admin surface is role-blind (root of #1/#2) | core/auth-smack.php |
| 6 | HIGH | Hub login has no brute-force lockout | smack-central/sc-login.php |
| 7 | HIGH | download_salt never generated → public hardcoded default | download.php, core/ftp-engine.php, core/secret-store.php |
| 8 | HIGH | CSRF via GET: account/content/community/federation deletion & moderation | 13 files (see #8) |
| 9 | HIGH | Media-library upload accepts any extension → webshell | smack-media.php |
| 10 | HIGH | Federation Update/Delete of comments not bound to author | core/smackverse.php |
| 11 | HIGH | Stored XSS via `javascript:` URI on public Photo Challenge board/HoF | photochallenge-board.php, photochallenge-hof.php, core/photochallenge.php |
| 12 | MED | CSRF via GET: backup restore/breach-clear, FTP push | smack-back.php, smack-ftp.php |
| 13 | MED | Skin `active_skin` path traversal (stored, CSRF-reachable) | smack-skin.php |
| 14 | MED | Stored XSS via `javascript:` URI in admin fediverse panel | smack-fediverse.php |
| 15 | LOW | Password-reset tokens stored in plaintext | core/auth-recovery.php, core/community-auth.php |
| 16 | LOW | sso.php session cookie missing Secure behind TLS proxy | sso.php |
| 17 | LOW | Username enumeration via login timing | snap-in.php |
| 18 | LOW | Updater signature fail-open if pubkey self-heal cannot write | core/updater.php |
| 19 | LOW | Open redirect via `?ap=remote-follow` | core/smackverse.php |
| 20 | LOW | RSS peer fetch has no SSRF guard / TLS off | cron-rss-fetch.php |
| 21 | LOW | Logout via GET (logout CSRF) | core/auth-smack.php |
| 22 | LOW | Reflected canonical/og:url unescaped | core/meta.php |

## 5. Critical findings

### 5.1 — #1 Editor promotes itself to admin (CRITICAL, FIXED)
**Where.** `smack-edit-user.php`, the self-service account editor.
**Vulnerable pattern.** The form exposed the `user_role` field and the save path wrote whatever role was posted, with no check that the *caller* was an admin. A logged-in editor could open their own account and submit `role=admin`.
**Impact.** Full vertical privilege escalation from the lowest authenticated role to site owner — the whole admin surface (settings, accounts, secrets, updater, federation) falls open. This is site takeover from a content account.
**Remediation.** The page is placed under the central admin-only gate (§6/#5) and the role field is admin-only; an editor can no longer view or change roles.

### 5.2 — #2 Editor creates or deletes administrators (CRITICAL, FIXED)
**Where.** `smack-users.php`, user management.
**Vulnerable pattern.** The page had no role gate, so an editor could open it and reach the create/delete/role-change actions for any account.
**Impact.** An editor could mint a new admin (persistence) or delete the real admin (lockout/denial). Equivalent to #1 by a second route.
**Remediation.** Added to the admin-only denylist and account-write paths check `smack_is_admin()`.

### 5.3 — #3 Recovery-kit import writes arbitrary files → RCE (CRITICAL, FIXED)
**Where.** `core/recovery-engine.php` (archive restore), reached from `smack-disaster.php`.
**Vulnerable pattern.** The restore loop wrote each archive entry to a path derived from the entry name without constraining it. A crafted archive could use traversal (`../../`) or a double extension (`shell.php.jpg`, which executes under Apache `AddHandler`) to drop executable PHP into the web root.
**Impact.** Remote code execution on the server from a restore operation. On top of that, the browser-side import had no CSRF token.
**Remediation.** The target is now confined:
```php
// SECAUDIT 047: constrain the restore target — a crafted archive could name
// "../../x" and drop executable code into the web root => RCE.
$blockedExt = ['php','php3','php4','php5','php7','php8','phtml','pht', /* … */];
$nameTokens = explode('.', $baseName);          // inspect EVERY token, not just the last
// reject if any token is a blocked extension, or the path contains ".."
if (/* traversal || absolute || NUL || any-token-blocked */) { /* refuse */ }
```
plus `realpath` confinement to the site root, and a CSRF token on the browser import in `smack-disaster.php`.

### 5.4 — #4 Federation actor spoofing / takeover (CRITICAL, FIXED)
**Where.** `core/smackverse.php`, `sv_verify_signature()`.
**Vulnerable pattern.** The HTTP-signature check fetched the key owner's actor document from the `keyId`, but never required the `keyId` origin to match the origin of the actor the message *claimed to be from*. A remote server could present a validly-signed message under its own key while claiming to be any other actor.
**Impact.** Impersonation of any federated account and overwrite of its posts/comments — federation takeover.
**Remediation.** The signing key is bound to the claimed author:
```php
// SECAUDIT 047 — bind the signing key to the actor it claims to be.
// Require the fetched keyId and the actor id to share an origin, and — when the
// actor declares a key id — require it to equal the keyId.
if (origin(keyId) !== origin(actor_id)) { $reject('keyId origin does not match actor id origin'); return null; }
if (actor_publicKey_id && actor_publicKey_id !== keyId) { $reject('actor publicKey.id != signature keyId'); return null; }
```
Verified consistent across Create, Update and Delete (the Create sibling gap is V1 in §7).

## 6. High findings

### 6.1 — #5 Admin surface was role-blind (HIGH, FIXED) — root cause of #1/#2
**Where.** `core/auth-smack.php`.
**Vulnerable pattern.** There was no central authorization gate; each admin page was responsible for its own check and almost none performed one. "Editor" was displayed but effectively unenforced.
**Remediation.** A central helper plus a denylist of admin-only pages, evaluated on every admin request:
```php
function smack_is_admin() {
    return in_array((string)($_SESSION['user_role'] ?? ''), ['admin','administrator','owner'], true);
}
function smack_require_admin() {
    if (smack_is_admin()) return;
    http_response_code(403);
    /* JSON for XHR, otherwise an "Administrator access required" page */ exit;
}
// central gate: if a logged-in non-admin requests a page on the admin-only
// denylist (settings, users, secrets, backup, federation control, fleet, …),
// smack_require_admin() is called for them.
```
The denylist is deny-by-configuration: a new *content* page is editor-reachable by default; a new *config/system* page must be added to the list. (SECAUDIT 048 later found three config pages missing from that list — audit/backfill/stats — closed in 0.7.528.)

### 6.2 — #6 Hub login has no brute-force lockout (HIGH, FIXED)
**Where.** `smack-central/sc-login.php`.
**Vulnerable pattern.** The Smack Central hub login accepted unlimited attempts. The hub uses its own DB (`sc_db()`), so the spoke rate-limit table did not cover it.
**Impact.** Offline-free password guessing against the fleet controller.
**Remediation.** A self-contained lockout on the hub DB (short window after repeated failures) plus a dummy verify so a locked/unknown account cannot be distinguished by timing.

### 6.3 — #7 download_salt was a shared hardcoded default (HIGH, FIXED)
**Where.** `download.php`, `core/secret-store.php`, `core/ftp-engine.php`.
**Vulnerable pattern.** Download links are signed `HMAC-SHA256(image_id, download_salt)`, but the salt was never generated per install, so every site fell back to the same hardcoded `SNAPSMACK_DEFAULT_SALT`. Anyone knowing that default could forge valid links for any site.
**Impact.** Forgeable download tokens fleet-wide (limited to published images, but the signature was meaningless).
**Remediation.** Each install self-heals a random salt, and the check remains constant-time:
```php
$salt = snap_ensure_download_salt($pdo ?? null);      // random per-install, seeded at install
$expected_token = hash_hmac('sha256', (string)$img_id, $salt);
if (!hash_equals($expected_token, $token)) { http_response_code(403); die('Invalid token.'); }
```
`snap_ensure_download_salt()` mints and stores a random salt on first use (and re-encrypts the stored FTP password); the concurrency race in that heal is V4 in §7.

### 6.4 — #8 CSRF via GET on deletion & moderation (HIGH, FIXED)
**Where.** 13 files: `smack-users.php`, `smack-community-users.php`, `smack-comments.php`, `smack-fediverse.php`, `smack-media.php`, `smack-pages.php`, `smack-albums.php`, `smack-cats.php`, `smack-blogroll.php`, `smack-manage.php`, `smack-post-long.php`, plus the backup/FTP pair in #12.
**Vulnerable pattern.** Delete/approve/ban/restore/push were plain `GET` links with no anti-CSRF token, so a booby-trapped link — or an `<img src>` in an email — could perform them silently in an authenticated admin's browser.
**Impact.** One-click (or zero-click) destructive actions: delete content/accounts, approve or ban, clear moderation — all as the victim admin.
**Remediation.** New `core/csrf.php` (`csrf_token()`, `csrf_field()`, `csrf_verify()`, `csrf_url()`); a per-session token is threaded through every GET-mutation link (`?delete=…&t=<token>`) and verified server-side before the action runs. Confirmed live in 048 (media purge / post delete carry `&t=`).

### 6.5 — #9 Media upload accepts any extension → webshell (HIGH, FIXED)
**Where.** `smack-media.php`.
**Vulnerable pattern.** The media-library uploader trusted the client filename extension, so a `.php` file could be stored in a web-served directory and executed.
**Impact.** Remote code execution via uploaded webshell.
**Remediation.** Extension is derived from the detected MIME, restricted to raster images, and the upload directory refuses to execute PHP:
```php
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp_path) ?: '';
$map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
return $map[$mime] ?? null;              // null => reject
// + media_assets/.htaccess: "media assets are data, never executable code" (PHP denied)
```
Verified live in 048: a `.php` upload was rejected and a JPEG+PHP polyglot was stored inert (served `image/jpeg`, not executed).

### 6.6 — #10 Federated comment Update/Delete not bound to author (HIGH, FIXED)
**Where.** `core/smackverse.php`.
**Vulnerable pattern.** Incoming Update/Delete for a comment matched on the object id alone, not on the actor that owned it.
**Impact.** A signed remote actor could edit or delete another actor's comment.
**Remediation.** Update/Delete now require the acting actor to equal the stored `ap_actor_url`. (The matching Create-upsert gap is V1 in §7.)

### 6.7 — #11 Stored XSS via `javascript:` on the Photo Challenge board (HIGH, FIXED)
**Where.** `photochallenge-board.php`, `photochallenge-hof.php`, `core/photochallenge.php`.
**Vulnerable pattern.** Submitted links (including federated content) were rendered without a scheme check, so a `javascript:` URI could execute for every visitor of the public board / hall of fame.
**Impact.** Stored XSS on a public, unauthenticated page — runs in every visitor's browser.
**Remediation.** An http(s)-only scheme guard at ingest (`sv_ingest_timeline`) and again at every render sink.

## 7. Medium findings

### #12 CSRF via GET on backup restore / FTP push (MED, FIXED)
Same class as #8 on the highest-impact operations. `smack-back.php` restore/restore-all and `smack-ftp.php` actions now require `csrf_verify()` with a token in the trigger.

### #13 Skin `active_skin` path traversal (MED, FIXED)
`smack-skin.php` accepted an `active_skin` value that could point outside the skins folder (stored, and reachable via CSRF). Now only installed skins are accepted, and a sanitized slug is used for the path and the stored setting. (A compiled control only takes effect after a settings re-save — a compile step, not a security gap.)

### #14 Stored XSS via `javascript:` in the admin fediverse panel (MED, FIXED)
A `javascript:` link could execute in the admin fediverse view. Neutralized by #4 (a `javascript:` actor id can't pass signature verification) plus the render-sink scheme guard.

## 8. Low findings

- **#15 Reset tokens in plaintext (FIXED).** `core/auth-recovery.php` now stores and looks up reset tokens as SHA-256, so a database read can't reuse a live token. (The community-auth token store is the parallel follow-up.)
- **#16 SSO cookie missing Secure behind a TLS proxy (FIXED).** `sso.php` sets `Secure` when `X-Forwarded-Proto` is https.
- **#17 Username enumeration via login timing (FIXED).** `snap-in.php` runs a dummy `password_verify` on unknown usernames to even out response time.
- **#18 Updater signature fail-open (DEFERRED — accepted).** In a narrow state (read-only `core/` plus a placeholder pubkey that can't self-heal) the updater could proceed without a completed signature check. **Left untouched on purpose:** the updater is the *only* channel by which fixes reach installs, and Sean cannot FTP; a wrong assumption here could lock every site out of all future updates. Documented for a deliberate, separately-tested change.
- **#19 Open redirect on remote-follow (FIXED).** `sv_remote_follow_url()` restricts the redirect to https on the same instance host.
- **#20 RSS peer fetch SSRF / TLS off (FIXED).** `cron-rss-fetch.php` refuses feed URLs resolving to private/reserved IPs, caps redirects, and enables TLS verification.
- **#21 Logout via GET / logout CSRF (FIXED).** A `Sec-Fetch-Site` guard drops forged cross-site background `?logout=1` requests without changing any real logout link.
- **#22 Reflected canonical/og:url unescaped (FIXED — but incomplete).** `core/meta.php` now escapes the canonical and OpenGraph URL. **This fix missed the sibling JSON-LD block**, which was still emitting user input into an inline `<script>` unescaped — found and proven exploitable (reflected *and* stored) in SECAUDIT 048 and fixed in 0.7.528 with `JSON_HEX_TAG`.

## 9. Post-remediation verification (adversarial re-audit)

Two adversarial verifiers re-checked the fixes. Authz + CSRF were confirmed solid (denylist complete for the pages known at the time, all `user_role` write paths gated, every GET-mutation handler carries `csrf_verify()`). The federation/salt/upload verifier found **4 residual gaps, all fixed**:

- **V1 (MED)** — the federated-comment **Create** upsert (`ON DUPLICATE KEY UPDATE`) collided on `ap_object_id` alone, so a signed remote actor could still overwrite another actor's comment via Create (the Update/Delete binds from #10 were correct; the Create sibling was missed). Fixed: `comment_text = IF(ap_actor_url = VALUES(ap_actor_url), VALUES(comment_text), comment_text)` on both Create inserts.
- **V2 (MED)** — the recovery guard (#3) checked only the final extension, so `shell.php.jpg` bypassed it. Fixed: reject if ANY dot-separated token is a blocked extension.
- **V3 (LOW)** — `smack-post-solo.php` still trusted the client filename extension. Fixed: extension derived from finfo MIME, raster images only.
- **V4 (LOW, race)** — first-boot salt heal (#7) could mint two salts under concurrency and orphan the FTP password. Fixed: advisory `GET_LOCK` + re-read under the lock.

Re-verified: all four re-fixes pass `php -l`, EOF markers intact. Shipped in 0.7.526.

## 10. Follow-through in SECAUDIT 048 (live test, 0.7.528)

047 was a **code review**. The live penetration test that followed (SECAUDIT 048, against a running 527D site) confirmed the upload, CSRF, download-link and role-gate fixes above **hold against real attacks**, and caught two things a code review over the changed surface did not surface:

1. The leftover **installer** (`install.php` / `setup.php`) was an *unauthenticated* recovery/patch console — the recovery-engine internals from #3 were hardened, but the front door to them was never locked.
2. The **JSON-LD** XSS that #22 only half-closed — proven exploitable both reflected and stored.

Both fixed in 0.7.528. See `2026-08-15-048-live-penetration-test-0.7.527.md`.

<!-- ===== SNAPSMACK EOF ===== -->
