# SNAPSMACK_EOF_HEADER
"""The graphics card and the processor must produce the same photograph.

The adjustment chain is dispatched rather than written twice: each helper asks
the array it is handed which module it belongs to. That is the only reason the
two paths can be trusted to agree, and these tests are what hold it true.

Everything here skips when there is no usable card, so the suite still passes on
a machine without one. A skip is not a pass: on a machine with a card, these
must run.
"""

import os
import sys

import numpy as np
import pytest

HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, HUB)
sys.path.insert(0, os.path.join(os.path.dirname(HUB), "_shared"))

import editor_engine  # noqa: E402
import gpu_acceleration  # noqa: E402
import highbit_image  # noqa: E402

# Half of one 16-bit code value. Anything under this cannot survive being
# written to a file, let alone be seen.
TOLERANCE = 0.5 / 65535.0

# A sharpen threshold is a hard cutoff: a sample whose detail sits exactly on it
# can fall either side between two implementations, moving by threshold *
# amount. That is one sample in millions, so it is bounded by count, not by size.
KNIFE_EDGE_SAMPLES = 8


def _card_available():
    gpu_acceleration.configure("auto")
    return gpu_acceleration.status()["available"]


requires_card = pytest.mark.skipif(
    not _card_available(),
    reason="no usable NVIDIA card on this machine")


@pytest.fixture
def frame():
    # Big enough to be offloaded, with headroom and true blacks in it.
    rgb = np.random.default_rng(5).random((1000, 1400, 3)).astype(np.float32)
    rgb[:40] = 0.0
    rgb[40:70] = 1.8
    rgb[70:100] *= -0.2
    return highbit_image.FloatImage(rgb, ("R", "G", "B"))


def _both_ways(frame, adjustments):
    gpu_acceleration.configure("cpu")
    processor = highbit_image.apply_adjustments(
        frame, adjustments, editor_engine.DEFAULT_ADJUSTMENTS).pixels
    gpu_acceleration.configure("gpu")
    card = highbit_image.apply_adjustments(
        frame, adjustments, editor_engine.DEFAULT_ADJUSTMENTS).pixels
    gpu_acceleration.configure("auto")
    assert not gpu_acceleration.is_device_array(card), (
        "apply_adjustments must hand back a frame on the processor")
    return (np.asarray(processor, dtype=np.float64),
            np.asarray(card, dtype=np.float64))


EDITS = {
    "tone": {"exposure": .5, "contrast": 18, "shadows": 25, "highlights": -20,
             "whites": 10, "blacks": -12, "brightness": 8},
    "colour": {"saturation": 25, "vibrance": 30, "temperature": 15, "tint": -10,
               "col_sat_red": 35, "col_lum_blue": -25},
    "black and white": {"black_white": True, "bw_red": 30, "bw_aqua": -20},
    "curves and levels": {"curve": [[0, 0], [115, 140], [255, 255]],
                          "curve_red": [[0, 8], [255, 248]],
                          "level_black": 12, "level_white": 240,
                          "level_gamma": 1.2},
    "clarity and texture": {"clarity": 30, "texture": 25},
    "negative texture": {"texture": -40},
    "noise reduction": {"noise_luminance": 35, "noise_colour": 25},
    "vignette and glow": {"vignette": -40, "vignette_feather": 180,
                          "glow_amount": 25, "glow_size": 55},
    "grain": {"grain": 30},
    "grain darkening": {"grain": 30, "grain_darken": True},
    "split toning and photo filter": {"split_shadow_amount": 40,
                                      "split_highlight_amount": 30,
                                      "photo_filter_density": 35},
    "dehaze": {"dehaze": 45},
    "nothing moved": {},
}


@requires_card
@pytest.mark.parametrize("label", sorted(EDITS))
def test_the_card_matches_the_processor(frame, label):
    processor, card = _both_ways(frame, EDITS[label])
    difference = np.abs(processor - card)
    assert difference.max() <= TOLERANCE, (
        f"{label}: worst difference {difference.max() * 255:.5f} of an 8-bit "
        f"code value, over {(difference > TOLERANCE).sum()} samples")


@requires_card
def test_sharpen_matches_apart_from_the_threshold_knife_edge(frame):
    processor, card = _both_ways(
        frame, {"sharpen": 55, "sharpen_radius": 2.0, "sharpen_reduce_noise": 20})
    difference = np.abs(processor - card)
    outliers = int((difference > TOLERANCE).sum())
    assert outliers <= KNIFE_EDGE_SAMPLES, (
        f"{outliers} samples differ, which is more than a threshold knife edge")
    assert np.median(difference) <= TOLERANCE


