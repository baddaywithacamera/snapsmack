"""Paint a defect, send a working copy to Gemini, and return a masked repair."""

import os
import threading
import time

from PIL import Image
from PySide6.QtCore import QObject, Signal
from PySide6.QtWidgets import (
    QDialog, QDialogButtonBox, QHBoxLayout, QLabel, QLineEdit, QMessageBox,
    QPushButton, QSlider, QVBoxLayout,
)
from PySide6.QtCore import Qt

import gemini_image_edit
import snap_creds
import snap_home
from .mask_brush import MaskBrushCanvas
from . import BUILD_VERSION


class _Signals(QObject):
    finished = Signal(object)
    failed = Signal(str)


class AIHealDialog(QDialog):
    def __init__(self, host):
        super().__init__(host)
        self.host = host
        self.photo = host.render_preview_image((2048, 2048)).convert("RGB")
        self.signals = _Signals()
        self.signals.finished.connect(self._received)
        self.signals.failed.connect(self._failed)
        self.setWindowTitle(f"AI Heal — Gemini — {BUILD_VERSION}")
        self.resize(900, 720)
        layout = QVBoxLayout(self)
        title = QLabel("PAINT OVER THE DEFECT")
        title.setObjectName("SectionTitle")
        layout.addWidget(title)
        note = QLabel(
            "The red area is what Gemini may rebuild. Everything outside it is "
            "preserved locally, pixel for pixel.")
        note.setWordWrap(True); layout.addWidget(note)
        self.canvas = MaskBrushCanvas(self, box_size=(820, 500), tint_white=True)
        self.canvas.load(self.photo, Image.new("L", self.photo.size, 0))
        self.canvas.set_paint_white(True)
        layout.addWidget(self.canvas, 1, Qt.AlignCenter)
        controls = QHBoxLayout()
        controls.addWidget(QLabel("Brush size"))
        size = QSlider(Qt.Horizontal); size.setRange(4, 100); size.setValue(28)
        size.valueChanged.connect(self.canvas.set_radius); controls.addWidget(size, 1)
        clear = QPushButton("CLEAR SELECTION"); clear.clicked.connect(lambda: self.canvas.fill(False))
        controls.addWidget(clear); layout.addLayout(controls)
        self.prompt = QLineEdit()
        self.prompt.setPlaceholderText("Optional: remove the wire; continue the brick pattern…")
        layout.addWidget(self.prompt)
        actions = QHBoxLayout(); actions.addStretch(1)
        cancel = QPushButton("CANCEL"); cancel.clicked.connect(self.reject); actions.addWidget(cancel)
        self.go = QPushButton("HEAL SELECTED AREA"); self.go.setObjectName("LayerAddBtn")
        self.go.clicked.connect(self._start); actions.addWidget(self.go); layout.addLayout(actions)
        self.status = QLabel("Ready"); layout.addWidget(self.status)

    def _start(self):
        mask = self.canvas.mask_pil(self.photo.size)
        if mask is None or mask.getbbox() is None:
            QMessageBox.information(self, "Select the defect", "Paint over the defect first.")
            return
        if QMessageBox.question(
                self, "Send this repair to Gemini?",
                "SNAP SLAPPER will send a working-resolution copy of this photograph, "
                "the painted mask, and your instruction to Google Gemini. This may incur "
                "an API charge. Continue?",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
            return
        key = snap_creds.get("gemini_api_key", "")
        model = snap_creds.get("gemini_image_model", "gemini-3.1-flash-image")
        self.go.setEnabled(False); self.status.setText("Gemini is rebuilding the selected area…")
        def work():
            try:
                result = gemini_image_edit.heal(
                    self.photo, mask, self.prompt.text(), key, model=model)
                self.signals.finished.emit((result, mask, model))
            except Exception as error:  # noqa: BLE001
                self.signals.failed.emit(str(error))
        threading.Thread(target=work, daemon=True).start()

    def _received(self, payload):
        result, mask, model = payload
        result = result.resize(self.photo.size, Image.Resampling.LANCZOS)
        # The mask remains the final authority; Gemini never gets to replace the
        # rest of the frame. A scaled overlap avoids a visible pasted boundary.
        feathered = gemini_image_edit.blend_mask(mask)
        folder = os.path.join(snap_home.shared_library(), "snap_slapper", "generative")
        os.makedirs(folder, exist_ok=True)
        path = os.path.join(folder, f"ai-heal-{int(time.time() * 1000)}.png")
        result.save(path, "PNG")
        self.host.apply_ai_heal(path, feathered, model, self.prompt.text().strip())
        self.accept()

    def _failed(self, message):
        self.go.setEnabled(True); self.status.setText("Gemini could not finish the repair.")
        QMessageBox.warning(self, "AI Heal could not finish", message)

# ===== SNAPSMACK EOF =====
