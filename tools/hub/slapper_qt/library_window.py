"""SNAP SLAPPER Qt library browser.

A Picasa-style entry point: pick a folder (or click one in the folder tree),
see a thumbnail grid, double-click to open a photo in the editor. Thumbnails
load on a background thread pool so the grid never freezes.

The Qt library retains the established organizer features: saved folders,
albums, blog archives, metadata, filtering, bulk file operations, recoverable
Trash, external editors, diagnostics, backup, and slideshow.
"""

import os
import time
import hashlib
import ctypes
import datetime
import json
import shutil
import subprocess

from PySide6.QtCore import Qt, QObject, QRunnable, QThreadPool, Signal, QSize, QDir, QTimer
from PySide6.QtGui import QImage, QPixmap, QIcon, QAction, QKeySequence
from PySide6.QtWidgets import (
    QMainWindow, QListWidget, QListWidgetItem, QFileDialog, QLabel, QSlider,
    QWidget, QHBoxLayout, QComboBox, QLineEdit, QTreeView, QSplitter,
    QFileSystemModel, QMenu, QMessageBox, QDockWidget, QVBoxLayout, QFormLayout,
    QPushButton, QCheckBox, QSpinBox, QInputDialog, QAbstractItemView,
    QDialog,
)

from PIL import Image, ImageOps

from . import theme
from .editor_window import EditorWindow
from .library_state import LibraryState
import photo_manager

try:
    import snap_log
    _log = snap_log.get("snap_slapper")
except Exception:  # noqa: BLE001
    import logging
    _log = logging.getLogger("snapsmack.snap_slapper")

IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png", ".tif", ".tiff", ".webp", ".bmp", ".gif",
                    ".dng", ".nef", ".cr2", ".cr3", ".arw", ".orf", ".rw2", ".raf"}
RAW_EXTENSIONS = {".dng", ".nef", ".cr2", ".cr3", ".arw", ".orf", ".rw2", ".raf"}
THUMB_SOURCE = 256   # thumbnails are generated at this size, displayed smaller

