"""A compact tone-curve editor with per-channel (RGB / R / G / B) curves.

Drag a point to bend the curve; click empty space to add a point; right-click a
point to remove it. Emits the channel's adjustment key and its point list so
the editor can store it straight onto the layer — this is what the per-colour
casts (cross-process, film looks) are built from.
"""

from PySide6.QtCore import Qt, Signal, QRectF, QPointF
from PySide6.QtGui import QPainter, QPen, QColor, QBrush
from PySide6.QtWidgets import QWidget, QVBoxLayout, QHBoxLayout, QPushButton, QButtonGroup

SIZE = 210
IDENTITY = [[0, 0], [255, 255]]

CHANNELS = [("RGB", "curve"), ("R", "curve_red"),
            ("G", "curve_green"), ("B", "curve_blue")]
CHANNEL_COLOUR = {"curve": "#dddddd", "curve_red": "#ff5a5a",
                  "curve_green": "#5aff5a", "curve_blue": "#5a9cff"}


class _Canvas(QWidget):
    changed = Signal(str, list)      # adjustment key, points

    def __init__(self, parent=None):
        super().__init__(parent)
        self.setFixedSize(SIZE, SIZE)
        self.setMouseTracking(True)
        self._curves = {key: [list(p) for p in IDENTITY] for _n, key in CHANNELS}
        self._key = "curve"
        self._drag = None

    def set_curves(self, adjustments):
        for _n, key in CHANNELS:
            pts = adjustments.get(key) or IDENTITY
            self._curves[key] = [[int(x), int(y)] for x, y in pts]
        self.update()

    def set_channel(self, key):
        self._key = key
        self.update()

    # --- geometry -----------------------------------------------------------
    def _to_px(self, point):
        x = point[0] / 255.0 * (self.width() - 1)
        y = (1 - point[1] / 255.0) * (self.height() - 1)
        return QPointF(x, y)

    def _to_val(self, pos):
        x = max(0, min(255, round(pos.x() / (self.width() - 1) * 255)))
        y = max(0, min(255, round((1 - pos.y() / (self.height() - 1)) * 255)))
        return [int(x), int(y)]

    def _points(self):
        return self._curves[self._key]

    def _nearest(self, pos, radius=12):
        for index, point in enumerate(self._points()):
            if (self._to_px(point) - pos).manhattanLength() <= radius:
                return index
        return None

    # --- interaction --------------------------------------------------------
    def mousePressEvent(self, event):
        pos = event.position()
        index = self._nearest(pos)
        if event.button() == Qt.RightButton:
            if index is not None and 0 < index < len(self._points()) - 1:
                self._points().pop(index)
                self._emit()
            return
        if event.button() != Qt.LeftButton:
            return
        if index is None:
            value = self._to_val(pos)
            self._points().append(value)
            self._points().sort(key=lambda p: p[0])
            index = self._points().index(value)
            self._emit()
        self._drag = index

    def mouseMoveEvent(self, event):
        if self._drag is None:
            return
        points = self._points()
        value = self._to_val(event.position())
        last = len(points) - 1
        if self._drag == 0:
            value[0] = 0                       # endpoints keep their x
        elif self._drag == last:
            value[0] = 255
        else:
            low = points[self._drag - 1][0] + 1
            high = points[self._drag + 1][0] - 1
            value[0] = max(low, min(high, value[0]))
        points[self._drag] = value
        self._emit()

    def mouseReleaseEvent(self, event):
        self._drag = None

    def _emit(self):
        self.changed.emit(self._key, [list(p) for p in self._points()])
        self.update()

    # --- display ------------------------------------------------------------
    def paintEvent(self, event):
        painter = QPainter(self)
        painter.setRenderHint(QPainter.Antialiasing, True)
        painter.fillRect(self.rect(), QColor(20, 20, 20))
        grid = QPen(QColor(60, 60, 60), 1)
        painter.setPen(grid)
        for i in range(1, 4):
            x = i / 4 * self.width()
            y = i / 4 * self.height()
            painter.drawLine(QPointF(x, 0), QPointF(x, self.height()))
            painter.drawLine(QPointF(0, y), QPointF(self.width(), y))
        painter.setPen(QPen(QColor(45, 45, 45), 1))
        painter.drawLine(QPointF(0, self.height()), QPointF(self.width(), 0))
        # every channel faintly, the active one bright
        for _n, key in CHANNELS:
            active = key == self._key
            colour = QColor(CHANNEL_COLOUR[key])
            if not active:
                colour.setAlpha(60)
            painter.setPen(QPen(colour, 2 if active else 1))
            pts = [self._to_px(p) for p in self._curves[key]]
            for a, b in zip(pts, pts[1:]):
                painter.drawLine(a, b)
        # handles for the active curve
        painter.setBrush(QBrush(QColor(CHANNEL_COLOUR[self._key])))
        painter.setPen(QPen(QColor(20, 20, 20), 1))
        for point in self._points():
            centre = self._to_px(point)
            painter.drawEllipse(centre, 4, 4)
        painter.end()


class CurveEditor(QWidget):
    """Channel picker + the curve canvas."""

    changed = Signal(str, list)

    def __init__(self, parent=None):
        super().__init__(parent)
        layout = QVBoxLayout(self)
        layout.setContentsMargins(12, 4, 12, 6)
        layout.setSpacing(6)
        tabs = QHBoxLayout()
        tabs.setSpacing(4)
        self._group = QButtonGroup(self)
        self._group.setExclusive(True)
        for name, key in CHANNELS:
            btn = QPushButton(name)
            btn.setObjectName("MaskTypeBtn")
            btn.setCheckable(True)
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(lambda _c, k=key: self.canvas.set_channel(k))
            self._group.addButton(btn)
            tabs.addWidget(btn)
            if key == "curve":
                btn.setChecked(True)
        layout.addLayout(tabs)
        self.canvas = _Canvas()
        self.canvas.changed.connect(self.changed)
        layout.addWidget(self.canvas, 0, Qt.AlignHCenter)

    def set_curves(self, adjustments):
        self.canvas.set_curves(adjustments)

# ===== SNAPSMACK EOF =====
