<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  (Authoritative byte sequence: tools/check-eof.py EOF_MARKERS.)
  Missing or different = truncated/corrupted. Restore before saving.
-->

# SnapSmack Changelog

All notable changes to SnapSmack are documented here. Newest release first.

## 0.7.266 — "Musical Chairs" (2026-06-17)

Shared the Grid-family JS engines into the core asset library, and brought AURORA's
overnight build to ship-ready with The Grid sharing the same engines.

### Skin JS unified in the core library (de-dup)

- **One engine per job, every skin.** The forked `au-*` / `tg-*` modal, lightbox,
  and sticky-nav engines were byte-identical except their class prefix. They're now
  a single prefix-derived engine each in `/assets/js` (`ss-engine-grid-modal`,
  `-grid-lightbox`, `-grid-nav`) that reads the skin prefix from the DOM — no
  per-skin copies. AURORA's own atmosphere engines (background curtains, border
  wave, grow-as-you-scroll reveal) moved to `/assets/js` as shared assets too.
- **All skin JS now loads through the manifest.** AURORA and The Grid no longer
  emit direct `<script>` tags from `skin-footer.php`; every engine is registered in
  `core/manifest-inventory.php` and pulled via `require_scripts`, keeping executable
  JS on the inventoried / integrity-monitored path. (SECAUDIT 025.)
- **AURORA was running The Grid's engines.** Its `require_scripts` referenced
  `smack-grid-nav`/`-modal`, so it loaded `tg-*` against `.au-` markup (silently
  inert) while its own nav engine never loaded — the sticky mini-avatar was dead.
  Now points at its own/shared handles.

### AURORA skin — ship-ready (v1.0.14)

- Profile header restored (the background layer was painting over it on a wrong
  z-index); background curtains, grid borders, and the border wave all verified.
- Border wave now animates only on-screen tiles (IntersectionObserver) and tiles
  skip off-screen render (`content-visibility`), so a 3,900-post grid no longer
  pins the main thread.
- Grow-as-you-scroll reveal — the page starts short and grows on scroll instead of
  rendering all posts at full height at once.
- Two-part nav divider lines (green + offset dark indigo) with adjustable opacity;
  outer-glow controls for nav text and title/tagline; default dark legibility halo.
- Admin reorganised — Profile Header split into Profile Header / Title & Tagline /
  Text Glow / Menu-Nav; added Description / Bio colour and size controls.

### The Grid (v1.3.21)

- Uses the shared `/assets/js` grid engines; its `tg-*` JS copies removed.
- Avatar lightbox now registered and loaded through the manifest (was a stray
  direct `<script>`).

## 0.7.264 — "Musical Chairs" (2026-06-17)

Hotfix release shaken out by the 0.7.263 fleet rollout + the first big Instagram import.

### Bulk-import guard no longer guillotines its own import

- **Gate on pre-existing content, not the live count.** The non-empty-site lock
  (Unzucker, Flkr Fckr) recomputed the item count on every upload, so an import
  larger than the threshold sailed past 5 of its own writes and then 403'd itself
  on a clean site ("This site already holds 6 items"). It now blocks only when the
  site is *already* over the limit with no import window open; a clean/under-limit
  site is let through.
- **Sliding import window.** Every accepted write now (re)opens the window, so a
  multi-thousand-item migration runs to completion and never expires mid-flight —
  also fixing the manual authorization lapsing 60 minutes into a big import.

### Fixes

- **Delete 500 in Manage.** `smack-manage.php` deleted from `snap_collection_items`
  by `image_id`, a column that no longer exists since collections went polymorphic
  (`item_type`/`item_id`) — every post/image delete threw a 1054 fatal. Images are
  not collection members, so the obsolete cleanup is removed.
- **Manage delete no longer orphans posts (ghost content).** A Manage grid tile is
  a *post*, but delete removed only the cover image and left the `snap_posts` row
  behind — invisible in the grid (no cover) yet still counted by the bulk-import
  guard, so a "deleted" site kept reporting content and blocked re-imports. Both the
  single and batch delete paths now cascade the whole post: its images (unless
  shared), the post→image links, trigram slices, collection membership, and the post
  row. A new password + 2FA gated **Purge Orphaned Posts** action on the Manage page
  clears pre-existing ghosts (post rows with no images) that no grid tile could reach.
- **SMACKBACK missing-table hardening.** `smackback_init_manifest()` and
  `smackback_init_from_disk()` now treat a missing `snap_file_manifest` table as
  "nothing to baseline" instead of fataling — a hub-pushed update (which calls the
  manifest init before the schema sync has created the table on a schema-behind
  spoke) and the in-admin "Re-initialise baseline from disk" recovery can no longer
  500. Same guard the 0.7.262 hotfix gave `smackback_verify_all()`.

### Step-up auth hardening — password + 2FA, always

- **No password-only step-up.** `core/reauth.php` `reauth_verify()` now requires a
  valid TOTP code for *every* step-up action, not only when 2FA happens to be
  enrolled. An account without 2FA cannot perform a critical action — it is told to
  enrol (the call returns `needs_2fa_enrollment`). The 30-day post-install 2FA grace
  applies to LOGIN only; critical actions never get it. Because every step-up gate
  funnels through `reauth_verify`, this covers them all at once: disable-SMACKBACK,
  hub join/permissions, hub push, bulk-import authorize, and the new schema prune.

### Schema drift report + authenticated prune

- **DB-side debris is now visible.** The schema sync is additive/corrective only and
  never removes, so leftovers (tables/columns from dropped features or old
  migrations) accumulated silently. A new read-only reverse diff
  (`snap_schema_drift()`) lists everything in the live DB that is absent from
  canonical, shown on the Database Schema admin page.
- **Prune Debris.** A destructive prune (`snap_schema_prune()`) drops the listed
  extras — gated by password + 2FA, re-verifying drift at execution time and
  validating every identifier so nothing canonical can ever be dropped. It reports
  automatically and destroys only on authenticated confirmation.

## 0.7.263 — "Bass Ackwards" (2026-06-16)

Tool-security pass — per-tool scoped keys, bulk-import safety rails, and
cross-mode restore protection. Renumbered from the originally-planned 0.7.261
because the 0.7.262 "Hot Seat" login hotfix shipped first. Spec:
`_spec/tool-security-scoped-keys-and-import-guards-v0.1.md`.

### New skin — AURORA (v1.0.0)

- **AURORA** — a new desktop **GRAMOFSMACK** skin built on The Grid's proven
  3-across square-tile architecture, overlaid with a two-layer animation system:
  - **Layer 1 — background aurora.** A slow, CSS-only animated radial gradient
    that breathes the active palette behind the photography. Atmospheric by
    design: low opacity, ~30s cycle. Admin-configurable palette, sky base, and
    opacity.
  - **Layer 2 — tile border wave.** A colour wave travels across the grid's tile
    borders (`skins/aurora/assets/js/aurora-wave.js`), with admin-configurable
    direction, speed, intensity, and border width.
  - Palettes (Aurora / Borealis Ice / Solar Storm) and sky bases are data-driven
    via `skins/aurora/aurora-config.php` — new palettes need no code changes.
  - Respects `prefers-reduced-motion` (both layers freeze, still coherent) and
    pauses the wave loop while the tab is hidden. No inline JS; the wave engine
    ships as a skin asset, loaded from the skin footer.
  - Desktop-only: declared `incompatible: ["mobile", "tablet"]` so it is never
    offered on mobile-primary installs (PHOTOGRAM/TELEGRAM handle mobile).
  - Distributed via the **Skin Packager** (like all non-base skins) — not bundled
    in the core release zip. Spec: `_spec/aurora-parade-skin-spec-v0.1.docx`.
    PARADE (the Pride-palette variant of the same architecture) is a separate
    skin, deferred.

### SMACKBACK status accuracy + incident log + multisite UI

- **PENDING-forever fix.** `smackback_status` was only ever written `clean` by
  `smackback_resolve_breach()`, so a site that was armed but never breached
  reported `pending` ("awaiting first run") indefinitely and the hub dashboard
  showed PENDING for healthy spokes. New guarded `smackback_mark_clean()` helper
  promotes pending/unknown → `clean` on a clean full-verify pass and on a fresh
  disk baseline. It deliberately refuses to clear an active breach (that still
  goes through `resolve_breach`, which logs the incident). Net effect: a clean
  verification or a "Re-initialise baseline from disk" now flips a spoke to CLEAN.
- **Incident log is now readable + dismissible.** Each entry in the SMACKBACK
  incident log expands to show exactly which files were affected; individual
  incidents can be dismissed, and the whole log cleared (with confirmation).
- **Multisite dashboard alignment.** The BACKUP / SMACKBACK / ACTION columns now
  line up: `col-center` cells are vertically centred and the per-spoke ACTION
  control cluster is a tidy centred flex row regardless of which buttons a given
  spoke shows.

### Bulk-import safety (Unzucker, Flkr Fckr)

- **Install-mode lock.** Flkr Fckr imports into SMACKONEOUT (`photoblog`) installs
  only; Unzucker into GRAMOFSMACK (`carousel`) only. Pointed at the wrong mode the
  importer refuses (HTTP 409) and names the actual mode.
- **Non-empty-site guard.** If a target already holds more than 5 items, the
  importer's write endpoints refuse (HTTP 403) until the site owner authorizes an
  import in the admin. Empty/new sites import with no friction.
- **Additive-only, single-site (reaffirmed in code + comments).** The importers
  carry no DELETE/UPDATE-of-existing path and no hub/multisite awareness.

### Per-tool scoped keys (SUYB, SYBU)

- **Least-privilege tool keys.** SUYB and SYBU now authenticate with their own
  typed key (`snap_ohsnap_keys`, `key_type='suyb'`/`'sybu'`) — the same hashed
  Bearer model already used by Unzucker and Flkr Fckr. A SUYB key cannot act on
  SYBU's endpoints and vice-versa. Issue them from Admin → API Keys.
- **Central enforcement.** `core/api-auth.php` validates a typed Bearer key when an
  endpoint declares which `key_type`(s) it allows, and can require a specific
  `site_mode` for tool access. Admin sessions still work for the dual-use admin pages.
- **Legacy shared `tool_api_key` RETIRED.** The old single `X-Snap-Key` shared key is
  gone — validation removed from `api-auth.php`, generation + UI removed from Settings
  and the installer. SUYB and SYBU now send their scoped key as `Authorization: Bearer`
  (the desktop tools were updated to match). Mint per-tool keys on Admin → API Keys.
  **Note:** the rebuilt SUYB/SYBU executables must ship together with this release —
  an un-rebuilt tool still sending `X-Snap-Key` will get a 401.
- **SYBU is photoblog-only.** Tool access to SYBU's endpoints is refused (409) on
  non-`photoblog` installs. Browser admin access is unaffected.
- **SYBU write scope locked.** SYBU's writable surface is now documented and pinned
  in code: it may only INSERT a new image plus its category/album/collection map
  rows (`smack-post-solo.php`) and update a single image's title
  (`smack-audit.php` `update_title`). No update or deletion of existing content.

### Least-privilege multisite backup key

- **Scoped `api_key_backup` for SUYB backup pulls.** Each spoke now issues a second,
  least-privilege hub→spoke key at join that is valid **only** on
  `multisite/backup/*` endpoints — so the full hub key (`api_key_local`) no longer
  has to live in a SUYB desktop profile. The auth gate accepts it for backup
  resources only (403 on anything else). A new `POST multisite/backup/report`
  lets SUYB record a completed pull (writes only the `last_backup_*` keys).
- **Additive rollout.** `api_key_local` still works everywhere; `suyb-data.php`
  emits both keys and SUYB prefers the scoped one when present. The new
  `snap_multisite_nodes.api_key_backup` column is populated when a spoke re-joins —
  until then SUYB transparently falls back to the legacy key. No flag day.

### Cross-mode restore protection

- **Backups stamped with their origin.** SQL dumps and recovery kits now record
  the source `site_url`, `site_mode`, and a stable `site_uuid`, and the SQL dump
  carries a header warning that restoring it onto a different-mode site will break
  that site. `site_uuid` is generated once and reused.

### Multisite

- **Hub-pushed updates no longer trigger a false SMACKBACK breach.** When the hub
  applied an update to a spoke (`multisite/updates/trigger`), it replaced files
  but never refreshed the spoke's SMACKBACK file-integrity manifest, so the next
  scan saw "changed" files and flagged a breach. The hub-push path now refreshes
  the manifest from the signed update package — and clears a stale breach — exactly
  as the local SYSTEM UPDATES path already did.

### API key expiry + admin theme fixes

- **Mandatory API-key expiry (4-week cap).** Tool keys minted in Admin → API Keys
  now require an expiry — 1 day, 1 week, 2 weeks, or 4 weeks (no permanent keys).
  `core/api-auth.php` rejects an expired key (with a graceful fallback if the column
  hasn't synced yet). Pre-existing keys carry a NULL expiry and are grandfathered
  until re-minted. New `snap_ohsnap_keys.expires_at` column.
- **Admin button labels readable in every colour scheme.** Delete/revoke buttons
  (`.btn-reset.action-delete`) lost their colour to `.btn-reset` by CSS source order;
  fixed across all admin themes by raising the action-delete selector's specificity,
  preserving each scheme's own palette. Added a readable `.action-copy` control and
  moved the API-keys page off inline styles onto theme classes.

### SMACKBACK false-breach fix (no more update lockouts)

- **Core integrity manifest is core-only.** The release packager no longer sweeps
  `skins/` file hashes into the core `smackback-manifest.json`, and the manifest
  loader skips any stray skin path — so a core update can never plant skin rows that
  false-breach the fleet. Skins stay monitored via their own `skin_id` rows.
- **Re-baseline from disk now prunes orphaned rows.** `smackback_init_from_disk()`
  removes manifest rows whose file is no longer on disk (and preserves each skin
  file's `skin_id`). A poisoned/stale row can no longer survive a re-baseline, so the
  in-admin "Re-initialise baseline from disk" recovery actually sticks — no more
  needing the operator break-glass (VAX) to escape a lockout.
- **A signed update self-heals on either baseline path.** When an Ed25519-verified
  update (local or hub-pushed) re-baselines from the freshly-extracted disk, a stale
  breach is now auto-cleared the same way the in-zip signed-manifest path already did
  — a trusted update resolves the breach instead of locking the site down.

## 0.7.262 — "Hot Seat" (2026-06-16)

Critical hotfix — the 30-day force-2FA rollout exposed two latent fatals that
locked admins out of the fleet. Shipped alone, ahead of the 0.7.263 tool-security
pass (which shipped once the SUYB/SYBU executables were rebuilt).

- **2FA verify no longer fatals.** `smack-2fa-verify.php` called the trusted-device
  check (`ss_totp_check_trust()`) before `$pending_id`/`$user` were defined, so on
  PHP 8 it threw an uncaught `TypeError` (null given for an `int` param) and
  white-screened the verify page — locking every 2FA-enabled admin out of every
  site. The check now runs after the pending user is loaded.
- **Admin dashboard tolerates an unarmed SMACKBACK.** `smackback_verify_all()`
  (`core/smackback.php`) now returns a clean result instead of fataling when the
  `snap_file_manifest` table doesn't exist yet, so `smack-admin.php` loads on
  installs where SMACKBACK was never armed.

## 0.7.260 — "Ejector Seat" (2026-06-15)

### Multisite mesh — critical key-broadcast fix + consent model

- **Roster no longer broadcasts node keys (P0 fix).** The hub's peer roster
  (`core/mesh-helpers.php` `ms_build_roster`) previously included every node's
  `api_key_local` — the hub→spoke credential — and served it to any holder of a
  valid spoke key, so one leaked spoke key could reach every sibling's database
  export and admin-session (SSO) endpoints. The roster now carries discovery data
  only (names/URLs/roles). `ms_ingest_roster` stores no peer key and self-heals by
  blanking any sibling key a spoke stored under the old behaviour.
- **Per-site hub-permission consent gates.** Powerful inbound hub actions — remote
  update, skin reinstall, SSO token, and database backup export — are now OFF by
  default and individually gated in `core/multisite-api.php`. Each spoke controls
  them from a new "What this site lets its hub do" panel (`smack-multisite.php`).
  Enabling a permission requires password + TOTP step-up (`core/reauth.php`);
  turning one off is always allowed.
- **Step-up auth model.** Joining a hub now requires step-up auth; leaving a hub
  does not (it only reduces access and may be needed in an emergency). Pushing
  settings from the hub (`smack-push-it.php`, `smack-multisite-settings.php`)
  requires one password + TOTP per push — a single entry covers the whole batch.
  SMACKBACK-disable keeps its own separate step-up gate.
- **Duplicate SSO-token handler removed.** An earlier unguarded
  `multisite/auth/sso-token` handler shadowed the hub-role-gated one; removed so
  only the gated handler runs.
- **SUYB backup tool.** Now authenticates to spokes with the correct key
  (`api_key_local`, matching the hub backup page) instead of the unusable
  `api_key_remote`; restores the broken `get_cloud_client()` factory in
  `cloud_client.py` so cloud backups import again.

### Security & platform — six-feature batch

- **Force TOTP 2FA (30-day grace).** New `installed_at` stamp starts a 30-day
  clock; after it, admins without 2FA are redirected to enrolment until they
  enable it (`core/auth-smack.php` gate). Enrolment screen now shows a countdown
  and recommends open-source authenticators (Aegis, Ente, 2FAS) first. Emergency
  escape hatch: an `core/release-2fa-override` file suspends enforcement.
- **Breach lockdown.** On a SMACKBACK breach the public side now serves a 503
  holding page (`core/maintenance-gate.php`, also added to `blog.php`) so a
  tampered install can't throw bad code at visitors. Admin is restricted to an
  allowlist — breach screen, updater, support forum, and backup utilities — and
  the support forum requires a step-up re-auth for a rolling 15-minute posting
  window while breached.
- **SMACKBACK disable re-auth.** Turning SMACKBACK off (locally or confirming a
  hub-requested disable) now requires password + TOTP step-up
  (`core/reauth.php`). Enabling and mode changes stay one-click.
- **SMACKBACK fleet enable from the hub** — already shipped via `smack-push-it.php`
  (verified): pushes `smackback_enabled` + `smackback_mode` + `hub_controls_smackback`.
- **Basic SEO.** Dedicated meta description, OG image override, and a per-page
  SEO title template (`{page}`/`{site}`) in `core/meta.php`; new `sitemap.php`
  served at `/sitemap.xml` (robots.txt already pointed to it). Settings on
  Global Configuration. The social-share (OG) image now uses ONLY a deliberately
  chosen image — the post's own image, or the OG Image Override — and never falls
  back to the site logo or the latest photo. If nothing is chosen, no OG image.
- **Opt-in page cache.** Anonymous-only, no-query full-page cache for the public
  read views (`core/page-cache.php`), default OFF, configurable TTL (default
  300s). Flushed on settings save and **instantly on publish/edit/delete** of
  posts (solo, carousel, longform). New **Dev Mode** pauses caching for a chosen
  window (5 min – 1 week), then auto-resumes. Logged-in admins and query-param
  pages are never cached.

## 0.7.259 — "Driver's Seat" (2026-06-14)

### Core — mobile skin configuration

- **`smack-skin.php`** — new **MOBILE** tab (third tab, alongside Customize and Gallery). Mobile-only skins are hidden from the gallery and excluded from the normal skin admin, so they couldn't be configured at all. The Mobile tab enumerates every `features.mobile_only` skin and gives each a Profile Avatar upload (Photogram now; Telegram and others appear automatically when installed). A dedicated save handler stores `<skin>__skin_avatar` and never changes the active desktop skin. (Avatar only for now; more mobile options to follow.)
- **`core/constants.php`** — version → 0.7.259 "Driver's Seat".

### Skins

- **Photogram 2.0.7** — avatar resolution now prefers its own avatar (set on the new MOBILE tab), then inherits the active desktop skin's `<active_skin>__skin_avatar`, then site logo / favicon.

## 0.7.258 — "Box Seat" (2026-06-14)

### Core — universal skin avatar

- **`smack-skin.php`** — every skin now gets a standard **Profile Avatar** upload in its settings, rendered universally (saved scoped as `<skin>__skin_avatar` via the existing generic image handler). Skins that already declare their own `skin_avatar` option (e.g. The Grid) keep theirs; all others get the injected field. Covers current, experimental, and future skins with no per-manifest work.

### Skins — avatar standardization

- **The Grid 1.3.19** — renamed the profile-avatar setting `tg_avatar` → `skin_avatar` (manifest + layout/skin-page/skin-profile) so it matches the universal key. Existing uploads need re-saving once under the new key (or a one-row settings migration).
- **Photogram 2.0.6** — as the mobile half of the active desktop skin, inherits that skin's avatar via `<active_skin>__skin_avatar` (single key, no install-mode logic), falling back to site logo / favicon.

## 0.7.257 — "Catbird Seat" (2026-06-14)

### Core — maintenance + FLKR FCKR

- **`smack-maintenance.php`** — new FORCE MOBILE SKIN UPDATE action and card. Mobile-only skins (Photogram) are hidden from the gallery and only self-update when the registry version is newer; this force-reinstalls them from the registry on demand (clears the registry session cache, then calls `skin_registry_install()` regardless of installed version).
- **`core/flkrfckr-api.php`** — fixed the EOF marker to the bare-comment form on this pure-PHP file; the prior `<?php // …` form was a fatal parse error in the import endpoint.
- **`core/constants.php`** — version → 0.7.257 "Catbird Seat".

### Skins — touch accessibility + Photogram

- **50 Shades of Noah Grey 1.4.1, Galleria 1.3.1, Rational Geo 2.1.4, Chaplin 0.2.10** — archive thumbnail titles now show on touch / no-hover devices via a `@media (hover: none), (pointer: coarse)` override. Previously hover-only, so the labels were invisible on tablets.
- **Photogram 2.0.3** — version bump so a registry republish triggers the mobile auto-update, carrying the 2.0.2 dual-mode grid fix to installs.

## 0.7.256 — "Hot Seat" (2026-06-13)

### Core — smack-update.php auto-check removed

- **`smack-update.php`** — removed the JS auto-check spinner that fired on every page load. The spinner was flaky (worked ~30% of the time), added confusing UX, and is unnecessary now that cron handles background notifications. Page now shows the last cached result on load; user clicks CHECK NOW to run a live check. Manual button has always been present.
- **`core/constants.php`** — version → 0.7.256 "Hot Seat".

### Skin — Kiosk deleted

- **`skins/kiosk/`** — 8 files removed. Kiosk was mission creep and is abandoned. Deleted from GitHub and SC skin registry.

### Skin — Slickr manifest and Photogram mobile skin delivery (core/updater.php)

- **`skins/slickr/manifest.php`** — removed accidental `modes` field that would have shipped Slickr to every SMACKONEOUT install. Slickr is gallery-only; it ships from the skin gallery, not with any install mode.
- **`core/updater.php` `updater_check_skin_registry()`** — added auto-repair block: if SNAPSMACK_MOBILE_SKIN is defined and the skin directory is absent, silently installs the skin from the remote registry on every updater check. Ensures Photogram is present on all SMACKONEOUT + GRAMOFSMACK installs.

## 0.7.255 — "Hot Seat" (2026-06-13)

### Core — stored-XSS fix in caption renderer (security)

- **`core/snap-tags.php` `snap_render_caption_html()`** — was `strip_tags($text, '<a><p>...')` which kept whitelisted tags with their attributes, allowing stored XSS via `<a href="javascript:">` or `onmouseover` etc. Rewritten: `htmlspecialchars(ENT_QUOTES)` first, then `nl2br`, then the existing safe hashtag linkifier. Fixes all three callers site-wide (The Grid `layout.php`, Photogram `feed.php` + `layout.php`). IG captions are plain text; embedded HTML formatting was never used. Documented in `secaudits/2026-06-12-024A-caption-xss-remediation-addendum.pdf`.

### Core — Photogram mobile skin delivery pipeline

- **`smack-update.php`** — auto-repair block added: on every update run, if `SNAPSMACK_MOBILE_SKIN` is defined and its directory is missing, fetches the skin from the registry and installs it automatically. Ensures Photogram is present after any install or update regardless of how skins were originally delivered.
- **`smack-central/sc-skins.php`** — Skin Packager no longer skips `mobile_only` skins. Photogram now enters `registry.json` so the installer and updater can fetch it.
- **`projects/snapsmack-ca/install-manifest.php`** (both copies) — `mobile_only` skins are always appended to the install manifest regardless of install mode; they are required infrastructure, not user-selectable skins.
- **`skins/photogram/manifest.php`** — guarded the `core/manifest-inventory.php` include with `is_file()` so the manifest returns cleanly when packaged in the SC temp extraction context (where `core/` is absent). Version → 2.0.1.
- **`core/constants.php`** — version → 0.7.255 "Hot Seat".

## 0.7.254 — "Hot Seat" (2026-06-12)

### Core — likes/comments now work inside injected modals (The Grid post modal)

- **ROOT CAUSE (heart dead in The Grid modal, no console error):** `assets/js/ss-engine-community.js` wired the `.ss-community` block exactly once, inside `DOMContentLoaded` (`const root = document.querySelector('.ss-community')`). That works for skins that render the post as a full page (e.g. 50-shades-of-noah-grey — the block exists at load), but The Grid injects the post — and its `.ss-community` — into a modal *after* load, so the engine never saw it and never bound the like/comment handlers. No error was thrown because nothing failed; the listeners simply were never attached. Confirmed by fetching the deployed file from a live install: it was the pre-fix version. The repo fix existed but had never shipped in a release.
- **`assets/js/ss-engine-community.js`** — extracted the in-flow wiring into `wireCommunity(scope)`, called on `DOMContentLoaded` for the initial page **and** on `snapsmack:modal:opened` (dispatched by `tg-modal.js` on the injected fragment) so a modal-injected community block is wired too. Likes, reactions, and comments now respond inside The Grid modal.

### Core — themed file-upload control in the skin admin

- **`smack-skin.php`** — `type:'image'` skin options (profile avatar, treatment image) rendered a raw browser `<input type="file">` that ignored the admin theme. Replaced with the standard `file-upload-wrapper` / `file-custom-btn` / `file-name-display` pattern (a hidden input behind a themed UPLOAD button + filename display), so it now inherits admin geometry like every other control.
- **`core/constants.php`** — version → 0.7.254 "Hot Seat".

## 0.7.253 — "Courtesy Flush" (2026-06-12)

### The Grid 1.3.15 — unified page shell, header on every page, background treatment, avatar lightbox, blogroll restyle

- **Identical header on every Grid page.** The profile block (avatar, name/tagline, post count, bio) + sticky nav previously lived inline in `landing.php` only, so About, Blogroll, archive, and hashtag pages rendered bare. Extracted into a new self-contained partial **`skins/the-grid/skin-profile.php`** (computes avatar/post-count/nav from `$pdo`+`$settings`, so it works in any page context). Wired into `landing.php` (inline copy removed), `skin-page.php` (replaced nav-only block), `archive-layout.php` and `hashtag.php` (now wrapped in `.tg-content-wrap` + header), and `skin-header.php` (now `include`s the partial, which is how core `blogroll.php` picks it up). `layout.php` untouched: a direct post visit already delegates to `landing.php`, and the modal fragment stays header-less.
- **Profile header centered.** `skins/the-grid/style.css` — `.tg-profile` is centered as a unit (`justify-content:center` + `.tg-profile-info{flex:0 1 auto}`); internal avatar-left/text-left alignment unchanged.
- **Background treatment (skin admin → TREATMENT).** New full-page background behind a centered content card, configurable per site. `manifest.php` adds `tg_treatment_mode` (none / image / colour), `tg_treatment_image` (upload, **min 1920×1080** enforced), `tg_treatment_color`, and `tg_treatment_overlay` (a single bidirectional slider: −100 darkens · 0 none · +100 lightens). Rendered by `skin-profile.php` as fixed `.tg-treatment-bg` + `.tg-treatment-overlay` layers; the card is keyed by `body:has(.tg-treatment-bg) .tg-content-wrap`, so with no treatment the flat layout is completely untouched.
- **Avatar lightbox.** New **`skins/the-grid/assets/js/tg-lightbox.js`** + a shared `#tg-lightbox` container in `skin-footer.php` + CSS. Clicking/keyboard-activating the profile avatar (`[data-tg-lightbox]`) opens the larger image full-screen; closes on backdrop, ×, or Escape. No inline JS in the skin.
- **Blogroll restyled to match The Grid.** Grid-scoped CSS under `body.is-blogroll` (this stylesheet only loads when The Grid is active): the raw full-URL line is hidden (the peer name is the link), headings/peers/descriptions adopt Grid type, and the page now carries the shared header. Core `blogroll.php` markup unchanged.
- **`skins/the-grid/manifest.php`** — `version` → 1.3.15.

### Core — footer lowercase option + skin image minimum-dimension guard

- **Footer lowercase (all themes).** New site-wide toggle in **`smack-globalvibe.php`** (Footer section → `settings[footer_lowercase]`, persisted by the generic settings saver). **`core/footer.php`** adds a `footer-lowercase` class + inline `text-transform:lowercase` to `#system-footer` when enabled, so the whole footer bar renders lowercase on every skin. Visual only — stored data is unchanged.
- **Skin image minimum dimensions.** **`smack-skin.php`** image-upload handler now honours per-field `min_width`/`min_height` from the active skin manifest (via `getimagesize`), rejecting undersized uploads with a `gallery_flash` message instead of saving them. Used by The Grid treatment image (1920×1080).
- **`core/constants.php`** — version → 0.7.253 "Courtesy Flush".

## 0.7.252 — "Fanny Pack" (2026-06-12)

### The Grid 1.3.14 — modal carousel + deep-link opens modal (no flat page)

- **Modal carousel now works.** `tg-modal.js` instantiated the slider via `SnapSlider.initAll(frame)` — a method that does not exist (SnapSlider is a class). The guard silently skipped it, so the injected carousel never initialized: it worked on the standalone page (engine auto-inits on `DOMContentLoaded`) but showed one image with no dots/arrows in the modal. Now `tg-modal.js` mirrors `ss-engine-carousel-view.js` — `new SnapSlider({container, speed, loop})` for each `.ss-slider` in the fragment, plus the `snapslider:slidechange` → EXIF-panel wiring. Validated live: dots + arrows render in the modal.
- **Deep links open the modal over the grid; the flat page is gone.** `skins/the-grid/layout.php` no longer renders a standalone solo post. A direct visit to a post URL (`?s=slug`, no `modal=1`) renders the grid (delegates to `landing.php`) and flags the overlay so `tg-modal.js` auto-opens that post's modal. Shared/bookmarked links still resolve; closing returns to the grid (`replaceState` to BASE_URL, no reload). The `.tg-post-ig` markup is now produced only as a modal fragment.
- **`skins/the-grid/skin-footer.php`** — overlay container gains `data-grid-url` (always) and `data-autoopen` (deep-link only). Data attributes, not inline JS.
- **`skins/the-grid/layout.php`** — removed inline `onclick="history.back()"` on `.tg-back-btn`; `tg-modal.js` now delegates `.tg-back-btn` → close (also clears an inline-JS-in-skin violation).
- **`skins/the-grid/manifest.php`** — `version` → 1.3.14.
- Not in this release (triaged, separate owners): caption appears twice because the post's *description field* contains the text twice — an Unzucker import bug (fix in Unzucker + a data cleanup of the 84 imported posts). The white image border is the Image Frame setting (1px `#cccccc` + white frame bg), changeable in Grid skin settings. This post carries 6 images, not 9.

### The Grid 1.3.13 — ship the modal CSS (1.3.12 hotfix)

- 1.3.12 shipped the overlay container + `tg-modal.js` but NOT the modal CSS. The `.tg-modal-overlay` / `.tg-modal-backdrop` / `.tg-modal-frame` rules live in `skins/the-grid/style.css`, which was uncommitted on master and got left out of the 0.7.252 push. Result: the overlay rendered unstyled (`position: static`, no `z-index`, no backdrop), so it opened in normal document flow — collapsed and invisible. Symptom: "click, nothing happens," no console error. Verified live: `#tg-modal-overlay` present and `tg-modal.js` loaded (v252), but `modalRulesFound: 0` in the deployed stylesheet.
- **`skins/the-grid/style.css`** — committed the POST MODAL OVERLAY section (already present in the working tree, never committed to master).
- **`skins/the-grid/manifest.php`** — `version` → 1.3.13. The `style.css` cache-bust embeds the skin version, so bumping to 1.3.13 forces browsers to refetch the corrected CSS (no core change needed).

### The Grid 1.3.12 — ship the modal overlay-container fix

- Reissue of 0.7.251 / The Grid 1.3.11, which was never deployed. Bumped to a fresh core version (0.7.252) and skin version (1.3.12) so the release tags are clean and no stale 0.7.251 tag or package can collide on deploy. **No code change from 0.7.251** — the modal fix itself is unchanged; see the 0.7.251 entry below and `_continuity/the-grid-modal-fix-2026-06-11.md` for the full root-cause writeup.
- **`core/constants.php`** — `SNAPSMACK_VERSION` / `SNAPSMACK_VERSION_SHORT` → 0.7.252.
- **`skins/the-grid/manifest.php`** — `version` → 1.3.12.

## 0.7.251 — "Fanny Pack" (2026-06-12) — NEVER DEPLOYED (reissued as 0.7.252)

### The Grid 1.3.11 — fix modal never opening (missing overlay container)

- **ROOT CAUSE (modal never opened, 0.7.247–0.7.251):** `tg-modal.js` bails at `if (!overlay || !frame) return;` when `#tg-modal-overlay` / `#tg-modal-frame` are not in the DOM. The container was emitted only by `landing.php` (uncommitted, never deployed) and was missing entirely from `archive-layout.php` and `hashtag.php`. With no container the script never binds its click handler, so every tile click falls through to its `<a href>` and navigates — visually identical to "the script didn't load," which is why five prior releases chased script-loading and fragment-routing instead. Verified live on unzucked.ca: `tg-modal.js` loads, `?modal=1` returns a clean `.tg-post-ig` fragment (HTTP 200, no `<html>` shell), but `getElementById('tg-modal-overlay')` returned null. Injecting the container and replaying `openModal()` opened the modal correctly — confirming the container was the only missing piece.
- **`skins/the-grid/skin-footer.php`** — now renders the `#tg-modal-overlay` container (backdrop + `#tg-modal-frame`) so EVERY Grid page that includes the footer (landing, archive, hashtag, skin-page, solo post view) has it. Modal fragments skip skin-footer via the `layout.php` modal-mode guard, so no duplicate container is ever injected into the frame. Direct `<script>` tag for `tg-modal.js` retained from the earlier fix.
- **`skins/the-grid/landing.php`** — removed the per-page overlay container, now redundant with the shared footer copy (avoids duplicate element IDs).
- **`skins/the-grid/assets/js/tg-modal.js`** — the missing-container guard now `console.warn`s instead of returning silently, so a missing container surfaces immediately in the console instead of masquerading as a script-load failure.

## 0.7.250 — "Fanny Pack" (2026-06-12)

### The Grid 1.3.10 — fix blank solo post page + modal registration

- **`skins/the-grid/skin-meta.php`** — was a title-only stub that never called `core/meta.php`. All skin CSS, OG tags, and head scripts were missing on solo post pages. Fixed to call `core/meta.php` like every other skin does.
- **`skins/the-grid/layout.php`** — removed duplicate `include core/meta.php` (was compensating for the broken skin-meta.php, but ran inside `<div id="page-wrapper">` which put all CSS in the body, causing a blank page). Only `skin-header.php` is included here now.
- **`core/constants.php`** — bumped to 0.7.250 to force fresh package builds. Previous 0.7.249 packages were built from a snapshot that predated the `smack-grid-modal` registration in `manifest-inventory.php`.
- **`smack-multisite-settings.php`** — fixed PHP parse error: `admin-footer` include was placed after the EOF marker, leaving an unclosed PHP block on line 242.

## 0.7.249 — "Fanny Pack" (2026-06-11)

### Fix modal fetch returning full page instead of fragment

- **`index.php`** — added early-exit modal fragment path: when `?modal=1` + a valid slug is requested, skip `skin-meta.php` / `<body>` / `#page-wrapper` entirely and serve only the `layout.php` output, then `exit`. The `$_tg_modal_mode` flag in `layout.php` then strips the skin-header/footer, leaving a clean `.tg-post-ig` fragment for `tg-modal.js` to inject.
- **`skins/the-grid/manifest.php`** — bumped to 1.3.8.

## 0.7.248 — "Fanny Pack" (2026-06-11)

### The Grid 1.3.7 — fix modal script not loading via manifest

- **`skins/the-grid/manifest.php`** — reverted inline `<script src>` hack; restored `smack-grid-modal` to `require_scripts`. Skin Packager must be re-run from current source for the entry to land in the deployed manifest. Bumped to 1.3.7.

## 0.7.247 — "Fanny Pack" (2026-06-11)

### The Grid 1.3.6 — IG-style post modal overlay

- **`skins/the-grid/assets/js/tg-modal.js`** *(new)* — intercepts `.tg-tile` link clicks, fetches post content as `?modal=1` fragment, injects into overlay container, pushes URL via `history.pushState`. Closes on backdrop click, ESC, or browser back. Falls back to full page navigation on fetch error.
- **`skins/the-grid/landing.php`** — added `#tg-modal-overlay` container (backdrop + frame) before footer; populated by `tg-modal.js` at runtime.
- **`skins/the-grid/layout.php`** — `$_tg_modal_mode` flag: when `?modal=1` is present, skips `meta.php` and `skin-header.php`/`skin-footer.php` and returns only the `.tg-post-ig` HTML fragment.
- **`skins/the-grid/style.css`** — modal overlay CSS: `position:fixed` full-viewport container, `rgba(0,0,0,0.8)` backdrop, `min(92vw,1300px) × min(92vh,900px)` frame with `border-radius:4px`; `.tg-modal-frame .tg-post-ig` overrides height to fill frame; `body.tg-modal-open` scroll lock.
- **`core/manifest-inventory.php`** — registered `smack-grid-modal` pointing to `tg-modal.js`.
- **`skins/the-grid/manifest.php`** — added `smack-grid-modal` to `require_scripts`; bumped to 1.3.6.

### The Grid 1.3.5 — caption + engagement bar + image gutter fixes

- **`skins/the-grid/layout.php`** — right panel caption now renders IG-style: **sitename** bold inline followed by post title + description as one block (no separate `<h2>`). Added engagement bar (`tg-post-ig-actions`) pinned between scrollable body and footer: comment icon, bookmark icon, post date.
- **`skins/the-grid/landing.php`** — nameline now uses `site_tagline` (short field) for inline `name / tagline` display; `site_description` (long bio) rendered as separate `tg-profile-bio` paragraph below stats.
- **`skins/the-grid/style.css`** — removed `padding:10% 24px` from `.tg-post-ig-image` (was causing side gutters on square images); image now `width/height:100% object-fit:contain` to fill panel. Added `.tg-post-caption-block`, `.tg-post-ig-caption`, `.tg-post-ig-caption-user`, `.tg-post-ig-actions`, `.tg-action-btn`, `.tg-post-ig-date`, `.tg-profile-bio` rules.

## 0.7.246 — "Fanny Pack" (2026-06-11)

### The Grid 1.3.4 — nav + profile header polish

- **`skins/the-grid/landing.php`** — tagline renders inline with site name as `sitename / tagline`; static pages (About etc.) appear in sticky nav automatically from `snap_pages`.
- **`skins/the-grid/style.css`** — sticky nav links centered (avatar absolutely left); tagline driven by `--tagline-font/size/weight/color` CSS vars; nav links respect `--nav-text-transform`; removed old `.tg-profile-bio`.
- **`skins/the-grid/manifest.php`** — added: tagline font/size/weight/colour pickers; nav link case selector (as-typed / ALL CAPS / First Letter / lowercase); blogroll nav link.
- **`skins/the-grid/skin-page.php`** *(new)* — static pages render inside full Grid shell (CSS, nav, footer).

## 0.7.242 — "Fanny Pack" (2026-06-11)

### The Grid 1.3.1 — post page image breathing room + pinned footer

- **`skins/the-grid/style.css`** — added `padding: 10% 24px` to `.tg-post-ig-image` so image sits at ~80% height with whitespace above and below; CSS for `#system-footer` inside right panel (pinned as flex child at bottom).
- **`skins/the-grid/layout.php`** — moved `skin-footer.php` include inside `.tg-post-ig-info` so the site footer renders as a pinned bottom bar in the right panel rather than off-screen below the viewport.

## 0.7.241 — "Fanny Pack" (2026-06-11)

### The Grid 1.3.0 — Instagram image page + blog title typography

- **`skins/the-grid/layout.php`** — replaced stacked post layout with Instagram two-panel layout: image fixed on the left (never scrolls), right panel contains header (back ← + avatar + site name), then scrollable body (post title, caption, EXIF, date, comments).
- **`skins/the-grid/style.css`** — new `.tg-post-ig` / `.tg-post-ig-image` / `.tg-post-ig-info` / `.tg-post-ig-header` / `.tg-post-ig-body` CSS; carousel fills image panel height; mobile stacks vertically (image 60vw max, info scrolls below). `.tg-profile-username` now driven by `--blog-title-font`, `--blog-title-size`, `--blog-title-weight` CSS vars.
- **`skins/the-grid/manifest.php`** — added `tg_blog_title_font`, `tg_blog_title_size`, `tg_blog_title_weight` (PROFILE HEADER section); added `tg_show_tagline` toggle for site description; bumped skin to 1.3.0.
- **`skins/the-grid/landing.php`** — wired `tg_show_tagline` to conditionally render bio.

### Light table drag + checkbox + zoom slider fixes

- **`smack-lt-gram.php`** — `Sortable.complete.min.js` doesn't exist in sortablejs@1.15.3; changed to `Sortable.js` (full build). Fixed `new MultiDrag()` → `new Sortable.MultiDrag()` (not a global in UMD build). Added `filter: '.ltg-select-cb, .ltg-btn-publish'` + `preventOnFilter: false` so checkboxes and publish buttons receive clicks instead of being swallowed by drag handler. Added zoom slider (shrinks grid width 240–900px, always 3 columns) so tiles can be made smaller to see more rows at once; preference persisted in localStorage.

## 0.7.240 — "Fanny Pack" (2026-06-11)

### Fix light table drag broken (Sortable CDN 404)

- **`smack-lt-gram.php`** — wrong CDN filename `Sortable.complete.min.js` doesn't exist in sortablejs@1.15.3 package; changed to `Sortable.js` (full build, includes MultiDrag). Drag silently broken since trigram-locking update.

## 0.7.239 — "Fanny Pack" (2026-06-11)

### The Grid — remove trigram negative margins (1.2.7)

- **`skins/the-grid/style.css`** — removed all special CSS from trigram tile classes (`.tg-tile--trigram-L/M/R`). Negative margins were collapsing the grid gap to fake a seamless panorama seam but caused tile overlap at any non-zero gap and mispositioned carousel indicators. `aspect-ratio: unset` on M was there solely to compensate for negative-margin width expansion — also removed. All trigram tiles are now plain square tiles inheriting `.tg-tile` styles exactly like every other tile in the grid. Tiles are always square, always.
- **`skins/the-grid/manifest.php`** — bumped to v1.2.7

## 0.7.239 — "Fanny Pack" (2026-06-11)

### The Grid — image page background color

- **`skins/the-grid/manifest.php`** — bumped to v1.2.6; added `tg_post_bg_color` (color picker, COLOURS section, `--post-bg`, default `#000000`)
- **`skins/the-grid/style.css`** — `.tg-carousel-wrap` background: `#000` → `var(--post-bg, #000)`
- **`skins/the-grid/layout.php`** — carousel slide `$img_style` and single-image `$single_wrap_bg` both changed from hardcoded `background:#000` to `background:var(--post-bg,#000)`

## 0.7.238 — "Fanny Pack" (2026-06-10)

### The Grid — UI cleanup (unzucked.ca)

- **`skins/the-grid/manifest.php`** — bumped to v1.2.5
- **`skins/the-grid/style.css`** — `.tg-tile--trigram-M`: added `aspect-ratio: unset` to fix middle trigram tile extending below L/R tiles (negative margins widened content box, causing `aspect-ratio: 1/1` to make M 4px taller than siblings); added `#system-footer` overrides: centered, max-width constrained, better slot spacing
- **`skins/the-grid/skin-header.php`** — removed `<header class="tg-topbar">` site-name bar (redundant given profile header and sticky nav)
- **`skins/the-grid/skin-footer.php`** — removed duplicate `<footer class="tg-footer">` copyright block (copyright is already in `core/footer.php` slot 1)
- **`core/footer.php`** — removed HELP link from footer slot 5b (F1 keyboard shortcut still works via `ss-engine-public-help.js`; the footer link was noise on a public photo grid)

## 0.7.237 — "Fanny Pack" (2026-06-10)

### The Grid 1.3.0 — full layout redesign

- **`smack-skin.php`** — new `range_numeric` setting type: slider + synced numeric input, emits correct `px`-suffixed CSS custom property values
- **`skins/the-grid/`** — The Grid 1.3.0:
  - 935px default max-width constrained layout (`.tg-content-wrap`), admin-configurable via slider
  - Sticky nav bar between profile and grid — `position: sticky; top: 0` with mini avatar that appears on scroll (JS: `tg-nav.js`, IntersectionObserver on profile section)
  - Feed ORDER BY fixed: `CASE WHEN sort_order > 0 THEN 0 ELSE 1 END ASC, sort_order ASC, created_at DESC` — pure chronological when sort_order is unset (0)
  - Font stack updated: removed `-apple-system`, `BlinkMacSystemFont`; uses `'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif`
  - `tg_gap` and `tg_gutter` settings migrated from select/range to `range_numeric` (slider + numeric input); `tg_gap` now emits correct `2px` (was unitless, gap property was broken)
  - New `tg_max_width` setting (600–1600px, default 935px)
- **`skins/the-grid/assets/js/tg-nav.js`** — new sticky nav script (registered via `smack-grid-nav` in manifest-inventory.php)
- **`core/manifest-inventory.php`** — registered `smack-grid-nav`

## 0.7.236 — "Fanny Pack" (2026-06-11)

### Fix — SC "Running" version permanently stuck at 0.7.217D

- `smack-central/sc-update.php` — RE-PULL copies `sc-version.php` from the raw GitHub zip, which is hardcoded to the last manually committed version (0.7.217D) and never updated. Fixed by adding `sc-version.php` to the protected files list and writing it explicitly after the copy loop, deriving the version string from the pulled tag and codename from `latest-dev.json`.

## 0.7.235 — "Fanny Pack" (2026-06-11)

### Fix — SC pages rendering `// EOF` as visible text

- `smack-central/sc-layout-bottom.php` — EOF marker was outside PHP tags, causing `// EOF` to render as literal text in the bottom-left corner of every Smack Central page.

## 0.7.234 — "Fanny Pack" (2026-06-10)

### Fix — Skin gallery blank (all skins filtered out)

- `smack-skin.php` — `$is_carousel_site` was used in three places (lines ~1099, 1171, 1308) but never defined. PHP evaluates it as null, making `$skin_carousel !== null` true for every skin, filtering the entire gallery to nothing. Fixed all three occurrences to use the defined variable `$is_carousel`.

### Fix — Release package size bloat (~700KB)

- `smack-central/sc-release.php` — `skins/alfred/` was added in 0.7.226 but not added to `always_exclude`. Alfred ships bundled fonts (Droid Serif, Montserrat, Font Awesome woff/woff2) and a bg.jpg totalling ~700KB. Added `skins/alfred/` to `always_exclude`. Alfred is distributed via the Skin Packager like all other non-base skins.

---

## 0.7.233 — "Fanny Pack" (2026-06-10)

### Fix — Chaplin: grain overlay darken bug

- `skins/chaplin/manifest.php` — removed `selector`/`property` mapping from `chap_grain_intensity`. The manifest was causing `smack-skin.php` to emit `--chap-grain-opacity: <raw_int>` (e.g. `2`) in the dynamic CSS block, which ran after `skin-header.php`'s correct calculation of `raw/100`. The raw value overwrote the fraction, giving `opacity: 2` (browser-clamped to `1`), making the film-grain overlay fully opaque and darkening the entire archive page. `skin-header.php` already handles the `/100` conversion — the manifest must not emit this var independently.

### Fix — SC Dashboard: ACTIVE INSTALLS over-counting

- `smack-central/sc-dashboard.php` — both the primary query (behind `WHERE role = 'hub'`) and the fallback query were using `SUM(1 + spoke_count)` to compute the active installs count. This counted spokes as separate installs: one hub with 7 spokes counted as 8, giving 15 total instead of 8. Correct metric is the count of distinct hub rows. Both queries changed to `COUNT(*)`.

---

## 0.7.232 — "Fanny Pack" (2026-06-10)

### The Grid skin — full overhaul

- **Full-width grid** — tiles now fill the viewport. The profile header and topbar respect a new "Side Gutter" setting (`tg_gutter`, 0–120 px range) instead of the old fixed 3-choice max-width. Default is 0 (edge-to-edge, Pixelfed-style).
- **Avatar upload** — skin admin now has a `tg_avatar` image-upload option under PROFILE HEADER. Uploads saved to `uploads/skin-avatars/`. Falls back to site-name initials if not set.
- **Hover = darken only** — new "Darken only" option added to Hover Overlay setting; made the new default. Title/count/none options retained.
- **Click bug fixed** — tile links were using `?id={img_id}` which index.php never routed. Fixed to `?s={img_slug}` in `landing.php`, `archive-layout.php`, and `hashtag.php`.
- **Infinite scroll on hashtag pages** — the "Load more" button replaced with an IntersectionObserver sentinel. Tiles are fetched and appended as the user scrolls.
- **Archive cleanup** — dead pagination block removed from `archive-layout.php` (archive.php never set `$total_pages`).

### Skin admin — type:'image' option support

- `smack-skin.php` — new `image` option type for skin manifests. Renders a file input with current image preview. Uploaded files saved to `uploads/skin-avatars/{skin}--{key}.{ext}`. Form updated to `enctype="multipart/form-data"`. Any skin can declare `'type' => 'image'` in its manifest options going forward.

---

## 0.7.231 — "Fanny Pack" (2026-06-10)

### Fix — Unzucker API: DB collision on retry of posts with zero timestamp

- `core/unzucker-api.php` — per-image duplicate check now joins `snap_post_images` before reusing an existing `snap_images` row. Previously, if a prior successful import had committed an image (with a `snap_post_images` pivot row), a retry would reuse that `image_id` and immediately hit the `UNIQUE KEY uq_image` constraint, returning a 500 collision. Fix: only reuse orphaned snap_images rows (those with no matching snap_post_images entry).
- `core/unzucker-api.php` — fixed PHP falsy-string bug: `$ig_id ?: null` stored `NULL` for posts with `ig_timestamp = 0` (PHP treats `"0"` as falsy). On retry those posts were never caught by the import_id dedup, hitting the image collision path above. Changed to `$ig_id !== '' ? $ig_id : null`.

---

## 0.7.230 — "Fanny Pack" (2026-06-10)

### Fix — Backup tool: version in dump header; hardcoded table list replaced with SHOW TABLES

- `smack-backup.php` — dump header now includes `-- Version: X.Y.Z` so the installed version is visible in every backup file. Replaced hardcoded `$core_tables` / `$extended_tables` arrays with `SHOW TABLES` dynamic discovery. The hardcoded list was missing ~20 tables added since the backup tool was written (`snap_post_cat_map`, `snap_ban_list`, `snap_comments_semantic`, `snap_skin_presets`, `snap_tags`, `snap_image_tags`, `snap_migrations`, `snap_password_resets`, `snap_rate_limits`, `snap_ip_bans`, `snap_totp_devices`, `snap_community_users`, `snap_community_tokens`, `snap_community_comments`, `snap_likes`, `snap_reactions`, `snap_ste_scores`, `snap_keywords`, `snap_stats`, `snap_stats_daily`, `snap_pimpotron_slideshows`, `snap_pimpotron_slides`, `snap_mosaics`, `snap_file_manifest`, `snap_smackback_log`, `snap_cats`, `snap_backup_log`). Full dump now exports every table that exists on the install.

### Fix — Schema sync + schema page: phantom wrong-type on MariaDB (json vs longtext)

- `core/schema-sync.php` — new `snap_types_equivalent()` helper treats `json` and `longtext` as equivalent. MariaDB stores JSON columns as LONGTEXT internally; INFORMATION_SCHEMA always reports `longtext` for them, making ALTER TABLE ... json a silent no-op that schema-sync was incorrectly reporting as a correction on every update run.
- `smack-schema.php` — same equivalence check applied to the diff comparison so the schema page no longer shows `tfidf_vector` and `preset_data` as wrong-type issues on MariaDB installs.

### Fix — Schema sync report: nullability-only corrections showed identical type strings

- `core/schema-sync.php` — report line for MODIFY COLUMN now itemises type change and nullability change separately. Previously the message only printed `live_type → canonical_type`, so when the trigger was a nullability mismatch (e.g. `NOT NULL → nullable`) the report showed `varchar(500) → varchar(500)` and looked like a false positive. The MODIFY was always correct; only the output was misleading.

---

## 0.7.229 — "Fanny Pack" (2026-06-10)

### Feature — smack-vax.php: emergency DB migration delivery through SMACKBACK lockdown

- `core/smack-vax.php` — new tool: admin POSTs to `smack-vax.php?pkg=CODE` with a token; fetches a signed payload from `https://snapsmack.ca/releases/vax/{pkg}.vax`, verifies an Ed25519 signature against `SNAPSMACK_RELEASE_PUBKEY`, applies the embedded SQL. 3 failed token attempts → 1-hour lockout in `snap_settings`. Payload marked consumed after first successful apply to prevent replay. Designed for surgical DB fixes on SMACKBACK-locked sites without FTP.
- `smack-central/sc-vax.php` — SC-side payload generator: create, sign, list, and delete `.vax` payloads hosted at `snapsmack.ca/releases/vax/`.
- `smack-central/sc-layout-top.php` — VAX Generator added to nav under Security.
- `_spec/smack-vax-v0_1.md` — spec written before build.

### Fix — Schema sync engine: detect and repair wrong column types across all installs

- `core/schema-sync.php` — section 2 (column sync) now reads `snapsmack_canonical.sql` at runtime and diffs it against `INFORMATION_SCHEMA` including `COLUMN_TYPE` and `IS_NULLABLE`, not just column presence. Missing columns get `ADD COLUMN`; columns with wrong type or nullability get `MODIFY COLUMN`. Replaces the old hardcoded `$column_additions` list which required manual updates on every schema change and caused multiple production breakages. On update, will automatically repair: `snap_posts.title` (`varchar(500)` → `text`) and `snap_trigrams.source_path`, `cut_a`, `cut_b` nullability (`NOT NULL` → `NULL`) across all existing installs.
- `smack-schema.php` — Admin → Database Schema page now detects wrong-type columns (not just missing ones). Wrong-type columns shown in orange with a tooltip displaying `live: varchar(500) NOT NULL  →  needs: text NOT NULL`. Apply button issues `MODIFY COLUMN` to fix them. Status bar breaks down missing tables, missing columns, and wrong types separately.

### Process change — new column workflow simplified

Adding a column now requires only updating `database/schema/snapsmack_canonical.sql`. The schema sync engine picks it up on next update or Apply Schema click. Migration files are no longer needed for structural changes (ADD/MODIFY). Migration files are still required for data changes (seed rows, value transforms).

## 0.7.228 — "Sitting Duck" (2026-06-09)

### Fix — Unzucker freeze after locking trigrams

- `tools/unzucker/main.py` — `TrigramPanel._lock()`: added explicit `grab_release()` before `destroy()`. Without it, a timing edge case could leave the modal grab alive on the (now-destroyed) panel window, making the main window appear frozen to Windows. `PostGrid._drop_all()`: now resets `thumb_loading = False` on all cell states when the canvas is cleared, preventing stale in-flight thumbnail threads from blocking fresh loads after any reorder or resize. `PostGrid._load_thumb_async()` and `_on_thumb_ready()`: now capture and verify post identity (not just index) so thumbnail callbacks that arrive after a reorder discard their result rather than storing a photo at the wrong cell index.

### Fix — Unzucker job state file saved next to exe, not in AppData

- `tools/unzucker/job_state.py` — `_JOBS_DIR` now resolves to `jobs/` alongside the executable (or script in dev), matching `unzucker.ini`. Previously wrote to `%APPDATA%\Unzucker\jobs\` which was invisible to the user.

### Fix — snap_posts.title too narrow for long Instagram captions

- `database/schema/snapsmack_canonical.sql` — `snap_posts.title` widened from `VARCHAR(500)` to `TEXT`. Instagram captions up to 2,200 characters were triggering `SQLSTATE[22001]: String data, right truncation` on import.
- `migrations/migrate-posts-title-text.sql` — migration added; registered in `UPDATER_KNOWN_MIGRATIONS`.
- `core/unzucker-api.php` — defensive `ALTER TABLE snap_posts MODIFY title TEXT` added to the trigram endpoint schema guard so existing installs are fixed on first Unzucker import without needing a full updater run.

### Feature — Unzucker pre-run server reconciliation

- `core/unzucker-api.php` — new `POST unzucker/posts/check` endpoint: accepts `{"ig_ids": [...]}`, returns `{"existing": {ig_id: post_id, ...}}` for all Instagram posts already on the server. Single round trip before the run starts.
- `tools/unzucker/poster.py` — `UnzuckerClient.check_posts()` added. `run_migration()` now calls it at the start of every run to bulk-check which posts already exist on the server. Posts confirmed present are skipped immediately without attempting upload or resize. Per-post server duplicates detected mid-run are now also added to the `server_existing` map and recorded to job state, so they don't get retried on the next run.
- `tools/unzucker/main.py` — `_poll_queue` progress handler now calls `record_uploaded()` for all successful results with a non-zero `post_id`, including server-confirmed duplicates. Previously only freshly-uploaded posts were recorded, leaving crash-recovered posts invisible to the job state.

## 0.7.227 — "Our Work" (2026-06-09)

### Fix — smack-update.php persistent "CHECKING FOR UPDATES…" hang

- `smack-update.php` — When the server could not reach snapsmack.ca, the `check_ajax` failure branch exited without writing anything to the update cache. On every subsequent page load the auto-check condition (`$core_status = 'checking'`) re-armed, triggering the JS retry loop (4 attempts, delays 0 / 5 / 10 / 20 s = ~83 s total) before giving up. Fixed: `check_ajax` now writes an `error` cache entry to `snap_settings` on failure. Auto-check page-load condition gains a `recently failed` branch: if the cache age is under 5 minutes and `core_status` is `error`, `$core_status` is set to `'error'` directly, skipping the JS spinner entirely and rendering the error badge + RETRY CHECK button immediately. After 5 minutes the spinner re-arms for a fresh attempt.

### Feature — Unzucker 0.7.28: virtual scroll + job state persistence

- `tools/unzucker/main.py` — `PostGrid` class fully rewritten from per-post `tk.Frame` widgets to a single `tk.Canvas` surface. Only visible rows are rendered; rows outside `VISIBLE_BUFFER = 2` above/below the viewport are evicted and redrawn on scroll. `_CellState` dataclass carries logical state (status, trigram group/slot, excluded, thumb) per post independently of render state. Math-based hit testing replaces per-widget event bindings. Scales to 2400+ posts with constant memory.
- `tools/unzucker/job_state.py` — **new file.** `JobState` class persists per-import state to `%APPDATA%\Unzucker\jobs\{job_name}.ini` via `configparser`. Sections: `[job]`, `[progress]`, `[trigrams]`. `parse_job_name()` extracts a clean name from `instagram-username-YYYY-MM-DD` folder patterns. `find_for_folder()` scans the jobs dir to detect an existing in-progress import for the same export folder. Written on every mutation: `record_uploaded()` per post, `save_trigrams()` on every lock/remove, `set_excluded()` on every right-click toggle. If the app closes mid-import, the next open detects the job and prompts resume.
- `tools/unzucker/main.py` — `App` gains `self._job: Optional[job_state.JobState]`, `_prompt_job_name()` Toplevel dialog (fired when folder name doesn't parse), and `_on_parse()` job detection/resume/create logic. On resume: trigram groups and excluded posts are restored, progress bar pre-filled to `len(job.uploaded)`. "Unload Job" button added to bottom bar: confirms, deletes the `.ini`, clears the grid, and returns to config view.

### Backport — FLKR FCKR fixes from Unzucker

- `tools/flkr-fckr/main.py` — Per-job file logging (job-named log file alongside `flkrfckr.log`). `requests.HTTPError` handler added to `_post_to_snapsmack()` to preserve PHP error messages from API failures (previously swallowed by generic `Exception`). `local_paths: list = []` pre-declared before the try block so cleanup is always safe even if an exception fires before the list is populated.

---

## 0.7.226 — "The Hover" (2026-06-09)

### Fix — Chaplin archive pages dim due to flicker animation fill-mode

- `skins/chaplin/skin-header.php` — `chap-flicker-in` animation was applied to `#scroll-stage` on all page types including archive. With `animation-fill-mode: both`, the element starts at `opacity: 0.55` (the 0% keyframe) and holds there until the animation fires. On archive pages with lazy-loaded images, and in any browser context that delays or skips animations (background tab, energy saver, `prefers-reduced-motion`), `#scroll-stage` was stuck permanently dim. Fixed: animation selector now excludes `.archive-page` and `.static-transmission` pages. Added `prefers-reduced-motion` override that forces `opacity: 1` on all pages.

### Fix — Chaplin EXIF toggle not wired to setting

- `skins/chaplin/layout.php` — `$exif_display_enabled` was used in the INFO overlay template but never explicitly set, relying on the `?? true` fallback. The setting was always treated as enabled regardless of the admin toggle. Fixed: variable is now set inline from `$settings['exif_display_enabled']`, consistent with how other skins handle it.

### Maintenance — snapsmack.ca copy updates

- `projects/snapsmack-ca/index.php` — SLICKR paragraph tightened; Flickr note moved from SLICKR to FLKR FCKR with updated closing line ("at a price you can handle").

---

## 0.7.225 — "Trickle Down" (2026-06-09)

### Fix — core/trigram.php fatal parse error

- `core/trigram.php` — EOF marker was written as `<?php // ===== SNAPSMACK EOF =====` but the file never closes PHP mode with `?>`. PHP parsed the second `<?php` as an unexpected token mid-file, causing a fatal parse error on every include. Result: any endpoint that requires trigram.php (unzucker-api.php, smack-lt-gram.php) returned HTTP 500. This was the root cause of all unzucker import failures. Fixed: marker is now `// ===== SNAPSMACK EOF =====` (plain comment, no opening tag).

### Fix — smack-manage.php collection subquery column mismatch

- `smack-manage.php` — Collection membership subquery used `sci.image_id` which does not exist on `snap_collection_items`. The correct columns are `sci.item_type = 'post' AND sci.item_id = i.id`. Old code was from a pre-schema-migration version that got restored to the server by the system updater. Caused a fatal PDOException on every media manager page load.

### Feature — Photogram v2.0.0 carousel support

- `skins/photogram/archive-layout.php` — Carousel badge added (single query, count per post_id).
- `skins/photogram/layout.php` — SnapSlider carousel for multi-image posts; post_id-keyed likes and comments. Caption now prefers `snap_posts.caption` over `img_description`.
- `skins/photogram/manifest.php` — `carousel => true`, smack-slider added to `require_scripts`, version bumped to 2.0.0.

### Maintenance — snapsmack.ca content updates

- `projects/snapsmack-ca/index.php` — THE GRID and UNZUCKER moved to Working Now; Coming Soon updated.
- `projects/snapsmack-ca/wotcha.php` — UNZUCKER launch article added.

---

## 0.7.224 — "Swirly Boi" (2026-06-08)

### Cleanup — multisite MAINT column removed

- `smack-multisite.php` — dropped the MAINT status-dot column from the spoke table. The column was redundant with the ENABLE/DISABLE MAINT button in the ACTION column, which already reflects current state via its label and colour. Button labels corrected from confusing state labels ("MAINT ON" / "MAINT OFF") to clear action labels ("ENABLE MAINT" / "DISABLE MAINT").

---

## 0.7.223 — "Phantom Flush" (2026-06-08)

### Fix — trigram sort_order row-boundary formula

- `core/trigram.php` — `trigram_check_and_publish()`: row-boundary formula was computing the next multiple of 3 (`max_so + (3 - max_so%3)`), which places trigram L tiles at column 2 (rightmost) instead of column 0 (leftmost). sort_order is 1-indexed so row starts are 1, 4, 7, 10... (≡ 1 mod 3). Fixed formula: `$col_offset = (1 - ($max_so%3) + 3) % 3; $start = $max_so + ($col_offset === 0 ? 3 : $col_offset)`. The phantom padding in landing.php corrected for this visually, but DB sort_order values were semantically wrong.
- `core/unzucker-api.php` — same formula fixed in the `POST unzucker/trigram` sort_order assignment block.

### Fix — duplicate trigram guard in unzucker-api.php

- `core/unzucker-api.php` — `POST unzucker/trigram`: added pre-flight check for existing `trigram_id` on any of the three post IDs. Previously a second call with the same posts would create a second snap_trigrams row and orphan the first. Now returns HTTP 409 with a clear error.

### Fix — core/trigram.php docblock correction

- `core/trigram.php` — Removed false claim that the file is "used by smack-post-gram.php". That wiring is intentionally deferred; the docblock now says so.

---

## 0.7.222 — "Courtesy Flush" (2026-06-08)

### GramOfSmack trigrams — full soft-import implementation

Soft trigrams (pre-sliced images, e.g. Instagram imports) are now fully supported alongside the existing hard-slice system. Three interconnected parts ship together.

**CMS scaffolding**

- `migrations/migrate-trigram-type.sql` — New `trigram_type ENUM('slice','group')` column on `snap_trigrams`. `slice` = existing GD/Imagick cut in SnapSmack; `group` = pre-sliced external import. `source_path`, `cut_a`, `cut_b` made nullable so group-type rows don't require them.
- `core/updater.php` — `migrate-trigram-type.sql` registered in `UPDATER_KNOWN_MIGRATIONS`.
- `database/schema/snapsmack_canonical.sql` — `snap_trigrams` CREATE TABLE updated with `trigram_type` and nullable slice columns.
- `core/trigram.php` — New helper library. `trigram_check_and_publish(PDO, trigram_id, post_id)`: parks a post as `queued` until all 3 slots are ready, then promotes all 3 atomically and assigns `sort_order` at the next row boundary (≡ 0 mod 3). `trigram_ready_count()` helper.

**The Grid skin**

- `skins/the-grid/landing.php` — Full rewrite. JOINs `snap_trigrams` to compute `trigram_slot` (L/M/R or T/M/B) and `trigram_orientation` per post. Emits phantom padding tiles to preserve 3-column row alignment when a trigram group starts mid-row. Pagination removed — all posts lazy-loaded per spec. Tiles carry `data-trigram-id` and `data-trigram-slot` attributes.
- `skins/the-grid/style.css` — Trigram stitch CSS: negative margins collapse the inter-tile gap on L/M/R tiles so the three images present as a continuous strip. `.tg-tile--phantom` hides padding tiles visually while preserving grid geometry.

**Lighttable (smack-lt-gram.php)**

- Full rewrite. `core/trigram.php` wired in. Single-post publish routes through `trigram_check_and_publish()`; returns `{promoted, post_ids}` (all 3 promoted simultaneously) or `{queued, ready: N}` (post parked until group is complete). Bulk publish handles per-post trigram routing. `status='queued'` posts appear in the Lighttable with a QUEUED N/3 badge. Sortable.js group-drag moves all 3 trigram tiles as a unit.

**Unzucker API**

- `core/unzucker-api.php` — New route: `POST unzucker/trigram`. Accepts `{post_id_1, post_id_2, post_id_3, orientation}`. Creates a `type='group'` row in `snap_trigrams`, links all 3 posts via UPDATE, assigns `sort_order` at the next row boundary. Defensive schema guard included.

**Unzucker Python app**

- `tools/unzucker/poster.py` — `PostResult` now captures `post_id` from API response. `UnzuckerClient.create_trigram()` added. `run_migration()` accepts optional `trigram_groups` list; after all posts are created, calls `create_trigram()` for each locked group (non-fatal on failure).
- `tools/unzucker/main.py` — Trigram UI: Ctrl+click enters selection mode (gold ring + badge overlay on tile), right-click context menu. `TrigramPanel` (new `Toplevel` dialog): shows L/M/R thumbnails with swap buttons, LOCK / CANCEL. `App` tracks `_tg_groups` list and shows live group count label. On post, group indices are remapped from original-list positions to active-list positions before passing to the migration thread. `BUILD_VERSION` bumped to `0.7.14`.

---

## 0.7.221 — "Splash Zone" (2026-06-07)

### Fix — HTTP 500 on first login to smack-admin.php

- `smack-admin.php` — On first page load (cold cache), the updater stale-cache branch was making blocking HTTP calls: `updater_fetch_release_info()` at 30s timeout × 3 retries, plus `updater_check_skin_registry()` — up to 90 s total, which exceeds PHP's max_execution_time and produces a 500 with no cache written. On refresh the cached result skips the fetch entirely, which is why a hard refresh always worked. Both calls switched to fast mode (`$fast = true`): 12s timeout, 1 retry, skin registry returns empty immediately. Acceptable tradeoff — skin notifications are cosmetic and stale on the first login anyway.

### Fix — Unzucker carousel cover image ordering

- `tools/unzucker/ig_parser.py` — Removed `images.reverse()` block that was re-ordering carousel images in reverse display order. Instagram export JSON stores carousel images cover-first (forward display order), so reversing buried the cover image at the bottom of the stack. The cover is now index 0 as it appears in the export.

### Fix — Unzucker main.py encoding cleanup (cp1252 mojibake)

- `tools/unzucker/main.py` — File had pervasive double-encoding: original Unicode chars were encoded as UTF-8, bytes were misread as Windows-1252 (cp1252), then re-encoded as UTF-8, producing garbled multi-byte sequences throughout. Fixed 13 character patterns: bullet (`•`), em dash (`—`), box-drawing (`─`), ellipsis (`…`), checkmark (`✓`), X mark (`✗`), empty set (`∅`), small square (`▪`), middle dot (`·`), up/down triangle (`▲`/`▼`), right arrow (`→`), lock emoji (`🔒`). The `show="•"` mask on the API key field was the user-visible symptom — displayed `§` instead of bullet dots.

### Unzucker — FTP removed; HTTP upload endpoint added

All FTP/SFTP infrastructure has been removed from Unzucker. Images are now uploaded directly to the server over HTTPS via a new authenticated multipart endpoint. This eliminates the SFTP host-key dialog that was a support nightmare for users, removes the paramiko dependency, and simplifies the tool significantly.

- `core/unzucker-api.php` — New route: `POST unzucker/upload`. Accepts a multipart JPEG upload (Bearer auth required), validates MIME via `finfo`, caps at 20 MB, sanitises filename, saves to `img_uploads/YYYY/MM/`, creates subdirs and `thumbs/` if missing, returns `{path: "YYYY/MM/filename.jpg"}` for use as `snap_images.img_file`.
- `core/unzucker-api.php` — Fixed: `snap_posts.title` was hardcoded to `''` in the INSERT — now reads `$body['title']` correctly.
- `tools/unzucker/poster.py` — Added `upload_image()` to `UnzuckerClient`: POSTs image bytes as multipart (clears session-level `Content-Type: application/json` header per-request so requests can set the multipart boundary), returns server path. `run_migration()` rewritten: step 2 is now HTTP upload per image; FTP transport arg removed throughout.
- `tools/unzucker/main.py` — Entire FTP settings UI section removed (host, port, protocol dropdown, username, password, remote base path, warning label, Test FTP button). `_on_test_ftp()` deleted. FTP references removed from `_load_config_to_ui()`, `_save_config()`, `_on_post()`, `_post_thread()`, `_set_posting()`. Confirm dialog updated to reference HTTPS upload.
- `tools/unzucker/config.py` — FTP fields removed (`ftp_host`, `ftp_port`, `ftp_username`, `ftp_password`, `ftp_protocol`, `ftp_remote_base`). `load()` and `save()` now handle only `url`, `api_key`, `export_folder`, `copyright_text`. FTP keyring account helper and migration path removed.
- `tools/unzucker/ftp_upload.py` — **Deleted.** No replacement.
- `tools/unzucker/requirements.txt` — `paramiko>=3.0` removed.
- `tools/unzucker/build.bat` — `--hidden-import=paramiko` removed from PyInstaller args.

### Unzucker — keyring migration fix + version bump

- `tools/unzucker/config.py` — Fixed migration gap: when keyring is available but a secret was never stored there (first run after upgrade), `load()` now falls back to the base64 value from the ini and migrates it into keyring on the spot. Previously keyring availability short-circuited the fallback entirely, leaving the API key blank on upgrade.
- `tools/unzucker/main.py` — `BUILD_VERSION` bumped to `0.7.13`.

### snapsmack.ca — index.php sticky menu restored

- `projects/snapsmack-ca/index.php` — `footer.php` include was missing, which dropped the sticky mini-header scroll script, the site footer, and `</body></html>`. Restored by adding `require_once __DIR__ . '/includes/footer.php'` at page end.

### Installer — Security &amp; Community opt-in screen (secopt)

- `install.php` — Step 6 secopt screen overhauled: heading changed to "Security &amp; Community", "stronger together" messaging added to SmackAttack card explaining how each contributing install benefits the whole network.
- `install.php` — New **Community Forum** card added (third card in secopt), pre-checked/opt-in by default. Explains the forum is the primary one-to-many communication channel — one post reaches all connected installs, more reliable than email.
- `install.php` — Backend wired: `$sec_forum` reads checkbox from POST, flows into `sec_consent` JSON blob and into the `forum_enabled` settings seed. Previously hardcoded to `'0'`; now driven by user choice, defaulting to `'1'`.

### Security — Unzucker attack surface hardening (audit #023)

- `tools/unzucker/ftp_upload.py` — `SFTPTransport` now uses `RejectPolicy` + `load_system_host_keys()` instead of `AutoAddPolicy`. Connecting to an unknown SFTP host now raises a clear error with the exact `ssh-keyscan` command needed to add the host key, rather than silently accepting any key. Eliminates MITM risk on SFTP sessions. (UZ-01)
- `core/unzucker-api.php` — `img_path` in the POST `unzucker/posts` handler is now validated before writing to `snap_images.img_file`: must be ≤500 chars and end in `.jpg` or `.jpeg`. Arbitrary path strings from a crafted API request can no longer be stored in the image table. (UZ-02)
- `tools/unzucker/ig_parser.py` — Resolved image paths must now stay within the export root directory. A URI that resolves outside the root (via absolute path or `../..` traversal) is skipped with a logged warning. Prevents a maliciously crafted export from causing Unzucker to upload arbitrary local files to the server. (UZ-03)
- `tools/unzucker/main.py` — Warning label now appears when plain FTP is selected, recommending SFTP for internet transfers. Protocol dropdown auto-switches port between 21 (FTP) and 22 (SFTP) when at a known default; custom ports are never touched. (UZ-05 + port UX)
- `tools/unzucker/config.py` — API key and FTP password now stored in the OS credential store via the `keyring` library (Windows Credential Manager / macOS Keychain / libsecret). Falls back to base64 obfuscation in `unzucker.ini` if keyring is unavailable. Non-secret settings (URLs, paths, ports) remain in the ini. `has_keyring()` helper added for UI use. (UZ-06)
- `tools/unzucker/main.py` — Header now shows `🔒 keyring` or `⚠ no keyring` so the user always knows whether secrets are secured by the OS or falling back to base64.
- `tools/unzucker/exif_writer.py` — Dead code removed: `embed()` and `embed_inplace()` (leftover from ft-batch-poster fork, never called by Unzucker). Unused imports (`os`, `shutil`, `tempfile`) removed alongside. (UZ-09)
- `tools/unzucker/requirements.txt` — `keyring>=24.0` added (optional; graceful fallback if absent).
- Security audit logged: `secaudits/2026-06-07-023-unzucker-attack-surface.pdf`

---

## 0.7.220 — "Splash Zone" (2026-06-08)

### Fix — htaccess repair now correctly strips all trailing separator lines

- `smack-maintenance.php` — Two-pass strip: (1) remove everything from the marker to end of file; (2) walk backwards stripping any trailing separator `# ─────` lines and blank lines left behind. Previous regex approach failed when a blank line separated the junk separators from the marker, leaving accumulated cruft on every REPAIR run.

## 0.7.219 — "Splash Zone" (2026-06-08)

### Fix — htaccess repair regex now handles any number of leading separator lines

- `smack-maintenance.php` — Did not work. Superseded by 0.7.220.

## 0.7.218 — "Splash Zone" (2026-06-08)

### Fix — Apache FastCGI strips Authorization header; Bearer auth now works

- `.htaccess` + `core/htaccess-template` — Added `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. Apache FastCGI/PHP-FPM strips the Authorization header by default, making `$_SERVER['HTTP_AUTHORIZATION']` always empty. Every Bearer-token API auth call (Unzucker, Oh Snap!, etc.) silently failed regardless of key validity. This was the root cause of the 4-day Unzucker blocker.
- `core/unzucker-api.php` — Auth function now checks `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`, and `getallheaders()` fallback for resilience across server configs.

## 0.7.217 — "Splash Zone" (2026-06-07)

### Fix — key_prefix column guard added to smack-api-keys.php

- `smack-api-keys.php` — The 0.7.216 fix added a `key_prefix` ALTER TABLE guard to `unzucker-api.php`, but key generation (and the INSERT) happens in `smack-api-keys.php`. If smack-api-keys.php was hit before the Unzucker API endpoint, `key_prefix` still didn't exist at INSERT time, causing the same silent failure. Guard added here as well.

---

## 0.7.216 — "Splash Zone" (2026-06-07)

### Fix — SC update page "Running" row showed stale version permanently

- `smack-central/sc-version.php` — Hardcoded to 0.7.209D since that release; never updated. SC's update page "Running" row reads SC_VERSION from this file, so it showed 0.7.209D regardless of what was actually installed.
- `smack-central/sc-release.php` — Both stable and dev build pipelines now auto-patch `smack-central/sc-version.php` inside the release zip at build time (same mechanism as the pubkey injection on setup.php). SC_VERSION and SC_CODENAME will always match the build that was pulled going forward.

### Fix — Unzucker API key silently not stored due to missing key_prefix column

- `core/unzucker-api.php` — The defensive `CREATE TABLE IF NOT EXISTS snap_ohsnap_keys` was missing the `key_prefix` column. When unzucker-api.php created the table on installs where it didn't exist yet, the table was created without `key_prefix`. `smack-api-keys.php` then tried to INSERT with `key_prefix` in the column list — the statement failed silently (no try/catch), so "KEY GENERATED" was shown to the user and a value was displayed, but nothing was written to the DB. Every subsequent connection attempt returned "Invalid API key." Fixed: `key_prefix` added to the defensive CREATE TABLE and an ALTER TABLE ADD COLUMN IF NOT EXISTS guard added for existing tables.

### Feature — Schema completeness audit in admin

- `smack-schema.php` — PHP Reference Audit panel added above the live DB diff. Scans PHP source files on disk for snap_* SQL table references and flags any absent from the canonical schema. Same check the SC build gate runs — now visible to the operator without needing CLI access.

### Tooling — Schema completeness audit gate in SC release packager

- `tools/check-schema.php` — Standalone CLI audit tool. Scans all PHP files for SQL table references, cross-references against canonical schema, exits 1 on any gap.
- `smack-central/sc-release.php` — Step 4d: `sc_audit_schema_completeness()` scans PHP files inside the freshly-built zip against the canonical schema extracted from the same zip. Build aborts if any referenced table is absent from the schema. Makes it structurally impossible to ship a release with a schema gap.

---

## 0.7.215 — "Flush Protocol" (2026-06-07)

### Fix — 31 tables missing from installs predating canonical schema system

- `migrations/migrate-create-missing-tables.sql` — The canonical schema diff system was never successfully applied on installs that ran before 0.7.213 fixed the zip packaging. 33 tables were absent. Additionally, snap_cats (SmackPress categories) and snap_backup_log were missing from the canonical schema entirely — found by auditing every SQL query in the PHP codebase. All 33 tables created with IF NOT EXISTS — idempotent on clean installs.
- `database/schema/snapsmack_canonical.sql` — Added snap_cats and snap_backup_log, which were referenced in smackpress-api.php and multisite-api.php but never defined in the schema.
- `core/updater.php` — Added migrate-create-missing-tables.sql to UPDATER_KNOWN_MIGRATIONS.

---

## 0.7.214 — "Flush Protocol (Hardened)" (2026-06-07)

### Security — Canonical schema remote fetch now requires verified signature

- `core/updater.php` — `updater_canonical_diff()` previously accepted a remotely-fetched canonical schema even when signature verification could not be performed (sodium extension absent, sig URL missing from manifest, or empty sig response). An attacker with access to snapsmack.ca or a MITM position could have served a malicious schema and had it applied to the target database (CREATE TABLE / ALTER TABLE — no DROP or DELETE possible, but still unacceptable). Fixed with an explicit gate: the remote copy is accepted **only** when all three conditions hold: (a) `SNAPSMACK_SIGNING_ENFORCED` is true (real pubkey installed), (b) a sig URL is present in the manifest, and (c) the sodium extension is available. Any failure in the chain rejects the remote copy and logs a specific error. When `SNAPSMACK_SIGNING_ENFORCED` is false (placeholder pubkey / dev install), the remote fetch is skipped entirely — no point fetching content that cannot be authenticated. The on-disk fallback (extracted from a sig-verified zip) is always trustworthy.

---

## 0.7.213 — "Flush Protocol" (2026-06-07)

### Fix — Canonical schema: duplicate blocks, incomplete definitions

- `database/schema/snapsmack_canonical.sql` — Rewrote the file from scratch. The previous version had grown two complete halves: a comprehensive first half and a second half that duplicated many tables with older, less-complete definitions. The `updater_canonical_diff()` regex processes tables in order, so the second (stale) definition silently overwrote the first (complete) one in the diff map. The result: columns like `totp_secret/totp_enabled/totp_recovery_json` (snap_users) and `source_hub_url` (snap_blogroll) and `ban_sync_cursor` (snap_multisite_nodes) were treated as "not expected" and therefore never created on new installs running schema sync. The new file has exactly one definition per table, all authoritative. Version header updated to 0.7.213.

### Fix — Canonical schema URL never used during updates

- `core/updater.php` — `updater_run_migrations()` called `updater_canonical_diff($pdo)` with no URL, meaning the remote `canonical_schema_url` from latest.json was never fetched. The function supports remote-first fetch but the call site never passed the URL. Fixed: `updater_run_migrations` now accepts optional `$canonical_url` and `$canonical_sig_url` parameters and passes them to `updater_canonical_diff`.
- `smack-update.php` — Both migration call sites (`stage_premigrate` and `stage_migrate`) now extract `canonical_schema_url` and `canonical_schema_sig` from the update manifest and pass them through.

### Fix — Dev builds didn't publish canonical schema

- `smack-central/sc-release.php` — Dev builds (`build_dev`) were explicitly skipping the canonical schema publish step ("stable build owns that"). On dev-track installs (like unzucked.ca), the remote URL was always empty, forcing disk-only fallback. Fixed: dev builds now also read the canonical schema from the zip and publish + sign it to `releases/snapsmack_canonical.sql`, and the URL is included in `latest-dev.json`.

### Fix — Schema Recovery section vanished after all migrations applied

- `smack-update.php` — The Schema Recovery box was gated on `$has_pending || $has_ghosts || $schema_resync_result`. Once all migrations ran successfully (like after 0.7.212D), the section disappeared entirely, leaving no way to manually trigger RUN SCHEMA SYNC. Fixed: section is always visible. The orange border highlight remains when there are pending migrations or ghost files.

### Also in this release

- `smack-backup.php` — DB download filenames include the site URL slug (e.g. `snapsmack_full_unzucked.ca_2026-06-07_13-43.sql`) to avoid filename collisions when managing multiple sites.
- `core/unzucker-api.php` — Defensive `CREATE TABLE IF NOT EXISTS snap_ohsnap_keys` + `ALTER TABLE ... ADD COLUMN IF NOT EXISTS key_type` at startup, handles installs that predate the key table or the key_type column.

---

## 0.7.212 — "Courtesy Wipe" (2026-06-07)

### Fix — Unzucker API parse error

- `core/unzucker-api.php` — PHP parse error on line 299: EOF marker was written as `<?php // ===== SNAPSMACK EOF =====` in a pure-PHP file (no `?>` close tag), producing a syntax error on every request. Changed to `// ===== SNAPSMACK EOF =====`. The API was completely non-functional in production.

### Unzucker desktop — QoL

- `tools/unzucker/main.py` — Show/hide toggle buttons added to API key and FTP password fields. Test FTP button added to the FTP settings box — connects and disconnects without running a full migration, reports success/failure in the status bar and a dialog. Both buttons disable during active transfer. Settings now also saved on window close (previously only saved on Connect/Parse/Transfer). Build version bumped to 0.7.12.

---

## 0.7.211 — "Phantom Flush" (2026-06-06)

### Fix — Unzucker import API

- `core/unzucker-api.php` — Three bugs fixed before first production use:
  - Removed `created_at` from `snap_images` INSERT (column doesn't exist — hard SQL error on every import).
  - `img_orientation` hardcoded to `0` (INT column was receiving `'portrait'`/`'landscape'` strings, silently stored as 0 anyway; all IG imports are square).
  - `snap_images.post_id` now SET after pivot insert, matching normal post-creation path (`smack-post-gram.php`) so photo editor links back correctly.
  - Entire POST handler wrapped in a PDO transaction with rollback on any `Throwable` — partial imports no longer possible.

### Fix — SSO session

- `sso.php` — Was not setting `$_SESSION['user_role']` after SSO login; now SELECTs `user_role` from `snap_users` and assigns it to session. Resolves role-gated page failures after SSO entry.

### Fix — Push It stale lock

- `core/multisite-api.php` — Disconnect endpoint now clears all 7 `hub_controls_*` session keys. Previously left stale lock state that prevented re-registration after a force disconnect.

### Fix — smack-help.php

- `smack-help.php` — File tail reconstructed after truncation; nav icon double-encoding fixed. Confirmed working on foundtextures.ca.

---

## 0.7.210 — "Courtesy Flush" (2026-06-06)

### Feature — SC dashboard: fleet mixed-track detection + accordion

- `smack-central/sc-dashboard.php` — Fleet count is now hub-only (spokes excluded from the
  headline number). Sites on a different track than the hub get an amber "mixed" label with a
  tooltip. Hub row expands to show each spoke's uid, version, and track in a collapsible accordion.
- `smack-central/schemas/sc-smackcent-canonical.sql` — `role` and `hub_uid` columns added to
  `sc_phone_home`.
- `core/updater.php` — Spoke slim ping now includes `role=spoke` and `hub_uid` so SC can
  associate spokes with their hub. Migration registered in `UPDATER_KNOWN_MIGRATIONS`.
- `migrations/migrate-phone-home-spoke-rows.sql` — New migration: adds `role` + `hub_uid`
  columns to `sc_phone_home`, adds index on `hub_uid`.
- `projects/snapsmack-ca/releases/ping.php` — Accepts and stores `role` + `hub_uid` params from
  spoke pings.

### Feature — Network alert: sidebar margin, dismiss button, auto-register on poll

- `core/network-alert.php` — Yellow alert banner now has correct `margin-left:240px` to clear
  the admin sidebar. Dismiss button (×) added — stores dismissal in `localStorage` keyed by
  alert hash so the banner stays gone on reload until a new alert arrives. Auto-registers for
  push when `push_enabled=1` and `push_registered!=1` during a poll cycle, so spokes that
  missed registration self-heal without manual intervention.
- `core/admin-header.php` — SMACKBACK red breach banner gets matching `margin-left:240px`
  sidebar clearance.
- `core/multisite-api.php` — `network_alert_push_registered` added to `$allowed_keys` so hubs
  can push the registration state to spokes.

### Feature — Push It: force re-register push button

- `smack-push-it.php` — New FORCE RE-REGISTER PUSH button. Pushes `push_registered=0` to all
  spokes via the existing fanout; spokes auto-register on their next admin page load. Recovery
  tool for fleets where push registration got out of sync.

### Fix — Chaplin archive thumbnails 15% too dark

- `skins/chaplin/style.css` — Removed `brightness(0.85)` and `grayscale(100%)` from
  `.rg-archive-item img`, `#justified-grid img`, and `.fsog-thumb img`. Chaplin photos are
  already B&W (photographer-processed) — the CSS filter was darkening thumbnails relative to the
  individual photo view. Hover states simplified to opacity-only.

### Fix — SMACKBACK breach page: misaligned RESTORE button left edges

- `smack-back.php` — Breach file rows switched from `flex` to `grid`
  (`1fr 90px 130px`) so filename, status label, and RESTORE button columns are always
  pixel-aligned regardless of filename length.

## 0.7.209 — "Courtesy Flush" (2026-06-05)

### Fix — SmackBack false positive on every release (legit core files read as TAMPERED)

- `smack-central/sc-release.php` — The SC Release Packager builds the deployed zip from the GitHub
  tag archive, which carries no `smackback-manifest.json`. `sc_build_release_zip()` now generates the
  manifest itself — per-file SHA-256, size, and EOF signature for every monitored `.php`/`.css`/`.js`,
  computed from the exact bytes written to the package — and adds it before the zip is closed, so it
  is covered by the existing Ed25519 package signature. Without this, the post-update re-baseline had
  no manifest to read and every legitimately-changed file reported as TAMPERED on the live fleet.
- `smack-update.php` — If a package has no `smackback-manifest.json`, the updater now falls back to
  re-baselining from the freshly-extracted (Ed25519-verified) disk instead of skipping. A stale breach
  is auto-cleared only when the baseline came from the signed in-zip manifest; the disk fallback never
  auto-clears.

### Fix — SmackBack breach could only be cleared by RESTORE (which reverts code)

- `smack-back.php` — RE-INITIALISE BASELINE now calls `smackback_resolve_breach('reinit')` after a
  successful re-hash, and a clean RUN FULL VERIFY now clears an active breach
  (`smackback_resolve_breach('manual')`). Previously neither cleared `smackback_status`, leaving
  RESTORE — which reverts files to the old baseline — as the only exit from the breach screen.

### Security

- `secaudits/secaudit-0.7.209.md` — Full review of this release. Net-positive: file integrity is now
  cryptographically anchored on deployed releases (previously absent), and the one moved surface
  (breach auto-clear) is gated to the signed path. Two follow-ups flagged: the SC skin packager
  (`sc-skins.php`) has the same missing-manifest gap, and `smackback-manifest.json` should get a
  web-root `.htaccess` deny.

## 0.7.208 — "Privy Council" (2026-06-05)

(0.7.207 "Privy Council" shipped the installer security opt-in and is already pushed. Codename
carried forward to 0.7.208.)

### Feature — Pulsing alert admin themes (whole-screen breach/advisory signal)

- `assets/adminthemes/alert-breach-red/`, `assets/adminthemes/alert-yellow-fast/`,
  `assets/adminthemes/alert-yellow-slow/` — Three new HIDDEN admin themes. Each `@import`s a base
  palette (Red John for breach, Amber Phosphorus for the yellows) and adds a fixed, click-through,
  screen-blended full-viewport `body.admin-body::after` overlay that pulses the entire admin UI
  dark↔light (smooth pulse, not a flash). Pulse speeds: breach 2s, yellow-fast 1.6s, yellow-slow 4s.
  Includes a `prefers-reduced-motion` steady-tint fallback.
- `core/admin-header.php` — Auto-applies the alert theme, overriding the user's chosen theme:
  `smackback_status == 'breach'` → breach red (read from `$settings` directly, so it pulses even
  in lockout mode on smack-back.php); otherwise `$_nalert_status` yellow_fast/slow → the matching
  yellow theme.
- `smack-globalvibe.php`, `smack-admin.php`, `smack-admin-reference.php` — Theme discovery now
  skips any manifest with `'hidden' => '1'`, so the alert themes never appear in the picker while
  remaining applicable by slug.

### Fix — Network Alert public API include paths (0 subscribers / no YELLOW root cause)

- `projects/snapsmack-ca/sc-network-api.php` — The web-root endpoint used
  `require_once __DIR__ . '/../smack-central/...'`, but this file deploys to the snapsmack.ca web
  root, so `../smack-central` resolved above the web root → fatal `require_once` → HTTP 500 on every
  status/report/register call. That single failure caused both 0 push subscribers and no YELLOW
  polling. Corrected to `__DIR__ . '/smack-central/...'`. (The earlier "matches ping.php pattern"
  note was wrong — ping.php lives in releases/, one level deeper, where `../` is correct.)

## 0.7.207 — "Privy Council" (2026-06-05)

### Feature — Installer security opt-in step

- `install.php` — New security step in the installer: opt-in to SmackBack (file integrity) and
  SmackAttack / network-alert breach-intel sharing. Both default opt-in with a clear opt-out,
  non-blocking, with an inline privacy disclosure and the consent decision + UTC timestamp logged.
  Seeds the relevant `snap_settings` keys and registers for push when opted in. Existing CSRF /
  admin-bypass installer audit gates preserved.

## 0.7.206 — "Bodacious Bidet" (2026-06-05)

### Fixed — Stale Smack Central version display

- `core/constants.php`, `smack-central/sc-version.php` — Version bump to 0.7.206 / 0.7.206D to
  correct the Smack Central update page reporting a stale "Running 0.7.190". Hand-bumped constant;
  the updater itself was not at fault.

## 0.7.205 — (2026-06-05)

### Changed — Inline JS purged from skins

- `skins/chaplin/`, `skins/rational-geo/`, `skins/slickr/` — Inline `<script>` removed from skin
  templates and moved into CMS-manifest-registered JS modules, so skins ship zero inline script.

### UI / Maintenance

- `smack-settings.php` — "Open SmackBack" button is now full-width.
- `smack-central/sc-dashboard.php` — New "Rebuild Fleet Count" maintenance action that truncates
  the phone-home table to clear stale spoke rows causing a doubled active-installs count.

## 0.7.204 — "Neighbourhood Watch" (2026-06-04)

### Feature — Immediate push notifications on network breach

- `network-alert-push.php` — New public endpoint on spoke installs. Smack Central calls this
  directly when a network-wide breach is detected, delivering alerts in seconds rather than
  waiting up to 30 minutes for the next poll cycle. Validates SC pushes via a per-install
  64-char hex push token (generated locally, constant-time compared). Rate-limited at 10/min by IP.
- `smack-back.php` — New "Immediate Breach Push Notifications" toggle in the Network Alert section.
  Full privacy disclosure: opt-in transmits site URL and site name to SC for push delivery.
  Shows live registration status. Opt-out triggers a deletion request loop that retries on every
  admin page load until SC confirms removal.
- `core/network-alert.php` — `nalert_register_push()` and `nalert_unregister_push()` handle SC
  registration handshake. `nalert_maybe_retry_unregister()` retries pending removals independently
  of the 30-minute poll throttle. Token generation via `random_bytes(32)`.
- `smack-central/sc-network-api.php` / `projects/snapsmack-ca/sc-network-api.php` — New
  `?route=register` and `?route=unregister` routes. host-match validation on push_url prevents
  register-then-redirect attacks. Unregister uses `hash_equals()` and returns 200 regardless of
  match to avoid record enumeration. Auto-escalation fan-out now uses `fastcgi_finish_request()`
  so breach-reporting installs aren't blocked while SC fans out.
- `smack-central/sc-network-fanout.php` — Shared curl_multi fan-out helper. Parallel delivery
  to all subscribers with 6s timeout per request. Auto-prunes subscribers at 5 consecutive
  delivery failures. Used by both the public API (auto-escalation) and admin console (manual push).
- `smack-central/sc-network-alert.php` — Push Subscribers panel: active count, per-site last-push
  timestamp, failure count (highlighted red at 3+), manual push button. Fan-out fires immediately
  when Sean sets a new alert level via the admin UI.
- `smack-central/schemas/sc-smackcent-canonical.sql` — New `sc_push_subscribers` table.
- `migrations/migrate-network-alert-push.sql` — Seeds four new `snap_settings` keys.
- `core/updater.php` — Migration registered in `UPDATER_KNOWN_MIGRATIONS`.

### Fix — SC self-updater now deploys web-root files and uses correct schema

- `smack-central/sc-update.php` — Pull now copies `projects/snapsmack-ca/` files to the
  snapsmack.ca web root (step 3b) in addition to `smack-central/`. Skips release zips,
  `.gitignore`, and `.sc-sessions/`. This means SC endpoints like `sc-network-api.php`
  at the web root self-update on pull — no manual FTP needed.
- Schema sync step now reads `schemas/sc-smackcent-canonical.sql` (the authoritative
  canonical file used by Schema Manager) instead of the legacy 78-line `sc-schema.sql`
  stub. New tables like `sc_push_subscribers` and `sc_network_alert_state` are now
  created automatically on SC pull.

### Fix — Network Alert API accessible at correct public URL

- `projects/snapsmack-ca/sc-network-api.php` — New file at the snapsmack.ca web root.
  The spoke-side network alert poller defaults to `https://snapsmack.ca/sc-network-api.php`
  but the API only existed inside `smack-central/` (served at `/smack-central/sc-network-api.php`).
  All polls were silently 404-ing, so no installs were receiving SC broadcast alerts or sending
  breach reports. Root-level file now resolves correctly with adjusted include paths
  (matches ping.php pattern). Both status and report routes present; auto-escalation logic intact.

---

## 0.7.203 — "Push It Real Good" (2026-06-04)

### Security — Hub/spoke attack surface (audit 021 F4, F5, F6)

- `core/multisite-api.php` — `settings/push` allowlist now includes all six `hub_controls_*` keys
  (`hub_controls_timezone`, `hub_controls_akismet`, `hub_controls_ai`, `hub_controls_smackback`,
  `hub_controls_comments`, `hub_controls_email`). Previously these were pushed by the hub but silently
  dropped on the spoke, so spoke setting-lock UI never activated. (audit 021 F5)
- `core/multisite-api.php` — `settings/push` allowlist now includes `ai_provider`, `ai_key_claude`,
  `ai_key_gemini`, `ai_key_openai`. Hub AI key push previously silently failed. (audit 021 F6)
- `core/multisite-api.php` — `smackback_mode` downgrades (lockout → alert) via hub push are now gated
  to pending-confirmation, same as `smackback_enabled = 0`. A compromised hub can no longer silently
  weaken protection mode before attacking. Stored as `smackback_hub_pending_mode`. (audit 021 F4)
- `smack-back.php` — New APPROVE / REJECT UI box for hub-requested mode downgrades, matching the
  existing pending-disable flow. Confirm/reject POST handlers clear the pending flag. (audit 021 F4)

### Fixed — smack-skin.php fatal crash on carousel sites with no carousel skin installed
- `smack-skin.php` — mode filter could empty `$available_skins` on carousel installs
  (The Grid is not in the base release), causing `array_key_first()` to return null and
  PHP 8 strict typing to throw a TypeError. Now falls back to unfiltered skins if the
  mode filter produces no results, keeping the admin page accessible.

### Fixed — smack-edit.php 500 on missing collection columns
- `smack-edit.php` — defensive `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` for
  `snap_collection_items.item_type`, `item_id`, and `sort_order`. Sites that received
  the table before the polymorphic migration ran were hitting a hard 500.

## 0.7.202 — "Push It Real Good" (2026-06-04)

### Changed — Thomas the Bear privacy
- `core/footer-scripts.php` — Thomas ping UID is now a random 32-char hex generated at install time and stored in `snap_settings` as `thomas_uid`. Previously it was SHA-256 of the site URL, which could be reverse-engineered by anyone who knew the URL. The new UID is anonymous and not linkable to any site.
- `assets/js/ss-engine-thomas.js` — updated comment to document the anonymous UID approach.
- `projects/snapsmack-ca/releases/thomas-ping.php` — updated comment to match.
- `projects/snapsmack-ca/tnb.php` (snapsmack.ca privacy page) — added "Version checks and install counting" section documenting the update ping payload (pseudonymous UID, version, track, spoke count); added "Thomas the Bear" section disclosing the Easter egg ping; updated "short version" to correctly distinguish pseudonymous automatic pings from opt-in features that identify your site by name.

## 0.7.201 — "Push It Real Good" (2026-06-04)

### Added — SMACKBACK admin UI
- `smack-back.php` — master toggle now uses a proper toggle-switch; dependent settings (threshold, notifications, auto-restore) visually grey out when SMACKBACK is disabled.
- Hub pending-disable flow: when a hub pushes a SMACKBACK disable request to a spoke, the spoke's SMACKBACK page shows an orange warning box with APPROVE and REJECT buttons instead of silently disabling. Spoke remains active until the admin explicitly approves.

### Changed — smack-push-it.php
- All 6 hub control checkbox groups converted to toggle-switches for visual consistency.

### Fixed — smack-settings.php
- Hub is now exempt from its own `hub_controls_*` lock gates. Hubs were incorrectly seeing "MANAGED BY NETWORK HUB" banners on their own settings page.

### Fixed — multisite sidebar
- `core/sidebar.php` — multisite quick-links hidden when already on a smack-multisite-* or smack-push-it.php page.

### Security — SSRF
- `core/mesh-helpers.php` — new `ms_is_safe_remote_url()` rejects RFC1918, loopback, and link-local addresses before any outbound multisite request.
- `core/multisite-api.php` — `posts/create` (img_url) and `skins/reinstall` (download_url) now validated through `ms_is_safe_remote_url()`. Forged internal URLs rejected with 400.

### Security — hub trust model
- Hub can no longer silently disable SMACKBACK on a spoke via push. Disable requests are stored as `smackback_hub_pending_disable` and require explicit spoke admin approval before taking effect.

### Added — smack-multisite.php
- Security warning on the spoke token-generation page: "Only connect to a hub you personally own and control." Hub gets admin-level access to the spoke; users should understand this before connecting.

## 0.7.200 — (2026-06-03)

### Fixed
- `core/updater.php` — spokes no longer phone home independently. Spokes are already counted via their hub's `spoke_count`; independent pings were causing every spoke to be double-counted on the SC dashboard install tally. Spokes with `multisite_role = 'spoke'` now skip the ping entirely. Existing spoke rows in `sc_phone_home` will age out of the 90-day active window once they stop updating their `last_seen`.
- `core/community-component.php` — added defensive `SHOW COLUMNS` guard for `guest_url` (added in 0.7.189). Sites that haven't run `migrate-comment-url.sql` were throwing a fatal PDOException mid-layout, which killed `skin-footer.php` before it could output manifest JS — including `ss-engine-image-fade-load`. Net effect: hero images invisible site-wide. Guard matches the existing `edited_at` pattern; column falls back to `NULL AS guest_url` on unmigrated sites.

## 0.7.199 — "Grid Lighttable" (2026-06-02)

### Added — smack-lt-gram.php
- New visual 3-column feed reorder tool for Grid (GramOfSmack) installs.
- All posts (published + draft) rendered as a live grid preview matching the live skin layout.
- Drag-to-reorder via SortableJS; sort order auto-saved on drop.
- Draft badges, carousel image count indicators, per-tile publish button, bulk publish.
- Trigram grouping: tiles with a `trigram_id` are visually outlined. Alignment check runs after every drag — warns if a trigram's three tiles don't form a valid horizontal row or vertical column.
- Sidebar shows "Grid Lighttable" link on carousel installs, "Light Table" on all others.

### Added — migrations
- `migrate-trigrams.sql` — creates `snap_trigrams` table (id, source_path, orientation h/v, cut_a, cut_b, post_id_1/2/3, created_at) and adds `trigram_id` FK column to `snap_posts`.
- `migrate-posts-sort-order.sql` — adds `sort_order` column to `snap_posts` for manual feed ordering.

### Changed
- `skins/the-grid/landing.php` — ORDER BY now uses `p.sort_order ASC, p.created_at DESC` so lighttable reorders are reflected on the live grid.
- `database/schema/snapsmack_canonical.sql` — `snap_trigrams` table added; `trigram_id` and `sort_order` columns added to `snap_posts`.
- `core/updater.php` — both new migrations registered in `UPDATER_KNOWN_MIGRATIONS`.

## 0.7.198 — "Trigram Schema" (2026-06-02)

### Added
- `migrations/migrate-trigrams.sql` — snap_trigrams table + trigram_id FK on snap_posts (orientation-aware: h/v, cut_a/cut_b, post_id_1/2/3).
- `migrations/migrate-posts-sort-order.sql` — sort_order column on snap_posts.
- Both migrations registered in `UPDATER_KNOWN_MIGRATIONS`.
- Canonical schema updated accordingly.

## 0.7.197 — "Push It Real Good" (2026-06-02)

### Changed — GramOfSmack mode gating
- **Admin sidebar** (`core/sidebar.php`) — Categories, Albums, and Collections nav items are now hidden when `site_mode = 'carousel'`. GramOfSmack installs see a clean sidebar without photoblog-only taxonomy UI.
- **GramOfSmack posting page** (`smack-post-gram.php`) — removed Categories and Albums selectors, their DB queries, and their insert logic. GramOfSmack posts have no taxonomy assignment.
- **Carousel editor** (`smack-edit-carousel.php`) — Categories and Albums selectors, DB queries, and map rebuilds are now gated behind `site_mode !== 'carousel'`. Photoblog carousel edits unchanged. GramOfSmack edits skip taxonomy entirely.

### Changed — Unzucker API cleanup
- **Unzucker import API** (`core/unzucker-api.php`) — `title`, `cat_ids`, and `album_ids` fields removed from the `POST unzucker/posts` request. Instagram exports have no titles and GramOfSmack has no taxonomy. Post and image slugs now generated from IG media ID (`ig_id`) or timestamp. Empty string stored in `title` column to preserve schema.

## 0.7.196 — "Push It Real Good" (2026-06-02)

### Fixed
- **stats.php** — added all-time views and unique visitor totals (`views_all`, `unique_all`) to the public stats endpoint for snapsmack.ca skin gallery tooltips.

## 0.7.195 — "Push It Real Good" (2026-06-01)

### Added
- **GramOfSmack post page** (`smack-post-gram.php`) — dedicated posting UI for mode 2.0 / The Grid installs. Carousel backend, stripped of per-image EXIF panels and frame style controls. Routed via skin manifest `post_page => gram`.
- **Multisite SSO token endpoint** (`core/multisite-api.php`) — implements `POST multisite/auth/sso-token`. Generates a 64-hex one-time token stored in snap_settings, valid 5 minutes. Enables REMOTE LOGIN from the hub multisite dashboard.

### Fixed
- **The Grid manifest** (`skins/the-grid/manifest.php`) — `post_page` set to `gram` so new posts route to `smack-post-gram.php` instead of falling through to solo.

## 0.7.194 — "Push It Real Good" (2026-06-01)

### Fixed
- **stats.php DB bootstrap** — `stats.php` was requiring `core/constants.php` only, making `DB_HOST` undefined and returning 500 on every request. Switched to `core/db.php` (which defines the DB constants and provides `$pdo`). snapsmack.ca skin gallery stats now populate correctly.
- **Multisite manual ping** (`smack-multisite.php`) — VERIFY CONNECTION single-node UPDATE was missing `site_mode`, `maintenance_mode`, `smackback_status`, and `smackback_breach_at`. Now matches the fleet sweep.
- **snapsmack.ca install counter** (`projects/snapsmack-ca/releases/ping.php`) — `sc-config.php` was required via `../../` (two levels up, outside web root). Corrected to `../` — `releases/` and `smack-central/` are siblings under the web root.

### Changed
- **Admin footer** (`core/admin-footer.php`) — Install mode (1.0 / 2.0 / 3.0) now shown in footer alongside version string.
- **Installer fallback version** (`install.php`) — Hardcoded fallback bumped to current. Live path via `core/constants.php` was already correct; fallback was stale.

## 0.7.193 — "Push It Real Good" (2026-06-01)

### Fixed
- **Multisite spoke sort** (`smack-multisite.php`) — `ORDER BY role ASC, name ASC` referenced non-existent column `name`; corrected to `site_name`. Caused 500 on all multisite pages.

## 0.7.192 — "Push It Real Good" (2026-05-31)

### Added
- **Push It (Push It Real Good)** (`smack-push-it.php`) — dedicated hub-only fleet settings control page. Per-group toggles for hub ownership: when Hub Controls is on for a group, the hub owns that setting fleet-wide and spoke UI for that section locks to read-only ("Managed by Network Hub"). Groups: timezone/date format, Akismet key, AI provider + API keys + crawler policy, SMACKBACK enabled/mode, global comments, contact email. PUSH IT ALL button pushes every hub-controlled group simultaneously. Spoke-side gating implemented in `smack-settings.php` and `smack-back.php` — locked fields show current value with ⊘ notice; form save skips locked fields so hub-pushed values persist.
- **SMACK-BACK** (`smack-back.php`) — SMACKBACK integrity monitor UI renamed from `smack-smackback.php`. Full restyle to standard box/lens-input-wrapper/dash-grid patterns. Old URL kept as a 301 redirect stub.
- Admin themes: **Prideful** and **Red John** manifests (`prideful-manifest.php`, `red-john-manifest.php`) — both themes existed but lacked manifest files, causing blank entries in the Core Admin Theme dropdown. Manifests created.
- **Unzucker API** (`core/unzucker-api.php`, `api.php`) — new authenticated JSON API for the Unzucker Instagram import desktop tool. Routes: `GET unzucker/ping`, `GET unzucker/site` (categories + albums), `POST unzucker/posts` (create post from FTP'd image paths, handles single and carousel). Bearer token auth via `snap_ohsnap_keys` (`key_type = 'unzucker'`). Duplicate detection via `import_source = 'instagram'` / `import_id`.
- **Unzucker key type** (`smack-api-keys.php`) — `unzucker` added to the key type dropdown.


### Fixed
- **Admin theme dropdown blank entries** — `smack-globalvibe.php` discovery returns an empty array when no manifest exists; the fallback only fires on non-array, so missing manifests silently produced blank `<option>` entries. Fixed by creating the missing manifests for Prideful and Red John.
- **Multisite registration token contrast** (`smack-multisite.php`) — token display used `color:inherit` which inherited a dark colour on some themes, making the token nearly invisible on the dark input background. Hardcoded to `color:#e0e0e0`.
- **smack-multisite-settings.php CSRF theater** — CSRF token was rendered in all push forms but never validated server-side. POST handlers now check `hash_equals()` before executing.
- **smack-push-it.php SSL verification** — cURL push helper was missing `CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST`. Added. Hub-guard added (spoke admins redirected to dashboard).

### Changed
- **Fleet settings push** moved from `smack-multisite-settings.php` to `smack-push-it.php`. Settings page now shows only the Downloads section. PUSH IT nav tab added to all multisite sub-pages.
- **AI push group** expanded (`smack-push-it.php`) — now pushes `ai_provider`, `ai_key_claude`, `ai_key_gemini`, `ai_key_openai`, `ai_training_policy`, and `hub_controls_ai`. Was AI training policy only.
- **Multisite spoke sort order** (`smack-multisite.php`, `smack-multisite-settings.php`) — fleet roster now sorted A→Z by name. Was insertion order (connected_at DESC).
- **Dapper Dan admin theme** (`admin-theme-colours-dapper-dan.css`) v1.1 — background restored to dark red canvas (`#1a0808`) from near-black (`#0D0D0D`). Red accent restored to bright `#CC1111` from oxblood `#630D0D`.
- **Prideful admin theme** (`admin-theme-colours-prideful.css`) — full rewrite. Light blue canvas (`#cce4ff`), purple sidebar, red page headers, orange section titles, green stats and action buttons, orange footer, rainbow progress bar. Previous version was a tasteful white background. It was lame.
- **Red John admin theme** (`admin-theme-colours-red-john.css`) — contrast pass. All low-contrast muted text colours raised to readable levels on the dark red background. (`#662222`→`#bb5555`, `#551111`→`#993333`, `#441111`→`#883333`, `#440000`→`#772222`).
- **API Keys page** (`smack-api-keys.php`) — renamed from "Oh Snap! API Keys" to "API Keys". Rebuilt to standard admin UI patterns (box/lens-input-wrapper/recent-item/master-update-btn). Oh Snap!-specific instructions section removed. Label field no longer prepopulated.
- **Unzucker** (`tools/unzucker/`) — switched from session cookie auth to Bearer token API key auth. No more login timeout. `SnapSmackClient` replaced with `UnzuckerClient`. BeautifulSoup dependency removed. `config.py` drops username/password/remember in favour of `api_key`. `main.py` CONNECTION box updated (API KEY field, session timer gone). Build bumped to 0.7.8.


## 0.7.191 — "Shit and Git" (2026-05-31)

### Added
- **Shortcodes system** (`core/parser.php`, `assets/css/shortcodes.css`, `smack-shortcodes.php`, `core/sidebar.php`, `core/meta.php`) — `parseAttrs()` helper; 4 layout shortcodes (`[columns]`, `[column]`, `[box]`, `[divider]`); 6 prose shortcodes (`[note]`, `[warn]`, `[tip]`, `[quote]`, `[code]`, `[kbd]`). SMACKCODES admin reference page with live previews and copy buttons (carousel-gated in sidebar).

### Fixed
- **`stats.php` parse error** — EOF marker was `<?php // ===== SNAPSMACK EOF =====` in a pure-PHP file (no closing `?>`). PHP re-open tag caused fatal parse error on every request. Corrected to `// ===== SNAPSMACK EOF =====`.
- **snapsmack.ca skin gallery stats** — Cards now curl each site's `/stats.php` directly with a 1-hour file cache. Previous approach (SC DB populated by phone-home ping) was wrong by design; stats were never SC's data to collect. Skin card tooltip brought closer to cursor (8px offset) and uses measured tooltip width for accurate viewport-edge flip.
- **Hub-triggered spoke updates now phone home** (`core/multisite-api.php`) — Explicit `_updater_ping_home()` call added as step 10 of the `multisite/updates/trigger` endpoint, after extraction and migration. Bootstraps the SC Active Installs counter even on the first update that introduces the phone-home system (previously the spoke ran its old updater.php during the trigger, which predated the ping function).

### Changed
- **Phone-home ping** (`core/updater.php`) — Stripped to install identity only: `uid`, `version`, `track`, `spoke_count`. Post counts, traffic, and site name removed entirely. Not appropriate to collect.
- **SHARE STATS toggle removed** (`smack-settings.php`, `projects/snapsmack-ca/releases/ping.php`) — Setting and stat columns no longer sent or stored.
- **Skins no longer bundled in release zip** (`smack-central/sc-release.php`) — `50-shades-of-noah-grey` and `new-horizon` removed from the package. Installer fetches mode-appropriate skins at install time instead.
- **Installer fetches skins at install time** (`install.php`) — Step 5 curls `snapsmack.ca/releases/skins/install-manifest.php` and installs the correct skins for the chosen mode. Mode 1 → new-horizon + 50-shades; Mode 2 → The Grid; Mode 3 → new-horizon (placeholder). Non-fatal if unreachable.
- **install-manifest.php** (`projects/snapsmack-ca/releases/skins/install-manifest.php`) — New registry-driven endpoint. Reads `modes` from registry.json — no hardcoded mapping. Repackaging a skin updates what the installer fetches automatically.
- **Skin Packager** (`smack-central/sc-skins.php`) — Now reads `modes` from skin manifests and writes to registry.json.
- **Skin manifests** — `modes` field added to `new-horizon` (`photoblog`, `smacktalk`), `50-shades-of-noah-grey` (`photoblog`), and `the-grid` (`carousel`).
- **install.php** — Step 1b heading updated. Default skin now set per install mode before DB seed.

## 0.7.189 — "Sit and Spin" (2026-05-30)

### Fixed
- **Chaplin archive layout** — Replaced broken chap-* archive CSS/PHP with direct 50-shades fsog-* port. Archive reads global Archive Appearance settings (browse_cols, archive_gutter, main_canvas_width) instead of skin-specific overrides.
- **Chaplin content width unified** — Single `chap_photo_max_width` (renamed "Content Max Width") controls header, photo frame, archive grid, masonry, and footer. Previously each had its own hardcoded value.
- **Chaplin header alignment** — Removed `padding: 0 var(--space-xl)` from `.rg-header-inside`; header text now aligns with grid edges.
- **Chaplin font size sliders** — Embedded inside font picker box via `sz_key_override` + `size` config. Standalone `_size` manifest entries removed.
- **Chaplin footer colour picker** — New greyscale colour picker in Typography for system footer text.
- **Chaplin calendar** — Panel side and month count now always respect Archive Appearance settings. `core/meta.php` outputs calendar config unconditionally (was gated behind `smack-calendar` in require_scripts).
- **Archive Appearance border** — Grid thumb border selector extended to include `fsog-` classes (Chaplin archive now respects Archive Appearance border settings).
- **Chaplin calendar font** — Calendar panel uses DM Sans (loaded via font-loader) for readability.
- **smack-update.php** — SMACKBACK manifest `warn` row shows `—` instead of `✗`. Adds `.step-warn` CSS class.
- **smack-skin.php** — Embedded font size slider supports `sz_key_override` and custom `unit` display label.

## 0.7.188 — "Sit and Spin" (2026-05-30)

### Added
- **`stats.php`** — Public JSON stats endpoint. Returns site_name, post count, 30-day views and unique visitors, active_since date, and installed version. CORS-allowed from snapsmack.ca. No auth, no personal data. Powers skin gallery hover cards.
- **Install phone-home** (`core/updater.php`) — Each install gets a unique `install_uid` in `snap_settings` (lazy-generated on first update check). Sent to snapsmack.ca/releases/ping.php on non-fast update checks. SC counts unique UIDs seen in 90 days for Active Installs.

### Fixed
- **SC Active Installs now counts unique phone-home UIDs** (`sc-dashboard.php`) — was counting forum registrations. Now reads `sc_phone_home` (90-day window). Falls back to 0 if table not yet created.
- **Chaplin photo max-width** (`skins/chaplin/style.css`, `skin-header.php`, `manifest.php`) — New `chap_photo_max_width` manifest entry (default 1600px, 800–2560 range) → `--chap-photo-max-width` CSS var → `.rg-photo-wrap max-width`. Independent from archive grid width.
- **Rational Geo photo max-width** (`skins/rational-geo/style.css`) — `.rg-photo-wrap` now respects the existing Content Width slider (`--rg-canvas-width`).

## 0.7.187 — "Barcalounger" (2026-05-30)

### Added
- **Network Settings Push** (`smack-multisite-settings.php`) — Hub can push timezone, Akismet key, AI training policy, SMACKBACK enabled/mode, global comments, and contact email to all spokes from one page. Download settings push to a custom selectable subset of spokes, with the selection persisted.
- **`POST multisite/settings/push` API endpoint** — Spoke-side receiver for hub-pushed settings. Explicit allowlist of 10 keys. Hub Bearer auth required.
- **Multisite SETTINGS nav** — SETTINGS link added to all multisite nav tabs.
- **Chaplin: Separate Masthead and Page Title fonts** — Masthead font (publication identity, site header) and Page Title font (h1 on Blogroll, Archive, static pages) are now independently configurable. Masthead ≠ Page Title — different semantic jobs, different controls.
- **Chaplin: Nav font, size, and colour controls** — Top navigation has its own font, size, and colour pickers in Typography.
- **Greyscale colour picker** — `is_greyscale: true` manifest flag renders 7 swatch buttons (black → white, even steps) instead of a free colour input. Appropriate for monochrome themes.
- **Body size cascades to static pages** — Body size slider now affects blogroll descriptions and static page body text, not just post content.
- **Skin version standardisation** — All 15 skin manifests now use MAJOR.MINOR.PATCH versioning. Two-part versions (e.g. 1.3) extended to three-part (1.3.0).

### Fixed
- **Post-update "Could Not Reach Update Server" false positive** — The 14-session bug. `$cached_result` was nulled after `stage_migrate` on the same POST request that renders the post-update page. The GET-only re-check block couldn't fire. Fix: write `up_to_date` to DB cache and hydrate all page vars on the POST itself. Post-update page now shows "✓ UP TO DATE".
- **Chaplin font size sliders** — Size manifest entries had `selector: ''` and `property: ''` — CSS compiler skipped them entirely. Fix: wired to `:root` CSS variables, consumed via `calc(var * 0.1rem)` throughout. The ×0.1rem scale is preserved; `skin-header.php` updated to output unitless integers to match.
- **Multisite action column alignment** — REMOTE LOGIN, MAINT ON/OFF, and DISCONNECT now use a flex container with `display:contents` on forms and `min-width` per button type. Columns align across all spoke rows.

## 0.7.186 — "Barcalounger" (2026-05-30)

### Added
- **`smack-schema.php`** — Spoke admin database schema sync tool. Diffs live snap_* database against `snapsmack_canonical.sql`, shows missing tables and columns, applies `ALTER TABLE / CREATE TABLE` with one click. Fixes the gap where canonical schema sync was SC-only and couldn't reach spoke databases.
- **`migrations/migrate-collection-items-polymorphic.sql`** — Adds `item_type`, `item_id`, `sort_order` columns to `snap_collection_items`. Fixes 500 error on `smack-edit.php` for all installs upgraded from pre-polymorphic schema.

### Changed
- **Chaplin film engine** (`ss-engine-chaplin-film.js`): Scratches now drawn in segments with per-frame lateral drift and per-segment wobble — behave like real film scratches. Dust spots added (brief white specks, occasional clusters). Hairs added (rare curved dark strands, quadratic bezier, 15–50 frame life).
- **Multisite management**: Maintenance mode button now shows current state (MAINT ON / MAINT OFF) rather than the action, eliminating the inverted-label confusion.
- **`database/schema/snapsmack_canonical.sql`**: `snap_collection_items` updated to polymorphic structure.

### Fixed
- CSS custom property values were being passed through `htmlspecialchars()` in Chaplin `skin-header.php`, encoding single quotes in font-family names to `&#039;` — breaking all font selections on the live site.
- Chaplin archive grid column count slider had no effect — `--grid-cols` was applied by admin JS preview only, never emitted server-side. Now emitted in `$css_vars`.

---

## 0.7.185 — "Barcalounger" (2026-05-29)

### Added
- **`core/font-loader.php`** — Shared font loading helper `snapsmack_emit_font_tags(array $font_keys, string $base_url)`. Replaces per-skin hardcoded Google Fonts `<link>` tags. Checks each font against manifest inventory: local fonts get `@font-face` blocks, Google Fonts get a single combined API request, system fonts are silently skipped. All skins updated to use this helper.

### Changed
- **Chaplin skin (v0.2.7dev)**: Landing page bottom nav now visible (system footer was rendering on `.chap-landing`, overflowing viewport). Typography section cleaned up — no phantom size sliders. `chap_heading_size` and `chap_body_size` sliders paired with their font pickers. Palette neutralized: dim/muted ink and border vars shifted from warm sepia tones (`#8a8070`, `#504840`) to neutral greys (`#888888`, `#484848`) to match the silver Art Deco frame aesthetic.
- **All skins with font pickers** (new-horizon, 50-shades-of-noah-grey, rational-geo, galleria, impact-printer): `skin-header.php` updated to call `snapsmack_emit_font_tags()` — selected fonts now actually load on the public page.

### Fixed
- Font picker selections were silently ignored on the public page for all skins — hardcoded font loading loaded the skin default regardless of what the user selected. Choosing a local font (FlottFlott, BlackCasper, etc.) or any non-default Google font now renders correctly.

---

## 0.7.184 — "Barcalounger" (2026-05-26)

### Changed
- **Image Sorter renamed to Light Table**: `smack-sorter.php` → `smack-lighttable.php`, `ss-engine-sorter.js` → `ss-engine-lighttable.js`, sidebar nav and all display text updated.
- **FLKR DCKR renamed to FLKR FCKR**: Tool renamed throughout — `core/flkrfckr-api.php`, `tools/flkr-fckr/`, API route `flkrfckr/*`, key type `flkrfckr`, all UI labels, help topics, and snapsmack.ca static copy updated. (Flickr price hike. You know why.)



### Fixed
- **Multisite "all spokes behind" false positive (root cause fix)**: The 0.7.183 fix corrected `snap_version_compare()` but the stored values in `snap_multisite_nodes.software_version` still contained the raw `"Alpha X.Y.Z"` prefix, making SQL `!=` comparisons fail even when PHP comparisons worked. Both heartbeat `UPDATE` paths in `smack-multisite.php` now normalise via `preg_replace('/^[^0-9]+/', '', ...)` before writing to the database. Belt-and-suspenders: `core/multisite-api.php` heartbeat response now sends `SNAPSMACK_VERSION_SHORT` (clean numeric string) instead of `SNAPSMACK_VERSION`.

### Added
- **SMACKBACK — Skin JS Security Scanner**: New section in `Admin → SMACKBACK` scans every non-base installed skin for `eval()` calls (always a violation), external scripts loaded from untrusted domains (violation), `atob()` (warning), `document.write()` (warning), and inline `<script>` blocks (info). Results persist in `snap_settings` and display per-skin with collapsible findings tables. A `skin_allow_custom_js` toggle demotes inline-script and external-script findings from violation to warning; `eval()` is always flagged as a violation regardless of this setting. Trusted CDN domains (cdnjs.cloudflare.com, fonts.googleapis.com, cdn.jsdelivr.net, code.jquery.com, unpkg.com) are never flagged for external scripts.
- **Archive page as homepage**: `Settings → Configuration → Landing Page` now includes an `ARCHIVE PAGE` option. When selected, the root URL issues a 302 redirect to `/archive`. Two sub-controls appear: opening layout (`MASONRY` or `THUMBS`) and, when Thumbs is chosen, thumbnail crop style (`CROPPED` or `SQUARE`). These settings override the skin manifest defaults for the archive page.
- **Update Track selector**: `Settings → Configuration` gains an `UPDATE TRACK` section. Sites choose `BORING` (stable releases only, default) or `BITCHIN'` (dev + stable releases, opt-in). The updater reads this setting and queries `latest.json` (stable) or `latest-dev.json` (dev) accordingly. A safety filter prevents D-suffixed dev builds from being offered to stable-track installs.
- **Branching strategy infrastructure (Phase 1 + 2)**: `master` = stable, `dev` = active development. D-suffix version convention established (`0.7.184D` etc.). Per-site `update_track` setting controls which release stream a site follows. Heartbeat now reports each spoke's track back to the hub; the multisite fleet table gains a `TRACK` column showing `BORING` (grey) or `BITCHIN'` (amber) per spoke. New `BRANCHING.md` at repo root documents the strategy.

## 0.7.183 — "Barcalounger" (2026-05-25)

### Fixed
- **Multisite UPDATE ALL BEHIND false positives**: If `latest.json` on snapsmack.ca contains a non-numeric prefix in the `version` field (e.g. `Alpha 0.7.182` instead of `0.7.182`), `snap_version_compare()` treated `Alpha` as a pre-release tag and incorrectly flagged every spoke as behind. Fixed in three places: `snap_version_compare()` now strips non-numeric prefix before comparing; `multisite-api.php` normalizes `to_version` in the update response; `smack-multisite.php` normalizes before writing `software_version` to DB.

## 0.7.182 — "Barcalounger" (2026-05-24)

### Fixed
- **Configuration save button broken on all sites**: The SAVE GLOBAL ENGINE CONFIGURATION button was disconnected. The SmackAttack and API Access sections each contained `<form>` elements nested inside the main `#config-form` — illegal in HTML. Browsers implicitly close the outer form at the first nested tag, leaving the save button orphaned. Fixed by extracting all action forms outside the main form and wiring their buttons back via the HTML5 `form="id"` attribute.
- **Update checker hanging on connectivity failure**: `check_ajax` now uses `$fast = true` mode — single 6-second cURL attempt, skin registry skipped. JS auto-check fires on stale/missing cache with a 15-second `AbortController` timeout; if PHP still hangs at OS level the browser bails and shows a manual CHECK NOW button. On success the page reloads with fresh data.
- **Rational Geo system footer missing on single-image page**: `.rg-single #system-footer { display: none }` CSS rule was hiding the footer on the solo view. Archive page was unaffected. Removed `#system-footer` from the hide selector.

### Added
- **Chaplin v2.5**: Full rebuild from Rational Geo structural base. Previous version retained Galleria `frame-mount/frame-bevel` div structure and had an incomplete `style.css`. New build: full RG structural CSS with Chaplin palette overlay, intertitle overlay (INFO/SIGNALS tabs), filmstrip, Art Deco ornament overlay, capture-phase overlay JS with `window.smackdown` bridge. `skin-page.php` (Galleria remnant) deleted.
- **smack-help.php**: Initial help system file.
- **Black Pearl admin theme**: Logout grey box fixed; skin gallery REFRESH link coloured.

## 0.7.181 — "Barcalounger" (2026-05-24)

### Fixed
- **Black Pearl admin theme — logout grey box**: `.sidebar-bottom` was `#0D0D0D` against the `#000000` sidebar, creating a visible grey box around the logout link. Set to `#000000` to match.
- **Black Pearl admin theme — REFRESH link colour**: Skin gallery REFRESH link had no theme-specific colour rule. Added `.registry-info a` styling to Black Pearl only (`#666666` / `#DFDFDF` hover).

## 0.7.180 — "Barcalounger" (2026-05-23)

### Fixed
- **Rational Geo 2.1.2**: Post date now centred to match title and description alignment.
- **Date/time live preview in Configuration**: Clock was showing the hardcoded example date from the format dropdown label (always "February 1, 2026") rather than today's date. Rewrote `updateClock()` in `ss-engine-admin-ui.js` to parse the PHP format code from the select value and format the real current date using timezone-aware `Intl` APIs.

### Changed
- **`.gitignore`**: Added `tools/oh-snap/src-tauri/target/` and `tools/oh-snap/node_modules/` — Rust/Tauri build artifacts that should never have been tracked.

## 0.7.179 — "Barcalounger" (2026-05-23)

### Changed
- **Rational Geo 2.1.1**: Photo title centred, description text fully justified (`text-align: justify`), description column centred under image. Per-role typography cards (Masthead, Body, EXIF, Comment each get their own font picker + size slider).

## 0.7.178 — "Barcalounger" (2026-05-23)

### Fixed
- **Maintenance mode never showed the offline page on public-facing pages**: `maintenance-gate.php` was included before `$settings` was loaded from the database in all eight public controllers (`index.php`, `archive.php`, `blogroll.php`, `page.php`, `gallery-wall.php`, `collections.php`, `collection.php`, `albums.php`). At that point `$settings` was an empty array, so `$settings['maintenance_mode'] ?? '0'` always returned `'0'` and every visitor passed through regardless of the admin setting. Moved the `require_once maintenance-gate.php` line to immediately after the `fetchAll()` settings query in every affected file.

## 0.7.177 — "Barcalounger" (2026-05-23)

### Fixed
- **Multisite: UPDATE ALL BEHIND count always showed total spoke count**: Heartbeat and registration endpoints were returning `SNAPSMACK_VERSION` (`"Alpha 0.7.176"`) for the `version` field stored in `snap_multisite_nodes.software_version`. The hub's `$behind_count` compared that against `SNAPSMACK_VERSION_SHORT` (`"0.7.176"`). PHP's `version_compare()` treats the `"Alpha"` prefix as a pre-release qualifier, making every spoke appear permanently behind the hub. Both endpoints now return `SNAPSMACK_VERSION_SHORT` so the comparison is clean numeric-vs-numeric.

## 0.7.176 — "Barcalounger" (2026-05-23)

### Added
- **Universal font size slider**: Every font picker in every skin's settings page now includes a size slider (rem) beneath the font preview, automatically. No skin manifest changes required. Skins can optionally declare a `size` key on any font-family option to override the default 0.7–2.0rem range with values appropriate for that font role.
- **Skin manifest: inline `size` key for font pickers**: Font picker options can now declare `'size' => ['default' => '1.0', 'min' => '0.7', 'max' => '2.0', 'step' => '0.05']` to provide a custom size range. The engine reads it automatically — no separate range entry needed.
- **Skin manifest: `spacer` type**: Options with `'type' => 'spacer'` render an empty grid cell, allowing skin authors to control alignment in the 3-column settings grid.

### Changed
- **Rational Geo 2.1.0**: Typography section reorganised — each font role (Masthead, Body, EXIF, Comment) now has its own settings card with the font picker and size slider together. Custom size ranges declared per role. Archive page header padding removed so site name aligns flush with the edge-to-edge justified grid. Photo title centred, description text right-aligned and centred within the content column. Comment text separated into its own font picker (previously shared with body font selector).
- **`smack-skin.php`**: CSS generator and rendering loop updated to support inline `size` companion and `spacer` type.

## 0.7.175 — "Barcalounger" (2026-05-23)

### Added
- **SMACKBACK Phase 2 — hub/spoke breach correlation**: Spokes running in paranoid mode now actively report breaches to their hub via `POST multisite/smackback/report`. The hub stores the breach against the spoke's node record, displays a BREACH/CLEAN badge in the multisite table, and fires a coordinated-attack alert email if two or more spokes are simultaneously in breach. Heartbeat sweep also caches `smackback_status` passively so the hub stays current even if the push report fails. New migration: `migrate-smackback-multisite.sql` (3 columns on `snap_multisite_nodes`).

## 0.7.174 — "Barcalounger" (2026-05-23)

### Fixed
- **Update check: silent JS retry loop instead of immediate red banner**: On sites with flaky connectivity to the update server, the synchronous PHP check was firing on page load and immediately showing the red COULD NOT REACH UPDATE SERVER banner. Replaced with a deferred JS fetch loop: page loads with a neutral ⌛ CHECKING badge, then retries the check_ajax POST endpoint up to 3 times (delays: 0 ms / 500 ms / 1500 ms / 3000 ms). On success the page reloads to show current status. The red banner only appears after all three retries fail, giving transient connectivity issues a chance to resolve without alarming the user.

## 0.7.173 — "Barcalounger" (2026-05-23)

### Fixed
- **Chaplin single photo page — photo overflows viewport, no scroll**: `#page-wrapper` had no height set, breaking the flex chain from `body { height: 100% }` down through `.chap-single { flex: 1 }`. Photo expanded to natural height, body `overflow: hidden` trapped it with no scroll. Fix: added `#page-wrapper { height: 100%; display: flex; flex-direction: column; }` to complete the chain. Also tightened `#rg-photobox` from `overflow: visible` to `overflow: hidden` as a safety net.
- **Chaplin v2.5** (skin version bump)

## 0.7.172 — "Barcalounger" (2026-05-23)

### Fixed
- **Admin theme colour bleed on maintenance buttons**: MAINTENANCE ALL ON and per-spoke MAINT ON/OFF buttons were using inline `style="background:var(--warning,#c55400)"`. The `--warning` CSS variable is not defined in any admin theme, so the hardcoded orange fallback fired in every theme including amber-phosphorus and green-phosphorus. Replaced with proper `.btn-smack.btn-warning` class (added to geometry master + all 16 admin theme colour files with per-theme-appropriate colours) and `.action-warning` outline class for the per-spoke table button.
- **Multisite bulk buttons not on same row**: UPDATE ALL BEHIND / MAINTENANCE ALL ON / MAINTENANCE ALL OFF buttons were stacking because the wrapper div had no flex context. Wrapper is now `display:flex; gap:8px; align-items:center; flex-wrap:wrap`.

## 0.7.171 — "Barcalounger" (2026-05-23)

### Added
- **Hub maintenance mode controls**: Hub administrators can now put individual spokes into maintenance mode remotely, or toggle all active spokes at once, directly from the Multisite Management dashboard. No need to remote-login into each spoke individually.
  - Per-spoke MAINT ON / MAINT OFF toggle button in the Connected Spokes table
  - MAINTENANCE ALL ON / MAINTENANCE ALL OFF bulk buttons above the table (with confirmation prompt)
  - MAINT column shows current state for each spoke: orange dot = in maintenance, green dot = live
  - Maintenance state is cached in `snap_multisite_nodes.maintenance_mode` and refreshed on every heartbeat sweep, so the dashboard always reflects current spoke state without an extra request
  - New API endpoint: `POST multisite/maintenance/set` — hub-authenticated, accepts `{"mode": 1}` or `{"mode": 0}`, updates `snap_settings.maintenance_mode` on the spoke
  - Heartbeat response now includes `maintenance_mode` field
  - **`migrations/migrate-spoke-maintenance-mode.sql`** — adds `maintenance_mode TINYINT(1)` to `snap_multisite_nodes`
- **Rational Geo — header/archive alignment fix**: The `.rg-header-inside` CSS container was hardcoded to `1400px` regardless of the `main_canvas_width` setting. `skin-header.php` now outputs `--rg-canvas-width` as an inline `:root` variable driven by `main_canvas_width`, so the header and the justified grid stay in alignment at any canvas width.

### Fixed
- **Release package bloat**: `reference/` (111 MB of skin screenshots, assets, and SQL backups) was inadvertently committed to git and not excluded from the release packager, causing the 0.7.170 package to balloon from ~1.1 MB to 73 MB. Added `reference/` to `always_exclude` in `smack-central/sc-release.php` and to `.gitignore`.

## 0.7.170 — "Wingback" (2026-05-22)

### Added
- **SMACKBACK — File Integrity Monitoring**: Automated sentinel software that ships in every SnapSmack install. Generates cryptographic SHA-256 hashes of all monitored PHP, CSS, and JS files (core + skins) at install and update time. Re-verifies on admin login, cron, and optionally on public page loads (mtime-only stat check — no file reads unless mtime changes). On confirmed tamper: email alert fires immediately, admin interface switches to a high-contrast BREACH skin (all CSS hardcoded inline — cannot be neutralised by file tampering). BREACH mode prevents admin navigation until tampered files are resolved. One-click restore: downloads current release from update server, verifies SHA-256 before writing to disk.
  - **`core/smackback.php`** — all SMACKBACK functions: `smackback_init_manifest()`, `smackback_init_from_disk()`, `smackback_verify_all()`, `smackback_verify_quick()`, `smackback_verify_file()`, `smackback_handle_breach()`, `smackback_restore_file()`, `smackback_restore_all_breached()`, `smackback_resolve_breach()`, `smackback_is_breach()`, `smackback_get_monitored_files()`, `smackback_should_monitor()`, `smackback_render_breach()`, `smackback_send_alert()`, `smackback_init_skin_manifest()`, `smackback_remove_skin_manifest()`, `smackback_get_eof_signature()`, `smackback_check_eof()`, `smackback_classify_mismatch()`
  - **`smack-smackback.php`** — admin page: status panel, breach detail with per-file restore buttons, manual verification, incident log (last 20), settings (enable/mode/email/pageload check), re-initialise baseline
  - **`migrations/migrate-smackback.sql`** — creates `snap_file_manifest` and `snap_smackback_log` tables; seeds SMACKBACK settings in `snap_settings`
  - **`database/schema/snapsmack_canonical.sql`** — `snap_file_manifest` and `snap_smackback_log` tables added
  - **EOF Sentinel**: Integrated into every SMACKBACK verification pass. At baseline time, the last non-empty line of each monitored file is recorded as `eof_signature` in `snap_file_manifest` (and in the build-time `smackback-manifest.json`). On hash mismatch, the EOF signal matrix distinguishes three causes: **Tampered** (hash changed, EOF intact — active modification), **Truncated** (last line differs — write failure or partial transfer), **Corrupted** (null bytes in last 64 bytes — filesystem fault or failed atomic write). Each cause is stored as a distinct `last_status` value, shown with distinct colours in the breach UI, and reported separately in alert emails. `snap_file_manifest.last_status` ENUM extended to include `truncated` and `corrupted`.
  - **Build pipeline**: `tools/_build/build-release.php`, `build-skin-package.php`, `package-skin.php` now generate per-file SHA-256 hashes and EOF signatures, embedding both in `smackback-manifest.json` in every release and skin ZIP. Manifest is covered by the package-level Ed25519 signature.
  - **Response modes**: Alert (banner only), Lockout (full admin redirect, default), Paranoid (Lockout + Phase 2 hub reporting stub)
  - **Integration hooks**: admin login verify (smack-admin.php), cron verify (cron-version-check.php), post-update manifest refresh (smack-update.php both paths), skin install/remove (core/skin-registry.php), fresh install baseline (install.php), breach gate on all admin pages (core/auth-smack.php), public pageload stat check (all 8 public pages)
  - **Phase 2 stub**: Hub/spoke breach correlation hook is wired in paranoid mode (no-op in this build); implementation deferred to next cycle
  - **smack-help.php** — SMACKBACK topic updated with full operational detail including EOF Sentinel signal matrix (TAMPERED / TRUNCATED / CORRUPTED explanations for admins)
  - **smack-settings.php** — SMACKBACK status widget + link added to the Security section
- secaudits/2026-05-22-017-smackback-file-integrity-monitoring.pdf — security review of the SMACKBACK implementation: vaporware-to-implementation gap (Finding 1), BREACH page design rationale and re-initialise risk (Finding 2), Phase 2 cross-spoke correlation deferral (Finding 3)

---

## 0.7.169 — "Chesterfield" (2026-05-22)

### Fixed
- **smack-maintenance.php — PHP parse error 500**: Missing `<?php endif; ?>` after the `else:` branch of the REBUILD THUMBNAILS batch-continue block. The unclosed `if:` caused PHP to report "unexpected end of file on line 902" and return a 500 on every visit to the Maintenance admin page. Detected immediately after the 0.7.168 deploy to foundtextures.ca.
- **maintenance-gate.php — unnecessary anonymous session creation**: When maintenance mode was active, the gate called `session_start()` unconditionally, creating a session file and sending a `PHPSESSID` cookie to visitors about to see the holding page. Fixed to check `$_COOKIE[session_name()]` first — session infrastructure is never created for maintenance-mode visitors.
- **smack-multisite-stats.php — undefined array key "days"**: Period selector ternary true branch read `$_GET['days']` without the `?? 30` fallback used in the validation check, producing a PHP warning when `days` was absent from the URL.
- **smack-update.php / core/updater.php — "COULD NOT REACH UPDATE SERVER" on flaky connections**: Three-part fix for servers with intermittent outbound HTTPS (e.g. self-hosted installs behind NAT): (1) `_updater_http_get()` now uses `CURLOPT_CONNECTTIMEOUT: 8` and retries once after a 2-second gap before giving up, instead of a single 30-second blocking attempt; (2) auto-check on page load now uses the cached result if it is less than 6 hours old and non-error, avoiding a live network hit on every visit; (3) when a live check does fail, the last known-good cache is preserved and displayed — the error is not written back to cache, so a transient failure no longer overwrites valid state.

---

## 0.7.168 — "Chesterfield" (2026-05-22)

### Added
- **Maintenance mode**: Admins can now put any site into maintenance mode from **Settings → Maintenance Mode**. Unauthenticated visitors see a self-contained holding page (503 + `Retry-After: 30`, `noindex,nofollow`) with a slow-rocking wrench icon, the site name, and a configurable title and message. Logged-in users see the normal site — no gate, no interruption. Implementation:
  - `core/maintenance-gate.php` — new standalone gate file; checks `maintenance_mode` in `snap_settings` and `$_SESSION['user_login']`; renders the holding page and `exit()`s when active
  - `smack-settings.php` — new MAINTENANCE MODE section (toggle, title field, message textarea)
  - Gate included in all eight public entry points: `index.php`, `archive.php`, `page.php`, `gallery-wall.php`, `blogroll.php`, `collection.php`, `collections.php`, `albums.php`
- **smack-help.php** — Maintenance Mode topic added to the Settings section

---

## 0.7.167 — "Chesterfield" (2026-05-22)

### Fixed
- **Rational Geo v1.8 — archive grid overflow**: `#justified-grid` and `.rg-archive-grid` were not being constrained by the `main_canvas_width` setting because they lacked `width: 100%` — flexbox `align-self: stretch` was setting the width outside the CSS cascade, leaving `max-width` with nothing to override. Fixed by: (1) adding `--rg-canvas-width` CSS variable to `:root` (default `1400px`), (2) switching header-inside, justified-grid, and archive-grid to all use `var(--rg-canvas-width)`, (3) changing the manifest `main_canvas_width` option to set `--rg-canvas-width` on `:root` instead of applying `max-width` directly to a fragile selector list. All three elements are now guaranteed to use the same width, and the admin setting controls all of them through one variable.

---

## 0.7.166 — "Chesterfield" (2026-05-22)

### Changed
- **Chaplin v2.4 — full rebuild as RG derivative**: Complete ground-up rewrite using Rational Geo as the base structure. All Galleria remnants removed.
  - **B&W only**: `filter: grayscale(1) contrast(1.05) brightness(0.95)` on `.chap-photo`. Sepia and tone toggle removed.
  - **Border system**: CSS `outline` + `box-shadow` rings stacked directly on the `<img>`. Single, double, and triple rules with per-line thickness and gap controls. Line colour fixed at `#ece6d4`.
  - **Ornament placement**: Independent toggles for corners, mid-top/bottom, and mid-left/right. Ornament style selector (None / A–D).
  - **Intertitle overlay**: Full-black-fade modal (`rgba(0,0,0,0.96)`) with INFO and SIGNALS tabs, replaces RG slide-up drawers.
  - **Filmstrip**: Horizontal scroll strip below the infobox, 60 most recent images, square thumbs.
  - **Title position**: Below photo (default), info tray only, or hidden.
  - **Manifest cleanup**: Removed GALLERY WALL, PICTURE FRAMES, FILM TONE sepia controls. Added BORDER, ORNAMENTS, TITLE sections. Preset slot retained.

---

## 0.7.165 — "Chesterfield" (2026-05-22)

### Fixed
- **Chaplin v2.2**: Restored Galleria 5-div frame structure (`frame-mount > frame-border > frame-mat > frame-bevel > frame-image`) — a previous session had incorrectly replaced it with an RG CSS-native single-div approach.
- **Chaplin**: Removed vignette entirely. No visual effects are applied to photos; that is the photographer's prerogative. Vignette option removed from manifest and CSS block removed from skin-header.php.
- **Rational Geo v1.7**: Restored `#justified-grid { max-width: 95% }` — had been changed to `max-width: 1600px`, breaking masonry layout when viewport width was less than 1600px.
- **Logout redirect**: `core/auth-smack.php` now reads `login_slug` from the database instead of hardcoding `snap-in.php`, fixing 403 errors on sites using a custom login URL.

---

## 0.7.164 — "Chesterfield" (2026-05-21)

### Fixed
- **Changelog cleanup**: Collapsed duplicate 0.7.163 headers; added missing entries for SC Skin Packager selection controls and Pimpmobile persistence fix; corrected codename from "Chaplin" (skin name) to "Chesterfield" (sitting codename) across 0.7.161–163.
- **SNAPSMACK_VERSION** synced to match `SNAPSMACK_VERSION_SHORT` (was stuck at Alpha 0.7.154).

---

## 0.7.163 — "Chesterfield" (2026-05-21)

### Added
- **SC Skin Packager — selection controls**: Phase 2 skin selection now has Select All, Select Updated, and Deselect All buttons above the skin table. Select Updated uses a `data-updated` attribute set at render time — no server round-trip.

### Fixed
- **Chaplin v2.1**: Corrected page structure — `landing.php` and `layout.php` were written as standalone pages instead of include fragments, causing doubled `<html>`, `<body>`, and `footer-scripts` on every load. Both files are now proper fragments matching the Rational Geo architecture pattern.
- **Chaplin**: Removed BlackCasper from font picker and as title font default. Cinzel is now the default site title font.
- **Chaplin style.css**: Replaced dead `body.chap-landing-body` selector with `body:has(.chap-landing)`.
- **Chaplin help.php**: Rewrote to reflect current feature set — removed stale references to four film stocks, square crop, and grain overlay.
- **Pimpmobile setting not persisting across sessions**: `auth-smack.php` was loading `preferred_skin` and `user_id` from the DB on every authenticated request but omitting `ui_mode`. Pimpmobile reverted to bigwheel on every page load. Added `ui_mode` to the SELECT and session population.
- **foundtextures.ca logout 403**: Logout was redirecting to `snap-in.php` (removed page) instead of `smack-admin.php`. Already fixed in working copy; committed here.
- **False "COULD NOT REACH UPDATE SERVER" after update**: Immediately after a successful update the page fired a live version check before PHP/opcache had settled, reliably producing a bogus error. Now skips the live check on the first load post-update and writes `up_to_date` to cache directly — the update pipeline already confirmed the version. RETRY CHECK still hits the server normally.

---

## 0.7.162 — "Chesterfield" (2026-05-21)

### Fixed
- **smack-update.php missing admin-footer**: Sidebar accordion JS (`ss-engine-sidebar.js`) was never loading on the System Updates page — nav section toggles were dead. Added missing `include 'core/admin-footer.php'`.

---

## 0.7.161 — "Chesterfield" (2026-05-21)

### Added
- **Chaplin skin v1.3 rebuilt from scratch**: Single centered framed image layout (no slider). Art Deco SVG ornament system — 4 styles (Stepped Sunburst, Minimal Chevrons, Heavy Deco Fan, Geometric Modernist) with corners-only / corners+mid / full-border modes and 1–3 configurable parallel rules. Film effects engine: canvas scratches behind image, CSS flicker and gate-slip on the frame — no overlay on the photo. Tone: sepia or B&W. New files: `frame-deco.php`, `assets/js/ss-engine-chaplin-film.js`. All 42 manifest settings are now wired.
- **Rational Geo masonry container fix**: `#justified-grid` was `max-width: 95%` — no pixel cap, escaped the 1600px layout on wide monitors. Fixed to match `.rg-archive-grid` at 1600px. Skin bumped to v1.6.

### Fixed
- **Black Pearl admin theme description text**: `.item-text .dim` was `#444444` on `#000000` background — invisible. Raised to `#AAAAAA`. `.item-meta` (album/collection descriptions) had no colour override; added at `#999999`. Body background explicitly set to `#000000`.
- **api-auth.php truncated EOF**: Last line was `// ===== SNAPSMAC` — repaired.
- **core/auth-smack.php**: Session lifetime corrected; stale logout redirect fixed.
- **Migration PHP stubs removed from git**: `migrations/027–066_*.php` were tracked but not on disk (replaced by SQL equivalents). Removed from git index.
- **check-eof.py hangs on Windows**: `dd iflag=direct` is unsupported on Windows and stalled the scanner. Now skips `dd` on Windows (`os.name == 'nt'`), adds a 5s timeout on Linux.

---

## 0.7.160 — "Booty Call" (2026-05-20)

### Fixed
- **snap_collections column mismatch**: Fresh installs from canonical schema created `snap_collections.title`, but `archive.php`, `smack-edit.php`, and `smack-post-solo.php` queried `ORDER BY name`, causing a fatal PDO error on those pages. All three updated to use `title`. Migration `migrate-collections-name-to-title.sql` added to rename the column on existing installs.
- **smack-admin.php truncated**: File was cut off mid-line at the RSS job remove button, causing a PHP parse error and 500 on the admin dashboard. Restored from reference copy.
- **updater.php idempotent errno**: Added MySQL 1054 (ER_BAD_FIELD_ERROR) to the swallowed error list so CHANGE COLUMN migrations are safe to run on installs where the rename is already applied.

---

## 0.7.159 — "Booty Call" (2026-05-19)

### Security
- **smack-2fa-verify.php: session cookie secure flag corrected**: The TOTP interstitial page was only checking `$_SERVER['HTTPS']` to set the cookie secure flag. On reverse-proxy installs (Nginx, Cloudflare) PHP runs on plain HTTP internally, so the flag was never set. Now matches snap-in.php's logic and also checks `HTTP_X_FORWARDED_PROTO`.
- **smack-2fa-verify.php: recovery code now forces password change**: Using a recovery code at the TOTP stage now triggers a forced password change on the next page, consistent with the recovery code path in snap-in.php. Burning a recovery code at any stage indicates normal auth could not complete and should prompt admin awareness.

### Changed
- Security audit documents 012–014 converted from .md to PDF. All new secaudit findings will be PDF going forward.
- secaudit 015 added for the two smack-2fa-verify.php findings above.

---

## 0.7.158 — "Booty Call" (2026-05-19)

### Added
- **Forum board management**: Admins with a moderator key can now create new boards directly from the forum admin panel. Enter your mod key once in the new Forum Admin section and a `+ NEW BOARD` button appears. Board name, slug (auto-generated if blank), description, and sort order are all configurable.
- **Forum API `POST /categories`**: New endpoint on the forum server. Moderator key required. Auto-generates slug from name, returns 409 on duplicate slug.

### Changed
- Forum category accent colour rotation updated — first board now gets grey (`#888888`) instead of red, matching the General Chat board.

---

## 0.7.157 — "Booty Call" (2026-05-19)

### Security
- **Installer blocked from creating second admin account**: If `install.php` is present on a site that already has an admin user, step 4 now hard-blocks with an error. Previously an attacker with access to a lingering `install.php` could create a new admin account with a different username, bypassing 2FA entirely. Now it checks for any existing admin in `snap_users` before proceeding.

### Fixed
- **Installer CSRF removed**: The installer CSRF token check consistently failed on servers where PHP sessions don't persist between GET and POST. There is no authenticated user to protect during a fresh install — it was security theatre. Removed entirely.

## 0.7.156 — "Booty Call" (2026-05-19)

### Fixed
- **Installer CSRF removed**: The installer had a CSRF token check that consistently failed on servers where PHP sessions don't persist between GET and POST (strict SameSite, PHP-FPM misconfiguration, etc.). There is no authenticated user session to protect during a fresh install — the CSRF was security theatre. Removed entirely.

## 0.7.155 — "Booty Call" (2026-05-19)

### Security
- **`login.php` deleted**: Orphaned duplicate login page sitting at a predictable URL. `snap-in.php` via the configurable login slug has been the real login since the slug model was introduced. All references (recovery emails, password reset back-links, SSO fallback, post-install link) updated to point to `snap-in`. Existing installs have `login.php` auto-removed on update via `UPDATER_DEPRECATED_FILES`.
- **`core/auth.php` renamed to `core/auth-smack.php`**: `auth.php` is a filename cPanel generates on some hosts. Having SnapSmack's auth guard share that name caused a gitignore collision and is a support nightmare waiting to happen. All 60+ requires updated. Old name added to `UPDATER_DEPRECATED_FILES`.
- **Installer `.htaccess` template missing login protections**: Generated `.htaccess` on fresh installs was missing the `snap-in` rewrite rule (so `/snap-in` 404'd) and the `login.php` block from `FilesMatch`. Both added to the installer template.

### Fixed
- **`core/auth.php` accidentally deleted in 0.7.105**: The admin authentication guard — required by every admin page — was removed in a large commit and never restored. All admin pages were 500ing on fresh installs. Restored (as `core/auth-smack.php`).
- **Gitignore `auth.php` rule was unanchored**: The rule intended to ignore the root-level cPanel `auth.php` was matching `core/auth.php` as well, silently preventing it from being tracked. Rule anchored to `/auth.php`.

## 0.7.154 — "Booty Call" (2026-05-18)

### Fixed
- **Fresh installs failed Ed25519 signature verification**: `setup.php` hardcoded its own copy of `SETUP_RELEASE_PUBKEY` which went stale whenever the signing key changed. The release packager (`sc-release.php`) now injects the current derived pubkey into `setup.php` inside the zip at build time (Step 2b), before SHA-256 and signing. The key in the shipped `setup.php` will always match the key that signed the package.

## 0.7.153 — "Booty Call" (2026-05-18)

### Added
- **Chaplin skin — stable release**: Chaplin skin marked stable. Silent-film-era Google Fonts added to `@import` (Cinzel, Cormorant Garamond, Special Elite, IM Fell DW Pica, Antic Didone, Poiret One, Josefin Slab, Playfair Display). All fonts verified OFL.
- **Film damage overlay engine** (`assets/js/ss-engine-film-damage.js`): Canvas-based animation engine for Chaplin. Renders randomised scratches, dust spots, hair, and gate weave as a full-viewport overlay. 24fps throttled rAF loop, mix-blend-mode screen. Configurable intensity (1–10) and element types. Registered in manifest inventory. Skin-header.php loads it conditionally via admin settings.
- **Smack Beacon spec** (`_spec/smack-beacon-spec.md`): Spec for a lightweight API-key-protected stats endpoint (`smack-beacon.php`). Exposes aggregate install metrics (post/photo/member counts, visitor stats, active skin) for snapsmack.ca skin gallery social proof. Implementation deferred pending snapsmack.ca DB schema confirmation.

### Fixed
- **Installer step 5 missing POST guard**: Step 5 (the write/self-delete step) had no `$_SERVER['REQUEST_METHOD'] === 'POST'` check, unlike steps 2–4. An attacker with a valid CSRF token could POST directly to step 5, writing `core/db.php` with empty credentials and self-deleting the installer. Added the missing guard.
- **Installer deploy message referenced GitHub**: "CODEBASE NOT FOUND" screen told users to deploy from GitHub. Deploy model changed to signed packages fetched by `setup.php` from snapsmack.ca. Message updated.
- **`setup.php` pubkey out of sync**: `SETUP_RELEASE_PUBKEY` in `setup.php` did not match `core/release-pubkey.php`. Synced manually; build-time injection added in 0.7.154 to prevent recurrence.
- **Stale installer comment**: Comment in `install.php` referenced "50 Shades and Rational Geo" as default skins. Corrected to "New Horizon (the default) and 50 Shades of Noah Grey".

## 0.7.152 — "La-Z-Boy" (2026-05-18)

### Fixed
- **Schema sync did nothing for ui_mode column**: updater_canonical_diff() falls back to the on-disk snapsmack_canonical.sql, but ui_mode was never added to it. Schema sync can't add a column it doesn't know about. Added ui_mode to both snap_users definitions in the canonical schema. RUN SCHEMA SYNC will now add the column on any site missing it.

## 0.7.151 — "La-Z-Boy" (2026-05-17)

### Fixed
- **Update stage buttons still misaligned**: vertical-align:top didn't fully fix the issue because the two forms had different margin-top values (16px vs 10px), creating a 6px offset. Replaced inline-block + vertical-align approach with a flex row wrapper (.stage-actions) using align-items:flex-start. Both forms now pin to the top of the row regardless of content height.

## 0.7.150 — "La-Z-Boy" (2026-05-17)

### Fixed
- **Thumb style setting (Cropped / Square) had no effect**: The 0.7.79 layout refactor collapsed archive layouts from 4-way (square/cropped/croppedwithcalendar/masonry) to 2-way (thumbs/masonry), with thumb style as a separate admin setting stored in $grid_layout. The render branch in archive.php was never updated — it still checked `$archive_layout === 'cropped'`, which is permanently false in the new model. Changed to `$grid_layout === 'cropped'` so the cropped render path is reachable again.

## 0.7.149 — "La-Z-Boy" (2026-05-17)

### Fixed
- **Pimpmobile toggle still 500 after 0.7.148**: The SQL migration added the ui_mode column for sites applying the update, but sites already on 0.7.147 had no way to get the column without first triggering the 500. smack-admin.php now runs a defensive ALTER TABLE before the first ui_mode write, self-healing on any install where the column is missing. Idempotent — swallows errno 1060 when the column already exists.

## 0.7.148 — "La-Z-Boy" (2026-05-17)

### Fixed
- **Pimpmobile toggle causes 500 on all sites**: Per-user ui_mode was implemented using a PHP migration file (066_per_user_ui_mode.php) but the migration runner only processes migrate*.sql files. The ALTER TABLE adding the ui_mode column to snap_users never ran, so UPDATE snap_users SET ui_mode crashed with "Unknown column". Replaced with a proper SQL migration (migrate-users-ui-mode.sql) added to UPDATER_KNOWN_MIGRATIONS.
- **Update stage buttons misaligned during auto-advance**: AUTO-CONTINUING... text was appended inside the stage-next-btn form, expanding its height and pushing the CANCEL UPDATE button down. Added vertical-align:top to both inline-block forms so they stay top-aligned regardless of content height.
- **Stale update server offers downgrade**: updater_check_status() checksum fallback path fired even when the remote version was older than installed, causing sites to show a phantom "update available" after the update server lagged behind. Checksum comparison now only applies when versions are exactly equal.

## 0.7.147 — "La-Z-Boy" (2026-05-17)

### Changed
- **Big Wheel / Pimpmobile toggle is now per-user**: Previously stored in snap_settings (site-wide), so switching modes affected every admin. Now stored in snap_users.ui_mode and loaded into the session at login. Each admin has their own preference.

### Added
- **Installer security advice page**: Step 0 added before the environment check. Covers unique passwords, Bitwarden as a free password manager, passphrases (links to xkcd 936), and TOTP 2FA setup after install.

## 0.7.146 — "La-Z-Boy" (2026-05-17)

### Fixed
- **smack-update.php truncated in 0.7.144**: Edit tool clobbered the file at line 1997, removing the auto-advance JS pipeline, admin footer include, and EOF marker. Restored from 0.7.143 with the PHP parse fix correctly re-applied.
- **Skin install fails cross-device**: skin-registry.php used rename() to move skin staging dir from /tmp to web root. Fails with a warning on servers where /tmp and the web root are on different filesystems. Suppressed warning and fallback copy already handled it correctly.
- **Auto-advance pipeline stalls after download**: form.submit() does not include the button name/value, so PHP received no action and the pipeline looped on the downloaded stage. Fixed by using nextBtn.click() instead.
- **Auto-advance pipeline stalls after download**: form.submit() does not include the submit button's name/value, so PHP received no action and the pipeline looped on the downloaded stage. Fixed by using nextBtn.click() instead.
- **Impact Printer T/M/C buttons still broken after 1.9 update**: manifest.php was bumped to 1.9 in 0.7.143 before style.css fix landed, so sites that updated to 0.7.143 already had "v1.9" with the broken CSS. Bumped to v2.0 so the skin updater offers the corrected version.

## 0.7.144 — "La-Z-Boy" (2026-05-17)

### Fixed
- **smack-update.php 500 on all installs**: PHP parse error on line 1722 — `$skin[\'name\']` and `$skin[\'to\']` used backslash-escaped single quotes in PHP code context (not inside a string), which is invalid in PHP 8. Replaced with a `$skin_confirm` variable built in a clean PHP block before the button.

## 0.7.143 — "La-Z-Boy" (2026-05-16)

### Fixed
- **Update page: apply was not actually 1-click**: Clicking "APPLY UPDATE →" kicked off a staged pipeline but required manually clicking through 5 more steps (Verify → Backup → Patch DB → Extract → Migrate). JS auto-advance now fires on each stage page: if no error is present, the next stage submits automatically 800ms after the page loads. The only manual action is the initial Apply button. On error, auto-advance stops and the manual buttons remain active. The extract stage uses existing meta-refresh chunking which is unaffected.

## 0.7.142 — "La-Z-Boy" (2026-05-16)

### Fixed
- **Impact Printer T/M/C buttons misaligned and oversized**: `right:0 !important` in the skin CSS was overriding `alignDockedControls()` JS calculation, pinning buttons to the scroll-stage edge instead of the masonry grid's right edge. Removed the override so JS-calculated alignment tracks the grid correctly. Reduced button `font-size` from 0.78em to 0.68em and `padding` from `6px 14px` to `4px 9px` to better fit the infobox strip. Impact Printer skin bumped to 1.9.

## 0.7.141 — "La-Z-Boy" (2026-05-16)

### Fixed
- **Masonry image size setting never wired up**: `masonry_use_thumbs` existed in Archive Appearance and was saved to the DB but archive.php always loaded full-size originals regardless. Now reads the setting and uses `img_thumb_aspect` (medium ~600px aspect-ratio thumbnail) when enabled, falling back to `img_file` if no thumbnail exists. Admin control changed from a generic checkbox to an explicit select: "Medium (~600px thumbnails) — Recommended" vs "Full size (original files)".

## 0.7.140 — "La-Z-Boy" (2026-05-16)

### Fixed
- **Impact Printer masonry grid still left-aligned**: `page-archive.css` sets `width:100%` on `#justified-grid`. In a flex column container with `align-items:stretch`, centering a 100%-wide item does nothing — the previous `align-self:center` fix was ineffective for this reason. Replaced with `flex:0 0 auto` (opts out of stretch sizing) + `max-width:95%` + `margin:auto`, matching the pattern already used by the square/cropped grid and 50 Shades. Impact Printer skin bumped to 1.8.

## 0.7.139 — "La-Z-Boy" (2026-05-16)

### Changed
- **Update page: styled file picker**: The manual upload "Choose File" button was a plain browser widget. Replaced with a styled button that matches the SnapSmack UI and shows the selected filename below it. Turns green when a file is chosen.
- **Update page: Advanced Options toggle cleaner**: Dashed border and arrow marker make it more obviously a collapsed section rather than an inert heading.

## 0.7.138 — "La-Z-Boy" (2026-05-16)

### Changed
- **Masonry added to all eligible skins**: 52 Card Pickup, A Grey Reckoning, Chaplin, Hip to be Square, and Impact Printer were all missing `masonry` from their `archive_layouts` feature declaration. All five updated — masonry layout option now appears in Archive Appearance for each. Skin versions bumped: 52 Card Pickup 1.2→1.3, A Grey Reckoning 1.2→1.3, Chaplin 1.2→1.3, Hip to be Square 1.2→1.3, Impact Printer 1.6→1.7.

## 0.7.137 — "La-Z-Boy" (2026-05-16)

### Changed
- **Update page redesigned**: Page now auto-checks on every load — no manual Check button. Simple status card shows up-to-date or update-available at a glance with inline Apply button. Skin updates surface as first-class cards with one-click UPDATE buttons (no more bouncing to the gallery). Schema recovery appears at the top only when migrations are overdue; everything else (current install details, reapply, manual upload, cron, canonical diff) lives under a collapsed Advanced Options section.

## 0.7.136 — "La-Z-Boy" (2026-05-16)

### Fixed
- **Impact Printer grid left-aligned after save**: `#scroll-stage` is a column flex container with `align-items: stretch`, which pins flex children to the left even with `max-width` + `margin: auto`. Added `align-self: center` to `#justified-grid` in style.css to pull it out of the stretch behaviour. Impact Printer skin bumped to 1.6.

## 0.7.135 — "La-Z-Boy" (2026-05-15)

### Fixed
- **Calendar double-loading on 50 Shades / photowalk.ing**: `archive.php` used a relative path to load the skin manifest (`'skins/...'`). If `file_exists` resolved against a different CWD on the server, the manifest silently failed to load, `$skin_has_calendar` stayed `false`, and archive.php loaded `ss-engine-calendar.js` a second time after skin-footer.php already loaded it via `require_scripts`. Changed to absolute path (`__DIR__ . '/skins/...'`) consistent with `core/meta.php`.
- **Smack Central session timeout at 20 minutes**: `ini_set('session.gc_maxlifetime')` is unreliable when server PHP GC runs at the default 1200s. Added a rolling `sc_expires_at` timestamp stored in the session itself — expiry now works independently of server GC config. Session stays alive for 8 hours of activity.
- **Smack Central main content right gutter too wide**: `.sc-main` padding was `32px 48px`; changed horizontal padding to `20px` to match the sidebar nav gutter.

## 0.7.134 — "La-Z-Boy" (2026-05-15)

### Fixed
- **Impact-printer masonry grid left-aligned**: `max-width: 100%` in skin CSS was loading after the manifest-compiled blob and overriding `max-width: 1280px`, leaving no space for `margin: auto` to centre. Removed `max-width` from skin CSS entirely — manifest value now applies and `margin: auto` centres the grid.

## 0.7.133 — "La-Z-Boy" (2026-05-15)

### Fixed
- **Skin registry URL DB override**: Stopped reading `skin_registry_url` from `snap_settings` entirely in `smack-skin.php` and `core/updater.php`. Both now use `SKIN_REGISTRY_DEFAULT_URL` directly. DB value cannot override it.

## 0.7.132 — "La-Z-Boy" (2026-05-15)

### Fixed
- **Skin registry URL still wrong after migration 064**: Migration 064 used a conditional `WHERE setting_val = '...'` that silently did nothing if the stored value differed in any way, then marked itself applied — fix never landed. Migration 065 uses `LIKE '%snapsmack.ca%'` to catch any variation of the wrong URL and force-writes the correct value.

## 0.7.131 — "La-Z-Boy" (2026-05-15)

### Fixed
- **Skin registry URL baked into install.php**: `install.php` was writing the wrong URL (`/skins/registry.json`) into `snap_settings` on every fresh install. Corrected to `/releases/skins/registry.json`.

## 0.7.130 — "La-Z-Boy" (2026-05-15)

### Fixed
- **Multisite hub version missing "Alpha" prefix**: Hub self-row was rendering `SNAPSMACK_VERSION_SHORT` (numeric only); changed to `SNAPSMACK_VERSION` so it matches what spokes report.
- **`core/constants.php` version stuck at 0.7.125**: `SNAPSMACK_VERSION` and `SNAPSMACK_VERSION_SHORT` were not being updated with each release. Bumped to current. These must be kept in sync with `sc-version.php` on every version bump.

## 0.7.129 — "La-Z-Boy" (2026-05-14)

### Fixed
- **Skin gallery registry URL wrong**: `SKIN_REGISTRY_DEFAULT_URL` pointed to `https://snapsmack.ca/skins/registry.json` but the Skin Packager writes to `releases/skins/`. Corrected to `https://snapsmack.ca/releases/skins/registry.json`.
- **Chaplin skin marked stable**: Set to `dev` — skin is not release-ready.

## 0.7.128 — "La-Z-Boy" (2026-05-14)

### Fixed
- **Impact-printer masonry grid left-aligned**: Added `margin-left: auto; margin-right: auto` to `#justified-grid` so it centres within the content column. Works at full width now; once the manifest-compiled CSS adds `max-width: 1280px` (after skin re-save), the centred 1280px column will match all other page elements.
- **Impact-printer T/M/C controls drifting left**: `alignDockedControls()` was positioning the controls relative to the grid's right edge, which is now shorter than the infobox after the overflow fix. Added `right: 0 !important` override in skin CSS to pin controls to the infobox right edge permanently.
- **Impact-printer manifest missing `#justified-grid`**: Committed `skins/impact-printer/manifest.php` — the `main_canvas_width` selector already included `#justified-grid` locally but was never in git. Re-saving skin settings will now regenerate the CSS blob with `max-width: 1280px` on the masonry grid.

## 0.7.127 — "La-Z-Boy" (2026-05-14)

### Fixed
- **Archive layout toggle controls hidden / misaligned**: `alignDockedControls()` in `ss-engine-archive-toggle.js` used to select `#justified-grid` directly — in thumbs mode that element is `display:none` (zero-width BoundingClientRect), causing the right-offset calculation to push the T/M/C buttons off-screen. Now loops through `['.fsog-archive-grid', '#browse-grid', '#justified-grid']` and skips any element with zero visible width, so controls stay aligned in both thumbs and masonry mode across all skins.
- **Archive layout toggle missing on some installs**: Migration 056 incorrectly set `archive_show_layout_toggle = '0'` for any site whose old `archive_layouts_available` contained only one layout family. New migration 063 corrects this to `'1'`.
- **Impact-printer masonry grid overflow**: `#justified-grid` had no `max-width` constraint in `style.css`, so the masonry engine could expand it past the skin's sprocket-hole margins. Added `max-width: 100%; box-sizing: border-box` to the existing `#justified-grid` rule — grid now stays within the paper container at all canvas widths.

## 0.7.126 — "La-Z-Boy" (2026-05-14)

### Added
- **Masonry image source toggle**: New *Archive Appearance* setting — masonry uses pre-generated aspect thumbnails (max 600px) instead of full-size images; faster load, lower bandwidth; defaults to ON (`masonry_use_thumbs`, migration 062)
- **Regenerate All Thumbnails**: Maintenance action force-regenerates square + aspect thumbnails for every image via `core/thumb-generator.php`; overwrites existing thumbs so quality/size changes take effect immediately
- **Skin masonry lock flag**: Skins can set `features['masonry_supported'] = false` in manifest to permanently disable masonry toggle — Photogram and The Grid locked

### Changed
- **`justified_row_height` is now global**: Owned by Archive Appearance only; removed from per-skin manifest option blocks; skin-scoped copies ignored via `$global_only` in `core/skin-settings.php`
- **Layout dispatch restructure**: `archive.php` handles masonry in core; skin `archive-layout.php` invoked only for thumbs path
- **Photogram / The Grid**: `masonry_supported = false` locked in manifests

### Fixed
- **Manifest error isolation**: All skin manifest `include`s wrapped in `try/catch \Throwable` — a broken non-active skin can no longer crash the admin or archive page
- **`tools/check-eof.py`**: Uses `dd iflag=direct` to bypass CIFS page cache — now correctly detects truncated files instead of reading stale cached content
- **`core/thumb-generator.php`**: Extracted shared thumbnail generation logic from `photo-editor-save.php`

## 0.7.125 — "La-Z-Boy" (2026-05-14)

### Added
- **Masonry image source toggle**: New *Archive Appearance* setting — masonry can now use pre-generated aspect thumbnails (max 600px) instead of full-size images; faster load, lower bandwidth; defaults to ON for new installs (`masonry_use_thumbs`, migration 062)
- **Regenerate All Thumbnails**: New maintenance action force-regenerates square + aspect thumbnails for every image via `core/thumb-generator.php`; overwrites existing thumbs so quality/size changes take effect immediately
- **Skin masonry lock flag**: Skins can set `features['masonry_supported'] = false` in their manifest to permanently disable the masonry toggle and force the thumbs layout — used by Photogram and The Grid

### Changed
- **`justified_row_height` is now a global setting**: Owned by Archive Appearance only; removed from per-skin manifest option blocks (new-horizon, true-grit) — skin-scoped copies are ignored via `$global_only` in `core/skin-settings.php`
- **Layout dispatch restructure**: `archive.php` now handles masonry rendering in core; skin `archive-layout.php` is invoked only for the thumbs path — skins without `archive-layout.php` fall back to the built-in cropped grid
- **Photogram**: `masonry_supported = false`, `supports_wall = false` locked in manifest
- **The Grid**: `masonry_supported = false` locked in manifest

### Fixed
- **`core/thumb-generator.php`**: Extracted shared thumbnail generation logic from `photo-editor-save.php` so maintenance and future tooling can call it without duplication
- **`tools/check-eof.py`**: Falls back to `os.walk()` filesystem scan when `git ls-files` is unavailable (CIFS-mounted repos with corrupt index)

## 0.7.124 — "La-Z-Boy" (2026-05-14)

### Fixed
- **page-archive.css / impact-printer**: Justified grid last row no longer overshoots the skin's paper container — last-row height now uses `var(--justified-row-height)` directly (already set inline by PHP) instead of a `100vw`-based width calculation; impact-printer style.css cleaned up (dropped broken `--justified-content-width` calc that referenced undefined vars)

## 0.7.123 — "La-Z-Boy" (2026-05-14)

### Fixed
- **archive.php**: Masonry toggle now works on skins without `archive-layout.php` (impact-printer and any other core-fallback skin) — grid containers now carry `archive-grid` / `archive-masonry` CSS classes so the JS fetch-and-replace can locate and swap them
- **archive.php**: Calendar JS double-load on rational-geo (and 50-shades-of-noah-grey) — skins that declare `smack-calendar` in `require_scripts` already load it via `skin-footer.php`; `archive.php` no longer loads it a second time; added companion CSS load for non-manifest skins that have calendar enabled via settings

## 0.7.122 — "La-Z-Boy" (2026-05-13)

### Fixed
- **F1 help menu missing keyboard shortcuts on all single-image pages** — the
  detection condition checked for `.image-stage` and `.single-image-page` CSS
  classes that don't exist in any skin. Now checks for `#snap-nav-data`
  (the element `index.php` always emits on post pages) and `window.SNAP_DATA`
  as the primary signals. Affects all skins — arrow keys, spacebar, and other
  per-page shortcuts now correctly appear in the F1 overlay.
- **F1 help: `[ 1 ]` / `[ 2 ]` info/comment toggles hidden when not available** —
  these keys require `window.smackdown.toggleFooter` (only present in skins that
  wire it up). Gated on that check so they don't appear as phantom shortcuts in
  Impact Printer and other skins that don't use smackdown.

## 0.7.121 — "La-Z-Boy" (2026-05-13)

### Changed
- **Fleet Stats — top 30 most viewed** — increased from 12 to 30 images in
  the fleet-wide Most Viewed grid. API spoke query and hub query both bumped
  from LIMIT 10 to LIMIT 30.

## 0.7.120 — "La-Z-Boy" (2026-05-13)

### Fixed
- **alert-error text colour — correctly fixed in theme colour files** — removed
  the dark `color:` overrides from the 14 admin theme colour CSS files that were
  clobbering the geometry fallback (`#ff8080`) without setting their own
  background. Geometry file left unchanged. Themes that provide both background
  and text colour (Bumblebee, Pixelpast) are untouched. Reverts the misguided
  0.7.119 revert and removes the `!important` that shouldn't have been in geometry.

## 0.7.119 — "La-Z-Boy" (2026-05-13)

### Fixed
- **alert-error text colour — fixed properly in master CSS** — added `!important`
  to `.alert-error { color: #ff8080 }` in `admin-theme-geometry-master.css`.
  0.7.118 unnecessarily edited 14 individual theme colour files to achieve the
  same result; this single master-CSS change supersedes those edits. Theme files
  reverted. One file to FTP, not fifteen.

## 0.7.118 — "La-Z-Boy" (2026-05-13)

### Fixed
- **multisite/updates/trigger endpoint restored (again)** — the endpoint was lost
  when `core/multisite-api.php` was rebuilt for the 0.7.117 fleet stats work.
  UPDATE ALL BEHIND / push-update calls were returning HTTP 404 on all spokes.
  Endpoint re-spliced from the 0.7.115 source.
- **Admin theme alert-error text unreadable** — 14 admin colour themes were
  overriding `.alert-error` text colour with dark values (e.g. `#630D0D` on the
  master's `#3b0d0d` dark-red background). Removed the conflicting `color:`
  overrides from all themes that don't set their own background; master CSS
  `#ff8080` now applies universally for those themes. Affects all error banners
  site-wide, including the multisite connected-spokes update results panel.

## 0.7.117 — "La-Z-Boy" (2026-05-13)

### Added
- **Fleet Stats — browsers, OS, categories, search terms, peak hours, countries** —
  six new panels added to the fleet stats rollup. Each spoke now returns browser
  breakdown, OS breakdown, views by category, archive search terms, peak hours
  heatmap (7x24 grid), and countries via the enriched stats endpoint. Hub aggregates
  all panels fleet-wide alongside its own local data. Panels degrade gracefully if
  a spoke is offline or on an older version.

## 0.7.116 — "La-Z-Boy" (2026-05-13)

### Fixed
- **Fleet Stats — PHP parse error (unexpected end of file)** — `smack-multisite-stats.php`
  missing closing `endif` for the `if (empty($spokes)):` / `else:` block. Fatal
  parse error on every fleet stats page load.

## 0.7.115 — "La-Z-Boy" (2026-05-13)

### Fixed
- **Multisite update push — HTTP 404 on all spokes** — `multisite/updates/trigger`
  endpoint handler dropped from `core/multisite-api.php` between 0.7.103 and 0.7.105,
  never restored. Hub UPDATE / UPDATE ALL BEHIND returned "HTTP 404: UNKNOWN MULTISITE
  ENDPOINT" on every spoke. Endpoint restored from 0.7.103.

## 0.7.114 — "La-Z-Boy" (2026-05-13)

### Changed
- **dev merged to master** — all 0.7.109–0.7.113 fixes now on master so the Skin
  Packager (which pulls from master) sees current skin versions. Version bump required
  to force updater refresh across all installs.

## 0.7.113 — "La-Z-Boy" (2026-05-13)

### Fixed
- **Canonical schema — duplicate `snap_migrations` definition** — `snapsmack_canonical.sql`
  contained two `CREATE TABLE IF NOT EXISTS snap_migrations` blocks. The second (erroneous)
  definition added an `id AUTO_INCREMENT` column that no live install has and no migration
  creates. Schema sync was fataling with `SQLSTATE[42000]: there can be only one auto column`
  on every site. Duplicate removed; the original definition (`migration` as PRIMARY KEY)
  is the authoritative one.

## 0.7.112 — "La-Z-Boy" (2026-05-13)

### Fixed
- **Skin manifests — masonry controls still outside `options` array** — the 0.7.110 fix
  script moved the masonry blocks but left an early-closing `],` before them, putting them
  back outside the `options` array with an orphaned bracket after them. `galleria` caused
  `Cannot use empty array elements in arrays`; `show-n-tell` and `true-grit` had the same
  structural fault. All three now correctly place `masonry_border_width` and
  `masonry_border_color` inside `options`, with `admin_styling` following the single
  closing `],`. `50-shades-of-noah-grey` had duplicate masonry entries (no error, but
  redundant); de-duplicated.

## 0.7.111 — "La-Z-Boy" (2026-05-12)

### Fixed
- **`core/multisite-api.php` ban-sync parse error** — the `ban-sync` endpoint had a
  stray single-quote in a `preg_replace()` call, making the entire file a PHP parse
  error. Every API call (heartbeat, ping, stats, etc.) returned HTTP 500, taking all
  spokes OFFLINE and causing spoke sites to report “COULD NOT REACH HUB — HTTP 500”
  when verifying hub connectivity. The ban-sync block was also corrected: uninitialised
  `$bans_to_store`, undefined `$reason` variable, dead duplicate prepared statement, and
  wrong table name (`snap_bans` → `snap_ban_list`) all fixed.
- **Spoke hub-ping error shown as green success** — a failed manual “Verify Connection”
  (e.g. “Could not reach hub — HTTP 500”) was placed in `$msg` (displayed green) instead
  of `$err` (displayed red). All three failure branches in the `verify_hub` handler now
  use `$err`.

## 0.7.110 — "La-Z-Boy" (2026-05-12)

### Fixed
- **Skin manifests — masonry controls orphaned after `admin_styling`** — in four skins
  (`50-shades-of-noah-grey`, `galleria`, `show-n-tell`, `true-grit`) the masonry border
  controls (`masonry_border_width`, `masonry_border_color`) were placed after the
  `admin_styling` top-level key instead of inside the `options` array. This left a
  dangling `],` in the PHP array structure, causing a parse error (`unexpected token ","`)
  on any site running one of these skins. `show-n-tell` and `true-grit` also had a double
  comma (`",,`) after the `admin_styling` string. All four manifests corrected.

## 0.7.109 — "La-Z-Boy" (2026-05-12)

### Fixed
- **Hub heartbeat sweep — PDO parameter count mismatch** — `smack-multisite.php` heartbeat
  UPDATE had `site_tagline = ?` in the SQL (added in 0.7.107) but `$hb["site_tagline"] ?? null`
  was missing from the execute array. PDO throws on parameter count mismatch
  (`ERRMODE_EXCEPTION`), crashing the hub admin page on every load and causing spokes to
  report HTTP 500 when verifying hub connectivity. Fixed by adding the missing value.

## 0.7.108 — "La-Z-Boy" (2026-05-12)

### Fixed
- **`core/multisite-api.php` truncation** — file was truncated on disk mid-line, causing HTTP 500 on all spoke API calls (`multisite/heartbeat`, `multisite/stats/daily`, etc.). All spokes appeared OFFLINE as a result. Restored from git.
- **`core/mesh-helpers.php` truncation** — same truncation pattern, same cause. Restored from git.
- **Widespread working-tree truncation** — 617 tracked files were found truncated on disk (CRLF padding to identical byte counts masked the damage). All restored from git HEAD. Root cause: FUSE mount write behaviour during a prior session.

- **Ping failure shown as green success** — a failed manual ping ("Could not reach … HTTP 500") was displayed in a green `alert-success` box. Now correctly uses `alert-error` (red).

### Changed
- **SUYB API key auth** — `suyb-export.php`, `suyb-data.php`, `smack-disaster.php` now accept `X-Snap-Key` header authentication via `core/api-auth.php`, matching SYBU's pattern. Session cookie auth still works for browser use. Requires SUYB v0.7.4+. Get your API key from Admin → OH SNAP! API KEYS.

---

## 0.7.107 — "La-Z-Boy" (2026-05-12)

### Fixed
- **Heartbeat UPDATE missing `site_tagline`** — file repair in the 0.7.106 session dropped `site_tagline = ?` from the heartbeat UPDATE SQL while leaving it in `execute()`. PDO silently rejected the param-count mismatch so `last_seen_at` and `status = 'active'` never wrote to the DB, causing all spokes to appear offline after the hub updated to 0.7.106. Column restored.

## 0.7.106 — "Bar Stool" (2026-05-12)

### Added
- **Multisite hub self-row** — Connected Spokes table now shows the hub itself as the first row, with version, post count, pending comments, and backup status. Removes the need to check the hub separately.

### Fixed
- **Login page — Return to Site link** — moved inside the login card (was floating outside the dialog box at an unpredictable position).
- **Heartbeat grace period** — a single failed curl to a spoke no longer immediately flips its status to `offline`. The hub now requires 10 minutes of silence (`last_seen_at` > 600 s ago) before marking a spoke offline, so transient restarts or brief connectivity blips don't take the entire fleet dark.
- **Fleet Stats — `$share_pct` parse error** — pre-existing corruption on line 550 of `smack-multisite-stats.php` (`     = round(...)` missing the variable name) caused an HTTP 500 on the Fleet Stats page. Fixed: `$share_pct = round(...)`.
- **Heartbeat UPDATE missing `site_tagline`** — file repair in the previous session accidentally dropped `site_tagline = ?` from the heartbeat UPDATE SQL while leaving it in `execute()`. PDO silently rejected the param-count mismatch, so `last_seen_at` and `status = 'active'` never wrote to the DB — causing all spokes to appear offline after the hub updated to 0.7.106. Column restored to the SQL.
- **Truncated manifests and CSS** — `page-archive.css`, `public-facing.css`, `skins/rational-geo/style.css` and `manifest.php`, plus five other skin manifests were truncated mid-file. All repaired with correct EOF markers.

## 0.7.105 — "Fainting Couch" (2026-05-12)

### Added
- **Fleet Stats — enriched data** — `stats/daily` API endpoint now accepts `&enriched=1`.
  When present the spoke returns: `top_images[]` (top 10 most-viewed images with title,
  slug, thumb URL, view count), `bot_total`, `top_day` (peak date + views), and
  `period_totals` convenience aggregate. Bot counts come from `snap_stats_daily.bot_views`;
  top images from a live query against `snap_stats` grouped by `image_id`.
- **Fleet Stats — six summary tiles** — Fleet totals box gains: BOT VIEWS (count + % of
  all traffic), AVG VIEWS/DAY (non-zero days only), and PEAK DAY (date + views).
- **Fleet Stats — Most Viewed panel** — cross-fleet grid of the top 12 most-viewed images
  across all sites. Hub merges top images from every spoke + its own local query, sorts by
  views DESC, renders linked image cards with thumbnail, title, site badge, view count.
- **Fleet Stats — Network Breakdown enhancements** — table gains BOT % column and TOP
  IMAGE column (36px thumb + title + link to live page for each site's #1 image).
- **Help system — Fleet Stats** — new dedicated `fleet-stats` topic explaining all panels,
  time windows, data collection, and how enriched API data flows from spoke to hub.
- **Help system — Hub Update Push** — new `hub-update-push` topic covering UPDATE /
  UPDATE ALL BEHIND, the update sequence (download → verify → extract → migrate → report),
  version comparison logic, and failure recovery.
- **Help system — Remote Login SSO** — new `remote-login-sso` topic explaining the
  single-use token flow, session behaviour, requirements, and HTTPS note.
- **Help system — Multisite topic** — updated to reference fleet stats enrichment,
  Remote Login detail, and Hub Update Push.

### Fixed
- **Black Pearl — box backgrounds too light** — `.box`, `.signal-body`, `.login-box`,
  `.skin-meta-wrap`, `.file-upload-wrapper`, `.asset-card` darkened from `#1C1C1C` to
  `#111111`. Body/sidebar/footer remain `#0D0D0D`. Only the text colours were intended
  to change in the previous session; container colours are restored to near-black.
- **smack-settings.php — SMACKATTACK section** — POST MODES, SMACKATTACK, and API ACCESS
  sections were bare `<h3>` tags outside `.box` containers. All three now wrapped in
  `.box` like every other settings section.
- **SMACKATTACK checkbox** — `ste_enabled` used a plain `<input type="checkbox">` instead
  of the standard `.toggle-switch` / `.toggle-slider` pattern.

## 0.7.104 — "Fainting Couch" (2026-05-11)

### Fixed
- **Spoke registration token mismatch (401)** — `$settings` was loaded from the
  database before POST handlers ran, so the token just written to the DB was never
  reflected in the in-memory array. On the same page load, the token display used
  the stale value; if the admin clicked Generate more than once the displayed token
  was always one generation behind what the DB held. Hub would call the handshake
  with the shown token, DB had the newer one, `hash_equals` failed → 401. Fixed by
  updating `$settings` in memory immediately after writing the new token (and also
  after `enable_spoke` sets the role).
- **Multisite "UPDATE ALL BEHIND" count** — used `!==` equality check instead of
  `snap_version_compare()`, so a spoke ahead of the hub (e.g. updated independently)
  counted as "behind." All four locations fixed: count, bulk-update query, per-row
  triangle indicator, per-row UPDATE button.
- **Remote Login redirect loop** — link passed `?spoke=` but handler read `$_GET['sat']`.
  Node ID was always 0, triggering the guard redirect back to multisite.php.
- **Spoke registered / alert styling** — success message used bespoke `.msg` class
  instead of `.alert.alert-success`; also stripped legacy `> ` prefix from error alerts.
- **Fleet Stats time windows** — added 6M (180d), 1YR (365d), ALL options. Spoke API
  `stats/daily` endpoint now accepts `days=0` as all-time (no date filter) and lifts
  the old 365-day hard cap to 3650.
- **"UPDATE ALL BEHIND" greyed state** — button now shows disabled "ALL UP TO DATE"
  when all spokes are current instead of disappearing entirely.

### Changed
- **Archive Appearance — dual thumb border pickers** — replaces the single grey-only
  `archive_frame_style` dropdown with two independent controls: Grid/Cropped Thumb
  Border (width 0–8px + colour picker) and Masonry/Justified Thumb Border (width
  0/1/2px + colour picker). Masonry uses `outline` since public-facing.css resets
  `border !important` on `.justified-item`. Migration 060 seeds defaults. Applies
  to all skins.
- **Rational Geo v1.3** — removed legacy floating icon toggle (`rg-layout-toggle`);
  core T/M + C buttons in the filter bar are now the canonical toggle. Removed all
  associated CSS (~50 lines). Removed orphaned `thumb_border_width` manifest option
  (never wired to CSS; replaced by global border pickers above).

## 0.7.103 — "Ottoman" (2026-05-12)

### Fixed
- **smack-multisite.php parse error** — stray `]` on line 615 caused a fatal
  PHP parse error on any site running multisite pages after updating to 0.7.102.

## 0.7.102 — "Love Seat" (2026-05-10)

### Security
- **CSRF hardening** — `?disconnect=NODE_ID` and `?ping=NODE_ID` multisite
  actions converted from GET links to POST forms; GET-based state mutation
  was exploitable via crafted links against an authenticated hub admin
- **Timing-safe token comparison** — handshake registration token now
  compared with `hash_equals()` instead of `!==`; closes theoretical
  timing-attack path on the handshake endpoint
- **SSRF guard** — spoke registration now rejects URLs that resolve to
  private/loopback/reserved IP ranges; prevents a compromised admin account
  from using spoke registration to probe internal network services
- **Role enforcement on comments API** — `multisite/comments/action`
  endpoint now enforces `role = hub`; previously any valid Bearer token
  could approve/delete comments on a spoke
- **Image content validation in cross-post** — `multisite/posts/create`
  now calls `getimagesizefromstring()` after fetching the hub-supplied image
  URL; rejects non-image content before writing to disk

## 0.7.101 — "Love Seat" (2026-05-10)

### Fixed
- smack-multisite-blogroll.php: hub was absent from its own My Blogs push — now adds itself to the network list so spokes see all sites including the hub
- smack-settings.php: SMACKATTACK registration sent bare domain (no scheme) when site_url lacked https:// prefix — now always ensures scheme before submitting
- smack-settings.php: TEST KEY button vertical alignment fixed (btn-mt-0 + explicit height to match input)

## 0.7.100 — "Throne" (2026-05-10)

### Fixed
- The Black Pearl admin theme: remove colour contamination from cloud-progress and reorder-status selectors (green #4EC994 and red #E86060 replaced with greyscale — theme must be monochromatic)

## 0.7.99 — "High Chair" (2026-05-11)

### Fixed
- smack-multisite.php: fix escaped dollar signs on early settings load (Python patch wrote \$settings instead of $settings causing line 13 parse error on hub)

## 0.7.98 — "Rocking Chair" (2026-05-11)

### Fixed
- assets/adminthemes/the-black-pearl: alert-success/alert-error now white/grey only (no colour contamination)
- assets/adminthemes/50-shades-of-greymatter: reorder-status.error + cloud-progress done/failed/error/success converted from red/green to grey tones
- assets/adminthemes/bumblebee: reorder-status.error + cloud-progress done/failed/error/success converted from red/green to yellow/amber (no rainbow)
- smack-multisite.php: settings loaded before POST handlers so multisite_role is available to push_update check (hub was showing "only a hub can push updates" on its own dashboard)
- smack-central/sc-auth.php + sc-login.php: session lifetime set to 8 hours (gc_maxlifetime + cookie_lifetime)

## 0.7.97 — "Footstool" (2026-05-11)

### Added
- smack-multisite-blogroll.php: My Blogs hub-network category — when enabled, all active spokes are auto-prepended to every blogroll push under a configurable category name (default "My Blogs"); each entry uses the spoke's site tagline as description with per-spoke override; spoke is excluded from its own push (self-exclusion); settings saved to snap_settings
- core/multisite-api.php: heartbeat now returns `site_tagline` from spoke's snap_settings
- smack-multisite.php: heartbeat sweep stores `site_tagline` per node
- migrations/059_multisite_node_tagline.php: adds `site_tagline` and `blogroll_desc` columns to snap_multisite_nodes

### Fixed
- core/mesh-helpers.php: guard `roster_source` / `last_roster_seen_at` columns — detects pre-migration-054 installs, falls back to INSERT/UPDATE without those columns; prune step skipped when columns absent (fixes 500 on spoke roster sync)
- core/multisite-api.php: blogroll sync endpoint try-catch on `source_hub_url` DELETE for pre-migration-052 installs; conditional INSERT based on column presence
- assets/adminthemes/the-black-pearl/admin-theme-colours-the-black-pearl.css: restore #0D0D0D backgrounds; remove `box-shadow` from .box; bump body text to #BBBBBB
- smack-2fa.php: layout fix — was using undefined `admin-content` CSS class, changed to `class="main"`
- smack-menu.php: remove stray backslash causing PHP parse error on line 165

## 0.7.96 — "Ottoman" (2026-05-11)

### Fixed
- blogroll.php: page title BLOGROLL restored (was incorrectly "THE NETWORK"); suppress hub-domain category names (e.g. FOUNDTEXTURES.CA) and replace with generic label; remove per-entry source badge
- core/stats-logger.php: remove stray SNAPSMACK_EOF_HEADER fragment injected mid-file causing PHP parse error on line 74

## 0.7.95 — "Saddle Up" (2026-05-10)

### Added
- smack-multisite.php: hub can now push software updates to spokes directly from the dashboard — per-spoke UPDATE button (green, appears only when spoke is behind hub version) and bulk UPDATE ALL BEHIND button; results shown inline with version change, file count, migration count, and any errors
- assets/css/admin-theme-geometry-master.css: `.action-update` class (green border/text, same geometry as other inline action links)

### Fixed
- core/multisite-api.php: `POST multisite/updates/trigger` endpoint now correctly uses `SNAPSMACK_VERSION_SHORT` (not `SNAPSMACK_VERSION`) for already-current check

## 0.7.94 — "Three-Legged Stool" (2026-05-10)

### Fixed
- Version bump to clear 0.7.93 checksum mismatch. Sites already on 0.7.93
  see a false "VERIFICATION FAILED: SHA-256 CHECKSUM MISMATCH" because the
  stored checksum from the update does not match the package checksum.
  Bumping forces a clean update pass through the new seeding code.
- All changes identical to 0.7.93.

## 0.7.93 — "Bleacher Seat" (2026-05-10)

### Fixed
- Updater checksum seeding: sites updating to 0.7.92 ran the old `updater_set_version()` (PHP holds the running copy in memory during extraction) and never stored `installed_checksum`. Bumping to 0.7.93 ensures every site goes through the new code at least once, seeding the checksum for all future same-version rebuild detection.
- Smack Central dashboard showed 0 active installs: `sc-dashboard.php` called `sc_db()` (the `smack_central` DB) but `ss_forum_installs` lives in the forum DB. Fixed to use `sc_forum_db()`.
- Blogroll public page: category label now appears on each peer card (not just as a section heading). URL at the bottom of each card changed from a plain `<span>` to a real `<a>` link.

## 0.7.92 — "Front Row" (2026-05-10)

### Added
- Maintenance lock during update extraction. `core/constants.php` checks for `data/maintenance.lock` on every web request (skipped on CLI and for `smack-update.php` itself via `SNAPSMACK_IS_UPDATER`). The updater acquires the lock immediately before the first extraction chunk and releases it on completion, failure, or cancel. A 5-minute safety valve clears stuck locks. Public visitors see a clean 503 + `Retry-After: 30` page for the ~5–10 second extraction window.

## 0.7.91 — "Squat" (2026-05-10)

### Fixed
- Blogroll public page (`blogroll.php`): dedup logic used `$p['url']` but the column is `peer_url` — every entry hit the empty-key guard and was silently dropped, leaving the page blank even with entries in the DB.
- Admin success messages: standardized `smack-comments.php`, `smack-community-settings.php`, `smack-community-users.php`, and `smack-cloud.php` from `.msg` / bare `.alert` to `.alert.alert-success` / `.alert.alert-error`. Consistent bordered box across all admin pages.
- `smack-blogroll.php`: replaced chatty save confirmation with standard `> BLOGROLL SAVED`.

## 0.7.90 — "Take a Load Off" (2026-05-10)

### Fixed
- T/M/C archive controls: `alignDockedControls()` now runs unconditionally on DOMContentLoaded. Previously it only ran inside `dockControls()` (which skipped when controls were already server-rendered in `#infobox`), so alignment never fired on initial page load.
- Updater checksum tracking: `updater_check_status()` now also compares the stored applied checksum against the published package checksum. If the version is the same but the checksum differs (e.g. a force-pushed tag and rebuilt release), the updater correctly shows "update available" instead of "up to date". Applied checksum is stored in `snap_settings` as `installed_checksum` after each successful update.

## 0.7.89 — "Park Bench" (2026-05-10)

### Fixed
- T/M/C archive controls alignment: JS now computes right offset from actual grid bounding rect instead of CSS calc — reliable across all viewport widths and max-width settings.

## 0.7.88 — "Take a Load Off" (2026-05-10)

### Fixed
- T/M/C archive control buttons now aligned to content container inner edge using max(40px, calc(50% - 785px)) — previously floating against viewport edge on wide monitors.

## 0.7.87 — "Squat" (2026-05-10)

### Fixed
- Version bump to clear 0.7.86 checksum mismatch caused by post-tag commit.
- All changes identical to 0.7.86.

## 0.7.86 — "Sit Up Straight" (2026-05-10)

### Fixed
- Archive/calendar settings (calendar_side, calendar_months, etc.) added to global_only list in skin-settings.php — skin-scoped stale DB values were clobbering Archive Appearance saves, causing calendar to always slide from left and show 1 month regardless of saved settings.
- T/M/C archive control buttons now aligned to container inner edge (right: 40px) instead of viewport edge.

## 0.7.85 — "Front Row" (2026-05-10)

### Fixed
- `core/meta.php` calendar config now correctly reads `calendar_side` and `calendar_months` from DB settings (previously missing from pushed commits, causing hardcoded `side: left`, `months: 1` on live sites).

## 0.7.84 — "Bleacher Seat" (2026-05-10)

### Fixed
- Version bump to clear 0.7.83 checksum mismatch caused by post-tag patch commit.
- All changes identical to 0.7.83.

## 0.7.83 — "Take a Load Off" Collections v0.3 admin + archive fixes (2026-05-09)

### Added
- **Collections v0.3 schema** (migration 057): `snap_collections` — `name→title`, `featured_post_id→cover_image_id`, `is_visible→published`, `+default_display ENUM('browse','slideshow')`; `snap_collection_items` — `item_type` dropped, `item_id→image_id`, `sort_order→position`, `+caption TEXT`; unique key updated to `(collection_id, image_id)`.
- **Caption field** on collection edit page — per-image text input, saves to `snap_collection_items.caption` on blur via AJAX.
- **Default view selector** (browse/slideshow) on collection edit form, saved per collection.
- **Collection Settings section** in `smack-collections.php` — index rows (1–5) and default public sort order (manual/alphabetical/newest). Replaces the buried Archive Appearance control.

### Fixed
- `data-id` stray backslash in member drag rows — drag reorder AJAX was sending NaN for every image ID (reorder was silently broken).
- `$editing['name']` / `$col['name']` / `$col['title']` references updated throughout collections admin and archive filter panel after `name→title` schema rename.
- Archive controls (T/M/C) now `position:absolute` inside `#infobox` — previous `margin-left:auto` flex approach broke centering in skins using `justify-content:center` (50 shades, rational-geo).
- Login page PASSWORD/RECOVERY CODE tabs reverted to `login-tab`/`login-panel` classes with proper active-state styling.
- Calendar `side` default changed `left→right`; live sites need one Archive Appearance save to persist their choice.
- `collections_index_rows` removed from Archive Appearance (now lives in Collections admin only).
- `collections.php` sort fallback: URL → cookie → admin `collections_default_sort` setting → `manual`.

## 0.7.81 — "Lotus Position" CSS architecture cleanup + skin contract (2026-05-09)

### Added
- **`_spec/skin-contract.md`** — the formal contract between the CMS and any skin. Documents every CSS class the CMS emits per page, every JS engine's expected DOM hooks, and per-class STRUCTURAL vs DECORATIVE flags so skin authors know what they can override and what they must leave alone. Lives in git, gets updated whenever new public-side widgets land. Will become the input to oh-snap's skin-author wizard.
- **Per-page public CSS files**: `public-base.css` (every page — utilities, alignment, image fade engine), `page-archive.css` (archive only — grids, filter panel, T/M/C controls), `page-collection.css` (collection landing + index), `page-blogroll.css` (blogroll), `page-static.css` (page hero + 404). Smaller surface area per page, less FUSE-truncation risk per file, cleaner override targets for skin authors.

### Changed
- **`core/meta.php`** detects the executing page via `basename($_SERVER['SCRIPT_NAME'])` and loads only `public-base.css` plus the matching `page-*.css`. Pages that don't match a known mapping (e.g. custom skin pages) get just `public-base.css`. Versioned via `?v=<SNAPSMACK_VERSION_SHORT>` for cache-busting.
- **`assets/css/public-facing.css`** is now a deprecation shim that `@import`s the five split files. Existing references (skin templates, third-party links to the old URL) keep working unchanged. Will be removed in a future release once all references are migrated; flagged here.

### Fixed
- **T/M/C buttons render correctly on every public skin.** 0.7.80's buttons appeared as raw `<button>` boxes on photowalk because the CSS rules for `.archive-controls` and `.archive-calendar-toggle` were either not in `public-facing.css` at the time of push, or scoped only to `.archive-layout-toggle .alt-btn` and missed the C button. The new `page-archive.css` carries the full styling and ships with this release; once 0.7.81 is live, the segmented `[T][M]` and standalone `[C]` buttons appear inside the existing filter row (`#infobox`) at the right edge, with active-state highlighting on hover. `ss-engine-archive-toggle.js` docks the bar via `dockControls()` on DOMContentLoaded.

### Architecture notes
- The previous `public-facing.css` mixed structural rules (engine plumbing) with decorative rules (button colours, opacity, hover) in one 600-line file. Skin authors couldn't tell which was safe to override. The split now separates them: STRUCTURAL rules live in `public-base.css` clearly marked, DECORATIVE rules live in their respective `page-*.css` files. See `_spec/skin-contract.md` for the per-class breakdown.
- House standard going forward: CMS provides stock styling for every widget it ships. Skin's `style.css` overrides via standard CSS cascade — no manifest plumbing required. New widgets land with stock styling so unupdated skins still render usable controls; skin authors update on their own schedule.

## 0.7.80 — "Cross-Legged" Collections v0.2 + Archive layout/calendar decoupling + T/M/C buttons (2026-05-09)

### Added
- **Public help modal (`ss-engine-public-help.js`).** F1 anywhere on the public site — or click the new HELP link in the footer — opens a page-aware modal listing exactly the controls and shortcuts active on the current page. Same feature-detection pattern as the admin help modal: hints for layout toggles, calendar, comments, download, lightbox, search field, and collections sort only render when their controls exist on the page. Optional admin-set `meta[name="snap-help-about"]` “About this site” blurb appears at the bottom when present.
- **Pretty URLs for collections.** `core/htaccess-template` now ships rewrites for `/collections` (→ `collections.php`) and `/collection/<slug>` (→ `collection.php?slug=<slug>`). Spokes pick up the new rules next time admin clicks REPAIR in *Maintenance → HTACCESS REPAIR*.
- **CALENDAR MONTHS slider** in Archive Appearance (1–6 months). Drives `calendar_months` setting consumed by `ss-engine-calendar.js`.
- **COLLECTIONS INDEX ROWS** select (1 or 2) in Archive Appearance. Drives `collections_index_rows` setting on `collections.php` index page.
- **Collection editor: 30-cap counter and image-only picker.** Member list shows `<count> / 30 IMAGES` at the top, updates live on add/remove/reorder. The Posts/Albums/Categories tabbed picker is gone — v0.2 is image-only, search field is straight image-title search.
- **Single-letter button labels** on the archive controls: `[ T ]`, `[ M ]`, `[ C ]`. Tooltips and aria-labels carry the full names ("Thumbs Layout", "Masonry / Justified Layout", "Toggle Calendar Panel") so screen readers and hover stay descriptive while the buttons themselves stay tight.
- **Collections v0.2 (image-only print folios).** Migration 055 narrows `snap_collection_items.item_type` ENUM to `('image')` and converts existing `'post'` rows to `'image'` via `snap_images.post_id` mapping; orphaned/album/category rows are deleted with reported counts. Adds `snap_collections.is_visible TINYINT` (defaults to 0 — existing collections start hidden until the admin re-curates as individual images and flips them live). Hard cap of 30 images per collection enforced in `smack-collections.php` `add_item` AJAX handler. Per spec `_spec/collections-v0_2.md`.
- **Public collection landing page — `collection.php`.** URL `/collection.php?slug=...`. Renders only if `is_visible = 1` (404 otherwise). H1 + description + featured image hero + member grid in curator sort_order. Each tile clicks through to the single-image page.
- **Public collections index page — `collections.php`.** Lists all visible collections as a tile grid. Sort toggle: Manual (sort_order) / A→Z / Newest / Oldest. Visitor's sort choice cookied (`smack_collections_sort`) for a year. Single or double row layout via new `collections_index_rows` setting (admin: 1 or 2; default 1).
- **Hard 30-image cap, server-side enforced.** `smack-collections.php` `add_item` returns `{ ok: false, cap_reached: true }` on the 31st add. DB-level `UNIQUE KEY` on `(collection_id, item_type, item_id)` prevents dupes; ENUM narrowing rejects non-image inserts at the DB layer too.
- **Per-collection visibility toggle.** New `toggle_visibility` AJAX action in `smack-collections.php`. Hidden collections excluded from `collection.php` (404), `collections.php` (filtered out), and Menu Manager pool (admin can't add a hidden collection to the public nav).
- **`ss-engine-archive-toggle.js` (new engine).** In-place T/M layout switching with `history.pushState`, AJAX grid swap, cookie persistence. Hotkey support: T = Thumbs, M = Masonry. Replaces the full-page reload pattern that caused the visible "blip" between layouts. Registered as `smack-archive-toggle` in `core/manifest-inventory.php`; loaded automatically on `archive.php`.
- **Calendar decoupled from layout.** Calendar is no longer a pseudo-layout (`croppedwithcalendar`); it's an independent on/off panel that overlays on either Thumbs or Masonry. Migration 056 promotes `archive_calendar_enabled` and `archive_calendar_default_open` to first-class settings. Cookie `smack_archive_calendar = open|closed` persists open state for a year.
- **Archive hotkey: C — toggle calendar.** Wired in `ss-engine-calendar.js` alongside the calendar button click handler. Only fires when calendar is admin-enabled (no toggle button = no hotkey, same pattern as comments / download / archive layout).
- **F1 help modal updated** to list T / M / C archive hotkeys when their controls are present on the current page — same feature-detection pattern that already gates `[2] Toggle Comments` and `[D] Download`.

### Changed
- **`50-shades-of-noah-grey` and `rational-geo` skins overhauled** to defer the layout toggle UI to core `archive.php`. Both skins previously rendered their own icon-based 4-way toggle (with their own localStorage keys and special-case calendar handling) which conflicted with the new `[T][M][C]` controls in 0.7.79's first cut. Now they render only the photo grids and listen for the `smackarchive:layoutchange` custom event from `ss-engine-archive-toggle.js` to swap which grid is visible. Single source of truth for layout choice; no duplicate toggles, no competing storage keys, no calendar reload navigation.
- **`archive.php` toggle gating** no longer checks for skin-specific `archive-layout.php` files when deciding to render the `[T][M][C]` controls. The admin's `archive_show_layout_toggle` setting is the only gate. Skins with `archive-layout.php` render grids only.
- **Archive layout vocabulary collapsed.** Old 4-way layouts (`square` / `cropped` / `croppedwithcalendar` / `masonry`) reduced to 2-way (`thumbs` / `masonry`). Thumb style (`square` or `cropped`) is now an independent admin choice via `archive_thumb_style` and applies whenever layout = thumbs, regardless of which skin is active. Old URL params (`?layout=square`, `?layout=cropped`, `?layout=croppedwithcalendar`) auto-redirect to the new model. Migration 056 maps existing settings to the new keys.
- **Calendar engine — in-place toggle, no navigation.** `closePanel()` no longer navigates to a fallback layout URL. The calendar panel slides out, sets `<html data-archive-calendar="closed">`, updates the cookie, and stays on the current page. Same for `open()`. URLs from date-clicks no longer pin `?layout=croppedwithcalendar` either — server uses the cookie + admin defaults.
- **Archive Appearance admin (`smack-appearance-archive.php`)** simplified. Old "Offer Visitors a Layout Switch?" four-checkbox grid replaced with: `DEFAULT LAYOUT` (Thumbs/Masonry/Disabled), `THUMB STYLE` (Cropped/Square), `VISITOR CONTROLS` (toggle thumbs↔masonry checkbox + enable calendar checkbox + calendar starts open checkbox).
- **`smack-collections.php` `search_items` is image-only** — the album and category branches are gone, matching the v0.2 schema narrowing. Picker queries `snap_images` directly with `img_status = 'published'` filter.

### Fixed
- **Public blogroll no longer leaks "Hub:" topology.** `blogroll.php` now strips any `Hub:` prefix from category names before rendering. Visitors stop seeing "Hub: foundtextures.ca" as a section header (which advertised that the site was part of a multisite mesh — a fingerprint useful only to attackers mapping the network). Internal `source_hub_url` tracking still works for sync; only the public label is sanitised.
- **Blogroll dedup across categories.** When a peer URL appeared in both a local category AND a hub-synced category, both entries rendered — visitors saw "Away With A Camera" and "Rick McGinnis Photographs" listed twice on photowalk.ing. `blogroll.php` now dedupes by lowercased URL across all sections; first occurrence wins, with a deliberate two-pass that prefers locally-added entries over hub-synced when both exist.
- **Menu Manager pool gates hidden collections.** Previously the pool query for collections was missing entirely (variable `$collection_items` was referenced but never populated, so the pool was always empty). Now populated with `WHERE is_visible = 1` so admin can drag visible collections into the nav but hidden ones never surface.

### Schema
- Migration 055 — collections v0.2 (ENUM narrow, drop non-image rows, add is_visible)
- Migration 056 — archive layout simplification (new keys: `archive_thumb_style`, `archive_calendar_enabled`, `archive_calendar_default_open`, `archive_show_layout_toggle`; legacy `archive_layouts_available` pinned to `thumbs,masonry`)
- `snapsmack_canonical.sql` — `snap_collections.is_visible` and `snap_collection_items.item_type ENUM('image')` reflected

### Migration notes
- After updating each spoke, click **REPAIR** in *Maintenance → HTACCESS REPAIR* (Probe Guard rule shipped in 0.7.77 — still required if a spoke skipped that update).
- Existing v0.1 collections lose their album/category members (re-curate as individual images). Existing collections also start hidden — admin flips visibility on after re-curating.
- Old archive bookmarks (`?layout=square`, `?layout=cropped`, `?layout=croppedwithcalendar`) auto-redirect to the new model. No 301 needed; the resolution happens server-side in `archive.php`.

## 0.7.78 — "Bench Press" SC tag-filter fix (2026-05-09)

### Fixed
- **Archive layout persistence is now flash-free and server-side.** Previously the layout choice (square / cropped / cropped-with-calendar / masonry) was kept in `localStorage` and a JS redirect re-loaded the page if the saved pref differed from what the server rendered. That caused a visible "blip back to cropped" between renders, and on some skins the redirect didn't fire at all. Switched to a `smack_archive_layout` cookie that the server reads in `archive.php` *before* selecting the layout, so bare `/archive.php` resolves to the right layout on first byte. JS still mirrors to localStorage for cross-tab sync. No more flash, no more "always comes back to cropped."
- **Calendar panel background now matches the host skin automatically.** `ss-engine-calendar.css` extended its CSS variable fallback chain: panel-specific `--cal-*` hooks first, then the admin-overridable `--page-bg`, then the standard skin convention (`--bg-primary`, `--text-primary`, `--border-color`, `--accent-color`), then short legacy names, then hardcoded dark. Skins no longer need to declare `--cal-*` hooks individually — the calendar inherits whatever the skin already sets for its body and accent colours. Same applies to text, border, dim, and accent.
- `smack-central/sc-update.php` — SC was pulling the wrong release tag because PHP's `version_compare`, after normalising trailing patch letters, was sorting `vSYBU-0.7.9i` (and other companion-tool tags) ABOVE the real SnapSmack release tags. Result: SC clicked UPDATE, downloaded a SYBU tag's repo zip, and overwrote `smack-central/` with files frozen at the SYBU commit — i.e. before the SC CSS daylight pass. Visible symptom: SC dashboard rendered with the OLD nested-comment-broken `sc-geometry.css` and `sc-colours.css` even after a successful "update." Filter now restricts the tag list to pure semver patterns (`v?\d+\.\d+\.\d+[a-z]?`) before sorting, so only SnapSmack release tags are considered.
- `smack-central/sc-update.php` — also upgraded from legacy `// EOF` marker to long-form `// ===== SNAPSMACK EOF =====` and added the `SNAPSMACK_EOF_HEADER` block that the rest of the codebase uses, so `tools/check-eof.py` covers it consistently.
- `smack-post-solo.php` and `smack-post-carousel.php` — added a `DOMContentLoaded` initialiser that calls `updateLabel('cat')`, `updateLabel('album')`, and `updateLabel('collection')` on page load. Without it, the multiselect placeholder spans rendered with their hardcoded mixed-case strings (`Select Albums...`, `Select Collections...`) instead of the all-caps form (`SELECT ALBUMS...`, `SELECT COLLECTIONS...`) used everywhere else in the admin. `smack-edit.php` already had this initialiser; the two post-creation pages were missing it.
- `assets/adminthemes/purple-rain/admin-theme-colours-purple-rain.css` — `.nav-section-toggle` was set to `#00FFFF` (pure cyan), making the accordion section headers (THE GOOD SHIT, PIMP YOUR RIDE, BORING ASS STUFF, HELP I NEED SOMEBODY, SWITCH TO BIG WHEEL) render in cyan instead of magenta on the Purple Rain skin. Copy/paste leftover from another theme. Switched to `#FF00FF` to match the brand text and active-item colour.

### Migration
- **Smack Central self-update is currently broken on snapsmack.ca because the running `sc-update.php` has the bug it's supposed to fix.** Recover by SSH'ing into snapsmack.ca and overwriting the SC files manually from the v0.7.78 tag, OR by manually pulling just the two CSS files from raw GitHub. After the fix is in place, future SC updates work normally.

## 0.7.77 — "Sit Pretty" (2026-05-09)

### Added
- **VIEW LIVE** and **VIEW TEMPLATE** buttons in *Boring Ass Stuff → Maintenance* — read-only display of the live `.htaccess` and the canonical `core/htaccess-template`. Useful for spotting drift between what's deployed and what should be there. No edits, just a `<pre>` block with monospace contents.
- `core/htaccess-template` — canonical .htaccess rules now live in a tracked template file rather than a heredoc inside `smack-maintenance.php`. Single source of truth in git: edit the template, every site picks it up next time you click REPAIR. No more FTPing per-site .htaccess to add a rule.
- **Probe Guard rule** added to the canonical template — routes scanner/exploit paths (`wp-login.php`, `xmlrpc.php`, `\.env`, `phpmyadmin`, `adminer`, `shell.php`, `c99.php`, etc.) to `probe-ban.php`, which logs a 30-day IP ban. This is what feeds the IP Smacker auto-ban list. Sites whose .htaccess pre-dates this rule see no auto-bans because the scanner traffic never reaches the counter — click REPAIR in System Maintenance to install the rule.
- `snap-in` named route added to the canonical template — `/snap-in` resolves to `snap-in.php` directly, bypassing the catch-all router. Required by the customisable login slug feature.
- **Proxy-aware HTTPS redirect** — `X-Forwarded-Proto` check added to the HTTPS redirect block. Prevents redirect loops behind Cloudflare Tunnel and other SSL-terminating reverse proxies that connect to origin over plain HTTP.
- **Custom error pages** — `ErrorDocument 404 /error404.php` and `ErrorDocument 500 /error500.php` added to the template.

### Changed
- `smack-maintenance.php` HTACCESS DIAGNOSTICS now checks 12 sections (was 8): added Proxy-aware HTTPS, snap-in named route, Probe Guard, and Custom error pages. HTACCESS REPAIR rebuilds the SnapSmack block from the template instead of the embedded heredoc.
- Core PHP file blocklist extended to include `login.php` (the legacy filename, still occasionally probed even though the live login is at `snap-in`).

### Migration
- After updating, click **REPAIR** in *Boring Ass Stuff → Maintenance → HTACCESS REPAIR* on each spoke. The repair preserves any non-SnapSmack rules already in your `.htaccess` and replaces only the SnapSmack block.

## 0.7.76 — "Park It" (2026-05-08)

### Fixed
- `core/sidebar.php` — the System Updates link in the admin sidebar had an inline `onclick` interceptor that called `SnapUpdater.open()` to show a modal in-place. The modal was failing silently (object exists, `.open` is a function, but the call did nothing) so clicks were swallowed and nothing happened. Removed the interceptor entirely so the link now navigates straight to `smack-update.php`, which works correctly. The modal can be re-introduced later once the JS-side problem is diagnosed; this is the pragmatic fix to unbreak the workflow now.

### Changed
- `smack-central/assets/css/sc-colours.css` — daylight contrast pass on the Smack Central dark theme. Body bg `#141414`→`#1A1A1A`, box bg `#1A1A1A`→`#232323`, primary text `#cccccc`→`#EAEAEA`, dim text (the labels everywhere) `#888888`→`#BBBBBB` (was failing WCAG AA), borders `#2A2A2A`→`#3A3A3A`. Status colours softened slightly. SC pages were unreadable in bright rooms; now legible. Smack Central version bumped to 0.7.77 to invalidate browser/CF cache via the `?v=<SC_VERSION>` query string already on the link tags.

## 0.7.75 — "Bench Press" (2026-05-08)

### Fixed
- `smack-central/assets/css/sc-geometry.css` and `sc-colours.css` — both files had a nested-comment line in the EOF_HEADER docblock: `/* foo /* bar */ baz */`. CSS comments don't nest — the first `*/` closes the first `/*`, leaving "baz */" as junk top-level CSS. Browsers usually error-recover, but some parsers don't, which can prevent the `:root` variable block below from registering. Symptom: text colour variables undefined, page renders unreadable on dim monitors. Rewrote both EOF_HEADER blocks in the multiline comment style sc-admin.css already uses

## 0.7.74 — "Easy Rider" (2026-05-08)

### Changed
- `smack-central/assets/css/sc-geometry.css` and new `sc-colours.css` — split the misnamed combined-tokens file into two: geometry (typography, spacing, sizing, transitions) and colours (backgrounds, borders, text, accent, status). Mirrors the SnapSmack admin pattern (geometry-master + per-theme colour files). Future SC theming becomes a sibling-file pattern (`sc-colours-purple-rain.css`, etc.) instead of editing one mixed file
- `smack-central/sc-layout-top.php` and `smack-central/sc-login.php` — now load `sc-colours.css` after `sc-geometry.css` so colour declarations win on tied specificity

### Fixed
- SC text was rendering near-invisible (`#555` dim, `#777` labels) on dark grey backgrounds — Smack Central pages looked all black until selected. Lifted to `#888` / `#aaa` for daylight legibility; fix carries forward in the new `sc-colours.css` file

## 0.7.73 — "Reverse Cowgirl" (2026-05-08)

### Fixed
- `sybu-data.php` — `ORDER BY img_id ASC` was wrong; `snap_images` primary key is `id`, not `img_id`. The malformed SQL was the actual cause of the empty-body 500 SYBU has been hitting on connect, not the snap_tags theory I'd been chasing. Fix is one column rename in the query
- `smack-central/assets/css/sc-admin.css` — `.sc-page-header` flex used `align-items: center`, which aligned the vertical midpoints of the bold uppercase title and the small dim trail text. Different sizes/weights → visual baseline drift. Switched to `align-items: baseline` so the bottoms of the text characters line up regardless of size mismatch

## 0.7.72 — "Sit Tight" — CSRF protection (2026-05-08)

### Added
- `core/csrf.php` — per-session CSRF token engine. Public API: `csrf_token()`, `csrf_field()`, `csrf_meta_tag()`, `csrf_check()`, `csrf_exempt()`, `csrf_rotate()`. Tokens generated via `random_bytes(32)` and stored in the session, validated with `hash_equals()` so failed checks don't leak via timing
- `assets/js/ss-engine-admin-csrf.js` — wraps `window.fetch` and `XMLHttpRequest.send` to auto-attach an `X-CSRF-Token` header on every POST/PUT/PATCH/DELETE request from admin pages. Token read from `<meta name="csrf-token">` emitted by admin-header.php
- Auto-injection of `<input type="hidden" name="csrf_token">` into every `<form method="POST">` on admin pages — handled by `core/admin-footer.php` via output buffering. No per-form code changes needed across the 60+ admin POST handlers

### Changed
- `core/auth.php` — calls `csrf_check()` automatically on POST. Pages that legitimately POST without a session-tied token (login flow, tool API endpoints) call `csrf_exempt()` first
- `core/admin-header.php` — emits `<meta name="csrf-token">` in the document head; opens an `ob_start()` buffer so the footer can inject form tokens
- `core/admin-footer.php` — closes the buffer, injects the hidden field into every `<form method="POST">`, then flushes
- `suyb-data.php`, `suyb-export.php` — call `csrf_exempt()` before including auth.php; SYBU authenticates with X-Snap-Key, doesn't carry CSRF tokens
- Tool API endpoints using `core/api-auth.php` already bypass CSRF naturally — `api-auth.php` returns early on valid X-Snap-Key, never reaching `auth.php`'s validator. Browser sessions falling through still get CSRF-checked

### Notes
- This addresses the "CSRF deferred (HIGH severity)" item from CLAUDE.md / the security audit. Combined with `SameSite=Lax` cookies (already in place), admin POSTs are now defended against forged cross-site form submissions
- Login form (`snap-in.php`), 2FA verification, password reset, and community-auth flows don't include `core/auth.php` so they aren't auto-validated. They handle their own pre-auth security via rate limiting and one-time tokens
- If a form anywhere on the admin breaks with "CSRF token mismatch" after this, the cause is almost always: the form lives in a partial that's rendered before `admin-header.php` runs (so the buffer isn't open yet), or the form is hand-emitted via JS without going through the engine. Fix is either move the form to inside the buffered region or call `csrf_field()` manually

## 0.7.71 — "Recliner" (2026-05-08)

### Changed
- `core/sidebar.php` — multisite menu items (Spoke Signals, Spoke Posts, Backup Dock, Fleet Stats, Cross-Post, Blogroll Sync) now visible on **any** install that's part of a multisite network, not just hubs. The 0.7.66/0.7.69 hub-only gate was correct under the old "features only work on hub" architecture, but with mesh foundation in 0.7.70 spokes will progressively gain access too as each feature is converted to peer-aware. Sidebar visibility is the entry point; per-page conversion is upcoming work and not in this commit. Clicking these on a spoke right now still hits the page-level `!== 'hub'` guard until each page is converted

## 0.7.70 — "Smack in the Middle" — mesh foundation (2026-05-08)

First slice of mesh-mode (codename **Smack in the Middle**): every install
can now hold a roster of every other install in the network, and inter-peer
auth is in place. No features have been converted from hub-only to
bidirectional yet — that comes in alpha-2 onward (Cross-Post first).

### Added
- `migrations/054_mesh_foundation.php` — extends `snap_multisite_nodes`
  with `accepts_crosspost`, `accepts_blogroll`, `accepts_stats_query`,
  `roster_source`, `last_roster_seen_at`. Existing rows stamped with
  `roster_source = 'self'` so they are never pruned by roster sync.
- `core/mesh-helpers.php` — shared functions: `ms_resolve_peer()`,
  `ms_peer_allows()`, `ms_build_roster()`, `ms_ingest_roster()`.
- `core/multisite-api.php` — `multisite/ping` response now includes
  `mesh.peers` (canonical roster, hub-side only). New endpoint
  `GET multisite/peers/list` for explicit on-demand roster pulls.
- `smack-multisite.php` — verify-hub button now ingests the roster
  returned by the hub on ping and reports added/updated/pruned counts.

### Foundation only
- No bidirectional features wired yet. Cross-Post, Blogroll Sync, Fleet
  Stats, etc. are still hub-only as before. Coming in subsequent alphas
  on the `dev` branch.
- Sidebar items still gated to `=== 'hub'` from 0.7.66 — will be
  loosened once corresponding features are mesh-aware.

## 0.7.69 — "Park It" (2026-05-08)

### Fixed
- `core/sidebar.php` — the gate-on-`hub` change from 0.7.66 didn't reach all installs (probably wasn't included in the 0.7.66 deploy or was overridden somewhere). Re-shipping the file so spokes definitively stop seeing Spoke Signals / Spoke Posts / Backup Dock / Fleet Stats / Cross-Post / Blogroll Sync menu items in the sidebar. Final deploy of this fix before the 0.8.0 mesh rewrite changes the rules anyway

## 0.7.68 — "Cheek Mate" (2026-05-08)

### Fixed
- `core/multisite-api.php` — `source_hub_url` was being stored as the full URL the hub sent (e.g. `https://foundtextures.ca/`), but migration 052 stamped legacy rows with hostname-only form (e.g. `foundtextures.ca`). The mismatch meant `DELETE WHERE source_hub_url = ?` on re-sync didn't find the migration-stamped rows, fresh rows got inserted alongside, and spokes ended up with duplicate peers — half under the legacy `Hub:` bucket (now uncategorized after 052) and half under proper categories. Sync handler now normalizes `hub_url` to hostname-only form at the top, so storage and comparison agree

### Added
- `migrations/053_blogroll_dedupe_hub_synced.php` — one-shot cleanup that drops every hub-synced row on the spoke (identified by `source_hub_url IS NOT NULL` OR membership in any leftover `Hub: <url>` category) plus any leftover `Hub: <url>` category rows. Locally-added peers (`source_hub_url IS NULL` and not in a `Hub:` category) are untouched. After running, ask the hub to re-push so entries come back with proper categories and consistent hostname-form `source_hub_url`. Idempotent

## 0.7.67 — "Sit On It" (2026-05-08)

### Fixed
- `core/multisite-api.php` and `smack-multisite-blogroll.php` — hub-to-spoke blogroll push was dropping the hub's category structure. The hub-side SELECT only pulled `peer_name, peer_url, peer_rss, peer_desc` (no category column), and the spoke-side endpoint dumped every received entry into a single auto-created category named `Hub: <hub_url>`. Visitors of any spoke saw all hub-pushed peers piled under one ugly heading like `HUB: FOUNDTEXTURES.CA` regardless of how the hub had organized them. Hub now sends each entry with its `category` field; spoke now matches/creates categories case-insensitively and assigns each entry to its proper bucket
- `core/multisite-api.php` — re-sync logic now identifies hub-synced entries by their new `source_hub_url` column instead of by the legacy `Hub: <url>` category. Spoke admin's locally-added blogroll entries are no longer at risk of being deleted on hub re-sync

### Added
- `migrations/052_blogroll_source_hub_url.php` — adds `source_hub_url` to `snap_blogroll` (with index), stamps it on existing rows that landed in legacy `Hub: <url>` categories (parsing the URL out of the cat name), uncategorizes those rows, and deletes the now-empty `Hub: <url>` categories. Idempotent — safe to re-run
- `database/schema/snapsmack_canonical.sql` — `snap_blogroll.source_hub_url` column added with `idx_source_hub_url` index

## 0.7.66 — "Hot Seat" (2026-05-08)

### Fixed
- `smack-collections.php` — "+ NEW COLLECTION" button was wrapped in `<a><button>` inside the `header-row--ruled` flex container, which caused it to render above the underline rule and broke header alignment. Moved the button onto its own row below the heading rule using a single `<a class="btn-smack">` element

### Added
- `smack-blogroll.php` — MANAGE CATEGORIES section above the existing add-peer form. Lists existing categories with rename + delete buttons inline, plus a "+ ADD CATEGORY" input below. Three new POST handlers (`new_blogroll_cat`, `rename_blogroll_cat`, `delete_blogroll_cat`). Deleting a category reassigns its peers to UNCATEGORIZED before removing the row. Was either lost in a pre-March 2026 OneDrive index-corruption event or never made it into the current repo

- `assets/css/admin-theme-geometry-master.css` — new utility classes used by the blogroll category rows: `.blogroll-cat-row`, `.blogroll-cat-row--new`, `.blogroll-cat-input`, and a generic small-button class `.btn-sm`

### Changed
- `core/sidebar.php` — Spoke Signals, Spoke Posts, Backup Dock, Fleet Stats, Cross-Post, and Blogroll Sync sidebar entries are now hidden on spoke installs. Previously the sidebar gate was `!empty($settings['multisite_role'])` which is true for both hubs and spokes, so spokes saw menu items that dead-ended on the target page's hub-only guard. Tightened the gate to `=== 'hub'`

## 0.7.65 — "Squat Goals" (2026-05-08)

### Fixed
- `core/meta.php` — every JS engine listed in a skin's `require_scripts` was loading TWICE. core/meta.php emitted `<script>` tags in `<head>`, and the active skin's `skin-footer.php` emitted them again at end of body. The comment in meta.php has always said "outputs only CSS links" — at some point the script emission got added and was never noticed because most engines are idempotent. The calendar engine isn't: each load builds its own panel + overlay. That's why clicking X on the calendar revealed a second calendar underneath — closePanel only closed the panel held by the second copy of the engine's closure. Removed the script emission from meta.php; skin-footer.php remains the single source of script tags. CSS link emission stays in meta.php as originally designed
- `smack-collections.php` — featured image picker AJAX endpoint queried `snap_posts` (joined to `snap_images` via `post_id`). On photoblog installs where photos live directly in `snap_images` and aren't wrapped in longform posts, this returned zero results — picker showed "No posts." Switched to query `snap_images` directly, matching `smack-albums.php` and `smack-cats.php`

## 0.7.64 — "Bottoms Up" (2026-05-08)

### Fixed
- `smack-albums.php` and `smack-collections.php` — `ssFeaturedPicker.attach()` calls were running during HTML parse, before the deferred engine script had executed. Result: empty FEATURED IMAGE box with no SELECT IMAGE button. Calls are now wrapped in `DOMContentLoaded`, which fires after deferred scripts load, so `window.ssFeaturedPicker` is defined when attach runs

### Changed
- `smack-cats.php` — migrated to the shared `ss-engine-featured-picker` engine (matches `smack-albums.php` and `smack-collections.php` from 0.7.63). Removes inline modal CSS, inline picker JS, and the legacy 80-row LIMIT. Same theme-variable styling, same LOAD MORE pagination

## 0.7.63 — "Saddle Up" (2026-05-08)

### Fixed
- `skins/50-shades-of-noah-grey/archive-layout.php` and `skins/rational-geo/archive-layout.php` — when on `?layout=croppedwithcalendar`, init() now hides `#justified-grid` and shows `#browse-grid` before returning. Previously the early-return (added in 0.7.60 to break the redirect loop) skipped grid-display setup entirely, so both grids stayed visible — the masonry grid bled through behind the calendar panel and was visible during the close slide-out, giving the impression of "another calendar under it"
- `skins/50-shades-of-noah-grey/archive-layout.php` and `skins/rational-geo/archive-layout.php` — URL is now source of truth for archive layout. When `?layout=` is explicit in the URL, the skin uses it and ignores stored localStorage preference. localStorage is consulted only when `archive.php` is hit with no params. Previously a stale `localStorage = 'masonry'` from a prior session would override every URL including `?layout=cropped`, making the cropped layout render as masonry

### Changed
- Featured image picker (used by `smack-albums.php`, `smack-collections.php`, and `smack-cats.php`) extracted from inline CSS / inline JS into shared engine files: `assets/css/ss-engine-featured-picker.css` and `assets/js/ss-engine-featured-picker.js`. All three pages now use the engine via `window.ssFeaturedPicker.attach({...})` from inside `DOMContentLoaded` (the engine script is loaded with `defer`, so the listener guarantees it's defined before attach runs). Picker uses the active admin theme's CSS custom properties (`--bg`, `--card-bg`, `--border`, `--text`, `--dim`, `--input-bg`, `--accent`) so it matches whatever skin is active — no hand-picked colours
- Featured image picker AJAX endpoints in all three pages now paginate. Each returns `{ posts: [...], hasMore: bool }`. Engine renders a "LOAD MORE" button at the bottom of the grid when more results are available

## 0.7.62 — "Lap Dance" (2026-05-08)

### Fixed
- `smack-globalvibe.php` — Masthead Mode dropdown now writes to setting `header_type` with values `text`/`image` to match what `core/header.php` reads. Previously it saved to an orphan key `masthead_type` with value `logo`, so picking "Custom Logo Image" had no effect on the rendered masthead
- `core/admin-footer.php` — Added missing `<script src="assets/js/ss-engine-updater.js">` tag. Without it, `SnapUpdater` was undefined globally and the sidebar's "System Updates" link silently fell through to the legacy page-load updater instead of opening the modal
- `assets/adminthemes/purple-rain/admin-theme-colours-purple-rain.css` — `.btn-smack` background changed from `#7F007F` to `#B000B0`. Brightness still reduced from full magenta, but vivid purple character is back instead of muddy plum

### Added
- `migrations/051_snap_tags.php` — idempotent migration that creates `snap_tags` (and adds `created_at` / `color_family` columns if missing) on installs that pre-date its addition to canonical schema. Without this table, `sybu-data.php` 500s after auth, blocking SYBU connect

## 0.7.61 — "Stay Seated" (2026-05-07)

### Fixed
- `assets/js/ss-engine-calendar.js` — X button and overlay click-outside now fire `smackcal:closing` CustomEvent before navigating, carrying the target layout slug so skins can update localStorage
- `assets/js/ss-engine-calendar.js` — `findFallbackLayoutLink()` and `wireLayoutLinks()` now handle `<button data-layout>` elements (not just `<a>` tags); URL is constructed from data-layout value when no href exists
- `assets/js/ss-engine-calendar.js` — Transparent overlay added behind panel; clicking outside the calendar panel closes it
- `assets/js/ss-engine-calendar.js` — ESC key now closes the panel (previously only cleared range-start mode)
- `assets/css/ss-engine-calendar.css` — Range-mode cursor changed from `crosshair` to `pointer` for consistency; added overlay CSS
- `skins/50-shades-of-noah-grey/archive-layout.php` — `init()` no longer restores `croppedwithcalendar` from localStorage; listens for `smackcal:closing` to write correct target layout
- `skins/rational-geo/archive-layout.php` — same localStorage guard and `smackcal:closing` listener

## 0.7.60 — "Stay Seated" (2026-05-07)

### Fixed
- `skins/50-shades-of-noah-grey/archive-layout.php`, `skins/rational-geo/archive-layout.php` — `init()` was reading localStorage on page load and calling `setLayout()`, which triggered an immediate redirect away from `croppedwithcalendar` (the body-class condition in `setLayout` fires a nav); calendar page now stays put when URL specifies that layout
- `assets/adminthemes/purple-rain/admin-theme-colours-purple-rain.css` — sidebar section headings colour corrected to saturated purple (#BB00BB) after 0.7.59 accidentally desaturated them

---

## 0.7.59 — "Stay Seated" (2026-05-07)

### Fixed
- `core/meta.php` — `admin_page` flag was incorrectly blocking calendar JS/CSS from loading on public pages; flag controls only where admin settings render, not whether the engine loads publicly
- `smack-appearance-archive.php` — restored "Cropped + Calendar" checkbox to the layout switch; it was removed without authorization in 0.7.58
- `assets/adminthemes/purple-rain/admin-theme-colours-purple-rain.css` — sidebar section headings (#444444) made readable (#886688); nav links bumped from #AAAAAA to #CCCCCC for daylight legibility

---

## 0.7.58 — "Stay Seated" (2026-05-06)

### Fixed
- `smack-appearance-archive.php` — masonry/justified option moved to bottom of layout order (square → cropped → calendar → masonry)
- `smack-appearance-archive.php` — "CROPPED + CALENDAR" removed from the layout switch checkboxes (duplicate control); calendar on/off is now controlled exclusively by the ENABLE SLIDING DATE PANEL checkbox in the CALENDAR section below
- `archive.php` — manifest path changed to relative (`skins/{skin}/manifest.php`); calendar detection now also checks `features.archive_layouts` for `croppedwithcalendar` as belt-and-suspenders
- `core/admin-header.php` — hardcoded `?v=076a` cache-busting string replaced with dynamic `?v=SNAPSMACK_VERSION_SHORT` on admin CSS; prevents stale styles after updates
- `assets/adminthemes/purple-rain/admin-theme-colours-purple-rain.css` — btn-smack and btn-danger brightness halved (full-brightness magenta/orange on dark admin theme was unreadable)
- `smack-settings.php` — logo and favicon upload handlers removed; upload is now handled exclusively in Global Vibe (`smack-globalvibe.php`) where MIME validation is enforced
- Pre-commit EOF scan run across all 491 failing tracked files; all truncations repaired without losing any uncommitted feature work

---

## 0.7.57 — "Stay Seated" (2026-05-06)

### Fixed
- `core/meta.php` — `require_scripts` loop was only emitting CSS links, never the JS `<script>` tag; engines declared via `require_scripts[]` in skin manifests (including `smack-calendar`) were silently never loaded; calendar has never worked on any site for this reason
- `core/meta.php` — `?v=SNAPSMACK_VERSION_SHORT` cache-busting strings restored on `public-facing.css`, `ss-engine-mosaic.css`, `ss-engine-mosaic.js` (lost when restoring from git in this session)
- `core/meta.php` — engines flagged `admin_page` in manifest-inventory are now skipped in the public require_scripts loop (was harmless before since JS wasn't emitted, now necessary)

---

## 0.7.56 — "Stay Seated" (2026-05-06)

### Changed
- Version bump only — no code changes. Allows updater to detect and pull 0.7.55 changes on existing installs.

---

## 0.7.55 — "Stay Seated" (2026-05-06)

### Added
- `smack-multisite-stats.php` — Fleet Stats now includes the hub's own traffic; hub rows are pulled directly from local `snap_stats_daily`, merged into fleet daily totals, and shown in the network breakdown table with a LOCAL badge; "SITES REPORTING" count includes hub
- `smack-stats.php` — "Exclude Admin: ON/OFF" toggle button on the Traffic Stats page; controls `stats_exclude_admin` setting which gates the existing admin-exclusion logic already in `core/stats-logger.php`

---

## 0.7.54 — "Stay Seated" (2026-05-04)

### Fixed
- `smack-appearance-archive.php` — Calendar is now a proper ENABLE/DISABLE toggle in Archive Appearance instead of a buried dropdown option nobody can find; checking the box sets croppedwithcalendar as default layout; unchecking removes it and falls back to cropped; calendar detail settings (months, side, recent posts count) hide when disabled
- `core/admin-header.php` + `core/meta.php` — dynamic `?v=` cache-busting on all CSS/JS links so Cloudflare serves updated files after each release (was causing old pre-fix styles to show on all sites)

---

## 0.7.53 — "Stay Seated" (2026-05-04)

### Fixed
- `core/multisite-api.php` — Bearer auth now works on nginx/PHP-FPM; `$_SERVER['HTTP_AUTHORIZATION']` falls back to `getallheaders()` so Authorization header is never silently dropped by the server; fixes 401 on spoke→hub VERIFY and hub→spoke heartbeat/ping on all self-hosted Proxmox sites
- `core/admin-header.php` — admin CSS links now use `?v=SNAPSMACK_VERSION_SHORT` for cache-busting instead of a hardcoded stale string; fixes stale Cloudflare-cached admin theme CSS (was causing old pre-0.7.42 orange buttons to show in Purple Rain despite the brightness fix)
- `core/meta.php` — public-facing CSS and JS also get dynamic version cache-busting strings

---

## 0.7.52 — "Stay Seated" (2026-05-06)

### Fixed
- `smack-backup.php` — backup now includes all extended tables (`snap_multisite_nodes`, `snap_multisite_queue`, `snap_hub_shared_bans`, `snap_collections`, `snap_collection_items`, `snap_blogroll_cats`); tables missing on older installs are skipped gracefully rather than hard-erroring
- `smack-multisite.php` — registration token COPY button no longer squeezes the token display to zero width; replaced `btn-smack` (which carries `width:100%`) with a purpose-built inline button style
- `smack-central/assets/css/sc-geometry.css` — lifted `--sc-text-dim` (#555→#888), `--sc-text-label` (#777→#aaa), added `--sc-text-muted` token (#888) for daylight legibility
- `smack-post-solo.php` / `smack-edit.php` — Collections section now always visible; shows "No collections yet" with create link on sites with no collections, instead of hiding the field entirely
- `database/schema/snapsmack_canonical.sql` — fixed `snap_migrations` table definition to match what the updater actually creates (migration as PRIMARY KEY, no id/AUTO_INCREMENT); canonical diff no longer attempts invalid ALTER TABLE on spoke updates

---

## 0.7.51 — "Sit Still" (2026-05-07)

### Added
- smack-post-solo.php: Collections multiselect added to new post form — posts can be assigned to one or more collections at upload time
- smack-edit.php: Collections multiselect added to edit metadata form — pre-populated from existing membership; saved on submit with full delete+repopulate
- smack-manage.php: Collections filter added to Manage Archive filter bar; collection membership displayed in post meta row; collection items correctly cleaned up on single and batch delete

### Fixed
- archive.php: croppedwithcalendar was being stripped from $available_modes when $skin_has_calendar was false (manifest not loading); clicking calendar toggle caused page to blip back to cropped; removed the gate — croppedwithcalendar is now unconditional matching Archive Appearance policy
- archive.php: manifest load changed to relative path (matches smack-skin.php pattern); $skin_has_calendar detection now checks features.archive_layouts as well as require_scripts
- smack-appearance-archive.php: archive thumb border selector now appears on Archive Appearance page — hardcoded fallback renders when active skin manifest pre-dates admin_page=>'archive' flag on archive_frame_style; suppressed automatically once manifest ships the flag
- smack-appearance-archive.php: saving archive_frame_style now regenerates the CSS blob (custom_css_public) immediately; uses comment marker for idempotent surgical replacement; also saves scoped key ({skin}__archive_frame_style) for smack-skin.php consistency
- Version bump to avoid checksum collision with deployed 0.7.50 package

---

## 0.7.50 — "Sit Down" (2026-05-07)

### Fixed
- smack-appearance-archive.php: calendar sidebar settings (months, panel side, recent posts) hardcoded directly — no longer depends on manifest or inventory loading; guaranteed to appear
- smack-appearance-archive.php: croppedwithcalendar unconditionally in layout list and checkboxes; all manifest-based detection removed
- smack-appearance-archive.php: manifest load reverted to relative path (matches smack-skin.php pattern); is_array() guard prevents PHP errors if include fails
- Version bump to avoid checksum collision with deployed 0.7.49 package

---

## 0.7.49 — "Sit Tight" (2026-05-07)

### Fixed
- calendar layout option (croppedwithcalendar) now reliably appears in Archive Appearance: detection changed from require_scripts check to features.archive_layouts check (belt + suspenders with require_scripts fallback); previous method failed when manifest didn't fully load
- Calendar settings (months to show, panel side, recent posts listed) moved from Smooth Your Skin to Archive Appearance — smack-calendar engine now carries admin_page=>'archive' flag in manifest-inventory.php; smack-skin.php skips those controls; smack-appearance-archive.php renders engine controls flagged for 'archive' page
- Version bump to 0.7.49 to avoid checksum collision with already-built 0.7.48 package

---

## 0.7.48 — "Sit Rep" (2026-05-06)

### Fixed
- smack-appearance-archive.php: manifest path changed to __DIR__-based absolute path (CWD ambiguity was preventing $skin_has_calendar detection — calendar layout option now appears correctly)
- smack-appearance-archive.php: archive display options (admin_page=>'archive' in manifest) now render in a new ARCHIVE DISPLAY section here instead of Smooth Your Skin; archive thumb frame relabelled "Thumb Border Selector"
- smack-skin.php: skips manifest options flagged admin_page=>'archive' in UI loop (CSS generation unaffected)
- skins/50-shades-of-noah-grey/manifest.php: archive_frame_style flagged admin_page=>'archive', relabelled Thumb Border Selector
- skins/50-shades-of-noah-grey/archive-layout.php: layout preference localStorage no longer consent-gated (UI preference, not tracking data)
- archive.php: layout persistence script runs unconditionally (was inside $offer_toggle block, so skins with archive-layout.php never persisted visitor layout choice)
- smack-appearance-archive.php: status text colour no longer hardcoded green (#6f6) — falls back to accent colour

---

## 0.7.47 — "Sitting Duck" (2026-05-06)

### Fixed
- archive.php: unified filter panel dropdown 50% wider (min 330px / max 420px)
- Version bump to distinguish from stale 0.7.46 package during update troubleshooting

---

## 0.7.46 — "Wet Toilet Seat" (2026-05-05)

### Added
- smack-globalvibe.php: Footer Configuration, Image Engine, and Floating Gallery sections moved here from smack-settings.php and smack-appearance-archive.php — all appearance/engine settings now live in Global Vibe
- smack-menu.php: 3-level drag-and-drop nav menu builder with container type, active toggle, album/category/collection pool items
- core/header.php: JSON nav renderer (3-level recursive) with flat nav fallback and _snap_nav_resolve_url()
- core/meta.php: injects --nav-dropdown-bg/text CSS vars when nav is configured
- core/footer.php: loads ss-engine-nav-dropdown.js when nav_menu_json is active
- core/sidebar.php: Menu Manager link in Pimp Your Ride
- migrations/049_nav_menu_json.php: seeds nav_menu_json and dropdown appearance settings
- migrations/050_search_placeholder.php: configurable search field label
- All 8 skins style.css: .nav-has-children / .nav-submenu dropdown CSS for 3-level nav
- secaudits/: audit numbering converted from letter suffix (A–H) to 3-digit sequence (001–008); all audits now PDFs

### Fixed
- smack-globalvibe.php: masthead logo upload handler now validates MIME type and extension (was missing finfo_file() check present on other upload handlers — security fix, audit 008)
- smack-update.php: reapply APPLY button now renders correctly on same page load ($stage_state rebind after session update)
- smack-central/sc-release.php: build blocked at preflight if sc-config.php and core/release-pubkey.php keys disagree — key drift can no longer happen silently
- core/release-pubkey.php: updated to correct release public key matching current sc-config.php
- assets/adminthemes/purple-rain/admin-theme-colours-purple-rain.css: btn-smack and btn-danger brightness halved (was blinding magenta/orange)
- assets/js/ss-engine-nav-dropdown.js: fixed openMenu() closing ancestor submenus on mobile (3-level nav fix)
- smack-settings.php: Akismet input width fixed; enctype removed (file uploads moved to globalvibe); NAVIGATION SLOT ASSIGNMENTS box removed

---

## 0.7.45 — "Chaise" (2026-05-05)

### Fixed
- core/release-pubkey.php: corrected release public key (was `4df51e2c...`, must be `b9955f78...` to match private key in sc-config.php) — signature verification was failing on all installs at 0.7.42+
- core/updater.php: corrected hardcoded root public key (was `d4c4256853...`, must be `3287b9b29257...`) — key rotation mechanism was non-functional

### Changed
- smack-menu.php: replaced invented `smack-*` HTML classes with standard admin classes (`main`, `box`, `h3`, `btn-smack`, `dim`, `form-action-row`) so page inherits active admin theme colours automatically
- smack-menu.php: menu builder CSS rewritten with transparent overlays — item cards, drop zones, and depth levels now render correctly on any admin theme

---

## 0.7.44 — "Barstool" (2026-05-05)

### Added
- Nav menu system fully wired end-to-end: Menu Manager in Pimp Your Ride sidebar, migration 049 seeds nav_menu_json and dropdown colour settings
- core/header.php: JSON-driven nav renderer with 3-level recursion and typed URL resolution (custom, external, container, page, album, category, collection); legacy flat nav kept as fallback for unconfigured sites
- Dropdown CSS added to all 8 skins (.nav-has-children / .nav-submenu); dropdown colours injected as CSS vars from admin settings
- ss-engine-nav-dropdown.js: fixed openMenu() so ancestor submenus stay open on mobile (3-level fix)
- ss-engine-menu-builder.js: full rewrite — 3-level drag-and-drop, container item type (dropdown parent with no URL), active/inactive toggle per item, album/category/collection pool
- smack-menu.php: loads albums, categories, collections for pool; container add UI; 3-level hint

### Removed
- smack-settings.php: NAVIGATION SLOT ASSIGNMENTS box (nav_slot_1–4) removed — Menu Manager replaces it
- smack-settings.php: PUBLIC BLOGROLL nav toggle removed — handle via Menu Manager
- smack-appearance-archive.php: FLOATING GALLERY LINK relabelled to ENABLE FLOATING GALLERY with tip pointing to Menu Manager

---

## 0.7.43 — “Ottoman” (2026-05-05)

### Added
- **Configurable archive search field placeholder** (`migrations/050_search_placeholder.php`) — new `search_placeholder` setting in `snap_settings` so each install can label the archive search box independently. Useful for multi-blog domains where one blog wants "Search articles" and another wants "Search photos". Wired into `archive.php` and `skins/photogram/search.php`. Exposed in **Settings → Site Identity & Branding** as **SEARCH FIELD LABEL**. Default: "Search or #tag…".
- **Floating gallery wall enabled on three more skins** — `rational-geo`, `photogram`, `impact-printer` now have `supports_wall: true`. The wall engine is skin-agnostic; each skin gained a `--wall-bg` CSS variable (default `#000000`). Skin versions bumped: rational-geo 1.1→1.2, photogram 1.0→1.1, impact-printer 1.1→1.2. Galleria intentionally excluded — uses its own texture-based wall.
- **Restored `smack-menu.php` and nav engine JS** — `smack-menu.php`, `assets/js/ss-engine-menu-builder.js`, and `assets/js/ss-engine-nav-dropdown.js` were lost from disk during a prior git index corruption incident. Restored from history (commits `d2b1da5`, `6cfdbda`). The Menu Manager UI builds a `nav_menu_json` setting; the public renderer to consume it is deferred to a future release. Existing flat-nav rendering in `core/header.php` is unchanged.

### Changed
- **EOF marker convention upgraded to long-form `===== SNAPSMACK EOF =====`** with `SNAPSMACK_EOF_HEADER` block near the top of every tracked source file. Old `// EOF` short form is retired; `tools/check-eof.py` now requires both header tag and long-form bottom marker. Migration applied via `tools/migrate-eof-marker.py` (one-shot script, retained for reference). Scope extended from PHP/JS/CSS to also include HTML/HTM/MD/SQL/PY/SH. 517 files now carry both sentinels. Rationale: greppability (long form is collision-free), anti-forgery (a partial-write can't accidentally leave a valid short-form marker), self-description (each file's top header names the marker future readers should expect at the bottom — no recall of external rules required). Rationale and per-extension forms documented in `CLAUDE.md`.

### Fixed
- **Migration commit recovery** — Cowork-session changes to `smack-settings.php`, `smack-appearance-archive.php`, `skins/50-shades-of-noah-grey/archive-layout.php`, `skins/rational-geo/archive-layout.php`, and `skins/50-shades-of-noah-grey/manifest.php` (API key UI sizing, dead TILE BORDER & SHADOW box removal, data-driven archive layout toggle, settings-key correction, manifest section move) were stuck behind a stale `.git/index.lock`. Lock cleared, changes committed. No code logic changed in this release relative to those fixes.

---

## 0.7.42 — “Recliner” (2026-05-04)

### Fixed
- **Smack Central CSS** — Added missing CSS classes (`sc-page-head`, `sc-card`, `sc-card-title`, `sc-btn--dim`, `sc-warn`, `sc-muted`, `sc-help-*`, `sc-step-log`) that were used in PHP templates but undefined, causing unstyled layouts across multiple SC pages.
- **Smack Central layout** — Increased `.sc-main` padding and set `max-width: 1400px` for better readability on wide screens.
- **Smack Central font size** — Base font bumped from 13px to 15px.
- **`core/updater.php` literal `\r\n` corruption** — `updater_fetch_key_rotation()` and `updater_cleanup()` were squashed onto a single line with 60 literal `\r\n` sequences instead of real newlines, causing a PHP parse error on any install that extracted the file via the updater. Fixed by replacing all occurrences with actual newlines.
- **IP Smacker tab permanently blank** (`smack-fingerprints.php`) — JS toggled class `tab-content--active` but CSS only defined `.tab-content.active`, so every tab panel stayed `display:none`. Added `tab-content--active` rule to `admin-theme-geometry-master.css`.
- **Archive Cal button missing** (`archive.php`) — `croppedwithcalendar` was silently stripped from available modes by an `array_filter` whitelist that omitted it. Added to whitelist.
- **Archive Appearance save stripping Cal mode** (`smack-appearance-archive.php`) — `array_intersect` on save excluded `croppedwithcalendar`, so the Cal checkbox had no effect. Fixed. Checkbox now only appears when the active skin supports the calendar engine.
- **`smack-help.php` truncated** — Truncated mid-sentence since 0.7.39. Restored from 0.7.29 clean version and updated with new topics: Archive Calendar, Probe Guard, API Key Access, Key Rotation. Existing topics for System Updates, IP Shield, and Applying Updates revised for current behaviour.
- **`install.php` truncated** — r4_exec recovery streaming section truncated since 0.7.39. Tail restored from 0.7.27 clean version; 0.7.39 installer overhaul content preserved.

---

## 0.7.41 — “Recliner” (2026-05-04)

### Added
- **Key rotation infrastructure** — Root-key-signed key rotation system. Installs that encounter a signature mismatch automatically fetch `key-rotation.json` from the release server, verify it against a hardcoded root public key, and present a one-click KEY ROTATION DETECTED panel. No manual key paste required when the release signing key is rotated.
- **Smack Central: Key Rotation panel** (`sc-release.php`) — Generate rotation blob, sign offline with root private key, paste signature, publish. SC verifies the signature against the root key before writing anything to disk.
- **`core/updater.php`** — `updater_fetch_key_rotation()` function, `SNAPSMACK_ROOT_PUBKEY` and `UPDATER_KEY_ROTATION_URL` constants.
- **`smack-update.php`** — `accept_key_rotation` action; repair panel now shows amber KEY ROTATION DETECTED state with pre-filled new key when a valid rotation file is found; falls back to manual paste otherwise.
- **`latest.json`** — Now includes `signing_pubkey` field so the current release public key is always visible in the manifest.

### Fixed
- **Smack Central font and layout** (`sc-geometry.css`, `sc-admin.css`) — Base font increased from 13px to 15px, label and dim sizes bumped proportionally, sidebar widened from 210px to 230px, main content area max-width constraint removed.
- **`core/release-pubkey.php`** — Real Ed25519 public key replacing all-zeros placeholder; signature verification now enforced on all installs receiving 0.7.41+.

---

## 0.7.40 — "Moist Bar Stool" (2026-05-03)

### Added
- **Archive Calendar** (`ss-engine-calendar.js`, `ss-engine-calendar.css`, `api-calendar.php`, `archive.php`) — Archive layout toggle gains a **Cal** option on skins that opt in. Selecting Cal slides a calendar panel in from the right. Shows as many months as fit the viewport height. Click a day to browse that date; click a second day to filter a date range. Colour scheme inherits from the active skin's CSS custom properties. Slides back out when another layout is selected.
- **Date-range archive filter** (`archive.php`) — New `?from=YYYY-MM-DD&to=YYYY-MM-DD` query params filter the archive to a date range. Sanitised and sorted server-side.
- **`api-calendar.php`** — Month count cap raised from 3 to 12 to support dynamic viewport-height-based loading.
- **`skins/50-shades-of-noah-grey/manifest.php`** — Added `smack-calendar` to `require_scripts`; `croppedwithcalendar` added to `archive_layouts`.
- **`skins/rational-geo/manifest.php`** — Same.

### Improved
- **`archive.php`** — `croppedwithcalendar` layout is stripped from the toggle if the active skin does not require the calendar engine, so no orphaned Cal buttons appear on unsupported skins.

---

## 0.7.39 — "Moist Bar Stool" (2026-05-01)

### Fixed
- **`skins/photogram/manifest.php`** — Added `smack-image-fade-load` to `require_scripts`. Images were hidden by the CSS initial-state rule (opacity:0) but the fade-in engine was never loaded, causing black boxes in the archive grid.
- **`skins/kiosk/manifest.php`** — Same `smack-image-fade-load` omission fixed.
- **`smack-update.php`** — Removed auto-advance `setTimeout` that caused the updater to loop through stages without user input. Each stage now waits for manual button click.
- **`archive.php`** — Skin manifests can now declare `features.archive_layout_default` to set a preferred default archive layout without overriding the admin's explicit DB setting. True Grit defaults to masonry.
- **`skins/true-grit/manifest.php`** — `archive_layout_default` set to `masonry` (justified grid).

### Improved
- **`install.php`** — Fresh install schema now driven by `database/schema/snapsmack_canonical.sql` directly; table prefix hardcoded to `snap_`; all numbered migrations auto-stamped via directory scan. Installer is now self-maintaining — no manual DDL or migration list to update.
- **`install.php`** — Missing settings seeded on fresh install: `archive_layout`, `archive_layouts_available`, `privacy_policy_*`, `tool_api_key` (auto-generated).
- **`database/schema/snapsmack_canonical.sql`** — Removed semicolons from column `COMMENT` strings that broke statement splitting.
- **`CLAUDE.md`** — Documented VM shell write prohibition (root cause of repeated file truncation).

## 0.7.38 — "Moist Bar Stool" (2026-05-01)

### Fixed
- **Footer SNAPSMACK link unstyled** (`core/footer.php`) — The powered-by SnapSmack link was missing `class="footer-link"`, the class every other footer link has. All skins style hover colour via that class. Bare `<a>` got browser-default styling.

---

## 0.7.37 — "Moist Bar Stool" (2026-05-01)

### Added
- **Probe Guard** (`probe-ban.php` + `.htaccess`) — Requests to known scanner paths (wp-login.php, xmlrpc.php, .env probes, shell uploads, phpmyadmin, etc.) are routed via RewriteRule to a PHP ban handler that records a 30-day ban in `snap_ip_bans` and returns a 403. Banning is automatic with no admin involvement required.

### Fixed
- **`smack-update.php` truncation** — File was truncated mid-content due to null byte corruption. Stripped nulls, completed the schema_changes warning block, Apply Update button, up-to-date fallback, and `admin-footer.php` include.
- **`SNAPSMACK_SIGNING_ENFORCED` undefined fatal** — Constant was referenced in `smack-update.php` and `core/updater.php` but never defined anywhere. Added definition to `core/updater.php` (derives from pubkey: enforced when real key present, advisory when placeholder). Guarded the display line in `smack-update.php` with `defined()` for safety on old installs.
- **`snap-in.php` passphrase nudge removed** — Passphrase suggester had no business on the login page. Removed the nudge block and `smack-passphrase.js` load. Belongs on change-password only.
- **Login tab panels both visible** — `.tab-content` had no CSS hide/show rules, causing both the PASSWORD and RECOVERY CODE panels to render simultaneously. Added `display:none` / `.active { display:block }` to `admin-theme-geometry-master.css`.
- **Featured image picker empty** (`smack-cats.php`, `smack-albums.php`, `smack-collections.php`) — Picker AJAX queried `snap_posts` which is empty on legacy/image-only installs. Switched all three to query `snap_images` directly (img_title for search, img_thumb_square for preview). Display fetch queries updated to match. The `featured_post_id` column now stores `snap_images.id`.
- **`smack-cats.php` 500 on new category** — INSERT was missing `cat_slug` which has no default on the column. Slug is now generated from the category name before insert.

---

## 0.7.36 — "Perch" (2026-05-01)

### Added
- **Tool API key authentication** (`core/api-auth.php`) — Companion tools (SYBU, etc.) can now authenticate with a 64-char hex key sent as the `X-Snap-Key` request header instead of maintaining a login session. Dual-auth: endpoints accept either a valid API key or a browser session cookie, so admin UI access is unchanged.
- **API Access settings UI** — Admin → Settings → API Access section: generate, copy, regenerate, and revoke the tool API key.
- **Migration 046** — Seeds `tool_api_key` (empty) into `snap_settings`.
- **`smack-audit.php`, `smack-backfill.php`, `sybu-data.php`, `smack-post-solo.php`** — Switched from `core/auth.php` to `core/api-auth.php` for dual auth support.

---

## 0.7.35 — "Perch" (2026-05-01)

### Fixed
- **`core/release-pubkey.php` missing** — `core/updater.php` hard-required this file, which was never committed to the repo or included in release packages. Any server that received the 0.7.27–0.7.34 updater code via an in-admin update would immediately 500 on `smack-update.php` after the update completed, because the new `updater.php` requires a file that was never deployed. Added `core/release-pubkey.php` with a placeholder all-zeros key (disables Ed25519 signature verification, falls back to SHA-256 checksum only). Made the `require_once` in `updater.php` defensive so a missing file no longer fatals.

---

## 0.7.34 — "Perch" (2026-05-01)

### Fixed
- **Removed updater modal** — `ss-engine-updater.js` and its modal were scope creep from a request that only needed a dismiss button on the update notification banner. The modal conflicted with the admin theme and added unnecessary complexity. Removed from `core/admin-footer.php` and `core/admin-header.php`. The `smack-update.php` page handles updates directly as it always did.
- **Update banner: DISMISS button added** — Dashboard update notification now has a DISMISS link alongside VIEW UPDATES. Clicking it sets a session flag and hides the banner for the rest of the session without navigating away.
- **Removed modal auto-open trigger** from `smack-update.php`.

---

## 0.7.33 — "Perch" (2026-05-01)

### Fixed
- **Engine CSS moved to `<head>`** — All skin `skin-footer.php` files were outputting engine `<link>` stylesheet tags at the bottom of `<body>`. CSS in the body is invalid HTML and causes browsers to re-render mid-paint, producing visible layout jumps on every page load and page switch. Engine CSS is now output by `core/meta.php` in the `<head>` (reads the skin manifest and outputs only CSS links); engine JS remains in the footer for performance. All 12 skin footers updated.
- **Image fade-in flash fixed** — `ss-engine-image-fade-load.js` was setting `opacity: 0` at runtime (end of body), creating a race condition where the browser partially painted images before the JS ran. Initial `opacity: 0` and `transition` now set in `public-facing.css` so images are invisible before first paint. JS handles only the transition to `opacity: 1` on load.

---

## 0.7.32 — "Perch" (2026-05-01)

### Fixed
- **Updater modal UI** — Multiple accumulated bugs: (1) admin theme's bare `button` rule (100% width, 52px height, 30px margin-top) was overriding all buttons inside the modal, turning the `×` close button into a full-width pink rectangle; (2) JS generated single-dash class names (`su-btn-primary`, `su-btn-secondary`, `su-btn-danger`) but CSS defined double-dash equivalents (`su-btn--primary` etc.) so button colours never applied; (3) `su-footer-btns` wrapper div had no CSS, causing CANCEL/APPLY buttons to stack vertically; (4) `su-uptodate-icon` class undefined in CSS (CSS had `su-big-icon`); (5) header `<span>` missing `su-title` class so title was unstyled. All fixed: admin theme isolation block added using ID specificity, missing classes added, class mismatches resolved.

---

## 0.7.31 — "Perch" (2026-05-01)

### Fixed
- **FOUC / layout shift on every page load** — `core/meta.php` and all skin `skin-footer.php` / `skin-meta.php` files were using `time()` as the CSS/JS cache buster, which generates a unique URL on every request and completely defeats browser caching. Every page load forced a fresh download of the skin stylesheet, variant stylesheet, and all engine CSS/JS files, causing visible reflow and font swap. Changed to `SNAPSMACK_VERSION_SHORT` — assets are now cached across page loads and only re-fetched when a new version is deployed.

---

## 0.7.30 — "Perch" (2026-05-01)

### Fixed
- **`core/parser.php` — `parseMosaics()` fatal error** — Method was called at line 100 but missing from the live server's copy of the file, causing a fatal error on all pages that parse post content (single photo view, static pages). Method stub is now present and passes content through unchanged pending full mosaic implementation.
- **`skins/50-shades-of-noah-grey` — keyboard shortcuts missing on photo/page views** — `smack-keyboard` was not listed in the skin's `require_scripts`, so F1 (help menu), `1` (toggle info), and `2` (toggle comments) only worked on archive (where the justified engine loads the comms script as a side effect). Added to manifest; shortcuts now load on all page types.

---

## 0.7.29 — "Lock-Off" (2026-04-29)

### Added
- **Login brute-force protection** — `snap-in.php` now tracks failed login attempts per IP in the existing `snap_rate_limits` table. Five failures within a 10-minute window triggers an automatic 7-day IP ban stored in the new `snap_ip_bans` table. Migration `045_login_protection.php` creates the table idempotently.
- **User-Agent filter at login** — Blank UAs and known scripted clients (curl, python-requests, Wget, sqlmap, Hydra, etc.) receive a silent 403 before any login logic runs.
- **IP Shield tab in Troll Control** — `smack-fingerprints.php` exposes the `snap_ip_bans` table via a new IP Shield tab: lists active bans with expiry, supports manual lift. New AJAX handlers: `fetch_ip_bans`, `lift_ip_ban`.
- **Admin settings hover tooltips** — All field descriptions across admin pages converted from visible `class="dim"` inline text to `class="field-tip"` hover icons (ⓘ). Eliminates uneven field spacing throughout the admin. CSS rule added to `admin-theme-geometry-master.css`.
- **`snap_ip_bans` table** — New canonical schema entry and migration `045`.
- **`tools/smackattack-scanner/`** — GOBSMACKED Scanner v0.1.0: local Python/tkinter desktop tool for the admin to run stylometric scans directly against the SnapSmack MySQL database. 25-dimension vector engine is an exact port of `core/ste-style.php`. Peer comparison, banned-profile comparison, results stored in `snap_gobsmacked_scan`, mark-reviewed and upload-to-hub actions. Ships as a single-file exe via PyInstaller (`build.bat`).
- **snapsmack.ca** — Two new WOTCHA articles: dedicated SMACKATTACK network explainer (Apr 22) and login security changes overview (Apr 29).

### Fixed
- **`snap-in.php` truncation** — Login page HTML was truncated mid-CSS block; reconstructed with complete form, tab UI, passphrase nudge, and JS.
- **Passphrase nudge CSS** — Moved from inline `<style>` block in `snap-in.php` to `admin-theme-geometry-master.css` (compliant with no-inline-style rule).
- **`smack-2fa-verify.php` truncation** — File was missing `>\n</html>` at EOF; repaired.

---

## 0.7.28 — "Lock-Off" (2026-04-28)

### Changed
- Version bump. Codename: Lock-Off (the locking mechanism on a child car seat — keeps things exactly where you put them).

---

## 0.7.27 — "Lawn Chair" (2026-04-28)

### Added
- **XHR-driven update modal** — Updates now run in a modal overlay without page navigation. Triggered from the dashboard banner, sidebar System Updates link, or the Updates page directly. Five-stage progress bar (Download → Verify → Backup → Extract → Migrate), live log, changelog review before applying, rollback button on failure. Full-page HTML fallback retained for non-JS environments. New `assets/js/ss-engine-updater.js` and `assets/css/ss-engine-updater.css`.
- **Custom login slug + bot protection** — Login page renamed from `login.php` to `snap-in.php`. Direct `.php` URL returns 403; named route `/snap-in` serves the page. Pre-shared token recovery path: `snap-in.php?key=TOKEN` redirects to the configured login slug so you're never locked out. Migration `044_login_slug.php` seeds `login_slug` and `login_recovery_key` in `snap_settings`.
- **Passphrase generator** — Login and change-password pages include a six-word passphrase generator (`assets/js/smack-passphrase.js`). "Generate & Fill" populates the password field; "Just show me one" displays a phrase without filling. Nudges users away from symbol-scrambled passwords.

### Fixed
- **`db.php` permissions on shared hosting** — Installer now sets `core/db.php` to `0644` instead of `0640`. Fixes "Permission denied" on servers where PHP runs as a different user than the FTP/deploy user.
- **Fresh install schema gaps** — `snap_users` CREATE TABLE in `install.php` was missing five columns added via migrations (`recovery_code_hash`, `force_password_change`, `totp_secret`, `totp_enabled`, `totp_recovery_json`). All columns now present in the initial schema.
- **Schema patcher** — `install.php?action=patch_schema` runs idempotent ALTER TABLE migrations for the missing columns. Safe to run against any existing install.

---

## 0.7.26 — "Lawn Chair" (2026-04-26)

### Added
- **SmackTalk 3.0 edition in installer** — The edition chooser (step 1b) now includes SmackTalk 3.0 alongside SmackOneOut and Carousel. Selecting it seeds `enable_longform = 1` so longform posting is active from first login with no admin configuration required.
- **Cloudflare Tunnel HTTPS detection** — `snap_is_https()` helper added to `core/constants.php`; checks `$_SERVER['HTTPS']`, `HTTP_X_FORWARDED_PROTO`, and `HTTP_X_FORWARDED_SSL`. Replaces bare `$_SERVER['HTTPS']` checks throughout the codebase. Installs behind Cloudflare Tunnel or any reverse proxy now correctly detect HTTPS, set secure cookies, and write correct base URLs.
- **Release packager security hardening** — `secaudits/`, `migrations/`, `database/`, `data/`, `.well-known/`, and internal maintenance scripts excluded from install packages. Galleria and Rational Geo added to the base release package (previously gallery-only).

### Fixed
- **`.htaccess` HTTPS redirect** — redirect rule was commented out entirely; now active with dual-condition check (`HTTP:X-Forwarded-Proto` + `HTTPS`) to avoid redirect loops on both direct HTTPS and Cloudflare-proxied installs.
- **Installer multi-step display** — DB-confirmed and admin account form were rendering on the same screen. DB confirm step now hands off cleanly to a separate screen before presenting the admin form.
- **`setup.php` installer** — file was truncated in git (missing Install button, closing form, and HTML); restored from history. Extraction now processes files individually, skipping `setup.php` itself to avoid PHP overwriting a running script.
- **Release packager changelog auto-fill** — `sc-release.php` was truncated in git (missing closing `</script>` tag and `require sc-layout-bottom.php`); browser never parsed or executed the changelog JS. Restored from history.
- **Oh Snap shell permission** — `tools/oh-snap/src-tauri/capabilities/default.json` updated `shell:open` → `shell:allow-open` for Tauri 2 compatibility.

---

## 0.7.26 — "Lawn Chair" (2026-04-26)

### Fixed
- **install.php fresh-install schema** — `snap_users` CREATE TABLE was missing `recovery_code_hash`, `force_password_change`, `totp_secret`, `totp_enabled`, and `totp_recovery_json` columns added in post-0.7.9g migrations. Fresh installs would fail at login with `Unknown column 'force_password_change'`.
- **install.php db.php permissions** — `core/db.php` was set to `0640` after write, breaking PHP access on any server where the FTP user and web server user differ. Changed to `0644` (three instances).

### Added
- **install.php schema patcher** — The "already installed" wall now offers a **Patch Schema** button (`?action=patch_schema`) alongside Recovery Mode. Runs `ALTER TABLE … ADD COLUMN IF NOT EXISTS` for any columns missing from older installs. Safe to run on any version, does not touch existing data.

---

## 0.7.25 — "Lawn Chair" (2026-04-25)

### Fixed
- **Reapply Current Version looping** — clicking APPLY after reapply kept returning to the review screen because `stage_download` only read from the cached update notification, not the session data set by the reapply action. Now falls back to session update data so reapply works without a pending update notification.

---

## 0.7.24 — "Lawn Chair" (2026-04-25)

### Added
- **SmackTalk mode toggle in Settings** — "New Longform Post" now only appears in the sidebar when SmackTalk mode is explicitly enabled. Photo-only (SmackOneOut) installs no longer show the longform editor link. Migration 043 seeds `enable_longform = 0` on existing installs.

### Changed
- **Smack the Enemy renamed to SMACKATTACK** — all user-facing labels, headings, help topics, settings section, and API response messages updated. Internal code, file names, and database tables (`sc-enemy-*`, `ste_*`) unchanged.
- **Packager changelog auto-fill fixed** — the packager JS now fetches CHANGELOG.md via a server-side PHP proxy that resolves the tag to a commit SHA before fetching, bypassing GitHub CDN tag-ref caching that caused the field to show empty after a force-push.

### Fixed
- **Dashboard "Apply Update" button broken** — `cron-version-check.php` and the `smack-admin.php` fallback on-load check both stored a partial `core_update` blob that omitted `download_url`, `checksum_sha256`, and `signature`. Clicking Apply Update from the dashboard always produced "NO DOWNLOAD URL — RUN CHECK FOR UPDATES AGAIN." Both now store the full field set so the cached result can drive a complete update without requiring a manual re-check.

---

## 0.7.23 — "Couch Potato" (2026-04-25)

### Security
- **Email header injection fixed in `core/contact-form.php`** — `$name` was interpolated directly into the mail subject and `$email` into `From:` / `Reply-To:` headers with no CRLF stripping. A crafted name containing `\r\n` could inject arbitrary mail headers enabling spam relay. Both inputs now stripped of CRLF sequences before use.
- **Race condition fixed in `smack-central/sc-enemy-api.php` rate limiter** — file-based rate limiting used no locking; concurrent requests all read the stale count before any write completed, allowing limit bypass. `ste_rate_limit()` now uses `flock(LOCK_EX)` for atomic read-increment-write.
- **Weak temp file randomness fixed in `smack-central/sc-release.php`** — `rand(1000, 9999)` replaced with `bin2hex(random_bytes(16))` for unpredictable temp filenames.

---

## 0.7.22 — "Couch Potato" (2026-04-25)

### Security
- **Open redirect fixed in `community-auth.php`.** Two redirect paths accepted arbitrary URLs — a logged-in check (line 30) passed `$_GET['redirect']` directly to `Location:`, and the login POST handler (line 182) used `FILTER_VALIDATE_URL` which accepts any valid URL including external ones. Both now pass through `community_safe_redirect()` which only allows relative paths starting with a single `/`.
- **Logo upload validation hardened in `smack-settings.php`.** Logo upload previously accepted any file extension with no MIME check — upload `logo.php`, get RCE. Now enforces a whitelist of image extensions (`jpg`, `jpeg`, `png`, `gif`, `svg`, `webp`) and validates the actual MIME type via `finfo`. Favicon upload already had an extension whitelist; MIME validation added there too.
- **Path traversal closed in `smack-edit.php`.** The skin manifest `edit_page` value was concatenated into an include path without validation. Skin slug and `edit_page` values are now validated against a safe slug pattern (`/^[a-z0-9][a-z0-9\-]*$/`) before any path construction.
- **Session fixation fixed in 2FA login flow (`login.php`).** `session_regenerate_id(true)` was called after full session grant (no-2FA path) but NOT before planting `totp_pending_user_id` on the 2FA path. An attacker who pre-seeded a known session ID could observe the pending user ID. Fixed by calling `session_regenerate_id(true)` before writing the pending state.
- **Rate limiting added to admin password reset (`password-reset.php`).** No rate limit existed on the admin reset form — an attacker could flood a target's inbox with reset emails. Max 5 requests per IP per hour using the existing `snap_rate_limits` table.
- **Slug validation hardened.** `smack-post-solo.php` slug generation now collapses consecutive hyphens and strips leading/trailing hyphens, with an `untitled` fallback for all-special-char titles. `smack-post-long.php` now passes user-supplied slugs through `long_slugify()` for normalisation.
- **DB error message suppressed in installer (`install.php`).** The catch-all connection failure branch was returning the raw MySQL exception message. Replaced with a generic error that doesn't leak server internals.
- **Security headers added site-wide (`core/constants.php`).** `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and `Referrer-Policy: strict-origin-when-cross-origin` are now sent on every request. CLI execution and already-sent-headers cases are skipped. Full CSP is deferred — too complex to implement correctly with skin-loaded external fonts and scripts.

---

## 0.7.21 — "Couch Potato" (2026-04-25)

### Added
- **Privacy Policy page** (`smack-privacy.php`, `privacy-policy.php`). Admin page in The Good Shit sidebar lets blog owners write and enable a public-facing privacy policy. When enabled, a link appears in the public footer. Content stored in `snap_settings`. Renders inside the active skin at `/privacy-policy.php`. Particularly relevant for installs participating in SMACK THE ENEMY or GOBSMACKED — the help topic lists what to disclose.
- **Security audit published** (`secaudits/`). Full audit findings committed to the repository. Security through obscurity is a non-starter with open source — publishing the audit is evidence that the work is being done.
- **Head scripts moved from DB to filesystem** (`data/custom-head.html`). File-based storage means SMACKBACK can watch for unauthorized changes. A DB injection attack cannot alter file-based content. `data/.htaccess` blocks direct web access. Existing installs fall back to the DB value until admin re-saves in smack-scripts.php.
- **`setup.php` rewritten — signed release installer.** Fetches `latest.json` from `snapsmack.ca/releases/`, downloads the signed release package, verifies SHA-256 checksum and Ed25519 signature before extracting. Zip entry path traversal validation added. Sodium fallback to checksum-only if extension unavailable. Git clone path removed.
- **SMACKBACK help topic** added to `smack-help.php`.
- **GOBSMACKED help topic** added to `smack-help.php`.
- **Privacy Policy help topic** added to `smack-help.php`.

---

## 0.7.20 — "Couch Potato" (2026-04-24)

### Added
- **Head scripts moved from DB to filesystem** (`data/custom-head.html`). File-based storage means SMACKBACK can watch for unauthorized changes — a DB injection attack cannot alter file-based content. `data/.htaccess` blocks direct web access. Existing installs fall back to the DB value until admin re-saves in smack-scripts.php, at which point the DB entry is cleared and the file becomes the single source of truth.
- **`setup.php` rewritten — signed release installer.** The bootstrap deployer no longer pulls raw source from GitHub. It now fetches `latest.json` from `snapsmack.ca/releases/`, downloads the signed release package, verifies the SHA-256 checksum and Ed25519 signature before extracting anything, and aborts if either check fails. Zip entry path traversal validation added. Falls back gracefully to checksum-only if the sodium extension is unavailable. Git clone path removed entirely.
- **SMACKBACK help topic** added to `smack-help.php`. SMACKBACK is the file integrity monitoring feature — automated sentinel software shipping in every install. Topic covers tamper detection, the BREACH skin response, hub/spoke escalation, and residual risks.
- **GOBSMACKED help topic** added to `smack-help.php`.
- **MOSAIC engine restored.** Row-based bin-packing layout engine for justified image panels inside post body content. `[mosaic:ID]` shortcode renders via `ss-engine-mosaic.js`. Parser Phase 8 calls `parseMosaics()`. Engine registered in manifest inventory. Admin builder at `smack-mosaics.php` (pimpmobile). Migration 038 creates `snap_mosaics` table.
- **Featured images for categories, albums, and collections.** Each container now has a `featured_post_id` column pointing to any published post whose first image is used as the representative hero thumbnail in gallery and collection views. Picker modal shared across `smack-cats.php`, `smack-albums.php`, and `smack-collections.php`. Migration 039.
- **Collections** (`smack-collections.php`, pimpmobile). Heterogeneous containers that hold posts, albums, and categories in any combination. Membership is live — member albums/categories resolve to their current posts at render time. Drag-to-reorder member list, AJAX add/remove. Two new tables: `snap_collections` and `snap_collection_items`. Migration 040.
- **SmackTalk longform post editor** (`smack-post-long.php`, pimpmobile). Writing-forward post type with full shortcode toolbar, MOSAIC insert button (opens picker modal), hero image from the media library, categories/albums/tags, publish/draft, timestamp override. Saves to `snap_posts` with `post_type = 'longform'`. New junction tables `snap_post_cat_map` and `snap_post_album_map` for direct post → category/album associations. `featured_asset_id` column on `snap_posts` for hero selection from the asset library. Migration 041.

---

## 0.7.19 — "Couch Potato" (2026-04-23)

### Added
- **snapsmack.ca** — TWIG N BERRIES! privacy policy page (`tnb.html`) added across all site navigation. Nav link text resized to 0.8rem site-wide to prevent overflow.

---

## 0.7.18 — "Bench Warmer" (2026-04-23)

### Added
- **GOBSMACKED — stylometric writing fingerprints (Shield Tier 3).** Detects ban evasion by writing style when IP, email, and browser fingerprint rotation would otherwise defeat the network.
  - `core/ste-style.php` (new) — extracts a 25-dimension writing style vector from a commenter's comment history at ban time. Features: function word frequencies, punctuation rates, TTR, capitalisation habits, contraction use, sentence length statistics. Raw comment text never leaves the installing server — only the numeric vector is transmitted.
  - `core/ban-check.php` — `add_ban()` now calls `ste_style_extract()` on the banned commenter's comment history and transmits the vector alongside the ban report. Added `_ste_fetch_comment_texts()` helper.
  - `core/ste-client.php` — `ste_client_report()` accepts an optional `$style_vector` parameter and includes it in the API payload when valid.
  - `smack-central/sc-enemy-api.php` — report handler receives and stores style vectors into `ste_style_vectors`, with opportunistic cleanup of expired rows.
  - `smack-central/schemas/sc-enemy-canonical.sql` — `ste_style_vectors` table added (fingerprint_id + site_id unique key, JSON vector, 365-day retention via `expires_at`).
  - `smack-central/sc-enemy-admin.php` — GOBSMACKED tab: run cosine-similarity clustering across stored style vectors, display matched fingerprint clusters with confidence badges (POSSIBLE / LIKELY / STRONG MATCH), escalate all cluster members to a higher colour level, or dismiss false-positive pairs. Admin page subtitle updated to Shield Tier 3.
- **Privacy policy** (`projects/snapsmack-ca/tnb.html`, new) — plain-language policy covering the self-hosted data model, STE network visibility, GOBSMACKED data (what is extracted, what is transmitted, 1-year retention), forum participant visibility. Blog owners who enable STE are directed to disclose GOBSMACKED collection to their own visitors. TWIG N BERRIES! nav link added site-wide.

### Fixed
- **`smack-central/sc-schema.php`** — Removed `IF NOT EXISTS` from `ADD COLUMN` DDL. MySQL 5.7 does not support `IF NOT EXISTS` on `ALTER TABLE ... ADD COLUMN`; this caused schema-sync to fail silently on the live server.
- **`smack-central/sc-update.php`** — Added `sc-db.php` to the `$protected` file list so the SC self-updater never overwrites it. Previously, running the updater before pushing the latest tag replaced the live `sc-db.php` with the version from the last published release, removing `sc_enemy_db()` and `sc_forum_db()`.
- **`smack-central/sc-layout-top.php`** — Removed skull emoji from the Smack the Enemy nav link.

### Companion Tools
- **SYBU 0.7.9c** — Advanced Visual Match tab: two-stage pHash + SIFT image matching ported from Fix Your Batch Up. Pick a server folder and originals folder, run matching, review side-by-side confidence-scored results, upload confirmed originals to Drive. Uses credentials already in Settings — no separate entry required.

---

## 0.7.17 — "Hot Seat" (2026-04-23)

### Changed
- **Versioning scheme.** Retired the letter-suffix format (`0.7.9P`) in favour of standard three-part numeric semver (`0.7.17`, `0.7.18`, …). Milestone map: `0.7.x` = Alpha, `0.8.x` = Closed Beta, `0.9.x` = Open Beta, `1.0` = Stable. `snap_version_compare()` in `core/constants.php` retains backward-compatibility with legacy letter-suffix version strings from older installs.
- **Smack Central release packager** (`smack-central/sc-release.php`) — tag list now filters to new-format semver tags only (`vX.Y.Z`). Old letter-suffix tags and companion-tool tags (`vSYBU-*`) are excluded. Dropdown and history table show the three most recent releases only.

### Added
- **Smack Central forum — PGSB redesign** (`smack-central/sc-forum.php`). Full rebuild of the hub forum interface.
  - Forum is now the primary full-page experience. The three-tab layout (Forum / Installs / Manage Boards) is replaced by a PGSB identity bar: avatar, gold PGSB badge, and "Pan Galactic Straw Boss" label on the left; Installs and Boards links as secondary nav on the right.
  - Hub posts (from `snapsmack.ca` install) are badged as PGSB throughout — in the thread list, thread title, and each post in the stream. Posts from PGSB get a distinct gold left-border tint (`scf-post--pgsb`).
  - Mod controls are contextual and always visible: Pin / Lock / Delete in the thread title bar; Delete / Restore per reply in each post header. No hunting.
  - PGSB composer shows the hub avatar, PGSB badge, and "Reply as Pan Galactic Straw Boss" label. Post button reads "Post as PGSB". Locked threads show an inline notice with a reminder that you can unlock from the controls above.
  - `PGSB_DISPLAY_NAME`, `PGSB_SHORT`, `PGSB_DOMAIN` constants defined at the top of the file — one place to change the hub identity.
  - Hub install row in the Installs section is identified with the PGSB badge; rename/ban/promote controls suppressed for it.
- **`assets/js/smack-sc-forum.js`** (new file) — emoji insertion and inline install rename JS extracted from inline `<script>` blocks into a proper file per architecture rules.

### snapsmack.ca
- **Three Ways to Play section** added between Working Right Now and Pick a Colour. Explains the three install modes (SMACKONEOUT, GRAMOFSMACK, SMACKTALK) with individual mode cards, mode numbers, and Coming Beta badge on SMACKTALK.
- **GRAMOFSMACK copy** updated: tagline "Got Zuck-fucked?", carousel/grid copy refreshed to emphasise power tools and ownership.
- **Coming Next** — SMACKTALK and MOSAIC split into separate cards. SMACKTALK focuses on the writing-and-images blogging identity; MOSAIC describes the inline panel layout engine as its own distinct feature.
- Version badge updated to Alpha 0.7.17 throughout.

---

## 0.7.9P — "Spam Blocker" (2026-04-22)

### Added
- **TOTP Two-Factor Authentication.** Full RFC 6238 2FA with no library dependencies.
  - `core/totp.php` — Pure PHP TOTP implementation: Base32 secret generation, RFC 6238 code generation with dynamic truncation, ±1 step verification window, `hash_equals()` for timing safety, 8-code bcrypt-hashed recovery code system, `otpauth://` URI builder, Google Charts QR helper.
  - `smack-2fa.php` — Setup/manage page. Three states: inactive (generate), pending (scan QR + confirm), active (disable or regenerate recovery codes). All sensitive actions require a live TOTP code to confirm.
  - `smack-2fa-verify.php` — Login interstitial. Shown after password accepted when 2FA is active. Accepts live TOTP code or one-time recovery code. Limits to 5 failed attempts before expiring the pending session.
  - `migrations/037_totp_2fa.php` — Adds `totp_secret`, `totp_enabled`, `totp_recovery_json` columns to `snap_users`.
  - `login.php` — 2FA gate wired in. Password success with `totp_enabled` → pending session → verify page instead of direct session grant.
  - `core/sidebar.php` — Two-Factor Auth link added under User Manager (Pimpmobile mode).
  - `smack-help.php` — Full 2FA help topic covering setup, recovery codes, login flow, and disable.
  - `assets/js/smack-login.js` — Shared tab-switcher JS for login.php and smack-2fa-verify.php.
  - `assets/js/smack-admin-2fa.js` — Recovery code copy-to-clipboard JS (reads from DOM, no PHP/JS coupling).
  - `assets/css/admin-theme-geometry-master.css` — Login tab strip, recovery code grid, 2FA status badge, and QR layout classes. Applies to login.php and 2FA pages.
- **Session security hardening.** `session_regenerate_id(true)` now called at every authentication completion point: password login, account recovery code login, and TOTP/2FA recovery code verification. Prevents session fixation.

---

## 0.7.9P — "Spam Blocker" (2026-04-20)

### Added
- **Require Download URL setting** — new toggle in Admin → Settings → Downloads. When enabled, posts cannot be published without a download URL. The previous implementation (in `smack-appearance-solo.php`) only appeared on spoke installs and only validated when the Allow Download toggle was also checked — meaning the SYBU batch poster could post without a Drive link even with the setting on. Both bugs fixed: setting is now in `smack-settings.php` for hub installs, and validation checks `img_status = published` + empty `download_url` regardless of the `allow_download` flag.
- **`smack-audit.php`** (new file) — authenticated JSON endpoint for the SYBU Audit & Repair tool. Three actions: `GET ?action=summary` returns post count, missing Drive link count, and duplicate title group stats; `GET ?action=list` returns all published posts with id, title, date, and download URL; `POST action=update_title` updates a post's title by ID.

### Fixed / Restored
- **Akismet spam filter restored.** `core/spam-check.php` was functional but the admin UI to configure it had been dropped during a settings page rebuild. The Akismet API Key field is now back in Admin → Settings → GLOBAL COMMENTS (Architecture & Interaction box). Includes a **TEST KEY** button that hits Akismet's `verify-key` endpoint via AJAX and shows an inline ✓/✗ result without a page reload.
- **`spam-check.php` hardcoded blog URL removed** — was hardcoded to `baddaywithacamera.ca`. Now reads `site_url` from `snap_settings` with a fallback to `$_SERVER['HTTP_HOST']`. Never hardcode.
- **`spam-check.php` upgraded to HTTPS curl** — was using `fsockopen` on port 80, which is deprecated and blocked by many shared hosts. Now uses `curl` over HTTPS to `https://{key}.rest.akismet.com/1.1/comment-check`. Timeout: 5 seconds. User-agent: `SnapSmack/{SNAPSMACK_VERSION}`.
- **`smack-help.php` SYBU section** — removed stale reference to Fix Your Batch Up ("use FYBU to recover links"). Now correctly describes the Repair tab in Smack Your Batch Up. Added help topics for all three Repair actions (Rename Drive Files, Re-enrich Duplicate Titles, Backfill Missing Drive Links).

### snapsmack.ca
- **Anti-spam section updated** — layer names, all body copy, and lede rewritten per brand refresh. New names: SMACK DAB (was Troll Control), SMACK DOWN (was SnapSmack Shield), SMACK UP (was Smack The Enemy). Layer 1 copy now mentions Akismet explicitly. Layer 2 copy mentions shared Akismet key managed centrally via hub. Section background lightened to `#2e2e2e` to separate it visually from the themes section below.
- **SYBU section updated** — status card and tool copy updated to describe the Audit and Repair tabs added in SYBU 0.7.9b. Fix Your Batch Up references removed.

---

## 0.7.9O — "Network Effects" (2026-04-21)

### Added
- **SnapSmack Shield Tier 1 — hub/spoke ban hash sharing.** When enabled, banning a troll on any site in a multisite installation automatically propagates that ban to every other site. Only SHA-256 hashes are exchanged — no raw IPs, emails, or identifying information ever leaves a site.
  - **Hub shared ban registry** (`snap_hub_shared_bans` table) — central store of consolidated cross-spoke ban hashes. Tracks ban type, hash, first/last seen, report count (number of distinct spokes reporting the same identifier), and a soft-delete flag for false-positive removal.
  - **Spoke-side `POST multisite/ban-sync` endpoint** in `core/multisite-api.php` — validates caller is hub, merges incoming consolidated bans (with `hub-sync:` reason prefix to prevent echo-back on next cycle), collects new local bans since last cursor, advances cursor, and returns new bans to hub.
  - **Hub-side ban sync sweep** in `smack-multisite.php` — after each successful heartbeat, hub calls spoke's `ban-sync` endpoint, ingests new bans into `snap_hub_shared_bans` (ON DUPLICATE KEY: bumps report_count + refreshes last_seen), advances `ban_sync_cursor` per node. Fully delta-synced. Non-fatal: older spokes that 404 are silently skipped and retried next sweep.
  - **Shared Bans tab** in `smack-fingerprints.php` (hub only) — paginated registry view. Shows hash, type, reason, reporting spoke hostname, report count (colour-coded amber at 3+, red at 5+), last seen date. Clear button soft-deletes from distribution while preserving audit row.
  - **Shield section** in `smack-community-settings.php` (hub only) — opt-in toggle for `hub_spoke_ban_sync`, per-spoke last sync timestamp table, shared ban count with link to registry.
  - **Migrations:** 033 creates `snap_hub_shared_bans` and adds `ban_sync_cursor` column to `snap_multisite_nodes`; 034 seeds `ban_hub_last_sync_at` and `ban_sync_capable_spokes` settings.
  - **Help topic:** Shield — Hub/Spoke Ban Sync (under Boring Ass Stuff).
  - **Spec document:** `tools/_specs/snapsmack-shield-spec.docx` — full architectural spec for Shield Tier 1 (hub/spoke) and Tier 2 (future network-wide registry).
- **Big Wheel / Pimpmobile admin UI modes.** New users start in Big Wheel (simplified) mode — only the essentials in the sidebar so publishing is front and centre. The full admin (Pimpmobile) unlocks automatically via an offer card on the dashboard at 100 published posts. Offer cadence: every 100 posts; after 3 declines, every 200 posts; after the 2nd decline, a "Leave Me Alone" option appears to suppress the offer permanently. Manual toggle available at the bottom of the sidebar at any time — switch in either direction instantly.
  - **Migration 035** seeds the four control keys: `ui_mode` (default: `bigwheel`), `pimpmobile_offer_declines`, `pimpmobile_last_offer_at`, `pimpmobile_never_show`.
  - **Help topic:** Big Wheel & Pimpmobile Modes.
- **Post composer button text** — "SMACK THAT @#$% UP!" restored on new post pages; "FIX UP YOUR @#$% UP" on edit pages. Applies to both standard and carousel variants.

### Smack Central
- **SMACK THE ENEMY — initial build.** Network-wide distributed reputation system for coordinated troll defence. Registered sites report bad fingerprints; the network scores each fingerprint by weighted site reputation and issues colour-coded threat levels (green / yellow / orange / red / black). Blog owners choose their own auto-ban threshold; community allow-votes roll back false positives.
  - `sc-enemy-schema.sql` — 6 tables: `ste_sites`, `ste_fingerprints`, `ste_reports`, `ste_allow_votes`, `ste_score_cache`, `ste_coordination_clusters`.
  - `sc-enemy-scoring.php` — site weight formula (post count × age × approval ratio), time decay (6-month half-life), velocity limiting (20 reports/hour), coordination cluster detection, reporter feedback loop.
  - `sc-enemy-api.php` — REST API: register, report (batch 500), allow-vote, scores/delta, heartbeat, opt-out. Bearer token auth, rate limiting, one-strike-per-site-per-fingerprint.
  - `sc-enemy-admin.php` — Smack Central dashboard: stats grid, Top Scores / Sites / Clusters tabs, reinstate/suspend/resolve/clear actions, inline help.
  - `sc-config.sample.php` updated with `STE_DB_*` constants; `sc-db.php` updated with `sc_enemy_db()`.
- **SMACK THE ENEMY client — blog-side integration.** Opted-in blogs communicate with the central server through the same Bearer token architecture as the community forum. Hidden under Pimpmobile mode.
  - `core/ste-client.php` — API client: register, report, allow-vote, fetch-delta, heartbeat, score lookup helpers (`ste_worst_colour`, `ste_exceeds_threshold`).
  - `core/ban-check.php` updated — `add_ban()` now reports to the network; `is_banned()` checks local `snap_ste_scores` against the configured auto-ban threshold.
  - `smack-settings.php` updated — SMACK THE ENEMY section (Pimpmobile only): Join/Opt Out, participation toggle, auto-ban threshold selector, Sync Now button, last sync timestamp.
  - `smack-comments.php` updated — coloured threat-level dot next to each pending comment; approving a comment sends an allow-vote to the network.
  - `snap_ste_scores` table — local score cache. Seeded by migration 036.
  - **Help topic:** SMACK THE ENEMY — Network Reputation.

---

## 0.7.9N — "Oh Snap" (2026-04-21)

### Added
- **Oh Snap! skin designer — full build.** Oh Snap! is a Tauri desktop app for designing SnapSmack skins without touching code. This release completes the core feature set:
  - **Live srcdoc preview** — skin CSS is inlined into an iframe, not a cross-origin site load. Every control change is instant. Three view modes: Post, Archive, Landing. Three viewport widths: Desktop (1280), Tablet (768), Mobile (390).
  - **Dynamic controls panel** — colour pickers, range sliders, and selects are built automatically from the active skin's `css_variables` manifest declaration. Groups appear as titled sections in the Colours, Type, and Layout sidebar tabs. Colour controls show a native swatch + hex input kept in sync. Range controls show the numeric value live.
  - **Bidirectional CSS editor** — the CSS tab shows the current override block as editable text. Changes in controls update the editor; edits in the editor update the controls and preview.
  - **AI assistant** — AI drawer at the bottom of the app. Describe a skin change in plain English ("make the background warm charcoal with amber text") and the AI returns a JSON object of CSS variable overrides which are applied directly to the preview. Supports four providers: Claude (claude-sonnet-4-6), Gemini (gemini-2.0-flash), OpenAI (gpt-4o), Ollama (local, any model). Provider and API keys configured in Settings.
  - **Settings modal** — accessible via the gear button or Ctrl+comma. Stores API keys in localStorage. Shows only the relevant key section for the active provider.
  - **Project management** — Save project as `.ohsnap` JSON file (Tauri file dialog or browser download fallback). Load project from file. Export as `.css` override file (drop into skin directory or paste into Admin → Pimp → Custom CSS). Auto-saves a draft to localStorage every 30 seconds. Project name is editable inline in the toolbar.
  - **Push to site** — new `ohsnap/skin/vars` API endpoint accepts a JSON object of CSS custom property overrides and stores them in `snap_settings`. The skin's `meta.php` reads this and injects a `:root {}` block after all other skin CSS, so changes appear on the live site immediately without touching any files.
- **New Horizon skin declared `css_variables`** in `manifest.php`. Maps all 15 CSS custom properties (backgrounds, text, borders, inputs, typography) to their Oh Snap! control types, labels, and defaults. New Horizon is the first Oh Snap!-ready skin (flag: `oh_snap_ready: true`).
- **Oh Snap! CSS override layer in New Horizon `meta.php`** — reads `ohsnap_vars_{skin_slug}` from `snap_settings` and injects a sanitised `:root {}` block after `snapsmack-dynamic-css`. Values are re-sanitised at render time (property name regex + value character filter).
- **`pushVars()` method added to `SnapSmackAPI`** — thin wrapper around the new `POST ohsnap/skin/vars` endpoint.

---

## 0.7.9M — "Maintenance Mode" (2026-04-17)

### Fixed
- **Schema sync now reads canonical schema from git instead of maintaining hardcoded copies.** `core/schema-sync.php` previously contained duplicate table definitions hardcoded in a PHP array. When new tables were added to `database/schema/snapsmack_canonical.sql`, the schema-sync function had to be manually updated or new tables wouldn't auto-discover. This was the third/fourth request to implement this fix. Now `snap_parse_canonical_schema()` function reads the canonical SQL file at runtime, extracts CREATE TABLE statements via regex, and builds the table array dynamically. Single source of truth: all schema changes go in canonical.sql, schema-sync reads from it automatically. Eliminates silent schema mismatches that caused features like fingerprints/bans page to stay blank on fresh deployments.

---

## 0.7.9L — "Hot Seat" (2026-04-16)

### Added
- **Media Gallery** (`smack-gallery.php`) — visual DAM (digital asset manager) replacing the flat archive list. Browse, search, filter, and manage the entire image library from one page. Features include AJAX-driven grid with lazy-loaded thumbnails, full-text search across titles/descriptions/tags, filters for album/category/status/camera/date range/colour palette, paginated load-more, rubber-band drag selection, keyboard shortcuts (Ctrl+A, Escape), inline quick-edit panel for title/status/tags/categories/albums, and bulk operations (publish, draft, assign category, assign album, delete). Also supports a picker mode for integration with editors.
- **Photo Editor** (`ss-engine-photo-editor.js`) — canvas-based non-destructive image editor launched from the edit page. Crop with freeform or fixed aspect ratios (1:1, 4:3, 16:9, 3:2) with rule-of-thirds overlay and draggable corner handles. Rotate 90° CW/CCW. Flip horizontal/vertical. Brightness, contrast, and sharpen sliders. Black & white conversion using luminosity method. Full undo stack. Saves at full resolution via `core/photo-editor-save.php` which overwrites the web copy and regenerates square + aspect thumbnails.
- **Edit Image button** added to `smack-edit.php` and `smack-edit-carousel.php` image preview areas.
- **Media Gallery** added to the sidebar navigation under "The Good Shit".
- **Photo editor engine** registered in `core/manifest-inventory.php` for skin manifest access.
- **AI Semantic Fingerprinting & Keyword Banning** — detect persistent trolls using writing style analysis and banned phrases. Browser fingerprints are stored alongside comment text; a TF-IDF semantic engine compares new comments against all prior submissions to find related accounts (55%+ similarity). Keyword/phrase banning supports exact word, substring, and regex matching with two severity levels (flag for review, or silent rejection). New admin tabs: Semantic Analysis (find similar fingerprints by writing style) and Keywords (manage banned phrases). Integration into both photo comment (`process-comment.php`) and community comment (`process-community-comment.php`) handlers. Silent rejection appears to succeed so troll doesn't know they're blocked. Essential for sites facing sophisticated attackers who rotate VPNs.
- **Fingerprints & Troll Bans admin page** updated with Semantic and Keywords tabs.
- **Database:** `snap_comments_semantic` table stores comment text and TF-IDF vectors; `snap_keywords` table stores banned phrases with match types and severity levels (migration 030).
- **Core functions:** `core/semantic-analysis.php` provides `find_similar_fingerprints()`, `store_comment_text()`, TF-IDF and cosine similarity. `core/keyword-check.php` provides `check_keywords()`, `add_keyword()`, `remove_keyword()`.

---

## 0.7.9k — "Is This Seat Taken" (2026-04-15)

### Added
- **All skins committed to git.** Galleria, New Horizon, Hip to be Square, Impact Printer, True Grit, A Grey Reckoning, In Stereo Where Available, Kiosk, and development stubs are now tracked. Base release includes only 50 Shades of Noah Grey and New Horizon; all others distributed via skin gallery.

### Fixed
- **Multisite hub sub-pages (Signals, Posts, Backup Dock, Stats, Cross-Post, Blogroll) all redirected silently back to the dashboard on click.** `core/auth.php` does not populate `$settings`. All six hub sub-pages used `$settings['multisite_role']` before loading it, so the hub guard always fired and redirected. Fixed by loading settings immediately after the auth include in all six files.
- **Hub spoke table showed wrong post counts.** Heartbeat API was counting from `snap_posts WHERE status = 'published'` but SnapSmack's primary content type (transmissions) lives in `snap_images WHERE img_status = 'published'`. Pixhellated was showing 27 instead of 77; water on the brain showing 0 instead of 44. Fixed to count from `snap_images`.
- **Multisite "Last seen" time was always stale after a ping.** Was using `strtotime()` on MySQL's `last_seen_at` string then subtracting PHP's `time()`. When MySQL's server timezone differs from PHP's, the diff is wrong (showed 4h ago for a spoke that just pinged). Now fetches `UNIX_TIMESTAMP(last_seen_at)` directly from MySQL so both values are in the same reference frame. Shows "just now" for pings under 60 seconds.
- **Registration token COPY button was a tiny grey orphan** pushed to the far right of the page using `action-view` class. Replaced with a `btn-smack` button flush against the token field, same height, shows "COPIED ✓" on success.

---

## 0.7.9j — "Is This Seat Taken" (2026-04-13)

### Fixed
- **Multisite status indicators use CSS classes, not hardcoded colours.** `#4CAF50`, `#f44336`, and `#FF9800` removed from PHP. All status dots, labels, backup indicators, version-behind flags, and hub connection border now use `.status-dot--*`, `.status-label--*`, `.version-behind`, and `.hub-connected-border` classes.
- **Phosphor themes no longer bleed non-theme colours.** Green Phosphorus and Amber Phosphorus now override all multisite status classes with brightness-only shades of their respective colours. No reds, oranges, or Material Design colours appear on monochrome displays.
- **Black Pearl disconnect button was invisible** (`#333` text on dark background). Now `#CC4444` with white hover.
- **Heartbeat sweep was skipping offline spokes** — once a spoke went offline it could never recover. Now skips only `disconnected` nodes.
- **Crosspost inline `<style>` and `<script>` blocks eliminated.** CSS moved to `admin-theme-geometry-master.css`; JS moved to `assets/js/ss-engine-crosspost.js`.

### Added
- **Verify Connection button on spoke's multisite page.** Spoke can now actively ping the hub and get immediate confirmation rather than waiting passively for the hub's next heartbeat sweep.

---

## 0.7.9i — "Is This Seat Taken" (2026-04-13)

### Fixed
- **Schema sync now covers multisite tables.** `snap_multisite_nodes` and `snap_multisite_queue` were missing from `schema-sync.php`, so the schema check always reported "up to date" even when the `role` enum was still `enum('hub','satellite')`. Fresh installs now get both tables automatically; existing installs get the enum repaired.
- **Enum repair engine (new Section 4 in schema-sync.php).** Detects stale enum values on existing tables, widens the enum, migrates rows, fixes blanks left by MySQL silent-fail inserts, then shrinks to the canonical definition. First use: `snap_multisite_nodes.role` satellite → spoke.
- **Spoke registration persisting blank role.** `ON DUPLICATE KEY UPDATE` in both `smack-multisite.php` and `core/multisite-api.php` was not updating the `role` column on re-registration. Fixed both handlers.
- **Migration 032 blank-role catch-all.** Added step 3b to fix rows where MySQL silently stored an empty string instead of 'spoke' (non-strict mode behaviour when inserting a value not in the enum).
- **SUYB settings tab layout.** Settings panel ran off-screen with no scrollbar and fields stretched full width. Rewritten with Canvas+Scrollbar wrapper and two-column layout (profile left, global config right).
- **Release package size (49 MB → ~15 MB).** `snapsmack-ca/` (34 MB of screenshots), `tools/`, `smack-central/`, and other dev-only directories now excluded from release builds via `$always_exclude` in `sc-release.php`.

### Added
- **SUYB v0.2.0.** Database SQL dump stage added to backup pipeline (full + schema dumps bundled into ZIP). Google Drive service account integration with global cloud config and per-profile overrides.
- **SUYB Google Drive service account auth.** Auto-detects service account vs OAuth key files. Global cloud config (`[cloud]` section in config) with per-profile override support.

---

## 0.7.9h — "Hub Spoke" (2026-04-13)

### Changed
- **Multisite terminology: satellite → spoke.** The entire codebase now uses "hub/spoke" instead of "hub/satellite". Database enum, PHP admin pages, API comments, sidebar nav labels, help docs, CHANGELOG, README, and landing page copy all updated. Migration 032 alters the `snap_multisite_nodes.role` enum and updates existing rows.

### Added
- **Backup filenames include site title.** Recovery kits exported from the admin panel now use the format `snapsmack_{SiteName}_{timestamp}.tar.gz` instead of the generic `snapsmack_recovery_{timestamp}.tar.gz`. Falls back to the old format when no site name is configured.
- **SUYB hub/spoke discovery.** Smack Up Your Backup can now connect to a hub blog, discover all spokes from `snap_multisite_nodes`, and auto-create profiles for the entire network. Cloud provider and folder ID are pulled from each spoke's `multisite/backup/config` endpoint.
- **SUYB auto-populate cloud config.** "Pull Cloud Config" button on the Settings tab connects to the current profile's blog and pre-fills cloud provider and folder ID from its existing SnapSmack cloud settings.
- **`suyb-data.php` endpoint.** Session-authed JSON endpoint returning cloud config, backup status, and multisite node list for SUYB consumption.
- **`multisite/backup/config` API endpoint.** Bearer-authed endpoint on each spoke returning cloud provider, folder ID, site name, and version (no secrets exposed).

---

## 0.7.9g — "Lumbar Support" (2026-04-10)

### Changed
- **Archive layout ownership moved to site owner.** Skin manifests no longer gate which layouts are available. The Archive Appearance page shows all three modes (Square, Cropped, Masonry) unconditionally; the owner picks the default and which modes to offer visitors as a toggle.
- **Visitor layout toggle.** When the owner enables multiple modes, toggle buttons (Grid / Crop / Flow) appear on the public archive. Visitor preference persists in `localStorage`. The `?layout=` URL param is the mechanism; only owner-approved modes are accepted.
- **`justified_row_height` and `browse_cols` moved to Archive Appearance.** Previously per-skin options in Smooth Your Skin; now global owner settings. Owner sets columns 2–8 and row height 120–500px.
- **`archive_crop_style` removed.** The separate crop-style pill toggle from 0.7.9f is dropped — layout mode and crop style are the same concept.
- **Both skin manifests cleaned** (50 Shades of Noah Grey, Rational Geo): removed `features['archive_layouts']` and the entire ARCHIVE GRID options section (`archive_layout`, `browse_cols`, `justified_row_height`, `archive_default_layout`).

### New setting key
- `archive_layouts_available` — comma-separated list of modes offered to visitors (e.g. `"square,masonry"`). Defaults to the current `archive_layout` (single mode, no toggle shown).

---

## 0.7.9f — "Footrest" (2026-04-10)

### Added
- **Settings restructure**: Appearance settings split into three dedicated pages — Archive Appearance (`smack-appearance-archive.php`), Solo Image Appearance (`smack-appearance-solo.php`), and Static Page Appearance (`smack-appearance-static.php`). All three appear under Pimp Your Ride in the sidebar.
- **Archive Appearance page**: Grid layout, crop style (new pill toggle, skin-gated), thumbnail size, columns slider, gutter slider, tile border/shadow controls, and floating gallery settings — all moved from Global Vibe.
- **Solo Image Appearance page**: EXIF display toggle, download controls (global kill-switch, per-post default, require link enforcement), and stub typography section (drop caps and pull quotes — skin-gated, appear when skin manifest declares support).
- **Static Page Appearance page**: Content width and side gutter sliders — moved from Global Vibe.
- **Crop style toggle**: New `archive_crop_style` setting with radio pill UI. Only shown when active skin declares multiple crop styles in `manifest['archive_options']['crop_styles']`.
- **Archive gutter control**: New `archive_gutter` setting (0–24 px, step 2) on the Archive Appearance page.
- **Archive border/shadow controls**: New `archive_border_style` and `archive_shadow_depth` settings. Shadow depth row shows/hides via JS based on border style selection.
- **Release Systems Reference** (`smack-central/sc-help-release.php`): Internal help page covering version numbering, release script, git workflow, the Release Packager, the Smack Central self-updater, and bootstrapping a new server. Linked from the SC sidebar as System → Release Guide.

### Added (continued)
- **Category visibility** (`smack-cats.php`): Show/hide toggle on each category. Hidden categories are excluded from the archive filter list and their images are hidden from the unfiltered archive grid (images in at least one visible category still show). Schema: `snap_categories.show_in_archive` tinyint(1) default 1. Added to `schema-sync.php` and `snapsmack_canonical.sql`.
- **Archive date filter** (`archive.php`): Accepts `?date=YYYY-MM-DD` to show all posts from a specific day. Used by the calendar engine day-click links.
- **Archive Calendar Engine** (`ss-engine-calendar.js`, `ss-engine-calendar.css`, `api-calendar.php`): Sliding fixed sidebar panel with monthly calendar view and recent post list. Opt-in via `require_scripts[] = 'smack-calendar'` in skin manifest. User controls: months to show (1-3), recent posts listed (5-20), panel side (left/right). Settings passed to JS via `window.SMACK_CONFIG.calendar`. Days with posts highlighted as links into `archive.php?date=`. Month navigation via AJAX. Escape key closes panel.

### Removed
- Archive grid, floating gallery, and page content width controls removed from Global Vibe — now live on their respective appearance pages.
- EXIF, download default, and require-download-link controls removed from Configuration — now live on Solo Image Appearance.

---

## 0.7.9e — "Recliner" (2026-04-10)

### Added
- **Release script** (`tools/release.py`): Single command bumps version across `core/constants.php`, `smack-central/sc-version.php`, and `CHANGELOG.md`. Usage: `python3 tools/release.py 0.7.9f "Codename"`. Idempotent — re-running the same version is safe.
- **Smack Central self-updater** (`smack-central/sc-update.php`): Pulls latest tagged release from GitHub, extracts `smack-central/` subtree, runs `sc-schema.sql` idempotently, records installed tag in `sc_settings`. `sc-config.php` is never touched.
- **Oh Snap! spec expanded**: Sections 7.1–7.3 added covering solo vs carousel preview modes, sample content strategy (live site preferred, local drop-in fallback, carousel padding to 12 images), and Oh Snap! readiness (full design mode vs import mode).

### Fixed
- **Schema sync skipped on updates with no SQL migration files**: `smack-update.php` was guarding `updater_run_migrations()` behind `!empty($migrations)`. Releases that ship no new `.sql` files (like 0.7.9d) silently skipped the canonical schema diff, leaving new tables uncreated on existing installs. Fix: always call `updater_run_migrations()` — the canonical diff runs regardless, the SQL loop is a no-op when the array is empty. Update log now shows which tables were created.
- **Smack Central updater pulls from latest tag, not master**: Swapped `commits/master` SHA lookup for `tags` endpoint. Zip URL now uses `archive/refs/tags/{tag}.zip`. Prevents pulling half-finished commits from a live working branch.

---

## 0.7.9d — "Hot Seat" (2026-04-10)

### Added
- **Oh Snap! API layer**: Six authenticated REST endpoints for the Oh Snap! desktop skin designer (`core/ohsnap-api.php`, routed via `api.php`). Endpoints: `GET ohsnap/ping` (connection test), `GET ohsnap/config` (site name, tagline, active skin), `GET ohsnap/posts` (recent 20 posts with cover images), `GET ohsnap/media` (recent 60 images with thumbnail URLs), `GET ohsnap/skin` (active skin manifest, CSS, and CSS variable map), `POST ohsnap/skin/push` (upload and optionally activate a skin zip).
- **Oh Snap! API key management** (`smack-api-keys.php`): Admin page for generating, labelling, revoking, and deleting API keys. Keys are SHA-256 hashed at rest — the raw key is shown once at creation. Accessible via Boring Ass Stuff → Oh Snap! API Keys.
- **`snap_ohsnap_keys` table**: Schema registered in `schema-sync.php` and `snapsmack_canonical.sql`. Created automatically on next migration runner pass — no manual SQL required.

---

## 0.7.9c — "Electric Chair" (2026-04-09)

### Added
- **AI Writing Assistant engine** (`assets/js/ss-engine-ai.js`): Wires the SP/GR and AI ASSIST buttons in the post editor to `smack-ai-assist.php`. SP/GR checks spelling and grammar on selected text or full content and presents a replace-or-discard overlay. AI ASSIST opens a chat panel with full conversation threading and a Dump to Editor button.
- **Thomas the Bear easter egg** on snapsmack.ca: Ctrl+Shift+Y spawns bears, Ctrl+Shift+Z opens the Noah Grey story modal. Mirrors the Picasa easter egg.

### Changed
- **Gemini model** updated to `gemini-3-flash-preview` in `core/ai-provider.php`.
- **EXIF fields hidden on swap page** when `exif_display_enabled` is off — `smack-swap.php` now respects the same setting as `smack-post.php`.

### Fixed
- **`smack-swap.php` missing `$settings` load**: Page had no access to snap_settings, which would have caused issues with any settings-conditional logic.
- **`core/admin-footer.php` truncated**: Missing `</script></body></html>` restored.
- **`core/ai-provider.php` truncated**: Missing closing return and brace of `_snap_ai_post()` restored.

---

## 0.7.9b — "Electric Chair" (2026-04-08)

### Added
- **Migration 028** (`migrations/028_pages_image_columns.php`): Idempotent migration adds `image_size`, `image_align`, and `image_shadow` columns to `snap_pages`. These were added to the schema in 0.7.8 but the migration was never written, causing server errors on any install that hadn't manually patched the table.

### Changed
- **Interaction page (formerly Community Settings)**: Renamed nav label and page title from "Community Settings" → "INTERACTION" to avoid confusion with the community/forum features. Checkbox toggles replaced with CSS left/right toggle switches (no JS).
- **CSS architecture compliance**: All inline and PHP-injected CSS from `smack-community-settings.php` moved to the correct architecture files — structural rules to `assets/css/admin-theme-geometry-master.css`, per-theme hex colours to each of the 16 `admin-theme-colours-*.css` files. No hex codes in geometry, no structure in colour files.
- **Traffic Stats page title**: `Traffic Stats` → `TRAFFIC STATS` (all admin page titles are ALL CAPS).

### Fixed
- **Server error: `image_size` column not found** (`smack-pages.php`): `snap_pages` was missing the `image_size`, `image_align`, and `image_shadow` columns on production. Run `migrations/028_pages_image_columns.php` to resolve.

---

## 0.7.9a — "Electric Chair" (2026-04-08)

### Added
- **Smack Central skin packager** (`sc-skins.php`): Web UI for packaging skins from the repo — select skins, zip them, Ed25519-sign the zips, and publish to `registry.json` without touching a command line. Screenshot URLs persist across re-packages. Added to Smack Central sidebar.

### Changed
- **Core ships with two skins only**: Removed 12 skins from the repo (`galleria`, `hip-to-be-square`, `photogram`, `true-grit`, `a-grey-reckoning`, `impact-printer`, `new-horizon`, `the-grid`, `kiosk`, `52-card-pickup`, `show-n-tell`, `in-stereo-where-available`). All are available via the skin gallery. Core default is now `50-shades-of-noah-grey`.
- **`SNAPSMACK_MOBILE_SKIN` cleared**: Was hardcoded to `photogram`; now empty string. Mobile visitors get the desktop skin until Photogram is installed from the gallery.
- **AI test connection no longer requires saving first**: TEST CONNECTION now passes the current form values directly to the test endpoint so you can verify a key before committing it to the database.
- **Gemini model updated**: `gemini-1.5-flash` → `gemini-2.0-flash` (1.5-flash deprecated on the v1beta endpoint).

---

## 0.7.9 — "Electric Chair" (2026-04-08)

### Added
- **Multisite Management — full hub/spoke architecture**: New admin suite for managing a fleet of SnapSmack installations from a central hub. Includes hub/spoke mode selection, one-time registration token handshake, Bearer API key authentication, and a public API router (`api.php`). Database migration 027 creates `snap_multisite_nodes` and `snap_multisite_queue` tables.
- **Multisite — live heartbeat sweep**: Hub dashboard polls every active spoke on each page load, caching version, post count, pending comments, backup state, and disk usage to `snap_multisite_nodes`. Marks unresponsive spokes offline automatically.
- **Multisite — Spoke Signal Control** (`smack-multisite-comments.php`): Unified pending comment queue pulling from all spokes. Per-spoke filter tabs with live counts. AUTHORIZE/TERMINATE actions proxied back to the originating spoke.
- **Multisite — Spoke Post Feed** (`smack-multisite-posts.php`): Aggregated reverse-chronological post feed across all spokes. Filter by site or post type, with load-more control.
- **Multisite — Backup Dock** (`smack-multisite-backup.php`): Fleet-wide backup health matrix (healthy/stale/failed/unknown). Per-spoke table with health indicator, last backup time, size, destination, and disk usage. Inline drill-down fetches the spoke's `snap_backup_log` on demand. Stale = status ok but older than 7 days.
- **Multisite — Fleet Stats Rollup** (`smack-multisite-stats.php`): Aggregated traffic across all spokes. Fleet-wide daily sparkline, per-spoke share bars, top 10 referrers across the network. 7d/30d/90d toggle.
- **Multisite — Cross-Post** (`smack-multisite-crosspost.php`): Push hub images to spoke sites. Grid picker with search and pagination. Spoke fetches the image server-to-server from the hub URL (no POST size limits), saves locally, reads EXIF, and creates a draft or published record. Per-post/per-spoke results with direct VIEW link.
- **Multisite — SSO drill-through**: REMOTE LOGIN button on hub spoke table. Hub calls `multisite/auth/sso-token` on the spoke, spoke generates a 64-char one-time token (5-minute TTL). Hub bounces admin's browser to `spoke/sso.php?token=...`. Spoke validates via `hash_equals()`, invalidates token immediately, creates a session for the primary admin user, and redirects to the spoke dashboard. `sso.php` added as spoke-side handler.
- **Multisite — Blogroll Sync** (`smack-multisite-blogroll.php`): PUSH mode sends the hub's full blogroll to selected spokes (placed in a dedicated "Hub:" category, replacing prior hub-synced entries without touching the spoke's own blogroll). PULL mode fetches all spoke blogrolls for review with per-entry IMPORT buttons and duplicate detection.
- **Multisite API endpoints** (`core/multisite-api.php`): `handshake`, `heartbeat`, `comments/pending`, `comments/action`, `posts/recent`, `posts/create`, `stats/daily`, `updates/status`, `backup/status`, `backup/log`, `auth/sso-token`, `blogroll/list`, `blogroll/sync`, `disconnect`.

### Fixed
- **Sticky header smooth unpin (50 Shades)**: Header was snapping back to opaque instantly when scrolling to the top. `transitionend` listener now removes `ss-sticky-active` only after the CSS fade completes.
- **Sticky header transparent-first**: Header goes transparent immediately on stick and stays transparent while scrolling; solid state only on CSS `:hover`.
- **Sticky header not loading on about page**: `skin-page.php` for 50 Shades was exiting before `core/footer-scripts.php` was included, so the sticky header JS/CSS never loaded on static pages. Fixed.
- **Phantom font entry**: Removed `DotMatrix-Var-UltraCondensed` from `core/manifest-inventory.php` — the TTF file doesn't exist (UltraCondensed only exists in the VarDuo subfamily).
- **Multisite API wrong column names**: Phase 1 agent-generated API used `is_active`, `commenter_name`, `post_id` on comments, and a non-existent `is_spam` column. Phase 2 rewrite uses correct schema column names throughout.
- **Multisite API wrong route parsing**: Nested routes like `multisite/comments/pending` were incorrectly parsed with `$action = $parts[1]`. Fixed with `$resource = $parts[1]`, `$sub_action = $parts[2]`.
- **Multisite handshake response parsing**: Hub was checking `response_data['status'] !== 'success'` but API returns `ok: true`. Fixed to check `ok` key.
- **Multisite registration token**: Was reading from `$_SESSION` which could expire or not persist. Now reads from `snap_settings` (where it was already stored).

---

## 0.7.8g — "Raised Toilet Seat" (2026-04-07)

### Added
- **EXIF copyright embedding on web upload**: `snap_exif_write_copyright()` — pure PHP binary IFD0 writer, no external dependencies. Handles both Intel (LE) and Motorola (BE) byte orders. Uses "relocate IFD0 to end of TIFF" strategy so all existing offsets (Exif SubIFD, GPS, thumbnail) remain valid. New `exif_artist` and `exif_copyright` settings in the Image Engine box of Global Settings; leave blank to opt out.
- **True Grit — lightbox backdrop opacity**: New `lightbox_bg_opacity` range slider in the LIGHTBOX section of the True Grit skin manifest. Wired to `window.SMACK_CONFIG.lightbox.opacity` via `core/meta.php`; `ss-engine-lightbox.js` picks it up automatically.
- **`window.SMACK_CONFIG` system**: `core/meta.php` now emits a `<script>window.SMACK_CONFIG = {...};</script>` block when any JS-configurable setting is present. Pattern is extensible for future engine settings without touching JS files.

---

## 0.7.8e — "Raised Toilet Seat" (2026-04-07)

### Added
- **Smack Up Your Backup**: New companion tool listed on the Companion Tools page. Backup and restore tool for SnapSmack sites — pulls the recovery kit, packages versioned ZIPs, pushes to Google Drive or OneDrive, supports cold-start cloud recovery and three-way file auditing.
- **Release builder — auto changelog**: `tools/build-release.php` now parses `CHANGELOG.md` and automatically populates the `changelog` array in the generated `latest.json` template. No more manual editing between build and publish.

### Changed
- **Smack Your Batch Up version badge**: Updated to v0.7.7a-04 on the Companion Tools page (session keepalive fix, snap-to-top queue fix).

---

## 0.7.8c — "Raised Toilet Seat" (2026-04-04)

### Added
- **Tablet responsiveness — touch targets**: Nav links across all stable skins now meet WCAG 2.5.5 minimum touch target height (44px) on touch devices via `@media (pointer: coarse)`. Desktop mouse users see no change. Common selectors handled in `public-facing.css`; skin-specific selectors (A Grey Reckoning, Rational Geo) added per-skin.
- **Tablet responsiveness — 50 Shades archive grid**: Added `@media (max-width: 1400px)` breakpoint switching the cropped grid from fixed pixel columns to `1fr` units. Prevents horizontal scroll at the 1200px tablet floor while leaving the desktop layout (>1400px) unchanged.
- **50 Shades skin — split static page colour pickers**: The single `page_bg_color` picker (which applied one colour to both the content card and the stage behind it) has been replaced with two independent pickers — `stage_bg_color` and `card_bg_color` — so the card can be visually distinct from the background. Re-save skin settings once after deploying to regenerate the compiled CSS.
- **Skin gallery — Photogram hidden**: Photogram was already excluded from the skin configurator tab but was still appearing in both the registry and local-only loops on the gallery tab. Now filtered from all three locations.
- **50 Shades screenshots**: All three skin gallery screenshots (landing, archive, text page) are present and auto-detected.
- **Tablet responsiveness audit**: `docs/tablet-responsiveness-audit.md` added — documents responsive gaps across all stable skins for the 1200px+ tablet range.

### Fixed
- **`100vh` → `100dvh` (all stable skins)**: Dynamic viewport height units replace static `100vh` across 40 instances in 8 skins. Prevents layout jump on tablet browsers (iPadOS Safari, Chrome Android) where the toolbar collapses on scroll. Equivalent to `100vh` on desktop — no visual change there.
- **Static page 500 error on new page without hero image**: `image_size` and `image_align` POST fields are only rendered when a hero image is selected. On new pages the fields were absent; the validation ternary read the undefined key, passed `null` to a `NOT NULL` MySQL column, and MySQL strict mode killed it. Fixed by reading raw POST values with `??` fallback before validation.
- **Admin shortcode picker rendered full-width**: The `sc-shortcode-select` element in the formatting toolbar was being overridden by the global `select { width: 100%; height: 52px; }` rule. Added `!important` to the toolbar-scoped override so the picker renders inline at the intended 155px alongside the other buttons.
- **Static page bottom padding (50 Shades)**: The `.description` container's `margin-bottom: 40px` was adding dead space between the last paragraph and the card edge, on top of the card's own 52px padding. Zeroed out inside `.static-content`.
- **Static page footer gap (50 Shades)**: `padding-bottom: 40px` on `#scroll-stage` created a gap after the footer (footer lives inside scroll-stage). Removed; replaced with `margin-bottom: 40px` on `.static-content` so the gap sits correctly between the card and the footer.
- **Static page heading-to-paragraph gap (True Grit, Galleria)**: `margin-bottom: 0.4em` on headings was correct but CSS margin collapsing let the adjacent `<p>` element's default `margin-top: 1em` win. Fixed by zeroing `margin-top` on paragraphs inside `.static-content .description`.
- **One-time recovery code intercepting live updates**: `smack-update.php` was not in the `force_password_change` exempt list, so an admin with a forced password change was kicked to the password form mid-extraction. Added to exempt list.
- **`force_password_change` silently unenforced at login**: The `SELECT` query in `login.php` did not include the `force_password_change` column, so the enforcement check always read `null`. Column added to the query.

### Changed
- **Static page card background (50 Shades)**: Card now uses `--static-card-bg` CSS variable (defined per variant) rather than the compiled blob value, with `background` (not `!important`) so the new `card_bg_color` skin picker drives it correctly after a settings save.
- **50 Shades variant files**: `--static-card-bg` token added to all three variants (dark: `#212121`, medium: `#404040`, light: `#ffffff`). Adjust via the new card colour picker in Skin Admin rather than editing these directly.

## 0.7.8b — "Raised Toilet Seat" (2026-04-04)

### Added
- **One-time recovery codes**: Admins can generate a single-use recovery code for any user account from the User Manager or Edit User page. Codes are displayed once and stored as bcrypt hashes. Logging in with a recovery code sets a `force_password_change` flag that redirects the user to the password change screen before they can access anything else.
- **Schema sync — Purge Ghost Files**: New button in the Schema Recovery panel that deletes migration files present on disk but not listed in the updater's known migration registry. Ghost files are skipped automatically during updates but now they can be cleared from the UI.

### Fixed
- **Schema column placement**: `recovery_code_hash` and `force_password_change` columns were initially placed in a migration file. Corrected — DDL column additions belong in `schema-sync.php` `$column_additions` array, not in `.sql` migration files (which are for data-only changes). Migration file deleted; `snapsmack_canonical.sql` updated.

---

## 0.7.7b — "Muffet's Tuffet" (2026-04-03)

### Added
- **Floating gallery enhancements**: smooth zoom close animation, image fade-in on load, and reflection toggle (Chromium/Safari, graceful fallback on Firefox). Reflection controlled from Global Vibe.
- **Global Vibe consolidation**: wall friction and drag weight sliders moved from skin manifests to Global Vibe. All floating gallery engine settings now live in one place.
- **Data shortcodes**: 11 new shortcodes for static pages and post content — `[post_count]`, `[site_name]`, `[site_url]`, `[current_year]`, `[years_since year="" month="" day=""]`, `[newest_post]`, `[oldest_post]`, `[archive_link]`, `[gallery_link]`, `[random_image]`, `[latest_image]`.
- **Shortcode insert dropdown**: `‹SC›` button on the static page toolbar opens a dropdown menu for inserting data shortcodes at the cursor.
- **Smack Your Scripts Up!**: New admin page under Pimp Your Ride for third-party scripts (analytics, tracking pixels) and named embed codes. Head scripts stored in DB and injected via `meta.php`; embed codes placed on any page via `[embed:key]` shortcode. Zero git footprint — each site has its own scripts.
- **Static page background colour**: New `page_bg_color` setting added to True Grit, 50 Shades, New Horizon, Galleria, and Hip to be Square. Targets `.static-content` and `#scroll-stage`.
- **Manifest reorganization**: Typography sections cleaned across six skins — fonts-only in Typography, colours split to Colours, header controls to Header & Nav, footer size to Footer.

### Fixed
- **Landing page CSS load order**: `public-facing.css` was loading after dynamic skin CSS on the landing-only path, overriding `page_bg_color` and other skin customizations. Removed duplicate `public-facing.css` loads from both static page paths (`meta.php` already handles it).
- **`$global_only` blocklist typo**: `archive_display_mode` corrected to `archive_layout` — the setting was unprotected since the blocklist entry didn't match the actual key.
- **Orphaned skin control**: removed `show_wall_link` toggle from rational-geo manifest (Global Vibe owns it).

---

## 0.7.7 — "Muffet's Tuffet" (2026-03-29)

### Added
- **Smack Your Batch Up v0.7.7a-01**: Auto-reconnect to Google Drive and site on launch (Drive token.json detected silently; site credentials re-loaded from config). Previously required manual reconnect after cold start.
- **Fix Your Batch Up v0.7.7a-01**: New companion desktop tool for recovering missing Google Drive download links. Accepts a folder of FTP-retrieved server images (A) and a folder of original files (B), uses two-stage matching (pHash pre-filter → SIFT feature matching) to pair them, then lets you review each match and upload individually to Drive. Processes in batches of 10 using up to 75% of available CPU cores. Includes Pick Different (native Windows file dialog, extra-large icons, sort by date) and Skip controls. Available at `snapsmack.ca/tools/fixyourbatchup.zip`.
- **`smack-backfill.php`**: New JSON API endpoint (GET `?action=list`, POST `?action=update`) used by Fix Your Batch Up to fetch images missing Drive links and write the resolved `download_url` back to the DB. Protected by `core/auth.php` session check.
- **Desktop Tools help section**: New admin-only section in `smack-help.php` covering Smack Your Batch Up (installation, Drive auth, posting workflow) and Fix Your Batch Up (when to use, two-stage matching, review interface).
- **`migrate-077.sql`**: Adds `sort_order INT NOT NULL DEFAULT 0` and `img_source_file VARCHAR(255) DEFAULT NULL` columns to `snap_images`. Both use `ADD COLUMN IF NOT EXISTS` for idempotent re-runs.

### Fixed
- **Justified grid archive invisible**: `ss-engine-justified.js` was using `document.querySelector('.justified-grid')` but `archive.php` only emitted `id="justified-grid"` with no class. fjGallery returned immediately on every page load, leaving full rows with no computed height. Fixed by adding `class="justified-grid"` to the grid container in `archive.php`.
- **Archive query on fresh installs**: `ORDER BY i.sort_order` now requires the column to exist — run `migrate-077.sql` before deploying this version.

---

## 0.7.6 — "Poäng Thang" (2026-03-26)

### Changed
- **Bifurcated installer**: Step 1 now presents two edition cards — *1.0 Photoblog* (one image per post, daily archive) and *2.0 Carousel* (multi-image stream). Selection is stored in `site_mode` setting; default skin seeded accordingly (`new-horizon` for 1.0, `the-grid` for 2.0).
- **Mosaic tool removed**: `smack-mosaics.php`, `ss-engine-mosaic.js`, `ss-engine-mosaic.css`, `migrate-mosaic.sql`, and the `parseMosaics()` parser phase have been stripped. The `[mosaic:ID]` shortcode is no longer supported. Mosaic infrastructure will return in a separate product.
- **Blabbermouth / Verbose mode shelved**: SnapSmack now ships as two focused editions (Photoblog and Carousel). Long-form blogging tooling has been removed from the core product.

### Fixed
- **Hyperlink duplication bug**: `insertLink()` in `formatting-toolbar.js` called `_getSelection()` *after* `prompt()`, causing the browser to reset `selectionStart/End` to 0. Selected text was inserted inside the `<a>` tag *and* left in place. Fix: snapshot selection before the dialog opens.
- **Manage Archive — View Post button**: added between SWAP and DELETE for published posts. Opens in a new tab. Hidden for drafts and scheduled posts (no live URL). `.action-view` style added to master CSS (steel blue, consistent across all admin themes).

---

## 0.7.5c — "Sitz Bath" (2026-03-21)

### Added
- **Static content width slider** (Global Vibe → Page Content Width): range control 400–1400 px that sets `--static-content-width` across all skins. Each skin retains its own default as the CSS variable fallback — no change in behaviour until the slider is moved.
- **Archive disabled option**: Archive Display Mode picker (Global Vibe) now includes *Disabled (Hide Archive View)*. Selecting it removes the ARCHIVE VIEW link from the nav on all skins and redirects any direct visit to `archive.php` back to the homepage.
- **Landing Page Only mode** (Global Settings → Homepage Mode): toggle available for both Skin Landing Page and Static Page modes. When enabled, the active skin's header and footer are suppressed and only the page content is shown — no nav, no chrome. Designed for coming-soon pages, splash screens, and single-page portfolios. Static page mode uses the full skin CSS (fonts, background, paper texture) with the nav stripped; skin landing page mode hides the header wrapper via CSS.

### Changed
- **Impact Printer — Fresh Ribbon** ink now substantially heavier and streakier: five-layer `text-shadow` with wider horizontal spread, blur radii, and a slight vertical drip replaces the previous two-layer shadow. Normal Wear also bumped modestly.
- **Impact Printer — site title** centres automatically when the nav-menu is absent (landing-only mode or any other nav-suppressed state), using `:has()` with a `.landing-only` body class fallback.
- `smack-config.php` renamed to `smack-settings.php`; `smack-community-config.php` renamed to `smack-community-settings.php`. All internal references updated. Avoids Imunify360 WAF blocks on HostPapa and similar hosts that reject URL paths containing "config".
- Nav separator logic in `core/header.php` rewritten to prefix-sep pattern — each nav item carries its own leading pipe, so disabling archive (or any other item) never leaves a dangling separator.
- Homepage page picker in Global Settings now correctly shows/hides using `classList.toggle()` instead of mixing inline style with the `d-none` class (was broken: picker stayed hidden when switching to Static Page mode).

### Fixed
- `$local_skins` undefined variable in `smack-skin.php` when gallery tab was not active — initialised at tab-routing time so modal-building code always has a valid array.
- `snap_version_compare()` and `SNAPSMACK_VERSION_CODENAME` now generated in both constants blocks of `install.php` — fresh installs no longer fatal-error on version comparison.

---

## 0.7.5b — "Sitz Bath" (2026-03-21)

### Added
- **Skin Landing Page** homepage mode: when set, the active skin's `landing.php` is shown as the homepage instead of the latest post. A configurable Blog URL Slug (default: `blog`) moves the image feed to a secondary URL and adds a BLOG link to the nav.
- **Show N Tell skin**: portfolio/photoblog hybrid with a horizontal image strip, bio panel, and a configurable featured-work grid.
- Admin footer copyright text visibility improved across all five dark admin themes (Bumblebee, Midnight Lime, The Black Pearl, Green Arrow, Green Phosphorus).

### Fixed
- **Galleria archive layout**: masonry/justified mode was being bypassed because `archive.php`'s skin-override system always loaded the skin's `archive-layout.php` before checking the layout mode. Fixed by branching on `$archive_layout` inside Galleria's `archive-layout.php` — masonry uses the justified row-fill engine; square/cropped use the framed grid.
- Blogroll nav link restored in `core/sidebar.php` — had been silently dropped in commit `604ea1d`.
- Admin UI: batch delete bar now compact (`width: auto`, `height: 32px`) and always visible; filter box top padding removed via `box--no-header` class; sidebar brand border aligns correctly with the ruled header line.

---

## 0.7.5 — "Sitz Bath" (2026-03-21)

_Internal bump. See 0.7.5b for the full feature set._

---

## Impact Printer v1.1 (2026-03-16)

### Added
- Archive thumbnails now use the ASCII box border (matching the hero image frame), hardcoded at 12 px weight with 16 px padding. One look, consistent across the grid.
- Inline `[img:]` page images: independent **Inline Image Frame Style** picker (box / plus / equals / slash / none) with **Inline Image Border Weight** (default 9 px) and **Inline Image Border Padding** (default 8 px) controls in PRINT HEAD.
- Inline images open the full-screen lightbox on click/tap via `data-lightbox-src`.

### Changed
- Archive Thumb Frame picker and Archive Thumb Border Weight slider removed from PRINT HEAD — thumb border is no longer user-configurable.

---

## 0.7.4d — "La-Z-Boy" (2026-03-18)

### Added (2026-03-19)
- **Batch Image Poster** (`tools/ft-batch-poster/`): standalone Windows desktop tool for bulk-posting images to SnapSmack with full EXIF/IPTC/XMP embedding. Loads one or more manifest files (accumulated — existing queue is preserved until the new Clear button is used), drag-reorders entries, sets per-row category and album, resizes to web dimensions, embeds copyright metadata via ExifTool, uploads originals to Google Drive, and posts to SnapSmack in a single batch. Connects to the live site on login and borrows the active admin colour scheme automatically.
- **smack-tools.php**: new admin page (The Good Shit → Tools) listing available companion tools. Admins can upload a zipped build of Batch Image Poster and serve it as a download link from within the CMS.
- `tools/ft-batch-poster/build.bat`: checks for both `exiftool.exe` and the required `exiftool_files\` Perl library folder before building; post-build robocopy step copies both into `dist\` automatically.

### Fixed (2026-03-19)
- Albums page (`smack-albums.php`): ADD TO REGISTRY / UPDATE MISSION button was placed outside the form grid in a `form-action-row` div, causing it to render at the bottom of the page below the footer and be unclickable. Button moved inside the left column, directly below the description textarea. Edit mode: UPDATE MISSION button appears first, CANCEL EDIT below it.

### Added
- Mosaic album builder with `[mosaic:ID]` shortcode for inline tiled image groups. Created via the Mosaics page in the admin (under The Good Shit). Pick assets from media library, drag to reorder, set gap, preview live. Automatic row-based packing respects aspect ratios with no cropping. Responsive layout re-arranges on window resize. Each mosaic gets a unique ID shown in the editor.
- Link dialog with `target="_blank"`, `rel="noopener"`, and `nofollow` options (Ctrl+K shortcut).
- Skin capability flags in manifests (`has_landing`, `post_modes`, `instagram_mode`, `carousel`, `community`).
- Skin detail modal in gallery — click any card to see screenshots, description, and capabilities.
- Content link styling across all skins (previously unstyled default blue).
- Base `.snap-inline-frame` and `.page-hero` CSS rules in `public-facing.css`.
- AI training crawler policy: new **AI Training Crawlers** setting in Global Config → Architecture & Interaction. Three modes — No Opinion (default), Allow, Disallow — control `robots.txt` directives for GPTBot, ChatGPT-User, CCBot, Google-Extended, anthropic-ai, ClaudeBot, and Bytespider. Disallow mode also injects `<meta name="robots" content="noai, noimageai">` on every page. `robots.txt` is regenerated on every Global Config save and always blocks `/smack-*`, `/core/`, `/backups/`, and `/migrations/`.
- Media library asset swap: each asset card now has a **SWAP** button. Replaces the file on disk and updates the database record while preserving the asset ID, so all `[img:ID|...]` shortcodes already embedded in pages continue to resolve without any editing.
- Inline `[img:]` page images now open the full-screen lightbox viewer on click or tap. The `data-lightbox-src` attribute always points to the original full-size file, regardless of whether the shortcode specifies `small`, `wall`, or `full` size.
- Impact Printer: archive thumbnails now always use the ASCII box border (the same pattern as the hero image), hardcoded in `style.css` at 12 px weight with 16 px padding. No picker — just the box, chunky and consistent.
- Impact Printer: inline `[img:]` page images now have an independent **Inline Image Frame Style** picker (box / plus / equals / slash / none) with matching **Inline Image Border Weight** (default 9 px) and **Inline Image Border Padding** (default 8 px) controls. All three appear in PRINT HEAD below the archive frame controls.
- Hex colour code hashtags: `#007a8b`, `#c25e31`, `#8c7d70` etc. now work as tags. Previously, codes starting with a digit were silently dropped by the extraction regex. Both digit-leading and letter-leading 6-char hex codes are now extracted, stored, and rendered as tappable archive links in captions.
- `snap_hex_to_color_family()`: maps any 6-character hex slug to a colour family name (red / orange / yellow / green / teal / blue / purple / pink / grey / black / white) via RGB → HSL conversion.
- Colour-family search: searching "teal" in Archive View now returns images tagged with hex codes belonging to that family (e.g. `#007a8b`). Matched-tag chips below the results surface colour-family hits first.
- `snap_backfill_color_families()`: one-shot post-update function that classifies any pre-existing hex-colour tags already in the database. Runs automatically after a successful update via both the staged-download and manual-ZIP paths.
- Social dock bounds clamping: the dock now stays within the content area between the page header and the bottom navigation bar. Measured dynamically via `.logo-area` / `.nav-menu` (header) and last `.nav-links` (nav bar); re-clamped on scroll (rAF-throttled) and resize. Works across all skins without skin-specific configuration.

### Changed
- Forum API URL hardcoded to snapsmack.ca, removed user-configurable setting.
- Removed legacy files from New Horizon skin (header.php, footer.php, meta.php, footer_scripts.php, skin.json).
- `snap_sync_tags()`: includes `color_family` in the tag upsert. Hex colour codes are classified on first insert; existing tags with `color_family IS NULL` are filled in via `COALESCE` when the image is next saved.
- `snap_render_caption()` / `snap_render_caption_html()`: regex updated to render digit-leading hex codes as links.
- `index.php` `?tag=` routing: now accepts digit-leading 6-char hex slugs (e.g. `?tag=007a8b`).
- `archive.php` `#hashtag` redirect: accepts digit-leading hex slugs.
- Community forum (`smack-forum.php`): consistent page-level `h2` / `header-row` pattern matching the rest of the admin interface. Rows use border separators instead of card backgrounds. Column labels use the `dim` class for theme-aware muting. Action buttons (+ NEW THREAD) live in `box-header` only — never in `header-row`.
- Forum CSS (`admin-theme-geometry-master.css`): `forum-cat-list` and `forum-thread-list` gap set to 0; rows drop `border-radius` and `overflow: hidden`; `border-bottom` separator added with `:last-child` suppression.
- Forum colours (`midnight-lime`): `forum-cat-row` and `forum-thread-row` use `border-bottom-color` instead of `background-color`; hover state uses `rgba(255,255,255,0.025)` instead of a flat fill; thread title hover uses accent green.
- Social dock CSS: `overflow-y: hidden` added to vertical column variants; `top`, `bottom`, and `max-height` added to the transition list for smooth clamping animation.

### Fixed
- Static page hero CSS selector mismatch in True Grit, Impact Printer, 50 Shades (`#tg-photobox` vs `#photobox`).
- `snap-inline-frame` class mismatch — parser output didn't match skin CSS selectors.
- Archive search input vertical alignment in True Grit, Impact Printer, 50 Shades, New Horizon.
- Session timeout doubled (24→48 min), expired sessions return 401 JSON for XHR instead of login page HTML.
- PDO errno 2014 ("Cannot execute queries while other unbuffered queries are active") during SQL migrations on shared hosts. Root cause: `PDO::exec()` doesn't drain MySQL's result/warning packets after DDL statements. Fixed in both `updater_find_migrations()` (CREATE TABLE) and `updater_run_migrations()` (each statement) by replacing `exec()` with `query()` + `closeCursor()`.
- "MISSION FAILURE" alert on new post even though the image posted successfully. Root cause: `snap_sync_tags()` referenced the `color_family` column unconditionally, but installs that hadn't run the 0.7.4c migration hit a PDO fatal error (HTTP 500, empty body) after the DB insert completed. Fixed by detecting column existence and falling back to a simpler INSERT.

### Migrations
- `migrate-074c.sql`: `ALTER TABLE snap_tags ADD COLUMN color_family VARCHAR(20) DEFAULT NULL`; `ADD INDEX idx_tags_color_family`. Idempotent via the migration runner's errno 1060/1061 catch.

---

## 0.7.4b — "Invalid Ring" (2026-03-15)

### Added
- Anonymous likes: visitors can like posts without creating a community account. Tracked by SHA-256 hashed IP — no PII stored.
- Anonymous reactions: same IP-hash pattern as likes. Visitors can react to posts without login. Auth gate on reaction trigger button removed entirely.
- Guest reaction state: dock and inline component both display the visitor's existing reaction on page load (IP hash lookup with try-catch fallback for pre-migration installs).
- Max active reactions raised from 6 to 10.
- Cookie consent banner: `core/consent-banner.php` — links to privacy/cookie page if one exists.
- True Grit transparency system: header and footer backgrounds rendered via `::before` pseudo-elements with configurable opacity (0–100 slider) so text stays fully opaque.
- True Grit header nav colour and hover colour options in manifest.
- True Grit footer font colour and link hover colour options in manifest.
- Help system updates for Photogram, True Grit, and main help file.
- Canonical schema reference file tracked in `database/schema/` — unignored from `.gitignore`, rebuilt with all 24 tables and migration columns folded in.

### Changed
- True Grit skin bumped to v1.1.
- True Grit `optical_lift` default changed from 50 to 0 (user typically sets this to zero).
- True Grit static page top padding balanced with bottom (50px each).
- True Grit footer `padding` stripped of `!important` so footer height slider works from manifest.
- Downloads simplified to global-only — per-post `allow_download` gate removed from `download.php` and `core/download-overlay.php`.
- Three separate `migrate-074b-*.sql` files consolidated into single `migrate-074b.sql`.
- Forum page inline styles (~400 lines) extracted to `admin-theme-geometry-master.css` (layout) and `admin-theme-colours-midnight-lime.css` (colours). No more inline `<style>` block.
- Help manual inline styles (~170 lines) extracted the same way. CSS variable references (`var(--accent)`, `var(--text-secondary)`, etc.) replaced with direct class selectors matching the admin theme system.
- Box sub-structure classes (`.box-header`, `.box-body`, `.box-title`) added to `admin-theme-geometry-master.css` and themed in the midnight-lime colour file.

### Fixed
- Consent banner fatal `PDOException`: query referenced non-existent columns `page_slug` and `page_status` — corrected to `slug` and `is_active`.
- Reaction trigger button (smiley face) forced login redirect even though likes (heart) worked anonymously. Root cause: JS auth gate on `#ss-cdock-react-trigger` not removed when anonymous likes were added. Fixed by removing all auth redirects from reaction triggers in `ss-engine-community.js`.
- Community forum fatal crash on PHP 8+: `count($cats)` called before `$cats` was defined (line 702 vs 710). Page rendered the header then died silently. Fixed by moving the assignment above the count call.
- Help manual search input focus border was blue (`#6cf` from orphaned CSS variable fallback) instead of neon green (`#39FF14`). Now themed through the midnight-lime colour file.
### Migrations
- `migrate-074b.sql`: adds `edited_at` to `snap_community_comments`, adds `guest_hash` column + index to both `snap_likes` and `snap_reactions`. Idempotent via the migration runner's errno 1060/1091 catch.

---

## 0.7.4 — "Whoopie Cushion" (2026-03-15)

### Added
- Photogram profile stats: aggregate like count and comment count now shown alongside post count on the landing page header.
- Photogram grid overlays: each thumbnail in the grid shows heart + comment icons with counts on hover/tap.
- Photogram search upgrade: queries now match against hashtags (via `snap_tags` JOIN) in addition to title and description. Matching tag chips shown above image results as pill-style links with post counts.
- Photogram search `#hashtag` redirect: typing `#concrete` in the search bar redirects straight to the `?tag=concrete` hashtag archive page.
- `smack-post.php` now calls `snap_sync_tags()` after image insert, so hashtags in title and description are indexed on first publish (previously only synced on edit and carousel post).
- Category description field exposed in admin UI (`smack-cats.php`): textarea on create/edit, saved to the existing `cat_description` column, shown inline in the category listing. Useful for category archive pages and SEO meta descriptions.
- Emoji picker on community forum: 20-emoji click-to-insert bar on reply composer and new thread form (`smack-forum.php`).
- Smack Central forum rewrite (`sc-forum.php`): replaced table-of-IDs admin with Discourse-style browsable UI. Category rows with colour accents, threaded post stream with avatars, inline mod controls (pin/unpin, lock/unlock, delete/restore), reply-as-HQ composer. Tabs: Forum (browsable), Installs (table), Manage Boards (table).
- Emoji picker in Smack Central forum with same 20-emoji set.

### Changed
- Photogram skin promoted from `beta` to `stable`. Protected from removal in skin gallery — Photogram is the mandatory mobile skin and cannot be uninstalled.
- Development-status skins (Kiosk, The Grid) now filtered out of the admin skin picker at runtime via manifest status check, regardless of deployment method (git clone vs install package).
- The Grid added to build exclusion list in `build-install-package.php`.
- Smack Central admin tabs restyled for readability: inactive tabs bumped from `--sc-text-dim` to `--sc-text-label` with visible border; active tabs use accent border and header background fill.
- Smack Central table cells now have proper horizontal padding (`12px` on `th` and `td`, was `0`).
- Smack Central `sc-assets.php` template corrected: `sc-box-head` → `sc-box-header`/`sc-box-title`, `sc-tab--active` → `active`, manifest buttons wrapped in flex container.
- Undefined CSS variables replaced: `--sc-surface` → `--sc-bg-box-head` (tabs) and `--sc-success-bg` (flash messages).
- Community forum header changed from "SNAPSMACK ADMINS" to dynamic board count.
- New Horizon Dark skin renamed to New Horizon (`skins/new-horizon-dark/` → `skins/new-horizon/`).
- All file headers bumped to Alpha v0.7.4d across the entire codebase.
- Custom version comparator `snap_version_compare()` added to `core/constants.php` — normalizes letter suffixes (a→.1, b→.2) to numeric segments before delegating to PHP's `version_compare()`. All five comparison call sites updated.
- Skin gallery now shows up to three screenshots per skin (landing, archive, text page) with carousel navigation, dot indicators, and labels.
- Impact Printer skin screenshots added (landing, archive, page).
- Thomas the Bear Easter egg now uses the real Thomas photograph (transparent PNG from Picasa) instead of CSS-constructed bear.
- Thomas Clause attribution corrected per Noah Grey's input.
- Build artifacts (`packages/`, `registry.json`) added to `.gitignore`.

### Fixed
- `smack-post.php` missing `snap_sync_tags()` call — hashtags were silently ignored on initial publish.
- Smack Central forum `$emoji_set` scope bug: variable defined inside one `elseif` branch but referenced in another. Moved to global scope.
- SC Assets tab buttons unreadable (medium grey text on medium grey background).
- SC Assets table cells missing left/top padding.

---

## 0.7.3 — "Whoopie Cushion" (2026-03-14)

### Added
- `core/asset-sync.php`: on-demand font and JS asset delivery. Checks `manifest-inventory.php` against disk; fetches any missing files from Smack Central's `releases/asset-manifest.json`; SHA-256 verifies each download before writing. 1-hour local cache. Auto-runs on skin save (`smack-skin.php`) and after successful updates (`smack-update.php`).
- `smack-central/sc-assets.php`: Asset Repository admin panel in Smack Central. Fonts tab (upload ZIP → extracts TTF/OTF/WOFF by family), Scripts tab (.js + optional .css), Upload tab, Rescan Disk recovery action. Auto-regenerates `releases/asset-manifest.json` on every change.
- `sc_assets` table added to `smack-central/sc-schema.sql`: tracks all hosted font/script files with family, relative path, SHA-256, and download URL.
- `SC_ASSETS_DIR` / `SC_ASSETS_URL` constants added to `smack-central/sc-config.php` and `sc-config.sample.php`.
- Asset Repository nav link added to Smack Central sidebar (`sc-layout-top.php`).
- `updater_prune_backups(int $keep = 3)` in `core/updater.php`: after every successful update, keeps only the 3 most recent pre-update backup files and deletes older ones. Prevents backup directory bloat on long-running installs.
- Photogram: single-tap post image now opens a full-screen lightbox — 80% black backdrop with scale-in animation. Portrait images fill height; landscape/square fill width. Dismisses on backdrop tap, X button, or browser back gesture (`pushState`/`popstate`). Double-tap to like still works: a 310 ms delay on single-tap distinguishes the two gestures.
- Photogram: `static-content` (About and other static pages) padded 20 px horizontally, constrained to the 480 px phone column, with bottom clearance for the nav bar.
- Forum redesign: complete Discourse-inspired dark theme. Category list with coloured accent bars, threaded post stream with gutter avatars, responsive layout. `smack-forum.php` rewritten.
- Forum avatars: site favicons pulled from Google's favicon API (`s2/favicons?domain=&sz=64`) with initial-letter fallback. Rendered square with 4 px border-radius.
- Forum moderator role system: `is_moderator` flag on `ss_forum_installs`. Moderators can pin/unpin threads, lock/unlock threads, and delete any thread or reply. Promote/demote UI in Smack Central forum admin.
- Forum hub posting: Smack Central can post threads and replies as "SnapSmack HQ" via a registered hub install identity (`api_key='hub_internal_reserved'`).
- Forum API `PATCH /threads/{id}` route for moderator pin/lock toggles.
- Forum API now returns `author_domain` on threads and replies (JOIN to `ss_forum_installs`) and `caller_is_mod` flag.
- `sc_forum_db()` added to `smack-central/sc-db.php` for isolated forum database access.
- `migrations/migrate-forum-moderators.sql`: adds `is_moderator` column to `ss_forum_installs` and registers snapsmack.ca as the hub install.
- Social dock redesign: each icon is now an independent 48 px dark circle matching the download button aesthetic. Semi-translucent at idle (configurable opacity), full opacity on hover.
- Social dock absorbs the download button: when downloads are active for the current image, the download icon appears as the first circle in the dock. Standalone download button hidden via JS when dock is present; falls back to standalone when dock is disabled.
- Bluesky SVG icon replaced with the correct butterfly logo (was rendering as a playing card ace at small sizes).
- Threads SVG icon replaced with the official at-sign thread path.

### Changed
- **Photogram promoted to `stable`**. It is now a core skin — shipped in every full release zip and must be present on every install. Removed from optional/beta distribution.
- Release zips are now always **full zips**. GitHub diff API is still called for the build log and schema-change auto-detection, but no longer filters files. Installs that skipped intermediate releases always receive a complete, consistent file set.
- `skins/` removed from `protected_paths.json`. Stock skins (including Photogram) must be updatable; non-stock/boutique skins are never included in the release zip so they are naturally untouched. The fallback hardcoded list in `core/updater.php` updated to match.
- `assets/fonts/` removed from `protected_paths.json`. Fonts are excluded at zip-build time so protecting them at extraction was redundant.
- `smack-central/sc-config.php` is now gitignored. `sc-config.sample.php` (renamed from `sc-config.example.php`) is the committed template; `sc-config.php` is the FTP-ready working copy.
- Release Packager (`sc-release.php`): codename field added to the build form and written to `latest.json`.
- Smack Central page header vertical alignment fixed (`align-items: baseline` → `center`).
- Photogram avatar fallback chain extended: `site_avatar` → `site_logo` → `favicon_url` → SVG placeholder. Sites that have a favicon but no dedicated avatar now show it in the Photogram profile circle.
- Social dock admin: removed icon shape (round/square) and icon style (outline/solid) options. All icons are now circles. Opacity slider relabelled "Idle Opacity" with 10–100% range (default 50%).
- Social dock CSS: glassmorphism bar container removed. No backdrop-blur, no shared border. Each icon stands alone.
- All file headers bumped to Alpha v0.7.3a across the entire codebase (63 files still at v0.7.1 updated).
- Spent migration scripts removed from `migrations/` (already applied to all installs).

### Fixed
- Photogram infinite scroll feed: fixed cursor-based pagination with min/max post ID bounds. Feed now knows upfront when it has reached the true oldest/newest post (via max_id/min_id), preventing ghost AJAX calls and false "no more posts" messages. Updated JS to use DOM-embedded bounds and check reachedTop/reachedBottom conditions before continuing pagination.
- Photogram feed insertion order bug: top sentinel was updating anchor on each insertion, reversing the order of newly-loaded newer posts. Fixed by keeping anchor fixed so posts stack in correct newest-first order below sentinel.
- Photogram caption rendering: HTML tags (`<p>`, `<br>`, `<ul>`, `<li>`, `<strong>`, etc.) now render correctly in post descriptions. `snap_render_caption_html()` whitelist-strips tags and converts plain newlines to `<br>` when no block-level HTML is present.
- Photogram caption layout: removed repeated site name before caption. Changed to show image title in bold above description, with proper spacing. Removed title subtitle under avatar.
- Photogram UI: social dock and sticky header no longer appear on Photogram skin. Both are now conditionally excluded via `$active_skin !== 'photogram'` check in `index.php` footer-scripts includes (main layout, static homepage, hashtag pages).
- Admin archive manager and post editor now display community engagement metrics: like counts for each post in the archive listing and engagement summary (likes + transmissions/comments) in the post editor header.
- `smack-swap.php`: image swap POST returned a server-side `Location:` redirect, which XHR followed and resolved to the full Manage Archive HTML. The JS then showed "MISSION FAILURE: <!DOCTYPE html>…". Fixed by echoing `"success"` (the string the XHR handler expects) instead of redirecting.
- `core/updater.php` hardcoded fallback protected-paths list included `skins/` — contradicting the intentional removal in `protected_paths.json`. Removed.

---

## 0.7.2 — "Sitzfleisch" (2026-03-10)

### Added
- `smack-central/sc-setup.php`: web-based Smack Central installer. Auto-resolves the latest release tag; seeds the database; writes `sc-config.php`; derives and displays the Ed25519 public key after setup.
- Smack Central release builder rewritten to use the **GitHub API** — no shell access or local repo clone required. Downloads the repo zip for any tag, repackages as a clean distributable, signs with Ed25519, and publishes `releases/latest.json`.
- `smack-central/sc-forum.php`: Forum Admin panel. Mirrors the community forum client in the main admin, scoped for Smack Central administrators.
- Delete action added to the Release Packager table — remove bad or test releases with a confirm dialog.
- Ed25519 derived public key displayed inline in the Release Packager UI for easy copy to `core/release-pubkey.php`.

### Changed
- Update apply split into **5 staged XHR requests** with meta-refresh fallback to avoid shared-host 30-second timeouts.
- Chunked zip extraction with session-safe meta-refresh for the extraction stage.
- Pre-update backup switched from full file-tree archive to fast **database-only dump** — eliminates gigabyte-scale backup files on media-heavy installs.
- Backup I/O throttled and streamed in 200-row batches to avoid shared-host rate limits.
- Fonts excluded from release zips; large file streaming added to the extraction engine.
- True Grit wall textures recompressed to 65% JPEG quality; release zip skin exclusion list corrected.
- Differential release packaging: only files changed since the previous tag are included in the zip (superseded by full zips in 0.7.3).

### Fixed
- Migration runner failed on SQL comments containing semicolons. Fixed parser; `migrate-comment-identity.sql` patched.
- Comment-identity migration failed on fresh installs that hadn't yet run the community migration (missing `snap_community_comments`). Added guard.
- Updater skips `1146 ER_NO_SUCH_TABLE` on `ALTER` statements — feature column additions no longer abort on installs where the feature hasn't been migrated yet.
- Stored procedures in migration files rewritten as plain PDO-safe SQL — no `DELIMITER` dependency, compatible with all hosting environments.
- Style column migration moved into `migrate-posts.sql` (correct ordering; previously ran before the `snap_post_images` table existed).

---

## 0.7.1 — "Kneepad"

### Added
- Homepage mode: set a static page as your homepage and move the blog to a separate /blog link in navigation. Global config toggle under Architecture & Interaction.
- OneDrive/SharePoint share links auto-converted to direct downloads in the download overlay, matching existing Google Drive behaviour.
- Spacer shortcode `[spacer:N]` for explicit vertical gaps (1–100px) in the text editor.
- Spacer button added to the shortcode toolbar.
- Community infrastructure: likes (`snap_likes`), reactions (`snap_reactions`), community accounts (`snap_community_accounts`, `snap_community_sessions`), and account verification system.
- Community component (`core/community-component.php`): shared include for likes, reactions, and comments. Drop-in for any skin layout.
- Community dock (`core/community-dock.php`): floating FAB for likes and reactions, position-configurable, conflict-safe with social dock.
- `smack-community-config.php`: admin settings page for community features — global toggles, dock position picker, active reaction set (up to 6), thumbs-down toggle, email settings, rate limits.
- `smack-community-users.php`: community account management page.
- Community nav link added to sidebar under Good Shit section.
- Disaster Recovery split out of Backup & Recovery into its own admin page (`smack-disaster.php`) with Export Recovery Kit, Import Recovery Kit, and User Credentials handlers.
- Disaster Recovery nav link added to sidebar. Backup & Recovery page now links to it from a dedicated button.
- Photogram skin (`skins/photogram/`): phone-native photo feed skin reproducing the Pixelfed/classic Instagram app experience. 3-column square archive grid, full-aspect post view, inline likes, comments bottom sheet, fixed bottom nav bar. Mobile-first; renders as a centred 480px phone column on desktop.
- `ss-engine-photogram.js`: Photogram engine — bottom sheet with touch drag-to-dismiss, double-tap image to like with heart burst animation, like button optimistic UI, nav tab state.
- Photogram design document (`photogram-design-document.docx`): full Phase 1/2 spec including screen inventory, CSS architecture, JS requirements, phase build plan, and open questions.
- Carousel posting infrastructure (dormant until a skin declares `post_page` in its manifest): `smack-post-carousel.php` multi-image composer (1–20 files, full EXIF/resize/thumbnail/checksum/palette pipeline, XHR upload with progress); `ss-engine-carousel-post.js` drag-drop strip engine with per-image EXIF panels, drag-to-reorder, post-type selector, and client-side validation; `migrations/migrate-posts.sql` post schema. `smack-post.php` now checks the active skin manifest for a `post_page` key and delegates to `smack-post-{value}.php` if present, falling through to the standard single-image form otherwise.
- `smack-edit-carousel.php` + `ss-engine-carousel-edit.js`: carousel post editor — reorder images, swap cover, remove individual images, update per-image EXIF metadata, and add more photos to an existing post without re-uploading. Supports per-image frame style editing when the active skin sets `tg_customize_level` to `per_image`.
- `ss-engine-slider.js` Phase 4: keyboard arrow navigation, touch/swipe support, per-slide EXIF auto-update, slide counter badge, and smooth transition engine.
- The Grid skin (`skins/the-grid/`): Instagram-style 3-column square tile feed with full carousel post support. Configurable tile gap, corner radius, hover overlay style, optional profile header (avatar initials or image, post count, bio), and grid max-width (735/935/1080 px). Carousel view has swipe/arrow navigation, EXIF panel updating per-slide, and slide counter. Status: `stable`.
- Image frame customisation for The Grid: a three-level style cascade giving photographers per-image control over image presentation. The skin admin `IMAGE FRAME` section sets the mode (`per_grid` / `per_carousel` / `per_image`) and style defaults. Controllable per image: size within the square (75–100% in 5% steps), border width (0–20 px), border colour, background colour, and drop shadow intensity (none / soft / medium / heavy). Framed tiles switch from `object-fit: cover` to flex-centred `contain` with a configurable background colour — the compositing work photographers previously did in Photoshop actions or phone apps. Schema: `migrate-image-style.sql` (see Migrations).
- Hashtag system (`core/snap-tags.php`, `migrations/migrate-tags.sql`): `#hashtags` parsed from image descriptions at save time. `snap_extract_tags()` extracts slugs; `snap_sync_tags()` upserts to `snap_tags` and maintains `snap_image_tags` junction with rolling `use_count`. `snap_render_caption()` renders captions with `#tags` as tappable archive links. Hooks added to `sm

<!-- ===== SNAPSMACK EOF ===== -->