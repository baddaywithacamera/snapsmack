"""BIGGIE — COLD SNAP's block editor (v1, per Sean 2026-09-06).

The shortcode-bar vocabulary as working blocks: create a block, choose its
type, then type or paste content as appropriate. Serializes to EXACTLY the
HTML/shortcode strings the CMS shortcode toolbar emits, stored in the same
caption/body field — the site renders it through core/parser.php with zero
server change. Parsing an existing body back into blocks is conservative:
anything not recognised becomes a RAW block and survives byte-for-byte
(glass box — nothing is ever silently rewritten).

Spec: _spec/SPEC-biggie-coldsnap.md (v1 scope = the bar vocabulary; the
webview WYSIWYG surface from §9 remains a later step). Desktop-only — BIGGIE
never ships in the SnapSmack web admin.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import re

from PySide6.QtCore import Qt, Signal
from PySide6.QtWidgets import (
    QWidget, QFrame, QVBoxLayout, QHBoxLayout, QLabel, QLineEdit,
    QPlainTextEdit, QComboBox, QPushButton, QMenu, QSpinBox,
)

from .widgets import hint, field_label

# ── Block model ──────────────────────────────────────────────────────────────
# {"type": "para"|"raw",     "text": str}
# {"type": "heading", "level": 2|3, "text": str}
# {"type": "quote",   "text": str}
# {"type": "hr"}
# {"type": "list",    "ordered": bool, "items": [str, ...]}
# {"type": "image",   "img_id": str, "size": str, "align": str}
# {"type": "columns", "cols": [str, ...]}          (2-4 columns)
# {"type": "dropcap", "text": str}
# {"type": "spacer",  "px": int}
# {"type": "mosaic"}

IMG_SIZES = ["full", "wall", "small"]
IMG_ALIGNS = ["center", "left", "right"]


def serialize_block(b: dict) -> str:
    t = b.get("type", "para")
    if t == "heading":
        lvl = 3 if int(b.get("level", 2)) == 3 else 2
        return f"<h{lvl}>{b.get('text', '')}</h{lvl}>"
    if t == "quote":
        return f"<blockquote>{b.get('text', '')}</blockquote>"
    if t == "hr":
        return "<hr>"
    if t == "list":
        tag = "ol" if b.get("ordered") else "ul"
        items = [i for i in (b.get("items") or []) if str(i).strip()]
        body = "".join(f"  <li>{i}</li>\n" for i in items) or "  <li></li>\n"
        return f"<{tag}>\n{body}</{tag}>"
    if t == "image":
        size = b.get("size") or "full"
        align = b.get("align") or "center"
        return f"[img:{str(b.get('img_id', '')).strip()}|{size}|{align}]"
    if t == "columns":
        cols = [str(c) for c in (b.get("cols") or ["", ""])]
        out = f"[columns={len(cols)}]\n{cols[0]}\n"
        for c in cols[1:]:
            out += f"\n[col]\n\n{c}\n"
        return out + "[/columns]"
    if t == "dropcap":
        return f"[dropcap]{b.get('text', '')}[/dropcap]"
    if t == "spacer":
        return f"[spacer:{max(1, min(100, int(b.get('px', 20) or 20)))}]"
    if t == "mosaic":
        return "[mosaic]"
    return str(b.get("text", ""))          # para / raw — verbatim


def serialize_blocks(blocks: list) -> str:
    parts = [serialize_block(b) for b in (blocks or [])]
    return "\n\n".join(p for p in parts if p != "")


# Shortcodes BIGGIE understands as blocks; any OTHER [name…] construct in a
# segment turns the whole segment into a RAW block (verbatim passthrough).
_KNOWN_SC = {"img", "columns", "col", "dropcap", "spacer", "mosaic"}

_MULTI = re.compile(
    r"(\[columns=\d+\].*?\[/columns\]"
    r"|<(?:ul|ol)>.*?</(?:ul|ol)>"
    r"|<blockquote>.*?</blockquote>)",
    re.DOTALL | re.IGNORECASE)

_RX = {
    "heading": re.compile(r"^<h([23])>(.*)</h\1>$", re.DOTALL | re.IGNORECASE),
    "hr":      re.compile(r"^<hr\s*/?>$", re.IGNORECASE),
    "dropcap": re.compile(r"^\[dropcap\](.*)\[/dropcap\]$", re.DOTALL | re.IGNORECASE),
    "spacer":  re.compile(r"^\[spacer:(\d+)\]$", re.IGNORECASE),
    "image":   re.compile(r"^\[img:([^|\]]+)(?:\|([^|\]]+))?(?:\|([^\]]+))?\]$", re.IGNORECASE),
    "mosaic":  re.compile(r"^\[mosaic\]$", re.IGNORECASE),
    "columns": re.compile(r"^\[columns=(\d+)\](.*)\[/columns\]$", re.DOTALL | re.IGNORECASE),
    "list":    re.compile(r"^<(ul|ol)>(.*)</\1>$", re.DOTALL | re.IGNORECASE),
    "quote":   re.compile(r"^<blockquote>(.*)</blockquote>$", re.DOTALL | re.IGNORECASE),
    "li":      re.compile(r"<li>(.*?)</li>", re.DOTALL | re.IGNORECASE),
    "colsep":  re.compile(r"\n?\[col\]\n?", re.IGNORECASE),
    "any_sc":  re.compile(r"\[([a-z_]+)[:=\]|]", re.IGNORECASE),
}


def _classify(seg: str) -> dict:
    """One blank-line-delimited segment → one block dict."""
    m = _RX["heading"].match(seg)
    if m:
        return {"type": "heading", "level": int(m.group(1)), "text": m.group(2)}
    if _RX["hr"].match(seg):
        return {"type": "hr"}
    m = _RX["dropcap"].match(seg)
    if m:
        return {"type": "dropcap", "text": m.group(1)}
    m = _RX["spacer"].match(seg)
    if m:
        return {"type": "spacer", "px": int(m.group(1))}
    m = _RX["mosaic"].match(seg)
    if m:
        return {"type": "mosaic"}
    m = _RX["image"].match(seg)
    if m:
        return {"type": "image", "img_id": m.group(1).strip(),
                "size": (m.group(2) or "full").strip(),
                "align": (m.group(3) or "center").strip()}
    m = _RX["columns"].match(seg)
    if m:
        cols = [c.strip() for c in _RX["colsep"].split(m.group(2))]
        return {"type": "columns", "cols": cols or ["", ""]}
    m = _RX["list"].match(seg)
    if m:
        return {"type": "list", "ordered": m.group(1).lower() == "ol",
                "items": [i.strip() for i in _RX["li"].findall(m.group(2))]}
    m = _RX["quote"].match(seg)
    if m:
        return {"type": "quote", "text": m.group(1).strip()}
    # [mosaic:123] points at an EXISTING site gallery — recognised but not
    # editable as a block, so it rides as RAW (verbatim keep).
    if re.match(r"^\[mosaic:\d+\]$", seg, re.IGNORECASE):
        return {"type": "raw", "text": seg}
    # Unrecognised shortcode anywhere in the segment → RAW (verbatim keep).
    for name in _RX["any_sc"].findall(seg):
        if name.lower() not in _KNOWN_SC:
            return {"type": "raw", "text": seg}
    return {"type": "para", "text": seg}


def parse_body(text: str) -> list:
    """Body string → block list. Lossless in content: para and raw serialize
    verbatim, so a misread never rewrites anyone's words."""
    text = (text or "").replace("\r\n", "\n").strip()
    if not text:
        return []
    blocks = []
    pos = 0
    for m in _MULTI.finditer(text):
        before = text[pos:m.start()]
        for seg in re.split(r"\n\s*\n", before):
            seg = seg.strip()
            if seg:
                blocks.append(_classify(seg))
        blocks.append(_classify(m.group(1).strip()))
        pos = m.end()
    for seg in re.split(r"\n\s*\n", text[pos:]):
        seg = seg.strip()
        if seg:
            blocks.append(_classify(seg))
    return blocks


