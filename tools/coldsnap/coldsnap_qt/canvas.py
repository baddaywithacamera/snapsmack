"""BIGGIE canvas — COLD SNAP's WYSIWYG writing surface (COLD TAKE).

Sean, 2026-09-09: "Blocks are supposed to be a WYSIWYG experience. That means
I should see the mosaic built in the editor window. Not a shitty block with
shitty text. And I cannot even figure out how to enter another paragraph."

So: ONE canvas (a QTextEdit over a QTextDocument). You click in it and type.
Enter makes a paragraph. Headings, quotes and pull quotes LOOK like headings,
quotes and pull quotes. A mosaic is painted from the bucket photos, tiled in
its layout, where it will sit in the post. Images, spacers and dividers are
drawn, not typed. Ctrl+Z undoes everything, mosaics included.

Shortcodes are an EXPORT format only: to_blocks() walks the document into the
same block list biggie.py has always serialized, so the site receives exactly
the strings the TWIGGY bar makes. from_blocks() rebuilds the canvas from a
saved draft. Round trip is lossless (tests/test_biggie.py pins it).

Spec, in Sean's words: _spec/SPEC-biggie-wysiwyg-in-seans-words-2026-09-09.md

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import os

from PySide6.QtCore import Qt, QRect, QRectF, QSizeF, Signal
from PySide6.QtGui import (
    QColor, QFont, QPainter, QPen, QPixmap, QPixmapCache, QTextBlockFormat,
    QTextCharFormat, QTextCursor, QTextFormat, QTextFrameFormat, QTextLength,
    QTextListFormat, QTextTableFormat, QPyTextObject, QAction, QKeySequence,
)
from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QTextEdit, QPushButton, QMenu,
    QInputDialog, QDialog, QDialogButtonBox, QLabel, QLineEdit, QComboBox,
    QSpinBox, QMessageBox,
)

from . import theme
from . import biggie
from .mosaic_layout import tile_rects, natural_height

# ── document vocabulary ─────────────────────────────────────────────────────
OBJ_TYPE     = QTextFormat.UserObject + 41          # our inline objects
PROP_KIND    = QTextFormat.UserProperty + 1         # block kind / object kind
PROP_DATA    = QTextFormat.UserProperty + 2         # object payload (JSON)
PROP_DROPCAP = QTextFormat.UserProperty + 3         # paragraph: drop cap on
PROP_RATIO   = QTextFormat.UserProperty + 4         # table: columns ratio
OBJECT_CHAR  = "￼"

BLOCK_KINDS = ("para", "h2", "h3", "quote", "pullquote", "raw")
KIND_LABELS = {"para": "Paragraph", "h2": "Heading", "h3": "Sub-heading",
               "quote": "Quote", "pullquote": "Pull quote", "raw": "Raw"}


def _block_kind(block) -> str:
    kind = block.blockFormat().property(PROP_KIND)
    return kind if kind in BLOCK_KINDS else "para"


# ── inline objects: mosaic · image · spacer · hr ────────────────────────────
class _Objects(QPyTextObject):
    """Paints the non-text things. Sizes come from the canvas width so a
    mosaic is the same proportion it will be on the site."""

    def __init__(self, canvas):
        super().__init__(canvas)
        self.canvas = canvas

    # width the object may use = the text column, minus a little breathing room
    def _width(self, doc):
        w = int(doc.textWidth())
        if w <= 0:
            w = max(300, self.canvas.viewport().width())
        return max(120, w - 24)

    def intrinsicSize(self, doc, pos, fmt):
        kind = fmt.property(PROP_KIND)
        data = _data(fmt)
        w = self._width(doc)
        if kind == "mosaic":
            order = data.get("order") or []
            count = len(order) or len(self.canvas.bucket)
            return QSizeF(w, natural_height(w, count, data.get("layout", "asymmetric")) + 22)
        if kind == "image":
            size = data.get("size", "full")
            align = data.get("align", "center")
            iw = w if size == "full" else (int(w * 0.6) if size == "wall" else int(w * 0.36))
            if align in ("left", "right") and size != "full":
                iw = int(w * 0.36)
            return QSizeF(w, int(iw * 0.66) + 4)
        if kind == "spacer":
            return QSizeF(w, max(6, min(100, int(data.get("px", 20)))) + 2)
        if kind == "hr":
            return QSizeF(w, 14)
        return QSizeF(w, 20)

    def drawObject(self, painter, rect, doc, pos, fmt):
        kind = fmt.property(PROP_KIND)
        data = _data(fmt)
        r = rect.toRect()
        painter.save()
        painter.setRenderHint(QPainter.SmoothPixmapTransform, True)
        painter.setRenderHint(QPainter.Antialiasing, True)
        if kind == "mosaic":
            self._draw_mosaic(painter, r, data)
        elif kind == "image":
            self._draw_image(painter, r, data)
        elif kind == "spacer":
            painter.setPen(QPen(QColor(theme.BORDER), 1, Qt.DashLine))
            painter.drawRect(r.adjusted(0, 1, -1, -2))
            painter.setPen(QColor(theme.FAINT))
            painter.drawText(r, Qt.AlignCenter, f"{data.get('px', 20)} px gap")
        elif kind == "hr":
            painter.setPen(QPen(QColor(theme.DIM), 1))
            y = r.top() + r.height() // 2
            painter.drawLine(r.left(), y, r.right(), y)
        painter.restore()

    def _draw_mosaic(self, painter, r, data):
        layout = data.get("layout", "asymmetric")
        order = list(data.get("order") or [])
        bucket = self.canvas.bucket
        if not order:
            order = list(range(1, len(bucket) + 1))
        paths = []
        for identity in order:
            if str(identity).isdigit() and 1 <= int(identity) <= len(bucket):
                paths.append(bucket[int(identity) - 1])
            elif str(identity) in self.canvas.assets:
                paths.append(self.canvas.assets[str(identity)])
        painter.fillRect(r, QColor("#090b0a"))
        inner = r.adjusted(0, 0, 0, -22)
        cells = tile_rects(inner.width(), inner.height(), len(paths), layout)
        for n, (path, cell) in enumerate(zip(paths, cells), 1):
            tile = cell.translated(inner.topLeft())
            pix = _pixmap(path, tile.width())
            painter.fillRect(tile, QColor("#141714"))
            if not pix.isNull():
                # Whole photograph, letterboxed — a preview must never imply a
                # crop the photographer did not choose (same rule as the dialog).
                scaled = pix.scaled(tile.size(), Qt.KeepAspectRatio, Qt.SmoothTransformation)
                target = QRect(tile.left() + (tile.width() - scaled.width()) // 2,
                               tile.top() + (tile.height() - scaled.height()) // 2,
                               scaled.width(), scaled.height())
                painter.drawPixmap(target, scaled)
            painter.setPen(QPen(QColor(theme.ACCENT_DIM), 1))
            painter.drawRect(tile.adjusted(0, 0, -1, -1))
            badge = QRect(tile.left() + 4, tile.top() + 4, 22, 18)
            painter.fillRect(badge, QColor(0, 0, 0, 190))
            painter.setPen(QColor("#ffffff"))
            painter.drawText(badge, Qt.AlignCenter, str(n))
        if not paths:
            painter.setPen(QColor(theme.DIM))
            painter.drawText(inner, Qt.AlignCenter,
                             "MOSAIC — add photos to the bucket to see it")
        foot = QRect(r.left(), r.bottom() - 20, r.width(), 20)
        painter.setPen(QColor(theme.DIM))
        painter.drawText(foot, Qt.AlignVCenter | Qt.AlignLeft,
                         f"  MOSAIC · {len(paths)} photo(s) · {layout} · double-click to change")

    def _draw_image(self, painter, r, data):
        size = data.get("size", "full")
        placement = data.get("placement", "")
        align = {"wrap-left": "left", "wrap-right": "right"}.get(
            placement, data.get("align", "center"))
        ratio = max(0.25, min(1.0, float(data.get("width_ratio", 0) or 0)))
        iw = int(r.width() * ratio) if data.get("asset_uuid") else (
            r.width() if size == "full" else (int(r.width() * 0.6) if size == "wall" else int(r.width() * 0.36)))
        if align in ("left", "right") and size != "full":
            iw = int(r.width() * 0.36)
        x = r.left() if align == "left" else (r.right() - iw if align == "right"
                                              else r.left() + (r.width() - iw) // 2)
        box = QRect(x, r.top() + 2, iw, r.height() - 4)
        identity = "asset:" + str(data["asset_uuid"]) if data.get("asset_uuid") else str(data.get("img_id", ""))
        pix = self.canvas.image_pixmap(identity, iw)
        painter.fillRect(box, QColor("#141714"))
        if pix is not None and not pix.isNull():
            scaled = pix.scaled(box.size(), Qt.KeepAspectRatio, Qt.SmoothTransformation)
            painter.drawPixmap(QRect(box.left() + (box.width() - scaled.width()) // 2,
                                     box.top() + (box.height() - scaled.height()) // 2,
                                     scaled.width(), scaled.height()), scaled)
        else:
            painter.setPen(QColor(theme.DIM))
            sid = str(data.get('img_id', '?'))
            what = (f"this post's photo {sid[7:]} (removed from the bucket?)" if sid.lower().startswith('bucket:')
                    else f"IMG #{sid} from the site's Media Gallery\n(not in COLD STORAGE yet)")
            painter.drawText(box, Qt.AlignCenter, f"{what}  {size} · {align}")
        painter.setPen(QPen(QColor(theme.ACCENT_DIM), 1))
        painter.drawRect(box.adjusted(0, 0, -1, -1))


def _data(fmt) -> dict:
    try:
        v = json.loads(fmt.property(PROP_DATA) or "{}")
        return v if isinstance(v, dict) else {}
    except ValueError:
        return {}


def _pixmap(path: str, width: int) -> QPixmap:
    """Bucket photo, decoded once and cached at a sane size."""
    key = f"biggie:{path}"
    pix = QPixmap()
    if QPixmapCache.find(key, pix) and not pix.isNull():
        return pix
    pix = QPixmap(path) if path and os.path.isfile(path) else QPixmap()
    if not pix.isNull() and pix.width() > 1200:
        pix = pix.scaledToWidth(1200, Qt.SmoothTransformation)
    if not pix.isNull():
        QPixmapCache.insert(key, pix)
    return pix


# ── the canvas ───────────────────────────────────────────────────────────────
class BiggieCanvas(QTextEdit):
    """One writing surface. See module docstring."""

    #: the user wants to build/edit a mosaic: (current order, current layout,
    #: or [] / "" for a fresh one). The host owns the bucket + dialog and
    #: answers with insert_mosaic()/replace_mosaic().
    mosaicEditRequested = Signal(list, str)
    photographsDropped = Signal(list)
    photographPasted = Signal(object)

    def __init__(self, parent=None, *, allow_mosaic: bool = True):
        super().__init__(parent)
        self.allow_mosaic = allow_mosaic
        self.bucket = []                 # ordered local paths of the post's photos
        self.assets = {}                 # immutable asset UUID -> local preview path
        self._image_resolver = None      # callable(img_id, width) -> QPixmap|None
        self._site_provider = None       # callable() -> site url; unlocks the gallery picker
        self._pending_obj_pos = None     # document position of a mosaic being edited
        self._guard = False
        self.setAcceptRichText(False)    # typed/pasted text stays plain — the site parses it
        self.setUndoRedoEnabled(True)
        self.setTabChangesFocus(False)
        self.setPlaceholderText("Start writing. Enter makes a new paragraph. "
                                "Use the bar above for headings, quotes, lists, "
                                "photos and a MOSAIC.")
        self.setObjectName("BiggieCanvas")
        self.setStyleSheet(
            f"QTextEdit#BiggieCanvas {{ background: {theme.CANVAS}; color: {theme.INK};"
            f" border: 1px solid {theme.BORDER}; padding: 14px 18px; }}")
        base = QFont(self.font())
        base.setPointSizeF(max(11.0, base.pointSizeF() + 1.5))
        self.setFont(base)
        self.document().setDefaultFont(base)
        self.document().setDocumentMargin(10)
        self._objects = _Objects(self)
        self.document().documentLayout().registerHandler(OBJ_TYPE, self._objects)
        self.document().contentsChange.connect(self._after_change)
        self.setContextMenuPolicy(Qt.DefaultContextMenu)

    # -- host hooks ---------------------------------------------------------
    def set_bucket(self, paths):
        self.bucket = [str(p) for p in (paths or [])]
        self.document().markContentsDirty(0, self.document().characterCount())
        self.viewport().update()

    def set_assets(self, assets):
        """Provide stable local identities independently of filmstrip order."""
        self.assets = {str(key): str(path) for key, path in dict(assets or {}).items()}
        self.document().markContentsDirty(0, self.document().characterCount())
        self.viewport().update()

    def set_image_resolver(self, fn):
        self._image_resolver = fn

    def set_site_provider(self, fn):
        """Tell the canvas which site it writes for: IMG becomes a picture picker
        from that site's cached Media Gallery, and inline images paint for real."""
        self._site_provider = fn

    def _site(self) -> str:
        try:
            return str(self._site_provider() or "") if self._site_provider else ""
        except Exception:  # noqa: BLE001
            return ""

    def image_pixmap(self, img_id: str, width: int):
        try:
            sid = str(img_id).strip().lower()
            if sid.startswith("asset:"):
                from .widgets import load_pixmap
                path = self.assets.get(sid[6:])
                return load_pixmap(path, max(64, int(width))) if path else None
            if sid.startswith("bucket:") and sid[7:].isdigit():
                n = int(sid[7:])
                if 1 <= n <= len(self.bucket):
                    from .widgets import load_pixmap
                    return load_pixmap(self.bucket[n - 1], max(64, int(width)))
                return None
            if self._image_resolver is not None:
                return self._image_resolver(img_id, width)
            site = self._site()
            if site:
                from .gallery_picker import gallery_thumb_path
                from .widgets import load_pixmap
                path = gallery_thumb_path(site, img_id)
                return load_pixmap(path, max(64, int(width))) if path else None
        except Exception:  # noqa: BLE001 — a preview must never break typing
            return None
        return None

    # -- block kinds ----------------------------------------------------------
    def _char_format_for(self, kind: str) -> QTextCharFormat:
        f = QTextCharFormat()
        base = self.document().defaultFont()
        font = QFont(base)
        f.setForeground(QColor(theme.INK))
        if kind == "h2":
            font.setPointSizeF(base.pointSizeF() * 1.7)
            font.setBold(True)
        elif kind == "h3":
            font.setPointSizeF(base.pointSizeF() * 1.3)
            font.setBold(True)
        elif kind == "quote":
            font.setItalic(True)
            f.setForeground(QColor(theme.BODY))
        elif kind == "pullquote":
            font.setPointSizeF(base.pointSizeF() * 1.35)
            font.setItalic(True)
            f.setForeground(QColor(theme.ACCENT))
        elif kind == "raw":
            font = QFont("Consolas")
            font.setPointSizeF(base.pointSizeF() * 0.95)
            f.setForeground(QColor(theme.DIM))
        f.setFont(font)
        return f

    def _block_format_for(self, kind: str, dropcap: bool = False) -> QTextBlockFormat:
        b = QTextBlockFormat()
        b.setProperty(PROP_KIND, kind)
        b.setProperty(PROP_DROPCAP, bool(dropcap))
        b.setTopMargin(6)
        b.setBottomMargin(6)
        if kind in ("quote", "pullquote"):
            b.setLeftMargin(28)
            b.setRightMargin(28 if kind == "pullquote" else 0)
            b.setTopMargin(12)
            b.setBottomMargin(12)
        if kind == "h2":
            b.setTopMargin(16)
        if kind == "raw":
            b.setBackground(QColor(theme.PANEL))
        return b

    def set_kind(self, kind: str):
        """Make the current paragraph(s) this kind. Lists are left by choosing Paragraph."""
        if kind not in BLOCK_KINDS:
            return
        cur = self.textCursor()
        cur.beginEditBlock()
        start, end = cur.selectionStart(), cur.selectionEnd()
        block = self.document().findBlock(start)
        last = self.document().findBlock(end)
        while block.isValid():
            if not _has_object(block):
                dropcap = bool(block.blockFormat().property(PROP_DROPCAP)) and kind == "para"
                c = QTextCursor(block)
                if block.textList():
                    block.textList().remove(block)
                c.setBlockFormat(self._block_format_for(kind, dropcap))
                c.select(QTextCursor.BlockUnderCursor)
                c.setCharFormat(self._char_format_for(kind))
                c.setBlockCharFormat(self._char_format_for(kind))
                self._apply_dropcap(block)
            if block == last:
                break
            block = block.next()
        cur.endEditBlock()
        self.setTextCursor(cur)
        self.setFocus()

    def toggle_dropcap(self):
        block = self.textCursor().block()
        if _has_object(block):
            return
        on = not bool(block.blockFormat().property(PROP_DROPCAP))
        c = QTextCursor(block)
        c.beginEditBlock()
        bf = block.blockFormat()
        bf.setProperty(PROP_DROPCAP, on)
        c.setBlockFormat(bf)
        self._apply_dropcap(block)
        c.endEditBlock()

    def _apply_dropcap(self, block):
        """A drop cap is the first letter, big. Re-applied after edits so it
        never drifts onto the wrong character."""
        text = block.text()
        base = self._char_format_for(_block_kind(block))
        on = bool(block.blockFormat().property(PROP_DROPCAP))
        c = QTextCursor(block)
        c.select(QTextCursor.BlockUnderCursor)
        c.setCharFormat(base)
        if on and text.strip():
            i = len(text) - len(text.lstrip())
            big = QTextCharFormat(base)
            font = QFont(base.font())
            font.setPointSizeF(font.pointSizeF() * 2.6)
            font.setBold(True)
            big.setFont(font)
            big.setForeground(QColor(theme.ACCENT))
            c = QTextCursor(block)
            c.setPosition(block.position() + i)
            c.setPosition(block.position() + i + 1, QTextCursor.KeepAnchor)
            c.setCharFormat(big)

    def set_list(self, ordered: bool):
        cur = self.textCursor()
        style = QTextListFormat.ListDecimal if ordered else QTextListFormat.ListDisc
        block = cur.block()
        if block.textList() and block.textList().format().style() == style:
            block.textList().remove(block)      # toggle off
            c = QTextCursor(block)
            c.setBlockFormat(self._block_format_for("para"))
            return
        cur.beginEditBlock()
        fmt = QTextListFormat()
        fmt.setStyle(style)
        fmt.setIndent(1)
        bf = self._block_format_for("para")
        cur.setBlockFormat(bf)
        cur.setBlockCharFormat(self._char_format_for("para"))
        cur.createList(fmt)
        cur.endEditBlock()
        self.setFocus()

    # -- inline objects ---------------------------------------------------------
    def _insert_object(self, kind: str, data: dict, replace_at=None):
        """Objects live in their own paragraph so they export as their own block."""
        fmt = QTextCharFormat()
        fmt.setObjectType(OBJ_TYPE)
        fmt.setProperty(PROP_KIND, kind)
        fmt.setProperty(PROP_DATA, json.dumps(data or {}, ensure_ascii=False))
        cur = self.textCursor()
        cur.beginEditBlock()
        if replace_at is not None:
            cur.setPosition(replace_at)
            cur.setPosition(replace_at + 1, QTextCursor.KeepAnchor)
            cur.insertText(OBJECT_CHAR, fmt)
        else:
            block = cur.block()
            if block.text().strip():
                cur.movePosition(QTextCursor.EndOfBlock)
                cur.insertBlock(self._block_format_for("para"), self._char_format_for("para"))
            else:
                cur.setBlockFormat(self._block_format_for("para"))
            cur.insertText(OBJECT_CHAR, fmt)
            cur.insertBlock(self._block_format_for("para"), self._char_format_for("para"))
        cur.endEditBlock()
        self.setTextCursor(cur)
        self.setFocus()

    def insert_mosaic(self, order, layout: str):
        self._insert_object("mosaic", {"order": list(order), "layout": str(layout)})

    def replace_mosaic(self, order, layout: str):
        pos = self._pending_obj_pos
        self._pending_obj_pos = None
        if pos is None:
            return self.insert_mosaic(order, layout)
        self._insert_object("mosaic", {"order": list(order), "layout": str(layout)},
                            replace_at=pos)

    def insert_image(self, img_id: str, size: str = "full", align: str = "center",
                     *, width_ratio=None, alt="", caption=""):
        data = {"img_id": str(img_id).strip(), "size": size, "align": align}
        if str(img_id).startswith("asset:"):
            data.update({"asset_uuid": str(img_id)[6:],
                         "width_ratio": float(width_ratio or 1.0),
                         "placement": "block" if align == "center" else f"wrap-{align}",
                         "alt": str(alt or ""), "caption": str(caption or "")})
        self._insert_object("image", data)

    # -- local photograph input -----------------------------------------------
    def canInsertFromMimeData(self, source):
        return bool(source.hasImage() or any(url.isLocalFile() for url in source.urls())) \
            or super().canInsertFromMimeData(source)

    def insertFromMimeData(self, source):
        paths = [url.toLocalFile() for url in source.urls() if url.isLocalFile()]
        if paths:
            self.photographsDropped.emit(paths)
            return
        if source.hasImage():
            self.photographPasted.emit(source.imageData())
            return
        super().insertFromMimeData(source)

    def insert_spacer(self, px: int = 20):
        self._insert_object("spacer", {"px": max(1, min(100, int(px)))})

    def insert_hr(self):
        self._insert_object("hr", {})

    def insert_columns(self, n: int, ratio: str = "equal"):
        n = max(1, min(4, int(n)))
        cur = self.textCursor()
        if cur.currentTable() is not None:
            QMessageBox.information(self, "Columns", "Columns can't go inside columns.")
            return
        cur.beginEditBlock()
        if cur.block().text().strip():
            cur.movePosition(QTextCursor.EndOfBlock)
            cur.insertBlock(self._block_format_for("para"), self._char_format_for("para"))
        tf = QTextTableFormat()
        tf.setProperty(PROP_RATIO, biggie._normalise_ratio(ratio, n))
        tf.setCellPadding(8)
        tf.setCellSpacing(0)
        tf.setBorder(1)
        tf.setBorderBrush(QColor(theme.BORDER))
        tf.setBorderStyle(QTextFrameFormat.BorderStyle_Dashed)
        tf.setWidth(QTextLength(QTextLength.PercentageLength, 100))
        parts = _ratio_parts(biggie._normalise_ratio(ratio, n), n)
        total = float(sum(parts))
        tf.setColumnWidthConstraints(
            [QTextLength(QTextLength.PercentageLength, 100.0 * p / total) for p in parts])
        table = cur.insertTable(1, n, tf)
        for i in range(n):
            c = table.cellAt(0, i).firstCursorPosition()
            c.setBlockFormat(self._block_format_for("para"))
            c.setBlockCharFormat(self._char_format_for("para"))
        after = QTextCursor(table.lastCursorPosition())
        after.movePosition(QTextCursor.NextBlock)
        if after.currentTable() is not None or after.atEnd():
            end = QTextCursor(self.document())
            end.movePosition(QTextCursor.End)
            end.insertBlock(self._block_format_for("para"), self._char_format_for("para"))
        cur.endEditBlock()
        self.setTextCursor(table.cellAt(0, 0).firstCursorPosition())
        self.setFocus()

    # -- object hit testing -------------------------------------------------------
    def _object_at(self, pos: int):
        """(kind, data, position) of the inline object at document position pos, or None."""
        doc = self.document()
        for p in (pos, pos - 1):
            if p < 0:
                continue
            block = doc.findBlock(p)
            if not block.isValid():
                continue
            it = block.begin()
            while not it.atEnd():
                frag = it.fragment()
                if frag.isValid() and frag.contains(p):
                    f = frag.charFormat()
                    if f.objectType() == OBJ_TYPE:
                        return f.property(PROP_KIND), _data(f), frag.position()
                    break
                it += 1
        return None

    def _object_under_mouse(self, point):
        pos = self.document().documentLayout().hitTest(point, Qt.FuzzyHit)
        return self._object_at(pos) if pos >= 0 else None

    def mouseDoubleClickEvent(self, event):
        hit = self._object_under_mouse(event.position())
        if hit and hit[0] == "mosaic":
            self._edit_mosaic(hit)
            return
        if hit and hit[0] == "image":
            self._edit_image(hit)
            return
        if hit and hit[0] == "spacer":
            self._edit_spacer(hit)
            return
        super().mouseDoubleClickEvent(event)

    def contextMenuEvent(self, event):
        hit = self._object_under_mouse(event.pos())
        menu = self.createStandardContextMenu()
        if hit:
            kind, _data_, pos = hit
            menu.addSeparator()
            if kind == "mosaic":
                menu.addAction("Change this mosaic…", lambda: self._edit_mosaic(hit))
            elif kind == "image":
                menu.addAction("Change this image…", lambda: self._edit_image(hit))
            elif kind == "spacer":
                menu.addAction("Change the gap…", lambda: self._edit_spacer(hit))
            if kind in ("image", "mosaic"):
                menu.addAction("Move earlier", lambda: self._move_object(pos, -1))
                menu.addAction("Move later", lambda: self._move_object(pos, 1))
            if kind == "image":
                menu.addAction("Make narrower", lambda: self._nudge_image(pos, -.17))
                menu.addAction("Make wider", lambda: self._nudge_image(pos, .17))
            menu.addAction(f"Remove this {KIND_OBJ.get(kind, kind)}", lambda: self._remove_object(pos))
        menu.exec(event.globalPos())

    def _remove_object(self, pos: int):
        c = self.textCursor()
        c.beginEditBlock()
        c.setPosition(pos)
        c.setPosition(pos + 1, QTextCursor.KeepAnchor)
        c.removeSelectedText()
        if not c.block().text().strip() and c.block().previous().isValid():
            c.deletePreviousChar()      # drop the now-empty paragraph
        c.endEditBlock()

    def _move_object(self, pos, delta):
        hit = self._object_at(pos)
        if not hit:
            return
        block = self.document().findBlock(pos)
        target = block.previous() if delta < 0 else block.next()
        if not target.isValid():
            return
        model = {"type": hit[0], **hit[1]}
        c = QTextCursor(block)
        c.beginEditBlock()
        c.select(QTextCursor.BlockUnderCursor)
        c.removeSelectedText()
        c.deleteChar()
        c.setPosition(target.position() if delta < 0 else target.position() + target.length() - 1)
        self.setTextCursor(c)
        self._insert_object(model.pop("type"), model)
        c.endEditBlock()

    def _nudge_image(self, pos, delta):
        hit = self._object_at(pos)
        if not hit or hit[0] != "image":
            return
        data = dict(hit[1])
        presets = (.25, .33, .5, .67, 1.0)
        current = float(data.get("width_ratio", 1.0) or 1.0)
        index = min(range(len(presets)), key=lambda i: abs(presets[i] - current))
        index = max(0, min(len(presets) - 1, index + (1 if delta > 0 else -1)))
        data["width_ratio"] = presets[index]
        self._insert_object("image", data, replace_at=hit[2])

    def set_preview_width(self, width):
        """Change only the composition viewport; stored responsive ratios remain unchanged."""
        self.document().setTextWidth(float(width) if width else -1)
        self.viewport().update()

    def _edit_mosaic(self, hit):
        _kind, data, pos = hit
        self._pending_obj_pos = pos
        self.mosaicEditRequested.emit(list(data.get("order", [])),
                                      str(data.get("layout", "")))

    def request_mosaic(self):
        """Toolbar MOSAIC: edit the mosaic under the cursor, else build a new one."""
        hit = self._object_at(self.textCursor().position())
        if hit and hit[0] == "mosaic":
            self._edit_mosaic(hit)
        else:
            self._pending_obj_pos = None
            self.mosaicEditRequested.emit([], "")

    def _edit_image(self, hit=None):
        data = hit[1] if hit else {}
        if data.get("asset_uuid"):
            dlg = _LocalImageDialog(self, data)
            if dlg.exec() == QDialog.Accepted and hit:
                self._insert_object("image", dlg.values(), replace_at=hit[2])
            return
        site = self._site()
        if site or self.bucket:
            from .gallery_picker import GalleryPicker
            dlg = GalleryPicker(self, site, data, bucket=self.bucket)
        else:
            dlg = _ImageDialog(self, data)
        if dlg.exec() == QDialog.Accepted:
            d = dlg.values()
            if not d["img_id"]:
                return
            if hit:
                self._insert_object("image", d, replace_at=hit[2])
            else:
                self._insert_object("image", d)

    def _edit_spacer(self, hit=None):
        current = int((hit[1] if hit else {}).get("px", 20))
        px, ok = QInputDialog.getInt(self, "Gap", "Gap height in pixels (1–100):",
                                     current, 1, 100)
        if ok:
            if hit:
                self._insert_object("spacer", {"px": px}, replace_at=hit[2])
            else:
                self.insert_spacer(px)

    # -- typing behaviour -----------------------------------------------------------
    def keyPressEvent(self, event):
        cur = self.textCursor()
        block = cur.block()
        key = event.key()
        if key in (Qt.Key_Return, Qt.Key_Enter) and not (event.modifiers() & Qt.ShiftModifier):
            kind = _block_kind(block)
            # A paragraph on an object line goes BELOW the object, never inside it.
            if _has_object(block):
                cur.movePosition(QTextCursor.EndOfBlock)
                cur.insertBlock(self._block_format_for("para"), self._char_format_for("para"))
                self.setTextCursor(cur)
                return
            if block.textList() and not block.text().strip():
                block.textList().remove(block)      # Enter on an empty bullet ends the list
                c = QTextCursor(block)
                c.setBlockFormat(self._block_format_for("para"))
                c.setBlockCharFormat(self._char_format_for("para"))
                return
            if kind != "para" or bool(block.blockFormat().property(PROP_DROPCAP)):
                # Enter after a heading / quote / drop-cap paragraph = a plain paragraph.
                super().keyPressEvent(event)
                c = self.textCursor()
                if c.block().textList():
                    return
                c.setBlockFormat(self._block_format_for("para"))
                c.setBlockCharFormat(self._char_format_for("para"))
                c.setCharFormat(self._char_format_for("para"))
                self.setTextCursor(c)
                return
        if key == Qt.Key_Backspace and cur.atBlockStart() and not cur.hasSelection():
            if _block_kind(block) != "para" and not block.textList():
                self.set_kind("para")           # first Backspace demotes, second joins
                return
            prev = block.previous()
            if prev.isValid() and _has_object(prev) and not block.text().strip():
                pass                            # fall through: joining onto an object is fine
        super().keyPressEvent(event)

    def _after_change(self, position, removed, added):
        """Keep drop caps on the first letter after any edit near them."""
        if self._guard:
            return
        block = self.document().findBlock(position)
        if block.isValid() and bool(block.blockFormat().property(PROP_DROPCAP)):
            self._guard = True
            try:
                self._apply_dropcap(block)
            finally:
                self._guard = False

    # -- model out ----------------------------------------------------------------------
    def to_blocks(self) -> list:
        doc = self.document()
        out = []
        block = doc.begin()
        table, cols = None, []
        list_acc = None  # (QTextList, ordered, items)

        def flush_list():
            nonlocal list_acc
            if list_acc:
                out.append({"type": "list", "ordered": list_acc[1], "items": list_acc[2]})
                list_acc = None

        def flush_table():
            nonlocal table, cols
            if table is not None:
                out.append({"type": "columns", "cols": cols,
                            "ratio": biggie._normalise_ratio(
                                str(table.format().property(PROP_RATIO) or "equal"), len(cols))})
                table, cols = None, []

        while block.isValid():
            c = QTextCursor(block)
            t = c.currentTable()
            if t is not None:
                flush_list()
                if t is not table:
                    flush_table()
                    table, cols = t, [[] for _ in range(t.columns())]
                cell = t.cellAt(block.position())
                if cell.isValid():
                    cols[cell.column()].extend(_export_block(block, nested=True))
                block = block.next()
                continue
            flush_table()
            tl = block.textList()
            if tl is not None:
                text = block.text().strip()
                ordered = tl.format().style() == QTextListFormat.ListDecimal
                if list_acc and list_acc[0] is tl:
                    if text:
                        list_acc[2].append(text)
                else:
                    flush_list()
                    list_acc = (tl, ordered, [text] if text else [])
                block = block.next()
                continue
            flush_list()
            out.extend(_export_block(block))
            block = block.next()
        flush_list()
        flush_table()
        return out

    # -- model in -----------------------------------------------------------------------
    def from_blocks(self, blocks: list):
        self._guard = True
        try:
            self.clear()
            doc = self.document()
            cur = QTextCursor(doc)
            cur.beginEditBlock()
            first = True
            for b in (blocks or []):
                b = biggie._migrate_block(b)
                if not first:
                    cur.movePosition(QTextCursor.End)
                    cur.insertBlock(self._block_format_for("para"), self._char_format_for("para"))
                first = False
                self._insert_block_model(cur, b, nested=False)
            cur.endEditBlock()
            # drop caps need their big letter painted
            block = doc.begin()
            while block.isValid():
                if bool(block.blockFormat().property(PROP_DROPCAP)):
                    self._apply_dropcap(block)
                block = block.next()
            cur.movePosition(QTextCursor.End)      # resume where the writing stopped
            self.setTextCursor(cur)
            self.document().clearUndoRedoStacks()
            self.document().setModified(False)
        finally:
            self._guard = False

    def _insert_block_model(self, cur, b: dict, nested: bool):
        t = b.get("type", "para")
        if t in ("para", "raw", "quote", "pullquote"):
            kind = {"para": "para", "raw": "raw", "quote": "quote", "pullquote": "pullquote"}[t]
            cur.setBlockFormat(self._block_format_for(kind, bool(b.get("dropcap"))))
            cur.setBlockCharFormat(self._char_format_for(kind))
            cur.insertText(str(b.get("text", "")), self._char_format_for(kind))
        elif t == "heading":
            kind = "h3" if int(b.get("level", 2)) == 3 else "h2"
            cur.setBlockFormat(self._block_format_for(kind))
            cur.setBlockCharFormat(self._char_format_for(kind))
            cur.insertText(str(b.get("text", "")), self._char_format_for(kind))
        elif t == "list":
            fmt = QTextListFormat()
            fmt.setStyle(QTextListFormat.ListDecimal if b.get("ordered") else QTextListFormat.ListDisc)
            fmt.setIndent(1)
            cur.setBlockFormat(self._block_format_for("para"))
            cur.setBlockCharFormat(self._char_format_for("para"))
            items = [str(i) for i in (b.get("items") or [])] or [""]
            lst = cur.createList(fmt)
            for n, item in enumerate(items):
                if n:
                    cur.insertBlock()
                    lst.add(cur.block())
                cur.insertText(item, self._char_format_for("para"))
        elif t in ("image", "spacer", "hr", "mosaic"):
            fmt = QTextCharFormat()
            fmt.setObjectType(OBJ_TYPE)
            fmt.setProperty(PROP_KIND, t)
            data = {k: v for k, v in b.items() if k != "type"}
            if t == "mosaic" and not self.allow_mosaic:
                cur.setBlockFormat(self._block_format_for("raw"))
                cur.insertText(biggie.serialize_block(b), self._char_format_for("raw"))
                return
            fmt.setProperty(PROP_DATA, json.dumps(data, ensure_ascii=False))
            cur.setBlockFormat(self._block_format_for("para"))
            cur.insertText(OBJECT_CHAR, fmt)
        elif t == "columns" and not nested:
            cols = [biggie._normalise_cell(c) for c in (b.get("cols") or [[]])]
            n = max(1, min(4, len(cols)))
            ratio = biggie._normalise_ratio(str(b.get("ratio") or "equal"), n)
            tf = QTextTableFormat()
            tf.setProperty(PROP_RATIO, ratio)
            tf.setCellPadding(8)
            tf.setCellSpacing(0)
            tf.setBorder(1)
            tf.setBorderBrush(QColor(theme.BORDER))
            tf.setBorderStyle(QTextFrameFormat.BorderStyle_Dashed)
            tf.setWidth(QTextLength(QTextLength.PercentageLength, 100))
            parts = _ratio_parts(ratio, n)
            total = float(sum(parts))
            tf.setColumnWidthConstraints(
                [QTextLength(QTextLength.PercentageLength, 100.0 * p / total) for p in parts])
            table = cur.insertTable(1, n, tf)
            for i in range(n):
                cc = table.cellAt(0, i).firstCursorPosition()
                first = True
                for cb in cols[i]:
                    cb = biggie._migrate_block(cb)
                    if not first:
                        cc.insertBlock(self._block_format_for("para"), self._char_format_for("para"))
                    first = False
                    self._insert_block_model(cc, cb, nested=True)
                if first:
                    cc.setBlockFormat(self._block_format_for("para"))
                    cc.setBlockCharFormat(self._char_format_for("para"))
            cur.setPosition(table.lastCursorPosition().position())
            cur.movePosition(QTextCursor.NextBlock)
        elif t == "columns":
            cur.setBlockFormat(self._block_format_for("raw"))
            cur.insertText(biggie.serialize_block(b), self._char_format_for("raw"))
        else:
            cur.setBlockFormat(self._block_format_for("raw"))
            cur.insertText(str(b.get("text", "")), self._char_format_for("raw"))

    # -- text face compat ------------------------------------------------------------------
    def body_text(self) -> str:
        return biggie.serialize_blocks(self.to_blocks())


