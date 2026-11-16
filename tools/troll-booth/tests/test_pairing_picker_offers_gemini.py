from __future__ import annotations
# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
"""The installed Brain Trust picker offers gemini.

Sean's screenshot showed the Agent Connections selector listing only claude,
codex and muse, so the Antigravity seat was invisible in the app even once the
server accepts it.

THIS IS DISCOVERABILITY, NOT ENFORCEMENT, and the distinction matters. The
combo is editable: anyone could already type any name into it. What stops an
unapproved actor is the server's PAIR_ACTORS whitelist, which is where it
belongs and which refused "antigravity" and "gemini" until Sean approved the
seat. Adding an entry here changes what Sean can find, not what anyone may do.

WHICH SOURCE THIS IS. The file is read from the revision that produced the
installed executable (431ed4cc), not from the troll-booth branch carrying the
server change: those two desktop.py files are different UIs, and the branch's
copy is three weeks older. Patching the wrong one and rebuilding would have
handed Sean a downgraded app. Codex caught that before I edited anything.

Read as text rather than imported, because importing desktop.py needs PySide6
and a display, and neither is needed to answer the question.
"""

import os
import re
import unittest

DESKTOP = os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
    "troll_booth", "desktop.py")


def _picker_items() -> list[str]:
    source = open(DESKTOP, encoding="utf-8").read()
    match = re.search(r"self\.pair_actor\.addItems\(\[([^\]]*)\]\)", source)
    if not match:
        raise AssertionError(
            "the pairing picker's addItems call is gone. Either the UI was "
            "replaced, or this test is pointed at the wrong desktop.py -- "
            "there is more than one, and they are different apps.")
    return [part.strip().strip('"\'') for part in match.group(1).split(",")
            if part.strip()]


class PairingPickerTests(unittest.TestCase):

    def test_gemini_is_offered(self):
        self.assertIn("gemini", _picker_items(),
                      "the Antigravity seat is invisible in the app Sean "
                      "actually runs")

    def test_the_existing_seats_are_still_offered(self):
        """A picker is a list; the easy mistake is replacing it, not adding."""
        items = _picker_items()
        for actor in ("claude", "codex", "muse"):
            self.assertIn(actor, items, f"{actor} was dropped from the picker")

    def test_the_picker_offers_only_real_seats(self):
        """Every name here must be one the server will actually accept.

        An entry the server refuses is a button that produces an error, which
        reads as the app being broken rather than the seat not existing.
        """
        self.assertEqual(set(_picker_items()),
                         {"claude", "codex", "muse", "gemini"})

    def test_this_is_the_installed_ui_not_the_other_one(self):
        """A guard on which file was edited.

        Two desktop.py revisions exist with different UIs. The installed one
        has the Agent Connections tab and an editable actor combo; the other
        has CONNECT CODEX / CONNECT CLAUDE buttons and no combo at all, so a
        gemini entry added there would never appear for Sean.
        """
        source = open(DESKTOP, encoding="utf-8").read()
        self.assertIn("AGENT CONNECTIONS", source)
        self.assertNotIn("CONNECT CODEX", source)


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
