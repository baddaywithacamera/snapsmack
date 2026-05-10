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
- Hashtag system (`core/snap-tags.php`, `migrations/migrate-tags.sql`): `#hashtags` parsed from image descriptions at save time. `snap_extract_tags()` extracts slugs; `snap_sync_tags()` upserts to `snap_tags` and maintains `snap_image_tags` junction with rolling `use_count`. `snap_render_caption()` renders captions with `#tags` as tappable archive links. Hooks added to `smack-edit.php`, `smack-post-carousel.php`, and `smack-edit-carousel.php`. `?tag=slug` routes to `skins/{skin}/hashtag.php` via `index.php`.
- Photogram hashtag archive (`skins/photogram/hashtag.php`): tag header (icon, post count, back link) + paginated 3-column grid of matching photos.
- The Grid hashtag archive (`skins/the-grid/hashtag.php`): tag header + paginated tg-grid respecting tile gap, radius, and max-width settings.
- Photogram search (`skins/photogram/search.php`): LIKE search across `img_title` and `img_description`, 60-result cap, 3-column results grid, empty state. Off by default (`search_enabled = 0` in Global Config). Search nav tab appears when enabled.
- Photogram about tab: bottom nav person icon now resolves to the page with `slug = 'about'` (falls back to first active page by menu_order). Tab is hidden when no pages exist. Previously pointed back to home.
- `site_description` field added to Global Config → Site Identity & Branding (textarea, 1–2 sentences). Used as the profile bio in Photogram and The Grid landing pages, and as the preferred `og:description` source. Both skins already read this key; the field simply had no way to be populated before.
- `smack-forum.php`: community forum client. Renders inside the admin panel; accessible only to install administrators. Auto-registers the install with the forum hub on first visit (stores API key in `snap_settings` as `forum_api_key`). Views: board list, thread list (paginated), thread detail with replies, new thread form, reply form. Delete controls for own posts. Enabled by default; configurable via `forum_enabled` toggle in Global Config. API endpoint configurable via `forum_api_url` for self-hosted forks.
- `forum_enabled` and `forum_api_url` settings added to Global Config → Architecture & Interaction. `forum_enabled` defaults on. `forum_api_url` defaults to `https://snapsmack.ca/api/forum`; self-hosters point this at their own hub.
- Forum nav link added to sidebar under **Help, I Need Somebody!** section alongside User Manual.
- `smack-central/`: SMACK CENTRAL hub administration application. Separate codebase deployed to snapsmack.ca as its own git repo. Foundation: `sc-config.example.php`, `sc-schema.sql` (`sc_admin_users`, `sc_settings`, `sc_releases`, `sc_rss_cache`), `sc-db.php`, `sc-auth.php`, `sc-login.php`, `sc-logout.php`, `sc-layout-top.php`, `sc-layout-bottom.php`, `sc-dashboard.php`.
- `smack-central/assets/css/sc-geometry.css`: Design token file. All `--sc-` custom properties mirroring SnapSmack midnight-lime admin visual language with `sc-` namespace for future merge compatibility.
- `smack-central/assets/css/sc-admin.css`: Full SMACK CENTRAL component stylesheet — layout shell, sidebar, boxes, form fields, buttons, alerts, tables, stat grid, status dots, release result panel, build log.
- `smack-central/sc-release.php`: Release Packager. Pulls a git tag from a local SnapSmack repo clone, builds a distributable zip via `git archive`, SHA-256 checksums it, signs the checksum with the Ed25519 private key via libsodium, moves the zip to the releases directory, writes `releases/latest.json` in the format `core/updater.php` expects, computes `file_changes` by diffing against the previous release tag, and persists the full release record to `sc_releases`. Preflight panel warns on missing dependencies. Release history table and live `latest.json` preview inline.
- `smack-central-design.docx` updated: Phase 6 (Release Packager) added to Build Order; Release Packager added to Modules and File Structure tables.
- Comment identity system: three modes selectable in Community Settings → System Toggles.
  - `open` (default) — any visitor can comment with just a name; no account or login required.
  - `hybrid` — logged-in account holders post with full identity; guests still welcome with a name.
  - `registered` — community account required (original behaviour).
  - `snap_community_comments.user_id` made nullable; `guest_name` (VARCHAR 100) and `guest_email` (VARCHAR 200) columns added. See `migrations/migrate-comment-identity.sql`.
  - Guest comments display the visitor's chosen name with an initial avatar. No delete button (guests have no session to prove identity).
  - Rate limiting unchanged — already IP-based, applies equally to guests and account holders.

