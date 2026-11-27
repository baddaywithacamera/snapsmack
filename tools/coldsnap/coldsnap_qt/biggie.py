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
        cap = str(b.get("caption") or "").strip() if bool(b.get("show_caption")) else ""
        if b.get("raw") and not cap:
            return str(b["raw"])
        prefix = "g" if b.get("gallery") else ""
        parts = [f"{prefix}{str(b.get('img_id', '')).strip()}"]
        for key in ("size", "align", "width", "decorative"):
            val = b.get(key)
            if key == "decorative":
                if val:
                    parts.append("decorative")
            elif val not in (None, ""):
                parts.append(str(val))
        if cap:
            safe_cap = cap.replace("]", "").replace("|", " - ").replace("\n", " ").strip()
            if safe_cap:
                parts.append(f"caption={safe_cap}")
        return "[img:" + "|".join(parts) + "]"
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
    "image":   re.compile(
        r"^\[img:\s*(?:(g)\s*(\d+)|([^|\]]+?))"
        r"(?:\s*\|\s*(small|wall|full))?"
        r"(?:\s*\|\s*(left|center|right))?"
        r"(?:\s*\|\s*(\d{1,3})(?:%)?)?"
        r"(?:\s*\|\s*(decorative))?"
        r"(?:\s*\|\s*caption=([^|\]]+))?\s*\]$",
        re.IGNORECASE,
    ),
    "figure_image": re.compile(
        r"^<figure[^>]*>\s*(\[img:[^\]]+\])\s*<figcaption[^>]*>(.*?)</figcaption>\s*</figure>$",
        re.DOTALL | re.IGNORECASE,
    ),
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
    fm = _RX["figure_image"].match(seg)
    if fm:
        inner_blk = _classify(fm.group(1).strip())
        if inner_blk.get("type") == "image":
            inner_blk["caption"] = re.sub(r"<[^>]+>", "", fm.group(2)).strip()
            inner_blk["show_caption"] = bool(inner_blk["caption"])
            inner_blk.pop("raw", None)
            return inner_blk
    m = _RX["image"].match(seg)
    if m:
        blk = {"type": "image", "img_id": (m.group(2) or m.group(3) or "").strip(), "raw": seg}
        if m.group(1):
            blk["gallery"] = True
        if m.group(4):
            blk["size"] = m.group(4).lower()
        if m.group(5):
            blk["align"] = m.group(5).lower()
        if m.group(6):
            blk["width"] = m.group(6)
        if m.group(7):
            blk["decorative"] = True
        if m.group(8):
            blk["caption"] = m.group(8).strip()
            blk["show_caption"] = True
            blk.pop("raw", None)
        return blk
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


# ---------------------------------------------------------------------------
# SS-1612: reading a PUBLISHED body back into blocks.
#
# parse_body() above reads AUTHORED text. A body that comes back from the site
# is not that: the site wraps every paragraph in <p>…</p> and stores a mosaic
# or an image shortcode INSIDE the paragraph it belongs to. Opening a published
# post therefore produced one of two failures, and both reached Sean:
#   - mode_take._draft_from_remote never called any parser at all; it put the
#     whole HTML body in draft.caption and left body_blocks empty.
#   - a segment like <p>[mosaic:752]prose…</p> matches no block pattern, and
#     "mosaic" is a KNOWN shortcode so it does not fall through to RAW either,
#     so it became a paragraph with the markup still in its text. BIGGIE then
#     printed <p>[mosaic:752]prose…</p> on the canvas as literal characters.
#
# THE INVARIANT HERE, and it is the whole design: every block keeps the exact
# bytes it came from in "src", and the exact bytes that followed it in "sep".
# Concatenating src + sep over the blocks in order reproduces the published
# body BYTE FOR BYTE. Nothing is reconstructed, so nothing can be lost by a
# misread: a pattern this code fails to recognise becomes a labelled raw block
# that still carries its own bytes.
#
# When the editor changes a block it DELETES that block's "src", which hands
# that block back to serialize_block(). Byte-identity stops being the goal the
# moment Sean changes something; it exists so that opening a post and sending
# it again without editing cannot alter it.
#
# A site element that yields two blocks (the mosaic and the prose that shared
# one <p>) marks both with "wrap": {"open","close","group"}, so the sender can
# tell they came out of one element and re-wrap them if one half is edited.
# The PM ruled (room 8293) that a mosaic is its own movable block, which is why
# the split happens at all.
# ---------------------------------------------------------------------------

