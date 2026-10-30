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
from .photo_loader import ListPhotos

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

        # SEARCH AND SORT. Sean, on a 3423-image shelf: "need to be able to
        # sort by site and date range. search by name."
        #
        # Name search and name/usage/insertion sort are here. DATE RANGE IS NOT,
        # deliberately: img_date is empty on every one of the 3480 rows across
        # all four catalogues on this machine, so a date control would filter
        # against nothing and look broken through no fault of its own. The cause
        # is recorded separately -- the rows were written by the upload path,
        # which never set the field, and the sync that would backfill it has
        # been failing. THE SITE PICKER IS NOW HERE, and the reasoning that
        # once kept it out was half right: a site is a whole catalogue file and
        # not a column, so there is nothing to SORT by -- but there is
        # something to CHOOSE. He said this panel was unusable without it.
        # Date range still waits on img_date being populated.
        find_row = QHBoxLayout()
        # SITE FIRST. He picks the shelf, then narrows it. The count beside
        # each name is there so he can see where the photographs are before
        # clicking: on his own machine one site holds 3,642 of 4,629.
        find_row.addWidget(QLabel("Site"))
        self.site_combo = QComboBox()
        self.site_combo.setMinimumWidth(240)
        self.site_combo.setToolTip(
            "Which blog's offline shelf to show. Each site is its own store on "
            "this machine, so this reads a different catalogue — it does not "
            "call the site.")
        self.site_combo.currentIndexChanged.connect(self._on_site_changed)
        find_row.addWidget(self.site_combo)
        self.find_edit = QLineEdit()
        self.find_edit.setPlaceholderText("Search by name — title or original filename")
        self.find_edit.setClearButtonEnabled(True)
        self.find_edit.setToolTip(
            "Matches anywhere in the title OR the original filename, ignoring case. "
            "Filters what is already on this machine; it does not call the site.")
        self.find_edit.textChanged.connect(self._populate)
        find_row.addWidget(self.find_edit, 1)
        find_row.addWidget(QLabel("Sort"))
        self.sort_combo = QComboBox()
        # (label, key) — the label is what he reads, the key is what sorts.
        for _label, _key in (("Name", "name"),
                             ("Most used", "used"),
                             ("Newest added", "added")):
            self.sort_combo.addItem(_label, _key)
        self.sort_combo.setToolTip(
            "Name sorts by title, or by original filename when a photograph has no "
            "title. Newest added is the order they arrived on this machine.")
        self.sort_combo.currentIndexChanged.connect(self._populate)
        find_row.addWidget(self.sort_combo)
        left.addLayout(find_row)

        self.grid = QListWidget()
        self.grid.setViewMode(QListWidget.IconMode)
        self.grid.setIconSize(QSize(96, 96))
        self.grid.setResizeMode(QListWidget.Adjust)
        self.grid.setUniformItemSizes(True)
        self.grid.setWordWrap(True)
        self.grid.currentItemChanged.connect(self._on_pick)
        # PER ROW, NOT PER VIEW. In an ALL SITES view two rows can belong to
        # different sites, and a thumbnail lives under shared_library/<site>/.
        self._photos = ListPhotos(self.grid, lambda item: snap_library.asset_file(
            self._row_site(item), item.data(Qt.UserRole)) or "")
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

    # ── which site's shelf ──────────────────────────────────────────────────
    #
    # THE SITE IS NOT A COLUMN. There is no site field on an asset row; the
    # site is WHICH CATALOGUE THE ROW LIVES IN -- shared_library/<host>/db/
    # catalog.sqlite, one per host. So choosing a site is choosing a file to
    # read, which needs no schema change and no migration.
    #
    # The owner, 30 October 2026: COLD STORAGE "is unusable without" this. He
    # could not show one site's images. The panel had always read whichever
    # site was connected, so seeing another site's shelf meant reconnecting.
    ALL_SITES = "\x00all"

    @staticmethod
    def site_catalogues() -> list:
        """Every site with a catalogue on this machine, biggest shelf first.

        A SITE IS A DIRECTORY THAT HAS A CATALOGUE, not just a directory.
        shared_library/ also holds auth/ and assets/, which are not sites; a
        picker built from a directory listing would offer two that cannot be
        opened.

        An empty catalogue is still listed, with 0, so "this site has no
        images yet" can be told apart from "this site is not here".
        """
        root = snap_home.shared_library()
        out = []
        try:
            names = sorted(os.listdir(root))
        except OSError:
            return out
        for name in names:
            db = os.path.join(root, name, "db", "catalog.sqlite")
            if not os.path.isfile(db):
                continue
            try:
                rows = len(snap_library.all_assets(name))
            except Exception:
                # A catalogue that cannot be read is still a site, and saying
                # so beats hiding it: he can see it and ask why it is empty.
                rows = 0
            out.append({"site": name, "rows": rows})
        out.sort(key=lambda c: (-c["rows"], c["site"]))
        return out

    @classmethod
    def assets_for(cls, site: str) -> list:
        """The rows to show, each tagged with the site it came from.

        THE TAG IS NOT DECORATION. A thumbnail and a media file both resolve
        under shared_library/<site>/, so a view holding more than one site's
        rows cannot find a single file without knowing each row's site.
        """
        if site == cls.ALL_SITES:
            out = []
            for cat in cls.site_catalogues():
                out.extend(cls.assets_for(cat["site"]))
            return out
        if not site:
            return []
        try:
            key = snap_home.site_key(site)
        except ValueError:
            return []
        rows = snap_library.all_assets(key)
        for r in rows:
            r["site"] = key
        return rows

    # ── grid ────────────────────────────────────────────────────────────────
    def _site(self) -> str:
        """The site whose shelf is on screen: the picker's choice, falling
        back to the connected site before the picker has been filled."""
        chosen = self.site_combo.currentData() if hasattr(self, "site_combo") else None
        if chosen:
            return chosen
        return self._connected_site()

    def _connected_site(self) -> str:
        return ((self.app_config() or {}).get("url") or "").strip()

    def _asset_site(self, asset: dict) -> str:
        """The site to resolve one row's files against.

        Tagged rows carry it, and every row from assets_for() is tagged. The
        fallback is the CONNECTED site, deliberately, not the picker's choice:
        with the picker on All, the choice is a sentinel and not a host, so
        falling back to it would hand a sentinel to a file path. An untagged
        row can only come from an older code path, and that path only ever
        held the connected site's rows.
        """
        return (asset or {}).get("site") or self._connected_site()

    def _row_site(self, item) -> str:
        """The site of the grid row an item stands for.

        No item means no row to resolve, so the connected site is the only
        honest answer -- and, as in _asset_site, never the picker's choice,
        which may be the All sentinel rather than a host.
        """
        if item is None:
            return self._connected_site()
        aid = item.data(Qt.UserRole)
        return self._asset_site(
            next((a for a in self._assets if a["asset_id"] == aid), None))

    def _refresh_sites(self):
        """Fill the picker from the catalogues on disk, keeping the choice.

        DEFAULT IS ALL SITES. Every shelf on the machine, in one grid, with
        each row carrying its own site so its files still resolve.

        I argued for defaulting to the connected site instead -- All means
        reading every catalogue, which on the owner's machine is 4,629 rows
        against the 3,642 of the one site he called unusable, so it opens
        bigger than what he complained about. That was considered and
        overruled, and All is what the panel does. Recorded here because the
        reasoning is worth having if it ever comes up again, not as an
        objection still standing.

        The choice is restored across reloads, so the default applies on first
        open and never fights a choice he has already made.
        """
        combo = self.site_combo
        was = combo.currentData()
        cats = self.site_catalogues()
        combo.blockSignals(True)
        combo.clear()
        total = 0
        for cat in cats:
            total += cat["rows"]
            combo.addItem(f"{cat['site']}  ({cat['rows']})", cat["site"])
        if len(cats) > 1:
            combo.addItem(f"All sites  ({total})", self.ALL_SITES)
        # Restore the previous choice; on first open, All. With only one
        # catalogue there is no All entry, so findData misses and index 0 --
        # that single site -- is both the fallback and the right answer.
        want = was if was is not None else self.ALL_SITES
        at = combo.findData(want)
        combo.setCurrentIndex(at if at >= 0 else 0)
        combo.blockSignals(False)
        combo.setEnabled(combo.count() > 1)

    @staticmethod
    def asset_label(asset: dict) -> str:
        """What the operator reads under a tile, and what Name sorts on.

        Title first, then the original filename. The filename matters as much
        as the title here: an image recorded by the upload path carries no
        title at all, and the filename is the only thing he could type.
        """
        return (asset.get("title") or asset.get("orig_name")
                or str(asset.get("asset_id", ""))[:12])

    @staticmethod
    def filter_assets(assets: list, needle: str) -> list:
        """Assets whose title OR original filename contains `needle`.

        Case-insensitive substring, over the list already in memory. reload()
        holds every row, so searching never re-queries and never calls the
        site. An empty needle returns everything rather than nothing.
        """
        needle = (needle or "").strip().lower()
        if not needle:
            return list(assets)
        return [a for a in assets
                if needle in str(a.get("title") or "").lower()
                or needle in str(a.get("orig_name") or "").lower()]

    @classmethod
    def sort_assets(cls, assets: list, key: str) -> list:
        """Sorted for display. `assets` arrives newest-added first.

        all_assets() returns ORDER BY rowid DESC, so 'added' is the order it
        already has and is left alone -- sorting it again would only risk
        changing it.
        """
        if key == "name":
            return sorted(assets, key=lambda a: cls.asset_label(a).lower())
        if key == "used":
            return sorted(assets, key=lambda a: (-int(a.get("used_in") or 0),
                                                 cls.asset_label(a).lower()))
        return list(assets)

    def _populate(self, *_):
        """Fill the grid from what is already loaded. No query, no site call."""
        shown = self.sort_assets(
            self.filter_assets(self._assets, self.find_edit.text()),
            self.sort_combo.currentData() or "name")

        self._photos.reset()
        self.grid.clear()
        for a in shown:
            item = QListWidgetItem(self.asset_label(a))
            item.setData(Qt.UserRole, a["asset_id"])
            self.grid.addItem(item)

        total = len(self._assets)
        # SAY WHICH SHELF. With a picker above the grid, a count on its own
        # cannot tell him whether he is looking at one site or all of them.
        where = self.site_combo.currentData() if hasattr(self, "site_combo") else None
        whose = "all sites" if where == self.ALL_SITES else (where or "")
        tail = f" — {whose}" if whose else ""
        if len(shown) == total:
            self.count_lbl.setText(
                f"COLD STORAGE — {total} image{'' if total == 1 else 's'} offline{tail}")
        else:
            # Say the total as well, so a filtered shelf can never be mistaken
            # for a shelf that lost photographs.
            self.count_lbl.setText(
                f"COLD STORAGE — {len(shown)} of {total} images shown{tail}")
        if total and not shown:
            self.status_lbl.setText("No photograph here matches that name.")

        if not total:
            self.status_lbl.setText("Nothing stored yet — SYNC FROM SITE, or post something.")
            self.grid_stack.setCurrentIndex(1)
        else:
            self.grid_stack.setCurrentIndex(0)
        self._sel = None
        self.detail.setEnabled(False)
        self.detail_stack.setCurrentIndex(1)

    def _on_site_changed(self, *_):
        """He chose a different shelf: read it, do not re-enumerate the disk.

        Separate from reload() on purpose. reload() refills the picker, and
        refilling it while responding to its own signal is how a combo ends up
        fighting the person using it.
        """
        self._assets = self.assets_for(self._site())
        self._populate()

    def reload(self):
        """Re-read the chosen shelf, then show it through the current filter."""
        self._refresh_sites()
        self._assets = self.assets_for(self._site())
        self._populate()

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
        site = self._asset_site(a)
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
                    if not r.ok:
                        try:
                            reason = r.json().get("error", "")
                        except (ValueError, AttributeError):
                            reason = ""
                        bridge.failed.emit(f"Site sync refused ({r.status_code}): "
                                           + (str(reason)[:500] or "The site could not return its image library."))
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
        media = snap_library.asset_file(self._asset_site(self._sel), self._sel["asset_id"])
        if not media:
            QMessageBox.warning(self, "No local file",
                                "This image's file isn't in the store — SYNC FROM SITE first.")
            return
        from .enrich_worker import EnrichWorker
        self._ai_worker = EnrichWorker(self.app_config() or {})

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
            snap_library.upsert_asset(self._asset_site(a), a)
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
