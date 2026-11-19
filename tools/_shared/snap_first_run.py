"""Shared first-run 'What this app does / doesn't do' screen for SNAPSMACK desktop tools."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import json
import os
from typing import Sequence

from PySide6.QtWidgets import (
    QCheckBox, QDialog, QFrame, QHBoxLayout, QLabel, QPushButton,
    QVBoxLayout,
)

import snap_home

_STYLE = """
QDialog { background:#0d120f; color:#f4f7f2; font-family:'Segoe UI'; font-size:14px; }
QFrame#panel { background:#17221b; border:1px solid #2b3d31; border-radius:10px; }
QLabel { background:transparent; color:#f4f7f2; }
QLabel#title { color:#73f04b; font-size:20px; font-weight:800; }
QLabel#doHeading { color:#73f04b; font-size:13px; font-weight:800; }
QLabel#dontHeading { color:#f0a84b; font-size:13px; font-weight:800; }
QLabel#muted { color:#9aa79b; font-size:13px; }
QCheckBox { background:transparent; color:#dbe4dc; spacing:8px; }
QPushButton#primary { background:#73f04b; color:#071006; border:1px solid #73f04b;
                     border-radius:8px; padding:9px 18px; font-weight:800; }
QPushButton#primary:hover { background:#86f562; }
"""


def _state_path(tool_key: str) -> str:
    return os.path.join(snap_home.config_dir(tool_key), "first_run.json")


def is_dismissed(tool_key: str) -> bool:
    path = _state_path(tool_key)
    try:
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
        return bool(data.get("dismissed", False))
    except Exception:
        return False


def set_dismissed(tool_key: str, dismissed: bool) -> None:
    path = _state_path(tool_key)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        json.dump({"dismissed": bool(dismissed)}, f, indent=2)


def should_show(tool_key: str) -> bool:
    return not is_dismissed(tool_key)


class FirstRunDialog(QDialog):
    def __init__(
        self,
        app_name: str,
        does_items: Sequence[str],
        doesnt_items: Sequence[str],
        *,
        note: str = "",
        tool_key: str = "",
        parent=None,
    ):
        super().__init__(parent)
        self.tool_key = tool_key
        self.setWindowTitle(f"{app_name} — What this app does")
        self.setMinimumWidth(620)
        self.setStyleSheet(_STYLE)

        root = QVBoxLayout(self)
        root.setContentsMargins(22, 20, 22, 18)
        root.setSpacing(14)

        title = QLabel(app_name)
        title.setObjectName("title")
        root.addWidget(title)

        sub = QLabel("Before you start, here is what this tool does and does not do.")
        sub.setObjectName("muted")
        sub.setWordWrap(True)
        root.addWidget(sub)

        does_card = QFrame()
        does_card.setObjectName("panel")
        dl = QVBoxLayout(does_card)
        dl.setContentsMargins(16, 12, 16, 12)
        dl.setSpacing(6)
        dh = QLabel("WHAT THIS APP DOES")
        dh.setObjectName("doHeading")
        dl.addWidget(dh)
        for item in does_items:
            lbl = QLabel(f"•  {item}")
            lbl.setWordWrap(True)
            dl.addWidget(lbl)
        root.addWidget(does_card)

        dont_card = QFrame()
        dont_card.setObjectName("panel")
        nl = QVBoxLayout(dont_card)
        nl.setContentsMargins(16, 12, 16, 12)
        nl.setSpacing(6)
        nh = QLabel("WHAT THIS APP DOESN'T DO")
        nh.setObjectName("dontHeading")
        nl.addWidget(nh)
        for item in doesnt_items:
            lbl = QLabel(f"•  {item}")
            lbl.setWordWrap(True)
            nl.addWidget(lbl)
        root.addWidget(dont_card)

        if note:
            note_lbl = QLabel(note)
            note_lbl.setObjectName("muted")
            note_lbl.setWordWrap(True)
            root.addWidget(note_lbl)

        footer = QHBoxLayout()
        self.dont_show_check = QCheckBox("Don't show this again on startup")
        if tool_key:
            self.dont_show_check.setChecked(is_dismissed(tool_key))
        footer.addWidget(self.dont_show_check)
        footer.addStretch(1)
        ok_btn = QPushButton("Got it")
        ok_btn.setObjectName("primary")
        ok_btn.setDefault(True)
        ok_btn.clicked.connect(self.accept)
        footer.addWidget(ok_btn)
        root.addLayout(footer)

    def _persist_dismissal(self):
        if self.tool_key:
            set_dismissed(self.tool_key, self.dont_show_check.isChecked())

    def done(self, result):
        self._persist_dismissal()
        super().done(result)


def show_if_needed(
    tool_key: str,
    app_name: str,
    does_items: Sequence[str],
    doesnt_items: Sequence[str],
    *,
    note: str = "",
    parent=None,
) -> bool:
    """Show the first-run dialog if not dismissed. Returns True if shown."""
    if not should_show(tool_key):
        return False
    dlg = FirstRunDialog(
        app_name, does_items, doesnt_items,
        note=note, tool_key=tool_key, parent=parent,
    )
    dlg.exec()
    return True


def show_from_help(
    tool_key: str,
    app_name: str,
    does_items: Sequence[str],
    doesnt_items: Sequence[str],
    *,
    note: str = "",
    parent=None,
) -> int:
    """Reopen the first-run dialog from Help regardless of dismissed state."""
    dlg = FirstRunDialog(
        app_name, does_items, doesnt_items,
        note=note, tool_key=tool_key, parent=parent,
    )
    return dlg.exec()

# ===== SNAPSMACK EOF =====
