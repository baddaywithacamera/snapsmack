import io
import json
import pathlib
import sys

HUB = pathlib.Path(__file__).resolve().parents[1]
SHARED = HUB.parent / "_shared"
for folder in (HUB, SHARED):
    if str(folder) not in sys.path:
        sys.path.insert(0, str(folder))

from slapper_qt import smackthemup_publish as publish


class Reply:
    def __init__(self, data, headers=None):
        self.data = json.dumps(data).encode()
        self.headers = headers or {}
    def __enter__(self): return self
    def __exit__(self, *_args): return False
    def read(self, size=-1): return self.data if size < 0 else self.data[:size]


def test_capabilities_requires_correct_mode(monkeypatch):
    monkeypatch.setattr(publish, "_open", lambda *_a, **_k: Reply({
        "ok": True, "site_mode": "smackthemup", "federation": False,
        "albums": [], "categories": []}))
    result = publish.capabilities("https://photos.example", "a" * 64)
    assert result["site_mode"] == "smackthemup"


def test_publish_photo_uses_bearer_multipart_and_public_record(monkeypatch, tmp_path):
    photo = tmp_path / "photo.jpg"; photo.write_bytes(b"jpeg")
    seen = []
    def fake(request, **_kwargs):
        seen.append(request)
        route = request.full_url
        if "capabilities" in route:
            return Reply({"ok": True, "site_mode": "smackthemup", "federation": False})
        if "upload" in route:
            return Reply({"ok": True, "path": "img_uploads/2026/09/photo.jpg"})
        return Reply({"ok": True, "url": "https://photos.example/test"})
    monkeypatch.setattr(publish, "_open", fake)
    result, _caps = publish.publish_photo(
        "https://photos.example", "b" * 64, str(photo), title="Test",
        idempotency_key="stable")
    assert result["url"].endswith("/test")
    assert seen[1].headers["Content-type"].startswith("multipart/form-data;")
    record = json.loads(seen[2].data)
    assert record["visibility"] == "public"
    assert record["idempotency_key"] == "stable"
    assert all(req.headers["Authorization"] == "Bearer " + "b" * 64 for req in seen)


def test_refuses_plain_http_before_credentials_leave_machine():
    try:
        publish.capabilities("http://photos.example", "c" * 64)
    except publish.PublishError as error:
        assert "HTTPS" in str(error)
    else:
        raise AssertionError("plain HTTP was accepted")


def test_cross_host_redirect_is_refused_before_key_can_follow():
    from urllib.request import Request
    handler = publish._SameHostRedirect()
    request = Request("https://photos.example/api.php?route=smackthemup/upload",
                      headers={"Authorization": "Bearer " + "d" * 64})
    try:
        handler.redirect_request(request, None, 302, "Found", {},
                                 "https://attacker.example/collect")
    except publish.PublishError as error:
        assert "key was not sent" in str(error)
    else:
        raise AssertionError("cross-host publishing redirect was accepted")


def test_same_host_different_port_redirect_is_refused():
    from urllib.request import Request
    handler = publish._SameHostRedirect()
    request = Request("https://photos.example/api.php?route=smackthemup/upload")
    try:
        handler.redirect_request(request, None, 302, "Found", {},
                                 "https://photos.example:8443/collect")
    except publish.PublishError:
        pass
    else:
        raise AssertionError("cross-port publishing redirect was accepted")


def test_oversized_site_reply_is_refused(monkeypatch):
    monkeypatch.setattr(publish, "_open", lambda *_a, **_k: Reply(
        {"ok": True}, {"Content-Length": str(publish.MAX_RESPONSE_BYTES + 1)}))
    try:
        publish.capabilities("https://photos.example", "e" * 64)
    except publish.PublishError as error:
        assert "too large" in str(error)
    else:
        raise AssertionError("oversized publishing response was accepted")


# ===== SNAPSMACK EOF =====
