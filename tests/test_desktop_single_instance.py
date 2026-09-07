"""Regression coverage for the shared desktop single-instance guard."""

import os
import sys
import unittest
import uuid
from unittest.mock import patch


SHARED = os.path.join(os.path.dirname(os.path.dirname(__file__)), "tools", "_shared")
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

import snap_single_instance  # noqa: E402


class SingleInstanceTests(unittest.TestCase):
    def test_second_acquire_is_rejected(self):
        app_id = "single-instance-test-" + uuid.uuid4().hex
        self.assertTrue(snap_single_instance.acquire(app_id, "Test App"))
        with patch.object(snap_single_instance, "_tell_user") as tell:
            self.assertFalse(snap_single_instance.acquire(app_id, "Test App"))
        tell.assert_called_once_with("Test App")

    def test_current_hq_and_coldsnap_entry_points_use_guard(self):
        root = os.path.dirname(os.path.dirname(__file__))
        paths = (
            os.path.join(root, "tools", "hub", "main.py"),
            os.path.join(root, "tools", "coldsnap", "coldsnap_qt", "app.py"),
        )
        for path in paths:
            with self.subTest(path=path), open(path, encoding="utf-8") as source:
                text = source.read()
                self.assertIn("snap_single_instance.acquire", text)


if __name__ == "__main__":
    unittest.main()
