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
        self.upload_names = []
        self.post_calls = []
        self.last_json = None
    def post(self, url, files=None, data=None, json=None, timeout=None):
        self.post_calls.append(url)
        if "smackpress/media/upload" in url:
            self.uploads += 1
            self.upload_names.append(files["file"][0])
            return FakeResp(200, {"image_id": 100 + self.uploads})
        if "smackpress/posts" in url:
            self.last_json = json
            return FakeResp(200, {"post_id": 777, "slug": "s", "url": "u"})
        if "smackpress/pages" in url:
            self.last_json = json
            return FakeResp(200, {"page_id": 778, "slug": "the-idea", "url": "page-u"})
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
        DraftImage(local_path=p3, filename="c.jpg", sort_position=2, is_cover=False,
                   is_signature=True),
    ]

    # 1. Happy path: all 3 uploaded, post created, success + post_id.
    fake = FakeSession()
    poster = P.SmacktalkPoster("https://smacktalk.example", "deadbeef", session=fake)
    res = poster.sync_smacktalk(draft)
    assert res.ok, res.message
    assert res.remote_post_id == 777, res.remote_post_id
    assert fake.uploads == 3, fake.uploads
    n += 1

    # A non-destructive edit uploads under the derivative's real extension,
    # not the untouched source's old name.
    edited_path = os.path.join(tmp, "edited-version.jpg"); _img(edited_path)
    edited = DraftImage(local_path=edited_path, original_path=p1,
                        filename="original.png", edit_flip_h=True)
    edited_fake = FakeSession()
    edited_poster = P.SmacktalkPoster(
        "https://smacktalk.example", "deadbeef", session=edited_fake)
    assert edited_poster._upload_one(edited) == 101
    assert edited_fake.upload_names == ["edited-version.jpg"], edited_fake.upload_names
    n += 1

    # A WordPress Page uses the static-page endpoint and cannot become a
    # long-form row.  It may also be a text-only page.
    page = Draft(draft_id="page1", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                 destination_type="page", title="The Idea", slug="the-idea",
                 caption="<p>Preserve this.</p>", img_status="published")
    page_fake = FakeSession()
    page_poster = P.SmacktalkPoster("https://smacktalk.example", "deadbeef",
                                    session=page_fake)
    page_result = page_poster.sync_smacktalk(page)
    assert page_result.ok and page_result.remote_post_id == 778, page_result.message
    assert any("smackpress/pages" in url for url in page_fake.post_calls), page_fake.post_calls
    assert not any("smackpress/posts" in url for url in page_fake.post_calls), page_fake.post_calls
    assert page_fake.last_json == {
        "title": "The Idea", "slug": "the-idea",
        "content": "<p>Preserve this.</p>", "status": "published",
    }, page_fake.last_json
    assert page.validate() == [], page.validate()
    n += 1

    # 2. Payload: bucket in upload order [101,102,103]; cover = the 2nd image's id (102).
    body = fake.last_json
    assert body["bucket_image_ids"] == [101, 102, 103], body["bucket_image_ids"]
    assert body["featured_image_id"] == 102, body["featured_image_id"]
    assert body["title"] == "Photo Essay" and body["content"] == "the body text", body
    assert body["status"] == "published", body
    assert body["tags"] == "street night", body["tags"]  # # stripped, whitespace-normalised
    assert body["signature_image_id"] == 103, body
    assert body["colophon"] == "", body
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

    # 7. Revising a published post reuses its Gallery ids, keeps its date and
    # sends post_id so the server updates instead of creating a duplicate.
    revised = Draft(draft_id="remote", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                    title="Revised essay", caption="new words",
                    post_date="2024-07-06 12:00:00", remote_post_id=42,
                    category_ids=[3], album_ids=[8])
    revised.images = [
        DraftImage(local_path=p1, filename="a.jpg", remote_image_id=501,
                   is_cover=True),
        DraftImage(local_path=p2, filename="b.jpg", remote_image_id=502),
    ]
    remote_fake = FakeSession()
    result = P.SmacktalkPoster("https://smacktalk.example", "deadbeef",
                               session=remote_fake).sync_smacktalk(revised)
    assert result.ok and remote_fake.uploads == 0, (result.message, remote_fake.uploads)
    assert remote_fake.last_json["post_id"] == 42
    assert remote_fake.last_json["bucket_image_ids"] == [501, 502]
    assert remote_fake.last_json["date"] == "2024-07-06 12:00:00"
    assert remote_fake.last_json["cat_ids"] == [3]
    assert remote_fake.last_json["album_ids"] == [8]
    n += 1

    # 8. A composed mosaic can select/reorder bucket positions and carry an
    # explicit three-photo layout through to mosaic creation.
    calls = []
    poster.create_mosaic = (
        lambda ids, title="Mosaic", gap=4, layout="asymmetric", gallery_shuffle=False: (
            calls.append((list(ids), layout, gallery_shuffle)) or (55, "[mosaic:55]")))
    resolved, mids = poster._resolve_mosaics(
        "before\n[mosaic=3,1,2 layout=one-top]\nafter", [101, 102, 103, 104], draft)
    assert resolved == "before\n[mosaic:55]\nafter", resolved
    assert mids == [55] and calls == [([103, 101, 102], "one-top", False)], calls
    n += 1

    # 9. A four-photo bucket can use only its bottom three, in their chosen order.
    calls.clear()
    resolved, mids = poster._resolve_mosaics(
        "[mosaic=2,3,4 layout=three-across]", [101, 102, 103, 104], draft)
    assert resolved == "[mosaic:55]", resolved
    assert mids == [55] and calls == [([102, 103, 104], "three-across", False)], calls
    n += 1

    # 10. SS-1185: an owner-random gallery. The `shuffle` keyword the WordPress
    # importer writes reaches create_mosaic as gallery_shuffle=True, and the ids
    # are still handed over IN THE AUTHORED ORDER - the randomising is the
    # site's presentation step, not something the poster does on the way out. A
    # poster that scrambled here would destroy the arrangement irrecoverably.
    calls.clear()
    resolved, mids = poster._resolve_mosaics(
        "[mosaic=1,2,3 layout=rows shuffle]", [101, 102, 103, 104], draft)
    assert resolved == "[mosaic:55]", resolved
    assert calls == [([101, 102, 103], "rows", True)], calls
    n += 1

    # 11. The same marker without the keyword is unchanged: OFF, not absent.
    # This is the pair that proves the keyword is read rather than ignored.
    calls.clear()
    poster._resolve_mosaics("[mosaic=1,2,3 layout=rows]", [101, 102, 103], draft)
    assert calls == [([101, 102, 103], "rows", False)], calls
    n += 1

    # 12. shuffle with no layout, and shuffle on a whole-bucket marker: both are
    # shapes the importer can emit, and neither may lose the flag.
    calls.clear()
    poster._resolve_mosaics("[mosaic shuffle]", [101, 102], draft)
    assert calls == [([101, 102], "asymmetric", True)], calls
    n += 1

    return n


