"""Built-in LEWKS gallery for the Qt editor.

Shows every built-in look as a live preview *on the current photograph* at an
adjustable strength, and applies the chosen one as a non-destructive layer that
does not flatten existing edits. Previews mirror a normal-blend adjustment layer
at the strength's opacity, so what you see is what you get.
"""

from PySide6.QtCore import Qt, QSize
from PySide6.QtGui import QIcon
from PySide6.QtWidgets import (
    QDialog, QVBoxLayout, QHBoxLayout, QLabel, QSlider, QPushButton,
    QListWidget, QListWidgetItem,
)

from PIL import Image

import editor_engine
import built_in_lewks
from . import theme
from .engine_bridge import pil_to_qpixmap

try:
    import snap_log
    _log = snap_log.get("snap_slapper")
except Exception:  # noqa: BLE001
    import logging
    _log = logging.getLogger("snapsmack.snap_slapper")

PREVIEW = 150


class LewksDialog(QDialog):
    def __init__(self, host):
        super().__init__(host)
        self.host = host
        self.setWindowTitle("LEWKS")
        self.resize(720, 620)
        self.setStyleSheet(theme.stylesheet())
        self._lewks = built_in_lewks.all_lewks()
        self._base = None                      # small base render of the photo

        layout = QVBoxLayout(self)
        layout.setContentsMargins(12, 12, 12, 12)
        layout.setSpacing(8)

        strength_row = QHBoxLayout()
        label = QLabel("Strength")
        label.setObjectName("ControlName")
        strength_row.addWidget(label)
        self.strength = QSlider(Qt.Horizontal)
        self.strength.setRange(0, 100)
        self.strength.setValue(100)
        self.strength.sliderReleased.connect(self._refresh_previews)
        strength_row.addWidget(self.strength, 1)
        self.strength_value = QLabel("100")
        self.strength_value.setObjectName("ControlValue")
        self.strength.valueChanged.connect(lambda v: self.strength_value.setText(str(v)))
        strength_row.addWidget(self.strength_value)
        layout.addLayout(strength_row)

        self.grid = QListWidget()
        self.grid.setViewMode(QListWidget.IconMode)
        self.grid.setResizeMode(QListWidget.Adjust)
        self.grid.setMovement(QListWidget.Static)
        self.grid.setIconSize(QSize(PREVIEW, PREVIEW))
        self.grid.setSpacing(10)
        self.grid.setWordWrap(True)
        self.grid.itemDoubleClicked.connect(lambda _i: self.apply_selected())
        layout.addWidget(self.grid, 1)

        actions = QHBoxLayout()
        self.caption = QLabel("Pick a look — the previews are on your photo.")
        self.caption.setObjectName("TargetLabel")
        actions.addWidget(self.caption, 1)
        teach_btn = QPushButton("TEACH ME")
        teach_btn.clicked.connect(self.teach_selected)
        actions.addWidget(teach_btn)
        apply_btn = QPushButton("Apply LEWK")
        apply_btn.setObjectName("LayerAddBtn")
        apply_btn.clicked.connect(self.apply_selected)
        actions.addWidget(apply_btn)
        layout.addLayout(actions)

        self._build_items()
        self._refresh_previews()

    def _build_items(self):
        for lewk in sorted(self._lewks, key=lambda x: (x.get("category", ""), x["name"])):
            item = QListWidgetItem(lewk["name"])
            item.setData(Qt.UserRole, lewk["id"])
            item.setToolTip(f"{lewk.get('category', '')} — {lewk.get('description', '')}")
            self.grid.addItem(item)

    def _base_image(self):
        if self._base is None:
            rendered = self.host.render_preview_image((PREVIEW, PREVIEW))
            self._base = rendered.convert("RGB") if rendered else None
        return self._base

    def _preview_for(self, lewk, strength):
        base = self._base_image()
        if base is None:
            return None
        values = dict(editor_engine.DEFAULT_ADJUSTMENTS)
        values.update(lewk.get("adjustments", {}))
        adjusted = editor_engine.apply_adjustments(base, values).convert("RGB")
        amount = max(0.0, min(1.0, strength / 100.0))
        return Image.blend(base, adjusted, amount)

    def _refresh_previews(self):
        strength = self.strength.value()
        lewk_by_id = {x["id"]: x for x in self._lewks}
        for row in range(self.grid.count()):
            item = self.grid.item(row)
            lewk = lewk_by_id.get(item.data(Qt.UserRole))
            if not lewk:
                continue
            try:
                preview = self._preview_for(lewk, strength)
                if preview is not None:
                    item.setIcon(QIcon(pil_to_qpixmap(preview)))
            except Exception:  # noqa: BLE001
                _log.debug("LEWK preview failed: %s", lewk.get("id"), exc_info=True)

    def apply_selected(self):
        items = self.grid.selectedItems()
        if not items:
            return
        lewk_id = items[0].data(Qt.UserRole)
        self.host.apply_lewk(lewk_id, self.strength.value())
        self.accept()

    def teach_selected(self):
        items = self.grid.selectedItems()
        if not items:
            return
        lewk_id = items[0].data(Qt.UserRole)
        lewk = next((entry for entry in self._lewks if entry["id"] == lewk_id), None)
        if lewk is None:
            return
        from .teach_me_dialog import TeachMeDialog
        TeachMeDialog(self.host, lewk, self.strength.value(), self).exec()

# ===== SNAPSMACK EOF =====
