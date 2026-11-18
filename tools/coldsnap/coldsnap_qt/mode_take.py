"""COLD SNAP Qt — COLD TAKE: a title, a story, and a bucket of photos
(long-form / SMACKTALK sites).

Feature parity with the Tk sumna_smacktalk panel; engines untouched. The
mosaic marker keeps its one-line plain-words explainer right beside the button.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import re
import tempfile
import uuid

import requests

from PySide6.QtCore import Qt, QRect, QSize, Signal, QTimer, QSettings
from PySide6.QtGui import QTextCursor, QPainter, QPixmap, QColor, QPen, QIcon, QKeySequence, QShortcut, QFont
from PySide6.QtWidgets import (
    QWidget, QHBoxLayout, QVBoxLayout, QGridLayout, QLabel, QLineEdit, QPlainTextEdit,
    QComboBox, QPushButton, QFileDialog, QMessageBox, QScrollArea, QFrame,
    QDialog, QDialogButtonBox, QListWidget, QListWidgetItem, QAbstractItemView,
    QSplitter, QFormLayout, QSpinBox, QSizePolicy, QRadioButton, QSlider,
)

import sumna_offline as O
import snap_library
from sumna_post import SmacktalkPoster

from . import theme, biggie
from .widgets import (Accordion, Card, build_rail, hint, field_label,
                      big_button, load_pixmap)
from .photo_loader import PhotoLoader
from .photo_edits import render_non_destructive
from .slapper_handoff import (ReturnWatch, find_executable,
                              focus_window, launch as launch_slapper)
from .body_editor import BodyEditor
from .block_tray import BlockAction, BlockTray
from .photo_tray import RightPhotosTray, PhotoThumbTile
from .drafts_panel import BatchRail, default_draft_row
from .mosaic_layout import tile_rects as _tile_rects


class MosaicPreview(QWidget):
    """Large live preview; dragging one tile onto another swaps their slots."""

    swapRequested = Signal(int, int)

    def __init__(self, parent=None):
        super().__init__(parent)
        self.setMinimumHeight(210)
        self.setToolTip("Drag one preview tile onto another to swap them.")
        self._tiles, self._rects = [], []
        self._layout_name, self._drag_from = "asymmetric", None
        # Codex's loader, c0b621f3. Decoding happens off the GUI thread with
        # QImageReader.setScaledSize, so a photograph is never decoded at full
        # resolution at all -- which is the part caching alone could not fix.
        self._photos = PhotoLoader(self)
        self._ready = {}

    def set_mosaic(self, tiles, layout_name):
        self._tiles = list(tiles)  # (row-in-list, local-path), in mosaic order
        self._layout_name = str(layout_name or "asymmetric")
        # Keep what is already decoded: ticking a 54th photograph must not make
        # the other 53 reload. The loader's own cache is keyed on
        # (path, mtime, size) and is the real store; this is just the widget's
        # view of it, bounded below.
        self.update()

    def _tile_pixmap(self, path, edge):
        """The decoded tile, or None while it is still being read.

        paintEvent cannot block, so this returns what is in hand and asks for
        anything missing. The loader answers on a 40ms timer and the callback
        repaints. A cache hit answers immediately, inside this call.
        """
        key = (path, edge)
        if key in self._ready:
            return self._ready[key]

        def arrived(pixmap, key=key):
            # Bounded so a slow drag-resize cannot grow this without limit;
            # every entry is recoverable from the loader's own cache.
            if len(self._ready) > 192:
                self._ready.clear()
            self._ready[key] = pixmap
            # Safe from inside paintEvent: update() SCHEDULES a repaint rather
            # than recursing, and the next paint finds the pixmap here and does
            # not ask again.
            self.update()

        # False means the loader already has four decodes in flight. Nothing to
        # do: those will finish, call their callbacks, and repaint us.
        self._photos.request(path, edge, arrived)
        return self._ready.get(key)

    @staticmethod
    def tile_rects(width, height, count, layout_name, gap=5):
        # One geometry for the dialog preview AND the BIGGIE canvas (mosaic_layout.py).
        return _tile_rects(width, height, count, layout_name, gap)

    def paintEvent(self, _event):
        painter = QPainter(self)
        painter.fillRect(self.rect(), QColor("#090b0a"))
        inner = self.rect().adjusted(7, 7, -7, -7)
        cells = self.tile_rects(inner.width(), inner.height(), len(self._tiles),
                                self._layout_name)
        self._rects = [(row, rect.translated(inner.topLeft()))
                       for (row, _path), rect in zip(self._tiles, cells)]
        painter.setRenderHint(QPainter.SmoothPixmapTransform, True)
        for position, ((row, path), cell) in enumerate(zip(self._tiles, cells), 1):
            rect = cell.translated(inner.topLeft())
            # THIS LINE WAS THE FREEZE. It was QPixmap(path): a full-resolution
            # decode from disk, uncached, for EVERY tile on EVERY paint event.
            # paintEvent runs on every repaint -- ticking a photo, resizing,
            # dragging a tile, the window regaining focus -- so a mosaic of 53
            # SNAP SLAPPER originals re-decoded all 53 at full size each time,
            # on the GUI thread. That is why the mosaic step stopped responding.
            #
            # It now goes through Codex's PhotoLoader (c0b621f3), which decodes
            # OFF the GUI thread and at reduced size via
            # QImageReader.setScaledSize -- so these photographs are never
            # decoded at full resolution at all. Caching alone fixed the
            # repaints but left the first paint doing 53 full decodes, about
            # 2.8 seconds; this removes that too.
            #
            # The size is still quantised to 64px steps because it is part of
            # the cache key, and without that a slow drag-resize would ask for
            # a size it had never seen on every single frame.
            #
            # Deliberately NOT thumb_square, which the lists use: that is a
            # square crop, and this preview exists to show the whole
            # photograph -- see the comment below, which predates me.
            edge = max(64, -(-max(rect.width(), rect.height()) // 64) * 64)
            pix = self._tile_pixmap(path, edge) or QPixmap()
            if not pix.isNull():
                # Preview the whole photograph.  Cropping here made the composer
                # imply a crop the photographer had never chosen.
                scaled = pix.scaled(rect.size(), Qt.KeepAspectRatio,
                                    Qt.SmoothTransformation)
                painter.fillRect(rect, QColor("#141714"))
                target = QRect(rect.left() + (rect.width() - scaled.width()) // 2,
                               rect.top() + (rect.height() - scaled.height()) // 2,
                               scaled.width(), scaled.height())
                painter.drawPixmap(target, scaled)
            else:
                painter.fillRect(rect, QColor("#202320"))
            painter.setPen(QPen(QColor("#35ff14"), 2 if row == self._drag_from else 1))
            painter.drawRect(rect.adjusted(0, 0, -1, -1))
            badge = QRect(rect.left() + 4, rect.top() + 4, 24, 20)
            painter.fillRect(badge, QColor(0, 0, 0, 190))
            painter.setPen(QColor("#ffffff"))
            painter.drawText(badge, Qt.AlignCenter, str(position))

    def _row_at(self, point):
        return next((row for row, rect in self._rects if rect.contains(point)), None)

    def mousePressEvent(self, event):
        if event.button() == Qt.LeftButton:
            self._drag_from = self._row_at(event.position().toPoint())
            self.update()

    def mouseReleaseEvent(self, event):
        source, target = self._drag_from, self._row_at(event.position().toPoint())
        self._drag_from = None
        self.update()
        if source is not None and target is not None and source != target:
            self.swapRequested.emit(source, target)


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

    def __init__(self, app_config_provider, parent=None):
        super().__init__(parent)
        self.app_config = app_config_provider
        self._editing_id = None
        self._bucket = []          # list[O.DraftImage]
        self._cover_idx = 0
        self._selected_photo_idx = None
        self._updating_photo_fields = False
        self._loading = False
        self._view_settings = QSettings("SnapSmack", "COLD SNAP")
        self._slapper_state = "idle"
        self._slapper_watch_session = None
        self._slapper_asset_uuid = ""
        self._slapper_launch_error = ""

        outer = QHBoxLayout(self)
        outer.setContentsMargins(0, 0, 0, 0)
        outer.setSpacing(0)

        self.rail = BatchRail(self.SUITE_MODE, "post", self._poster_and_url,
                              row_builder=self._rows)

        # SIMPLE (text + the CMS shortcode bar) / BIGGIE (block stack) — one field.
        self.body = BodyEditor(allow_mosaic=True, simple_height=220)
        self.body.bar.hide()  # SHORTCODES live in the left rail (Slice 8)
        self.body.canvas.mosaicEditRequested.connect(self._canvas_mosaic)
        self.body.canvas.photographsDropped.connect(
            lambda paths: self._add_photo_paths(paths, insert=True))
        self.body.canvas.photographPasted.connect(self._paste_photo)
        # IMG picks from THIS site's cached Media Gallery (COLD STORAGE), by picture.
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

        self.page_meta = QLabel("")
        self.page_meta.setObjectName("PageMeta")
        card_col.addWidget(self.page_meta)
        self.site_colors_note = QLabel("site colours unavailable")
        self.site_colors_note.setObjectName("SiteColorsNote")
        self.site_colors_note.setVisible(False)
        card_col.addWidget(self.site_colors_note)
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

        brow = QHBoxLayout()
        brow.setSpacing(4)
        add_btn = QPushButton("Add photos…")
        add_btn.clicked.connect(self._add_photos)
        open_btn = QPushButton("Open published…")
        open_btn.setToolTip("Bring an existing site post and its bucket into COLD TAKE")
        open_btn.clicked.connect(self._open_published)
        ai_btn = QPushButton("✨ AI FILL")
        ai_btn.setToolTip("Uses this site's prompt to fill all supported metadata: "
                          "post fields from the lead photo and ALT for every photo.")
        ai_btn.clicked.connect(self._ai_fill)
        for b in (add_btn, open_btn, ai_btn):
            b.setStyleSheet("padding: 5px 7px; font-size: 11px;")
            brow.addWidget(b)
        brow.addStretch(1)
        self.photos_sec.add_layout(brow)

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
            hint("The ★ photo leads the post. This order is the order in the post."))
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
        photos_open = self._view_settings.value("take/photos_open", False, type=bool)
        self.photos_tray.set_open(photos_open)
        outer.addWidget(self.photos_tray)
        self._refresh_bucket()
        self._autosave = QTimer(self)
        self._autosave.setSingleShot(True)
        self._autosave.setInterval(900)
        self._autosave.timeout.connect(self._autosave_now)
        self._slapper_timer = QTimer(self)
        self._slapper_timer.setInterval(1000)
        self._slapper_timer.timeout.connect(self._poll_slapper_return)
        self.body.changed.connect(self._changed)
        self.body.changed.connect(self._update_page_chrome)
        self.body.canvas.cursorPositionChanged.connect(self._update_page_chrome)
        self.title_edit.textChanged.connect(self._changed)
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
        base = QFont("Georgia")
        base.setPointSizeF(12.5 * (pct / 100.0))
        self.body.canvas.setFont(base)
        self.body.canvas.document().setDefaultFont(base)
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
        from PySide6.QtGui import QColor, QPalette
        from . import canvas as canvas_mod
        if explicit_bg and QColor(explicit_bg.strip()).isValid():
            bg = QColor(explicit_bg.strip()).name()
            fg = QColor(explicit_fg.strip()).name() if explicit_fg and QColor(explicit_fg.strip()).isValid() else ""
            available = True
        else:
            bg, fg, available = self.resolve_site_colors(site_url)

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
        self.body.canvas.set_preview_width(inner_w)

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

    def _show_add_block_menu(self):
        from PySide6.QtWidgets import QMenu
        menu = QMenu(self)
        c = self.body.canvas
        for label, cb in (
            ("Paragraph", lambda: c.set_kind("para")),
            ("Heading", lambda: c.set_kind("h2")),
            ("Subhead", lambda: c.set_kind("h3")),
            ("Quote", lambda: c.set_kind("quote")),
            ("Pull quote", lambda: c.set_kind("pullquote")),
            ("Drop cap", c.toggle_dropcap),
            ("Bullet list", lambda: c.set_list(False)),
            ("Numbered list", lambda: c.set_list(True)),
            ("Divider", c.insert_hr),
            ("Space", c._edit_spacer),
            ("Mosaic", self._insert_mosaic),
            ("Photograph", self._add_and_insert_photo),
            ("Image from gallery", c._edit_image),
            ("2 columns", lambda: c.insert_columns(2)),
            ("3 columns", lambda: c.insert_columns(3)),
            ("Raw", lambda: c.set_kind("raw")),
        ):
            menu.addAction(label, cb)
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
            BlockAction("IMG", "Image", "PLACE", "Pick an image from the site's Media Library",
                        canvas._edit_image),
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
            BlockAction("IMG", "Image", "PLACE", "[img:ID|size|align]", bar._img),
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
        existing = re.search(
            r'\[mosaic=([0-9]+(?:\s*,\s*[0-9]+)*)\s+layout=([a-z-]+)\]',
            block_text, re.IGNORECASE)
        existing_span = None
        existing_order, existing_layout = [], None
        rotation_originals = {}
        if existing:
            existing_order = [int(value) - 1 for value in existing.group(1).split(',')]
            existing_order = [value for value in existing_order
                              if 0 <= value < len(self._bucket)]
            existing_layout = existing.group(2).lower()
            existing_span = (block.position() + existing.start(),
                             block.position() + existing.end())

        result = self._mosaic_dialog(existing_order, existing_layout)
        if not result:
            return
        chosen, layout_name = result
        marker = "[mosaic=" + ",".join(map(str, chosen)) + " layout=" + layout_name + "]"
        if existing_span:
            replace_cursor = self.body.editor.textCursor()
            replace_cursor.setPosition(existing_span[0])
            replace_cursor.setPosition(existing_span[1], QTextCursor.KeepAnchor)
            replace_cursor.insertText(marker)
            self.body.editor.setTextCursor(replace_cursor)
        else:
            self.body.editor.insertPlainText(marker)
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
        result = self._mosaic_dialog(existing_order, (layout or "").lower() or None)
        if not result:
            self.body.canvas._pending_obj_pos = None
            return
        chosen, layout_name = result
        stable = [self._bucket[i - 1].asset_uuid for i in chosen]
        if order:
            self.body.canvas.replace_mosaic(stable, layout_name)
        else:
            self.body.canvas.insert_mosaic(stable, layout_name)

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
        uuid_to_index = {str(image.asset_uuid): index
                         for index, image in enumerate(self._bucket)}
        used = set()

        def walk(node):
            """Mosaics can sit inside a columns block, so this recurses rather
            than reading only the top level."""
            if isinstance(node, dict):
                if node.get("type") == "mosaic":
                    order = node.get("order") or []
                    if not order:
                        # A BARE [mosaic] MEANS EVERY PHOTOGRAPH IN THE BUCKET
                        # (biggie.py:46). Treating that as "all used" would hand
                        # him an empty picker and no way forward, so it counts
                        # as claiming nothing and the pool stays whole.
                        return
                    for identity in order:
                        key = str(identity)
                        if key in uuid_to_index:
                            used.add(uuid_to_index[key])
                        elif key.isdigit() and 1 <= int(key) <= len(self._bucket):
                            used.add(int(key) - 1)
                for value in node.values():
                    walk(value)
            elif isinstance(node, (list, tuple)):
                for value in node:
                    walk(value)

        try:
            walk(self.body.authoring_blocks())
        except Exception:
            # A body that cannot be parsed must not block mosaic building. The
            # worst case of returning nothing is the old behaviour.
            return set()

        return used - set(keep or [])

    def _mosaic_dialog(self, existing_order, existing_layout):
        """The one mosaic builder (live preview, tick photos, drag order, layout,
        rotate). Returns (chosen 1-based bucket positions, layout) or None."""
        existing = bool(existing_order)
        rotation_originals = {}
        dialog = QDialog(self)
        dialog.setWindowTitle("Edit mosaic" if existing else "Build mosaic")
        dialog.resize(900, 760)
        layout = QVBoxLayout(dialog)
        layout.addWidget(QLabel(
            "Choose exactly which photos belong in this mosaic. Drag them—or use "
            "the controls—to set its order. The post's photo order is unchanged."))
        preview = MosaicPreview()
        layout.addWidget(preview)
        preview_hint = QLabel("LIVE PREVIEW — drag one image onto another to swap them")
        preview_hint.setAlignment(Qt.AlignCenter)
        layout.addWidget(preview_hint)
        photos = QListWidget()
        # Without this the thumbnails above are drawn at the ~16px default and
        # are no more use than the filename was.
        photos.setIconSize(QSize(96, 96))
        photos.setDragDropMode(QListWidget.InternalMove)
        # Checkboxes own inclusion. Row selection is only the current photo for
        # move/rotate controls, avoiding Windows Ctrl-click multi-select rules.
        photos.setSelectionMode(QAbstractItemView.SingleSelection)
        # SS-1049: photographs already used by OTHER mosaics in this post are
        # not offered again. The ones belonging to the mosaic being edited stay,
        # ticked, which is what existing_order is doing here.
        _used = self._mosaic_used_indices(existing_order)
        display_order = existing_order + [i for i in range(len(self._bucket))
                                          if i not in existing_order
                                          and i not in _used]
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
        for bucket_index in display_order:
            image = self._bucket[bucket_index]
            if bucket_index not in rotation_originals:
                rotation_originals[bucket_index] = (
                    image.local_path, image.original_path, image.width, image.height)
            shape, ratio = self._image_shape(image)
            name = image.filename or os.path.basename(image.local_path)
            item = QListWidgetItem(f"{name}    {shape} · {ratio:.2f}:1")
            # A thumbnail, because a filename is not how a photographer knows
            # which photograph is which. This list was name + shape + ratio and
            # nothing else, which is SS-1033: with 53 in the bucket the operator
            # could not tell them apart, and that is what stopped the post.
            # thumb_square is the pre-made small file every other list in this
            # app already uses for its icons -- see the bucket rail below, which
            # does exactly this. Falling back to the original only when there is
            # no thumbnail yet.
            icon = load_pixmap(image.thumb_square or image.local_path, 96)
            if icon:
                item.setIcon(QIcon(icon))
            else:
                # An explicit state rather than a silently blank row, so a
                # photograph that will not decode is visible as a problem.
                item.setToolTip("This photograph could not be previewed: "
                                + (image.local_path or name))
            item.setData(Qt.UserRole, bucket_index)
            item.setFlags(item.flags() | Qt.ItemIsUserCheckable | Qt.ItemIsDragEnabled)
            item.setCheckState(Qt.Checked if existing and bucket_index in existing_order
                               else Qt.Unchecked)
            photos.addItem(item)
        layout.addWidget(photos, 1)

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
        up = QPushButton("Move up")
        down = QPushButton("Move down")
        rotate_left = QPushButton("↶ Rotate left")
        rotate_right = QPushButton("↷ Rotate right")
        suggest = QPushButton("✨ Suggest arrangement")
        arrows.addWidget(up); arrows.addWidget(down)
        arrows.addWidget(rotate_left); arrows.addWidget(rotate_right)
        arrows.addStretch(1); arrows.addWidget(suggest)
        layout.addLayout(arrows)

        def checked_count():
            return sum(photos.item(i).checkState() == Qt.Checked
                       for i in range(photos.count()))

        def refresh_preview():
            tiles = []
            for i in range(photos.count()):
                item = photos.item(i)
                if item.checkState() == Qt.Checked:
                    bucket_index = int(item.data(Qt.UserRole))
                    tiles.append((i, self._bucket[bucket_index].local_path))
            preview.set_mosaic(tiles, preset.currentData())

        def refresh_layouts():
            count = checked_count()
            previous = preset.currentData()
            preset.blockSignals(True)
            preset.clear()
            for label, value in self._mosaic_layouts(count):
                preset.addItem(label, value)
            old = preset.findData(previous)
            preset.setCurrentIndex(old if old >= 0 else 0)
            preset.blockSignals(False)
            # SS-1049 acceptance (c), the coordinator's suggestion: say how many
            # of the bucket are still unused, so he can see he has not missed
            # any. Only shown once something IS used -- on a first mosaic
            # "53 of 53 unused" is noise.
            if _used:
                selected_count.setText(
                    f"{count} of {photos.count()} included "
                    f"— {len(self._bucket) - len(_used)} of "
                    f"{len(self._bucket)} unused in this post")
            else:
                selected_count.setText(f"{count} of {photos.count()} included")
            refresh_preview()

        def set_all(state):
            for i in range(photos.count()):
                photos.item(i).setCheckState(state)

        def swap_rows(first, second):
            if first == second or first < 0 or second < 0:
                return
            low, high = sorted((first, second))
            high_item = photos.takeItem(high)
            low_item = photos.takeItem(low)
            photos.insertItem(low, high_item)
            photos.insertItem(high, low_item)
            refresh_preview()

        def rotate_current(degrees):
            row = photos.currentRow()
            if row < 0:
                QMessageBox.information(dialog, "Choose a photo",
                                        "Click one photo row before rotating it.")
                return
            item = photos.item(row)
            bucket_index = int(item.data(Qt.UserRole))
            image = self._bucket[bucket_index]
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
                shape, ratio = self._image_shape(image)
                name = image.filename or os.path.basename(image.local_path)
                item.setText(f"{name}    {shape} · {ratio:.2f}:1")
                refresh_preview()
            except Exception as exc:
                QMessageBox.warning(dialog, "Could not rotate photo", str(exc))

        def suggest_arrangement():
            included = [photos.item(i) for i in range(photos.count())
                        if photos.item(i).checkState() == Qt.Checked]
            if not included:
                return
            # Local, deterministic assist: similar shapes stay together and a
            # three-photo hero is chosen from the strongest outlier.
            included.sort(key=lambda item: self._image_shape(
                self._bucket[int(item.data(Qt.UserRole))])[1])
            excluded = [photos.item(i) for i in range(photos.count())
                        if photos.item(i).checkState() != Qt.Checked]
            while photos.count():
                photos.takeItem(0)
            for item in included + excluded:
                photos.addItem(item)
            if len(included) == 3:
                ratios = [self._image_shape(self._bucket[int(i.data(Qt.UserRole))])[1]
                          for i in included]
                preset.setCurrentIndex(preset.findData(
                    "one-top" if sum(r > 1.08 for r in ratios) >= 2 else "one-left"))
            elif len(included) >= 4:
                preset.setCurrentIndex(preset.findData("asymmetric"))

        select_all.clicked.connect(lambda: set_all(Qt.Checked))
        clear_all.clicked.connect(lambda: set_all(Qt.Unchecked))
        rotate_left.clicked.connect(lambda: rotate_current(90))
        rotate_right.clicked.connect(lambda: rotate_current(-90))
        suggest.clicked.connect(suggest_arrangement)
        photos.itemChanged.connect(lambda _item: refresh_layouts())
        photos.model().rowsMoved.connect(lambda *_args: refresh_preview())
        preset.currentIndexChanged.connect(lambda _index: refresh_preview())
        preview.swapRequested.connect(swap_rows)
        refresh_layouts()
        if existing_layout:
            found = preset.findData(existing_layout)
            if found >= 0:
                preset.setCurrentIndex(found)

        def move(delta):
            row = photos.currentRow()
            target = row + delta
            if row >= 0 and 0 <= target < photos.count():
                item = photos.takeItem(row)
                photos.insertItem(target, item)
                photos.setCurrentRow(target)
                refresh_preview()

        up.clicked.connect(lambda: move(-1))
        down.clicked.connect(lambda: move(1))
        buttons = QDialogButtonBox(QDialogButtonBox.Cancel | QDialogButtonBox.Ok)
        buttons.accepted.connect(dialog.accept)
        buttons.rejected.connect(dialog.reject)
        layout.addWidget(buttons)
        if dialog.exec() != QDialog.Accepted:
            for bucket_index, state in rotation_originals.items():
                image = self._bucket[bucket_index]
                image.local_path, image.original_path, image.width, image.height = state
            return None

        chosen = [int(photos.item(i).data(Qt.UserRole)) + 1
                  for i in range(photos.count())
                  if photos.item(i).checkState() == Qt.Checked]
        if not chosen:
            for bucket_index, state in rotation_originals.items():
                image = self._bucket[bucket_index]
                image.local_path, image.original_path, image.width, image.height = state
            QMessageBox.warning(self, "Empty mosaic", "Choose at least one photo.")
            return None
        # A rotate changed a working copy: the canvas must repaint from the new file.
        self.body.set_bucket([im.local_path for im in self._bucket])
        return chosen, str(preset.currentData())

    def _add_photos(self):
        from .pickers import pick_images
        paths = pick_images(self, (self.app_config() or {}).get("url", ""))
        self._add_photo_paths(paths, insert=False)

    def _open_published(self):
        poster, _url = self._poster_and_url()
        if poster is None:
            return
        try:
            posts = poster.list_posts()
        except Exception as exc:  # noqa: BLE001
            QMessageBox.critical(self, "Could not load posts", str(exc))
            return
        if not posts:
            QMessageBox.information(self, "No posts yet", "This site has no long-form posts to edit.")
            return

        dialog = QDialog(self)
        dialog.setWindowTitle("Open a published post")
        dialog.resize(720, 520)
        layout = QVBoxLayout(dialog)
        layout.addWidget(hint("Choose a post. COLD SNAP will load its saved bucket and keep its original date."))
        choices = QListWidget()
        for post in posts:
            label = f"{str(post.get('created_at') or '')[:10]}   {post.get('title') or 'Untitled'}   [{post.get('status') or 'draft'}]"
            item = QListWidgetItem(label)
            item.setData(Qt.UserRole, int(post.get("id") or 0))
            choices.addItem(item)
        if choices.count():
            choices.setCurrentRow(0)
        layout.addWidget(choices, 1)
        buttons = QDialogButtonBox(QDialogButtonBox.Cancel | QDialogButtonBox.Open)
        buttons.accepted.connect(dialog.accept)
        buttons.rejected.connect(dialog.reject)
        choices.itemDoubleClicked.connect(lambda _item: dialog.accept())
        layout.addWidget(buttons)
        if dialog.exec() != QDialog.Accepted or not choices.currentItem():
            return
        post_id = int(choices.currentItem().data(Qt.UserRole) or 0)
        try:
            post = poster.get_post(post_id)
            draft = self._draft_from_remote(post)
        except Exception as exc:  # noqa: BLE001
            QMessageBox.critical(self, "Could not open post", str(exc))
            return
        session = self.rail.ensure_session()
        session.add_draft(draft)
        self._edit(draft)
        self.save_state.setText("Published post loaded — changes will update this post")

    def _draft_from_remote(self, post):
        draft = O.Draft(draft_id=O._new_id(), kind=O.KIND_SMACKTALK,
                        mode=self.SUITE_MODE)
        draft.remote_post_id = int(post.get("id") or 0)
        draft.title = str(post.get("title") or "")
        draft.caption = str(post.get("content") or "")
        draft.img_status = str(post.get("status") or "draft")
        draft.post_date = str(post.get("created_at") or "")
        draft.tags = " ".join("#" + str(tag).lstrip("#") for tag in (post.get("tags") or []))
        draft.category_ids = [int(value) for value in (post.get("cat_ids") or [])]
        draft.album_ids = [int(value) for value in (post.get("album_ids") or [])]
        cover_id = int(post.get("featured_image_id") or 0)
        cache = os.path.join(tempfile.gettempdir(), "coldsnap-published",
                             str(draft.remote_post_id))
        os.makedirs(cache, exist_ok=True)
        for pos, row in enumerate(post.get("bucket") or []):
            image_id = int(row.get("id") or 0)
            source_url = str(row.get("url") or row.get("thumb_url") or "")
            filename = os.path.basename(source_url.split("?", 1)[0]) or f"image-{image_id}.jpg"
            target = os.path.join(cache, f"{image_id}-{filename}")
            if not os.path.isfile(target):
                response = requests.get(source_url, timeout=90)
                response.raise_for_status()
                with open(target, "wb") as handle:
                    handle.write(response.content)
            draft.images.append(O.DraftImage(
                local_path=target, original_path=target, filename=filename,
                width=int(row.get("width") or 0), height=int(row.get("height") or 0),
                alt=str(row.get("img_alt") or ""), title=str(row.get("img_title") or ""),
                remote_path=str(row.get("img_file") or ""), remote_image_id=image_id,
                sort_position=pos, is_cover=(image_id == cover_id)))
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
        del self._bucket[idx]
        if self._selected_photo_idx == idx:
            self._selected_photo_idx = min(idx, len(self._bucket) - 1) if self._bucket else None
        elif self._selected_photo_idx is not None and self._selected_photo_idx > idx:
            self._selected_photo_idx -= 1
        if self._cover_idx >= len(self._bucket):
            self._cover_idx = max(0, len(self._bucket) - 1)
        self._refresh_bucket()
        self._changed()

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
        lead = QPushButton("★ Lead")
        remove = QPushButton("×")
        remove.setObjectName("Danger")
        up.clicked.connect(lambda: self._manage_selected("up"))
        down.clicked.connect(lambda: self._manage_selected("down"))
        lead.clicked.connect(lambda: self._manage_selected("lead"))
        remove.clicked.connect(lambda: self._manage_selected("remove"))
        for button in (up, down, lead, remove):
            manage.addWidget(button)
        manage.addStretch(1)
        col.addLayout(manage)

        tools = QHBoxLayout()
        tools.setSpacing(4)
        self.flip_h_btn = QPushButton("⇆ Flip H")
        self.flip_v_btn = QPushButton("⇅ Flip V")
        self.crop_btn = QPushButton("Crop")
        self.caption_btn = QPushButton("Caption")
        self.metadata_btn = QPushButton("Metadata")
        for button in (self.flip_h_btn, self.flip_v_btn, self.crop_btn,
                       self.caption_btn, self.metadata_btn):
            button.setObjectName("PhotoTool")
            button.setSizePolicy(QSizePolicy.Ignored, QSizePolicy.Preferred)
            tools.addWidget(button)
        self.flip_h_btn.setCheckable(True)
        self.flip_v_btn.setCheckable(True)
        self.caption_btn.setCheckable(True)
        self.metadata_btn.setCheckable(True)
        self.flip_h_btn.clicked.connect(
            lambda: self._flip_selected("edit_flip_h"))
        self.flip_v_btn.clicked.connect(
            lambda: self._flip_selected("edit_flip_v"))
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

    def _select_photo(self, idx: int):
        if 0 <= idx < len(self._bucket):
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
            self._remove(index)

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

    def _flip_selected(self, attr: str):
        image = self._selected_image()
        if image is None:
            return
        setattr(image, attr, not bool(getattr(image, attr, False)))
        self._render_selected_edit()

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
            self.flip_h_btn.setChecked(bool(getattr(image, "edit_flip_h", False)))
            self.flip_v_btn.setChecked(bool(getattr(image, "edit_flip_v", False)))
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

        left_count = max(0, n - len(mosaic_paths))
        self.photos_sec.header.setText(
            f"THE PHOTOS — {left_count} left" if n else "THE PHOTOS — none yet")
        if not self._bucket:
            self._selected_photo_idx = None
            self._populate_photo_tools()
            self.bucket_col.addWidget(hint("No photos yet — click “Add photos…”."), 0, 0)
            return
        if self._selected_photo_idx is None or self._selected_photo_idx >= len(self._bucket):
            self._selected_photo_idx = 0

        zoom = getattr(self, "_thumb_zoom", 100)
        if zoom >= 135:
            cols, tile_px = 2, 146
        elif zoom >= 110:
            cols, tile_px = 3, 96
        elif zoom >= 85:
            cols, tile_px = 3, 88
        else:
            cols, tile_px = 4, 66

        for i, im in enumerate(self._bucket):
            is_cover = (i == self._cover_idx)
            is_selected = (i == self._selected_photo_idx)
            edited = bool(getattr(im, "slapper_edited_path", ""))
            in_slapper = (
                self._slapper_state == "waiting"
                and str(getattr(im, "asset_uuid", "")) == self._slapper_asset_uuid
            )
            pix = load_pixmap(im.thumb_square or im.local_path, tile_px)
            tile = PhotoThumbTile(
                i, pix, selected=is_selected, is_cover=is_cover,
                edited=edited, in_slapper=in_slapper, tile_size=tile_px)
            tile.setToolTip(im.filename or os.path.basename(im.local_path))
            tile.setAccessibleName(
                "Selected photograph" if is_selected else "Select photograph")
            tile.clicked.connect(lambda _=False, i=i: self._select_photo(i))
            self.bucket_col.addWidget(tile, i // cols, i % cols)
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

    def _edit(self, draft: O.Draft):
        self._loading = True
        self._editing_id = draft.draft_id
        self.title_edit.setText(draft.title)
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
        self._loading = False
        self.save_state.setText("Safe on this computer")

    def _clear(self):
        self._loading = True
        self._editing_id = None
        self.title_edit.clear()
        self.tags_edit.clear()
        self.status_combo.setCurrentText("published")
        self.body.clear()
        self._bucket = []
        self._cover_idx = 0
        self._selected_photo_idx = None
        self._ai_post_meta = {}
        self._refresh_bucket()
        self._loading = False
        self.save_state.setText("Begin the story… changes will be safe on this computer.")

    def _changed(self):
        if self._loading:
            return
        self.save_state.setText("Saving…")
        self._autosave.start()

    def _wire_blocks(self, blocks):
        """Translate stable local UUIDs to today's server bucket positions."""
        positions = {str(im.asset_uuid): i + 1 for i, im in enumerate(self._bucket)}
        def convert(block):
            out = dict(block)
            if out.get("type") == "image" and out.get("asset_uuid"):
                pos = positions.get(str(out["asset_uuid"]))
                if pos:
                    out["img_id"] = f"bucket:{pos}"
                for key in ("asset_uuid", "placement", "width_ratio", "crop", "alt", "caption", "link"):
                    out.pop(key, None)
            if out.get("type") == "mosaic" and out.get("order"):
                out["order"] = [positions.get(str(value), value) for value in out["order"]]
            if out.get("type") == "columns":
                out["cols"] = [[convert(child) for child in col] for col in out.get("cols", [])]
            return out
        return [convert(block) for block in blocks]

    def _build_draft(self, session):
        draft = (session.load_draft(self._editing_id) if self._editing_id else None) \
            or O.Draft(draft_id=O._new_id(), kind=O.KIND_SMACKTALK, mode=self.SUITE_MODE)
        self._editing_id = draft.draft_id
        draft.title = self.title_edit.text().strip()
        draft.tags = self.tags_edit.text().strip()
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
        if not (self.title_edit.text().strip() or self.body.toPlainText().strip() or self._bucket):
            self.save_state.setText("Begin the story… changes will be safe on this computer.")
            return
        try:
            session = self.rail.ensure_session()
            draft = self._build_draft(session)
            O.generate_draft_thumbs(draft)
            session.add_draft(draft)
            self._mirror_sqlite(draft)
            self.save_state.setText("Safe on this computer")
        except Exception as exc:  # noqa: BLE001
            self.save_state.setText(f"Needs attention — could not save: {exc}")

    def _mirror_sqlite(self, draft):
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
                                status=draft.status, blocks=self.body.authoring_blocks())

    def _save(self, ready: bool):
        session = self.rail.ensure_session()
        if not self._bucket:
            QMessageBox.warning(self, "No photos", "Add at least one photo first.")
            return
        draft = self._build_draft(session)
        O.generate_draft_thumbs(draft)
        problems = draft.validate()
        if ready and problems:
            QMessageBox.warning(self, "Not ready", "\n".join(problems))
        draft.status = O.ST_READY if (ready and not problems) else O.ST_DRAFT
        session.add_draft(draft)
        self._mirror_sqlite(draft)
        self._clear()
        self.rail.refresh_batches()

    # -- network -------------------------------------------------------------
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
        return SmacktalkPoster(url, key, site_data=None), url

# ===== SNAPSMACK EOF =====
