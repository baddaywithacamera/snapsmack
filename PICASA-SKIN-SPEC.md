# SnapSmack Skin: Mi Casa Es Su Picasa (Public Skin)
## Planning Document — NOT FOR RELEASE

> Status: **Queued** — Build after updater system is complete.
> Priority: After skin registry + updater pipeline.

---

## Concept

Recreate the Google Picasa 3 desktop application (2008–2015 era) as a full-page
SnapSmack skin. The browser viewport becomes the Picasa window — title bar, menu
bar, toolbar, content well, and status bar. Photos are browsed in a Picasa-style
library grid, and clicking an image opens the Picasa photo viewer with the
signature sliding filmstrip at the bottom.

The admin theme "mi-casa-es-su-picasa" already exists — this is the **public**
companion skin that makes the visitor-facing site look like Picasa.

---

## Design Reference: Picasa 3

### Window Chrome (top to bottom)
1. **Title bar** — Dark blue/grey gradient. App icon + "Picasa 3" + window controls
   (min/max/close). In our case: site name replaces "Picasa 3".
2. **Menu bar** — File / Edit / View / Folder / Tools / Help. We repurpose these
   as: Home / Archive / Wall / Blogroll / Pages / About (mapped to SnapSmack nav).
3. **Toolbar** — Icon buttons row below menu. Search bar on the right. Breadcrumb
   path in the center showing current view context.
4. **Content well** — Light grey background. This is where photos live.
5. **Status bar** — Bottom strip showing item count, zoom slider, view toggles.

### Library View (archive.php)
- Photos displayed in a grid grouped by date (Picasa's "tray" system)
- Each group has a collapsible header showing the date range
- Thumbnails are square with thin borders, slight shadow on hover
- Left sidebar panel (optional) showing folder tree / categories
- Blue highlight on selected/hovered items

### Photo Viewer (index.php)
- Full content area dedicated to the hero image
- Below the image: the **filmstrip** — a horizontal scrolling strip of thumbnails
  from the same archive/category
- Navigation arrows on left/right edges
- Info panel (title, date, EXIF) slides in from right or bottom
- Star rating display (maps to existing metadata)

### Colour Palette (Picasa 3)
- Title bar gradient: #3A5A8C → #2A4570 (blue-grey)
- Menu bar: #E8E8E8 (light grey)
- Toolbar: #D4D4D4 (medium grey)
- Content well: #F0F0F0 (near white)
- Status bar: #E0E0E0
- Accent/selection: #4A90D9 (Picasa blue)
- Text: #333333
- Filmstrip background: #1A1A1A (dark, like Picasa's viewer mode)

---

## Technical Architecture

### Skin Directory Structure
```
skins/mi-casa-es-su-picasa/
├── manifest.php          # Skin config, status: beta
├── skin-meta.php         # Head injection (fonts, viewport)
├── skin-header.php       # Title bar + menu bar + toolbar
├── skin-footer.php       # Status bar
├── layout.php            # Photo viewer page (hero + filmstrip)
├── style.css             # All Picasa chrome styling
├── filmstrip.js          # Filmstrip thumbnail scroller (or leverage existing engine)
└── screenshot.png        # Full HD preview for gallery
```

### Manifest Settings
```php
'name'     => 'Mi Casa Es Su Picasa',
'version'  => '0.1',
'status'   => 'beta',
'features' => [
    'supports_wall'   => false,    // Picasa didn't have a wall mode
    'archive_layouts' => ['square'],  // Picasa = square grid only
],
'require_scripts' => [
    'smack-footer',
    'smack-lightbox',
    'smack-keyboard',
],
```

### Filmstrip Component
The filmstrip at the bottom of the photo viewer needs:
- Horizontal scrolling thumbnail strip (CSS `overflow-x: auto` or JS drag-scroll)
- Current image highlighted with blue border
- Click thumbnail to navigate to that photo
- Keyboard left/right arrows scroll the strip
- Data source: same prev/next chain used by keyboard navigation

Could potentially extend `smack-keyboard.js` or create a `smack-filmstrip.js`
engine that reads `window.SNAP_DATA` for prev/next URLs and fetches adjacent
thumbnails via a lightweight API endpoint.

### CSS Window Chrome
The window chrome is pure CSS — no images needed:
- Title bar: CSS gradient + flexbox for layout
- Menu items: styled `<a>` tags with `:hover` highlight
- Toolbar buttons: CSS-only icon buttons (or Unicode/emoji placeholders)
- Window controls (min/max/close): decorative only, CSS circles
- Beveled edges: `box-shadow` insets for that classic Windows look

### Archive Page Modifications
The archive view needs to:
- Group photos by month/year with collapsible tray headers
- Show square thumbnails in a dense grid (smaller than other skins)
- Include a left sidebar panel for category/folder navigation
- Status bar shows "X items" count

This may require a skin-specific archive layout override or a new archive
layout type in the manifest (`archive_layouts: ['picasa']`).

---

## Open Questions

1. **Filmstrip data loading** — Do we AJAX-load adjacent thumbnails or embed
   them in the page? The existing `SNAP_DATA` only has prev/next slugs, not
   a full strip of 10-20 thumbnails.

2. **Left sidebar in archive** — Picasa had a folder tree. We could map this
   to categories + albums. Need to check if archive.php supports a sidebar
   injection point.

3. **Sean has reference screenshots** — Get these uploaded for pixel-accurate
   matching of the toolbar layout, button positions, and colour values.

4. **Responsive behaviour** — Picasa was a desktop app with no mobile view.
   Options: (a) force desktop layout always, (b) gracefully degrade the chrome
   on mobile, (c) hide the chrome on small screens and fall back to simple.

---

## Dependencies

- Skin registry system (done)
- Updater system (in progress — build first)
- Filmstrip JS engine (new)
- Possible archive layout extension for tray grouping
