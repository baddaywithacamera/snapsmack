<!-- SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below. -->
# SS-1112 — GAME ON carousel arrow geometry

## Scope

Source-build browser verification only. This is not an installed-build or live-site
claim. The fixture uses GAME ON's post markup, the shared slider stylesheet and
engine, and the registered carousel controller with five mixed-orientation images.

## Reproduction

Before the skin repair, the complete-path fixture stopped at its first geometry
assertion because `#tg-carousel` and `.go-post-ig-image` both measured `0px` wide.
The multi-photo panel had only percentage-sized descendants, so the flex item had
no intrinsic width. That also left arrow placement dependent on an unstable panel.

## Result after repair

`tools/browser-tests/game-on-carousel-arrow.cjs` passed at:

- 1920 × 1080
- 1440 × 900
- 1024 × 768

At all three viewports:

- the left and right arrows stayed inside both the slider and viewport;
- document horizontal overflow was zero;
- mouse and keyboard navigation each advanced one slide;
- the active dot and track transform updated; and
- the image-panel dimensions remained unchanged through both interactions.

Exact rectangles and transforms are in `measurements.json`. The PNGs show the
post after mouse and keyboard navigation.

<!-- ===== SNAPSMACK EOF ===== -->
