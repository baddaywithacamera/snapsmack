"""
Unzucker — unzucker_core.py
Tkinter-free session logic for the Instagram-export migration tool.

WHY THIS FILE EXISTS
    main.py is the Windows tkinter window: it tangles the real work (parse an
    Instagram export, connect to the SnapSmack API, upload images, create posts,
    manage trigram groups, persist a resumable job) with tkinter widgets. The
    Linux Chrome/Blink port (linux/app.py) needs that same work with NO tkinter.

    So the pure logic is factored here into one Session object that mirrors the
    tkinter App's state and handlers exactly — same parsing, same job persistence,
    same poster pipeline, same trigram reorder maths, same insecure-transport
    guard. The web page (linux/web/) is only the window; every button calls one
    of these methods through snap_blink.

    Nothing here imports tkinter. All the heavy lifting still runs through the
    tool's own existing modules (config, ig_parser, job_state, poster,
    exif_writer) — the same code the Windows build ships.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import base64
import io
import logging
import os
import queue
import shutil
import tempfile
import threading
from datetime import datetime
from typing import Dict, List, Optional
from urllib.parse import urlparse

from PIL import Image

import config as cfg_module
import ig_parser
import job_state
import poster as poster_module
from ig_parser import ParsedPost
from poster import UnzuckerClient, SiteData

log = logging.getLogger('unzucker')

# Kept in step with main.py's BUILD_VERSION. main.py imports tkinter at module
# top, so the port must not import it just to read a string.
BUILD_VERSION = "0.7.40"

GRID_COLS = 3   # Strictly three across. Always. (matches main.py)

# Server throttle options — (label, delay_seconds_as_str). Same set the tkinter
# IMPORT SETTINGS box shows as radio buttons.
THROTTLE_OPTIONS = [
    ("Full Send",       "0.0"),
    ("Fast Lane",       "0.25"),
    ("Steady",          "0.5"),
    ("Easy Does It",    "1.0"),
    ("Sunday Driver",   "2.0"),
    ("Pump da Brakes",  "5.0"),
    ("Grandma's Pace",  "10.0"),
    ("Geological Time", "30.0"),
]


# ---------------------------------------------------------------------------
# Small image helpers (return data: URIs so the page can show local files that
# live OUTSIDE the served web dir — the Blink server only serves web_dir).
# ---------------------------------------------------------------------------

def _img_data_uri(pil_img: Image.Image) -> str:
    buf = io.BytesIO()
    pil_img.convert('RGB').save(buf, 'JPEG', quality=82)
    return 'data:image/jpeg;base64,' + base64.b64encode(buf.getvalue()).decode('ascii')


# ---------------------------------------------------------------------------
# Session — one migration session's state + handlers. Mirrors tkinter App.
# ---------------------------------------------------------------------------

class Session:
    """Everything the tkinter App held, minus the widgets."""

    def __init__(self):
        self.config: dict = cfg_module.load()
        self.client: Optional[UnzuckerClient] = None
        self.site_data: Optional[SiteData] = None
        self.posts: List[ParsedPost] = []
        self.status: Dict[int, str] = {}     # idx -> pending|ok|error|skip
        self.export_folder: str = ''
        self.job: Optional[job_state.JobState] = None
        self._stats: dict = {}

        # Trigram grouping — same shapes as main.py
        self.tg_groups: list = []            # [{indices,slots,orientation,num}]
        self.tg_selection: list = []
        self.tg_group_ctr: int = 0

        # Migration
        self.posting: bool = False
        self.queue: "queue.Queue" = queue.Queue()

        # Security: only these on-disk paths may be turned into thumbnails.
        self._image_paths: set = set()

    # ------------------------------------------------------------------
    # Serialisation helpers
    # ------------------------------------------------------------------

    def _tg_lookup(self) -> Dict[int, tuple]:
        """idx -> (group_num, slot) for every cell in a locked trigram."""
        out: Dict[int, tuple] = {}
        for grp in self.tg_groups:
            for idx, slot in zip(grp['indices'], grp['slots']):
                out[idx] = (grp['num'], slot)
        return out

    def _cell(self, i: int, tg: Optional[Dict[int, tuple]] = None) -> dict:
        if tg is None:
            tg = self._tg_lookup()
        p = self.posts[i]
        num, slot = tg.get(i, (0, 0))
        return {
            'index':       i,
            'post_type':   p.post_type,
            'image_count': len(p.images),
            'first_image': p.images[0] if p.images else '',
            'excluded':    bool(p.excluded),
            'status':      self.status.get(i, 'pending'),
            'tg_group':    num,
            'tg_slot':     slot,
        }

    def _cells(self) -> List[dict]:
        tg = self._tg_lookup()
        return [self._cell(i, tg) for i in range(len(self.posts))]

    def _trigrams_out(self) -> List[dict]:
        return [
            {'num': g['num'], 'indices': list(g['indices']), 'slots': list(g['slots'])}
            for g in self.tg_groups
        ]

    def _active_count(self) -> int:
        return sum(1 for p in self.posts if not p.excluded)

    # ------------------------------------------------------------------
    # load_state — everything the page needs on open
    # ------------------------------------------------------------------

    def load_state(self) -> dict:
        c = self.config
        return {
            'build':            BUILD_VERSION,
            'keyring_ok':       cfg_module.has_keyring(),
            'throttle_options': [{'label': l, 'value': v} for l, v in THROTTLE_OPTIONS],
            'connected':        self.client is not None,
            'config': {
                'url':            c.get('url', ''),
                'api_key':        c.get('api_key', ''),
                'export_folder':  c.get('export_folder', ''),
                'copyright_text': c.get('copyright_text', ''),
                'import_delay':   c.get('import_delay', '0.5'),
                'offpeak_only':   str(c.get('offpeak_only', 'false')).lower() == 'true',
                'peak_start':     c.get('peak_start', '9'),
                'peak_end':       c.get('peak_end', '23'),
            },
        }

    # ------------------------------------------------------------------
    # save_config — mirrors App._save_config
    # ------------------------------------------------------------------

    def save_config(self, data: dict) -> dict:
        cfg_module.save({
            'url':             (data.get('url') or '').strip(),
            'api_key':         data.get('api_key') or '',
            'export_folder':   (data.get('export_folder') or '').strip(),
            'copyright_text':  (data.get('copyright_text') or '').strip(),
            'import_delay':    data.get('import_delay') or '0.5',
            'offpeak_only':    'true' if data.get('offpeak_only') else 'false',
            'peak_start':      str(data.get('peak_start') or '9'),
            'peak_end':        str(data.get('peak_end') or '23'),
            # TODO(port): a Chrome --app window does not expose its geometry to
            # Python the way tkinter did, so window size/position is not
            # persisted here. Kept blank/normal so the ini format is unchanged.
            'window_state':    'normal',
            'window_geometry': '',
        })
        self.config = cfg_module.load()
        return {'ok': True}

    # ------------------------------------------------------------------
    # connect — mirrors App._on_connect (transport guard + ping + site data)
    # ------------------------------------------------------------------

    def connect(self, url: str, api_key: str, allow_insecure: bool = False) -> dict:
        url = (url or '').strip()
        api_key = (api_key or '').strip()
        if not url or not api_key:
            raise RuntimeError("Fill in Site URL and API Key.")

        # Transport guard (SECAUDIT 041): the Bearer key must not go out over
        # plain http. https -> allowed silently; anything else -> warn-and-confirm
        # (a scoped key, not an account password), fail CLOSED until confirmed.
        if not url.lower().startswith('https://') and not allow_insecure:
            return {'needs_confirm': True, 'reason': 'not_https', 'url': url}

        client = UnzuckerClient(url, api_key)
        ok, msg = client.ping()
        if not ok:
            raise RuntimeError(msg)

        site_data = client.fetch_site_data()
        self.client = client
        self.site_data = site_data

        cats = sorted(site_data._cat_display.values())
        albums = sorted(site_data._album_display.values())
        log.info(f"Connected to {url} — {len(cats)} cats, {len(albums)} albums")

        # Persist creds/settings on a successful connect, like the tkinter app.
        self.save_config({
            'url': url, 'api_key': api_key,
            'export_folder':  self.config.get('export_folder', ''),
            'copyright_text': self.config.get('copyright_text', ''),
            'import_delay':   self.config.get('import_delay', '0.5'),
            'offpeak_only':   str(self.config.get('offpeak_only', 'false')).lower() == 'true',
            'peak_start':     self.config.get('peak_start', '9'),
            'peak_end':       self.config.get('peak_end', '23'),
        })

        return {
            'connected': True,
            'cats': len(cats),
            'albums': len(albums),
            'message': msg,
        }

    # ------------------------------------------------------------------
    # parse_export — first half of App._on_parse (parse + detect job)
    # ------------------------------------------------------------------

    def parse_export(self, export_folder: str) -> dict:
        export_folder = (export_folder or '').strip()
        if not export_folder:
            raise RuntimeError("Select an Instagram export folder.")

        result = ig_parser.parse(export_folder)
        if not result.posts:
            raise RuntimeError("No valid posts found in the export.")

        self.posts = result.posts
        self.export_folder = export_folder
        self._stats = result.stats
        self.status = {}
        self._image_paths = {img for p in self.posts for img in p.images}

        existing = job_state.JobState.find_for_folder(export_folder)
        existing_out = None
        if existing and existing.has_progress:
            existing_out = {
                'job_name': existing.job_name,
                'upload_count': existing.upload_count,
            }

        return {
            'stats': result.stats,
            'errors': result.errors,
            'existing_job': existing_out,
            'suggested_job_name': job_state.parse_job_name(export_folder),
        }

    # ------------------------------------------------------------------
    # begin_job — second half of App._on_parse (job setup + restore + load)
    # ------------------------------------------------------------------

    def begin_job(self, resume: bool, job_name: str, url: str = '') -> dict:
        if not self.posts:
            raise RuntimeError("Parse an export first.")

        existing = job_state.JobState.find_for_folder(self.export_folder)
        self._clear_trigram_state()
        self.status = {}

        if existing and resume and existing.has_progress:
            self.job = existing
            # Replay saved ordering so indices line up with saved trigrams.
            if self.job.ordering:
                orig_map = {p.original_index: p for p in self.posts}
                reordered = [orig_map[oi] for oi in self.job.ordering if oi in orig_map]
                seen = set(self.job.ordering)
                reordered += [p for p in self.posts if p.original_index not in seen]
                self.posts = reordered
        else:
            if existing:
                existing.delete()
            name = (job_name or '').strip() or \
                job_state.parse_job_name(self.export_folder) or \
                (os.path.basename(self.export_folder.rstrip('/\\')) or 'job')
            site_url = (url or self.config.get('url', '') or '').strip()
            self.job = job_state.JobState(name, self.export_folder, site_url)
            self.job.save()
            resume = False

        # Restore trigram group counter + groups from persisted job
        if self.job.trigrams:
            self.tg_group_ctr = max(g['num'] for g in self.job.trigrams)
            for grp in self.job.trigrams:
                self.tg_groups.append(dict(grp))

        # Restore persisted cell state (excluded / uploaded), mirroring
        # PostGrid.restore_state.
        if resume:
            for idx in self.job.excluded:
                if 0 <= idx < len(self.posts):
                    self.posts[idx].excluded = True
            for idx in self.job.uploaded:
                if 0 <= idx < len(self.posts):
                    self.status[idx] = 'ok'

        uploaded_count = len(self.job.uploaded)
        s = self._stats
        log.info(
            f"[{self.job.job_name}] Parsed {s.get('total_posts', 0)} posts from "
            f"{self.export_folder}"
            + (f" — resuming, {uploaded_count} already done" if resume else "")
        )

        return {
            'posts':     self._cells(),
            'stats':     s,
            'trigrams':  self._trigrams_out(),
            'job_name':  self.job.job_name,
            'resumed':   resume,
            'progress':  {'done': uploaded_count, 'total': len(self.posts)},
        }

    # ------------------------------------------------------------------
    # detail — one post's full detail (App._on_cell_click / PostDetail.show)
    # ------------------------------------------------------------------

    def detail(self, index: int) -> dict:
        if not (0 <= index < len(self.posts)):
            raise RuntimeError("No such post.")
        p = self.posts[index]
        dt = datetime.utcfromtimestamp(p.ig_timestamp)
        return {
            'index':       index,
            'total':       len(self.posts),
            'post_type':   p.post_type,
            'image_count': len(p.images),
            'caption':     p.caption,
            'hashtags':    list(p.hashtags),
            'date':        dt.strftime('%B %d, %Y  %H:%M'),
            'images':      list(p.images),
            'excluded':    bool(p.excluded),
        }

    # ------------------------------------------------------------------
    # validate — App._on_validate
    # ------------------------------------------------------------------

    def validate(self) -> dict:
        if not self.posts:
            raise RuntimeError("Parse an export first.")
        issues = []
        for post in self.posts:
            if post.excluded:
                continue
            for img in post.images:
                if not os.path.isfile(img):
                    issues.append(f"Missing: {img}")
        active = self._active_count()
        return {'ok': not issues, 'active_count': active, 'issues': issues}

    # ------------------------------------------------------------------
    # toggle_exclude — right-click "exclude/include" on a cell
    # ------------------------------------------------------------------

    def toggle_exclude(self, index: int) -> dict:
        if not (0 <= index < len(self.posts)):
            raise RuntimeError("No such post.")
        p = self.posts[index]
        p.excluded = not p.excluded
        if self.job:
            excluded = {i for i, q in enumerate(self.posts) if q.excluded}
            self.job.set_excluded(excluded)
        return {'index': index, 'excluded': p.excluded, 'active_count': self._active_count()}

    # ------------------------------------------------------------------
    # Trigram grouping — mirrors App._on_ctrl_click and friends
    # ------------------------------------------------------------------

    def trigram_select(self, index: int) -> dict:
        if self.posting:
            return {'noop': True}

        # Already locked in a group? Ctrl+click removes that group.
        for grp in self.tg_groups:
            if index in grp['indices']:
                return self.remove_trigram(index)

        if index in self.tg_selection:
            self.tg_selection.remove(index)
            return {'selection': list(self.tg_selection)}

        if len(self.tg_selection) >= 3:
            return {'error': "A trigram needs exactly 3 posts. Deselect one first.",
                    'selection': list(self.tg_selection)}

        self.tg_selection.append(index)

        if len(self.tg_selection) == 3:
            indices = list(self.tg_selection)
            panel = [{'index': i, 'first_image': self.posts[i].images[0] if self.posts[i].images else ''}
                     for i in indices]
            # Clear the accumulating selection now the panel owns these 3.
            self.tg_selection = []
            return {'open_panel': True, 'panel': panel, 'indices': indices}

        return {'selection': list(self.tg_selection)}

    def lock_trigram(self, indices: list, slots: Optional[list] = None) -> dict:
        """indices arrive in L/M/R order from the panel; slots default [1,2,3]."""
        indices = [int(i) for i in indices]
        slots = [int(s) for s in (slots or [1, 2, 3])]
        self.tg_group_ctr += 1
        self.tg_groups.append({
            'indices': indices, 'slots': slots,
            'orientation': 'h', 'num': self.tg_group_ctr,
        })
        # Reorder so the three land in a row-aligned L/M/R run (App._reorder…).
        self._reorder_posts_for_trigram(indices)
        if self.job:
            self.job.save_trigrams(self.tg_groups)
        new_idx = self.tg_groups[-1]['indices']
        status = (f"Trigram T{self.tg_group_ctr} locked "
                  f"({new_idx[0] + 1}, {new_idx[1] + 1}, {new_idx[2] + 1}).")
        return {'posts': self._cells(), 'trigrams': self._trigrams_out(), 'status': status}

    def _reorder_posts_for_trigram(self, lmr_indices: list):
        old_posts = list(self.posts)
        lmr_set = set(lmr_indices)
        row_start = (min(lmr_indices) // GRID_COLS) * GRID_COLS
        other_posts = [p for i, p in enumerate(old_posts) if i not in lmr_set]
        lmr_posts = [old_posts[i] for i in lmr_indices]
        lmr_before = sum(1 for i in lmr_indices if i < row_start)
        adj_start = row_start - lmr_before
        new_posts = other_posts[:adj_start] + lmr_posts + other_posts[adj_start:]

        new_idx_map = {id(p): ni for ni, p in enumerate(new_posts)}
        for grp in self.tg_groups:
            grp['indices'] = [new_idx_map[id(old_posts[oi])] for oi in grp['indices']]

        # Carry per-index status across the reshuffle by post identity.
        old_status_by_id = {id(old_posts[i]): st for i, st in self.status.items()
                            if i < len(old_posts)}
        self.posts = new_posts
        self.status = {ni: old_status_by_id[id(p)]
                       for ni, p in enumerate(new_posts) if id(p) in old_status_by_id}

        if self.job:
            self.job.save_ordering([p.original_index for p in self.posts])

    def remove_trigram(self, index: int) -> dict:
        for grp in list(self.tg_groups):
            if index in grp['indices']:
                cleared = list(grp['indices'])
                self.tg_groups.remove(grp)
                if self.job:
                    self.job.save_trigrams(self.tg_groups)
                return {'removed': True, 'cleared_indices': cleared,
                        'trigrams': self._trigrams_out()}
        return {'removed': False, 'trigrams': self._trigrams_out()}

    def _clear_trigram_state(self):
        self.tg_groups.clear()
        self.tg_selection.clear()
        self.tg_group_ctr = 0

    # ------------------------------------------------------------------
    # Migration — App._on_post / _post_thread / _poll_queue
    # ------------------------------------------------------------------

    def migration_preview(self) -> dict:
        if not self.posts:
            return {'error': "Parse an export first."}
        if not self.client or not self.site_data:
            return {'error': "Click Connect first."}
        active = [p for p in self.posts if not p.excluded]
        if not active:
            return {'error': "All posts are excluded."}

        # Which trigram groups would actually link (none excluded).
        active_orig = [(i, p) for i, p in enumerate(self.posts) if not p.excluded]
        orig_to_active = {orig: act for act, (orig, _) in enumerate(active_orig)}
        tg_count = 0
        for grp in self.tg_groups:
            if all(orig_to_active.get(i, -1) >= 0 for i in grp['indices']):
                tg_count += 1

        u = (self.config.get('url') or '').strip()
        dest = urlparse(u if "://" in u else "https://" + u).netloc or u or "your site"
        return {'count': len(active), 'dest': dest, 'trigram_count': tg_count}

    def start_migration(self) -> dict:
        if self.posting:
            return {'error': "Already migrating."}
        if not self.posts:
            return {'error': "Parse an export first."}
        if not self.client or not self.site_data:
            return {'error': "Click Connect first."}

        active_with_orig = [(i, p) for i, p in enumerate(self.posts) if not p.excluded]
        if not active_with_orig:
            return {'error': "All posts are excluded."}
        active = [p for _, p in active_with_orig]
        orig_to_active = {orig: act for act, (orig, _) in enumerate(active_with_orig)}

        remapped_groups = []
        for grp in self.tg_groups:
            mapped = [orig_to_active.get(i, -1) for i in grp['indices']]
            if any(m < 0 for m in mapped):
                continue
            remapped_groups.append({
                'indices': mapped, 'slots': grp['slots'], 'orientation': grp['orientation'],
            })

        count = len(active)
        try:
            post_delay = float(self.config.get('import_delay', '0.5'))
        except (ValueError, TypeError):
            post_delay = 0.5
        offpeak_only = str(self.config.get('offpeak_only', 'false')).lower() == 'true'
        try:
            peak_start = int(self.config.get('peak_start', '9'))
        except (ValueError, TypeError):
            peak_start = 9
        try:
            peak_end = int(self.config.get('peak_end', '23'))
        except (ValueError, TypeError):
            peak_end = 23

        self.posting = True
        staging_dir = tempfile.mkdtemp(prefix='unzucker_')
        t = threading.Thread(
            target=self._post_thread,
            args=(active, staging_dir, count, remapped_groups,
                  post_delay, offpeak_only, peak_start, peak_end),
            daemon=True,
        )
        t.start()
        return {'total': count}

    def _post_thread(self, posts, staging_dir, total, trigram_groups,
                     post_delay, offpeak_only, peak_start, peak_end):
        def on_progress(current, total, result):
            self.queue.put(('progress', current, total, result))

        def on_wait(resume_hour):
            self.queue.put(('waiting', resume_hour))

        try:
            poster_module.run_migration(
                client=self.client,
                posts=posts,
                site_data=self.site_data,
                staging_dir=staging_dir,
                default_category='',
                default_album='',
                copyright_text=(self.config.get('copyright_text') or '').strip(),
                on_progress=on_progress,
                trigram_groups=trigram_groups or [],
                post_delay=post_delay,
                offpeak_only=offpeak_only,
                peak_start=peak_start,
                peak_end=peak_end,
                on_wait=on_wait,
            )
        except Exception as e:            # never leave the page waiting forever
            log.error(f"Migration thread crashed: {e}", exc_info=True)
            self.queue.put(('error', str(e)))
        finally:
            self.queue.put(('done', total))
            shutil.rmtree(staging_dir, ignore_errors=True)

    def poll(self) -> dict:
        """Drain migration events for the page (App._poll_queue, web-side)."""
        events = []
        while True:
            try:
                msg = self.queue.get_nowait()
            except queue.Empty:
                break
            if msg[0] == 'progress':
                _, current, total, result = msg
                status = 'ok' if result.success else 'error'
                if result.message.startswith("Skipped"):
                    status = 'skip'
                self.status[result.post_index] = status
                if self.job and result.success and getattr(result, 'post_id', 0):
                    self.job.record_uploaded(result.post_index, result.post_id)
                events.append({
                    'type': 'progress', 'current': current, 'total': total,
                    'index': result.post_index, 'status': status,
                    'success': bool(result.success), 'message': result.message,
                })
            elif msg[0] == 'waiting':
                events.append({'type': 'waiting', 'hour': msg[1]})
            elif msg[0] == 'error':
                events.append({'type': 'error', 'message': msg[1]})
            elif msg[0] == 'done':
                self.posting = False
                events.append({'type': 'done', 'total': msg[1]})
        return {'events': events, 'posting': self.posting}

    # ------------------------------------------------------------------
    # unload_job — App._unload_job
    # ------------------------------------------------------------------

    def unload_job(self) -> dict:
        if self.posting:
            raise RuntimeError("Cannot unload while a migration is running.")
        if not self.job:
            return {'ok': True}
        self.job.delete()
        self.job = None
        self.posts = []
        self.status = {}
        self._image_paths = set()
        self._clear_trigram_state()
        return {'ok': True}

    # ------------------------------------------------------------------
    # Image thumbnails / preview (data: URIs; path must be a known export image)
    # ------------------------------------------------------------------

    def _guard_path(self, path: str) -> str:
        real = os.path.abspath(path)
        # Only ever read files that came out of the parsed export.
        if real not in {os.path.abspath(p) for p in self._image_paths}:
            raise RuntimeError("Refused: path is not part of the loaded export.")
        return real

    def thumb(self, path: str, size: int = 160) -> str:
        real = self._guard_path(path)
        size = max(32, min(512, int(size)))
        with Image.open(real) as img:
            w, h = img.size
            side = min(w, h)
            left = (w - side) // 2
            top = (h - side) // 2
            img = img.crop((left, top, left + side, top + side))
            img = img.resize((size, size), Image.LANCZOS)
            return _img_data_uri(img)

    def preview(self, path: str, max_w: int = 640, max_h: int = 520) -> str:
        real = self._guard_path(path)
        with Image.open(real) as img:
            img = img.copy()
            img.thumbnail((max(64, int(max_w)), max(64, int(max_h))), Image.LANCZOS)
            return _img_data_uri(img)


# ===== SNAPSMACK EOF =====
