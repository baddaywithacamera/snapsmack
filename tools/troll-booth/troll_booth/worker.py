from __future__ import annotations

import argparse
import json
import os
import subprocess
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

from . import __version__
from .history import ConversationStore


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
        self.history = ConversationStore(history_path) if history_path else None

    def _load(self) -> dict[str, dict[str, Any]]:
        if not self.config_path.exists():
            return {}
        data = json.loads(self.config_path.read_text(encoding="utf-8"))
        apps = {}
        for entry in data.get("apps", []):
            slug = str(entry.get("slug", ""))
            command = entry.get("capabilities_command", [])
            if slug and isinstance(command, list) and command and all(isinstance(item, str) for item in command):
                apps[slug] = {"slug": slug, "name": str(entry.get("name", slug)), "command": command}
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

    def execute(self, capability: str, arguments: dict[str, Any], actor: str = "local-agent") -> dict[str, Any]:
        if capability == "apps.list":
            return {"apps": self.summary()}
        if capability == "apps.capabilities":
            return self.capabilities(str(arguments.get("app", "")))
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
    parser.add_argument("--history", type=Path, default=Path.home() / ".troll-booth" / "history.sqlite3")
    parser.add_argument("--worker-id", default=os.environ.get("COMPUTERNAME", "local-worker").lower())
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    token = args.token
    if not token and args.token_file:
        token = args.token_file.read_text(encoding="utf-8").strip()
    if not args.server or not token:
        parser.error("--server and --token (or matching environment variables) are required")
    run(args.server, token, args.config, args.history, args.worker_id, args.once)


if __name__ == "__main__":
    main()

