"""
Smack Your Batch Up — sybu_core.py

Headless engine for the Linux Chrome/Blink port. This holds ALL of SYBU's real
work — connect, scan, load a manifest, Gemini enrich, post (solo + gram), audit,
repair (rename / re-enrich / backfill), advanced visual match, and site profiles —
factored out of the tkinter window in main.py so the web port (linux/app.py) can
call it with no tkinter, no widgets, no threads owned by Tk.

It REUSES the tool's proven modules unchanged:
    config (cfg_module)  poster  gemini  drive  manifest_parser  profile_manager
    recovery  matcher  snap_stepup
Nothing here re-implements a network / file / Gemini / Drive call — it wires the
same functions the desktop app called, keeping the shared-library contract
(config.py / profile_manager.py already route creds/config/profiles/prompts
through snap_home / snap_creds / snap_profiles / snap_prompts).

State model
    The tkinter App kept live state inside widgets (the queue rows, the connected
    client, the drive service). The web page is stateless between calls, so that
    state lives here in one module-level Engine singleton. The page reads/writes it
    through the blink.call handlers in app.py.

Long jobs
    Enrich / post / audit / rename / re-enrich / match / drive-auth run in a
    background thread and stream per-item events into an Op record. The page polls
    op_poll(key, seen) and applies the new events to the table — the same shape the
    desktop app's _msg_queue / _poll_queue loop used.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import base64
import io
import os
import re
import sys
import threading
import time
from dataclasses import asdict
from typing import Dict, List, Optional

# The tool's own modules live one directory up (tools/sybu). app.py puts that on
# sys.path; guard here too so sybu_core is importable on its own for tests.
_TOOL_ROOT = os.path.dirname(os.path.abspath(__file__))
if _TOOL_ROOT not in sys.path:
    sys.path.insert(0, _TOOL_ROOT)
_SHARED = os.path.join(_TOOL_ROOT, "..", "_shared")
if os.path.isdir(_SHARED) and os.path.abspath(_SHARED) not in sys.path:
    sys.path.insert(0, os.path.abspath(_SHARED))

import config as cfg_module
import manifest_parser
import poster as poster_module
import profile_manager
import recovery as recovery_module
from manifest_parser import ManifestEntry
from poster import SnapSmackClient, WrongSiteModeError

# gemini / drive / matcher pull heavy optional deps (google-generativeai, the
# Google API client, opencv). Import them lazily so the app still opens and the
# POST tab works even when those libraries are absent — exactly what the desktop
# tool does via gemini_module.is_available().
gemini_module = None
drive_module = None


def _gemini():
    global gemini_module
    if gemini_module is None:
        import gemini as _g
        gemini_module = _g
    return gemini_module


def _drive():
    global drive_module
    if drive_module is None:
        import drive as _d
        drive_module = _d
    return drive_module


# Shared transport guard — same warn-and-confirm the desktop app uses so a Bearer
# API key is never sent over plain http:// without an explicit OK.
try:
    from snap_stepup import insecure_transport_reason
except Exception:                      # helper absent from an old build
    def insecure_transport_reason(base_url):
        u = str(base_url).strip().lower()
        if u.startswith('https://'):
            return ''
        for host in ('http://localhost', 'http://127.', 'http://[::1]'):
            if u.startswith(host):
                return ''
        return ('This site URL is not https://, so your API key would be sent '
                'across the network in the clear.')


IMAGE_EXTS = {'.jpg', '.jpeg', '.png', '.webp'}

# Orientation: the queue stores the API value; the UI shows the friendly label.
ORIENT_DISPLAY_TO_API = {
    'auto': 'auto', 'landscape': '0', 'portrait': '1', 'square': '2',
    '0': '0', '1': '1', '2': '2', '': 'auto',
}
ORIENT_API_TO_DISPLAY = {'auto': 'Auto', '0': 'Landscape', '1': 'Portrait', '2': 'Square'}


def _orient_to_api(value: str) -> str:
    return ORIENT_DISPLAY_TO_API.get((value or 'auto').strip().lower(), 'auto')


# ---------------------------------------------------------------------------
# Background op record — one active long job per key
# ---------------------------------------------------------------------------

class Op:
    """One background job. Events are appended as work progresses; the page polls
    for the slice it hasn't seen yet, so a batch shows row-by-row like the
    desktop tool's progress loop."""

    def __init__(self, key: str):
        self.key = key
        self.running = True
        self.error = ''
        self.result = None
        self.events: List[dict] = []
        self.cancel = threading.Event()
        self.thread: Optional[threading.Thread] = None
        self.active_conn = None     # gram GramConnection, so cancel can close it


# ---------------------------------------------------------------------------
# The engine — the whole tool's live state
# ---------------------------------------------------------------------------

