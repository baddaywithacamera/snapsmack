"""
Unzucker — main.py
Instagram export migration tool for SnapSmack (The Grid / Carousel mode).

Desktop app with an Instagram-style 3-column square-thumbnail grid.
Click a cell to see post details (all carousel images + the single
shared caption). Transfer & Post uploads images via HTTPS then
creates posts through the SnapSmack admin API. No FTP required.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


BUILD_VERSION = "0.7.38"

import logging
import logging.handlers
import os
import queue
import sys
import tempfile
import threading
import tkinter as tk
from dataclasses import dataclass, field
from tkinter import filedialog, messagebox, ttk
from typing import Dict, List, Optional, Set

from PIL import Image, ImageTk

import config as cfg_module
import ig_parser
import job_state
import poster as poster_module
from ig_parser import ParsedPost
from poster import UnzuckerClient, SiteData

# ---------------------------------------------------------------------------
# Logging — rotating daily, 7-day retention, %APPDATA%\Unzucker\unzucker.log
# ---------------------------------------------------------------------------

if getattr(sys, 'frozen', False):
    # Running as compiled exe — log sits next to the exe
    _LOG_DIR = os.path.dirname(sys.executable)
else:
    # Running from source
    _LOG_DIR = os.path.dirname(os.path.abspath(__file__))
_LOG_FILE = os.path.join(_LOG_DIR, 'unzucker.log')
os.makedirs(_LOG_DIR, exist_ok=True)

_log_handler = logging.handlers.TimedRotatingFileHandler(
    _LOG_FILE, when='D', interval=1, backupCount=7, encoding='utf-8',
)
_log_handler.setFormatter(logging.Formatter(
    '%(asctime)s  %(levelname)-8s  %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S',
))
log = logging.getLogger('unzucker')
log.setLevel(logging.DEBUG)
log.addHandler(_log_handler)

def _excepthook(exc_type, exc_value, exc_tb):
    if not issubclass(exc_type, KeyboardInterrupt):
        log.critical('Unhandled exception', exc_info=(exc_type, exc_value, exc_tb))
    sys.__excepthook__(exc_type, exc_value, exc_tb)

sys.excepthook = _excepthook

# Surface keyring availability for the UI indicator
_KEYRING_OK = cfg_module.has_keyring()


# ---------------------------------------------------------------------------
# Colour palette & typography (matches batch poster)
# ---------------------------------------------------------------------------

BG_DEEP   = "#141414"   # Midnight Lime
BG_CARD   = "#1C1C1C"
BG_MID    = "#050505"
BG_HOVER  = "#252525"
ACCENT    = "#39FF14"   # neon lime
ACCENT2   = "#2ECC10"
BORDER    = "#2A2A2A"

FG_MAIN   = "#EEEEEE"
FG_DIM    = "#777777"
FG_OK     = "#4EC994"
FG_ERR    = "#FF3E3E"
FG_WARN   = "#D4872A"

THUMB_GRID = 160       # px per grid cell (square)
THUMB_DETAIL = 80      # px per detail thumbnail
GRID_COLS  = 3         # Strictly three across. Always.
GRID_GAP   = 3         # px between cells

WIN_W, WIN_H = 560, 780
FONT_UI      = ("Segoe UI", 15)
FONT_BOLD    = ("Segoe UI", 15, "bold")
FONT_SMALL   = ("Segoe UI", 13)
FONT_MONO    = ("Consolas", 13)
FONT_TITLE   = ("Segoe UI", 19, "bold")

# Server throttle options — (label, delay_seconds_as_str)
# Shown as radio buttons (2 rows × 4 cols) in IMPORT SETTINGS.
THROTTLE_OPTIONS = [
    ("Full Send",       "0.0"),   # no delay — localhost / VPS only
    ("Fast Lane",       "0.25"),  # 0.25s
    ("Steady",          "0.5"),   # 0.5s  ← default
    ("Easy Does It",    "1.0"),   # 1s
    ("Sunday Driver",   "2.0"),   # 2s
    ("Pump da Brakes",  "5.0"),   # 5s
    ("Grandma's Pace",  "10.0"),  # 10s  — very stressed shared host
    ("Geological Time", "30.0"),  # 30s  — barely-alive host
]


# ---------------------------------------------------------------------------
# Per-cell logical state (persists across scroll; no widget attached)
# ---------------------------------------------------------------------------

@dataclass
class _CellState:
    post:          ParsedPost
    status:        str  = 'pending'   # pending | ok | error | skip
    tg_group:      int  = 0           # 0 = none; >0 = group number
    tg_slot:       int  = 0           # 1=L, 2=M, 3=R
    selecting:     bool = False
    thumb_loading: bool = False


# ---------------------------------------------------------------------------
# Post grid — virtualised 3-column canvas (only visible rows are rendered)
# ---------------------------------------------------------------------------

class PostGrid(tk.Frame):
    """
    Virtualised 3-column grid.  Only the rows within the visible viewport
    (plus VISIBLE_BUFFER rows above and below) are drawn as Canvas items.
    Off-screen rows are evicted to keep the item count and memory flat
    regardless of how large the import is.
    """

    VISIBLE_BUFFER = 2   # extra rows rendered above/below viewport edge

    def __init__(self, parent, on_cell_click, on_ctrl_click=None,
                 on_right_click_cb=None, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._on_click          = on_cell_click
        self._on_ctrl_click     = on_ctrl_click
        self._on_right_click_cb = on_right_click_cb

        self._posts:       List[ParsedPost]          = []
        self._cell_size:   int                       = 0
        self._row_h:       int                       = 0
        self._total_rows:  int                       = 0

        # Logical state for every post (index → _CellState)
        self._states:   Dict[int, _CellState]        = {}
        # Canvas item IDs for rendered cells (index → list[item_id])
        self._rendered: Dict[int, List[int]]         = {}
        # PhotoImage refs keyed by index — must stay referenced to avoid GC
        self._photos:   Dict[int, ImageTk.PhotoImage] = {}

        self._canvas = tk.Canvas(self, bg=BG_DEEP, highlightthickness=0, bd=0)
        self._scrollbar = ttk.Scrollbar(self, orient="vertical",
                                         command=self._scroll_cmd)
        self._canvas.configure(yscrollcommand=self._scrollbar.set)
        self._scrollbar.pack(side="right", fill="y")
        self._canvas.pack(side="left", fill="both", expand=True)

        self._canvas.bind("<Configure>",        self._on_canvas_resize)
        self._canvas.bind("<Button-1>",         self._on_left_click)
        self._canvas.bind("<Control-Button-1>", self._on_ctrl_left_click)
        self._canvas.bind("<Button-3>",         self._on_right_click)
        self._canvas.bind("<Enter>",  lambda e: self.bind_all("<MouseWheel>", self._on_scroll))
        self._canvas.bind("<Leave>",  lambda e: self.unbind_all("<MouseWheel>"))

    # ------------------------------------------------------------------
    # Scroll
    # ------------------------------------------------------------------

    def _scroll_cmd(self, *args):
        self._canvas.yview(*args)
        self._update_viewport()

    def _on_scroll(self, event):
        self._canvas.yview_scroll(int(-1 * (event.delta / 120)), "units")
        self._update_viewport()

    # ------------------------------------------------------------------
    # Canvas resize → re-layout
    # ------------------------------------------------------------------

    def _on_canvas_resize(self, event):
        if not self._posts:
            return
        new_size = max(60, (event.width - GRID_GAP * (GRID_COLS - 1)) // GRID_COLS)
        if new_size == self._cell_size:
            return
        ypos = self._canvas.yview()[0]
        self._cell_size = new_size
        self._row_h     = new_size + GRID_GAP
        self._drop_all()
        total_h = self._total_rows * self._row_h
        self._canvas.configure(scrollregion=(0, 0, event.width, total_h))
        self._canvas.yview_moveto(ypos)
        self._update_viewport()

    def _drop_all(self):
        """Delete all canvas items and clear tracking — no state loss."""
        for items in self._rendered.values():
            for item in items:
                self._canvas.delete(item)
        self._rendered.clear()
        self._photos.clear()
        # Reset thumb_loading so stale in-flight threads don't block fresh loads
        for s in self._states.values():
            s.thumb_loading = False

    # ------------------------------------------------------------------
    # Viewport management
    # ------------------------------------------------------------------

    def _visible_index_range(self):
        """(first_idx, last_idx) covering visible rows + buffer."""
        if not self._row_h or not self._posts:
            return 0, -1
        y0, y1   = self._canvas.yview()
        total_h  = self._total_rows * self._row_h
        first_row = max(0,
            int(y0 * total_h / self._row_h) - self.VISIBLE_BUFFER)
        last_row  = min(self._total_rows - 1,
            int(y1 * total_h / self._row_h) + self.VISIBLE_BUFFER)
        return first_row * GRID_COLS, min(
            len(self._posts) - 1, (last_row + 1) * GRID_COLS - 1)

    def _update_viewport(self):
        if not self._posts:
            return
        first, last = self._visible_index_range()

        # Evict cells that are well off-screen
        margin = self.VISIBLE_BUFFER * GRID_COLS * 3
        for idx in list(self._rendered):
            if idx < first - margin or idx > last + margin:
                for item in self._rendered.pop(idx):
                    self._canvas.delete(item)
                self._photos.pop(idx, None)

        # Draw newly visible cells
        for idx in range(first, last + 1):
            if idx not in self._rendered:
                self._draw_cell(idx)

    # ------------------------------------------------------------------
    # Drawing
    # ------------------------------------------------------------------

    def _cell_xy(self, idx: int):
        row = idx // GRID_COLS
        col = idx % GRID_COLS
        return col * (self._cell_size + GRID_GAP), row * self._row_h

    def _draw_cell(self, idx: int):
        """Create canvas items for one cell; store IDs in _rendered[idx]."""
        if idx >= len(self._posts):
            return
        state = self._states[idx]
        cs    = self._cell_size
        x, y  = self._cell_xy(idx)
        items: List[int] = []

        # Background
        items.append(self._canvas.create_rectangle(
            x, y, x + cs, y + cs,
            fill=BG_HOVER if state.post.excluded else BG_DEEP, outline=''))

        # Thumbnail
        if idx in self._photos:
            items.append(self._canvas.create_image(
                x + cs // 2, y + cs // 2, image=self._photos[idx]))

        # Carousel badge (top-right)
        if state.post.post_type == 'carousel':
            items.append(self._canvas.create_text(
                x + cs - 8, y + 8,
                text=f"▪ {len(state.post.images)}",
                anchor="ne", fill="white", font=("Segoe UI", 11, "bold")))

        # Status / excluded overlay
        _ICONS  = {'ok': '✓', 'error': '✗', 'skip': '—'}
        _COLORS = {'ok': FG_OK, 'error': FG_ERR, 'skip': FG_WARN}
        if state.post.excluded:
            items.append(self._canvas.create_rectangle(
                x, y, x + cs, y + cs,
                fill=BG_DEEP, stipple='gray50', outline=''))
            items.append(self._canvas.create_text(
                x + cs // 2, y + cs // 2, text='∅',
                fill=FG_DIM, font=("Segoe UI", 24, "bold")))
        elif state.status in _ICONS:
            items.append(self._canvas.create_rectangle(
                x, y, x + cs, y + cs,
                fill=BG_DEEP, stipple='gray50', outline=''))
            items.append(self._canvas.create_text(
                x + cs // 2, y + cs // 2, text=_ICONS[state.status],
                fill=_COLORS[state.status], font=("Segoe UI", 24, "bold")))

        # Trigram ring + slot badge (bottom-right)
        if state.tg_group > 0:
            lbl = f"T{state.tg_group}{('L','M','R')[state.tg_slot - 1] if 1 <= state.tg_slot <= 3 else str(state.tg_slot)}"
            bw  = 22
            items.append(self._canvas.create_rectangle(
                x + 1, y + 1, x + cs - 1, y + cs - 1,
                outline='#c8a96e', width=2))
            items.append(self._canvas.create_rectangle(
                x + cs - bw, y + cs - 16, x + cs, y + cs,
                fill='#c8a96e', outline=''))
            items.append(self._canvas.create_text(
                x + cs - bw // 2, y + cs - 8, text=lbl,
                anchor='center', fill='#000', font=("Segoe UI", 9, "bold")))

        # Selection ring (dashed, during Ctrl+click group building)
        if state.selecting:
            items.append(self._canvas.create_rectangle(
                x + 2, y + 2, x + cs - 2, y + cs - 2,
                outline=ACCENT, width=2, dash=(4, 3)))

        self._rendered[idx] = items

        # Kick off async thumb load if not already loaded/loading
        if (idx not in self._photos and not state.thumb_loading
                and state.post.images):
            state.thumb_loading = True
            self._load_thumb_async(idx, state.post.images[0])

    def _redraw_cell(self, idx: int):
        """Delete + redraw one cell if it's currently rendered."""
        if idx not in self._rendered:
            return
        for item in self._rendered.pop(idx):
            self._canvas.delete(item)
        self._draw_cell(idx)

    def _load_thumb_async(self, idx: int, img_path: str):
        cs   = self._cell_size
        post = self._posts[idx] if idx < len(self._posts) else None
        def _load():
            try:
                img  = Image.open(img_path)
                w, h = img.size
                side = min(w, h)
                img  = img.crop(((w - side) // 2, (h - side) // 2,
                                 (w + side) // 2, (h + side) // 2))
                img  = img.resize((cs, cs), Image.LANCZOS)
                photo = ImageTk.PhotoImage(img)
                self.after(0, lambda: self._on_thumb_ready(idx, post, photo))
            except Exception:
                pass
            finally:
                # Only clear flag if this slot still belongs to the same post
                if (post is not None and idx in self._states
                        and idx < len(self._posts)
                        and self._posts[idx] is post):
                    self._states[idx].thumb_loading = False
        threading.Thread(target=_load, daemon=True).start()

    def _on_thumb_ready(self, idx: int, post, photo: ImageTk.PhotoImage):
        # Discard if this slot now belongs to a different post (reorder happened)
        if post is None or idx >= len(self._posts) or self._posts[idx] is not post:
            return
        if idx not in self._states:
            return
        self._photos[idx] = photo
        self._redraw_cell(idx)

    # ------------------------------------------------------------------
    # Hit testing + input handlers
    # ------------------------------------------------------------------

    def _hit(self, ex: int, ey_canvas: int) -> int:
        """Canvas coordinates → post index.  Returns -1 on miss."""
        if not self._row_h or not self._cell_size:
            return -1
        row = int(ey_canvas // self._row_h)
        col = int(ex // (self._cell_size + GRID_GAP))
        if col >= GRID_COLS:
            return -1
        # Reject clicks in the gap between cells
        if (ex - col * (self._cell_size + GRID_GAP) >= self._cell_size or
                ey_canvas - row * self._row_h >= self._cell_size):
            return -1
        idx = row * GRID_COLS + col
        return idx if 0 <= idx < len(self._posts) else -1

    def _on_left_click(self, e):
        idx = self._hit(e.x, self._canvas.canvasy(e.y))
        if idx >= 0:
            self._on_click(idx)

    def _on_ctrl_left_click(self, e):
        if not self._on_ctrl_click:
            return
        idx = self._hit(e.x, self._canvas.canvasy(e.y))
        if idx >= 0:
            self._on_ctrl_click(idx)

    def _on_right_click(self, e):
        idx = self._hit(e.x, self._canvas.canvasy(e.y))
        if idx < 0:
            return
        state = self._states[idx]
        menu  = tk.Menu(self, tearoff=0)
        if state.tg_group > 0:
            menu.add_command(
                label=f"Remove from Trigram T{state.tg_group}",
                command=lambda i=idx: (self._on_right_click_cb('remove', i)
                                       if self._on_right_click_cb else None))
        else:
            menu.add_command(
                label="Add to Trigram Group  (or Ctrl+click)",
                command=lambda i=idx: (self._on_right_click_cb('add', i)
                                       if self._on_right_click_cb else None))
        menu.add_separator()
        lbl = "Include" if state.post.excluded else "Exclude"
        menu.add_command(label=lbl, command=lambda i=idx: self._toggle_excluded(i))
        try:
            menu.tk_popup(e.x_root, e.y_root)
        finally:
            menu.grab_release()

    def _toggle_excluded(self, idx: int):
        state = self._states[idx]
        state.post.excluded = not state.post.excluded
        self._redraw_cell(idx)
        if self._on_right_click_cb:
            self._on_right_click_cb('excluded_changed', idx)

    # ------------------------------------------------------------------
    # Public API  (interface identical to the old widget-based PostGrid)
    # ------------------------------------------------------------------

    def load(self, posts: List[ParsedPost]):
        self.clear()
        self._posts = list(posts)
        w = self._canvas.winfo_width()
        if w < 100:
            w = WIN_W - 30
        self._cell_size  = max(60, (w - GRID_GAP * (GRID_COLS - 1)) // GRID_COLS)
        self._row_h      = self._cell_size + GRID_GAP
        self._total_rows = (len(posts) + GRID_COLS - 1) // GRID_COLS
        self._states     = {i: _CellState(post=p) for i, p in enumerate(posts)}
        total_h = self._total_rows * self._row_h
        self._canvas.configure(scrollregion=(0, 0, w, total_h))
        self._canvas.yview_moveto(0)
        self._update_viewport()

    def reorder(self, posts: List[ParsedPost]):
        """Remap states + photos to new post order; re-render visible rows."""
        if not self._posts:
            return
        ypos = self._canvas.yview()[0]

        old_states = {id(s.post): s for s in self._states.values()}
        old_photos = {id(self._posts[i]): ph
                      for i, ph in self._photos.items()
                      if i < len(self._posts)}

        self._drop_all()
        self._posts  = list(posts)
        self._states = {}
        self._photos = {}
        for new_idx, post in enumerate(posts):
            s = old_states.get(id(post))
            self._states[new_idx] = s if s else _CellState(post=post)
            if id(post) in old_photos:
                self._photos[new_idx] = old_photos[id(post)]

        self._total_rows = (len(posts) + GRID_COLS - 1) // GRID_COLS
        total_h = self._total_rows * self._row_h
        self._canvas.configure(
            scrollregion=(0, 0, self._canvas.winfo_width(), total_h))
        self._canvas.yview_moveto(ypos)
        self._update_viewport()

    def clear(self):
        self._drop_all()
        self._states.clear()
        self._posts.clear()
        self._cell_size  = 0
        self._row_h      = 0
        self._total_rows = 0

    def reflow(self):
        if self._posts:
            w = self._canvas.winfo_width()
            # Simulate a Configure event to trigger re-layout
            class _E:
                width = w
            self._on_canvas_resize(_E())

    def restore_state(self, uploaded: Dict[int, int],
                      excluded: Set[int], trigram_groups: List[dict]):
        """Restore persisted job state after a fresh parse."""
        for idx in excluded:
            if idx in self._states:
                self._states[idx].post.excluded = True
        for idx in uploaded:
            if idx in self._states:
                self._states[idx].status = 'ok'
        for grp in trigram_groups:
            for idx, slot in zip(grp['indices'], grp['slots']):
                if idx in self._states:
                    self._states[idx].tg_group = grp['num']
                    self._states[idx].tg_slot  = slot
        self._drop_all()
        self._update_viewport()

    def set_cell_status(self, index: int, status: str):
        if index in self._states:
            self._states[index].status = status
            self._redraw_cell(index)

    def set_trigram_cells(self, group_num: int, indices: list, slots: list):
        for idx, slot in zip(indices, slots):
            if idx in self._states:
                self._states[idx].tg_group = group_num
                self._states[idx].tg_slot  = slot
                self._redraw_cell(idx)

    def clear_trigram_cells(self, indices: list):
        for idx in indices:
            if idx in self._states:
                self._states[idx].tg_group = 0
                self._states[idx].tg_slot  = 0
                self._redraw_cell(idx)

    def set_selecting_cells(self, indices: list, active: bool):
        for idx in indices:
            if idx in self._states:
                self._states[idx].selecting = active
                self._redraw_cell(idx)

    def clear_all_selecting(self):
        for idx, state in list(self._states.items()):
            if state.selecting:
                state.selecting = False
                self._redraw_cell(idx)


# ---------------------------------------------------------------------------
# Post detail view
# ---------------------------------------------------------------------------

class PostDetail(tk.Frame):
    """Detail view for a single post — shows all images + caption."""

    def __init__(self, parent, on_back, on_prev, on_next, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._on_back = on_back
        self._on_prev = on_prev
        self._on_next = on_next
        self._thumbs  = []   # keep references
        self._preview = None  # large preview reference
        self._post    = None
        self._build()

    def _build(self):
        # ── Navigation bar ───────────────────────────────────────────
        nav = tk.Frame(self, bg=BG_CARD, height=48)
        nav.pack(fill="x")
        nav.pack_propagate(False)

        self._back_btn = tk.Button(
            nav, text="←  BACK TO GRID", command=self._on_back,
            bg=BG_CARD, fg=ACCENT, activebackground=BG_HOVER,
            activeforeground=ACCENT, relief="flat", font=FONT_BOLD,
            cursor="hand2",
        )
        self._back_btn.pack(side="left", padx=10)

        self._next_btn = tk.Button(
            nav, text="NEXT →", command=self._on_next,
            bg=BG_CARD, fg=FG_DIM, activebackground=BG_HOVER,
            activeforeground=FG_MAIN, relief="flat", font=FONT_SMALL,
            cursor="hand2",
        )
        self._next_btn.pack(side="right", padx=10)

        self._prev_btn = tk.Button(
            nav, text="← PREV", command=self._on_prev,
            bg=BG_CARD, fg=FG_DIM, activebackground=BG_HOVER,
            activeforeground=FG_MAIN, relief="flat", font=FONT_SMALL,
            cursor="hand2",
        )
        self._prev_btn.pack(side="right")

        self._post_info = tk.Label(
            nav, text="", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL,
        )
        self._post_info.pack(side="right", padx=10)

        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # ── Scrollable content ───────────────────────────────────────
        self._content_canvas = tk.Canvas(self, bg=BG_DEEP, highlightthickness=0)
        self._content_sb = ttk.Scrollbar(self, orient="vertical",
                                          command=self._content_canvas.yview)
        self._content_canvas.configure(yscrollcommand=self._content_sb.set)
        self._content_sb.pack(side="right", fill="y")
        self._content_canvas.pack(side="left", fill="both", expand=True)

        self._content = tk.Frame(self._content_canvas, bg=BG_DEEP)
        self._content_win = self._content_canvas.create_window(
            (0, 0), window=self._content, anchor="nw")
        self._content.bind("<Configure>", lambda e: self._content_canvas.configure(
            scrollregion=self._content_canvas.bbox("all")))
        self._content_canvas.bind("<Configure>", lambda e: self._content_canvas.itemconfig(
            self._content_win, width=e.width))
        self._content_canvas.bind("<MouseWheel>", lambda e: self._content_canvas.yview_scroll(
            int(-1 * (e.delta / 120)), "units"))

        # ── Large preview ────────────────────────────────────────────
        self._preview_lbl = tk.Label(self._content, bg="#0A0A0E")
        self._preview_lbl.pack(fill="x", padx=10, pady=(10, 5))

        # ── Thumbnail strip ──────────────────────────────────────────
        self._strip_frame = tk.Frame(self._content, bg=BG_DEEP)
        self._strip_frame.pack(fill="x", padx=10, pady=5)

        # ── Metadata bar ─────────────────────────────────────────────
        meta = tk.Frame(self._content, bg=BG_CARD)
        meta.pack(fill="x", padx=10, pady=(5, 5))

        self._type_lbl = tk.Label(
            meta, text="", bg=BG_CARD, fg=ACCENT, font=FONT_BOLD,
        )
        self._type_lbl.pack(side="left", padx=10, pady=6)

        self._count_lbl = tk.Label(
            meta, text="", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL,
        )
        self._count_lbl.pack(side="left", padx=(0, 10))

        self._date_lbl = tk.Label(
            meta, text="", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL,
        )
        self._date_lbl.pack(side="right", padx=10)

        # ── Caption ──────────────────────────────────────────────────
        tk.Label(
            self._content, text="CAPTION", bg=BG_DEEP, fg=FG_DIM,
            font=FONT_SMALL, anchor="w",
        ).pack(fill="x", padx=14, pady=(10, 2))

        self._caption_text = tk.Text(
            self._content, bg=BG_MID, fg=FG_MAIN, font=FONT_UI,
            relief="flat", height=5, wrap="word",
            insertbackground=ACCENT,
            highlightthickness=1, highlightbackground=BORDER,
            highlightcolor=ACCENT,
        )
        self._caption_text.pack(fill="x", padx=10, pady=(0, 5))

        # ── Tags ─────────────────────────────────────────────────────
        tk.Label(
            self._content, text="EXTRACTED TAGS", bg=BG_DEEP, fg=FG_DIM,
            font=FONT_SMALL, anchor="w",
        ).pack(fill="x", padx=14, pady=(5, 2))

        self._tags_lbl = tk.Label(
            self._content, text="", bg=BG_MID, fg=ACCENT2,
            font=FONT_SMALL, anchor="w", wraplength=480, justify="left",
            padx=10, pady=6,
        )
        self._tags_lbl.pack(fill="x", padx=10, pady=(0, 10))

    def show(self, post: ParsedPost, index: int, total: int):
        """Display a post's details."""
        self._post = post
        self._thumbs.clear()

        # Nav info
        self._post_info.configure(text=f"{index + 1} / {total}")

        # Type badge
        self._type_lbl.configure(
            text=post.post_type.upper(),
            fg=ACCENT if post.post_type == 'carousel' else ACCENT2,
        )
        self._count_lbl.configure(
            text=f"{len(post.images)} image{'s' if len(post.images) != 1 else ''}",
        )

        # Date
        from datetime import datetime
        dt = datetime.utcfromtimestamp(post.ig_timestamp)
        self._date_lbl.configure(text=dt.strftime('%B %d, %Y  %H:%M'))

        # Caption
        self._caption_text.delete("1.0", "end")
        if post.caption:
            self._caption_text.insert("1.0", post.caption)
            self._caption_text.configure(fg=FG_MAIN)
        else:
            self._caption_text.insert("1.0", "(no caption)")
            self._caption_text.configure(fg=FG_DIM)

        # Tags
        if post.hashtags:
            self._tags_lbl.configure(
                text='  '.join(f'#{t}' for t in post.hashtags),
                fg=ACCENT2,
            )
        else:
            self._tags_lbl.configure(text="(no tags)", fg=FG_DIM)

        # Load large preview of first image
        self._load_preview(post.images[0] if post.images else None)

        # Thumbnail strip
        for w in self._strip_frame.winfo_children():
            w.destroy()

        if len(post.images) > 1:
            for i, img_path in enumerate(post.images):
                self._load_strip_thumb(img_path, i)

        self._content_canvas.yview_moveto(0)

    def _load_preview(self, img_path):
        if not img_path:
            return

        def load():
            try:
                img = Image.open(img_path)
                # Fit within preview area (max ~500px wide)
                max_w = 500
                max_h = 400
                img.thumbnail((max_w, max_h), Image.LANCZOS)
                photo = ImageTk.PhotoImage(img)
                self.after(0, lambda: self._set_preview(photo))
            except Exception:
                pass
        threading.Thread(target=load, daemon=True).start()

    def _set_preview(self, photo):
        self._preview = photo
        self._preview_lbl.configure(image=photo)

    def _load_strip_thumb(self, img_path: str, index: int):
        lbl = tk.Label(
            self._strip_frame, bg="#0A0A0E", width=THUMB_DETAIL,
            height=THUMB_DETAIL, cursor="hand2",
        )
        lbl.pack(side="left", padx=(0, 3))
        lbl.bind("<Button-1>", lambda e, p=img_path: self._load_preview(p))

        def load():
            try:
                img = Image.open(img_path)
                w, h = img.size
                side = min(w, h)
                left = (w - side) // 2
                top  = (h - side) // 2
                img = img.crop((left, top, left + side, top + side))
                img = img.resize((THUMB_DETAIL, THUMB_DETAIL), Image.LANCZOS)
                photo = ImageTk.PhotoImage(img)
                self._thumbs.append(photo)
                self.after(0, lambda: lbl.configure(image=photo))
            except Exception:
                pass
        threading.Thread(target=load, daemon=True).start()


# ---------------------------------------------------------------------------
# Trigram slot assignment panel
# ---------------------------------------------------------------------------

class TrigramPanel(tk.Toplevel):
    """
    Modal dialog for assigning L/M/R slots to 3 selected posts.

    Shows three thumbnails side-by-side.  Swap buttons between adjacent
    positions let the user reorder them.  LOCK commits the group.
    """

    THUMB = 90  # px per thumbnail

    def __init__(self, parent, posts, indices, on_lock, **kwargs):
        """
        posts:    list of ParsedPost — exactly 3 items, in display order
        indices:  matching list of int — original post indices
        on_lock:  callback(indices_in_slot_order, slots=[1,2,3])
        """
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self.title("Assign Trigram Slots")
        self.resizable(False, False)
        self.grab_set()
        self.transient(parent)

        self._on_lock  = on_lock
        # Work with a mutable list of (post, original_index) pairs
        self._order    = list(zip(posts, indices))  # current L/M/R order
        self._thumbs   = [None, None, None]         # PhotoImage refs
        self._drag_src = None                        # slot index being dragged

        self._build()
        self._load_thumbs()
        self.after(50, self._center)

    def _center(self):
        self.update_idletasks()
        pw, ph = self.winfo_reqwidth(), self.winfo_reqheight()
        rx = self.master.winfo_rootx() + (self.master.winfo_width()  - pw) // 2
        ry = self.master.winfo_rooty() + (self.master.winfo_height() - ph) // 2
        self.geometry(f"+{rx}+{ry}")

    def _build(self):
        tk.Label(self, text="TRIGRAM SLOT ORDER", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_SMALL).pack(padx=14, pady=(12, 4))
        tk.Label(self, text="Drag thumbnails to reorder  ·  or use ⇄ to swap adjacent.",
                 bg=BG_DEEP, fg=FG_DIM, font=("Segoe UI", 11)).pack(padx=14, pady=(0, 8))

        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # Thumbnail strip
        self._strip = tk.Frame(self, bg=BG_DEEP)
        self._strip.pack(padx=14, pady=12)

        self._thumb_labels = []
        self._slot_labels  = []
        SLOT_NAMES = ["L", "M", "R"]
        T = self.THUMB

        for i in range(3):
            col = tk.Frame(self._strip, bg=BG_DEEP)
            col.pack(side="left", padx=6)

            # Slot label above thumbnail
            sl = tk.Label(col, text=SLOT_NAMES[i], bg=BG_DEEP, fg=ACCENT,
                          font=FONT_BOLD)
            sl.pack()
            self._slot_labels.append(sl)

            # Thumbnail canvas — drag-and-drop reorder
            c = tk.Canvas(col, width=T, height=T, bg="#0A0A0E",
                          highlightthickness=1, highlightbackground=BORDER,
                          cursor="fleur")
            c.pack()
            self._thumb_labels.append(c)
            slot_i = i  # capture
            c.bind("<ButtonPress-1>",   lambda e, s=slot_i: self._start_drag(e, s))
            c.bind("<B1-Motion>",       lambda e, s=slot_i: self._on_drag_motion(e, s))
            c.bind("<ButtonRelease-1>", lambda e, s=slot_i: self._end_drag(e, s))

            # Swap button between positions (not after the last one)
            if i < 2:
                swap_i = i  # capture loop var
                btn = tk.Button(
                    self._strip,
                    text="⇄",
                    command=lambda si=swap_i: self._swap(si),
                    bg=BG_MID, fg=FG_MAIN, relief="flat",
                    font=("Segoe UI", 19), cursor="hand2",
                    padx=4, pady=0,
                    activebackground=BG_HOVER, activeforeground=ACCENT,
                )
                btn.pack(side="left", padx=2, pady=(T // 2, 0))

        # Post titles under strip
        self._title_frame = tk.Frame(self, bg=BG_DEEP)
        self._title_frame.pack(padx=14, pady=(0, 8))
        self._title_labels = []
        for i in range(3):
            lbl = tk.Label(self._title_frame, text="", bg=BG_DEEP, fg=FG_DIM,
                           font=("Segoe UI", 11), width=12, wraplength=90,
                           justify="center")
            lbl.pack(side="left", padx=6)
            self._title_labels.append(lbl)

        self._update_titles()

        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # Buttons
        btn_row = tk.Frame(self, bg=BG_CARD, padx=14, pady=10)
        btn_row.pack(fill="x")

        tk.Button(
            btn_row, text="CANCEL",
            command=self.destroy,
            bg=BG_MID, fg=FG_DIM, relief="flat", font=FONT_SMALL,
            activebackground=BG_HOVER, activeforeground=FG_MAIN, cursor="hand2",
        ).pack(side="left")

        tk.Button(
            btn_row, text="LOCK GROUP",
            command=self._lock,
            bg=ACCENT, fg=BG_DEEP, relief="flat", font=FONT_BOLD,
            activebackground=ACCENT2, activeforeground=BG_DEEP, cursor="hand2",
            padx=12,
        ).pack(side="right")

    def _swap(self, i: int):
        """Swap position i with position i+1."""
        self._order[i], self._order[i + 1] = self._order[i + 1], self._order[i]
        self._thumbs[i], self._thumbs[i + 1] = self._thumbs[i + 1], self._thumbs[i]
        self._update_titles()
        self._refresh_thumb_display()

    def _swap_to(self, src: int, dest: int):
        """Move the item at src to dest, shifting others."""
        if src == dest:
            return
        item = self._order.pop(src)
        self._order.insert(dest, item)
        photo = self._thumbs.pop(src)
        self._thumbs.insert(dest, photo)
        self._update_titles()
        self._refresh_thumb_display()

    def _refresh_thumb_display(self):
        """Repaint all three thumbnail canvases from the cached PhotoImages."""
        T = self.THUMB
        for slot, photo in enumerate(self._thumbs):
            canvas = self._thumb_labels[slot]
            canvas.delete("all")
            if photo:
                canvas.create_image(T // 2, T // 2, image=photo, anchor="center")
            else:
                canvas.create_text(T // 2, T // 2, text="?",
                                   fill=FG_DIM, font=FONT_BOLD)

    # ---- drag-and-drop handlers ----

    def _start_drag(self, event, slot: int):
        self._drag_src = slot
        self._thumb_labels[slot].configure(highlightbackground=ACCENT, highlightthickness=2)

    def _on_drag_motion(self, event, slot: int):
        # Highlight potential drop target
        w = event.widget.winfo_containing(event.x_root, event.y_root)
        for i, canvas in enumerate(self._thumb_labels):
            if canvas is w and i != self._drag_src:
                canvas.configure(highlightbackground=FG_WARN, highlightthickness=2)
            elif i != self._drag_src:
                canvas.configure(highlightbackground=BORDER, highlightthickness=1)

    def _end_drag(self, event, slot: int):
        if self._drag_src is None:
            return
        src = self._drag_src
        self._drag_src = None
        # Reset all highlights
        for c in self._thumb_labels:
            c.configure(highlightbackground=BORDER, highlightthickness=1)
        # Find which canvas the pointer landed on
        w = event.widget.winfo_containing(event.x_root, event.y_root)
        for i, canvas in enumerate(self._thumb_labels):
            if canvas is w and i != src:
                self._swap_to(src, i)
                return

    def _update_titles(self):
        for i, (post, _) in enumerate(self._order):
            title = (post.body or f"Post {i+1}")[:30]
            self._title_labels[i].configure(text=title)

    def _load_thumbs(self):
        T = self.THUMB
        for i, (post, _) in enumerate(self._order):
            if post.images:
                self._load_one_thumb(i, post.images[0], T)
            else:
                self._thumb_labels[i].delete("all")
                self._thumb_labels[i].create_text(
                    T // 2, T // 2, text="?", fill=FG_DIM,
                    font=FONT_BOLD,
                )

    def _load_one_thumb(self, slot: int, img_path: str, size: int):
        canvas = self._thumb_labels[slot]

        def load():
            try:
                img = Image.open(img_path)
                w, h = img.size
                side = min(w, h)
                img  = img.crop(((w-side)//2, (h-side)//2,
                                 (w+side)//2, (h+side)//2))
                img  = img.resize((size, size), Image.LANCZOS)
                photo = ImageTk.PhotoImage(img)
                self._thumbs[slot] = photo
                self.after(0, lambda c=canvas, p=photo: (
                    c.delete("all"),
                    c.create_image(size // 2, size // 2, image=p, anchor="center"),
                ))
            except Exception:
                pass
        threading.Thread(target=load, daemon=True).start()

    def _lock(self):
        indices = [idx for _, idx in self._order]
        slots   = [1, 2, 3]
        self._on_lock(indices, slots)
        self.grab_release()
        self.destroy()


# ---------------------------------------------------------------------------
# Main application window
# ---------------------------------------------------------------------------

class App(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title(f"UNZUCKER  —  build {BUILD_VERSION}")
        self.minsize(520, 600)

        # State — load config first so geometry restore can use it
        self._config         = cfg_module.load()

        # Restore saved geometry / maximised state
        saved_geo   = self._config.get('window_geometry', '')
        saved_state = self._config.get('window_state', 'zoomed')  # default zoomed on first run
        if saved_geo:
            try:
                self.geometry(saved_geo)
            except tk.TclError:
                self.geometry(f"{WIN_W}x{WIN_H}")
        else:
            self.geometry(f"{WIN_W}x{WIN_H}")
        if saved_state == 'zoomed':
            self.after(50, lambda: self.state('zoomed'))
        self.configure(bg=BG_DEEP)

        # Remaining state
        self._client:        Optional[UnzuckerClient] = None
        self._site_data:     Optional[SiteData]       = None
        self._posts:         List[ParsedPost]          = []
        self._ftp_transport  = None
        self._posting        = False
        self._msg_queue:     queue.Queue               = queue.Queue()
        self._current_view   = 'grid'   # 'grid' or 'detail'
        self._detail_index   = 0

        # Trigram group state
        # _tg_groups: list of {'indices': [i,j,k], 'slots': [1,2,3], 'orientation': 'h'}
        # _tg_selection: indices currently being accumulated (max 3)
        self._tg_groups:     list = []
        self._tg_selection:  list = []
        self._tg_group_ctr:  int  = 0  # monotonically incrementing group number

        # Job persistence
        self._job:           Optional[job_state.JobState] = None

        self._apply_ttk_style()
        self._build_ui()
        self._load_config_to_ui()
        self.after(100, self._poll_queue)
        self.protocol("WM_DELETE_WINDOW", self._on_close)

    # ------------------------------------------------------------------
    # TTK style
    # ------------------------------------------------------------------

    def _apply_ttk_style(self):
        style = ttk.Style(self)
        style.theme_use("clam")

        style.configure("TCombobox",
            fieldbackground=BG_MID, background=BG_MID, foreground=FG_MAIN,
            selectbackground=ACCENT, selectforeground=BG_DEEP,
            bordercolor=BORDER, arrowcolor=FG_DIM, padding=3,
        )
        style.map("TCombobox",
            fieldbackground=[("readonly", BG_MID), ("!readonly", BG_MID)],
            foreground=[("readonly", FG_MAIN), ("!readonly", FG_MAIN)],
        )
        style.configure("TScrollbar",
            background=BG_MID, troughcolor=BG_DEEP,
            bordercolor=BG_DEEP, arrowcolor=FG_DIM,
        )
        style.configure("Accent.TButton",
            background=ACCENT, foreground=BG_DEEP, font=FONT_BOLD,
            padding=(14, 7), borderwidth=0,
        )
        style.map("Accent.TButton",
            background=[("active", "#E8A030"), ("disabled", BG_MID)],
            foreground=[("disabled", FG_DIM)],
        )
        style.configure("Ghost.TButton",
            background=BG_MID, foreground=FG_MAIN, font=FONT_BOLD,
            padding=(14, 7), borderwidth=0,
        )
        style.map("Ghost.TButton",
            background=[("active", BG_HOVER)],
        )

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

    # ------------------------------------------------------------------
    # UI construction
    # ------------------------------------------------------------------

    def _build_ui(self):
        # ── Header ───────────────────────────────────────────────────
        header = tk.Frame(self, bg=BG_CARD, height=59)
        header.pack(fill="x")
        header.pack_propagate(False)

        tk.Label(
            header, text="UNZUCKER", bg=BG_CARD, fg=ACCENT, font=FONT_TITLE,
        ).pack(side="left", padx=16)

        self._conn_dot = tk.Label(header, text="●", bg=BG_CARD, fg=FG_DIM, font=("Segoe UI", 15))
        self._conn_dot.pack(side="right", padx=(0, 14))
        self._conn_lbl = tk.Label(header, text="Not connected", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        self._conn_lbl.pack(side="right")
        tk.Label(header, text=f"build {BUILD_VERSION}", bg=BG_CARD, fg=FG_DIM,
                 font=("Segoe UI", 11)).pack(side="right", padx=(0, 20))

        # Keyring indicator
        kr_text  = "🔒 keyring" if _KEYRING_OK else "⚠ no keyring"
        kr_color = FG_OK       if _KEYRING_OK else FG_WARN
        tk.Label(header, text=kr_text, bg=BG_CARD, fg=kr_color,
                 font=("Segoe UI", 11)).pack(side="right", padx=(0, 10))

        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # ── Config collapse toggle ───────────────────────────────────
        self._cfg_visible = True
        cfg_toggle = tk.Frame(self, bg=BG_DEEP, height=35, cursor="hand2")
        cfg_toggle.pack(fill="x")
        cfg_toggle.pack_propagate(False)
        self._cfg_arrow = tk.Label(cfg_toggle, text="▲  CONFIGURATION",
                                   bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                                   cursor="hand2")
        self._cfg_arrow.pack(side="left", padx=14, pady=4)
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # ── Config area ──────────────────────────────────────────────
        self._cfg_frame = tk.Frame(self, bg=BG_DEEP)
        self._cfg_frame.pack(fill="both", expand=True, padx=14, pady=10)

        cfg_toggle.bind("<Button-1>", self._toggle_config)
        self._cfg_arrow.bind("<Button-1>", self._toggle_config)

        cfg = self._cfg_frame

        # ── Box: CONNECTION ──────────────────────────────────────────
        self._url_var     = tk.StringVar()
        self._api_key_var = tk.StringVar()

        conn_box  = self._box(cfg, "CONNECTION")
        conn_box.pack(fill="x", pady=(0, 8))
        conn_body = self._box_body(conn_box)

        self._field(conn_body, "SITE URL", self._url_var)
        self._field_password(conn_body, "API KEY", self._api_key_var)

        btn_row = tk.Frame(conn_body, bg=BG_CARD)
        btn_row.pack(fill="x")
        self._connect_btn = ttk.Button(btn_row, text="Connect", style="Accent.TButton",
                                        command=self._on_connect)
        self._connect_btn.pack(side="right")

        # ── Box: IMPORT SETTINGS ─────────────────────────────────────
        self._export_var    = tk.StringVar()
        self._copy_var      = tk.StringVar()
        self._throttle_var  = tk.StringVar(value="0.5")
        self._offpeak_var   = tk.BooleanVar(value=False)
        self._peak_start_var = tk.StringVar(value="9")
        self._peak_end_var   = tk.StringVar(value="23")

        imp_box  = self._box(cfg, "IMPORT SETTINGS")
        imp_box.pack(fill="both", expand=True, pady=(0, 0))
        imp_body = self._box_body(imp_box, expand=True)

        self._field_browse(imp_body, "INSTAGRAM EXPORT FOLDER", self._export_var, self._browse_export)
        self._field(imp_body, "COPYRIGHT STRING", self._copy_var)

        # ── Server throttle (2 rows × 4 cols) ───────────────────────
        tk.Label(imp_body, text="SERVER THROTTLE", bg=BG_CARD, fg=FG_DIM,
                 font=FONT_SMALL).pack(anchor="w", pady=(4, 2))
        throttle_grid = tk.Frame(imp_body, bg=BG_CARD)
        throttle_grid.pack(fill="x", pady=(0, 4))
        for i, (label, value) in enumerate(THROTTLE_OPTIONS):
            r, c = divmod(i, 4)
            tk.Radiobutton(
                throttle_grid, text=label, variable=self._throttle_var, value=value,
                bg=BG_CARD, fg=FG_DIM, selectcolor=BG_MID,
                activebackground=BG_CARD, activeforeground=FG_MAIN,
                font=FONT_SMALL, indicatoron=True, cursor="hand2",
            ).grid(row=r, column=c, sticky="w", padx=(0, 10), pady=1)

        # ── Off-peak only ────────────────────────────────────────────
        offpeak_row = tk.Frame(imp_body, bg=BG_CARD)
        offpeak_row.pack(fill="x", pady=(2, 4))

        _HOURS = [str(h) for h in range(24)]

        self._offpeak_hours_frame = tk.Frame(offpeak_row, bg=BG_CARD)
        hours_frame = self._offpeak_hours_frame

        tk.Checkbutton(
            offpeak_row, text="OFF-PEAK ONLY", variable=self._offpeak_var,
            command=self._apply_offpeak_toggle,
            bg=BG_CARD, fg=FG_DIM, selectcolor=BG_MID,
            activebackground=BG_CARD, activeforeground=FG_MAIN,
            font=FONT_SMALL, cursor="hand2",
        ).pack(side="left", padx=(0, 4))

        tk.Label(hours_frame, text="Peak:", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(side="left")
        _om_cfg = dict(bg=BG_MID, fg=FG_MAIN, activebackground=BG_HOVER,
                       activeforeground=FG_MAIN, relief="flat",
                       font=FONT_SMALL, highlightthickness=0, width=2)
        start_om = tk.OptionMenu(hours_frame, self._peak_start_var, *_HOURS)
        start_om.config(**_om_cfg)
        start_om["menu"].config(bg=BG_MID, fg=FG_MAIN, font=FONT_SMALL)
        start_om.pack(side="left", padx=(4, 2))
        tk.Label(hours_frame, text="to", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(side="left")
        end_om = tk.OptionMenu(hours_frame, self._peak_end_var, *_HOURS)
        end_om.config(**_om_cfg)
        end_om["menu"].config(bg=BG_MID, fg=FG_MAIN, font=FONT_SMALL)
        end_om.pack(side="left", padx=(2, 0))
        # hours_frame is conditionally shown by _toggle_offpeak

        # ── Grid section — hidden until parse succeeds ───────────────
        # Wraps the grid header, post grid, and detail view.
        # Config fills the window until the first successful parse.
        self._grid_section = tk.Frame(self, bg=BG_DEEP)
        # NOT packed here — _collapse_config() shows it after parse.

        tk.Frame(self._grid_section, bg=BORDER, height=1).pack(fill="x")

        g_hdr = tk.Frame(self._grid_section, bg=BG_DEEP, height=40)
        g_hdr.pack(fill="x")
        g_hdr.pack_propagate(False)

        self._grid_lbl = tk.Label(
            g_hdr, text="POSTS — 0", bg=BG_DEEP, fg=FG_DIM, font=FONT_BOLD,
        )
        self._grid_lbl.pack(side="left", padx=14, pady=6)

        self._tg_lbl = tk.Label(
            g_hdr, text="", bg=BG_DEEP, fg='#c8a96e', font=FONT_SMALL,
        )
        self._tg_lbl.pack(side="left", padx=(0, 8), pady=6)

        tk.Frame(self._grid_section, bg=BORDER, height=1).pack(fill="x")

        self._view_container = tk.Frame(self._grid_section, bg=BG_DEEP)
        self._view_container.pack(fill="both", expand=True)

        self._grid = PostGrid(
            self._view_container,
            on_cell_click=self._on_cell_click,
            on_ctrl_click=self._on_ctrl_click,
            on_right_click_cb=self._on_right_click_cb,
        )
        self._grid.pack(fill="both", expand=True)

        self._detail = PostDetail(
            self._view_container,
            on_back=self._show_grid,
            on_prev=self._detail_prev,
            on_next=self._detail_next,
        )
        # Detail is not packed initially — shown on cell click

        # ── Bottom action bar (always visible) ───────────────────────
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")
        self._bottom_bar = tk.Frame(self, bg=BG_CARD, height=69)
        self._bottom_bar.pack(fill="x")
        self._bottom_bar.pack_propagate(False)
        bottom = self._bottom_bar

        # Parse Export lives here so it's reachable from both config and grid views
        self._parse_btn = ttk.Button(bottom, text="Parse Export", style="Ghost.TButton",
                                      command=self._on_parse)
        self._parse_btn.pack(side="left", padx=(14, 6), pady=10)

        self._validate_btn = ttk.Button(bottom, text="Validate", style="Ghost.TButton",
                                         command=self._on_validate)
        self._validate_btn.pack(side="left", padx=(0, 6), pady=10)

        self._unload_btn = ttk.Button(bottom, text="Unload Job", style="Ghost.TButton",
                                       command=self._unload_job)
        self._unload_btn.pack(side="left", padx=(0, 6), pady=10)
        self._unload_btn.configure(state="disabled")

        # TRANSFER & POST button (Canvas for reliable Windows rendering)
        self._post_canvas = tk.Canvas(
            bottom, width=240, height=48,
            bg=BG_CARD, highlightthickness=0, cursor="hand2",
        )
        self._post_canvas.pack(side="left", padx=(10, 0), pady=4)
        self._post_rect = self._post_canvas.create_rectangle(
            0, 0, 240, 48, fill=ACCENT, outline='', width=0,
        )
        self._post_text = self._post_canvas.create_text(
            120, 24, text="TRANSFER & POST", fill=BG_DEEP,
            font=("Segoe UI", 13, "bold"),
        )
        self._post_canvas.bind("<Button-1>", lambda e: self._on_post())
        self._post_canvas.bind("<Enter>", lambda e: self._post_canvas.itemconfig(
            self._post_rect, fill=self._lighten(self._post_canvas.itemcget(self._post_rect, 'fill'))))
        self._post_canvas.bind("<Leave>", lambda e: self._post_canvas.itemconfig(
            self._post_rect, fill=ACCENT))

        self._prog_var = tk.DoubleVar()
        self._progress = ttk.Progressbar(bottom, variable=self._prog_var,
                                          mode="determinate", length=140)
        self._progress.pack(side="right", padx=(0, 14), pady=16)

        self._prog_lbl = tk.Label(bottom, text="", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        self._prog_lbl.pack(side="right", padx=6)

        self._status_lbl = tk.Label(bottom, text="Parse an export to begin.",
                                     bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        self._status_lbl.pack(side="right", padx=14)

        # Reflow grid when window is resized
        self._resize_job = None
        self.bind("<Configure>", self._on_win_resize)

    # ------------------------------------------------------------------
    # Window resize → grid reflow
    # ------------------------------------------------------------------

    def _on_win_resize(self, event):
        if event.widget is not self:
            return
        if self._resize_job:
            self.after_cancel(self._resize_job)
        self._resize_job = self.after(150, self._do_reflow)

    def _do_reflow(self):
        self._resize_job = None
        if self._posts and self._current_view == 'grid':
            self._grid.reflow()

    # ------------------------------------------------------------------
    # Box layout helpers
    # ------------------------------------------------------------------

    def _box(self, parent, title: str) -> tk.Frame:
        outer = tk.Frame(parent, bg=BORDER, padx=1, pady=1)
        hdr   = tk.Frame(outer, bg=BG_DEEP, height=43)
        hdr.pack(fill="x")
        hdr.pack_propagate(False)
        tk.Label(hdr, text=title, bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL).pack(
            side="left", padx=10, pady=6)
        return outer

    def _box_body(self, box: tk.Frame, expand=False) -> tk.Frame:
        body = tk.Frame(box, bg=BG_CARD, padx=12, pady=10)
        body.pack(fill="both", expand=expand)
        return body

    def _field_password(self, parent, label, var):
        """Label + masked entry + show/hide toggle."""
        tk.Label(parent, text=label, bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        row = tk.Frame(parent, bg=BG_CARD)
        row.pack(fill="x", pady=(2, 8))
        e = self._entry(row, var, width=0, show="•")
        e.pack(side="left", fill="x", expand=True, padx=(0, 4))
        _vis = [False]
        def _toggle():
            _vis[0] = not _vis[0]
            e.configure(show="" if _vis[0] else "•")
            btn.configure(text="hide" if _vis[0] else "show")
        btn = tk.Button(
            row, text="show", command=_toggle,
            bg=BG_MID, fg=FG_DIM, activebackground=BG_HOVER,
            activeforeground=FG_MAIN, relief="flat",
            font=FONT_SMALL, padx=5, pady=2, cursor="hand2",
        )
        btn.pack(side="left")
        return e

    def _field_in_password(self, grid_parent, label, var, row, col, padx=(0, 0)):
        """Grid cell: masked entry + show/hide toggle."""
        cell = tk.Frame(grid_parent, bg=BG_CARD)
        cell.grid(row=row, column=col, sticky="ew", padx=padx)
        tk.Label(cell, text=label, bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        inner = tk.Frame(cell, bg=BG_CARD)
        inner.pack(fill="x", pady=(2, 0))
        e = self._entry(inner, var, width=1, show="•")
        e.pack(side="left", fill="x", expand=True, padx=(0, 2))
        _vis = [False]
        def _toggle():
            _vis[0] = not _vis[0]
            e.configure(show="" if _vis[0] else "•")
            btn.configure(text="hide" if _vis[0] else "show")
        btn = tk.Button(
            inner, text="show", command=_toggle,
            bg=BG_MID, fg=FG_DIM, activebackground=BG_HOVER,
            activeforeground=FG_MAIN, relief="flat",
            font=FONT_SMALL, padx=4, pady=0, cursor="hand2",
        )
        btn.pack(side="left")

    def _field(self, parent, label, var, show=""):
        tk.Label(parent, text=label, bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        e = self._entry(parent, var, width=0, show=show)
        e.pack(fill="x", pady=(2, 8))
        return e

    def _field_browse(self, parent, label, var, cmd):
        tk.Label(parent, text=label, bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        row = tk.Frame(parent, bg=BG_CARD)
        row.pack(fill="x", pady=(2, 8))
        self._entry(row, var).pack(side="left", fill="x", expand=True, padx=(0, 4))
        tk.Button(
            row, text="…", command=cmd,
            bg=BG_MID, fg=FG_DIM, activebackground=BG_HOVER,
            activeforeground=FG_MAIN, relief="flat",
            font=FONT_SMALL, padx=5, pady=2, cursor="hand2",
        ).pack(side="left")

    def _field_in(self, grid_parent, label, var, row, col, padx=(0, 0), show=""):
        cell = tk.Frame(grid_parent, bg=BG_CARD)
        cell.grid(row=row, column=col, sticky="ew", padx=padx)
        tk.Label(cell, text=label, bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        self._entry(cell, var, width=0, show=show).pack(fill="x", pady=(2, 0))

    def _entry(self, parent, var, width=18, show=""):
        return tk.Entry(
            parent, textvariable=var, width=width, show=show,
            bg=BG_MID, fg=FG_MAIN, insertbackground=ACCENT,
            relief="flat", font=FONT_UI,
            highlightthickness=1, highlightbackground=BORDER,
            highlightcolor=ACCENT,
        )

    # ------------------------------------------------------------------
    # Config load/save
    # ------------------------------------------------------------------

    def _load_config_to_ui(self):
        c = self._config
        self._url_var.set(c.get('url', ''))
        self._api_key_var.set(c.get('api_key', ''))
        self._export_var.set(c.get('export_folder', ''))
        self._copy_var.set(c.get('copyright_text', ''))
        self._throttle_var.set(c.get('import_delay', '0.5'))
        self._offpeak_var.set(c.get('offpeak_only', 'false').lower() == 'true')
        self._peak_start_var.set(c.get('peak_start', '9'))
        self._peak_end_var.set(c.get('peak_end', '23'))
        self._apply_offpeak_toggle()  # show/hide hours frame to match loaded state

    def _save_config(self):
        win_state = self.state()
        cfg_module.save({
            'url':             self._url_var.get().strip(),
            'api_key':         self._api_key_var.get(),
            'export_folder':   self._export_var.get().strip(),
            'copyright_text':  self._copy_var.get().strip(),
            'import_delay':    self._throttle_var.get(),
            'offpeak_only':    'true' if self._offpeak_var.get() else 'false',
            'peak_start':      self._peak_start_var.get(),
            'peak_end':        self._peak_end_var.get(),
            'window_state':    win_state,
            # Only save normal-mode geometry; zoomed geometry is wrong on restore
            'window_geometry': self.geometry() if win_state != 'zoomed' else '',
        })

    # ------------------------------------------------------------------
    # Browse
    # ------------------------------------------------------------------

    # ------------------------------------------------------------------
    # Config drawer
    # ------------------------------------------------------------------

    def _collapse_config(self):
        """Hide config. If posts are loaded, show the grid section."""
        if self._cfg_visible:
            self._cfg_frame.pack_forget()
            self._cfg_arrow.configure(text="▼  CONFIGURATION")
            self._cfg_visible = False
        if self._posts and not self._grid_section.winfo_ismapped():
            self._grid_section.pack(fill="both", expand=True,
                                    before=self._bottom_bar)

    def _expand_config(self):
        """Show config. If posts are loaded, config is a drawer above the grid;
        otherwise it fills the whole window."""
        if not self._cfg_visible:
            if self._posts:
                # Drawer mode — grid stays visible below
                self._cfg_frame.pack(fill="x", padx=14, pady=10,
                                     before=self._grid_section)
            else:
                # Full-screen mode — no grid yet
                self._cfg_frame.pack(fill="both", expand=True,
                                     before=self._bottom_bar)
            self._cfg_arrow.configure(text="▲  CONFIGURATION")
            self._cfg_visible = True

    def _toggle_config(self, e=None):
        if self._cfg_visible:
            self._collapse_config()
        else:
            self._expand_config()

    def _apply_offpeak_toggle(self):
        if self._offpeak_var.get():
            self._offpeak_hours_frame.pack(side="left")
        else:
            self._offpeak_hours_frame.pack_forget()

    def _browse_export(self):
        p = filedialog.askdirectory(title="Select Instagram export folder")
        if p:
            self._export_var.set(p)

    # ------------------------------------------------------------------
    # View switching
    # ------------------------------------------------------------------

    def _on_cell_click(self, index: int):
        if not self._posts:
            return
        self._detail_index = index
        self._grid.pack_forget()
        self._detail.pack(fill="both", expand=True)
        self._detail.show(self._posts[index], index, len(self._posts))
        self._current_view = 'detail'

    def _show_grid(self):
        self._detail.pack_forget()
        self._grid.pack(fill="both", expand=True)
        self._current_view = 'grid'

    def _detail_prev(self):
        if self._detail_index > 0:
            self._detail_index -= 1
            self._detail.show(self._posts[self._detail_index],
                             self._detail_index, len(self._posts))

    def _detail_next(self):
        if self._detail_index < len(self._posts) - 1:
            self._detail_index += 1
            self._detail.show(self._posts[self._detail_index],
                             self._detail_index, len(self._posts))

    # ------------------------------------------------------------------
    # Parse export
    # ------------------------------------------------------------------

    def _prompt_job_name(self, export_folder: str) -> str:
        """
        Try to derive a job name from the folder.  If that fails, show a
        small dialog and ask the user.  Returns a non-empty string.
        """
        name = job_state.parse_job_name(export_folder)
        if name:
            return name
        # Fallback — ask
        dlg = tk.Toplevel(self)
        dlg.title("Job name")
        dlg.resizable(False, False)
        dlg.grab_set()
        dlg.transient(self)
        dlg.configure(bg=BG_DEEP)
        tk.Label(dlg, text="Could not detect account name from folder.\nEnter a job name:",
                 bg=BG_DEEP, fg=FG_MAIN, font=FONT_SMALL, justify="left").pack(padx=14, pady=(12, 6))
        var = tk.StringVar()
        e = tk.Entry(dlg, textvariable=var, bg=BG_MID, fg=FG_MAIN,
                     insertbackground=ACCENT, relief="flat", font=FONT_UI,
                     highlightthickness=1, highlightbackground=BORDER)
        e.pack(fill="x", padx=14, pady=(0, 8))
        e.focus_set()
        result: list = [os.path.basename(export_folder.rstrip('/\\')) or 'job']
        def _ok():
            v = var.get().strip()
            if v:
                result[0] = v
            dlg.destroy()
        tk.Button(dlg, text="OK", command=_ok,
                  bg=ACCENT, fg=BG_DEEP, relief="flat", font=FONT_BOLD,
                  cursor="hand2").pack(padx=14, pady=(0, 12))
        e.bind("<Return>", lambda _: _ok())
        dlg.update_idletasks()
        rx = self.winfo_rootx() + (self.winfo_width() - dlg.winfo_reqwidth()) // 2
        ry = self.winfo_rooty() + (self.winfo_height() - dlg.winfo_reqheight()) // 2
        dlg.geometry(f"+{rx}+{ry}")
        dlg.wait_window()
        return result[0]

    def _on_parse(self):
        export_folder = self._export_var.get().strip()
        if not export_folder:
            messagebox.showerror("No folder", "Select an Instagram export folder.")
            return

        self._set_status("Parsing…", FG_WARN)
        self.update_idletasks()

        result = ig_parser.parse(export_folder)

        for err in result.errors:
            self._set_status(f"Parse: {err}", FG_ERR)

        if not result.posts:
            messagebox.showerror("Empty export", "No valid posts found in the export.")
            return

        self._posts = result.posts

        # ── Job state: check for an existing save for this folder ────
        existing = job_state.JobState.find_for_folder(export_folder)
        resume   = False
        if existing and existing.has_progress:
            resume = messagebox.askyesno(
                "Resume job?",
                f"A saved job \"{existing.job_name}\" exists for this folder "
                f"with {existing.upload_count} post(s) already uploaded.\n\n"
                f"Resume from where you left off?"
            )
            if not resume:
                existing.delete()
                existing = None

        if existing and resume:
            self._job = existing
            # Replay the saved post ordering so grid indices match saved trigrams.
            # The parser always produces posts in original_index order; we need to
            # re-sort them into the reordered sequence that was saved at lock time.
            if self._job.ordering:
                orig_map = {p.original_index: p for p in self._posts}
                reordered = [orig_map[oi] for oi in self._job.ordering
                             if oi in orig_map]
                # Append any posts absent from saved ordering (shouldn't happen,
                # but guard against export folder being re-used with new content)
                seen = set(self._job.ordering)
                reordered += [p for p in self._posts
                              if p.original_index not in seen]
                self._posts = reordered
        else:
            # Create a fresh job
            job_name     = self._prompt_job_name(export_folder)
            site_url     = self._url_var.get().strip()
            self._job    = job_state.JobState(job_name, export_folder, site_url)
            self._job.save()

        self._unload_btn.configure(state="normal")

        self._clear_trigram_state()

        # Restore trigram group counter from persisted groups
        if self._job.trigrams:
            self._tg_group_ctr = max(g['num'] for g in self._job.trigrams)
            for grp in self._job.trigrams:
                self._tg_groups.append(dict(grp))

        # Collapse config first so the grid canvas is visible and has real
        # geometry before load() calls winfo_width(). Without this, winfo_width()
        # returns 0 and cells are sized against the fallback WIN_W constant.
        self._collapse_config()
        self.update_idletasks()

        self._grid.load(self._posts)

        # Restore persisted cell state (excluded, uploaded status, trigram badges)
        if resume:
            self._grid.restore_state(
                self._job.uploaded,
                self._job.excluded,
                self._job.trigrams,
            )
            # Re-apply trigram labels with correct group nums
            for grp in self._tg_groups:
                self._grid.set_trigram_cells(grp['num'], grp['indices'], grp['slots'])

        s = result.stats
        self._grid_lbl.configure(
            text=f"POSTS — {s['total_posts']}  "
                 f"({s['carousel_posts']} carousel, {s['single_posts']} single)  "
                 f"·  {s['total_images']} images"
        )
        self._update_tg_label()

        self._progress['maximum'] = len(self._posts)
        uploaded_count = len(self._job.uploaded)
        self._prog_var.set(uploaded_count)
        self._prog_lbl.configure(text=f"{uploaded_count} / {len(self._posts)}")
        resume_note = f"  ({uploaded_count} already uploaded)" if resume else ""
        self._set_status(
            f"Parsed {s['total_posts']} posts.{resume_note} "
            f"Right-click a cell to exclude or start a trigram group. "
            f"Ctrl+click 3 posts to group them.",
            FG_MAIN
        )
        log.info(
            f"[{self._job.job_name}] Parsed {s['total_posts']} posts "
            f"({s['carousel_posts']} carousel, {s['single_posts']} single, "
            f"{s['total_images']} images) from {export_folder}"
            + (f" — resuming, {uploaded_count} already done" if resume else "")
        )
        self._save_config()

    # ------------------------------------------------------------------
    # Connect
    # ------------------------------------------------------------------

    def _on_connect(self):
        url     = self._url_var.get().strip()
        api_key = self._api_key_var.get().strip()

        if not url or not api_key:
            messagebox.showerror("Missing credentials", "Fill in Site URL and API Key.")
            return

        self._set_status("Connecting…", FG_WARN)
        self._conn_dot.configure(fg=FG_WARN)
        self._conn_lbl.configure(text="Connecting…", fg=FG_WARN)
        self.update_idletasks()

        try:
            client    = UnzuckerClient(url, api_key)
            ok, msg   = client.ping()

            if not ok:
                raise RuntimeError(msg)

            site_data = client.fetch_site_data()

            self._client    = client
            self._site_data = site_data

            cats   = sorted(site_data._cat_display.values())
            albums = sorted(site_data._album_display.values())

            self._conn_dot.configure(fg=FG_OK)
            self._conn_lbl.configure(
                text=f"Connected — {len(cats)} cats, {len(albums)} albums", fg=FG_OK)
            self._set_status("Connected. Ready to transfer & post.", FG_OK)
            log.info(f"Connected to {url} — {len(cats)} cats, {len(albums)} albums")
            self._save_config()

        except Exception as e:
            self._conn_dot.configure(fg=FG_ERR)
            self._conn_lbl.configure(text="Connection failed", fg=FG_ERR)
            self._set_status(f"Error: {e}", FG_ERR)
            log.error(f"Connection failed to {url}: {e}")
            messagebox.showerror("Connection failed", str(e))

    # ------------------------------------------------------------------
    # Validate
    # ------------------------------------------------------------------

    def _on_validate(self):
        if not self._posts:
            messagebox.showerror("Nothing loaded", "Parse an export first.")
            return

        issues = []
        for post in self._posts:
            if post.excluded:
                continue
            for img in post.images:
                if not os.path.isfile(img):
                    issues.append(f"Missing: {img}")

        if not issues:
            active = sum(1 for p in self._posts if not p.excluded)
            self._set_status(f"✓ {active} posts validated OK.", FG_OK)
            messagebox.showinfo("Validation passed", f"All {active} posts look good.")
        else:
            self._set_status(f"{len(issues)} issues found.", FG_WARN)
            messagebox.showwarning("Issues found",
                                   "\n".join(issues[:20]) +
                                   (f"\n…and {len(issues) - 20} more" if len(issues) > 20 else ""))

    # ------------------------------------------------------------------
    # Transfer & Post
    # ------------------------------------------------------------------

    def _on_post(self):
        if self._posting:
            return
        if not self._posts:
            messagebox.showerror("Nothing loaded", "Parse an export first.")
            return
        if not self._client or not self._site_data:
            messagebox.showinfo("Not connected", "Click Connect first.")
            return

        # Build active list and an original→active index map
        active_with_orig = [(i, p) for i, p in enumerate(self._posts) if not p.excluded]
        if not active_with_orig:
            messagebox.showerror("Nothing to post", "All posts are excluded.")
            return
        active = [p for _, p in active_with_orig]
        orig_to_active = {orig: act for act, (orig, _) in enumerate(active_with_orig)}

        # Remap trigram group indices (original → active)
        remapped_groups = []
        for grp in self._tg_groups:
            mapped = [orig_to_active.get(i, -1) for i in grp['indices']]
            if any(m < 0 for m in mapped):
                continue  # a post in this group is excluded — skip
            remapped_groups.append({
                'indices':     mapped,
                'slots':       grp['slots'],
                'orientation': grp['orientation'],
            })

        count = len(active)
        tg_note = f"\n\n{len(remapped_groups)} trigram group{'s' if len(remapped_groups) != 1 else ''} will be linked." if remapped_groups else ""
        if not messagebox.askyesno(
            "Confirm migration",
            f"Transfer & post {count} post{'s' if count != 1 else ''} to SnapSmack?\n\n"
            f"Images will be uploaded via HTTPS and posts created via the API.{tg_note}",
        ):
            return

        self._set_posting(True)
        self._prog_var.set(0)
        self._progress['maximum'] = count
        self._prog_lbl.configure(text=f"0 / {count}")
        self._set_status("Migrating…", FG_WARN)
        self._save_config()

        # Make sure we're on the grid view during posting
        if self._current_view == 'detail':
            self._show_grid()

        staging_dir = tempfile.mkdtemp(prefix='unzucker_')

        try:
            post_delay = float(self._throttle_var.get())
        except (ValueError, TypeError):
            post_delay = 0.5

        offpeak_only = self._offpeak_var.get()
        try:
            peak_start = int(self._peak_start_var.get())
        except (ValueError, TypeError):
            peak_start = 9
        try:
            peak_end = int(self._peak_end_var.get())
        except (ValueError, TypeError):
            peak_end = 23

        thread = threading.Thread(
            target=self._post_thread,
            args=(active, staging_dir, count, remapped_groups,
                  post_delay, offpeak_only, peak_start, peak_end),
            daemon=True,
        )
        thread.start()

    def _post_thread(self, posts, staging_dir, total, trigram_groups=None,
                     post_delay=0.5, offpeak_only=False, peak_start=9, peak_end=23):
        def on_progress(current, total, result):
            self._msg_queue.put(('progress', current, total, result))

        def on_wait(resume_hour):
            self._msg_queue.put(('waiting', resume_hour))

        poster_module.run_migration(
            client=self._client,
            posts=posts,
            site_data=self._site_data,
            staging_dir=staging_dir,
            default_category='',
            default_album='',
            copyright_text=self._copy_var.get().strip(),
            on_progress=on_progress,
            trigram_groups=trigram_groups or [],
            post_delay=post_delay,
            offpeak_only=offpeak_only,
            peak_start=peak_start,
            peak_end=peak_end,
            on_wait=on_wait,
        )
        self._msg_queue.put(('done', total))

        # Clean up staging dir
        try:
            import shutil
            shutil.rmtree(staging_dir, ignore_errors=True)
        except Exception:
            pass

    def _poll_queue(self):
        try:
            while True:
                msg = self._msg_queue.get_nowait()
                if msg[0] == 'progress':
                    _, current, total, result = msg
                    self._prog_var.set(current)
                    self._prog_lbl.configure(text=f"{current} / {total}")

                    # Update grid cell status
                    status = 'ok' if result.success else 'error'
                    if result.message.startswith("Skipped"):
                        status = 'skip'
                    self._grid.set_cell_status(result.post_index, status)

                    # Persist successful uploads to job state — includes
                    # server-confirmed duplicates so they don't get retried.
                    if (self._job and result.success
                            and getattr(result, 'post_id', 0)):
                        post_id = result.post_id
                        self._job.record_uploaded(result.post_index, post_id)

                    icon  = '✓' if result.success else '✗'
                    color = FG_OK   if result.success else FG_ERR
                    self._set_status(
                        f"{icon}  Post {current}/{total} — {result.message}",
                        color,
                    )

                elif msg[0] == 'waiting':
                    resume_hour = msg[1]
                    self._set_status(
                        f"⏸  Off-peak only — resuming at {resume_hour:02d}:00…", FG_WARN)

                elif msg[0] == 'done':
                    total = msg[1]
                    self._set_posting(False)
                    self._set_status(f"Migration complete — {total} processed.", FG_OK)
                    log.info(f"Migration complete — {total} posts processed")
        except queue.Empty:
            pass
        self.after(100, self._poll_queue)

    # ------------------------------------------------------------------
    # Trigram group management
    # ------------------------------------------------------------------

    def _on_ctrl_click(self, index: int):
        """Handle Ctrl+click — add or remove from active selection."""
        if self._posting:
            return

        # Already locked in a group?  Treat as a remove request.
        for grp in self._tg_groups:
            if index in grp['indices']:
                self._remove_trigram(index)
                return

        # Toggle selection
        if index in self._tg_selection:
            self._tg_selection.remove(index)
            self._grid.set_selecting_cells([index], False)
        else:
            if len(self._tg_selection) >= 3:
                self._set_status("A trigram needs exactly 3 posts. Deselect one first.", FG_WARN)
                return
            self._tg_selection.append(index)
            self._grid.set_selecting_cells([index], True)

        if len(self._tg_selection) == 3:
            # Open the slot assignment panel
            self._open_trigram_panel(list(self._tg_selection))

    def _on_right_click_cb(self, action: str, index: int):
        """Callback from PostGrid canvas right-click context menu."""
        if action == 'add':
            self._on_ctrl_click(index)
        elif action == 'remove':
            self._remove_trigram(index)
        elif action == 'excluded_changed':
            if self._job:
                excluded = {i for i, p in enumerate(self._posts) if p.excluded}
                self._job.set_excluded(excluded)

    def _open_trigram_panel(self, indices: list):
        """Open TrigramPanel for slot assignment."""
        if not self._posts:
            return
        posts = [self._posts[i] for i in indices]
        # Clear selection rings before panel opens
        self._grid.set_selecting_cells(indices, False)
        self._tg_selection.clear()
        TrigramPanel(
            self,
            posts=posts,
            indices=indices,
            on_lock=self._on_trigram_lock,
        )
        # No wait_window — grab_set() in TrigramPanel makes it modal.
        # Returning here lets tkinter finish rendering the panel before
        # the user interacts; the on_lock callback fires when they hit LOCK.

    def _on_trigram_lock(self, indices: list, slots: list):
        """Called when user clicks LOCK in TrigramPanel."""
        self._tg_group_ctr += 1
        group = {
            'indices':     indices,
            'slots':       slots,
            'orientation': 'h',
            'num':         self._tg_group_ctr,
        }
        self._tg_groups.append(group)
        # Defer the grid reload so it fires after the panel is fully destroyed.
        # This ensures winfo_width() returns the correct canvas geometry and
        # avoids doing heavy widget churn inside the panel's event handler.
        self.after(0, self._finish_trigram_lock)

    def _finish_trigram_lock(self):
        """Deferred: reorder + reload grid after TrigramPanel is gone."""
        grp = self._tg_groups[-1]
        self._reorder_posts_for_trigram(grp['indices'])
        self._update_tg_label()
        if self._job:
            self._job.save_trigrams(self._tg_groups)
        new_idx = self._tg_groups[-1]['indices']
        self._set_status(
            f"Trigram T{self._tg_group_ctr} locked "
            f"({new_idx[0]+1}, {new_idx[1]+1}, {new_idx[2]+1}).",
            FG_OK,
        )

    def _reorder_posts_for_trigram(self, lmr_indices: list):
        """
        Move the three trigram posts to a row-aligned run in L/M/R order and
        reload the grid.  All existing trigram group indices are remapped to
        match the new post order.
        """
        old_posts = list(self._posts)
        lmr_set   = set(lmr_indices)

        # Row-align: put L at the first cell of the row that contains min(lmr_indices)
        row_start  = (min(lmr_indices) // GRID_COLS) * GRID_COLS

        # Posts that are NOT in this trigram, preserving order
        other_posts = [p for i, p in enumerate(old_posts) if i not in lmr_set]

        # L/M/R posts in slot order
        lmr_posts   = [old_posts[i] for i in lmr_indices]

        # Adjust insertion point: some lmr_indices may be < row_start and
        # have already been removed from other_posts, shifting it left.
        lmr_before  = sum(1 for i in lmr_indices if i < row_start)
        adj_start   = row_start - lmr_before

        new_posts   = other_posts[:adj_start] + lmr_posts + other_posts[adj_start:]

        # Build post-identity → new-index map
        new_idx_map = {id(p): ni for ni, p in enumerate(new_posts)}

        # Remap every group's indices (including the one we just appended)
        for grp in self._tg_groups:
            grp['indices'] = [new_idx_map[id(old_posts[oi])] for oi in grp['indices']]

        self._posts = new_posts
        if self._job:
            self._job.save_ordering([p.original_index for p in self._posts])
        self._grid.reorder(self._posts)

        # Re-apply all trigram badges with updated indices
        for grp in self._tg_groups:
            self._grid.set_trigram_cells(grp['num'], grp['indices'], grp['slots'])

    def _remove_trigram(self, index: int):
        """Remove the trigram group containing index."""
        for grp in list(self._tg_groups):
            if index in grp['indices']:
                self._grid.clear_trigram_cells(grp['indices'])
                self._tg_groups.remove(grp)
                self._update_tg_label()
                if self._job:
                    self._job.save_trigrams(self._tg_groups)
                self._set_status("Trigram group removed.", FG_DIM)
                return

    def _update_tg_label(self):
        n = len(self._tg_groups)
        self._tg_lbl.configure(
            text=f"  {n} trigram{'s' if n != 1 else ''}" if n > 0 else ""
        )

    def _clear_trigram_state(self):
        """Clear all trigram groups and selection (called on new parse)."""
        for grp in self._tg_groups:
            self._grid.clear_trigram_cells(grp['indices'])
        self._tg_groups.clear()
        self._tg_selection.clear()
        self._tg_group_ctr = 0
        self._update_tg_label()

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------

    def _unload_job(self):
        """Discard the current job state and clear the grid."""
        if self._posting:
            messagebox.showwarning("Busy", "Cannot unload while a migration is running.")
            return
        if not self._job:
            return
        if not messagebox.askyesno(
            "Unload job",
            f"Unload job \"{self._job.job_name}\"?\n\n"
            f"The saved progress file will be deleted. "
            f"Uploaded posts on your site are NOT affected.",
        ):
            return
        self._job.delete()
        self._job = None
        self._posts.clear()
        self._clear_trigram_state()
        self._grid.clear()
        self._unload_btn.configure(state="disabled")
        self._grid_lbl.configure(text="POSTS — 0")
        self._progress['maximum'] = 1
        self._prog_var.set(0)
        self._prog_lbl.configure(text="")
        self._set_status("Job unloaded.", FG_DIM)
        # Collapse grid section; show config
        if self._grid_section.winfo_ismapped():
            self._grid_section.pack_forget()
        self._expand_config()

    def _on_close(self):
        self._save_config()
        self.destroy()

    def _set_status(self, text: str, color: str = FG_DIM):
        self._status_lbl.configure(text=text, fg=color)

    def _set_posting(self, posting: bool):
        self._posting = posting
        state = "disabled" if posting else "normal"
        if posting:
            self._post_canvas.itemconfig(self._post_text, fill=FG_DIM)
            self._post_canvas.itemconfig(self._post_rect, fill=BG_MID)
            self._post_canvas.configure(cursor="")
            self._post_canvas.unbind("<Button-1>")
        else:
            self._post_canvas.itemconfig(self._post_text, fill=BG_DEEP)
            self._post_canvas.itemconfig(self._post_rect, fill=ACCENT)
            self._post_canvas.configure(cursor="hand2")
            self._post_canvas.bind("<Button-1>", lambda e: self._on_post())
        self._validate_btn.configure(state=state)
        self._connect_btn.configure(state=state)
        self._parse_btn.configure(state=state)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    app = App()
    app.mainloop()
# ===== SNAPSMACK EOF =====
