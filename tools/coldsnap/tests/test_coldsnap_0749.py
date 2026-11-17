"""Tests for COLD SNAP 0.7.49-test: safe slug-only update resolution + confirmation."""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

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

    def get(self, url, params=None, timeout=30):
        self.calls.append(("GET", url, params))
        if "smackpress/posts" in url:
            return _MockResponse(200, {"posts": self.existing_posts})
        return _MockResponse(200, {})

    def post(self, url, json=None, files=None, data=None, timeout=60):
        self.calls.append(("POST", url, json))
        if "smackpress/posts" in url:
            pid = int((json or {}).get("post_id") or 999)
            return _MockResponse(200, {"post_id": pid, "slug": (json or {}).get("slug", "same-title")})
        return _MockResponse(404, {})


def test_blank_slug_with_same_title_never_overwrites_existing_post(monkeypatch):
    monkeypatch.setattr("sumna_post._produce_library", lambda *a, **kw: None)
    draft = O.Draft(
        draft_id="d-new",
        kind=O.KIND_SMACKTALK,
        mode="smacktalk",
        title="Same Title",
        slug="",
        caption="Brand new essay body.",
        img_status="published",
        remote_post_id=0,
        images=[],
    )
    sess = _MockSession(existing_posts=[{"id": 42, "slug": "same-title", "title": "Same Title"}])
    poster = SmacktalkPoster("https://example.test", "k" * 64, session=sess)
    res = poster.sync_smacktalk(draft)

    assert res.ok, res.message
    assert res.remote_post_id == 999
    post_calls = [c for c in sess.calls if c[0] == "POST" and "smackpress/posts" in c[1]]
    assert len(post_calls) == 1
    assert "post_id" not in post_calls[0][2]


def test_explicit_slug_match_fails_closed_without_confirmation(monkeypatch):
    monkeypatch.setattr("sumna_post._produce_library", lambda *a, **kw: None)
    draft = O.Draft(
        draft_id="d-upd",
        kind=O.KIND_SMACKTALK,
        mode="smacktalk",
        title="Same Title",
        slug="same-title",
        caption="Updated essay body.",
        img_status="published",
        remote_post_id=0,
        images=[],
    )
    sess = _MockSession(existing_posts=[{"id": 42, "slug": "same-title", "title": "Same Title"}])
    poster = SmacktalkPoster("https://example.test", "k" * 64, session=sess)
    res = poster.sync_smacktalk(draft)

    assert not res.ok
    assert "confirm" in res.message.lower()
    post_calls = [c for c in sess.calls if c[0] == "POST" and "smackpress/posts" in c[1]]
    assert post_calls == []


def test_explicit_slug_match_updates_when_confirmed(monkeypatch):
    monkeypatch.setattr("sumna_post._produce_library", lambda *a, **kw: None)
    draft = O.Draft(
        draft_id="d-upd2",
        kind=O.KIND_SMACKTALK,
        mode="smacktalk",
        title="Same Title",
        slug="same-title",
        caption="Updated essay body.",
        img_status="published",
        remote_post_id=0,
        images=[],
    )
    sess = _MockSession(existing_posts=[{"id": 42, "slug": "same-title", "title": "Same Title"}])
    poster = SmacktalkPoster("https://example.test", "k" * 64, session=sess)
    res = poster.sync_smacktalk(draft, confirm_overwrite_fn=lambda p: p["id"] == 42)

    assert res.ok, res.message
    assert res.remote_post_id == 42
    post_calls = [c for c in sess.calls if c[0] == "POST" and "smackpress/posts" in c[1]]
    assert len(post_calls) == 1
    assert post_calls[0][2]["post_id"] == 42


# ===== SNAPSMACK EOF =====
