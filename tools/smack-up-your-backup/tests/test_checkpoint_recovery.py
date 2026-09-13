"""Crash recovery and pause contracts for the Qt SUYB build.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import json
import os
import sys
import tempfile
import threading
import time

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, HERE)

from backup_engine import BackupEngine
from checkpoint import BackupCheckpoint


def make_checkpoint(root):
    token = "large-example-ca"
    stamp = "2026-09-13_01-02-03"
    media = os.path.join(root, f"{token}_media_{stamp}")
    os.makedirs(media)
    inventory = os.path.join(root, f"{token}_inventory_{stamp}.json")
    sql = os.path.join(root, f"{token}_full_{stamp}.sql")
    with open(inventory, "w", encoding="utf-8") as handle:
        json.dump({"files": {}}, handle)
    with open(sql, "wb") as handle:
        handle.write(b"database")
    cp = BackupCheckpoint(BackupCheckpoint.path_for(root, "Large Example"))
    cp.start("Large Example", stamp,
             os.path.join(root, f"{token}_recovery_kit_{stamp}.tar.gz"),
             sql, "", media, f"{token}_backup_{stamp}.zip", {}, False)
    return cp


def test_resume_does_not_require_final_kit():
    with tempfile.TemporaryDirectory() as root:
        cp = make_checkpoint(root)
        cp.record("photo/a.jpg", downloaded=True)
        loaded = BackupCheckpoint.load(root, "Large Example")
        assert loaded is not None
        assert loaded.already_processed() == {"photo/a.jpg"}


def test_journal_survives_truncated_last_record():
    with tempfile.TemporaryDirectory() as root:
        cp = make_checkpoint(root)
        cp.record("photo/a.jpg", downloaded=True)
        cp.record("photo/b.jpg", skipped=True)
        with open(cp.path + cp.JOURNAL_SUFFIX, "a", encoding="utf-8") as handle:
            handle.write('{"key":"unfinished')
        loaded = BackupCheckpoint.load(root, "Large Example")
        assert loaded.already_processed() == {"photo/a.jpg", "photo/b.jpg"}


def test_pause_waits_and_cancel_releases():
    engine = BackupEngine({})
    engine.pause()
    finished = threading.Event()
    worker = threading.Thread(target=lambda: (engine._wait_if_paused(), finished.set()))
    worker.start()
    time.sleep(0.05)
    assert not finished.is_set()
    engine.resume()
    worker.join(1)
    assert finished.is_set()

    engine.pause(); finished.clear()
    worker = threading.Thread(target=lambda: (engine._wait_if_paused(), finished.set()))
    worker.start(); time.sleep(0.05); engine.cancel(); worker.join(1)
    assert finished.is_set()


def test_qt_background_backup_has_a_real_tray_contract():
    with open(os.path.join(HERE, "suyb_qt.py"), encoding="utf-8") as handle:
        source = handle.read()
    assert "QSystemTrayIcon" in source
    assert 'self.showMinimized()' in source
    assert '"Backup still running"' in source
    assert 'remains available on the taskbar' in source
    assert 'self.tray_pause_action.triggered.connect(self._toggle_pause)' in source
    assert 'app.setQuitOnLastWindowClosed(False)' in source

if __name__ == "__main__":
    test_resume_does_not_require_final_kit()
    test_journal_survives_truncated_last_record()
    test_pause_waits_and_cancel_releases()
    test_qt_background_backup_has_a_real_tray_contract()
    print("PASS: SUYB crash recovery and pause regression suite")

# ===== SNAPSMACK EOF =====
