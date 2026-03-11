# SnapSmack Changelog

All notable changes to SnapSmack are documented here. Newest release first.

---

## 0.7.1 (in progress)

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
