#!/usr/bin/env python3
"""
TAKE YOUR SHIT WITH YOU — Linux Chrome/Blink port.

The window is HTML (web/), drawn by Chromium through snap_blink. The WORK — talk
to the site, walk its tables, download every image, build the WordPress courtesy
package, verify the lot — is the ORIGINAL Python, unchanged:

    config.py          settings + the credential vault
    tyswy_client.py    the read-only export API client
    export_engine.py   the actual export (ExportEngine / ExportOptions / report)
    tyswy_core.py      the two pure helpers factored out of main.py

This file is only the bridge: it registers Python functions the page can call,
runs the long export on a background thread, and buffers its progress + log so
the page can poll them (the same shape as main.py's tkinter event pump, minus
tkinter).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import queue
import shutil
import subprocess
import sys
import threading
import traceback
import webbrowser

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", os.path.basename(HERE)))                          # tools/take-your-shit-with-you/
SHARED = os.path.join(TOOL_ROOT, "..", "_shared")          # tools/_shared/
for p in (SHARED, TOOL_ROOT):
    sys.path.insert(0, os.path.abspath(p))

import snap_blink                                          # shared Blink runtime

# The tool's real work — imported, not reimplemented.
import config
from tyswy_core import human_bytes, open_in_file_manager
from export_engine import Cancelled, ExportEngine, ExportOptions
from tyswy_client import TyswyClient, TyswyError

# Same version string the tkinter build reads. main.py owns the canonical
# BUILD_VERSION and bump_version.py rewrites it; the port follows suit so the
# User-Agent and report stamp match whichever front end made the export.
BUILD_VERSION = "0.1.0"

TAGLINE = "Every image. Every scrap of data. Pack it up and leave."
FAREWELL = "No hard feelings. Here's your hat—what's your hurry?"

STAGE_TITLES = {
    'connect':   'PACKING LIST',
    'preflight': 'PACKING LIST',
    'collect':   'PACK YOUR SHIT',
    'assemble':  'PACK YOUR SHIT',
    'media':     'PACK YOUR SHIT',
    'indexes':   'PACK YOUR SHIT',
    'adapters':  'COURTESY COUNTER',
    'verify':    'EVERYTHING ACCOUNTED FOR',
    'finish':    'YOUR SHIT IS PACKED',
}

_SITE_MODES = {
    'photoblog': 'SMACKONEOUT (photoblog)',
    'carousel':  'GRAMOFSMACK (grams)',
    'longform':  'SMACKTALK (longform)',
}


# ---------------------------------------------------------------------------
# One process = one window = one export at a time. Session state lives here,
# mirroring the attributes the tkinter App held on `self`.
# ---------------------------------------------------------------------------
class _Session:
    def __init__(self):
        self.cfg = {}
        self.client = None
        self.preflight = None
        self.report = None
        self.worker = None
        self.cancel_evt = threading.Event()
        self.events = queue.Queue()


S = _Session()

app = snap_blink.App(tool="tyswy", title="TAKE YOUR SHIT WITH YOU",
                     web_dir=os.path.join(HERE, "web"))


# ---------------------------------------------------------------------------
# Event buffer — the worker thread never touches the page. It pushes tagged
# events here; the page drains them by polling poll_events(). This is exactly
# what main.py's queue + self._poll() did, only the UI side is JS on a timer.
# ---------------------------------------------------------------------------
def _emit(kind, **payload):
    payload['type'] = kind
    S.events.put(payload)


# =====================================================================
# Boot / settings
# =====================================================================
@app.api
def load_state():
    """Everything the page needs on open: saved settings and vault status. Also
    initialises the vault and unlocks it with this machine's key if it can, the
    same two lines main.py runs in __init__."""
    config.init_vault()
    if config.vault_enabled() and not config.vault_unlocked():
        config.unlock_with_machine_key()

    S.cfg = config.load()
    return {
        "version": BUILD_VERSION,
        "tagline": TAGLINE,
        "farewell": FAREWELL,
        "settings": {
            "site_url":           S.cfg.get('site_url', ''),
            "api_key":            S.cfg.get('api_key', ''),
            "destination":        S.cfg.get('destination', ''),
            "include_thumbnails": bool(S.cfg.get('include_thumbnails', False)),
            "media_concurrency":  int(S.cfg.get('media_concurrency', 2)),
            "courtesy_wordpress": bool(S.cfg.get('courtesy_wordpress', True)),
            "compress":           bool(S.cfg.get('compress', False)),
        },
        "vault": _vault_status(),
    }


# =====================================================================
# Vault / Key security  (the KeySecurityDialog, ported)
# =====================================================================
def _vault_status():
    return {
        "available": config.vault_available(),
        "enabled":   config.vault_enabled(),
        "unlocked":  config.vault_unlocked(),
        "has_machine_key": config.has_machine_key(),
    }


@app.api
def vault_status():
    return _vault_status()


@app.api
def vault_enable(passphrase, remember_on_this_machine):
    """Turn encryption ON, sealing the stored export key with the passphrase."""
    if not passphrase:
        raise ValueError("A passphrase is required.")
    config.enable_encryption(passphrase, remember_on_this_machine=bool(remember_on_this_machine))
    _emit('log', message='Export key encryption turned ON.', tag='accent')
    return _vault_status()


@app.api
def vault_disable():
    """Turn encryption OFF — rewrites the key as base64. Refuses while locked
    (config.disable_encryption reads the key first and would strand it)."""
    if not config.vault_unlocked():
        raise RuntimeError("Unlock the vault first. Turning encryption off while "
                           "locked would strand your key.")
    config.disable_encryption()
    _emit('log', message='Export key encryption turned OFF.', tag='warn')
    return _vault_status()


@app.api
def vault_change(old, new):
    if not old or not new:
        raise ValueError("Both the current and new passphrase are required.")
    if not config.change_passphrase(old, new):
        raise ValueError("That current passphrase is wrong.")
    _emit('log', message='Encryption passphrase changed.', tag='accent')
    return _vault_status()


@app.api
def vault_unlock(passphrase):
    if not config.unlock(passphrase):
        raise ValueError("That passphrase did not unlock the vault.")
    # Re-read config now that sealed values are readable again.
    S.cfg = config.load()
    return {"vault": _vault_status(), "api_key": S.cfg.get('api_key', '')}


# =====================================================================
# Destination helpers  (Browse… and the free-space probe)
# =====================================================================
@app.api
def disk_free(path):
    """Free bytes on the drive that holds `path` (walking up to the first real
    directory), plus a formatted line and a low-space warning — the l_space
    label logic from main.py, returned as data for the page to show."""
    probe = (path or '').strip()
    while probe and not os.path.isdir(probe):
        parent = os.path.dirname(probe)
        if parent == probe:
            break
        probe = parent
    if not probe or not os.path.isdir(probe):
        return {"known": False, "text": ""}
    try:
        free = shutil.disk_usage(probe).free
    except OSError:
        return {"known": False, "text": ""}
    text = f'{human_bytes(free)} free on that drive.'
    low = free < 2 * 1024 ** 3
    if low:
        text += '  That is not much room for a photo archive.'
    return {"known": True, "free": free, "text": text, "low": low}


@app.api
def pick_folder(start):
    """Native folder picker. tkinter had filedialog.askdirectory(); the Blink
    window has no native dialog, so we shell out to the Linux desktop's own
    picker (zenity, then kdialog). If neither is present the page keeps the
    typed-path field — nothing is blocked.

    TODO(port): depends on zenity or kdialog being installed. When absent the
    user types the destination path by hand (the text field is always live)."""
    start = (start or os.path.expanduser('~')).strip()
    if not os.path.isdir(start):
        start = os.path.expanduser('~')
    for cmd in (
        ['zenity', '--file-selection', '--directory', '--title=Where should your archive go?',
         '--filename=' + (start.rstrip('/') + '/')],
        ['kdialog', '--getexistingdirectory', start],
    ):
        if not shutil.which(cmd[0]):
            continue
        try:
            out = subprocess.run(cmd, capture_output=True, text=True)
        except Exception:
            continue
        if out.returncode == 0:
            chosen = (out.stdout or '').strip()
            if chosen:
                return {"picked": True, "path": chosen}
        return {"picked": False, "path": ""}      # user cancelled the dialog
    return {"picked": False, "path": "", "no_picker": True}


# =====================================================================
# Connect  (SCREEN 1 CONNECT button → preflight → WHAT IS THERE)
# =====================================================================
def _manifest(pre):
    """Turn a preflight response into the WHAT IS THERE panel data. Same set of
    'interesting' types, same order, as main.py._on_connected."""
    name = pre.get('site_name') or pre.get('site_url') or 'that site'
    mode = _SITE_MODES.get(pre.get('site_mode'), pre.get('site_mode') or 'unknown')
    types = pre.get('types') or {}
    interesting = ['images', 'posts', 'pages', 'comments', 'image_comments',
                   'categories', 'albums', 'tags', 'collections',
                   'assets', 'mosaics', 'trigrams', 'blogroll', 'follows']
    rows = []
    for t in interesting:
        info = types.get(t) or {}
        if not info.get('supported'):
            continue
        n = int(info.get('count') or 0)
        if n:
            rows.append({"name": t, "count": n})
    other = sum(int((types.get(t) or {}).get('count') or 0)
                for t in types if t not in interesting)
    return {
        "name": name,
        "mode": mode,
        "site_version": pre.get('site_version'),
        "rows": rows,
        "other": other,
    }


@app.api
def connect(url, key):
    """Ask the site what is there. Runs the network call inline (blink.call is
    async on the page, so this does not freeze the window) and saves the
    settings, exactly like main.py's connect worker + _on_connected."""
    url = (url or '').strip()
    key = (key or '').strip()
    if not url or not key:
        raise ValueError("A site address and an export key are both needed.")
    try:
        client = TyswyClient(url, key, app_version=BUILD_VERSION,
                             allow_http=bool(S.cfg.get('allow_http')))
        pre = client.preflight()
    except TyswyError as e:
        detail = str(e)
        if getattr(e, 'request_id', None):
            detail += f'\n\nRequest id (for your site log): {e.request_id}'
        raise RuntimeError(detail)
    S.client = client
    S.preflight = pre

    # Persist what the user typed (mirrors _save_config).
    _save_settings(url=url, key=key)
    return {"connected": True, "manifest": _manifest(pre)}


