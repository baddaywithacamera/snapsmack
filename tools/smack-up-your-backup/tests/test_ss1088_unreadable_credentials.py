"""SS-1088 regression tests:
- load_profile() distinguishes unreadable/corrupt sealed credentials from absent
- save_profile() refuses to overwrite unreadable credentials on disk and raises
  UnreadableCredentialsError, preserving the on-disk file byte-for-byte
"""

import json
import os
import sys

import pytest

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

import profile_manager


class _FakeVault:
    def __init__(self, enabled=True, unlocked=True):
        self.enabled = enabled
        self.unlocked = unlocked

    def is_enabled(self):
        return self.enabled

    def is_unlocked(self):
        return self.unlocked

    def is_encrypted(self, blob):
        return isinstance(blob, str) and blob.startswith("enc1:")

    def encrypt(self, plain):
        return f"enc1:ok:{plain}"

    def decrypt(self, blob):
        if not self.unlocked:
            raise RuntimeError("locked")
        if not blob.startswith("enc1:ok:"):
            raise ValueError("corrupt ciphertext")
        return blob[len("enc1:ok:"):]


def _setup_profiles(monkeypatch, tmp_path, vault):
    monkeypatch.setattr(profile_manager, "PROFILES_DIR", str(tmp_path))
    monkeypatch.setattr(profile_manager, "secret_vault", vault)
    monkeypatch.setattr(profile_manager, "sync_shared_profiles", lambda: 0)


def test_absent_credentials_are_not_marked_unreadable_and_save_succeeds(monkeypatch, tmp_path):
    vault = _FakeVault(enabled=True, unlocked=True)
    _setup_profiles(monkeypatch, tmp_path, vault)

    p = {
        "name": "CleanSite",
        "site_url": "https://clean.example",
        "backup_dir": str(tmp_path / "b1"),
        "ftp_pass": "",
        "snap_admin_pass": "",
        "api_key": "",
    }
    profile_manager.save_profile(p)
    loaded = profile_manager.load_profile("CleanSite")
    assert loaded is not None
    assert "_unreadable_credentials" not in loaded
    loaded["backup_dir"] = str(tmp_path / "b2")
    profile_manager.save_profile(loaded)
    assert profile_manager.load_profile("CleanSite")["backup_dir"] == str(tmp_path / "b2")


def test_corrupt_sealed_credentials_unlocked_vault_refuses_overwrite(monkeypatch, tmp_path):
    vault = _FakeVault(enabled=True, unlocked=True)
    _setup_profiles(monkeypatch, tmp_path, vault)

    prof_path = tmp_path / "CorruptSite.json"
    raw = {
        "name": "CorruptSite",
        "site_url": "https://corrupt.example",
        "backup_dir": str(tmp_path / "orig"),
        "ftp_pass_enc": "enc1:CORRUPT_FTP",
        "snap_admin_pass_enc": "enc1:CORRUPT_ADMIN",
        "api_key": "enc1:CORRUPT_KEY",
    }
    prof_path.write_text(json.dumps(raw, indent=2), encoding="utf-8")
    before_bytes = prof_path.read_bytes()

    loaded = profile_manager.load_profile("CorruptSite")
    assert loaded is not None
    assert loaded["ftp_pass"] == ""
    assert loaded["snap_admin_pass"] == ""
    assert loaded["api_key"] == ""
    assert loaded["_unreadable_credentials"] == ["ftp_pass", "snap_admin_pass", "api_key"]

    # Unrelated save must raise and leave disk bytes untouched
    loaded["backup_dir"] = str(tmp_path / "changed")
    with pytest.raises(profile_manager.UnreadableCredentialsError, match="could not be decrypted"):
        profile_manager.save_profile(loaded)
    assert prof_path.read_bytes() == before_bytes

    # Even if a caller strips _unreadable_credentials, disk check still refuses overwrite
    stripped = dict(loaded)
    stripped.pop("_unreadable_credentials", None)
    with pytest.raises(profile_manager.UnreadableCredentialsError, match="could not be decrypted"):
        profile_manager.save_profile(stripped)
    assert prof_path.read_bytes() == before_bytes


