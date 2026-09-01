"""Transparent, educational inspection of a built-in LEWK.

TEACH ME renders from the LEWK's real adjustment dictionary.  It never keeps a
second, hand-written representation of the look, so the lesson and the result
cannot quietly drift apart.
"""

from PySide6.QtCore import Qt, QSize
from PySide6.QtWidgets import (
    QDialog, QVBoxLayout, QHBoxLayout, QLabel, QListWidget, QListWidgetItem,
    QPushButton, QSplitter, QWidget,
)

from PIL import Image

import editor_engine
from . import theme
from .engine_bridge import pil_to_qpixmap


ACTION_GROUPS = (
    ("light", "Shape the light", ("exposure", "brightness", "contrast", "highlights",
                                  "shadows", "whites", "blacks"),
     "Sets the overall brightness range and decides which parts of the frame receive emphasis."),
    ("white-balance", "Set the colour balance", ("temperature", "tint"),
     "Warms, cools, or neutralises the photograph before the stylistic colour work."),
    ("colour", "Control colour intensity", ("saturation", "vibrance"),
     "Saturation moves every colour together; vibrance concentrates more on quieter colours."),
    ("presence", "Shape local detail", ("clarity", "texture", "dehaze", "sharpen",
                                        "sharpen_radius", "sharpen_reduce_noise", "sharpen_mode"),
     "Changes edge contrast and fine detail. This can add bite, reveal surface, or soften skin."),
    ("curve", "Draw the tone curve", ("curve",),
     "Remaps dark, middle, and bright tones. A curve can add punch, fade blacks, or soften highlights."),
    ("channel-curves", "Colour with channel curves", ("curve_red", "curve_green", "curve_blue"),
     "Moves the red, green, and blue channels independently to create colour casts and cross-processing."),
    ("colour-mix", "Mix individual colours", tuple(
        f"col_{kind}_{colour}" for kind in ("hue", "sat", "lum")
        for colour in ("red", "orange", "yellow", "green", "aqua", "blue", "purple", "magenta")),
     "Shifts, strengthens, or lightens particular colours instead of pushing every colour together."),
    ("black-white", "Build the monochrome response", ("black_white",) + tuple(
        f"bw_{colour}" for colour in
        ("red", "orange", "yellow", "green", "aqua", "blue", "purple", "magenta")),
     "Converts to monochrome and controls how the original colours translate into light and dark greys."),
    ("split-shadow", "Colour the shadows", ("split_shadow", "split_shadow_amount"),
     "Places a controlled colour cast into darker tones without tinting the whole photograph equally."),
    ("split-midtone", "Colour the midtones", ("split_midtone", "split_midtone_amount"),
     "Colours the middle of the tonal range, where much of a photograph's subject matter usually lives."),
    ("split-highlight", "Colour the highlights", ("split_highlight", "split_highlight_amount"),
     "Places colour into the brighter tones to suggest warm light, cool air, paper, or film response."),
    ("photo-filter", "Place a colour filter over the lens",
     ("photo_filter_color", "photo_filter_density", "photo_filter_preserve_lum"),
     "Adds a photographic colour wash while optionally protecting the original brightness."),
    ("glow", "Place the glow", ("glow_amount", "glow_colour", "glow_x", "glow_y", "glow_size"),
     "Adds a positioned bloom of light. Its location and size matter as much as its colour."),
    ("finish", "Finish the frame", ("vignette", "vignette_size", "vignette_feather", "grain", "grain_darken"),
     "Uses edge falloff and grain to guide the eye and give the final image a physical character."),
)


def actions_for(lewk):
    """Return ordered educational actions sourced from the real LEWK values."""
    source = lewk.get("adjustments", {})
    actions = []
    claimed = set()
    for action_id, label, keys, why in ACTION_GROUPS:
        values = {key: source[key] for key in keys if key in source}
        if values:
            claimed.update(values)
            actions.append({"id": action_id, "label": label,
                            "values": values, "why": why})
    # Future adjustment primitives remain visible even before bespoke teaching
    # prose is added. Transparency beats silently omitting an unfamiliar step.
    for key, value in source.items():
        if key not in claimed:
            actions.append({"id": key, "label": key.replace("_", " ").title(),
                            "values": {key: value},
                            "why": "This is an explicit adjustment stored in the LEWK."})
    return actions


