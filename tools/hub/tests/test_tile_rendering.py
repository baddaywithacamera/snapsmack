# SNAPSMACK_EOF_HEADER
"""A tile of the frame must render exactly as that part of the full frame.

Zoomed to actual pixels, SNAP SLAPPER renders every pixel of a 24 MP photograph
for each slider move and throws away the roughly 90% that is off screen. That
measured 17.2 s per move against 1.1 s for the visible tile. The saving is only
usable if a tile is indistinguishable from the matching crop of the full render,
so that is what these tests check, stage by stage.

Several stages are not local, and each one is a way for a tile to come out
wrong: Clarity takes its radius from the frame's short side, Vignette and Glow
are positioned from the frame's centre, Grain is one noise field across the
frame, and Clarity, Texture and Sharpen all blur across the tile's edge. Noise
Reduction thresholds against the median of the whole frame's finest detail and
cannot be tiled at all, so tile_is_exact() must refuse it rather than quietly
denoise a tile against its own statistics.
"""

import os
import sys

import numpy as np
import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import editor_engine
import gpu_acceleration
import highbit_image


@pytest.fixture(autouse=True)
def _pin_to_the_processor():
    """Compare tiling against tiling, not one device against another.

    A full frame is large enough to be offloaded to the graphics card while a
    viewport tile is not, so with the card enabled these two renders would run
    on different hardware and could not be bit-identical. That parity is covered
    by test_device_parity.py; here the tiling maths is what is under test.
    """
    gpu_acceleration.configure("cpu")
    yield
    gpu_acceleration.configure("auto")


FRAME_WIDTH, FRAME_HEIGHT = 1300, 900
# Deliberately off-centre and touching neither edge, like a scrolled 100% view.
TILE = (620, 410, 480, 320)


@pytest.fixture(scope="module")
def frame_pixels():
    return np.random.default_rng(4).random(
        (FRAME_HEIGHT, FRAME_WIDTH, 3)).astype(np.float32)


def _full_render(pixels, adjustments):
    image = highbit_image.FloatImage(pixels, ("R", "G", "B"))
    rendered = highbit_image.apply_adjustments(
        image, adjustments, editor_engine.DEFAULT_ADJUSTMENTS).pixels
    left, top, width, height = TILE
    return rendered[top:top + height, left:left + width]


def _tile_render(pixels, adjustments):
    settings = dict(editor_engine.DEFAULT_ADJUSTMENTS)
    settings.update(adjustments)
    left, top, width, height = TILE
    margin = highbit_image.tile_margin(settings, FRAME_WIDTH, FRAME_HEIGHT)
    pad_left, pad_top = max(0, left - margin), max(0, top - margin)
    pad_right = min(FRAME_WIDTH, left + width + margin)
    pad_bottom = min(FRAME_HEIGHT, top + height + margin)
    padded = highbit_image.FloatImage(
        np.ascontiguousarray(pixels[pad_top:pad_bottom, pad_left:pad_right]),
        ("R", "G", "B"))
    frame = highbit_image.Frame(FRAME_WIDTH, FRAME_HEIGHT, pad_left, pad_top)
    rendered = highbit_image.apply_adjustments(
        padded, adjustments, editor_engine.DEFAULT_ADJUSTMENTS, frame=frame).pixels
    return rendered[top - pad_top:top - pad_top + height,
                    left - pad_left:left - pad_left + width]


