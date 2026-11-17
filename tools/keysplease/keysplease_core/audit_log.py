"""Append-only local JSONL action log for KEYS PLEASE (never logs secrets)."""

import json
import os
from datetime import datetime, timezone
from typing import Optional


def default_log_path() -> str:
    base = os.environ.get("LOCALAPPDATA") or os.path.expanduser("~")
    return os.path.join(base, "SnapSmack", "keysplease-actions.jsonl")


def append_action_record(
    *,
    action: str,
    site_url: str,
    key_id: int,
    key_type: str,
    key_prefix: str,
    outcome: str,
    log_path: Optional[str] = None,
) -> str:
    path = log_path or default_log_path()
    os.makedirs(os.path.dirname(path), exist_ok=True)
    entry = {
        "ts": datetime.now(timezone.utc).isoformat(),
        "action": str(action),
        "site_url": str(site_url),
        "key_id": int(key_id),
        "key_type": str(key_type),
        "key_prefix": str(key_prefix),
        "outcome": str(outcome),
    }
    with open(path, "a", encoding="utf-8") as fh:
        fh.write(json.dumps(entry) + "\n")
    return path


# ===== SNAPSMACK EOF =====
