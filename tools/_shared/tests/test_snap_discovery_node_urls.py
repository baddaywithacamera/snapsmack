"""SECAUDIT 054 F2 — hub-supplied node URLs are validated before they become
profiles or receive the hub key."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import snap_discovery as D  # noqa: E402


class NodeUrlReasonTests(unittest.TestCase):
    def test_clean_https_origin_passes(self):
        self.assertEqual(D.node_url_reason("https://pixhellated.ca"), "")
        self.assertEqual(D.node_url_reason("https://pixhellated.ca/"), "")

    def test_refusals(self):
        bad = {
            "": "no site URL",
            "http://pixhellated.ca": "not https",
            "https://user:pw@pixhellated.ca": "credentials",
            "https://pixhellated.ca/?x=1": "query",
            "https://pixhellated.ca/#frag": "fragment",
            "https://10.0.0.5": "private",
            "https://192.168.1.11": "private",
            "https://127.0.0.1": "local",
            "https://169.254.169.254": "link-local (cloud metadata)",
            "https://[::1]": "loopback v6",
            "https://[fd00::1]": "private v6",
            "https://pixhellated.ca/\x00": "control char",
            "ftp://pixhellated.ca": "scheme",
        }
        for url, why in bad.items():
            with self.subTest(url=url, why=why):
                self.assertNotEqual(D.node_url_reason(url), "", f"should refuse: {why}")


class DiscoverDropsBadNodesTests(unittest.TestCase):
    def test_bad_nodes_are_dropped_and_recorded(self):
        payload = {
            "ok": True, "site_url": "https://photoblogs.fyi", "site_name": "hub",
            "multisite": {"nodes": [
                {"role": "spoke", "site_url": "https://pixhellated.ca", "api_key_local": "k1"},
                {"role": "spoke", "site_url": "http://plain.example", "api_key_local": "k2"},
                {"role": "spoke", "site_url": "https://192.168.1.11", "api_key_local": "k3"},
                {"role": "spoke", "site_url": "https://evil:pw@x.example", "api_key_local": "k4"},
                {"role": "hub",   "site_url": "https://photoblogs.fyi"},
            ]},
        }

        class _R:
            status_code = 200
            text = "{}"
            def json(self):
                return payload
            def raise_for_status(self):
                pass

        class _S:
            headers = {}
            def get(self, *a, **k):
                return _R()

        with mock.patch.object(D, "_session", return_value=_S()):
            hub_info, spokes = D.discover("https://photoblogs.fyi", api_key="hubkey")
        self.assertEqual([s["site_url"] for s in spokes], ["https://pixhellated.ca"])
        self.assertEqual(len(hub_info["rejected_nodes"]), 3)
        self.assertTrue(all(n["_rejected"] for n in hub_info["rejected_nodes"]))


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