def blocks_to_json(blocks: list) -> str:
    return json.dumps(blocks or [], ensure_ascii=False)


def blocks_from_json(raw: str) -> list:
    try:
        v = json.loads(raw or "[]")
        return v if isinstance(v, list) else []
    except ValueError:
        return []


# ── Widgets ──────────────────────────────────────────────────────────────────

_TYPE_LABELS = [
    ("para",    "Paragraph"),
    ("heading", "Heading"),
    ("quote",   "Quote"),
    ("list",    "List"),
    ("image",   "Image (Media Library)"),
    ("columns", "Columns"),
    ("dropcap", "Dropcap"),
    ("spacer",  "Spacer"),
    ("hr",      "Divider line"),
    ("mosaic",  "Mosaic — this post's photos"),
    ("raw",     "Raw (kept exactly as-is)"),
]


class _BlockRow(QFrame):
    """One block: a small header (type · move · delete) over its own editor."""

    changed = Signal()

    def __init__(self, block: dict, owner, parent=None):
        super().__init__(parent)
        self.setObjectName("Card")
        self.btype = block.get("type", "para")
        self._owner = owner
        col = QVBoxLayout(self)
        col.setContentsMargins(10, 8, 10, 10)
        col.setSpacing(6)

        head = QHBoxLayout()
        name = QLabel(dict(_TYPE_LABELS).get(self.btype, self.btype).upper())
        name.setObjectName("FieldLabel")
        head.addWidget(name)
        head.addStretch(1)
        for sym, tip, cb in (("▲", "Move up", lambda: owner.move_row(self, -1)),
                             ("▼", "Move down", lambda: owner.move_row(self, 1)),
                             ("✕", "Remove this block", lambda: owner.remove_row(self))):
            b = QPushButton(sym)
            b.setObjectName("ScBtn" if sym != "✕" else "Danger")
            b.setFixedWidth(34)
            b.setToolTip(tip)
            b.clicked.connect(cb)
            head.addWidget(b)
        col.addLayout(head)

        self._build_body(col, block)

    # -- per-type editors ---------------------------------------------------
    def _build_body(self, col, b):
        t = self.btype
        if t in ("para", "raw", "quote"):
            self.text = QPlainTextEdit(b.get("text", ""))
            self.text.setPlaceholderText(
                "Type or paste…" if t != "quote" else "The quoted words…")
            self.text.setFixedHeight(72 if t != "raw" else 88)
            col.addWidget(self.text)
            if t == "raw":
                col.addWidget(hint("Kept exactly as written — shortcodes and HTML "
                                   "here go to the site untouched."))
        elif t == "heading":
            row = QHBoxLayout()
            self.level = QComboBox()
            self.level.addItem("H2 — section", 2)
            self.level.addItem("H3 — sub-section", 3)
            self.level.setCurrentIndex(1 if int(b.get("level", 2)) == 3 else 0)
            row.addWidget(self.level)
            self.text = QLineEdit(b.get("text", ""))
            self.text.setPlaceholderText("The heading…")
            row.addWidget(self.text, 1)
            col.addLayout(row)
        elif t == "list":
            self.ordered = QComboBox()
            self.ordered.addItem("• Bullet list", False)
            self.ordered.addItem("1. Numbered list", True)
            self.ordered.setCurrentIndex(1 if b.get("ordered") else 0)
            col.addWidget(self.ordered)
            self.text = QPlainTextEdit("\n".join(b.get("items") or []))
            self.text.setPlaceholderText("One item per line…")
            self.text.setFixedHeight(72)
            col.addWidget(self.text)
        elif t == "image":
            row = QHBoxLayout()
            idcol = QVBoxLayout()
            idcol.addWidget(field_label("Image ID"))
            self.img_id = QLineEdit(str(b.get("img_id", "")))
            self.img_id.setPlaceholderText("from the Media Library")
            idcol.addWidget(self.img_id)
            row.addLayout(idcol, 1)
            scol = QVBoxLayout()
            scol.addWidget(field_label("Size"))
            self.size = QComboBox()
            self.size.addItems(IMG_SIZES)
            self.size.setCurrentText(b.get("size") or "full")
            scol.addWidget(self.size)
            row.addLayout(scol)
            acol = QVBoxLayout()
            acol.addWidget(field_label("Align"))
            self.align = QComboBox()
            self.align.addItems(IMG_ALIGNS)
            self.align.setCurrentText(b.get("align") or "center")
            acol.addWidget(self.align)
            row.addLayout(acol)
            col.addLayout(row)
        elif t == "columns":
            self.count = QComboBox()
            for n in (2, 3, 4):
                self.count.addItem(f"{n} columns", n)
            cols = b.get("cols") or ["", ""]
            self.count.setCurrentIndex(max(0, min(2, len(cols) - 2)))
            col.addWidget(self.count)
            self._cols_host = QVBoxLayout()
            col.addLayout(self._cols_host)
            self._col_edits = []
            self._rebuild_cols(cols)
            self.count.currentIndexChanged.connect(
                lambda *_: self._rebuild_cols(self.col_texts()))
        elif t == "dropcap":
            self.text = QLineEdit(b.get("text", ""))
            self.text.setPlaceholderText("The letter or word the skin makes big…")
            col.addWidget(self.text)
        elif t == "spacer":
            row = QHBoxLayout()
            row.addWidget(field_label("Gap height (px)"))
            self.px = QSpinBox()
            self.px.setRange(1, 100)
            self.px.setValue(max(1, min(100, int(b.get("px", 20) or 20))))
            row.addWidget(self.px)
            row.addStretch(1)
            col.addLayout(row)
        elif t == "hr":
            col.addWidget(hint("A horizontal divider line — no content needed."))
        elif t == "mosaic":
            col.addWidget(hint("On send, THE PHOTOS below become a tiled grid "
                               "right here in the post."))

    def _rebuild_cols(self, texts):
        while self._cols_host.count():
            item = self._cols_host.takeAt(0)
            w = item.widget()
            if w:
                w.deleteLater()
        self._col_edits = []
        n = int(self.count.currentData() or 2)
        for i in range(n):
            e = QPlainTextEdit(texts[i] if i < len(texts) else "")
            e.setPlaceholderText(f"Column {i + 1}…")
            e.setFixedHeight(60)
            self._cols_host.addWidget(e)
            self._col_edits.append(e)

    def col_texts(self):
        return [e.toPlainText() for e in getattr(self, "_col_edits", [])]

    # -- read back ----------------------------------------------------------
    def to_block(self) -> dict:
        t = self.btype
        if t in ("para", "raw", "quote"):
            return {"type": t, "text": self.text.toPlainText()}
        if t == "heading":
            return {"type": t, "level": int(self.level.currentData()),
                    "text": self.text.text()}
        if t == "list":
            items = [ln.strip() for ln in self.text.toPlainText().split("\n") if ln.strip()]
            return {"type": t, "ordered": bool(self.ordered.currentData()), "items": items}
        if t == "image":
            return {"type": t, "img_id": self.img_id.text().strip(),
                    "size": self.size.currentText(), "align": self.align.currentText()}
        if t == "columns":
            return {"type": t, "cols": self.col_texts()}
        if t == "dropcap":
            return {"type": t, "text": self.text.text()}
        if t == "spacer":
            return {"type": t, "px": int(self.px.value())}
        return {"type": t}


