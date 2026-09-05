"""Regression checks for COLD SNAP's SMACKTALK mosaic integration + library record.

A [mosaic] placeholder in a longform body must become a real [mosaic:ID] gallery of
the essay's uploaded photos (via POST smackpress/mosaics), a hand-written numeric
[mosaic:123] must be left alone, and a successful post must mirror itself into the
shared offline library. All exercised with a fake HTTP session and an isolated
SNAPSMACK_HOME — no network, no live site, no image processing.
"""

import os
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
for sub in ("tools/coldsnap", "tools/_shared"):
    p = os.path.join(ROOT, *sub.split("/"))
    if p not in sys.path:
        sys.path.insert(0, p)

os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="cs_mosaic_")

import sumna_post as P            # noqa: E402
import snap_library as L          # noqa: E402

SITE = "https://site.test"


class _Resp:
    def __init__(self, payload, status=200):
        self._p = payload
        self.status_code = status
        self.url = ""

    def json(self):
        return self._p

    def raise_for_status(self):
        if self.status_code >= 400:
            raise RuntimeError("http %d" % self.status_code)


class _Session:
    def __init__(self):
        self.calls = []

    def post(self, url, json=None, files=None, timeout=None):
        self.calls.append((url, json))
        if url.endswith("smackpress/mosaics"):
            return _Resp({"ok": True, "mosaic_id": 55, "shortcode": "[mosaic:55]"})
        if url.endswith("smackpress/posts"):
            return _Resp({"ok": True, "post_id": 900, "permalink": "https://site.test/p/900"})
        if url.endswith("smackpress/media/upload"):
            return _Resp({"image_id": 1})
        return _Resp({"ok": True})

    def get(self, *a, **k):
        return _Resp({"ok": True})


class _Img:
    def __init__(self, path, cover=False):
        self.local_path = path
        self.filename = os.path.basename(path)
        self.is_cover = cover
        self.alt = "alt"


class _Draft:
    def __init__(self, imgs, caption):
        self.title = "Essay"
        self.caption = caption
        self.tags = "#x #y"
        self.images = imgs
        self.img_status = "published"
        self.post_date = ""


def _poster():
    return P.SmacktalkPoster(SITE, "smackpress-key", session=_Session())


def test_create_mosaic_returns_id_and_shortcode():
    mid, sc = _poster().create_mosaic([1, 2, 3], title="T", gap=6)
    assert mid == 55 and sc == "[mosaic:55]"


def test_resolve_replaces_placeholder_but_not_numeric():
    po = _poster()
    d = _Draft([], "")
    c, ids = po._resolve_mosaics("A\n\n[mosaic]\n\nB", [1, 2], d)
    assert c == "A\n\n[mosaic:55]\n\nB" and ids == [55]
    c2, ids2 = po._resolve_mosaics("keep [mosaic:12] as-is", [1, 2], d)
    assert c2 == "keep [mosaic:12] as-is" and ids2 == []
    # no images → nothing created, placeholder left for the author to notice
    c3, ids3 = po._resolve_mosaics("[mosaic]", [], d)
    assert ids3 == []


def test_build_payload_uses_resolved_content():
    pl = _poster().build_payload(_Draft([], "orig"), [1, 2], 1, content="RESOLVED [mosaic:55]")
    assert pl["content"] == "RESOLVED [mosaic:55]"
    # falls back to caption when no override
    assert _poster().build_payload(_Draft([], "orig"), [1], 1)["content"] == "orig"


def test_sync_smacktalk_resolves_mosaic_and_records_to_library():
    d = tempfile.mkdtemp()
    p1 = os.path.join(d, "one.jpg")
    with open(p1, "wb") as f:
        f.write(b"IMG-BYTES-1")
    draft = _Draft([_Img(p1, cover=True)], "Story.\n\n[mosaic]\n\nEnd.")

    po = _poster()
    po._upload_one = lambda im: 1          # skip PIL/network image processing
    res = po.sync_smacktalk(draft)
    assert res.ok and res.remote_post_id == 900, res.message

    posts = [j for (u, j) in po.session.calls if u.endswith("smackpress/posts")]
    assert posts and "[mosaic:55]" in posts[0]["content"], posts

    recs = L.posts(SITE, site_mode="smacktalk")
    assert len(recs) == 1 and recs[0]["post_id"] == 900
    assert "[mosaic:55]" in recs[0]["body"]
    assert recs[0]["source_tool"] == "coldsnap"
    assets = L.assets_for(SITE, 900)
    assert len(assets) == 1 and assets[0]["alt"] == "alt"


if __name__ == "__main__":
    test_create_mosaic_returns_id_and_shortcode()
    test_resolve_replaces_placeholder_but_not_numeric()
    test_build_payload_uses_resolved_content()
    test_sync_smacktalk_resolves_mosaic_and_records_to_library()
    print("ok")

# ===== SNAPSMACK EOF =====
