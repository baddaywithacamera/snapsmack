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
import math
import os
import re

from PySide6.QtCore import Qt, QPointF, QRect, QRectF, QSize, QSizeF, QTimer, Signal
from PySide6.QtGui import (
    QAbstractTextDocumentLayout, QColor, QFont, QMouseEvent, QPainter, QPalette,
    QPen, QPixmap, QPixmapCache, QTextBlockFormat,
    QTextCharFormat, QTextCursor, QTextFormat, QTextFrameFormat, QTextLength,
    QTextListFormat, QTextTableFormat, QPyTextObject, QAction, QKeySequence,
    QImageReader, QImageIOHandler, QShortcut,
)
from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QTextEdit, QPushButton, QMenu,
    QInputDialog, QDialog, QDialogButtonBox, QLabel, QLineEdit, QComboBox,
    QSpinBox, QMessageBox, QCheckBox,
)

from . import theme
from . import biggie
from .mosaic_browser import MosaicBrowser
from .mosaic_clusters import break_cluster_weights, uniform_clusters
from .mosaic_site_layout import compute_layout as site_compute_layout
from .photo_loader import PhotoLoader

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


def add_mosaic_photo_actions(menu, position, count, blocks, cluster, dispatch):
    """Use the same photo commands and labels in both mosaic editors."""
    can_mirror = any(block["count"] > 1 and block["start"] <= position < block["start"] + block["count"]
                     for block in blocks)
    for label, change, step, enabled in (
        ("Move this image earlier", "move", -1, position > 0),
        ("Move this image later", "move", 1, position < count - 1),
        ("Swap this image to the other side", "mirror", 0, can_mirror),
        ("Make larger", "size", 1, count > 1),
        ("Make smaller", "size", -1, count > 1),
        ("Reverse arrangement", "reverse", 0, any(block["count"] > 1 for block in blocks)),
        ("Break up", "break", 0, bool(cluster)),
        ("Include or exclude photos…", "selection", 0, True),
    ):
        action = menu.addAction(label)
        action.triggered.connect(lambda _checked=False, c=change, s=step: dispatch(c, s))
        action.setEnabled(enabled)


def _block_kind(block) -> str:
    kind = block.blockFormat().property(PROP_KIND)
    return kind if kind in BLOCK_KINDS else "para"


def _mosaic_images(canvas, data):
    """The photographs this mosaic draws, with the dimensions the site uses.

    The site composes from each photograph's real aspect ratio, so the editor
    has to hand compute_layout the same width/height the browser would read
    from the file. Sizes come from the header, never a decode.
    """
    order = list(data.get("order") or [])
    bucket = canvas.bucket
    if not order:
        order = list(range(1, len(bucket) + 1))
    selected = []
    for index, identity in enumerate(order):
        if str(identity).isdigit() and 1 <= int(identity) <= len(bucket):
            selected.append((index, identity, bucket[int(identity) - 1]))
        elif str(identity) in canvas.assets:
            selected.append((index, identity, canvas.assets[str(identity)]))
    size_cache = getattr(canvas, "_mosaic_size_cache", None)
    raw_weights = data.get("weights") or {}
    paths = []
    images = []
    for idx, identity, path in selected:
        if size_cache is not None and path in size_cache:
            width, height = size_cache[path]
        else:
            width, height = _image_size(path)
            if size_cache is not None:
                size_cache[path] = (width, height)
        w_val = 0
        if isinstance(raw_weights, dict):
            w_val = int(raw_weights.get(str(identity), raw_weights.get(identity, 0)) or 0)
        elif isinstance(raw_weights, (list, tuple)) and idx < len(raw_weights):
            w_val = int(raw_weights[idx] or 0)
        paths.append(path)
        images.append({"width": width, "height": height, "src": path,
                       "weight": w_val, "identity": identity})
    return paths, images


def _mosaic_gap(data):
    """The saved gap, with ZERO meaning zero.

    `int(data.get("gap", 4) or 4)` read a saved 0 as 4, because `0 or 4` is 4.
    Gap 0 is a real choice -- the column schema allows 0 to 20, parser.php
    clamps to the same range and the engine honours it -- and it is the one a
    flush grid of photographs needs, so previewing it at 4 would show spacing
    the published page does not have. Only a missing, blank or unparseable
    value falls back to 4, which is the schema default for snap_mosaics.gap.
    The range is left to compute_layout, which clamps 0 to 20 exactly as the
    site does; clamping twice is how the two drift apart.
    """
    raw = data.get("gap", 4)
    if raw is None or raw == "":
        return 4
    try:
        return int(float(raw))
    except (TypeError, ValueError):
        return 4


def mosaic_cells(canvas, data, width):
    """The cells this mosaic will occupy at `width`, as the site computes them.

    Separated from the painting so it can be checked. A geometry bug inside a
    paintEvent can only be caught by looking at the screen; the same bug in a
    function that returns numbers is caught by a test. The mutation run that
    prompted this split had a mutant halving the composition width survive,
    because nothing could see the width the painter used.
    """
    paths, images = _mosaic_images(canvas, data)
    if not paths:
        return [], {"items": [], "sections": [], "height": 0.0}
    layout = data.get("layout", "asymmetric")
    gap = _mosaic_gap(data)
    w_int = max(1, int(round(width)))
    weight_key = tuple(image["weight"] for image in images)
    cache = getattr(canvas, "_mosaic_layout_cache", None)
    key = (tuple(paths), layout, gap, w_int, weight_key)
    if cache is not None and key in cache:
        return cache[key]
    # For 1-4 photos, compose at the actual column width (760px/360px) so
    # buildSection() matches ss-engine-mosaic.js (never falling back to 2x2).
    # For 5+ photos on desktop, compose at 1200px so multi-image rows pack cleanly.
    ref_width = 1200.0 if (width > 520 and len(images) >= 5) else float(width)
    scale = (float(width) / ref_width) if ref_width > 0 else 1.0
    raw_out = site_compute_layout(
        images, ref_width, gap, "natural",
        "" if layout == "asymmetric" else layout)
    if abs(scale - 1.0) > 1e-6:
        out = {
            "items": [
                {
                    **t,
                    "x": t["x"] * scale,
                    "y": t["y"] * scale,
                    "width": t["width"] * scale,
                    "height": t["height"] * scale,
                }
                for t in raw_out.get("items", [])
            ],
            "sections": [
                {
                    **s,
                    "x": s.get("x", 0) * scale,
                    "y": s.get("y", 0) * scale,
                    "width": s.get("width", ref_width) * scale,
                    "height": s.get("height", 0) * scale,
                }
                for s in raw_out.get("sections", [])
            ],
            "height": raw_out.get("height", 0.0) * scale,
        }
    else:
        out = raw_out
    res = (paths, out)
    if cache is not None:
        if len(cache) > 64:
            cache.clear()
        cache[key] = res
    return res


def mosaic_cell_problem(path):
    """Why a mosaic cell cannot draw its photograph, or None when it can.

    SS-1301 CLOSE CONDITION 3. Sean reported cells 13 and 14 as blank numbered
    boxes in a 53-photograph mosaic and asked what they were. The editor could
    not tell him: a missing file, a file it cannot decode and an empty path all
    produced the same QPixmap() and the same dark box with a number on it. The
    ticket asks for them to be distinguishable rather than silently identical,
    and that is the whole of what this does -- it does not repair a photograph,
    it stops the editor being silent about one.

    I am NOT claiming this is why 13 and 14 were blank. I have never seen his
    files. This makes the editor state the reason the next time it happens, in
    the cell itself, instead of leaving him to guess from a number.

    There are two states here and not the three the ticket lists. The third,
    still loading, belongs to mode_take's PhotoLoader, which decodes off the
    GUI thread; this path is synchronous, so a cell that has no photograph has
    finished finding that out. Saying "still loading" here would be a lie.
    """
    if not path:
        return "NO FILE"
    if not os.path.isfile(path):
        return "FILE MISSING"
    width, height = _image_size(path)
    if width <= 0 or height <= 0:
        return "CANNOT READ"
    return None


def mosaic_is_approximate(count, layout):
    """True when the preview cannot promise the published arrangement.

    Exactly one case: three photographs on the default layout. The site then
    picks one-left or one-right by hashing the page URL and alternating against
    the previous mosaic on the page (ss-engine-mosaic.js stableTripletSide), and
    a draft has no URL. Everything else is pinned by data-template and exact.
    """
    return layout == "asymmetric" and count == 3


