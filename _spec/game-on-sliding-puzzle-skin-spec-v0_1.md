<!--
  GAME ON Skin — Spec v0.1
  SNAPSMACK_EOF_HEADER
      <!-- ===== SNAPSMACK EOF ===== -->
  Last non-empty line of this file MUST match the marker above.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# GAME ON — Sliding Puzzle Showcase Skin — Spec v0.1

**Family:** GRAMOFSMACK / showcase / desktop-first  
**Intended first site:** theschoolofhardnocks.ca  
**Name:** GAME ON  
**Invitation:** “I want to play a game.”  
**Status:** Co-authored concept locked; implementation pending.

## 1. The idea

GAME ON turns the page background into a living field of classic fifteen-piece
sliding puzzles. Each puzzle is a 4×4 square board containing fifteen square
image pieces and one empty position. The photographs come from the site's own
GRAM inventory.

The background puzzles continuously make slow, legal moves. A visitor may click
an unobscured puzzle to promote it into a large modal, take control, and solve
the photograph. Every state must remain solvable. An impossible puzzle is never
generated as a joke, failure state, or difficulty option.

This is a showcase skin: the archive itself supplies the spectacle. The School
of Hard Nocks, with roughly 4,300 images, is the intended first deployment.
PARADE is separately intended to move to allinthewrist.photoblogs.fyi.

## 2. Image selection and repetition

- Fill every visible background puzzle slot from eligible GRAM images.
- With fewer images than slots, repeat images evenly.
- Avoid placing two copies of the same image beside one another when possible.
- Give repeated photographs independent shuffle states and movement schedules.
- As inventory grows, repetition naturally decreases.
- With enough inventory, do not repeat an image in the visible field.
- Rotate the selected set between visits, favouring recent work while reserving
  some positions for archive discoveries.
- Within the modal session, cycle through eligible images without repetition
  until the current selection pool is exhausted; then reshuffle the pool.

## 3. Crops, thumbnails, and full-sized images

The photograph framing must not change when a puzzle moves from the background
to the modal.

### Background puzzles

- Build each small background puzzle from the site's existing square thumbnail.
- Do not download full-resolution photographs for the whole background.
- Slice or position the thumbnail into sixteen consistent square regions.
- Thumbnail requests must reuse the normal image cache and derivative pipeline.

### Foreground modal puzzle

- Load the full-sized image only after the visitor selects that puzzle.
- Apply the exact same stored square crop coordinates used by its GRAM tile.
- Divide that high-resolution square crop into the same sixteen regions.
- The modal shows more detail, never a different composition.
- Do not independently recalculate the crop with `object-fit: cover`.
- If legacy content has no durable crop coordinates, derive one deterministic
  square crop and reuse it for both contexts for that page view. A later shared
  crop-data improvement may persist it, but GAME ON must not silently rewrite
  image metadata merely by being viewed.

After solving, a separate “View full photograph” action may reveal the uncropped
source or open its GRAM post. It does not alter the puzzle while play is active.

## 4. Background field and layout

- Use multiple equal square puzzles across the viewport, not one stretched
  rectangular puzzle.
- Initial desktop composition target: approximately 5 across × 3 down.
- Exact 16:9 coverage may clip outer puzzles at the viewport boundary; never
  distort a square puzzle to force a mathematical fit.
- Responsive layouts use fewer, larger puzzles rather than 144 tiny puzzles.
- Content remains above the puzzle field in its normal readable content panel.
- Puzzles physically covered by the content area are not clickable.
- Exposed portions of partially covered puzzles must not create tiny accidental
  click targets. Apply a minimum visible-area threshold before enabling them.
- Puzzle controls and modal must remain above every decorative background layer.

## 5. Autonomous puzzle movement

Every background puzzle has an independent scheduler:

- wait a random 1–3 seconds between moves;
- choose only a tile orthogonally adjacent to the empty position;
- slide that tile into the empty position;
- vary slide duration approximately 180–450 ms;
- allow natural overlap, so two or three puzzles sometimes move simultaneously;
- do not synchronize the entire field;
- prevent an animation from scheduling a second move on the same puzzle before
  the first finishes;
- pause all autonomous movement while the document is hidden;
- pause the selected puzzle immediately when it enters the modal.

