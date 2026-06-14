"""
FLKR FCKR — main.py
Flickr export → SnapSmack migration desktop tool.

Dark tkinter UI in the Unzucker/SmackPress/SUYB family.
Window: ~820×760. Sections: Settings, Photo Grid, Album Panel, Run Panel.

Forked from tools/unzucker/main.py — IG-specific logic replaced.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import logging
import logging.handlers
import os
import queue
import sys
import tempfile
import threading
import tkinter as tk
from tkinter import filedialog, font, messagebox, ttk
from typing import Dict, List, Optional

import config as cfg_mod
import flickr_parser
from checkpoint import ImportCheckpoint
from poster import FlkrDckrClient, run_import

# ---------------------------------------------------------------------------
# Logging — rotating daily, 7-day retention, %APPDATA%\FlkrFckr\flkrfckr.log
# ---------------------------------------------------------------------------

BUILD_VERSION = "1.0.0"

_LOG_DIR  = os.path.join(os.environ.get('APPDATA', os.path.expanduser('~')), 'FlkrFckr')
_LOG_FILE = os.path.join(_LOG_DIR, 'flkrfckr.log')
os.makedirs(_LOG_DIR, exist_ok=True)

_log_handler = logging.handlers.TimedRotatingFileHandler(
    _LOG_FILE, when='D', interval=1, backupCount=7, encoding='utf-8',
)
_log_handler.setFormatter(logging.Formatter(
    '%(asctime)s  %(levelname)-8s  %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S',
))
log = logging.getLogger('flkrfckr')
log.setLevel(logging.DEBUG)
log.addHandler(_log_handler)

def _excepthook(exc_type, exc_value, exc_tb):
    if not issubclass(exc_type, KeyboardInterrupt):
        log.critical('Unhandled exception', exc_info=(exc_type, exc_value, exc_tb))
    sys.__excepthook__(exc_type, exc_value, exc_tb)

sys.excepthook = _excepthook

# ---------------------------------------------------------------------------
# Palette — matches Unzucker dark theme
# ---------------------------------------------------------------------------

BG_DEEP    = '#0d0d0d'
BG_PANEL   = '#1a1a1a'
BG_CELL    = '#222222'
BG_HOVER   = '#2c2c2c'
BG_ACTIVE  = '#1e3a1e'
ACCENT     = '#aaff00'    # neon lime
TEXT_PRI   = '#e8e8e8'
TEXT_DIM   = '#777777'
TEXT_ERR   = '#ff4444'
TEXT_WARN  = '#ffaa00'
BORDER     = '#333333'

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _e(text: str) -> str:
    """Truncate long strings for display."""
    return text if len(text) <= 60 else text[:57] + '...'


def _status_colour(msg: str) -> str:
    msg = msg.lower()
    if 'error' in msg or 'fail' in msg or 'missing' in msg:
        return TEXT_ERR
    if 'skip' in msg or 'duplicate' in msg or 'already' in msg:
        return TEXT_DIM
    return ACCENT


# ---------------------------------------------------------------------------
# Main application window
# ---------------------------------------------------------------------------

class FlkrDckrApp(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title('FLKR FCKR')
        self.configure(bg=BG_DEEP)
        self.resizable(True, True)
        self.minsize(700, 600)

        # State
        self._cfg: dict                              = cfg_mod.load()
        self._parse_result: Optional[flickr_parser.ParseResult] = None
        self._client:       Optional[FlkrDckrClient] = None
        self._checkpoint:   Optional[ImportCheckpoint] = None
        self._running       = False
        self._paused        = False
        self._stop_event    = threading.Event()
        self._pause_event   = threading.Event()
        self._pause_event.set()   # not paused by default
        self._q: queue.Queue      = queue.Queue()
        self._album_filter: str   = 'all'   # 'all', album flickr ID, or 'unalbumed'

        # Fonts
        self._font_ui    = font.Font(family='Segoe UI', size=9)
        self._font_label = font.Font(family='Segoe UI', size=8)
        self._font_bold  = font.Font(family='Segoe UI', size=9, weight='bold')
        self._font_mono  = font.Font(family='Consolas', size=8)

        self._build_ui()
        self.protocol('WM_DELETE_WINDOW', self._on_close)
        self._check_resume()
        self.after(100, self._poll_queue)

    def _on_close(self):
        """Signal any running import to stop, then close the window."""
        if self._running:
            self._stop_event.set()
            self._pause_event.set()   # unblock the worker if it is paused
        self.destroy()

    # ------------------------------------------------------------------
    # UI construction
    # ------------------------------------------------------------------

    def _build_ui(self):
        # Top-level layout: settings row + main content row
        self.columnconfigure(0, weight=1)
        self.rowconfigure(1, weight=1)

        self._build_settings_bar()
        self._build_main_area()
        self._build_run_panel()

    def _build_settings_bar(self):
        bar = tk.Frame(self, bg=BG_PANEL, pady=8, padx=10)
        bar.grid(row=0, column=0, sticky='ew')
        bar.columnconfigure(1, weight=1)
        bar.columnconfigure(3, weight=1)
        bar.columnconfigure(5, weight=1)
        bar.columnconfigure(7, weight=1)

        def lbl(parent, text, col, row=0):
            tk.Label(parent, text=text, bg=BG_PANEL, fg=TEXT_DIM,
                     font=self._font_label).grid(row=row, column=col, sticky='w', padx=(0, 2))

        def entry(parent, var, col, row=0, width=28, show=''):
            e = tk.Entry(parent, textvariable=var, bg=BG_CELL, fg=TEXT_PRI,
                         insertbackground=TEXT_PRI, relief='flat', bd=4,
                         font=self._font_ui, width=width, show=show)
            e.grid(row=row, column=col, sticky='ew', padx=(0, 8))
            return e

        # Row 0: Site URL + API key
        lbl(bar, 'Site URL', 0)
        self._v_url = tk.StringVar(value=self._cfg.get('site_url', ''))
        entry(bar, self._v_url, 1)

        lbl(bar, 'API Key', 2)
        self._v_key = tk.StringVar(value=self._cfg.get('api_key', ''))
        entry(bar, self._v_key, 3, show='•')

        self._btn_test = tk.Button(bar, text='Test', bg=BG_CELL, fg=ACCENT,
                                   relief='flat', bd=0, padx=8, pady=3,
                                   font=self._font_bold, cursor='hand2',
                                   command=self._test_connection)
        self._btn_test.grid(row=0, column=4, padx=(0, 12))

        # Row 1: Export folder + options. Images upload over HTTPS now (same
        # channel as the API) — no FTP host/credentials needed.
        lbl(bar, 'Export Folder', 0, row=1)
        self._v_folder = tk.StringVar(value=self._cfg.get('export_folder', ''))
        entry(bar, self._v_folder, 1, row=1, width=36)

        btn_browse = tk.Button(bar, text='Browse…', bg=BG_CELL, fg=TEXT_PRI,
                               relief='flat', bd=0, padx=8, pady=3,
                               font=self._font_ui, cursor='hand2',
                               command=self._browse_folder)
        btn_browse.grid(row=1, column=2, sticky='w', padx=(0, 8))

        lbl(bar, 'Throttle (s)', 4, row=1)
        self._v_throttle = tk.DoubleVar(value=self._cfg.get('throttle_delay', 1.5))
        tk.Spinbox(bar, from_=0.5, to=5.0, increment=0.5, textvariable=self._v_throttle,
                   bg=BG_CELL, fg=TEXT_PRI, insertbackground=TEXT_PRI, relief='flat',
                   bd=4, font=self._font_ui, width=6).grid(row=1, column=5, sticky='w', padx=(0, 8))

        lbl(bar, 'Private →', 6, row=1)
        self._v_private = tk.StringVar(value=self._cfg.get('private_status', 'draft'))
        ttk.Combobox(bar, textvariable=self._v_private, values=['draft', 'published'],
                     width=10, state='readonly').grid(row=1, column=7, sticky='w')

        # Connection status label
        self._lbl_conn = tk.Label(bar, text='', bg=BG_PANEL, fg=TEXT_DIM,
                                  font=self._font_label)
        self._lbl_conn.grid(row=0, column=8, padx=(8, 0), sticky='w')

        btn_load = tk.Button(bar, text='Load Export', bg=ACCENT, fg='#000000',
                             relief='flat', bd=0, padx=10, pady=4,
                             font=self._font_bold, cursor='hand2',
                             command=self._load_export)
        btn_load.grid(row=1, column=8, padx=(8, 0), sticky='e')

        btn_help = tk.Button(bar, text='?', bg=BG_CELL, fg=TEXT_DIM,
                             relief='flat', bd=0, padx=6, pady=3,
                             font=self._font_bold, cursor='hand2',
                             command=self._show_help)
        btn_help.grid(row=0, column=8, padx=(8, 0), sticky='ne')

    def _build_main_area(self):
        pane = tk.Frame(self, bg=BG_DEEP)
        pane.grid(row=1, column=0, sticky='nsew', padx=6, pady=4)
        pane.columnconfigure(0, weight=3)
        pane.columnconfigure(1, weight=1)
        pane.rowconfigure(0, weight=1)

        self._build_photo_grid(pane)
        self._build_album_sidebar(pane)

    def _build_photo_grid(self, parent):
        frame = tk.Frame(parent, bg=BG_DEEP)
        frame.grid(row=0, column=0, sticky='nsew', padx=(0, 4))
        frame.rowconfigure(1, weight=1)
        frame.columnconfigure(0, weight=1)

        # Filter bar
        filter_bar = tk.Frame(frame, bg=BG_PANEL, pady=4, padx=6)
        filter_bar.grid(row=0, column=0, sticky='ew')

        tk.Label(filter_bar, text='Show:', bg=BG_PANEL, fg=TEXT_DIM,
                 font=self._font_label).pack(side='left', padx=(0, 4))

        self._v_filter = tk.StringVar(value='all')
        for val, lbl in [('all', 'All'), ('unalbumed', 'Unalbumed')]:
            tk.Radiobutton(filter_bar, text=lbl, variable=self._v_filter, value=val,
                           bg=BG_PANEL, fg=TEXT_PRI, selectcolor=BG_CELL, activebackground=BG_PANEL,
                           font=self._font_label, command=self._apply_filter).pack(side='left', padx=4)

        self._lbl_grid_count = tk.Label(filter_bar, text='No export loaded', bg=BG_PANEL,
                                         fg=TEXT_DIM, font=self._font_label)
        self._lbl_grid_count.pack(side='right', padx=4)

        # Scrollable canvas grid
        canvas_frame = tk.Frame(frame, bg=BG_DEEP)
        canvas_frame.grid(row=1, column=0, sticky='nsew')
        canvas_frame.rowconfigure(0, weight=1)
        canvas_frame.columnconfigure(0, weight=1)

        self._canvas = tk.Canvas(canvas_frame, bg=BG_DEEP, highlightthickness=0)
        scrollbar = ttk.Scrollbar(canvas_frame, orient='vertical', command=self._canvas.yview)
        self._canvas.configure(yscrollcommand=scrollbar.set)

        self._canvas.grid(row=0, column=0, sticky='nsew')
        scrollbar.grid(row=0, column=1, sticky='ns')

        self._grid_frame = tk.Frame(self._canvas, bg=BG_DEEP)
        self._canvas_window = self._canvas.create_window((0, 0), window=self._grid_frame, anchor='nw')

        self._grid_frame.bind('<Configure>', self._on_grid_configure)
        self._canvas.bind('<Configure>', self._on_canvas_configure)
        self._canvas.bind('<MouseWheel>', lambda e: self._canvas.yview_scroll(-1 * (e.delta // 120), 'units'))

        self._photo_cells: List[dict] = []   # list of {flickr_id, frame, lbl_title, ...}

    def _build_album_sidebar(self, parent):
        frame = tk.Frame(parent, bg=BG_PANEL, bd=0)
        frame.grid(row=0, column=1, sticky='nsew')
        frame.rowconfigure(1, weight=1)
        frame.columnconfigure(0, weight=1)

        tk.Label(frame, text='ALBUMS', bg=BG_PANEL, fg=TEXT_DIM,
                 font=self._font_label, pady=6).grid(row=0, column=0, sticky='ew')

        listbox_frame = tk.Frame(frame, bg=BG_PANEL)
        listbox_frame.grid(row=1, column=0, sticky='nsew')
        listbox_frame.rowconfigure(0, weight=1)
        listbox_frame.columnconfigure(0, weight=1)

        self._album_listbox = tk.Listbox(
            listbox_frame,
            bg=BG_PANEL, fg=TEXT_PRI, selectbackground=BG_ACTIVE,
            selectforeground=TEXT_PRI, activestyle='none',
            relief='flat', bd=0, font=self._font_label,
            exportselection=False,
        )
        sb = ttk.Scrollbar(listbox_frame, orient='vertical', command=self._album_listbox.yview)
        self._album_listbox.configure(yscrollcommand=sb.set)
        self._album_listbox.grid(row=0, column=0, sticky='nsew')
        sb.grid(row=0, column=1, sticky='ns')
        self._album_listbox.bind('<<ListboxSelect>>', self._on_album_select)

        # Unalbumed action
        opts_frame = tk.Frame(frame, bg=BG_PANEL, pady=4, padx=4)
        opts_frame.grid(row=2, column=0, sticky='ew')

        tk.Label(opts_frame, text='Unalbumed:', bg=BG_PANEL, fg=TEXT_DIM,
                 font=self._font_label).pack(side='left')
        self._v_unalbumed = tk.StringVar(value=self._cfg.get('unalbumed_action', 'feed'))
        ttk.Combobox(opts_frame, textvariable=self._v_unalbumed,
                     values=['feed', 'default_album'],
                     width=12, state='readonly').pack(side='left', padx=4)

    def _build_run_panel(self):
        panel = tk.Frame(self, bg=BG_PANEL, pady=6, padx=10)
        panel.grid(row=2, column=0, sticky='ew')
        panel.columnconfigure(1, weight=1)

        # Summary label
        self._lbl_summary = tk.Label(panel, text='Load an export to begin.',
                                      bg=BG_PANEL, fg=TEXT_DIM, font=self._font_label)
        self._lbl_summary.grid(row=0, column=0, sticky='w')

        # Progress bar
        self._progress_var = tk.DoubleVar(value=0)
        self._progress_bar = ttk.Progressbar(panel, variable=self._progress_var,
                                              maximum=100, length=300)
        self._progress_bar.grid(row=0, column=1, sticky='ew', padx=8)

        # Start/Pause/Resume button
        self._btn_run = tk.Button(panel, text='Start Import', bg=ACCENT, fg='#000000',
                                  relief='flat', bd=0, padx=14, pady=5,
                                  font=self._font_bold, cursor='hand2',
                                  command=self._toggle_run, state='disabled')
        self._btn_run.grid(row=0, column=2, padx=(0, 8))

        # Log area
        log_frame = tk.Frame(panel, bg=BG_PANEL)
        log_frame.grid(row=1, column=0, columnspan=3, sticky='ew', pady=(4, 0))
        log_frame.columnconfigure(0, weight=1)

        self._log = tk.Text(log_frame, height=5, bg=BG_DEEP, fg=TEXT_PRI,
                            relief='flat', bd=0, font=self._font_mono,
                            state='disabled', wrap='none')
        log_sb = ttk.Scrollbar(log_frame, orient='vertical', command=self._log.yview)
        self._log.configure(yscrollcommand=log_sb.set)
        self._log.grid(row=0, column=0, sticky='ew')
        log_sb.grid(row=0, column=1, sticky='ns')

    # ------------------------------------------------------------------
    # Actions
    # ------------------------------------------------------------------

    def _browse_folder(self):
        folder = filedialog.askdirectory(title='Select Flickr export folder')
        if folder:
            self._v_folder.set(folder)

    def _load_export(self):
        folder = self._v_folder.get().strip()
        if not folder or not os.path.isdir(folder):
            messagebox.showerror('FLKR FCKR', 'Please select a valid export folder first.')
            return

        self._log_write('Parsing Flickr export…', TEXT_DIM)
        self._save_settings()

        def _parse():
            result = flickr_parser.parse(folder)
            self._q.put(('parse_done', result))

        threading.Thread(target=_parse, daemon=True).start()

    def _on_parse_done(self, result: flickr_parser.ParseResult):
        self._parse_result = result

        for err in result.errors:
            self._log_write(f'WARN: {err}', TEXT_WARN)

        stats = result.stats
        self._log_write(
            f"Loaded: {stats.get('total_photos', 0)} photos, "
            f"{stats.get('total_albums', 0)} albums"
            + (f", {stats.get('missing_images', 0)} missing image files" if stats.get('missing_images') else '')
            + (f", {stats.get('private_photos', 0)} private" if stats.get('private_photos') else ''),
            ACCENT,
        )

        self._populate_album_sidebar(result)
        self._render_grid(result.photos)
        self._update_summary()
        self._btn_run.config(state='normal')

    def _populate_album_sidebar(self, result: flickr_parser.ParseResult):
        self._album_listbox.delete(0, 'end')
        self._album_listbox.insert('end', f'All ({len(result.photos)})')
        self._album_listbox.itemconfigure(0, foreground=ACCENT)
        self._album_listbox.select_set(0)

        for album in result.albums:
            count = len(album.photo_ids)
            self._album_listbox.insert('end', f'  {album.title[:28]} ({count})')

        # Store album objects for filter lookup
        self._albums_ordered = result.albums

    def _on_album_select(self, event):
        sel = self._album_listbox.curselection()
        if not sel:
            return
        idx = sel[0]
        if idx == 0:
            self._v_filter.set('all')
            self._apply_filter()
        else:
            album = self._albums_ordered[idx - 1]
            self._album_filter = album.flickr_id
            self._v_filter.set('album')
            self._apply_filter()

    def _apply_filter(self):
        if not self._parse_result:
            return
        flt = self._v_filter.get()
        if flt == 'all':
            photos = self._parse_result.photos
        elif flt == 'unalbumed':
            photos = [p for p in self._parse_result.photos if not p.album_ids]
        elif flt == 'album':
            fid = self._album_filter
            photos = [p for p in self._parse_result.photos if fid in p.album_ids]
        else:
            photos = self._parse_result.photos
        self._render_grid(photos)

    def _render_grid(self, photos):
        """Re-render the photo grid with the given list of ParsedPhoto objects."""
        for widget in self._grid_frame.winfo_children():
            widget.destroy()
        self._photo_cells.clear()

        COLS = 4
        for i, photo in enumerate(photos):
            row, col = divmod(i, COLS)
            self._make_cell(photo, row, col)

        total = len(photos)
        excluded = sum(1 for p in photos if p.excluded)
        self._lbl_grid_count.config(text=f'{total} photos' + (f'  ({excluded} excluded)' if excluded else ''))

    def _make_cell(self, photo, row: int, col: int):
        """Create a single photo cell in the grid."""
        cell = tk.Frame(self._grid_frame, bg=BG_CELL, bd=1, relief='flat',
                        highlightbackground=BORDER, highlightthickness=1)
        cell.grid(row=row, column=col, padx=3, pady=3, sticky='nsew')
        self._grid_frame.columnconfigure(col, weight=1)

        # Thumbnail placeholder (grey box)
        thumb_lbl = tk.Label(cell, text='', bg='#333333', width=14, height=6)
        thumb_lbl.pack(fill='x')

        # Title
        title_lbl = tk.Label(cell, text=_e(photo.title), bg=BG_CELL, fg=TEXT_PRI,
                             font=self._font_label, anchor='w', wraplength=140)
        title_lbl.pack(fill='x', padx=4, pady=(2, 0))

        # Date
        date_str = photo.date_taken.strftime('%Y-%m-%d') if photo.date_taken else '?'
        date_lbl = tk.Label(cell, text=date_str, bg=BG_CELL, fg=TEXT_DIM,
                            font=self._font_label, anchor='w')
        date_lbl.pack(fill='x', padx=4)

        # Status badge
        badge_text = ''
        badge_fg   = TEXT_DIM
        if photo.missing_image:
            badge_text = 'MISSING IMAGE'
            badge_fg   = TEXT_ERR
        elif photo.privacy != 'public':
            badge_text = f'PRIVATE → {self._v_private.get().upper()}'
            badge_fg   = TEXT_WARN
        elif photo.excluded:
            badge_text = 'EXCLUDED'
            badge_fg   = TEXT_DIM

        if badge_text:
            tk.Label(cell, text=badge_text, bg=BG_CELL, fg=badge_fg,
                     font=self._font_label, anchor='w').pack(fill='x', padx=4, pady=(0, 2))

        # Toggle exclude on click
        def _toggle(event, p=photo, c=cell):
            if p.missing_image:
                return
            p.excluded = not p.excluded
            c.configure(bg=BG_DEEP if p.excluded else BG_CELL)
            self._update_summary()

        for widget in (cell, thumb_lbl, title_lbl, date_lbl):
            widget.bind('<Button-1>', _toggle)

        cell.config(bg=BG_DEEP if photo.excluded else BG_CELL)

        self._photo_cells.append({'flickr_id': photo.flickr_id, 'cell': cell})

    def _show_help(self):
        """Open in-app help window."""
        win = tk.Toplevel(self)
        win.title('FLKR FCKR — Help')
        win.configure(bg=BG_DEEP)
        win.geometry('640x620')
        win.resizable(True, True)

        # Scrollable text area
        frame = tk.Frame(win, bg=BG_DEEP)
        frame.pack(fill='both', expand=True, padx=12, pady=10)
        frame.rowconfigure(0, weight=1)
        frame.columnconfigure(0, weight=1)

        txt = tk.Text(frame, bg=BG_PANEL, fg=TEXT_PRI, relief='flat', bd=0,
                      font=self._font_ui, wrap='word', state='normal',
                      padx=12, pady=10, cursor='arrow')
        sb = ttk.Scrollbar(frame, orient='vertical', command=txt.yview)
        txt.configure(yscrollcommand=sb.set)
        txt.grid(row=0, column=0, sticky='nsew')
        sb.grid(row=0, column=1, sticky='ns')

        # Tag styles
        txt.tag_configure('h1',   font=font.Font(family='Segoe UI', size=13, weight='bold'), foreground=ACCENT, spacing3=6)
        txt.tag_configure('h2',   font=font.Font(family='Segoe UI', size=10, weight='bold'), foreground=TEXT_PRI, spacing1=10, spacing3=4)
        txt.tag_configure('body', font=self._font_ui, foreground=TEXT_PRI, spacing3=4)
        txt.tag_configure('dim',  font=self._font_ui, foreground=TEXT_DIM, spacing3=4)
        txt.tag_configure('code', font=self._font_mono, foreground=ACCENT, background=BG_CELL)

        def h1(t):  txt.insert('end', t + '\n', 'h1')
        def h2(t):  txt.insert('end', t + '\n', 'h2')
        def p(t):   txt.insert('end', t + '\n', 'body')
        def dim(t): txt.insert('end', t + '\n', 'dim')
        def br():   txt.insert('end', '\n')

        h1('FLKR FCKR — Flickr → SnapSmack Migration Tool')
        p('Migrates your Flickr photo archive to a self-hosted SnapSmack photoblog. '
          'Runs on your computer, not on your server — because a large Flickr archive '
          '(thousands of photos, gigabytes of data) cannot run inside a PHP request. '
          'FLKR FCKR handles the heavy work locally and talks to your server at a '
          'throttled rate you control.')
        br()

        h2('Quick Start')
        p('1. Download and unzip your Flickr data export (Account Settings → Your Flickr Data → Request your archive).')
        p('2. In your SnapSmack admin panel, go to Boring Ass Stuff → API Keys and generate a new key with type "FLKR FCKR Import". Copy it — shown only once.')
        p('3. Enter your site URL and API key in the settings bar above. Click Test to verify.')
        p('4. Browse to your unzipped Flickr export folder and click Load Export.')
        p('5. Review the photo grid. Click any tile to exclude it. Use the album sidebar to filter by album.')
        p('6. Click Start Import. Pause and resume at any time. If interrupted, FLKR FCKR offers to resume on next launch.')
        br()

        h2('Settings')
        h2('  Site URL')
        p('The full URL of your SnapSmack install, e.g. https://myphotoblog.com — no trailing slash.')
        h2('  API Key')
        p('The FLKR FCKR Import key generated from your SnapSmack admin panel. '
          'Revoke it when your import is done.')
        h2('  Export Folder')
        p('The root of your unzipped Flickr export. Should contain albums.json and '
          'many photo_XXXXXXXX.json sidecar files.')
        h2('  Throttle (seconds)')
        p('Delay between API calls after each photo is imported. Default 1.5s is safe '
          'for shared hosting. Lower it carefully on a fast VPS; raise it if your host '
          'is slow or rate-limits aggressively.')
        h2('  Private →')
        p('What to do with photos marked private or friends-only on Flickr. '
          '"draft" imports them as unpublished drafts (recommended). '
          '"published" makes everything live regardless of Flickr privacy.')
        br()

        h2('Photo Grid')
        p('Each tile shows the photo title, date, and any warnings (MISSING IMAGE, PRIVATE). '
          'Click a tile to toggle it excluded — excluded photos are skipped during import. '
          'The album sidebar lets you filter by album or see unalbumed photos only.')
        br()

        h2('Unalbumed Photos')
        p('Photos that belong to no Flickr album. Choose "feed" to import them to the '
          'main image feed with no album assignment, or "default_album" to put them '
          'all into a named album (enter the album name in the settings).')
        br()

        h2('Comments & Commenter Names')
        p('Comments left on your Flickr photos are imported automatically and appear '
          'in each photo\'s comment thread. Flickr only stores the commenter\'s ID '
          '(e.g. 196612229@N08), not their name, so by default that ID is shown. To '
          'give the people who matter their real names, create a file named '
          'flkrfckr-names.json in your export folder (or next to this app) mapping '
          'IDs (or profile slugs) to names, e.g. {"196612229@N08": "Ray van der Woning"}. '
          'FLKR FCKR reads it on Load Export. Entries whose key starts with _ are notes.')
        br()

        h2('Resume After Interruption')
        p('If FLKR FCKR is closed or crashes mid-import, a checkpoint file is written '
          'after every successfully imported photo. On next launch, FLKR FCKR detects '
          'the checkpoint and offers to resume. Already-imported Flickr IDs are skipped '
          'instantly — no duplicates, no re-processing.')
        br()

        h2('How Images Are Transferred')
        p('FLKR FCKR resizes each photo locally, then uploads it to your site over '
          'the same secure HTTPS connection it uses for the API — no FTP, no separate '
          'credentials. The server stores the image and generates thumbnails for you.')
        br()

        h2('After the Import')
        p('Go back to your SnapSmack admin panel and revoke the FLKR FCKR API key — '
          'you do not need it again. If any photos failed (usually a dropped '
          'connection), re-run FLKR FCKR against the same export folder with the same '
          'settings. Duplicates are detected and skipped automatically.')
        br()

        dim('FLKR FCKR is part of the SnapSmack companion tool family alongside '
            'Smack Your Batch Up (batch posting), Smack Up Your Backup (backups), '
            'and SmackPress (WordPress migration). All upload over HTTPS with an API '
            'key and ship as standalone Windows executables.')

        txt.configure(state='disabled')

        tk.Button(win, text='Close', bg=BG_CELL, fg=TEXT_PRI, relief='flat',
                  bd=0, padx=14, pady=5, font=self._font_bold, cursor='hand2',
                  command=win.destroy).pack(pady=(0, 10))

    def _test_connection(self):
        url = self._v_url.get().strip()
        key = self._v_key.get().strip()
        if not url or not key:
            self._lbl_conn.config(text='URL and key required', fg=TEXT_ERR)
            return
        self._lbl_conn.config(text='Testing…', fg=TEXT_DIM)
        self._save_settings()

        def _test():
            client = FlkrDckrClient(url, key)
            ok, msg = client.ping()
            self._q.put(('conn_result', ok, msg))

        threading.Thread(target=_test, daemon=True).start()

    def _toggle_run(self):
        if not self._running:
            self._start_import()
        elif self._paused:
            self._resume_import()
        else:
            self._pause_import()

    def _start_import(self):
        if not self._parse_result:
            return
        photos = [p for p in self._parse_result.photos if not p.missing_image]
        if not photos:
            messagebox.showwarning('FLKR FCKR', 'No photos to import.')
            return

        self._save_settings()
        cfg = cfg_mod.load()

        url = cfg.get('site_url', '').strip()
        key = cfg.get('api_key', '').strip()
        if not url or not key:
            messagebox.showerror('FLKR FCKR', 'Site URL and API key are required.')
            return

        self._client = FlkrDckrClient(url, key)

        # Reuse an existing checkpoint when resuming (or retrying failures from a
        # previous run); only start a fresh one otherwise. Calling start() on a
        # resumed checkpoint would wipe its 'imported' map and re-import
        # everything. If the checkpoint belongs to a different export, discard it.
        if (self._checkpoint is not None
                and self._checkpoint.data.get('export_folder') != cfg.get('export_folder', '')):
            self._checkpoint = None
        if self._checkpoint is None:
            self._checkpoint = ImportCheckpoint(ImportCheckpoint.path_for())
            self._checkpoint.start(
                export_folder=cfg.get('export_folder', ''),
                site_url=url,
                total_photos=len(photos),
            )
        else:
            self._checkpoint.update_total(len(photos))

        staging = tempfile.mkdtemp(prefix='flkrfckr_')

        # Build album map from parse result
        flickr_album_map = {
            a.flickr_id: {'title': a.title, 'description': a.description}
            for a in (self._parse_result.albums or [])
        }

        self._running      = True
        self._paused       = False
        self._stop_event.clear()
        self._pause_event.set()

        self._btn_run.config(text='Pause', bg=TEXT_WARN, fg='#000000')
        self._progress_var.set(0)

        total = len(photos)

        def _on_progress(done, total, result):
            pct = (done / total) * 100
            colour = _status_colour(result.message)
            status = 'DUP' if result.duplicate else ('ERR' if not result.success else 'OK')
            self._q.put(('progress', pct, f"[{status}] {result.flickr_id} — {result.message}", colour))

        def _run():
            try:
                run_import(
                    client=self._client,
                    photos=photos,
                    staging_dir=staging,
                    checkpoint=self._checkpoint,
                    flickr_album_map=flickr_album_map,
                    private_status=cfg.get('private_status', 'draft'),
                    unalbumed_action=cfg.get('unalbumed_action', 'feed'),
                    default_album=cfg.get('default_album', ''),
                    throttle_delay=float(cfg.get('throttle_delay', 1.5)),
                    on_progress=_on_progress,
                    stop_event=self._stop_event,
                    pause_event=self._pause_event,
                )
            except Exception as e:
                self._q.put(('log', f'FATAL: {e}', TEXT_ERR))
            finally:
                self._q.put(('done',))

        threading.Thread(target=_run, daemon=True).start()

    def _pause_import(self):
        self._paused = True
        self._pause_event.clear()
        self._btn_run.config(text='Resume', bg=ACCENT, fg='#000000')
        self._log_write('Import paused.', TEXT_WARN)

    def _resume_import(self):
        self._paused = False
        self._pause_event.set()
        self._btn_run.config(text='Pause', bg=TEXT_WARN, fg='#000000')
        self._log_write('Import resumed.', ACCENT)

    def _on_import_done(self):
        self._running = False
        self._paused  = False
        self._btn_run.config(text='Start Import', bg=ACCENT, fg='#000000')
        self._progress_var.set(100)
        self._log_write('Import complete.', ACCENT)
        if self._checkpoint:
            prog = self._checkpoint.progress()
            self._log_write(
                f"Done: {prog['imported']} imported, "
                f"{prog['failed']} failed, "
                f"{prog['skipped']} skipped.",
                TEXT_PRI,
            )
            if prog['failed'] == 0:
                # Clean run — drop the checkpoint so the next import starts fresh.
                self._checkpoint.delete()
                self._checkpoint = None
            # If anything failed, keep the checkpoint: clicking Start again will
            # skip the already-imported photos and retry only the failures.

    def _update_summary(self):
        if not self._parse_result:
            return
        total    = len(self._parse_result.photos)
        selected = sum(1 for p in self._parse_result.photos if not p.excluded and not p.missing_image)
        missing  = sum(1 for p in self._parse_result.photos if p.missing_image)
        self._lbl_summary.config(
            text=f'{selected} of {total} photos selected for import'
                 + (f'  ({missing} missing image files)' if missing else ''),
            fg=TEXT_PRI,
        )

    # ------------------------------------------------------------------
    # Resume from checkpoint
    # ------------------------------------------------------------------

    def _check_resume(self):
        cp = ImportCheckpoint.load()
        if not cp:
            return
        prog = cp.progress()
        if prog['imported'] == 0:
            cp.delete()
            return
        answer = messagebox.askyesno(
            'FLKR FCKR',
            f"A previous import was interrupted.\n"
            f"{prog['imported']} photos already imported.\n\n"
            f"Resume from where it left off?"
        )
        if answer:
            self._checkpoint = cp
            folder = cp.data.get('export_folder', '')
            self._v_folder.set(folder)
            self._log_write(f"Resuming — {prog['imported']} already done.", ACCENT)
            # Auto-load the export so the grid populates and Start is enabled;
            # the retained checkpoint means already-imported photos are skipped.
            if folder and os.path.isdir(folder):
                self._load_export()
            else:
                self._log_write(
                    "Export folder not found — set it and click Load Export to resume.",
                    TEXT_WARN,
                )
        else:
            cp.delete()

    # ------------------------------------------------------------------
    # Queue polling (main thread receives updates from worker threads)
    # ------------------------------------------------------------------

    def _poll_queue(self):
        try:
            while True:
                msg = self._q.get_nowait()
                kind = msg[0]

                if kind == 'parse_done':
                    self._on_parse_done(msg[1])
                elif kind == 'conn_result':
                    ok, text = msg[1], msg[2]
                    self._lbl_conn.config(text=text, fg=ACCENT if ok else TEXT_ERR)
                    if ok:
                        self._log_write(text, ACCENT)
                elif kind == 'progress':
                    _, pct, text, colour = msg
                    self._progress_var.set(pct)
                    self._log_write(text, colour)
                elif kind == 'log':
                    self._log_write(msg[1], msg[2] if len(msg) > 2 else TEXT_PRI)
                elif kind == 'done':
                    self._on_import_done()

        except queue.Empty:
            pass
        self.after(80, self._poll_queue)

    # ------------------------------------------------------------------
    # Log
    # ------------------------------------------------------------------

    def _log_write(self, text: str, colour: str = TEXT_PRI):
        self._log.configure(state='normal')
        self._log.insert('end', text + '\n', ('coloured',))
        self._log.tag_configure('coloured', foreground=colour)
        # Re-apply colour to last line only
        idx = self._log.index('end-2l')
        self._log.tag_add(colour, idx, 'end-1c')
        self._log.tag_configure(colour, foreground=colour)
        self._log.see('end')
        self._log.configure(state='disabled')

    # ------------------------------------------------------------------
    # Settings persistence
    # ------------------------------------------------------------------

    def _save_settings(self):
        data = cfg_mod.load()
        data.update({
            'site_url':         self._v_url.get().strip(),
            'api_key':          self._v_key.get().strip(),
            'export_folder':    self._v_folder.get().strip(),
            'throttle_delay':   self._v_throttle.get(),
            'private_status':   self._v_private.get(),
            'unalbumed_action': self._v_unalbumed.get(),
        })
        cfg_mod.save(data)

    # ------------------------------------------------------------------
    # Canvas / grid helpers
    # ------------------------------------------------------------------

    def _on_grid_configure(self, event):
        self._canvas.configure(scrollregion=self._canvas.bbox('all'))

    def _on_canvas_configure(self, event):
        self._canvas.itemconfig(self._canvas_window, width=event.width)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    app = FlkrDckrApp()
    app.mainloop()
# ===== SNAPSMACK EOF =====