### Changed
- `search_enabled` moved from Photogram skin manifest (`pg_show_search`) to global `snap_settings` key, controlled from **Global Config → Architecture & Interaction**. Skins that support search read this shared key; setting survives skin switches.
- `og:description` now prefers `site_description` over `site_tagline`. Tagline remains the fallback. Keeps punchy browser-tab slogans out of link unfurl previews.
- All stable skins (Galleria, Hip to be Square, Impact Printer, True Grit, 50 Shades of Noah Grey, New Horizon Dark, Pocket Rocket) retrofitted with community component and community dock. Legacy anonymous comment system removed from all skins.
- Each retrofitted skin manifest updated with `smack-community` in `require_scripts` and `community_comments`, `community_likes`, `community_reactions` flags.
- `core/community-component.php`: added `$pg_suppress_likes` override flag so Photogram (and future skins) can suppress the likes row when handling likes inline.
- `core/manifest-inventory.php`: `smack-photogram` engine registered.
- Pocket Operator skin renamed to **Pocket Rocket** throughout (`skins/pocket-operator/` → `skins/pocket-rocket/`, all internal references updated).
- `SNAPSMACK_MOBILE_SKIN` constant changed from `pocket-operator` to `photogram`. Photogram is now the default mobile skin served automatically on phone detection.
- Pocket Rocket status set to `beta` (functional, present in all installs, superseded by Photogram).
- Photogram status set to `beta` (Phase 1 complete, serving as default mobile skin).
- The Grid status set to `stable` (full carousel post system, image frame customisation, community-ready).
- Shortcode toolbar split onto two rows to accommodate new buttons.
- Content links in Impact Printer styled monochrome (inherit colour, underline only).
- Build script outputs `snapsmack-{version}.zip` instead of `snapsmack-{version}-full.zip`.
- Inline image shortcodes use `snap-framed-img` class instead of `snapsmack-asset` to avoid inherited margins inside picture frames.
- Thomas the Bear CSS restored from correct source.
- Font license files normalised (line endings only, no content changes).

### Fixed
- `<p>` tags no longer wrap block-level image frame divs on static pages (display-time `cleanBlockNesting` in parser).
- ASCII border frames now shrink-wrap tightly around images (`width: fit-content`).
- F1 help not firing on Galleria after Phase C community retrofit. Root cause: `community-component.php` and `community-dock.php` called `community_current_user()` before the community migration had been run, throwing an uncaught `PDOException` that halted rendering before `ss-engine-comms.js` loaded. Fix: added `snap_community_ready()` guard to `core/community-session.php`; both includes bail silently when the community tables are absent.
- F1 help modal invisible on texture-background skins (Impact Printer). Root cause: Impact Printer sets `body` background via `background-image` only — `getComputedStyle(body).backgroundColor` returns `rgba(0,0,0,0)`, making the modal panel transparent. Fix: added `getThemeColors()` helper to `ss-engine-comms.js` that reads `--bg-primary` / `--text-primary` from `:root` CSS custom properties first, falls back to computed body styles, then falls back to `#1a1a1a` / `#e0e0e0` if still transparent.
- Unsolicited Disaster Recovery button removed from `smack-backup.php` header. The button was added uninstructed when `smack-disaster.php` was split out and broke the layout.
- Photogram `layout.php` like queries used wrong column names (`img_id` / `account_id`) against `snap_likes` which uses `post_id` / `user_id`. Fixed both queries and replaced stale `$_SESSION['community_account_id']` with `community_current_user()`.
- Photogram system footer cut off by fixed bottom nav. Root cause: `core/footer.php` renders `#system-footer` in normal document flow below `#pg-app`, directly under the `position: fixed` nav bar. Fix: `#system-footer { display: none; }` in Photogram's `style.css`. The bottom nav replaces the site footer concept in this skin.