def _value_text(values):
    lines = []
    for key, value in values.items():
        label = key.replace("_", " ").title()
        if isinstance(value, list) and len(value) == 3 and all(
                isinstance(part, (int, float)) for part in value):
            shown = f"RGB {tuple(value)}"
        elif isinstance(value, list):
            shown = " → ".join(f"({point[0]}, {point[1]})" for point in value)
        elif isinstance(value, bool):
            shown = "On" if value else "Off"
        else:
            shown = str(value)
        lines.append(f"{label}: {shown}")
    return "\n".join(lines)


def _strength(value, full=100):
    try:
        amount = abs(float(value)) / float(full)
    except (TypeError, ValueError):
        return ""
    if amount < .12:
        return "slightly"
    if amount < .35:
        return "gently"
    if amount < .65:
        return "noticeably"
    return "strongly"


def explain_action(action):
    """Photographer language first; raw settings remain behind a button."""
    values = action["values"]
    phrases = []
    verbs = {
        "exposure": ("brightens the whole frame", "darkens the whole frame", 3),
        "brightness": ("opens up the overall brightness", "pulls the overall brightness down", 100),
        "contrast": ("separates light and dark tones", "softens the difference between light and dark", 100),
        "highlights": ("brightens the brightest areas", "holds back the brightest areas", 100),
        "shadows": ("lifts detail from the shadows", "deepens the shadows", 100),
        "whites": ("raises the white point", "restrains the white point", 100),
        "blacks": ("lifts the deepest blacks", "crushes the deepest blacks", 100),
        "temperature": ("warms the photograph", "cools the photograph", 100),
        "tint": ("moves the colour toward magenta", "moves the colour toward green", 100),
        "saturation": ("intensifies every colour", "restrains every colour", 100),
        "vibrance": ("brings up the quieter colours", "calms the quieter colours", 100),
        "texture": ("brings out fine surface detail", "smooths fine surface detail", 100),
        "clarity": ("adds midtone bite", "softens midtone edges", 100),
        "dehaze": ("cuts through haze", "adds atmospheric haze", 100),
        "vignette": ("brightens the edges", "darkens the edges to hold attention inward", 100),
        "grain": ("adds visible grain", "reduces the grain effect", 100),
    }
    for key, (positive, negative, full) in verbs.items():
        value = values.get(key)
        if isinstance(value, (int, float)) and value:
            phrases.append(f"It {_strength(value, full)} {positive if value > 0 else negative}.")
    if values.get("black_white"):
        phrases.append("It converts colour into monochrome, using the original colours to shape the greys.")
    if "curve" in values:
        phrases.append("It reshapes the tone curve so dark, middle, and bright areas respond differently.")
    if any(key.startswith("curve_") for key in values):
        phrases.append("It bends individual colour channels to create the colour character.")
    if any(key.startswith("col_") for key in values):
        if any(key.startswith("col_hue_") for key in values):
            phrases.append("It shifts selected colours around the colour wheel without tinting the whole frame.")
        else:
            phrases.append("It changes selected colours without pushing every colour together.")
    if any(key.startswith("split_") for key in values):
        phrases.append("It places colour into a chosen tonal range instead of tinting the whole frame.")
    if "photo_filter_color" in values:
        phrases.append("It lays a controlled photographic colour wash over the frame.")
    if "glow_amount" in values:
        phrases.append("It adds a positioned bloom of light to guide the eye.")
    return " ".join(phrases) or action["why"]


