"""Backup provider adapters and offline test fixtures for DRIVE BY.

Safety invariants:
- Google Drive uses files().update(body={"trashed": True}), NEVER files().delete().
- Google Drive reuses SYBU/SUYB's shared OAuth grant from snap_creds.
- Backblaze B2 uses restricted bucket app keys ONLY and b2_hide_file, never delete_file_version().
- FakeDriveProvider and FakeB2Provider run offline for isolated tests only.
"""

import json
import os
from typing import Callable, List, Optional

from .auth_state import (
    DRIVE_SCOPE,
    get_drive_auth_state,
    is_likely_b2_master_key,
    save_sybu_drive_authorization,
)
from .models import BackupRecord, parse_suyb_filename


PROVIDER_LABELS = {
    "google_drive": "Google Drive",
    "b2": "Backblaze B2",
}

RECOVERY_WINDOWS_DAYS = {
    "google_drive": 30,  # Google Drive Trash retention window
    "b2": 30,            # B2 hidden file lifecycle default window (bucket rule dependent)
}

DRIVE_SCOPES = [DRIVE_SCOPE, "https://www.googleapis.com/auth/drive.file"]

_BACKUP_EXTENSIONS = (".zip", ".tar.gz", ".tgz", ".sql.gz", ".sql", ".gz", ".7z", ".bak")


def _is_backup_filename(name: str) -> bool:
    low = str(name or "").strip().lower()
    if any(low.endswith(ext) for ext in _BACKUP_EXTENSIONS):
        return True
    parsed = parse_suyb_filename(name)
    return parsed["backup_type"] != "unknown" and bool(parsed["date_iso"])