If autonomous moves happen to solve a background puzzle, run the normal solved
sequence, reshuffle it legally while invisible, and resume. Background movement
must never change the user's active modal board.

## 6. Solvability

- Construct a board in the solved state.
- Scramble it exclusively through legal moves into the empty position.
- Use enough moves to avoid trivial states; an initial target is 100–250 legal
  moves, tuned by testing.
- Avoid immediately undoing the preceding shuffle move when alternatives exist.
- Store tile order and empty-position state explicitly.
- Never use an arbitrary permutation unless its parity is validated.
- Every autonomous move, shuffle, restore, and modal transfer must preserve a
  solvable state.

## 7. Opening and playing

Clicking an enabled background puzzle:

1. Freezes that exact board state.
2. Opens a dimmed modal above the site content.
3. Loads the full-sized source and applies the identical square crop.
4. Reconstructs the same tile order at higher resolution.
5. Shows “I want to play a game.” briefly, then lets the line recede.

The modal puzzle occupies up to 80% of the viewport, constrained by the smaller
viewport dimension so it remains square. It must remain playable without
covering essential close/navigation controls.

Input:

- click or tap an adjacent tile to move it;
- swipe toward the empty space where unambiguous;
- arrow keys;
- optional WASD equivalents;
- only legal tiles expose enabled controls;
- focus state and keyboard operation must be fully visible.

Closing the modal preserves the board state when it returns to the background.
The modal includes an explicit close control and supports Escape. It may include
an optional “New puzzle” action; this must clearly disclose that it abandons the
current solve.

## 8. Modal session and solved sequence

The modal is a continuing play session rather than a one-shot viewer.

When the visitor solves a board:

1. Lock input and show the intact photograph.
2. Hold long enough to enjoy it (initial target: two seconds).
3. Pulse slowly exactly twice.
4. Fade the solved image out.
5. Choose the next image and construct a legally shuffled board while invisible.
6. Fade the new unsolved puzzle in.
7. Unlock input and begin the next solve.

“Again?” may appear during the transition. No sound, horror effects, threats,
countdown peril, gore, or trap imitation belong in the default skin. The phrase
is an invitation, not a hostile interaction.

## 9. Scoreboard

Show a compact scoreboard with the modal puzzle:

- current elapsed time;
- current move count;
- puzzles solved this session;
- fastest completed solve this session;
- average completed solve time this session.

Rules:

- Start the clock on the visitor's first legal move, not when the modal opens.
- Pause timing while the document is hidden.
- Stop timing immediately on the solved state.
- Do not include abandoned puzzles in the average.
- Keep session statistics in memory or session storage only by default.
- Do not require an account, cookie, server write, or public leaderboard.
- The scoreboard is not part of the photograph's accessible description.

## 10. Solved pulse and accessibility

Reuse the HEURISTIC skin's established pulse setup and safety treatment rather
than inventing a new flash system. The reference implementation is the
`he-screen-pulse` behaviour and its associated motion protections.

- Exactly two slow pulses for a solved puzzle.
- Remain below the three-flashes-per-second threshold.
- Honour `prefers-reduced-motion` and the same HEURISTIC accessibility policy.
- Share or extract the underlying implementation where practical; do not create
  a subtly different copy whose timing can drift.
- Decorative background puzzles are hidden from assistive technology.
- The active modal is a labelled dialog with an accessible board description,
  instructions, status announcements, close control, and predictable focus.
- Reduced-motion visitors still get a satisfying solved acknowledgement using
  the HEURISTIC-approved safe behaviour, followed by the next solvable state.

## 11. Independent sliding border field

Border colour is a skin-level system independent of photograph selection,
puzzle state, puzzle movement, and modal completion.

- Render a continuous repeating border-colour field across the puzzle grid.
- The colour field slides left, right, up, or down.
- Horizontal travel is the common state; vertical travel appears occasionally.
- Movement wraps seamlessly at field edges.
- Direction changes ease rather than snapping.
- Do not assign a permanent colour to an individual photograph or puzzle.
- The border system does not react to puzzle moves or solved states.
- The modal may retain a neutral frame; the solved pulse remains governed by
  HEURISTIC, not by the ambient border engine.

Selectable palettes:

### Electric

- Acid chartreuse `#B7FF00`
- Hot magenta `#FF2B9D`
- Electric cyan `#00D9FF`
- Deep violet `#5A25B5`

