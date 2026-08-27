"""The Qt editor window — Phase 1.

Opens a photograph, shows it on a dark canvas, and drives the existing
``EditorDocument`` engine through a light/colour/presence/effects/levels rail.
Live preview, undo/redo, an unsaved indicator with a close guard, and a
metadata-preserving export. No image math lives here — only the engine's.
"""

import os

from PySide6.QtCore import Qt, QTimer
from PySide6.QtGui import QAction, QKeySequence
from PySide6.QtWidgets import (
    QMainWindow, QWidget, QVBoxLayout, QHBoxLayout, QScrollArea, QCheckBox,
    QFileDialog, QMessageBox, QLabel, QButtonGroup, QPushButton,
)

import editor_engine
from . import theme
from .engine_bridge import render_pixmap, original_pixmap
from .widgets import ImageView, SliderRow, Accordion, Histogram
from .layers_panel import LayersPanel, BASE

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


class EditorWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.doc = None
        self.rows = {}
        self.active_target = "base"   # "base" or a layer id
        self.setWindowTitle("SNAP SLAPPER")
        self.resize(1280, 820)

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

        self._refresh_actions()

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

        self.act_fit = QAction("Fit", self)
        self.act_fit.triggered.connect(lambda: self.view.fit())
        bar.addAction(self.act_fit)

        self.act_full = QAction("100%", self)
        self.act_full.triggered.connect(lambda: self.view.actual_size())
        bar.addAction(self.act_full)

        self.act_compare = QAction("Before/After", self)
        self.act_compare.setCheckable(True)
        self.act_compare.toggled.connect(self._toggle_compare)
        bar.addAction(self.act_compare)

        bar.addSeparator()

        self.act_recipe_save = QAction("Save Recipe", self)
        self.act_recipe_save.triggered.connect(self.save_recipe)
        bar.addAction(self.act_recipe_save)

        self.act_recipe_apply = QAction("Apply Recipe", self)
        self.act_recipe_apply.triggered.connect(self.apply_recipe)
        bar.addAction(self.act_recipe_apply)

        bar.addSeparator()

        self.act_save_project = QAction("Save Project", self)
        self.act_save_project.triggered.connect(self.save_project)
        bar.addAction(self.act_save_project)

        self.act_export = QAction("Export…", self)
        self.act_export.setShortcut(QKeySequence.Save)
        self.act_export.triggered.connect(self.export_image)
        bar.addAction(self.act_export)

    def _build_canvas(self):
        self.view = ImageView(self)
        self.setCentralWidget(self.view)

    def _build_rail(self):
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

        # "Editing: …" target indicator
        self.target_label = QLabel("Editing: Base image")
        self.target_label.setObjectName("TargetLabel")
        inner_layout.addWidget(self.target_label)

        for title, controls in GROUPS:
            section = Accordion(title, expanded=(title == "LIGHT"))
            if title == "LIGHT":
                section.add(self._build_histogram())
            for key, label, start, end, resolution, default in controls:
                srow = SliderRow(key, label, start, end, resolution, default)
                srow.changed.connect(self._on_adjust)
                srow.committed.connect(self._on_commit)
                self.rows[key] = srow
                section.add(srow)
            inner_layout.addWidget(section)

        # Geometry (rotate / straighten / flip)
        geo_section = Accordion("GEOMETRY", expanded=False)
        geo_section.add(self._build_geometry())
        inner_layout.addWidget(geo_section)

        # Black & white — neutral toggle + per-colour luminance mix
        bw_section = Accordion("BLACK + WHITE", expanded=False)
        self.bw_check = QCheckBox("Convert to black and white")
        self.bw_check.toggled.connect(self._on_bw)
        bw_section.add(self.bw_check)
        bw_hint = QLabel("Colour mix — how each colour becomes grey")
        bw_hint.setObjectName("TargetLabel")
        bw_section.add(bw_hint)
        for key, label in (("bw_red", "Red"), ("bw_orange", "Orange"),
                           ("bw_yellow", "Yellow"), ("bw_green", "Green"),
                           ("bw_aqua", "Aqua"), ("bw_blue", "Blue"),
                           ("bw_purple", "Purple"), ("bw_magenta", "Magenta")):
            srow = SliderRow(key, label, -100, 100, 1, 0)
            srow.changed.connect(self._on_adjust)
            srow.committed.connect(self._on_commit)
            self.rows[key] = srow
            bw_section.add(srow)
        inner_layout.addWidget(bw_section)

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

    def request_render(self):
        self._render_preview()

    def update_title(self):
        self._update_title()

    def after_structure_change(self):
        self.layers_panel.rebuild()
        self.target_label.setText(f"Editing: {self._active_name()}")
        self._sync_controls_from_doc()
        self._render_preview()
        self._update_title()

    # --- Document lifecycle -------------------------------------------------
    def open_path(self, path):
        """Open a specific image file (no dialog). Returns True on success."""
        try:
            self.doc = editor_engine.EditorDocument(path)
        except Exception as error:  # noqa: BLE001 — surface any decode failure plainly
            QMessageBox.critical(self, "Cannot open", f"Could not open this image:\n{error}")
            return False
        self.doc.on_change = lambda _doc: self._refresh_actions()
        self.active_target = BASE
        self.layers_panel.rebuild()
        self.target_label.setText("Editing: Base image")
        self._sync_controls_from_doc()
        self._render_preview(keep_view=False)
        self._update_title()
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
            self.doc = editor_engine.EditorDocument.load_project(path)
        except Exception as error:  # noqa: BLE001
            QMessageBox.critical(self, "Cannot open project", str(error))
            return
        self.doc.on_change = lambda _doc: self._refresh_actions()
        self.active_target = BASE
        self.layers_panel.rebuild()
        self.target_label.setText("Editing: Base image")
        self._sync_controls_from_doc()
        self._render_preview(keep_view=False)
        self._update_title()
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
            QMessageBox.critical(self, "Save failed", str(error))
            return
        self._update_title()
        self.status.showMessage(f"Saved {os.path.basename(path)}")

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
            QMessageBox.critical(self, "Save failed", str(error))
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
            QMessageBox.critical(self, "Apply failed", str(error))
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
            self._render_preview()
            self._update_title()

    def redo(self):
        if self.doc and self.doc.redo():
            self._validate_target()
            self.layers_panel.rebuild()
            self._sync_controls_from_doc()
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
        try:
            self.doc.export(path)
        except Exception as error:  # noqa: BLE001
            QMessageBox.critical(self, "Export failed", str(error))
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
        if self.act_compare.isChecked():
            pixmap = original_pixmap(self.doc.source_path,
                                     max_size=self.view.viewport_target())
        else:
            pixmap = render_pixmap(self.doc, max_size=self.view.viewport_target())
        self.view.set_pixmap(pixmap, keep_view=keep_view)
        self._refresh_histogram()

    def _toggle_compare(self, _checked):
        if self.doc:
            self._render_preview()

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
        self.act_recipe_save.setEnabled(has)
        self.act_recipe_apply.setEnabled(has)
        self.act_save_project.setEnabled(has)

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
            event.accept()
        else:
            event.ignore()

# ===== SNAPSMACK EOF =====
