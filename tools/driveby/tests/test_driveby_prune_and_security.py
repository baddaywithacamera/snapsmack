"""Comprehensive security, retention, provider, and audit tests for DRIVE BY (SS-1364)."""

import json
import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtCore import Qt
from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from driveby_core.auth_state import (
    CRED_DRIVE_REVOKE_PENDING,
    CredentialStoreUnavailableError,
    SYBU_DRIVE_TOKEN_KEY,
    get_drive_auth_state,
    revoke_drive_grant,
    save_sybu_drive_authorization,
)
from driveby_core.executor import execute_prune_plan
from driveby_core.models import BackupRecord
from driveby_core.providers import FakeB2Provider, FakeDriveProvider, GoogleDriveProvider
from driveby_core.retention import KeepRule, build_prune_plan
from driveby_qt.main_window import CredentialsDialog, MainWindow
import driveby_qt.main_window as mw_mod


class _TestMemoryCredStore:
    """Test-only in-memory store (production fails closed without snap_creds)."""

    def __init__(self, initial=None):
        self._data: dict[str, str] = dict(initial or {})

    def get(self, key: str, default: str = "") -> str:
        return self._data.get(key, default)

    def set(self, key: str, value: str):
        self._data[key] = str(value)


def _linked_store() -> _TestMemoryCredStore:
    store = _TestMemoryCredStore()
    save_sybu_drive_authorization(
        store,
        json.dumps(
            {
                "token": "ya29.test-access",
                "refresh_token": "1//test-refresh-token",
                "token_uri": "https://oauth2.googleapis.com/token",
                "client_id": "test-client.apps.googleusercontent.com",
                "client_secret": "test-secret",
            }
        ),
    )
    return store


def _app():
    return QApplication.instance() or QApplication([])


# ---------------------------------------------------------------------------
# Blocker 2: Retention safety across (blog, provider, backup_type)
# ---------------------------------------------------------------------------
def test_retention_protects_newest_per_blog_provider_and_backup_type():
    records = [
        BackupRecord("google_drive", "badday", "badday_full_2026-11-20.zip", "full", "2026-11-20", 1000, True, False, "drive-new"),
        BackupRecord("b2", "badday", "badday_db_2026-11-19.sql.gz", "db", "2026-11-19", 200, True, False, "b2-new"),
        BackupRecord("google_drive", "badday", "badday_full_2026-11-10.zip", "full", "2026-11-10", 900, False, False, "drive-old"),
    ]
    plan = build_prune_plan(records, KeepRule(keep_last_n=1, keep_one_per_month=False))
    assert "drive-new" in plan.protected_ids
    assert "b2-new" in plan.protected_ids
    assert [r.remote_id for r in plan.prune] == ["drive-old"]


# ---------------------------------------------------------------------------
# Blocker 1: Provider-specific auth + fresh TOTP on EVERY delete
# ---------------------------------------------------------------------------
def test_unlinked_drive_fails_closed_even_with_typed_confirm_and_totp(tmp_path):
    app = _app()
    empty_store = _TestMemoryCredStore()
    drive = FakeDriveProvider(authenticated=True)
    log_file = str(tmp_path / "deletions.jsonl")

    win = MainWindow(providers=[drive], log_path=log_file, cred_store=empty_store)
    win.setAttribute(Qt.WA_DontShowOnScreen, True)
    win.mode_toggle_btn.setChecked(True)
    app.processEvents()

    assert len(win._plan.prune) > 0
    win.confirm_input.setText(win._expected_confirm_text())
    win.totp_input.setText("123456")
    app.processEvents()

    assert win._grant_blocks_deletes() is True
    assert win.execute_btn.isEnabled() is False
    assert win.execute_prune() == 0
    assert drive.trashed_ids == []
    win.close()


def test_fresh_totp_required_every_delete_and_cannot_be_reused(tmp_path):
    store = _linked_store()
    drive = FakeDriveProvider(authenticated=True)
    log_file = str(tmp_path / "deletions.jsonl")

    app = _app()
    win = MainWindow(
        providers=[drive],
        log_path=log_file,
        cred_store=store,
        stepup_verifier=lambda code: (code in {"111111", "222222"}, "Invalid TOTP"),
    )
    win.setAttribute(Qt.WA_DontShowOnScreen, True)
    win.mode_toggle_btn.setChecked(True)
    win.keep_n_spin.setValue(3)
    win.keep_monthly_cb.setChecked(False)
    win.preview_prune()
    n = len(win._plan.prune)
    assert n >= 1

    # Wrong / rejected TOTP fails closed
    win.confirm_input.setText(win._expected_confirm_text())
    win.totp_input.setText("999999")
    assert win.execute_prune() == 0
    assert drive.trashed_ids == []

    # Valid fresh TOTP succeeds and clears input
    win.confirm_input.setText(win._expected_confirm_text())
    win.totp_input.setText("111111")
    assert win.execute_prune() == n
    assert win.totp_input.text() == ""
    assert len(drive.trashed_ids) == n

    # Reusing the same TOTP code on another delete is rejected
    win.keep_n_spin.setValue(1)
    win.preview_prune()
    win.confirm_input.setText(win._expected_confirm_text())
    win.totp_input.setText("111111")
    assert win.execute_prune() == 0
    assert win._last_execution is not None
    assert "already used" in win._last_execution.message.lower()
    win.close()


