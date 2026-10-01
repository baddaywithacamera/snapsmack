"""The single seam between the image engine and Qt.

The engine speaks PIL. Qt speaks QPixmap. This module is the only place that
converts between them, so the rest of the Qt code never imports PIL and the
engine never imports Qt.
"""

from PIL import Image, ImageOps
from PySide6.QtGui import QImage, QPixmap


def pil_to_qpixmap(image) -> QPixmap:
    """Convert a PIL image (any mode) to a QPixmap for display."""
    if image.mode != "RGBA":
        image = image.convert("RGBA")
    data = image.tobytes("raw", "RGBA")
    qimage = QImage(data, image.width, image.height,
                    image.width * 4, QImage.Format_RGBA8888)
    # .copy() detaches the QImage from the temporary ``data`` buffer so the
    # pixels survive after this function returns.
    return QPixmap.fromImage(qimage.copy())


def render_pixmap(document, max_size=None) -> QPixmap:
    """Render an EditorDocument to a QPixmap at an optional preview size.

    ``max_size`` is an (width, height) cap. Passing a viewport-sized cap keeps
    slider drags smooth because the engine applies adjustments to the smaller
    image, not the full-resolution original.
    """
    image = document.render(max_size=max_size)
    return pil_to_qpixmap(image)


def original_pixmap(source_path, max_size=None) -> QPixmap:
    """The untouched original photograph (for before/after comparison)."""
    with Image.open(source_path) as source:
        image = ImageOps.exif_transpose(source).convert("RGBA")
    if max_size:
        image.thumbnail(max_size, Image.Resampling.LANCZOS)
    return pil_to_qpixmap(image)


def display_thumbnail(source_path, max_size):
    """Decode a bounded UI thumbnail, including high-bit TIFF variants.

    Pillow remains the quick path for ordinary web images.  TIFF is routed
    through the editor's authoritative OpenImageIO decoder: camera, HDR, and
    external-editor TIFFs can use compressions/sample depths which the Pillow
    build bundled with the desktop app cannot display.  Only the disposable
    8-bit proxy crosses back into Qt; the source file is never rewritten.
    """
    extension = __import__("os").path.splitext(source_path)[1].lower()
    if extension in {".tif", ".tiff"}:
        import highbit_image
        return highbit_image.read(source_path, maximum=max_size).display_proxy()
    with Image.open(source_path) as source:
        try:
            source.draft("RGB", tuple(max_size))
        except Exception:  # draft is an optional JPEG acceleration hint
            pass
        image = ImageOps.exif_transpose(source).convert("RGBA")
    image.thumbnail(tuple(max_size), Image.Resampling.LANCZOS)
    return image

# ===== SNAPSMACK EOF =====
