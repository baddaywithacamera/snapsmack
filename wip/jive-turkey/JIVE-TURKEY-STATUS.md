# JIVE TURKEY — work-in-progress state (SnapSmack GRAMOFSMACK theme)

Updated 2026-07-17. Engine integration done + verified headless. Still standalone
(not wired into a `skins/jive-turkey/` package yet — that's the next step).

## Modes — ALL FIVE APPROVED LOOKS folded into ss-engine-jive-turkey.js
- **SCOPE** — reflection kaleidoscope. ✅ in engine.
- **BLOOM** — quatrefoil flower field. ✅ in engine.
- **FLOW**  — racing-stripe ribbons. ✅ in engine.
- **DAISY** — sunburst rays + floating smiley daisy. ✅ ported in (was prototype-only).
- **REELS** — Bauhaus shuffle grid on dark field. ✅ ported in (was prototype-only).
- **TUBES** — DROPPED per Sean 2026-07-17 ("lines and loops that weren't working").
  Prototype kept (jive-tube-prototype.html) but NOT in the engine.

## Meta-modes
- **CYCLE** — rotates through the enabled modes on a timer, each with its OWN saved
  settings. Colourway can also rotate when random-colour is on.
- **SURPRISE** (NEW, Sean's request 2026-07-17) — on every page load, picks a random
  enabled mode + (with "both barrels" on) a random colourway, runs it with that mode's
  saved settings. Repeat visitors never see the same thing twice. The chosen colourway
  is broadcast on a `jt:colourway` CustomEvent so the border engine matches automatically.
  Owner can either PIN one look (mode + colourway) or switch on SURPRISE.

## Border engine — ss-engine-jive-border.js (NEW)
Shrink-to-0 → expand-back-in-as-next-colour pulse, 5–15px, staggered across the tile
grid as a wave (6 AURORA/PARADE directions). Colour-agnostic: listens for `jt:colourway`
and re-tints itself instantly, so SURPRISE/CYCLE keep borders matched to the background.
Grid row/col inferred from layout (works at any responsive column count).

## Carrier data contract (`.jt-jive-turkey-bg` dataset)
data-jt-mode (scope|bloom|flow|daisy|reels|cycle|surprise), data-jt-colourway (NAME),
data-jt-palette/-field (back-compat single colourway), data-jt-speed, data-jt-cycle,
data-jt-colourways (JSON map of ALL colourways), data-jt-modes (JSON per-mode saved
settings), data-jt-pool (JSON array of modes eligible for cycle/surprise),
data-jt-random-colour ("1" = both barrels), data-jt-border-width/-speed/-wave/-dir.

Colourways (colour tokens are the ONLY place colour lives):
- BARF    cream #efe7cf · #c9b23a #6e7f39 #6b4a2a · centre #c9b23a · dark #40301c
- BLECH   cream #efe3cd · #6a3b86 #dd7328 #c39a3f · centre #c39a3f · dark #33223e
- GROOVY  cream #f2e7d6 · #7b3f9e #e368a4 #3f7cc4 · centre #e368a4 · dark #2b2340
- HARVEST cream #f2e2c0 · #d99a2b #bd4e1f #6b3f24 · centre #d99a2b · dark #38220f

## Remaining integration work (next session)
1. Build `skins/jive-turkey/` — clone AURORA, rename au- → jt-, launch install craptasti.ca.
2. Register both engines in `core/manifest-inventory.php` (require_scripts).
3. Wire the carrier + admin controls in skin-profile.php/manifest.php: per-mode settings,
   colourway picker, SURPRISE / "both barrels" toggle, border controls.
4. Add `.jt-tile` class to the skin's grid tiles so the border engine targets them.
5. Skin `version` + CMS `SNAPSMACK_VERSION` bump to redeploy (Packager).
6. Optional polish: true crossfade between modes in CYCLE (currently a hard switch).

## Files in this bundle
- ss-engine-jive-turkey.js  — background engine, all 5 modes + CYCLE + SURPRISE
- ss-engine-jive-border.js   — tile border engine
- harness.html / render.py    — headless verification harness (dev only, not shipped)
- jive-*-prototype.html        — original approved prototypes (reference)
