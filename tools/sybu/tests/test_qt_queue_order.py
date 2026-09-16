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


def test_post_button_follows_the_blogs_mode():
    from PySide6.QtWidgets import QApplication
    import sybu_qt
    app = QApplication.instance() or QApplication([])
    w = sybu_qt.Window()
    # unknown mode → the explicit pair, no single POST
    assert w.post_solo.isVisibleTo(w) and w.post_gram.isVisibleTo(w) and not w.post_btn.isVisibleTo(w)
    w._apply_site_mode("carousel")
    assert w.post_btn.isVisibleTo(w) and w.post_btn.text() == "POST GRAM" and w._site_grams is True
    assert not w.post_solo.isVisibleTo(w) and not w.qpost_gram.isVisibleTo(w)
    assert w.qpost_btn.text() == "POST GRAM"
    w._apply_site_mode("photoblog")
    assert w.post_btn.text() == "POST SOLO" and w._site_grams is False


def test_progress_strip_visible_from_every_page_and_posted_wording():
    from PySide6.QtWidgets import QApplication
    import sybu_qt
    app = QApplication.instance() or QApplication([])
    w = sybu_qt.Window()
    # the strip is a child of the main column, not of the Post page
    assert w.progress.parent() is not None and w.progress.parent() is not w.pages.widget(0)
    assert w.stop_btn.text() == "STOP" and not w.stop_btn.isEnabled()
    # status wording: 'ok' from the engine means the image went up
    data = {"rows": [{"selected": True, "status": "ok", "file": "a.jpg", "message": ""},
                     {"selected": True, "status": "posting", "file": "b.jpg", "message": ""},
                     {"selected": True, "status": "error", "file": "c.jpg", "message": "401"}],
            "count": 3, "selected": 3, "failed": 1}
    w.engine.thumb = lambda i, n: ""
    w._fill_queue(data)
    assert w.table.item(0, 11).text() == "POSTED"
    assert w.table.item(1, 11).text() == "posting…"
    assert w.table.item(2, 11).text() == "ERROR: 401"
def test_scan_restores_saved_enrichment(monkeypatch):
    """A folder with a recovery file comes back enriched, without asking, without Gemini."""
    from PySide6.QtWidgets import QApplication
    import sybu_qt
    app = QApplication.instance() or QApplication([])
    w = sybu_qt.Window()
    calls = {"apply": 0}
    class Rec:
        def exists(self): return True
        def enriched_count_for(self, entries): return 3
    w.engine.recovery = Rec(); w.engine.entries = [1, 2, 3, 4]
    def apply_resume():
        calls["apply"] += 1
        return {"rows": [], "count": 4, "selected": 4, "failed": 0}
    monkeypatch.setattr(w.engine, "apply_resume", apply_resume)
    monkeypatch.setattr(w, "_fill_queue", lambda data=None: None)
    w._task_done("scan", {"rows": [], "count": 4, "selected": 4, "failed": 0}, None)
    assert calls["apply"] == 1
    assert "Restored saved enrichment for 3" in w.log.toPlainText()


def test_queue_toolbar_wraps_instead_of_clipping():
    from PySide6.QtWidgets import QApplication
    import sybu_qt
    app = QApplication.instance() or QApplication([]); app.setStyleSheet(sybu_qt.STYLE)
    w = sybu_qt.Window(); w.resize(1040, 700); w.show(); app.processEvents()
    lay = w.table.parentWidget().layout()
    flows = [lay.itemAt(i).layout() for i in range(lay.count()) if isinstance(lay.itemAt(i).layout(), sybu_qt.FlowLayout)]
    assert flows, "queue toolbar must be a FlowLayout"
    fl = flows[0]
    # at 1040 px the eleven controls need more than one row
    assert fl.heightForWidth(700) > fl.heightForWidth(3000)

# ===== SNAPSMACK EOF =====
