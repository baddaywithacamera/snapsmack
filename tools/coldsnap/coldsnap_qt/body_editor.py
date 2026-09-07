"""COLD SNAP Qt — BodyEditor: one body field, two faces.

SIMPLE = the plain text box with the CMS shortcode bar (unchanged behaviour).
BIGGIE = the same content as a block stack (biggie.py). The toggle is lossless
both ways: switching to BIGGIE parses the text into blocks (unrecognised bits
become RAW blocks, byte-preserved); switching back serializes the blocks into
the exact toolbar vocabulary. Drop-in for a QPlainTextEdit — it answers
toPlainText / setPlainText / clear so the modes barely change.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from PySide6.QtWidgets import QWidget, QVBoxLayout, QHBoxLayout, QPlainTextEdit, QPushButton

import config as cfg_module

from . import biggie
from .shortcode_bar import ShortcodeBar
from .widgets import hint


class BodyEditor(QWidget):
    def __init__(self, *, allow_mosaic: bool = False, simple_height: int = 84,
                 parent=None):
        super().__init__(parent)
        col = QVBoxLayout(self)
        col.setContentsMargins(0, 0, 0, 0)
        col.setSpacing(4)

        # -- the toggle ---------------------------------------------------------
        row = QHBoxLayout()
        self.simple_btn = QPushButton("SIMPLE")
        self.biggie_btn = QPushButton("BIGGIE — BLOCKS")
        for b in (self.simple_btn, self.biggie_btn):
            b.setObjectName("ScBtn")
            b.setCheckable(True)
        self.simple_btn.setToolTip("One text box + the shortcode bar — how the "
                                   "site editor works.")
        self.biggie_btn.setToolTip("Build the body out of blocks: add a block, "
                                   "choose its type, type or paste its content.")
        self.simple_btn.clicked.connect(lambda: self._set_biggie(False))
        self.biggie_btn.clicked.connect(lambda: self._set_biggie(True))
        row.addWidget(self.simple_btn)
        row.addWidget(self.biggie_btn)
        row.addStretch(1)
        col.addLayout(row)

        # -- SIMPLE face --------------------------------------------------------
        self._simple = QWidget()
        s = QVBoxLayout(self._simple)
        s.setContentsMargins(0, 0, 0, 0)
        s.setSpacing(4)
        self.editor = QPlainTextEdit()
        self.editor.setMinimumHeight(simple_height)
        self.bar = ShortcodeBar(self.editor)
        s.addWidget(self.bar)
        s.addWidget(self.editor, 1)   # the textarea fills the space BodyEditor is given
        col.addWidget(self._simple, 1)

        # -- BIGGIE face --------------------------------------------------------
        self._biggie_page = QWidget()
        g = QVBoxLayout(self._biggie_page)
        g.setContentsMargins(0, 0, 0, 0)
        g.setSpacing(4)
        self.biggie = biggie.BiggieEditor(allow_mosaic=allow_mosaic)
        g.addWidget(self.biggie, 1)   # the block canvas fills too
        g.addWidget(hint("Blocks send as the same shortcodes/HTML the SIMPLE "
                         "bar makes — the site renders them identically."))
        col.addWidget(self._biggie_page, 1)

        # Last-used face is a per-tool setting (spec §7: biggie_enabled).
        self._biggie_on = False
        try:
            remembered = bool((cfg_module.load() or {}).get("biggie_enabled"))
        except Exception:  # noqa: BLE001
            remembered = False
        self._apply_face(remembered)

    # -- face switching ---------------------------------------------------------
    def _apply_face(self, on: bool):
        self._biggie_on = on
        self.simple_btn.setChecked(not on)
        self.biggie_btn.setChecked(on)
        self._simple.setVisible(not on)
        self._biggie_page.setVisible(on)

    def _set_biggie(self, on: bool):
        if on == self._biggie_on:
            self._apply_face(on)   # re-assert button states
            return
        if on:
            self.biggie.from_blocks(biggie.parse_body(self.editor.toPlainText()))
        else:
            self.editor.setPlainText(biggie.serialize_blocks(self.biggie.to_blocks()))
        self._apply_face(on)
        try:
            data = cfg_module.load() or {}
            data["biggie_enabled"] = bool(on)
            cfg_module.save(data)
        except Exception:  # noqa: BLE001 — remembering the face is best-effort
            pass

    # -- QPlainTextEdit-compatible API ------------------------------------------
    def toPlainText(self) -> str:
        if self._biggie_on:
            return biggie.serialize_blocks(self.biggie.to_blocks())
        return self.editor.toPlainText()

    def setPlainText(self, text: str):
        self.editor.setPlainText(text or "")
        if self._biggie_on:
            self.biggie.from_blocks(biggie.parse_body(text or ""))

    def clear(self):
        self.editor.clear()
        self.biggie.clear()

    # -- draft persistence -------------------------------------------------------
    def blocks_json(self) -> str:
        """The authoring blocks when BIGGIE is the active face, else ''."""
        if self._biggie_on:
            return biggie.blocks_to_json(self.biggie.to_blocks())
        return ""

    def set_state(self, caption: str, blocks_json: str):
        """Restore a draft: blocks win when the draft has them."""
        blocks = biggie.blocks_from_json(blocks_json)
        if blocks:
            self.editor.setPlainText(caption or "")
            self.biggie.from_blocks(blocks)
            self._apply_face(True)
        else:
            self.setPlainText(caption or "")

# ===== SNAPSMACK EOF =====
