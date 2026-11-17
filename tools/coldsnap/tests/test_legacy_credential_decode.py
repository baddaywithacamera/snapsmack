"""COLD SNAP must not bypass the shared strict legacy decoder.

Only fixed synthetic values are used; no configuration file or live vault is
opened.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import os
import sys
import tempfile

os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="strict-coldsnap-creds-")
BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)
SHARED = os.path.join(os.path.dirname(BASE), "_shared")
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

import config  # noqa: E402


def test_canonical_legacy_value_still_reads(monkeypatch):
    encoded = base64.b64encode(b"legacy-cold-snap-key").decode("ascii")
    assert config._open_secret(encoded) == "legacy-cold-snap-key"
    assert config._open_secret_checked(encoded) == ("legacy-cold-snap-key", True)
    monkeypatch.setattr(config, "_shared_creds", lambda: None)
    assert config._open_secret(encoded) == "legacy-cold-snap-key"
    assert config._open_secret_checked(encoded) == ("legacy-cold-snap-key", True)


def test_malformed_legacy_value_is_unreadable_not_a_different_key(monkeypatch):
    assert config._open_secret("YWJj$") == ""
    assert config._open_secret_checked("YWJj$") == ("", False)
    monkeypatch.setattr(config, "_shared_creds", lambda: None)
    assert config._open_secret("YWJj$") == ""
    assert config._open_secret_checked("YWJj$") == ("", False)

# ===== SNAPSMACK EOF =====
