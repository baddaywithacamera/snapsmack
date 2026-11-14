"""Paint a selection and repair/fill it with Gemini or an optional local model."""

import os
import threading
import time

from PIL import Image
from PySide6.QtCore import QObject, QTimer, Signal, Qt
from PySide6.QtWidgets import (
    QComboBox, QDialog, QHBoxLayout, QLabel, QLineEdit, QMessageBox,
    QProgressBar, QPushButton, QSlider, QVBoxLayout,
)

import gemini_image_edit
import snap_creds
import snap_home
from .mask_brush import MaskBrushCanvas
from . import BUILD_VERSION, local_fill
from .generative_consent import confirm_send


# WHAT A BLANK PROMPT ASKS FOR.
#
# Leaving the box empty is the common case and the right default: most of the
# time the answer wanted is "more of what is already there". But "blank" was
# being passed through as an empty string, and an empty string is not an
# instruction -- each provider then fell back to its own built-in wording,
# which is not the same wording, so the two providers behaved differently for
# the same empty box.
#
# This says the one thing the model cannot work out from the pixels and the
# person should not have to type: continue the surrounding material. It is a
# hint, not a lock -- the moment anything is typed, the typed words are what
# is sent.
BLANK_FILL_HINT = (
    "continue the surrounding material exactly: same texture, grain, focus, "
    "lighting direction and perspective, no new subject")
BLANK_HEAL_HINT = (
    "remove what is selected and continue the surrounding material exactly: "
    "same texture, grain, focus, lighting direction and perspective")


def prompt_for(typed, is_fill):
    """The instruction actually sent. Typed words always win."""
    typed = (typed or "").strip()
    if typed:
        return typed
    return BLANK_FILL_HINT if is_fill else BLANK_HEAL_HINT


