"""Installed-binary performance/equivalence gate for the SNAP SLAPPER core."""

from __future__ import annotations

import json
import os
import tempfile
import time

import numpy as np
from PIL import Image, ImageDraw

import editor_engine
import render_graph


def _photo(path, size=(2400, 1600)):
    width, height = size
    yy, xx = np.mgrid[:height, :width]
    values = np.empty((height, width, 3), dtype=np.uint8)
    values[:, :, 0] = np.uint8((xx * 255) // max(1, width - 1))
    values[:, :, 1] = np.uint8((yy * 255) // max(1, height - 1))
    values[:, :, 2] = np.uint8(((xx // 7 + yy // 5) % 256))
    Image.fromarray(values, "RGB").save(path, quality=94)


def _mask(size, kind):
    image = Image.new("L", size, 0)
    draw = ImageDraw.Draw(image)
    if kind == "ellipse":
        draw.ellipse((size[0] // 8, size[1] // 8,
                      size[0] * 7 // 8, size[1] * 7 // 8), fill=255)
    else:
        for y in range(size[1]):
            value = round(255 * y / max(1, size[1] - 1))
            draw.line((0, y, size[0], y), fill=value)
    return editor_engine._mask_to_text(image)


def reference_document(folder):
    source = os.path.join(folder, "reference.jpg")
    texture = os.path.join(folder, "texture.png")
    _photo(source)
    _photo(texture, (1200, 900))
    document = editor_engine.EditorDocument(source)
    document.adjustments.update({"exposure": .18, "highlights": -24,
                                 "shadows": 16, "temperature": 7})
    document.retouched.append({"type": "patch", "x": .68, "y": .42,
                               "source_x": .62, "source_y": .42, "radius": .025})
    tone = document.add_adjustment_layer("Tone")
    tone["adjustments"].update({"contrast": 14, "clarity": 9})
    tone["mask"] = _mask((2400, 1600), "gradient")
    colour = document.add_adjustment_layer("Colour")
    colour["adjustments"].update({"vibrance": 18, "saturation": -4})
    colour["mask"] = _mask((2400, 1600), "ellipse")
    texture_layer = document.add_image_layer(texture, "Texture")
    texture_layer.update({"fit": "cover", "opacity": .18, "blend": "soft_light"})
    grain = document.add_filter_layer("film_grain", "Grain")
    grain["settings"].update({"amount": 12, "size": 35, "seed": 417})
    vignette = document.add_adjustment_layer("Vignette")
    vignette["adjustments"].update({"vignette": -22, "vignette_size": 76,
                                    "vignette_feather": 58})
    finishing = document.add_adjustment_layer("Finishing")
    finishing["adjustments"].update({"sharpen": 8, "whites": 4})
    return document, finishing


def run(report_path):
    with tempfile.TemporaryDirectory(prefix="snap-slapper-core-gate-") as folder:
        graph = render_graph.RenderGraph(render_graph.RenderCache(512 * 1024 * 1024))
        document, finishing = reference_document(folder)
        # During continuous movement the editor is allowed to use a softer
        # viewport proxy, but not different mathematics. 600×450 is the
        # production interaction target; release immediately refines at the
        # actual fitted viewport dimensions.
        viewport = (600, 450)
        cold = graph.render(document, viewport)
        samples = []
        for value in (2, 4, 6, 8, 10, 12, 10, 8, 6, 4, 2, 0):
            finishing["adjustments"]["brightness"] = value
            document.revision += 1
            samples.append(graph.render(document, viewport).elapsed_ms)
        exact = graph.render(document, viewport).image.pixels
        reference = document._render_float_reference(viewport).pixels
        maximum_error = float(np.max(np.abs(exact - reference)))
        hard_max = max(samples)
        updates_per_second = 1000.0 / max(0.001, sum(samples) / len(samples))
        passed = hard_max <= 100.0 and updates_per_second >= 15.0 and maximum_error == 0.0
        report = {
            "passed": passed,
            "reference": {"source": [2400, 1600], "layers": 6,
                          "full_resolution_masks": 2, "repair_layers": 1,
                          "textures": 1, "vignette": 1},
            "cold_ms": round(cold.elapsed_ms, 3),
            "edit_samples_ms": [round(value, 3) for value in samples],
            "first_response_hard_max_ms": round(hard_max, 3),
            "displayed_updates_per_second": round(updates_per_second, 3),
            "preview_reference_max_error": maximum_error,
        }
        with open(report_path, "w", encoding="utf-8") as stream:
            json.dump(report, stream, indent=2, sort_keys=True)
        return passed


# ===== SNAPSMACK EOF =====
