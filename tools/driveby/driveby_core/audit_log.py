"""Append-only local deletion log for DRIVE BY (stored outside app config, no secrets)."""

from datetime import datetime, timezone
import json
import os
from typing import Optional

from .models import BackupRecord


def default_log_path() -> str:
    try:
        import snap_home
        base = os.path.join(snap_home.shared_library(), "driveby")
    except Exception:  # noqa: BLE001
        base = os.path.join(os.path.expanduser("~"), ".snapsmack", "driveby")
    return os.path.join(base, "deletions.jsonl")


def append_deletion_record(record: BackupRecord, action: str, recovery_days: int,
                           log_path: Optional[str] = None) -> str:
    """Append one deletion entry as a single JSONL line; never logs secrets."""
    target = log_path or default_log_path()
    os.makedirs(os.path.dirname(target), exist_ok=True)
    entry = {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "provider": record.provider,
        "blog": record.blog,
        "filename": record.filename,
        "backup_type": record.backup_type,
        "date_iso": record.date_iso,
        "size_bytes": int(record.size_bytes or 0),
        "remote_id": record.remote_id,
        "action": action,
        "recovery_window_days": int(recovery_days),
    }
    with open(target, "a", encoding="utf-8") as fh:
        fh.write(json.dumps(entry, ensure_ascii=False) + "\n")
    return target


# ===== SNAPSMACK EOF =====
