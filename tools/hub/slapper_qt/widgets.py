"""Reusable Qt components for the editor shell.

These are application-owned widgets (the spec's ``slapper_ui`` idea) so every
screen shares one look and one behaviour instead of styling controls ad hoc.
"""

from PySide6.QtCore import Qt, Signal, QRectF
from PySide6.QtGui import QPainter, QPixmap
from PySide6.QtWidgets import (
    QGraphicsView, QGraphicsScene, QGraphicsPixmapItem,
    QWidget, QLabel, QSlider, QHBoxLayout, QVBoxLayout, QPushButton, QSizePolicy,
)


class ImageView(QGraphicsView):
    """A pannable, zoomable canvas that shows the rendered photo.

    Fit-to-window by default; scroll wheel zooms toward the cursor; drag pans.
    """

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

    def wheelEvent(self, event):
        if not self._has_image:
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
