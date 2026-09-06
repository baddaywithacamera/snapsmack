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

from PySide6.QtCore import Qt
from PySide6.QtGui import QTextCursor
from PySide6.QtWidgets import (
    QWidget, QHBoxLayout, QVBoxLayout, QLabel, QLineEdit, QPlainTextEdit,
    QComboBox, QPushButton, QFileDialog, QMessageBox, QScrollArea, QFrame,
)

import sumna_offline as O
from sumna_post import SmacktalkPoster

from . import theme
from .widgets import Card, hint, field_label, big_button, thumb_label
from .drafts_panel import BatchRail, default_draft_row


class TakeMode(QWidget):
    SUITE_MODE = O.MODE_SMACKTALK

    def __init__(self, app_config_provider, parent=None):
        super().__init__(parent)
        self.app_config = app_config_provider
        self._editing_id = None
        self._bucket = []          # list[O.DraftImage]
        self._cover_idx = 0

        outer = QHBoxLayout(self)
        outer.setContentsMargins(10, 10, 10, 10)
        outer.setSpacing(10)

        self.rail = BatchRail(self.SUITE_MODE, "post", self._poster_and_url,
                              row_builder=self._rows)
        outer.addWidget(self.rail)

        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        host = QWidget()
        right = QVBoxLayout(host)
        right.setContentsMargins(0, 0, 0, 0)

        card = Card("COMPOSE — an essay with photos")
        right.addWidget(card)

        card.body.addWidget(field_label("Title"))
        self.title_edit = QLineEdit()
        card.body.addWidget(self.title_edit)

        card.body.addWidget(field_label("The write-up"))
        self.body_edit = QPlainTextEdit()
        self.body_edit.setMinimumHeight(160)
        card.body.addWidget(self.body_edit)

        mrow = QHBoxLayout()
        mosaic_btn = QPushButton("Insert photo grid here")
        mosaic_btn.clicked.connect(self._insert_mosaic)
        mrow.addWidget(mosaic_btn)
        mrow.addWidget(hint("puts a [mosaic] marker at the cursor — on send, the photos "
                            "below become a tiled grid right at that spot"), 1)
        card.body.addLayout(mrow)

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

        # -- the bucket ---------------------------------------------------------
        brow = QHBoxLayout()
        bl = QLabel("THE PHOTOS")
        bl.setObjectName("CardTitle")
        brow.addWidget(bl)
        self.bucket_count = hint("none yet")
        brow.addWidget(self.bucket_count, 1)
        add_btn = QPushButton("Add photos…")
        add_btn.clicked.connect(self._add_photos)
        brow.addWidget(add_btn)
        card.body.addLayout(brow)
        card.body.addWidget(hint("The ★ photo leads the post. This order is the order in the post."))

        self.bucket_col = QVBoxLayout()
        self.bucket_col.setSpacing(4)
        card.body.addLayout(self.bucket_col)

        act = QHBoxLayout()
        self.queue_btn = big_button("QUEUE POST  →  goes in the batch, sends on SEND")
        self.queue_btn.clicked.connect(lambda: self._save(ready=True))
        act.addWidget(self.queue_btn, 1)
        save_btn = QPushButton("Save as draft")
        save_btn.clicked.connect(lambda: self._save(ready=False))
        act.addWidget(save_btn)
        clear_btn = QPushButton("Clear")
        clear_btn.setObjectName("Quiet")
        clear_btn.clicked.connect(self._clear)
        act.addWidget(clear_btn)
        card.body.addLayout(act)

        right.addStretch(1)
        scroll.setWidget(host)
        outer.addWidget(scroll, 1)
        self._refresh_bucket()

    # -- rail rows -----------------------------------------------------------
    def _rows(self, session, refresh_cb):
        return [default_draft_row(session, d, refresh_cb, edit_cb=self._edit,
                                  subtitle=f"{len(d.images)} photo(s)")
                for d in session.list_drafts()]

    # -- compose -----------------------------------------------------------------
    def _insert_mosaic(self):
        self.body_edit.insertPlainText("[mosaic]")
        self.body_edit.setFocus()

    def _add_photos(self):
        from .pickers import pick_images
        paths = pick_images(self, (self.app_config() or {}).get("url", ""))
        for p in paths:
            if len(self._bucket) >= O.SMACKTALK_BUCKET_MAX:
                QMessageBox.information(
                    self, "That's the lot",
                    f"An essay holds up to {O.SMACKTALK_BUCKET_MAX} photos.")
                break
            self._bucket.append(O.DraftImage(local_path=p, filename=os.path.basename(p)))
        if paths and not self.title_edit.text().strip():
            self.title_edit.setText(os.path.splitext(os.path.basename(paths[0]))[0])
        self._refresh_bucket()

    def _move(self, idx: int, delta: int):
        j = idx + delta
        if 0 <= j < len(self._bucket):
            self._bucket[idx], self._bucket[j] = self._bucket[j], self._bucket[idx]
            if self._cover_idx == idx:
                self._cover_idx = j
            elif self._cover_idx == j:
                self._cover_idx = idx
            self._refresh_bucket()

    def _remove(self, idx: int):
        del self._bucket[idx]
        if self._cover_idx >= len(self._bucket):
            self._cover_idx = max(0, len(self._bucket) - 1)
        self._refresh_bucket()

    def _set_cover(self, idx: int):
        self._cover_idx = idx
        self._refresh_bucket()

    def _refresh_bucket(self):
        while self.bucket_col.count():
            item = self.bucket_col.takeAt(0)
            w = item.widget()
            if w:
                w.deleteLater()
        n = len(self._bucket)
        self.bucket_count.setText("none yet" if n == 0 else f"{n} photo(s)")
        if not self._bucket:
            self.bucket_col.addWidget(hint("No photos yet — click “Add photos…”."))
            return
        for i, im in enumerate(self._bucket):
            is_cover = (i == self._cover_idx)
            row = QFrame()
            row.setObjectName("Card")
            lay = QHBoxLayout(row)
            lay.setContentsMargins(6, 6, 6, 6)
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
            self.bucket_col.addWidget(row)

    def _edit(self, draft: O.Draft):
        self._editing_id = draft.draft_id
        self.title_edit.setText(draft.title)
        self.tags_edit.setText(draft.tags)
        self.status_combo.setCurrentText(draft.img_status)
        self.body_edit.setPlainText(draft.caption)
        self.body_edit.moveCursor(QTextCursor.End)   # so an insert lands where writing resumes
        self._bucket = [O.DraftImage(local_path=im.local_path, filename=im.filename,
                                     thumb_square=im.thumb_square, is_cover=im.is_cover)
                        for im in draft.images]
        self._cover_idx = next((i for i, im in enumerate(self._bucket) if im.is_cover), 0)
        self._refresh_bucket()

    def _clear(self):
        self._editing_id = None
        self.title_edit.clear()
        self.tags_edit.clear()
        self.status_combo.setCurrentText("published")
        self.body_edit.clear()
        self._bucket = []
        self._cover_idx = 0
        self._refresh_bucket()

    def _save(self, ready: bool):
        session = self.rail.ensure_session()
        if not self._bucket:
            QMessageBox.warning(self, "No photos", "Add at least one photo first.")
            return
        draft = (session.load_draft(self._editing_id) if self._editing_id else None) \
            or O.Draft(draft_id=O._new_id(), kind=O.KIND_SMACKTALK, mode=self.SUITE_MODE)
        draft.title = self.title_edit.text().strip()
        draft.tags = self.tags_edit.text().strip()
        draft.caption = self.body_edit.toPlainText().strip()
        draft.img_status = self.status_combo.currentText()
        draft.images = [
            O.DraftImage(local_path=im.local_path,
                         filename=im.filename or os.path.basename(im.local_path),
                         sort_position=i, is_cover=(i == self._cover_idx))
            for i, im in enumerate(self._bucket)]
        O.generate_draft_thumbs(draft)
        problems = draft.validate()
        if ready and problems:
            QMessageBox.warning(self, "Not ready", "\n".join(problems))
        draft.status = O.ST_READY if (ready and not problems) else O.ST_DRAFT
        session.add_draft(draft)
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
