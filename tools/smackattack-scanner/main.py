"""
GOBSMACKED Scanner — main.py
Local desktop tool for running stylometric scans against a SnapSmack database.
Detects commenters whose writing style matches banned users or each other.

Python 3.10+ | tkinter | pymysql
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


BUILD_VERSION = "0.1.0"

import os
import sys
import json
import queue
import threading
import tkinter as tk
from tkinter import messagebox, ttk
from datetime import datetime
from typing import Optional

import config as cfg_module
import db as db_module
import scanner as scanner_module
import smackattack_core as core_module

# ─── Debug log ────────────────────────────────────────────────────────────────

def _setup_log() -> None:
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    log_path = os.path.join(base, 'gobsmacked-debug.log')
    try:
        lf = open(log_path, 'a', encoding='utf-8', buffering=1)
        lf.write(f"\n{'='*60}\nGOBSMACKED Scanner {BUILD_VERSION}  —  {datetime.now():%Y-%m-%d %H:%M:%S}\n{'='*60}\n")
        sys.stdout = lf
        sys.stderr = lf
    except Exception:
        pass

_setup_log()

# ─── Palette (Midnight Lime — matches SYBU) ───────────────────────────────────

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
FG_YELL  = "#D4D400"

FONT_UI  = ("Inter", 10)
FONT_SM  = ("Inter", 9)
FONT_LG  = ("Inter", 12, "bold")
FONT_HDR = ("Inter", 9, "bold")
FONT_MONO= ("Consolas", 9)

# ─── Shared state ─────────────────────────────────────────────────────────────

_cfg  : dict       = {}
_conn              = None    # live pymysql connection
_msg_q : queue.Queue = queue.Queue()


# ─── Helpers ──────────────────────────────────────────────────────────────────

def _cfg_get(key: str) -> str:
    return _cfg.get(key, '')

def _try_connect() -> Optional[object]:
    try:
        c = db_module.connect(_cfg)
        return c
    except Exception as e:
        return None

def _post(fn, *args):
    """Schedule fn(*args) on the main thread via the message queue."""
    _msg_q.put((fn, args))


# ─────────────────────────────────────────────────────────────────────────────
# MAIN WINDOW
# ─────────────────────────────────────────────────────────────────────────────

class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title(f"GOBSMACKED Scanner  v{BUILD_VERSION}")
        self.geometry("1000x680")
        self.minsize(820, 560)
        self.configure(bg=BG_DEEP)
        self._build_style()
        self._build_ui()
        self._pump_queue()

    # ── Style ──────────────────────────────────────────────────────────────────

    def _build_style(self):
        s = ttk.Style(self)
        s.theme_use('clam')
        s.configure('TNotebook',         background=BG_DEEP,  borderwidth=0)
        s.configure('TNotebook.Tab',     background=BG_CARD,  foreground=FG_DIM,
                    padding=[14, 6],     font=FONT_HDR,        borderwidth=0)
        s.map('TNotebook.Tab',
              background=[('selected', BG_DEEP)],
              foreground=[('selected', ACCENT)])
        s.configure('TFrame',    background=BG_DEEP)
        s.configure('TLabel',    background=BG_DEEP,  foreground=FG_MAIN, font=FONT_UI)
        s.configure('TEntry',    fieldbackground=BG_MID, foreground=FG_MAIN,
                    insertcolor=ACCENT, font=FONT_MONO, relief='flat', borderwidth=1)
        s.configure('TButton',   background=ACCENT,  foreground='#000',
                    font=FONT_HDR,  relief='flat',  padding=[12, 6])
        s.map('TButton',
              background=[('active', '#2ECC10'), ('disabled', BG_CARD)],
              foreground=[('disabled', FG_DIM)])
        s.configure('Dim.TLabel',  background=BG_DEEP, foreground=FG_DIM,  font=FONT_SM)
        s.configure('Accent.TLabel', background=BG_DEEP, foreground=ACCENT, font=FONT_LG)
        s.configure('OK.TLabel',   background=BG_DEEP, foreground=FG_OK,   font=FONT_SM)
        s.configure('Err.TLabel',  background=BG_DEEP, foreground=FG_ERR,  font=FONT_SM)
        s.configure('Treeview',    background=BG_CARD, foreground=FG_MAIN,
                    fieldbackground=BG_CARD, rowheight=26, font=FONT_SM)
        s.configure('Treeview.Heading', background=BG_MID, foreground=FG_DIM,
                    font=FONT_HDR, relief='flat')
        s.map('Treeview', background=[('selected', '#1a3a1a')], foreground=[('selected', ACCENT)])
        s.configure('TProgressbar', troughcolor=BG_MID, background=ACCENT,
                    thickness=6, borderwidth=0)

    # ── Layout ────────────────────────────────────────────────────────────────

    def _build_ui(self):
        # Header bar
        hdr = tk.Frame(self, bg=BG_CARD, height=52)
        hdr.pack(fill='x', side='top')
        tk.Label(hdr, text="GOBSMACKED SCANNER", bg=BG_CARD, fg=ACCENT,
                 font=("Inter", 13, "bold")).pack(side='left', padx=20, pady=12)
        tk.Label(hdr, text=f"v{BUILD_VERSION}", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_SM).pack(side='left', pady=12)

        # Status bar at bottom
        self._status_var = tk.StringVar(value="Not connected.")
        self._status_lbl = tk.Label(self, textvariable=self._status_var,
                                    bg=BG_DEEP, fg=FG_DIM, font=FONT_SM,
                                    anchor='w', padx=14)
        self._status_lbl.pack(fill='x', side='bottom', pady=(0, 6))

        # Notebook
        nb = ttk.Notebook(self)
        nb.pack(fill='both', expand=True, padx=0, pady=0)

        self._tab_settings = SettingsTab(nb, self)
        self._tab_scan     = ScanTab(nb, self)
        self._tab_results  = ResultsTab(nb, self)
        self._tab_central  = SmackCentralTab(nb, self)

        nb.add(self._tab_settings, text='  SETTINGS  ')
        nb.add(self._tab_scan,     text='  SCAN  ')
        nb.add(self._tab_results,  text='  RESULTS  ')
        nb.add(self._tab_central,  text='  SMACK CENTRAL  ')

        self._nb = nb

    # ── Queue pump ────────────────────────────────────────────────────────────

    def _pump_queue(self):
        try:
            while True:
                fn, args = _msg_q.get_nowait()
                fn(*args)
        except queue.Empty:
            pass
        self.after(60, self._pump_queue)

    # ── Public helpers ────────────────────────────────────────────────────────

    def set_status(self, msg: str, colour: str = FG_DIM):
        self._status_var.set(msg)
        self._status_lbl.configure(fg=colour)

    def open_results(self):
        self._nb.select(2)
        self._tab_results.refresh()


# ─────────────────────────────────────────────────────────────────────────────
# TAB: SETTINGS
# ─────────────────────────────────────────────────────────────────────────────

class SettingsTab(ttk.Frame):
    def __init__(self, parent, app: App):
        super().__init__(parent)
        self._app = app
        self._entries: dict[str, tk.StringVar] = {}
        self._build()
        self._load()

    def _field(self, parent, label: str, key: str, show: str = '') -> ttk.Entry:
        row = ttk.Frame(parent)
        row.pack(fill='x', pady=4)
        ttk.Label(row, text=label, width=22, anchor='e').pack(side='left', padx=(0, 10))
        var = tk.StringVar()
        e = ttk.Entry(row, textvariable=var, show=show, width=42)
        e.pack(side='left')
        self._entries[key] = var
        return e

    def _build(self):
        pad = ttk.Frame(self)
        pad.pack(fill='both', expand=True, padx=40, pady=30)

        ttk.Label(pad, text="DATABASE CONNECTION", style='Accent.TLabel').pack(anchor='w', pady=(0, 16))

        db_frame = ttk.Frame(pad)
        db_frame.pack(fill='x')
        self._field(db_frame, "Host",     'db_host')
        self._field(db_frame, "Port",     'db_port')
        self._field(db_frame, "Database", 'db_name')
        self._field(db_frame, "Username", 'db_user')
        self._field(db_frame, "Password", 'db_password', show='•')

        ttk.Label(pad, text="", style='Dim.TLabel').pack()
        ttk.Label(pad, text="SMACK CENTRAL ADMIN API  (SS-1457)", style='Accent.TLabel').pack(anchor='w', pady=(10, 16))

        api_frame = ttk.Frame(pad)
        api_frame.pack(fill='x')
        self._field(api_frame, "Central API URL", 'api_url')
        self._field(api_frame, "Admin Token",     'admin_token', show='•')
        kr_note = (
            "Stored in OS Credential Store (keyring) only — never written to gobsmacked-scanner.ini."
            if cfg_module.has_keyring()
            else "⚠ OS Credential Store (keyring) unavailable — Admin Token cannot be saved to disk."
        )
        ttk.Label(pad, text=kr_note,
                  style='OK.TLabel' if cfg_module.has_keyring() else 'Err.TLabel').pack(
                  anchor='w', padx=(180, 0), pady=(0, 8))

        ttk.Label(pad, text="", style='Dim.TLabel').pack()
        ttk.Label(pad, text="SCAN PARAMETERS", style='Accent.TLabel').pack(anchor='w', pady=(10, 16))

        scan_frame = ttk.Frame(pad)
        scan_frame.pack(fill='x')
        self._field(scan_frame, "Similarity Threshold", 'threshold')
        ttk.Label(pad, text="Flag matches at or above this cosine similarity (0.0–1.0). Default: 0.55",
                  style='Dim.TLabel').pack(anchor='w', padx=(180, 0), pady=(0, 8))
        self._field(scan_frame, "Minimum Words", 'min_words')
        ttk.Label(pad, text="Skip authors with fewer combined words than this. Default: 30",
                  style='Dim.TLabel').pack(anchor='w', padx=(180, 0), pady=(0, 8))

        btn_row = ttk.Frame(pad)
        btn_row.pack(anchor='w', pady=24)
        ttk.Button(btn_row, text="SAVE & TEST CONNECTION", command=self._save_and_test).pack(side='left')
        self._conn_lbl = ttk.Label(btn_row, text="", style='Dim.TLabel')
        self._conn_lbl.pack(side='left', padx=16)

    def _load(self):
        global _cfg
        _cfg = cfg_module.load()
        for k, var in self._entries.items():
            var.set(_cfg.get(k, ''))

    def _save_and_test(self):
        global _cfg, _conn
        for k, var in self._entries.items():
            _cfg[k] = var.get().strip()
        try:
            cfg_module.save(_cfg)
        except Exception as e:
            self._conn_lbl.configure(text=f"✗ {e}", foreground=FG_ERR)
            self._app.set_status(f"Save failed: {e}", FG_ERR)
            return
        if not _cfg.get('db_name'):
            self._conn_lbl.configure(text="✓ Settings saved", foreground=FG_OK)
            self._app.set_status("Settings saved (OS credential store updated).", FG_OK)
            return
        self._conn_lbl.configure(text="Connecting…", foreground=FG_DIM)
        self.update_idletasks()

        try:
            if _conn:
                try: _conn.close()
                except Exception: pass
            _conn = db_module.connect(_cfg)
            _conn.ping(reconnect=True)
            self._conn_lbl.configure(text="✓ Connected", foreground=FG_OK)
            self._app.set_status(f"Connected to {_cfg['db_name']}@{_cfg['db_host']}", FG_OK)
        except Exception as e:
            _conn = None
            self._conn_lbl.configure(text=f"✗ {e}", foreground=FG_ERR)
            self._app.set_status(f"Connection failed: {e}", FG_ERR)


# ─────────────────────────────────────────────────────────────────────────────
# TAB: SCAN
# ─────────────────────────────────────────────────────────────────────────────

class ScanTab(ttk.Frame):
    def __init__(self, parent, app: App):
        super().__init__(parent)
        self._app     = app
        self._running = False
        self._build()

    def _build(self):
        pad = ttk.Frame(self)
        pad.pack(fill='both', expand=True, padx=40, pady=30)

        ttk.Label(pad, text="STYLOMETRIC SCAN", style='Accent.TLabel').pack(anchor='w', pady=(0, 8))
        ttk.Label(pad,
            text="Fetches approved comments from the database, computes 25-dimension writing style\n"
                 "vectors, and compares all authors against each other and any stored ban profiles.\n"
                 "Matches above the similarity threshold are stored in snap_gobsmacked_scan.",
            style='Dim.TLabel').pack(anchor='w', pady=(0, 24))

        # Stats row
        stats_frame = tk.Frame(pad, bg=BG_CARD, padx=16, pady=12)
        stats_frame.pack(fill='x', pady=(0, 20))
        self._lbl_authors = tk.Label(stats_frame, text="—", bg=BG_CARD, fg=ACCENT, font=("Inter", 18, "bold"))
        self._lbl_authors.grid(row=0, column=0, padx=20)
        tk.Label(stats_frame, text="AUTHORS WITH\nENOUGH TEXT", bg=BG_CARD, fg=FG_DIM, font=FONT_SM).grid(row=0, column=1, padx=4)
        tk.Label(stats_frame, text=" ", bg=BG_CARD, fg=FG_DIM).grid(row=0, column=2, padx=20)
        self._lbl_pairs = tk.Label(stats_frame, text="—", bg=BG_CARD, fg=ACCENT, font=("Inter", 18, "bold"))
        self._lbl_pairs.grid(row=0, column=3, padx=20)
        tk.Label(stats_frame, text="PAIRS\nCOMPARED", bg=BG_CARD, fg=FG_DIM, font=FONT_SM).grid(row=0, column=4, padx=4)
        tk.Label(stats_frame, text=" ", bg=BG_CARD, fg=FG_DIM).grid(row=0, column=5, padx=20)
        self._lbl_flags = tk.Label(stats_frame, text="—", bg=BG_CARD, fg=FG_WARN, font=("Inter", 18, "bold"))
        self._lbl_flags.grid(row=0, column=6, padx=20)
        tk.Label(stats_frame, text="FLAGS\nISSUED", bg=BG_CARD, fg=FG_DIM, font=FONT_SM).grid(row=0, column=7, padx=4)

        # Progress
        self._progress_var = tk.DoubleVar(value=0.0)
        self._progress_bar = ttk.Progressbar(pad, variable=self._progress_var,
                                              maximum=100, mode='determinate')
        self._progress_bar.pack(fill='x', pady=(0, 6))
        self._progress_lbl = ttk.Label(pad, text="", style='Dim.TLabel')
        self._progress_lbl.pack(anchor='w', pady=(0, 16))

        # Log
        log_frame = tk.Frame(pad, bg=BG_MID)
        log_frame.pack(fill='both', expand=True, pady=(0, 16))
        self._log = tk.Text(log_frame, bg=BG_MID, fg=FG_DIM, font=FONT_MONO,
                            state='disabled', relief='flat', padx=10, pady=8,
                            wrap='word', height=10)
        log_scroll = ttk.Scrollbar(log_frame, command=self._log.yview)
        self._log.configure(yscrollcommand=log_scroll.set)
        log_scroll.pack(side='right', fill='y')
        self._log.pack(fill='both', expand=True)
        self._log.tag_configure('ok',   foreground=FG_OK)
        self._log.tag_configure('warn', foreground=FG_WARN)
        self._log.tag_configure('err',  foreground=FG_ERR)
        self._log.tag_configure('dim',  foreground=FG_DIM)

        btn_row = ttk.Frame(pad)
        btn_row.pack(anchor='w')
        self._btn_run = ttk.Button(btn_row, text="RUN SCAN", command=self._run)
        self._btn_run.pack(side='left')
        ttk.Button(btn_row, text="VIEW RESULTS", command=self._app.open_results).pack(side='left', padx=12)

    def _log_write(self, msg: str, tag: str = ''):
        self._log.configure(state='normal')
        self._log.insert('end', msg + '\n', tag)
        self._log.see('end')
        self._log.configure(state='disabled')

    def _run(self):
        global _conn
        if self._running:
            return
        if not _conn:
            _conn = _try_connect()
        if not _conn:
            messagebox.showerror("No Connection",
                "Not connected to a database. Configure the connection in Settings first.")
            return

        self._running = True
        self._btn_run.configure(state='disabled')
        self._progress_var.set(0)
        self._progress_lbl.configure(text="Starting…")
        self._log.configure(state='normal')
        self._log.delete('1.0', 'end')
        self._log.configure(state='disabled')
        self._log_write(f"Scan started at {datetime.now():%H:%M:%S}", 'dim')

        threshold = float(_cfg.get('threshold', '0.55'))
        min_words = int(_cfg.get('min_words', '30'))

        threading.Thread(target=self._scan_thread,
                         args=(_conn, threshold, min_words), daemon=True).start()

    def _scan_thread(self, conn, threshold: float, min_words: int):
        try:
            # 1. Fetch authors
            _post(self._progress_lbl.configure, text="Fetching comments from database…")
            _post(self._log_write, "Fetching approved comments…", 'dim')
            authors = db_module.fetch_comment_authors(conn, min_words)
            n = len(authors)
            _post(self._lbl_authors.configure, text=str(n))
            _post(self._log_write, f"Found {n} authors with enough text.", 'ok')

            if n < 2:
                _post(self._log_write, "Not enough authors to compare. Add more comments and retry.", 'warn')
                _post(self._progress_lbl.configure, text="Scan complete — not enough data.")
                _post(self._progress_var.set, 100.0)
                _post(self._btn_run.configure, state='normal')
                self._running = False
                return

            # 2. Compute vectors
            _post(self._log_write, "Computing stylometric vectors…", 'dim')
            vectors: dict[str, list[float]] = {}
            meta:    dict[str, dict]         = {}
            for key, info in authors.items():
                vec = scanner_module.extract_vector(info['texts'])
                if vec:
                    vectors[key] = vec
                    meta[key]    = info

            _post(self._log_write, f"Vectors computed for {len(vectors)} authors.", 'ok')

            # 3. Fetch banned vectors
            banned = db_module.fetch_banned_vectors(conn)
            _post(self._log_write, f"Loaded {len(banned)} banned style profiles.", 'dim')

            # 4. Compare all pairs
            keys    = list(vectors.keys())
            n_keys  = len(keys)
            total_pairs = (n_keys * (n_keys - 1)) // 2 + n_keys * len(banned)
            _post(self._lbl_pairs.configure, text=str(total_pairs))
            _post(self._log_write, f"Comparing {total_pairs} pairs…", 'dim')

            flags: list[dict] = []
            done  = 0

            # Peer comparisons
            for i in range(n_keys):
                for j in range(i + 1, n_keys):
                    sim = scanner_module.cosine_similarity(vectors[keys[i]], vectors[keys[j]])
                    if sim >= threshold:
                        flags.append({
                            'author_key':  keys[i],
                            'matched_key': keys[j],
                            'match_type':  'peer',
                            'similarity':  sim,
                            'display_name': meta[keys[i]].get('display_name', ''),
                            'email':        meta[keys[i]].get('email', ''),
                        })
                    done += 1
                    if done % 500 == 0:
                        pct = (done / total_pairs) * 100
                        _post(self._progress_var.set, pct)

            # Banned comparisons
            for key in keys:
                for bv in banned:
                    sim = scanner_module.cosine_similarity(vectors[key], bv['vector'])
                    if sim >= threshold:
                        flags.append({
                            'author_key':  key,
                            'matched_key': bv['fp_hash'],
                            'match_type':  'banned',
                            'similarity':  sim,
                            'display_name': meta[key].get('display_name', ''),
                            'email':        meta[key].get('email', ''),
                        })
                    done += 1

            _post(self._progress_var.set, 100.0)
            _post(self._lbl_flags.configure, text=str(len(flags)))

            # 5. Store results
            if flags:
                _post(self._log_write, f"Storing {len(flags)} flagged pair(s)…", 'dim')
                db_module.store_scan_results(conn, flags)
                _post(self._log_write,
                      f"Done. {len(flags)} match(es) above {threshold:.0%} threshold. View in Results tab.",
                      'warn' if flags else 'ok')
            else:
                _post(self._log_write,
                      f"Scan complete. No matches above {threshold:.0%} threshold. Clean.", 'ok')

            _post(self._progress_lbl.configure, text=f"Scan finished at {datetime.now():%H:%M:%S}")
            _post(self._app.set_status,
                  f"Scan complete — {len(flags)} flag(s) | {total_pairs} pairs compared",
                  FG_OK if not flags else FG_WARN)

        except Exception as e:
            _post(self._log_write, f"Error: {e}", 'err')
            _post(self._app.set_status, f"Scan error: {e}", FG_ERR)
        finally:
            self._running = False
            _post(self._btn_run.configure, state='normal')


# ─────────────────────────────────────────────────────────────────────────────
# TAB: RESULTS
# ─────────────────────────────────────────────────────────────────────────────

class ResultsTab(ttk.Frame):
    def __init__(self, parent, app: App):
        super().__init__(parent)
        self._app = app
        self._build()

    def _build(self):
        pad = ttk.Frame(self)
        pad.pack(fill='both', expand=True, padx=20, pady=16)

        ttk.Label(pad, text="FLAGGED MATCHES", style='Accent.TLabel').pack(anchor='w', pady=(0, 12))

        # Filter bar
        fbar = ttk.Frame(pad)
        fbar.pack(fill='x', pady=(0, 10))
        ttk.Label(fbar, text="Show:").pack(side='left')
        self._filter_var = tk.StringVar(value='all')
        for val, lbl in [('all','All'), ('peer','Peer Matches'), ('banned','vs Banned'), ('unreviewed','Unreviewed')]:
            tk.Radiobutton(fbar, text=lbl, variable=self._filter_var, value=val,
                           bg=BG_DEEP, fg=FG_MAIN, selectcolor=BG_DEEP,
                           activebackground=BG_DEEP, font=FONT_SM,
                           command=self.refresh).pack(side='left', padx=8)
        ttk.Button(fbar, text="⟳ Refresh", command=self.refresh).pack(side='right')

        # Tree
        cols = ('sim', 'type', 'author', 'matched', 'email', 'flagged', 'status')
        self._tree = ttk.Treeview(pad, columns=cols, show='headings', selectmode='browse')
        headers = {
            'sim':     ('SIMILARITY', 90),
            'type':    ('TYPE',       90),
            'author':  ('AUTHOR',    180),
            'matched': ('MATCHED',   180),
            'email':   ('EMAIL',     160),
            'flagged': ('FLAGGED',   130),
            'status':  ('STATUS',     80),
        }
        for col, (heading, width) in headers.items():
            self._tree.heading(col, text=heading)
            self._tree.column(col, width=width, minwidth=60, anchor='w')

        vsb = ttk.Scrollbar(pad, orient='vertical',   command=self._tree.yview)
        hsb = ttk.Scrollbar(pad, orient='horizontal', command=self._tree.xview)
        self._tree.configure(yscrollcommand=vsb.set, xscrollcommand=hsb.set)

        self._tree.pack(side='top', fill='both', expand=True)
        hsb.pack(side='bottom', fill='x')
        vsb.pack(side='right',  fill='y')

        self._tree.tag_configure('very_high', foreground='#FF3E3E')
        self._tree.tag_configure('high',      foreground=FG_WARN)
        self._tree.tag_configure('moderate',  foreground=FG_YELL)
        self._tree.tag_configure('reviewed',  foreground=FG_DIM)

        # Action bar
        act = ttk.Frame(pad)
        act.pack(fill='x', pady=(10, 0))
        ttk.Button(act, text="MARK REVIEWED", command=self._mark_reviewed).pack(side='left')
        self._act_lbl = ttk.Label(act, text="", style='Dim.TLabel')
        self._act_lbl.pack(side='left', padx=10)

        self._row_ids: dict = {}   # tree iid → db row id

    def refresh(self):
        global _conn
        self._tree.delete(*self._tree.get_children())
        self._row_ids.clear()

        if not _conn:
            _conn = _try_connect()
        if not _conn:
            return

        rows = db_module.fetch_stored_results(_conn)
        filt = self._filter_var.get()

        shown = 0
        for row in rows:
            mt   = row.get('match_type', 'peer')
            rev  = row.get('reviewed', 0)
            sim  = float(row.get('similarity', 0.0))

            if filt == 'peer'       and mt  != 'peer':    continue
            if filt == 'banned'     and mt  != 'banned':  continue
            if filt == 'unreviewed' and rev  == 1:        continue

            label = scanner_module.similarity_label(sim)
            tag   = label.lower().replace(' ', '_')
            if rev: tag = 'reviewed'

            flagged = str(row.get('flagged_at', ''))[:16]
            status  = 'Reviewed' if rev else 'New'

            iid = self._tree.insert('', 'end', tags=(tag,), values=(
                f"{sim:.1%}",
                mt.upper(),
                (row.get('display_name') or row.get('author_key',''))[:32],
                row.get('matched_key','')[:24] + '…',
                row.get('email', ''),
                flagged,
                status,
            ))
            self._row_ids[iid] = row.get('id', 0)
            shown += 1

        self._act_lbl.configure(text=f"{shown} row(s)")

    def _selected_iid(self):
        sel = self._tree.selection()
        return sel[0] if sel else None

    def _mark_reviewed(self):
        global _conn
        iid = self._selected_iid()
        if not iid or not _conn:
            return
        db_id = self._row_ids.get(iid, 0)
        if db_id:
            db_module.mark_reviewed(_conn, db_id)
        self.refresh()


# ─────────────────────────────────────────────────────────────────────────────
# TAB: SMACK CENTRAL  (SS-1457 Desktop Stylometric Analysis Engine)
# ─────────────────────────────────────────────────────────────────────────────

class SmackCentralTab(ttk.Frame):
    """
    SS-1457 Desktop Stylometric Analysis Engine (deterministic v1).
    Pulls non-expired 25-float vectors from SMACK CENTRAL via
    GET ?route=admin/style/vectors, computes pairwise cosine similarity and
    connected clusters (>= 0.80; STRONG >= 0.95 / LIKELY >= 0.90 / POSSIBLE >= 0.80)
    entirely on Sean's desktop, and uploads results + operator decisions via
    POST ?route=admin/style/results.
    """
    def __init__(self, parent, app: App):
        super().__init__(parent)
        self._app = app
        self._running = False
        self._clusters: list[dict] = []
        self._outcomes: list[dict] = []
        self._pending_dismiss: list[list[int]] = []
        self._pending_escalations: list[dict] = []
        self._cluster_by_iid: dict[str, dict] = {}
        self._build()

    def _build(self):
        pad = ttk.Frame(self)
        pad.pack(fill='both', expand=True, padx=24, pady=16)

        ttk.Label(pad, text="SMACK CENTRAL STYLOMETRIC ENGINE  (SS-1457)",
                  style='Accent.TLabel').pack(anchor='w', pady=(0, 4))
        ttk.Label(pad,
            text="Pulls 25-float style vectors from SMACK CENTRAL (GET ?route=admin/style/vectors), runs deterministic\n"
                 "cosine clustering locally (≥ 80% POSSIBLE, ≥ 90% LIKELY, ≥ 95% STRONG — zero server compute, no AI key,\n"
                 "no raw text), and uploads cluster confidence & operator actions (POST ?route=admin/style/results).",
            style='Dim.TLabel').pack(anchor='w', pady=(0, 14))

        # Stats bar
        stats_frame = tk.Frame(pad, bg=BG_CARD, padx=16, pady=10)
        stats_frame.pack(fill='x', pady=(0, 12))
        self._lbl_vectors = tk.Label(stats_frame, text="—", bg=BG_CARD, fg=ACCENT, font=("Inter", 16, "bold"))
        self._lbl_vectors.grid(row=0, column=0, padx=16)
        tk.Label(stats_frame, text="VECTORS\nPULLED", bg=BG_CARD, fg=FG_DIM, font=FONT_SM).grid(row=0, column=1, padx=4)
        self._lbl_pairs = tk.Label(stats_frame, text="—", bg=BG_CARD, fg=ACCENT, font=("Inter", 16, "bold"))
        self._lbl_pairs.grid(row=0, column=2, padx=16)
        tk.Label(stats_frame, text="PAIRS\nCOMPARED", bg=BG_CARD, fg=FG_DIM, font=FONT_SM).grid(row=0, column=3, padx=4)
        self._lbl_clusters = tk.Label(stats_frame, text="—", bg=BG_CARD, fg=FG_WARN, font=("Inter", 16, "bold"))
        self._lbl_clusters.grid(row=0, column=4, padx=16)
        tk.Label(stats_frame, text="CLUSTERS\n(≥ 80%)", bg=BG_CARD, fg=FG_DIM, font=FONT_SM).grid(row=0, column=5, padx=4)

        # Controls
        ctrl = ttk.Frame(pad)
        ctrl.pack(fill='x', pady=(0, 10))
        self._btn_pull = ttk.Button(ctrl, text="PULL & ANALYSE VECTORS", command=self._pull_and_analyse)
        self._btn_pull.pack(side='left')
        self._btn_upload = ttk.Button(ctrl, text="UPLOAD RESULTS TO SMACK CENTRAL", command=self._upload_results)
        self._btn_upload.pack(side='left', padx=10)
        self._info_lbl = ttk.Label(ctrl, text="", style='Dim.TLabel')
        self._info_lbl.pack(side='left', padx=10)

        # Clusters tree
        cols = ('cluster', 'confidence', 'max_sim', 'members', 'target', 'hashes', 'sites')
        self._tree = ttk.Treeview(pad, columns=cols, show='headings', selectmode='browse')
        headers = {
            'cluster':    ('CLUSTER',     80),
            'confidence': ('CONFIDENCE', 130),
            'max_sim':    ('MAX SIM',     85),
            'members':    ('MEMBERS',     80),
            'target':     ('TARGET LVL',  95),
            'hashes':     ('FINGERPRINTS', 220),
            'sites':      ('REPORTED BY', 220),
        }
        for col, (heading, width) in headers.items():
            self._tree.heading(col, text=heading)
            self._tree.column(col, width=width, minwidth=60, anchor='w')

        vsb = ttk.Scrollbar(pad, orient='vertical', command=self._tree.yview)
        self._tree.configure(yscrollcommand=vsb.set)
        self._tree.pack(side='top', fill='both', expand=True)
        vsb.pack(side='right', fill='y')

        self._tree.tag_configure('strong_match',   foreground= FG_ERR)
        self._tree.tag_configure('likely_match',   foreground= FG_WARN)
        self._tree.tag_configure('possible_match', foreground= FG_YELL)

        # Cluster operator actions
        act = ttk.Frame(pad)
        act.pack(fill='x', pady=(10, 0))
        ttk.Button(act, text="QUEUE ESCALATE CLUSTER", command=self._queue_escalate).pack(side='left')
        ttk.Button(act, text="QUEUE DISMISS PAIR",     command=self._queue_dismiss).pack(side='left', padx=10)
        self._act_lbl = ttk.Label(act, text="", style='Dim.TLabel')
        self._act_lbl.pack(side='left', padx=10)

    def _credentials(self) -> Optional[tuple[str, str]]:
        api_url = (_cfg.get('api_url') or '').strip()
        token = (_cfg.get('admin_token') or cfg_module.get_secret('admin_token', api_url)).strip()
        if not api_url or not token:
            messagebox.showinfo(
                "SMACK CENTRAL Credentials Required",
                "Configure the Central API URL and Admin Token (stored in OS keyring) in Settings first."
            )
            return None
        return api_url, token

    def _pull_and_analyse(self):
        if self._running:
            return
        creds = self._credentials()
        if not creds:
            return
        api_url, token = creds
        self._running = True
        self._btn_pull.configure(state='disabled')
        self._info_lbl.configure(text="Pulling vectors from SMACK CENTRAL…", foreground=FG_DIM)
        threading.Thread(
            target=self._pull_thread, args=(api_url, token), daemon=True
        ).start()

    def _pull_thread(self, api_url: str, token: str):
        try:
            pulled = core_module.pull_central_vectors(api_url, token)
            vectors = pulled.get("vectors") or []
            dismissed = pulled.get("dismissed_pairs") or []
            _post(self._info_lbl.configure,
                  text=f"Analysing {len(vectors)} vector(s) locally…", foreground=FG_DIM)
            res = core_module.analyze_central_vectors(vectors, dismissed)
            _post(self._apply_analysis, res)
        except Exception as e:
            _post(self._info_lbl.configure, text=f"✗ {e}", foreground=FG_ERR)
            _post(self._app.set_status, f"SMACK CENTRAL analysis failed: {e}", FG_ERR)
        finally:
            self._running = False
            _post(self._btn_pull.configure, state='normal')

    def _apply_analysis(self, res: dict):
        self._clusters = res.get("clusters") or []
        self._outcomes = res.get("outcomes") or []
        self._pending_dismiss.clear()
        self._pending_escalations.clear()
        self._lbl_vectors.configure(text=str(res.get("vector_count", 0)))
        self._lbl_pairs.configure(text=str(res.get("pairs_compared", 0)))
        self._lbl_clusters.configure(text=str(len(self._clusters)))
        self._refresh_tree()
        self._info_lbl.configure(
            text=f"✓ Local analysis complete — {len(self._clusters)} cluster(s) found.",
            foreground=FG_OK if not self._clusters else FG_WARN,
        )
        self._app.set_status(
            f"SMACK CENTRAL analysis complete — {len(self._clusters)} cluster(s) across {res.get('vector_count', 0)} vectors",
            FG_OK if not self._clusters else FG_WARN,
        )

    def _refresh_tree(self):
        self._tree.delete(*self._tree.get_children())
        self._cluster_by_iid.clear()
        for cl in self._clusters:
            conf = cl.get("confidence", "POSSIBLE MATCH")
            tag = conf.lower().replace(" ", "_")
            members = cl.get("members") or []
            hashes = ", ".join(str(m.get("ban_value", ""))[:12] + "…" for m in members)
            sites = ", ".join(sorted({
                s.strip()
                for m in members
                for s in str(m.get("reporting_sites") or "").split(",")
                if s.strip()
            })) or "—"
            iid = self._tree.insert('', 'end', tags=(tag,), values=(
                f"#{cl.get('cluster_id')}",
                conf,
                f"{float(cl.get('max_sim', 0.0)):.1%}",
                str(len(members)),
                str(cl.get("target_colour", "yellow")).upper(),
                hashes,
                sites,
            ))
            self._cluster_by_iid[iid] = cl

    def _selected_cluster(self) -> Optional[dict]:
        sel = self._tree.selection()
        if not sel:
            self._act_lbl.configure(text="Select a cluster first.", foreground=FG_WARN)
            return None
        return self._cluster_by_iid.get(sel[0])

    def _queue_escalate(self):
        cl = self._selected_cluster()
        if not cl:
            return
        fp_ids = [int(m["fingerprint_id"]) for m in (cl.get("members") or []) if "fingerprint_id" in m]
        target = str(cl.get("target_colour") or "yellow")
        if fp_ids:
            self._pending_escalations.append({
                "fingerprint_ids": fp_ids,
                "target_colour":   target,
            })
            self._act_lbl.configure(
                text=f"Queued escalation of {len(fp_ids)} fingerprints to {target.upper()} ({len(self._pending_escalations)} pending).",
                foreground=FG_OK,
            )

    def _queue_dismiss(self):
        cl = self._selected_cluster()
        if not cl:
            return
        members = cl.get("members") or []
        if len(members) != 2:
            self._act_lbl.configure(
                text="Dismiss is only available for 2-member clusters.", foreground=FG_WARN)
            return
        a = int(members[0]["fingerprint_id"])
        b = int(members[1]["fingerprint_id"])
        pair = [min(a, b), max(a, b)]
        if pair not in self._pending_dismiss:
            self._pending_dismiss.append(pair)
        self._act_lbl.configure(
            text=f"Queued false-positive dismissal for pair ({pair[0]}, {pair[1]}) ({len(self._pending_dismiss)} pending).",
            foreground=FG_OK,
        )

    def _upload_results(self):
        if self._running:
            return
        creds = self._credentials()
        if not creds:
            return
        api_url, token = creds
        self._running = True
        self._btn_upload.configure(state='disabled')
        self._info_lbl.configure(text="Uploading results to SMACK CENTRAL…", foreground=FG_DIM)
        threading.Thread(
            target=self._upload_thread, args=(api_url, token), daemon=True
        ).start()

    def _upload_thread(self, api_url: str, token: str):
        try:
            core_module.upload_central_results(
                api_url,
                token,
                clusters=self._clusters,
                outcomes=self._outcomes,
                dismiss_pairs=self._pending_dismiss,
                escalations=self._pending_escalations,
            )
            _post(self._pending_dismiss.clear)
            _post(self._pending_escalations.clear)
            _post(self._info_lbl.configure,
                  text="✓ Uploaded results to SMACK CENTRAL.", foreground=FG_OK)
            _post(self._act_lbl.configure, text="", foreground=FG_DIM)
        except Exception as e:
            _post(self._info_lbl.configure, text=f"✗ Upload failed: {e}", foreground=FG_ERR)
        finally:
            self._running = False
            _post(self._btn_upload.configure, state='normal')


# ─── Entry point ──────────────────────────────────────────────────────────────

if __name__ == '__main__':
    app = App()
    app.mainloop()
# ===== SNAPSMACK EOF =====