KIND_OBJ = {"mosaic": "mosaic", "image": "image", "spacer": "gap", "hr": "divider"}


def _has_object(block) -> bool:
    return OBJECT_CHAR in block.text()


def _ratio_parts(ratio: str, n: int):
    if ratio == "equal":
        return [1] * n
    return [int(p) for p in ratio.split("-")]


def _export_block(block, nested: bool = False) -> list:
    """One document paragraph → zero or more model blocks."""
    kind = _block_kind(block)
    dropcap = bool(block.blockFormat().property(PROP_DROPCAP))
    out = []
    buf = ""

    def flush_text():
        nonlocal buf
        text = buf.strip()
        buf = ""
        if not text:
            return
        if kind == "h2":
            out.append({"type": "heading", "level": 2, "text": text})
        elif kind == "h3":
            out.append({"type": "heading", "level": 3, "text": text})
        elif kind in ("quote", "pullquote", "raw"):
            out.append({"type": kind, "text": text})
        else:
            blk = {"type": "para", "text": text}
            if dropcap:
                blk["dropcap"] = True
            out.append(blk)

    it = block.begin()
    while not it.atEnd():
        frag = it.fragment()
        if frag.isValid():
            f = frag.charFormat()
            if f.objectType() == OBJ_TYPE:
                flush_text()
                okind = f.property(PROP_KIND)
                data = _data(f)
                if okind == "mosaic":
                    blk = {"type": "mosaic"}
                    if data.get("order"):
                        blk["order"] = list(data["order"])
                        blk["layout"] = str(data.get("layout") or "asymmetric")
                    out.append(blk)
                elif okind == "image":
                    image = {"type": "image", "img_id": str(data.get("img_id", "")),
                             "size": data.get("size") or "full",
                             "align": data.get("align") or "center"}
                    for key in ("asset_uuid", "placement", "width_ratio", "crop",
                                "alt", "caption", "link"):
                        if key in data:
                            image[key] = data[key]
                    out.append(image)
                elif okind == "spacer":
                    out.append({"type": "spacer", "px": int(data.get("px", 20))})
                elif okind == "hr":
                    out.append({"type": "hr"})
            else:
                buf += frag.text()
        it += 1
    flush_text()
    return out


