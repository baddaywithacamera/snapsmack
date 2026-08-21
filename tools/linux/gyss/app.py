#!/usr/bin/env python3
"""
GET YOUR SHIT SORTED (GYSS) — Linux Chrome/Blink port.

WHAT THIS IS
    GYSS was a Tauri app: a thin Rust backend (file IO + a shared SQLite catalog)
    with ALL the application logic in an HTML/JS front-end. This port keeps that
    front-end verbatim and re-implements the Rust `#[tauri::command]` functions in
    Python on top of the shared snap_blink runtime. The window is now drawn by
    Chromium/Chrome instead of the Tauri webview; nothing about what GYSS DOES
    changes.

    GYSS is an OFFLINE per-blog sorter for SMACKONEOUT (photoblog) and GRAMOFSMACK
    (carousel) sites only — never SMACKTALK longform (longform images live inside
    essays, so there is no feed to sort). The front-end enforces that; this backend
    just moves bytes.

THE COMMAND CONTRACT (parity with src-tauri/src/lib.rs)
    Tauri commands receive NAMED parameters (invoke('write_file', { path, content })).
    snap_blink hands a handler POSITIONAL args. The port keeps the Tauri shape by
    having the JS shim pass the SINGLE named-args OBJECT as one positional argument,
    so every handler below takes one `args` dict and reads the same keys the Rust
    command did. `shared_home` takes no args, exactly like the Rust command.

    Reproduced 1:1 from Rust:
        shared_home   read_file   write_file   list_dir
        download_to   delete_file catalog_sync catalog_read
    Added for the Blink runtime (Tauri had convertFileSrc built in; Blink cannot
    serve files from outside web/ so thumbnails are handed back as a data: URL):
        read_asset

SECURITY (ports the SECAUDIT-039 file jail)
    Every file command is confined to the shared SnapSmack root (SNAPSMACK_HOME,
    else the snap_home default). `_resolve_in_root` rejects any '..' component and
    then requires the path to sit inside the root — the same guard the Rust
    `resolve_in_root` applied. download_to only accepts http(s) and caps the body.

SHARED-LIBRARY CONTRACT
    GYSS reads/writes the SAME on-disk locations and byte formats as the Python
    family (COLD SNAP / SYBU): connection profiles under shared_library/profiles/,
    shared secrets under shared_library/auth/shared_creds.json, and the per-site
    store + db/catalog.sqlite under shared_library/<site_key>/. As in the Tauri
    build, that profile/creds encoding lives in the JS (profiles.js / shared-creds.js);
    this backend only provides the jailed file primitives they stand on, so a blog
    set up in any tool is visible here and vice-versa.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import base64
import json
import os
import sqlite3
import sys
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                              # tools/gyss/
SHARED = os.path.abspath(os.path.join(TOOL_ROOT, "..", "_shared"))  # tools/_shared/
for _p in (SHARED, TOOL_ROOT):
    if _p not in sys.path:
        sys.path.insert(0, _p)

import snap_blink  # noqa: E402  (path set up above)

# snap_home gives the same root (SNAPSMACK_HOME, else the family default) the Rust
# shared_root() used. Import is best-effort: if it is unavailable for any reason we
# fall back to the identical env-var-or-default logic so the jail still holds.
try:
    import snap_home  # noqa: E402
    _home = snap_home.home
except Exception:  # pragma: no cover - defensive
    def _home():
        root = (os.environ.get("SNAPSMACK_HOME") or "").strip() or os.path.expanduser("~/snapsmack")
        return os.path.abspath(root)


app = snap_blink.App(
    tool="gyss",
    title="GET YOUR SHIT SORTED",
    web_dir=os.path.join(HERE, "web"),
)


# ── snap_library.py's _SCHEMA, VERBATIM (kept byte-for-byte with lib.rs) ───────
CATALOG_SCHEMA = """
CREATE TABLE IF NOT EXISTS meta       (key TEXT PRIMARY KEY, val TEXT);
CREATE TABLE IF NOT EXISTS categories (name TEXT PRIMARY KEY, description TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS albums     (name TEXT PRIMARY KEY, description TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS tags       (tag  TEXT PRIMARY KEY);
CREATE TABLE IF NOT EXISTS titles     (title TEXT PRIMARY KEY);
"""

_MAX_DOWNLOAD_BYTES = 64 * 1024 * 1024  # 64 MiB — matches the Rust download cap.

_ASSET_MIME = {
    ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png",
    ".gif": "image/gif", ".webp": "image/webp", ".bmp": "image/bmp",
    ".svg": "image/svg+xml",
}


# ── The file jail (ports resolve_in_root from lib.rs) ─────────────────────────
def _resolve_in_root(path):
    """Confine `path` to the shared SnapSmack root. Reject any '..' component, then
    require the resolved path to sit inside the root. Mirrors the Rust guard that
    stopped write_file being an arbitrary-file-write primitive."""
    if path is None:
        raise ValueError("Refused: no path given.")
    raw = str(path)
    # Reject traversal on either separator before resolving (defense in depth).
    parts = raw.replace("\\", "/").split("/")
    if any(part == ".." for part in parts):
        raise ValueError("Refused: path traversal ('..') is not allowed.")
    base = os.path.normpath(_home())
    resolved = os.path.normpath(os.path.abspath(raw))
    try:
        inside = os.path.commonpath([resolved, base]) == base
    except ValueError:
        inside = False  # different drives / unrelated roots
    if not (resolved == base or inside):
        raise ValueError("Refused: path is outside the SnapSmack root.")
    return resolved


# ── Commands reproduced from src-tauri/src/lib.rs ─────────────────────────────
@app.api
def shared_home():
    """Rust `shared_home`: the resolved SnapSmack root, so JS can build paths under
    it. Matches snap_home.home()."""
    return os.path.normpath(_home())


@app.api
def read_file(args):
    """Rust `read_file`: read a UTF-8 file (profile / session / meta JSON)."""
    p = _resolve_in_root(args.get("path"))
    with open(p, "r", encoding="utf-8") as fh:
        return fh.read()


@app.api
def write_file(args):
    """Rust `write_file`: write a UTF-8 string, creating parent dirs."""
    p = _resolve_in_root(args.get("path"))
    content = args.get("content", "")
    parent = os.path.dirname(p)
    if parent:
        os.makedirs(parent, exist_ok=True)
    with open(p, "w", encoding="utf-8") as fh:
        fh.write(content if isinstance(content, str) else str(content))
    return None


@app.api
def list_dir(args):
    """Rust `list_dir`: absolute paths of the *.json files in a directory, sorted.
    A missing directory returns [] (not an error), as in the Rust command."""
    d = _resolve_in_root(args.get("path"))
    if not os.path.exists(d):
        return []
    files = []
    try:
        for name in os.listdir(d):
            full = os.path.join(d, name)
            if os.path.isfile(full) and name.lower().endswith(".json"):
                files.append(full)
    except OSError as exc:
        raise RuntimeError(str(exc))
    files.sort()
    return files


@app.api
def download_to(args):
    """Rust `download_to`: fetch an http(s) URL and write the bytes to a jailed path.
    This is the binary-safe primitive the offline library uses to store thumbnails
    (a JS fetch would be blocked by CORS on /uploads/ thumbs; a native fetch is not).
    Only http/https, and the body is capped at 64 MiB."""
    url = str(args.get("url") or "")
    lower = url.lower()
    if not (lower.startswith("http://") or lower.startswith("https://")):
        raise ValueError("Refused: only http(s) URLs may be downloaded.")
    dest = _resolve_in_root(args.get("path"))  # jail BEFORE the network round-trip

    req = urllib.request.Request(url, headers={"User-Agent": "SnapSmack-GYSS/blink"})
    with urllib.request.urlopen(req, timeout=60) as resp:  # nosec - scheme checked above
        status = getattr(resp, "status", 200)
        if status and int(status) >= 400:
            raise RuntimeError("Download failed: HTTP %s" % status)
        length = resp.headers.get("Content-Length")
        if length is not None:
            try:
                if int(length) > _MAX_DOWNLOAD_BYTES:
                    raise RuntimeError("Refused: response too large (%s bytes)." % length)
            except ValueError:
                pass
        data = resp.read(_MAX_DOWNLOAD_BYTES + 1)
    if len(data) > _MAX_DOWNLOAD_BYTES:
        raise RuntimeError("Refused: response too large (%d bytes)." % len(data))

    parent = os.path.dirname(dest)
    if parent:
        os.makedirs(parent, exist_ok=True)
    with open(dest, "wb") as fh:
        fh.write(data)
    return None


@app.api
def delete_file(args):
    """Rust `delete_file`: remove a jailed file. Missing file is a no-op (idempotent
    prune of an orphaned thumbnail), matching the Rust NotFound handling."""
    p = _resolve_in_root(args.get("path"))
    try:
        os.remove(p)
    except FileNotFoundError:
        return None
    except IsADirectoryError as exc:
        raise RuntimeError(str(exc))
    return None


# ── Shared SQLite catalog (ports catalog_sync / catalog_read) ─────────────────
def _named_rows(payload, key):
    """[{name, description?}, …] -> [(name, description)], dropping empty names.
    Mirrors lib.rs named_rows / snap_library's `if c.get("name")` filter."""
    out = []
    arr = payload.get(key) if isinstance(payload, dict) else None
    if isinstance(arr, list):
        for it in arr:
            if not isinstance(it, dict):
                continue
            name = str(it.get("name") or "")
            if not name:
                continue
            desc = str(it.get("description") or "")
            out.append((name, desc))
    return out


def _scalar_rows(payload, key):
    """['tag', …] -> ['tag', …], dropping blanks (matches `if str(t).strip()`)."""
    out = []
    arr = payload.get(key) if isinstance(payload, dict) else None
    if isinstance(arr, list):
        for it in arr:
            s = str(it or "").strip()
            if s:
                out.append(s)
    return out


@app.api
def catalog_sync(args):
    """Rust `catalog_sync`: wholesale-replace this site's cached vocab inside ONE
    transaction — exactly like snap_library.sync_from_sybu_data. `path` is the
    jailed catalog.sqlite path; `payload` carries site_url / site_mode / categories
    / albums / tags / titles."""
    p = _resolve_in_root(args.get("path"))
    payload = args.get("payload") or {}
    parent = os.path.dirname(p)
    if parent:
        os.makedirs(parent, exist_ok=True)

    site_url = str(payload.get("site_url") or "")
    site_mode = str(payload.get("site_mode") or "").strip().lower()
    cats = _named_rows(payload, "categories")
    albums = _named_rows(payload, "albums")
    tags = _scalar_rows(payload, "tags")
    titles = _scalar_rows(payload, "titles")

    conn = sqlite3.connect(p)
    try:
        conn.executescript(CATALOG_SCHEMA)
        cur = conn.cursor()
        cur.execute("BEGIN")
        cur.execute("DELETE FROM categories")
        cur.execute("DELETE FROM albums")
        cur.execute("DELETE FROM tags")
        cur.execute("DELETE FROM titles")
        cur.executemany(
            "INSERT OR REPLACE INTO categories(name, description) VALUES (?, ?)", cats
        )
        cur.executemany(
            "INSERT OR REPLACE INTO albums(name, description) VALUES (?, ?)", albums
        )
        cur.executemany("INSERT OR REPLACE INTO tags(tag) VALUES (?)", [(t,) for t in tags])
        cur.executemany(
            "INSERT OR REPLACE INTO titles(title) VALUES (?)", [(t,) for t in titles]
        )
        cur.execute(
            "INSERT OR REPLACE INTO meta(key, val) VALUES ('site_url', ?)", (site_url,)
        )
        if site_mode:
            cur.execute(
                "INSERT OR REPLACE INTO meta(key, val) VALUES ('site_mode', ?)", (site_mode,)
            )
        # Local-time "%Y-%m-%d %H:%M:%S" — matches snap_library's time.strftime and
        # the Rust strftime(... ,'localtime').
        cur.execute(
            "INSERT OR REPLACE INTO meta(key, val) VALUES ('synced_at', ?)",
            (time.strftime("%Y-%m-%d %H:%M:%S"),),
        )
        conn.commit()
    finally:
        conn.close()

    return {
        "categories": len(cats),
        "albums": len(albums),
        "tags": len(tags),
        "titles": len(titles),
        "site_mode": site_mode,
    }


@app.api
def catalog_read(args):
    """Rust `catalog_read`: read the shared catalog for a site. Empty lists when no
    catalog exists yet. (Currently unused by the JS — the Rust command was likewise
    registered but not invoked — but reproduced for parity.)"""
    p = _resolve_in_root(args.get("path"))
    empty = {
        "categories": [], "albums": [], "tags": [], "titles": [],
        "site_mode": "", "synced_at": "",
    }
    if not os.path.isfile(p):
        return empty
    conn = sqlite3.connect(p)
    try:
        conn.executescript(CATALOG_SCHEMA)
        cur = conn.cursor()

        def col(sql):
            return [r[0] for r in cur.execute(sql).fetchall()]

        def meta(key):
            row = cur.execute("SELECT val FROM meta WHERE key = ?", (key,)).fetchone()
            return row[0] if row else ""

        return {
            "categories": col("SELECT name FROM categories ORDER BY name COLLATE NOCASE"),
            "albums": col("SELECT name FROM albums ORDER BY name COLLATE NOCASE"),
            "tags": col("SELECT tag FROM tags ORDER BY tag COLLATE NOCASE"),
            "titles": col("SELECT title FROM titles ORDER BY title COLLATE NOCASE"),
            "site_mode": meta("site_mode"),
            "synced_at": meta("synced_at"),
        }
    finally:
        conn.close()


# ── Blink-only: stand in for Tauri's convertFileSrc ───────────────────────────
@app.api
def read_asset(args):
    """Return a jailed image file as a `data:` URL so the webview can render locally
    downloaded thumbnails. Tauri exposed convertFileSrc (an asset:// URL); snap_blink
    only serves files under web/, and thumbnails live under shared_library/, so the
    port hands the bytes back inline instead. Thumbnails are tens of KB, so this is
    cheap. Reuses the same file jail as every other command."""
    p = _resolve_in_root(args.get("path"))
    if not os.path.isfile(p):
        raise FileNotFoundError("no such asset")
    ext = os.path.splitext(p)[1].lower()
    mime = _ASSET_MIME.get(ext, "application/octet-stream")
    with open(p, "rb") as fh:
        raw = fh.read()
    return "data:%s;base64,%s" % (mime, base64.b64encode(raw).decode("ascii"))


# ── Blink-only: the blog API transport ───────────────────────────────────────
@app.api
def http_request(args):
    """Make one HTTP call to the blog's gyss-api.php on behalf of the front-end.

    WHY THIS EXISTS. In the Tauri build, api.js called the blog with a browser
    fetch() — gyss-api.php allows CORS for tauri:// / file:// origins, so the
    webview could reach it directly. The Blink webview's origin is
    http://127.0.0.1:<port>, which gyss-api.php does NOT allow, so a browser fetch
    would be blocked. A backend request is not bound by browser CORS (the same
    reasoning the Rust `download_to` used for thumbnails), so the blog API calls run
    here. No server-side change is needed.

    Returns { status, body } — body is the raw response text, which api.js parses.
    An HTTP error status still returns its JSON body so api.js can surface the
    server's own {ok:false,error} message. Only http(s) is accepted."""
    url = str(args.get("url") or "")
    lower = url.lower()
    if not (lower.startswith("http://") or lower.startswith("https://")):
        raise ValueError("Refused: only http(s) URLs are allowed.")
    method = str(args.get("method") or "GET").upper()
    headers = args.get("headers") or {}
    body = args.get("body")

    data = None
    if body is not None:
        data = body.encode("utf-8") if isinstance(body, str) else json.dumps(body).encode("utf-8")

    req = urllib.request.Request(url, data=data, method=method)
    if isinstance(headers, dict):
        for k, v in headers.items():
            req.add_header(str(k), str(v))
    req.add_header("User-Agent", "SnapSmack-GYSS/blink")

    try:
        with urllib.request.urlopen(req, timeout=120) as resp:  # nosec - scheme checked
            status = int(getattr(resp, "status", 200) or 200)
            text = resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        # gyss-api.php returns a JSON {ok:false,error} body with a 4xx/5xx status;
        # hand it back so the caller sees the real error, not just the code.
        status = int(exc.code)
        try:
            text = exc.read().decode("utf-8", "replace")
        except Exception:
            text = ""
    except urllib.error.URLError as exc:
        raise RuntimeError("Network error: %s" % (getattr(exc, "reason", exc),))

    return {"status": status, "body": text}


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