TILEABLE = {
    "tone and curve": {"exposure": .6, "contrast": 22, "shadows": 25,
                       "highlights": -20, "brightness": 12,
                       "curve": [[0, 0], [110, 145], [255, 255]]},
    "colour": {"saturation": 25, "vibrance": 30, "temperature": 15,
               "col_sat_red": 35, "col_lum_blue": -25},
    "black and white with hue mix": {"black_white": True, "bw_red": 30,
                                     "bw_aqua": -20},
    # Radius comes from the frame's short side, not the tile's.
    "clarity": {"clarity": 35},
    "texture positive": {"texture": 45},
    "texture negative": {"texture": -45},
    # Blurs across the tile edge; the padding has to cover the radius.
    "sharpen": {"sharpen": 60, "sharpen_radius": 2.2},
    "sharpen at the widest radius": {"sharpen": 70, "sharpen_radius": 6.0},
    # Positioned from the frame centre, so a tile must know where it sits.
    "vignette": {"vignette": -55},
    "vignette positive and feathered": {"vignette": 50, "vignette_size": 120,
                                        "vignette_feather": 90},
    "glow off centre": {"glow_amount": 45, "glow_x": 30, "glow_y": 65,
                        "glow_size": 40},
    # One noise field across the frame, so the tile must get its own slice of it.
    "grain": {"grain": 35},
    "grain darkening only": {"grain": 35, "grain_darken": True},
    "split toning": {"split_shadow_amount": 45, "split_highlight_amount": 35},
    "photo filter": {"photo_filter_density": 45},
    "dehaze": {"dehaze": 40},
    "levels": {"level_black": 15, "level_white": 240, "level_gamma": 1.3},
    "every tileable stage at once": {
        "exposure": .5, "contrast": 18, "shadows": 25, "highlights": -20,
        "saturation": 20, "vibrance": 20, "temperature": 12, "col_sat_red": 25,
        "clarity": 25, "texture": 20, "sharpen": 45, "sharpen_radius": 2.0,
        "vignette": -35, "glow_amount": 20, "grain": 15,
        "split_shadow_amount": 25, "photo_filter_density": 15,
        "curve": [[0, 0], [115, 140], [255, 255]]},
}


@pytest.mark.parametrize("label", sorted(TILEABLE))
def test_tile_is_identical_to_that_part_of_the_full_render(frame_pixels, label):
    adjustments = TILEABLE[label]
    full = _full_render(frame_pixels, adjustments).astype(np.float64)
    tile = _tile_render(frame_pixels, adjustments).astype(np.float64)
    difference = float(np.max(np.abs(full - tile)))
    assert difference == 0.0, (
        f"{label}: the tile differs from the full render by {difference:.3e}, "
        f"which is {difference * 255:.4f} of one 8-bit code value")


@pytest.mark.parametrize("key", ["noise_luminance", "noise_colour",
                                 "raw_noise_reduction"])
def test_noise_reduction_refuses_to_be_tiled(key):
    settings = dict(editor_engine.DEFAULT_ADJUSTMENTS)
    settings[key] = 30
    assert not highbit_image.tile_is_exact(settings), (
        f"{key} thresholds against the whole frame's median, so a tile would be "
        "denoised against its own local statistics")


def test_ordinary_edits_are_accepted_for_tiling():
    settings = dict(editor_engine.DEFAULT_ADJUSTMENTS)
    settings.update({"exposure": .5, "clarity": 20, "sharpen": 40,
                     "vignette": -30, "grain": 15})
    assert highbit_image.tile_is_exact(settings)


def test_margin_covers_every_blurring_stage():
    """The padding must grow with the radii it has to cover."""
    frame = (6000, 4000)
    none = highbit_image.tile_margin(
        dict(editor_engine.DEFAULT_ADJUSTMENTS), *frame)
    clarity = highbit_image.tile_margin(
        {**editor_engine.DEFAULT_ADJUSTMENTS, "clarity": 30}, *frame)
    wide = highbit_image.tile_margin(
        {**editor_engine.DEFAULT_ADJUSTMENTS, "clarity": 30, "sharpen": 50,
         "sharpen_radius": 6.0, "texture": 40}, *frame)
    assert none < clarity < wide
    # Clarity alone asks for the frame's short side over 180.
    assert clarity >= 4000 / 180


def test_frame_defaults_to_the_whole_image(frame_pixels):
    """Passing no frame must behave exactly as it always has."""
    image = highbit_image.FloatImage(frame_pixels, ("R", "G", "B"))
    adjustments = TILEABLE["every tileable stage at once"]
    without = highbit_image.apply_adjustments(
        image, adjustments, editor_engine.DEFAULT_ADJUSTMENTS).pixels
    explicit = highbit_image.apply_adjustments(
        image, adjustments, editor_engine.DEFAULT_ADJUSTMENTS,
        frame=highbit_image.Frame(FRAME_WIDTH, FRAME_HEIGHT)).pixels
    assert np.array_equal(without, explicit)


# ===== SNAPSMACK EOF =====