class Engine:

    def __init__(self):
        self.config: dict = cfg_module.load()
        self.prompts: dict = cfg_module.load_prompts()

        self.client: Optional[SnapSmackClient] = None
        self.site_data = None
        self.drive_service = None

        self.image_folder: str = ''
        self.entries: List[ManifestEntry] = []
        self.rowstate: List[dict] = []   # index-aligned: {selected, status, message}

        # Gemini-failure latch. Keyed like the desktop app (folder|file); while a
        # key is present the matching image is blocked from posting until the user
        # re-enriches or explicitly clears the warning.
        self.enrichment_warnings: Dict[str, str] = {}

        self.recovery: Optional[recovery_module.RecoveryStore] = None

        self.audit_summary: Optional[dict] = None
        self.audit_data: Optional[list] = None

        # Advanced-match review rows: id -> {record, result}
        self.match_rows: Dict[int, dict] = {}
        self._match_next_id = 1

        self.ops: Dict[str, Op] = {}
        self._lock = threading.Lock()

    # ── generic op plumbing ─────────────────────────────────────────────────
    def start_op(self, key: str, target) -> dict:
        """Run target(op) in a daemon thread. Refuses if the same op is running."""
        cur = self.ops.get(key)
        if cur and cur.running:
            raise RuntimeError(f"{key} is already running.")
        op = Op(key)
        self.ops[key] = op

        def _run():
            try:
                target(op)
            except Exception as exc:            # surface, never crash the server
                op.error = f"{type(exc).__name__}: {exc}"
                op.events.append({'type': 'error', 'message': op.error})
            finally:
                op.running = False
                op.events.append({'type': 'done'})

        op.thread = threading.Thread(target=_run, daemon=True)
        op.thread.start()
        return {'started': True, 'key': key}

    def op_poll(self, key: str, seen: int = 0) -> dict:
        op = self.ops.get(key)
        if op is None:
            return {'exists': False, 'running': False, 'events': [], 'total_seen': seen}
        seen = max(0, int(seen or 0))
        new = op.events[seen:]
        return {
            'exists': True,
            'running': op.running,
            'error': op.error,
            'result': op.result,
            'events': new,
            'total_seen': seen + len(new),
        }

    def cancel_op(self, key: str) -> dict:
        op = self.ops.get(key)
        if op and op.running:
            op.cancel.set()
            conn = op.active_conn
            if conn is not None:
                try:
                    threading.Thread(target=conn.session.close, daemon=True).start()
                except Exception:
                    pass
            return {'cancelling': True}
        return {'cancelling': False}

    # ── serialisation ───────────────────────────────────────────────────────
    def _serialize_entry(self, i: int) -> dict:
        e = self.entries[i]
        rs = self.rowstate[i]
        d = {
            'index': i,
            'file': e.file,
            'title': e.title,
            'tags': e.tags,
            'category': e.category,
            'album': e.album,
            'orientation': _orient_to_api(e.orientation),
            'orientation_label': ORIENT_API_TO_DISPLAY.get(_orient_to_api(e.orientation), 'Auto'),
            'colors': e.colors,
            'caption': e.caption,
            'alt': getattr(e, 'alt', ''),
            'color_mode': getattr(e, 'color_mode', ''),
            'selected': rs['selected'],
            'status': rs['status'],
            'message': rs['message'],
            'warning': self._warn_key(e) in self.enrichment_warnings,
        }
        return d

    def serialize_queue(self) -> dict:
        rows = [self._serialize_entry(i) for i in range(len(self.entries))]
        return {
            'image_folder': self.image_folder,
            'rows': rows,
            'count': len(rows),
            'selected': sum(1 for r in self.rowstate if r['selected']),
            'warnings': len(self.enrichment_warnings),
            'failed': sum(1 for r in self.rowstate if r['status'] == 'error'),
        }

    # ── enrichment-warning latch ────────────────────────────────────────────
    def _warn_key(self, entry) -> str:
        folder = os.path.normcase(os.path.abspath(self.image_folder or ''))
        return f"{folder}|{(entry.file or '').lower()}"

    # ── connection status for the LED bar ───────────────────────────────────
    def connection_state(self) -> dict:
        mode = ''
        if self.site_data is not None:
            mode = (getattr(self.site_data, 'site_mode', '') or '').strip().lower()
        return {
            'connected': self.client is not None and self.site_data is not None,
            'site_mode': mode,
            'mode_tab': {'photoblog': 'solo', 'carousel': 'gram'}.get(mode, ''),
            'drive': self.drive_service is not None,
            'gemini_key': bool((self.config.get('gemini_api_key') or '').strip()),
            'base_url': getattr(self.client, 'base_url', '') if self.client else '',
        }

    def categories(self) -> List[str]:
        return sorted(self.site_data._cat_display.values()) if self.site_data else []

    def albums(self) -> List[str]:
        return sorted(self.site_data._album_display.values()) if self.site_data else []

    # ── config field helpers ────────────────────────────────────────────────
    def config_fields(self) -> dict:
        """The subset of config the POST-tab form binds to (secrets included so the
        page can prefill; this is a localhost-only window)."""
        c = self.config
        return {
            'url': c.get('url', ''),
            'api_key': c.get('api_key', ''),
            'remember': bool(c.get('remember', False)),
            'default_category': c.get('default_category', ''),
            'default_album': c.get('default_album', ''),
            'default_orientation': c.get('default_orientation', 'auto') or 'auto',
            'last_image_folder': c.get('last_image_folder', ''),
            'last_manifest_file': c.get('last_manifest_file', ''),
            'google_credentials': c.get('google_credentials', ''),
            'drive_folder_id': c.get('drive_folder_id', ''),
            'drive_enabled': bool(c.get('drive_enabled', True)),
            'gemini_api_key': c.get('gemini_api_key', ''),
            'gemini_last_prompt': c.get('gemini_last_prompt', ''),
            'copyright_text': c.get('copyright_text', ''),
        }

    def save_config(self, fields: dict) -> None:
        """Persist POST-tab fields through config.save (which also pushes shared
        secrets and preserves [ui] keys)."""
        self.config.update({
            'url': fields.get('url', self.config.get('url', '')),
            'api_key': fields.get('api_key', self.config.get('api_key', '')),
            'remember': bool(fields.get('remember', self.config.get('remember', False))),
            'default_category': fields.get('default_category', self.config.get('default_category', '')),
            'default_album': fields.get('default_album', self.config.get('default_album', '')),
            'default_orientation': fields.get('default_orientation', self.config.get('default_orientation', 'auto')),
            'last_image_folder': fields.get('last_image_folder', self.config.get('last_image_folder', '')),
            'last_manifest_file': fields.get('last_manifest_file', self.config.get('last_manifest_file', '')),
            'google_credentials': fields.get('google_credentials', self.config.get('google_credentials', '')),
            'drive_folder_id': fields.get('drive_folder_id', self.config.get('drive_folder_id', '')),
            'gemini_api_key': fields.get('gemini_api_key', self.config.get('gemini_api_key', '')),
            'gemini_last_prompt': fields.get('gemini_last_prompt', self.config.get('gemini_last_prompt', '')),
            'copyright_text': fields.get('copyright_text', self.config.get('copyright_text', '')),
        })
        cfg_module.save(self.config)

    # ────────────────────────────────────────────────────────────────────────
    # CONNECT
    # ────────────────────────────────────────────────────────────────────────
    def connect(self, url: str, api_key: str, remember: bool, ack_insecure: bool = False) -> dict:
        url = (url or '').strip()
        api_key = (api_key or '').strip()
        if not url or not api_key:
            raise RuntimeError("Enter both a site URL and an API key.")
        reason = insecure_transport_reason(url)
        if reason and not ack_insecure:
            return {'needs_insecure_ack': True, 'reason': reason}

        client = SnapSmackClient(url, api_key=api_key)
        try:
            client.verify()
            site_data = client.fetch_site_data()
        except WrongSiteModeError as e:
            if e.site_mode == 'smacktalk':
                raise RuntimeError(
                    "This is a SMACKTALK site — SMACK YOUR BATCH UP can't post to it. "
                    "Use COLD SNAP for SMACKTALK.")
            raise RuntimeError(str(e))

        self.client = client
        self.site_data = site_data

        # Persist connection like the desktop app does on success.
        self.config['url'] = url
        self.config['api_key'] = api_key
        self.config['remember'] = bool(remember)
        cfg_module.save(self.config)

        mode = (getattr(site_data, 'site_mode', '') or '').strip().lower()
        return {
            'ok': True,
            'site_mode': mode,
            'mode_tab': {'photoblog': 'solo', 'carousel': 'gram'}.get(mode, ''),
            'categories': self.categories(),
            'albums': self.albums(),
            'base_url': client.base_url,
        }

    # ────────────────────────────────────────────────────────────────────────
    # QUEUE — scan / load / edit / select / reorder / clear
    # ────────────────────────────────────────────────────────────────────────
    def _set_entries(self, entries: List[ManifestEntry], image_folder: str) -> dict:
        """Replace the queue with a fresh entry list; offer recovery restore."""
        self.image_folder = image_folder
        self.entries = entries
        self.rowstate = [{'selected': True, 'status': 'pending', 'message': ''} for _ in entries]
        self.ensure_recovery(image_folder)
        restorable = 0
        try:
            if self.recovery and self.recovery.exists():
                restorable = self.recovery.enriched_count_for(entries)
        except Exception:
            restorable = 0
        out = self.serialize_queue()
        out['cats'] = self.categories()
        out['albums'] = self.albums()
        out['restorable'] = restorable
        return out

    def scan_folder(self, image_folder: str, def_cat: str, def_album: str, def_orient: str) -> dict:
        image_folder = (image_folder or '').strip()
        if not image_folder:
            raise RuntimeError("Select an image folder first.")
        if not os.path.isdir(image_folder):
            raise RuntimeError(f"Cannot find folder:\n{image_folder}")
        files = sorted(
            f for f in os.listdir(image_folder)
            if os.path.splitext(f)[1].lower() in IMAGE_EXTS
        )
        if not files:
            raise RuntimeError("No JPG / PNG / WebP files found in that folder.")
        orient_api = _orient_to_api(def_orient)
        entries = []
        for fname in files:
            e = ManifestEntry()
            e.file = fname
            e.category = (def_cat or '').strip()
            e.album = (def_album or '').strip()
            e.orientation = orient_api
            entries.append(e)
        return self._set_entries(entries, image_folder)

    def load_manifest(self, manifest_path: str, image_folder: str) -> dict:
        manifest_path = (manifest_path or '').strip()
        image_folder = (image_folder or '').strip()
        if not manifest_path or not os.path.isfile(manifest_path):
            raise RuntimeError("Pick a manifest .txt file that exists.")
        if not image_folder or not os.path.isdir(image_folder):
            raise RuntimeError("Pick an image folder that exists.")
        parsed = manifest_parser.parse(manifest_path)
        if not parsed.entries:
            raise RuntimeError("No valid entries in the manifest.\n" + "\n".join(parsed.errors[:10]))
        out = self._set_entries(parsed.entries, image_folder)
        out['manifest_errors'] = parsed.errors
        return out

    def apply_resume(self) -> dict:
        """Restore saved enrichment for the current folder (the dollar-saver)."""
        if not self.recovery:
            return self.serialize_queue()
        before = {i: bool(self.entries[i].title or self.entries[i].tags) for i in range(len(self.entries))}
        self.recovery.restore_into(self.entries)
        for i, e in enumerate(self.entries):
            rec = self.recovery.lookup(e)
            if rec and rec.get('status') in ('enriched', 'ok') and (rec.get('title') or rec.get('tags')):
                self.rowstate[i]['status'] = 'enriched'
        return self.serialize_queue()

    def ensure_recovery(self, image_folder: str) -> None:
        try:
            store = recovery_module.RecoveryStore(image_folder)
            if store.exists():
                store.load()
            self.recovery = store
        except Exception:
            self.recovery = None

    def update_entry(self, index: int, patch: dict) -> dict:
        if not (0 <= index < len(self.entries)):
            raise RuntimeError("Row out of range.")
        e = self.entries[index]
        for k in ('title', 'tags', 'category', 'album', 'colors', 'caption', 'alt', 'color_mode'):
            if k in patch:
                setattr(e, k, patch[k] or '')
        if 'orientation' in patch:
            e.orientation = _orient_to_api(patch['orientation'])
        return self._serialize_entry(index)

    def set_selected(self, index: int, on: bool) -> None:
        if 0 <= index < len(self.rowstate):
            self.rowstate[index]['selected'] = bool(on)

    def set_all_selected(self, on: bool) -> None:
        for rs in self.rowstate:
            rs['selected'] = bool(on)

    def reorder(self, from_index: int, to_index: int) -> dict:
        n = len(self.entries)
        if not (0 <= from_index < n) or not (0 <= to_index < n):
            raise RuntimeError("Reorder index out of range.")
        e = self.entries.pop(from_index)
        rs = self.rowstate.pop(from_index)
        self.entries.insert(to_index, e)
        self.rowstate.insert(to_index, rs)
        return self.serialize_queue()

    def shuffle(self) -> dict:
        import random
        pairs = list(zip(self.entries, self.rowstate))
        random.shuffle(pairs)
        if pairs:
            self.entries, self.rowstate = [list(t) for t in zip(*pairs)]
            self.entries = list(self.entries)
            self.rowstate = list(self.rowstate)
        return self.serialize_queue()

    def clear_queue(self) -> dict:
        """Clear, but KEEP failed rows so an accidental clear never wipes uploads
        that didn't land (mirrors EntryList.clear(keep_errors=True))."""
        keep_e, keep_r = [], []
        for e, rs in zip(self.entries, self.rowstate):
            if rs['status'] == 'error':
                keep_e.append(e)
                keep_r.append(rs)
        self.entries, self.rowstate = keep_e, keep_r
        # Prune warnings to surviving files.
        live = {self._warn_key(e) for e in self.entries}
        self.enrichment_warnings = {k: v for k, v in self.enrichment_warnings.items() if k in live}
        return self.serialize_queue()

    def clear_enrichment_warning(self) -> dict:
        n = len(self.enrichment_warnings)
        self.enrichment_warnings.clear()
        return {'cleared': n, **self.serialize_queue()}

    # ── thumbnails ──────────────────────────────────────────────────────────
    def thumb(self, index: int, size: int = 144) -> str:
        if not (0 <= index < len(self.entries)):
            raise RuntimeError("Row out of range.")
        path = os.path.join(self.image_folder, self.entries[index].file)
        return thumb_data_uri(path, (size, size))

    # ────────────────────────────────────────────────────────────────────────
    # GEMINI presets + test
    # ────────────────────────────────────────────────────────────────────────
    def preset_names(self) -> List[str]:
        return sorted(self.prompts.keys())

    def preset_text(self, name: str) -> str:
        return self.prompts.get(name, '')

    def preset_save(self, name: str, text: str) -> dict:
        name = (name or '').strip()
        text = (text or '').strip()
        if not name:
            raise RuntimeError("Give the preset a name.")
        if not text:
            raise RuntimeError("Write a prompt before saving it as a preset.")
        self.prompts[name] = text
        cfg_module.save_prompts(self.prompts)
        return {'names': self.preset_names(), 'selected': name}

    def preset_delete(self, name: str) -> dict:
        name = (name or '').strip()
        if not name or name not in self.prompts:
            return {'names': self.preset_names(), 'message': ''}
        is_builtin = name in cfg_module.DEFAULT_PROMPTS
        is_override = is_builtin and self.prompts.get(name) != cfg_module.DEFAULT_PROMPTS.get(name)
        if is_builtin and not is_override:
            return {'names': self.preset_names(),
                    'refused': True,
                    'message': f'"{name}" is a built-in preset and can\'t be deleted. '
                               'Edit it and Save under a new name to make your own.'}
        del self.prompts[name]
        cfg_module.save_prompts(self.prompts)
        # Reload so an overridden built-in reverts to shipped text.
        self.prompts = cfg_module.load_prompts()
        msg = (f'Reset "{name}" to the built-in text.' if is_override else f'Deleted "{name}".')
        return {'names': self.preset_names(), 'message': msg}

    def gemini_test(self, api_key: str) -> dict:
        api_key = (api_key or '').strip()
        if not api_key:
            raise RuntimeError("No key entered.")
        g = _gemini()
        if not g.is_available():
            raise RuntimeError("google-generativeai library not installed.")

        def _target(op: Op):
            ok, msg = g.test_connection(api_key)
            op.result = {'ok': ok, 'message': msg}
        return self.start_op('gemini_test', _target)

    # ────────────────────────────────────────────────────────────────────────
    # ENRICH
    # ────────────────────────────────────────────────────────────────────────
    def enrich_start(self, api_key: str, custom_prompt: str) -> dict:
        api_key = (api_key or '').strip()
        if not api_key:
            raise RuntimeError("Enter a Gemini API key first.")
        g = _gemini()
        if not g.is_available():
            raise RuntimeError("google-generativeai library not installed.")
        # Selected subset, keyed by identity for row mapping.
        sel = [(i, self.entries[i]) for i in range(len(self.entries)) if self.rowstate[i]['selected']]
        if not sel:
            raise RuntimeError("Tick at least one image to enrich.")
        self.ensure_recovery(self.image_folder)
        id_to_index = {id(e): i for i, e in enumerate(self.entries)}
        sel_entries = [e for _, e in sel]
        cats = self.categories()
        albums = self.albums()
        sd = self.site_data

        def _target(op: Op):
            def on_progress(idx, total, entry, error):
                i = id_to_index.get(id(entry))
                if error:
                    key = self._warn_key(entry)
                    self.enrichment_warnings[key] = error
                    if i is not None:
                        self.rowstate[i]['status'] = 'error'
                        self.rowstate[i]['message'] = error
                    op.events.append({'type': 'progress', 'index': i, 'current': idx,
                                      'total': total, 'ok': False, 'message': error})
                else:
                    if i is not None:
                        self.rowstate[i]['status'] = 'enriched'
                        self.rowstate[i]['message'] = ''
                    try:
                        if self.recovery:
                            self.recovery.upsert(entry, 'enriched')
                    except Exception:
                        pass
                    row = self._serialize_entry(i) if i is not None else None
                    op.events.append({'type': 'progress', 'index': i, 'current': idx,
                                      'total': total, 'ok': True, 'row': row})

            g.enrich_batch(
                api_key=api_key,
                entries=sel_entries,
                image_folder=self.image_folder,
                categories=cats,
                albums=albums,
                on_progress=on_progress,
                skip_filled=True,
                custom_prompt=custom_prompt or '',
                cat_descriptions=getattr(sd, 'cat_descriptions', None),
                album_descriptions=getattr(sd, 'album_descriptions', None),
                existing_tags=getattr(sd, 'tags', None),
                existing_titles=getattr(sd, 'titles', None),
                cancel_event=op.cancel,
            )
            op.result = {'warnings': len(self.enrichment_warnings)}
        return self.start_op('enrich', _target)

    # ────────────────────────────────────────────────────────────────────────
    # VALIDATE
    # ────────────────────────────────────────────────────────────────────────
    def validate(self, def_cat: str, def_album: str) -> dict:
        if not (self.client and self.site_data):
            raise RuntimeError("Connect first.")
        if not self.entries:
            raise RuntimeError("Load or scan images first.")
        cats = list(self.site_data._cat_display.values())
        albums = list(self.site_data._album_display.values())
        issues = manifest_parser.validate(
            entries=self.entries,
            image_folder=self.image_folder,
            known_categories=cats,
            known_albums=albums,
            default_category=(def_cat or '').strip(),
            default_album=(def_album or '').strip(),
        )
        out = []
        for entry, warnings in issues:
            for w in warnings:
                out.append(f"{entry.file}: {w}")
        return {'ok': not out, 'count': len(self.entries), 'issues': out}

    # ────────────────────────────────────────────────────────────────────────
    # POST — preflight + run (solo or gram)
    # ────────────────────────────────────────────────────────────────────────
    def post_preflight(self, as_grams: bool, drive_enabled: bool) -> dict:
        if not (self.client and self.site_data):
            raise RuntimeError("Connect first.")
        if not self.entries:
            raise RuntimeError("Load or scan images first.")
        sel_idx = [i for i in range(len(self.entries)) if self.rowstate[i]['selected']]
        if not sel_idx:
            raise RuntimeError("Tick at least one image to post (or use Select all).")

        blocked = []
        for i in sel_idx:
            key = self._warn_key(self.entries[i])
            if key in self.enrichment_warnings:
                blocked.append(f"{self.entries[i].file}: {self.enrichment_warnings[key]}")

        # Destination host for the confirm text.
        from urllib.parse import urlparse
        base = getattr(self.client, 'base_url', '') or self.config.get('url', '')
        try:
            dest = urlparse(base if '://' in base else 'https://' + base).netloc or base
        except Exception:
            dest = base
        dest = dest or 'your site'

        want_mode = 'carousel' if as_grams else 'photoblog'
        site_mode = (getattr(self.site_data, 'site_mode', '') or '').strip().lower()
        tab_label = 'GRAM' if as_grams else 'SOLO'
        site_label = {'photoblog': 'SOLO (SmackOneOut photoblog)',
                      'carousel': 'GRAM (GramOfSmack / The Grid)',
                      'smacktalk': 'SMACKTALK'}.get(site_mode, site_mode or 'unknown')
        if site_mode and site_mode == want_mode:
            mode_state = 'match'
        elif site_mode in ('photoblog', 'carousel', 'smacktalk'):
            mode_state = 'known_mismatch'
        else:
            mode_state = 'unknown'

        return {
            'count': len(sel_idx),
            'total': len(self.entries),
            'dest': dest,
            'blocked_enrichment': blocked,
            'drive_missing': bool(drive_enabled and self.drive_service is None),
            'mode_state': mode_state,
            'tab_label': tab_label,
            'site_label': site_label,
            'site_mode': site_mode,
        }

    def post_start(self, as_grams: bool, def_cat: str, def_album: str, def_orient: str,
                   def_color: str, copyright_text: str, drive_folder_id: str,
                   ack_no_drive: bool = False, ack_unknown_mode: bool = False,
                   drive_enabled: bool = True) -> dict:
        pf = self.post_preflight(as_grams, drive_enabled)
        if pf['blocked_enrichment']:
            raise RuntimeError("Posting blocked — %d image(s) failed enrichment. "
                               "Re-run ENRICH or Clear AI Warning." % len(pf['blocked_enrichment']))
        if pf['mode_state'] == 'known_mismatch':
            raise RuntimeError(
                f"Wrong mode — post blocked. You're posting {pf['tab_label']}, but "
                f"{pf['dest']} is a {pf['site_label']} site. Switch tabs or connect to a "
                f"matching site.")
        if pf['drive_missing'] and not ack_no_drive:
            return {'needs_ack': 'no_drive'}
        if pf['mode_state'] == 'unknown' and not ack_unknown_mode:
            return {'needs_ack': 'unknown_mode', 'dest': pf['dest'], 'tab_label': pf['tab_label']}

        sel = [(i, self.entries[i]) for i in range(len(self.entries)) if self.rowstate[i]['selected']]
        id_to_index = {id(e): i for i, e in enumerate(self.entries)}
        sel_entries = [e for _, e in sel]
        image_folder = self.image_folder
        self.ensure_recovery(image_folder)

        # Batch COLOUR/B&W tag → color_mode on entries lacking one.
        batch_color = {'colour': 'color', 'color': 'color', 'b&w': 'bw', 'bw': 'bw'}.get(
            (def_color or '').strip().lower(), '')
        if batch_color:
            for e in sel_entries:
                if not (getattr(e, 'color_mode', '') or '').strip():
                    e.color_mode = batch_color

        orient_val = _orient_to_api(def_orient)

        # Mark selected rows 'posting'.
        for i, _ in sel:
            self.rowstate[i]['status'] = 'posting'

        def _target(op: Op):
            def on_progress(current, total, result):
                i = id_to_index.get(id(result.entry))
                if result.success:
                    status = 'ok' if result.exif_ok else 'warning'
                    if self.recovery:
                        try:
                            self.recovery.mark_status(result.entry, 'ok')
                        except Exception:
                            pass
                else:
                    status = 'error'
                if i is not None:
                    self.rowstate[i]['status'] = status
                    self.rowstate[i]['message'] = result.message
                op.events.append({'type': 'progress', 'index': i, 'current': current,
                                  'total': total, 'success': result.success,
                                  'status': status, 'message': result.message,
                                  'file': result.entry.file})

            if as_grams:
                auth = self.client.session.headers.get("Authorization", "")
                key = auth[7:].strip() if auth.lower().startswith("bearer ") else ""
                conn = poster_module.GramConnection(self.client.base_url, key)
                op.active_conn = conn
                results = poster_module.run_gram_batch(
                    conn=conn, entries=sel_entries, image_folder=image_folder,
                    on_progress=on_progress, cancel_event=op.cancel)
            else:
                results = poster_module.run_batch(
                    client=self.client, entries=sel_entries, image_folder=image_folder,
                    site_data=self.site_data,
                    default_category=(def_cat or '').strip(),
                    default_album=(def_album or '').strip(),
                    default_orientation=orient_val,
                    on_progress=on_progress,
                    drive_service=self.drive_service,
                    drive_folder_id=(drive_folder_id or '').strip(),
                    copyright_text=(copyright_text or '').strip(),
                    cancel_event=op.cancel)
            op.active_conn = None
            cancelled = op.cancel.is_set()
            failed = sum(1 for r in results if not r.success)
            # Whole batch clean → recovery file no longer needed.
            if not cancelled and failed == 0 and self.recovery:
                try:
                    if self.recovery.all_posted():
                        self.recovery.delete()
                        self.recovery = None
                except Exception:
                    pass
            op.result = {'processed': len(results), 'failed': failed, 'cancelled': cancelled}
        return self.start_op('post', _target)

    def cancel_post(self) -> dict:
        return self.cancel_op('post')

    # ────────────────────────────────────────────────────────────────────────
    # GOOGLE DRIVE
    # ────────────────────────────────────────────────────────────────────────
    def drive_toggle(self, enabled: bool) -> dict:
        self.config['drive_enabled'] = bool(enabled)
        cfg_module.save(self.config)
        if not enabled:
            self.drive_service = None
            return {'enabled': False, 'connected': False}
        return {'enabled': True, 'connected': self.drive_service is not None}

    def auth_drive(self, creds_path: str) -> dict:
        creds_path = (creds_path or '').strip()
        if not creds_path or not os.path.isfile(creds_path):
            raise RuntimeError("Pick a valid Google credentials .json first.")
        d = _drive()

        def _target(op: Op):
            # NOTE(port): first-run Drive auth opens a browser tab for OAuth consent
            # (drive.authenticate → run_local_server); after that token.json is silent.
            service = d.authenticate(creds_path)
            self.drive_service = service
            op.result = {'connected': True}
        return self.start_op('drive_auth', _target)

    def drive_status(self) -> dict:
        return {'connected': self.drive_service is not None}

    # ────────────────────────────────────────────────────────────────────────
    # AUDIT
    # ────────────────────────────────────────────────────────────────────────
    def audit_refresh(self) -> dict:
        if not self.client:
            raise RuntimeError("Connect first.")

        def _target(op: Op):
            summary = self.client.audit_summary()
            posts = self.client.audit_list()
            self.audit_summary = summary
            self.audit_data = posts
            dups, missing = self._compute_audit_issues(posts)
            op.result = {
                'summary': summary,
                'total': len(posts),
                'duplicates': [
                    {'title': t, 'posts': [{'snap_id': p.get('snap_id'),
                                            'img_title': p.get('img_title', ''),
                                            'img_date': p.get('img_date', '')} for p in ps]}
                    for t, ps in dups.items()
                ],
                'missing_drive': [
                    {'snap_id': p.get('snap_id'), 'img_title': p.get('img_title', ''),
                     'img_date': p.get('img_date', '')} for p in missing
                ],
            }
        return self.start_op('audit', _target)

    def _compute_audit_issues(self, posts):
        by_title: Dict[str, list] = {}
        missing = []
        for p in posts or []:
            title = (p.get('img_title') or '').strip()
            if title:
                by_title.setdefault(title.lower(), []).append(p)
            if not (p.get('download_url') or '').strip():
                missing.append(p)
        dups = {}
        for _k, group in by_title.items():
            if len(group) > 1:
                dups[group[0].get('img_title', _k)] = group
        return dups, missing

    # ────────────────────────────────────────────────────────────────────────
    # REPAIR — rename / re-enrich / backfill
    # ────────────────────────────────────────────────────────────────────────
    @staticmethod
    def _drive_file_id(download_url: str) -> str:
        if not download_url:
            return ''
        m = re.search(r'/file/d/([A-Za-z0-9_\-]+)', download_url) or \
            re.search(r'[?&]id=([A-Za-z0-9_\-]+)', download_url)
        return m.group(1) if m else ''

    def rename_start(self) -> dict:
        if self.drive_service is None:
            raise RuntimeError("Auth Google Drive first.")
        if not self.audit_data:
            raise RuntimeError("Run an Audit refresh first.")
        d = _drive()
        posts = [p for p in self.audit_data if (p.get('download_url') or '').strip()]

        def _target(op: Op):
            done = errors = 0
            total = len(posts)
            for n, p in enumerate(posts, start=1):
                if op.cancel.is_set():
                    break
                sid = p.get('snap_id')
                fid = self._drive_file_id(p.get('download_url', ''))
                if not fid:
                    errors += 1
                    op.events.append({'type': 'log', 'level': 'warn', 'current': n, 'total': total,
                                      'message': f"#{sid}: no Drive file id in URL — skipped"})
                    continue
                try:
                    new_name = f"{sid}.jpg"
                    d.rename(self.drive_service, fid, new_name)
                    done += 1
                    op.events.append({'type': 'log', 'level': 'ok', 'current': n, 'total': total,
                                      'message': f"#{sid} → {new_name}"})
                except Exception as exc:
                    errors += 1
                    op.events.append({'type': 'log', 'level': 'err', 'current': n, 'total': total,
                                      'message': f"#{sid}: {exc}"})
                time.sleep(0.15)
            op.result = {'done': done, 'errors': errors, 'total': total}
        return self.start_op('rename', _target)

    def rename_stop(self) -> dict:
        return self.cancel_op('rename')

    def reenrich_start(self) -> dict:
        if self.drive_service is None:
            raise RuntimeError("Auth Google Drive first.")
        if not self.audit_data:
            raise RuntimeError("Run an Audit refresh first.")
        if not self.client:
            raise RuntimeError("Connect first.")
        api_key = (self.config.get('gemini_api_key') or '').strip()
        if not api_key:
            raise RuntimeError("Set a Gemini API key (Settings or POST tab) first.")
        g = _gemini()
        d = _drive()
        dups, _missing = self._compute_audit_issues(self.audit_data)
        to_fix = []
        for _title, group in dups.items():
            to_fix.extend(group[1:])   # keep the first of each duplicate group
        used_titles = {(p.get('img_title') or '').strip().lower()
                       for p in self.audit_data if (p.get('img_title') or '').strip()}
        cats = self.categories()
        albums = self.albums()

        def _target(op: Op):
            genai = g._import_genai()
            genai.configure(api_key=api_key)
            model = genai.GenerativeModel(g.MODEL_NAME)
            prompt = g._build_prompt(cats, albums)
            done = errors = 0
            total = len(to_fix)
            for n, p in enumerate(to_fix, start=1):
                if op.cancel.is_set():
                    break
                sid = p.get('snap_id')
                fid = self._drive_file_id(p.get('download_url', ''))
                if not fid:
                    errors += 1
                    op.events.append({'type': 'log', 'level': 'warn', 'current': n, 'total': total,
                                      'message': f"#{sid}: no Drive file id — skipped"})
                    continue
                tmp = ''
                try:
                    tmp = d.download_to_temp(self.drive_service, fid)
                    img_part = g._load_image_part(None, tmp)
                    new_title = ''
                    for attempt in range(1, 5):
                        run_prompt = prompt if attempt == 1 else (
                            f'The title "{new_title}" is already used. Generate a DIFFERENT '
                            f'unique title.\n\n' + prompt)
                        resp = model.generate_content([run_prompt, img_part])
                        cand = (g._parse_response(resp.text).get('title') or '').strip()
                        if cand and cand.lower() not in used_titles:
                            new_title = cand
                            break
                        new_title = cand or new_title
                    if not new_title or new_title.lower() in used_titles:
                        errors += 1
                        op.events.append({'type': 'log', 'level': 'err', 'current': n, 'total': total,
                                          'message': f"#{sid}: no unique title after retries"})
                    else:
                        self.client.audit_update_title(sid, new_title)
                        used_titles.add(new_title.lower())
                        done += 1
                        op.events.append({'type': 'log', 'level': 'ok', 'current': n, 'total': total,
                                          'message': f"#{sid} → {new_title}"})
                except Exception as exc:
                    errors += 1
                    op.events.append({'type': 'log', 'level': 'err', 'current': n, 'total': total,
                                      'message': f"#{sid}: {exc}"})
                finally:
                    if tmp and os.path.isfile(tmp):
                        try:
                            os.unlink(tmp)
                        except OSError:
                            pass
                time.sleep(0.5)
            # Mark audit stale so the next refresh re-pulls.
            self.audit_data = None
            self.audit_summary = None
            op.result = {'done': done, 'errors': errors, 'total': total}
        return self.start_op('reenrich', _target)

    def reenrich_stop(self) -> dict:
        return self.cancel_op('reenrich')

    def backfill_list(self, drive_folder_id: str) -> dict:
        """The missing-drive posts from the last audit + whether auto-search is
        possible (drive connected AND a folder id set)."""
        _dups, missing = self._compute_audit_issues(self.audit_data or [])
        drive_ready = self.drive_service is not None and bool((drive_folder_id or '').strip())
        return {
            'drive_ready': drive_ready,
            'rows': [{'snap_id': p.get('snap_id'), 'img_title': p.get('img_title', ''),
                      'img_date': p.get('img_date', '')} for p in missing],
        }

    def backfill_auto(self, snap_id: int, title: str, drive_folder_id: str) -> dict:
        if self.drive_service is None or not (drive_folder_id or '').strip():
            return {'found': False, 'message': 'Drive not ready — type the link.'}
        d = _drive()
        try:
            results = d.search(self.drive_service, drive_folder_id.strip(), title or '')
        except Exception as exc:
            return {'found': False, 'message': str(exc)}
        if not results:
            return {'found': False, 'message': 'No matching Drive file — type the link.'}
        url = results[0]['url']
        return self.backfill_save(snap_id, url)

    def backfill_save(self, snap_id, url: str) -> dict:
        url = (url or '').strip()
        if not url:
            raise RuntimeError("Enter a download URL.")
        if not self.client:
            raise RuntimeError("Connect first.")
        try:
            self.client.keepalive()
            r = self.client.session.post(
                f"{self.client.base_url}/smack-backfill.php",
                data={'action': 'update', 'snap_id': snap_id, 'download_url': url},
                timeout=15,
            )
            r.raise_for_status()
            data = r.json()
        except Exception as exc:
            raise RuntimeError(f"Backfill failed: {exc}")
        if not data.get('ok'):
            raise RuntimeError(data.get('error', 'Backfill rejected by server.'))
        self.audit_data = None   # mark audit stale
        return {'ok': True, 'snap_id': snap_id, 'url': url}

    # ────────────────────────────────────────────────────────────────────────
    # ADVANCED VISUAL MATCH
    # ────────────────────────────────────────────────────────────────────────
    def match_start(self, srv_folder: str, orig_folder: str) -> dict:
        srv_folder = (srv_folder or '').strip()
        orig_folder = (orig_folder or '').strip()
        if not os.path.isdir(srv_folder):
            raise RuntimeError("Pick a valid SERVER folder (local FTP copy).")
        if not os.path.isdir(orig_folder):
            raise RuntimeError("Pick a valid ORIGINALS folder.")
        srv_files = [os.path.join(srv_folder, f) for f in sorted(os.listdir(srv_folder))
                     if os.path.splitext(f)[1].lower() in IMAGE_EXTS]
        orig_files = [os.path.join(orig_folder, f) for f in sorted(os.listdir(orig_folder))
                      if os.path.splitext(f)[1].lower() in IMAGE_EXTS]
        if not srv_files:
            raise RuntimeError("No images in the SERVER folder.")
        if not orig_files:
            raise RuntimeError("No images in the ORIGINALS folder.")
        self.match_rows = {}
        self._match_srv_folder = srv_folder
        self._match_orig_folder = orig_folder

        def _target(op: Op):
            from concurrent.futures import ProcessPoolExecutor, as_completed
            import matcher as matcher_module
            orig_pairs = [(op_path, matcher_module.phash_file(op_path)) for op_path in orig_files]
            total = len(srv_files)
            workers = max(1, min(4, int((os.cpu_count() or 2) * 0.75)))
            with ProcessPoolExecutor(max_workers=workers) as pool:
                futs = {pool.submit(matcher_module.match_one, (sp, orig_pairs)): sp
                        for sp in srv_files}
                done = 0
                for fut in as_completed(futs):
                    if op.cancel.is_set():
                        break
                    result = fut.result()
                    done += 1
                    sp = result.get('server_path', futs[fut])
                    base = os.path.splitext(os.path.basename(sp))[0]
                    record = {'snap_id': base, 'img_title': base, 'img_file': sp}
                    rid = self._match_next_id
                    self._match_next_id += 1
                    self.match_rows[rid] = {'record': record, 'result': result}
                    op.events.append({'type': 'row', 'row_id': rid,
                                      'current': done, 'total': total,
                                      'record': record,
                                      'confidence': result.get('confidence', 0.0),
                                      'match_count': result.get('match_count', 0),
                                      'label': result.get('label', 'none'),
                                      'match_path': result.get('match_path', ''),
                                      'server_path': sp})
            op.result = {'total': total, 'rows': len(self.match_rows)}
        return self.start_op('match', _target)

    def match_stop(self) -> dict:
        return self.cancel_op('match')

    def match_preview(self, row_id: int, which: str) -> str:
        row = self.match_rows.get(int(row_id))
        if not row:
            raise RuntimeError("No such match row.")
        if which == 'server':
            path = row['record'].get('img_file', '')
        else:
            path = row['result'].get('match_path', '')
        if not path or not os.path.isfile(path):
            return ''
        return thumb_data_uri(path, (380, 260))

    def match_pick(self, row_id: int, new_path: str) -> dict:
        row = self.match_rows.get(int(row_id))
        if not row:
            raise RuntimeError("No such match row.")
        new_path = (new_path or '').strip()
        if not new_path or not os.path.isfile(new_path):
            raise RuntimeError("Pick a file that exists.")
        row['result']['match_path'] = new_path
        return {'row_id': row_id, 'match_path': new_path}

    def match_skip(self, row_id: int) -> dict:
        self.match_rows.pop(int(row_id), None)
        return {'skipped': row_id}

    def match_upload(self, row_id: int, drive_folder_id: str) -> dict:
        row = self.match_rows.get(int(row_id))
        if not row:
            raise RuntimeError("No such match row.")
        match_path = row['result'].get('match_path', '')
        if not match_path or not os.path.isfile(match_path):
            raise RuntimeError("Choose the original first (Pick Different).")
        if self.drive_service is None:
            raise RuntimeError("Auth Google Drive first.")
        if not self.client:
            raise RuntimeError("Connect first.")
        d = _drive()
        record = row['record']
        snap_id = record.get('snap_id')
        raw_title = (record.get('img_title', '') or '').strip()
        _, ext = os.path.splitext(match_path)
        fname = poster_module.haiku_to_filename(raw_title, ext.lower()) if raw_title \
            else os.path.basename(match_path)
        folder_id = (drive_folder_id or '').strip() or None

        def _target(op: Op):
            import socket
            prev = socket.getdefaulttimeout()
            socket.setdefaulttimeout(180)
            try:
                drive_url = d.upload(self.drive_service, match_path, fname, folder_id=folder_id)
                # snap_id from the filename stem may be non-numeric; only backfill
                # when it is a real post id.
                try:
                    self.client.backfill_update_link(int(snap_id), drive_url)
                except (TypeError, ValueError):
                    pass
                self.match_rows.pop(int(row_id), None)
                op.result = {'ok': True, 'url': drive_url, 'row_id': row_id}
            finally:
                socket.setdefaulttimeout(prev)
        return self.start_op('match_upload', _target)

    # ────────────────────────────────────────────────────────────────────────
    # SITE PROFILES (SETTINGS)
    # ────────────────────────────────────────────────────────────────────────
    def profiles_list(self) -> List[str]:
        return profile_manager.list_profiles()

    def profile_get(self, name: str) -> dict:
        p = profile_manager.load_profile(name) or {}
        return {
            'name': p.get('name', name),
            'url': p.get('url', ''),
            'api_key': p.get('api_key', ''),
            'google_credentials': p.get('google_credentials', ''),
            'drive_folder_id': p.get('drive_folder_id', ''),
            'drive_enabled': bool(p.get('drive_enabled', True)),
            'gemini_api_key': p.get('gemini_api_key', ''),
            'copyright_text': p.get('copyright_text', ''),
            'default_category': p.get('default_category', ''),
            'default_album': p.get('default_album', ''),
            'default_orientation': p.get('default_orientation', 'auto') or 'auto',
        }

    def profile_new(self) -> dict:
        existing = set(profile_manager.list_profiles())
        base = 'New Site'
        name = base
        n = 2
        while name in existing:
            name = f"{base} {n}"
            n += 1
        p = profile_manager.blank_profile()
        p['name'] = name
        profile_manager.save_profile(p)
        return {'name': name, 'profiles': profile_manager.list_profiles()}

    def profile_duplicate(self, name: str) -> dict:
        if not name:
            raise RuntimeError("Select a profile to duplicate.")
        existing = set(profile_manager.list_profiles())
        base = f"{name} (copy)"
        new_name = base
        n = 2
        while new_name in existing:
            new_name = f"{base} {n}"
            n += 1
        profile_manager.duplicate_profile(name, new_name)
        return {'name': new_name, 'profiles': profile_manager.list_profiles()}

    def profile_save(self, fields: dict, old_name: str = '', overwrite: bool = False) -> dict:
        name = (fields.get('name') or '').strip()
        if not name:
            raise RuntimeError("Give the profile a name.")
        existing = set(profile_manager.list_profiles())
        renamed = bool(old_name) and old_name != name
        if name in existing and name != old_name and not overwrite:
            return {'needs_overwrite': True, 'name': name}
        profile = {
            'name': name,
            'url': fields.get('url', ''),
            'api_key': fields.get('api_key', ''),
            'google_credentials': fields.get('google_credentials', ''),
            'drive_folder_id': fields.get('drive_folder_id', ''),
            'drive_enabled': True,
            'gemini_api_key': fields.get('gemini_api_key', ''),
            'copyright_text': fields.get('copyright_text', ''),
            'default_category': fields.get('default_category', ''),
            'default_album': fields.get('default_album', ''),
            'default_orientation': fields.get('default_orientation', 'auto') or 'auto',
        }
        profile_manager.save_profile(profile)
        if renamed:
            profile_manager.delete_profile(old_name)
        return {'saved': True, 'name': name, 'profiles': profile_manager.list_profiles()}

    def profile_delete(self, name: str) -> dict:
        if not name:
            raise RuntimeError("Select a profile to delete.")
        profile_manager.delete_profile(name)
        return {'profiles': profile_manager.list_profiles()}

    def profile_apply_to_post(self, name: str) -> dict:
        """POST-tab values for a profile, inheriting shared/global creds from config
        when the profile leaves them blank (same as _apply_profile_to_post)."""
        p = profile_manager.load_profile(name) or {}
        c = self.config
        return {
            'url': p.get('url', ''),
            'api_key': p.get('api_key', ''),
            'google_credentials': p.get('google_credentials', '') or c.get('google_credentials', ''),
            'drive_folder_id': p.get('drive_folder_id', '') or c.get('drive_folder_id', ''),
            'gemini_api_key': p.get('gemini_api_key', '') or c.get('gemini_api_key', ''),
            'copyright_text': p.get('copyright_text', ''),
            'default_category': p.get('default_category', ''),
            'default_album': p.get('default_album', ''),
            'default_orientation': p.get('default_orientation', 'auto') or 'auto',
            'drive_enabled': bool(p.get('drive_enabled', True)),
        }

    def sp_test(self, url: str, api_key: str, ack_insecure: bool = False) -> dict:
        url = (url or '').strip()
        api_key = (api_key or '').strip()
        if not url or not api_key:
            raise RuntimeError("Enter both a URL and an API key.")
        reason = insecure_transport_reason(url)
        if reason and not ack_insecure:
            return {'needs_insecure_ack': True, 'reason': reason}

        def _target(op: Op):
            client = SnapSmackClient(url, api_key=api_key)
            client.verify()
            op.result = {'ok': True, 'message': 'Connected — key accepted.'}
        return self.start_op('sp_test', _target)

    def sp_gemini_test(self, api_key: str) -> dict:
        api_key = (api_key or '').strip()
        if not api_key:
            raise RuntimeError("No key entered.")
        g = _gemini()
        if not g.is_available():
            raise RuntimeError("google-generativeai library not installed.")

        def _target(op: Op):
            ok, msg = g.test_connection(api_key)
            op.result = {'ok': ok, 'message': msg}
        return self.start_op('sp_gem_test', _target)


# ---------------------------------------------------------------------------
# Thumbnail helper — Pillow → base64 data URI (the web port's stand-in for the
# desktop ImageTk thumbnails).
# ---------------------------------------------------------------------------

def thumb_data_uri(path: str, size=(144, 144)) -> str:
    try:
        from PIL import Image
        with Image.open(path) as src:
            img = src.convert("RGB")
            img.thumbnail(size, Image.LANCZOS)
        canvas = Image.new("RGB", size, (10, 10, 14))
        offset = ((size[0] - img.width) // 2, (size[1] - img.height) // 2)
        canvas.paste(img, offset)
        buf = io.BytesIO()
        canvas.save(buf, format="JPEG", quality=82)
        return "data:image/jpeg;base64," + base64.b64encode(buf.getvalue()).decode("ascii")
    except Exception:
        return ""


# One process-wide engine — the tool's whole live state.
ENGINE = Engine()

# ===== SNAPSMACK EOF =====
