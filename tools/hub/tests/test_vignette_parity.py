"""One vignette, drawn the same way on the canvas and in a preset thumbnail.

There were two implementations. The float compositor that draws the canvas drew
a plain circle with a cubic ramp and ignored the blend mode and the edge colour
entirely; the 8-bit path behind the LEWKS / LEWK AGAIN / TEACH ME thumbnails
drew a frame-following superellipse with a quintic ramp and honoured both. So
the dropdown and the colour picker did nothing to the photograph, and the
little preset thumbnails showed a different vignette from the photograph beside
them.

A circle also reaches the corners at radius 1.414 while the ramp at ordinary
settings ends well before that, so the mask saturated around three quarters of
the way out and the whole outer quarter of the diagonal was flat. Flat against
a gradient is a visible edge once the darkening is deep enough to bottom out,
which is why the feathering looked harder the darker it got.
"""

import os
import sys

import numpy as np
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
HUB = os.path.dirname(HERE)
SHARED = os.path.join(os.path.dirname(HUB), "_shared")
for folder in (HUB, SHARED):
    if folder not in sys.path:
        sys.path.insert(0, folder)

import editor_engine
import highbit_image as highbit

WIDTH, HEIGHT = 240, 160
BLEND_MODES = ("normal", "multiply", "soft_light", "overlay")


def settings(**changes):
    values = dict(editor_engine.DEFAULT_ADJUSTMENTS)
    values.update({"vignette": -70.0, "vignette_size": 70.0,
                   "vignette_feather": 105.0, "vignette_color": [0, 0, 0],
                   "vignette_blend": "normal"})
    values.update(changes)
    return values


def eight_bit(values):
    grey = Image.new("RGB", (WIDTH, HEIGHT), (128, 128, 128))
    return np.asarray(editor_engine.apply_adjustments(grey, values).convert("RGB"),
                      dtype=np.float32) / 255.0


def floating(values):
    pixels = np.full((HEIGHT, WIDTH, 3), 128.0 / 255.0, dtype=np.float32)
    image = highbit.FloatImage(pixels, ["R", "G", "B"], "png", b"")
    return np.clip(highbit.apply_adjustments(image, values).pixels, 0.0, 1.0)


def diagonal(pixels, fraction):
    """A pixel this far from the centre towards the top-left corner."""
    row = int(round((HEIGHT - 1) / 2 * (1.0 - fraction)))
    column = int(round((WIDTH - 1) / 2 * (1.0 - fraction)))
    return float(pixels[row, column, 0]) * 255.0


def test_canvas_and_thumbnail_draw_the_same_vignette():
    for mode in BLEND_MODES:
        for colour in ([0, 0, 0], [220, 120, 40]):
            values = settings(vignette_blend=mode, vignette_color=colour)
            delta = np.abs(eight_bit(values) - floating(values)).max() * 255.0
            # One count of 255 is the 8-bit path rounding its own result.
            assert delta <= 2.0, f"{mode} {colour} differs by {delta:.2f}/255"


def test_blend_mode_changes_the_canvas():
    """The dropdown was inert on the float path; prove each mode lands."""
    grey = floating(settings(vignette_blend="normal", vignette_color=[220, 120, 40]))
    seen = {tuple(np.round(grey[0, 0], 4))}
    for mode in ("multiply", "soft_light", "overlay"):
        corner = floating(settings(vignette_blend=mode,
                                   vignette_color=[220, 120, 40]))[0, 0]
        key = tuple(np.round(corner, 4))
        assert key not in seen, f"{mode} rendered the same as another mode"
        seen.add(key)


def test_edge_colour_changes_the_canvas():
    black = floating(settings(vignette_color=[0, 0, 0]))[0, 0]
    warm = floating(settings(vignette_color=[220, 120, 40]))[0, 0]
    assert not np.allclose(black, warm), "the colour picker did nothing"
    # A warm edge must actually be warm: more red than blue at the corner.
    assert warm[0] > warm[2] + .05


def test_positive_vignette_still_brightens_without_a_chosen_colour():
    corner = floating(settings(vignette=70.0, vignette_color=[0, 0, 0]))[0, 0]
    assert corner[0] > 128.0 / 255.0


def test_the_ramp_still_has_gradient_where_the_corner_is():
    """The old circle flattened at ~75% out, which is the hard edge Sean saw."""
    deep = floating(settings(vignette=-100.0))
    assert diagonal(deep, .75) > 1.0, "still flat before the corner"
    assert diagonal(deep, .80) > 1.0
    assert diagonal(deep, .80) < diagonal(deep, .75), "ramp must keep falling"


def test_normal_darkening_happens_in_linear_light():
    """Gamma-space darkening reads about a quarter as bright at the same mask.

    Photoshop takes light away in linear light, and so does a lens. The old
    float path multiplied the display-encoded numbers instead, which is far
    steeper and is why the vignette slammed into black.
    """
    middle = diagonal(floating(settings(vignette=-100.0)), .70)
    mask = highbit.vignette_geometry(
        settings(vignette=-100.0), *np.ogrid[:HEIGHT, :WIDTH], WIDTH, HEIGHT)
    weight = float(mask[int(round((HEIGHT - 1) / 2 * .30)),
                        int(round((WIDTH - 1) / 2 * .30))])
    gamma_space = 128.0 * (1.0 - weight)
    assert middle > gamma_space * 1.3, (
        f"{middle:.1f} is no brighter than the gamma-space {gamma_space:.1f}")
    # And it is the sRGB curve, not an arbitrary softening.
    expected = float(highbit._to_display(
        highbit._to_linear(np.float32(128.0 / 255.0)) * (1.0 - weight))) * 255.0
    assert abs(middle - expected) < 1.5


def test_the_mask_follows_the_frame_rather_than_drawing_a_circle():
    values = settings()
    yy, xx = np.ogrid[:HEIGHT, :WIDTH]
    mask = highbit.vignette_geometry(values, yy, xx, WIDTH, HEIGHT)
    assert mask.shape == (HEIGHT, WIDTH)
    corner = float(mask[0, 0])
    side = float(mask[HEIGHT // 2, 0])
    top = float(mask[0, WIDTH // 2])
    assert corner > side > 0.0 and corner > top > 0.0, (
        "the corner must be darkest, but the sides must still be touched -- "
        "a circle leaves the sides untouched and advertises an oval")
    assert float(mask[HEIGHT // 2, WIDTH // 2]) == 0.0


def test_edge_colour_rejects_rubbish_without_crashing_a_render():
    for rubbish in (None, [], [1, 2], "black", [1, 2, 3, 4]):
        colour = highbit.vignette_edge_colour(settings(vignette_color=rubbish))
        assert len(colour) == 3
        assert all(0 <= channel <= 255 for channel in colour)


def test_srgb_transfer_curves_round_trip():
    values = np.linspace(0.0, 2.0, 257, dtype=np.float32)
    assert np.allclose(highbit._to_display(highbit._to_linear(values)),
                       values, atol=1e-5)
    # Negative pixels are out-of-gamut carry, not light; they pass through.
    negative = np.float32([-.25, -.01])
    assert np.allclose(highbit._to_linear(negative), negative)
    assert np.allclose(highbit._to_display(negative), negative)


# ===== SNAPSMACK EOF =====