# ---------------------------------------------------------------------------
# Blocker 3: snap_creds failure fails CLOSED (no silent memory fallback)
# ---------------------------------------------------------------------------
def test_snap_creds_failure_fails_closed(monkeypatch):
    def _broken_init():
        raise RuntimeError("Windows Credential Manager locked")

    fake_mod = type("FakeSnapCreds", (), {"init": staticmethod(_broken_init)})
    monkeypatch.setitem(sys.modules, "snap_creds", fake_mod)

    try:
        mw_mod._default_cred_store()
        assert False, "Expected CredentialStoreUnavailableError"
    except CredentialStoreUnavailableError as exc:
        assert "unavailable" in str(exc).lower()

    app = _app()
    win = MainWindow(providers=[FakeDriveProvider()], cred_store=None, vault_error="vault locked")
    win.setAttribute(Qt.WA_DontShowOnScreen, True)
    assert win._cred_store is None
    assert win._grant_blocks_deletes() is True
    assert "vault locked" in win.grant_lbl.text().lower()

    dlg = CredentialsDialog(None, vault_error="vault locked")
    assert dlg.save_b2_btn.isEnabled() is False
    ok, msg = dlg.save_b2_keys()
    assert ok is False
    assert "vault locked" in msg.lower()
    win.close()


# ---------------------------------------------------------------------------
# Blocker 4: SYBU/SUYB Drive grant reuse + provider-first revoke
# ---------------------------------------------------------------------------
def test_sybu_drive_grant_reuse_and_provider_first_revoke():
    store = _linked_store()
    state = get_drive_auth_state(store)
    assert state.linked is True
    assert state.deletes_allowed is True
    assert "sybu" in state.message.lower()

    # CredentialsDialog has no refresh-token paste input
    app = _app()
    dlg = CredentialsDialog(store)
    assert not hasattr(dlg, "drive_token_edit")
    assert hasattr(dlg, "connect_drive_btn")

    # Failed provider revoke keeps token and locks deletes
    class _FailResp:
        status_code = 503

    ok, _msg = revoke_drive_grant(store, post_fn=lambda *a, **kw: _FailResp())
    assert ok is False
    assert store.get(SYBU_DRIVE_TOKEN_KEY) != ""
    assert store.get(CRED_DRIVE_REVOKE_PENDING) == "1"
    assert get_drive_auth_state(store).deletes_allowed is False

    # Successful provider revoke clears shared SYBU token
    class _OkResp:
        status_code = 200

    ok, _msg = revoke_drive_grant(store, post_fn=lambda *a, **kw: _OkResp())
    assert ok is True
    assert store.get(SYBU_DRIVE_TOKEN_KEY) == ""
    assert store.get(CRED_DRIVE_REVOKE_PENDING) == ""
    assert get_drive_auth_state(store).linked is False


# ---------------------------------------------------------------------------
# Blocker 5: Real GoogleDriveProvider (list, pagination, trash via update)
# ---------------------------------------------------------------------------
def test_real_google_drive_provider_list_and_trash_never_deletes():
    calls = {"list": [], "update": [], "delete": []}

    class _Exec:
        def __init__(self, payload):
            self._payload = payload

        def execute(self):
            return self._payload

    class _FilesResource:
        def list(self, **kwargs):
            calls["list"].append(kwargs)
            if not kwargs.get("pageToken"):
                return _Exec(
                    {
                        "nextPageToken": "page-2",
                        "files": [
                            {
                                "id": "gd-1",
                                "name": "baddaywithacamera_full_2026-11-20.zip",
                                "size": "2048",
                                "createdTime": "2026-11-20T12:00:00Z",
                                "trashed": False,
                            },
                            {
                                "id": "gd-ignore",
                                "name": "notes.txt",
                                "size": "12",
                                "trashed": False,
                            },
                        ],
                    }
                )
            return _Exec(
                {
                    "files": [
                        {
                            "id": "gd-2",
                            "name": "baddaywithacamera_full_2026-10-01.zip",
                            "size": "1024",
                            "createdTime": "2026-10-01T12:00:00Z",
                            "trashed": False,
                        }
                    ]
                }
            )

        def update(self, **kwargs):
            calls["update"].append(kwargs)
            return _Exec({"id": kwargs["fileId"], "trashed": True})

        def delete(self, **kwargs):
            calls["delete"].append(kwargs)
            raise AssertionError("GoogleDriveProvider must NEVER call files().delete()!")

    class _FakeService:
        def files(self):
            return _FilesResource()

    store = _linked_store()
    prov = GoogleDriveProvider(cred_store=store, service_factory=lambda _info: _FakeService())
    assert prov.is_authenticated() is True

    backups = prov.list_backups()
    assert len(backups) == 2
    assert [b.remote_id for b in backups] == ["gd-1", "gd-2"]
    assert len(calls["list"]) == 2

    res = prov.trash_backup(backups[1])
    assert res["action"] == "drive_trash"
    assert backups[1].trashed is True
    assert calls["update"] == [{"fileId": "gd-2", "body": {"trashed": True}}]
    assert calls["delete"] == []


