"""Thomas the Bear must stay present and attributed in SNAP SLAPPER.

The Thomas Clause (licenses/THE-THOMAS-CLAUSE.txt) requires that Thomas remain
functionally present, findable, and attributed to Noah Grey. These checks guard
that obligation so a future refactor cannot quietly erase him.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import os
import sys
import unittest

SHARED = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "tools", "_shared"))
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

import thomas


class PresenceTests(unittest.TestCase):
    def test_image_ships_with_the_editor(self):
        self.assertTrue(os.path.isfile(thomas.image_path()),
                        "Thomas image must ship beside the editor")

    def test_story_attributes_noah_grey(self):
        story = thomas.STORY
        for required in ("Noah Grey", "Greymatter", "Picasa"):
            self.assertIn(required, story)
        # the clause's "he lives here now, he is not going anywhere" spirit
        self.assertIn("lives here now", story)
        self.assertIn("not going anywhere", story)

    def test_installer_never_raises(self):
        # install() must swallow any failure so the app always opens.
        self.assertIsNone(thomas.install(None))


class LiveTkTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        try:
            import tkinter as tk
            cls.root = tk.Tk()
            cls.root.withdraw()
        except Exception as exc:  # no display
            raise unittest.SkipTest("no Tk display: %s" % exc)

    @classmethod
    def tearDownClass(cls):
        try:
            cls.root.destroy()
        except Exception:
            pass

    def test_spawn_and_clear(self):
        controller = thomas.install(self.root)
        self.assertIsNotNone(controller)
        controller._spawn()
        controller._spawn()
        self.assertEqual(len(controller.bears), 2)
        controller.clear()
        self.assertEqual(controller.bears, [])

    def test_story_window_builds_with_attribution(self):
        controller = thomas.Thomas(self.root)
        window = controller.show_story()
        self.root.update_idletasks()
        self.assertTrue(window.winfo_exists())
        window.destroy()


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
