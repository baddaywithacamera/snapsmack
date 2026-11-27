"""COLD TAKE cancellation leaves the existing saved draft in place.

# SNAPSMACK_EOF_HEADER
"""

import threading

from PySide6.QtWidgets import QPushButton

from test_ss1514_open_published_preserves_draft import _FakePoster, _setup_mode, _wait_until


def test_cancel_during_published_fetch_keeps_editor_and_draft(monkeypatch, tmp_path):
    post = {"id": 4, "title": "Published photo essay", "content": "<p>Server body.</p>"}
    poster = _FakePoster([post], {4: post})
    entered, release = threading.Event(), threading.Event()

    def fetch(post_id):
        entered.set()
        release.wait(timeout=5)
        return post

    monkeypatch.setattr(poster, "get_post", fetch)
    app, mode = _setup_mode(monkeypatch, tmp_path, poster)
    try:
        mode.title_edit.setText("Unfinished draft")
        mode.body.setPlainText("Keep this draft.")
        mode._autosave_now()
        session = mode.rail.ensure_session()
        active_id = mode._editing_id
        saved = session.load_draft(active_id).caption
        draft_ids = {d.draft_id for d in session.list_drafts()}

        mode._open_published(choose_fn=lambda _title: "separate")
        assert _wait_until(app, entered.is_set)
        dialog = mode._published_progress
        assert dialog.isVisible()
        next(button for button in dialog.findChildren(QPushButton)
             if button.text() == "Cancel").click()
        assert "Canceled" in mode.save_state.text()
        release.set()
        assert _wait_until(app, lambda: not mode._published_load_active)
        assert mode._editing_id == active_id
        assert mode.title_edit.text() == "Unfinished draft"
        assert {d.draft_id for d in session.list_drafts()} == draft_ids
        assert session.load_draft(active_id).caption == saved
    finally:
        release.set()
        mode.close()

# ===== SNAPSMACK EOF =====
