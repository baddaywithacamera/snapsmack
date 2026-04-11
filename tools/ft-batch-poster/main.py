"""
Smack Your Batch Up — main.py
SnapSmack batch image posting tool.
Admin-styled desktop app with thumbnail queue, drag reorder,
per-row category/album editing, and Google Drive upload.
"""

BUILD_VERSION = "0.7.7a-05"   # bump this on every rebuild

import os
import queue
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, simpledialog, ttk
from typing import List, Optional

from PIL import Image, ImageTk

import config as cfg_module
import drive as drive_module
import gemini as gemini_module
import manifest_parser
import poster as poster_module
from manifest_parser import ManifestEntry
from poster import SnapSmackClient, SiteData


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
    'posting':  (ACCENT,    "#000000"),
    'ok':       (FG_OK,     "#000000"),
    'error':    (FG_ERR,    "#000000"),
    'warning':  (FG_WARN,   "#000000"),
}

THUMB_SIZE  = (72, 72)
ROW_HEIGHT  = 108       # px per entry row (taller to fit inline editing)
WIN_W, WIN_H = 1020, 920
FONT_UI      = ("Segoe UI", 9)
FONT_BOLD    = ("Segoe UI", 9, "bold")
FONT_SMALL   = ("Segoe UI", 8)
FONT_MONO    = ("Consolas", 9)
FONT_TITLE   = ("Segoe UI", 13, "bold")

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

        self._build(cats, albums, on_drag_start, on_drag_motion, on_drag_end)

    def _build(self, cats, albums, on_drag_start, on_drag_motion, on_drag_end):
        self.configure(height=ROW_HEIGHT)

        # ── Drag handle ───────────────────────────────────────────────
        self._handle = tk.Label(
            self, text="⠿", bg=BG_CARD, fg=FG_DIM,
            font=("Segoe UI", 14), cursor="fleur", padx=6,
        )
        self._handle.place(x=0, y=0, width=28, height=ROW_HEIGHT)
        self._handle.bind("<ButtonPress-1>",   on_drag_start)
        self._handle.bind("<B1-Motion>",        on_drag_motion)
        self._handle.bind("<ButtonRelease-1>", on_drag_end)

        # ── Thumbnail ─────────────────────────────────────────────────
        self._thumb_lbl = tk.Label(
            self, bg="#0A0A0E", relief="flat",
            text="…", fg=FG_DIM, font=FONT_SMALL,
        )
        self._thumb_lbl.place(x=30, y=18, width=THUMB_SIZE[0], height=THUMB_SIZE[1])

        # ── File name ─────────────────────────────────────────────────
        tk.Label(
            self, text=self.entry.file, bg=BG_CARD, fg=FG_MAIN,
            font=FONT_BOLD, anchor="w",
        ).place(x=110, y=6, width=830, height=16)

        # ── Inline title entry ────────────────────────────────────────
        tk.Label(self, text="title", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=110, y=24)
        self._title_var = tk.StringVar(value=self.entry.title)
        self._title_entry = tk.Entry(
            self, textvariable=self._title_var,
            bg=BG_MID, fg=FG_MAIN, insertbackground=ACCENT,
            relief="flat", font=FONT_SMALL, bd=0,
            highlightthickness=1, highlightbackground=BORDER, highlightcolor=ACCENT,
        )
        self._title_entry.place(x=145, y=22, width=695, height=20)
        self._title_var.trace_add("write", lambda *a: setattr(self.entry, 'title', self._title_var.get()))

        # ── Inline tags entry ─────────────────────────────────────────
        tk.Label(self, text="tags", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=110, y=50)
        self._tags_var = tk.StringVar(value=self.entry.tags)
        self._tags_entry = tk.Entry(
            self, textvariable=self._tags_var,
            bg=BG_MID, fg=FG_DIM, insertbackground=ACCENT,
            relief="flat", font=FONT_SMALL, bd=0,
            highlightthickness=1, highlightbackground=BORDER, highlightcolor=ACCENT,
        )
        self._tags_entry.place(x=145, y=48, width=695, height=20)
        self._tags_var.trace_add("write", lambda *a: setattr(self.entry, 'tags', self._tags_var.get()))

        # ── Category combobox ─────────────────────────────────────────
        self._cat_var = tk.StringVar(value=self.entry.category)
        self._cat_cb = ttk.Combobox(
            self, textvariable=self._cat_var, values=[''] + cats,
            font=FONT_SMALL, state="normal",
        )
        self._cat_cb.place(x=110, y=78, width=180)
        self._cat_cb.bind("<<ComboboxSelected>>",
                    lambda e: setattr(self.entry, 'category', self._cat_var.get()))
        self._cat_var.trace_add("write",
                    lambda *a: setattr(self.entry, 'category', self._cat_var.get()))

        tk.Label(self, text="cat", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=110, y=66)

        # ── Album combobox ────────────────────────────────────────────
        self._album_var = tk.StringVar(value=self.entry.album)
        self._album_cb = ttk.Combobox(
            self, textvariable=self._album_var, values=[''] + albums,
            font=FONT_SMALL, state="normal",
        )
        self._album_cb.place(x=300, y=78, width=180)
        self._album_cb.bind("<<ComboboxSelected>>",
                      lambda e: setattr(self.entry, 'album', self._album_var.get()))
        self._album_var.trace_add("write",
                      lambda *a: setattr(self.entry, 'album', self._album_var.get()))

        tk.Label(self, text="album", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=300, y=66)

        # ── Orientation combobox ─────────────────────────────────────
        orient_display = {'auto': 'Auto', '0': 'Landscape', '1': 'Portrait', '2': 'Square'}
        display_val = orient_display.get(self.entry.orientation, 'Auto')
        self._orient_var = tk.StringVar(value=display_val)
        self._orient_cb = ttk.Combobox(
            self, textvariable=self._orient_var,
            values=['Auto', 'Landscape', 'Portrait', 'Square'],
            font=FONT_SMALL, state="readonly", width=9,
        )
        self._orient_cb.place(x=490, y=78, width=100)
        orient_reverse = {'Auto': 'auto', 'Landscape': '0', 'Portrait': '1', 'Square': '2'}
        self._orient_cb.bind("<<ComboboxSelected>>",
                      lambda e: setattr(self.entry, 'orientation',
                                        orient_reverse.get(self._orient_var.get(), 'auto')))

        tk.Label(self, text="orient", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=490, y=66)

        # ── Colour swatches (filled by Gemini) ────────────────────────
        tk.Label(self, text="colors", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=610, y=66)
        self._swatch_labels = []
        for i in range(3):
            sw = tk.Label(self, bg=BG_CARD, relief="flat", width=4,
                          cursor="hand2", font=FONT_SMALL)
            sw.place(x=610 + i * 46, y=78, width=40, height=20)
            self._swatch_labels.append(sw)
        self._update_swatches(self.entry.colors)

        # ── Status badge ──────────────────────────────────────────────
        self._status_lbl = tk.Label(
            self, text="PENDING", font=("Segoe UI", 7, "bold"),
            padx=6, pady=2, relief="flat",
        )
        self._status_lbl.place(x=868, y=42, width=72, height=20)
        self._set_status_visual('pending')

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
            'pending': "PENDING",
            'posting': "POSTING",
            'ok':      "  POSTED",
            'error':   "  ERROR",
            'warning': "  WARN",
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

    def get_row(self, index: int) -> Optional['EntryRow']:
        if 0 <= index < len(self._rows):
            return self._rows[index]
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
# Main application window
# ---------------------------------------------------------------------------

