"""
Smack Your Batch Up — main.py
SnapSmack batch image posting tool.
Admin-styled desktop app with thumbnail queue, drag reorder,
per-row category/album editing, and Google Drive upload.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


BUILD_VERSION = "0.7.20"   # integer build counter — +1 each rebuild (dropped letter suffixes after 0.7.9k)

# ---------------------------------------------------------------------------
# Debug log — redirect stdout/stderr to sybu-debug.log next to the exe.
# Must happen before any other import so library warnings are captured too.
# ---------------------------------------------------------------------------
import os
import sys

def _setup_log() -> str:
    """Open sybu-debug.log next to the exe (or source file when dev).  Returns path."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    log_path = os.path.join(base, 'sybu-debug.log')
    try:
        _lf = open(log_path, 'a', encoding='utf-8', buffering=1)
        import datetime
        _lf.write(f"\n{'='*60}\n"
                  f"  Smack Your Batch Up {BUILD_VERSION}  —  "
                  f"{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n"
                  f"{'='*60}\n")
        sys.stdout = _lf
        sys.stderr = _lf
    except Exception:
        pass  # if we can't open the log, don't crash — just run silently
    return log_path

LOG_PATH = _setup_log()

import queue
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, simpledialog, ttk
from typing import List, Optional

from concurrent.futures import ProcessPoolExecutor, as_completed
from PIL import Image, ImageTk

import config as cfg_module
import drive as drive_module
import gemini as gemini_module
import manifest_parser
import poster as poster_module
import profile_manager
import recovery as recovery_module
from manifest_parser import ManifestEntry
from poster import SnapSmackClient, SiteData

# SON OF A BATCH — offline posting suite (BATCH SLAPPED + BATCH, PLEASE).
# Guarded so a missing optional dep can never stop SYBU's core from launching.
try:
    from sob_solo import build_solo_mode
    from sob_gram import build_gram_mode
    _SOB_AVAILABLE = True
    _SOB_IMPORT_ERROR = ""
except Exception as _sob_err:  # pragma: no cover - import shim
    _SOB_AVAILABLE = False
    _SOB_IMPORT_ERROR = str(_sob_err)


# ---------------------------------------------------------------------------
# Audit log — rotating daily, 7-day retention. Records every Gemini request +
# response and every post. Separate from the stdout/stderr debug log above.
# File: sybu.log next to the exe (or the source dir in dev).
# ---------------------------------------------------------------------------
import logging
import logging.handlers

_AUDIT_LOG = os.path.join(os.path.dirname(LOG_PATH), 'sybu.log')
try:
    _audit_handler = logging.handlers.TimedRotatingFileHandler(
        _AUDIT_LOG, when='D', interval=1, backupCount=7, encoding='utf-8',
    )
    _audit_handler.setFormatter(logging.Formatter(
        '%(asctime)s  %(levelname)-8s  %(message)s', datefmt='%Y-%m-%d %H:%M:%S',
    ))
    _audit_log = logging.getLogger('sybu')
    _audit_log.setLevel(logging.DEBUG)
    if not _audit_log.handlers:
        _audit_log.addHandler(_audit_handler)
    _audit_log.info("SYBU %s — audit log opened", BUILD_VERSION)
except Exception:
    pass  # never let logging setup crash the app


# ---------------------------------------------------------------------------
# Colour palette & typography
# ---------------------------------------------------------------------------

BG_DEEP   = "#141414"   # window background (Midnight Lime)
BG_CARD   = "#1C1C1C"   # row / card background
BG_MID    = "#050505"   # input fields
BG_HOVER  = "#252525"   # hover state
ACCENT    = "#39FF14"   # neon lime
ACCENT2   = "#2ECC10"   # darker lime
BORDER    = "#2A2A2A"   # subtle borders

FG_MAIN   = "#EEEEEE"   # primary text
FG_DIM    = "#777777"   # muted / placeholder
FG_OK     = "#4EC994"   # success
FG_ERR    = "#FF3E3E"   # error
FG_WARN   = "#D4872A"   # warning

STATUS_COLORS = {
    'pending':  ("#2A2A2A", FG_DIM),
    'enriched': ("#0D2B3E", "#00BFFF"),  # dark teal bg, bright blue text
    'posting':  (ACCENT,    "#000000"),
    'ok':       (FG_OK,     "#000000"),
    'error':    (FG_ERR,    "#000000"),
    'warning':  (FG_WARN,   "#000000"),
}

THUMB_SIZE  = (144, 144)
ROW_HEIGHT  = 168       # px per entry row (taller to fit inline editing)
WIN_W, WIN_H = 1020, 920
FONT_UI      = ("Segoe UI", 9)
FONT_BOLD    = ("Segoe UI", 9, "bold")
FONT_SMALL   = ("Segoe UI", 8)
FONT_MONO    = ("Consolas", 9)
FONT_TITLE   = ("Segoe UI", 13, "bold")

# Advanced Visual Match tab constants (ported from Fix Your Batch Up)
MATCH_IMG_W   = 380    # max display width for preview images
MATCH_IMG_H   = 260    # max display height
FG_HIGH       = "#4EC994"   # green  — high confidence
FG_MED        = "#D4872A"   # amber  — medium confidence
FG_LOW        = "#FF3E3E"   # red    — low / no match
FONT_CONF     = ("Segoe UI", 22, "bold")

BG_SBAR      = "#0c0c0c"   # LED status bar background

# LED display colours (high contrast on near-black)
LED_OK       = "#39FF14"   # neon green — good
LED_ERR      = "#FF3E3E"   # red — error
LED_WARN     = "#FFB300"   # bright amber — warning / flash
LED_OFF      = "#2A2A2A"   # unlit segment

# ---------------------------------------------------------------------------
# Font helpers
# ---------------------------------------------------------------------------

def _resource_path(relative: str) -> str:
    """Resolve path to a bundled asset (works in dev and PyInstaller exe)."""
    import sys
    base = getattr(sys, '_MEIPASS', os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, relative)


def _load_dotmatrix_font() -> str:
    """
    Register DotMatrix-Bold from the bundled assets folder on Windows using
    AddFontResourceExW (no admin rights required, private to this process).
    Returns the tkinter family name to use, falling back to Consolas.
    """
    import sys
    if sys.platform != 'win32':
        return 'Consolas'
    try:
        import ctypes
        path = _resource_path(os.path.join('assets', 'fonts', 'DotMatrix-Bold.ttf'))
        if not os.path.isfile(path):
            return 'Consolas'
        FR_PRIVATE = 0x10
        ctypes.windll.gdi32.AddFontResourceExW(path, FR_PRIVATE, 0)
        return 'DotMatrix'
    except Exception:
        return 'Consolas'


# ---------------------------------------------------------------------------
# Entry row widget
# ---------------------------------------------------------------------------

class EntryRow(tk.Frame):
    """One row in the batch table. Holds all per-image state."""

    def __init__(self, parent, entry: ManifestEntry, row_index: int,
                 cats: List[str], albums: List[str], on_drag_start, on_drag_motion, on_drag_end):
        super().__init__(parent, bg=BG_CARD, highlightthickness=1,
                         highlightbackground=BORDER, cursor="arrow")

        self.entry       = entry
        self.row_index   = row_index
        self._thumb_img  = None   # keep reference to prevent GC
        self._status     = 'pending'
        self._sel_var    = tk.BooleanVar(value=True)   # selected for enrich/post

        self._build(cats, albums, on_drag_start, on_drag_motion, on_drag_end)

    def _build(self, cats, albums, on_drag_start, on_drag_motion, on_drag_end):
        self.configure(height=ROW_HEIGHT)

        # ── Selection checkbox (top of the left gutter) ───────────────
        self._sel_chk = tk.Checkbutton(
            self, variable=self._sel_var,
            bg=BG_CARD, activebackground=BG_CARD,
            selectcolor=BG_MID, fg=FG_DIM, cursor="hand2",
            relief="flat", bd=0, highlightthickness=0,
        )
        self._sel_chk.place(x=4, y=4, width=22, height=22)

        # ── Drag handle (below the checkbox) ──────────────────────────
        self._handle = tk.Label(
            self, text="⠿", bg=BG_CARD, fg=FG_DIM,
            font=("Segoe UI", 14), cursor="fleur", padx=6,
        )
        self._handle.place(x=0, y=28, width=28, height=ROW_HEIGHT - 28)
        self._handle.bind("<ButtonPress-1>",   on_drag_start)
        self._handle.bind("<B1-Motion>",        on_drag_motion)
        self._handle.bind("<ButtonRelease-1>", on_drag_end)

        # ── Thumbnail ─────────────────────────────────────────────────
        # Centred vertically: (ROW_HEIGHT - THUMB_SIZE[1]) // 2 = (168-144)//2 = 12
        self._thumb_lbl = tk.Label(
            self, bg="#0A0A0E", relief="flat",
            text="…", fg=FG_DIM, font=FONT_SMALL,
        )
        self._thumb_lbl.place(x=30, y=12, width=THUMB_SIZE[0], height=THUMB_SIZE[1])

        # ── File name ─────────────────────────────────────────────────
        # Content area starts at x=190 (28 handle + 144 thumb + 18 gap)
        self._fname_lbl = tk.Label(
            self, text=self.entry.file, bg=BG_CARD, fg=FG_MAIN,
            font=FONT_BOLD, anchor="w",
        )
        self._fname_lbl.place(x=190, y=8, width=760, height=16)

        # ── Inline title entry ────────────────────────────────────────
        tk.Label(self, text="title", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=190, y=30)
        self._title_var = tk.StringVar(value=self.entry.title)
        self._title_entry = tk.Entry(
            self, textvariable=self._title_var,
            bg=BG_MID, fg=FG_MAIN, insertbackground=ACCENT,
            relief="flat", font=FONT_SMALL, bd=0,
            highlightthickness=1, highlightbackground=BORDER, highlightcolor=ACCENT,
        )
        self._title_entry.place(x=226, y=28, width=695, height=20)
        self._title_var.trace_add("write", lambda *a: setattr(self.entry, 'title', self._title_var.get()))

        # ── Inline tags entry ─────────────────────────────────────────
        tk.Label(self, text="tags", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=190, y=56)
        self._tags_var = tk.StringVar(value=self.entry.tags)
        self._tags_entry = tk.Entry(
            self, textvariable=self._tags_var,
            bg=BG_MID, fg=FG_DIM, insertbackground=ACCENT,
            relief="flat", font=FONT_SMALL, bd=0,
            highlightthickness=1, highlightbackground=BORDER, highlightcolor=ACCENT,
        )
        self._tags_entry.place(x=226, y=54, width=695, height=20)
        self._tags_var.trace_add("write", lambda *a: setattr(self.entry, 'tags', self._tags_var.get()))

        # ── Category combobox ─────────────────────────────────────────
        tk.Label(self, text="cat", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=190, y=88)
        self._cat_var = tk.StringVar(value=self.entry.category)
        self._cat_cb = ttk.Combobox(
            self, textvariable=self._cat_var, values=[''] + cats,
            font=FONT_SMALL, state="normal",
        )
        self._cat_cb.place(x=190, y=100, width=200)
        self._cat_cb.bind("<<ComboboxSelected>>",
                    lambda e: setattr(self.entry, 'category', self._cat_var.get()))
        self._cat_var.trace_add("write",
                    lambda *a: setattr(self.entry, 'category', self._cat_var.get()))

        # ── Album combobox ────────────────────────────────────────────
        tk.Label(self, text="album", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=402, y=88)
        self._album_var = tk.StringVar(value=self.entry.album)
        self._album_cb = ttk.Combobox(
            self, textvariable=self._album_var, values=[''] + albums,
            font=FONT_SMALL, state="normal",
        )
        self._album_cb.place(x=402, y=100, width=200)
        self._album_cb.bind("<<ComboboxSelected>>",
                      lambda e: setattr(self.entry, 'album', self._album_var.get()))
        self._album_var.trace_add("write",
                      lambda *a: setattr(self.entry, 'album', self._album_var.get()))

        # ── Orientation combobox ─────────────────────────────────────
        tk.Label(self, text="orient", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=614, y=88)
        orient_display = {'auto': 'Auto', '0': 'Landscape', '1': 'Portrait', '2': 'Square'}
        display_val = orient_display.get(self.entry.orientation, 'Auto')
        self._orient_var = tk.StringVar(value=display_val)
        self._orient_cb = ttk.Combobox(
            self, textvariable=self._orient_var,
            values=['Auto', 'Landscape', 'Portrait', 'Square'],
            font=FONT_SMALL, state="readonly", width=9,
        )
        self._orient_cb.place(x=614, y=100, width=110)
        orient_reverse = {'Auto': 'auto', 'Landscape': '0', 'Portrait': '1', 'Square': '2'}
        self._orient_cb.bind("<<ComboboxSelected>>",
                      lambda e: setattr(self.entry, 'orientation',
                                        orient_reverse.get(self._orient_var.get(), 'auto')))

        # ── Colour swatches (filled by Gemini) ────────────────────────
        tk.Label(self, text="colors", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=736, y=88)
        self._swatch_labels = []
        for i in range(3):
            sw = tk.Label(self, bg=BG_CARD, relief="flat", width=4,
                          cursor="hand2", font=FONT_SMALL)
            sw.place(x=736 + i * 50, y=100, width=44, height=20)
            self._swatch_labels.append(sw)
        self._update_swatches(self.entry.colors)

        # ── Status badge ──────────────────────────────────────────────
        self._status_lbl = tk.Label(
            self, text="PENDING", font=("Segoe UI", 7, "bold"),
            padx=6, pady=2, relief="flat",
        )
        # Centred vertically: (ROW_HEIGHT - 20) // 2 = 74
        self._status_lbl.place(x=868, y=74, width=72, height=20)
        self._set_status_visual('pending')

        # Bind resize so fields stretch to fill the row width
        self.bind("<Configure>", self._on_resize)

    # ── Responsive layout ─────────────────────────────────────────────
    _BADGE_W   = 72
    _RIGHT_PAD = 4   # px clearance from right edge
    _BADGE_GAP = 28  # gap between entry right edge and status badge

    def _on_resize(self, event):
        """Recalculate place() widths when the row frame is resized."""
        w = event.width
        if w < 400:
            return
        badge_x  = w - self._BADGE_W - self._RIGHT_PAD
        entry_w  = badge_x - 226 - self._BADGE_GAP   # entries start at x=226
        fname_w  = badge_x - 190 - 10                 # filename starts at x=190
        self._title_entry.place(width=max(entry_w, 50))
        self._tags_entry.place(width=max(entry_w, 50))
        self._fname_lbl.place(width=max(fname_w, 50))
        self._status_lbl.place(x=badge_x)

    def set_thumb(self, img: ImageTk.PhotoImage):
        self._thumb_img = img
        self._thumb_lbl.configure(image=img, text="")

    def set_status(self, status: str, message: str = ""):
        self._status = status
        self._set_status_visual(status)
        if message:
            self._thumb_lbl.configure(cursor="")
        self.update_idletasks()

    def _set_status_visual(self, status: str):
        bg, fg = STATUS_COLORS.get(status, (BG_MID, FG_DIM))
        labels = {
            'pending':  "PENDING",
            'enriched': "ENRICHED",
            'posting':  "POSTING",
            'ok':       "  POSTED",
            'error':    "  ERROR",
            'warning':  "  WARN",
        }
        self._status_lbl.configure(
            text=labels.get(status, status.upper()),
            bg=bg, fg=fg,
        )

    def _update_swatches(self, colors_str: str):
        """Repaint the three colour swatch labels from a space-separated hex string."""
        hexes = colors_str.split() if colors_str else []
        for i, sw in enumerate(self._swatch_labels):
            if i < len(hexes):
                h = hexes[i]
                sw.configure(bg=h, text=h, fg=self._contrast_fg(h))
            else:
                sw.configure(bg=BG_CARD, text="", fg=FG_DIM)

    @staticmethod
    def _contrast_fg(hex_color: str) -> str:
        """Return black or white text depending on background luminance."""
        try:
            h = hex_color.lstrip('#')
            r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
            luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255
            return '#000000' if luminance > 0.5 else '#FFFFFF'
        except Exception:
            return FG_MAIN

    def fill_from_ai(self, title: str = '', tags: str = '', category: str = '',
                     album: str = '', colors: str = ''):
        """Push Gemini-generated values into the live fields. Skips blank values."""
        if title:
            self._title_var.set(title)
        if tags:
            self._tags_var.set(tags)
        if category:
            self._cat_var.set(category)
        if album:
            self._album_var.set(album)
        if colors:
            self.entry.colors = colors
            self._update_swatches(colors)
        # Force immediate repaint so fields appear filled before the next image starts
        self.update_idletasks()

    def set_highlight(self, on: bool):
        self.configure(
            bg=BG_HOVER if on else BG_CARD,
            highlightbackground=ACCENT if on else BORDER,
        )
        self._handle.configure(bg=BG_HOVER if on else BG_CARD)

    def update_combos(self, cats: List[str], albums: List[str]):
        cur_cat = self._cat_cb.get()
        self._cat_cb['values'] = [''] + cats
        self._cat_cb.set(cur_cat)
        cur_alb = self._album_cb.get()
        self._album_cb['values'] = [''] + albums
        self._album_cb.set(cur_alb)

    # ── Selection ──────────────────────────────────────────────────────
    def is_selected(self) -> bool:
        return bool(self._sel_var.get())

    def set_selected(self, on: bool):
        self._sel_var.set(bool(on))


# ---------------------------------------------------------------------------
# Scrollable entry list with drag reorder
# ---------------------------------------------------------------------------

class EntryList(tk.Frame):
    """Scrollable container for EntryRow widgets with drag-to-reorder."""

    def __init__(self, parent, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)

        self._rows:      List[EntryRow] = []
        self._cats:      List[str]      = []
        self._albums:    List[str]      = []

        # Drag state
        self._drag_row:     Optional[EntryRow] = None
        self._drag_start_y: int = 0
        self._drag_origin:  int = 0   # original index

        self._build()

    def _build(self):
        # Canvas + scrollbar for scrollable row list
        self._canvas = tk.Canvas(self, bg=BG_DEEP, highlightthickness=0, bd=0)
        self._scrollbar = ttk.Scrollbar(self, orient="vertical",
                                         command=self._canvas.yview)
        self._canvas.configure(yscrollcommand=self._scrollbar.set)

        self._scrollbar.pack(side="right", fill="y")
        self._canvas.pack(side="left", fill="both", expand=True)

        self._inner = tk.Frame(self._canvas, bg=BG_DEEP)
        self._window = self._canvas.create_window((0, 0), window=self._inner, anchor="nw")

        self._inner.bind("<Configure>", self._on_inner_configure)
        self._canvas.bind("<Configure>", self._on_canvas_configure)

        # Bind mousewheel globally so child widgets (rows, combos, labels)
        # don't swallow scroll events — covers mouse wheel and touchpad swipes
        self._canvas.bind("<MouseWheel>", self._on_mousewheel)
        self._canvas.bind("<Button-4>",   self._on_mousewheel)   # Linux scroll up
        self._canvas.bind("<Button-5>",   self._on_mousewheel)   # Linux scroll down
        self.bind_all("<MouseWheel>",     self._on_mousewheel_global)
        self.bind_all("<Button-4>",       self._on_mousewheel_global)
        self.bind_all("<Button-5>",       self._on_mousewheel_global)

    def _on_inner_configure(self, _event):
        self._canvas.configure(scrollregion=self._canvas.bbox("all"))

    def _on_canvas_configure(self, event):
        self._canvas.itemconfig(self._window, width=event.width)

    def _on_mousewheel(self, event):
        if event.num == 4:
            self._canvas.yview_scroll(-3, "units")
        elif event.num == 5:
            self._canvas.yview_scroll(3, "units")
        else:
            self._canvas.yview_scroll(int(-1 * (event.delta / 120)), "units")

    def _on_mousewheel_global(self, event):
        """Route mousewheel from any child widget to the queue canvas."""
        # Only scroll if the pointer is over the entry list area
        try:
            x, y = self._canvas.winfo_rootx(), self._canvas.winfo_rooty()
            w, h = self._canvas.winfo_width(), self._canvas.winfo_height()
            if x <= event.x_root <= x + w and y <= event.y_root <= y + h:
                self._on_mousewheel(event)
        except Exception:
            pass

    # ------------------------------------------------------------------
    # Population
    # ------------------------------------------------------------------

    def load(self, entries: List[ManifestEntry], image_folder: str,
             cats: List[str], albums: List[str]):
        self._cats   = cats
        self._albums = albums
        # Append to existing rows — caller uses clear() explicitly via the Clear button.

        offset = len(self._rows)
        for i, entry in enumerate(entries):
            row = EntryRow(
                self._inner, entry, offset + i, cats, albums,
                on_drag_start=self._drag_start,
                on_drag_motion=self._drag_motion,
                on_drag_end=self._drag_end,
            )
            row.pack(fill="x", pady=(0, 2))
            self._rows.append(row)

            # Load thumbnail in background
            self._load_thumb_async(row, os.path.join(image_folder, entry.file))

        # Flush pending geometry events so the scrollregion is correct before
        # snapping to the top — without this, yview_moveto(0) fires before
        # tkinter has laid out the new rows and the snap has no effect.
        self._inner.update_idletasks()
        self._canvas.configure(scrollregion=self._canvas.bbox("all"))
        self._canvas.yview_moveto(0)

    def update_combos(self, cats: List[str], albums: List[str]):
        self._cats   = cats
        self._albums = albums
        for row in self._rows:
            row.update_combos(cats, albums)

    def clear(self):
        for row in self._rows:
            row.destroy()
        self._rows.clear()

    def get_entries(self) -> List[ManifestEntry]:
        return [r.entry for r in self._rows]

    def get_selected_entries(self) -> List[ManifestEntry]:
        return [r.entry for r in self._rows if r.is_selected()]

    def selected_count(self) -> int:
        return sum(1 for r in self._rows if r.is_selected())

    def set_all_selected(self, on: bool):
        for r in self._rows:
            r.set_selected(on)

    def get_row(self, index: int) -> Optional['EntryRow']:
        if 0 <= index < len(self._rows):
            return self._rows[index]
        return None

    def row_for_entry(self, entry) -> Optional['EntryRow']:
        """Find the row owning a given entry object (identity match).
        Used so status updates land on the right row even when only a
        filtered subset of the queue is being processed."""
        for r in self._rows:
            if r.entry is entry:
                return r
        return None

    def set_row_status(self, index: int, status: str, message: str = ""):
        if 0 <= index < len(self._rows):
            self._rows[index].set_status(status, message)

    # ------------------------------------------------------------------
    # Thumbnails
    # ------------------------------------------------------------------

    def _load_thumb_async(self, row: EntryRow, img_path: str):
        def load():
            try:
                img = Image.open(img_path)
                img.thumbnail(THUMB_SIZE, Image.LANCZOS)
                # Pad to exact THUMB_SIZE with dark background
                canvas = Image.new("RGB", THUMB_SIZE, (10, 10, 14))
                offset = (
                    (THUMB_SIZE[0] - img.width)  // 2,
                    (THUMB_SIZE[1] - img.height) // 2,
                )
                canvas.paste(img, offset)
                photo = ImageTk.PhotoImage(canvas)
                self.after(0, lambda: row.set_thumb(photo))
            except Exception:
                pass  # Leave the placeholder text

        threading.Thread(target=load, daemon=True).start()

    # ------------------------------------------------------------------
    # Drag reorder
    # ------------------------------------------------------------------

    def _row_at_y(self, y_canvas: int) -> int:
        """Return the row index closest to the given canvas y coordinate."""
        for i, row in enumerate(self._rows):
            ry = row.winfo_y()
            if y_canvas < ry + ROW_HEIGHT // 2:
                return i
        return len(self._rows) - 1

    def _drag_start(self, event):
        # Find which row owns this widget
        widget = event.widget
        row = widget.master
        if not isinstance(row, EntryRow):
            return
        self._drag_row     = row
        self._drag_origin  = self._rows.index(row)
        self._drag_start_y = event.y_root
        row.set_highlight(True)

    def _drag_motion(self, event):
        if self._drag_row is None:
            return
        # Map root y → canvas y
        canvas_y = self._canvas.canvasy(
            event.y_root - self._canvas.winfo_rooty()
        )
        target_idx = self._row_at_y(int(canvas_y))
        current_idx = self._rows.index(self._drag_row)
        if target_idx != current_idx:
            self._rows.pop(current_idx)
            self._rows.insert(target_idx, self._drag_row)
            self._repack()

    def _drag_end(self, event):
        if self._drag_row:
            self._drag_row.set_highlight(False)
            self._drag_row = None

    def _repack(self):
        for row in self._rows:
            row.pack_forget()
        for row in self._rows:
            row.pack(fill="x", pady=(0, 2))
        self._canvas.update_idletasks()


