"""Mutual-auth A1 (SECAUDIT 054): every desktop client that writes with a site
key names the site (X-Snap-Site). Header helper + wiring contract."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
SHARED = os.path.dirname(HERE)
ROOT = os.path.dirname(os.path.dirname(SHARED))
sys.path.insert(0, SHARED)

import snap_site_scope as S  # noqa: E402

# Every client that sends a Bearer key to a SnapSmack site. Adding a new tool
# that posts with a site key? Add it here and wire _site_scope() into its
# headers, or REQUIRE mode on a site will refuse it.
WIRED_CLIENTS = [
    "tools/sybu/poster.py",
    "tools/coldsnap/sumna_post.py",
    "tools/smack-up-your-backup/backup_engine.py",
    "tools/hub/slapper_qt/smackthemup_publish.py",
    "tools/cronometer/heartbeat_client.py",
    "tools/gyss/gyss_qt.py",
    "tools/flkr-fckr/poster.py",
    "tools/unzucker/poster.py",
    "tools/blogger-flogger/blogger_flogger/destination_client.py",
    "tools/take-your-shit-with-you/tyswy_client.py",
    "tools/shots-fired/schedule_client.py",
    "tools/smack-your-mouth/moderation_api.py",
    "tools/_shared/snap_settings_sync.py",
    "tools/_shared/snap_discovery.py",
]


class HeaderTests(unittest.TestCase):
    def test_host_from_url_forms(self):
        for v, want in {
            "https://pixhellated.ca/": "pixhellated.ca",
            "https://PIXHELLATED.ca:443/api.php?x=1": "pixhellated.ca",
            "pixhellated.ca": "pixhellated.ca",
            "http://localhost:8080": "localhost",
            "": "",
            None: "",
        }.items():
            with self.subTest(v=v):
                self.assertEqual(S.host_of(v), want)

    def test_header_dict(self):
        self.assertEqual(S.header("https://foundtextures.ca"), {"X-Snap-Site": "foundtextures.ca"})
        self.assertEqual(S.header(""), {})


class WiringTests(unittest.TestCase):
    def test_every_listed_client_uses_the_helper(self):
        for rel in WIRED_CLIENTS:
            with self.subTest(file=rel):
                src = open(os.path.join(ROOT, rel), encoding="utf-8").read()
                self.assertIn("_site_scope(", src, f"{rel} does not call _site_scope()")
                self.assertIn("snap_site_scope", src, f"{rel} does not import snap_site_scope")

    def test_gyss_rust_sends_the_header(self):
        src = open(os.path.join(ROOT, "tools/gyss/src-tauri/src/lib.rs"), encoding="utf-8").read()
        self.assertIn('.header("X-Snap-Site", site_host)', src)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