class GoogleDriveProvider:
    """Real Google Drive backup adapter reusing SYBU/SUYB's protected vault grant."""

    provider_id = "google_drive"
    recovery_days = 30

    def __init__(
        self,
        cred_store=None,
        vault_error: str = "",
        folder_id: str = "",
        service_factory: Optional[Callable] = None,
    ):
        self._cred_store = cred_store
        self._vault_error = vault_error
        self._folder_id_override = str(folder_id or "").strip()
        self._service_factory = service_factory

    def auth_state(self):
        return get_drive_auth_state(self._cred_store, self._vault_error)

    def is_authenticated(self) -> bool:
        return self.auth_state().deletes_allowed

    def _build_service(self):
        state = self.auth_state()
        if state.state == "vault_error":
            raise RuntimeError(state.message)
        if state.revoke_pending:
            raise RuntimeError(state.message)
        if not state.linked or not state.authorized_user_json:
            raise RuntimeError(state.message)

        if self._service_factory is not None:
            return self._service_factory(json.loads(state.authorized_user_json)), state

        try:
            from google.auth.transport.requests import Request
            from google.oauth2.credentials import Credentials
            from googleapiclient.discovery import build
        except ImportError as exc:
            raise RuntimeError(
                "google-api-python-client and google-auth are required for Google Drive."
            ) from exc

        info = json.loads(state.authorized_user_json)
        creds = Credentials.from_authorized_user_info(info)
        if not creds.valid:
            if creds.expired and creds.refresh_token:
                creds.refresh(Request())
                try:
                    save_sybu_drive_authorization(self._cred_store, creds.to_json())
                except Exception:  # noqa: BLE001
                    pass
            else:
                raise RuntimeError("Google Drive authorization expired; reconnect Google Drive.")

        return build("drive", "v3", credentials=creds), state

    def list_backups(self) -> List[BackupRecord]:
        state = self.auth_state()
        if not state.linked:
            return []

        service, resolved = self._build_service()
        folder_id = self._folder_id_override or resolved.folder_id
        q_parts = [
            "trashed = false",
            "mimeType != 'application/vnd.google-apps.folder'",
        ]
        if folder_id:
            safe_folder = folder_id.replace("'", "\\'")
            q_parts.append(f"'{safe_folder}' in parents")
        query = " and ".join(q_parts)

        records: List[BackupRecord] = []
        page_token: Optional[str] = None
        while True:
            kwargs = {
                "q": query,
                "spaces": "drive",
                "pageSize": 200,
                "fields": "nextPageToken, files(id, name, size, createdTime, modifiedTime, trashed, mimeType, parents)",
            }
            if page_token:
                kwargs["pageToken"] = page_token
            resp = service.files().list(**kwargs).execute()
            for f in resp.get("files", []) or []:
                if f.get("trashed"):
                    continue
                name = str(f.get("name") or "").strip()
                if not name or not _is_backup_filename(name):
                    continue
                parsed = parse_suyb_filename(name)
                date_iso = parsed["date_iso"]
                if not date_iso:
                    raw_time = str(f.get("createdTime") or f.get("modifiedTime") or "")
                    date_iso = raw_time[:10] if len(raw_time) >= 10 else ""
                try:
                    size_bytes = int(f.get("size") or 0)
                except (TypeError, ValueError):
                    size_bytes = 0
                records.append(
                    BackupRecord(
                        provider=self.provider_id,
                        blog=parsed["blog"],
                        filename=name,
                        backup_type=parsed["backup_type"],
                        date_iso=date_iso,
                        size_bytes=size_bytes,
                        verified=False,
                        replayed=False,
                        remote_id=str(f.get("id") or ""),
                        trashed=False,
                        raw_meta=dict(f),
                    )
                )
            page_token = resp.get("nextPageToken")
            if not page_token:
                break
        return records

    def trash_backup(self, record: BackupRecord) -> dict:
        """Move a Drive backup to Trash (30-day recovery). NEVER permanently deletes."""
        if not self.is_authenticated():
            raise PermissionError("Google Drive is not authenticated; refusing to trash backup.")
        if not record.remote_id:
            raise ValueError(f"Backup {record.filename!r} is missing a Google Drive file ID.")

        service, _ = self._build_service()
        service.files().update(fileId=record.remote_id, body={"trashed": True}).execute()
        record.trashed = True
        return {
            "action": "drive_trash",
            "remote_id": record.remote_id,
            "recovery_days": self.recovery_days,
        }

    def connect_oauth(self, credentials_path: Optional[str] = None) -> tuple[bool, str]:
        """Run browser OAuth consent using SNAP HQ's Google credentials JSON and save to shared vault."""
        state = self.auth_state()
        if state.state == "vault_error":
            return False, state.message
        creds_file = str(credentials_path or state.credentials_path or "").strip()
        if not creds_file or not os.path.isfile(creds_file):
            return (
                False,
                "Google credentials JSON not found. Set it in SNAP HQ Settings -> Google Drive Credentials.",
            )
        try:
            from google_auth_oauthlib.flow import InstalledAppFlow
        except ImportError:
            return False, "google-auth-oauthlib is required to connect Google Drive."

        try:
            flow = InstalledAppFlow.from_client_secrets_file(creds_file, DRIVE_SCOPES)
            creds = flow.run_local_server(port=0)
            save_sybu_drive_authorization(self._cred_store, creds.to_json())
            return True, "Google Drive connected and saved to shared SYBU/SUYB vault."
        except Exception as exc:  # noqa: BLE001
            return False, f"Google Drive OAuth failed: {exc}"


class FakeDriveProvider:
    """In-memory Google Drive backup fixture for isolated tests ONLY (30-day Trash recovery)."""

    provider_id = "google_drive"
    recovery_days = 30

    def __init__(self, records: List[BackupRecord] = None, authenticated: bool = True):
        self._records = list(records) if records is not None else _default_drive_fixture()
        self._authenticated = bool(authenticated)
        self.trashed_ids: List[str] = []

    def is_authenticated(self) -> bool:
        return self._authenticated

    def list_backups(self) -> List[BackupRecord]:
        return [r for r in self._records if not r.trashed]

    def trash_backup(self, record: BackupRecord) -> dict:
        if not self._authenticated:
            raise PermissionError("FakeDriveProvider is unauthenticated.")
        record.trashed = True
        self.trashed_ids.append(record.remote_id)
        return {"action": "drive_trash", "remote_id": record.remote_id, "recovery_days": self.recovery_days}


