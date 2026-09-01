"""SNAP SLAPPER Qt filmstrip — a toggleable horizontal thumbnail strip.

Shows the other photographs in the folder of the currently-open image, so the
photographer can step through a shoot without leaving the editor. Thumbnails
load on a background thread pool (the same pattern the library grid uses), so
opening a folder never freezes the canvas. Click a frame to open it.

The strip is shown or hidden by the editor's Filmstrip toolbar toggle; the
choice is remembered in prefs. It only scans a folder when it is actually
visible, so a hidden strip costs nothing.
"""

import os

from PySide6.QtCore import Qt, QObject, QPoint, QRunnable, QThreadPool, Signal, QSize, QTimer
from PySide6.QtGui import QImage, QPixmap, QIcon
from PySide6.QtWidgets import QListWidget, QListWidgetItem

from PIL import Image, ImageOps

try:
    import snap_log
    _log = snap_log.get("snap_slapper")
except Exception:  # noqa: BLE001
    import logging
    _log = logging.getLogger("snapsmack.snap_slapper")

IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".tif", ".tiff", ".webp", ".bmp", ".gif"}
THUMB_SOURCE = 160   # thumbnails are generated at this size, displayed smaller
ICON = 84            # on-screen frame size
STRIP_HEIGHT = 120   # fixed strip height (frame + a little chrome)


class _ThumbSignals(QObject):
    ready = Signal(str, QImage)


class _ThumbTask(QRunnable):
    """Load and scale one filmstrip thumbnail off the GUI thread.

    ``draft`` lets libjpeg decode a JPEG straight to a fraction of its size, so
    a strip of full-resolution shots populates fast without decoding every
    photograph at full resolution first.
    """

    def __init__(self, path, signals):
        super().__init__()
        self.path = path
        self.signals = signals

    def run(self):
        try:
            with Image.open(self.path) as source:
                try:
                    source.draft("RGB", (THUMB_SOURCE, THUMB_SOURCE))
                except Exception:  # noqa: BLE001 — draft is a speed hint only
                    pass
                image = ImageOps.exif_transpose(source).convert("RGBA")
            image.thumbnail((THUMB_SOURCE, THUMB_SOURCE), Image.Resampling.LANCZOS)
            data = image.tobytes("raw", "RGBA")
            qimage = QImage(data, image.width, image.height,
                            image.width * 4, QImage.Format_RGBA8888).copy()
            self.signals.ready.emit(self.path, qimage)
        except Exception:  # noqa: BLE001 — a bad file just keeps its placeholder
            _log.debug("filmstrip thumbnail failed for %s", self.path, exc_info=True)


