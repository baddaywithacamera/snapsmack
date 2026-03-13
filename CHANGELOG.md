# SnapSmack Changelog

All notable changes to SnapSmack are documented here. Newest release first.

---

## 0.7.3 — "Bedpan" (2026-03-12)

### Added
- `core/asset-sync.php`: on-demand font and JS asset delivery. Checks `manifest-inventory.php` against disk; fetches any missing files from Smack Central's `releases/asset-manifest.json`; SHA-256 verifies each download before writing. 1-hour local cache. Auto-runs on skin save (`smack-skin.php`) and after successful updates (`smack-update.php`).
- `smack-central/sc-assets.php`: Asset Repository admin panel in Smack Central. Fonts tab (upload ZIP → extracts TTF/OTF/WOFF by family), Scripts tab (.js + optional .css), Upload tab, Rescan Disk recovery action. Auto-regenerates `releases/asset-manifest.json` on every change.
- `sc_assets` table added to `smack-central/sc-schema.sql`: tracks all hosted font/script files with family, relative path, SHA-256, and download URL.
- `SC_ASSETS_DIR` / `SC_ASSETS_URL` constants added to `smack-central/sc-config.php` and `sc-config.sample.php`.
- Asset Repository nav link added to Smack Central sidebar (`sc-layout-top.php`).
- `updater_prune_backups(int $keep = 3)` in `core/updater.php`: after every successful update, keeps only the 3 most recent pre-update backup files and deletes older ones. Prevents backup directory bloat on long-running installs.
- Photogram: single-tap post image now opens a full-screen lightbox — 80% black backdrop with scale-in animation. Portrait images fill height; landscape/square fill width. Dismisses on backdrop tap, X button, or browser back gesture (`pushState`/`popstate`). Double-tap to like still works: a 310 ms delay on single-tap distinguishes the two gestures.
- Photogram: `static-content` (About and other static pages) padded 20 px horizontally, constrained to the 480 px phone column, with bottom clearance for the nav bar.

### Changed
- **Photogram promoted to `stable`**. It is now a core skin — shipped in every full release zip and must be present on every install. Removed from optional/beta distribution.
- Release zips are now always **full zips**. GitHub diff API is still called for the build log and schema-change auto-detection, but no longer filters files. Installs that skipped intermediate releases always receive a complete, consistent file set.
- `skins/` removed from `protected_paths.json`. Stock skins (including Photogram) must be updatable; non-stock/boutique skins are never included in the release zip so they are naturally untouched. The fallback hardcoded list in `core/updater.php` updated to match.
- `assets/fonts/` removed from `protected_paths.json`. Fonts are excluded at zip-build time so protecting them at extraction was redundant.
- `smack-central/sc-config.php` is now gitignored. `sc-config.sample.php` (renamed from `sc-config.example.php`) is the committed template; `sc-config.php` is the FTP-ready working copy.
- Release Packager (`sc-release.php`): codename field added to the build form and written to `latest.json`.
- Smack Central page header vertical alignment fixed (`align-items: baseline` → `center`).
- Photogram avatar fallback chain extended: `site_avatar` → `site_logo` → `favicon_url` → SVG placeholder. Sites that have a favicon but no dedicated avatar now show it in the Photogram profile circle.

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