_RX_SITE = {
    # One top-level site element. Non-greedy, so it stops at the FIRST closing
    # tag of the same name: a <div> inside a <div> is therefore cut at the
    # inner close, and the remainder becomes a labelled raw block. That is a
    # known limit, not a silent one, and the byte-exact invariant still holds
    # because every piece keeps its own bytes -- the blocks are wrong, the
    # content is not lost. The site stores <p> and <figure>, neither of which
    # nests, so this does not arise on a SnapSmack body today.
    "element": re.compile(
        r"<(p|figure|h2|h3|ul|ol|blockquote)\b[^>]*>.*?</\1\s*>|<hr\s*/?>",
        re.DOTALL | re.IGNORECASE),
    # A <p>'s own tags, so the inner content can be read on its own.
    "p_wrap": re.compile(r"^(<p\b[^>]*>)(.*)(</p\s*>)$", re.DOTALL | re.IGNORECASE),
    # A leading mosaic or image shortcode, which is how the site stores them.
    "lead_sc": re.compile(r"^\s*(\[(?:mosaic|img):[^\]]*\])", re.IGNORECASE),
    # The same, anywhere in the paragraph. A mid-paragraph shortcode is not a
    # shape the site produces, but it must not print itself if it ever appears.
    "any_media_sc": re.compile(r"\[(?:mosaic|img):[^\]]*\]", re.IGNORECASE),
    "site_mosaic": re.compile(r"^\[mosaic:(\d+)\]$", re.IGNORECASE),
}

_SITE_GROUP = "__ss1612_group_"


def _site_block_for(fragment: str) -> dict:
    """One fragment of site content → one display block (no src/sep yet).

    Falls back to a LABELLED raw block rather than to a paragraph, because a
    paragraph is what printed the markup on the canvas in the first place.
    """
    fragment = fragment.strip()
    if not fragment:
        return {"type": "raw", "text": "", "label": "Empty"}
    m = _RX_SITE["site_mosaic"].match(fragment)
    if m:
        # Points at a gallery that lives on the site. Not editable here, but it
        # is a block of its own: movable, deletable, and shown as a placeholder.
        return {"type": "site_mosaic", "mosaic_id": int(m.group(1)),
                "text": fragment, "label": "Site mosaic " + m.group(1)}
    block = _classify(fragment)
    # _classify falls back to {"type": "para"} for anything it does not know.
    # On this path that fallback is only acceptable when the fragment carries
    # no markup of its own; otherwise it is the fault this function exists to
    # prevent, so it becomes a labelled raw block instead.
    if block.get("type") == "para" and re.search(r"<[a-z/!][^>]*>", fragment, re.IGNORECASE):
        return {"type": "raw", "text": fragment, "label": "Kept exactly as the site stored it"}
    return block


def _split_site_element(inner: str) -> list:
    """A <p>'s inner content → its blocks, split at every media shortcode.

    The site stores a mosaic or image at the START of the paragraph it belongs
    to, which is the only shape a SnapSmack body contains today. A shortcode in
    the MIDDLE used to fall through to paragraph text with the markup showing,
    which is the very thing this ticket exists to stop. "The site does not do
    that today" is not the same as "it cannot happen", so every media shortcode
    splits, wherever it sits. Found by Muse reviewing 0e0e2314.

    Returns the pieces in order; the caller slices the source bytes to match,
    so the byte-exact invariant is unaffected by how many pieces there are.
    """
    pieces = _split_site_fragments(inner)
    return [_site_block_for(piece) for piece in pieces]