class Filmstrip(QListWidget):
    """A horizontal, single-row strip of the current folder's photographs."""

    open_requested = Signal(str)

    def __init__(self, parent=None):
        super().__init__(parent)
        self.setObjectName("Filmstrip")
        self.setViewMode(QListWidget.IconMode)
        self.setFlow(QListWidget.LeftToRight)
        self.setWrapping(False)
        self.setMovement(QListWidget.Static)
        self.setResizeMode(QListWidget.Adjust)
        self.setUniformItemSizes(True)
        self.setIconSize(QSize(ICON, ICON))
        self.setSpacing(6)
        self.setFixedHeight(STRIP_HEIGHT)
        self.setHorizontalScrollBarPolicy(Qt.ScrollBarAsNeeded)
        self.setVerticalScrollBarPolicy(Qt.ScrollBarAlwaysOff)

        self._items = {}          # abs path -> QListWidgetItem
        self._folder = None
        self._current = None
        # Keep filmstrip work out of the application's global worker pool. A
        # folder containing thousands of photos must never starve the library,
        # file operations, or editor previews.
        self._pool = QThreadPool(self)
        self._pool.setMaxThreadCount(2)
        self._queued = set()
        self._signals = _ThumbSignals()
        self._signals.ready.connect(self._on_thumb)
        self.itemClicked.connect(self._activate)
        self.itemActivated.connect(self._activate)
        # The initial queue is centred on the open photograph.  Keep extending
        # it as the user browses along the strip; otherwise distant frames stay
        # as placeholders forever.
        self._scroll_timer = QTimer(self)
        self._scroll_timer.setSingleShot(True)
        self._scroll_timer.setInterval(60)
        self._scroll_timer.timeout.connect(self._queue_visible)
        self.horizontalScrollBar().valueChanged.connect(
            lambda _value: self._scroll_timer.start())

    def show_for(self, path):
        """Populate from the folder that holds ``path`` and select that frame.

        Re-scans only when the folder changes, so simply stepping between shots
        in the same folder just moves the selection.
        """
        if not path:
            return
        current = os.path.abspath(path)
        folder = os.path.dirname(current)
        if folder != self._folder:
            self._folder = folder
            self._load_folder(folder)
        self._current = current
        self._select_current()
        if self.isVisible():
            self._queue_near(current)

    def showEvent(self, event):  # noqa: N802 — Qt override
        super().showEvent(event)
        if self._current:
            self._queue_near(self._current)
        self._scroll_timer.start()

    def resizeEvent(self, event):  # noqa: N802 — Qt override
        super().resizeEvent(event)
        self._scroll_timer.start()

    def _load_folder(self, folder):
        self.clear()
        self._items.clear()
        self._queued.clear()
        placeholder = self._placeholder()
        for full in self._scan(folder):
            item = QListWidgetItem(placeholder, "")
            item.setData(Qt.UserRole, full)
            item.setToolTip(os.path.basename(full))
            self.addItem(item)
            self._items[full] = item

    def _queue_near(self, current, radius=16):
        """Decode only nearby frames; placeholders make the full shoot navigable."""
        paths = list(self._items)
        if not paths:
            return
        try:
            centre = paths.index(current)
        except ValueError:
            centre = 0
        start, end = max(0, centre - radius), min(len(paths), centre + radius + 1)
        for path in paths[start:end]:
            if path not in self._queued:
                self._queued.add(path)
                self._pool.start(_ThumbTask(path, self._signals))

    def _queue_visible(self, margin=10):
        """Queue the visible run plus a small look-ahead on either side."""
        if not self._items:
            return
        viewport = self.viewport()
        first = self.itemAt(QPoint(1, max(1, viewport.height() // 2)))
        last = self.itemAt(QPoint(max(1, viewport.width() - 2),
                                  max(1, viewport.height() // 2)))
        if first is None:
            bar = self.horizontalScrollBar()
            progress = (bar.value() / bar.maximum()) if bar.maximum() else 0.0
            visible_count = max(1, viewport.width() // (ICON + self.spacing() * 2))
            first_row = round(progress * max(0, self.count() - visible_count))
        else:
            first_row = self.row(first)
        last_row = self.row(last) if last is not None else min(
            self.count() - 1, first_row + max(1, viewport.width() // (ICON + self.spacing() * 2)))
        if last_row < first_row:
            first_row, last_row = last_row, first_row
        start = max(0, first_row - margin)
        end = min(self.count(), last_row + margin + 1)
        for row in range(start, end):
            item = self.item(row)
            path = item.data(Qt.UserRole)
            if path and path not in self._queued:
                self._queued.add(path)
                self._pool.start(_ThumbTask(path, self._signals))

    @staticmethod
    def _scan(folder):
        found = []
        try:
            for name in os.listdir(folder):
                full = os.path.join(folder, name)
                if os.path.isfile(full) and \
                        os.path.splitext(name)[1].lower() in IMAGE_EXTENSIONS:
                    found.append(full)
        except OSError:
            return []
        return sorted(found, key=lambda p: os.path.basename(p).lower())

    def _placeholder(self):
        pixmap = QPixmap(ICON, ICON)
        pixmap.fill(Qt.transparent)
        return QIcon(pixmap)

    def _on_thumb(self, path, qimage):
        item = self._items.get(path)
        if item is not None:
            item.setIcon(QIcon(QPixmap.fromImage(qimage)))

    def _select_current(self):
        item = self._items.get(self._current)
        self.blockSignals(True)
        if item is not None:
            self.setCurrentItem(item)
            self.scrollToItem(item, QListWidget.PositionAtCenter)
        else:
            self.clearSelection()
        self.blockSignals(False)

    def _activate(self, item):
        path = item.data(Qt.UserRole)
        if path and os.path.abspath(path) != self._current:
            self.open_requested.emit(path)

# ===== SNAPSMACK EOF =====
