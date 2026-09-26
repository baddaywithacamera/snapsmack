"""SMACK UP YOUR BACKUP — saved credentials library (Qt).

Port of the Tk `_CredLibraryDialog` plus the helpers that used it
(`_open_cred_library`, `_offer_save_to_library`). A credentials JSON file is
registered once under a friendly name, then picked by name in any site
connection or Cloud Sync job. The list itself lives in credential_store.py;
this module is only the window.

Public names other pages import (keep them stable):
    CredLibraryDialog(parent, on_select=None)
    choose_credential(parent) -> (name, path) | None
    offer_save_to_library(parent, path)

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
from typing import Callable, Optional, Tuple

from PySide6.QtWidgets import (
    QDialog, QFileDialog, QHBoxLayout, QInputDialog, QLineEdit, QListWidget,
    QMessageBox, QPushButton, QVBoxLayout,
)

import credential_store as cred_store
from suyb_qt_common import label


# --- small wrappers so tests can answer the questions without a screen -----

def _pick_json_file(parent) -> str:
    path, _ = QFileDialog.getOpenFileName(
        parent, "Select credentials JSON", "", "JSON files (*.json);;All files (*.*)")
    return path or ""


def _ask_name(parent, title: str, initial: str = "") -> Optional[str]:
    """Ask for a name. Blank or Cancel returns None (the Tk box refused blank)."""
    text, ok = QInputDialog.getText(parent, title, "Name:", QLineEdit.Normal, initial)
    text = (text or "").strip()
    return text if ok and text else None


def _confirm(parent, title: str, text: str, yes_text: str, danger: bool = False) -> bool:
    box = QMessageBox(parent)
    box.setWindowTitle(title); box.setIcon(QMessageBox.Question); box.setText(text)
    yes = box.addButton(yes_text, QMessageBox.AcceptRole)
    if danger:
        yes.setObjectName("Danger")
    no = box.addButton("Cancel", QMessageBox.RejectRole)
    box.setDefaultButton(no); box.setEscapeButton(no)
    box.exec()
    return box.clickedButton() is yes


class CredLibraryDialog(QDialog):
    """Manage the named credential library: add, rename, remove, use.

    After exec(), `selected_name` / `selected_path` hold the entry the user
    chose with Use selected (both None if they just closed the window).
    `on_select(name, path)` is also called, as in the Tk dialog.
    """

    def __init__(self, parent=None, on_select: Optional[Callable[[str, str], None]] = None):
        super().__init__(parent)
        self.setWindowTitle("Credential Library")
        self.setModal(True)
        self.resize(640, 420)
        self._on_select = on_select
        self.selected_name: Optional[str] = None
        self.selected_path: Optional[str] = None
        self._entries = []
        self._build()
        self._refresh()

    def _build(self):
        layout = QVBoxLayout(self); layout.setContentsMargins(18, 16, 18, 16); layout.setSpacing(9)
        layout.addWidget(label("Saved Credentials", "CardTitle"))
        layout.addWidget(label("Register credentials files once — pick them by name "
                               "in any site connection or Cloud Sync job.", "Muted"))
        self.list = QListWidget(); self.list.setMinimumHeight(180)
        self.list.currentRowChanged.connect(self._on_row_changed)
        self.list.itemDoubleClicked.connect(lambda _item: self._use_selected())
        layout.addWidget(self.list, 1)
        self.path_label = label("", "Muted")
        layout.addWidget(self.path_label)

        row = QHBoxLayout()
        self.use_btn = QPushButton("Use selected"); self.use_btn.setObjectName("Primary")
        self.use_btn.clicked.connect(self._use_selected); row.addWidget(self.use_btn)
        self.add_btn = QPushButton("Add…"); self.add_btn.clicked.connect(self._add); row.addWidget(self.add_btn)
        self.rename_btn = QPushButton("Rename…"); self.rename_btn.clicked.connect(self._rename); row.addWidget(self.rename_btn)
        self.remove_btn = QPushButton("Remove"); self.remove_btn.setObjectName("Danger")
        self.remove_btn.clicked.connect(self._remove); row.addWidget(self.remove_btn)
        row.addStretch(1)
        close = QPushButton("Close"); close.clicked.connect(self.reject); row.addWidget(close)
        layout.addLayout(row)

    def _refresh(self, select_name: Optional[str] = None):
        self._entries = cred_store.load()
        self.list.blockSignals(True)
        self.list.clear()
        select_row = -1
        for i, entry in enumerate(self._entries):
            self.list.addItem(entry["name"])
            if select_name and entry["name"] == select_name:
                select_row = i
        self.list.setCurrentRow(select_row)
        self.list.blockSignals(False)
        self._on_row_changed(select_row)

    def _on_row_changed(self, row: int):
        entry = self._selected_entry()
        self.path_label.setText(entry["path"] if entry else "")
        for button in (self.use_btn, self.rename_btn, self.remove_btn):
            button.setEnabled(entry is not None)

    def _selected_entry(self) -> Optional[dict]:
        row = self.list.currentRow()
        if 0 <= row < len(self._entries):
            return self._entries[row]
        return None

    def _use_selected(self):
        entry = self._selected_entry()
        if not entry:
            return
        self.selected_name = entry["name"]
        self.selected_path = entry["path"]
        if self._on_select:
            self._on_select(entry["name"], entry["path"])
        self.accept()

    def _add(self):
        path = _pick_json_file(self)
        if not path:
            return
        # Suggest a name — the existing one if this file is already registered.
        suggested = cred_store.name_for(path) or os.path.splitext(os.path.basename(path))[0]
        name = _ask_name(self, "Add credential", suggested)
        if name:
            cred_store.add_or_update(name, path)
            self._refresh(select_name=name)

    def _rename(self):
        entry = self._selected_entry()
        if not entry:
            return
        new_name = _ask_name(self, "Rename credential", entry["name"])
        if new_name and new_name != entry["name"]:
            cred_store.rename(entry["name"], new_name)
            self._refresh(select_name=new_name)

    def _remove(self):
        entry = self._selected_entry()
        if not entry:
            return
        if _confirm(self, "Remove",
                    f"Remove '{entry['name']}' from the library?\n"
                    "(The credentials file itself is not deleted.)",
                    "Remove from library", danger=True):
            cred_store.remove(entry["name"])
            self._refresh()


def choose_credential(parent) -> Optional[Tuple[str, str]]:
    """Open the library; return (friendly_name, path) the user picked, or None."""
    dialog = CredLibraryDialog(parent)
    dialog.exec()
    if dialog.selected_name and dialog.selected_path:
        return dialog.selected_name, dialog.selected_path
    return None


def offer_save_to_library(parent, path: str) -> None:
    """After a successful sign-in, offer to save the credentials path by name."""
    if not path or cred_store.name_for(path):
        return   # nothing to save, or already registered
    if not _confirm(parent, "Save to credential library",
                    f"Save these credentials to your library?\n\n{path}\n\n"
                    "You'll be able to pick them by name in any site connection or sync job.",
                    "Save to library"):
        return
    name = _ask_name(parent, "Credential name", os.path.splitext(os.path.basename(path))[0])
    if name:
        cred_store.add_or_update(name, path)

# ===== SNAPSMACK EOF =====
