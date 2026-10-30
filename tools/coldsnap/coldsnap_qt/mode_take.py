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

from PySide6.QtCore import Qt, QRect, QSize, Signal, QTimer
from PySide6.QtGui import QTextCursor, QPainter, QPixmap, QColor, QPen, QIcon
from PySide6.QtWidgets import (
    QWidget, QHBoxLayout, QVBoxLayout, QLabel, QLineEdit, QPlainTextEdit,
    QComboBox, QPushButton, QFileDialog, QMessageBox, QScrollArea, QFrame,
    QDialog, QDialogButtonBox, QListWidget, QListWidgetItem, QAbstractItemView,
)

import sumna_offline as O
import snap_library
from sumna_post import SmacktalkPoster

from . import theme, biggie
from .widgets import (Accordion, Card, build_rail, hint, field_label,
                      big_button, thumb_label, load_pixmap)
from .photo_loader import PhotoLoader
from .body_editor import BodyEditor
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


class TakeMode(QWidget):
    SUITE_MODE = O.MODE_SMACKTALK

    def __init__(self, app_config_provider, parent=None):
        super().__init__(parent)
        self.app_config = app_config_provider
        self._editing_id = None
        self._bucket = []          # list[O.DraftImage]
        self._cover_idx = 0
        self._loading = False

        outer = QHBoxLayout(self)
        outer.setContentsMargins(10, 10, 0, 0)
        outer.setSpacing(10)

        self.rail = BatchRail(self.SUITE_MODE, "post", self._poster_and_url,
                              row_builder=self._rows)

        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        host = QWidget()
        right = QVBoxLayout(host)
        right.setContentsMargins(0, 0, 0, 0)

        card = Card("COMPOSE — an essay with photos")
        # Let the editor use the centre pane instead of shrinking to sizeHint.
        right.addWidget(card)

        card.body.addWidget(field_label("Title"))
        self.title_edit = QLineEdit()
        card.body.addWidget(self.title_edit)

        card.body.addWidget(field_label("The write-up"))
        # SIMPLE (text + the CMS shortcode bar) / BIGGIE (block stack) — one field.
        self.body = BodyEditor(allow_mosaic=True, simple_height=160)
        self.body.bar.add_button(
            "MOSAIC",
            "Puts a [mosaic] marker at the cursor — on send, the photos below "
            "become a tiled grid right at that spot",
            self._insert_mosaic)
        # BIGGIE: the same button on the canvas bar; the canvas asks for the
        # dialog itself on double-click / right-click of a drawn mosaic.
        self.body.canvas_bar.add_button(
            "MOSAIC",
            "Build a tiled grid of this post's photos right here — you see it "
            "in the page as you write",
            self._insert_mosaic)
        self.body.canvas.mosaicEditRequested.connect(self._canvas_mosaic)
        self.body.canvas.photographsDropped.connect(
            lambda paths: self._add_photo_paths(paths, insert=True))
        self.body.canvas.photographPasted.connect(self._paste_photo)
        self.body.canvas_bar.add_button(
            "ADD PHOTOGRAPH", "Choose a local photograph and place it at the caret",
            self._add_and_insert_photo)
        # IMG picks from THIS site's cached Media Gallery (COLD STORAGE), by picture.
        self.body.canvas.set_site_provider(
            lambda: ((self.app_config() or {}).get("url") or "").strip())
        card.body.addWidget(self.body, 1)   # the write-up is the main event — it grows
        self.save_state = hint("Begin the story… changes will be safe on this computer.")
        card.body.addWidget(self.save_state)
        card.body.addWidget(hint(
            "MOSAIC = a tiled grid of this post's photos at the marker. For a "
            "text grid with no photos, use COL 2 / COL 3. For one inline image "
            "from the site's Media Library, use IMG."))

        card.body.addWidget(field_label("Tags (space-separated #hashtags)"))
        self.tags_edit = QLineEdit()
        card.body.addWidget(self.tags_edit)

        srow = QHBoxLayout()
        srow.addWidget(field_label("Status"))
        self.status_combo = QComboBox()
        self.status_combo.addItems(["published", "draft"])
        srow.addWidget(self.status_combo)
        srow.addStretch(1)
        card.body.addLayout(srow)

        # -- THE PHOTOS — a rail section, opened when wanted. The count lives in
        #    the accordion header (no redundant, cramped label fighting the
        #    buttons for the 340px rail width). --------------------------------
        self.photos_sec = Accordion("THE PHOTOS — none yet")
        brow = QHBoxLayout()
        add_btn = QPushButton("Add photos…")
        add_btn.clicked.connect(self._add_photos)
        open_btn = QPushButton("Open published…")
        open_btn.setToolTip("Bring an existing site post and its bucket into COLD TAKE")
        open_btn.clicked.connect(self._open_published)
        ai_btn = QPushButton("✨ AI FILL")
        ai_btn.setToolTip("Uses this site's prompt to fill all supported metadata: "
                          "post fields from the lead photo and ALT for every photo.")
        ai_btn.clicked.connect(self._ai_fill)
        brow.addWidget(add_btn)
        brow.addWidget(open_btn)
        brow.addWidget(ai_btn)
        brow.addStretch(1)
        self.photos_sec.add_layout(brow)
        self.photos_sec.add(
            hint("The ★ photo leads the post. This order is the order in the post."))
        self.bucket_count = hint("")   # its own line — AI-ALT progress / status only
        self.photos_sec.add(self.bucket_count)

        self.bucket_col = QVBoxLayout()
        self.bucket_col.setSpacing(4)
        self.photos_sec.add_layout(self.bucket_col)

        # No trailing stretch — the compose card (stretch 1 above) fills the
        # height itself, so there is no dead void beneath it.
        scroll.setWidget(host)

        # Primary action pinned under the scroll — never below the fold.
        act = QHBoxLayout()
        act.addStretch(1)
        self.queue_btn = big_button("QUEUE POST")
        self.queue_btn.setMaximumWidth(260)
        self.queue_btn.setToolTip("Add this post to the batch. Nothing publishes until SEND.")
        self.queue_btn.clicked.connect(lambda: self._save(ready=True))
        save_btn = QPushButton("Save as draft")
        save_btn.clicked.connect(lambda: self._save(ready=False))
        act.addWidget(save_btn)
        clear_btn = QPushButton("Clear")
        clear_btn.setObjectName("Quiet")
        clear_btn.clicked.connect(self._clear)
        act.addWidget(clear_btn)
        act.addWidget(self.queue_btn)

        centre = QVBoxLayout()
        centre.setSpacing(8)
        centre.addWidget(scroll, 1)
        centre.addLayout(act)
        outer.addLayout(centre, 1)

        # The rail: photos + batch accordion open when wanted; SEND pinned.
        outer.addWidget(build_rail(
            [self.photos_sec, self.rail.section],
            [self.rail.send_box]))
        self._refresh_bucket()
        self._autosave = QTimer(self)
        self._autosave.setSingleShot(True)
        self._autosave.setInterval(900)
        self._autosave.timeout.connect(self._autosave_now)
        self.body.changed.connect(self._changed)
        self.title_edit.textChanged.connect(self._changed)
        self.tags_edit.textChanged.connect(self._changed)
        QTimer.singleShot(0, self._recover_last_draft)

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
            self._bucket.append(image)
            added.append(image)
        if paths and not self.title_edit.text().strip():
            self.title_edit.setText(os.path.splitext(os.path.basename(paths[0]))[0])
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
            if self._cover_idx == idx:
                self._cover_idx = j
            elif self._cover_idx == j:
                self._cover_idx = idx
            self._refresh_bucket()
            self._changed()

    def _remove(self, idx: int):
        del self._bucket[idx]
        if self._cover_idx >= len(self._bucket):
            self._cover_idx = max(0, len(self._bucket) - 1)
        self._refresh_bucket()
        self._changed()

    def _set_cover(self, idx: int):
        self._cover_idx = idx
        self._refresh_bucket()
        self._changed()

    def _refresh_bucket(self):
        while self.bucket_col.count():
            item = self.bucket_col.takeAt(0)
            w = item.widget()
            if w:
                w.deleteLater()
        n = len(self._bucket)
        self.body.set_bucket([im.local_path for im in self._bucket])   # mosaics repaint
        self.body.canvas.set_assets({im.asset_uuid: im.local_path for im in self._bucket})
        self.bucket_count.setText("")   # count lives in the header; this line = AI progress only
        self.photos_sec.header.setText(
            f"THE PHOTOS — {n} in the bucket" if n else "THE PHOTOS — none yet")
        if not self._bucket:
            self.bucket_col.addWidget(hint("No photos yet — click “Add photos…”."))
            return
        for i, im in enumerate(self._bucket):
            is_cover = (i == self._cover_idx)
            row = QFrame()
            row.setObjectName("Card")
            col = QVBoxLayout(row)
            col.setContentsMargins(6, 6, 6, 6)
            col.setSpacing(4)
            lay = QHBoxLayout()
            lay.addWidget(thumb_label(im.thumb_square or im.local_path, 40))
            name = QLabel(("★ " if is_cover else "") +
                          (im.filename or os.path.basename(im.local_path)))
            name.setStyleSheet(
                f"color: {theme.ACCENT if is_cover else theme.BODY}; background: transparent;")
            lay.addWidget(name, 1)
            up = QPushButton("▲"); up.setFixedWidth(34)
            up.clicked.connect(lambda _=False, i=i: self._move(i, -1))
            dn = QPushButton("▼"); dn.setFixedWidth(34)
            dn.clicked.connect(lambda _=False, i=i: self._move(i, 1))
            lay.addWidget(up); lay.addWidget(dn)
            if not is_cover:
                cov = QPushButton("★ Lead")
                cov.clicked.connect(lambda _=False, i=i: self._set_cover(i))
                lay.addWidget(cov)
            rm = QPushButton("✕"); rm.setObjectName("Danger"); rm.setFixedWidth(34)
            rm.clicked.connect(lambda _=False, i=i: self._remove(i))
            lay.addWidget(rm)
            col.addLayout(lay)
            # Per-photo ALT — saved with the image on the site (img_alt).
            alt = QLineEdit(getattr(im, "alt", "") or "")
            alt.setPlaceholderText("ALT — one plain sentence describing this photo")
            alt.textChanged.connect(lambda text, im=im: (setattr(im, "alt", text.strip()),
                                                         self._changed()))
            col.addWidget(alt)
            self.bucket_col.addWidget(row)

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
        self.body.set_state(draft.caption, getattr(draft, "body_blocks", ""))
        self.body.editor.moveCursor(QTextCursor.End)   # so an insert lands where writing resumes
        self._bucket = [O.DraftImage(local_path=im.local_path, filename=im.filename,
                                     thumb_square=im.thumb_square, is_cover=im.is_cover,
                                     **{name: getattr(im, name)
                                        for name in O.DraftImage.__dataclass_fields__
                                        if name not in {"local_path", "filename", "thumb_square",
                                                        "is_cover"}})
                        for im in draft.images]
        self._cover_idx = next((i for i, im in enumerate(self._bucket) if im.is_cover), 0)
        self._ai_post_meta = {
            "category": getattr(draft, "category", ""),
            "album": getattr(draft, "album", ""),
            "orientation": getattr(draft, "orientation", "auto"),
            "color_mode": getattr(draft, "color_mode", ""),
            "colors": getattr(draft, "ai_colors", ""),
        }
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
        return SmacktalkPoster(url, key, site_data=None), url

# ===== SNAPSMACK EOF =====
