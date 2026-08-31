"""Filter gallery and editable controls for non-destructive filter layers."""

import random

from PySide6.QtCore import Qt
from PySide6.QtGui import QColor
from PySide6.QtWidgets import (
    QDialog, QVBoxLayout, QHBoxLayout, QLabel, QPushButton, QFormLayout,
    QSlider, QCheckBox, QComboBox, QDialogButtonBox, QColorDialog,
    QScrollArea, QWidget, QGroupBox, QGridLayout, QFrame,
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
    "angle": (-180, 180), "strength": (1, 100),
    "center_x": (0, 100), "center_y": (0, 100),
}

FILTER_GROUPS = (
    ("BLUR", (
        ("gaussian_blur", "Even, natural softening. Control the radius and mask it where needed."),
        ("motion_blur", "Drag detail in a straight direction using length and angle."),
        ("radial_blur", "Spin around a point or zoom outward from a movable centre."),
    )),
    ("LIGHT + ATMOSPHERE", (
        ("orton", "Luminous soft focus with highlight and shadow protection."),
        ("light_leak", "A movable, coloured edge leak with bloom and softness."),
    )),
    ("FILM + FINISH", (
        ("film_grain", "Controlled monochrome or colour grain across tonal ranges."),
        ("pastel", "Soft colour, lifted blacks, rolled highlights, and gentle tint."),
    )),
)

LABELS = {
    "amount": "Amount", "radius": "Radius", "length": "Length",
    "angle": "Angle", "strength": "Strength", "mode": "Style",
    "center_x": "Centre — left/right", "center_y": "Centre — up/down",
    "highlight_protection": "Protect highlights", "shadow_protection": "Protect shadows",
    "lifted_blacks": "Lift blacks", "highlight_rolloff": "Soften highlights",
    "contrast_reduction": "Reduce contrast", "color_variation": "Colour variation",
    "tint_strength": "Tint amount", "primary": "Primary colour",
    "secondary": "Secondary colour", "monochrome": "Monochrome grain",
}


class FiltersDialog(QDialog):
    def __init__(self, host, layer=None):
        super().__init__(host)
        self.host = host
        self.layer = layer
        self.setWindowTitle("FILTERS" if layer is None else layer.get("name", "Filter"))
        self.resize(620, 680)
        outer = QVBoxLayout(self)
        if layer is None:
            title = QLabel("ADD A FILTER LAYER")
            title.setObjectName("SectionTitle")
            outer.addWidget(title)
            intro = QLabel(
                "Choose an effect. Every filter stays editable, supports masks, "
                "and leaves the original photograph untouched.")
            intro.setWordWrap(True)
            outer.addWidget(intro)
            scroll = QScrollArea(); scroll.setWidgetResizable(True); scroll.setFrameShape(QFrame.NoFrame)
            content = QWidget(); content_layout = QVBoxLayout(content)
            for group_name, filters in FILTER_GROUPS:
                group = QGroupBox(group_name)
                grid = QGridLayout(group)
                for row, (kind, description) in enumerate(filters):
                    button = QPushButton(slapper_filters.FILTER_NAMES[kind])
                    button.setMinimumHeight(38)
                    button.setCursor(Qt.PointingHandCursor)
                    button.clicked.connect(lambda _checked=False, value=kind: self._add(value))
                    detail = QLabel(description); detail.setWordWrap(True)
                    detail.setStyleSheet("color:#aaa;")
                    grid.addWidget(button, row, 0)
                    grid.addWidget(detail, row, 1)
                    grid.setColumnStretch(1, 1)
                content_layout.addWidget(group)
            content_layout.addStretch(1)
            scroll.setWidget(content)
            outer.addWidget(scroll, 1)
            close = QDialogButtonBox(QDialogButtonBox.Close)
            close.rejected.connect(self.reject)
            outer.addWidget(close)
            return
        kind = layer.get("filter_type")
        title = QLabel(slapper_filters.FILTER_NAMES.get(kind, kind).upper())
        title.setObjectName("SectionTitle")
        outer.addWidget(title)
        outer.addWidget(QLabel(
            "Changes appear live. Use the layer panel afterward for opacity, "
            "blend mode, masks, order, or visibility."))
        scroll = QScrollArea(); scroll.setWidgetResizable(True); scroll.setFrameShape(QFrame.NoFrame)
        content = QWidget()
        form = QFormLayout()
        form.setFieldGrowthPolicy(QFormLayout.AllNonFixedFieldsGrow)
        content.setLayout(form)
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
            elif key == "mode":
                control = QComboBox(); control.addItems(["spin", "zoom"])
                control.setCurrentText(str(value))
                control.currentTextChanged.connect(lambda text, k=key: self._set(k, text))
            elif key in RANGES and isinstance(value, (int, float)):
                control = QWidget(); control_row = QHBoxLayout(control)
                control_row.setContentsMargins(0, 0, 0, 0)
                slider = QSlider(Qt.Horizontal)
                slider.setRange(*RANGES[key]); slider.setValue(int(value))
                readout = QLabel(str(int(value))); readout.setMinimumWidth(42)
                readout.setAlignment(Qt.AlignRight | Qt.AlignVCenter)
                slider.valueChanged.connect(
                    lambda number, k=key, label=readout:
                    (label.setText(str(number)), self._set(k, number)))
                control_row.addWidget(slider, 1); control_row.addWidget(readout)
            elif key in {"primary", "secondary", "tint"} and isinstance(value, list):
                control = QPushButton("Choose…")
                self._show_colour(control, value)
                control.clicked.connect(
                    lambda _checked=False, k=key, button=control: self._pick_colour(k, button))
            elif key == "seed":
                control = QPushButton("Randomize")
                control.clicked.connect(lambda _checked=False, k=key: self._randomize(k))
            else:
                continue
            form.addRow(LABELS.get(key, key.replace("_", " ").title()), control)
        scroll.setWidget(content)
        outer.addWidget(scroll, 1)
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

    @staticmethod
    def _show_colour(button, value):
        colour = QColor(*value[:3])
        button.setStyleSheet(
            f"background:{colour.name()};color:{'#000' if colour.lightness() > 145 else '#fff'}")

    def _pick_colour(self, key, button):
        value = self.layer["settings"].get(key, [255, 255, 255])
        colour = QColorDialog.getColor(QColor(*value[:3]), self, "Choose filter colour")
        if colour.isValid():
            rgb = [colour.red(), colour.green(), colour.blue()]
            self.layer["settings"][key] = rgb
            self._show_colour(button, rgb)
            self.host.request_render()

    def _randomize(self, key):
        self.layer["settings"][key] = random.SystemRandom().randint(1, 2_147_483_647)
        self.host.request_render()

    def accept(self):
        if self.layer is not None:
            self.host.doc.record(f"Edit {self.layer.get('name', 'filter')}")
            self.host.update_title()
        super().accept()


# ===== SNAPSMACK EOF =====
