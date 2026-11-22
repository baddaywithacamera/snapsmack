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


def test_ss1514_open_draft_button_lists_and_loads_saved_draft(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())
    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    mode._clear()
    try:
        from PySide6.QtWidgets import QDialog, QPushButton
        open_draft_btns = [b for b in mode.findChildren(QPushButton) if b.text() == "Open draft…"]
        assert len(open_draft_btns) == 1

        p1 = _make_photo(tmp_path / "draft01.jpg")
        mode._add_photo_paths([p1], insert=False)
        uid = mode._bucket[0].asset_uuid
        mode.title_edit.setText("Saved Local Story")
        mode.body.canvas.from_blocks([
            {"type": "para", "text": "Paragraph one."},
            {"type": "image", "img_id": f"asset:{uid}", "asset_uuid": uid, "width_ratio": 1.0},
            {"type": "para", "text": "Paragraph two."},
        ])
        mode._changed()
        mode._autosave_now()
        saved_id = mode._editing_id
        draft_path = mode.rail.ensure_session()._draft_path(saved_id)
        mtime_before = os.stat(draft_path).st_mtime_ns

        # Clear editor to simulate landing on an empty published post
        mode._clear()
        assert mode.title_edit.text() == ""

        seen = {}
        orig_build = TakeMode._build_open_draft_dialog

        def _auto_pick(self_mode, entries):
            dlg = orig_build(self_mode, entries)
            seen["item_text"] = dlg._choices.item(0).text()
            dlg._choices.setCurrentRow(0)
            QTimer.singleShot(0, dlg.accept)
            return dlg

        monkeypatch.setattr(TakeMode, "_build_open_draft_dialog", _auto_pick)
        mode._open_draft()
        app.processEvents()

        assert "Saved Local Story" in seen["item_text"]
        assert "3 blocks" in seen["item_text"]
        assert mode._editing_id == saved_id
        assert mode.title_edit.text() == "Saved Local Story"
        assert len(mode.body.canvas.to_blocks()) == 3
        assert os.stat(draft_path).st_mtime_ns == mtime_before
    finally:
        mode.close()


