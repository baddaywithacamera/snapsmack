"""Tests for COLD SNAP 0.7.51-test: Qt overwrite dialog wired through BatchRail -> SyncEngine."""

import os
import sys
import time

from PySide6.QtWidgets import QApplication, QMessageBox

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

from coldsnap_qt import drafts_panel
import sumna_offline as O
from sumna_post import SmacktalkPoster


def _app():
    return QApplication.instance() or QApplication([])


class _MockResponse:
    def __init__(self, status_code=200, payload=None):
        self.status_code = status_code
        self._payload = payload or {}

    def json(self):
        return self._payload

    def raise_for_status(self):
        if self.status_code >= 400:
            raise RuntimeError(f"HTTP {self.status_code}")


class _MockSession:
    def __init__(self, existing_posts=None, exact=True, full_list=False):
        self.headers = {}
        self.existing_posts = list(existing_posts or [])
        self.exact = exact
        self.full_list = full_list
        self.posts_sent = []

    def get(self, url, params=None, timeout=30):
        if "smackpress/posts/" in url:
            return _MockResponse(200, {"post": {"id": 42, "slug": "start-your-engines"}})
        if "smackpress/posts" in url:
            if self.full_list:
                posts = [{"id": i + 1, "slug": f"other-{i}", "title": f"Other {i}"} for i in range(500)]
                return _MockResponse(200, {"posts": posts})
            slug = (params or {}).get("slug")
            if self.exact and slug:
                matches = [p for p in self.existing_posts if p["slug"] == slug]
                return _MockResponse(200, {"posts": matches, "exact": True})
            return _MockResponse(200, {"posts": self.existing_posts})
        return _MockResponse(200, {})

    def post(self, url, json=None, files=None, data=None, timeout=60):
        if "smackpress/media/upload" in url:
            return _MockResponse(200, {"image_id": 101, "file": "/img/101.jpg"})
        if "smackpress/posts" in url:
            self.posts_sent.append(dict(json or {}))
            pid = int((json or {}).get("post_id") or 888)
            return _MockResponse(200, {"post_id": pid, "slug": (json or {}).get("slug", "")})
        return _MockResponse(404, {})


def _setup_rail_with_draft(tmp_path, monkeypatch, sess):
    from PIL import Image
    tmp_path.mkdir(parents=True, exist_ok=True)
    p1 = tmp_path / "cover.jpg"
    Image.new("RGB", (800, 600), (20, 30, 40)).save(p1)
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    monkeypatch.setattr("sumna_post._produce_library", lambda *a, **kw: None)
    monkeypatch.setattr(drafts_panel, "confirm_post", lambda *a, **kw: True)

    poster = SmacktalkPoster("https://example.test", "k" * 64, session=sess)
    poster.publishing_policy = lambda: {"download_link_required": False}

    rail = drafts_panel.BatchRail("smacktalk", "post", lambda: (poster, "https://example.test"))
    sess_store = rail.ensure_session()
    draft = O.Draft(
        draft_id="d-ui-1",
        kind=O.KIND_SMACKTALK,
        mode="smacktalk",
        title="Start your engines",
        slug="start-your-engines",
        caption="Essay body text.",
        img_status="published",
        status=O.ST_READY,
        remote_post_id=0,
        images=[O.DraftImage(local_path=str(p1), filename="cover.jpg", is_cover=True)],
    )
    sess_store.add_draft(draft)
    return rail, sess_store, draft


def _patch_qmessagebox(monkeypatch, pick_label: str):
    seen_buttons = []
    seen_texts = []

    class _FakeBox:
        Question = QMessageBox.Question
        AcceptRole = QMessageBox.AcceptRole
        ActionRole = QMessageBox.ActionRole
        RejectRole = QMessageBox.RejectRole

        def __init__(self, _parent=None):
            self._buttons = {}
            self._clicked = None

        def setIcon(self, *_):
            pass

        def setWindowTitle(self, *_):
            pass

        def setText(self, txt):
            seen_texts.append(txt)

        def addButton(self, label, _role):
            btn = object()
            self._buttons[label] = btn
            return btn

        def setDefaultButton(self, *_):
            pass

        def setEscapeButton(self, *_):
            pass

        def exec(self):
            seen_buttons.append(list(self._buttons.keys()))
            self._clicked = self._buttons.get(pick_label)

        def clickedButton(self):
            return self._clicked

    monkeypatch.setattr(drafts_panel, "QMessageBox", _FakeBox)
    return seen_buttons, seen_texts


def _run_send_and_wait(app, rail):
    rail._send()
    deadline = time.monotonic() + 5.0
    while rail._sending and time.monotonic() < deadline:
        app.processEvents()
        time.sleep(0.02)
    app.processEvents()
    assert not rail._sending


def test_send_overwrite_dialog_update_new_and_cancel(tmp_path, monkeypatch):
    app = _app()

    # 1. Update
    sess1 = _MockSession(existing_posts=[
        {"id": 42, "slug": "start-your-engines", "title": "Start your engines", "created_at": "2026-08-17"}
    ])
    rail1, _store1, _d1 = _setup_rail_with_draft(tmp_path / "u", monkeypatch, sess1)
    btns1, texts1 = _patch_qmessagebox(monkeypatch, "Update")
    _run_send_and_wait(app, rail1)
    assert btns1 == [["Update", "Publish as new", "Cancel"]]
    assert "Replace existing post 'Start your engines' (start-your-engines, 2026-08-17)?" in texts1[0]
    assert len(sess1.posts_sent) == 1
    assert sess1.posts_sent[0]["post_id"] == 42

    # 2. Publish as new (with -2 suffix when base slug exists)
    sess2 = _MockSession(existing_posts=[
        {"id": 42, "slug": "start-your-engines", "title": "Start your engines", "created_at": "2026-08-17"}
    ])
    rail2, _store2, _d2 = _setup_rail_with_draft(tmp_path / "n", monkeypatch, sess2)
    btns2, _ = _patch_qmessagebox(monkeypatch, "Publish as new")
    _run_send_and_wait(app, rail2)
    assert btns2 == [["Update", "Publish as new", "Cancel"]]
    assert len(sess2.posts_sent) == 1
    assert "post_id" not in sess2.posts_sent[0]
    assert sess2.posts_sent[0]["slug"] == "start-your-engines-2"

    # 3. Cancel leaves draft untouched and NOT marked failed
    sess3 = _MockSession(existing_posts=[
        {"id": 42, "slug": "start-your-engines", "title": "Start your engines", "created_at": "2026-08-17"}
    ])
    rail3, store3, d3 = _setup_rail_with_draft(tmp_path / "c", monkeypatch, sess3)
    _patch_qmessagebox(monkeypatch, "Cancel")
    _run_send_and_wait(app, rail3)
    assert sess3.posts_sent == []
    reloaded = store3.load_draft(d3.draft_id)
    assert reloaded is not None
    assert reloaded.status == O.ST_READY
    assert reloaded.error == ""


def test_send_overwrite_dialog_full_list_omits_update(tmp_path, monkeypatch):
    app = _app()
    sess = _MockSession(exact=False, full_list=True)
    rail, store, d = _setup_rail_with_draft(tmp_path / "full", monkeypatch, sess)
    btns, _ = _patch_qmessagebox(monkeypatch, "Cancel")
    _run_send_and_wait(app, rail)
    assert btns == [["Publish as new", "Cancel"]]
    assert sess.posts_sent == []
    assert store.load_draft(d.draft_id).status == O.ST_READY


# ===== SNAPSMACK EOF =====
