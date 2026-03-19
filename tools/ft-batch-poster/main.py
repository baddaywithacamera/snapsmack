"""
ft-batch-poster — main.py
SnapSmack Batch Image Poster.
Admin-styled desktop app with thumbnail queue, drag reorder,
per-row category/album editing, and Google Drive upload.
"""

BUILD_VERSION = "0.7.4d-6"   # bump this on every rebuild

import os
import queue
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, ttk
from typing import List, Optional

from PIL import Image, ImageTk

import config as cfg_module
import drive as drive_module
import manifest_parser
import poster as poster_module
from manifest_parser import ManifestEntry
from poster import SnapSmackClient, SiteData


# ---------------------------------------------------------------------------
# Colour palette & typography
# ---------------------------------------------------------------------------

BG_DEEP   = "#0C0C10"   # window background
BG_CARD   = "#16161E"   # row / card background
BG_MID    = "#1E1E2A"   # input fields, alternate rows
BG_HOVER  = "#252535"   # hover state
ACCENT    = "#D4872A"   # warm amber — primary accent
ACCENT2   = "#3AB8CC"   # teal — secondary / info
BORDER    = "#2A2A3A"   # subtle borders

FG_MAIN   = "#E8E8E0"   # primary text
FG_DIM    = "#6A6A7E"   # muted / placeholder
FG_OK     = "#4EC994"   # success
FG_ERR    = "#E86060"   # error
FG_WARN   = "#D4872A"   # warning (same as accent)

STATUS_COLORS = {
    'pending':  ("#3A3A4A", FG_DIM),
    'posting':  (ACCENT,    "#0C0C10"),
    'ok':       (FG_OK,     "#0C0C10"),
    'error':    (FG_ERR,    "#0C0C10"),
    'warning':  (FG_WARN,   "#0C0C10"),
}

