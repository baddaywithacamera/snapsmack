"""Regression tests for SS-1404: persistent fleet batch-job receipts & resume."""

import json
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import batch_job as bj


def test_batch_job_creation_sanitizes_secrets_and_persists_atomically(tmp_path):
    job = bj.BatchJob.create(
        ["HAL 9000", "craptasti.ca", "found textures"],
        options={
            "force_full": False,
            "exit_package": True,
            "cloud_required": True,
            "api_key": "super-secret-api-key",
            "password": "my-password",
            "refresh_token": "rt-secret",
        },
        jobs_dir=str(tmp_path),
    )
    raw = open(job.path, encoding="utf-8").read()
    assert "super-secret-api-key" not in raw
    assert "my-password" not in raw
    assert "rt-secret" not in raw
    assert job.is_nonterminal
    assert bj.find_nonterminal_jobs(str(tmp_path))[0].job_id == job.job_id


def test_interruption_at_every_boundary_and_resume_plan(tmp_path):
    sites = ["HAL 9000", "craptasti.ca", "found textures", "pixhellated"]
    job = bj.BatchJob.create(
        sites,
        options={"cloud_required": True, "exit_package": False},
        jobs_dir=str(tmp_path),
    )

    # 1. Site 0 completes (zip_verified + cloud_verified)
    job.mark_site_running("HAL 9000")
    st0 = job.mark_site_result(
        "HAL 9000", zip_verified=True, cloud_required=True, cloud_verified=True,
        evidence={"zip_path": "hal.zip", "bytes": 163723},
    )
    assert st0 == "completed"

    # 2. Boundary A: killed mid-file on site 1 -> still running/nonterminal
    job.mark_site_running("craptasti.ca")
    reloaded_a = bj.find_nonterminal_jobs(str(tmp_path))[0]
    completed_a, remaining_a = reloaded_a.resume_plan()
    assert completed_a == ["HAL 9000"]
    assert remaining_a == ["craptasti.ca", "found textures", "pixhellated"]

    # 3. Boundary B: ZIP written on disk, but zip_verified=False -> NOT complete
    st1_unverified = reloaded_a.mark_site_result(
        "craptasti.ca", zip_verified=False, cloud_required=True, cloud_verified=False,
        evidence={"zip_path": "craptasti.zip"},
    )
    assert st1_unverified == "failed"

    # 4. Boundary C: ZIP verified, but cloud copy did NOT finish -> NOT complete
    st1_nocloud = reloaded_a.mark_site_result(
        "craptasti.ca", zip_verified=True, cloud_required=True, cloud_verified=False,
        evidence={"zip_path": "craptasti.zip"},
    )
    assert st1_nocloud == "failed"

    # 5. Boundary D: interrupted between sites (craptasti.ca cancelled/interrupted)
    st1_int = reloaded_a.mark_site_result(
        "craptasti.ca", zip_verified=False, cloud_required=True, cloud_verified=False,
        cancelled=True,
    )
    assert st1_int == "interrupted"
    assert reloaded_a.is_nonterminal
    completed_d, remaining_d = reloaded_a.resume_plan()
    assert completed_d == ["HAL 9000"]
    assert remaining_d[0] == "craptasti.ca"

    # 6. Finish remaining sites -> batch transitions to terminal 'completed'
    for s in remaining_d:
        reloaded_a.mark_site_running(s)
        reloaded_a.mark_site_result(s, zip_verified=True, cloud_required=True, cloud_verified=True)
    assert reloaded_a.finalize_if_done() == "completed"
    assert bj.find_nonterminal_jobs(str(tmp_path)) == []


def test_start_fresh_abandon_clears_nonterminal_and_preserves_receipt(tmp_path):
    job = bj.BatchJob.create(["siteA", "siteB"], jobs_dir=str(tmp_path))
    assert len(bj.find_nonterminal_jobs(str(tmp_path))) == 1
    job.abandon()
    assert bj.find_nonterminal_jobs(str(tmp_path)) == []
    # Receipt file still exists on disk as readable history
    data = json.load(open(job.path, encoding="utf-8"))
    assert data["status"] == "abandoned"


