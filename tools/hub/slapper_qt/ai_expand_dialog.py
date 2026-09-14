"""Draggable, asymmetric Generative Expand with an honest area cap."""
import os
import threading
import time
from PIL import Image
from PySide6.QtCore import QObject, Qt, Signal
from PySide6.QtGui import QImage, QPixmap
from PySide6.QtWidgets import QDialog, QHBoxLayout, QLabel, QLineEdit, QMessageBox, QPushButton, QVBoxLayout
import gemini_image_edit
import snap_creds
import snap_home
from . import BUILD_VERSION
from .expand_canvas import ExpandCanvas
from .generative_consent import confirm_send


class _Signals(QObject):
    finished = Signal(object)
    failed = Signal(str)


def _pixmap(image, maximum=(640, 360)):
    value = image.convert("RGB").copy(); value.thumbnail(maximum, Image.Resampling.LANCZOS)
    data = value.tobytes("raw", "RGB")
    return QPixmap.fromImage(QImage(data, value.width, value.height, value.width * 3,
                                   QImage.Format_RGB888).copy())


class AIExpandDialog(QDialog):
    def __init__(self, host):
        super().__init__(host)
        self.host = host
        self.photo = host.render_preview_image((2048, 2048)).convert("RGB")
        (self.original_size, self.current_size, self.used_area,
         self.remaining_area) = host.generative_expand_budget()
        self.original_area = self.original_size[0] * self.original_size[1]
        self.edges = {name: 0.0 for name in ("left", "top", "right", "bottom")}
        self.pending = None
        self.signals = _Signals(); self.signals.finished.connect(self._received); self.signals.failed.connect(self._failed)
        self.setWindowTitle(f"Generative Expand — Gemini — {BUILD_VERSION}"); self.resize(700, 610)
        layout = QVBoxLayout(self)
        title = QLabel("EXPAND THE PHOTOGRAPH BEYOND ITS CAPTURED FRAME"); title.setObjectName("SectionTitle"); layout.addWidget(title)
        note = QLabel("Drag only the edge or corner you want. Generated area is measured against the original captured frame and is cumulatively limited to 20%.")
        note.setWordWrap(True); layout.addWidget(note)
        self.canvas = ExpandCanvas(self.photo); self.canvas.edges_changed.connect(self._edges_changed); layout.addWidget(self.canvas)
        self.measure = QLabel(); self.measure.setAlignment(Qt.AlignCenter); layout.addWidget(self.measure)
        self.prompt = QLineEdit(); self.prompt.setPlaceholderText("Optional direction: continue the prairie and evening sky…"); layout.addWidget(self.prompt)
        self.preview = QLabel(); self.preview.setAlignment(Qt.AlignCenter); self.preview.setVisible(False); layout.addWidget(self.preview, 1)
        actions = QHBoxLayout(); actions.addStretch(1)
        cancel = QPushButton("CANCEL"); cancel.clicked.connect(self.reject); actions.addWidget(cancel)
        self.go = QPushButton("EXPAND WITH GEMINI"); self.go.setObjectName("LayerAddBtn"); self.go.clicked.connect(self._start); actions.addWidget(self.go)
        self.accept_result = QPushButton("ADD EXPANSION"); self.accept_result.setObjectName("LayerAddBtn")
        self.accept_result.clicked.connect(self._accept_result); self.accept_result.setVisible(False); actions.addWidget(self.accept_result)
        layout.addLayout(actions); self.status = QLabel("Ready"); layout.addWidget(self.status)
        self._edges_changed(self.edges)

    def _measurement(self):
        pads, _unused_size, _unused_area = gemini_image_edit.expansion_geometry(
            self.original_size, self.edges)
        size = (self.current_size[0] + pads["left"] + pads["right"],
                self.current_size[1] + pads["top"] + pads["bottom"])
        area = size[0] * size[1] - self.current_size[0] * self.current_size[1]
        return pads, size, area, area * 100 / self.original_area, (self.used_area + area) * 100 / self.original_area

    def _edges_changed(self, edges):
        self.edges = dict(edges)
        _pads, size, area, percent, cumulative = self._measurement()
        sides = ", ".join(f"{name} {value:.1f}%" for name, value in self.edges.items() if value >= .1)
        self.measure.setText(f"{sides or 'No expansion selected'}  •  {size[0]} × {size[1]} px  •  {percent:.1f}% of original this time  •  {cumulative:.1f}% cumulative")
        allowed = area > 0 and area <= self.remaining_area
        self.go.setEnabled(allowed); self.measure.setStyleSheet("" if area <= self.remaining_area else "color: #ff695c;")
        self.status.setText("Ready" if allowed else "Drag an edge to expand." if area <= 0 else "This would exceed the cumulative 20% limit.")

    def _start(self):
        _pads, size, area, percent, _cumulative = self._measurement()
        if area <= 0 or area > self.remaining_area:
            QMessageBox.information(self, "Expansion limit", self.status.text()); return
        sides = ", ".join(f"{name} {value:.1f}%" for name, value in self.edges.items() if value >= .1)
        if not confirm_send(self, "ai_expand_send_warning_hidden", "Send this expansion to Gemini?",
                f"Expand {sides}: {area:,} generated pixels ({percent:.1f}% of the original frame), producing {size[0]} × {size[1]} pixels. The working copy and displayed instruction will leave this computer. This is a Class C generative alteration. Continue?"):
            return
        key = snap_creds.get("gemini_api_key", ""); model = snap_creds.get("gemini_image_model", "gemini-3.1-flash-image")
        instruction = self.prompt.text().strip(); self.go.setEnabled(False); self.status.setText("Gemini is extending the selected edge…")
        def work():
            try:
                generator_edges = {
                    "left": self.edges["left"] * self.original_size[0] / self.current_size[0],
                    "right": self.edges["right"] * self.original_size[0] / self.current_size[0],
                    "top": self.edges["top"] * self.original_size[1] / self.current_size[1],
                    "bottom": self.edges["bottom"] * self.original_size[1] / self.current_size[1],
                }
                preview_limit = round(self.photo.width * self.photo.height *
                                      (self.remaining_area / (self.current_size[0] * self.current_size[1])))
                result = gemini_image_edit.expand(self.photo, generator_edges, instruction, key,
                                                  model=model,
                                                  max_generated_area=preview_limit)
                self.signals.finished.emit((result, model, instruction, area, dict(self.edges)))
            except Exception as error:
                self.signals.failed.emit(str(error))
        threading.Thread(target=work, daemon=True).start()

    def _received(self, payload):
        (result, mask, box), model, instruction, area, edges = payload
        self.pending = (result, mask, box, model, instruction, area, edges)
        self.preview.setPixmap(_pixmap(result)); self.preview.setVisible(True); self.canvas.setVisible(False)
        self.go.setText("GENERATE AGAIN"); self.go.setEnabled(True); self.accept_result.setVisible(True)
        self.status.setText("Preview ready — add it, generate again, or cancel.")

    def _accept_result(self):
        if not self.pending: return
        result, mask, box, model, instruction, area, edges = self.pending
        folder = os.path.join(snap_home.shared_library(), "snap_slapper", "generative"); os.makedirs(folder, exist_ok=True)
        path = os.path.join(folder, f"ai-expand-{int(time.time() * 1000)}.png"); result.save(path, "PNG")
        self.host.apply_ai_expand(path, mask, box, model, instruction, self.photo, area, edges); self.accept()

    def _failed(self, message):
        self._edges_changed(self.edges); self.status.setText("Gemini could not expand the selected edge.")
        QMessageBox.warning(self, "Generative Expand could not finish", message)

# ===== SNAPSMACK EOF =====