def _bucket_img_checks():
    """Bucket images keep plain/gallery intent when permanent ids replace positions."""
    from sumna_post import SmacktalkPoster
    p = SmacktalkPoster.__new__(SmacktalkPoster)
    out = p._resolve_bucket_images("a [img:bucket:2|wall|left] b [img:bucket:9] c [img:77|full|center]", [101, 102, 103])
    assert out == "a [img:102|wall|left] b  c [img:77|full|center]", out
    assert p._resolve_bucket_images("[img:gbucket:1|full|center|100]", [101]) == \
        "[img:g101|full|center|100]"
    assert p._resolve_bucket_images("plain", []) == "plain"
    return 3


def _mosaic_token_checks():
    """Every layout the site accepts must resolve — Columns/Rows used to go out as text."""
    from sumna_post import SmacktalkPoster
    rx = SmacktalkPoster._MOSAIC_TOKEN
    for name in SmacktalkPoster._MOSAIC_LAYOUTS:
        m = rx.search(f"[mosaic=1,2 layout={name}]")
        assert m and m.group(2) == name, name
    assert rx.search("[mosaic]") and rx.search("[mosaic:bucket]")
    assert not rx.search("[mosaic:123]"), "numeric mosaic ids are the site's, left alone"

    # SS-1185: the shuffle keyword, in every shape the importer can write, and
    # asserted ABSENT where it is not written. Group 3 is the flag.
    for text in ("[mosaic=1,2 layout=rows shuffle]", "[mosaic shuffle]",
                 "[mosaic=1,2 shuffle]", "[mosaic=1,2 layout=rows SHUFFLE]"):
        m = rx.search(text)
        assert m and m.group(3), text
    for text in ("[mosaic=1,2 layout=rows]", "[mosaic]", "[mosaic:bucket]"):
        m = rx.search(text)
        assert m and not m.group(3), text
    # The layout must survive alongside it rather than swallowing the keyword.
    m = rx.search("[mosaic=1,2 layout=one-top shuffle]")
    assert m and m.group(2) == "one-top" and m.group(3), m.groups()
    # A word that merely starts the same is not the flag.
    m = rx.search("[mosaic=1,2 layout=rows shuffled]")
    assert (m is None) or not m.group(3), "shuffled is not shuffle"
    return 8


