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

from PySide6.QtCore import Qt, QRect, QSettings, QSize, Signal
from PySide6.QtGui import QColor, QFont, QPainter
from PySide6.QtWidgets import QWidget, QVBoxLayout, QHBoxLayout, QPlainTextEdit, QPushButton, QFrame


from . import biggie, theme
from . import canvas as canvas_mod
from .shortcode_bar import ShortcodeBar
from .widgets import hint


class _LineNumberArea(QWidget):
    def __init__(self, editor):
        super().__init__(editor)
        self._editor = editor

    def sizeHint(self):
        return QSize(self._editor.line_number_area_width(), 0)

    def paintEvent(self, event):
        self._editor.line_number_area_paint_event(event)


class TwiggyEditor(QPlainTextEdit):
    """Dark, line-numbered plain-text shortcode editor for TWIGGY mode."""

    def __init__(self, parent=None):
        super().__init__(parent)
        self.setObjectName("TwiggyEditor")
        font = QFont("Consolas")
        font.setStyleHint(QFont.Monospace)
        font.setPointSize(11)
        self.setFont(font)
        self._line_area = _LineNumberArea(self)
        self.blockCountChanged.connect(self._update_line_area_width)
        self.updateRequest.connect(self._update_line_area)
        self._update_line_area_width(0)

    def line_number_area_width(self) -> int:
        digits = max(2, len(str(max(1, self.blockCount()))))
        return 12 + self.fontMetrics().horizontalAdvance("9") * digits

    def _update_line_area_width(self, _new_count: int):
        self.setViewportMargins(self.line_number_area_width(), 0, 0, 0)

    def _update_line_area(self, rect, dy):
        if dy:
            self._line_area.scroll(0, dy)
        else:
            self._line_area.update(0, rect.y(), self._line_area.width(), rect.height())
        if rect.contains(self.viewport().rect()):
            self._update_line_area_width(0)

    def resizeEvent(self, event):
        super().resizeEvent(event)
        cr = self.contentsRect()
        self._line_area.setGeometry(
            QRect(cr.left(), cr.top(), self.line_number_area_width(), cr.height()))

    def line_number_area_paint_event(self, event):
        p = QPainter(self._line_area)
        p.fillRect(event.rect(), QColor("#141914"))
        block = self.firstVisibleBlock()
        block_num = block.blockNumber()
        top = round(self.blockBoundingGeometry(block).translated(self.contentOffset()).top())
        bottom = top + round(self.blockBoundingRect(block).height())
        p.setPen(QColor("#52634e"))
        while block.isValid() and top <= event.rect().bottom():
            if block.isVisible() and bottom >= event.rect().top():
                p.drawText(
                    0, top, self._line_area.width() - 6, self.fontMetrics().height(),
                    Qt.AlignRight | Qt.AlignVCenter, str(block_num + 1))
            block = block.next()
            top = bottom
            bottom = top + round(self.blockBoundingRect(block).height())
            block_num += 1