class FakeB2Provider:
    """In-memory Backblaze B2 backup fixture for isolated tests ONLY (hide_file, never delete_file_version)."""

    provider_id = "b2"
    recovery_days = 30

    def __init__(self, records: List[BackupRecord] = None, authenticated: bool = True):
        self._records = list(records) if records is not None else _default_b2_fixture()
        self._authenticated = bool(authenticated)
        self.hidden_ids: List[str] = []

    def is_authenticated(self) -> bool:
        return self._authenticated

    def list_backups(self) -> List[BackupRecord]:
        return [r for r in self._records if not r.trashed]

    def trash_backup(self, record: BackupRecord) -> dict:
        if not self._authenticated:
            raise PermissionError("FakeB2Provider is unauthenticated.")
        record.trashed = True
        self.hidden_ids.append(record.remote_id)
        return {"action": "b2_hide_file", "remote_id": record.remote_id, "recovery_days": self.recovery_days}


def _make(provider: str, filename: str, size_mb: float, verified: bool,
          replayed: bool, remote_id: str) -> BackupRecord:
    parsed = parse_suyb_filename(filename)
    return BackupRecord(
        provider=provider,
        blog=parsed["blog"],
        filename=filename,
        backup_type=parsed["backup_type"],
        date_iso=parsed["date_iso"],
        size_bytes=int(size_mb * 1024 * 1024),
        verified=verified,
        replayed=replayed,
        remote_id=remote_id,
    )


def _default_drive_fixture() -> List[BackupRecord]:
    return [
        _make("google_drive", "baddaywithacamera_full_2026-11-16.zip", 1840.0, True, True, "gd-bd-1116"),
        _make("google_drive", "baddaywithacamera_full_2026-11-09.zip", 1815.0, True, False, "gd-bd-1109"),
        _make("google_drive", "baddaywithacamera_full_2026-11-02.zip", 1790.0, True, False, "gd-bd-1102"),
        _make("google_drive", "baddaywithacamera_full_2026-10-18.zip", 1720.0, False, False, "gd-bd-1018"),
        _make("google_drive", "baddaywithacamera_full_2026-09-20.zip", 1650.0, True, False, "gd-bd-0920"),
        _make("google_drive", "foundtextures_full_2026-11-15.zip", 940.0, True, True, "gd-ft-1115"),
        _make("google_drive", "foundtextures_full_2026-11-01.zip", 925.0, True, False, "gd-ft-1101"),
        _make("google_drive", "foundtextures_full_2026-10-10.zip", 910.0, False, False, "gd-ft-1010"),
    ]


def _default_b2_fixture() -> List[BackupRecord]:
    return [
        _make("b2", "baddaywithacamera_db_2026-11-16.sql.gz", 42.5, True, True, "b2-bd-1116"),
        _make("b2", "baddaywithacamera_db_2026-11-09.sql.gz", 41.8, True, False, "b2-bd-1109"),
        _make("b2", "baddaywithacamera_db_2026-10-15.sql.gz", 39.2, False, False, "b2-bd-1015"),
        _make("b2", "photowalking_full_2026-11-14.zip", 1210.0, True, False, "b2-pw-1114"),
        _make("b2", "photowalking_full_2026-10-28.zip", 1190.0, True, False, "b2-pw-1028"),
        _make("b2", "photowalking_full_2026-09-25.zip", 1140.0, False, False, "b2-pw-0925"),
    ]


# ===== SNAPSMACK EOF =====
