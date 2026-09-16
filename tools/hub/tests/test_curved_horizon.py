"""Curved-horizon correction stays float32 and flattens the traced guide."""

import os
import sys

import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
HUB = os.path.dirname(HERE)
if HUB not in sys.path:
    sys.path.insert(0, HUB)

import highbit_image as highbit


def test_traced_curve_is_mapped_to_a_horizontal_line():
    width, height = 241, 141
    pixels = np.zeros((height, width, 3), dtype=np.float32)
    xs = np.arange(width)
    curve = np.rint(70 + 20 * np.cos((xs / (width - 1) - .5) * np.pi)).astype(int)
    pixels[curve, xs, :] = 1.0
    image = highbit.FloatImage(pixels, ("R", "G", "B"), "float-test")
    points = [[x / (width - 1), curve[x] / (height - 1)] for x in range(width)]

    result = highbit.straighten_curved_horizon(image, points, 1.0, 0.6)

    peaks = np.argmax(result.pixels[:, :, 0], axis=0)
    assert result.pixels.dtype == np.float32
    assert np.percentile(np.abs(peaks - np.median(peaks)), 95) <= 1


def test_zero_strength_is_visually_neutral():
    rng = np.random.default_rng(42)
    pixels = rng.random((20, 30, 3), dtype=np.float32)
    image = highbit.FloatImage(pixels, ("R", "G", "B"), "float-test")
    points = [[0, .2], [.5, .8], [1, .2]]
    result = highbit.straighten_curved_horizon(image, points, 0.0, 1.0)
    assert np.allclose(result.pixels, pixels)

