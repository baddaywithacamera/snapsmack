"""
blink-suyb — blink_server.py
A tiny, dependency-free localhost server (Python stdlib only) that serves the
Chrome UI and exposes the SUYB engines as a JSON API.

Why stdlib only: blink-suyb must run on Linux, macOS and Windows with nothing
but a Python 3 runtime. No Flask, no external web framework — http.server is
enough for a single-user, localhost-only control surface.

Security posture: binds to 127.0.0.1 only, never 0.0.0.0. A random per-launch
token is required on every /api call (the launcher passes it to the page), so a
stray browser tab on another site cannot poke the API.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import json
import mimetypes
import os
import secrets
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

from suyb_bridge import (
    engine_report,
    list_credentials,
    list_profiles,
    read_config,
    status,
)

_HERE = os.path.dirname(os.path.abspath(__file__))
WEB_DIR = os.path.normpath(os.path.join(_HERE, "..", "web"))

# Per-launch shared secret. The launcher reads this and injects it into the page.
API_TOKEN = secrets.token_urlsafe(24)


def _api_routes():
    """Map of GET API paths -> zero-arg callables returning JSON-able data."""
    return {
        "/api/status": status,
        "/api/profiles": list_profiles,
        "/api/credentials": list_credentials,
        "/api/config": read_config,
        "/api/engines": engine_report,
        "/api/version": lambda: {"name": "blink-suyb", "version": _version()},
    }


def _version():
    vf = os.path.normpath(os.path.join(_HERE, "..", "VERSION"))
    try:
        with open(vf, encoding="utf-8") as f:
            return f.read().strip()
    except OSError:
        return "0.1.0"


class Handler(BaseHTTPRequestHandler):
    server_version = "blink-suyb"

    # --- helpers -----------------------------------------------------------
    def _send_json(self, obj, code=200):
        body = json.dumps(obj).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def _send_file(self, relpath):
        # Serve static UI files, guarding against path traversal.
        relpath = relpath.lstrip("/")
        if not relpath:
            relpath = "index.html"
        full = os.path.normpath(os.path.join(WEB_DIR, relpath))
        if not full.startswith(WEB_DIR) or not os.path.isfile(full):
            self._send_json({"error": "not found"}, 404)
            return
        ctype = mimetypes.guess_type(full)[0] or "application/octet-stream"
        with open(full, "rb") as f:
            body = f.read()
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _authed(self):
        return self.headers.get("X-Blink-Token") == API_TOKEN

    # --- verbs -------------------------------------------------------------
    def do_GET(self):
        path = urlparse(self.path).path
        if path == "/token":
            # The launcher opens /token#<token>; page reads it, then calls APIs.
            self._send_file("index.html")
            return
        if path.startswith("/api/"):
            if not self._authed():
                self._send_json({"error": "unauthorized"}, 401)
                return
            route = _api_routes().get(path)
            if not route:
                self._send_json({"error": "unknown endpoint"}, 404)
                return
            try:
                self._send_json({"ok": True, "data": route()})
            except Exception as e:                        # noqa: BLE001
                self._send_json({"ok": False, "error": f"{type(e).__name__}: {e}"}, 500)
            return
        self._send_file(path)

    def log_message(self, *args):
        pass  # quiet; the UI is the console


def make_server(port=0):
    httpd = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    return httpd


def serve(port=0, ready=None):
    httpd = make_server(port)
    actual = httpd.server_address[1]
    if ready:
        ready(actual, API_TOKEN)
    httpd.serve_forever()


if __name__ == "__main__":
    def _print_ready(port, token):
        print(f"blink-suyb server on http://127.0.0.1:{port}/  token={token}")
    t = threading.Thread(target=serve, kwargs={"port": 8765, "ready": _print_ready})
    t.start()
    t.join()

# ===== SNAPSMACK EOF =====
