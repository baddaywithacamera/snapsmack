"""
SHOTS FIRED — ui.py
Shared dark palette + Tk widget helpers for the SHOTS FIRED calendar shell.
Values mirror the SnapSmack desktop family (copied from COLD SNAP's sumna_ui so
the whole tool suite looks identical). Kept separate from main.py so the calendar
panels import a stable palette without a circular import.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""


import tkinter as tk
from tkinter import ttk
from typing import List

# -- palette (mirrors the SnapSmack admin dark theme) -----------------------
BG_DEEP  = "#141414"
BG_CARD  = "#1C1C1C"
BG_MID   = "#050505"
BG_HOVER = "#252525"
ACCENT   = "#39FF14"
FG_MAIN  = "#EEEEEE"
FG_DIM   = "#777777"
FG_OK    = "#4EC994"
FG_ERR   = "#FF3E3E"
FG_WARN  = "#D4872A"

FONT_UI    = ("Segoe UI", 9)
FONT_BOLD  = ("Segoe UI", 9, "bold")
FONT_SMALL = ("Segoe UI", 8)
FONT_TITLE = ("Segoe UI", 13, "bold")
FONT_MONO  = ("Consolas", 9)

# One colour per site so a busy agenda stays legible. Assigned round-robin in
# agenda.py; kept here so the palette lives in one place.
SITE_SWATCHES = [
    "#39FF14", "#4EC994", "#D4872A", "#4AA3FF", "#FF6FB5",
    "#C792EA", "#FFCB6B", "#F78C6C", "#89DDFF", "#B0BEC5",
]


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


def button(parent: tk.Widget, text: str, cmd, *, kind: str = "normal") -> tk.Button:
    fg = {"primary": BG_DEEP, "danger": "#FFFFFF", "normal": FG_MAIN}.get(kind, FG_MAIN)
    bg = {"primary": ACCENT, "danger": FG_ERR, "normal": BG_HOVER}.get(kind, BG_HOVER)
    b = tk.Button(parent, text=text, command=cmd, bg=bg, fg=fg,
                  activebackground=bg, activeforeground=fg, relief="flat",
                  font=FONT_BOLD, padx=12, pady=5, cursor="hand2", bd=0)
    return b


def combo(parent: tk.Widget, var: tk.StringVar, values: List[str], width: int = 22):
    return ttk.Combobox(parent, textvariable=var, values=values, width=width,
                        state="readonly")


def apply_ttk_style(root: tk.Misc) -> None:
    """Dark-theme the ttk widgets (Combobox) to match the hand-rolled tk ones."""
    style = ttk.Style(root)
    try:
        style.theme_use("clam")
    except tk.TclError:
        pass
    style.configure("TCombobox",
                    fieldbackground=BG_MID, background=BG_MID,
                    foreground=FG_MAIN, arrowcolor=ACCENT,
                    bordercolor="#2A2A2A", relief="flat")
    style.map("TCombobox",
              fieldbackground=[("readonly", BG_MID)],
              foreground=[("readonly", FG_MAIN)])


def install_clipboard_bindings(root: tk.Misc) -> None:
    """Windows Tk sometimes fails to wire Ctrl+V on Entry/Text and ships no
    right-click menu. Bind cut/copy/paste at the class level plus a context menu
    so pasting an API key or a date works by keyboard or mouse. Copied verbatim
    from COLD SNAP so every tool behaves the same."""
    def _clip(evt_name):
        def _h(e):
            try:
                e.widget.event_generate(evt_name)
            except tk.TclError:
                pass
            return "break"
        return _h

    def _clip_menu(e):
        w = e.widget
        m = tk.Menu(root, tearoff=0)
        m.add_command(label="Cut",   command=lambda: w.event_generate("<<Cut>>"))
        m.add_command(label="Copy",  command=lambda: w.event_generate("<<Copy>>"))
        m.add_command(label="Paste", command=lambda: w.event_generate("<<Paste>>"))
        try:
            w.focus_set()
            m.tk_popup(e.x_root, e.y_root)
        finally:
            m.grab_release()
        return "break"

    for _cls in ("Entry", "TEntry", "Text"):
        root.bind_class(_cls, "<Control-v>", _clip("<<Paste>>"))
        root.bind_class(_cls, "<Control-V>", _clip("<<Paste>>"))
        root.bind_class(_cls, "<Control-c>", _clip("<<Copy>>"))
        root.bind_class(_cls, "<Control-C>", _clip("<<Copy>>"))
        root.bind_class(_cls, "<Control-x>", _clip("<<Cut>>"))
        root.bind_class(_cls, "<Control-X>", _clip("<<Cut>>"))
        root.bind_class(_cls, "<Button-3>", _clip_menu)
# ===== SNAPSMACK EOF =====