FULL_EDIT = {
    "exposure": .5, "contrast": 18, "shadows": 25, "highlights": -20,
    "saturation": 20, "vibrance": 20, "temperature": 12, "col_sat_red": 25,
    "clarity": 25, "vignette": -35, "glow_amount": 15,
    "grain": 12, "split_shadow_amount": 20,
    "curve": [[0, 0], [115, 140], [255, 255]]}


@requires_card
def test_a_full_edit_matches(frame):
    """Fifteen controls at once, and every sample still agrees to the last bit.

    Texture is deliberately not in this edit; it has its own test below. It
    routes through unsharp(), which applies a threshold, and a threshold
    cannot be held to a strict bound -- see the test that follows.
    """
    processor, card = _both_ways(frame, FULL_EDIT)
    difference = np.abs(processor - card)
    assert difference.max() <= TOLERANCE, (
        f"worst difference {difference.max() * 255:.5f} of an 8-bit code value, "
        f"over {(difference > TOLERANCE).sum()} samples")


@requires_card
def test_a_full_edit_with_texture_matches_apart_from_the_knife_edge(frame):
    """Texture is a thresholded sharpen, so it is bounded by count, not size.

    Positive Texture calls unsharp(radius .65, amount texture * .018,
    threshold 2/255), and unsharp zeroes any detail under the threshold. That
    is the same hard cutoff this file already documents for Sharpen: the blur
    underneath legitimately differs between the two devices by about one ULP,
    so a sample whose detail sits that close to the cutoff is zeroed on one
    device and kept on the other, and the output moves by exactly
    threshold * amount -- 0.00784 * 0.36 = 0.00282, or 0.72 of an 8-bit code
    value at Texture 20. No implementation of a thresholded sharpen can be
    held under TOLERANCE, which is why the strict test above leaves Texture
    out and this one counts samples instead.

    Measured on an RTX 3050: one sample in 4,200,000, in four of eight seeds.
    """
    processor, card = _both_ways(frame, dict(FULL_EDIT, texture=20))
    difference = np.abs(processor - card)
    outliers = int((difference > TOLERANCE).sum())
    assert outliers <= KNIFE_EDGE_SAMPLES, (
        f"{outliers} samples differ, which is more than a threshold knife edge")
    assert np.median(difference) <= TOLERANCE
    # The size of a knife-edge jump is capped by the threshold and the amount.
    # Anything bigger is a real divergence wearing the same costume.
    assert difference.max() <= (2.0 / 255.0) * (20 * .018) * 1.01, (
        f"worst difference {difference.max() * 255:.5f} of an 8-bit code value "
        f"is larger than the threshold knife edge can account for")


@requires_card
def test_a_frame_too_small_to_be_worth_offloading_stays_put(frame):
    small = highbit_image.FloatImage(
        np.random.default_rng(2).random((200, 300, 3)).astype(np.float32),
        ("R", "G", "B"))
    gpu_acceleration.configure("auto")
    out = highbit_image.apply_adjustments(
        small, {"exposure": .4}, editor_engine.DEFAULT_ADJUSTMENTS)
    assert not gpu_acceleration.is_device_array(out.pixels)


def test_the_processor_path_works_with_no_card_at_all(frame):
    """The fallback is the contract: nothing may depend on CuPy existing."""
    gpu_acceleration.configure("cpu")
    out = highbit_image.apply_adjustments(
        frame, {"exposure": .4, "clarity": 20, "sharpen": 30},
        editor_engine.DEFAULT_ADJUSTMENTS)
    gpu_acceleration.configure("auto")
    assert isinstance(out.pixels, np.ndarray)
    assert np.isfinite(out.pixels).all()


def test_the_status_never_claims_a_card_it_cannot_use():
    """What Preferences shows must match what the chain can actually do."""
    gpu_acceleration.configure("auto")
    status = gpu_acceleration.status()
    if status["available"]:
        module = gpu_acceleration.array_module()
        assert module.__name__.startswith("cupy")
        probe = module.zeros(4, dtype=module.float32) + module.float32(1.0)
        assert float(probe.sum().get()) == 4.0
    else:
        assert gpu_acceleration.array_module() is np


# ===== SNAPSMACK EOF =====
