<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 03 handoff — darkroom foundation

**Status:** first tonal slice complete; Packet 03 remains in progress.

Delivered as an original, non-destructive recipe operation:

- exposure;
- contrast;
- highlights and shadows;
- whites and blacks;
- temperature and tint;
- saturation and vibrance.
- levels and B&W channel mixing;
- vignette, thresholded sharpening, and separate luminance/chroma noise reduction.

Processing decodes sRGB values to linear light, emits 16-bit RGBA, preserves alpha and
uses one identical path for preview and export. Every control is range-validated. A
completely neutral operation is an exact pixel identity. The interface supplies numeric
entry, individual resets and one explicit apply action so history stays intelligible.

Acceptance covers neutral identity, range refusal and deterministic adjusted output.
The formulas and ranges are documented in `DARKROOM-MATH.md`.

Still required for Packet 03: local contrast, haze removal, curves, HSL, presets and
obsolete-preview cancellation.

<!-- ===== SNAPSMACK EOF ===== -->
