"""
SNAP HQ — SnapSmack unified desktop front end & launcher.

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
import hashlib
import json
import os
import subprocess
import sys
import tkinter as tk
from datetime import datetime
from tkinter import filedialog, messagebox
from PIL import Image, ImageTk

BUILD_VERSION = "0.7.41"

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
    import snap_connections
    import snap_profiles
    import snap_discovery
    import snap_prompts
    import snap_prompt_sync
    import snap_home
    import snap_site_settings
    import snap_settings_sync
    import snap_device_auth
    import snap_stepup
    import snap_session_gate
    _SHARED_OK = True
except Exception as _e:                      # pragma: no cover
    _SHARED_OK = False
    _SHARED_ERR = str(_e)

# Start the run log + crash capture as early as possible so anything that goes
# wrong during HQ startup is recorded. Filed under HQ's own namespace — it used to
# log as "snap_slapper", which buried HQ's interface/startup failures inside SNAP
# SLAPPER's run logs and made them hard to attribute. Tools HQ launches keep their
# own logs.
try:
    import snap_log
    snap_log.setup("snap_hq")
except Exception:                            # pragma: no cover
    pass

# ── palette (matches the tool family: onyx + green) ─────────────────────────
BG      = "#0a0a0a"
CARD    = "#141414"
INK     = "#e6e6e6"
DIM     = "#8a8a8a"
ACCENT  = "#39ff14"
FIELD   = "#1c1c1c"
BORDER  = "#2a2a2a"


def _launcher_column_count(width):
    """Use every column that fits without crushing a launch tile."""
    if width >= 900:
        return 3
    if width >= 600:
        return 2
    return 1

# ── the tools SNAP HQ fronts, and where they install ─────────────────────
# The SnapSmack shared root. This is ALSO the GYSS file-jail root (SECAUDIT 039): a
# compromised GYSS webview is permitted to write ANYWHERE under it. So it must never
# be a source of WILDCARD-matched launch targets — see _find_exe and SECAUDIT 044.
def _shared_root():
    return os.path.abspath((os.environ.get("SNAPSMACK_HOME") or "").strip() or r"C:\snapsmack")


# Candidate exe locations per tool. THUMB-DRIVE PORTABLE (2026-08-21): every path is
# now built from _shared_root() (honours SNAPSMACK_HOME), so the whole kit runs from
# one folder on any drive/letter — Sean's "all utils in one place" requirement. Each
# path is EXACT and inside the shared root; NO wildcards anywhere, which keeps the
# SECAUDIT 044 rule satisfied (a wildcard inside the GYSS-writable root would let a
# compromised webview plant an arbitrary <name>.exe for the launcher to run). Tools
# with version-named exes (SUYB) are placed under a fixed name (suyb.exe) so they can
# be listed exactly rather than globbed. _find_exe's wildcard-in-root refusal is kept
# as defence in depth even though nothing globs now.
_R = _shared_root()
ROSTER = [
    ("SNAP SLAPPER",        "photo manager and editor", [os.path.join(_R, "snap_slapper", "SNAP SLAPPER.exe")]),
    ("SMACK YOUR BATCH UP", "batch poster",         [os.path.join(_R, "sybu", "sybu.exe")]),
    ("GET YOUR SHIT SORTED", "offline sorter",      [os.path.join(_R, "gyss", "GET YOUR SHIT SORTED.exe")]),
    ("COLD SNAP",           "offline poster",       [os.path.join(_R, "coldsnap", "coldsnap.exe")]),
    ("SMACK UP YOUR BACKUP", "backup",              [os.path.join(_R, "suyb", "suyb.exe")]),
    ("OH SNAP",             "skin designer",        [os.path.join(_R, "ohsnap", "oh-snap.exe")]),
    ("SMACK YOUR MOUTH",    "comments: mod + reply", [os.path.join(_R, "smack-your-mouth", "smackmouth.exe")]),
    ("SHOTS FIRED",         "schedule board",       [os.path.join(_R, "shots-fired", "shots-fired.exe")]),
    ("CRONOMETER",          "fleet cron health",    [os.path.join(_R, "cronometer", "cronometer.exe")]),
]

TOOL_ICON_FILES = {
    "SNAP HQ": "snap-hq.ico",
    "SNAP SLAPPER": "snap-slapper-simple.ico",
    "SMACK YOUR BATCH UP": "sybu-taskbar.ico",
    "GET YOUR SHIT SORTED": "gyss-simple.ico",
    "COLD SNAP": "coldsnap-simple.ico",
    "SMACK UP YOUR BACKUP": "suyb-simple.ico",
    "OH SNAP": "ohsnap-simple.ico",
    "SMACK YOUR MOUTH": "smackmouth-simple.ico",
    "SHOTS FIRED": "shotsfired-simple.ico",
    "CRONOMETER": "cronometer-simple.ico",
}

TOOL_IMAGE_FILES = {
    name: filename.replace(".ico", ".png") for name, filename in TOOL_ICON_FILES.items()
}


def _tool_icon(name, exe):
    """Permanent icon installed beside SNAP HQ; executable icon is fallback."""
    filename = TOOL_ICON_FILES.get(name, "")
    icon = os.path.join(_shared_root(), "hub", "icons", filename) if filename else ""
    return icon if icon and os.path.isfile(icon) else exe


def _tool_image(name):
    filename = TOOL_IMAGE_FILES.get(name, "")
    path = os.path.join(_shared_root(), "hub", "icons", filename) if filename else ""
    if path and os.path.isfile(path):
        return path
    bundled = os.path.join(getattr(sys, "_MEIPASS", ""), "icons", filename) if filename else ""
    return bundled if bundled and os.path.isfile(bundled) else ""


def _find_exe(paths):
    """First existing exe among the candidates. A candidate may be a glob (for a
    versioned exe name); when it matches, the most-recently-modified file wins.

    SECURITY (SECAUDIT 044, Finding 1): a WILDCARD candidate is REFUSED when it
    resolves inside the shared root, because that tree is the GYSS write-jail — a
    compromised webview (or a weak-ACL local user) could plant an arbitrary
    `<name>.exe` there and this launcher would execute it. Wildcards are therefore
    honoured only for out-of-jail legacy install dirs; inside the jail only the
    exact roster paths above are eligible."""
    root = _shared_root()
    for p in paths:
        if any(ch in p for ch in "*?["):
            base = os.path.dirname(os.path.abspath(p))
            if base == root or base.startswith(root + os.sep):
                continue  # never glob for an exe inside the GYSS-writable shared root
            matches = [m for m in glob.glob(p) if os.path.isfile(m)]
            if matches:
                return max(matches, key=os.path.getmtime)
        elif os.path.isfile(p):
            return p
    return None


# ── Roster-exe hash pinning (SECAUDIT 054) ───────────────────────────────────
# The Hub launches exes that live inside the shared root — the same tree the
# GYSS fs-jail can write into. Pin each exe's sha256 on first launch and verify
# it on every launch after; a changed exe is refused until the operator says,
# in one click, "yes, I rebuilt this on purpose." The pin ledger lives OUTSIDE
# the shared root (%APPDATA%) so nothing inside the jail can bless a payload.

def _exe_ledger_path():
    base = os.environ.get("APPDATA") or os.path.expanduser("~")
    folder = os.path.join(base, "snapsmack-hub")
    os.makedirs(folder, exist_ok=True)
    return os.path.join(folder, "exe-pins.json")


def _sha256_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _exe_pin_verify(path):
    """(status, pinned_hash, current_hash). status: 'ok' | 'new' | 'changed'."""
    current = _sha256_file(path)
    ledger_file = _exe_ledger_path()
    try:
        with open(ledger_file, encoding="utf-8") as fh:
            ledger = json.load(fh)
    except Exception:  # noqa: BLE001 — absent/corrupt ledger = start fresh
        ledger = {}
    key = os.path.abspath(path).lower()
    pinned = ledger.get(key)
    if pinned == current:
        return "ok", pinned, current
    return ("new" if pinned is None else "changed"), pinned, current


def _exe_pin_store(path, digest):
    ledger_file = _exe_ledger_path()
    try:
        with open(ledger_file, encoding="utf-8") as fh:
            ledger = json.load(fh)
    except Exception:  # noqa: BLE001
        ledger = {}
    ledger[os.path.abspath(path).lower()] = digest
    with open(ledger_file, "w", encoding="utf-8") as fh:
        json.dump(ledger, fh, indent=1, sort_keys=True)


def _launch(path, parent=None):
    try:
        status, _pinned, current = _exe_pin_verify(path)
        if status == "changed":
            # A different exe than the one this Hub has been launching. That is
            # either the operator's own rebuild — or exactly the swap the pin
            # exists to catch. One plain question, one click.
            proceed = messagebox.askyesno(
                "This program changed",
                f"{os.path.basename(path)} is not the same file the Hub "
                f"launched last time.\n\nIf you rebuilt or updated this tool "
                f"on purpose, choose Yes to trust the new build and launch "
                f"it.\n\nIf you did NOT change this tool, choose No and do "
                f"not run it until you know why it changed.",
                parent=parent)
            if not proceed:
                return False, "Launch cancelled — the exe changed and was not trusted."
        if status in ("new", "changed"):
            _exe_pin_store(path, current)
        subprocess.Popen([path], cwd=os.path.dirname(path))
        return True, ""
    except Exception as e:
        return False, str(e)


def _shortcut_path(name, destination):
    """Create a real Windows .lnk using the executable's own embedded icon."""
    if destination == "desktop":
        folder = os.path.join(os.environ.get("USERPROFILE", ""), "Desktop")
    else:
        folder = os.path.join(os.environ.get("APPDATA", ""),
                              "Microsoft", "Windows", "Start Menu", "Programs", "SnapSmack")
    return os.path.join(folder, f"{name}.lnk")


