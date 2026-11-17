"""Preferences dialog — export defaults for the Qt SNAP SLAPPER."""

from PySide6.QtCore import Qt, QUrl
from PySide6.QtGui import QDesktopServices
from PySide6.QtWidgets import (
    QDialog, QVBoxLayout, QHBoxLayout, QFormLayout, QSlider, QLineEdit,
    QCheckBox, QLabel, QPushButton, QFileDialog, QWidget, QComboBox,
    QMessageBox,
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

        performance_title = QLabel("PERFORMANCE")
        performance_title.setObjectName("ControlName")
        root.addWidget(performance_title)
        performance = QFormLayout()
        self.render_device = QComboBox()
        self.render_device.addItem("Automatic (use NVIDIA GPU when available)", "auto")
        self.render_device.addItem("NVIDIA GPU", "gpu")
        self.render_device.addItem("CPU", "cpu")
        selected = self.render_device.findData(self._values.get("render_device", "auto"))
        self.render_device.setCurrentIndex(max(0, selected))
        performance.addRow("Image processing", self.render_device)
        try:
            import gpu_acceleration
            gpu_acceleration.configure(self.render_device.currentData())
            self.gpu_status = QLabel(gpu_acceleration.status()["label"])
        except Exception as exc:  # noqa: BLE001
            self.gpu_status = QLabel(f"CPU fallback — {exc}")
        self.gpu_status.setWordWrap(True)
        self.gpu_status.setObjectName("ControlValue")
        performance.addRow("Detected", self.gpu_status)
        root.addLayout(performance)

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
        self.include_subfolders = QCheckBox(
            "Include subfolders when browsing the library")
        self.include_subfolders.setChecked(
            bool(self._values.get("library_include_subfolders", False)))
        folders.addRow("", self.include_subfolders)
        root.addLayout(folders)

        # --- SS-1350: DEV LOG ------------------------------------------------
        # The switch is shared by every SnapSmack desktop tool, not stored in
        # this one's preferences, so it is read from and written to snap_log
        # rather than self._values. That is why it takes effect the moment it
        # is clicked instead of on Save: a trace you have to restart for is
        # useless when you are trying to catch something that is happening now.
        diag_title = QLabel("DIAGNOSTICS")
        diag_title.setObjectName("ControlName")
        root.addWidget(diag_title)
        diag = QFormLayout()
        diag.setSpacing(10)
        self.dev_log = QCheckBox(
            "DEV LOG — record what ran, when, and for how long")
        self.dev_log.setChecked(self._dev_log_enabled())
        self.dev_log.setToolTip(
            "Writes a detailed trace to the SnapSmack logs folder: process "
            "start and exit, windows opening and closing, timers, background "
            "tasks and child programs. No passwords, keys or image data are "
            "ever written. Takes effect immediately, applies to every "
            "SnapSmack desktop tool, and the log is capped so it cannot fill "
            "the disk.")
        self.dev_log.toggled.connect(self._toggle_dev_log)
        diag.addRow("", self.dev_log)

        log_row = QHBoxLayout()
        open_logs = QPushButton("OPEN LOG FOLDER")
        open_logs.setObjectName("LayerOrderBtn")
        open_logs.clicked.connect(self._open_log_folder)
        log_row.addWidget(open_logs)
        log_row.addStretch(1)
        diag.addRow("", self._wrap(log_row))
        root.addLayout(diag)

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

    # --- SS-1350 helpers. Each one survives snap_log being unavailable, because
    # a diagnostics control must never be the reason Preferences will not open.
    @staticmethod
    def _dev_log_enabled():
        try:
            import snap_log
            return snap_log.dev_log_enabled()
        except Exception:  # noqa: BLE001
            return False

    def _toggle_dev_log(self, on):
        try:
            import snap_log
            if not snap_log.set_dev_log(on):
                QMessageBox.warning(
                    self, "DEV LOG",
                    "The setting could not be saved. The logs folder may be "
                    "read-only.")
                self.dev_log.setChecked(self._dev_log_enabled())
        except Exception as exc:  # noqa: BLE001
            QMessageBox.warning(self, "DEV LOG", str(exc))

    def _open_log_folder(self):
        try:
            import snap_log
            folder = snap_log.log_folder()
        except Exception as exc:  # noqa: BLE001
            QMessageBox.warning(self, "Logs", str(exc))
            return
        QDesktopServices.openUrl(QUrl.fromLocalFile(folder))

    def _save(self):
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
            "render_device": self.render_device.currentData(),
        })
        prefs.save(self._values)
        try:
            import gpu_acceleration
            gpu_acceleration.configure(self.render_device.currentData())
        except Exception:  # noqa: BLE001
            pass
        self.accept()

# ===== SNAPSMACK EOF =====
