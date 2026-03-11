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

### Changed
- All stable skins (Galleria, Hip to be Square, Impact Printer, True Grit, 50 Shades of Noah Grey, New Horizon Dark, Pocket Rocket) retrofitted with community component and community dock. Legacy anonymous comment system removed from all skins.
- Each retrofitted skin manifest updated with `smack-community` in `require_scripts` and `community_comments`, `community_likes`, `community_reactions` flags.
- `core/community-component.php`: added `$pg_suppress_likes` override flag so Photogram (and future skins) can suppress the likes row when handling likes inline.
- `core/manifest-inventory.php`: `smack-photogram` engine registered.
- Pocket Operator skin renamed to **Pocket Rocket** throughout (`skins/pocket-operator/` → `skins/pocket-rocket/`, all internal references updated).
- `SNAPSMACK_MOBILE_SKIN` constant changed from `pocket-operator` to `photogram`. Photogram is now the default mobile skin served automatically on phone detection.
- Pocket Rocket status set to `beta` (functional, present in all installs, superseded by Photogram).
- Photogram status set to `beta` (Phase 1 complete, serving as default mobile skin).
- Shortcode toolbar split onto two rows to accommodate new buttons.
- Content links in Impact Printer styled monochrome (inherit colour, underline only).
- Build script outputs `snapsmack-{version}.zip` instead of `snapsmack-{version}-full.zip`.
- Inline image shortcodes use `snap-framed-img` class instead of `snapsmack-asset` to avoid inherited margins inside picture frames.
- Thomas the Bear CSS restored from correct source.
- Font license files normalised (line endings only, no content changes).

### Fixed
- `<p>` tags no longer wrap block-level image frame divs on static pages (display-time `cleanBlockNesting` in parser).
- ASCII border frames now shrink-wrap tightly around images (`width: fit-content`).

### Migrations
- `migrate-rename-pocket-operator.sql`: updates any install with `active_skin = 'pocket-operator'` in `snap_settings` to `pocket-rocket`. Safe to run on installs that never used Pocket Operator (no-op).
- Community infrastructure tables (`snap_likes`, `snap_reactions`, `snap_community_accounts`, `snap_community_sessions`) require the 0.7.1 migration to be run before community features are active.

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
