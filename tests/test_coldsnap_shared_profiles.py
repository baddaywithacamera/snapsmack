"""COLD SNAP must read the SHARED profile store (the sites SNAP HQ / GYSS / SYBU
set up), not just its own local profiles/ folder — otherwise the LOAD PROFILE
dropdown is empty on a machine whose sites were configured in the Hub. Isolated
SNAPSMACK_HOME so the real shared library is never touched.
"""

import os
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
for sub in ("tools/_shared", "tools/coldsnap"):
    p = os.path.join(ROOT, *sub.split("/"))
    if p not in sys.path:
        sys.path.insert(0, p)

os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="cs_profiles_")

import snap_profiles          # noqa: E402
import profile_manager        # noqa: E402


def test_coldsnap_sees_shared_profiles():
    snap_profiles.save({
        "name": "Craptastic", "site_url": "https://craptasti.ca",
        "api_key": "KEY123", "extras": {"smackpress_key": "SMACK987"},
    })

    assert "Craptastic" in profile_manager.list_profiles()

    prof = profile_manager.load_profile("Craptastic")
    assert prof is not None
    assert prof["url"] == "https://craptasti.ca"      # site_url → url mapping
    assert prof["api_key"] == "KEY123"
    assert prof["smackpress_key"] == "SMACK987"       # pulled from extras


if __name__ == "__main__":
    test_coldsnap_sees_shared_profiles()
    print("ok")

# ===== SNAPSMACK EOF =====
