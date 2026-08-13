"""
THE HUB — SnapSmack unified desktop front end & launcher.

One door: launch every offline tool from here, and set the fleet up ONCE. Enter the
hub login and hit Discover Fleet — it fills the SHARED stores (snap_creds + snap_profiles)
that every tool reads, so SYBU / SUYB / GYSS / COLD SNAP all get every site and every
shared secret. No per-tool setup.

v1 scope: launch installed tools + shared setup/discovery. Fetching *missing* tools over
the network (the spec's hard "distribution" question) is deliberately out of v1.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import glob
import os
import subprocess
import sys
import tkinter as tk
from tkinter import filedialog, messagebox

BUILD_VERSION = "0.1.1"

# ── shared plumbing (C:\snapsmack\_shared at runtime, ../_shared in source) ──
def _add_shared_to_path():
    base = os.path.dirname(sys.executable) if getattr(sys, "frozen", False) \
        else os.path.dirname(os.path.abspath(__file__))
    for cand in (os.path.join(base, "..", "_shared"), os.path.join(base, "_shared")):
        cand = os.path.normpath(cand)
        if os.path.isdir(cand) and cand not in sys.path:
            sys.path.insert(0, cand)

_add_shared_to_path()
try:
    import snap_creds
    import snap_profiles
    import snap_discovery
    _SHARED_OK = True
except Exception as _e:                      # pragma: no cover
    _SHARED_OK = False
    _SHARED_ERR = str(_e)

# ── palette (matches the tool family: onyx + green) ─────────────────────────
BG      = "#0a0a0a"
CARD    = "#141414"
INK     = "#e6e6e6"
DIM     = "#8a8a8a"
ACCENT  = "#39ff14"
FIELD   = "#1c1c1c"
BORDER  = "#2a2a2a"

# ── the tools the Hub fronts, and where they install ────────────────────────
# Candidate exe locations per tool, most-preferred first. A candidate may contain a
# glob (`*`) so a versioned exe name (e.g. smackupyourbackup-0.7.20.exe) is still
# found. The shared-layout path (C:\snapsmack\<tool>) comes first so a freshly-built
# tool is launched in preference to an older copy at a legacy location.
ROSTER = [
    ("SMACK YOUR BATCH UP", "batch poster",        [r"C:\snapsmack\sybu\sybu.exe"]),
    ("GET YOUR SHIT SORTED", "offline sorter",     [r"C:\GYSS\GET YOUR SHIT SORTED.exe",
                                                    r"C:\snapsmack\gyss\GET YOUR SHIT SORTED.exe"]),
    ("COLD SNAP",           "offline poster",      [r"C:\snapsmack\coldsnap\coldsnap.exe",
                                                    r"C:\COLDSNAP\coldsnap.exe"]),
    ("SMACK UP YOUR BACKUP", "backup",             [r"C:\snapsmack\suyb\suyb.exe",
                                                    r"C:\snapsmack\suyb\smackupyourbackup*.exe",
                                                    r"C:\SmackUpYourBackup\smackupyourbackup*.exe",
                                                    r"C:\SmackUpYourBackup\suyb.exe",
                                                    r"C:\SUYB\smackupyourbackup*.exe",
                                                    r"C:\SUYB\suyb*.exe"]),
    ("OH SNAP",             "skin designer",       [r"C:\OHSNAP\OH SNAP.exe",
                                                    r"C:\OhSnap\oh-snap.exe",
                                                    r"C:\snapsmack\ohsnap\*.exe"]),
]


def _find_exe(paths):
    """First existing exe among the candidates. A candidate may be a glob (for a
    versioned exe name); when it matches, the most-recently-modified file wins."""
    for p in paths:
        if any(ch in p for ch in "*?["):
            matches = [m for m in glob.glob(p) if os.path.isfile(m)]
            if matches:
                return max(matches, key=os.path.getmtime)
        elif os.path.isfile(p):
            return p
    return None


def _launch(path):
    try:
        subprocess.Popen([path], cwd=os.path.dirname(path))
        return True, ""
    except Exception as e:
        return False, str(e)


class Hub(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title(f"THE HUB — SnapSmack   (build {BUILD_VERSION})")
        self.configure(bg=BG)
        self.geometry("980x720")
        self.minsize(860, 640)

        self._creds_vars = {}
        self._build_header()
        if not _SHARED_OK:
            tk.Label(self, text=f"Shared modules unavailable: {_SHARED_ERR}",
                     bg=BG, fg="#ff5555", font=("Segoe UI", 11)).pack(pady=40)
            return
        body = tk.Frame(self, bg=BG)
        body.pack(fill="both", expand=True, padx=18, pady=(0, 14))
        self._build_launcher(body)
        self._build_setup(body)
        self._build_profiles(body)
        self._load_creds()
        self._refresh_profiles()

    # ── header ──────────────────────────────────────────────────────────────
    def _build_header(self):
        h = tk.Frame(self, bg=BG)
        h.pack(fill="x", padx=18, pady=(16, 12))
        tk.Label(h, text="THE HUB", bg=BG, fg=ACCENT,
                 font=("Segoe UI Black", 22, "bold")).pack(side="left")
        tk.Label(h, text="  one door · set the fleet up once",
                 bg=BG, fg=DIM, font=("Segoe UI", 11)).pack(side="left", pady=(10, 0))

    def _card(self, parent, title):
        outer = tk.Frame(parent, bg=BORDER)
        outer.pack(fill="x", pady=(0, 12))
        inner = tk.Frame(outer, bg=CARD)
        inner.pack(fill="both", expand=True, padx=1, pady=1)
        tk.Label(inner, text=title, bg=CARD, fg=ACCENT,
                 font=("Segoe UI", 10, "bold")).pack(anchor="w", padx=14, pady=(10, 6))
        return inner

    # ── launcher ────────────────────────────────────────────────────────────
    def _build_launcher(self, parent):
        card = self._card(parent, "LAUNCH")
        grid = tk.Frame(card, bg=CARD)
        grid.pack(fill="x", padx=12, pady=(0, 12))
        for i, (name, sub, paths) in enumerate(ROSTER):
            exe = _find_exe(paths)
            cell = tk.Frame(grid, bg=CARD)
            cell.grid(row=i // 3, column=i % 3, sticky="nsew", padx=6, pady=6)
            grid.grid_columnconfigure(i % 3, weight=1)
            state = "normal" if exe else "disabled"
            btn = tk.Button(cell, text=name, state=state,
                            bg=FIELD if exe else "#181818",
                            fg=INK if exe else DIM, activebackground=ACCENT,
                            activeforeground=BG, relief="flat", bd=0,
                            font=("Segoe UI", 10, "bold"), height=2,
                            cursor="hand2" if exe else "arrow",
                            command=(lambda p=exe, n=name: self._on_launch(p, n)))
            btn.pack(fill="x")
            tk.Label(cell, text=(sub if exe else "not installed"),
                     bg=CARD, fg=DIM, font=("Segoe UI", 8)).pack(anchor="w", pady=(2, 0))

    def _on_launch(self, path, name):
        ok, err = _launch(path)
        if not ok:
            messagebox.showerror("Launch failed", f"{name}\n\n{err}", parent=self)

    # ── shared setup ─────────────────────────────────────────────────────────
    def _field(self, parent, label, key, show=None, browse=False):
        row = tk.Frame(parent, bg=CARD)
        row.pack(fill="x", padx=14, pady=(0, 8))
        tk.Label(row, text=label, bg=CARD, fg=DIM,
                 font=("Segoe UI", 8)).pack(anchor="w")
        line = tk.Frame(row, bg=CARD)
        line.pack(fill="x")
        var = tk.StringVar()
        self._creds_vars[key] = var
        ent = tk.Entry(line, textvariable=var, show=show, bg=FIELD, fg=INK,
                       insertbackground=INK, relief="flat", font=("Consolas", 10))
        ent.pack(side="left", fill="x", expand=True, ipady=5)
        if browse:
            tk.Button(line, text="…", bg=FIELD, fg=INK, relief="flat",
                      command=lambda v=var: self._browse(v)).pack(side="left", padx=(6, 0))
        return var

    def _browse(self, var):
        p = filedialog.askopenfilename(parent=self, title="Choose credentials JSON",
                                       filetypes=[("JSON", "*.json"), ("All", "*.*")])
        if p:
            var.set(p)

    def _build_setup(self, parent):
        card = self._card(parent, "HUB SETUP  ·  set once, every tool has it")
        self._field(card, "HUB SITE URL",   "hub_url")
        self._field(card, "HUB API KEY",    "hub_key", show="•")
        self._field(card, "GEMINI API KEY", "gemini_api_key", show="•")
        self._field(card, "GOOGLE DRIVE CREDENTIALS (json)", "google_credentials", browse=True)
        self._field(card, "DRIVE FOLDER ID", "drive_folder_id")
        bar = tk.Frame(card, bg=CARD)
        bar.pack(fill="x", padx=14, pady=(4, 12))
        tk.Button(bar, text="SAVE SHARED CREDENTIALS", bg=FIELD, fg=INK,
                  activebackground=ACCENT, activeforeground=BG, relief="flat",
                  font=("Segoe UI", 9, "bold"), cursor="hand2",
                  command=self._on_save_creds).pack(side="left", ipadx=8, ipady=4)
        tk.Button(bar, text="⟳  DISCOVER FLEET", bg=ACCENT, fg=BG,
                  activebackground="#2ecc10", relief="flat",
                  font=("Segoe UI", 9, "bold"), cursor="hand2",
                  command=self._on_discover).pack(side="left", padx=(10, 0), ipadx=8, ipady=4)
        self._setup_status = tk.Label(bar, text="", bg=CARD, fg=DIM,
                                      font=("Segoe UI", 9))
        self._setup_status.pack(side="left", padx=12)

    def _load_creds(self):
        for key, var in self._creds_vars.items():
            if key == "hub_url":
                continue  # hub_url is not a secret; kept only for discovery input
            try:
                var.set(snap_creds.get(key, ""))
            except Exception:
                pass

    def _on_save_creds(self):
        try:
            for key, var in self._creds_vars.items():
                if key == "hub_url":
                    continue
                val = var.get().strip()
                if val:
                    snap_creds.set(key, val)
            self._setup_status.configure(text="✓ saved to shared vault", fg=ACCENT)
        except Exception as e:
            self._setup_status.configure(text=f"save failed: {e}", fg="#ff5555")

    def _on_discover(self):
        hub_url = self._creds_vars["hub_url"].get().strip()
        hub_key = self._creds_vars["hub_key"].get().strip()
        if not hub_url:
            messagebox.showwarning("Hub URL required",
                                   "Enter your hub site URL first.", parent=self)
            return
        self._setup_status.configure(text="discovering…", fg=DIM)
        self.update_idletasks()
        try:
            summary = snap_discovery.discover_and_save(hub_url, api_key=hub_key)
        except Exception as e:
            self._setup_status.configure(text="", fg=DIM)
            messagebox.showerror("Discovery failed", str(e), parent=self)
            return
        self._load_creds()
        self._refresh_profiles()
        n = summary.get("count", 0)
        self._setup_status.configure(text=f"✓ {n} site(s) into the shared store", fg=ACCENT)

    # ── shared profiles list ─────────────────────────────────────────────────
    def _build_profiles(self, parent):
        card = self._card(parent, "SHARED PROFILES  ·  every tool sees these")
        wrap = tk.Frame(card, bg=CARD)
        wrap.pack(fill="both", expand=True, padx=14, pady=(0, 12))
        self._prof_list = tk.Listbox(wrap, bg=FIELD, fg=INK, relief="flat",
                                     font=("Consolas", 10), height=7,
                                     selectbackground=ACCENT, selectforeground=BG,
                                     highlightthickness=0, bd=0)
        self._prof_list.pack(fill="both", expand=True)

    def _refresh_profiles(self):
        self._prof_list.delete(0, "end")
        try:
            profs = snap_profiles.list_profiles()
        except Exception:
            profs = []
        if not profs:
            self._prof_list.insert("end", "  (no shared profiles yet — Discover Fleet, "
                                          "or save one in any tool)")
            return
        for p in profs:
            name = p.get("name", "")
            url = p.get("site_url", "")
            self._prof_list.insert("end", f"  {name:<28}  {url}")


if __name__ == "__main__":
    Hub().mainloop()
# ===== SNAPSMACK EOF =====
