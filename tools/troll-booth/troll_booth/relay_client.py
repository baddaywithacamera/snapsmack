# SNAPSMACK_EOF_HEADER
"""Outbound HTTPS client connecting a local booth to the public relay."""

from __future__ import annotations

import json
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Callable

from .broker import Broker
from .mcp import McpAdapter


class RelayError(RuntimeError):
    """The relay connection or response was invalid."""


class RelayClient:
    def __init__(self, broker: Broker, url: str, device_id: str, token: str,
                 status: Callable[[str], None] | None = None,
                 authorized: Callable[[], bool] | None = None):
        parsed = urllib.parse.urlparse(url.rstrip("/"))
        if parsed.scheme != "https" or not parsed.netloc or parsed.path not in {"", "/"}:
            raise RelayError("relay URL must be an HTTPS origin without a path")
        if not device_id or len(device_id) > 80 or not all(
                ch.isalnum() or ch in "._-" for ch in device_id):
            raise RelayError("invalid device id")
        if len(token) < 32:
            raise RelayError("device token must contain at least 32 characters")
        self.broker = broker
        self.url = url.rstrip("/")
        self.device_id = device_id
        self.token = token
        self.status = status or (lambda _message: None)
        self.authorized = authorized or (lambda: True)
        self.stop_event = threading.Event()

    def _request(self, path: str, body: dict[str, Any]) -> dict[str, Any]:
        request = urllib.request.Request(
            self.url + path,
            data=json.dumps(body, ensure_ascii=False).encode("utf-8"),
            method="POST",
            headers={"Authorization": "Bearer " + self.token,
                     "X-Troll-Device": self.device_id,
                     "Content-Type": "application/json",
                     "User-Agent": "TROLL-BOOTH/0.3"},
        )
        try:
            with urllib.request.urlopen(request, timeout=35) as response:
                value = json.load(response)
        except (OSError, urllib.error.HTTPError, json.JSONDecodeError) as exc:
            raise RelayError(str(exc)) from exc
        if not isinstance(value, dict):
            raise RelayError("relay returned an invalid response")
        return value

    def register(self) -> None:
        tools = McpAdapter(self.broker).tools()
        response = self._request("/agent/register", {"tools": tools})
        if not response.get("ok"):
            raise RelayError(str(response.get("error", "registration refused")))

    def execute(self, job: dict[str, Any]) -> dict[str, Any]:
        name = job.get("tool", "")
        actor = job.get("actor", "")
        arguments = job.get("arguments", {})
        if not isinstance(name, str) or "__" not in name or not isinstance(arguments, dict):
            return {"ok": False, "error": "relay supplied an invalid job"}
        app_id, capability = name.split("__", 1)
        return self.broker.invoke(app_id, capability, arguments, str(actor),
                                  "Remote MCP request", str(job.get("id", "")))

    def run(self) -> None:
        if not self.authorized():
            self.status("Relay refused: local session is locked or expired")
            return
        self.register()
        self.status("Relay connected")
        while not self.stop_event.is_set() and self.authorized():
            try:
                response = self._request("/agent/next", {})
                job = response.get("job")
                if isinstance(job, dict):
                    if self.stop_event.is_set() or not self.authorized():
                        result = {"ok": False, "error": "Local TROLL BOOTH was locked"}
                    else:
                        result = self.execute(job)
                    self._request("/agent/result", {"job_id": job.get("id"),
                                                     "result": result})
                else:
                    self.stop_event.wait(1.0)
            except RelayError as exc:
                self.status("Relay unavailable: " + str(exc))
                self.stop_event.wait(5.0)
        self.status("Relay disconnected or local session expired")

    def stop(self) -> None:
        self.stop_event.set()

# ===== SNAPSMACK EOF =====
