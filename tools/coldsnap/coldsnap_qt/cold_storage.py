"""COLD SNAP Qt — COLD STORAGE: this blog's images, offline.

The consumer face of the shared store (shared_library/<site>): a grid of every
image held locally for the connected site, a SYNC that pulls the rest of the
site's Gallery down (web-size files + metadata via sybu-images.php), AI assist
for titles/captions/ALT, and SAVE TO SITE to land edits back on the image's
snap_images row. Metadata edits are field-scoped — never the file, never
status, never deletion.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import threading

from PySide6.QtCore import QObject, QSize, Qt, Signal
from PySide6.QtGui import QIcon
from PySide6.QtWidgets import (
    QWidget, QHBoxLayout, QVBoxLayout, QLabel, QLineEdit, QPlainTextEdit,
    QComboBox, QPushButton, QListWidget, QListWidgetItem, QMessageBox,
    QSplitter, QStackedWidget,
)

import snap_home
import snap_library
from sumna_post import SumnaConnection, InsecureTransportError

from . import theme
from .widgets import Card, hint, field_label, big_button, load_pixmap

_COLOUR_LABELS = ["—", "Colour", "B&W"]
_COLOUR_TO_VAL = {"Colour": "color", "B&W": "bw"}
_VAL_TO_COLOUR = {"color": "Colour", "bw": "B&W"}


class _SyncBridge(QObject):
    progressed = Signal(str)
    finished = Signal(int, int)   # new, total on server
    failed = Signal(str)


class StorageMode(QWidget):
    def __init__(self, app_config_provider, parent=None):
        super().__init__(parent)
        self.app_config = app_config_provider
        self._assets = []
        self._sel = None

        outer = QHBoxLayout(self)
        outer.setContentsMargins(10, 10, 10, 10)
        outer.setSpacing(0)
        split = QSplitter(Qt.Horizontal)
        split.setChildrenCollapsible(False)
        outer.addWidget(split)

        # ── the grid ─────────────────────────────────────────────────────────
        left_panel = QWidget()
        left = QVBoxLayout(left_panel)
        left.setContentsMargins(0, 0, 0, 0)
        left.setSpacing(12)
        head = QHBoxLayout()
        self.count_lbl = QLabel("COLD STORAGE")
        self.count_lbl.setObjectName("CardTitle")
        head.addWidget(self.count_lbl)
        self.status_lbl = hint("")
        head.addWidget(self.status_lbl, 1)
        refresh_btn = QPushButton("Refresh")
        refresh_btn.setObjectName("Quiet")
        refresh_btn.clicked.connect(self.reload)
        head.addWidget(refresh_btn)
        self.sync_btn = QPushButton("⇣ SYNC FROM SITE")
        self.sync_btn.setToolTip("Pulls the site's Gallery into the offline store — "
                                 "web-size files + titles/captions/ALT/colour. "
                                 "Skips what's already here.")
        self.sync_btn.clicked.connect(self._sync)
        head.addWidget(self.sync_btn)
        left.addLayout(head)

        self.grid = QListWidget()
        self.grid.setViewMode(QListWidget.IconMode)
        self.grid.setIconSize(QSize(96, 96))
        self.grid.setResizeMode(QListWidget.Adjust)
        self.grid.setUniformItemSizes(True)
        self.grid.setWordWrap(True)
        self.grid.currentItemChanged.connect(self._on_pick)
        self.grid_stack = QStackedWidget()
        self.grid_stack.addWidget(self.grid)
        empty = QWidget()
        empty_col = QVBoxLayout(empty)
        empty_col.setContentsMargins(40, 40, 40, 40)
        empty_col.addStretch(2)
        empty_title = QLabel("YOUR OFFLINE SHELF IS EMPTY")
        empty_title.setObjectName("EmptyTitle")
        empty_title.setAlignment(Qt.AlignCenter)
        empty_col.addWidget(empty_title)
        empty_body = QLabel(
            "Pull this site's photographs down once, then browse and work with them offline.")
        empty_body.setObjectName("EmptyBody")
        empty_body.setAlignment(Qt.AlignCenter)
        empty_body.setWordWrap(True)
        empty_col.addWidget(empty_body)
        empty_sync = big_button("SYNC PHOTOGRAPHS FROM SITE")
        empty_sync.setMaximumWidth(320)
        empty_sync.clicked.connect(self._sync)
        empty_col.addWidget(empty_sync, 0, Qt.AlignHCenter)
        empty_col.addStretch(3)
        self.grid_stack.addWidget(empty)
        left.addWidget(self.grid_stack, 1)
        split.addWidget(left_panel)

        # ── the detail panel ────────────────────────────────────────────────
        card = Card("SELECTED IMAGE")
        self.detail = card
        card.setEnabled(False)
        card.setToolTip("Click an image in the grid.")

        self.preview = QLabel()
        self.preview.setFixedSize(180, 180)
        self.preview.setAlignment(Qt.AlignCenter)
        self.preview.setStyleSheet(f"background: {theme.CANVAS}; border-radius: 6px;")
        card.body.addWidget(self.preview, 0, Qt.AlignHCenter)

        self.info_lbl = hint("")
        card.body.addWidget(self.info_lbl)

        card.body.addWidget(field_label("Title"))
        self.title_edit = QLineEdit()
        card.body.addWidget(self.title_edit)
        card.body.addWidget(field_label("Caption / description"))
        self.desc_edit = QPlainTextEdit()
        self.desc_edit.setFixedHeight(72)
        card.body.addWidget(self.desc_edit)
        card.body.addWidget(field_label("ALT text — one plain sentence for screen readers"))
        self.alt_edit = QPlainTextEdit()
        self.alt_edit.setFixedHeight(56)
        card.body.addWidget(self.alt_edit)
        colour_row = QHBoxLayout()
        colour_row.addWidget(field_label("Colour / B&W"))
        self.colour_combo = QComboBox()
        self.colour_combo.addItems(_COLOUR_LABELS)
        self.colour_combo.setToolTip("A search/filter tag — never changes how the photo looks.")
        colour_row.addWidget(self.colour_combo)
        colour_row.addStretch(1)
        card.body.addLayout(colour_row)

        ai_row = QHBoxLayout()
        ai_btn = QPushButton("✨ AI Fill (Gemini)")
        ai_btn.setToolTip("Suggests a title, caption and ALT for this image — "
                          "review, then SAVE TO SITE.")
        ai_btn.clicked.connect(self._ai_fill)
        ai_row.addWidget(ai_btn)
        self.ai_status = hint("")
        ai_row.addWidget(self.ai_status, 1)
        card.body.addLayout(ai_row)

        self.save_btn = big_button("SAVE TO SITE")
        self.save_btn.setToolTip("Updates this image's title / caption / ALT / colour "
                                 "on the live site — metadata only, never the photo.")
        self.save_btn.clicked.connect(self._save)
        card.body.addWidget(self.save_btn)
        self.save_status = hint("")
        card.body.addWidget(self.save_status)
        card.body.addStretch(1)
        card.setMinimumWidth(390)
        card.setMaximumWidth(560)

        detail_stack = QStackedWidget()
        detail_stack.setMinimumWidth(390)
        detail_stack.setMaximumWidth(560)
        detail_stack.addWidget(card)
        waiting = Card("PHOTO DETAILS")
        waiting.body.addStretch(2)
        waiting_title = QLabel("SELECT A PHOTO")
        waiting_title.setObjectName("EmptyTitle")
        waiting_title.setAlignment(Qt.AlignCenter)
        waiting.body.addWidget(waiting_title)
        waiting_body = QLabel("Its preview, description and site metadata will appear here.")
        waiting_body.setObjectName("EmptyBody")
        waiting_body.setAlignment(Qt.AlignCenter)
        waiting_body.setWordWrap(True)
        waiting.body.addWidget(waiting_body)
        waiting.body.addStretch(3)
        detail_stack.addWidget(waiting)
        self.detail_stack = detail_stack
        split.addWidget(detail_stack)
        split.setStretchFactor(0, 5)
        split.setStretchFactor(1, 3)
        split.setSizes([900, 500])

        self._sync_bridge = _SyncBridge()
        self._sync_bridge.progressed.connect(self.status_lbl.setText)
        self._sync_bridge.finished.connect(self._sync_done)
        self._sync_bridge.failed.connect(self._sync_failed)

        self.reload()

    # ── grid ────────────────────────────────────────────────────────────────
    def _site(self) -> str:
        return ((self.app_config() or {}).get("url") or "").strip()

    def reload(self):
        site = self._site()
        self.grid.clear()
        self._assets = snap_library.all_assets(site) if site else []
        for a in self._assets:
            label = a.get("title") or a.get("orig_name") or a.get("asset_id", "")[:12]
            item = QListWidgetItem(label)
            media = snap_library.asset_file(site, a["asset_id"])
            pm = load_pixmap(media, 96) if media else None
            if pm:
                item.setIcon(QIcon(pm))
            item.setData(Qt.UserRole, a["asset_id"])
            self.grid.addItem(item)
        n = len(self._assets)
        self.count_lbl.setText(f"COLD STORAGE — {n} image{'' if n == 1 else 's'} offline")
        if not n:
            self.status_lbl.setText("Nothing stored yet — SYNC FROM SITE, or post something.")
            self.grid_stack.setCurrentIndex(1)
        else:
            self.grid_stack.setCurrentIndex(0)
        self._sel = None
        self.detail.setEnabled(False)
        self.detail_stack.setCurrentIndex(1)

    def _on_pick(self, item, _prev=None):
        if item is None:
            return
        aid = item.data(Qt.UserRole)
        a = next((x for x in self._assets if x["asset_id"] == aid), None)
        if a is None:
            return
        self._sel = a
        self.detail.setEnabled(True)
        self.detail_stack.setCurrentIndex(0)
        site = self._site()
        media = snap_library.asset_file(site, aid)
        pm = load_pixmap(media, 180) if media else None
        self.preview.setPixmap(pm) if pm else self.preview.clear()
        used = int(a.get("used_in", 0) or 0)
        bits = []
        if a.get("width") and a.get("height"):
            bits.append(f"{a['width']}×{a['height']}")
        if a.get("status"):
            bits.append(a["status"])
        if a.get("source_ref", "").startswith("img:"):
            bits.append(f"site id {a['source_ref'][4:]}")
        bits.append(f"in {used} post{'' if used == 1 else 's'} here")
        self.info_lbl.setText(" · ".join(bits))
        self.title_edit.setText(a.get("title", "") or "")
        self.desc_edit.setPlainText(a.get("description", "") or "")
        self.alt_edit.setPlainText(a.get("alt", "") or "")
        self.colour_combo.setCurrentText(_VAL_TO_COLOUR.get(a.get("color_mode", ""), "—"))
        self.save_btn.setEnabled(a.get("source_ref", "").startswith("img:"))
        self.save_status.setText("" if self.save_btn.isEnabled() else
                                 "This image has no site id yet — SYNC FROM SITE to link it.")
        self.ai_status.setText("")

    # ── sync down ───────────────────────────────────────────────────────────
    def _conn(self):
        cfg = self.app_config() or {}
        url = (cfg.get("url") or "").strip()
        key = (cfg.get("api_key") or "").strip()
        if not url or not key:
            QMessageBox.warning(self, "No site", "Pick a site at the top first.")
            return None
        try:
            return SumnaConnection(url, key)
        except InsecureTransportError as e:
            QMessageBox.warning(self, "Insecure connection", str(e))
            return None

    def _sync(self):
        conn = self._conn()
        if conn is None:
            return
        self.sync_btn.setEnabled(False)
        bridge, site = self._sync_bridge, conn.base_url

        def worker():
            try:
                have = snap_library.asset_source_refs(site)
                new = 0
                total = 0
                page = 1
                while True:
                    r = conn.session.get(f"{site}/sybu-images.php",
                                         params={"page": page, "per": 100}, timeout=60)
                    if r.status_code in (401, 403):
                        bridge.failed.emit("The site refused the key (needs 0.7.651+ "
                                           "and a 'sybu' API key).")
                        return
                    if r.status_code == 404:
                        bridge.failed.emit("This site doesn't have the image endpoint "
                                           "yet — update it to 0.7.651+.")
                        return
                    r.raise_for_status()
                    data = r.json()
                    total = int(data.get("total", 0))
                    rows = data.get("images") or []
                    if not rows:
                        break
                    for row in rows:
                        ref = "img:%d" % int(row["id"])
                        if ref in have:
                            continue
                        f = conn.session.get(f"{site}/{row['file'].lstrip('/')}",
                                             timeout=120)
                        if f.status_code != 200 or not f.content:
                            continue
                        entry = snap_library.store_media(
                            site, f.content,
                            orig_name=os.path.basename(row["file"]),
                            ext=os.path.splitext(row["file"])[1])
                        entry.update({
                            "alt": row.get("alt", ""), "source_ref": ref,
                            "width": row.get("width", 0), "height": row.get("height", 0),
                            "title": row.get("title", ""),
                            "description": row.get("description", ""),
                            "color_mode": row.get("color_mode", ""),
                            "status": row.get("status", ""),
                            "img_date": row.get("img_date", ""),
                        })
                        snap_library.upsert_asset(site, entry)
                        have.add(ref)
                        new += 1
                        bridge.progressed.emit(f"Syncing… {new} pulled")
                    if page * int(data.get("per", 100)) >= total:
                        break
                    page += 1
                bridge.finished.emit(new, total)
            except Exception as e:  # noqa: BLE001 — every sync error shown plainly
                bridge.failed.emit(str(e))

        threading.Thread(target=worker, daemon=True).start()

    def _sync_done(self, new: int, total: int):
        self.sync_btn.setEnabled(True)
        self.status_lbl.setStyleSheet(f"color: {theme.OK};")
        self.status_lbl.setText(f"Synced — {new} new, site holds {total}.")
        self.reload()

    def _sync_failed(self, msg: str):
        self.sync_btn.setEnabled(True)
        self.status_lbl.setStyleSheet(f"color: {theme.DANGER};")
        self.status_lbl.setText(msg)

    # ── AI fill ─────────────────────────────────────────────────────────────
    def _ai_fill(self):
        if self._sel is None:
            return
        media = snap_library.asset_file(self._site(), self._sel["asset_id"])
        if not media:
            QMessageBox.warning(self, "No local file",
                                "This image's file isn't in the store — SYNC FROM SITE first.")
            return
        from .enrich_worker import EnrichWorker
        self._ai_worker = EnrichWorker()

        def _done(_idx, meta):
            if meta.get("title") and not self.title_edit.text().strip():
                self.title_edit.setText(meta["title"])
            if meta.get("caption"):
                self.desc_edit.setPlainText(meta["caption"])
            if meta.get("alt"):
                self.alt_edit.setPlainText(meta["alt"])
            self.ai_status.setText("Filled ✓ — make it yours, then SAVE TO SITE")

        self._ai_worker.image_done.connect(_done)
        self._ai_worker.failed.connect(
            lambda msg: (self.ai_status.setText(""),
                         QMessageBox.critical(self, "AI Fill failed", msg)))
        self.ai_status.setText("Thinking… (Gemini)")
        self._ai_worker.start([media])

    # ── save back to the site ───────────────────────────────────────────────
    def _save(self):
        a = self._sel
        if a is None or not a.get("source_ref", "").startswith("img:"):
            return
        conn = self._conn()
        if conn is None:
            return
        img_id = int(a["source_ref"][4:])
        fields = {
            "id": str(img_id),
            "title": self.title_edit.text().strip(),
            "description": self.desc_edit.toPlainText().strip(),
            "alt": self.alt_edit.toPlainText().strip(),
            "color_mode": _COLOUR_TO_VAL.get(self.colour_combo.currentText(), ""),
        }
        self.save_btn.setEnabled(False)
        self.save_status.setText("Saving…")
        self.save_status.setStyleSheet(f"color: {theme.WARN};")
        bridge = _SyncBridge()
        self._save_bridge = bridge

        def worker():
            try:
                r = conn.session.post(f"{conn.base_url}/sybu-images.php",
                                      data=fields, timeout=60)
                data = r.json() if r.headers.get("Content-Type", "").startswith("application/json") else {}
                if r.status_code == 200 and data.get("ok"):
                    bridge.finished.emit(1, 1)
                else:
                    bridge.failed.emit(data.get("error") or f"server said {r.status_code}")
            except Exception as e:  # noqa: BLE001
                bridge.failed.emit(str(e))

        def done(*_):
            self.save_btn.setEnabled(True)
            self.save_status.setStyleSheet(f"color: {theme.OK};")
            self.save_status.setText("Saved to the site ✓")
            a.update({"title": fields["title"], "description": fields["description"],
                      "alt": fields["alt"], "color_mode": fields["color_mode"]})
            snap_library.upsert_asset(self._site(), a)
            row = self.grid.currentItem()
            if row is not None:
                row.setText(fields["title"] or a.get("orig_name") or "")

        def fail(msg):
            self.save_btn.setEnabled(True)
            self.save_status.setStyleSheet(f"color: {theme.DANGER};")
            self.save_status.setText(f"Save failed: {msg}")

        bridge.finished.connect(done)
        bridge.failed.connect(fail)
        threading.Thread(target=worker, daemon=True).start()

# ===== SNAPSMACK EOF =====