# ── the small image dialog ───────────────────────────────────────────────────
class _ImageDialog(QDialog):
    def __init__(self, parent, data: dict):
        super().__init__(parent)
        self.setWindowTitle("Image from the site's Media Gallery")
        col = QVBoxLayout(self)
        col.addWidget(QLabel("No site selected, so no pictures to show. Media Gallery image id:"))
        self.img_id = QLineEdit(str(data.get("img_id", "")))
        col.addWidget(self.img_id)
        row = QHBoxLayout()
        row.addWidget(QLabel("Size"))
        self.size = QComboBox()
        self.size.addItems(list(biggie.IMG_SIZES))
        self.size.setCurrentText(data.get("size") or "full")
        row.addWidget(self.size)
        row.addWidget(QLabel("Align"))
        self.align = QComboBox()
        self.align.addItems(list(biggie.IMG_ALIGNS))
        self.align.setCurrentText(data.get("align") or "center")
        row.addWidget(self.align)
        col.addLayout(row)
        buttons = QDialogButtonBox(QDialogButtonBox.Cancel | QDialogButtonBox.Ok)
        buttons.accepted.connect(self.accept)
        buttons.rejected.connect(self.reject)
        col.addWidget(buttons)

    def values(self) -> dict:
        return {"img_id": self.img_id.text().strip(), "size": self.size.currentText(),
                "align": self.align.currentText()}


