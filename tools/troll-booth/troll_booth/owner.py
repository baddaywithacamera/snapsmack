from __future__ import annotations

import html
import json
import os
import secrets
import shutil
import threading
import webbrowser
from http import cookies
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, quote, urlparse

from .history import open_conversation_store


HOST = "127.0.0.1"
PORT = int(os.environ.get("TROLL_BOOTH_OWNER_PORT", "8766"))
ROOT = Path.home() / ".troll-booth"
HISTORY = Path(os.environ.get("TROLL_BOOTH_HISTORY", ROOT / "history.sqlite3"))
AGREEMENT = Path(os.environ.get("TROLL_BOOTH_AGREEMENT", ROOT / "working-agreement.md"))
APPS = Path(os.environ.get("TROLL_BOOTH_APPS", ROOT / "apps.json"))
TOKEN_FILE = ROOT / "owner-token"


def _token() -> str:
    ROOT.mkdir(parents=True, exist_ok=True)
    if TOKEN_FILE.exists():
        value = TOKEN_FILE.read_text(encoding="utf-8").strip()
        if len(value) >= 32:
            return value
    value = secrets.token_urlsafe(32)
    TOKEN_FILE.write_text(value, encoding="utf-8")
    return value


OWNER_TOKEN = _token()
STORE, STORAGE_LABEL = open_conversation_store()


def _read(path: Path, default: str) -> str:
    try:
        return path.read_text(encoding="utf-8")
    except FileNotFoundError:
        return default


