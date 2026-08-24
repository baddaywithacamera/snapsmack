<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# PIXEL TICKLER darkroom math

The implementation is original SnapSmack code. It does not reproduce or name another
editor's proprietary processing.

Packet 03 starts with one non-destructive `darkroom` recipe operation. Exposure accepts
-5 to +5 EV; every other initial control accepts -1 to +1. Exact zero settings return
the input unchanged, making the neutral operation a pixel identity.

RGB values are decoded from the standard sRGB transfer curve into linear-light floats.
Exposure multiplies by `2^EV`. Temperature applies opposing bounded red/blue gains;
tint applies an opposing green versus red/blue gain. Shadows and highlights use squared
luminance weights; blacks and whites use sixth-power endpoint weights. Contrast pivots
around linear 18% grey. Saturation moves channels from linear luminance, while vibrance
reduces its effect as existing channel chroma rises. Output is encoded through the sRGB
transfer curve to 16-bit RGBA and alpha is preserved exactly.

The remaining Packet 03 exclusions are local contrast, haze removal, curves, HSL and
preset management. Those must not be claimed yet.

## Finishing controls

Levels maps a validated linear input interval through a gamma midpoint and optional
linear output interval. B&W computes a normalized, user-weighted mix of linear RGB.
Vignette uses a radial cubic smoothstep between midpoint and feather, with positive
amounts darkening edges and negative amounts lifting them.

Sharpening is an original 3×3 linear-light unsharp operation with an explicit edge
threshold. Noise reduction separates Rec. 709 luminance from chroma, blends each toward
its bounded 3×3 neighbourhood independently, and preserves alpha. Zero strength is an
exact identity for levels, vignette, sharpening and both noise-reduction channels.


<!-- ===== SNAPSMACK EOF ===== -->