def _gap_checks():
    """SS-1301: the mosaic gap, with ZERO meaning zero, on the PUBLISH path.

    `int(gap or 4)` and `int(getattr(draft, "mosaic_gap", 4) or 4)` both read a
    saved 0 as 4, because `0 or 4` is 4. snap_mosaics.gap allows 0 to 20,
    core/parser.php clamps to the same range and ss-engine-mosaic.js honours
    it, so a flush grid of photographs was a layout COLD SNAP could not send --
    not a preview nit but what the site ends up storing.

    Muse found the same expression in the BIGGIE preview. This is the rest of
    the path, which is why it is checked here as well as there.
    """
    n = 0
    assert P._gap_value(0) == 0, "a gap of 0 must stay 0"
    assert P._gap_value("0") == 0, "a gap of '0' must stay 0"
    assert P._gap_value(0.0) == 0, "a gap of 0.0 must stay 0"
    n += 3
    assert P._gap_value(12) == 12 and P._gap_value("12") == 12
    n += 1
    # Only absence falls back to the schema default.
    assert P._gap_value(None) == 4 and P._gap_value("") == 4
    assert P._gap_value("wide") == 4, "garbage falls back rather than raising"
    n += 2
    # This layer clamps, because it is what leaves the machine.
    assert P._gap_value(99) == 20 and P._gap_value(-5) == 0
    n += 1

    # AND THE PAYLOAD ITSELF, which is what the site stores. A mutation run
    # put the old `int(gap or 4)` back on this line and nothing failed: every
    # check above stubs create_mosaic out, so the draft -> gap step was proved
    # and the gap -> payload step was not. This drives the real method.
    class _MosaicSession:
        def __init__(self):
            self.headers = {}
            self.sent = []

        def post(self, url, files=None, data=None, json=None, timeout=None):
            if "smackpress/mosaics" in url:
                self.sent.append(json)
                return FakeResp(200, {"mosaic_id": 9})
            return FakeResp(404, {})

        def get(self, url, timeout=None, params=None):
            return FakeResp(200, {})

    for value, expected in ((0, 0), (4, 4), (20, 20), (99, 20), (None, 4),
                            ("", 4), ("7", 7)):
        sess = _MosaicSession()
        mid, token = P.SmacktalkPoster("https://x", "k", session=sess).create_mosaic(
            [1, 2, 3], gap=value)
        assert sess.sent and sess.sent[0]["gap"] == expected, (value, sess.sent)
        assert (mid, token) == (9, "[mosaic:9]"), (mid, token)
        n += 1

    # And end to end: a draft carrying gap 0 reaches create_mosaic as 0.
    from sumna_offline import Draft, KIND_SMACKTALK, MODE_SMACKTALK
    seen = []
    poster = P.SmacktalkPoster.__new__(P.SmacktalkPoster)
    poster.create_mosaic = (
        lambda ids, title="Mosaic", gap=4, layout="asymmetric",
        gallery_shuffle=False: (seen.append(gap) or (7, "[mosaic:7]")))
    for value, expected in ((0, 0), (4, 4), (11, 11), (None, 4)):
        seen.clear()
        draft = Draft(draft_id="g", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                      title="t", caption="[mosaic]", tags="")
        draft.mosaic_gap = value
        poster._resolve_mosaics("[mosaic]", [1, 2, 3], draft)
        assert seen == [expected], (value, seen, expected)
        n += 1
    return n


if __name__ == "__main__":
    passed = (_checks() + _mosaic_token_checks() + _bucket_img_checks()
              + _gap_checks())
    print(f"OK - {passed} checks passed")
# ===== SNAPSMACK EOF =====
