"""The Qt editor window — Phase 1.

Opens a photograph, shows it on a dark canvas, and drives the existing
``EditorDocument`` engine through a light/colour/presence/effects/levels rail.
Live preview, undo/redo, an unsaved indicator with a close guard, and a
metadata-preserving export. No image math lives here — only the engine's.
"""

import os
import sys

from PySide6.QtCore import Qt, QTimer, QSize
from PySide6.QtGui import QAction, QActionGroup, QKeySequence, QIcon, QPixmap
from PySide6.QtWidgets import (
    QMainWindow, QWidget, QVBoxLayout, QHBoxLayout, QScrollArea, QCheckBox,
    QFileDialog, QMessageBox, QLabel, QButtonGroup, QPushButton, QLineEdit,
    QColorDialog, QComboBox, QStackedWidget, QInputDialog, QWidgetAction,
)
from PySide6.QtGui import QColor

from PIL import ImageOps, ImageStat

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
        ("sharpen", "Sharpen", -100, 100, 1, 0),
    ]),
    ("EFFECTS", [
        ("clarity", "Clarity", -100, 100, 1, 0),
        ("dehaze", "Dehaze", -100, 100, 1, 0),
        ("grain", "Grain", -100, 100, 1, 0),
        ("texture", "Texture", -100, 100, 1, 0),
        ("vignette", "Vignette", -100, 100, 1, 0),
        ("vignette_size", "Vignette Size", 0, 100, 1, 50),
        ("vignette_feather", "Vignette Feather", 0, 100, 1, 50),
    ]),
    ("LEVELS", [
        ("level_black", "Black", 0, 254, 1, 0),
        ("level_gamma", "Gamma", 0.1, 3, 0.05, 1),
        ("level_white", "White", 1, 255, 1, 255),
    ]),
]

IMAGE_FILTER = ("Images (*.jpg *.jpeg *.png *.tif *.tiff *.webp *.bmp);;"
                "All files (*.*)")


def _default_export_name(document):
    """Use the saved project title as the export title when one exists."""
    project_path = str(document.project_path or "")
    if project_path.lower().endswith(".slapper"):
        return os.path.splitext(os.path.basename(project_path))[0] + ".jpg"
    source = os.path.splitext(os.path.basename(document.source_path))[0]
    return source + "_edited.jpg"

