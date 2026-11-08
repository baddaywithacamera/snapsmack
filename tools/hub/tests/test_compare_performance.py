"""Compare cache parity, invalidation, and bounded memory.
SNAPSMACK_EOF_HEADER: keep the canonical EOF marker last.
"""
import os
import sys
from pathlib import Path
from unittest.mock import patch

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from PIL import Image, ImageOps
from PySide6.QtWidgets import QApplication
from slapper_qt import engine_bridge as bridge

APP = QApplication.instance() or QApplication([])


def test_compare_reuses_decode_and_preserves_pixels(tmp_path, monkeypatch):
    source = tmp_path / "original.jpg"
    Image.new("RGB", (2400, 1600), (45, 98, 130)).save(source)
    bridge._original_images.clear()
    monkeypatch.setattr(bridge, "pil_to_qpixmap", lambda image: image.copy())
    original_open = Image.open
    with patch.object(bridge.Image, "open", wraps=original_open) as opened:
        first = bridge.original_pixmap(source, (800, 600))
        second = bridge.original_pixmap(source, (800, 600))
        assert opened.call_count == 1
    with original_open(source) as image:
        expected = ImageOps.exif_transpose(image).convert("RGBA")
    expected.thumbnail((800, 600), Image.Resampling.LANCZOS)
    assert first.tobytes() == second.tobytes() == expected.tobytes()
    # The converter cannot mutate the cached frame through its returned image.
    first.putpixel((0, 0), (0, 0, 0, 0))
    assert bridge.original_pixmap(source, (800, 600)).tobytes() == expected.tobytes()
    Image.new("RGB", (2400, 1600), (200, 0, 0)).save(source)
    stat = source.stat()
    os.utime(source, ns=(stat.st_atime_ns, stat.st_mtime_ns + 1000000000))
    changed = bridge.original_pixmap(source, (800, 600))
    assert changed.tobytes() != second.tobytes()
    assert len(bridge._original_images) == 1


def test_compare_cache_budget_and_native_exclusion(tmp_path, monkeypatch):
    source = tmp_path / "original.png"
    Image.new("RGB", (100, 80)).save(source)
    bridge._original_images.clear()
    monkeypatch.setattr(bridge, "pil_to_qpixmap", lambda image: image.copy())
    monkeypatch.setattr(bridge, "_ORIGINAL_CACHE_BYTES", 4000)
    for size in [(20, 20), (25, 25), (30, 30), (35, 35)]:
        bridge.original_pixmap(source, size)
        assert sum(i.width * i.height * 4 for i in bridge._original_images.values()) <= 4000
    before = len(bridge._original_images)
    bridge.original_pixmap(source)
    assert len(bridge._original_images) == before

def test_rotate_drag_coalesces_when_a_preview_is_busy():
    sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "_shared"))
    from types import SimpleNamespace
    from slapper_qt.editor_window import EditorWindow
    scheduled = []
    window = SimpleNamespace(
        doc=SimpleNamespace(geometry={}),
        _schedule_render=lambda: scheduled.append(True),
        _interactive_render=False,
        _interactive_job_active=True,
        _interactive_render_pending=False,
    )
    for value in range(40):
        EditorWindow._on_geometry(window, "rotation", value)
        EditorWindow._dispatch_render(window)
    assert window.doc.geometry["rotation"] == 39.0
    assert window._interactive_render_pending is True
    assert window._geometry_preview_mode == "rotation"
    assert len(scheduled) == 40
    # The busy dispatch never called a quality renderer or queued another job.

# ===== SNAPSMACK EOF =====
