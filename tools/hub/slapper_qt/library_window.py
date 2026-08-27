"""SNAP SLAPPER Qt library browser (Phase 5).

A Picasa-style entry point: pick a folder, see a thumbnail grid, double-click
to open a photo in the editor. Thumbnails load on a background thread pool so
the grid never freezes, and the icon size is adjustable.

This is a focused first browser — folders + grid + open. Ratings, tags,
albums, filters, and Trash are richer features of the Tk library still to be
ported in later phases.
"""

import os

from PySide6.QtCore import Qt, QObject, QRunnable, QThreadPool, Signal, QSize
from PySide6.QtGui import QImage, QPixmap, QIcon, QAction
from PySide6.QtWidgets import (
    QMainWindow, QListWidget, QListWidgetItem, QFileDialog, QLabel, QSlider,
    QWidget, QHBoxLayout,
)

from PIL import Image, ImageOps

from . import theme
from .editor_window import EditorWindow

try:
    import snap_log
    _log = snap_log.get("snap_slapper")
except Exception:  # noqa: BLE001
    import logging
    _log = logging.getLogger("snapsmack.snap_slapper")

IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".tif", ".tiff", ".webp", ".bmp", ".gif"}
THUMB_SOURCE = 256   # thumbnails are generated at this size, displayed smaller


class _ThumbSignals(QObject):
    ready = Signal(str, QImage)


class _ThumbTask(QRunnable):
    """Load and scale one thumbnail off the GUI thread."""

    def __init__(self, path, signals):
        super().__init__()
        self.path = path
        self.signals = signals

    def run(self):
        try:
            with Image.open(self.path) as source:
                image = ImageOps.exif_transpose(source).convert("RGBA")
            image.thumbnail((THUMB_SOURCE, THUMB_SOURCE), Image.Resampling.LANCZOS)
            data = image.tobytes("raw", "RGBA")
            qimage = QImage(data, image.width, image.height,
                            image.width * 4, QImage.Format_RGBA8888).copy()
            self.signals.ready.emit(self.path, qimage)
        except Exception:  # noqa: BLE001 — a bad file just keeps its placeholder
            _log.debug("thumbnail failed for %s", self.path, exc_info=True)


class LibraryWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("SNAP SLAPPER — Library")
        self.resize(1180, 780)
        self._pool = QThreadPool.globalInstance()
        self._items = {}          # path -> QListWidgetItem
        self._editors = []        # keep editor windows alive
        self._signals = _ThumbSignals()
        self._signals.ready.connect(self._on_thumb)

        self._build_toolbar()

        self.list = QListWidget()
        self.list.setViewMode(QListWidget.IconMode)
        self.list.setResizeMode(QListWidget.Adjust)
        self.list.setMovement(QListWidget.Static)
        self.list.setSpacing(10)
        self.list.setUniformItemSizes(True)
        self.list.setIconSize(QSize(160, 160))
        self.list.setWordWrap(True)
        self.list.itemActivated.connect(self._open_item)
        self.list.itemDoubleClicked.connect(self._open_item)
        self.setCentralWidget(self.list)

        self.status = self.statusBar()
        self.status.showMessage("Choose a folder to browse your photographs.")

    def _build_toolbar(self):
        bar = self.addToolBar("Main")
        bar.setMovable(False)

        act_open = QAction("Choose Folder", self)
        act_open.triggered.connect(self.choose_folder)
        bar.addAction(act_open)

        self.act_subfolders = QAction("Include Subfolders", self)
        self.act_subfolders.setCheckable(True)
        bar.addAction(self.act_subfolders)

        bar.addSeparator()

        act_edit = QAction("Edit Selected", self)
        act_edit.triggered.connect(self._open_selected)
        bar.addAction(act_edit)

        # thumbnail size control on the right
        spacer = QWidget()
        spacer.setSizePolicy(spacer.sizePolicy().horizontalPolicy().Expanding,
                             spacer.sizePolicy().verticalPolicy().Preferred)
        bar.addWidget(spacer)
        size_wrap = QWidget()
        size_layout = QHBoxLayout(size_wrap)
        size_layout.setContentsMargins(0, 0, 8, 0)
        size_layout.setSpacing(6)
        label = QLabel("Size")
        label.setObjectName("ControlName")
        size_layout.addWidget(label)
        self.size_slider = QSlider(Qt.Horizontal)
        self.size_slider.setRange(96, 240)
        self.size_slider.setValue(160)
        self.size_slider.setFixedWidth(140)
        self.size_slider.valueChanged.connect(
            lambda v: self.list.setIconSize(QSize(v, v)))
        size_layout.addWidget(self.size_slider)
        bar.addWidget(size_wrap)

    # --- Folder scanning ----------------------------------------------------
    def choose_folder(self):
        folder = QFileDialog.getExistingDirectory(self, "Choose photo folder")
        if folder:
            self.load_folder(folder)

    def load_folder(self, folder):
        self.list.clear()
        self._items.clear()
        paths = self._scan(folder, self.act_subfolders.isChecked())
        self.setWindowTitle(f"SNAP SLAPPER — {os.path.basename(folder) or folder}")
        placeholder = self._placeholder_icon()
        for path in paths:
            item = QListWidgetItem(placeholder, os.path.basename(path))
            item.setData(Qt.UserRole, path)
            item.setToolTip(path)
            self.list.addItem(item)
            self._items[path] = item
            self._pool.start(_ThumbTask(path, self._signals))
        self.status.showMessage(f"{len(paths)} photo(s) in {folder}")

    def _scan(self, folder, recursive):
        found = []
        if recursive:
            for root, _dirs, files in os.walk(folder):
                for name in files:
                    if os.path.splitext(name)[1].lower() in IMAGE_EXTENSIONS:
                        found.append(os.path.join(root, name))
        else:
            for name in os.listdir(folder):
                full = os.path.join(folder, name)
                if os.path.isfile(full) and \
                        os.path.splitext(name)[1].lower() in IMAGE_EXTENSIONS:
                    found.append(full)
        return sorted(found, key=lambda p: os.path.basename(p).lower())

    def _placeholder_icon(self):
        pixmap = QPixmap(THUMB_SOURCE, THUMB_SOURCE)
        pixmap.fill(Qt.transparent)
        return QIcon(pixmap)

    def _on_thumb(self, path, qimage):
        item = self._items.get(path)
        if item is not None:
            item.setIcon(QIcon(QPixmap.fromImage(qimage)))

    # --- Opening ------------------------------------------------------------
    def _open_selected(self):
        items = self.list.selectedItems()
        if items:
            self._open_item(items[0])

    def _open_item(self, item):
        path = item.data(Qt.UserRole)
        if not path:
            return
        editor = EditorWindow()
        editor.open_path(path)
        editor.show()
        self._editors.append(editor)

# ===== SNAPSMACK EOF =====
