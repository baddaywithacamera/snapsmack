"""COLD TAKE reuse of a retained published post and its local photos.

# SNAPSMACK_EOF_HEADER
"""

from types import SimpleNamespace

from PIL import Image

from sumna_post import SmacktalkPoster
import published_post_cache as cache
from test_ss1514_open_published_preserves_draft import _FakePoster, _setup_mode, _wait_until


SITE = "https://fixture.example"
REVISION = "2026-11-27 09:00:00"
POST = {"id": 36, "status": "published", "slug": "test-essay",
        "title": "Test essay", "content": "<p>Keep this body.</p>"}


def test_cache_is_dormant_until_owner_sets_retention(monkeypatch, tmp_path):
    monkeypatch.setattr(cache, "site_dir", lambda site: str(tmp_path))
    monkeypatch.setattr(cache, "RETENTION_SECONDS", None)
    cache.save(SITE, POST, REVISION, {})
    assert cache.load(SITE, 36, REVISION) is None
    assert not (tmp_path / "published-post-cache").exists()


def test_cache_checks_site_revision_and_expiry(monkeypatch, tmp_path):
    monkeypatch.setattr(cache, "site_dir", lambda site: str(tmp_path))
    monkeypatch.setattr(cache, "RETENTION_SECONDS", 60)
    now = [1000]
    monkeypatch.setattr(cache.time, "time", lambda: now[0])
    cache.save(SITE, POST, REVISION, {})
    assert cache.load(SITE, 36, REVISION) == (POST, {})
    assert cache.load(SITE, 36, "newer") is None
    assert cache.load(SITE + "/another-install", 36, REVISION) is None
    now[0] += 60
    assert cache.load(SITE, 36, REVISION) is None


def test_publish_caches_authoritative_body_and_photo_ids(monkeypatch, tmp_path):
    monkeypatch.setattr(cache, "site_dir", lambda site: str(tmp_path))
    monkeypatch.setattr(cache, "RETENTION_SECONDS", 60)
    requests = []

    def get(url, *, params, timeout):
        requests.append(params["slug"])
        return SimpleNamespace(raise_for_status=lambda: None,
                               json=lambda: {"exact": True, "posts": [
                                   {"id": 36, "updated_at": REVISION},
                               ]})

    poster = SmacktalkPoster(SITE, "fixture", session=SimpleNamespace(get=get))
    monkeypatch.setattr(poster, "get_post", lambda post_id: POST)
    photo = SimpleNamespace(local_path="source.jpg", thumb_aspect="aspect.jpg", thumb_square="square.jpg")
    poster._cache_published_post(36, "test-essay", [photo], [7])
    assert requests == ["test-essay"]
    assert cache.load(SITE, 36, REVISION) == (
        POST, {"7": {"original": "source.jpg", "aspect": "aspect.jpg", "square": "square.jpg"}},
    )


def test_open_published_reuses_retained_post_and_photo(monkeypatch, tmp_path):
    original = tmp_path / "local.jpg"
    Image.new("RGB", (80, 60), "green").save(original)
    post = {**POST, "bucket": [{"id": 7, "width": 80, "height": 60,
                               "url": "https://fixture.example/remote.jpg"}]}
    poster = _FakePoster([], {})
    app, mode = _setup_mode(monkeypatch, tmp_path, poster)
    monkeypatch.setattr(cache, "load", lambda *_args: (post, {"7": {"original": str(original)}}))
    calls = []

    def unexpected(*args, **kwargs):
        calls.append(args)
        raise RuntimeError("Retained posts and photos should reopen locally.")

    monkeypatch.setattr(poster, "get_post", unexpected)
    monkeypatch.setattr("requests.get", unexpected)
    try:
        session = mode.rail.ensure_session()
        mode._start_published_load(poster, 36, "separate", session, "", revision=REVISION)
        assert _wait_until(app, lambda: not mode._published_load_active)
        assert not calls
        assert mode.title_edit.text() == "Test essay"
        assert len(mode._bucket) == 1 and mode._bucket[0].remote_image_id == 7
    finally:
        mode.close()


# ===== SNAPSMACK EOF =====
