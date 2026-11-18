# SNAPSMACK_EOF_HEADER
"""SS-1418 — the desktop half: a cached entitlement must not outlive its facts.

decision() is what actually gates features. require_full() calls it, and it
reads the CACHED entitlement without going near the server, so two things
Muse found live here:

  1. It never checked last_clock. _verify_and_store() refuses an entitlement
     when the clock has moved backwards, but that only runs online. Winding
     the system clock back turned an expired cached entitlement into a
     current one, offline, with no network involved at all.
  2. A revoked device kept working until its own expiry. That part cannot be
     fixed inside decision() -- a signed offline token cannot learn it was
     revoked -- so it is fixed in refresh(), which drops the cached copy the
     moment the site answers 403.

These tests sign real entitlements with a real Ed25519 key and drive the real
functions. Nothing is mocked except the clock and the HTTP response.

NOT CHECKED here: a live site, a real enrolled device, an installed build.
"""

import base64
import json
import os
import sys
import time

import pytest

HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, HUB)
sys.path.insert(0, os.path.join(os.path.dirname(HUB), "_shared"))

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey  # noqa: E402
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat  # noqa: E402

import snap_device_auth  # noqa: E402

DAY = 86400
NOW = 1795000000


@pytest.fixture()
def auth_home(tmp_path, monkeypatch):
    """Point the module's state file at a scratch directory."""
    directory = tmp_path / "auth"
    directory.mkdir()
    monkeypatch.setattr(snap_device_auth.snap_home, "auth_dir", lambda: str(directory))
    return directory


def _signed_state(server_key, *, expires_at, grace_ends_at, last_clock, verified_at,
                  fingerprint="fp"):
    """A cached state holding a genuinely signed entitlement."""
    payload = {
        "v": 1, "device_id": "dev-1", "fingerprint": fingerprint,
        "issued_at": verified_at, "term_started_at": verified_at,
        "expires_at": expires_at, "grace_ends_at": grace_ends_at,
        "token_version": 1, "capabilities": ["snap_slapper_full"],
    }
    encoded = snap_device_auth._b64u(
        json.dumps(payload, separators=(",", ":"), sort_keys=True).encode("utf-8"))
    signature = snap_device_auth._b64u(server_key.sign(encoded.encode("ascii")))
    public64 = base64.b64encode(
        server_key.public_key().public_bytes(Encoding.Raw, PublicFormat.Raw)).decode("ascii")
    return {
        "site_url": "https://example.test",
        "server_public_key": public64,
        "entitlement": {"payload": encoded, "signature": signature,
                        "server_public_key": public64},
        "payload": payload,
        "last_clock": last_clock,
        "verified_at": verified_at,
    }


@pytest.fixture()
def server_key():
    return Ed25519PrivateKey.generate()


# ── the clock rollback ───────────────────────────────────────────────────────

def test_a_current_entitlement_is_full(monkeypatch, server_key):
    state = _signed_state(server_key, expires_at=NOW + 30 * DAY,
                          grace_ends_at=NOW + 44 * DAY,
                          last_clock=NOW - DAY, verified_at=NOW - DAY)
    monkeypatch.setattr(time, "time", lambda: NOW)
    result = snap_device_auth.decision(state)
    assert result["status"] == "full"
    assert result["authorized"] is True


def test_winding_the_clock_back_does_not_revive_an_expired_entitlement(
        monkeypatch, server_key):
    """The whole point. Expired on the real calendar, 'current' if you lie."""
    expires = NOW - 30 * DAY
    state = _signed_state(server_key, expires_at=expires,
                          grace_ends_at=expires + 14 * DAY,
                          last_clock=NOW, verified_at=NOW - 60 * DAY)

    # Someone sets the machine clock back to before the term ended.
    rolled_back = expires - DAY
    monkeypatch.setattr(time, "time", lambda: rolled_back)

    result = snap_device_auth.decision(state)
    assert result["authorized"] is False, (
        "a rolled-back clock reactivated an expired entitlement")
    assert result["status"] == "restricted"
    assert result["reason"] == "clock_rollback"


def test_a_small_backwards_drift_is_tolerated(monkeypatch, server_key):
    """NTP nudges clocks backwards by seconds. That is not an attack, and
    treating it as one would restrict an owner for keeping good time."""
    state = _signed_state(server_key, expires_at=NOW + 30 * DAY,
                          grace_ends_at=NOW + 44 * DAY,
                          last_clock=NOW, verified_at=NOW)
    monkeypatch.setattr(time, "time", lambda: NOW - 120)
    assert snap_device_auth.decision(state)["authorized"] is True


def test_no_recorded_clock_does_not_restrict(monkeypatch, server_key):
    """An older state file has no last_clock. It must not be read as zero and
    treated as a rollback -- that would restrict every machine on upgrade."""
    state = _signed_state(server_key, expires_at=NOW + 30 * DAY,
                          grace_ends_at=NOW + 44 * DAY,
                          last_clock=0, verified_at=NOW)
    state.pop("last_clock")
    monkeypatch.setattr(time, "time", lambda: NOW)
    assert snap_device_auth.decision(state)["authorized"] is True


def test_grace_still_works_for_an_honest_clock(monkeypatch, server_key):
    """Past expiry, inside grace, clock untouched: still authorized. This is
    the window that stops the owner being stranded, so it has to survive the
    rollback check."""
    expires = NOW - 3 * DAY
    state = _signed_state(server_key, expires_at=expires,
                          grace_ends_at=expires + 14 * DAY,
                          last_clock=NOW - DAY, verified_at=NOW - 10 * DAY)
    monkeypatch.setattr(time, "time", lambda: NOW)
    result = snap_device_auth.decision(state)
    assert result["status"] == "grace"
    assert result["authorized"] is True


