"""COLD SNAP Qt — BodyEditor: one body field, two faces.

TWIGGY (was SIMPLE) = the plain text box with the CMS shortcode bar (unchanged behaviour).
BIGGIE = the WYSIWYG canvas (canvas.py): one writing surface where headings,
quotes, photos and mosaics look like what they are. The toggle is lossless
both ways: switching to BIGGIE parses the text into blocks (unrecognised bits
become RAW, byte-preserved) and draws them; switching back serializes the
canvas into the exact toolbar vocabulary. Drop-in for a QPlainTextEdit — it answers
toPlainText / setPlainText / clear so the modes barely change.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from PySide6.QtCore import Signal
from PySide6.QtWidgets import QWidget, QVBoxLayout, QHBoxLayout, QPlainTextEdit, QPushButton

import config as cfg_module

from . import biggie
from . import canvas as canvas_mod
from .shortcode_bar import ShortcodeBar
from .widgets import hint


class BodyEditor(QWidget):
    changed = Signal()
    def __init__(self, *, allow_mosaic: bool = False, simple_height: int = 84,
                 rich: bool = True, parent=None):
        """rich=False (COLD ONE / COLD STACK): the plain box and bar only, no
        TWIGGY/BIGGIE pills, no canvas. Sean 2026-09-10: the advanced editor is
        for long-form (COLD TAKE) only."""
        super().__init__(parent)
        self.rich = bool(rich)
        col = QVBoxLayout(self)
        col.setContentsMargins(0, 0, 0, 0)
        col.setSpacing(4)

        # -- the toggle ---------------------------------------------------------
        row = QHBoxLayout()
        self.simple_btn = QPushButton("TWIGGY")
        self.biggie_btn = QPushButton("BIGGIE — BLOCKS")
        for b in (self.simple_btn, self.biggie_btn):
            b.setObjectName("ScBtn")
            b.setCheckable(True)
        self.simple_btn.setToolTip("One text box + the shortcode bar — how the "
                                   "site editor works.")
        self.biggie_btn.setToolTip("The writing surface: click and type, Enter for a "
                                   "new paragraph. Headings, quotes, photos and the "
                                   "MOSAIC appear as they will on the site.")
        self.simple_btn.clicked.connect(lambda: self._set_biggie(False))
        self.biggie_btn.clicked.connect(lambda: self._set_biggie(True))
        row.addWidget(self.simple_btn)
        row.addWidget(self.biggie_btn)
        row.addStretch(1)
        col.addLayout(row)
        if not self.rich:
            self.simple_btn.hide()
            self.biggie_btn.hide()

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
        self.canvas = canvas_mod.BiggieCanvas(allow_mosaic=allow_mosaic)
        self.biggie = self.canvas          # model API: to_blocks / from_blocks / clear
        self.canvas_bar = canvas_mod.CanvasBar(self.canvas)
        g.addWidget(self.canvas_bar)
        g.addWidget(self.canvas, 1)        # the canvas fills the space it is given
        g.addWidget(hint("What you see here sends as the same shortcodes/HTML the "
                         "TWIGGY bar makes — the site renders it identically."))
        col.addWidget(self._biggie_page, 1)
        self.editor.textChanged.connect(self.changed)
        self.canvas.textChanged.connect(self.changed)

        # Last-used face is a per-tool setting (spec §7: biggie_enabled).
        self._biggie_on = False
        if not self.rich:
            self._biggie_page.hide()
            self._apply_face(False)
            return
        # COLD TAKE is the visual essay editor. TWIGGY remains callable as a
        # compatibility escape hatch, but is not presented as a competing mode.
        self.simple_btn.hide()
        self.biggie_btn.hide()
        self._apply_face(True)

    # -- face switching ---------------------------------------------------------
    def _apply_face(self, on: bool):
        self._biggie_on = on
        self.simple_btn.setChecked(not on)
        self.biggie_btn.setChecked(on)
        self._simple.setVisible(not on)
        self._biggie_page.setVisible(on)

    def _set_biggie(self, on: bool):
        if not self.rich:
            on = False
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

    def is_biggie(self) -> bool:
        return self._biggie_on

    def set_bucket(self, paths):
        """The post's photos, in order — the canvas paints mosaics from them."""
        self.canvas.set_bucket(paths)

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

    def authoring_blocks(self) -> list:
        """Canonical local model. Unlike the wire string, this retains UUIDs."""
        return self.biggie.to_blocks() if self._biggie_on else biggie.parse_body(
            self.editor.toPlainText())

    def set_state(self, caption: str, blocks_json: str):
        """Restore a draft: blocks win when the draft has them."""
        blocks = biggie.blocks_from_json(blocks_json)
        if blocks and not self.rich:
            # A draft made when this box still had BIGGIE: keep every word as text.
            self.setPlainText(biggie.serialize_blocks(blocks) or (caption or ""))
            return
        if blocks:
            self.editor.setPlainText(caption or "")
            self.biggie.from_blocks(blocks)
            self._apply_face(True)
        else:
            self.setPlainText(caption or "")

# ===== SNAPSMACK EOF =====
