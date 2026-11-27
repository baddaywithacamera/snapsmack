"""Sender regressions for standalone SMACKTALK mosaics.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical SNAPSMACK EOF marker.
"""
import json
from types import SimpleNamespace

import pytest

from sumna_offline import Draft, DraftImage, KIND_SMACKTALK, MODE_SMACKTALK
from sumna_post import SmacktalkPoster


class FixtureSite:
    def __init__(self):
        self.posts = []

    def post(self, url, json, timeout):
        if url.endswith("/mosaics"):
            return SimpleNamespace(
                status_code=200, raise_for_status=lambda: None,
                json=lambda: {"mosaic_id": 752, "shortcode": "[mosaic:752]"},
            )
        assert url.endswith("/posts")
        self.posts.append(json)
        return SimpleNamespace(status_code=200, json=lambda: {"post_id": 4}, raise_for_status=lambda: None)


def test_authored_mosaic_goes_over_the_wire_between_top_level_paragraphs(monkeypatch):
    site = FixtureSite()
    poster = SmacktalkPoster("https://example.test", "fixture", session=site)
    monkeypatch.setattr(poster, "_record_to_library", lambda *_: None)
    draft = Draft(draft_id="test", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                  title="Test", remote_post_id=4, slug="start-your-engines",
                  caption='Before 1 < 2\n\n[mosaic=1 layout=asymmetric]\n\n'
                          'After <a href="https://example.test/">link</a>',
                  images=[DraftImage(local_path="fixture.jpg", remote_image_id=81)])
    result = poster.sync_smacktalk(draft)
    assert result.ok, result.message
    assert site.posts[0]["post_id"] == 4
    assert site.posts[0]["content"] == (
        '<p>Before 1 &lt; 2</p>\n\n[mosaic:752]\n\n'
        '<p>After <a href="https://example.test/">link</a></p>'
    )


def test_legacy_split_paragraph_retains_valid_markup_after_prose_edit():
    edited = '<p>[mosaic:752]Corrected <em>prose</em>.</p>\n<p>Leave this paragraph.</p>'
    assert SmacktalkPoster._standalone_mosaic_body(edited) == (
        '[mosaic:752]\n\n<p>Corrected <em>prose</em>.</p>\n<p>Leave this paragraph.</p>'
    )


def test_mosaic_only_posts_stay_out_of_cms_automatic_paragraph_conversion():
    assert SmacktalkPoster._standalone_mosaic_body("[mosaic:752]") == "<div></div>\n\n[mosaic:752]"


@pytest.mark.parametrize(("change", "expected"), [
    ("untouched", "<p>[mosaic:751]Prose.</p>\n<p>Other paragraph.</p>\n"),
    ("edit_prose", "[mosaic:751]\n\n<p>New &lt; note.</p>\n<p>Other paragraph.</p>\n"),
    ("edit_mosaic", "[mosaic:752]\n\n<p>Prose.</p>\n<p>Other paragraph.</p>\n"),
    ("remove_mosaic", "<p>Prose.</p>\n<p>Other paragraph.</p>\n"),
    ("remove_prose", "[mosaic:751]\n\n<p>Other paragraph.</p>\n"),
])
def test_imported_pair_roundtrip_and_half_edits(change, expected, monkeypatch):
    wrap = {"open": "<p>", "close": "</p>", "group": "p1"}
    media = {"type": "mosaic", "src": "<p>[mosaic:751]", "sep": "", "wrap": wrap}
    prose = {"type": "para", "text": "Prose.", "src": "Prose.</p>", "sep": "\n", "wrap": wrap}
    blocks = [media, prose, {"type": "para", "src": "<p>Other paragraph.</p>", "sep": "\n"}]
    if change == "edit_prose":
        prose.pop("src")
        prose["text"] = "New < note."
    elif change == "edit_mosaic":
        media.pop("src")
        media["order"] = [1]
    elif change == "remove_mosaic":
        blocks.remove(media)
    elif change == "remove_prose":
        blocks.remove(prose)
    site = FixtureSite()
    poster = SmacktalkPoster("https://example.test", "fixture", session=site)
    monkeypatch.setattr(poster, "_record_to_library", lambda *_: None)
    draft = Draft(draft_id="test-import", kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
                  title="Test", remote_post_id=4, slug="start-your-engines",
                  caption="<p>Stale caption cache.</p>", body_blocks=json.dumps(blocks),
                  images=[DraftImage(local_path="fixture.jpg", remote_image_id=81)])
    result = poster.sync_smacktalk(draft)
    assert result.ok, result.message
    assert site.posts[0]["content"] == expected


# ===== SNAPSMACK EOF =====
