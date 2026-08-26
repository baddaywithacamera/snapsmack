<!--
SNAPSMACK_EOF_HEADER
Last non-empty line must be the canonical HTML-comment EOF marker.
-->

# SNAP SLAPPER — Editing Language and LEWKS Specification

Status: normative product specification  
Target: 0.7.564D development path / closed beta

## 1. Product language

SNAP SLAPPER uses four distinct editing terms. They are not interchangeable.

### Adjustment

An **adjustment** changes a photographic property through a continuous or
selectable control. Examples include exposure, highlights, temperature, tint,
saturation, tone curve, levels, and vignette.

Adjustments are non-destructive and may exist globally or on an adjustment
layer. An adjustment is not called a filter in the interface.

### Filter

A **Filter** is a Photoshop-style image-processing operation with its own
parameters. Examples include Gaussian Blur, Unsharp Mask, Noise Reduction,
Glow, Edge treatment, Lens Correction, Perspective Correction, and Chromatic
Aberration removal.

A Filter is an individual processor, not a packaged appearance. Filters appear
in a visible, reorderable filter stack when the photographer adds them
directly. Each Filter has:

- an enabled/disabled state;
- its own parameter controls;
- opacity or amount where the operation supports it;
- optional mask and blend mode where meaningful;
- deterministic rendering and a versioned identifier;
- a reset command that restores only that Filter's defaults.

### Action

An **Action** is one reproducible editing step. It may set an adjustment, add or
configure a Filter, create or alter a layer, set a blend mode, apply a mask, or
perform another supported non-destructive operation.

Actions are the ordered instructions from which a LEWK is assembled. They are
inspectable when a photographer opens a LEWK, but do not need to be exposed
during ordinary one-click use.

### LEWK

A **LEWK** is an Instagram/Snapseed-style appearance pack. It applies a
coordinated, ordered collection of hidden Actions, Adjustments, Filters, layer
settings, masks, and blend modes to produce a particular visual style.

A LEWK is not called a Filter. Built-in appearances, photographer-created
appearances, imported packs, and AI-assisted appearances are all LEWKS and use
the same underlying format.

Example: a LEWK named `PARKING LOT DISCO` might lower highlights, warm the white
balance, add a lifted tone curve, mute greens, apply restrained glow and grain
Filters, and finish with a vignette. The photographer initially sees one LEWK
and one overall strength control—not a row of unrelated sliders.

### LEWK AGAIN

**LEWK AGAIN** is the AI-assisted LEWK builder. It converts a plain-language
description or supported reference image into a proposed LEWK made only from
operations the installed SNAP SLAPPER renderer understands.

LEWK AGAIN produces an editable LEWK, never a permanently flattened mystery
effect. Its proposal must be previewed and explicitly accepted before it changes
a project. It must work through the same versioned LEWK format as built-in and
handmade LEWKS.

## 2. User-facing distinction

The interface must preserve this rule everywhere:

> **Filters process pixels. LEWKS coordinate a style.**

Consequently:

- `FILTERS` opens individual processing operations and their controls.
- `LEWKS` opens the visual appearance browser.
- `ACTIONS` appears only when inspecting or editing how a LEWK is constructed.
- `LEWK AGAIN` opens the assisted LEWK builder.
- Existing `.slaprecipe` functionality is the technical predecessor of LEWKS;
  user-facing `Preset` and `Recipe` labels migrate to `LEWK` where they describe
  a complete reusable appearance.
- A narrow technical export/import description may mention the recipe format
  for backward compatibility, but the primary product term remains LEWK.

## 3. LEWKS browser

The LEWKS browser provides:

- built-in, custom, imported, and LEWK AGAIN sections;
- preview thumbnails generated from the current photograph;
- click-to-preview without committing the project;
- explicit Apply and Cancel controls;
- an overall strength control from 0–100%;
- search, categories, favorites, and recently used ordering;
- keyboard navigation and a text alternative for every thumbnail;
- before/after comparison using the existing compare system;
- a clear indicator when a LEWK contains an unavailable operation.

Built-in LEWKS are read-only masters. Editing one creates a custom copy.

## 4. Applying and stacking LEWKS

Applying a LEWK creates a non-destructive LEWK instance in the project. It does
not rewrite the original photograph and does not flatten existing edits.

Each instance stores:

- the LEWK identifier and format version;
- a snapshot of the Actions actually applied;
- overall strength;
- enabled/disabled state;
- stack position;
- any photographer overrides;
- provenance: built-in, custom, imported, or LEWK AGAIN.

Photographers may stack, reorder, disable, duplicate, rename, and remove LEWK
instances. Overall strength blends the complete rendered result of the LEWK
against its input; it does not naïvely multiply every internal slider.

