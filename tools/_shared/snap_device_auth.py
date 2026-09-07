"""Device-bound authorization shared by SNAP HQ, SNAP SLAPPER and LEWK AGAIN.

SNAP HQ owns activation and renewal.  Its Ed25519 private key lives in Windows
Credential Manager; companion apps only read and verify the CMS-signed cached
entitlement.  The online CMS remains authoritative.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

from __future__ import annotations

import base64
import hashlib
import json
import locale
import os
import platform
import secrets
import socket
import tempfile
import time
from datetime import datetime
from urllib.parse import urlparse

import requests
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey, Ed25519PublicKey
from cryptography.hazmat.primitives.serialization import Encoding, NoEncryption, PrivateFormat, PublicFormat

import snap_home
import snap_native_creds

_CRED_SITE = "https://desktop-auth.snapsmack.local"
_CRED_PRIVATE = "device-private-key"
_STATE_FILE = "desktop_entitlement.json"


def _b64u(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).decode("ascii").rstrip("=")


def _unb64u(text: str) -> bytes:
    return base64.urlsafe_b64decode(text + "=" * ((4 - len(text) % 4) % 4))


def _state_path() -> str:
    return os.path.join(snap_home.auth_dir(), _STATE_FILE)


def _read_state() -> dict:
    try:
        with open(_state_path(), "r", encoding="utf-8") as handle:
            value = json.load(handle)
        return value if isinstance(value, dict) else {}
    except (OSError, ValueError):
        return {}


def _write_state(value: dict) -> None:
    directory = snap_home.auth_dir()
    fd, temporary = tempfile.mkstemp(prefix="desktop-auth-", suffix=".tmp", dir=directory)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(value, handle, separators=(",", ":"), sort_keys=True)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, _state_path())
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def _private_key() -> Ed25519PrivateKey:
    encoded = snap_native_creds.get_site(_CRED_SITE, _CRED_PRIVATE)
    if encoded:
        return Ed25519PrivateKey.from_private_bytes(base64.b64decode(encoded))
    key = Ed25519PrivateKey.generate()
    raw = key.private_bytes(Encoding.Raw, PrivateFormat.Raw, NoEncryption())
    if not snap_native_creds.set_site(_CRED_SITE, _CRED_PRIVATE, base64.b64encode(raw).decode("ascii")):
        raise RuntimeError("SNAP HQ could not protect its device key in Windows Credential Manager.")
    return key


def device_metadata(hq_version: str = "") -> dict:
    language = locale.getlocale()[0] or ""
    timezone = datetime.now().astimezone().tzname() or ""
    return {
        "device_name": socket.gethostname(), "locale": language, "timezone": timezone,
        "os": f"{platform.system()} {platform.release()} {platform.machine()}", "hq_version": hq_version,
    }


def _endpoint(site_url: str, action: str) -> str:
    return site_url.rstrip("/") + "/api.php?route=desktop-auth/" + action


def _verify_and_store(site_url: str, entitlement: dict, *, allow_new_server_key: bool) -> dict:
    payload_text = str(entitlement.get("payload", ""))
    signature = _unb64u(str(entitlement.get("signature", "")))
    public64 = str(entitlement.get("server_public_key", ""))
    state = _read_state()
    pinned = str(state.get("server_public_key", ""))
    if pinned and public64 != pinned:
        raise RuntimeError("The CMS authorization signing key changed. Refusing an untrusted entitlement.")
    if not pinned and not allow_new_server_key:
        raise RuntimeError("No CMS signing key is pinned. Activate this SNAP HQ first.")
    public_raw = base64.b64decode(public64, validate=True)
    Ed25519PublicKey.from_public_bytes(public_raw).verify(signature, payload_text.encode("ascii"))
    payload = json.loads(_unb64u(payload_text).decode("utf-8"))
    private = _private_key()
    own_public = private.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
    if payload.get("fingerprint") != hashlib.sha256(own_public).hexdigest():
        raise RuntimeError("The entitlement belongs to a different computer.")
    now = int(time.time())
    previous_seen = int(state.get("last_clock", 0) or 0)
    if previous_seen and now + 300 < previous_seen:
        raise RuntimeError("The system clock moved backwards. Go online in SNAP HQ to verify authorization.")
    stored = {"site_url": site_url.rstrip("/"), "server_public_key": public64,
              "entitlement": entitlement, "payload": payload, "last_clock": now,
              "verified_at": now}
    _write_state(stored)
    return decision(stored)


def activate(site_url: str, activation_code: str, hq_version: str = "") -> dict:
    key = _private_key()
    public = key.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
    body = {"activation_code": activation_code.strip(), "public_key": base64.b64encode(public).decode("ascii"),
            **device_metadata(hq_version)}
    response = requests.post(_endpoint(site_url, "activate"), json=body, timeout=30)
    data = response.json()
    if not response.ok or not data.get("ok"):
        raise RuntimeError(str(data.get("error") or f"Activation failed ({response.status_code})."))
    return _verify_and_store(site_url, data["entitlement"], allow_new_server_key=True)


def enroll(site_url: str, hub_key: str, username: str, password: str,
           totp_code: str, hq_version: str = "") -> dict:
    """Bind this device in one step after password + TOTP verification."""
    key = _private_key()
    public = key.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)
    body = {
        "username": username.strip(), "password": password,
        "totp_code": totp_code.strip(),
        "public_key": base64.b64encode(public).decode("ascii"),
        **device_metadata(hq_version),
    }
    response = requests.post(
        _endpoint(site_url, "enroll"), json=body,
        headers={"Authorization": f"Bearer {hub_key.strip()}"}, timeout=30)
    try:
        data = response.json()
    except ValueError as exc:
        raise RuntimeError(f"The CMS returned an unexpected response ({response.status_code}).") from exc
    if not response.ok or not data.get("ok"):
        detail = str(data.get("error") or data.get("message") or "").strip()
        if response.status_code == 404 and detail == "Unknown API endpoint":
            raise RuntimeError("This CMS has not yet been updated for seamless device authorization.")
        raise RuntimeError(detail or f"Authorization failed ({response.status_code}).")
    return _verify_and_store(site_url, data["entitlement"], allow_new_server_key=True)


def refresh(hq_version: str = "") -> dict:
    state = _read_state()
    site_url = str(state.get("site_url", ""))
    device_id = str((state.get("payload") or {}).get("device_id", ""))
    if not site_url or not device_id:
        raise RuntimeError("This SNAP HQ has not been authorized.")
    key = _private_key(); body = device_metadata(hq_version)
    raw = json.dumps(body, separators=(",", ":"), sort_keys=True).encode("utf-8")
    url = _endpoint(site_url, "status"); path = urlparse(url).path
    stamp = str(int(time.time())); nonce = secrets.token_urlsafe(24)
    proof = b"\n".join([b"POST", path.encode(), stamp.encode(), nonce.encode(), hashlib.sha256(raw).hexdigest().encode()])
    headers = {"Content-Type":"application/json", "X-Snap-Device":device_id, "X-Snap-Timestamp":stamp,
               "X-Snap-Nonce":nonce, "X-Snap-Signature":_b64u(key.sign(proof))}
    response = requests.post(url, data=raw, headers=headers, timeout=30); data=response.json()
    if not response.ok or not data.get("ok"):
        raise RuntimeError(str(data.get("error") or f"Authorization check failed ({response.status_code})."))
    return _verify_and_store(site_url, data["entitlement"], allow_new_server_key=False)


def decision(state: dict | None = None) -> dict:
    """Verify the cached signature and return full/grace/restricted state."""
    state = state or _read_state(); entitlement = state.get("entitlement") or {}
    try:
        payload_text = str(entitlement["payload"]); public64 = str(state["server_public_key"])
        Ed25519PublicKey.from_public_bytes(base64.b64decode(public64, validate=True)).verify(
            _unb64u(str(entitlement["signature"])), payload_text.encode("ascii"))
        payload = json.loads(_unb64u(payload_text).decode("utf-8")); now=int(time.time())
        if now <= int(payload["expires_at"]): status="full"
        elif now <= int(payload["grace_ends_at"]): status="grace"
        else: status="restricted"
        return {"status":status,"authorized":status in ("full","grace"),"payload":payload,
                "site_url":state.get("site_url","")}
    except Exception as exc:
        return {"status":"restricted","authorized":False,"error":str(exc)}


def require_full(feature: str = "This feature") -> None:
    result = decision()
    if not result["authorized"]:
        raise PermissionError(f"{feature} requires a current CMS authorization in SNAP HQ.")

# ===== SNAPSMACK EOF =====