class _Signals(QObject):
    finished = Signal(object)
    failed = Signal(str)
    # stage, done, total. total is 0 when the stage has no measurable steps,
    # which is the honest answer for a model load and for every Gemini call.
    progress = Signal(str, int, int)
    stopped = Signal()


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
        self.signals.progress.connect(self._progressed)
        self.signals.stopped.connect(self._stopped)
        self._cancelled = False
        self._running = False
        self._started_at = 0.0
        self._clock = QTimer(self)
        self._clock.setInterval(1000)
        self._clock.timeout.connect(self._tick)
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
            "Leave blank to continue the surrounding material, or describe what to add\u2026"
            if self.is_fill else
            "Leave blank to continue the surrounding material, or: remove the wire\u2026")
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
        # A BAR THAT ONLY CLAIMS WHAT IS MEASURED. The local model reports one
        # line per diffusion step, so its bar is real: 0..30. Loading the model,
        # and every Gemini call, have no step count at all -- those show a busy
        # bar and an elapsed clock rather than a percentage somebody invented.
        self.bar = QProgressBar()
        self.bar.setTextVisible(True)
        self.bar.setVisible(False)
        layout.addWidget(self.bar)
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
        self._begin_run(provider)

        def work():
            try:
                # The generated pixels are cut from the PHOTOGRAPH, not from the
                # screen preview. Rendering the document here (on this worker
                # thread, the same way the flattened-JPEG job does) is what lets
                # the patch come back at the photograph's own resolution.
                document = self.host.render_document_image()
                full_mask = (mask if document.size == self.photo.size else
                             mask.resize(document.size, Image.Resampling.LANCZOS))
                bounds = full_mask.getbbox()
                # HOW MUCH OF THE PHOTOGRAPH THE MODEL SEES. The tile is
                # resized to the provider's working resolution, so the context
                # it can afford depends on which provider is being asked: the
                # local model is capped at 512 by the model itself, Gemini
                # works at 1536. Asking for the same margin from both starves
                # the local model's selection -- measured: a 40px blemish on a
                # 6000px frame arrives as 8 pixels. context_floor_ratio works
                # out the widest tile that still leaves something to generate
                # into.
                work = (local_fill.WORK_RESOLUTION if provider == "local"
                        else gemini_image_edit.WORK_RESOLUTION)
                box = gemini_image_edit.square_tile_box(
                    document.size, bounds,
                    floor_ratio=gemini_image_edit.context_floor_ratio(
                        document.size, bounds, work))
                # The model works in sRGB and will not ask what it was given.
                # Convert out of the document's space before sending and back
                # into it before compositing, or the patch is the right picture
                # in the wrong colours.
                profile = document.info.get("icc_profile", b"")
                tile = gemini_image_edit.to_srgb(
                    document.crop(box).convert("RGB"), profile)
                tile_mask = full_mask.crop(box)
                if provider == "local":
                    patch, used_model = local_fill.fill_tile(
                        tile, tile_mask, prompt_for(self.prompt.text(), self.is_fill),
                        on_progress=self.signals.progress.emit,
                        should_cancel=lambda: self._cancelled)
                    drift = gemini_image_edit.alignment_error(tile, patch, tile_mask)
                    patch = gemini_image_edit.from_srgb(patch, profile)
                    payload = (patch, tile, tile_mask, mask, box, document.size,
                               used_model, "Local Diffusers", drift)
                else:
                    patch, _instruction, drift = gemini_image_edit.heal_tile(
                        tile, tile_mask, prompt_for(self.prompt.text(), self.is_fill),
                        key, model=model, operation=self.operation)
                    patch = gemini_image_edit.from_srgb(patch, profile)
                    payload = (patch, tile, tile_mask, mask, box, document.size,
                               model, "Google Gemini", drift)
                if self._cancelled:
                    self.signals.stopped.emit()
                    return
                self.signals.finished.emit(payload)
            except local_fill.Cancelled:
                self.signals.stopped.emit()
            except Exception as error:  # noqa: BLE001
                if self._cancelled:
                    self.signals.stopped.emit()
                else:
                    self.signals.failed.emit(str(error))
        threading.Thread(target=work, daemon=True).start()

    def _begin_run(self, provider):
        self._cancelled = False
        self._running = True
        self._provider_in_flight = provider
        self._started_at = time.monotonic()
        self.bar.setVisible(True)
        self.bar.setRange(0, 0)            # busy until a real step count lands
        self.bar.setFormat("Working\u2026")
        self.go.setText("STOP")
        self.go.setEnabled(True)
        try:
            self.go.clicked.disconnect(self._start)
        except (RuntimeError, TypeError):
            pass
        self.go.clicked.connect(self._stop)
        self._clock.start()
        self.status.setText(
            "Loading the local model\u2026" if provider == "local"
            else "Gemini is rebuilding the selected area \u2014 elapsed 0:00")

    def _stop(self):
        """Give the person their application back.

        For the local model this kills the diffusion child outright. For Gemini
        there is nothing to kill: the request is one blocking round trip with no
        cancel, so stopping means we stop waiting and throw the answer away. The
        call may already have been made and may already be billable, and the
        message says so rather than implying the charge was avoided.
        """
        if not self._running:
            return
        self._cancelled = True
        self.go.setEnabled(False)
        self.go.setText("STOPPING\u2026")
        self.bar.setRange(0, 0)
        self.bar.setFormat("Stopping\u2026")
        if getattr(self, "_provider_in_flight", "") == "gemini":
            self.status.setText(
                "Stopping. The request was already sent to Gemini, so it may "
                "still be charged; the answer will be discarded.")
        else:
            self.status.setText("Stopping the local model\u2026")

    def _progressed(self, stage, done, total):
        if not self._running or self._cancelled:
            return
        if stage == "generating" and total > 0:
            self.bar.setRange(0, total)
            self.bar.setValue(done)
            self.bar.setFormat("Step %v of %m")
            self.status.setText("The local model is generating \u2014 step %d of %d"
                                % (done, total))
        elif stage == "saving":
            self.bar.setRange(0, 0)
            self.bar.setFormat("Saving\u2026")
            self.status.setText("Saving the generated area\u2026")
        else:
            self.bar.setRange(0, 0)
            self.bar.setFormat("Loading the model\u2026")
            self.status.setText("Loading the local model\u2026")

    def _tick(self):
        """The elapsed clock. It is the ONLY honest number for a Gemini call."""
        if not self._running or self._cancelled:
            return
        seconds = int(time.monotonic() - self._started_at)
        clock = "%d:%02d" % (seconds // 60, seconds % 60)
        if getattr(self, "_provider_in_flight", "") == "gemini":
            self.bar.setFormat("Gemini \u2014 %s elapsed" % clock)
            self.status.setText(
                "Gemini is rebuilding the selected area \u2014 elapsed %s" % clock)
        elif self.bar.maximum() == 0:
            self.bar.setFormat("Loading the model \u2014 %s elapsed" % clock)

    def _end_run(self):
        self._running = False
        self._clock.stop()
        self.bar.setVisible(False)
        self.bar.setRange(0, 0)
        self.go.setText("GENERATE SELECTED AREA" if self.is_fill
                        else "HEAL SELECTED AREA")
        try:
            self.go.clicked.disconnect(self._stop)
        except (RuntimeError, TypeError):
            pass
        self.go.clicked.connect(self._start)
        self.go.setEnabled(True)

    def _stopped(self):
        gemini = getattr(self, "_provider_in_flight", "") == "gemini"
        self._end_run()
        if self.is_fill:
            self._provider_changed()
        if gemini:
            self.status.setText(
                "Stopped. Nothing was changed here, but the Gemini request had "
                "already been sent and may still be charged.")
        else:
            self.status.setText("Stopped. Nothing was changed.")

    def _received(self, payload):
        self._end_run()
        (patch, tile, tile_mask, layer_mask, box, document_size, model,
         provider, drift) = payload
        # The layer is document-sized and transparent everywhere except the
        # tile, so the image layer's "stretch" fit is a 1:1 placement at export
        # instead of a 3x upscale of a 2048-pixel preview. An almost entirely
        # transparent PNG costs little on disk.
        canvas = gemini_image_edit.tile_layer_image(
            document_size, patch, tile_mask, box)
        folder = os.path.join(snap_home.shared_library(), "snap_slapper", "generative")
        os.makedirs(folder, exist_ok=True)
        slug = "ai-fill" if self.is_fill else "ai-heal"
        path = os.path.join(folder, f"{slug}-{int(time.time() * 1000)}.png")
        canvas.save(path, "PNG")
        self.host.apply_ai_generation(
            path, layer_mask, model,
            self.prompt.text().strip(), self.operation, provider,
            input_image=tile, output_image=patch, drift=drift)
        self.accept()

    def _failed(self, message):
        tool_name = "Generative Fill" if self.is_fill else "AI Heal"
        self._end_run()
        if self.is_fill:
            self._provider_changed()
        self.status.setText(
            f"The model could not finish {tool_name.lower()}.")
        QMessageBox.warning(self, f"{tool_name} could not finish", message)


# ===== SNAPSMACK EOF =====
