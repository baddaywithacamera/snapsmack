# SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""SYBU 0.7.68: the Qt queue can be reordered — drag a row, Move up/down, Randomize.
The Tk window had Randomize + drag; the Qt rebuild shipped without either."""

import os
import sys
from types import SimpleNamespace

TOOL = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
for p in (TOOL, os.path.join(TOOL, "..", "_shared")):
    if p not in sys.path:
        sys.path.insert(0, p)
os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

import sybu_core


def _engine(n=4):
    e = sybu_core.Engine.__new__(sybu_core.Engine)
    e.entries = [SimpleNamespace(file=f"img{i}.jpg") for i in range(n)]
    e.rowstate = [{"selected": True, "status": "pending"} for _ in range(n)]
    # reorder()/shuffle() return serialize_queue(); keep the row shape simple here
    e._serialize_entry = lambda i: {"index": i, "file": e.entries[i].file, **e.rowstate[i]}
    e.image_folder = ""
    e.enrichment_warnings = {}
    return e


def test_engine_reorder_moves_entry_and_rowstate_together():
    e = _engine()
    e.rowstate[0]["status"] = "marker"
    e.reorder(0, 2)
    assert [x.file for x in e.entries] == ["img1.jpg", "img2.jpg", "img0.jpg", "img3.jpg"]
    assert e.rowstate[2]["status"] == "marker"


def test_engine_shuffle_keeps_pairs():
    e = _engine(6)
    for i, rs in enumerate(e.rowstate):
        rs["status"] = f"s{i}"
    e.shuffle()
    assert sorted(x.file for x in e.entries) == sorted(f"img{i}.jpg" for i in range(6))
    for ent, rs in zip(e.entries, e.rowstate):
        assert rs["status"] == "s" + ent.file[3]


def test_qt_queue_has_order_controls():
    from PySide6.QtWidgets import QApplication, QPushButton
    import sybu_qt
    app = QApplication.instance() or QApplication([])
    w = sybu_qt.Window()
    names = {b.text() for b in w.findChildren(QPushButton)}
    assert "Randomize" in names and "▲ Move up" in names and "▼ Move down" in names
    assert isinstance(w.table, sybu_qt.QueueTable) and w.table.dragEnabled() and w.table.acceptDrops()


def test_qt_drop_reorders_through_engine(monkeypatch):
    from PySide6.QtWidgets import QApplication
    import sybu_qt
    app = QApplication.instance() or QApplication([])
    w = sybu_qt.Window()
    e = _engine(3)
    monkeypatch.setattr(w, "engine", e)
    monkeypatch.setattr(w, "_sync_queue", lambda: None)
    filled = {}
    monkeypatch.setattr(w, "_fill_queue", lambda data=None: filled.update(data or {}))
    w.table.setRowCount(3)
    w.table.moved.emit(2, 0)
    assert [x.file for x in e.entries] == ["img2.jpg", "img0.jpg", "img1.jpg"]
    assert filled["count"] == 3
# ===== SNAPSMACK EOF =====