# Normal mode (Picasa/Snapseed-simple) shows a curated subset; Advanced shows
# everything. These name what stays visible in Normal.
NORMAL_SECTIONS = {"LIGHT", "COLOUR", "EFFECTS", "BLACK + WHITE", "GEOMETRY"}
NORMAL_ROWS = {"brightness", "contrast", "highlights", "shadows",
               "temperature", "tint", "saturation", "vibrance",
               "clarity", "dehaze", "texture", "vignette"}


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
        stored_prefs = _prefs.load()
        self._filmstrip_visible = bool(stored_prefs.get("filmstrip_visible", True))
        self._restore_maximized = bool(stored_prefs.get("editor_maximized", False))
        self._window_state_restored = False

        self._build_toolbar()
        self._build_canvas()
        self._build_rail()
        self.view.crop_rotation_changed.connect(self._rotate_from_crop)

        self.status = self.statusBar()
        self.status.showMessage("Open a photograph to begin.")

        # Debounce live renders so a slider drag doesn't render on every pixel.
        self._render_timer = QTimer(self)
        self._render_timer.setSingleShot(True)
        self._render_timer.setInterval(75)
        self._render_timer.timeout.connect(self._render_drag_preview)

        # Perspective warps are substantially dearer than tonal adjustments.
        # During a drag use a deliberately smaller proxy, then replace it with
        # the normal crisp Fit render on release.
        self._perspective_render_timer = QTimer(self)
        self._perspective_render_timer.setSingleShot(True)
        self._perspective_render_timer.setInterval(70)
        self._perspective_render_timer.timeout.connect(
            self._render_perspective_preview)

        # Opening from Windows happens before the window receives its final
        # layout. The first proxy is therefore intentionally cheap; once the
        # canvas settles, replace it with a viewport-sized render automatically.
        self._layout_render_timer = QTimer(self)
        self._layout_render_timer.setSingleShot(True)
        self._layout_render_timer.setInterval(120)
        self._layout_render_timer.timeout.connect(self._refresh_fit_resolution)
        self.view.fit_view_resized.connect(self._queue_fit_resolution_refresh)

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
        self.main_toolbar = bar
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

        self.act_reset = QAction("Reset All", self)
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

        self.act_lewk_again = QAction("LEWK AGAIN…", self)
        self.act_lewk_again.setToolTip(
            "Describe a look; the AI returns an inspectable recipe. Your photo stays local.")
        self.act_lewk_again.triggered.connect(self.open_lewk_again)
        bar.addAction(self.act_lewk_again)

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

        self.act_blog_copy = QAction("Blog Copy…", self)
        self.act_blog_copy.setToolTip(
            "Prepare a local upload copy using a profile configured in THE HUB")
        self.act_blog_copy.triggered.connect(self.prepare_blog_copy)
        bar.addAction(self.act_blog_copy)

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

        # The first row chooses a workspace.  The second row is genuinely
        # contextual: it expands only the selected workspace instead of
        # presenting the whole editor at once.
        self._context_selectors = {}
        context_group = QActionGroup(self)
        context_group.setExclusive(True)
        for key, label, tip in (
                ("edit", "EDIT", "Crop, automatic correction and comparison"),
                ("retouch", "RETOUCH", "Healing and red-eye correction"),
                ("looks", "LOOKS", "LEWKS, filters, textures and recipes"),
                ("output", "OUTPUT", "Projects, exports and blog copies"),
                ("view", "VIEW", "Zoom, filmstrip, preferences and help")):
            action = QAction(label, self)
            action.setCheckable(True)
            action.setToolTip(tip)
            action.triggered.connect(
                lambda _checked=False, selected=key:
                self._show_toolbar_context(selected))
            context_group.addAction(action)
            self._context_selectors[key] = action

        # Remove all task actions from the global row before rebuilding it in
        # its deliberately small, predictable order.
        for action in (
                self.act_reset, self.act_auto, self.act_fit, self.act_full,
                self.act_crop, self.act_heal, self.act_redeye,
                self.act_compare, self.act_filmstrip,
                self.act_recipe_save, self.act_recipe_apply,
                self.act_lewks, self.act_lewk_again, self.act_textures, self.act_filters,
                self.act_save_project, self.act_export, self.act_blog_copy,
                self.act_prefs, self.act_help):
            bar.removeAction(action)
            # Preserve shortcuts while an action belongs to a context that is
            # not currently displayed.
            self.addAction(action)

        # Discard separators left behind by the former everything-at-once
        # layout, then use one clean break before the workspace choices.
        for action in tuple(bar.actions()):
            if action.isSeparator():
                bar.removeAction(action)
        bar.addSeparator()
        # The mode controls were inserted before Undo above. Put the workspace
        # selectors after Undo/Redo, where the eye naturally looks for tools.
        for action in self._context_selectors.values():
            bar.addAction(action)

        self.addToolBarBreak(Qt.TopToolBarArea)
        tools_bar = self.addToolBar("Editing Tools")
        self.context_toolbar = tools_bar
        tools_bar.setMovable(False)

        self._toolbar_contexts = {
            "edit": (self.act_crop, self.act_auto, self.act_reset,
                     self.act_compare),
            "retouch": (self.act_heal, self.act_redeye),
            "looks": (self.act_lewks, self.act_lewk_again, self.act_filters, self.act_textures,
                      self.act_recipe_save, self.act_recipe_apply),
            "output": (self.act_save_project, self.act_export,
                       self.act_blog_copy),
            "view": (self.act_fit, self.act_full, self.act_filmstrip,
                     self.act_prefs, self.act_help),
        }
        crop_controls = QWidget()
        crop_layout = QHBoxLayout(crop_controls)
        crop_layout.setContentsMargins(8, 0, 0, 0)
        crop_layout.setSpacing(5)
        crop_label = QLabel("Aspect")
        crop_label.setObjectName("ControlName")
        crop_layout.addWidget(crop_label)
        self.crop_aspect = QComboBox()
        for label, ratio in (("Free", None), ("Original", "original"),
                             ("1 : 1", 1.0), ("4 : 3", 4 / 3),
                             ("3 : 2", 3 / 2), ("16 : 9", 16 / 9)):
            self.crop_aspect.addItem(label, ratio)
        self.crop_aspect.setToolTip("Lock the crop frame to a common aspect ratio")
        self.crop_aspect.currentIndexChanged.connect(self._on_crop_aspect)
        crop_layout.addWidget(self.crop_aspect)
        self.crop_orientation_btn = QPushButton("↕ PORTRAIT")
        self.crop_orientation_btn.setObjectName("LayerOrderBtn")
        self.crop_orientation_btn.setCursor(Qt.PointingHandCursor)
        self.crop_orientation_btn.setToolTip(
            "Swap crop orientation (for example 3:2 ↔ 2:3)")
        self.crop_orientation_btn.clicked.connect(self._swap_crop_orientation)
        crop_layout.addWidget(self.crop_orientation_btn)
        self.crop_apply_btn = QPushButton("APPLY CROP")
        self.crop_apply_btn.setObjectName("LayerAddBtn")
        self.crop_apply_btn.setCursor(Qt.PointingHandCursor)
        self.crop_apply_btn.clicked.connect(self._commit_crop)
        crop_layout.addWidget(self.crop_apply_btn)
        self.crop_cancel_btn = QPushButton("CANCEL")
        self.crop_cancel_btn.setCursor(Qt.PointingHandCursor)
        self.crop_cancel_btn.clicked.connect(self._cancel_crop)
        crop_layout.addWidget(self.crop_cancel_btn)
        self._crop_controls_action = QWidgetAction(self)
        self._crop_controls_action.setDefaultWidget(crop_controls)
        self._crop_aspect_inverted = False

        # Toolbar actions hidden in Normal mode (Advanced-only).
        self._advanced_actions = [
            self.act_open_project, self.act_heal, self.act_compare,
            self.act_recipe_save, self.act_recipe_apply, self.act_save_project,
            self.act_textures,
            self.act_filters,
        ]
        self._advanced_action_set = set(self._advanced_actions)
        self._toolbar_context = "edit"
        self._context_selectors["edit"].setChecked(True)
        self._show_toolbar_context("edit")

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

    def _show_toolbar_context(self, key):
        """Expand one top-row workspace into the contextual second row."""
        if key not in self._toolbar_contexts:
            return
        self._toolbar_context = key
        selector = self._context_selectors[key]
        if not selector.isChecked():
            selector.setChecked(True)
        self.context_toolbar.clear()
        advanced = getattr(self, "mode", "advanced") == "advanced"
        for action in self._toolbar_contexts[key]:
            if action in self._advanced_action_set and not advanced:
                continue
            self.context_toolbar.addAction(action)
        if key == "edit" and self.act_crop.isChecked():
            self.context_toolbar.addSeparator()
            self.context_toolbar.addAction(self._crop_controls_action)

    def _error(self, title, message):
        """Show an error dialog AND write it (with traceback if any) to the log."""
        _log.error("%s — %s", title, message, exc_info=sys.exc_info()[0] is not None)
        QMessageBox.critical(self, title, message)

    def _build_canvas(self):
        self.view = ImageView(self)
        self.view.cropped.connect(self._apply_crop)
        self.view.retouch_clicked.connect(self._add_retouch)
        self.view.neutral_clicked.connect(self._apply_neutral_sample)
        self.view.layer_dragged.connect(self._move_active_layer)
        self.view.perspective_corner_dragged.connect(self._move_perspective_corner)
        self._layer_drag_changed = False

        self.filmstrip = Filmstrip(self)
        self.filmstrip.open_requested.connect(self._open_from_filmstrip)
        self.filmstrip.setVisible(self._filmstrip_visible)
        self.filmstrip_handle = QPushButton()
        self.filmstrip_handle.setObjectName("FilmstripHandle")
        self.filmstrip_handle.setFixedHeight(24)
        self.filmstrip_handle.setCursor(Qt.PointingHandCursor)
        self.filmstrip_handle.setToolTip(
            "Open or close the folder thumbnail strip (Ctrl+Shift+F)")
        self.filmstrip_handle.clicked.connect(
            lambda: self.act_filmstrip.setChecked(not self.act_filmstrip.isChecked()))
        self._sync_filmstrip_handle()

        container = QWidget()
        layout = QVBoxLayout(container)
        layout.setContentsMargins(0, 0, 0, 0)
        layout.setSpacing(0)
        layout.addWidget(self.view, 1)
        layout.addWidget(self.filmstrip_handle, 0)
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
            self._sections["COLOUR"].add(self._build_neutral_picker())
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

        self.perspective_v_row = SliderRow(
            "perspective_vertical", "Vertical", -100, 100, .1, 0)
        self.perspective_h_row = SliderRow(
            "perspective_horizontal", "Horizontal", -100, 100, .1, 0)
        for row, label in ((self.perspective_v_row, "Vertical perspective"),
                           (self.perspective_h_row, "Horizontal perspective")):
            row.slider.setObjectName("PrecisionSlider")
            row.changed.connect(self._on_perspective)
            row.committed.connect(
                lambda _key, text=label: self._commit_perspective(text))
            layout.addWidget(row)

        perspective_buttons = QHBoxLayout()
        perspective_buttons.setContentsMargins(12, 2, 12, 2)
        self.free_perspective_btn = QPushButton("Free Corners")
        self.free_perspective_btn.setCheckable(True)
        self.free_perspective_btn.setCursor(Qt.PointingHandCursor)
        self.free_perspective_btn.setToolTip(
            "Drag any corner. Straight lines stay straight; this is not a bend or liquify tool.")
        self.free_perspective_btn.toggled.connect(self._toggle_free_perspective)
        perspective_buttons.addWidget(self.free_perspective_btn)
        self.perspective_edges = QComboBox()
        self.perspective_edges.addItem("Auto Crop", "auto_crop")
        self.perspective_edges.addItem("Transparent Edges", "transparent")
        self.perspective_edges.setToolTip(
            "Auto Crop removes empty edges; Transparent preserves the full canvas")
        self.perspective_edges.currentIndexChanged.connect(self._on_perspective_edges)
        perspective_buttons.addWidget(self.perspective_edges, 1)
        layout.addLayout(perspective_buttons)

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

    def _on_perspective(self, key, value):
        if not self.doc:
            return
        self.doc.geometry[key] = float(value)
        self._perspective_render_timer.start()

    def _render_perspective_preview(self):
        if not self.doc:
            return
        target = self.view.viewport_target()
        scale = min(1.0, 900.0 / max(target[0], 1),
                    700.0 / max(target[1], 1))
        proxy = (max(320, int(target[0] * scale)),
                 max(320, int(target[1] * scale)))
        self.view.set_pixmap(render_pixmap(self.doc, max_size=proxy), keep_view=True)

    def _commit_perspective(self, label):
        if not self.doc:
            return
        self._perspective_render_timer.stop()
        self.doc.record(label)
        self._render_preview(keep_view=False)
        self._update_title()

    def _toggle_free_perspective(self, enabled):
        corners = (self.doc.geometry.get("perspective_corners") if self.doc else None)
        self.view.set_perspective_mode(enabled, corners)
        if enabled:
            self.status.showMessage("Drag a red corner handle; straight lines remain straight")

    def _move_perspective_corner(self, index, x, y, finished):
        if not self.doc:
            return
        corners = [list(point) for point in self.doc.geometry.get(
            "perspective_corners", [[0, 0], [1, 0], [1, 1], [0, 1]])]
        # Keep a valid convex quadrilateral while dragging. A corner may fan
        # beyond the canvas, but it may not cross its two neighbours.
        limits = {
            0: (-0.5, corners[1][0] - .01, -0.5, corners[3][1] - .01),
            1: (corners[0][0] + .01, 1.5, -0.5, corners[2][1] - .01),
            2: (corners[3][0] + .01, 1.5, corners[1][1] + .01, 1.5),
            3: (-0.5, corners[2][0] - .01, corners[0][1] + .01, 1.5),
        }
        min_x, max_x, min_y, max_y = limits[index]
        x = max(min_x, min(max_x, float(x)))
        y = max(min_y, min(max_y, float(y)))
        corners[index] = [round(float(x), 6), round(float(y), 6)]
        self.doc.geometry["perspective_corners"] = corners
        self.view.set_perspective_corners(corners)
        if finished:
            self.doc.record("Free perspective")
            self._update_title()
        self._schedule_render()

    def _on_perspective_edges(self, index):
        if not self.doc:
            return
        value = self.perspective_edges.itemData(index) or "auto_crop"
        if self.doc.geometry.get("perspective_edges") != value:
            self.doc.geometry["perspective_edges"] = value
            self.doc.record("Perspective edges")
            self._render_preview()
            self._update_title()

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
                                  "flip_x": False, "flip_y": False,
                                  "perspective_vertical": 0.0,
                                  "perspective_horizontal": 0.0,
                                  "perspective_corners": [[0.0, 0.0], [1.0, 0.0],
                                                          [1.0, 1.0], [0.0, 1.0]],
                                  "perspective_edges": "auto_crop"})
        self.free_perspective_btn.setChecked(False)
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
                            ("brush", "Brush"), ("colour", "Colour Range")):
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

        # Colour-range page
        colour_page = QWidget()
        cl = QVBoxLayout(colour_page)
        cl.setContentsMargins(0, 0, 0, 0)
        cl.setSpacing(4)
        self.mask_hue = SliderRow("hue", "Hue", 0, 359, 1, 30)
        self.mask_hue_range = SliderRow("hue_range", "Hue range", 2, 180, 1, 30)
        self.mask_min_sat = SliderRow("min_sat", "Minimum saturation", 0, 100, 1, 10)
        self.mask_min_lum = SliderRow("min_lum", "Minimum luminance", 0, 100, 1, 0)
        self.mask_max_lum = SliderRow("max_lum", "Maximum luminance", 0, 100, 1, 100)
        self.mask_colour_soft = SliderRow("colour_soft", "Softness", 0, 100, 1, 15)
        for row in (self.mask_hue, self.mask_hue_range, self.mask_min_sat,
                    self.mask_min_lum, self.mask_max_lum, self.mask_colour_soft):
            row.committed.connect(self._reapply_mask)
            cl.addWidget(row)
        self.mask_stack.addWidget(colour_page)

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

    _MASK_PAGES = {"radial": 0, "linear": 1, "brush": 2, "colour": 3}

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

    def _apply_colour_mask(self):
        layer = self._mask_layer()
        if layer is None:
            return
        visible = layer.get("visible", True)
        layer["visible"] = False
        try:
            photo = self.doc.render(self._mask_target_size())
        finally:
            layer["visible"] = visible
        mask = masks.colour_range_mask(
            photo, self.mask_hue.slider.value(),
            self.mask_hue_range.slider.value(), self.mask_min_sat.slider.value(),
            self.mask_min_lum.slider.value(), self.mask_max_lum.slider.value(),
            self.mask_colour_soft.slider.value(), self.mask_invert.isChecked())
        self._store_mask(layer, mask, "colour", "Colour range mask")

    def _store_mask(self, layer, mask, kind, label):
        layer["mask"] = editor_engine._mask_to_text(mask)
        layer["mask_enabled"] = True
        layer["mask_kind"] = kind
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
        elif self._mask_kind == "colour":
            self._apply_colour_mask()

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
            self.view.set_crop_mode(True, self._saved_crop)
            self._on_crop_aspect(self.crop_aspect.currentIndex())
            self.status.showMessage(
                "Crop — drag handles to resize, drag inside to move, then Apply Crop.")
        else:
            self.view.set_crop_mode(False)
            if self.doc.geometry.get("crop") is None and self._saved_crop is not None:
                self.doc.geometry["crop"] = self._saved_crop   # cancelled — restore
            self._render_preview(keep_view=False)
        self._show_toolbar_context("edit")

    def _on_crop_aspect(self, index):
        if not hasattr(self, "view"):
            return
        value = self.crop_aspect.itemData(index)
        if value == "original":
            pixmap = self.view._item.pixmap()
            value = (pixmap.width() / pixmap.height()
                     if pixmap and pixmap.height() else None)
        if value and self._crop_aspect_inverted:
            value = 1.0 / float(value)
        self.view.set_crop_aspect(value)
        self._sync_crop_orientation_button(value)

    def _swap_crop_orientation(self):
        value = self.crop_aspect.itemData(self.crop_aspect.currentIndex())
        if value is None:
            return
        self._crop_aspect_inverted = not self._crop_aspect_inverted
        self._on_crop_aspect(self.crop_aspect.currentIndex())

    def _sync_crop_orientation_button(self, ratio):
        enabled = ratio is not None
        self.crop_orientation_btn.setEnabled(enabled)
        if enabled:
            self.crop_orientation_btn.setText(
                "↕ PORTRAIT" if float(ratio) >= 1.0 else "↔ LANDSCAPE")
        else:
            self.crop_orientation_btn.setText("SWAP")

    def _rotate_from_crop(self, delta):
        """Commit a corner-drag straighten while preserving the crop frame."""
        if not self.doc:
            return
        crop = self.view.crop_rect_normalized()
        self.doc.geometry["rotation"] = round(
            float(self.doc.geometry.get("rotation", 0.0)) + float(delta), 3)
        self.doc.record("Rotate crop")
        self.rotation_row.set_value(self.doc.geometry["rotation"])
        self._render_preview(keep_view=False)
        self.view.set_crop_mode(True, crop)
        self._on_crop_aspect(self.crop_aspect.currentIndex())
        self._update_title()

    def _commit_crop(self):
        rect = self.view.crop_rect_normalized()
        if rect:
            self._apply_crop(*rect)

    def _cancel_crop(self):
        if self.act_crop.isChecked():
            self.act_crop.setChecked(False)

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
        self.perspective_v_row.set_value(
            self.doc.geometry.get("perspective_vertical", 0.0))
        self.perspective_h_row.set_value(
            self.doc.geometry.get("perspective_horizontal", 0.0))
        edge_index = self.perspective_edges.findData(
            self.doc.geometry.get("perspective_edges", "auto_crop"))
        self.perspective_edges.blockSignals(True)
        self.perspective_edges.setCurrentIndex(max(0, edge_index))
        self.perspective_edges.blockSignals(False)
        self.view.set_perspective_corners(self.doc.geometry.get(
            "perspective_corners", [[0, 0], [1, 0], [1, 1], [0, 1]]))
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
        self._sync_canvas_layer_mode()

    def _active_layer(self):
        if not self.doc or self.active_target == BASE:
            return None
        return next((layer for layer in self.doc.layers
                     if layer.get("id") == self.active_target), None)

    def _sync_canvas_layer_mode(self):
        layer = self._active_layer()
        movable = bool(layer and (
            (layer.get("type") in {"image", "text"} and
             layer.get("fit", "original") == "original") or
            (layer.get("type") == "filter" and
             layer.get("filter_type") == "light_leak")))
        self.view.set_layer_move_mode(movable)

    def _move_active_layer(self, dx, dy, finished):
        layer = self._active_layer()
        if layer is None:
            return
        if not finished and (dx or dy):
            if layer.get("type") == "filter" and \
                    layer.get("filter_type") == "light_leak":
                settings = layer.setdefault("settings", {})
                edge = settings.get("edge", "left")
                delta = dy if edge in {"left", "right"} else dx
                settings["position"] = max(
                    0.0, min(100.0, float(settings.get("position", 25)) + delta * 100))
            elif layer.get("type") in {"image", "text"}:
                transform = layer.setdefault("transform", self.doc.default_transform())
                transform["x"] = max(-1.0, min(2.0, float(transform.get("x", .5)) + dx))
                transform["y"] = max(-1.0, min(2.0, float(transform.get("y", .5)) + dy))
            else:
                return
            self._layer_drag_changed = True
            self._schedule_render()
        elif finished and self._layer_drag_changed:
            self._layer_drag_changed = False
            self.doc.record("Move layer")
            self._update_title()

    def request_render(self):
        self._render_preview()

    def update_title(self):
        self._update_title()

    def after_structure_change(self):
        self.layers_panel.rebuild()
        self.target_label.setText(f"Editing: {self._active_name()}")
        self._sync_controls_from_doc()
        self._update_text_panel()
        self._sync_canvas_layer_mode()
        self._render_preview()
        self._update_title()

    # --- Document lifecycle -------------------------------------------------
    def open_path(self, path):
        """Open a specific image file (no dialog). Returns True on success."""
        if os.path.splitext(path)[1].lower() in photo_manager.RAW_EXTENSIONS:
            from .raw_handoff import offer_raw_handoff
            offer_raw_handoff(path, self)
            return False
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
        self._sync_canvas_layer_mode()
        self._render_preview(keep_view=False)
        self._update_title()
        self._refresh_filmstrip()
        self.status.showMessage(os.path.basename(path))
        return True

    def open_image(self):
        if not self._confirm_discard():
            return
        from . import prefs
        initial = prefs.load().get("library_folder", "")
        path, _ = QFileDialog.getOpenFileName(
            self, "Open photograph", initial, IMAGE_FILTER)
        if path:
            self.open_path(path)

    def open_project(self):
        if not self._confirm_discard():
            return
        from . import prefs
        initial = prefs.load().get("projects_folder", "")
        path, _ = QFileDialog.getOpenFileName(
            self, "Open SNAP SLAPPER project", initial, PROJECT_FILTER)
        if not path:
            return
        self.open_project_path(path)

    def open_project_path(self, path):
        """Open a project selected in-app or passed by Windows/the command line."""
        try:
            document = editor_engine.EditorDocument.load_project(path)
            if not self._resolve_texture_assets(document):
                return
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
        self._sync_canvas_layer_mode()
        self._render_preview(keep_view=False)
        self._update_title()
        self._refresh_filmstrip()
        self.status.showMessage(os.path.basename(path))
        return True

    def save_project(self):
        if not self.doc:
            return
        base = os.path.splitext(os.path.basename(self.doc.source_path))[0]
        from . import prefs
        project_dir = prefs.load().get("projects_folder", "")
        suggested = (os.path.join(project_dir, f"{base}.slapper")
                     if project_dir else f"{base}.slapper")
        path, _ = QFileDialog.getSaveFileName(
            self, "Save project", suggested, PROJECT_FILTER)
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
        # Normal keeps the everyday Effects controls; the grain-specific option
        # belongs with the Advanced-only Grain control.
        self.grain_darken_check.setVisible(advanced)
        for key, row in self.rows.items():
            row.setVisible(advanced or key in NORMAL_ROWS)
        self.target_label.setVisible(advanced)
        for action in self._advanced_actions:
            action.setVisible(advanced)
        self._show_toolbar_context(self._toolbar_context)
        if not advanced:
            # Normal edits the base photo; no layer panels.
            self.active_target = BASE
            self.text_section.setVisible(False)
            self.mask_section.setVisible(False)
            self._sync_controls_from_doc()
            self._sync_canvas_layer_mode()
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

    def _build_neutral_picker(self):
        """Ordinary JPEG white balance: sample a neutral patch on the photo."""
        wrap = QWidget()
        row = QHBoxLayout(wrap)
        row.setContentsMargins(12, 7, 12, 7)
        row.setSpacing(8)
        self.neutral_picker = QPushButton("Pick Neutral Colour…")
        self.neutral_picker.setCheckable(True)
        self.neutral_picker.setCursor(Qt.PointingHandCursor)
        self.neutral_picker.setToolTip(
            "Click, then click a grey card or neutral grey/white area in the photo. "
            "Avoid blown highlights and crushed blacks.")
        self.neutral_picker.toggled.connect(self._toggle_neutral_picker)
        row.addWidget(self.neutral_picker, 1)
        return wrap

    def _toggle_neutral_picker(self, checked):
        if checked and not self.doc:
            self.neutral_picker.blockSignals(True)
            self.neutral_picker.setChecked(False)
            self.neutral_picker.blockSignals(False)
            self._error("Open a photo first",
                        "Open a photograph before choosing a neutral colour.")
            return
        self.view.set_neutral_mode(checked)
        if checked:
            self.status.showMessage(
                "Neutral Picker — click a grey card or neutral grey/white area")
        else:
            self.status.showMessage("Neutral Picker off")

    def _apply_neutral_sample(self, x, y):
        """Balance temperature/tint from the median of a small rendered patch."""
        if not self.doc:
            return
        try:
            image = self.doc.render((1600, 1600)).convert("RGB")
            px = max(0, min(image.width - 1, round(x * (image.width - 1))))
            py = max(0, min(image.height - 1, round(y * (image.height - 1))))
            radius = max(3, round(min(image.size) * 0.006))
            patch = image.crop((max(0, px - radius), max(0, py - radius),
                                min(image.width, px + radius + 1),
                                min(image.height, py + radius + 1)))
            red, green, blue = ImageStat.Stat(patch).median
        except Exception as error:  # noqa: BLE001
            self._error("Neutral sample failed", str(error))
            return

        darkest, brightest = min(red, green, blue), max(red, green, blue)
        if brightest < 18:
            self.status.showMessage(
                "That sample is crushed black — choose a lighter neutral area", 7000)
            return
        if darkest > 247 or brightest >= 254:
            self.status.showMessage(
                "That sample is blown white — choose a neutral area with visible detail",
                7000)
            return

        target = self.active_adjustments()
        if target is None:
            return
        old_temperature = float(target.get("temperature", 0.0))
        old_tint = float(target.get("tint", 0.0))
        temperature = max(-100, min(100,
            old_temperature + (blue - red) * 0.5))
        tint = max(-100, min(100,
            old_tint + (((red + blue) / 2.0) - green) * 0.4))
        target["temperature"] = round(temperature)
        target["tint"] = round(tint)
        self.rows["temperature"].set_value(target["temperature"])
        self.rows["tint"].set_value(target["tint"])
        self.doc.record("Neutral white balance")
        self._render_preview()
        self._update_title()

        self.neutral_picker.blockSignals(True)
        self.neutral_picker.setChecked(False)
        self.neutral_picker.blockSignals(False)
        self.view.set_neutral_mode(False)
        self.status.showMessage(
            f"Neutral balance set from RGB {red:.0f}, {green:.0f}, {blue:.0f} — "
            f"Temperature {target['temperature']:+.0f}, Tint {target['tint']:+.0f}",
            9000)

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

    def open_lewk_again(self):
        if not self.doc:
            self._error("Open a photo first",
                        "Open a photograph before building a LEWK.")
            return
        from .lewk_again_dialog import LewkAgainDialog
        LewkAgainDialog(self).show()

    def apply_generated_lewk(self, recipe):
        """Stack a previously validated LEWK AGAIN recipe non-destructively."""
        if not self.doc or not isinstance(recipe, dict):
            return None
        # Revalidate serialized data at the application boundary. This prevents
        # a modified saved response from smuggling unsupported layer content in.
        import lewk_again
        safe = lewk_again.validate_response(
            __import__("json").dumps({
                "name": recipe.get("name"),
                "description": recipe.get("description"),
                "explanation": recipe.get("explanation", []),
                "adjustments": next((layer.get("adjustments", {}) for layer in recipe.get("layers", [])
                                     if layer.get("type") == "adjustment"), {}),
                "filters": [{"type": layer.get("filter_type"), "name": layer.get("name"),
                             "settings": layer.get("settings", {})}
                            for layer in recipe.get("layers", []) if layer.get("type") == "filter"],
            }), recipe.get("provider", ""), recipe.get("model", ""),
            recipe.get("prompt", ""))
        added = self.doc.stack_layers(safe["layers"])
        if added:
            self.set_target(added[-1]["id"])
        self.after_structure_change()
        return added[-1] if added else None

    def apply_lewk(self, lewk_id, strength=100):
        """Apply a built-in LEWK as a non-destructive adjustment layer on top,
        without flattening the photographer's existing edits."""
        if not self.doc:
            return None
        import built_in_lewks
        recipe = built_in_lewks.recipe(lewk_id, strength)
        added = self.doc.stack_layers(recipe.get("layers", []))
        if not self._resolve_texture_assets(self.doc):
            added_ids = {layer.get("id") for layer in added}
            self.doc.layers = [layer for layer in self.doc.layers
                               if layer.get("id") not in added_ids]
            return None
        if added:
            self.set_target(added[-1]["id"])
        self.after_structure_change()
        _log.info("Applied LEWK %s at %s%%", lewk_id, strength)
        return added[-1] if added else None

    def _resolve_texture_assets(self, document):
        """Resolve references, asking before a first-party network restore."""
        import texture_assets
        for position, layer in enumerate(document.layers, 1):
            ref = layer.get("asset_ref")
            if layer.get("type") != "image" or not ref:
                continue
            local = texture_assets.resolve(ref)
            if local:
                layer["path"] = local
                continue
            name = ref.get("name") or layer.get("name") or "Texture"
            if ref.get("origin") != "first-party" or not ref.get("restore_url"):
                self._error(
                    "Texture is missing",
                    f'"{name}" is missing from layer {position}. SNAP SLAPPER cannot '
                    "restore third-party textures automatically.\n\n"
                    f'Source: {ref.get("source_url") or "unknown"}')
                return False
            answer = QMessageBox.question(
                self, "Restore missing texture?",
                f'"{name}" is missing from layer {position}.\n\n'
                "Download it again from FOUND TEXTURES into the shared asset library?",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
            if answer != QMessageBox.Yes:
                self._error("Texture is missing",
                            f'"{name}" is still missing from layer {position}.')
                return False
            try:
                import found_textures
                resolved = found_textures.resolve_profile() or ("", "")
                texture_id = str(ref.get("key", "")).partition(":")[2]
                texture = {
                    "id": texture_id, "title": name,
                    "source_site": resolved[0] or "https://foundtextures.ca",
                    "source_page_url": ref.get("source_url", ""),
                    "highres_download_url": ref.get("restore_url", ""),
                    "full_url": ref.get("restore_url", ""),
                    "rights_status": ref.get("license_status", "unknown"),
                }
                local = found_textures.download(texture, resolved[1])
                layer["path"] = local
                layer["asset_ref"] = texture_assets.register(
                    layer.get("texture") or found_textures.provenance(texture), local)
            except Exception as error:  # noqa: BLE001
                self._error("Texture restore failed",
                            f'Could not restore "{name}" in layer {position}.\n\n{error}')
                return False
        return True

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
        try:
            import texture_assets
            layer["asset_ref"] = texture_assets.register(provenance, path)
        except Exception as error:  # noqa: BLE001
            _log.warning("Texture asset could not be indexed: %s", error)
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
        self._render_timer.stop()
        self.doc.record(f"Adjust {key.replace('_', ' ')}")
        self._render_preview(keep_view=False)
        self._update_title()

    def _on_bw(self, checked):
        target = self.active_adjustments()
        if target is None:
            return
        target["black_white"] = bool(checked)
        # A useful photographic starting mix, rather than a lifeless straight
        # desaturation. Warm subject tones lift; blue skies and cool shadows
        # deepen. The eight controls remain fully editable in Advanced mode.
        if checked and not any(float(target.get(key, 0.0)) for key, _deg
                               in editor_engine.BW_BANDS):
            defaults = {
                "bw_red": 10, "bw_orange": 22, "bw_yellow": 14,
                "bw_green": 5, "bw_aqua": -6, "bw_blue": -20,
                "bw_purple": -10, "bw_magenta": 6,
            }
            target.update(defaults)
            for key, value in defaults.items():
                if key in self.rows:
                    self.rows[key].set_value(value)
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
            self._set_swatch_icon(btn, rgb)

    @staticmethod
    def _set_swatch_icon(button, rgb):
        """Keep colour visible without turning the whole control into a pastel slab."""
        r, g, b = [int(c) for c in rgb][:3]
        chip = QPixmap(18, 18)
        chip.fill(QColor(r, g, b))
        button.setIcon(QIcon(chip))
        button.setIconSize(QSize(18, 18))
        button.setStyleSheet("")

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
        self._set_swatch_icon(self.photo_filter_btn, rgb)

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
        self._sync_canvas_layer_mode()

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
        default_name = _default_export_name(self.doc)
        from . import prefs
        settings = prefs.load()
        export_dir = settings.get("exports_folder", "")
        suggested = (os.path.join(export_dir, default_name)
                     if export_dir else default_name)
        path, selected_filter = QFileDialog.getSaveFileName(
            self, "Export copy", suggested,
            "JPEG — flattened (*.jpg);;PNG — flattened (*.png);;"
            "TIFF — flattened (*.tif *.tiff);;Photoshop PSD — layered checkpoints (*.psd)")
        if not path:
            return
        extension = os.path.splitext(path)[1].lower()
        if not extension:
            chosen = ".psd" if "PSD" in selected_filter else \
                ".tif" if "TIFF" in selected_filter else \
                ".png" if "PNG" in selected_filter else ".jpg"
            path += chosen
        copyright_text = (settings["copyright_text"]
                          if settings["add_copyright_if_missing"] else "")
        try:
            if os.path.splitext(path)[1].lower() == ".psd":
                from .psd_export import export_layered_psd
                export_layered_psd(self.doc, path)
            else:
                self.doc.export(path, quality=int(settings["export_quality"]),
                                copyright_text=copyright_text,
                                strip_gps=bool(settings["strip_gps"]))
        except Exception as error:  # noqa: BLE001
            self._error("Export failed", str(error))
            return
        self.doc.mark_saved()
        self._update_title()
        self.status.showMessage(f"Exported {os.path.basename(path)}")

    def prepare_blog_copy(self):
        if not self.doc:
            QMessageBox.information(self, "Blog Copy", "Open a photograph first.")
            return
        try:
            import snap_profiles
            profiles = snap_profiles.list_profiles()
        except Exception as exc:  # noqa: BLE001
            self._error("Blog Copy", f"THE HUB profiles could not be read:\n{exc}")
            return
        if not profiles:
            QMessageBox.information(
                self, "Blog Copy",
                "No shared blog profiles were found. Add the blog in THE HUB first.")
            return
        labels = [f"{p.get('name') or p.get('site_url')} — {p.get('site_url')}"
                  for p in profiles]
        label, accepted = QInputDialog.getItem(
            self, "Prepare Blog Copy", "Blog profile:", labels, 0, False)
        if not accepted:
            return
        profile = profiles[labels.index(label)]
        from . import publishing_contract
        try:
            summary = publishing_contract.describe(profile)
        except ValueError as exc:
            QMessageBox.warning(self, "Blog Copy", str(exc))
            return
        answer = QMessageBox.question(
            self, "Prepare local blog copy?",
            summary + "\n\nThis prepares a local file only. It does not upload or publish.",
            QMessageBox.Yes | QMessageBox.Cancel)
        if answer != QMessageBox.Yes:
            return
        from . import prefs
        settings = prefs.load()
        copyright_text = (settings["copyright_text"]
                          if settings["add_copyright_if_missing"] else "")
        try:
            target, _manifest, _data = publishing_contract.prepare(
                self.doc, profile, copyright_text=copyright_text)
        except (OSError, ValueError) as exc:
            QMessageBox.warning(self, "Blog copy failed", str(exc))
            return
        self.status.showMessage(
            f"Prepared {os.path.basename(target)} — ready in the local staging folder",
            9000)

    # --- Rendering ----------------------------------------------------------
    def _schedule_render(self):
        self._render_timer.start()

    def _render_drag_preview(self):
        """Fast proxy used by all continuously dragged controls."""
        if not self.doc:
            return
        target = self.view.viewport_target()
        scale = min(1.0, 960.0 / max(target[0], 1),
                    720.0 / max(target[1], 1))
        proxy = (max(320, int(target[0] * scale)),
                 max(320, int(target[1] * scale)))
        self.view.set_pixmap(render_pixmap(self.doc, max_size=proxy), keep_view=True)

    def _queue_fit_resolution_refresh(self):
        if self.doc and not self._zoom_actual:
            self._layout_render_timer.start()

    def _refresh_fit_resolution(self):
        if self.doc and not self._zoom_actual and self.view.isVisible():
            self._render_preview(keep_view=False)

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
        self._sync_filmstrip_handle()
        if self._filmstrip_visible and self.doc:
            self.filmstrip.show_for(self.doc.source_path)
        from . import prefs
        values = prefs.load()
        values["filmstrip_visible"] = self._filmstrip_visible
        prefs.save(values)

    def _sync_filmstrip_handle(self):
        if not hasattr(self, "filmstrip_handle"):
            return
        self.filmstrip_handle.setText(
            "▼  Hide folder thumbnails" if self._filmstrip_visible
            else "▲  Show folder thumbnails")

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
        self.act_lewk_again.setEnabled(has)
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
    def showEvent(self, event):  # noqa: N802 — Qt override
        """Restore maximized state once, after Qt has created the native window."""
        super().showEvent(event)
        if not self._window_state_restored:
            self._window_state_restored = True
            if self._restore_maximized:
                QTimer.singleShot(0, self.showMaximized)

    def _save_window_state(self):
        from . import prefs as _prefs
        values = _prefs.load()
        values["editor_maximized"] = self.isMaximized()
        _prefs.save(values)

    def _confirm_discard(self):
        if self.doc and self.doc.is_dirty():
            answer = QMessageBox.question(
                self, "Unsaved edits",
                "This photo has unsaved edits. Discard them?",
                QMessageBox.Discard | QMessageBox.Cancel)
            return answer == QMessageBox.Discard
        return True

    def keyPressEvent(self, event):  # noqa: N802 — Qt override
        if self.act_crop.isChecked():
            if event.key() in (Qt.Key_Return, Qt.Key_Enter):
                self._commit_crop()
                return
            if event.key() == Qt.Key_Escape:
                self._cancel_crop()
                return
        super().keyPressEvent(event)

    def closeEvent(self, event):
        if self._confirm_discard():
            self._save_window_state()
            self._clear_recovery()   # deliberate close — discard the recovery copy
            event.accept()
        else:
            event.ignore()

# ===== SNAPSMACK EOF =====