class _LocalImageDialog(QDialog):
    """Plain-language contextual controls for a photograph already in the essay."""
    def __init__(self, parent, data):
        super().__init__(parent)
        self._asset_uuid = str(data.get("asset_uuid", ""))
        self.setWindowTitle("Photograph in this story")
        col = QVBoxLayout(self)
        row = QHBoxLayout()
        row.addWidget(QLabel("Placement"))
        self.placement = QComboBox()
        for label, value in (("Block", "block"), ("Wrap left", "wrap-left"),
                             ("Wrap right", "wrap-right"), ("Wide", "wide"),
                             ("Full bleed", "full-bleed")):
            self.placement.addItem(label, value)
        found = self.placement.findData(data.get("placement", "block"))
        self.placement.setCurrentIndex(max(0, found))
        row.addWidget(self.placement, 1)
        row.addWidget(QLabel("Width"))
        self.width = QComboBox()
        for label, value in (("Quarter page", .25), ("Third page", .33),
                             ("Half page", .5), ("Two-thirds page", .67),
                             ("Page width", 1.0)):
            self.width.addItem(label, value)
        wanted = float(data.get("width_ratio", 1.0) or 1.0)
        self.width.setCurrentIndex(min(range(self.width.count()),
                                       key=lambda i: abs(float(self.width.itemData(i)) - wanted)))
        row.addWidget(self.width, 1)
        col.addLayout(row)
        col.addWidget(QLabel("ALT text"))
        self.alt = QLineEdit(str(data.get("alt", "")))
        col.addWidget(self.alt)
        col.addWidget(QLabel("Caption"))
        self.caption = QLineEdit(str(data.get("caption", "")))
        col.addWidget(self.caption)
        col.addWidget(QLabel("Link (optional)"))
        self.link = QLineEdit(str(data.get("link", "")))
        col.addWidget(self.link)
        buttons = QDialogButtonBox(QDialogButtonBox.Cancel | QDialogButtonBox.Ok)
        buttons.accepted.connect(self.accept)
        buttons.rejected.connect(self.reject)
        col.addWidget(buttons)

    def values(self):
        placement = str(self.placement.currentData())
        align = "left" if placement == "wrap-left" else ("right" if placement == "wrap-right" else "center")
        ratio = float(self.width.currentData())
        return {"asset_uuid": self._asset_uuid, "img_id": "asset:" + self._asset_uuid,
                "placement": placement, "width_ratio": ratio,
                "size": "full" if ratio >= 1 else ("wall" if ratio >= .5 else "small"),
                "align": align, "crop": {"mode": "none", "focal_x": .5, "focal_y": .5},
                "alt": self.alt.text().strip(), "caption": self.caption.text().strip(),
                "link": self.link.text().strip()}


