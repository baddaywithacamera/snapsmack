#!/usr/bin/env python3
"""
THE HUB — Linux Chrome/Blink port.

The window is HTML/CSS/JS drawn by Chromium; the WORK is the original Python. This
file replaces the tkinter class in ../main.py with a set of @app.api handlers that
the page reaches through blink.call(). Every handler either returns JSON-serialisable
data or raises (the page shows the error in its log). The credential / discovery /
profile / prompt logic is unchanged — it still goes through the shared library, and
the tool-specific pieces (roster, launch, prompt fetch/push) live in hub_core.py.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                       # tools/hub/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")       # tools/_shared/
for p in (SHARED, TOOL_ROOT, HERE):
    ap = os.path.abspath(p)
    if ap not in sys.path:
        sys.path.insert(0, ap)

import snap_blink

# Shared library. If these are missing the page still opens and says so, matching
# the Windows Hub's "Shared modules unavailable" banner.
_SHARED_OK = True
_SHARED_ERR = ""
try:
    import snap_creds
    import snap_profiles
    import snap_discovery
    import snap_prompts
    import snap_prompt_sync
    import hub_core
except Exception as _e:  # pragma: no cover - depends on the box
    _SHARED_OK = False
    _SHARED_ERR = str(_e)

# The five shared credentials the Hub sets up once, in display order.
CRED_KEYS = ["hub_url", "hub_key", "gemini_api_key", "google_credentials", "drive_folder_id"]

app = snap_blink.App(tool="hub", title="THE HUB", web_dir=os.path.join(HERE, "web"))


# ── helpers shared by the handlers ───────────────────────────────────────────
def _read_creds():
    out = {}
    for key in CRED_KEYS:
        try:
            out[key] = snap_creds.get(key, "")
        except Exception:
            out[key] = ""
    return out


def _list_profiles():
    try:
        return snap_profiles.list_profiles()
    except Exception:
        return []


def _prompt_profiles():
    """Profiles that have a site_url, keyed by their prompt-sync site key."""
    profs = [p for p in _list_profiles() if p.get("site_url")]
    return {snap_prompt_sync.site_key(p): p for p in profs}


def _prompt_sites():
    return sorted(_prompt_profiles().keys())


def _pool():
    try:
        return snap_prompts.load()
    except Exception:
        return {}


# ── everything the page needs on open ────────────────────────────────────────
@app.api
def load_state():
    """One call the page makes on boot. Mirrors the Windows Hub's initial paint:
    the launch roster, the saved shared credentials, the shared profiles list, and
    the prompt-sync site list + local pool."""
    if not _SHARED_OK:
        return {"shared_ok": False, "shared_err": _SHARED_ERR,
                "version": hub_core.BUILD_VERSION if "hub_core" in globals() else ""}
    return {
        "shared_ok": True,
        "version": hub_core.BUILD_VERSION,
        "roster": hub_core.roster(),
        "creds": _read_creds(),
        "profiles": _profiles_for_page(),
        "prompt_sites": _prompt_sites(),
        "pool": _pool(),
    }


def _profiles_for_page():
    out = []
    for p in _list_profiles():
        out.append({"name": p.get("name", ""), "site_url": p.get("site_url", "")})
    return out


# ── LAUNCH ───────────────────────────────────────────────────────────────────
@app.api
def launch_tool(path):
    """Launch a tool's Linux run.sh. Raises with the OS error on failure."""
    ok, err = hub_core.launch(path)
    if not ok:
        raise RuntimeError(err)
    return {"ok": True}


# ── HUB SETUP: save + discover ───────────────────────────────────────────────
@app.api
def save_creds(creds):
    """Persist typed secrets to the shared vault. `creds` is a {key: value} object
    from the form; only non-empty values are written. Returns how many were saved."""
    n = 0
    for key in CRED_KEYS:
        val = str((creds or {}).get(key, "") or "").strip()
        if val:
            snap_creds.set(key, val)
            n += 1
    return {"ok": True, "saved": n, "creds": _read_creds()}


@app.api
def discover(creds):
    """Discover the fleet from the hub and fill the shared stores. Saves whatever
    is typed FIRST (so a user who never clicks Save never loses their Gemini/Drive
    keys), then pulls. Returns the reloaded creds + profiles + prompt sites so the
    page can refresh in one round-trip. Raises on failure (page shows a friendly
    error)."""
    creds = creds or {}
    hub_url = str(creds.get("hub_url", "") or "").strip()
    hub_key = str(creds.get("hub_key", "") or "").strip()
    if not hub_url:
        raise ValueError("Enter your hub site URL first.")
    # Save typed creds first (matches the Windows _on_discover order).
    try:
        save_creds(creds)
    except Exception:
        pass
    summary = snap_discovery.discover_and_save(hub_url, api_key=hub_key)
    return {
        "ok": True,
        "count": summary.get("count", 0),
        "sites": summary.get("sites", []),
        "vault_keys": summary.get("vault_keys", []),
        "creds": _read_creds(),
        "profiles": _profiles_for_page(),
        "prompt_sites": _prompt_sites(),
        "pool": _pool(),
    }


