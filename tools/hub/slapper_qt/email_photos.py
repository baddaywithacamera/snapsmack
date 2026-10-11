"""Emailing photographs: make sending-sized copies, then hand them to the
photographer's own mail program.

SNAP SLAPPER never sends anything. It prepares the copies and opens a draft;
pressing Send is the photographer's, always. There is no SMTP server here, no
address book and no account credentials -- nothing to leak and nothing to
configure.

Two routes, because Windows has two kinds of mail program:

* A desktop client registered for Simple MAPI (Outlook, Thunderbird, eM
  Client, Windows Mail) can be handed the attachments directly, with the
  compose window shown so nothing leaves without a click.
* Webmail in a browser is not reachable that way -- no operating system call
  can put a file into a Gmail tab. For that the copies are left in one folder,
  the folder is opened, and a draft with the subject and message is started so
  the files can be dragged in. Said plainly rather than failing silently.
"""

import ctypes
import os
import subprocess
import sys
import urllib.parse

from PySide6.QtCore import QUrl
from PySide6.QtGui import QDesktopServices
from PySide6.QtWidgets import (
    QCheckBox, QComboBox, QDialog, QHBoxLayout, QLabel, QLineEdit,
    QMessageBox, QPushButton, QVBoxLayout,
)

import photo_manager

# Simple MAPI, from MAPI32.DLL. MAPI_DIALOG is the important flag: it SHOWS
# the compose window instead of sending. Nothing is ever sent by SNAP SLAPPER.
MAPI_DIALOG = 0x00000008
MAPI_LOGON_UI = 0x00000001
SUCCESS_SUCCESS = 0
MAPI_USER_ABORT = 1
MAPI_E_FAILURE = 2

SIZES = (
    (1024, "Small — 1024 pixels, quickest to send"),
    (1600, "Medium — 1600 pixels, good on a screen"),
    (2400, "Large — 2400 pixels"),
    (0, "Original size — can be very large"),
)


class _MapiRecipDesc(ctypes.Structure):
    _fields_ = [("ulReserved", ctypes.c_ulong),
                ("ulRecipClass", ctypes.c_ulong),
                ("lpszName", ctypes.c_char_p),
                ("lpszAddress", ctypes.c_char_p),
                ("ulEIDSize", ctypes.c_ulong),
                ("lpEntryID", ctypes.c_void_p)]


class _MapiFileDesc(ctypes.Structure):
    _fields_ = [("ulReserved", ctypes.c_ulong),
                ("flFlags", ctypes.c_ulong),
                ("nPosition", ctypes.c_ulong),
                ("lpszPathName", ctypes.c_char_p),
                ("lpszFileName", ctypes.c_char_p),
                ("lpFileType", ctypes.c_void_p)]


class _MapiMessage(ctypes.Structure):
    _fields_ = [("ulReserved", ctypes.c_ulong),
                ("lpszSubject", ctypes.c_char_p),
                ("lpszNoteText", ctypes.c_char_p),
                ("lpszMessageType", ctypes.c_char_p),
                ("lpszDateReceived", ctypes.c_char_p),
                ("lpszConversationID", ctypes.c_char_p),
                ("flFlags", ctypes.c_ulong),
                ("lpOriginator", ctypes.POINTER(_MapiRecipDesc)),
                ("nRecipCount", ctypes.c_ulong),
                ("lpRecips", ctypes.POINTER(_MapiRecipDesc)),
                ("nFileCount", ctypes.c_ulong),
                ("lpFiles", ctypes.POINTER(_MapiFileDesc))]


def mapi_available():
    """Whether a desktop mail client can be handed attachments."""
    if not sys.platform.startswith("win"):
        return False
    try:
        return hasattr(ctypes.WinDLL("MAPI32.DLL"), "MAPISendMail")
    except (OSError, AttributeError):
        return False


def outbox_folder():
    """Where sending-sized copies are written. Inside SNAP SLAPPER's own
    library, never beside the photographer's originals."""
    import snap_home
    return os.path.join(snap_home.shared_library(), "snap_slapper", "email")


def prepare_copies(paths, destination, max_size=1600, quality=88,
                   strip_location=True):
    """Write sending-sized JPEG copies. Originals are never touched.

    Location data is removed by default: an email leaves the photographer's
    control the moment it is sent, and a holiday snapshot carries the
    coordinates of wherever it was taken.
    """
    os.makedirs(destination, exist_ok=True)
    return photo_manager.export_files(
        paths, destination, max_size=max_size, quality=quality,
        strip_gps=strip_location)


def mailto_url(subject="", body=""):
    query = urllib.parse.urlencode({"subject": subject, "body": body},
                                   quote_via=urllib.parse.quote)
    return "mailto:?" + query


