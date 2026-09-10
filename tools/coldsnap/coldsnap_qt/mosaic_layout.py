"""COLD SNAP Qt — mosaic tile geometry, shared by the mosaic dialog preview
and the BIGGIE canvas so both draw the same picture.

Pure geometry: a width, a height, a count and a layout name in; one QRect per
tile out (the same order the photos were chosen in).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from PySide6.QtCore import QRect

# Every layout name the site's smackpress/mosaics endpoint accepts.
LAYOUTS = ("asymmetric", "columns", "rows", "square",
           "one-left", "one-right", "three-across", "one-top")


def tile_rects(width, height, count, layout_name, gap=5):
    if count <= 0:
        return []
    w, h = max(1, width), max(1, height)
    if count == 1:
        return [QRect(0, 0, w, h)]
    if count == 2:
        if layout_name == "rows":
            hh = (h - gap) // 2
            return [QRect(0, 0, w, hh), QRect(0, hh + gap, w, h - hh - gap)]
        ww = (w - gap) // 2
        return [QRect(0, 0, ww, h), QRect(ww + gap, 0, w - ww - gap, h)]
    if count == 3:
        if layout_name == "three-across":
            ww = (w - gap * 2) // 3
            return [QRect(i * (ww + gap), 0,
                          ww if i < 2 else w - i * (ww + gap), h)
                    for i in range(3)]
        if layout_name == "one-top":
            hh, ww = (h - gap) // 2, (w - gap) // 2
            return [QRect(0, 0, w, hh), QRect(0, hh + gap, ww, h - hh - gap),
                    QRect(ww + gap, hh + gap, w - ww - gap, h - hh - gap)]
        hero = (w - gap) * 2 // 3
        small, hh = w - hero - gap, (h - gap) // 2
        if layout_name == "one-right":
            return [QRect(small + gap, 0, hero, h), QRect(0, 0, small, hh),
                    QRect(0, hh + gap, small, h - hh - gap)]
        return [QRect(0, 0, hero, h), QRect(hero + gap, 0, small, hh),
                QRect(hero + gap, hh + gap, small, h - hh - gap)]
    cols = count if layout_name == "columns" else min(3, count)
    rows = 1 if layout_name == "columns" else (count + cols - 1) // cols
    cw, ch = (w - gap * (cols - 1)) // cols, (h - gap * (rows - 1)) // rows
    return [QRect((i % cols) * (cw + gap), (i // cols) * (ch + gap), cw, ch)
            for i in range(count)]


def natural_height(width, count, layout_name):
    """A sensible canvas height for a mosaic of this shape at this width."""
    w = max(1, width)
    if count <= 1:
        return int(w * 0.62)
    if layout_name == "rows":
        return int(w * 0.5 * min(count, 4))
    if layout_name in ("columns", "three-across"):
        return int(w * 0.42)
    if layout_name == "one-top":
        return int(w * 0.8)
    if count <= 3:
        return int(w * 0.6)
    rows = (count + 2) // 3
    return int(w * 0.3 * rows)

# ===== SNAPSMACK EOF =====
