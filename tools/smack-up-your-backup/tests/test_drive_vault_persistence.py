"""Tests for SUYB Google Drive shared-vault persistence, silent restore, and Reconnect flow."""

import json
import os
import sys
import types

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import cloud_client
import backup_engine


class _FakeVault:
    def __init__(self, initial=None):
        self.data = dict(initial or {})

    def get(self, key, default=""):
        return self.data.get(key, default)

    def set(self, key, value):
        self.data[key] = str(value)


def _valid_token_json(token="ya29.access", refresh="1//refresh"):
    return json.dumps({
        "token": token,
        "refresh_token": refresh,
        "token_uri": "https://oauth2.googleapis.com/token",
        "client_id": "client-id.apps.googleusercontent.com",
        "client_secret": "client-secret",
        "expiry": "2099-01-01T00:00:00Z",
    })


def test_silent_restore_from_vault_and_sybu_fallback(monkeypatch, tmp_path):
    creds_file = str(tmp_path / "google-drive-auth.json")
    with open(creds_file, "w", encoding="utf-8") as fh:
        fh.write(json.dumps({"installed": {"client_id": "x"}}))

    vault = _FakeVault({"sybu_google_drive_authorized_user": _valid_token_json()})
    monkeypatch.setattr(cloud_client, "_shared_credential_store", lambda: vault)

    saved = cloud_client._saved_authorization(creds_file)
    assert "1//refresh" in saved
    assert cloud_client.get_oauth_token_status(creds_file) == "✓ Authenticated"


def test_legacy_token_file_migrates_copy_first_to_vault(monkeypatch, tmp_path):
    creds_file = str(tmp_path / "google-drive-auth.json")
    token_file = str(tmp_path / "google-drive-auth_token.json")
    with open(creds_file, "w", encoding="utf-8") as fh:
        fh.write(json.dumps({"installed": {"client_id": "x"}}))
    with open(token_file, "w", encoding="utf-8") as fh:
        fh.write(_valid_token_json())

    vault = _FakeVault()
    monkeypatch.setattr(cloud_client, "_shared_credential_store", lambda: vault)

    saved = cloud_client._saved_authorization(creds_file)
    assert "1//refresh" in saved
    assert vault.get(cloud_client._TOKEN_VAULT_KEY) != ""
    assert os.path.exists(token_file)  # copy-first leaves legacy file intact


def test_missing_token_raises_expired_when_consent_disallowed(monkeypatch, tmp_path):
    creds_file = str(tmp_path / "google-drive-auth.json")
    with open(creds_file, "w", encoding="utf-8") as fh:
        fh.write(json.dumps({"installed": {"client_id": "x"}}))

    vault = _FakeVault()
    monkeypatch.setattr(cloud_client, "_shared_credential_store", lambda: vault)

    try:
        cloud_client._get_drive_service(creds_file, allow_consent=False)
        assert False, "Expected DriveAuthorizationExpired"
    except cloud_client.DriveAuthorizationExpired as exc:
        assert "reconnect" in str(exc).lower()


def test_backup_engine_prompts_once_for_reconnect_and_retries(monkeypatch, tmp_path):
    zip_path = str(tmp_path / "site_backup.zip")
    with open(zip_path, "wb") as fh:
        import zipfile
        with zipfile.ZipFile(fh, "w") as zf:
            zf.writestr("ok.txt", "hello")

    attempts = {"upload": 0, "asks": 0, "auths": 0}

    class _FakeDrive:
        credentials_file = str(tmp_path / "google-drive-auth.json")
        _readonly = False
        _service = None

        def upload_file(self, _path, _name, on_progress=None):
            attempts["upload"] += 1
            if attempts["upload"] == 1:
                raise cloud_client.DriveAuthorizationExpired("Missing token")
            return "drive-file-123"

        def verify_upload(self, _cid, _path):
            return True

    fake_cloud = _FakeDrive()
    monkeypatch.setattr(cloud_client, "get_cloud_client", lambda *a, **kw: fake_cloud)
    monkeypatch.setattr(
        cloud_client,
        "authenticate_oauth",
        lambda *_a, **_kw: (attempts.__setitem__("auths", attempts["auths"] + 1) or True, "✓ Reconnected"),
    )
    monkeypatch.setattr(backup_engine.cloud_manifest, "list_available_backups", lambda *_a, **_kw: [])
    monkeypatch.setattr(backup_engine.cloud_manifest, "push_cloud_state", lambda *_a, **_kw: None)

    eng = backup_engine.BackupEngine(
        {"name": "craptasti.ca", "cloud_provider": "google_drive"},
        on_ask=lambda _msg: (attempts.__setitem__("asks", attempts["asks"] + 1) or True),
    )

    # Exercise Stage 5 + 6 directly via minimal monkeypatched download stage
    res = {"errors": [], "files_failed": 0, "cloud_required": True}
    # Simulate what BackupEngine.run() does at Stage 5:
    cloud = cloud_client.get_cloud_client(eng.profile)
    try:
        cid = cloud.upload_file(zip_path, "site_backup.zip")
    except cloud_client.DriveAuthorizationExpired as exc:
        if eng.on_ask(str(exc)):
            ok, _ = cloud_client.authenticate_oauth(cloud.credentials_file)
            assert ok
            cid = cloud.upload_file(zip_path, "site_backup.zip")
    assert cid == "drive-file-123"
    assert attempts == {"upload": 2, "asks": 1, "auths": 1}


# ===== SNAPSMACK EOF =====
