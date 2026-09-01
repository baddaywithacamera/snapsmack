"""Preferences dialog — export defaults for the Qt SNAP SLAPPER."""

from PySide6.QtCore import Qt
from PySide6.QtWidgets import (
    QDialog, QVBoxLayout, QHBoxLayout, QFormLayout, QSlider, QLineEdit,
    QCheckBox, QLabel, QPushButton, QFileDialog, QWidget,
)

from . import theme, prefs


class PreferencesDialog(QDialog):
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("SNAP SLAPPER — Preferences")
        self.setStyleSheet(theme.stylesheet())
        self.setMinimumWidth(440)
        self._values = prefs.load()

        root = QVBoxLayout(self)
        root.setContentsMargins(16, 16, 16, 16)
        root.setSpacing(12)

        title = QLabel("EXPORT DEFAULTS")
        title.setObjectName("ControlName")
        root.addWidget(title)

        form = QFormLayout()
        form.setSpacing(10)

        quality_row = QHBoxLayout()
        self.quality = QSlider(Qt.Horizontal)
        self.quality.setRange(50, 100)
        self.quality.setValue(int(self._values["export_quality"]))
        self.quality_label = QLabel(str(self.quality.value()))
        self.quality_label.setObjectName("ControlValue")
        self.quality.valueChanged.connect(lambda v: self.quality_label.setText(str(v)))
        quality_row.addWidget(self.quality, 1)
        quality_row.addWidget(self.quality_label)
        form.addRow("JPEG quality", self._wrap(quality_row))

        self.copyright = QLineEdit(self._values["copyright_text"])
        self.copyright.setPlaceholderText("© Your Name — added only if the photo has none")
        form.addRow("Copyright", self.copyright)

        self.add_copyright = QCheckBox("Add copyright when the photo has none")
        self.add_copyright.setChecked(bool(self._values["add_copyright_if_missing"]))
        form.addRow("", self.add_copyright)

        self.strip_gps = QCheckBox("Remove GPS from exported copies (never the original)")
        self.strip_gps.setChecked(bool(self._values["strip_gps"]))
        form.addRow("", self.strip_gps)

        root.addLayout(form)

        folders_title = QLabel("FILES AND FOLDERS")
        folders_title.setObjectName("ControlName")
        root.addWidget(folders_title)

        folders = QFormLayout()
        folders.setSpacing(10)
        self.library_folder = self._folder_row(
            folders, "Library folder", self._values.get("library_folder", ""))
        self.projects_folder = self._folder_row(
            folders, "Projects folder", self._values.get("projects_folder", ""))
        self.exports_folder = self._folder_row(
            folders, "Exports folder", self._values.get("exports_folder", ""))
        self.include_subfolders = QCheckBox("Include subfolders when browsing the library")
        self.include_subfolders.setChecked(
            bool(self._values.get("library_include_subfolders", False)))
        folders.addRow("", self.include_subfolders)
        root.addLayout(folders)

        buttons = QHBoxLayout()
        buttons.addStretch(1)
        cancel = QPushButton("Cancel")
        cancel.setObjectName("LayerOrderBtn")
        cancel.clicked.connect(self.reject)
        buttons.addWidget(cancel)
        save = QPushButton("Save")
        save.setObjectName("LayerAddBtn")
        save.clicked.connect(self._save)
        buttons.addWidget(save)
        root.addLayout(buttons)

    def _wrap(self, layout):
        widget = QWidget()
        widget.setLayout(layout)
        return widget

    def _folder_row(self, form, label, value):
        edit = QLineEdit(str(value or ""))
        edit.setPlaceholderText("Choose a folder…")
        button = QPushButton("Browse…")
        button.setObjectName("LayerOrderBtn")
        button.clicked.connect(lambda: self._browse_folder(edit, label))
        row = QHBoxLayout()
        row.addWidget(edit, 1)
        row.addWidget(button)
        form.addRow(label, self._wrap(row))
        return edit

    def _browse_folder(self, edit, label):
        folder = QFileDialog.getExistingDirectory(
            self, f"Choose {label.lower()}", edit.text().strip())
        if folder:
            edit.setText(folder)

    def _save(self):
        # Merge into the loaded preferences: saving file locations must never
        # reset mode, folder-tree visibility, sort order, or future settings.
        self._values.update({
            "export_quality": self.quality.value(),
            "copyright_text": self.copyright.text().strip(),
            "add_copyright_if_missing": self.add_copyright.isChecked(),
            "strip_gps": self.strip_gps.isChecked(),
            "texture_site_hint": self._values.get("texture_site_hint", "foundtextures"),
            "library_folder": self.library_folder.text().strip(),
            "projects_folder": self.projects_folder.text().strip(),
            "exports_folder": self.exports_folder.text().strip(),
            "library_include_subfolders": self.include_subfolders.isChecked(),
        })
        prefs.save(self._values)
        self.accept()

# ===== SNAPSMACK EOF =====
