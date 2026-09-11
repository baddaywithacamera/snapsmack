"""COLD SNAP Qt — pick an image from the site's MEDIA GALLERY, by looking at it.

In a post, [img:ID] points at the site's Media Gallery (its post images).
COLD STORAGE already syncs that gallery to the shared library with thumbnails,
so the picker is a grid of pictures that works offline. Sean, 2026-09-10:
"Wow. Really. Not an image picker?" — this is the image picker.

Anything the cache doesn't know yet (not synced, or a Library image on a
static page) can still be typed as an ID in the small box at the bottom.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from PySide6.QtCore import Qt, QSize
from PySide6.QtGui import QIcon
from PySide6.QtWidgets import (
    QDialog, QVBoxLayout, QHBoxLayout, QLabel, QLineEdit, QComboBox,
    QListWidget, QListWidgetItem, QDialogButtonBox, QPushButton,
)

import snap_library

from . import biggie
from .widgets import hint, field_label, load_pixmap


def gallery_images(site: str) -> list:
    """The cached gallery images that carry a site id, newest first:
    [{'img_id': '42', 'title': ..., 'path': local file or '', 'asset_id': ...}]."""
    if not site:
        return []
    try:
        assets = snap_library.all_assets(site)
    except Exception:  # noqa: BLE001 — no cache is not an error
        return []
    out = []
    for a in assets:
        ref = str(a.get("source_ref", "") or "")
        if not ref.startswith("img:") or not ref[4:].strip().isdigit():
            continue
        try:
            path = snap_library.asset_file(site, a["asset_id"]) or ""
        except Exception:  # noqa: BLE001
            path = ""
        out.append({"img_id": ref[4:].strip(),
                    "title": (a.get("title") or a.get("orig_name") or "").strip(),
                    "path": path, "asset_id": a.get("asset_id", "")})
    return out


def gallery_thumb_path(site: str, img_id: str) -> str:
    """Local file for a site image id, or '' — lets the canvas paint the real picture."""
    want = str(img_id).strip()
    for im in gallery_images(site):
        if im["img_id"] == want:
            return im["path"]
    return ""


class GalleryPicker(QDialog):
    """Grid of the site's gallery pictures; click one, choose size and alignment."""

    def __init__(self, parent, site: str, data: dict | None = None):
        super().__init__(parent)
        data = data or {}
        self.setWindowTitle("Image from this site's Media Gallery")
        self.resize(760, 560)
        self._images = gallery_images(site)
        col = QVBoxLayout(self)

        top = QHBoxLayout()
        self.search = QLineEdit()
        self.search.setPlaceholderText("Find by title or file name…")
        self.search.textChanged.connect(self._fill)
        top.addWidget(self.search, 1)
        col.addLayout(top)

        self.grid = QListWidget()
        self.grid.setViewMode(QListWidget.IconMode)
        self.grid.setIconSize(QSize(120, 120))
        self.grid.setResizeMode(QListWidget.Adjust)
        self.grid.setMovement(QListWidget.Static)
        self.grid.setSpacing(8)
        self.grid.setWordWrap(True)
        self.grid.itemDoubleClicked.connect(lambda _i: self.accept())
        self.grid.currentItemChanged.connect(self._picked)
        col.addWidget(self.grid, 1)
        if not self._images:
            col.addWidget(hint("Nothing cached for this site yet. Open COLD STORAGE and "
                               "SYNC FROM SITE, then this becomes a grid of your pictures. "
                               "You can still type an image id below."))

        row = QHBoxLayout()
        row.addWidget(field_label("Size"))
        self.size = QComboBox()
        self.size.addItems(list(biggie.IMG_SIZES))
        self.size.setCurrentText(data.get("size") or "full")
        row.addWidget(self.size)
        row.addWidget(field_label("Align"))
        self.align = QComboBox()
        self.align.addItems(list(biggie.IMG_ALIGNS))
        self.align.setCurrentText(data.get("align") or "center")
        row.addWidget(self.align)
        row.addStretch(1)
        row.addWidget(field_label("or image id"))
        self.img_id = QLineEdit(str(data.get("img_id", "")))
        self.img_id.setPlaceholderText("e.g. 42")
        self.img_id.setFixedWidth(90)
        row.addWidget(self.img_id)
        col.addLayout(row)

        buttons = QDialogButtonBox(QDialogButtonBox.Cancel | QDialogButtonBox.Ok)
        buttons.accepted.connect(self.accept)
        buttons.rejected.connect(self.reject)
        col.addWidget(buttons)
        self._fill()
        # editing an existing IMG: land on it
        current = str(data.get("img_id", "")).strip()
        if current:
            for i in range(self.grid.count()):
                if self.grid.item(i).data(Qt.UserRole) == current:
                    self.grid.setCurrentRow(i)
                    break

    def _fill(self):
        needle = self.search.text().strip().lower()
        self.grid.clear()
        for im in self._images:
            label = im["title"] or f"image {im['img_id']}"
            if needle and needle not in label.lower():
                continue
            item = QListWidgetItem(label)
            pm = load_pixmap(im["path"], 120) if im["path"] else None
            if pm:
                item.setIcon(QIcon(pm))
            item.setData(Qt.UserRole, im["img_id"])
            item.setToolTip(f"site image {im['img_id']}")
            self.grid.addItem(item)

    def _picked(self, item, _prev=None):
        if item is not None:
            self.img_id.setText(str(item.data(Qt.UserRole)))

    def values(self) -> dict:
        return {"img_id": self.img_id.text().strip(), "size": self.size.currentText(),
                "align": self.align.currentText()}

# ===== SNAPSMACK EOF =====
