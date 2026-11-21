"""Tests for SS-1474, SS-1475, and SS-1483 in BRAIN TRUST:
- SS-1474: Per-room archive (read-only + restorable), soft remove (hidden + restorable,
  hash chain and messages intact), rename display title only (room_id unchanged),
  export text (with timestamps, sender, message id, and hash chain), and export CSV
  matching the messages table columns exactly (id, room_id, sender, body, ticket_id,
  task_id, created_at, previous_hash, message_hash, member_sequence, attachments).
- SS-1475: Selecting an agent blanks the previous pairing code in UI and invalidates
  it server-side, auto-generates a new 5-minute single-use code, ticks a visible
  countdown, and clears at zero or on consumption.
- SS-1483: Window title includes the version from troll_booth.__version__.
"""

# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.

from __future__ import annotations

import csv
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from PySide6.QtWidgets import QApplication

import troll_booth
from troll_booth.desktop import BoothWindow
from troll_booth.history import CSV_COLUMNS, ConversationStore
from troll_booth.store import BoothStore


def _app():
    return QApplication.instance() or QApplication([])


class BrainTrustRoomAndExportTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.db_path = Path(self.temp.name) / "test_history.sqlite3"
        self.store = ConversationStore(self.db_path)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_rename_archive_and_soft_remove_preserve_room_id_and_hash_chain(self) -> None:
        m1 = self.store.append("ops-room", "sean", "First line\nSecond line", title="Original Title", ticket_id="SS-1474")
        m2 = self.store.append("ops-room", "claude", "Acknowledged", title="Original Title", task_id="T-1")

        before = self.store.all_messages("ops-room")
        self.assertEqual([m["id"] for m in before], [m1, m2])
        self.assertEqual(before[1]["previous_hash"], before[0]["message_hash"])

        # Rename display name only
        self.assertTrue(self.store.rename_room("ops-room", "Renamed Display Title"))
        rooms = self.store.rooms()
        self.assertEqual(len(rooms), 1)
        self.assertEqual(rooms[0]["id"], "ops-room")
        self.assertEqual(rooms[0]["title"], "Renamed Display Title")

        # Archive hides from default room list and can be restored
        self.assertTrue(self.store.archive_room("ops-room", True))
        self.assertEqual(self.store.rooms(), [])
        self.assertEqual(len(self.store.rooms(include_archived=True)), 1)
        self.assertTrue(self.store.archive_room("ops-room", False))
        self.assertEqual(len(self.store.rooms()), 1)

        # Soft remove hides from default room list, keeps rows & hash chain intact, and restores
        self.assertTrue(self.store.soft_remove_room("ops-room", True))
        self.assertEqual(self.store.rooms(), [])
        self.assertEqual(self.store.rooms(include_archived=True), [])
        self.assertEqual(len(self.store.rooms(include_removed=True)), 1)

        after = self.store.all_messages("ops-room")
        self.assertEqual([m["id"] for m in after], [m1, m2])
        self.assertEqual(after[0]["message_hash"], before[0]["message_hash"])
        self.assertEqual(after[1]["previous_hash"], before[0]["message_hash"])
        self.assertEqual(after[1]["message_hash"], before[1]["message_hash"])

        self.assertTrue(self.store.soft_remove_room("ops-room", False))
        self.assertEqual(len(self.store.rooms()), 1)

    def test_export_text_and_csv_match_messages_schema_and_quote_multiline(self) -> None:
        m1 = self.store.append(
            "archive-recovery",
            "sean",
            'Line 1 with "quotes", commas, and\nLine 2 in body',
            title="Archive recovery agreement",
            ticket_id="SS-1474",
            task_id="TASK-9",
        )
        m2 = self.store.append(
            "archive-recovery",
            "antigravity",
            "Follow-up message",
            title="Archive recovery agreement",
        )
        msgs = self.store.all_messages("archive-recovery")

        text_out = self.store.export_text("archive-recovery")
        self.assertIn("Archive recovery agreement (archive-recovery)", text_out)
        self.assertIn(m1, text_out)
        self.assertIn(m2, text_out)
        self.assertIn(msgs[0]["message_hash"], text_out)
        self.assertIn(msgs[1]["previous_hash"], text_out)

        csv_out = self.store.export_csv("archive-recovery")
        reader = list(csv.reader(io.StringIO(csv_out)))
        self.assertEqual(tuple(reader[0]), CSV_COLUMNS)
        self.assertEqual(len(reader), 3)  # header + 2 message rows
        row1, row2 = reader[1], reader[2]
        self.assertEqual(row1[0], m1)
        self.assertEqual(row1[1], "archive-recovery")
        self.assertEqual(row1[2], "sean")
        self.assertEqual(row1[3], 'Line 1 with "quotes", commas, and\nLine 2 in body')
        self.assertEqual(row1[4], "SS-1474")
        self.assertEqual(row1[5], "TASK-9")
        self.assertEqual(row1[8], msgs[0]["message_hash"])
        self.assertEqual(int(row1[9]), 1)
        self.assertEqual(row1[10], "[]")
        self.assertEqual(row2[0], m2)
        self.assertEqual(row2[7], msgs[0]["message_hash"])


