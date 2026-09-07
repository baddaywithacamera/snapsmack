"""COLD SNAP Qt — the shortcode bar: the CMS compose toolbar, ported 1:1.

The web posters (smack-post-solo.php, smack-post-long.php, smack-edit-carousel.php)
all carry the sc-toolbar from assets/js/shortcode-toolbar.js. This is that bar for
a QPlainTextEdit — same actions, same markup emitted, same wrap-the-selection
semantics, same Ctrl+B/I/U/K shortcuts — so the desktop composer has at least the
same controls as the CMS. PREVIEW is the one web button not ported: it needs the
logged-in admin session in a browser.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from PySide6.QtCore import Qt
from PySide6.QtGui import QKeySequence, QShortcut, QIntValidator
from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QPushButton, QDialog, QLineEdit,
    QCheckBox, QComboBox, QLabel, QInputDialog, QPlainTextEdit,
)

from .widgets import hint, field_label


class ShortcodeBar(QWidget):
    """Two grouped rows of insert-at-cursor buttons for one QPlainTextEdit."""

    def __init__(self, editor: QPlainTextEdit, parent=None):
        super().__init__(parent)
        self.editor = editor

        col = QVBoxLayout(self)
        col.setContentsMargins(0, 0, 0, 0)
        col.setSpacing(4)

        # Row 1 — text marks (mirrors the web bar's first group)
        row1 = QHBoxLayout()
        row1.setSpacing(4)
        for label, tip, cb in (
            ("B", "Bold (Ctrl+B)", lambda: self._wrap("<strong>", "</strong>")),
            ("I", "Italic (Ctrl+I)", lambda: self._wrap("<em>", "</em>")),
            ("U", "Underline (Ctrl+U)", lambda: self._wrap("<u>", "</u>")),
            ("LINK", "Insert link (Ctrl+K)", self._link),
            ("H2", "Heading 2", lambda: self._wrap("<h2>", "</h2>")),
            ("H3", "Heading 3", lambda: self._wrap("<h3>", "</h3>")),
            ("BQ", "Blockquote", lambda: self._wrap("<blockquote>", "</blockquote>")),
            ("PULL", "Pullquote", lambda: self._wrap("[pullquote]", "[/pullquote]")),
            ("HR", "Horizontal rule", lambda: self._wrap("\n<hr>\n")),
        ):
            row1.addWidget(self._btn(label, tip, cb))
        row1.addStretch(1)
        col.addLayout(row1)

        # Row 2 — blocks & shortcodes (the web bar's second group)
        self._row2 = QHBoxLayout()
        self._row2.setSpacing(4)
        for label, tip, cb in (
            ("UL", "Bullet list — selected lines become items", lambda: self._list("ul")),
            ("OL", "Numbered list — selected lines become items", lambda: self._list("ol")),
            ("IMG", "Insert image shortcode — needs the image's Media Library ID",
             self._img),
            ("COL 2", "2-column layout", lambda: self._columns(2)),
            ("COL 3", "3-column layout", lambda: self._columns(3)),
            ("DROP", "Dropcap (skins that support it)",
             lambda: self._wrap("[dropcap]", "[/dropcap]")),
            ("SPACER", "Vertical spacer (1-100px)", self._spacer),
        ):
            self._row2.addWidget(self._btn(label, tip, cb))
        self._row2.addStretch(1)
        col.addLayout(self._row2)

        # Same shortcuts the web textarea binds.
        for seq, cb in (("Ctrl+B", lambda: self._wrap("<strong>", "</strong>")),
                        ("Ctrl+I", lambda: self._wrap("<em>", "</em>")),
                        ("Ctrl+U", lambda: self._wrap("<u>", "</u>")),
                        ("Ctrl+K", self._link)):
            sc = QShortcut(QKeySequence(seq), editor)
            sc.setContext(Qt.WidgetShortcut)
            sc.activated.connect(cb)

    def add_button(self, label: str, tip: str, callback) -> QPushButton:
        """Let a mode append its own insert button (COLD TAKE's MOSAIC)."""
        b = self._btn(label, tip, callback)
        self._row2.insertWidget(self._row2.count() - 1, b)  # before the stretch
        return b

    def _btn(self, label, tip, cb) -> QPushButton:
        b = QPushButton(label)
        b.setObjectName("ScBtn")
        b.setToolTip(tip)
        b.clicked.connect(cb)
        b.setFocusPolicy(Qt.NoFocus)   # clicking never steals the text cursor
        return b

    # -- insert primitives (port of insertAtCursor) ---------------------------
    def _wrap(self, before: str, after: str = ""):
        c = self.editor.textCursor()
        sel = c.selectedText().replace(" ", "\n")
        start = c.selectionStart()
        c.insertText(before + sel + after)
        if not sel:
            c.setPosition(start + len(before))
            self.editor.setTextCursor(c)
        self.editor.setFocus()

    def insert(self, text: str):
        self._wrap(text)

    # -- LIST (port of insertList: blank-line split, else newline split) ------
    def _list(self, tag: str):
        c = self.editor.textCursor()
        sel = c.selectedText().replace(" ", "\n")
        items = []
        if sel:
            parts = [p for p in sel.split("\n\n") if True]
            if len(parts) == 1:
                parts = sel.split("\n")
            items = [p.strip() for p in parts if p.strip()]
        if items:
            block = f"\n<{tag}>\n" + "".join(f"  <li>{i}</li>\n" for i in items) + f"</{tag}>\n"
        else:
            block = f"\n<{tag}>\n  <li></li>\n  <li></li>\n</{tag}>\n"
        start = c.selectionStart()
        c.insertText(block)
        c.setPosition(start + block.index("<li>") + 4)
        self.editor.setTextCursor(c)
        self.editor.setFocus()

    # -- COLUMNS (port of insertColumns) --------------------------------------
    def _columns(self, cols: int):
        block = f"[columns={cols}]\nFirst column content.\n"
        for i in range(1, cols):
            block += f"\n[col]\n\nColumn {i + 1} content.\n"
        block += "[/columns]"
        self._wrap(block)

    # -- SPACER ---------------------------------------------------------------
    def _spacer(self):
        px, ok = QInputDialog.getInt(self, "Spacer", "Height in pixels (1-100):",
                                     20, 1, 100)
        if ok:
            self._wrap(f"[spacer:{px}]")

    # -- LINK (port of the web link dialog) -----------------------------------
    def _link(self):
        c = self.editor.textCursor()
        sel = c.selectedText().replace(" ", "\n")
        start, end = c.selectionStart(), c.selectionEnd()

        dlg = QDialog(self)
        dlg.setWindowTitle("Insert link")
        lay = QVBoxLayout(dlg)
        lay.addWidget(field_label("URL"))
        url_edit = QLineEdit("https://")
        lay.addWidget(url_edit)
        lay.addWidget(field_label("Link text"))
        text_edit = QLineEdit(sel)
        text_edit.setPlaceholderText("Selected text, or type here")
        lay.addWidget(text_edit)
        opts = QHBoxLayout()
        newtab = QCheckBox("Open in new tab")
        newtab.setChecked(True)
        nofollow = QCheckBox("Nofollow")
        opts.addWidget(newtab)
        opts.addWidget(nofollow)
        opts.addStretch(1)
        lay.addLayout(opts)
        btns = QHBoxLayout()
        btns.addStretch(1)
        cancel = QPushButton("Cancel")
        cancel.clicked.connect(dlg.reject)
        insert = QPushButton("Insert link")
        insert.setObjectName("Primary")
        insert.setDefault(True)
        insert.clicked.connect(dlg.accept)
        btns.addWidget(cancel)
        btns.addWidget(insert)
        lay.addLayout(btns)
        url_edit.setFocus()
        url_edit.selectAll()

        if dlg.exec() != QDialog.Accepted:
            return
        url = url_edit.text().strip()
        if not url or url == "https://":
            return
        tag = f'<a href="{url}"'
        rels = []
        if newtab.isChecked():
            tag += ' target="_blank"'
            rels.append("noopener")
        if nofollow.isChecked():
            rels.append("nofollow")
        if rels:
            tag += f' rel="{" ".join(rels)}"'
        tag += ">"
        link_text = text_edit.text().strip() or sel or "link text"
        replacement = tag + link_text + "</a>"
        c.setPosition(start)
        c.setPosition(end, c.MoveMode.KeepAnchor)
        c.insertText(replacement)
        self.editor.setFocus()

    # -- IMG (the web fallback path — COLD SNAP has no live asset picker) -----
    def _img(self):
        dlg = QDialog(self)
        dlg.setWindowTitle("Insert image shortcode")
        lay = QVBoxLayout(dlg)
        lay.addWidget(hint("The ID comes from the site's Media Library "
                           "(hover an image, or its edit page)."))
        lay.addWidget(field_label("Image ID"))
        id_edit = QLineEdit()
        id_edit.setValidator(QIntValidator(1, 99999999))
        lay.addWidget(id_edit)
        row = QHBoxLayout()
        size_col = QVBoxLayout()
        size_col.addWidget(field_label("Size"))
        size = QComboBox()
        size.addItems(["full", "wall", "small"])
        size_col.addWidget(size)
        row.addLayout(size_col)
        align_col = QVBoxLayout()
        align_col.addWidget(field_label("Align"))
        align = QComboBox()
        align.addItems(["center", "left", "right"])
        align_col.addWidget(align)
        row.addLayout(align_col)
        lay.addLayout(row)
        btns = QHBoxLayout()
        btns.addStretch(1)
        cancel = QPushButton("Cancel")
        cancel.clicked.connect(dlg.reject)
        insert = QPushButton("Insert")
        insert.setObjectName("Primary")
        insert.setDefault(True)
        insert.clicked.connect(dlg.accept)
        btns.addWidget(cancel)
        btns.addWidget(insert)
        lay.addLayout(btns)
        id_edit.setFocus()
        if dlg.exec() != QDialog.Accepted or not id_edit.text().strip():
            return
        self._wrap(f"[img:{id_edit.text().strip()}|{size.currentText()}|{align.currentText()}]")

# ===== SNAPSMACK EOF =====
