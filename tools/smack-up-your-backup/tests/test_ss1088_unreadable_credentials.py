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

# ===== SNAPSMACK EOF =====
