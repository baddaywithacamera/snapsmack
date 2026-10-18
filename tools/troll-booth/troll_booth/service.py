# SNAPSMACK_EOF_HEADER
"""Loopback-only HTTP service for TROLL BOOTH."""

from __future__ import annotations

import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

from .auth import AuthError, AuthManager
from .broker import Broker
from .room import Room, RoomError


class BoothService:
    def __init__(self, tool_root: Path, port: int = 0):
        self.tool_root = tool_root.resolve()
        self.broker = Broker(
            self.tool_root,
            trust_path=self.tool_root / "state" / "trusted-apps.json",
            require_trust=True,
        )
        self.auth = AuthManager(self.tool_root / "state" / "credentials.json")
        self.room = Room(self.tool_root / "state" / "room.jsonl")
        self.server = ThreadingHTTPServer(("127.0.0.1", port), self._handler())

    @property
    def address(self) -> tuple[str, int]:
        host, port = self.server.server_address[:2]
        return str(host), int(port)

    def _handler(self):
        service = self

        class Handler(BaseHTTPRequestHandler):
            server_version = "TrollBooth/0.2"

            def log_message(self, *_args) -> None:
                return

            def _json(self, status: int, body: Any) -> None:
                encoded = json.dumps(body, ensure_ascii=False).encode("utf-8")
                self.send_response(status)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Content-Length", str(len(encoded)))
                self.send_header("Cache-Control", "no-store")
                self.send_header("X-Content-Type-Options", "nosniff")
                self.end_headers()
                self.wfile.write(encoded)

            def _session(self):
                header = self.headers.get("Authorization", "")
                if not header.startswith("Bearer "):
                    raise AuthError("bearer session required")
                return service.auth.authenticate(header[7:])

            def _body(self) -> dict[str, Any]:
                try:
                    size = int(self.headers.get("Content-Length", "0"))
                except ValueError as exc:
                    raise ValueError("invalid content length") from exc
                if size < 0 or size > 65_536:
                    raise ValueError("request body is too large")
                value = json.loads(self.rfile.read(size) or b"{}")
                if not isinstance(value, dict):
                    raise ValueError("request body must be an object")
                return value

            def do_GET(self) -> None:
                if self.path == "/v1/health":
                    self._json(200, {"ok": True, "configured": service.auth.configured,
                                     "locked": service.auth.locked})
                    return
                try:
                    self._session()
                except AuthError as exc:
                    self._json(401, {"ok": False, "error": str(exc)})
                    return
                if self.path == "/v1/apps":
                    self._json(200, {"ok": True, "apps": service.broker.list_apps()})
                elif self.path == "/v1/room":
                    self._json(200, {"ok": True, "messages": service.room.recent()})
                else:
                    self._json(404, {"ok": False, "error": "not found"})

            def do_POST(self) -> None:
                if self.path == "/v1/pair":
                    try:
                        body = self._body()
                        code = body.get("code")
                        if not isinstance(code, str) or len(code) > 64:
                            raise ValueError("invalid pairing code")
                        token, session = service.auth.consume_pairing(code)
                    except (AuthError, ValueError, json.JSONDecodeError) as exc:
                        self._json(401 if isinstance(exc, AuthError) else 400,
                                   {"ok": False, "error": str(exc)})
                        return
                    self._json(200, {"ok": True, "token": token,
                                     "actor": session.actor,
                                     "expires_at": session.expires_at})
                    return
                try:
                    session = self._session()
                    body = self._body()
                except (AuthError, ValueError, json.JSONDecodeError) as exc:
                    self._json(401 if isinstance(exc, AuthError) else 400,
                               {"ok": False, "error": str(exc)})
                    return
                if self.path == "/v1/lock":
                    service.auth.lock()
                    self._json(200, {"ok": True, "locked": True})
                    return
                if self.path == "/v1/room/message":
                    try:
                        message = service.room.add(
                            session.actor, body.get("text", ""),
                            body.get("recipient", "all"), body.get("kind", "message"))
                    except RoomError as exc:
                        self._json(400, {"ok": False, "error": str(exc)})
                        return
                    self._json(200, {"ok": True, "message": message})
                    return
                if self.path != "/v1/invoke":
                    self._json(404, {"ok": False, "error": "not found"})
                    return
                app = body.get("app")
                capability = body.get("capability")
                arguments = body.get("arguments", {})
                if not isinstance(app, str) or not isinstance(capability, str) \
                        or not isinstance(arguments, dict):
                    self._json(400, {"ok": False, "error": "invalid invocation"})
                    return
                result = service.broker.invoke(
                    app, capability, arguments, session.actor,
                    str(body.get("reason", ""))[:500], str(body.get("ticket", ""))[:80],
                )
                self._json(200 if result.get("ok") else 400, result)

        return Handler

    def serve(self) -> None:
        self.server.serve_forever(poll_interval=0.25)

    def close(self) -> None:
        self.server.server_close()

# ===== SNAPSMACK EOF =====