def _create_shortcut(name, exe, destination):
    """Create a shortcut without interpolating paths into PowerShell source."""
    shortcut = _shortcut_path(name, destination)
    env = os.environ.copy()
    env["SNAP_SHORTCUT_EXE"] = os.path.abspath(exe)
    env["SNAP_SHORTCUT_LNK"] = shortcut
    env["SNAP_SHORTCUT_ICON"] = _tool_icon(name, exe)
    script = (
        "$ErrorActionPreference='Stop'; "
        "$target=$env:SNAP_SHORTCUT_EXE; $link=$env:SNAP_SHORTCUT_LNK; $icon=$env:SNAP_SHORTCUT_ICON; "
        "New-Item -ItemType Directory -Force -Path ([IO.Path]::GetDirectoryName($link)) | Out-Null; "
        "$w=New-Object -ComObject WScript.Shell; $s=$w.CreateShortcut($link); "
        "$s.TargetPath=$target; $s.WorkingDirectory=[IO.Path]::GetDirectoryName($target); "
        "$s.IconLocation=\"$icon,0\"; $s.Save()"
    )
    done = subprocess.run(["powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script],
                          env=env, capture_output=True, text=True,
                          creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
    if done.returncode:
        return None, (done.stderr or done.stdout or "Windows could not create the shortcut").strip()
    return shortcut, ""


def _try_pin_to_taskbar(shortcut):
    """Ask Windows for its Pin verb. Windows 11 may require user confirmation."""
    env = os.environ.copy()
    env["SNAP_SHORTCUT_LNK"] = shortcut
    script = (
        "$p=$env:SNAP_SHORTCUT_LNK; $sh=New-Object -ComObject Shell.Application; "
        "$f=$sh.Namespace([IO.Path]::GetDirectoryName($p)); "
        "$i=$f.ParseName([IO.Path]::GetFileName($p)); "
        "$v=$i.Verbs() | Where-Object { ($_.Name -replace '&','') -match '^Pin to taskbar$' } | Select-Object -First 1; "
        "if($v){$v.DoIt(); exit 0}else{exit 3}"
    )
    done = subprocess.run(["powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script],
                          env=env, capture_output=True, text=True,
                          creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
    return done.returncode == 0


class Hub(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title(f"SNAP HQ — local desktop headquarters   (build {BUILD_VERSION})")
        self.configure(bg=BG)
        self.geometry("980x720")
        self.minsize(700, 480)

        self._ui_images = {}
        hq_image = self._load_ui_icon("SNAP HQ", 48)
        if hq_image:
            self.iconphoto(True, hq_image)

        self._creds_vars = {}
        self._settings_window = None
        self._gyss_keys = {}          # site_url -> minted gyss key (cached per run)
        self._build_header()
        if not _SHARED_OK:
            tk.Label(self, text=f"Shared modules unavailable: {_SHARED_ERR}",
                     bg=BG, fg="#ff5555", font=("Segoe UI", 11)).pack(pady=40)
            return
        # Keep the launcher reachable in a genuinely small window, but do not
        # reserve a scrollbar when all launch tiles already fit.
        scroll_shell = tk.Frame(self, bg=BG)
        scroll_shell.pack(fill="both", expand=True, padx=18, pady=(0, 14))
        self._body_canvas = tk.Canvas(
            scroll_shell, bg=BG, highlightthickness=0, bd=0)
        body_scroll = tk.Scrollbar(
            scroll_shell, orient="vertical", command=self._body_canvas.yview,
            bg=FIELD, troughcolor=BG, activebackground=ACCENT,
            highlightthickness=0, bd=0)
        self._body_canvas.configure(yscrollcommand=body_scroll.set)
        scroll_shell.grid_rowconfigure(0, weight=1)
        scroll_shell.grid_columnconfigure(0, weight=1)
        self._body_canvas.grid(row=0, column=0, sticky="nsew")

        body = tk.Frame(self._body_canvas, bg=BG)
        self._body_window = self._body_canvas.create_window(
            (0, 0), window=body, anchor="nw")
        self._install_auto_scroll(
            self._body_canvas, body_scroll, body, self._body_window)
        self._body_canvas.bind_all(
            "<MouseWheel>",
            lambda e: self._body_canvas.yview_scroll(int(-e.delta / 120), "units"))
        self._gyss_keys = {}          # site_url -> minted gyss key (cached per run)
        self._build_launcher(body)
        # A dashboard benefits from the available desktop.  Defer this until
        # Tk has created the native window so Windows honours the request.
        self.after_idle(self._open_maximized)

    def _open_maximized(self):
        try:
            self.state("zoomed")
        except tk.TclError:                 # non-Windows Tk fallback
            try:
                self.attributes("-zoomed", True)
            except tk.TclError:
                pass

    def _update_body_scroll_region(self, _event=None):
        bounds = self._body_canvas.bbox("all")
        if bounds:
            # Preserve the 18px left/right breathing room from the old body.
            self._body_canvas.configure(
                scrollregion=(0, 0, bounds[2] + 18, bounds[3] + 14))

    def _resize_scroll_body(self, event):
        self._body_canvas.itemconfigure(
            self._body_window, width=max(1, event.width - 36))

    def _scroll_body(self, event):
        if event.delta:
            self._body_canvas.yview_scroll(
                -1 if event.delta > 0 else 1, "units")

    # ── header ──────────────────────────────────────────────────────────────
    def _build_header(self):
        h = tk.Frame(self, bg=BG)
        h.pack(fill="x", padx=18, pady=(16, 12))
        hq_image = self._load_ui_icon("SNAP HQ", 42)
        tk.Label(h, text="SNAP HQ", image=hq_image, compound="left", padx=6,
                 bg=BG, fg=ACCENT,
                 font=("Segoe UI Black", 22, "bold")).pack(side="left")
        tk.Label(h, text="  local desktop headquarters",
                 bg=BG, fg=DIM, font=("Segoe UI", 11)).pack(side="left", pady=(10, 0))
        settings_b = tk.Button(h, text="⚙  SETTINGS", bg=ACCENT, fg=BG,
                               activebackground="#2ecc10", activeforeground=BG,
                               relief="flat", bd=0, font=("Segoe UI", 9, "bold"),
                               cursor="hand2", command=self._open_settings)
        settings_b.pack(side="right", padx=(10, 0), pady=(6, 0), ipadx=9, ipady=4)
        self._hoverize(settings_b, hover_bg="#2ecc10")
        if getattr(sys, "frozen", False):
            for label, where in (("DESKTOP", "desktop"), ("TASKBAR", "taskbar")):
                b = tk.Button(h, text=label, bg=FIELD, fg=DIM, relief="flat", bd=0,
                              activebackground=ACCENT, activeforeground=BG,
                              font=("Segoe UI", 7, "bold"), cursor="hand2",
                              command=lambda w=where: self._on_shortcut(sys.executable, "SNAP HQ", w))
                b.pack(side="right", padx=(6, 0), pady=(8, 0))
                self._hoverize(b)

    def _open_settings(self):
        """Keep setup out of the launcher; one obvious gear opens it on demand."""
        if self._settings_window is not None and self._settings_window.winfo_exists():
            self._settings_window.deiconify()
            self._settings_window.lift()
            self._settings_window.focus_force()
            return

        win = tk.Toplevel(self)
        self._settings_window = win
        win.title(f"SNAP HQ Settings   (build {BUILD_VERSION})")
        win.configure(bg=BG)
        win.geometry("1100x850")
        win.minsize(820, 620)
        win.transient(self)

        heading = tk.Frame(win, bg=BG)
        heading.pack(fill="x", padx=18, pady=(16, 10))
        tk.Label(heading, text="SETTINGS", bg=BG, fg=ACCENT,
                 font=("Segoe UI Black", 20, "bold")).pack(side="left")
        tk.Label(heading, text="  Set it here once. Every desktop tool uses it.",
                 bg=BG, fg=DIM, font=("Segoe UI", 10)).pack(side="left", pady=(8, 0))
        tk.Button(heading, text="CLOSE", command=win.destroy, bg=FIELD, fg=INK,
                  activebackground=ACCENT, activeforeground=BG, relief="flat",
                  font=("Segoe UI", 9, "bold")).pack(side="right", ipadx=8, ipady=3)

        shell = tk.Frame(win, bg=BG)
        shell.pack(fill="both", expand=True, padx=18, pady=(0, 14))
        canvas = tk.Canvas(shell, bg=BG, highlightthickness=0)
        scroll = tk.Scrollbar(shell, orient="vertical", command=canvas.yview,
                              bg=FIELD, troughcolor=BG, activebackground=ACCENT,
                              highlightthickness=0, bd=0)
        canvas.configure(yscrollcommand=scroll.set)
        shell.grid_rowconfigure(0, weight=1)
        shell.grid_columnconfigure(0, weight=1)
        canvas.grid(row=0, column=0, sticky="nsew")
        body = tk.Frame(canvas, bg=BG)
        window = canvas.create_window((0, 0), window=body, anchor="nw")
        self._install_auto_scroll(canvas, scroll, body, window)
        canvas.bind_all("<MouseWheel>", lambda e: canvas.yview_scroll(int(-e.delta / 120), "units"))

        self._build_setup(body)
        self._build_profiles(body)
        self._build_prompts(body)
        self._load_creds()
        self._refresh_profiles()
        self._refresh_prompt_sites()
        win.protocol("WM_DELETE_WINDOW", win.destroy)

    def _open_authorization(self):
        """Open Settings directly at the device-authorization card."""
        self._open_settings()
        if self._settings_window is not None and self._settings_window.winfo_exists():
            self._settings_window.deiconify()
            self._settings_window.lift()
            self._device_site_entry.focus_set()

    def _install_auto_scroll(self, canvas, scroll, body, window):
        """Show the scrollbar only when the page is taller than its viewport."""
        def refresh(_event=None):
            if not canvas.winfo_exists():
                return
            canvas.itemconfigure(window, width=canvas.winfo_width())
            bbox = canvas.bbox("all")
            canvas.configure(scrollregion=bbox or (0, 0, 0, 0))
            content_height = (bbox[3] - bbox[1]) if bbox else 0
            needed = content_height > canvas.winfo_height() + 1
            if needed and not scroll.winfo_manager():
                scroll.grid(row=0, column=1, sticky="ns")
            elif not needed and scroll.winfo_manager():
                scroll.grid_remove()
                canvas.yview_moveto(0)

        body.bind("<Configure>", refresh)
        canvas.bind("<Configure>", refresh)

    def _load_ui_icon(self, name, size=42):
        key = (name, size)
        if key in self._ui_images:
            return self._ui_images[key]
        path = _tool_image(name)
        if not path:
            return None
        try:
            image = Image.open(path).convert("RGBA")
            image.thumbnail((size, size), Image.Resampling.LANCZOS)
            photo = ImageTk.PhotoImage(image)
            self._ui_images[key] = photo
            return photo
        except Exception:
            return None

    def _card(self, parent, title):
        outer = tk.Frame(parent, bg=BORDER)
        outer.pack(fill="x", pady=(0, 12))
        inner = tk.Frame(outer, bg=CARD)
        inner.pack(fill="both", expand=True, padx=1, pady=1)
        tk.Label(inner, text=title, bg=CARD, fg=ACCENT,
                 font=("Segoe UI", 10, "bold")).pack(anchor="w", padx=14, pady=(10, 6))
        return inner

    def _hoverize(self, btn, hover_bg=ACCENT, hover_fg=BG):
        """Green-on-hover for any button. Tk's activebackground only shows while a
        button is pressed on Windows, not on mouse-over, so bind Enter/Leave
        explicitly and restore the button's own colours on leave. A disabled
        button never lights up. Returns the button so callers can chain."""
        base_bg, base_fg = btn.cget("bg"), btn.cget("fg")
        def on_enter(_e):
            if str(btn.cget("state")) != "disabled":
                btn.configure(bg=hover_bg, fg=hover_fg)
        def on_leave(_e):
            btn.configure(bg=base_bg, fg=base_fg)
        btn.bind("<Enter>", on_enter)
        btn.bind("<Leave>", on_leave)
        return btn

    # ── launcher ────────────────────────────────────────────────────────────
    def _build_launcher(self, parent):
        card = self._card(parent, "LAUNCH")
        grid = tk.Frame(card, bg=CARD)
        grid.pack(fill="x", padx=12, pady=(0, 12))
        cells = []
        for i, (name, sub, paths) in enumerate(ROSTER):
            exe = _find_exe(paths)
            cell = tk.Frame(grid, bg=CARD)
            cells.append(cell)
            tool_image = self._load_ui_icon(name, 42)
            button_bg = FIELD if exe else "#181818"
            button_fg = INK if exe else DIM
            launch = tk.Frame(cell, bg=button_bg, height=56, bd=0,
                              cursor="hand2" if exe else "arrow", takefocus=bool(exe))
            launch.pack(fill="x")
            launch.pack_propagate(False)

            # A fixed-width holder is centred in the full button. Within it,
            # icons occupy one column and names occupy one left-aligned column.
            content = tk.Frame(launch, bg=button_bg, width=300, height=52)
            content.place(relx=.5, rely=.5, anchor="center")
            content.grid_propagate(False)
            content.grid_rowconfigure(0, weight=1)
            content.grid_columnconfigure(0, minsize=60)
            icon = tk.Label(content, image=tool_image, bg=button_bg, bd=0)
            icon.grid(row=0, column=0, sticky="w")
            title = tk.Label(content, text=name, bg=button_bg, fg=button_fg,
                             anchor="w", justify="left", bd=0,
                             font=("Segoe UI", 10, "bold"))
            title.grid(row=0, column=1, sticky="w")

            launch_parts = (launch, content, icon, title)
            def paint(bg, fg, parts=launch_parts):
                for widget in parts:
                    widget.configure(bg=bg)
                parts[-1].configure(fg=fg)
            def enter(_event, enabled=bool(exe), painter=paint):
                if enabled:
                    painter(ACCENT, BG)
            def leave(_event, painter=paint, bg=button_bg, fg=button_fg):
                painter(bg, fg)
            def activate(_event=None, path=exe, tool_name=name):
                if path:
                    self._on_launch(path, tool_name)
            for widget in launch_parts:
                widget.bind("<Enter>", enter)
                widget.bind("<Leave>", leave)
                widget.bind("<Button-1>", activate)
            launch.bind("<Return>", activate)
            launch.bind("<space>", activate)
            foot = tk.Frame(cell, bg=CARD)
            foot.pack(fill="x", pady=(2, 0))
            tk.Label(foot, text=(sub if exe else "not installed"),
                     bg=CARD, fg=DIM, font=("Segoe UI", 8)).pack(side="left", anchor="w")
            if exe:
                for label, where in (("DESKTOP", "desktop"), ("TASKBAR", "taskbar")):
                    sb = tk.Button(foot, text=label, bg=CARD, fg=DIM, relief="flat", bd=0,
                                   activebackground=ACCENT, activeforeground=BG,
                                   font=("Segoe UI", 7, "bold"), cursor="hand2",
                                   command=lambda p=exe, n=name, w=where: self._on_shortcut(p, n, w))
                    sb.pack(side="right", padx=(5, 0))
                    self._hoverize(sb)

        layout = {"columns": 0}
        def _reflow(event=None):
            width = event.width if event is not None else grid.winfo_width()
            columns = _launcher_column_count(width)
            if layout["columns"] == columns:
                return
            layout["columns"] = columns
            for column in range(3):
                grid.grid_columnconfigure(column, weight=1 if column < columns else 0)
            for index, cell in enumerate(cells):
                cell.grid_forget()
                cell.grid(row=index // columns, column=index % columns,
                          sticky="nsew", padx=6, pady=6)

        grid.bind("<Configure>", _reflow)
        _reflow()

    def _on_launch(self, path, name):
        ok, err = _launch(path, parent=self)
        if not ok:
            messagebox.showerror("Launch failed", f"{name}\n\n{err}", parent=self)

    def _on_shortcut(self, path, name, destination):
        shortcut, err = _create_shortcut(name, path,
                                         "desktop" if destination == "desktop" else "start")
        if err:
            messagebox.showerror("Shortcut failed", f"{name}\n\n{err}", parent=self)
            return
        if destination == "desktop":
            messagebox.showinfo("Shortcut added", f"{name} is now on your desktop.", parent=self)
            return
        if _try_pin_to_taskbar(shortcut):
            messagebox.showinfo("Added to taskbar", f"{name} was added to the taskbar.", parent=self)
            return
        # Current Windows builds deliberately hide the programmatic Pin verb.
        # Launch the requested app so its real icon is already on the taskbar;
        # pinning that running icon is the shortest supported Windows workflow.
        _launch(path)
        messagebox.showinfo(
            "One Windows click remains",
            f"{name} is open and installed in your Start menu.\n\n"
            "Right-click its icon on the taskbar and choose Pin to taskbar.",
            parent=self)

    # ── shared setup ─────────────────────────────────────────────────────────
    def _field(self, parent, label, key, show=None, browse=False, test=None, reveal=False):
        row = tk.Frame(parent, bg=CARD)
        row.pack(fill="x", padx=14, pady=(0, 8))
        head = tk.Frame(row, bg=CARD)
        head.pack(fill="x")
        tk.Label(head, text=label, bg=CARD, fg=DIM,
                 font=("Segoe UI", 8)).pack(side="left", anchor="w")
        status = None
        if test is not None:
            status = tk.Label(head, text="", bg=CARD, fg=DIM, font=("Segoe UI", 8))
            status.pack(side="right")
        line = tk.Frame(row, bg=CARD)
        line.pack(fill="x")
        var = tk.StringVar()
        self._creds_vars[key] = var
        ent = tk.Entry(line, textvariable=var, show=show, bg=FIELD, fg=INK,
                       insertbackground=INK, relief="flat", font=("Consolas", 10))
        ent.pack(side="left", fill="x", expand=True, ipady=5)
        if reveal and show:
            # Masked by default; this button flips the value in and out of view.
            def _toggle_reveal(e=ent, mask=show):
                if e.cget("show"):
                    e.config(show="")
                    rb.config(text="Hide")
                else:
                    e.config(show=mask)
                    rb.config(text="Show")
            rb = tk.Button(line, text="Show", bg=FIELD, fg=INK, relief="flat",
                           activebackground=ACCENT, activeforeground=BG,
                           font=("Segoe UI", 8, "bold"), cursor="hand2",
                           command=_toggle_reveal)
            rb.pack(side="left", padx=(6, 0), ipadx=8, ipady=3)
            self._hoverize(rb)
        if browse:
            bb = tk.Button(line, text="…", bg=FIELD, fg=INK, relief="flat",
                           command=lambda v=var: self._browse(v))
            bb.pack(side="left", padx=(6, 0))
            self._hoverize(bb)
        if test is not None:
            # Big, clearly-labelled target next to the field it checks.
            tb = tk.Button(line, text="Test", bg=FIELD, fg=INK, relief="flat",
                           activebackground=ACCENT, activeforeground=BG,
                           font=("Segoe UI", 8, "bold"), cursor="hand2",
                           command=lambda s=status: test(s))
            tb.pack(side="left", padx=(6, 0), ipadx=8, ipady=3)
            self._hoverize(tb)
        return var

    def _set_status(self, label, ok, msg):
        if label is not None:
            label.configure(text=("✓ " if ok else "✗ ") + msg,
                            fg=ACCENT if ok else "#ff5555")

    def _testing(self, label):
        if label is not None:
            label.configure(text="testing…", fg=DIM)
            self.update_idletasks()

    def _test_hub(self, status):
        url = self._creds_vars["hub_url"].get().strip()
        key = self._creds_vars["hub_key"].get().strip()
        if not url:
            self._set_status(status, False, "enter the hub URL first"); return
        self._testing(status)
        try:
            _hub_info, spokes = snap_discovery.discover(url, api_key=key)
            n = len(spokes or [])
            self._set_status(status, True, f"connected — {n} site(s)")
        except Exception as e:
            self._set_status(status, False, str(e)[:70])

    def _test_gemini(self, status):
        key = self._creds_vars["gemini_api_key"].get().strip()
        if not key:
            self._set_status(status, False, "enter a key first"); return
        self._testing(status)
        try:
            import requests
            r = requests.get("https://generativelanguage.googleapis.com/v1beta/models",
                             params={"key": key}, timeout=15)
            self._set_status(status, r.status_code == 200,
                             "key valid" if r.status_code == 200
                             else f"rejected (HTTP {r.status_code})")
        except Exception as e:
            self._set_status(status, False, str(e)[:70])

    def _test_ai_provider(self, status, provider, key_name):
        key = self._creds_vars[key_name].get().strip()
        if not key:
            self._set_status(status, False, "enter a key first"); return
        self._testing(status)
        try:
            import requests
            if provider == "claude":
                r = requests.get("https://api.anthropic.com/v1/models", headers={
                    "x-api-key": key, "anthropic-version": "2023-06-01"}, timeout=15)
            elif provider == "openai":
                r = requests.get("https://api.openai.com/v1/models",
                                 headers={"Authorization": f"Bearer {key}"}, timeout=15)
            elif provider == "deepseek":
                r = requests.get("https://api.deepseek.com/v1/models",
                                 headers={"Authorization": f"Bearer {key}"}, timeout=15)
            elif provider == "kimi":
                r = requests.get("https://api.moonshot.cn/v1/models",
                                 headers={"Authorization": f"Bearer {key}"}, timeout=15)
            else:
                self._set_status(status, False, "unknown provider"); return
            self._set_status(status, r.status_code == 200,
                             "key valid" if r.status_code == 200
                             else f"rejected (HTTP {r.status_code})")
        except Exception as e:
            self._set_status(status, False, str(e)[:70])

    def _test_drive(self, status):
        import json, os
        path = self._creds_vars["google_credentials"].get().strip()
        folder = self._creds_vars["drive_folder_id"].get().strip()
        if not path:
            self._set_status(status, False, "choose a credentials JSON first"); return
        if not os.path.isfile(path):
            self._set_status(status, False, "file not found"); return
        try:
            data = json.load(open(path, encoding="utf-8"))
        except Exception:
            self._set_status(status, False, "not valid JSON"); return
        looks_ok = isinstance(data, dict) and (
            "installed" in data or "web" in data or "client_email" in data)
        if not looks_ok:
            self._set_status(status, False, "doesn't look like Google creds"); return
        if not folder:
            self._set_status(status, True, "creds look valid — add a backup folder ID")
        else:
            self._set_status(status, True, "creds + folder set — SUYB proves the live link")

    def _browse(self, var):
        p = filedialog.askopenfilename(parent=self, title="Choose credentials JSON",
                                       filetypes=[("JSON", "*.json"), ("All", "*.*")])
        if p:
            var.set(p)

    def _build_setup(self, parent):
        auth = self._card(parent, "DEVICE AUTHORIZATION  ·  one CMS licence, up to four computers")
        auth_row = tk.Frame(auth, bg=CARD)
        auth_row.pack(fill="x", padx=14, pady=(0, 8))
        self._device_site = tk.StringVar()
        for column, (label, variable, secret) in enumerate((
                ("CMS SITE URL", self._device_site, False),)):
            cell = tk.Frame(auth_row, bg=CARD)
            cell.grid(row=0, column=column, sticky="ew", padx=(0, 10))
            auth_row.grid_columnconfigure(column, weight=1)
            tk.Label(cell, text=label, bg=CARD, fg=DIM, font=("Segoe UI", 8)).pack(anchor="w")
            entry = tk.Entry(cell, textvariable=variable, show="•" if secret else "", bg=FIELD, fg=INK,
                             insertbackground=INK, relief="flat", font=("Consolas", 9))
            entry.pack(fill="x", ipady=5)
            if not secret:
                self._device_site_entry = entry
        auth_buttons = tk.Frame(auth, bg=CARD)
        auth_buttons.pack(fill="x", padx=14, pady=(0, 12))
        tk.Button(auth_buttons, text="AUTHORIZE THIS COMPUTER", command=self._activate_device,
                  bg=ACCENT, fg=BG, relief="flat", font=("Segoe UI", 9, "bold")).pack(side="left", ipadx=8, ipady=4)
        tk.Button(auth_buttons, text="CHECK NOW", command=self._refresh_device_auth,
                  bg=FIELD, fg=INK, relief="flat", font=("Segoe UI", 9, "bold")).pack(side="left", padx=8, ipadx=8, ipady=4)
        self._device_status = tk.Label(auth_buttons, text="", bg=CARD, fg=DIM, font=("Segoe UI", 9))
        self._device_status.pack(side="left", padx=8)
        self._show_device_auth_status()

        card = self._card(parent, "HUB SETUP  ·  set once, every tool has it")
        self._field(card, "HUB SITE URL",   "hub_url")
        self._field(card, "HUB API KEY",    "hub_key", show="•", test=self._test_hub)
        self._field(card, "CLAUDE API KEY", "claude_api_key", show="•",
                    test=lambda s: self._test_ai_provider(s, "claude", "claude_api_key"))
        self._field(card, "GEMINI API KEY", "gemini_api_key", show="•", test=self._test_gemini)
        self._field(card, "KIMI API KEY", "kimi_api_key", show="•", reveal=True)
        self._field(card, "DEEPSEEK API KEY", "deepseek_api_key", show="•", reveal=True)
        self._field(card, "CLAUDE API KEY", "claude_api_key", show="•", reveal=True)
        self._field(card, "OPENAI API KEY", "openai_api_key", show="•", reveal=True)
        self._field(card, "GOOGLE DRIVE CREDENTIALS (json)", "google_credentials", browse=True, test=self._test_drive)
        self._field(card, "BACKUP FOLDER ID", "drive_folder_id", show="•", reveal=True)
        bar = tk.Frame(card, bg=CARD)
        bar.pack(fill="x", padx=14, pady=(4, 12))
        save_b = tk.Button(bar, text="SAVE SHARED CREDENTIALS", bg=INK, fg=BG,
                           activebackground=ACCENT, activeforeground=BG, relief="flat",
                           font=("Segoe UI", 9, "bold"), cursor="hand2",
                           command=self._on_save_creds)
        save_b.pack(side="left", ipadx=8, ipady=4)
        self._hoverize(save_b)
        disc_b = tk.Button(bar, text="⟳  DISCOVER FLEET", bg=ACCENT, fg=BG,
                           activebackground="#2ecc10", relief="flat",
                           font=("Segoe UI", 9, "bold"), cursor="hand2",
                           command=self._on_discover)
        disc_b.pack(side="left", padx=(10, 0), ipadx=8, ipady=4)
        self._hoverize(disc_b, hover_bg="#2ecc10")
        self._setup_status = tk.Label(bar, text="", bg=CARD, fg=DIM,
                                      font=("Segoe UI", 9))
        self._setup_status.pack(side="left", padx=12)

    def _show_device_auth_status(self, result=None):
        if not hasattr(self, "_device_status"):
            return
        result = result or snap_device_auth.decision()
        status = result.get("status", "restricted")
        payload = result.get("payload") or {}
        if status == "full":
            expiry = datetime.fromtimestamp(int(payload.get("expires_at", 0))).strftime("%Y-%m-%d")
            text, colour = f"✓ AUTHORIZED · renew by {expiry}", ACCENT
        elif status == "grace":
            expiry = datetime.fromtimestamp(int(payload.get("grace_ends_at", 0))).strftime("%Y-%m-%d")
            text, colour = f"! GRACE PERIOD · connect by {expiry}", "#ffb000"
        else:
            text, colour = "RESTRICTED · SNAP SLAPPER opens and exports only", "#ff5555"
        self._device_status.configure(text=text, fg=colour)
        if result.get("site_url") and not self._device_site.get().strip():
            self._device_site.set(result["site_url"])

    def _activate_device(self):
        site = self._device_site.get().strip()
        hub_key = self._creds_vars.get("hub_key").get().strip()
        if not site or not hub_key:
            messagebox.showwarning(
                "Device authorization",
                "Enter the CMS site and save its Hub API key first.",
                parent=self._settings_window)
            return
        evidence = snap_session_gate.load()
        creds = snap_stepup.prompt_stepup_dialog(
            self._settings_window, site_url=site,
            username_default=getattr(evidence, "username", ""),
            title="Authorize this computer")
        if creds is None:
            return
        username, password, totp = creds
        self._device_status.configure(text="authorizing…", fg=DIM)
        self.update_idletasks()
        try:
            result = snap_device_auth.enroll(
                site, hub_key, username, password, totp, BUILD_VERSION)
            self._show_device_auth_status(result)
        except Exception as exc:
            self._device_status.configure(text=f"authorization failed: {exc}", fg="#ff5555")

    def _refresh_device_auth(self):
        self._device_status.configure(text="checking…", fg=DIM); self.update_idletasks()
        try:
            self._show_device_auth_status(snap_device_auth.refresh(BUILD_VERSION))
        except Exception as exc:
            self._device_status.configure(text=f"check failed: {exc}", fg="#ff5555")


    def _load_creds(self):
        for key, var in self._creds_vars.items():
            try:
                var.set(snap_creds.get(key, ""))
            except Exception:
                pass
        if hasattr(self, "_device_site") and not self._device_site.get().strip():
            hub_url = self._creds_vars.get("hub_url")
            if hub_url is not None:
                self._device_site.set(hub_url.get().strip())

    def _save_typed_creds(self):
        """Persist locally-typed secrets to the shared vault. Returns the count."""
        if any(var.get().strip() for var in self._creds_vars.values()):
            snap_creds.prepare_explicit_replacement()
        n = 0
        for key, var in self._creds_vars.items():
            val = var.get().strip()
            if val:
                snap_creds.set(key, val); n += 1
            else:
                # An intentionally emptied field means remove it. This restores
                # the pre-0.7.30 UI contract and avoids immortal stale keys.
                snap_creds.delete(key)
        return n

    def _on_save_creds(self):
        """Persist the form EXACTLY as shown. Typed values are saved; a field the
        user CLEARED removes that secret from the vault (the form is loaded from the
        vault, so an empty field means 'make this empty'). Unlike the non-destructive
        _save_typed_creds() that DISCOVER uses, the explicit Save can clear.

        Safety: only clear a key that currently reads back as a non-empty secret, so a
        locked/unreadable vault entry the user never actually saw is never deleted."""
        try:
            saved = cleared = 0
            for key, var in self._creds_vars.items():
                val = var.get().strip()
                if val:
                    snap_creds.set(key, val); saved += 1
                elif snap_creds.get(key, "").strip():
                    snap_creds.delete(key); cleared += 1
            msg = f"✓ {saved} credential(s) saved"
            if cleared:
                msg += f", {cleared} cleared"
            self._setup_status.configure(text=f"{msg} · shared vault", fg=ACCENT)
        except Exception as e:
            self._setup_status.configure(text=f"save failed: {e}", fg="#ff5555")

    def _on_discover(self):
        hub_url = self._creds_vars["hub_url"].get().strip()
        hub_key = self._creds_vars["hub_key"].get().strip()
        if not hub_url:
            messagebox.showwarning("Hub URL required",
                                   "Enter your hub site URL first.", parent=self)
            return
        # Save whatever is typed FIRST, so a user who never clicks Save never
        # loses their Gemini/Drive keys. Discover both saves and pulls.
        try:
            self._save_typed_creds()
        except Exception as e:
            self._setup_status.configure(text="credentials were not saved", fg="#ff5555")
            try:
                import snap_errors
                snap_errors.show_error("Credentials not saved", e, parent=self)
            except Exception:
                messagebox.showerror("Credentials not saved", str(e), parent=self)
            return
        self._setup_status.configure(text="saving + discovering…", fg=DIM)
        self.update_idletasks()
        try:
            summary = snap_discovery.discover_and_save(hub_url, api_key=hub_key)
        except Exception as e:
            self._setup_status.configure(text="", fg=DIM)
            try:
                import snap_errors
                snap_errors.show_error("Discovery failed", e, parent=self)
            except Exception:
                messagebox.showerror("Discovery failed", str(e), parent=self)
            return
        self._load_creds()
        self._refresh_profiles()
        n = summary.get("count", 0)
        native_failed = summary.get("native_credential_failures") or []
        if native_failed:
            self._setup_status.configure(
                text=f"saved {n} site(s), but {len(native_failed)} native app credential(s) failed — details shown",
                fg="#ff5555")
            messagebox.showerror(
                "Some app credentials were not saved",
                "Windows protected storage refused:\n\n" + "\n".join(
                    f"{row['site_url']} — {row['key_type']}" for row in native_failed),
                parent=self)
        else:
            self._setup_status.configure(text=f"✓ saved + {n} site(s) into the shared store", fg=ACCENT)

    # ── shared profiles list ─────────────────────────────────────────────────
    def _build_profiles(self, parent):
        card = self._card(parent, "BLOG IMAGE SETUP")
        wrap = tk.Frame(card, bg=CARD)
        wrap.pack(fill="both", expand=True, padx=14, pady=(0, 12))

        tk.Label(
            wrap,
            text="Choose where finished images go, then set the instructions for each blog.",
            bg=CARD, fg=INK, font=("Segoe UI", 10), justify="left"
        ).pack(anchor="w", pady=(0, 12))

        root_row = tk.Frame(wrap, bg=CARD)
        root_row.pack(fill="x", pady=(0, 10))
        tk.Label(root_row, text="1. CHOOSE THE MAIN IMAGE FOLDER", bg=CARD,
                 fg=ACCENT, font=("Segoe UI", 9, "bold")).pack(anchor="w")
        tk.Label(root_row,
                 text="SNAP HQ creates one folder inside it for every blog.",
                 bg=CARD, fg=DIM, font=("Segoe UI", 9)).pack(anchor="w", pady=(2, 4))
        root_line = tk.Frame(root_row, bg=CARD)
        root_line.pack(fill="x", pady=(3, 0))
        self._workflow_root_var = tk.StringVar(value=snap_site_settings.load_workflow_root())
        tk.Entry(root_line, textvariable=self._workflow_root_var, bg=FIELD, fg=INK,
                 insertbackground=INK, relief="flat", font=("Consolas", 9)).pack(
                     side="left", fill="x", expand=True, ipady=4)
        tk.Button(root_line, text="CHOOSE FOLDER…", command=self._browse_workflow_root, bg=FIELD,
                  fg=INK, relief="flat").pack(side="left", padx=(6, 0), ipadx=7, ipady=3)
        tk.Button(root_line, text="USE THIS FOLDER", command=self._save_workflow_root,
                  bg=ACCENT, fg=BG, relief="flat", font=("Segoe UI", 8, "bold")).pack(
                      side="left", padx=(6, 0), ipadx=7, ipady=3)
        tk.Label(root_row,
                 text="Example: Main folder  ›  example.com  ›  Upload / Finished",
                 bg=CARD, fg=DIM, font=("Segoe UI", 8)).pack(anchor="w", pady=(4, 0))

        choose = tk.Frame(wrap, bg=CARD)
        choose.pack(fill="x", pady=(7, 10))
        tk.Label(choose, text="2. CHOOSE A BLOG", bg=CARD, fg=ACCENT,
                 font=("Segoe UI", 9, "bold")).pack(anchor="w", pady=(0, 4))
        self._prof_choice = tk.StringVar()
        self._prof_menu = tk.OptionMenu(choose, self._prof_choice, "")
        self._prof_menu.configure(bg=FIELD, fg=INK, activebackground=ACCENT,
                                  activeforeground=BG, relief="flat", highlightthickness=0,
                                  font=("Segoe UI", 10), anchor="w")
        self._prof_menu["menu"].configure(bg=FIELD, fg=INK)
        self._prof_menu.pack(fill="x", ipady=3)
        self._prof_choice.trace_add("write", lambda *_: self._on_profile_selected())

        editor = tk.Frame(wrap, bg=CARD)
        editor.pack(fill="both", expand=True)
        self._site_vars = {key: tk.StringVar() for key in
            ("max_long_edge", "jpeg_quality",
             "image_resize_enabled", "export_sharpen", "handoff_dir")}
        fields = [
            ("LONGEST EDGE MAX (px)", "max_long_edge"), ("JPEG QUALITY", "jpeg_quality"),
            ("RESIZE (on/off)", "image_resize_enabled"), ("SHARPEN", "export_sharpen"),
            ("BLOG FOLDER OVERRIDE (optional)", "handoff_dir"),
        ]
        tk.Label(editor, text="3. ENTER THIS BLOG'S AI INSTRUCTIONS", bg=CARD, fg=ACCENT,
                 font=("Segoe UI", 9, "bold")).pack(anchor="w")
        tk.Label(editor,
                 text="These instructions load automatically when you select this blog in a desktop tool.",
                 bg=CARD, fg=DIM, font=("Segoe UI", 9)).pack(anchor="w", pady=(2, 5))
        self._site_prompt = tk.Text(editor, height=5, wrap="word", bg=FIELD, fg=INK,
                                    insertbackground=INK, relief="flat", font=("Consolas", 9))
        self._site_prompt.pack(fill="x", pady=(0, 8))

        self._site_paths = tk.Label(editor, text="Choose a blog to see its folders.", bg=FIELD,
                                    fg=INK, justify="left", anchor="w",
                                    font=("Segoe UI", 9), padx=10, pady=8)
        self._site_paths.pack(fill="x", pady=(0, 8))

        advanced = tk.Frame(editor, bg=CARD)
        self._advanced_visible = False
        self._advanced_frame = tk.Frame(advanced, bg=CARD)
        self._advanced_button = tk.Button(
            advanced, text="▸ Advanced image settings", bg=CARD, fg=DIM,
            activebackground=CARD, activeforeground=INK, relief="flat", bd=0,
            cursor="hand2", font=("Segoe UI", 9), command=self._toggle_advanced)
        self._advanced_button.pack(anchor="w")
        advanced.pack(fill="x")

        for row, (label, key) in enumerate(fields, start=1):
            tk.Label(self._advanced_frame, text=label, bg=CARD, fg=DIM,
                     font=("Segoe UI", 8)).grid(row=row, column=0, sticky="w", pady=3)
            entry = tk.Entry(self._advanced_frame, textvariable=self._site_vars[key], bg=FIELD, fg=INK,
                             insertbackground=INK, relief="flat", font=("Consolas", 9))
            entry.grid(row=row, column=1, sticky="ew", padx=(10, 4), pady=3, ipady=4)
            if key == "handoff_dir":
                tk.Button(self._advanced_frame, text="CHOOSE…", command=self._browse_handoff, bg=FIELD, fg=INK,
                          relief="flat").grid(row=row, column=2, sticky="ew", pady=3)
        self._advanced_frame.grid_columnconfigure(1, weight=1)

        bar = tk.Frame(editor, bg=CARD)
        bar.pack(fill="x", pady=(10, 0))
        tk.Button(bar, text="SAVE THIS BLOG", command=self._save_site_settings, bg=ACCENT, fg=BG,
                  relief="flat", font=("Segoe UI", 9, "bold")).pack(side="left", ipadx=10, ipady=4)
        tk.Button(bar, text="GET CURRENT SETTINGS FROM BLOG", command=self._sync_site_settings,
                  bg=FIELD, fg=INK, relief="flat").pack(side="left", padx=8, ipadx=8, ipady=4)
        self._site_status = tk.Label(bar, text="Choose a blog above", bg=CARD, fg=DIM,
                                     font=("Segoe UI", 9))
        self._site_status.pack(side="left", padx=8)
        self._profile_rows = []
        self._profile_choice_map = {}

    def _toggle_advanced(self):
        self._advanced_visible = not self._advanced_visible
        if self._advanced_visible:
            self._advanced_frame.pack(fill="x", pady=(5, 0))
            self._advanced_button.configure(text="▾ Advanced image settings")
        else:
            self._advanced_frame.pack_forget()
            self._advanced_button.configure(text="▸ Advanced image settings")

    def _refresh_profiles(self):
        try:
            profs = snap_profiles.list_profiles()
        except Exception:
            profs = []
        self._profile_rows = profs
        menu = self._prof_menu["menu"]
        menu.delete(0, "end")
        self._profile_choice_map = {}
        if not profs:
            self._prof_choice.set("No blogs found — use FIND MY BLOGS above")
            self._site_status.configure(text="No blogs have been discovered yet", fg=DIM)
            return
        for index, profile in enumerate(profs):
            name = str(profile.get("name", "") or "Untitled blog").strip()
            url = str(profile.get("site_url", "") or "").strip()
            label = f"{name}  —  {url}"
            if label in self._profile_choice_map:
                label += f" ({index + 1})"
            self._profile_choice_map[label] = index
            menu.add_command(label=label, command=lambda value=label: self._prof_choice.set(value))
        current = self._prof_choice.get()
        if current not in self._profile_choice_map:
            self._prof_choice.set(next(iter(self._profile_choice_map)))
        else:
            self._on_profile_selected()

    def _selected_profile(self):
        index = self._profile_choice_map.get(self._prof_choice.get())
        return self._profile_rows[index] if index is not None and index < len(self._profile_rows) else None

    def _on_profile_selected(self, _event=None):
        profile = self._selected_profile()
        if not profile:
            return
        portable = snap_site_settings.validate_portable(profile.get("portable") or {})
        local = snap_site_settings.load_local(profile["site_url"])
        local_override = snap_site_settings.load_local_override(profile["site_url"])
        self._site_prompt.delete("1.0", "end")
        self._site_prompt.insert("1.0", portable.get("prompt", ""))
        for key, value in portable.items():
            if key == "prompt" or key not in self._site_vars:
                continue
            self._site_vars[key].set("on" if value is True else "off" if value is False else str(value))
        self._site_vars["handoff_dir"].set(local_override["handoff_dir"])
        paths = snap_site_settings.handoff_paths(profile["site_url"])
        self._site_paths.configure(
            text=f"Images waiting to upload:\n  {paths['upload'] or 'Choose the main image folder above'}\n\n"
                 f"Completed uploads:\n  {paths['completed'] or 'Choose the main image folder above'}")
        synced = (profile.get("portable_sync") or {}).get("synced_at")
        self._site_status.configure(text=("OFFLINE COPY — synced " + synced if synced else "NOT YET SYNCED"), fg=DIM)

    def _browse_handoff(self):
        path = filedialog.askdirectory(parent=self, title="Choose handoff parent folder")
        if path:
            self._site_vars["handoff_dir"].set(path)

    def _browse_workflow_root(self):
        path = filedialog.askdirectory(parent=self, title="Choose the default image workflow folder")
        if path:
            self._workflow_root_var.set(path)
            # Choosing the folder is the user's save action. Requiring a second,
            # easy-to-miss button left the picker value only in this window, so
            # sibling tools such as SYBU correctly found no persisted path.
            self._save_workflow_root()

    def _save_workflow_root(self):
        try:
            root = snap_site_settings.save_workflow_root(self._workflow_root_var.get())
            for profile in self._profile_rows:
                snap_site_settings.handoff_paths(profile["site_url"], create=True)
            self._refresh_profiles()
            self._site_status.configure(
                text=("DEFAULT FOLDER SAVED" if root else "DEFAULT FOLDER CLEARED"), fg=ACCENT)
        except Exception as exc:
            messagebox.showerror("Folder not saved", str(exc), parent=self)

    def _portable_form(self):
        return snap_site_settings.validate_portable({
            "prompt": self._site_prompt.get("1.0", "end").strip(),
            "max_long_edge": self._site_vars["max_long_edge"].get(),
            "jpeg_quality": self._site_vars["jpeg_quality"].get(),
            "image_resize_enabled": self._site_vars["image_resize_enabled"].get().lower() in ("on","1","true","yes"),
            "export_sharpen": self._site_vars["export_sharpen"].get(),
        })

    def _save_site_settings(self):
        profile = self._selected_profile()
        if not profile:
            return
        try:
            snap_site_settings.save_local(profile["site_url"], {"handoff_dir": self._site_vars["handoff_dir"].get()})
            snap_site_settings.handoff_paths(profile["site_url"], create=True)
            portable = self._portable_form()

            # The prompt has a long-established per-blog endpoint. Save it there
            # first so an older hub that does not yet know the newer portable
            # settings action cannot prevent the user's prompt from being saved.
            prompt_ok = snap_prompt_sync.push(
                profile, portable.get("prompt", ""), self._prompt_push)
            if not prompt_ok:
                raise RuntimeError(
                    "The blog did not accept the prompt. Use FIND MY BLOGS, then try again.")

            # Keep the complete local profile current for every desktop tool,
            # even while the fleet is rolling out the newer all-settings API.
            cached = snap_profiles.load_by_site(profile["site_url"]) or dict(profile)
            cached["portable"] = portable
            snap_profiles.save(cached)

            try:
                result = snap_settings_sync.save(profile["site_url"], portable)
                states = ", ".join(r.get("status", "saved") for r in result.get("results", []))
                self._site_status.configure(
                    text="✓ Prompt and blog settings saved" + (" — " + states if states else ""),
                    fg=ACCENT)
            except Exception as sync_exc:
                # Compatibility mode is an expected rollout state, not a failed
                # prompt save. The prompt is already live and all settings are in
                # the shared desktop profile. Avoid the old "Unknown action" box.
                if "unknown action" not in str(sync_exc).lower():
                    raise
                self._site_status.configure(
                    text="✓ Prompt saved to the blog; image settings saved on this computer",
                    fg=ACCENT)
        except Exception as exc:
            self._site_status.configure(text="Prompt was not saved", fg="#ff5555")
            messagebox.showerror("Could not save this blog", str(exc), parent=self)

    def _sync_site_settings(self):
        try:
            result = snap_settings_sync.refresh_all()
            self._refresh_profiles()
            self._site_status.configure(text="SYNCED " + result["synced_at"], fg=ACCENT)
        except Exception as exc:
            self._site_status.configure(text="OFFLINE — using last synchronized copy", fg="#ff5555")
            messagebox.showerror("Sync failed", str(exc), parent=self)

    # ── prompt sync ──────────────────────────────────────────────────────────
    # One WHOLE-POST AI prompt per blog (the single-call prompt that fills
    # caption / ALT / tags / colours in ONE request). It lives in two places —
    # the CMS setting on each blog (GET|POST gyss/prompt) and the shared desktop
    # pool (snap_prompts). This card syncs them: PULL brings every blog's prompt
    # into the pool so you see them all in one place; PUSH sends the edited
    # prompt back to a blog. Non-destructive: a blog whose live prompt differs
    # from your local pool is reported, never silently overwritten.
    def _build_prompts(self, parent):
        card = self._card(parent, "PROMPT SYNC  ·  one AI prompt per blog, shared across the fleet")
        wrap = tk.Frame(card, bg=CARD)
        wrap.pack(fill="both", expand=True, padx=14, pady=(0, 12))

        top = tk.Frame(wrap, bg=CARD)
        top.pack(fill="x", pady=(0, 6))
        tk.Label(top, text="SITE", bg=CARD, fg=DIM, font=("Segoe UI", 9)).pack(side="left")
        self._psite = tk.StringVar()
        self._psite_menu = tk.OptionMenu(top, self._psite, "")
        self._psite_menu.configure(bg=FIELD, fg=INK, activebackground=ACCENT,
                                   activeforeground=BG, relief="flat", highlightthickness=0,
                                   font=("Consolas", 10), width=30, anchor="w")
        self._psite_menu["menu"].configure(bg=FIELD, fg=INK)
        self._psite_menu.pack(side="left", padx=(8, 0))
        pull_all = tk.Button(top, text="⟳  PULL ALL FROM FLEET", bg=ACCENT, fg=BG,
                             activebackground="#2ecc10", relief="flat", cursor="hand2",
                             font=("Segoe UI", 9, "bold"), command=self._on_pull_all)
        pull_all.pack(side="right", ipadx=8, ipady=4)
        self._hoverize(pull_all, hover_bg="#2ecc10")

        self._ptext = tk.Text(wrap, height=6, bg=FIELD, fg=INK, relief="flat",
                              insertbackground=INK, wrap="word", font=("Consolas", 10),
                              highlightthickness=1, highlightbackground=BORDER, bd=0)
        self._ptext.pack(fill="both", expand=True, pady=(0, 6))

        bot = tk.Frame(wrap, bg=CARD)
        bot.pack(fill="x")
        copy_b = tk.Button(bot, text="COPY FROM…", bg=FIELD, fg=INK, relief="flat",
                           cursor="hand2", font=("Segoe UI", 9), command=self._on_copy_from)
        copy_b.pack(side="left", ipadx=6, ipady=3)
        pull_one = tk.Button(bot, text="⬇  PULL THIS SITE", bg=FIELD, fg=INK, relief="flat",
                             cursor="hand2", font=("Segoe UI", 9), command=self._on_pull_one)
        pull_one.pack(side="left", padx=(8, 0), ipadx=6, ipady=3)
        push_b = tk.Button(bot, text="⬆  PUSH TO THIS SITE", bg=INK, fg=BG,
                           activebackground=ACCENT, activeforeground=BG, relief="flat",
                           cursor="hand2", font=("Segoe UI", 9, "bold"), command=self._on_push_one)
        push_b.pack(side="right", ipadx=8, ipady=4)
        self._hoverize(push_b)
        self._psync_status = tk.Label(bot, text="", bg=CARD, fg=DIM, font=("Segoe UI", 9))
        self._psync_status.pack(side="right", padx=12)
        self._pprofiles = {}
        self._psite.trace_add("write", lambda *_: self._on_site_selected())

    def _prompt_profiles(self):
        try:
            return [p for p in snap_profiles.list_profiles() if p.get("site_url")]
        except Exception:
            return []

    def _refresh_prompt_sites(self):
        profs = self._prompt_profiles()
        self._pprofiles = {snap_prompt_sync.site_key(p): p for p in profs}
        menu = self._psite_menu["menu"]
        menu.delete(0, "end")
        keys = sorted(self._pprofiles.keys())
        for k in keys:
            menu.add_command(label=k, command=lambda v=k: self._psite.set(v))
        if keys:
            if self._psite.get() in self._pprofiles:
                self._on_site_selected()
            else:
                self._psite.set(keys[0])
        else:
            self._psite.set("")

    def _current_profile(self):
        p = self._pprofiles.get(self._psite.get())
        if not p:
            messagebox.showwarning("Pick a site", "Choose a site first "
                                   "(Discover Fleet fills the list).", parent=self)
        return p

    def _on_site_selected(self):
        key = self._psite.get()
        if not key:
            return
        try:
            pool = snap_prompts.load()
        except Exception:
            pool = {}
        self._ptext.delete("1.0", "end")
        self._ptext.insert("1.0", str(pool.get(key, "")))
        self._psync_status.configure(text="", fg=DIM)

    def _psync_err(self, title, e):
        try:
            import snap_errors
            snap_errors.show_error(title, e, parent=self)
        except Exception:
            messagebox.showerror(title, str(e), parent=self)

    def _gyss_key_for(self, profile):
        """A gyss-type Bearer key for this site. gyss/prompt requires key_type
        'gyss'; the stored api_key is the sybu posting key, so mint a gyss key
        from the full hub->spoke key in the shared vault, cached per run."""
        site = (profile.get("site_url") or "").rstrip("/")
        if self._gyss_keys.get(site):
            return self._gyss_keys[site]
        connection = snap_connections.resolve(site, "gyss")
        if connection and connection.get("api_key"):
            self._gyss_keys[site] = connection["api_key"]
            return connection["api_key"]
        # The hub deliberately has no self-referential multisite node, so it
        # cannot mint a GYSS key through the spoke-only provision-key route.
        # Its SNAP HQ key is accepted by the hub's prompt endpoint only.
        hub_site = (snap_creds.get("hub_url") or "").rstrip("/")
        hub_key = (snap_creds.get("hub_key") or "").strip()
        if site and site == hub_site and hub_key:
            self._gyss_keys[site] = hub_key
            return hub_key
        akl = snap_creds.get_site(site, "api_key_local").strip()
        key = ""
        if akl:
            try:
                key = snap_discovery._provision_spoke_key(site, akl, "gyss")
            except Exception:
                key = ""
        self._gyss_keys[site] = key
        return key

    def _prompt_fetch(self, profile):
        import requests
        site = (profile.get("site_url") or "").rstrip("/")
        key = self._gyss_key_for(profile)
        if not key:
            raise RuntimeError("no GYSS key for this site — run Discover Fleet")
        r = requests.get(site + "/api.php", params={"route": "gyss/prompt"},
                         headers={"Authorization": "Bearer " + key,
                                  "User-Agent": f"SnapSmackHub/{BUILD_VERSION}"}, timeout=25)
        if r.status_code != 200:
            raise RuntimeError(f"HTTP {r.status_code}")
        data = r.json() if r.content else {}
        if not data.get("ok", False):
            raise RuntimeError(data.get("error", "unexpected response"))
        return data.get("prompt", "")

    def _prompt_push(self, profile, text):
        import requests
        site = (profile.get("site_url") or "").rstrip("/")
        key = self._gyss_key_for(profile)
        if not key:
            return False
        r = requests.post(site + "/api.php", params={"route": "gyss/prompt"},
                          json={"prompt": text},
                          headers={"Authorization": "Bearer " + key,
                                   "User-Agent": f"SnapSmackHub/{BUILD_VERSION}"}, timeout=25)
        if r.status_code != 200:
            try:
                detail = str((r.json() or {}).get("error") or "").strip()
            except Exception:
                detail = ""
            raise RuntimeError(
                f"{site} rejected the prompt (HTTP {r.status_code})"
                + (f": {detail}" if detail else ""))
        try:
            return bool((r.json() or {}).get("ok", False))
        except Exception:
            return False

    def _on_pull_all(self):
        profs = self._prompt_profiles()
        if not profs:
            messagebox.showwarning("No sites", "Run Discover Fleet first.", parent=self)
            return
        self._psync_status.configure(text="pulling from the fleet…", fg=DIM)
        self.update_idletasks()
        try:
            report = snap_prompt_sync.pull(profs, self._prompt_fetch)
        except Exception as e:
            self._psync_status.configure(text="", fg=DIM)
            self._psync_err("Pull failed", e)
            return
        self._refresh_prompt_sites()
        added = report.get("added", [])
        unchanged = report.get("unchanged", [])
        differs = report.get("differs", [])
        failed = report.get("failed", [])
        msg = (f"Pulled {len(added) + len(unchanged)} blog(s) into the shared pool.\n\n"
               f"• {len(added)} new prompt(s) added\n"
               f"• {len(unchanged)} already matched\n")
        if differs:
            msg += ("• " + str(len(differs)) + " differ from your local pool (left untouched): "
                    + ", ".join(d["site"] for d in differs)
                    + "\n   Select one, then PULL THIS SITE to see the live copy, or PUSH to overwrite it.\n")
        if failed:
            msg += ("• " + str(len(failed)) + " could not be reached: "
                    + ", ".join(f'{f["site"]} ({f["error"]})' for f in failed) + "\n")
        self._psync_status.configure(
            text=f"✓ {len(added)} added · {len(differs)} differ · {len(failed)} failed",
            fg=ACCENT if not failed else "#e0a020")
        messagebox.showinfo("Prompt sync", msg, parent=self)

    def _on_pull_one(self):
        p = self._current_profile()
        if not p:
            return
        self._psync_status.configure(text="fetching this site's live prompt…", fg=DIM)
        self.update_idletasks()
        try:
            remote = self._prompt_fetch(p)
        except Exception as e:
            self._psync_status.configure(text="", fg=DIM)
            self._psync_err("Fetch failed", e)
            return
        self._ptext.delete("1.0", "end")
        self._ptext.insert("1.0", str(remote or ""))
        self._psync_status.configure(text="✓ showing the live prompt — PUSH to keep it, or edit first", fg=ACCENT)

    def _on_push_one(self):
        p = self._current_profile()
        if not p:
            return
        key = self._psite.get()
        text = self._ptext.get("1.0", "end").strip()
        if not messagebox.askyesno(
                "Push prompt to " + key,
                f"Send this prompt to {key}?\n\nIt becomes the prompt that blog's one-call AI fill "
                f"uses for every new image. An empty prompt resets that blog to its built-in default.",
                parent=self):
            return
        self._psync_status.configure(text="pushing…", fg=DIM)
        self.update_idletasks()
        try:
            ok = snap_prompt_sync.push(p, text, self._prompt_push)
        except Exception as e:
            self._psync_status.configure(text="", fg=DIM)
            self._psync_err("Push failed", e)
            return
        if ok:
            self._psync_status.configure(text=f"✓ pushed to {key} and saved to the shared pool", fg=ACCENT)
        else:
            self._psync_status.configure(
                text="push rejected — check the site key (re-run Discover Fleet)", fg="#ff5555")

    def _on_copy_from(self):
        keys = sorted(self._pprofiles.keys())
        others = [k for k in keys if k != self._psite.get()]
        if not others:
            messagebox.showinfo("Copy from", "No other blog to copy from yet.", parent=self)
            return
        win = tk.Toplevel(self)
        win.title("Copy prompt from…")
        win.configure(bg=CARD)
        win.transient(self)
        tk.Label(win, text="Use which blog's prompt as a starting point?", bg=CARD, fg=INK,
                 font=("Segoe UI", 10)).pack(padx=16, pady=(14, 8))
        lb = tk.Listbox(win, bg=FIELD, fg=INK, relief="flat", font=("Consolas", 10),
                        height=min(10, len(others)), selectbackground=ACCENT,
                        selectforeground=BG, highlightthickness=0, width=42)
        for k in others:
            lb.insert("end", k)
        lb.pack(fill="both", expand=True, padx=16, pady=(0, 8))

        def _do():
            sel = lb.curselection()
            if sel:
                src = others[sel[0]]
                try:
                    pool = snap_prompts.load()
                except Exception:
                    pool = {}
                self._ptext.delete("1.0", "end")
                self._ptext.insert("1.0", str(pool.get(src, "")))
                self._psync_status.configure(
                    text=f"copied {src}'s prompt into the editor — review, then PUSH", fg=ACCENT)
            win.destroy()

        use_b = tk.Button(win, text="USE THIS", bg=ACCENT, fg=BG, relief="flat",
                          font=("Segoe UI", 9, "bold"), cursor="hand2", command=_do)
        use_b.pack(pady=(0, 14), ipadx=10, ipady=4)


if __name__ == "__main__":
    app = Hub()
    if "--authorize" in sys.argv:
        app.after_idle(app._open_authorization)
    # Packaged-build smoke test: construct and lay out the real interface, then
    # exit without requiring a person to close a test window.
    qa_marker = os.environ.get("SNAP_HQ_QA_MARKER", "").strip()
    layout_qa_marker = os.environ.get("SNAP_HQ_LAYOUT_QA_MARKER", "").strip()
    credential_qa_marker = os.environ.get("SNAP_HQ_CREDENTIAL_QA_MARKER", "").strip()
    orphan_qa_marker = os.environ.get("SNAP_HQ_ORPHAN_QA_MARKER", "").strip()
    if layout_qa_marker:
        def widget_texts(root):
            texts = []
            pending = list(root.winfo_children())
            while pending:
                widget = pending.pop()
                pending.extend(widget.winfo_children())
                try:
                    text = str(widget.cget("text")).strip()
                except tk.TclError:
                    text = ""
                if text:
                    texts.append(text)
            return texts

        app.update_idletasks()
        launcher_text = widget_texts(app)
        forbidden = ("DEVICE AUTHORIZATION", "HUB SETUP", "BLOG IMAGE SETUP", "PROMPT SYNC")
        if any(any(text.startswith(title) for text in launcher_text) for title in forbidden):
            raise RuntimeError("Settings content leaked into the SNAP HQ launcher")
        app._open_settings()
        app.update_idletasks()
        settings_text = widget_texts(app._settings_window)
        required = ("DEVICE AUTHORIZATION", "HUB SETUP", "BLOG IMAGE SETUP", "PROMPT SYNC")
        if not all(any(text.startswith(title) for text in settings_text) for title in required):
            raise RuntimeError("SNAP HQ Settings is missing a configuration section")
        app.withdraw()
        with open(layout_qa_marker, "w", encoding="utf-8") as handle:
            handle.write(BUILD_VERSION)
        app.destroy()
    elif orphan_qa_marker:
        # Reproduce the real 0.7.30 mixed state inside the frozen executable:
        # inaccessible ciphertext plus readable legacy settings and no machine
        # key. The first new save must archive/rebuild, not fail or erase.
        import base64
        import snap_vault
        snap_creds.set("lost_probe", "old-inaccessible-value")
        data = snap_creds._read()
        data["readable_probe"] = "b64:" + base64.b64encode(b"keep-readable").decode()
        snap_creds._write(data)
        snap_vault.lock()
        snap_vault.clear_machine_key()
        snap_creds.set("hub_key", "new-working-key")
        if snap_creds.get("hub_key") != "new-working-key":
            raise RuntimeError("Orphan recovery did not save the replacement key")
        if snap_creds.get("readable_probe") != "keep-readable":
            raise RuntimeError("Orphan recovery lost a readable credential")
        if snap_creds.get("lost_probe") != "":
            raise RuntimeError("Orphan ciphertext remained active")
        recovery = os.path.join(snap_home.auth_dir(), "recovery")
        if not os.path.isdir(recovery) or not os.listdir(recovery):
            raise RuntimeError("Orphan vault was not archived")
        app.withdraw()
        with open(orphan_qa_marker, "w", encoding="utf-8") as handle:
            handle.write(BUILD_VERSION)
        app.destroy()
    elif credential_qa_marker:
        # Real packaged-process proof: save, forget the in-memory key as a full
        # restart would, reopen through Windows protected storage, and decrypt.
        # QA runs only with an isolated SNAPSMACK_HOME supplied by the builder.
        import snap_vault
        probe = "snap-hq-packaged-credential-probe"
        snap_creds.set("hub_key", probe)
        snap_vault.lock()
        snap_creds._initialized_for = None
        snap_creds.init()
        if snap_creds.get("hub_key") != probe:
            raise RuntimeError("Packaged credential restart round-trip failed")
        with open(snap_creds._store_path(), "rb") as handle:
            if probe.encode("utf-8") in handle.read():
                raise RuntimeError("Packaged credential was stored in plaintext")
        app.withdraw()
        with open(credential_qa_marker, "w", encoding="utf-8") as handle:
            handle.write(BUILD_VERSION)
        app.destroy()
    elif qa_marker:
        app.withdraw()
        app.update_idletasks()
        with open(qa_marker, "w", encoding="utf-8") as handle:
            handle.write(BUILD_VERSION)
        app.destroy()
    else:
        app.mainloop()
# ===== SNAPSMACK EOF =====
