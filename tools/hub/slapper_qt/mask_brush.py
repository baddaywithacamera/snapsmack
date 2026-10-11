"""A small paint-on-the-mask canvas for local adjustments.

A layer mask is a grayscale image: white = the layer's effect shows, black =
it's hidden. This widget lets the photographer paint that mask by hand with a
round brush — white to reveal, black to hide — the way every editor does it.
It shows the mask itself in black and white and hands back a PIL ``L`` mask
for the engine to store on the layer. The main canvas shows the photo result.
"""

from PySide6.QtCore import Qt, Signal, QSize, QPoint, QRectF
from PySide6.QtGui import (
    QPainter, QPixmap, QImage, QColor, QRadialGradient, QPen, QBrush,
)
from PySide6.QtWidgets import QWidget

import numpy as np
from PIL import Image

BOX_W = 238      # display width in the rail
BOX_H = 172      # maximum display height


def qimage_l_to_pil(qimg):
    """Grayscale8 QImage -> PIL 'L', stripping any row padding."""
    qimg = qimg.convertToFormat(QImage.Format_Grayscale8)
    width, height = qimg.width(), qimg.height()
    stride = qimg.bytesPerLine()
    raw = bytes(qimg.constBits())[:stride * height]
    if stride == width:
        return Image.frombytes("L", (width, height), raw)
    rows = bytearray()
    for y in range(height):
        rows += raw[y * stride: y * stride + width]
    return Image.frombytes("L", (width, height), bytes(rows))


def pil_to_qimage_l(pil, size):
    pil = pil.convert("L").resize(size, Image.Resampling.LANCZOS)
    data = pil.tobytes("raw", "L")
    return QImage(data, size[0], size[1], size[0],
                  QImage.Format_Grayscale8).copy()


