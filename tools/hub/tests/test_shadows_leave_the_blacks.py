"""Shadows opens dense shadow detail; Blacks owns the black point.

In Photoshop, pulling Shadows up leaves the darkest part of the blacks where
they are, so the photograph keeps an anchor. Ours had a shelf -- full weight
everywhere below 10% luminance, pure black included -- so the control lifted
pure black to a flat grey and the whole frame looked faded.

tone_version 3 makes the band fall back to nothing at pure black. Edits saved
under an earlier tone_version keep rendering exactly as they did.
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

RAMP = np.tile(np.arange(256, dtype=np.uint8)[None, :], (4, 1))


def settings(**changes):
    values = dict(editor_engine.DEFAULT_ADJUSTMENTS)
    values.update(changes)
    return values


def eight_bit(values):
    source = Image.fromarray(np.dstack([RAMP] * 3), "RGB")
    return np.asarray(editor_engine.apply_adjustments(source, values)
                      .convert("RGB"))[0, :, 0].astype(float)


def floating(values):
    pixels = np.dstack([RAMP.astype(np.float32) / 255.0] * 3)
    image = highbit.FloatImage(pixels, ["R", "G", "B"], "png", b"")
    return np.clip(highbit.apply_adjustments(image, values).pixels[0, :, 0],
                   0.0, 1.0) * 255.0


def test_shadows_leaves_pure_black_alone():
    for amount in (25.0, 50.0, 100.0):
        curve = eight_bit(settings(shadows=amount))
        assert curve[0] == 0.0, f"Shadows +{amount:.0f} lifted pure black"
        assert floating(settings(shadows=amount))[0] < .5


def test_shadows_still_opens_dense_shadow_detail():
    """Releasing pure black must not cost the control its reach."""
    curve = eight_bit(settings(shadows=100.0))
    assert curve[12] > 12.0 and curve[26] > 26.0 and curve[51] > 51.0
    # Peak weight is 0.986 of the old shelf, so the top of the band is intact.
    assert curve[51] >= 86.0


def test_shadows_leaves_the_midtones_where_they_were():
    curve = eight_bit(settings(shadows=100.0))
    assert curve[128] == 128.0
    assert curve[200] == 200.0


def test_blacks_still_reaches_pure_black():
    """The other half of the pair is the one meant to move the black point."""
    assert eight_bit(settings(blacks=100.0))[0] > 30.0
    assert eight_bit(settings(blacks=-100.0))[26] < 26.0


def test_an_edit_saved_under_the_old_tone_version_renders_as_it_did():
    old = eight_bit(settings(shadows=100.0, tone_version=2))
    assert old[0] == 50.0, "version 2 must keep lifting pure black to 50"
    assert old[8] == 58.0


def test_the_canvas_and_the_preset_thumbnails_agree():
    for amount in (-100.0, -40.0, 40.0, 100.0):
        values = settings(shadows=amount)
        delta = np.abs(eight_bit(values) - floating(values)).max()
        assert delta <= 2.0, f"shadows {amount} differs by {delta:.2f}/255"


def test_the_band_peaks_in_the_dense_shadows_and_dies_at_both_ends():
    tone = np.linspace(0.0, 1.0, 1001, dtype=np.float32)
    weight = highbit._shadow_weight(tone, 3)
    assert weight[0] == 0.0
    assert weight[-1] == 0.0
    peak = float(tone[int(np.argmax(weight))])
    assert .08 < peak < .20, f"the band peaks at {peak:.3f}, not in the shadows"
    assert weight.max() > .95
    # Version 1 and 2 keep the shelf.
    assert highbit._shadow_weight(np.float32([0.0]), 2)[0] == 1.0


# ===== SNAPSMACK EOF =====