# ── field TEST buttons ───────────────────────────────────────────────────────
@app.api
def test_hub(url, key):
    """Test the hub URL + key by discovering it. Returns {ok, msg}."""
    url = (url or "").strip()
    key = (key or "").strip()
    if not url:
        return {"ok": False, "msg": "enter the hub URL first"}
    try:
        hub_info, spokes = snap_discovery.discover(url, api_key=key)
        n = len(spokes or [])
        return {"ok": True, "msg": "connected — %d site(s)" % n}
    except Exception as e:
        return {"ok": False, "msg": str(e)[:70]}


@app.api
def test_gemini(key):
    """Test a Gemini API key against the models endpoint. Returns {ok, msg}."""
    key = (key or "").strip()
    if not key:
        return {"ok": False, "msg": "enter a key first"}
    try:
        import requests
        r = requests.get("https://generativelanguage.googleapis.com/v1beta/models",
                         params={"key": key}, timeout=15)
        ok = r.status_code == 200
        return {"ok": ok, "msg": "key valid" if ok else "rejected (HTTP %d)" % r.status_code}
    except Exception as e:
        return {"ok": False, "msg": str(e)[:70]}


@app.api
def test_drive(path, folder):
    """Sanity-check a Google Drive credentials JSON + backup folder id. Returns
    {ok, msg}. (SUYB proves the live link; this only validates the file shape.)"""
    import json
    path = (path or "").strip()
    folder = (folder or "").strip()
    if not path:
        return {"ok": False, "msg": "choose a credentials JSON first"}
    if not os.path.isfile(path):
        return {"ok": False, "msg": "file not found"}
    try:
        data = json.load(open(path, encoding="utf-8"))
    except Exception:
        return {"ok": False, "msg": "not valid JSON"}
    looks_ok = isinstance(data, dict) and (
        "installed" in data or "web" in data or "client_email" in data)
    if not looks_ok:
        return {"ok": False, "msg": "doesn't look like Google creds"}
    if not folder:
        return {"ok": True, "msg": "creds look valid — add a backup folder ID"}
    return {"ok": True, "msg": "creds + folder set — SUYB proves the live link"}


# ── PROMPT SYNC ──────────────────────────────────────────────────────────────
@app.api
def load_prompt(site_key):
    """The locally-pooled prompt for one site (what the editor shows on select)."""
    return {"prompt": str(_pool().get(site_key, ""))}


@app.api
def copy_from(src_key):
    """Return another blog's pooled prompt, to seed the editor. Never touches the
    site — the user reviews then pushes."""
    return {"prompt": str(_pool().get(src_key, ""))}


@app.api
def pull_all():
    """Pull every blog's live prompt into the shared pool. Non-destructive: a blog
    whose live prompt differs from the local pool is reported, never overwritten.
    Returns the sync report + the refreshed site list and pool."""
    profs = list(_prompt_profiles().values())
    if not profs:
        raise RuntimeError("Run Discover Fleet first.")
    report = snap_prompt_sync.pull(profs, hub_core.prompt_fetch)
    return {
        "ok": True,
        "report": report,
        "prompt_sites": _prompt_sites(),
        "pool": _pool(),
    }


@app.api
def pull_one(site_key):
    """Fetch ONE site's live prompt and return it (not saved to the pool until the
    user pushes/accepts). Raises on fetch failure."""
    prof = _prompt_profiles().get(site_key)
    if not prof:
        raise RuntimeError("Choose a site first (Discover Fleet fills the list).")
    remote = hub_core.prompt_fetch(prof)
    return {"ok": True, "prompt": str(remote or "")}


@app.api
def push_one(site_key, text):
    """Push the editor's prompt to one blog AND save it to the shared pool. An empty
    prompt resets that blog to its built-in default. Returns {ok, pushed}."""
    prof = _prompt_profiles().get(site_key)
    if not prof:
        raise RuntimeError("Choose a site first (Discover Fleet fills the list).")
    ok = snap_prompt_sync.push(prof, text or "", hub_core.prompt_push)
    return {"ok": True, "pushed": bool(ok), "pool": _pool()}


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
