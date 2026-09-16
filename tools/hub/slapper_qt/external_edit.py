"""Edit-copy handoff to separately installed photographic applications."""

import glob
import os
import shutil
import subprocess

from PySide6.QtCore import Signal
from PySide6.QtWidgets import (QComboBox, QDialog, QFileDialog, QHBoxLayout,
                               QLabel, QMessageBox, QPushButton, QVBoxLayout)


def detected_editors():
    """Find supported editors without depending on registry or a shell."""
    candidates = []
    names = (
        ("ON1 Effects", "ON1/ON1 Effects */ON1 Effects *.exe"),
        ("Topaz DeNoise AI", "Topaz Labs LLC/Topaz DeNoise AI/Topaz DeNoise AI.exe"),
        ("Topaz Sharpen AI", "Topaz Labs LLC/Topaz Sharpen AI/Topaz Sharpen AI.exe"),
    )
    if os.name == "nt":
        for root in (os.environ.get("ProgramFiles", ""),
                     os.environ.get("ProgramFiles(x86)", "")):
            if not root:
                continue
            for label, pattern in names:
                for path in glob.glob(os.path.join(root, pattern)):
                    if os.path.isfile(path):
                        candidates.append((label, os.path.abspath(path)))
    else:
        for label, executable in (("ON1", "on1"), ("Topaz DeNoise AI", "topaz-denoise-ai"),
                                  ("Topaz Sharpen AI", "topaz-sharpen-ai")):
            path = shutil.which(executable)
            if path:
                candidates.append((label, os.path.abspath(path)))
    unique = []
    seen = set()
    for label, path in candidates:
        key = os.path.normcase(path)
        if key not in seen:
            seen.add(key); unique.append((label, path))
    return unique


class ExternalEditDialog(QDialog):
    import_requested = Signal(str, str)

    def __init__(self, edit_copy, parent=None):
        super().__init__(parent)
        self.edit_copy = os.path.abspath(edit_copy)
        self.setWindowTitle("External Edit Copy")
        self.setMinimumWidth(600)
        layout = QVBoxLayout(self)
        note = QLabel(
            "SNAP SLAPPER created a 16-bit TIFF edit copy. Open it in the external "
            "application, save the result, then import it as a new layer. The original "
            "photograph and current edit remain untouched.")
        note.setWordWrap(True); layout.addWidget(note)
        self.path_label = QLabel(self.edit_copy)
        self.path_label.setWordWrap(True); layout.addWidget(self.path_label)
        row = QHBoxLayout()
        self.editor = QComboBox()
        for label, path in detected_editors():
            self.editor.addItem(label, path)
        row.addWidget(self.editor, 1)
        choose = QPushButton("Choose application…")
        choose.clicked.connect(self._choose_editor); row.addWidget(choose)
        layout.addLayout(row)
        actions = QHBoxLayout()
        launch = QPushButton("OPEN EDIT COPY")
        launch.clicked.connect(self._launch); actions.addWidget(launch)
        self.import_button = QPushButton("IMPORT RETURNED COPY")
        self.import_button.clicked.connect(self._import); actions.addWidget(self.import_button)
        locate = QPushButton("Locate saved result…")
        locate.clicked.connect(self._locate_result); actions.addWidget(locate)
        layout.addLayout(actions)
        self.status = QLabel("")
        layout.addWidget(self.status)
        close = QPushButton("Close"); close.clicked.connect(self.accept)
        layout.addWidget(close)

    def _choose_editor(self):
        suffix = "Applications (*.exe);;All files (*)" if os.name == "nt" else "All files (*)"
        path, _ = QFileDialog.getOpenFileName(self, "Choose external editor", "", suffix)
        if path:
            self.editor.addItem(os.path.splitext(os.path.basename(path))[0], os.path.abspath(path))
            self.editor.setCurrentIndex(self.editor.count() - 1)

    def _launch(self):
        executable = self.editor.currentData()
        if not executable:
            QMessageBox.information(self, "External editor", "Choose an application first.")
            return
        try:
            subprocess.Popen([os.path.abspath(executable), self.edit_copy],
                             shell=False, close_fds=True)
            self.status.setText("Edit copy opened. Save it in the external application, then import it.")
        except OSError as error:
            QMessageBox.warning(self, "External editor", str(error))

    def _import(self):
        if not os.path.isfile(self.edit_copy):
            QMessageBox.warning(self, "External edit", "The edit copy no longer exists.")
            return
        self.import_requested.emit(self.edit_copy, self.editor.currentText() or "External Edit")
        self.status.setText("Returned copy imported as a new layer.")

    def _locate_result(self):
        path, _ = QFileDialog.getOpenFileName(
            self, "Locate the externally saved result", os.path.dirname(self.edit_copy),
            "Images (*.tif *.tiff *.png *.jpg *.jpeg *.webp);;All files (*.*)")
        if path:
            self.edit_copy = os.path.abspath(path)
            self.path_label.setText(self.edit_copy)