class BiggieEditor(QWidget):
    """The block stack + ADD BLOCK menu."""

    def __init__(self, allow_mosaic: bool = False, parent=None):
        super().__init__(parent)
        self.allow_mosaic = allow_mosaic
        self._rows = []
        col = QVBoxLayout(self)
        col.setContentsMargins(0, 0, 0, 0)
        col.setSpacing(6)
        self._stack = QVBoxLayout()
        self._stack.setSpacing(6)
        col.addLayout(self._stack)
        add = QPushButton("+  ADD BLOCK")
        add.setToolTip("Pick a block type, then type or paste its content")
        add.setMinimumHeight(36)
        menu = QMenu(add)
        for key, label in _TYPE_LABELS:
            if key == "mosaic" and not allow_mosaic:
                continue
            menu.addAction(label, lambda k=key: self.add_block({"type": k}))
        add.setMenu(menu)
        col.addWidget(add)

    # -- stack ops ----------------------------------------------------------
    def add_block(self, block: dict):
        row = _BlockRow(block, owner=self)
        self._rows.append(row)
        self._stack.addWidget(row)

    def move_row(self, row, delta: int):
        i = self._rows.index(row)
        j = i + delta
        if 0 <= j < len(self._rows):
            self._rows[i], self._rows[j] = self._rows[j], self._rows[i]
            self._stack.removeWidget(row)
            self._stack.insertWidget(j, row)

    def remove_row(self, row):
        if row in self._rows:
            self._rows.remove(row)
            row.deleteLater()

    def clear(self):
        for r in list(self._rows):
            self.remove_row(r)

    # -- model in/out --------------------------------------------------------
    def to_blocks(self) -> list:
        return [r.to_block() for r in self._rows]

    def from_blocks(self, blocks: list):
        self.clear()
        for b in blocks or []:
            if b.get("type") == "mosaic" and not self.allow_mosaic:
                b = {"type": "raw", "text": "[mosaic]"}
            self.add_block(b)

# ===== SNAPSMACK EOF =====
