# SnapSmack Changelog

All notable changes to SnapSmack are documented here. Newest release first.

---

## Impact Printer v1.1 (2026-03-16)

### Added
- Archive thumbnails now use the ASCII box border (matching the hero image frame), hardcoded at 12 px weight with 16 px padding. One look, consistent across the grid.
- Inline `[img:]` page images: independent **Inline Image Frame Style** picker (box / plus / equals / slash / none) with **Inline Image Border Weight** (default 9 px) and **Inline Image Border Padding** (default 8 px) controls in PRINT HEAD.
- Inline images open the full-screen lightbox on click/tap via `data-lightbox-src`.

### Changed
- Archive Thumb Frame picker and Archive Thumb Border Weight slider removed from PRINT HEAD — thumb border is no longer user-configurable.

---

## 0.7.4c — "La-Z-Boy" (2026-03-17)

### Added
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
- `snap_sync_tags()`: includes `color_family` in the tag upsert. Hex colour codes are classified on first insert; existing tags with `color_family IS NULL` are filled in via `COALESCE` when the image is next saved.
- `snap_render_caption()` / `snap_render_caption_html()`: regex updated to render digit-leading hex codes as links.
- `index.php` `?tag=` routing: now accepts digit-leading 6-char hex slugs (e.g. `?tag=007a8b`).
- `archive.php` `#hashtag` redirect: accepts digit-leading hex slugs.
- Community forum (`smack-forum.php`): consistent page-level `h2` / `header-row` pattern matching the rest of the admin interface. Rows use border separators instead of card backgrounds. Column labels use the `dim` class for theme-aware muting. Action buttons (+ NEW THREAD) live in `box-header` only — never in `header-row`.
- Forum CSS (`admin-theme-geometry-master.css`): `forum-cat-list` and `forum-thread-list` gap set to 0; rows drop `border-radius` and `overflow: hidden`; `border-bottom` separator added with `:last-child` suppression.
- Forum colours (`midnight-lime`): `forum-cat-row` and `forum-thread-row` use `border-bottom-color` instead of `background-color`; hover state uses `rgba(255,255,255,0.025)` instead of a flat fill; thread title hover uses accent green.
- Social dock CSS: `overflow-y: hidden` added to vertical column variants; `top`, `bottom`, and `max-height` added to the transition list for smooth clamping animation.

### Fixed
- PDO errno 2014 ("Cannot execute queries while other unbuffered queries are active") during SQL migrations on shared hosts. Root cause: `PDO::exec()` doesn't drain MySQL's result/warning packets after DDL statements. Fixed in both `updater_find_migrations()` (CREATE TABLE) and `updater_run_migrations()` (each statement) by replacing `exec()` with `query()` + `closeCursor()`.

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
- All file headers bumped to Alpha v0.7.4 across the entire codebase.
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
