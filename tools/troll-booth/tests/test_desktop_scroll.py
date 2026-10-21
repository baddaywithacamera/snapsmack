from __future__ import annotations

import unittest
from pathlib import Path


DESKTOP = (Path(__file__).resolve().parents[1] / "troll_booth" / "desktop.py").read_text(encoding="utf-8")


class TranscriptScrollTests(unittest.TestCase):
    def test_unchanged_transcript_is_not_redrawn(self) -> None:
        self.assertIn("if render_key == self._transcript_render_key:", DESKTOP)

    def test_bottom_position_is_preserved_across_new_messages(self) -> None:
        self.assertIn("was_at_bottom", DESKTOP)
        self.assertIn("scrollbar.maximum() if was_at_bottom", DESKTOP)


if __name__ == "__main__":
    unittest.main()
