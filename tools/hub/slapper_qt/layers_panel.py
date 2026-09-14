"""Layers panel for the Qt editor (Phase 3).

Drives ``document.layers`` — the engine already composites them. The panel adds
adjustment / image / text layers, toggles visibility, sets opacity and blend
mode, reorders and deletes, and chooses the *edit target*: the base photograph
or a selected layer. When a layer is the target, the light/colour rail edits
that layer's own adjustments.
"""

import os

from PySide6.QtCore import Qt, QTimer
from PySide6.QtGui import QColor
from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QLabel, QPushButton, QCheckBox,
    QComboBox, QFileDialog, QSlider, QGridLayout, QInputDialog, QColorDialog,
)

import editor_engine
import gemini_image_edit
from .engine_bridge import pil_to_qpixmap

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
        self._mask_thumbnails = {}
        self._row_buttons = {}
        self._copied_mask = None

        outer = QVBoxLayout(self)
        outer.setContentsMargins(12, 8, 12, 10)
        outer.setSpacing(8)

        add_label = QLabel("ADD A LAYER")
        add_label.setObjectName("LayerSectionLabel")
        outer.addWidget(add_label)

        # Two calm rows are easier to scan than four abbreviated buttons.
        add_grid = QGridLayout()
        add_grid.setSpacing(4)
        for index, (text, tip, handler) in enumerate((
                ("Adjustment", "Edit light and colour without changing the base photo", self._add_adjustment),
                ("Blank", "Add a transparent layer to fill, blend and mask", self._add_paint),
                ("Image", "Place another image over the photo", self._add_image),
                ("Text", "Add editable text", self._add_text),
                ("Creative filter", "Add a filter as a separate layer", self._add_filter),
                ("Texture", "Search FoundTextures.ca and add a texture layer", self._add_texture))):
            btn = QPushButton(text)
            btn.setObjectName("LayerAddBtn")
            btn.setToolTip(tip)
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(handler)
            add_grid.addWidget(btn, index // 2, index % 2)
        outer.addLayout(add_grid)

        stack_label = QLabel("LAYER STACK  ·  TOP LAYER FIRST")
        stack_label.setObjectName("LayerSectionLabel")
        outer.addWidget(stack_label)

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
        self.opacity.sliderPressed.connect(self.host.begin_interactive_render)
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

        self.feather_row = QWidget()
        feather_layout = QHBoxLayout(self.feather_row)
        feather_layout.setContentsMargins(0, 0, 0, 0)
        feather_layout.setSpacing(8)
        feather_label = QLabel("Feather")
        feather_label.setObjectName("ControlName")
        feather_label.setFixedWidth(52)
        feather_layout.addWidget(feather_label)
        self.feather = QSlider(Qt.Horizontal)
        self.feather.setRange(0, 200)
        self.feather.setValue(100)
        self.feather.setToolTip(
            "Soften the AI repair boundary. The preview updates when you pause or "
            "release the slider; this does not contact Gemini again.")
        self.feather.valueChanged.connect(self._on_ai_heal_feather)
        self.feather.sliderReleased.connect(self._commit_ai_heal_feather)
        self._pending_feather = None
        self._feather_changed = False
        self._feather_timer = QTimer(self)
        self._feather_timer.setSingleShot(True)
        self._feather_timer.setInterval(300)
        self._feather_timer.timeout.connect(self._apply_pending_ai_heal_feather)
        feather_layout.addWidget(self.feather, 1)
        self.feather_value = QLabel("100")
        self.feather_value.setObjectName("ControlValue")
        self.feather_value.setFixedWidth(30)
        self.feather_value.setAlignment(Qt.AlignRight | Qt.AlignVCenter)
        feather_layout.addWidget(self.feather_value)
        detail_layout.addWidget(self.feather_row)

        self.fill_colour_btn = QPushButton("Fill blank layer…")
        self.fill_colour_btn.setObjectName("LayerAddBtn")
        self.fill_colour_btn.clicked.connect(self._choose_fill_colour)
        detail_layout.addWidget(self.fill_colour_btn)

        mask_row = QGridLayout()
        mask_row.setSpacing(4)
        self.mask_enabled = QCheckBox("Use layer mask")
        self.mask_enabled.setToolTip("Temporarily enable or disable this layer's mask")
        self.mask_enabled.toggled.connect(self._toggle_mask_enabled)
        mask_row.addWidget(self.mask_enabled, 0, 0)
        self.mask_linked = QCheckBox("Linked")
        self.mask_linked.setToolTip("Move/transform this mask with its layer")
        self.mask_linked.toggled.connect(self._toggle_mask_linked)
        mask_row.addWidget(self.mask_linked, 0, 1)
        self.edit_mask_btn = QPushButton("Edit mask")
        self.edit_mask_btn.setObjectName("LayerOrderBtn")
        self.edit_mask_btn.clicked.connect(self._edit_mask)
        mask_row.addWidget(self.edit_mask_btn, 1, 0)
        self.rename_btn = QPushButton("Rename layer")
        self.rename_btn.setObjectName("LayerOrderBtn")
        self.rename_btn.clicked.connect(self._rename)
        mask_row.addWidget(self.rename_btn, 1, 1)
        detail_layout.addLayout(mask_row)

        mask_copy_row = QHBoxLayout()
        mask_copy_row.setSpacing(4)
        self.copy_mask_btn = QPushButton("Copy mask")
        self.copy_mask_btn.setObjectName("LayerOrderBtn")
        self.copy_mask_btn.clicked.connect(self._copy_mask)
        mask_copy_row.addWidget(self.copy_mask_btn)
        self.paste_mask_btn = QPushButton("Paste mask")
        self.paste_mask_btn.setObjectName("LayerOrderBtn")
        self.paste_mask_btn.clicked.connect(self._paste_mask)
        mask_copy_row.addWidget(self.paste_mask_btn)
        detail_layout.addLayout(mask_copy_row)

        order_row = QHBoxLayout()
        order_row.setSpacing(4)
        for text, tip, handler in (("Move up", "Move toward the top of the stack", self._move_up),
                                   ("Move down", "Move toward the base photo", self._move_down)):
            btn = QPushButton(text)
            btn.setObjectName("LayerOrderBtn")
            btn.setToolTip(tip)
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(handler)
            order_row.addWidget(btn)
        order_row.addStretch(1)
        self.delete_btn = QPushButton("Remove")
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
        self._mask_thumbnails = {}
        self._row_buttons = {}
        while self.list_container.count():
            item = self.list_container.takeAt(0)
            widget = item.widget()
            if widget:
                widget.deleteLater()

        # Layers shown top-of-stack first
        if self.doc:
            for layer in reversed(self.doc.layers):
                lid = layer.get("id")
                self._label_for(layer)
                self.list_container.addWidget(
                    self._make_row(lid, self._label_for(layer),
                                   layer.get("visible", True),
                                   self.host.active_target == lid))

        # The base belongs at the bottom of the visual stack.
        self.list_container.addWidget(
            self._make_row(BASE, "Base photo", None, self.host.active_target == BASE))

        self._sync_detail()

    def _label_for(self, layer):
        kind = {"adjustment": "Adjustment", "paint": "Blank", "image": "Image", "text": "Text",
                "filter": "Filter", "generative_expand": "Canvas expansion"}.get(
                    layer.get("type"), "Layer")
        name = layer.get("name", "Layer")
        return name if name.lower() == kind.lower() else f"{name}  ·  {kind}"

    def _make_row(self, target, label, visible, active):
        row = QWidget()
        row.setObjectName("LayerRowActive" if active else "LayerRow")
        layout = QHBoxLayout(row)
        layout.setContentsMargins(6, 3, 6, 3)
        layout.setSpacing(6)

        if visible is not None:
            check = QCheckBox("Show")
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
        self._row_buttons[target] = name
        if target != BASE:
            layer = next((item for item in self.doc.layers
                          if item.get("id") == target), None)
            if layer and layer.get("mask"):
                mask = editor_engine._mask_from_text(layer["mask"])
                mask.thumbnail((28, 28))
                thumbnail = QLabel()
                thumbnail.setFixedSize(30, 30)
                thumbnail.setPixmap(pil_to_qpixmap(mask.convert("RGB")))
                thumbnail.setToolTip(
                    "Layer mask thumbnail — white reveals, black hides" if
                    layer.get("mask_enabled", True) else "Layer mask is disabled")
                layout.addWidget(thumbnail)
                self._mask_thumbnails[target] = thumbnail
                badge = QLabel("MASK" if layer.get("mask_enabled", True) else "OFF")
                badge.setObjectName("LayerSectionLabel")
                layout.addWidget(badge)
            if layer and layer.get("type") == "adjustment":
                changed = sum(1 for key, value in layer.get("adjustments", {}).items()
                              if value != editor_engine.DEFAULT_ADJUSTMENTS.get(key))
                if changed:
                    summary = QLabel(f"{changed} edits")
                    summary.setObjectName("LayerSectionLabel")
                    summary.setToolTip("Number of non-default adjustments on this layer")
                    layout.addWidget(summary)
        return row

    def update_mask_thumbnail(self, layer):
        """Refresh the selected row's mask preview without rebuilding the panel."""
        thumbnail = self._mask_thumbnails.get(layer.get("id"))
        if thumbnail is None or not layer.get("mask"):
            return
        mask = editor_engine._mask_from_text(layer["mask"])
        mask.thumbnail((28, 28))
        thumbnail.setPixmap(pil_to_qpixmap(mask.convert("RGB")))

    def _sync_detail(self):
        layer = self._selected_layer()
        self.detail.setVisible(layer is not None)
        if not layer:
            return
        self.opacity.blockSignals(True)
        self.opacity.setValue(int(round(float(layer.get("opacity", 1.0)) * 100)))
        self.opacity.setEnabled(layer.get("type") != "generative_expand")
        self.opacity.blockSignals(False)
        self.opacity_value.setText(str(self.opacity.value()))
        mode = layer.get("blend", "normal")
        if mode in BLEND_MODES:
            self.blend.blockSignals(True)
            self.blend.setCurrentIndex(BLEND_MODES.index(mode))
            self.blend.blockSignals(False)
        self.blend.setEnabled(layer.get("type") != "generative_expand")
        has_mask = bool(layer.get("mask"))
        self.mask_enabled.blockSignals(True)
        self.mask_enabled.setChecked(bool(layer.get("mask_enabled", True)))
        self.mask_enabled.setEnabled(has_mask)
        self.mask_enabled.blockSignals(False)
        self.mask_linked.blockSignals(True)
        self.mask_linked.setChecked(bool(layer.get("mask_linked", True)))
        self.mask_linked.setEnabled(has_mask and layer.get("type") in {"image", "text"})
        self.mask_linked.blockSignals(False)
        self.edit_mask_btn.setEnabled(True)
        self.fill_colour_btn.setVisible(layer.get("type") == "paint")
        if (layer.get("mask_kind") in {"ai-heal-selection", "ai-fill-selection"} and
                layer.get("mask") and not layer.get("ai_heal_source_mask")):
            # Repairs made before 0.7.38 stored only the final feathered mask.
            # Recover its solid centre so the user can tune those existing
            # repairs instead of paying Gemini to generate them again.
            legacy_mask = editor_engine._mask_from_text(layer["mask"])
            recovered = legacy_mask.point(lambda value: 255 if value >= 128 else 0)
            layer["ai_heal_source_mask"] = editor_engine._mask_to_text(recovered)
            layer["ai_heal_feather"] = 100
        adjustable_feather = bool(
            layer.get("mask_kind") in {"ai-heal-selection", "ai-fill-selection"} and
            layer.get("ai_heal_source_mask"))
        self.feather_row.setVisible(adjustable_feather)
        if adjustable_feather:
            self.feather.blockSignals(True)
            self.feather.setValue(int(layer.get("ai_heal_feather", 100)))
            self.feather.blockSignals(False)
            self.feather_value.setText(str(self.feather.value()))
        if layer.get("type") == "paint":
            fill = list(layer.get("fill", [0, 0, 0, 0]))
            colour = QColor(*((fill + [0, 0, 0])[:3]))
            self.fill_colour_btn.setStyleSheet(
                f"background:{colour.name()};color:{'#000' if colour.lightness() > 140 else '#fff'}")
        self.copy_mask_btn.setEnabled(has_mask)
        self.paste_mask_btn.setEnabled(self._copied_mask is not None)

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
            "Images and SVG watermarks (*.jpg *.jpeg *.png *.tif *.tiff *.webp *.bmp *.svg)")
        if not path:
            return
        layer = self.doc.add_image_layer(path)
        self.host.set_target(layer["id"])
        self.host.after_structure_change()

    def _add_paint(self):
        if not self.doc:
            return
        layer = self.doc.add_paint_layer()
        self.host.set_target(layer["id"])
        self.host.after_structure_change()

    def _choose_fill_colour(self):
        layer = self._selected_layer()
        if layer is None or layer.get("type") != "paint":
            return
        current = list(layer.get("fill", [0, 0, 0, 0]))
        colour = QColorDialog.getColor(QColor(*((current + [0, 0, 0])[:3])), self,
                                       "Fill blank layer")
        if not colour.isValid():
            return
        layer["fill"] = [colour.red(), colour.green(), colour.blue(), 255]
        self.doc.record("Fill blank layer")
        self.host.after_structure_change()

    def _copy_mask(self):
        layer = self._selected_layer()
        if layer is None or not layer.get("mask"):
            return
        self._copied_mask = {
            "mask": str(layer["mask"]),
            "mask_kind": str(layer.get("mask_kind", "copied")),
        }
        self.paste_mask_btn.setEnabled(True)
        self.host.status.showMessage("Layer mask copied.")

    def _paste_mask(self):
        layer = self._selected_layer()
        if layer is None or self._copied_mask is None:
            return
        layer["mask"] = self._copied_mask["mask"]
        layer["mask_kind"] = self._copied_mask["mask_kind"]
        layer["mask_enabled"] = True
        self.doc.record("Paste layer mask")
        self.host.after_structure_change()
        self.host.status.showMessage("Copied mask pasted as an independent mask.")

    def _add_text(self):
        if not self.doc:
            return
        layer = self.doc.add_text_layer()
        self.host.set_target(layer["id"])
        self.host.after_structure_change()

    def _add_filter(self):
        if self.doc:
            self.host.open_filters()

    def _add_texture(self):
        if self.doc:
            self.host.open_textures()

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
            self.host.request_render(interactive=self.opacity.isSliderDown())

    def _commit_opacity(self):
        if self._selected_layer() is not None:
            self.doc.record("Layer opacity")
            self.host.update_title()
            self.host.finish_interactive_render()

    def _on_ai_heal_feather(self, value):
        self.feather_value.setText(str(value))
        layer = self._selected_layer()
        if not layer or not layer.get("ai_heal_source_mask"):
            return
        self._pending_feather = (layer.get("id"), int(value))
        self._feather_changed = True
        self._feather_timer.start()

    def _apply_pending_ai_heal_feather(self):
        pending = self._pending_feather
        self._pending_feather = None
        if not pending or not self.doc:
            return
        layer_id, value = pending
        layer = next((candidate for candidate in self.doc.layers
                      if candidate.get("id") == layer_id), None)
        if not layer or not layer.get("ai_heal_source_mask"):
            return
        source = editor_engine._mask_from_text(layer["ai_heal_source_mask"])
        layer["ai_heal_feather"] = int(value)
        layer["mask"] = editor_engine._mask_to_text(
            gemini_image_edit.blend_mask(source, value / 100.0))
        if self.host.active_target == layer_id:
            self.update_mask_thumbnail(layer)
        self.host.request_render()

    def _commit_ai_heal_feather(self):
        self._feather_timer.stop()
        self._apply_pending_ai_heal_feather()
        layer = self._selected_layer()
        if layer and layer.get("ai_heal_source_mask") and self._feather_changed:
            self._feather_changed = False
            self.doc.record("AI Heal feather")
            self.host.update_title()

    def _on_blend(self, index):
        layer = self._selected_layer()
        if layer is not None and 0 <= index < len(BLEND_MODES):
            layer["blend"] = BLEND_MODES[index]
            self.doc.record("Layer blend mode")
            self.host.request_render()
            self.host.update_title()

    def _toggle_mask_enabled(self, enabled):
        layer = self._selected_layer()
        if layer is None or not layer.get("mask"):
            return
        layer["mask_enabled"] = bool(enabled)
        self.doc.record("Toggle layer mask")
        self.host.request_render()
        self.rebuild()

    def _toggle_mask_linked(self, linked):
        layer = self._selected_layer()
        if layer is None or not layer.get("mask"):
            return
        layer["mask_linked"] = bool(linked)
        self.doc.record("Link layer mask" if linked else "Unlink layer mask")
        self.host.request_render()

    def _edit_mask(self):
        if self._selected_layer() is None:
            return
        self.host.mask_section.header.setChecked(True)
        self.host.mask_section.setVisible(True)

    def _rename(self):
        layer = self._selected_layer()
        if layer is None:
            return
        name, accepted = QInputDialog.getText(
            self, "Rename layer", "Layer name:", text=str(layer.get("name") or "Layer"))
        if not accepted or not name.strip():
            return
        layer["name"] = name.strip()
        self.doc.record("Rename layer")
        self.rebuild()
        self.host.target_label.setText(f"Editing: {layer['name']}")
        self.host.update_title()

# ===== SNAPSMACK EOF =====
