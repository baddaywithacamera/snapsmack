"""Backup provider adapters and offline fake fixtures for DRIVE BY.

Safety invariants:
- Google Drive uses drive.file scope ONLY and files().update(trashed=True), never delete().
- Backblaze B2 uses restricted bucket app keys ONLY and b2_hide_file, never delete_file_version().
- FakeDriveProvider and FakeB2Provider run offline with zero network calls.
"""

from typing import List

from .models import BackupRecord, parse_suyb_filename


PROVIDER_LABELS = {
    "google_drive": "Google Drive",
    "b2": "Backblaze B2",
}

RECOVERY_WINDOWS_DAYS = {
    "google_drive": 30,  # Google Drive Trash retention window
    "b2": 30,            # B2 hidden file lifecycle default window (bucket rule dependent)
}


class FakeDriveProvider:
    """In-memory Google Drive backup fixture (30-day Trash recovery)."""

    provider_id = "google_drive"
    recovery_days = 30

    def __init__(self, records: List[BackupRecord] = None):
        self._records = list(records) if records is not None else _default_drive_fixture()
        self.trashed_ids: List[str] = []

    def list_backups(self) -> List[BackupRecord]:
        return [r for r in self._records if not r.trashed]

    def trash_backup(self, record: BackupRecord) -> dict:
        record.trashed = True
        self.trashed_ids.append(record.remote_id)
        return {"action": "drive_trash", "remote_id": record.remote_id, "recovery_days": self.recovery_days}


class FakeB2Provider:
    """In-memory Backblaze B2 backup fixture (hide_file, never delete_file_version)."""

    provider_id = "b2"
    recovery_days = 30

    def __init__(self, records: List[BackupRecord] = None):
        self._records = list(records) if records is not None else _default_b2_fixture()
        self.hidden_ids: List[str] = []

    def list_backups(self) -> List[BackupRecord]:
        return [r for r in self._records if not r.trashed]

    def trash_backup(self, record: BackupRecord) -> dict:
        record.trashed = True
        self.hidden_ids.append(record.remote_id)
        return {"action": "b2_hide_file", "remote_id": record.remote_id, "recovery_days": self.recovery_days}


def is_likely_b2_master_key(key_id: str) -> bool:
    """B2 master account keys start with '000'/'001'/'002'/'003'/'004'/'005' and are 12-char account IDs
    without an app-key prefix 'K'. Warn/block if the key ID looks like an account master key ID."""
    kid = str(key_id or "").strip()
    return bool(kid) and not kid.startswith("K") and len(kid) <= 16


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
