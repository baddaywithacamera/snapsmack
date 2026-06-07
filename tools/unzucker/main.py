"""
Unzucker — main.py
Instagram export migration tool for SnapSmack (The Grid / Carousel mode).

Desktop app with an Instagram-style 3-column square-thumbnail grid.
Click a cell to see post details (all carousel images + the single
shared caption). Transfer & Post pushes everything via FTP then
creates posts through the SnapSmack admin API.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


BUILD_VERSION = "0.7.8"

import os
import queue
import tempfile
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, ttk
from typing import List, Optional

from PIL import Image, ImageTk

import config as cfg_module
import ig_parser
import ftp_upload
import poster as poster_module
from ig_parser import ParsedPost
from poster import UnzuckerClient, SiteData


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
FONT_UI      = ("Segoe UI", 9)
FONT_BOLD    = ("Segoe UI", 9, "bold")
FONT_SMALL   = ("Segoe UI", 8)
FONT_MONO    = ("Consolas", 9)
FONT_TITLE   = ("Segoe UI", 13, "bold")


# ---------------------------------------------------------------------------
# Grid cell widget
# ---------------------------------------------------------------------------

class GridCell(tk.Frame):
    """One square thumbnail in the 3-column grid."""

    def __init__(self, parent, post: ParsedPost, index: int,
                 on_click, cell_size: int):
        super().__init__(parent, bg=BG_DEEP, width=cell_size, height=cell_size)
        self.pack_propagate(False)
        self.post      = post
        self.index     = index
        self._thumb    = None
        self._status   = 'pending'  # pending | ok | error | skip | excluded
        self._cell_size = cell_size

        # Canvas for the thumbnail + overlays
        self._canvas = tk.Canvas(
            self, width=cell_size, height=cell_size,
            bg="#0A0A0E", highlightthickness=0, cursor="hand2",
        )
        self._canvas.pack(fill="both", expand=True)
        self._canvas.bind("<Button-1>", lambda e: on_click(self.index))
        self._canvas.bind("<Button-3>", lambda e: self._toggle_excluded())

        # Carousel icon overlay (top-right)
        if post.post_type == 'carousel':
            self._canvas.create_text(
                cell_size - 8, 8,
                text=f"▣ {len(post.images)}",
                anchor="ne", fill="white",
                font=("Segoe UI", 8, "bold"),
            )

        # Status overlay (hidden initially)
        self._status_overlay = self._canvas.create_rectangle(
            0, 0, cell_size, cell_size,
            fill='', outline='', stipple='', state='hidden',
        )
        self._status_icon = self._canvas.create_text(
            cell_size // 2, cell_size // 2,
            text='', fill='white', font=("Segoe UI", 18, "bold"),
            state='hidden',
        )

    def set_thumb(self, photo: ImageTk.PhotoImage):
        self._thumb = photo
        self._canvas.create_image(
            self._cell_size // 2, self._cell_size // 2,
            image=photo, anchor="center",
        )
        # Re-draw carousel indicator on top
        if self.post.post_type == 'carousel':
            self._canvas.create_text(
                self._cell_size - 8, 8,
                text=f"▣ {len(self.post.images)}",
                anchor="ne", fill="white",
                font=("Segoe UI", 8, "bold"),
            )

    def set_status(self, status: str):
        self._status = status
        icons  = {'ok': '✓', 'error': '✗', 'skip': '—'}
        colors = {'ok': FG_OK,    'error': FG_ERR,   'skip': FG_WARN}

        if status in icons:
            self._canvas.itemconfig(self._status_overlay,
                                    fill=BG_DEEP, stipple='gray50', state='normal')
            self._canvas.itemconfig(self._status_icon,
                                    text=icons[status], fill=colors[status], state='normal')
            self._canvas.tag_raise(self._status_overlay)
            self._canvas.tag_raise(self._status_icon)

    def _toggle_excluded(self):
        self.post.excluded = not self.post.excluded
        if self.post.excluded:
            self._canvas.itemconfig(self._status_overlay,
                                    fill=BG_DEEP, stipple='gray50', state='normal')
            self._canvas.itemconfig(self._status_icon,
                                    text='∅', fill=FG_DIM, state='normal')
            self._canvas.tag_raise(self._status_overlay)
            self._canvas.tag_raise(self._status_icon)
        else:
            self._canvas.itemconfig(self._status_overlay, state='hidden')
            self._canvas.itemconfig(self._status_icon, state='hidden')


# ---------------------------------------------------------------------------
# Post grid (Instagram-style 3-column layout)
# ---------------------------------------------------------------------------

class PostGrid(tk.Frame):
    """Scrollable 3-column grid of square cover thumbnails."""

    def __init__(self, parent, on_cell_click, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._cells:    List[GridCell] = []
        self._on_click = on_cell_click

        self._canvas = tk.Canvas(self, bg=BG_DEEP, highlightthickness=0, bd=0)
        self._scrollbar = ttk.Scrollbar(self, orient="vertical",
                                         command=self._canvas.yview)
        self._canvas.configure(yscrollcommand=self._scrollbar.set)

        self._scrollbar.pack(side="right", fill="y")
        self._canvas.pack(side="left", fill="both", expand=True)

        self._inner = tk.Frame(self._canvas, bg=BG_DEEP)
        self._window = self._canvas.create_window((0, 0), window=self._inner, anchor="nw")

        self._inner.bind("<Configure>", lambda e: self._canvas.configure(
            scrollregion=self._canvas.bbox("all")))
        self._canvas.bind("<Configure>", self._on_canvas_resize)
        self._canvas.bind("<MouseWheel>", lambda e: self._canvas.yview_scroll(
            int(-1 * (e.delta / 120)), "units"))

    def _on_canvas_resize(self, event):
        self._canvas.itemconfig(self._window, width=event.width)

    def load(self, posts: List[ParsedPost]):
        """Populate the grid with parsed posts."""
        self.clear()
        canvas_w = self._canvas.winfo_width()
        if canvas_w < 100:
            canvas_w = WIN_W - 30  # scrollbar allowance
        cell_size = (canvas_w - GRID_GAP * (GRID_COLS - 1)) // GRID_COLS

        for idx, post in enumerate(posts):
            cell = GridCell(self._inner, post, idx, self._on_click, cell_size)
            row = idx // GRID_COLS
            col = idx % GRID_COLS
            cell.grid(row=row, column=col, padx=(0, GRID_GAP if col < GRID_COLS - 1 else 0),
                      pady=(0, GRID_GAP))
            self._cells.append(cell)

            # Load cover thumbnail async
            if post.images:
                self._load_thumb_async(cell, post.images[0], cell_size)

        self._canvas.yview_moveto(0)

    def clear(self):
        for cell in self._cells:
            cell.destroy()
        self._cells.clear()

    def set_cell_status(self, index: int, status: str):
        if 0 <= index < len(self._cells):
            self._cells[index].set_status(status)

    def _load_thumb_async(self, cell: GridCell, img_path: str, size: int):
        def load():
            try:
                img = Image.open(img_path)
                # Square center crop
                w, h = img.size
                side = min(w, h)
                left = (w - side) // 2
                top  = (h - side) // 2
                img = img.crop((left, top, left + side, top + side))
                img = img.resize((size, size), Image.LANCZOS)
                photo = ImageTk.PhotoImage(img)
                self.after(0, lambda: cell.set_thumb(photo))
            except Exception:
                pass
        threading.Thread(target=load, daemon=True).start()


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
        nav = tk.Frame(self, bg=BG_CARD, height=36)
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
# Main application window
# ---------------------------------------------------------------------------

class App(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title(f"UNZUCKER  —  build {BUILD_VERSION}")
        self.geometry(f"{WIN_W}x{WIN_H}")
        self.minsize(520, 600)
        self.configure(bg=BG_DEEP)

        # State
        self._config         = cfg_module.load()
        self._client:        Optional[UnzuckerClient] = None
        self._site_data:     Optional[SiteData]       = None
        self._posts:         List[ParsedPost]          = []
        self._ftp_transport  = None
        self._posting        = False
        self._msg_queue:     queue.Queue               = queue.Queue()
        self._current_view   = 'grid'   # 'grid' or 'detail'
        self._detail_index   = 0

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
        header = tk.Frame(self, bg=BG_CARD, height=44)
        header.pack(fill="x")
        header.pack_propagate(False)

        tk.Label(
            header, text="UNZUCKER", bg=BG_CARD, fg=ACCENT, font=FONT_TITLE,
        ).pack(side="left", padx=16)

        self._conn_dot = tk.Label(header, text="●", bg=BG_CARD, fg=FG_DIM, font=("Segoe UI", 11))
        self._conn_dot.pack(side="right", padx=(0, 14))
        self._conn_lbl = tk.Label(header, text="Not connected", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        self._conn_lbl.pack(side="right")
        tk.Label(header, text=f"build {BUILD_VERSION}", bg=BG_CARD, fg=FG_DIM,
                 font=("Segoe UI", 8)).pack(side="right", padx=(0, 20))

        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # ── Config collapse toggle ───────────────────────────────────
        self._cfg_visible = True
        cfg_toggle = tk.Frame(self, bg=BG_DEEP, height=26, cursor="hand2")
        cfg_toggle.pack(fill="x")
        cfg_toggle.pack_propagate(False)
        self._cfg_arrow = tk.Label(cfg_toggle, text="▲  CONFIGURATION",
                                   bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                                   cursor="hand2")
        self._cfg_arrow.pack(side="left", padx=14, pady=4)
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # ── Config area ──────────────────────────────────────────────
        self._cfg_frame = tk.Frame(self, bg=BG_DEEP)
        self._cfg_frame.pack(fill="x", padx=14, pady=10)

        def _toggle_cfg(e=None):
            if self._cfg_visible:
                self._cfg_frame.pack_forget()
                self._cfg_arrow.configure(text="▼  CONFIGURATION")
            else:
                self._cfg_frame.pack(fill="x", padx=14, pady=10,
                                     before=self._grid_rule)
                self._cfg_arrow.configure(text="▲  CONFIGURATION")
            self._cfg_visible = not self._cfg_visible

        cfg_toggle.bind("<Button-1>", _toggle_cfg)
        self._cfg_arrow.bind("<Button-1>", _toggle_cfg)

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

        # ── Box: FTP SETTINGS ────────────────────────────────────────
        self._ftp_host_var     = tk.StringVar()
        self._ftp_port_var     = tk.StringVar(value='21')
        self._ftp_user_var     = tk.StringVar()
        self._ftp_pass_var     = tk.StringVar()
        self._ftp_proto_var    = tk.StringVar(value='ftp')
        self._ftp_base_var     = tk.StringVar(value='/public_html/images')

        ftp_box  = self._box(cfg, "FTP SETTINGS")
        ftp_box.pack(fill="x", pady=(0, 8))
        ftp_body = self._box_body(ftp_box)

        ftp_row1 = tk.Frame(ftp_body, bg=BG_CARD)
        ftp_row1.pack(fill="x", pady=(0, 8))
        ftp_row1.columnconfigure(0, weight=3)
        ftp_row1.columnconfigure(1, weight=1)
        ftp_row1.columnconfigure(2, weight=1)
        self._field_in(ftp_row1, "HOST", self._ftp_host_var, 0, 0, padx=(0, 6))
        self._field_in(ftp_row1, "PORT", self._ftp_port_var, 0, 1, padx=(0, 6))
        # Protocol dropdown
        proto_cell = tk.Frame(ftp_row1, bg=BG_CARD)
        proto_cell.grid(row=0, column=2, sticky="ew")
        tk.Label(proto_cell, text="PROTOCOL", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w")
        ttk.Combobox(proto_cell, textvariable=self._ftp_proto_var,
                      values=['ftp', 'sftp'], font=FONT_SMALL, state="readonly",
                      width=6).pack(fill="x", pady=(2, 0))

        ftp_row2 = tk.Frame(ftp_body, bg=BG_CARD)
        ftp_row2.pack(fill="x", pady=(0, 8))
        ftp_row2.columnconfigure(0, weight=1)
        ftp_row2.columnconfigure(1, weight=1)
        self._field_in(ftp_row2, "FTP USERNAME", self._ftp_user_var, 0, 0, padx=(0, 6))
        self._field_in_password(ftp_row2, "FTP PASSWORD", self._ftp_pass_var, 0, 1)

        self._field(ftp_body, "REMOTE BASE PATH", self._ftp_base_var)

        ftp_btn_row = tk.Frame(ftp_body, bg=BG_CARD)
        ftp_btn_row.pack(fill="x")
        self._test_ftp_btn = ttk.Button(ftp_btn_row, text="Test FTP", style="Ghost.TButton",
                                         command=self._on_test_ftp)
        self._test_ftp_btn.pack(side="right")

        # ── Box: IMPORT SETTINGS ─────────────────────────────────────
        self._export_var   = tk.StringVar()
        self._copy_var     = tk.StringVar()

        imp_box  = self._box(cfg, "IMPORT SETTINGS")
        imp_box.pack(fill="x", pady=(0, 8))
        imp_body = self._box_body(imp_box)

        self._field_browse(imp_body, "INSTAGRAM EXPORT FOLDER", self._export_var, self._browse_export)
        self._field(imp_body, "COPYRIGHT STRING", self._copy_var)

        # ── Grid header ──────────────────────────────────────────────
        self._grid_rule = tk.Frame(self, bg=BORDER, height=1)
        self._grid_rule.pack(fill="x")

        g_hdr = tk.Frame(self, bg=BG_DEEP, height=30)
        g_hdr.pack(fill="x")
        g_hdr.pack_propagate(False)

        self._grid_lbl = tk.Label(
            g_hdr, text="POSTS — 0", bg=BG_DEEP, fg=FG_DIM, font=FONT_BOLD,
        )
        self._grid_lbl.pack(side="left", padx=14, pady=6)

        self._parse_btn = ttk.Button(g_hdr, text="Parse Export", style="Ghost.TButton",
                                      command=self._on_parse)
        self._parse_btn.pack(side="right", padx=14, pady=4)

        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # ── Grid + Detail container ──────────────────────────────────
        self._view_container = tk.Frame(self, bg=BG_DEEP)
        self._view_container.pack(fill="both", expand=True)

        self._grid = PostGrid(self._view_container, on_cell_click=self._on_cell_click)
        self._grid.pack(fill="both", expand=True)

        self._detail = PostDetail(
            self._view_container,
            on_back=self._show_grid,
            on_prev=self._detail_prev,
            on_next=self._detail_next,
        )
        # Detail is not packed initially — shown on cell click

        # ── Bottom action bar ────────────────────────────────────────
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")
        bottom = tk.Frame(self, bg=BG_CARD, height=52)
        bottom.pack(fill="x")
        bottom.pack_propagate(False)

        self._validate_btn = ttk.Button(bottom, text="Validate", style="Ghost.TButton",
                                         command=self._on_validate)
        self._validate_btn.pack(side="left", padx=(14, 6), pady=10)

        # TRANSFER & POST button (Canvas for reliable Windows rendering)
        self._post_canvas = tk.Canvas(
            bottom, width=180, height=36,
            bg=BG_CARD, highlightthickness=0, cursor="hand2",
        )
        self._post_canvas.pack(side="left", padx=(10, 0), pady=6)
        self._post_rect = self._post_canvas.create_rectangle(
            0, 0, 180, 36, fill=ACCENT, outline='', width=0,
        )
        self._post_text = self._post_canvas.create_text(
            90, 18, text="TRANSFER & POST", fill=BG_DEEP,
            font=("Segoe UI", 10, "bold"),
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

    # ------------------------------------------------------------------
    # Box layout helpers
    # ------------------------------------------------------------------

    def _box(self, parent, title: str) -> tk.Frame:
        outer = tk.Frame(parent, bg=BORDER, padx=1, pady=1)
        hdr   = tk.Frame(outer, bg=BG_DEEP, height=26)
        hdr.pack(fill="x")
        hdr.pack_propagate(False)
        tk.Label(hdr, text=title, bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL).pack(
            side="left", padx=10, pady=4)
        return outer

    def _box_body(self, box: tk.Frame) -> tk.Frame:
        body = tk.Frame(box, bg=BG_CARD, padx=12, pady=10)
        body.pack(fill="both", expand=True)
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
        e = self._entry(inner, var, width=0, show="•")
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
        self._ftp_host_var.set(c.get('ftp_host', ''))
        self._ftp_port_var.set(str(c.get('ftp_port', 21)))
        self._ftp_user_var.set(c.get('ftp_username', ''))
        self._ftp_pass_var.set(c.get('ftp_password', ''))
        self._ftp_proto_var.set(c.get('ftp_protocol', 'ftp'))
        self._ftp_base_var.set(c.get('ftp_remote_base', '/public_html/images'))
        self._export_var.set(c.get('export_folder', ''))
        self._copy_var.set(c.get('copyright_text', ''))

    def _save_config(self):
        cfg_module.save({
            'url':              self._url_var.get().strip(),
            'api_key':          self._api_key_var.get(),
            'ftp_host':         self._ftp_host_var.get().strip(),
            'ftp_port':         int(self._ftp_port_var.get() or 21),
            'ftp_username':     self._ftp_user_var.get().strip(),
            'ftp_password':     self._ftp_pass_var.get(),
            'ftp_protocol':     self._ftp_proto_var.get(),
            'ftp_remote_base':  self._ftp_base_var.get().strip(),
            'export_folder':    self._export_var.get().strip(),
            'copyright_text':   self._copy_var.get().strip(),
        })

    # ------------------------------------------------------------------
    # Browse
    # ------------------------------------------------------------------

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
        self._grid.load(self._posts)

        s = result.stats
        self._grid_lbl.configure(
            text=f"POSTS — {s['total_posts']}  "
                 f"({s['carousel_posts']} carousel, {s['single_posts']} single)  "
                 f"·  {s['total_images']} images"
        )

        self._progress['maximum'] = len(self._posts)
        self._prog_var.set(0)
        self._prog_lbl.configure(text=f"0 / {len(self._posts)}")
        self._set_status(
            f"Parsed {s['total_posts']} posts. "
            f"Click a cell to inspect. Right-click to exclude.",
            FG_MAIN
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
            self._save_config()

        except Exception as e:
            self._conn_dot.configure(fg=FG_ERR)
            self._conn_lbl.configure(text="Connection failed", fg=FG_ERR)
            self._set_status(f"Error: {e}", FG_ERR)
            messagebox.showerror("Connection failed", str(e))

    # ------------------------------------------------------------------
    # Test FTP
    # ------------------------------------------------------------------

    def _on_test_ftp(self):
        host = self._ftp_host_var.get().strip()
        if not host:
            messagebox.showerror("Missing", "Enter FTP host first.")
            return
        self._set_status("Testing FTP…", FG_WARN)
        self.update_idletasks()
        try:
            transport = ftp_upload.create_transport(
                protocol=self._ftp_proto_var.get(),
                host=host,
                port=int(self._ftp_port_var.get() or 21),
                username=self._ftp_user_var.get().strip(),
                password=self._ftp_pass_var.get(),
            )
            transport.connect()
            transport.disconnect()
            self._set_status("FTP connection OK.", FG_OK)
            messagebox.showinfo("FTP OK", "FTP connection successful.")
        except Exception as e:
            self._set_status(f"FTP failed: {e}", FG_ERR)
            messagebox.showerror("FTP failed", str(e))

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

        active = [p for p in self._posts if not p.excluded]
        if not active:
            messagebox.showerror("Nothing to post", "All posts are excluded.")
            return

        count = len(active)
        if not messagebox.askyesno(
            "Confirm migration",
            f"Transfer & post {count} post{'s' if count != 1 else ''} to SnapSmack?\n\n"
            f"Images will be FTP'd to {self._ftp_host_var.get().strip()} "
            f"and posts created via the API.",
        ):
            return

        # Build FTP transport
        try:
            self._ftp_transport = ftp_upload.create_transport(
                protocol=self._ftp_proto_var.get(),
                host=self._ftp_host_var.get().strip(),
                port=int(self._ftp_port_var.get() or 21),
                username=self._ftp_user_var.get().strip(),
                password=self._ftp_pass_var.get(),
            )
            self._ftp_transport.connect()
        except Exception as e:
            messagebox.showerror("FTP connection failed", str(e))
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

        thread = threading.Thread(
            target=self._post_thread,
            args=(active, staging_dir, count),
            daemon=True,
        )
        thread.start()

    def _post_thread(self, posts, staging_dir, total):
        def on_progress(current, total, result):
            self._msg_queue.put(('progress', current, total, result))

        poster_module.run_migration(
            client=self._client,
            posts=posts,
            site_data=self._site_data,
            ftp_transport=self._ftp_transport,
            ftp_remote_base=self._ftp_base_var.get().strip(),
            staging_dir=staging_dir,
            default_category='',
            default_album='',
            copyright_text=self._copy_var.get().strip(),
            on_progress=on_progress,
        )
        self._msg_queue.put(('done', total))

        # Clean up staging dir
        try:
            import shutil
            shutil.rmtree(staging_dir, ignore_errors=True)
        except Exception:
            pass

        # Disconnect FTP
        if self._ftp_transport:
            try:
                self._ftp_transport.disconnect()
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

                    icon  = '✓' if result.success else '✗'
                    color = FG_OK   if result.success else FG_ERR
                    self._set_status(
                        f"{icon}  Post {current}/{total} — {result.message}",
                        color,
                    )

                elif msg[0] == 'done':
                    total = msg[1]
                    self._set_posting(False)
                    self._set_status(f"Migration complete — {total} processed.", FG_OK)
        except queue.Empty:
            pass
        self.after(100, self._poll_queue)

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------

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
        self._test_ftp_btn.configure(state=state)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    app = App()
    app.mainloop()
# ===== SNAPSMACK EOF =====
