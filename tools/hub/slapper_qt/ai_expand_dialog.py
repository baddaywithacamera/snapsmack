"""Generative Expand controls and explicit network disclosure."""

import os
import threading
import time

from PIL import Image
from PySide6.QtCore import QObject, Qt, Signal
from PySide6.QtWidgets import (
    QDialog, QHBoxLayout, QLabel, QLineEdit, QMessageBox, QPushButton,
    QSlider, QVBoxLayout,
)

import gemini_image_edit
import snap_creds
import snap_home
from . import BUILD_VERSION


class _Signals(QObject):
    finished = Signal(object)
    failed = Signal(str)


class AIExpandDialog(QDialog):
    def __init__(self, host):
        super().__init__(host)
        self.host = host
        self.photo = host.render_preview_image((2048, 2048)).convert("RGB")
        self.signals = _Signals()
        self.signals.finished.connect(self._received)
        self.signals.failed.connect(self._failed)
        self.setWindowTitle(f"Generative Expand — Gemini — {BUILD_VERSION}")
        self.resize(620, 300)
        layout = QVBoxLayout(self)
        title = QLabel("EXPAND THE PHOTOGRAPH BEYOND ITS CAPTURED FRAME")
        title.setObjectName("SectionTitle"); layout.addWidget(title)
        note = QLabel(
            "SNAP SLAPPER sends a working-resolution copy, the new border mask, "
            "and your instruction to Gemini. The existing photograph is restored "
            "inside the result locally and remains the source ingredient.")
        note.setWordWrap(True); layout.addWidget(note)
        row = QHBoxLayout(); row.addWidget(QLabel("Border on every side"))
        self.amount = QSlider(Qt.Horizontal); self.amount.setRange(5, 20)
        self.amount.setValue(20); row.addWidget(self.amount, 1)
        self.amount_label = QLabel("20%")
        self.amount.valueChanged.connect(
            lambda value: self.amount_label.setText(f"{value}%"))
        row.addWidget(self.amount_label); layout.addLayout(row)
        self.prompt = QLineEdit()
        self.prompt.setPlaceholderText(
            "Optional direction: continue the prairie and evening sky…")
        layout.addWidget(self.prompt)
        actions = QHBoxLayout(); actions.addStretch(1)
        cancel = QPushButton("CANCEL"); cancel.clicked.connect(self.reject)
        actions.addWidget(cancel)
        self.go = QPushButton("EXPAND WITH GEMINI"); self.go.setObjectName("LayerAddBtn")
        self.go.clicked.connect(self._start); actions.addWidget(self.go)
        layout.addLayout(actions)
        self.status = QLabel("Ready"); layout.addWidget(self.status)

    def _start(self):
        amount = self.amount.value()
        if QMessageBox.question(
                self, "Send this expansion to Gemini?",
                f"A {amount}% border on every side and the displayed instruction "
                "will leave this computer. This is a Class C generative alteration "
                "and its provenance cannot be removed from exports. Continue?",
                QMessageBox.Yes | QMessageBox.No,
                QMessageBox.No) != QMessageBox.Yes:
            return
        key = snap_creds.get("gemini_api_key", "")
        model = snap_creds.get("gemini_image_model", "gemini-3.1-flash-image")
        instruction = self.prompt.text().strip()
        self.go.setEnabled(False); self.status.setText("Gemini is extending the frame…")

        def work():
            try:
                result = gemini_image_edit.expand(
                    self.photo, amount, instruction, key, model=model)
                self.signals.finished.emit((result, model, instruction))
            except Exception as error:  # noqa: BLE001
                self.signals.failed.emit(str(error))
        threading.Thread(target=work, daemon=True).start()

    def _received(self, payload):
        (result, mask, box), model, instruction = payload
        folder = os.path.join(snap_home.shared_library(), "snap_slapper", "generative")
        os.makedirs(folder, exist_ok=True)
        path = os.path.join(folder, f"ai-expand-{int(time.time() * 1000)}.png")
        result.save(path, "PNG")
        self.host.apply_ai_expand(path, mask, box, model, instruction, self.photo)
        self.accept()

    def _failed(self, message):
        self.go.setEnabled(True); self.status.setText("Gemini could not expand the frame.")
        QMessageBox.warning(self, "Generative Expand could not finish", message)

# ===== SNAPSMACK EOF =====
