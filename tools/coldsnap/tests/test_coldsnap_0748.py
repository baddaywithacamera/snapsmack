"""Tests for COLD SNAP 0.7.48-test: mosaic+text publish integrity and slug-based update."""

import os
import sys

from PIL import Image

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

from coldsnap_qt import biggie
import sumna_offline as O
from sumna_post import SmacktalkPoster


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
    def __init__(self, existing_posts=None):
        self.headers = {}
        self.calls = []
        self.existing_posts = list(existing_posts or [])
        self._next_img = 101

    def get(self, url, params=None, timeout=30):
        self.calls.append(("GET", url, params))
        if "smackpress/posts" in url:
            return _MockResponse(200, {"posts": self.existing_posts})
        return _MockResponse(200, {})

    def post(self, url, json=None, files=None, data=None, timeout=60):
        self.calls.append(("POST", url, json, data))
        if "smackpress/media/upload" in url:
            iid = self._next_img
            self._next_img += 1
            return _MockResponse(200, {"image_id": iid, "file": f"/img/{iid}.jpg"})
        if "smackpress/mosaics" in url:
            return _MockResponse(200, {"mosaic_id": 77, "shortcode": "[mosaic:77]"})
        if "smackpress/posts" in url:
            pid = int((json or {}).get("post_id") or 555)
            return _MockResponse(200, {"post_id": pid, "slug": (json or {}).get("slug", "start-your-engines")})
        return _MockResponse(404, {})


def test_mosaic_plus_text_publish_arrives_with_body_and_refs_and_updates_existing_slug(tmp_path, monkeypatch):
    monkeypatch.setattr("sumna_post._produce_library", lambda *a, **kw: None)
    p1 = tmp_path / "a.jpg"
    p2 = tmp_path / "b.jpg"
    Image.new("RGB", (1200, 800), (10, 20, 30)).save(p1)
    Image.new("RGB", (1200, 800), (40, 50, 60)).save(p2)

    blocks = [
        {"type": "para", "text": "Start your engines, folks."},
        {"type": "mosaic", "order": ["u1", "u2"], "layout": "asymmetric"},
        {"type": "image", "asset_uuid": "u1", "width_ratio": 1.0, "placement": "block"},
    ]
    draft = O.Draft(
        draft_id="d-engines",
        kind=O.KIND_SMACKTALK,
        mode="smacktalk",
        title="Start your engines",
        slug="start-your-engines",
        caption="",  # Simulate empty caption when only body_blocks was persisted
        body_blocks=biggie.blocks_to_json(blocks),
        img_status="published",
        remote_post_id=0,
        images=[
            O.DraftImage(local_path=str(p1), filename="a.jpg", asset_uuid="u1", is_cover=True),
            O.DraftImage(local_path=str(p2), filename="b.jpg", asset_uuid="u2"),
        ],
    )

    sess = _MockSession(existing_posts=[{"id": 42, "slug": "start-your-engines", "title": "Start your engines"}])
    poster = SmacktalkPoster("https://example.test", "k" * 64, session=sess)
    res = poster.sync_smacktalk(draft, confirm_overwrite_fn=lambda _p: True)

    assert res.ok, res.message
    assert res.remote_post_id == 42

    post_calls = [c for c in sess.calls if c[0] == "POST" and "smackpress/posts" in c[1]]
    assert len(post_calls) == 1
    body = post_calls[0][2]
    assert body["post_id"] == 42
    assert body["slug"] == "start-your-engines"
    assert "Start your engines, folks." in body["content"]
    assert "[mosaic:77]" in body["content"]
    assert "[img:101" in body["content"]
    assert body["bucket_image_ids"] == [101, 102]
    assert body["featured_image_id"] == 101


# ===== SNAPSMACK EOF =====
