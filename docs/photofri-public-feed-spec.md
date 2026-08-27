# photofri.day public feed — spec (for Codex to finish)

**Status:** spec + working prototype. Claude built and verified the feed-tile prototype
(embedded below) but **reverted it** so it doesn't ride the 0.7.567 scheduler release.
Codex to implement for real, decide the two open questions, and — critically — **surface it**.

**Owner split:** this is visual/layout + IA, so it's Codex's. Claude did the diagnosis and
the tile prototype; Codex finishes and signs off live.

---

## Why this exists (the requirement, not a nice-to-have)

photofri.day's own FAQ / how-it-works copy promises that people **who are not on the
fediverse can see the activity on the site** — that on-site view is **the hook that makes
them join**. Right now that hook is missing:

- The public board **exists** at `photofri.day/board` (`photochallenge-board.php`, htaccess
  route `^board/?$`). It reads the CMS's own `snap_ap_timeline` via
  `pc_board_ranked()` — **teaser-only**: every tile hotlinks the origin thumbnail and links,
  `rel=canonical`, back to the maker's origin post. No participant image is stored or
  re-hosted (FEDISTRUCTURE §10/§11, SECAUDIT 047).
- **Nothing links to it.** It is not in photofri.day's nav, so a plain web visitor has no way
  to reach it. The hook is invisible.

So the job is two parts: **(A)** make the feed a clean 3-across tile grid, and **(B) surface
it** so visitors actually land on it. (B) is the part that matters most.

---

## Part A — the feed layout (`photochallenge-board.php`)

Sean's spec, verbatim intent:

- **Three across, like classic Instagram.** Fixed 3-column grid, tight gaps.
- **Just the tiles.** No per-tile chrome — drop the rank badge, handle, and boost/hc tag that
  the old board painted under each image. A tile is only the photo.
- **Square. If not square, crop to square.** `aspect-ratio:1/1; object-fit:cover` center-crops
  any landscape/portrait entry into a square tile. (Already how `.thumb` behaves — keep it.)
- **Each tile links back to the post on the originating actor.** Keep the existing
  `<a class="card" href="<origin>" target="_blank" rel="canonical noopener">` wrapper and the
  SECAUDIT-047 scheme guard (only `http(s)` becomes a live href). Maker + caption stay on the
  `title` (hover) so the tile itself is clean but attribution is one hover/click away.

### OPEN DECISION 1 — header
The prototype keeps a **slim header** (kicker · `PHOTO FRIDAY` · OPEN/CLOSED state · a one-line
"post `#PhotoFri<Word>` and follow to join" CTA) and the footer's teaser-only note. Rationale:
that CTA line is the actual join hook. **Sean to decide:** keep the slim header, or strip to
pure tiles with no header at all. If this feed is going to be **embedded** on the home page
(see Part B, option 3), the standalone page's header becomes redundant and pure-tiles is right.

### OPEN DECISION 2 — order
`pc_board_ranked()` returns entries ranked by score (likes + weighted boosts), newest as
tiebreak. A "feed" often reads better reverse-chronological. Decide: keep ranked, or switch the
feed to newest-first (a `pc_board()` chronological read already exists).

### Reference implementation (Claude's prototype — apply or refine)

This is the exact change Claude made to `photochallenge-board.php` and reverted. It lints clean
and matches the existing onyx/garnet palette (red `#D40000` on `#111`). Codex can apply it as-is
and iterate.

```diff
   main { position:relative; z-index:1; flex:1; width:100%; max-width:1180px; margin:0 auto; padding:10px 20px 60px; }
-  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(210px,1fr)); gap:14px; }
+  /* Classic three-across tile feed. Just the squares; each links home to its
+     maker's origin post. No stored image, no per-tile chrome. */
+  main { ... max-width:940px; ... }
+  .grid { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
   .card {
     position:relative; display:block; text-decoration:none; color:var(--ink);
-    background:#0b0b0b; border:1px solid #262626; overflow:hidden;
-    transition:border-color 0.15s, transform 0.15s;
+    background:#0b0b0b; overflow:hidden;
   }
-  .card:hover, .card:focus-visible { border-color:var(--red); transform:translateY(-2px); outline:none; }
+  .card::after { content:""; position:absolute; inset:0; pointer-events:none;
+    box-shadow:inset 0 0 0 0 var(--red); transition:box-shadow 0.15s; }
+  .card:hover::after, .card:focus-visible::after { box-shadow:inset 0 0 0 3px var(--red); }
+  .card:focus-visible { outline:none; }
   .thumb { display:block; width:100%; aspect-ratio:1/1; object-fit:cover; background:#1a1a1a; }
-  /* REMOVE the per-tile meta CSS: .meta / .by / .rank / .rank.gold|silver|bronze / .tag-badge */
```

