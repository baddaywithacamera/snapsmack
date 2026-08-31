# SNAP SLAPPER / desktop handoff — 2026-08-31

## Working state

- Worktree: `C:\dev\snapsmack\.claude\worktrees\release-563d`
- Branch: `dev`
- Latest commit: `3cfcb706 slapper: complete normal effects and refine colour controls`
- Do not overwrite or stage `projects/snapsmack-ca/brass-tacks.php`; it is the user's existing edit.
- `projects/snapsmack-ca/wotcha.php` contains a deliberate, uncommitted rewrite described below.
- `build/` is untracked PyInstaller output and should not be committed.

## SNAP SLAPPER installed/staged builds

- Installed executable: `C:\snapsmack\snap_slapper\SNAP SLAPPER.exe`
- Latest corrected build is staged at:
  `C:\snapsmack\snap_slapper\SNAP SLAPPER.next.exe`
- The staged build includes commit `3cfcb706` changes but has NOT yet replaced the
  installed executable because SNAP SLAPPER was reopened for testing.
- Ask the user to close SNAP SLAPPER, verify no matching process remains, copy
  `.next.exe` over `SNAP SLAPPER.exe`, verify SHA-256 equality, then remove `.next.exe`.

### Included editor fixes

- Normal-mode Effects now exposes, alphabetically:
  1. Clarity
  2. Dehaze
  3. Texture
  4. Vignette
- Split-tone and other colour-picker buttons now use black centres, green outlines
  and green text; hover is solid green with black text. A small colour chip preserves
  the selected colour without turning the entire button into a pastel slab.
- TEACH ME now renders cumulatively through the selected lesson. Holding BEFORE THIS
  STEP removes only the selected step for a real before/after comparison.
- 54 full editor tests passed after the TEACH ME and Clarity/Dehaze fixes. Focused
  tests passed after adding Texture and changing swatches.

## Immediate next feature question

The user asked whether Levels and Curves are adjustment layers and expects HSL,
hue/tint, and vibrance.

Current truth:

- `+ Adjust` creates a generic adjustment layer. When it is selected, Levels,
  master RGB curve, per-channel R/G/B curves, Tint, Saturation, Vibrance, and the
  other rail controls are stored on that layer.
- There are not separate menu commands named “Levels Layer” or “Curves Layer.”
- Colour Mix is incomplete HSL: it has per-colour Saturation and Luminance for
  eight bands, but no per-colour Hue shift. This was disclosed to the user.
- Likely next implementation: add eight `col_hue_*` controls, engine processing,
  project/recipe/LEWK compatibility through the existing adjustment dictionary,
  UI sliders in COLOUR MIX, TEACH ME explanations, and regression tests.

## WOTCHA pending local edit

`projects/snapsmack-ca/wotcha.php` is intentionally modified but not committed.

- LEWK AGAIN remains a separate post and was not scrambled into SNAP SLAPPER.
- The SNAP SLAPPER post was changed from a wall of prose into scannable sections:
  photo manager, everyday editor, advanced workshop, reusable/open work.
- Removed premature “Picasa-style publishing path” language.
- BLOG COPY is described honestly: it currently creates a local collision-safe
  staged copy and manifest; it does not upload or publish.
- PHP lint and diff checks passed.
- Review visually, then commit separately from `brass-tacks.php` when approved.

## CRONOMETER

- Commit `250a0531` corrected CRONOMETER to prefer each profile's full management
  key in `extras.api_key_local`, falling back to the legacy top-level key.
- Corrected executable is installed at `C:\snapsmack\cronometer\cronometer.exe`.
- Live verification recovered 22/24 sites. FOUND TEXTURES and IN STEREO WHERE
  AVAILABLE still rejected their individually stored keys. DISCOVER FLEET should
  refresh them; if 401 remains, those two CMS hub/spoke relationships need reconnecting.

## Naming discussion

- The desktop app currently called THE HUB is confused with CMS hub/spoke roles.
- SMACK CENTRAL and SNAP CENTRAL are already used and cannot be reused.
- THE TOOLSHED was suggested, but the user is still thinking; do not rename yet.

## Important product language

- All app names are ALL CAPS.
- Do not claim Picasa-style publishing until BLOG COPY actually uploads/publishes
  and survives real use.
- Distinguish the desktop launcher from the CMS hub and CMS spokes.

<!-- ===== SNAPSMACK EOF ===== -->
