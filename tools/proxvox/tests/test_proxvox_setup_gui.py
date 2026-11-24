"""PROX VOX credential setup screen contract.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import unittest

from proxvox.credentials import NoToken
from proxvox.setup_gui import ROWS, credential_presence, save_setup_secret


class SetupGuiTests(unittest.TestCase):
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
