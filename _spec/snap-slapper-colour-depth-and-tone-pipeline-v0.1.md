# SNAP SLAPPER Colour Depth, Colour Management, and Tone Pipeline — v0.1

**Status:** Approved for implementation
**Date:** 2026-09-01
**Scope:** SNAP SLAPPER desktop editor and its exported derivatives

## 1. Purpose

SNAP SLAPPER must stop treating every photograph as anonymous 8-bit display RGB.
The editor must tell the photographer what the source contains, preserve available
precision and colour meaning, process edits at high precision, and export an explicitly
chosen depth/profile combination.

The tonal controls must behave like photographic controls rather than broad 8-bit
offsets. Exposure remains global. Highlights, Midtones, and Shadows operate as smooth,
overlapping luminance bands without abrupt boundaries, colour shifts, or repeated
quantization.

## 2. Non-negotiable interface contract

The editor workspace always shows a compact source/working readout near the filename or
status bar:

`RGB · 8-bit/channel · Unprofiled (assumed sRGB) · Working: linear sRGB float`

Examples:

- `RGB · 16-bit/channel · Display P3 (embedded ICC) · Working: linear sRGB float`
- `RGB · 10-bit/channel · HEIC NCLX/P3 · Working: linear sRGB float`
- `Grayscale · 16-bit/channel · Gray Gamma 2.2 · Working: linear sRGB float`

The readout must never claim a profile exists when it does not. An unprofiled source is
labelled **Unprofiled** and the assumed interpretation is disclosed.

The workspace also exposes:

- source bit depth and colour model;
- embedded/declared source profile;
- working space and precision;
- soft-proof/output profile when enabled;
- gamut/clipping warning state.

## 3. Source inspection

Inspection occurs before conversion and records:

- format, dimensions, channel count, alpha, and orientation;
- bits per sample for every channel;
- integer versus floating-point sample format;
- embedded ICC profile description and bytes;
- HEIC/AVIF NCLX colour information where present;
- EXIF colour-space declaration where present;
- whether a profile was embedded, declared, assigned by policy, or merely assumed.

The original metadata is immutable project provenance. Assigning an interpretation to
an unprofiled image does not rewrite the original.

## 4. Decode and working representation

- Decode at the source's available precision. Never reduce 10/12/16-bit input to 8-bit
  merely to enter the editor.
- Convert samples into a floating-point linear-light working buffer before photographic
  adjustments.
- The first implementation uses 32-bit float linear sRGB as the mandatory working
  space. The architecture keeps the working-space identifier explicit so a future
  linear Display P3/ProPhoto option does not change project meaning silently.
- Alpha remains linear and separate from colour operations.
- Screen previews may be 8-bit display buffers, but document state and final rendering
  remain high precision.
- Each edit is evaluated from the high-precision document state; intermediate results
  are not repeatedly written back as 8-bit pixels.

## 5. Colour management

- Embedded ICC input profiles are interpreted through LittleCMS.
- Recognized HEIC/AVIF colour declarations are converted equivalently.
- Untagged RGB defaults to an explicitly disclosed sRGB assumption; the user may assign
  another input interpretation without modifying the original.
- Display conversion uses the configured monitor profile, falling back visibly to sRGB.
- Export conversion supports an explicit output profile and rendering intent.
- Relative colorimetric and perceptual intents are offered where the profile supports
  them; black-point compensation is explicit.
- Soft proof, gamut warning, and channel-clipping warnings are non-destructive views.
- ICC preservation alone is not described as colour management.

## 6. Tone engine

The implementation is independent SNAP SLAPPER code informed by published photographic
and colour-science principles. RawTherapee source code is not copied or translated.

### 6.1 Exposure

Exposure is a linear-light gain:

`RGB' = RGB × 2^EV`

It is the deliberate whole-frame brightness control.

### 6.2 Five-band tone equalizer

Blacks, Shadows, Midtones, Highlights, and Whites operate in perceptual luminance/
exposure-value space:

1. Calculate luminance from linear working RGB.
2. Convert positive luminance to a log2 exposure coordinate.
3. Evaluate five overlapping smooth basis functions.
4. Normalize the weights so their sum is stable through the usable range.
5. Apply each slider as an exposure-like luminance change.
6. Scale RGB by `new_luminance / old_luminance` to preserve hue.
7. Roll values into the display/output range with smooth toe and shoulder functions.

No band may have a hard threshold. A strong Highlights change must leave shadows
substantially stable; a strong Shadows change must leave highlights substantially
stable; Midtones must peak around middle grey and fall away smoothly.

### 6.3 Highlight and shadow rolloff

- Highlight compression uses a monotonic filmic shoulder, not clipping or a fixed
  channel subtraction.
- Shadow shaping uses a monotonic toe with protected black ordering.
- Tone mappings must remain monotonic at every allowed slider value.
- Luminance changes must not independently bend RGB channels and create hue shifts.

### 6.4 Curves and local contrast

- The master tone curve operates on perceptual luminance with colour-preserving RGB
  rescaling by default.
