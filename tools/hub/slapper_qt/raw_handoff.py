"""Safe handoff of untouched RAW photographs to an external RAW editor."""

import os
import shutil
import subprocess

from PySide6.QtWidgets import QFileDialog, QMessageBox


def detected_editors():
    candidates = []
    names = (("RawTherapee", "rawtherapee.exe" if os.name == "nt" else "rawtherapee"),
             ("darktable", "darktable.exe" if os.name == "nt" else "darktable"))
    for label, executable in names:
        found = shutil.which(executable)
        if found:
            candidates.append((label, found))
    if os.name == "nt":
        roots = [os.environ.get("ProgramFiles", ""),
                 os.environ.get("ProgramFiles(x86)", "")]
        known = (("RawTherapee", "RawTherapee", "rawtherapee.exe"),
                 ("darktable", "darktable", "bin", "darktable.exe"))
        for root in roots:
            for entry in known:
                path = os.path.join(root, *entry[1:]) if root else ""
                if path and os.path.isfile(path) and not any(p == path for _, p in candidates):
                    candidates.append((entry[0], path))
    return candidates


def launch(executable, photo_path):
    """Launch without a shell so paths and filenames are never interpreted."""
    subprocess.Popen([os.path.abspath(executable), os.path.abspath(photo_path)],
                     shell=False, close_fds=True)


def offer_raw_handoff(photo_path, parent=None):
    editors = detected_editors()
    box = QMessageBox(parent)
    box.setIcon(QMessageBox.Information)
    box.setWindowTitle("Open RAW photograph")
    box.setText("SNAP SLAPPER does not develop RAW photographs.")
    box.setInformativeText(
        "Open the untouched original in RawTherapee, darktable, or another program.")
    choices = {}
    for label, executable in editors:
        choices[box.addButton(f"Open in {label}", QMessageBox.AcceptRole)] = executable
    choose = box.addButton("Choose another program…", QMessageBox.ActionRole)
    box.addButton(QMessageBox.Cancel)
    box.exec()
    clicked = box.clickedButton()
    executable = choices.get(clicked)
    if clicked is choose:
        executable, _ = QFileDialog.getOpenFileName(
            parent, "Choose RAW editor", "", "Applications (*.exe);;All files (*)")
    if executable:
        try:
            launch(executable, photo_path)
            return True
        except OSError as exc:
            QMessageBox.warning(parent, "Could not open RAW editor", str(exc))
    return False


# ===== SNAPSMACK EOF =====
