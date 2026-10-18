# SNAPSMACK_EOF_HEADER
"""Collaboration room tests."""

import sys
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(HERE))

from troll_booth.room import Room, RoomError


class TestRoom(unittest.TestCase):
    def test_messages_are_attributed_and_persistent(self):
        with tempfile.TemporaryDirectory() as directory:
            room = Room(Path(directory) / "room.jsonl")
            message = room.add("codex", "Backup is verified.", "sean", "status")
            self.assertEqual(message["actor"], "codex")
            self.assertEqual(Room(room.path).recent()[0]["text"], "Backup is verified.")

    def test_room_rejects_unbounded_or_unknown_messages(self):
        with tempfile.TemporaryDirectory() as directory:
            room = Room(Path(directory) / "room.jsonl")
            with self.assertRaises(RoomError):
                room.add("intruder", "hello")
            with self.assertRaises(RoomError):
                room.add("codex", "x" * 4001)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
