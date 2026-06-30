"""
SON OF A BATCH — sob_ui.py
Shared Tk widgets + palette for the SON OF A BATCH mode panels (BATCH SLAPPED,
BATCH, PLEASE). Kept separate from main.py so the panels can import a stable
palette + helpers without a circular import. Values mirror main.py's theme.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
import tkinter as tk
from tkinter import ttk
from typing import Dict, List, Optional

from PIL import Image, ImageTk

# -- palette (mirrors main.py) ----------------------------------------------
BG_DEEP = "#141414"
BG_CARD = "#1C1C1C"
BG_MID  = "#050505"
BG_HOVER = "#252525"
ACCENT  = "#39FF14"
FG_MAIN = "#EEEEEE"
FG_DIM  = "#777777"
FG_OK   = "#4EC994"
FG_ERR  = "#FF3E3E"
FG_WARN = "#D4872A"

FONT_UI    = ("Segoe UI", 9)
FONT_BOLD  = ("Segoe UI", 9, "bold")
FONT_SMALL = ("Segoe UI", 8)
FONT_TITLE = ("Segoe UI", 13, "bold")

# Status colours for draft badges.
STATUS_COLORS = {
    "draft":   FG_DIM,
    "ready":   ACCENT,
    "syncing": FG_WARN,
    "synced":  FG_OK,
    "failed":  FG_ERR,
    "queued":  FG_WARN,
}


# A small image cache so PhotoImages aren't garbage-collected out from under Tk.
_THUMB_CACHE: Dict[str, ImageTk.PhotoImage] = {}


def load_thumb(path: str, size: int = 64) -> Optional[ImageTk.PhotoImage]:
    """Load a square contact-sheet thumbnail, cached. Returns None if missing."""
    if not path or not os.path.isfile(path):
        return None
    key = f"{path}@{size}"
    if key in _THUMB_CACHE:
        return _THUMB_CACHE[key]
    try:
        im = Image.open(path)
        im.thumbnail((size, size), Image.LANCZOS)
        photo = ImageTk.PhotoImage(im)
        _THUMB_CACHE[key] = photo
        return photo
    except Exception:
        return None


def box(parent: tk.Widget, title: str) -> tk.Frame:
    """A titled card container in the admin style. Returns the body frame."""
    wrap = tk.Frame(parent, bg=BG_CARD, highlightbackground="#2A2A2A",
                    highlightthickness=1)
    wrap.pack(fill="x", padx=8, pady=6)
    if title:
        tk.Label(wrap, text=title, bg=BG_CARD, fg=ACCENT,
                 font=FONT_BOLD).pack(anchor="w", padx=10, pady=(8, 2))
    body = tk.Frame(wrap, bg=BG_CARD)
    body.pack(fill="x", padx=10, pady=(0, 8))
    return body


def field(parent: tk.Widget, label: str, var: tk.Variable,
          show: str = "") -> tk.Entry:
    """Stacked label + full-width entry."""
    tk.Label(parent, text=label, bg=BG_CARD, fg=FG_DIM,
             font=FONT_SMALL).pack(anchor="w", pady=(6, 0))
    e = tk.Entry(parent, textvariable=var, bg=BG_MID, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat", font=FONT_UI, show=show)
    e.pack(fill="x", ipady=3)
    return e


def textarea(parent: tk.Widget, label: str, height: int = 4) -> tk.Text:
    tk.Label(parent, text=label, bg=BG_CARD, fg=FG_DIM,
             font=FONT_SMALL).pack(anchor="w", pady=(6, 0))
    t = tk.Text(parent, height=height, bg=BG_MID, fg=FG_MAIN,
                insertbackground=ACCENT, relief="flat", font=FONT_UI, wrap="word")
    t.pack(fill="x")
    return t


def button(parent: tk.Widget, text: str, cmd, *, kind: str = "normal") -> tk.Button:
    fg = {"primary": BG_DEEP, "danger": "#FFFFFF", "normal": FG_MAIN}.get(kind, FG_MAIN)
    bg = {"primary": ACCENT, "danger": FG_ERR, "normal": BG_HOVER}.get(kind, BG_HOVER)
    b = tk.Button(parent, text=text, command=cmd, bg=bg, fg=fg,
                  activebackground=bg, activeforeground=fg, relief="flat",
                  font=FONT_BOLD, padx=12, pady=5, cursor="hand2", bd=0)
    return b


def combo(parent: tk.Widget, var: tk.StringVar, values: List[str], width: int = 18):
    cb = ttk.Combobox(parent, textvariable=var, values=values, width=width,
                      state="readonly")
    return cb


def status_badge(parent: tk.Widget, status: str) -> tk.Label:
    return tk.Label(parent, text=status.upper(), bg=BG_CARD,
                    fg=STATUS_COLORS.get(status, FG_DIM), font=FONT_SMALL)
# ===== SNAPSMACK EOF =====
