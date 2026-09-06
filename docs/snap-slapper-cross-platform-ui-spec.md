<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical .md EOF marker:
  an HTML comment containing five equals, space, 'SNAPSMACK EOF', space, five equals.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# SNAP SLAPPER Cross-Platform UI and Layer Workspace Specification

**Status:** In progress — layer foundation built locally, pending release  
**Date:** 2026-08-27  
**Applies to:** SNAP SLAPPER desktop editor on Windows and Linux

## 1. Decision

SNAP SLAPPER will retain its Python/Tk foundation for the current product cycle and
build a small application-owned UI system on top of Tk and ttk. The application will
not imitate Windows, GNOME, KDE, Cinnamon, XFCE, or another desktop environment.

The operating system owns the outer window, file dialogs, clipboard, installed-font
discovery, display scaling, and ordinary accessibility integration. SNAP SLAPPER owns
the editor's internal visual language, component geometry, icons, interaction states,
and workspace behavior.

The existing native Tk layer listbox must be replaced before text layers, layer
transforms, linking, or additional mask features are considered complete.

## 2. Goals

- Present the same understandable editor on Windows, X11, and Wayland.
- Make every control's target, state, and consequence visible before interaction.
- Preserve keyboard, mouse, touchpad, and pen workflows.
- Support high-DPI and fractional-scale displays without clipped text or controls.
- Keep `.slapper` projects non-destructive, portable, and recoverable.
- Improve the interface incrementally without rewriting the editor or compositor.
- Avoid desktop-theme dependencies and platform-specific visual forks.

## 3. Non-goals

- Pixel-for-pixel imitation of Photoshop, Paint.NET, Photopea, or Lightroom.
- A Qt, Electron, GTK, or webview rewrite during this implementation cycle.
- Custom replacements for secure native file and directory dialogs.
- Reliance on a particular Linux desktop, icon pack, compositor, or font package.
- Live web dependencies for the editor's normal operation.

## 4. UI foundation

Create a `slapper_ui` package containing reusable components rather than styling each
screen independently:

- `SlapperTheme`: colours, typography, spacing, radii, borders, focus rings, and scale.
- `SlapperButton` and `SlapperIconButton`.
- `SlapperField`, `SlapperNumberField`, and `SlapperSelect`.
- `SlapperSlider` with keyboard adjustment and a direct-entry value field.
- `SlapperPanel`, `SlapperAccordion`, and `SlapperToolbar`.
- `SlapperScrollArea` with wheel, touchpad, keyboard, and autoscroll support.
- `SlapperTooltip` and concise persistent help text.
- `SlapperDialog` with predictable primary, secondary, and destructive actions.
- `SlapperLayerPanel`, `SlapperLayerRow`, and `SlapperThumbnail`.

Components must expose semantic state—normal, hovered, pressed, focused, selected,
disabled, warning, and destructive—without depending on colour alone.

All geometry must derive from theme tokens. Screens must not introduce magic sizes or
private colour values unless the value represents image content, such as a histogram
channel or mask overlay.

## 5. Scaling and typography

- Read Tk's effective display scaling at startup and when a window changes display.
- Support at least 100%, 125%, 150%, 175%, and 200% UI scaling.
- Use logical dimensions multiplied by the effective scale; do not scale stored image
  coordinates or project values with the UI.
- Provide Small, Standard, and Large interface-density preferences.
- Select a bundled, metrically predictable interface font when licensing permits;
  otherwise use an ordered cross-platform fallback list.
- Measure text before fixing component heights. Labels may wrap; controls may not clip.
- Icons must remain sharp at each supported scale and include a text alternative.

## 6. Workspace layout

The editor workspace contains:

1. A top command bar for project, undo/redo, comparison, zoom, save, and export.
2. A tool bar for canvas tools and their immediate settings.
3. A central image canvas.
4. A filmstrip that can be collapsed.
5. A right inspector containing Layers and context-sensitive Properties.
6. A persistent status bar for the active tool, target, zoom, and background work.

The Layers panel appears before adjustment properties. Selecting a layer or its mask
changes the Properties target explicitly. The inspector header must always state a
phrase such as `EDITING: ADJUSTMENT — SKY` or `EDITING MASK: SKY`.