def _split_site_fragments(inner: str) -> list:
    """The exact substrings _split_site_element turns into blocks.

    Shared with the caller so the block list and the byte slices can never
    disagree about where the boundaries are.
    """
    out = []
    pos = 0
    for m in _RX_SITE["any_media_sc"].finditer(inner):
        before = inner[pos:m.start()]
        if before.strip() != "":
            out.append(before)
        elif before != "" and out:
            # Whitespace between two shortcodes rides with the previous piece.
            out[-1] = out[-1] + before
        elif before != "":
            out.append(before)
        out.append(m.group(0))
        pos = m.end()
    tail = inner[pos:]
    if tail.strip() != "":
        out.append(tail)
    elif tail != "" and out:
        out[-1] = out[-1] + tail
    elif tail != "":
        out.append(tail)
    return out or [inner]


def parse_site_html(html: str) -> list:
    """A published body → blocks that carry their own bytes.

    Byte-exact: "".join(b["src"] + b["sep"] for b in parse_site_html(x)) == x
    for any input, including one this parser does not understand.
    """
    text = html or ""
    if text == "":
        return []
    blocks: list = []
    pending_prefix = ""      # whitespace before the first block
    pos = 0
    group_n = 0

    def _emit(parts: list, src_slices: list, wrap: tuple | None) -> None:
        nonlocal pending_prefix, group_n
        if not parts:
            return
        if wrap is not None and len(parts) > 1:
            group_n += 1
            gid = _SITE_GROUP + str(group_n)
            for part in parts:
                part["wrap"] = {"open": wrap[0], "close": wrap[1], "group": gid}
        for part, slice_src in zip(parts, src_slices):
            part["src"] = pending_prefix + slice_src
            part["sep"] = ""
            part["imported"] = True
            pending_prefix = ""
            blocks.append(part)

    for m in _RX_SITE["element"].finditer(text):
        gap = text[pos:m.start()]
        if gap:
            if gap.strip() == "" and blocks:
                blocks[-1]["sep"] = blocks[-1].get("sep", "") + gap
            elif gap.strip() == "":
                pending_prefix += gap
            else:
                # Loose text between elements. Kept verbatim, labelled, never
                # silently folded into a neighbour.
                _emit([{"type": "raw", "text": gap.strip(),
                        "label": "Content the site stored outside any element"}],
                      [gap], None)
        element = m.group(0)
        pw = _RX_SITE["p_wrap"].match(element)
        if pw:
            open_tag, inner, close_tag = pw.group(1), pw.group(2), pw.group(3)
            fragments = _split_site_fragments(inner)
            parts = [_site_block_for(fragment) for fragment in fragments]
            if len(parts) == 1:
                _emit(parts, [element], None)
            else:
                # The wrapper bytes ride with the pieces they touch -- the open
                # tag with the first, the close tag with the last -- so the
                # concatenation is still exact however many pieces there are.
                slices = list(fragments)
                slices[0] = open_tag + slices[0]
                slices[-1] = slices[-1] + close_tag
                _emit(parts, slices, (open_tag, close_tag))
        else:
            _emit([_site_block_for(element)], [element], None)
        pos = m.end()

    tail = text[pos:]
    if tail:
        if tail.strip() == "" and blocks:
            blocks[-1]["sep"] = blocks[-1].get("sep", "") + tail
        elif tail.strip() == "":
            _emit([{"type": "raw", "text": "", "label": "Whitespace"}], [tail], None)
        else:
            _emit([{"type": "raw", "text": tail.strip(),
                    "label": "Content the site stored outside any element"}], [tail], None)
    if pending_prefix and blocks:
        blocks[0]["src"] = pending_prefix + blocks[0]["src"]
    elif pending_prefix:
        blocks.append({"type": "raw", "text": "", "label": "Whitespace",
                       "src": pending_prefix, "sep": "", "imported": True})
    return blocks


