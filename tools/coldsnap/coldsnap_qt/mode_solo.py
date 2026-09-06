"""COLD SNAP Qt — COLD ONE: one photo, one post (solo photoblog sites).

Feature parity with the Tk sumna_solo panel; engines untouched.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import threading

from PySide6.QtCore import QObject, Signal, Qt
from PySide6.QtWidgets import (
    QWidget, QHBoxLayout, QVBoxLayout, QGridLayout, QLabel, QLineEdit,
    QPlainTextEdit, QComboBox, QCheckBox, QPushButton, QFileDialog,
    QMessageBox, QScrollArea,
)

import sumna_offline as O
from sumna_post import SumnaConnection, SoloPoster, InsecureTransportError

from . import theme
from .widgets import (Accordion, Card, build_rail, hint, field_label,
                      big_button, thumb_label, load_pixmap)
from .body_editor import BodyEditor
from .drafts_panel import BatchRail, default_draft_row

_COLOUR_LABELS = ["—", "Colour", "B&W"]
_COLOUR_TO_VAL = {"Colour": "color", "B&W": "bw"}
_VAL_TO_COLOUR = {"color": "Colour", "bw": "B&W"}


class _AiBridge(QObject):
    done = Signal(dict)
    failed = Signal(str)


class SoloMode(QWidget):
    SUITE_MODE = O.MODE_SOLO

    def __init__(self, app_config_provider, parent=None):
        super().__init__(parent)
        self.app_config = app_config_provider     # () -> dict with url/api_key
        self._editing_id = None
        self._image_path = ""

        outer = QHBoxLayout(self)
        outer.setContentsMargins(10, 10, 0, 0)
        outer.setSpacing(10)

        self.rail = BatchRail(
            self.SUITE_MODE, "photo", self._poster_and_url,
            row_builder=self._rows)

        # -- compose (centre — the writing dominates the window) ---------------
        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        compose_host = QWidget()
        right = QVBoxLayout(compose_host)
        right.setContentsMargins(0, 0, 0, 0)

        card = Card("COMPOSE — one photo, one post")
        right.addWidget(card)

        # -- PHOTO — a rail section, opened when wanted (Sean: images don't
        #    need to be on screen all the time) --------------------------------
        self.photo_sec = Accordion("PHOTO — none yet")
        pick_row = QHBoxLayout()
        self.preview = thumb_label("", 120)
        pick_row.addWidget(self.preview)
        pick_col = QVBoxLayout()
        pick_btn = QPushButton("Choose photo…")
        pick_btn.clicked.connect(self._choose_image)
        pick_col.addWidget(pick_btn, 0, Qt.AlignLeft)
        self.path_lbl = hint("No photo yet.")
        self.path_lbl.setWordWrap(True)
        pick_col.addWidget(self.path_lbl)
        pick_col.addStretch(1)
        pick_row.addLayout(pick_col, 1)
        self.photo_sec.add_layout(pick_row)

        card.body.addWidget(field_label("Title"))
        self.title_edit = QLineEdit()
        card.body.addWidget(self.title_edit)

        card.body.addWidget(field_label("Caption / description"))
        # The CMS solo poster has the shortcode toolbar on this exact field —
        # SIMPLE face = same controls; BIGGIE face = the same body as blocks.
        self.caption_edit = BodyEditor(allow_mosaic=False, simple_height=84)
        card.body.addWidget(self.caption_edit)

        card.body.addWidget(field_label("ALT text — one plain sentence for screen readers"))
        self.alt_edit = QPlainTextEdit()
        self.alt_edit.setFixedHeight(56)
        card.body.addWidget(self.alt_edit)

        ai_row = QHBoxLayout()
        ai_btn = QPushButton("✨ AI Fill (Gemini)")
        ai_btn.clicked.connect(self._ai_fill)
        ai_row.addWidget(ai_btn)
        self.ai_status = hint("")
        ai_row.addWidget(self.ai_status, 1)
        card.body.addLayout(ai_row)

        card.body.addWidget(field_label("Tags (space-separated #hashtags)"))
        self.tags_edit = QLineEdit()
        card.body.addWidget(self.tags_edit)

        # -- OPTIONS — a rail section ------------------------------------------
        self.options_sec = Accordion("OPTIONS — category · status · colour")
        grid = QGridLayout()
        grid.addWidget(field_label("Category"), 0, 0)
        grid.addWidget(field_label("Album"), 0, 1)
        self.cat_edit = QLineEdit()
        self.album_edit = QLineEdit()
        grid.addWidget(self.cat_edit, 1, 0)
        grid.addWidget(self.album_edit, 1, 1)
        self.options_sec.add_layout(grid)

        opts = QGridLayout()
        opts.addWidget(field_label("Orientation"), 0, 0)
        self.orient_combo = QComboBox()
        self.orient_combo.addItems(["auto", "landscape", "portrait", "square"])
        opts.addWidget(self.orient_combo, 0, 1)
        opts.addWidget(field_label("Status"), 1, 0)
        self.status_combo = QComboBox()
        self.status_combo.addItems(["published", "draft"])
        opts.addWidget(self.status_combo, 1, 1)
        opts.addWidget(field_label("Colour / B&W"), 2, 0)
        self.colour_combo = QComboBox()
        self.colour_combo.addItems(_COLOUR_LABELS)
        self.colour_combo.setToolTip("A search/filter tag — never changes how the photo looks.")
        opts.addWidget(self.colour_combo, 2, 1)
        self.options_sec.add_layout(opts)

        dl_row = QHBoxLayout()
        self.dl_check = QCheckBox("Allow download")
        dl_row.addWidget(self.dl_check)
        self.options_sec.add_layout(dl_row)
        self.dl_url = QLineEdit()
        self.dl_url.setPlaceholderText("Download URL (only if allowed)")
        self.options_sec.add(self.dl_url)

        right.addStretch(1)
        scroll.setWidget(compose_host)

        # QUEUE POST lives OUTSIDE the scroll, pinned under it — the compose
        # form may scroll, but its primary action must never be below the fold.
        act = QHBoxLayout()
        act.setContentsMargins(0, 0, 0, 10)
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

        centre = QVBoxLayout()
        centre.setSpacing(8)
        centre.addWidget(scroll, 1)
        centre.addLayout(act)
        outer.addLayout(centre, 1)

        # -- the rail: sections scroll, SEND stays pinned ----------------------
        outer.addWidget(build_rail(
            [self.photo_sec, self.options_sec, self.rail.section],
            [self.rail.send_box]))

        self._ai_bridge = _AiBridge()
        self._ai_bridge.done.connect(self._apply_ai)
        self._ai_bridge.failed.connect(self._ai_failed)

    # -- rows ------------------------------------------------------------------
    def _rows(self, session, refresh_cb):
        return [default_draft_row(session, d, refresh_cb, edit_cb=self._edit)
                for d in session.list_drafts()]

    # -- compose behaviour ------------------------------------------------------
    def _choose_image(self):
        from .pickers import pick_image
        p = pick_image(self, (self.app_config() or {}).get("url", ""))
        if not p:
            return
        self._image_path = p
        self.path_lbl.setText(os.path.basename(p))
        self.photo_sec.header.setText(f"PHOTO — {os.path.basename(p)}")
        if not self.title_edit.text().strip():
            self.title_edit.setText(os.path.splitext(os.path.basename(p))[0])
        pm = load_pixmap(p, 120)
        if pm:
            self.preview.setPixmap(pm)

    def _edit(self, draft: O.Draft):
        self._editing_id = draft.draft_id
        cover = draft.cover()
        self._image_path = cover.local_path if cover else ""
        self.path_lbl.setText(os.path.basename(self._image_path) or "No photo yet.")
        self.photo_sec.header.setText(
            f"PHOTO — {os.path.basename(self._image_path)}" if self._image_path
            else "PHOTO — none yet")
        pm = load_pixmap(cover.thumb_square if cover else self._image_path, 120)
        if pm:
            self.preview.setPixmap(pm)
        self.title_edit.setText(draft.title)
        self.tags_edit.setText(draft.tags)
        self.caption_edit.set_state(draft.caption, getattr(draft, "body_blocks", ""))
        self.alt_edit.setPlainText(draft.alt)
        self.cat_edit.setText(draft.category)
        self.album_edit.setText(draft.album)
        self.orient_combo.setCurrentText(draft.orientation or "auto")
        self.status_combo.setCurrentText(draft.img_status)
        self.colour_combo.setCurrentText(_VAL_TO_COLOUR.get(draft.color_mode, "—"))
        self.dl_check.setChecked(draft.allow_download)
        self.dl_url.setText(draft.download_url)

    def _clear(self):
        self._editing_id = None
        self._image_path = ""
        self.path_lbl.setText("No photo yet.")
        self.photo_sec.header.setText("PHOTO — none yet")
        self.preview.clear()
        for w in (self.title_edit, self.tags_edit, self.cat_edit,
                  self.album_edit, self.dl_url):
            w.clear()
        self.caption_edit.clear()
        self.alt_edit.clear()
        self.orient_combo.setCurrentText("auto")
        self.status_combo.setCurrentText("published")
        self.colour_combo.setCurrentText("—")
        self.dl_check.setChecked(False)
        self.ai_status.setText("")

    def _save(self, ready: bool):
        session = self.rail.ensure_session()
        if not self._image_path or not os.path.isfile(self._image_path):
            QMessageBox.warning(self, "No photo", "Choose a photo first.")
            return
        draft = (session.load_draft(self._editing_id) if self._editing_id else None) \
            or O.Draft(draft_id=O._new_id(), kind=O.KIND_SOLO, mode=self.SUITE_MODE)
        draft.title = self.title_edit.text().strip()
        draft.tags = self.tags_edit.text().strip()
        draft.caption = self.caption_edit.toPlainText().strip()
        draft.body_blocks = self.caption_edit.blocks_json()
        draft.alt = self.alt_edit.toPlainText().strip()
        draft.category = self.cat_edit.text().strip()
        draft.album = self.album_edit.text().strip()
        draft.orientation = self.orient_combo.currentText()
        draft.color_mode = _COLOUR_TO_VAL.get(self.colour_combo.currentText(), "")
        draft.img_status = self.status_combo.currentText()
        draft.allow_download = self.dl_check.isChecked()
        draft.download_url = self.dl_url.text().strip()
        draft.images = [O.DraftImage(local_path=self._image_path,
                                     filename=os.path.basename(self._image_path),
                                     is_cover=True,
                                     alt=draft.alt)]   # solo: the post's ALT IS the image's
        O.generate_draft_thumbs(draft)
        problems = draft.validate()
        if ready and problems:
            QMessageBox.warning(self, "Not ready", "\n".join(problems))
        draft.status = O.ST_READY if (ready and not problems) else O.ST_DRAFT
        session.add_draft(draft)
        if session.over_soft_limit():
            QMessageBox.information(
                self, "Big batch",
                f"This batch now holds {session.image_count()} images "
                f"(gentle limit ~{O.SOFT_BATCH_IMAGE_LIMIT}). It'll still send fine — "
                "consider starting a new batch to stay friendly to the shared host.")
        self._clear()
        self.rail.refresh_batches()

    # -- AI fill ------------------------------------------------------------------
    def _ai_fill(self):
        img = self._image_path
        if not img or not os.path.isfile(img):
            QMessageBox.warning(self, "No photo", "Choose a photo first.")
            return
        try:
            import snap_enrich  # noqa: F401
        except Exception:
            QMessageBox.critical(self, "AI Fill unavailable",
                                 "The shared enrichment module isn't installed.")
            return
        self.ai_status.setText("Thinking… (Gemini)")
        bridge = self._ai_bridge

        def work():
            try:
                import snap_enrich
                import config as _cfg
                data = _cfg.load()
                site = (data.get("url") or "").strip()
                cats, albums, cat_d, alb_d, etags = [], [], {}, {}, []
                try:
                    import snap_library as lib
                    if site:
                        cats, albums = lib.categories(site), lib.albums(site)
                        cat_d = lib.category_descriptions(site)
                        alb_d = lib.album_descriptions(site)
                        etags = lib.tags(site)
                except Exception:
                    pass
                meta = snap_enrich.enrich_image(
                    img, categories=cats, albums=albums,
                    api_key=data.get("gemini_api_key", ""),
                    custom_prompt=(data.get("gemini_last_prompt") or "").strip(),
                    cat_descriptions=cat_d, album_descriptions=alb_d,
                    existing_tags=etags)
                bridge.done.emit(meta or {})
            except Exception as e:  # noqa: BLE001
                bridge.failed.emit(str(e))

        threading.Thread(target=work, daemon=True).start()

    def _apply_ai(self, meta: dict):
        if meta.get("caption"):
            self.caption_edit.setPlainText(meta["caption"])
        if meta.get("alt"):
            self.alt_edit.setPlainText(meta["alt"])
        if meta.get("tags"):
            self.tags_edit.setText(meta["tags"])
        if meta.get("title") and not self.title_edit.text().strip():
            self.title_edit.setText(meta["title"])
        if meta.get("category") and not self.cat_edit.text().strip():
            self.cat_edit.setText(meta["category"])
        if meta.get("album") and not self.album_edit.text().strip():
            self.album_edit.setText(meta["album"])
        self.ai_status.setText("Filled ✓ — make it yours before posting")

    def _ai_failed(self, msg: str):
        self.ai_status.setText("")
        QMessageBox.critical(self, "AI Fill failed", msg)

    # -- network ------------------------------------------------------------------
    def _poster_and_url(self):
        cfg = self.app_config() or {}
        url = (cfg.get("url") or "").strip()
        key = (cfg.get("api_key") or "").strip()
        if not url or not key:
            QMessageBox.warning(self, "No site", "Pick a site at the top first.")
            return None, url
        try:
            conn = SumnaConnection(url, key)
        except InsecureTransportError as e:
            QMessageBox.warning(self, "Insecure connection", str(e))
            return None, url
        return SoloPoster(conn, site_data=None), url

# ===== SNAPSMACK EOF =====
