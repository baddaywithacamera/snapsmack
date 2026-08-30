"""Found Textures browser dialog for the Qt editor.

Search foundtextures.ca, see a thumbnail grid (thumbnails fetched on a thread
pool with the Hub key), pick a fit + blend, and add a texture as a layer. The
network calls go through ``found_textures``; all compositing is local.
"""

import os

from PySide6.QtCore import Qt, QObject, QRunnable, QThreadPool, Signal, QSize
from PySide6.QtGui import QImage, QPixmap, QIcon
from PySide6.QtWidgets import (
    QDialog, QVBoxLayout, QHBoxLayout, QLineEdit, QPushButton, QListWidget,
    QListWidgetItem, QLabel, QComboBox, QMessageBox,
)

import found_textures
from . import theme
from .layers_panel import BLEND_MODES

try:
    import snap_log
    _log = snap_log.get("snap_slapper")
except Exception:  # noqa: BLE001
    import logging
    _log = logging.getLogger("snapsmack.snap_slapper")

FIT_MODES = ["cover", "contain", "stretch", "tile", "original"]
RIGHTS_FILTERS = [
    ("Clear rights", "clear"),
    ("All rights statuses", "all"),
    ("Unclear rights", "unclear"),
    ("Unknown rights", "unknown"),
]


class _ThumbSignals(QObject):
    ready = Signal(str, QImage)


class _ThumbTask(QRunnable):
    def __init__(self, url, key, signals):
        super().__init__()
        self.url = url
        self.key = key
        self.signals = signals

    def run(self):
        try:
            data = found_textures.fetch_bytes(self.url, self.key)
            image = QImage.fromData(data)
            if not image.isNull():
                self.signals.ready.emit(self.url, image)
        except Exception:  # noqa: BLE001
            _log.debug("texture thumb failed: %s", self.url, exc_info=True)


