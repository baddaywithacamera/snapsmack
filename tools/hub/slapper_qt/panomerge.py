"""PANOMERGE — a friendly SNAP SLAPPER front end for the external XPANO CLI.

XPANO is never bundled or imported.  The user installs it separately and this
module invokes its documented command line with an argument list (never a
shell), keeping both the process boundary and the licensing boundary explicit.
"""

from dataclasses import dataclass
import os
import shutil
import sys

from PySide6.QtCore import Qt, QProcess, QTimer, Signal, QUrl
from PySide6.QtGui import QDesktopServices
from PySide6.QtWidgets import (
    QAbstractItemView, QDialog, QFileDialog, QHBoxLayout, QLabel, QListWidget,
    QListWidgetItem, QMessageBox, QProgressBar, QPushButton, QVBoxLayout,
)


@dataclass(frozen=True)
class XpanoEngine:
    label: str
    command: tuple


def platform_supported():
    """SNAPSMACK companion-app policy: Windows and Linux, never macOS."""
    return os.name == "nt" or sys.platform.startswith("linux")


def _unique_existing(paths):
    result = []
    seen = set()
    for path in paths:
        if not path:
            continue
        absolute = os.path.abspath(os.path.expanduser(path))
        key = os.path.normcase(absolute)
        if key not in seen and os.path.isfile(absolute):
            seen.add(key)
            result.append(absolute)
    return result


def detect_xpano(configured_path=""):
    """Return available XPANO engines, preferring the user's chosen binary."""
    if not platform_supported():
        return []
    candidates = [configured_path]
    for name in ("Xpano.exe", "xpano.exe", "Xpano", "xpano"):
        found = shutil.which(name)
        if found:
            candidates.append(found)
    if os.name == "nt":
        local = os.environ.get("LOCALAPPDATA", "")
        program_files = [os.environ.get("ProgramFiles", ""),
                         os.environ.get("ProgramFiles(x86)", "")]
        candidates.extend([
            os.path.join(local, "Microsoft", "WindowsApps", "Xpano.exe"),
            os.path.join(local, "Programs", "Xpano", "Xpano.exe"),
            *(os.path.join(root, folder, "Xpano.exe")
              for root in program_files for folder in ("Xpano", "XPano") if root),
        ])
    engines = [XpanoEngine("XPANO", (path,)) for path in _unique_existing(candidates)]

    # Flathub's build is also external. Only advertise it when its installed
    # app directory is present; merely having Flatpak is not enough.
    if sys.platform.startswith("linux"):
        flatpak = shutil.which("flatpak")
        flatpak_roots = (
            "/var/lib/flatpak/app/cz.krupkat.Xpano",
            os.path.expanduser("~/.local/share/flatpak/app/cz.krupkat.Xpano"),
        )
        if flatpak and any(os.path.isdir(path) for path in flatpak_roots):
            engines.append(XpanoEngine(
                "XPANO (Flathub)", (flatpak, "run", "cz.krupkat.Xpano")))
    return engines


def build_command(engine, paths, output_path):
    """Build XPANO's documented automatic-stitch command without a shell."""
    photos = _unique_existing(paths)
    if len(photos) < 2:
        raise ValueError("PANOMERGE needs at least two readable photographs.")
    output = os.path.abspath(output_path)
    return list(engine.command) + photos + [f"--output={output}"]