def _save(path: Path, value: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        shutil.copy2(path, path.with_suffix(path.suffix + ".bak"))
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(value, encoding="utf-8")
    os.replace(temporary, path)


class OwnerHandler(BaseHTTPRequestHandler):
    server_version = "TrollBoothOwner/0.1"

    def log_message(self, format: str, *args: object) -> None:
        return

    def _authorized(self) -> bool:
        parsed = urlparse(self.path)
        supplied = parse_qs(parsed.query).get("token", [""])[0]
        jar = cookies.SimpleCookie(self.headers.get("Cookie", ""))
        cookie = jar.get("trollbooth_owner")
        return secrets.compare_digest(supplied or (cookie.value if cookie else ""), OWNER_TOKEN)

    def _send(self, body: str, status: int = 200, *, set_cookie: bool = False) -> None:
        data = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("Content-Security-Policy", "default-src 'self'; style-src 'unsafe-inline'; form-action 'self'")
        if set_cookie:
            self.send_header("Set-Cookie", f"trollbooth_owner={OWNER_TOKEN}; HttpOnly; SameSite=Strict; Path=/")
        self.end_headers()
        self.wfile.write(data)

    def _deny(self) -> None:
        self._send("<!doctype html><title>TROLL BOOTH</title><h1>Owner token required.</h1>", 403)

    def do_GET(self) -> None:
        if not self._authorized():
            self._deny()
            return
        parsed = urlparse(self.path)
        params = parse_qs(parsed.query)
        room = params.get("room", [""])[0]
        query = params.get("q", [""])[0].strip()
        rooms = STORE.rooms()
        if not room and rooms:
            room = rooms[0]["id"]
        messages = STORE.search(query, room or None, 200) if query else (STORE.messages(room, 500) if room else [])
        self._send(self._page(room, query, rooms, messages), set_cookie=True)

    def do_POST(self) -> None:
        if not self._authorized() or self.headers.get("Origin") not in {None, f"http://{HOST}:{PORT}"}:
            self._deny()
            return
        length = min(int(self.headers.get("Content-Length", "0")), 1_000_000)
        form = parse_qs(self.rfile.read(length).decode("utf-8", "replace"))
        action = urlparse(self.path).path
        room = form.get("room", ["general"])[0].strip() or "general"
        if action == "/message":
            body = form.get("body", [""])[0].strip()
            if body:
                STORE.append(room, "sean", body, title=room)
        elif action == "/agreement":
            _save(AGREEMENT, form.get("agreement", [""])[0])
        elif action == "/apps":
            value = form.get("apps", [""])[0]
            parsed = json.loads(value)
            if not isinstance(parsed, dict) or not isinstance(parsed.get("apps"), list):
                raise ValueError("App configuration requires an apps array")
            _save(APPS, json.dumps(parsed, indent=2, ensure_ascii=False) + "\n")
        self.send_response(303)
        self.send_header("Location", f"/?room={quote(room)}")
        self.end_headers()

    def _page(self, room: str, query: str, rooms: list[dict], messages: list[dict]) -> str:
        room_links = "".join(
            f"<a class={'active' if item['id'] == room else ''} href='/?room={quote(item['id'])}'>"
            f"{html.escape(item['title'])}<small>{item['message_count']} messages</small></a>"
            for item in rooms
        ) or "<p class=muted>No rooms yet.</p>"
        transcript = "".join(
            f"<article><header>{html.escape(message['sender'])}<time>{message['created_at']}</time></header>"
            f"<div>{html.escape(message['body']).replace(chr(10), '<br>')}</div></article>"
            for message in messages
        ) or "<p class=muted>No messages here yet.</p>"
        agreement = html.escape(_read(AGREEMENT, "# Working Agreement\n\nRequests, boundaries, and collaboration preferences live here.\n"))
        apps = html.escape(_read(APPS, '{\n  "apps": []\n}\n'))
        safe_room = html.escape(room or "general", quote=True)
        safe_query = html.escape(query, quote=True)
        return f"""<!doctype html><html><head><meta charset=utf-8><meta name=viewport content='width=device-width'>
<title>TROLL BOOTH Owner Console</title><style>
:root{{--bg:#0a0f0b;--panel:#111b14;--line:#29432e;--ink:#edf7ee;--muted:#93a894;--green:#72ff48}}
*{{box-sizing:border-box}} body{{margin:0;background:var(--bg);color:var(--ink);font:15px system-ui}}
header.top{{padding:18px 24px;border-bottom:1px solid var(--line);display:flex;gap:16px;align-items:baseline}}
h1{{font-size:20px;margin:0;color:var(--green);letter-spacing:.08em}} .muted,small,time{{color:var(--muted)}}
main{{display:grid;grid-template-columns:240px minmax(380px,1fr) minmax(330px,.7fr);height:calc(100vh - 64px)}}
nav,.chat,.config{{padding:18px;overflow:auto}} nav,.chat{{border-right:1px solid var(--line)}}
nav a{{display:block;color:var(--ink);padding:12px;text-decoration:none;border-radius:7px}} nav a.active{{background:var(--panel);color:var(--green)}} nav small{{display:block}}
form{{margin:0 0 16px}} input,textarea,button{{font:inherit;color:var(--ink);background:#071009;border:1px solid var(--line);padding:10px;border-radius:5px}}
input,textarea{{width:100%}} textarea{{min-height:110px;resize:vertical}} button{{background:var(--green);color:#071009;font-weight:700;cursor:pointer}}
article{{background:var(--panel);border:1px solid var(--line);padding:12px;margin:0 0 10px;border-radius:7px}} article header{{font-weight:700;color:var(--green);margin-bottom:7px}} time{{float:right;font-weight:400}}
.config section{{margin-bottom:24px}} .config textarea{{min-height:220px;font-family:ui-monospace,monospace}} .config h2,.chat h2{{font-size:16px}}
@media(max-width:950px){{main{{display:block;height:auto}}nav,.chat{{border-right:0;border-bottom:1px solid var(--line)}}}}
</style></head><body><header class=top><h1>TROLL BOOTH</h1><span>Owner console · local machine only</span></header>
<main><nav><h2>Rooms</h2>{room_links}</nav><section class=chat><h2>{html.escape(room or 'general')}</h2>
<form method=get><input type=hidden name=room value='{safe_room}'><input name=q value='{safe_query}' placeholder='Search this room'></form>
{transcript}<form method=post action=/message><input type=hidden name=room value='{safe_room}'><textarea name=body required placeholder='Write a request or message…'></textarea><button>Post as Sean</button></form></section>
<aside class=config><section><h2>Working Agreement</h2><p class=muted>Your interaction preferences and standing requests. No passwords or TOTP.</p>
<form method=post action=/agreement><input type=hidden name=room value='{safe_room}'><textarea name=agreement>{agreement}</textarea><button>Save agreement</button></form></section>
<section><h2>App capability configuration</h2><p class=muted>Structured app discovery only. A timestamped previous copy is kept beside this file.</p>
<form method=post action=/apps><input type=hidden name=room value='{safe_room}'><textarea name=apps>{apps}</textarea><button>Validate and save</button></form></section></aside></main></body></html>"""


def main() -> None:
    server = ThreadingHTTPServer((HOST, PORT), OwnerHandler)
    url = f"http://{HOST}:{PORT}/?token={OWNER_TOKEN}"
    if os.environ.get("TROLL_BOOTH_NO_BROWSER") != "1":
        threading.Timer(0.5, lambda: webbrowser.open(url)).start()
    print(f"TROLL BOOTH owner console: {url}")
    server.serve_forever()


if __name__ == "__main__":
    main()
