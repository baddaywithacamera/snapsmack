"""Reusable Qt components for the editor shell.

These are application-owned widgets (the spec's ``slapper_ui`` idea) so every
screen shares one look and one behaviour instead of styling controls ad hoc.
"""

from PySide6.QtCore import Qt, Signal, QRectF
from PySide6.QtGui import QPainter, QPixmap, QColor, QPolygonF, QPen
from PySide6.QtCore import QPointF
from PySide6.QtWidgets import (
    QGraphicsView, QGraphicsScene, QGraphicsPixmapItem, QGraphicsRectItem,
    QWidget, QLabel, QSlider, QHBoxLayout, QVBoxLayout, QPushButton, QSizePolicy,
)

from . import theme


class ImageView(QGraphicsView):
    """A pannable, zoomable canvas that shows the rendered photo.

    Fit-to-window by default; scroll wheel zooms toward the cursor; drag pans.
    """

    # emitted when a crop rectangle is drawn, as normalized (l, t, r, b)
    cropped = Signal(float, float, float, float)
    # emitted when the canvas is clicked in retouch mode, as normalized (x, y)
    retouch_clicked = Signal(float, float)

    def __init__(self, parent=None):
        super().__init__(parent)
        self.setObjectName("ImageView")
        self._scene = QGraphicsScene(self)
        self.setScene(self._scene)
        self._item = QGraphicsPixmapItem()
        self._item.setTransformationMode(Qt.SmoothTransformation)
        self._scene.addItem(self._item)
        self.setRenderHints(QPainter.SmoothPixmapTransform | QPainter.Antialiasing)
        self.setDragMode(QGraphicsView.ScrollHandDrag)
        self.setTransformationAnchor(QGraphicsView.AnchorUnderMouse)
        self.setResizeAnchor(QGraphicsView.AnchorViewCenter)
        self.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
        self.setVerticalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
        self._has_image = False
        self._fitting = True
        self._crop_mode = False
        self._crop_rect_item = None
        self._crop_origin = None
        self._retouch_mode = False

    def set_retouch_mode(self, enabled):
        self._retouch_mode = enabled
        self.setDragMode(QGraphicsView.NoDrag if enabled
                         else QGraphicsView.ScrollHandDrag)
        self.setCursor(Qt.CrossCursor if enabled else Qt.ArrowCursor)

    def set_crop_mode(self, enabled):
        self._crop_mode = enabled
        self.setDragMode(QGraphicsView.NoDrag if enabled
                         else QGraphicsView.ScrollHandDrag)
        self.setCursor(Qt.CrossCursor if enabled else Qt.ArrowCursor)
        if not enabled and self._crop_rect_item is not None:
            self._scene.removeItem(self._crop_rect_item)
            self._crop_rect_item = None

    def set_pixmap(self, pixmap: QPixmap, keep_view: bool = True):
        """Show a pixmap. When ``keep_view`` the current zoom/pan is preserved
        (used for live slider updates); otherwise the view fits the image."""
        first = not self._has_image
        self._item.setPixmap(pixmap)
        self._scene.setSceneRect(QRectF(pixmap.rect()))
        self._has_image = True
        if first or not keep_view or self._fitting:
            self.fit()

    def fit(self):
        if not self._has_image:
            return
        self._fitting = True
        self.resetTransform()
        self.fitInView(self._item, Qt.KeepAspectRatio)

    def actual_size(self):
        if not self._has_image:
            return
        self._fitting = False
        self.resetTransform()

    def mousePressEvent(self, event):
        if self._retouch_mode and self._has_image and event.button() == Qt.LeftButton:
            point = self.mapToScene(event.position().toPoint())
            scene = self._scene.sceneRect()
            if scene.contains(point) and scene.width() and scene.height():
                self.retouch_clicked.emit(point.x() / scene.width(),
                                          point.y() / scene.height())
            return
        if self._crop_mode and self._has_image and event.button() == Qt.LeftButton:
            self._crop_origin = self.mapToScene(event.position().toPoint())
            if self._crop_rect_item is None:
                pen = QPen(QColor(theme.ACCENT), 0)
                self._crop_rect_item = QGraphicsRectItem()
                self._crop_rect_item.setPen(pen)
                self._crop_rect_item.setBrush(QColor(57, 255, 20, 40))
                self._scene.addItem(self._crop_rect_item)
            self._crop_rect_item.setRect(QRectF(self._crop_origin, self._crop_origin))
            return
        super().mousePressEvent(event)

    def mouseMoveEvent(self, event):
        if self._crop_mode and self._crop_origin is not None:
            current = self.mapToScene(event.position().toPoint())
            self._crop_rect_item.setRect(QRectF(self._crop_origin, current).normalized())
            return
        super().mouseMoveEvent(event)

    def mouseReleaseEvent(self, event):
        if self._crop_mode and self._crop_origin is not None:
            rect = self._crop_rect_item.rect().intersected(self._scene.sceneRect())
            self._crop_origin = None
            scene = self._scene.sceneRect()
            if rect.width() > 4 and rect.height() > 4 and scene.width() and scene.height():
                self.cropped.emit(rect.left() / scene.width(),
                                  rect.top() / scene.height(),
                                  rect.right() / scene.width(),
                                  rect.bottom() / scene.height())
            return
        super().mouseReleaseEvent(event)

    def wheelEvent(self, event):
        if not self._has_image or self._crop_mode:
            return
        self._fitting = False
        factor = 1.15 if event.angleDelta().y() > 0 else 1 / 1.15
        self.scale(factor, factor)

    def resizeEvent(self, event):
        super().resizeEvent(event)
        if self._fitting:
            self.fit()

    def viewport_target(self):
        """Preview render cap sized to the viewport (times device pixel ratio,
        so a HiDPI display still gets crisp pixels)."""
        ratio = self.devicePixelRatioF() or 1.0
        width = max(320, int(self.viewport().width() * ratio))
        height = max(320, int(self.viewport().height() * ratio))
        # A little headroom so a zoom-in past fit still looks sharp.
        return (min(4096, int(width * 1.5)), min(4096, int(height * 1.5)))