# ── revocation reaching the cache ────────────────────────────────────────────

class _Response:
    def __init__(self, status_code, body):
        self.status_code = status_code
        self.ok = 200 <= status_code < 300
        self._body = body

    def json(self):
        return self._body


def _prime(auth_home, server_key, monkeypatch):
    state = _signed_state(server_key, expires_at=NOW + 30 * DAY,
                          grace_ends_at=NOW + 44 * DAY,
                          last_clock=NOW, verified_at=NOW)
    snap_device_auth._write_state(state)
    monkeypatch.setattr(time, "time", lambda: NOW)
    return state


def test_a_403_drops_the_cached_entitlement(auth_home, server_key, monkeypatch):
    """Revocation lands the first time the machine is online, instead of
    waiting out the signed term."""
    _prime(auth_home, server_key, monkeypatch)
    monkeypatch.setattr(snap_device_auth.requests, "post",
                        lambda *a, **k: _Response(403, {"ok": False,
                                                        "error": "This device is blocked."}))
    with pytest.raises(RuntimeError):
        snap_device_auth.refresh("test")

    after = snap_device_auth._read_state()
    assert "entitlement" not in after, "a revoked device kept its cached entitlement"
    assert after.get("revoked_at") == NOW
    assert snap_device_auth.decision(after)["authorized"] is False


@pytest.mark.parametrize("status_code", [500, 502, 404, 429])
def test_other_failures_leave_the_cache_alone(auth_home, server_key, monkeypatch,
                                              status_code):
    """A dropped connection or a broken site must not cost the owner their
    authorization. That would be the lockout this ticket exists to avoid."""
    _prime(auth_home, server_key, monkeypatch)
    monkeypatch.setattr(snap_device_auth.requests, "post",
                        lambda *a, **k: _Response(status_code, {"ok": False,
                                                                "error": "nope"}))
    with pytest.raises(RuntimeError):
        snap_device_auth.refresh("test")

    after = snap_device_auth._read_state()
    assert "entitlement" in after, (
        f"an HTTP {status_code} wiped the cached entitlement")
    assert snap_device_auth.decision(after)["authorized"] is True

# ── r2: Codex's FAIL. the rollback window is the OFFLINE stretch, not the
#        gap since the last online check ────────────────────────────────────

def test_long_offline_rollback_is_caught_by_the_high_water_mark(
        auth_home, server_key, monkeypatch):
    """Codex's exact reproduction, which my first test could not see.

    Last online check 60 days BEFORE expiry. Really 30 days AFTER it. Clock
    wound back to the day before expiry -- which is still later than
    last_clock, so comparing against last_clock alone called it full.

    The machine has been running HQ through that stretch, so the high-water
    mark has been moving even though it never got online.
    """
    expires = NOW
    state = _signed_state(server_key, expires_at=expires,
                          grace_ends_at=expires + 14 * DAY,
                          last_clock=expires - 60 * DAY,
                          verified_at=expires - 60 * DAY)
    snap_device_auth._write_state(state)

    # HQ is opened now and then over the offline stretch, up to 30 days past
    # expiry. Each run records the time it saw.
    for day in (10, 20, 40, 60, 75, 89):
        at = expires - 60 * DAY + day * DAY
        monkeypatch.setattr(time, "time", lambda at=at: at)
        snap_device_auth.decision()

    recorded = int(snap_device_auth._read_state().get("clock_high_water", 0))
    assert recorded >= expires, (
        "the high-water mark never passed expiry, so nothing was learned "
        "from running offline")

    # Now wind the clock back to the day before expiry.
    rolled_back = expires - DAY
    monkeypatch.setattr(time, "time", lambda: rolled_back)
    result = snap_device_auth.decision()
    assert result["authorized"] is False, (
        "a long-offline device revived an expired entitlement by rolling the "
        "clock back to a point after its last ONLINE check")
    assert result["reason"] == "clock_rollback"


def test_a_wild_forward_jump_is_not_remembered(auth_home, server_key, monkeypatch):
    """A dead BIOS battery must not restrict the machine permanently.

    The absurd reading is still used for the expiry comparison -- it will
    read as restricted while it stands -- but it is not written down, so
    correcting the clock restores the machine without going online.
    """
    state = _signed_state(server_key, expires_at=NOW + 30 * DAY,
                          grace_ends_at=NOW + 44 * DAY,
                          last_clock=NOW, verified_at=NOW)
    snap_device_auth._write_state(state)

    monkeypatch.setattr(time, "time", lambda: NOW + 3000 * DAY)
    snap_device_auth.decision()
    assert int(snap_device_auth._read_state().get("clock_high_water", 0)) <= NOW, (
        "an implausible clock reading was recorded as the high-water mark, "
        "which would restrict this machine for good")

    monkeypatch.setattr(time, "time", lambda: NOW + DAY)
    assert snap_device_auth.decision()["authorized"] is True, (
        "correcting the clock did not restore the machine")


def test_inspecting_a_state_dict_does_not_write_the_real_file(
        auth_home, server_key, monkeypatch):
    """decision(state) is used to inspect. It must not persist scratch values."""
    snap_device_auth._write_state(
        _signed_state(server_key, expires_at=NOW + 30 * DAY,
                      grace_ends_at=NOW + 44 * DAY,
                      last_clock=NOW, verified_at=NOW))
    before = snap_device_auth._read_state()

    scratch = _signed_state(server_key, expires_at=NOW + 99 * DAY,
                            grace_ends_at=NOW + 113 * DAY,
                            last_clock=NOW, verified_at=NOW)
    monkeypatch.setattr(time, "time", lambda: NOW + 500 * DAY)
    snap_device_auth.decision(scratch)

    assert snap_device_auth._read_state() == before

# ===== SNAPSMACK EOF =====