def send_with_mapi(paths, subject="", body=""):
    """Open a draft in the desktop mail client with ``paths`` attached.

    Returns True when the client took it. MAPI_DIALOG means the photographer
    sees the message and presses Send; this function never sends.
    """
    if not mapi_available():
        return False
    files = (_MapiFileDesc * len(paths))()
    for index, path in enumerate(paths):
        files[index].ulReserved = 0
        files[index].flFlags = 0
        files[index].nPosition = 0xFFFFFFFF
        files[index].lpszPathName = os.path.abspath(path).encode("mbcs")
        files[index].lpszFileName = os.path.basename(path).encode("mbcs")
        files[index].lpFileType = None
    message = _MapiMessage()
    message.lpszSubject = subject.encode("mbcs", "replace") or None
    message.lpszNoteText = body.encode("mbcs", "replace") or None
    message.nFileCount = len(paths)
    message.lpFiles = files
    try:
        mapi = ctypes.WinDLL("MAPI32.DLL")
        result = mapi.MAPISendMail(
            ctypes.c_void_p(0), ctypes.c_void_p(0), ctypes.byref(message),
            ctypes.c_ulong(MAPI_DIALOG | MAPI_LOGON_UI), ctypes.c_ulong(0))
    except OSError:
        return False
    # An abort is the photographer closing the draft. That is not a failure
    # and must not send them round the fallback route.
    return result in (SUCCESS_SUCCESS, MAPI_USER_ABORT)


def reveal(folder):
    """Open a folder in the file manager, so copies can be dragged into webmail."""
    folder = os.path.abspath(folder)
    if sys.platform.startswith("win"):
        os.startfile(folder)  # noqa: S606 — a folder path we built ourselves
        return True
    opener = "open" if sys.platform == "darwin" else "xdg-open"
    try:
        subprocess.Popen([opener, folder])
        return True
    except OSError:
        return False



class EmailPhotosDialog(QDialog):
    """Make sending-sized copies, then open a draft in the mail program."""

    def __init__(self, parent, paths, title="Email Photographs"):
        super().__init__(parent)
        self.paths = [path for path in paths if path]
        self.outputs = []
        self.setWindowTitle(title)
        self.resize(560, 360)
        layout = QVBoxLayout(self)
        heading = QLabel("EMAIL")
        heading.setObjectName("SectionTitle")
        layout.addWidget(heading)
        self.summary = QLabel()
        self.summary.setWordWrap(True)
        layout.addWidget(self.summary)
        row = QHBoxLayout()
        row.addWidget(QLabel("Send at"))
        self.size_choice = QComboBox()
        for pixels, label in SIZES:
            self.size_choice.addItem(label, pixels)
        self.size_choice.setCurrentIndex(1)
        self.size_choice.currentIndexChanged.connect(self._refresh)
        row.addWidget(self.size_choice, 1)
        layout.addLayout(row)
        self.strip_location = QCheckBox(
            "Remove the location the photograph was taken")
        self.strip_location.setChecked(True)
        self.strip_location.setToolTip(
            "An email is out of your hands once it is sent, and a photograph "
            "normally carries the coordinates of where it was taken.")
        layout.addWidget(self.strip_location)
        self.subject = QLineEdit()
        self.subject.setPlaceholderText("Subject")
        layout.addWidget(self.subject)
        self.body = QLineEdit()
        self.body.setPlaceholderText("A line to go with them (optional)")
        layout.addWidget(self.body)
        self.route = QLabel()
        self.route.setWordWrap(True)
        layout.addWidget(self.route)
        actions = QHBoxLayout()
        actions.addStretch(1)
        cancel = QPushButton("CANCEL")
        cancel.clicked.connect(self.reject)
        actions.addWidget(cancel)
        self.go = QPushButton("PREPARE AND OPEN A DRAFT")
        self.go.setObjectName("LayerAddBtn")
        self.go.clicked.connect(self._prepare)
        actions.addWidget(self.go)
        layout.addLayout(actions)
        self._refresh()

    def _refresh(self):
        pixels = self.size_choice.currentData()
        self.summary.setText(
            f"{len(self.paths):,} photograph(s). Copies are made for sending; "
            "your originals are not touched or changed.")
        self.route.setText(
            "Your mail program will open with them attached. Nothing is sent "
            "until you press Send."
            if mapi_available() else
            "No desktop mail program is registered on this computer, so the "
            "copies cannot be attached for you. They will be left in one "
            "folder, that folder will open, and a draft will be started -- "
            "drag the files into it. Webmail in a browser cannot be given "
            "files by any other program.")
        self.go.setEnabled(bool(self.paths))

    def _prepare(self):
        from PySide6.QtWidgets import QApplication
        from PySide6.QtCore import Qt
        folder = outbox_folder()
        QApplication.setOverrideCursor(Qt.WaitCursor)
        try:
            self.outputs = prepare_copies(
                self.paths, folder,
                max_size=int(self.size_choice.currentData() or 0),
                strip_location=self.strip_location.isChecked())
        except Exception as error:  # noqa: BLE001
            QApplication.restoreOverrideCursor()
            QMessageBox.warning(self, "The copies could not be made", str(error))
            return
        finally:
            QApplication.restoreOverrideCursor()
        subject = self.subject.text().strip() or "Photographs"
        body = self.body.text().strip()
        if send_with_mapi(self.outputs, subject, body):
            self.accept()
            return
        reveal(folder)
        QDesktopServices.openUrl(QUrl(mailto_url(subject, body)))
        total = sum(os.path.getsize(path) for path in self.outputs
                    if os.path.isfile(path))
        QMessageBox.information(
            self, "Ready to attach",
            f"{len(self.outputs):,} copy/copies ({total / 1048576:.1f} MB) are "
            f"in:\n\n{folder}\n\nThat folder is open and a draft has been "
            "started. Drag the files into the message, then send it.")
        self.accept()


# ===== SNAPSMACK EOF =====
