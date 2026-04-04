# SnapSmack Alpha v0.7.8 — Tablet Responsiveness Audit

**Scope:** Stable skins only.
**Target range:** 1200px and above — large tablets, slates, and iPad Pro class devices in landscape. Anything under 1200px is Photogram's problem and is explicitly out of scope.
**Excluded:** Phones. All of them.

---

## The Actual Situation

Every existing breakpoint across all stable skins sits at or below 1024px. Under the new 1200px boundary, that means **every skin's entire responsive ruleset is in phone territory and irrelevant.** From a tablet perspective, all skins are running their desktop layout at 1200px — no skin has ever been designed with this range in mind.

This is not necessarily a disaster. At 1200px most desktop layouts are functional. The audit below identifies where things actually break or significantly degrade, and where the intent of the design is not met.

---

## Issues by Skin

### 50 Shades of Noah Grey
**No media queries.**

**Archive grid — cropped mode: BREAKS at 1200px.**
The grid is `repeat(var(--grid-cols, 4), var(--thumb-width, 250px))` with `padding: 30px 60px` on the grid container. At defaults: 4 × 250px columns + 3 × 30px gaps + 120px side padding = **1210px minimum**. A 1200px viewport will clip the right column. A user who has cranked column count or thumb size in the manifest even slightly will see worse overflow.

Fix: Add a `@media (max-width: 1400px)` breakpoint that switches the grid to `repeat(var(--grid-cols, 4), 1fr)` — keeping the column count but making widths fluid — or reduces the grid padding.

**100vh snapping engine.**
Safari on iPadOS and Chrome on Android tablets have dynamic browser chrome (toolbars hide on scroll). `height: 100vh` on `#page-wrapper` is calculated from the *static* viewport — when the toolbar collapses, the layout jumps visibly. Photogram already uses `100dvh`. This skin should too.

**Nav layout at 1200px: marginal.**
The header uses `padding: 0 40px` and has both a title and a nav menu in a flex row. At 1200px this is survivable but a long site title + several nav links will crowd. No hard break found — it's a cosmetic risk, not a guaranteed failure.

---

### New Horizon
**No media queries.**

Same snapping engine as 50 Shades; same `100vh` issue applies.

**Archive grid: fine at 1200px.**
The public-facing.css grid engine uses `repeat(var(--grid-cols, 4), var(--thumb-width, 200px))`. 4 × 200px + gaps = ~890px, well within 1200px without padding concerns.

**No other hard breaks found at 1200px.** The fixed 80px header and 60px infobox are proportionally fine at this width.

---

### True Grit
**No media queries.**

Same `100vh` issue.

**Archive grid: not an issue.**
Uses `repeat(var(--grid-cols, 4), 1fr)` — fractional units, inherently fluid. No overflow possible.

**No hard breaks at 1200px.**

---

### Galleria / Hip to be Square
**Highest existing breakpoint: 1024px** (phone territory under new definition).

Same `100vh` on `#page-wrapper` and single-image view. Same fix needed.

**No hard breaks at 1200px.** The frame/mat/image sizing is vw-based on single image view, so it scales naturally into the tablet range. The archive grid uses fixed-column counts but those have been tuned for desktop and work fine at 1200px+.

**Minor:** The 1024px breakpoint drops the archive grid to 3 columns. Since 1024px is now phone territory, tablet users at 1200px+ will always see the desktop column count. That's correct behaviour — no action needed.

---

### Rational Geo
**Highest existing breakpoint: 1024px** (phone territory).

Same `100vh` issue on the snapping engine.

**No hard breaks at 1200px.** The drawer-based layout handles this width comfortably. vw-based padding throughout.

---

### A Grey Reckoning
**Highest existing breakpoint: 768px** (deep phone territory).

`min-height: 100vh` on the landing page — lower-severity instance of the same issue.

**Landing split layout at 1200px: fine.** The two-column split is the intended desktop design, and 1200px is comfortably in that range.

**No hard breaks at 1200px.**

---

### Impact Printer
**Highest existing breakpoint: 1024px** (phone territory).

`height: 100vh` on `#page-wrapper`.

**55px side padding for sprocket holes** — the defining aesthetic of this skin. At 1200px this gives a 1090px content column. That's generous. Fine.

**No hard breaks at 1200px.**

---

## Summary Table

| Skin | Hard break at 1200px | 100vh issue | Notes |
|------|---------------------|-------------|-------|
| 50 Shades | **YES — cropped archive grid** | Yes | Grid overflow at defaults |
| New Horizon | No | Yes | Grid uses 200px thumbs, fits fine |
| True Grit | No | Yes | `1fr` grid is inherently fluid |
| Galleria | No | Yes | |
| Hip to be Square | No | Yes | |
| Rational Geo | No | Yes | |
| A Grey Reckoning | No | Minor | `min-height` not `height` |
| Impact Printer | No | Yes | |

---

## Recommended Fixes

### 1. `100vh` → `100dvh` (all snapping engine skins)
**Priority: High. Affects every skin with a full-viewport snap layout.**

`100dvh` (dynamic viewport height) accounts for collapsing browser chrome on tablet browsers. Supported Safari 16+, Chrome 108+ — which covers every tablet that would land on these sites. Photogram already uses it as the pattern.

Skins affected: 50 Shades, New Horizon, True Grit, Galleria, Hip to be Square, Rational Geo, Impact Printer.

The change is one line per skin on the `#page-wrapper` / equivalent root height declaration. Low risk.

### 2. 50 Shades cropped archive grid at 1200px
**Priority: High. This is the only confirmed hard layout break.**

Add a breakpoint at `1400px` (safely above the 1200px floor) that replaces the fixed pixel column template with `1fr` units at the same column count:

```css
@media (max-width: 1400px) {
    .fsog-archive-grid {
        grid-template-columns: repeat(var(--grid-cols, 4), 1fr);
        padding-left: 30px;
        padding-right: 30px;
    }
}
```

This preserves the column count set by the user in the manifest while making widths fluid. The justified grid is unaffected.

### 3. Touch target sizing (all skins)
**Priority: Medium. Accessibility concern raised specifically by Noah Grey.**

Nav links across all skins have approximately 24–28px effective touch height. WCAG 2.5.5 recommends 44×44px minimum for pointer targets. For users with motor impairments using a tablet, this is the most significant accessibility gap.

Fix pattern: add `min-height: 44px; display: inline-flex; align-items: center;` to `.nav-menu a` and `.nav-links a`. Does not change the visual appearance — just increases the tappable area.

This applies to all eight skins uniformly and could reasonably be added to `public-facing.css` with a `.static-transmission` or body-class scope to avoid affecting the admin UI.

### 4. `@media (max-width: 1400px)` breakpoint tier
**Priority: Low-Medium. Applies to skins where 1200–1400px is worth addressing.**

No skin currently has a breakpoint that speaks to the 1200–1400px band. For most skins this is fine — the desktop layout works. For any skin where the desktop layout was designed assuming 1400px+, a 1400px tier can handle the transition. Currently only 50 Shades needs this (see item 2). Worth bearing in mind for future skin work.

---

## What Does Not Need Fixing

- Existing 1024px and below breakpoints: phone territory, leave them alone.
- Archive grid column counts at 1200px+ on skins other than 50 Shades: all fine.
- Static page card max-widths: at 1200px there is adequate breathing room on all skins.
- The entire 480px–768px band: Photogram.

---

*Audit conducted against SnapSmack Alpha v0.7.8 "Raised Toilet Seat" codebase.*
*Scope revised: tablet = 1200px and above. Phones excluded.*
*Date: 2026-04-04*
