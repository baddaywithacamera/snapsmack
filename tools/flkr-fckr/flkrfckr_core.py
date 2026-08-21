"""
FLKR FCKR — flkrfckr_core.py
Tkinter-free orchestration layer shared by the Linux Chrome/Blink port.

WHY THIS FILE EXISTS
    The original FLKR FCKR window (main.py, tkinter) mixed the WINDOW (widgets,
    dialogs, the queue that fed them) with the WORK (parse a Flickr export, test
    the connection, run the throttled import, keep a crash checkpoint, manage the
    API-key vault). The Linux port replaces the window with HTML/Chromium via
    snap_blink but keeps the work identical, so the work is factored out here as a
    plain, GUI-free Session object. Both the old tkinter app and the new Blink app
    could drive it; the Blink app (linux/app.py) is what does.

    IMPORTANT — DATA SAFETY (unchanged from the tkinter tool):
      * The importer attaches comments to the IMAGE id and preserves GPS/EXIF on
        purpose. This layer does NOT touch that. There is deliberately NO
        strip-location option and NO metadata "cleanup" — behaviour is identical
        to the Windows tool. Do not add one.
      * The import reads from the SAME filtered photo list the grid shows, so it
        never uploads a photo the operator can't see and exclude.

    The heavy modules (config, flickr_parser, poster, checkpoint, image_prep) are
    already tkinter-free and are imported and reused as-is. snap_stepup's GUI-free
    primitives (insecure_transport_reason, request_authorization) are reused for
    the step-up authorize flow; the tkinter dialogs in that module are not used.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import datetime
import glob
import logging
import os
import queue
import sys
import tempfile
import threading
import time
from typing import Dict, List, Optional

# The tool's own directory (this file lives in tools/flkr-fckr/) and the shared
# library one level up in _shared/ must both be importable so the reused work
# modules and snap_stepup/snap_vault resolve the same way they do for main.py.
_TOOL_DIR = os.path.dirname(os.path.abspath(__file__))
_SHARED_DIR = os.path.join(_TOOL_DIR, '..', '_shared')
for _p in (_TOOL_DIR, os.path.abspath(_SHARED_DIR)):
    if os.path.isdir(_p) and _p not in sys.path:
        sys.path.insert(0, _p)

import config as cfg_mod                       # noqa: E402
import flickr_parser                           # noqa: E402
from checkpoint import ImportCheckpoint        # noqa: E402
from poster import FlkrDckrClient, run_import   # noqa: E402
import snap_stepup                             # noqa: E402

BUILD_VERSION = "0.7.25"   # mirrors main.py's BUILD_VERSION

# Log level names carried in events, so the web UI can colour lines the same way
# the tkinter palette did (accent = ok, warn = amber, err = red, dim = muted).
OK, WARN, ERR, DIM, PRI = 'ok', 'warn', 'err', 'dim', 'pri'


# ---------------------------------------------------------------------------
# Logging — dated file next to this module, 14-day retention. Same convention as
# main.py (flkrfckr.<date>.log) so an operator's "today's log" is findable.
# ---------------------------------------------------------------------------

def _setup_logging() -> str:
    log_dir = _TOOL_DIR
    os.makedirs(log_dir, exist_ok=True)
    log_file = os.path.join(log_dir, 'flkrfckr.' + datetime.date.today().isoformat() + '.log')
    try:
        cutoff = time.time() - 14 * 86400
        for old in glob.glob(os.path.join(log_dir, 'flkrfckr.*.log')):
            if os.path.getmtime(old) < cutoff:
                try:
                    os.remove(old)
                except OSError:
                    pass
    except Exception:
        pass
    handler = logging.FileHandler(log_file, encoding='utf-8')
    handler.setFormatter(logging.Formatter(
        '%(asctime)s  %(levelname)-8s  %(message)s', datefmt='%Y-%m-%d %H:%M:%S'))
    lg = logging.getLogger('flkrfckr')
    lg.setLevel(logging.DEBUG)
    if not lg.handlers:
        lg.addHandler(handler)
    return log_dir


LOG_DIR = _setup_logging()
log = logging.getLogger('flkrfckr')


# Named throttle presets — identical set to the tkinter combobox. Stored in
# config as seconds-as-string. 'Full Send' (0s) is deliberately absent.
THROTTLE_OPTIONS = [
    ('Fast Lane (0.25s)', '0.25'), ('Steady (0.5s)', '0.5'),
    ('Easy Does It (1s)', '1.0'), ('Sunday Driver (2s)', '2.0'),
    ('Pump da Brakes (5s)', '5.0'), ("Grandma's Pace (10s)", '10.0'),
    ('Geological Time (30s)', '30.0'),
]
_THROTTLE_L2V = {l: v for l, v in THROTTLE_OPTIONS}
_THROTTLE_V2L = {v: l for l, v in THROTTLE_OPTIONS}


def throttle_label_for(value) -> str:
    cur = str(value)
    if cur not in _THROTTLE_V2L:
        try:
            cur = str(float(cur))
        except (TypeError, ValueError):
            cur = '1.0'
    return _THROTTLE_V2L.get(cur, 'Easy Does It (1s)')


def _status_colour(msg: str) -> str:
    m = msg.lower()
    if 'error' in m or 'fail' in m or 'missing' in m:
        return ERR
    if 'skip' in m or 'duplicate' in m or 'already' in m:
        return DIM
    return OK


class Session:
    """One live FLKR FCKR run, GUI-free. The window (tkinter or Blink) reads and
    writes this and drains its event queue for progress/log lines."""

    def __init__(self):
        cfg_mod.init_vault()
        self.cfg = cfg_mod.load()
        self.parse_result: Optional[flickr_parser.ParseResult] = None
        self.client: Optional[FlkrDckrClient] = None
        self.checkpoint: Optional[ImportCheckpoint] = None
        self.running = False
        self.paused = False
        self._stop_event = threading.Event()
        self._pause_event = threading.Event()
        self._pause_event.set()
        # Event queue drained by the page via poll_events(). Mirrors the tkinter
        # self._q that fed _poll_queue; the web UI polls instead of Tk .after().
        self._events: "queue.Queue" = queue.Queue()
        self._photo_by_id: Dict[str, object] = {}
        self._thumb_px = 120

    # ── event plumbing ──────────────────────────────────────────────────────
    def _emit(self, ev: dict) -> None:
        self._events.put(ev)

    def poll_events(self) -> List[dict]:
        """Drain and return every queued event. The page calls this on a timer."""
        out = []
        try:
            while True:
                out.append(self._events.get_nowait())
        except queue.Empty:
            pass
        return out

    def _log(self, text: str, level: str = PRI) -> None:
        self._emit({'type': 'log', 'text': text, 'level': level})

    # ── vault / key security ────────────────────────────────────────────────
    def vault_status(self) -> dict:
        try:
            import snap_vault
            available = cfg_mod.vault_available()
            enabled = bool(available and snap_vault.is_enabled())
            return {
                'available': available,
                'enabled': enabled,
                'unlocked': bool(enabled and snap_vault.is_unlocked()),
                'has_machine_key': bool(enabled and snap_vault.has_machine_key()),
                'sealed': cfg_mod.is_key_sealed(),
            }
        except Exception:
            return {'available': False, 'enabled': False, 'unlocked': False,
                    'has_machine_key': False, 'sealed': False}

    def vault_try_machine_key(self) -> bool:
        """Attempt a no-typing unlock from this machine's stored key. Returns True
        if the vault is now open (or was never encrypted)."""
        try:
            import snap_vault
            if not cfg_mod.vault_available() or not snap_vault.is_enabled():
                return True
            if snap_vault.is_unlocked():
                return True
            if snap_vault.unlock_with_machine_key():
                log.info("Vault unlocked from this machine's stored key.")
                self.cfg = cfg_mod.load()
                return True
        except Exception:
            pass
        return False

    def vault_unlock(self, passphrase: str) -> bool:
        import snap_vault
        if snap_vault.unlock(passphrase or ''):
            log.info('Vault unlocked.')
            self.cfg = cfg_mod.load()   # re-read so the (now readable) key loads
            return True
        return False

    def vault_enable(self, passphrase: str, remember: bool = False) -> None:
        cfg_mod.enable_encryption(passphrase, remember_on_this_machine=remember)
        self._log('API key encryption turned ON.', OK)

    def vault_disable(self) -> None:
        cfg_mod.disable_encryption()
        self._log('API key encryption turned OFF.', WARN)

    def vault_rekey(self, old: str, new: str) -> bool:
        ok = cfg_mod.change_passphrase(old, new)
        if ok:
            self._log('Encryption passphrase changed.', OK)
        return ok

    # ── settings ────────────────────────────────────────────────────────────
    def get_settings(self) -> dict:
        """Everything the settings bar needs. The API key is included only if the
        vault (if any) is unlocked; config.load() returns '' while locked."""
        c = self.cfg
        return {
            'site_url': c.get('site_url', ''),
            'api_key': c.get('api_key', ''),
            'export_folder': c.get('export_folder', ''),
            'throttle_label': throttle_label_for(c.get('throttle_delay', 1.0)),
            'throttle_options': [l for l, _ in THROTTLE_OPTIONS],
            'offpeak_only': bool(c.get('offpeak_only', False)),
            'peak_start': int(c.get('peak_start', 9)),
            'peak_end': int(c.get('peak_end', 23)),
            'private_status': c.get('private_status', 'draft'),
            'unalbumed_action': c.get('unalbumed_action', 'feed'),
            'default_album': c.get('default_album', ''),
            'auth_username': c.get('auth_username', ''),
            'version': BUILD_VERSION,
        }

    def save_settings(self, s: dict) -> None:
        """Persist the settings bar. `throttle` arrives as a preset LABEL (as the
        combobox held it) or a raw seconds value — both are normalised."""
        data = cfg_mod.load()
        throttle = s.get('throttle', s.get('throttle_delay', '1.0'))
        throttle_val = _THROTTLE_L2V.get(throttle, str(throttle))
        data.update({
            'site_url': (s.get('site_url') or '').strip(),
            'api_key': (s.get('api_key') or '').strip(),
            'export_folder': (s.get('export_folder') or '').strip(),
            'throttle_delay': throttle_val,
            'offpeak_only': bool(s.get('offpeak_only', False)),
            'peak_start': s.get('peak_start', 9),
            'peak_end': s.get('peak_end', 23),
            'private_status': s.get('private_status', 'draft'),
            'unalbumed_action': s.get('unalbumed_action', 'feed'),
            'default_album': (s.get('default_album') or '').strip(),
        })
        cfg_mod.save(data)
        self.cfg = cfg_mod.load()

    # ── connection test ─────────────────────────────────────────────────────
    def test_connection(self, url: str, key: str) -> dict:
        url = (url or '').strip()
        key = (key or '').strip()
        if not url or not key:
            return {'ok': False, 'message': 'URL and key required'}
        # SECAUDIT 040 — plaintext transport check BEFORE the Bearer key goes out.
        reason = snap_stepup.insecure_transport_reason(url)
        insecure = bool(reason)
        client = FlkrDckrClient(url, key)
        ok, msg = client.ping()
        if ok:
            self._log(msg, OK)
        return {'ok': ok, 'message': msg, 'insecure': insecure, 'insecure_reason': reason}

    # ── parse the export (threaded) ─────────────────────────────────────────
    def load_export(self, folder: str) -> dict:
        folder = (folder or '').strip()
        if not folder or not os.path.isdir(folder):
            return {'ok': False, 'message': 'Please select a valid export folder first.'}
        self._log('Parsing Flickr export…', DIM)

        def _on_prog(done, total):
            self._emit({'type': 'parse_progress', 'done': done, 'total': total})

        def _parse():
            try:
                result = flickr_parser.parse(folder, on_progress=_on_prog)
                self._on_parse_done(result)
            except Exception as e:
                log.exception('parse failed')
                self._log(f'FATAL: could not parse export — {e}', ERR)
                self._emit({'type': 'parse_failed', 'message': str(e)})

        threading.Thread(target=_parse, daemon=True).start()
        return {'ok': True, 'message': 'parsing'}

    def _on_parse_done(self, result: 'flickr_parser.ParseResult') -> None:
        self.parse_result = result
        self._photo_by_id = {p.flickr_id: p for p in result.photos}

        for err in result.errors:
            self._log(f'WARN: {err}', WARN)

        stats = result.stats
        self._log('── IMPORT SUMMARY ──────────────────────────────', OK)
        self._log(f"  Images found:       {stats.get('total_photos', 0)}", OK)
        self._log(f"  Albums found:       {stats.get('total_albums', 0)}", OK)
        self._log(f"  Album covers found: {stats.get('albums_with_covers', 0)}  (mapped to their albums on import)", OK)
        self._log(f"  Collections found:  {stats.get('total_collections', 0)}  (Flickr galleries -> SnapSmack Collections)", OK)
        self._log(f"  Comments found:     {stats.get('total_comments', 0)}", OK)
        self._log(f"  Likes found:        {stats.get('total_likes', 0)}  (Flickr fave counts, seeded on import)", OK)
        _dates = [flickr_parser._best_date(p) for p in result.photos
                  if p.date_taken or p.create_date]
        if _dates:
            self._log(f"  Earliest post:      {min(_dates).strftime('%Y-%m-%d')}  (date_taken, else create_date)", OK)
            self._log(f"  Latest post:        {max(_dates).strftime('%Y-%m-%d')}", OK)
        if stats.get('skipped_videos'):
            self._log(f"  Videos ignored:     {stats.get('skipped_videos', 0)}  (intentionally skipped - NOT missing photos)", DIM)
        if stats.get('missing_images'):
            self._log(f"  Missing image files:{stats.get('missing_images', 0)}  (sidecar present, image file not found)", WARN)
        if stats.get('private_photos'):
            self._log(f"  Private on Flickr:  {stats.get('private_photos', 0)}", DIM)
        self._log('────────────────────────────────────────────────', OK)

        self._emit({'type': 'parse_done',
                    'albums': self._albums_payload(),
                    'photos': self._photos_payload(),
                    'stats': stats})

    def _albums_payload(self) -> List[dict]:
        if not self.parse_result:
            return []
        return [{'flickr_id': a.flickr_id, 'title': a.title,
                 'count': len(a.photo_ids)} for a in self.parse_result.albums]

    def _photos_payload(self) -> List[dict]:
        """Serialise photos for the grid. Album filtering happens in the page
        (each photo carries its album ids); exclude toggles come back to us."""
        if not self.parse_result:
            return []
        out = []
        for p in self.parse_result.photos:
            badge, badge_kind = '', DIM
            if p.missing_image:
                badge, badge_kind = 'MISSING IMAGE', ERR
            elif p.privacy != 'public':
                badge, badge_kind = 'PRIVATE', WARN
            out.append({
                'flickr_id': p.flickr_id,
                'title': p.title,
                'date': p.date_taken.strftime('%Y-%m-%d') if p.date_taken else '?',
                'album_ids': list(p.album_ids),
                'missing_image': bool(p.missing_image),
                'privacy': p.privacy,
                'excluded': bool(p.excluded),
                'has_image': bool(p.image_path) and not p.missing_image,
                'badge': badge,
                'badge_kind': badge_kind,
            })
        return out

    def toggle_exclude(self, flickr_id: str) -> dict:
        p = self._photo_by_id.get(flickr_id)
        if p is None or p.missing_image:
            return {'ok': False, 'excluded': False}
        p.excluded = not p.excluded
        return {'ok': True, 'excluded': p.excluded}

    def _current_photos(self, flt: str, album_id: str) -> list:
        """The exact list the grid shows and Start Import uses. Single source of
        truth so the import never pulls a photo not visible/filtered in the page."""
        if not self.parse_result:
            return []
        if flt == 'unalbumed':
            return [p for p in self.parse_result.photos if not p.album_ids]
        if flt == 'album' and album_id:
            return [p for p in self.parse_result.photos if album_id in p.album_ids]
        return list(self.parse_result.photos)

    def summary(self, flt: str, album_id: str) -> dict:
        shown = self._current_photos(flt, album_id)
        total = len(shown)
        selected = sum(1 for p in shown if not p.excluded and not p.missing_image)
        missing = sum(1 for p in shown if p.missing_image)
        return {'total': total, 'selected': selected, 'missing': missing}

    # ── thumbnails ──────────────────────────────────────────────────────────
    def thumbnail(self, flickr_id: str) -> Optional[str]:
        """Square-cropped data: URI for one photo, decoded on demand (the page
        lazy-loads visible tiles). Returns None if Pillow is unavailable or the
        file can't be read — the page then shows the grey placeholder. Reuses the
        exact centre-crop the tkinter grid used. Does NOT alter the source file or
        its EXIF; it only reads it."""
        p = self._photo_by_id.get(flickr_id)
        if p is None or p.missing_image or not p.image_path:
            return None
        try:
            import base64
            import io
            from PIL import Image
            im = Image.open(p.image_path)
            try:
                im.draft('RGB', (self._thumb_px * 2, self._thumb_px * 2))
            except Exception:
                pass
            im = im.convert('RGB')
            w, h = im.size
            s = min(w, h)
            left, top = (w - s) // 2, (h - s) // 2
            im = im.crop((left, top, left + s, top + s)).resize(
                (self._thumb_px, self._thumb_px), Image.LANCZOS)
            buf = io.BytesIO()
            im.save(buf, format='JPEG', quality=82)
            return 'data:image/jpeg;base64,' + base64.b64encode(buf.getvalue()).decode('ascii')
        except Exception as e:
            log.warning('thumb decode failed id=%s: %s', flickr_id, e)
            return None

    # ── step-up authorization (GUI-free) ────────────────────────────────────
    def preflight_import(self, url: str, key: str) -> dict:
        """Check the server's auth state before importing. Returns what the page
        must do next: proceed, regenerate the key, or open a step-up window."""
        url = (url or '').strip()
        key = (key or '').strip()
        if not url or not key:
            return {'ok': False, 'action': 'error', 'message': 'Site URL and API key are required.'}
        self.client = FlkrDckrClient(url, key)
        auth = self.client.check_auth()
        if not auth.get('ok'):
            return {'ok': False, 'action': 'error',
                    'message': auth.get('message', 'Could not reach the site.')}
        if not auth.get('key_bound', True):
            return {'ok': False, 'action': 'regenerate_key',
                    'message': ('This import key is not tied to a user account. Regenerate it in '
                                'your site admin → API Keys, then paste the new key here.')}
        if not auth.get('import_authorized'):
            reason = snap_stepup.insecure_transport_reason(url)
            if reason:
                # request_authorization would refuse anyway; say so plainly.
                return {'ok': False, 'action': 'error',
                        'message': reason + ' Switch the site URL to https:// and try again.'}
            return {'ok': True, 'action': 'stepup',
                    'message': 'Import needs a fresh password + 2FA check.'}
        return {'ok': True, 'action': 'proceed', 'message': 'Import already authorized.'}

    def authorize_import(self, url: str, key: str, username: str,
                         password: str, totp_code: str) -> dict:
        """Open a leased import window with the step-up endpoint. Password and TOTP
        are used once and never stored; only the username is persisted."""
        res = snap_stepup.request_authorization(
            (url or '').strip(), 'flkrfckr/authorize', (key or '').strip(),
            username=(username or '').strip(), password=password or '',
            totp_code=(totp_code or '').strip())
        if res.ok:
            data = cfg_mod.load()
            data['auth_username'] = res.username
            try:
                cfg_mod.save(data)
                self.cfg = cfg_mod.load()
            except Exception:
                pass
            mins = res.window_minutes or 0
            self._log(f'Import authorized for {mins} min.' if mins else 'Import authorized.', OK)
        return {'ok': res.ok, 'message': res.message,
                'needs_enrollment': res.needs_enrollment,
                'window_minutes': res.window_minutes, 'username': res.username}

    # ── the import run (threaded) ───────────────────────────────────────────
    def start_import(self, flt: str, album_id: str) -> dict:
        """Begin the throttled import of exactly the filtered+kept photos. Assumes
        preflight/authorize already succeeded. Progress arrives via poll_events."""
        if self.running:
            return {'ok': False, 'message': 'An import is already running.'}
        if not self.parse_result or self.client is None:
            return {'ok': False, 'message': 'Load an export and connect first.'}

        photos = [p for p in self._current_photos(flt, album_id)
                  if not p.missing_image and not p.excluded]
        if not photos:
            return {'ok': False, 'message': 'No photos to import.'}

        self.save_settings({
            'site_url': self.cfg.get('site_url', ''),
            'api_key': self.cfg.get('api_key', ''),
            'export_folder': self.cfg.get('export_folder', ''),
        })
        cfg = cfg_mod.load()
        url = cfg.get('site_url', '').strip()
        key = cfg.get('api_key', '').strip()
        if not url or not key:
            return {'ok': False, 'message': 'Site URL and API key are required.'}

        # Reuse a resumed checkpoint (skip already-imported); discard one that
        # belongs to a different export; otherwise start fresh.
        if (self.checkpoint is not None
                and self.checkpoint.data.get('export_folder') != cfg.get('export_folder', '')):
            self.checkpoint = None
        if self.checkpoint is None:
            self.checkpoint = ImportCheckpoint(ImportCheckpoint.path_for())
            self.checkpoint.start(export_folder=cfg.get('export_folder', ''),
                                  site_url=url, total_photos=len(photos))
        else:
            self.checkpoint.update_total(len(photos))

        staging = tempfile.mkdtemp(prefix='flkrfckr_')
        flickr_album_map = {
            a.flickr_id: {'title': a.title, 'description': a.description,
                          'cover_flickr_id': a.cover_flickr_id,
                          'view_count': a.view_count}
            for a in (self.parse_result.albums or [])
        }

        self.running = True
        self.paused = False
        self._stop_event.clear()
        self._pause_event.set()
        self._emit({'type': 'started'})

        total = len(photos)
        throttle = float(cfg.get('throttle_delay', 1.0) or 1.0)

        def _on_progress(done, total_, result):
            if result.message.startswith('AUTH_EXPIRED'):
                self._log('Authorization window expired — re-authorize and click '
                          'Start to resume.', WARN)
                self._emit({'type': 'auth_expired'})
                return
            pct = (done / total_) * 100 if total_ else 0
            colour = _status_colour(result.message)
            status = 'DUP' if result.duplicate else ('ERR' if not result.success else 'OK')
            who = f"{result.flickr_id} ({result.filename})" if getattr(result, 'filename', '') else result.flickr_id
            self._emit({'type': 'progress', 'pct': pct,
                        'text': f"[{status}] {who} — {result.message}", 'level': colour})

        def _run():
            try:
                run_import(
                    client=self.client, photos=photos, staging_dir=staging,
                    checkpoint=self.checkpoint, flickr_album_map=flickr_album_map,
                    private_status=cfg.get('private_status', 'draft'),
                    unalbumed_action=cfg.get('unalbumed_action', 'feed'),
                    default_album=cfg.get('default_album', ''),
                    throttle_delay=throttle,
                    offpeak_only=bool(cfg.get('offpeak_only', False)),
                    peak_start=int(cfg.get('peak_start', 9)),
                    peak_end=int(cfg.get('peak_end', 23)),
                    on_wait=lambda hr: self._log(f'Off-peak: paused until {hr}:00', DIM),
                    on_progress=_on_progress,
                    stop_event=self._stop_event, pause_event=self._pause_event,
                )
            except Exception as e:
                log.exception('import failed')
                self._log(f'FATAL: {e}', ERR)
            finally:
                self._on_import_done()

        threading.Thread(target=_run, daemon=True).start()
        return {'ok': True, 'message': f'Importing {total} photo(s).', 'total': total}

    def pause_import(self) -> dict:
        self.paused = True
        self._pause_event.clear()
        self._log('Import paused.', WARN)
        return {'ok': True, 'paused': True}

    def resume_import(self) -> dict:
        self.paused = False
        self._pause_event.set()
        self._log('Import resumed.', OK)
        return {'ok': True, 'paused': False}

    def stop_import(self) -> dict:
        self._stop_event.set()
        self._pause_event.set()   # unblock a paused worker so it can exit
        return {'ok': True}

    def _on_import_done(self) -> None:
        self.running = False
        self.paused = False
        self._log('Import complete.', OK)
        if self.checkpoint:
            prog = self.checkpoint.progress()
            self._log(f"Done: {prog['imported']} imported, {prog['failed']} failed, "
                      f"{prog['skipped']} skipped.", PRI)
            if prog['failed'] == 0:
                self.checkpoint.delete()
                self.checkpoint = None
        self._emit({'type': 'done'})

    # ── resume-from-checkpoint (startup) ────────────────────────────────────
    def check_resume(self) -> Optional[dict]:
        """If a prior run was interrupted, describe it so the page can offer to
        resume. Returns None when there is nothing to resume."""
        cp = ImportCheckpoint.load()
        if not cp:
            return None
        prog = cp.progress()
        if prog['imported'] == 0:
            cp.delete()
            return None
        return {'imported': prog['imported'],
                'export_folder': cp.data.get('export_folder', '')}

    def resume_accept(self) -> dict:
        cp = ImportCheckpoint.load()
        if not cp:
            return {'ok': False}
        self.checkpoint = cp
        folder = cp.data.get('export_folder', '')
        prog = cp.progress()
        self._log(f"Resuming — {prog['imported']} already done.", OK)
        if folder and os.path.isdir(folder):
            self.load_export(folder)
            return {'ok': True, 'export_folder': folder, 'loading': True}
        self._log('Export folder not found — set it and click Load Export to resume.', WARN)
        return {'ok': True, 'export_folder': folder, 'loading': False}

    def resume_decline(self) -> dict:
        cp = ImportCheckpoint.load()
        if cp:
            cp.delete()
        self.checkpoint = None
        return {'ok': True}

    # ── misc ────────────────────────────────────────────────────────────────
    def open_logs(self) -> dict:
        """Open the log folder in the OS file manager (xdg-open on Linux)."""
        try:
            os.makedirs(LOG_DIR, exist_ok=True)
            import subprocess
            if hasattr(os, 'startfile'):
                os.startfile(LOG_DIR)                       # Windows
            else:
                opener = 'open' if sys.platform == 'darwin' else 'xdg-open'
                subprocess.Popen([opener, LOG_DIR])
            return {'ok': True, 'path': LOG_DIR}
        except Exception as e:
            log.warning('Open Logs failed: %s', e)
            return {'ok': False, 'message': str(e), 'path': LOG_DIR}

    def pick_folder(self) -> Optional[str]:
        """Best-effort native folder picker on Linux via zenity/kdialog. Returns
        the chosen path, or None if the operator cancelled or no picker exists —
        the page keeps a plain text field either way.
        TODO(port): there is no Blink/JS way to hand the server a *folder path*
        (a file input yields file blobs, not a server-side path), so this shells
        out to zenity or kdialog when present; without one, the operator types or
        pastes the export folder path into the field."""
        import shutil
        import subprocess
        for cmd in (['zenity', '--file-selection', '--directory',
                     '--title=Select Flickr export folder'],
                    ['kdialog', '--getexistingdirectory', os.path.expanduser('~')]):
            if shutil.which(cmd[0]):
                try:
                    out = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
                    path = (out.stdout or '').strip()
                    if out.returncode == 0 and path and os.path.isdir(path):
                        return path
                    return None
                except Exception:
                    return None
        return None
# ===== SNAPSMACK EOF =====