Opening `SHOW THE GUTS` reveals the internal Actions. Changing an internal Action
turns that instance into an overridden/custom LEWK without modifying its source
master.

## 5. Filter stack

Filters added directly by the photographer appear as explicit stack entries.
The stack supports:

- add, remove, duplicate, reorder, and enable/disable;
- per-Filter settings and reset;
- optional masks, opacity, and blend modes;
- drag-and-drop ordering with keyboard equivalents;
- cached previews that never replace the full-resolution render;
- clear warnings for operations that cannot render in the current build.

Filters embedded inside an unopened LEWK remain hidden as implementation
details. `SHOW THE GUTS` exposes them in the LEWK's Action list.

## 6. File formats and compatibility

The canonical LEWK document is versioned JSON and uses the extension `.lewk`.
It contains no photograph pixels, credentials, absolute cache paths, or executable
code. Imported values are bounded and validated before preview or application.

For closed-beta compatibility:

- SNAP SLAPPER continues to read `.slaprecipe` version 1 files;
- loading a `.slaprecipe` converts it in memory to the current LEWK model;
- saving a newly created appearance defaults to `.lewk`;
- projects snapshot applied Actions so a later built-in LEWK update cannot
  silently alter an existing edit;
- unknown Actions or Filters fail visibly and remain preserved for a newer build
  rather than being silently discarded;
- LEWK packs may contain multiple `.lewk` documents plus preview assets and a
  manifest, but never executable scripts.

## 7. Built-in LEWKS

The closed beta ships a deliberately curated starter collection rather than a
large pile of near-duplicates. Every built-in LEWK must have a distinct purpose,
tested skin-tone behavior, a descriptive preview, and an intensity that remains
usable below 100%.

Initial categories:

- Clean and corrective;
- Film and print;
- Black and white;
- Portrait;
- Landscape and weather;
- Night and neon;
- Experimental.

Names may carry SNAP SLAPPER's voice, but Help must describe the practical look
in plain language.

## 8. Required Filter families

The Filter catalogue is delivered separately from the LEWKS catalogue. Closed
beta should cover at least:

- Sharpen: Unsharp Mask and High Pass;
- Blur: Gaussian, motion, and selective/lens blur;
- Noise: luminance/chroma reduction and grain;
- Light: glow/bloom, shadows/highlights, and local contrast;
- Colour: channel mixer, HSL/selective colour, colour grading, and LUT;
- Lens: distortion, vignette correction, and chromatic aberration;
- Geometry: perspective and transform;
- Stylize: edge, posterize, halftone, and diffusion.

An adjustment already represented by a primary editing control is not duplicated
as a Filter merely to inflate the catalogue.

## 9. Rendering and safety

- Originals remain immutable.
- LEWKS, Actions, Adjustments, and Filters render non-destructively in projects.
- Preview resolution may be reduced; exports render the full source resolution.
- The renderer must produce the same result for the same source, operation
  versions, parameters, and colour profile.
- Unsupported operations fail loudly before export.
- Batch application creates new files in the photographer-selected saved-image
  location.
- Metadata and colour-profile preservation follow SNAP SLAPPER's export policy.
- LEWK AGAIN cannot introduce arbitrary Python, shell commands, network fetches,
  plugins, or operations absent from the local allowlist.

## 10. Current implementation mapping

The existing adjustment renderer, adjustment/image layers, masks, blend modes,
history, batch application, and `.slaprecipe` import/export are the foundation.
They do not by themselves complete this specification.

Work remaining includes:

1. Add the versioned Filter node model and visible Filter stack.
2. Rename complete reusable appearance flows from Preset/Recipe to LEWK while
   retaining `.slaprecipe` import compatibility.
3. Add LEWK instances, master strength, stacking, and `SHOW THE GUTS`.
4. Build the visual LEWKS browser and curated built-in library.
5. Implement and test the required Filter families.
6. Add `.lewk` and safe LEWK-pack import/export.
7. Build LEWK AGAIN on the same allowlisted Action schema.
8. Extend offline Help with Filters, LEWKS, Actions, compatibility, and safety.

## 11. Acceptance criteria

The feature is complete only when:

- no user-facing screen calls a style pack a Filter;
- a Filter can be added and adjusted independently;
- a LEWK can contain multiple Adjustments, Filters, and Actions;
- LEWK strength blends the entire appearance predictably;
- multiple LEWKS can be stacked and reordered non-destructively;
- a photographer can inspect, override, save, export, import, and batch-apply a
  LEWK;
- legacy `.slaprecipe` files still load;
- built-in LEWKS cannot be mutated in place;
- malformed or unsupported LEWKS cannot execute code or silently corrupt an
  edit;
- offline Help teaches the distinction with the exact phrase:
  **Filters process pixels. LEWKS coordinate a style.**

<!-- ===== SNAPSMACK EOF ===== -->
