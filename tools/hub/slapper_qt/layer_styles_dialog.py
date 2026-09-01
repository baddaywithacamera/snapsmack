"""Editable, non-destructive styles for the selected layer."""

import copy

from PySide6.QtCore import Qt
from PySide6.QtGui import QColor
from PySide6.QtWidgets import (
    QCheckBox, QColorDialog, QDialog, QDialogButtonBox, QFormLayout,
    QHBoxLayout, QLabel, QPushButton, QSlider, QSpinBox, QVBoxLayout, QWidget,
)


class LayerStylesDialog(QDialog):
    """Edit a layer's effects with live preview and cancel-safe rollback."""

    def __init__(self, host, layer, parent=None):
        super().__init__(parent or host)
        self.host = host
        self.layer = layer
        self._had_styles = "styles" in layer
        self._original = copy.deepcopy(layer.get("styles", {}))
        self.styles = layer.setdefault("styles", {})
        self._colours = {
            "stroke_color": list(self.styles.get("stroke_color", [255, 255, 255]))[:3],
            "glow_color": list(self.styles.get("glow_color", [255, 255, 255]))[:3],
            "overlay_color": list(self.styles.get("overlay_color", [255, 255, 255]))[:3],
        }

        self.setWindowTitle("LAYER STYLES")
        self.setMinimumWidth(470)
        outer = QVBoxLayout(self)
        title = QLabel("LAYER STYLES")
        title.setObjectName("SectionTitle")
        outer.addWidget(title)
        intro = QLabel("Effects stay editable and never alter the original photograph.")
        intro.setWordWrap(True)
        outer.addWidget(intro)

        form = QFormLayout()
        form.setFieldGrowthPolicy(QFormLayout.AllNonFixedFieldsGrow)
        self.shadow = QCheckBox("Enable drop shadow")
        self.shadow.setChecked(bool(self.styles.get("shadow", False)))
        form.addRow("Drop shadow", self.shadow)
        self.shadow_blur = self._number(0, 100, self.styles.get("shadow_blur", 8))
        form.addRow("Shadow softness", self.shadow_blur)
        self.shadow_offset = self._number(-100, 100, self.styles.get("shadow_offset", 6))
        form.addRow("Shadow offset", self.shadow_offset)

        self.inner_shadow = QCheckBox("Enable inner shadow")
        self.inner_shadow.setChecked(bool(self.styles.get("inner_shadow", False)))
        form.addRow("Inner shadow", self.inner_shadow)
        self.inner_shadow_blur = self._number(0, 100, self.styles.get("inner_shadow_blur", 7))
        form.addRow("Inner softness", self.inner_shadow_blur)

        self.stroke = self._number(0, 30, self.styles.get("stroke", 0))
        form.addRow("Stroke width", self._effect_row(self.stroke, "stroke_color"))
        self.glow = self._number(0, 100, self.styles.get("glow", 0))
        form.addRow("Outer glow size", self._effect_row(self.glow, "glow_color"))

        self.overlay = QCheckBox("Enable colour overlay")
        self.overlay.setChecked(bool(self.styles.get("color_overlay", False)))
        form.addRow("Colour overlay", self._effect_row(self.overlay, "overlay_color"))
        self.overlay_opacity = QSlider(Qt.Horizontal)
        self.overlay_opacity.setRange(0, 100)
        self.overlay_opacity.setValue(round(float(self.styles.get("overlay_opacity", .35)) * 100))
        self.overlay_readout = QLabel(str(self.overlay_opacity.value()))
        form.addRow("Overlay opacity", self._slider_row(self.overlay_opacity, self.overlay_readout))
        outer.addLayout(form)

        buttons = QDialogButtonBox(QDialogButtonBox.Cancel | QDialogButtonBox.Apply)
        buttons.button(QDialogButtonBox.Apply).setText("APPLY")
        buttons.button(QDialogButtonBox.Apply).clicked.connect(self.accept)
        buttons.rejected.connect(self.reject)
        outer.addWidget(buttons)

        for control in (self.shadow, self.shadow_blur, self.shadow_offset,
                        self.inner_shadow, self.inner_shadow_blur,
                        self.stroke, self.glow, self.overlay):
            signal = control.toggled if isinstance(control, QCheckBox) else control.valueChanged
            signal.connect(self._preview)
        self.overlay_opacity.valueChanged.connect(self._overlay_changed)
        self._sync_enabled()

    @staticmethod
    def _number(low, high, value):
        control = QSpinBox()
        control.setRange(low, high)
        control.setValue(int(value))
        return control

    @staticmethod
    def _slider_row(slider, readout):
        widget = QWidget()
        row = QHBoxLayout(widget)
        row.setContentsMargins(0, 0, 0, 0)
        row.addWidget(slider, 1)
        readout.setMinimumWidth(30)
        readout.setAlignment(Qt.AlignRight | Qt.AlignVCenter)
        row.addWidget(readout)
        return widget

    def _effect_row(self, control, key):
        widget = QWidget()
        row = QHBoxLayout(widget)
        row.setContentsMargins(0, 0, 0, 0)
        row.addWidget(control, 1)
        button = QPushButton("COLOUR…")
        button.setObjectName("SwatchBtn")
        button.clicked.connect(lambda _checked=False, k=key, b=button: self._pick_colour(k, b))
        row.addWidget(button)
        self._show_colour(button, self._colours[key])
        return widget

    @staticmethod
    def _show_colour(button, value):
        colour = QColor(*value[:3])
        ink = "#000" if colour.lightness() > 145 else "#fff"
        button.setStyleSheet(f"background:{colour.name()};color:{ink}")

    def _pick_colour(self, key, button):
        colour = QColorDialog.getColor(QColor(*self._colours[key]), self, "Choose layer style colour")
        if colour.isValid():
            self._colours[key] = [colour.red(), colour.green(), colour.blue()]
            self._show_colour(button, self._colours[key])
            self._preview()

    def _overlay_changed(self, value):
        self.overlay_readout.setText(str(value))
        self._preview()

    def _sync_enabled(self):
        self.shadow_blur.setEnabled(self.shadow.isChecked())
        self.shadow_offset.setEnabled(self.shadow.isChecked())
        self.inner_shadow_blur.setEnabled(self.inner_shadow.isChecked())
        self.overlay_opacity.setEnabled(self.overlay.isChecked())

    def _preview(self, *_args):
        self._sync_enabled()
        self.styles.update({
            "shadow": self.shadow.isChecked(),
            "shadow_blur": self.shadow_blur.value(),
            "shadow_offset": self.shadow_offset.value(),
            "inner_shadow": self.inner_shadow.isChecked(),
            "inner_shadow_blur": self.inner_shadow_blur.value(),
            "stroke": self.stroke.value(),
            "stroke_color": list(self._colours["stroke_color"]),
            "glow": self.glow.value(),
            "glow_color": list(self._colours["glow_color"]),
            "color_overlay": self.overlay.isChecked(),
            "overlay_color": list(self._colours["overlay_color"]),
            "overlay_opacity": self.overlay_opacity.value() / 100.0,
        })
        self.host.request_render()

    def accept(self):
        self._preview()
        self.host.doc.record("Layer styles")
        self.host.update_title()
        super().accept()

    def reject(self):
        if self._had_styles:
            self.layer["styles"] = copy.deepcopy(self._original)
        else:
            self.layer.pop("styles", None)
        self.host.request_render()
        super().reject()


# ===== SNAPSMACK EOF =====