Panels may be resized and collapsed. A narrow window may stack or hide secondary
panels, but must never overlap the canvas or make primary actions unreachable.

## 7. Layer panel replacement

Replace the listbox with a scrollable collection of custom layer rows. Each row uses
this logical order:

`visibility | link | content thumbnail | mask thumbnail | name and type | lock`

Requirements:

- Content and mask thumbnails are separate selectable targets.
- The active target receives a high-contrast border and focus ring.
- Selection remains visible when keyboard focus moves to Properties.
- Layer types display clear icons and text: Base, Image, Adjustment, Text, Group, and
  unavailable/unknown.
- Mask thumbnails render the actual grayscale mask, not a generic mask icon.
- A layer without a mask shows an explicit empty mask slot or Add Mask action.
- Visibility, link, and lock states are visible without opening a menu.
- Missing images, unavailable operations, and missing fonts show durable warnings.
- Rename is available from F2, double-click on the name, and the context menu.
- Duplicate, remove, merge/apply where supported, and mask operations live in the
  context menu and accessible keyboard commands.

## 8. Layer and mask targeting

Clicking the content thumbnail selects layer content. Clicking the mask thumbnail
selects the mask. The border, inspector heading, canvas overlay, and available tools
must agree about the selected target.

When a mask is selected:

- Brush, gradient, colour range, and outline tools edit the mask.
- A red overlay may be toggled; red means masked/hidden.
- The mask can be viewed directly as grayscale.
- White Mask, Black Mask, Invert, Disable, Delete, Apply, and Unlink are explicit.
- Delete Mask never deletes the layer.
- Painting the image accidentally while the mask is targeted must be impossible.

When layer content is selected, mask tools either switch to the mask deliberately or
offer Add Mask. They must not silently paint an unrelated target.

## 9. Dragging, ordering, transforms, and linking

### Stack dragging

- Dragging a row reorders the layer stack.
- Show an insertion line before committing the move.
- Autoscroll when dragging near the top or bottom of the panel.
- Escape cancels the drag.
- Reordering is one undoable operation.
- Locked layers may be reordered but not modified unless the lock policy says otherwise.

### Canvas dragging

Image and text layers store non-destructive transforms:

- position X/Y;
- scale X/Y, with proportional lock;
- rotation;
- optional flip X/Y;
- transform origin.

Dragging the selected layer on the canvas changes position. Transform handles provide
scale and rotation. Arrow keys nudge one logical pixel; Shift uses a larger step.

### Mask linking

Each mask has a `linked_to_layer` flag. When linked, content transforms also transform
the mask. When unlinked, the mask can be positioned and transformed independently.
The chain state is visible in the row and preserved in the project.

### Layer linking

Layers may share a stable link-group identifier. Moving or transforming one linked
layer applies the same transform delta to every unlocked member. Linking does not
merge layers, change stack order, or share adjustments. Removing a layer safely removes
only that member from the group.

## 10. Mask tools

- **Brush:** size, hardness, opacity, flow, Hide/Reveal, and cursor preview.
- **Linear Gradient:** drag start/end, reverse, feather, and live handles.
- **Radial Gradient:** centre, radius, aspect, rotation, reverse, and feather.
- **Colour Range:** sampled colour, add/subtract samples, tolerance, feather, and preview.
- **Outline/Lasso:** click polygon or freehand mode, close/cancel, feather, add, subtract,
  intersect, and anti-alias.
- **Mask Overlay:** red by default, adjustable colour and opacity for accessibility.

Every operation must produce one undo step and update the row thumbnail promptly. Full
resolution may render after release; interaction may use a bounded preview mask.

## 11. Text layers

Add a first-class editable `text` layer. It must not be rasterized merely by saving a
project. Store:

- UTF-8 text content;
- font family, resolved face, style, and font-file identity;
- size, fill colour, and opacity;
- alignment, line spacing, and character spacing;
- text-box width and wrapping behavior;
- position, scale, rotation, and transform origin;
- stroke colour/width, shadow, and optional background;
- blend mode, visibility, lock, mask, and link-group identifier.

Double-clicking a text layer or its canvas content enters text editing. Text properties
appear only when a text layer is selected. The layer remains editable after reopening.

