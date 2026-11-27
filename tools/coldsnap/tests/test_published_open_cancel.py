"""COLD TAKE cancellation leaves the existing saved draft in place.

# SNAPSMACK_EOF_HEADER
"""

import threading
from types import SimpleNamespace

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

def test_published_image_preview_uses_downloaded_photo_before_scanning_gallery(monkeypatch, tmp_path):
    _, mode = _setup_mode(monkeypatch, tmp_path, _FakePoster([], {}))
    try:
        canvas = mode.body.canvas
        mode._bucket = [SimpleNamespace(
            remote_image_id=36, local_path="downloaded.jpg", thumb_aspect="preview.jpg",
            asset_uuid="image-uuid",
        )]
        mode._sync_body_assets()
        monkeypatch.setattr(canvas, "_load_gallery_paths",
                            lambda site: (_ for _ in ()).throw(AssertionError(site)))
        monkeypatch.setattr(canvas._objects, "_cached_cell_pixmap", lambda path, size: path)
        assert canvas.image_pixmap("36", 500) == "preview.jpg"
        assert mode.page_card.layout().indexOf(mode.colophon_edit) > mode.page_card.layout().indexOf(mode.body)
    finally:
        mode.close()


def test_gallery_preview_skips_missing_duplicate_on_a_worker(monkeypatch, tmp_path):
    from coldsnap_qt import gallery_picker

    app, mode = _setup_mode(monkeypatch, tmp_path, _FakePoster([], {}))
    try:
        canvas = mode.body.canvas
        canvas.set_site_provider(lambda: "https://fixture.example")
        caller = threading.get_ident()
        observed = []

        def cached_gallery(site):
            observed.append((site, threading.get_ident()))
            return [{"img_id": "36", "path": ""}, {"img_id": "36", "path": "older.jpg"}]

        monkeypatch.setattr(gallery_picker, "gallery_images", cached_gallery)
        monkeypatch.setattr(canvas._objects, "_cached_cell_pixmap", lambda path, size: path)
        assert canvas.image_pixmap("36", 500) is None
        assert _wait_until(app, lambda: canvas.image_pixmap("36", 500) == "older.jpg")
        assert len(observed) == 1 and observed[0][0] == "https://fixture.example"
        assert observed[0][1] != caller
    finally:
        mode.close()


# ===== SNAPSMACK EOF =====