def mosaic_note(count, layout):
    """The footer line. It says plainly when the preview is exact and when it
    is not, because a preview that quietly guesses is the fault being fixed."""
    tail = (" · ARRANGEMENT DECIDED AT PUBLISH — pick a layout to fix it"
            if mosaic_is_approximate(count, layout) else " · matches the site")
    return f"  MOSAIC · {count} photo(s) · {layout}{tail} · double-click to change"


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

    def _image_box_metrics(self, data: dict, col_w: int):
        """Return (iw, ih, align, cap_text, cap_h) matching the photograph's true aspect ratio."""
        size = data.get("size", "full")
        placement = data.get("placement", "")
        align = {"wrap-left": "left", "wrap-right": "right"}.get(
            placement, data.get("align", "center"))
        ratio = max(0.25, min(1.0, float(data.get("width_ratio", 0) or 0)))
        if data.get("asset_uuid"):
            iw = int(col_w * ratio)
        else:
            iw = col_w if size == "full" else (int(col_w * 0.6) if size == "wall" else int(col_w * 0.36))
            if align in ("left", "right") and size != "full":
                iw = int(col_w * 0.36)
        iw = max(48, iw)
        path = ""
        if data.get("asset_uuid"):
            path = self.canvas.assets.get(str(data["asset_uuid"]), "")
        else:
            sid = str(data.get("img_id", "")).strip().lower()
            if sid.startswith("bucket:") and sid[7:].isdigit():
                n = int(sid[7:])
                if 1 <= n <= len(self.canvas.bucket):
                    path = self.canvas.bucket[n - 1]
        ih = int(iw * 0.66)
        if path:
            src_w, src_h = _image_size(path)
            if src_w > 0 and src_h > 0:
                ih = max(32, int(round(iw * (src_h / float(src_w)))))
        cap_text = str(data.get("caption") or "").strip() if bool(data.get("show_caption")) else ""
        cap_h = 26 if cap_text else 0
        return iw, ih, align, cap_text, cap_h

    def intrinsicSize(self, doc, pos, fmt):
        kind = fmt.property(PROP_KIND)
        data = _data(fmt)
        w = self._width(doc)
        if kind == "mosaic":
            # The height the SITE will give this mosaic, computed by the same
            # arithmetic the site uses, rather than the old w * 0.3 * ceil(n/3)
            # guess -- which for 53 photographs returned 5.4x the column width
            # and bore no relation to what would be published.
            paths, out = self.canvas.browser_mosaic_cells(data, w)
            if not paths:
                return QSizeF(w, 160)
            return QSizeF(w, out["height"] + 22)
        if kind == "image":
            _iw, ih, _align, _cap_text, cap_h = self._image_box_metrics(data, w)
            return QSizeF(w, ih + 4 + cap_h)
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
            self._draw_mosaic(painter, r, data, pos)
        elif kind == "image":
            self._draw_image(painter, r, data)
        elif kind == "spacer":
            painter.setPen(QPen(QColor(theme.BORDER), 1, Qt.DashLine))
            painter.drawRect(r.adjusted(0, 1, -1, -2))
            painter.setPen(QColor(theme.FAINT))
            painter.drawText(r, Qt.AlignCenter, f"{data.get('px', 20)} px gap")
        elif kind == "hr":
            ruler = getattr(self.canvas, "_ruler_color", theme.DIM)
            painter.setPen(QPen(QColor(ruler), 1))
            y = r.top() + r.height() // 2
            painter.drawLine(r.left(), y, r.right(), y)
        painter.restore()

    def _placeholder_colors(self):
        """Return (outline_hex, text_hex) contrasting with the canvas page background."""
        bg = None
        ss = self.canvas.styleSheet() or ""
        m = re.search(r"background\s*:\s*(#[0-9a-fA-F]{3,8})", ss)
        if m:
            bg = QColor(m.group(1))
        if bg is None or not bg.isValid():
            pal = getattr(self.canvas, "_surface_palette", None)
            if pal:
                return pal["outline"], pal["fg"]
        if bg is None or not bg.isValid():
            bg = self.canvas.palette().color(self.canvas.viewport().backgroundRole())
        if not bg.isValid():
            bg = QColor("#ffffff")
        lum = 0.299 * bg.red() + 0.587 * bg.green() + 0.114 * bg.blue()
        if lum >= 128:
            return "#444444", "#222222"
        return "#9aa39b", "#e4e8e4"

    def _draw_mosaic(self, painter, r, data, pos=None):
        """Paint the shared browser engine's cells inside the text document."""
        layout = data.get("layout", "asymmetric")
        outline_col, text_col = self._placeholder_colors()
        inner = r.adjusted(0, 0, 0, -22)
        paths, out = self.canvas.browser_mosaic_cells(data, inner.width())

        if not paths:
            order = list(data.get("order") or [])
            msg = (f"MOSAIC — {len(order)} photos not in bucket yet" if order
                   else "MOSAIC — add photos to the bucket to see it")
            painter.setPen(QPen(QColor(outline_col), 1, Qt.DashLine))
            painter.setBrush(Qt.NoBrush)
            painter.drawRect(inner.adjusted(0, 0, -1, -1))
            painter.setPen(QColor(text_col))
            painter.drawText(inner.adjusted(8, 4, -8, -4), Qt.AlignCenter | Qt.TextWordWrap, msg)
            self._mosaic_footer(painter, r, 0, layout)
            return

        missing_names = [os.path.basename(p) or "unknown" for p in paths if not p or not os.path.isfile(p)]
        if len(missing_names) == len(paths):
            shown = ", ".join(missing_names[:4]) + (f" (+{len(missing_names) - 4} more)" if len(missing_names) > 4 else "")
            msg = f"MOSAIC — {len(missing_names)} of {len(paths)} photos missing: {shown}"
            painter.setPen(QPen(QColor(outline_col), 1, Qt.DashLine))
            painter.setBrush(Qt.NoBrush)
            painter.drawRect(inner.adjusted(0, 0, -1, -1))
            painter.setPen(QColor(text_col))
            painter.drawText(inner.adjusted(8, 4, -8, -4), Qt.AlignCenter | Qt.TextWordWrap, msg)
            self._mosaic_footer(painter, r, len(paths), layout)
            return

        if not out['items']:
            painter.setPen(QColor(text_col))
            status = self.canvas._inline_browser_error or 'Loading mosaic preview…'
            painter.drawText(inner, Qt.AlignCenter, status)
        tile_hits = {}
        for n, tile in enumerate(out["items"], 1):
            cell = QRect(inner.left() + int(round(tile["x"])),
                         inner.top() + int(round(tile["y"])),
                         max(1, int(round(tile["width"]))),
                         max(1, int(round(tile["height"]))))
            path = tile["image"].get("src", "")
            pix = self._cached_cell_pixmap(path, cell.size())
            if pix is not None and not pix.isNull():
                painter.drawPixmap(
                    QRect(cell.left() + (cell.width() - pix.width()) // 2,
                          cell.top() + (cell.height() - pix.height()) // 2,
                          pix.width(), pix.height()), pix)
                painter.setPen(QPen(QColor(theme.ACCENT_DIM), 1))
                painter.setBrush(Qt.NoBrush)
                painter.drawRect(cell.adjusted(0, 0, -1, -1))
            else:
                is_missing = not path or not os.path.isfile(path)
                lbl = (f"MOSAIC — 1 photo missing: {os.path.basename(path) or 'unknown'}" if is_missing
                       else f"MOSAIC — {len(paths)} photos — loading…")
                painter.setPen(QPen(QColor(outline_col), 1, Qt.DashLine))
                painter.setBrush(Qt.NoBrush)
                painter.drawRect(cell.adjusted(0, 0, -1, -1))
                painter.setPen(QColor(text_col))
                painter.drawText(cell.adjusted(4, 18, -4, -4),
                                 Qt.AlignCenter | Qt.TextWordWrap, lbl)
            badge = QRect(cell.left() + 4, cell.top() + 4, 22, 18)
            painter.fillRect(badge, QColor(0, 0, 0, 190))
            painter.setPen(QColor("#ffffff"))
            painter.drawText(badge, Qt.AlignCenter, str(n))
            tile_hits[tile['id']] = (tile['image']['identity'], cell)

        clusters = uniform_clusters(out['items'], _mosaic_gap(data))
        buttons = []
        for cluster in clusters:
            frame = QRect()
            for tile in cluster:
                frame = frame.united(tile_hits[tile['id']][1])
            painter.setBrush(Qt.NoBrush)
            painter.setPen(QPen(QColor('#ffc44a'), 3))
            painter.drawRect(frame.adjusted(1, 1, -1, -1))
            button = QRect(frame.left() + 29, frame.top() + 4, 70, 23)
            painter.fillRect(button, QColor('#ffc44a'))
            painter.setPen(QColor('#111111'))
            painter.drawText(button, Qt.AlignCenter, 'Break up')
            buttons.append((button, [tile_hits[tile['id']][0] for tile in cluster]))
        if pos is not None:
            self.canvas._inline_mosaic_hits[pos] = (tile_hits, buttons)
            self.canvas._inline_mosaic_sections[pos] = out.get("sections", ())
        self._mosaic_footer(painter, r, len(paths), layout, len(clusters))

    def _cached_cell_pixmap(self, path: str, cell_size: QSize):
        """Return a cell-sized QPixmap from QPixmapCache or request off-thread decode.

        Never decodes on the UI thread inside paint/drawObject; returns None so
        the caller paints a placeholder until PhotoLoader delivers the frame.
        """
        if not path:
            return None
        stat_cache = getattr(self.canvas, "_mosaic_stat_cache", None)
        if stat_cache is not None and path in stat_cache:
            stamp = stat_cache[path]
        else:
            if not os.path.isfile(path):
                return None
            try:
                stamp = os.stat(path).st_mtime_ns
            except OSError:
                return None
            if stat_cache is not None:
                stat_cache[path] = stamp
        cw, ch = max(1, cell_size.width()), max(1, cell_size.height())
        ckey = f"biggie_cell:{path}:{stamp}:{cw}x{ch}"
        cached = QPixmap()
        if QPixmapCache.find(ckey, cached) and not cached.isNull():
            return cached

        loader = getattr(self.canvas, "_mosaic_loader", None)
        if loader is None:
            return None

        bucket = max(64, ((max(cw, ch) + 63) // 64) * 64)
        sync_result = [None]
        in_sync = [True]

        def _on_ready(pix):
            if pix is None or pix.isNull():
                return
            scaled = pix.scaled(cell_size, Qt.KeepAspectRatio, Qt.FastTransformation)
            QPixmapCache.insert(ckey, scaled)
            if in_sync[0]:
                sync_result[0] = scaled
            elif not getattr(self.canvas, "_mosaic_repaint_pending", False):
                self.canvas._mosaic_repaint_pending = True

                def _flush_repaint():
                    self.canvas._mosaic_repaint_pending = False
                    vp = self.canvas.viewport()
                    if vp is not None:
                        vp.update()

                QTimer.singleShot(0, _flush_repaint)

        accepted = loader.request(path, bucket, _on_ready)
        in_sync[0] = False
        if sync_result[0] is not None:
            return sync_result[0]
        if not accepted and not getattr(self.canvas, "_mosaic_retry_scheduled", False):
            self.canvas._mosaic_retry_scheduled = True

            def _retry_paint():
                self.canvas._mosaic_retry_scheduled = False
                vp = self.canvas.viewport()
                if vp is not None:
                    vp.update()

            QTimer.singleShot(50, _retry_paint)
        return None

    def _mosaic_footer(self, painter, r, count, layout, clusters=0):
        """The strip under the mosaic. It says when the preview is EXACT and
        when it is not, because a preview that quietly guesses is the fault
        this whole change exists to fix.

        The one inexact case: three photographs on the default layout. The site
        then picks one-left or one-right by hashing the page's URL
        (ss-engine-mosaic.js stableTripletSide) and alternates against the
        previous mosaic on the page. A draft has no URL yet, so the editor can
        show a true composition but not necessarily the one that will publish.
        Saving an explicit layout makes it exact.
        """
        foot = QRect(r.left(), r.bottom() - 20, r.width(), 20)
        _outline_col, text_col = self._placeholder_colors()
        painter.setPen(QColor(text_col))
        metrics = painter.fontMetrics()
        if clusters:
            status = f"{clusters} cluster{'s' if clusters != 1 else ''} to break up"
            status = metrics.elidedText(status, Qt.ElideRight, foot.width())
            painter.drawText(foot, Qt.AlignVCenter | Qt.AlignRight, status)
            foot.adjust(0, 0, -metrics.horizontalAdvance(status) - 8, 0)
        if foot.width() > 0:
            note = metrics.elidedText(mosaic_note(count, layout), Qt.ElideRight, foot.width())
            painter.drawText(foot, Qt.AlignVCenter | Qt.AlignLeft, note)

    def _draw_image(self, painter, r, data):
        iw, _ih, align, cap_text, cap_h = self._image_box_metrics(data, r.width())
        x = r.left() if align == "left" else (r.right() - iw if align == "right"
                                              else r.left() + (r.width() - iw) // 2)
        box = QRect(x, r.top() + 2, iw, max(16, r.height() - 4 - cap_h))
        identity = "asset:" + str(data["asset_uuid"]) if data.get("asset_uuid") else str(data.get("img_id", ""))
        pix = self.canvas.image_pixmap(identity, iw)
        if pix is not None and not pix.isNull():
            scaled = pix.scaled(box.size(), Qt.KeepAspectRatio, Qt.SmoothTransformation)
            painter.drawPixmap(QRect(box.left() + (box.width() - scaled.width()) // 2,
                                     box.top() + (box.height() - scaled.height()) // 2,
                                     scaled.width(), scaled.height()), scaled)
            painter.setPen(QPen(QColor(theme.ACCENT_DIM), 1))
            painter.setBrush(Qt.NoBrush)
            painter.drawRect(box.adjusted(0, 0, -1, -1))
        else:
            outline_col, text_col = self._placeholder_colors()
            painter.setPen(QPen(QColor(outline_col), 1, Qt.DashLine))
            painter.setBrush(Qt.NoBrush)
            painter.drawRect(box.adjusted(0, 0, -1, -1))
            painter.setPen(QColor(text_col))
            if data.get("asset_uuid"):
                local_p = self.canvas.assets.get(str(data["asset_uuid"]), "")
                fname = os.path.basename(local_p) if local_p else str(data["asset_uuid"])
                what = f"IMAGE — file missing: {fname}"
            else:
                sid = str(data.get("img_id", "?"))
                what = (f"IMAGE — photo {sid[7:]} missing from bucket" if sid.lower().startswith("bucket:")
                        else f"IMAGE — #{sid} not in COLD STORAGE yet")
            painter.drawText(box.adjusted(8, 4, -8, -4), Qt.AlignCenter | Qt.TextWordWrap, what)
        if cap_text and cap_h > 0:
            cap_font, cap_color = self.canvas.caption_font_and_color()
            cap_align = str(self.canvas._caption_style.get("align") or "left").lower()
            painter.save()
            painter.setFont(cap_font)
            painter.setPen(QColor(cap_color))
            cap_rect = QRect(x, box.bottom() + 2, iw, cap_h - 2)
            align_flag = Qt.AlignHCenter if cap_align == "center" else (Qt.AlignRight if cap_align == "right" else Qt.AlignLeft)
            painter.drawText(cap_rect, align_flag | Qt.AlignVCenter, cap_text)
            painter.restore()


def _data(fmt) -> dict:
    try:
        v = json.loads(fmt.property(PROP_DATA) or "{}")
        return v if isinstance(v, dict) else {}
    except ValueError:
        return {}


#: Qt reports the EXIF transform as flags. Bit 4 is the quarter turn, and it
#: is the only bit that changes the SHAPE: mirroring and flipping leave the
#: width and height alone. Measured across all eight EXIF orientation tags --
#: 1 to 4 are unrotated, 5 to 8 all carry this bit and all swap.
_ROTATE90 = QImageIOHandler.Transformation.TransformationRotate90
_ROTATE90_BIT = int(_ROTATE90.value)


def _seen_size(size, transformation):
    """(width, height) as a VIEWER shows it, from the raw header size + flags.

    QImageReader.size() reports the stored pixel grid and ignores the EXIF
    orientation -- with setAutoTransform(True) as well, which is the part that
    catches people. Measured: a 1500x1000 JPEG tagged Orientation=6 reports
    1500x1000 from size() with autoTransform either way, and read() returns
    1000x1500. There is no effectiveSize() in this PySide6 to ask instead.

    So the swap is done here, from the flags, and this takes plain values so a
    test can drive all eight orientations without eight files on disk.
    """
    width = int(size.width()) if hasattr(size, "width") else int(size[0])
    height = int(size.height()) if hasattr(size, "height") else int(size[1])
    if width <= 0 or height <= 0:
        return (0, 0)
    # The flags are normalised to a plain int, because Qt's Transformation enum
    # in this PySide6 supports neither int() nor `int & Transformation`
    # (measured -- both raise TypeError). Going through .value once here is
    # what lets a test drive all eight orientations from literals.
    flags = int(getattr(transformation, "value", transformation))
    return (height, width) if flags & _ROTATE90_BIT else (width, height)


def _pixmap(path: str, width: int) -> QPixmap:
    """Bucket photo, decoded once and cached at a sane size.

    THE MODIFICATION TIME IS PART OF THE KEY, and it was not. Keyed on the path
    alone, this drew whatever it had cached first and kept drawing it: edit or
    rotate a photograph and the editor kept showing the old version until COLD
    SNAP was restarted. Measured, not theorised -- wrote a red file, drew it,
    replaced the file with a green one at the same path, drew it again, and got
    red.

    That is this owner's actual workflow. SNAP SLAPPER is his only image source
    and the mosaic dialog has Rotate left / Rotate right, so a photograph being
    rewritten at the same path is the normal case, not an edge one. An editor
    that shows the wrong version of a photograph is worse than a slow one,
    because he would only find out after the post was published.

    widgets.load_pixmap already keys on (path, mtime, size) and
    photo_loader.PhotoLoader on st_mtime_ns. This now matches them.
    """
    try:
        stamp = os.stat(path).st_mtime_ns if path else 0
    except OSError:
        stamp = 0

    # DECODE AT THE SIZE ASKED FOR. This function has always taken a width and
    # always ignored it: it loaded the photograph at FULL size and then scaled
    # the result down to a flat 1200. Sean's About portrait is 2655x2655, which
    # is about 28 MB as a QPixmap, allocated and thrown away to keep a 1200px
    # copy -- and QPixmapCache holds 10 MB, measured, which is about TWO of
    # those. With 53 photographs in a mosaic almost every one missed the cache
    # and was decoded again from disk on every repaint: scroll, resize, cursor
    # blink. QImageReader.setScaledSize decodes straight to the target, so the
    # full-size intermediate never exists.
    want = max(16, int(width or 0) or 1200)
    # Bucket to 64px so a few pixels of resize do not invalidate every entry,
    # and so the cache key is stable while a column is being dragged.
    bucket = ((want + 63) // 64) * 64
    key = f"biggie:{path}:{stamp}:{bucket}"
    pix = QPixmap()
    if QPixmapCache.find(key, pix) and not pix.isNull():
        return pix
    if not path or not os.path.isfile(path):
        return QPixmap()

    reader = QImageReader(path)
    reader.setAutoTransform(True)       # honour the EXIF orientation, as before
    source = reader.size()
    # SCALE IN THE PRE-TRANSFORM FRAME, because that is the frame setScaledSize
    # works in: Qt scales the stored grid and THEN rotates. Asking a quarter-
    # turned photograph for a 320-wide pixmap used to deliver a 213-wide one
    # (measured), so the cell got an under-resolved photograph and the cache
    # key lied about what it held. Scaling both axes by one factor off the SEEN
    # width lands the delivered width on the bucket for all eight orientations.
    seen_w, _seen_h = _seen_size(source, reader.transformation())
    if source.isValid() and seen_w > bucket:
        scale = bucket / float(seen_w)
        reader.setScaledSize(QSize(max(1, int(round(source.width() * scale))),
                                   max(1, int(round(source.height() * scale)))))
    image = reader.read()
    pix = QPixmap.fromImage(image) if not image.isNull() else QPixmap()
    if not pix.isNull():
        QPixmapCache.insert(key, pix)
    return pix


def _image_size(path: str):
    """(width, height) from the file HEADER, without decoding the photograph.

    The site composes a mosaic from each photograph's real aspect ratio, so the
    editor needs the dimensions of 53 files before it can draw anything. Reading
    them with QImageReader.size() touches only the header; decoding 53 JPEGs to
    ask how big they are is what made this editor unusable in the first place.
    Cached on path and modification time, like everything else here, because
    SNAP SLAPPER rewrites photographs in place.

    THE EXIF ORIENTATION IS APPLIED, and it was not. A photograph tagged for a
    quarter turn was composed from its stored landscape grid while _pixmap drew
    it portrait, so KeepAspectRatio letterboxed it inside its own cell -- the
    exact banding this whole change removes, back again for rotated files. The
    test fixtures are PIL JPEGs with no EXIF, so no assertion could see it;
    Muse read it out of the Qt API instead.

    Matching the site is not a judgement call here. core/image-ingest.php:359
    rotates the PIXELS on upload for orientation 3, 6 and 8 and then stores
    img_width/img_height off the rotated image, which parser.php:509 emits as
    data-w/data-h and the engine composes from. The published page therefore
    uses the ORIENTED shape, so the editor must too.
    """
    try:
        stamp = os.stat(path).st_mtime_ns if path else 0
    except OSError:
        return (0, 0)
    hit = _SIZE_CACHE.get((path, stamp))
    if hit is not None:
        return hit
    out = (0, 0)
    if os.path.isfile(path):
        reader = QImageReader(path)
        size = reader.size()
        if size.isValid():
            out = _seen_size(size, reader.transformation())
    if len(_SIZE_CACHE) > 2000:
        _SIZE_CACHE.clear()
    _SIZE_CACHE[(path, stamp)] = out
    return out


#: path+mtime -> (w, h). Bounded; header reads are cheap but not free.
_SIZE_CACHE = {}


# ── the canvas ───────────────────────────────────────────────────────────────
def _rel_luminance(c: QColor) -> float:
    def _lin(ch: int) -> float:
        s = ch / 255.0
        return s / 12.92 if s <= 0.03928 else ((s + 0.055) / 1.055) ** 2.4
    return 0.2126 * _lin(c.red()) + 0.7152 * _lin(c.green()) + 0.0722 * _lin(c.blue())


def compute_contrast_ratio(c1: QColor, c2: QColor) -> float:
    """Return WCAG 2.1 contrast ratio (>= 1.0) between two QColors."""
    l1 = _rel_luminance(c1)
    l2 = _rel_luminance(c2)
    hi, lo = max(l1, l2), min(l1, l2)
    return (hi + 0.05) / (lo + 0.05)


def site_surface_palette(bg_hex: str, fg_hex: str = "") -> dict:
    """Derive a WCAG-contrasting editor palette for a site background colour."""
    bg_c = QColor(str(bg_hex or "").strip())
    if not bg_c.isValid():
        bg_c = QColor("#ffffff")
    bg = bg_c.name()
    light_bg = _rel_luminance(bg_c) >= 0.179

    fg_c = QColor(str(fg_hex or "").strip()) if fg_hex else QColor()
    if fg_c.isValid() and compute_contrast_ratio(bg_c, fg_c) >= 4.5:
        fg = fg_c.name()
    else:
        dark_cand = QColor("#1a1a1a")
        light_cand = QColor("#f0f2f0")
        fg = dark_cand.name() if compute_contrast_ratio(bg_c, dark_cand) >= compute_contrast_ratio(bg_c, light_cand) else light_cand.name()

    if light_bg:
        return {
            "bg": bg,
            "fg": fg,
            "heading": "#111111" if compute_contrast_ratio(bg_c, QColor("#111111")) >= 4.5 else fg,
            "muted": "#555555" if compute_contrast_ratio(bg_c, QColor("#555555")) >= 4.5 else fg,
            "eyebrow": "#1e6610" if compute_contrast_ratio(bg_c, QColor("#1e6610")) >= 4.5 else fg,
            "pullquote": "#1e6610" if compute_contrast_ratio(bg_c, QColor("#1e6610")) >= 4.5 else fg,
            "link": "#1a5fb4",
            "selection_bg": "#c8f5be",
            "selection_fg": "#111111",
            "placeholder": "#666666" if compute_contrast_ratio(bg_c, QColor("#666666")) >= 3.0 else fg,
            "outline": "#444444",
            "ruler": "#333333",
        }
    return {
        "bg": bg,
        "fg": fg,
        "heading": "#ffffff" if compute_contrast_ratio(bg_c, QColor("#ffffff")) >= 4.5 else fg,
        "muted": "#b4beb4" if compute_contrast_ratio(bg_c, QColor("#b4beb4")) >= 4.5 else fg,
        "eyebrow": "#76ff03" if compute_contrast_ratio(bg_c, QColor("#76ff03")) >= 4.5 else fg,
        "pullquote": "#8cff5a" if compute_contrast_ratio(bg_c, QColor("#8cff5a")) >= 4.5 else fg,
        "link": "#7ab8ff",
        "selection_bg": "#295a1e",
        "selection_fg": "#ffffff",
        "placeholder": "#9aa39b" if compute_contrast_ratio(bg_c, QColor("#9aa39b")) >= 3.0 else fg,
        "outline": "#9aa39b",
        "ruler": "#9aa39b",
    }


class BiggieCanvas(QTextEdit):
    """One writing surface. See module docstring."""

    #: the user wants to build/edit a mosaic: (current order, current layout,
    #: or [] / "" for a fresh one). The host owns the bucket + dialog and
    #: answers with insert_mosaic()/replace_mosaic().
    mosaicEditRequested = Signal(list, str)
    photographsDropped = Signal(list)
    photographPasted = Signal(object)
    trayPhotoDropped = Signal(str)

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
                                "Use the BLOCKS tray on the left for headings, quotes, "
                                "lists, photos and a MOSAIC.")
        self.setObjectName("BiggieCanvas")
        self.setVerticalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
        self.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
        self.setMinimumHeight(260)
        QPixmapCache.setCacheLimit(65536)
        self._mosaic_loader = PhotoLoader(self)
        self._mosaic_retry_scheduled = False
        self._mosaic_layout_cache = {}
        self._mosaic_size_cache = {}
        self._mosaic_stat_cache = {}
        self._inline_browser = None
        self._inline_browser_error = ''
        self._inline_browser_active = None
        self._inline_browser_jobs = {}
        self._inline_browser_cache = {}
        self._inline_mosaic_hits = {}
        self._inline_mosaic_sections = {}
        self._selected_obj_pos = None
        self._selected_block_pos = None
        self._block_close_rect = None
        self._gutter_grip_rects = {}
        self._obj_close_rects = {}
        self._ruler_color = theme.DIM
        self._body_font_family = "Georgia"
        self._body_font_pt = 12.5
        self._line_height_pct = 150.0
        self._para_margin_bottom = 8.0
        self._image_margin_top = 8.0
        self._image_margin_bottom = 8.0
        self._heading_font_family = theme.FONT
        self._caption_style = {
            "font_family": "Georgia",
            "font_pt": 10.5,
            "color": "",
            "italic": True,
            "align": "left",
        }
        self.default_captions = {}       # asset_uuid or img_id -> default caption/title
        self._preview_width = 760
        self._zoom_scale = 1.0
        self._surface_palette = site_surface_palette("#ffffff", "#1a1a1a")
        self.document().setDocumentMargin(4)
        self._ensure_gutter_margin()
        self.apply_surface_palette(self._surface_palette)
        self._init_empty_doc_format()
        self._objects = _Objects(self)
        self.document().documentLayout().registerHandler(OBJ_TYPE, self._objects)
        self.document().documentLayout().documentSizeChanged.connect(self._sync_canvas_height)
        self.document().contentsChange.connect(self._after_change)
        self._sync_canvas_height()
        self.setContextMenuPolicy(Qt.DefaultContextMenu)
        QShortcut(QKeySequence("Ctrl+B"), self).activated.connect(self.toggle_bold)
        QShortcut(QKeySequence("Ctrl+I"), self).activated.connect(self.toggle_italic)
        QShortcut(QKeySequence("Ctrl+U"), self).activated.connect(self.toggle_underline)
        QShortcut(QKeySequence("Ctrl+K"), self).activated.connect(self.insert_link)

    def browser_mosaic_cells(self, data, width):
        paths, images = _mosaic_images(self, data)
        empty = {'items': [], 'height': 138}
        if not paths:
            return paths, empty
        layout = str(data.get('layout') or 'asymmetric')
        gap = max(0, min(20, _mosaic_gap(data)))
        width = max(1, round(width))
        mirrors = tuple(data.get('mirrors') or ()) if len(paths) == len(data.get('order', ())) else ()
        key = (tuple(paths), tuple(str(item['identity']) for item in images),
               tuple(item['weight'] for item in images), layout, gap, width, mirrors)
        if key in self._inline_browser_cache:
            return paths, self._inline_browser_cache[key]
        if key != self._inline_browser_active and key not in self._inline_browser_jobs:
            self._inline_browser_jobs[key] = images
            QTimer.singleShot(0, self._start_inline_mosaic)
        return paths, empty

    def _start_inline_mosaic(self):
        if self._inline_browser_active is not None or not self._inline_browser_jobs:
            return
        if self._inline_browser is None:
            self._inline_browser = MosaicBrowser(self, measure_only=True)
            self._inline_browser.geometry_ready.connect(self._finish_inline_mosaic)
            self._inline_browser.failed.connect(self._fail_inline_mosaic)
        key = next(iter(self._inline_browser_jobs))
        paths, _identities, weights, layout, gap, width, mirrors = key
        self._inline_browser_active = key
        self._inline_browser.set_mosaic(list(enumerate(paths)), layout, gap,
                                        {str(i): w for i, w in enumerate(weights, 1)}, width, mirrors)

    def _fail_inline_mosaic(self, message):
        self._inline_browser_error = message
        self._finish_inline_mosaic({'items': [], 'height': 138})

    def _finish_inline_mosaic(self, geometry):
        key = self._inline_browser_active
        if key is None:
            return
        images = self._inline_browser_jobs.pop(key)
        for tile in geometry['items']:
            tile['image'] = images[tile['id'] - 1]
        if len(self._inline_browser_cache) >= 128:
            self._inline_browser_cache.pop(next(iter(self._inline_browser_cache)))
        self._inline_browser_cache[key] = geometry
        self._inline_browser_active = None
        document = self.document()
        document.markContentsDirty(0, document.characterCount())
        self.viewport().update()
        self._start_inline_mosaic()

    def _base_font(self) -> QFont:
        f = QFont(self._body_font_family or "Georgia")
        f.setPointSizeF(float(self._body_font_pt or 12.5))
        return f

    def _init_empty_doc_format(self):
        base = self._base_font()
        self.setFont(base)
        self.document().setDefaultFont(base)
        blk = self.document().begin()
        if blk.isValid() and not _has_object(blk) and not blk.text():
            c = QTextCursor(blk)
            c.setBlockFormat(self._block_format_for("para"))
            c.setBlockCharFormat(self._char_format_for("para"))
            c.setCharFormat(self._char_format_for("para"))
            self.setCurrentCharFormat(self._char_format_for("para"))

    def set_skin_typography(self, *, body_font: str = "", body_pt: float = 0.0,
                            caption_font: str = "", caption_pt: float = 0.0,
                            caption_color: str = "", caption_italic: bool = True,
                            caption_align: str = "",
                            line_height_pct: float = 0.0, para_margin_bottom: float = 0.0,
                            image_margin_top: float = 0.0, image_margin_bottom: float = 0.0):
        if body_font and str(body_font).strip():
            self._body_font_family = str(body_font).strip().split(",")[0].strip(" '\"") or "Georgia"
        if body_pt and float(body_pt) > 0:
            self._body_font_pt = float(body_pt)
        if line_height_pct and float(line_height_pct) > 0:
            self._line_height_pct = float(line_height_pct)
        if para_margin_bottom and float(para_margin_bottom) > 0:
            self._para_margin_bottom = float(para_margin_bottom)
        if image_margin_top and float(image_margin_top) > 0:
            self._image_margin_top = float(image_margin_top)
        if image_margin_bottom and float(image_margin_bottom) > 0:
            self._image_margin_bottom = float(image_margin_bottom)
        if caption_font and str(caption_font).strip():
            self._caption_style["font_family"] = str(caption_font).strip().split(",")[0].strip(" '\"")
        else:
            self._caption_style["font_family"] = self._body_font_family
        if caption_pt and float(caption_pt) > 0:
            self._caption_style["font_pt"] = float(caption_pt)
        self._caption_style["color"] = str(caption_color or "").strip()
        self._caption_style["italic"] = bool(caption_italic)
        if caption_align and str(caption_align).strip().lower() in ("left", "center", "right"):
            self._caption_style["align"] = str(caption_align).strip().lower()
        self.apply_surface_palette(self._surface_palette)
        self._refresh_block_spacing()
        self.document().markContentsDirty(0, self.document().characterCount())
        self._sync_canvas_height()
        self.viewport().update()

    def _refresh_block_spacing(self):
        doc = self.document()
        if doc is None:
            return
        prev_guard = self._guard
        self._guard = True
        try:
            blk = doc.begin()
            while blk.isValid():
                c = QTextCursor(blk)
                if _has_object(blk):
                    c.setBlockFormat(self._object_block_format())
                else:
                    kind = _block_kind(blk)
                    dropcap = bool(blk.blockFormat().property(PROP_DROPCAP))
                    c.setBlockFormat(self._block_format_for(kind, dropcap=dropcap))
                blk = blk.next()
        finally:
            self._guard = prev_guard

    def caption_font_and_color(self) -> tuple:
        fam = self._caption_style.get("font_family") or self._body_font_family or "Georgia"
        pt = float(self._caption_style.get("font_pt") or 10.5)
        f = QFont(fam)
        f.setPointSizeF(pt)
        f.setItalic(bool(self._caption_style.get("italic", True)))
        col = str(self._caption_style.get("color") or "").strip()
        if not col or not QColor(col).isValid():
            col = self._surface_palette.get("muted", "#555555")
        return f, col

    def _sync_canvas_height(self, _size=None):
        scale = float(getattr(self, "_zoom_scale", 1.0) or 1.0)
        doc_h = self.document().documentLayout().documentSize().height() * scale
        self.setFixedHeight(max(260, int(math.ceil(doc_h)) + 18))

    # -- host hooks ---------------------------------------------------------
    def set_bucket(self, paths):
        self.bucket = [str(p) for p in (paths or [])]
        self._mosaic_layout_cache.clear()
        self._mosaic_size_cache.clear()
        self._mosaic_stat_cache.clear()
        self.document().markContentsDirty(0, self.document().characterCount())
        self._sync_canvas_height()
        self.viewport().update()

    def set_assets(self, assets):
        """Provide stable local identities independently of filmstrip order."""
        self.assets = {str(key): str(path) for key, path in dict(assets or {}).items()}
        self._mosaic_layout_cache.clear()
        self._mosaic_size_cache.clear()
        self._mosaic_stat_cache.clear()
        self.document().markContentsDirty(0, self.document().characterCount())
        self._sync_canvas_height()
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

    def apply_surface_palette(self, pal: dict):
        """Apply site background and contrasting text/selection/placeholder colours."""
        self._surface_palette = dict(pal)
        self._ruler_color = pal["ruler"]
        fam = self._body_font_family or "Georgia"
        pt = float(self._body_font_pt or 12.5)
        self.setStyleSheet(
            f"QTextEdit#BiggieCanvas {{ background: {pal['bg']}; color: {pal['fg']};"
            f" font-family: \"{fam}\"; font-size: {pt}pt;"
            f" border: none; padding: 6px 0px; selection-background-color: {pal['selection_bg']};"
            f" selection-color: {pal['selection_fg']}; }}")
        vp = self.viewport()
        vp.setAutoFillBackground(True)
        vp.setStyleSheet(
            f"background: {pal['bg']}; color: {pal['fg']};"
            f" font-family: \"{fam}\"; font-size: {pt}pt;")
        base = self._base_font()
        self.setFont(base)
        self.document().setDefaultFont(base)
        p = self.palette()
        p.setColor(QPalette.Base, QColor(pal["bg"]))
        p.setColor(QPalette.Window, QColor(pal["bg"]))
        p.setColor(QPalette.Text, QColor(pal["fg"]))
        p.setColor(QPalette.WindowText, QColor(pal["fg"]))
        p.setColor(QPalette.PlaceholderText, QColor(pal["placeholder"]))
        p.setColor(QPalette.Link, QColor(pal["link"]))
        self.setPalette(p)
        self._recolor_existing_blocks()
        self.viewport().update()

    def _recolor_existing_blocks(self):
        """Refresh block fonts and foreground colours across all text blocks."""
        doc = self.document()
        if doc is None:
            return
        prev_guard = self._guard
        self._guard = True
        try:
            cur = QTextCursor(doc)
            cur.beginEditBlock()
            blk = doc.begin()
            while blk.isValid():
                if not _has_object(blk):
                    kind = _block_kind(blk)
                    target = self._char_format_for(kind)
                    c = QTextCursor(blk)
                    c.setBlockCharFormat(target)
                    it = blk.begin()
                    has_frags = False
                    while not it.atEnd():
                        frag = it.fragment()
                        if frag.isValid() and frag.charFormat().objectType() != OBJ_TYPE:
                            has_frags = True
                            fc = QTextCursor(doc)
                            fc.setPosition(frag.position())
                            fc.setPosition(frag.position() + frag.length(), QTextCursor.KeepAnchor)
                            old_f = frag.charFormat()
                            merged = QTextCharFormat(target)
                            if old_f.fontWeight() >= QFont.Bold and kind not in ("h2", "h3"):
                                mf = QFont(merged.font())
                                mf.setBold(True)
                                merged.setFont(mf)
                            if old_f.fontItalic() and kind not in ("quote", "pullquote"):
                                mf = QFont(merged.font())
                                mf.setItalic(True)
                                merged.setFont(mf)
                            if old_f.fontUnderline():
                                merged.setFontUnderline(True)
                            if old_f.isAnchor():
                                merged.setAnchor(True)
                                merged.setAnchorHref(old_f.anchorHref())
                                merged.setForeground(QColor(self._surface_palette["link"]))
                                merged.setFontUnderline(True)
                            fc.setCharFormat(merged)
                        it += 1
                    if not has_frags:
                        c.select(QTextCursor.BlockUnderCursor)
                        c.setCharFormat(target)
                    if bool(blk.blockFormat().property(PROP_DROPCAP)):
                        self._apply_dropcap(blk)
                blk = blk.next()
            cur.endEditBlock()
            cur_kind = _block_kind(self.textCursor().block())
            self.setCurrentCharFormat(self._char_format_for(cur_kind))
        finally:
            self._guard = prev_guard

    # -- block kinds ----------------------------------------------------------
    def _char_format_for(self, kind: str) -> QTextCharFormat:
        pal = self._surface_palette
        f = QTextCharFormat()
        base = self._base_font()
        font = QFont(base)
        f.setForeground(QColor(pal["fg"]))
        if kind == "h2":
            font = QFont(self._heading_font_family or theme.FONT)
            font.setPointSizeF(20.0)
            font.setBold(True)
            f.setForeground(QColor(pal["heading"]))
        elif kind == "h3":
            font = QFont(self._heading_font_family or theme.FONT)
            font.setPointSizeF(15.5)
            font.setBold(True)
            f.setForeground(QColor(pal["heading"]))
        elif kind == "quote":
            font.setItalic(True)
            f.setForeground(QColor(pal["muted"]))
        elif kind == "pullquote":
            font.setPointSizeF(base.pointSizeF() * 1.25)
            font.setItalic(True)
            f.setForeground(QColor(pal["pullquote"]))
        elif kind == "raw":
            font = QFont("Consolas")
            font.setPointSizeF(11.0)
            f.setForeground(QColor(pal["fg"]))
        f.setFont(font)
        return f

    def _object_block_format(self) -> QTextBlockFormat:
        """Single-height block format for inline objects (images, mosaics, dividers, spacers).

        Never apply 150% ProportionalHeight to an object block: Qt scales the
        entire line box by 1.5x, adding half an image height of blank space below it.
        """
        b = QTextBlockFormat()
        b.setProperty(PROP_KIND, "para")
        b.setProperty(PROP_DROPCAP, False)
        b.setTopMargin(float(getattr(self, "_image_margin_top", 8.0)))
        b.setBottomMargin(float(getattr(self, "_image_margin_bottom", 8.0)))
        b.setLineHeight(0.0, QTextBlockFormat.SingleHeight.value)
        return b

    def _block_format_for(self, kind: str, dropcap: bool = False) -> QTextBlockFormat:
        b = QTextBlockFormat()
        b.setProperty(PROP_KIND, kind)
        b.setProperty(PROP_DROPCAP, bool(dropcap))
        b.setTopMargin(8)
        b.setBottomMargin(float(getattr(self, "_para_margin_bottom", 8.0)))
        b.setLineHeight(float(getattr(self, "_line_height_pct", 150.0)), QTextBlockFormat.ProportionalHeight.value)
        if kind in ("quote", "pullquote"):
            b.setLeftMargin(28)
            b.setRightMargin(28 if kind == "pullquote" else 0)
            b.setTopMargin(12)
            b.setBottomMargin(12)
        if kind == "h2":
            b.setTopMargin(18)
        if kind == "raw":
            b.setBackground(QColor("#f3f4f3"))
        return b

    # -- inline formatting (B / I / U / Link) ---------------------------------
    def toggle_bold(self):
        cur = self.textCursor()
        fmt = QTextCharFormat()
        is_bold = cur.charFormat().fontWeight() >= QFont.Bold
        fmt.setFontWeight(QFont.Normal if is_bold else QFont.Bold)
        cur.mergeCharFormat(fmt)
        self.mergeCurrentCharFormat(fmt)

    def toggle_italic(self):
        cur = self.textCursor()
        fmt = QTextCharFormat()
        fmt.setFontItalic(not cur.charFormat().fontItalic())
        cur.mergeCharFormat(fmt)
        self.mergeCurrentCharFormat(fmt)

    def toggle_underline(self):
        cur = self.textCursor()
        fmt = QTextCharFormat()
        fmt.setFontUnderline(not cur.charFormat().fontUnderline())
        cur.mergeCharFormat(fmt)
        self.mergeCurrentCharFormat(fmt)

    def insert_link(self):
        cur = self.textCursor()
        url, ok = QInputDialog.getText(self, "Insert link", "URL:")
        if not ok or not url.strip():
            return
        url = url.strip()
        label = cur.selectedText() or url
        fmt = QTextCharFormat()
        fmt.setAnchor(True)
        fmt.setAnchorHref(url)
        fmt.setForeground(QColor("#1e6610"))
        fmt.setFontUnderline(True)
        cur.insertText(label, fmt)

    def current_block_tag(self) -> str:
        block = self.textCursor().block()
        hit = self._object_at(block.position())
        if hit:
            kind = str(hit[0] or "").upper()
            return {"MOSAIC": "MOSAIC", "IMAGE": "IMG", "HR": "DIV", "SPACER": "SP"}.get(kind, kind)
        c = QTextCursor(block)
        if c.currentTable() is not None:
            return f"{c.currentTable().columns()}C"
        if block.textList():
            return "NUM" if block.textList().format().style() == QTextListFormat.ListDecimal else "BUL"
        if bool(block.blockFormat().property(PROP_DROPCAP)):
            return "DC"
        kind = _block_kind(block)
        return {"para": "P", "h2": "H", "h3": "SH", "quote": "Q", "pullquote": "PQ", "raw": "RAW"}.get(kind, "P")

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

    def insert_block_at_cursor(self, kind: str = "para", *, position: str = "after",
                               ordered_list=None, dropcap: bool = False, block_pos=None):
        """Insert a new block directly after (or before) the currently focused/selected block."""
        if kind not in BLOCK_KINDS:
            kind = "para"
        doc = self.document()
        sel_pos = (
            block_pos if block_pos is not None
            else (self._selected_block_pos if self._selected_block_pos is not None else self._selected_obj_pos)
        )
        if sel_pos is not None:
            block = doc.findBlock(int(sel_pos))
        else:
            block = self.textCursor().block()
        if not block.isValid():
            block = doc.lastBlock()
        self._selected_obj_pos = None
        self._selected_block_pos = None
        self._block_close_rect = None

        cur = QTextCursor(doc)
        cur.beginEditBlock()
        bf = self._block_format_for(kind, dropcap and kind == "para")
        cf = self._char_format_for(kind)
        if position == "before":
            cur.setPosition(block.position())
            cur.insertBlock(bf, cf)
            cur.movePosition(QTextCursor.PreviousBlock)
            cur.setBlockFormat(bf)
            cur.setBlockCharFormat(cf)
            cur.setCharFormat(cf)
        else:
            if not _has_object(block) and not block.text().strip() and block.textList() is None:
                cur.setPosition(block.position())
                cur.setBlockFormat(bf)
                cur.setBlockCharFormat(cf)
                cur.setCharFormat(cf)
            else:
                cur.setPosition(block.position() + max(0, block.length() - 1))
                cur.insertBlock(bf, cf)
                cur.setBlockFormat(bf)
                cur.setBlockCharFormat(cf)
                cur.setCharFormat(cf)
        if ordered_list is not None:
            fmt = QTextListFormat()
            fmt.setStyle(QTextListFormat.ListDecimal if ordered_list else QTextListFormat.ListDisc)
            fmt.setIndent(1)
            cur.createList(fmt)
        cur.endEditBlock()
        self.setTextCursor(cur)
        self.setCurrentCharFormat(cf)
        self.setFocus()
        self.viewport().update()

    # -- inline objects ---------------------------------------------------------
    def _insert_object(self, kind: str, data: dict, replace_at=None, *, position: str = "after"):
        """Objects live in their own paragraph so they export as their own block."""
        fmt = QTextCharFormat()
        fmt.setObjectType(OBJ_TYPE)
        fmt.setProperty(PROP_KIND, kind)
        fmt.setProperty(PROP_DATA, json.dumps(data or {}, ensure_ascii=False))
        doc = self.document()
        cur = self.textCursor()
        cur.beginEditBlock()
        if replace_at is not None:
            cur.setPosition(replace_at)
            cur.setPosition(replace_at + 1, QTextCursor.KeepAnchor)
            cur.insertText(OBJECT_CHAR, fmt)
            cur.setBlockFormat(self._object_block_format())
        else:
            sel_pos = self._selected_block_pos if self._selected_block_pos is not None else self._selected_obj_pos
            if sel_pos is not None:
                target_blk = doc.findBlock(sel_pos)
                if target_blk.isValid():
                    cur.setPosition(target_blk.position())
            self._selected_obj_pos = None
            self._selected_block_pos = None
            self._block_close_rect = None
            block = cur.block()
            if position == "before":
                cur.setPosition(block.position())
                cur.insertBlock(self._object_block_format(), self._char_format_for("para"))
                cur.movePosition(QTextCursor.PreviousBlock)
                cur.setBlockFormat(self._object_block_format())
                cur.insertText(OBJECT_CHAR, fmt)
            else:
                if block.text().strip() or _has_object(block):
                    cur.movePosition(QTextCursor.EndOfBlock)
                    cur.insertBlock(self._object_block_format(), self._char_format_for("para"))
                else:
                    if block.textList():
                        block.textList().remove(block)
                    cur.setBlockFormat(self._object_block_format())
                cur.insertText(OBJECT_CHAR, fmt)
                if not cur.block().next().isValid():
                    cur.insertBlock(self._block_format_for("para"), self._char_format_for("para"))
                    cur.setCharFormat(self._char_format_for("para"))
                else:
                    cur.movePosition(QTextCursor.NextBlock)
                    cur.movePosition(QTextCursor.StartOfBlock)
        cur.endEditBlock()
        self.setTextCursor(cur)
        self.setCurrentCharFormat(self._char_format_for(_block_kind(cur.block())))
        self.setFocus()

    def insert_mosaic(self, order, layout: str, *, position: str = "after", weights=None, mirrors=()):
        payload = {"order": list(order), "layout": str(layout)}
        if weights:
            payload["weights"] = {str(k): int(v) for k, v in weights.items() if int(v or 0) != 0}
        if mirrors:
            payload["mirrors"] = list(mirrors)
        self._insert_object("mosaic", payload, position=position)

    def replace_mosaic(self, order, layout: str, weights=None, mirrors=()):
        pos = self._pending_obj_pos
        self._pending_obj_pos = None
        if pos is None:
            return self.insert_mosaic(order, layout, weights=weights, mirrors=mirrors)
        payload = {"order": list(order), "layout": str(layout)}
        if weights:
            payload["weights"] = {str(k): int(v) for k, v in weights.items() if int(v or 0) != 0}
        if mirrors:
            payload["mirrors"] = list(mirrors)
        self._insert_object("mosaic", payload, replace_at=pos)

    def insert_image(self, img_id: str, size: str = "full", align: str = "center",
                     *, width_ratio=None, alt="", caption="", show_caption: bool = False,
                     position: str = "after"):
        data = {"img_id": str(img_id).strip(), "size": size, "align": align}
        if caption or show_caption:
            data["caption"] = str(caption or "")
            data["show_caption"] = bool(show_caption)
        if str(img_id).startswith("asset:"):
            uid = str(img_id)[6:]
            default_cap = str(caption or self.default_captions.get(uid, "") or "")
            data.update({"asset_uuid": uid,
                         "width_ratio": float(width_ratio or 1.0),
                         "placement": "block" if align == "center" else f"wrap-{align}",
                         "alt": str(alt or ""),
                         "caption": default_cap,
                         "show_caption": bool(show_caption)})
        self._insert_object("image", data, position=position)

    def set_image_caption(self, pos: int, *, show_caption: bool = None, caption: str = None) -> bool:
        """Toggle or update caption visibility and text on the placed image at pos."""
        hit = self._object_at(pos)
        if not hit or hit[0] != "image":
            return False
        data = dict(hit[1])
        uid = str(data.get("asset_uuid") or "")
        img_id = str(data.get("img_id") or "")
        if caption is not None:
            data["caption"] = str(caption).strip()
        elif not str(data.get("caption") or "").strip():
            fallback = (
                self.default_captions.get(uid)
                or self.default_captions.get(img_id)
                or ""
            )
            if fallback:
                data["caption"] = str(fallback).strip()
        if show_caption is not None:
            data["show_caption"] = bool(show_caption)
        data.pop("raw", None)
        self._insert_object("image", data, replace_at=hit[2])
        self.document().markContentsDirty(0, self.document().characterCount())
        self._sync_canvas_height()
        self.viewport().update()
        return True

    # -- local photograph input -----------------------------------------------
    def canInsertFromMimeData(self, source):
        from .photo_tray import TRAY_PHOTO_MIME
        return bool(source.hasFormat(TRAY_PHOTO_MIME) or source.hasImage()
                    or any(url.isLocalFile() for url in source.urls())) \
            or super().canInsertFromMimeData(source)

    def insertFromMimeData(self, source):
        from .photo_tray import TRAY_PHOTO_MIME
        if source.hasFormat(TRAY_PHOTO_MIME):
            import json as _json
            try:
                data = _json.loads(bytes(source.data(TRAY_PHOTO_MIME)).decode("utf-8"))
            except Exception:
                data = {}
            asset_uuid = str(data.get("asset_uuid") or "")
            if asset_uuid:
                self.trayPhotoDropped.emit(asset_uuid)
            return
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

    def _unscale_point(self, point) -> QPointF:
        scale = float(getattr(self, "_zoom_scale", 1.0) or 1.0)
        x = point.x() if hasattr(point, "x") else 0.0
        y = point.y() if hasattr(point, "y") else 0.0
        return QPointF(float(x) / scale, float(y) / scale)

    def _unscale_mouse_event(self, event: QMouseEvent) -> QMouseEvent:
        scale = float(getattr(self, "_zoom_scale", 1.0) or 1.0)
        if abs(scale - 1.0) < 1e-4:
            return event
        local_pos = self._unscale_point(event.position())
        return QMouseEvent(
            event.type(),
            local_pos,
            event.scenePosition(),
            event.globalPosition(),
            event.button(),
            event.buttons(),
            event.modifiers(),
        )

    def _object_under_mouse(self, point):
        pt = self._unscale_point(point)
        pos = self.document().documentLayout().hitTest(pt, Qt.FuzzyHit)
        return self._object_at(pos) if pos >= 0 else None

    def _ensure_gutter_margin(self):
        rf = self.document().rootFrame().frameFormat()
        if rf.leftMargin() < 20:
            rf.setLeftMargin(20)
            self.document().rootFrame().setFrameFormat(rf)

    def select_block_at(self, pos: int):
        """Select any BIGGIE block (inline object, para, heading, quote, list, or columns)."""
        hit = self._object_at(pos)
        if hit:
            self._selected_obj_pos = hit[2]
            self._selected_block_pos = hit[2]
        else:
            blk = self.document().findBlock(pos)
            if not blk.isValid():
                self._selected_obj_pos = None
                self._selected_block_pos = None
                self._block_close_rect = None
                self.viewport().update()
                return
            tbl = QTextCursor(blk).currentTable()
            self._selected_obj_pos = None
            self._selected_block_pos = tbl.firstCursorPosition().position() if tbl else blk.position()
        self.viewport().update()

    def _block_selection_rect(self, pos: int):
        """Return viewport QRect for the selected block/table/object, or None."""
        doc = self.document()
        layout = doc.documentLayout()
        scale = float(getattr(self, "_zoom_scale", 1.0) or 1.0)
        off_x = -self.horizontalScrollBar().value()
        off_y = -self.verticalScrollBar().value()
        blk = doc.findBlock(pos)
        if not blk.isValid():
            return None
        tbl = QTextCursor(blk).currentTable()
        if tbl is not None:
            rf = layout.frameBoundingRect(tbl)
            r = QRect(
                int(round(rf.left() * scale)),
                int(round(rf.top() * scale)),
                int(round(rf.width() * scale)),
                int(round(rf.height() * scale)),
            )
            r.translate(off_x, off_y)
            return r
        rf = layout.blockBoundingRect(blk)
        r = QRect(
            int(round(rf.left() * scale)),
            int(round(rf.top() * scale)),
            int(round(rf.width() * scale)),
            int(round(rf.height() * scale)),
        )
        r.translate(off_x, off_y)
        if r.height() < 22:
            r.setHeight(22)
        return r

    def paintEvent(self, event):
        self._inline_mosaic_hits.clear()
        self._inline_mosaic_sections.clear()
        scale = float(getattr(self, "_zoom_scale", 1.0) or 1.0)
        doc = self.document()
        layout = doc.documentLayout()
        if abs(scale - 1.0) < 1e-4:
            super().paintEvent(event)
            painter = QPainter(self.viewport())
            painter.setRenderHint(QPainter.Antialiasing, True)
        else:
            painter = QPainter(self.viewport())
            painter.setRenderHint(QPainter.Antialiasing, True)
            painter.setRenderHint(QPainter.TextAntialiasing, True)
            painter.setRenderHint(QPainter.SmoothPixmapTransform, True)
            bg_col = QColor(self._surface_palette.get("bg", "#ffffff"))
            painter.fillRect(self.viewport().rect(), bg_col)
            painter.save()
            painter.scale(scale, scale)
            plain = self.toPlainText()
            is_empty = (doc.blockCount() <= 1 and not plain.strip() and OBJECT_CHAR not in plain)
            if is_empty and self.placeholderText():
                painter.setFont(self._base_font())
                painter.setPen(QColor(self._surface_palette.get("placeholder", "#666666")))
                ph_w = max(120, int(self._preview_width) - 28)
                painter.drawText(
                    QRectF(24, 12, ph_w, 200),
                    Qt.AlignLeft | Qt.AlignTop | Qt.TextWordWrap,
                    self.placeholderText(),
                )
            ctx = QAbstractTextDocumentLayout.PaintContext()
            ctx.palette = self.palette()
            cur = self.textCursor()
            if cur.hasSelection():
                sel = QAbstractTextDocumentLayout.Selection()
                sel.cursor = cur
                sel.format.setBackground(QColor(self._surface_palette.get("selection_bg", "#c8f5be")))
                sel.format.setForeground(QColor(self._surface_palette.get("selection_fg", "#111111")))
                ctx.selections = [sel]
            ctx.cursorPosition = cur.position() if self.hasFocus() else -1
            layout.draw(painter, ctx)
            painter.restore()

        off_y = -self.verticalScrollBar().value()
        grips = {}
        seen_tables = set()
        blk = doc.begin()
        while blk.isValid():
            tbl = QTextCursor(blk).currentTable()
            if tbl is not None:
                tpos = tbl.firstCursorPosition().position()
                if tpos not in seen_tables:
                    seen_tables.add(tpos)
                    rf = layout.frameBoundingRect(tbl)
                    top_px = int(round(rf.top() * scale)) + off_y
                    h_px = int(round(rf.height() * scale))
                    grip = QRect(2, top_px + 2, 14, max(18, min(24, h_px)))
                    grips[tpos] = grip
            else:
                rf = layout.blockBoundingRect(blk)
                top_px = int(round(rf.top() * scale)) + off_y
                h_px = int(round(rf.height() * scale))
                grip = QRect(2, top_px + 2, 14, max(18, min(24, h_px)))
                grips[blk.position()] = grip
            blk = blk.next()
        self._gutter_grip_rects = grips

        for bpos, grip in grips.items():
            is_sel = (bpos == self._selected_block_pos)
            painter.setPen(QColor(theme.ACCENT if is_sel else theme.DIM))
            painter.drawText(grip, Qt.AlignCenter, "⋮")

        self._block_close_rect = None
        if self._selected_block_pos is not None:
            sel_r = self._block_selection_rect(self._selected_block_pos)
            if sel_r is not None:
                painter.setPen(QPen(QColor(theme.ACCENT), 2))
                painter.setBrush(Qt.NoBrush)
                painter.drawRect(sel_r.adjusted(1, 1, -1, -1))
                close_r = QRect(max(sel_r.left(), sel_r.right() - 24), sel_r.top() + 2, 20, 20)
                painter.fillRect(close_r, QColor("#181a18"))
                painter.setPen(QPen(QColor("#ff4f4f"), 1))
                painter.drawRect(close_r)
                painter.setPen(QColor("#ffffff"))
                painter.drawText(close_r, Qt.AlignCenter, "✕")
                self._block_close_rect = close_r
        painter.end()

    def mousePressEvent(self, event):
        if event.button() == Qt.LeftButton:
            pt = event.position().toPoint()
            document_point = self._unscale_point(event.position()).toPoint()
            for pos, (_tiles, buttons) in self._inline_mosaic_hits.items():
                for rect, identities in buttons:
                    if rect.contains(document_point):
                        self._break_inline_cluster(pos, identities)
                        return
            if self._block_close_rect is not None and self._block_close_rect.contains(pt):
                if self._selected_block_pos is not None:
                    self._remove_block_at(self._selected_block_pos)
                    return
            for obj_pos, rect in list(self._obj_close_rects.items()):
                if rect.contains(pt):
                    self._remove_object(obj_pos)
                    return
            for bpos, grip in list(self._gutter_grip_rects.items()):
                if grip.contains(pt):
                    self.select_block_at(bpos)
                    return
            hit = self._object_under_mouse(event.position())
            if hit:
                self.select_block_at(hit[2])
            elif self._selected_block_pos is not None or self._selected_obj_pos is not None:
                self._selected_obj_pos = None
                self._selected_block_pos = None
                self._block_close_rect = None
                self.viewport().update()
        super().mousePressEvent(self._unscale_mouse_event(event))

    def mouseMoveEvent(self, event):
        super().mouseMoveEvent(self._unscale_mouse_event(event))

    def mouseReleaseEvent(self, event):
        super().mouseReleaseEvent(self._unscale_mouse_event(event))

    def _remove_block_at(self, pos: int):
        """Delete any selected BIGGIE block (inline object, columns table, list, para, heading, quote)."""
        hit = self._object_at(pos)
        if hit:
            self._remove_object(hit[2])
            return
        doc = self.document()
        blk = doc.findBlock(pos)
        if not blk.isValid():
            self._selected_block_pos = None
            self._block_close_rect = None
            return
        self._selected_obj_pos = None
        self._selected_block_pos = None
        self._block_close_rect = None
        c = self.textCursor()
        c.beginEditBlock()
        tbl = QTextCursor(blk).currentTable()
        if tbl is not None:
            tbl.removeRows(0, tbl.rows())
        else:
            if blk.textList():
                blk.textList().remove(blk)
            if blk.next().isValid():
                c.setPosition(blk.position())
                c.setPosition(blk.next().position(), QTextCursor.KeepAnchor)
                c.removeSelectedText()
            elif blk.previous().isValid():
                prev = blk.previous()
                c.setPosition(prev.position() + prev.length() - 1)
                c.setPosition(blk.position() + blk.length() - 1, QTextCursor.KeepAnchor)
                c.removeSelectedText()
            else:
                c.setPosition(blk.position())
                c.setPosition(blk.position() + blk.length() - 1, QTextCursor.KeepAnchor)
                c.removeSelectedText()
                c.setBlockFormat(self._block_format_for("para"))
                c.setBlockCharFormat(self._char_format_for("para"))
        c.endEditBlock()
        self.setTextCursor(c)
        self.viewport().update()

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
        super().mouseDoubleClickEvent(self._unscale_mouse_event(event))

    def contextMenuEvent(self, event):
        hit = self._object_under_mouse(event.pos())
        menu = self.createStandardContextMenu()
        if hit:
            kind, data, pos = hit
            menu.addSeparator()
            if kind == "mosaic":
                point = self._unscale_point(event.pos()).toPoint()
                tiles, buttons = self._inline_mosaic_hits.get(pos, ({}, []))
                picked = next(((number, ident) for number, (ident, rect) in tiles.items()
                               if rect.contains(point)), None)
                if picked:
                    number, identity = picked
                    order = list(data.get('order') or range(1, len(self.bucket) + 1))
                    index = next((i for i, item in enumerate(order) if str(item) == str(identity)), -1)
                    blocks = self._inline_mosaic_sections.get(pos, ()) if len(tiles) == len(order) and index == number - 1 else ()
                    cluster = next((group for _button, group in buttons if identity in group), ())
                    add_mosaic_photo_actions(
                        menu, index, len(order), blocks, cluster,
                        lambda change, step: self._dispatch_inline_mosaic(hit, identity, change, step, blocks, cluster),
                    )
                    menu.addSeparator()
                menu.addAction("Change this mosaic…", lambda: self._edit_mosaic(hit))
            elif kind == "image":
                menu.addAction("Change this image…", lambda: self._edit_image(hit))
                show_cap_act = menu.addAction("Show caption")
                show_cap_act.setCheckable(True)
                show_cap_act.setChecked(bool(data.get("show_caption")))
                show_cap_act.triggered.connect(
                    lambda checked, p=pos: self.set_image_caption(p, show_caption=checked))
                menu.addAction("Edit caption…", lambda p=pos: self._prompt_image_caption(p))
            elif kind == "spacer":
                menu.addAction("Change the gap…", lambda: self._edit_spacer(hit))
            if kind in ("image", "mosaic"):
                earlier = menu.addAction("Move earlier", lambda: self._move_object(pos, -1))
                later = menu.addAction("Move later", lambda: self._move_object(pos, 1))
                earlier.setEnabled(self._can_move_object(pos, -1))
                later.setEnabled(self._can_move_object(pos, 1))
            if kind == "image":
                menu.addAction("Make narrower", lambda: self._nudge_image(pos, -.17))
                menu.addAction("Make wider", lambda: self._nudge_image(pos, .17))
            menu.addAction(f"Remove this {KIND_OBJ.get(kind, kind)}", lambda: self._remove_object(pos))
        menu.exec(event.globalPos())

    def _dispatch_inline_mosaic(self, hit, identity, change, step, blocks, cluster):
        _kind, original, pos = hit
        if change == "selection":
            self._edit_mosaic(hit)
        elif change == "break":
            self._break_inline_cluster(pos, cluster)
        elif change in ("mirror", "reverse"):
            data = dict(original)
            order = list(data.get('order') or range(1, len(self.bucket) + 1))
            index = next(i for i, item in enumerate(order) if str(item) == str(identity))
            toggled = {f'{block["start"]}:{block["count"]}' for block in blocks
                       if block["count"] > 1 and
                       (change == "reverse" or block["start"] <= index < block["start"] + block["count"])}
            if toggled:
                data.update(order=order, mirrors=sorted(set(data.get("mirrors") or ()) ^ toggled))
                self._insert_object("mosaic", data, replace_at=pos)
        else:
            self._edit_inline_photo(pos, identity, change, step)

    def _edit_inline_photo(self, pos, identity, change, step):
        hit = self._object_at(pos)
        if not hit or hit[0] != 'mosaic':
            return False
        data = dict(hit[1])
        order = list(data.get('order') or range(1, len(self.bucket) + 1))
        index = next((i for i, item in enumerate(order) if str(item) == str(identity)), -1)
        if index < 0 or len(order) < 2:
            return False
        _paths, images = _mosaic_images(self, data)
        weights = {str(image['identity']): image['weight'] for image in images if image['weight']}
        if change == 'move':
            target = index + step
            if not 0 <= target < len(order):
                return False
            if data.get("mirrors"):
                indices = list(range(len(order)))
                indices[index], indices[target] = indices[target], indices[index]
                data["mirrors"] = biggie.remap_mosaic_mirrors(data["mirrors"], indices)
            order[index], order[target] = order[target], order[index]
        else:
            old = weights.get(str(identity), 0)
            new = max(-1, min(2, old + step))
            if new == old:
                return False
            if new:
                weights[str(identity)] = new
            else:
                weights.pop(str(identity), None)
        data.update(order=order, weights=weights)
        self._insert_object('mosaic', data, replace_at=hit[2])
        return True

    def _break_inline_cluster(self, pos, identities):
        hit = self._object_at(pos)
        if not hit or hit[0] != 'mosaic':
            return
        data = dict(hit[1])
        _paths, images = _mosaic_images(self, data)
        weights = {str(image['identity']): image['weight'] for image in images if image['weight']}
        changes = break_cluster_weights(
            (str(identity) for identity in identities), lambda identity: weights.get(identity, 0))
        if changes:
            weights.update(changes)
            data['weights'] = {identity: weight for identity, weight in weights.items() if weight}
            self._insert_object('mosaic', data, replace_at=hit[2])

    def _prompt_image_caption(self, pos: int):
        hit = self._object_at(pos)
        if not hit or hit[0] != "image":
            return
        data = hit[1]
        uid = str(data.get("asset_uuid") or "")
        img_id = str(data.get("img_id") or "")
        cur_cap = str(data.get("caption") or "").strip()
        if not cur_cap:
            cur_cap = str(self.default_captions.get(uid) or self.default_captions.get(img_id) or "").strip()
        text, ok = QInputDialog.getText(self, "Image caption", "Caption:", text=cur_cap)
        if ok:
            self.set_image_caption(pos, show_caption=bool(text.strip()), caption=text.strip())

    def _remove_object(self, pos: int):
        self._selected_obj_pos = None
        self._selected_block_pos = None
        self._block_close_rect = None
        self._obj_close_rects.pop(pos, None)
        c = self.textCursor()
        c.beginEditBlock()
        c.setPosition(pos)
        c.setPosition(pos + 1, QTextCursor.KeepAnchor)
        c.removeSelectedText()
        if not c.block().text().strip() and c.block().previous().isValid():
            c.deletePreviousChar()      # drop the now-empty paragraph
        c.endEditBlock()
        self.setTextCursor(c)
        self.viewport().update()

    def _object_move(self, pos, delta):
        """Return the top-level model and destination for one object move."""
        hit = self._object_at(pos)
        if not hit:
            return None
        block = self.document().findBlock(pos)
        if not block.isValid() or QTextCursor(block).currentTable() is not None:
            return None
        model = {"type": hit[0], **hit[1]}
        occurrence = 0
        scan = self.document().begin()
        while scan.isValid() and scan.position() < block.position():
            if QTextCursor(scan).currentTable() is None:
                previous = self._object_at(scan.position())
                if previous and {"type": previous[0], **previous[1]} == model:
                    occurrence += 1
            scan = scan.next()
        blocks = self.to_blocks()
        matches = [index for index, item in enumerate(blocks) if item == model]
        if occurrence >= len(matches):
            return None
        source = matches[occurrence]
        destination = source + (-1 if int(delta) < 0 else 1)
        if destination < 0 or destination >= len(blocks):
            return None
        return blocks, source, destination

    def _can_move_object(self, pos, delta) -> bool:
        return self._object_move(pos, delta) is not None

    def _move_object(self, pos, delta):
        move = self._object_move(pos, delta)
        if move is None:
            return
        blocks, source, destination = move
        blocks[source], blocks[destination] = blocks[destination], blocks[source]
        self._replace_blocks_undoable(blocks)

    def _replace_blocks_undoable(self, blocks):
        """Replace the top-level model as one undoable document operation."""
        self._guard = True
        try:
            doc = self.document()
            cur = QTextCursor(doc)
            cur.beginEditBlock()
            cur.select(QTextCursor.Document)
            cur.removeSelectedText()
            cur.setPosition(0)
            first = True
            for model in blocks:
                if not first:
                    cur.movePosition(QTextCursor.End)
                    cur.insertBlock(
                        self._block_format_for("para"), self._char_format_for("para"),
                    )
                first = False
                self._insert_block_model(cur, biggie._migrate_block(model), nested=False)
            block = doc.begin()
            while block.isValid():
                if bool(block.blockFormat().property(PROP_DROPCAP)):
                    self._apply_dropcap(block)
                block = block.next()
            cur.endEditBlock()
            cur.movePosition(QTextCursor.End)
            self.setTextCursor(cur)
            self._sync_canvas_height()
        finally:
            self._guard = False

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
        """Lock the document column to the target preview width (e.g. Desktop 760 / Phone 360)."""
        self._preview_width = int(width or 760)
        self.document().setTextWidth(float(width) if width else -1)
        self._sync_canvas_height()
        self.viewport().update()

    def set_zoom_scale(self, scale: float):
        """Scale the rendered page as one unit without changing document textWidth or line breaks."""
        self._zoom_scale = max(0.5, min(2.0, float(scale or 1.0)))
        self._sync_canvas_height()
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
        key = event.key()
        sel_pos = self._selected_block_pos if self._selected_block_pos is not None else self._selected_obj_pos
        if sel_pos is not None:
            if key in (Qt.Key_Delete, Qt.Key_Backspace):
                self._remove_block_at(sel_pos)
                return
            if key not in (Qt.Key_Shift, Qt.Key_Control, Qt.Key_Alt, Qt.Key_Meta):
                self._selected_obj_pos = None
                self._selected_block_pos = None
                self._block_close_rect = None
                self.viewport().update()
        cur = self.textCursor()
        block = cur.block()
        if key in (Qt.Key_Return, Qt.Key_Enter) and not (event.modifiers() & Qt.ShiftModifier):
            kind = _block_kind(block)
            # A paragraph on an object line goes BELOW the object, never inside it.
            if _has_object(block):
                cur.movePosition(QTextCursor.EndOfBlock)
                cur.insertBlock(self._block_format_for("para"), self._char_format_for("para"))
                cur.setCharFormat(self._char_format_for("para"))
                self.setTextCursor(cur)
                self.setCurrentCharFormat(self._char_format_for("para"))
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
                self.setCurrentCharFormat(self._char_format_for("para"))
                return
        if key == Qt.Key_Backspace and not cur.hasSelection():
            if _has_object(block):
                # SS-1492: First Backspace on an image/object selects it; second removes it.
                self.select_block_at(block.position())
                return
            if cur.atBlockStart():
                if _block_kind(block) != "para" and not block.textList():
                    self.set_kind("para")           # first Backspace demotes, second joins
                    return
                prev = block.previous()
                if prev.isValid() and _has_object(prev):
                    # SS-1492: First Backspace at the start of a block after an image/object
                    # selects (highlights) the image instead of merging onto or deleting it.
                    self.select_block_at(prev.position())
                    return
        if key == Qt.Key_Delete and not cur.hasSelection():
            if _has_object(block):
                self.select_block_at(block.position())
                return
            if cur.atBlockEnd():
                nxt = block.next()
                if nxt.isValid() and _has_object(nxt):
                    self.select_block_at(nxt.position())
                    return
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
            self._selected_obj_pos = None
            self._selected_block_pos = None
            self._block_close_rect = None
            self._ensure_gutter_margin()
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
            if first:
                self._init_empty_doc_format()
            cur.endEditBlock()
            # drop caps need their big letter painted
            block = doc.begin()
            while block.isValid():
                if bool(block.blockFormat().property(PROP_DROPCAP)):
                    self._apply_dropcap(block)
                block = block.next()
            cur.movePosition(QTextCursor.End)      # resume where the writing stopped
            self.setTextCursor(cur)
            self._sync_canvas_height()
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
            cur.setBlockFormat(self._object_block_format())
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
                        if data.get("weights"):
                            blk["weights"] = dict(data["weights"])
                        if data.get("mirrors"):
                            blk["mirrors"] = list(data["mirrors"])
                    out.append(blk)
                elif okind == "image":
                    image = {"type": "image", "img_id": str(data.get("img_id", ""))}
                    for key in ("gallery", "size", "align", "width", "decorative", "raw",
                                "asset_uuid", "placement", "width_ratio", "crop",
                                "alt", "caption", "show_caption", "link"):
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
        self.show_caption = QCheckBox("Show caption")
        self.show_caption.setChecked(bool(data.get("show_caption", False)))
        col.addWidget(self.show_caption)
        col.addWidget(QLabel("Caption"))
        default_cap = str(data.get("caption", "") or "")
        if not default_cap and hasattr(parent, "default_captions"):
            default_cap = str(parent.default_captions.get(self._asset_uuid, "") or "")
        self.caption = QLineEdit(default_cap)
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
                "alt": self.alt.text().strip(),
                "caption": self.caption.text().strip(),
                "show_caption": bool(self.show_caption.isChecked()),
                "link": self.link.text().strip()}


# ── toolbar ──────────────────────────────────────────────────────────────────
class CanvasBar(QWidget):
    """What you can make: paragraph kinds, lists, drop cap, and the drawn things."""

    def __init__(self, canvas: BiggieCanvas, parent=None):
        super().__init__(parent)
        self.canvas = canvas
        column = QVBoxLayout(self)
        column.setContentsMargins(0, 0, 0, 0)
        column.setSpacing(4)
        writing = QHBoxLayout()
        media = QHBoxLayout()
        for row in (writing, media):
            row.setContentsMargins(0, 0, 0, 0)
            row.setSpacing(4)
        self._label(writing, "WRITE")
        self._btn(writing, "Paragraph", "Plain paragraph", lambda: canvas.set_kind("para"))
        self._btn(writing, "Heading", "Large heading", lambda: canvas.set_kind("h2"))
        self._btn(writing, "Subhead", "Smaller heading", lambda: canvas.set_kind("h3"))
        self._btn(writing, "Quote", "Quoted passage", lambda: canvas.set_kind("quote"))
        self._btn(writing, "Pull quote", "Words lifted out large", lambda: canvas.set_kind("pullquote"))
        self._btn(writing, "Drop cap", "A big first letter on this paragraph", canvas.toggle_dropcap)
        self._btn(writing, "Bullets", "Bullet list", lambda: canvas.set_list(False))
        self._btn(writing, "Numbers", "Numbered list", lambda: canvas.set_list(True))
        writing.addStretch(1)

        self._label(media, "PLACE")
        self._btn(media, "Image", "Pick one image from this site's Media Gallery", canvas._edit_image)
        self._btn(media, "2 columns", "Two columns side by side", lambda: canvas.insert_columns(2))
        self._btn(media, "3 columns", "Three columns", lambda: canvas.insert_columns(3))
        self._btn(media, "Divider", "A divider line", canvas.insert_hr)
        self._btn(media, "Space", "A vertical gap", canvas._edit_spacer)
        self._btn(media, "Raw", "Shortcodes or HTML kept exactly as typed", lambda: canvas.set_kind("raw"))
        self._sep(media)
        self._btn(media, "Desktop", "Preview at desktop essay width", lambda: canvas.set_preview_width(760))
        self._btn(media, "Phone", "Preview responsive wrapping at phone width", lambda: canvas.set_preview_width(360))
        media.addStretch(1)
        column.addLayout(writing)
        column.addLayout(media)
        self._host_row = media

    def _label(self, row, text):
        label = QLabel(text)
        label.setObjectName("ChromeLabel")
        label.setMinimumWidth(48)
        row.addWidget(label)

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
        row = self._host_row
        b = QPushButton(label)
        b.setObjectName("ScBtn")
        b.setToolTip(tip)
        b.setFocusPolicy(Qt.NoFocus)
        b.clicked.connect(callback)
        row.insertWidget(max(1, row.count() - 3), b)
        return b

# ===== SNAPSMACK EOF =====