## 12. Fonts and Google Fonts

Support three font sources:

1. Fonts installed on the operating system.
2. User-imported `.ttf` and `.otf` files stored in an application font library.
3. Google Fonts explicitly downloaded by the user through a font browser.

Google Fonts must not be fetched silently when a project opens. A chosen face is
downloaded, cached locally, and recorded by family, style, version/hash, source URL,
and licence. Project portability options may embed redistributable font files when the
licence permits.

If a font is missing, retain the requested identity, show a warning, offer Locate,
Download, or Substitute, and never silently save a substitute as though it were the
original face.

The font browser and existing projects must remain usable offline after required fonts
have been downloaded.

## 13. Zoom, pan, and input

- Visible Zoom Out, percentage, and Zoom In controls remain in the command bar.
- Fit displays the actual fitted percentage.
- Mouse wheel and touchpad pinch zoom around the pointer position.
- Space-drag temporarily pans regardless of the selected editing tool.
- Middle-button drag pans where supported.
- Keyboard shortcuts support plus/minus, numeric keypad, Fit, and 100%.
- Zoom is clamped to a documented safe range and never changes export resolution.
- Gestures degrade gracefully when a Linux compositor does not expose pinch events;
  Ctrl+wheel and visible controls remain available.

## 14. Cross-platform rules

- Do not branch visual layout by Linux desktop environment.
- Test both X11 and Wayland, but use capability checks rather than desktop-name checks.
- Do not assume Windows mouse-wheel delta sizes.
- Support wheel button events used by X11 as well as `MouseWheel` events.
- Do not assume a title-bar height, taskbar position, or system decoration geometry.
- Use native file dialogs and clipboard APIs through Tk unless a documented defect
  requires a narrow platform adapter.
- Treat missing optional system services as recoverable, not fatal.
- Never require network access to start, edit, save, reopen, or export a project.

## 15. Accessibility

- Every icon control has an accessible text name and tooltip.
- Every operation is reachable without a mouse.
- Focus order follows visible reading order.
- Focus and selected-target borders remain distinguishable from each other.
- Status is never communicated by colour alone.
- Provide high-contrast and reduced-motion preferences.
- Mask overlay colour and opacity are configurable.
- Thumbnail targets have text alternatives such as `Layer pixels: Sky` and
  `Layer mask: Sky, partially masked`.

## 16. Project-format evolution

Increment the `.slapper` project format only when required by the portable-format
contract. New layer fields must have safe defaults so older projects continue to open.

Required stable identifiers include:

- layer ID;
- layer type;
- mask data and mask transform;
- content transform;
- mask-link state;
- optional layer-link group;
- text and font identity for text layers.

Unknown future layer types and fields remain preserved. Older readers must report what
they cannot render rather than dropping the layer during save.

## 17. Performance

- Layer and mask thumbnails are cached by layer state hash.
- Dragging and mask painting use bounded preview resolution.
- Full-resolution rendering runs after interaction settles and during export.
- Thumbnail generation must not block canvas interaction.
- Large projects virtualize off-screen layer rows if ordinary scrolling becomes slow.
- Repeated slider changes collapse into one undo operation.

## 18. Migration order

1. Extract theme tokens and shared components without changing editor behavior.
2. Replace the listbox with custom rows and preserve existing layer ordering/actions.
3. Add content/mask thumbnails, active borders, direct mask view, and overlay controls.
4. Add drag-to-reorder with keyboard parity and undo.
5. Add layer transforms and canvas dragging.
6. Add mask linking and linked-layer transform groups.
7. Complete brush, gradient, colour-range, and outline mask controls.
8. Add editable text layers and installed/imported fonts.
9. Add the offline Google Fonts browser and missing-font recovery.
10. Only then integrate generative fill and expand as new reversible layers.

Each step must leave the editor usable and projects readable. Do not merge a partial
custom layer panel that removes an existing operation without its replacement.

### Implementation status — pending release

The current local build contains the following work but has not yet passed the release
gate or shipped:

