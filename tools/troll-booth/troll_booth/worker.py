from __future__ import annotations
# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.

import argparse
import atexit
import ctypes
import json
import os
import re
import subprocess
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

from . import __version__
from .history import ConversationStore, open_conversation_store


class WorkerInstanceLock:
    """One outbound worker per worker ID, with automatic stale-lock recovery."""

    def __init__(self, worker_id: str) -> None:
        safe_id = re.sub(r"[^A-Za-z0-9_.-]+", "-", worker_id).strip("-") or "local-worker"
        self.path = Path.home() / ".troll-booth" / f"worker-{safe_id}.lock"
        self.owned = False

    @staticmethod
    def _process_is_alive(pid: int) -> bool:
        if pid <= 0:
            return False
        if os.name == "nt":
            process_query_limited_information = 0x1000
            handle = ctypes.windll.kernel32.OpenProcess(process_query_limited_information, False, pid)
            if not handle:
                return False
            ctypes.windll.kernel32.CloseHandle(handle)
            return True
        try:
            os.kill(pid, 0)
        except ProcessLookupError:
            return False
        except (PermissionError, OSError):
            return True
        return True

    def acquire(self) -> bool:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        for _attempt in range(2):
            try:
                descriptor = os.open(self.path, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
            except FileExistsError:
                try:
                    existing_pid = int(self.path.read_text(encoding="ascii").strip())
                except (OSError, ValueError):
                    existing_pid = 0
                if self._process_is_alive(existing_pid):
                    return False
                try:
                    self.path.unlink()
                except FileNotFoundError:
                    pass
                continue
            with os.fdopen(descriptor, "w", encoding="ascii") as stream:
                stream.write(str(os.getpid()))
            self.owned = True
            atexit.register(self.release)
            return True
        return False

    def release(self) -> None:
        if not self.owned:
            return
        try:
            if self.path.read_text(encoding="ascii").strip() == str(os.getpid()):
                self.path.unlink()
        except FileNotFoundError:
            pass
        finally:
            self.owned = False


def _request(base: str, token: str, path: str, payload: dict[str, Any]) -> dict[str, Any]:
    request = urllib.request.Request(
        f"{base.rstrip('/')}{path}",
        data=json.dumps(payload).encode("utf-8"),
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        return json.loads(response.read().decode("utf-8"))


class CapabilityRegistry:
    def __init__(self, config_path: Path, history_path: Path | None = None):
        self.config_path = config_path
        self.apps = self._load()
        if history_path:
            self.history = ConversationStore(history_path)
        else:
            # The shared MariaDB configuration may come from the environment or
            # from the Windows DPAPI-protected desktop configuration.
            self.history, _ = open_conversation_store(allow_offline_cache=False)

    def _load(self) -> dict[str, dict[str, Any]]:
        if not self.config_path.exists():
            return {}
        data = json.loads(self.config_path.read_text(encoding="utf-8"))
        apps = {}
        for entry in data.get("apps", []):
            slug = str(entry.get("slug", ""))
            command = entry.get("capabilities_command", [])
            if slug and isinstance(command, list) and command and all(isinstance(item, str) for item in command):
                actions = {}
                for action_name, action in entry.get("actions", {}).items():
                    if not isinstance(action_name, str) or not isinstance(action, dict):
                        continue
                    argv = action.get("argv", [])
                    access = action.get("access", "read")
                    options = action.get("options", {})
                    max_positionals = action.get("max_positionals", 0)
                    if (
                        action_name
                        and isinstance(argv, list)
                        and argv
                        and all(isinstance(item, str) and item for item in argv)
                        and access in {"read", "write"}
                        and isinstance(options, dict)
                        and all(
                            isinstance(option, str)
                            and option.startswith("--")
                            and kind in {"flag", "value"}
                            for option, kind in options.items()
                        )
                        and isinstance(max_positionals, int)
                        and 0 <= max_positionals <= 8
                    ):
                        actions[action_name] = {
                            "argv": argv,
                            "access": access,
                            "options": options,
                            "max_positionals": max_positionals,
                        }
                apps[slug] = {
                    "slug": slug,
                    "name": str(entry.get("name", slug)),
                    "command": command,
                    "env": [
                        name for name in entry.get("env", [])
                        if isinstance(name, str)
                        and name.replace("_", "A").isalnum()
                        and name.upper() == name
                        and len(name) <= 128
                    ][:16],
                    "actions": actions,
                }
        return apps

    def summary(self) -> list[dict[str, str]]:
        return [{"slug": app["slug"], "name": app["name"]} for app in self.apps.values()]

    def capabilities(self, slug: str) -> dict[str, Any]:
        app = self.apps.get(slug)
        if not app:
            raise ValueError("unknown or disabled app")
        child_env = {
            key: os.environ[key]
            for key in ("PATH", "SystemRoot", "WINDIR", "TEMP", "TMP", "LANG")
            if key in os.environ
        }
        completed = subprocess.run(
            app["command"], capture_output=True, text=True, timeout=15, shell=False, check=False,
            env=child_env,
        )
        if completed.returncode != 0:
            raise RuntimeError(f"capability command failed with exit {completed.returncode}")
        if len(completed.stdout) > 262144:
            raise RuntimeError("capability response exceeded 256 KiB")
        result = json.loads(completed.stdout)
        if not isinstance(result, dict):
            raise RuntimeError("capability response must be a JSON object")
        return result

    @staticmethod
    def _child_env(app: dict[str, Any]) -> dict[str, str]:
        child = {
            key: os.environ[key]
            for key in ("PATH", "SystemRoot", "WINDIR", "TEMP", "TMP", "LANG")
            if key in os.environ
        }
        for key in app.get("env", []):
            if key in os.environ:
                child[key] = os.environ[key]
        return child

    def run_action(self, slug: str, action_name: str, extra_argv: list[str], allow_write: bool) -> dict[str, Any]:
        app = self.apps.get(slug)
        if not app:
            raise ValueError("unknown or disabled app")
        action = app["actions"].get(action_name)
        if not action:
            raise ValueError("action is not declared for this app")
        if action["access"] == "write" and not allow_write:
            raise PermissionError("write action requires booth:write and explicit confirmation")
        if len(extra_argv) > 32 or any(not isinstance(item, str) or len(item) > 4096 for item in extra_argv):
            raise ValueError("action arguments exceed the bounded argv contract")
        positionals = 0
        index = 0
        while index < len(extra_argv):
            item = extra_argv[index]
            if item.startswith("--"):
                kind = action["options"].get(item)
                if not kind:
                    raise ValueError(f"argument is not declared for this action: {item}")
                if kind == "value":
                    index += 1
                    if index >= len(extra_argv) or extra_argv[index].startswith("--"):
                        raise ValueError(f"argument requires a value: {item}")
            else:
                positionals += 1
                if positionals > action["max_positionals"]:
                    raise ValueError("too many positional arguments for this action")
            index += 1
        base = app["command"][:-2] if app["command"][-2:] == ["agent", "--json"] else app["command"]
        command = [*base, *action["argv"], *extra_argv]
        completed = subprocess.run(
            command,
            capture_output=True,
            text=True,
            timeout=30,
            shell=False,
            check=False,
            env=self._child_env(app),
        )
        if len(completed.stdout) > 262144 or len(completed.stderr) > 65536:
            raise RuntimeError("app response exceeded the bounded output contract")
        parsed: Any = None
        if completed.stdout.strip():
            try:
                parsed = json.loads(completed.stdout)
            except json.JSONDecodeError:
                parsed = completed.stdout
        return {
            "app": slug,
            "action": action_name,
            "access": action["access"],
            "exit_code": completed.returncode,
            "result": parsed,
            "stderr": completed.stderr or None,
        }

    def execute(self, capability: str, arguments: dict[str, Any], actor: str = "local-agent") -> dict[str, Any]:
        if capability == "apps.list":
            return {"apps": self.summary()}
        if capability == "apps.capabilities":
            return self.capabilities(str(arguments.get("app", "")))
        if capability == "apps.run":
            extra_argv = arguments.get("argv", [])
            if not isinstance(extra_argv, list):
                raise ValueError("argv must be a list")
            return self.run_action(
                str(arguments.get("app", "")),
                str(arguments.get("action", "")),
                extra_argv,
                bool(arguments.get("allow_write", False)),
            )
        if capability == "conversation.rooms" and self.history:
            return {"rooms": self.history.rooms(int(arguments.get("limit", 100)))}
        if capability == "conversation.messages" and self.history:
            return {
                "messages": self.history.messages(
                    str(arguments.get("room_id", "")),
                    int(arguments.get("limit", 200)),
                    int(arguments["before"]) if arguments.get("before") is not None else None,
                )
            }
        if capability == "conversation.catch_up" and self.history:
            return self.history.catch_up(str(arguments.get("room_id", "")), arguments.get("after"), int(arguments.get("limit", 200)))
        if capability == "conversation.search" and self.history:
            return {
                "messages": self.history.search(
                    str(arguments.get("query", "")),
                    str(arguments["room_id"]) if arguments.get("room_id") else None,
                    int(arguments.get("limit", 50)),
                )
            }
        if capability == "conversation.post" and self.history:
            message_id = self.history.append(
                str(arguments.get("room_id", "")),
                actor,
                str(arguments.get("body", "")),
                title=str(arguments["title"]) if arguments.get("title") else None,
                ticket_id=str(arguments["ticket_id"]) if arguments.get("ticket_id") else None,
                task_id=str(arguments["task_id"]) if arguments.get("task_id") else None,
            )
            return {"posted": True, "message_id": message_id, "sender": actor}
        if capability == "scope.list" and self.history:
            return {"claims": self.history.scopes()}
        if capability == "scope.claim" and self.history:
            return self.history.claim_scope(
                str(arguments.get("scope", "")),
                actor,
                task_id=str(arguments["task_id"]) if arguments.get("task_id") else None,
                note=str(arguments["note"]) if arguments.get("note") else None,
                ttl=int(arguments.get("ttl", 3600)),
            )
        if capability == "scope.release" and self.history:
            return self.history.release_scope(str(arguments.get("scope", "")), actor)
        raise ValueError("capability is not available to this TROLL BOOTH worker")


def run(base: str, token: str, config: Path, history: Path, worker_id: str, once: bool = False) -> None:
    registry = CapabilityRegistry(config, history)
    while True:
        try:
            _request(base, token, "/worker/heartbeat", {"worker_id": worker_id, "version": __version__, "apps": registry.summary()})
            response = _request(base, token, "/worker/claim", {"worker_id": worker_id})
            job = response.get("job")
            if job:
                try:
                    result = registry.execute(job["capability"], job.get("arguments", {}), str(job.get("actor", "agent")))
                    _request(base, token, "/worker/result", {"job_id": job["id"], "result": result})
                except Exception as exc:
                    _request(base, token, "/worker/result", {"job_id": job["id"], "error": str(exc)[:1000]})
        except (OSError, ValueError, urllib.error.URLError):
            if once:
                raise
        if once:
            return
        time.sleep(2)


def main() -> None:
    parser = argparse.ArgumentParser(description="TROLL BOOTH outbound read-only worker")
    parser.add_argument("--server", default=os.environ.get("TROLL_BOOTH_SERVER", ""))
    parser.add_argument("--token", default=os.environ.get("TROLL_BOOTH_WORKER_TOKEN", ""))
    parser.add_argument("--token-file", type=Path)
    parser.add_argument("--config", type=Path, default=Path.home() / ".troll-booth" / "apps.json")
    parser.add_argument(
        "--history",
        type=Path,
        help="Explicit offline/test store. Production uses the configured MariaDB database.",
    )
    parser.add_argument("--worker-id", default=os.environ.get("COMPUTERNAME", "local-worker").lower())
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    token = args.token
    if not token and args.token_file:
        token = args.token_file.read_text(encoding="utf-8").strip()
    if not args.server or not token:
        parser.error("--server and --token (or matching environment variables) are required")
    instance_lock = WorkerInstanceLock(args.worker_id)
    if not instance_lock.acquire():
        parser.error(f"worker {args.worker_id!r} is already running")
    run(args.server, token, args.config, args.history, args.worker_id, args.once)


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