# ── toolbar ──────────────────────────────────────────────────────────────────
class CanvasBar(QWidget):
    """What you can make: paragraph kinds, lists, drop cap, and the drawn things."""

    def __init__(self, canvas: BiggieCanvas, parent=None):
        super().__init__(parent)
        self.canvas = canvas
        row = QHBoxLayout(self)
        row.setContentsMargins(0, 0, 0, 0)
        row.setSpacing(4)
        self._btn(row, "¶", "Plain paragraph", lambda: canvas.set_kind("para"))
        self._btn(row, "H2", "Heading", lambda: canvas.set_kind("h2"))
        self._btn(row, "H3", "Sub-heading", lambda: canvas.set_kind("h3"))
        self._btn(row, "BQ", "Quote", lambda: canvas.set_kind("quote"))
        self._btn(row, "PULL", "Pull quote — words lifted out large", lambda: canvas.set_kind("pullquote"))
        self._btn(row, "DROP", "Drop cap — a big first letter on this paragraph", canvas.toggle_dropcap)
        self._sep(row)
        self._btn(row, "UL", "Bullet list", lambda: canvas.set_list(False))
        self._btn(row, "OL", "Numbered list", lambda: canvas.set_list(True))
        self._sep(row)
        self._btn(row, "IMG", "One image from this site's Media Gallery — pick it by picture", canvas._edit_image)
        self._btn(row, "COL 2", "Two columns side by side", lambda: canvas.insert_columns(2))
        self._btn(row, "COL 3", "Three columns", lambda: canvas.insert_columns(3))
        self._btn(row, "HR", "A divider line", canvas.insert_hr)
        self._btn(row, "GAP", "A vertical gap", canvas._edit_spacer)
        self._sep(row)
        self._btn(row, "RAW", "Raw — shortcodes/HTML kept exactly as typed", lambda: canvas.set_kind("raw"))
        self._sep(row)
        self._btn(row, "DESKTOP", "Preview at desktop essay width", lambda: canvas.set_preview_width(760))
        self._btn(row, "PHONE", "Preview responsive wrapping at phone width", lambda: canvas.set_preview_width(360))
        row.addStretch(1)

    def _sep(self, row):
        s = QLabel("·")
        s.setStyleSheet(f"color: {theme.FAINT}; background: transparent;")
        row.addWidget(s)

    def _btn(self, row, label, tip, cb) -> QPushButton:
        b = QPushButton(label)
        b.setObjectName("ScBtn")
        b.setToolTip(tip)
        b.setFocusPolicy(Qt.NoFocus)   # a click must not steal the caret
        b.clicked.connect(cb)
        row.addWidget(b)
        return b

    def add_button(self, label: str, tip: str, callback) -> QPushButton:
        """Host-supplied buttons (COLD TAKE adds MOSAIC)."""
        row = self.layout()
        b = QPushButton(label)
        b.setObjectName("ScBtn")
        b.setToolTip(tip)
        b.setFocusPolicy(Qt.NoFocus)
        b.clicked.connect(callback)
        row.insertWidget(row.count() - 1, b)
        return b

# ===== SNAPSMACK EOF =====
