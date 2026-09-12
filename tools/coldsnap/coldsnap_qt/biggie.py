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
    QPlainTextEdit, QComboBox, QPushButton, QMenu, QSpinBox, QCheckBox,
)

from .widgets import hint, field_label

# ── Block model ──────────────────────────────────────────────────────────────
# {"type": "para", "text": str, "dropcap": bool}
# {"type": "raw",  "text": str}
# {"type": "heading", "level": 2|3, "text": str}
# {"type": "quote",   "text": str}
# {"type": "hr"}
# {"type": "list",    "ordered": bool, "items": [str, ...]}
# {"type": "image",   "img_id": str, "size": str, "align": str}
# {"type": "columns", "cols": [[block, ...], ...], "ratio": str}
#     One to four columns. Each cell owns blocks; columns cannot nest.
# {"type": "pullquote", "text": str}
# {"type": "spacer",  "px": int}
# {"type": "mosaic", "order": [1, 3, 2], "layout": "one-left"}
#     A composed mosaic: 1-based positions in THE PHOTOS bucket + a layout the
#     site's mosaics endpoint accepts. Bare {"type": "mosaic"} = every photo.

IMG_SIZES = ["full", "wall", "small"]
IMG_ALIGNS = ["center", "left", "right"]
RATIO_PRESETS = {
    1: [("Full width", "equal")],
    2: [("Equal — 1/2 + 1/2", "equal"), ("1/3 + 2/3", "1-2"),
        ("2/3 + 1/3", "2-1"), ("1/4 + 3/4", "1-3"), ("3/4 + 1/4", "3-1")],
    3: [("Equal thirds", "equal"), ("1/4 + 1/4 + 1/2", "1-1-2"),
        ("1/2 + 1/4 + 1/4", "2-1-1")],
    4: [("Equal quarters", "equal")],
}


def serialize_block(b: dict) -> str:
    t = b.get("type", "para")
    if t == "para":
        text = str(b.get("text", ""))
        if b.get("dropcap") and text:
            match = re.search(r"\S", text)
            if match:
                i = match.start()
                text = text[:i] + f"[dropcap]{text[i]}[/dropcap]" + text[i + 1:]
        return text
    if t == "heading":
        lvl = 3 if int(b.get("level", 2)) == 3 else 2
        return f"<h{lvl}>{b.get('text', '')}</h{lvl}>"
    if t == "quote":
        return f"<blockquote>{b.get('text', '')}</blockquote>"
    if t == "pullquote":
        return f"[pullquote]{b.get('text', '')}[/pullquote]"
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
        cols = b.get("cols") or [[]]
        rendered = [serialize_blocks(_normalise_cell(c)) for c in cols]
        ratio = _normalise_ratio(str(b.get("ratio") or "equal"), len(cols))
        ratio_attr = "" if ratio == "equal" else f" ratio={ratio}"
        out = f"[columns={len(cols)}{ratio_attr}]\n{rendered[0]}\n"
        for cell in rendered[1:]:
            out += f"\n[col]\n\n{cell}\n"
        return out + "[/columns]"
    if t == "spacer":
        return f"[spacer:{max(1, min(100, int(b.get('px', 20) or 20)))}]"
    if t == "mosaic":
        order = [int(i) for i in (b.get("order") or [])
                 if str(i).strip().isdigit() and int(i) >= 1]
        if not order:
            return "[mosaic]"
        layout = str(b.get("layout") or "asymmetric").lower()
        return "[mosaic=" + ",".join(map(str, order)) + " layout=" + layout + "]"
    return str(b.get("text", ""))          # raw — verbatim


def serialize_blocks(blocks: list) -> str:
    parts = [serialize_block(b) for b in (blocks or [])]
    return "\n\n".join(p for p in parts if p != "")


# Shortcodes BIGGIE understands as blocks; any OTHER [name…] construct in a
# segment turns the whole segment into a RAW block (verbatim passthrough).
_KNOWN_SC = {"img", "columns", "col", "dropcap", "pullquote", "spacer", "mosaic"}

_MULTI = re.compile(
    r"(\[columns=\d+(?:\s+ratio=[0-9-]+)?\].*?\[/columns\]"
    r"|<(?:ul|ol)>.*?</(?:ul|ol)>"
    r"|<blockquote>.*?</blockquote>"
    r"|\[pullquote\].*?\[/pullquote\])",
    re.DOTALL | re.IGNORECASE)

