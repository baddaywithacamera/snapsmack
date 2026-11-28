"""Regression checks for reconnecting a fleet update in one host session.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python SNAPSMACK EOF marker.
"""

from unittest.mock import Mock, call

from proxvox import fleet_release
from proxvox.broker import Refused


def test_lost_start_and_status_responses_reuse_the_session(monkeypatch):
    monkeypatch.setattr(fleet_release.time, "sleep", Mock())
    version = fleet_release.VERSION
    first, second = "one.example", "two.example"

    def verified(site):
        return {"state": "verified", "receipt": {"site": site, "version": version, "http": 200}}

    client = Mock()
    client.is_authorized.return_value = True
    client.signed_release_status.side_effect = [
        {"state": "pending"}, Refused("connection dropped"), {"state": "running"},
        verified(first), {"state": "pending"},
    ]
    client.start_signed_release.side_effect = [Refused("start receipt lost"), verified(second)]
    assert fleet_release.run_fleet_release(client, [first, second], Mock()) == 2
    assert client.start_signed_release.call_args_list == [call(first, version), call(second, version)]
    client.authorize.assert_not_called()
    client.revoke.assert_not_called()


# ===== SNAPSMACK EOF =====
