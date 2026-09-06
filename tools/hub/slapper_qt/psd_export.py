"""Layered Photoshop export for SNAP SLAPPER projects.

SNAP SLAPPER filters and adjustments are not Photoshop-native operations. The
PSD therefore contains a guaranteed visible full-resolution composite plus a
named, hidden raster checkpoint for the base and every SNAP SLAPPER layer. A
photographer can inspect, extract, reorder, or paint on those checkpoints in
Photoshop without pretending they remain native SNAP SLAPPER adjustments.
"""

import copy
import os
import sys
import typing

# shiboken6 (PySide6's binding layer) injects a `Self` special form into the
# STDLIB typing module on Python 3.10, where no such form exists. Python
# 3.10's own Union type-check then rejects it ("Plain typing.Self is not valid
# as type argument") the moment psd_tools' class annotations use Self — which
# made every PSD export crash under the Qt shell. Remove the injected form
# (3.11+ has a real one; leave that alone) and evict a typing_extensions that
# already aliased it, so psd_tools re-imports the genuine backport.
if sys.version_info < (3, 11) and hasattr(typing, "Self"):
    del typing.Self
    sys.modules.pop("typing_extensions", None)

from psd_tools import PSDImage

import editor_engine
import photo_manager


def _checkpoint(document, layer_count):
    clone = editor_engine.EditorDocument(document.source_path)
    clone.adjustments = copy.deepcopy(document.adjustments)
    clone.geometry = copy.deepcopy(document.geometry)
    clone.retouched = copy.deepcopy(document.retouched)
    clone.layers = copy.deepcopy(document.layers[:layer_count])
    return clone.render().convert("RGBA")


def export_layered_psd(document, path):
    """Atomically write and validate a layered, full-resolution RGB PSD."""
    if os.path.splitext(path)[1].lower() != ".psd":
        path += ".psd"
    if photo_manager.same_file(path, document.source_path):
        raise ValueError("SNAP SLAPPER will not overwrite the original photograph.")

    composite = document.render().convert("RGBA")
    if composite.width > 30000 or composite.height > 30000:
        raise ValueError(
            "This photograph exceeds PSD's 30,000-pixel limit. Export TIFF instead.")
    psd = PSDImage.new(mode="RGB", size=composite.size, depth=8)

    base = psd.create_pixel_layer(
        _checkpoint(document, 0), name="00 Base image - SNAP SLAPPER edits")
    base.visible = False
    for index, layer in enumerate(document.layers, 1):
        name = str(layer.get("name") or f"Layer {index}")
        kind = str(layer.get("type") or "layer").replace("_", " ")
        checkpoint = psd.create_pixel_layer(
            _checkpoint(document, index),
            name=f"{index:02d} {name} - {kind} raster checkpoint")
        checkpoint.visible = False

    psd.create_pixel_layer(
        composite, name="SNAP SLAPPER Composite - export appearance")

    directory = os.path.dirname(os.path.abspath(path)) or "."
    os.makedirs(directory, exist_ok=True)
    temporary = path + ".tmp"
    try:
        psd.save(temporary)
        # Independent parse before publication: a corrupt/flat output never
        # replaces a previous good export.
        verified = PSDImage.open(temporary)
        expected_layers = len(document.layers) + 2
        if verified.size != composite.size or len(verified) != expected_layers:
            raise ValueError("PSD verification failed after writing the file.")
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            try:
                os.remove(temporary)
            except OSError:
                pass
    return path


# ===== SNAPSMACK EOF =====
