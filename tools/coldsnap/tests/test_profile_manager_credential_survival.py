"""Legacy COLD SNAP profile saves must not silently destroy credentials.

Every test redirects the legacy profile directory to pytest scratch space and
disables the shared profile sources.  No installed profile or vault is read.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import json
import os
import sys

import pytest

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)

import profile_manager  # noqa: E402


@pytest.fixture(autouse=True)
def scratch_profiles(tmp_path, monkeypatch):
    monkeypatch.setattr(profile_manager, "PROFILES_DIR", str(tmp_path / "profiles"))
    monkeypatch.setattr(profile_manager, "_shared_profiles", None)
    monkeypatch.setattr(profile_manager, "snap_connections", None)


def raw_profile(name="Example"):
    with open(profile_manager._profile_path(name), encoding="utf-8") as handle:
        return json.load(handle)


def test_unrelated_partial_save_preserves_stored_password():
    profile_manager.save_profile({
        "name": "Example",
        "url": "https://example.test",
        "password": "keep-me",
    })
    before = raw_profile()["password_enc"]

    profile_manager.save_profile({
        "name": "Example",
        "url": "https://changed.example.test",
    })

    after = raw_profile()
    assert after["password_enc"] == before
    assert profile_manager.load_profile("Example")["password"] == "keep-me"
    assert after["url"] == "https://changed.example.test"


def test_unreadable_password_is_reported_and_preserved_on_save():
    os.makedirs(profile_manager.PROFILES_DIR, exist_ok=True)
    malformed = "YWJj$"
    with open(profile_manager._profile_path("Example"), "w", encoding="utf-8") as handle:
        json.dump({"name": "Example", "url": "https://example.test",
                   "password_enc": malformed}, handle)

    loaded = profile_manager.load_profile("Example")
    assert loaded["password"] == ""
    assert loaded["secrets_unreadable"] == ["password"]
    loaded["url"] = "https://changed.example.test"
    profile_manager.save_profile(loaded)

    after = raw_profile()
    assert after["password_enc"] == malformed
    assert "secrets_unreadable" not in after
    assert "_unreadable_password_enc" not in after


def test_absent_password_is_not_reported_unreadable():
    profile_manager.save_profile({"name": "Example", "password": ""})
    loaded = profile_manager.load_profile("Example")
    assert loaded["password"] == ""
    assert loaded.get("secrets_unreadable") in (None, [], ())


def test_explicit_empty_password_still_clears_readable_password():
    profile_manager.save_profile({"name": "Example", "password": "keep-me"})
    loaded = profile_manager.load_profile("Example")
    loaded["password"] = ""
    profile_manager.save_profile(loaded)
    assert raw_profile()["password_enc"] == ""
    assert profile_manager.load_profile("Example")["password"] == ""


def test_deliberate_replacement_of_unreadable_password_still_works():
    os.makedirs(profile_manager.PROFILES_DIR, exist_ok=True)
    with open(profile_manager._profile_path("Example"), "w", encoding="utf-8") as handle:
        json.dump({"name": "Example", "password_enc": "not-base64$"}, handle)
    loaded = profile_manager.load_profile("Example")
    loaded["password"] = "replacement"
    profile_manager.save_profile(loaded)
    assert profile_manager.load_profile("Example")["password"] == "replacement"


def test_rename_preserves_exact_unreadable_password_blob():
    os.makedirs(profile_manager.PROFILES_DIR, exist_ok=True)
    malformed = base64.b64encode(b"\xff").decode("ascii")
    with open(profile_manager._profile_path("Old"), "w", encoding="utf-8") as handle:
        json.dump({"name": "Old", "password_enc": malformed}, handle)

    assert profile_manager.rename_profile("Old", "New") is True
    assert raw_profile("New")["password_enc"] == malformed
    assert not os.path.exists(profile_manager._profile_path("Old"))

# ===== SNAPSMACK EOF =====