THUMB_SIZE  = (72, 72)
ROW_HEIGHT  = 88        # px per entry row
WIN_W, WIN_H = 1020, 780
FONT_UI      = ("Segoe UI", 9)
FONT_BOLD    = ("Segoe UI", 9, "bold")
FONT_SMALL   = ("Segoe UI", 8)
FONT_MONO    = ("Consolas", 9)
FONT_TITLE   = ("Segoe UI", 13, "bold")


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
        self._thumb_lbl.place(x=30, y=8, width=THUMB_SIZE[0], height=THUMB_SIZE[1])

        # ── File name + title ─────────────────────────────────────────
        name_frame = tk.Frame(self, bg=BG_CARD)
        name_frame.place(x=110, y=8, width=260, height=THUMB_SIZE[1])

        tk.Label(
            name_frame, text=self.entry.file, bg=BG_CARD, fg=FG_MAIN,
            font=FONT_BOLD, anchor="w",
        ).pack(fill="x")

        title_text = self.entry.title or "(no title)"
        tk.Label(
            name_frame, text=title_text, bg=BG_CARD, fg=FG_DIM,
            font=FONT_SMALL, anchor="w", wraplength=255, justify="left",
        ).pack(fill="x")

        # ── Category combobox ─────────────────────────────────────────
        self._cat_var = tk.StringVar(value=self.entry.category)
        self._cat_cb = ttk.Combobox(
            self, textvariable=self._cat_var, values=[''] + cats,
            font=FONT_SMALL, state="normal",
        )
        self._cat_cb.place(x=378, y=20, width=180)
        self._cat_cb.bind("<<ComboboxSelected>>",
                    lambda e: setattr(self.entry, 'category', self._cat_var.get()))
        self._cat_var.trace_add("write",
                    lambda *a: setattr(self.entry, 'category', self._cat_var.get()))

        tk.Label(self, text="cat", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=378, y=6)

        # ── Album combobox ────────────────────────────────────────────
        self._album_var = tk.StringVar(value=self.entry.album)
        self._album_cb = ttk.Combobox(
            self, textvariable=self._album_var, values=[''] + albums,
            font=FONT_SMALL, state="normal",
        )
        self._album_cb.place(x=568, y=20, width=180)
        self._album_cb.bind("<<ComboboxSelected>>",
                      lambda e: setattr(self.entry, 'album', self._album_var.get()))
        self._album_var.trace_add("write",
                      lambda *a: setattr(self.entry, 'album', self._album_var.get()))

        tk.Label(self, text="album", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).place(x=568, y=6)

        # ── Status badge ──────────────────────────────────────────────
        self._status_lbl = tk.Label(
            self, text="PENDING", font=("Segoe UI", 7, "bold"),
            padx=6, pady=2, relief="flat",
        )
        self._status_lbl.place(x=758, y=32, width=72, height=20)
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
        self._canvas.bind("<MouseWheel>", self._on_mousewheel)

    def _on_inner_configure(self, _event):
        self._canvas.configure(scrollregion=self._canvas.bbox("all"))

    def _on_canvas_configure(self, event):
        self._canvas.itemconfig(self._window, width=event.width)

    def _on_mousewheel(self, event):
        self._canvas.yview_scroll(int(-1 * (event.delta / 120)), "units")

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
        self.title(f"SNAPSMACK BATCH IMAGE POSTER  —  build {BUILD_VERSION}")
        self.geometry(f"{WIN_W}x{WIN_H}")
        self.minsize(860, 600)
        self.configure(bg=BG_DEEP)

        # State
        self._config        = cfg_module.load()
        self._client:       Optional[SnapSmackClient] = None
        self._site_data:    Optional[SiteData]        = None
        self._drive_service = None
        self._posting       = False
        self._msg_queue:    queue.Queue               = queue.Queue()

        self._apply_ttk_style()
        self._build_ui()
        self._load_config_to_ui()
        self.after(100, self._poll_queue)

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
            fieldbackground=[("readonly", BG_MID)],
            foreground=[("readonly", FG_MAIN)],
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

    def _apply_theme(self, theme: dict):
        """
        Re-skin the entire UI using colors fetched from the site's active admin theme.
        Walks the full widget tree replacing any widget whose bg/fg matches our current
        known theme palette. Safe to call after the UI is fully built.
        """
        accent   = theme.get('accent',   self._t_accent)
        bg       = theme.get('bg',       self._t_bg)
        bg_card  = theme.get('bg_card',  self._t_card)
        bg_input = theme.get('bg_input', self._t_input)
        border   = theme.get('border',   self._t_border)
        fg       = theme.get('fg',       self._t_fg)
        fg_dim   = theme.get('fg_dim',   self._t_dim)

        def _lighten(hex_color: str) -> str:
            h = hex_color.lstrip('#')
            if len(h) == 3:
                h = ''.join(c * 2 for c in h)
            try:
                r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
                return f'#{min(255,int(r*1.15)):02x}{min(255,int(g*1.15)):02x}{min(255,int(b*1.15)):02x}'
            except Exception:
                return hex_color

        # ttk styles
        style = ttk.Style(self)
        style.configure("Accent.TButton", background=accent, foreground=bg)
        style.map("Accent.TButton",
            background=[("active", _lighten(accent)), ("disabled", bg_input)],
            foreground=[("active", bg), ("disabled", fg_dim)],
        )
        style.configure("Ghost.TButton", background=bg_input, foreground=fg)
        style.map("Ghost.TButton", background=[("active", bg_card)])
        style.configure("Post.TButton", background=accent, foreground=bg)
        style.map("Post.TButton",
            background=[("active", _lighten(accent)), ("disabled", bg_input)],
            foreground=[("active", bg), ("disabled", fg_dim)],
        )
        style.configure("TCombobox",
            fieldbackground=bg_input, background=bg_input, foreground=fg,
            selectbackground=accent, selectforeground=bg, bordercolor=border,
        )
        style.configure("TScrollbar",
            background=bg_input, troughcolor=bg, bordercolor=bg, arrowcolor=fg_dim,
        )

        # Build colour replacement maps from current stored palette.
        bg_map  = {self._t_bg: bg, self._t_card: bg_card, self._t_input: bg_input}
        fg_map  = {self._t_fg: fg, self._t_dim: fg_dim, self._t_accent: accent}

        def _walk(widget):
            # bg / background
            for attr in ('bg', 'background'):
                try:
                    cur = widget.cget(attr)
                    if cur in bg_map:
                        widget.configure(**{attr: bg_map[cur]})
                    break
                except Exception:
                    pass
            # fg / foreground
            for attr in ('fg', 'foreground'):
                try:
                    cur = widget.cget(attr)
                    if cur in fg_map:
                        widget.configure(**{attr: fg_map[cur]})
                    break
                except Exception:
                    pass
            # selectcolor (Checkbutton)
            try:
                cur = widget.cget('selectcolor')
                if cur == self._t_input:
                    widget.configure(selectcolor=bg_input)
            except Exception:
                pass
            # highlightbackground / highlightcolor
            try:
                widget.configure(highlightbackground=border)
            except Exception:
                pass
            for child in widget.winfo_children():
                _walk(child)

        _walk(self)

        # Update stored palette so a second connect picks up fresh values.
        self._t_bg     = bg
        self._t_card   = bg_card
        self._t_input  = bg_input
        self._t_border = border
        self._t_fg     = fg
        self._t_dim    = fg_dim
        self._t_accent = accent

    # ------------------------------------------------------------------
    # UI construction
    # ------------------------------------------------------------------

    def _build_ui(self):
        # Track theme colours so _apply_theme can walk and replace them.
        self._t_bg     = BG_DEEP
        self._t_card   = BG_CARD
        self._t_input  = BG_MID
        self._t_border = BORDER
        self._t_fg     = FG_MAIN
        self._t_dim    = FG_DIM
        self._t_accent = ACCENT

        # ── Header row (mirrors admin header-row--ruled) ──────────────
        header = tk.Frame(self, bg=BG_CARD, height=44)
        header.pack(fill="x")
        header.pack_propagate(False)

        self._title_lbl = tk.Label(
            header, text=f"SNAPSMACK  BATCH IMAGE POSTER",
            bg=BG_CARD, fg=ACCENT, font=FONT_TITLE,
        )
        self._title_lbl.pack(side="left", padx=16)

        self._conn_dot = tk.Label(header, text="●", bg=BG_CARD, fg=FG_DIM, font=("Segoe UI", 11))
        self._conn_dot.pack(side="right", padx=(0, 14))
        self._conn_lbl = tk.Label(header, text="Not connected", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        self._conn_lbl.pack(side="right")
        tk.Label(header, text=f"build {BUILD_VERSION}", bg=BG_CARD, fg=FG_DIM,
                 font=("Segoe UI", 8)).pack(side="right", padx=(0, 20))

        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

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
                self._cfg_frame.pack_forget()
                self._cfg_arrow.configure(text="▼  CONFIGURATION")
            else:
                self._cfg_frame.pack(fill="x", padx=14, pady=10,
                                     before=self._queue_rule)
                self._cfg_arrow.configure(text="▲  CONFIGURATION")
            self._cfg_visible = not self._cfg_visible

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
        self._def_cat_var  = tk.StringVar()
        self._def_alb_var  = tk.StringVar()

        mfst_box  = self._box(cols, "MANIFEST & DEFAULTS")
        mfst_box.grid(row=0, column=1, sticky="nsew", padx=(7, 0))
        mfst_body = self._box_body(mfst_box)

        self._field_browse(mfst_body, "IMAGE FOLDER",  self._folder_var,   self._browse_folder)
        self._field_browse(mfst_body, "MANIFEST FILE", self._manifest_var, self._browse_manifest)

        dm_cols = tk.Frame(mfst_body, bg=BG_CARD)
        dm_cols.pack(fill="x", pady=(0, 8))
        dm_cols.columnconfigure(0, weight=1)
        dm_cols.columnconfigure(1, weight=1)

        cat_cell = tk.Frame(dm_cols, bg=BG_CARD)
        cat_cell.grid(row=0, column=0, sticky="ew", padx=(0, 6))
        tk.Label(cat_cell, text="DEFAULT CATEGORY", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        self._def_cat_cb = ttk.Combobox(cat_cell, textvariable=self._def_cat_var, font=FONT_SMALL)
        self._def_cat_cb.pack(fill="x", pady=(2, 0))

        alb_cell = tk.Frame(dm_cols, bg=BG_CARD)
        alb_cell.grid(row=0, column=1, sticky="ew")
        tk.Label(alb_cell, text="DEFAULT ALBUM", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        self._def_alb_cb = ttk.Combobox(alb_cell, textvariable=self._def_alb_var, font=FONT_SMALL)
        self._def_alb_cb.pack(fill="x", pady=(2, 0))

        ttk.Button(mfst_body, text="Load Manifest", style="Ghost.TButton",
                   command=self._on_load).pack(anchor="w")

        # ── Box: GOOGLE DRIVE ─────────────────────────────────────────
        self._goog_creds_var   = tk.StringVar()
        self._drive_folder_var = tk.StringVar()

        drv_box  = self._box(cfg, "GOOGLE DRIVE")
        drv_box.pack(fill="x", pady=(10, 0))
        drv_body = self._box_body(drv_box)

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
        drv_status = tk.Frame(drv_btn_cell, bg=BG_CARD)
        drv_status.pack()
        self._drive_dot = tk.Label(drv_status, text="●", bg=BG_CARD, fg=FG_DIM, font=("Segoe UI", 10))
        self._drive_dot.pack(side="left", padx=(0, 3))
        self._drive_lbl = tk.Label(drv_status, text="Not connected", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        self._drive_lbl.pack(side="left")

        # ── Box: COPYRIGHT ────────────────────────────────────────────
        self._copyright_var = tk.StringVar()

        copy_box  = self._box(cfg, "COPYRIGHT STRING")
        copy_box.pack(fill="x", pady=(10, 0))
        copy_body = self._box_body(copy_box)
        self._entry(copy_body, self._copyright_var, width=0).pack(fill="x")

        # ── Queue header (ruled like admin h2) ────────────────────────
        self._queue_rule = tk.Frame(self, bg=BORDER, height=1)
        self._queue_rule.pack(fill="x")
        q_hdr = tk.Frame(self, bg=BG_DEEP, height=30)
        q_hdr.pack(fill="x")
        q_hdr.pack_propagate(False)
        self._queue_lbl = tk.Label(
            q_hdr, text="QUEUE — 0 ITEMS",
            bg=BG_DEEP, fg=FG_DIM, font=FONT_BOLD,
        )
        self._queue_lbl.pack(side="left", padx=14, pady=6)
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # ── Entry list ────────────────────────────────────────────────
        self._entry_list = EntryList(self)
        self._entry_list.pack(fill="both", expand=True)

        # ── Bottom action bar ─────────────────────────────────────────
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")
        bottom = tk.Frame(self, bg=BG_CARD, height=52)
        bottom.pack(fill="x")
        bottom.pack_propagate(False)

        self._validate_btn = ttk.Button(bottom, text="Validate", style="Ghost.TButton",
                                         command=self._on_validate)
        self._validate_btn.pack(side="left", padx=(14, 6), pady=10)

        self._post_btn = ttk.Button(bottom, text="POST BATCH", style="Post.TButton",
                                     command=self._on_post)
        self._post_btn.pack(side="left", pady=6)

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
        self._folder_var.set(c.get('last_image_folder', ''))
        self._manifest_var.set(c.get('last_manifest_file', ''))
        self._goog_creds_var.set(c.get('google_credentials', ''))
        self._drive_folder_var.set(c.get('drive_folder_id', ''))
        self._copyright_var.set(c.get('copyright_text', ''))

        # If we already have a token, silently reconnect Drive in the background
        if drive_module.is_authenticated():
            creds_path = c.get('google_credentials', '')
            if creds_path and os.path.isfile(creds_path):
                self._drive_dot.configure(fg=FG_WARN)
                self._drive_lbl.configure(text="Connecting…", fg=FG_WARN)
                def _auto_connect():
                    try:
                        service = drive_module.authenticate(creds_path)
                        self._drive_service = service
                        self.after(0, lambda: self._drive_dot.configure(fg=FG_OK))
                        self.after(0, lambda: self._drive_lbl.configure(text="Authenticated", fg=FG_OK))
                    except Exception:
                        self.after(0, lambda: self._drive_dot.configure(fg=FG_DIM))
                        self.after(0, lambda: self._drive_lbl.configure(text="Not connected", fg=FG_DIM))
                import threading as _threading
                _threading.Thread(target=_auto_connect, daemon=True).start()
            else:
                self._drive_dot.configure(fg=FG_OK)
                self._drive_lbl.configure(text="Authenticated", fg=FG_OK)

    def _save_config(self):
        cfg_module.save({
            'url':                self._url_var.get().strip(),
            'username':           self._user_var.get().strip(),
            'password':           self._pass_var.get(),
            'remember':           self._rem_var.get(),
            'default_category':   self._def_cat_var.get().strip(),
            'default_album':      self._def_alb_var.get().strip(),
            'last_image_folder':  self._folder_var.get().strip(),
            'last_manifest_file': self._manifest_var.get().strip(),
            'google_credentials': self._goog_creds_var.get().strip(),
            'drive_folder_id':    self._drive_folder_var.get().strip(),
            'copyright_text':     self._copyright_var.get().strip(),
        })

    # ------------------------------------------------------------------
    # Browse
    # ------------------------------------------------------------------

    def _browse_folder(self):
        p = filedialog.askdirectory(title="Select image folder")
        if p:
            self._folder_var.set(p)

    def _browse_manifest(self):
        p = filedialog.askopenfilename(
            title="Select manifest file",
            filetypes=[("Text files", "*.txt"), ("All files", "*.*")],
        )
        if p:
            self._manifest_var.set(p)

    def _browse_creds(self):
        p = filedialog.askopenfilename(
            title="Select Google credentials.json",
            filetypes=[("JSON files", "*.json"), ("All files", "*.*")],
        )
        if p:
            self._goog_creds_var.set(p)
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

        self._drive_dot.configure(fg=FG_WARN)
        self._drive_lbl.configure(text="Opening browser…", fg=FG_WARN)
        self._drive_btn.configure(state="disabled")
        self.update_idletasks()

        def auth_thread():
            try:
                service = drive_module.authenticate(creds_path)
                self._drive_service = service
                self.after(0, lambda: self._drive_dot.configure(fg=FG_OK))
                self.after(0, lambda: self._drive_lbl.configure(text="Authenticated", fg=FG_OK))
                self.after(0, lambda: self._set_status("Google Drive connected.", FG_OK))
            except Exception as e:
                self.after(0, lambda: self._drive_dot.configure(fg=FG_ERR))
                self.after(0, lambda: self._drive_lbl.configure(text="Auth failed", fg=FG_ERR))
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
        self._conn_dot.configure(fg=FG_WARN)
        self._conn_lbl.configure(text="Connecting…", fg=FG_WARN)
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

            self._conn_dot.configure(fg=FG_OK)
            self._conn_lbl.configure(text=f"Connected — {len(cats)} cats, {len(albums)} albums", fg=FG_OK)
            self._set_status("Connected. Load a manifest to begin.", FG_OK)
            self._save_config()

            # Apply the site's active admin theme colors to the UI.
            theme = client.fetch_theme()
            if theme:
                self._apply_theme(theme)

        except Exception as e:
            self._conn_dot.configure(fg=FG_ERR)
            self._conn_lbl.configure(text="Connection failed", fg=FG_ERR)
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

        poster_module.run_batch(
            client=self._client,
            entries=entries,
            image_folder=image_folder,
            site_data=self._site_data,
            default_category=self._def_cat_var.get().strip(),
            default_album=self._def_alb_var.get().strip(),
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
        except queue.Empty:
            pass
        self.after(100, self._poll_queue)

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------

    def _ensure_connected(self) -> bool:
        if self._client and self._site_data:
            return True
        messagebox.showinfo("Not connected",
                            "Click Connect first and enter your credentials.")
        return False

    def _set_status(self, text: str, color: str = FG_DIM):
        self._status_lbl.configure(text=text, fg=color)

    def _set_posting(self, posting: bool):
        self._posting = posting
        state = "disabled" if posting else "normal"
        self._post_btn.configure(state=state)
        self._validate_btn.configure(state=state)
        self._connect_btn.configure(state=state)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    app = App()
    app.mainloop()
