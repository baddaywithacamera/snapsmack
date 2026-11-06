<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF
  marker for this file type: an HTML comment containing five
  equals, space, the literal string 'SNAPSMACK EOF', space, five
  equals.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# 0.7.978D — what to check on a real site

Eight things in this build have never been seen on a live install. Each one
below is: where to click, what to set, what to look at, and what wrong looks
like. Stop at the first FAIL and tell me — do not work around it.

All eight need the build installed first. None of them needs more than one
site, except numbers 7 and 8, which need the hub.

---

**1. IMPACT PRINTER archive thumbnails — Pixhellated**

Admin → **Archive Appearance** → THUMB STYLE → **SQUARE CROP (1:1)** → SAVE
ARCHIVE APPEARANCE. Then open `/archive`.

- PASS: every tile is the same square, in even rows.
- Switch to **NATURAL ASPECT** and save: tiles now differ in height.
- FAIL: nothing changes between the two, or the insets down the left and right
  of the grid are uneven.

**2. 52-CARD-PICKUP archive tiles**

No setting. Activate the skin, open `/archive`.

- PASS: each photo sits on a white card with a thin shadow, and lifts slightly
  when you hover it.
- FAIL: bare photos, no white card, no shadow, no hover.

**3. GALLERIA square crop**

Admin → **Skins** → GALLERIA → **IMAGE CROP** → Force Square Crop → **Yes
(Square)** → save. Open `/archive`.

- PASS: every thumbnail is square inside its frame. The frame, mat and bevel
  are unchanged — only the picture's shape changes.
- Set it back to **No (Original Aspect)**: thumbnails return to their own
  proportions.
- FAIL: no difference between Yes and No.

**4. NEW HORIZON thumb style**

Admin → **Archive Appearance** → THUMB STYLE → switch between the two → save
each time. Open `/archive`.

- PASS: SQUARE CROP gives even square tiles; NATURAL ASPECT gives mixed
  heights, and portraits stay portrait.
- FAIL: no difference. This skin ignored the setting before this build, so
  that is exactly the fault being checked.

**5. GUTTER slider**

Use a skin that sets its own spacing, so this proves yours wins: **AURORA,
GAME ON, HEURISTIC, INSTANT CAMERA, JIVE TURKEY, PARADE, SLIDERS, SUDDEN
IMPACT** or **THE GRID**.

Admin → **Archive Appearance** → GUTTER → drag to **4** → save. Open
`/archive`. Then set it to **24** and save, and look again.

- PASS: the gaps between tiles visibly tighten at 4 and open up at 24.
- FAIL: the gaps look the same at both, or they stay at roughly 50px. 50px was
  the old broken default — if you see that, your value is not arriving.

**6. Blogroll order**

Admin → **Blogroll** → edit a peer → **Order Within Category** → type **1** →
UPDATE PEER. Give a second peer in the same category **2**. Open `/blogroll`.

- PASS: those two appear in your numbered order, ahead of the peers still at 0,
  inside their category.
- Leave everything at 0: the list is alphabetical, exactly as before.
- FAIL: your numbers change nothing, or the admin list and the public page
  disagree.

**7. MY BLOGS last — needs a Push**

On the **hub**: Admin → **Blogroll Sync** → PUSH → **PUSH n ENTRIES**. Then
open `/blogroll` on a **spoke**.

- PASS: your own sites are the **last** group on the page.
- FAIL: that group is still in the middle, alphabetically. If so, check the
  Push actually reported success — a spoke learns nothing until the hub pushes.

**8. Fleet skin push includes the hub**

On the **hub**: Admin → **Multisite Management** → the skin update row →
**PUSH TO ALL SPOKES**.

- PASS: the results list includes the **hub itself**, not only the spokes, and
  the hub ends on the same skin version as everyone else.
- FAIL: the hub is missing from the results, or is left on the old version.
  That was the fault; the hub was the one site the push never reached.

---

## If something fails

Tell me which number and what you saw. Do not change settings to make it look
right — a control that needs persuading is still broken.

**What nobody has checked at all:** every item above. The code is verified and
the geometry has been measured in a browser on fixtures, but **no one has
saved any of these in a real admin and reloaded a real page.** That is what
this list is for.

<!-- ===== SNAPSMACK EOF ===== -->
