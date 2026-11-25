"""Official desktop-companion dependency and SNAP HQ CLI switch gate.

Packaged SnapSmack companions require SNAP HQ to be installed. Source/dev runs
remain available to contributors and automated tests.

Desktop CLI automation is available to all users, controlled by an explicit
ON/OFF switch in SNAP HQ Settings (default OFF) and the owner's step-up policy
(once per device every 30 days, or on every live-site write).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

from __future__ import annotations

import json
import os
import shutil
import sys
from typing import Any

import snap_home

POLICY_30_DAYS = "30_days"
POLICY_EVERY_USE = "every_use"
VALID_POLICIES = (POLICY_30_DAYS, POLICY_EVERY_USE)
SCHEMA = 1


def _cli_config_path() -> str:
    if getattr(sys, "frozen", False):
        return os.path.join(snap_home._DEFAULT_ROOT, "config_files", "snap-hq", "desktop_cli.json")
    return snap_home.config_path("snap-hq", "desktop_cli.json")


def _atomic_write(path: str, data: dict[str, Any]) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        json.dump(data, handle, indent=2, ensure_ascii=False)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(tmp, path)


def cli_settings() -> dict[str, Any]:
    """Return current SNAP HQ CLI settings. Default is OFF with 30-day trust policy."""
    try:
        with open(_cli_config_path(), encoding="utf-8") as handle:
            raw = json.load(handle)
        if not isinstance(raw, dict):
            raw = {}
    except Exception:
        raw = {}
    policy = str(raw.get("stepup_policy", POLICY_30_DAYS) or POLICY_30_DAYS).strip()
    if policy not in VALID_POLICIES:
        policy = POLICY_30_DAYS
    return {
        "cli_enabled": bool(raw.get("cli_enabled", False)),
        "stepup_policy": policy,
    }


def set_cli_settings(*, cli_enabled: bool, stepup_policy: str = POLICY_30_DAYS) -> dict[str, Any]:
    """Persist the owner's SNAP HQ CLI switch and step-up policy."""
    policy = str(stepup_policy or POLICY_30_DAYS).strip()
    if policy not in VALID_POLICIES:
        raise ValueError(f"Unsupported step-up policy: {stepup_policy!r}")
    state = {
        "schema": SCHEMA,
        "cli_enabled": bool(cli_enabled),
        "stepup_policy": policy,
    }
    _atomic_write(_cli_config_path(), state)
    return {"cli_enabled": state["cli_enabled"], "stepup_policy": state["stepup_policy"]}


def cli_enabled() -> bool:
    return bool(cli_settings()["cli_enabled"])


def stepup_policy() -> str:
    return str(cli_settings()["stepup_policy"])


def installed() -> bool:
    override = os.environ.get("SNAPSMACK_HQ_EXECUTABLE", "").strip()
    if override:
        return os.path.isfile(override)
    if os.name == "nt":
        return any(os.path.isfile(path) for path in (
            r"C:\snapsmack\hub\SNAP HQ.exe", r"C:\snapsmack\hub\hub.exe"))
    return bool(shutil.which("snap-hq") or os.path.isfile("/opt/snapsmack/hub/snap-hq"))


def require(*, packaged_only: bool = True) -> None:
    if os.environ.get("SNAPSMACK_DEV_BYPASS_HQ") == "1":
        return
    if packaged_only and not getattr(sys, "frozen", False):
        return
    if not installed():
        raise SystemExit("SNAP HQ is required. Install SNAP HQ, then launch this app from it.")


def require_cli(*, packaged_only: bool = True) -> dict[str, Any]:
    """Enforce both the packaged SNAP HQ install gate and the SNAP HQ CLI switch."""
    require(packaged_only=packaged_only)
    settings = cli_settings()
    if not settings["cli_enabled"]:
        raise PermissionError(
            "Desktop CLI is turned off in SNAP HQ. Open SNAP HQ Settings to turn it on."
        )
    return settings

# ===== SNAPSMACK EOF =====
