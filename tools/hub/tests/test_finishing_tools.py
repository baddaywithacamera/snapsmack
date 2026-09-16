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
import highbit_image
from slapper_qt import masks


def _source(path):
    pixels = np.zeros((40, 60, 3), dtype=np.float32)
    pixels[:, :, :] = .1
    pixels[8:20, 8:20, 0] = .9
    highbit_image.write(highbit_image.FloatImage(pixels, ("R", "G", "B")), path)
    return path


def test_clone_stamp_samples_source_in_float_composite(tmp_path):
    document = editor_engine.EditorDocument(str(_source(tmp_path / "source.tif")))
    document.retouched.append({"type": "clone", "x": .75, "y": .65,
                               "source_x": .23, "source_y": .35, "size": 12})
    output = document.render_float().pixels
    assert output[26, 45, 0] > .45


def test_corner_warp_changes_float_layer_placement():
    pixels = np.zeros((8, 8, 4), dtype=np.float32)
    pixels[:, :, 0] = 1; pixels[:, :, 3] = 1
    image = highbit_image.FloatImage(pixels, ("R", "G", "B", "A"))
    plain = highbit_image.place_layer(image, (20, 20), {"x": .5, "y": .5})
    warped = highbit_image.place_layer(image, (20, 20), {
        "x": .5, "y": .5, "warp_corners": [[.4, 0], [0, 0], [0, 0], [0, 0]]})
    assert not np.array_equal(plain.pixels, warped.pixels)


def test_saved_selection_and_warp_survive_project_round_trip(tmp_path):
    source = _source(tmp_path / "source.tif")
    document = editor_engine.EditorDocument(str(source))
    layer = document.add_adjustment_layer()
    layer["mask"] = editor_engine._mask_to_text(Image.new("L", (60, 40), 255))
    document.saved_selections["Subject"] = layer["mask"]
    image_layer = document.add_image_layer(str(source))
    image_layer["transform"]["warp_corners"][0] = [.1, -.1]
    document.record("Finishing tools")
    project = tmp_path / "finish.slapper"
    document.save_project(str(project))
    # This test created both external files itself; imported projects otherwise
    # require the same explicit local-file approval as the real UI.
    loaded = editor_engine.EditorDocument.load_project(
        str(project), trust_external_source=True)
    assert loaded.saved_selections["Subject"] == layer["mask"]
    assert loaded.layers[-1]["transform"]["warp_corners"][0] == [.1, -.1]


def test_slider_preview_reuses_larger_float_source(monkeypatch, tmp_path):
    source = _source(tmp_path / "cached.tif")
    editor_engine._FLOAT_SOURCE_CACHE.clear()
    original = highbit_image.read
    calls = []

    def counted(path, maximum=None):
        calls.append(maximum)
        return original(path, maximum)

    monkeypatch.setattr(editor_engine.highbit, "read", counted)
    document = editor_engine.EditorDocument(str(source))
    document.render_float((1600, 1000))
    document.adjustments["exposure"] = .5
    document.render_float((900, 700))
    document.adjustments["exposure"] = 1.0
    document.render_float((900, 700))
    assert len(calls) == 1


def test_short_diagonal_gradient_does_not_create_far_corner_wedge():
    mask = masks.drawn_linear_mask((400, 300), .70, .70, .80, .80)
    # Beyond the white end remains white; it must never fall off a finite ramp
    # and turn black again in the bottom-right corner.
    assert mask.getpixel((399, 299)) == 255
    assert mask.getpixel((0, 0)) == 0
    diagonal = [mask.getpixel((x, round(x * 299 / 399))) for x in range(400)]
    assert diagonal == sorted(diagonal)
