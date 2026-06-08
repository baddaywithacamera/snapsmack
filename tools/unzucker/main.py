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


BUILD_VERSION = "0.7.16"

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
import poster as poster_module
from ig_parser import ParsedPost
from poster import UnzuckerClient, SiteData

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
                 on_click, cell_size: int, on_ctrl_click=None,
                 on_right_click_cb=None):
        super().__init__(parent, bg=BG_DEEP, width=cell_size, height=cell_size)
        self.pack_propagate(False)
        self.post               = post
        self.index              = index
        self._thumb             = None
        self._status            = 'pending'  # pending | ok | error | skip | excluded
        self._cell_size         = cell_size
        self._on_ctrl_click_cb  = on_ctrl_click
        self._on_right_click_cb = on_right_click_cb
        self._trigram_group     = 0   # 0 = none; >0 = group number
        self._trigram_slot      = 0   # 1=L, 2=M, 3=R
        self._selecting         = False

        # Canvas for the thumbnail + overlays
        self._canvas = tk.Canvas(
            self, width=cell_size, height=cell_size,
            bg="#0A0A0E", highlightthickness=0, cursor="hand2",
        )
        self._canvas.pack(fill="both", expand=True)
        self._canvas.bind("<Button-1>", lambda e: on_click(self.index))
        self._canvas.bind("<Control-Button-1>",
                          lambda e: (on_ctrl_click(self.index) if on_ctrl_click else None))
        self._canvas.bind("<Button-3>", self._on_right_click)

        # Carousel icon overlay (top-right)
        if post.post_type == 'carousel':
            self._canvas.create_text(
                cell_size - 8, 8,
                text=f"▪ {len(post.images)}",
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

        # Trigram ring: gold outline, hidden until group assigned
        self._tg_ring = self._canvas.create_rectangle(
            1, 1, cell_size - 1, cell_size - 1,
            outline='#c8a96e', width=2, state='hidden',
        )
        # Trigram slot badge background + text (bottom-right corner)
        bw = 22
        self._tg_badge_bg = self._canvas.create_rectangle(
            cell_size - bw, cell_size - 16, cell_size, cell_size,
            fill='#c8a96e', outline='', state='hidden',
        )
        self._tg_badge_txt = self._canvas.create_text(
            cell_size - bw // 2, cell_size - 8,
            text='', anchor='center', fill='#000',
            font=("Segoe UI", 7, "bold"), state='hidden',
        )
        # Selection ring: neon green dashed outline during Ctrl+click selection
        self._sel_ring = self._canvas.create_rectangle(
            2, 2, cell_size - 2, cell_size - 2,
            outline=ACCENT, width=2, dash=(4, 3), state='hidden',
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
                text=f"▪ {len(self.post.images)}",
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

    def _on_right_click(self, e):
        menu = tk.Menu(self, tearoff=0)
        if self._trigram_group > 0:
            menu.add_command(
                label=f"Remove from Trigram T{self._trigram_group}",
                command=lambda: (self._on_right_click_cb('remove', self.index)
                                 if self._on_right_click_cb else None),
            )
        else:
            menu.add_command(
                label="Add to Trigram Group  (or Ctrl+click)",
                command=lambda: (self._on_right_click_cb('add', self.index)
                                 if self._on_right_click_cb else None),
            )
        menu.add_separator()
        lbl = "Include" if self.post.excluded else "Exclude"
        menu.add_command(label=lbl, command=self._toggle_excluded)
        try:
            menu.tk_popup(e.x_root, e.y_root)
        finally:
            menu.grab_release()

    def set_trigram_state(self, group_num: int, slot: int):
        """Mark this cell as belonging to trigram group_num at slot (1/2/3)."""
        self._trigram_group = group_num
        self._trigram_slot  = slot
        labels = {1: 'L', 2: 'M', 3: 'R'}
        lbl = f"T{group_num}{labels.get(slot, str(slot))}"
        self._canvas.itemconfig(self._tg_ring,      state='normal')
        self._canvas.itemconfig(self._tg_badge_bg,  state='normal')
        self._canvas.itemconfig(self._tg_badge_txt, text=lbl, state='normal')
        self._canvas.tag_raise(self._tg_ring)
        self._canvas.tag_raise(self._tg_badge_bg)
        self._canvas.tag_raise(self._tg_badge_txt)

    def clear_trigram_state(self):
        """Remove trigram ring and badge from this cell."""
        self._trigram_group = 0
        self._trigram_slot  = 0
        self._canvas.itemconfig(self._tg_ring,      state='hidden')
        self._canvas.itemconfig(self._tg_badge_bg,  state='hidden')
        self._canvas.itemconfig(self._tg_badge_txt, state='hidden')

    def set_selecting(self, active: bool):
        """Show/hide the selection ring (used during Ctrl+click group building)."""
        self._selecting = active
        self._canvas.itemconfig(self._sel_ring,
                                state='normal' if active else 'hidden')
        if active:
            self._canvas.tag_raise(self._sel_ring)


# ---------------------------------------------------------------------------
# Post grid (Instagram-style 3-column layout)
# ---------------------------------------------------------------------------

class PostGrid(tk.Frame):
    """Scrollable 3-column grid of square cover thumbnails."""

    def __init__(self, parent, on_cell_click, on_ctrl_click=None,
                 on_right_click_cb=None, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._cells:             List[GridCell] = []
        self._on_click           = on_cell_click
        self._on_ctrl_click      = on_ctrl_click
        self._on_right_click_cb  = on_right_click_cb

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
            cell = GridCell(self._inner, post, idx, self._on_click, cell_size,
                            on_ctrl_click=self._on_ctrl_click,
                            on_right_click_cb=self._on_right_click_cb)
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

    def set_trigram_cells(self, group_num: int, indices: list, slots: list):
        """Apply trigram state to three cells."""
        for idx, slot in zip(indices, slots):
            if 0 <= idx < len(self._cells):
                self._cells[idx].set_trigram_state(group_num, slot)

    def clear_trigram_cells(self, indices: list):
        """Remove trigram state from cells."""
        for idx in indices:
            if 0 <= idx < len(self._cells):
                self._cells[idx].clear_trigram_state()

    def set_selecting_cells(self, indices: list, active: bool):
        """Show/hide the selection ring on the given cells."""
        for idx in indices:
            if 0 <= idx < len(self._cells):
                self._cells[idx].set_selecting(active)

    def clear_all_selecting(self):
        """Clear selection rings from all cells."""
        for cell in self._cells:
            cell.set_selecting(False)

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
            except Exception as e:
                print(f"[thumb] failed to load {img_path!r}: {e}")
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
            nav, text="â†  BACK TO GRID", command=self._on_back,
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
            nav, text="â† PREV", command=self._on_prev,
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

        self._on_lock = on_lock
        # Work with a mutable list of (post, original_index) pairs
        self._order   = list(zip(posts, indices))  # current L/M/R order
        self._thumbs  = [None, None, None]         # PhotoImage refs

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
        tk.Label(self, text="Swap adjacent thumbnails to set L / M / R order.",
                 bg=BG_DEEP, fg=FG_DIM, font=("Segoe UI", 8)).pack(padx=14, pady=(0, 8))

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

            # Thumbnail canvas
            c = tk.Canvas(col, width=T, height=T, bg="#0A0A0E",
                          highlightthickness=1, highlightbackground=BORDER)
            c.pack()
            self._thumb_labels.append(c)

            # Swap button between positions (not after the last one)
            if i < 2:
                swap_i = i  # capture loop var
                btn = tk.Button(
                    self._strip,
                    text="⇄",
                    command=lambda si=swap_i: self._swap(si),
                    bg=BG_MID, fg=FG_MAIN, relief="flat",
                    font=("Segoe UI", 14), cursor="hand2",
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
                           font=("Segoe UI", 8), width=12, wraplength=90,
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
        self._update_titles()
        self._load_thumbs()

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
        self.destroy()


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

        # Trigram group state
        # _tg_groups: list of {'indices': [i,j,k], 'slots': [1,2,3], 'orientation': 'h'}
        # _tg_selection: indices currently being accumulated (max 3)
        self._tg_groups:     list = []
        self._tg_selection:  list = []
        self._tg_group_ctr:  int  = 0  # monotonically incrementing group number

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
        header = tk.Frame(self, bg=BG_CARD, height=44)
        header.pack(fill="x")
        header.pack_propagate(False)

        tk.Label(
            header, text="UNZUCKER", bg=BG_CARD, fg=ACCENT, font=FONT_TITLE,
        ).pack(side="left", padx=16)

        self._conn_dot = tk.Label(header, text="â—", bg=BG_CARD, fg=FG_DIM, font=("Segoe UI", 11))
        self._conn_dot.pack(side="right", padx=(0, 14))
        self._conn_lbl = tk.Label(header, text="Not connected", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        self._conn_lbl.pack(side="right")
        tk.Label(header, text=f"build {BUILD_VERSION}", bg=BG_CARD, fg=FG_DIM,
                 font=("Segoe UI", 8)).pack(side="right", padx=(0, 20))

        # Keyring indicator
        kr_text  = "🔒 keyring" if _KEYRING_OK else "âš  no keyring"
        kr_color = FG_OK       if _KEYRING_OK else FG_WARN
        tk.Label(header, text=kr_text, bg=BG_CARD, fg=kr_color,
                 font=("Segoe UI", 8)).pack(side="right", padx=(0, 10))

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

        self._tg_lbl = tk.Label(
            g_hdr, text="", bg=BG_DEEP, fg='#c8a96e', font=FONT_SMALL,
        )
        self._tg_lbl.pack(side="left", padx=(0, 8), pady=6)

        self._parse_btn = ttk.Button(g_hdr, text="Parse Export", style="Ghost.TButton",
                                      command=self._on_parse)
        self._parse_btn.pack(side="right", padx=14, pady=4)

        tk.Frame(self, bg=BORDER, height=1).pack(fill="x")

        # ── Grid + Detail container ──────────────────────────────────
        self._view_container = tk.Frame(self, bg=BG_DEEP)
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

    def _save_config(self):
        cfg_module.save({
            'url':            self._url_var.get().strip(),
            'api_key':        self._api_key_var.get(),
            'export_folder':  self._export_var.get().strip(),
            'copyright_text': self._copy_var.get().strip(),
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
        self._clear_trigram_state()
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

        thread = threading.Thread(
            target=self._post_thread,
            args=(active, staging_dir, count, remapped_groups),
            daemon=True,
        )
        thread.start()

    def _post_thread(self, posts, staging_dir, total, trigram_groups=None):
        def on_progress(current, total, result):
            self._msg_queue.put(('progress', current, total, result))

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
        """Callback from GridCell right-click context menu."""
        if action == 'add':
            self._on_ctrl_click(index)
        elif action == 'remove':
            self._remove_trigram(index)

    def _open_trigram_panel(self, indices: list):
        """Open TrigramPanel for slot assignment."""
        if not self._posts:
            return
        posts = [self._posts[i] for i in indices]
        panel = TrigramPanel(
            self,
            posts=posts,
            indices=indices,
            on_lock=self._on_trigram_lock,
        )
        # Clear selection rings now — panel takes over
        self._grid.set_selecting_cells(indices, False)
        self._tg_selection.clear()
        self.wait_window(panel)

    def _on_trigram_lock(self, indices: list, slots: list):
        """Called when user clicks LOCK in TrigramPanel."""
        self._tg_group_ctr += 1
        group = {
            'indices':     indices,
            'slots':       slots,
            'orientation': 'h',
        }
        self._tg_groups.append(group)
        self._grid.set_trigram_cells(self._tg_group_ctr, indices, slots)
        self._update_tg_label()
        self._set_status(
            f"Trigram T{self._tg_group_ctr} locked ({indices[0]+1}, {indices[1]+1}, {indices[2]+1}).",
            FG_OK,
        )

    def _remove_trigram(self, index: int):
        """Remove the trigram group containing index."""
        for grp in list(self._tg_groups):
            if index in grp['indices']:
                self._grid.clear_trigram_cells(grp['indices'])
                self._tg_groups.remove(grp)
                self._update_tg_label()
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