class BodyEditor(QWidget):
    changed = Signal()
    faceChanged = Signal(bool)  # True = BIGGIE visual, False = TWIGGY text
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

        # -- the format strip (Slice 1: Text/Visual segmented + B I U Link) ----
        self.format_strip = QFrame()
        self.format_strip.setObjectName("FormatStrip")
        row = QHBoxLayout(self.format_strip)
        row.setContentsMargins(12, 8, 12, 8)
        row.setSpacing(6)

        seg = QFrame()
        seg.setObjectName("FaceSegment")
        seg_row = QHBoxLayout(seg)
        seg_row.setContentsMargins(2, 2, 2, 2)
        seg_row.setSpacing(2)
        self.simple_btn = QPushButton("Text · TWIGGY")
        self.biggie_btn = QPushButton("Visual · BIGGIE")
        for b in (self.simple_btn, self.biggie_btn):
            b.setObjectName("FaceToggle")
            b.setCheckable(True)
            b.setFocusPolicy(Qt.NoFocus)
        self.simple_btn.setToolTip("TWIGGY · raw shortcodes — how the site editor works.")
        self.biggie_btn.setToolTip("BIGGIE · what you see — headings, quotes, photos and "
                                   "the MOSAIC appear as they will on the site.")
        self.simple_btn.clicked.connect(lambda: self._set_biggie(False))
        self.biggie_btn.clicked.connect(lambda: self._set_biggie(True))
        seg_row.addWidget(self.simple_btn)
        seg_row.addWidget(self.biggie_btn)
        row.addWidget(seg)

        row.addSpacing(8)
        self._inline_btns = []
        for label, tip, action in (
            ("B", "Bold (Ctrl+B)", "bold"),
            ("I", "Italic (Ctrl+I)", "italic"),
            ("U", "Underline (Ctrl+U)", "underline"),
            ("Link  Ctrl+K", "Insert link (Ctrl+K)", "link"),
        ):
            btn = QPushButton(label)
            btn.setObjectName("FormatBtn")
            btn.setToolTip(tip)
            btn.setFocusPolicy(Qt.NoFocus)
            btn.clicked.connect(lambda _=False, act=action: self._apply_inline(act))
            row.addWidget(btn)
            self._inline_btns.append(btn)
        row.addStretch(1)
        col.addWidget(self.format_strip)
        if not self.rich:
            self.format_strip.hide()
            self.simple_btn.hide()
            self.biggie_btn.hide()

        # -- SIMPLE face --------------------------------------------------------
        self._simple = QWidget()
        s = QVBoxLayout(self._simple)
        s.setContentsMargins(0, 0, 0, 0)
        s.setSpacing(4)
        self.editor = TwiggyEditor()
        self.editor.setMinimumHeight(simple_height)
        self.bar = ShortcodeBar(self.editor)
        s.addWidget(self.bar)
        s.addWidget(self.editor, 1)   # the textarea fills the space BodyEditor is given
        col.addWidget(self._simple, 1)

        # -- BIGGIE face --------------------------------------------------------
        self._biggie_page = QWidget()
        self._biggie_page.setObjectName("BiggiePage")
        self._biggie_page.setAttribute(Qt.WA_StyledBackground, True)
        self._biggie_page.setStyleSheet("QWidget#BiggiePage { background: #ffffff; }")
        g = QVBoxLayout(self._biggie_page)
        g.setContentsMargins(0, 0, 0, 0)
        g.setSpacing(0)
        self.canvas = canvas_mod.BiggieCanvas(allow_mosaic=allow_mosaic)
        self.biggie = self.canvas          # model API: to_blocks / from_blocks / clear
        self.canvas_bar = None
        g.addWidget(self.canvas, 1)        # the canvas fills the space it is given
        col.addWidget(self._biggie_page, 1)
        self.editor.textChanged.connect(self.changed)
        self.canvas.textChanged.connect(self.changed)

        # Last-used face is a per-tool setting (spec §7: biggie_enabled).
        self._biggie_on = False
        self._text_signatures = []
        self._text_mosaic_document = None
        if not self.rich:
            self._biggie_page.hide()
            self._apply_face(False)
            return
        # COLD TAKE offers both named faces.  They are two lossless views of the
        # same body, not install modes; keeping the switch visible is essential
        # to making the editor understandable and matches the bundled Help.
        self._apply_face(True)

    def apply_surface_palette(self, pal: dict):
        """Apply site theme surface colours to BiggiePage wrapper and BiggieCanvas."""
        from PySide6.QtGui import QColor, QPalette
        self._biggie_page.setAutoFillBackground(True)
        bp = self._biggie_page.palette()
        bp.setColor(QPalette.Window, QColor(pal["bg"]))
        self._biggie_page.setPalette(bp)
        self._biggie_page.setStyleSheet(f"QWidget#BiggiePage {{ background: {pal['bg']}; }}")
        st = self._biggie_page.style()
        if st is not None:
            st.unpolish(self._biggie_page)
            st.polish(self._biggie_page)
        self._biggie_page.update()
        self.canvas.apply_surface_palette(pal)

    def _apply_inline(self, action: str):
        if self._biggie_on:
            {
                "bold": self.canvas.toggle_bold,
                "italic": self.canvas.toggle_italic,
                "underline": self.canvas.toggle_underline,
                "link": self.canvas.insert_link,
            }[action]()
        else:
            {
                "bold": lambda: self.bar._wrap("<strong>", "</strong>"),
                "italic": lambda: self.bar._wrap("<em>", "</em>"),
                "underline": lambda: self.bar._wrap("<u>", "</u>"),
                "link": self.bar._link,
            }[action]()

    # -- face switching ---------------------------------------------------------
    def _apply_face(self, on: bool):
        self._biggie_on = on
        self.simple_btn.setChecked(not on)
        self.biggie_btn.setChecked(on)
        self._simple.setVisible(not on)
        self._biggie_page.setVisible(on)
        self.faceChanged.emit(on)

    def _set_biggie(self, on: bool):
        if not self.rich:
            on = False
        if on == self._biggie_on:
            self._apply_face(on)   # re-assert button states
            return
        if on:
            self.biggie.from_blocks(self.authoring_blocks())
            self._text_signatures = []
            self._text_mosaic_document = None
        else:
            blocks = self.biggie.to_blocks()
            self._text_signatures = [(None if index == len(blocks) - 1 else index, block) for index, block in enumerate(blocks)
                                     if block.get("type") == "signature"]
            self.editor.setPlainText(biggie.serialize_blocks(blocks))
            self._text_mosaic_document = (
                (self.editor.toPlainText(), blocks)
                if any(block.get("remote_mosaic") for block in blocks) else None
            )
        self._apply_face(on)
        # SS-1084. THIS USED TO REWRITE THE WHOLE CONFIGURATION TO REMEMBER ONE
        # BOOLEAN, and that is how every stored credential on this machine got
        # destroyed: load() returned '' for secrets it could not open, this
        # handed the dict straight back to save(), and the empties were written
        # over the sealed password, API key and SMACKPRESS key. Nothing threw,
        # and the `except Exception: pass` below it meant nothing would have
        # been reported if it had.
        #
        # Remembering which face was last used is a VIEW preference, so it goes
        # where the other view state goes -- QSettings, the same store the
        # window geometry uses -- and it writes ONE KEY. It cannot reach a
        # credential from here.
        QSettings("SnapSmack", "COLD SNAP").setValue(
            "body_editor/biggie_enabled", bool(on))

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
        self._text_signatures = []
        self._text_mosaic_document = None
        self.editor.setPlainText(text or "")
        if self._biggie_on:
            self.biggie.from_blocks(biggie.parse_body(text or ""))

    def clear(self):
        self._text_signatures = []
        self._text_mosaic_document = None
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
        if self._biggie_on:
            return self.biggie.to_blocks()
        text = self.editor.toPlainText()
        if self._text_mosaic_document:
            original_text, original_blocks = self._text_mosaic_document
            if text == original_text:
                return original_blocks
        blocks = biggie.parse_body(text)
        if self._text_mosaic_document:
            for original in original_blocks:
                if not original.get("remote_mosaic"):
                    continue
                shortcode = biggie.serialize_block(original)
                index = next((i for i, block in enumerate(blocks)
                              if not block.get("remote_mosaic") and biggie.serialize_block(block) == shortcode), None)
                if index is not None:
                    blocks[index] = {key: value for key, value in original.items()
                                     if key not in {"src", "sep", "wrap"}}
        for index, signature in self._text_signatures:
            blocks.insert(len(blocks) if index is None else index, signature)
        return blocks

    def set_state(self, caption: str, blocks_json: str):
        """Restore a draft: blocks win when the draft has them."""
        self._text_signatures = []
        self._text_mosaic_document = None
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
