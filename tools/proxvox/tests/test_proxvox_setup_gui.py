"""PROX VOX credential setup screen contract.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys
import unittest

# THIS FILE COULD NOT BE RUN ON ITS OWN. Every other test module in this
# directory inserts tools/proxvox into sys.path; this one did not, and relied
# on a sibling having been imported first. So the whole suite passed while
# `pytest tools/proxvox/tests/test_proxvox_setup_gui.py` failed at collection
# with "No module named 'proxvox'" -- which also means a change to pytest's
# collection order could have broken it with nothing having changed here.
# Found while reconciling the suite counts for Muse.
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from proxvox.credentials import NoToken
from proxvox.setup_gui import ROWS, credential_presence, save_setup_secret


class SetupGuiTests(unittest.TestCase):
    def test_button_label_has_one_ampersand(self):
        with open(
                os.path.join(os.path.dirname(__file__), "..", "proxvox", "setup_gui.py"),
                encoding="utf-8") as stream:
            source = stream.read()
        self.assertIn('text="PASTE & SAVE"', source)
        self.assertNotIn('text="PASTE && SAVE"', source)

    def test_status_reports_presence_without_returning_values(self):
        values = {
            ("proxvox", "ct102_ssh"): "one",
            ("proxvox", "mysql_ro"): "three",
        }
        status = credential_presence(reader=lambda site, kind: values.get((site, kind), ""))
        self.assertEqual(status, {
            "ct102_ssh": True,
            "ct106_ssh": False,
            "mysql_ro": True,
            "pve_host_ssh": False,
        })
        self.assertNotIn("one", repr(status))
        self.assertNotIn("three", repr(status))

    def test_each_visible_row_saves_to_its_exact_slot(self):
        writes = []
        for kind, _label in ROWS:
            save_setup_secret(kind, "owner-secret", writer=lambda *args: writes.append(args) or True)
        self.assertEqual(writes, [
            ("proxvox", "ct102_ssh", "owner-secret"),
            ("proxvox", "ct106_ssh", "owner-secret"),
            ("proxvox", "mysql_ro", "owner-secret"),
            ("proxvox", "pve_host_ssh", "owner-secret"),
        ])

    def test_unknown_and_empty_values_are_refused(self):
        with self.assertRaises(NoToken):
            save_setup_secret("not-a-slot", "value", writer=lambda *_: True)
        with self.assertRaises(NoToken):
            save_setup_secret("ct102_ssh", "", writer=lambda *_: True)


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
