"""
THE HUB — tool-specific pure logic, factored out of the tkinter main.py for reuse
by the Linux Chrome/Blink port (and, in principle, by the Windows UI too).

Nothing here draws a window. Every function returns data or raises. The heavy
lifting (talking to the hub, writing the shared stores) still lives in the shared
library — snap_discovery / snap_creds / snap_profiles / snap_prompts /
snap_prompt_sync — exactly as the Windows Hub used it. This file only holds the
pieces that were tangled inside the tkinter class: the launch roster, the exe/
launcher finder, and the per-site GYSS-key + prompt fetch/push helpers.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import glob
import os
import subprocess
import sys

# Shared library is put on sys.path by app.py / run.sh before this imports.
import snap_discovery
import snap_prompt_sync  # noqa: F401  (re-exported convenience for callers)

BUILD_VERSION = "0.1.11"


# ── where the fleet lives on disk ────────────────────────────────────────────
def shared_root():
    """The SnapSmack shared root. Honours SNAPSMACK_HOME (thumb-drive portable);
    on Linux snap_blink defaults SNAPSMACK_HOME to ~/snapsmack when unset.

    This root is ALSO the GYSS file-jail (SECAUDIT 039): a compromised GYSS webview
    may write anywhere under it, so launch targets inside it must be EXACT paths,
    never wildcards (SECAUDIT 044)."""
    env = (os.environ.get("SNAPSMACK_HOME") or "").strip()
    if env:
        return os.path.abspath(env)
    # Match snap_blink's Linux default so the Hub and the tools agree on one root.
    if not sys.platform.startswith("win"):
        return os.path.abspath(os.path.expanduser("~/snapsmack"))
    return os.path.abspath(r"C:\snapsmack")


# The tools the Hub fronts. Same names + one-line subtitles as the Windows roster.
#
# TODO(port): the Windows Hub launched each tool's versioned Windows .exe
# (e.g. sybu/sybu.exe). A .exe has no meaning on Linux, so every tool in this
# family is being ported to its own linux/run.sh launcher. Here we point each
# roster entry at "<root>/<key>/linux/run.sh" — the Linux equivalent of that
# tool's exe. A tool whose Linux port does not exist yet simply shows as
# "not installed" until its run.sh lands under the shared root, exactly as a
# missing .exe did on Windows. All paths are EXACT and inside the shared root:
# no wildcards, so the SECAUDIT 044 rule still holds.
#   (name, subtitle, tool-key-under-root)
_TOOLS = [
    ("SMACK YOUR BATCH UP",  "batch poster",          "sybu"),
    ("GET YOUR SHIT SORTED", "offline sorter",        "gyss"),
    ("COLD SNAP",            "offline poster",        "coldsnap"),
    ("SMACK UP YOUR BACKUP", "backup",                "suyb"),
    ("OH SNAP",              "skin designer",         "ohsnap"),
    ("SMACK YOUR MOUTH",     "comments: mod + reply", "smack-your-mouth"),
    ("SHOTS FIRED",          "schedule board",        "shots-fired"),
    ("CRONOMETER",           "fleet cron health",     "cronometer"),
]


def roster():
    """The launch roster as plain dicts for the page.

    Each entry: {name, sub, key, path, available}. `path` is the tool's Linux
    launcher (run.sh) under the shared root; `available` is whether that file
    exists right now. The page renders an enabled button when available, a
    disabled "not installed" cell otherwise — same behaviour as the Windows grid.
    """
    root = shared_root()
    out = []
    for name, sub, key in _TOOLS:
        candidates = [
            os.path.join(root, key, "linux", "run.sh"),  # installed layout
            os.path.join(root, key, "run.sh"),           # flat fallback
        ]
        found = find_launcher(candidates)
        out.append({
            "name": name,
            "sub": sub,
            "key": key,
            "path": found or "",
            "available": bool(found),
        })
    return out


def find_launcher(paths):
    """First existing launcher among the candidates.

    SECURITY (SECAUDIT 044): a WILDCARD candidate is REFUSED when it resolves
    inside the shared root, because that tree is the GYSS write-jail — a
    compromised webview could plant an arbitrary launcher there for the Hub to
    run. Wildcards are honoured only for out-of-jail paths; inside the jail only
    exact paths are eligible. (Nothing globs today; kept as defence in depth,
    carried over verbatim from the Windows _find_exe.)"""
    root = shared_root()
    for p in paths:
        if any(ch in p for ch in "*?["):
            base = os.path.dirname(os.path.abspath(p))
            if base == root or base.startswith(root + os.sep):
                continue  # never glob inside the GYSS-writable shared root
            matches = [m for m in glob.glob(p) if os.path.isfile(m)]
            if matches:
                return max(matches, key=os.path.getmtime)
        elif os.path.isfile(p):
            return p
    return None


def launch(path):
    """Start a tool's Linux launcher. Returns (ok, error_message).

    TODO(port): the Windows Hub did subprocess.Popen([exe]). On Linux the target
    is a run.sh, which may or may not carry the +x bit after a git checkout, so we
    invoke it through bash explicitly rather than relying on the executable bit."""
    if not path or not os.path.isfile(path):
        return False, "launcher not found: %s" % path
    try:
        subprocess.Popen(["bash", path], cwd=os.path.dirname(path))
        return True, ""
    except Exception as e:  # pragma: no cover - depends on the box
        return False, str(e)


# ── per-site GYSS key + prompt fetch/push (ported from the tkinter class) ─────
# gyss/prompt requires a key_type 'gyss' Bearer key. The stored api_key is the
# sybu posting key, so mint a gyss key from the full hub->spoke key
# (extras.api_key_local). Cached per process in _GYSS_KEYS, matching the Windows
# self._gyss_keys per-run cache.
_GYSS_KEYS = {}


def gyss_key_for(profile):
    """A gyss-type Bearer key for this site, minted from the hub->spoke full key
    and cached for the life of the process. "" if the site can't mint one."""
    site = (profile.get("site_url") or "").rstrip("/")
    if _GYSS_KEYS.get(site):
        return _GYSS_KEYS[site]
    akl = ((profile.get("extras") or {}).get("api_key_local") or "").strip()
    key = ""
    if akl:
        try:
            key = snap_discovery._provision_spoke_key(site, akl, "gyss")
        except Exception:
            key = ""
    _GYSS_KEYS[site] = key
    return key