class BrainTrustPairingAndWindowTests(unittest.TestCase):
    def setUp(self) -> None:
        _app()
        self.temp = tempfile.TemporaryDirectory()
        self.booth_store = BoothStore(Path(self.temp.name) / "booth.sqlite3")
        self.conv_store = ConversationStore(Path(self.temp.name) / "history.sqlite3")
        self.conv_store.append("archive-recovery", "claude", "Hello room", title="Archive recovery agreement")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_server_invalidates_previous_pair_code_when_new_code_issued_or_cleared(self) -> None:
        c1 = self.booth_store.issue_pair_code("claude", ["booth:read", "booth:write"], ttl=300)
        self.assertTrue(self.booth_store.is_pair_code_active(c1))

        # Issuing a code for another agent invalidates c1 immediately
        c2 = self.booth_store.issue_pair_code("gemini", ["booth:read", "booth:write"], ttl=300)
        self.assertFalse(self.booth_store.is_pair_code_active(c1))
        self.assertIsNone(self.booth_store.consume_pair_code(c1))
        self.assertTrue(self.booth_store.is_pair_code_active(c2))

        # Explicit invalidation clears c2 as well
        self.booth_store.invalidate_pair_codes()
        self.assertFalse(self.booth_store.is_pair_code_active(c2))
        self.assertIsNone(self.booth_store.consume_pair_code(c2))

    def test_window_title_has_version_and_agent_selection_regenerates_with_countdown(self) -> None:
        requests_made: list[dict] = []

        class FakeResponse:
            def __init__(self, data: dict):
                self._raw = json.dumps(data).encode("utf-8")

            def read(self):
                return self._raw

            def __enter__(self):
                return self

            def __exit__(self, *args):
                return False

        def fake_urlopen(req, timeout=15):
            body = json.loads(req.data.decode("utf-8")) if req.data else {}
            requests_made.append(body)
            if body.get("invalidate"):
                self.booth_store.invalidate_pair_codes()
                return FakeResponse({"invalidated": True})
            actor = body["actor"]
            code = self.booth_store.issue_pair_code(actor, ["booth:read", "booth:write"], ttl=300)
            return FakeResponse({"code": code, "actor": actor, "expires_in": 300})

        with patch("troll_booth.desktop.open_conversation_store", return_value=(self.conv_store, "TEST COPY")), \
             patch.dict("os.environ", {"TROLL_BOOTH_WORKER_TOKEN": "test-worker-token"}), \
             patch("urllib.request.urlopen", side_effect=fake_urlopen):
            win = BoothWindow()
            try:
                # SS-1483: version in title bar from __version__
                self.assertIn(troll_booth.__version__, win.windowTitle())
                self.assertEqual(win.windowTitle(), f"BRAIN TRUST - build {troll_booth.__version__}")

                # SS-1475: Selecting an agent invalidates old code, auto-generates a new 5-min code, and shows countdown
                win.pair_actor.setCurrentText("claude")
                win._on_pair_actor_selected(0)
                code_claude = win.pair_code.text()
                self.assertTrue(code_claude)
                self.assertTrue(self.booth_store.is_pair_code_active(code_claude))
                self.assertIn("expires in", win.pair_countdown.text())

                # Switching to gemini invalidates claude's code server-side and generates gemini's code
                win.pair_actor.setCurrentText("gemini")
                win._on_pair_actor_selected(3)
                code_gemini = win.pair_code.text()
                self.assertTrue(code_gemini)
                self.assertNotEqual(code_claude, code_gemini)
                self.assertFalse(self.booth_store.is_pair_code_active(code_claude))
                self.assertTrue(self.booth_store.is_pair_code_active(code_gemini))
                self.assertIn("gemini", win.pair_countdown.text())

                # Countdown reaching zero blanks and invalidates the code
                win.pair_expires_at = 0.01
                with patch("time.monotonic", return_value=100.0):
                    win._tick_pair_countdown()
                self.assertEqual(win.pair_code.text(), "")
                self.assertFalse(self.booth_store.is_pair_code_active(code_gemini))
                self.assertIn("expired", win.pair_countdown.text())
            finally:
                win.close()


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
