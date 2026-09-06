"""Shared desktop authentication session gate.

The gate stores evidence only (username and successful-verification timestamps),
never a password, TOTP code, or TOTP seed.  Offline work remains available, but
an expired TOTP window creates a hard reconnect latch: callers must run
``authorize`` before making any online request.
"""

import json
import os
import time
from dataclasses import asdict, dataclass
from typing import Callable, Optional

import snap_home
import snap_stepup


LOGIN_SECONDS = 24 * 60 * 60
DEFAULT_TOTP_DAYS = 30
SCHEMA = 1


@dataclass
class SessionState:
    username: str = ""
    login_at: int = 0
    totp_at: int = 0
    totp_window_days: int = DEFAULT_TOTP_DAYS
    last_online_ok: int = 0
    totp_required_on_reconnect: bool = False


@dataclass(frozen=True)
class GateDecision:
    allow_local: bool
    allow_online: bool
    require_login: bool
    require_totp: bool
    reason: str = ""


def _state_path() -> str:
    return snap_home.config_path("snap-hq", "desktop_session.json")


def _atomic_write(path: str, data: dict) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        json.dump(data, handle, indent=2, ensure_ascii=False)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(tmp, path)
    try:
        os.chmod(path, 0o600)
    except Exception:
        pass


def load() -> SessionState:
    try:
        with open(_state_path(), encoding="utf-8") as handle:
            raw = json.load(handle)
        if not isinstance(raw, dict):
            return SessionState()
        return SessionState(
            username=str(raw.get("username", "") or ""),
            login_at=max(0, int(raw.get("login_at", 0) or 0)),
            totp_at=max(0, int(raw.get("totp_at", 0) or 0)),
            totp_window_days=max(1, int(raw.get("totp_window_days", DEFAULT_TOTP_DAYS)
                                        or DEFAULT_TOTP_DAYS)),
            last_online_ok=max(0, int(raw.get("last_online_ok", 0) or 0)),
            totp_required_on_reconnect=bool(raw.get("totp_required_on_reconnect", False)),
        )
    except Exception:
        return SessionState()


def save(state: SessionState) -> None:
    # Deliberately whitelist the evidence fields: credentials passed to authorize()
    # cannot accidentally land in this file through a caller-supplied dictionary.
    _atomic_write(_state_path(), {"schema": SCHEMA, **asdict(state)})


def evaluate(*, online: bool, now: Optional[int] = None,
             state: Optional[SessionState] = None) -> GateDecision:
    now = int(time.time() if now is None else now)
    state = state or load()
    login_due = not state.login_at or now - state.login_at >= LOGIN_SECONDS
    totp_seconds = max(1, state.totp_window_days) * 24 * 60 * 60
    totp_due = (not state.totp_at or now - state.totp_at >= totp_seconds or
                state.totp_required_on_reconnect)

    if not online:
        if totp_due and not state.totp_required_on_reconnect:
            state.totp_required_on_reconnect = True
            save(state)
        return GateDecision(True, False, login_due, False,
                            "Offline work is available; authentication is required before reconnecting.")
    if login_due or totp_due:
        return GateDecision(True, False, login_due, totp_due,
                            "Sign in is required before this online exchange.")
    return GateDecision(True, True, False, False)


def record_online_success(*, now: Optional[int] = None) -> SessionState:
    state = load()
    state.last_online_ok = int(time.time() if now is None else now)
    save(state)
    return state


def authorize(base_url: str, route: str, api_key: str, username: str,
              password: str, totp_code: str, *, now: Optional[int] = None,
              requester: Callable = snap_stepup.request_authorization):
    """Authenticate and persist only non-secret proof of success."""
    result = requester(base_url, route, api_key, username, password, totp_code)
    if not result.ok:
        return result
    stamp = int(time.time() if now is None else now)
    state = load()
    state.username = result.username or username
    state.login_at = stamp
    state.totp_at = stamp
    state.last_online_ok = stamp
    state.totp_window_days = max(1, int(getattr(result, "totp_window_days", 30) or 30))
    state.totp_required_on_reconnect = False
    save(state)
    return result

# ===== SNAPSMACK EOF =====
