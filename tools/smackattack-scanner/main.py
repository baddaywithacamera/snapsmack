"""
GOBSMACKED Scanner — main.py
Local desktop tool for running stylometric scans against a SnapSmack database.
Detects commenters whose writing style matches banned users or each other.

Python 3.10+ | tkinter | pymysql
"""

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

        nb.add(self._tab_settings, text='  SETTINGS  ')
        nb.add(self._tab_scan,     text='  SCAN  ')
        nb.add(self._tab_results,  text='  RESULTS  ')

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
        ttk.Label(pad, text="SMACKATTACK HUB API  (optional)", style='Accent.TLabel').pack(anchor='w', pady=(10, 16))

        api_frame = ttk.Frame(pad)
        api_frame.pack(fill='x')
        self._field(api_frame, "Hub API URL",  'api_url')
        self._field(api_frame, "API Key",      'api_key', show='•')

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
        cfg_module.save(_cfg)
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
        ttk.Button(act, text="UPLOAD TO HUB",  command=self._upload_selected).pack(side='left', padx=10)
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

    def _upload_selected(self):
        iid = self._selected_iid()
        if not iid:
            self._act_lbl.configure(text="Select a row first.", foreground=FG_WARN)
            return
        api_url = _cfg.get('api_url', '').strip()
        api_key = _cfg.get('api_key', '').strip()
        if not api_url or not api_key:
            messagebox.showinfo("No API Config",
                "Enter the hub API URL and key in Settings to enable uploads.")
            return
        vals  = self._tree.item(iid, 'values')
        db_id = self._row_ids.get(iid, 0)
        threading.Thread(target=self._upload_thread,
                         args=(api_url, api_key, iid, db_id, vals), daemon=True).start()

    def _upload_thread(self, url, key, iid, db_id, vals):
        import urllib.request
        payload = json.dumps({
            'action':      'gobsmacked_report',
            'author_key':  vals[2] if len(vals) > 2 else '',
            'matched_key': vals[3] if len(vals) > 3 else '',
            'match_type':  vals[1] if len(vals) > 1 else '',
            'similarity':  vals[0] if len(vals) > 0 else '',
        }).encode()
        req = urllib.request.Request(url, data=payload,
                                     headers={'Content-Type': 'application/json',
                                              'Authorization': f'Bearer {key}'})
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                body = json.loads(resp.read())
            if body.get('ok'):
                _post(self._act_lbl.configure, text="✓ Uploaded to hub.", foreground=FG_OK)
                if db_id and _conn:
                    db_module.mark_reviewed(_conn, db_id)
                _post(self.refresh)
            else:
                _post(self._act_lbl.configure,
                      text=f"Hub error: {body.get('error','unknown')}", foreground=FG_ERR)
        except Exception as e:
            _post(self._act_lbl.configure, text=f"Upload failed: {e}", foreground=FG_ERR)


# ─── Entry point ──────────────────────────────────────────────────────────────

if __name__ == '__main__':
    app = App()
    app.mainloop()
