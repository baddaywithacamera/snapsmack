"""SNAP SLAPPER Qt library browser.

A Picasa-style entry point: pick a folder (or click one in the folder tree),
see a thumbnail grid, double-click to open a photo in the editor. Thumbnails
load on a background thread pool so the grid never freezes.

Features:
  - Collapsible **folder tree** on the left (toggle to slide it in/out).
  - **Sort** by name or capture date (newest / oldest). Capture date is read
    from EXIF while the thumbnail loads, so it reflects when the shot was
    taken, not when the file happened to land on disk.
  - **Search** box that filters the grid by filename as you type.
  - **Click a photo** to see its dimensions and file size in the status bar.

Ratings, tags, albums, and Trash are richer features of the Tk library still
to be ported in later phases.
"""

import os
import time

from PySide6.QtCore import (
    Qt, QObject, QRunnable, QThreadPool, Signal, QSize, QDir, QTimer,
)
from PySide6.QtGui import QImage, QPixmap, QIcon, QAction, QKeySequence
from PySide6.QtWidgets import (
    QMainWindow, QListWidget, QListWidgetItem, QFileDialog, QLabel, QSlider,
    QWidget, QHBoxLayout, QComboBox, QLineEdit, QTreeView, QSplitter,
    QFileSystemModel,
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

SORTS = [
    ("name", "Name"),
    ("date_new", "Date (newest)"),
    ("date_old", "Date (oldest)"),
]

_EXIF_DATETIME_ORIGINAL = 0x9003
_EXIF_IFD = 0x8769


def _capture_timestamp(pil_image, path):
    """Best-effort capture time as an epoch float: EXIF DateTimeOriginal when
    present, else the file's modified time. Used only for sorting."""
    try:
        exif = pil_image.getexif()
        raw = None
        try:
            raw = exif.get_ifd(_EXIF_IFD).get(_EXIF_DATETIME_ORIGINAL)
        except Exception:  # noqa: BLE001
            raw = None
        if raw:
            return time.mktime(time.strptime(str(raw), "%Y:%m:%d %H:%M:%S"))
    except Exception:  # noqa: BLE001 — any EXIF trouble just falls back
        pass
    try:
        return os.path.getmtime(path)
    except OSError:
        return 0.0


def _human_size(num_bytes):
    value = float(num_bytes)
    for unit in ("B", "KB", "MB", "GB"):
        if value < 1024 or unit == "GB":
            return f"{value:.0f} {unit}" if unit == "B" else f"{value:.1f} {unit}"
        value /= 1024
    return f"{value:.1f} GB"


class _ThumbSignals(QObject):
    ready = Signal(str, QImage, float)   # path, thumbnail, capture timestamp


class _ThumbTask(QRunnable):
    """Load one thumbnail (and its capture time) off the GUI thread."""

    def __init__(self, path, signals):
        super().__init__()
        self.path = path
        self.signals = signals

    def run(self):
        try:
            with Image.open(self.path) as source:
                stamp = _capture_timestamp(source, self.path)
                image = ImageOps.exif_transpose(source).convert("RGBA")
            image.thumbnail((THUMB_SOURCE, THUMB_SOURCE), Image.Resampling.LANCZOS)
            data = image.tobytes("raw", "RGBA")
            qimage = QImage(data, image.width, image.height,
                            image.width * 4, QImage.Format_RGBA8888).copy()
            self.signals.ready.emit(self.path, qimage, stamp)
        except Exception:  # noqa: BLE001 — a bad file just keeps its placeholder
            _log.debug("thumbnail failed for %s", self.path, exc_info=True)


class _ScanSignals(QObject):
    ready = Signal(str, bool, int, object)  # folder, recursive, generation, paths


class _ScanToken:
    def __init__(self):
        self.cancelled = False


class _ScanTask(QRunnable):
    """Enumerate photo paths without blocking the library window."""

    def __init__(self, folder, recursive, generation, signals, token):
        super().__init__()
        self.folder = folder
        self.recursive = recursive
        self.generation = generation
        self.signals = signals
        self.token = token

    def run(self):
        found = []
        if self.recursive:
            def ignore_error(_error):
                return None

            for root, _dirs, files in os.walk(
                    self.folder, onerror=ignore_error, followlinks=False):
                if self.token.cancelled:
                    return
                for name in files:
                    if os.path.splitext(name)[1].lower() in IMAGE_EXTENSIONS:
                        found.append(os.path.join(root, name))
        else:
            try:
                with os.scandir(self.folder) as entries:
                    for entry in entries:
                        if self.token.cancelled:
                            return
                        try:
                            if entry.is_file() and os.path.splitext(
                                    entry.name)[1].lower() in IMAGE_EXTENSIONS:
                                found.append(entry.path)
                        except OSError:
                            continue
            except OSError:
                pass
        self.signals.ready.emit(
            self.folder, self.recursive, self.generation, found)


class LibraryWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("SNAP SLAPPER — Library")
        self.resize(1180, 780)
        self._pool = QThreadPool.globalInstance()
        self._scan_pool = QThreadPool(self)
        self._scan_pool.setMaxThreadCount(1)
        self._items = {}          # path -> QListWidgetItem
        self._icons = {}          # path -> QIcon (cached so re-sort never re-decodes)
        self._stamps = {}         # path -> capture timestamp (float)
        self._paths = []          # all photo paths in the current folder
        self._folder = None
        self._scan_generation = 0
        self._scan_token = None
        self._populate_generation = 0
        self._editors = []        # keep editor windows alive
        self._signals = _ThumbSignals()
        self._signals.ready.connect(self._on_thumb)
        self._scan_signals = _ScanSignals()
        self._scan_signals.ready.connect(self._on_scan_ready)

        from . import prefs
        settings = prefs.load()
        self._sort = settings.get("library_sort", "name")
        folders_visible = bool(settings.get("library_folders_visible", True))

        self._build_toolbar()
        self.act_subfolders.blockSignals(True)
        self.act_subfolders.setChecked(
            bool(settings.get("library_include_subfolders", False)))
        self.act_subfolders.blockSignals(False)
        self._update_subfolder_action()

        # Left: folder tree. Right: thumbnail grid. Split so the tree slides
        # in and out without disturbing the grid.
        self.tree_model = QFileSystemModel(self)
        self.tree_model.setRootPath("")
        self.tree_model.setFilter(QDir.Dirs | QDir.Drives | QDir.NoDotAndDotDot)
        self.tree = QTreeView()
        self.tree.setModel(self.tree_model)
        self.tree.setHeaderHidden(True)
        for column in range(1, self.tree_model.columnCount()):
            self.tree.hideColumn(column)
        self.tree.setMinimumWidth(180)
        self.tree.clicked.connect(self._tree_clicked)

        self.list = QListWidget()
        self.list.setViewMode(QListWidget.IconMode)
        self.list.setResizeMode(QListWidget.Adjust)
        self.list.setMovement(QListWidget.Static)
        self.list.setSpacing(10)
        self.list.setUniformItemSizes(True)
        self.list.setIconSize(QSize(160, 160))
        self.list.setWordWrap(True)
        grid_font = self.list.font()   # filenames under each thumb were too small
        grid_font.setPointSize(max(grid_font.pointSize() + 2, 11))
        self.list.setFont(grid_font)
        self.list.itemActivated.connect(self._open_item)
        self.list.itemDoubleClicked.connect(self._open_item)
        self.list.itemClicked.connect(self._show_info)
        self.list.currentItemChanged.connect(
            lambda cur, _prev: self._show_info(cur))

        self.split = QSplitter(Qt.Horizontal)
        self.split.addWidget(self.tree)
        self.split.addWidget(self.list)
        self.split.setStretchFactor(0, 0)
        self.split.setStretchFactor(1, 1)
        self.split.setSizes([240, 940])
        self.setCentralWidget(self.split)

        self.tree.setVisible(folders_visible)
        self.act_folders.setChecked(folders_visible)

        self.status = self.statusBar()
        self.status.showMessage("Choose a folder to browse your photographs.")

    # --- Toolbar ------------------------------------------------------------
    def _build_toolbar(self):
        bar = self.addToolBar("Main")
        bar.setMovable(False)

        act_open = QAction("Choose Folder", self)
        act_open.triggered.connect(self.choose_folder)
        bar.addAction(act_open)

        self.act_folders = QAction("Folders", self)
        self.act_folders.setCheckable(True)
        self.act_folders.setToolTip("Show or hide the folder tree")
        self.act_folders.toggled.connect(self._toggle_folders)
        bar.addAction(self.act_folders)

        self.act_subfolders = QAction("Subfolders: OFF", self)
        self.act_subfolders.setCheckable(True)
        self.act_subfolders.setToolTip(
            "OFF — show photographs only from the selected folder")
        self.act_subfolders.toggled.connect(self._subfolders_toggled)
        bar.addAction(self.act_subfolders)

        bar.addSeparator()

        act_edit = QAction("Edit Selected", self)
        act_edit.triggered.connect(self._open_selected)
        bar.addAction(act_edit)

        act_help = QAction("Help", self)
        act_help.setShortcut(QKeySequence.HelpContents)   # F1
        act_help.triggered.connect(self._open_help)
        bar.addAction(act_help)

        bar.addSeparator()

        # Sort control
        sort_label = QLabel("Sort")
        sort_label.setObjectName("ControlName")
        bar.addWidget(sort_label)
        self.sort_combo = QComboBox()
        for key, name in SORTS:
            self.sort_combo.addItem(name, key)
        index = self.sort_combo.findData(self._sort)
        self.sort_combo.setCurrentIndex(index if index >= 0 else 0)
        self.sort_combo.currentIndexChanged.connect(self._sort_changed)
        bar.addWidget(self.sort_combo)

        # Search box
        self.search = QLineEdit()
        self.search.setPlaceholderText("Search filenames…")
        self.search.setClearButtonEnabled(True)
        self.search.setFixedWidth(200)
        self.search.textChanged.connect(self._apply_filter)
        bar.addWidget(self.search)

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

    # --- Folder tree --------------------------------------------------------
    def _toggle_folders(self, checked):
        self.tree.setVisible(bool(checked))
        from . import prefs
        values = prefs.load()
        values["library_folders_visible"] = bool(checked)
        prefs.save(values)

    def _tree_clicked(self, index):
        path = self.tree_model.filePath(index)
        if path and os.path.isdir(path):
            self.load_folder(path)

    # --- Folder scanning ----------------------------------------------------
    def choose_folder(self):
        dialog = QFileDialog(self, "Choose photo folder")
        dialog.setFileMode(QFileDialog.Directory)
        dialog.setOption(QFileDialog.ShowDirsOnly, True)
        # The Windows shell picker can hang while resolving disconnected drives
        # and stale network locations. Qt's own directory view stays responsive.
        dialog.setOption(QFileDialog.DontUseNativeDialog, True)
        folder = ""
        if dialog.exec():
            selected = dialog.selectedFiles()
            folder = selected[0] if selected else ""
        if folder:
            self.load_folder(folder)
            index = self.tree_model.index(folder)
            if index.isValid():
                self.tree.setCurrentIndex(index)
                self.tree.scrollTo(index)
                self.tree.expand(index)

    def _subfolders_toggled(self, checked):
        from . import prefs
        values = prefs.load()
        values["library_include_subfolders"] = bool(checked)
        prefs.save(values)
        self._update_subfolder_action()
        self._reload_current()

    def _update_subfolder_action(self):
        enabled = self.act_subfolders.isChecked()
        self.act_subfolders.setText(
            "Subfolders: ON" if enabled else "Subfolders: OFF")
        self.act_subfolders.setToolTip(
            "ON — include photographs from every folder below the selection"
            if enabled else
            "OFF — show photographs only from the selected folder")

    def _reload_current(self, _checked=None):
        if self._folder:
            self.load_folder(self._folder)

    def load_folder(self, folder):
        folder = os.path.abspath(folder)
        recursive = self.act_subfolders.isChecked()
        self._folder = folder
        self._scan_generation += 1
        generation = self._scan_generation
        if self._scan_token is not None:
            self._scan_token.cancelled = True
        self._scan_token = _ScanToken()
        self._populate_generation += 1  # cancel a pending incremental populate
        self._paths = []
        self._icons.clear()
        self._stamps.clear()
        self.list.clear()
        self._items.clear()
        self.setWindowTitle(f"SNAP SLAPPER — {os.path.basename(folder) or folder}")
        scope = "folder and subfolders" if recursive else "folder only"
        self.status.showMessage(f"Scanning {scope}…  {folder}")
        self._scan_pool.start(_ScanTask(
            folder, recursive, generation, self._scan_signals,
            self._scan_token))

    def _scan(self, folder, recursive):
        """Synchronous helper retained for tests and small direct callers."""
        found = []
        if recursive:
            for root, _dirs, files in os.walk(folder, followlinks=False):
                found.extend(os.path.join(root, name) for name in files
                             if os.path.splitext(name)[1].lower()
                             in IMAGE_EXTENSIONS)
        else:
            try:
                with os.scandir(folder) as entries:
                    found.extend(entry.path for entry in entries
                                 if entry.is_file() and os.path.splitext(
                                     entry.name)[1].lower()
                                 in IMAGE_EXTENSIONS)
            except OSError:
                pass
        return found

    def _on_scan_ready(self, folder, recursive, generation, paths):
        if generation != self._scan_generation or folder != self._folder or \
                recursive != self.act_subfolders.isChecked():
            return  # a newer folder/scope selection superseded this scan
        self._paths = paths
        self._populate()

    # --- Grid population, sort, filter --------------------------------------
    def _sorted_paths(self):
        if self._sort == "name":
            return sorted(self._paths, key=lambda p: os.path.basename(p).lower())
        newest = self._sort == "date_new"
        return sorted(self._paths,
                      key=lambda p: self._stamps.get(p, self._fallback_stamp(p)),
                      reverse=newest)

    def _fallback_stamp(self, path):
        try:
            return os.path.getmtime(path)
        except OSError:
            return 0.0

    def _populate(self):
        self.list.clear()
        self._items.clear()
        placeholder = self._placeholder_icon()
        paths = self._sorted_paths()
        self._populate_generation += 1
        generation = self._populate_generation

        def add_batch(offset=0):
            if generation != self._populate_generation:
                return
            end = min(offset + 150, len(paths))
            for path in paths[offset:end]:
                icon = self._icons.get(path, placeholder)
                item = QListWidgetItem(icon, os.path.basename(path))
                item.setData(Qt.UserRole, path)
                item.setToolTip(path)
                self.list.addItem(item)
                self._items[path] = item
                if path not in self._icons:
                    self._pool.start(_ThumbTask(path, self._signals))
            if end < len(paths):
                self.status.showMessage(
                    f"Showing {end} of {len(paths)} photos…")
                QTimer.singleShot(0, lambda: add_batch(end))
            else:
                self._apply_filter(self.search.text())

        add_batch()

    def _sort_changed(self, _index):
        self._sort = self.sort_combo.currentData()
        from . import prefs
        values = prefs.load()
        values["library_sort"] = self._sort
        prefs.save(values)
        if self._paths:
            self._populate()

    def _apply_filter(self, text):
        needle = (text or "").strip().lower()
        shown = 0
        for path, item in self._items.items():
            match = needle in os.path.basename(path).lower()
            item.setHidden(not match)
            if match:
                shown += 1
        if needle and self._folder:
            self.status.showMessage(
                f"{shown} of {len(self._paths)} match “{text}”")
        elif self._folder:
            self.status.showMessage(f"{len(self._paths)} photo(s) in {self._folder}")

    def _placeholder_icon(self):
        pixmap = QPixmap(THUMB_SOURCE, THUMB_SOURCE)
        pixmap.fill(Qt.transparent)
        return QIcon(pixmap)

    def _on_thumb(self, path, qimage, stamp):
        icon = QIcon(QPixmap.fromImage(qimage))
        self._icons[path] = icon
        self._stamps[path] = stamp
        item = self._items.get(path)
        if item is not None:
            item.setIcon(icon)
        # Once every thumbnail (and its capture date) is in, settle a date sort.
        if self._sort in ("date_new", "date_old") and \
                len(self._stamps) == len(self._paths) and self._paths:
            self._populate()

    # --- Info on click ------------------------------------------------------
    def _show_info(self, item):
        if item is None:
            return
        path = item.data(Qt.UserRole)
        if not path:
            return
        name = os.path.basename(path)
        try:
            size = _human_size(os.path.getsize(path))
        except OSError:
            size = "?"
        dimensions = ""
        try:
            with Image.open(path) as probe:   # header read only, no full decode
                dimensions = f"{probe.width}×{probe.height}  •  "
        except Exception:  # noqa: BLE001
            dimensions = ""
        self.status.showMessage(f"{name}  •  {dimensions}{size}")

    # --- Opening ------------------------------------------------------------
    def _open_help(self):
        from .help_dialog import HelpDialog
        HelpDialog(self).show()

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
