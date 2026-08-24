<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# PIXEL TICKLER — product and architecture specification

**Status:** build-ready draft  
**Platforms:** Windows 10+ and current Linux distributions  
**macOS:** unsupported; no binary, packaging, signing, testing, fixes, or promise  
**License:** original SnapSmack code under SPL 2.1

## Product promise

A fast, offline, non-destructive desktop darkroom for rendered photographs. It must
be useful standalone and give COLD SNAP and GYSS posting/editing capability equal to
or better than the CMS. RawTherapee is the recommended RAW developer: develop RAW
there, export 16-bit TIFF, then edit or publish through SnapSmack.

SnapSmack will not support HEIC/HEIF because it commonly relies on patent-encumbered
HEVC technology. The project cannot accept that litigation risk. Reject it clearly.

## Supported boundaries

Import: JPEG, PNG, WebP, TIFF (8/16-bit as the selected codec permits).  
Export: JPEG, PNG, WebP, TIFF; flattened image plus optional editable project.  
Explicitly excluded: RAW development, HEIC/HEIF, video, generative AI, panorama/HDR/
focus stacking, and Photoshop-style arbitrary pixel painting in the initial product.

The original is never overwritten silently. `Save Copy` is default. Replacement is
an explicit calling-app workflow with atomic write, backup/recovery, and confirmation.

## Architecture

One implementation of each responsibility:

- `core/`: original native processing engine and long-lived JSON-RPC sidecar.
- `web/`: shared editor HTML/CSS/JS used by both desktop hosts.
- `windows/`: thin Tauri host; starts the sidecar and exposes filesystem/dialog APIs.
- `linux/`: thin `snap_blink` Python host; starts the same Linux sidecar and exposes
  the same logical API. Chromium draws the window only.
- `contracts/`: versioned schemas for jobs, projects, recipes, results and errors.

Full-resolution pixels never enter browser JavaScript. The engine owns decode,
colour conversion, processing, compositing and encode. The web UI receives a bounded
preview image, histogram data and operation state. A host adapter hides whether calls
use Tauri invoke or `blink.call`.

The sidecar uses framed JSON messages over private stdin/stdout, never a listening
network socket. Every request has an ID; every response is success or a stable error.
Only canonical, host-approved paths may be opened or written.

## Processing model

- Non-destructive ordered operation graph stored as JSON, never executable code.
- Linear-light, colour-managed working pipeline; preview and export use the same graph.
- Bounded proxy preview for interactive work; full-resolution tiled export.
- Memory budget checked before decode and every allocation; malformed/huge images fail
  safely. Cancellation is mandatory for preview and export jobs.
- Undo/redo stores recipe changes, not duplicate full-resolution images.
- Autosave writes project state atomically and supports crash recovery.
- Deterministic output: identical input, recipe, engine version and export settings
  produce identical pixels within documented codec tolerance.

## Features by completed packet

Core: open/save, crop, ratios, rotate, straighten, flip, resize, zoom/pan, histogram,
clipping warnings, undo/redo, reset, before/after and export controls.

Darkroom: exposure, contrast, highlights, shadows, whites, blacks, temperature, tint,
saturation, vibrance, clarity/local contrast, haze reduction, B&W channel mix,
sharpening, basic luminance/chroma noise reduction, vignette, levels, curves and HSL.

Geometry/optics: interactive free and common-ratio crop with draggable handles and
rule-of-thirds overlay; arbitrary horizon straightening plus draw-a-horizon mode;
two-axis horizontal/vertical perspective correction; draggable four-corner freeform
perspective correction; lens distortion and red/cyan plus blue/yellow chromatic
aberration correction. Preview and full export must use the same transform.

Layers: reorderable named adjustment layers; text and image-overlay layers; opacity,
fill opacity, groups, common blend modes, visibility, duplicate/delete, presets, and
flattened export while preserving the editable project recipe.

The Layers panel is a first-class, plainly visible accordion—not a buried advanced
screen. It must always show the ordered stack and expose add adjustment layer, add text,
add image overlay, group, select, edit, hide/show, lock/unlock, duplicate, reorder,
delete and ungroup. Every applicable layer exposes Normal, Multiply, Screen, Overlay
and Soft Light blending plus opacity and fill. Structural actions participate in
undo/redo and survive editable-project round trips.

