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

# ===== SNAPSMACK EOF =====
