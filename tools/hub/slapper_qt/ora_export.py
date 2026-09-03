"""OpenRaster recovery export for SNAP SLAPPER documents.

SNAP SLAPPER adjustments are not native OpenRaster operations. As with PSD export,
the ORA contains one visible composite plus hidden, named cumulative checkpoints.
This is truthful, portable recovery—not a claim that foreign editors can reconstruct
SNAP SLAPPER's parameter engine.
"""

import copy
import io
import os
import zipfile
import xml.etree.ElementTree as ET

from PIL import Image

import editor_engine
import photo_manager


MIMETYPE = b"image/openraster"


def _png_bytes(image):
    stream = io.BytesIO()
    image.convert("RGBA").save(stream, "PNG")
    return stream.getvalue()


def _checkpoint(document, layer_count):
    clone = editor_engine.EditorDocument(document.source_path)
    clone.adjustments = copy.deepcopy(document.adjustments)
    clone.geometry = copy.deepcopy(document.geometry)
    clone.retouched = copy.deepcopy(document.retouched)
    clone.layers = copy.deepcopy(document.layers[:layer_count])
    return clone.render().convert("RGBA")


def export_openraster(document, path):
    if os.path.splitext(path)[1].lower() != ".ora":
        path += ".ora"
    if photo_manager.same_file(path, document.source_path):
        raise ValueError("SNAP SLAPPER will not overwrite the original photograph.")

    composite = document.render().convert("RGBA")
    root = ET.Element("image", {
        "version": "0.0.1", "w": str(composite.width), "h": str(composite.height),
        "name": os.path.basename(document.source_path),
    })
    stack = ET.SubElement(root, "stack", {"name": "SNAP SLAPPER recovery"})

    layer_files = []
    # ORA stack order is top first. The visible composite guarantees appearance.
    layer_files.append(("data/000-composite.png", composite,
                        "SNAP SLAPPER Composite - export appearance", "visible"))
    checkpoints = [(0, "00 Base image - SNAP SLAPPER edits")]
    checkpoints.extend((index,
                        f"{index:02d} {layer.get('name') or f'Layer {index}'} - "
                        f"{str(layer.get('type') or 'layer').replace('_', ' ')} raster checkpoint")
                       for index, layer in enumerate(document.layers, 1))
    for sequence, (count, name) in enumerate(reversed(checkpoints), 1):
        layer_files.append((f"data/{sequence:03d}-checkpoint.png",
                            _checkpoint(document, count), name, "hidden"))
    for filename, _image, name, visibility in layer_files:
        ET.SubElement(stack, "layer", {
            "name": name, "src": filename, "visibility": visibility,
            "composite-op": "svg:src-over", "opacity": "1.0",
        })

    thumbnail = composite.copy()
    thumbnail.thumbnail((256, 256), Image.Resampling.LANCZOS)
    xml = ET.tostring(root, encoding="utf-8", xml_declaration=True)
    directory = os.path.dirname(os.path.abspath(path)) or "."
    os.makedirs(directory, exist_ok=True)
    temporary = path + ".tmp"
    try:
        with zipfile.ZipFile(temporary, "w", allowZip64=True) as archive:
            # The ORA spec requires this exact first, uncompressed member.
            archive.writestr("mimetype", MIMETYPE, compress_type=zipfile.ZIP_STORED)
            archive.writestr("stack.xml", xml, compress_type=zipfile.ZIP_DEFLATED)
            archive.writestr("mergedimage.png", _png_bytes(composite),
                             compress_type=zipfile.ZIP_DEFLATED)
            archive.writestr("Thumbnails/thumbnail.png", _png_bytes(thumbnail),
                             compress_type=zipfile.ZIP_DEFLATED)
            for filename, image, _name, _visibility in layer_files:
                archive.writestr(filename, _png_bytes(image),
                                 compress_type=zipfile.ZIP_DEFLATED)
        with zipfile.ZipFile(temporary, "r") as archive:
            members = archive.infolist()
            if not members or members[0].filename != "mimetype" or \
                    members[0].compress_type != zipfile.ZIP_STORED:
                raise ValueError("OpenRaster verification failed: invalid mimetype member.")
            parsed = ET.fromstring(archive.read("stack.xml"))
            if parsed.get("w") != str(composite.width) or parsed.get("h") != str(composite.height):
                raise ValueError("OpenRaster verification failed: incorrect canvas size.")
            with Image.open(io.BytesIO(archive.read("mergedimage.png"))) as merged:
                if merged.size != composite.size:
                    raise ValueError("OpenRaster verification failed: incorrect composite.")
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            try:
                os.remove(temporary)
            except OSError:
                pass
    return path


# ===== SNAPSMACK EOF =====
