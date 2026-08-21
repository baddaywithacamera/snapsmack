#!/usr/bin/env python3
"""
OH SNAP! — Linux Chrome/Blink port.

The window is HTML/CSS/JS (reused verbatim from the Tauri build, under web/).
The WORK that used to live in the thin Rust backend (src-tauri/src/lib.rs) is
reproduced here in plain Python and exposed to the page through snap_blink.

Tauri command  ->  @app.api handler (same name, positional args):
    save_project(path, content)                 file I/O — DONE
    load_project(path)                          file I/O — DONE
    export_shareable_package(path, files)       ZIP + lane guard — DONE
    vault_set(account, secret)                  creds store — DONE (see TODO(port))
    vault_get(account)                          creds store — DONE (see TODO(port))
    vault_delete(account)                       creds store — DONE (see TODO(port))

Two Tauri dialog-plugin calls have no stdlib-only Blink equivalent (there is no
native file chooser in this runtime). They are wired to best-effort helpers so no
feature is silently dropped:
    dialog_save(default_name)   -> a path under the shared projects dir
    dialog_open()               -> the most recent project path, or null

HONESTY: OH SNAP is NOT a finished tool. Per project status its AI has no
knowledge of the server's shared assets and its skin-kit contract is
incomplete/unwired. This port faithfully carries ACROSS what exists; it does not
finish the tool. Imports are verified with ast.parse; it has NOT been run on
Linux Chromium from the build box.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import json
import os
import sys
import tempfile
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                       # tools/oh-snap/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")       # tools/_shared/
for _p in (SHARED, TOOL_ROOT):
    sys.path.insert(0, os.path.abspath(_p))

import snap_blink            # shared Blink runtime (stdlib-only)
import snap_home             # shared on-disk layout (auth_dir, home, ...)

app = snap_blink.App(tool="ohsnap", title="OH SNAP!", web_dir=os.path.join(HERE, "web"))


# ── shared paths ─────────────────────────────────────────────────────────────
def _projects_dir():
    """Where .ohsnap project files and exported packages live for this tool.

    The Tauri build let the OS file dialog pick any path; the stdlib Blink runtime
    has no native chooser, so we keep a predictable per-user folder under the
    SnapSmack root instead."""
    d = os.path.join(snap_home.home(), "ohsnap", "projects")
    os.makedirs(d, exist_ok=True)
    return d


def _vault_path():
    """The credential store, kept in the ONE shared auth location so it lives
    alongside every other tool's creds (snap_home contract)."""
    return os.path.join(snap_home.auth_dir(), "ohsnap_vault.json")


def _safe_name(name, fallback="ohsnap-project"):
    keep = "".join(c if (c.isalnum() or c in "._-") else "-" for c in str(name or ""))
    keep = keep.strip("-.") or fallback
    return keep


# ── project file I/O (was Rust save_project / load_project) ──────────────────
@app.api
def save_project(path, content):
    """Atomically write `content` to `path`, keeping a .bak of any prior file.
    Faithful reproduction of the Rust save_project (temp write + fsync + rename)."""
    target = os.path.abspath(path)
    parent = os.path.dirname(target)
    if not parent:
        raise ValueError("Project path has no parent directory")
    os.makedirs(parent, exist_ok=True)
    base = os.path.basename(target) or "ohsnap"
    temp = os.path.join(parent, ".%s.tmp" % base)
    backup = os.path.join(parent, "%s.bak" % base)
    with open(temp, "w", encoding="utf-8") as fh:
        fh.write(content)
        fh.flush()
        os.fsync(fh.fileno())
    if os.path.exists(target):
        try:
            import shutil
            shutil.copy2(target, backup)
        except Exception:
            pass
    os.replace(temp, target)
    return None


@app.api
def load_project(path):
    """Read a project file; fall back to its .bak if the primary read fails.
    Faithful reproduction of the Rust load_project."""
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return fh.read()
    except Exception as primary:
        target = os.path.abspath(path)
        parent = os.path.dirname(target)
        base = os.path.basename(target) or "ohsnap"
        backup = os.path.join(parent, "%s.bak" % base)
        try:
            with open(backup, "r", encoding="utf-8") as fh:
                return fh.read()
        except Exception:
            raise primary


