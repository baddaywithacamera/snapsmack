"""SMACKPRESS WordPress client request identity regression tests."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from smackpress import wp_client  # noqa: E402


class HeaderTests(unittest.TestCase):
    def test_client_identifies_itself_instead_of_using_python_urllib(self):
        values = {"wp_user": "sean", "wp_app_password": "abcd efgh"}
        with mock.patch.object(wp_client.config, "get", lambda key: values[key]):
            headers = wp_client._headers()
        self.assertEqual(headers["User-Agent"], "SmackPress/0.3 (+https://snapsmack.ca)")
        self.assertNotIn("Python-urllib", headers["User-Agent"])


# ===== SNAPSMACK EOF =====
