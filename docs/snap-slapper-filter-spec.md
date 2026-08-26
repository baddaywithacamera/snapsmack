<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical HTML-comment
  SNAPSMACK EOF marker used by this repository.
-->

# SNAP SLAPPER Filter System Specification

**Status:** Approved next editor milestone  
**Written:** 2026-08-24  
**Version target:** SnapSmack `0.7.x`; increment only the final revision  
**Priority:** Before LEWK AGAIN

## 1. Goal

Add a practical photographic filter system to SNAP SLAPPER. Filters must feel like
part of the existing editor rather than one-click destructive gimmicks. A filter is
an editable, non-destructive layer with a live preview, adjustable controls, opacity,
blend mode, mask, history support, project persistence, preset support, and batch use.

The first required filter set is:

1. Orton Effect
2. Film Grain
3. Light Leak
4. Pastel Effect

## 2. Governing rules

- Source photographs are never overwritten by applying a filter.
- EXIF, GPS, ICC profile, DPI, and existing copyright metadata follow the current
  SNAP SLAPPER export invariant and are never silently stripped.
- Filters render locally. No photograph or preview is uploaded to the CMS.
- Every filter remains editable until flattened export.
- A filter can be hidden, reordered, duplicated, removed, masked, and blended.
- Applying, removing, reordering, masking, or changing a filter participates in
  undo/redo history.
- Filters are stored in `.slapper` projects and adjustment recipes.
- Unsupported or failed effects must report an error; they must not silently export
  a different appearance.

## 3. Filter gallery and workflow

Add a `FILTERS` accordion to the editor rail and an `ADD FILTER` control in the layer
area. Opening the gallery shows dark, consistently styled preview tiles generated
from the current photograph. At minimum each tile shows the filter name and an actual
preview, not a decorative stock thumbnail.

Selecting a tile adds one filter layer and opens its controls. The layer list clearly
identifies filter layers and their type. A global Amount control is always available
and defaults to a useful but reversible value. Double-clicking a filter layer reopens
its controls.

Preview rendering should use the existing bounded editor preview. Full-resolution
processing happens only when required for export. Slider movement may use a short
debounce, but the interface must remain responsive and show that a preview is pending.

## 4. Common filter-layer model

Each filter layer stores:

- Stable layer ID and human-readable name
- Filter type and filter schema version
- Filter-specific settings
- Global amount from 0 to 100 percent
- Layer opacity and supported blend mode
- Visibility and ordering
- Optional raster mask
- Creation and last-edit timestamps where useful

Projects must reject unsupported future filter versions with a clear compatibility
message rather than corrupting or discarding the layer.

## 5. Orton Effect

The Orton filter creates a luminous soft-focus treatment by combining a sharp base
with a brightened, blurred copy. It must preserve enough original detail to avoid
looking like a simple blur.

Controls:

- Amount: 0–100
- Glow radius: scaled sensibly to image dimensions
- Glow brightness/exposure
- Glow contrast
- Saturation
- Highlight protection
- Shadow protection
- Blend mode, defaulting to Screen or a visually equivalent implementation

Defaults should produce a restrained photographic glow. Highlight protection must
prevent bright skies and specular highlights from immediately clipping.

## 6. Film Grain

Film Grain adds resolution-aware organic grain rather than static television noise.
The pattern must be regenerated deterministically from a stored seed so a project
renders consistently between sessions and during export.

Controls:

- Amount: 0–100
- Grain size
- Roughness/irregularity
- Softness
- Monochrome or colour grain
- Colour variation when colour grain is enabled
- Shadow response
- Midtone response
- Highlight response
- Randomize seed

Grain scale must remain visually consistent between preview and full-resolution
export. It must not create obvious repeating tiles or change every time the screen
repaints.

## 7. Light Leak

Light Leak overlays a controllable photographic flare or edge leak. The initial
implementation must generate the leak procedurally so no third-party texture licence
is required.

Controls:

- Amount: 0–100
- Position on the photograph
- Edge/origin selection
- Rotation
- Spread/width
- Length/reach
- Softness
- Primary colour
- Secondary colour
- Warmth
- Blend mode, defaulting to Screen
- Optional flare bloom
- Seed/randomize for organic variation

The leak should be directly positionable on the image when the layer is selected.
The overlay must scale with the photograph, remain maskable, and avoid visible hard
rectangular boundaries.

## 8. Pastel Effect

Pastel produces a soft, lifted, low-contrast colour treatment without merely placing
a white veil over the photograph.

Controls:

- Amount: 0–100
- Softness
- Lifted blacks
- Highlight roll-off
- Contrast reduction
- Saturation/colour strength
- Vibrance
- Warm/cool bias
- Fade
- Optional tint colour and tint strength

Defaults should retain recognizable whites and blacks while creating a gentle matte
palette. Skin tones and already pale highlights should not be pushed immediately to
featureless white.

## 9. Masks, brushes, and stacking

Every filter layer uses the same layer-mask system as adjustment and image layers.
White reveals, black hides, and grayscale partially applies the filter. The planned
brush palette—including built-in tips, image tips, and compatible Photoshop sampled
brush tips—must work on filter masks without a separate brush implementation.

Filters can be stacked in any order. Reordering must change the composite predictably.
The result shown in comparison modes, histogram, and exported output must use the same
layer order and settings.

