"""Small file-backed requests between SnapSmack desktop applications.

The request file lives in the shared config tree, so a second invocation can
hand work to an application that is already protected by the suite's
single-instance gate. Requests are atomic, bounded, short-lived, and consumed
once.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

import json
import os
import time
import uuid

import snap_home

_MAX_BYTES = 16 * 1024
_MAX_AGE_SECONDS = 300


def request_path(tool: str) -> str:
    safe = "".join(c for c in str(tool or "").lower() if c.isalnum() or c in "-_")
    if not safe:
        raise ValueError("desktop handoff requires a tool name")
    return os.path.join(snap_home.config_dir(safe), "handoff.json")


def write_request(tool: str, payload: dict) -> str:
    path = request_path(tool)
    body = dict(payload or {})
    body["request_id"] = uuid.uuid4().hex
    body["requested_at"] = int(time.time())
    raw = json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    if len(raw) > _MAX_BYTES:
        raise ValueError("desktop handoff is too large")
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".tmp-" + uuid.uuid4().hex
    with open(tmp, "wb") as handle:
        handle.write(raw)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(tmp, path)
    return path


def consume_request(tool: str):
    path = request_path(tool)
    try:
        if os.path.getsize(path) > _MAX_BYTES:
            os.remove(path)
            return None
        with open(path, "r", encoding="utf-8") as handle:
            body = json.load(handle)
        os.remove(path)
    except FileNotFoundError:
        return None
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        try:
            os.remove(path)
        except OSError:
            pass
        return None
    if not isinstance(body, dict):
        return None
    when = int(body.get("requested_at") or 0)
    if when <= 0 or abs(int(time.time()) - when) > _MAX_AGE_SECONDS:
        return None
    return body

# ===== SNAPSMACK EOF =====