def test_ss1514_no_empty_duplicate_archive_and_block_empty_queue(monkeypatch, tmp_path):
    poster = _FakePoster(
        posts=[{"id": 4, "title": "Start your engines...", "status": "published", "created_at": "2026-01-04 12:00:00"}],
        full_posts={4: {"id": 4, "title": "Start your engines...", "content": "", "status": "published", "bucket": []}},
    )
    app, mode = _setup_mode(monkeypatch, tmp_path, poster)
    try:
        from PySide6.QtCore import QSettings
        QSettings("SnapSmack", "ColdSnap").remove("take/archived_draft_ids")

        # 1. Opening an empty published post does NOT create a 0-block draft on disk
        before_count = len(mode.rail.ensure_session().list_drafts())
        mode._open_published()
        app.processEvents()
        mode._autosave_now()
        assert len(mode.rail.ensure_session().list_drafts()) == before_count

        # 2. Queuing a 0-block draft linked to #4 is blocked unless confirmed
        p1 = _make_photo(tmp_path / "p.jpg")
        mode._add_photo_paths([p1], insert=False)
        mode._remote_post_id = 4
        mode.body.canvas.from_blocks([{"type": "para", "text": ""}])
        mode._save(ready=True, confirm_empty_fn=lambda _rid: False)
        assert len(mode.rail.ensure_session().list_drafts()) == before_count

        # 3. Duplicate remote link warning + Archive draft (soft hide, file preserved)
        import sumna_offline as O
        sess = mode.rail.ensure_session()
        d1 = O.Draft(draft_id="d1", kind=O.KIND_SMACKTALK, mode=mode.SUITE_MODE, title="Real Draft", caption="Para 1", remote_post_id=4)
        d2 = O.Draft(draft_id="d2", kind=O.KIND_SMACKTALK, mode=mode.SUITE_MODE, title="Old Stub", caption="", remote_post_id=4)
        sess.add_draft(d1, copy_images=False)
        sess.add_draft(d2, copy_images=False)

        entries = mode._collect_local_drafts()
        dlg = mode._build_open_draft_dialog(entries)
        assert not dlg._dup_banner.isHidden()
        assert "#4" in dlg._dup_banner.text()

        # Archive current item without deleting its file
        before_dlg_count = dlg._choices.count()
        dlg._choices.setCurrentRow(0)
        archived_idx = int(dlg._choices.currentItem().data(0x0100))  # Qt.UserRole
        archived_draft = dlg._entries[archived_idx][1]
        dlg._archive_btn.click()
        app.processEvents()
        assert os.path.isfile(sess._draft_path(archived_draft.draft_id))
        assert dlg._choices.count() == before_dlg_count - 1

        dlg._show_arch_cb.setChecked(True)
        app.processEvents()
        assert dlg._choices.count() == before_dlg_count

        # 4. Delete draft moves to 30-day trash, blocks open draft, requires 2nd confirm on linked draft, and Restore brings it back
        QSettings("SnapSmack", "ColdSnap").remove("take/archived_draft_ids")
        dlg._entries = mode._collect_local_drafts(include_archived=False)
        dlg._show_arch_cb.setChecked(False)
        dlg._show_arch_cb.toggled.emit(False)
        mode._editing_id = d1.draft_id
        app.processEvents()
        # Try deleting open draft d1 -> blocked
        for row in range(dlg._choices.count()):
            idx = int(dlg._choices.item(row).data(0x0100))
            if dlg._entries[idx][1].draft_id == d1.draft_id:
                dlg._choices.setCurrentRow(row)
                break
        prompts = []
        dlg._on_delete(confirm_fn=lambda stage, dr: (prompts.append((stage, dr.draft_id)), True)[1])
        assert prompts == []  # open draft cannot be deleted

        # Delete linked draft d2 -> prompts both 'delete' and 'linked'
        mode._editing_id = None
        for row in range(dlg._choices.count()):
            idx = int(dlg._choices.item(row).data(0x0100))
            if dlg._entries[idx][1].draft_id == d2.draft_id:
                dlg._choices.setCurrentRow(row)
                break
        dlg._on_delete(confirm_fn=lambda stage, dr: (prompts.append((stage, dr.draft_id)), True)[1])
        assert prompts == [("delete", "d2"), ("linked", "d2")]
        assert not os.path.isfile(sess._draft_path("d2"))

        # Show trash & Restore d2
        trash_before = len(mode._list_trash_drafts())
        dlg._show_trash_cb.setChecked(True)
        app.processEvents()
        assert dlg._choices.count() == trash_before
        for row in range(dlg._choices.count()):
            idx = int(dlg._choices.item(row).data(0x0100))
            if dlg._trash_entries[idx][3].draft_id == "d2":
                dlg._choices.setCurrentRow(row)
                break
        dlg._restore_btn.click()
        app.processEvents()
        assert os.path.isfile(sess._draft_path("d2"))
        dlg.reject()
    finally:
        from PySide6.QtCore import QSettings
        QSettings("SnapSmack", "ColdSnap").remove("take/archived_draft_ids")
        mode.close()


def test_featured_photo_control_and_linked_status_line(monkeypatch, tmp_path):
    poster = _FakePoster(
        posts=[{"id": 4, "title": "Start your engines...", "slug": "start-your-engines", "status": "published", "created_at": "2026-01-04 12:00:00"}],
        full_posts={4: {"id": 4, "title": "Start your engines...", "slug": "start-your-engines", "content": "", "status": "published", "bucket": []}},
    )
    app, mode = _setup_mode(monkeypatch, tmp_path, poster)
    try:
        from coldsnap_qt.photo_tray import PhotoThumbTile
        p1 = _make_photo(tmp_path / "f1.jpg")
        p2 = _make_photo(tmp_path / "f2.jpg")
        mode._add_photo_paths([p1, p2], insert=False)

        assert "Featured photo" in mode.featured_btn.text()
        tiles = [mode.bucket_col.itemAt(i).widget() for i in range(mode.bucket_col.count())]
        assert len(tiles) == 2
        assert tiles[0]._is_cover is True
        assert tiles[1]._is_cover is False

        # Right-click / signal setFeaturedRequested on 2nd photo
        tiles[1].setFeaturedRequested.emit(1)
        app.processEvents()
        assert mode._cover_idx == 1
        tiles = [mode.bucket_col.itemAt(i).widget() for i in range(mode.bucket_col.count())]
        assert tiles[1]._is_cover is True

        # Attach to published post -> linked_post_lbl shows "Editing: <title>  /<slug>"
        mode.title_edit.setText("Start your engines...")
        mode.body.canvas.from_blocks([{"type": "para", "text": "Draft paragraph."}])
        mode._open_published(choose_fn=lambda _t: "attach")
        app.processEvents()
        assert not mode.linked_post_lbl.isHidden()
        assert "Editing: Start your engines..." in mode.linked_post_lbl.text()
        assert "/start-your-engines" in mode.linked_post_lbl.text()

        # Clear hides linked_post_lbl
        mode._clear()
        app.processEvents()
        assert mode.linked_post_lbl.isHidden()
    finally:
        mode.close()