class Histogram(QWidget):
    """A live Luma/RGB histogram painted from the engine's histogram data.

    A clipped black or white bin can dwarf every useful tonal bin, so the
    vertical scale ignores the two extreme bins (0 and 255) plus a little
    headroom — the same reasoning as the Tk editor.
    """

    def __init__(self, parent=None):
        super().__init__(parent)
        self.setFixedHeight(96)
        self._data = None
        self._mode = "luma"

    def set_data(self, data, mode=None):
        self._data = data
        if mode:
            self._mode = mode
        self.update()

    def set_mode(self, mode):
        self._mode = mode
        self.update()

    def _scale_max(self, bins):
        interior = bins[1:255] if len(bins) >= 256 else bins
        return max(interior) if interior and max(interior) > 0 else 1

    def paintEvent(self, event):
        painter = QPainter(self)
        painter.fillRect(self.rect(), QColor("#070707"))
        painter.setPen(QPen(QColor(theme.BORDER), 1))
        painter.drawRect(self.rect().adjusted(0, 0, -1, -1))
        if not self._data:
            return
        painter.setRenderHint(QPainter.Antialiasing)
        width = self.width() - 2
        height = self.height() - 2

        if self._mode == "rgb":
            channels = [("red", QColor(255, 70, 70, 150)),
                        ("green", QColor(70, 255, 70, 150)),
                        ("blue", QColor(90, 130, 255, 150))]
        else:
            channels = [("luminance", QColor(theme.ACCENT))]

        painter.setCompositionMode(QPainter.CompositionMode_Plus if self._mode == "rgb"
                                   else QPainter.CompositionMode_SourceOver)
        for key, colour in channels:
            bins = self._data.get(key)
            if not bins:
                continue
            top = self._scale_max(bins)
            polygon = QPolygonF()
            polygon.append(QPointF(1, height + 1))
            for i, value in enumerate(bins):
                x = 1 + (i / 255.0) * width
                y = 1 + height - min(1.0, value / top) * height
                polygon.append(QPointF(x, y))
            polygon.append(QPointF(width + 1, height + 1))
            painter.setPen(Qt.NoPen)
            painter.setBrush(colour)
            painter.drawPolygon(polygon)


