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

        # Black & white toggle
        bw_section = Accordion("BLACK + WHITE", expanded=False)
        self.bw_check = QCheckBox("Convert to black and white")
        self.bw_check.toggled.connect(self._on_bw)
        bw_section.add(self.bw_check)
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

    def _set_hist_mode(self, mode):
        self._hist_mode = mode
        self.histogram.set_mode(mode)
        self._refresh_histogram()

    def _refresh_histogram(self):
        if self.doc:
            self.histogram.set_data(self.doc.histogram(), self._hist_mode)

    # --- Document lifecycle -------------------------------------------------
    def open_image(self):
        if not self._confirm_discard():
            return
        path, _ = QFileDialog.getOpenFileName(
            self, "Open photograph", "", IMAGE_FILTER)
        if not path:
            return
        try:
            self.doc = editor_engine.EditorDocument(path)
        except Exception as error:  # noqa: BLE001 — surface any decode failure plainly
            QMessageBox.critical(self, "Cannot open", f"Could not open this image:\n{error}")
            return
        self.doc.on_change = lambda _doc: self._refresh_actions()
        self._sync_controls_from_doc()
        self._render_preview(keep_view=False)
        self._update_title()
        self.status.showMessage(os.path.basename(path))

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
        self._sync_controls_from_doc()
        self._render_preview()
        self._update_title()
        self.status.showMessage(f"Applied {os.path.basename(path)}")

    def _on_adjust(self, key, value):
        if not self.doc:
            return
        self.doc.adjustments[key] = value
        self._schedule_render()

    def _on_commit(self, key):
        if not self.doc:
            return
        self.doc.record(f"Adjust {key.replace('_', ' ')}")
        self._update_title()

    def _on_bw(self, checked):
        if not self.doc:
            return
        self.doc.adjustments["black_white"] = bool(checked)
        self.doc.record("Black and white")
        self._schedule_render()
        self._update_title()

    def reset_all(self):
        if not self.doc:
            return
        self.doc.adjustments = editor_engine.copy.deepcopy(editor_engine.DEFAULT_ADJUSTMENTS)
        self.doc.record("Reset adjustments")
        self._sync_controls_from_doc()
        self._render_preview()
        self._update_title()

    def undo(self):
        if self.doc and self.doc.undo():
            self._sync_controls_from_doc()
            self._render_preview()
            self._update_title()

    def redo(self):
        if self.doc and self.doc.redo():
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
        if not self.doc:
            return
        for key, row in self.rows.items():
            row.set_value(self.doc.adjustments.get(key, 0))
        self.bw_check.blockSignals(True)
        self.bw_check.setChecked(bool(self.doc.adjustments.get("black_white", False)))
        self.bw_check.blockSignals(False)

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
