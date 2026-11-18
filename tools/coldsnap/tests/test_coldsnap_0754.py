"""COLD SNAP 0.7.54 regression tests:
- list_posts defaults to limit=500 and sorts newest first even when server returns ASC
- Open a published post dialog: newest first, live search box, >500-post truncation note
"""

import os
import sys
from datetime import date, timedelta

from PySide6.QtCore import Qt
from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

from sumna_post import SmacktalkPoster
from coldsnap_qt.mode_take import TakeMode


def _app():
    return QApplication.instance() or QApplication([])


def test_list_posts_defaults_to_500_and_sorts_newest_first(monkeypatch):
    poster = SmacktalkPoster("https://example.test", "sp-key")
    seen = {}

    class Resp:
        status_code = 200

        def raise_for_status(self):
            pass

        def json(self):
            return {
                "posts": [
                    {"id": 1, "title": "Oldest", "created_at": "2024-01-01 10:00:00"},
                    {"id": 3, "title": "Newest", "created_at": "2026-11-18 10:00:00"},
                    {"id": 2, "title": "Middle", "created_at": "2025-06-15 10:00:00"},
                ]
            }

    def fake_get(url, params=None, timeout=30):
        seen["params"] = dict(params or {})
        return Resp()

    monkeypatch.setattr(poster.session, "get", fake_get)
    posts = poster.list_posts()
    assert seen["params"]["limit"] == 500
    assert [p["id"] for p in posts] == [3, 2, 1]


def test_open_published_dialog_newest_first_search_and_over_500_truncation_note():
    _app()
    mode = TakeMode(lambda: {"url": "https://example.test"})

    start = date(2024, 1, 1)
    asc_posts = []
    for i in range(1, 521):
        d = (start + timedelta(days=i)).isoformat()
        asc_posts.append({
            "id": i,
            "title": f"Essay #{i}" + (" TargetNeedle" if i == 515 else ""),
            "slug": f"essay-{i}",
            "status": "published",
            "created_at": f"{d} 12:00:00",
        })

    dlg = mode._build_open_published_dialog(asc_posts)
    try:
        assert dlg._choices.count() == 500
        assert dlg._choices.item(0).data(Qt.UserRole) == 520
        assert not dlg._trunc_note.isHidden()
        assert "500" in dlg._trunc_note.text()

        dlg._search_box.setText("TargetNeedle")
        assert dlg._choices.count() == 1
        assert dlg._choices.item(0).data(Qt.UserRole) == 515

        dlg._search_box.setText("")
        assert dlg._choices.count() == 500
    finally:
        dlg.close()

    small_dlg = mode._build_open_published_dialog(asc_posts[:10])
    try:
        assert small_dlg._choices.count() == 10
        assert small_dlg._choices.item(0).data(Qt.UserRole) == 10
        assert small_dlg._trunc_note.isHidden()
    finally:
        small_dlg.close()
        mode.close()

# ===== SNAPSMACK EOF =====
