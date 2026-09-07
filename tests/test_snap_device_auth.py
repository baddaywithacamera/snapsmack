"""Offline verification tests for CMS-signed desktop entitlements.

SNAPSMACK_EOF_HEADER: last non-empty line must be # ===== SNAPSMACK EOF =====
"""

import base64
import json
import os
import sys
import time

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "tools", "_shared"))
import snap_device_auth


def _signed_state(expires, grace):
    server = Ed25519PrivateKey.generate()
    public = server.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
    payload = {"expires_at": expires, "grace_ends_at": grace, "capabilities": ["snap_slapper_full"]}
    encoded = snap_device_auth._b64u(json.dumps(payload).encode())
    entitlement = {"payload": encoded, "signature": snap_device_auth._b64u(server.sign(encoded.encode())),
                   "server_public_key": base64.b64encode(public).decode()}
    return {"server_public_key": entitlement["server_public_key"], "entitlement": entitlement}


def test_signed_entitlement_full_then_grace_then_restricted():
    now = int(time.time())
    assert snap_device_auth.decision(_signed_state(now + 60, now + 120))["status"] == "full"
    assert snap_device_auth.decision(_signed_state(now - 1, now + 60))["status"] == "grace"
    assert snap_device_auth.decision(_signed_state(now - 120, now - 1))["status"] == "restricted"


def test_tampered_entitlement_is_restricted():
    state = _signed_state(int(time.time()) + 60, int(time.time()) + 120)
    state["entitlement"]["payload"] += "A"
    assert snap_device_auth.decision(state)["authorized"] is False


def test_seamless_enroll_sends_stepup_once_and_stores_entitlement(monkeypatch):
    device_key = Ed25519PrivateKey.generate()
    seen = {}

    class Response:
        ok = True
        status_code = 200
        def json(self):
            return {"ok": True, "entitlement": {"payload": "p"}}

    def post(url, **kwargs):
        seen.update(url=url, **kwargs)
        return Response()

    monkeypatch.setattr(snap_device_auth, "_private_key", lambda: device_key)
    monkeypatch.setattr(snap_device_auth.requests, "post", post)
    monkeypatch.setattr(
        snap_device_auth, "_verify_and_store",
        lambda site, entitlement, **kwargs: {"authorized": True, "site_url": site})

    result = snap_device_auth.enroll(
        "https://example.test", "hub-secret", "owner", "password", "123456", "0.7.40")

    assert result["authorized"] is True
    assert seen["url"].endswith("/api.php?route=desktop-auth/enroll")
    assert seen["headers"]["Authorization"] == "Bearer hub-secret"
    assert seen["json"]["username"] == "owner"
    assert seen["json"]["password"] == "password"
    assert seen["json"]["totp_code"] == "123456"


def test_seamless_enroll_explains_missing_cms_handler(monkeypatch):
    class Response:
        ok = False
        status_code = 404

        def json(self):
            return {"status": "error", "message": "Unknown API endpoint"}

    monkeypatch.setattr(snap_device_auth, "_private_key", Ed25519PrivateKey.generate)
    monkeypatch.setattr(snap_device_auth.requests, "post", lambda *args, **kwargs: Response())

    try:
        snap_device_auth.enroll("https://example.test", "hub-secret", "owner", "password", "123456")
    except RuntimeError as exc:
        assert str(exc) == "This CMS has not yet been updated for seamless device authorization."
    else:
        raise AssertionError("missing CMS handler should fail clearly")

# ===== SNAPSMACK EOF =====
