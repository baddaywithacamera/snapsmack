"""
Tests for SmacktalkPoster (sumna_post) — COLD SNAP's SMACKTALK longform+bucket
poster. Uses a fake session (no network): proves each photo is uploaded, the
smackpress/posts payload carries the bucket in order with the right cover, and the
key/title guards fire.

Run: python tools/coldsnap/tests/test_smacktalk_poster.py   (exit 0 = all pass)

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(
    os.path.abspath(__file__)))), "_shared"))

# Isolate the shared library: a successful post now mirrors itself into
# shared_library/<site> (snap_library producer contract). Point that at a throwaway
# home so the test never writes into the real C:\snapsmack library.
os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="smacktalk_poster_test_")

from PIL import Image
import sumna_post as P
from sumna_offline import Draft, DraftImage, KIND_SMACKTALK, MODE_SMACKTALK


class FakeResp:
    def __init__(self, status=200, payload=None):
        self.status_code = status
        self._payload = payload or {}
        self.text = "ok"
        self.url = ""
    def json(self): return self._payload
    def raise_for_status(self):
        if self.status_code >= 400:
            raise RuntimeError(f"HTTP {self.status_code}")


class FakeSession:
    """Records posts; returns an incrementing image_id for uploads and a fixed
    post_id for the create."""
    def __init__(self):
        self.headers = {}
        self.uploads = 0
        self.post_calls = []
        self.last_json = None
    def post(self, url, files=None, data=None, json=None, timeout=None):
        self.post_calls.append(url)
        if "smackpress/media/upload" in url:
            self.uploads += 1
            return FakeResp(200, {"image_id": 100 + self.uploads})
        if "smackpress/posts" in url:
            self.last_json = json
            return FakeResp(200, {"post_id": 777, "slug": "s", "url": "u"})
        return FakeResp(404, {})
    def get(self, url, timeout=None, params=None):
        return FakeResp(200, {})


def _img(path, color=(90, 120, 60)):
    Image.new("RGB", (200, 150), color).save(path, "JPEG")


def _checks():
    n = 0
    tmp = tempfile.mkdtemp(prefix="smacktalk_test_")
    p1 = os.path.join(tmp, "a.jpg"); _img(p1)
    p2 = os.path.join(tmp, "b.jpg"); _img(p2)
    p3 = os.path.join(tmp, "c.jpg"); _img(p3)

    # Draft: 3 images, the SECOND marked cover; title + body + tags.
    draft = Draft(draft_id="d1", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                  title="Photo Essay", caption="the body text", tags="#street  night")
    draft.images = [
        DraftImage(local_path=p1, filename="a.jpg", sort_position=0, is_cover=False),
        DraftImage(local_path=p2, filename="b.jpg", sort_position=1, is_cover=True),
        DraftImage(local_path=p3, filename="c.jpg", sort_position=2, is_cover=False),
    ]

    # 1. Happy path: all 3 uploaded, post created, success + post_id.
    fake = FakeSession()
    poster = P.SmacktalkPoster("https://smacktalk.example", "deadbeef", session=fake)
    res = poster.sync_smacktalk(draft)
    assert res.ok, res.message
    assert res.remote_post_id == 777, res.remote_post_id
    assert fake.uploads == 3, fake.uploads
    n += 1

    # 2. Payload: bucket in upload order [101,102,103]; cover = the 2nd image's id (102).
    body = fake.last_json
    assert body["bucket_image_ids"] == [101, 102, 103], body["bucket_image_ids"]
    assert body["featured_image_id"] == 102, body["featured_image_id"]
    assert body["title"] == "Photo Essay" and body["content"] == "the body text", body
    assert body["status"] == "published", body
    assert body["tags"] == "street night", body["tags"]  # # stripped, whitespace-normalised
    n += 1

    # 3. build_payload cover-leads + no explicit cover -> first image.
    b2 = poster.build_payload(draft, [5, 6, 7], 6)
    assert b2["featured_image_id"] == 6 and b2["bucket_image_ids"] == [5, 6, 7], b2
    n += 1

    # 4. Guard: no key -> friendly failure, no uploads attempted.
    f2 = FakeSession()
    res = P.SmacktalkPoster("https://x", "", session=f2).sync_smacktalk(draft)
    assert not res.ok and "key" in res.message.lower(), res.message
    assert f2.uploads == 0, f2.uploads
    n += 1

    # 5. Guard: no title -> failure.
    notitle = Draft(draft_id="d2", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK, title="  ")
    notitle.images = [DraftImage(local_path=p1, filename="a.jpg")]
    res = P.SmacktalkPoster("https://x", "k", session=FakeSession()).sync_smacktalk(notitle)
    assert not res.ok and "title" in res.message.lower(), res.message
    n += 1

    # 6. draft.validate() accepts a well-formed SMACKTALK draft.
    assert draft.validate() == [], draft.validate()
    n += 1

    # 7. A composed mosaic can select/reorder bucket positions and carry an
    # explicit three-photo layout through to mosaic creation.
    calls = []
    poster.create_mosaic = lambda ids, title="Mosaic", gap=4, layout="asymmetric": (
        calls.append((list(ids), layout)) or (55, "[mosaic:55]"))
    resolved, mids = poster._resolve_mosaics(
        "before\n[mosaic=3,1,2 layout=one-top]\nafter", [101, 102, 103, 104], draft)
    assert resolved == "before\n[mosaic:55]\nafter", resolved
    assert mids == [55] and calls == [([103, 101, 102], "one-top")], calls
    n += 1

    # 8. A four-photo bucket can use only its bottom three, in their chosen order.
    calls.clear()
    resolved, mids = poster._resolve_mosaics(
        "[mosaic=2,3,4 layout=three-across]", [101, 102, 103, 104], draft)
    assert resolved == "[mosaic:55]", resolved
    assert mids == [55] and calls == [([102, 103, 104], "three-across")], calls
    n += 1

    return n


def _bucket_img_checks():
    """[img:bucket:N] becomes the uploaded site id at send; a gone photo drops the tag."""
    from sumna_post import SmacktalkPoster
    p = SmacktalkPoster.__new__(SmacktalkPoster)
    out = p._resolve_bucket_images("a [img:bucket:2|wall|left] b [img:bucket:9] c [img:77|full|center]", [101, 102, 103])
    assert out == "a [img:102|wall|left] b  c [img:77|full|center]", out
    assert p._resolve_bucket_images("plain", []) == "plain"
    return 2


def _mosaic_token_checks():
    """Every layout the site accepts must resolve — Columns/Rows used to go out as text."""
    from sumna_post import SmacktalkPoster
    rx = SmacktalkPoster._MOSAIC_TOKEN
    for name in SmacktalkPoster._MOSAIC_LAYOUTS:
        m = rx.search(f"[mosaic=1,2 layout={name}]")
        assert m and m.group(2) == name, name
    assert rx.search("[mosaic]") and rx.search("[mosaic:bucket]")
    assert not rx.search("[mosaic:123]"), "numeric mosaic ids are the site's, left alone"
    return 3


if __name__ == "__main__":
    passed = _checks() + _mosaic_token_checks() + _bucket_img_checks()
    print(f"OK - {passed} checks passed")
# ===== SNAPSMACK EOF =====
