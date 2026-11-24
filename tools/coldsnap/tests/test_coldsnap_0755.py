"""SS-1514: opening a published post must never discard the open draft.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys

from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

import sumna_offline as O
from coldsnap_qt import biggie
from coldsnap_qt.mode_take import TakeMode


def _app():
    return QApplication.instance() or QApplication([])


def _mode_with_session(tmp_path, monkeypatch):
    _app()
    mode = TakeMode(lambda: {"url": "https://example.test"})
    store = O.SessionStore(str(tmp_path / "sessions"))
    session = store.create("SS-1514", O.MODE_SMACKTALK)
    mode.rail.store = store
    mode.rail.session = session
    monkeypatch.setattr(mode, "_mirror_sqlite", lambda _draft, **_kwargs: None)
    return mode, session


def _local_draft(session):
    draft = O.Draft(draft_id="local-draft", kind=O.KIND_SMACKTALK,
                    mode=O.MODE_SMACKTALK, title="My unfinished draft")
    draft.caption = "Intro paragraph\n\n[img:bucket:1]\n\nClosing paragraph"
    draft.body_blocks = '[{"type":"paragraph","text":"Intro paragraph"}]'
    session.add_draft(draft, copy_images=False)
    return draft


def test_attach_keeps_local_body_and_targets_published_post(tmp_path, monkeypatch):
    mode, session = _mode_with_session(tmp_path, monkeypatch)
    draft = _local_draft(session)
    mode._edit(draft)
    result = {
        "post": {"id": 42, "slug": "published-post", "created_at": "2026-10-01 12:00:00"},
        "draft": None,
        "action": "attach",
        "session": session,
        "active_id": draft.draft_id,
    }

    mode._published_post_loaded(result)

    kept = session.load_draft(draft.draft_id)
    assert kept.caption == draft.caption
    assert kept.body_blocks == draft.body_blocks
    assert kept.remote_post_id == 42
    assert kept.slug == "published-post"
    assert mode._editing_id == draft.draft_id
    mode.close()


def test_open_separately_keeps_local_draft_and_opens_new_one(tmp_path, monkeypatch):
    mode, session = _mode_with_session(tmp_path, monkeypatch)
    local = _local_draft(session)
    mode._edit(local)
    remote = O.Draft(draft_id="remote-copy", kind=O.KIND_SMACKTALK,
                     mode=O.MODE_SMACKTALK, title="Published post",
                     remote_post_id=42)
    session.add_draft(remote, copy_images=False)

    mode._published_post_loaded({
        "post": {"id": 42},
        "draft": remote,
        "action": "separate",
        "session": session,
        "active_id": local.draft_id,
    })

    assert session.load_draft(local.draft_id).caption == local.caption
    assert session.load_draft(remote.draft_id).remote_post_id == 42
    assert mode._editing_id == remote.draft_id
    mode.close()


def test_remote_read_starts_on_worker_thread(tmp_path, monkeypatch):
    mode, session = _mode_with_session(tmp_path, monkeypatch)
    started = {}

    class FakeThread:
        def __init__(self, *, target, name, daemon):
            started.update(target=target, name=name, daemon=daemon)

        def start(self):
            started["started"] = True

    class Poster:
        def get_post(self, _post_id):
            raise AssertionError("must not run on the GUI thread")

    monkeypatch.setattr("coldsnap_qt.mode_take.threading.Thread", FakeThread)
    mode._start_published_load(Poster(), 42, "separate", session, "")

    assert started["started"] is True
    assert started["daemon"] is True
    assert started["name"] == "coldsnap-open-published"
    assert callable(started["target"])
    mode.close()


def test_open_published_flushes_pending_autosave_before_network(tmp_path, monkeypatch):
    mode, session = _mode_with_session(tmp_path, monkeypatch)
    mode.title_edit.setText("Unsaved title")
    mode.body.set_state("Words still in the editor", biggie.blocks_to_json([
        {"type": "para", "text": "Words still in the editor"},
    ]))
    observed = {}

    class FakeThread:
        def __init__(self, *, target, name, daemon):
            observed.update(target=target, name=name, daemon=daemon)

        def start(self):
            observed["started"] = True

    class Poster:
        def list_posts(self):
            observed["drafts_at_network"] = session.list_drafts()
            return []

    monkeypatch.setattr(mode, "_poster_and_url", lambda: (Poster(), "https://example.test"))
    monkeypatch.setattr("coldsnap_qt.mode_take.threading.Thread", FakeThread)
    monkeypatch.setattr("coldsnap_qt.mode_take.QMessageBox.information",
                        lambda *_args, **_kwargs: None)
    mode._open_published()

    assert observed["started"] is True
    assert observed["name"] == "coldsnap-list-published"
    assert "drafts_at_network" not in observed
    observed["target"]()
    assert len(observed["drafts_at_network"]) == 1
    saved = observed["drafts_at_network"][0]
    assert saved.title == "Unsaved title"
    assert "Words still in the editor" in saved.caption
    mode.close()


def test_published_post_list_fetch_stays_off_gui_thread(tmp_path, monkeypatch):
    mode, _session = _mode_with_session(tmp_path, monkeypatch)
    started = {}

    class FakeThread:
        def __init__(self, *, target, name, daemon):
            started.update(target=target, name=name, daemon=daemon)

        def start(self):
            started["started"] = True

    class Poster:
        def list_posts(self):
            raise AssertionError("list_posts must not run on the GUI thread")

    monkeypatch.setattr("coldsnap_qt.mode_take.threading.Thread", FakeThread)
    mode._start_published_list(Poster())

    assert started["started"] is True
    assert started["daemon"] is True
    assert started["name"] == "coldsnap-list-published"
    assert callable(started["target"])
    mode.close()


def test_open_published_aborts_when_draft_cannot_be_saved(tmp_path, monkeypatch):
    mode, _session = _mode_with_session(tmp_path, monkeypatch)
    called = {"network": False, "warning": False}

    class Poster:
        def list_posts(self):
            called["network"] = True
            return []

    monkeypatch.setattr(mode, "_autosave_now", lambda: False)
    monkeypatch.setattr(mode, "_poster_and_url", lambda: (Poster(), "https://example.test"))
    monkeypatch.setattr(
        "coldsnap_qt.mode_take.QMessageBox.critical",
        lambda *_args, **_kwargs: called.__setitem__("warning", True),
    )

    mode._open_published()

    assert called == {"network": False, "warning": True}
    mode.close()


# ===== SNAPSMACK EOF =====
