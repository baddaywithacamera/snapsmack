"""Reusable Qt components for the editor shell.

These are application-owned widgets (the spec's ``slapper_ui`` idea) so every
screen shares one look and one behaviour instead of styling controls ad hoc.
"""

from PySide6.QtCore import Qt, Signal, QRectF, QTimer
from PySide6.QtGui import (QPainter, QPixmap, QImage, QColor, QPolygonF, QPen,
                           QFont, QTransform)
from PySide6.QtCore import QPointF
from PySide6.QtWidgets import (
    QGraphicsView, QGraphicsScene, QGraphicsPixmapItem, QGraphicsRectItem,
    QGraphicsEllipseItem, QGraphicsPolygonItem, QGraphicsLineItem,
    QWidget, QLabel, QSlider, QHBoxLayout, QVBoxLayout, QPushButton, QSizePolicy,
)
from PIL import Image

from . import theme

PERSPECTIVE_GRID_DIVISIONS = 20


class ImageView(QGraphicsView):
    """A pannable, zoomable canvas that shows the rendered photo.

    Fit-to-window by default; scroll wheel zooms toward the cursor; drag pans.
    """

    # emitted when a crop rectangle is drawn, as normalized (l, t, r, b)
    cropped = Signal(float, float, float, float)
    # emitted when the canvas is clicked in retouch mode, as normalized (x, y)
    retouch_clicked = Signal(float, float)
    # emitted when the canvas is clicked with the neutral-colour eyedropper
    neutral_clicked = Signal(float, float)
    # emitted when a colour-range mask eyedropper samples the canvas
    colour_range_clicked = Signal(float, float)
    # normalized canvas movement for the selected movable layer
    layer_dragged = Signal(float, float, bool)
    # corner index, normalized x/y, and whether the drag has finished
    perspective_corner_dragged = Signal(int, float, float, bool)
    # normalized x/y and stroke-finished state for full-canvas mask painting
    mask_painted = Signal(float, float, bool)
    gradient_drawn = Signal(float, float, float, float)
    # The editor uses a viewport-sized proxy in Fit mode. When layout changes,
    # ask it to render a new proxy instead of stretching the old one.
    fit_view_resized = Signal()

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
        self._logical_image_rect = None
        self._fitting = True
        self._crop_mode = False
        self._crop_rect_item = None
        self._crop_origin = None
        self._crop_drag = None
        self._crop_drag_start = None
        self._crop_start_rect = None
        self._crop_aspect = None
        self._crop_shades = []
        self._crop_grid = []
        self._crop_handles = []
        self._retouch_mode = False
        self._neutral_mode = False
        self._colour_range_mode = False
        self._gradient_mode = False
        self._gradient_start = None
        self._gradient_line = None
        self._mask_paint_mode = False
        self._mask_painting = False
        self._mask_overlay = QGraphicsPixmapItem()
        self._mask_overlay.setZValue(7)
        self._mask_overlay.setVisible(False)
        self._scene.addItem(self._mask_overlay)
        self._layer_move_mode = False
        self._layer_drag_point = None
        self._perspective_mode = False
        self._perspective_corners = [[0.0, 0.0], [1.0, 0.0],
                                     [1.0, 1.0], [0.0, 1.0]]
        self._perspective_drag = None
        self._perspective_polygon = None
        self._perspective_handles = []
        self._perspective_grid = []
        # Before/After split: original on the left of the divider, edited on the
        # right, drag anywhere to move the split.
        self._compare = False
        self._orig_pixmap = None
        self._edit_pixmap = None
        self._divider = 0.5

    def set_layer_move_mode(self, enabled):
        """Let a selected text/image layer be dragged directly on the photo."""
        self._layer_move_mode = bool(enabled)
        self._layer_drag_point = None
        if enabled:
            self.setDragMode(QGraphicsView.NoDrag)
            self.viewport().setCursor(Qt.SizeAllCursor)
        elif not (self._crop_mode or self._retouch_mode or self._compare):
            self.setDragMode(QGraphicsView.ScrollHandDrag)
            self.viewport().unsetCursor()

    def set_perspective_mode(self, enabled, corners=None):
        self._perspective_mode = bool(enabled)
        if corners and len(corners) == 4:
            self._perspective_corners = [list(point) for point in corners]
        self._perspective_drag = None
        self.setDragMode(QGraphicsView.NoDrag if enabled
                         else QGraphicsView.ScrollHandDrag)
        self.viewport().setCursor(Qt.CrossCursor if enabled else Qt.ArrowCursor)
        self._update_perspective_overlay()

    def set_perspective_corners(self, corners):
        if corners and len(corners) == 4:
            self._perspective_corners = [list(point) for point in corners]
            self._update_perspective_overlay()

    def _clear_perspective_overlay(self):
        if self._perspective_polygon is not None:
            self._scene.removeItem(self._perspective_polygon)
            self._perspective_polygon = None
        for handle in self._perspective_handles:
            self._scene.removeItem(handle)
        self._perspective_handles = []
        for line in self._perspective_grid:
            self._scene.removeItem(line)
        self._perspective_grid = []

    def _update_perspective_overlay(self):
        self._clear_perspective_overlay()
        if not (self._perspective_mode and self._has_image):
            return
        rect = self._scene.sceneRect()
        points = [QPointF(rect.left() + x * rect.width(),
                          rect.top() + y * rect.height())
                  for x, y in self._perspective_corners]
        pen = QPen(QColor(theme.ACCENT), 0)
        self._perspective_polygon = QGraphicsPolygonItem(QPolygonF(points))
        self._perspective_polygon.setPen(pen)
        self._perspective_polygon.setBrush(QColor(0, 0, 0, 0))
        self._scene.addItem(self._perspective_polygon)
        # A fine 20×20 architectural grid gives enough nearby references for
        # rooflines, walls, windows and posts. Quarters remain a little brighter
        # so the dense guide does not become an undifferentiated mesh.
        # These bilinear guides are only an editing overlay; the rendered image
        # itself uses one true projective transform, which preserves lines.
        for division in range(1, PERSPECTIVE_GRID_DIVISIONS):
            fraction = division / PERSPECTIVE_GRID_DIVISIONS
            # Every fifth line is stronger, producing clear quarters without
            # losing the dense one-twentieth alignment guides.
            major = division % 5 == 0
            grid_pen = QPen(QColor(255, 255, 255, 165 if major else 82), 0)
            top = points[0] + (points[1] - points[0]) * fraction
            bottom = points[3] + (points[2] - points[3]) * fraction
            left = points[0] + (points[3] - points[0]) * fraction
            right = points[1] + (points[2] - points[1]) * fraction
            for start, end in ((top, bottom), (left, right)):
                line = QGraphicsLineItem(start.x(), start.y(), end.x(), end.y())
                line.setPen(grid_pen)
                line.setZValue(9)
                self._scene.addItem(line)
                self._perspective_grid.append(line)
        radius = max(5.0, min(rect.width(), rect.height()) / 80.0)
        for point in points:
            handle = QGraphicsEllipseItem(point.x() - radius, point.y() - radius,
                                          radius * 2, radius * 2)
            handle.setPen(QPen(QColor("#ffffff"), 0))
            handle.setBrush(QColor(theme.ACCENT))
            handle.setZValue(10)
            self._scene.addItem(handle)
            self._perspective_handles.append(handle)

    def set_retouch_mode(self, enabled):
        self._retouch_mode = enabled
        self.setDragMode(QGraphicsView.NoDrag if enabled
                         else QGraphicsView.ScrollHandDrag)
        self.setCursor(Qt.CrossCursor if enabled else Qt.ArrowCursor)

    def set_neutral_mode(self, enabled):
        """Turn the white-balance eyedropper on without changing the photo."""
        self._neutral_mode = bool(enabled)
        self.setDragMode(QGraphicsView.NoDrag if enabled
                         else QGraphicsView.ScrollHandDrag)
        self.viewport().setCursor(Qt.CrossCursor if enabled else Qt.ArrowCursor)

    def set_colour_range_mode(self, enabled):
        """Turn the layer-mask colour eyedropper on."""
        self._colour_range_mode = bool(enabled)
        self.setDragMode(QGraphicsView.NoDrag if enabled
                         else QGraphicsView.ScrollHandDrag)
        self.viewport().setCursor(Qt.CrossCursor if enabled else Qt.ArrowCursor)

    def set_gradient_mode(self, enabled):
        """Let a graduated mask be drawn directly across the photograph."""
        self._gradient_mode = bool(enabled)
        self._gradient_start = None
        if self._gradient_line is not None:
            self._scene.removeItem(self._gradient_line)
            self._gradient_line = None
        self.setDragMode(QGraphicsView.NoDrag if enabled
                         else QGraphicsView.ScrollHandDrag)
        self.viewport().setCursor(Qt.CrossCursor if enabled else Qt.ArrowCursor)

    def set_mask_paint_mode(self, enabled):
        self._mask_paint_mode = bool(enabled)
        self._mask_painting = False
        self.setDragMode(QGraphicsView.NoDrag if enabled
                         else QGraphicsView.ScrollHandDrag)
        self.viewport().setCursor(Qt.CrossCursor if enabled else Qt.ArrowCursor)
        if not enabled:
            self._mask_overlay.setVisible(False)

    def set_mask_overlay(self, mask):
        """Show black mask areas as a red overlay over the main photograph."""
        if mask is None or self._item.pixmap().isNull():
            self._mask_overlay.setVisible(False)
            return
        width, height = self._item.pixmap().width(), self._item.pixmap().height()
        display = mask.convert("L").resize((width, height), Image.Resampling.BILINEAR)
        alpha = display.point(lambda value: round((255 - value) * .48))
        overlay = Image.new("RGBA", display.size, (225, 35, 35, 0))
        overlay.putalpha(alpha)
        data = overlay.tobytes("raw", "RGBA")
        qimage = QImage(data, width, height, width * 4,
                        QImage.Format_RGBA8888).copy()
        self._mask_overlay.setPixmap(QPixmap.fromImage(qimage))
        self._mask_overlay.setVisible(self._mask_paint_mode)

    def set_crop_mode(self, enabled, normalized_rect=None):
        self._crop_mode = enabled
        self.setDragMode(QGraphicsView.NoDrag if enabled
                         else QGraphicsView.ScrollHandDrag)
        self.viewport().setCursor(Qt.CrossCursor if enabled else Qt.ArrowCursor)
        self._crop_origin = None
        self._crop_drag = None
        if not enabled:
            self._clear_crop_overlay()
            return
        scene = self._scene.sceneRect()
        if not (scene.width() and scene.height()):
            return
        if normalized_rect and len(normalized_rect) == 4:
            left, top, right, bottom = normalized_rect
            rect = QRectF(scene.left() + left * scene.width(),
                          scene.top() + top * scene.height(),
                          (right - left) * scene.width(),
                          (bottom - top) * scene.height())
        else:
            # A new crop begins at the actual photograph boundary. Free crop
            # must not retain the old arbitrary 8% inset; locked ratios will
            # subsequently constrain the largest fitting rectangle.
            rect = QRectF(scene)
        self._set_crop_rect(rect)

    def set_crop_aspect(self, ratio):
        self._crop_aspect = float(ratio) if ratio else None
        if not (self._crop_mode and self._crop_rect_item and self._crop_aspect):
            return
        scene = self._scene.sceneRect()
        centre = scene.center()
        # Selecting an aspect is an explicit framing request: start with the
        # largest crop of that shape, constrained by whichever image dimension
        # it reaches first. The user can then trim or reposition from there.
        width = scene.width()
        height = width / self._crop_aspect
        if height > scene.height():
            height = scene.height()
            width = height * self._crop_aspect
        self._set_crop_rect(QRectF(centre.x() - width / 2,
                                   centre.y() - height / 2, width, height))

    def crop_rect_normalized(self):
        if not self._crop_rect_item:
            return None
        rect = self._crop_rect_item.rect().intersected(self._scene.sceneRect())
        scene = self._scene.sceneRect()
        if rect.width() < 4 or rect.height() < 4 or not scene.width() or not scene.height():
            return None
        return ((rect.left() - scene.left()) / scene.width(),
                (rect.top() - scene.top()) / scene.height(),
                (rect.right() - scene.left()) / scene.width(),
                (rect.bottom() - scene.top()) / scene.height())

    def nudge_crop(self, dx, dy):
        """Move the crop frame by canvas pixels without leaving the image."""
        if not (self._crop_mode and self._crop_rect_item):
            return False
        scene = self._scene.sceneRect()
        rect = QRectF(self._crop_rect_item.rect())
        left = min(max(rect.left() + dx, scene.left()), scene.right() - rect.width())
        top = min(max(rect.top() + dy, scene.top()), scene.bottom() - rect.height())
        moved = QRectF(left, top, rect.width(), rect.height())
        if moved == rect:
            return False
        self._set_crop_rect(moved)
        return True

    def keyPressEvent(self, event):
        if self._crop_mode and self._crop_rect_item:
            directions = {
                Qt.Key_Left: (-1, 0), Qt.Key_Right: (1, 0),
                Qt.Key_Up: (0, -1), Qt.Key_Down: (0, 1),
            }
            direction = directions.get(event.key())
            if direction:
                step = 10 if event.modifiers() & Qt.ShiftModifier else 1
                self.nudge_crop(direction[0] * step, direction[1] * step)
                event.accept()
                return
        super().keyPressEvent(event)

    def _clear_crop_overlay(self):
        for item in ([self._crop_rect_item] if self._crop_rect_item else []) + \
                self._crop_shades + self._crop_grid + self._crop_handles:
            self._scene.removeItem(item)
        self._crop_rect_item = None
        self._crop_shades = []
        self._crop_grid = []
        self._crop_handles = []

    def _set_crop_rect(self, rect):
        scene = self._scene.sceneRect()
        rect = rect.normalized().intersected(scene)
        if self._crop_rect_item is None:
            self._crop_rect_item = QGraphicsRectItem()
            self._crop_rect_item.setPen(QPen(QColor("#ffffff"), 0))
            self._crop_rect_item.setBrush(QColor(0, 0, 0, 0))
            self._crop_rect_item.setZValue(20)
            self._scene.addItem(self._crop_rect_item)
        self._crop_rect_item.setRect(rect)
        self._update_crop_overlay()

    def _update_crop_overlay(self):
        for item in self._crop_shades + self._crop_grid + self._crop_handles:
            self._scene.removeItem(item)
        self._crop_shades, self._crop_grid, self._crop_handles = [], [], []
        if not self._crop_rect_item:
            return
        scene, rect = self._scene.sceneRect(), self._crop_rect_item.rect()
        shade_rects = (
            QRectF(scene.left(), scene.top(), scene.width(), rect.top() - scene.top()),
            QRectF(scene.left(), rect.bottom(), scene.width(), scene.bottom() - rect.bottom()),
            QRectF(scene.left(), rect.top(), rect.left() - scene.left(), rect.height()),
            QRectF(rect.right(), rect.top(), scene.right() - rect.right(), rect.height()))
        for bounds in shade_rects:
            item = QGraphicsRectItem(bounds)
            item.setPen(QPen(Qt.NoPen)); item.setBrush(QColor(0, 0, 0, 145)); item.setZValue(18)
            self._scene.addItem(item); self._crop_shades.append(item)
        grid_pen = QPen(QColor(255, 255, 255, 150), 0)
        for fraction in (1 / 3, 2 / 3):
            for x1, y1, x2, y2 in (
                    (rect.left() + rect.width() * fraction, rect.top(),
                     rect.left() + rect.width() * fraction, rect.bottom()),
                    (rect.left(), rect.top() + rect.height() * fraction,
                     rect.right(), rect.top() + rect.height() * fraction)):
                line = QGraphicsLineItem(x1, y1, x2, y2)
                line.setPen(grid_pen); line.setZValue(21)
                self._scene.addItem(line); self._crop_grid.append(line)
        radius = max(4.0, min(scene.width(), scene.height()) / 120.0)
        for point in self._crop_handle_points(rect).values():
            handle = QGraphicsRectItem(point.x() - radius, point.y() - radius,
                                       radius * 2, radius * 2)
            handle.setPen(QPen(QColor("#ffffff"), 0)); handle.setBrush(QColor(theme.ACCENT))
            handle.setZValue(22); self._scene.addItem(handle); self._crop_handles.append(handle)

    @staticmethod
    def _crop_handle_points(rect):
        return {
            "nw": rect.topLeft(), "n": QPointF(rect.center().x(), rect.top()),
            "ne": rect.topRight(), "e": QPointF(rect.right(), rect.center().y()),
            "se": rect.bottomRight(), "s": QPointF(rect.center().x(), rect.bottom()),
            "sw": rect.bottomLeft(), "w": QPointF(rect.left(), rect.center().y())}

    def _crop_hit(self, point):
        if not self._crop_rect_item:
            return "new"
        tolerance = 10.0 / max(.01, abs(self.transform().m11()))
        for name, handle in self._crop_handle_points(self._crop_rect_item.rect()).items():
            if abs(point.x() - handle.x()) <= tolerance and abs(point.y() - handle.y()) <= tolerance:
                return name
        return "move" if self._crop_rect_item.rect().contains(point) else "new"

    def _set_crop_cursor(self, hit):
        cursors = {"nw": Qt.SizeFDiagCursor, "se": Qt.SizeFDiagCursor,
                   "ne": Qt.SizeBDiagCursor, "sw": Qt.SizeBDiagCursor,
                   "n": Qt.SizeVerCursor, "s": Qt.SizeVerCursor,
                   "e": Qt.SizeHorCursor, "w": Qt.SizeHorCursor,
                   "move": Qt.SizeAllCursor, "new": Qt.CrossCursor}
        self.viewport().setCursor(cursors.get(hit, Qt.CrossCursor))

    def set_pixmap(self, pixmap: QPixmap, keep_view: bool = True,
                   stable_geometry: bool = False):
        """Show a pixmap. When ``keep_view`` the current zoom/pan is preserved
        (used for live slider updates); otherwise the view fits the image."""
        first = not self._has_image
        self._item.setPixmap(pixmap)
        self._item.setTransform(QTransform())
        if stable_geometry and self._logical_image_rect is not None and \
                pixmap.width() and pixmap.height():
            logical = self._logical_image_rect
            self._item.setTransform(QTransform.fromScale(
                logical.width() / pixmap.width(), logical.height() / pixmap.height()))
            self._scene.setSceneRect(logical)
        else:
            self._logical_image_rect = QRectF(pixmap.rect())
            self._scene.setSceneRect(self._logical_image_rect)
        self._has_image = True
        self._update_perspective_overlay()
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

    # --- Before/After split -------------------------------------------------
    def set_compare(self, original, edited, keep_view=True, stable_geometry=False):
        """Enter Before/After: original left of the divider, edited on the
        right. Drag anywhere across the canvas to move the split."""
        self._compare = True
        self._orig_pixmap = original
        self._edit_pixmap = edited
        self.viewport().setCursor(Qt.SplitHCursor)
        self._compose_compare(keep_view=keep_view, stable_geometry=stable_geometry)

    def reset_divider(self):
        self._divider = 0.5

    def clear_compare(self):
        self._compare = False
        self._orig_pixmap = None
        self._edit_pixmap = None
        self.viewport().unsetCursor()

    def _compose_compare(self, keep_view=True, stable_geometry=False):
        if not (self._orig_pixmap and self._edit_pixmap):
            return
        edited = self._edit_pixmap
        width, height = edited.width(), edited.height()
        if width < 1 or height < 1:
            return
        original = self._orig_pixmap
        if original.size() != edited.size():
            original = original.scaled(width, height, Qt.IgnoreAspectRatio,
                                       Qt.SmoothTransformation)
        canvas = QPixmap(width, height)
        canvas.fill(Qt.black)
        painter = QPainter(canvas)
        split = max(0, min(width, int(width * self._divider)))
        painter.drawPixmap(0, 0, original, 0, 0, split, height)
        painter.drawPixmap(split, 0, edited, split, 0, width - split, height)
        pen = QPen(QColor(theme.ACCENT))
        pen.setWidth(max(1, round(width / 500)))
        painter.setPen(pen)
        painter.drawLine(split, 0, split, height)
        self._draw_compare_labels(painter, width, height, split)
        painter.end()
        self.set_pixmap(canvas, keep_view=keep_view,
                        stable_geometry=stable_geometry)

    def _draw_compare_labels(self, painter, width, height, split):
        font = QFont()
        font.setPixelSize(max(11, round(height / 30)))
        font.setBold(True)
        painter.setFont(font)
        metrics = painter.fontMetrics()
        pad = max(6, round(width / 90))
        y = metrics.ascent() + pad
        if split > metrics.horizontalAdvance("BEFORE") + pad * 2:
            self._label(painter, "BEFORE", pad, y)
        after_w = metrics.horizontalAdvance("AFTER")
        if (width - split) > after_w + pad * 2:
            self._label(painter, "AFTER", width - after_w - pad, y)

    def _label(self, painter, text, x, y):
        painter.setPen(QColor(0, 0, 0, 210))   # shadow so it reads on any photo
        painter.drawText(x + 1, y + 1, text)
        painter.setPen(QColor(theme.ACCENT))
        painter.drawText(x, y, text)

    def _set_divider_from(self, event):
        if not self._edit_pixmap:
            return
        point = self.mapToScene(event.position().toPoint())
        width = self._edit_pixmap.width() or 1
        self._divider = max(0.0, min(1.0, point.x() / width))
        self._compose_compare(keep_view=True)

    def mousePressEvent(self, event):
        if self._gradient_mode and self._has_image and event.button() == Qt.LeftButton:
            point = self.mapToScene(event.position().toPoint())
            if self._scene.sceneRect().contains(point):
                self._gradient_start = point
                self._gradient_line = QGraphicsLineItem(point.x(), point.y(),
                                                        point.x(), point.y())
                self._gradient_line.setPen(QPen(QColor(theme.ACCENT), 2))
                self._gradient_line.setZValue(30)
                self._scene.addItem(self._gradient_line)
            return
        if self._perspective_mode and self._has_image and event.button() == Qt.LeftButton:
            point = self.mapToScene(event.position().toPoint())
            rect = self._scene.sceneRect()
            positions = [QPointF(rect.left() + x * rect.width(),
                                 rect.top() + y * rect.height())
                         for x, y in self._perspective_corners]
            if positions:
                index = min(range(4), key=lambda i:
                            (positions[i].x() - point.x()) ** 2 +
                            (positions[i].y() - point.y()) ** 2)
                tolerance = max(rect.width(), rect.height()) * 0.08
                if ((positions[index].x() - point.x()) ** 2 +
                        (positions[index].y() - point.y()) ** 2) <= tolerance ** 2:
                    self._perspective_drag = index
            return
        if self._compare and self._has_image and event.button() == Qt.LeftButton:
            self._set_divider_from(event)
            return
        if self._neutral_mode and self._has_image and event.button() == Qt.LeftButton:
            point = self.mapToScene(event.position().toPoint())
            scene = self._scene.sceneRect()
            if scene.contains(point) and scene.width() and scene.height():
                self.neutral_clicked.emit(
                    (point.x() - scene.left()) / scene.width(),
                    (point.y() - scene.top()) / scene.height())
            return
        if self._colour_range_mode and self._has_image and event.button() == Qt.LeftButton:
            point = self.mapToScene(event.position().toPoint())
            scene = self._scene.sceneRect()
            if scene.contains(point) and scene.width() and scene.height():
                self.colour_range_clicked.emit(
                    (point.x() - scene.left()) / scene.width(),
                    (point.y() - scene.top()) / scene.height())
            return
        if self._mask_paint_mode and self._has_image and event.button() == Qt.LeftButton:
            point = self.mapToScene(event.position().toPoint())
            scene = self._scene.sceneRect()
            if scene.contains(point) and scene.width() and scene.height():
                self._mask_painting = True
                self.mask_painted.emit(
                    (point.x() - scene.left()) / scene.width(),
                    (point.y() - scene.top()) / scene.height(), False)
            return
        if self._retouch_mode and self._has_image and event.button() == Qt.LeftButton:
            point = self.mapToScene(event.position().toPoint())
            scene = self._scene.sceneRect()
            if scene.contains(point) and scene.width() and scene.height():
                self.retouch_clicked.emit(point.x() / scene.width(),
                                          point.y() / scene.height())
            return
        if self._crop_mode and self._has_image and event.button() == Qt.LeftButton:
            point = self.mapToScene(event.position().toPoint())
            self._crop_drag = self._crop_hit(point)
            self._crop_drag_start = point
            self._crop_start_rect = (QRectF(self._crop_rect_item.rect())
                                     if self._crop_rect_item else QRectF(point, point))
            if self._crop_drag == "new":
                self._set_crop_rect(QRectF(point, point))
            return
        if self._layer_move_mode and self._has_image and event.button() == Qt.LeftButton:
            self._layer_drag_point = self.mapToScene(event.position().toPoint())
            return
        super().mousePressEvent(event)

    def mouseMoveEvent(self, event):
        if self._gradient_mode and self._gradient_start is not None and \
                (event.buttons() & Qt.LeftButton):
            point = self.mapToScene(event.position().toPoint())
            self._gradient_line.setLine(self._gradient_start.x(), self._gradient_start.y(),
                                        point.x(), point.y())
            return
        if self._mask_paint_mode and self._mask_painting and \
                (event.buttons() & Qt.LeftButton):
            point = self.mapToScene(event.position().toPoint())
            scene = self._scene.sceneRect()
            if scene.width() and scene.height():
                self.mask_painted.emit(
                    max(0.0, min(1.0, (point.x() - scene.left()) / scene.width())),
                    max(0.0, min(1.0, (point.y() - scene.top()) / scene.height())), False)
            return
        if self._perspective_mode and self._perspective_drag is not None and \
                (event.buttons() & Qt.LeftButton):
            point = self.mapToScene(event.position().toPoint())
            rect = self._scene.sceneRect()
            if rect.width() and rect.height():
                x = max(-0.5, min(1.5, (point.x() - rect.left()) / rect.width()))
                y = max(-0.5, min(1.5, (point.y() - rect.top()) / rect.height()))
                self._perspective_corners[self._perspective_drag] = [x, y]
                self._update_perspective_overlay()
                self.perspective_corner_dragged.emit(
                    self._perspective_drag, x, y, False)
            return
        if self._compare and (event.buttons() & Qt.LeftButton):
            self._set_divider_from(event)
            return
        if self._crop_mode:
            current = self.mapToScene(event.position().toPoint())
            if self._crop_drag is None:
                self._set_crop_cursor(self._crop_hit(current))
                return
            if not (event.buttons() & Qt.LeftButton):
                return
            scene = self._scene.sceneRect()
            start = self._crop_start_rect
            if self._crop_drag == "new":
                rect = QRectF(self._crop_drag_start, current).normalized()
            elif self._crop_drag == "move":
                delta = current - self._crop_drag_start
                rect = start.translated(delta)
                if rect.left() < scene.left(): rect.moveLeft(scene.left())
                if rect.right() > scene.right(): rect.moveRight(scene.right())
                if rect.top() < scene.top(): rect.moveTop(scene.top())
                if rect.bottom() > scene.bottom(): rect.moveBottom(scene.bottom())
            else:
                rect = QRectF(start)
                hit = self._crop_drag
                if "w" in hit: rect.setLeft(current.x())
                if "e" in hit: rect.setRight(current.x())
                if "n" in hit: rect.setTop(current.y())
                if "s" in hit: rect.setBottom(current.y())
                rect = rect.normalized()
            if self._crop_aspect and self._crop_drag != "move" and rect.width() > 0:
                height = rect.width() / self._crop_aspect
                if self._crop_drag and "n" in self._crop_drag:
                    rect.setTop(rect.bottom() - height)
                else:
                    rect.setBottom(rect.top() + height)
            self._set_crop_rect(rect)
            return
        if self._layer_move_mode and self._layer_drag_point is not None and \
                (event.buttons() & Qt.LeftButton):
            current = self.mapToScene(event.position().toPoint())
            scene = self._scene.sceneRect()
            if scene.width() and scene.height():
                self.layer_dragged.emit(
                    (current.x() - self._layer_drag_point.x()) / scene.width(),
                    (current.y() - self._layer_drag_point.y()) / scene.height(), False)
            self._layer_drag_point = current
            return
        super().mouseMoveEvent(event)

    def mouseReleaseEvent(self, event):
        if self._gradient_mode and self._gradient_start is not None:
            end = self.mapToScene(event.position().toPoint())
            scene = self._scene.sceneRect()
            start = self._gradient_start
            self._gradient_start = None
            if self._gradient_line is not None:
                self._scene.removeItem(self._gradient_line)
                self._gradient_line = None
            if scene.width() and scene.height():
                self.gradient_drawn.emit(
                    (start.x() - scene.left()) / scene.width(),
                    (start.y() - scene.top()) / scene.height(),
                    (end.x() - scene.left()) / scene.width(),
                    (end.y() - scene.top()) / scene.height())
            return
        if self._mask_paint_mode and self._mask_painting:
            self._mask_painting = False
            point = self.mapToScene(event.position().toPoint())
            scene = self._scene.sceneRect()
            if scene.width() and scene.height():
                self.mask_painted.emit(
                    max(0.0, min(1.0, (point.x() - scene.left()) / scene.width())),
                    max(0.0, min(1.0, (point.y() - scene.top()) / scene.height())), True)
            return
        if self._perspective_mode and self._perspective_drag is not None:
            index = self._perspective_drag
            self._perspective_drag = None
            x, y = self._perspective_corners[index]
            self.perspective_corner_dragged.emit(index, x, y, True)
            return
        if self._crop_mode and self._crop_drag is not None:
            self._crop_drag = None
            self._crop_drag_start = None
            self._crop_start_rect = None
            point = self.mapToScene(event.position().toPoint())
            self._set_crop_cursor(self._crop_hit(point))
            return
        if self._layer_move_mode and self._layer_drag_point is not None:
            self._layer_drag_point = None
            self.layer_dragged.emit(0.0, 0.0, True)
            return
        super().mouseReleaseEvent(event)

    def wheelEvent(self, event):
        # Windows trackpads often encode a pinch as Ctrl+wheel.  Ignore every
        # wheel gesture over the canvas so an accidental touch cannot resize
        # or shift the photograph.  Toolbar/buttons and Ctrl+/- still zoom.
        event.accept()

    def zoom_by(self, factor):
        """Zoom one predictable step, bounded against accidental runaway."""
        if not self._has_image or self._crop_mode:
            return
        current = abs(self.transform().m11()) or 1.0
        target = max(.02, min(32.0, current * float(factor)))
        applied = target / current
        if abs(applied - 1.0) < .0001:
            return
        self._fitting = False
        self.scale(applied, applied)

    def resizeEvent(self, event):
        super().resizeEvent(event)
        if self._fitting:
            self.fit()
            if self._has_image:
                self.fit_view_resized.emit()

    def viewport_target(self, interactive=False):
        """Preview render cap sized to the viewport (times device pixel ratio,
        so a HiDPI display still gets crisp pixels).

        Slider drags use a lighter proxy and resolve to the normal crisp target
        on release.  This keeps the UI continuous instead of asking the main
        thread to composite several megapixels every few dozen milliseconds.
        """
        ratio = self.devicePixelRatioF() or 1.0
        width = max(320, int(self.viewport().width() * ratio))
        height = max(320, int(self.viewport().height() * ratio))
        if interactive:
            scale = min(1.0, 1100.0 / max(width, height))
            return (max(320, int(width * scale)),
                    max(320, int(height * scale)))
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