# ---------------------------------------------------------------------------
# Blocker 6: Audit preflight, intent-before-action, partial failure, dry-run
# ---------------------------------------------------------------------------
def test_dry_run_and_audit_preflight_intent_and_partial_failure(tmp_path):
    store = _linked_store()
    r1 = BackupRecord("google_drive", "blog", "blog_full_2026-11-20.zip", "full", "2026-11-20", 500, True, False, "id-keep")
    r2 = BackupRecord("google_drive", "blog", "blog_full_2026-11-10.zip", "full", "2026-11-10", 400, False, False, "id-ok")
    r3 = BackupRecord("google_drive", "blog", "blog_full_2026-11-01.zip", "full", "2026-11-01", 300, False, False, "id-fail")
    plan = build_prune_plan([r1, r2, r3], KeepRule(keep_last_n=1, keep_one_per_month=False))
    assert {r.remote_id for r in plan.prune} == {"id-ok", "id-fail"}

    events_order = []

    class _PartialFailDrive:
        provider_id = "google_drive"
        recovery_days = 30

        def is_authenticated(self):
            return True

        def trash_backup(self, record):
            events_order.append(("provider", record.remote_id))
            if record.remote_id == "id-fail":
                raise RuntimeError("Drive API 500")
            record.trashed = True
            return {"action": "drive_trash", "remote_id": record.remote_id, "recovery_days": 30}

    prov = _PartialFailDrive()
    log_file = str(tmp_path / "audit.jsonl")

    # 1. Dry-run proves zero provider mutations and zero audit writes
    dry_res = execute_prune_plan(plan, {"google_drive": prov}, cred_store=store, log_path=log_file, dry_run=True)
    assert dry_res.ok is True
    assert dry_res.dry_run is True
    assert dry_res.attempted == 2
    assert dry_res.succeeded == 0
    assert events_order == []
    assert not os.path.exists(log_file)

    # 2. Unwritable audit log aborts BEFORE any provider mutation
    bad_log_path = str(tmp_path)  # directory path cannot be opened as a file
    fail_res = execute_prune_plan(
        plan, {"google_drive": prov}, cred_store=store, totp_code="246810", log_path=bad_log_path, dry_run=False
    )
    assert fail_res.ok is False
    assert "not writable" in fail_res.message.lower()
    assert events_order == []

    # 3. Live execution writes intent BEFORE each action and reports partial failure honestly
    live_res = execute_prune_plan(
        plan, {"google_drive": prov}, cred_store=store, totp_code="246810", log_path=log_file, dry_run=False
    )
    assert live_res.ok is False
    assert live_res.succeeded == 1
    assert live_res.failed == 1
    assert "partial completion" in live_res.message.lower()

    lines = [json.loads(line) for line in open(log_file, encoding="utf-8").read().splitlines()]
    assert [(l["remote_id"], l["phase"], l["status"]) for l in lines] == [
        ("id-ok", "intent", "pending"),
        ("id-ok", "result", "ok"),
        ("id-fail", "intent", "pending"),
        ("id-fail", "result", "error"),
    ]


# ---------------------------------------------------------------------------
# Blocker 7: Honest HQ unavailable state ("coming soon" when exe missing)
# ---------------------------------------------------------------------------
def test_hq_roster_marks_driveby_coming_soon_until_exe_exists():
    hub_main = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "hub", "main.py"))
    src = open(hub_main, encoding="utf-8").read()
    assert '"DRIVE BY"' in src
    assert 'coming soon' in src


# ===== SNAPSMACK EOF =====
