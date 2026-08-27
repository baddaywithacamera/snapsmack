"""The single seam between the image engine and Qt.

The engine speaks PIL. Qt speaks QPixmap. This module is the only place that
converts between them, so the rest of the Qt code never imports PIL and the
engine never imports Qt.
"""

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

# ===== SNAPSMACK EOF =====
