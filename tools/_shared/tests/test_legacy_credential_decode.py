"""Strict compatibility checks for pre-vault base64 credentials.

The tests use only fixed synthetic strings.  They do not read a configuration
file, initialise the credential vault, or touch an installed application's
credentials.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import json
import os
import sys
import tempfile

import pytest

os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="strict-legacy-creds-")
SHARED = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

import snap_creds  # noqa: E402
import snap_profiles  # noqa: E402


@pytest.mark.parametrize("value", [
    "ordinary-password",
    "snowman-\u2603",
    "contains spaces and punctuation !?",
])
def test_genuine_legacy_base64_round_trips(value):
    encoded = base64.b64encode(value.encode("utf-8")).decode("ascii")
    assert snap_creds.decode_legacy_base64(encoded) == value
    assert snap_creds.open_local(encoded) == value
    assert snap_profiles._legacy_deobfuscate(encoded) == value


@pytest.mark.parametrize("blob", [
    "YWJj$",       # permissive decoders discard the dollar sign
    "Zg",          # missing required padding
    "Zh==",        # valid alphabet, but non-canonical pad bits for b'f'
    "/w==",        # canonical base64, but not UTF-8 text
    "snowman-\u2603",  # not ASCII base64
])
def test_malformed_or_noncanonical_values_are_rejected(blob):
    with pytest.raises(snap_creds.LegacyCredentialDecodeError):
        snap_creds.decode_legacy_base64(blob)
    with pytest.raises(snap_creds.LegacyCredentialDecodeError):
        snap_creds.open_local(blob)
    with pytest.raises(snap_creds.LegacyCredentialDecodeError):
        snap_profiles._legacy_deobfuscate(blob)


def test_prefixed_shared_store_value_uses_the_same_strict_decoder():
    encoded = base64.b64encode(b"legacy-shared-value").decode("ascii")
    assert snap_creds._open("b64:" + encoded) == "legacy-shared-value"
    with pytest.raises(snap_creds.LegacyCredentialDecodeError):
        snap_creds._open("b64:YWJj$")


def test_profile_load_rejects_malformed_legacy_value_loudly(tmp_path):
    path = tmp_path / "example.test.json"
    path.write_text(json.dumps({
        "schema": 1,
        "name": "Example",
        "site_url": "https://example.test",
        "api_key_enc": "YWJj$",
    }), encoding="utf-8")
    with pytest.raises(snap_creds.LegacyCredentialDecodeError):
        snap_profiles._read(str(path))


def test_get_returns_default_for_one_bad_entry_without_hiding_good_entries(
        monkeypatch, caplog):
    encoded = base64.b64encode(b"still-readable").decode("ascii")
    monkeypatch.setattr(snap_creds, "init", lambda: None)
    monkeypatch.setattr(snap_creds, "_read", lambda: {
        "bad_key": "b64:YWJj$",
        "good_key": "b64:" + encoded,
    })

    with caplog.at_level("WARNING", logger=snap_creds.__name__):
        assert snap_creds.get("bad_key", "safe-default") == "safe-default"
    assert snap_creds.get("good_key", "safe-default") == "still-readable"
    assert "bad_key" in caplog.text
    assert "YWJj$" not in caplog.text


def test_migration_leaves_bad_entry_and_migrates_good_entries(
        monkeypatch, caplog):
    encoded = base64.b64encode(b"migrate-me").decode("ascii")
    original = {
        "bad_key": "b64:YWJj$",
        "good_key": "b64:" + encoded,
        "plain_key": "plain legacy value",
    }
    written = []
    monkeypatch.setattr(snap_creds, "_read", lambda: dict(original))
    monkeypatch.setattr(snap_creds, "_write", lambda data: written.append(dict(data)))
    monkeypatch.setattr(snap_creds.snap_vault, "is_encrypted", lambda blob: False)
    monkeypatch.setattr(
        snap_creds.snap_vault, "encrypt", lambda value: "enc1:" + value)

    with caplog.at_level("WARNING", logger=snap_creds.__name__):
        snap_creds._migrate_legacy_values()

    assert written == [{
        "bad_key": "b64:YWJj$",
        "good_key": "enc1:migrate-me",
        "plain_key": "enc1:plain legacy value",
    }]
    assert "bad_key" in caplog.text
    assert "YWJj$" not in caplog.text

# ===== SNAPSMACK EOF =====