# ---------------------------------------------------------------------------
# Advanced Visual Match — MatchRow widget
# ---------------------------------------------------------------------------

def _match_load_thumb(path: str, max_w: int = MATCH_IMG_W,
                      max_h: int = MATCH_IMG_H) -> Optional['ImageTk.PhotoImage']:
    """Load an image from disk, scale to fit, return a PhotoImage."""
    try:
        img = Image.open(path).convert('RGB')
        w, h = img.size
        scale = min(max_w / w, max_h / h, 1.0)
        img = img.resize((max(1, int(w * scale)), max(1, int(h * scale))),
                         Image.LANCZOS)
        return ImageTk.PhotoImage(img)
    except Exception:
        return None


def _match_haiku_to_filename(title: str, ext: str) -> str:
    """Sanitize a title into a safe filename."""
    invalid = r'\/:*?"<>|'
    clean = ''.join(c for c in title if c not in invalid).strip()
    return f"{(clean or 'untitled')}{ext}"


class SybuMatchRow(tk.Frame):
    """
    One image-matching review row in the Advanced Visual Match tab.

    Left   : server-side FTP copy (post title, thumbnail)
    Centre : confidence score + match count + label
    Right  : matched local original + Upload / Pick Different / Skip buttons

    Drive credentials and folder ID are read from app._config — no separate
    credential fields anywhere in this widget.
    """

    def __init__(self, parent, record: dict, result: dict, app: 'App'):
        super().__init__(parent, bg=BG_CARD,
                         highlightthickness=1, highlightbackground=BORDER)
        self.record        = record
        self.result        = result
        self.app           = app
        self._orig_photo   = None
        self._srv_photo    = None
        self._cancel_evt   = threading.Event()
        self._approved_var = tk.BooleanVar(value=False)
        self._build()

    def _build(self):
        # ── Left: server image ────────────────────────────────────────────
        left = tk.Frame(self, bg=BG_CARD, width=MATCH_IMG_W + 10)
        left.pack(side='left', fill='y', padx=(8, 4), pady=8)
        left.pack_propagate(False)

        srv_path = self._server_local_path()
        self._blank_srv = tk.PhotoImage(width=1, height=1)
        self._srv_lbl = tk.Label(left, bg=BG_MID,
                                  width=MATCH_IMG_W, height=MATCH_IMG_H,
                                  image=self._blank_srv, relief='flat')
        self._srv_lbl.pack(fill='x')
        if srv_path:
            photo = _match_load_thumb(srv_path)
            if photo:
                self._srv_photo = photo
                self._srv_lbl.configure(image=photo,
                                        width=photo.width(), height=photo.height())
            else:
                self._srv_lbl.configure(text="Preview unavailable",
                                        fg=FG_DIM, font=FONT_UI, compound='center')

        title = self.record.get('img_title', 'Untitled')[:60]
        tk.Label(left, text=title, bg=BG_CARD, fg=FG_MAIN,
                 font=FONT_BOLD, wraplength=MATCH_IMG_W,
                 justify='left', anchor='w').pack(fill='x', pady=(4, 0))
        tk.Label(left, text=f"ID {self.record.get('snap_id', '?')}",
                 bg=BG_CARD, fg=FG_DIM,
                 font=FONT_SMALL, anchor='w').pack(fill='x')

        # ── Centre: confidence ────────────────────────────────────────────
        mid = tk.Frame(self, bg=BG_CARD, width=160)
        mid.pack(side='left', fill='y', padx=8)
        mid.pack_propagate(False)

        conf      = self.result.get('confidence', 0.0)
        label_key = self.result.get('label', 'none')
        conf_color = {'high': FG_HIGH, 'medium': FG_MED,
                      'low': FG_LOW, 'none': FG_DIM}.get(label_key, FG_DIM)

        conf_pct = f"{int(conf * 100)}%" if conf > 0 else "—"
        tk.Label(mid, text=conf_pct, bg=BG_CARD, fg=conf_color,
                 font=FONT_CONF).pack(pady=(50, 0))
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
                 font=FONT_SMALL, wraplength=150, justify='center').pack(pady=(6, 0))

        # ── Right: original + buttons ─────────────────────────────────────
        right = tk.Frame(self, bg=BG_CARD, width=MATCH_IMG_W + 100)
        right.pack(side='left', fill='both', expand=True, padx=(4, 8), pady=8)

        orig_frame = tk.Frame(right, bg=BG_CARD)
        orig_frame.pack(side='top', fill='x')

        self._blank_orig = tk.PhotoImage(width=1, height=1)
        self._orig_lbl = tk.Label(orig_frame, bg=BG_MID,
                                   width=MATCH_IMG_W, height=MATCH_IMG_H,
                                   image=self._blank_orig, relief='flat')
        self._orig_lbl.pack(side='left')

        orig_path = self.result.get('match_path')
        if not orig_path:
            self._orig_lbl.configure(text="No match found\nClick 'Pick Different'",
                                      fg=FG_DIM, font=FONT_UI, compound='center')
        elif not os.path.isfile(orig_path):
            self._orig_lbl.configure(text="File moved or deleted\nClick 'Pick Different'",
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
        folder = self.app._match_srv_folder_var.get().strip()
        img_file = self.record.get('img_file', '')
        if not folder or not img_file:
            return None
        path = os.path.join(folder, os.path.basename(img_file))
        return path if os.path.isfile(path) else None

    def _set_orig_image(self, path: str):
        photo = _match_load_thumb(path)
        if photo:
            self._orig_photo = photo
            self._orig_lbl.configure(image=photo, text='',
                                     width=photo.width(), height=photo.height())
        self._orig_name_lbl.configure(text=os.path.basename(path))
        self.result['match_path'] = path

    def _orig_basename(self) -> str:
        p = self.result.get('match_path', '')
        return os.path.basename(p) if p else '—'

    # ── Actions ───────────────────────────────────────────────────────────

    def _on_upload(self):
        match_path = self.result.get('match_path')
        if not match_path:
            messagebox.showwarning("No original selected",
                                   "Use 'Pick Different' to choose the original.",
                                   parent=self)
            return
        if not self.app._drive_service:
            messagebox.showerror("Drive not connected",
                                  "Auth Drive on the POST tab before uploading.",
                                  parent=self)
            return
        self._upload_btn.configure(text="Queued…", state='disabled',
                                   bg=FG_DIM, fg="#000000")
        self._approved_var.set(False)
        self.app._match_enqueue_upload(self)

    def _start_upload(self, done_evt: threading.Event):
        """Called by the drain thread to start the actual upload."""
        match_path = self.result.get('match_path')
        if not match_path or not os.path.isfile(match_path):
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
        folder_id  = self.app._config.get('drive_folder_id', '').strip() or None
        drive_svc  = self.app._drive_service
        client     = self.app._client
        orig_path  = match_path
        cancel_evt = self._cancel_evt

        raw_title   = self.record.get('img_title', '').strip()
        _, _ext     = os.path.splitext(orig_path)
        drive_fname = (_match_haiku_to_filename(raw_title, _ext.lower())
                       if raw_title else os.path.basename(orig_path))

        def _worker():
            import socket
            import drive as drive_module
            _prev = socket.getdefaulttimeout()
            socket.setdefaulttimeout(180)
            try:
                drive_url = drive_module.upload(drive_svc, orig_path,
                                                drive_fname, folder_id=folder_id)
                if cancel_evt.is_set():
                    self.after(0, self._mark_cancelled)
                    return
                client.backfill_update_link(snap_id, drive_url)
                self.after(0, lambda: self._mark_done(drive_url))
            except Exception as exc:
                self.after(0, lambda: self._mark_error(str(exc)))
            finally:
                socket.setdefaulttimeout(_prev)
                done_evt.set()

        threading.Thread(target=_worker, daemon=True).start()

    def _mark_done(self, drive_url: str):
        self.result['_uploaded'] = True
        self._cancel_btn.configure(text="", bg=BG_CARD, fg=BG_CARD, state='disabled')
        self._upload_btn.configure(text="✓ Done", bg=FG_OK, fg="#000000",
                                   state='disabled')
        self.configure(highlightbackground=FG_OK)
        self.app._match_on_row_done()
        self.app._match_remove_row(self)
        self.after(1500, self._dismiss)

    def _dismiss(self):
        try:
            self.destroy()
        except Exception:
            pass
        try:
            self.app._match_inner.update_idletasks()
            self.app._match_canvas.configure(
                scrollregion=self.app._match_canvas.bbox('all'))
        except Exception:
            pass

    def _mark_cancelled(self):
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
        folder_b = self.app._match_orig_folder_var.get().strip()
        path = filedialog.askopenfilename(
            title="Choose the original for this image",
            initialdir=folder_b if folder_b and os.path.isdir(folder_b) else None,
            filetypes=[('Image files', '*.jpg *.jpeg *.png *.webp *.JPG *.JPEG *.PNG'),
                       ('All files', '*.*')],
            parent=self,
        )
        if path:
            self._set_orig_image(path)
            self._upload_btn.configure(text="Upload", state='normal',
                                       bg=ACCENT, fg="#000000")

    def _on_skip(self):
        self.app._match_on_row_done()
        self.app._match_remove_row(self)
        self.after(0, self._dismiss)


# ---------------------------------------------------------------------------
# Main application window
# ---------------------------------------------------------------------------

class App(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title(f"SUMNABATCH  —  build {BUILD_VERSION}")
        self.geometry(f"{WIN_W}x{WIN_H}")
        self.minsize(860, 600)
        self.configure(bg=BG_DEEP)

        # Set window/taskbar icon explicitly — the exe icon set via PyInstaller
        # only affects File Explorer; tkinter needs iconbitmap() for the taskbar.
        try:
            if getattr(sys, 'frozen', False):
                _ico = os.path.join(sys._MEIPASS, 'assets', 'sybu.ico')
            else:
                _ico = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                    'assets', 'sybu.ico')
            if os.path.exists(_ico):
                self.iconbitmap(_ico)
        except Exception:
            pass  # non-fatal — falls back to default tkinter feather

        # State
        self._config        = cfg_module.load()
        self._client:       Optional[SnapSmackClient] = None
        self._site_data:    Optional[SiteData]        = None
        self._drive_service = None
        self._posting           = False
        self._keepalive_running = False
        self._cancel_evt        = threading.Event()    # set to abort a running POST
        self._recovery          = None                 # recovery_module.RecoveryStore
        self._msg_queue:    queue.Queue               = queue.Queue()

        self._led_font = _load_dotmatrix_font()
        self._apply_ttk_style()
        self._build_ui()
        self._load_config_to_ui()
        self.after(100, self._poll_queue)
        self.after(200, self._auto_reconnect)

    # ------------------------------------------------------------------
    # Colour helpers
    # ------------------------------------------------------------------

    @staticmethod
    def _lighten(hex_color: str) -> str:
        h = hex_color.lstrip('#')
        if len(h) == 3:
            h = ''.join(c * 2 for c in h)
        try:
            r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
            return f'#{min(255,int(r*1.15)):02x}{min(255,int(g*1.15)):02x}{min(255,int(b*1.15)):02x}'
        except Exception:
            return hex_color

    @staticmethod
    def _contrast_text(hex_bg: str) -> str:
        """Return '#000000' or '#FFFFFF' for readable text on the given background."""
        h = hex_bg.lstrip('#')
        if len(h) == 3:
            h = ''.join(c * 2 for c in h)
        try:
            r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
            # Relative luminance (ITU-R BT.709)
            luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255
            return '#000000' if luminance > 0.5 else '#FFFFFF'
        except Exception:
            return '#000000'

    # ------------------------------------------------------------------
    # TTK style
    # ------------------------------------------------------------------

    def _apply_ttk_style(self):
        style = ttk.Style(self)
        style.theme_use("clam")

        style.configure("TCombobox",
            fieldbackground=BG_MID,
            background=BG_MID,
            foreground=FG_MAIN,
            selectbackground=ACCENT,
            selectforeground=BG_DEEP,
            bordercolor=BORDER,
            arrowcolor=FG_DIM,
            padding=3,
        )
        style.map("TCombobox",
            fieldbackground=[("readonly", BG_MID), ("!readonly", BG_MID)],
            foreground=[("readonly", FG_MAIN), ("!readonly", FG_MAIN)],
        )
        style.configure("TScrollbar",
            background=BG_MID,
            troughcolor=BG_DEEP,
            bordercolor=BG_DEEP,
            arrowcolor=FG_DIM,
        )
        style.configure("Accent.TButton",
            background=ACCENT,
            foreground=BG_DEEP,
            font=FONT_BOLD,
            padding=(14, 7),
            borderwidth=0,
        )
        style.map("Accent.TButton",
            background=[("active", "#E8A030"), ("disabled", BG_MID)],
            foreground=[("disabled", FG_DIM)],
        )
        style.configure("Ghost.TButton",
            background=BG_MID,
            foreground=FG_MAIN,
            font=FONT_BOLD,
            padding=(14, 7),
            borderwidth=0,
        )
        style.map("Ghost.TButton",
            background=[("active", BG_HOVER)],
        )
        style.configure("Post.TButton",
            background=ACCENT,
            foreground=BG_DEEP,
            font=("Segoe UI", 11, "bold"),
            padding=(28, 10),
            borderwidth=0,
        )
        style.map("Post.TButton",
            background=[("active", "#E8A030"), ("disabled", BG_MID)],
            foreground=[("disabled", FG_DIM)],
        )

    # ------------------------------------------------------------------
    # UI construction
    # ------------------------------------------------------------------

    def _build_ui(self):

        # ── Header row ────────────────────────────────────────────────
        header = tk.Frame(self, bg=BG_CARD, height=44)
        header.pack(fill="x")
        header.pack_propagate(False)

        # Left: title + version
        title_cluster = tk.Frame(header, bg=BG_CARD)
        title_cluster.pack(side="left", padx=16, fill="y")
        self._title_lbl = tk.Label(
            title_cluster, text="SUMNABATCH",
            bg=BG_CARD, fg=ACCENT, font=FONT_TITLE,
        )
        self._title_lbl.pack(side="left", anchor="center")
        tk.Label(title_cluster, text=BUILD_VERSION,
                 bg=BG_CARD, fg=FG_DIM,
                 font=("Segoe UI", 8)).pack(side="left", padx=(8, 0), anchor="center")

        # Right: Help button
        ttk.Button(header, text="?  Help", style="Ghost.TButton",
                   command=self._show_help).pack(side="right", padx=14, pady=6)

        # Centre: tab strip
        self._active_tab     = 'post'
        self._tab_btns       = {}
        self._tab_indicators = {}
        tab_strip = tk.Frame(header, bg=BG_CARD)
        tab_strip.pack(side="left", padx=(28, 0), fill="y")
        _tabs = [('post', 'POST'), ('audit', 'AUDIT'),
                 ('repair', 'BASIC REPAIR'), ('match', 'ADV. MATCH')]
        if _SOB_AVAILABLE:
            _tabs += [('slapped', 'BATCH SLAPPED'), ('gram', 'BATCH, PLEASE'),
                      ('smacktalk', 'SMACK YOUR BATCH UP')]
        _tabs += [('settings', 'SETTINGS')]
        for _tname, _tlabel in _tabs:
            _cell = tk.Frame(tab_strip, bg=BG_CARD)
            _cell.pack(side="left")
            _active = (_tname == 'post')
            _btn = tk.Label(_cell, text=_tlabel,
                            bg=BG_CARD, fg=ACCENT if _active else FG_DIM,
                            font=FONT_BOLD, padx=16, cursor="hand2")
            _btn.pack()
            _ind = tk.Frame(_cell, bg=ACCENT if _active else BG_CARD, height=2)
            _ind.pack(fill="x")
            _btn.bind("<Button-1>", lambda e, t=_tname: self._switch_tab(t))
            self._tab_btns[_tname]       = _btn
            self._tab_indicators[_tname] = _ind

        self._session_remaining = 0
        self._session_timer_id  = None
        self._conn_flash_state  = False
        self._conn_flash_id     = None

        lf = self._led_font   # shorthand

        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # ── LED status bar — always visible ───────────────────────────
        sbar = tk.Frame(self, bg=BG_SBAR, height=90)
        sbar.pack(fill="x")
        sbar.pack_propagate(False)
        sbar.columnconfigure(0, weight=1)
        sbar.columnconfigure(1, weight=0)   # separator
        sbar.columnconfigure(2, weight=1)
        sbar.columnconfigure(3, weight=0)   # separator
        sbar.columnconfigure(4, weight=1)
        sbar.rowconfigure(0, weight=1)

        def _led_panel(col, header):
            """Create a padded panel cell and return it."""
            f = tk.Frame(sbar, bg=BG_SBAR)
            f.grid(row=0, column=col, sticky="nsew", padx=18, pady=10)
            tk.Label(f, text=header, bg=BG_SBAR, fg="#333333",
                     font=(lf, 7)).pack(anchor="w")
            return f

        def _led_sep(col):
            tk.Frame(sbar, bg="#222222", width=1).grid(
                row=0, column=col, sticky="ns", pady=10)

        # ── SITE panel ────────────────────────────────────────────────
        site_panel = _led_panel(0, "SITE CONNECTION")

        conn_row = tk.Frame(site_panel, bg=BG_SBAR)
        conn_row.pack(fill="x", pady=(4, 0))
        self._conn_dot = tk.Label(conn_row, text="■", bg=BG_SBAR, fg=LED_OFF,
                                  font=(lf, 20, "bold"))
        self._conn_dot.pack(side="left", anchor="w")
        self._conn_lbl = tk.Label(conn_row, text="NOT CONNECTED",
                                  bg=BG_SBAR, fg=LED_OFF, font=(lf, 20, "bold"))
        self._conn_lbl.pack(side="left", padx=(5, 0), anchor="w")
        self._session_timer_lbl = tk.Label(conn_row, text="--:--",
                                           bg=BG_SBAR, fg=LED_OFF,
                                           font=(lf, 20, "bold"))
        self._session_timer_lbl.pack(side="right", anchor="e", padx=(10, 4))

        _led_sep(1)

        # ── DRIVE panel ───────────────────────────────────────────────
        drive_panel = _led_panel(2, "CLOUD DRIVE")

        drive_row = tk.Frame(drive_panel, bg=BG_SBAR)
        drive_row.pack(fill="x", pady=(4, 0))
        self._drive_dot = tk.Label(drive_row, text="■", bg=BG_SBAR, fg=LED_OFF,
                                   font=(lf, 20, "bold"))
        self._drive_dot.pack(side="left", anchor="w")
        self._drive_lbl = tk.Label(drive_row, text="NOT CONNECTED",
                                   bg=BG_SBAR, fg=LED_OFF, font=(lf, 20, "bold"))
        self._drive_lbl.pack(side="left", padx=(5, 0), anchor="w")

        _led_sep(3)

        # ── AI panel ──────────────────────────────────────────────────
        ai_panel = _led_panel(4, "AI ENGINE")

        ai_row = tk.Frame(ai_panel, bg=BG_SBAR)
        ai_row.pack(fill="x", pady=(4, 0))
        self._ai_dot = tk.Label(ai_row, text="■", bg=BG_SBAR, fg=LED_OFF,
                                font=(lf, 20, "bold"))
        self._ai_dot.pack(side="left", anchor="w")
        self._ai_lbl = tk.Label(ai_row, text="NO KEY",
                                bg=BG_SBAR, fg=LED_OFF, font=(lf, 20, "bold"))
        self._ai_lbl.pack(side="left", padx=(5, 0), anchor="w")

        # ── Tab content frames (below shared LED bar) ─────────────────
        # POST is visible by default; AUDIT and REPAIR are packed on demand.
        self._post_frame     = tk.Frame(self, bg=BG_DEEP)
        self._audit_frame    = tk.Frame(self, bg=BG_DEEP)
        self._repair_frame   = tk.Frame(self, bg=BG_DEEP)
        self._settings_frame = tk.Frame(self, bg=BG_DEEP)
        self._match_frame    = tk.Frame(self, bg=BG_DEEP)
        if _SOB_AVAILABLE:
            self._slapped_frame   = tk.Frame(self, bg=BG_DEEP)
            self._gram_frame      = tk.Frame(self, bg=BG_DEEP)
            self._smacktalk_frame = tk.Frame(self, bg=BG_DEEP)
        self._post_frame.pack(fill="both", expand=True)
        # other frames packed by _switch_tab()

        # ── Config collapse toggle bar ────────────────────────────────
        self._cfg_visible = True
        cfg_toggle = tk.Frame(self._post_frame, bg=BG_DEEP, height=26, cursor="hand2")
        cfg_toggle.pack(fill="x")
        cfg_toggle.pack_propagate(False)
        self._cfg_arrow = tk.Label(cfg_toggle, text="▲  CONFIGURATION",
                                   bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                                   cursor="hand2")
        self._cfg_arrow.pack(side="left", padx=14, pady=4)

        # ── Config area ───────────────────────────────────────────────
        self._cfg_frame = tk.Frame(self._post_frame, bg=BG_DEEP)
        self._cfg_frame.pack(fill="x", padx=14, pady=10)
        cfg = self._cfg_frame

        def _toggle_cfg(e=None):
            if self._cfg_visible:
                # Collapse config → show queue
                # Insert queue items BEFORE the bottom bar so it stays anchored.
                self._cfg_frame.pack_forget()
                self._cfg_arrow.configure(text="▼  CONFIGURATION")
                self._queue_rule.pack(fill="x", before=self._bottom_sep)
                self._queue_hdr.pack(fill="x", before=self._bottom_sep)
                self._queue_sep.pack(fill="x", before=self._bottom_sep)
                self._entry_list.pack(fill="both", expand=True, before=self._bottom_sep)
            else:
                # Expand config → hide queue
                self._entry_list.pack_forget()
                self._queue_sep.pack_forget()
                self._queue_hdr.pack_forget()
                self._queue_rule.pack_forget()
                self._cfg_frame.pack(fill="x", padx=14, pady=10)
                self._cfg_arrow.configure(text="▲  CONFIGURATION")
            self._cfg_visible = not self._cfg_visible

        self._toggle_cfg = _toggle_cfg
        cfg_toggle.bind("<Button-1>", _toggle_cfg)
        self._cfg_arrow.bind("<Button-1>", _toggle_cfg)
        tk.Frame(self._post_frame, bg=BORDER, height=1).pack(fill="x")

        # Two-column grid: CONNECTION (left) | MANIFEST & DEFAULTS (right)
        cols = tk.Frame(cfg, bg=BG_DEEP)
        cols.pack(fill="x")
        cols.columnconfigure(0, weight=1)
        cols.columnconfigure(1, weight=1)

        # ── Box: CONNECTION ───────────────────────────────────────────
        self._url_var     = tk.StringVar()
        self._api_key_var = tk.StringVar()
        self._rem_var     = tk.BooleanVar()

        conn_box  = self._box(cols, "CONNECTION")
        conn_box.grid(row=0, column=0, sticky="nsew", padx=(0, 7))
        conn_body = self._box_body(conn_box)

        self._field(conn_body, "SITE URL", self._url_var)
        self._field(conn_body, "API KEY",  self._api_key_var, show="•")

        btn_row = tk.Frame(conn_body, bg=BG_CARD)
        btn_row.pack(fill="x")
        tk.Checkbutton(
            btn_row, text="Remember", variable=self._rem_var,
            bg=BG_CARD, fg=FG_DIM, selectcolor=BG_MID,
            activebackground=BG_CARD, activeforeground=ACCENT,
            font=FONT_SMALL, cursor="hand2",
        ).pack(side="left")
        self._connect_btn = ttk.Button(btn_row, text="Connect", style="Accent.TButton",
                                        command=self._on_connect)
        self._connect_btn.pack(side="right")

        # ── Box: MANIFEST & DEFAULTS ──────────────────────────────────
        self._folder_var   = tk.StringVar()
        self._manifest_var = tk.StringVar()
        self._def_cat_var    = tk.StringVar()
        self._def_alb_var    = tk.StringVar()
        self._def_orient_var = tk.StringVar(value='auto')

        mfst_box  = self._box(cols, "MANIFEST & DEFAULTS")
        mfst_box.grid(row=0, column=1, sticky="nsew", padx=(7, 0))
        mfst_body = self._box_body(mfst_box)

        self._field_browse(mfst_body, "IMAGE FOLDER",  self._folder_var,   self._browse_folder)
        self._field_browse(mfst_body, "MANIFEST FILE", self._manifest_var, self._browse_manifest)

        dm_cols = tk.Frame(mfst_body, bg=BG_CARD)
        dm_cols.pack(fill="x", pady=(0, 8))
        dm_cols.columnconfigure(0, weight=1)
        dm_cols.columnconfigure(1, weight=1)
        dm_cols.columnconfigure(2, weight=1)

        cat_cell = tk.Frame(dm_cols, bg=BG_CARD)
        cat_cell.grid(row=0, column=0, sticky="ew", padx=(0, 6))
        tk.Label(cat_cell, text="DEFAULT CATEGORY", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        self._def_cat_cb = ttk.Combobox(cat_cell, textvariable=self._def_cat_var, font=FONT_SMALL)
        self._def_cat_cb.pack(fill="x", pady=(2, 0))

        alb_cell = tk.Frame(dm_cols, bg=BG_CARD)
        alb_cell.grid(row=0, column=1, sticky="ew", padx=(0, 6))
        tk.Label(alb_cell, text="DEFAULT ALBUM", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        self._def_alb_cb = ttk.Combobox(alb_cell, textvariable=self._def_alb_var, font=FONT_SMALL)
        self._def_alb_cb.pack(fill="x", pady=(2, 0))

        orient_cell = tk.Frame(dm_cols, bg=BG_CARD)
        orient_cell.grid(row=0, column=2, sticky="ew")
        tk.Label(orient_cell, text="ORIENTATION", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        self._def_orient_cb = ttk.Combobox(
            orient_cell, textvariable=self._def_orient_var, font=FONT_SMALL,
            values=['Auto', 'Landscape', 'Portrait', 'Square'], state="readonly",
        )
        self._def_orient_cb.pack(fill="x", pady=(2, 0))

        mfst_btn_row = tk.Frame(mfst_body, bg=BG_CARD)
        mfst_btn_row.pack(anchor="w", fill="x", pady=(6, 0))
        ttk.Button(mfst_btn_row, text="Load Manifest", style="Ghost.TButton",
                   command=self._on_load).pack(side="left")
        ttk.Button(mfst_btn_row, text="Scan Folder", style="Ghost.TButton",
                   command=self._on_scan_folder).pack(side="left", padx=(8, 0))
        self._enrich_btn = ttk.Button(mfst_btn_row, text="✦ Enrich with Gemini",
                                       style="Ghost.TButton", command=self._on_enrich)
        self._enrich_btn.pack(side="left", padx=(8, 0))

        # ── Box: GOOGLE DRIVE ─────────────────────────────────────────
        self._goog_creds_var   = tk.StringVar()
        self._drive_folder_var = tk.StringVar()
        self._drive_enabled_var = tk.BooleanVar(value=True)

        drv_box  = self._box(cfg, "GOOGLE DRIVE (OPTIONAL)")
        drv_box.pack(fill="x", pady=(10, 0))
        drv_body = self._box_body(drv_box)

        drv_toggle_row = tk.Frame(drv_body, bg=BG_CARD)
        drv_toggle_row.pack(anchor="w", pady=(0, 6))
        ttk.Checkbutton(
            drv_toggle_row, text="Enable Google Drive", variable=self._drive_enabled_var,
            command=self._on_drive_toggle,
        ).pack(side="left")

        drv_row = tk.Frame(drv_body, bg=BG_CARD)
        drv_row.pack(fill="x")
        drv_row.columnconfigure(0, weight=3)
        drv_row.columnconfigure(1, weight=2)
        drv_row.columnconfigure(2, weight=0)

        creds_cell = tk.Frame(drv_row, bg=BG_CARD)
        creds_cell.grid(row=0, column=0, sticky="ew", padx=(0, 8))
        tk.Label(creds_cell, text="CREDENTIALS FILE", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        creds_inner = tk.Frame(creds_cell, bg=BG_CARD)
        creds_inner.pack(fill="x", pady=(2, 0))
        self._entry(creds_inner, self._goog_creds_var).pack(side="left", fill="x", expand=True, padx=(0, 4))
        self._mini_btn(creds_inner, "…", self._browse_creds).pack(side="left")

        fid_cell = tk.Frame(drv_row, bg=BG_CARD)
        fid_cell.grid(row=0, column=1, sticky="ew", padx=(0, 8))
        tk.Label(fid_cell, text="FOLDER ID", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        self._entry(fid_cell, self._drive_folder_var).pack(fill="x", pady=(2, 0))

        drv_btn_cell = tk.Frame(drv_row, bg=BG_CARD)
        drv_btn_cell.grid(row=0, column=2, sticky="e")
        self._drive_btn = ttk.Button(drv_btn_cell, text="Auth Drive", style="Ghost.TButton",
                                      command=self._on_auth_drive)
        self._drive_btn.pack(pady=(14, 4))

        # ── Box: GEMINI AI ────────────────────────────────────────────
        self._gemini_key_var = tk.StringVar()

        gem_box  = self._box(cfg, "GEMINI AI (OPTIONAL)")
        gem_box.pack(fill="x", pady=(10, 0))
        gem_body = self._box_body(gem_box)

        tk.Label(
            gem_body, text="Paste your Gemini API key to enable one-click metadata enrichment.",
            bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL,
        ).pack(anchor="w", pady=(0, 6))

        # API key row
        tk.Label(gem_body, text="API KEY", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        gem_key_inner = tk.Frame(gem_body, bg=BG_CARD)
        gem_key_inner.pack(fill="x", pady=(2, 0))
        self._entry(gem_key_inner, self._gemini_key_var, width=0).pack(side="left", fill="x", expand=True, padx=(0, 6))
        self._gem_test_btn = ttk.Button(gem_key_inner, text="Test Connection",
                                         style="Ghost.TButton", command=self._on_gemini_test)
        self._gem_test_btn.pack(side="left")
        self._gem_test_lbl = tk.Label(gem_key_inner, text="", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        self._gem_test_lbl.pack(side="left", padx=(6, 0))

        # Prompt label + preset controls on separate rows
        tk.Label(gem_body, text="PROMPT", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w", pady=(12, 0))
        gem_preset_row = tk.Frame(gem_body, bg=BG_CARD)
        gem_preset_row.pack(fill="x", pady=(4, 4))
        tk.Label(gem_preset_row, text="Saved presets:",
                 bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(side="left")
        self._gem_preset_cb = ttk.Combobox(gem_preset_row, font=FONT_SMALL,
                                            state="readonly", width=24)
        self._gem_preset_cb.pack(side="left", padx=(6, 0))
        self._gem_preset_cb.bind("<<ComboboxSelected>>", self._on_gem_preset_load)
        self._mini_btn(gem_preset_row, "Save As…", self._on_gem_preset_save).pack(side="left", padx=(8, 0))
        self._mini_btn(gem_preset_row, "Delete",   self._on_gem_preset_delete).pack(side="left", padx=(4, 0))

        tk.Label(gem_body, text="Leave blank to use the built-in default.",
                 bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w", pady=(0, 4))
        self._gem_prompt_txt = tk.Text(
            gem_body, height=5,
            bg=BG_MID, fg=FG_MAIN, insertbackground=ACCENT,
            relief="flat", font=FONT_SMALL, bd=0,
            highlightthickness=1, highlightbackground=BORDER, highlightcolor=ACCENT,
            wrap="word",
        )
        self._gem_prompt_txt.pack(fill="x")
        self._gem_prompts: dict = {}   # name → text, loaded at startup

        # ── Box: COPYRIGHT ────────────────────────────────────────────
        self._copyright_var = tk.StringVar()

        copy_box  = self._box(cfg, "COPYRIGHT STRING")
        copy_box.pack(fill="x", pady=(10, 0))
        copy_body = self._box_body(copy_box)
        self._entry(copy_body, self._copyright_var, width=0).pack(fill="x")

        # ── Queue header (ruled like admin h2) ────────────────────────
        self._queue_rule = tk.Frame(self._post_frame, bg=BORDER, height=1)
        self._queue_hdr  = tk.Frame(self._post_frame, bg=BG_DEEP, height=30)
        self._queue_hdr.pack_propagate(False)
        self._queue_lbl = tk.Label(
            self._queue_hdr, text="QUEUE — 0 ITEMS",
            bg=BG_DEEP, fg=FG_DIM, font=FONT_BOLD,
        )
        self._queue_lbl.pack(side="left", padx=14, pady=6)

        # Select-all toggle — Enrich and Post act only on ticked rows.
        self._sel_all_var = tk.BooleanVar(value=True)
        self._sel_all_chk = tk.Checkbutton(
            self._queue_hdr, text="Select all", variable=self._sel_all_var,
            command=self._on_select_all,
            bg=BG_DEEP, fg=FG_DIM, activebackground=BG_DEEP, activeforeground=FG_MAIN,
            selectcolor=BG_MID, font=FONT_SMALL, cursor="hand2",
            relief="flat", bd=0, highlightthickness=0,
        )
        self._sel_all_chk.pack(side="right", padx=14)

        self._queue_sep  = tk.Frame(self._post_frame, bg=BORDER, height=1)

        # ── Entry list ────────────────────────────────────────────────
        self._entry_list = EntryList(self._post_frame)

        # Queue section is hidden while config is open; shown when config collapses
        # (pack() calls intentionally omitted — _toggle_cfg manages visibility)

        # ── Bottom action bar ─────────────────────────────────────────
        # Stored as instance vars so _toggle_cfg can insert queue items before them.
        self._bottom_sep = tk.Frame(self._post_frame, bg=BORDER, height=1)
        self._bottom_sep.pack(fill="x")
        self._bottom_bar = tk.Frame(self._post_frame, bg=BG_CARD, height=52)
        self._bottom_bar.pack(fill="x")
        bottom = self._bottom_bar
        bottom.pack_propagate(False)

        self._validate_btn = ttk.Button(bottom, text="Validate", style="Ghost.TButton",
                                         command=self._on_validate)
        self._validate_btn.pack(side="left", padx=(14, 6), pady=10)

        # ENRICH WITH GEMINI — canvas button matching POST BATCH style
        self._bottom_enrich_canvas = tk.Canvas(
            bottom, width=180, height=36,
            bg=BG_DEEP, highlightthickness=0, cursor="hand2",
        )
        self._bottom_enrich_canvas.pack(side="left", padx=(4, 0), pady=6)
        self._bottom_enrich_rect = self._bottom_enrich_canvas.create_rectangle(
            0, 0, 180, 36, fill="#0D2B3E", outline=FG_OK, width=1,
        )
        self._bottom_enrich_text = self._bottom_enrich_canvas.create_text(
            90, 18, text="✦ ENRICH", fill="#00BFFF",
            font=("Segoe UI", 10, "bold"),
        )
        self._bottom_enrich_canvas.bind("<Button-1>", lambda e: self._on_enrich())
        self._bottom_enrich_canvas.bind("<Enter>", lambda e: self._bottom_enrich_canvas.itemconfig(
            self._bottom_enrich_rect, fill="#143D55"))
        self._bottom_enrich_canvas.bind("<Leave>", lambda e: self._bottom_enrich_canvas.itemconfig(
            self._bottom_enrich_rect, fill="#0D2B3E"))

        # POST BATCH — drawn on a tk.Canvas because Windows ignores bg/fg
        # on both tk.Button AND tk.Label under certain DPI / theme combos.
        # Canvas pixel drawing is the one thing Windows cannot override.
        self._post_canvas = tk.Canvas(
            bottom, width=160, height=36,
            bg=BG_DEEP, highlightthickness=0, cursor="hand2",
        )
        self._post_canvas.pack(side="left", padx=(10, 0), pady=6)
        self._post_rect = self._post_canvas.create_rectangle(
            0, 0, 160, 36, fill=ACCENT, outline='', width=0,
        )
        self._post_text = self._post_canvas.create_text(
            80, 18, text="POST BATCH", fill=BG_DEEP,
            font=("Segoe UI", 11, "bold"),
        )
        self._post_canvas.bind("<Button-1>", lambda e: self._on_post())
        self._post_canvas.bind("<Enter>", lambda e: self._post_hover(True))
        self._post_canvas.bind("<Leave>", lambda e: self._post_hover(False))

        self._clear_btn = ttk.Button(bottom, text="Clear", style="Ghost.TButton",
                                      command=self._on_clear)
        self._clear_btn.pack(side="left", padx=(10, 0), pady=10)

        self._prog_var = tk.DoubleVar()
        self._progress = ttk.Progressbar(bottom, variable=self._prog_var,
                                          mode="determinate", length=200)
        self._progress.pack(side="right", padx=(0, 14), pady=16)

        self._prog_lbl = tk.Label(bottom, text="", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        self._prog_lbl.pack(side="right", padx=6)

        self._status_lbl = tk.Label(bottom, text="Load a manifest to begin.",
                                     bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        self._status_lbl.pack(side="right", padx=14)

        # ── Build audit and repair tab content ────────────────────────
        self._build_audit_ui()
        self._build_repair_ui()
        self._build_match_ui()
        self._build_settings_ui()
        self._build_sob_modes()

        # Phase-1 launch dedication (Richard Dimitri). Deferred so it draws over
        # the window; fully self-guarded so it can never block startup.
        try:
            import dedication
            self.after(400, lambda: dedication.maybe_show(self))
        except Exception:
            pass

    # ------------------------------------------------------------------
    # SON OF A BATCH — mount the offline posting mode panels
    # ------------------------------------------------------------------

    def _build_sob_modes(self):
        """Mount BATCH SLAPPED + BATCH, PLEASE; SMACK YOUR BATCH UP is a
        deferred 'coming soon' tab (it's the only mode needing a full local
        skin render, so it lands last)."""
        if not _SOB_AVAILABLE:
            return
        try:
            build_solo_mode(self._slapped_frame, self).pack(fill="both", expand=True)
            build_gram_mode(self._gram_frame, self).pack(fill="both", expand=True)
        except Exception as e:
            # Never let a panel error take down the whole tool.
            for fr in (self._slapped_frame, self._gram_frame):
                for w in fr.winfo_children():
                    w.destroy()
                tk.Label(fr, text=f"SON OF A BATCH panel failed to load:\n{e}",
                         bg=BG_DEEP, fg=FG_ERR, font=FONT_UI, justify="left").pack(padx=20, pady=20)
        # SMACK YOUR BATCH UP — deferred longform mode.
        tk.Label(self._smacktalk_frame, text="SMACK YOUR BATCH UP", bg=BG_DEEP,
                 fg=ACCENT, font=FONT_TITLE).pack(anchor="w", padx=20, pady=(24, 4))
        tk.Label(self._smacktalk_frame,
                 text="Longform / photo-essay mode — coming soon.\n\nIt's the only mode that "
                      "needs a full local skin render, so it ships after a tested SmackTalk "
                      "install. Build BATCH SLAPPED + BATCH, PLEASE here in the meantime.",
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_UI, justify="left").pack(anchor="w", padx=20)

    # ------------------------------------------------------------------
    # Tab switching
    # ------------------------------------------------------------------

    def _switch_tab(self, tab: str):
        if tab == self._active_tab:
            return
        for name, btn in self._tab_btns.items():
            active = (name == tab)
            btn.configure(fg=ACCENT if active else FG_DIM)
            self._tab_indicators[name].configure(bg=ACCENT if active else BG_CARD)
        self._post_frame.pack_forget()
        self._audit_frame.pack_forget()
        self._repair_frame.pack_forget()
        self._match_frame.pack_forget()
        self._settings_frame.pack_forget()
        for _attr in ('_slapped_frame', '_gram_frame', '_smacktalk_frame'):
            _fr = getattr(self, _attr, None)
            if _fr is not None:
                _fr.pack_forget()
        if tab == 'post':
            self._post_frame.pack(fill="both", expand=True)
        elif tab == 'audit':
            self._audit_frame.pack(fill="both", expand=True)
            self._refresh_audit_if_needed()
        elif tab == 'repair':
            self._repair_frame.pack(fill="both", expand=True)
        elif tab == 'match':
            self._match_frame.pack(fill="both", expand=True)
        elif tab == 'settings':
            self._settings_frame.pack(fill="both", expand=True)
        elif tab == 'slapped' and hasattr(self, '_slapped_frame'):
            self._slapped_frame.pack(fill="both", expand=True)
        elif tab == 'gram' and hasattr(self, '_gram_frame'):
            self._gram_frame.pack(fill="both", expand=True)
        elif tab == 'smacktalk' and hasattr(self, '_smacktalk_frame'):
            self._smacktalk_frame.pack(fill="both", expand=True)
        self._active_tab = tab

    # ------------------------------------------------------------------
    # Audit tab UI
    # ------------------------------------------------------------------

    def _build_audit_ui(self):
        """Populate self._audit_frame."""
        p = self._audit_frame

        # ── Top bar ───────────────────────────────────────────────────
        top = tk.Frame(p, bg=BG_CARD, height=44)
        top.pack(fill="x")
        top.pack_propagate(False)
        tk.Label(top, text="SITE AUDIT", bg=BG_CARD, fg=ACCENT,
                 font=FONT_TITLE).pack(side="left", padx=16, anchor="center")
        self._audit_refresh_btn = ttk.Button(
            top, text="↺  Refresh", style="Ghost.TButton",
            command=self._on_audit_refresh)
        self._audit_refresh_btn.pack(side="right", padx=14, pady=6)
        tk.Frame(p, bg=BORDER, height=1).pack(fill="x")

        # ── Summary stats + progress ──────────────────────────────────
        self._audit_summary_frame = tk.Frame(p, bg=BG_DEEP, padx=16, pady=8)
        self._audit_summary_frame.pack(fill="x")
        self._audit_summary_lbl = tk.Label(
            self._audit_summary_frame,
            text="Connect to a site on the POST tab, then switch here to audit it.",
            bg=BG_DEEP, fg=FG_DIM, font=FONT_UI, anchor="w")
        self._audit_summary_lbl.pack(fill="x")
        # Indeterminate progress bar — shown only while a pull is in flight
        self._audit_prog = ttk.Progressbar(
            self._audit_summary_frame, mode="indeterminate", length=400)
        # (not packed yet — _on_audit_refresh shows/hides it)
        self._audit_elapsed_lbl = tk.Label(
            self._audit_summary_frame,
            text="", bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL, anchor="w")
        # (not packed yet)
        tk.Frame(p, bg=BORDER, height=1).pack(fill="x")

        # ── Scrollable issue list ─────────────────────────────────────
        canvas_frame = tk.Frame(p, bg=BG_DEEP)
        canvas_frame.pack(fill="both", expand=True)

        self._audit_canvas = tk.Canvas(canvas_frame, bg=BG_DEEP,
                                       highlightthickness=0)
        vbar = ttk.Scrollbar(canvas_frame, orient="vertical",
                             command=self._audit_canvas.yview)
        self._audit_canvas.configure(yscrollcommand=vbar.set)
        vbar.pack(side="right", fill="y")
        self._audit_canvas.pack(side="left", fill="both", expand=True)

        self._audit_inner = tk.Frame(self._audit_canvas, bg=BG_DEEP)
        self._audit_win = self._audit_canvas.create_window(
            (0, 0), window=self._audit_inner, anchor="nw")
        self._audit_inner.bind("<Configure>", lambda e: self._audit_canvas.configure(
            scrollregion=self._audit_canvas.bbox("all")))
        self._audit_canvas.bind("<Configure>", lambda e:
            self._audit_canvas.itemconfigure(self._audit_win, width=e.width))
        self._audit_canvas.bind_all("<MouseWheel>",
            lambda e: self._audit_canvas.yview_scroll(
                int(-1 * (e.delta / 120)), "units") if self._active_tab == 'audit' else None)

        # Placeholder — replaced after first pull
        self._audit_placeholder = tk.Label(
            self._audit_inner,
            text="No audit data loaded.",
            bg=BG_DEEP, fg=FG_DIM, font=FONT_UI, anchor="w")
        self._audit_placeholder.pack(padx=16, pady=20, anchor="w")

        # Internal state
        self._audit_data     = None   # list of post dicts from audit_list()
        self._audit_summary  = None   # summary dict
        self._audit_loaded   = False

    def _refresh_audit_if_needed(self):
        """Auto-pull on first tab switch if connected and not yet loaded."""
        if not self._audit_loaded and self._client:
            self._on_audit_refresh()

    def _on_audit_refresh(self):
        if not self._client:
            self._audit_summary_lbl.configure(
                text="Not connected. Connect on the POST tab first.", fg=FG_WARN)
            return

        import time as _time_mod
        self._audit_refresh_btn.configure(state="disabled")
        self._audit_summary_lbl.configure(
            text="Step 1 / 2 — fetching summary stats…", fg=FG_WARN)
        self._audit_prog.pack(fill="x", pady=(6, 2))
        self._audit_elapsed_lbl.pack(anchor="w")
        self._audit_prog.start(12)   # ms per step — smooth bounce
        self._audit_pull_start = _time_mod.time()
        self._audit_elapsed_id = self.after(500, self._audit_tick_elapsed)

        def _worker():
            try:
                summary = self._client.audit_summary()
                total   = summary.get('total', '?')
                self.after(0, lambda: self._audit_summary_lbl.configure(
                    text=f"Step 2 / 2 — fetching {total} posts… "
                         f"(large sites can take 30–60 s)", fg=FG_WARN))
                posts = self._client.audit_list()
                self.after(0, lambda: self._on_audit_loaded(summary, posts))
            except Exception as exc:
                self.after(0, lambda: self._on_audit_error(str(exc)))

        threading.Thread(target=_worker, daemon=True).start()

    def _audit_tick_elapsed(self):
        """Update the elapsed-time label every second while audit is running."""
        import time as _time_mod
        if not hasattr(self, '_audit_pull_start'):
            return
        elapsed = int(_time_mod.time() - self._audit_pull_start)
        self._audit_elapsed_lbl.configure(
            text=f"  {elapsed}s elapsed…" if elapsed < 60
            else f"  {elapsed // 60}m {elapsed % 60}s elapsed…")
        # Reschedule until the pull finishes (_on_audit_loaded/_on_audit_error cancel it)
        self._audit_elapsed_id = self.after(1000, self._audit_tick_elapsed)

    def _audit_stop_progress(self):
        """Stop the progress bar and elapsed timer."""
        self._audit_prog.stop()
        self._audit_prog.pack_forget()
        self._audit_elapsed_lbl.pack_forget()
        self._audit_elapsed_lbl.configure(text="")
        if hasattr(self, '_audit_elapsed_id'):
            self.after_cancel(self._audit_elapsed_id)
            del self._audit_elapsed_id
        if hasattr(self, '_audit_pull_start'):
            del self._audit_pull_start

    def _on_audit_loaded(self, summary: dict, posts: list):
        self._audit_stop_progress()
        self._audit_summary  = summary
        self._audit_data     = posts
        self._audit_loaded   = True
        self._audit_refresh_btn.configure(state="normal")

        # Update summary text
        total  = summary.get('total', 0)
        miss   = summary.get('missing_drive', 0)
        dgrps  = summary.get('duplicate_groups', 0)
        need   = summary.get('posts_needing_titles', 0)
        parts  = [f"{total} published posts"]
        if miss:
            parts.append(f"{miss} missing Drive link{'s' if miss != 1 else ''}")
        else:
            parts.append("all have Drive links ✓")
        if dgrps:
            parts.append(f"{dgrps} duplicate title group{'s' if dgrps != 1 else ''} "
                         f"({need} post{'s' if need != 1 else ''} need new titles)")
        else:
            parts.append("no duplicate titles ✓")
        self._audit_summary_lbl.configure(
            text="  ·  ".join(parts),
            fg=FG_WARN if (miss or dgrps) else FG_OK)

        # Rebuild issue list
        for w in self._audit_inner.winfo_children():
            w.destroy()

        dups, missing = self._compute_audit_issues(posts)

        # Also populate the Repair tab's backfill panel with missing-link posts
        self._populate_backfill(missing)

        if not dups and not missing:
            tk.Label(self._audit_inner, text="✓  No issues found.",
                     bg=BG_DEEP, fg=FG_OK, font=FONT_BOLD,
                     anchor="w").pack(padx=16, pady=20, fill="x")
            return

        # ── Duplicate titles section ──────────────────────────────────
        if dups:
            self._audit_section(self._audit_inner, "DUPLICATE TITLES",
                                f"{len(dups)} groups · {sum(len(v)-1 for v in dups.values())} posts need new titles")
            for title, group_posts in sorted(dups.items(),
                                             key=lambda x: -len(x[1])):
                row = tk.Frame(self._audit_inner, bg=BG_CARD,
                               highlightthickness=1, highlightbackground=BORDER)
                row.pack(fill="x", padx=8, pady=2)
                count = len(group_posts)
                hdr = tk.Frame(row, bg=BG_CARD)
                hdr.pack(fill="x", padx=10, pady=(6, 2))
                tk.Label(hdr, text=f"×{count}", bg=BG_CARD, fg=FG_WARN,
                         font=FONT_BOLD, width=4, anchor="w").pack(side="left")
                tk.Label(hdr, text=title[:90], bg=BG_CARD, fg=FG_MAIN,
                         font=FONT_UI, anchor="w").pack(side="left", fill="x",
                                                         expand=True)
                for pp in group_posts:
                    sub = tk.Frame(row, bg=BG_CARD)
                    sub.pack(fill="x", padx=28, pady=(0, 2))
                    tk.Label(sub, text=f"ID {pp['snap_id']}",
                             bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL,
                             width=8, anchor="w").pack(side="left")
                    tk.Label(sub, text=pp['img_date'][:10],
                             bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL,
                             width=12, anchor="w").pack(side="left")
                    url = pp.get('download_url', '')
                    url_color = FG_OK if url else FG_ERR
                    url_text  = "Drive ✓" if url else "No Drive link"
                    tk.Label(sub, text=url_text, bg=BG_CARD, fg=url_color,
                             font=FONT_SMALL, anchor="w").pack(side="left")

        # ── Missing Drive links section ───────────────────────────────
        if missing:
            self._audit_section(self._audit_inner, "MISSING DRIVE LINKS",
                                f"{len(missing)} posts")
            for pp in missing:
                row = tk.Frame(self._audit_inner, bg=BG_CARD,
                               highlightthickness=1, highlightbackground=BORDER)
                row.pack(fill="x", padx=8, pady=2)
                r = tk.Frame(row, bg=BG_CARD)
                r.pack(fill="x", padx=10, pady=6)
                tk.Label(r, text=f"ID {pp['snap_id']}",
                         bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL,
                         width=8, anchor="w").pack(side="left")
                tk.Label(r, text=pp['img_date'][:10],
                         bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL,
                         width=12, anchor="w").pack(side="left")
                tk.Label(r, text=pp['img_title'][:70],
                         bg=BG_CARD, fg=FG_MAIN, font=FONT_UI,
                         anchor="w").pack(side="left", fill="x", expand=True)

        # ── Go to Repair button ───────────────────────────────────────
        if dups or missing:
            tk.Frame(self._audit_inner, bg=BORDER, height=1).pack(
                fill="x", padx=8, pady=(12, 0))
            btn_row = tk.Frame(self._audit_inner, bg=BG_DEEP)
            btn_row.pack(fill="x", padx=8, pady=8)
            ttk.Button(btn_row, text="→  Go to Repair",
                       style="Accent.TButton",
                       command=lambda: self._switch_tab('repair')).pack(
                           side="left")

    def _on_audit_error(self, msg: str):
        self._audit_stop_progress()
        self._audit_refresh_btn.configure(state="normal")
        self._audit_summary_lbl.configure(
            text=f"Audit failed: {msg}", fg=FG_ERR)

    def _compute_audit_issues(self, posts: list):
        """
        Returns:
          dups    — {title: [post, post, …]} for titles appearing more than once
          missing — [post, …] for posts with empty download_url
        """
        from collections import defaultdict
        title_map = defaultdict(list)
        missing   = []
        for p in posts:
            title_map[p.get('img_title') or ''].append(p)
            if not p.get('download_url'):
                missing.append(p)
        dups = {t: ps for t, ps in title_map.items() if len(ps) > 1 and t}
        return dups, missing

    def _audit_section(self, parent, title: str, subtitle: str = ''):
        """Render a section header row in the audit inner frame."""
        f = tk.Frame(parent, bg=BG_DEEP)
        f.pack(fill="x", padx=8, pady=(14, 4))
        tk.Label(f, text=title, bg=BG_DEEP, fg=ACCENT,
                 font=FONT_BOLD, anchor="w").pack(side="left")
        if subtitle:
            tk.Label(f, text=f"  —  {subtitle}", bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_SMALL, anchor="w").pack(side="left")
        tk.Frame(parent, bg=BORDER, height=1).pack(fill="x", padx=8)

    # ------------------------------------------------------------------
    # Advanced Visual Match tab UI
    # ------------------------------------------------------------------

    def _build_match_ui(self):
        """Populate self._match_frame — two-stage pHash + SIFT visual matching."""
        p = self._match_frame

        # ── Top bar ───────────────────────────────────────────────────
        top = tk.Frame(p, bg=BG_CARD, height=44)
        top.pack(fill="x")
        top.pack_propagate(False)
        tk.Label(top, text="ADVANCED VISUAL MATCH", bg=BG_CARD, fg=ACCENT,
                 font=FONT_TITLE).pack(side="left", padx=16, anchor="center")
        tk.Frame(p, bg=BORDER, height=1).pack(fill="x")

        # ── Config strip ──────────────────────────────────────────────
        cfg = tk.Frame(p, bg=BG_DEEP, padx=14, pady=10)
        cfg.pack(fill="x")

        self._match_srv_folder_var  = tk.StringVar()
        self._match_orig_folder_var = tk.StringVar()

        def _folder_row(parent, label, var, col):
            cell = tk.Frame(parent, bg=BG_DEEP)
            cell.grid(row=0, column=col, sticky="ew", padx=(0, 16))
            tk.Label(cell, text=label, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_SMALL).pack(anchor="w")
            row = tk.Frame(cell, bg=BG_DEEP)
            row.pack(fill="x", pady=(2, 0))
            tk.Entry(row, textvariable=var,
                     bg=BG_MID, fg=FG_MAIN, font=FONT_UI,
                     relief="flat", insertbackground=ACCENT).pack(
                         side="left", fill="x", expand=True, padx=(0, 4))
            self._mini_btn(row, "…",
                lambda v=var: v.set(
                    filedialog.askdirectory(
                        title="Select folder",
                        initialdir=v.get() if v.get() and os.path.isdir(v.get()) else "~")
                    or v.get()
                )
            ).pack(side="left")

        cfg.columnconfigure(0, weight=1)
        cfg.columnconfigure(1, weight=1)
        _folder_row(cfg, "SERVER FOLDER (local FTP copy)",
                    self._match_srv_folder_var, 0)
        _folder_row(cfg, "ORIGINALS FOLDER (local photos)",
                    self._match_orig_folder_var, 1)

        # Drive status + Match button
        ctrl = tk.Frame(p, bg=BG_CARD, padx=14, pady=8)
        ctrl.pack(fill="x")

        self._match_drive_lbl = tk.Label(ctrl, text="", bg=BG_CARD,
                                          fg=FG_DIM, font=FONT_SMALL)
        self._match_drive_lbl.pack(side="left")

        self._match_status_lbl = tk.Label(ctrl, text="", bg=BG_CARD,
                                           fg=FG_DIM, font=FONT_SMALL)
        self._match_status_lbl.pack(side="left", padx=(16, 0))

        self._match_prog_var = tk.DoubleVar()
        self._match_prog = ttk.Progressbar(ctrl, variable=self._match_prog_var,
                                            mode="determinate", length=220)
        self._match_prog.pack(side="left", padx=(16, 0))
        self._match_prog.pack_forget()   # shown only while matching

        self._match_stop_flag = False
        self._match_running   = False

        self._match_stop_btn = ttk.Button(ctrl, text="■  Stop",
                                           style="Ghost.TButton",
                                           command=self._on_match_stop,
                                           state="disabled")
        self._match_stop_btn.pack(side="right", padx=(8, 0))

        self._match_btn = ttk.Button(ctrl, text="▶  Run Matching",
                                      style="Accent.TButton",
                                      command=self._on_match_start)
        self._match_btn.pack(side="right")

        tk.Frame(p, bg=BORDER, height=1).pack(fill="x")

        # ── Scrollable MatchRow area ──────────────────────────────────
        canvas_frame = tk.Frame(p, bg=BG_DEEP)
        canvas_frame.pack(fill="both", expand=True)

        self._match_canvas = tk.Canvas(canvas_frame, bg=BG_DEEP,
                                        highlightthickness=0)
        mvbar = ttk.Scrollbar(canvas_frame, orient="vertical",
                              command=self._match_canvas.yview)
        self._match_canvas.configure(yscrollcommand=mvbar.set)
        mvbar.pack(side="right", fill="y")
        self._match_canvas.pack(side="left", fill="both", expand=True)

        self._match_inner = tk.Frame(self._match_canvas, bg=BG_DEEP)
        mwin = self._match_canvas.create_window(
            (0, 0), window=self._match_inner, anchor="nw")
        self._match_inner.bind("<Configure>", lambda e: self._match_canvas.configure(
            scrollregion=self._match_canvas.bbox("all")))
        self._match_canvas.bind("<Configure>", lambda e:
            self._match_canvas.itemconfigure(mwin, width=e.width))
        self._match_canvas.bind_all("<MouseWheel>",
            lambda e: self._match_canvas.yview_scroll(
                int(-1 * (e.delta / 120)), "units")
            if self._active_tab == 'match' else None)

        self._match_placeholder = tk.Label(
            self._match_inner,
            text=(
                "Pick a server folder and originals folder, then click Run Matching.\n\n"
                "Stage 1: pHash pre-filter narrows to the 10 most similar candidates.\n"
                "Stage 2: SIFT keypoint matching selects the best match.\n\n"
                "Requires Drive auth on the POST tab to upload confirmed matches."
            ),
            bg=BG_DEEP, fg=FG_DIM, font=FONT_UI,
            justify="left", anchor="w", wraplength=680)
        self._match_placeholder.pack(padx=20, pady=24, anchor="w")

        # Internal state
        self._match_row_widgets: list  = []
        self._match_done_count: int    = 0
        self._match_total_count: int   = 0
        self._match_upload_queue       = queue.Queue()
        self._match_upload_running     = False

        # Refresh drive status whenever this tab becomes active
        self.after(500, self._match_refresh_drive_status)

    def _match_refresh_drive_status(self):
        """Update the Drive status label in the match tab."""
        if not hasattr(self, '_match_drive_lbl'):
            return
        if self._drive_service:
            self._match_drive_lbl.configure(
                text="● Drive connected — uploads ready", fg=FG_OK)
        else:
            self._match_drive_lbl.configure(
                text="● Drive not connected — auth on POST tab before uploading",
                fg=FG_WARN)

    def _on_match_start(self):
        """Validate folders and launch two-stage matching in a thread pool."""
        srv_folder  = self._match_srv_folder_var.get().strip()
        orig_folder = self._match_orig_folder_var.get().strip()

        if not srv_folder or not os.path.isdir(srv_folder):
            messagebox.showerror("Server folder missing",
                                  "Select a valid server folder (local FTP copy).",
                                  parent=self)
            return
        if not orig_folder or not os.path.isdir(orig_folder):
            messagebox.showerror("Originals folder missing",
                                  "Select a valid originals folder.",
                                  parent=self)
            return

        # Collect image files from both folders
        _img_exts = {'.jpg', '.jpeg', '.png', '.webp'}
        srv_files  = [os.path.join(srv_folder, f)
                      for f in os.listdir(srv_folder)
                      if os.path.splitext(f.lower())[1] in _img_exts]
        orig_files = [os.path.join(orig_folder, f)
                      for f in os.listdir(orig_folder)
                      if os.path.splitext(f.lower())[1] in _img_exts]

        if not srv_files:
            messagebox.showinfo("No images", "No images found in the server folder.",
                                parent=self)
            return
        if not orig_files:
            messagebox.showinfo("No originals",
                                "No images found in the originals folder.", parent=self)
            return

        # Clear previous results
        for w in self._match_inner.winfo_children():
            w.destroy()
        self._match_row_widgets.clear()
        self._match_done_count  = 0
        self._match_total_count = len(srv_files)

        self._match_running   = True
        self._match_stop_flag = False
        self._match_btn.configure(state="disabled")
        self._match_stop_btn.configure(state="normal")
        self._match_prog.configure(maximum=len(srv_files))
        self._match_prog_var.set(0)
        self._match_prog.pack(side="left", padx=(16, 0))
        self._match_status_lbl.configure(
            text=f"Hashing {len(orig_files)} originals…", fg=FG_WARN)
        self._match_refresh_drive_status()

        def _run():
            import sys as _sys
            # Make matcher importable by worker processes
            _sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
            from matcher import match_one, phash_file

            # Pre-hash originals (fast — all in-process)
            orig_pairs = []
            for op in orig_files:
                h = phash_file(op)
                orig_pairs.append((op, h))

            self.after(0, lambda: self._match_status_lbl.configure(
                text=f"Matching {len(srv_files)} server images…", fg=FG_WARN))

            import math
            max_workers = max(1, min(4, math.floor(os.cpu_count() * 0.75)))

            done = 0
            args_list = [(sp, orig_pairs) for sp in srv_files]

            with ProcessPoolExecutor(max_workers=max_workers) as pool:
                futures = {pool.submit(match_one, a): a[0] for a in args_list}
                for fut in as_completed(futures):
                    if self._match_stop_flag:
                        pool.shutdown(wait=False, cancel_futures=True)
                        break
                    try:
                        result = fut.result()
                    except Exception:
                        result = {'server_path': futures[fut],
                                  'match_path': None, 'confidence': 0.0,
                                  'match_count': 0, 'candidates': [],
                                  'label': 'none'}

                    # Build a minimal record dict from server filename
                    sp      = result['server_path']
                    base    = os.path.splitext(os.path.basename(sp))[0]
                    record  = {'snap_id': base, 'img_title': base,
                                'img_file': sp}

                    done += 1
                    _done = done
                    _res  = dict(result)
                    _rec  = dict(record)
                    self.after(0, lambda r=_rec, rs=_res, d=_done: (
                        self._match_add_row(r, rs),
                        self._match_prog_var.set(d),
                        self._match_status_lbl.configure(
                            text=f"{d} / {len(srv_files)} matched", fg=FG_WARN),
                    ))

            self.after(0, self._on_match_done)

        threading.Thread(target=_run, daemon=True).start()

    def _match_add_row(self, record: dict, result: dict):
        row = SybuMatchRow(self._match_inner, record, result, self)
        row.pack(fill="x", padx=8, pady=(0, 4))
        self._match_row_widgets.append(row)
        self._match_canvas.configure(
            scrollregion=self._match_canvas.bbox("all"))

    def _on_match_done(self):
        self._match_running = False
        self._match_btn.configure(state="normal")
        self._match_stop_btn.configure(state="disabled")
        self._match_prog.pack_forget()
        total = len(self._match_row_widgets)
        self._match_status_lbl.configure(
            text=f"Done — {total} image{'s' if total != 1 else ''} to review.",
            fg=FG_OK if total else FG_DIM)

    def _on_match_stop(self):
        self._match_stop_flag = True
        self._match_stop_btn.configure(state="disabled")
        self._match_status_lbl.configure(text="Stopping…", fg=FG_WARN)

    def _match_on_row_done(self):
        self._match_done_count += 1

    def _match_remove_row(self, row: 'SybuMatchRow'):
        if row in self._match_row_widgets:
            self._match_row_widgets.remove(row)

    def _match_enqueue_upload(self, row: 'SybuMatchRow'):
        self._match_upload_queue.put(row)
        if not self._match_upload_running:
            self._match_drain_uploads()

    def _match_drain_uploads(self):
        """Serially drain the upload queue — one upload at a time."""
        try:
            row = self._match_upload_queue.get_nowait()
        except queue.Empty:
            self._match_upload_running = False
            return
        self._match_upload_running = True
        done_evt = threading.Event()
        row._start_upload(done_evt)

        def _wait():
            done_evt.wait()
            self.after(0, self._match_drain_uploads)

        threading.Thread(target=_wait, daemon=True).start()

    # ------------------------------------------------------------------
    # Settings tab UI
    # ------------------------------------------------------------------

    def _build_settings_ui(self):
        """Populate self._settings_frame — profile manager."""
        p = self._settings_frame

        # ── Top bar ───────────────────────────────────────────────────
        top = tk.Frame(p, bg=BG_CARD, height=44)
        top.pack(fill="x")
        top.pack_propagate(False)
        tk.Label(top, text="SITE PROFILES", bg=BG_CARD, fg=ACCENT,
                 font=FONT_TITLE).pack(side="left", padx=16, anchor="center")
        tk.Frame(p, bg=BORDER, height=1).pack(fill="x")

        # ── Two-pane layout ───────────────────────────────────────────
        body = tk.Frame(p, bg=BG_DEEP)
        body.pack(fill="both", expand=True, padx=16, pady=14)
        body.columnconfigure(0, weight=0)   # profile list — fixed
        body.columnconfigure(1, weight=1)   # form — expands
        body.rowconfigure(0, weight=1)

        # ── LEFT: profile list ────────────────────────────────────────
        left = tk.Frame(body, bg=BG_CARD, width=200,
                        highlightthickness=1, highlightbackground=BORDER)
        left.grid(row=0, column=0, sticky="nsew", padx=(0, 14))
        left.pack_propagate(False)

        tk.Label(left, text="SITES", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_SMALL).pack(anchor="w", padx=10, pady=(8, 4))

        list_frame = tk.Frame(left, bg=BG_MID)
        list_frame.pack(fill="both", expand=True, padx=6)

        self._profile_lb = tk.Listbox(
            list_frame,
            bg=BG_MID, fg=FG_MAIN, font=FONT_UI,
            selectbackground=ACCENT, selectforeground="#000000",
            relief="flat", bd=0, highlightthickness=0,
            activestyle="none",
        )
        lb_scroll = ttk.Scrollbar(list_frame, orient="vertical",
                                  command=self._profile_lb.yview)
        self._profile_lb.configure(yscrollcommand=lb_scroll.set)
        lb_scroll.pack(side="right", fill="y")
        self._profile_lb.pack(side="left", fill="both", expand=True)
        self._profile_lb.bind("<<ListboxSelect>>", self._on_profile_select)

        btn_bar = tk.Frame(left, bg=BG_CARD)
        btn_bar.pack(fill="x", padx=6, pady=8)
        ttk.Button(btn_bar, text="+ New", style="Ghost.TButton",
                   command=self._on_profile_new).pack(side="left")
        ttk.Button(btn_bar, text="Delete", style="Ghost.TButton",
                   command=self._on_profile_delete).pack(side="right")

        load_btn = tk.Frame(left, bg=BG_CARD)
        load_btn.pack(fill="x", padx=6, pady=(0, 10))
        ttk.Button(load_btn, text="→  Load Site",
                   style="Accent.TButton",
                   command=self._on_profile_load).pack(fill="x")

        # ── RIGHT: form ───────────────────────────────────────────────
        right_outer = tk.Frame(body, bg=BG_DEEP)
        right_outer.grid(row=0, column=1, sticky="nsew")

        rcanvas = tk.Canvas(right_outer, bg=BG_DEEP, highlightthickness=0)
        rvbar   = ttk.Scrollbar(right_outer, orient="vertical",
                                command=rcanvas.yview)
        rcanvas.configure(yscrollcommand=rvbar.set)
        rvbar.pack(side="right", fill="y")
        rcanvas.pack(side="left", fill="both", expand=True)
        right = tk.Frame(rcanvas, bg=BG_DEEP)
        rwin  = rcanvas.create_window((0, 0), window=right, anchor="nw")
        right.bind("<Configure>", lambda e: rcanvas.configure(
            scrollregion=rcanvas.bbox("all")))
        rcanvas.bind("<Configure>", lambda e:
            rcanvas.itemconfigure(rwin, width=e.width))
        rcanvas.bind_all("<MouseWheel>",
            lambda e: rcanvas.yview_scroll(
                int(-1 * (e.delta / 120)), "units")
            if self._active_tab == 'settings' else None)

        def _sbox(title):
            f = tk.Frame(right, bg=BG_CARD,
                         highlightthickness=1, highlightbackground=BORDER)
            f.pack(fill="x", pady=(0, 10))
            tk.Label(f, text=title, bg=BG_CARD, fg=FG_DIM,
                     font=FONT_SMALL).pack(anchor="w", padx=10, pady=(6, 0))
            body_f = tk.Frame(f, bg=BG_CARD, padx=10, pady=8)
            body_f.pack(fill="x")
            return body_f

        def _sfield(parent, label, var, show='', width=0):
            tk.Label(parent, text=label, bg=BG_CARD, fg=FG_DIM,
                     font=FONT_SMALL).pack(anchor="w")
            e = tk.Entry(parent, textvariable=var,
                         bg=BG_MID, fg=FG_MAIN, font=FONT_UI,
                         relief="flat", insertbackground=ACCENT,
                         show=show)
            if width:
                e.config(width=width)
            e.pack(fill="x", pady=(2, 8))
            return e

        def _sfield_browse(parent, label, var, browse_cmd):
            tk.Label(parent, text=label, bg=BG_CARD, fg=FG_DIM,
                     font=FONT_SMALL).pack(anchor="w")
            row = tk.Frame(parent, bg=BG_CARD)
            row.pack(fill="x", pady=(2, 8))
            tk.Entry(row, textvariable=var,
                     bg=BG_MID, fg=FG_MAIN, font=FONT_UI,
                     relief="flat", insertbackground=ACCENT).pack(
                         side="left", fill="x", expand=True, padx=(0, 4))
            self._mini_btn(row, "…", browse_cmd).pack(side="left")

        # Profile name
        name_row = tk.Frame(right, bg=BG_DEEP)
        name_row.pack(fill="x", pady=(0, 10))
        tk.Label(name_row, text="PROFILE NAME", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_SMALL).pack(anchor="w")
        self._sp_name_var = tk.StringVar()
        tk.Entry(name_row, textvariable=self._sp_name_var,
                 bg=BG_MID, fg=ACCENT, font=FONT_BOLD,
                 relief="flat", insertbackground=ACCENT).pack(
                     fill="x", pady=(2, 0))

        # CONNECTION
        self._sp_url_var  = tk.StringVar()
        self._sp_api_key_var = tk.StringVar()
        conn_body = _sbox("CONNECTION")
        _sfield(conn_body, "SITE URL", self._sp_url_var)
        _sfield(conn_body, "API KEY",  self._sp_api_key_var, show="•")

        # Test connection button + result label (inside CONNECTION box)
        test_row = tk.Frame(conn_body, bg=BG_CARD)
        test_row.pack(fill="x", pady=(0, 4))
        self._sp_test_btn = ttk.Button(test_row, text="Test Connection",
                                        style="Ghost.TButton",
                                        command=self._on_sp_test_connection)
        self._sp_test_btn.pack(side="left")
        self._sp_test_lbl = tk.Label(test_row, text="", bg=BG_CARD,
                                      fg=FG_DIM, font=FONT_SMALL)
        self._sp_test_lbl.pack(side="left", padx=(10, 0))

        # GOOGLE DRIVE
        self._sp_creds_var  = tk.StringVar()
        self._sp_folder_var = tk.StringVar()
        drv_body = _sbox("GOOGLE DRIVE")
        _sfield_browse(drv_body, "CREDENTIALS FILE", self._sp_creds_var,
                       lambda: self._sp_creds_var.set(
                           filedialog.askopenfilename(
                               title="Select credentials.json",
                               filetypes=[("JSON files", "*.json"), ("All files", "*.*")],
                               initialdir=os.path.dirname(self._sp_creds_var.get())
                                          if self._sp_creds_var.get() else "~")
                           or self._sp_creds_var.get()))
        _sfield(drv_body, "DRIVE FOLDER ID", self._sp_folder_var)

        # GEMINI AI
        self._sp_gemini_var = tk.StringVar()
        gem_body = _sbox("GEMINI AI")
        _sfield(gem_body, "API KEY", self._sp_gemini_var)

        # DEFAULTS
        self._sp_copyright_var = tk.StringVar()
        self._sp_cat_var       = tk.StringVar()
        self._sp_alb_var       = tk.StringVar()
        self._sp_orient_var    = tk.StringVar(value='auto')
        def_body = _sbox("DEFAULTS")
        _sfield(def_body, "COPYRIGHT TEXT", self._sp_copyright_var)
        def_row = tk.Frame(def_body, bg=BG_CARD)
        def_row.pack(fill="x")
        def_row.columnconfigure(0, weight=1)
        def_row.columnconfigure(1, weight=1)
        def_row.columnconfigure(2, weight=1)
        cat_f = tk.Frame(def_row, bg=BG_CARD)
        cat_f.grid(row=0, column=0, sticky="ew", padx=(0, 6))
        tk.Label(cat_f, text="DEFAULT CATEGORY", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_SMALL).pack(anchor="w")
        tk.Entry(cat_f, textvariable=self._sp_cat_var,
                 bg=BG_MID, fg=FG_MAIN, font=FONT_UI, relief="flat",
                 insertbackground=ACCENT).pack(fill="x", pady=(2, 0))
        alb_f = tk.Frame(def_row, bg=BG_CARD)
        alb_f.grid(row=0, column=1, sticky="ew", padx=(0, 6))
        tk.Label(alb_f, text="DEFAULT ALBUM", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_SMALL).pack(anchor="w")
        tk.Entry(alb_f, textvariable=self._sp_alb_var,
                 bg=BG_MID, fg=FG_MAIN, font=FONT_UI, relief="flat",
                 insertbackground=ACCENT).pack(fill="x", pady=(2, 0))
        ori_f = tk.Frame(def_row, bg=BG_CARD)
        ori_f.grid(row=0, column=2, sticky="ew")
        tk.Label(ori_f, text="ORIENTATION", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_SMALL).pack(anchor="w")
        ttk.Combobox(ori_f, textvariable=self._sp_orient_var,
                     values=['auto', 'landscape', 'portrait', 'square'],
                     font=FONT_SMALL, state="readonly").pack(fill="x", pady=(2, 0))

        # Save button
        save_row = tk.Frame(right, bg=BG_DEEP)
        save_row.pack(fill="x", pady=(4, 0))
        self._sp_status_lbl = tk.Label(save_row, text="", bg=BG_DEEP,
                                        fg=FG_DIM, font=FONT_SMALL)
        self._sp_status_lbl.pack(side="left")
        ttk.Button(save_row, text="Save Profile",
                   style="Accent.TButton",
                   command=self._on_profile_save).pack(side="right")

        # Populate list
        self._settings_refresh_list()

    def _settings_refresh_list(self, select_name: str = ''):
        """Reload the profile listbox."""
        names = profile_manager.list_profiles()
        self._profile_lb.delete(0, "end")
        sel_idx = 0
        for i, name in enumerate(names):
            self._profile_lb.insert("end", name)
            if name == select_name:
                sel_idx = i
        if names:
            self._profile_lb.selection_set(sel_idx)
            self._profile_lb.see(sel_idx)
            self._settings_populate_form(names[sel_idx])

    def _on_profile_select(self, _event=None):
        sel = self._profile_lb.curselection()
        if not sel:
            return
        name = self._profile_lb.get(sel[0])
        self._settings_populate_form(name)

    def _settings_populate_form(self, name: str):
        p = profile_manager.load_profile(name)
        if p is None:
            return
        self._sp_name_var.set(p.get('name', ''))
        self._sp_url_var.set(p.get('url', ''))
        self._sp_api_key_var.set(p.get('api_key', ''))
        self._sp_creds_var.set(p.get('google_credentials', ''))
        self._sp_folder_var.set(p.get('drive_folder_id', ''))
        self._sp_gemini_var.set(p.get('gemini_api_key', ''))
        self._sp_copyright_var.set(p.get('copyright_text', ''))
        self._sp_cat_var.set(p.get('default_category', ''))
        self._sp_alb_var.set(p.get('default_album', ''))
        self._sp_orient_var.set(p.get('default_orientation', 'auto'))
        self._sp_status_lbl.configure(text='', fg=FG_DIM)

    def _on_profile_new(self):
        blank = profile_manager.blank_profile()
        # Pick a unique name
        existing = profile_manager.list_profiles()
        base = 'New Site'
        name = base
        n = 2
        while name in existing:
            name = f'{base} {n}'
            n += 1
        blank['name'] = name
        profile_manager.save_profile(blank)
        self._settings_refresh_list(select_name=name)

    def _on_profile_save(self):
        name = self._sp_name_var.get().strip()
        if not name:
            messagebox.showwarning("Name required",
                                   "Enter a profile name before saving.", parent=self)
            return
        # If name changed, check for collision
        sel = self._profile_lb.curselection()
        old_name = self._profile_lb.get(sel[0]) if sel else ''
        existing = profile_manager.list_profiles()
        if name != old_name and name in existing:
            if not messagebox.askyesno("Overwrite?",
                    f'A profile named "{name}" already exists. Overwrite?',
                    parent=self):
                return
        # Delete old file if renamed
        if old_name and old_name != name:
            profile_manager.delete_profile(old_name)

        profile_manager.save_profile({
            'name':                name,
            'url':                 self._sp_url_var.get().strip(),
            'api_key':             self._sp_api_key_var.get().strip(),
            'google_credentials':  self._sp_creds_var.get().strip(),
            'drive_folder_id':     self._sp_folder_var.get().strip(),
            'drive_enabled':       True,
            'gemini_api_key':      self._sp_gemini_var.get().strip(),
            'copyright_text':      self._sp_copyright_var.get(),
            'default_category':    self._sp_cat_var.get().strip(),
            'default_album':       self._sp_alb_var.get().strip(),
            'default_orientation': self._sp_orient_var.get(),
        })
        self._settings_refresh_list(select_name=name)
        self._sp_status_lbl.configure(text='✓  Saved', fg=FG_OK)

    def _on_profile_delete(self):
        sel = self._profile_lb.curselection()
        if not sel:
            return
        name = self._profile_lb.get(sel[0])
        if not messagebox.askyesno("Delete profile",
                f'Delete "{name}"? This cannot be undone.', parent=self):
            return
        profile_manager.delete_profile(name)
        self._settings_refresh_list()
        self._sp_status_lbl.configure(text='', fg=FG_DIM)

    def _on_profile_load(self):
        """Load selected profile into POST tab fields and reconnect."""
        sel = self._profile_lb.curselection()
        if not sel:
            messagebox.showwarning("No profile selected",
                                   "Select a site profile first.", parent=self)
            return
        name = self._profile_lb.get(sel[0])
        p = profile_manager.load_profile(name)
        if p is None:
            return

        # Populate POST tab config vars
        self._url_var.set(p.get('url', ''))
        self._api_key_var.set(p.get('api_key', ''))
        self._goog_creds_var.set(p.get('google_credentials', ''))
        self._drive_folder_var.set(p.get('drive_folder_id', ''))
        self._gemini_key_var.set(p.get('gemini_api_key', ''))
        self._copyright_var.set(p.get('copyright_text', ''))
        self._def_cat_var.set(p.get('default_category', ''))
        self._def_alb_var.set(p.get('default_album', ''))
        orient = p.get('default_orientation', 'auto')
        self._def_orient_var.set(orient.capitalize() if orient != 'auto' else 'Auto')
        drive_on = p.get('drive_enabled', True)
        self._drive_enabled_var.set(drive_on)
        self._on_drive_toggle()

        # Save to config.ini so values persist on next launch
        self._save_config()

        # Switch to POST and connect
        self._switch_tab('post')
        self._on_connect()

    def _on_sp_test_connection(self):
        """
        Test connection using the URL / API key currently in the
        Settings form fields (no save required).  Runs in a background thread
        so the UI stays responsive.
        """
        url = self._sp_url_var.get().strip()
        key = self._sp_api_key_var.get().strip()

        if not url or not key:
            self._sp_test_lbl.configure(text="Enter URL and API Key first.", fg=FG_WARN)
            return

        self._sp_test_btn.configure(state="disabled")
        self._sp_test_lbl.configure(text="Testing…", fg=FG_DIM)

        def _worker():
            try:
                from poster import SnapSmackClient
                client = SnapSmackClient(url, api_key=key)
                client.verify()
                self.after(0, lambda: self._sp_test_lbl.configure(
                    text="✓  Connected successfully", fg=FG_OK))
            except Exception as exc:
                msg = str(exc)
                if len(msg) > 80:
                    msg = msg[:77] + "…"
                self.after(0, lambda m=msg: self._sp_test_lbl.configure(
                    text=f"✗  {m}", fg=FG_ERR))
            finally:
                self.after(0, lambda: self._sp_test_btn.configure(state="normal"))

        threading.Thread(target=_worker, daemon=True).start()

    # ------------------------------------------------------------------
    # Repair tab UI
    # ------------------------------------------------------------------

    def _build_repair_ui(self):
        """Populate self._repair_frame."""
        p = self._repair_frame

        # ── Top bar ───────────────────────────────────────────────────
        top = tk.Frame(p, bg=BG_CARD, height=44)
        top.pack(fill="x")
        top.pack_propagate(False)
        tk.Label(top, text="BASIC REPAIR & MATCH", bg=BG_CARD, fg=ACCENT,
                 font=FONT_TITLE).pack(side="left", padx=16, anchor="center")
        tk.Frame(p, bg=BORDER, height=1).pack(fill="x")

        # Scrollable body
        canvas_frame = tk.Frame(p, bg=BG_DEEP)
        canvas_frame.pack(fill="both", expand=True)
        rcanvas = tk.Canvas(canvas_frame, bg=BG_DEEP, highlightthickness=0)
        rvbar   = ttk.Scrollbar(canvas_frame, orient="vertical",
                                command=rcanvas.yview)
        rcanvas.configure(yscrollcommand=rvbar.set)
        rvbar.pack(side="right", fill="y")
        rcanvas.pack(side="left", fill="both", expand=True)
        rbody = tk.Frame(rcanvas, bg=BG_DEEP)
        rwin  = rcanvas.create_window((0, 0), window=rbody, anchor="nw")
        rbody.bind("<Configure>", lambda e: rcanvas.configure(
            scrollregion=rcanvas.bbox("all")))
        rcanvas.bind("<Configure>", lambda e:
            rcanvas.itemconfigure(rwin, width=e.width))
        rcanvas.bind_all("<MouseWheel>",
            lambda e: rcanvas.yview_scroll(
                int(-1 * (e.delta / 120)), "units") if self._active_tab == 'repair' else None)

        # ═══════════════════════════════════════════════════════════════
        # ACTION 1: Rename Drive Files to {id}.jpg
        # ═══════════════════════════════════════════════════════════════
        self._repair_section(rbody, "1. RENAME DRIVE FILES TO {id}.jpg",
            "Updates filenames in Google Drive to match their blog post ID. "
            "URLs are file-ID-based and are NOT changed — no posts will break.")

        rename_body = tk.Frame(rbody, bg=BG_CARD, padx=16, pady=12)
        rename_body.pack(fill="x", padx=8, pady=(0, 4))

        self._rename_status_lbl = tk.Label(
            rename_body,
            text="Requires Drive auth and audit data. Pull audit on the AUDIT tab first.",
            bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL, anchor="w", wraplength=700)
        self._rename_status_lbl.pack(fill="x", pady=(0, 8))

        self._rename_prog_var = tk.DoubleVar()
        self._rename_prog = ttk.Progressbar(rename_body, variable=self._rename_prog_var,
                                             mode="determinate", length=400)
        self._rename_prog.pack(anchor="w", pady=(0, 6))

        rename_btn_row = tk.Frame(rename_body, bg=BG_CARD)
        rename_btn_row.pack(fill="x")
        self._rename_btn = ttk.Button(rename_btn_row, text="▶  Start Rename Batch",
                                       style="Accent.TButton",
                                       command=self._on_rename_start)
        self._rename_btn.pack(side="left")
        self._rename_stop_btn = ttk.Button(rename_btn_row, text="■  Stop",
                                            style="Ghost.TButton",
                                            command=self._on_rename_stop,
                                            state="disabled")
        self._rename_stop_btn.pack(side="left", padx=(8, 0))

        rename_log_frame = tk.Frame(rename_body, bg=BG_MID,
                                    highlightthickness=1,
                                    highlightbackground=BORDER)
        rename_log_frame.pack(fill="x", pady=(10, 0))
        self._rename_log = tk.Text(rename_log_frame, bg=BG_MID, fg=FG_MAIN,
                                    font=FONT_SMALL, height=8, state="disabled",
                                    wrap="none", relief="flat")
        self._rename_log.pack(fill="x", padx=4, pady=4)
        self._rename_log.tag_configure("ok",   foreground=FG_OK)
        self._rename_log.tag_configure("err",  foreground=FG_ERR)
        self._rename_log.tag_configure("warn", foreground=FG_WARN)
        self._rename_running = False
        self._rename_stop    = False

        # ═══════════════════════════════════════════════════════════════
        # ACTION 2: Re-enrich Duplicate Titles
        # ═══════════════════════════════════════════════════════════════
        tk.Frame(rbody, bg=BORDER, height=1).pack(fill="x", padx=8, pady=(16, 0))
        self._repair_section(rbody, "2. RE-ENRICH DUPLICATE TITLES",
            "Downloads each duplicate-title post's original from Google Drive, "
            "sends to Gemini, writes a new unique title back to the blog.")

        enrich_body = tk.Frame(rbody, bg=BG_CARD, padx=16, pady=12)
        enrich_body.pack(fill="x", padx=8, pady=(0, 4))

        self._reenrich_status_lbl = tk.Label(
            enrich_body,
            text="Requires Drive auth, Gemini key, and audit data.",
            bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL, anchor="w", wraplength=700)
        self._reenrich_status_lbl.pack(fill="x", pady=(0, 8))

        self._reenrich_prog_var = tk.DoubleVar()
        self._reenrich_prog = ttk.Progressbar(enrich_body,
                                               variable=self._reenrich_prog_var,
                                               mode="determinate", length=400)
        self._reenrich_prog.pack(anchor="w", pady=(0, 6))

        reenrich_btn_row = tk.Frame(enrich_body, bg=BG_CARD)
        reenrich_btn_row.pack(fill="x")
        self._reenrich_btn = ttk.Button(reenrich_btn_row,
                                         text="▶  Start Re-enrichment",
                                         style="Accent.TButton",
                                         command=self._on_reenrich_start)
        self._reenrich_btn.pack(side="left")
        self._reenrich_stop_btn = ttk.Button(reenrich_btn_row, text="■  Stop",
                                              style="Ghost.TButton",
                                              command=self._on_reenrich_stop,
                                              state="disabled")
        self._reenrich_stop_btn.pack(side="left", padx=(8, 0))

        reenrich_log_frame = tk.Frame(enrich_body, bg=BG_MID,
                                       highlightthickness=1,
                                       highlightbackground=BORDER)
        reenrich_log_frame.pack(fill="x", pady=(10, 0))
        self._reenrich_log = tk.Text(reenrich_log_frame, bg=BG_MID, fg=FG_MAIN,
                                      font=FONT_SMALL, height=8, state="disabled",
                                      wrap="none", relief="flat")
        self._reenrich_log.pack(fill="x", padx=4, pady=4)
        self._reenrich_log.tag_configure("ok",   foreground=FG_OK)
        self._reenrich_log.tag_configure("err",  foreground=FG_ERR)
        self._reenrich_log.tag_configure("warn", foreground=FG_WARN)
        self._reenrich_running = False
        self._reenrich_stop    = False

        # ═══════════════════════════════════════════════════════════════
        # ACTION 3: Backfill Missing Drive Links
        # ═══════════════════════════════════════════════════════════════
        tk.Frame(rbody, bg=BORDER, height=1).pack(fill="x", padx=8, pady=(16, 0))
        self._repair_section(rbody, "3. BACKFILL MISSING DRIVE LINKS",
            "For posts without a download URL, paste in the Drive share link and save it.")

        self._backfill_body = tk.Frame(rbody, bg=BG_CARD, padx=16, pady=12)
        self._backfill_body.pack(fill="x", padx=8, pady=(0, 16))

        self._backfill_status_lbl = tk.Label(
            self._backfill_body,
            text="Pull audit data on the AUDIT tab to see missing-link posts here.",
            bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL, anchor="w")
        self._backfill_status_lbl.pack(fill="x")
        # Rows are built dynamically by _populate_backfill()

    def _repair_section(self, parent, title: str, desc: str):
        hdr = tk.Frame(parent, bg=BG_DEEP)
        hdr.pack(fill="x", padx=8, pady=(12, 4))
        tk.Label(hdr, text=title, bg=BG_DEEP, fg=ACCENT,
                 font=FONT_BOLD, anchor="w").pack(fill="x")
        if desc:
            tk.Label(hdr, text=desc, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_SMALL, anchor="w",
                     wraplength=760).pack(fill="x", pady=(2, 0))

    def _repair_log(self, widget: 'tk.Text', msg: str, tag: str = ''):
        widget.configure(state="normal")
        widget.insert("end", msg + "\n", tag)
        widget.see("end")
        widget.configure(state="disabled")

    # ------------------------------------------------------------------
    # Repair Action 1: Rename Drive files
    # ------------------------------------------------------------------

    def _on_rename_start(self):
        if not self._drive_service:
            messagebox.showerror("Drive not connected",
                                 "Auth Drive on the POST tab first.", parent=self)
            return
        if not self._audit_data:
            messagebox.showerror("No audit data",
                                 "Pull audit data on the AUDIT tab first.", parent=self)
            return

        posts_with_drive = [
            p for p in self._audit_data if p.get('download_url')]
        if not posts_with_drive:
            messagebox.showinfo("Nothing to rename",
                                "No posts have Drive links.", parent=self)
            return

        self._rename_running = True
        self._rename_stop    = False
        self._rename_btn.configure(state="disabled")
        self._rename_stop_btn.configure(state="normal")
        self._rename_prog.configure(maximum=len(posts_with_drive))
        self._rename_prog_var.set(0)
        self._rename_status_lbl.configure(
            text=f"Renaming {len(posts_with_drive)} files…", fg=FG_WARN)

        import drive as drive_module

        def _worker():
            import re, time
            done = 0; errors = 0
            for pp in posts_with_drive:
                if self._rename_stop:
                    self.after(0, lambda: self._repair_log(
                        self._rename_log, "— Stopped by user.", "warn"))
                    break
                url   = pp['download_url']
                sid   = pp['snap_id']
                # Extract Drive file ID — handles both URL formats
                m = re.search(r'/file/d/([a-zA-Z0-9_-]+)', url)
                if not m:
                    m = re.search(r'[?&]id=([a-zA-Z0-9_-]+)', url)
                if not m:
                    self.after(0, lambda s=sid: self._repair_log(
                        self._rename_log,
                        f"✗ ID {s} — cannot extract Drive file ID from URL", "err"))
                    errors += 1
                    done   += 1
                    self.after(0, lambda d=done: self._rename_prog_var.set(d))
                    continue
                file_id  = m.group(1)
                new_name = f"{sid}.jpg"
                try:
                    drive_module.rename(self._drive_service, file_id, new_name)
                    self.after(0, lambda s=sid, n=new_name: self._repair_log(
                        self._rename_log, f"✓ ID {s}  →  {n}", "ok"))
                except Exception as exc:
                    self.after(0, lambda s=sid, e=str(exc): self._repair_log(
                        self._rename_log, f"✗ ID {s} — {e}", "err"))
                    errors += 1
                done += 1
                self.after(0, lambda d=done: self._rename_prog_var.set(d))
                time.sleep(0.15)   # stay under Drive API quota

            total = len(posts_with_drive)
            self.after(0, lambda: self._on_rename_done(done, errors, total))

        threading.Thread(target=_worker, daemon=True).start()

    def _on_rename_stop(self):
        self._rename_stop = True
        self._rename_stop_btn.configure(state="disabled")

    def _on_rename_done(self, done: int, errors: int, total: int):
        self._rename_running = False
        self._rename_btn.configure(state="normal")
        self._rename_stop_btn.configure(state="disabled")
        msg = f"Done: {done}/{total} processed, {errors} error(s)."
        self._rename_status_lbl.configure(
            text=msg, fg=FG_OK if not errors else FG_WARN)
        self._repair_log(self._rename_log, f"\n{msg}")

    # ------------------------------------------------------------------
    # Repair Action 2: Re-enrich duplicate titles
    # ------------------------------------------------------------------

    def _on_reenrich_start(self):
        if not self._drive_service:
            messagebox.showerror("Drive not connected",
                                 "Auth Drive on the POST tab first.", parent=self)
            return
        if not self._audit_data:
            messagebox.showerror("No audit data",
                                 "Pull audit data on the AUDIT tab first.", parent=self)
            return
        if not self._client:
            messagebox.showerror("Not connected",
                                 "Connect to site on the POST tab first.", parent=self)
            return

        gemini_key = self._config.get('gemini_api_key', '').strip()
        if not gemini_key:
            messagebox.showerror("No Gemini key",
                                 "Add a Gemini API key on the POST tab first.",
                                 parent=self)
            return

        dups, _ = self._compute_audit_issues(self._audit_data)
        if not dups:
            messagebox.showinfo("No duplicates",
                                "No duplicate titles found in audit data.", parent=self)
            return

        # Collect posts that need new titles (all but the first per group)
        to_fix = []
        for title, group in dups.items():
            to_fix.extend(group[1:])   # keep first, re-enrich the rest

        if not to_fix:
            return

        self._reenrich_running = True
        self._reenrich_stop    = False
        self._reenrich_btn.configure(state="disabled")
        self._reenrich_stop_btn.configure(state="normal")
        self._reenrich_prog.configure(maximum=len(to_fix))
        self._reenrich_prog_var.set(0)
        self._reenrich_status_lbl.configure(
            text=f"Re-enriching {len(to_fix)} posts…", fg=FG_WARN)

        # Seed used_titles from all current blog titles (guard against NULL titles)
        used_titles = {p['img_title'].strip().lower()
                       for p in self._audit_data if p.get('img_title')}

        import gemini as gemini_module
        import drive as drive_module

        # Pre-fetch site data for category/album prompts if available
        cats   = list(self._site_data._cat_display.values()) if self._site_data else []
        albums = list(self._site_data._album_display.values()) if self._site_data else []

        def _worker():
            import re, os, time
            done = 0; errors = 0
            genai = None
            try:
                import google.generativeai as genai_mod
                genai_mod.configure(api_key=gemini_key)
                model = genai_mod.GenerativeModel(gemini_module.MODEL_NAME)
            except Exception as e:
                self.after(0, lambda: self._repair_log(
                    self._reenrich_log, f"✗ Gemini init failed: {e}", "err"))
                self.after(0, lambda: self._on_reenrich_done(0, len(to_fix), len(to_fix)))
                return

            prompt = gemini_module._build_prompt(cats, albums)

            for pp in to_fix:
                if self._reenrich_stop:
                    self.after(0, lambda: self._repair_log(
                        self._reenrich_log, "— Stopped by user.", "warn"))
                    break

                sid = pp['snap_id']
                url = pp.get('download_url', '')
                old_title = pp['img_title']

                if not url:
                    self.after(0, lambda s=sid: self._repair_log(
                        self._reenrich_log,
                        f"✗ ID {s} — no Drive URL, cannot download", "err"))
                    errors += 1
                    done   += 1
                    self.after(0, lambda d=done: self._reenrich_prog_var.set(d))
                    continue

                m = re.search(r'/file/d/([a-zA-Z0-9_-]+)', url)
                if not m:
                    m = re.search(r'[?&]id=([a-zA-Z0-9_-]+)', url)
                if not m:
                    self.after(0, lambda s=sid: self._repair_log(
                        self._reenrich_log,
                        f"✗ ID {s} — cannot extract Drive file ID", "err"))
                    errors += 1
                    done   += 1
                    self.after(0, lambda d=done: self._reenrich_prog_var.set(d))
                    continue

                file_id  = m.group(1)
                tmp_path = None
                try:
                    # 1. Download from Drive
                    tmp_path = drive_module.download_to_temp(
                        self._drive_service, file_id)

                    # 2. Send to Gemini — retry up to 4× for uniqueness
                    img_part  = gemini_module._load_image_part(None, tmp_path)
                    new_title = ''
                    for attempt in range(1, 5):
                        run_prompt = prompt if attempt == 1 else (
                            f"The title \"{new_title}\" is already in use. "
                            f"Generate a DIFFERENT haiku-style title.\n\n" + prompt)
                        resp   = model.generate_content([run_prompt, img_part])
                        parsed = gemini_module._parse_response(resp.text)
                        t      = parsed.get('title', '').strip()
                        if t and t.lower() not in used_titles:
                            new_title = t
                            break
                        if t:
                            new_title = t   # keep for retry prompt

                    if not new_title or new_title.lower() in used_titles:
                        raise RuntimeError("Could not generate a unique title after 4 attempts")

                    # 3. Update blog
                    self._client.audit_update_title(sid, new_title)
                    used_titles.add(new_title.lower())

                    self.after(0, lambda s=sid, o=old_title, n=new_title:
                        self._repair_log(self._reenrich_log,
                            f'✓ ID {s}  "{o[:40]}"  →  "{n[:40]}"', "ok"))

                except Exception as exc:
                    self.after(0, lambda s=sid, e=str(exc): self._repair_log(
                        self._reenrich_log, f"✗ ID {s} — {e}", "err"))
                    errors += 1
                finally:
                    if tmp_path and os.path.isfile(tmp_path):
                        try:
                            os.unlink(tmp_path)
                        except OSError:
                            pass

                done += 1
                self.after(0, lambda d=done: self._reenrich_prog_var.set(d))
                time.sleep(0.5)   # Gemini rate limiting

            self.after(0, lambda: self._on_reenrich_done(done, errors, len(to_fix)))

        threading.Thread(target=_worker, daemon=True).start()

    def _on_reenrich_stop(self):
        self._reenrich_stop = True
        self._reenrich_stop_btn.configure(state="disabled")

    def _on_reenrich_done(self, done: int, errors: int, total: int):
        self._reenrich_running = False
        self._reenrich_btn.configure(state="normal")
        self._reenrich_stop_btn.configure(state="disabled")
        msg = f"Done: {done}/{total} processed, {errors} error(s). Refresh Audit to verify."
        self._reenrich_status_lbl.configure(
            text=msg, fg=FG_OK if not errors else FG_WARN)
        self._repair_log(self._reenrich_log, f"\n{msg}")
        # Mark audit as stale so next switch re-pulls
        self._audit_loaded = False

    # ------------------------------------------------------------------
    # Repair Action 3: Backfill missing Drive links
    # ------------------------------------------------------------------

    def _populate_backfill(self, missing: list):
        """Build one row per missing-link post in the backfill panel."""
        # Clear existing rows (keep status label)
        for w in self._backfill_body.winfo_children():
            if w is not self._backfill_status_lbl:
                w.destroy()

        if not missing:
            self._backfill_status_lbl.configure(
                text="✓  No posts are missing Drive links.", fg=FG_OK)
            return

        drive_ready = (self._drive_service is not None
                       and bool(self._drive_folder_var.get().strip()))

        self._backfill_status_lbl.configure(
            text=f"{len(missing)} post(s) missing a Drive link. "
                 + ("Searching Drive…" if drive_ready
                    else "Paste the share URL and click Save."),
            fg=FG_WARN)

        self._backfill_vars = {}
        rows_data = []

        for pp in missing:
            row = tk.Frame(self._backfill_body, bg=BG_CARD)
            row.pack(fill="x", pady=3)
            sid   = pp['snap_id']
            title = pp['img_title']

            tk.Label(row, text=f"ID {sid}", bg=BG_CARD, fg=FG_DIM,
                     font=FONT_SMALL, width=8, anchor="w").pack(side="left")
            tk.Label(row, text=title[:40], bg=BG_CARD, fg=FG_MAIN,
                     font=FONT_SMALL, width=42, anchor="w").pack(side="left", padx=(0, 8))

            # Status label — shows "…" while auto-searching, then result or nothing
            status_lbl = tk.Label(row, text="…" if drive_ready else "",
                                  bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL, width=12)
            status_lbl.pack(side="left")

            # Manual entry controls — hidden while Drive auto-search is running;
            # revealed if the search comes up empty or Drive is not connected.
            url_var = tk.StringVar()
            self._backfill_vars[sid] = url_var
            entry    = tk.Entry(row, textvariable=url_var, bg=BG_MID, fg=FG_MAIN,
                                font=FONT_SMALL, relief="flat", width=36,
                                insertbackground=ACCENT)
            save_lbl = tk.Label(row, text="", bg=BG_CARD, font=FONT_SMALL, width=4)
            save_btn = ttk.Button(row, text="Save", style="Ghost.TButton",
                                  command=lambda s=sid, v=url_var, l=save_lbl:
                                      self._on_backfill_save(s, v, l))

            if not drive_ready:
                # Drive not connected — show manual controls immediately
                entry.pack(side="left", padx=(0, 4))
                save_lbl.pack(side="left")
                save_btn.pack(side="left")

            rows_data.append((sid, title, url_var, status_lbl, entry, save_lbl, save_btn))

        # If Drive is ready, kick off auto-find-and-save for every row.
        # Stagger by 300 ms so we don't hammer the API simultaneously.
        if drive_ready:
            folder_id = self._drive_folder_var.get().strip()
            for i, row_info in enumerate(rows_data):
                self.after(i * 300,
                           lambda ri=row_info, fid=folder_id:
                               self._auto_backfill(ri, fid))

    def _auto_backfill(self, row_info: tuple, folder_id: str):
        """
        Search Drive for a file matching the post title; if found, save the
        URL directly to the blog without any user interaction.
        Reveals the manual entry controls only if the search fails or finds nothing.
        """
        sid, title, url_var, status_lbl, entry, save_lbl, save_btn = row_info

        def _reveal_manual():
            entry.pack(side="left", padx=(0, 4))
            save_lbl.pack(side="left")
            save_btn.pack(side="left")

        def _worker():
            # --- Drive search ---
            try:
                results = drive_module.search(self._drive_service, folder_id, title)
            except Exception as exc:
                self.after(0, lambda: status_lbl.configure(text="✗ search err", fg=FG_ERR))
                self.after(0, _reveal_manual)
                print(f"[backfill] Drive search error for ID {sid}: {exc}")
                return

            if not results:
                self.after(0, lambda: status_lbl.configure(text="not found", fg=FG_DIM))
                self.after(0, _reveal_manual)
                return

            # Found — auto-save to blog
            url = results[0]['url']
            self.after(0, lambda: url_var.set(url))
            self.after(0, lambda: status_lbl.configure(text="saving…", fg=FG_DIM))

            if not self._client:
                self.after(0, lambda: status_lbl.configure(text="not connected", fg=FG_ERR))
                self.after(0, _reveal_manual)
                return

            try:
                self._client.keepalive()
                r = self._client.session.post(
                    f"{self._client.base_url}/smack-backfill.php",
                    data={'action': 'update', 'snap_id': sid, 'download_url': url},
                    timeout=15)
                r.raise_for_status()
                data = r.json()
                if data.get('ok'):
                    self.after(0, lambda: status_lbl.configure(text="✓ saved", fg=FG_OK))
                    self._audit_loaded = False
                else:
                    raise RuntimeError(data.get('error', 'Save failed'))
            except Exception as exc:
                self.after(0, lambda: status_lbl.configure(text="✗ save err", fg=FG_ERR))
                self.after(0, _reveal_manual)
                print(f"[backfill] Auto-save error for ID {sid}: {exc}")

        threading.Thread(target=_worker, daemon=True).start()

    def _on_backfill_save(self, snap_id: int, url_var: 'tk.StringVar',
                          status_lbl: 'tk.Label'):
        """Manual save — used only when auto-find came up empty."""
        url = url_var.get().strip()
        if not url:
            messagebox.showwarning("Empty URL", "Paste the Drive share URL first.",
                                   parent=self)
            return
        if not self._client:
            messagebox.showerror("Not connected",
                                 "Connect to site on the POST tab first.", parent=self)
            return
        status_lbl.configure(text="saving…", fg=FG_WARN)

        def _worker():
            try:
                self._client.keepalive()
                r = self._client.session.post(
                    f"{self._client.base_url}/smack-backfill.php",
                    data={'action': 'update', 'snap_id': snap_id,
                          'download_url': url},
                    timeout=15)
                r.raise_for_status()
                data = r.json()
                if data.get('ok'):
                    self.after(0, lambda: status_lbl.configure(text="✓", fg=FG_OK))
                    self._audit_loaded = False
                else:
                    raise RuntimeError(data.get('error', 'Save failed'))
            except Exception as exc:
                self.after(0, lambda: status_lbl.configure(text="✗", fg=FG_ERR))
                self.after(0, lambda: messagebox.showerror(
                    "Save failed", str(exc), parent=self))
        threading.Thread(target=_worker, daemon=True).start()

    # ------------------------------------------------------------------
    # Box layout helpers (mirror admin .box / box-header pattern)
    # ------------------------------------------------------------------

    def _box(self, parent, title: str) -> tk.Frame:
        """Create a titled box container matching admin .box style."""
        outer = tk.Frame(parent, bg=BORDER, padx=1, pady=1)
        hdr   = tk.Frame(outer, bg=BG_DEEP, height=26)
        hdr.pack(fill="x")
        hdr.pack_propagate(False)
        tk.Label(hdr, text=title, bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL).pack(
            side="left", padx=10, pady=4)
        return outer

    def _box_body(self, box: tk.Frame) -> tk.Frame:
        """Add and return the content area of a box."""
        body = tk.Frame(box, bg=BG_CARD, padx=12, pady=10)
        body.pack(fill="both", expand=True)
        return body

    def _field(self, parent, label: str, var: tk.StringVar, show: str = "") -> tk.Entry:
        """Stacked label + full-width input, with bottom margin."""
        tk.Label(parent, text=label, bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        e = self._entry(parent, var, width=0, show=show)
        e.pack(fill="x", pady=(2, 8))
        return e

    def _field_browse(self, parent, label: str, var: tk.StringVar, cmd) -> None:
        """Stacked label + entry + browse button row."""
        tk.Label(parent, text=label, bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        row = tk.Frame(parent, bg=BG_CARD)
        row.pack(fill="x", pady=(2, 8))
        self._entry(row, var).pack(side="left", fill="x", expand=True, padx=(0, 4))
        self._mini_btn(row, "…", cmd).pack(side="left")

    def _field_in(self, grid_parent, label: str, var: tk.StringVar,
                  row: int, col: int, padx=(0, 0), show: str = "") -> None:
        """Stacked label + input placed into a grid cell."""
        cell = tk.Frame(grid_parent, bg=BG_CARD)
        cell.grid(row=row, column=col, sticky="ew", padx=padx)
        tk.Label(cell, text=label, bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        self._entry(cell, var, width=0, show=show).pack(fill="x", pady=(2, 0))

    # ------------------------------------------------------------------
    # Widget helpers
    # ------------------------------------------------------------------

    def _lbl(self, parent, text: str) -> tk.Label:
        bg = parent.cget('bg') if parent else BG_DEEP
        return tk.Label(parent, text=text, bg=bg, fg=FG_DIM, font=FONT_SMALL)

    def _entry(self, parent, var: tk.StringVar, width=18, show="") -> tk.Entry:
        return tk.Entry(
            parent, textvariable=var, width=width, show=show,
            bg=BG_MID, fg=FG_MAIN, insertbackground=ACCENT,
            relief="flat", font=FONT_UI,
            highlightthickness=1, highlightbackground=BORDER,
            highlightcolor=ACCENT,
        )

    def _mini_btn(self, parent, text: str, cmd) -> tk.Button:
        return tk.Button(
            parent, text=text, command=cmd,
            bg=BG_MID, fg=FG_DIM, activebackground=BG_HOVER,
            activeforeground=FG_MAIN, relief="flat",
            font=FONT_SMALL, padx=5, pady=2, cursor="hand2",
        )

    # ------------------------------------------------------------------
    # Config
    # ------------------------------------------------------------------

    def _load_config_to_ui(self):
        c = self._config
        self._url_var.set(c.get('url', ''))
        self._api_key_var.set(c.get('api_key', ''))
        self._rem_var.set(c.get('remember', False))
        self._def_cat_var.set(c.get('default_category', ''))
        self._def_alb_var.set(c.get('default_album', ''))
        self._def_orient_var.set(c.get('default_orientation', 'Auto'))
        self._folder_var.set(c.get('last_image_folder', ''))
        self._manifest_var.set(c.get('last_manifest_file', ''))
        self._goog_creds_var.set(c.get('google_credentials', ''))
        self._drive_folder_var.set(c.get('drive_folder_id', ''))
        self._drive_enabled_var.set(c.get('drive_enabled', True))
        self._gemini_key_var.set(c.get('gemini_api_key', ''))
        last_prompt = c.get('gemini_last_prompt', '')
        self._gem_prompt_txt.delete('1.0', 'end')
        if last_prompt:
            self._gem_prompt_txt.insert('1.0', last_prompt)
        self._gem_prompts = cfg_module.load_prompts()
        self._refresh_preset_dropdown()
        self._copyright_var.set(c.get('copyright_text', ''))
        self._update_ai_dot()

        # Scroll long path fields to show the end (filename) instead of
        # the beginning which is just C:\Users\... and looks empty.
        self.after(50, self._scroll_path_fields_to_end)

    def _scroll_path_fields_to_end(self):
        """Scroll all path entry fields to show the rightmost text."""
        for var in (self._folder_var, self._manifest_var,
                    self._goog_creds_var, self._drive_folder_var,
                    self._url_var, self._copyright_var):
            for widget in self.winfo_children():
                self._scroll_entries_for_var(widget, var)

    def _scroll_entries_for_var(self, widget, var):
        """Recursively find Entry widgets bound to var and scroll to end."""
        if isinstance(widget, tk.Entry):
            try:
                if widget.cget('textvariable') and str(widget['textvariable']) == str(var):
                    widget.xview_moveto(1.0)
            except Exception:
                pass
        for child in widget.winfo_children():
            self._scroll_entries_for_var(child, var)

    def _auto_reconnect(self):
        """Silently reconnect Drive and site on launch if credentials are saved."""
        c = self._config

        # ── Drive ─────────────────────────────────────────────────────
        if not self._drive_enabled_var.get():
            self._drive_dot.configure(fg=LED_OFF)
            self._drive_lbl.configure(text="DISABLED", fg=LED_OFF)
        else:
            creds_path = c.get('google_credentials', '')
            if drive_module.is_authenticated() and creds_path and os.path.isfile(creds_path):
                self._drive_dot.configure(fg=LED_WARN)
                self._drive_lbl.configure(text="CONNECTING...", fg=LED_WARN)
                def _drive_thread():
                    try:
                        service = drive_module.authenticate(creds_path)
                        self._drive_service = service
                        self.after(0, lambda: self._drive_dot.configure(fg=LED_OK))
                        self.after(0, lambda: self._drive_lbl.configure(text="AUTHENTICATED", fg=LED_OK))
                    except Exception:
                        self.after(0, lambda: self._drive_dot.configure(fg=LED_OFF))
                        self.after(0, lambda: self._drive_lbl.configure(text="NOT CONNECTED", fg=LED_OFF))
                threading.Thread(target=_drive_thread, daemon=True).start()

        # ── Site ──────────────────────────────────────────────────────
        url      = c.get('url', '').strip()
        api_key = c.get('api_key', '').strip()
        if url and api_key:
            self._conn_dot.configure(fg=LED_WARN)
            self._conn_lbl.configure(text="CONNECTING...", fg=LED_WARN)
            def _site_thread():
                try:
                    client = SnapSmackClient(url, api_key=api_key)
                    client.verify()
                    site_data = client.fetch_site_data()
                    self._client    = client
                    self._site_data = site_data
                    cats   = sorted(site_data._cat_display.values())
                    albums = sorted(site_data._album_display.values())
                    def _done():
                        self._conn_dot.configure(fg=LED_OK)
                        self._conn_lbl.configure(
                            text=f"CONNECTED  {len(cats)} CATS  {len(albums)} ALBUMS",
                            fg=LED_OK,
                        )
                        self._entry_list.update_combos(cats, albums)
                        self._def_cat_cb['values'] = [''] + cats
                        self._def_alb_cb['values'] = [''] + albums

                        self._save_config()
                    self.after(0, _done)
                except Exception:
                    self.after(0, lambda: self._conn_dot.configure(fg=LED_OFF))
                    self.after(0, lambda: self._conn_lbl.configure(
                        text="AUTO-CONNECT FAILED", fg=LED_OFF))
            threading.Thread(target=_site_thread, daemon=True).start()

    def _save_config(self):
        cfg_module.save({
            'url':                self._url_var.get().strip(),
            'api_key':            self._api_key_var.get().strip(),
            'remember':           self._rem_var.get(),
            'default_category':    self._def_cat_var.get().strip(),
            'default_album':       self._def_alb_var.get().strip(),
            'default_orientation': self._def_orient_var.get().strip(),
            'last_image_folder':  self._folder_var.get().strip(),
            'last_manifest_file': self._manifest_var.get().strip(),
            'drive_enabled':      self._drive_enabled_var.get(),
            'google_credentials': self._goog_creds_var.get().strip(),
            'drive_folder_id':    self._drive_folder_var.get().strip(),
            'gemini_api_key':     self._gemini_key_var.get().strip(),
            'gemini_last_prompt': self._gem_prompt_txt.get('1.0', 'end').strip(),
            'copyright_text':     self._copyright_var.get().strip(),
        })
        self._update_ai_dot()

    def _update_ai_dot(self):
        """Reflect AI key presence in the status bar dot."""
        if self._gemini_key_var.get().strip():
            self._ai_dot.configure(fg=LED_OK)
            self._ai_lbl.configure(text="GEMINI READY", fg=LED_OK)
        else:
            self._ai_dot.configure(fg=LED_OFF)
            self._ai_lbl.configure(text="NO KEY", fg=LED_OFF)

    # ------------------------------------------------------------------
    # Browse
    # ------------------------------------------------------------------

    def _browse_folder(self):
        init = self._folder_var.get().strip()
        p = filedialog.askdirectory(parent=self.winfo_toplevel(), 
            title="Select image folder",
            initialdir=init if init and os.path.isdir(init) else None,
        )
        if p:
            self._folder_var.set(p)
            self._save_config()

    def _browse_manifest(self):
        init = self._manifest_var.get().strip()
        init_dir = os.path.dirname(init) if init and os.path.isfile(init) else None
        p = filedialog.askopenfilename(parent=self.winfo_toplevel(), 
            title="Select manifest file",
            initialdir=init_dir,
            filetypes=[("Text files", "*.txt"), ("All files", "*.*")],
        )
        if p:
            self._manifest_var.set(p)
            self._save_config()

    def _browse_creds(self):
        p = filedialog.askopenfilename(parent=self.winfo_toplevel(), 
            title="Select Google credentials.json",
            filetypes=[("JSON files", "*.json"), ("All files", "*.*")],
        )
        if p:
            self._goog_creds_var.set(p)
            self._save_config()

    def _on_drive_toggle(self):
        """Called when the Enable Google Drive checkbox is toggled."""
        enabled = self._drive_enabled_var.get()
        if enabled:
            # Re-show the normal not-connected state; auto-reconnect will
            # pick it up if credentials are already saved.
            self._drive_dot.configure(fg=LED_OFF)
            self._drive_lbl.configure(text="NOT CONNECTED", fg=LED_OFF)
            self._auto_reconnect()
        else:
            self._drive_service = None
            self._drive_dot.configure(fg=LED_OFF)
            self._drive_lbl.configure(text="DISABLED", fg=LED_OFF)
        self._save_config()

    # ------------------------------------------------------------------
    # Google Drive auth
    # ------------------------------------------------------------------

    def _on_auth_drive(self):
        creds_path = self._goog_creds_var.get().strip()
        if not creds_path or not os.path.isfile(creds_path):
            messagebox.showerror(
                "No credentials file",
                "Select your Google credentials.json first.\n\n"
                "Get it from Google Cloud Console → APIs & Services → Credentials.",
            )
            return

        self._drive_dot.configure(fg=LED_WARN)
        self._drive_lbl.configure(text="OPENING BROWSER...", fg=LED_WARN)
        self._drive_btn.configure(state="disabled")
        self.update_idletasks()

        def auth_thread():
            try:
                service = drive_module.authenticate(creds_path)
                self._drive_service = service
                self.after(0, lambda: self._drive_dot.configure(fg=LED_OK))
                self.after(0, lambda: self._drive_lbl.configure(text="AUTHENTICATED", fg=LED_OK))
                self.after(0, lambda: self._set_status("Google Drive connected.", FG_OK))
                self.after(0, self._save_config)
            except Exception as e:
                self.after(0, lambda: self._drive_dot.configure(fg=LED_ERR))
                self.after(0, lambda: self._drive_lbl.configure(text="AUTH FAILED", fg=LED_ERR))
                self.after(0, lambda: messagebox.showerror("Drive auth failed", str(e)))
            finally:
                self.after(0, lambda: self._drive_btn.configure(state="normal"))

        threading.Thread(target=auth_thread, daemon=True).start()

    # ------------------------------------------------------------------
    # Connect
    # ------------------------------------------------------------------

    def _on_connect(self):
        url = self._url_var.get().strip()
        key = self._api_key_var.get().strip()

        if not url or not key:
            messagebox.showerror("Missing credentials", "Fill in Site URL and API Key.")
            return

        self._set_status("Connecting…", FG_WARN)
        self._conn_dot.configure(fg=LED_WARN)
        self._conn_lbl.configure(text="CONNECTING...", fg=LED_WARN)
        self.update_idletasks()

        try:
            client    = SnapSmackClient(url, api_key=key)
            client.verify()
            site_data = client.fetch_site_data()

            self._client    = client
            self._site_data = site_data

            cats   = sorted(site_data._cat_display.values())
            albums = sorted(site_data._album_display.values())

            self._def_cat_cb['values'] = [''] + cats
            self._def_alb_cb['values'] = [''] + albums
            self._entry_list.update_combos(cats, albums)

            self._conn_dot.configure(fg=LED_OK)
            self._conn_lbl.configure(text=f"CONNECTED  {len(cats)} CATS  {len(albums)} ALBUMS", fg=LED_OK)
            self._set_status("Connected. Load a manifest to begin.", FG_OK)
            self._save_config()

        except Exception as e:
            self._conn_dot.configure(fg=LED_ERR)
            self._conn_lbl.configure(text="CONNECTION FAILED", fg=LED_ERR)
            self._set_status(f"Error: {e}", FG_ERR)
            messagebox.showerror("Connection failed", str(e))

    # ------------------------------------------------------------------
    # Load manifest
    # ------------------------------------------------------------------

    def _on_load(self):
        manifest_path = self._manifest_var.get().strip()
        image_folder  = self._folder_var.get().strip()

        if not manifest_path:
            messagebox.showerror("No manifest", "Select a manifest file.")
            return
        if not image_folder:
            messagebox.showerror("No folder", "Select an image folder.")
            return

        parse_result = manifest_parser.parse(manifest_path)
        for err in parse_result.errors:
            self._set_status(f"Parse error: {err}", FG_ERR)

        if not parse_result.entries:
            messagebox.showerror("Empty manifest", "No valid entries found.")
            return

        cats   = sorted(self._site_data._cat_display.values()) if self._site_data else []
        albums = sorted(self._site_data._album_display.values()) if self._site_data else []

        # Offer to restore saved enrichment for this folder before building rows.
        self._maybe_resume(parse_result.entries, image_folder)

        self._entry_list.load(parse_result.entries, image_folder, cats, albums)
        self._mark_restored_rows()
        self._sel_all_var.set(True)
        total = len(self._entry_list.get_entries())
        added = len(parse_result.entries)
        self._progress['maximum'] = total
        self._prog_var.set(0)
        self._prog_lbl.configure(text=f"0 / {total}")
        self._queue_lbl.configure(text=f"QUEUE — {total} ITEM{'S' if total != 1 else ''}")
        self._set_status(f"+{added} added — {total} total. Drag to reorder.", FG_MAIN)
        self._save_config()
        if self._cfg_visible:
            self._toggle_cfg()

    def _on_scan_folder(self):
        """Populate the queue from image files in the image folder — no manifest needed."""
        image_folder = self._folder_var.get().strip()
        if not image_folder:
            messagebox.showerror("No folder", "Select an image folder first.")
            return
        if not os.path.isdir(image_folder):
            messagebox.showerror("Folder not found", f"Cannot find:\n{image_folder}")
            return

        EXTS = {'.jpg', '.jpeg', '.png', '.webp'}
        files = sorted(
            f for f in os.listdir(image_folder)
            if os.path.splitext(f)[1].lower() in EXTS
        )

        if not files:
            messagebox.showinfo("Nothing found", "No JPG / PNG / WebP files found in that folder.")
            return

        default_cat    = self._def_cat_cb.get()  if hasattr(self, '_def_cat_cb')    else ''
        default_album  = self._def_album_cb.get() if hasattr(self, '_def_album_cb')  else ''
        default_orient = self._def_orient_cb.get() if hasattr(self, '_def_orient_cb') else 'auto'

        entries = []
        for fname in files:
            e = manifest_parser.ManifestEntry()
            e.file        = fname
            e.category    = default_cat
            e.album       = default_album
            e.orientation = default_orient
            entries.append(e)

        cats   = sorted(self._site_data._cat_display.values()) if self._site_data else []
        albums = sorted(self._site_data._album_display.values()) if self._site_data else []

        # Offer to restore saved enrichment for this folder before building rows.
        self._maybe_resume(entries, image_folder)

        self._entry_list.load(entries, image_folder, cats, albums)
        self._mark_restored_rows()
        self._sel_all_var.set(True)
        total = len(entries)
        self._progress['maximum'] = total
        self._prog_var.set(0)
        self._prog_lbl.configure(text=f"0 / {total}")
        self._queue_lbl.configure(text=f"QUEUE — {total} ITEM{'S' if total != 1 else ''}")
        self._set_status(f"Scanned {total} image{'s' if total != 1 else ''} from folder. Enrich with Gemini or edit manually.", FG_MAIN)
        self._save_config()
        if self._cfg_visible:
            self._toggle_cfg()

    # ------------------------------------------------------------------
    # Gemini Enrich
    # ------------------------------------------------------------------

    def _refresh_preset_dropdown(self):
        names = sorted(self._gem_prompts.keys())
        self._gem_preset_cb['values'] = names
        if names and self._gem_preset_cb.get() not in names:
            self._gem_preset_cb.set('')

    def _on_gem_preset_load(self, _event=None):
        name = self._gem_preset_cb.get()
        if name and name in self._gem_prompts:
            self._gem_prompt_txt.delete('1.0', 'end')
            self._gem_prompt_txt.insert('1.0', self._gem_prompts[name])

    def _on_gem_preset_save(self):
        current = self._gem_prompt_txt.get('1.0', 'end').strip()
        if not current:
            messagebox.showerror("Empty prompt", "Write a prompt before saving it as a preset.")
            return
        name = tk.simpledialog.askstring("Save Preset", "Preset name:", parent=self)
        if not name:
            return
        name = name.strip()
        if not name:
            return
        self._gem_prompts[name] = current
        cfg_module.save_prompts(self._gem_prompts)
        self._refresh_preset_dropdown()
        self._gem_preset_cb.set(name)

    def _on_gem_preset_delete(self):
        name = self._gem_preset_cb.get()
        if not name or name not in self._gem_prompts:
            return
        if not messagebox.askyesno("Delete Preset", f"Delete preset \"{name}\"?"):
            return
        del self._gem_prompts[name]
        cfg_module.save_prompts(self._gem_prompts)
        self._refresh_preset_dropdown()

    def _on_gemini_test(self):
        api_key = self._gemini_key_var.get().strip()
        if not api_key:
            self._gem_test_lbl.configure(text="No key entered.", fg=FG_ERR)
            return
        if not gemini_module.is_available():
            self._gem_test_lbl.configure(text="Library not installed.", fg=FG_ERR)
            return
        self._gem_test_btn.configure(state="disabled")
        self._gem_test_lbl.configure(text="Testing…", fg=FG_WARN)

        def _test_thread():
            ok, msg = gemini_module.test_connection(api_key)
            def _update():
                self._gem_test_btn.configure(state="normal")
                self._gem_test_lbl.configure(text=msg, fg=FG_OK if ok else FG_ERR)
                self._save_config()
            self.after(0, _update)

        threading.Thread(target=_test_thread, daemon=True).start()

    def _on_enrich(self):
        api_key = self._gemini_key_var.get().strip()
        if not api_key:
            messagebox.showerror("No API Key", "Enter your Gemini API key in the configuration panel.")
            return

        if not self._entry_list.get_entries():
            messagebox.showerror("Nothing loaded", "Load images or a manifest first.")
            return
        entries = self._entry_list.get_selected_entries()
        if not entries:
            messagebox.showerror("Nothing selected",
                                 "Tick at least one image to enrich (or use Select all).")
            return

        image_folder = self._folder_var.get().strip()
        if not image_folder:
            messagebox.showerror("No folder", "Select an image folder first.")
            return

        # Recovery store for this folder — each enriched item is saved as it lands.
        self._ensure_recovery(image_folder)

        if not gemini_module.is_available():
            messagebox.showerror(
                "Missing library",
                "google-generativeai is not installed.\n\nRun: pip install google-generativeai",
            )
            return

        site_data     = self._site_data
        cats          = list(site_data._cat_display.values())   if site_data else []
        albums        = list(site_data._album_display.values()) if site_data else []
        custom_prompt = self._gem_prompt_txt.get("1.0", "end").strip()

        self._enrich_btn.configure(state="disabled")
        self._bottom_enrich_canvas.configure(cursor="")
        self._bottom_enrich_canvas.unbind("<Button-1>")
        self._bottom_enrich_canvas.itemconfig(self._bottom_enrich_rect, fill="#1A1A1A")
        self._bottom_enrich_canvas.itemconfig(self._bottom_enrich_text, fill=FG_DIM)
        self._set_status(f"Enriching with Gemini — 0 / {len(entries)}…", FG_WARN)

        def _enrich_thread():
            def _progress(idx, total, entry, error):
                def _ui_update():
                    if error:
                        self._set_status(
                            f"Gemini: {entry.file} — {error}", FG_ERR)
                    else:
                        # Locate the row by entry identity — robust even when only
                        # a selected subset of the queue is being enriched.
                        row = self._entry_list.row_for_entry(entry)
                        if row:
                            row.fill_from_ai(
                                title=entry.title,
                                tags=entry.tags,
                                category=entry.category,
                                album=entry.album,
                                colors=entry.colors,
                            )
                            row.set_status('enriched')
                        # Persist this item's enrichment to disk the moment it lands
                        # so a later crash/hang/close can't lose the Gemini spend.
                        if self._recovery:
                            try:
                                self._recovery.upsert(entry, 'enriched')
                            except Exception:
                                pass
                        self._set_status(
                            f"Gemini: enriched {idx} / {total} — {entry.file}", FG_OK)
                self.after(0, _ui_update)

            gemini_module.enrich_batch(
                api_key=api_key,
                entries=entries,
                image_folder=image_folder,
                categories=cats,
                albums=albums,
                on_progress=_progress,
                skip_filled=True,
                custom_prompt=custom_prompt,
                cat_descriptions=self._site_data.cat_descriptions if self._site_data else None,
                album_descriptions=self._site_data.album_descriptions if self._site_data else None,
                existing_tags=self._site_data.tags if self._site_data else None,
                existing_titles=self._site_data.titles if self._site_data else None,
            )

            def _done():
                self._enrich_btn.configure(state="normal")
                self._bottom_enrich_canvas.configure(cursor="hand2")
                self._bottom_enrich_canvas.bind("<Button-1>", lambda e: self._on_enrich())
                self._bottom_enrich_canvas.itemconfig(self._bottom_enrich_rect, fill="#0D2B3E")
                self._bottom_enrich_canvas.itemconfig(self._bottom_enrich_text, fill="#00BFFF")
                self._set_status("Gemini enrichment complete. Review and post.", FG_OK)
                self._save_config()
            self.after(0, _done)

        threading.Thread(target=_enrich_thread, daemon=True).start()

    # ------------------------------------------------------------------
    # Clear queue
    # ------------------------------------------------------------------

    def _on_clear(self):
        if not self._entry_list.get_entries():
            return
        self._entry_list.clear()
        self._progress['maximum'] = 1
        self._prog_var.set(0)
        self._prog_lbl.configure(text="")
        self._queue_lbl.configure(text="QUEUE — 0 ITEMS")
        self._set_status("Queue cleared. Load a manifest to begin.", FG_DIM)
        if not self._cfg_visible:
            self._toggle_cfg()

    # ------------------------------------------------------------------
    # Help
    # ------------------------------------------------------------------

    def _show_help(self):
        """Open the in-app help window."""
        win = tk.Toplevel(self)
        win.title("Smack Your Batch Up — Help")
        win.geometry("720x620")
        win.minsize(560, 400)
        win.configure(bg=BG_DEEP)
        win.grab_set()

        # Header
        hdr = tk.Frame(win, bg=BG_CARD, height=44)
        hdr.pack(fill="x")
        hdr.pack_propagate(False)
        tk.Label(hdr, text="SMACK YOUR BATCH UP  —  USER GUIDE",
                 bg=BG_CARD, fg=ACCENT, font=FONT_TITLE).pack(side="left", padx=16, anchor="center")
        ttk.Button(hdr, text="Close", style="Ghost.TButton",
                   command=win.destroy).pack(side="right", padx=14, pady=6)

        tk.Frame(win, bg=BORDER, height=1).pack(fill="x")

        # Scrollable content
        canvas  = tk.Canvas(win, bg=BG_DEEP, highlightthickness=0)
        scrollb = ttk.Scrollbar(win, orient="vertical", command=canvas.yview)
        canvas.configure(yscrollcommand=scrollb.set)
        scrollb.pack(side="right", fill="y")
        canvas.pack(side="left", fill="both", expand=True)

        body = tk.Frame(canvas, bg=BG_DEEP)
        body_id = canvas.create_window((0, 0), window=body, anchor="nw")

        def _on_resize(e):
            canvas.itemconfig(body_id, width=e.width)
        canvas.bind("<Configure>", _on_resize)
        body.bind("<Configure>", lambda e: canvas.configure(
            scrollregion=canvas.bbox("all")))
        canvas.bind_all("<MouseWheel>",
            lambda e: canvas.yview_scroll(int(-1 * (e.delta / 120)), "units"))

        def _section(title):
            tk.Frame(body, bg=BORDER, height=1).pack(fill="x", padx=20, pady=(18, 0))
            tk.Label(body, text=title, bg=BG_DEEP, fg=ACCENT,
                     font=FONT_BOLD, anchor="w").pack(fill="x", padx=20, pady=(6, 2))

        def _para(text):
            tk.Label(body, text=text, bg=BG_DEEP, fg=FG_MAIN,
                     font=FONT_UI, wraplength=640, justify="left",
                     anchor="w").pack(fill="x", padx=20, pady=(2, 0))

        def _item(label, detail):
            row = tk.Frame(body, bg=BG_DEEP)
            row.pack(fill="x", padx=28, pady=(3, 0))
            tk.Label(row, text=f"▸  {label}", bg=BG_DEEP, fg=FG_OK,
                     font=FONT_BOLD, anchor="w", width=22).pack(side="left", anchor="n")
            tk.Label(row, text=detail, bg=BG_DEEP, fg=FG_MAIN,
                     font=FONT_UI, wraplength=480, justify="left",
                     anchor="w").pack(side="left", fill="x", expand=True)

        # ── Content ────────────────────────────────────────────────────
        tk.Label(body, text="", bg=BG_DEEP).pack()   # top padding

        _section("OVERVIEW")
        _para("Smack Your Batch Up posts large batches of images to a SnapSmack site. "
              "Connect to your site, point it at a folder of images, let Gemini AI write "
              "the titles and tags, then post everything in one click.")

        _section("STATUS BAR")
        _para("The three panels at the top tell you everything important at a glance.")
        _item("SITE CONNECTION", "Green = connected and session active. The countdown "
              "shows how long your login session has left. It turns amber at 10 minutes, "
              "red at 2 minutes, and flashes at 5 minutes. If it expires, click Connect again — "
              "your queue is not lost.")
        _item("CLOUD DRIVE", "Shows whether Google Drive authentication is active. "
              "Required only if you want download links attached to posts.")
        _item("AI ENGINE", "Green = a Gemini API key is saved and ready. "
              "Without a key the Enrich button still appears but will ask for one.")

        _section("BASIC WORKFLOW")
        _item("1.  Connect", "Enter your site URL and API Key (generated in SnapSmack Admin → Settings → API Access), then click Connect. "
              "SYBU logs in and loads your categories and albums.")
        _item("2.  Set image folder", "Click … next to Image Folder and pick the folder "
              "containing your images.")
        _item("3.  Scan Folder", "Click Scan Folder to load every JPG / PNG / WebP in the "
              "folder into the queue. Default category, album, and orientation are applied "
              "to all rows.")
        _item("4.  Enrich with Gemini", "Gemini looks at each image and fills in a title, "
              "tags, category, and album. Rows that already have a title are skipped. "
              "Edit any row by clicking its fields directly.")
        _item("5.  Post Batch", "Validates then posts every item in the queue. Progress is "
              "shown row by row. Failed posts stay red so you can retry.")

        _section("LOAD MANIFEST (ADVANCED)")
        _para("A manifest is a plain text file listing images with optional pre-filled metadata. "
              "Use Load Manifest instead of Scan Folder if you have one. "
              "Each entry block looks like:")
        tk.Label(body,
                 text="FILE: photo.jpg\nTITLE: Dark stone holds the rain\n"
                      "TAGS: #stone #texture #macro\nCATEGORY: Feeling Knotty\nALBUM: Morning Wood",
                 bg=BG_MID, fg=FG_OK, font=FONT_MONO,
                 justify="left", anchor="w", padx=12, pady=8
                 ).pack(fill="x", padx=28, pady=(6, 0))

        _section("GEMINI AI")
        _item("API Key", "Paste your Gemini API key in the config panel. Click Test Connection "
              "to verify it works. The key is saved locally in config.ini next to the exe.")
        _item("Custom Prompt", "Leave the prompt blank to use the built-in default, which "
              "generates haiku-style titles and descriptive hashtags. Write your own prompt "
              "to change the tone, style, or output format.")
        _item("Saved Presets", "Type a name and click Save As… to store a prompt for reuse. "
              "Delete removes the selected preset.")
        _item("Skip Filled", "Gemini skips any row that already has a title, so you can "
              "run enrichment multiple times without overwriting manual edits.")

        _section("GOOGLE DRIVE")
        _para("Google Drive support adds a public download link to each post. "
              "You need a credentials.json file from Google Cloud Console.")
        _item("Credentials file", "Download from Google Cloud Console → APIs & Services → "
              "Credentials → your OAuth 2.0 client → Download JSON.")
        _item("Folder ID", "The ID at the end of your Google Drive folder URL. "
              "e.g. drive.google.com/drive/folders/1pztAN2j… → copy the last segment.")
        _item("Auth Drive", "Opens a browser tab for OAuth consent. Once approved, "
              "credentials are cached and you won't need to re-auth unless revoked.")

        _section("SESSION MANAGEMENT")
        _para("The SITE panel shows a countdown from 48 minutes — that's the PHP session "
              "window on the server. SYBU pings the server every 10 minutes to keep it "
              "alive, so the countdown resets automatically and the session stays open "
              "indefinitely. If you lose network and the session expires, just click "
              "Connect again — the queue, settings, and credentials are all preserved.")

        _section("SETTINGS & CONFIG")
        _para("All settings are saved automatically to config.ini next to the exe. "
              "Gemini prompts are stored in gemini_prompts.json in the same folder. "
              "You can delete either file to reset to defaults.")

        tk.Label(body, text="", bg=BG_DEEP).pack()   # bottom padding

    # ------------------------------------------------------------------
    # Validate
    # ------------------------------------------------------------------

    def _on_validate(self):
        if not self._ensure_connected():
            return

        entries = self._entry_list.get_entries()
        if not entries:
            messagebox.showerror("Nothing loaded", "Load a manifest first.")
            return

        cats   = list(self._site_data._cat_display.values())
        albums = list(self._site_data._album_display.values())

        issues = manifest_parser.validate(
            entries=entries,
            image_folder=self._folder_var.get().strip(),
            known_categories=cats,
            known_albums=albums,
            default_category=self._def_cat_var.get().strip(),
            default_album=self._def_alb_var.get().strip(),
        )

        if not issues:
            self._set_status(f"✓ All {len(entries)} entries look good.", FG_OK)
            messagebox.showinfo("Validation passed",
                                f"All {len(entries)} entries validated OK.")
        else:
            msgs = []
            for entry, warnings in issues:
                for w in warnings:
                    msgs.append(f"• {entry.file}: {w}")
            self._set_status(f"{len(issues)} entries have issues — see dialog.", FG_WARN)
            messagebox.showwarning(
                "Validation issues",
                "\n".join(msgs[:20]) + (f"\n…and {len(msgs) - 20} more" if len(msgs) > 20 else ""),
            )

    # ------------------------------------------------------------------
    # Post batch
    # ------------------------------------------------------------------

    def _on_post(self):
        if self._posting:
            return
        if not self._ensure_connected():
            return

        if not self._entry_list.get_entries():
            messagebox.showerror("Nothing loaded", "Load a manifest first.")
            return
        entries = self._entry_list.get_selected_entries()
        if not entries:
            messagebox.showerror("Nothing selected",
                                 "Tick at least one image to post (or use Select all).")
            return

        # ── Warn if Drive is enabled but not connected ─────────────────
        if self._drive_enabled_var.get() and self._drive_service is None:
            proceed = messagebox.askyesno(
                "Google Drive not connected",
                "⚠  Google Drive is NOT connected.\n\n"
                "Images will be posted WITHOUT download links.\n"
                "You will need to upload them to Drive manually later.\n\n"
                "Are you sure you want to continue without Drive?",
                icon="warning",
            )
            if not proceed:
                return

        count = len(entries)
        total_in_queue = len(self._entry_list.get_entries())
        subset_note = ("" if count == total_in_queue
                       else f"  (of {total_in_queue} in the queue)")
        if not messagebox.askyesno(
            "Confirm post",
            f"Post {count} selected image{'s' if count != 1 else ''}{subset_note} to SnapSmack?\n\n"
            "They'll appear in the archive in the order shown.",
        ):
            return

        # Recovery store for this folder — posted items are marked as they go.
        self._ensure_recovery(self._folder_var.get().strip())
        self._cancel_evt.clear()
        self._set_posting(True)
        self._prog_var.set(0)
        self._progress['maximum'] = count
        self._prog_lbl.configure(text=f"0 / {count}")
        self._set_status("Posting…", FG_WARN)
        self._save_config()

        thread = threading.Thread(
            target=self._post_thread,
            args=(entries, self._folder_var.get().strip(), count),
            daemon=True,
        )
        thread.start()

    def _post_thread(self, entries, image_folder, total):
        def on_progress(current, total, result):
            self._msg_queue.put(('progress', current, total, result))

        # Map display label to API value for orientation
        orient_map = {'auto': 'auto', 'landscape': '0', 'portrait': '1', 'square': '2'}
        orient_val = orient_map.get(self._def_orient_var.get().strip().lower(), 'auto')

        results = poster_module.run_batch(
            client=self._client,
            entries=entries,
            image_folder=image_folder,
            site_data=self._site_data,
            default_category=self._def_cat_var.get().strip(),
            default_album=self._def_alb_var.get().strip(),
            default_orientation=orient_val,
            on_progress=on_progress,
            drive_service=self._drive_service,
            drive_folder_id=self._drive_folder_var.get().strip(),
            copyright_text=self._copyright_var.get().strip(),
            cancel_event=self._cancel_evt,
        )
        cancelled = self._cancel_evt.is_set()
        self._msg_queue.put(('done', len(results), cancelled))

    def _poll_queue(self):
        try:
            while True:
                msg = self._msg_queue.get_nowait()
                if msg[0] == 'progress':
                    _, current, total, result = msg
                    self._prog_var.set(current)
                    self._prog_lbl.configure(text=f"{current} / {total}")

                    # Badge the row by entry identity (not index) so a selected
                    # subset still lights up the correct rows.
                    row = self._entry_list.row_for_entry(result.entry)
                    if result.success:
                        row_status = 'ok' if result.exif_ok else 'warning'
                    else:
                        row_status = 'error'
                    if row:
                        row.set_status(row_status, result.message)

                    # Mark the item posted in the recovery file as it succeeds.
                    if result.success and self._recovery:
                        try:
                            self._recovery.mark_status(result.entry, 'ok')
                        except Exception:
                            pass

                    row_color = FG_OK if result.success and result.exif_ok else (FG_WARN if result.success else FG_ERR)
                    self._set_status(
                        f"{'✓' if result.success else '✗'}  {result.entry.file} — {result.message}",
                        row_color,
                    )

                elif msg[0] == 'done':
                    processed = msg[1]
                    cancelled = msg[2] if len(msg) > 2 else False
                    self._set_posting(False)
                    if cancelled:
                        self._set_status(
                            f"Cancelled — {processed} posted before stop. "
                            "The rest stay in the queue.", FG_WARN)
                    else:
                        self._set_status(f"Batch complete — {processed} processed.", FG_OK)
                        # Whole batch posted → recovery file no longer needed.
                        if self._recovery:
                            try:
                                if self._recovery.all_posted():
                                    self._recovery.delete()
                                    self._recovery = None
                            except Exception:
                                pass
                    if not self._cfg_visible:
                        self._toggle_cfg()

                elif msg[0] == 'session_renewed':
                    # Keepalive ping succeeded (or silent re-login worked) —
                    # reset the session countdown so it reflects the renewed lifetime.
                    self._conn_dot.configure(fg=LED_OK)

                elif msg[0] == 'session_renewal_failed':
                    # Keepalive and re-login both failed.  The next post_image
                    # call will fail and surface the error in the queue normally;
                    # flag the session indicator so the user knows what happened.
                    self._conn_lbl.configure(
                        text="SESSION RENEWAL FAILED", fg=LED_ERR
                    )
                    self._conn_dot.configure(fg=LED_ERR)
        except queue.Empty:
            pass
        self.after(100, self._poll_queue)

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------

    def _ensure_connected(self) -> bool:
        # API-key auth (0.7.9e+) has NO server session to check. The old code
        # here called self._client.is_session_alive(), a method deleted in the
        # API-key migration — it raised AttributeError and froze POST forever on
        # "Checking session…" (the 0.7.9j hang). We only need a client + site data.
        if not self._client or not self._site_data:
            messagebox.showinfo("Not connected",
                                "Click Connect first and enter your credentials.")
            return False
        return True

    # ------------------------------------------------------------------
    # Session countdown timer
    # ------------------------------------------------------------------
    SESSION_LIFETIME = 2880  # 48 minutes in seconds (matches PHP gc_maxlifetime)

    def _start_session_timer(self):
        """Start or restart the session countdown from full lifetime."""
        if self._session_timer_id:
            self.after_cancel(self._session_timer_id)
        self._session_remaining = self.SESSION_LIFETIME
        self._tick_session_timer()

    def _stop_session_timer(self):
        """Stop the timer and clear the label."""
        if self._session_timer_id:
            self.after_cancel(self._session_timer_id)
            self._session_timer_id = None
        self._stop_conn_flash()
        self._session_remaining = 0
        self._session_timer_lbl.configure(text="--:--", fg=LED_OFF)

    def _tick_session_timer(self):
        """Called every second. Updates the countdown label and colour."""
        s = self._session_remaining
        if s <= 0:
            self._stop_conn_flash()
            self._session_timer_lbl.configure(text="EXPIRED", fg=LED_ERR)
            self._conn_dot.configure(fg=LED_ERR)
            self._conn_lbl.configure(text="SESSION EXPIRED", fg=LED_ERR)
            messagebox.showwarning(
                "Session Expired",
                "Your login session has timed out on the server.\n\n"
                "Click Connect to log in again. Your queue is still here."
            )
            return

        mins, secs = divmod(s, 60)
        self._session_timer_lbl.configure(text=f"{mins:02d}:{secs:02d}")

        # Colour thresholds on timer label: green > 10min, amber ≤ 10min, red ≤ 2min
        if s <= 120:
            self._session_timer_lbl.configure(fg=LED_ERR)
        elif s <= 600:
            self._session_timer_lbl.configure(fg=LED_WARN)
        else:
            self._session_timer_lbl.configure(fg=LED_OK)

        # Flash the site dot amber when ≤ 5 minutes
        if s <= 300 and not self._conn_flash_id:
            self._start_conn_flash()
        elif s > 300 and self._conn_flash_id:
            self._stop_conn_flash()
            self._conn_dot.configure(fg=LED_OK)

        self._session_remaining -= 1
        self._session_timer_id = self.after(1000, self._tick_session_timer)

    def _start_conn_flash(self):
        """Begin flashing the site dot yellow."""
        self._conn_flash_state = False
        self._conn_flash_tick()

    def _conn_flash_tick(self):
        self._conn_flash_state = not self._conn_flash_state
        self._conn_dot.configure(fg=LED_WARN if self._conn_flash_state else BG_SBAR)
        self._conn_flash_id = self.after(500, self._conn_flash_tick)

    def _stop_conn_flash(self):
        if self._conn_flash_id:
            self.after_cancel(self._conn_flash_id)
            self._conn_flash_id = None

    # ------------------------------------------------------------------
    # Session keepalive
    # ------------------------------------------------------------------
    KEEPALIVE_INTERVAL = 600  # Ping every 10 minutes — well inside PHP's 48-min window

    def _start_keepalive(self):
        """Start background keepalive thread. Idempotent — no-op if already running."""
        if self._keepalive_running:
            return
        self._keepalive_running = True
        t = threading.Thread(target=self._keepalive_worker, daemon=True)
        t.start()

    def _stop_keepalive(self):
        """Signal the keepalive thread to exit on its next tick."""
        self._keepalive_running = False

    def _keepalive_worker(self):
        """
        Runs in a background thread while a batch is in progress.
        Every KEEPALIVE_INTERVAL seconds it calls client.keepalive(), which
        hits smack-admin.php to extend the PHP session.  If the session has
        already expired it silently re-logs in with stored credentials.
        Posts the result back to the UI via _msg_queue so the countdown
        timer can be reset without touching tkinter from this thread.
        """
        import time
        elapsed = 0
        while self._keepalive_running:
            time.sleep(1)
            elapsed += 1
            if elapsed >= self.KEEPALIVE_INTERVAL:
                elapsed = 0
                client = self._client
                if client:
                    renewed = client.keepalive()
                    self._msg_queue.put(
                        ('session_renewed',) if renewed else ('session_renewal_failed',)
                    )

    def _set_status(self, text: str, color: str = FG_DIM):
        self._status_lbl.configure(text=text, fg=color)

    # ------------------------------------------------------------------
    # Selection
    # ------------------------------------------------------------------

    def _on_select_all(self):
        """Master checkbox in the queue header → tick/untick every row."""
        self._entry_list.set_all_selected(self._sel_all_var.get())

    # ------------------------------------------------------------------
    # Recovery (incremental enrichment save + resume)
    # ------------------------------------------------------------------

    def _ensure_recovery(self, image_folder: str):
        """Return the active RecoveryStore for image_folder, creating/loading it
        if the folder changed. Never raises."""
        if not image_folder:
            return self._recovery
        try:
            same = (self._recovery is not None and
                    os.path.normcase(os.path.abspath(self._recovery.image_folder))
                    == os.path.normcase(os.path.abspath(image_folder)))
            if not same:
                store = recovery_module.RecoveryStore(image_folder)
                if store.exists():
                    store.load()
                self._recovery = store
        except Exception:
            pass
        return self._recovery

    def _maybe_resume(self, entries, image_folder: str):
        """If a recovery file exists for this folder, offer to restore enrichment
        into `entries` (in place) and skip re-enriching them. Sets self._recovery."""
        try:
            store = recovery_module.RecoveryStore(image_folder)
        except Exception:
            self._recovery = None
            return
        if store.exists():
            store.load()
            have = store.enriched_count()
            if have and messagebox.askyesno(
                "Resume enrichment",
                f"Saved enrichment was found for this folder:\n\n"
                f"    {have} of {len(entries)} item(s) already enriched.\n\n"
                "Restore it and skip re-enriching those?\n"
                "(This is recovered from a previous run — it saves Gemini cost.)",
            ):
                restored = store.restore_into(entries)
                self._set_status(
                    f"Recovered {restored} enriched item(s) from disk.", FG_OK)
            elif have:
                # Declined — start fresh; next enrich overwrites the old file.
                store = recovery_module.RecoveryStore(image_folder)
        self._recovery = store

    def _mark_restored_rows(self):
        """After load, badge any rows whose enrichment was restored from disk."""
        if not self._recovery:
            return
        for i in range(len(self._entry_list.get_entries())):
            row = self._entry_list.get_row(i)
            if not row:
                continue
            rec = self._recovery.lookup(row.entry)
            if rec and (rec.get('title') or rec.get('tags')):
                row.set_status('ok' if rec.get('status') == 'ok' else 'enriched')

    # ------------------------------------------------------------------
    # Cancel a running POST
    # ------------------------------------------------------------------

    def _on_cancel_post(self):
        if not self._posting:
            return
        self._cancel_evt.set()
        self._post_canvas.itemconfig(self._post_text, text="CANCELLING…")
        self._post_canvas.unbind("<Button-1>")
        self._post_canvas.configure(cursor="")
        self._set_status("Cancelling after the current image finishes…", FG_WARN)

    def _post_hover(self, on: bool):
        """Hover lighten for the POST button — suppressed while posting so the
        red CANCEL state isn't wiped back to green."""
        if self._posting:
            return
        self._post_canvas.itemconfig(
            self._post_rect, fill=self._lighten(ACCENT) if on else ACCENT)

    def _set_posting(self, posting: bool):
        self._posting = posting
        # The POST button is a Canvas. While posting it becomes a red CANCEL
        # button; otherwise it's the green POST BATCH button.
        self._post_canvas.unbind("<Button-1>")
        if posting:
            self._post_canvas.itemconfig(self._post_text, text="CANCEL", fill="#FFFFFF")
            self._post_canvas.itemconfig(self._post_rect, fill=FG_ERR)
            self._post_canvas.configure(cursor="hand2")
            self._post_canvas.bind("<Button-1>", lambda e: self._on_cancel_post())
        else:
            self._post_canvas.itemconfig(self._post_text, text="POST BATCH", fill="#000000")
            self._post_canvas.itemconfig(self._post_rect, fill=ACCENT)
            self._post_canvas.configure(cursor="hand2")
            self._post_canvas.bind("<Button-1>", lambda e: self._on_post())


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    app = App()
    app.mainloop()
# ===== SNAPSMACK EOF =====