### Migrations
- `migrate-rename-pocket-operator.sql`: updates any install with `active_skin = 'pocket-operator'` in `snap_settings` to `pocket-rocket`. Safe to run on installs that never used Pocket Operator (no-op).
- Community infrastructure tables (`snap_likes`, `snap_reactions`, `snap_community_accounts`, `snap_community_sessions`) require the 0.7.1 migration to be run before community features are active.
- `migrate-posts.sql`: creates `snap_posts`, `snap_post_images`, `snap_post_cat_map`, `snap_post_album_map`; adds `post_id` FK column to `snap_images`; wraps all existing images in single-type post records for forward compatibility. Non-destructive — legacy skins continue querying `snap_images` unchanged. Required before any skin activating `post_page` in its manifest can be used.
- `migrate-image-style.sql`: adds five style columns to `snap_post_images` (`img_size_pct`, `img_border_px`, `img_border_color`, `img_bg_color`, `img_shadow`) and five matching columns to `snap_posts` (`post_img_size_pct`, `post_border_px`, `post_border_color`, `post_bg_color`, `post_shadow`). MySQL-safe stored procedure pattern — checks `information_schema.COLUMNS` before altering; safe to re-run. Required before The Grid image frame customisation system is active.
- `migrate-tags.sql`: creates `snap_tags` (global tag registry) and `snap_image_tags` (image ↔ tag junction). MySQL-safe stored procedure pattern. Required before hashtag extraction and archive pages are active.

---

## 0.7.0 — "Lapdog" (2026-03-08)

### Added
- Inline image frames: `[img:ID|size|align]` shortcodes render inside the skin's ASCII border frame on static pages.
- Smart Open Graph tags with latest-image fallback for all skins.
- Release packaging system (`tools/build-install-package.php`, `tools/sign-release.php`).
- Ed25519 package signing with public key verification enforced.
- Skin packager for building individual skin zips (`tools/build-skin-package.php`).
- UL/OL list buttons in the shortcode toolbar with keyboard shortcuts (Ctrl+U, Ctrl+O).
- Underline button and shortcut (Ctrl+Shift+U) in the shortcode toolbar.
- Custom list markers so UL/OL render in the active skin font.
- Site email field in Configuration and install wizard.
- Cropped grid support in Impact Printer archive layout.
- EXIF display toggle (`exif_display_enabled`) respected across all skin layouts.

### Changed
- All file headers bumped to Alpha v0.7.
- Kiosk and Impact Printer excluded from the public install package (development/beta status).
- Toolbar keyboard shortcuts documented in help system.

### Fixed
- Sidebar variable collision that broke static pages listing.
- Skin settings form showing stale values after save.
- Edit page album/category dropdowns loading wrong JS.
- `smack_autop()` mangling `<ul>`, `<ol>`, and other block HTML.
- Cropped grid forcing 1:1 aspect ratio in Impact Printer.
- Portrait thumbnails capped to landscape height in cropped grid.
- UL/OL buttons now split selected text into individual list items.
- Installer progress dots skipping step 3.

---

## 0.6.0 (2026-02)

### Added
- Self-update system with Ed25519 signing and dual admin notifications.
- Setup bootstrap deployer (`setup.php`) and first-run install wizard (`install.php`).
- Floating social profile dock with glass-morphism UI and appearance customisation.
- Sticky header engine with glass-morphism transparency.
- Full help system with table of contents, full-text search, and skin-specific topic hooks.
- Backup, recovery, and export system with FTP support.
- OAuth cloud push to Google Drive and OneDrive with persistent refresh tokens.
- Formatting toolbar with live preview, columns, and dropcap support.
- Release signing utility with Ed25519 verification.
- Self-update version check with cron registration UI.
- Skin gallery for browsing and installing skins.
- Batch throttling for all bulk thumbnail and checksum operations.
- Recovery system, schema enrichment, integrity tools, and .htaccess repair.
- Rational Geo skin (NatGeo-inspired editorial magazine theme).
- Pocket Operator mobile-first skin (doomscroll feed, hamburger nav, drawer UI).

### Changed
- Documentation standardisation pass across all files.
- Hardened .htaccess with HTTPS redirect, security headers, and asset caching.
- Installer appends to existing .htaccess instead of skipping.
- Removed Picasa Web Albums skin.

### Fixed
- Preflight security holes in custom JPG handling (found in audits by Claude and Gemini).
- Google Drive share links auto-converted to direct downloads.
- Various skin display bugs in New Horizon Dark and 50 Shades of Grey.

---
<!-- ===== SNAPSMACK EOF ===== -->
