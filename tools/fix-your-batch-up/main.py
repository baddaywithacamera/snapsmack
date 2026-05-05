# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


BUILD_VERSION = "0.7.7a-23"   # bump this on every rebuild

"""
Fix Your Batch Up — main.py
SnapSmack Drive link recovery tool.

Pulls images missing a download_url from your SnapSmack site, matches each
server-side FTP copy against your local originals using pHash + SIFT, and
lets you confirm or override each match before uploading to Google Drive and
writing the link back to the database — one image at a time, at your pace.

CPU usage is capped at ~75 % via a sized ProcessPoolExecutor so you can keep
working while the next batch is being matched in the background.
"""

import configparser
import json
import os
import queue
import shutil
import sys
import threading
import tkinter as tk
from concurrent.futures import ProcessPoolExecutor, as_completed
from datetime import datetime
from tkinter import filedialog, messagebox, ttk
from typing import Optional

import requests
from PIL import Image, ImageTk

import local_drive

# Ensure matcher.py is importable by worker processes spawned from this dir
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from matcher import match_one, phash_file, CONF_HIGH, CONF_MED

# ---------------------------------------------------------------------------
# Colour palette — matches Smack Your Batch Up
# ---------------------------------------------------------------------------
BG_DEEP  = "#141414"
BG_CARD  = "#1C1C1C"
BG_MID   = "#050505"
BG_HOVER = "#252525"
ACCENT   = "#39FF14"
BORDER   = "#2A2A2A"

FG_MAIN  = "#EEEEEE"
FG_DIM   = "#777777"
FG_OK    = "#4EC994"
FG_ERR   = "#FF3E3E"
FG_WARN  = "#D4872A"
FG_HIGH  = "#4EC994"   # green  — high confidence
FG_MED   = "#D4872A"   # amber  — medium confidence
FG_LOW   = "#FF3E3E"   # red    — low / no match

FONT_UI    = ("Segoe UI", 9)
FONT_BOLD  = ("Segoe UI", 9, "bold")
FONT_SMALL = ("Segoe UI", 8)
FONT_TITLE = ("Segoe UI", 12, "bold")
FONT_CONF  = ("Segoe UI", 22, "bold")

WIN_W, WIN_H = 1160, 860
IMG_W        = 430    # max display width for preview images
IMG_MAX_H    = 290    # max display height (fits landscape ~3:2 nicely)
BATCH_SIZE   = 10     # images matched per batch
PAGE_SIZE    = 75     # max rows rendered at once (tkinter canvas coord limit ~32767px)


# ---------------------------------------------------------------------------
# Config helpers
# ---------------------------------------------------------------------------
# When frozen as an exe, store config next to the executable.
# When running from source, store it next to this script.
if getattr(sys, 'frozen', False):
    _APP_DIR = os.path.dirname(sys.executable)
else:
    _APP_DIR = os.path.dirname(os.path.abspath(__file__))

CONFIG_FILE   = os.path.join(_APP_DIR, 'config.ini')
RECOVERY_FILE = os.path.join(_APP_DIR, 'fybu-recovery.json')

# Default SYBU dir: prefer the known SYBU install location, but fall back to
# the exe's own directory so credentials.json and token.json dropped alongside
# the exe are picked up automatically on first run.
_SYBU_DEFAULT = r'C:\SmackYourBatchUp' if os.path.isdir(r'C:\SmackYourBatchUp') else _APP_DIR

_CONFIG_DEFAULTS = {
    'url':                '',
    'username':           '',
    'password':           '',
    'sybu_dir':           _SYBU_DEFAULT,
    'google_credentials': '',
    'drive_folder_id':    '',
    'server_folder':      '',
    'originals_folder':   '',
}


def _load_sybu_config(sybu_dir: str) -> dict:
    """Read ft-batch-poster's config.ini from sybu_dir and return relevant fields."""
    import base64
    sybu_ini = os.path.join(sybu_dir, 'config.ini')
    if not os.path.isfile(sybu_ini):
        return {}
    cp = configparser.ConfigParser()
    cp.read(sybu_ini)
    password_raw = cp.get('auth', 'password', fallback='')
    try:
        password = base64.b64decode(password_raw.encode()).decode() if password_raw else ''
    except Exception:
        password = ''
    return {
        'url':                cp.get('site', 'url', fallback=''),
        'username':           cp.get('auth', 'username', fallback=''),
        'password':           password,
        'google_credentials': cp.get('google', 'credentials_path', fallback=''),
        'drive_folder_id':    cp.get('google', 'drive_folder_id', fallback=''),
    }


def _load_config() -> dict:
    cp = configparser.ConfigParser()
    cp.read(CONFIG_FILE)
    s = cp['fybu'] if 'fybu' in cp else {}
    cfg = {k: s.get(k, v) for k, v in _CONFIG_DEFAULTS.items()}

    # Bootstrap from SYBU config.ini for fields not yet set in our own config.
    sybu_dir = cfg.get('sybu_dir', '').strip()
    if sybu_dir:
        sybu = _load_sybu_config(sybu_dir)
        for key in ('url', 'username', 'password', 'google_credentials', 'drive_folder_id'):
            if not cfg.get(key) and sybu.get(key):
                cfg[key] = sybu[key]

    return cfg


def _save_config(data: dict) -> None:
    cp = configparser.ConfigParser()
    cp['fybu'] = {k: data.get(k, '') for k in _CONFIG_DEFAULTS}
    with open(CONFIG_FILE, 'w') as f:
        cp.write(f)


# ---------------------------------------------------------------------------
# Filename helper — borrowed from SYBU's poster.py haiku_to_filename()
# ---------------------------------------------------------------------------
def _haiku_to_filename(title: str, ext: str) -> str:
    """Sanitize a haiku title into a safe filename, preserving spaces and commas."""
    invalid = r'\/:*?"<>|'
    clean = ''.join(c for c in title if c not in invalid).strip()
    if not clean:
        clean = 'untitled'
    return f"{clean}{ext}"


# ---------------------------------------------------------------------------
# Backfill API client
# ---------------------------------------------------------------------------
class BackfillClient:
    """Minimal HTTP client for smack-backfill.php."""

    def __init__(self, base_url: str):
        self.base_url  = base_url.rstrip('/')
        self.session   = requests.Session()
        self.session.headers['User-Agent'] = 'FixYourBatchUp/0.7.7'
        self._username = ''
        self._password = ''

    def login(self, username: str, password: str) -> None:
        r = self.session.post(
            f'{self.base_url}/login.php',
            data={'username': username, 'password': password},
            allow_redirects=True,
            timeout=15,
        )
        r.raise_for_status()
        if 'login.php' in r.url:
            raise RuntimeError('Login failed — check your username and password.')
        # Stash credentials so we can silently re-login if the session expires
        self._username = username
        self._password = password

    def is_session_alive(self) -> bool:
        """Lightweight check — mirrors SYBU's poster.py is_session_alive()."""
        try:
            r = self.session.get(
                f'{self.base_url}/smack-admin.php',
                timeout=10,
                allow_redirects=True,
            )
            return 'login.php' not in r.url
        except Exception:
            return False

    def _ensure_session(self) -> None:
        """Re-login if the PHP session has expired. Raises if credentials are missing."""
        if not self.is_session_alive():
            if not self._username:
                raise RuntimeError(
                    'Session expired and no credentials are stored — '
                    'please click Connect + Pull to log in again.'
                )
            self.login(self._username, self._password)

    def fetch_missing(self) -> list:
        r = self.session.get(
            f'{self.base_url}/smack-backfill.php',
            params={'action': 'list'},
            timeout=20,
        )
        r.raise_for_status()
        data = r.json()
        if not data.get('ok'):
            raise RuntimeError(data.get('error', 'API error'))
        return data['images']

    def update_link(self, snap_id: int, download_url: str) -> None:
        # Silently refresh the PHP session if it has expired between
        # Connect+Pull and the time the user clicks Upload.
        self._ensure_session()
        r = self.session.post(
            f'{self.base_url}/smack-backfill.php',
            data={'action': 'update', 'snap_id': snap_id, 'download_url': download_url},
            timeout=15,
        )
        r.raise_for_status()
        try:
            data = r.json()
        except Exception:
            raise RuntimeError(
                f'Server returned non-JSON (likely a login redirect). '
                f'Response: {r.text[:200]}'
            )
        if not data.get('ok'):
            raise RuntimeError(data.get('error', 'API error'))