class TexturesDialog(QDialog):
    def __init__(self, host, site_url, api_key):
        super().__init__(host)
        self.host = host
        self.site_url = site_url
        self.api_key = api_key
        self._items = {}                     # thumb_url -> QListWidgetItem
        self._textures = {}                  # thumb_url -> texture dict
        self._pool = QThreadPool.globalInstance()
        self._signals = _ThumbSignals()
        self._signals.ready.connect(self._on_thumb)

        self.setWindowTitle("Found Textures")
        self.resize(760, 560)
        self.setStyleSheet(theme.stylesheet())

        layout = QVBoxLayout(self)
        layout.setContentsMargins(12, 12, 12, 12)
        layout.setSpacing(8)

        search_row = QHBoxLayout()
        self.search = QLineEdit()
        self.search.setPlaceholderText("Search textures (rust, paper, concrete…)")
        self.search.returnPressed.connect(self.run_search)
        search_row.addWidget(self.search, 1)
        go = QPushButton("Search")
        go.setObjectName("LayerAddBtn")
        go.clicked.connect(self.run_search)
        search_row.addWidget(go)
        self.rights = QComboBox()
        for label, value in RIGHTS_FILTERS:
            self.rights.addItem(label, value)
        self.rights.setToolTip(
            "Clear rights is the safe default. Unclear and unknown textures "
            "require confirmation before import.")
        self.rights.currentIndexChanged.connect(self.run_search)
        search_row.addWidget(self.rights)
        layout.addLayout(search_row)

        self.grid = QListWidget()
        self.grid.setViewMode(QListWidget.IconMode)
        self.grid.setResizeMode(QListWidget.Adjust)
        self.grid.setMovement(QListWidget.Static)
        self.grid.setIconSize(QSize(150, 150))
        self.grid.setSpacing(8)
        self.grid.itemDoubleClicked.connect(lambda _i: self.add_selected())
        layout.addWidget(self.grid, 1)

        controls = QHBoxLayout()
        controls.setSpacing(8)
        controls.addWidget(self._label("Fit"))
        self.fit = QComboBox()
        self.fit.addItems(["Cover (auto-fit to image)", "Contain", "Stretch",
                           "Tile", "Original size"])
        self.fit.setCurrentIndex(FIT_MODES.index("cover"))
        self.fit.setToolTip(
            "Cover automatically resizes the texture to fill the photograph, "
            "keeps its proportions, centres it, and crops only the overflow.")
        controls.addWidget(self.fit)
        controls.addWidget(self._label("Blend"))
        self.blend = QComboBox()
        self.blend.addItems([m.replace("_", " ").title() for m in BLEND_MODES])
        self.blend.setCurrentIndex(BLEND_MODES.index("overlay"))  # textures love overlay
        controls.addWidget(self.blend)
        controls.addStretch(1)
        self.status = QLabel("")
        self.status.setObjectName("TargetLabel")
        controls.addWidget(self.status)
        add = QPushButton("Add as Layer")
        add.setObjectName("LayerAddBtn")
        add.clicked.connect(self.add_selected)
        controls.addWidget(add)
        layout.addLayout(controls)

    def _label(self, text):
        label = QLabel(text)
        label.setObjectName("ControlName")
        return label

    # --- Search -------------------------------------------------------------
    def run_search(self):
        self.grid.clear()
        self._items.clear()
        self._textures.clear()
        self.status.setText("Searching…")
        try:
            textures, total = found_textures.search(
                self.site_url, self.api_key, query=self.search.text().strip(),
                rights=self.rights.currentData())
        except Exception as error:  # noqa: BLE001
            _log.exception("Found Textures search failed")
            QMessageBox.critical(self, "Search failed", str(error))
            self.status.setText("")
            return
        self.status.setText(f"{len(textures)} of {total}")
        for texture in textures:
            thumb = texture.get("thumb_url")
            if not thumb:
                continue
            status = texture.get("rights_status") or "unknown"
            badge = {"clear": "CLEAR RIGHTS", "unclear": "UNCLEAR RIGHTS"}.get(
                status, "RIGHTS UNKNOWN")
            item = QListWidgetItem(
                f"{texture.get('title', 'Texture')}\n[{badge}]")
            item.setToolTip(
                f"Rights: {badge}\nLicence: {texture.get('licence') or 'unknown'}")
            item.setData(Qt.UserRole, thumb)
            self.grid.addItem(item)
            self._items[thumb] = item
            self._textures[thumb] = texture
            self._pool.start(_ThumbTask(thumb, self.api_key, self._signals))

    def _on_thumb(self, url, image):
        item = self._items.get(url)
        if item is not None:
            item.setIcon(QIcon(QPixmap.fromImage(image)))

    # --- Add ----------------------------------------------------------------
    def add_selected(self):
        items = self.grid.selectedItems()
        if not items:
            return
        texture = self._textures.get(items[0].data(Qt.UserRole))
        if not texture:
            return
        rights = texture.get("rights_status") or "unknown"
        if rights != "clear":
            label = "UNCLEAR RIGHTS" if rights == "unclear" else "RIGHTS UNKNOWN"
            answer = QMessageBox.warning(
                self, "Confirm texture rights",
                f"This texture is marked {label}.\n\n"
                "Only use it if you have confirmed that your intended use is permitted.\n\n"
                "Add it as a layer anyway?",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
            if answer != QMessageBox.Yes:
                return
        try:
            path = found_textures.download(texture, self.api_key)
        except Exception as error:  # noqa: BLE001
            _log.exception("Found Textures download failed")
            QMessageBox.critical(self, "Download failed", str(error))
            return
        fit = FIT_MODES[self.fit.currentIndex()]
        blend = BLEND_MODES[self.blend.currentIndex()]
        self.host.add_texture_layer(
            path, found_textures.provenance(texture), fit=fit, blend=blend)
        self.accept()

# ===== SNAPSMACK EOF =====
