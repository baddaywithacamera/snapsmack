"""Integrated LEWK AGAIN generator: text goes out; the photograph does not."""

import json
import os
import re

from PySide6.QtCore import QObject, QThread, Signal, Slot, Qt
from PySide6.QtGui import QIcon
from PySide6.QtWidgets import (
    QComboBox, QDialog, QFileDialog, QFormLayout, QHBoxLayout, QLabel,
    QLineEdit, QMessageBox, QPushButton, QTabWidget, QTextEdit, QVBoxLayout,
    QWidget,
)

import lewk_again
import snap_creds
from . import theme
from .engine_bridge import pil_to_qpixmap


class _Worker(QObject):
    complete = Signal(object)
    failed = Signal(str)

    def __init__(self, args):
        super().__init__()
        self.args = args

    @Slot()
    def run(self):
        try:
            self.complete.emit(lewk_again.request_lewk(**self.args))
        except Exception as error:  # noqa: BLE001
            self.failed.emit(str(error))


class LewkAgainDialog(QDialog):
    def __init__(self, host):
        super().__init__(host)
        self.host = host
        self.recipe = None
        self.thread = None
        self.setWindowTitle("LEWK AGAIN")
        self.resize(880, 700)
        self.setStyleSheet(theme.stylesheet())
        layout = QVBoxLayout(self)
        title = QLabel("LEWK AGAIN")
        title.setObjectName("SectionTitle")
        layout.addWidget(title)
        privacy = QLabel("TEXT ONLY — YOUR PHOTOGRAPH NEVER LEAVES THIS COMPUTER")
        privacy.setObjectName("TargetLabel")
        privacy.setWordWrap(True)
        layout.addWidget(privacy)

        form = QFormLayout()
        self.provider = QComboBox()
        self.provider.addItems(lewk_again.PROVIDERS)
        self.provider.currentTextChanged.connect(self._provider_changed)
        form.addRow("Provider", self.provider)
        self.model = QLineEdit()
        form.addRow("Model", self.model)
        self.endpoint = QLineEdit()
        self.endpoint.setPlaceholderText("http://127.0.0.1:11434/v1/chat/completions")
        form.addRow("Local endpoint", self.endpoint)
        layout.addLayout(form)

        self.prompt = QTextEdit()
        self.prompt.setPlaceholderText(
            "Describe the photographic look in ordinary language. Example: muted "
            "winter documentary colour, protect skin tones, lift deep shadows, "
            "restrained grain.")
        self.prompt.setMaximumHeight(125)
        layout.addWidget(self.prompt)

        row = QHBoxLayout()
        self.generate = QPushButton("CREATE LEWK")
        self.generate.setObjectName("LayerAddBtn")
        self.generate.clicked.connect(self._generate)
        row.addWidget(self.generate)
        self.status = QLabel("Ready")
        row.addWidget(self.status, 1)
        layout.addLayout(row)

        tabs = QTabWidget()
        preview_tab = QWidget()
        preview_layout = QVBoxLayout(preview_tab)
        self.preview = QLabel("Your preview will appear here.")
        self.preview.setMinimumHeight(270)
        self.preview.setAlignment(Qt.AlignCenter)
        preview_layout.addWidget(self.preview)
        self.summary = QTextEdit()
        self.summary.setReadOnly(True)
        self.summary.setMaximumHeight(140)
        preview_layout.addWidget(self.summary)
        tabs.addTab(preview_tab, "PREVIEW + EXPLANATION")
        self.guts = QTextEdit()
        self.guts.setReadOnly(True)
        tabs.addTab(self.guts, "SHOW THE GUTS")
        layout.addWidget(tabs, 1)

        actions = QHBoxLayout()
        save = QPushButton("SAVE LEWK…")
        save.clicked.connect(self._save)
        actions.addWidget(save)
        actions.addStretch(1)
        apply_button = QPushButton("APPLY LEWK")
        apply_button.setObjectName("LayerAddBtn")
        apply_button.clicked.connect(self._apply)
        actions.addWidget(apply_button)
        layout.addLayout(actions)
        self._provider_changed(self.provider.currentText())

    def _provider_changed(self, provider):
        _key, default_model = lewk_again.PROVIDERS[provider]
        self.model.setText(snap_creds.get(f"lewk_model_{provider.lower()}", default_model))
        local = provider == "LOCAL"
        self.endpoint.setEnabled(local)
        self.endpoint.setText(snap_creds.get(
            "lewk_local_endpoint", "http://127.0.0.1:11434/v1/chat/completions"))

    def _generate(self):
        prompt = self.prompt.toPlainText().strip()
        if not prompt:
            QMessageBox.information(self, "Describe the LEWK",
                                    "Tell LEWK AGAIN what you want first.")
            return
        provider = self.provider.currentText()
        key_name, _default = lewk_again.PROVIDERS[provider]
        model = self.model.text().strip()
        endpoint = self.endpoint.text().strip()
        snap_creds.set(f"lewk_model_{provider.lower()}", model)
        if provider == "LOCAL":
            snap_creds.set("lewk_local_endpoint", endpoint)
        args = {"provider": provider, "api_key": snap_creds.get(key_name, "") if key_name else "",
                "model": model, "prompt": prompt, "previous": self.recipe,
                "endpoint": endpoint}
        self.generate.setEnabled(False)
        self.status.setText("Asking the provider — photo remains local…")
        self.thread = QThread(self)
        self.worker = _Worker(args)
        self.worker.moveToThread(self.thread)
        self.thread.started.connect(self.worker.run)
        self.worker.complete.connect(self._received)
        self.worker.failed.connect(self._failed)
        self.worker.complete.connect(self.thread.quit)
        self.worker.failed.connect(self.thread.quit)
        self.thread.finished.connect(self.worker.deleteLater)
        self.thread.finished.connect(self.thread.deleteLater)
        self.thread.start()

    def _received(self, recipe):
        self.recipe = recipe
        self.generate.setText("REFINE LEWK")
        self.generate.setEnabled(True)
        self.status.setText("Safe recipe received and validated")
        lines = [recipe["name"], recipe.get("description", ""), ""]
        lines.extend("• " + item for item in recipe.get("explanation", []))
        self.summary.setPlainText("\n".join(lines))
        self.guts.setPlainText(json.dumps(recipe, indent=2, ensure_ascii=False))
        try:
            image = self.host.render_preview_image((650, 360)).convert("RGB")
            for layer in recipe["layers"]:
                if layer["type"] == "adjustment":
                    import editor_engine
                    image = editor_engine.apply_adjustments(image, layer["adjustments"]).convert("RGB")
                elif layer["type"] == "filter":
                    import slapper_filters
                    image = slapper_filters.apply_filter(
                        image, layer["filter_type"], layer["settings"]).convert("RGB")
            self.preview.setPixmap(pil_to_qpixmap(image))
        except Exception as error:  # noqa: BLE001
            self.preview.setText("Preview failed: " + str(error))

    def _failed(self, message):
        self.generate.setEnabled(True)
        self.status.setText("Provider request failed")
        QMessageBox.warning(self, "LEWK AGAIN could not finish", message)

    def _save(self):
        if not self.recipe:
            return
        default_dir = lewk_again.library_dir()
        slug = re.sub(r"[^a-z0-9]+", "-", self.recipe["name"].lower()).strip("-") or "lewk-again"
        path, _ = QFileDialog.getSaveFileName(
            self, "Save editable LEWK", os.path.join(default_dir, slug + ".lewk"),
            "SNAP SLAPPER LEWK (*.lewk);;JSON (*.json)")
        if path:
            if not os.path.splitext(path)[1]:
                path += ".lewk"
            with open(path, "w", encoding="utf-8") as handle:
                json.dump(self.recipe, handle, indent=2, ensure_ascii=False)
            self.status.setText("Saved " + os.path.basename(path))

    def _apply(self):
        if not self.recipe:
            return
        self.host.apply_generated_lewk(self.recipe)
        self.accept()

# ===== SNAPSMACK EOF =====