def _save_settings(url=None, key=None, destination=None, include_thumbnails=None,
                   media_concurrency=None, courtesy_wordpress=None, compress=None):
    data = dict(S.cfg)
    if url is not None:                 data['site_url'] = url
    if key is not None:                 data['api_key'] = key
    if destination is not None:         data['destination'] = destination
    if include_thumbnails is not None:  data['include_thumbnails'] = bool(include_thumbnails)
    if media_concurrency is not None:   data['media_concurrency'] = int(media_concurrency or 2)
    if courtesy_wordpress is not None:  data['courtesy_wordpress'] = bool(courtesy_wordpress)
    if compress is not None:            data['compress'] = bool(compress)
    config.save(data)                   # RuntimeError if vault is locked
    S.cfg = data


@app.api
def save_settings(settings):
    """Persist the form without connecting. Surfaces the vault-locked case as a
    RuntimeError (the page shows it), never a silent base64 downgrade."""
    _save_settings(
        url=settings.get('site_url', ''),
        key=settings.get('api_key', ''),
        destination=settings.get('destination', ''),
        include_thumbnails=settings.get('include_thumbnails', False),
        media_concurrency=settings.get('media_concurrency', 2),
        courtesy_wordpress=settings.get('courtesy_wordpress', True),
        compress=settings.get('compress', False),
    )
    return {"ok": True}


