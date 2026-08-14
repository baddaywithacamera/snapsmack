"""
CRONOMETER — fleet cron / job-health console for SnapSmack.

A cron + chronometer pun: it times the crons. One desktop board that, per fleet
site, shows the health of the scheduled jobs — RSS fetch, version check, fediverse
delivery, backups, SMACKBACK integrity — as red / amber / grey / green, so a
silently-dead cron is CAUGHT before it bites (the "backups went red weeks ago and
nobody noticed" scenario). This is an INSURANCE tool: surfacing silent failure is
the entire point, so a job the site can't yet report is shown as an explicit grey
"not reported" caution, never a fabricated green.

The fleet loads from the ONE shared per-site profile store (tools/_shared), so any
blog set up in SYBU / GYSS / COLD SNAP appears here with no re-typing. Each site
gets a RE-CHECK button; REFRESH ALL re-polls the whole board. Polling runs off the
UI thread; results are marshalled back with Tk's `after`.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


BUILD_VERSION = "0.1.0"

# ---------------------------------------------------------------------------
# Shared-path bootstrap + debug log. Must happen before any _shared import so
# library warnings are captured too. Copied from COLD SNAP's convention.
# ---------------------------------------------------------------------------
import os
import sys


def _add_shared_to_path() -> None:
    """Put tools/_shared (C:\\snapsmack\\_shared at runtime) on sys.path."""
    try:
        if getattr(sys, 'frozen', False):
            base = os.path.dirname(sys.executable)
        else:
            base = os.path.dirname(os.path.abspath(__file__))
        _sd = os.path.join(base, '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
    except Exception:
        pass


def _setup_log() -> str:
    """Open the CRONOMETER debug log (stdout/stderr). Prefers the shared
    C:\\snapsmack\\logs\\cronometer_debug_<ts>.log; falls back to cronometer-debug.log
    next to the exe when the shared home isn't reachable. Returns the path."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    log_path = os.path.join(base, 'cronometer-debug.log')
    try:
        _add_shared_to_path()
        import snap_home
        log_path = snap_home.log_path('cronometer', 'debug')
    except Exception:
        pass  # shared home unreachable — keep the next-to-exe path
    try:
        _lf = open(log_path, 'a', encoding='utf-8', buffering=1)
        import datetime
        _lf.write(f"\n{'='*60}\n"
                  f"  CRONOMETER {BUILD_VERSION}  —  "
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
from tkinter import messagebox, ttk
from concurrent.futures import ThreadPoolExecutor
from typing import List, Optional

import config as cfg_module
import heartbeat_client as hb


# ── Palette (mirrors the SnapSmack admin dark theme; see sumna_ui.py) ────────
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
FG_GREY  = "#8A8A8A"

FONT_UI    = ("Segoe UI", 9)
FONT_BOLD  = ("Segoe UI", 9, "bold")
FONT_SMALL = ("Segoe UI", 8)
FONT_TITLE = ("Segoe UI", 13, "bold")

# Severity -> (dot colour, short word). One place maps the client's verdicts to
# the board's visual language.
SEV_STYLE = {
    hb.SEV_OK:      (FG_OK,   "OK"),
    hb.SEV_STALE:   (FG_WARN, "STALE"),
    hb.SEV_UNKNOWN: (FG_GREY, "UNKNOWN"),
    hb.SEV_FAILED:  (FG_ERR,  "FAILED"),
    hb.SEV_NA:      (FG_DIM,  "N/A"),
    hb.SEV_OFFLINE: (FG_ERR,  "OFFLINE"),
}


def _sev_style(sev: str):
    return SEV_STYLE.get(sev, (FG_GREY, sev.upper()))


WIN_W, WIN_H = 940, 720


class App(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title(f"CRONOMETER  —  build {BUILD_VERSION}")
        self.geometry(f"{WIN_W}x{WIN_H}")
        self.minsize(760, 520)
        self.configure(bg=BG_DEEP)

        self._prefs = cfg_module.load()
        self._timeout = int(self._prefs.get('poll_timeout',
                                            cfg_module.DEFAULT_POLL_TIMEOUT))
        self._fleet: List[dict] = []
        self._site_cards = {}          # url -> dict of widgets to update in place
        self._busy = False
        self._pool = ThreadPoolExecutor(max_workers=6)

        self._apply_ttk_style()
        self._build_ui()
        self._load_fleet()
        # Kick an initial sweep so the board isn't blank on open.
        self.after(200, self.refresh_all)
        self.protocol("WM_DELETE_WINDOW", self._on_close)

    # ------------------------------------------------------------------
    def _apply_ttk_style(self):
        style = ttk.Style(self)
        try:
            style.theme_use("clam")
        except tk.TclError:
            pass
        style.configure("Cron.Vertical.TScrollbar",
                        background=BG_HOVER, troughcolor=BG_MID,
                        bordercolor=BG_MID, arrowcolor=FG_DIM)

    # ------------------------------------------------------------------
    # UI scaffold
    # ------------------------------------------------------------------
    def _build_ui(self):
        # ── Top bar ───────────────────────────────────────────────────
        bar = tk.Frame(self, bg=BG_MID)
        bar.pack(fill="x", side="top")
        tk.Label(bar, text="CRONOMETER", bg=BG_MID, fg=ACCENT,
                 font=FONT_TITLE, padx=14, pady=10).pack(side="left")
        tk.Label(bar, text="fleet cron / job-health", bg=BG_MID, fg=FG_DIM,
                 font=FONT_SMALL).pack(side="left", pady=(14, 0))

        self._refresh_btn = self._make_button(bar, "REFRESH ALL", self.refresh_all,
                                              kind="primary")
        self._refresh_btn.pack(side="right", padx=(6, 12), pady=8)
        self._make_button(bar, "RELOAD FLEET", self._reload_fleet).pack(
            side="right", padx=6, pady=8)

        self._status = tk.Label(bar, text="", bg=BG_MID, fg=FG_DIM,
                                font=FONT_SMALL)
        self._status.pack(side="right", padx=8)

        # ── Legend ────────────────────────────────────────────────────
        legend = tk.Frame(self, bg=BG_DEEP)
        legend.pack(fill="x", side="top")
        for sev in (hb.SEV_OK, hb.SEV_STALE, hb.SEV_UNKNOWN,
                    hb.SEV_FAILED, hb.SEV_OFFLINE, hb.SEV_NA):
            colour, word = _sev_style(sev)
            cell = tk.Frame(legend, bg=BG_DEEP)
            cell.pack(side="left", padx=(12, 0), pady=4)
            tk.Label(cell, text="\u25CF", bg=BG_DEEP, fg=colour,
                     font=FONT_UI).pack(side="left")
            tk.Label(cell, text=word, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_SMALL).pack(side="left", padx=(4, 0))

        # ── Scrollable board ──────────────────────────────────────────
        outer = tk.Frame(self, bg=BG_DEEP)
        outer.pack(fill="both", expand=True)
        self._canvas = tk.Canvas(outer, bg=BG_DEEP, highlightthickness=0)
        vsb = ttk.Scrollbar(outer, orient="vertical", command=self._canvas.yview,
                            style="Cron.Vertical.TScrollbar")
        self._board = tk.Frame(self._canvas, bg=BG_DEEP)
        self._board.bind(
            "<Configure>",
            lambda e: self._canvas.configure(scrollregion=self._canvas.bbox("all")))
        self._board_win = self._canvas.create_window((0, 0), window=self._board,
                                                     anchor="nw")
        self._canvas.bind(
            "<Configure>",
            lambda e: self._canvas.itemconfigure(self._board_win, width=e.width))
        self._canvas.configure(yscrollcommand=vsb.set)
        self._canvas.pack(side="left", fill="both", expand=True)
        vsb.pack(side="right", fill="y")
        # Mouse wheel scroll.
        self._canvas.bind_all("<MouseWheel>",
                              lambda e: self._canvas.yview_scroll(
                                  int(-1 * (e.delta / 120)), "units"))

    def _make_button(self, parent, text, cmd, *, kind="normal"):
        fg = {"primary": BG_DEEP, "normal": FG_MAIN}.get(kind, FG_MAIN)
        bg = {"primary": ACCENT, "normal": BG_HOVER}.get(kind, BG_HOVER)
        return tk.Button(parent, text=text, command=cmd, bg=bg, fg=fg,
                         activebackground=bg, activeforeground=fg, relief="flat",
                         font=FONT_BOLD, padx=12, pady=5, cursor="hand2", bd=0)

    # ------------------------------------------------------------------
    # Fleet loading + board build
    # ------------------------------------------------------------------
    def _load_fleet(self):
        self._fleet = cfg_module.load_fleet()
        self._rebuild_board()

    def _reload_fleet(self):
        self._load_fleet()
        self.after(150, self.refresh_all)

    def _rebuild_board(self):
        for w in self._board.winfo_children():
            w.destroy()
        self._site_cards = {}

        if not self._fleet:
            tk.Label(self._board,
                     text=("No fleet sites found.\n\n"
                           "CRONOMETER reads the shared per-site profiles that SYBU, "
                           "GYSS and COLD SNAP write.\nSet up a site in one of those "
                           "tools (or confirm C:\\snapsmack\\shared_library\\profiles "
                           "exists),\nthen press RELOAD FLEET."),
                     bg=BG_DEEP, fg=FG_DIM, font=FONT_UI, justify="left").pack(
                         anchor="w", padx=20, pady=20)
            return

        for site in self._fleet:
            self._site_cards[site['url']] = self._build_site_card(site)

    def _build_site_card(self, site: dict) -> dict:
        wrap = tk.Frame(self._board, bg=BG_CARD, highlightbackground="#2A2A2A",
                        highlightthickness=1)
        wrap.pack(fill="x", padx=10, pady=6)

        head = tk.Frame(wrap, bg=BG_CARD)
        head.pack(fill="x", padx=10, pady=(8, 2))

        dot = tk.Label(head, text="\u25CF", bg=BG_CARD, fg=FG_DIM,
                       font=("Segoe UI", 14))
        dot.pack(side="left")
        tk.Label(head, text=site['name'], bg=BG_CARD, fg=FG_MAIN,
                 font=FONT_BOLD).pack(side="left", padx=(6, 0))
        ver = tk.Label(head, text="", bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL)
        ver.pack(side="left", padx=(8, 0))

        recheck = self._make_button(
            head, "RE-CHECK", lambda s=site: self.recheck_site(s))
        recheck.pack(side="right")
        summary = tk.Label(head, text="not checked yet", bg=BG_CARD, fg=FG_DIM,
                           font=FONT_SMALL)
        summary.pack(side="right", padx=(0, 10))

        url_lbl = tk.Label(wrap, text=site['url'], bg=BG_CARD, fg=FG_DIM,
                           font=FONT_SMALL)
        url_lbl.pack(anchor="w", padx=10)

        # Per-job rows — pre-built so a re-check updates in place (no flicker).
        jobs_frame = tk.Frame(wrap, bg=BG_CARD)
        jobs_frame.pack(fill="x", padx=10, pady=(6, 10))
        job_rows = {}
        for spec in hb.JOB_SPECS:
            row = tk.Frame(jobs_frame, bg=BG_CARD)
            row.pack(fill="x", pady=1)
            jdot = tk.Label(row, text="\u25CF", bg=BG_CARD, fg=FG_DIM,
                            font=FONT_UI, width=2)
            jdot.pack(side="left")
            tk.Label(row, text=spec.label, bg=BG_CARD, fg=FG_MAIN,
                     font=FONT_UI, width=22, anchor="w").pack(side="left")
            jstate = tk.Label(row, text="—", bg=BG_CARD, fg=FG_DIM,
                              font=FONT_BOLD, width=9, anchor="w")
            jstate.pack(side="left")
            jage = tk.Label(row, text="", bg=BG_CARD, fg=FG_DIM,
                            font=FONT_SMALL, width=12, anchor="w")
            jage.pack(side="left")
            jdetail = tk.Label(row, text="", bg=BG_CARD, fg=FG_DIM,
                               font=FONT_SMALL, anchor="w")
            jdetail.pack(side="left", padx=(6, 0))
            job_rows[spec.key] = (jdot, jstate, jage, jdetail)

        return {
            'dot': dot, 'ver': ver, 'summary': summary,
            'recheck': recheck, 'job_rows': job_rows,
        }

    # ------------------------------------------------------------------
    # Polling — off the UI thread, results marshalled back via `after`.
    # ------------------------------------------------------------------
    def refresh_all(self):
        if self._busy or not self._fleet:
            if not self._fleet:
                self._set_status("no sites to check")
            return
        self._set_busy(True)
        self._set_status(f"checking {len(self._fleet)} site(s)…")
        for card in self._site_cards.values():
            card['summary'].configure(text="checking…", fg=FG_WARN)
        remaining = {'n': len(self._fleet)}

        def _done(_fut, total=len(self._fleet)):
            remaining['n'] -= 1
            if remaining['n'] <= 0:
                self.after(0, lambda: self._sweep_finished(total))

        for site in list(self._fleet):
            fut = self._pool.submit(hb.probe, site, self._timeout)
            fut.add_done_callback(
                lambda f, s=site: (self._deliver(s, f), _done(f)))

    def recheck_site(self, site: dict):
        card = self._site_cards.get(site['url'])
        if card:
            card['summary'].configure(text="checking…", fg=FG_WARN)
            card['recheck'].configure(state="disabled")
        self._set_status(f"re-checking {site['name']}…")
        fut = self._pool.submit(hb.probe, site, self._timeout)
        fut.add_done_callback(lambda f, s=site: self._deliver(s, f, single=True))

    def _deliver(self, site: dict, fut, single: bool = False):
        try:
            health = fut.result()
        except Exception as e:  # probe() shouldn't raise, but never crash the UI
            health = hb.SiteHealth(name=site['name'], url=site['url'],
                                   online=False, error=f'internal error: {e}')
        self.after(0, lambda: self._render_site(site, health, single))

    def _render_site(self, site: dict, health: "hb.SiteHealth", single: bool):
        card = self._site_cards.get(site['url'])
        if not card:
            return
        overall = health.overall()
        colour, _word = _sev_style(overall)
        card['dot'].configure(fg=colour)
        card['ver'].configure(
            text=(f"v{health.version}" if health.version else ""))
        if card.get('recheck'):
            card['recheck'].configure(state="normal")

        if not health.online:
            card['summary'].configure(text=health.error or "offline", fg=FG_ERR)
            for spec in hb.JOB_SPECS:
                jdot, jstate, jage, jdetail = card['job_rows'][spec.key]
                jdot.configure(fg=FG_DIM)
                jstate.configure(text="—", fg=FG_DIM)
                jage.configure(text="")
                jdetail.configure(text="(site unreachable)")
            return

        # Online — paint each job row.
        counts = {}
        by_key = {j.key: j for j in health.jobs}
        for spec in hb.JOB_SPECS:
            jh = by_key.get(spec.key)
            jdot, jstate, jage, jdetail = card['job_rows'][spec.key]
            if jh is None:
                continue
            jcolour, jword = _sev_style(jh.severity)
            counts[jh.severity] = counts.get(jh.severity, 0) + 1
            jdot.configure(fg=jcolour)
            jstate.configure(text=jword, fg=jcolour)
            jage.configure(text=jh.age_text())
            jdetail.configure(text=jh.detail)
        # Summary line = the worst thing on the card, in words.
        summ_colour, summ_word = _sev_style(overall)
        n_bad = counts.get(hb.SEV_FAILED, 0)
        n_stale = counts.get(hb.SEV_STALE, 0)
        n_unk = counts.get(hb.SEV_UNKNOWN, 0)
        bits = []
        if n_bad:
            bits.append(f"{n_bad} failed")
        if n_stale:
            bits.append(f"{n_stale} stale")
        if n_unk:
            bits.append(f"{n_unk} not reported")
        summary = ", ".join(bits) if bits else "all jobs healthy"
        card['summary'].configure(text=summary, fg=summ_colour)

        if single:
            self._set_status(f"{site['name']}: {summ_word.lower()}")

    def _sweep_finished(self, total: int):
        self._set_busy(False)
        # Every card's dot is already painted by _render_site; roll them up to one
        # headline so the operator sees the fleet's worst state at a glance.
        self._set_status(f"checked {total} site(s) — {self._fleet_headline()}")

    def _fleet_headline(self) -> str:
        # Derive a headline from the current dot colours we set on each card.
        sev_by_colour = {v[0]: k for k, v in SEV_STYLE.items()}
        worst = hb.SEV_NA
        for card in self._site_cards.values():
            colour = card['dot'].cget('fg')
            sev = sev_by_colour.get(colour, hb.SEV_UNKNOWN)
            worst = hb.worst([worst, sev])
        _c, word = _sev_style(worst)
        return f"worst: {word}"

    # ------------------------------------------------------------------
    def _set_busy(self, busy: bool):
        self._busy = busy
        try:
            self._refresh_btn.configure(state="disabled" if busy else "normal")
        except tk.TclError:
            pass

    def _set_status(self, text: str):
        try:
            self._status.configure(text=text)
        except tk.TclError:
            pass

    def _on_close(self):
        try:
            geo = self.winfo_geometry()
            self._prefs['win_geometry'] = geo
            cfg_module.save(self._prefs)
        except Exception:
            pass
        try:
            self._pool.shutdown(wait=False)
        except Exception:
            pass
        self.destroy()


if __name__ == "__main__":
    App().mainloop()
# ===== SNAPSMACK EOF =====