def red_wash(mask, tint_white=False, alpha=110):
    """A translucent red QImage over the masked area, or None if nothing is.

    One definition, used by the small rail preview and by the full photograph,
    so "red" means the same thing in both places. ``tint_white`` picks which end
    of the mask is the painted one: Generative Fill and AI Heal paint WHITE to
    mark what a model may rebuild, while a layer mask paints BLACK to hide part
    of a layer -- so on a layer mask, red is the hidden area.
    """
    grey = np.asarray(mask.convert("L"), dtype=np.uint8)
    height, width = grey.shape
    if not width or not height:
        return None
    painted = grey if tint_white else (255 - grey)
    strength = (painted.astype(np.uint16) * int(alpha) // 255).astype(np.uint8)
    if not strength.any():
        return None
    # Qt wants premultiplied ARGB32 in BGRA byte order.
    red = (strength.astype(np.uint16) * 230 // 255).astype(np.uint8)
    zero = np.zeros_like(strength)
    argb = np.dstack((zero, zero, red, strength)).copy()
    return QImage(argb.data, width, height, width * 4,
                  QImage.Format_ARGB32_Premultiplied).copy()


class MaskBrushCanvas(QWidget):
    """Paint white/black onto a layer mask with a soft round brush."""

    mask_changed = Signal()   # emitted when a stroke finishes

    def __init__(self, parent=None, *, box_size=(BOX_W, BOX_H), tint_white=False):
        super().__init__(parent)
        self.setObjectName("MaskBrushCanvas")
        self._photo = None        # QPixmap scaled to the display box
        self._baseline = None     # the mask as loaded, for delta merges
        self._mask = None         # QImage Grayscale8 at display size
        self._paint_white = False  # False = hide (black), True = reveal (white)
        self._radius = 22
        self._hardness = 70
        self._opacity = 100
        self._flow = 100
        self._last = None
        self._hover = None
        self._box_size = tuple(box_size)
        self._tint_white = bool(tint_white)
        self.setMinimumSize(QSize(120, 90))
        self.setMaximumHeight(self._box_size[1])
        self.setMouseTracking(True)
        self.setCursor(Qt.BlankCursor)

    # --- setup --------------------------------------------------------------
    def load(self, pil_photo, pil_mask=None):
        """Show ``pil_photo`` (a PIL image) and seed the mask (PIL 'L' or None).
        With no mask, start fully white — the layer shows everywhere until the
        photographer paints black to hide part of it."""
        photo = pil_photo.convert("RGB")
        photo.thumbnail(self._box_size, Image.Resampling.LANCZOS)
        width, height = photo.size
        data = photo.tobytes("raw", "RGB")
        qphoto = QImage(data, width, height, width * 3,
                        QImage.Format_RGB888).copy()
        self._photo = QPixmap.fromImage(qphoto)
        if pil_mask is not None:
            self._mask = pil_to_qimage_l(pil_mask, (width, height))
        else:
            self._mask = QImage(width, height, QImage.Format_Grayscale8)
            self._mask.fill(255)
        # Keep what was loaded. This preview is far smaller than the mask the
        # photograph is painted at, so the editor merges only the pixels this
        # widget actually changed instead of replacing a detailed mask with an
        # enlargement of a 238-pixel-wide one.
        self._baseline = self._mask.copy()
        self.setFixedSize(width, height)
        self.update()

    def baseline_pil(self, size):
        """The mask as it was loaded, at ``size`` -- for merging changes."""
        if getattr(self, "_baseline", None) is None:
            return None
        return qimage_l_to_pil(self._baseline).resize(
            size, Image.Resampling.LANCZOS)

    def set_paint_white(self, white):
        self._paint_white = bool(white)

    def set_radius(self, radius):
        self._radius = max(3, int(radius))
        self.update()

    def set_hardness(self, value):
        self._hardness = max(0, min(100, int(value)))

    def set_opacity(self, value):
        self._opacity = max(1, min(100, int(value)))

    def set_flow(self, value):
        self._flow = max(1, min(100, int(value)))

    def fill(self, white):
        """Paint-bucket the whole mask with Reveal (white) or Hide (black)."""
        if self._mask is None:
            return
        self._mask.fill(255 if white else 0)
        self.update()
        self.mask_changed.emit()

    def has_mask(self):
        return self._mask is not None

    def mask_pil(self, size):
        """The painted mask as a PIL 'L' image resized to ``size``."""
        if self._mask is None:
            return None
        pil = qimage_l_to_pil(self._mask)
        return pil.resize(size, Image.Resampling.LANCZOS)

    # --- painting -----------------------------------------------------------
    def _paint_at(self, point):
        if self._mask is None:
            return
        painter = QPainter(self._mask)
        painter.setRenderHint(QPainter.Antialiasing, True)
        value = 255 if self._paint_white else 0
        # A soft round brush: solid core fading at the rim.
        gradient = QRadialGradient(point, self._radius)
        alpha = round(255 * (self._opacity / 100.0) * (self._flow / 100.0))
        core = QColor(value, value, value, alpha)
        edge = QColor(value, value, value, 0)
        gradient.setColorAt(0.0, core)
        gradient.setColorAt(max(0.0, min(.98, self._hardness / 100.0)), core)
        gradient.setColorAt(1.0, edge)
        painter.setPen(Qt.NoPen)
        painter.setBrush(QBrush(gradient))
        painter.drawEllipse(point, self._radius, self._radius)
        if self._last is not None:
            # join fast strokes so there are no gaps between samples
            pen = QPen(QColor(value, value, value, alpha))
            pen.setWidth(self._radius * 2)
            pen.setCapStyle(Qt.RoundCap)
            painter.setPen(pen)
            painter.drawLine(self._last, point)
        painter.end()

    def mousePressEvent(self, event):
        if self._mask is None or event.button() != Qt.LeftButton:
            return
        self._last = event.position().toPoint()
        self._paint_at(self._last)
        self.update()

    def mouseMoveEvent(self, event):
        self._hover = event.position().toPoint()
        if self._mask is not None and (event.buttons() & Qt.LeftButton):
            point = event.position().toPoint()
            self._paint_at(point)
            self._last = point
        self.update()

    def mouseReleaseEvent(self, event):
        if self._last is not None:
            self._last = None
            self.mask_changed.emit()

    def leaveEvent(self, event):
        self._hover = None
        self.update()

    # --- display ------------------------------------------------------------
    def _tint_overlay(self):
        """A translucent red wash over the painted area, or None.

        The mask is a selection, not the picture. ``red_wash`` above holds the
        one definition of which end is painted; the full-photograph canvas uses
        the same function so the colour means the same thing in both places.
        """
        if self._mask is None:
            return None
        return red_wash(qimage_l_to_pil(self._mask), self._tint_white)

    def paintEvent(self, event):
        painter = QPainter(self)
        painter.fillRect(self.rect(), QColor(20, 20, 20))
        if self._photo is None:
            painter.setPen(QColor(150, 150, 150))
            painter.drawText(self.rect(), Qt.AlignCenter, "Open a photo")
            painter.end()
            return
        # The photograph itself. This was missing: paintEvent checked _photo
        # for None and then drew only the mask, so Generative Fill -- whose
        # mask starts empty -- showed a solid black box, and the layer mask
        # brush showed a solid white one. The widget has never shown a photo.
        painter.drawPixmap(0, 0, self._photo)
        overlay = self._tint_overlay()
        if overlay is not None:
            painter.drawImage(0, 0, overlay)
        # brush cursor
        if self._hover is not None:
            painter.setPen(QPen(QColor(57, 255, 20), 1))
            painter.setBrush(Qt.NoBrush)
            painter.drawEllipse(self._hover, self._radius, self._radius)
        painter.end()

# ===== SNAPSMACK EOF =====
