# SNAPSMACK_EOF_HEADER
"""Persistent executable-hash approvals for TROLL BOOTH."""

from __future__ import annotations

import json
import os
import time
from pathlib import Path


class TrustError(ValueError):
    """A trust record was invalid or unavailable."""


class TrustStore:
    def __init__(self, path: Path):
        self.path = path.resolve()

    def _load(self) -> dict[str, dict[str, object]]:
        if not self.path.exists():
            return {}
        try:
            value = json.loads(self.path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise TrustError("trusted-app store is unavailable") from exc
        if not isinstance(value, dict) or value.get("version") != 1 \
                or not isinstance(value.get("apps"), dict):
            raise TrustError("trusted-app store has an unsupported format")
        return value["apps"]

    def _save(self, apps: dict[str, dict[str, object]]) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.path.with_suffix(".tmp")
        temporary.write_text(json.dumps({"version": 1, "apps": apps}, indent=2,
                                        sort_keys=True) + "\n", encoding="utf-8")
        os.replace(temporary, self.path)

    def approved_hash(self, app_id: str) -> str:
        record = self._load().get(app_id, {})
        digest = record.get("sha256") if isinstance(record, dict) else ""
        return digest if isinstance(digest, str) else ""

    def is_approved(self, app_id: str, digest: str) -> bool:
        return bool(digest) and self.approved_hash(app_id) == digest

    def approve(self, app_id: str, digest: str, actor: str = "sean") -> None:
        if not app_id or len(digest) != 64 or any(ch not in "0123456789abcdef" for ch in digest):
            raise TrustError("invalid application identity")
        apps = self._load()
        apps[app_id] = {"sha256": digest, "approved_by": actor,
                        "approved_at": int(time.time())}
        self._save(apps)

    def revoke(self, app_id: str) -> None:
        apps = self._load()
        apps.pop(app_id, None)
        self._save(apps)

# ===== SNAPSMACK EOF =====