def test_locked_vault_marks_unreadable_and_refuses_overwrite(monkeypatch, tmp_path):
    vault = _FakeVault(enabled=True, unlocked=True)
    _setup_profiles(monkeypatch, tmp_path, vault)

    profile_manager.save_profile({
        "name": "LockedSite",
        "site_url": "https://locked.example",
        "backup_dir": str(tmp_path / "orig"),
        "ftp_pass": "secret-ftp",
        "snap_admin_pass": "secret-admin",
        "api_key": "secret-key",
    })
    prof_path = tmp_path / "LockedSite.json"
    before_bytes = prof_path.read_bytes()

    vault.unlocked = False
    loaded = profile_manager.load_profile("LockedSite")
    assert loaded["_unreadable_credentials"] == ["ftp_pass", "snap_admin_pass", "api_key"]
    loaded["backup_dir"] = str(tmp_path / "changed")
    with pytest.raises(profile_manager.UnreadableCredentialsError):
        profile_manager.save_profile(loaded)
    assert prof_path.read_bytes() == before_bytes


def test_readable_sealed_credentials_can_be_deliberately_cleared(monkeypatch, tmp_path):
    vault = _FakeVault(enabled=True, unlocked=True)
    _setup_profiles(monkeypatch, tmp_path, vault)

    profile_manager.save_profile({
        "name": "ClearableSite",
        "site_url": "https://clear.example",
        "backup_dir": str(tmp_path / "orig"),
        "ftp_pass": "secret-ftp",
        "snap_admin_pass": "secret-admin",
        "api_key": "secret-key",
    })
    loaded = profile_manager.load_profile("ClearableSite")
    assert "_unreadable_credentials" not in loaded
    assert loaded["ftp_pass"] == "secret-ftp"

    loaded["ftp_pass"] = ""
    loaded["snap_admin_pass"] = ""
    loaded["api_key"] = ""
    profile_manager.save_profile(loaded)

    reloaded = profile_manager.load_profile("ClearableSite")
    assert reloaded["ftp_pass"] == ""
    assert reloaded["snap_admin_pass"] == ""
    assert reloaded["api_key"] == ""


def test_qt_save_profile_safe_and_backup_done_notify_user(monkeypatch, tmp_path):
    from PySide6.QtWidgets import QApplication, QMessageBox
    import suyb_qt

    QApplication.instance() or QApplication([])
    vault = _FakeVault(enabled=True, unlocked=True)
    _setup_profiles(monkeypatch, tmp_path, vault)

    prof_path = tmp_path / "UiCorrupt.json"
    raw = {
        "name": "UiCorrupt",
        "site_url": "https://uicorrupt.example",
        "backup_dir": str(tmp_path / "orig"),
        "ftp_pass_enc": "enc1:CORRUPT_FTP",
        "snap_admin_pass_enc": "enc1:CORRUPT_ADMIN",
        "api_key": "enc1:CORRUPT_KEY",
    }
    prof_path.write_text(json.dumps(raw, indent=2), encoding="utf-8")
    before_bytes = prof_path.read_bytes()

    alerts = []
    monkeypatch.setattr(QMessageBox, "critical", staticmethod(lambda _p, t, m: alerts.append((t, m))))
    monkeypatch.setattr(QMessageBox, "warning", staticmethod(lambda _p, t, m: alerts.append((t, m))))

    dummy = type("DummyWin", (), {
        "current_profile": profile_manager.load_profile("UiCorrupt"),
        "_save_profile_safe": suyb_qt.SuybWindow._save_profile_safe,
        "_load_profiles": lambda self, select=None: None,
        "_update_backup_selection": lambda self: None,
        "_page_widgets": [],
    })()

    # _dup_profile path via _save_profile_safe
    ok = dummy._save_profile_safe(action=lambda: profile_manager.duplicate_profile("UiCorrupt", "UiCorrupt (copy)"))
    assert ok is False
    assert alerts and "could not be decrypted" in alerts[-1][1]
    assert prof_path.read_bytes() == before_bytes

    # _edit_profile save path via _save_profile_safe
    edited = dict(dummy.current_profile)
    edited["backup_dir"] = str(tmp_path / "new")
    ok2 = dummy._save_profile_safe(edited)
    assert ok2 is False
    assert len(alerts) == 2
    assert prof_path.read_bytes() == before_bytes

# ===== SNAPSMACK EOF =====
