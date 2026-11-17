# Scrollbar phase-one proof — SS1257

WIP on codex/subtle-scrollbars. Not part of 990D. The version policy requires a distinct skin version for changed files: NEW HORIZON is 1.3.36 here, following 990D's 1.3.35. No core release number is assigned; this branch is not package-ready until review.

Shared CSS is opt-in. NEW HORIZON marks body and its primary #scroll-stage. No other skin opts in. Nested controls retain automatic native scrollbar treatment. Dark thumb #3b3b3b, hover #555555, active #666666; light thumb #bbbbbb, hover #999999, active #888888. Track and corner are transparent. Chromium hit area is 12px with a 6px painted thumb. Firefox uses normal native width and scrollbar-color; it cannot express separate hover colours through that standard property.

The fixture embeds production skin/base/variant CSS around an explicitly long primary surface and an independent nested scroller. This isolates scrollbar behavior; it is not a full archive/render parity claim. Before uses the base CSS from 3675add9; after uses the WIP CSS and explicit opt-in classes. The browser must ignore the default headless --hide-scrollbars switch to measure the real draggable gutter.

Twelve Chromium state/variant/width captures: 1920, 1440, 1024; dark/light; before/after. After checks verify computed colour/width, wheel scrolling, actual thumb dragging, and unchanged native nested-control colour. Screenshots were visually inspected at 1024 for both variants. Chromium hover colour and retention of that highlight while the mouse is pressed are checked. Chromium exposes the hover colour during native thumb dragging; the distinct declared :active shade is NOT CHECKED. Firefox runtime unavailable: NOT CHECKED. Installed owner settings, admin lifecycle and owner acceptance: NOT CHECKED.

Run: node tools/browser-tests/subtle-scrollbars.cjs OUTPUT_DIRECTORY

The 30-skin default fixture inventory is provisional. Transparent and textured backgrounds are explicitly unresolved. Other variants, owner settings and per-skin primary surfaces need review before phase two. No broader packaging decision is inferred.

<!-- ===== SNAPSMACK EOF ===== -->