def test_corrupt_receipt_quarantined_and_reconcile_from_disk(tmp_path):
    import zipfile

    bad_path = tmp_path / "batch-bad.json"
    bad_path.write_text("{corrupt json", encoding="utf-8")

    jobs, quarantined = bj.find_nonterminal_and_corrupt(str(tmp_path))
    assert jobs == []
    assert len(quarantined) == 1
    assert ".corrupt-" in quarantined[0]
    assert not bad_path.exists()
    assert os.path.exists(quarantined[0])

    # Crash between verify and receipt write -> reconcile_site_from_disk marks complete without re-run
    zpath = tmp_path / "site1.zip"
    with zipfile.ZipFile(zpath, "w") as zf:
        zf.writestr("db.sql", "SELECT 1;")
    job = bj.BatchJob.create(["site1", "site2"], options={"cloud_required": False}, jobs_dir=str(tmp_path))
    job.mark_site_running("site1")
    assert job.reconcile_site_from_disk("site1", str(zpath)) is True
    completed, remaining = job.resume_plan()
    assert completed == ["site1"]
    assert remaining == ["site2"]


def test_startup_start_fresh_starts_new_batch_and_discard_does_not(tmp_path, monkeypatch):
    os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
    from PySide6.QtWidgets import QApplication, QMessageBox
    import suyb_qt

    _app = QApplication.instance() or QApplication([])
    monkeypatch.setenv("SUYB_BATCH_JOBS_DIR", str(tmp_path))

    # Stub SuybWindow without full profile/UI init
    win = suyb_qt.SuybWindow.__new__(suyb_qt.SuybWindow)
    win._batch_jobs_dir = str(tmp_path)
    win.busy = lambda: False

    started = []
    win._start_backup = lambda names, **kw: started.append(list(names)) or True

    job = bj.BatchJob.create(["alpha", "beta"], jobs_dir=str(tmp_path))
    monkeypatch.setattr(suyb_qt.profile_manager, "load_profile", lambda _n: {})

    class _FakeBox:
        Information = QMessageBox.Information
        AcceptRole = QMessageBox.AcceptRole
        DestructiveRole = QMessageBox.DestructiveRole
        RejectRole = QMessageBox.RejectRole

        def __init__(self, *_a):
            self._btns = {}
            self._choice = "START FRESH"

        def setWindowTitle(self, *_a): pass
        def setIcon(self, *_a): pass
        def setText(self, *_a): pass
        def setInformativeText(self, *_a): pass
        def setDefaultButton(self, *_a): pass

        def addButton(self, text, _role):
            btn = object()
            self._btns[text] = btn
            return btn

        def exec(self): pass
        def clickedButton(self): return self._btns[self._choice]

    # 1. START FRESH abandons old job AND starts a fresh batch with the same site list
    monkeypatch.setattr(suyb_qt, "QMessageBox", _FakeBox)
    win._check_interrupted_batch_job()
    assert started == [["alpha", "beta"]]
    assert bj.BatchJob.load(job.path).status == "abandoned"

    # 2. DISCARD abandons old job WITHOUT starting a batch
    started.clear()
    job2 = bj.BatchJob.create(["alpha", "beta"], jobs_dir=str(tmp_path))

    class _DiscardBox(_FakeBox):
        def __init__(self, *_a):
            super().__init__(*_a)
            self._choice = "DISCARD"

    monkeypatch.setattr(suyb_qt, "QMessageBox", _DiscardBox)
    win._check_interrupted_batch_job()
    assert started == []
    assert bj.BatchJob.load(job2.path).status == "abandoned"


# ===== SNAPSMACK EOF =====
