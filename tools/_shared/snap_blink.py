"""
SNAPSMACK — snap_blink.py  (shared Chromium/Blink runtime for the Linux tool family)

WHAT THIS IS, IN PLAIN TERMS
    The SnapSmack desktop tools were Windows apps drawn with tkinter. This is the
    ONE shared piece that lets every tool run on Linux with its window drawn by
    Chrome/Chromium (the Blink engine) instead of tkinter. A tool now is:

        - Python for the WORK (talk to the site, read/write files, call Gemini …)
        - HTML/CSS/JS for the WINDOW (served locally, shown in a real Chrome window)

    snap_blink is the bridge between those two halves. A tool registers Python
    functions; the web page calls them with one line of JavaScript; the answer
    comes back as JSON. No tkinter, no PyQt, no CEF, no pip installs — just the
    Python standard library plus a Chromium/Chrome binary that is already on the
    box. "Look for the pencil": this is the pencil.

HOW A TOOL USES IT
    import snap_blink
    app = snap_blink.App(tool="sybu", title="SYBU", web_dir="web")

    @app.api                      # exposes load_sites() to the page as blink.call('load_sites')
    def load_sites():
        return [...]              # anything JSON-serialisable

    @app.api
    def post_photo(site, path, caption):
        ...                       # positional args arrive in the order JS passed them
        return {"ok": True}

    app.run()                     # starts the local server, opens the Chrome window,
                                  # and blocks until the user closes that window.

    In the page:
        <script src="/snap_blink.js"></script>
        const sites = await blink.call('load_sites');
        await blink.call('post_photo', site, path, caption);

WHY IT IS SAFE
    - The server binds to 127.0.0.1 only and on a random free port. Nothing off-box
      can reach it.
    - Every /api call must carry a per-launch random token (X-Blink-Token). The token
      is handed to the page inside the served snap_blink.js, so only the window this
      process opened can call the Python side. A stray local script cannot guess it.
    - Chromium runs with its OWN throwaway profile dir per tool, so opening the app
      never collides with the user's real browser session, and closing the window
      exits the process (that is how we know to shut the server down).

SNAPSMACK_HOME
    snap_home defaults its root to C:\\snapsmack. On a non-Windows box that is wrong,
    so App() sets a sane Linux default (~/snapsmack) if SNAPSMACK_HOME is unset. Set
    the env var yourself to override.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import os
import secrets
import shutil
import subprocess
import sys
import tempfile
import threading
import webbrowser
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, unquote


# ── Chromium/Chrome discovery ────────────────────────────────────────────────
# Ordered by preference. First hit on PATH (or at an absolute location) wins. The
# whole point of the family is Blink, so every candidate here is a Blink browser.
_LINUX_CANDIDATES = [
    "chromium", "chromium-browser",
    "google-chrome", "google-chrome-stable",
    "brave-browser", "microsoft-edge", "microsoft-edge-stable",
    "vivaldi", "vivaldi-stable",
    # common absolute installs when not on PATH
    "/usr/bin/chromium", "/usr/bin/chromium-browser",
    "/usr/bin/google-chrome", "/snap/bin/chromium",
    "/var/lib/flatpak/exports/bin/org.chromium.Chromium",
]
_WIN_CANDIDATES = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Chromium\Application\chrome.exe",
]
_MAC_CANDIDATES = [
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    "/Applications/Chromium.app/Contents/MacOS/Chromium",
    "/Applications/Brave Browser.app/Contents/MacOS/Brave Browser",
]


def find_chromium():
    """Return the path to a Blink browser binary, or None if none is installed."""
    if sys.platform.startswith("win"):
        candidates = _WIN_CANDIDATES
    elif sys.platform == "darwin":
        candidates = _MAC_CANDIDATES
    else:
        candidates = _LINUX_CANDIDATES
    for cand in candidates:
        hit = shutil.which(cand) if os.path.basename(cand) == cand else (cand if os.path.exists(cand) else None)
        if hit:
            return hit
    return None


# ── The client shim served to the page ───────────────────────────────────────
# blink.call(method, ...args) → POST /api/method with the args as a JSON array and
# the launch token in a header. Returns the parsed JSON result, throws on error.
_JS_TEMPLATE = """// snap_blink.js — auto-served bridge to the Python side. Do not edit; regenerated per launch.
(function () {
  const TOKEN = "%TOKEN%";
  async function call(method /*, ...args */) {
    const args = Array.prototype.slice.call(arguments, 1);
    const res = await fetch("/api/" + encodeURIComponent(method), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Blink-Token": TOKEN },
      body: JSON.stringify(args),
    });
    const text = await res.text();
    let body;
    try { body = text ? JSON.parse(text) : null; } catch (e) { body = text; }
    if (!res.ok) {
      const msg = (body && body.error) ? body.error : (text || ("HTTP " + res.status));
      throw new Error(msg);
    }
    return body;
  }
  window.blink = { call: call, token: TOKEN };
})();
"""


class App:
    """One tool's Blink app: a local static+API server plus a Chrome window."""

    def __init__(self, tool, title=None, web_dir="web", host="127.0.0.1"):
        self.tool = tool
        self.title = title or tool
        self.host = host
        self.web_dir = os.path.abspath(web_dir)
        self.token = secrets.token_urlsafe(24)
        self._handlers = {}
        self._httpd = None
        self._server_thread = None

        # Give the shared library a Linux-sane root if the caller did not set one.
        if not os.environ.get("SNAPSMACK_HOME") and not sys.platform.startswith("win"):
            os.environ["SNAPSMACK_HOME"] = os.path.expanduser("~/snapsmack")

    # ── registering Python functions ────────────────────────────────────────
    def api(self, fn):
        """Decorator: expose fn to the page as blink.call('<fn.__name__>', ...)."""
        self._handlers[fn.__name__] = fn
        return fn

    def register(self, name, fn):
        """Non-decorator form: app.register('save', save_fn)."""
        self._handlers[name] = fn
        return fn

    # ── the request handler ─────────────────────────────────────────────────
    def _make_handler(self):
        app = self

        class Handler(BaseHTTPRequestHandler):
            # Quiet by default; a tool can tail its own log instead of stderr noise.
            def log_message(self, *a):
                pass

            def _send(self, code, body=b"", ctype="application/octet-stream"):
                self.send_response(code)
                self.send_header("Content-Type", ctype)
                self.send_header("Content-Length", str(len(body)))
                # Localhost-only app; no caching so edits show on reload during dev.
                self.send_header("Cache-Control", "no-store")
                self.end_headers()
                if body:
                    self.wfile.write(body)

            def _send_json(self, code, obj):
                self._send(code, json.dumps(obj).encode("utf-8"), "application/json; charset=utf-8")

            # static files + the js shim
            def do_GET(self):
                path = urlparse(self.path).path
                if path == "/snap_blink.js":
                    js = _JS_TEMPLATE.replace("%TOKEN%", app.token).encode("utf-8")
                    return self._send(200, js, "application/javascript; charset=utf-8")
                rel = unquote(path.lstrip("/")) or "index.html"
                # Contain the path inside web_dir — never serve outside the UI folder.
                full = os.path.abspath(os.path.join(app.web_dir, rel))
                if not full.startswith(app.web_dir + os.sep) and full != app.web_dir:
                    return self._send(403, b"forbidden", "text/plain")
                if os.path.isdir(full):
                    full = os.path.join(full, "index.html")
                if not os.path.isfile(full):
                    return self._send(404, b"not found", "text/plain")
                return self._send(200, _read(full), _ctype(full))

            # api calls
            def do_POST(self):
                path = urlparse(self.path).path
                if not path.startswith("/api/"):
                    return self._send(404, b"not found", "text/plain")
                if self.headers.get("X-Blink-Token") != app.token:
                    return self._send_json(403, {"error": "bad token"})
                method = unquote(path[len("/api/"):])
                fn = app._handlers.get(method)
                if fn is None:
                    return self._send_json(404, {"error": "no such method: " + method})
                try:
                    length = int(self.headers.get("Content-Length") or 0)
                    raw = self.rfile.read(length) if length else b"[]"
                    args = json.loads(raw or b"[]")
                    if not isinstance(args, list):
                        args = [args]
                    result = fn(*args)
                    return self._send_json(200, result)
                except Exception as exc:  # surface the real error to the page for the log
                    return self._send_json(500, {"error": "%s: %s" % (type(exc).__name__, exc)})

        return Handler

    # ── lifecycle ───────────────────────────────────────────────────────────
    def start_server(self):
        """Start the local server on a random free port; return (host, port)."""
        self._httpd = ThreadingHTTPServer((self.host, 0), self._make_handler())
        port = self._httpd.server_address[1]
        self._server_thread = threading.Thread(target=self._httpd.serve_forever, daemon=True)
        self._server_thread.start()
        return self.host, port

    def _profile_dir(self):
        # Per-tool throwaway Chromium profile so each app is its own window and
        # closing it exits the process. Lives under the shared home, not /tmp, so a
        # crash leaves it somewhere findable.
        base = os.path.join(os.environ.get("SNAPSMACK_HOME", tempfile.gettempdir()), ".blink")
        d = os.path.join(base, self.tool)
        os.makedirs(d, exist_ok=True)
        return d

    def open_window(self, url):
        """Open `url` in a Chromium app window and BLOCK until it is closed.
        Falls back to the default browser (non-blocking) if no Blink binary found."""
        browser = find_chromium()
        if not browser:
            # No Chromium/Chrome anywhere. Do not fail the tool — open in whatever
            # the OS has and let the caller decide when to stop.
            webbrowser.open(url)
            return None
        args = [
            browser,
            "--app=" + url,
            "--user-data-dir=" + self._profile_dir(),
            "--no-first-run",
            "--no-default-browser-check",
            "--disable-background-networking",
            "--window-size=1200,820",
        ]
        proc = subprocess.Popen(args)
        proc.wait()  # returns when the user closes the app window
        return proc.returncode

    def stop(self):
        if self._httpd is not None:
            self._httpd.shutdown()
            self._httpd = None

    def run(self):
        """Start server, open the Chrome window, and clean up when it closes."""
        host, port = self.start_server()
        url = "http://%s:%d/" % (host, port)
        try:
            rc = self.open_window(url)
            if rc is None:
                # Fallback path (no Chromium): keep serving until Ctrl-C so the tool
                # is still usable in a normal browser tab.
                print("[snap_blink] No Chromium/Chrome found. Open %s in a browser." % url)
                print("[snap_blink] Press Ctrl-C to quit.")
                try:
                    self._server_thread.join()
                except KeyboardInterrupt:
                    pass
        finally:
            self.stop()