class App(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title(f"SMACK YOUR BATCH UP  —  build {BUILD_VERSION}")
        self.geometry(f"{WIN_W}x{WIN_H}")
        self.minsize(860, 600)
        self.configure(bg=BG_DEEP)

        # State
        self._config        = cfg_module.load()
        self._client:       Optional[SnapSmackClient] = None
        self._site_data:    Optional[SiteData]        = None
        self._drive_service = None
        self._posting           = False
        self._keepalive_running = False
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
            title_cluster, text="SMACK YOUR BATCH UP",
            bg=BG_CARD, fg=ACCENT, font=FONT_TITLE,
        )
        self._title_lbl.pack(side="left", anchor="center")
        tk.Label(title_cluster, text=BUILD_VERSION,
                 bg=BG_CARD, fg=FG_DIM,
                 font=("Segoe UI", 8)).pack(side="left", padx=(8, 0), anchor="center")

        # Right: Help button
        ttk.Button(header, text="?  Help", style="Ghost.TButton",
                   command=self._show_help).pack(side="right", padx=14, pady=6)

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

        # ── Config collapse toggle bar ────────────────────────────────
        self._cfg_visible = True
        cfg_toggle = tk.Frame(self, bg=BG_DEEP, height=26, cursor="hand2")
        cfg_toggle.pack(fill="x")
        cfg_toggle.pack_propagate(False)
        self._cfg_arrow = tk.Label(cfg_toggle, text="▲  CONFIGURATION",
                                   bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                                   cursor="hand2")
        self._cfg_arrow.pack(side="left", padx=14, pady=4)
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # ── Config area ───────────────────────────────────────────────
        self._cfg_frame = tk.Frame(self, bg=BG_DEEP)
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

        # Two-column grid: CONNECTION (left) | MANIFEST & DEFAULTS (right)
        cols = tk.Frame(cfg, bg=BG_DEEP)
        cols.pack(fill="x")
        cols.columnconfigure(0, weight=1)
        cols.columnconfigure(1, weight=1)

        # ── Box: CONNECTION ───────────────────────────────────────────
        self._url_var  = tk.StringVar()
        self._user_var = tk.StringVar()
        self._pass_var = tk.StringVar()
        self._rem_var  = tk.BooleanVar()

        conn_box  = self._box(cols, "CONNECTION")
        conn_box.grid(row=0, column=0, sticky="nsew", padx=(0, 7))
        conn_body = self._box_body(conn_box)

        self._field(conn_body, "SITE URL", self._url_var)

        cred_cols = tk.Frame(conn_body, bg=BG_CARD)
        cred_cols.pack(fill="x", pady=(0, 8))
        cred_cols.columnconfigure(0, weight=1)
        cred_cols.columnconfigure(1, weight=1)
        self._field_in(cred_cols, "USERNAME",  self._user_var, row=0, col=0, padx=(0, 6))
        self._field_in(cred_cols, "PASSWORD",  self._pass_var, row=0, col=1, show="•")

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
        self._queue_rule = tk.Frame(self, bg=BORDER, height=1)
        self._queue_hdr  = tk.Frame(self, bg=BG_DEEP, height=30)
        self._queue_hdr.pack_propagate(False)
        self._queue_lbl = tk.Label(
            self._queue_hdr, text="QUEUE — 0 ITEMS",
            bg=BG_DEEP, fg=FG_DIM, font=FONT_BOLD,
        )
        self._queue_lbl.pack(side="left", padx=14, pady=6)
        self._queue_sep  = tk.Frame(self, bg=BORDER, height=1)

        # ── Entry list ────────────────────────────────────────────────
        self._entry_list = EntryList(self)

        # Queue section is hidden while config is open; shown when config collapses
        # (pack() calls intentionally omitted — _toggle_cfg manages visibility)

        # ── Bottom action bar ─────────────────────────────────────────
        # Stored as instance vars so _toggle_cfg can insert queue items before them.
        self._bottom_sep = tk.Frame(self, bg=BORDER, height=1)
        self._bottom_sep.pack(fill="x")
        self._bottom_bar = tk.Frame(self, bg=BG_CARD, height=52)
        self._bottom_bar.pack(fill="x")
        bottom = self._bottom_bar
        bottom.pack_propagate(False)

        self._validate_btn = ttk.Button(bottom, text="Validate", style="Ghost.TButton",
                                         command=self._on_validate)
        self._validate_btn.pack(side="left", padx=(14, 6), pady=10)

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
        self._post_canvas.bind("<Enter>", lambda e: self._post_canvas.itemconfig(
            self._post_rect, fill=self._lighten(self._post_canvas.itemcget(self._post_rect, 'fill'))))
        self._post_canvas.bind("<Leave>", lambda e: self._post_canvas.itemconfig(
            self._post_rect, fill=ACCENT))

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
        self._user_var.set(c.get('username', ''))
        self._pass_var.set(c.get('password', ''))
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
        username = c.get('username', '').strip()
        password = c.get('password', '')
        if url and username and password:
            self._conn_dot.configure(fg=LED_WARN)
            self._conn_lbl.configure(text="CONNECTING...", fg=LED_WARN)
            def _site_thread():
                try:
                    client = SnapSmackClient(url)
                    client.login(username, password)
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
                        self._start_session_timer()
                        self._start_keepalive()
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
            'username':           self._user_var.get().strip(),
            'password':           self._pass_var.get(),
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
        p = filedialog.askdirectory(
            title="Select image folder",
            initialdir=init if init and os.path.isdir(init) else None,
        )
        if p:
            self._folder_var.set(p)
            self._save_config()

    def _browse_manifest(self):
        init = self._manifest_var.get().strip()
        init_dir = os.path.dirname(init) if init and os.path.isfile(init) else None
        p = filedialog.askopenfilename(
            title="Select manifest file",
            initialdir=init_dir,
            filetypes=[("Text files", "*.txt"), ("All files", "*.*")],
        )
        if p:
            self._manifest_var.set(p)
            self._save_config()

    def _browse_creds(self):
        p = filedialog.askopenfilename(
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
        url  = self._url_var.get().strip()
        user = self._user_var.get().strip()
        pw   = self._pass_var.get()

        if not url or not user or not pw:
            messagebox.showerror("Missing credentials", "Fill in Site URL, Username, and Password.")
            return

        self._set_status("Connecting…", FG_WARN)
        self._conn_dot.configure(fg=LED_WARN)
        self._conn_lbl.configure(text="CONNECTING...", fg=LED_WARN)
        self.update_idletasks()

        try:
            client    = SnapSmackClient(url)
            client.login(user, pw)
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
            self._start_session_timer()
            self._start_keepalive()
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

        self._entry_list.load(parse_result.entries, image_folder, cats, albums)
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

        self._entry_list.load(entries, image_folder, cats, albums)
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

        entries = self._entry_list.get_entries()
        if not entries:
            messagebox.showerror("Nothing loaded", "Load images or a manifest first.")
            return

        image_folder = self._folder_var.get().strip()
        if not image_folder:
            messagebox.showerror("No folder", "Select an image folder first.")
            return

        if not gemini_module.is_available():
            messagebox.showerror(
                "Missing library",
                "google-generativeai is not installed.\n\nRun: pip install google-generativeai",
            )
            return

        site_data     = self._site_data
        cats          = list(site_data.categories.keys()) if site_data else []
        albums        = list(site_data.albums.keys())     if site_data else []
        custom_prompt = self._gem_prompt_txt.get("1.0", "end").strip()

        self._enrich_btn.configure(state="disabled")
        self._set_status(f"Enriching with Gemini — 0 / {len(entries)}…", FG_WARN)

        def _enrich_thread():
            def _progress(idx, total, entry, error):
                def _ui_update():
                    if error:
                        self._set_status(
                            f"Gemini: {entry.file} — {error}", FG_ERR)
                    else:
                        row = self._entry_list.get_row(idx - 1)
                        if row:
                            row.fill_from_ai(
                                title=entry.title,
                                tags=entry.tags,
                                category=entry.category,
                                album=entry.album,
                                colors=entry.colors,
                            )
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
            )

            def _done():
                self._enrich_btn.configure(state="normal")
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
        _item("1.  Connect", "Enter your site URL, username, and password, then click Connect. "
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

        entries = self._entry_list.get_entries()
        if not entries:
            messagebox.showerror("Nothing loaded", "Load a manifest first.")
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
        if not messagebox.askyesno(
            "Confirm post",
            f"Post {count} image{'s' if count != 1 else ''} to SnapSmack?\n\n"
            "They'll appear in the archive in the order shown.",
        ):
            return

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

        poster_module.run_batch(
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
        )
        self._msg_queue.put(('done', total))

    def _poll_queue(self):
        try:
            while True:
                msg = self._msg_queue.get_nowait()
                if msg[0] == 'progress':
                    _, current, total, result = msg
                    self._prog_var.set(current)
                    self._prog_lbl.configure(text=f"{current} / {total}")

                    idx = current - 1
                    if result.success:
                        row_status = 'ok' if result.exif_ok else 'warning'
                        self._entry_list.set_row_status(idx, row_status, result.message)
                    else:
                        self._entry_list.set_row_status(idx, 'error', result.message)

                    row_color = FG_OK if result.success and result.exif_ok else (FG_WARN if result.success else FG_ERR)
                    self._set_status(
                        f"{'✓' if result.success else '✗'}  {result.entry.file} — {result.message}",
                        row_color,
                    )

                elif msg[0] == 'done':
                    total = msg[1]
                    self._set_posting(False)
                    self._set_status(f"Batch complete — {total} processed.", FG_OK)
                    if not self._cfg_visible:
                        self._toggle_cfg()

                elif msg[0] == 'session_renewed':
                    # Keepalive ping succeeded (or silent re-login worked) —
                    # reset the session countdown so it reflects the renewed lifetime.
                    self._start_session_timer()
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
        if not self._client or not self._site_data:
            messagebox.showinfo("Not connected",
                                "Click Connect first and enter your credentials.")
            return False

        # Verify the server session is still alive
        self._set_status("Checking session…", FG_WARN)
        self.update_idletasks()

        if not self._client.is_session_alive():
            self._set_status("Session expired.", FG_ERR)
            self._conn_lbl.configure(text="SESSION EXPIRED", fg=LED_ERR)
            self._conn_dot.configure(fg=LED_ERR)
            messagebox.showwarning(
                "Session Expired",
                "Your login session has timed out on the server.\n\n"
                "Click Connect to log in again. Your queue is still here."
            )
            return False

        self._set_status("Session OK.", FG_OK)
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

    def _set_posting(self, posting: bool):
        self._posting = posting
        state = "disabled" if posting else "normal"
        # Post button is a Canvas — simulate disabled by dimming and blocking clicks
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


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    app = App()
    app.mainloop()
