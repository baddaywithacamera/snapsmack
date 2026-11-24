"""Shared desktop authentication session gate.

The gate stores evidence only (username, device fingerprint, and successful
verification timestamps), never a password, TOTP code, or TOTP seed. Offline
work remains available, but live-site CLI writes require both the SNAP HQ CLI
switch and step-up verification (password + TOTP via snap_stepup):
  - Either on every use or once per device every 30 days (owner chooses in HQ).
  - A new or different device always requires TOTP.
  - Posting new content, rescheduling, metadata updates, and changing sort order
    can ride the 30-day device trust.
  - DELETING always requires a fresh TOTP code, every time.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

import hashlib
import json
import os
import platform
import time
import uuid
from dataclasses import asdict, dataclass
from typing import Callable, Optional

import snap_home
import snap_hq_gate
import snap_stepup


LOGIN_SECONDS = 24 * 60 * 60
DEFAULT_TOTP_DAYS = 30
SCHEMA = 1

TRUST_ELIGIBLE_ACTIONS = frozenset({
    "read",
    "inspect",
    "local_write",
    "post",
    "sort",
    "metadata-update",
    "enrichment-sync",
    "reschedule",
    "repair",
})

ALL_SCOPES = TRUST_ELIGIBLE_ACTIONS | frozenset({"delete"})


@dataclass
class SessionState:
    username: str = ""
    login_at: int = 0
    totp_at: int = 0
    totp_window_days: int = DEFAULT_TOTP_DAYS
    last_online_ok: int = 0
    totp_required_on_reconnect: bool = False
    device_id: str = ""


@dataclass(frozen=True)
class GateDecision:
    allow_local: bool
    allow_online: bool
    require_login: bool
    require_totp: bool
    reason: str = ""


def current_device_id() -> str:
    """Stable local device identifier for binding 30-day step-up trust to one device."""
    override = (os.environ.get("SNAPSMACK_DEVICE_ID") or "").strip()
    if override:
        return override
    raw = f"{platform.node()}|{platform.system()}|{platform.machine()}|{uuid.getnode()}"
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()[:32]


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
            device_id=str(raw.get("device_id", "") or ""),
        )
    except Exception:
        return SessionState()


def save(state: SessionState) -> None:
    # Deliberately whitelist the evidence fields: credentials passed to authorize()
    # cannot accidentally land in this file through a caller-supplied dictionary.
    _atomic_write(_state_path(), {"schema": SCHEMA, **asdict(state)})


def evaluate(*, online: bool, now: Optional[int] = None,
             state: Optional[SessionState] = None,
             device_id: Optional[str] = None) -> GateDecision:
    now = int(time.time() if now is None else now)
    state = state or load()
    active_device = str(device_id if device_id is not None else current_device_id())
    device_mismatch = bool(state.totp_at and (not state.device_id or state.device_id != active_device))
    login_due = not state.login_at or now - state.login_at >= LOGIN_SECONDS or device_mismatch
    totp_seconds = max(1, state.totp_window_days) * 24 * 60 * 60
    totp_due = (not state.totp_at or now - state.totp_at >= totp_seconds or
                state.totp_required_on_reconnect or device_mismatch)

    if not online:
        if totp_due and not state.totp_required_on_reconnect:
            state.totp_required_on_reconnect = True
            save(state)
        return GateDecision(True, False, login_due, False,
                            "Offline work is available; authentication is required before reconnecting.")
    if login_due or totp_due:
        reason = ("New or different device detected; password and 2FA step-up is required."
                  if device_mismatch else "Sign in is required before this online exchange.")
        return GateDecision(True, False, login_due, totp_due, reason)
    return GateDecision(True, True, False, False)


def record_online_success(*, now: Optional[int] = None) -> SessionState:
    state = load()
    state.last_online_ok = int(time.time() if now is None else now)
    save(state)
    return state


def authorize(base_url: str, route: str, api_key: str, username: str,
              password: str, totp_code: str, *, now: Optional[int] = None,
              device_id: Optional[str] = None,
              requester: Callable = snap_stepup.request_authorization):
    """Authenticate via snap_stepup and persist only non-secret proof of success."""
    result = requester(base_url, route, api_key, username, password, totp_code)
    if not result.ok:
        return result
    stamp = int(time.time() if now is None else now)
    active_device = str(device_id if device_id is not None else current_device_id())
    state = load()
    state.username = result.username or username
    state.login_at = stamp
    state.totp_at = stamp
    state.last_online_ok = stamp
    state.totp_window_days = max(1, int(getattr(result, "totp_window_days", 30) or 30))
    state.totp_required_on_reconnect = False
    state.device_id = active_device
    save(state)
    return result


def _authorize_second_factor(
    second_factor_verifier: Callable[[], object],
    *,
    now: int,
    device_id: str,
) -> SessionState:
    """Verify an alternative second factor (e.g., FIDO2/WebAuthn hardware key in SS-1564).

    Pluggable so TOTP OR a hardware key can satisfy step-up, never both forced.
    """
    res = second_factor_verifier()
    if isinstance(res, tuple):
        ok, msg = bool(res[0]), str(res[1])
    else:
        ok, msg = bool(res), ("Second factor verified." if res else "Second factor verification failed.")
    if not ok:
        raise PermissionError(msg or "Second factor verification failed.")
    state = load()
    state.login_at = now
    state.totp_at = now
    state.last_online_ok = now
    state.totp_required_on_reconnect = False
    state.device_id = device_id
    save(state)
    return state


def require_stepup_for_write(
    base_url: str,
    route: str,
    api_key: str,
    *,
    action: str = "post",
    username: str = "",
    password: str = "",
    totp_code: str = "",
    now: Optional[int] = None,
    device_id: Optional[str] = None,
    stepup_policy: Optional[str] = None,
    requester: Callable = snap_stepup.request_authorization,
    second_factor_verifier: Optional[Callable[[], object]] = None,
) -> SessionState:
    """Enforce SNAP HQ CLI switch + pluggable step-up policy (TOTP or hardware key)."""
    hq_settings = snap_hq_gate.require_cli()
    policy = str(stepup_policy or hq_settings.get("stepup_policy") or snap_hq_gate.POLICY_30_DAYS)
    active_device = str(device_id if device_id is not None else current_device_id())
    stamp = int(time.time() if now is None else now)
    act = (action or "").strip().lower()
    is_delete = act == "delete" or "delete" in act

    u = (username or "").strip()
    p = password or ""
    t = (totp_code or "").strip()
    has_fresh_creds = bool(u and p and t)
    has_partial_creds = bool(u or p or t) and not has_fresh_creds
    if has_partial_creds and second_factor_verifier is None:
        raise PermissionError("Step-up authentication requires username, password, and 2FA code together.")

    if is_delete:
        if second_factor_verifier is not None:
            return _authorize_second_factor(second_factor_verifier, now=stamp, device_id=active_device)
        if not has_fresh_creds:
            raise PermissionError(
                "Deleting always requires fresh step-up verification (TOTP or hardware key) every time."
            )
        res = authorize(
            base_url, route, api_key, u, p, t,
            now=stamp, device_id=active_device, requester=requester,
        )
        if not res.ok:
            raise PermissionError(res.message or "Step-up authorization failed.")
        return load()

    if second_factor_verifier is not None:
        return _authorize_second_factor(second_factor_verifier, now=stamp, device_id=active_device)

    if has_fresh_creds:
        res = authorize(
            base_url, route, api_key, u, p, t,
            now=stamp, device_id=active_device, requester=requester,
        )
        if not res.ok:
            raise PermissionError(res.message or "Step-up authorization failed.")
        return load()

    if policy == snap_hq_gate.POLICY_EVERY_USE:
        raise PermissionError(
            "SNAP HQ is set to require password and 2FA step-up on every live-site write."
        )

    if act not in TRUST_ELIGIBLE_ACTIONS:
        raise PermissionError(
            f"Operation {action!r} requires a fresh password and 2FA TOTP code."
        )

    state = load()
    if not state.totp_at:
        raise PermissionError(
            "Step-up authentication (password + 2FA) is required before writing to a live site."
        )
    if not state.device_id or state.device_id != active_device:
        raise PermissionError(
            "New or different device detected; password and 2FA TOTP step-up is required."
        )
    totp_seconds = max(1, state.totp_window_days) * 24 * 60 * 60
    if state.totp_required_on_reconnect or stamp < state.totp_at or (stamp - state.totp_at) >= totp_seconds:
        raise PermissionError(
            "30-day device trust has expired; password and 2FA TOTP step-up is required."
        )
    return record_online_success(now=stamp)

# ===== SNAPSMACK EOF =====
