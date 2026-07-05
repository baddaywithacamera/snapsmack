"""
COLD SNAP — offline store-and-forward poster for SnapSmack.

A minimal desktop shell around the shared offline posting suite: a CONNECTION
panel (site URL + API key + saved-profile picker) feeding two offline modes —
COLD ONE (solo) and COLD STACK (gram/trigram). The heavy lifting lives
in sumna_offline / sumna_post / sumna_solo / sumna_gram; this file is just the
host App that supplies `_config` (url / api_key) and `_site_data` to them.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


BUILD_VERSION = "0.1.0"

# ---------------------------------------------------------------------------
# Debug log — redirect stdout/stderr to coldsnap-debug.log next to the exe.
# Must happen before any other import so library warnings are captured too.
# ---------------------------------------------------------------------------
import os
import sys

def _setup_log() -> str:
    """Open coldsnap-debug.log next to the exe (or source file when dev).  Returns path."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    log_path = os.path.join(base, 'coldsnap-debug.log')
    try:
        _lf = open(log_path, 'a', encoding='utf-8', buffering=1)
        import datetime
        _lf.write(f"\n{'='*60}\n"
                  f"  COLD SNAP {BUILD_VERSION}  —  "
                  f"{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n"
                  f"{'='*60}\n")
        sys.stdout = _lf
        sys.stderr = _lf
    except Exception:
        pass  # if we can't open the log, don't crash — just run silently
    return log_path

LOG_PATH = _setup_log()

import tkinter as tk
from tkinter import messagebox, ttk
from typing import Optional

import config as cfg_module
import profile_manager
import sumna_ui as ui

# Offline posting suite (COLD ONE + COLD STACK; longform COLD TAKE deferred). Guarded so a missing
# optional dep can never stop COLD SNAP's shell from launching.
try:
    from sumna_solo import build_solo_mode
    from sumna_gram import build_gram_mode
    _SUMNA_AVAILABLE = True
    _SUMNA_IMPORT_ERROR = ""
except Exception as _sumna_err:  # pragma: no cover - import shim
    _SUMNA_AVAILABLE = False
    _SUMNA_IMPORT_ERROR = str(_sumna_err)


WIN_W, WIN_H = 1000, 720


