"""Tests for Build mosaic WYSIWYG preview (#6435):
- MosaicPreview uses the exact same site/BIGGIE layout geometry (mosaic_cells /
  mosaic_site_layout.compute_layout) at Desktop (760px) and Phone (360px).
- Build mosaic dialog is resizable and maximisable (Qt.WindowMaximizeButtonHint).
"""

import os
import sys

from PIL import Image
from PySide6.QtCore import Qt, QTimer
from PySide6.QtWidgets import QApplication, QDialog

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

_SHARED = os.path.join(os.path.dirname(_ROOT), "_shared")
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

from coldsnap_qt import theme
from coldsnap_qt.canvas import mosaic_cells
from coldsnap_qt.mode_take import MosaicPreview, TakeMode
from coldsnap_qt.mosaic_site_layout import compute_layout


def _app():
    return QApplication.instance() or QApplication([])


def _make_photo(path, size, color=(40, 110, 180)):
    im = Image.new("RGB", size, color)
    im.save(str(path), "JPEG")
    return str(path)


def test_mosaic_preview_matches_biggie_and_site_geometry(tmp_path):
    _app()
    sizes = [
        (900, 600),   # landscape 3:2
        (600, 900),   # portrait 2:3
        (800, 800),   # square 1:1
        (1200, 800),  # landscape 3:2
        (600, 800),   # portrait 3:4
        (900, 600),   # landscape 3:2
    ]
    paths = [_make_photo(tmp_path / f"p{i}.jpg", sz) for i, sz in enumerate(sizes)]
    tiles = [(i, p) for i, p in enumerate(paths)]

    class _FakeCanvas:
        bucket = paths
        assets = {}

    for col_width in (760, 360):
        for layout_name in ("asymmetric", "columns", "rows", "square"):
            preview = MosaicPreview(content_width=col_width)
            preview.set_mosaic(tiles, layout_name, gap=4)
            prev_out = preview.computed_cells()

            data = {"order": list(range(1, len(paths) + 1)), "layout": layout_name, "gap": 4}
            _, biggie_out = mosaic_cells(_FakeCanvas(), data, col_width)

            assert len(prev_out["items"]) == 6
            assert len(biggie_out["items"]) == 6
            assert abs(prev_out["height"] - biggie_out["height"]) < 1e-6

            for pt, bt in zip(prev_out["items"], biggie_out["items"]):
                assert abs(pt["x"] - bt["x"]) < 1e-6
                assert abs(pt["y"] - bt["y"]) < 1e-6
                assert abs(pt["width"] - bt["width"]) < 1e-6
                assert abs(pt["height"] - bt["height"]) < 1e-6

            # Also verify against mosaic_site_layout.compute_layout directly
            images = [{"width": w, "height": h, "src": p} for (w, h), p in zip(sizes, paths)]
            ref_w = 1200.0 if (col_width > 520 and len(images) >= 5) else float(col_width)
            scale = float(col_width) / ref_w
            site_out = compute_layout(images, ref_w, 4, "natural", "" if layout_name == "asymmetric" else layout_name)
            for pt, st in zip(prev_out["items"], site_out["items"]):
                assert abs(pt["x"] - st["x"] * scale) < 1e-4
                assert abs(pt["y"] - st["y"] * scale) < 1e-4
                assert abs(pt["width"] - st["width"] * scale) < 1e-4
                assert abs(pt["height"] - st["height"] * scale) < 1e-4


def _is_two_by_two(items):
    """Detect a 2-col x 2-row grid (even within 10% tolerance)."""
    if len(items) != 4:
        return False
    ys = sorted(set(round(t["y"]) for t in items))
    xs = sorted(set(round(t["x"]) for t in items))
    # Cluster into <= 2 rows and <= 2 cols
    row_ys = []
    for y in ys:
        if not row_ys or abs(y - row_ys[-1]) > 10:
            row_ys.append(y)
    col_xs = []
    for x in xs:
        if not col_xs or abs(x - col_xs[-1]) > 10:
            col_xs.append(x)
    if len(row_ys) != 2 or len(col_xs) != 2:
        return False
    ws = [t["width"] for t in items]
    hs = [t["height"] for t in items]
    return (max(ws) - min(ws)) <= 0.1 * max(ws) and (max(hs) - min(hs)) <= 0.1 * max(hs)


def test_four_photos_never_produce_two_by_two_grid(tmp_path):
    _app()
    shape_sets = {
        "landscape": [(900, 600)] * 4,
        "portrait": [(600, 900)] * 4,
        "square": [(800, 800)] * 4,
        "mixed": [(900, 600), (600, 900), (800, 800), (900, 600)],
    }
    for label, sizes in shape_sets.items():
        paths = [_make_photo(tmp_path / f"{label}_{i}.jpg", sz) for i, sz in enumerate(sizes)]
        tiles = [(i, p) for i, p in enumerate(paths)]
        for layout_name in ("asymmetric", "columns", "rows", "square"):
            preview = MosaicPreview(content_width=760)
            preview.set_mosaic(tiles, layout_name, gap=4)
            out = preview.computed_cells()
            assert not _is_two_by_two(out["items"]), (
                f"4-photo {label}/{layout_name} produced 2x2 grid: {out['items']}"
            )


def test_mosaic_dialog_is_maximisable_and_uses_preview_width(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())
    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    mode._clear()
    try:
        paths = [
            _make_photo(tmp_path / "a.jpg", (900, 600)),
            _make_photo(tmp_path / "b.jpg", (600, 900)),
            _make_photo(tmp_path / "c.jpg", (800, 800)),
        ]
        mode._add_photo_paths(paths, insert=False)
        mode._preview_width = 360

        seen = {}
        orig_exec = QDialog.exec

        def _capture_exec(dlg):
            seen["flags"] = dlg.windowFlags()
            seen["size_grip"] = dlg.isSizeGripEnabled()
            previews = dlg.findChildren(MosaicPreview)
            seen["preview_count"] = len(previews)
            seen["content_width"] = previews[0]._content_width if previews else None
            QTimer.singleShot(0, dlg.reject)
            return orig_exec(dlg)

        monkeypatch.setattr(QDialog, "exec", _capture_exec)
        mode._mosaic_dialog([], "asymmetric")

        assert seen["flags"] & Qt.WindowMaximizeButtonHint
        assert seen["size_grip"] is True
        assert seen["preview_count"] == 1
        assert seen["content_width"] == 360
    finally:
        mode.close()
