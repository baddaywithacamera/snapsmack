# SNAPSMACK_EOF_HEADER
"""Persistent, bounded collaboration room for local actors."""

from __future__ import annotations

import json
import time
import threading
import uuid
from pathlib import Path


class RoomError(ValueError):
    """A collaboration-room request was invalid."""


class Room:
    ACTORS = {"sean", "codex", "claude", "assistant"}
    RECIPIENTS = ACTORS | {"all"}

    def __init__(self, path: Path):
        self.path = path.resolve()
        self._lock = threading.Lock()

    def add(self, actor: str, text: str, recipient: str = "all",
            kind: str = "message") -> dict[str, object]:
        if actor not in self.ACTORS:
            raise RoomError("unrecognized actor")
        if recipient not in self.RECIPIENTS:
            raise RoomError("unrecognized recipient")
        if kind not in {"message", "instruction", "status"}:
            raise RoomError("unrecognized message kind")
        clean = text.strip()
        if not clean or len(clean) > 4000 or "\x00" in clean:
            raise RoomError("message must contain 1 to 4000 safe characters")
        record: dict[str, object] = {
            "id": str(uuid.uuid4()), "timestamp": int(time.time()),
            "actor": actor, "recipient": recipient, "kind": kind, "text": clean,
        }
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self._lock:
            with self.path.open("a", encoding="utf-8", newline="\n") as handle:
                handle.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")
        return record

    def recent(self, limit: int = 100) -> list[dict[str, object]]:
        bounded = max(1, min(int(limit), 200))
        if not self.path.exists():
            return []
        records: list[dict[str, object]] = []
        with self._lock:
            lines = self.path.read_text(encoding="utf-8", errors="replace").splitlines()
        for line in lines:
            try:
                record = json.loads(line)
            except json.JSONDecodeError:
                continue
            if isinstance(record, dict):
                records.append(record)
        return records[-bounded:]

# ===== SNAPSMACK EOF =====
