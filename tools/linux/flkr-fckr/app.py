#!/usr/bin/env python3
"""
FLKR FCKR — Linux Chrome/Blink port.

The WINDOW is HTML/CSS/JS drawn by Chromium (web/); the WORK is the original
FLKR FCKR Python, untouched. This file is only the bridge: it imports the shared
snap_blink runtime and the tkinter-free orchestration layer (flkrfckr_core), then
exposes each user action as a blink.call handler. No tkinter is imported anywhere
in this port.

Data safety (unchanged): the importer attaches comments to the image id and keeps
GPS/EXIF on purpose. Nothing here strips location or "fixes" metadata.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                       # tools/flkr-fckr/
SHARED = os.path.join(TOOL_ROOT, '..', '_shared')       # tools/_shared/
for p in (SHARED, TOOL_ROOT):
    ap = os.path.abspath(p)
    if ap not in sys.path:
        sys.path.insert(0, ap)

import snap_blink                     # noqa: E402  shared Blink runtime
import flkrfckr_core as core          # noqa: E402  tkinter-free work layer

app = snap_blink.App(tool="flkrfckr", title="FLKR FCKR",
                     web_dir=os.path.join(HERE, "web"))

# One live session drives everything, exactly like the single tkinter window.
SESSION = core.Session()


# ── page bootstrap ──────────────────────────────────────────────────────────
@app.api
def load_state():
    """Everything the page needs on open: settings, vault status (after a silent
    machine-key unlock attempt), and any interrupted run to offer resuming."""
    SESSION.vault_try_machine_key()
    return {
        "settings": SESSION.get_settings(),
        "vault": SESSION.vault_status(),
        "resume": SESSION.check_resume(),
        "version": core.BUILD_VERSION,
    }


@app.api
def poll_events():
    """Drain queued log/progress events. The page calls this on a short timer —
    the web stand-in for the tkinter after()/queue poller."""
    return SESSION.poll_events()


# ── settings / connection ───────────────────────────────────────────────────
@app.api
def save_settings(settings):
    SESSION.save_settings(settings or {})
    return {"ok": True}


@app.api
def test_connection(url, key):
    return SESSION.test_connection(url, key)


@app.api
def pick_folder():
    """Native folder picker (zenity/kdialog) when available; None otherwise."""
    return {"path": SESSION.pick_folder()}


# ── export parsing / grid ───────────────────────────────────────────────────
@app.api
def load_export(folder):
    return SESSION.load_export(folder)


@app.api
def toggle_exclude(flickr_id):
    return SESSION.toggle_exclude(flickr_id)


@app.api
def summary(flt, album_id):
    return SESSION.summary(flt, album_id)


@app.api
def thumbnail(flickr_id):
    """On-demand square thumbnail as a data: URI (or None). Lazy-loaded per tile."""
    return {"data": SESSION.thumbnail(flickr_id)}


# ── import lifecycle ────────────────────────────────────────────────────────
@app.api
def preflight_import(url, key):
    return SESSION.preflight_import(url, key)


@app.api
def authorize_import(url, key, username, password, totp_code):
    return SESSION.authorize_import(url, key, username, password, totp_code)


@app.api
def start_import(flt, album_id):
    return SESSION.start_import(flt, album_id)


@app.api
def pause_import():
    return SESSION.pause_import()


@app.api
def resume_import():
    return SESSION.resume_import()


@app.api
def stop_import():
    return SESSION.stop_import()


# ── resume-from-checkpoint ──────────────────────────────────────────────────
@app.api
def resume_accept():
    return SESSION.resume_accept()


@app.api
def resume_decline():
    return SESSION.resume_decline()


# ── vault / key security ────────────────────────────────────────────────────
@app.api
def vault_status():
    return SESSION.vault_status()


@app.api
def vault_unlock(passphrase):
    return {"ok": SESSION.vault_unlock(passphrase)}


@app.api
def vault_enable(passphrase, remember):
    SESSION.vault_enable(passphrase, bool(remember))
    return {"ok": True, "vault": SESSION.vault_status()}


@app.api
def vault_disable():
    SESSION.vault_disable()
    return {"ok": True, "vault": SESSION.vault_status()}


@app.api
def vault_rekey(old, new):
    return {"ok": SESSION.vault_rekey(old, new), "vault": SESSION.vault_status()}


# ── misc ────────────────────────────────────────────────────────────────────
@app.api
def open_logs():
    return SESSION.open_logs()


if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
