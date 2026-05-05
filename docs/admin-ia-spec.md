# SnapSmack Admin UI — Information Architecture Spec
*Captured 2026-05-04. Do not commit to git — working document.*

---

## 1. Background / Problem Statement

Admin features have been added incrementally with little thought to where they belong. The result:

- Some settings pages are nearly empty (Global Vibe)
- Some pages are over-stuffed with unrelated things (Archive Appearance has floating gallery config; Skin has archive frame options)
- Some features exist in the codebase but have no on/off switch exposed in the UI (calendar)
- Some features were built but appear to have been lost in a truncation/git-restore incident (nav menu drag-and-drop with nesting)
- Dead settings are saved to DB but consumed nowhere (archive_border_style, archive_shadow_depth in Archive Appearance → TILE BORDER & SHADOW)

---

## 2. Current Sidebar Structure (Pimp Your Ride section)

Pages currently under "Pimp Your Ride":
- Global Vibe (`smack-globalvibe.php`) — thin; has admin theme, masthead/logo, sticky header
- Smooth Your Skin (`smack-skin.php`) — over-stuffed; skin options + archive frame + blogroll
- Pimpotron (`smack-pimpotron.php`) — pimpmobile only
- Social Dock (`smack-social-dock.php`)
- Smack Your CSS Up (`smack-css.php`)
- Smack Your Scripts Up (`smack-scripts.php`)
- Archive Appearance (`smack-appearance-archive.php`) — has layout/grid + dead border/shadow + floating gallery
- Solo Image Appearance (`smack-appearance-solo.php`)
- Static Page Appearance (`smack-appearance-static.php`)

---

## 3. Confirmed Issues to Fix

### 3a. Archive Layout Toggle — hard-wired, ignores admin settings

**Bug:** Both `50-shades-of-noah-grey/archive-layout.php` and `rational-geo/archive-layout.php` render a hard-wired 2-button toggle (cropped / justified). This ignores `$available_modes` computed by `archive.php` and ignores admin settings entirely. The toggle appears even when only one layout is enabled.

**Fix:** Replace the hard-wired button pair with a loop over `$available_modes` (already in scope when archive.php includes the skin file). Keep the skin's CSS classes and visual style exactly. If `count($available_modes) === 1`, render no toggle. If `croppedwithcalendar` is in `$available_modes`, render a third "Cal" button between cropped and justified.

`archive.php` already has its own correct toggle (reads `$available_modes`, handles Cal, conditionally renders) — this one should be **removed** since the skin toggle will replace it correctly. Or repurposed as fallback for skins without `archive-layout.php`.

### 3b. Calendar — no visible on/off switch

**Bug:** The `croppedwithcalendar` layout option exists in `smack-appearance-archive.php` (appears in DEFAULT LAYOUT dropdown and layout switch checkboxes when `$skin_has_calendar` is true). But because the skin's toggle is hard-wired (see 3a), selecting it has no visible effect on the visitor-facing toggle. User cannot find the switch.

**Fix (two-part):**
1. Fix the toggle (3a) — Cal button appears automatically once croppedwithcalendar is in available_modes.
2. Rename/clarify the Archive Appearance UI: the calendar option should be more visibly labelled and accompanied by a "CALENDAR MONTHS" slider (1–3, stored as `calendar_months`, default 1) that appears when croppedwithcalendar is checked or selected as default. This slider controls how many months render in the calendar panel.

**Current calendar engine status:** `ss-engine-calendar.js` exists and activates on body class `archive-layout-croppedwithcalendar`. `api-calendar.php` presumably exists. Declared in manifest via `require_scripts[] = 'smack-calendar'`. 50-shades and rational-geo both declare it. **Needs live test once toggle is fixed.**

### 3c. Dead settings in Archive Appearance — TILE BORDER & SHADOW box

`archive_border_style` and `archive_shadow_depth` are saved to DB by `smack-appearance-archive.php` but consumed nowhere in the codebase. No skin reads them. No CSS is generated from them.

**Fix (Option C as discussed):** Remove the TILE BORDER & SHADOW box from Archive Appearance entirely. The per-skin archive frame styling (`archive_frame_style` in skin manifests) is the working control. Move `archive_frame_style` in the 50-shades manifest from `'section' => 'VERTICAL LOCKS'` to `'section' => 'ARCHIVE'` so it's findable.

### 3d. Floating Gallery (Wall) — wrong page

Wall configuration (rows, gap, friction, drag weight, reflection, background colour) lives in Archive Appearance. It belongs in **Global Vibe** since it's a site-wide visual feature, not an archive layout feature.

