"""suyb-settings.json inside a backup zip must carry no secret.

It used to drop only ftp_pass and snap_admin_pass, so the site's backup key
(api_key) went into every package — the nightly headless run always bundles
settings, and the package is pushed to the cloud.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import backup_engine
import profile_manager


def test_bundle_drops_every_secret_field():
    profile = profile_manager.new_profile_template()
    profile.update({
        "name": "a colourless life", "site_url": "https://acolourlesslife.ca",
        "ftp_pass": "hunter2", "snap_admin_pass": "hunter3", "api_key": "suyb_live_abc",
        "ftp_pass_enc": "sealed", "snap_admin_pass_enc": "sealed", "future_token": "t",
    })
    bundled = backup_engine.settings_bundle_profile(profile)
    for secret in ("ftp_pass", "snap_admin_pass", "api_key", "ftp_pass_enc",
                   "snap_admin_pass_enc", "future_token"):
        assert secret not in bundled
    assert "suyb_live_abc" not in repr(bundled)
    # Ordinary settings still travel.
    assert bundled["site_url"] == "https://acolourlesslife.ca"
    assert bundled["schedule_time"] == profile["schedule_time"]
    assert bundled["cloud_folder_id"] == profile["cloud_folder_id"]

# ===== SNAPSMACK EOF =====
