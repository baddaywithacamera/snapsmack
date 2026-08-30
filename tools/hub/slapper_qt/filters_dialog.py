"""Filter gallery and editable controls for non-destructive filter layers."""

from PySide6.QtCore import Qt
from PySide6.QtWidgets import (
    QDialog, QVBoxLayout, QHBoxLayout, QLabel, QPushButton, QFormLayout,
    QSlider, QCheckBox, QComboBox, QDialogButtonBox,
)

import slapper_filters


RANGES = {
    "amount": (0, 100), "radius": (1, 60), "brightness": (-50, 100),
    "contrast": (-100, 100), "saturation": (-100, 100),
    "highlight_protection": (0, 100), "shadow_protection": (0, 100),
    "size": (1, 8), "roughness": (0, 100), "softness": (0, 100),
    "color_variation": (0, 100), "shadows": (0, 100),
    "midtones": (0, 100), "highlights": (0, 100), "position": (0, 100),
    "rotation": (-180, 180), "spread": (1, 100), "length": (1, 100),
    "warmth": (-100, 100), "bloom": (0, 100), "lifted_blacks": (0, 100),
    "highlight_rolloff": (0, 100), "contrast_reduction": (0, 100),
    "vibrance": (-100, 100), "fade": (0, 100), "tint_strength": (0, 100),
}


class FiltersDialog(QDialog):
    def __init__(self, host, layer=None):
        super().__init__(host)
        self.host = host
        self.layer = layer
        self.setWindowTitle("FILTERS" if layer is None else layer.get("name", "Filter"))
        self.resize(520, 560)
        outer = QVBoxLayout(self)
        if layer is None:
            outer.addWidget(QLabel(
                "Add an editable filter layer. The original photograph is never changed."))
            for kind, name in slapper_filters.FILTER_NAMES.items():
                button = QPushButton(name)
                button.clicked.connect(lambda _checked=False, value=kind: self._add(value))
                outer.addWidget(button)
            outer.addStretch(1)
            return
        kind = layer.get("filter_type")
        outer.addWidget(QLabel(
            f"{slapper_filters.FILTER_NAMES.get(kind, kind)} — editable filter layer"))
        form = QFormLayout()
        settings = layer.setdefault("settings", slapper_filters.defaults(kind))
        for key, value in list(settings.items()):
            if key == "monochrome":
                control = QCheckBox()
                control.setChecked(bool(value))
                control.toggled.connect(lambda checked, k=key: self._set(k, checked))
            elif key == "edge":
                control = QComboBox(); control.addItems(["left", "right", "top", "bottom"])
                control.setCurrentText(str(value))
                control.currentTextChanged.connect(lambda text, k=key: self._set(k, text))
            elif key in RANGES and isinstance(value, (int, float)):
                control = QSlider(Qt.Horizontal)
                control.setRange(*RANGES[key]); control.setValue(int(value))
                control.valueChanged.connect(lambda number, k=key: self._set(k, number))
            else:
                continue
            form.addRow(key.replace("_", " ").title(), control)
        outer.addLayout(form)
        buttons = QDialogButtonBox(QDialogButtonBox.Close)
        buttons.rejected.connect(self.accept)
        outer.addWidget(buttons)

    def _add(self, kind):
        layer = self.host.doc.add_filter_layer(kind)
        self.host.set_target(layer["id"])
        self.host.after_structure_change()
        self.accept()
        FiltersDialog(self.host, layer).exec()

    def _set(self, key, value):
        self.layer["settings"][key] = value
        self.host.request_render()

    def accept(self):
        if self.layer is not None:
            self.host.doc.record(f"Edit {self.layer.get('name', 'filter')}")
            self.host.update_title()
        super().accept()


# ===== SNAPSMACK EOF =====
