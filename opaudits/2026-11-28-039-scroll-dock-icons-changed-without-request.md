# OPAUDIT 039 — SCROLL dock icons changed without a request

<!-- SNAPSMACK_EOF_HEADER
The last non-empty line must be the canonical SNAPSMACK EOF marker.
-->

## What happened

On `usedcarparts.photoblogs.fyi` (running the **SCROLL** skin), the inline Social Dock icons (`Vero`, `Bluesky`, `Linktree`, `Website`) in the header row rendered as **filled dark circles** (`rgba(0, 0, 0, .7)`) with **solid white glyphs** (`#ffffff`), sitting directly beside SCROLL's own outlined transparent nav chips (`Home`, `Blogroll`, `Info`, `Search`, `Filter`).

Sean did not request any change to SCROLL's header dock appearance. Three commits combined to produce the mismatch:

1. `9b7b972c` (`One precedence for both dock paths, and the admin stops claiming the skin owns it`) switched `core/skin-view-model.php` from skin-first to owner-first via `core/social-dock-appearance.php`. On `usedcarparts.photoblogs.fyi`, an older saved `social_dock_color_mode = 'light'` setting overrode SCROLL's `default_color_mode = 'dark'`, turning `--dock-bg` dark (`rgba(0,0,0,.7)`) and `--dock-icon` white (`#ffffff`).
2. `58e799b8` (`Use solid brand marks in the shared social dock`) and `1c2fde73` (`Draw Vero as a V and remove LinkedIn from the social dock`) replaced the outline brand `<symbol>` paths in `assets/icons/crop-circles.svg` with solid `fill="currentColor"` paths while leaving the five SCROLL nav symbols (`home`, `about`, `blogroll`, `search`, `filter`) as `stroke="currentColor"` line art.

## Why the review missed it

Each change was verified against its own target: `9b7b972c` proved precedence order in unit tests, and `58e799b8` / `1c2fde73` verified solid brand marks in `tests/ss1555-social-dock-outline-regression.php` and ALFRED captures. Neither check inspected the rendered header of a live SCROLL site where `.scroll-sticky-nav .ss-grid-nav-link` sits inline next to `.social-dock-inline .dock-link`.

## Correction and follow-up

Commits `807e66132ed2ac550a5e1e8a6afee977c60b691c` and `1cc8b1688314564dae5ed945eff1ec3e83128f08` (`SCROLL 0.1.83`) pin `.scroll-sticky-nav .social-dock-inline .dock-link` in `skins/scroll/style.css` to SCROLL's exact nav-chip values (`background: transparent; border-color: rgba(26, 26, 26, .3); color: #1a1a1a;`, hover `rgba(255, 255, 255, .95)` / `rgba(26, 26, 26, .7)`), with `1920`/`1440`/`1024` before/after captures committed in `_review/scroll-dock-icons/`. Shared `assets/icons/crop-circles.svg` and other skins were not altered.

### Shared grid-nav / social-dock inventory (NOT CHECKED)

Eleven skins reference the grid-nav engine (`smack-grid-nav` in ten manifests plus `glide` via `grid-nav-links`), and `assets/css/ss-engine-grid-nav.css:100` pins only `width` and `height` on `.social-dock-inline .dock-link`. Only **SCROLL** renders the social dock inline inside the sticky nav row via `crop-circles`; the other ten skins render their own sticky navs with text-link `navigation-tree` and no inline dock:

- `aurora` — text-link sticky nav, no inline dock (**NOT CHECKED** for floating dock vs nav appearance)
- `game-on` — text-link sticky nav, no inline dock (**NOT CHECKED**)
- `glide` — text-link sticky nav, no inline dock (**NOT CHECKED**)
- `heuristic` — text-link sticky nav, no inline dock (**NOT CHECKED**)
- `instant-camera` — text-link sticky nav, no inline dock (**NOT CHECKED**)
- `jive-turkey` — text-link sticky nav, no inline dock (**NOT CHECKED**)
- `parade` — text-link sticky nav, no inline dock (**NOT CHECKED**)
- `sliders` — text-link sticky nav, no inline dock (**NOT CHECKED**)
- `sudden-impact` — text-link sticky nav, no inline dock (**NOT CHECKED**)
- `the-grid` — text-link sticky nav, no inline dock (**NOT CHECKED**)

In addition, two conflicting `--dock-*` default families exist in core: `core/skin-render-helpers.php` defaults light (`transparent` / `rgba(26, 26, 26, .3)` / `#1a1a1a`), while `core/social-dock.php` and `assets/css/ss-engine-social-dock.css` default dark (`rgba(0, 0, 0, 0.7)` / `#fff`). No other skin was modified here; any further alignment awaits Sean's decision.

<!-- ===== SNAPSMACK EOF ===== -->
