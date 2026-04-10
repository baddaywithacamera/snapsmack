# SnapSmack Changelog

All notable changes to SnapSmack are documented here. Newest release first.

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
- **Multisite Management — full hub/satellite architecture**: New admin suite for managing a fleet of SnapSmack installations from a central hub. Includes hub/satellite mode selection, one-time registration token handshake, Bearer API key authentication, and a public API router (`api.php`). Database migration 027 creates `snap_multisite_nodes` and `snap_multisite_queue` tables.
- **Multisite — live heartbeat sweep**: Hub dashboard polls every active satellite on each page load, caching version, post count, pending comments, backup state, and disk usage to `snap_multisite_nodes`. Marks unresponsive satellites offline automatically.
- **Multisite — Satellite Signal Control** (`smack-multisite-comments.php`): Unified pending comment queue pulling from all satellites. Per-satellite filter tabs with live counts. AUTHORIZE/TERMINATE actions proxied back to the originating satellite.
- **Multisite — Satellite Post Feed** (`smack-multisite-posts.php`): Aggregated reverse-chronological post feed across all satellites. Filter by site or post type, with load-more control.
- **Multisite — Backup Dock** (`smack-multisite-backup.php`): Fleet-wide backup health matrix (healthy/stale/failed/unknown). Per-satellite table with health indicator, last backup time, size, destination, and disk usage. Inline drill-down fetches the satellite's `snap_backup_log` on demand. Stale = status ok but older than 7 days.
- **Multisite — Fleet Stats Rollup** (`smack-multisite-stats.php`): Aggregated traffic across all satellites. Fleet-wide daily sparkline, per-satellite share bars, top 10 referrers across the network. 7d/30d/90d toggle.
- **Multisite — Cross-Post** (`smack-multisite-crosspost.php`): Push hub images to satellite sites. Grid picker with search and pagination. Satellite fetches the image server-to-server from the hub URL (no POST size limits), saves locally, reads EXIF, and creates a draft or published record. Per-post/per-satellite results with direct VIEW link.
- **Multisite — SSO drill-through**: REMOTE LOGIN button on hub satellite table. Hub calls `multisite/auth/sso-token` on the satellite, satellite generates a 64-char one-time token (5-minute TTL). Hub bounces admin's browser to `satellite/sso.php?token=...`. Satellite validates via `hash_equals()`, invalidates token immediately, creates a session for the primary admin user, and redirects to the satellite dashboard. `sso.php` added as satellite-side handler.
- **Multisite — Blogroll Sync** (`smack-multisite-blogroll.php`): PUSH mode sends the hub's full blogroll to selected satellites (placed in a dedicated "Hub:" category, replacing prior hub-synced entries without touching the satellite's own blogroll). PULL mode fetches all satellite blogrolls for review with per-entry IMPORT buttons and duplicate detection.
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

## 0.5.0 and earlier

Initial development. Admin interface, theme system, per-skin settings scoping,
sidebar redesign, admin theme CSS consolidation, comment controls, footer
configuration, and foundational CMS architecture.
