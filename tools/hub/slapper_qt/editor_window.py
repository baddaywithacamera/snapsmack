"""The Qt editor window — Phase 1.

Opens a photograph, shows it on a dark canvas, and drives the existing
``EditorDocument`` engine through a light/colour/presence/effects/levels rail.
Live preview, undo/redo, an unsaved indicator with a close guard, and a
metadata-preserving export. No image math lives here — only the engine's.
"""

import os
import sys

from PySide6.QtCore import Qt, QTimer
from PySide6.QtGui import QAction, QKeySequence
from PySide6.QtWidgets import (
    QMainWindow, QWidget, QVBoxLayout, QHBoxLayout, QScrollArea, QCheckBox,
    QFileDialog, QMessageBox, QLabel, QButtonGroup, QPushButton, QLineEdit,
    QColorDialog, QComboBox, QStackedWidget,
)
from PySide6.QtGui import QColor

from PIL import ImageOps

from . import masks

import editor_engine
import photo_manager
from . import theme
from .engine_bridge import render_pixmap, original_pixmap
from .widgets import ImageView, SliderRow, Accordion, Histogram
from .layers_panel import LayersPanel, BASE
from .filmstrip import Filmstrip
from .mask_brush import MaskBrushCanvas
from .curve_editor import CurveEditor

try:
    import snap_log
    _log = snap_log.get("snap_slapper")
except Exception:  # noqa: BLE001
    import logging
    _log = logging.getLogger("snapsmack.snap_slapper")

PROJECT_FILTER = "SNAP SLAPPER project (*.slapper)"
RECIPE_FILTER = "SNAP SLAPPER recipe (*.slaprecipe *.json)"

# The control groups and ranges mirror the Tk editor exactly so the feel is
# identical and every key matches DEFAULT_ADJUSTMENTS in the engine.
GROUPS = [
    ("LIGHT", [
        ("exposure", "Exposure", -3, 3, 0.05, 0),
        ("brightness", "Brightness", -100, 100, 1, 0),
        ("contrast", "Contrast", -100, 100, 1, 0),
        ("highlights", "Highlights", -100, 100, 1, 0),
        ("shadows", "Shadows", -100, 100, 1, 0),
        ("whites", "Whites", -100, 100, 1, 0),
        ("blacks", "Blacks", -100, 100, 1, 0),
    ]),
    ("COLOUR", [
        ("temperature", "Temperature", -100, 100, 1, 0),
        ("tint", "Tint", -100, 100, 1, 0),
        ("saturation", "Saturation", -100, 100, 1, 0),
        ("vibrance", "Vibrance", -100, 100, 1, 0),
    ]),
    ("PRESENCE", [
        ("clarity", "Clarity", -100, 100, 1, 0),
        ("texture", "Texture", -100, 100, 1, 0),
        ("dehaze", "Dehaze", -100, 100, 1, 0),
        ("sharpen", "Sharpen", -100, 100, 1, 0),
    ]),
    ("EFFECTS", [
        ("vignette", "Vignette", -100, 100, 1, 0),
        ("vignette_feather", "Vignette Feather", 0, 100, 1, 50),
        ("grain", "Grain", -100, 100, 1, 0),
    ]),
    ("LEVELS", [
        ("level_black", "Black", 0, 254, 1, 0),
        ("level_gamma", "Gamma", 0.1, 3, 0.05, 1),
        ("level_white", "White", 1, 255, 1, 255),
    ]),
]

IMAGE_FILTER = ("Images (*.jpg *.jpeg *.png *.tif *.tiff *.webp *.bmp);;"
                "All files (*.*)")

# Normal mode (Picasa/Snapseed-simple) shows a curated subset; Advanced shows
# everything. These name what stays visible in Normal.
NORMAL_SECTIONS = {"LIGHT", "COLOUR", "BLACK + WHITE", "GEOMETRY"}
NORMAL_ROWS = {"brightness", "contrast", "highlights", "shadows",
               "temperature", "saturation"}


class EditorWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.doc = None
        self.rows = {}
        self.active_target = "base"   # "base" or a layer id
        self.setWindowTitle("SNAP SLAPPER")
        self.resize(1280, 820)

        # Zoom mode: False = Fit (fast viewport-sized proxy); True = 100% /
        # actual pixels (render at the photo's native resolution so a focus
        # check shows real detail, not an upscaled preview).
        self._zoom_actual = False
        from . import prefs as _prefs
        self._filmstrip_visible = bool(_prefs.load().get("filmstrip_visible", True))

        self._build_toolbar()
        self._build_canvas()
        self._build_rail()

        self.status = self.statusBar()
        self.status.showMessage("Open a photograph to begin.")

        # Debounce live renders so a slider drag doesn't render on every pixel.
        self._render_timer = QTimer(self)
        self._render_timer.setSingleShot(True)
        self._render_timer.setInterval(45)
        self._render_timer.timeout.connect(self._render_preview)

        # Autosave: write a crash-recovery copy a couple seconds after edits.
        self._recovery_dir = self._resolve_recovery_dir()
        self._recovery_timer = QTimer(self)
        self._recovery_timer.setSingleShot(True)
        self._recovery_timer.setInterval(2500)
        self._recovery_timer.timeout.connect(self._write_recovery)

        self._refresh_actions()
        self._init_mode()

    # --- Autosave / crash recovery ------------------------------------------
    @staticmethod
    def _resolve_recovery_dir():
        try:
            import snap_home
            directory = os.path.join(snap_home.home(), "snap_slapper", "recovery")
        except Exception:  # noqa: BLE001
            directory = os.path.join(os.path.expanduser("~"), "SnapSmack", "recovery")
        try:
            os.makedirs(directory, exist_ok=True)
        except OSError:
            return None
        return directory

    def _on_doc_change(self, _doc):
        self._refresh_actions()
        if self._recovery_dir:
            self._recovery_timer.start()

    def _recovery_path(self):
        if not self._recovery_dir or not self.doc:
            return None
        return photo_manager.recovery_path(self._recovery_dir, self.doc.source_path)

    def _write_recovery(self):
        path = self._recovery_path()
        if not path or not self.doc or not self.doc.is_dirty():
            return
        try:
            self.doc.save_recovery(path)
            _log.info("autosaved recovery: %s", path)
        except Exception:  # noqa: BLE001
            _log.exception("autosave (recovery) failed")

    def _clear_recovery(self):
        path = self._recovery_path()
        if path and os.path.isfile(path):
            try:
                os.remove(path)
            except OSError:
                pass

    def _maybe_recover(self, source_path):
        """If a newer recovery file exists for this photo, offer to restore it.
        Returns a recovered EditorDocument, or None to open normally."""
        if not self._recovery_dir:
            return None
        rec = photo_manager.recovery_path(self._recovery_dir, source_path)
        if not (rec and os.path.isfile(rec) and os.path.getsize(rec) > 0):
            return None
        answer = QMessageBox.question(
            self, "Recover unsaved edits?",
            "SNAP SLAPPER has unsaved edits for this photo from a previous "
            "session. Restore them?",
            QMessageBox.Yes | QMessageBox.No)
        if answer != QMessageBox.Yes:
            try:
                os.remove(rec)
            except OSError:
                pass
            return None
        try:
            return editor_engine.EditorDocument.load_project(rec)
        except Exception:  # noqa: BLE001
            _log.exception("recovery restore failed")
            return None

    # --- Construction -------------------------------------------------------
    def _build_toolbar(self):
        bar = self.addToolBar("Main")
        bar.setMovable(False)

        self.act_open = QAction("Open", self)
        self.act_open.setShortcut(QKeySequence.Open)
        self.act_open.triggered.connect(self.open_image)
        bar.addAction(self.act_open)

        self.act_open_project = QAction("Open Project", self)
        self.act_open_project.triggered.connect(self.open_project)
        bar.addAction(self.act_open_project)

        bar.addSeparator()

        self.act_undo = QAction("Undo", self)
        self.act_undo.setShortcut(QKeySequence.Undo)
        self.act_undo.triggered.connect(self.undo)
        bar.addAction(self.act_undo)

        self.act_redo = QAction("Redo", self)
        self.act_redo.setShortcut(QKeySequence.Redo)
        self.act_redo.triggered.connect(self.redo)
        bar.addAction(self.act_redo)

        self.act_reset = QAction("Reset", self)
        self.act_reset.triggered.connect(self.reset_all)
        bar.addAction(self.act_reset)

        bar.addSeparator()

        self.act_auto = QAction("Auto", self)
        self.act_auto.setToolTip("Auto-enhance — one-click improvement you can still tweak")
        self.act_auto.triggered.connect(self.auto_enhance)
        bar.addAction(self.act_auto)

        self.act_fit = QAction("Fit", self)
        self.act_fit.setToolTip("Fit the whole photograph to the window")
        self.act_fit.triggered.connect(self.zoom_fit)
        bar.addAction(self.act_fit)

        self.act_full = QAction("100%", self)
        self.act_full.setToolTip("Actual pixels — check focus at the photo's true resolution")
        self.act_full.triggered.connect(self.zoom_actual)
        bar.addAction(self.act_full)

        self.act_crop = QAction("Crop", self)
        self.act_crop.setCheckable(True)
        self.act_crop.toggled.connect(self._toggle_crop)
        bar.addAction(self.act_crop)

        self.act_heal = QAction("Heal", self)
        self.act_heal.setCheckable(True)
        self.act_heal.toggled.connect(lambda on: self._toggle_retouch("heal", on))
        bar.addAction(self.act_heal)

        self.act_redeye = QAction("Red-Eye", self)
        self.act_redeye.setCheckable(True)
        self.act_redeye.toggled.connect(lambda on: self._toggle_retouch("red_eye", on))
        bar.addAction(self.act_redeye)

        self.act_compare = QAction("Before/After", self)
        self.act_compare.setCheckable(True)
        self.act_compare.toggled.connect(self._toggle_compare)
        bar.addAction(self.act_compare)

        self.act_filmstrip = QAction("Filmstrip", self)
        self.act_filmstrip.setCheckable(True)
        self.act_filmstrip.setChecked(self._filmstrip_visible)
        self.act_filmstrip.setToolTip("Show or hide the folder filmstrip")
        self.act_filmstrip.toggled.connect(self._toggle_filmstrip)
        bar.addAction(self.act_filmstrip)

        bar.addSeparator()

        self.act_recipe_save = QAction("Save Recipe", self)
        self.act_recipe_save.triggered.connect(self.save_recipe)
        bar.addAction(self.act_recipe_save)

        self.act_recipe_apply = QAction("Apply Recipe", self)
        self.act_recipe_apply.triggered.connect(self.apply_recipe)
        bar.addAction(self.act_recipe_apply)

        bar.addSeparator()

        self.act_lewks = QAction("LEWKS…", self)
        self.act_lewks.triggered.connect(self.open_lewks)
        bar.addAction(self.act_lewks)

        self.act_textures = QAction("Textures…", self)
        self.act_textures.triggered.connect(self.open_textures)
        bar.addAction(self.act_textures)

        self.act_filters = QAction("Filters…", self)
        self.act_filters.triggered.connect(self.open_filters)
        bar.addAction(self.act_filters)

        self.act_save_project = QAction("Save Project", self)
        self.act_save_project.triggered.connect(self.save_project)
        bar.addAction(self.act_save_project)

        self.act_export = QAction("Export…", self)
        self.act_export.setShortcut(QKeySequence.Save)
        self.act_export.triggered.connect(self.export_image)
        bar.addAction(self.act_export)

        self.act_prefs = QAction("Preferences", self)
        self.act_prefs.triggered.connect(self.open_preferences)
        bar.addAction(self.act_prefs)

        self.act_help = QAction("Help", self)
        self.act_help.setShortcut(QKeySequence.HelpContents)   # F1
        self.act_help.triggered.connect(self.open_help)
        bar.addAction(self.act_help)

        self.act_advanced = QAction("Advanced", self)
        self.act_advanced.setCheckable(True)
        self.act_advanced.setToolTip(
            "Switch between Normal and Advanced editor modes")
        self.act_advanced.toggled.connect(self._on_mode_toggled)
        # An unchecked "Advanced" button made Normal mode effectively secret.
        # Present both choices explicitly and keep the action for its shortcut.
        self.addAction(self.act_advanced)
        bar.addSeparator()
        mode_label = QLabel("Editor mode")
        mode_label.setObjectName("ControlName")
        bar.insertWidget(self.act_undo, mode_label)
        self.mode_combo = QComboBox()
        self.mode_combo.addItem("Normal", "normal")
        self.mode_combo.addItem("Advanced", "advanced")
        self.mode_combo.setFixedWidth(112)
        self.mode_combo.setToolTip(
            "Normal shows the essential controls; Advanced shows everything")
        self.mode_combo.currentIndexChanged.connect(self._on_mode_combo_changed)
        bar.insertWidget(self.act_undo, self.mode_combo)

        # Keep essentials and the mode switch on the first row. Editing tools
        # get a dedicated second row instead of vanishing behind Qt's tiny
        # overflow chevron on ordinary laptop-sized windows.
        self.addToolBarBreak(Qt.TopToolBarArea)
        tools_bar = self.addToolBar("Editing Tools")
        tools_bar.setMovable(False)
        for action in (
                self.act_crop, self.act_heal, self.act_redeye,
                self.act_compare, self.act_filmstrip,
                self.act_recipe_save, self.act_recipe_apply,
                self.act_lewks, self.act_textures, self.act_filters,
                self.act_save_project,
                self.act_prefs):
            bar.removeAction(action)
            tools_bar.addAction(action)

        # Toolbar actions hidden in Normal mode (Advanced-only).
        self._advanced_actions = [
            self.act_open_project, self.act_heal, self.act_compare,
            self.act_recipe_save, self.act_recipe_apply, self.act_save_project,
            self.act_textures,
            self.act_filters,
        ]

        # Keyboard shortcuts. Modifier combos only (never bare letters) so they
        # can't fire while someone is typing in a text layer or a dialog. Open,
        # Undo, Redo, Export (save) and Help already carry the standard sequences.
        self._shortcuts = [
            (self.act_auto,         "Ctrl+U"),        # auto-enhance
            (self.act_fit,          "Ctrl+0"),        # fit to window
            (self.act_full,         "Ctrl+1"),        # 100% / actual pixels
            (self.act_reset,        "Ctrl+Shift+R"),  # reset all
            (self.act_crop,         "Ctrl+Shift+C"),  # crop tool
            (self.act_heal,         "Ctrl+Shift+H"),  # heal tool
            (self.act_redeye,       "Ctrl+Shift+E"),  # red-eye tool
            (self.act_compare,      "Ctrl+\\"),       # before / after
            (self.act_filmstrip,    "Ctrl+Shift+F"),  # filmstrip
            (self.act_lewks,        "Ctrl+K"),        # LEWKS browser
            (self.act_textures,     "Ctrl+T"),        # textures
            (self.act_save_project, "Ctrl+Shift+S"),  # save project
            (self.act_advanced,     "Ctrl+Shift+A"),  # normal / advanced
        ]
        for action, seq in self._shortcuts:
            action.setShortcut(QKeySequence(seq))
            base = action.toolTip() or action.text()
            pretty = QKeySequence(seq).toString(QKeySequence.NativeText)
            action.setToolTip(f"{base}  ({pretty})")

    def _error(self, title, message):
        """Show an error dialog AND write it (with traceback if any) to the log."""
        _log.error("%s — %s", title, message, exc_info=sys.exc_info()[0] is not None)
        QMessageBox.critical(self, title, message)

    def _build_canvas(self):
        self.view = ImageView(self)
        self.view.cropped.connect(self._apply_crop)
        self.view.retouch_clicked.connect(self._add_retouch)

        self.filmstrip = Filmstrip(self)
        self.filmstrip.open_requested.connect(self._open_from_filmstrip)
        self.filmstrip.setVisible(self._filmstrip_visible)

        container = QWidget()
        layout = QVBoxLayout(container)
        layout.setContentsMargins(0, 0, 0, 0)
        layout.setSpacing(0)
        layout.addWidget(self.view, 1)
        layout.addWidget(self.filmstrip, 0)
        self.setCentralWidget(container)

        self._saved_crop = None
        self._retouch_radius = 0.035
        self._retouch_type = "heal"

    def _build_rail(self):
        self._sections = {}          # title -> Accordion (for Normal/Advanced)
        self._bw_mixer_widgets = []  # the 8 colour sliders + hint (advanced only)
        rail = QWidget()
        rail.setObjectName("Rail")
        rail.setFixedWidth(288)
        layout = QVBoxLayout(rail)
        layout.setContentsMargins(0, 0, 0, 0)
        layout.setSpacing(0)

        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        inner = QWidget()
        inner_layout = QVBoxLayout(inner)
        inner_layout.setContentsMargins(0, 0, 0, 0)
        inner_layout.setSpacing(0)

        # Layers section at the top of the rail
        layers_section = Accordion("LAYERS", expanded=True)
        self.layers_panel = LayersPanel(self)
        layers_section.add(self.layers_panel)
        inner_layout.addWidget(layers_section)
        self._sections["LAYERS"] = layers_section

        # "Editing: …" target indicator
        self.target_label = QLabel("Editing: Base image")
        self.target_label.setObjectName("TargetLabel")
        inner_layout.addWidget(self.target_label)

        # Text-layer editor (only visible when a text layer is selected)
        self.text_section = Accordion("TEXT LAYER", expanded=True)
        self.text_section.add(self._build_text_panel())
        self.text_section.setVisible(False)
        inner_layout.addWidget(self.text_section)

        # Layer mask (only visible when a layer is selected)
        self.mask_section = Accordion("MASK", expanded=False)
        self.mask_section.add(self._build_mask_panel())
        self.mask_section.setVisible(False)
        inner_layout.addWidget(self.mask_section)

        for title, controls in GROUPS:
            section = Accordion(title, expanded=(title == "LIGHT"))
            if title == "LIGHT":
                self._histogram_wrap = self._build_histogram()
                section.add(self._histogram_wrap)
            for key, label, start, end, resolution, default in controls:
                srow = SliderRow(key, label, start, end, resolution, default)
                srow.changed.connect(self._on_adjust)
                srow.committed.connect(self._on_commit)
                self.rows[key] = srow
                section.add(srow)
            inner_layout.addWidget(section)
            self._sections[title] = section

        # Split-tone controls live in the COLOUR section
        if "COLOUR" in self._sections:
            self._sections["COLOUR"].add(self._build_split_tone())

        # Smart-sharpen detail controls sit under the PRESENCE Sharpen slider
        if "PRESENCE" in self._sections:
            self._sections["PRESENCE"].add(self._build_sharpen_detail())

        # Darken-only grain toggle lives with the EFFECTS controls
        self.grain_darken_check = QCheckBox("Darken-only grain (film style)")
        self.grain_darken_check.setToolTip(
            "Grain that only darkens — like real film grain — instead of also "
            "brightening the photo.")
        self.grain_darken_check.toggled.connect(self._on_grain_darken)
        if "EFFECTS" in self._sections:
            self._sections["EFFECTS"].add(self.grain_darken_check)

        # Tone curve — master + per-channel (R / G / B)
        curve_section = Accordion("TONE CURVE", expanded=False)
        self.curve_editor = CurveEditor()
        self.curve_editor.changed.connect(self._on_curve_changed)
        curve_section.add(self.curve_editor)
        inner_layout.addWidget(curve_section)
        self._sections["TONE CURVE"] = curve_section

        # Geometry (rotate / straighten / flip)
        geo_section = Accordion("GEOMETRY", expanded=False)
        geo_section.add(self._build_geometry())
        inner_layout.addWidget(geo_section)
        self._sections["GEOMETRY"] = geo_section

        # Retouch (spot heal / red-eye)
        retouch_section = Accordion("RETOUCH", expanded=False)
        retouch_section.add(self._build_retouch())
        inner_layout.addWidget(retouch_section)
        self._sections["RETOUCH"] = retouch_section

        # Black & white — neutral toggle + per-colour luminance mix
        bw_section = Accordion("BLACK + WHITE", expanded=False)
        self.bw_check = QCheckBox("Convert to black and white")
        self.bw_check.toggled.connect(self._on_bw)
        bw_section.add(self.bw_check)
        bw_hint = QLabel("Colour mix — how each colour becomes grey")
        bw_hint.setObjectName("TargetLabel")
        bw_section.add(bw_hint)
        self._bw_mixer_widgets.append(bw_hint)
        for key, label in (("bw_red", "Red"), ("bw_orange", "Orange"),
                           ("bw_yellow", "Yellow"), ("bw_green", "Green"),
                           ("bw_aqua", "Aqua"), ("bw_blue", "Blue"),
                           ("bw_purple", "Purple"), ("bw_magenta", "Magenta")):
            srow = SliderRow(key, label, -100, 100, 1, 0)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.rows[key] = srow
            bw_section.add(srow)
            self._bw_mixer_widgets.append(srow)
        inner_layout.addWidget(bw_section)
        self._sections["BLACK + WHITE"] = bw_section

        # Colour mix — per-hue saturation + luminance (HSL in colour)
        hsl_section = Accordion("COLOUR MIX", expanded=False)
        _bands = (("red", "Red"), ("orange", "Orange"), ("yellow", "Yellow"),
                  ("green", "Green"), ("aqua", "Aqua"), ("blue", "Blue"),
                  ("purple", "Purple"), ("magenta", "Magenta"))
        sat_hint = QLabel("Saturation — how vivid each colour is")
        sat_hint.setObjectName("TargetLabel")
        hsl_section.add(sat_hint)
        for band, label in _bands:
            key = f"col_sat_{band}"
            srow = SliderRow(key, label, -100, 100, 1, 0)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.rows[key] = srow
            hsl_section.add(srow)
        lum_hint = QLabel("Luminance — how light or dark each colour is")
        lum_hint.setObjectName("TargetLabel")
        hsl_section.add(lum_hint)
        for band, label in _bands:
            key = f"col_lum_{band}"
            srow = SliderRow(key, label, -100, 100, 1, 0)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.rows[key] = srow
            hsl_section.add(srow)
        inner_layout.addWidget(hsl_section)
        self._sections["COLOUR MIX"] = hsl_section

        # Glow — a placed colour bloom (centre spotlight or coloured leak)
        glow_section = Accordion("GLOW", expanded=False)
        gwrap = QWidget()
        gl = QVBoxLayout(gwrap)
        gl.setContentsMargins(12, 4, 12, 6)
        gl.setSpacing(4)
        ghint = QLabel("A soft colour bloom you can place — a centre glow or a "
                       "coloured light leak.")
        ghint.setObjectName("TargetLabel")
        ghint.setWordWrap(True)
        gl.addWidget(ghint)
        self.glow_btn = QPushButton("Glow colour")
        self.glow_btn.setObjectName("SwatchBtn")
        self.glow_btn.setCursor(Qt.PointingHandCursor)
        self.glow_btn.clicked.connect(lambda: self._pick_split_colour("glow_colour"))
        gl.addWidget(self.glow_btn)
        for key, label, lo, hi, df in (("glow_amount", "Amount", 0, 100, 0),
                                       ("glow_x", "Position X", 0, 100, 50),
                                       ("glow_y", "Position Y", 0, 100, 40),
                                       ("glow_size", "Size", 5, 100, 45)):
            srow = SliderRow(key, label, lo, hi, 1, df)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.rows[key] = srow
            gl.addWidget(srow)
        glow_section.add(gwrap)
        inner_layout.addWidget(glow_section)
        self._sections["GLOW"] = glow_section

        # Photo filter — a coloured gel over the lens (warming / cooling / colour
        # filters + a few faux-infrared washes)
        pf_section = Accordion("PHOTO FILTER", expanded=False)
        pf_section.add(self._build_photo_filter())
        inner_layout.addWidget(pf_section)
        self._sections["PHOTO FILTER"] = pf_section

        inner_layout.addStretch(1)
        scroll.setWidget(inner)
        layout.addWidget(scroll)

        from PySide6.QtWidgets import QDockWidget
        dock = QDockWidget("", self)
        dock.setTitleBarWidget(QWidget())  # no title chrome
        dock.setFeatures(QDockWidget.NoDockWidgetFeatures)
        dock.setAllowedAreas(Qt.RightDockWidgetArea)
        dock.setWidget(rail)
        self.addDockWidget(Qt.RightDockWidgetArea, dock)

    def _build_histogram(self):
        wrap = QWidget()
        layout = QVBoxLayout(wrap)
        layout.setContentsMargins(12, 6, 12, 6)
        layout.setSpacing(6)

        header = QHBoxLayout()
        header.setSpacing(4)
        title = QLabel("LIVE HISTOGRAM")
        title.setObjectName("ControlName")
        header.addWidget(title)
        header.addStretch(1)

        self._hist_mode = "luma"
        self._hist_buttons = QButtonGroup(self)
        for text, value in (("LUMA", "luma"), ("RGB", "rgb")):
            btn = QPushButton(text)
            btn.setObjectName("MiniToggle")
            btn.setCheckable(True)
            btn.setChecked(value == "luma")
            btn.setCursor(Qt.PointingHandCursor)
            btn.setFixedHeight(20)
            btn.clicked.connect(lambda _c, v=value: self._set_hist_mode(v))
            self._hist_buttons.addButton(btn)
            header.addWidget(btn)
        layout.addLayout(header)

        self.histogram = Histogram()
        layout.addWidget(self.histogram)
        return wrap

    def _build_geometry(self):
        wrap = QWidget()
        layout = QVBoxLayout(wrap)
        layout.setContentsMargins(0, 2, 0, 4)
        layout.setSpacing(4)

        self.rotation_row = SliderRow("rotation", "Rotate", -180, 180, 0.5, 0)
        self.rotation_row.changed.connect(self._on_geometry)
        self.rotation_row.committed.connect(lambda _k: self._commit_geometry("Rotate"))
        layout.addWidget(self.rotation_row)

        buttons = QHBoxLayout()
        buttons.setContentsMargins(12, 2, 12, 2)
        buttons.setSpacing(4)
        self.flip_h_btn = QPushButton("Flip H")
        self.flip_v_btn = QPushButton("Flip V")
        for btn, axis in ((self.flip_h_btn, "flip_x"), (self.flip_v_btn, "flip_y")):
            btn.setObjectName("LayerAddBtn")
            btn.setCheckable(True)
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(lambda _c, a=axis: self._flip(a))
            buttons.addWidget(btn)
        reset_geo = QPushButton("Reset")
        reset_geo.setObjectName("LayerOrderBtn")
        reset_geo.setCursor(Qt.PointingHandCursor)
        reset_geo.clicked.connect(self._reset_geometry)
        buttons.addWidget(reset_geo)
        layout.addLayout(buttons)
        return wrap

    def _on_geometry(self, _key, value):
        if not self.doc:
            return
        self.doc.geometry["rotation"] = float(value)
        self._schedule_render()

    def _commit_geometry(self, label):
        if self.doc:
            self.doc.record(label)
            self._update_title()

    def _flip(self, axis):
        if not self.doc:
            self._sync_geometry()
            return
        self.doc.geometry[axis] = not self.doc.geometry.get(axis, False)
        self.doc.record("Flip")
        self._render_preview()
        self._update_title()
        self._sync_geometry()

    def _reset_geometry(self):
        if not self.doc:
            return
        self.doc.geometry.update({"rotation": 0.0, "crop": None,
                                  "flip_x": False, "flip_y": False})
        self.doc.record("Reset geometry")
        self._sync_geometry()
        self._render_preview()
        self._update_title()

    def _build_mask_panel(self):
        wrap = QWidget()
        layout = QVBoxLayout(wrap)
        layout.setContentsMargins(12, 4, 12, 6)
        layout.setSpacing(6)

        hint = QLabel("Limit this layer to part of the photo. "
                      "Pick a mask type, then shape it.")
        hint.setObjectName("TargetLabel")
        hint.setWordWrap(True)
        layout.addWidget(hint)

        # --- Type chooser (pick first) --------------------------------------
        type_row = QHBoxLayout()
        type_row.setContentsMargins(0, 0, 0, 0)
        type_row.setSpacing(4)
        self.mask_type_group = QButtonGroup(self)
        self.mask_type_group.setExclusive(True)
        self._mask_type_buttons = {}
        for kind, label in (("radial", "Radial"), ("linear", "Graduated"),
                            ("brush", "Brush")):
            btn = QPushButton(label)
            btn.setObjectName("MaskTypeBtn")
            btn.setCheckable(True)
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(lambda _c, k=kind: self._select_mask_type(k))
            self.mask_type_group.addButton(btn)
            self._mask_type_buttons[kind] = btn
            type_row.addWidget(btn)
        layout.addLayout(type_row)

        # --- Controls that change with the type -----------------------------
        self.mask_stack = QStackedWidget()

        # Radial page
        radial_page = QWidget()
        rl = QVBoxLayout(radial_page)
        rl.setContentsMargins(0, 0, 0, 0)
        rl.setSpacing(4)
        self.mask_cx = SliderRow("cx", "Centre X", 0, 100, 1, 50)
        self.mask_cy = SliderRow("cy", "Centre Y", 0, 100, 1, 50)
        self.mask_size = SliderRow("size", "Size", 5, 100, 1, 40)
        self.mask_soft = SliderRow("soft", "Softness", 0, 60, 1, 15)
        for row in (self.mask_cx, self.mask_cy, self.mask_size, self.mask_soft):
            row.committed.connect(self._reapply_mask)   # apply on release, not per tick
            rl.addWidget(row)
        self.mask_stack.addWidget(radial_page)

        # Graduated page
        linear_page = QWidget()
        ll = QVBoxLayout(linear_page)
        ll.setContentsMargins(0, 0, 0, 0)
        ll.setSpacing(4)
        dir_row = QHBoxLayout()
        dir_row.setContentsMargins(0, 0, 0, 0)
        dir_row.setSpacing(8)
        dlabel = QLabel("Direction")
        dlabel.setObjectName("ControlName")
        dlabel.setFixedWidth(74)
        dir_row.addWidget(dlabel)
        self.mask_dir = QComboBox()
        self.mask_dir.addItems(["Top", "Bottom", "Left", "Right"])
        self.mask_dir.currentIndexChanged.connect(self._reapply_mask)
        dir_row.addWidget(self.mask_dir, 1)
        ll.addLayout(dir_row)
        self.mask_pos = SliderRow("pos", "Line position", 0, 100, 1, 50)
        self.mask_soft_lin = SliderRow("softl", "Softness", 0, 60, 1, 15)
        for row in (self.mask_pos, self.mask_soft_lin):
            row.committed.connect(self._reapply_mask)   # apply on release, not per tick
            ll.addWidget(row)
        self.mask_stack.addWidget(linear_page)

        # Brush page
        brush_page = QWidget()
        bl = QVBoxLayout(brush_page)
        bl.setContentsMargins(0, 0, 0, 0)
        bl.setSpacing(4)
        paint_hint = QLabel("Paint on the photo: Hide dims this layer, "
                            "Reveal brings it back.")
        paint_hint.setObjectName("TargetLabel")
        paint_hint.setWordWrap(True)
        bl.addWidget(paint_hint)
        self.mask_brush = MaskBrushCanvas()
        self.mask_brush.mask_changed.connect(self._store_brush_mask)
        bl.addWidget(self.mask_brush, 0, Qt.AlignHCenter)
        paint_row = QHBoxLayout()
        paint_row.setContentsMargins(0, 0, 0, 0)
        paint_row.setSpacing(4)
        self.brush_paint_group = QButtonGroup(self)
        self.brush_paint_group.setExclusive(True)
        self.btn_hide = QPushButton("Hide")
        self.btn_reveal = QPushButton("Reveal")
        for btn, white in ((self.btn_hide, False), (self.btn_reveal, True)):
            btn.setObjectName("MaskTypeBtn")
            btn.setCheckable(True)
            btn.setCursor(Qt.PointingHandCursor)
            btn.clicked.connect(lambda _c, w=white: self.mask_brush.set_paint_white(w))
            self.brush_paint_group.addButton(btn)
            paint_row.addWidget(btn)
        self.btn_hide.setChecked(True)
        bl.addLayout(paint_row)
        self.brush_size = SliderRow("brush", "Brush size", 3, 60, 1, 22)
        self.brush_size.changed.connect(
            lambda _k, v: self.mask_brush.set_radius(int(v)))
        bl.addWidget(self.brush_size)
        self.mask_stack.addWidget(brush_page)

        layout.addWidget(self.mask_stack)

        # --- Shared: invert + clear -----------------------------------------
        self.mask_invert = QCheckBox("Invert mask")
        self.mask_invert.toggled.connect(self._reapply_mask)
        layout.addWidget(self.mask_invert)

        clear_row = QHBoxLayout()
        clear_row.setContentsMargins(0, 2, 0, 0)
        clear = QPushButton("Clear mask")
        clear.setObjectName("LayerOrderBtn")
        clear.setCursor(Qt.PointingHandCursor)
        clear.clicked.connect(self._clear_mask)
        clear_row.addWidget(clear)
        layout.addLayout(clear_row)

        self._mask_kind = "radial"
        self._mask_type_buttons["radial"].setChecked(True)
        self.mask_stack.setCurrentIndex(0)
        return wrap

    _MASK_PAGES = {"radial": 0, "linear": 1, "brush": 2}

    def _select_mask_type(self, kind):
        self._mask_kind = kind
        self.mask_stack.setCurrentIndex(self._MASK_PAGES[kind])
        if kind == "brush":
            self._seed_brush()
        else:
            self._reapply_mask()

    def _mask_layer(self):
        if not self.doc or self.active_target == BASE:
            return None
        for layer in self.doc.layers:
            if layer.get("id") == self.active_target:
                return layer
        return None

    def _mask_target_size(self):
        probe = self.doc.render((256, 256))
        long_edge = 1600
        scale = long_edge / max(probe.width, probe.height)
        return (max(1, int(probe.width * scale)), max(1, int(probe.height * scale)))

    def _apply_radial_mask(self):
        layer = self._mask_layer()
        if layer is None:
            return
        mask = masks.radial_mask(
            self._mask_target_size(), self.mask_cx.slider.value() / 100.0,
            self.mask_cy.slider.value() / 100.0, self.mask_size.slider.value() / 100.0,
            self.mask_soft.slider.value() / 100.0, self.mask_invert.isChecked())
        self._store_mask(layer, mask, "radial", "Radial mask")

    def _apply_linear_mask(self):
        layer = self._mask_layer()
        if layer is None:
            return
        mask = masks.linear_mask(
            self._mask_target_size(), self.mask_dir.currentText().lower(),
            self.mask_pos.slider.value() / 100.0, self.mask_soft_lin.slider.value() / 100.0,
            self.mask_invert.isChecked())
        self._store_mask(layer, mask, "linear", "Graduated mask")

    def _seed_brush(self):
        layer = self._mask_layer()
        if layer is None:
            return
        photo = self.doc.render((476, 344))   # reference view for painting
        existing = None
        if layer.get("mask"):
            existing = editor_engine._mask_from_text(layer.get("mask", ""))
        self.mask_brush.load(photo, existing)

    def _store_brush_mask(self):
        layer = self._mask_layer()
        if layer is None:
            return
        mask = self.mask_brush.mask_pil(self._mask_target_size())
        if mask is None:
            return
        if self.mask_invert.isChecked():
            mask = ImageOps.invert(mask)
        self._store_mask(layer, mask, "brush", "Brush mask")

    def _store_mask(self, layer, mask, kind, label):
        layer["mask"] = editor_engine._mask_to_text(mask)
        layer["mask_enabled"] = True
        self.doc.record(label)
        self._render_preview()
        self._update_title()

    def _reapply_mask(self, *_args):
        # regenerate the currently-chosen mask kind (slider/invert changed)
        if self._mask_kind == "radial":
            self._apply_radial_mask()
        elif self._mask_kind == "linear":
            self._apply_linear_mask()
        elif self._mask_kind == "brush":
            self._store_brush_mask()

    def _clear_mask(self):
        layer = self._mask_layer()
        if layer is None or not layer.get("mask"):
            return
        layer["mask"] = ""
        self.doc.record("Clear mask")
        if self._mask_kind == "brush":
            self._seed_brush()
        self._render_preview()
        self._update_title()

    def _build_text_panel(self):
        wrap = QWidget()
        layout = QVBoxLayout(wrap)
        layout.setContentsMargins(12, 4, 12, 6)
        layout.setSpacing(6)

        self.text_edit = QLineEdit()
        self.text_edit.setPlaceholderText("Text…")
        self.text_edit.textEdited.connect(self._on_text_changed)
        self.text_edit.editingFinished.connect(lambda: self._commit_text("Edit text"))
        layout.addWidget(self.text_edit)

        self.text_size_row = SliderRow("font_size", "Size", 8, 400, 1, 72)
        self.text_size_row.changed.connect(self._on_text_size)
        self.text_size_row.committed.connect(lambda _k: self._commit_text("Text size"))
        layout.addWidget(self.text_size_row)

        colour_row = QHBoxLayout()
        colour_row.setContentsMargins(0, 0, 0, 0)
        colour_row.setSpacing(8)
        label = QLabel("Colour")
        label.setObjectName("ControlName")
        label.setFixedWidth(52)
        colour_row.addWidget(label)
        self.text_colour_btn = QPushButton("Choose…")
        self.text_colour_btn.setObjectName("LayerAddBtn")
        self.text_colour_btn.setCursor(Qt.PointingHandCursor)
        self.text_colour_btn.clicked.connect(self._pick_text_colour)
        colour_row.addWidget(self.text_colour_btn, 1)
        layout.addLayout(colour_row)
        return wrap

    def _text_layer(self):
        if not self.doc or self.active_target == BASE:
            return None
        for layer in self.doc.layers:
            if layer.get("id") == self.active_target and layer.get("type") == "text":
                return layer
        return None

    def _update_text_panel(self):
        # the mask panel applies to any selected layer
        self.mask_section.setVisible(self.doc is not None and self.active_target != BASE)
        layer = self._text_layer()
        self.text_section.setVisible(layer is not None)
        if not layer:
            return
        self.text_edit.blockSignals(True)
        self.text_edit.setText(layer.get("text", ""))
        self.text_edit.blockSignals(False)
        self.text_size_row.set_value(layer.get("font_size", 72))
        fill = layer.get("fill", [255, 255, 255, 255])
        self._set_colour_swatch(fill)

    def _set_colour_swatch(self, fill):
        colour = QColor(*[int(c) for c in fill[:3]])
        text = "#000" if colour.lightness() > 140 else "#fff"
        self.text_colour_btn.setStyleSheet(
            f"background:{colour.name()};color:{text};border:1px solid {theme.BORDER};"
            "border-radius:5px;padding:5px 6px;")

    def _on_text_changed(self, value):
        layer = self._text_layer()
        if layer is not None:
            layer["text"] = value
            self._schedule_render()

    def _on_text_size(self, _key, value):
        layer = self._text_layer()
        if layer is not None:
            layer["font_size"] = int(value)
            self._schedule_render()

    def _commit_text(self, label):
        if self._text_layer() is not None:
            self.doc.record(label)
            self._update_title()

    def _pick_text_colour(self):
        layer = self._text_layer()
        if layer is None:
            return
        fill = layer.get("fill", [255, 255, 255, 255])
        chosen = QColorDialog.getColor(QColor(*[int(c) for c in fill[:3]]), self,
                                       "Text colour")
        if chosen.isValid():
            layer["fill"] = [chosen.red(), chosen.green(), chosen.blue(),
                             fill[3] if len(fill) > 3 else 255]
            self._set_colour_swatch(layer["fill"])
            self.doc.record("Text colour")
            self._render_preview()
            self._update_title()

    def _build_retouch(self):
        wrap = QWidget()
        layout = QVBoxLayout(wrap)
        layout.setContentsMargins(0, 2, 0, 4)
        layout.setSpacing(4)

        hint = QLabel("Toggle Heal or Red-Eye, then click blemishes on the photo")
        hint.setObjectName("TargetLabel")
        hint.setWordWrap(True)
        layout.addWidget(hint)

        self.retouch_size_row = SliderRow("spot", "Spot size", 1, 15, 0.5, 3.5)
        self.retouch_size_row.changed.connect(
            lambda _k, v: setattr(self, "_retouch_radius", v / 100.0))
        layout.addWidget(self.retouch_size_row)

        buttons = QHBoxLayout()
        buttons.setContentsMargins(12, 2, 12, 2)
        clear = QPushButton("Clear all retouch")
        clear.setObjectName("LayerDeleteBtn")
        clear.setCursor(Qt.PointingHandCursor)
        clear.clicked.connect(self._clear_retouch)
        buttons.addWidget(clear)
        layout.addLayout(buttons)
        return wrap

    def _toggle_retouch(self, kind, on):
        if not self.doc:
            (self.act_heal if kind == "heal" else self.act_redeye).setChecked(False)
            return
        if on:
            self._retouch_type = kind
            other = self.act_redeye if kind == "heal" else self.act_heal
            if other.isChecked():
                other.setChecked(False)
            if self.act_crop.isChecked():
                self.act_crop.setChecked(False)
            self.view.set_retouch_mode(True)
            self.status.showMessage(f"{kind.replace('_', '-').title()} — click blemishes; toggle off when done")
        elif not (self.act_heal.isChecked() or self.act_redeye.isChecked()):
            self.view.set_retouch_mode(False)

    def _add_retouch(self, nx, ny):
        if not self.doc:
            return
        self.doc.retouched.append({"x": round(nx, 5), "y": round(ny, 5),
                                   "radius": self._retouch_radius,
                                   "type": self._retouch_type})
        self.doc.record("Retouch")
        self._render_preview()
        self._update_title()

    def _clear_retouch(self):
        if not self.doc or not self.doc.retouched:
            return
        self.doc.retouched = []
        self.doc.record("Clear retouch")
        self._render_preview()
        self._update_title()

    def _toggle_crop(self, checked):
        if not self.doc:
            self.act_crop.setChecked(False)
            return
        if checked:
            for act in (self.act_heal, self.act_redeye):
                if act.isChecked():
                    act.setChecked(False)
            self.view.set_retouch_mode(False)
            self._saved_crop = self.doc.geometry.get("crop")
            self.doc.geometry["crop"] = None      # show the full frame to crop on
            self._render_preview(keep_view=False)
            self.view.set_crop_mode(True)
            self.status.showMessage("Drag a rectangle to crop. Toggle Crop off to cancel.")
        else:
            self.view.set_crop_mode(False)
            if self.doc.geometry.get("crop") is None and self._saved_crop is not None:
                self.doc.geometry["crop"] = self._saved_crop   # cancelled — restore
            self._render_preview(keep_view=False)

    def _apply_crop(self, left, top, right, bottom):
        if not self.doc:
            return
        self.doc.geometry["crop"] = [round(left, 5), round(top, 5),
                                     round(right, 5), round(bottom, 5)]
        self.doc.record("Crop")
        self.act_crop.setChecked(False)   # exits crop mode, keeps the new crop
        self._update_title()

    def _sync_geometry(self):
        if not self.doc:
            return
        self.rotation_row.set_value(self.doc.geometry.get("rotation", 0.0))
        self.flip_h_btn.setChecked(bool(self.doc.geometry.get("flip_x", False)))
        self.flip_v_btn.setChecked(bool(self.doc.geometry.get("flip_y", False)))

    def _set_hist_mode(self, mode):
        self._hist_mode = mode
        self.histogram.set_mode(mode)
        self._refresh_histogram()

    def _refresh_histogram(self):
        if self.doc:
            self.histogram.set_data(self.doc.histogram(), self._hist_mode)

    # --- Edit target (base vs a layer) --------------------------------------
    def active_adjustments(self):
        """The adjustments dict the rail currently edits."""
        if not self.doc:
            return None
        if self.active_target == BASE:
            return self.doc.adjustments
        for layer in self.doc.layers:
            if layer.get("id") == self.active_target:
                return layer.setdefault("adjustments",
                                        editor_engine.copy.deepcopy(
                                            editor_engine.DEFAULT_ADJUSTMENTS))
        return self.doc.adjustments

    def _active_name(self):
        if self.active_target == BASE:
            return "Base image"
        for layer in (self.doc.layers if self.doc else []):
            if layer.get("id") == self.active_target:
                return layer.get("name", "Layer")
        return "Base image"

    # --- Host interface used by LayersPanel ---------------------------------
    def set_target(self, target):
        self.active_target = target
        self.target_label.setText(f"Editing: {self._active_name()}")
        self._sync_controls_from_doc()
        self._update_text_panel()

    def request_render(self):
        self._render_preview()

    def update_title(self):
        self._update_title()

    def after_structure_change(self):
        self.layers_panel.rebuild()
        self.target_label.setText(f"Editing: {self._active_name()}")
        self._sync_controls_from_doc()
        self._update_text_panel()
        self._render_preview()
        self._update_title()

    # --- Document lifecycle -------------------------------------------------
    def open_path(self, path):
        """Open a specific image file (no dialog). Returns True on success."""
        try:
            document = editor_engine.EditorDocument(path)
            document.render((64, 64))   # decode now so a bad file fails cleanly here
        except Exception as error:  # noqa: BLE001 — surface any decode failure plainly
            self._error("Cannot open", f"Could not open this image:\n{error}")
            return False
        recovered = self._maybe_recover(path)   # offer to restore unsaved edits
        if recovered is not None:
            document = recovered
        self.doc = document
        self.doc.on_change = self._on_doc_change
        self.active_target = BASE
        self._zoom_actual = False   # a freshly opened photo starts fitted
        self.layers_panel.rebuild()
        self.target_label.setText("Editing: Base image")
        self._sync_controls_from_doc()
        self._update_text_panel()
        self._render_preview(keep_view=False)
        self._update_title()
        self._refresh_filmstrip()
        self.status.showMessage(os.path.basename(path))
        return True

    def open_image(self):
        if not self._confirm_discard():
            return
        path, _ = QFileDialog.getOpenFileName(
            self, "Open photograph", "", IMAGE_FILTER)
        if path:
            self.open_path(path)

    def open_project(self):
        if not self._confirm_discard():
            return
        path, _ = QFileDialog.getOpenFileName(
            self, "Open SNAP SLAPPER project", "", PROJECT_FILTER)
        if not path:
            return
        try:
            document = editor_engine.EditorDocument.load_project(path)
            document.render((64, 64))   # decode the referenced photo now
        except Exception as error:  # noqa: BLE001
            self._error("Cannot open project", str(error))
            return
        self.doc = document
        self.doc.on_change = self._on_doc_change
        self.active_target = BASE
        self._zoom_actual = False   # a freshly opened project starts fitted
        self.layers_panel.rebuild()
        self.target_label.setText("Editing: Base image")
        self._sync_controls_from_doc()
        self._update_text_panel()
        self._render_preview(keep_view=False)
        self._update_title()
        self._refresh_filmstrip()
        self.status.showMessage(os.path.basename(path))

    def save_project(self):
        if not self.doc:
            return
        base = os.path.splitext(os.path.basename(self.doc.source_path))[0]
        path, _ = QFileDialog.getSaveFileName(
            self, "Save project", f"{base}.slapper", PROJECT_FILTER)
        if not path:
            return
        try:
            self.doc.save_project(path)
        except Exception as error:  # noqa: BLE001
            self._error("Save failed", str(error))
            return
        self._clear_recovery()   # project saved — recovery no longer needed
        self._update_title()
        self.status.showMessage(f"Saved {os.path.basename(path)}")

    def open_help(self):
        from .help_dialog import HelpDialog
        HelpDialog(self).show()

    def open_preferences(self):
        from .prefs_dialog import PreferencesDialog
        PreferencesDialog(self).exec()

    # --- Normal / Advanced mode ---------------------------------------------
    def _init_mode(self):
        from . import prefs
        self.mode = prefs.load().get("mode", "advanced")
        self.act_advanced.blockSignals(True)
        self.act_advanced.setChecked(self.mode == "advanced")
        self.act_advanced.blockSignals(False)
        self.mode_combo.blockSignals(True)
        index = self.mode_combo.findData(self.mode)
        self.mode_combo.setCurrentIndex(index if index >= 0 else 1)
        self.mode_combo.blockSignals(False)
        self.apply_mode(self.mode)

    def _on_mode_toggled(self, checked):
        self.apply_mode("advanced" if checked else "normal")
        self.mode_combo.blockSignals(True)
        self.mode_combo.setCurrentIndex(
            self.mode_combo.findData(self.mode))
        self.mode_combo.blockSignals(False)
        from . import prefs
        values = prefs.load()
        values["mode"] = self.mode
        prefs.save(values)

    def _on_mode_combo_changed(self, _index):
        mode = self.mode_combo.currentData() or "normal"
        self.apply_mode(mode)
        self.act_advanced.blockSignals(True)
        self.act_advanced.setChecked(mode == "advanced")
        self.act_advanced.blockSignals(False)
        from . import prefs
        values = prefs.load()
        values["mode"] = self.mode
        prefs.save(values)

    def apply_mode(self, mode):
        advanced = (mode == "advanced")
        self.mode = mode
        for title, section in self._sections.items():
            section.setVisible(advanced or title in NORMAL_SECTIONS)
        self._histogram_wrap.setVisible(advanced)
        for widget in self._bw_mixer_widgets:
            widget.setVisible(advanced)
        for key, row in self.rows.items():
            row.setVisible(advanced or key in NORMAL_ROWS)
        self.target_label.setVisible(advanced)
        for action in self._advanced_actions:
            action.setVisible(advanced)
        if not advanced:
            # Normal edits the base photo; no layer panels.
            self.active_target = BASE
            self.text_section.setVisible(False)
            self.mask_section.setVisible(False)
            self._sync_controls_from_doc()
        self.status.showMessage(
            "Advanced mode" if advanced else "Normal mode — simple editing")

    def auto_enhance(self):
        if not self.doc:
            return
        from PIL import Image, ImageOps
        try:
            with Image.open(self.doc.source_path) as source:
                small = ImageOps.exif_transpose(source).convert("RGB")
            small.thumbnail((400, 400))
            auto = editor_engine.auto_adjustments(small)
        except Exception as error:  # noqa: BLE001
            self._error("Auto-enhance failed", str(error))
            return
        target = self.active_adjustments()
        if target is None:
            return
        target.update(auto)
        self.doc.record("Auto enhance")
        self._sync_controls_from_doc()
        self._render_preview()
        self._update_title()
        self.status.showMessage("Auto-enhanced — tweak any slider to taste")

    def open_lewks(self):
        if not self.doc:
            self._error("Open a photo first",
                        "Open a photograph before applying a LEWK.")
            return
        try:
            from .lewks_dialog import LewksDialog
        except Exception as error:  # noqa: BLE001
            self._error("LEWKS unavailable", str(error))
            return
        LewksDialog(self).show()

    def apply_lewk(self, lewk_id, strength=100):
        """Apply a built-in LEWK as a non-destructive adjustment layer on top,
        without flattening the photographer's existing edits."""
        if not self.doc:
            return None
        import built_in_lewks
        recipe = built_in_lewks.recipe(lewk_id, strength)
        added = self.doc.stack_layers(recipe.get("layers", []))
        if added:
            self.set_target(added[-1]["id"])
        self.after_structure_change()
        _log.info("Applied LEWK %s at %s%%", lewk_id, strength)
        return added[-1] if added else None

    def render_preview_image(self, max_size=(160, 160)):
        """A small PIL render of the current document — for look previews."""
        if not self.doc:
            return None
        return self.doc.render(max_size=max_size)

    def open_filters(self):
        if not self.doc:
            self._error("Open a photo first",
                        "Open a photograph before adding a filter layer.")
            return
        from .filters_dialog import FiltersDialog
        layer = next((candidate for candidate in self.doc.layers
                      if candidate.get("id") == self.active_target and
                      candidate.get("type") == "filter"), None)
        FiltersDialog(self, layer).exec()

    def open_textures(self):
        if not self.doc:
            self._error("Open a photo first",
                        "Open a photograph before adding a texture layer.")
            return
        try:
            import found_textures
            from .textures_dialog import TexturesDialog
        except Exception as error:  # noqa: BLE001
            self._error("Textures unavailable", str(error))
            return
        resolved = found_textures.resolve_profile()
        if not resolved or not resolved[0]:
            self._error(
                "No Found Textures site",
                "No Found Textures site is set up in The Hub. Add the "
                "foundtextures.ca site (with its API key) in The Hub first.")
            return
        site, key = resolved
        TexturesDialog(self, site, key).show()

    def add_texture_layer(self, path, provenance, *, fit="cover",
                          blend="normal", opacity=1.0):
        """Add a downloaded texture as an image layer, with fit + provenance."""
        if not self.doc:
            return None
        layer = self.doc.add_image_layer(path, name=provenance.get("title") or "Texture")
        layer["fit"] = fit
        layer["blend"] = blend
        layer["opacity"] = float(opacity)
        layer["texture"] = dict(provenance)     # preserved in the .slapper project
        self.doc.record("Add texture")
        self.set_target(layer["id"])
        self.after_structure_change()
        _log.info("Added texture layer: %s (%s)", provenance.get("title"),
                  provenance.get("source_url"))
        return layer

    def save_recipe(self):
        if not self.doc:
            return
        path, _ = QFileDialog.getSaveFileName(
            self, "Save recipe", "look.slaprecipe", RECIPE_FILTER)
        if not path:
            return
        try:
            editor_engine.save_recipe(path, self.doc.recipe())
        except Exception as error:  # noqa: BLE001
            self._error("Save failed", str(error))
            return
        self.status.showMessage(f"Saved recipe {os.path.basename(path)}")

    def apply_recipe(self):
        if not self.doc:
            return
        path, _ = QFileDialog.getOpenFileName(
            self, "Apply recipe", "", RECIPE_FILTER)
        if not path:
            return
        try:
            self.doc.apply_recipe(editor_engine.load_recipe(path))
        except Exception as error:  # noqa: BLE001
            self._error("Apply failed", str(error))
            return
        self.layers_panel.rebuild()
        self._sync_controls_from_doc()
        self._render_preview()
        self._update_title()
        self.status.showMessage(f"Applied {os.path.basename(path)}")

    def _on_adjust(self, key, value):
        target = self.active_adjustments()
        if target is None:
            return
        target[key] = value
        self._schedule_render()

    def _on_commit(self, key):
        if not self.doc:
            return
        self.doc.record(f"Adjust {key.replace('_', ' ')}")
        self._update_title()

    def _on_bw(self, checked):
        target = self.active_adjustments()
        if target is None:
            return
        target["black_white"] = bool(checked)
        self.doc.record("Black and white")
        self._schedule_render()
        self._update_title()

    def _build_split_tone(self):
        wrap = QWidget()
        col = QVBoxLayout(wrap)
        col.setContentsMargins(0, 2, 0, 0)
        col.setSpacing(4)
        hint = QLabel("Split tone — colour the shadows and highlights")
        hint.setObjectName("TargetLabel")
        hint.setWordWrap(True)
        col.addWidget(hint)

        self.split_shadow_btn = QPushButton("Shadow colour")
        self.split_shadow_btn.setObjectName("SwatchBtn")
        self.split_shadow_btn.setCursor(Qt.PointingHandCursor)
        self.split_shadow_btn.clicked.connect(
            lambda: self._pick_split_colour("split_shadow"))
        col.addWidget(self.split_shadow_btn)
        shadow_amt = SliderRow("split_shadow_amount", "Shadows", 0, 100, 1, 0)
        shadow_amt.changed.connect(self._on_adjust)
        shadow_amt.committed.connect(self._on_commit)
        self.rows["split_shadow_amount"] = shadow_amt
        col.addWidget(shadow_amt)

        self.split_mid_btn = QPushButton("Midtone colour")
        self.split_mid_btn.setObjectName("SwatchBtn")
        self.split_mid_btn.setCursor(Qt.PointingHandCursor)
        self.split_mid_btn.clicked.connect(
            lambda: self._pick_split_colour("split_midtone"))
        col.addWidget(self.split_mid_btn)
        mid_amt = SliderRow("split_midtone_amount", "Midtones", 0, 100, 1, 0)
        mid_amt.changed.connect(self._on_adjust)
        mid_amt.committed.connect(self._on_commit)
        self.rows["split_midtone_amount"] = mid_amt
        col.addWidget(mid_amt)

        self.split_hi_btn = QPushButton("Highlight colour")
        self.split_hi_btn.setObjectName("SwatchBtn")
        self.split_hi_btn.setCursor(Qt.PointingHandCursor)
        self.split_hi_btn.clicked.connect(
            lambda: self._pick_split_colour("split_highlight"))
        col.addWidget(self.split_hi_btn)
        hi_amt = SliderRow("split_highlight_amount", "Highlights", 0, 100, 1, 0)
        hi_amt.changed.connect(self._on_adjust)
        hi_amt.committed.connect(self._on_commit)
        self.rows["split_highlight_amount"] = hi_amt
        col.addWidget(hi_amt)
        return wrap

    def _pick_split_colour(self, key):
        target = self.active_adjustments()
        if target is None:
            return
        current = target.get(key, editor_engine.DEFAULT_ADJUSTMENTS[key])
        colour = QColorDialog.getColor(
            QColor(*[int(c) for c in current]), self, "Choose split-tone colour")
        if not colour.isValid():
            return
        target[key] = [colour.red(), colour.green(), colour.blue()]
        self._update_split_swatches()
        self.doc.record("Split-tone colour")
        self._schedule_render()
        self._update_title()

    def _update_split_swatches(self):
        target = self.active_adjustments() or {}
        for key, btn in (("split_shadow", self.split_shadow_btn),
                        ("split_midtone", self.split_mid_btn),
                        ("split_highlight", self.split_hi_btn),
                        ("glow_colour", self.glow_btn)):
            rgb = target.get(key, editor_engine.DEFAULT_ADJUSTMENTS[key])
            r, g, b = [int(c) for c in rgb][:3]
            ink = "#000000" if (r * 0.299 + g * 0.587 + b * 0.114) > 140 else "#ffffff"
            # dynamic swatch colour (the user's chosen tone) — a live value, not
            # a static style: setStyleSheet is the Qt way to show a picked colour
            btn.setStyleSheet(
                f"QPushButton#SwatchBtn {{ background: rgb({r},{g},{b}); color: {ink};"
                f" border: 1px solid #333; border-radius: 4px; padding: 6px; }}")

    def _build_sharpen_detail(self):
        wrap = QWidget()
        col = QVBoxLayout(wrap)
        col.setContentsMargins(12, 2, 12, 6)
        col.setSpacing(4)
        hint = QLabel("Sharpen detail — set the Sharpen amount above, then tune "
                      "the edge width, noise guard, and edge model here.")
        hint.setObjectName("TargetLabel")
        hint.setWordWrap(True)
        col.addWidget(hint)

        radius = SliderRow("sharpen_radius", "Radius", 0.1, 6.0, 0.1, 1.2)
        radius.changed.connect(self._on_adjust)
        radius.committed.connect(self._on_commit)
        self.rows["sharpen_radius"] = radius
        col.addWidget(radius)

        reduce_noise = SliderRow("sharpen_reduce_noise", "Reduce noise", 0, 100, 1, 0)
        reduce_noise.changed.connect(self._on_adjust)
        reduce_noise.committed.connect(self._on_commit)
        self.rows["sharpen_reduce_noise"] = reduce_noise
        col.addWidget(reduce_noise)

        self.sharpen_mode_combo = QComboBox()
        self.sharpen_mode_combo.addItem("Lens Blur — finer, fewer haloes", "lens")
        self.sharpen_mode_combo.addItem("Gaussian — classic unsharp mask", "gaussian")
        self.sharpen_mode_combo.setToolTip(
            "Lens Blur confines sharpening to real edges (like Photoshop's Smart "
            "Sharpen); Gaussian is the plain unsharp mask.")
        self.sharpen_mode_combo.activated.connect(self._on_sharpen_mode)
        col.addWidget(self.sharpen_mode_combo)
        return wrap

    def _on_sharpen_mode(self, index):
        target = self.active_adjustments()
        if target is None:
            return
        target["sharpen_mode"] = self.sharpen_mode_combo.itemData(index) or "lens"
        self.doc.record("Sharpen mode")
        self._schedule_render()
        self._update_title()

    def _build_photo_filter(self):
        wrap = QWidget()
        col = QVBoxLayout(wrap)
        col.setContentsMargins(12, 4, 12, 6)
        col.setSpacing(4)
        hint = QLabel("A coloured filter over the lens — warm or cool the photo, "
                      "or wash it with a colour. Pick a preset or your own colour.")
        hint.setObjectName("TargetLabel")
        hint.setWordWrap(True)
        col.addWidget(hint)

        self.photo_filter_combo = QComboBox()
        self.photo_filter_combo.addItem("Custom", None)
        for label, rgb, density in editor_engine.PHOTO_FILTER_PRESETS:
            self.photo_filter_combo.addItem(label, [list(rgb), density])
        self.photo_filter_combo.activated.connect(self._on_photo_filter_preset)
        col.addWidget(self.photo_filter_combo)

        self.photo_filter_btn = QPushButton("Filter colour")
        self.photo_filter_btn.setObjectName("SwatchBtn")
        self.photo_filter_btn.setCursor(Qt.PointingHandCursor)
        self.photo_filter_btn.clicked.connect(self._pick_photo_filter_colour)
        col.addWidget(self.photo_filter_btn)

        density = SliderRow("photo_filter_density", "Density", 0, 100, 1, 0)
        density.changed.connect(self._on_adjust)
        density.committed.connect(self._on_commit)
        self.rows["photo_filter_density"] = density
        col.addWidget(density)

        self.photo_filter_preserve = QCheckBox("Preserve brightness")
        self.photo_filter_preserve.setChecked(True)
        self.photo_filter_preserve.setToolTip(
            "Keep the photo's original brightness and let the filter change only "
            "the colour — the standard photo-filter behaviour.")
        self.photo_filter_preserve.toggled.connect(self._on_photo_filter_preserve)
        col.addWidget(self.photo_filter_preserve)
        return wrap

    def _on_photo_filter_preset(self, index):
        data = self.photo_filter_combo.itemData(index)
        if data is None:      # "Custom" — keep whatever colour is set
            return
        target = self.active_adjustments()
        if target is None:
            return
        rgb, density = data
        target["photo_filter_color"] = [int(c) for c in rgb]
        target["photo_filter_density"] = float(density)
        self.rows["photo_filter_density"].set_value(float(density))
        self._update_photo_filter_swatch()
        self.doc.record("Photo filter")
        self._schedule_render()
        self._update_title()

    def _pick_photo_filter_colour(self):
        target = self.active_adjustments()
        if target is None:
            return
        current = target.get("photo_filter_color",
                             editor_engine.DEFAULT_ADJUSTMENTS["photo_filter_color"])
        colour = QColorDialog.getColor(
            QColor(*[int(c) for c in current[:3]]), self, "Choose filter colour")
        if not colour.isValid():
            return
        target["photo_filter_color"] = [colour.red(), colour.green(), colour.blue()]
        self.photo_filter_combo.setCurrentIndex(0)   # Custom
        self._update_photo_filter_swatch()
        self.doc.record("Photo filter colour")
        self._schedule_render()
        self._update_title()

    def _on_photo_filter_preserve(self, checked):
        target = self.active_adjustments()
        if target is None:
            return
        target["photo_filter_preserve_lum"] = bool(checked)
        self.doc.record("Photo filter")
        self._schedule_render()
        self._update_title()

    def _update_photo_filter_swatch(self):
        target = self.active_adjustments() or {}
        rgb = target.get("photo_filter_color",
                         editor_engine.DEFAULT_ADJUSTMENTS["photo_filter_color"])
        r, g, b = [int(c) for c in rgb][:3]
        ink = "#000000" if (r * 0.299 + g * 0.587 + b * 0.114) > 140 else "#ffffff"
        # dynamic swatch colour (the user's chosen filter) — a live value, not a
        # static style: setStyleSheet is the Qt way to show a picked colour.
        self.photo_filter_btn.setStyleSheet(
            f"QPushButton#SwatchBtn {{ background: rgb({r},{g},{b}); color: {ink};"
            f" border: 1px solid #333; border-radius: 4px; padding: 6px; }}")

    def _sync_photo_filter_combo(self, adjustments):
        rgb = [int(c) for c in adjustments.get(
            "photo_filter_color",
            editor_engine.DEFAULT_ADJUSTMENTS["photo_filter_color"])[:3]]
        self.photo_filter_combo.blockSignals(True)
        matched = 0     # default to Custom
        for i in range(self.photo_filter_combo.count()):
            data = self.photo_filter_combo.itemData(i)
            if data and [int(c) for c in data[0][:3]] == rgb:
                matched = i
                break
        self.photo_filter_combo.setCurrentIndex(matched)
        self.photo_filter_combo.blockSignals(False)

    def _on_grain_darken(self, checked):
        target = self.active_adjustments()
        if target is None:
            return
        target["grain_darken"] = bool(checked)
        self.doc.record("Grain style")
        self._schedule_render()
        self._update_title()

    def _on_curve_changed(self, key, points):
        target = self.active_adjustments()
        if target is None:
            return
        target[key] = points
        self.doc.record("Tone curve")
        self._schedule_render()
        self._update_title()

    def reset_all(self):
        target = self.active_adjustments()
        if target is None:
            return
        for key, value in editor_engine.DEFAULT_ADJUSTMENTS.items():
            target[key] = editor_engine.copy.deepcopy(value)
        self.doc.record("Reset adjustments")
        self._sync_controls_from_doc()
        self._render_preview()
        self._update_title()

    def _validate_target(self):
        if self.active_target == BASE:
            return
        ids = {layer.get("id") for layer in (self.doc.layers if self.doc else [])}
        if self.active_target not in ids:
            self.active_target = BASE
        self.target_label.setText(f"Editing: {self._active_name()}")

    def undo(self):
        if self.doc and self.doc.undo():
            self._validate_target()
            self.layers_panel.rebuild()
            self._sync_controls_from_doc()
            self._update_text_panel()
            self._render_preview()
            self._update_title()

    def redo(self):
        if self.doc and self.doc.redo():
            self._validate_target()
            self.layers_panel.rebuild()
            self._sync_controls_from_doc()
            self._update_text_panel()
            self._render_preview()
            self._update_title()

    def export_image(self):
        if not self.doc:
            return
        base = os.path.splitext(os.path.basename(self.doc.source_path))[0]
        path, _ = QFileDialog.getSaveFileName(
            self, "Export copy", f"{base}_edited.jpg",
            "JPEG (*.jpg);;PNG (*.png)")
        if not path:
            return
        from . import prefs
        settings = prefs.load()
        copyright_text = (settings["copyright_text"]
                          if settings["add_copyright_if_missing"] else "")
        try:
            self.doc.export(path, quality=int(settings["export_quality"]),
                            copyright_text=copyright_text,
                            strip_gps=bool(settings["strip_gps"]))
        except Exception as error:  # noqa: BLE001
            self._error("Export failed", str(error))
            return
        self.doc.mark_saved()
        self._update_title()
        self.status.showMessage(f"Exported {os.path.basename(path)}")

    # --- Rendering ----------------------------------------------------------
    def _schedule_render(self):
        self._render_timer.start()

    def _render_preview(self, keep_view=True):
        if not self.doc:
            return
        # At 100% we render the photograph at its native resolution so the
        # canvas shows true pixels (a real focus check); when Fit, we render a
        # fast proxy capped to the window so slider drags stay smooth.
        max_size = None if self._zoom_actual else self.view.viewport_target()
        if self.act_compare.isChecked():
            edited = render_pixmap(self.doc, max_size=max_size)
            original = original_pixmap(self.doc.source_path, max_size=max_size)
            self.view.set_compare(original, edited, keep_view=keep_view)
        else:
            pixmap = render_pixmap(self.doc, max_size=max_size)
            self.view.set_pixmap(pixmap, keep_view=keep_view)
        self._refresh_histogram()

    def zoom_fit(self):
        """Fit the whole photograph to the window (fast preview resolution)."""
        self._zoom_actual = False
        if self.doc:
            self._render_preview(keep_view=False)
        else:
            self.view.fit()

    def zoom_actual(self):
        """Show actual pixels — re-render at native resolution, then 1:1."""
        self._zoom_actual = True
        if self.doc:
            self._render_preview(keep_view=True)
        self.view.actual_size()

    def _toggle_compare(self, checked):
        if checked:
            self.view.reset_divider()
        else:
            self.view.clear_compare()
        if self.doc:
            self._render_preview()

    # --- Filmstrip ----------------------------------------------------------
    def _toggle_filmstrip(self, checked):
        self._filmstrip_visible = bool(checked)
        self.filmstrip.setVisible(self._filmstrip_visible)
        if self._filmstrip_visible and self.doc:
            self.filmstrip.show_for(self.doc.source_path)
        from . import prefs
        values = prefs.load()
        values["filmstrip_visible"] = self._filmstrip_visible
        prefs.save(values)

    def _refresh_filmstrip(self):
        if self._filmstrip_visible and self.doc:
            self.filmstrip.show_for(self.doc.source_path)

    def _open_from_filmstrip(self, path):
        if not self._confirm_discard():
            self._refresh_filmstrip()   # bounce selection back to the open photo
            return
        self.open_path(path)

    # --- UI sync ------------------------------------------------------------
    def _sync_controls_from_doc(self):
        adjustments = self.active_adjustments()
        if adjustments is None:
            return
        for key, row in self.rows.items():
            row.set_value(adjustments.get(key, editor_engine.DEFAULT_ADJUSTMENTS.get(key, 0)))
        self.bw_check.blockSignals(True)
        self.bw_check.setChecked(bool(adjustments.get("black_white", False)))
        self.bw_check.blockSignals(False)
        self.grain_darken_check.blockSignals(True)
        self.grain_darken_check.setChecked(bool(adjustments.get("grain_darken", False)))
        self.grain_darken_check.blockSignals(False)
        self.photo_filter_preserve.blockSignals(True)
        self.photo_filter_preserve.setChecked(
            bool(adjustments.get("photo_filter_preserve_lum", True)))
        self.photo_filter_preserve.blockSignals(False)
        self._update_photo_filter_swatch()
        self._sync_photo_filter_combo(adjustments)
        self.sharpen_mode_combo.blockSignals(True)
        self.sharpen_mode_combo.setCurrentIndex(
            0 if adjustments.get("sharpen_mode", "lens") == "lens" else 1)
        self.sharpen_mode_combo.blockSignals(False)
        self._update_split_swatches()
        self.curve_editor.set_curves(adjustments)
        self._sync_geometry()

    def _refresh_actions(self):
        has = self.doc is not None
        self.act_undo.setEnabled(has and self.doc.history_index > 0)
        self.act_redo.setEnabled(has and self.doc.history_index + 1 < len(self.doc.history))
        self.act_export.setEnabled(has)
        self.act_reset.setEnabled(has)
        self.act_fit.setEnabled(has)
        self.act_full.setEnabled(has)
        self.act_compare.setEnabled(has)
        self.act_crop.setEnabled(has)
        self.act_heal.setEnabled(has)
        self.act_redeye.setEnabled(has)
        self.act_recipe_save.setEnabled(has)
        self.act_recipe_apply.setEnabled(has)
        self.act_save_project.setEnabled(has)
        self.act_textures.setEnabled(has)
        self.act_lewks.setEnabled(has)
        self.act_auto.setEnabled(has)

    def _update_title(self):
        if not self.doc:
            self.setWindowTitle("SNAP SLAPPER")
            return
        name = os.path.basename(self.doc.source_path)
        dirty = " ●" if self.doc.is_dirty() else ""
        self.setWindowTitle(f"{name}{dirty} — SNAP SLAPPER")
        self._refresh_actions()

    # --- Close guard --------------------------------------------------------
    def _confirm_discard(self):
        if self.doc and self.doc.is_dirty():
            answer = QMessageBox.question(
                self, "Unsaved edits",
                "This photo has unsaved edits. Discard them?",
                QMessageBox.Discard | QMessageBox.Cancel)
            return answer == QMessageBox.Discard
        return True

    def closeEvent(self, event):
        if self._confirm_discard():
            self._clear_recovery()   # deliberate close — discard the recovery copy
            event.accept()
        else:
            event.ignore()

# ===== SNAPSMACK EOF =====