Styles/masks: stroke, drop/inner shadow, outer/inner glow, colour/gradient/texture
overlay, bevel/emboss; gradient, radial, luminance and colour-range masks. Painted
masks are a later acceptance-gated addition, not a prerequisite for release.

Effects: vignette and deterministic photographic film grain with amount, grain size
and roughness controls. Film grain must be seeded from stable recipe data so preview,
project reopen and export remain reproducible.

## Lighttable and selection workflow

Folder browsing shows compatible JPEG, PNG, WebP and TIFF images only. RAW, HEIC/HEIF,
SVG, GIF, AVIF, PDF, video, renamed/disguised payloads and corrupt files do not appear.
The Lighttable provides a virtualized thumbnail grid, filmstrip, previous/next image,
keyboard navigation, pick, reject, zero-to-five stars, colour labels and filters for
flag/rating/label. `P` means pick, `X` means reject and `0`–`5` set stars.

Ratings live in an atomic portable catalog beside the folder and never modify original
images. Thumbnail generation is lazy, cached, cancellable and isolated so one corrupt
or enormous file cannot block the folder. Folder changes and concurrent catalog writers
are detected. Selected images can be opened for editing or handed to suite posting flows.

## Preset compatibility

PIXEL TICKLER has its own named non-destructive presets and imports current Lightroom
Develop `.xmp` presets, including `.zip` packages containing them. Import translates
only settings with a documented PIXEL TICKLER equivalent and reports imported,
approximated and unsupported properties. It never claims pixel-identical Adobe output.
Legacy `.lrtemplate`, camera/DCP profiles, proprietary lens profiles, RAW calibration,
AI masks and Adobe process-version rendering are not silently accepted. Preset files
are data only, never executable, and importing them does not redistribute their content.

## Interface organization

The right rail uses independently collapsible Midnight Lime accordions, including at
minimum Crop, Straighten, Resize, Exposure, Optics, Colour/Tone, Detail, Effects,
Layers, Text, Image Overlay, Groups, Layer Styles, Masks and Histogram. A collapsed
section consumes only its labelled header. Essential layer and blend controls must be
discoverable without scrolling through every photographic adjustment.

## Job and project contracts

Standalone launch may have no job. Integrated launch uses a UTF-8 JSON job file with:
contract version, mode (`edit-copy` or `edit-replace-working-copy`), input path,
permitted output directory, calling app, optional existing project, output presets and
one random return token. Paths are absolute and resolved by the host before the engine
sees them. The result is written atomically to a caller-designated result JSON path.

Project files reference an original by path, size and SHA-256; contain canvas/colour
policy, ordered operations/layers, export presets, engine/contract versions and no
credentials. Unknown required operations fail closed; unknown optional metadata is
preserved during save.

## Security and licensing gates

No site credentials enter the editor. It has no publishing or network capability.
Calling apps upload results. Reject traversal, symlink escape, device paths, output
outside the permitted directory, oversized dimensions, decompression bombs and invalid
recipes. Use temporary files plus atomic rename; never partially replace an image.

All editor-specific source is original. Do not copy code, shaders, assets or UI from
other editors. Dependencies require a recorded allowlist, exact version, purpose,
copyright/license text and transitive audit. Prefer MIT/BSD/Apache-style dependencies.
GPL/LGPL, patent-encumbered codecs and unclear licenses stop the build pending Sean's
explicit decision. Third-party notices ship in `licenses/`.

## Definition of release quality

Windows and Linux produce visually equivalent previews and exports from the same
fixtures and recipes. Colour-managed reference fixtures, 8/16-bit TIFFs, alpha images,
large images, corrupt inputs, cancellation, crash recovery and atomic replacement are
tested. Accessibility includes keyboard operation, visible focus, scalable UI and
minimum 44px primary targets. No macOS claim appears in product copy.

<!-- ===== SNAPSMACK EOF ===== -->
