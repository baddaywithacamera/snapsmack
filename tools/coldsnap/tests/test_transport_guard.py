"""
Tests for COLD SNAP's SECAUDIT 054 F1 transport guard — SumnaConnection must
REFUSE to attach the posting key to an insecure (non-https) connection.

Deny-path first: an http site raises before any Bearer session is built; a real
https site connects normally; loopback is exempt so localhost dev still works.
This is the guard the other five suite tools already had and COLD SNAP skipped.

Run: python tools/coldsnap/tests/test_transport_guard.py   (exit 0 = all pass)
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(
    os.path.abspath(__file__)))), "_shared"))

import sumna_post as P


def _checks():
    n = 0

    # 1. DENY — a plain-http public site refuses before the key is attached.
    raised = False
    try:
        P.SumnaConnection("http://evil.example.com", "SECRET-KEY")
    except P.InsecureTransportError:
        raised = True
    assert raised, "http site did NOT refuse — the key would go out in the clear"
    n += 1

    # 2. ALLOW — a real https site connects and the Bearer key is attached.
    conn = P.SumnaConnection("https://example.com", "SECRET-KEY")
    assert conn.session.headers.get("Authorization") == "Bearer SECRET-KEY", \
        "https connection didn't attach the Bearer key"
    n += 1

    # 3. ALLOW — loopback is exempt so localhost dev/testing over http still works.
    for local in ("http://127.0.0.1:8080", "http://localhost"):
        try:
            P.SumnaConnection(local, "SECRET-KEY")
        except P.InsecureTransportError:
            raise AssertionError(f"loopback {local} should be exempt but refused")
    n += 1

    # 4. The refusal message is the shared, user-facing one (not a bare code).
    try:
        P.SumnaConnection("http://evil.example.com", "SECRET-KEY")
    except P.InsecureTransportError as e:
        assert "https" in str(e).lower(), "refusal message should mention https"
    n += 1

    return n


if __name__ == "__main__":
    count = _checks()
    print(f"OK — {count} checks passed")