class SliderRow(QWidget):
    """One labelled adjustment: name, slider, live value, double-click reset.

    Qt sliders are integer-only, so a float ``resolution`` maps the engine's
    real value onto integer slider steps.
    """

    # emitted continuously while dragging: (key, float value)
    changed = Signal(str, float)
    # emitted when the user finishes a drag — the moment to record undo history
    committed = Signal(str)

    def __init__(self, key, label, start, end, resolution, default=0.0, parent=None):
        super().__init__(parent)
        self.key = key
        self.start = float(start)
        self.end = float(end)
        self.resolution = float(resolution)
        self.default = float(default)
        self._decimals = 0 if resolution >= 1 else len(str(resolution).split(".")[-1])

        row = QHBoxLayout(self)
        row.setContentsMargins(12, 3, 12, 3)
        row.setSpacing(8)

        name = QLabel(label)
        name.setObjectName("ControlName")
        name.setFixedWidth(74)
        row.addWidget(name)

        self.slider = QSlider(Qt.Horizontal)
        self.slider.setMinimum(self._to_step(self.start))
        self.slider.setMaximum(self._to_step(self.end))
        self.slider.setValue(self._to_step(self.default))
        self.slider.valueChanged.connect(self._on_slider)
        self.slider.sliderReleased.connect(lambda: self.committed.emit(self.key))
        row.addWidget(self.slider, 1)

        self.value_label = QLabel(self._format(self.default))
        self.value_label.setObjectName("ControlValue")
        self.value_label.setFixedWidth(40)
        self.value_label.setAlignment(Qt.AlignRight | Qt.AlignVCenter)
        row.addWidget(self.value_label)

        self._suppress = False

    def _to_step(self, value):
        return int(round(value / self.resolution))

    def _from_step(self, step):
        return step * self.resolution

    def _format(self, value):
        return f"{value:.{self._decimals}f}"

    def _on_slider(self, step):
        value = self._from_step(step)
        self.value_label.setText(self._format(value))
        if not self._suppress:
            self.changed.emit(self.key, value)

    def set_value(self, value):
        """Set the slider without emitting a live change (used by undo/redo and
        preset loads to sync the UI to the document)."""
        self._suppress = True
        self.slider.setValue(self._to_step(float(value)))
        self.value_label.setText(self._format(float(value)))
        self._suppress = False

    def mouseDoubleClickEvent(self, event):
        self.set_value(self.default)
        self.changed.emit(self.key, self.default)
        self.committed.emit(self.key)


class Accordion(QWidget):
    """A collapsible titled section that holds control rows."""

    def __init__(self, title, expanded=False, parent=None):
        super().__init__(parent)
        outer = QVBoxLayout(self)
        outer.setContentsMargins(0, 0, 0, 0)
        outer.setSpacing(0)

        self.header = QPushButton(title)
        self.header.setObjectName("AccordionHeader")
        self.header.setCheckable(True)
        self.header.setChecked(expanded)
        self.header.setCursor(Qt.PointingHandCursor)
        self.header.toggled.connect(self._toggle)
        outer.addWidget(self.header)

        self.body = QWidget()
        self.body_layout = QVBoxLayout(self.body)
        self.body_layout.setContentsMargins(0, 4, 0, 8)
        self.body_layout.setSpacing(0)
        self.body.setVisible(expanded)
        outer.addWidget(self.body)

        self.setSizePolicy(QSizePolicy.Preferred, QSizePolicy.Fixed)

    def add(self, widget):
        self.body_layout.addWidget(widget)

    def _toggle(self, checked):
        self.body.setVisible(checked)

# ===== SNAPSMACK EOF =====
