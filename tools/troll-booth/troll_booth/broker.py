"""Discovery, invocation, and audit for TROLL BOOTH."""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
import sys
import time
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from .policy import AppPolicy, PolicyError, builtin_policies, resolve_app_directory


class BrokerError(RuntimeError):
    """A discovery or invocation failed closed."""


@dataclass(frozen=True)
class DiscoveredApp:
    policy: AppPolicy
    directory: Path
    entrypoint: Path
    executable_hash: str
    sheet: dict[str, Any]

    @property
    def declared_commands(self) -> set[str]:
        commands = self.sheet.get("commands", [])
        if not isinstance(commands, list):
            return set()
        return {
            str(command.get("name")) for command in commands
            if isinstance(command, dict) and isinstance(command.get("name"), str)
        }


class Broker:
    def __init__(self, tool_root: Path, audit_path: Path | None = None,
                 timeout: float = 15.0, max_output: int = 2_000_000):
        self.tool_root = tool_root.resolve()
        self.policies = builtin_policies()
        self.audit_path = (audit_path or self.tool_root / "state" / "audit.jsonl").resolve()
        self.timeout = timeout
        self.max_output = max_output

    def _base_command(self, app: DiscoveredApp, actor: str | None = None,
                      discovery: bool = False) -> list[str]:
        command = [sys.executable, str(app.entrypoint)]
        if app.policy.actor_flag and not discovery:
            if actor not in {"claude", "codex", "sean", "assistant"}:
                raise PolicyError("ticket-wicket requires a recognized actor")
            command.extend(["--as", "chatgpt" if actor == "codex" else actor])
        return command

    def _run(self, command: list[str], cwd: Path) -> subprocess.CompletedProcess[str]:
        try:
            result = subprocess.run(
                command, cwd=str(cwd), shell=False, capture_output=True,
                text=True, encoding="utf-8", errors="replace", timeout=self.timeout,
                env=os.environ.copy(), check=False,
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            raise BrokerError(str(exc)) from exc
        if len(result.stdout.encode("utf-8")) > self.max_output:
            raise BrokerError("application output exceeded the broker limit")
        return result

    def discover(self, app_id: str) -> DiscoveredApp:
        policy = self.policies.get(app_id)
        if policy is None:
            raise PolicyError(f"unknown app: {app_id}")
        directory = resolve_app_directory(self.tool_root, policy)
        entrypoint = (directory / policy.entrypoint).resolve()
        if entrypoint.parent != directory or not entrypoint.is_file():
            raise BrokerError(f"missing pinned entrypoint for {app_id}")
        digest = hashlib.sha256(entrypoint.read_bytes()).hexdigest()
        provisional = DiscoveredApp(policy, directory, entrypoint, digest, {})
        result = self._run(
            self._base_command(provisional, discovery=True) + ["agent", "--json"],
            directory,
        )
        if result.returncode != 0:
            raise BrokerError(f"{app_id} discovery refused with exit {result.returncode}")
        try:
            sheet = json.loads(result.stdout)
        except json.JSONDecodeError as exc:
            raise BrokerError(f"{app_id} returned an invalid capability sheet") from exc
        if not isinstance(sheet, dict) or sheet.get("tool") != policy.display_name:
            raise BrokerError(f"{app_id} capability identity does not match policy")
        app = DiscoveredApp(policy, directory, entrypoint, digest, sheet)
        missing = sorted(set(policy.capabilities) - app.declared_commands)
        if missing:
            raise BrokerError(f"{app_id} no longer declares: {', '.join(missing)}")
        return app

    def list_apps(self) -> list[dict[str, Any]]:
        rows = []
        for app_id in sorted(self.policies):
            try:
                app = self.discover(app_id)
                rows.append({
                    "id": app_id, "name": app.policy.display_name, "healthy": True,
                    "executable_sha256": app.executable_hash,
                    "capabilities": sorted(app.policy.capabilities),
                })
            except (BrokerError, PolicyError) as exc:
                rows.append({"id": app_id, "name": self.policies[app_id].display_name,
                             "healthy": False, "error": str(exc), "capabilities": []})
        return rows

    def invoke(self, app_id: str, capability_name: str, arguments: dict[str, Any],
               actor: str, reason: str = "", ticket_id: str = "") -> dict[str, Any]:
        operation_id = str(uuid.uuid4())
        started = time.time()
        result_record: dict[str, Any]
        try:
            app = self.discover(app_id)
            capability = app.policy.capabilities.get(capability_name)
            if capability is None:
                raise PolicyError(f"capability is not granted: {capability_name}")
            app_args = capability.argv(arguments)
            command = self._base_command(app, actor) + app_args
            result = self._run(command, app.directory)
            try:
                output = json.loads(result.stdout) if result.stdout.strip() else None
            except json.JSONDecodeError as exc:
                raise BrokerError("application returned invalid JSON") from exc
            result_record = {
                "operation_id": operation_id, "ok": result.returncode == 0,
                "exit_code": result.returncode, "result": output,
                "diagnostic": result.stderr[-4000:] if result.stderr else "",
            }
        except (BrokerError, PolicyError) as exc:
            result_record = {"operation_id": operation_id, "ok": False,
                             "exit_code": 1, "error": str(exc)}
        self._audit({
            "operation_id": operation_id, "timestamp": int(started), "actor": actor,
            "app": app_id, "capability": capability_name, "arguments": arguments,
            "reason": reason, "ticket_id": ticket_id, "ok": result_record["ok"],
            "elapsed_ms": round((time.time() - started) * 1000),
        })
        return result_record

    def _audit(self, record: dict[str, Any]) -> None:
        self.audit_path.parent.mkdir(parents=True, exist_ok=True)
        with self.audit_path.open("a", encoding="utf-8", newline="\n") as handle:
            handle.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")

# ===== SNAPSMACK EOF =====
