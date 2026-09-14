"""FED UP — Qt shell. Fediverse profile backup, restore into SnapSmack, and the
@you@photoblogs.fyi alias. Spec: _spec/fed-up-spec-v0_1.md + v0_2.md.

This is the cockpit with the three functions laid out and labelled by what is
actually built. Nothing here pretends to work: a tab that is not built says so
in its own body, in plain words, and offers no button that would do nothing.
The look copies SNAP HQ / SUYB (dark, lime accent) so it drops into the suite
without a visual seam.
"""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
from __future__ import annotations

import os
import re
import sys
from pathlib import Path

from PySide6.QtCore import Qt
from PySide6.QtGui import QIcon
from PySide6.QtWidgets import (QApplication, QFrame, QHBoxLayout, QLabel, QLineEdit,
                               QMainWindow, QPushButton, QTabWidget, QVBoxLayout, QWidget)

from _version import BUILD_VERSION

STYLE = """
QWidget { background:#090b0a; color:#eef2ed; font-family:'Segoe UI'; font-size:13px; }
QMainWindow { background:#090b0a; }
QFrame#header { background:#0e1310; border-bottom:1px solid #243028; }
QFrame#section { background:#101511; border:1px solid #29342c; border-radius:14px; }
QLabel#brand { color:#63ef3d; font-size:27px; font-weight:900; }
QLabel#tag { color:#94a098; font-size:13px; }
QLabel#sectionTitle { color:#63ef3d; font-size:12px; font-weight:800; }
QLabel#muted { color:#8d9a90; }
QLabel#status { color:#ffb454; font-weight:700; }
QPushButton { background:#1b241e; border:1px solid #334238; border-radius:8px; padding:8px 13px; font-weight:700; }
QPushButton:hover { border-color:#63ef3d; background:#243129; }
QPushButton#primary { background:#63ef3d; color:#071006; border-color:#63ef3d; }
QPushButton:disabled { color:#5c665f; border-color:#243028; }
QLineEdit { background:#0b0f0c; border:1px solid #334238; border-radius:8px; padding:9px; }
QTabWidget::pane { border:1px solid #29342c; border-radius:12px; background:#101511; }
QTabBar::tab { background:#0e1310; color:#94a098; padding:9px 18px; border:1px solid #243028; border-bottom:0;
               border-top-left-radius:9px; border-top-right-radius:9px; font-weight:800; }
QTabBar::tab:selected { background:#101511; color:#63ef3d; }
"""

HANDLE_RE = re.compile(r"^@?([A-Za-z0-9_.-]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,})$")


def _asset(name: str) -> str:
    root = getattr(sys, "_MEIPASS", str(Path(__file__).resolve().parents[1]))
    return os.path.join(root, "assets", name)


def _section(title: str, body: str, status: str) -> QFrame:
    """One function of the tool, stated honestly: what it will do, and where it is."""
    frame = QFrame(); frame.setObjectName("section")
    lay = QVBoxLayout(frame); lay.setContentsMargins(18, 14, 18, 16); lay.setSpacing(8)
    head = QLabel(title); head.setObjectName("sectionTitle"); lay.addWidget(head)
    text = QLabel(body); text.setWordWrap(True); lay.addWidget(text)
    state = QLabel(status); state.setObjectName("status"); lay.addWidget(state)
    return frame


class BackupTab(QWidget):
    """Function 1. Only the handle field is live; it validates and nothing else."""

    def __init__(self, parent=None):
        super().__init__(parent)
        lay = QVBoxLayout(self); lay.setContentsMargins(16, 16, 16, 16); lay.setSpacing(12)
        lay.addWidget(_section(
            "BACK UP YOUR FEDIVERSE PROFILE",
            "Profile, every post with its original date, media, like/boost/reply counts, and your "
            "follower and following lists — from Mastodon, Pixelfed, GoToSocial, or another SnapSmack "
            "site — into a folder of plain files you keep. Full first, then incremental. Optional "
            "copy to your own cloud storage through the same door SMACK UP YOUR BACKUP uses.",
            "NOT BUILT YET — spec v0.2, function 1."))
        row = QHBoxLayout()
        cap = QLabel("Account"); cap.setMinimumWidth(90); row.addWidget(cap)
        self.handle = QLineEdit(); self.handle.setPlaceholderText("@you@your.instance")
        self.handle.textChanged.connect(self._check); row.addWidget(self.handle, 1)
        self.go = QPushButton("BACK UP"); self.go.setObjectName("primary"); self.go.setEnabled(False)
        self.go.setToolTip("Not built yet."); row.addWidget(self.go)
        lay.addLayout(row)
        self.note = QLabel(""); self.note.setObjectName("muted"); lay.addWidget(self.note)
        lay.addStretch(1)

    def _check(self, text: str):
        match = HANDLE_RE.match(text.strip())
        self.note.setText(f"Looks like a handle on {match.group(2)}." if match
                          else ("" if not text.strip() else "A handle looks like @name@instance."))


