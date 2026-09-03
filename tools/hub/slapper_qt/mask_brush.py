"""A small paint-on-the-mask canvas for local adjustments.

A layer mask is a grayscale image: white = the layer's effect shows, black =
it's hidden. This widget lets the photographer paint that mask by hand with a
round brush — white to reveal, black to hide — the way every editor does it.
It shows the photo with the hidden areas tinted red so you can see what you're
doing, and hands back a PIL ``L`` mask for the engine to store on the layer.
"""

from PySide6.QtCore import Qt, Signal, QSize, QPoint, QRectF
from PySide6.QtGui import (
    QPainter, QPixmap, QImage, QColor, QRadialGradient, QPen, QBrush,
)
from PySide6.QtWidgets import QWidget

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


class MaskBrushCanvas(QWidget):
    """Paint white/black onto a layer mask with a soft round brush."""

    mask_changed = Signal()   # emitted when a stroke finishes

    def __init__(self, parent=None):
        super().__init__(parent)
        self.setObjectName("MaskBrushCanvas")
        self._photo = None        # QPixmap scaled to the display box
        self._mask = None         # QImage Grayscale8 at display size
        self._overlay = None      # cached red tint of the hidden areas
        self._paint_white = False  # False = hide (black), True = reveal (white)
        self._radius = 22
        self._hardness = 70
        self._opacity = 100
        self._flow = 100
        self._last = None
        self._hover = None
        self.setMinimumSize(QSize(120, 90))
        self.setMaximumHeight(BOX_H)
        self.setMouseTracking(True)
        self.setCursor(Qt.BlankCursor)

    # --- setup --------------------------------------------------------------
    def load(self, pil_photo, pil_mask=None):
        """Show ``pil_photo`` (a PIL image) and seed the mask (PIL 'L' or None).
        With no mask, start fully white — the layer shows everywhere until the
        photographer paints black to hide part of it."""
        photo = pil_photo.convert("RGB")
        photo.thumbnail((BOX_W, BOX_H), Image.Resampling.LANCZOS)
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
        self.setFixedSize(width, height)
        self._rebuild_overlay()
        self.update()

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
        self._rebuild_overlay()
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
        self._rebuild_overlay()

    def _rebuild_overlay(self):
        """Cache a red tint of the hidden (black) areas, built once per stroke
        with PIL's fast per-band point() — never pixel-by-pixel in paintEvent."""
        if self._mask is None:
            self._overlay = None
            return
        mask_pil = qimage_l_to_pil(self._mask)
        alpha = mask_pil.point(lambda v: int((255 - v) * 0.45))
        red = Image.new("RGBA", mask_pil.size, (220, 40, 40, 0))
        red.putalpha(alpha)
        data = red.tobytes("raw", "RGBA")
        qimg = QImage(data, red.width, red.height, red.width * 4,
                      QImage.Format_RGBA8888).copy()
        self._overlay = QPixmap.fromImage(qimg)

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
    def paintEvent(self, event):
        painter = QPainter(self)
        painter.fillRect(self.rect(), QColor(20, 20, 20))
        if self._photo is None:
            painter.setPen(QColor(150, 150, 150))
            painter.drawText(self.rect(), Qt.AlignCenter, "Open a photo")
            painter.end()
            return
        painter.drawPixmap(0, 0, self._photo)
        # tint the hidden (black) areas red so the mask is visible
        if self._overlay is not None:
            painter.drawPixmap(0, 0, self._overlay)
        # brush cursor
        if self._hover is not None:
            painter.setPen(QPen(QColor(57, 255, 20), 1))
            painter.setBrush(Qt.NoBrush)
            painter.drawEllipse(self._hover, self._radius, self._radius)
        painter.end()

# ===== SNAPSMACK EOF =====