_RX = {
    "heading": re.compile(r"^<h([23])>(.*)</h\1>$", re.DOTALL | re.IGNORECASE),
    "hr":      re.compile(r"^<hr\s*/?>$", re.IGNORECASE),
    "dropcap_para": re.compile(r"^(\s*)\[dropcap\](.*?)\[/dropcap\](.*)$", re.DOTALL | re.IGNORECASE),
    "pullquote": re.compile(r"^\[pullquote\](.*)\[/pullquote\]$", re.DOTALL | re.IGNORECASE),
    "spacer":  re.compile(r"^\[spacer:(\d+)\]$", re.IGNORECASE),
    "image":   re.compile(r"^\[img:([^|\]]+)(?:\|([^|\]]+))?(?:\|([^\]]+))?\]$", re.IGNORECASE),
    "mosaic":  re.compile(r"^\[mosaic(?:=([0-9]+(?:\s*,\s*[0-9]+)*)\s+layout=([a-z-]+))?\]$", re.IGNORECASE),
    "columns": re.compile(r"^\[columns=(\d+)(?:\s+ratio=([0-9-]+))?\](.*)\[/columns\]$", re.DOTALL | re.IGNORECASE),
    "list":    re.compile(r"^<(ul|ol)>(.*)</\1>$", re.DOTALL | re.IGNORECASE),
    "quote":   re.compile(r"^<blockquote>(.*)</blockquote>$", re.DOTALL | re.IGNORECASE),
    "li":      re.compile(r"<li>(.*?)</li>", re.DOTALL | re.IGNORECASE),
    "colsep":  re.compile(r"\n?\[col\]\n?", re.IGNORECASE),
    "any_sc":  re.compile(r"\[([a-z_]+)[:=\]|]", re.IGNORECASE),
}


def _normalise_cell(cell) -> list:
    """Migrate v1 string cells to the nested block model without data loss."""
    if isinstance(cell, list):
        return cell
    return parse_body(str(cell)) if str(cell) else []


def _normalise_ratio(ratio: str, count: int) -> str:
    parts = ratio.split("-") if ratio != "equal" else []
    if len(parts) != count or any(not p.isdigit() or not 1 <= int(p) <= 4 for p in parts):
        return "equal"
    return "-".join(str(int(p)) for p in parts)


def _classify(seg: str) -> dict:
    """One blank-line-delimited segment → one block dict."""
    m = _RX["heading"].match(seg)
    if m:
        return {"type": "heading", "level": int(m.group(1)), "text": m.group(2)}
    if _RX["hr"].match(seg):
        return {"type": "hr"}
    m = _RX["dropcap_para"].match(seg)
    if m:
        return {"type": "para", "text": m.group(1) + m.group(2) + m.group(3), "dropcap": True}
    m = _RX["pullquote"].match(seg)
    if m:
        return {"type": "pullquote", "text": m.group(1)}
    m = _RX["spacer"].match(seg)
    if m:
        return {"type": "spacer", "px": int(m.group(1))}
    m = _RX["mosaic"].match(seg)
    if m:
        if m.group(1):
            return {"type": "mosaic",
                    "order": [int(v) for v in re.split(r"\s*,\s*", m.group(1).strip())],
                    "layout": m.group(2).lower()}
        return {"type": "mosaic"}
    m = _RX["image"].match(seg)
    if m:
        return {"type": "image", "img_id": m.group(1).strip(),
                "size": (m.group(2) or "full").strip(),
                "align": (m.group(3) or "center").strip()}
    m = _RX["columns"].match(seg)
    if m:
        cells = [c.strip() for c in _RX["colsep"].split(m.group(3))]
        cols = [parse_body(c) for c in cells]
        return {"type": "columns", "cols": cols or [[]],
                "ratio": _normalise_ratio(m.group(2) or "equal", len(cols or [[]]))}
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
        return [_migrate_block(b) for b in v] if isinstance(v, list) else []
    except ValueError:
        return []


def _migrate_block(block: dict) -> dict:
    """Read v1 BIGGIE drafts into the corrected container/mark model."""
    b = dict(block or {})
    if b.get("type") == "dropcap":
        return {"type": "para", "text": str(b.get("text", "")), "dropcap": True}
    if b.get("type") == "columns":
        b["cols"] = [[_migrate_block(child) for child in _normalise_cell(cell)]
                     for cell in (b.get("cols") or [[]])]
        b["ratio"] = _normalise_ratio(str(b.get("ratio") or "equal"), len(b["cols"]))
    return b


