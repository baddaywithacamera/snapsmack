"""Tracked first-run notice for SNAP SLAPPER generative operations."""

from PySide6.QtWidgets import QCheckBox, QMessageBox

from . import prefs


NOTICE_TITLE = "Before your first AI edit — how SNAP SLAPPER records it"
NOTICE_TEXT = (
    "SNAP SLAPPER keeps an embedded record of AI and other substantial edits "
    "and writes it into the files you export. This is deliberate and cannot be "
    "turned off — a permanent, honest edit trail is the point of the tool.\n\n"
    "That record can include which AI operations ran, how much of the image they "
    "affected, the provider, and the time. The instruction you type is sent to "
    "that provider and retained in your local .slapper project, but it is not "
    "written into the exported photo.\n\n"
    "The exported summary travels wherever you post or send the photo. Your "
    ".slapper project also stores the original photograph and exact instruction, "
    "so keep that in mind before sharing the project itself.\n\n"
    "SNAP SLAPPER collects no identity, telemetry, or per-user edit log. You can "
    "strip metadata later with another tool; SNAP SLAPPER will not do it for you."
)


def confirm(parent):
    """Return true only after an affirmative first-run acknowledgement."""
    values = prefs.load()
    if values.get("generative_notice_acknowledged", False):
        return True
    box = QMessageBox(parent)
    box.setIcon(QMessageBox.Information)
    box.setWindowTitle(NOTICE_TITLE)
    box.setText(NOTICE_TEXT)
    box.setStandardButtons(QMessageBox.Ok | QMessageBox.Cancel)
    box.setDefaultButton(QMessageBox.Cancel)
    box.button(QMessageBox.Ok).setText("I understand — continue")
    remember = QCheckBox("Don't show this again")
    remember.setChecked(True)
    box.setCheckBox(remember)
    if box.exec() != QMessageBox.Ok:
        return False
    if remember.isChecked():
        values["generative_notice_acknowledged"] = True
        prefs.save(values)
    return True


def confirm_send(parent, preference, title, text):
    """Confirm a provider upload, with a persistent per-tool dismissal."""
    values = prefs.load()
    if values.get(preference, False):
        return True
    box = QMessageBox(parent)
    box.setIcon(QMessageBox.Question)
    box.setWindowTitle(title)
    box.setText(text)
    box.setStandardButtons(QMessageBox.Yes | QMessageBox.No)
    box.setDefaultButton(QMessageBox.No)
    remember = QCheckBox("Do not display again")
    box.setCheckBox(remember)
    if box.exec() != QMessageBox.Yes:
        return False
    if remember.isChecked():
        values[preference] = True
        prefs.save(values)
    return True

# ===== SNAPSMACK EOF =====