# ── native file/folder picker (the one thing a Blink window cannot do itself) ─
# A sandboxed Chromium --app window hands the page file *contents*, never a path,
# and offers no folder chooser at all. Every ported tool needs "pick a folder / a
# file", so this is the ONE shared implementation: shell out to the Linux desktop's
# own dialog (zenity / kdialog / qarma). Returns the chosen absolute path, or None
# if the user cancelled or no dialog program is installed (callers then fall back to
# a typed path field). A tool exposes this as an @app.api handler, e.g.:
#     @app.api
#     def browse_folder(): return snap_blink.pick_path("folder") or ""
_DIALOGS = ["zenity", "kdialog", "qarma"]


def find_dialog():
    """Return the name of an installed desktop file-dialog program, or None."""
    for d in _DIALOGS:
        if shutil.which(d):
            return d
    return None


def pick_path(mode="file", title=None, start_dir=None):
    """Open the desktop's native chooser and return the picked absolute path.

    mode: "file" (open a file), "folder" (choose a directory), "save" (save-as).
    Returns None on cancel or when no dialog program exists. Never raises."""
    prog = find_dialog()
    if not prog:
        return None
    title = title or {"file": "Choose a file", "folder": "Choose a folder",
                      "save": "Save as"}.get(mode, "Choose")
    try:
        if prog == "kdialog":
            flag = {"folder": "--getexistingdirectory", "save": "--getsavefilename"}.get(mode, "--getopenfilename")
            cmd = [prog, flag, start_dir or os.path.expanduser("~")]
        else:  # zenity / qarma share a CLI
            cmd = [prog, "--file-selection", "--title", title]
            if mode == "folder":
                cmd.append("--directory")
            if mode == "save":
                cmd.append("--save")
            if start_dir:
                cmd += ["--filename", os.path.join(start_dir, "")]
        out = subprocess.run(cmd, capture_output=True, text=True)
        path = (out.stdout or "").strip()
        return path or None
    except Exception:
        return None


# ── tiny helpers ─────────────────────────────────────────────────────────────
_CTYPES = {
    ".html": "text/html; charset=utf-8", ".htm": "text/html; charset=utf-8",
    ".css": "text/css; charset=utf-8", ".js": "application/javascript; charset=utf-8",
    ".json": "application/json; charset=utf-8", ".svg": "image/svg+xml",
    ".png": "image/png", ".jpg": "image/jpeg", ".jpeg": "image/jpeg",
    ".gif": "image/gif", ".webp": "image/webp", ".ico": "image/x-icon",
    ".woff": "font/woff", ".woff2": "font/woff2", ".ttf": "font/ttf",
    ".map": "application/json",
}


def _ctype(path):
    return _CTYPES.get(os.path.splitext(path)[1].lower(), "application/octet-stream")


def _read(path):
    with open(path, "rb") as fh:
        return fh.read()


# ===== SNAPSMACK EOF =====