# ── Widgets ──────────────────────────────────────────────────────────────────

_TYPE_LABELS = [
    ("para",    "Paragraph"),
    ("heading", "Heading"),
    ("quote",   "Quote"),
    ("pullquote", "Pullquote"),
    ("list",    "List"),
    ("image",   "Image (Media Library)"),
    ("columns", "Columns"),
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
        self._block = dict(block)
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
        if t in ("para", "raw", "quote", "pullquote"):
            self.text = QPlainTextEdit(b.get("text", ""))
            self.text.setPlaceholderText(
                "The words to pull out…" if t == "pullquote" else
                "The quoted words…" if t == "quote" else "Type or paste…")
            self.text.setFixedHeight(72 if t != "raw" else 88)
            col.addWidget(self.text)
            if t == "para":
                self.dropcap = QCheckBox("Make the first letter a drop cap")
                self.dropcap.setChecked(bool(b.get("dropcap")))
                col.addWidget(self.dropcap)
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
            controls = QHBoxLayout()
            self.count = QComboBox()
            for n in (1, 2, 3, 4):
                self.count.addItem(f"{n} column" if n == 1 else f"{n} columns", n)
            cols = [_normalise_cell(c) for c in (b.get("cols") or [[]])]
            self.count.setCurrentIndex(max(0, min(3, len(cols) - 1)))
            controls.addWidget(self.count)
            self.ratio = QComboBox()
            controls.addWidget(self.ratio, 1)
            col.addLayout(controls)
            self._cols_host = QHBoxLayout()
            col.addLayout(self._cols_host)
            self._col_editors = []
            self._fill_ratio_menu(b.get("ratio") or "equal")
            self._rebuild_cols(cols)
            self.count.currentIndexChanged.connect(self._column_count_changed)
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

    def _fill_ratio_menu(self, selected="equal"):
        count = int(self.count.currentData() or 1)
        self.ratio.clear()
        for label, value in RATIO_PRESETS[count]:
            self.ratio.addItem(label, value)
        index = self.ratio.findData(_normalise_ratio(str(selected), count))
        self.ratio.setCurrentIndex(max(0, index))

    def _column_count_changed(self):
        cells = self.col_blocks()
        self._fill_ratio_menu("equal")
        self._rebuild_cols(cells)

    def _rebuild_cols(self, cells):
        while self._cols_host.count():
            item = self._cols_host.takeAt(0)
            w = item.widget()
            if w:
                w.deleteLater()
        self._col_editors = []
        n = int(self.count.currentData() or 1)
        for i in range(n):
            frame = QFrame()
            frame.setObjectName("ColumnCell")
            layout = QVBoxLayout(frame)
            layout.setContentsMargins(6, 6, 6, 6)
            layout.addWidget(field_label(f"Column {i + 1}"))
            editor = BiggieEditor(allow_mosaic=self._owner.allow_mosaic, allow_columns=False)
            editor.from_blocks(cells[i] if i < len(cells) else [])
            layout.addWidget(editor)
            self._cols_host.addWidget(frame, 1)
            self._col_editors.append(editor)

    def col_blocks(self):
        return [editor.to_blocks() for editor in getattr(self, "_col_editors", [])]

    # -- read back ----------------------------------------------------------
    def to_block(self) -> dict:
        t = self.btype
        if t in ("para", "raw", "quote", "pullquote"):
            block = {"type": t, "text": self.text.toPlainText()}
            if t == "para" and self.dropcap.isChecked():
                block["dropcap"] = True
            return block
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
            return {"type": t, "cols": self.col_blocks(),
                    "ratio": str(self.ratio.currentData() or "equal")}
        if t == "spacer":
            return {"type": t, "px": int(self.px.value())}
        if t == "mosaic":
            return {**self._block, "type": "mosaic"}   # a composed mosaic keeps its photos + layout
        return {"type": t}


class BiggieEditor(QWidget):
    """The block stack + ADD BLOCK menu."""

    def __init__(self, allow_mosaic: bool = False, allow_columns: bool = True, parent=None):
        super().__init__(parent)
        self.allow_mosaic = allow_mosaic
        self.allow_columns = allow_columns
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
            if key == "columns" and not allow_columns:
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
            b = _migrate_block(b)
            if b.get("type") == "mosaic" and not self.allow_mosaic:
                b = {"type": "raw", "text": "[mosaic]"}
            self.add_block(b)

# ===== SNAPSMACK EOF =====
