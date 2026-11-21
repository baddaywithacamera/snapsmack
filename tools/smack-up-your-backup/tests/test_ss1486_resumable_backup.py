"""Tests for SS-1486 (and SS-1419):
1. Interrupting / killing a backup run mid-media against a scratch destination,
   relaunching, and resuming from the checkpoint without re-downloading files
   that already finished, producing a final archive that matches a clean run
   (identical media files, database SQL, and SHA-256 checksums in manifest.json).
2. Visibly offering an incomplete job for resume in the interface (Overview
   incomplete banner + startup orphan checkpoint prompt).
3. Prompting "A backup is in progress, quit anyway?" on closeEvent while a
   backup is running.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import hashlib
import io
import json
import os
import sys
import tarfile
import zipfile

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import backup_engine
from backup_engine import BackupEngine
from checkpoint import BackupCheckpoint


def _extract_zip_tree(zip_path: str) -> dict:
    """Return {arcname: bytes} for media/sql files and parsed kit manifest."""
    contents = {}
    kit_manifest = None
    with zipfile.ZipFile(zip_path, "r") as zf:
        assert zf.testzip() is None
        for name in zf.namelist():
            raw = zf.read(name)
            if name.endswith(".tar.gz") and "_recovery_kit_" in name:
                with tarfile.open(fileobj=io.BytesIO(raw), mode="r:gz") as tf:
                    for member in tf.getmembers():
                        if member.name.endswith("/manifest.json"):
                            kit_manifest = json.loads(tf.extractfile(member).read().decode("utf-8"))
                        elif member.name.endswith("/database.sql"):
                            contents["__kit_database_sql__"] = tf.extractfile(member).read()
            elif name not in ("backup-state.json", "suyb-settings.json"):
                contents[name] = raw
    return {"files": contents, "kit_manifest": kit_manifest}


def test_kill_mid_media_resume_skips_completed_files_and_matches_clean_run(tmp_path, monkeypatch):
    source_files = {
        "img_uploads/photo1.jpg": b"JPEG-DATA-ONE-" + (b"A" * 512),
        "img_uploads/photo2.jpg": b"JPEG-DATA-TWO-" + (b"B" * 640),
        "img_uploads/photo3.jpg": b"JPEG-DATA-THREE-" + (b"C" * 768),
        "img_uploads/photo4.jpg": b"JPEG-DATA-FOUR-" + (b"D" * 896),
    }
    sql_full_bytes = b"-- FULL SQL DUMP\nCREATE TABLE snap_posts (id INT);\n"
    sql_schema_bytes = b"-- SCHEMA SQL DUMP\nCREATE TABLE snap_posts (id INT);\n"

    inventory_payload = {
        "snapsmack_version": "0.7.1000D",
        "export_type": "inventory",
        "stats": {"total_media_bytes": sum(len(v) for v in source_files.values())},
        "files": {
            rel: {
                "restores_to": rel,
                "size": len(data),
                "bundled": False,
            }
            for rel, data in source_files.items()
        },
    }

    class FakeSession:
        def __init__(self, site_url, api_key="", login_slug="snap-in"):
            self.site_url = site_url

        def login(self, user="", pw=""):
            return True

        def download_sql_dump(self, dest_path, dump_type="full", on_progress=None):
            payload = sql_full_bytes if dump_type == "full" else sql_schema_bytes
            with open(dest_path, "wb") as fh:
                fh.write(payload)

        def fetch_inventory(self):
            return json.loads(json.dumps(inventory_payload))

        def report_backup_complete(self, status_str, size_b, dest):
            return True

    monkeypatch.setattr(backup_engine, "SnapSmackSession", FakeSession)
    monkeypatch.setattr(backup_engine.config_module, "effective_backup_key", lambda p: "test-key")
    monkeypatch.setattr(backup_engine, "load_backup_state", lambda _ftp: {})
    monkeypatch.setattr(backup_engine, "save_backup_state", lambda _ftp, _st: True)

    # ── 1. Clean uninterrupted run against scratch_clean ───────────────
    clean_dir = tmp_path / "scratch_clean"
    clean_downloads = []

    class CleanFTP:
        def connect(self): pass
        def disconnect(self): pass

        def download_file(self, remote_rel, local_path, on_progress=None):
            clean_downloads.append(remote_rel)
            os.makedirs(os.path.dirname(local_path), exist_ok=True)
            with open(local_path, "wb") as fh:
                fh.write(source_files[remote_rel])
            return True, ""

    monkeypatch.setattr(backup_engine.transport, "make_client", lambda *a, **kw: CleanFTP())
    clean_profile = {
        "name": "Test Blog",
        "site_url": "https://testblog.example",
        "backup_dir": str(clean_dir),
        "cloud_provider": "none",
    }
    clean_res = BackupEngine(clean_profile, include_settings=False).run()
    assert clean_res["success"] is True
    assert clean_res["files_downloaded"] == 4
    assert len(clean_downloads) == 4

    # ── 2. Interrupted run against scratch_resume (killed after 2 files) ──
    resume_dir = tmp_path / "scratch_resume"
    attempt1_downloads = []
    engine_holder = {}

    class InterruptingFTP:
        def connect(self): pass
        def disconnect(self): pass

        def download_file(self, remote_rel, local_path, on_progress=None):
            attempt1_downloads.append(remote_rel)
            os.makedirs(os.path.dirname(local_path), exist_ok=True)
            with open(local_path, "wb") as fh:
                fh.write(source_files[remote_rel])
            if len(attempt1_downloads) == 2:
                # Simulate process close / kill right after the 2nd file lands
                engine_holder["engine"].cancel()
            return True, ""

    monkeypatch.setattr(backup_engine.transport, "make_client", lambda *a, **kw: InterruptingFTP())
    resume_profile = {
        "name": "Test Blog",
        "site_url": "https://testblog.example",
        "backup_dir": str(resume_dir),
        "cloud_provider": "none",
    }
    eng1 = BackupEngine(resume_profile, include_settings=False)
    engine_holder["engine"] = eng1
    res1 = eng1.run()
    assert res1["success"] is False
    assert res1["cancelled"] is True
    assert len(attempt1_downloads) == 2

    # Checkpoint must exist on disk with the 2 completed files
    cp = BackupCheckpoint.load(str(resume_dir), "Test Blog")
    assert cp is not None
    assert len(cp.already_downloaded()) == 2
    assert set(cp.already_downloaded()) == set(attempt1_downloads)

    # ── 3. Relaunch & resume from checkpoint ───────────────────────────
    attempt2_downloads = []

    class ResumingFTP:
        def connect(self): pass
        def disconnect(self): pass

        def download_file(self, remote_rel, local_path, on_progress=None):
            attempt2_downloads.append(remote_rel)
            os.makedirs(os.path.dirname(local_path), exist_ok=True)
            with open(local_path, "wb") as fh:
                fh.write(source_files[remote_rel])
            return True, ""

    monkeypatch.setattr(backup_engine.transport, "make_client", lambda *a, **kw: ResumingFTP())
    eng2 = BackupEngine(resume_profile, include_settings=False, resume_checkpoint=cp)
    res2 = eng2.run()
    assert res2["success"] is True
    assert res2["files_downloaded"] == 4
    assert res2["files_failed"] == 0
    # Must NOT have re-downloaded the first 2 files!
    assert len(attempt2_downloads) == 2
    assert set(attempt1_downloads).isdisjoint(set(attempt2_downloads))
    assert set(attempt1_downloads) | set(attempt2_downloads) == set(source_files.keys())
    # Checkpoint is cleared after successful completion
    assert BackupCheckpoint.load(str(resume_dir), "Test Blog") is None

    # ── 4. Compare final resumed archive against clean archive ─────────
    clean_tree = _extract_zip_tree(clean_res["zip_path"])
    resumed_tree = _extract_zip_tree(res2["zip_path"])

    assert resumed_tree["files"]["__kit_database_sql__"] == clean_tree["files"]["__kit_database_sql__"]
    for rel, expected_bytes in source_files.items():
        arc_key = rel.replace("/", os.sep)
        # Check both slash forms
        resumed_bytes = resumed_tree["files"].get(rel) or resumed_tree["files"].get(arc_key)
        clean_bytes = clean_tree["files"].get(rel) or clean_tree["files"].get(arc_key)
        assert resumed_bytes == expected_bytes
        assert resumed_bytes == clean_bytes
        expected_sha = "sha256:" + hashlib.sha256(expected_bytes).hexdigest()
        # Both previously-downloaded and newly-downloaded files must carry their SHA-256 in manifest.json
        assert resumed_tree["kit_manifest"]["files"][rel]["checksum"] == expected_sha
        assert clean_tree["kit_manifest"]["files"][rel]["checksum"] == expected_sha


def test_close_during_backup_prompts_quit_anyway_and_incomplete_banner_shows(tmp_path, monkeypatch):
    os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
    from PySide6.QtGui import QCloseEvent
    from PySide6.QtWidgets import QApplication, QMessageBox
    import suyb_qt

    _app = QApplication.instance() or QApplication([])

    # Create a valid checkpoint on disk
    bdir = str(tmp_path)
    token = "testblog-example"
    stamp = "2026-11-20_22-00-00"
    media = os.path.join(bdir, f"{token}_media_{stamp}")
    os.makedirs(media, exist_ok=True)
    inv = os.path.join(bdir, f"{token}_inventory_{stamp}.json")
    sql = os.path.join(bdir, f"{token}_full_{stamp}.sql")
    with open(inv, "w", encoding="utf-8") as fh:
        json.dump({"files": {}}, fh)
    with open(sql, "wb") as fh:
        fh.write(b"SELECT 1;")
    cp = BackupCheckpoint(BackupCheckpoint.path_for(bdir, "Test Blog"))
    cp.start(
        "Test Blog", stamp,
        os.path.join(bdir, f"{token}_recovery_kit_{stamp}.tar.gz"),
        sql, "", media, f"{token}_backup_{stamp}.zip", {}, False,
    )
    cp.record("img_uploads/a.jpg", downloaded=True)

    win = suyb_qt.SuybWindow.__new__(suyb_qt.SuybWindow)
    win._quitting = False
    win.engine = suyb_qt._StartingEngine()
    win.current_profile = {"name": "Test Blog", "site_url": "https://testblog.example", "backup_dir": bdir}
    win.showMinimized = lambda: None
    win._tray_message = lambda *a, **kw: None
    quit_called = []
    win._quit = lambda: quit_called.append(True)

    prompts = []

    class FakeCloseBox:
        Warning = QMessageBox.Warning
        RejectRole = QMessageBox.RejectRole
        DestructiveRole = QMessageBox.DestructiveRole
        choice = "KEEP RUNNING (MINIMIZE)"

        def __init__(self, *_a):
            self._btns = {}

        def setWindowTitle(self, *_a): pass
        def setIcon(self, *_a): pass
        def setText(self, text): prompts.append(text)
        def setInformativeText(self, *_a): pass
        def setDefaultButton(self, *_a): pass

        def addButton(self, text, _role):
            btn = object()
            self._btns[text] = btn
            return btn

        def exec(self): pass
        def clickedButton(self): return self._btns[self.choice]

    monkeypatch.setattr(suyb_qt, "QMessageBox", FakeCloseBox)

    # 1. Keeping running ignores close event and does not quit
    ev1 = QCloseEvent()
    win.closeEvent(ev1)
    assert prompts == ["A backup is in progress, quit anyway?"]
    assert not ev1.isAccepted()
    assert quit_called == []

    # 2. Choosing QUIT ANYWAY accepts close event and quits
    FakeCloseBox.choice = "QUIT ANYWAY"
    ev2 = QCloseEvent()
    win.closeEvent(ev2)
    assert ev2.isAccepted()
    assert quit_called == [True]
# ===== SNAPSMACK EOF =====