# =====================================================================
# Export  (PACK MY SHIT → the worker + the progress screen)
# =====================================================================
@app.api
def start_export(url, key, dest, options):
    """Validate the destination, save settings, and launch the export on a
    background thread. Progress + log arrive via poll_events(). One export at a
    time; refuses to start a second over a live one."""
    if S.worker and S.worker.is_alive():
        raise RuntimeError("An export is already running.")
    if S.client is None:
        raise RuntimeError("Connect to the site first.")

    dest = (dest or '').strip()
    if not dest:
        raise ValueError("Choose a folder for the archive first.")
    if not os.path.isdir(dest):
        try:
            os.makedirs(dest, exist_ok=True)
        except OSError as e:
            raise RuntimeError(f"That folder cannot be created:\n{e}")

    _save_settings(
        url=(url or '').strip(), key=(key or '').strip(), destination=dest,
        include_thumbnails=options.get('include_thumbnails', False),
        media_concurrency=options.get('media_concurrency', 2),
        courtesy_wordpress=options.get('courtesy_wordpress', True),
        compress=options.get('compress', False),
    )

    S.cancel_evt = threading.Event()
    S.report = None
    # Drain any stale events so the fresh export starts with a clean log.
    try:
        while True:
            S.events.get_nowait()
    except queue.Empty:
        pass

    opts = ExportOptions(
        include_thumbnails=bool(options.get('include_thumbnails', False)),
        media_concurrency=int(options.get('media_concurrency', 2) or 2),
        courtesy_wordpress=bool(options.get('courtesy_wordpress', True)),
        compress=bool(options.get('compress', False)))

    url2 = (url or '').strip()
    key2 = (key or '').strip()
    allow_http = bool(S.cfg.get('allow_http'))

    _emit('log', message=f'TAKE YOUR SHIT WITH YOU v{BUILD_VERSION}', tag='accent')
    _emit('log', message=f'Destination: {dest}')

    def work():
        try:
            engine = ExportEngine(
                S.client, dest, options=opts, app_version=BUILD_VERSION,
                on_progress=lambda s, m, f: _emit('progress', stage=s, message=m, frac=f),
                on_log=lambda m: _emit('log', message=m),
                cancel_event=S.cancel_evt,
                client_factory=lambda: TyswyClient(
                    url2, key2, app_version=BUILD_VERSION, allow_http=allow_http))
            report = engine.run()
            S.report = report
            _emit('finished', report=report.to_dict())
        except Cancelled:
            _emit('cancelled')
        except Exception as e:                       # noqa: BLE001 - reported
            _emit('failed', error=str(e), traceback=traceback.format_exc(),
                  request_id=getattr(e, 'request_id', None))

    S.worker = threading.Thread(target=work, daemon=True)
    S.worker.start()
    return {"started": True}