class PanomergeDialog(QDialog):
    """Order photographs, run XPANO asynchronously, and return its panorama."""

    panorama_ready = Signal(str)

    def __init__(self, paths, parent=None):
        super().__init__(parent)
        self.setWindowTitle("PANOMERGE — Stitch a Panorama")
        self.setMinimumSize(720, 520)
        self.result_path = ""
        self.process = None
        self._output = ""

        from . import prefs
        settings = prefs.load()
        configured = settings.get("panomerge_xpano_path", "")
        self.engines = detect_xpano(configured)
        self.engine = self.engines[0] if self.engines else None

        layout = QVBoxLayout(self)
        title = QLabel("PANOMERGE")
        title.setStyleSheet("font-size:22px;font-weight:800;color:#39FF14;")
        layout.addWidget(title)
        intro = QLabel(
            "Select overlapping photographs, put them in shooting order, then merge. "
            "Your originals are never changed. XPANO runs as a separately installed tool.")
        intro.setWordWrap(True)
        layout.addWidget(intro)

        self.list = QListWidget()
        self.list.setSelectionMode(QAbstractItemView.ExtendedSelection)
        self.list.setDragDropMode(QAbstractItemView.InternalMove)
        self.list.setDefaultDropAction(Qt.MoveAction)
        for path in _unique_existing(paths):
            item = QListWidgetItem(os.path.basename(path))
            item.setData(Qt.UserRole, path)
            item.setToolTip(path)
            self.list.addItem(item)
        layout.addWidget(self.list, 1)

        controls = QHBoxLayout()
        add = QPushButton("Add Photos…")
        add.clicked.connect(self._add_photos)
        remove = QPushButton("Remove")
        remove.clicked.connect(self._remove_selected)
        up = QPushButton("Move Up")
        up.clicked.connect(lambda: self._move_selected(-1))
        down = QPushButton("Move Down")
        down.clicked.connect(lambda: self._move_selected(1))
        for button in (add, remove, up, down):
            controls.addWidget(button)
        controls.addStretch(1)
        layout.addLayout(controls)

        engine_row = QHBoxLayout()
        self.engine_label = QLabel()
        engine_row.addWidget(self.engine_label, 1)
        get_xpano = QPushButton("GET XPANO")
        get_xpano.setToolTip("Open XPANO's official releases page")
        get_xpano.clicked.connect(lambda: QDesktopServices.openUrl(
            QUrl("https://github.com/krupkat/xpano/releases/latest")))
        engine_row.addWidget(get_xpano)
        choose = QPushButton("Choose XPANO…")
        choose.clicked.connect(self._choose_engine)
        engine_row.addWidget(choose)
        layout.addLayout(engine_row)
        self._show_engine()

        self.progress = QProgressBar()
        self.progress.setRange(0, 1)
        self.progress.setValue(0)
        self.progress.setFormat("Ready")
        layout.addWidget(self.progress)
        self.detail = QLabel("Automatic alignment, stitching, blending, and full-resolution export.")
        self.detail.setWordWrap(True)
        layout.addWidget(self.detail)

        buttons = QHBoxLayout()
        buttons.addStretch(1)
        self.cancel_button = QPushButton("Cancel")
        self.cancel_button.clicked.connect(self._cancel_or_close)
        self.merge_button = QPushButton("MERGE PANORAMA…")
        self.merge_button.setObjectName("PrimaryButton")
        self.merge_button.clicked.connect(self._start)
        buttons.addWidget(self.cancel_button)
        buttons.addWidget(self.merge_button)
        layout.addLayout(buttons)

    def paths(self):
        return [self.list.item(index).data(Qt.UserRole)
                for index in range(self.list.count())]

    def _show_engine(self):
        if self.engine:
            self.engine_label.setText(
                f"Engine: {self.engine.label} — {self.engine.command[0]}")
        else:
            self.engine_label.setText(
                "XPANO not found. Install it separately, or choose its executable.")

    def _add_photos(self):
        paths, _ = QFileDialog.getOpenFileNames(
            self, "Add photographs to PANOMERGE", "",
            "Photographs (*.jpg *.jpeg *.png *.tif *.tiff *.webp *.bmp);;All files (*)")
        existing = {os.path.normcase(os.path.abspath(path)) for path in self.paths()}
        for path in _unique_existing(paths):
            if os.path.normcase(path) in existing:
                continue
            item = QListWidgetItem(os.path.basename(path))
            item.setData(Qt.UserRole, path)
            item.setToolTip(path)
            self.list.addItem(item)
            existing.add(os.path.normcase(path))

    def _remove_selected(self):
        for item in self.list.selectedItems():
            self.list.takeItem(self.list.row(item))

    def _move_selected(self, direction):
        rows = sorted((self.list.row(item) for item in self.list.selectedItems()),
                      reverse=direction > 0)
        for row in rows:
            target = row + direction
            if target < 0 or target >= self.list.count():
                continue
            item = self.list.takeItem(row)
            self.list.insertItem(target, item)
            item.setSelected(True)
            self.list.setCurrentItem(item)

    def _choose_engine(self):
        suffix = "Applications (*.exe);;All files (*)" if os.name == "nt" else "All files (*)"
        path, _ = QFileDialog.getOpenFileName(self, "Choose the XPANO executable", "", suffix)
        if not path:
            return
        self.engine = XpanoEngine("XPANO", (os.path.abspath(path),))
        from . import prefs
        values = prefs.load()
        values["panomerge_xpano_path"] = os.path.abspath(path)
        prefs.save(values)
        self._show_engine()

    def _start(self):
        if not platform_supported():
            QMessageBox.warning(self, "PANOMERGE unavailable",
                                "PANOMERGE supports Windows and Linux only.")
            return
        if not self.engine:
            QMessageBox.information(
                self, "XPANO required",
                "Install XPANO, then click Choose XPANO and select its executable.")
            return
        if len(self.paths()) < 2:
            QMessageBox.information(self, "More photographs needed",
                                    "Select at least two overlapping photographs.")
            return
        start = os.path.dirname(self.paths()[0])
        output, _ = QFileDialog.getSaveFileName(
            self, "Save merged panorama", os.path.join(start, "panorama.tif"),
            "TIFF (*.tif *.tiff);;JPEG (*.jpg *.jpeg);;PNG (*.png)")
        if not output:
            return
        try:
            command = build_command(self.engine, self.paths(), output)
            if os.path.exists(output):
                os.remove(output)  # QFileDialog already confirmed replacement
        except (OSError, ValueError) as error:
            QMessageBox.warning(self, "Cannot start PANOMERGE", str(error))
            return

        self._output = os.path.abspath(output)
        self.process = QProcess(self)
        self.process.setProgram(command[0])
        self.process.setArguments(command[1:])
        self.process.setWorkingDirectory(os.path.dirname(self._output))
        self.process.setProcessChannelMode(QProcess.MergedChannels)
        self.process.readyReadStandardOutput.connect(self._read_output)
        self.process.errorOccurred.connect(self._process_error)
        self.process.finished.connect(self._finished)
        self.progress.setRange(0, 0)
        self.progress.setFormat("PANOMERGE is aligning and stitching…")
        self.detail.setText("Working… large panoramas can take several minutes.")
        self.merge_button.setEnabled(False)
        self.list.setEnabled(False)
        self.cancel_button.setText("Stop")
        self.process.start()

    def _read_output(self):
        if not self.process:
            return
        text = bytes(self.process.readAllStandardOutput()).decode("utf-8", "replace").strip()
        if text:
            self.detail.setText(text[-500:])

    def _process_error(self, error):
        if error == QProcess.FailedToStart:
            self.detail.setText("XPANO could not be started. Choose its executable and try again.")

    def _finished(self, exit_code, _status):
        succeeded = exit_code == 0 and os.path.isfile(self._output) and \
            os.path.getsize(self._output) > 0
        self.progress.setRange(0, 1)
        self.progress.setValue(1 if succeeded else 0)
        self.list.setEnabled(True)
        self.merge_button.setEnabled(True)
        self.cancel_button.setText("Close")
        if succeeded:
            self.result_path = self._output
            self.progress.setFormat("Panorama complete")
            self.detail.setText(f"Created {self._output}")
            self.panorama_ready.emit(self.result_path)
            self.accept()
        else:
            output = self.detail.text()
            self.progress.setFormat("PANOMERGE failed")
            QMessageBox.warning(
                self, "PANOMERGE could not finish",
                "XPANO did not create the panorama. Check that the photographs "
                "overlap and are in shooting order.\n\n" + output[-700:])
        self.process = None

    def _cancel_or_close(self):
        if self.process and self.process.state() != QProcess.NotRunning:
            self.process.terminate()
            QTimer.singleShot(1500, self._kill_if_running)
            self.detail.setText("Stopping PANOMERGE…")
        else:
            self.reject()

    def _kill_if_running(self):
        if self.process and self.process.state() != QProcess.NotRunning:
            self.process.kill()

    def reject(self):
        if self.process and self.process.state() != QProcess.NotRunning:
            self._cancel_or_close()
            return
        super().reject()


# ===== SNAPSMACK EOF =====