# ---------------------------------------------------------------------------
# Image loading helper
# ---------------------------------------------------------------------------
def _load_thumb(path: str, max_w: int = IMG_W, max_h: int = IMG_MAX_H):
    """Load an image from disk and return a PhotoImage scaled to fit."""
    try:
        img = Image.open(path).convert('RGB')
        w, h = img.size
        scale = min(max_w / w, max_h / h, 1.0)
        img = img.resize((max(1, int(w * scale)), max(1, int(h * scale))),
                         Image.LANCZOS)
        return ImageTk.PhotoImage(img)
    except Exception:
        return None


def _fmt_date(iso: str) -> str:
    """Format '2026-03-18 14:23:45' → 'Mar 18, 2026'."""
    try:
        return datetime.strptime(iso[:10], '%Y-%m-%d').strftime('%b %-d, %Y')
    except Exception:
        try:
            return datetime.strptime(iso[:10], '%Y-%m-%d').strftime('%b %d, %Y').replace(' 0', ' ')
        except Exception:
            return iso[:10]


# ---------------------------------------------------------------------------
# Match row widget
# ---------------------------------------------------------------------------
class MatchRow(tk.Frame):
    """
    One row in the review queue.

    Left panel  : server image (FTP copy) + title + date
    Middle panel: confidence score + match count
    Right panel : matched original + Upload / Pick Different / Skip buttons
    """

    def __init__(self, parent, record: dict, result: dict, app: 'App'):
        super().__init__(parent, bg=BG_CARD, highlightthickness=1,
                         highlightbackground=BORDER)
        self.record       = record
        self.result       = result
        self.app          = app
        self._orig_photo  = None   # keep reference
        self._srv_photo   = None
        self._cancel_evt  = threading.Event()
        self._approved_var = tk.BooleanVar(value=False)

        self._build()

    def _build(self):
        # ── Left: server image ────────────────────────────────────────────
        left = tk.Frame(self, bg=BG_CARD, width=IMG_W + 10)
        left.pack(side='left', fill='y', padx=(8, 4), pady=8)
        left.pack_propagate(False)

        srv_path = self._server_local_path()
        self._blank_srv_img = tk.PhotoImage(width=1, height=1)
        self._srv_lbl = tk.Label(left, bg=BG_MID,
                                 width=IMG_W, height=IMG_MAX_H,
                                 image=self._blank_srv_img,
                                 relief='flat')
        self._srv_lbl.pack(fill='x')
        if srv_path:
            photo = _load_thumb(srv_path)
            if photo:
                self._srv_photo = photo
                self._srv_lbl.configure(image=photo, width=photo.width(),
                                        height=photo.height())
            else:
                self._srv_lbl.configure(
                    text="Preview unavailable", fg=FG_DIM,
                    font=FONT_UI, compound='center')

        title = self.record.get('img_title', 'Untitled')[:60]
        date  = _fmt_date(self.record.get('img_date', ''))
        tk.Label(left, text=title, bg=BG_CARD, fg=FG_MAIN,
                 font=FONT_BOLD, wraplength=IMG_W, justify='left',
                 anchor='w').pack(fill='x', pady=(4, 0))
        tk.Label(left, text=date, bg=BG_CARD, fg=FG_DIM,
                 font=FONT_SMALL, anchor='w').pack(fill='x')

        # ── Middle: confidence ────────────────────────────────────────────
        mid = tk.Frame(self, bg=BG_CARD, width=170)
        mid.pack(side='left', fill='y', padx=8)
        mid.pack_propagate(False)

        conf      = self.result.get('confidence', 0.0)
        label_key = self.result.get('label', 'none')
        conf_color = {
            'high':   FG_HIGH,
            'medium': FG_MED,
            'low':    FG_LOW,
            'none':   FG_DIM,
        }.get(label_key, FG_DIM)

        conf_pct = f"{int(conf * 100)}%" if conf > 0 else "—"
        tk.Label(mid, text=conf_pct, bg=BG_CARD, fg=conf_color,
                 font=FONT_CONF).pack(pady=(60, 0))
        tk.Label(mid, text="confidence", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_SMALL).pack()

        match_n = self.result.get('match_count', 0)
        if match_n:
            tk.Label(mid, text=f"{match_n} keypoints", bg=BG_CARD,
                     fg=FG_DIM, font=FONT_SMALL).pack(pady=(4, 0))

        label_text = {
            'high':   "Auto-matched",
            'medium': "Review suggested",
            'low':    "Weak match",
            'none':   "No match found",
        }.get(label_key, "")
        tk.Label(mid, text=label_text, bg=BG_CARD, fg=conf_color,
                 font=FONT_SMALL, wraplength=160, justify='center').pack(pady=(6, 0))

        # ── Right: original + buttons ─────────────────────────────────────
        right = tk.Frame(self, bg=BG_CARD, width=IMG_W + 120)
        right.pack(side='left', fill='both', expand=True, padx=(4, 8), pady=8)

        orig_frame = tk.Frame(right, bg=BG_CARD)
        orig_frame.pack(side='top', fill='x')

        # A 1×1 blank image anchors the label to pixel dimensions so that
        # width=IMG_W / height=IMG_MAX_H are always interpreted as pixels,
        # not character units (the tkinter default when no image is set).
        self._blank_img = tk.PhotoImage(width=1, height=1)
        self._orig_lbl = tk.Label(orig_frame, bg=BG_MID,
                                  width=IMG_W, height=IMG_MAX_H,
                                  image=self._blank_img,
                                  relief='flat')
        self._orig_lbl.pack(side='left')

        orig_path = self.result.get('match_path')
        if not orig_path:
            self._orig_lbl.configure(
                text="No match found\nClick 'Pick Different'",
                fg=FG_DIM, font=FONT_UI, compound='center')
        elif not os.path.isfile(orig_path):
            self._orig_lbl.configure(
                text="File moved or deleted\nClick 'Pick Different'",
                fg=FG_WARN, font=FONT_UI, compound='center')

        self._orig_name_lbl = tk.Label(right, text=self._orig_basename(),
                                       bg=BG_CARD, fg=FG_DIM,
                                       font=FONT_SMALL, anchor='w')
        self._orig_name_lbl.pack(fill='x', pady=(4, 6))

        if orig_path and os.path.isfile(orig_path):
            self._set_orig_image(orig_path)

        # Buttons
        btn_row = tk.Frame(right, bg=BG_CARD)
        btn_row.pack(fill='x')

        # Approve checkbox — check rows then click Upload Approved to batch-queue them.
        self._approve_chk = tk.Checkbutton(
            btn_row, text="Approve", variable=self._approved_var,
            bg=BG_CARD, fg=FG_DIM, font=FONT_UI,
            selectcolor=BG_MID, activebackground=BG_CARD,
            relief='flat', cursor='hand2',
        )
        self._approve_chk.pack(side='left', padx=(0, 8))

        self._upload_btn = tk.Button(
            btn_row, text="Upload",
            bg=ACCENT, fg="#000000", font=FONT_BOLD,
            relief='flat', cursor='hand2', width=10,
            command=self._on_upload,
        )
        self._upload_btn.pack(side='left', padx=(0, 6))

        # Cancel button stays in layout permanently — invisible until upload starts.
        # Using pack_forget/pack cycles loses insertion position in tkinter and
        # causes the button to squish to a thin line at the far right edge.
        self._cancel_btn = tk.Button(
            btn_row, text="",
            bg=BG_CARD, fg=BG_CARD, font=FONT_UI,
            relief='flat', width=8, state='disabled',
            command=self._on_cancel,
        )
        self._cancel_btn.pack(side='left', padx=(0, 6))

        tk.Button(
            btn_row, text="Pick Different",
            bg=BG_HOVER, fg=FG_MAIN, font=FONT_UI,
            relief='flat', cursor='hand2', width=14,
            command=self._on_pick,
        ).pack(side='left', padx=(0, 6))

        tk.Button(
            btn_row, text="Skip",
            bg=BG_MID, fg=FG_DIM, font=FONT_UI,
            relief='flat', cursor='hand2', width=6,
            command=self._on_skip,
        ).pack(side='left')

    # ── Helpers ───────────────────────────────────────────────────────────

    def _server_local_path(self) -> Optional[str]:
        folder_a = self.app._folder_a_var.get().strip()
        img_file = self.record.get('img_file', '')
        if not folder_a or not img_file:
            return None
        basename = os.path.basename(img_file)
        path     = os.path.join(folder_a, basename)
        return path if os.path.isfile(path) else None

    def _set_orig_image(self, path: str):
        photo = _load_thumb(path)
        if photo:
            self._orig_photo = photo
            self._orig_lbl.configure(image=photo, text='',
                                     width=photo.width(),
                                     height=photo.height())
        self._orig_name_lbl.configure(text=os.path.basename(path))
        self.result['match_path'] = path

    def _orig_basename(self) -> str:
        p = self.result.get('match_path', '')
        return os.path.basename(p) if p else '—'

    # ── Actions ───────────────────────────────────────────────────────────

    def _on_upload(self):
        """Validate and enqueue this row for upload. Uploads are processed serially."""
        match_path = self.result.get('match_path')
        if not match_path:
            messagebox.showwarning(
                "No original selected",
                "Use 'Pick Different' to choose the original before uploading.",
                parent=self,
            )
            return

        if not self.app._drive_service:
            messagebox.showerror(
                "Drive not connected",
                "Drive is not connected. Check the Drive status at the top of the window.",
                parent=self,
            )
            return

        if not self.app._folder_id_var.get().strip():
            messagebox.showerror(
                "Drive Folder ID missing",
                "Drive Folder ID is empty — files would upload to the root of your Drive.\n\n"
                "Paste the destination folder ID into the 'Drive Folder ID' field and "
                "click Auth Drive before uploading.",
                parent=self,
            )
            return

        self._upload_btn.configure(text="Queued…", state='disabled',
                                   bg=FG_DIM, fg="#000000")
        self._approved_var.set(False)
        self.app._enqueue_upload(self)

    def _start_upload(self, done_evt: threading.Event):
        """Called by the drain thread (via self.after) to begin the actual upload."""
        match_path = self.result.get('match_path')
        if not match_path or not os.path.isfile(match_path):
            # File was moved or removed since queuing — skip silently
            self._upload_btn.configure(text="Upload", state='normal',
                                       bg=ACCENT, fg="#000000")
            done_evt.set()
            return

        self._cancel_evt.clear()
        self._upload_btn.configure(text="Uploading…", state='disabled',
                                   bg=FG_WARN, fg="#000000")
        self._cancel_btn.configure(text="Cancel", bg=BG_MID, fg=FG_DIM,
                                   state='normal', cursor='hand2')
        self.update_idletasks()

        snap_id    = int(self.record['snap_id'])
        folder_id  = self.app._folder_id_var.get().strip() or None
        drive_svc  = self.app._drive_service
        client     = self.app._client
        orig_path  = match_path
        cancel_evt = self._cancel_evt

        raw_title   = self.record.get('img_title', '').strip()
        _, _ext     = os.path.splitext(orig_path)
        drive_fname = (_haiku_to_filename(raw_title, _ext.lower())
                       if raw_title else os.path.basename(orig_path))

        def _worker():
            import socket
            _prev_timeout = socket.getdefaulttimeout()
            socket.setdefaulttimeout(180)
            try:
                import local_drive as ld
                drive_url = ld.upload(drive_svc, orig_path,
                                      drive_fname,
                                      folder_id=folder_id)
                if cancel_evt.is_set():
                    self.after(0, self._mark_cancelled)
                    return
                client.update_link(snap_id, drive_url)
                self.after(0, lambda: self._mark_done(drive_url))
            except Exception as exc:
                self.after(0, lambda: self._mark_error(str(exc)))
            finally:
                socket.setdefaulttimeout(_prev_timeout)
                done_evt.set()   # always signal drain thread regardless of outcome

        threading.Thread(target=_worker, daemon=True).start()

    def _mark_done(self, drive_url: str):
        self.result['_uploaded'] = True
        self._cancel_btn.configure(text="", bg=BG_CARD, fg=BG_CARD, state='disabled')
        self._upload_btn.configure(text="✓ Done", bg=FG_OK, fg="#000000",
                                   state='disabled')
        self.configure(highlightbackground=FG_OK)
        self.app._on_row_done()
        self._move_original_to_done()
        self.app._remove_row(self)          # strip from _all_rows + save recovery
        self.after(1500, self._dismiss)     # auto-dismiss after user can see Done state

    def _move_original_to_done(self):
        """Move the matched original file into Folder B/DONE/ to reduce clutter."""
        match_path = self.result.get('match_path')
        if not match_path or not os.path.isfile(match_path):
            return
        folder_b = self.app._folder_b_var.get().strip()
        if not folder_b:
            return
        done_dir = os.path.join(folder_b, 'DONE')
        try:
            os.makedirs(done_dir, exist_ok=True)
            dest = os.path.join(done_dir, os.path.basename(match_path))
            if os.path.exists(dest):
                # Avoid collision: suffix with snap_id
                base, ext = os.path.splitext(os.path.basename(match_path))
                dest = os.path.join(done_dir,
                                    f"{base}_{self.record.get('snap_id', 0)}{ext}")
            shutil.move(match_path, dest)
        except Exception:
            pass   # file move failure should never block the upload result

    def _dismiss(self):
        """Remove this widget from the canvas after it's been marked done."""
        try:
            self.destroy()
        except Exception:
            pass
        self.app._inner.update_idletasks()
        self.app._canvas.configure(
            scrollregion=self.app._canvas.bbox('all'))

    def _mark_cancelled(self):
        """Upload completed on Drive side but user cancelled — reset for retry."""
        self._cancel_btn.configure(text="", bg=BG_CARD, fg=BG_CARD, state='disabled')
        self._upload_btn.configure(text="Upload", bg=ACCENT, fg="#000000",
                                   state='normal')
        self.configure(highlightbackground=BORDER)

    def _on_cancel(self):
        self._cancel_evt.set()
        self._cancel_btn.configure(text="Cancelling…", state='disabled')

    def _mark_error(self, msg: str):
        self._cancel_btn.configure(text="", bg=BG_CARD, fg=BG_CARD, state='disabled')
        self._upload_btn.configure(text="Error — retry", bg=FG_ERR,
                                   fg="#000000", state='normal')
        messagebox.showerror("Upload failed", msg, parent=self)

    def _on_pick(self):
        folder_b = self.app._folder_b_var.get().strip()
        path = filedialog.askopenfilename(
            title="Choose the original for this image",
            initialdir=folder_b if folder_b and os.path.isdir(folder_b) else None,
            filetypes=[('Image files', '*.jpg *.jpeg *.png *.webp *.JPG *.JPEG *.PNG'),
                       ('All files', '*.*')],
            parent=self,
        )
        if path:
            self._set_orig_image(path)
            # Reset upload button in case it was in an error state
            self._upload_btn.configure(text="Upload", state='normal',
                                       bg=ACCENT, fg="#000000")

    def _on_skip(self):
        self.app._on_row_done()
        self.app._remove_row(self)   # strip from _all_rows + save recovery
        self.after(0, self._dismiss) # remove widget immediately