def serialize_site_blocks(blocks: list) -> str:
    """Imported blocks → the bytes they came from.

    Only meaningful while every block still carries its "src". A block the
    editor has changed has had its "src" removed, and the sender serializes
    that one through serialize_block() instead -- see the contract posted to
    the room for SS-1612.
    """
    out = []
    for b in (blocks or []):
        if "src" in b:
            out.append(str(b.get("src", "")) + str(b.get("sep", "")))
        else:
            out.append(serialize_block(b) + str(b.get("sep", "")))
    return "".join(out)


def site_blocks_are_untouched(blocks: list) -> bool:
    """True when every block still carries the bytes it was imported with."""
    blocks = blocks or []
    return bool(blocks) and all("src" in b for b in blocks)


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
        if t == "site_mosaic":
            # A gallery that lives on the site. It is a BLOCK -- movable and
            # deletable -- but nothing about it is editable here, and it must
            # never render as the characters "[mosaic:752]", which is what
            # Sean saw on the canvas.
            mid = str(b.get("mosaic_id") or "").strip()
            label = QLabel("▦  SITE MOSAIC" + (" " + mid if mid else ""))
            label.setStyleSheet("padding:14px; border:1px dashed rgba(127,127,127,0.6);"
                                " font-weight:600;")
            label.setAlignment(Qt.AlignCenter)
            col.addWidget(label)
            col.addWidget(hint("A gallery stored on the site. Move or delete this block; "
                               "its photographs are arranged on the site, not here."))
            return
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
    # SS-1612. Keys an IMPORTED block carries that no widget owns. to_block()
    # rebuilds a block from its widgets, so without this list the verbatim
    # source bytes were dropped the moment the editor opened -- which would
    # have destroyed the byte-identical round trip by merely looking at a post.
    _IMPORT_KEYS = ("sep", "wrap", "imported", "mosaic_id", "label")

    def _carry_import_keys(self, block: dict) -> dict:
        """Re-attach the import bookkeeping, and the source bytes if and only
        if this block's content is still exactly what was imported.

        The source is what makes an untouched post send back byte for byte. It
        must NOT survive an edit, or an edited block would be sent as its old
        text."""
        original = self._block or {}
        for key in self._IMPORT_KEYS:
            if key in original and key not in block:
                block[key] = original[key]
        if "src" in original:
            strip = set(self._IMPORT_KEYS) | {"src"}
            was = {k: v for k, v in original.items() if k not in strip}
            now = {k: v for k, v in block.items() if k not in strip}
            if was == now:
                block["src"] = original["src"]
        return block

    def to_block(self) -> dict:
        return self._carry_import_keys(self._to_block_from_widgets())

    def _to_block_from_widgets(self) -> dict:
        t = self.btype
        if t == "site_mosaic":
            # Nothing here is editable: it points at a gallery on the site.
            # Rebuilt from what was imported so it cannot drift.
            return {**(self._block or {}), "type": "site_mosaic"}
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
            # SS-1612, and a fault in its own right that predates it: this
            # returned ONLY the three fields it has widgets for, so caption,
            # show_caption, gallery, width and decorative were DISCARDED by
            # displaying the block. Measured on Ray's post 4: the captioned
            # photograph "Pontiac RPM, Car Show, Strathmore, AB, 2026-07-11"
            # lost its caption on the way through, because BIGGIE has no
            # caption widget for an image and nothing carried the stored value
            # forward. An editor must not drop what it cannot show.
            widget_fields = {"img_id": self.img_id.text().strip(),
                             "size": self.size.currentText(),
                             "align": self.align.currentText()}
            block = {**(self._block or {}), "type": t, **widget_fields}
            # "raw" is the verbatim shortcode and WINS in serialize_block, so
            # it must not survive a change to the fields it contradicts.
            original = self._block or {}
            if any(str(original.get(k, "")) != str(v) for k, v in widget_fields.items()):
                block.pop("raw", None)
            return block
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
