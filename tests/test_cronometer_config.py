"""Regression checks for CRONOMETER's shared fleet credential selection."""

import os
import sys
import types

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CRONOMETER = os.path.join(ROOT, "tools", "cronometer")
if CRONOMETER not in sys.path:
    sys.path.insert(0, CRONOMETER)

import config  # noqa: E402


def test_load_fleet_prefers_full_management_key(monkeypatch=None):
    fake = types.SimpleNamespace(list_profiles=lambda: [{
        "name": "Example",
        "site_url": "https://example.test",
        "api_key": "restricted-posting-key",
        "extras": {"api_key_local": "full-management-key"},
    }, {
        "name": "Legacy",
        "site_url": "https://legacy.test",
        "api_key": "legacy-key",
        "extras": {},
    }])
    old = sys.modules.get("snap_profiles")
    sys.modules["snap_profiles"] = fake
    try:
        fleet = config.load_fleet()
    finally:
        if old is None:
            sys.modules.pop("snap_profiles", None)
        else:
            sys.modules["snap_profiles"] = old
    assert fleet[0]["api_key"] == "full-management-key"
    assert fleet[1]["api_key"] == "legacy-key"


if __name__ == "__main__":
    test_load_fleet_prefers_full_management_key()
    print("CRONOMETER credential selection: passed")

# ===== SNAPSMACK EOF =====