class RestoreTab(QWidget):
    """Function 2."""

    def __init__(self, parent=None):
        super().__init__(parent)
        lay = QVBoxLayout(self); lay.setContentsMargins(16, 16, 16, 16); lay.setSpacing(12)
        lay.addWidget(_section(
            "BITCHSLAP IT INTO SNAPSMACK",
            "Read a FED UP archive and rebuild the posts on your SnapSmack site with their real dates, "
            "captions, tags, and media; restore the profile. Followers come across by ActivityPub Move "
            "if your old server is still alive and cooperative; if it is not, you get the list of who "
            "followed you, so you can tell them where you went.",
            "NOT BUILT YET — spec v0.2, function 2. Rides UNZUCKER's poster."))
        lay.addStretch(1)


class AliasTab(QWidget):
    """Function 3 — lives in the CMS, not here; this tab explains and points."""

    def __init__(self, parent=None):
        super().__init__(parent)
        lay = QVBoxLayout(self); lay.setContentsMargins(16, 16, 16, 16); lay.setSpacing(12)
        lay.addWidget(_section(
            "@YOU@PHOTOBLOGS.FYI",
            "A fediverse handle on a domain we keep alive, pointing at your blog's own actor. Your "
            "identity stops depending on a server you do not own. Set on your site (Fediverse Config) "
            "and on the hub; nothing to run here.",
            "NOT BUILT YET — spec v0.2, function 3. This one is a CMS build, first in line."))
        lay.addStretch(1)


class Window(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle(f"FED UP — fed up with your server  ·  {BUILD_VERSION}")
        self.resize(900, 620); self.setMinimumSize(720, 520)
        root = QWidget(); self.setCentralWidget(root)
        outer = QVBoxLayout(root); outer.setContentsMargins(0, 0, 0, 0); outer.setSpacing(0)

        header = QFrame(); header.setObjectName("header")
        hl = QHBoxLayout(header); hl.setContentsMargins(22, 14, 22, 14)
        brand = QLabel("FED UP"); brand.setObjectName("brand"); hl.addWidget(brand)
        tag = QLabel("fediverse backup · migration · identity"); tag.setObjectName("tag"); hl.addWidget(tag)
        hl.addStretch(1)
        outer.addWidget(header)

        tabs = QTabWidget()
        tabs.addTab(BackupTab(), "BACK UP")
        tabs.addTab(RestoreTab(), "RESTORE")
        tabs.addTab(AliasTab(), "ALIAS")
        body = QWidget(); bl = QVBoxLayout(body); bl.setContentsMargins(18, 18, 18, 18); bl.addWidget(tabs)
        outer.addWidget(body, 1)

        foot = QLabel("Your identity should not depend on someone else's server. Nothing in this window "
                      "sends anything anywhere yet.")
        foot.setObjectName("muted"); foot.setContentsMargins(22, 0, 22, 14); foot.setWordWrap(True)
        outer.addWidget(foot)


def run() -> int:
    app = QApplication.instance() or QApplication(sys.argv)
    app.setStyle("Fusion"); app.setStyleSheet(STYLE)
    app.setApplicationName("FED UP")
    icon = _asset("fed-up.png")
    if os.path.isfile(icon):
        app.setWindowIcon(QIcon(icon))
    window = Window(); window.show()
    return app.exec()

# ===== SNAPSMACK EOF =====
