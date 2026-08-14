"""
SHOTS FIRED — agenda.py

The fleet-wide agenda: every upcoming scheduled post from every site, grouped by
day, one colour-coded row per post, with a MOVE control that opens the reschedule
dialog. This is the "SEE the whole lineup and SHUFFLE it" surface.

The view is deliberately an agenda (a scrollable day-grouped list) rather than a
month grid: a fleet's queue is sparse and spread across many sites, so a vertical
timeline reads better than 30 tiny month cells and scales to any look-ahead. A
month grid can layer on later; the data model (ScheduledPost with an img_date) is
grid-ready.

Reschedule ergonomics follow the family rule for forgiving tools: the MOVE button
is large and sits apart from CANCEL, the target date/time are plain typed fields
pre-filled from the current date, and the destructive-looking confirm is a single
deliberate click — no drag needed to move a post (drag can be added as a shortcut,
but is never the ONLY way).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""


import datetime
import tkinter as tk
from typing import Callable, Dict, List, Optional

import ui
from schedule_client import ScheduledPost


_DAY_FMT = "%A  %d %B %Y"


class AgendaView(tk.Frame):
    """Scrollable, day-grouped list of the fleet's scheduled posts."""

    def __init__(self, parent: tk.Widget,
                 on_reschedule: Callable[[ScheduledPost, datetime.datetime], None]):
        super().__init__(parent, bg=ui.BG_DEEP)
        self._on_reschedule = on_reschedule
        self._swatch_for: Dict[str, str] = {}
        self._build()

    # -- scaffolding --------------------------------------------------------

    def _build(self) -> None:
        self._canvas = tk.Canvas(self, bg=ui.BG_DEEP, highlightthickness=0, bd=0)
        vbar = tk.Scrollbar(self, orient="vertical", command=self._canvas.yview)
        self._canvas.configure(yscrollcommand=vbar.set)
        vbar.pack(side="right", fill="y")
        self._canvas.pack(side="left", fill="both", expand=True)

        self._body = tk.Frame(self._canvas, bg=ui.BG_DEEP)
        self._win = self._canvas.create_window((0, 0), window=self._body, anchor="nw")
        self._body.bind("<Configure>",
                        lambda e: self._canvas.configure(scrollregion=self._canvas.bbox("all")))
        self._canvas.bind("<Configure>",
                          lambda e: self._canvas.itemconfigure(self._win, width=e.width))
        # Mouse-wheel scrolling (Windows delta).
        self._canvas.bind_all("<MouseWheel>",
                              lambda e: self._canvas.yview_scroll(int(-e.delta / 120), "units"))

    def _swatch(self, key: str) -> str:
        if key not in self._swatch_for:
            self._swatch_for[key] = ui.SITE_SWATCHES[len(self._swatch_for) % len(ui.SITE_SWATCHES)]
        return self._swatch_for[key]

    # -- population ---------------------------------------------------------

    def set_posts(self, posts: List[ScheduledPost], notes: Optional[List[str]] = None) -> None:
        """Rebuild the agenda from a flat list of ScheduledPost (any order) plus
        optional per-site status notes (e.g. 'foo.fyi — no scheduling API yet')."""
        for w in self._body.winfo_children():
            w.destroy()

        # Per-site status notes (unreachable / not-deployed / no key) up top so the
        # operator always knows which sites are NOT represented below.
        for note in (notes or []):
            tk.Label(self._body, text=note, bg=ui.BG_DEEP, fg=ui.FG_WARN,
                     font=ui.FONT_SMALL, anchor="w").pack(fill="x", padx=14, pady=(4, 0))

        if not posts:
            tk.Label(self._body,
                     text="No upcoming scheduled posts in the look-ahead window.\n"
                          "Future-dated posts made in SYBU / COLD SNAP appear here.",
                     bg=ui.BG_DEEP, fg=ui.FG_DIM, font=ui.FONT_UI,
                     justify="left").pack(anchor="w", padx=16, pady=16)
            return

        # Group by calendar day.
        by_day: Dict[datetime.date, List[ScheduledPost]] = {}
        for p in posts:
            by_day.setdefault(p.img_date.date(), []).append(p)

        today = datetime.date.today()
        for day in sorted(by_day):
            self._day_header(day, today, len(by_day[day]))
            for p in sorted(by_day[day], key=lambda x: x.img_date):
                self._post_row(p)

    def _day_header(self, day: datetime.date, today: datetime.date, count: int) -> None:
        rel = (day - today).days
        when = "TODAY" if rel == 0 else ("TOMORROW" if rel == 1 else f"in {rel} days")
        bar = tk.Frame(self._body, bg=ui.BG_MID)
        bar.pack(fill="x", padx=8, pady=(12, 0))
        tk.Label(bar, text=day.strftime(_DAY_FMT).upper(), bg=ui.BG_MID,
                 fg=ui.ACCENT, font=ui.FONT_BOLD).pack(side="left", padx=10, pady=6)
        tk.Label(bar, text=f"{when}   •   {count} post{'s' if count != 1 else ''}",
                 bg=ui.BG_MID, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(side="right", padx=10)

    def _post_row(self, p: ScheduledPost) -> None:
        row = tk.Frame(self._body, bg=ui.BG_CARD, highlightbackground="#2A2A2A",
                       highlightthickness=1)
        row.pack(fill="x", padx=8, pady=(4, 0))

        # Site colour swatch (left rail).
        tk.Frame(row, bg=self._swatch(p.site_url), width=5).pack(side="left", fill="y")

        # Time.
        tk.Label(row, text=p.img_date.strftime("%H:%M"), bg=ui.BG_CARD,
                 fg=ui.FG_MAIN, font=ui.FONT_MONO, width=6).pack(side="left", padx=(10, 6), pady=8)

        # Title + site.
        col = tk.Frame(row, bg=ui.BG_CARD)
        col.pack(side="left", fill="x", expand=True, pady=6)
        tk.Label(col, text=(p.title or "(untitled)"), bg=ui.BG_CARD, fg=ui.FG_MAIN,
                 font=ui.FONT_UI, anchor="w").pack(fill="x")
        tk.Label(col, text=p.site_name, bg=ui.BG_CARD,
                 fg=self._swatch(p.site_url), font=ui.FONT_SMALL, anchor="w").pack(fill="x")

        # MOVE control — large, on the right, sits apart from everything else.
        ui.button(row, "MOVE…", lambda: self._open_move_dialog(p),
                  kind="primary").pack(side="right", padx=10)

    # -- reschedule dialog --------------------------------------------------

    def _open_move_dialog(self, p: ScheduledPost) -> None:
        dlg = tk.Toplevel(self)
        dlg.title("Reschedule")
        dlg.configure(bg=ui.BG_DEEP)
        dlg.transient(self.winfo_toplevel())
        dlg.grab_set()
        dlg.resizable(False, False)

        body = ui.box(dlg, "MOVE THIS POST")
        tk.Label(body, text=(p.title or "(untitled)"), bg=ui.BG_CARD, fg=ui.FG_MAIN,
                 font=ui.FONT_BOLD, wraplength=340, justify="left").pack(anchor="w")
        tk.Label(body, text=f"{p.site_name}   —   currently "
                            f"{p.img_date.strftime('%Y-%m-%d %H:%M')}",
                 bg=ui.BG_CARD, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(anchor="w", pady=(0, 6))

        date_var = tk.StringVar(value=p.img_date.strftime("%Y-%m-%d"))
        time_var = tk.StringVar(value=p.img_date.strftime("%H:%M"))
        ui.field(body, "NEW DATE  (YYYY-MM-DD)", date_var)
        ui.field(body, "NEW TIME  (HH:MM, 24h)", time_var)

        err = tk.Label(body, text="", bg=ui.BG_CARD, fg=ui.FG_ERR, font=ui.FONT_SMALL)
        err.pack(anchor="w", pady=(6, 0))

        btns = tk.Frame(body, bg=ui.BG_CARD)
        btns.pack(fill="x", pady=(10, 2))

        def _confirm():
            new_dt = _parse_local(date_var.get(), time_var.get())
            if new_dt is None:
                err.configure(text="Enter a valid date (YYYY-MM-DD) and time (HH:MM).")
                return
            dlg.destroy()
            # Hand the network write + refresh back to the App.
            self._on_reschedule(p, new_dt)

        # CANCEL on the left, the big MOVE on the right — separated so a mis-click
        # (Parkinson's-forgiving) can't confirm when the operator meant to cancel.
        ui.button(btns, "CANCEL", dlg.destroy, kind="normal").pack(side="left")
        mv = ui.button(btns, "MOVE POST", _confirm, kind="primary")
        mv.configure(padx=22, pady=8)
        mv.pack(side="right")

        dlg.bind("<Return>", lambda e: _confirm())
        dlg.bind("<Escape>", lambda e: dlg.destroy())
        _center_on_parent(dlg, self.winfo_toplevel())


def _parse_local(date_s: str, time_s: str) -> Optional[datetime.datetime]:
    date_s = (date_s or "").strip()
    time_s = (time_s or "").strip() or "00:00"
    for tfmt in ("%H:%M", "%H:%M:%S"):
        try:
            return datetime.datetime.strptime(f"{date_s} {time_s}", f"%Y-%m-%d {tfmt}")
        except ValueError:
            continue
    return None


def _center_on_parent(win: tk.Toplevel, parent: tk.Misc) -> None:
    try:
        win.update_idletasks()
        px, py = parent.winfo_rootx(), parent.winfo_rooty()
        pw, ph = parent.winfo_width(), parent.winfo_height()
        ww, wh = win.winfo_width(), win.winfo_height()
        win.geometry(f"+{px + (pw - ww) // 2}+{py + (ph - wh) // 3}")
    except Exception:
        pass
# ===== SNAPSMACK EOF =====
