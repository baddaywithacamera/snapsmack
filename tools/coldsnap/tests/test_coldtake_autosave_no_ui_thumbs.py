"""COLD TAKE typing lag: autosave must not bake thumbs on the UI thread, must
not copy photos into the session, and must only rebake a photo when its source,
focal point or zoom actually changed.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import threading
from pathlib import Path

from PIL import Image
from PySide6.QtWidgets import QApplication

import sumna_offline as O
from coldsnap_qt.mode_take import TakeMode


def _app():
    return QApplication.instance() or QApplication([])


def _photo(path: Path, size=(320, 200), color=(90, 30, 160)) -> str:
    Image.new("RGB", size, color).save(path, "JPEG")
    return str(path)


def test_thumbs_rebake_only_when_crop_params_change(tmp_path, monkeypatch):
    im = O.DraftImage(local_path=_photo(tmp_path / "a.jpg"))
    draft = O.Draft(draft_id="d", kind=O.KIND_SMACKTALK, mode=O.MODE_SMACKTALK)
    draft.images = [im]
    O.generate_draft_thumbs(draft)
    assert O.image_thumbs_current(im)

    real = O.snap_thumbs.generate_thumbs
    calls = []
    monkeypatch.setattr(O.snap_thumbs, "generate_thumbs",
                        lambda *a, **kw: calls.append(kw) or real(*a, **kw))
    O.generate_draft_thumbs(draft)
    assert calls == []

    im.focus_x = 10          # crop change → stale → one rebake
    assert not O.image_thumbs_current(im)
    O.generate_draft_thumbs(draft)
    assert len(calls) == 1 and calls[0]["focus_x"] == 10
    O.generate_draft_thumbs(draft)
    assert len(calls) == 1


def test_autosave_bakes_off_ui_thread_and_skips_copy(tmp_path, monkeypatch):
    _app()
    paths = [_photo(tmp_path / f"p{i}.jpg") for i in range(4)]
    take = TakeMode(lambda: {"url": ""})
    take._add_photo_paths(paths, insert=False)
    take._wait_for_thumbs()

    threads, copies = [], []
    real = O.snap_thumbs.generate_thumbs
    monkeypatch.setattr(O.snap_thumbs, "generate_thumbs",
                        lambda *a, **kw: threads.append(threading.current_thread())
                        or real(*a, **kw))
    real_add = O.Session.add_draft

    def spy_add(self, draft, copy_images=True):
        copies.append(copy_images)
        return real_add(self, draft, copy_images=copy_images)

    monkeypatch.setattr(O.Session, "add_draft", spy_add)
    session = O.Session(str(tmp_path / "sess"), {"session_id": "t"})
    monkeypatch.setattr(take.rail, "ensure_session", lambda: session)

    take.title_edit.setText("hello")
    take._autosave_now()
    take._wait_for_thumbs(timeout=30)
    assert copies and all(c is False for c in copies)
    assert threads, "fresh photos must still get thumbs"
    assert all(t is not threading.main_thread() for t in threads)
    assert all(O.image_thumbs_current(im) for im in take._bucket)

    # Typing again: nothing stale, nothing baked.
    threads.clear()
    take.title_edit.setText("hello world")
    take._autosave_now()
    take._wait_for_thumbs(timeout=30)
    assert threads == []

    # A crop change on one photo rebakes exactly that photo.
    take._bucket[2].zoom = 150
    take._autosave_now()
    take._wait_for_thumbs(timeout=30)
    assert len(threads) == 1
    assert O.image_thumbs_current(take._bucket[2])


def test_thumb_result_discarded_if_photo_changed_while_baking(tmp_path):
    _app()
    take = TakeMode(lambda: {"url": ""})
    take._add_photo_paths([_photo(tmp_path / "x.jpg")], insert=False)
    take._wait_for_thumbs()
    image = take._bucket[0]
    image.focus_y = 5
    take._queue_stale_thumbs()
    image.focus_y = 80       # edited again before the bake lands
    take._wait_for_thumbs(timeout=30)
    assert not O.image_thumbs_current(image)

# ===== SNAPSMACK EOF =====
