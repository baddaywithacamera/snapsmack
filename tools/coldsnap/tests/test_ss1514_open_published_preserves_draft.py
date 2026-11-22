"""Tests for SS-1514:
- "Open published…" must NEVER silently replace an open draft (saved or unsaved).
  When a draft has content, it prompts to:
    1) Attach this draft to the published post (keeps blocks & bucket, sets remote_post_id)
    2) Open separately (saves the open draft first so it remains in the session, then opens remote)
    3) Cancel (leaves the open draft untouched in the editor)
- "Open published…" runs list_posts, get_post, and bucket downloads off the GUI thread
  so the window does not freeze (Not Responding).
"""

import os
import sys
import time

from PIL import Image
from PySide6.QtCore import QTimer
from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

_SHARED = os.path.join(os.path.dirname(_ROOT), "_shared")
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

from coldsnap_qt import theme
from coldsnap_qt.mode_take import TakeMode


def _app():
    return QApplication.instance() or QApplication([])


def _make_photo(path, size=(600, 400), color=(40, 110, 180)):
    im = Image.new("RGB", size, color)
    im.save(str(path), "JPEG")
    return str(path)


class _FakePoster:
    def __init__(self, posts, full_posts, delay_s=0.0):
        self._posts = posts
        self._full_posts = full_posts
        self._delay_s = delay_s

    def list_posts(self):
        if self._delay_s:
            time.sleep(self._delay_s)
        return list(self._posts)

    def get_post(self, post_id):
        if self._delay_s:
            time.sleep(self._delay_s)
        return dict(self._full_posts[int(post_id)])


def _setup_mode(monkeypatch, tmp_path, poster):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())
    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    mode._clear()
    monkeypatch.setattr(mode, "_poster_and_url", lambda: (poster, "https://example.com"))

    def _auto_accept_picker(posts):
        dlg = TakeMode._build_open_published_dialog(mode, posts)
        dlg._choices.setCurrentRow(0)
        QTimer.singleShot(0, dlg.accept)
        return dlg

    monkeypatch.setattr(mode, "_build_open_published_dialog", _auto_accept_picker)
    return app, mode


def test_ss1514_attach_preserves_saved_three_block_draft(monkeypatch, tmp_path):
    poster = _FakePoster(
        posts=[{"id": 4, "title": "Start your engines...", "status": "published", "created_at": "2026-01-04 12:00:00"}],
        full_posts={4: {"id": 4, "title": "Start your engines...", "content": "", "status": "published", "bucket": []}},
    )
    app, mode = _setup_mode(monkeypatch, tmp_path, poster)
    try:
        p1 = _make_photo(tmp_path / "01.jpg")
        mode._add_photo_paths([p1], insert=False)
        uid = mode._bucket[0].asset_uuid
        mode.title_edit.setText("Start your engines...")
        mode.body.canvas.from_blocks([
            {"type": "para", "text": "It's been a minute, huh? My last post here was on Jan 4, 2026."},
            {"type": "image", "img_id": f"asset:{uid}", "asset_uuid": uid, "width_ratio": 1.0},
            {"type": "para", "text": "Second paragraph below the photo."},
        ])
        mode._changed()
        mode._autosave_now()
        saved_id = mode._editing_id
        assert saved_id
        assert len(mode.body.canvas.to_blocks()) == 3

        mode._open_published(choose_fn=lambda _title: "attach")
        app.processEvents()

        assert mode._editing_id == saved_id
        assert len(mode.body.canvas.to_blocks()) == 3
        assert "It's been a minute, huh?" in mode.body.toPlainText()
        assert len(mode._bucket) == 1

        saved_draft = mode.rail.ensure_session().load_draft(saved_id)
        assert saved_draft is not None
        assert saved_draft.remote_post_id == 4
        assert "It's been a minute, huh?" in saved_draft.caption
        assert len(saved_draft.images) == 1
    finally:
        mode.close()


def test_ss1514_unsaved_draft_survives_separate_and_cancel(monkeypatch, tmp_path):
    poster = _FakePoster(
        posts=[{"id": 9, "title": "Published Post Nine", "status": "published", "created_at": "2026-02-01 10:00:00"}],
        full_posts={9: {"id": 9, "title": "Published Post Nine", "content": "Remote body text.", "status": "published", "bucket": []}},
    )
    app, mode = _setup_mode(monkeypatch, tmp_path, poster)
    try:
        p1 = _make_photo(tmp_path / "unsaved.jpg")
        mode._add_photo_paths([p1], insert=False)
        mode.title_edit.setText("Unsaved Draft Title")
        mode.body.canvas.from_blocks([
            {"type": "para", "text": "Unsaved paragraph that must not vanish."},
        ])
        assert mode._editing_id is None

        # 1. Cancel leaves the unsaved draft untouched in the editor
        mode._open_published(choose_fn=lambda _title: "cancel")
        app.processEvents()
        assert mode.title_edit.text() == "Unsaved Draft Title"
        assert "Unsaved paragraph that must not vanish." in mode.body.toPlainText()
        assert len(mode._bucket) == 1

        # 2. Separate saves the unsaved draft into the session before loading remote post
        mode._open_published(choose_fn=lambda _title: "separate")
        app.processEvents()

        assert mode.title_edit.text() == "Published Post Nine"
        assert "Remote body text." in mode.body.toPlainText()

        session = mode.rail.ensure_session()
        titles = {d.title: d for d in session.list_drafts()}
        assert "Unsaved Draft Title" in titles
        preserved = titles["Unsaved Draft Title"]
        assert "Unsaved paragraph that must not vanish." in preserved.caption
        assert len(preserved.images) == 1
    finally:
        mode.close()


def test_ss1514_open_published_runs_off_gui_thread(monkeypatch, tmp_path):
    poster = _FakePoster(
        posts=[{"id": 12, "title": "Slow Remote Post", "status": "published", "created_at": "2026-03-01 09:00:00"}],
        full_posts={12: {"id": 12, "title": "Slow Remote Post", "content": "Loaded after delay.", "status": "published", "bucket": []}},
        delay_s=0.18,
    )
    app, mode = _setup_mode(monkeypatch, tmp_path, poster)
    try:
        ticks = []
        timer = QTimer()
        timer.setInterval(20)
        timer.timeout.connect(lambda: ticks.append(1))
        timer.start()

        mode._open_published()
        timer.stop()
        app.processEvents()

        assert len(ticks) >= 3, f"GUI event loop froze during _open_published (ticks={len(ticks)})"
        assert mode.title_edit.text() == "Slow Remote Post"
        assert "Loaded after delay." in mode.body.toPlainText()
    finally:
        mode.close()
