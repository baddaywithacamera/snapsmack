"""Paint a selection and repair/fill it with Gemini or an optional local model."""

import os
import threading
import time

from PIL import Image
from PySide6.QtCore import QObject, Signal, Qt
from PySide6.QtWidgets import (
    QComboBox, QDialog, QHBoxLayout, QLabel, QLineEdit, QMessageBox,
    QPushButton, QSlider, QVBoxLayout,
)

import gemini_image_edit
import snap_creds
import snap_home
from .mask_brush import MaskBrushCanvas
from . import BUILD_VERSION, local_fill
from .generative_consent import confirm_send


class _Signals(QObject):
    finished = Signal(object)
    failed = Signal(str)


class AIHealDialog(QDialog):
    def __init__(self, host, operation="heal"):
        super().__init__(host)
        self.host = host
        self.operation = operation
        self.is_fill = operation == "fill"
        self.photo = host.render_preview_image((2048, 2048)).convert("RGB")
        self.signals = _Signals()
        self.signals.finished.connect(self._received)
        self.signals.failed.connect(self._failed)
        tool_name = "Generative Fill" if self.is_fill else "AI Heal"
        self.setWindowTitle(f"{tool_name} — {BUILD_VERSION}")
        self.resize(900, 720)
        layout = QVBoxLayout(self)
        title = QLabel("PAINT WHERE CONTENT SHOULD GO" if self.is_fill else
                       "PAINT OVER THE DEFECT")
        title.setObjectName("SectionTitle"); layout.addWidget(title)
        note = QLabel(
            "The red area is what the selected model may rebuild. Everything outside "
            "it is preserved locally, pixel for pixel.")
        note.setWordWrap(True); layout.addWidget(note)
        self.canvas = MaskBrushCanvas(self, box_size=(820, 500), tint_white=True)
        self.canvas.load(self.photo, Image.new("L", self.photo.size, 0))
        self.canvas.set_paint_white(True); layout.addWidget(self.canvas, 1, Qt.AlignCenter)
        controls = QHBoxLayout(); controls.addWidget(QLabel("Brush size"))
        size = QSlider(Qt.Horizontal); size.setRange(4, 100); size.setValue(28)
        size.valueChanged.connect(self.canvas.set_radius); controls.addWidget(size, 1)
        clear = QPushButton("CLEAR SELECTION"); clear.clicked.connect(lambda: self.canvas.fill(False))
        controls.addWidget(clear); layout.addLayout(controls)
        self.prompt = QLineEdit()
        self.prompt.setPlaceholderText(
            "Optional: leave blank to match the surroundings, or describe what to add…"
            if self.is_fill else "Optional: remove the wire; continue the brick pattern…")
        layout.addWidget(self.prompt)

        self.provider = None
        if self.is_fill:
            provider_row = QHBoxLayout(); provider_row.addWidget(QLabel("Provider"))
            self.provider = QComboBox()
            self.provider.addItem("Local — runs on this computer", "local")
            self.provider.addItem("Gemini — sends a working copy to Google", "gemini")
            self.provider.currentIndexChanged.connect(self._provider_changed)
            provider_row.addWidget(self.provider, 1)
            self.install = QPushButton("INSTALL LOCAL FILL")
            self.install.clicked.connect(self._install_local)
            provider_row.addWidget(self.install); layout.addLayout(provider_row)

        actions = QHBoxLayout(); actions.addStretch(1)
        cancel = QPushButton("CANCEL"); cancel.clicked.connect(self.reject); actions.addWidget(cancel)
        self.go = QPushButton("GENERATE SELECTED AREA" if self.is_fill else
                              "HEAL SELECTED AREA")
        self.go.setObjectName("LayerAddBtn"); self.go.clicked.connect(self._start)
        actions.addWidget(self.go); layout.addLayout(actions)
        self.status = QLabel("Ready"); layout.addWidget(self.status)
        if self.is_fill:
            self._provider_changed()

    def _provider_changed(self):
        local = self.provider is not None and self.provider.currentData() == "local"
        self.install.setVisible(local and not local_fill.installed())
        self.go.setEnabled(not local or local_fill.installed())
        if local:
            self.status.setText(
                "Local model ready — the photograph stays on this computer."
                if local_fill.installed() else
                "Local model is not installed. Installation is large and separate.")
        else:
            self.status.setText("Ready — Gemini requires an API key and sends a working copy.")

    def _install_local(self):
        answer = QMessageBox.question(
            self, "Install Local Generative Fill?",
            "This installs a separate local AI environment and downloads an inpainting "
            "model. Allow roughly 12 GB of disk space. On a 4 GB graphics card a fill may "
            "take several minutes. The installer opens a progress window and can be removed "
            "without removing SNAP SLAPPER. The model uses the CreativeML OpenRAIL-M "
            "license. Continue?",
            QMessageBox.Yes | QMessageBox.Cancel, QMessageBox.Cancel)
        if answer != QMessageBox.Yes:
            return
        try:
            local_fill.start_installer()
        except Exception as error:  # noqa: BLE001
            QMessageBox.warning(self, "Installer could not start", str(error)); return
        QMessageBox.information(
            self, "Installer started",
            "Finish the separate installer, then close and reopen Generative Fill.")

    def _start(self):
        mask = self.canvas.mask_pil(self.photo.size)
        if mask is None or mask.getbbox() is None:
            QMessageBox.information(
                self, "Select an area", "Paint where content should be generated first."
                if self.is_fill else "Paint over the defect first.")
            return
        provider = self.provider.currentData() if self.provider else "gemini"
        if provider == "local" and not local_fill.installed():
            QMessageBox.information(self, "Install Local Generative Fill",
                                    "Install the separate local model first.")
            return
        if provider == "gemini" and not confirm_send(
                self, "ai_fill_send_warning_hidden" if self.is_fill else
                "ai_heal_send_warning_hidden",
                "Send this generative fill to Gemini?" if self.is_fill else
                "Send this repair to Gemini?",
                "SNAP SLAPPER will send a working-resolution copy of this photograph, "
                "the painted mask, and your instruction to Google Gemini. This may incur "
                "an API charge. Continue?"):
            return
        key = snap_creds.get("gemini_api_key", "")
        model = snap_creds.get("gemini_image_model", "gemini-3.1-flash-image")
        self.go.setEnabled(False)
        self.status.setText(
            "The local model is generating — this can take several minutes…"
            if provider == "local" else "Gemini is rebuilding the selected area…")

        def work():
            try:
                if provider == "local":
                    result, used_model = local_fill.fill(self.photo, mask, self.prompt.text())
                    payload = (result, mask, used_model, "Local Diffusers")
                else:
                    operation = gemini_image_edit.fill if self.is_fill else gemini_image_edit.heal
                    result = operation(self.photo, mask, self.prompt.text(), key, model=model)
                    payload = (result, mask, model, "Google Gemini")
                self.signals.finished.emit(payload)
            except Exception as error:  # noqa: BLE001
                self.signals.failed.emit(str(error))
        threading.Thread(target=work, daemon=True).start()

    def _received(self, payload):
        result, mask, model, provider = payload
        result = result.resize(self.photo.size, Image.Resampling.LANCZOS)
        folder = os.path.join(snap_home.shared_library(), "snap_slapper", "generative")
        os.makedirs(folder, exist_ok=True)
        slug = "ai-fill" if self.is_fill else "ai-heal"
        path = os.path.join(folder, f"{slug}-{int(time.time() * 1000)}.png")
        result.save(path, "PNG")
        self.host.apply_ai_generation(
            path, mask, model, self.prompt.text().strip(), self.operation, provider)
        self.accept()

    def _failed(self, message):
        tool_name = "Generative Fill" if self.is_fill else "AI Heal"
        self.go.setEnabled(True)
        if self.is_fill:
            self._provider_changed()
        self.status.setText(
            f"The model could not finish {tool_name.lower()}.")
        QMessageBox.warning(self, f"{tool_name} could not finish", message)


# ===== SNAPSMACK EOF =====
