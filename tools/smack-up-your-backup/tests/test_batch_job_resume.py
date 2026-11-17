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


# ===== SNAPSMACK EOF =====
