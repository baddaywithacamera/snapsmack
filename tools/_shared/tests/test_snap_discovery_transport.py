"""
SECAUDIT 054 F2 — discovery must NOT POST the hub's key to a node over insecure
transport. Proves the transport guard fires BEFORE the network call (an http node
URL is refused without requests.post ever being reached), while a safe URL is not
short-circuited by the guard.

Run: python tools/_shared/tests/test_snap_discovery_transport.py   (exit 0 = pass)
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import snap_discovery as D


class _Resp:
    status_code = 500          # non-200 => the function returns "" without needing .json()


class _PostSpy:
    """Records whether requests.post was reached, and returns a harmless non-200
    response (the function's own try/except would swallow a raise, so we signal via
    a flag instead)."""
    def __init__(self):
        self.called = False

    def __call__(self, *a, **k):
        self.called = True
        return _Resp()


def _checks():
    n = 0
    spy = _PostSpy()
    orig = D.requests.post
    D.requests.post = spy
    try:
        # DENY — plain-http node: refused, and the key never hits the wire.
        spy.called = False
        assert D._provision_spoke_key("http://evil.example.com", "HUB-KEY") == ""
        assert spy.called is False, "http node reached the network — guard did NOT fire"
        spy.called = False
        assert D._provision_hub_backup_key("http://evil.example.com", "HUB-KEY", "k" * 64) == ""
        assert spy.called is False, "http hub-backup reached the network — guard did NOT fire"
        n += 1

        # ALLOW — a safe https URL is NOT blocked by the transport guard; it reaches
        # the network (spy records the call), proving the guard let it through.
        spy.called = False
        D._provision_spoke_key("https://good.example.com", "HUB-KEY")
        assert spy.called is True, "https node was wrongly blocked by the transport guard"
        n += 1
    finally:
        D.requests.post = orig

    return n


if __name__ == "__main__":
    count = _checks()
    print("OK — %d checks passed" % count)