class _ScrollSafeSlider(QSlider):
    """A slider that never steals mouse-wheel/trackpad scrolling."""

    def wheelEvent(self, event):  # noqa: N802 — Qt override
        event.ignore()  # propagate to the containing scroll area


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

        self.slider = _ScrollSafeSlider(Qt.Horizontal)
        self.slider.setMinimum(self._to_step(self.start))
        self.slider.setMaximum(self._to_step(self.end))
        self.slider.setValue(self._to_step(self.default))
        self.slider.valueChanged.connect(self._on_slider)
        self.slider.sliderPressed.connect(self._cancel_deferred_commit)
        self.slider.sliderReleased.connect(self._commit_drag)
        row.addWidget(self.slider, 1)

        self.value_label = QLabel(self._format(self.default))
        self.value_label.setObjectName("ControlValue")
        self.value_label.setFixedWidth(40)
        self.value_label.setAlignment(Qt.AlignRight | Qt.AlignVCenter)
        row.addWidget(self.value_label)

        self._suppress = False
        self._commit_timer = QTimer(self)
        self._commit_timer.setSingleShot(True)
        self._commit_timer.setInterval(300)
        self._commit_timer.timeout.connect(lambda: self.committed.emit(self.key))

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
            # Keyboard and groove-click changes have no sliderReleased signal.
            # Group key-repeat into one action, then create its own undo point.
            if not self.slider.isSliderDown():
                self._commit_timer.start()

    def _cancel_deferred_commit(self):
        self._commit_timer.stop()

    def _commit_drag(self):
        self._commit_timer.stop()
        self.committed.emit(self.key)

    def set_value(self, value):
        """Set the slider without emitting a live change (used by undo/redo and
        preset loads to sync the UI to the document)."""
        self._suppress = True
        self.slider.setValue(self._to_step(float(value)))
        self.value_label.setText(self._format(float(value)))
        self._suppress = False

    def mouseDoubleClickEvent(self, event):
        self._commit_timer.stop()
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
