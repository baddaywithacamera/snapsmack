"""Tests for SS-1413: SUYB is write-only in the cloud; b2_integrity reports without deleting."""

import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

import b2_integrity as bi
import cloud_client as cc


def test_all_cloud_clients_refuse_delete():
    drive = cc.DriveClient.__new__(cc.DriveClient)
    box = cc.BoxClient.__new__(cc.BoxClient)
    b2 = cc.B2Client.__new__(cc.B2Client)

    with pytest.raises(PermissionError, match="write-only"):
        drive.delete_file("id-1", "a.zip")
    with pytest.raises(PermissionError, match="write-only"):
        box.delete_file("id-2", "b.zip")
    with pytest.raises(PermissionError, match="write-only"):
        b2.delete_file("id-3", "c.zip")
    with pytest.raises(PermissionError, match="write-only"):
        b2.delete_file_version("id-3", "c.zip")


def test_b2_integrity_reports_without_deleting(tmp_path):
    class _FakeB2:
        def __init__(self):
            self.delete_calls = 0

        def delete_file_version(self, *a, **kw):
            self.delete_calls += 1
            raise AssertionError("b2_integrity must never call delete_file_version")

    b2 = _FakeB2()
    report = [
        {
            "filename": "dup-backup.zip",
            "action": "DELETE_DUPLICATE",
            "b2_version_id": "ver-123",
            "b2_size": 1024,
        }
    ]
    res = bi.execute_cleanup(
        b2_client=b2,
        src_client=object(),
        src_inventory={},
        report=report,
        scratch_dir=str(tmp_path),
        on_log=lambda _m: None,
        on_progress=lambda _p: None,
        cancelled=lambda: False,
    )
    assert b2.delete_calls == 0
    assert res["deleted"] == 0
    assert res["reported"] == 1
    assert res["log_rows"][0]["action"] == "REPORT_ONLY"
    assert res["log_rows"][0]["status"] == "NOT_DELETED"


# ===== SNAPSMACK EOF =====
