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

The library also provides practical organizer operations: drag/drop moves,
copy, rename, new folders, Recycle Bin, and Explorer access.
"""

import os
import shutil
import time
import photo_manager
import editor_engine

from PySide6.QtCore import (
    Qt, QObject, QRunnable, QThreadPool, Signal, QSize, QDir, QTimer,
    QStandardPaths, QMimeData, QUrl, QFile,
)
from PySide6.QtGui import (
    QImage, QPixmap, QIcon, QAction, QKeySequence, QDrag, QDesktopServices,
    QPainter,
)
from PySide6.QtWidgets import (
    QMainWindow, QListWidget, QListWidgetItem, QFileDialog, QLabel, QSlider,
    QWidget, QHBoxLayout, QComboBox, QLineEdit, QTreeView, QSplitter,
    QFileSystemModel, QAbstractItemView, QInputDialog, QMessageBox, QMenu,
    QToolButton, QDockWidget, QVBoxLayout, QPushButton, QCheckBox, QDialog,
)
from PySide6.QtPrintSupport import QPrinter, QPrintDialog

from PIL import Image, ImageOps

from . import theme
from . import BUILD_VERSION
from .editor_window import EditorWindow
from .catalog import Catalog
from .organizer_ops import import_photos, batch_rename
from .output_tools import create_contact_sheet, SlideshowDialog

try:
    import snap_log
    _log = snap_log.get("snap_slapper")
except Exception:  # noqa: BLE001
    import logging
    _log = logging.getLogger("snapsmack.snap_slapper")

IMAGE_EXTENSIONS = ({".jpg", ".jpeg", ".png", ".tif", ".tiff", ".webp", ".bmp", ".gif"}
                    | set(photo_manager.RAW_EXTENSIONS))
THUMB_SOURCE = 256   # thumbnails are generated at this size, displayed smaller

SORTS = [
    ("name", "Name"),
    ("date_new", "Date (newest)"),
    ("date_old", "Date (oldest)"),
]


def _transfer_photo_files(paths, destination, copy_files=False):
    """Copy/move photos without overwriting anything. Returns (done, errors)."""
    destination = os.path.abspath(destination)
    done, errors = [], []
    if not os.path.isdir(destination):
        return done, [f"Folder does not exist: {destination}"]
    for source in paths:
        source = os.path.abspath(source)
        if not os.path.isfile(source):
            errors.append(f"Not a file: {source}")
            continue
        target = os.path.join(destination, os.path.basename(source))
        if os.path.normcase(source) == os.path.normcase(target):
            continue
        if os.path.exists(target):
            errors.append(f"Already exists: {target}")
            continue
        try:
            if copy_files:
                shutil.copy2(source, target)
            else:
                shutil.move(source, target)
            done.append(target)
        except OSError as exc:
            errors.append(f"{os.path.basename(source)}: {exc}")
    return done, errors


class _PhotoList(QListWidget):
    """Thumbnail grid that exports selected photos as ordinary file drags."""

    def startDrag(self, supported_actions):  # noqa: N802 — Qt override
        paths = [item.data(Qt.UserRole) for item in self.selectedItems()]
        paths = [path for path in paths if path and os.path.isfile(path)]
        if not paths:
            return
        mime = QMimeData()
        mime.setUrls([QUrl.fromLocalFile(path) for path in paths])
        drag = QDrag(self)
        drag.setMimeData(mime)
        result = drag.exec(Qt.CopyAction | Qt.MoveAction, Qt.MoveAction)
        if result == Qt.MoveAction:
            window = self.window()
            if hasattr(window, "_reload_current"):
                window._reload_current()


class _FolderTree(QTreeView):
    """Folder tree drop target for photo moves and copies."""

    filesDropped = Signal(object, str, bool)  # paths, destination, copy

    def dragEnterEvent(self, event):  # noqa: N802 — Qt override
        if event.mimeData().hasUrls():
            event.acceptProposedAction()
        else:
            event.ignore()

    def dragMoveEvent(self, event):  # noqa: N802 — Qt override
        if event.mimeData().hasUrls():
            event.acceptProposedAction()
        else:
            event.ignore()

    def dropEvent(self, event):  # noqa: N802 — Qt override
        index = self.indexAt(event.position().toPoint())
        model = self.model()
        destination = model.filePath(index) if index.isValid() else model.rootPath()
        if not destination or not os.path.isdir(destination):
            event.ignore()
            return
        paths = [url.toLocalFile() for url in event.mimeData().urls()
                 if url.isLocalFile()]
        paths = [path for path in paths
                 if os.path.splitext(path)[1].lower() in IMAGE_EXTENSIONS]
        if not paths:
            event.ignore()
            return
        copy_files = bool(event.keyboardModifiers() & Qt.ControlModifier) or \
            event.proposedAction() == Qt.CopyAction
        self.filesDropped.emit(paths, destination, copy_files)
        # We perform the operation ourselves. Always report CopyAction to the
        # drag source so Windows Explorer does not also delete/move the source.
        event.setDropAction(Qt.CopyAction)
        event.accept()


class _FileSignals(QObject):
    finished = Signal(object, object, str, bool, object)


class _FileTask(QRunnable):
    """Run potentially slow cross-drive/network file work off the GUI thread."""

    def __init__(self, paths, destination, copy_files, signals):
        super().__init__()
        self.paths = list(paths)
        self.destination = destination
        self.copy_files = copy_files
        self.signals = signals

    def run(self):
        done, errors = _transfer_photo_files(
            self.paths, self.destination, self.copy_files)
        self.signals.finished.emit(
            done, errors, self.destination, self.copy_files, self.paths)

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
        self.setWindowTitle(f"SNAP SLAPPER — Library (build {BUILD_VERSION})")
        self.resize(1180, 780)
        self._pool = QThreadPool.globalInstance()
        self._scan_pool = QThreadPool(self)
        self._scan_pool.setMaxThreadCount(1)
        self._items = {}          # path -> QListWidgetItem
        self._icons = {}          # path -> QIcon (cached so re-sort never re-decodes)
        self._stamps = {}         # path -> capture timestamp (float)
        self._paths = []          # all photo paths in the current folder
        self._folder = None
        self._virtual_source = None
        self._scan_generation = 0
        self._scan_token = None
        self._populate_generation = 0
        self._editors = []        # keep editor windows alive
        self._opening_editor_paths = set()  # suppress re-entrant activation while loading
        self._signals = _ThumbSignals()
        self._signals.ready.connect(self._on_thumb)
        self._scan_signals = _ScanSignals()
        self._scan_signals.ready.connect(self._on_scan_ready)
        self._file_signals = _FileSignals()
        self._file_signals.finished.connect(self._on_transfer_finished)
        self.catalog = Catalog()

        from . import prefs
        settings = prefs.load()
        self._restore_maximized = bool(settings.get("library_maximized", True))
        self._window_state_restored = False
        self._sort = settings.get("library_sort", "name")
        folders_visible = bool(settings.get("library_folders_visible", True))
        self._folder_font_size = int(settings.get("library_folder_font_size", 11))
        self._splitter_sizes = settings.get("library_splitter_sizes", [260, 920])

        self._build_toolbar()
        self.act_subfolders.blockSignals(True)
        self.act_subfolders.setChecked(
            bool(settings.get("library_include_subfolders", False)))
        self.act_subfolders.blockSignals(False)
        self._update_subfolder_action()

        # Left: folder tree. Right: thumbnail grid. Split so the tree slides
        # in and out without disturbing the grid.
        self.tree_model = QFileSystemModel(self)
        self.tree_model.setFilter(QDir.Dirs | QDir.Drives | QDir.NoDotAndDotDot)
        self.tree = _FolderTree()
        self.tree.setModel(self.tree_model)
        # Never root this model at "" on Windows. That asks the shell to probe
        # every mapped/cloud/network drive; one stale share marks the entire
        # application Not Responding. Start from a known local directory and
        # re-root only when the user explicitly chooses another folder.
        initial_root = QStandardPaths.writableLocation(
            QStandardPaths.PicturesLocation)
        if not initial_root or not os.path.isdir(initial_root):
            initial_root = QDir.homePath()
        initial_index = self.tree_model.setRootPath(initial_root)
        self.tree.setRootIndex(initial_index)
        self.tree.setHeaderHidden(True)
        self.tree.setAcceptDrops(True)
        self.tree.setDropIndicatorShown(True)
        self.tree.setContextMenuPolicy(Qt.CustomContextMenu)
        self.tree.setToolTip(
            "Drop photos here to move them. Hold Ctrl while dropping to copy.")
        for column in range(1, self.tree_model.columnCount()):
            self.tree.hideColumn(column)
        self.tree.setMinimumWidth(180)
        tree_font = self.tree.font()
        tree_font.setPointSize(max(9, min(20, self._folder_font_size)))
        self.tree.setFont(tree_font)
        self.tree.clicked.connect(self._tree_clicked)
        self.tree.filesDropped.connect(self._drop_on_folder)
        self.tree.customContextMenuRequested.connect(self._folder_context_menu)

        self.list = _PhotoList()
        self.list.setViewMode(QListWidget.IconMode)
        self.list.setResizeMode(QListWidget.Adjust)
        self.list.setMovement(QListWidget.Static)
        self.list.setSelectionMode(QAbstractItemView.ExtendedSelection)
        self.list.setDragEnabled(True)
        self.list.setDragDropMode(QAbstractItemView.DragOnly)
        self.list.setContextMenuPolicy(Qt.CustomContextMenu)
        self.list.setSpacing(10)
        self.list.setUniformItemSizes(True)
        self._set_thumbnail_size(160)
        self.list.setWordWrap(True)
        grid_font = self.list.font()   # filenames under each thumb were too small
        grid_font.setPointSize(max(grid_font.pointSize() + 2, 11))
        self.list.setFont(grid_font)
        # itemActivated already represents double-click/Enter on desktop Qt.
        # Connecting itemDoubleClicked as well opens the same photo twice.
        self.list.itemActivated.connect(self._open_item)
        self.list.itemClicked.connect(self._show_info)
        self.list.currentItemChanged.connect(
            lambda cur, _prev: self._show_info(cur))
        self.list.itemSelectionChanged.connect(self._selection_changed)
        self.list.customContextMenuRequested.connect(self._photo_context_menu)

        self.split = QSplitter(Qt.Horizontal)
        self.split.addWidget(self.tree)
        self.split.addWidget(self.list)
        self.split.setStretchFactor(0, 0)
        self.split.setStretchFactor(1, 1)
        self.split.setHandleWidth(9)
        if isinstance(self._splitter_sizes, list) and len(self._splitter_sizes) == 2:
            self.split.setSizes([int(v) for v in self._splitter_sizes])
        else:
            self.split.setSizes([260, 920])
        self.split.splitterMoved.connect(self._splitter_moved)
        self.setCentralWidget(self.split)
        self._build_catalog_dock()

        self.tree.setVisible(folders_visible)
        self.act_folders.setChecked(folders_visible)

        self.status = self.statusBar()
        self.status.showMessage("Choose a folder to browse your photographs.")
        remembered = str(settings.get("library_folder", "") or "")
        starting_folder = remembered if os.path.isdir(remembered) else initial_root
        if starting_folder and os.path.isdir(starting_folder):
            self.load_folder(starting_folder)

    # --- Toolbar ------------------------------------------------------------
    def _build_toolbar(self):
        bar = self.addToolBar("Organize")
        bar.setMovable(False)
        self.addToolBarBreak(Qt.TopToolBarArea)
        view_bar = self.addToolBar("Browse and View")
        view_bar.setMovable(False)

        self.act_open = QAction("Choose Folder", self)
        self.act_open.triggered.connect(self.choose_folder)
        bar.addAction(self.act_open)

        self.act_folder_up = QAction("Up One Folder", self)
        self.act_folder_up.setShortcut(QKeySequence("Alt+Up"))
        self.act_folder_up.setToolTip("Open the parent folder (Alt+Up)")
        self.act_folder_up.triggered.connect(self._go_up_folder)
        bar.addAction(self.act_folder_up)

        self.act_folders = QAction("Folders", self)
        self.act_folders.setCheckable(True)
        self.act_folders.setToolTip("Show or hide the folder tree")
        self.act_folders.toggled.connect(self._toggle_folders)
        view_bar.addAction(self.act_folders)

        self.act_subfolders = QAction("Subfolders: OFF", self)
        self.act_subfolders.setCheckable(True)
        self.act_subfolders.setToolTip(
            "OFF — show photographs only from the selected folder")
        self.act_subfolders.toggled.connect(self._subfolders_toggled)
        view_bar.addAction(self.act_subfolders)

        view_bar.addSeparator()

        self.act_edit = QAction("Edit Selected", self)
        self.act_edit.triggered.connect(self._open_selected)
        bar.addAction(self.act_edit)

        self.act_import = QAction("Import Photos…", self)
        self.act_import.triggered.connect(self._import_photos)
        bar.addAction(self.act_import)

        self.act_panomerge = QAction("PANOMERGE…", self)
        from .panomerge import detect_xpano, platform_supported
        panomerge_available = platform_supported() and bool(detect_xpano())
        self.act_panomerge.setEnabled(panomerge_available)
        self.act_panomerge.setToolTip(
            "Stitch selected overlapping photographs into a panorama with XPANO"
            if panomerge_available else
            "Install XPANO separately, then restart SNAP SLAPPER to enable PANOMERGE")
        self.act_panomerge.triggered.connect(self._panomerge_selected)
        bar.addAction(self.act_panomerge)

        self.act_new_folder = QAction("New Folder…", self)
        self.act_new_folder.setShortcut(QKeySequence("Ctrl+Shift+N"))
        self.act_new_folder.setToolTip(
            "Create a folder inside the selected folder (Ctrl+Shift+N)")
        self.act_new_folder.triggered.connect(self._new_folder)
        bar.addAction(self.act_new_folder)
        self.act_rename = QAction("Rename…", self)
        self.act_rename.setShortcut(QKeySequence("F2"))
        self.act_rename.triggered.connect(self._rename_selected)
        self.act_batch_rename = QAction("Batch Rename…", self)
        self.act_batch_rename.triggered.connect(self._batch_rename_selected)
        self.act_move = QAction("Move to Folder…", self)
        self.act_move.triggered.connect(lambda: self._choose_transfer(False))
        self.act_copy = QAction("Copy to Folder…", self)
        self.act_copy.triggered.connect(lambda: self._choose_transfer(True))
        self.act_trash = QAction("Move to SNAP SLAPPER Trash…", self)
        self.act_trash.setShortcut(QKeySequence.Delete)
        self.act_trash.triggered.connect(self._trash_selected)
        self.act_show_folder = QAction("Show in Folder", self)
        self.act_show_folder.triggered.connect(self._show_in_folder)
        self.act_restore_trash = QAction("Restore Last Trashed Photo", self)
        self.act_restore_trash.triggered.connect(self._restore_trash)
        self.act_undo_files = QAction("Undo Last Move/Rename", self)
        self.act_undo_files.setShortcut(QKeySequence("Ctrl+Alt+Z"))
        self.act_undo_files.triggered.connect(self._undo_file_operation)
        self.act_rotate_left = QAction("Create Rotated Copies — Left", self)
        self.act_rotate_left.triggered.connect(lambda: self._rotate_selected(90))
        self.act_rotate_right = QAction("Create Rotated Copies — Right", self)
        self.act_rotate_right.triggered.connect(lambda: self._rotate_selected(-90))
        self.act_find_duplicates = QAction("Find Exact Duplicates", self)
        self.act_find_duplicates.triggered.connect(self._find_duplicates)
        self.act_export_selection = QAction("Batch Export…", self)
        self.act_export_selection.triggered.connect(self._export_selected)
        self.act_apply_recipe = QAction("Apply Recipe to Selection…", self)
        self.act_apply_recipe.triggered.connect(self._apply_recipe_selected)
        self.act_slideshow = QAction("Slideshow", self)
        self.act_slideshow.setShortcut(QKeySequence("F5"))
        self.act_slideshow.triggered.connect(self._start_slideshow)
        self.act_contact_sheet = QAction("Create Contact Sheet…", self)
        self.act_contact_sheet.triggered.connect(self._create_contact_sheet)
        self.act_print = QAction("Print Selected…", self)
        self.act_print.setShortcut(QKeySequence.Print)
        self.act_print.triggered.connect(self._print_selected)

        organize_menu = QMenu(self)
        for action in (self.act_new_folder, self.act_rename,
                       self.act_batch_rename, self.act_move, self.act_copy, self.act_trash,
                       self.act_restore_trash, self.act_rotate_left,
                       self.act_undo_files,
                       self.act_rotate_right, self.act_find_duplicates,
                       self.act_export_selection, self.act_apply_recipe,
                       self.act_show_folder):
            organize_menu.addAction(action)
        organize_button = QToolButton()
        organize_button.setText("Organize")
        organize_button.setPopupMode(QToolButton.InstantPopup)
        organize_button.setMenu(organize_menu)
        organize_button.setToolTip("Rename, move, copy, delete, or create folders")
        bar.addWidget(organize_button)

        output_menu = QMenu(self)
        for action in (self.act_slideshow, self.act_contact_sheet, self.act_print):
            output_menu.addAction(action)
        output_button = QToolButton()
        output_button.setText("Present / Print")
        output_button.setPopupMode(QToolButton.InstantPopup)
        output_button.setMenu(output_menu)
        output_button.setToolTip("Slideshow, contact sheet, or print selected photos")
        bar.addWidget(output_button)

        # Standard menus make the same operations discoverable without knowing
        # that a thumbnail or folder can be right-clicked.
        file_menu = self.menuBar().addMenu("File")
        file_menu.addAction(self.act_open)
        file_menu.addAction(self.act_edit)
        file_menu.addAction(self.act_panomerge)
        file_menu.addAction(self.act_print)
        file_menu.addAction(self.act_show_folder)
        organize_bar_menu = self.menuBar().addMenu("Organize")
        for action in (self.act_import, self.act_new_folder, self.act_rename,
                       self.act_batch_rename,
                       self.act_move, self.act_copy, self.act_trash,
                       self.act_restore_trash, self.act_rotate_left,
                       self.act_undo_files,
                       self.act_rotate_right, self.act_find_duplicates,
                       self.act_export_selection, self.act_apply_recipe):
            organize_bar_menu.addAction(action)
        output_bar_menu = self.menuBar().addMenu("Present")
        output_bar_menu.addAction(self.act_slideshow)
        output_bar_menu.addAction(self.act_contact_sheet)
        output_bar_menu.addAction(self.act_print)

        act_help = QAction("Help", self)
        act_help.setShortcut(QKeySequence.HelpContents)   # F1
        act_help.triggered.connect(self._open_help)
        bar.addAction(act_help)

        view_bar.addSeparator()

        # Sort control
        source_label = QLabel("Source")
        source_label.setObjectName("ControlName")
        view_bar.addWidget(source_label)
        self.source_combo = QComboBox()
        self.source_combo.setMinimumWidth(150)
        self.source_combo.currentIndexChanged.connect(self._source_changed)
        view_bar.addWidget(self.source_combo)
        self._refresh_catalog_sources()

        sort_label = QLabel("Sort")
        sort_label.setObjectName("ControlName")
        view_bar.addWidget(sort_label)
        self.sort_combo = QComboBox()
        for key, name in SORTS:
            self.sort_combo.addItem(name, key)
        index = self.sort_combo.findData(self._sort)
        self.sort_combo.setCurrentIndex(index if index >= 0 else 0)
        self.sort_combo.currentIndexChanged.connect(self._sort_changed)
        view_bar.addWidget(self.sort_combo)

        # Search box
        self.search = QLineEdit()
        self.search.setPlaceholderText("Search filenames…")
        self.search.setClearButtonEnabled(True)
        self.search.setFixedWidth(200)
        self.search.textChanged.connect(self._apply_filter)
        view_bar.addWidget(self.search)

        self.catalog_filter = QComboBox()
        for label, value in (
                ("All photos", "all"), ("Favorites", "favorites"),
                ("Rated", "rated"), ("Unrated", "unrated"),
                ("5 stars", "5"), ("4+ stars", "4+"),
                ("3+ stars", "3+")):
            self.catalog_filter.addItem(label, value)
        self.catalog_filter.setToolTip("Filter by catalogue rating or favorite")
        self.catalog_filter.currentIndexChanged.connect(
            lambda _index: self._apply_filter(self.search.text()))
        view_bar.addWidget(self.catalog_filter)

        # thumbnail size control on the right
        spacer = QWidget()
        spacer.setSizePolicy(spacer.sizePolicy().horizontalPolicy().Expanding,
                             spacer.sizePolicy().verticalPolicy().Preferred)
        view_bar.addWidget(spacer)
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
        self.size_slider.valueChanged.connect(self._set_thumbnail_size)
        size_layout.addWidget(self.size_slider)
        view_bar.addWidget(size_wrap)

        folder_size_wrap = QWidget()
        folder_size_layout = QHBoxLayout(folder_size_wrap)
        folder_size_layout.setContentsMargins(0, 0, 8, 0)
        folder_size_layout.setSpacing(5)
        folder_size_label = QLabel("Folder text")
        folder_size_label.setObjectName("ControlName")
        folder_size_layout.addWidget(folder_size_label)
        self.folder_size_slider = QSlider(Qt.Horizontal)
        self.folder_size_slider.setRange(9, 20)
        self.folder_size_slider.setValue(self._folder_font_size)
        self.folder_size_slider.setFixedWidth(80)
        self.folder_size_slider.setToolTip("Make folder names smaller or larger")
        self.folder_size_slider.valueChanged.connect(self._folder_font_changed)
        folder_size_layout.addWidget(self.folder_size_slider)
        view_bar.addWidget(folder_size_wrap)

    def _set_thumbnail_size(self, size):
        """Reserve a real caption row even when the image is portrait/square."""
        size = int(size)
        self.list.setIconSize(QSize(size, size))
        self.list.setGridSize(QSize(size + 28, size + 76))

    def _build_catalog_dock(self):
        dock = QDockWidget("PHOTO INFO", self)
        dock.setObjectName("CatalogInfo")
        dock.setMinimumWidth(230)
        panel = QWidget()
        layout = QVBoxLayout(panel)
        self.info_name = QLabel("Select a photo")
        self.info_name.setWordWrap(True)
        layout.addWidget(self.info_name)
        self.favorite_check = QCheckBox("♥ Favorite")
        layout.addWidget(self.favorite_check)
        rating_label = QLabel("Rating")
        rating_label.setObjectName("ControlName")
        layout.addWidget(rating_label)
        self.rating_combo = QComboBox()
        self.rating_combo.addItem("No rating", 0)
        for value in range(1, 6):
            self.rating_combo.addItem("★" * value, value)
        layout.addWidget(self.rating_combo)
        tags_label = QLabel("Tags — comma separated")
        tags_label.setObjectName("ControlName")
        layout.addWidget(tags_label)
        self.tags_edit = QLineEdit()
        self.tags_edit.setPlaceholderText("family, mountains, 2026")
        layout.addWidget(self.tags_edit)
        save = QPushButton("Save Details")
        save.clicked.connect(self._save_selected_details)
        layout.addWidget(save)
        album = QPushButton("Add to Album…")
        album.clicked.connect(self._add_selected_to_album)
        layout.addWidget(album)
        layout.addStretch(1)
        dock.setWidget(panel)
        self.addDockWidget(Qt.RightDockWidgetArea, dock)
        self.catalog_dock = dock

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

    def _selected_photo_paths(self):
        return [item.data(Qt.UserRole) for item in self.list.selectedItems()
                if item.data(Qt.UserRole)]

    def _selection_changed(self):
        paths = self._selected_photo_paths()
        if not paths:
            self.info_name.setText("Select a photo")
            return
        if len(paths) > 1:
            self.info_name.setText(f"{len(paths)} photos selected")
            self.favorite_check.setChecked(False)
            self.rating_combo.setCurrentIndex(0)
            self.tags_edit.clear()
            return
        path = paths[0]
        details = self.catalog.details(path)
        self.info_name.setText(os.path.basename(path))
        self.favorite_check.setChecked(details["favorite"])
        self.rating_combo.setCurrentIndex(
            self.rating_combo.findData(details["rating"]))
        self.tags_edit.setText(details["tags"])

    def _save_selected_details(self):
        paths = self._selected_photo_paths()
        if not paths:
            QMessageBox.information(self, "Photo Info", "Select one or more photos first.")
            return
        self.catalog.set_details(
            paths, favorite=self.favorite_check.isChecked(),
            rating=self.rating_combo.currentData() or 0,
            replace_tags=self.tags_edit.text())
        self._populate()
        self.status.showMessage(f"Saved details for {len(paths)} photo(s)", 5000)

    def _add_selected_to_album(self):
        paths = self._selected_photo_paths()
        if not paths:
            QMessageBox.information(self, "Albums", "Select one or more photos first.")
            return
        existing = sorted(self.catalog.albums, key=str.lower)
        prompt = "Album name:"
        if existing:
            prompt += "\n\nExisting: " + ", ".join(existing)
        name, accepted = QInputDialog.getText(self, "Add to Album", prompt)
        if accepted and name.strip():
            try:
                self.catalog.add_to_album(name, paths)
            except (OSError, ValueError) as exc:
                QMessageBox.warning(self, "Could not save album", str(exc))
                return
            self.status.showMessage(
                f"Added {len(paths)} photo(s) to {name.strip()}", 5000)
            self._refresh_catalog_sources(select_album=name.strip())

    def _refresh_catalog_sources(self, select_album=None):
        if not hasattr(self, "source_combo"):
            return
        current = self.source_combo.currentData()
        self.source_combo.blockSignals(True)
        self.source_combo.clear()
        self.source_combo.addItem("Current Folder", ("folder", ""))
        self.source_combo.addItem(
            f"All Catalog Photos ({len(self.catalog.all_paths())})",
            ("catalog", ""))
        for name in sorted(self.catalog.albums, key=str.lower):
            self.source_combo.addItem(
                f"Album: {name} ({len(self.catalog.albums[name])})",
                ("album", name))
        wanted = ("album", select_album) if select_album else current
        index = self.source_combo.findData(wanted)
        self.source_combo.setCurrentIndex(index if index >= 0 else 0)
        self.source_combo.blockSignals(False)

    def _source_changed(self, _index):
        source = self.source_combo.currentData()
        if not source or source[0] == "folder":
            self._virtual_source = None
            self._reload_current()
            return
        kind, name = source
        paths = self.catalog.all_paths() if kind == "catalog" else \
            [path for path in self.catalog.albums.get(name, [])
             if os.path.isfile(path)]
        self._virtual_source = source
        self._folder = None
        self._scan_generation += 1
        if self._scan_token is not None:
            self._scan_token.cancelled = True
        self._paths = paths
        self._icons.clear()
        self._stamps.clear()
        title = "All Catalog Photos" if kind == "catalog" else f"Album — {name}"
        self.setWindowTitle(f"SNAP SLAPPER — {title} (build {BUILD_VERSION})")
        self._populate()

    def _selected_tree_folder(self):
        index = self.tree.currentIndex()
        path = self.tree_model.filePath(index) if index.isValid() else ""
        return path if path and os.path.isdir(path) else self._folder

    def _splitter_moved(self, _position, _index):
        from . import prefs
        values = prefs.load()
        values["library_splitter_sizes"] = self.split.sizes()
        prefs.save(values)

    def _folder_font_changed(self, size):
        font = self.tree.font()
        font.setPointSize(int(size))
        self.tree.setFont(font)
        from . import prefs
        values = prefs.load()
        values["library_folder_font_size"] = int(size)
        prefs.save(values)

    def _photo_context_menu(self, position):
        item = self.list.itemAt(position)
        if item is None:
            return
        if item is not None and not item.isSelected():
            self.list.clearSelection()
            item.setSelected(True)
        menu = QMenu(self)
        for action in (self.act_edit, self.act_panomerge, self.act_rename, self.act_move,
                       self.act_copy, self.act_trash, self.act_show_folder):
            menu.addAction(action)
        menu.addSeparator()
        favorite = menu.addAction("Toggle Favorite")
        rating_menu = menu.addMenu("Set Rating")
        rating_actions = {}
        for value in range(0, 6):
            label = "No rating" if value == 0 else ("★" * value)
            rating_actions[rating_menu.addAction(label)] = value
        add_tags = menu.addAction("Add Tags…")
        add_album = menu.addAction("Add to Album…")
        chosen = menu.exec(self.list.mapToGlobal(position))
        paths = self._selected_photo_paths()
        if chosen == favorite:
            make_favorite = not all(
                self.catalog.details(path)["favorite"] for path in paths)
            self.catalog.set_details(paths, favorite=make_favorite)
            self._populate()
        elif chosen in rating_actions:
            self.catalog.set_details(paths, rating=rating_actions[chosen])
            self._populate()
        elif chosen == add_tags:
            tags, accepted = QInputDialog.getText(
                self, "Add Tags", "Comma-separated tags to add:")
            if accepted and tags.strip():
                self.catalog.set_details(paths, add_tags=tags)
                self._populate()
        elif chosen == add_album:
            self._add_selected_to_album()

    def _folder_context_menu(self, position):
        index = self.tree.indexAt(position)
        if index.isValid():
            self.tree.setCurrentIndex(index)
        menu = QMenu(self)
        new_action = menu.addAction("New Folder Here…")
        rename_action = menu.addAction("Rename Folder…")
        menu.addSeparator()
        show_action = menu.addAction("Open in File Explorer")
        chosen = menu.exec(self.tree.mapToGlobal(position))
        if chosen == new_action:
            self._new_folder()
        elif chosen == rename_action:
            self._rename_folder()
        elif chosen == show_action:
            folder = self._selected_tree_folder()
            if folder:
                QDesktopServices.openUrl(QUrl.fromLocalFile(folder))

    def _new_folder(self):
        parent = self._selected_tree_folder()
        if not parent:
            QMessageBox.information(self, "New Folder", "Choose a folder first.")
            return
        name, accepted = QInputDialog.getText(
            self, "New Folder", f"Create a folder inside:\n{parent}\n\nFolder name:")
        name = name.strip()
        if not accepted or not name:
            return
        if name in (".", "..") or os.path.basename(name) != name:
            QMessageBox.warning(self, "New Folder", "Enter a single folder name.")
            return
        target = os.path.join(parent, name)
        try:
            os.mkdir(target)
        except OSError as exc:
            QMessageBox.warning(self, "Could not create folder", str(exc))
            return
        self.status.showMessage(f"Created folder: {target}", 5000)

    def _rename_selected(self):
        photos = self._selected_photo_paths()
        if photos:
            if len(photos) != 1:
                QMessageBox.information(
                    self, "Rename", "Select one photo to rename at a time.")
                return
            self._rename_path(photos[0], folder=False)
        else:
            self._rename_folder()

    def _rename_folder(self):
        folder = self._selected_tree_folder()
        root = self.tree_model.rootPath()
        if not folder or os.path.normcase(folder) == os.path.normcase(root):
            QMessageBox.information(
                self, "Rename Folder", "Select a folder below the library root.")
            return
        self._rename_path(folder, folder=True)

    def _batch_rename_selected(self):
        paths = self._selected_photo_paths()
        if not paths:
            QMessageBox.information(self, "Batch Rename", "Select photos first.")
            return
        pattern, accepted = QInputDialog.getText(
            self, "Batch Rename",
            "Filename pattern:\n\n{name} original name  ·  {n} number  ·  {date} capture date",
            text="{date}_{n}")
        if not accepted or not pattern.strip():
            return
        try:
            changes = batch_rename(paths, pattern.strip())
            for source, target in changes:
                self.catalog.move_path(source, target)
            self.catalog.record_operation("rename", changes)
        except (OSError, ValueError) as exc:
            QMessageBox.warning(self, "Batch rename failed", str(exc))
            return
        self.status.showMessage(f"Renamed {len(changes)} photo(s)", 6000)
        self._reload_current()

    def _rename_path(self, path, folder=False):
        old_name = os.path.basename(path)
        stem, extension = os.path.splitext(old_name)
        initial = old_name if folder else stem
        name, accepted = QInputDialog.getText(
            self, "Rename Folder" if folder else "Rename Photo",
            "New name:", text=initial)
        name = name.strip()
        if not accepted or not name:
            return
        if name in (".", "..") or os.path.basename(name) != name:
            QMessageBox.warning(self, "Rename", "Enter a single name, not a path.")
            return
        if not folder and not os.path.splitext(name)[1]:
            name += extension
        target = os.path.join(os.path.dirname(path), name)
        if os.path.exists(target):
            QMessageBox.warning(self, "Rename", f"That name already exists:\n{target}")
            return
        try:
            os.rename(path, target)
        except OSError as exc:
            QMessageBox.warning(self, "Could not rename", str(exc))
            return
        self.catalog.move_path(path, target)
        self.catalog.record_operation("rename", [(path, target)])
        if folder and self._folder:
            try:
                inside = os.path.commonpath([self._folder, path]) == path
            except ValueError:
                inside = False
            if inside:
                self._folder = target + self._folder[len(path):]
        self._reload_current()

    def _choose_destination(self, title):
        dialog = QFileDialog(self, title, self._folder or QDir.homePath())
        dialog.setFileMode(QFileDialog.Directory)
        dialog.setOption(QFileDialog.ShowDirsOnly, True)
        dialog.setOption(QFileDialog.DontUseNativeDialog, True)
        if dialog.exec():
            selected = dialog.selectedFiles()
            return selected[0] if selected else ""
        return ""

    def _import_photos(self):
        dialog = QFileDialog(self, "Choose photos to import")
        dialog.setFileMode(QFileDialog.ExistingFiles)
        dialog.setNameFilter(
            "Photos (*.jpg *.jpeg *.png *.tif *.tiff *.webp *.bmp *.gif "
            "*.dng *.nef *.cr2 *.cr3 *.arw *.orf *.rw2 *.raf)")
        dialog.setOption(QFileDialog.DontUseNativeDialog, True)
        if not dialog.exec():
            return
        paths = dialog.selectedFiles()
        if not paths:
            return
        destination = self._choose_destination("Import photos into folder")
        if not destination:
            return
        organize = QMessageBox.question(
            self, "Organize Import",
            "Create YYYY/MM folders using each photo's capture date?",
            QMessageBox.Yes | QMessageBox.No | QMessageBox.Cancel,
            QMessageBox.Yes)
        if organize == QMessageBox.Cancel:
            return
        try:
            outputs, skipped = import_photos(
                paths, destination, date_folders=organize == QMessageBox.Yes,
                skip_duplicates=True)
        except (OSError, ValueError) as exc:
            QMessageBox.warning(self, "Import failed", str(exc))
            return
        self.status.showMessage(
            f"Imported {len(outputs)} photo(s); skipped {len(skipped)} duplicate/error(s)",
            7000)
        if self._folder and os.path.normcase(self._folder) == os.path.normcase(destination):
            self._reload_current()

    def _rotate_selected(self, degrees):
        paths = self._selected_photo_paths()
        if not paths:
            QMessageBox.information(self, "Rotate", "Select one or more photos first.")
            return
        answer = QMessageBox.question(
            self, "Create Rotated Copies",
            f"Create rotated copies of {len(paths)} photo(s)?\n\n"
            "The originals will not be changed.",
            QMessageBox.Yes | QMessageBox.Cancel, QMessageBox.Cancel)
        if answer != QMessageBox.Yes:
            return
        try:
            outputs = photo_manager.rotate_files(paths, degrees)
        except (OSError, ValueError) as exc:
            QMessageBox.warning(self, "Rotation failed", str(exc))
            return
        self.status.showMessage(f"Created {len(outputs)} rotated copy/copies", 6000)
        self._reload_current()

    def _find_duplicates(self):
        groups = photo_manager.duplicate_groups(self._paths)
        duplicates = {os.path.normcase(os.path.abspath(path))
                      for group in groups for path in group}
        self.list.clearSelection()
        for path, item in self._items.items():
            if os.path.normcase(os.path.abspath(path)) in duplicates:
                item.setSelected(True)
        self.status.showMessage(
            f"Found {len(groups)} duplicate group(s); "
            f"selected {len(duplicates)} files", 8000)

    def _export_selected(self):
        paths = self._selected_photo_paths()
        if not paths:
            QMessageBox.information(self, "Batch Export", "Select photos first.")
            return
        destination = self._choose_destination("Export copies into folder")
        if not destination:
            return
        max_size, accepted = QInputDialog.getInt(
            self, "Batch Export", "Maximum width/height in pixels (0 = full size):",
            2048, 0, 30000, 1)
        if not accepted:
            return
        quality, accepted = QInputDialog.getInt(
            self, "Batch Export", "JPEG quality:", 90, 40, 100, 1)
        if not accepted:
            return
        try:
            outputs = photo_manager.export_files(
                paths, destination, max_size=max_size, quality=quality,
                sharpen=True)
        except (OSError, ValueError) as exc:
            QMessageBox.warning(self, "Batch export failed", str(exc))
            return
        self.status.showMessage(
            f"Exported {len(outputs)} photo(s) to {destination}", 7000)

    def _apply_recipe_selected(self):
        paths = self._require_selected("Apply Recipe")
        if not paths:
            return
        recipe_path, _ = QFileDialog.getOpenFileName(
            self, "Choose SNAP SLAPPER Recipe", "", "SNAP SLAPPER recipe (*.slaprecipe)")
        if not recipe_path:
            return
        destination = self._choose_destination("Save edited copies into folder")
        if not destination:
            return
        try:
            from . import prefs
            settings = prefs.load()
            outputs = editor_engine.batch_apply(
                paths, editor_engine.load_recipe(recipe_path), destination,
                quality=int(settings["export_quality"]),
                copyright_text=(settings["copyright_text"]
                                if settings["add_copyright_if_missing"] else ""),
                strip_gps=bool(settings["strip_gps"]))
        except (OSError, ValueError) as exc:
            QMessageBox.warning(self, "Recipe batch failed", str(exc))
            return
        self.status.showMessage(
            f"Applied recipe to {len(outputs)} photo(s); originals unchanged", 7000)

    def _require_selected(self, title):
        paths = self._selected_photo_paths()
        if not paths:
            QMessageBox.information(self, title, "Select one or more photos first.")
        return paths

    def _start_slideshow(self):
        paths = self._require_selected("Slideshow")
        if not paths:
            return
        self._slideshow = SlideshowDialog(paths, self)
        self._slideshow.showMaximized()

    def _create_contact_sheet(self):
        paths = self._require_selected("Contact Sheet")
        if not paths:
            return
        columns, accepted = QInputDialog.getInt(
            self, "Contact Sheet", "Photos per row:", 4, 1, 12, 1)
        if not accepted:
            return
        output, _ = QFileDialog.getSaveFileName(
            self, "Save Contact Sheet", "contact-sheet.jpg", "JPEG image (*.jpg *.jpeg)")
        if not output:
            return
        try:
            output = create_contact_sheet(paths, output, columns=columns)
        except (OSError, ValueError) as exc:
            QMessageBox.warning(self, "Contact sheet failed", str(exc))
            return
        self.status.showMessage(f"Saved contact sheet: {output}", 7000)

    def _print_selected(self):
        paths = self._require_selected("Print")
        if not paths:
            return
        printer = QPrinter(QPrinter.HighResolution)
        printer.setDocName("SNAP SLAPPER photographs")
        dialog = QPrintDialog(printer, self)
        if dialog.exec() != QPrintDialog.Accepted:
            return
        painter = QPainter(printer)
        if not painter.isActive():
            QMessageBox.warning(self, "Print failed", "The printer could not be started.")
            return
        try:
            page = printer.pageRect(QPrinter.DevicePixel)
            for index, path in enumerate(paths):
                if index:
                    printer.newPage()
                image = QImage(path)
                if image.isNull():
                    continue
                target = image.size()
                target.scale(page.size(), Qt.KeepAspectRatio)
                x = page.x() + (page.width() - target.width()) // 2
                y = page.y() + (page.height() - target.height()) // 2
                painter.drawImage(x, y, image.scaled(
                    target, Qt.KeepAspectRatio, Qt.SmoothTransformation))
        finally:
            painter.end()
        self.status.showMessage(f"Sent {len(paths)} photo(s) to the printer", 7000)

    def _choose_transfer(self, copy_files):
        paths = self._selected_photo_paths()
        if not paths:
            QMessageBox.information(self, "Organize", "Select one or more photos first.")
            return
        destination = self._choose_destination(
            "Copy photos to folder" if copy_files else "Move photos to folder")
        if destination:
            self._perform_transfer(paths, destination, copy_files)

    def _drop_on_folder(self, paths, destination, copy_files):
        self._perform_transfer(paths, destination, copy_files)

    def _perform_transfer(self, paths, destination, copy_files=False):
        verb = "Copying" if copy_files else "Moving"
        self.status.showMessage(
            f"{verb} {len(paths)} photo(s) to {destination}…")
        self._pool.start(_FileTask(
            paths, destination, copy_files, self._file_signals))

    def _on_transfer_finished(self, done, errors, destination, copy_files, sources):
        if errors:
            detail = "\n".join(errors[:8])
            if len(errors) > 8:
                detail += f"\n…and {len(errors) - 8} more."
            QMessageBox.warning(self, "Some photos were not organized", detail)
        verb = "Copied" if copy_files else "Moved"
        if done:
            by_name = {os.path.normcase(os.path.basename(path)): path
                       for path in sources}
            for target in done:
                source = by_name.get(os.path.normcase(os.path.basename(target)))
                if source:
                    if copy_files:
                        self.catalog.copy_path(source, target)
                    else:
                        self.catalog.move_path(source, target)
            if not copy_files:
                changes = []
                for target in done:
                    source = by_name.get(os.path.normcase(os.path.basename(target)))
                    if source:
                        changes.append((source, target))
                if changes:
                    self.catalog.record_operation("move", changes)
            self.status.showMessage(
                f"{verb} {len(done)} photo(s) to {destination}", 6000)
        self._reload_current()

    def _trash_selected(self):
        paths = self._selected_photo_paths()
        if not paths:
            QMessageBox.information(self, "Trash", "Select one or more photos first.")
            return
        answer = QMessageBox.question(
            self, "Move to SNAP SLAPPER Trash",
            f"Move {len(paths)} selected photo(s) to recoverable SNAP SLAPPER Trash?",
            QMessageBox.Yes | QMessageBox.Cancel, QMessageBox.Cancel)
        if answer != QMessageBox.Yes:
            return
        try:
            photo_manager.trash_files(
                paths, self.catalog.trash_root, self.catalog.trash_path)
        except OSError as exc:
            QMessageBox.warning(self, "Trash failed", str(exc))
            return
        self._reload_current()

    def _restore_trash(self):
        try:
            restored = photo_manager.restore_last_trash(self.catalog.trash_path)
        except OSError as exc:
            QMessageBox.warning(self, "Restore failed", str(exc))
            return
        if restored:
            self.status.showMessage(f"Restored {restored[0]}", 7000)
            self._reload_current()
        else:
            QMessageBox.information(self, "SNAP SLAPPER Trash", "Trash is empty.")

    def _undo_file_operation(self):
        try:
            restored = self.catalog.undo_last_move()
        except OSError as exc:
            QMessageBox.warning(self, "Undo failed", str(exc))
            return
        if not restored:
            QMessageBox.information(
                self, "Undo File Operation", "There is no move or rename to undo.")
            return
        self.status.showMessage(f"Undid {len(restored)} file change(s)", 7000)
        self._reload_current()

    def _show_in_folder(self):
        paths = self._selected_photo_paths()
        folder = os.path.dirname(paths[0]) if paths else self._selected_tree_folder()
        if folder:
            QDesktopServices.openUrl(QUrl.fromLocalFile(folder))

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
            self.catalog.register_folder(folder)
            self.load_folder(folder)

    def _show_folder_in_tree(self, folder):
        """Show the selected folder as a row, together with its siblings.

        A QTreeView displays the *children* of its root index.  Rooting it at
        the selected photo folder therefore makes the pane look empty whenever
        that folder has no subdirectories.  Root at the parent instead and
        select the current folder so the navigation pane always contains a
        visible, useful location.
        """
        folder = os.path.abspath(folder)
        parent = os.path.dirname(folder)
        if not parent or os.path.normcase(parent) == os.path.normcase(folder):
            parent = folder
        root_index = self.tree_model.setRootPath(parent)
        if root_index.isValid():
            self.tree.setRootIndex(root_index)
        folder_index = self.tree_model.index(folder)
        if folder_index.isValid():
            self.tree.setCurrentIndex(folder_index)
            self.tree.scrollTo(folder_index)

    def _go_up_folder(self):
        """Leave the current tree root instead of trapping navigation below it."""
        current = os.path.abspath(self._folder or self._selected_tree_folder() or "")
        if not current:
            return
        parent = os.path.dirname(current)
        if not parent or os.path.normcase(parent) == os.path.normcase(current):
            return
        self.catalog.register_folder(parent)
        self.load_folder(parent)

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
        from . import prefs
        values = prefs.load()
        if values.get("library_folder") != folder:
            values["library_folder"] = folder
            prefs.save(values)
        self._virtual_source = None
        recursive = self.act_subfolders.isChecked()
        self._folder = folder
        self._show_folder_in_tree(folder)
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
        self.setWindowTitle(
            f"SNAP SLAPPER — {os.path.basename(folder) or folder} "
            f"(build {BUILD_VERSION})")
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
        self.catalog.update_index(paths)
        self._refresh_catalog_sources()
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
                details = self.catalog.details(path)
                badges = ("♥ " if details["favorite"] else "") + \
                    ("★" * details["rating"])
                label = os.path.basename(path)
                if badges:
                    label = f"{badges}\n{label}"
                item = QListWidgetItem(icon, label)
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
        catalog_filter = self.catalog_filter.currentData() \
            if hasattr(self, "catalog_filter") else "all"
        shown = 0
        for path, item in self._items.items():
            details = self.catalog.details(path)
            haystack = " ".join((os.path.basename(path), details["tags"])).lower()
            match = not needle or needle in haystack
            rating = details["rating"]
            if catalog_filter == "favorites":
                match = match and details["favorite"]
            elif catalog_filter == "rated":
                match = match and rating > 0
            elif catalog_filter == "unrated":
                match = match and rating == 0
            elif str(catalog_filter).endswith("+"):
                match = match and rating >= int(str(catalog_filter)[0])
            elif str(catalog_filter).isdigit():
                match = match and rating == int(catalog_filter)
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

    def _panomerge_selected(self):
        from .panomerge import PanomergeDialog, platform_supported
        if not platform_supported():
            QMessageBox.warning(
                self, "PANOMERGE unavailable",
                "PANOMERGE is available on Windows and Linux only.")
            return
        paths = self._selected_photo_paths()
        dialog = PanomergeDialog(paths, self)
        if dialog.exec() == QDialog.Accepted and dialog.result_path:
            self._open_editor_path(dialog.result_path)
            if self._folder and os.path.dirname(dialog.result_path) == self._folder:
                self._reload_current()

    def _open_item(self, item):
        path = item.data(Qt.UserRole)
        if not path:
            return
        self._open_editor_path(path)

    def _open_editor_path(self, path):
        path = os.path.abspath(path)
        path_key = os.path.normcase(path)
        if path_key in self._opening_editor_paths:
            return
        # Guard against duplicate activation signals and bring the existing
        # editor forward instead of manufacturing a second identical window.
        for existing in list(self._editors):
            try:
                source = existing.doc.source_path if existing.doc else None
                if source and os.path.abspath(source) == path and existing.isVisible():
                    existing.raise_()
                    existing.activateWindow()
                    return
            except RuntimeError:
                self._editors.remove(existing)
        self._opening_editor_paths.add(path_key)
        try:
            editor = EditorWindow()
            # Retain the window before opening the document. Image decoding and
            # layout can process queued UI events; a second activation during
            # that interval must not manufacture another editor.
            self._editors.append(editor)
            editor.open_path(path)
            editor.show()
        finally:
            self._opening_editor_paths.discard(path_key)

    def showEvent(self, event):  # noqa: N802 — Qt override
        """Restore the useful library state after its native window exists."""
        super().showEvent(event)
        if not self._window_state_restored:
            self._window_state_restored = True
            if self._restore_maximized:
                QTimer.singleShot(0, self.showMaximized)

    def closeEvent(self, event):  # noqa: N802 — Qt override
        # Closing from the taskbar while minimized is not a request to reopen
        # small. Only remember a visible, intentional normal/maximized state.
        if not self.isMinimized():
            from . import prefs
            values = prefs.load()
            values["library_maximized"] = self.isMaximized()
            prefs.save(values)
        super().closeEvent(event)

# ===== SNAPSMACK EOF =====
