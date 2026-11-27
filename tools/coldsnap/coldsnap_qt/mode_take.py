"""COLD SNAP Qt — COLD TAKE: a title, a story, and a bucket of photos
(long-form / SMACKTALK sites).

Feature parity with the Tk sumna_smacktalk panel; engines untouched. The
mosaic marker keeps its one-line plain-words explainer right beside the button.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import math
import os
import re
import tempfile
import traceback
import threading
import uuid

import requests

from concurrent.futures import ThreadPoolExecutor
from PySide6.QtCore import QObject, Qt, QDate, QEvent, QEventLoop, QMetaObject, QRect, QSize, Signal, Slot, QTimer, QSettings
from PySide6.QtGui import QTextCursor, QPainter, QPixmap, QColor, QPen, QIcon, QKeySequence, QShortcut, QFont
from PySide6.QtWidgets import (
    QWidget, QHBoxLayout, QVBoxLayout, QGridLayout, QLabel, QLineEdit, QPlainTextEdit,
    QComboBox, QPushButton, QFileDialog, QMessageBox, QProgressDialog, QScrollArea, QFrame,
    QDialog, QDialogButtonBox, QListWidget, QListWidgetItem, QAbstractItemView,
    QSplitter, QFormLayout, QSpinBox, QSizePolicy, QRadioButton, QSlider, QDateEdit,
    QMenu, QCheckBox, QInputDialog,
)

import sumna_offline as O
import snap_library
from snap_home import site_key
from sumna_post import AuthorizationExpiredError, SmacktalkPoster

from . import theme, biggie
from .canvas import mosaic_cells as _mosaic_cells
from .canvas import add_mosaic_photo_actions
from .widgets import (Accordion, Card, build_rail, hint, field_label,
                      big_button, load_pixmap)
from .mosaic_browser import MosaicBrowser
from .mosaic_clusters import break_cluster_weights, uniform_clusters
from .photo_edits import render_non_destructive
from .slapper_handoff import (ReturnWatch, find_executable,
                              focus_window, launch as launch_slapper)
from .body_editor import BodyEditor
from .block_tray import BlockAction, BlockTray
from .photo_tray import RightPhotosTray, PhotoThumbTile, photo_orientation_badge
from .drafts_panel import BatchRail, default_draft_row
from .mosaic_layout import tile_rects as _tile_rects


class _PublishedPostBridge(QObject):
    """Move published-post network results back onto the GUI thread."""

    list_loaded = Signal(object)
    list_failed = Signal(object)
    loaded = Signal(object)
    failed = Signal(object)


class MosaicPreview(QWidget):
    """Embedded site preview with native controls for the selected photographs."""

    swapRequested = Signal(int, int)
    moveRequested = Signal(int, int)
    sideSwapRequested = Signal(int)
    sizeRequested = Signal(int, int)
    reverseRequested = Signal()
    breakRequested = Signal(object)
    includeRequested = Signal()
    excludeRequested = Signal(int)
    clustersChanged = Signal(int)
    zoomChanged = Signal(str)

    def __init__(self, parent=None, *, content_width: int = 760, gap: int = 4):
        super().__init__(parent)
        self._content_width = max(240, int(content_width or 760))
        self._gap = max(0, min(20, int(gap)))
        self.setMinimumSize(self._content_width + 16, 220)
        self.setFixedWidth(self._content_width + 16)
        self.setToolTip(
            "Drag one preview tile onto another to swap them. Right-click a "
            "photo to move, resize, swap sides, or reverse the arrangement.")
        self._tiles, self._rects = [], []
        self._layout_name, self._drag_from = "asymmetric", None
        self._rendered_layout = "asymmetric"
        self._weights = {}
        self._mirrored_sections = ()
        self._cells = {"items": [], "height": 0}
        self._fit_viewport = None
        self._fit_scale = 1.0
        self._zoom = None
        self._clusters = []
        self._cluster_widgets = []
        self.tray_additions = lambda: ()
        self._browser = MosaicBrowser(self)
        self._browser.geometry_ready.connect(self._use_geometry)
        self._browser.failed.connect(self._show_error)
        self._error = QLabel(self)
        self._error.setAlignment(Qt.AlignCenter)
        self._error.hide()
        stack = QVBoxLayout(self)
        stack.setContentsMargins(8, 8, 8, 8)
        stack.addWidget(self._browser)
        stack.addWidget(self._error)
        self.setFixedHeight(220)

    def _show_error(self, message):
        self._browser.hide()
        self._error.setText(message)
        self._error.show()

    def set_content_width(self, width: int):
        self._content_width = max(240, int(width or 760))
        self.setFixedWidth(self._content_width + 16)
        self._refresh_browser()

    def set_mosaic(self, tiles, layout_name, gap: int = 4, weights=None, mirrored_sections=()):
        self._tiles = list(tiles)
        self._layout_name = str(layout_name or "asymmetric")
        self._gap = max(0, min(20, int(gap)))
        self._weights = {str(k): int(v) for k, v in (weights or {}).items() if int(v or 0) != 0}
        self._mirrored_sections = tuple(mirrored_sections)
        self._refresh_browser()

    def _refresh_browser(self):
        self._clear_cluster_widgets()
        self._clusters = []
        self._rects = []
        self._cells = {"items": [], "height": 0}
        self._browser.set_mosaic(
            self._tiles, self._layout_name, self._gap, self._weights, self._content_width,
            self._mirrored_sections)

    def computed_cells(self):
        """Geometry from the last completed browser render."""
        return self._cells

    def fit_to_viewport(self, viewport):
        self._fit_viewport = viewport
        viewport.installEventFilter(self)
        self._place_geometry()

    def eventFilter(self, watched, event):
        if watched is self._fit_viewport:
            if event.type() == QEvent.Resize:
                self._place_geometry()
            elif event.type() == QEvent.Wheel and self._zoom_from_wheel(event):
                return True
        return super().eventFilter(watched, event)

    def wheelEvent(self, event):
        if not self._zoom_from_wheel(event):
            super().wheelEvent(event)

    def _zoom_from_wheel(self, event):
        if self._fit_viewport is None or not (event.modifiers() & Qt.ControlModifier):
            return False
        delta = event.angleDelta().y()
        if delta:
            self.step_zoom(1 if delta > 0 else -1)
        event.accept()
        return True

    def set_zoom(self, scale):
        self._zoom = scale
        self._place_geometry()

    def step_zoom(self, direction):
        scale = round(self._fit_scale * 100) + direction * 10
        self.set_zoom(max(10, min(200, scale)) / 100)

    def _use_geometry(self, geometry):
        self._error.hide()
        self._browser.show()
        self._cells = geometry
        self._rendered_layout = self._layout_name
        self._place_geometry()
        self.clustersChanged.emit(len(self._clusters))

    def _place_geometry(self):
        geometry = self._cells
        height = geometry["height"] if geometry["items"] else 220
        scale = 1.0
        if self._zoom is not None:
            scale = self._zoom
        elif self._fit_viewport is not None:
            available = self._fit_viewport.size()
            scale = min(1, max(1, available.width() - 16) / self._content_width,
                        max(1, available.height() - 16) / height)
        self._fit_scale = scale
        self.zoomChanged.emit(f"{round(scale * 100)}%")
        self._browser.set_visual_scale(scale)
        self._rects = [
            (self._tiles[cell["id"] - 1][0], QRect(
                8 + round(cell["x"] * scale), 8 + round(cell["y"] * scale),
                max(1, round(cell["width"] * scale)), max(1, round(cell["height"] * scale)),
            ))
            for cell in geometry["items"]
        ]
        self.setFixedSize(math.ceil(self._content_width * scale) + 16,
                          max(0 if self._fit_viewport is not None else 220,
                              math.ceil(height * scale) + 16))
        self._show_clusters(geometry["items"])

    def _clear_cluster_widgets(self):
        for widget in self._cluster_widgets:
            widget.hide()
            widget.deleteLater()
        self._cluster_widgets = []

    def _show_clusters(self, cells):
        self._clear_cluster_widgets()
        self._clusters = uniform_clusters(cells, self._gap)
        for cluster in self._clusters:
            x = min(cell["x"] for cell in cluster)
            y = min(cell["y"] for cell in cluster)
            right = max(cell["x"] + cell["width"] for cell in cluster)
            bottom = max(cell["y"] + cell["height"] for cell in cluster)
            outline = QFrame(self)
            scale = self._fit_scale
            outline.setGeometry(8 + round(x * scale), 8 + round(y * scale),
                                round((right - x) * scale), round((bottom - y) * scale))
            outline.setAttribute(Qt.WA_TransparentForMouseEvents)
            outline.setStyleSheet("border: 3px solid #ffc44a; background: transparent;")
            outline.show()
            outline.raise_()
            button = QPushButton("Break up", self)
            rows = [self._tiles[cell["id"] - 1][0] for cell in cluster]
            button.clicked.connect(lambda _checked=False, rows=rows: self.breakRequested.emit([rows]))
            button.adjustSize()
            button.move(16 + round(x * scale), 16 + round(y * scale))
            button.show()
            button.raise_()
            self._cluster_widgets.extend((outline, button))

    @staticmethod
    def tile_rects(width, height, count, layout_name, gap=5):
        return _tile_rects(width, height, count, layout_name, gap)

    def _row_at(self, point):
        return next((row for row, rect in self._rects if rect.contains(point)), None)

    def mousePressEvent(self, event):
        if event.button() == Qt.LeftButton:
            self._drag_from = self._row_at(event.position().toPoint())

    def mouseReleaseEvent(self, event):
        source, target = self._drag_from, self._row_at(event.position().toPoint())
        self._drag_from = None
        if source is not None and target is not None and source != target:
            self.swapRequested.emit(source, target)

    def _arrangement_menu(self, row):
        ordered_rows = [tile_row for tile_row, _rect in self._rects]
        if row not in ordered_rows:
            return None
        position = ordered_rows.index(row)
        menu = QMenu(self)
        cluster = next(([self._tiles[cell["id"] - 1][0] for cell in group]
                        for group in self._clusters if any(cell["id"] == position + 1 for cell in group)), ())
        dispatch = {
            "move": lambda step: self.moveRequested.emit(row, step),
            "size": lambda step: self.sizeRequested.emit(row, step),
            "mirror": lambda _step: self.sideSwapRequested.emit(row),
            "reverse": lambda _step: self.reverseRequested.emit(),
            "break": lambda _step: self.breakRequested.emit([cluster]),
            "include": lambda _step: self.includeRequested.emit(),
            "exclude": lambda _step: self.excludeRequested.emit(row),
        }
        add_mosaic_photo_actions(
            menu, position, len(ordered_rows), self._cells.get("sections", ()), cluster, self.tray_additions(),
            lambda change, step: dispatch[change](step),
        )
        return menu

    def contextMenuEvent(self, event):
        row = self._row_at(event.pos())
        menu = self._arrangement_menu(row)
        if menu is None:
            return
        menu.exec(event.globalPos())


class _MosaicSizeProbe:
    """The minimum canvas contract needed by the shared mosaic geometry."""

    def __init__(self, paths):
        self.bucket = list(paths)
        self.assets = {}
        self._mosaic_size_cache = {}
        self._mosaic_layout_cache = {}


def _mosaic_area_profile(order, path_for, layout, width=760, gap=4):
    """Return cell areas in authored order using the exact site-layout port."""
    paths = [path_for(value) for value in order]
    probe = _MosaicSizeProbe(paths)
    _paths, geometry = _mosaic_cells(
        probe,
        {
            "order": list(range(1, len(paths) + 1)),
            "layout": str(layout or "asymmetric"),
            "gap": gap,
        },
        width,
    )
    return [
        float(cell.get("width") or 0.0) * float(cell.get("height") or 0.0)
        for cell in (geometry.get("items") or [])
    ]


def _resize_mosaic_order(order, target, path_for, *, larger, width=760, gap=4):
    """Move one photo into a larger/smaller span without changing its pixels.

    The public mosaic engine already makes unequal editorial spans. Which photo
    gets each span is determined by the authored order. Try the selected photo
    in every saved position, measure each result with the same geometry used by
    BIGGIE, and choose the largest or smallest real cell. Other photos keep
    their relative order, so the action is predictable and reversible.
    """
    authored = list(order)
    if target not in authored or len(authored) < 3:
        return authored, "asymmetric", False
    target_layout = "one-left" if len(authored) == 3 else "asymmetric"
    others = [value for value in authored if value != target]
    current_position = authored.index(target)
    candidates = []
    for position in range(len(authored)):
        candidate = list(others)
        candidate.insert(position, target)
        areas = _mosaic_area_profile(candidate, path_for, target_layout, width, gap)
        area = areas[position] if position < len(areas) else 0.0
        candidates.append((area, -abs(position - current_position), candidate))
    if larger:
        _area, _distance, chosen = max(
            candidates, key=lambda item: (item[0], item[1]))
    else:
        _area, _distance, chosen = min(
            candidates, key=lambda item: (item[0], -item[1]))
    return chosen, target_layout, chosen != authored


class _PageRuler(QWidget):
    """24px ruler above the document canvas showing the active preview width."""

    def __init__(self, preview_width: int = 760, parent=None):
        super().__init__(parent)
        self._preview_width = int(preview_width or 760)
        self.setFixedHeight(24)

    def set_preview_width(self, width: int):
        self._preview_width = int(width or 760)
        self.update()

    def paintEvent(self, _event):
        p = QPainter(self)
        p.fillRect(self.rect(), QColor(theme.BG))
        w = self.width()
        span = min(w - 24, self._preview_width + 80)
        left = (w - span) // 2
        right = left + span
        p.setPen(QPen(QColor(theme.BORDER), 1))
        p.drawLine(left, self.height() - 1, right, self.height() - 1)
        for x in range(left, right + 1, 16):
            major = ((x - left) % 80) == 0
            tick_h = 8 if major else 4
            p.drawLine(x, self.height() - 1 - tick_h, x, self.height() - 1)
        # Green margin markers
        p.setBrush(QColor(theme.ACCENT))
        p.setPen(Qt.NoPen)
        for mx in (left + 40, right - 40):
            p.drawRect(mx - 3, 2, 6, 5)


class TakeMode(QWidget):
    SUITE_MODE = O.MODE_SMACKTALK
    blocksStateChanged = Signal(bool, str)  # open, header label
    photosStateChanged = Signal(bool)       # right photos tray open
    detailsStateChanged = Signal(bool)      # post details drawer open
    exitFocusRequested = Signal()           # exit focus mode from format strip
    _thumbsReady = Signal(object)           # background thumb job finished (queued to UI thread)

    def __init__(self, app_config_provider, parent=None):
        super().__init__(parent)
        self.app_config = app_config_provider
        self._editing_id = None
        self._bucket = []          # list[O.DraftImage]
        self._mosaic_tray_edits = (), None
        self._cover_idx = 0
        self._selected_photo_idx = None
        self._selected_photo_indices = []
        self._updating_photo_fields = False
        self._loading = False
        self._view_settings = QSettings("SnapSmack", "COLD SNAP")
        self._slapper_state = "idle"
        self._slapper_watch_session = None
        self._slapper_asset_uuid = ""
        self._slapper_launch_error = ""
        self._published_list_active = False
        self._published_load_active = False
        self._published_choose_fn = None
        self._published_bridge = _PublishedPostBridge(self)
        self._published_bridge.list_loaded.connect(self._published_list_loaded)
        self._published_bridge.list_failed.connect(self._published_list_failed)
        self._published_bridge.loaded.connect(self._published_post_loaded)
        self._published_bridge.failed.connect(self._published_post_failed)

        outer = QHBoxLayout(self)
        outer.setContentsMargins(0, 0, 0, 0)
        outer.setSpacing(0)

        self.rail = BatchRail(self.SUITE_MODE, "post", self._poster_and_url,
                              row_builder=self._rows)

        # SIMPLE (text + the CMS shortcode bar) / BIGGIE (block stack) — one field.
        self.body = BodyEditor(allow_mosaic=True, simple_height=220)
        self.body.bar.hide()  # SHORTCODES live in the left rail (Slice 8)
        self.body.canvas.mosaicEditRequested.connect(self._canvas_mosaic)
        self.body.canvas.mosaicPreviewFailed.connect(self._show_canvas_failure)
        self.body.canvas.mosaic_tray_additions = self._mosaic_tray_additions
        self.body.canvas.photographsDropped.connect(
            lambda paths: self._add_photo_paths(paths, insert=True))
        self.body.canvas.photographPasted.connect(self._paste_photo)
        self.body.canvas.trayPhotoDropped.connect(self._drop_tray_photo_biggie)
        self.body.editor.setAcceptDrops(True)
        # Existing media gallery images can still be edited on the canvas.
        self.body.canvas.set_site_provider(
            lambda: ((self.app_config() or {}).get("url") or "").strip())

        scroll = QScrollArea()
        scroll.setObjectName("CanvasWell")
        scroll.setFrameShape(QFrame.NoFrame)
        scroll.setWidgetResizable(True)
        scroll.setMinimumWidth(0)
        self._canvas_scroll = scroll
        scroll.viewport().installEventFilter(self)
        self.body.canvas.viewport().installEventFilter(self)
        self.body.editor.viewport().installEventFilter(self)
        host = QWidget()
        host.setObjectName("CanvasWell")
        well = QVBoxLayout(host)
        well.setContentsMargins(16, 12, 16, 18)
        well.setSpacing(10)
        self._well_layout = well

        # Centered white page card + thin left gutter tag (Slice 2)
        page_row = QHBoxLayout()
        page_row.setContentsMargins(0, 0, 0, 0)
        page_row.setSpacing(8)
        page_row.addStretch(1)

        self.gutter_tag = QLabel("P")
        self.gutter_tag.setObjectName("BlockGutterTag")
        self.gutter_tag.setFixedWidth(52)
        self.gutter_tag.setAlignment(Qt.AlignRight | Qt.AlignTop)
        page_row.addWidget(self.gutter_tag, 0, Qt.AlignTop)

        self.page_card = QFrame()
        self.page_card.setObjectName("PageCard")
        self.page_card.setProperty("textView", False)
        self._preview_width = 760
        self.page_card.setFixedWidth(self._preview_width + 80)
        card_col = QVBoxLayout(self.page_card)
        card_col.setContentsMargins(40, 30, 40, 26)
        card_col.setSpacing(8)

        self.page_eyebrow = QLabel("ESSAY · DRAFT")
        self.page_eyebrow.setObjectName("PageEyebrow")
        card_col.addWidget(self.page_eyebrow)

        self.title_edit = QLineEdit()
        self.title_edit.setObjectName("PageTitle")
        self.title_edit.setPlaceholderText("Essay title…")
        card_col.addWidget(self.title_edit)

        colophon_label = field_label("Colophon")
        self.colophon_edit = QLineEdit()
        self.colophon_edit.setPlaceholderText("Equipment or production note (optional)")
        self.colophon_edit.setAccessibleName("Colophon")
        colophon_label.setBuddy(self.colophon_edit)
        card_col.addWidget(colophon_label)
        card_col.addWidget(self.colophon_edit)

        signature_row = QHBoxLayout()
        self.signature_check = QCheckBox("Include blog signature")
        self.signature_check.setChecked(True)
        signature_row.addWidget(self.signature_check)
        signature_settings = QPushButton("Blog signature…")
        signature_settings.clicked.connect(self._configure_signature)
        signature_row.addWidget(signature_settings)
        signature_row.addStretch()
        card_col.addLayout(signature_row)

        self.page_meta = QLabel("")
        self.page_meta.setObjectName("PageMeta")
        card_col.addWidget(self.page_meta)
        self.linked_post_lbl = QLabel("")
        self.linked_post_lbl.setObjectName("LinkedPostStatus")
        self.linked_post_lbl.setVisible(False)
        card_col.addWidget(self.linked_post_lbl)
        self.site_colors_note = QLabel("approximate: skin styles not loaded")
        self.site_colors_note.setObjectName("SiteColorsNote")
        self.site_colors_note.setVisible(False)
        card_col.addWidget(self.site_colors_note)
        self.canvas_warning = hint("")
        self.canvas_warning.setWordWrap(True)
        self.canvas_warning.hide()
        card_col.addWidget(self.canvas_warning)
        self.text_view_hint = hint(
            "A plain text editor. Each block uses CMS shortcodes from core/parser.php "
            "(plus COLD SNAP [mosaic=…] markers resolved to [mosaic:ID] on send).")
        self.text_view_hint.setVisible(False)
        card_col.addWidget(self.text_view_hint)
        card_col.addSpacing(6)

        card_col.addWidget(self.body, 1)

        add_row = QHBoxLayout()
        add_row.addStretch(1)
        self.add_block_btn = QPushButton("+ Add block")
        self.add_block_btn.setObjectName("AddBlockPill")
        self.add_block_btn.setFocusPolicy(Qt.NoFocus)
        self.add_block_btn.setToolTip("Insert a new block at the cursor (or use BLOCKS on the left)")
        self.add_block_btn.clicked.connect(self._show_add_block_menu)
        add_row.addWidget(self.add_block_btn)
        add_row.addStretch(1)
        card_col.addLayout(add_row)

        page_row.addWidget(self.page_card, 0)
        page_row.addStretch(1)
        well.addLayout(page_row, 1)

        # Compact details row (Tags + Status; four explainer hints moved to tooltips/Help)
        compose_details = QWidget()
        details = QHBoxLayout(compose_details)
        details.setContentsMargins(16, 4, 16, 4)
        details.setSpacing(10)
        details.addWidget(field_label("Tags"))
        self.tags_edit = QLineEdit()
        self.tags_edit.setPlaceholderText("#space-separated #hashtags")
        details.addWidget(self.tags_edit, 1)
        details.addWidget(field_label("Status"))
        self.status_combo = QComboBox()
        self.status_combo.addItems(["published", "draft"])
        self.status_combo.currentTextChanged.connect(lambda _t: self._update_page_chrome())
        details.addWidget(self.status_combo)
        self.compose_splitter = None

        # -- THE PHOTOS — a rail section, opened when wanted. The count lives in
        #    the accordion header (no redundant, cramped label fighting the
        #    buttons for the 340px rail width). --------------------------------
        self.photos_sec = Accordion("THE PHOTOS — none yet", expanded=True)
        self.photos_sec.setMaximumWidth(360)
        self.photos_sec.body.setMaximumWidth(360)

        # Thumbnail zoom row (60–150%, persisted in QSettings)
        self._thumb_zoom = int(self._view_settings.value("take/thumb_zoom", 100))
        t_row = QHBoxLayout()
        t_row.setContentsMargins(0, 0, 0, 2)
        t_row.setSpacing(4)
        t_lbl = QLabel("THUMBNAILS")
        t_lbl.setObjectName("BlockTrayGroup")
        t_row.addWidget(t_lbl)
        t_out = QPushButton("-")
        t_out.setObjectName("PreviewToggle")
        t_out.setFixedWidth(24)
        t_out.setFocusPolicy(Qt.NoFocus)
        t_out.clicked.connect(lambda: self._set_thumb_zoom(self._thumb_zoom - 10))
        t_row.addWidget(t_out)
        self.thumb_zoom_slider = QSlider(Qt.Horizontal)
        self.thumb_zoom_slider.setRange(60, 150)
        self.thumb_zoom_slider.setSingleStep(10)
        self.thumb_zoom_slider.setValue(self._thumb_zoom)
        self.thumb_zoom_slider.valueChanged.connect(self._set_thumb_zoom)
        t_row.addWidget(self.thumb_zoom_slider, 1)
        t_in = QPushButton("+")
        t_in.setObjectName("PreviewToggle")
        t_in.setFixedWidth(24)
        t_in.setFocusPolicy(Qt.NoFocus)
        t_in.clicked.connect(lambda: self._set_thumb_zoom(self._thumb_zoom + 10))
        t_row.addWidget(t_in)
        self.thumb_zoom_lbl = hint(f"{self._thumb_zoom}%")
        t_row.addWidget(self.thumb_zoom_lbl)
        t_reset = QPushButton("Reset")
        t_reset.setObjectName("PreviewToggle")
        t_reset.setFocusPolicy(Qt.NoFocus)
        t_reset.clicked.connect(lambda: self._set_thumb_zoom(100))
        t_row.addWidget(t_reset)
        self.photos_sec.add_layout(t_row)

        brow = QGridLayout()
        brow.setSpacing(4)
        add_btn = QPushButton("Add photos…")
        add_btn.clicked.connect(self._add_photos)
        open_btn = QPushButton("Open published…")
        open_btn.setToolTip("Bring an existing site post and its bucket into COLD TAKE")
        open_btn.clicked.connect(self._open_published)
        open_draft_btn = QPushButton("Open draft…")
        open_draft_btn.setToolTip("Open a saved local draft from your COLD TAKE session")
        open_draft_btn.clicked.connect(self._open_draft)
        ai_btn = QPushButton("✨ AI FILL")
        ai_btn.setToolTip("Uses this site's prompt to fill all supported metadata: "
                          "post fields from the lead photo and ALT for every photo.")
        ai_btn.clicked.connect(self._ai_fill)
        for b in (add_btn, open_btn, open_draft_btn, ai_btn):
            b.setStyleSheet("padding: 5px 7px; font-size: 11px;")
        brow.addWidget(add_btn, 0, 0)
        brow.addWidget(ai_btn, 0, 1)
        brow.addWidget(open_btn, 1, 0)
        brow.addWidget(open_draft_btn, 1, 1)
        self.photos_sec.add_layout(brow)

        insert_row = QHBoxLayout()
        insert_row.setContentsMargins(0, 2, 0, 2)
        insert_row.setSpacing(6)
        self.insert_into_post_btn = big_button("INSERT INTO POST")
        self.insert_into_post_btn.setToolTip("Place the selected photo into the post at the cursor")
        self.insert_into_post_btn.clicked.connect(self.insert_selected_into_post)
        self.insert_into_post_btn.setVisible(False)
        insert_row.addWidget(self.insert_into_post_btn)
        self.insert_mosaic_btn = big_button("INSERT MOSAIC")
        self.insert_mosaic_btn.setToolTip("Insert the selected photos as a mosaic block at the cursor")
        self.insert_mosaic_btn.clicked.connect(lambda: self.insert_selected_mosaic_into_post())
        self.insert_mosaic_btn.setVisible(False)
        insert_row.addWidget(self.insert_mosaic_btn)
        self.photos_sec.add_layout(insert_row)

        # "In mosaics — N" summary bar (shown when photos are placed in mosaics)
        self.in_mosaics_bar = QFrame()
        self.in_mosaics_bar.setObjectName("InMosaicsBar")
        im_row = QHBoxLayout(self.in_mosaics_bar)
        im_row.setContentsMargins(8, 5, 8, 5)
        im_row.setSpacing(6)
        self.in_mosaics_lbl = QLabel("In mosaics — 0")
        self.in_mosaics_lbl.setObjectName("BlockTrayGroup")
        im_row.addWidget(self.in_mosaics_lbl)
        self.in_mosaics_thumbs = QHBoxLayout()
        self.in_mosaics_thumbs.setSpacing(2)
        im_row.addLayout(self.in_mosaics_thumbs)
        im_row.addStretch(1)
        self.in_mosaics_bar.setVisible(False)
        self.photos_sec.add(self.in_mosaics_bar)

        self.photos_sec.add(
            hint("★ FEATURED = landing tile on the site (e.g. TILEZ). Right-click any photo or click Featured photo."))
        self.bucket_count = hint("")   # its own line — AI-ALT progress / status only
        self.photos_sec.add(self.bucket_count)

        self.bucket_host = QWidget()
        self.bucket_host.installEventFilter(self)
        self.bucket_col = QGridLayout(self.bucket_host)
        self.bucket_col.setContentsMargins(0, 2, 0, 2)
        self.bucket_col.setSpacing(6)
        self.photos_sec.add(self.bucket_host)
        self._build_photo_tools()

        # No trailing stretch — the compose card (stretch 1 above) fills the
        # height itself, so there is no dead void beneath it.
        scroll.setWidget(host)

        # Primary action + collapsible Post details (Ctrl+3) + Preview + PAGE zoom
        self._compose_details = compose_details
        details_open = self._view_settings.value("take/details_open", False, type=bool)
        self._compose_details.setVisible(details_open)

        act = QHBoxLayout()
        act.setContentsMargins(14, 6, 14, 10)
        act.setSpacing(6)
        self.act_layout = act
        self.details_toggle_btn = QPushButton()
        self.details_toggle_btn.setObjectName("PreviewToggle")
        self.details_toggle_btn.setCheckable(True)
        self.details_toggle_btn.setChecked(details_open)
        self.details_toggle_btn.setFocusPolicy(Qt.NoFocus)
        self.details_toggle_btn.setToolTip("Show or hide Tags & Status (Ctrl+3)")
        self.details_toggle_btn.toggled.connect(self._toggle_post_details)
        QShortcut(QKeySequence("Ctrl+3"), self).activated.connect(
            self.details_toggle_btn.toggle)
        QShortcut(QKeySequence("Ctrl+0"), self).activated.connect(self._reset_hovered_zoom)
        QShortcut(QKeySequence("Ctrl+="), self).activated.connect(
            lambda: self._set_page_zoom(self._page_zoom + 10))
        QShortcut(QKeySequence("Ctrl++"), self).activated.connect(
            lambda: self._set_page_zoom(self._page_zoom + 10))
        QShortcut(QKeySequence("Ctrl+-"), self).activated.connect(
            lambda: self._set_page_zoom(self._page_zoom - 10))
        act.addWidget(self.details_toggle_btn)

        self.page_state_lbl = hint("1 block")
        self.page_state_lbl.setWordWrap(False)
        act.addWidget(self.page_state_lbl)
        self.save_state = hint("Begin the story… changes will be safe on this computer.")
        self.save_state.setWordWrap(False)
        self.save_state.setSizePolicy(QSizePolicy.Ignored, QSizePolicy.Preferred)
        act.addWidget(self.save_state, 1)

        self.save_btn = QPushButton("Save as draft")
        self.save_btn.clicked.connect(lambda: self._save(ready=False))
        act.addWidget(self.save_btn)
        clear_btn = QPushButton("Clear")
        clear_btn.setObjectName("Quiet")
        clear_btn.clicked.connect(self._clear)
        act.addWidget(clear_btn)
        self.queue_btn = big_button("QUEUE POST")
        self.queue_btn.setMaximumWidth(180)
        self.queue_btn.setToolTip("Add this post to the batch. Nothing publishes until SEND.")
        self.queue_btn.clicked.connect(lambda: self._save(ready=True))
        act.addWidget(self.queue_btn)

        act.addSpacing(4)
        self._preview_hint_lbl = hint("Preview")
        self._preview_hint_lbl.setWordWrap(False)
        act.addWidget(self._preview_hint_lbl)
        self.preview_desktop_btn = QPushButton("Desktop 760")
        self.preview_phone_btn = QPushButton("Phone 360")
        for btn, w in ((self.preview_desktop_btn, 760), (self.preview_phone_btn, 360)):
            btn.setObjectName("PreviewToggle")
            btn.setCheckable(True)
            btn.setFocusPolicy(Qt.NoFocus)
            btn.clicked.connect(lambda _=False, width=w: self._set_preview_width(width))
            act.addWidget(btn)
        self.preview_desktop_btn.setChecked(True)

        act.addSpacing(4)
        self._page_hint_lbl = hint("PAGE")
        self._page_hint_lbl.setWordWrap(False)
        act.addWidget(self._page_hint_lbl)
        self._page_zoom = int(self._view_settings.value("take/page_zoom", 100))
        zoom_out = QPushButton("-")
        zoom_out.setObjectName("PreviewToggle")
        zoom_out.setFixedWidth(26)
        zoom_out.setFocusPolicy(Qt.NoFocus)
        zoom_out.clicked.connect(lambda: self._set_page_zoom(self._page_zoom - 10))
        act.addWidget(zoom_out)
        self.page_zoom_slider = QSlider(Qt.Horizontal)
        self.page_zoom_slider.setRange(70, 160)
        self.page_zoom_slider.setSingleStep(10)
        self.page_zoom_slider.setFixedWidth(72)
        self.page_zoom_slider.setValue(self._page_zoom)
        self.page_zoom_slider.valueChanged.connect(self._set_page_zoom)
        act.addWidget(self.page_zoom_slider)
        zoom_in = QPushButton("+")
        zoom_in.setObjectName("PreviewToggle")
        zoom_in.setFixedWidth(26)
        zoom_in.setFocusPolicy(Qt.NoFocus)
        zoom_in.clicked.connect(lambda: self._set_page_zoom(self._page_zoom + 10))
        act.addWidget(zoom_in)
        self.page_zoom_lbl = hint(f"{self._page_zoom}%")
        self.page_zoom_lbl.setWordWrap(False)
        act.addWidget(self.page_zoom_lbl)
        zoom_reset = QPushButton("Reset")
        zoom_reset.setObjectName("PreviewToggle")
        zoom_reset.setFocusPolicy(Qt.NoFocus)
        zoom_reset.clicked.connect(lambda: self._set_page_zoom(100))
        act.addWidget(zoom_reset)

        self._sync_details_btn(details_open)

        self.ruler = _PageRuler(self._preview_width)
        self._set_page_zoom(self._page_zoom)
        self.focus_exit_btn = QPushButton("⛶ Exit focus  F11")
        self.focus_exit_btn.setObjectName("PreviewToggle")
        self.focus_exit_btn.setFocusPolicy(Qt.NoFocus)
        self.focus_exit_btn.setToolTip("Exit focus mode (F11 or Esc)")
        self.focus_exit_btn.clicked.connect(self.exitFocusRequested.emit)
        self.focus_exit_btn.setVisible(False)
        strip_lay = self.body.format_strip.layout()
        if strip_lay is not None:
            strip_lay.addWidget(self.focus_exit_btn)

        centre = QVBoxLayout()
        centre.setContentsMargins(0, 0, 0, 0)
        centre.setSpacing(0)
        centre.addWidget(self.body.format_strip)
        centre.addWidget(self.ruler)
        centre.addWidget(scroll, 1)
        centre.addWidget(compose_details)
        centre.addLayout(act)
        self.blocks_tray = BlockTray()
        self.blocks_tray.stateChanged.connect(self._blocks_state_changed)
        self.body.faceChanged.connect(self._refresh_block_actions)
        self._refresh_block_actions(self.body.is_biggie())
        outer.addWidget(self.blocks_tray)
        outer.addLayout(centre, 1)

        # The rail: collapsible right photos + batch tray (Ctrl+2), default closed.
        self.right_rail = build_rail(
            [self.photos_sec, self.rail.section],
            [self.rail.send_box], width=360)
        self.photos_tray = RightPhotosTray(self.right_rail)
        self.photos_tray.stateChanged.connect(self._on_photos_tray_changed)
        self.photos_tray.deletePressed.connect(lambda: self._remove_from_tray())
        photos_open = self._view_settings.value("take/photos_open", False, type=bool)
        self.photos_tray.set_open(photos_open)
        outer.addWidget(self.photos_tray)
        self._refresh_bucket()
        self._autosave = QTimer(self)
        self._autosave.setSingleShot(True)
        self._autosave.setInterval(900)
        self._autosave.timeout.connect(self._autosave_now)
        # Thumbnails are baked off the UI thread, one job at a time.
        self._thumb_executor = None
        self._thumb_job = None
        self._thumbsReady.connect(self._apply_thumb_results)
        self._slapper_timer = QTimer(self)
        self._slapper_timer.setInterval(1000)
        self._slapper_timer.timeout.connect(self._poll_slapper_return)
        self.body.changed.connect(self._changed)
        self.body.changed.connect(self._update_page_chrome)
        self.body.canvas.cursorPositionChanged.connect(self._update_page_chrome)
        self.title_edit.textChanged.connect(self._changed)
        self.colophon_edit.textChanged.connect(self._changed)
        self.signature_check.toggled.connect(self._changed)
        self.tags_edit.textChanged.connect(self._changed)
        self._update_page_chrome()
        QTimer.singleShot(0, self._recover_last_draft)
        QTimer.singleShot(0, self._sync_block_tray_width)

    def _sync_details_btn(self, opened: bool):
        arrow = "▼" if opened else "▲"
        window = self.window()
        compact = bool(window and window.width() <= 1366)
        label = f"{arrow} Post details" if compact else f"{arrow} Post details && queue  Ctrl+3"
        self.details_toggle_btn.setText(label)

    def photos_open(self) -> bool:
        return self.photos_tray.is_open()

    def set_photos_open(self, opened: bool):
        self.photos_tray.set_open(opened)

    def toggle_photos(self):
        self.photos_tray.toggle()

    def _on_photos_tray_changed(self, opened: bool):
        self._view_settings.setValue("take/photos_open", bool(opened))
        self.photosStateChanged.emit(bool(opened))
        QTimer.singleShot(0, self._fit_page_card_width)

    def _reset_hovered_zoom(self):
        if hasattr(self, "bucket_host") and self.bucket_host.underMouse():
            self._set_thumb_zoom(100)
        else:
            self._set_page_zoom(100)

    def _set_thumb_zoom(self, pct: int):
        pct = max(60, min(150, int(pct or 100)))
        if getattr(self, "_thumb_zoom", 100) == pct and hasattr(self, "thumb_zoom_lbl"):
            return
        self._thumb_zoom = pct
        self._view_settings.setValue("take/thumb_zoom", pct)
        if hasattr(self, "thumb_zoom_slider"):
            self.thumb_zoom_slider.blockSignals(True)
            self.thumb_zoom_slider.setValue(pct)
            self.thumb_zoom_slider.blockSignals(False)
        if hasattr(self, "thumb_zoom_lbl"):
            self.thumb_zoom_lbl.setText(f"{pct}%")
        self._refresh_bucket()

    def eventFilter(self, obj, event):
        if obj is self.body.editor.viewport():
            from .photo_tray import TRAY_PHOTO_MIME
            if event.type() in (event.Type.DragEnter, event.Type.DragMove):
                if event.mimeData().hasFormat(TRAY_PHOTO_MIME):
                    event.acceptProposedAction()
                    return True
            if event.type() == event.Type.Drop:
                if event.mimeData().hasFormat(TRAY_PHOTO_MIME):
                    import json as _json
                    try:
                        data = _json.loads(bytes(event.mimeData().data(TRAY_PHOTO_MIME)).decode("utf-8"))
                    except Exception:
                        data = {}
                    cursor = self.body.editor.cursorForPosition(event.position().toPoint())
                    self.body.editor.setTextCursor(cursor)
                    self._drop_tray_photo_twiggy(
                        str(data.get("asset_uuid") or ""),
                        data.get("index"),
                    )
                    event.acceptProposedAction()
                    return True
        if event.type() == event.Type.Wheel and (event.modifiers() & Qt.ControlModifier):
            delta = 10 if event.angleDelta().y() > 0 else -10
            if obj is getattr(self, "bucket_host", None):
                self._set_thumb_zoom(self._thumb_zoom + delta)
                return True
            if obj in (
                getattr(self, "_canvas_scroll", None) and self._canvas_scroll.viewport(),
                self.body.canvas.viewport(),
                self.body.editor.viewport(),
            ):
                self._set_page_zoom(self._page_zoom + delta)
                return True
        return super().eventFilter(obj, event)

    def details_open(self) -> bool:
        return bool(self.details_toggle_btn.isChecked())

    def set_details_open(self, opened: bool):
        opened = bool(opened)
        if self.details_toggle_btn.isChecked() != opened:
            self.details_toggle_btn.setChecked(opened)
        else:
            self._toggle_post_details(opened)

    def _toggle_post_details(self, opened: bool):
        opened = bool(opened)
        self._compose_details.setVisible(opened)
        self._view_settings.setValue("take/details_open", opened)
        self._sync_details_btn(opened)
        self.detailsStateChanged.emit(opened)

    def _set_page_zoom(self, pct: int):
        pct = max(70, min(160, int(pct or 100)))
        self._page_zoom = pct
        self._view_settings.setValue("take/page_zoom", pct)
        if hasattr(self, "page_zoom_slider"):
            self.page_zoom_slider.blockSignals(True)
            self.page_zoom_slider.setValue(pct)
            self.page_zoom_slider.blockSignals(False)
        if hasattr(self, "page_zoom_lbl"):
            self.page_zoom_lbl.setText(f"{pct}%")
        self._fit_page_card_width()

    @staticmethod
    def resolve_site_colors(site_url: str) -> tuple:
        """Return (bg_hex, fg_hex, available_bool) for a site, falling back to white/dark."""
        from PySide6.QtGui import QColor
        url = str(site_url or "").strip()
        bg, fg, available = "", "", False
        if url:
            try:
                import snap_library
                for key in ("site_bg", "page_bg", "background_color", "bg_color"):
                    val = str(snap_library.meta(url, key, "") or "").strip()
                    if val and QColor(val).isValid():
                        bg = QColor(val).name()
                        available = True
                        break
                fg_val = str(snap_library.meta(url, "site_fg", "") or "").strip()
                if fg_val and QColor(fg_val).isValid():
                    fg = QColor(fg_val).name()
            except Exception:  # noqa: BLE001
                pass
        if not bg:
            return "#ffffff", "#1a1a1a", False
        return bg, fg, available

    @staticmethod
    def resolve_site_bg_color(site_url: str) -> str:
        return TakeMode.resolve_site_colors(site_url)[0]

    def apply_canvas_background(self, site_url: str = "", explicit_bg: str = "", explicit_fg: str = "") -> str:
        import json
        from PySide6.QtGui import QColor, QPalette
        from . import canvas as canvas_mod
        from . import skin_css

        url = str(site_url or "").strip()
        sybu_skin = {}
        if url:
            try:
                raw_skin = snap_library.meta(url, "skin_json", "")
                if raw_skin:
                    sybu_skin = skin_css.parse_sybu_skin(json.loads(raw_skin))
            except Exception:  # noqa: BLE001
                sybu_skin = {}

        cached_skin = skin_css._SITE_CACHE.get(url) if url else None
        css_loaded = bool(cached_skin and cached_skin.get("loaded"))

        # Authoritative sybu-data values win whenever declared; CSS parse fills gaps.
        eff_bg = sybu_skin.get("bg") or (cached_skin.get("bg") if css_loaded else "")
        eff_fg = sybu_skin.get("fg") or (cached_skin.get("fg") if css_loaded else "")
        eff_font = sybu_skin.get("body_font") or (cached_skin.get("body_font") if css_loaded else "")
        eff_pt = float((cached_skin.get("body_pt") if css_loaded else 0.0) or 0.0)
        eff_lh = float((cached_skin.get("line_height_pct") if css_loaded else 0.0) or 0.0)
        eff_pmb = float((cached_skin.get("para_margin_bottom_px") if css_loaded else 0.0) or 0.0)
        eff_imt = float((cached_skin.get("image_margin_top_px") if css_loaded else 0.0) or 0.0)
        eff_imb = float((cached_skin.get("image_margin_bottom_px") if css_loaded else 0.0) or 0.0)
        eff_cap_font = sybu_skin.get("caption_font") or (cached_skin.get("caption_font") if css_loaded else "")
        eff_cap_pt = float(sybu_skin.get("caption_pt") or (cached_skin.get("caption_pt") if css_loaded else 0.0) or 0.0)
        eff_cap_color = sybu_skin.get("caption_color") or (cached_skin.get("caption_color") if css_loaded else "")
        eff_cap_italic = (
            sybu_skin["caption_italic"] if sybu_skin.get("caption_italic") is not None
            else (bool(cached_skin.get("caption_italic", True)) if css_loaded else True)
        )
        eff_cap_align = sybu_skin.get("caption_align") or (cached_skin.get("caption_align") if css_loaded else "") or ""
        decl_width = int(sybu_skin.get("post_content_width") or (cached_skin.get("container_width") if css_loaded else 0) or 0)
        if decl_width > 0 and getattr(self, "_preview_width", 760) != 360:
            self._preview_width = decl_width
            if hasattr(self, "preview_desktop_btn"):
                self.preview_desktop_btn.setText(f"Desktop {decl_width}")
            if hasattr(self, "_fit_page_card_width"):
                self._fit_page_card_width()

        note_msg = ""
        if explicit_bg and QColor(explicit_bg.strip()).isValid():
            bg = QColor(explicit_bg.strip()).name()
            fg = QColor(explicit_fg.strip()).name() if explicit_fg and QColor(explicit_fg.strip()).isValid() else ""
            available = True
        elif eff_bg or eff_fg or eff_font or eff_cap_color or eff_cap_pt or eff_cap_font or eff_cap_align or css_loaded:
            fallback_bg, fallback_fg, _ = self.resolve_site_colors(url)
            bg = eff_bg or fallback_bg
            fg = eff_fg or fallback_fg
            if hasattr(self, "body") and hasattr(self.body, "canvas"):
                self.body.canvas.set_skin_typography(
                    body_font=eff_font,
                    body_pt=eff_pt,
                    caption_font=eff_cap_font,
                    caption_pt=eff_cap_pt,
                    caption_color=eff_cap_color,
                    caption_italic=eff_cap_italic,
                    caption_align=eff_cap_align,
                    line_height_pct=eff_lh,
                    para_margin_bottom=eff_pmb,
                    image_margin_top=eff_imt,
                    image_margin_bottom=eff_imb,
                )
            if sybu_skin.get("has_sybu") and (not eff_bg or not eff_font) and not css_loaded:
                available = False
                note_msg = "approximate: skin styles not loaded"
            elif sybu_skin.get("has_sybu") and (not sybu_skin.get("bg") or not sybu_skin.get("body_font")) and css_loaded:
                available = False
                note_msg = "approximate: undeclared skin styles read from CSS fallback"
            else:
                available = True
        else:
            bg, fg, available = self.resolve_site_colors(url)
            note_msg = "approximate: skin styles not loaded"

        if not css_loaded and url.startswith(("http://", "https://")) and getattr(self, "_skin_fetch_inflight", None) != url:
            self._skin_fetch_inflight = url

            def _bg_fetch(u=url):
                res = skin_css.fetch_site_skin_styles(u)
                if res.get("loaded"):
                    try:
                        import shiboken6
                        if shiboken6.isValid(self):
                            QMetaObject.invokeMethod(
                                self, "_on_skin_styles_loaded", Qt.QueuedConnection
                            )
                    except Exception:  # noqa: BLE001
                        pass

            if not hasattr(self, "_skin_executor") or self._skin_executor is None:
                self._skin_executor = ThreadPoolExecutor(max_workers=1, thread_name_prefix="coldtake-skin")
            self._skin_executor.submit(_bg_fetch)

        if hasattr(self, "site_colors_note") and note_msg:
            self.site_colors_note.setText(note_msg)

        pal = canvas_mod.site_surface_palette(bg, fg)
        bg = pal["bg"]
        self._canvas_bg_color = bg
        self._surface_palette = pal

        if hasattr(self, "_canvas_scroll"):
            self._canvas_scroll.setStyleSheet(f"QScrollArea#CanvasWell {{ background: {bg}; border: none; }}")
        if hasattr(self, "_canvas_host"):
            self._canvas_host.setStyleSheet(f"QWidget#CanvasWell {{ background: {bg}; }}")
        if hasattr(self, "page_card"):
            if self.page_card.property("textView") is None:
                self.page_card.setProperty("textView", False)
            self.page_card.setStyleSheet(
                f'QFrame#PageCard[textView="false"] {{ background: {bg}; border-radius: 6px; }}'
                'QFrame#PageCard[textView="true"] { background: #141914; border: 1px solid #2b3a29; border-radius: 6px; }'
            )
        if hasattr(self, "page_eyebrow"):
            self.page_eyebrow.setStyleSheet(
                f"QLabel#PageEyebrow {{ background: transparent; color: {pal['eyebrow']};"
                " font-size: 11px; font-weight: 700; letter-spacing: 1.4px; }"
            )
        if hasattr(self, "title_edit"):
            self.title_edit.setStyleSheet(
                f"QLineEdit#PageTitle {{ background: transparent; color: {pal['heading']}; border: none;"
                " padding: 2px 0px; font-size: 26px; font-weight: 800; }"
            )
            tp = self.title_edit.palette()
            tp.setColor(QPalette.PlaceholderText, QColor(pal["placeholder"]))
            tp.setColor(QPalette.Text, QColor(pal["heading"]))
            self.title_edit.setPalette(tp)
        if hasattr(self, "page_meta"):
            self.page_meta.setStyleSheet(
                f"QLabel#PageMeta {{ background: transparent; color: {pal['muted']}; font-size: 12px; }}"
            )
        if hasattr(self, "linked_post_lbl"):
            rid = int(getattr(self, "_remote_post_id", 0) or 0)
            if rid:
                t = self.title_edit.text().strip() or f"Post #{rid}"
                slug = str(getattr(self, "_remote_slug", "") or "").strip().lstrip("/")
                loc = f"/{slug}" if slug else f"#{rid}"
                self.linked_post_lbl.setText(f"Editing: {t}  {loc}")
                self.linked_post_lbl.setStyleSheet(
                    f"QLabel#LinkedPostStatus {{ background: transparent; color: {pal['eyebrow']};"
                    " font-size: 11px; font-weight: 700; }}"
                )
                self.linked_post_lbl.setVisible(True)
            else:
                self.linked_post_lbl.setVisible(False)
        if hasattr(self, "site_colors_note"):
            self.site_colors_note.setStyleSheet(
                f"QLabel#SiteColorsNote {{ background: transparent; color: {pal['muted']}; font-size: 11px; font-style: italic; }}"
            )
            self.site_colors_note.setVisible(not available)
        if hasattr(self, "gutter_tag"):
            self.gutter_tag.setStyleSheet(
                f"QLabel#BlockGutterTag {{ background: transparent; color: {pal['eyebrow']};"
                " font-size: 10px; font-weight: 700; letter-spacing: 0.8px; }"
            )
        if hasattr(self, "body") and hasattr(self.body, "apply_surface_palette"):
            self.body.apply_surface_palette(pal)
        return bg

    @Slot()
    def _on_skin_styles_loaded(self):
        cfg = self.app_config() or {}
        url = (cfg.get("url") or "").strip()
        self._skin_fetch_inflight = None
        if url:
            self.apply_canvas_background(url)

    def _fit_page_card_width(self):
        pct = getattr(self, "_page_zoom", 100)
        desired_inner = int((self._preview_width * pct) / 100.0)
        desired_card = desired_inner + 80
        scroll = getattr(self, "_canvas_scroll", None)
        if scroll is not None and scroll.viewport().width() > 0:
            vp_w = scroll.viewport().width()
            gutter_w = self.gutter_tag.width() + 8 if hasattr(self, "gutter_tag") else 40
            m = self._well_layout.contentsMargins() if hasattr(self, "_well_layout") else None
            lr = (m.left() + m.right()) if m is not None else 32
            # 24px buffer keeps CanvasWell horizontalScrollBar().maximum() == 0
            # even when the vertical scrollbar is present.
            avail_card = max(280, vp_w - gutter_w - lr - 24)
            target_card = min(desired_card, avail_card)
        else:
            target_card = desired_card
        inner_w = max(200, target_card - 80)
        self.page_card.setFixedWidth(target_card)
        self.ruler.set_preview_width(inner_w)
        self.body.canvas.set_preview_width(self._preview_width)
        self.body.canvas.set_zoom_scale(inner_w / float(max(1, self._preview_width)))

    def _set_preview_width(self, width: int):
        self._preview_width = int(width or 760)
        self.preview_desktop_btn.setChecked(self._preview_width == 760)
        self.preview_phone_btn.setChecked(self._preview_width == 360)
        self._set_page_zoom(getattr(self, "_page_zoom", 100))

    def _update_page_chrome(self):
        status = (self.status_combo.currentText() or "draft").strip().upper()
        visual = self.body.is_biggie()
        self.page_eyebrow.setText(
            f"ESSAY · {status}" if visual else f"ESSAY · {status} — TEXT VIEW")
        site = ((self.app_config() or {}).get("url") or "").strip()
        for prefix in ("https://", "http://"):
            if site.startswith(prefix):
                site = site[len(prefix):]
        site = site.rstrip("/") or "local draft"
        if self.body.is_biggie():
            doc = self.body.canvas.document()
            plain = self.body.canvas.toPlainText()
            blocks = 0 if (doc.blockCount() <= 1 and not plain.strip() and "\ufffc" not in plain) else doc.blockCount()
        else:
            blocks = len([ln for ln in self.body.editor.toPlainText().splitlines() if ln.strip()])
        from datetime import date
        self.page_meta.setText(f"{date.today().isoformat()} · {site} · {blocks} block{'s' if blocks != 1 else ''}")
        if hasattr(self, "page_state_lbl"):
            self.page_state_lbl.setText(f"{blocks} block{'s' if blocks != 1 else ''}")
        if self.body.is_biggie():
            self.gutter_tag.setText(self.body.canvas.current_block_tag())
            self.gutter_tag.setVisible(True)
            self.add_block_btn.setVisible(True)
        else:
            self.gutter_tag.setVisible(False)
            self.add_block_btn.setVisible(False)

    def _build_add_block_menu(self):
        from PySide6.QtWidgets import QMenu
        menu = QMenu(self)
        c = self.body.canvas
        sel_pos = c._selected_block_pos if c._selected_block_pos is not None else c._selected_obj_pos
        target_block_pos = (
            int(sel_pos) if sel_pos is not None
            else c.textCursor().block().position()
        )
        on_object = bool(
            (sel_pos is not None and c._object_at(sel_pos) is not None)
            or c._object_at(target_block_pos) is not None
        )
        if on_object:
            menu.addAction("Paragraph before", lambda bp=target_block_pos: c.insert_block_at_cursor("para", position="before", block_pos=bp))
            menu.addAction("Paragraph after", lambda bp=target_block_pos: c.insert_block_at_cursor("para", position="after", block_pos=bp))
            menu.addSeparator()
        for label, cb in (
            ("Paragraph", lambda bp=target_block_pos: c.insert_block_at_cursor("para", position="after", block_pos=bp)),
            ("Heading", lambda bp=target_block_pos: c.insert_block_at_cursor("h2", position="after", block_pos=bp)),
            ("Subhead", lambda bp=target_block_pos: c.insert_block_at_cursor("h3", position="after", block_pos=bp)),
            ("Quote", lambda bp=target_block_pos: c.insert_block_at_cursor("quote", position="after", block_pos=bp)),
            ("Pull quote", lambda bp=target_block_pos: c.insert_block_at_cursor("pullquote", position="after", block_pos=bp)),
            ("Drop cap", lambda bp=target_block_pos: c.insert_block_at_cursor("para", position="after", dropcap=True, block_pos=bp)),
            ("Bullet list", lambda bp=target_block_pos: c.insert_block_at_cursor("para", position="after", ordered_list=False, block_pos=bp)),
            ("Numbered list", lambda bp=target_block_pos: c.insert_block_at_cursor("para", position="after", ordered_list=True, block_pos=bp)),
            ("Divider", c.insert_hr),
            ("Space", c._edit_spacer),
            ("Mosaic", self._insert_mosaic),
            ("Photograph", self._add_and_insert_photo),
            ("Photo from tray", self._pick_tray_photo),
            ("2 columns", lambda: c.insert_columns(2)),
            ("3 columns", lambda: c.insert_columns(3)),
            ("Raw", lambda bp=target_block_pos: c.insert_block_at_cursor("raw", position="after", block_pos=bp)),
        ):
            menu.addAction(label, cb)
        return menu

    def _show_add_block_menu(self):
        menu = self._build_add_block_menu()
        menu.exec(self.add_block_btn.mapToGlobal(self.add_block_btn.rect().bottomLeft()))

    def blocks_open(self) -> bool:
        return self.blocks_tray.is_open

    def blocks_header_label(self) -> str:
        return "BLOCKS" if self.body.is_biggie() else "SHORTCODES"

    def set_blocks_open(self, opened: bool):
        self.blocks_tray.set_open(opened)
        self.blocksStateChanged.emit(self.blocks_open(), self.blocks_header_label())

    def toggle_blocks(self):
        self.blocks_tray.toggle_open()
        self.blocksStateChanged.emit(self.blocks_open(), self.blocks_header_label())

    def _blocks_state_changed(self, opened: bool, _pinned: bool):
        self.blocksStateChanged.emit(opened, self.blocks_header_label())
        QTimer.singleShot(0, self._fit_page_card_width)

    def _sync_block_tray_width(self):
        window = self.window()
        w = window.width() if window else 1920
        compact = w <= 1366
        tight = w <= 1100
        self.blocks_tray.set_compact_window(compact)
        if hasattr(self, "photos_tray"):
            self.photos_tray.set_compact_window(compact)
        if hasattr(self, "details_toggle_btn"):
            self._sync_details_btn(self.details_toggle_btn.isChecked())
        if hasattr(self, "_preview_hint_lbl"):
            self._preview_hint_lbl.setVisible(not compact)
        if hasattr(self, "save_state"):
            self.save_state.setVisible(w > 1480)
        if hasattr(self, "page_zoom_slider"):
            self.page_zoom_slider.setFixedWidth(50 if tight else (58 if compact else 72))
        if hasattr(self, "act_layout"):
            self.act_layout.setContentsMargins(8 if tight else 12, 6, 8 if tight else 12, 8)
            self.act_layout.setSpacing(4 if tight else 6)
        if hasattr(self, "save_btn"):
            self.save_btn.setText("Save" if tight else "Save as draft")
        if hasattr(self, "queue_btn"):
            self.queue_btn.setText("QUEUE" if tight else "QUEUE POST")
        if hasattr(self, "preview_desktop_btn"):
            self.preview_desktop_btn.setText("760" if tight else "Desktop 760")
        if hasattr(self, "preview_phone_btn"):
            self.preview_phone_btn.setText("360" if tight else "Phone 360")
        if hasattr(self, "gutter_tag"):
            self.gutter_tag.setFixedWidth(32 if tight else 52)
        if hasattr(self, "_well_layout"):
            self._well_layout.setContentsMargins(6 if tight else 16, 12, 6 if tight else 16, 18)
        self._fit_page_card_width()
        QTimer.singleShot(0, self._fit_page_card_width)

    def resizeEvent(self, event):
        super().resizeEvent(event)
        self._sync_block_tray_width()

    def closeEvent(self, event):
        if hasattr(self, "_autosave"):
            self._autosave.stop()
        if hasattr(self, "_slapper_timer"):
            self._slapper_timer.stop()
        self._thumb_job = None
        super().closeEvent(event)

    def _refresh_block_actions(self, visual: bool):
        self.page_card.setProperty("textView", not visual)
        self.page_card.style().unpolish(self.page_card)
        self.page_card.style().polish(self.page_card)
        if hasattr(self, "text_view_hint"):
            self.text_view_hint.setVisible(not visual)
        self.blocks_tray.set_actions(
            "BLOCKS" if visual else "SHORTCODES",
            self._visual_block_actions() if visual else self._text_block_actions())
        self._update_page_chrome()
        self.blocksStateChanged.emit(self.blocks_open(), self.blocks_header_label())

    def _visual_block_actions(self):
        canvas = self.body.canvas
        return [
            BlockAction("P", "Paragraph", "WRITE", "Plain paragraph",
                        lambda: canvas.set_kind("para")),
            BlockAction("H", "Heading", "WRITE", "Large heading",
                        lambda: canvas.set_kind("h2")),
            BlockAction("SH", "Subhead", "WRITE", "Smaller heading",
                        lambda: canvas.set_kind("h3")),
            BlockAction("Q", "Quote", "WRITE", "Quoted passage",
                        lambda: canvas.set_kind("quote")),
            BlockAction("PQ", "Pull quote", "WRITE", "Words lifted out large",
                        lambda: canvas.set_kind("pullquote")),
            BlockAction("DC", "Drop cap", "WRITE", "A large first letter",
                        canvas.toggle_dropcap),
            BlockAction("BUL", "Bullets", "WRITE", "Bullet list",
                        lambda: canvas.set_list(False)),
            BlockAction("NUM", "Numbers", "WRITE", "Numbered list",
                        lambda: canvas.set_list(True)),
            BlockAction("DIV", "Divider", "PLACE", "A divider line", canvas.insert_hr),
            BlockAction("SP", "Space", "PLACE", "A vertical gap", canvas._edit_spacer),
            BlockAction("RAW", "Raw", "PLACE", "Shortcodes or HTML kept exactly as typed",
                        lambda: canvas.set_kind("raw")),
            BlockAction("MSC", "Mosaic", "PLACE", "Build a mosaic from this post's photos",
                        self._insert_mosaic),
            BlockAction("PHO", "Photograph", "PLACE", "Choose a local photograph",
                        self._add_and_insert_photo),
            BlockAction("2C", "2 columns", "PLACE", "Two columns side by side",
                        lambda: canvas.insert_columns(2)),
            BlockAction("3C", "3 columns", "PLACE", "Three columns",
                        lambda: canvas.insert_columns(3)),
            BlockAction("IMG", "Image", "PLACE", "Choose an unplaced photo from this post",
                        self._pick_tray_photo),
        ]

    def _text_block_actions(self):
        bar = self.body.bar
        return [
            BlockAction("H2", "Heading 2", "WRITE", "<h2>…</h2>",
                        lambda: bar._wrap("<h2>", "</h2>")),
            BlockAction("H3", "Heading 3", "WRITE", "<h3>…</h3>",
                        lambda: bar._wrap("<h3>", "</h3>")),
            BlockAction("BQ", "Blockquote", "WRITE", "<blockquote>…</blockquote>",
                        lambda: bar._wrap("<blockquote>", "</blockquote>")),
            BlockAction("PULL", "Pull quote", "WRITE", "[pullquote]…[/pullquote]",
                        lambda: bar._wrap("[pullquote]", "[/pullquote]")),
            BlockAction("HR", "Divider", "PLACE", "<hr>",
                        lambda: bar._wrap("\n<hr>\n")),
            BlockAction("UL", "Bullets", "WRITE", "<ul><li>…</li></ul>",
                        lambda: bar._list("ul")),
            BlockAction("OL", "Numbers", "WRITE", "<ol><li>…</li></ol>",
                        lambda: bar._list("ol")),
            BlockAction("IMG", "Image", "PLACE", "Choose an unplaced photo from this post",
                        self._pick_tray_photo),
            BlockAction("COL 2", "2 columns", "PLACE", "[columns=2]…[/columns]",
                        lambda: bar._columns(2)),
            BlockAction("COL 3", "3 columns", "PLACE", "[columns=3]…[/columns]",
                        lambda: bar._columns(3)),
            BlockAction("DROP", "Drop cap", "WRITE", "[dropcap]…[/dropcap]",
                        lambda: bar._wrap("[dropcap]", "[/dropcap]")),
            BlockAction("SPACER", "Space", "PLACE", "[spacer:20]", bar._spacer),
            BlockAction("MOSAIC", "Mosaic", "PLACE",
                        "[mosaic=1,2 layout=asymmetric] (built from the photo picker)",
                        self._insert_mosaic),
        ]

    def _recover_last_draft(self):
        """Reopen the newest safe, unfinished essay after a restart."""
        session = self.rail.session
        if session is None or self._editing_id:
            return
        candidates = [d for d in session.list_drafts() if d.status == O.ST_DRAFT]
        if not candidates:
            return
        latest = max(candidates, key=lambda d: (d.updated_at, d.draft_id))
        self._edit(latest)
        self.save_state.setText("Recovered your last safe draft from this computer")

    # -- rail rows -----------------------------------------------------------
    def _rows(self, session, refresh_cb):
        return [default_draft_row(session, d, refresh_cb, edit_cb=self._edit,
                                  subtitle=f"{len(d.images)} photo(s)")
                for d in session.list_drafts()]

    # -- compose -----------------------------------------------------------------
    @staticmethod
    def _mosaic_layouts(photo_count):
        """Only offer layouts the selected number of photos can actually use."""
        if photo_count <= 0:
            return []
        if photo_count == 1:
            return [("Single photo", "asymmetric")]
        if photo_count == 2:
            return [("Side by side", "asymmetric"),
                    ("Columns", "columns"), ("Rows", "rows")]
        if photo_count == 3:
            return [("One left, two right", "one-left"),
                    ("One right, two left", "one-right"),
                    ("Three across", "three-across"),
                    ("One top, two below", "one-top")]
        return [("Asymmetric quilt", "asymmetric"),
                ("Columns", "columns"), ("Rows", "rows"),
                ("Even square grid", "square")]

    @staticmethod
    def _image_shape(image):
        width, height = int(image.width or 0), int(image.height or 0)
        if not width or not height:
            try:
                from PIL import Image
                with Image.open(image.local_path) as source:
                    width, height = source.size
            except Exception:
                return "unknown", 1.0
        ratio = width / max(1, height)
        if ratio > 1.08:
            return "landscape", ratio
        if ratio < 0.92:
            return "portrait", ratio
        return "square", ratio

    def _insert_mosaic(self):
        """MOSAIC button. BIGGIE face: the canvas decides new-vs-edit and asks
        via mosaicEditRequested. TWIGGY face: the text marker, as always."""
        if self.body.is_biggie():
            if not self._bucket:
                QMessageBox.warning(self, "No photos", "Add photos before building a mosaic.")
                return
            self.body.canvas.request_mosaic()
            return
        if not self._bucket:
            QMessageBox.warning(self, "No photos", "Add photos before building a mosaic.")
            return

        # Pressing MOSAIC while the cursor is on an existing composed marker
        # edits that marker instead of blindly inserting another one.
        cursor = self.body.editor.textCursor()
        block = cursor.block()
        block_text = block.text()
        existing = next((match for match in biggie.MOSAIC_TOKEN.finditer(block_text) if match.group(1)), None)
        existing_span = None
        existing_order, existing_layout, existing_weights = [], None, {}
        rotation_originals = {}
        if existing:
            full_order = [int(value) - 1 for value in existing.group(1).split(',')]
            existing_order = [value for value in full_order if 0 <= value < len(self._bucket)]
            existing_layout = existing.group(2).lower()
            if existing.group(4) and len(existing_order) == len(full_order):
                self._pending_mosaic_mirrors = existing.group(4).split(",")
            if existing.group(3):
                for pair in existing.group(3).split(','):
                    k, v = pair.strip().split(':', 1)
                    bi = int(k) - 1
                    if 0 <= bi < len(self._bucket) and int(v) != 0:
                        existing_weights[bi] = int(v)
            existing_span = (block.position() + existing.start(),
                             block.position() + existing.end())

        preselected = [] if existing_order else self.selected_tray_indices()
        self._pending_mosaic_weights = dict(existing_weights)
        self._last_mosaic_weights = {}
        self._last_mosaic_mirrors = ()
        result = self._mosaic_dialog(existing_order, existing_layout, preselected_order=preselected)
        if not result:
            return
        chosen, layout_name = result
        chosen_weights = dict(getattr(self, "_last_mosaic_weights", {}) or {})
        marker = biggie.serialize_block({"type": "mosaic", "order": chosen, "layout": layout_name,
                                         "weights": chosen_weights, "mirrors": self._last_mosaic_mirrors})
        if existing_span:
            replace_cursor = self.body.editor.textCursor()
            replace_cursor.setPosition(existing_span[0])
            replace_cursor.setPosition(existing_span[1], QTextCursor.KeepAnchor)
            replace_cursor.insertText(marker)
            self.body.editor.setTextCursor(replace_cursor)
        else:
            self.body.editor.insertPlainText(marker)
        self._apply_mosaic_tray_edits()
        self.body.editor.setFocus()

    def _canvas_mosaic(self, order, layout):
        """The BIGGIE canvas wants a mosaic built (order == []) or changed."""
        if not self._bucket:
            QMessageBox.warning(self, "No photos", "Add photos before building a mosaic.")
            return
        uuid_to_index = {str(im.asset_uuid): i for i, im in enumerate(self._bucket)}
        existing_order = []
        for identity in (order or []):
            if str(identity) in uuid_to_index:
                existing_order.append(uuid_to_index[str(identity)])
            elif str(identity).isdigit() and 1 <= int(identity) <= len(self._bucket):
                existing_order.append(int(identity) - 1)
        existing_weights = {}
        if self.body.canvas._pending_obj_pos is not None:
            hit = self.body.canvas._object_at(self.body.canvas._pending_obj_pos)
            if hit and hit[0] == "mosaic":
                if len(existing_order) == len(order or ()):
                    self._pending_mosaic_mirrors = hit[1].get("mirrors") or ()
                raw_w = (hit[1] or {}).get("weights") or {}
                for k, v in raw_w.items():
                    if str(k) in uuid_to_index:
                        existing_weights[uuid_to_index[str(k)]] = int(v)
                    elif str(k).isdigit() and 1 <= int(k) <= len(self._bucket):
                        existing_weights[int(k) - 1] = int(v)
        preselected = [] if existing_order else self.selected_tray_indices()
        self._pending_mosaic_weights = dict(existing_weights)
        self._last_mosaic_weights = {}
        self._last_mosaic_mirrors = ()
        result = self._mosaic_dialog(
            existing_order,
            (layout or "").lower() or None,
            preselected_order=preselected,
        )
        if not result:
            self.body.canvas._pending_obj_pos = None
            return
        chosen, layout_name = result
        chosen_weights = dict(getattr(self, "_last_mosaic_weights", {}) or {})
        stable = [self._bucket[i - 1].asset_uuid for i in chosen]
        stable_weights = {
            str(self._bucket[int(k) - 1].asset_uuid): int(v)
            for k, v in chosen_weights.items()
            if 1 <= int(k) <= len(self._bucket) and int(v or 0) != 0
        }
        if order:
            self.body.canvas.replace_mosaic(stable, layout_name, weights=stable_weights,
                                            mirrors=self._last_mosaic_mirrors)
        else:
            self.body.canvas.insert_mosaic(stable, layout_name, weights=stable_weights,
                                           mirrors=self._last_mosaic_mirrors)
        self._apply_mosaic_tray_edits()

    def _apply_mosaic_tray_edits(self):
        # Apply only after the mosaic has saved using the original bucket indices.
        removed, featured = self._mosaic_tray_edits
        self._mosaic_tray_edits = (), None
        if featured is not None:
            self._set_featured_photo(featured)
        if removed:
            self._remove_from_tray(removed, exact=True)

    def _mosaic_tray_additions(self, order):
        positions = {str(photo.asset_uuid): index for index, photo in enumerate(self._bucket)}
        current = {positions.get(str(photo), int(photo) - 1 if str(photo).isdigit() else -1)
                   for photo in order}
        used = self._mosaic_used_indices(current)
        return tuple(self._bucket[index].asset_uuid for index in self.selected_tray_indices()
                     if 0 <= index < len(self._bucket) and index not in current and index not in used)

    def _mosaic_used_indices(self, keep):
        """0-based bucket indices already spoken for by OTHER mosaics in this post.

        SS-1049. With 53 photographs in a post's bucket and several mosaics,
        remembering which have been used is not something a person should be
        asked to do. The owner's words: he will not double-use or miss images.

        DERIVED, NEVER STORED. The post body already records every mosaic's
        membership -- {"type": "mosaic", "order": [...]} in the authoring model,
        which is the same thing as a [mosaic=1,4,7 layout=rows] marker. So this
        reads the answer out of the body each time instead of keeping a
        used-images list that could drift from the post, and nothing is written,
        nothing is marked, and nothing leaves the bucket or the archive. That
        was the explicit worry on the ticket: hiding from a picker and deleting
        from an archive are very different things, and only one is reversible.

        `keep` is the mosaic being edited. Its own photographs stay available,
        because the dialog shows them already ticked -- so the set returned is
        every other mosaic's membership minus this one's. That formulation also
        means it does not matter which marker is which, and two mosaics sharing
        a photograph does not strand it.

        BOTH EDITOR FACES, one implementation. body.authoring_blocks() is the
        canonical model for TWIGGY and BIGGIE alike; TWIGGY's markers are parsed
        into it and BIGGIE's canvas objects are serialised into it. The order
        entries are 1-based bucket positions from the text face and asset UUIDs
        from the canvas, so both are resolved here the same way _canvas_mosaic
        already resolves them.
        """
        return self._used_photo_indices(include_images=False, keep=keep)

    def _used_photo_indices(self, *, include_images: bool = True, keep=None):
        """0-based bucket indices already placed in mosaics (and image blocks)."""
        uuid_to_index = {str(image.asset_uuid): index
                         for index, image in enumerate(self._bucket)}
        used = set()

        def _claim(identity):
            key = str(identity or "").strip()
            if key in uuid_to_index:
                used.add(uuid_to_index[key])
            elif key.startswith("asset:") and key[6:] in uuid_to_index:
                used.add(uuid_to_index[key[6:]])
            elif key.startswith("bucket:") and key[7:].isdigit():
                pos = int(key[7:])
                if 1 <= pos <= len(self._bucket):
                    used.add(pos - 1)
            elif key.isdigit() and 1 <= int(key) <= len(self._bucket):
                used.add(int(key) - 1)

        def walk(node):
            """Mosaics and images can sit inside a columns block, so recurse."""
            if isinstance(node, dict):
                kind = node.get("type")
                if kind == "mosaic":
                    order = node.get("order") or []
                    if not order:
                        return
                    for identity in order:
                        _claim(identity)
                elif include_images and kind == "image":
                    if node.get("asset_uuid"):
                        _claim(node.get("asset_uuid"))
                    elif node.get("img_id"):
                        _claim(node.get("img_id"))
                for value in node.values():
                    walk(value)
            elif isinstance(node, (list, tuple)):
                for value in node:
                    walk(value)

        try:
            walk(self.body.authoring_blocks())
        except Exception:
            return set()

        return used - set(keep or [])

    def _pick_tray_photo(self):
        """Choose an unplaced tray photo and insert it at the cursor."""
        used = self._used_photo_indices()
        available = [i for i in range(len(self._bucket)) if i not in used]
        if not available:
            QMessageBox.information(self, "Photo from tray", "There are no unplaced photos in the tray.")
            return
        dialog = QDialog(self)
        dialog.setWindowTitle("Photo from tray")
        layout = QVBoxLayout(dialog)
        photos = QListWidget()
        photos.setIconSize(QSize(64, 64))
        for index in available:
            photo = self._bucket[index]
            icon = QIcon(load_pixmap(photo.thumb_aspect or photo.local_path, 64))
            item = QListWidgetItem(icon, photo.filename or os.path.basename(photo.local_path))
            item.setData(Qt.UserRole, index)
            photos.addItem(item)
        selected = self.selected_tray_indices()
        photos.setCurrentRow(available.index(selected[0]) if selected else 0)
        layout.addWidget(photos)
        buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
        buttons.button(QDialogButtonBox.Ok).setText("Insert photo")
        buttons.accepted.connect(dialog.accept)
        buttons.rejected.connect(dialog.reject)
        photos.itemDoubleClicked.connect(dialog.accept)
        layout.addWidget(buttons)
        photos.setFocus()
        if dialog.exec() == QDialog.Accepted:
            self._insert_tray_photo(photos.currentItem().data(Qt.UserRole))

    def _find_bucket_image(self, asset_uuid: str, index_hint=None):
        target = str(asset_uuid or "").strip()
        if target:
            for idx, im in enumerate(self._bucket):
                if str(getattr(im, "asset_uuid", "")) == target:
                    return idx, im
        if isinstance(index_hint, int) and 0 <= index_hint < len(self._bucket):
            return index_hint, self._bucket[index_hint]
        return None, None

    def _drop_tray_photo_biggie(self, asset_uuid: str):
        _, im = self._find_bucket_image(asset_uuid)
        if im is None:
            return
        default_cap = (im.caption or im.title or "").strip()
        self.body.canvas.insert_image("asset:" + im.asset_uuid,
                                      alt=im.alt, caption=default_cap)

    def _drop_tray_photo_twiggy(self, asset_uuid: str, index_hint=None):
        idx, _ = self._find_bucket_image(asset_uuid, index_hint)
        if idx is None:
            return
        shortcode = f"[img:bucket:{idx + 1}|full|center]"
        cursor = self.body.editor.textCursor()
        if cursor.positionInBlock() > 0:
            shortcode = "\n" + shortcode
        cursor.insertText(shortcode + "\n")
        self.body.editor.setTextCursor(cursor)

    def selected_tray_indices(self) -> list:
        """Return ordered list of currently selected unplaced 0-based bucket indices."""
        used = self._used_photo_indices()
        return [
            i for i in getattr(self, "_selected_photo_indices", [])
            if 0 <= i < len(self._bucket) and i not in used
        ]

    def select_tray_photos(self, indices):
        """Select one or more unplaced tray photos by 0-based bucket index."""
        used = self._used_photo_indices()
        clean = []
        for idx in (indices or []):
            i = int(idx)
            if 0 <= i < len(self._bucket) and i not in used and i not in clean:
                clean.append(i)
        self._selected_photo_indices = clean
        self._selected_photo_idx = clean[0] if clean else None
        self._refresh_bucket()

    def insert_selected_into_post(self) -> bool:
        """Place the single selected tray photo at the cursor (same result as dragging)."""
        sel = self.selected_tray_indices()
        if len(sel) != 1:
            return False
        self._insert_tray_photo(sel[0])
        return True

    def _insert_tray_photo(self, index):
        photo = self._bucket[index]
        if self.body.is_biggie():
            self._drop_tray_photo_biggie(photo.asset_uuid)
        else:
            self._drop_tray_photo_twiggy(photo.asset_uuid, index)
        self._selected_photo_indices = []
        self._refresh_bucket()
        self._changed()

    def insert_selected_mosaic_into_post(self, layout: str = "asymmetric") -> bool:
        """Insert 2+ selected tray photos as a mosaic block at the cursor."""
        sel = self.selected_tray_indices()
        if len(sel) < 2:
            return False
        layout_name = str(layout or "asymmetric").strip().lower() or "asymmetric"
        if self.body.is_biggie():
            stable = [str(self._bucket[i].asset_uuid) for i in sel]
            self.body.canvas.insert_mosaic(stable, layout_name)
        else:
            chosen_1based = [i + 1 for i in sel]
            marker = "[mosaic=" + ",".join(map(str, chosen_1based)) + " layout=" + layout_name + "]"
            cursor = self.body.editor.textCursor()
            if cursor.positionInBlock() > 0:
                marker = "\n" + marker
            cursor.insertText(marker + "\n")
            self.body.editor.setTextCursor(cursor)
        self._selected_photo_indices = []
        self._refresh_bucket()
        self._changed()
        return True

    def _mosaic_dialog(self, existing_order, existing_layout, preselected_order=None):
        """The one mosaic builder (live preview, thumbnail grid, drag/move order,
        layout, rotate). Returns (chosen 1-based bucket positions, layout) or None."""
        self._mosaic_tray_edits = (), None
        existing = bool(existing_order)
        initial_checked = list(existing_order) if existing else [
            int(i) for i in (preselected_order or []) if 0 <= int(i) < len(self._bucket)
        ]
        rotation_originals = {}
        dialog = QDialog(self)
        dialog.setWindowTitle("Edit mosaic" if existing else "Build mosaic")
        dialog.setWindowFlags(
            dialog.windowFlags()
            | Qt.Window
            | Qt.WindowMinimizeButtonHint
            | Qt.WindowMaximizeButtonHint
            | Qt.WindowCloseButtonHint
        )
        dialog.setSizeGripEnabled(True)
        dialog.resize(max(980, self._preview_width + 80), 820)
        layout = QVBoxLayout(dialog)
        layout.addWidget(QLabel(
            "Click photos to include them. Drag in the preview to swap positions. "
            "Right-click a preview photo to rearrange or resize it."))

        preview = MosaicPreview(content_width=self._preview_width)
        preview_scroll = QScrollArea()
        preview_scroll.setWidgetResizable(False)
        preview_scroll.setAlignment(Qt.AlignCenter)
        preview_scroll.setHorizontalScrollBarPolicy(Qt.ScrollBarAsNeeded)
        preview_scroll.setVerticalScrollBarPolicy(Qt.ScrollBarAsNeeded)
        preview_scroll.setFrameShape(QFrame.NoFrame)
        preview_scroll.setStyleSheet("QScrollArea { background: #090b0a; border: 1px solid #262926; }")
        preview_scroll.setWidget(preview)
        preview_scroll.setMinimumHeight(260)
        preview.fit_to_viewport(preview_scroll.viewport())

        preview_hint = QLabel(
            f"LIVE WYSIWYG PREVIEW ({self._preview_width}px column) — drag to swap; right-click to resize or rearrange"
        )
        preview_hint.setAlignment(Qt.AlignCenter)

        preview_pane = QWidget()
        preview_pane_lay = QVBoxLayout(preview_pane)
        preview_pane_lay.setContentsMargins(0, 0, 0, 0)
        preview_pane_lay.setSpacing(4)
        preview_pane_lay.addWidget(preview_scroll, 1)
        preview_pane_lay.addWidget(preview_hint)
        zoom_controls = QHBoxLayout()
        zoom_controls.addStretch()
        zoom_value = QLabel()
        preview.zoomChanged.connect(zoom_value.setText)
        zoom_value.setText(f"{round(preview._fit_scale * 100)}%")
        for label, handler in (
            ("Fit", lambda: preview.set_zoom(None)),
            ("−", lambda: preview.step_zoom(-1)),
            ("+", lambda: preview.step_zoom(1)),
            ("100%", lambda: preview.set_zoom(1.0)),
        ):
            button = QPushButton(label)
            button.clicked.connect(handler)
            zoom_controls.addWidget(button)
        zoom_controls.addWidget(zoom_value)
        preview_pane_lay.addLayout(zoom_controls)
        cluster_bar = QHBoxLayout()
        cluster_count = QLabel()
        break_all = QPushButton("Break up all")
        cluster_bar.addWidget(cluster_count, 1)
        cluster_bar.addWidget(break_all)
        preview_pane_lay.addLayout(cluster_bar)
        break_all.hide()

        # SS-1049: photographs already used by OTHER mosaics in this post are
        # not offered again.
        _used = self._mosaic_used_indices(existing_order)
        used_in_post = self._used_photo_indices()
        display_order = list(initial_checked) + [
            i for i in range(len(self._bucket))
            if i not in initial_checked and i not in _used
        ]
        if not display_order:
            QMessageBox.information(
                self, "Every photo is already in a mosaic",
                "All %d photographs in this post's bucket are already used in a "
                "mosaic, so there are none left to build a new one from.\n\n"
                "Nothing has been removed: they are all still in the bucket and "
                "in your archive. Add more photos, or edit an existing mosaic "
                "instead of starting another."
                % len(self._bucket))
            return None

        original_picker = tuple(display_order)
        featured = self._cover_idx
        for bucket_index in display_order:
            image = self._bucket[bucket_index]
            if bucket_index not in rotation_originals:
                rotation_originals[bucket_index] = (
                    image.local_path, image.original_path, image.width, image.height)

        tile_scroll = QScrollArea()
        tile_scroll.setWidgetResizable(True)
        tile_scroll.setFrameShape(QFrame.NoFrame)
        tile_host = QWidget()
        tile_grid = QGridLayout(tile_host)
        tile_grid.setContentsMargins(4, 4, 4, 4)
        tile_grid.setSpacing(8)
        tile_scroll.setWidget(tile_host)
        tile_scroll.setMinimumWidth(130)
        tile_scroll.setMaximumWidth(185)
        tile_scroll.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
        preview_pane.setMinimumHeight(240)
        splitter = QSplitter(Qt.Horizontal)
        splitter.addWidget(preview_pane)
        splitter.addWidget(tile_scroll)
        splitter.setChildrenCollapsible(False)
        splitter.setStretchFactor(0, 1)
        splitter.setStretchFactor(1, 0)
        splitter.setSizes([820, 143])
        layout.addWidget(splitter, 1)

        selection_row = QHBoxLayout()
        select_all = QPushButton("Select all")
        clear_all = QPushButton("Clear all")
        selected_count = QLabel()
        selection_row.addWidget(QLabel("Tick exactly the photos to include"))
        selection_row.addWidget(select_all)
        selection_row.addWidget(clear_all)
        selection_row.addStretch(1)
        selection_row.addWidget(selected_count)
        layout.addLayout(selection_row)

        preset_row = QHBoxLayout()
        preset_row.addWidget(QLabel("Layout"))
        preset = QComboBox()
        preset_row.addWidget(preset, 1)
        layout.addLayout(preset_row)

        arrows = QHBoxLayout()
        undo = QPushButton("Undo")
        redo = QPushButton("Redo")
        up = QPushButton("Move earlier")
        down = QPushButton("Move later")
        rotate_left = QPushButton("↶ Rotate left")
        rotate_right = QPushButton("↷ Rotate right")
        suggest = QPushButton("✨ Suggest arrangement")
        arrows.addWidget(undo); arrows.addWidget(redo)
        arrows.addWidget(up); arrows.addWidget(down)
        arrows.addWidget(rotate_left); arrows.addWidget(rotate_right)
        arrows.addStretch(1); arrows.addWidget(suggest)
        layout.addLayout(arrows)

        checked_set = set(initial_checked)
        preview.tray_additions = lambda: [bi for bi in self.selected_tray_indices()
                                          if bi in display_order and bi not in checked_set]
        pending_w = getattr(self, "_pending_mosaic_weights", None) or {}
        self._pending_mosaic_weights = {}
        weights_by_idx = {
            int(bi): max(-1, min(2, int(w)))
            for bi, w in pending_w.items()
            if int(w or 0) != 0
        }
        pending_mirrors = getattr(self, "_pending_mosaic_mirrors", ())
        self._pending_mosaic_mirrors = ()
        mirrored_groups = set()
        for value in pending_mirrors:
            start, count = map(int, value.split(":"))
            if start >= 0 and count > 1 and start + count <= len(initial_checked):
                mirrored_groups.add(tuple(initial_checked[start:start + count]))
        undo_history = []
        redo_history = []
        current_state = None
        active_bucket_idx = [display_order[0] if display_order else None]
        tiles_by_idx = {}
        constraint = {"previous": None, "attempts": 0, "keep_partial": False, "history_recorded": False}
        focus_after_resize = [None]

        def checked_indices():
            return [bi for bi in display_order if bi in checked_set]

        def mirror_positions(photos):
            return [
                f"{start}:{len(group)}"
                for group in sorted(mirrored_groups)
                if group[0] in photos
                for start in [photos.index(group[0])]
                if tuple(photos[start:start + len(group)]) == group
            ]

        def snapshot():
            return (tuple(display_order), frozenset(checked_set), dict(weights_by_idx),
                    preset.currentData(), frozenset(mirrored_groups), featured)

        def update_history_buttons():
            undo.setEnabled(bool(undo_history))
            redo.setEnabled(bool(redo_history))

        def restore_history(source, destination):
            nonlocal current_state, featured
            if not source:
                return
            constraint["previous"] = None
            destination.append(snapshot())
            current_state = source.pop()
            order, included, saved_weights, saved_layout, saved_mirrors, featured = current_state
            display_order[:] = order
            checked_set.clear()
            checked_set.update(included)
            weights_by_idx.clear()
            weights_by_idx.update(saved_weights)
            mirrored_groups.clear()
            mirrored_groups.update(saved_mirrors)
            rebuild_grid()
            refresh_layouts(saved_layout)

        def refresh_preview():
            nonlocal current_state
            inc = checked_indices()
            state = snapshot()
            if current_state is not None and state != current_state:
                if constraint["previous"] is None or not constraint["history_recorded"]:
                    undo_history.append(current_state)
                    redo_history.clear()
                    if constraint["previous"] is not None:
                        constraint["history_recorded"] = True
                current_state = state
            update_history_buttons()
            tiles = [
                (pos, self._bucket[bi].local_path, self._bucket[bi].thumb_aspect)
                for pos, bi in enumerate(display_order)
                if bi in checked_set
            ]
            w_map = {
                i + 1: weights_by_idx[bi]
                for i, bi in enumerate(inc)
                if weights_by_idx.get(bi, 0) != 0
            }
            preview.set_mosaic(tiles, preset.currentData() or "asymmetric", weights=w_map,
                               mirrored_sections=mirror_positions(inc))

        def refresh_layouts(selected=None):
            inc = checked_indices()
            count = len(inc)
            previous = selected if selected is not None else preset.currentData()
            preset.blockSignals(True)
            preset.clear()
            layouts_list = self._mosaic_layouts(count)
            if layouts_list:
                for label, value in layouts_list:
                    preset.addItem(label, value)
                preset.setEnabled(True)
                old = preset.findData(previous)
                preset.setCurrentIndex(old if old >= 0 else 0)
            else:
                preset.addItem("(tick 1+ photos to choose layout)", "")
                preset.setEnabled(False)
            preset.blockSignals(False)
            if _used:
                selected_count.setText(
                    f"{count} of {len(display_order)} included "
                    f"— {len(self._bucket) - len(_used)} of "
                    f"{len(self._bucket)} unused in this post")
            else:
                selected_count.setText(f"{count} of {len(display_order)} included")
            refresh_preview()

        def rebuild_grid():
            while tile_grid.count():
                item = tile_grid.takeAt(0)
                w = item.widget()
                if w:
                    w.deleteLater()
            tiles_by_idx.clear()
            tile_px = 98
            for grid_pos, bi in enumerate(display_order):
                image = self._bucket[bi]
                ori_code, ratio = photo_orientation_badge(
                    image.width, image.height, image.local_path)
                name = image.filename or os.path.basename(image.local_path)
                pix = load_pixmap(image.thumb_aspect or image.local_path, tile_px) or QPixmap()
                is_inc = bi in checked_set
                tile = PhotoThumbTile(
                    bi,
                    pix,
                    selected=is_inc,
                    is_cover=(bi == featured),
                    can_remove=not is_inc and bi not in used_in_post,
                    edited=bool(getattr(image, "slapper_edited_path", "")),
                    tile_size=tile_px,
                    asset_uuid=str(getattr(image, "asset_uuid", "")),
                    orientation=ori_code,
                    ratio_text=f"{ratio:.2f}:1",
                )
                tile.setToolTip(f"{name}\n{ori_code} · {ratio:.2f}:1")
                if not pix or pix.isNull():
                    tile.setToolTip("This photograph could not be previewed: " + (image.local_path or name))

                def _on_tile_click(b_idx=bi):
                    active_bucket_idx[0] = b_idx
                    if b_idx in checked_set:
                        checked_set.remove(b_idx)
                    else:
                        checked_set.add(b_idx)
                    rebuild_grid()
                    refresh_layouts()

                tile.clicked.connect(lambda _=False, b_idx=bi: _on_tile_click(b_idx))
                tile.removeRequested.connect(remove_picker_photo)
                tile.setFeaturedRequested.connect(feature_picker_photo)
                tiles_by_idx[bi] = tile
                tile_grid.addWidget(tile, grid_pos, 0, Qt.AlignHCenter)

        def remove_picker_photo(bi):
            nonlocal featured
            if bi in checked_set or bi in used_in_post:
                return
            display_order.remove(bi)
            if featured == bi:
                featured = -1
            rebuild_grid()
            refresh_layouts()

        def feature_picker_photo(bi):
            nonlocal featured
            featured = bi
            rebuild_grid()
            refresh_preview()

        def set_all(state):
            if state == Qt.Checked:
                checked_set.update(display_order)
            else:
                checked_set.clear()
            rebuild_grid()
            refresh_layouts()

        def include_tray_photos():
            checked_set.update(preview.tray_additions())
            rebuild_grid()
            refresh_layouts()

        def exclude_preview_photo(row):
            if len(checked_set) > 1:
                checked_set.discard(display_order[row])
                rebuild_grid()
                refresh_layouts()

        def swap_rows(first, second):
            if first == second or first < 0 or second < 0:
                return
            if first >= len(display_order) or second >= len(display_order):
                return
            display_order[first], display_order[second] = display_order[second], display_order[first]
            rebuild_grid()
            refresh_preview()

        def move_preview_row(row, delta):
            included_rows = [
                position for position, bucket_index in enumerate(display_order)
                if bucket_index in checked_set
            ]
            if row not in included_rows:
                return
            current = included_rows.index(row)
            target_index = current + int(delta)
            if not 0 <= target_index < len(included_rows):
                return
            target = included_rows[target_index]
            display_order[row], display_order[target] = display_order[target], display_order[row]
            rebuild_grid()
            refresh_preview()

        def swap_preview_side(row):
            photos = checked_indices()
            if not 0 <= row < len(display_order) or display_order[row] not in checked_set:
                return
            position = photos.index(display_order[row])
            for section in preview.computed_cells().get("sections", ()):
                start, count = section["start"], section["count"]
                if count > 1 and start <= position < start + count:
                    mirrored_groups.symmetric_difference_update([tuple(photos[start:start + count])])
                    refresh_preview()
                    return

        def reverse_arrangement():
            photos = checked_indices()
            groups = [
                tuple(photos[section["start"]:section["start"] + section["count"]])
                for section in preview.computed_cells().get("sections", ())
                if section["count"] > 1
            ]
            if groups:
                mirrored_groups.symmetric_difference_update(groups)
                refresh_preview()

        def resize_preview_row(row, direction):
            if row < 0 or row >= len(display_order):
                return
            bi = display_order[row]
            if bi not in checked_set or len(checked_indices()) < 2:
                return
            step = 1 if int(direction) > 0 else -1
            cur = weights_by_idx.get(bi, 0)
            nxt = max(-1, min(2, cur + step))
            if nxt == 0:
                weights_by_idx.pop(bi, None)
            else:
                weights_by_idx[bi] = nxt
            focus_after_resize[0] = row
            refresh_preview()

        def break_clusters(clusters):
            changed = False
            for rows in clusters:
                selected = {row: display_order[row] for row in rows if display_order[row] in checked_set}
                changes = break_cluster_weights(
                    selected, lambda row: weights_by_idx.get(selected[row], 0))
                for row, weight in changes.items():
                    bi = selected[row]
                    if weight:
                        weights_by_idx[bi] = weight
                    else:
                        weights_by_idx.pop(bi, None)
                    if not changed:
                        focus_after_resize[0] = row
                    changed = True
            if changed:
                refresh_preview()
            return changed

        def cluster_status(count):
            cluster_count.setText(f"{count} cluster{'s' if count != 1 else ''} to break up" if count else "")
            break_all.setVisible(count > 0)
            target = next((rect for row, rect in preview._rects if row == focus_after_resize[0]), None)
            if target is not None:
                preview_scroll.ensureVisible(target.center().x(), target.top() + 16)
                focus_after_resize[0] = None
            saved = constraint["previous"]
            if saved is None:
                return
            if not count:
                constraint["previous"] = None
                return
            constraint["attempts"] += 1
            if constraint["attempts"] <= 8:
                groups = [[preview._tiles[cell["id"] - 1][0] for cell in group]
                          for group in preview._clusters]
                if break_clusters(groups):
                    return
            constraint["previous"] = None
            if constraint["keep_partial"]:
                return
            order, weights, previous_layout = saved
            display_order[:] = order
            weights_by_idx.clear()
            weights_by_idx.update(weights)
            index = preset.findData(previous_layout)
            if index >= 0:
                preset.setCurrentIndex(index)
            rebuild_grid()
            refresh_preview()

        def constrain_layout(previous_layout, *, keep_partial=False):
            constraint["previous"] = (list(display_order), dict(weights_by_idx), previous_layout)
            constraint["attempts"] = 0
            constraint["keep_partial"] = keep_partial
            constraint["history_recorded"] = False

        def break_every_cluster():
            constrain_layout(preset.currentData(), keep_partial=True)
            cluster_status(len(preview._clusters))

        def selected_layout(_index):
            if preset.currentData() == "asymmetric":
                constrain_layout(preview._rendered_layout)
                refresh_preview()

        def rotate_current(degrees):
            bi = active_bucket_idx[0]
            if bi is None or bi not in display_order:
                QMessageBox.information(dialog, "Choose a photo",
                                        "Click one photo tile before rotating it.")
                return
            image = self._bucket[bi]
            try:
                from PIL import Image, ImageOps
                target_dir = os.path.join(tempfile.gettempdir(), "coldsnap-rotated")
                os.makedirs(target_dir, exist_ok=True)
                ext = os.path.splitext(image.filename or image.local_path)[1].lower()
                if ext not in (".jpg", ".jpeg", ".png", ".webp"):
                    ext = ".jpg"
                target = os.path.join(target_dir, uuid.uuid4().hex + ext)
                with Image.open(image.local_path) as source:
                    corrected = ImageOps.exif_transpose(source).rotate(degrees, expand=True)
                    save_args = {"quality": 95} if ext in (".jpg", ".jpeg") else {}
                    if corrected.mode not in ("RGB", "RGBA"):
                        corrected = corrected.convert("RGB")
                    corrected.save(target, **save_args)
                    image.width, image.height = corrected.size
                if not image.original_path:
                    image.original_path = image.local_path
                image.local_path = target
                image.thumb_aspect = ""
                image.thumb_square = ""
                rebuild_grid()
                refresh_preview()
            except Exception as exc:
                QMessageBox.warning(dialog, "Could not rotate photo", str(exc))

        def suggest_arrangement():
            inc = checked_indices()
            if not inc:
                return
            constrain_layout(preset.currentData())
            # Group by orientation (P, SQ, L) and aspect ratio so similar shapes sit cleanly
            ori_rank = {"P": 0, "SQ": 1, "L": 2, "": 3}
            def _key(bi):
                im = self._bucket[bi]
                ori, r = photo_orientation_badge(im.width, im.height, im.local_path)
                return (ori_rank.get(ori, 3), r)
            inc.sort(key=_key)
            exc = [bi for bi in display_order if bi not in checked_set]
            display_order[:] = inc + exc
            oris = [photo_orientation_badge(self._bucket[bi].width, self._bucket[bi].height, self._bucket[bi].local_path)[0]
                    for bi in inc]
            ratios = [photo_orientation_badge(self._bucket[bi].width, self._bucket[bi].height, self._bucket[bi].local_path)[1]
                      for bi in inc]
            if len(inc) == 3:
                if all(o == "P" for o in oris) or all(o == "SQ" for o in oris):
                    target_layout = "three-across"
                elif sum(o == "L" for o in oris) >= 2:
                    target_layout = "one-top"
                else:
                    target_layout = "one-left"
                idx_l = preset.findData(target_layout)
                if idx_l >= 0:
                    preset.setCurrentIndex(idx_l)
            elif len(inc) == 2:
                target_layout = "rows" if all(r > 1.3 for r in ratios) else "asymmetric"
                idx_l = preset.findData(target_layout)
                if idx_l >= 0:
                    preset.setCurrentIndex(idx_l)
            elif len(inc) >= 4:
                target_layout = "square" if all(o == "SQ" for o in oris) else "asymmetric"
                idx_l = preset.findData(target_layout)
                if idx_l >= 0:
                    preset.setCurrentIndex(idx_l)
            rebuild_grid()
            refresh_preview()

        select_all.clicked.connect(lambda: set_all(Qt.Checked))
        clear_all.clicked.connect(lambda: set_all(Qt.Unchecked))
        rotate_left.clicked.connect(lambda: rotate_current(90))
        rotate_right.clicked.connect(lambda: rotate_current(-90))
        suggest.clicked.connect(suggest_arrangement)
        preset.currentIndexChanged.connect(lambda _index: refresh_preview())
        preset.activated.connect(selected_layout)
        preview.swapRequested.connect(swap_rows)
        preview.moveRequested.connect(move_preview_row)
        preview.sideSwapRequested.connect(swap_preview_side)
        preview.sizeRequested.connect(resize_preview_row)
        preview.reverseRequested.connect(reverse_arrangement)
        preview.breakRequested.connect(break_clusters)
        preview.includeRequested.connect(include_tray_photos)
        preview.excludeRequested.connect(exclude_preview_photo)
        preview.clustersChanged.connect(cluster_status)
        break_all.clicked.connect(break_every_cluster)

        def move(delta):
            bi = active_bucket_idx[0]
            if bi is None or bi not in display_order:
                return
            pos = display_order.index(bi)
            target = pos + delta
            if 0 <= target < len(display_order):
                display_order[pos], display_order[target] = display_order[target], display_order[pos]
                rebuild_grid()
                refresh_preview()

        up.clicked.connect(lambda: move(-1))
        down.clicked.connect(lambda: move(1))
        undo.clicked.connect(lambda: restore_history(undo_history, redo_history))
        redo.clicked.connect(lambda: restore_history(redo_history, undo_history))
        QShortcut(QKeySequence(QKeySequence.Undo), dialog,
                  activated=lambda: restore_history(undo_history, redo_history))
        QShortcut(QKeySequence(QKeySequence.Redo), dialog,
                  activated=lambda: restore_history(redo_history, undo_history))
        buttons = QDialogButtonBox(QDialogButtonBox.Cancel | QDialogButtonBox.Ok)
        buttons.accepted.connect(dialog.accept)
        buttons.rejected.connect(dialog.reject)
        layout.addWidget(buttons)

        # Expose dialog internals for headless/UI regression tests
        dialog._mosaic_preview = preview
        dialog._mosaic_preset = preset
        dialog._mosaic_tiles = tiles_by_idx
        dialog._mosaic_checked_indices = checked_indices
        dialog._mosaic_selected_count_lbl = selected_count
        dialog._mosaic_suggest_btn = suggest
        dialog._mosaic_move_preview = move_preview_row
        dialog._mosaic_swap_side = swap_preview_side
        dialog._mosaic_resize = resize_preview_row
        dialog._mosaic_weights = weights_by_idx
        dialog._mosaic_reverse = reverse_arrangement

        rebuild_grid()
        refresh_layouts()
        if existing_layout:
            found = preset.findData(existing_layout)
            if found >= 0:
                preset.setCurrentIndex(found)
        current_state = snapshot()
        update_history_buttons()

        if dialog.exec() != QDialog.Accepted:
            for bucket_index, state in rotation_originals.items():
                image = self._bucket[bucket_index]
                image.local_path, image.original_path, image.width, image.height = state
            return None

        chosen = [bi + 1 for bi in display_order if bi in checked_set]
        if not chosen:
            for bucket_index, state in rotation_originals.items():
                image = self._bucket[bucket_index]
                image.local_path, image.original_path, image.width, image.height = state
            QMessageBox.warning(self, "Empty mosaic", "Choose at least one photo.")
            return None
        # A rotate changed a working copy: the canvas must repaint from the new file.
        self.body.set_bucket([im.local_path for im in self._bucket])
        self._last_mosaic_weights = {
            bi + 1: weights_by_idx[bi]
            for bi in display_order
            if bi in checked_set and weights_by_idx.get(bi, 0) != 0
        }
        self._last_mosaic_mirrors = mirror_positions(checked_indices())
        remaining = set(display_order)
        removed = tuple(bi for bi in original_picker if bi not in remaining)
        new_featured = featured if featured >= 0 and featured != self._cover_idx else None
        self._mosaic_tray_edits = removed, new_featured
        return chosen, str(preset.currentData() or "asymmetric")

    def _add_photos(self):
        from .pickers import pick_images
        paths = pick_images(self, (self.app_config() or {}).get("url", ""))
        self._add_photo_paths(paths, insert=False)

    def _build_open_published_dialog(self, posts: list):
        ordered = sorted(
            list(posts or []),
            key=lambda p: (
                str(p.get("created_at") or p.get("updated_at") or ""),
                int(p.get("id") or 0),
            ),
            reverse=True,
        )
        truncated = len(ordered) >= 500
        shown = ordered[:500] if len(ordered) > 500 else ordered

        dialog = QDialog(self)
        dialog.setWindowTitle("Open a published post")
        dialog.resize(740, 580)
        layout = QVBoxLayout(dialog)
        layout.addWidget(hint("Choose a post (newest first). COLD SNAP will load its saved bucket and keep its original date."))

        filter_row = QHBoxLayout()
        search_box = QLineEdit()
        search_box.setPlaceholderText("Search by title, slug, status, or date…")
        filter_row.addWidget(search_box, 1)

        min_date = QDate(1900, 1, 1)
        filter_row.addWidget(QLabel("From:"))
        from_date = QDateEdit()
        from_date.setCalendarPopup(True)
        from_date.setDisplayFormat("yyyy-MM-dd")
        from_date.setMinimumDate(min_date)
        from_date.setSpecialValueText(" ")
        from_date.setDate(min_date)
        filter_row.addWidget(from_date)

        filter_row.addWidget(QLabel("To:"))
        to_date = QDateEdit()
        to_date.setCalendarPopup(True)
        to_date.setDisplayFormat("yyyy-MM-dd")
        to_date.setMinimumDate(min_date)
        to_date.setSpecialValueText(" ")
        to_date.setDate(min_date)
        filter_row.addWidget(to_date)

        clear_btn = QPushButton("Clear")
        filter_row.addWidget(clear_btn)
        layout.addLayout(filter_row)

        count_lbl = hint("")
        layout.addWidget(count_lbl)

        trunc_note = hint(
            f"Showing the newest {len(shown)} posts (server 500-post limit reached). "
            "Use the search box or date range above to filter this list."
        )
        trunc_note.setVisible(truncated)
        layout.addWidget(trunc_note)

        choices = QListWidget()
        buttons = QDialogButtonBox(QDialogButtonBox.Cancel | QDialogButtonBox.Open)
        open_btn = buttons.button(QDialogButtonBox.Open)

        def _bound_iso(de: QDateEdit) -> str:
            if not de.lineEdit().text().strip() or de.date() <= min_date:
                return ""
            return de.date().toString("yyyy-MM-dd")

        def _populate(*_args):
            q = str(search_box.text() or "").strip().lower()
            from_iso = _bound_iso(from_date)
            to_iso = _bound_iso(to_date)
            choices.clear()
            matched = 0
            for post in shown:
                title = str(post.get("title") or "Untitled")
                slug = str(post.get("slug") or "")
                status = str(post.get("status") or "draft")
                created_iso = str(post.get("created_at") or "")[:10]
                date_str = created_iso or str(post.get("updated_at") or "")[:10]
                if from_iso and (not created_iso or created_iso < from_iso):
                    continue
                if to_iso and (not created_iso or created_iso > to_iso):
                    continue
                hay = f"{date_str} {title} {slug} {status}".lower()
                if q and q not in hay:
                    continue
                label = f"{date_str}   {title}   [{status}]"
                item = QListWidgetItem(label)
                item.setData(Qt.UserRole, int(post.get("id") or 0))
                choices.addItem(item)
                matched += 1
            count_lbl.setText(f"Showing {matched} of {len(shown)} posts")
            if matched:
                choices.setCurrentRow(0)
                if open_btn is not None:
                    open_btn.setEnabled(True)
            else:
                empty = QListWidgetItem("No posts in this range")
                empty.setFlags(Qt.NoItemFlags)
                choices.addItem(empty)
                if open_btn is not None:
                    open_btn.setEnabled(False)

        def _reset_filters():
            search_box.clear()
            from_date.setDate(min_date)
            to_date.setDate(min_date)
            _populate()

        _populate()
        search_box.textChanged.connect(_populate)
        from_date.dateChanged.connect(_populate)
        to_date.dateChanged.connect(_populate)
        clear_btn.clicked.connect(_reset_filters)
        layout.addWidget(choices, 1)

        buttons.accepted.connect(dialog.accept)
        buttons.rejected.connect(dialog.reject)
        choices.itemDoubleClicked.connect(
            lambda item: dialog.accept() if (item.flags() & Qt.ItemIsEnabled) else None
        )
        layout.addWidget(buttons)
        dialog._search_box = search_box
        dialog._from_date = from_date
        dialog._to_date = to_date
        dialog._clear_btn = clear_btn
        dialog._count_lbl = count_lbl
        dialog._choices = choices
        dialog._trunc_note = trunc_note
        return dialog

    def _has_open_draft_content(self) -> bool:
        """True when the editor currently holds any authored title, body, or photos."""
        if self.title_edit.text().strip() or self.colophon_edit.text().strip():
            return True
        if self._bucket:
            return True
        if self.body.is_biggie():
            plain = self.body.canvas.toPlainText()
            if plain.strip() or "\ufffc" in plain:
                return True
        elif self.body.editor.toPlainText().strip():
            return True
        return False

    def _ask_open_published_action(self, post_title: str, choose_fn=None) -> str:
        """Ask how to handle an open draft before opening a published post.
        Returns 'attach', 'separate', or 'cancel'.
        """
        if choose_fn is not None:
            return str(choose_fn(post_title) or "cancel")
        box = QMessageBox(self)
        box.setIcon(QMessageBox.Question)
        box.setWindowTitle("You have a draft open")
        box.setText(
            f"You already have a draft open in the editor.\n\n"
            f"How do you want to open \"{post_title or 'this published post'}\"?"
        )
        attach_btn = box.addButton("Attach this draft to the published post", QMessageBox.AcceptRole)
        separate_btn = box.addButton("Open separately", QMessageBox.ActionRole)
        box.addButton("Cancel", QMessageBox.RejectRole)
        box.exec()
        clicked = box.clickedButton()
        if clicked is attach_btn:
            return "attach"
        if clicked is separate_btn:
            return "separate"
        return "cancel"

    def _run_off_thread(self, fn, label: str = "Loading…"):
        """Run blocking network work off the GUI thread so the window never freezes."""
        prog = QProgressDialog(label, None, 0, 0, self)
        prog.setWindowTitle("COLD TAKE")
        prog.setWindowModality(Qt.WindowModal)
        prog.setCancelButton(None)
        prog.setMinimumDuration(150)

        loop = QEventLoop(self)
        outcome = {}

        def _worker():
            try:
                outcome["value"] = fn()
            except Exception as exc:  # noqa: BLE001
                outcome["error"] = exc
            finally:
                QMetaObject.invokeMethod(loop, "quit", Qt.QueuedConnection)

        with ThreadPoolExecutor(max_workers=1) as pool:
            pool.submit(_worker)
            loop.exec()
        prog.close()
        if "error" in outcome:
            raise outcome["error"]
        return outcome.get("value")

    @staticmethod
    def _draft_block_count(draft) -> int:
        import json
        raw_blocks = getattr(draft, "blocks_json", "") or ""
        if raw_blocks:
            try:
                parsed = json.loads(raw_blocks)
                if isinstance(parsed, list):
                    return len(parsed)
            except (TypeError, ValueError):
                pass
        cap = (getattr(draft, "caption", "") or "").strip()
        if not cap:
            return 0
        return len([p for p in re.split(r"\n\s*\n", cap) if p.strip()])

    @staticmethod
    def _trash_dir() -> str:
        path = os.path.join(O.default_sessions_root(), "trash")
        os.makedirs(path, exist_ok=True)
        return path

    @classmethod
    def _move_draft_to_trash(cls, session, draft) -> str:
        import json
        from datetime import datetime, timezone
        tdir = cls._trash_dir()
        payload = {
            "deleted_at": datetime.now(timezone.utc).isoformat(),
            "session_id": session.session_id,
            "draft": draft.to_dict(),
        }
        tpath = os.path.join(tdir, f"{draft.draft_id}.json")
        with open(tpath, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, indent=2)
        session.delete_draft(draft.draft_id)
        return tpath

    @classmethod
    def _list_trash_drafts(cls) -> list:
        import json
        from datetime import datetime, timezone, timedelta
        tdir = cls._trash_dir()
        cutoff = datetime.now(timezone.utc) - timedelta(days=30)
        items = []
        for fn in os.listdir(tdir):
            if not fn.endswith(".json"):
                continue
            fp = os.path.join(tdir, fn)
            try:
                with open(fp, "r", encoding="utf-8") as fh:
                    data = json.load(fh)
                dt = datetime.fromisoformat(str(data.get("deleted_at") or ""))
                if dt.tzinfo is None:
                    dt = dt.replace(tzinfo=timezone.utc)
                if dt < cutoff:
                    os.remove(fp)
                    continue
                draft = O.Draft.from_dict(data["draft"])
                items.append((fp, str(data.get("session_id") or ""), str(data.get("deleted_at") or ""), draft))
            except Exception:  # noqa: BLE001
                continue
        items.sort(key=lambda x: x[2], reverse=True)
        return items

    @classmethod
    def _restore_from_trash(cls, trash_path: str, target_session):
        import json
        with open(trash_path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
        draft = O.Draft.from_dict(data["draft"])
        target_session.add_draft(draft, copy_images=False)
        if os.path.isfile(trash_path):
            os.remove(trash_path)
        return draft

    @staticmethod
    def _archived_draft_ids() -> set:
        raw = QSettings("SnapSmack", "ColdSnap").value("take/archived_draft_ids", []) or []
        if isinstance(raw, str):
            raw = [x for x in raw.split(",") if x]
        return set(str(x) for x in raw)

    @staticmethod
    def _archive_draft_id(draft_id: str):
        settings = QSettings("SnapSmack", "ColdSnap")
        ids = TakeMode._archived_draft_ids()
        ids.add(str(draft_id))
        settings.setValue("take/archived_draft_ids", sorted(ids))

    def _collect_local_drafts(self, *, include_archived: bool = False):
        """Return (session, draft) pairs for all local drafts in this mode, newest first."""
        seen = set()
        archived = set() if include_archived else self._archived_draft_ids()
        entries = []
        active_session = self.rail.ensure_session()
        sessions = [active_session]
        try:
            for s in O.SessionStore().list():
                if s.mode == self.SUITE_MODE and s.session_id != active_session.session_id:
                    sessions.append(s)
        except Exception:  # noqa: BLE001
            pass
        for s in sessions:
            try:
                for d in s.list_drafts():
                    if d.draft_id not in seen and d.draft_id not in archived:
                        seen.add(d.draft_id)
                        entries.append((s, d))
            except Exception:  # noqa: BLE001
                continue
        entries.sort(
            key=lambda pair: (str(getattr(pair[1], "updated_at", "") or ""),
                              str(getattr(pair[1], "created_at", "") or "")),
            reverse=True,
        )
        return entries

    @staticmethod
    def _duplicate_remote_links(entries) -> dict:
        counts = {}
        for _sess, d in entries:
            rid = getattr(d, "remote_post_id", None)
            if rid:
                counts[int(rid)] = counts.get(int(rid), 0) + 1
        return {rid: cnt for rid, cnt in counts.items() if cnt > 1}

    def _build_open_draft_dialog(self, entries):
        from PySide6.QtWidgets import QCheckBox
        dialog = QDialog(self)
        dialog.setWindowTitle("Open local draft")
        dialog.resize(680, 480)
        layout = QVBoxLayout(dialog)
        layout.addWidget(QLabel("Choose a saved local draft to load into COLD TAKE:"))

        dup_banner = QLabel()
        dup_banner.setWordWrap(True)
        dup_banner.setStyleSheet("color: #f5b942; font-weight: 700; padding: 4px 0;")
        layout.addWidget(dup_banner)

        choices = QListWidget()
        layout.addWidget(choices, 1)

        ctrl_row = QHBoxLayout()
        archive_btn = QPushButton("Archive draft")
        archive_btn.setToolTip("Hide this draft from the list without deleting its file on disk")
        delete_btn = QPushButton("Delete draft")
        delete_btn.setToolTip("Move this draft to COLD SNAP Trash (recoverable for 30 days)")
        restore_btn = QPushButton("Restore")
        restore_btn.setToolTip("Restore the selected draft from COLD SNAP Trash")
        restore_btn.setVisible(False)
        show_arch_cb = QCheckBox("Show archived")
        show_trash_cb = QCheckBox("Show trash (30d)")
        ctrl_row.addWidget(archive_btn)
        ctrl_row.addWidget(delete_btn)
        ctrl_row.addWidget(restore_btn)
        ctrl_row.addWidget(show_arch_cb)
        ctrl_row.addWidget(show_trash_cb)
        ctrl_row.addStretch(1)
        layout.addLayout(ctrl_row)

        buttons = QDialogButtonBox(QDialogButtonBox.Cancel | QDialogButtonBox.Open)
        buttons.accepted.connect(dialog.accept)
        buttons.rejected.connect(dialog.reject)
        choices.itemDoubleClicked.connect(lambda _item: dialog.accept())
        layout.addWidget(buttons)

        dialog._entries = list(entries)
        dialog._trash_entries = []
        dialog._choices = choices
        dialog._dup_banner = dup_banner
        dialog._archive_btn = archive_btn
        dialog._delete_btn = delete_btn
        dialog._restore_btn = restore_btn
        dialog._show_arch_cb = show_arch_cb
        dialog._show_trash_cb = show_trash_cb

        def _populate():
            choices.clear()
            showing_trash = show_trash_cb.isChecked()
            archive_btn.setVisible(not showing_trash)
            delete_btn.setVisible(not showing_trash)
            restore_btn.setVisible(showing_trash)
            buttons.button(QDialogButtonBox.Open).setEnabled(not showing_trash)
            if showing_trash:
                dup_banner.setVisible(False)
                dialog._trash_entries = self._list_trash_drafts()
                for idx, (_fp, _sid, del_at, draft) in enumerate(dialog._trash_entries):
                    title = str(getattr(draft, "title", "") or "Untitled")
                    n_blocks = self._draft_block_count(draft)
                    label = f"[TRASH] {title}   [{n_blocks} blocks]   — deleted {del_at[:19].replace('T', ' ')}"
                    item = QListWidgetItem(label)
                    item.setData(Qt.UserRole, idx)
                    choices.addItem(item)
                if choices.count() > 0:
                    choices.setCurrentRow(0)
                return

            cur_entries = dialog._entries
            dups = self._duplicate_remote_links(cur_entries)
            if dups:
                parts = [f"#{rid} ({cnt} drafts)" for rid, cnt in sorted(dups.items())]
                dup_banner.setText("⚠ Multiple drafts are linked to the same published post: " + ", ".join(parts) + ". Which is current?")
                dup_banner.setVisible(True)
            else:
                dup_banner.setVisible(False)
            archived_ids = self._archived_draft_ids()
            for idx, (_sess, draft) in enumerate(cur_entries):
                title = str(getattr(draft, "title", "") or "Untitled")
                date_s = str(getattr(draft, "updated_at", "") or getattr(draft, "post_date", "") or getattr(draft, "created_at", "") or "")[:19].replace("T", " ")
                n_blocks = self._draft_block_count(draft)
                n_photos = len(getattr(draft, "images", None) or [])
                rid = getattr(draft, "remote_post_id", None)
                linked = f" · linked #{rid}" if rid else ""
                arch_tag = " [archived]" if draft.draft_id in archived_ids else ""
                label = f"{title}{arch_tag}   [{n_blocks} blocks, {n_photos} photos{linked}]"
                if date_s:
                    label += f"   — {date_s}"
                item = QListWidgetItem(label)
                item.setData(Qt.UserRole, idx)
                choices.addItem(item)
            if choices.count() > 0:
                choices.setCurrentRow(0)

        def _on_archive():
            item = choices.currentItem()
            if not item:
                return
            idx = int(item.data(Qt.UserRole))
            _s, draft = dialog._entries[idx]
            self._archive_draft_id(draft.draft_id)
            dialog._entries = self._collect_local_drafts(include_archived=show_arch_cb.isChecked())
            _populate()

        def _on_delete(*, confirm_fn=None):
            item = choices.currentItem()
            if not item:
                return
            idx = int(item.data(Qt.UserRole))
            sess, draft = dialog._entries[idx]
            title = str(getattr(draft, "title", "") or "Untitled")
            if draft.draft_id == getattr(self, "_editing_id", None):
                if confirm_fn is None:
                    QMessageBox.information(
                        dialog, "Draft is open",
                        f"\"{title}\" is currently open in COLD TAKE. Clear or open another draft before deleting it.",
                    )
                return
            if confirm_fn is not None:
                if not confirm_fn("delete", draft):
                    return
            else:
                ans = QMessageBox.question(
                    dialog, "Delete draft",
                    f"Move \"{title}\" to COLD SNAP Trash (recoverable for 30 days)?",
                    QMessageBox.Yes | QMessageBox.Cancel, QMessageBox.Cancel,
                )
                if ans != QMessageBox.Yes:
                    return
            rid = getattr(draft, "remote_post_id", None)
            if rid:
                if confirm_fn is not None:
                    if not confirm_fn("linked", draft):
                        return
                else:
                    ans2 = QMessageBox.warning(
                        dialog, "Linked to published post",
                        f"\"{title}\" is linked to published post #{rid}. Move it to Trash anyway?",
                        QMessageBox.Yes | QMessageBox.Cancel, QMessageBox.Cancel,
                    )
                    if ans2 != QMessageBox.Yes:
                        return
            self._move_draft_to_trash(sess, draft)
            dialog._entries = self._collect_local_drafts(include_archived=show_arch_cb.isChecked())
            _populate()

        def _on_restore():
            item = choices.currentItem()
            if not item:
                return
            idx = int(item.data(Qt.UserRole))
            fp, _sid, _del, _draft = dialog._trash_entries[idx]
            self._restore_from_trash(fp, self.rail.ensure_session())
            dialog._entries = self._collect_local_drafts(include_archived=show_arch_cb.isChecked())
            _populate()

        def _on_toggle_archived():
            dialog._entries = self._collect_local_drafts(include_archived=show_arch_cb.isChecked())
            _populate()

        dialog._on_delete = _on_delete
        archive_btn.clicked.connect(_on_archive)
        delete_btn.clicked.connect(lambda: _on_delete())
        restore_btn.clicked.connect(_on_restore)
        show_arch_cb.toggled.connect(lambda _checked: _on_toggle_archived())
        show_trash_cb.toggled.connect(lambda _checked: _populate())
        _populate()
        return dialog

    def _open_draft(self):
        entries = self._collect_local_drafts()
        if not entries:
            QMessageBox.information(self, "No saved drafts", "There are no saved drafts in your COLD TAKE sessions yet.")
            return
        dialog = self._build_open_draft_dialog(entries)
        if dialog.exec() != QDialog.Accepted or not dialog._choices.currentItem():
            return
        idx = int(dialog._choices.currentItem().data(Qt.UserRole))
        _sess, draft = dialog._entries[idx]
        n_blocks = self._draft_block_count(draft)
        self._edit(draft)
        self.save_state.setText(f"Opened draft \"{draft.title or 'Untitled'}\" ({n_blocks} blocks)")

    def _open_published(self, *, choose_fn=None):
        # A pending autosave must be durable before the first network request.
        self._autosave.stop()
        if self._autosave_now() is False:
            QMessageBox.critical(
                self,
                "Draft was not saved",
                "COLD SNAP could not save the open draft, so it will not open "
                "another post. The editor has been left exactly as it was.",
            )
            return
        poster, _url = self._poster_and_url()
        if poster is None:
            return
        self._published_choose_fn = choose_fn
        self._start_published_list(poster)

    def _start_published_list(self, poster) -> None:
        """Fetch chooser data without blocking the GUI thread."""
        if self._published_list_active:
            return
        self._published_list_active = True
        self.save_state.setText("Loading published posts… your draft is safe")

        def load_list():
            try:
                self._published_bridge.list_loaded.emit({
                    "poster": poster,
                    "posts": poster.list_posts(),
                })
            except Exception as exc:  # noqa: BLE001
                self._published_bridge.list_failed.emit(exc)

        threading.Thread(
            target=load_list, name="coldsnap-list-published", daemon=True,
        ).start()

    def _published_list_loaded(self, result: dict) -> None:
        self._published_list_active = False
        poster = result["poster"]
        posts = result["posts"]
        if not posts:
            self.save_state.setText("No published posts found — your draft is safe")
            QMessageBox.information(self, "No posts yet", "This site has no long-form posts to edit.")
            return

        dialog = self._build_open_published_dialog(posts)
        if dialog.exec() != QDialog.Accepted or not dialog._choices.currentItem():
            return
        post_id = int(dialog._choices.currentItem().data(Qt.UserRole) or 0)
        selected_row = next((p for p in posts if int(p.get("id") or 0) == post_id), {})
        selected_title = str(selected_row.get("title") or f"Post #{post_id}")

        action = "separate"
        if self._has_open_draft_content():
            action = self._ask_open_published_action(
                selected_title, choose_fn=self._published_choose_fn,
            )
            if action not in ("attach", "separate"):
                return
        session = self.rail.ensure_session()
        self._start_published_load(
            poster, post_id, action, session, str(self._editing_id or ""),
        )

    def _published_list_failed(self, error) -> None:
        self._published_list_active = False
        self.save_state.setText("Could not load published posts — your draft is safe")
        if isinstance(error, AuthorizationExpiredError):
            from .connect_panel import prompt_reauthentication
            prompt_reauthentication(self, str(error), long_form=True)
            return
        QMessageBox.critical(self, "Could not load posts", str(error))

    def _start_published_load(self, poster, post_id: int, action: str,
                              session, active_id: str) -> None:
        """Fetch one published post and its photos without blocking the GUI."""
        if self._published_load_active:
            return
        self._published_load_active = True
        self.save_state.setText("Loading published post… your draft is safe")

        def load():
            try:
                post = poster.get_post(post_id)
                draft = None if action == "attach" else self._draft_from_remote(post)
                if draft is not None and (
                    (getattr(draft, "caption", "") or "").strip()
                    or (getattr(draft, "body_blocks", "") or "").strip()
                ):
                    session.add_draft(draft)
                self._published_bridge.loaded.emit({
                    "post": post,
                    "draft": draft,
                    "action": action,
                    "session": session,
                    "active_id": active_id,
                })
            except Exception as exc:  # noqa: BLE001
                self._published_bridge.failed.emit(exc)

        threading.Thread(
            target=load, name="coldsnap-open-published", daemon=True,
        ).start()

    def _published_post_loaded(self, result: dict) -> None:
        self._published_load_active = False
        session = result["session"]
        if result["action"] == "attach":
            draft = session.load_draft(result["active_id"])
            if draft is None:
                self._published_post_failed("The saved draft could not be reopened.")
                return
            post = result["post"]
            draft.remote_post_id = int(post.get("id") or 0)
            draft.post_date = str(post.get("created_at") or draft.post_date or "")
            draft.slug = str(post.get("slug") or draft.slug or "")
            session.save_draft(draft)
            self._edit(draft)
            self.save_state.setText("Draft attached — changes will update the published post")
        else:
            draft = result["draft"]
            self._edit(draft)
            self.save_state.setText("Published post opened separately — your draft is still safe")
        self.rail.refresh_batches()

    def _published_post_failed(self, error) -> None:
        self._published_load_active = False
        self.save_state.setText("Could not open published post — your draft is still safe")
        if isinstance(error, AuthorizationExpiredError):
            from .connect_panel import prompt_reauthentication
            prompt_reauthentication(self, str(error), long_form=True)
            return
        QMessageBox.critical(self, "Could not open post", str(error))

    def _draft_from_remote(self, post):
        draft = O.Draft(draft_id=O._new_id(), kind=O.KIND_SMACKTALK,
                        mode=self.SUITE_MODE)
        draft.remote_post_id = int(post.get("id") or 0)
        draft.slug = str(post.get("slug") or "")
        draft.title = str(post.get("title") or "")
        draft.caption = str(post.get("content") or "")
        # SS-1612. This used to stop at the line above: the site's whole HTML
        # body went into draft.caption and body_blocks was left empty, so
        # Open published never parsed anything. BIGGIE then had nothing but one
        # blob of HTML to show and printed it as literal characters --
        # <p>[mosaic:752]prose…</p> on the canvas, with no mosaic rendering.
        #
        # parse_site_html reads the PUBLISHED shape (prose wrapped in <p>, a
        # mosaic or image shortcode stored inside the paragraph it belongs to)
        # and keeps each block's exact bytes, so an untouched post sends back
        # byte for byte. See the invariant in biggie.py.
        draft.body_blocks = biggie.blocks_to_json(
            biggie.parse_site_html(draft.caption))
        draft.colophon = str(post.get("colophon") or "")
        draft.img_status = str(post.get("status") or "draft")
        draft.post_date = str(post.get("created_at") or "")
        draft.tags = " ".join("#" + str(tag).lstrip("#") for tag in (post.get("tags") or []))
        draft.category_ids = [int(value) for value in (post.get("cat_ids") or [])]
        draft.album_ids = [int(value) for value in (post.get("album_ids") or [])]
        cover_id = int(post.get("featured_image_id") or 0)
        cache = os.path.join(tempfile.gettempdir(), "coldsnap-published",
                             str(draft.remote_post_id))
        os.makedirs(cache, exist_ok=True)
        rows = list(post.get("bucket") or [])

        def _fetch_one(item):
            pos, row = item
            image_id = int(row.get("id") or 0)
            source_url = str(row.get("url") or row.get("thumb_url") or "")
            filename = os.path.basename(source_url.split("?", 1)[0]) or f"image-{image_id}.jpg"
            target = os.path.join(cache, f"{image_id}-{filename}")
            if not os.path.isfile(target) and source_url:
                response = requests.get(source_url, timeout=90)
                response.raise_for_status()
                with open(target, "wb") as handle:
                    handle.write(response.content)
            return O.DraftImage(
                local_path=target, original_path=target, filename=filename,
                width=int(row.get("width") or 0), height=int(row.get("height") or 0),
                alt=str(row.get("img_alt") or ""), title=str(row.get("img_title") or ""),
                remote_path=str(row.get("img_file") or ""), remote_image_id=image_id,
                sort_position=pos, is_cover=(image_id == cover_id))

        if rows:
            with ThreadPoolExecutor(max_workers=4) as pool:
                draft.images = list(pool.map(_fetch_one, enumerate(rows)))
        if draft.images and not any(image.is_cover for image in draft.images):
            draft.images[0].is_cover = True
        return draft

    def accept_handoff(self, rows):
        """Start a new COLD TAKE bucket from photographs selected in GYSS."""
        self._clear()
        cache = os.path.join(tempfile.gettempdir(), "coldsnap-gyss")
        os.makedirs(cache, exist_ok=True)
        bucket = []
        try:
            for pos, row in enumerate(rows):
                image_id = int(row.get("id") or 0)
                source_url = str(row.get("full_url") or row.get("thumb_url") or "")
                filename = str(row.get("filename") or os.path.basename(source_url.split("?", 1)[0])
                               or f"image-{image_id}.jpg")
                target = os.path.join(cache, f"{image_id}-{filename}")
                if not os.path.isfile(target):
                    response = requests.get(source_url, timeout=90)
                    response.raise_for_status()
                    with open(target, "wb") as handle:
                        handle.write(response.content)
                bucket.append(O.DraftImage(
                    local_path=target, original_path=target, filename=filename,
                    width=int(row.get("width") or 0), height=int(row.get("height") or 0),
                    alt=str(row.get("alt") or ""), remote_image_id=image_id,
                    sort_position=pos, is_cover=(pos == 0)))
        except Exception as exc:  # noqa: BLE001
            QMessageBox.critical(self, "Could not receive photographs", str(exc))
            return
        self._bucket = bucket
        self._cover_idx = 0
        self._selected_photo_idx = 0 if bucket else None
        self._refresh_bucket()
        self._changed()
        self.save_state.setText(f"{len(bucket)} photographs received from GYSS")

    def _add_photo_paths(self, paths, *, insert=False):
        added = []
        for p in paths:
            if len(self._bucket) >= O.SMACKTALK_BUCKET_MAX:
                QMessageBox.information(
                    self, "That's the lot",
                    f"An essay holds up to {O.SMACKTALK_BUCKET_MAX} photos.")
                break
            image = O.DraftImage(local_path=p, filename=os.path.basename(p))
            image.original_path = os.path.abspath(p)
            self._bucket.append(image)
            added.append(image)
        if paths and not self.title_edit.text().strip():
            self.title_edit.setText(os.path.splitext(os.path.basename(paths[0]))[0])
        if added:
            self._selected_photo_idx = len(self._bucket) - len(added)
        self._refresh_bucket()
        if insert:
            for image in added:
                self.body.canvas.insert_image("asset:" + image.asset_uuid,
                                              alt=image.alt, caption=image.caption)
        if added:
            self._changed()

    def _add_and_insert_photo(self):
        from .pickers import pick_images
        paths = pick_images(self, (self.app_config() or {}).get("url", ""))
        self._add_photo_paths(paths, insert=True)

    def _paste_photo(self, image):
        if image is None or image.isNull():
            return
        target = os.path.join(tempfile.gettempdir(), "coldsnap-pasted-" + uuid.uuid4().hex + ".png")
        if image.save(target, "PNG"):
            self._add_photo_paths([target], insert=True)

    def _move(self, idx: int, delta: int):
        j = idx + delta
        if 0 <= j < len(self._bucket):
            self._bucket[idx], self._bucket[j] = self._bucket[j], self._bucket[idx]
            if self._selected_photo_idx == idx:
                self._selected_photo_idx = j
            elif self._selected_photo_idx == j:
                self._selected_photo_idx = idx
            if self._cover_idx == idx:
                self._cover_idx = j
            elif self._cover_idx == j:
                self._cover_idx = idx
            self._refresh_bucket()
            self._changed()

    def _remove(self, idx: int):
        self._remove_from_tray([idx], in_post_fn=lambda _used: "remove_both")

    def _strip_photos_from_blocks(self, remove_indices: list[int]):
        """Remove blocks/entries referencing removed tray indices and remap 1-based refs."""
        remove_set = set(remove_indices)
        remove_uuids = {
            str(getattr(self._bucket[i], "asset_uuid", "") or "")
            for i in remove_set
            if 0 <= i < len(self._bucket) and getattr(self._bucket[i], "asset_uuid", "")
        }
        remove_pos = {i + 1 for i in remove_set}

        def _matches(identity) -> bool:
            key = str(identity or "").strip()
            if not key:
                return False
            if key in remove_uuids:
                return True
            if key.startswith("asset:") and key[6:] in remove_uuids:
                return True
            if key.startswith("bucket:") and key[7:].isdigit():
                return int(key[7:]) in remove_pos
            if key.isdigit():
                return int(key) in remove_pos
            return False

        def _remap_identity(identity):
            key = str(identity or "").strip()
            if key.startswith("bucket:") and key[7:].isdigit():
                pos = int(key[7:])
                shift = sum(1 for r in remove_set if r < pos - 1)
                return f"bucket:{pos - shift}"
            if key.isdigit():
                pos = int(key)
                shift = sum(1 for r in remove_set if r < pos - 1)
                remapped = pos - shift
                return remapped if isinstance(identity, int) else str(remapped)
            return identity

        def _clean_list(items):
            out = []
            for node in items:
                if not isinstance(node, dict):
                    continue
                kind = node.get("type")
                if kind == "image":
                    ident = node.get("asset_uuid") or node.get("img_id")
                    if _matches(ident):
                        continue
                    copied = dict(node)
                    if not copied.get("asset_uuid") and copied.get("img_id"):
                        old_id = copied["img_id"]
                        new_id = _remap_identity(old_id)
                        copied["img_id"] = new_id
                        if new_id != old_id and copied.get("raw"):
                            copied["raw"] = copied["raw"].replace(str(old_id), str(new_id), 1)
                    out.append(copied)
                elif kind == "mosaic":
                    order = node.get("order") or []
                    retained = [i for i, identity in enumerate(order) if not _matches(identity)]
                    kept = [_remap_identity(order[i]) for i in retained]
                    if order and not kept:
                        continue
                    copied = dict(node)
                    copied["order"] = kept
                    if copied.get("mirrors"):
                        copied["mirrors"] = biggie.remap_mosaic_mirrors(copied["mirrors"], retained)
                    if copied.get("weights"):
                        copied["weights"] = {
                            str(_remap_identity(key)): weight
                            for key, weight in copied["weights"].items()
                            if not _matches(key)
                        }
                    out.append(copied)
                elif kind == "columns":
                    copied = dict(node)
                    key = "cols" if "cols" in node else "columns"
                    cols = node.get(key) or []
                    copied[key] = [_clean_list(col) for col in cols]
                    out.append(copied)
                else:
                    out.append(dict(node))
            return out

        try:
            blocks = self.body.authoring_blocks()
        except Exception:
            return
        cleaned = _clean_list(blocks)
        if cleaned == blocks:
            return
        if getattr(self.body, "_biggie_on", False):
            self.body.biggie.from_blocks(cleaned)
        else:
            self.body.editor.setPlainText(biggie.serialize_blocks(cleaned))

    def _remove_from_tray(self, indices=None, *, in_post_fn=None, exact=False) -> bool:
        """Remove one or more photos from the tray only (never deletes from disk)."""
        if not self._bucket:
            return False
        selected = [
            i for i in getattr(self, "_selected_photo_indices", [])
            if 0 <= i < len(self._bucket)
        ]
        if indices is not None:
            raw = [int(i) for i in indices if 0 <= int(i) < len(self._bucket)]
            if not exact and len(raw) == 1 and raw[0] in selected and len(selected) > 1:
                targets = sorted(set(selected))
            else:
                targets = sorted(set(raw))
        else:
            if selected:
                targets = sorted(set(selected))
            elif self._selected_photo_idx is not None and 0 <= self._selected_photo_idx < len(self._bucket):
                targets = [self._selected_photo_idx]
            else:
                targets = []
        if not targets:
            return False

        used = self._used_photo_indices()
        in_post = [i for i in targets if i in used]
        if in_post:
            if in_post_fn is not None:
                choice = in_post_fn(in_post)
            else:
                box = QMessageBox(self)
                box.setWindowTitle("Photo already in post")
                noun = "This photo is" if len(in_post) == 1 else f"{len(in_post)} selected photos are"
                box.setText(f"{noun} already placed in the post.")
                box.setInformativeText("Remove from the post as well, or leave in the post?")
                btn_both = box.addButton("Remove from post too", QMessageBox.DestructiveRole)
                btn_leave = box.addButton("Leave in post", QMessageBox.AcceptRole)
                box.addButton("Cancel", QMessageBox.RejectRole)
                box.exec()
                clicked = box.clickedButton()
                if clicked == btn_both:
                    choice = "remove_both"
                elif clicked == btn_leave:
                    choice = "leave_in_post"
                else:
                    choice = "cancel"
            if choice == "cancel":
                return False
            if choice == "leave_in_post":
                targets = [i for i in targets if i not in used]
                if not targets:
                    return False
            elif choice != "remove_both":
                return False

        self._strip_photos_from_blocks(targets)
        if self._cover_idx in targets:
            self._cover_idx = -1
        elif self._cover_idx >= 0:
            self._cover_idx -= sum(1 for i in targets if i < self._cover_idx)

        for idx in sorted(targets, reverse=True):
            del self._bucket[idx]

        self._selected_photo_indices = []
        if not self._bucket:
            self._selected_photo_idx = None
        elif self._selected_photo_idx is not None:
            if self._selected_photo_idx in targets:
                self._selected_photo_idx = min(targets[0], len(self._bucket) - 1)
            else:
                self._selected_photo_idx -= sum(1 for i in targets if i < self._selected_photo_idx)

        self._refresh_bucket()
        self._changed()
        return True

    @classmethod
    def _without_photo_references(cls, blocks, asset_uuid: str, position: int):
        """Remove one bucket photograph and renumber legacy numeric references."""
        cleaned = []
        for block in list(blocks or []):
            out = dict(block)
            kind = out.get("type")
            if kind == "image":
                image_asset = str(out.get("asset_uuid") or "")
                image_id = str(out.get("img_id") or "")
                if image_asset == asset_uuid or image_id == "asset:" + asset_uuid:
                    continue
                if image_id.startswith("bucket:") and image_id[7:].isdigit():
                    old_position = int(image_id[7:])
                    if old_position == position:
                        continue
                    if old_position > position:
                        out["img_id"] = "bucket:%d" % (old_position - 1)
            elif kind == "mosaic" and out.get("order"):
                order = []
                retained = []
                saved_weights = {str(key): weight for key, weight in (out.get("weights") or {}).items()}
                weights = {}
                for index, identity in enumerate(out["order"]):
                    key = str(identity)
                    if key == asset_uuid:
                        continue
                    if key.isdigit():
                        old_position = int(key)
                        if old_position == position:
                            continue
                        identity = old_position - 1 if old_position > position else old_position
                    order.append(identity)
                    retained.append(index)
                    if key in saved_weights:
                        weights[str(identity)] = saved_weights[key]
                if not order:
                    continue
                out["order"] = order
                if out.get("mirrors"):
                    out["mirrors"] = biggie.remap_mosaic_mirrors(out["mirrors"], retained)
                if "weights" in out:
                    out["weights"] = weights
            elif kind == "columns":
                out["cols"] = [
                    cls._without_photo_references(column, asset_uuid, position)
                    for column in out.get("cols", [])
                ]
            cleaned.append(out)
        return cleaned

    def _set_cover(self, idx: int):
        self._cover_idx = idx
        self._refresh_bucket()
        self._changed()

    def _build_photo_tools(self):
        """Selected-photo controls; edits stay beside, never inside, the source."""
        self.photo_tools = QFrame()
        self.photo_tools.setObjectName("SelectedPhotoTools")
        self.photo_tools.setMaximumWidth(340)
        col = QVBoxLayout(self.photo_tools)
        col.setContentsMargins(0, 8, 0, 0)
        col.setSpacing(7)

        self.selected_photo_label = QLabel("SELECT A PHOTO")
        self.selected_photo_label.setObjectName("BlockTrayGroup")
        col.addWidget(self.selected_photo_label)

        manage = QHBoxLayout()
        manage.setSpacing(4)
        up = QPushButton("▲")
        down = QPushButton("▼")
        self.featured_btn = QPushButton("★ Featured photo (landing tile)")
        self.featured_btn.setToolTip("Make the selected photo the post's featured landing tile (featured_image_id)")
        self.remove_photo_btn = QPushButton("Remove photo")
        self.remove_photo_btn.setObjectName("Danger")
        self.remove_photo_btn.setToolTip(
            "Remove the selected photograph from this draft. The source file is not deleted."
        )
        self.remove_photo_btn.setAccessibleName("Remove selected photo from draft")
        up.clicked.connect(lambda: self._manage_selected("up"))
        down.clicked.connect(lambda: self._manage_selected("down"))
        self.featured_btn.clicked.connect(lambda: self._manage_selected("lead"))
        self.remove_photo_btn.clicked.connect(lambda: self._manage_selected("remove"))
        for button in (up, down, self.featured_btn, self.remove_photo_btn):
            manage.addWidget(button)
        manage.addStretch(1)
        col.addLayout(manage)

        tools = QHBoxLayout()
        tools.setSpacing(4)
        self.crop_btn = QPushButton("Crop")
        self.caption_btn = QPushButton("Caption")
        self.metadata_btn = QPushButton("Metadata")
        for button in (self.crop_btn, self.caption_btn, self.metadata_btn):
            button.setObjectName("PhotoTool")
            button.setSizePolicy(QSizePolicy.Ignored, QSizePolicy.Preferred)
            tools.addWidget(button)
        self.caption_btn.setCheckable(True)
        self.metadata_btn.setCheckable(True)
        self.crop_btn.clicked.connect(self._crop_selected)
        self.caption_btn.clicked.connect(
            lambda checked: self._show_photo_detail("caption", checked))
        self.metadata_btn.clicked.connect(
            lambda checked: self._show_photo_detail("metadata", checked))
        col.addLayout(tools)

        self.photo_alt = QLineEdit()
        self.photo_alt.setPlaceholderText("ALT — one plain sentence describing this photo")
        self.photo_alt.textEdited.connect(
            lambda text: self._set_selected_field("alt", text.strip()))
        col.addWidget(self.photo_alt)

        self.caption_panel = QWidget()
        caption_col = QVBoxLayout(self.caption_panel)
        caption_col.setContentsMargins(0, 2, 0, 2)
        caption_col.setSpacing(4)
        caption_col.addWidget(field_label(
            "Caption — typed against this photo, carried in the post"))
        self.photo_caption = QPlainTextEdit()
        self.photo_caption.setMaximumHeight(78)
        self.photo_caption.setPlaceholderText("Caption")
        self.photo_caption.textChanged.connect(
            lambda: self._set_selected_field(
                "caption", self.photo_caption.toPlainText().strip()))
        caption_col.addWidget(self.photo_caption)
        self.caption_panel.setVisible(False)
        col.addWidget(self.caption_panel)

        self.metadata_panel = QWidget()
        metadata_col = QVBoxLayout(self.metadata_panel)
        metadata_col.setContentsMargins(0, 2, 0, 2)
        metadata_col.setSpacing(5)
        metadata_col.addWidget(field_label("Metadata — saved with this post"))
        form = QFormLayout()
        form.setContentsMargins(0, 0, 0, 0)
        form.setSpacing(5)
        self.photo_meta_fields = {}
        for attr, label in (
                ("title", "Title"), ("description", "Description"),
                ("credit", "Credit"), ("copyright", "Copyright"),
                ("place", "Place"), ("date_taken", "Date taken"),
                ("tags", "Keywords")):
            edit = QLineEdit()
            edit.textEdited.connect(
                lambda text, attr=attr: self._set_selected_field(attr, text.strip()))
            self.photo_meta_fields[attr] = edit
            form.addRow(label, edit)
        metadata_col.addLayout(form)
        metadata_col.addWidget(hint(
            "Kept in the post draft. Nothing is written into the image file."))
        self.metadata_panel.setVisible(False)
        col.addWidget(self.metadata_panel)

        self.slapper_btn = QPushButton("Edit in SNAP SLAPPER  ↗")
        self.slapper_btn.setObjectName("PhotoTool")
        self.slapper_btn.setToolTip(
            "Open this photograph in SNAP SLAPPER. Save a flattened copy "
            "beside it and COLD SNAP will bring that copy back.")
        self.slapper_btn.clicked.connect(self._start_slapper_handoff)
        col.addWidget(self.slapper_btn)
        self.slapper_hint = hint(
            "COLD SNAP keeps the original. Save the edited copy beside it in "
            "SNAP SLAPPER; COLD SNAP will notice when the file is complete.")
        col.addWidget(self.slapper_hint)

        self.slapper_state_panel = QFrame()
        self.slapper_state_panel.setObjectName("SlapperState")
        state_col = QVBoxLayout(self.slapper_state_panel)
        state_col.setContentsMargins(9, 9, 9, 9)
        state_col.setSpacing(5)
        self.slapper_state_title = QLabel()
        self.slapper_state_title.setObjectName("BlockTrayGroup")
        self.slapper_state_body = QLabel()
        self.slapper_state_body.setWordWrap(True)
        state_col.addWidget(self.slapper_state_title)
        state_col.addWidget(self.slapper_state_body)
        self.slapper_use_edit = QRadioButton("Use the edited version")
        self.slapper_use_original = QRadioButton("Use the original")
        self.slapper_use_edit.clicked.connect(
            lambda: self._choose_slapper_version(True))
        self.slapper_use_original.clicked.connect(
            lambda: self._choose_slapper_version(False))
        state_col.addWidget(self.slapper_use_edit)
        state_col.addWidget(self.slapper_use_original)
        state_actions = QHBoxLayout()
        self.slapper_primary = QPushButton()
        self.slapper_secondary = QPushButton()
        self.slapper_close = QPushButton("Close")
        self.slapper_close.setObjectName("Quiet")
        self.slapper_primary.clicked.connect(self._slapper_primary_action)
        self.slapper_secondary.clicked.connect(self._slapper_secondary_action)
        self.slapper_close.clicked.connect(self._stop_slapper_handoff)
        state_actions.addWidget(self.slapper_primary)
        state_actions.addWidget(self.slapper_secondary)
        state_actions.addWidget(self.slapper_close)
        state_col.addLayout(state_actions)
        self.slapper_state_panel.setVisible(False)
        col.addWidget(self.slapper_state_panel)
        self.photos_sec.add(self.photo_tools)

    def _start_slapper_handoff(self):
        image = self._selected_image()
        source = os.path.abspath(str(
            getattr(image, "local_path", "") if image else ""))
        if not image or not source or not os.path.isfile(source):
            self._slapper_launch_error = "The selected photograph is not available."
            self._set_slapper_state("launch-error")
            return
        configured = self._view_settings.value(
            "snap_slapper_executable", "", type=str)
        executable = find_executable(configured)
        if not executable:
            self._set_slapper_state("not-installed")
            return
        try:
            watcher = ReturnWatch.start(source)
            launch_slapper(executable, source)
        except Exception as exc:  # noqa: BLE001 - show the local launch failure
            self._slapper_launch_error = str(exc)
            self._set_slapper_state("launch-error")
            return
        image.slapper_base_path = source
        image.slapper_base_filename = image.filename or os.path.basename(source)
        image.slapper_base_edit_state = {
            name: getattr(image, name, 0)
            for name in ("edit_flip_h", "edit_flip_v", "edit_crop_left",
                         "edit_crop_top", "edit_crop_right", "edit_crop_bottom")
        }
        self._slapper_asset_uuid = str(image.asset_uuid)
        self._slapper_watch_session = watcher
        self._slapper_timer.start()
        self._set_slapper_state("waiting")
        self._changed()

    def _set_slapper_state(self, state: str):
        self._slapper_state = state
        self.slapper_state_panel.setVisible(state != "idle")
        returned = state == "returned"
        self.slapper_use_edit.setVisible(returned)
        self.slapper_use_original.setVisible(returned)
        self.slapper_close.setVisible(state in {"returned", "not-installed", "launch-error"})
        self.slapper_secondary.setVisible(True)
        self.slapper_state_panel.setObjectName(
            "SlapperError" if state in {"not-installed", "launch-error"}
            else "SlapperState")
        self.slapper_state_panel.style().unpolish(self.slapper_state_panel)
        self.slapper_state_panel.style().polish(self.slapper_state_panel)
        if state == "waiting":
            self.slapper_state_title.setText("WAITING FOR SNAP SLAPPER")
            self.slapper_state_body.setText(
                "Save a flattened copy beside the original. COLD SNAP will "
                "bring it back after the file finishes writing.")
            self.slapper_primary.setText("Bring editor forward")
            self.slapper_secondary.setText("Stop waiting")
        elif state == "returned":
            self.slapper_state_title.setText("EDITED COPY RETURNED")
            self.slapper_state_body.setText(
                "Both versions are safe. Choose which one this post should use.")
            self.slapper_primary.setText("Edit again")
            self.slapper_secondary.setText("Open folder")
        elif state == "not-installed":
            self.slapper_state_title.setText("SNAP SLAPPER NOT FOUND")
            self.slapper_state_body.setText(
                "Choose the installed SNAP SLAPPER app, then COLD SNAP can "
                "open the selected photograph in it.")
            self.slapper_primary.setText("Find SNAP SLAPPER…")
            self.slapper_secondary.setVisible(False)
        elif state == "launch-error":
            self.slapper_state_title.setText("SNAP SLAPPER COULD NOT START")
            self.slapper_state_body.setText(
                self._slapper_launch_error or "Windows did not start the editor.")
            self.slapper_primary.setText("Try again")
            self.slapper_secondary.setText("Choose another app…")

    def _slapper_primary_action(self):
        if self._slapper_state == "waiting":
            focus_window()
        elif self._slapper_state == "returned":
            self._start_slapper_handoff()
        elif self._slapper_state == "not-installed":
            self._browse_for_slapper()
        elif self._slapper_state == "launch-error":
            self._start_slapper_handoff()

    def _slapper_secondary_action(self):
        if self._slapper_state == "waiting":
            self._stop_slapper_handoff()
        elif self._slapper_state == "returned":
            image = self._image_for_uuid(self._slapper_asset_uuid)
            path = str(getattr(image, "slapper_edited_path", "") or "") if image else ""
            folder = os.path.dirname(os.path.abspath(path)) if path else ""
            if folder and os.path.isdir(folder) and hasattr(os, "startfile"):
                os.startfile(folder)  # noqa: S606 - owner-requested local folder
        elif self._slapper_state == "launch-error":
            self._browse_for_slapper()

    def _browse_for_slapper(self):
        path, _kind = QFileDialog.getOpenFileName(
            self, "Find SNAP SLAPPER", "", "Applications (*.exe);;All files (*)")
        if not path:
            return
        self._view_settings.setValue("snap_slapper_executable", path)
        self._start_slapper_handoff()

    def _stop_slapper_handoff(self):
        self._slapper_timer.stop()
        self._slapper_watch_session = None
        self._set_slapper_state("idle")

    def _image_for_uuid(self, asset_uuid: str):
        return next((image for image in self._bucket
                     if str(image.asset_uuid) == str(asset_uuid)), None)

    def _poll_slapper_return(self):
        if self._slapper_watch_session is None:
            self._slapper_timer.stop()
            return
        returned = self._slapper_watch_session.poll()
        if not returned:
            return
        image = self._image_for_uuid(self._slapper_asset_uuid)
        if image is None:
            self._stop_slapper_handoff()
            return
        try:
            from PIL import Image
            with Image.open(returned) as opened:
                opened.verify()
            with Image.open(returned) as opened:
                width, height = opened.size
        except Exception as exc:  # noqa: BLE001 - do not accept a broken return
            self._slapper_launch_error = f"The returned file is not a readable image: {exc}"
            self._slapper_timer.stop()
            self._set_slapper_state("launch-error")
            return
        image.slapper_edited_path = os.path.abspath(returned)
        image.slapper_use_edited = True
        image.local_path = image.slapper_edited_path
        image.filename = os.path.basename(returned)
        image.width, image.height = width, height
        image.thumb_square = ""
        image.thumb_aspect = ""
        for name in ("edit_flip_h", "edit_flip_v", "edit_crop_left",
                     "edit_crop_top", "edit_crop_right", "edit_crop_bottom"):
            setattr(image, name, False if name.startswith("edit_flip") else 0)
        self._slapper_timer.stop()
        self._slapper_watch_session = None
        self.slapper_use_edit.setChecked(True)
        self.slapper_use_original.setChecked(False)
        self._set_slapper_state("returned")
        self._refresh_bucket()
        self._changed()

    def _choose_slapper_version(self, use_edited: bool):
        image = self._image_for_uuid(self._slapper_asset_uuid)
        if image is None or not image.slapper_edited_path:
            return
        image.slapper_use_edited = bool(use_edited)
        if use_edited:
            image.local_path = image.slapper_edited_path
            image.filename = os.path.basename(image.slapper_edited_path)
            for name in ("edit_flip_h", "edit_flip_v", "edit_crop_left",
                         "edit_crop_top", "edit_crop_right", "edit_crop_bottom"):
                setattr(image, name, False if name.startswith("edit_flip") else 0)
        else:
            image.local_path = image.slapper_base_path or image.original_path
            image.filename = (image.slapper_base_filename or
                              os.path.basename(image.local_path))
            for name, value in (image.slapper_base_edit_state or {}).items():
                if hasattr(image, name):
                    setattr(image, name, value)
        image.thumb_square = ""
        image.thumb_aspect = ""
        self._refresh_bucket()
        self._changed()

    def _selected_image(self):
        index = self._selected_photo_idx
        if index is None or not (0 <= index < len(self._bucket)):
            return None
        return self._bucket[index]

    def _select_photo(self, idx: int, *, toggle: bool = False):
        if not (0 <= idx < len(self._bucket)):
            return
        used = self._used_photo_indices()
        cur = [i for i in getattr(self, "_selected_photo_indices", [])
               if 0 <= i < len(self._bucket) and i not in used]
        if toggle:
            if idx in cur:
                cur.remove(idx)
            else:
                cur.append(idx)
            self._selected_photo_indices = cur
            self._selected_photo_idx = cur[-1] if cur else None
        else:
            self._selected_photo_indices = [idx] if idx not in used else []
            self._selected_photo_idx = idx
        self._refresh_bucket()

    def _manage_selected(self, action: str):
        index = self._selected_photo_idx
        if index is None:
            return
        if action == "up":
            self._move(index, -1)
        elif action == "down":
            self._move(index, 1)
        elif action == "lead":
            self._set_cover(index)
        elif action == "remove":
            self._remove_from_tray([index])

    def _set_selected_field(self, attr: str, value: str):
        if self._updating_photo_fields:
            return
        image = self._selected_image()
        if image is None:
            return
        setattr(image, attr, value)
        self._changed()

    def _show_photo_detail(self, which: str, checked: bool):
        if which == "caption":
            self.metadata_btn.blockSignals(True)
            self.metadata_btn.setChecked(False)
            self.metadata_btn.blockSignals(False)
            self.metadata_panel.setVisible(False)
            self.caption_panel.setVisible(bool(checked))
        else:
            self.caption_btn.blockSignals(True)
            self.caption_btn.setChecked(False)
            self.caption_btn.blockSignals(False)
            self.caption_panel.setVisible(False)
            self.metadata_panel.setVisible(bool(checked))

    def _crop_selected(self):
        image = self._selected_image()
        if image is None:
            return
        dialog = QDialog(self)
        dialog.setWindowTitle("Crop this photograph")
        layout = QVBoxLayout(dialog)
        layout.addWidget(hint(
            "Trim percentages from the four edges. The original is never changed."))
        form = QFormLayout()
        controls = {}
        for attr, label in (
                ("edit_crop_left", "Left %"),
                ("edit_crop_top", "Top %"),
                ("edit_crop_right", "Right %"),
                ("edit_crop_bottom", "Bottom %")):
            spin = QSpinBox()
            spin.setRange(0, 90)
            spin.setSuffix(" %")
            spin.setValue(int(getattr(image, attr, 0) or 0))
            controls[attr] = spin
            form.addRow(label, spin)
        layout.addLayout(form)
        reset = QPushButton("Reset crop")
        reset.clicked.connect(lambda: [control.setValue(0)
                                      for control in controls.values()])
        layout.addWidget(reset)
        buttons = QDialogButtonBox(QDialogButtonBox.Cancel | QDialogButtonBox.Ok)
        buttons.accepted.connect(dialog.accept)
        buttons.rejected.connect(dialog.reject)
        layout.addWidget(buttons)
        if dialog.exec() != QDialog.Accepted:
            return
        left, right = controls["edit_crop_left"].value(), controls["edit_crop_right"].value()
        top, bottom = controls["edit_crop_top"].value(), controls["edit_crop_bottom"].value()
        if left + right > 95 or top + bottom > 95:
            QMessageBox.warning(
                self, "Crop is too small",
                "Leave at least five percent of the photograph in each direction.")
            return
        for attr, control in controls.items():
            setattr(image, attr, control.value())
        self._render_selected_edit()

    def _render_selected_edit(self):
        image = self._selected_image()
        if image is None:
            return
        try:
            render_non_destructive(image)
        except Exception as exc:  # noqa: BLE001
            QMessageBox.warning(self, "Could not apply photo edit", str(exc))
            return
        self._refresh_bucket()
        self._changed()

    def _populate_photo_tools(self):
        image = self._selected_image()
        enabled = image is not None
        self.photo_tools.setEnabled(enabled)
        if not enabled:
            self.selected_photo_label.setText("SELECT A PHOTO")
            if self._slapper_state != "waiting":
                self._slapper_asset_uuid = ""
                self._set_slapper_state("idle")
            return
        name = image.filename or os.path.basename(image.original_path or image.local_path)
        edited = bool(getattr(image, "slapper_use_edited", False))
        self.selected_photo_label.setText(
            f"SELECTED — {name}{' · EDITED' if edited else ''}")
        self._updating_photo_fields = True
        try:
            self.photo_alt.setText(getattr(image, "alt", "") or "")
            self.photo_caption.setPlainText(getattr(image, "caption", "") or "")
            for attr, edit in self.photo_meta_fields.items():
                edit.setText(str(getattr(image, attr, "") or ""))
        finally:
            self._updating_photo_fields = False
        if self._slapper_state != "waiting":
            if getattr(image, "slapper_edited_path", ""):
                self._slapper_asset_uuid = str(image.asset_uuid)
                self.slapper_use_edit.blockSignals(True)
                self.slapper_use_original.blockSignals(True)
                self.slapper_use_edit.setChecked(edited)
                self.slapper_use_original.setChecked(not edited)
                self.slapper_use_edit.blockSignals(False)
                self.slapper_use_original.blockSignals(False)
                self._set_slapper_state("returned")
            else:
                self._slapper_asset_uuid = ""
                self._set_slapper_state("idle")

    def _refresh_bucket(self):
        while self.bucket_col.count():
            item = self.bucket_col.takeAt(0)
            w = item.widget()
            if w:
                w.deleteLater()
        while self.in_mosaics_thumbs.count():
            item = self.in_mosaics_thumbs.takeAt(0)
            w = item.widget()
            if w:
                w.deleteLater()

        n = len(self._bucket)
        self.body.set_bucket([im.local_path for im in self._bucket])   # mosaics repaint
        self.body.canvas.set_assets({im.asset_uuid: im.local_path for im in self._bucket})
        default_caps = {}
        for i, im in enumerate(self._bucket):
            cap_val = (im.caption or im.title or "").strip()
            default_caps[str(im.asset_uuid)] = cap_val
            default_caps[f"bucket:{i + 1}"] = cap_val
        self.body.canvas.default_captions = default_caps
        self.bucket_count.setText("")   # count lives in the header; this line = AI progress only

        # Count photos placed in mosaic blocks on the canvas
        mosaic_paths = []
        if hasattr(self.body, "canvas") and hasattr(self.body.canvas, "to_blocks"):
            for blk in self.body.canvas.to_blocks():
                if isinstance(blk, dict) and blk.get("type") == "mosaic":
                    for p in (blk.get("paths") or blk.get("images") or []):
                        if p and p not in mosaic_paths:
                            mosaic_paths.append(p)
        self.in_mosaics_lbl.setText(f"In mosaics — {len(mosaic_paths)}")
        for p in mosaic_paths[:5]:
            thumb = QLabel()
            thumb.setFixedSize(18, 18)
            thumb.setPixmap(load_pixmap(p, 18))
            self.in_mosaics_thumbs.addWidget(thumb)
        self.in_mosaics_bar.setVisible(bool(mosaic_paths))

        used_indices = self._used_photo_indices()
        self._last_used_indices = frozenset(used_indices)
        left_count = max(0, n - len(used_indices))
        self.photos_sec.header.setText(
            f"THE PHOTOS — {left_count} left" if n else "THE PHOTOS — none yet")
        if not self._bucket:
            self._selected_photo_idx = None
            self._selected_photo_indices = []
            if hasattr(self, "insert_into_post_btn"):
                self.insert_into_post_btn.setVisible(False)
            if hasattr(self, "insert_mosaic_btn"):
                self.insert_mosaic_btn.setVisible(False)
            self._populate_photo_tools()
            self.bucket_col.addWidget(hint("No photos yet — click “Add photos…”."), 0, 0)
            return

        unplaced = [i for i in range(len(self._bucket)) if i not in used_indices]
        cur_sel = [
            i for i in getattr(self, "_selected_photo_indices", [])
            if 0 <= i < len(self._bucket) and i not in used_indices
        ]
        if not cur_sel:
            if (self._selected_photo_idx is not None
                    and 0 <= self._selected_photo_idx < len(self._bucket)
                    and self._selected_photo_idx not in used_indices):
                cur_sel = [self._selected_photo_idx]
            elif unplaced:
                cur_sel = [unplaced[0]]
        self._selected_photo_indices = cur_sel
        if cur_sel:
            if self._selected_photo_idx not in cur_sel:
                self._selected_photo_idx = cur_sel[0]
        elif self._selected_photo_idx is None or self._selected_photo_idx >= len(self._bucket):
            self._selected_photo_idx = 0
        if hasattr(self, "insert_into_post_btn"):
            self.insert_into_post_btn.setVisible(len(cur_sel) == 1)
        if hasattr(self, "insert_mosaic_btn"):
            self.insert_mosaic_btn.setVisible(len(cur_sel) >= 2)

        zoom = getattr(self, "_thumb_zoom", 100)
        if zoom >= 135:
            cols, tile_px = 2, 146
        elif zoom >= 110:
            cols, tile_px = 3, 96
        elif zoom >= 85:
            cols, tile_px = 3, 88
        else:
            cols, tile_px = 4, 66

        selected_set = set(self._selected_photo_indices)
        visible_pos = 0
        for i, im in enumerate(self._bucket):
            if i in used_indices:
                continue
            is_cover = (i == self._cover_idx)
            is_selected = (i in selected_set)
            edited = bool(getattr(im, "slapper_edited_path", ""))
            in_slapper = (
                self._slapper_state == "waiting"
                and str(getattr(im, "asset_uuid", "")) == self._slapper_asset_uuid
            )
            pix = load_pixmap(im.thumb_aspect or im.local_path, tile_px)
            ori_code, ratio = photo_orientation_badge(im.width, im.height, im.local_path)
            tile = PhotoThumbTile(
                i, pix, selected=is_selected, is_cover=is_cover,
                edited=edited, in_slapper=in_slapper, tile_size=tile_px,
                asset_uuid=str(getattr(im, "asset_uuid", "")),
                orientation=ori_code)
            name = im.filename or os.path.basename(im.local_path)
            tile.setToolTip(f"{name}\n{ori_code} · {ratio:.2f}:1" if ori_code else name)
            tile.setAccessibleName(
                "Selected photograph" if is_selected else "Select photograph")
            tile.selectionClicked.connect(
                lambda idx, toggle: self._select_photo(idx, toggle=toggle))
            tile.setFeaturedRequested.connect(self._set_featured_photo)
            tile.removeRequested.connect(lambda idx: self._remove_from_tray([idx]))
            self.bucket_col.addWidget(tile, visible_pos // cols, visible_pos % cols)
            visible_pos += 1
        if visible_pos == 0:
            self.bucket_col.addWidget(
                hint("All photos are placed on the page — remove one from the page to return it here."),
                0, 0)
        self._populate_photo_tools()

    def _ai_fill(self):
        if not self._bucket:
            QMessageBox.warning(self, "No photos", "Add photos first.")
            return
        from .enrich_worker import EnrichWorker
        imgs = list(self._bucket)
        self._ai_worker = EnrichWorker(self.app_config() or {})

        def _one_done(idx, meta, imgs=imgs):
            if not (0 <= idx < len(imgs)):
                return
            imgs[idx].apply_enrichment(meta)
            # One call per photograph supplies its ALT.  The lead photograph
            # supplies post-level metadata; keep authored values intact.
            if idx == self._cover_idx:
                if meta.get("title") and not self.title_edit.text().strip():
                    self.title_edit.setText(meta["title"])
                if meta.get("caption") and not self.body.toPlainText().strip():
                    self.body.set_state(meta["caption"], "")
                if meta.get("tags") and not self.tags_edit.text().strip():
                    self.tags_edit.setText(meta["tags"])
                self._ai_post_meta = dict(meta)

        self._ai_worker.image_done.connect(_one_done)
        self._ai_worker.progressed.connect(
            lambda done, total: self.bucket_count.setText(
                f"AI FILL… photo {done} of {total} (Gemini)"))
        self._ai_worker.finished.connect(self._refresh_bucket)
        self._ai_worker.failed.connect(
            lambda msg: (self._refresh_bucket(),
                         QMessageBox.critical(self, "AI FILL failed", msg)))
        self.bucket_count.setText(f"AI FILL… photo 1 of {len(imgs)} (Gemini)")
        self._ai_worker.start([im.local_path for im in imgs])

    def _set_featured_photo(self, idx: int):
        if 0 <= idx < len(self._bucket):
            self._cover_idx = idx
            self._selected_photo_idx = idx
            self._refresh_bucket()
            self._changed()

    def _update_linked_banner(self):
        if not hasattr(self, "linked_post_lbl"):
            return
        rid = int(getattr(self, "_remote_post_id", 0) or 0)
        if rid:
            t = self.title_edit.text().strip() or f"Post #{rid}"
            slug = str(getattr(self, "_remote_slug", "") or "").strip().lstrip("/")
            loc = f"/{slug}" if slug else f"#{rid}"
            self.linked_post_lbl.setText(f"Editing: {t}  {loc}")
            col = (getattr(self, "_surface_palette", None) or {}).get("eyebrow", "#2b7a1e")
            self.linked_post_lbl.setStyleSheet(
                f"QLabel#LinkedPostStatus {{ background: transparent; color: {col};"
                " font-size: 11px; font-weight: 700; }}"
            )
            self.linked_post_lbl.setVisible(True)
        else:
            self.linked_post_lbl.setVisible(False)

    def _edit(self, draft: O.Draft):
        self._loading = True
        self._editing_id = draft.draft_id
        self._remote_post_id = int(getattr(draft, "remote_post_id", 0) or 0)
        self._remote_slug = str(getattr(draft, "slug", "") or "")
        self.title_edit.setText(draft.title)
        self.colophon_edit.setText(draft.colophon)
        self.signature_check.setChecked(draft.signature_enabled)
        self.tags_edit.setText(draft.tags)
        self.status_combo.setCurrentText(draft.img_status)
        self._bucket = [O.DraftImage(local_path=im.local_path, filename=im.filename,
                                     thumb_square=im.thumb_square, is_cover=im.is_cover,
                                     **{name: getattr(im, name)
                                        for name in O.DraftImage.__dataclass_fields__
                                        if name not in {"local_path", "filename", "thumb_square",
                                                        "is_cover"}})
                        for im in draft.images]
        self._cover_idx = next((i for i, im in enumerate(self._bucket) if im.is_cover), 0)
        self._selected_photo_idx = 0 if self._bucket else None
        self._ai_post_meta = {
            "category": getattr(draft, "category", ""),
            "album": getattr(draft, "album", ""),
            "orientation": getattr(draft, "orientation", "auto"),
            "color_mode": getattr(draft, "color_mode", ""),
            "colors": getattr(draft, "ai_colors", ""),
        }
        # Populate bucket + canvas.assets BEFORE set_state() so restored mosaics resolve immediately.
        self._refresh_bucket()
        self.body.set_state(draft.caption, getattr(draft, "body_blocks", ""))
        self.body.editor.moveCursor(QTextCursor.End)   # so an insert lands where writing resumes
        self._refresh_bucket()
        self._update_linked_banner()
        self._loading = False
        self._update_linked_banner()
        self.save_state.setText("Safe on this computer")

    def _clear(self):
        self._loading = True
        self._editing_id = None
        self._remote_post_id = 0
        self._remote_slug = ""
        self.title_edit.clear()
        self.colophon_edit.clear()
        self.signature_check.setChecked(True)
        self.tags_edit.clear()
        self.status_combo.setCurrentText("published")
        self.body.clear()
        self._bucket = []
        self._cover_idx = 0
        self._selected_photo_idx = None
        self._ai_post_meta = {}
        self._refresh_bucket()
        self._update_linked_banner()
        self._loading = False
        self.save_state.setText("Begin the story… changes will be safe on this computer.")

    def _changed(self):
        if self._loading:
            return
        if self._bucket:
            used = frozenset(self._used_photo_indices())
            if used != getattr(self, "_last_used_indices", frozenset()):
                self._refresh_bucket()
        self.save_state.setText("Saving…")
        self._autosave.start()

    def _wire_blocks(self, blocks):
        """Translate stable local UUIDs to today's server bucket positions."""
        positions = {str(im.asset_uuid): i + 1 for i, im in enumerate(self._bucket)}
        def convert(block):
            out = dict(block)
            if out.get("type") == "image":
                show_cap = bool(out.get("show_caption"))
                cap_text = str(out.get("caption") or "").strip()
                if out.get("asset_uuid"):
                    pos = positions.get(str(out["asset_uuid"]))
                    if pos:
                        out["img_id"] = f"bucket:{pos}"
                        if cap_text and 1 <= pos <= len(self._bucket):
                            self._bucket[pos - 1].caption = cap_text
                    for key in ("asset_uuid", "placement", "width_ratio", "crop", "alt", "link"):
                        out.pop(key, None)
                if show_cap and cap_text:
                    out["show_caption"] = True
                    out["caption"] = cap_text
                    out.pop("raw", None)
                else:
                    out.pop("caption", None)
                    out.pop("show_caption", None)
            if out.get("type") == "mosaic" and out.get("order"):
                biggie.wire_mosaic_ids(out, positions)
            if out.get("type") == "columns":
                out["cols"] = [[convert(child) for child in col] for col in out.get("cols", [])]
            return out
        return [convert(block) for block in blocks]

    def _build_draft(self, session, *, authored=None):
        draft = (session.load_draft(self._editing_id) if self._editing_id else None) \
            or O.Draft(draft_id=O._new_id(), kind=O.KIND_SMACKTALK, mode=self.SUITE_MODE)
        self._editing_id = draft.draft_id
        if getattr(self, "_remote_post_id", 0):
            draft.remote_post_id = int(self._remote_post_id)
        if getattr(self, "_remote_slug", ""):
            draft.slug = str(self._remote_slug)
        draft.title = self.title_edit.text().strip()
        draft.colophon = self.colophon_edit.text().strip()
        draft.signature_enabled = self.signature_check.isChecked()
        draft.tags = self.tags_edit.text().strip()
        if authored is None:
            authored = self.body.authoring_blocks()
        draft.body_blocks = biggie.blocks_to_json(authored)
        draft.caption = biggie.serialize_blocks(self._wire_blocks(authored)).strip()
        draft.img_status = self.status_combo.currentText()
        meta = getattr(self, "_ai_post_meta", {}) or {}
        for attr, key, default in (("category", "category", ""), ("album", "album", ""),
                                   ("orientation", "orientation", "auto"),
                                   ("color_mode", "color_mode", ""), ("ai_colors", "colors", "")):
            setattr(draft, attr, meta.get(key, default) or default)
        draft.images = []
        for i, im in enumerate(self._bucket):
            saved = O.DraftImage.from_dict(im.to_dict())
            saved.filename = im.filename or os.path.basename(im.local_path)
            saved.sort_position, saved.is_cover = i, (i == self._cover_idx)
            draft.images.append(saved)
        return draft

    def _autosave_now(self):
        if not self._has_open_draft_content():
            self.save_state.setText("Begin the story… changes will be safe on this computer.")
            return True
        # Never auto-save an unmodified empty remote post as a duplicate 0-block draft
        if getattr(self, "_remote_post_id", 0):
            has_body = bool(self.body.toPlainText().strip()) or any(
                b.get("type") in ("image", "mosaic") for b in self.body.canvas.to_blocks()
            )
            on_disk = bool(self._editing_id and self.rail.ensure_session().load_draft(self._editing_id))
            if not has_body and not on_disk:
                return True
        try:
            # Typing must never decode originals: only photos whose thumbs are
            # missing or stale (new photo, crop/focus/zoom change, new edit)
            # are baked, and that happens on a worker thread.
            self._queue_stale_thumbs()
            session = self.rail.ensure_session()
            authored = self.body.authoring_blocks()
            draft = self._build_draft(session, authored=authored)
            # Autosave records the draft only; copying photos into the
            # session store happens on an explicit save.
            session.add_draft(draft, copy_images=False)
            self._mirror_sqlite(draft, authored=authored)
            self.save_state.setText("Safe on this computer")
            return True
        except Exception as exc:  # noqa: BLE001
            self.save_state.setText(f"Needs attention — could not save: {exc}")
            return False

    def _queue_stale_thumbs(self):
        """Start a background thumb job for bucket photos whose thumbs are stale."""
        if self._thumb_job is not None:
            return  # the finishing job re-triggers autosave, which re-checks
        stale = [im for im in self._bucket
                 if im.local_path and os.path.isfile(im.local_path)
                 and not O.image_thumbs_current(im)]
        if not stale:
            return
        jobs = [O.DraftImage.from_dict(im.to_dict()) for im in stale]
        if self._thumb_executor is None:
            from concurrent.futures import ThreadPoolExecutor
            self._thumb_executor = ThreadPoolExecutor(
                max_workers=1, thread_name_prefix="coldtake-thumbs")

        def work(copies):
            done = []
            for copy in copies:
                try:
                    if O.generate_image_thumbs(copy):
                        done.append(copy)
                except Exception:  # noqa: BLE001 - one bad photo must not stop the rest
                    pass
            return done

        self._thumb_job = self._thumb_executor.submit(work, jobs)
        self._thumb_job.add_done_callback(self._thumbsReady.emit)

    def _apply_thumb_results(self, future):
        """UI thread: copy finished thumbs onto photos that have not changed since."""
        if future is not self._thumb_job:
            return
        self._thumb_job = None
        try:
            done = future.result()
        except Exception:  # noqa: BLE001
            done = []
        applied = 0
        for copy in done:
            image = self._image_for_uuid(copy.asset_uuid)
            if image is None or O.thumb_signature(image) != copy.thumb_params:
                continue  # removed, or edited again while baking: next pass redoes it
            for name in ("width", "height", "thumb_square", "thumb_aspect", "thumb_params"):
                setattr(image, name, getattr(copy, name))
            if not image.filename:
                image.filename = copy.filename
            applied += 1
        if applied and not self._loading:
            self._autosave.start()  # persist the thumbs; re-checks for newer staleness

    def _wait_for_thumbs(self, timeout=None):
        """Block until the current background thumb job is applied (tests/explicit save)."""
        job = self._thumb_job
        if job is None:
            return
        try:
            job.result(timeout=timeout)
        except Exception:  # noqa: BLE001
            pass
        self._apply_thumb_results(job)

    def _mirror_sqlite(self, draft, *, authored=None):
        site = ((self.app_config() or {}).get("url") or "").strip()
        if not site:
            return
        stored = snap_library.draft(site, draft.draft_id)
        if stored is None:
            snap_library.create_draft(site, title=draft.title, draft_uuid=draft.draft_id)
            stored = snap_library.draft(site, draft.draft_id)
        known = {a["asset_uuid"] for a in stored["assets"]}
        for image in draft.images:
            if image.asset_uuid not in known and not image.remote_image_id:
                snap_library.absorb_draft_asset(
                    site, draft.draft_id, image.local_path, alt_text=image.alt,
                    caption=image.caption, asset_uuid=image.asset_uuid)
        snap_library.save_draft(site, draft.draft_id, title=draft.title,
                                status=draft.status,
                                blocks=authored if authored is not None else self.body.authoring_blocks())

    def _show_canvas_failure(self, message):
        self.canvas_warning.setText(
            f"{message} You can still save your draft. Reopen COLD SNAP to restore the preview.")
        self.canvas_warning.show()

    def _save(self, ready: bool, *, confirm_empty_fn=None):
        if not self._bucket:
            QMessageBox.warning(self, "No photos", "Add at least one photo first.")
            return
        try:
            session = self.rail.ensure_session()
            if ready and getattr(self, "_remote_post_id", None):
                has_body = bool(self.body.toPlainText().strip()) or any(
                    b.get("type") in ("image", "mosaic") for b in self.body.canvas.to_blocks()
                )
                if not has_body:
                    if confirm_empty_fn is not None:
                        confirmed = bool(confirm_empty_fn(self._remote_post_id))
                    else:
                        ans = QMessageBox.warning(
                            self,
                            "Empty draft over published post",
                            f"This draft has 0 blocks and is linked to published post #{self._remote_post_id}.\n\n"
                            "Queueing and sending it could overwrite the live post with empty content. Queue anyway?",
                            QMessageBox.Yes | QMessageBox.Cancel,
                            QMessageBox.Cancel,
                        )
                        confirmed = (ans == QMessageBox.Yes)
                    if not confirmed:
                        return
            self._wait_for_thumbs()  # reuse any background bake already in flight
            draft = self._build_draft(session)
            O.generate_draft_thumbs(draft)
            problems = draft.validate()
            if ready and problems:
                QMessageBox.warning(self, "Not ready", "\n".join(problems))
            draft.status = O.ST_READY if (ready and not problems) else O.ST_DRAFT
            session.add_draft(draft)
        except Exception as exc:
            traceback.print_exc()
            action = "queue this post" if ready else "save this draft"
            self.save_state.setText(f"Could not {action}")
            QMessageBox.critical(
                self, "Post could not be saved",
                f"COLD SNAP could not {action}. It is still open in the editor.\n\n{exc}")
            return

        try:
            self._mirror_sqlite(draft)
        except Exception as exc:
            traceback.print_exc()
            outcome = "queued" if draft.status == O.ST_READY else "saved as a draft"
            QMessageBox.warning(
                self, "Library update failed",
                f"The post was {outcome} on this computer. The library copy could not be updated.\n\n{exc}")
        self._clear()
        self.rail.refresh_batches()

    # -- network -------------------------------------------------------------
    def _signature_default(self, url):
        return self._view_settings.value(f"take/signatures/{site_key(url)}", 0, type=int)

    def _configure_signature(self):
        url = str((self.app_config() or {}).get("url") or "").strip()
        if not url:
            QMessageBox.warning(self, "No site", "Pick a site at the top first.")
            return
        host = site_key(url)
        value, accepted = QInputDialog.getInt(
            self, f"Blog signature for {host}",
            "CMS image ID for the signature (0 clears the default)",
            self._signature_default(url), 0, 2147483647,
        )
        if accepted:
            self._view_settings.setValue(f"take/signatures/{host}", value)

    def _poster_and_url(self):
        cfg = self.app_config() or {}
        url = (cfg.get("url") or "").strip()
        key = (cfg.get("smackpress_key") or "").strip()
        if not url:
            QMessageBox.warning(self, "No site", "Pick a site at the top first.")
            return None, url
        if not key:
            QMessageBox.warning(
                self, "No long-form key",
                "Essay posting needs this site's long-form key (a 'smackpress' key "
                "from SnapSmack Admin → API Access). Open Connection details at the "
                "top and paste it into the long-form key box.")
            return None, url
        from sumna_post import SumnaConnection, InsecureTransportError
        from .connect_panel import verify_site_mode
        probe_key = (cfg.get("api_key") or key).strip()
        try:
            conn = SumnaConnection(url, probe_key)
        except InsecureTransportError as e:
            QMessageBox.warning(self, "Insecure connection", str(e))
            return None, url
        if not verify_site_mode(self, conn, "smacktalk", "TAKE"):
            return None, url
        return SmacktalkPoster(url, key, signature_image_id=self._signature_default(url)), url

# ===== SNAPSMACK EOF =====
