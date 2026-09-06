"""Offline verification tests for CMS-signed desktop entitlements."""

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

# ===== SNAPSMACK EOF =====