# ── SHAREABLE package export (was Rust export_shareable_package) ─────────────
_FORBIDDEN_EXT = {"php", "phtml", "phar", "exe", "dll", "bat", "cmd", "ps1", "sh"}


@app.api
def export_shareable_package(path, files):
    """Build a deflate ZIP from [{path, content}, ...] with the same path-safety
    and forbidden-file guards the Rust command enforced. Written to a temp file
    first, then atomically moved into place. `files` arrives as a list of dicts."""
    target = os.path.abspath(path)
    parent = os.path.dirname(target)
    if not parent:
        raise ValueError("Package path has no parent directory")
    os.makedirs(parent, exist_ok=True)
    base = os.path.basename(target) or "ohsnap.zip"
    temp = os.path.join(parent, ".%s.tmp" % base)

    ordered = sorted(files or [], key=lambda item: item.get("path", ""))
    with open(temp, "wb") as raw:
        with zipfile.ZipFile(raw, "w", compression=zipfile.ZIP_DEFLATED) as archive:
            for item in ordered:
                normalized = str(item.get("path", "")).replace("\\", "/")
                if (not normalized or normalized.startswith("/")
                        or "../" in normalized or ":" in normalized):
                    raise ValueError("Unsafe package path: %s" % item.get("path"))
                ext = os.path.splitext(normalized)[1].lstrip(".").lower()
                if ext in _FORBIDDEN_EXT or normalized.lower() == ".htaccess":
                    raise ValueError("Forbidden SHAREABLE file: %s" % normalized)
                archive.writestr(normalized, item.get("content", ""))
        raw.flush()
        os.fsync(raw.fileno())
    os.replace(temp, target)
    return None


# ── credential vault (was Rust vault_set / vault_get / vault_delete) ─────────
# TODO(port): the Tauri build stored these in the OS keyring (machine-bound,
# encrypted). The Blink runtime is stdlib-only, so this is a plaintext JSON map
# in the shared auth dir (chmod 600). The faithful upgrade is to adopt the shared
# snap_vault module (scrypt + Fernet), but that pulls in the `cryptography` (and
# optional `keyring`) pip deps, which the port's stdlib-only rule forbids adding
# here. Wired and working as storage; NOT encrypted at rest.
def _vault_load():
    try:
        with open(_vault_path(), "r", encoding="utf-8") as fh:
            data = json.load(fh)
        return data if isinstance(data, dict) else {}
    except FileNotFoundError:
        return {}
    except Exception:
        return {}


def _vault_write(data):
    path = _vault_path()
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(data, fh, indent=2)
    os.replace(tmp, path)
    try:
        os.chmod(path, 0o600)
    except Exception:
        pass


@app.api
def vault_set(account, secret):
    data = _vault_load()
    data[str(account)] = secret
    _vault_write(data)
    return None


@app.api
def vault_get(account):
    return _vault_load().get(str(account))


@app.api
def vault_delete(account):
    data = _vault_load()
    if str(account) in data:
        del data[str(account)]
        _vault_write(data)
    return None


# ── file-dialog shims (were Tauri dialog-plugin calls) ───────────────────────
@app.api
def dialog_save(default_name):
    """Return a path to save to. TODO(port): the Tauri build showed a native OS
    save dialog; the stdlib Blink runtime has no file chooser, so we resolve a
    predictable path under the shared projects dir. The name is sanitised."""
    return os.path.join(_projects_dir(), _safe_name(default_name))


@app.api
def dialog_open():
    """Return the most recently modified .ohsnap project path, or None.
    TODO(port): no native open dialog in the Blink runtime — this cannot let the
    user browse to an arbitrary file the way the Tauri open dialog did. A future
    revision should surface a project list in the UI and pass the chosen path."""
    d = _projects_dir()
    candidates = [os.path.join(d, f) for f in os.listdir(d) if f.lower().endswith(".ohsnap")]
    if not candidates:
        return None
    return max(candidates, key=os.path.getmtime)


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
