"""Dedicated Luminance HDR workflow for SNAP SLAPPER."""

import os

from PySide6.QtCore import QProcess, Signal
from PySide6.QtWidgets import (QCheckBox, QComboBox, QDialog, QFileDialog,
                               QFormLayout, QHBoxLayout, QLabel, QListWidget,
                               QMessageBox, QPushButton, QVBoxLayout)

import hdr_processor
from .widgets import SliderRow
from . import prefs


class HdrDialog(QDialog):
    completed = Signal(str)

    def __init__(self, parent=None, paths=None):
        super().__init__(parent)
        self.setWindowTitle("HDR — Luminance HDR")
        self.setMinimumWidth(580)
        self.process = None
        self.hdr_path = ""
        self.tiff_path = ""
        root = QVBoxLayout(self)
        note = QLabel("Merge bracketed photographs with your separately installed Luminance HDR. "
                      "SNAP SLAPPER keeps an EXR HDR master and imports a 16-bit TIFF.")
        note.setWordWrap(True)
        root.addWidget(note)
        self.files = QListWidget()
        self.files.setMinimumHeight(120)
        for path in paths or []:
            if os.path.isfile(path):
                self.files.addItem(os.path.abspath(path))
        root.addWidget(self.files)
        file_buttons = QHBoxLayout()
        add = QPushButton("Add bracketed photographs…")
        add.clicked.connect(self._add_files)
        remove = QPushButton("Remove selected")
        remove.clicked.connect(lambda: [self.files.takeItem(self.files.row(item))
                                        for item in self.files.selectedItems()])
        file_buttons.addWidget(add); file_buttons.addWidget(remove)
        root.addLayout(file_buttons)

        form = QFormLayout()
        self.align = QComboBox(); self.align.addItem("Auto — high quality", "AIS")
        self.align.addItem("Auto — fast", "MTB"); self.align.addItem("None", "none")
        self.model = QComboBox(); self.model.addItem("Debevec", "debevec")
        self.model.addItem("Robertson automatic", "robertsonauto")
        self.model.addItem("Robertson", "robertson")
        self.weight = QComboBox()
        for label, value in (("Triangular", "triangular"), ("Gaussian", "gaussian"),
                             ("Plateau", "plateau"), ("Flat", "flat")):
            self.weight.addItem(label, value)
        self.response = QComboBox()
        for label, value in (("Linear", "linear"), ("Camera gamma", "gamma"),
                             ("Logarithmic", "log"), ("sRGB", "srgb")):
            self.response.addItem(label, value)
        self.operator = QComboBox()
        for label, value in (("Natural detail — Mantiuk '06", "mantiuk06"),
                             ("Photographic — Reinhard '05", "reinhard05"),
                             ("Photographic — Reinhard '02", "reinhard02"),
                             ("Local contrast — Fattal", "fattal"),
                             ("Edge preserving — Durand", "durand"),
                             ("Adaptive logarithmic — Drago", "drago")):
            self.operator.addItem(label, value)
        form.addRow("Alignment", self.align); form.addRow("Merge model", self.model)
        form.addRow("Exposure weighting", self.weight); form.addRow("Response curve", self.response)
        form.addRow("Tone mapping", self.operator)
        root.addLayout(form)
        self.deghost = SliderRow("deghost", "Deghost", 0, 100, 1, 0)
        self.gamma = SliderRow("gamma", "Gamma", 20, 300, 1, 100)
        self.saturation = SliderRow("saturation", "Saturation", 0, 200, 1, 100)
        self.detail = SliderRow("detail", "Detail", 0, 200, 1, 100)
        for row in (self.deghost, self.gamma, self.saturation, self.detail): root.addWidget(row)
        self.autolevels = QCheckBox("Automatic finishing levels")
        root.addWidget(self.autolevels)
        self.status = QLabel("")
        root.addWidget(self.status)
        buttons = QHBoxLayout()
        self.run_button = QPushButton("CREATE HDR")
        self.run_button.clicked.connect(self._run)
        close = QPushButton("Close"); close.clicked.connect(self.reject)
        buttons.addStretch(); buttons.addWidget(self.run_button); buttons.addWidget(close)
        root.addLayout(buttons)

    def _add_files(self):
        paths, _ = QFileDialog.getOpenFileNames(
            self, "Choose bracketed photographs", "",
            "Photographs (*.jpg *.jpeg *.png *.tif *.tiff *.webp *.bmp *.dng *.orf *.nef *.cr2 *.cr3 *.arw *.raf);;All files (*.*)")
        known = {self.files.item(i).text() for i in range(self.files.count())}
        for path in paths:
            if path not in known: self.files.addItem(os.path.abspath(path))

    def _run(self):
        inputs = [self.files.item(i).text() for i in range(self.files.count())]
        if len(inputs) < 2:
            QMessageBox.information(self, "HDR", "Add at least two bracketed photographs.")
            return
        settings = prefs.load()
        executable = hdr_processor.find_luminance_hdr(settings.get("luminance_hdr_path", ""))
        if not executable:
            executable, _ = QFileDialog.getOpenFileName(
                self, "Locate Luminance HDR CLI", "",
                "Luminance HDR CLI (luminance-hdr-cli.exe luminance-hdr-cli);;All files (*.*)")
            if not executable: return
            settings["luminance_hdr_path"] = executable; prefs.save(settings)
        folder = QFileDialog.getExistingDirectory(self, "Save HDR results", os.path.dirname(inputs[0]))
        if not folder: return
        self.hdr_path, self.tiff_path = hdr_processor.output_paths(
            folder, os.path.splitext(os.path.basename(inputs[0]))[0] + "-HDR")
        try:
            argv = hdr_processor.build_command(
                executable, inputs, self.hdr_path, self.tiff_path,
                align=self.align.currentData(), deghost=self.deghost.slider.value() / 100,
                weight=self.weight.currentData(), response=self.response.currentData(),
                model=self.model.currentData(), operator=self.operator.currentData(),
                gamma=self.gamma.slider.value() / 100,
                saturation=self.saturation.slider.value() / 100,
                detail=self.detail.slider.value() / 100,
                autolevels=self.autolevels.isChecked())
        except (ValueError, OSError) as error:
            QMessageBox.warning(self, "HDR", str(error)); return
        self.process = QProcess(self)
        self.process.setProgram(argv[0]); self.process.setArguments(argv[1:])
        self.process.setWorkingDirectory(folder)
        self.process.finished.connect(self._finished)
        self.process.errorOccurred.connect(lambda _error: self._failed(self.process.errorString()))
        self.run_button.setEnabled(False); self.status.setText("Aligning and merging HDR…")
        self.process.start()

    def _failed(self, message):
        self.run_button.setEnabled(True); self.status.setText("HDR processing failed.")
        QMessageBox.warning(self, "Luminance HDR", message)

    def _finished(self, exit_code, _status):
        self.run_button.setEnabled(True)
        if exit_code == 0 and os.path.isfile(self.hdr_path) and os.path.isfile(self.tiff_path):
            self.status.setText("HDR complete — EXR master and 16-bit TIFF saved.")
            self.completed.emit(self.tiff_path)
        else:
            details = bytes(self.process.readAllStandardError()).decode("utf-8", "replace").strip()
            self._failed(details or f"Luminance HDR stopped with code {exit_code}.")