### Film Box

- Kodak yellow `#F6C515`
- Film red `#D82C2C`
- Faded cyan `#36A8B8`
- Darkroom violet `#563B70`

### Monochrome

- Paper white `#F2F0E9`
- Silver `#B7B7B2`
- Graphite `#626262`
- Near black `#161616`

### Automatic

- Choose one complete palette once per visit/session.
- Do not blend colours across palette families.
- Keep the chosen palette stable during navigation for that session.

## 12. Performance contract

This skin must remain smooth despite a large inventory and many moving pieces.

- Small puzzles use square thumbnails only.
- Full-sized data is fetched only for the one selected modal puzzle.
- Do not create a separate image request for every one of the fifteen pieces;
  reuse one image resource with background positioning or equivalent clipping.
- Pause offscreen puzzles with IntersectionObserver or an equivalent mechanism.
- Pause all engines on `document.hidden`.
- Cap simultaneous tile transitions naturally through independent scheduling;
  avoid a global tick that wakes every puzzle each frame.
- Animate transform and opacity where possible; avoid layout thrashing.
- No external CDN, tracking library, game framework, or heavyweight canvas engine.
- The feed and navigation remain usable before the puzzle engine initializes.
- Engine failure degrades to a still thumbnail background and ordinary GRAM UI.

## 13. Admin controls

Minimum skin settings:

- Background puzzles: Off / Still / Moving.
- Border palette: Electric / Film Box / Monochrome / Automatic.
- Border direction preference: Automatic / Horizontal / Vertical.
- Motion density: Calm / Normal / Busy, with Normal matching the 1–3-second
  independent cadence.
- Archive mix: Recent / Balanced / Deep archive.
- Modal “View full photograph” link: On / Off.

Defaults for the intended showcase deployment:

- Background puzzles: Moving.
- Border palette: Automatic.
- Border direction: Automatic.
- Motion density: Normal.
- Archive mix: Deep archive.
- View full photograph: On.

## 14. Privacy and content integrity

- No score telemetry leaves the browser.
- Do not modify posts, image records, crop metadata, or ordering while browsing.
- Respect unpublished/private content rules; draw only from photographs eligible
  for the public GRAM surface.
- Preserve ALT text and the link to the source post independently of decorative
  puzzle rendering.
- Never infer permission to expose an original file when the public site is
  configured to serve derivatives only.

## 15. Acceptance tests

1. Every generated board is solvable across a large randomized test sample.
2. A background board promoted to the modal retains the exact tile order.
3. Background thumbnail and modal full-sized crop match exactly in framing.
4. The background loads thumbnails, not all full-sized originals.
5. Modal opening triggers only the selected photograph's full-sized request.
6. Autonomous loops move every 1–3 seconds independently and sometimes overlap
   without synchronized mass movement.
7. Covered puzzles are not clickable; exposed eligible puzzles are.
8. Keyboard, pointer, touch, focus, and Escape behaviour work.
9. Solve detection fires once, records one result, pulses exactly twice, fades,
   legally reshuffles, and presents the next image.
10. Timing starts on the first legal move and excludes abandoned puzzles.
11. Repeated images are distributed when inventory is smaller than the field;
    repetition disappears when inventory is sufficient.
12. Electric, Film Box, Monochrome, and Automatic border modes work independently
    of puzzle movement.
13. Reduced-motion behaviour matches HEURISTIC's safety policy.
14. Hidden tabs and offscreen puzzles stop consuming animation work.
15. Engine failure leaves the site readable, navigable, and photograph links
    usable.

## 16. Implementation boundaries

- Build GAME ON as its own GRAMOFSMACK skin and namespaced asset set.
- Prefer shared helpers for crop coordinates, inventory retrieval, modal focus,
  and HEURISTIC's safe pulse rather than forking behaviour.
- Do not alter PARADE as part of this build; moving PARADE to another site is an
  operational skin-selection task.
- Do not deploy directly to The School of Hard Nocks until local/prototype and
  test-site performance, solvability, accessibility, and fallback checks pass.
- Include the repository's required Thomas Clause/Easter egg during build.

*Co-authored by Sean McCormick and Codex — SMACK PUBLIC LICENSE.*

<!-- ===== SNAPSMACK EOF ===== -->