SORTS = [
    ("name", "Name"),
    ("name_desc", "Name (Z–A)"),
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


def _is_online_only(path):
    """True for a Windows cloud placeholder whose contents are not local.

    Reading file attributes does not hydrate OneDrive Files On-Demand. Opening
    the file with Pillow does, so automatic thumbnail work must stop here.
    """
    if os.name != "nt":
        return False
    try:
        attrs = ctypes.windll.kernel32.GetFileAttributesW(str(path))
        if attrs == 0xFFFFFFFF:
            return False
        offline = 0x00001000
        recall_on_open = 0x00040000
        recall_on_data_access = 0x00400000
        return bool(attrs & (offline | recall_on_open | recall_on_data_access))
    except Exception:
        return False


def _move_to_recycle_bin(path):
    """Move one file through the Windows shell; never permanently delete it."""
    if os.name != "nt":
        return False
    try:
        from ctypes import wintypes

        class SHFILEOPSTRUCTW(ctypes.Structure):
            _fields_ = [
                ("hwnd", wintypes.HWND), ("wFunc", wintypes.UINT),
                ("pFrom", wintypes.LPCWSTR), ("pTo", wintypes.LPCWSTR),
                ("fFlags", wintypes.WORD), ("fAnyOperationsAborted", wintypes.BOOL),
                ("hNameMappings", wintypes.LPVOID),
                ("lpszProgressTitle", wintypes.LPCWSTR),
            ]

        operation = SHFILEOPSTRUCTW()
        operation.wFunc = 3                    # FO_DELETE
        operation.pFrom = os.path.abspath(path) + "\0\0"
        operation.fFlags = 0x0040 | 0x0010 | 0x0400  # ALLOWUNDO, NOCONFIRMATION, NOERRORUI
        result = ctypes.windll.shell32.SHFileOperationW(ctypes.byref(operation))
        return result == 0 and not operation.fAnyOperationsAborted and not os.path.exists(path)
    except Exception:
        return False


class _ThumbSignals(QObject):
    ready = Signal(str, QImage, float)   # path, thumbnail, capture timestamp


class _ThumbTask(QRunnable):
    """Load one thumbnail (and its capture time) off the GUI thread."""

    def __init__(self, path, signals, cache_dir):
        super().__init__()
        self.path = path
        self.signals = signals
        self.cache_dir = cache_dir

    def _cache_paths(self):
        try:
            stat = os.stat(self.path)
            identity = f"{os.path.abspath(self.path)}|{stat.st_mtime_ns}|{stat.st_size}|{THUMB_SOURCE}"
            key = hashlib.sha1(identity.encode("utf-8")).hexdigest()
            return (os.path.join(self.cache_dir, key + ".jpg"),
                    os.path.join(self.cache_dir, key + ".stamp"))
        except OSError:
            return "", ""

    def run(self):
        try:
            cache_path, stamp_path = self._cache_paths()
            if cache_path and os.path.isfile(cache_path):
                qimage = QImage(cache_path)
                if not qimage.isNull():
                    try:
                        with open(stamp_path, encoding="ascii") as handle:
                            stamp = float(handle.read())
                    except Exception:
                        stamp = os.path.getmtime(self.path)
                    self.signals.ready.emit(self.path, qimage, stamp)
                    return
            with Image.open(self.path) as source:
                stamp = _capture_timestamp(source, self.path)
                image = ImageOps.exif_transpose(source).convert("RGBA")
            image.thumbnail((THUMB_SOURCE, THUMB_SOURCE), Image.Resampling.LANCZOS)
            if cache_path:
                try:
                    os.makedirs(self.cache_dir, exist_ok=True)
                    tmp = cache_path + ".tmp"
                    image.convert("RGB").save(tmp, "JPEG", quality=82)
                    os.replace(tmp, cache_path)
                    with open(stamp_path, "w", encoding="ascii") as handle:
                        handle.write(str(stamp))
                except Exception:
                    pass
            data = image.tobytes("raw", "RGBA")
            qimage = QImage(data, image.width, image.height,
                            image.width * 4, QImage.Format_RGBA8888).copy()
            self.signals.ready.emit(self.path, qimage, stamp)
        except Exception:  # noqa: BLE001 — a bad file just keeps its placeholder
            _log.debug("thumbnail failed for %s", self.path, exc_info=True)


class LibraryWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("SNAP SLAPPER — Library")
        self.resize(1180, 780)
        # A dedicated, deliberately small pool keeps hundreds of TIFF/JPEG
        # decodes from starving Qt's event loop and making Windows report a hang.
        self._pool = QThreadPool(self)
        self._pool.setMaxThreadCount(3)
        try:
            import snap_home
            self._thumb_cache = os.path.join(
                os.path.dirname(snap_home.config_path("snap_slapper", "slapper_qt.json")),
                "thumbnail_cache_qt")
        except Exception:
            self._thumb_cache = os.path.join(os.path.expanduser("~"), ".snapsmack-thumbs")
        self._items = {}          # path -> QListWidgetItem
        self._icons = {}          # path -> QIcon (cached so re-sort never re-decodes)
        self._stamps = {}         # path -> capture timestamp (float)
        self._paths = []          # all photo paths in the current folder
        self._online_only = set() # cloud placeholders: never auto-download
        self._folder = None
        self._folder_signature = None
        self._source_label = ""
        self._source = ("current", "")
        self._state = LibraryState()
        self._editors = []        # keep editor windows alive
        self._signals = _ThumbSignals()
        self._signals.ready.connect(self._on_thumb)

        from . import prefs
        settings = prefs.load()
        self._sort = settings.get("library_sort", "name")
        folders_visible = bool(settings.get("library_folders_visible", True))

        self._build_toolbar()
        self._build_filter_toolbar()

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
        self.list.setSelectionMode(QAbstractItemView.ExtendedSelection)
        grid_font = self.list.font()   # filenames under each thumb were too small
        grid_font.setPointSize(max(grid_font.pointSize() + 2, 11))
        self.list.setFont(grid_font)
        self.list.itemActivated.connect(self._open_item)
        self.list.itemDoubleClicked.connect(self._open_item)
        self.list.itemClicked.connect(self._show_info)
        self.list.currentItemChanged.connect(
            lambda cur, _prev: self._show_info(cur))
        self.list.setContextMenuPolicy(Qt.CustomContextMenu)
        self.list.customContextMenuRequested.connect(self._show_photo_menu)

        delete_action = QAction("Move to Recycle Bin", self)
        delete_action.setShortcut(QKeySequence.Delete)
        delete_action.setShortcutContext(Qt.WidgetWithChildrenShortcut)
        delete_action.triggered.connect(self._delete_selected)
        self.list.addAction(delete_action)
        self._delete_action = delete_action

        self.split = QSplitter(Qt.Horizontal)
        self.split.addWidget(self.tree)
        self.split.addWidget(self.list)
        self.split.setStretchFactor(0, 0)
        self.split.setStretchFactor(1, 1)
        self.split.setSizes([240, 940])
        self.setCentralWidget(self.split)
        self._build_info_dock()
        self._build_menus()
        self._refresh_source_picker()
        self._refresh_timer = QTimer(self)
        self._refresh_timer.setInterval(8000)
        self._refresh_timer.timeout.connect(self._auto_refresh_tick)
        self._refresh_timer.start()

        self.tree.setVisible(folders_visible)
        self.act_folders.setChecked(folders_visible)
        self.act_subfolders.setChecked(
            bool(settings.get("library_include_subfolders", False)))

        self.status = self.statusBar()
        self.status.showMessage("Choose a folder to browse your photographs.")
        remembered = str(settings.get("library_folder", "") or "")
        if remembered and os.path.isdir(remembered):
            self.load_folder(remembered)
            QTimer.singleShot(0, lambda: self._reveal_folder(remembered))
            QTimer.singleShot(500, lambda: self._reveal_folder(remembered))

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

        self.act_subfolders = QAction("Include Subfolders", self)
        self.act_subfolders.setCheckable(True)
        self.act_subfolders.toggled.connect(self._reload_current)
        bar.addAction(self.act_subfolders)

        bar.addSeparator()

        act_edit = QAction("Edit Selected", self)
        act_edit.triggered.connect(self._open_selected)
        bar.addAction(act_edit)

        act_help = QAction("Help", self)
        act_help.setShortcut(QKeySequence.HelpContents)   # F1
        act_help.triggered.connect(self._open_help)
        bar.addAction(act_help)

        act_prefs = QAction("Preferences", self)
        act_prefs.triggered.connect(self._open_preferences)
        bar.addAction(act_prefs)

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

    def _build_filter_toolbar(self):
        bar = self.addToolBar("Library filters")
        bar.setMovable(False)
        bar.addWidget(QLabel("Source"))
        self.source_combo = QComboBox()
        self.source_combo.setMinimumWidth(220)
        self.source_combo.currentIndexChanged.connect(self._source_changed)
        bar.addWidget(self.source_combo)
        bar.addSeparator()
        bar.addWidget(QLabel("Date"))
        self.date_filter = QComboBox()
        self.date_filter.addItem("All dates", "")
        self.date_filter.currentIndexChanged.connect(lambda _i: self._apply_filter(self.search.text()))
        bar.addWidget(self.date_filter)
        bar.addWidget(QLabel("Show"))
        self.meta_filter = QComboBox()
        for label, value in (("All photos", "all"), ("Favorites", "favorite"),
                             ("Rated", "rated"), ("Unrated", "unrated"),
                             ("1+ stars", "1+"), ("2+ stars", "2+"),
                             ("3+ stars", "3+"), ("4+ stars", "4+"),
                             ("5 stars", "5")):
            self.meta_filter.addItem(label, value)
        self.meta_filter.currentIndexChanged.connect(lambda _i: self._apply_filter(self.search.text()))
        bar.addWidget(self.meta_filter)
        bar.addWidget(QLabel("Tag"))
        self.tag_filter = QLineEdit()
        self.tag_filter.setPlaceholderText("Filter tags…")
        self.tag_filter.setFixedWidth(140)
        self.tag_filter.textChanged.connect(lambda _t: self._apply_filter(self.search.text()))
        bar.addWidget(self.tag_filter)
        reset = QAction("Reset filters", self)
        reset.triggered.connect(self._reset_filters)
        bar.addAction(reset)

    def _build_info_dock(self):
        dock = QDockWidget("PHOTO INFO", self)
        dock.setObjectName("PhotoInfoDock")
        panel = QWidget()
        layout = QVBoxLayout(panel)
        self.info_preview = QLabel("Select a photo")
        self.info_preview.setAlignment(Qt.AlignCenter)
        self.info_preview.setMinimumHeight(180)
        layout.addWidget(self.info_preview)
        self.favorite_check = QCheckBox("♥ FAVORITE")
        self.favorite_check.toggled.connect(self._save_selected_details)
        layout.addWidget(self.favorite_check)
        form = QFormLayout()
        self.rating_spin = QSpinBox()
        self.rating_spin.setRange(0, 5)
        self.rating_spin.valueChanged.connect(self._save_selected_details)
        form.addRow("Rating", self.rating_spin)
        self.tags_edit = QLineEdit()
        self.tags_edit.setPlaceholderText("comma-separated tags")
        self.tags_edit.editingFinished.connect(self._save_selected_details)
        form.addRow("Tags", self.tags_edit)
        layout.addLayout(form)
        self.info_text = QLabel("")
        self.info_text.setWordWrap(True)
        self.info_text.setTextInteractionFlags(Qt.TextSelectableByMouse)
        layout.addWidget(self.info_text, 1)
        show = QPushButton("SHOW IN FOLDER")
        show.clicked.connect(self._show_in_folder)
        layout.addWidget(show)
        external = QPushButton("OPEN WITH / EDIT COPY")
        external.clicked.connect(self._open_with_menu)
        layout.addWidget(external)
        dock.setWidget(panel)
        self.addDockWidget(Qt.RightDockWidgetArea, dock)
        self.info_dock = dock
        self._updating_details = False

    def _action(self, menu, label, handler, shortcut=None):
        action = menu.addAction(label)
        action.triggered.connect(handler)
        if shortcut:
            action.setShortcut(shortcut)
        return action

    def _build_menus(self):
        library = self.menuBar().addMenu("Library")
        self.library_menu = library
        self._action(library, "Open local folder…", self.choose_folder)
        self._action(library, "Remove saved folder", self._remove_saved_folder)
        self._action(library, "Refresh now", self._reload_current, "F5")
        self.auto_refresh_action = self._action(library, "Auto refresh", lambda: None)
        self.auto_refresh_action.setCheckable(True); self.auto_refresh_action.setChecked(True)
        self._action(library, "Import photos…", self._import_photos)
        library.addSeparator()
        self._action(library, "Back up organizer data…", self._backup_organizer)

        organize = self.menuBar().addMenu("Organize")
        self.organize_menu = organize
        self._action(organize, "Add selection to album…", self._add_to_album)
        self._action(organize, "Add tags to selection…", self._bulk_tags)
        self._action(organize, "Remove tag from selection…", self._remove_tag)
        self._action(organize, "Set rating on selection…", self._bulk_rating)
        self._action(organize, "Toggle favorite on selection", self._bulk_favorite)
        organize.addSeparator()
        self._action(organize, "Copy selection…", lambda: self._transfer_selection(False))
        self._action(organize, "Move selection…", lambda: self._transfer_selection(True))
        self._action(organize, "Rename selected photo…", self._rename_selected)
        self._action(organize, "Export selection…", self._export_selection)
        self._action(organize, "Choose saved images folder…", self._choose_exports_folder)
        self._action(organize, "Choose project folder…", self._choose_projects_folder)
        self._action(organize, "Rotate selection left", lambda: self._rotate_selection(90))
        self._action(organize, "Rotate selection right", lambda: self._rotate_selection(-90))
        organize.addSeparator()
        self._action(organize, "Find exact duplicates", self._find_duplicates)
        self._action(organize, "Find blurry / dark photos", self._find_quality_issues)
        organize.addSeparator()
        self._action(organize, "Move selection to SNAP SLAPPER Trash…", self._trash_selection)
        self._action(organize, "Restore last trashed photo", self._restore_trash)

        view = self.menuBar().addMenu("View")
        self.view_menu = view
        self._action(view, "Slideshow", self._start_slideshow, "F11")
        view.addAction(self.info_dock.toggleViewAction())

    def _refresh_source_picker(self):
        current = self.source_combo.currentData() if self.source_combo.count() else None
        self.source_combo.blockSignals(True)
        self.source_combo.clear()
        self.source_combo.addItem("Current folder", ("current", ""))
        for folder in self._state.folders():
            self.source_combo.addItem("Folder · " + (os.path.basename(folder) or folder), ("folder", folder))
        for name in sorted(self._state.albums(), key=str.lower):
            self.source_combo.addItem("Album · " + name, ("album", name))
        try:
            import snap_home
            root = snap_home.shared_library()
            for name in sorted(os.listdir(root), key=str.lower):
                folder = os.path.join(root, name)
                if os.path.isfile(os.path.join(folder, "index.json")):
                    self.source_combo.addItem("Blog archive · " + name, ("shared", folder))
        except Exception:
            pass
        if current is not None:
            index = self.source_combo.findData(current)
            if index >= 0:
                self.source_combo.setCurrentIndex(index)
        self.source_combo.blockSignals(False)

    def _source_changed(self, _index):
        data = self.source_combo.currentData()
        if not data or data[0] == "current":
            return
        kind, value = data
        if kind == "folder":
            self.load_folder(value)
            self._reveal_folder(value)
        elif kind == "album":
            self._load_path_source(self._state.albums().get(value, []),
                                   "Album · " + value, (kind, value))
        elif kind == "shared":
            self._load_blog_archive(value)

    def _load_blog_archive(self, folder):
        paths = []
        try:
            with open(os.path.join(folder, "index.json"), encoding="utf-8") as handle:
                images = (json.load(handle) or {}).get("images", {})
            paths = [os.path.normpath(os.path.join(folder, row.get("thumb_file", "")))
                     for row in images.values() if row.get("thumb_file")]
        except Exception as exc:
            QMessageBox.warning(self, "Library unavailable", str(exc))
        self._load_path_source(paths, "Blog archive · " + os.path.basename(folder),
                               ("shared", folder))

    def _load_path_source(self, paths, label, source=("current", "")):
        self._folder = None
        self._source_label = label
        self._source = source
        self._paths = [path for path in paths if os.path.isfile(path)]
        self._icons.clear(); self._stamps.clear()
        self._online_only = {path for path in self._paths if _is_online_only(path)}
        self._populate()
        for path in self._paths:
            if path not in self._online_only:
                self._pool.start(_ThumbTask(path, self._signals, self._thumb_cache))
        self._refresh_date_filter()
        self.status.showMessage(f"{len(self._paths)} photo(s) in {label}")

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

    def _reveal_folder(self, folder):
        # Preserve the normal drive -> Users -> OneDrive -> Pictures hierarchy.
        # Only the remembered path is expanded; thumbnail loading separately
        # refuses online-only cloud placeholders.
        self.tree_model.setRootPath("")
        self.tree.setRootIndex(self.tree_model.index(""))
        index = self.tree_model.index(folder)
        if not index.isValid():
            return
        parent = index.parent()
        parents = []
        while parent.isValid():
            parents.append(parent)
            parent = parent.parent()
        for ancestor in reversed(parents):
            self.tree.expand(ancestor)
        self.tree.setCurrentIndex(index)
        self.tree.scrollTo(index, QTreeView.PositionAtCenter)

    # --- Folder scanning ----------------------------------------------------
    def choose_folder(self):
        folder = QFileDialog.getExistingDirectory(
            self, "Choose photo folder", self._folder or "")
        if folder:
            from . import prefs
            values = prefs.load()
            values["library_folder"] = folder
            prefs.save(values)
            folders = self._state.folders()
            if folder not in folders:
                self._state.save_folders(folders + [folder])
                self._refresh_source_picker()
            self.load_folder(folder)
            index = self.tree_model.index(folder)
            if index.isValid():
                self.tree.setCurrentIndex(index)
                self.tree.scrollTo(index)
                self.tree.expand(index)

    def _reload_current(self, _checked=None):
        from . import prefs
        values = prefs.load()
        values["library_include_subfolders"] = self.act_subfolders.isChecked()
        prefs.save(values)
        if self._folder:
            self.load_folder(self._folder)
        elif self._source[0] == "album":
            name = self._source[1]
            self._load_path_source(self._state.albums().get(name, []),
                                   "Album · " + name, self._source)
        elif self._source[0] == "shared":
            self._load_blog_archive(self._source[1])

    def _open_preferences(self):
        from .prefs_dialog import PreferencesDialog
        from . import prefs
        if PreferencesDialog(self).exec():
            values = prefs.load()
            self.act_subfolders.setChecked(
                bool(values.get("library_include_subfolders", False)))
            folder = str(values.get("library_folder", "") or "")
            if folder and os.path.isdir(folder) and folder != self._folder:
                self.load_folder(folder)

    def load_folder(self, folder):
        self._folder = folder
        self._source_label = folder
        self._source = ("folder", folder)
        self._paths = self._scan(folder, self.act_subfolders.isChecked())
        self._folder_signature = self._source_signature(folder)
        self._icons.clear()
        self._stamps.clear()
        self._online_only = {path for path in self._paths if _is_online_only(path)}
        self.setWindowTitle(f"SNAP SLAPPER — {os.path.basename(folder) or folder}")
        self._populate()
        for path in self._paths:
            if path not in self._online_only:
                self._pool.start(_ThumbTask(path, self._signals, self._thumb_cache))
        cloud_note = (f" · {len(self._online_only)} online-only left undownloaded"
                      if self._online_only else "")
        self.status.showMessage(f"{len(self._paths)} photo(s) in {folder}{cloud_note}")
        self._refresh_date_filter()

    def _scan(self, folder, recursive):
        found = []
        if recursive:
            for root, _dirs, files in os.walk(folder):
                for name in files:
                    if os.path.splitext(name)[1].lower() in IMAGE_EXTENSIONS:
                        found.append(os.path.join(root, name))
        else:
            try:
                for name in os.listdir(folder):
                    full = os.path.join(folder, name)
                    if os.path.isfile(full) and \
                            os.path.splitext(name)[1].lower() in IMAGE_EXTENSIONS:
                        found.append(full)
            except OSError:
                return []
        return found

    # --- Grid population, sort, filter --------------------------------------
    def _sorted_paths(self):
        if self._sort == "name":
            return sorted(self._paths, key=lambda p: os.path.basename(p).lower())
        if self._sort == "name_desc":
            return sorted(self._paths, key=lambda p: os.path.basename(p).lower(), reverse=True)
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
        for path in self._sorted_paths():
            icon = self._icons.get(path, placeholder)
            label = os.path.basename(path)
            meta = self._state.photo(path)
            if meta["favorite"]: label = "♥  " + label
            if meta["rating"]: label += "\n" + ("★" * meta["rating"])
            if path in self._online_only:
                label = "☁  " + label
            item = QListWidgetItem(icon, label)
            item.setData(Qt.UserRole, path)
            item.setToolTip(path + ("\nOnline-only — not downloaded automatically"
                                    if path in self._online_only else ""))
            self.list.addItem(item)
            self._items[path] = item
        self._apply_filter(self.search.text())

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
        month = self.date_filter.currentData() if hasattr(self, "date_filter") else ""
        meta_mode = self.meta_filter.currentData() if hasattr(self, "meta_filter") else "all"
        tag = self.tag_filter.text().strip().lower() if hasattr(self, "tag_filter") else ""
        shown = 0
        for path, item in self._items.items():
            match = needle in os.path.basename(path).lower()
            if match and month:
                match = datetime.datetime.fromtimestamp(self._fallback_stamp(path)).strftime("%Y-%m") == month
            details = self._state.photo(path)
            if match and meta_mode == "favorite": match = details["favorite"]
            elif match and meta_mode == "rated": match = details["rating"] > 0
            elif match and meta_mode == "unrated": match = details["rating"] == 0
            elif match and meta_mode.endswith("+"): match = details["rating"] >= int(meta_mode[0])
            elif match and meta_mode.isdigit(): match = details["rating"] == int(meta_mode)
            if match and tag:
                match = tag in details["tags"].lower()
            item.setHidden(not match)
            if match:
                shown += 1
        if needle and self._folder:
            self.status.showMessage(
                f"{shown} of {len(self._paths)} match “{text}”")
        elif self._folder:
            self.status.showMessage(f"{len(self._paths)} photo(s) in {self._folder}")

    def _refresh_date_filter(self):
        months = sorted({datetime.datetime.fromtimestamp(self._fallback_stamp(path)).strftime("%Y-%m")
                         for path in self._paths}, reverse=True)
        self.date_filter.blockSignals(True)
        self.date_filter.clear(); self.date_filter.addItem("All dates", "")
        for month in months:
            self.date_filter.addItem(month, month)
        self.date_filter.blockSignals(False)

    def _reset_filters(self):
        self.search.clear(); self.date_filter.setCurrentIndex(0)
        self.meta_filter.setCurrentIndex(0); self.tag_filter.clear()
        self._apply_filter("")

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
        if path not in self._online_only:
            try:
                with Image.open(path) as probe:   # header read only, no full decode
                    dimensions = f"{probe.width}×{probe.height}  •  "
            except Exception:  # noqa: BLE001
                dimensions = ""
        self.status.showMessage(f"{name}  •  {dimensions}{size}")
        self._update_info_panel(path, dimensions, size)

    def _selected_paths(self):
        return [item.data(Qt.UserRole) for item in self.list.selectedItems()
                if item.data(Qt.UserRole)]

    def _update_info_panel(self, path, dimensions="", size=""):
        details = self._state.photo(path)
        self._updating_details = True
        self.favorite_check.setChecked(details["favorite"])
        self.rating_spin.setValue(details["rating"])
        self.tags_edit.setText(details["tags"])
        self._updating_details = False
        lines = [f"{dimensions}{size}", path]
        if path in self._online_only:
            self.info_preview.setPixmap(QPixmap())
            self.info_preview.setText("Online-only photo\nNot downloaded automatically")
        else:
            pixmap = QPixmap(path)
            if not pixmap.isNull():
                self.info_preview.setPixmap(pixmap.scaled(260, 220, Qt.KeepAspectRatio, Qt.SmoothTransformation))
            try:
                with Image.open(path) as image:
                    exif = image.getexif()
                camera = " ".join(filter(None, (str(exif.get(271, "")).strip(), str(exif.get(272, "")).strip())))
                taken = str(exif.get(36867, exif.get(306, ""))).strip()
                if camera: lines.append("Camera: " + camera)
                if taken: lines.append("Taken: " + taken)
            except Exception:
                pass
        self.info_text.setText("\n".join(line for line in lines if line))

    def _save_selected_details(self, *_args):
        if self._updating_details:
            return
        paths = self._selected_paths()
        if len(paths) != 1:
            return
        self._state.set_photo(paths[0], favorite=self.favorite_check.isChecked(),
                              rating=self.rating_spin.value(), tags=self.tags_edit.text())
        self._metadata_changed()

    def _metadata_changed(self):
        selected = set(self._selected_paths())
        self._populate()
        for path in selected:
            item = self._items.get(path)
            if item is not None:
                item.setSelected(True)

    def _remove_saved_folder(self):
        data = self.source_combo.currentData()
        if not data or data[0] != "folder":
            QMessageBox.information(self, "Remove folder", "Choose a saved Folder source first.")
            return
        self._state.save_folders([path for path in self._state.folders() if path != data[1]])
        self._refresh_source_picker()

    def _import_photos(self):
        paths, _ = QFileDialog.getOpenFileNames(self, "Choose photos to import", "", "Photos (*.jpg *.jpeg *.png *.webp *.gif *.bmp *.tif *.tiff *.dng *.nef *.cr2 *.cr3 *.arw *.orf *.rw2 *.raf);;All files (*)")
        if not paths: return
        destination = QFileDialog.getExistingDirectory(self, "Import into folder")
        if not destination: return
        try:
            outputs = photo_manager.copy_files(paths, destination)
            QMessageBox.information(self, "Import complete", f"Imported {len(outputs)} photo(s).")
            self._reload_current()
        except Exception as exc: QMessageBox.warning(self, "Import failed", str(exc))

    def _add_to_album(self):
        paths = self._selected_paths()
        if not paths: return
        name, ok = QInputDialog.getText(self, "Album", "Album name:")
        if ok and name.strip():
            self._state.add_album(name.strip(), paths); self._refresh_source_picker()

    def _bulk_tags(self):
        paths = self._selected_paths()
        value, ok = QInputDialog.getText(self, "Bulk tags", "Comma-separated tags to add:")
        if not paths or not ok: return
        additions = [tag.strip() for tag in value.split(",") if tag.strip()]
        def update(meta):
            existing = [tag.strip() for tag in meta["tags"].split(",") if tag.strip()]
            meta["tags"] = ", ".join(dict.fromkeys(existing + additions))
        self._state.update_many(paths, update); self._metadata_changed()

    def _remove_tag(self):
        paths = self._selected_paths()
        value, ok = QInputDialog.getText(self, "Remove tag", "Exact tag to remove:")
        if not paths or not ok or not value.strip(): return
        wanted = value.strip().lower()
        self._state.update_many(paths, lambda meta: meta.update(tags=", ".join(
            tag.strip() for tag in meta["tags"].split(",") if tag.strip().lower() != wanted)))
        self._metadata_changed()

    def _bulk_rating(self):
        paths = self._selected_paths()
        value, ok = QInputDialog.getInt(self, "Bulk rating", "Rating from 0 to 5:", 0, 0, 5)
        if paths and ok:
            self._state.update_many(paths, lambda meta: meta.update(rating=value))
            self._metadata_changed()

    def _bulk_favorite(self):
        self._state.update_many(self._selected_paths(), lambda meta: meta.update(favorite=not meta["favorite"]))
        self._metadata_changed()

    def _transfer_selection(self, move):
        paths = self._selected_paths()
        from . import prefs
        destination = QFileDialog.getExistingDirectory(
            self, "Move into folder" if move else "Copy into folder",
            str(prefs.load().get("projects_folder", "") or ""))
        if not paths or not destination: return
        try:
            outputs = (photo_manager.move_files if move else photo_manager.copy_files)(paths, destination)
            self._state.remap(paths, outputs, remove_old=move); self._reload_current()
        except Exception as exc: QMessageBox.warning(self, "File operation failed", str(exc))

    def _rename_selected(self):
        paths = self._selected_paths()
        if len(paths) != 1:
            QMessageBox.information(self, "Rename", "Select exactly one photo to rename."); return
        source = paths[0]
        name, ok = QInputDialog.getText(self, "Rename photo", "New filename:", text=os.path.basename(source))
        if not ok or not name.strip(): return
        name = os.path.basename(name.strip())
        if not os.path.splitext(name)[1]: name += os.path.splitext(source)[1]
        target = os.path.join(os.path.dirname(source), name)
        if os.path.exists(target): QMessageBox.warning(self, "Rename failed", "A file with that name exists."); return
        try:
            os.replace(source, target); self._state.remap([source], [target], remove_old=True); self._reload_current()
        except Exception as exc: QMessageBox.warning(self, "Rename failed", str(exc))

    def _export_selection(self):
        paths = self._selected_paths()
        from . import prefs
        settings = prefs.load()
        destination = QFileDialog.getExistingDirectory(
            self, "Export into folder", str(settings.get("exports_folder", "") or ""))
        if not paths or not destination: return
        size, ok = QInputDialog.getInt(self, "Export size", "Maximum width/height (0 = full size):", 2048, 0, 30000)
        if not ok: return
        quality, ok = QInputDialog.getInt(self, "JPEG quality", "Quality from 40 to 100:", 90, 40, 100)
        if not ok: return
        sharpen = QMessageBox.question(self, "Export sharpening", "Apply gentle output sharpening?") == QMessageBox.Yes
        try:
            copyright_text = (settings.get("copyright_text", "")
                              if settings.get("add_copyright_if_missing", True) else "")
            outputs = photo_manager.export_files(paths, destination, size, quality, sharpen,
                copyright_text, bool(settings.get("strip_gps", False)))
            QMessageBox.information(self, "Export complete", f"Exported {len(outputs)} photo(s).")
        except Exception as exc: QMessageBox.warning(self, "Export failed", str(exc))

    def _choose_exports_folder(self):
        self._choose_preference_folder("exports_folder", "Choose saved images folder")

    def _choose_projects_folder(self):
        self._choose_preference_folder("projects_folder", "Choose project folder")

    def _choose_preference_folder(self, key, title):
        from . import prefs
        settings = prefs.load()
        folder = QFileDialog.getExistingDirectory(
            self, title, str(settings.get(key, "") or ""))
        if folder:
            settings[key] = os.path.abspath(folder)
            prefs.save(settings)

    def _rotate_selection(self, degrees):
        paths = self._selected_paths()
        if not paths: return
        if QMessageBox.question(self, "Create rotated copies?", "Create rotated copies? Originals remain unchanged.") != QMessageBox.Yes: return
        try: photo_manager.rotate_files(paths, degrees); self._reload_current()
        except Exception as exc: QMessageBox.warning(self, "Rotation failed", str(exc))

    def _trash_selection(self):
        paths = self._selected_paths()
        if not paths or QMessageBox.question(self, "SNAP SLAPPER Trash", "Move selection to recoverable SNAP SLAPPER Trash?") != QMessageBox.Yes: return
        try: photo_manager.trash_files(paths, self._state.trash_root, self._state.trash_path); self._reload_current()
        except Exception as exc: QMessageBox.warning(self, "Trash failed", str(exc))

    def _restore_trash(self):
        try:
            restored = photo_manager.restore_last_trash(self._state.trash_path)
            QMessageBox.information(self, "Trash", "Restored:\n" + restored[0] if restored else "Trash is empty.")
            self._reload_current()
        except Exception as exc: QMessageBox.warning(self, "Restore failed", str(exc))

    def _find_duplicates(self):
        groups = photo_manager.duplicate_groups(self._paths)
        wanted = {path for group in groups for path in group}
        for path, item in self._items.items(): item.setSelected(path in wanted)
        QMessageBox.information(self, "Exact duplicates", f"Found {len(groups)} duplicate group(s), {len(wanted)} files selected.")

    def _find_quality_issues(self):
        results = photo_manager.quality_flags(self._paths)
        wanted = {row["path"] for row in results}
        for path, item in self._items.items(): item.setSelected(path in wanted)
        QMessageBox.information(self, "Quality suggestions", f"Flagged {len(wanted)} possibly blurry or dark photo(s). Nothing changed.")

    def _backup_organizer(self):
        path, _ = QFileDialog.getSaveFileName(self, "Back up organizer data", "snap-slapper-organizer-backup.json", "JSON (*.json)")
        if path:
            try: self._state.backup(path)
            except Exception as exc: QMessageBox.warning(self, "Backup failed", str(exc))

    def _show_photo_menu(self, position):
        item = self.list.itemAt(position)
        if item is None:
            return
        if not item.isSelected():
            self.list.setCurrentItem(item)
        menu = QMenu(self)
        menu.addAction("Edit", self._open_selected)
        menu.addAction("Open in Windows", self._open_in_windows)
        menu.addAction("Show in folder", self._show_in_folder)
        menu.addAction("Open with / edit copy…", self._open_with_menu)
        menu.addSeparator()
        paths = self._selected_paths()
        all_favorite = bool(paths) and all(self._state.photo(path)["favorite"] for path in paths)
        menu.addAction("Remove favorite" if all_favorite else "Mark as favorite",
                       lambda: (self._state.update_many(paths, lambda meta: meta.update(favorite=not all_favorite)),
                                self._metadata_changed()))
        ratings = menu.addMenu("Set rating")
        for value in range(0, 6):
            ratings.addAction("No rating" if value == 0 else "★" * value,
                              lambda checked=False, v=value: (
                                  self._state.update_many(paths, lambda meta: meta.update(rating=v)),
                                  self._metadata_changed()))
        menu.addAction("Add tags…", self._bulk_tags)
        menu.addAction("Remove tag…", self._remove_tag)
        menu.addSeparator()
        menu.addAction("Move to SNAP SLAPPER Trash…", self._trash_selection)
        menu.addAction(self._delete_action)
        menu.exec(self.list.viewport().mapToGlobal(position))

    def _show_in_folder(self):
        paths = self._selected_paths()
        if paths:
            subprocess.Popen(["explorer.exe", "/select," + os.path.normpath(paths[0])])

    def _open_in_windows(self):
        paths = self._selected_paths()
        if paths:
            os.startfile(paths[0])

    def _source_signature(self, folder):
        try:
            if self.act_subfolders.isChecked():
                rows = []
                for root, _dirs, files in os.walk(folder):
                    for name in files:
                        if os.path.splitext(name)[1].lower() in IMAGE_EXTENSIONS:
                            path = os.path.join(root, name)
                            try:
                                rows.append((os.path.relpath(path, folder), os.stat(path).st_mtime_ns))
                            except OSError:
                                pass
            else:
                rows = [(entry.name, entry.stat(follow_symlinks=False).st_mtime_ns)
                        for entry in os.scandir(folder)
                        if entry.is_file(follow_symlinks=False) and os.path.splitext(entry.name)[1].lower() in IMAGE_EXTENSIONS]
            return tuple(sorted(rows))
        except OSError:
            return None

    def _auto_refresh_tick(self):
        if not self.auto_refresh_action.isChecked() or not self._folder:
            return
        signature = self._source_signature(self._folder)
        if self._folder_signature is not None and signature != self._folder_signature:
            self.load_folder(self._folder)

    def _open_with_menu(self):
        paths = self._selected_paths()
        if len(paths) != 1:
            return
        menu = QMenu(self)
        for tool in self._state.external_tools():
            menu.addAction("Edit a copy in " + tool["name"],
                           lambda checked=False, t=tool: self._launch_external(t))
        menu.addSeparator()
        menu.addAction("Add an editor…", self._add_external_tool)
        menu.exec(self.cursor().pos())

    def _add_external_tool(self):
        path, _ = QFileDialog.getOpenFileName(self, "Choose a photo editor", "", "Applications (*.exe);;All files (*)")
        if path:
            self._state.add_external_tool(os.path.splitext(os.path.basename(path))[0], path)

    def _launch_external(self, tool):
        paths = self._selected_paths()
        if len(paths) != 1: return
        try:
            target = photo_manager.copy_for_external_edit(paths[0])
            subprocess.Popen([tool["path"], target], cwd=os.path.dirname(tool["path"]))
        except Exception as exc: QMessageBox.warning(self, "Editor failed to open", str(exc))

    def _start_slideshow(self):
        paths = self._selected_paths() or [path for path, item in self._items.items() if not item.isHidden()]
        if not paths: return
        dialog = QDialog(self)
        dialog.setWindowTitle("SNAP SLAPPER — Slideshow")
        layout = QVBoxLayout(dialog); label = QLabel(); label.setAlignment(Qt.AlignCenter); layout.addWidget(label)
        dialog.showFullScreen(); state = {"index": 0}
        def step():
            if not dialog.isVisible(): return
            path = paths[state["index"] % len(paths)]; state["index"] += 1
            pixmap = QPixmap(path)
            if not pixmap.isNull(): label.setPixmap(pixmap.scaled(dialog.size(), Qt.KeepAspectRatio, Qt.SmoothTransformation))
            QTimer.singleShot(3000, step)
        QTimer.singleShot(0, step)
        dialog.exec()

    def _delete_selected(self):
        items = self.list.selectedItems()
        if not items:
            return
        paths = [item.data(Qt.UserRole) for item in items if item.data(Qt.UserRole)]
        if not paths:
            return
        subject = os.path.basename(paths[0]) if len(paths) == 1 else f"{len(paths)} selected photos"
        answer = QMessageBox.question(
            self, "Move to Recycle Bin?",
            f"Move {subject} to the Windows Recycle Bin?\n\nThe original file will not be permanently deleted.",
            QMessageBox.Yes | QMessageBox.Cancel, QMessageBox.Cancel)
        if answer != QMessageBox.Yes:
            return
        failed = []
        removed = []
        for path in paths:
            try:
                ok = _move_to_recycle_bin(path)
            except Exception:
                ok = False
            if ok:
                removed.append(path)
            else:
                failed.append(path)
        if removed:
            removed_set = set(removed)
            self._paths = [path for path in self._paths if path not in removed_set]
            self._online_only.difference_update(removed_set)
            for path in removed:
                self._icons.pop(path, None)
                self._stamps.pop(path, None)
            self._populate()
            self.status.showMessage(f"Moved {len(removed)} photo(s) to the Recycle Bin")
        if failed:
            QMessageBox.warning(self, "Could not move file",
                "Windows could not move the following to the Recycle Bin:\n\n" +
                "\n".join(os.path.basename(path) for path in failed))

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
        if os.path.splitext(path)[1].lower() in RAW_EXTENSIONS:
            self._open_with_menu()
            return
        editor = EditorWindow()
        editor.open_path(path)
        editor.show()
        self._editors.append(editor)

# ===== SNAPSMACK EOF =====