**Fix:** Move all wall settings from `smack-appearance-archive.php` to `smack-globalvibe.php`. Archive Appearance keeps only a "SHOW WALL LINK" on/off. Global Vibe gains a "FLOATING GALLERY" section.

**Wall in all skins:** Currently `supports_wall` is `true` only for 50-shades-of-noah-grey and new-horizon. The wall engine (`ss-engine-wall.js`) is skin-agnostic. Flip `supports_wall` to `true` in: rational-geo, photogram, kiosk, impact-printer, true-grit. Galleria needs separate assessment (has its own texture-based wall assets and `htbs_wall_texture` manifest option — may conflict).

### 3e. API Key UI on smack-settings.php — broken layout

The API key field and copy button are badly proportioned — button takes ~95% width, key field ~5%. Quick HTML/CSS structural fix in `smack-settings.php`. The row should be: `[key field — flex-grow] [COPY button — fixed width] [REGENERATE] [REVOKE]`.

### 3f. Blogroll — placement TBD

**Current state:** Blogroll layout options (columns, max-width, gap, show desc, show url) are in skin manifests under `'section' => 'BLOGROLL'`. They compile into per-skin `custom_css_public`. They are identical across all skins.

**Options:**
- **A (quick):** Leave CSS pipeline alone, create a dedicated Blogroll section in Global Vibe that reads blogroll options from the active skin's manifest. UI is centralized, plumbing unchanged.
- **B (clean, more work):** Move blogroll options out of skin manifests entirely. Compile blogroll CSS to a global blob (`custom_css_global` or similar). Skins no longer declare blogroll options.

**Decision pending.** Leaning toward A for now, B as a future refactor.

### 3g. Missing Feature — Nav Menu Editor (drag-and-drop with nesting)

**Status: Believed lost in truncation/git-restore incident.**

A drag-and-drop nav menu editor with nesting support was previously built and lived under Pimp Your Ride. No `smack-nav.php` or `smack-menu.php` exists in the current codebase. No sortable/drag JS engine exists in `assets/js/`. The nav link ordering/nesting configuration slots were supposed to disappear from skin config settings once the editor was in place — unclear if that happened before the loss.

**Action required:**
1. Search git log for last known state of the nav editor
2. Determine what was lost vs. what was never finished
3. Re-spec the feature before rebuilding: nested nav (depth limit?), drag-and-drop reorder, per-link label/URL/visibility overrides, show/hide from skin config

---

## 4. Proposed Information Architecture (Target State)

### Global Vibe
- Admin theme
- Global branding / masthead / logo
- Sticky header
- **[NEW] Floating Gallery (Wall)** — rows, gap, friction, drag weight, reflection, background colour
- **[TBD] Blogroll** — if Option A or B is chosen

### Smooth Your Skin
- Skin selector
- Per-skin visual options (typography, colours, header/nav, footer, vertical locks)
- **[NEW] ARCHIVE section** — archive frame style (moved from VERTICAL LOCKS)
- Blogroll section **removed** if moving to Global Vibe

### Archive Appearance
- Grid Architecture (layout default, layout switch offer, thumb size, columns, gutter, justified row height)
- **[NEW] Calendar** — explicitly labelled on/off, months slider (1–3), only shown when skin supports calendar
- **[REMOVED] TILE BORDER & SHADOW** — dead settings, remove entirely
- **[REDUCED] Floating Gallery** — keep only "SHOW WALL LINK" on/off; full config moves to Global Vibe

### [NEW] Nav Menu Editor (to be rebuilt)
- Drag-and-drop reorder
- Nesting (dropdown menus)
- Per-link: label, URL, visibility toggle
- Lives under Pimp Your Ride

### Settings (Boring Ass Stuff)
- API Key UI fix — field/button layout corrected

---

## 5. Implementation Order (Proposed)

1. **API key UI fix** — self-contained, 15 min
2. **Archive toggle fix** — unblocks calendar visibility, affects 2 skin files
3. **Calendar months slider** — small addition to Archive Appearance
4. **Remove dead TILE BORDER & SHADOW box** — Archive Appearance cleanup
5. **Move archive_frame_style to ARCHIVE section** in 50-shades manifest
6. **Move wall config to Global Vibe** — move settings + update Archive Appearance
7. **Enable wall in remaining skins** — flip supports_wall flag
8. **Blogroll** — decision + implementation
9. **Nav menu editor** — investigate git history first, then re-spec

---

## 6. Open Questions

- Galleria wall: does `htbs_wall_texture` conflict with the standard wall engine? Needs investigation before flipping `supports_wall`.
- Nav menu nesting: depth limit? 1 level (dropdown only) or arbitrary?
- Blogroll: Option A or B?
- Calendar months slider: does the calendar JS already support multiple months, or does that need to be built too?