class App(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title(f"COLD SNAP  —  build {BUILD_VERSION}")
        self.geometry(f"{WIN_W}x{WIN_H}")
        self.minsize(860, 600)
        self.configure(bg=ui.BG_DEEP)

        # Clipboard: Windows Tk sometimes fails to wire Ctrl+V on Entry/Text, and
        # Tk ships no right-click menu — so pasting an API key was impossible.
        # Bind cut/copy/paste at the class level for every text widget, plus a
        # right-click context menu, so paste works by keyboard or mouse everywhere.
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
            m = tk.Menu(self, tearoff=0)
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
            self.bind_class(_cls, "<Control-v>", _clip("<<Paste>>"))
            self.bind_class(_cls, "<Control-V>", _clip("<<Paste>>"))
            self.bind_class(_cls, "<Control-c>", _clip("<<Copy>>"))
            self.bind_class(_cls, "<Control-C>", _clip("<<Copy>>"))
            self.bind_class(_cls, "<Control-x>", _clip("<<Cut>>"))
            self.bind_class(_cls, "<Control-X>", _clip("<<Cut>>"))
            self.bind_class(_cls, "<Button-3>", _clip_menu)

        # Set window/taskbar icon explicitly — the exe icon set via PyInstaller
        # only affects File Explorer; tkinter needs iconbitmap() for the taskbar.
        try:
            if getattr(sys, 'frozen', False):
                _ico = os.path.join(sys._MEIPASS, 'assets', 'coldsnap.ico')
            else:
                _ico = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                    'assets', 'coldsnap.ico')
            if os.path.exists(_ico):
                self.iconbitmap(_ico)
        except Exception:
            pass  # non-fatal — falls back to default tkinter feather

        # State — the panels read getattr(self, "_config", {}) for url/api_key
        # and getattr(self, "_site_data", None). We must supply both.
        self._config    = cfg_module.load()
        self._site_data: Optional[object] = None

        self._url_var     = tk.StringVar(value=self._config.get('url', ''))
        self._api_key_var = tk.StringVar(value=self._config.get('api_key', ''))
        self._profile_var = tk.StringVar()

        self._active_tab = None
        self._apply_ttk_style()
        self._build_ui()
        self._switch_tab('slapped')

    # ------------------------------------------------------------------
    # TTK style
    # ------------------------------------------------------------------

    def _apply_ttk_style(self):
        style = ttk.Style(self)
        try:
            style.theme_use("clam")
        except tk.TclError:
            pass
        style.configure("TCombobox",
                        fieldbackground=ui.BG_MID, background=ui.BG_MID,
                        foreground=ui.FG_MAIN, arrowcolor=ui.ACCENT,
                        bordercolor="#2A2A2A", relief="flat")
        style.map("TCombobox",
                  fieldbackground=[("readonly", ui.BG_MID)],
                  foreground=[("readonly", ui.FG_MAIN)])

    # ------------------------------------------------------------------
    # UI
    # ------------------------------------------------------------------

    def _build_ui(self):
        # ── CONNECTION panel ──────────────────────────────────────────
        conn = tk.Frame(self, bg=ui.BG_DEEP)
        conn.pack(fill="x", side="top")
        body = ui.box(conn, "CONNECTION")

        # Profile picker — load a saved site profile's url + api key.
        tk.Label(body, text="LOAD PROFILE", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(anchor="w", pady=(6, 0))
        self._profile_cb = ttk.Combobox(body, textvariable=self._profile_var,
                                        values=profile_manager.list_profiles(),
                                        state="readonly")
        self._profile_cb.pack(fill="x")
        self._profile_cb.bind("<<ComboboxSelected>>", self._on_profile_pick)

        ui.field(body, "SITE URL", self._url_var)
        ui.field(body, "API KEY", self._api_key_var, show="•")

        btn_row = tk.Frame(body, bg=ui.BG_CARD)
        btn_row.pack(fill="x", pady=(8, 0))
        ui.button(btn_row, "SAVE / APPLY", self._on_save, kind="primary").pack(side="right")

        self._conn_status = tk.Label(btn_row, text="", bg=ui.BG_CARD,
                                     fg=ui.FG_DIM, font=ui.FONT_SMALL)
        self._conn_status.pack(side="left")

        # ── Tab strip ─────────────────────────────────────────────────
        tabs = tk.Frame(self, bg=ui.BG_MID)
        tabs.pack(fill="x", side="top")
        self._tab_btns = {}
        self._tab_indicators = {}
        for name, label in (("slapped", "COLD ONE"), ("gram", "COLD STACK")):
            cell = tk.Frame(tabs, bg=ui.BG_MID)
            cell.pack(side="left")
            btn = tk.Label(cell, text=label, bg=ui.BG_MID, fg=ui.FG_DIM,
                           font=ui.FONT_BOLD, padx=18, pady=10, cursor="hand2")
            btn.pack()
            ind = tk.Frame(cell, bg=ui.BG_MID, height=2)
            ind.pack(fill="x")
            btn.bind("<Button-1>", lambda e, n=name: self._switch_tab(n))
            self._tab_btns[name] = btn
            self._tab_indicators[name] = ind

        # ── Mode frames ───────────────────────────────────────────────
        self._slapped_frame = tk.Frame(self, bg=ui.BG_DEEP)
        self._gram_frame    = tk.Frame(self, bg=ui.BG_DEEP)
        self._build_modes()

    def _build_modes(self):
        """Mount COLD ONE + COLD STACK. A panel error shows a label
        rather than taking down the whole tool."""
        if not _SUMNA_AVAILABLE:
            for fr in (self._slapped_frame, self._gram_frame):
                tk.Label(fr,
                         text=f"Offline suite failed to import:\n{_SUMNA_IMPORT_ERROR}",
                         bg=ui.BG_DEEP, fg=ui.FG_ERR, font=ui.FONT_UI,
                         justify="left").pack(padx=20, pady=20)
            return
        try:
            build_solo_mode(self._slapped_frame, self).pack(fill="both", expand=True)
            build_gram_mode(self._gram_frame, self).pack(fill="both", expand=True)
        except Exception as e:
            for fr in (self._slapped_frame, self._gram_frame):
                for w in fr.winfo_children():
                    w.destroy()
                tk.Label(fr, text=f"COLD SNAP panel failed to load:\n{e}",
                         bg=ui.BG_DEEP, fg=ui.FG_ERR, font=ui.FONT_UI,
                         justify="left").pack(padx=20, pady=20)

    # ------------------------------------------------------------------
    # Tab switching
    # ------------------------------------------------------------------

    def _switch_tab(self, tab: str):
        if tab == self._active_tab:
            return
        for name, btn in self._tab_btns.items():
            active = (name == tab)
            btn.configure(fg=ui.ACCENT if active else ui.FG_DIM)
            self._tab_indicators[name].configure(bg=ui.ACCENT if active else ui.BG_MID)
        self._slapped_frame.pack_forget()
        self._gram_frame.pack_forget()
        if tab == 'slapped':
            self._slapped_frame.pack(fill="both", expand=True)
        elif tab == 'gram':
            self._gram_frame.pack(fill="both", expand=True)
        self._active_tab = tab

    # ------------------------------------------------------------------
    # Connection actions
    # ------------------------------------------------------------------

    def _on_profile_pick(self, _evt=None):
        name = self._profile_var.get().strip()
        if not name:
            return
        prof = profile_manager.load_profile(name)
        if not prof:
            self._conn_status.configure(text="Profile not found.", fg=ui.FG_ERR)
            return
        if prof.get('url'):
            self._url_var.set(prof.get('url', ''))
        # Profiles from the SYBU suite may not carry an api_key field; only
        # overwrite the key field if the profile actually supplies one.
        if prof.get('api_key'):
            self._api_key_var.set(prof.get('api_key', ''))
        self._conn_status.configure(text=f"Loaded '{name}' — review + SAVE / APPLY.",
                                    fg=ui.FG_WARN)

    def _on_save(self):
        url = self._url_var.get().strip()
        key = self._api_key_var.get().strip()
        if not url or not key:
            messagebox.showwarning("Missing details",
                                   "Both SITE URL and API KEY are required.")
            return
        self._config['url'] = url
        self._config['api_key'] = key
        try:
            cfg_module.save(self._config)
        except Exception as e:
            self._conn_status.configure(text=f"Save failed: {e}", fg=ui.FG_ERR)
            return
        self._conn_status.configure(text="Saved. Connection ready.", fg=ui.FG_OK)


if __name__ == "__main__":
    App().mainloop()
# ===== SNAPSMACK EOF =====
