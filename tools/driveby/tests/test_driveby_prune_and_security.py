"""DRIVE BY Slices (b), (c), (d) tests: keep-rules, trash/hide + log + confirm, grant expiry/revoke."""

import json
import os
import pathlib
import sys
import tempfile

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from driveby_core.auth_state import (
    CRED_DRIVE_REVOKE_PENDING, CRED_DRIVE_REFRESH_TOKEN,
    get_grant_status, record_drive_grant, revoke_drive_grant,
    validate_b2_credentials,
)
from driveby_core.models import BackupRecord
from driveby_core.providers import FakeB2Provider, FakeDriveProvider
from driveby_core.retention import KeepRule, build_prune_plan
from driveby_qt.main_window import CredentialsDialog, MainWindow


class _MemoryStore:
    def __init__(self):
        self._d = {}

    def get(self, k, default=""):
        return self._d.get(k, default)

    def set(self, k, v):
        self._d[k] = v


def _app():
    return QApplication.instance() or QApplication([])


def test_retention_never_deletes_newest_or_newest_verified():
    # Newest is unverified (Nov 16); older verified is Nov 01; oldest unverified is Oct 01.
    records = [
        BackupRecord("google_drive", "blog1", "blog1_full_2026-11-16.zip", "full", "2026-11-16", 100, verified=False, remote_id="newest"),
        BackupRecord("google_drive", "blog1", "blog1_full_2026-11-01.zip", "full", "2026-11-01", 100, verified=True, remote_id="newest_ver"),
        BackupRecord("google_drive", "blog1", "blog1_full_2026-10-01.zip", "full", "2026-10-01", 100, verified=False, remote_id="old1"),
    ]
    plan = build_prune_plan(records, KeepRule(keep_last_n=1, keep_one_per_month=False))
    assert "newest" in plan.protected_ids
    assert "newest_ver" in plan.protected_ids
    pruned_ids = {r.remote_id for r in plan.prune}
    assert pruned_ids == {"old1"}
    assert plan.bytes_recovered == 100


def test_prune_mode_typed_confirm_trash_and_append_only_log():
    _app()
    with tempfile.TemporaryDirectory(dir=os.path.dirname(__file__)) as td:
        log_file = pathlib.Path(td) / "deletions.jsonl"
        drive = FakeDriveProvider()
        b2 = FakeB2Provider()
        win = MainWindow(providers=[drive, b2], log_path=str(log_file), cred_store=_MemoryStore())

        assert win.is_read_only() is True
        assert win.execute_prune() == 0

        win.mode_toggle_btn.setChecked(True)
        assert win.is_read_only() is False
        win.keep_n_spin.setValue(1)
        win.keep_monthly_cb.setChecked(False)
        plan = win.preview_prune()
        n = len(plan.prune)
        assert n > 0
        assert not win.execute_btn.isEnabled()

        win.confirm_input.setText(f"DELETE {n} BACKUPS")
        assert win.execute_btn.isEnabled()
        deleted = win.execute_prune()
        assert deleted == n

        # Verify append-only JSONL log recorded all deletions without secrets
        lines = log_file.read_text(encoding="utf-8").strip().splitlines()
        assert len(lines) == n
        sample = json.loads(lines[0])
        assert sample["action"] in ("drive_trash", "b2_hide_file")
        assert sample["recovery_window_days"] == 30


def test_grant_expiry_and_provider_first_revoke():
    store = _MemoryStore()
    record_drive_grant(store, "rt-secret-123", lifetime_days=7, now=1000.0)
    st = get_grant_status(store, now=1000.0 + 3 * 86400.0)
    assert st.linked and not st.expired and st.deletes_allowed

    st_exp = get_grant_status(store, now=1000.0 + 8 * 86400.0)
    assert st_exp.expired and not st_exp.deletes_allowed

    # Failed provider revoke keeps local token and locks deletes
    class _FailResp:
        status_code = 500

    ok, _msg = revoke_drive_grant(store, post_fn=lambda *_a, **_kw: _FailResp())
    assert not ok
    assert store.get(CRED_DRIVE_REFRESH_TOKEN) == "rt-secret-123"
    assert store.get(CRED_DRIVE_REVOKE_PENDING) == "1"
    assert not get_grant_status(store, now=1000.0 + 3 * 86400.0).deletes_allowed

    # Successful provider revoke purges local token
    class _OkResp:
        status_code = 200

    ok2, _msg2 = revoke_drive_grant(store, post_fn=lambda *_a, **_kw: _OkResp())
    assert ok2
    assert store.get(CRED_DRIVE_REFRESH_TOKEN) == ""

    # B2 master key rejection
    b2_ok, b2_msg = validate_b2_credentials("004abc123456", "secret")
    assert not b2_ok and "master" in b2_msg.lower()
    b2_ok2, _ = validate_b2_credentials("K004abcdef1234567890", "secret")
    assert b2_ok2


def test_ui_grant_lock_and_credentials_dialog():
    _app()
    store = _MemoryStore()
    record_drive_grant(store, "rt-ui-secret", lifetime_days=7)

    class _FailResp:
        status_code = 500

    with tempfile.TemporaryDirectory(dir=os.path.dirname(__file__)) as td:
        log_file = pathlib.Path(td) / "deletions.jsonl"
        win = MainWindow(
            providers=[FakeDriveProvider(), FakeB2Provider()],
            log_path=str(log_file),
            cred_store=store,
            post_fn=lambda *_a, **_kw: _FailResp(),
        )
        win.mode_toggle_btn.setChecked(True)
        win.keep_n_spin.setValue(1)
        win.keep_monthly_cb.setChecked(False)
        plan = win.preview_prune()
        win.confirm_input.setText(f"DELETE {len(plan.prune)} BACKUPS")
        assert win.execute_btn.isEnabled()

        # Provider-first revoke fails -> deletes must lock in UI immediately
        ok, _ = win.revoke_drive_grant_action()
        assert not ok
        assert not win.execute_btn.isEnabled()
        assert win.execute_prune() == 0
        assert "REVOKE PENDING" in win.grant_lbl.text()

        # CredentialsDialog rejects B2 master key and accepts bucket-restricted key
        dlg = CredentialsDialog(store, parent=win)
        dlg.b2_key_id_edit.setText("004masterkeyid")
        dlg.b2_app_key_edit.setText("secret")
        b2_ok, b2_msg = dlg.save_b2_keys()
        assert not b2_ok and "master" in b2_msg.lower()

        dlg.b2_key_id_edit.setText("K004restrictedid")
        b2_ok2, _ = dlg.save_b2_keys()
        assert b2_ok2


# ===== SNAPSMACK EOF =====
