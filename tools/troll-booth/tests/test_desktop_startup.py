"""TROLL BOOTH must never start into nothing.

19 October 2026. Sean: "there were multiple troll booth apps running and i
think one was supposed to show in the system tray but was not." Two separate
ways the app could be running and invisible:

  * A second launch hit a bare `return` after the single-instance lock -- no
    window, no tray icon, no message. Nothing told anyone why.
  * `_build_tray` never asked whether a notification area exists. Qt hands
    back a QSystemTrayIcon that simply never appears, and with
    setQuitOnLastWindowClosed(False) closing the window hid it to nowhere.

Measured while fixing it, because the first explanation was wrong: Qt DOES
reclaim a lock whose owner has died, even with setStaleLockTime(0). A stale
desktop.lock is not what blocks a launch -- a live instance is. These are
source assertions because the behaviour needs a desktop session; the lock
semantics underneath are exercised against the real QLockFile.
"""

from __future__ import annotations

import subprocess
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

from PySide6.QtCore import QLockFile

DESKTOP = (Path(__file__).resolve().parents[1] / "troll_booth" / "desktop.py").read_text(encoding="utf-8")


class SingleInstanceTests(unittest.TestCase):
    def test_second_instance_is_reported_not_swallowed(self) -> None:
        self.assertIn("QMessageBox.information", DESKTOP)
        self.assertIn("TROLL BOOTH is already running", DESKTOP)

    def test_lock_info_is_unpacked_as_three_values(self) -> None:
        # PySide6 returns (pid, hostname, appname). Unpacking four raises
        # ValueError at the exact moment the message is meant to appear.
        self.assertIn("pid, host, appname = instance_lock.getLockInfo()", DESKTOP)

    def test_a_live_owner_blocks_and_names_itself(self) -> None:
        holder = Path(tempfile.mkdtemp()) / "holder.py"
        holder.write_text(textwrap.dedent("""
            import sys, time
            from PySide6.QtCore import QLockFile
            lf = QLockFile(sys.argv[1]); lf.setStaleLockTime(0)
            print("locked" if lf.tryLock(1000) else "failed", flush=True)
            time.sleep(600)
        """), encoding="utf-8")
        target = Path(tempfile.mkdtemp()) / "desktop.lock"
        proc = subprocess.Popen([sys.executable, str(holder), str(target)],
                                stdout=subprocess.PIPE, text=True)
        try:
            self.assertEqual(proc.stdout.readline().strip(), "locked")
            lock = QLockFile(str(target))
            lock.setStaleLockTime(30_000)
            acquired = lock.tryLock(250)
            if not acquired:
                lock.removeStaleLockFile()
                acquired = lock.tryLock(250)
            self.assertFalse(acquired, "a running instance should still hold the lock")
            pid, host, _appname = lock.getLockInfo()
            # Not proc.pid: a venv launcher re-execs the base interpreter, so
            # the lock records the child. Codex made the same point about the
            # worker's two processes, and it holds here.
            self.assertGreater(pid, 0, "the message has no process to name")
            self.assertTrue(host)
        finally:
            proc.kill()
            proc.wait()

    def test_a_dead_owner_does_not_block(self) -> None:
        # The reason the fix is a message and not stale-lock handling.
        holder = Path(tempfile.mkdtemp()) / "holder.py"
        holder.write_text(textwrap.dedent("""
            import sys, time
            from PySide6.QtCore import QLockFile
            lf = QLockFile(sys.argv[1]); lf.setStaleLockTime(0)
            print("locked" if lf.tryLock(1000) else "failed", flush=True)
            time.sleep(600)
        """), encoding="utf-8")
        target = Path(tempfile.mkdtemp()) / "desktop.lock"
        proc = subprocess.Popen([sys.executable, str(holder), str(target)],
                                stdout=subprocess.PIPE, text=True)
        self.assertEqual(proc.stdout.readline().strip(), "locked")
        proc.kill()
        proc.wait()
        lock = QLockFile(str(target))
        lock.setStaleLockTime(30_000)
        self.assertTrue(lock.tryLock(500), "a dead owner must not lock the app out")
        lock.unlock()


class TrayTests(unittest.TestCase):
    def test_tray_availability_is_checked(self) -> None:
        self.assertIn("QSystemTrayIcon.isSystemTrayAvailable()", DESKTOP)
        self.assertIn("self.tray = None", DESKTOP)

    def test_close_quits_when_there_is_no_tray(self) -> None:
        # Otherwise the window hides into a notification area that is not
        # there, and setQuitOnLastWindowClosed(False) keeps it alive unseen.
        self.assertIn("if self.tray is None:", DESKTOP)
        self.assertIn("QApplication.instance().quit()", DESKTOP)

    def test_notifications_tolerate_a_missing_tray(self) -> None:
        self.assertIn("if self.tray is not None:", DESKTOP)


class TranscriptScrollTests(unittest.TestCase):
    """The newest message should be on screen without scrolling.

    Sean: "can it stay at the bottom by default? i have to keep scrolling
    down." The code already set the scrollbar to its maximum -- on the line
    after setHtml(), which lays out asynchronously, so the value read was the
    previous document's and the view stayed at the top.
    """

    def test_scroll_is_deferred_past_the_layout(self) -> None:
        self.assertIn("QTimer.singleShot(0, to_end)", DESKTOP)

    def test_refresh_does_not_drag_the_view(self) -> None:
        # refresh_messages() runs every 2.5s while visible. Forcing the
        # bottom each time would yank the page away from anyone reading back.
        self.assertIn("room_changed or at_end", DESKTOP)
        self.assertIn("_rendered_room", DESKTOP)


if __name__ == "__main__":
    unittest.main()