def prompt_fetch(profile):
    """Fetch a blog's live one-call AI prompt (GET gyss/prompt). Raises on error."""
    import requests
    site = (profile.get("site_url") or "").rstrip("/")
    key = gyss_key_for(profile)
    if not key:
        raise RuntimeError("no GYSS key for this site — run Discover Fleet")
    r = requests.get(site + "/api.php", params={"route": "gyss/prompt"},
                     headers={"Authorization": "Bearer " + key,
                              "User-Agent": "SnapSmackHub/1.0"}, timeout=25)
    if r.status_code != 200:
        raise RuntimeError("HTTP %s" % r.status_code)
    data = r.json() if r.content else {}
    if not data.get("ok", False):
        raise RuntimeError(data.get("error", "unexpected response"))
    return data.get("prompt", "")


def prompt_push(profile, text):
    """Push a prompt to a blog (POST gyss/prompt). Returns True on success."""
    import requests
    site = (profile.get("site_url") or "").rstrip("/")
    key = gyss_key_for(profile)
    if not key:
        return False
    r = requests.post(site + "/api.php", params={"route": "gyss/prompt"},
                      json={"prompt": text},
                      headers={"Authorization": "Bearer " + key,
                               "User-Agent": "SnapSmackHub/1.0"}, timeout=25)
    if r.status_code != 200:
        return False
    try:
        return bool((r.json() or {}).get("ok", False))
    except Exception:
        return False
# ===== SNAPSMACK EOF =====