And in the tile loop, drop the `$rank` computation and the whole `<span class="meta">…</span>`
block, leaving just the `<a class="card">` wrapping the `<img class="thumb">` (or the
`no preview` fallback). Fold handle+excerpt into the card `title`:

```php
<a class="card" href="<origin, scheme-guarded>" rel="canonical noopener" target="_blank"
   title="<handle> — <excerpt>">
    <img class="thumb" loading="lazy" src="<origin thumb>" alt="Photo by <handle>">
</a>
```

**Do NOT touch:** the 404-when-`!pc_enabled` guard, the SECAUDIT-047 scheme guard, the
teaser-only/no-store model, or the EOF marker.

---

## Part B — surface it (the actual fix)

The feed is worthless if nobody can reach it. `core/header.php` builds photofri.day's nav one
of two ways:

- **`nav_menu_json` set** (from the Menu Manager, `smack-menu.php`) → renders from JSON, which
  **does** support `custom` / `external` link items (`_snap_nav_resolve_url`).
- **else legacy** → a flat nav of active static pages ordered by `menu_order`.

Three ways to surface, cheapest → strongest hook:

1. **Menu link (fastest).** Add a Menu Manager **custom link** — label e.g. `THE FEED`, URL
   `/board` — so it appears in the site nav. Pure config, no code. Works only if photofri.day's
   nav is in JSON mode (adding via Menu Manager puts it there). This is the minimum.
2. **Auto nav link (deterministic).** In `core/header.php`, when `pc_enabled($settings)`, always
   inject a board link into the nav (both JSON and legacy paths), so every challenge install
   surfaces its feed without depending on menu config — the same way the board's own topnav
   already links Hall of Fame. Guard strictly on `pc_enabled` so ordinary blogs never show it.
3. **Embed on the landing page (best hook).** Show a strip of the newest tiles **on the home
   page itself**, so a first-time visitor sees live activity immediately — that is the hook the
   FAQ describes. Needs the tile grid extracted from `photochallenge-board.php` into a reusable
   render — a `[photofri_board count="9"]` shortcode (see `core/shortcodes*`) or an includable
   partial — so the CMS home page (`snap_pages` slug `home`, rendered in the ONYX skin) can drop
   it in. Reuse `pc_board_ranked()`; keep teaser-only + scheme guard.

**Recommendation:** do (2) so the feed is always reachable, and (3) for the real hook — a
3-across strip of the latest entries on the landing page with a "see all →" to `/board`.

---

## Constraints / gotchas

- **Teaser-only, forever.** Never store or re-host a participant image. Origin thumbnail is
  hotlinked; the tile links `rel=canonical` to the origin post. Keep the SECAUDIT-047 scheme
  guard on every href.
- **Palette.** The board uses red `#D40000` on `#111`, which equals photofri.day's **Garnet**
  onyx palette — leave it. (If a board is ever embedded in-skin, prefer the skin's `--pfd-red`
  so it tracks the site's stone.)
- **Desktop-first** but don't let the 3-up grid break on a phone (it can stay 3-up — classic
  insta is 3-up on mobile too).
- **EOF markers** on every touched file; run `tools/check-eof.py`. `photochallenge-board.php` is
  a standalone PHP page, not a skin, so `tools/skin-scan.php` does not apply.
- **Related, already flagged (Codex's 566D area):** photofri.day still shows **CRIMSON ONYX** in
  the footer because its `active_skin` is the old `crimson-onyx` slug — re-pick **ONYX** +
  its palette (Garnet) in the admin. And `projects/photofri-day/cms-pages/home.html` hardcodes
  `skins/crimson-onyx/img/...` paths that 404 once the old folder is gone — repoint to
  `skins/onyx/img/...`. If (3) embeds a strip on `home`, fix those paths in the same pass.

## Done / to-do
- **Done (prototype, reverted):** the 3-across tile CSS + per-tile meta removal above.
- **To-do (Codex):** apply Part A + Decisions 1–2; do Part B (at least option 2, ideally +3);
  verify live on photofri.day with real entries once the challenge is out of test mode.