class TeachMeDialog(QDialog):
    def __init__(self, host, lewk, strength=100, parent=None):
        super().__init__(parent or host)
        self.host = host
        self.lewk = lewk
        self.strength = max(0, min(100, int(strength)))
        self.actions = actions_for(lewk)
        self._base = host.render_preview_image((760, 460))
        self._show_before = False

        self.setWindowTitle(f"TEACH ME · {lewk['name']}")
        self.resize(1040, 720)
        self.setStyleSheet(theme.stylesheet())

        outer = QVBoxLayout(self)
        title = QLabel(f"TEACH ME · {lewk['name']}")
        title.setObjectName("SectionTitle")
        outer.addWidget(title)
        intro = QLabel(
            f"{lewk.get('description', '')}\n\n"
            "These are the real instructions in this LEWK. Uncheck a step to see "
            "what it contributes; select it and hold BEFORE THIS STEP to compare "
            "the photograph immediately before and after that lesson.")
        intro.setWordWrap(True)
        intro.setObjectName("TargetLabel")
        outer.addWidget(intro)

        split = QSplitter(Qt.Horizontal)
        self.steps = QListWidget()
        self.steps.setMinimumWidth(310)
        for index, action in enumerate(self.actions, 1):
            item = QListWidgetItem(f"{index}. {action['label']}")
            item.setData(Qt.UserRole, index - 1)
            item.setFlags(item.flags() | Qt.ItemIsUserCheckable)
            item.setCheckState(Qt.Checked)
            self.steps.addItem(item)
        self.steps.itemChanged.connect(self._refresh_preview)
        self.steps.currentItemChanged.connect(self._show_lesson)
        split.addWidget(self.steps)

        lesson = QWidget()
        lesson_layout = QVBoxLayout(lesson)
        self.preview = QLabel("Preview unavailable")
        self.preview.setAlignment(Qt.AlignCenter)
        self.preview.setMinimumSize(QSize(520, 340))
        self.preview.setStyleSheet("background: #050505; border: 1px solid #333;")
        lesson_layout.addWidget(self.preview, 1)
        self.lesson_title = QLabel("")
        self.lesson_title.setObjectName("SectionTitle")
        lesson_layout.addWidget(self.lesson_title)
        self.why = QLabel("")
        self.why.setWordWrap(True)
        lesson_layout.addWidget(self.why)
        self.values = QLabel("")
        self.values.setWordWrap(True)
        self.values.setTextInteractionFlags(Qt.TextSelectableByMouse)
        self.values.setStyleSheet("font-family: Consolas, monospace; color: #ddd;")
        self.values.hide()
        lesson_layout.addWidget(self.values)
        self.show_settings = QPushButton("SHOW SETTINGS")
        self.show_settings.setObjectName("TeachSettingsToggle")
        self.show_settings.setCheckable(True)
        self.show_settings.toggled.connect(self._toggle_settings)
        lesson_layout.addWidget(self.show_settings, 0, Qt.AlignLeft)
        split.addWidget(lesson)
        split.setStretchFactor(1, 1)
        outer.addWidget(split, 1)

        buttons = QHBoxLayout()
        self.before = QPushButton("Hold: BEFORE THIS STEP")
        self.before.pressed.connect(self._before_pressed)
        self.before.released.connect(self._before_released)
        buttons.addWidget(self.before)
        buttons.addStretch(1)
        close = QPushButton("Close")
        close.clicked.connect(self.reject)
        buttons.addWidget(close)
        editable = QPushButton("MAKE EDITABLE COPY")
        editable.setObjectName("LayerAddBtn")
        editable.clicked.connect(self._make_editable)
        buttons.addWidget(editable)
        outer.addLayout(buttons)

        if self.steps.count():
            self.steps.setCurrentRow(0)
        self._refresh_preview()

    def _selected_index(self):
        item = self.steps.currentItem()
        return item.data(Qt.UserRole) if item is not None else None

    def _enabled_values(self, before_selected=False):
        selected = self._selected_index()
        values = {}
        for row, action in enumerate(self.actions):
            # TEACH ME is a walk through the stack, not merely a list of
            # descriptions.  The selected lesson renders the cumulative result
            # through that step; later lessons must not appear until selected.
            if selected is not None and row > selected:
                break
            if self.steps.item(row).checkState() != Qt.Checked:
                continue
            if before_selected and selected is not None and row == selected:
                continue
            values.update(action["values"])
        return values

    def _render(self):
        if self._base is None:
            return None
        values = dict(editor_engine.DEFAULT_ADJUSTMENTS)
        values.update(self._enabled_values(self._show_before))
        changed = editor_engine.apply_adjustments(self._base, values).convert("RGB")
        amount = self.strength / 100.0
        return Image.blend(self._base.convert("RGB"), changed, amount)

    def _refresh_preview(self, *_args):
        rendered = self._render()
        if rendered is not None:
            self.preview.setPixmap(pil_to_qpixmap(rendered))

    def _show_lesson(self, current, _previous=None):
        if current is None:
            return
        action = self.actions[current.data(Qt.UserRole)]
        self.lesson_title.setText(action["label"])
        self.why.setText(explain_action(action) + "\n\nWhy: " + action["why"])
        self.values.setText(_value_text(action["values"]))
        self._refresh_preview()

    def _toggle_settings(self, shown):
        self.values.setVisible(shown)
        self.show_settings.setText("HIDE SETTINGS" if shown else "SHOW SETTINGS")

    def _before_pressed(self):
        self._show_before = True
        self._refresh_preview()

    def _before_released(self):
        self._show_before = False
        self._refresh_preview()

    def _make_editable(self):
        layer = self.host.apply_lewk(self.lewk["id"], self.strength)
        if layer is not None:
            # The applied copy is selected in the editor and all of its real
            # controls are now available for experimentation.
            self.accept()


# ===== SNAPSMACK EOF =====