- Per-channel RGB curves remain explicitly creative colour controls.
- Spatial local-contrast/sharpening operations remain separate and cannot be smuggled
  into Highlights/Shadows.
- Existing source halos may become more visible after contrast changes; the interface
  must not misidentify that as newly applied sharpening.

## 7. Interaction and preview quality

- Slider thumbs and numeric readouts track the pointer synchronously. Image work may
  never block, rewind, or fight the control.
- While a slider is held, no full document composite runs on the UI thread. A bounded
  preview may be produced asynchronously; if that path is unavailable, release-to-
  preview is preferred to a jerking control.
- Slider release performs one full-quality viewport/native render.
- Histogram data is derived from the already-rendered preview, never from a second full
  document render.
- Preview substitution must not alter saved/exported pixels.
- The UI remains responsive with a 16-bit 24-megapixel source and several adjustment
  layers on the reference workstation.

## 7.1 Local masks and shared brush palette

Every adjustment/filter/image layer exposes a visible mask thumbnail, enable switch,
invert, clear, and an explicit **Edit mask** action. White reveals the layer; black
hides it. The canvas can display a red mask overlay while editing.

Mask creation includes:

- an eyedropper that samples the photograph and seeds a colour-range mask;
- additive and subtractive colour samples;
- hue, saturation, luminance, range, and softness refinement;
- paint bucket actions to fill the current mask with Reveal (white) or Hide (black);
- a draggable linear gradient and a radial gradient;
- a brush/eraser palette shared with retouch and other paint-capable tools.

The shared brush palette provides at least size, hardness, opacity, flow, paint mode,
and useful soft/hard presets. Bracket keys change size; Shift+brackets change hardness.
Mask editing must occur on the main photograph, not only in a small rail thumbnail.

## 7.2 Keyboard contract

Every shipped shortcut is listed in Help and exposed in tooltips/menus. Local-mask
minimums are: `M` edit/toggle mask, `I` invert mask, `G` gradient, `K` bucket,
`B` brush, `E` erase/hide, `[`/`]` brush size, and Escape exits the active canvas tool.
Shortcuts must not fire while the user is typing in a text field.

## 8. Export

The Export dialog exposes **Colour depth** and **Output profile**. Invalid combinations
are disabled rather than silently changed.

Initial matrix:

| Format | Depth choices | Profile behavior |
|---|---|---|
| JPEG | 8-bit | embed selected ICC; normally sRGB |
| PNG | 8-bit, 16-bit | embed selected ICC |
| TIFF | 8-bit, 16-bit integer, 32-bit float | embed selected ICC |
| PSD | 8-bit, 16-bit where writer supports it | embed selected ICC |
| HEIC/AVIF | 8/10-bit when an available encoder proves support | embed/declare colour |

Quantization to integer output happens once, at export. Reducing to 8-bit uses suitable
dithering unless the target encoder makes that impossible.

The default export depth/profile is saved in Preferences, and every individual export
can override it.

## 9. Project and recipe compatibility

- Existing projects load with their current appearance as closely as practical.
- New projects store source interpretation, working-space identifier, engine version,
  and export defaults.
- Legacy 8-bit tone settings are migrated deliberately and tagged with their engine
  version; they are not silently reinterpreted if doing so would materially change an
  existing saved edit.
- Recipes describe photographic intent and engine version, not transient preview depth.

## 10. Required tests

1. Detect 8-bit, 16-bit integer, and floating-point TIFF fixtures correctly.
2. Detect embedded ICC and unprofiled sources without false claims.
3. Preserve a 16-bit ramp through a no-op edit/export without reducing it to 8-bit.
4. Confirm a no-op colour-managed round trip remains within defined numerical tolerance.
5. Verify Exposure is linear gain in working space.
6. Verify all five tone bands are smooth, monotonic, and appropriately isolated.
7. Verify neutral-grey hue remains neutral through every tonal slider.
8. Verify saturated test colours retain hue within tolerance.
9. Verify the live slider path uses the lightweight proxy and release resolves once.
10. Verify export depth/profile combinations and embedded profile bytes.
11. Verify old projects still load and render.
12. Verify the packaged EXE contains every required decoder/profile dependency.
13. Verify colour eyedropper sampling seeds a matching range mask.
14. Verify bucket fills, gradient generation, mask enable/invert, and brush persistence.
15. Verify brush size/hardness/opacity/flow and their shortcuts.
16. Verify dragging every adjustment slider changes its thumb/readout without running a
    synchronous document render; release produces exactly one final render.

## 11. Delivery gate

The feature is not complete when a profile is merely copied to output or when a 16-bit
file is converted to 8-bit on entry. Completion requires:

- truthful workspace depth/profile readout;
- high-precision document processing;
- real input/display/output profile conversion;
- the five-band colour-preserving tone engine;
- selectable supported export depths;
- passing source and packaged-build tests;
- an installed Windows EXE visibly smoke-tested with 8-bit TIFF, 16-bit TIFF, profiled
  RGB, unprofiled RGB, and the supported HEIC path.
