"""Regression checks for snap_library's content mirror (posts + assets + media).

The shared-library-post-cache-producer spec declares catalog.sqlite should hold a
`posts` + `assets` content mirror with a media/ store, so COLD SNAP (and the other
desktop tools) compose long-form offline and record what they post. This pins the
producer (record_post/store_media), the offline read API, sha256 dedupe, idempotent
upsert, the resume ledger, and that adding the content tables never disturbs the
existing vocabulary catalog.

Runs standalone (no pytest) against an isolated SNAPSMACK_HOME so the real shared
library is never touched.
"""

import os
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHARED = os.path.join(ROOT, "tools", "_shared")
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

SITE = "https://example.test"


def _fresh_home():
    d = tempfile.mkdtemp(prefix="snaplib_")
    os.environ["SNAPSMACK_HOME"] = d
    for m in ("snap_home", "snap_library", "snap_paths"):
        sys.modules.pop(m, None)
    import snap_library  # noqa: E402
    return snap_library


def test_store_media_dedupes_by_sha256_and_writes_file():
    L = _fresh_home()
    tf = tempfile.NamedTemporaryFile(delete=False, suffix=".JPG")
    tf.write(b"\xff\xd8\xff-fake-jpeg-bytes"); tf.close()

    a1 = L.store_media(SITE, tf.name)
    a2 = L.store_media(SITE, b"\xff\xd8\xff-fake-jpeg-bytes", ext=".jpg")
    assert a1["asset_id"] == a2["asset_id"], "identical bytes must share an asset_id"
    assert a1["media_path"].endswith(".jpg")
    assert a1["mime"] == "image/jpeg"
    import snap_home
    assert os.path.isfile(os.path.join(snap_home.site_media_dir(SITE), a1["media_path"]))
    assert L.asset_file(SITE, a1["asset_id"]) is None  # not attached to a post yet
    os.unlink(tf.name)


def test_record_post_and_offline_read():
    L = _fresh_home()
    a = L.store_media(SITE, b"IMGDATA-1", ext=".png", orig_name="hero.png")
    summ = L.record_post(SITE, {
        "post_id": 1234, "post_type": "long", "site_mode": "smacktalk",
        "title": "An Essay", "body": "Intro.\n\n[mosaic:9]\n\nOutro.",
        "categories": ["Words"], "tags": ["a", "b"],
        "source_tool": "coldsnap", "source_ref": "wp:example.com/?p=99",
    }, assets=[dict(a, alt="hero", width=4000, height=3000)])
    assert summ == {"post_id": 1234, "assets": 1}

    p = L.post(SITE, 1234)
    assert p["title"] == "An Essay"
    assert p["categories"] == ["Words"] and p["tags"] == ["a", "b"]  # JSON round-trip
    assert "[mosaic:9]" in p["body"]

    got = L.posts(SITE, site_mode="smacktalk")
    assert len(got) == 1 and got[0]["post_id"] == 1234
    assert L.posts(SITE, source_tool="nobody") == []

    imgs = L.assets_for(SITE, 1234)
    assert len(imgs) == 1 and imgs[0]["alt"] == "hero" and imgs[0]["width"] == 4000
    fpath = L.asset_file(SITE, a["asset_id"])
    assert fpath and os.path.isfile(fpath)


def test_record_post_is_idempotent():
    L = _fresh_home()
    for _ in range(3):
        L.record_post(SITE, {"post_id": 7, "title": "T"}, assets=[])
    assert len(L.posts(SITE)) == 1, "re-recording the same post_id must not duplicate"
    assert L.has_source_ref(SITE, "wp:x/?p=1") is False
    L.record_post(SITE, {"post_id": 8, "title": "U", "source_ref": "wp:x/?p=1"})
    assert L.has_source_ref(SITE, "wp:x/?p=1") is True  # resume ledger


def test_missing_post_id_raises():
    L = _fresh_home()
    try:
        L.record_post(SITE, {"title": "no id"})
    except ValueError:
        pass
    else:
        raise AssertionError("record_post must require post_id")


def test_content_tables_do_not_disturb_vocabulary_catalog():
    L = _fresh_home()
    # Simulate a pre-existing vocabulary-only catalog, then use the new content API.
    L.sync_from_sybu_data(SITE, {"categories": [{"name": "Nature"}],
                                 "site_mode": "photoblog"})
    L.record_post(SITE, {"post_id": 1, "title": "x"})
    assert L.categories(SITE) == ["Nature"], "vocabulary must survive content writes"
    assert L.site_mode(SITE) == "photoblog"
    assert L.post(SITE, 1)["title"] == "x"


if __name__ == "__main__":
    test_store_media_dedupes_by_sha256_and_writes_file()
    test_record_post_and_offline_read()
    test_record_post_is_idempotent()
    test_missing_post_id_raises()
    test_content_tables_do_not_disturb_vocabulary_catalog()
    print("ok")

# ===== SNAPSMACK EOF =====
