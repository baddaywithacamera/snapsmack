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

This first darkroom slice intentionally excludes local contrast, haze removal, B&W mix,
sharpening, noise reduction, vignette, levels, curves and HSL. Those remain Packet 03
acceptance work and must not be claimed yet.

<!-- ===== SNAPSMACK EOF ===== -->
