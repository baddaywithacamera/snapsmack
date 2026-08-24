<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 03 handoff — darkroom foundation

**Status:** complete control set implemented; extended golden-fixture QA remains.

Delivered as an original, non-destructive recipe operation:

- exposure;
- contrast;
- highlights and shadows;
- whites and blacks;
- temperature and tint;
- saturation and vibrance.
- levels and B&W channel mixing;
- vignette, thresholded sharpening, and separate luminance/chroma noise reduction.
- local contrast and bounded haze reduction;
- three-region tone curve, global HSL, and original starting presets.

Processing decodes sRGB values to linear light, emits 16-bit RGBA, preserves alpha and
uses one identical path for preview and export. Every control is range-validated. A
completely neutral operation is an exact pixel identity. The interface supplies numeric
entry, individual resets and one explicit apply action so history stays intelligible.

Acceptance covers neutral identity, range refusal and deterministic adjusted output.
The formulas and ranges are documented in `DARKROOM-MATH.md`.

Fast successive requests carry monotonically increasing generations. Both hosts prevent
an obsolete preview from replacing newer autosave state, and the UI suppresses stale
visual results. True mid-operation cancellation and expanded colour-chart/golden-image
fixtures remain before Packet 03 receives its final release gate.

<!-- ===== SNAPSMACK EOF ===== -->
