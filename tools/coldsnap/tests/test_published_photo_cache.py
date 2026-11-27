"""Published originals are reused across opens and safe to evict.

# SNAPSMACK_EOF_HEADER
"""

import os
from pathlib import Path
import sys
import time

_TOOLS = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(_TOOLS / "coldsnap"))
sys.path.insert(0, str(_TOOLS / "_shared"))

import published_photo_cache as cache
import sumna_offline as offline


def test_cache_reopen_refreshes_expiry_and_separates_sites(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    site = "https://one.example"
    url = "https://cdn.example/original.jpg"
    now = time.time()
    fetched = []

    def fetch():
        fetched.append(url)
        return b"published original bytes"

    first = cache.get_full_size(site, 14, url, fetch)
    assert first.is_relative_to(tmp_path / "shared_library" / "one.example")
    os.utime(first.parent, (now - cache.RETENTION_SECONDS + 10, now - cache.RETENTION_SECONDS + 10))
    assert cache.get_full_size(site, 14, url, fetch) == first
    assert cache.prune(site, now=now + 20) == 0
    assert len(fetched) == 1
    assert cache.get_full_size("https://two.example", 14, url, fetch) != first
    assert len(fetched) == 2
    assert cache.prune(site, now=time.time() + cache.RETENTION_SECONDS + 1) == 1
    assert not first.exists()


def test_clearing_shared_cache_keeps_saved_draft_original(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    site = "https://one.example"
    path = cache.get_full_size(site, 9, "https://one.example/example.jpg", lambda: b"original")
    session = offline.SessionStore().create(offline.MODE_SMACKTALK, "Published copies")
    draft = offline.Draft(draft_id=offline._new_id(), kind=offline.KIND_SMACKTALK,
                          mode=offline.MODE_SMACKTALK,
                          images=[offline.DraftImage(local_path=str(path), original_path=str(path))])
    session.add_draft(draft)
    saved = Path(session.load_draft(draft.draft_id).images[0].local_path)
    assert saved != path and saved.is_file()
    assert saved.samefile(path)
    assert cache.prune(site, clear=True) == 1
    assert not path.exists()
    assert saved.read_bytes() == b"original"

def test_open_published_keeps_other_photos_when_one_preview_is_unreadable(monkeypatch, tmp_path):
    from io import BytesIO
    from types import SimpleNamespace
    from PIL import Image
    from coldsnap_qt import mode_take
    from test_ss1514_open_published_preserves_draft import _FakePoster, _setup_mode, _wait_until

    positive = BytesIO()
    Image.new("RGB", (160, 100), "#3377aa").save(positive, "JPEG")
    invalid_url = "https://media.example/bad.jpg"
    valid_url = "https://media.example/good.jpg"
    files = {invalid_url: b"not an image", valid_url: positive.getvalue()}
    monkeypatch.setattr(mode_take.requests, "get", lambda url, timeout: SimpleNamespace(
        content=files[url], raise_for_status=lambda: None,
    ))
    post = {"id": 4, "title": "Published post", "content": "<p>Still editable.</p>",
            "featured_image_id": 21,
            "bucket": [{"id": 21, "url": invalid_url}, {"id": 22, "url": valid_url}]}
    app, mode = _setup_mode(monkeypatch, tmp_path, _FakePoster([post], {4: post}))
    try:
        mode._open_published(choose_fn=lambda _title: "separate")
        assert _wait_until(app, lambda: mode._remote_post_id == 4)
        assert "Still editable." in mode.body.toPlainText()
        assert [image.remote_image_id for image in mode._bucket] == [21, 22]
        broken, good = mode._bucket
        assert broken.is_cover and not broken.thumb_aspect
        assert Path(good.thumb_aspect).is_file()
        assert "1 photo preview unavailable" in mode.save_state.text()
    finally:
        mode.close()


# ===== SNAPSMACK EOF =====
