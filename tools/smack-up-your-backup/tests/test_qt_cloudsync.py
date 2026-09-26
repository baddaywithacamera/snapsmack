"""Cloud Sync Qt page + sync job dialog + credential library regressions.

Runs offscreen (QT_QPA_PLATFORM=offscreen), no pytest-qt, no network, no
window ever shown: every dialog/message box is answered by monkeypatching.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import json
import os
import pathlib
import sys
import tempfile

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import pytest
from PySide6.QtWidgets import QApplication, QDialog

import credential_store
import sync_manager
import suyb_qt_cloudsync as cs
import suyb_qt_credentials as qc

APP = QApplication.instance() or QApplication([])

TK_SAVE_KEYS = {
    "name", "source_provider", "source_credentials_file", "source_folder",
    "source_b2_key_id", "source_b2_app_key", "dest_provider",
    "dest_credentials_file", "dest_folder", "dest_b2_key_id", "dest_b2_app_key",
}


class FakeWindow:
    current_profile = None

    def __init__(self):
        self.notes = []

    def notify(self, title, message, critical=False):
        self.notes.append((title, message, critical))

    def busy(self):
        return False


@pytest.fixture
def env(monkeypatch):
    # tempfile, not pytest's tmp_path: the shared pytest-of-<user> folder is not
    # always readable on this machine, and the tests must run anywhere.
    holder = tempfile.TemporaryDirectory()
    tmp_path = pathlib.Path(holder.name)
    jobs = tmp_path / "sync_jobs"; jobs.mkdir()
    monkeypatch.setattr(sync_manager, "SYNC_JOBS_DIR", str(jobs))
    monkeypatch.setattr(credential_store, "STORE_FILE", str(tmp_path / "credentials.json"))
    monkeypatch.setattr(sync_manager.secret_vault, "is_enabled", lambda: False)
    # No token files exist, but never let a dialog reach Google either way.
    monkeypatch.setattr(cs.cloud_module, "get_oauth_token_status", lambda path, readonly=False: "")
    boxes = []
    monkeypatch.setattr(cs, "_info", lambda p, t, m: boxes.append(("info", t, m)))
    monkeypatch.setattr(cs, "_error", lambda p, t, m: boxes.append(("error", t, m)))
    yield {"jobs": jobs, "boxes": boxes, "tmp": tmp_path}
    holder.cleanup()


def make_job(name, **over):
    job = sync_manager.new_job_template()
    job.update(name=name, source_credentials_file="C:/creds/drive.json", source_folder="FOLDER1",
               dest_b2_key_id="KEY", dest_b2_app_key="APPKEY", dest_folder="bucket-one")
    job.update(over)
    sync_manager.save_job(job)
    return job


def page_for():
    page = cs.CloudSyncPage(FakeWindow())
    page.refresh()
    return page


def test_job_list_loads_from_folder(env):
    make_job("Beta"); make_job("Alpha")
    page = page_for()
    assert [page.job_combo.itemText(i) for i in range(page.job_combo.count())] == ["Alpha", "Beta"]
    assert page.current_job_name() == "Alpha"
    assert page.src_lbl.text() == "Source: Google Drive — FOLDER1"
    assert page.dst_lbl.text() == "Destination: Backblaze B2 — bucket-one"


def test_init_touches_no_disk(env, monkeypatch):
    monkeypatch.setattr(sync_manager, "list_jobs", lambda: pytest.fail("disk read in __init__"))
    cs.CloudSyncPage(FakeWindow())


def test_empty_folder_shows_dashes(env):
    page = page_for()
    assert page.job_combo.count() == 0
    assert page.src_lbl.text() == "Source: —"


def test_dialog_result_matches_tk_shape(env):
    template = sync_manager.new_job_template()
    dialog = cs.SyncJobDialog(None, template, title="New Sync Job")
    dialog.name_edit.setText("  Drive to B2  ")
    dialog.source.creds_edit.setText(" C:/c.json ")
    dialog.source.folder_edit.setText(" FID ")
    dialog.dest.key_edit.setText(" K ")
    dialog.dest.appkey_edit.setText(" A ")
    dialog.dest.folder_edit.setText(" bkt ")
    dialog._save()
    result = dialog.result
    assert TK_SAVE_KEYS <= set(result)
    assert set(result) == set(template)            # nothing added, nothing dropped
    assert result["name"] == "Drive to B2"
    assert result["source_provider"] == "google_drive"
    assert result["source_credentials_file"] == "C:/c.json"
    assert result["source_folder"] == "FID"
    assert result["dest_provider"] == "backblaze_b2"
    assert (result["dest_b2_key_id"], result["dest_b2_app_key"], result["dest_folder"]) == ("K", "A", "bkt")
    on_disk = json.loads((env["jobs"] / "Drive to B2.json").read_text())
    assert on_disk == result


def test_dialog_provider_switch_shows_right_rows(env):
    dialog = cs.SyncJobDialog(None, sync_manager.new_job_template())
    src = dialog.source
    assert not src.creds_edit.isHidden() and src.key_edit.isHidden()
    assert src.auth_btn.text() == "Authenticate with Google"
    src.provider_buttons["backblaze_b2"].setChecked(True)
    assert src.creds_edit.isHidden() and not src.key_edit.isHidden()
    assert src.folder_lbl.text() == "Bucket name:"
    assert src.auth_btn.text() == "Test connection"
    assert dialog.build_result()["source_provider"] == "backblaze_b2"


def test_dialog_blank_name_refused(env):
    dialog = cs.SyncJobDialog(None, sync_manager.new_job_template())
    dialog._save()
    assert dialog.result is None
    assert env["boxes"] == [("error", "Name required", "Enter a job name.")]
    assert list(env["jobs"].iterdir()) == []


def test_dialog_rename_deletes_old_file(env):
    job = make_job("Old")
    dialog = cs.SyncJobDialog(None, sync_manager.load_job("Old"))
    dialog.name_edit.setText("New")
    dialog._save()
    assert sorted(p.name for p in env["jobs"].iterdir()) == ["New.json"]
    assert dialog.result["dest_folder"] == job["dest_folder"]


def test_dialog_keeps_unknown_provider(env):
    dialog = cs.SyncJobDialog(None, dict(sync_manager.new_job_template(), name="x", source_provider="box"))
    assert dialog.build_result()["source_provider"] == "box"


def _auto_save(name):
    def exec_(self):
        self.name_edit.setText(name)
        self._save()
        return QDialog.Accepted
    return exec_


def test_new_and_edit_write_job_files(env, monkeypatch):
    page = page_for()
    monkeypatch.setattr(cs.SyncJobDialog, "exec", _auto_save("Fresh"))
    page._new_job()
    assert (env["jobs"] / "Fresh.json").exists()
    assert page.current_job_name() == "Fresh"
    monkeypatch.setattr(cs.SyncJobDialog, "exec", _auto_save("Renamed"))
    page._edit_job()
    assert sorted(p.name for p in env["jobs"].iterdir()) == ["Renamed.json"]
    assert page.current_job_name() == "Renamed"


def test_edit_with_no_job_says_so(env):
    page = page_for()
    page._edit_job()
    assert env["boxes"] == [("info", "No job", "Select a sync job first.")]


def test_delete_asks_and_removes(env, monkeypatch):
    make_job("Gone"); make_job("Stays")
    page = page_for()
    page.job_combo.setCurrentText("Gone")
    monkeypatch.setattr(cs, "_confirm", lambda *a, **k: False)
    page._delete_job()
    assert (env["jobs"] / "Gone.json").exists()
    asked = []
    monkeypatch.setattr(cs, "_confirm", lambda *a, **k: asked.append(k) or True)
    page._delete_job()
    assert asked == [{"danger": True}]
    assert not (env["jobs"] / "Gone.json").exists()
    assert page.current_job_name() == "Stays"


class FakeEngine:
    instances = []
    _build_client = staticmethod(lambda *a, **k: object())

    def __init__(self, config, on_log, on_progress, on_stats, on_done, on_ask=None, scratch_dir=None):
        self.config = config
        self.cb = dict(on_log=on_log, on_progress=on_progress, on_stats=on_stats, on_done=on_done, on_ask=on_ask)
        self.ran = False; self.cancelled = False; self.continued = False
        FakeEngine.instances.append(self)

    def run(self):
        self.ran = True

    def cancel(self):
        self.cancelled = True

    def prompt_continue(self):
        self.continued = True


def _sync_run(relay, work, on_done=None, on_error=None):
    try:
        result = work()
    except Exception as exc:
        if on_error:
            on_error(exc)
        return
    if on_done:
        on_done(result)


@pytest.fixture
def fake_engine(monkeypatch):
    FakeEngine.instances = []
    monkeypatch.setattr(cs, "CloudSyncEngine", FakeEngine)
    monkeypatch.setattr(cs, "run_in_thread", _sync_run)
    return FakeEngine


def test_start_builds_engine_with_job_config(env, fake_engine):
    job = make_job("Run me")
    page = page_for()
    page._start()
    engine = fake_engine.instances[-1]
    assert engine.ran
    assert engine.config == sync_manager.load_job("Run me") == job
    assert not page.run_btn.isEnabled() and page.cancel_btn.isEnabled()
    engine.cb["on_stats"](3, 10, 1, 0, 2048, 4 * 1048576)
    engine.cb["on_progress"](0.3)
    assert page.files_lbl.text() == "Files: 3 / 10 synced   Skipped: 1   Failed: 0"
    assert page.bytes_lbl.text() == "2 KB / 4.0 MB"
    assert page.progress.value() == 300
    engine.cb["on_done"]({"ok": True, "cancelled": False, "files_synced": 9, "bytes_synced": 5000,
                          "files_failed": 0, "error": ""})
    assert page.run_btn.isEnabled() and not page.cancel_btn.isEnabled()
    saved = sync_manager.load_job("Run me")
    assert saved["last_files_synced"] == 9 and saved["last_bytes_synced"] == 5000
    assert saved["last_sync_date"]
    assert "✓ Sync complete — 9 file(s), 5 KB." in page.log.toPlainText()


def test_start_refuses_missing_config(env, fake_engine):
    make_job("Half", dest_b2_app_key="", source_folder="")
    page = page_for()
    page._start()
    assert fake_engine.instances == []
    kind, title, msg = env["boxes"][-1]
    assert title == "Missing config"
    assert "Source folder ID" in msg and "Destination B2 Application Key" in msg


def test_cancel_and_shutdown_cancel_engine(env, fake_engine):
    make_job("J")
    page = page_for()
    page._start()
    engine = fake_engine.instances[-1]
    page._cancel()
    assert engine.cancelled and not page.cancel_btn.isEnabled()
    page._start_time = None
    engine.cancelled = False
    page.shutdown()
    assert engine.cancelled


@pytest.mark.parametrize("answer, attr", [(True, "continued"), (False, "cancelled")])
def test_on_ask_answers_engine(env, fake_engine, monkeypatch, answer, attr):
    import suyb_qt_common
    asked = []
    monkeypatch.setattr(suyb_qt_common, "ask_continue",
                        lambda parent, title, message, abort: asked.append((title, message, abort)) or answer)
    make_job("J")
    page = page_for()
    page._start()
    engine = fake_engine.instances[-1]
    engine.cb["on_ask"]("5 files failed. Continue?")
    assert asked == [("Sync failure", "5 files failed. Continue?", "Abort sync")]
    assert getattr(engine, attr)


def test_audit_refuses_non_b2_destination(env, fake_engine):
    make_job("Drive", dest_provider="google_drive", dest_credentials_file="x.json")
    page = page_for()
    page._audit_cleanup()
    assert env["boxes"][-1] == ("info", "B2 only", "Audit & Cleanup only works on Backblaze B2 destinations.")


def test_audit_confirm_then_cleanup(env, fake_engine, monkeypatch):
    import b2_integrity as bi
    make_job("Aud")
    monkeypatch.setattr(cs.cloud_module, "B2Client", lambda *a: "dst")
    monkeypatch.setattr(cs, "_manifests_dir", lambda: str(env["tmp"] / "manifests"))
    monkeypatch.setattr(bi, "inventory_b2", lambda c, log: {"a.jpg": [{"size": 1, "sha1": "x"}]})
    monkeypatch.setattr(bi, "inventory_source", lambda c, log: {"a.jpg": {"size": 2, "md5": "m"}})
    monkeypatch.setattr(bi, "generate_dedup_report", lambda s, d, log: [
        {"filename": "a.jpg", "action": "BAD_SIZE_REPLACE", "b2_version_id": "v1"}])
    ran = {}

    def fake_cleanup(**kw):
        ran.update(kw)
        return {"deleted": 0, "replaced": 1, "failed": 0, "missing_from_source": 0, "log_rows": []}
    monkeypatch.setattr(bi, "execute_cleanup", fake_cleanup)

    page = page_for()
    monkeypatch.setattr(cs, "_confirm", lambda *a, **k: False)
    page._audit_cleanup()
    assert ran == {} and "Cleanup cancelled." in page.log.toPlainText()
    assert page.run_btn.isEnabled() and page.audit_btn.isEnabled()

    questions = []
    monkeypatch.setattr(cs, "_confirm", lambda p, title, msg, yes, danger=False: questions.append((title, msg, danger)) or True)
    page._audit_cleanup()
    assert questions[0][0] == "Confirm Cleanup" and questions[0][2] is True
    assert "Files to re-transfer: 1" in questions[0][1]
    assert ran["b2_client"] == "dst" and ran["cancelled"]() is False
    assert "1 file(s) re-transferred" in page.log.toPlainText()
    assert page.run_btn.isEnabled()


def test_help_topics_ported(env):
    titles = [t for t, _ in cs.CloudSyncPage(FakeWindow()).help_topics()]
    assert "Cloud Sync" in titles and "Audit & Cleanup" in titles


# --- credential library ------------------------------------------------------

def test_cred_library_add_rename_remove_use(env, monkeypatch):
    path = str(env["tmp"] / "drive.json")
    monkeypatch.setattr(qc, "_pick_json_file", lambda parent: path)
    monkeypatch.setattr(qc, "_ask_name", lambda parent, title, initial="": initial)
    dialog = qc.CredLibraryDialog(None)
    assert not dialog.use_btn.isEnabled()
    dialog._add()
    assert credential_store.load() == [{"name": "drive", "path": path}]
    assert dialog.use_btn.isEnabled() and dialog.path_label.text() == path
    monkeypatch.setattr(qc, "_ask_name", lambda parent, title, initial="": "My Drive")
    dialog._rename()
    assert credential_store.names() == ["My Drive"]
    picked = []
    dialog._on_select = lambda n, p: picked.append((n, p))
    dialog._use_selected()
    assert picked == [("My Drive", path)]
    assert (dialog.selected_name, dialog.selected_path) == ("My Drive", path)
    dialog2 = qc.CredLibraryDialog(None)
    dialog2.list.setCurrentRow(0)
    monkeypatch.setattr(qc, "_confirm", lambda *a, **k: True)
    dialog2._remove()
    assert credential_store.load() == []


def test_choose_credential_returns_pick_or_none(env, monkeypatch):
    credential_store.add_or_update("Box", "C:/b.json")

    def pick(self):
        self.list.setCurrentRow(0); self._use_selected(); return QDialog.Accepted
    monkeypatch.setattr(qc.CredLibraryDialog, "exec", pick)
    assert qc.choose_credential(None) == ("Box", "C:/b.json")
    monkeypatch.setattr(qc.CredLibraryDialog, "exec", lambda self: QDialog.Rejected)
    assert qc.choose_credential(None) is None


def test_offer_save_skips_known_and_saves_new(env, monkeypatch):
    credential_store.add_or_update("Known", "C:/k.json")
    monkeypatch.setattr(qc, "_confirm", lambda *a, **k: pytest.fail("asked about a known file"))
    qc.offer_save_to_library(None, "C:/k.json")
    monkeypatch.setattr(qc, "_confirm", lambda *a, **k: True)
    monkeypatch.setattr(qc, "_ask_name", lambda parent, title, initial="": initial)
    qc.offer_save_to_library(None, "C:/new_creds.json")
    assert credential_store.path_for("new_creds") == "C:/new_creds.json"

# ===== SNAPSMACK EOF =====