@app.api
def poll_events():
    """Drain and return buffered worker events. The page polls this on a timer,
    the same job main.py's after(120, self._poll) did."""
    out = []
    try:
        while True:
            out.append(S.events.get_nowait())
    except queue.Empty:
        pass
    return {"events": out, "running": bool(S.worker and S.worker.is_alive())}


@app.api
def cancel_export():
    """Ask the running export to stop after the current file. Safe: completed,
    verified work is kept and the folder resumes later."""
    S.cancel_evt.set()
    _emit('log', message='Stopping after the current file…', tag='warn')
    return {"stopping": True}


# =====================================================================
# Results  (the done screen actions)
# =====================================================================
@app.api
def open_folder():
    if not (S.report and S.report.root):
        raise RuntimeError("No export folder yet.")
    if not open_in_file_manager(S.report.root):
        return {"opened": False, "path": S.report.root}
    return {"opened": True, "path": S.report.root}


@app.api
def view_report():
    """Open the best available report file in the user's browser — the WordPress
    conversion report, else verification.json, else the export log."""
    if not (S.report and S.report.root):
        raise RuntimeError("No report yet.")
    candidates = [
        os.path.join(S.report.root, 'courtesy', 'wordpress', 'conversion-report.html'),
        os.path.join(S.report.root, 'verification.json'),
        os.path.join(S.report.root, 'logs', 'export.log'),
    ]
    for c in candidates:
        if os.path.exists(c):
            webbrowser.open('file://' + os.path.abspath(c))
            return {"opened": True, "path": c}
    raise RuntimeError("No report file was written.")


@app.api
def compress():
    """Make a .zip of the export folder on a background thread. Result arrives as
    a 'zipped' or 'zip_failed' event through poll_events()."""
    if not (S.report and S.report.root):
        raise RuntimeError("Nothing to compress yet.")
    root = S.report.root

    def work():
        try:
            path = shutil.make_archive(root.rstrip('\\/'), 'zip', root)
            if S.report:
                S.report.zip_path = path
            _emit('zipped', path=path)
        except Exception as e:                       # noqa: BLE001 - reported
            _emit('zip_failed', error=str(e))

    threading.Thread(target=work, daemon=True).start()
    return {"started": True}


@app.api
def delete_incomplete():
    """Spec 13. The page shows the two confirmations; the safety checks live
    here and cannot be skipped: refuses a completed export, and refuses anything
    that does not carry this tool's own .tyswy/state.json — so a mistyped path
    can never take a folder of holiday photos with it."""
    if not (S.report and S.report.root):
        raise RuntimeError("No export to delete.")
    root = S.report.root
    if S.report.complete:
        raise RuntimeError("This export is complete. Completed exports are never "
                           "deleted from here — move or remove the folder yourself.")
    if not os.path.isfile(os.path.join(root, '.tyswy', 'state.json')):
        raise RuntimeError(f"{root} does not look like an export this tool made. "
                           "Refusing to delete it.")
    try:
        shutil.rmtree(root)
    except OSError as e:
        raise RuntimeError(f"Could not delete it:\n{e}")
    S.report = None
    return {"deleted": True}


@app.api
def reset():
    """START ANOTHER — clear the finished report so the page returns to CONNECT."""
    S.report = None
    return {"ok": True}


# =====================================================================
if __name__ == "__main__":
    app.run()
# ===== SNAPSMACK EOF =====