## 10. Presets, recipes, and batch application

A single filter or a stack of filters may be saved as a SNAP SLAPPER preset. Recipes
store settings and deterministic seeds, not rendered pixels. Loading a recipe adds or
restores the editable filter layers.

Batch application always creates new files, uses collision-safe names, preserves the
metadata invariant, and applies only to the explicit batch selection. Existing source
files remain byte-for-byte unchanged.

## 11. Performance and colour handling

- Cache reusable blurred images, masks, gradients, and grain fields where practical.
- Cancel or supersede stale preview work after another slider change.
- Do not perform repeated full-resolution renders while dragging a control.
- Generated overlays and grain must be based on image-space coordinates, not the
  current window size.
- Maintain the existing colour-profile preservation behavior on export.
- Histogram updates reflect the filtered composite without triggering duplicate
  full renders.

## 12. Acceptance checks

The milestone is not complete until all of the following pass:

- Each of the four filters adds an editable layer and visibly changes a test image.
- Every documented control changes the preview in the expected direction.
- Filter Amount at zero is visually identical to the input below the layer.
- Hide, reorder, opacity, blend mode, masks, undo, redo, and history restore work.
- Save/open `.slapper` produces the same filter settings and deterministic appearance.
- Preset save/load and selected-photo batch application work.
- Preview and full export are materially consistent at equivalent zoom.
- Original photograph hashes remain unchanged.
- JPEG, PNG, and TIFF exports preserve required metadata.
- The frozen Windows executable opens the gallery, previews each filter, saves a
  project, exports a filtered copy, and exits cleanly.
- The interface remains dark, maximizable, readable, and responsive with a real photo.

## 13. Deferred filter families

After the first four filters survive hands-on use, the same framework may add cinematic,
vintage, faded, warm/cool, matte, black-and-white film, colour grading, split toning,
bleach bypass, cross-process, infrared-style, diffusion, soft-focus, and high-pass
sharpening filters. These are not part of the first acceptance gate.

## 14. Snapseed-level capability target

SNAP SLAPPER should eventually cover the practical photographic filter territory offered
by Snapseed while using original names, presets, code, visual design, and control layouts.
This is capability equivalence, not pixel-for-pixel preset cloning or imitation of another
application's interface.

Required capability families after the first four-filter milestone:

- **Lens focus:** elliptical, linear, and painted blur regions with transition, blur
  strength, vignette, and highlight controls.
- **Photographic glow:** soft luminous glow with warmth and saturation controls. The
  Orton implementation supplies the foundation but must also support restrained portrait
  and diffusion-style results.
- **Tonal contrast:** separately adjustable highlight, midtone, and shadow detail with
  protection against halos and clipped extremes.
- **Single-image HDR:** local tone mapping with natural through stylized strengths,
  brightness, saturation, smoothing, and halo suppression.
- **Dramatic tone:** gritty or smooth high-impact contrast with brightness, saturation,
  shadow protection, and strength controls.
- **Grunge and distressed texture:** locally generated or properly licensed textures,
  style seed, texture strength, saturation, contrast, and movable texture origin.
- **Colour film looks:** deterministic colour-film profiles combined with the Film Grain
  engine; grain and style strength remain independently adjustable.
- **Vintage colour:** colour response, fade, texture, brightness, saturation, and a
  movable vignette.
- **Retro damage:** film style, scratches, dust, light leaks, brightness, saturation,
  contrast, strength, and deterministic shuffle. This extends the first Light Leak layer.
- **Noir:** darkroom-style monochrome tone, wash, grain, brightness, contrast, and filter
  strength.
- **Black and white:** channel-sensitive monochrome conversion, coloured lens-filter
  simulation, brightness, contrast, grain, and editable starting styles.
- **Frames:** adjustable border width, inset, colour, irregularity, and properly licensed
  or procedurally generated frame textures. Frames remain editable and are applied after
  or before other layers according to their stack position.

Face-aware enhancement is not part of this required filter target. If it is ever added,
it must be an explicit optional capability and must follow the separate product decision
on AI/face processing rather than introducing silent face recognition into the library.

All equivalents inherit the same requirements as the first four filters: non-destructive
layers, masks, stacking, history, deterministic projects, presets, selected-photo batch
application, local processing, responsive previews, and metadata-safe export.

## 15. Perspective correction

Perspective correction is a geometry tool, not a stylistic filter, but it is required for
the intended editor capability level and must be built before claiming Snapseed-level tool
coverage.

Required controls and interactions:

- Vertical perspective/keystone correction
- Horizontal perspective/keystone correction
- Rotation and straighten integration
- X and Y scale
- Aspect correction where needed after transformation
- Four-corner free perspective mode
- Visible grid and draggable on-image handles
- Auto-crop to valid pixels
- Transparent-edge mode for formats that support alpha
- Edge-fill option that clearly identifies generated pixels and can be disabled
- Reset and before/after comparison

Perspective remains non-destructive in the document geometry model, participates in
undo/redo, survives project save/open, renders consistently in previews and exports, and
never changes the source file. Crop coordinates, masks, retouch points, and positioned
overlays must continue to align after the transformation. Automated checks must cover
both keystone axes, four-corner transforms, crop/fill behavior, project round-trips, and
full-resolution export.

<!-- ===== SNAPSMACK EOF ===== -->
