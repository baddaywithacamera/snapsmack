"""Draggable asymmetric canvas selector for Generative Expand."""

from PySide6.QtCore import Qt, Signal, QRectF
from PySide6.QtGui import QColor, QImage, QPainter, QPen, QPixmap
from PySide6.QtWidgets import QWidget


def clamp_edges(start, candidate, allowed, steps=28):
    """Return the furthest point on a drag that satisfies the caller's budget."""
    if allowed(candidate):
        return candidate
    low, high = 0.0, 1.0
    for _ in range(steps):
        fraction = (low + high) / 2
        probe = {name: start[name] + (candidate[name] - start[name]) * fraction
                 for name in start}
        if allowed(probe):
            low = fraction
        else:
            high = fraction
    return {name: start[name] + (candidate[name] - start[name]) * low
            for name in start}


class ExpandCanvas(QWidget):
    edges_changed = Signal(object)

    def __init__(self, photo, parent=None):
        super().__init__(parent)
        self.setMinimumSize(500, 280)
        self.setMouseTracking(True)
        image = photo.convert("RGB")
        data = image.tobytes("raw", "RGB")
        self._photo = QPixmap.fromImage(QImage(
            data, image.width, image.height, image.width * 3,
            QImage.Format_RGB888).copy())
        self.edges = {name: 0.0 for name in ("left", "top", "right", "bottom")}
        self._drag = None
        self._start = None
        self._start_edges = None
        self._constraint = None

    def set_constraint(self, constraint):
        """Set a predicate that defines the legal cumulative expansion budget."""
        self._constraint = constraint

    def _original_rect(self):
        available = self.rect().adjusted(70, 50, -70, -50)
        scaled = self._photo.size()
        scaled.scale(available.size(), Qt.KeepAspectRatio)
        x = (self.width() - scaled.width()) / 2
        y = (self.height() - scaled.height()) / 2
        return QRectF(x, y, scaled.width(), scaled.height())

    def _outer_rect(self):
        original = self._original_rect()
        return original.adjusted(
            -original.width() * self.edges["left"] / 100,
            -original.height() * self.edges["top"] / 100,
            original.width() * self.edges["right"] / 100,
            original.height() * self.edges["bottom"] / 100)

    def _hit_edges(self, point):
        outer = self._outer_rect()
        tolerance = 12
        horizontal = ("left" if abs(point.x() - outer.left()) <= tolerance else
                      "right" if abs(point.x() - outer.right()) <= tolerance else None)
        vertical = ("top" if abs(point.y() - outer.top()) <= tolerance else
                    "bottom" if abs(point.y() - outer.bottom()) <= tolerance else None)
        if horizontal and outer.top() - tolerance <= point.y() <= outer.bottom() + tolerance:
            return tuple(filter(None, (horizontal, vertical)))
        if vertical and outer.left() - tolerance <= point.x() <= outer.right() + tolerance:
            return (vertical,)
        return ()

    @staticmethod
    def _cursor_for_edges(edges):
        edge_set = frozenset(edges)
        if edge_set in (frozenset(("left", "top")),
                        frozenset(("right", "bottom"))):
            return Qt.SizeFDiagCursor
        if edge_set in (frozenset(("right", "top")),
                        frozenset(("left", "bottom"))):
            return Qt.SizeBDiagCursor
        if edge_set & {"left", "right"}:
            return Qt.SizeHorCursor
        if edge_set & {"top", "bottom"}:
            return Qt.SizeVerCursor
        return Qt.ArrowCursor

    def mousePressEvent(self, event):
        if event.button() == Qt.LeftButton:
            self._drag = self._hit_edges(event.position())
            if self._drag:
                self._start = event.position()
                self._start_edges = dict(self.edges)

    def mouseMoveEvent(self, event):
        point = event.position()
        if self._drag:
            original = self._original_rect()
            dx = (point.x() - self._start.x()) * 100 / original.width()
            dy = (point.y() - self._start.y()) * 100 / original.height()
            candidate = dict(self._start_edges)
            for edge in self._drag:
                delta = {"left": -dx, "right": dx, "top": -dy, "bottom": dy}[edge]
                candidate[edge] = max(0.0, min(20.0, self._start_edges[edge] + delta))
            if self._constraint is not None:
                candidate = clamp_edges(self._start_edges, candidate, self._constraint)
            self.edges = candidate
            self.edges_changed.emit(dict(self.edges))
            self.update()
        else:
            hit = self._hit_edges(point)
            self.setCursor(self._cursor_for_edges(hit))

    def mouseReleaseEvent(self, _event):
        self._drag = self._start = self._start_edges = None

    def paintEvent(self, _event):
        painter = QPainter(self)
        painter.fillRect(self.rect(), QColor(16, 16, 16))
        outer = self._outer_rect()
        original = self._original_rect()
        painter.fillRect(outer, QColor(48, 48, 48))
        painter.drawPixmap(original.toRect(), self._photo)
        painter.setPen(QPen(QColor(57, 255, 20), 2))
        painter.setBrush(Qt.NoBrush)
        painter.drawRect(outer)
        painter.setPen(QPen(QColor(215, 215, 215), 1, Qt.DashLine))
        painter.drawRect(original)
        painter.setPen(QColor(190, 190, 190))
        painter.drawText(self.rect().adjusted(8, 6, -8, -6), Qt.AlignTop | Qt.AlignHCenter,
                         "Drag an edge or corner outward")
        painter.end()

# ===== SNAPSMACK EOF =====