# ---------------------------------------------------------------------------
# Main application window
# ---------------------------------------------------------------------------
class App(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title("FIX YOUR BATCH UP — Drive Link Recovery")
        self.geometry(f"{WIN_W}x{WIN_H}")
        self.minsize(WIN_W, 600)
        self.configure(bg=BG_DEEP)

        self._config       = _load_config()
        self._client: Optional[BackfillClient] = None
        self._drive_service                    = None
        self._records: list       = []   # full list from API
        self._row_widgets: list   = []
        self._all_rows: list      = []   # accumulated (rec, result) pairs for recovery
        self._unrendered_rows: list = [] # rows waiting for next page load
        self._load_more_btn       = None # "Load more" button widget
        self._pending_count       = 0
        self._done_count          = 0
        self._matching            = False

        # Serial upload queue — prevents race conditions when multiple rows
        # are submitted at once.  All uploads go through this queue and are
        # processed one at a time by a single background drain thread.
        self._upload_queue   = queue.Queue()
        self._upload_running = False

        # Apply saved token base before any is_authenticated() check
        sybu = self._config.get('sybu_dir', r'C:\SmackYourBatchUp').strip()
        if sybu:
            local_drive.set_token_base(sybu)

        self._build_ui()
        self._load_config_to_ui()
        self.after(200, self._auto_reconnect)
        self.after(300, self._check_recovery_on_launch)

    # ── UI construction ───────────────────────────────────────────────────

    def _build_ui(self):
        # ── Top bar ────────────────────────────────────────────────────────
        top = tk.Frame(self, bg=BG_DEEP, pady=10)
        top.pack(fill='x', padx=16)

        title_frame = tk.Frame(top, bg=BG_DEEP)
        title_frame.pack(side='left')
        tk.Label(title_frame, text="FIX YOUR BATCH UP",
                 bg=BG_DEEP, fg=ACCENT, font=FONT_TITLE).pack(side='left')
        tk.Label(title_frame, text=f"  {BUILD_VERSION}",
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL).pack(side='left', pady=(4, 0))
        self._cfg_collapsed   = False
        self._cfg_toggle_btn  = tk.Button(
            title_frame, text="▲", bg=BG_DEEP, fg=FG_DIM,
            font=FONT_SMALL, relief='flat', cursor='hand2',
            bd=0, padx=6, pady=0,
            command=self._toggle_config,
        )
        self._cfg_toggle_btn.pack(side='left', padx=(10, 0), pady=(3, 0))

        status_frame = tk.Frame(top, bg=BG_DEEP)
        status_frame.pack(side='right')

        self._drive_dot = tk.Label(status_frame, text="●", bg=BG_DEEP, fg=FG_DIM,
                                   font=FONT_BOLD)
        self._drive_dot.pack(side='left')
        self._drive_lbl = tk.Label(status_frame, text="Drive: Not connected",
                                   bg=BG_DEEP, fg=FG_DIM, font=FONT_UI)
        self._drive_lbl.pack(side='left', padx=(2, 20))

        self._conn_dot = tk.Label(status_frame, text="●", bg=BG_DEEP, fg=FG_DIM,
                                  font=FONT_BOLD)
        self._conn_dot.pack(side='left')
        self._conn_lbl = tk.Label(status_frame, text="Site: Not connected",
                                  bg=BG_DEEP, fg=FG_DIM, font=FONT_UI)
        self._conn_lbl.pack(side='left', padx=(2, 0))

        tk.Frame(self, bg=BORDER, height=1).pack(fill='x')

        # ── Config card ────────────────────────────────────────────────────
        # Outer padding frame so the card has breathing room against the edges
        self._cfg_outer = tk.Frame(self, bg=BG_DEEP)
        self._cfg_outer.pack(fill='x', padx=12, pady=8)
        cfg_outer = self._cfg_outer

        # Card itself — slightly lighter than the window background
        cfg = tk.Frame(cfg_outer, bg=BG_CARD,
                       highlightbackground=BORDER, highlightthickness=1)
        cfg.pack(fill='x')

        # ── Folders section ────────────────────────────────────────────────
        self._folder_a_var = tk.StringVar()
        self._folder_b_var = tk.StringVar()
        self._url_var       = tk.StringVar()
        self._user_var      = tk.StringVar()
        self._pass_var      = tk.StringVar()
        self._sybu_var      = tk.StringVar()
        self._creds_var     = tk.StringVar()
        self._folder_id_var = tk.StringVar()

        folders_card = tk.Frame(cfg, bg=BG_CARD, padx=12, pady=8)
        folders_card.pack(fill='x')

        tk.Label(folders_card, text="FOLDERS", bg=BG_CARD, fg=FG_DIM,
                 font=("Segoe UI", 7, "bold")).pack(anchor='w', pady=(0, 4))

        self._make_folder_row(folders_card, "Server Images (Folder A):",
                              self._folder_a_var, self._browse_folder_a)
        self._make_folder_row(folders_card, "Originals (Folder B):",
                              self._folder_b_var, self._browse_folder_b)

        tk.Frame(cfg, bg=BORDER, height=1).pack(fill='x')

        # ── Site + Drive section ───────────────────────────────────────────
        conn_card = tk.Frame(cfg, bg=BG_CARD, padx=12, pady=8)
        conn_card.pack(fill='x')

        # Site row
        site_hdr = tk.Frame(conn_card, bg=BG_CARD)
        site_hdr.pack(fill='x', pady=(0, 4))
        tk.Label(site_hdr, text="SITE", bg=BG_CARD, fg=FG_DIM,
                 font=("Segoe UI", 7, "bold")).pack(side='left')

        site_row = tk.Frame(conn_card, bg=BG_CARD)
        site_row.pack(fill='x', pady=(0, 8))

        tk.Label(site_row, text="URL:", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_UI).pack(side='left')
        tk.Entry(site_row, textvariable=self._url_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_UI, relief='flat', width=32,
                 insertbackground=ACCENT).pack(side='left', padx=(4, 16))

        tk.Label(site_row, text="User:", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_UI).pack(side='left')
        tk.Entry(site_row, textvariable=self._user_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_UI, relief='flat', width=14,
                 insertbackground=ACCENT).pack(side='left', padx=(4, 16))

        tk.Label(site_row, text="Pass:", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_UI).pack(side='left')
        tk.Entry(site_row, textvariable=self._pass_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_UI, relief='flat', width=14, show='*',
                 insertbackground=ACCENT).pack(side='left', padx=(4, 16))

        tk.Button(site_row, text="Connect + Pull",
                  bg=ACCENT, fg="#000000", font=FONT_BOLD,
                  relief='flat', cursor='hand2',
                  command=self._on_connect_and_pull).pack(side='left')

        # Drive row
        tk.Frame(conn_card, bg=BORDER, height=1).pack(fill='x', pady=(0, 6))
        tk.Label(conn_card, text="DRIVE", bg=BG_CARD, fg=FG_DIM,
                 font=("Segoe UI", 7, "bold")).pack(anchor='w', pady=(0, 4))

        drive_row = tk.Frame(conn_card, bg=BG_CARD)
        drive_row.pack(fill='x')

        tk.Label(drive_row, text="Credentials:", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_UI).pack(side='left')
        _creds_entry = tk.Entry(drive_row, textvariable=self._creds_var,
                                bg=BG_MID, fg=FG_MAIN, font=FONT_UI,
                                relief='flat', width=38,
                                insertbackground=ACCENT)
        _creds_entry.pack(side='left', padx=(4, 2))
        _creds_entry.bind('<FocusOut>', lambda _e: self._save_config())
        tk.Button(drive_row, text="…", bg=BG_HOVER, fg=FG_MAIN, font=FONT_UI,
                  relief='flat', cursor='hand2', width=3,
                  command=self._browse_creds).pack(side='left', padx=(0, 16))

        tk.Label(drive_row, text="Folder ID:", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_UI).pack(side='left')
        _folder_entry = tk.Entry(drive_row, textvariable=self._folder_id_var,
                                 bg=BG_MID, fg=FG_MAIN, font=FONT_UI,
                                 relief='flat', width=26,
                                 insertbackground=ACCENT)
        _folder_entry.pack(side='left', padx=(4, 16))
        _folder_entry.bind('<FocusOut>', lambda _e: self._save_config())

        tk.Button(drive_row, text="Auth Drive",
                  bg=BG_HOVER, fg=FG_MAIN, font=FONT_UI,
                  relief='flat', cursor='hand2',
                  command=self._on_auth_drive).pack(side='left')

        tk.Frame(cfg, bg=BORDER, height=1).pack(fill='x')

        # ── Actions row ────────────────────────────────────────────────────
        actions_card = tk.Frame(cfg, bg=BG_CARD, padx=12, pady=8)
        actions_card.pack(fill='x')

        tk.Label(actions_card, text="SYBU Folder:", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_UI).pack(side='left')
        tk.Entry(actions_card, textvariable=self._sybu_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_UI, relief='flat', width=36,
                 insertbackground=ACCENT).pack(side='left', padx=(4, 2))
        tk.Button(actions_card, text="…", bg=BG_HOVER, fg=FG_MAIN, font=FONT_UI,
                  relief='flat', cursor='hand2', width=3,
                  command=self._browse_sybu).pack(side='left', padx=(0, 0))

        # Action buttons pushed to the right
        btn_right = tk.Frame(actions_card, bg=BG_CARD)
        btn_right.pack(side='right')

        tk.Button(
            btn_right, text="Upload Approved",
            bg=BG_HOVER, fg=FG_MAIN, font=FONT_BOLD,
            relief='flat', cursor='hand2',
            command=self._on_upload_approved,
        ).pack(side='left', padx=(0, 8))

        self._start_btn = tk.Button(
            btn_right, text="Start Matching",
            bg=ACCENT, fg="#000000", font=FONT_BOLD,
            relief='flat', cursor='hand2',
            command=self._on_start_matching,
        )
        self._start_btn.pack(side='left')

        self._cfg_bottom_border = tk.Frame(self, bg=BORDER, height=1)
        self._cfg_bottom_border.pack(fill='x')

        # ── Progress bar ───────────────────────────────────────────────────
        self._prog_frame = tk.Frame(self, bg=BG_DEEP, pady=6)
        self._prog_frame.pack(fill='x', padx=16)

        self._prog_bar = ttk.Progressbar(self._prog_frame, mode='determinate',
                                          length=WIN_W - 200)
        self._prog_bar.pack(side='left')
        self._prog_lbl = tk.Label(self._prog_frame, text="",
                                   bg=BG_DEEP, fg=FG_DIM, font=FONT_UI)
        self._prog_lbl.pack(side='left', padx=12)

        # ── Scrollable review area ─────────────────────────────────────────
        canvas_frame = tk.Frame(self, bg=BG_DEEP)
        canvas_frame.pack(fill='both', expand=True)

        self._canvas = tk.Canvas(canvas_frame, bg=BG_DEEP, highlightthickness=0,
                                  yscrollcommand=lambda *a: self._vbar.set(*a))
        self._vbar   = tk.Scrollbar(canvas_frame, orient='vertical',
                                     command=self._canvas.yview)
        self._vbar.pack(side='right', fill='y')
        self._canvas.pack(side='left', fill='both', expand=True)

        self._inner = tk.Frame(self._canvas, bg=BG_DEEP)
        self._canvas_win = self._canvas.create_window(
            (0, 0), window=self._inner, anchor='nw'
        )
        self._inner.bind('<Configure>', self._on_inner_configure)
        self._canvas.bind('<Configure>', self._on_canvas_configure)
        self._canvas.bind_all('<MouseWheel>', self._on_scroll)
        self._canvas.bind_all('<Button-4>',   self._on_scroll)
        self._canvas.bind_all('<Button-5>',   self._on_scroll)

        # ── Status bar ─────────────────────────────────────────────────────
        tk.Frame(self, bg=BORDER, height=1).pack(fill='x')
        self._status_lbl = tk.Label(self, text="Connect to site and pull records to begin.",
                                     bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL, anchor='w')
        self._status_lbl.pack(fill='x', padx=16, pady=4)

    def _make_folder_row(self, parent, label, var, cmd):
        row = tk.Frame(parent, bg=BG_CARD)
        row.pack(fill='x', pady=2)
        tk.Label(row, text=label, bg=BG_CARD, fg=FG_DIM, font=FONT_UI,
                 width=24, anchor='e').pack(side='left')
        tk.Entry(row, textvariable=var, bg=BG_MID, fg=FG_MAIN, font=FONT_UI,
                 relief='flat', width=62,
                 insertbackground=ACCENT).pack(side='left', padx=(4, 4))
        tk.Button(row, text="Browse…", bg=BG_HOVER, fg=FG_MAIN, font=FONT_UI,
                  relief='flat', cursor='hand2', command=cmd).pack(side='left')

    # ── Config panel collapse / expand ────────────────────────────────────

    def _toggle_config(self):
        if self._cfg_collapsed:
            self._expand_config()
        else:
            self._collapse_config()

    def _collapse_config(self):
        if self._cfg_collapsed:
            return
        self._cfg_collapsed = True
        self._cfg_outer.pack_forget()
        self._cfg_toggle_btn.configure(text="▼")

    def _expand_config(self):
        if not self._cfg_collapsed:
            return
        self._cfg_collapsed = False
        # Re-insert the card above its bottom border so pack order is preserved.
        self._cfg_outer.pack(fill='x', padx=12, pady=8,
                             before=self._cfg_bottom_border)
        self._cfg_toggle_btn.configure(text="▲")

    # ── Config load / save ────────────────────────────────────────────────

    def _load_config_to_ui(self):
        c = self._config
        self._url_var.set(c.get('url', ''))
        self._user_var.set(c.get('username', ''))
        self._pass_var.set(c.get('password', ''))
        self._sybu_var.set(c.get('sybu_dir', r'C:\SmackYourBatchUp'))
        self._creds_var.set(c.get('google_credentials', ''))
        self._folder_id_var.set(c.get('drive_folder_id', ''))
        self._folder_a_var.set(c.get('server_folder', ''))
        self._folder_b_var.set(c.get('originals_folder', ''))

    def _save_config(self):
        _save_config({
            'url':                self._url_var.get().strip(),
            'username':           self._user_var.get().strip(),
            'password':           self._pass_var.get(),
            'sybu_dir':           self._sybu_var.get().strip(),
            'google_credentials': self._creds_var.get().strip(),
            'drive_folder_id':    self._folder_id_var.get().strip(),
            'server_folder':      self._folder_a_var.get().strip(),
            'originals_folder':   self._folder_b_var.get().strip(),
        })
        self._config = _load_config()

    # ── Browse helpers ────────────────────────────────────────────────────

    def _browse_folder_a(self):
        init = self._folder_a_var.get().strip()
        p = filedialog.askdirectory(title="Select Folder A (Server FTP images)",
                                    initialdir=init if init and os.path.isdir(init) else None)
        if p:
            self._folder_a_var.set(p)
            self._save_config()

    def _browse_folder_b(self):
        init = self._folder_b_var.get().strip()
        p = filedialog.askdirectory(title="Select Folder B (Original images)",
                                    initialdir=init if init and os.path.isdir(init) else None)
        if p:
            self._folder_b_var.set(p)
            self._save_config()

    def _browse_creds(self):
        p = filedialog.askopenfilename(
            title="Select Google credentials.json",
            filetypes=[('JSON files', '*.json'), ('All files', '*.*')],
        )
        if p:
            self._creds_var.set(p)
            self._save_config()

    def _browse_sybu(self):
        init = self._sybu_var.get().strip()
        p = filedialog.askdirectory(
            title="Select SmackYourBatchUp folder (contains token.json)",
            initialdir=init if init and os.path.isdir(init) else None,
        )
        if p:
            self._sybu_var.set(p)
            local_drive.set_token_base(p)
            self._save_config()

    # ── Auto-reconnect on launch ──────────────────────────────────────────

    def _auto_reconnect(self):
        c = self._config

        # ── Drive ─────────────────────────────────────────────────────────
        sybu  = c.get('sybu_dir', '').strip()
        creds = c.get('google_credentials', '').strip()
        if sybu:
            local_drive.set_token_base(sybu)
        # If credentials path not saved yet, look for credentials.json in the
        # sybu dir and the exe dir automatically.
        if not creds or not os.path.isfile(creds):
            for _candidate in [sybu, _APP_DIR]:
                _p = os.path.join(_candidate, 'credentials.json')
                if os.path.isfile(_p):
                    creds = _p
                    self._creds_var.set(creds)
                    break
        if local_drive.is_authenticated() and creds and os.path.isfile(creds):
            self._drive_dot.configure(fg=FG_WARN)
            self._drive_lbl.configure(text="Drive: Connecting…", fg=FG_WARN)
            def _drive_thread():
                try:
                    svc = local_drive.authenticate(creds)
                    self._drive_service = svc
                    self.after(0, lambda: self._drive_dot.configure(fg=FG_OK))
                    self.after(0, lambda: self._drive_lbl.configure(
                        text="Drive: Authenticated", fg=FG_OK))
                except Exception:
                    self.after(0, lambda: self._drive_dot.configure(fg=FG_DIM))
                    self.after(0, lambda: self._drive_lbl.configure(
                        text="Drive: Not connected", fg=FG_DIM))
            threading.Thread(target=_drive_thread, daemon=True).start()

        # ── Site ──────────────────────────────────────────────────────────
        url  = c.get('url', '').strip()
        user = c.get('username', '').strip()
        pw   = c.get('password', '')
        if url and user and pw:
            self._conn_dot.configure(fg=FG_WARN)
            self._conn_lbl.configure(text="Site: Connecting…", fg=FG_WARN)
            def _site_thread():
                try:
                    client = BackfillClient(url)
                    client.login(user, pw)
                    self._client = client
                    self.after(0, lambda: self._conn_dot.configure(fg=FG_OK))
                    self.after(0, lambda: self._conn_lbl.configure(
                        text="Site: Connected", fg=FG_OK))
                    self.after(0, self._pull_records)
                except Exception:
                    self.after(0, lambda: self._conn_dot.configure(fg=FG_DIM))
                    self.after(0, lambda: self._conn_lbl.configure(
                        text="Site: Auto-connect failed", fg=FG_DIM))
            threading.Thread(target=_site_thread, daemon=True).start()

    # ── Drive auth ────────────────────────────────────────────────────────

    def _on_auth_drive(self):
        creds = self._creds_var.get().strip()
        sybu  = self._sybu_var.get().strip()
        # Auto-locate credentials.json if field left blank
        if not creds or not os.path.isfile(creds):
            for _candidate in [sybu, _APP_DIR]:
                _p = os.path.join(_candidate, 'credentials.json')
                if os.path.isfile(_p):
                    creds = _p
                    self._creds_var.set(creds)
                    break
        if not creds or not os.path.isfile(creds):
            messagebox.showerror("Credentials missing",
                                 "Select your credentials.json file first.")
            return
        if sybu:
            local_drive.set_token_base(sybu)

        self._drive_dot.configure(fg=FG_WARN)
        self._drive_lbl.configure(text="Drive: Authenticating…", fg=FG_WARN)
        def _worker():
            try:
                svc = local_drive.authenticate(creds)
                self._drive_service = svc
                self.after(0, lambda: self._drive_dot.configure(fg=FG_OK))
                self.after(0, lambda: self._drive_lbl.configure(
                    text="Drive: Authenticated", fg=FG_OK))
                self.after(0, self._save_config)
            except Exception as exc:
                self.after(0, lambda: self._drive_dot.configure(fg=FG_ERR))
                self.after(0, lambda: self._drive_lbl.configure(
                    text=f"Drive: Auth failed", fg=FG_ERR))
                self.after(0, lambda: messagebox.showerror("Drive auth failed", str(exc)))
        threading.Thread(target=_worker, daemon=True).start()

    # ── Site connect + pull ───────────────────────────────────────────────

    def _on_connect_and_pull(self):
        url  = self._url_var.get().strip()
        user = self._user_var.get().strip()
        pw   = self._pass_var.get()
        if not url or not user or not pw:
            messagebox.showerror("Missing info",
                                 "Enter site URL, username and password.")
            return
        self._save_config()
        self._conn_dot.configure(fg=FG_WARN)
        self._conn_lbl.configure(text="Site: Connecting…", fg=FG_WARN)
        def _worker():
            try:
                client = BackfillClient(url)
                client.login(user, pw)
                self._client = client
                self.after(0, lambda: self._conn_dot.configure(fg=FG_OK))
                self.after(0, lambda: self._conn_lbl.configure(
                    text="Site: Connected", fg=FG_OK))
                self.after(0, self._pull_records)
            except Exception as exc:
                self.after(0, lambda: self._conn_dot.configure(fg=FG_ERR))
                self.after(0, lambda: self._conn_lbl.configure(
                    text="Site: Connection failed", fg=FG_ERR))
                self.after(0, lambda: messagebox.showerror("Connection failed", str(exc)))
        threading.Thread(target=_worker, daemon=True).start()

    def _pull_records(self):
        if not self._client:
            return
        self._status_lbl.configure(text="Pulling records from site…", fg=FG_WARN)
        def _worker():
            try:
                records = self._client.fetch_missing()
                self._records = records
                count = len(records)
                self.after(0, lambda: self._status_lbl.configure(
                    text=f"{count} images need Drive links. Set folders and click Start Matching.",
                    fg=FG_OK if count > 0 else FG_DIM,
                ))
            except Exception as exc:
                self.after(0, lambda: self._status_lbl.configure(
                    text=f"Pull failed: {exc}", fg=FG_ERR))
        threading.Thread(target=_worker, daemon=True).start()

    # ── Recovery on launch ────────────────────────────────────────────────

    def _check_recovery_on_launch(self):
        if os.path.isfile(RECOVERY_FILE):
            self._restore_recovery()

    # ── Start matching ────────────────────────────────────────────────────

    def _on_start_matching(self):
        if self._matching:
            return

        folder_a = self._folder_a_var.get().strip()
        folder_b = self._folder_b_var.get().strip()

        if not folder_a or not os.path.isdir(folder_a):
            messagebox.showerror("Folder A missing",
                                 "Set the server images folder (Folder A).")
            return
        if not folder_b or not os.path.isdir(folder_b):
            messagebox.showerror("Folder B missing",
                                 "Set the originals folder (Folder B).")
            return
        if not self._records:
            messagebox.showinfo("No records",
                                "No records to process. Connect to site and pull first.")
            return

        self._save_config()
        self._collapse_config()
        self._matching        = True
        self._all_rows        = []
        self._unrendered_rows = []
        if self._load_more_btn is not None:
            self._load_more_btn.destroy()
            self._load_more_btn = None
        self._start_btn.configure(state='disabled', bg=FG_DIM)
        self._status_lbl.configure(text="Building pHash index for originals…", fg=FG_WARN)
        self._prog_bar['value'] = 0

        threading.Thread(target=self._matching_thread,
                         args=(folder_a, folder_b), daemon=True).start()

    def _matching_thread(self, folder_a: str, folder_b: str):
        # ── Build pHash index for all originals ───────────────────────────
        exts = {'.jpg', '.jpeg', '.png', '.webp'}
        orig_paths = sorted([
            os.path.join(folder_b, f)
            for f in os.listdir(folder_b)
            if os.path.splitext(f)[1].lower() in exts
        ])
        if not orig_paths:
            self.after(0, lambda: messagebox.showerror(
                "No images in Folder B",
                "No JPG/PNG/WEBP files found in the originals folder."))
            self._matching = False
            return

        self.after(0, lambda n=len(orig_paths): self._status_lbl.configure(
            text=f"Hashing {n} originals…", fg=FG_WARN))

        # TODO: add per-image progress bar updates during pHash indexing
        # (one update per file as phash_file() completes, so the bar fills
        # across the hashing phase before matching begins)
        orig_pairs = [(p, phash_file(p)) for p in orig_paths]

        # ── Build server image path list (matched to records) ─────────────
        # pair each record with its local FTP copy path (may not exist)
        record_srv = []
        for rec in self._records:
            basename = os.path.basename(rec.get('img_file', ''))
            srv_path = os.path.join(folder_a, basename)
            if os.path.isfile(srv_path):
                record_srv.append((rec, srv_path))
            else:
                # No local copy — still show row so user can pick manually
                record_srv.append((rec, None))

        n_total    = len(record_srv)
        n_workers  = max(1, int(os.cpu_count() * 0.75))

        self.after(0, lambda: self._prog_bar.configure(maximum=n_total))
        self.after(0, lambda n=n_total: self._status_lbl.configure(
            text=f"Matching {n} images — {n_workers} workers…", fg=FG_WARN))

        # ── Process in batches of BATCH_SIZE ──────────────────────────────
        processed = 0
        for batch_start in range(0, n_total, BATCH_SIZE):
            batch = record_srv[batch_start: batch_start + BATCH_SIZE]

            # Separate records without local server copy (can't SIFT-match)
            needs_match = [(rec, srv) for rec, srv in batch if srv is not None]
            no_copy     = [(rec, None) for rec, srv in batch if srv is None]

            results_map = {}   # srv_path → result dict

            if needs_match:
                args_list = [(srv, orig_pairs) for _, srv in needs_match]
                with ProcessPoolExecutor(max_workers=n_workers) as ex:
                    futures = {ex.submit(match_one, a): a[0]
                               for a in args_list}
                    for fut in as_completed(futures):
                        srv = futures[fut]
                        try:
                            results_map[srv] = fut.result()
                        except Exception:
                            results_map[srv] = {
                                'server_path': srv,
                                'match_path': None,
                                'confidence': 0.0,
                                'match_count': 0,
                                'candidates': [],
                                'label': 'none',
                            }

            # Build ordered row data for this batch
            batch_rows = []
            for rec, srv in batch:
                if srv is not None:
                    result = results_map.get(srv, {'server_path': srv,
                                                    'match_path': None,
                                                    'confidence': 0.0,
                                                    'match_count': 0,
                                                    'candidates': [],
                                                    'label': 'none'})
                else:
                    result = {'server_path': None, 'match_path': None,
                              'confidence': 0.0, 'match_count': 0,
                              'candidates': [], 'label': 'none'}
                batch_rows.append((rec, result))

            processed += len(batch)
            self.after(0, lambda rows=batch_rows, p=processed:
                       self._on_batch_done(rows, p, n_total))

        self._matching = False

    def _on_batch_done(self, batch_rows: list, processed: int, total: int):
        self._prog_bar['value'] = processed
        self._prog_lbl.configure(
            text=f"Batch done — {processed}/{total} matched",
            fg=FG_DIM,
        )
        self._all_rows.extend(batch_rows)   # accumulate for recovery
        for rec, result in batch_rows:
            if len(self._row_widgets) < PAGE_SIZE:
                row = MatchRow(self._inner, rec, result, self)
                row.pack(fill='x', pady=2, padx=4)
                self._row_widgets.append(row)
                self._pending_count += 1
            else:
                self._unrendered_rows.append((rec, result))

        self._inner.update_idletasks()
        self._canvas.configure(scrollregion=self._canvas.bbox('all'))

        if processed >= total:
            self._save_recovery()
            self._update_load_more_btn()   # only after all batches land
            self._status_lbl.configure(
                text=f"Matching complete — {total} images ready to review.",
                fg=FG_OK)
            self._start_btn.configure(state='normal', bg=ACCENT)
            self.after(100, self._finalize_canvas)

    # ── Row completion callback ───────────────────────────────────────────

    def _on_row_done(self):
        self._done_count    += 1
        remaining            = self._pending_count - self._done_count
        self._status_lbl.configure(
            text=f"{self._done_count} uploaded / skipped — {remaining} remaining.",
            fg=FG_DIM,
        )
        if remaining == 0 and not self._unrendered_rows:
            self._expand_config()

    # ── Serial upload queue ───────────────────────────────────────────────

    def _enqueue_upload(self, row: 'MatchRow'):
        """Add a row to the serial upload queue; start the drain thread if idle."""
        self._upload_queue.put(row)
        if not self._upload_running:
            self._upload_running = True
            threading.Thread(target=self._drain_upload_queue,
                             daemon=True).start()

    def _drain_upload_queue(self):
        """Process upload queue one item at a time. Runs in a background thread."""
        while True:
            try:
                row = self._upload_queue.get(timeout=3)
            except queue.Empty:
                self._upload_running = False
                return

            done_evt = threading.Event()
            # _start_upload must run on the main thread (touches tkinter widgets)
            self.after(0, lambda r=row, e=done_evt: r._start_upload(e))
            done_evt.wait()   # block until this upload finishes (success, error, cancel)
            self._upload_queue.task_done()

    def _on_upload_approved(self):
        """Enqueue all rows whose Approve checkbox is ticked."""
        approved = [
            row for row in self._row_widgets
            if hasattr(row, '_approved_var')
            and row._approved_var.get()
            and not row.result.get('_uploaded')
            and row.result.get('match_path')
        ]
        if not approved:
            messagebox.showinfo(
                "Nothing approved",
                "Check the Approve box on the rows you want to upload, then click Upload Approved.",
                parent=self,
            )
            return
        for row in approved:
            row._approved_var.set(False)
            row._upload_btn.configure(text="Queued…", state='disabled',
                                      bg=FG_DIM, fg="#000000")
            self._enqueue_upload(row)
        self._status_lbl.configure(
            text=f"{len(approved)} upload(s) queued — processing serially…",
            fg=FG_WARN,
        )

    def _remove_row(self, row: 'MatchRow'):
        """Remove a completed row from tracking lists and re-save recovery."""
        self._all_rows = [
            (rec, result) for rec, result in self._all_rows
            if result is not row.result
        ]
        if row in self._row_widgets:
            self._row_widgets.remove(row)
        self._save_recovery()

    # ── Recovery save / restore ───────────────────────────────────────────

    def _save_recovery(self):
        """Write all matched rows to fybu-recovery.json next to the exe."""
        data = {
            'saved_at':     datetime.now().isoformat(timespec='seconds'),
            'fybu_version': BUILD_VERSION,
            'site_url':     self._config.get('url', ''),
            'folder_a':     self._folder_a_var.get().strip(),
            'folder_b':     self._folder_b_var.get().strip(),
            'rows': [{'rec': rec, 'result': result}
                     for rec, result in self._all_rows],
        }
        try:
            with open(RECOVERY_FILE, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2)
        except Exception:
            pass   # best-effort; don't crash the UI over a failed save

    def _restore_recovery(self):
        """Load fybu-recovery.json and rebuild the review queue without re-scanning.

        If the site is already connected, cross-references each row's snap_id
        against the server's current missing-links list.  Any row that the DB
        has already resolved is tagged _uploaded=True so it renders as Done
        without re-uploading.
        """
        try:
            with open(RECOVERY_FILE, 'r', encoding='utf-8') as f:
                data = json.load(f)
        except Exception as exc:
            messagebox.showerror("Recovery failed",
                                 f"Could not read recovery file:\n{exc}")
            return

        rows = data.get('rows', [])
        if not rows:
            messagebox.showinfo("Empty session",
                                "Recovery file contained no rows.")
            return

        # If connected, fetch the IDs still missing from the DB and mark the
        # rest as already done — no local _uploaded flag required.
        if self._client:
            try:
                still_missing = {
                    int(img['snap_id'])
                    for img in self._client.fetch_missing()
                }
                for r in rows:
                    sid = int(r['rec'].get('snap_id', -1))
                    if sid not in still_missing:
                        r['result']['_uploaded'] = True
            except Exception:
                pass  # fall back to whatever _uploaded flags are in the file

        saved_at = data.get('saved_at', 'unknown')
        n        = len(rows)
        n_pending = sum(1 for r in rows if not r['result'].get('_uploaded'))

        # ── All done — nothing left to review ─────────────────────────────
        if n_pending == 0:
            # Delete the consumed recovery file so it doesn't reappear.
            try:
                os.remove(RECOVERY_FILE)
            except Exception:
                pass
            # Show a visible placeholder in the canvas so the screen isn't
            # just a dark void.
            tk.Label(
                self._inner,
                text=f"✓  All {n} images from this session have been uploaded.\n\n"
                     "Use Start Matching to scan for any new missing images.",
                bg=BG_DEEP, fg=FG_OK,
                font=FONT_BOLD, justify='center',
                wraplength=600,
            ).pack(expand=True, pady=60)
            self._inner.update_idletasks()
            self._canvas.configure(scrollregion=self._canvas.bbox('all'))
            self._canvas.itemconfigure(self._canvas_win,
                                       width=self._canvas.winfo_width())
            self._prog_bar.configure(maximum=n)
            self._prog_bar['value'] = n
            self._prog_lbl.configure(text=f"{n}/{n}", fg=FG_DIM)
            self._status_lbl.configure(
                text=f"✓  All caught up — {n} images uploaded. Ready for a fresh scan.",
                fg=FG_OK,
            )
            self._start_btn.configure(state='normal', bg=ACCENT)
            return

        self._prog_bar.configure(maximum=n)
        self._prog_bar['value'] = 0
        self._prog_lbl.configure(text=f"Restoring {n} rows…", fg=FG_DIM)
        self._all_rows = [(r['rec'], r['result']) for r in rows]

        # Surface the real pending count before batches start
        self._status_lbl.configure(
            text=f"Recovery: {n_pending} still need uploading, "
                 f"{n - n_pending} already done.",
            fg=FG_WARN if n_pending else FG_OK,
        )

        # Build rows in batches via self.after() so the UI stays responsive.
        self._collapse_config()
        self._restore_batch(self._all_rows[:], 0, n, saved_at)

    def _restore_batch(self, remaining: list, done: int, total: int, saved_at: str):
        """Drip-feed row creation in BATCH_SIZE chunks to keep the UI alive.

        Rows already marked _uploaded are skipped — they were removed from the
        recovery JSON at upload time, so this is just a safety net for any that
        slipped through a crash before the file was re-saved.
        """
        batch, remaining = remaining[:BATCH_SIZE], remaining[BATCH_SIZE:]
        for rec, result in batch:
            done += 1
            if result.get('_uploaded'):
                continue   # already done — don't re-show
            if len(self._row_widgets) < PAGE_SIZE:
                row = MatchRow(self._inner, rec, result, self)
                row.pack(fill='x', pady=2, padx=4)
                self._row_widgets.append(row)
                self._pending_count += 1
            else:
                self._unrendered_rows.append((rec, result))

        self._prog_bar['value'] = done
        self._prog_lbl.configure(text=f"Restoring… {done}/{total}", fg=FG_DIM)
        self._inner.update_idletasks()
        self._canvas.configure(scrollregion=self._canvas.bbox('all'))
        # Keep canvas window width in sync — it may not have received a
        # Configure event while rows were being batch-created.
        self._canvas.itemconfigure(self._canvas_win,
                                   width=self._canvas.winfo_width())

        if remaining:
            # 20 ms gap lets tkinter fully lay out the current batch before
            # starting the next — prevents partial rendering on large restores.
            self.after(20, lambda r=remaining, d=done: self._restore_batch(r, d, total, saved_at))
        else:
            self._update_load_more_btn()
            pending = len(self._row_widgets) + len(self._unrendered_rows)
            done_ct = total - pending - sum(
                1 for _, r in self._all_rows if r.get('_uploaded')
            ) if total > pending else total - pending
            self._status_lbl.configure(
                text=f"Session restored — {pending} pending"
                     + (f", {total - pending} already done." if total > pending else ".")
                     + (" Click Start Matching for a fresh scan." if pending == 0 else ""),
                fg=FG_OK if pending == 0 else FG_WARN,
            )
            self._start_btn.configure(state='normal', bg=ACCENT)
            # Final forced relayout after all batches are in — ensures the
            # scrollregion and canvas window width are correct for the full list.
            self.after(100, self._finalize_canvas)

    # ── Pagination ────────────────────────────────────────────────────────

    def _update_load_more_btn(self):
        """Show or hide the Load More button depending on whether _unrendered_rows exists."""
        if self._unrendered_rows:
            n = len(self._unrendered_rows)
            if self._load_more_btn is None:
                self._load_more_btn = tk.Button(
                    self._inner,
                    text=f"▼  Load next {min(PAGE_SIZE, n)} of {n} remaining",
                    bg=BG_HOVER, fg=FG_MAIN, font=FONT_BOLD,
                    relief='flat', cursor='hand2',
                    command=self._load_next_page,
                )
                self._load_more_btn.pack(fill='x', pady=8, padx=4)
            else:
                self._load_more_btn.configure(
                    text=f"▼  Load next {min(PAGE_SIZE, n)} of {n} remaining")
        else:
            if self._load_more_btn is not None:
                self._load_more_btn.destroy()
                self._load_more_btn = None

    def _load_next_page(self):
        """Render the next PAGE_SIZE rows from _unrendered_rows."""
        # Remove Load More button temporarily while building
        if self._load_more_btn is not None:
            self._load_more_btn.destroy()
            self._load_more_btn = None

        page, self._unrendered_rows = (
            self._unrendered_rows[:PAGE_SIZE],
            self._unrendered_rows[PAGE_SIZE:],
        )
        for rec, result in page:
            row = MatchRow(self._inner, rec, result, self)
            row.pack(fill='x', pady=2, padx=4)
            self._row_widgets.append(row)
            self._pending_count += 1

        self._update_load_more_btn()
        self.after(100, self._finalize_canvas)

    # ── Scroll handling ───────────────────────────────────────────────────

    def _finalize_canvas(self):
        """Force a complete canvas relayout after a bulk restore or match pass."""
        self._inner.update_idletasks()
        bbox = self._canvas.bbox('all')
        if bbox:
            self._canvas.configure(scrollregion=bbox)
        self._canvas.itemconfigure(self._canvas_win,
                                   width=self._canvas.winfo_width())

    def _on_inner_configure(self, _event):
        self._canvas.configure(scrollregion=self._canvas.bbox('all'))

    def _on_canvas_configure(self, event):
        self._canvas.itemconfigure(self._canvas_win, width=event.width)

    def _on_scroll(self, event):
        if event.num == 4 or event.delta > 0:
            self._canvas.yview_scroll(-1, 'units')
        else:
            self._canvas.yview_scroll(1, 'units')


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
if __name__ == '__main__':
    # Required on Windows for ProcessPoolExecutor with 'spawn' start method
    import multiprocessing
    multiprocessing.freeze_support()

    app = App()
    app.mainloop()
# ===== SNAPSMACK EOF =====
