"""A longform post with no photographs must send.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

SmacktalkPoster.sync_smacktalk() used to open with

    if not draft.images and not is_page:
        return SyncResult(False, message="no images on draft")

so an essay written with no picture was refused before it was ever sent, by
the desktop, with a message that read like a fault rather than a rule. Nothing
downstream needed the photograph: build_payload sends featured_image_id = None
and an empty bucket, and core/smackpress-api.php requires only a title.

These tests pin the three behaviours that replaced it:

  1. text-only longform reaches the network with a correct payload;
  2. a missing title is reported as a missing title, even when the draft also
     has no photographs (the old order reported the images and stayed silent
     about the title);
  3. the one case that genuinely still needs photographs -- a [mosaic]
     placeholder in the body -- is refused, and says which one it is, because
     _resolve_mosaics() leaves the token in the body when there is nothing to
     build a mosaic from and it would publish as literal text.

No network: the session is a stub that records the call and raises, so a
regression that starts posting from a unit test fails loudly instead of
reaching a site.
"""

import os
import sys



sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import sumna_offline as O  # noqa: E402
import sumna_post as P  # noqa: E402


class _Captured(Exception):
    """Raised instead of making a request, so nothing can leave the machine."""


class _StubSession:
    def __init__(self):
        self.url = None
        self.payload = None

    def post(self, url, **kwargs):
        self.url = url
        self.payload = kwargs.get("json")
        raise _Captured("stopped at the network boundary")


def _poster():
    poster = P.SmacktalkPoster.__new__(P.SmacktalkPoster)
    poster.key = "a-smackpress-key"
    poster.session = _StubSession()
    poster._route = lambda route: "https://example.invalid/" + route
    return poster


def _draft(title="An essay with no photographs", body="Para one.\n\nPara two."):
    draft = O.Draft(draft_id="t", kind="post", mode=O.MODE_SMACKTALK)
    draft.title = title
    draft.caption = body
    draft.images = []
    return draft


def test_text_only_longform_reaches_the_network_with_its_words_intact():
    poster = _poster()
    result = poster.sync_smacktalk(_draft())

    # It got as far as the POST; the stub is what stopped it, not a guard.
    assert result.ok is False
    assert "stopped at the network boundary" in result.message
    assert poster.session.url.endswith("smackpress/posts")

    payload = poster.session.payload
    assert payload["title"] == "An essay with no photographs"
    assert payload["content"] == "Para one.\n\nPara two."
    assert payload["bucket_image_ids"] == []
    assert payload["featured_image_id"] is None


def test_a_missing_title_is_reported_as_a_missing_title():
    poster = _poster()
    draft = _draft(title="   ")

    result = poster.sync_smacktalk(draft)

    assert result.ok is False
    assert "title" in result.message.lower()
    # The old guard answered this draft with "no images on draft".
    assert "image" not in result.message.lower()
    assert poster.session.url is None


def test_a_mosaic_with_no_photographs_is_refused_and_says_so():
    poster = _poster()
    draft = _draft(body="Para one.\n\n[mosaic]\n\nPara two.")

    result = poster.sync_smacktalk(draft)

    assert result.ok is False
    assert "MOSAIC" in result.message
    assert "photograph" in result.message.lower()
    # Nothing was sent: a stray [mosaic] token must never reach the site.
    assert poster.session.url is None


def test_the_mosaic_refusal_only_applies_when_the_body_asks_for_one():
    """Guards the obvious over-correction: refusing every text-only post again."""
    poster = _poster()

    result = poster.sync_smacktalk(_draft(body="No mosaic token here at all."))

    assert "MOSAIC" not in (result.message or "")
    assert poster.session.url is not None

# ===== SNAPSMACK EOF =====
