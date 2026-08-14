"""
SHOTS FIRED — fleet-wide scheduled-post calendar (see + shuffle the queue).

A desktop board that pulls the FUTURE-dated (scheduled) posts from every fleet
site into one agenda so the operator can SEE the whole lineup and SHUFFLE it —
move a post to a new day, spread a bunched week out, bump one. Scheduling itself
already works per-site: the CMS feed query is
    WHERE img_status='published' AND img_date <= NOW()
so a post with a FUTURE img_date is already scheduled — the spoke holds it and
reveals it on its date. What was missing is a centralized VIEW to rearrange the
queue. SHOTS FIRED does NOT create posts (that's SYBU / COLD SNAP); it only
surfaces and reschedules what's already queued.

This file is the host App: it bootstraps tools/_shared, loads the fleet from the
shared profile store, pulls each site's upcoming posts (schedule_client), renders
the agenda, and writes an img_date change back when the operator moves a post.

The two server routes it calls DO NOT EXIST YET — both are MUST-ADD (see
docs/shots-fired-spec.md). Until a spoke has them the client 404s and that site
shows "no scheduling API yet" while the rest of the fleet still loads.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""


BUILD_VERSION = "0.1.0"

# ---------------------------------------------------------------------------
# Debug log — redirect stdout/stderr to a log file before any other import so
# library warnings are captured too. Mirrors COLD SNAP's _setup_log().
# ---------------------------------------------------------------------------
import os
import sys


def _setup_log() -> str:
    """Open the SHOTS FIRED debug log. Prefers the shared
    C:\\snapsmack\\logs\\shots-fired_debug_<ts>.log; falls back to a next-to-exe file
    when the shared home isn't reachable. Returns the path."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    log_path = os.path.join(base, 'shots-fired-debug.log')
    try:
        _sd = os.path.join(base, '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
        import snap_home
        log_path = snap_home.log_path('shots-fired', 'debug')
    except Exception:
        pass  # shared home unreachable — keep the next-to-exe path
    try:
        _lf = open(log_path, 'a', encoding='utf-8', buffering=1)
        import datetime
        _lf.write(f"\n{'='*60}\n"
                  f"  SHOTS FIRED {BUILD_VERSION}  —  "
                  f"{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n"
                  f"{'='*60}\n")
        sys.stdout = _lf
        sys.stderr = _lf
    except Exception:
        pass  # if we can't open the log, don't crash — just run silently
    return log_path


LOG_PATH = _setup_log()

import threading
import tkinter as tk
from tkinter import messagebox
from typing import List

import config as cfg_module
import fleet as fleet_module
import ui
from agenda import AgendaView
from schedule_client import (ApiStatus, ScheduledPost, list_scheduled,
                             reschedule)


WIN_W, WIN_H = 1040, 760
LOOKAHEAD_CHOICES = [14, 30, 60, 90, 180, 365]


class App(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title(f"SHOTS FIRED  —  build {BUILD_VERSION}")
        self.geometry(f"{WIN_W}x{WIN_H}")
        self.minsize(900, 600)
        self.configure(bg=ui.BG_DEEP)

        ui.install_clipboard_bindings(self)
        ui.apply_ttk_style(self)

        # Window/taskbar icon — only if an assets/shots-fired.ico is bundled.
        try:
            if getattr(sys, 'frozen', False):
                _ico = os.path.join(sys._MEIPASS, 'assets', 'shots-fired.ico')
            else:
                _ico = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                    'assets', 'shots-fired.ico')
            if os.path.exists(_ico):
                self.iconbitmap(_ico)
        except Exception:
            pass  # non-fatal — default tkinter feather

        self._config = cfg_module.load()
        self._fleet: List[fleet_module.Site] = []
        self._loading = False

        self._lookahead_var = tk.StringVar(value=str(self._config.get('lookahead_days', 60)))
        self._status_var = tk.StringVar(value="Loading fleet…")

        self._build_ui()
        self.after(150, self.refresh)

    # ------------------------------------------------------------------
    # UI
    # ------------------------------------------------------------------

    def _build_ui(self):
        bar = tk.Frame(self, bg=ui.BG_MID)
        bar.pack(fill="x", side="top")

        tk.Label(bar, text="SHOTS FIRED", bg=ui.BG_MID, fg=ui.ACCENT,
                 font=ui.FONT_TITLE).pack(side="left", padx=(14, 6), pady=10)
        tk.Label(bar, text="fleet-wide scheduled-post calendar", bg=ui.BG_MID,
                 fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(side="left", pady=10)

        ui.button(bar, "REFRESH", self.refresh, kind="primary").pack(side="right", padx=12)

        tk.Label(bar, text="LOOK AHEAD", bg=ui.BG_MID, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="right", padx=(0, 4))
        cb = ui.combo(bar, self._lookahead_var,
                      [f"{d} days" for d in LOOKAHEAD_CHOICES], width=10)
        # Store the raw day count while showing "<n> days".
        cb.configure(values=[f"{d} days" for d in LOOKAHEAD_CHOICES])
        self._lookahead_var.set(f"{self._config.get('lookahead_days', 60)} days")
        cb.pack(side="right", padx=4, pady=10)
        cb.bind("<<ComboboxSelected>>", lambda e: self.refresh())

        # Agenda.
        self._agenda = AgendaView(self, on_reschedule=self._handle_reschedule)
        self._agenda.pack(fill="both", expand=True)

        # Status line.
        status = tk.Frame(self, bg=ui.BG_MID)
        status.pack(fill="x", side="bottom")
        tk.Label(status, textvariable=self._status_var, bg=ui.BG_MID, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL, anchor="w").pack(side="left", padx=12, pady=5)

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------

    def _lookahead_days(self) -> int:
        raw = self._lookahead_var.get().split()[0]
        try:
            return max(1, int(raw))
        except ValueError:
            return 60

    def _set_status(self, text: str, color: str = ui.FG_DIM):
        self._status_var.set(text)
        # (Label colour follows the theme's dim by default; kept simple.)

    # ------------------------------------------------------------------
    # Load / refresh — networking on a worker thread, UI on the main thread.
    # ------------------------------------------------------------------

    def refresh(self):
        if self._loading:
            return
        self._loading = True
        days = self._lookahead_days()
        self._config['lookahead_days'] = days
        cfg_module.save(self._config)
        self._set_status("Loading fleet + pulling scheduled posts…")
        threading.Thread(target=self._load_worker, args=(days,), daemon=True).start()

    def _load_worker(self, days: int):
        """Runs off the UI thread: enumerate the fleet, pull each site's queue."""
        posts: List[ScheduledPost] = []
        notes: List[str] = []
        try:
            fleet = fleet_module.load_fleet()
            self._fleet = fleet
            if not fleet:
                notes.append("No sites configured yet — set a blog up in SYBU or "
                             "COLD SNAP and it appears here.")
            for site in fleet:
                res = list_scheduled(site, lookahead_days=days)
                if res.status is ApiStatus.OK:
                    posts.extend(res.posts)
                    if not res.posts:
                        notes.append(f"{site.display} — no scheduled posts")
                elif res.status is ApiStatus.NOT_DEPLOYED:
                    notes.append(f"{site.display} — no scheduling API yet "
                                 f"(server route not deployed)")
                elif res.status is ApiStatus.UNAUTHORIZED:
                    notes.append(f"{site.display} — {res.message}")
                else:
                    notes.append(f"{site.display} — {res.message}")
        except Exception as e:
            notes.append(f"load failed: {e}")
        self.after(0, lambda: self._apply_load(posts, notes))

    def _apply_load(self, posts: List[ScheduledPost], notes: List[str]):
        self._agenda.set_posts(posts, notes)
        n = len(posts)
        sites = len(self._fleet)
        self._set_status(f"{n} scheduled post{'s' if n != 1 else ''} across "
                         f"{sites} site{'s' if sites != 1 else ''}   •   "
                         f"look-ahead {self._lookahead_days()} days")
        self._loading = False

    # ------------------------------------------------------------------
    # Reschedule — worker thread, then refresh.
    # ------------------------------------------------------------------

    def _handle_reschedule(self, post: ScheduledPost, new_dt):
        site = self._site_for(post)
        if site is None:
            messagebox.showerror("Reschedule",
                                 "Could not match this post to a fleet site.")
            return
        self._set_status(f"Moving “{post.title or 'post'}” to "
                         f"{new_dt.strftime('%Y-%m-%d %H:%M')}…")
        threading.Thread(target=self._reschedule_worker,
                         args=(site, post, new_dt), daemon=True).start()

    def _reschedule_worker(self, site, post: ScheduledPost, new_dt):
        res = reschedule(site, post.snap_id, new_dt)
        self.after(0, lambda: self._apply_reschedule(res, post, new_dt))

    def _apply_reschedule(self, res, post: ScheduledPost, new_dt):
        if res.status is ApiStatus.OK:
            self._set_status(f"Moved “{post.title or 'post'}” to "
                             f"{new_dt.strftime('%Y-%m-%d %H:%M')}.")
            self.refresh()
            return
        if res.status is ApiStatus.NOT_DEPLOYED:
            messagebox.showwarning(
                "No scheduling API yet",
                f"{post.site_name} does not have the reschedule endpoint deployed "
                f"yet, so the post could not be moved.\n\n"
                f"(Server MUST-ADD route: POST smack-schedule.php?action=set_date — "
                f"see the SHOTS FIRED spec.)")
        else:
            messagebox.showerror("Reschedule failed",
                                 f"{post.site_name}: {res.message}")
        self._set_status("Reschedule failed — see dialog.")

    def _site_for(self, post: ScheduledPost):
        for s in self._fleet:
            if s.url == post.site_url:
                return s
        return None


def main():
    App().mainloop()


if __name__ == "__main__":
    main()
# ===== SNAPSMACK EOF =====