- the native layer listbox has been replaced by scrollable custom rows;
- image content and masks have independent selectable thumbnails and active borders;
- mask thumbnails show the stored grayscale mask;
- visibility controls and drag-to-reorder are present;
- adjustment and image layers are explicit adjustment targets;
- image-layer transparency survives per-layer adjustments;
- visible zoom out/in controls, actual scale percentage, Fit percentage, wheel zoom,
  touchpad Ctrl+wheel fallback, and keyboard zoom controls are present;
- brush, directional-gradient, radial-gradient, colour-range, and outline mask tools are
  wired, with feather/reverse and replace/add/subtract/intersect selection modes;
- the mask brush exposes hardness, opacity, and flow;
- masks can be viewed directly in grayscale, disabled without deletion, or deleted while
  retaining the layer;
- the optional red mask overlay is present;
- image layers now store non-destructive position, scale, rotation, and flip values;
- selected image layers have a visible transform border, corner scale handles, rotation
  handle, direct canvas movement, and numeric transform controls;
- mask-to-layer linking is explicit and persisted: linked masks follow image transforms,
  while unlinked masks remain fixed to the canvas;
- unlinked masks now have independent persistent position, scale, and rotation and can be
  manipulated with the same numeric controls and canvas handles;
- first-class editable text layers are built with text content, installed/local font
  selection, size, colour, alignment, line and character spacing, wrapping width,
  stroke, background, shadow, transforms, masks, blend, opacity, save/reopen behavior,
  and durable missing-font warnings;
- existing projects without transform fields receive safe centred defaults; and
- the current SNAP SLAPPER regression suite contains 48 passing tests.

This does not claim completion of the specification. Pending work includes shared theme
components, insertion-line/autoscroll/keyboard refinements for stack dragging, advanced
mask refinements, layer link groups, an application-managed imported-font library and
Google Fonts browser, Linux/X11/Wayland and DPI validation, and the full release-gate
test matrix. The current test host lacks a usable Tcl/Tk runtime, so widget-construction
and visual platform checks remain explicitly unverified despite the passing renderer and
source regression tests.

### Next implementation slice

Before adding another layer type, harden and release the layer foundation already built:

1. Add project save/reopen and undo/redo regression tests for every transform and both
   mask-link states.
2. Exercise move, scale, rotate, flip, reorder, mask targeting, mask overlay, zoom, and
   missing-image behavior through the actual UI on Windows.
3. Fix any control clipping or focus problems at 100%, 125%, 150%, and 200% scaling.
4. Add X11 and Wayland wheel-event compatibility and run the same smoke test on Linux.
5. Ship that stable layer foundation.

After that release, build layer link groups, then the managed font library and offline
Google Fonts browser. Generative fill/expand remains later.

## 19. Acceptance tests

1. The editor presents equivalent geometry and state on Windows, X11, and Wayland.
2. UI scaling at 100–200% clips no labels, values, thumbnails, or focus rings.
3. Clicking a layer thumbnail and mask thumbnail visibly selects different targets.
4. Mask painting changes only the selected mask and updates its thumbnail.
5. The red overlay and grayscale mask view agree with exported masking.
6. Dragging a row changes stack order once and undo restores it once.
7. Keyboard reordering produces the same project state as pointer dragging.
8. Linked content and mask move together; unlinking permits independent transforms.
9. Linked layers receive the same transform delta without being merged.
10. Image-layer transparency survives adjustment, transform, save, reopen, and export.
11. Text remains editable after save/reopen and renders consistently with its resolved font.
12. Missing fonts and images produce durable warnings without corrupting the project.
13. A downloaded Google Font remains usable with networking disabled.
14. Mouse wheel, touchpad pinch where exposed, buttons, and keyboard all update the same
    visible zoom percentage.
15. Old `.slapper` projects open with safe defaults; unknown fields survive round-trip.
16. A 500-layer stress project remains scrollable and cancellable during background work.
17. Every destructive action is undoable or explicitly confirmed when undo is impossible.

## 20. Release gate

The redesigned layer workspace is not release-ready until thumbnails, target borders,
drag ordering, project round-trips, undo/redo, scaling, Windows, X11, and Wayland tests
all pass. Text layers are not release-ready until missing-font recovery is honest and
offline reopening is verified. Generative features must not ship before selections,
masks, transforms, and target visibility are dependable.

<!-- ===== SNAPSMACK EOF ===== -->
