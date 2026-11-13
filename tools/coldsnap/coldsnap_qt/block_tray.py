"""COLD SNAP Qt — the COLD TAKE block and shortcode tray.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from dataclasses import dataclass
from typing import Callable

from PySide6.QtCore import QSettings, Qt, Signal
from PySide6.QtWidgets import (
    QFrame, QGridLayout, QHBoxLayout, QLabel, QPushButton, QScrollArea,
    QVBoxLayout, QWidget,
)


@dataclass(frozen=True)
class BlockAction:
    tag: str
    label: str
    group: str
    tooltip: str
    callback: Callable[[], None]


class BlockTray(QWidget):
    """A large, keyboard-reachable tray with a compact always-visible strip."""

    stateChanged = Signal(bool, bool)  # open, pinned

    def __init__(self, parent=None):
        super().__init__(parent)
        self._settings = QSettings("SnapSmack", "COLD SNAP")
        self._open = self._settings.value("shell/blocks_open", True, type=bool)
        self._pinned = self._settings.value("shell/blocks_pinned", True, type=bool)
        self._compact_window = False
        self._actions = []
        self._title = "BLOCKS"

        shell = QVBoxLayout(self)
        shell.setContentsMargins(0, 0, 0, 0)
        shell.setSpacing(0)

        self._collapsed = QWidget()
        self._collapsed.setObjectName("BlockTagStrip")
        collapsed = QVBoxLayout(self._collapsed)
        collapsed.setContentsMargins(6, 8, 6, 8)
        collapsed.setSpacing(5)
        self._open_btn = QPushButton("»")
        self._open_btn.setObjectName("BlockTrayToggle")
        self._open_btn.setToolTip("Open the blocks tray (Ctrl+4)")
        self._open_btn.setAccessibleName("Open the blocks tray")
        self._open_btn.clicked.connect(lambda: self.set_open(True))
        collapsed.addWidget(self._open_btn)
        shortcut = QLabel("Ctrl+4")
        shortcut.setObjectName("BlockTrayShortcut")
        shortcut.setAlignment(Qt.AlignCenter)
        collapsed.addWidget(shortcut)
        self._collapsed_grid = QGridLayout()
        self._collapsed_grid.setContentsMargins(0, 2, 0, 0)
        self._collapsed_grid.setSpacing(5)
        collapsed.addLayout(self._collapsed_grid)
        collapsed.addStretch(1)

        self._expanded = QWidget()
        self._expanded.setObjectName("BlockTray")
        expanded = QVBoxLayout(self._expanded)
        expanded.setContentsMargins(0, 0, 0, 0)
        expanded.setSpacing(0)
        header = QHBoxLayout()
        header.setContentsMargins(12, 8, 8, 8)
        self._title_label = QLabel(self._title)
        self._title_label.setObjectName("ChromeLabel")
        header.addWidget(self._title_label)
        header.addStretch(1)
        self._pin_btn = QPushButton()
        self._pin_btn.setObjectName("ScBtn")
        self._pin_btn.setCheckable(True)
        self._pin_btn.setChecked(self._pinned)
        self._pin_btn.setAccessibleName("Keep the blocks tray open")
        self._pin_btn.toggled.connect(self.set_pinned)
        header.addWidget(self._pin_btn)
        close_btn = QPushButton("×")
        close_btn.setObjectName("ScBtn")
        close_btn.setToolTip("Close the blocks tray (Ctrl+4)")
        close_btn.setAccessibleName("Close the blocks tray")
        close_btn.clicked.connect(lambda: self.set_open(False))
        header.addWidget(close_btn)
        expanded.addLayout(header)
        self._state_label = QLabel()
        self._state_label.setObjectName("BlockTrayState")
        self._state_label.setContentsMargins(12, 0, 8, 8)
        expanded.addWidget(self._state_label)

        scroller = QScrollArea()
        scroller.setWidgetResizable(True)
        scroller.setFrameShape(QFrame.NoFrame)
        self._action_host = QWidget()
        self._action_layout = QVBoxLayout(self._action_host)
        self._action_layout.setContentsMargins(10, 4, 10, 10)
        self._action_layout.setSpacing(5)
        scroller.setWidget(self._action_host)
        expanded.addWidget(scroller, 1)

        shell.addWidget(self._collapsed)
        shell.addWidget(self._expanded, 1)
        self._sync_pin_text()
        self._sync_visibility()

    @property
    def is_open(self) -> bool:
        return self._open

    @property
    def is_pinned(self) -> bool:
        return self._pinned

    def set_open(self, opened: bool):
        opened = bool(opened)
        if opened == self._open:
            self._sync_visibility()
            return
        self._open = opened
        self._settings.setValue("shell/blocks_open", opened)
        self._sync_visibility()
        self.stateChanged.emit(self._open, self._pinned)

    def toggle_open(self):
        self.set_open(not self._open)

    def set_pinned(self, pinned: bool):
        pinned = bool(pinned)
        if pinned == self._pinned:
            self._sync_pin_text()
            return
        self._pinned = pinned
        self._settings.setValue("shell/blocks_pinned", pinned)
        self._sync_pin_text()
        self.stateChanged.emit(self._open, self._pinned)

    def set_compact_window(self, compact: bool):
        compact = bool(compact)
        if compact == self._compact_window:
            return
        self._compact_window = compact
        self._rebuild_actions()
        self._sync_visibility()

    def set_actions(self, title: str, actions):
        self._title = str(title or "BLOCKS")
        self._actions = list(actions or [])
        self._title_label.setText(self._title)
        self._rebuild_actions()

    def _sync_visibility(self):
        self._collapsed.setVisible(not self._open)
        self._expanded.setVisible(self._open)
        if self._open:
            self.setFixedWidth(248)
        else:
            self.setFixedWidth(122 if self._compact_window else 68)

    def _sync_pin_text(self):
        self._pin_btn.blockSignals(True)
        self._pin_btn.setChecked(self._pinned)
        self._pin_btn.setText("PINNED" if self._pinned else "PIN")
        self._pin_btn.setToolTip(
            "Pinned — stays open" if self._pinned
            else "Unpinned — closes after you choose a block")
        self._state_label.setText(
            "Pinned — stays open · Ctrl+4" if self._pinned
            else "Unpinned — closes after a choice · Ctrl+4")

    @staticmethod
    def _clear(layout):
        while layout.count():
            item = layout.takeAt(0)
            child = item.widget()
            if child:
                child.hide()
                child.setParent(None)
                child.deleteLater()
            nested = item.layout()
            if nested:
                BlockTray._clear(nested)

    def _rebuild_actions(self):
        self._clear(self._collapsed_grid)
        self._clear(self._action_layout)
        columns = 2 if self._compact_window else 1
        for index, action in enumerate(self._actions):
            button = self._button(action, compact=True)
            self._collapsed_grid.addWidget(button, index // columns, index % columns)

        groups = list(dict.fromkeys(action.group for action in self._actions))
        for group in groups:
            label = QLabel(group)
            label.setObjectName("BlockTrayGroup")
            self._action_layout.addWidget(label)
            for action in self._actions:
                if action.group == group:
                    self._action_layout.addWidget(self._button(action, compact=False))
        self._action_layout.addStretch(1)

    def _button(self, action: BlockAction, *, compact: bool) -> QPushButton:
        text = action.tag if compact else f"{action.tag}    {action.label}"
        button = QPushButton(text)
        button.setObjectName("BlockTag" if compact else "BlockAction")
        button.setToolTip(action.tooltip)
        button.setAccessibleName(action.label)
        button.setFocusPolicy(Qt.StrongFocus)
        button.clicked.connect(lambda _checked=False, item=action: self._run(item))
        return button

    def _run(self, action: BlockAction):
        action.callback()
        if not self._pinned:
            self.set_open(False)

# ===== SNAPSMACK EOF =====
