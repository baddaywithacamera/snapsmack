"""COLD SNAP Qt — COLD STACK: single / carousel / trigram (gram grid sites).

Feature parity with the Tk sumna_gram panel — the same per-image controls the
web gram poster exposes (fit/fill, size, border, matte, shadow, focal point,
zoom, split), trigram slicing with adjustable seams and a band preview, and
trigram groups that send as one unit. Engines untouched.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os

from PySide6.QtCore import Qt
from PySide6.QtGui import QColor
from PySide6.QtWidgets import (
    QWidget, QHBoxLayout, QVBoxLayout, QGridLayout, QLabel, QLineEdit,
    QPlainTextEdit, QComboBox, QCheckBox, QPushButton, QRadioButton,
    QButtonGroup, QColorDialog, QFileDialog, QMessageBox, QScrollArea, QFrame,
)

import sumna_offline as O
from sumna_post import SumnaConnection, GramPoster, InsecureTransportError

from . import theme
from .widgets import (Accordion, Card, build_rail, hint, field_label,
                      big_button, thumb_label, load_pixmap, SliderRow,
                      status_badge)
from .body_editor import BodyEditor
from .drafts_panel import BatchRail, default_draft_row


class StackMode(QWidget):
    SUITE_MODE = O.MODE_GRAM

    def __init__(self, app_config_provider, parent=None):
        super().__init__(parent)
        self.app_config = app_config_provider

        # working state (mirrors the Tk panel)
        self._work_images = []            # single/carousel
        self._trig_slots = [[], [], []]
        self._trig_cover_src = ""
        self._trig_group_key = ""
        self._sel_img = None
        self._editing_id = ""
        self._editing_group = ""
        self._loading_controls = False

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

        # ---- PHOTOS — a rail section, opened when wanted ----------------------
        self.photos_sec = Accordion("PHOTOS — none yet")

        kind_row = QVBoxLayout()   # stacked — the rail is a narrow column
        self.kind_group = QButtonGroup(self)
        self._kind_btns = {}
        for val, label in (("single", "One photo"), ("carousel", "Carousel (stack)"),
                           ("trigram", "Trigram (3-across band)")):
            rb = QRadioButton(label)
            self.kind_group.addButton(rb)
            self._kind_btns[val] = rb
            kind_row.addWidget(rb)
        self._kind_btns["carousel"].setChecked(True)
        self.kind_group.buttonToggled.connect(lambda *_: self._on_kind_change())
        self.photos_sec.add_layout(kind_row)

        # trigram controls (hidden unless trigram)
        self.trig_box = QWidget()
        tb = QVBoxLayout(self.trig_box)
        tb.setContentsMargins(0, 0, 0, 0)
        style_row = QGridLayout()   # stacked pairs — the rail is a narrow column
        style_row.addWidget(field_label("Trigram of"), 0, 0)
        self.trig_style = QComboBox()
        self.trig_style.addItem("3 single slices", "single")
        self.trig_style.addItem("3 carousels (slice = cover)", "carousels")
        style_row.addWidget(self.trig_style, 0, 1)
        style_row.addWidget(field_label("Direction"), 1, 0)
        self.trig_orient = QComboBox()
        self.trig_orient.addItem("across (left · mid · right)", "h")
        self.trig_orient.addItem("down (top · mid · bottom)", "v")
        style_row.addWidget(self.trig_orient, 1, 1)
        tb.addLayout(style_row)
        self.cut_a = SliderRow("Seam A %", 5, 90, 33)
        self.cut_b = SliderRow("Seam B %", 10, 95, 67)
        self.cut_a.slider.sliderReleased.connect(self._reslice)
        self.cut_b.slider.sliderReleased.connect(self._reslice)
        tb.addWidget(self.cut_a)
        tb.addWidget(self.cut_b)
        band_row = QHBoxLayout()
        band_row.addWidget(hint("Band preview (how the row lands):"))
        self.band_host = QHBoxLayout()
        band_row.addLayout(self.band_host)
        band_row.addStretch(1)
        tb.addLayout(band_row)
        self.photos_sec.add(self.trig_box)

        src_row = QHBoxLayout()
        self.add_btn = QPushButton("Add photos…")
        self.add_btn.clicked.connect(self._add_images)
        self.clear_imgs_btn = QPushButton("Clear photos")
        self.clear_imgs_btn.setObjectName("Quiet")
        self.clear_imgs_btn.clicked.connect(self._clear_images)
        src_row.addWidget(self.add_btn)
        src_row.addWidget(self.clear_imgs_btn)
        src_row.addStretch(1)
        self.photos_sec.add_layout(src_row)
        self.slice_btn = QPushButton("Choose cover & slice into three…")
        self.slice_btn.clicked.connect(self._slice_cover)
        self.photos_sec.add(self.slice_btn)

        self.strip_col = QVBoxLayout()
        self.strip_col.setSpacing(4)
        self.photos_sec.add_layout(self.strip_col)

        # ---- IMAGE CONTROLS card -------------------------------------------------
        # Locked until a photo is actually selected — live sliders over
        # "(no photos)" are controls that operate on nothing.
        ctrl = Accordion("SELECTED PHOTO")
        self.ctrl_card = ctrl.body
        ctrl.body.setEnabled(False)
        ctrl.setToolTip("The same per-photo controls as the web poster. Add "
                        "photos, then click one — these work on the clicked photo.")
        self.sel_sec = ctrl
        fit_row = QHBoxLayout()
        fit_row.addWidget(field_label("Fit"))
        self.crop_combo = QComboBox()
        self.crop_combo.addItem("Fit — whole image in the tile", "fit")
        self.crop_combo.addItem("Fill — square crop", "fill")
        fit_row.addWidget(self.crop_combo, 1)
        ctrl.add_layout(fit_row)
        self.split_check = QCheckBox("Post this photo separately (split)")
        ctrl.add(self.split_check)

        ctrl.add(field_label("ALT text"))
        self.alt_edit = QLineEdit()
        self.alt_edit.setPlaceholderText("One plain sentence — saved with the image.")
        self.alt_edit.setToolTip("Accessibility ALT for screen readers — saved "
                                 "with THIS image on the site (img_alt).")
        ctrl.add(self.alt_edit)

        self.size_row = SliderRow("Image size %", 10, 100, 100)
        self.fx_row = SliderRow("Focal X %", 0, 100, 50)
        self.fy_row = SliderRow("Focal Y %", 0, 100, 50)
        self.zoom_row = SliderRow("Zoom %", 100, 300, 100)
        self.border_row = SliderRow("Border px", 0, 50, 0)
        self.shadow_row = SliderRow("Shadow", 0, 3, 0)
        for r in (self.size_row, self.fx_row, self.fy_row, self.zoom_row,
                  self.border_row, self.shadow_row):
            ctrl.add(r)
        for r in (self.fx_row, self.fy_row, self.zoom_row):
            r.slider.sliderReleased.connect(self._recrop_selected)

        # A colour is picked from a picker, not typed as hex — the swatch IS
        # the button; the hex field stays as the typed alternative. Stacked
        # pairs: the rail is a narrow column.
        colour_grid = QGridLayout()
        colour_grid.addWidget(field_label("Border colour"), 0, 0, 1, 2)
        self.border_colour = QLineEdit("#000000")
        self.border_well = self._colour_well(self.border_colour, "Pick the border colour")
        colour_grid.addWidget(self.border_well, 1, 0)
        colour_grid.addWidget(self.border_colour, 1, 1)
        colour_grid.addWidget(field_label("Background matte"), 2, 0, 1, 2)
        self.bg_colour = QLineEdit("#ffffff")
        self.bg_well = self._colour_well(self.bg_colour, "Pick the background matte")
        colour_grid.addWidget(self.bg_well, 3, 0)
        colour_grid.addWidget(self.bg_colour, 3, 1)
        ctrl.add_layout(colour_grid)

        prow = QHBoxLayout()
        self.sel_preview = thumb_label("", 110)
        prow.addWidget(self.sel_preview)
        upd = QPushButton("Update crop preview")
        upd.clicked.connect(self._recrop_selected)
        prow.addWidget(upd)
        prow.addStretch(1)
        ctrl.add_layout(prow)

        # write-back wiring (guarded by _loading_controls)
        self.crop_combo.currentIndexChanged.connect(self._write_controls)
        self.split_check.toggled.connect(self._write_controls)
        for r in (self.size_row, self.fx_row, self.fy_row, self.zoom_row,
                  self.border_row, self.shadow_row):
            r.slider.valueChanged.connect(self._write_controls)
        self.border_colour.textChanged.connect(self._write_controls)
        self.bg_colour.textChanged.connect(self._write_controls)
        self.alt_edit.textChanged.connect(self._write_controls)

        # ---- POST card --------------------------------------------------------------
        post = Card("POST")
        # The compose card owns the centre pane; do not centre-align it at its
        # narrow sizeHint or widescreen layouts collapse into a tiny column.
        right.addWidget(post)
        post.body.addWidget(field_label("Caption"))
        # Same shortcode toolbar the CMS carousel editor puts on this field;
        # BIGGIE face builds the same caption out of blocks.
        self.caption_edit = BodyEditor(allow_mosaic=False, simple_height=84)
        post.body.addWidget(self.caption_edit)

        ai_row = QHBoxLayout()
        ai_btn = QPushButton("✨ AI Fill (Gemini)")
        ai_btn.setToolTip("Writes an ALT sentence for EVERY photo in the post, "
                          "and suggests a caption and tags from the cover if "
                          "those boxes are empty.")
        ai_btn.clicked.connect(self._ai_fill)
        ai_row.addWidget(ai_btn)
        self.ai_status = hint("")
        ai_row.addWidget(self.ai_status, 1)
        post.body.addLayout(ai_row)
        post.body.addWidget(field_label("Tags (space-separated #hashtags)"))
        self.tags_edit = QLineEdit()
        post.body.addWidget(self.tags_edit)

        # ---- OPTIONS — a rail section ----------------------------------------
        self.options_sec = Accordion("OPTIONS — date · status · download")
        self.options_sec.add(field_label("Date (YYYY-MM-DD HH:MM:SS, blank = now)"))
        self.date_edit = QLineEdit()
        self.options_sec.add(self.date_edit)
        opt_row = QHBoxLayout()
        self.comments_check = QCheckBox("Comments")
        self.comments_check.setChecked(True)
        opt_row.addWidget(self.comments_check)
        self.dl_check = QCheckBox("Allow download")
        opt_row.addWidget(self.dl_check)
        opt_row.addStretch(1)
        self.options_sec.add_layout(opt_row)
        st_row = QHBoxLayout()
        st_row.addWidget(field_label("Status"))
        self.status_combo = QComboBox()
        self.status_combo.addItems(["published", "draft"])
        st_row.addWidget(self.status_combo)
        st_row.addStretch(1)
        self.options_sec.add_layout(st_row)
        self.dl_url = QLineEdit()
        self.dl_url.setPlaceholderText("Download URL (only if allowed)")
        self.options_sec.add(self.dl_url)

        right.addStretch(1)
        scroll.setWidget(host)

        # Primary action pinned under the scroll — never below the fold.
        act = QHBoxLayout()
        act.setContentsMargins(0, 0, 0, 10)
        act.addStretch(1)
        self.queue_btn = big_button("QUEUE POST")
        self.queue_btn.setMaximumWidth(260)
        self.queue_btn.setToolTip("Add this post to the batch. Nothing publishes until SEND.")
        self.queue_btn.clicked.connect(lambda: self._commit(ready=True))
        save_btn = QPushButton("Save as draft")
        save_btn.clicked.connect(lambda: self._commit(ready=False))
        act.addWidget(save_btn)
        clear_btn = QPushButton("Clear")
        clear_btn.setObjectName("Quiet")
        clear_btn.clicked.connect(self._clear_compose)
        act.addWidget(clear_btn)
        act.addWidget(self.queue_btn)

        centre = QVBoxLayout()
        centre.setSpacing(8)
        centre.addWidget(scroll, 1)
        centre.addLayout(act)
        outer.addLayout(centre, 1)

        # The rail: PHOTOS, the per-photo controls, options and the batch all
        # accordion open when wanted; SEND stays pinned (SNAP SLAPPER design).
        outer.addWidget(build_rail(
            [self.photos_sec, self.sel_sec, self.options_sec, self.rail.section],
            [self.rail.send_box]))
        self._on_kind_change()

    # ======================================================================
    # Colour wells
    # ======================================================================
    def _colour_well(self, hex_edit: QLineEdit, tip: str) -> QPushButton:
        well = QPushButton()
        well.setObjectName("ColourWell")
        well.setToolTip(tip)
        well.setCursor(Qt.PointingHandCursor)

        def paint():
            c = QColor(hex_edit.text().strip())
            well.setStyleSheet(
                f"background: {c.name() if c.isValid() else theme.CANVAS};")

        def pick():
            seed = QColor(hex_edit.text().strip())
            c = QColorDialog.getColor(
                seed if seed.isValid() else QColor("#000000"), self, tip)
            if c.isValid():
                hex_edit.setText(c.name())   # textChanged → _write_controls

        hex_edit.textChanged.connect(lambda *_: paint())
        well.clicked.connect(pick)
        paint()
        return well

    def _reflect_ctrl_enabled(self):
        on = self._sel_img is not None
        self.ctrl_card.setEnabled(on)
        if on and not self.sel_sec.header.isChecked():
            self.sel_sec.set_expanded(True)   # clicking a photo opens its controls

    # ======================================================================
    # AI fill (per-photo ALT + caption/tags from the cover)
    # ======================================================================
    def _all_images(self) -> list:
        if self._kind() == "trigram":
            return [im for slot in self._trig_slots for im in slot]
        return list(self._work_images)

    def _ai_fill(self):
        imgs = self._all_images()
        if not imgs:
            QMessageBox.warning(self, "No photos", "Add photos first.")
            return
        from .enrich_worker import EnrichWorker
        self._ai_imgs = imgs
        self._ai_worker = EnrichWorker(self.app_config() or {})
        self._ai_worker.image_done.connect(self._ai_image_done)
        self._ai_worker.progressed.connect(
            lambda done, total: self.ai_status.setText(
                f"Thinking… photo {done} of {total} (Gemini)"))
        self._ai_worker.finished.connect(
            lambda: self.ai_status.setText("Filled ✓ — make it yours before posting"))
        self._ai_worker.failed.connect(self._ai_failed)
        self.ai_status.setText(f"Thinking… photo 1 of {len(imgs)} (Gemini)")
        self._ai_worker.start([im.local_path for im in imgs])

    def _ai_image_done(self, idx: int, meta: dict):
        imgs = getattr(self, "_ai_imgs", [])
        if not (0 <= idx < len(imgs)):
            return
        im = imgs[idx]
        if meta.get("alt"):
            im.alt = meta["alt"]
            if im is self._sel_img:
                self._loading_controls = True
                self.alt_edit.setText(im.alt)
                self._loading_controls = False
        # The cover speaks for the post — fill caption/tags only if still empty.
        if im.is_cover or (idx == 0 and not any(x.is_cover for x in imgs)):
            if meta.get("caption") and not self.caption_edit.toPlainText().strip():
                self.caption_edit.setPlainText(meta["caption"])
            if meta.get("tags") and not self.tags_edit.text().strip():
                self.tags_edit.setText(meta["tags"])

    def _ai_failed(self, msg: str):
        self.ai_status.setText("")
        QMessageBox.critical(self, "AI Fill failed", msg)

    # ======================================================================
    # Kind / sources
    # ======================================================================
    def _kind(self) -> str:
        for val, rb in self._kind_btns.items():
            if rb.isChecked():
                return val
        return "carousel"

    def _on_kind_change(self):
        trig = self._kind() == "trigram"
        self.trig_box.setVisible(trig)
        self.slice_btn.setVisible(trig)
        self.add_btn.setVisible(not trig)
        self.clear_imgs_btn.setVisible(not trig)
        self._render_strip()

    def _new_image(self, path, cover=False, pos=0):
        return O.DraftImage(local_path=path, filename=os.path.basename(path),
                            is_cover=cover, sort_position=pos)

    def _add_images(self):
        from .pickers import pick_images
        paths = pick_images(self, (self.app_config() or {}).get("url", ""))
        for p in paths:
            if len(self._work_images) >= O.CAROUSEL_MAX_IMAGES:
                QMessageBox.information(self, "That's the lot",
                                        f"Up to {O.CAROUSEL_MAX_IMAGES} photos per post.")
                break
            self._work_images.append(self._new_image(p, cover=not self._work_images,
                                                     pos=len(self._work_images)))
        self._render_strip()

    def _clear_images(self):
        self._work_images = []
        self._sel_img = None
        self._render_strip()

    def _slice_cover(self):
        from .pickers import pick_image
        cover = pick_image(self, (self.app_config() or {}).get("url", ""),
                           "Choose a cover to slice into three")
        if not cover:
            return
        self.rail.ensure_session()
        self._trig_cover_src = cover
        self._trig_group_key = ""
        self._do_slice()

    def _reslice(self):
        if self._kind() == "trigram" and self._trig_cover_src:
            self._do_slice()

    def _do_slice(self):
        if not self._trig_cover_src or not os.path.isfile(self._trig_cover_src):
            return
        session = self.rail.ensure_session()
        extras = [slot[1:] if slot else [] for slot in self._trig_slots]
        ca = max(5, min(90, self.cut_a.value())) / 100.0
        cb = max(self.cut_a.value() + 5, min(95, self.cut_b.value())) / 100.0
        chunks = O.slice_trigram_cover(
            self._trig_cover_src, session.images_dir,
            orientation=self.trig_orient.currentData(), mode=self.SUITE_MODE,
            cut_a=ca, cut_b=cb, group_key=self._trig_group_key or None)
        self._trig_group_key = chunks[0].group_key
        self._trig_slots = []
        for i, c in enumerate(chunks):
            slot = [c.images[0]]
            if i < len(extras):
                slot.extend(extras[i])
            self._trig_slots.append(slot)
        self._select_image(self._trig_slots[0][0])
        self._render_strip()

    def _add_to_slot(self, slot_idx: int):
        if self.trig_style.currentData() != "carousels":
            QMessageBox.information(
                self, "Single slices",
                "Switch 'Trigram of' to '3 carousels' to add photos to a slot.")
            return
        from .pickers import pick_images
        paths = pick_images(self, (self.app_config() or {}).get("url", ""),
                            f"Add photos to slot {slot_idx + 1}")
        slot = self._trig_slots[slot_idx]
        for p in paths:
            if len(slot) >= O.CAROUSEL_MAX_IMAGES:
                break
            slot.append(self._new_image(p, cover=False, pos=len(slot)))
        self._render_strip()

    # ======================================================================
    # Strip / band rendering
    # ======================================================================
    def _clear_layout(self, lay):
        while lay.count():
            item = lay.takeAt(0)
            w = item.widget()
            if w:
                w.deleteLater()
            elif item.layout():
                self._clear_layout(item.layout())

    def _render_strip(self):
        self._clear_layout(self.strip_col)
        if self._kind() == "trigram":
            if not any(self._trig_slots):
                self.strip_col.addWidget(hint("Choose a cover and slice it into three."))
            else:
                labels = ("Left / Top", "Middle", "Right / Bottom")
                for i, slot in enumerate(self._trig_slots):
                    row = QHBoxLayout()
                    tag = QLabel(labels[i])
                    tag.setObjectName("CardTitle")
                    tag.setFixedWidth(96)
                    row.addWidget(tag)
                    self._render_image_row(row, slot, slot_idx=i)
                    add = QPushButton("+ photos")
                    add.clicked.connect(lambda _=False, i=i: self._add_to_slot(i))
                    row.addWidget(add)
                    row.addStretch(1)
                    self.strip_col.addLayout(row)
        else:
            row = QHBoxLayout()
            self._render_image_row(row, self._work_images, slot_idx=None)
            row.addStretch(1)
            self.strip_col.addLayout(row)
        self._render_band()
        self._reflect_ctrl_enabled()   # every photo mutation lands here
        n = (sum(len(s) for s in self._trig_slots) if self._kind() == "trigram"
             else len(self._work_images))
        self.photos_sec.header.setText(
            f"PHOTOS — {n} added" if n else "PHOTOS — none yet")

    def _render_image_row(self, lay, images, slot_idx):
        if not images:
            lay.addWidget(hint("(no photos)"))
            return
        for i, im in enumerate(images):
            cell = QVBoxLayout()
            holder = QFrame()
            sel = im is self._sel_img
            holder.setStyleSheet(
                f"border: 2px solid {theme.ACCENT if sel else theme.BORDER};"
                f"border-radius: 5px; background: {theme.CANVAS};")
            hl = QVBoxLayout(holder)
            hl.setContentsMargins(2, 2, 2, 2)
            t = thumb_label(im.thumb_square or im.local_path, 56)
            t.setCursor(Qt.PointingHandCursor)
            t.mousePressEvent = (lambda ev, im=im: self._select_image(im))
            hl.addWidget(t)
            cell.addWidget(holder)
            brow = QHBoxLayout()
            tag = QLabel("★" if im.is_cover else str(i + 1))
            tag.setObjectName("Hint")
            brow.addWidget(tag)
            left = QPushButton("◀"); left.setFixedSize(26, 22)
            left.clicked.connect(lambda _=False, im=im, s=slot_idx: self._move(im, -1, s))
            right = QPushButton("▶"); right.setFixedSize(26, 22)
            right.clicked.connect(lambda _=False, im=im, s=slot_idx: self._move(im, 1, s))
            rm = QPushButton("✕"); rm.setObjectName("Danger"); rm.setFixedSize(26, 22)
            rm.clicked.connect(lambda _=False, im=im, s=slot_idx: self._remove(im, s))
            brow.addWidget(left); brow.addWidget(right); brow.addWidget(rm)
            cell.addLayout(brow)
            lay.addLayout(cell)

    def _render_band(self):
        self._clear_layout(self.band_host)
        if not (self._kind() == "trigram" and any(self._trig_slots)):
            return
        for slot in self._trig_slots:
            cov = slot[0] if slot else None
            self.band_host.addWidget(
                thumb_label(cov.thumb_square if cov else "", 60))

    def _list_for_slot(self, slot_idx):
        return self._work_images if slot_idx is None else self._trig_slots[slot_idx]

    def _move(self, im, delta, slot_idx):
        lst = self._list_for_slot(slot_idx)
        if im not in lst:
            return
        i = lst.index(im)
        j = i + delta
        if 0 <= j < len(lst):
            lst[i], lst[j] = lst[j], lst[i]
            for k, x in enumerate(lst):
                x.sort_position = k
                if slot_idx is None or self._kind() != "trigram":
                    x.is_cover = (k == 0)
            self._render_strip()

    def _remove(self, im, slot_idx):
        lst = self._list_for_slot(slot_idx)
        if im not in lst:
            return
        if self._kind() == "trigram" and im.is_cover:
            QMessageBox.information(self, "Cover slice",
                                    "The slice is this slot's cover; it can't be removed.")
            return
        lst.remove(im)
        for k, x in enumerate(lst):
            x.sort_position = k
        if self._sel_img is im:
            self._sel_img = None
        self._render_strip()

    # ======================================================================
    # Per-image controls
    # ======================================================================
    def _select_image(self, im):
        self._sel_img = im
        self._loading_controls = True
        idx = self.crop_combo.findData(im.crop_mode)
        self.crop_combo.setCurrentIndex(max(0, idx))
        self.size_row.set_value(im.size_pct)
        self.border_row.set_value(im.border_px)
        self.border_colour.setText(im.border_color)
        self.bg_colour.setText(im.bg_color)
        self.shadow_row.set_value(im.shadow)
        self.fx_row.set_value(im.focus_x)
        self.fy_row.set_value(im.focus_y)
        self.zoom_row.set_value(im.zoom)
        self.split_check.setChecked(im.split)
        self.alt_edit.setText(getattr(im, "alt", "") or "")
        self._loading_controls = False
        self._show_sel_preview()
        self._render_strip()

    def _write_controls(self, *_):
        if self._loading_controls or self._sel_img is None:
            return
        im = self._sel_img
        im.crop_mode = self.crop_combo.currentData() or "fit"
        im.size_pct = self.size_row.value()
        im.border_px = self.border_row.value()
        im.shadow = self.shadow_row.value()
        im.focus_x = self.fx_row.value()
        im.focus_y = self.fy_row.value()
        im.zoom = self.zoom_row.value()
        im.border_color = self.border_colour.text().strip() or "#000000"
        im.bg_color = self.bg_colour.text().strip() or "#ffffff"
        im.split = self.split_check.isChecked()
        im.alt = self.alt_edit.text().strip()

    def _recrop_selected(self):
        if self._sel_img is None:
            return
        self._write_controls()
        im = self._sel_img
        if im.local_path and os.path.isfile(im.local_path):
            import snap_thumbs
            res = snap_thumbs.generate_thumbs(
                im.local_path, sq_size=400, asp_max=400,
                focus_x=im.focus_x, focus_y=im.focus_y, zoom=im.zoom)
            if res:
                im.thumb_square = res["sq_path"]
                im.thumb_aspect = res["asp_path"]
                im.width, im.height = res["width"], res["height"]
        self._show_sel_preview()
        self._render_strip()

    def _show_sel_preview(self):
        im = self._sel_img
        pm = load_pixmap((im.thumb_square or im.local_path) if im else "", 110)
        if pm:
            self.sel_preview.setPixmap(pm)
        else:
            self.sel_preview.clear()

    # ======================================================================
    # Commit
    # ======================================================================
    def _apply_post_fields(self, d: O.Draft):
        d.body_blocks = self.caption_edit.blocks_json()
        d.img_status = self.status_combo.currentText()
        d.post_date = self.date_edit.text().strip()
        d.allow_comments = self.comments_check.isChecked()
        d.allow_download = self.dl_check.isChecked()
        d.download_url = self.dl_url.text().strip()

    def _commit(self, ready: bool):
        session = self.rail.ensure_session()
        caption = self.caption_edit.toPlainText().strip()
        tags = self.tags_edit.text().strip()
        kind = self._kind()

        if self._editing_id:
            session.delete_draft(self._editing_id)
        if self._editing_group:
            for d in session.group_drafts(self._editing_group):
                session.delete_draft(d.draft_id)

        if kind == "trigram":
            if not all(self._trig_slots) or len(self._trig_slots) != 3:
                QMessageBox.warning(self, "Slice first",
                                    "Choose a cover and slice it into three.")
                return
            group_key = self._trig_group_key or O._new_id()
            for slot_idx, slot in enumerate(self._trig_slots, start=1):
                d = O.Draft(draft_id=O._new_id(), kind=O.KIND_GRAM_TRIGRAM,
                            mode=self.SUITE_MODE, caption=caption, tags=tags,
                            group_key=group_key, trigram_slot=slot_idx,
                            trigram_orientation=self.trig_orient.currentData())
                self._apply_post_fields(d)
                d.images = list(slot)
                O.generate_draft_thumbs(d)
                probs = d.validate()
                if ready and probs:
                    QMessageBox.warning(self, "Not ready", "\n".join(probs))
                    return
                d.status = O.ST_READY if ready else O.ST_DRAFT
                session.add_draft(d)
        else:
            imgs = list(self._work_images)
            if not imgs:
                QMessageBox.warning(self, "No photos", "Add at least one photo.")
                return
            dkind = O.KIND_GRAM_CAROUSEL if len(imgs) > 1 else O.KIND_GRAM_SINGLE
            d = O.Draft(draft_id=O._new_id(), kind=dkind, mode=self.SUITE_MODE,
                        caption=caption, tags=tags)
            self._apply_post_fields(d)
            d.images = imgs
            O.generate_draft_thumbs(d)
            probs = d.validate()
            if ready and probs:
                QMessageBox.warning(self, "Not ready", "\n".join(probs))
                return
            d.status = O.ST_READY if ready else O.ST_DRAFT
            session.add_draft(d)

        if session.over_soft_limit():
            QMessageBox.information(
                self, "Big batch",
                f"This batch now holds {session.image_count()} images "
                f"(gentle limit ~{O.SOFT_BATCH_IMAGE_LIMIT}). It'll still send fine — "
                "consider starting a new batch to stay friendly to the shared host.")
        self._clear_compose()
        self.rail.refresh_batches()

    def _clear_compose(self):
        self._work_images = []
        self._trig_slots = [[], [], []]
        self._trig_group_key = ""
        self._trig_cover_src = ""
        self.cut_a.set_value(33)
        self.cut_b.set_value(67)
        self._sel_img = None
        self._editing_id = ""
        self._editing_group = ""
        self.tags_edit.clear()
        self.date_edit.clear()
        self.dl_url.clear()
        self.status_combo.setCurrentText("published")
        self.comments_check.setChecked(True)
        self.dl_check.setChecked(False)
        self.caption_edit.clear()
        self._render_strip()
        self._show_sel_preview()

    # ======================================================================
    # Rail rows (groups trigram chunks into one card)
    # ======================================================================
    def _rows(self, session, refresh_cb):
        rows = []
        drafts = session.list_drafts()
        shown = set()
        for d in drafts:
            if d.kind == O.KIND_GRAM_TRIGRAM:
                if d.group_key in shown:
                    continue
                shown.add(d.group_key)
                rows.append(self._trigram_row(session, drafts, d.group_key, refresh_cb))
            else:
                sub = ("Carousel" if d.kind == O.KIND_GRAM_CAROUSEL else "Single") \
                    + f" · {len(d.images)} photo(s)"
                rows.append(default_draft_row(session, d, refresh_cb,
                                              edit_cb=self._edit_single, subtitle=sub))
        return rows

    def _trigram_row(self, session, drafts, group_key, refresh_cb):
        members = sorted([d for d in drafts if d.group_key == group_key],
                         key=lambda d: d.trigram_slot)
        ready_n = O.trigram_ready_count(drafts, group_key)
        row = QFrame()
        row.setObjectName("Card")
        lay = QHBoxLayout(row)
        lay.setContentsMargins(8, 8, 8, 8)
        strip = QHBoxLayout()
        for m in members:
            cov = m.cover()
            strip.addWidget(thumb_label(cov.thumb_square if cov else "", 32))
        lay.addLayout(strip)
        mid = QVBoxLayout()
        orient = members[0].trigram_orientation if members else "h"
        is_caro = any(len(m.images) > 1 for m in members)
        title = QLabel(f"Trigram ({'across' if orient == 'h' else 'down'}"
                       f"{' · carousels' if is_caro else ''}) · {ready_n}/3")
        title.setStyleSheet(f"color: {theme.INK}; font-weight: 700; background: transparent;")
        mid.addWidget(title)
        statuses = {m.status for m in members}
        if ready_n < 3:
            w = QLabel(f"waiting for {3 - ready_n} more slice(s)")
            w.setStyleSheet(f"color: {theme.WARN}; font-size: 11px; background: transparent;")
            mid.addWidget(w)
        else:
            st = "synced" if statuses == {O.ST_SYNCED} else (
                "failed" if O.ST_FAILED in statuses else (
                    "ready" if O.ST_READY in statuses else "draft"))
            mid.addWidget(status_badge(st))
        err = next((m.error for m in members if m.error), "")
        if err:
            e = QLabel(err)
            e.setWordWrap(True)
            e.setStyleSheet(f"color: {theme.DANGER}; font-size: 11px; background: transparent;")
            mid.addWidget(e)
        lay.addLayout(mid, 1)
        btns = QVBoxLayout()
        if statuses != {O.ST_SYNCED}:
            e = QPushButton("Edit")
            e.clicked.connect(lambda _=False, gk=group_key: self._edit_trigram(gk))
            btns.addWidget(e)
        delete = QPushButton("Delete")
        delete.setObjectName("Danger")

        def _del(_=False, ms=members):
            if QMessageBox.question(row, "Delete trigram",
                                    "Delete all three slices of this trigram?",
                                    QMessageBox.Yes | QMessageBox.No,
                                    QMessageBox.No) == QMessageBox.Yes:
                for m in ms:
                    session.delete_draft(m.draft_id)
                refresh_cb()

        delete.clicked.connect(_del)
        btns.addWidget(delete)
        lay.addLayout(btns)
        return row

    # ======================================================================
    # Edit (unsynced drafts reload into the composer)
    # ======================================================================
    def _edit_single(self, draft: O.Draft):
        self._clear_compose()
        self._editing_id = draft.draft_id
        self._kind_btns["carousel" if draft.kind == O.KIND_GRAM_CAROUSEL else "single"].setChecked(True)
        self._work_images = [O.DraftImage.from_dict(im.to_dict()) for im in draft.images]
        self._load_post_fields(draft)
        self._on_kind_change()
        if self._work_images:
            self._select_image(self._work_images[0])

    def _edit_trigram(self, group_key: str):
        session = self.rail.ensure_session()
        self._clear_compose()
        members = sorted(session.group_drafts(group_key), key=lambda d: d.trigram_slot)
        if not members:
            return
        self._editing_group = group_key
        self._trig_group_key = group_key
        self._kind_btns["trigram"].setChecked(True)
        oi = self.trig_orient.findData(members[0].trigram_orientation)
        self.trig_orient.setCurrentIndex(max(0, oi))
        self.cut_a.set_value(int(round(members[0].trigram_cut_a * 100)))
        self.cut_b.set_value(int(round(members[0].trigram_cut_b * 100)))
        self._trig_cover_src = ""    # original cover not retained; adjust tiles, not seams
        si = self.trig_style.findData(
            "carousels" if any(len(m.images) > 1 for m in members) else "single")
        self.trig_style.setCurrentIndex(max(0, si))
        self._trig_slots = [[O.DraftImage.from_dict(im.to_dict()) for im in m.images]
                            for m in members]
        self._load_post_fields(members[0])
        self._on_kind_change()
        if self._trig_slots and self._trig_slots[0]:
            self._select_image(self._trig_slots[0][0])

    def _load_post_fields(self, draft: O.Draft):
        self.caption_edit.set_state(draft.caption, getattr(draft, "body_blocks", ""))
        self.tags_edit.setText(draft.tags)
        self.date_edit.setText(draft.post_date)
        self.status_combo.setCurrentText(draft.img_status)
        self.comments_check.setChecked(draft.allow_comments)
        self.dl_check.setChecked(draft.allow_download)
        self.dl_url.setText(draft.download_url)

    # ======================================================================
    # Network
    # ======================================================================
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
        return GramPoster(conn), url

# ===== SNAPSMACK EOF =====
