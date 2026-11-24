"""Append-only local deletion audit log for DRIVE BY (stored outside app config, no secrets)."""

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


def preflight_audit_log(log_path: Optional[str] = None) -> str:
    """Verify the audit log directory and file are writable BEFORE any provider action."""
    target = log_path or default_log_path()
    parent = os.path.dirname(target)
    if parent:
        os.makedirs(parent, exist_ok=True)
    with open(target, "a", encoding="utf-8") as fh:
        fh.flush()
    return target


def append_audit_event(
    record: BackupRecord,
    phase: str,
    action: str,
    recovery_days: int,
    status: str = "pending",
    detail: str = "",
    batch_id: str = "",
    log_path: Optional[str] = None,
) -> str:
    """Append one durable intent/result entry as a single JSONL line; never logs secrets."""
    target = preflight_audit_log(log_path)
    entry = {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "batch_id": batch_id,
        "phase": phase,       # "intent" | "result"
        "status": status,     # "pending" | "ok" | "error"
        "provider": record.provider,
        "blog": record.blog,
        "filename": record.filename,
        "backup_type": record.backup_type,
        "date_iso": record.date_iso,
        "size_bytes": int(record.size_bytes or 0),
        "remote_id": record.remote_id,
        "action": action,
        "recovery_window_days": int(recovery_days),
        "detail": str(detail or ""),
    }
    with open(target, "a", encoding="utf-8") as fh:
        fh.write(json.dumps(entry, ensure_ascii=False) + "\n")
        fh.flush()
        try:
            os.fsync(fh.fileno())
        except OSError:
            pass
    return target


def append_deletion_record(record: BackupRecord, action: str, recovery_days: int,
                           log_path: Optional[str] = None) -> str:
    """Append a completed deletion entry as a single JSONL line; never logs secrets."""
    return append_audit_event(
        record=record,
        phase="result",
        action=action,
        recovery_days=recovery_days,
        status="ok",
        log_path=log_path,
    )


# ===== SNAPSMACK EOF =====
