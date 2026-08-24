<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# Packet 03 — photographic darkroom

Add original, documented implementations of exposure; contrast; highlights/shadows;
whites/blacks; temperature/tint; saturation/vibrance; local contrast; haze reduction;
B&W channel mix; sharpening; bounded luminance/chroma noise reduction; vignette;
levels; tone curve and HSL. Add per-control reset, numeric entry, presets and clipping
feedback. Controls are operations in the existing graph, not destructive rewrites.

Acceptance uses synthetic ramps, colour charts, edge/noise fixtures and golden recipes.
Neutral settings must be identity operations. Slider previews must cancel obsolete work,
remain interactive, and match full export. Document the math and ranges so another agent
can maintain it without reverse engineering. Avoid proprietary algorithm names or copied
implementations.

<!-- ===== SNAPSMACK EOF ===== -->
