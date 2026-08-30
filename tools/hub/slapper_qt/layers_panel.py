"""Layers panel for the Qt editor (Phase 3).

Drives ``document.layers`` — the engine already composites them. The panel adds
adjustment / image / text layers, toggles visibility, sets opacity and blend
mode, reorders and deletes, and chooses the *edit target*: the base photograph
or a selected layer. When a layer is the target, the light/colour rail edits
that layer's own adjustments.
"""

import os

from PySide6.QtCore import Qt
from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QLabel, QPushButton, QCheckBox,
    QComboBox, QFileDialog, QSlider,
)

from . import theme

BLEND_MODES = [
    "normal", "multiply", "screen", "overlay", "soft_light", "hard_light",
    "darken", "lighten", "difference", "color", "luminosity",
]
BASE = "base"


class LayersPanel(QWidget):
    """The layers list plus its add / order / blend controls.

    ``host`` must provide:
        host.doc                      -> the EditorDocument (or None)
        host.set_target(target)       -> "base" or a layer id
        host.active_target            -> current target
        host.request_render()         -> re-render preview + histogram
        host.after_structure_change() -> rebuild + sync controls + render + title
    """

    def __init__(self, host, parent=None):
        super().__init__(parent)
        self.host = host

        outer = QVBoxLayout(self)
        outer.setContentsMargins(12, 8, 12, 10)
        outer.setSpacing(8)

        # Add-layer buttons
        add_row = QHBoxLayout()
        add_row.setSpacing(4)
        for text, handler in (("+ Adjust", self._add_adjustment),
                              ("+ Image", self._add_image),
                              ("+ Text", self._add_text),
                              ("+ Filter", self._add_filter)):
            btn = QPushButton(text)
            btn.setObjectName("LayerAddBtn")
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(handler)
            add_row.addWidget(btn)
        outer.addLayout(add_row)

        # The list of layers (rebuilt on change)
        self.list_container = QVBoxLayout()
        self.list_container.setSpacing(2)
        outer.addLayout(self.list_container)

        # Selected-layer detail: opacity, blend, order, delete
        self.detail = QWidget()
        detail_layout = QVBoxLayout(self.detail)
        detail_layout.setContentsMargins(0, 4, 0, 0)
        detail_layout.setSpacing(6)

        op_row = QHBoxLayout()
        op_row.setSpacing(8)
        op_label = QLabel("Opacity")
        op_label.setObjectName("ControlName")
        op_label.setFixedWidth(52)
        op_row.addWidget(op_label)
        self.opacity = QSlider(Qt.Horizontal)
        self.opacity.setRange(0, 100)
        self.opacity.setValue(100)
        self.opacity.valueChanged.connect(self._on_opacity)
        self.opacity.sliderReleased.connect(self._commit_opacity)
        op_row.addWidget(self.opacity, 1)
        self.opacity_value = QLabel("100")
        self.opacity_value.setObjectName("ControlValue")
        self.opacity_value.setFixedWidth(30)
        self.opacity_value.setAlignment(Qt.AlignRight | Qt.AlignVCenter)
        op_row.addWidget(self.opacity_value)
        detail_layout.addLayout(op_row)

        blend_row = QHBoxLayout()
        blend_row.setSpacing(8)
        blend_label = QLabel("Blend")
        blend_label.setObjectName("ControlName")
        blend_label.setFixedWidth(52)
        blend_row.addWidget(blend_label)
        self.blend = QComboBox()
        self.blend.addItems([m.replace("_", " ").title() for m in BLEND_MODES])
        self.blend.currentIndexChanged.connect(self._on_blend)
        blend_row.addWidget(self.blend, 1)
        detail_layout.addLayout(blend_row)

        order_row = QHBoxLayout()
        order_row.setSpacing(4)
        for text, handler in (("▲", self._move_up), ("▼", self._move_down)):
            btn = QPushButton(text)
            btn.setObjectName("LayerOrderBtn")
            btn.setFixedWidth(34)
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(handler)
            order_row.addWidget(btn)
        order_row.addStretch(1)
        self.delete_btn = QPushButton("Delete Layer")
        self.delete_btn.setObjectName("LayerDeleteBtn")
        self.delete_btn.setCursor(Qt.PointingHandCursor)
        self.delete_btn.clicked.connect(self._delete)
        order_row.addWidget(self.delete_btn)
        detail_layout.addLayout(order_row)

        outer.addWidget(self.detail)
        self.rebuild()

    # --- Helpers ------------------------------------------------------------
    @property
    def doc(self):
        return self.host.doc

    def _selected_layer(self):
        if not self.doc or self.host.active_target == BASE:
            return None
        for layer in self.doc.layers:
            if layer.get("id") == self.host.active_target:
                return layer
        return None

    def _selected_index(self):
        if not self.doc:
            return -1
        for i, layer in enumerate(self.doc.layers):
            if layer.get("id") == self.host.active_target:
                return i
        return -1

    # --- Build the list -----------------------------------------------------
    def rebuild(self):
        while self.list_container.count():
            item = self.list_container.takeAt(0)
            widget = item.widget()
            if widget:
                widget.deleteLater()

        # Base image row (always present, always visible)
        self.list_container.addWidget(
            self._make_row(BASE, "Base image", None, self.host.active_target == BASE))

        # Layers shown top-of-stack first
        if self.doc:
            for layer in reversed(self.doc.layers):
                lid = layer.get("id")
                self._label_for(layer)
                self.list_container.addWidget(
                    self._make_row(lid, self._label_for(layer),
                                   layer.get("visible", True),
                                   self.host.active_target == lid))

        self._sync_detail()

    def _label_for(self, layer):
        icon = {"adjustment": "◐", "image": "▣", "text": "T",
                "filter": "FX"}.get(layer.get("type"), "•")
        return f"{icon}  {layer.get('name', 'Layer')}"

    def _make_row(self, target, label, visible, active):
        row = QWidget()
        row.setObjectName("LayerRowActive" if active else "LayerRow")
        layout = QHBoxLayout(row)
        layout.setContentsMargins(6, 3, 6, 3)
        layout.setSpacing(6)

        if visible is not None:
            check = QCheckBox()
            check.setChecked(bool(visible))
            check.setToolTip("Visible")
            check.toggled.connect(lambda state, t=target: self._toggle_visible(t, state))
            layout.addWidget(check)
        else:
            spacer = QLabel()
            spacer.setFixedWidth(15)
            layout.addWidget(spacer)

        name = QPushButton(label)
        name.setObjectName("LayerName")
        name.setCursor(Qt.PointingHandCursor)
        if target == BASE:
            name.setToolTip("The original photograph. Click to edit it directly — "
                            "the sliders then change the base photo, beneath any "
                            "LEWKS or layers stacked on top.")
        else:
            name.setToolTip("Click to edit this layer")
        name.clicked.connect(lambda _c, t=target: self._select(t))
        layout.addWidget(name, 1)
        return row

    def _sync_detail(self):
        layer = self._selected_layer()
        self.detail.setVisible(layer is not None)
        if not layer:
            return
        self.opacity.blockSignals(True)
        self.opacity.setValue(int(round(float(layer.get("opacity", 1.0)) * 100)))
        self.opacity.blockSignals(False)
        self.opacity_value.setText(str(self.opacity.value()))
        mode = layer.get("blend", "normal")
        if mode in BLEND_MODES:
            self.blend.blockSignals(True)
            self.blend.setCurrentIndex(BLEND_MODES.index(mode))
            self.blend.blockSignals(False)

    # --- Actions ------------------------------------------------------------
    def _select(self, target):
        self.host.set_target(target)
        self.rebuild()

    def _toggle_visible(self, target, state):
        layer = None
        for candidate in (self.doc.layers if self.doc else []):
            if candidate.get("id") == target:
                layer = candidate
                break
        if layer is None:
            return
        layer["visible"] = bool(state)
        self.doc.record("Toggle layer visibility")
        self.host.request_render()

    def _add_adjustment(self):
        if not self.doc:
            return
        layer = self.doc.add_adjustment_layer()
        self.host.set_target(layer["id"])
        self.host.after_structure_change()

    def _add_image(self):
        if not self.doc:
            return
        path, _ = QFileDialog.getOpenFileName(
            self, "Add image layer", "",
            "Images (*.jpg *.jpeg *.png *.tif *.tiff *.webp *.bmp)")
        if not path:
            return
        layer = self.doc.add_image_layer(path)
        self.host.set_target(layer["id"])
        self.host.after_structure_change()

    def _add_text(self):
        if not self.doc:
            return
        layer = self.doc.add_text_layer()
        self.host.set_target(layer["id"])
        self.host.after_structure_change()

    def _add_filter(self):
        if self.doc:
            self.host.open_filters()

    def _delete(self):
        index = self._selected_index()
        if index < 0:
            return
        del self.doc.layers[index]
        self.doc.record("Delete layer")
        self.host.set_target(BASE)
        self.host.after_structure_change()

    def _move_up(self):
        index = self._selected_index()
        if index < 0 or index >= len(self.doc.layers) - 1:
            return
        self.doc.layers[index], self.doc.layers[index + 1] = \
            self.doc.layers[index + 1], self.doc.layers[index]
        self.doc.record("Reorder layer")
        self.host.after_structure_change()

    def _move_down(self):
        index = self._selected_index()
        if index <= 0:
            return
        self.doc.layers[index], self.doc.layers[index - 1] = \
            self.doc.layers[index - 1], self.doc.layers[index]
        self.doc.record("Reorder layer")
        self.host.after_structure_change()

    def _on_opacity(self, value):
        self.opacity_value.setText(str(value))
        layer = self._selected_layer()
        if layer is not None:
            layer["opacity"] = value / 100.0
            self.host.request_render()

    def _commit_opacity(self):
        if self._selected_layer() is not None:
            self.doc.record("Layer opacity")
            self.host.update_title()

    def _on_blend(self, index):
        layer = self._selected_layer()
        if layer is not None and 0 <= index < len(BLEND_MODES):
            layer["blend"] = BLEND_MODES[index]
            self.doc.record("Layer blend mode")
            self.host.request_render()
            self.host.update_title()

# ===== SNAPSMACK EOF =====
