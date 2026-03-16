# FTP Deploy List — Session 2026-03-15

Upload all files below to the matching paths on foundtextures.ca.

## NEW FILES (create on server)
```
assets/js/ss-engine-overlay.js
assets/js/ss-engine-drawer.js
assets/js/ss-engine-carousel-view.js
migrations/migrate-074b.sql
```

## MODIFIED FILES (overwrite on server)
```
CHANGELOG.md
archive.php
assets/css/admin-theme-geometry-master.css
assets/css/public-facing.css
assets/css/ss-community.css
assets/css/ss-engine-social-dock.css
assets/css/ss-engine-wall.css
assets/adminthemes/midnight-lime/admin-theme-colours-midnight-lime.css
assets/js/ss-engine-comms.js
assets/js/ss-engine-community.js
assets/js/ss-engine-logo.js
assets/js/ss-engine-pimpotron.js
assets/js/ss-engine-slider.js
assets/js/ss-engine-wall.js
core/community-dock.php
core/header.php
core/manifest-inventory.php
gallery-wall.php
index.php
skins/galleria/landing.php
skins/galleria/layout.php
skins/galleria/manifest.php
skins/hip-to-be-square/landing.php
skins/hip-to-be-square/layout.php
skins/hip-to-be-square/manifest.php
skins/kiosk/layout.php
skins/rational-geo/layout.php
skins/rational-geo/manifest.php
skins/the-grid/hashtag.php
skins/the-grid/layout.php
skins/the-grid/manifest.php
skins/the-grid/style.css
skins/true-grit/hashtag.php
skins/true-grit/style.css
smack-forum.php
smack-help.php
```

## DELETE FROM SERVER (consolidated into migrate-074b.sql)
```
migrations/migrate-074b-community-edited-at.sql
migrations/migrate-074b-guest-likes.sql
migrations/migrate-074b-guest-reactions.sql
```

## POST-UPLOAD
Run `migrate-074b.sql` if not already applied (idempotent — safe to re-run).

## WHAT CHANGED
- **Inline code cleanup**: All inline `<script>` blocks extracted to engine JS files, inline `style=""` moved to CSS
- **Unified reaction picker**: Heart/like merged into emoji pad (single FAB instead of two)
- **Icon balance**: Download and emoji icons now both 20px
- **Archive fix**: True Grit square-grid thumbnails now crop correctly
- **New engines registered**: overlay, drawer, carousel-view in manifest-inventory
- **Forum fix**: Fixed `count($cats)` crash (variable used before assignment), moved inline `<style>` to geometry master
- **Help manual fix**: Moved inline `<style>` to geometry master, colours now themed via midnight-lime
- **Box sub-structure**: Added `.box-header`/`.box-body`/`.box-title` to geometry master for forum containers
