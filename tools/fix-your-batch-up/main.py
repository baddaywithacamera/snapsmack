BUILD_VERSION = "0.7.7a-12"   # bump this on every rebuild

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
        self.record  = record
        self.result  = result
        self.app     = app
        self._orig_photo = None   # keep reference
        self._srv_photo  = None
        self._cancel_evt = threading.Event()

        self._build()

    def _build(self):
        # ── Left: server image ────────────────────────────────────────────
        left = tk.Frame(self, bg=BG_CARD, width=IMG_W + 10)
        left.pack(side='left', fill='y', padx=(8, 4), pady=8)
        left.pack_propagate(False)

        srv_path = self._server_local_path()
        self._srv_lbl = tk.Label(left, bg=BG_MID,
                                 width=IMG_W, height=IMG_MAX_H,
                                 relief='flat')
        self._srv_lbl.pack(fill='x')
        if srv_path:
            photo = _load_thumb(srv_path)
            if photo:
                self._srv_photo = photo
                self._srv_lbl.configure(image=photo, width=photo.width(),
                                        height=photo.height())

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

        self._orig_lbl = tk.Label(orig_frame, bg=BG_MID,
                                  width=IMG_W, height=IMG_MAX_H,
                                  relief='flat')
        self._orig_lbl.pack(side='left')

        orig_path = self.result.get('match_path')
        if not orig_path:
            self._orig_lbl.configure(
                text="No match found\nClick 'Pick Different'",
                fg=FG_DIM, font=FONT_UI, compound='center')

        self._orig_name_lbl = tk.Label(right, text=self._orig_basename(),
                                       bg=BG_CARD, fg=FG_DIM,
                                       font=FONT_SMALL, anchor='w')
        self._orig_name_lbl.pack(fill='x', pady=(4, 6))

        if orig_path:
            self._set_orig_image(orig_path)

        # Buttons
        btn_row = tk.Frame(right, bg=BG_CARD)
        btn_row.pack(fill='x')

        self._upload_btn = tk.Button(
            btn_row, text="Upload",
            bg=ACCENT, fg="#000000", font=FONT_BOLD,
            relief='flat', cursor='hand2', width=10,
            command=self._on_upload,
        )
        self._upload_btn.pack(side='left', padx=(0, 6))

        self._cancel_btn = tk.Button(
            btn_row, text="Cancel",
            bg=BG_MID, fg=FG_DIM, font=FONT_UI,
            relief='flat', cursor='hand2', width=8,
            command=self._on_cancel,
        )
        # Hidden until an upload is in-flight
        self._cancel_btn.pack(side='left', padx=(0, 6))
        self._cancel_btn.pack_forget()

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

        # If this row was already uploaded in a previous session, show it done.
        if self.result.get('_uploaded'):
            self.after(0, self._restore_done_state)

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

        self._cancel_evt.clear()
        self._upload_btn.configure(text="Uploading…", state='disabled',
                                   bg=FG_WARN, fg="#000000")
        self._cancel_btn.pack(side='left', padx=(0, 6))
        self.update_idletasks()

        snap_id     = int(self.record['snap_id'])
        folder_id   = self.app._config.get('drive_folder_id', '').strip() or None
        drive_svc   = self.app._drive_service
        client      = self.app._client
        orig_path   = match_path
        cancel_evt  = self._cancel_evt

        # Build Drive filename from the haiku title + original extension,
        # using the same sanitizer as SYBU's poster.py haiku_to_filename().
        raw_title = self.record.get('img_title', '').strip()
        _, _ext   = os.path.splitext(orig_path)
        drive_fname = (_haiku_to_filename(raw_title, _ext.lower())
                       if raw_title else os.path.basename(orig_path))

        def _worker():
            import socket
            _prev_timeout = socket.getdefaulttimeout()
            socket.setdefaulttimeout(180)   # 3 min cap — prevents indefinite hangs
            try:
                import local_drive as ld
                drive_url = ld.upload(drive_svc, orig_path,
                                      drive_fname,
                                      folder_id=folder_id)
                if cancel_evt.is_set():
                    # Upload finished but user cancelled — skip the DB write
                    self.after(0, self._mark_cancelled)
                    return
                client.update_link(snap_id, drive_url)
                self.after(0, lambda: self._mark_done(drive_url))
            except Exception as exc:
                self.after(0, lambda: self._mark_error(str(exc)))
            finally:
                socket.setdefaulttimeout(_prev_timeout)

        threading.Thread(target=_worker, daemon=True).start()

    def _mark_done(self, drive_url: str):
        self.result['_uploaded'] = True
        self._cancel_btn.pack_forget()
        self._restore_done_state()
        self.app._on_row_done()
        self.app._save_recovery()

    def _restore_done_state(self):
        """Set row to the Done visual state without touching the done counter."""
        self._upload_btn.configure(text="✓ Done", bg=FG_OK, fg="#000000",
                                   state='disabled')
        self.configure(highlightbackground=FG_OK)

    def _mark_cancelled(self):
        """Upload completed on Drive side but user cancelled — reset for retry."""
        self._cancel_btn.pack_forget()
        self._upload_btn.configure(text="Upload", bg=ACCENT, fg="#000000",
                                   state='normal')
        self.configure(highlightbackground=BORDER)

    def _on_cancel(self):
        self._cancel_evt.set()
        self._cancel_btn.configure(text="Cancelling…", state='disabled')

    def _mark_error(self, msg: str):
        self._cancel_btn.pack_forget()
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
        self.configure(highlightbackground=BORDER, bg="#1A1A1A")
        for w in self.winfo_children():
            w.configure(bg="#1A1A1A")
        self.app._on_row_done()


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
        self._records: list  = []      # full list from API
        self._row_widgets: list = []
        self._all_rows: list = []      # accumulated (rec, result) pairs for recovery
        self._pending_count  = 0
        self._done_count     = 0
        self._matching       = False

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

        tk.Label(top, text="FIX YOUR BATCH UP",
                 bg=BG_DEEP, fg=ACCENT, font=FONT_TITLE).pack(side='left')

        status_frame = tk.Frame(top, bg=BG_DEEP)
        status_frame.pack(side='right')

        self._drive_dot = tk.Label(status_frame, text="●", bg=BG_DEEP, fg=FG_DIM,
                                   font=FONT_BOLD)
        self._drive_dot.pack(side='left')
        self._drive_lbl = tk.Label(status_frame, text="Drive: Not connected",
                                   bg=BG_DEEP, fg=FG_DIM, font=FONT_UI)
        self._drive_lbl.pack(side='left', padx=(0, 20))

        self._conn_dot = tk.Label(status_frame, text="●", bg=BG_DEEP, fg=FG_DIM,
                                  font=FONT_BOLD)
        self._conn_dot.pack(side='left')
        self._conn_lbl = tk.Label(status_frame, text="Site: Not connected",
                                  bg=BG_DEEP, fg=FG_DIM, font=FONT_UI)
        self._conn_lbl.pack(side='left')

        tk.Frame(self, bg=BORDER, height=1).pack(fill='x')

        # ── Config section ─────────────────────────────────────────────────
        cfg = tk.Frame(self, bg=BG_DEEP, pady=8)
        cfg.pack(fill='x', padx=16)

        # Row 1: Folder A + Folder B
        folder_row = tk.Frame(cfg, bg=BG_DEEP)
        folder_row.pack(fill='x', pady=(0, 6))

        self._folder_a_var = tk.StringVar()
        self._folder_b_var = tk.StringVar()

        self._make_folder_row(folder_row, "Server Images (Folder A):",
                              self._folder_a_var, self._browse_folder_a)
        self._make_folder_row(folder_row, "Originals (Folder B):",
                              self._folder_b_var, self._browse_folder_b)

        # Row 2: Site URL + credentials + Connect + Pull buttons
        site_row = tk.Frame(cfg, bg=BG_DEEP)
        site_row.pack(fill='x', pady=(0, 4))

        self._url_var  = tk.StringVar()
        self._user_var = tk.StringVar()
        self._pass_var = tk.StringVar()
        self._sybu_var = tk.StringVar()
        self._creds_var = tk.StringVar()
        self._folder_id_var = tk.StringVar()

        tk.Label(site_row, text="Site URL:", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_UI, width=14, anchor='e').pack(side='left')
        tk.Entry(site_row, textvariable=self._url_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_UI, relief='flat', width=32,
                 insertbackground=ACCENT).pack(side='left', padx=(4, 12))

        tk.Label(site_row, text="User:", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_UI).pack(side='left')
        tk.Entry(site_row, textvariable=self._user_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_UI, relief='flat', width=14,
                 insertbackground=ACCENT).pack(side='left', padx=(4, 12))

        tk.Label(site_row, text="Pass:", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_UI).pack(side='left')
        tk.Entry(site_row, textvariable=self._pass_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_UI, relief='flat', width=14, show='*',
                 insertbackground=ACCENT).pack(side='left', padx=(4, 12))

        tk.Button(site_row, text="Connect + Pull",
                  bg=ACCENT, fg="#000000", font=FONT_BOLD,
                  relief='flat', cursor='hand2',
                  command=self._on_connect_and_pull).pack(side='left')

        # Row 3: Drive credentials + folder ID + Start Matching
        drive_row = tk.Frame(cfg, bg=BG_DEEP)
        drive_row.pack(fill='x', pady=(0, 4))

        tk.Label(drive_row, text="Credentials:", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_UI, width=14, anchor='e').pack(side='left')
        tk.Entry(drive_row, textvariable=self._creds_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_UI, relief='flat', width=36,
                 insertbackground=ACCENT).pack(side='left', padx=(4, 4))
        tk.Button(drive_row, text="…", bg=BG_HOVER, fg=FG_MAIN, font=FONT_UI,
                  relief='flat', cursor='hand2', width=3,
                  command=self._browse_creds).pack(side='left', padx=(0, 12))

        tk.Label(drive_row, text="Drive Folder ID:", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_UI).pack(side='left')
        tk.Entry(drive_row, textvariable=self._folder_id_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_UI, relief='flat', width=26,
                 insertbackground=ACCENT).pack(side='left', padx=(4, 12))

        tk.Button(drive_row, text="Auth Drive",
                  bg=BG_HOVER, fg=FG_MAIN, font=FONT_UI,
                  relief='flat', cursor='hand2',
                  command=self._on_auth_drive).pack(side='left', padx=(0, 8))

        # Row 4: SmackYourBatchUp dir + Start Matching button
        bottom_cfg = tk.Frame(cfg, bg=BG_DEEP)
        bottom_cfg.pack(fill='x')

        tk.Label(bottom_cfg, text="SYBU Folder:", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_UI, width=14, anchor='e').pack(side='left')
        tk.Entry(bottom_cfg, textvariable=self._sybu_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_UI, relief='flat', width=36,
                 insertbackground=ACCENT).pack(side='left', padx=(4, 4))
        tk.Button(bottom_cfg, text="…", bg=BG_HOVER, fg=FG_MAIN, font=FONT_UI,
                  relief='flat', cursor='hand2', width=3,
                  command=self._browse_sybu).pack(side='left', padx=(0, 24))

        self._start_btn = tk.Button(
            bottom_cfg, text="Start Matching",
            bg=ACCENT, fg="#000000", font=FONT_BOLD,
            relief='flat', cursor='hand2',
            command=self._on_start_matching,
        )
        self._start_btn.pack(side='left')

        tk.Frame(self, bg=BORDER, height=1).pack(fill='x')

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
        row = tk.Frame(parent, bg=BG_DEEP)
        row.pack(fill='x', pady=2)
        tk.Label(row, text=label, bg=BG_DEEP, fg=FG_DIM, font=FONT_UI,
                 width=26, anchor='e').pack(side='left')
        tk.Entry(row, textvariable=var, bg=BG_MID, fg=FG_MAIN, font=FONT_UI,
                 relief='flat', width=60,
                 insertbackground=ACCENT).pack(side='left', padx=(4, 4))
        tk.Button(row, text="Browse…", bg=BG_HOVER, fg=FG_MAIN, font=FONT_UI,
                  relief='flat', cursor='hand2', command=cmd).pack(side='left')

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
        self._matching = True
        self._all_rows = []
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
            row = MatchRow(self._inner, rec, result, self)
            row.pack(fill='x', pady=2, padx=4)
            self._row_widgets.append(row)
            self._pending_count += 1

        self._inner.update_idletasks()
        self._canvas.configure(scrollregion=self._canvas.bbox('all'))

        if processed >= total:
            self._save_recovery()
            self._status_lbl.configure(
                text=f"Matching complete — {total} images ready to review.",
                fg=FG_OK)
            self._start_btn.configure(state='normal', bg=ACCENT)

    # ── Row completion callback ───────────────────────────────────────────

    def _on_row_done(self):
        self._done_count    += 1
        remaining            = self._pending_count - self._done_count
        self._status_lbl.configure(
            text=f"{self._done_count} uploaded / skipped — {remaining} remaining.",
            fg=FG_DIM,
        )

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
        n = len(rows)

        self._prog_bar.configure(maximum=n)
        self._prog_bar['value'] = 0
        self._prog_lbl.configure(text=f"Restoring {n} rows…", fg=FG_DIM)
        self._all_rows = [(r['rec'], r['result']) for r in rows]

        # Build rows in batches via self.after() so the UI stays responsive.
        self._restore_batch(self._all_rows[:], 0, n, saved_at)

    def _restore_batch(self, remaining: list, done: int, total: int, saved_at: str):
        """Drip-feed row creation in BATCH_SIZE chunks to keep the UI alive."""
        batch, remaining = remaining[:BATCH_SIZE], remaining[BATCH_SIZE:]
        for rec, result in batch:
            row = MatchRow(self._inner, rec, result, self)
            row.pack(fill='x', pady=2, padx=4)
            self._row_widgets.append(row)
            self._pending_count += 1
            done += 1

        self._prog_bar['value'] = done
        self._prog_lbl.configure(text=f"Restoring… {done}/{total}", fg=FG_DIM)
        self._inner.update_idletasks()
        self._canvas.configure(scrollregion=self._canvas.bbox('all'))

        if remaining:
            self.after(0, lambda r=remaining, d=done: self._restore_batch(r, d, total, saved_at))
        else:
            self._status_lbl.configure(
                text=f"Session restored — {total} images ready to review. "
                     f"(Delete fybu-recovery.json and click Start Matching for a fresh scan.)",
                fg=FG_OK)
            self._start_btn.configure(state='normal', bg=ACCENT)

    # ── Scroll handling ───────────────────────────────────────────────────

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
