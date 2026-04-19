"""
Smack Up Your Backup — main.py
Multi-blog backup & restore engine for SnapSmack.
Dark UI palette, tkinter, PyInstaller single-exe build chain.
Same visual family as Smack Your Batch Up.
"""

BUILD_VERSION = "0.2.6"

import os
import queue
import threading
import time
import tkinter as tk
from tkinter import filedialog, messagebox, ttk
from typing import Optional

import config as cfg_module
import profile_manager
import sync_manager
import manifest_reader
import cloud_manifest as cloud_manifest_module
import cloud_client as cloud_module
from audit_engine import AuditEngine, AuditReport
from backup_engine import BackupEngine
from cloud_sync_engine import CloudSyncEngine
from restore_engine import RestoreEngine
from report_writer import write_txt, write_html


# ---------------------------------------------------------------------------
# Palette — warm dark with soft green accents
# ---------------------------------------------------------------------------
BG_DEEP   = "#1a1a22"       # deepest background (main window)
BG_MID    = "#22222c"       # card / section background
BG_CARD   = "#2c2c38"       # elevated surface (buttons, hover areas)
BG_INPUT  = "#2a2a34"       # text input fields
ACCENT    = "#5dea5d"       # primary accent — softer leaf green
ACCENT2   = "#4ac34a"       # secondary accent — pressed / active states
FG_MAIN   = "#e8e8ec"       # primary text
FG_DIM    = "#888890"       # secondary / muted text
FG_OK     = "#5dea5d"       # success
FG_WARN   = "#f0b030"       # warning — slightly warmer
FG_ERR    = "#e86060"       # error
BORDER    = "#3a3a46"       # card borders, dividers

FONT_TITLE = ("Segoe UI", 14, "bold")
FONT_HEAD  = ("Segoe UI", 11, "bold")
FONT_BODY  = ("Segoe UI", 10)
FONT_SMALL = ("Segoe UI", 9)
FONT_MONO  = ("Consolas", 11)

TAB_BACKUP     = "backup"
TAB_RESTORE    = "restore"
TAB_AUDIT      = "audit"
TAB_SCHEDULER  = "scheduler"
TAB_SETTINGS   = "settings"
TAB_CLOUD_SYNC = "cloud_sync"
TAB_HELP       = "help"


# ---------------------------------------------------------------------------
# File dialog helpers — PowerShell subprocess on Windows, tkinter elsewhere
# ---------------------------------------------------------------------------
# tkinter's filedialog silently fails inside --windowed PyInstaller exes when
# the calling widget is a Frame in a notebook tab, and ctypes Win32 calls also
# fail silently.  The nuclear option: spawn the dialog in a separate PowerShell
# process using .NET System.Windows.Forms.  This runs in its own process with
# its own window, so tkinter's window hierarchy is irrelevant.  The 1-2 second
# PowerShell startup is the only downside.  Non-Windows keeps tkinter.

import sys as _sys
import subprocess as _sp
import threading as _threading

_CREATE_NO_WINDOW = 0x08000000  # prevents PowerShell console flash


def _ps_filter(filetypes):
    """Convert [('Desc', '*.ext'), …] to .NET dialog filter format."""
    if not filetypes:
        return "All Files (*.*)|*.*"
    return "|".join(f"{d} ({e})|{e}" for d, e in filetypes)


def _ps_run(widget, cmd: str) -> str:
    """
    Run a PowerShell dialog command in a background thread and wait for it
    using tkinter's wait_variable so the event loop stays alive (no freeze,
    no 'not responding', no timeout).
    """
    result_holder = [""]
    done_var = tk.StringVar(widget.winfo_toplevel(), value="")

    def _run():
        try:
            r = _sp.run(
                ['powershell', '-noprofile', '-command', cmd],
                capture_output=True, text=True,
                creationflags=_CREATE_NO_WINDOW,
                # No timeout — user may browse slowly
            )
            result_holder[0] = r.stdout.strip()
        except Exception:
            pass
        # Signal completion from the main thread via after()
        widget.after(0, lambda: done_var.set("done"))

    _threading.Thread(target=_run, daemon=True).start()
    # wait_variable keeps tkinter's event loop running while we wait
    widget.winfo_toplevel().wait_variable(done_var)
    return result_holder[0]


def _ps_open(widget, title, filetypes):
    filt = _ps_filter(filetypes).replace("'", "''")
    title = title.replace("'", "''")
    cmd = (
        "Add-Type -AssemblyName System.Windows.Forms;"
        "$d = New-Object System.Windows.Forms.OpenFileDialog;"
        f"$d.Title = '{title}';"
        f"$d.Filter = '{filt}';"
        "if ($d.ShowDialog() -eq 'OK') { $d.FileName }"
    )
    return _ps_run(widget, cmd)


def _ps_save(widget, title, filetypes, ext, initialfile):
    filt = _ps_filter(filetypes).replace("'", "''")
    title = title.replace("'", "''")
    initialfile = (initialfile or "").replace("'", "''")
    cmd = (
        "Add-Type -AssemblyName System.Windows.Forms;"
        "$d = New-Object System.Windows.Forms.SaveFileDialog;"
        f"$d.Title = '{title}';"
        f"$d.Filter = '{filt}';"
        f"$d.DefaultExt = '{ext.lstrip('.')}';"
        f"$d.FileName = '{initialfile}';"
        "if ($d.ShowDialog() -eq 'OK') { $d.FileName }"
    )
    return _ps_run(widget, cmd)


def _ps_folder(widget, title):
    title = title.replace("'", "''")
    cmd = (
        "Add-Type -AssemblyName System.Windows.Forms;"
        "$d = New-Object System.Windows.Forms.FolderBrowserDialog;"
        f"$d.Description = '{title}';"
        "$d.ShowNewFolderButton = $true;"
        "if ($d.ShowDialog() -eq 'OK') { $d.SelectedPath }"
    )
    return _ps_run(widget, cmd)


def _dlg_open(widget, title="Open", filetypes=None, **kwargs) -> str:
    try:
        if _sys.platform == 'win32':
            return _ps_open(widget, title, filetypes or [("All Files", "*.*")])
        return filedialog.askopenfilename(
            parent=widget.winfo_toplevel(), title=title, filetypes=filetypes, **kwargs) or ""
    except Exception as e:
        messagebox.showerror("Browse error", f"{type(e).__name__}: {e}")
        return ""


def _dlg_dir(widget, title="Select Folder", **kwargs) -> str:
    try:
        if _sys.platform == 'win32':
            return _ps_folder(widget, title)
        return filedialog.askdirectory(
            parent=widget.winfo_toplevel(), title=title, **kwargs) or ""
    except Exception as e:
        messagebox.showerror("Browse error", f"{type(e).__name__}: {e}")
        return ""


def _dlg_save(widget, title="Save As", filetypes=None, defaultextension="",
              initialfile="", **kwargs) -> str:
    try:
        if _sys.platform == 'win32':
            return _ps_save(widget, title, filetypes or [("All Files", "*.*")],
                            defaultextension, initialfile)
        return filedialog.asksaveasfilename(
            parent=widget.winfo_toplevel(), title=title, filetypes=filetypes,
            defaultextension=defaultextension, initialfile=initialfile, **kwargs) or ""
    except Exception as e:
        messagebox.showerror("Browse error", f"{type(e).__name__}: {e}")
        return ""


# ---------------------------------------------------------------------------
# First-run setup wizard
# ---------------------------------------------------------------------------

class SetupWizard(tk.Toplevel):
    """Friendly multi-step wizard for first-time users."""

    STEPS = [
        "Welcome",
        "Blog Details",
        "Admin Login",
        "FTP Setup",
        "Backup Destination",
        "Ready!",
    ]

    def __init__(self, parent):
        super().__init__(parent)
        self.title("Welcome to Smack Up Your Backup")
        self.configure(bg=BG_DEEP)
        self.resizable(False, False)
        self.geometry("620x520")
        self.transient(parent)
        self.grab_set()
        self.result: Optional[dict] = None
        self._step = 0
        self._data = profile_manager.new_profile_template()
        self._frames: list[tk.Frame] = []
        self._vars: dict[str, tk.Variable] = {}

        # ── Layout skeleton ─────────────────────────────────────────────
        # Progress dots
        self._dots_frame = tk.Frame(self, bg=BG_DEEP)
        self._dots_frame.pack(fill="x", padx=30, pady=(20, 0))

        # Content area
        self._content = tk.Frame(self, bg=BG_DEEP)
        self._content.pack(fill="both", expand=True, padx=30, pady=10)

        # Navigation buttons
        nav = tk.Frame(self, bg=BG_DEEP)
        nav.pack(fill="x", padx=30, pady=(0, 20))

        self._back_btn = tk.Button(nav, text="← Back", bg=BG_CARD, fg=FG_MAIN,
                                    relief="flat", font=FONT_BODY, padx=14, pady=6,
                                    command=self._back)
        self._back_btn.pack(side="left")

        self._skip_btn = tk.Button(nav, text="Skip Setup", bg=BG_CARD, fg=FG_DIM,
                                    relief="flat", font=FONT_SMALL, padx=10, pady=6,
                                    command=self.destroy)
        self._skip_btn.pack(side="left", padx=(10, 0))

        self._next_btn = tk.Button(nav, text="Next →", bg=ACCENT, fg=BG_DEEP,
                                    relief="flat", font=FONT_HEAD, padx=18, pady=6,
                                    command=self._next)
        self._next_btn.pack(side="right")

        self._build_steps()
        self._show_step(0)

    # ── Step builders ───────────────────────────────────────────────────
    def _make_frame(self):
        f = tk.Frame(self._content, bg=BG_DEEP)
        self._frames.append(f)
        return f

    def _heading(self, parent, text, sub=""):
        tk.Label(parent, text=text, bg=BG_DEEP, fg=FG_MAIN,
                 font=FONT_TITLE, anchor="w").pack(anchor="w", pady=(0, 4))
        if sub:
            tk.Label(parent, text=sub, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_BODY, anchor="w", wraplength=540,
                     justify="left").pack(anchor="w", pady=(0, 12))

    def _field(self, parent, label, key, show="", width=40):
        row = tk.Frame(parent, bg=BG_DEEP)
        row.pack(fill="x", pady=4)
        tk.Label(row, text=label, bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_BODY, width=18, anchor="w").pack(side="left")
        var = tk.StringVar(value=str(self._data.get(key, "")))
        tk.Entry(row, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=width, show=show).pack(
            side="left", fill="x", expand=True)
        self._vars[key] = var
        return var

    def _status_label(self, parent):
        var = tk.StringVar(value="")
        tk.Label(parent, textvariable=var, bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_SMALL, anchor="w").pack(anchor="w", pady=(8, 0))
        return var

    def _build_steps(self):
        # Step 0: Welcome
        f = self._make_frame()
        self._heading(f,
            "Welcome to Smack Up Your Backup",
            "This wizard will walk you through connecting to your SnapSmack blog "
            "and setting up your first backup profile.\n\n"
            "Here's what SUYB does for you:")
        features = tk.Frame(f, bg=BG_MID, padx=16, pady=12,
                            highlightbackground=BORDER, highlightthickness=1)
        features.pack(fill="x", pady=(0, 10))
        for icon, text in [
            ("📦", "Downloads your blog's recovery kit, database, and media files"),
            ("🔄", "Differential backups — only grabs what changed since last time"),
            ("☁️",  "Optionally uploads to Google Drive or OneDrive"),
            ("🔍", "Audit mode checks your server for missing or orphaned files"),
            ("⏪", "Restore mode puts everything back if disaster strikes"),
        ]:
            row = tk.Frame(features, bg=BG_MID)
            row.pack(fill="x", pady=3)
            tk.Label(row, text=icon, bg=BG_MID, font=FONT_BODY).pack(side="left", padx=(0, 10))
            tk.Label(row, text=text, bg=BG_MID, fg=FG_MAIN,
                     font=FONT_BODY, anchor="w").pack(side="left")

        # Step 1: Blog details
        f = self._make_frame()
        self._heading(f,
            "Blog Details",
            "Enter your blog's name and URL. The name is just a label — "
            "pick whatever helps you identify this site.")
        self._field(f, "Blog name", "name")
        self._field(f, "Site URL", "site_url")

        # Step 2: Admin login
        f = self._make_frame()
        self._heading(f,
            "SnapSmack Admin Login",
            "SUYB logs into your blog's admin panel to download the recovery kit "
            "and SQL backups. Use the same credentials you log in with.")
        self._field(f, "Admin username", "snap_admin_user")
        self._field(f, "Admin password", "snap_admin_pass", show="●")
        self._admin_status = self._status_label(f)

        test_row = tk.Frame(f, bg=BG_DEEP)
        test_row.pack(anchor="w", pady=(4, 0))
        tk.Button(test_row, text="Test Connection", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=12, pady=4,
                  command=self._test_admin).pack(side="left")

        # Step 3: FTP
        f = self._make_frame()
        self._heading(f,
            "FTP Connection",
            "SUYB uses FTP to download and upload media files. "
            "Your web host provides these credentials.")
        self._field(f, "FTP host", "ftp_host")
        self._field(f, "Port", "ftp_port", width=8)
        self._field(f, "Username", "ftp_user")
        self._field(f, "Password", "ftp_pass", show="●")
        self._field(f, "Remote directory", "ftp_remote_dir")

        ssl_var = tk.BooleanVar(value=True)
        self._vars["ftp_ssl"] = ssl_var
        tk.Checkbutton(f, text="Use FTP_TLS (recommended)", variable=ssl_var,
                       bg=BG_DEEP, fg=FG_MAIN, selectcolor=BG_INPUT,
                       activebackground=BG_DEEP, font=FONT_BODY).pack(
            anchor="w", pady=(6, 0))

        self._ftp_status = self._status_label(f)
        test_row2 = tk.Frame(f, bg=BG_DEEP)
        test_row2.pack(anchor="w", pady=(4, 0))
        tk.Button(test_row2, text="Test FTP", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=12, pady=4,
                  command=self._test_ftp).pack(side="left")

        # Step 4: Backup destination
        f = self._make_frame()
        self._heading(f,
            "Backup Destination",
            "Choose where to store backup files on this computer. "
            "Cloud upload is optional — you can configure it later in Settings.")
        self._field(f, "Local folder", "backup_dir")
        browse_row = tk.Frame(f, bg=BG_DEEP)
        browse_row.pack(anchor="w", pady=(0, 10))
        tk.Button(browse_row, text="Browse…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=12, pady=4,
                  command=self._browse_dir).pack(side="left")

        # Step 5: Summary / ready
        f = self._make_frame()
        self._heading(f,
            "You're all set!",
            "Your profile is ready. Click Finish to save it and "
            "jump to the Backup tab where you can run your first backup.")
        self._summary_var = tk.StringVar()
        tk.Label(f, textvariable=self._summary_var, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_BODY, padx=16, pady=12, justify="left", anchor="nw",
                 highlightbackground=BORDER, highlightthickness=1,
                 wraplength=520).pack(fill="x", pady=(0, 10))

        tour_lbl = tk.Frame(f, bg=BG_DEEP)
        tour_lbl.pack(fill="x", pady=(4, 0))
        tk.Label(tour_lbl, text="Quick tour:", bg=BG_DEEP, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w")
        for tab, desc in [
            ("Backup", "Run backups — differential or full, one blog or all at once"),
            ("Restore", "Upload files back to your server from a backup package"),
            ("Audit", "Scan your server for missing, orphaned, or mismatched files"),
            ("Settings", "Manage profiles, cloud config, and global defaults"),
        ]:
            r = tk.Frame(f, bg=BG_DEEP)
            r.pack(fill="x", pady=1)
            tk.Label(r, text=f"{tab}:", bg=BG_DEEP, fg=ACCENT,
                     font=FONT_BODY, width=10, anchor="w").pack(side="left")
            tk.Label(r, text=desc, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_BODY, anchor="w").pack(side="left")

    # ── Navigation ──────────────────────────────────────────────────────
    def _show_step(self, idx):
        self._step = idx
        for f in self._frames:
            f.pack_forget()
        self._frames[idx].pack(fill="both", expand=True)

        # Update dots
        for w in self._dots_frame.winfo_children():
            w.destroy()
        for i, name in enumerate(self.STEPS):
            color = ACCENT if i == idx else (FG_DIM if i > idx else ACCENT2)
            tk.Label(self._dots_frame, text=f"● {name}" if i == idx else "●",
                     bg=BG_DEEP, fg=color, font=FONT_SMALL).pack(side="left", padx=3)

        # Button states
        self._back_btn.configure(state="normal" if idx > 0 else "disabled")
        if idx == len(self.STEPS) - 1:
            self._next_btn.configure(text="Finish ✓")
        else:
            self._next_btn.configure(text="Next →")

    def _collect(self):
        """Pull all StringVar values into self._data."""
        for key, var in self._vars.items():
            val = var.get()
            if key in ("ftp_port", "pacing_delay", "batch_size"):
                try:
                    val = int(val)
                except ValueError:
                    pass
            elif key == "ftp_ssl":
                val = bool(var.get())
            self._data[key] = val

    def _next(self):
        self._collect()

        # Validate current step
        if self._step == 1:  # Blog details
            if not self._data.get("name", "").strip():
                messagebox.showwarning("Blog name required",
                    "Enter a name for this blog profile.", parent=self)
                return
        elif self._step == 4:  # Backup destination
            if not self._data.get("backup_dir", "").strip():
                messagebox.showwarning("Folder required",
                    "Pick a local folder for backup storage.", parent=self)
                return

        if self._step < len(self.STEPS) - 2:
            self._show_step(self._step + 1)
        elif self._step == len(self.STEPS) - 2:
            # Moving to summary — populate it
            d = self._data
            self._summary_var.set(
                f"Blog:   {d.get('name', '')}\n"
                f"URL:    {d.get('site_url', '')}\n"
                f"FTP:    {d.get('ftp_user', '')}@{d.get('ftp_host', '')}:{d.get('ftp_port', 21)}\n"
                f"Folder: {d.get('backup_dir', '')}"
            )
            self._show_step(self._step + 1)
        else:
            # Finish
            self.result = dict(self._data)
            self.destroy()

    def _back(self):
        if self._step > 0:
            self._collect()
            self._show_step(self._step - 1)

    # ── Actions ─────────────────────────────────────────────────────────
    def _test_admin(self):
        self._collect()
        self._admin_status.set("Testing…")
        self.update_idletasks()
        import requests
        try:
            url = self._data.get("site_url", "").rstrip("/")
            r = requests.get(f"{url}/login.php", timeout=10,
                             allow_redirects=False)
            if r.status_code < 400:
                self._admin_status.set("✓ Blog is reachable")
            else:
                self._admin_status.set(f"⚠ HTTP {r.status_code}")
        except Exception as e:
            self._admin_status.set(f"✗ {e}")

    def _test_ftp(self):
        self._collect()
        self._ftp_status.set("Connecting…")
        self.update_idletasks()
        try:
            from ftp_client import connect_ftp
            ftp = connect_ftp(self._data)
            ftp.quit()
            self._ftp_status.set("✓ FTP connected successfully")
        except Exception as e:
            self._ftp_status.set(f"✗ {e}")

    def _browse_dir(self):
        d = _dlg_dir(self, title="Choose backup folder")
        if d and "backup_dir" in self._vars:
            self._vars["backup_dir"].set(d)


# ---------------------------------------------------------------------------
# Profile editor dialog
# ---------------------------------------------------------------------------

class ProfileDialog(tk.Toplevel):
    def __init__(self, parent, profile: Optional[dict] = None, title: str = "Blog Profile"):
        super().__init__(parent)
        self.title(title)
        self.configure(bg=BG_MID)
        self.resizable(False, False)
        self.grab_set()
        self.result: Optional[dict] = None

        tmpl = profile_manager.new_profile_template()
        self._data = dict(tmpl)
        if profile:
            self._data.update(profile)

        self._vars = {}
        self._build()
        self.transient(parent)
        self.wait_visibility()
        self.lift()

    def _field(self, frame, row, label, key, show=""):
        tk.Label(frame, text=label, bg=BG_MID, fg=FG_DIM,
                 font=FONT_SMALL, anchor="w").grid(row=row, column=0, sticky="w", padx=(0, 8), pady=3)
        var = tk.StringVar(value=str(self._data.get(key, "")))
        entry = tk.Entry(frame, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                         insertbackground=ACCENT, relief="flat",
                         font=FONT_MONO, show=show, width=38)
        entry.grid(row=row, column=1, sticky="ew", pady=3)
        self._vars[key] = var

    def _check(self, frame, row, label, key):
        var = tk.BooleanVar(value=bool(self._data.get(key, True)))
        cb  = tk.Checkbutton(frame, text=label, variable=var,
                             bg=BG_MID, fg=FG_MAIN, selectcolor=BG_INPUT,
                             activebackground=BG_MID, font=FONT_BODY)
        cb.grid(row=row, column=0, columnspan=2, sticky="w", pady=3)
        self._vars[key] = var

    def _build(self):
        pad = {"padx": 20, "pady": 6}
        f   = tk.Frame(self, bg=BG_MID)
        f.pack(fill="both", expand=True, **pad)
        f.columnconfigure(1, weight=1)

        tk.Label(f, text="Site", bg=BG_MID, fg=ACCENT,
                 font=FONT_HEAD).grid(row=0, column=0, columnspan=2, sticky="w", pady=(0, 4))
        self._field(f, 1,  "Blog name",          "name")
        self._field(f, 2,  "Site URL",            "site_url")

        tk.Label(f, text="FTP", bg=BG_MID, fg=ACCENT,
                 font=FONT_HEAD).grid(row=3, column=0, columnspan=2, sticky="w", pady=(12, 4))
        self._field(f, 4,  "Host",                "ftp_host")
        self._field(f, 5,  "Port",                "ftp_port")
        self._field(f, 6,  "Username",            "ftp_user")
        self._field(f, 7,  "Password",            "ftp_pass", show="●")
        self._field(f, 8,  "Remote directory",    "ftp_remote_dir")
        self._check(f, 9,  "Use FTP_TLS",         "ftp_ssl")

        tk.Label(f, text="SnapSmack Admin", bg=BG_MID, fg=ACCENT,
                 font=FONT_HEAD).grid(row=10, column=0, columnspan=2, sticky="w", pady=(12, 4))
        self._field(f, 11, "Admin username",       "snap_admin_user")
        self._field(f, 12, "Admin password",       "snap_admin_pass", show="●")

        tk.Label(f, text="Cloud", bg=BG_MID, fg=ACCENT,
                 font=FONT_HEAD).grid(row=13, column=0, columnspan=3, sticky="w", pady=(12, 4))
        self._field(f, 14, "Provider", "cloud_provider")
        self._field(f, 15, "Creds override (optional)",     "cloud_credentials_file")
        tk.Button(f, text="Browse…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_SMALL, padx=8, pady=2,
                  command=self._browse_credentials).grid(row=15, column=2, padx=(4, 0), pady=3)
        self._field(f, 16, "Cloud folder ID",      "cloud_folder_id")

        tk.Label(f, text="Backup", bg=BG_MID, fg=ACCENT,
                 font=FONT_HEAD).grid(row=17, column=0, columnspan=3, sticky="w", pady=(12, 4))
        self._field(f, 18, "Local backup directory", "backup_dir")
        tk.Button(f, text="Browse…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_SMALL, padx=8, pady=2,
                  command=self._browse_backup_dir).grid(row=18, column=2, padx=(4, 0), pady=3)
        self._field(f, 19, "Pacing delay (sec)",    "pacing_delay")
        self._field(f, 20, "Batch size (0=unlimited)", "batch_size")

        # Buttons
        btn_frame = tk.Frame(self, bg=BG_MID)
        btn_frame.pack(fill="x", padx=20, pady=(0, 16))

        tk.Button(btn_frame, text="Cancel", bg=BG_CARD, fg=FG_DIM,
                  relief="flat", font=FONT_BODY,
                  command=self.destroy).pack(side="right", padx=(8, 0))
        tk.Button(btn_frame, text="Save", bg=ACCENT, fg=BG_DEEP,
                  relief="flat", font=FONT_HEAD,
                  command=self._save).pack(side="right")

    def _browse_credentials(self):
        p = _dlg_open(self, title="Select credentials JSON",
                      filetypes=[("JSON files", "*.json"), ("All files", "*.*")])
        if p and "cloud_credentials_file" in self._vars:
            self._vars["cloud_credentials_file"].set(p)

    def _browse_backup_dir(self):
        d = _dlg_dir(self, title="Choose local backup folder")
        if d and "backup_dir" in self._vars:
            self._vars["backup_dir"].set(d)

    def _save(self):
        name = self._vars["name"].get().strip()
        if not name:
            messagebox.showerror("Required", "Blog name is required.", parent=self)
            return
        data = dict(self._data)
        for key, var in self._vars.items():
            val = var.get()
            # Preserve int fields
            if key in ("ftp_port", "pacing_delay", "batch_size"):
                try:
                    val = type(self._data.get(key, 0))(val)
                except (ValueError, TypeError):
                    pass
            data[key] = val
        self.result = data
        self.destroy()


# ---------------------------------------------------------------------------
# Hub discovery dialog
# ---------------------------------------------------------------------------

class HubDiscoveryDialog(tk.Toplevel):
    """Dialog for connecting to a hub blog and discovering all its spokes."""

    def __init__(self, parent, app, title: str = "Discover from Hub"):
        super().__init__(parent)
        self.title(title)
        self.configure(bg=BG_MID)
        self.resizable(False, False)
        self.grab_set()
        self._app = app
        self.created_count = 0
        self._build()
        self.transient(parent)
        self.wait_visibility()
        self.lift()

    def _build(self):
        pad = {"padx": 20, "pady": 6}
        f = tk.Frame(self, bg=BG_MID)
        f.pack(fill="both", expand=True, **pad)
        f.columnconfigure(1, weight=1)

        tk.Label(f, text="Hub Connection", bg=BG_MID, fg=ACCENT,
                 font=FONT_HEAD).grid(row=0, column=0, columnspan=2, sticky="w", pady=(0, 8))

        tk.Label(f, text="Enter the hub blog's URL and admin credentials.\n"
                         "SUYB will discover all spokes and create profiles.",
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL,
                 justify="left").grid(row=1, column=0, columnspan=2, sticky="w", pady=(0, 8))

        self._vars = {}
        for row, (label, key, show) in enumerate([
            ("Hub URL",         "site_url",        ""),
            ("Admin username",  "admin_user",      ""),
            ("Admin password",  "admin_pass",      "●"),
        ], start=2):
            tk.Label(f, text=label, bg=BG_MID, fg=FG_DIM,
                     font=FONT_SMALL, anchor="w").grid(
                row=row, column=0, sticky="w", padx=(0, 12), pady=3)
            var = tk.StringVar()
            tk.Entry(f, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                     insertbackground=ACCENT, relief="flat",
                     font=FONT_MONO, show=show, width=38).grid(
                row=row, column=1, sticky="ew", pady=3)
            self._vars[key] = var

        # Pre-fill from current profile if available
        cp = self._app._current_profile
        if cp:
            self._vars["site_url"].set(cp.get("site_url", ""))
            self._vars["admin_user"].set(cp.get("snap_admin_user", ""))
            self._vars["admin_pass"].set(cp.get("snap_admin_pass", ""))

        # Backup directory for new profiles
        tk.Label(f, text="Backup base dir", bg=BG_MID, fg=FG_DIM,
                 font=FONT_SMALL, anchor="w").grid(
            row=5, column=0, sticky="w", padx=(0, 12), pady=3)
        self._dir_var = tk.StringVar()
        dir_row = tk.Frame(f, bg=BG_MID)
        dir_row.grid(row=5, column=1, sticky="ew", pady=3)
        tk.Entry(dir_row, textvariable=self._dir_var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=30).pack(side="left", fill="x", expand=True)
        tk.Button(dir_row, text="…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY,
                  command=self._browse_dir).pack(side="left", padx=(4, 0))

        # Status label
        self._status_var = tk.StringVar(value="")
        tk.Label(f, textvariable=self._status_var, bg=BG_MID, fg=FG_DIM,
                 font=FONT_SMALL, wraplength=400,
                 justify="left").grid(row=6, column=0, columnspan=2, sticky="w", pady=(8, 4))

        # Buttons
        btn_frame = tk.Frame(self, bg=BG_MID)
        btn_frame.pack(fill="x", padx=20, pady=(0, 16))

        tk.Button(btn_frame, text="Cancel", bg=BG_CARD, fg=FG_DIM,
                  relief="flat", font=FONT_BODY,
                  command=self.destroy).pack(side="right", padx=(8, 0))
        self._go_btn = tk.Button(
            btn_frame, text="Discover", bg=ACCENT, fg=BG_DEEP,
            relief="flat", font=FONT_HEAD,
            command=self._discover)
        self._go_btn.pack(side="right")

    def _browse_dir(self):
        d = filedialog.askdirectory(parent=self.winfo_toplevel(), title="Choose backup base directory")
        if d:
            self._dir_var.set(d)

    def _discover(self):
        url  = self._vars["site_url"].get().strip()
        user = self._vars["admin_user"].get().strip()
        pw   = self._vars["admin_pass"].get().strip()

        if not url or not user or not pw:
            messagebox.showerror("Required", "All three fields are required.", parent=self)
            return

        self._go_btn.configure(state="disabled")
        self._status_var.set("Connecting to hub…")
        self.update_idletasks()

        import threading
        threading.Thread(target=self._run_discovery,
                         args=(url, user, pw), daemon=True).start()

    def _run_discovery(self, url: str, user: str, pw: str):
        try:
            from hub_discovery import HubDiscovery, build_profiles_from_spokes

            disc = HubDiscovery(url, user, pw)
            self.after(0, lambda: self._status_var.set("Logged in. Fetching spoke list…"))

            hub_info, spokes = disc.discover_spokes()

            # Query each spoke for its backup config
            spoke_configs = {}
            for i, spoke in enumerate(spokes):
                spoke_url = spoke.get("site_url", "").rstrip("/")
                api_key   = spoke.get("api_key_remote", "")
                self.after(0, lambda s=spoke.get("site_name", "?"), n=i+1, t=len(spokes):
                           self._status_var.set(f"Querying spoke {n}/{t}: {s}…"))
                if spoke_url and api_key:
                    cfg = disc.fetch_spoke_backup_config(spoke_url, api_key)
                    if cfg:
                        spoke_configs[spoke_url] = cfg

            disc.close()

            # Build profile dicts
            base_dir = self._dir_var.get().strip()
            profiles = build_profiles_from_spokes(
                hub_info, spokes, spoke_configs, base_dir,
            )

            # Also populate hub profile with admin credentials
            if profiles:
                profiles[0]["snap_admin_user"] = user
                profiles[0]["snap_admin_pass"] = pw

            self.after(0, lambda: self._on_discovery_done(profiles))

        except Exception as e:
            self.after(0, lambda: self._on_discovery_error(str(e)))

    def _on_discovery_done(self, profiles: list):
        if not profiles:
            self._status_var.set("No blogs found.")
            self._go_btn.configure(state="normal")
            return

        # Check for existing profiles to avoid duplicates
        existing = set(profile_manager.list_profiles())
        created = 0
        skipped = 0

        for p in profiles:
            name = p.get("name", "")
            if name in existing:
                skipped += 1
                continue
            profile_manager.save_profile(p)
            created += 1
            existing.add(name)

        self.created_count = created
        msg = f"Done! Created {created} profile(s)."
        if skipped:
            msg += f" Skipped {skipped} existing."
        self._status_var.set(msg)
        self._go_btn.configure(state="normal")

        if created > 0:
            messagebox.showinfo(
                "Discovery complete",
                f"{msg}\n\nYou'll still need to enter FTP credentials and backup\n"
                "directories for each spoke if they weren't auto-populated.",
                parent=self.winfo_toplevel(),
            )

    def _on_discovery_error(self, err: str):
        self._status_var.set(f"Error: {err}")
        self._go_btn.configure(state="normal")


# ---------------------------------------------------------------------------
# Log widget
# ---------------------------------------------------------------------------

class LogPane(tk.Frame):
    def __init__(self, parent, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._text = tk.Text(self, bg=BG_DEEP, fg=FG_DIM, font=FONT_MONO,
                             state="disabled", relief="flat", wrap="word",
                             height=6, bd=0)
        self._sb = ttk.Scrollbar(self, command=self._text.yview)
        self._text.configure(yscrollcommand=self._on_scroll)
        # Scrollbar hidden until there's content to scroll
        self._text.pack(side="left", fill="both", expand=True)

    def _on_scroll(self, first, last):
        """Show scrollbar only when content overflows."""
        self._sb.set(first, last)
        if float(first) <= 0.0 and float(last) >= 1.0:
            self._sb.pack_forget()
        else:
            self._sb.pack(side="right", fill="y")

    def append(self, msg: str) -> None:
        self._text.configure(state="normal")
        self._text.insert("end", msg + "\n")
        self._text.see("end")
        self._text.configure(state="disabled")

    def clear(self) -> None:
        self._text.configure(state="normal")
        self._text.delete("1.0", "end")
        self._text.configure(state="disabled")


# ---------------------------------------------------------------------------
# Progress bar widget
# ---------------------------------------------------------------------------

class ProgressBar(tk.Frame):
    def __init__(self, parent, **kwargs):
        super().__init__(parent, bg=BG_MID, height=14, **kwargs)
        self._fill = tk.Frame(self, bg=ACCENT, height=14)
        self._fill.place(x=0, y=0, relheight=1.0, relwidth=0)
        self._pct = 0.0

    def set(self, pct: float) -> None:
        self._pct = max(0.0, min(1.0, pct))
        self._fill.place(relwidth=self._pct)

    def reset(self) -> None:
        self.set(0.0)


# ---------------------------------------------------------------------------
# Backup tab
# ---------------------------------------------------------------------------

class BackupTab(tk.Frame):
    def __init__(self, parent, app, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._app         = app
        self._busy        = False
        self._engine: Optional[BackupEngine] = None
        self._all_queue: list   = []
        self._all_results: list = []
        self._start_time  = None   # time.monotonic() when backup started
        self._tick_job    = None   # after() job id for the clock ticker
        self._last_pct    = 0.0   # last known progress pct for ETA
        self._build()

    def _build(self):
        # Status card
        card = tk.Frame(self, bg=BG_MID, padx=20, pady=16)
        card.pack(fill="x", padx=16, pady=(16, 8))

        self._last_backup_lbl = tk.Label(card, text="Last backup: —",
                                          bg=BG_MID, fg=FG_DIM, font=FONT_BODY, anchor="w")
        self._last_backup_lbl.pack(fill="x")
        self._file_count_lbl = tk.Label(card, text="",
                                         bg=BG_MID, fg=FG_DIM, font=FONT_SMALL, anchor="w")
        self._file_count_lbl.pack(fill="x")

        # Progress bar
        self._prog_bar = ProgressBar(self)
        self._prog_bar.pack(fill="x", padx=16, pady=(0, 4))

        # Stats row: current file | files counter | bytes | elapsed | ETA
        stats_row = tk.Frame(self, bg=BG_DEEP)
        stats_row.pack(fill="x", padx=16, pady=(0, 2))

        self._prog_lbl = tk.Label(stats_row, text="", bg=BG_DEEP, fg=FG_DIM,
                                   font=FONT_SMALL, anchor="w")
        self._prog_lbl.pack(side="left", fill="x", expand=True)

        self._time_lbl = tk.Label(stats_row, text="", bg=BG_DEEP, fg=FG_DIM,
                                   font=FONT_SMALL, anchor="e")
        self._time_lbl.pack(side="right")

        # File/byte counts row
        counts_row = tk.Frame(self, bg=BG_DEEP)
        counts_row.pack(fill="x", padx=16, pady=(0, 4))

        self._files_lbl = tk.Label(counts_row, text="", bg=BG_DEEP, fg=FG_DIM,
                                    font=FONT_SMALL, anchor="w")
        self._files_lbl.pack(side="left")

        self._bytes_lbl = tk.Label(counts_row, text="", bg=BG_DEEP, fg=FG_DIM,
                                    font=FONT_SMALL, anchor="e")
        self._bytes_lbl.pack(side="right")

        # Pack from bottom: buttons first (lowest), then options above them
        btn_row = tk.Frame(self, bg=BG_DEEP)
        btn_row.pack(fill="x", padx=16, pady=(0, 16), side="bottom")

        opts_row = tk.Frame(self, bg=BG_DEEP)
        opts_row.pack(fill="x", padx=16, pady=(4, 4), side="bottom")

        # Log fills remaining space
        self._log = LogPane(self)
        self._log.pack(fill="both", expand=True, padx=16, pady=8)

        # Populate the options row
        self._backup_mode_var = tk.StringVar(value="differential")
        for val, label in [
            ("differential", "Differential — skip unchanged files"),
            ("full",         "Full — re-download everything"),
        ]:
            tk.Radiobutton(
                opts_row, text=label, variable=self._backup_mode_var, value=val,
                bg=BG_DEEP, fg=FG_MAIN, selectcolor=BG_INPUT,
                activebackground=BG_DEEP, font=FONT_BODY,
            ).pack(side="left", padx=(0, 16))

        self._include_settings_var = tk.BooleanVar(value=True)
        tk.Checkbutton(
            opts_row, text="Include SUYB settings", variable=self._include_settings_var,
            bg=BG_DEEP, fg=FG_MAIN, selectcolor=BG_INPUT,
            activebackground=BG_DEEP, font=FONT_BODY,
        ).pack(side="left", padx=(16, 0))

        # Populate the button row
        self._start_btn = tk.Button(
            btn_row, text="▶  START BACKUP",
            bg=ACCENT, fg=BG_DEEP, font=FONT_HEAD, relief="flat",
            padx=20, pady=8, cursor="hand2",
            command=self._start,
        )
        self._start_btn.pack(side="left")
        self._all_btn = tk.Button(
            btn_row, text="▶  BACKUP ALL BLOGS",
            bg=BG_CARD, fg=ACCENT, font=FONT_HEAD, relief="flat",
            padx=20, pady=8, cursor="hand2",
            command=self._start_all,
        )
        self._all_btn.pack(side="left", padx=(10, 0))
        self._cancel_btn = tk.Button(
            btn_row, text="Cancel", bg=BG_CARD, fg=FG_DIM,
            font=FONT_BODY, relief="flat", padx=12, pady=8,
            state="disabled", command=self._cancel,
        )
        self._cancel_btn.pack(side="left", padx=(8, 0))

    def refresh(self, profile: Optional[dict]) -> None:
        if profile:
            dt = profile.get("last_backup_date", "") or "Never"
            self._last_backup_lbl.configure(text=f"Last backup:  {dt}")
            self._file_count_lbl.configure(text=f"Blog: {profile.get('site_url', '')}")
        else:
            self._last_backup_lbl.configure(text="Last backup: —")
            self._file_count_lbl.configure(text="")

    def _global_config_dict(self) -> dict:
        """Return global config as a plain dict for bundling into backups."""
        cfg = self._app._cfg
        return {section: dict(cfg[section]) for section in cfg.sections()}

    def _start(self):
        profile = self._app.current_profile()
        if not profile:
            messagebox.showinfo("No profile", "Select or create a blog profile first.")
            return
        if not profile.get("backup_dir"):
            messagebox.showerror("No backup dir", "Set a local backup directory in the profile.")
            return

        # ── Pre-flight: validate cloud config if method is cloud ──────
        if profile.get("backup_method") == "cloud":
            import cloud_client as _cc
            gc = self._app.global_cloud_config()
            test_client = _cc.get_cloud_client(profile, global_cloud=gc)
            if not test_client:
                def _pick(*vals):
                    for v in vals:
                        if v and v != "none":
                            return v
                    return ""
                provider = _pick(profile.get("cloud_provider"), gc.get("cloud_provider")) or "none"
                if provider in ("google_drive", "onedrive"):
                    msg = (f"Cloud provider is set to '{provider}' but no credentials "
                           f"file is configured.\n\nGo to Settings → Global Cloud Config, "
                           f"set the Credentials JSON and click Save Defaults.\n\n"
                           f"Continue with local-only backup instead?")
                else:
                    msg = ("No cloud provider is configured.\n\n"
                           "Go to Settings → Global Cloud Config and set a provider.\n\n"
                           "Continue with local-only backup instead?")
                if not messagebox.askyesno("Cloud not configured", msg):
                    return

        # ── Check for an interrupted backup checkpoint ────────────────
        from checkpoint import BackupCheckpoint
        backup_dir = profile.get("backup_dir", "")
        blog_name  = profile.get("name", "blog")
        resume_cp  = BackupCheckpoint.load(backup_dir, blog_name)

        if resume_cp:
            import datetime as _dt
            created = resume_cp.data.get("created_at", "")[:16].replace("T", " ")
            done    = resume_cp.data.get("files_downloaded", 0)
            skipped = resume_cp.data.get("files_skipped", 0)
            answer  = messagebox.askyesnocancel(
                "Interrupted backup found",
                f"A backup of '{blog_name}' was interrupted on {created}.\n\n"
                f"  Downloaded so far: {done} files\n"
                f"  Skipped (unchanged): {skipped} files\n\n"
                "Yes  — Resume from where it stopped\n"
                "No   — Delete checkpoint and start fresh\n"
                "Cancel — Do nothing",
            )
            if answer is None:
                return
            if answer is False:
                resume_cp.delete()
                resume_cp = None
            # answer is True → resume using the checkpoint
        else:
            resume_cp = None

        self._busy = True
        self._start_btn.configure(state="disabled")
        self._all_btn.configure(state="disabled")
        self._cancel_btn.configure(state="normal")
        self._prog_bar.reset()
        self._files_lbl.configure(text="")
        self._bytes_lbl.configure(text="")
        self._time_lbl.configure(text="")
        self._log.clear()
        self._log.append("Resuming backup…" if resume_cp else "Starting backup…")
        self._start_clock()

        force_full       = self._backup_mode_var.get() == "full"
        include_settings = self._include_settings_var.get()
        self._engine = BackupEngine(
            profile,
            on_progress=lambda s, m, p: self._app.queue_msg(("backup_progress", m, p)),
            on_log=lambda m: self._app.queue_msg(("backup_log", m)),
            on_ask=lambda msg: self._app.queue_msg(("backup_ask", msg)),
            on_stats=lambda *a: self._app.queue_msg(("backup_stats",) + a),
            force_full=force_full,
            include_settings=include_settings,
            global_config=self._global_config_dict() if include_settings else None,
            global_cloud=self._app.global_cloud_config(),
            resume_checkpoint=resume_cp,
        )
        self._all_queue = []
        t = threading.Thread(target=self._run_engine, daemon=True)
        t.start()

    def _start_all(self):
        """Queue backups for every profile, one after the other."""
        profiles = self._app._profiles
        if not profiles:
            messagebox.showinfo("No profiles", "Create at least one blog profile first.")
            return

        # Load all profiles and check they have backup dirs
        loaded = []
        missing = []
        for name in profiles:
            p = profile_manager.load_profile(name)
            if p and p.get("backup_dir"):
                loaded.append(p)
            elif p:
                missing.append(p.get("name", name))

        if missing:
            msg = "These profiles have no backup directory and will be skipped:\n• " + "\n• ".join(missing)
            if not loaded:
                messagebox.showerror("No valid profiles", msg)
                return
            messagebox.showwarning("Skipping profiles", msg)

        if not loaded:
            return

        self._busy = True
        self._start_btn.configure(state="disabled")
        self._all_btn.configure(state="disabled")
        self._cancel_btn.configure(state="normal")
        self._prog_bar.reset()
        self._files_lbl.configure(text="")
        self._bytes_lbl.configure(text="")
        self._time_lbl.configure(text="")
        self._log.clear()
        self._log.append(f"Backing up {len(loaded)} blog(s)…")
        self._start_clock()

        self._all_queue = loaded
        self._all_results = []
        self._run_next_in_queue()

    def _run_next_in_queue(self):
        """Pop the next profile from the all-blogs queue and start its backup."""
        if not self._all_queue or self._cancelled():
            self._finish_all()
            return

        profile = self._all_queue.pop(0)
        force_full       = self._backup_mode_var.get() == "full"
        include_settings = self._include_settings_var.get()

        self._log.append(f"\n── {profile['name']} ──")
        self._engine = BackupEngine(
            profile,
            on_progress=lambda s, m, p: self._app.queue_msg(("backup_progress", m, p)),
            on_log=lambda m: self._app.queue_msg(("backup_log", m)),
            on_ask=lambda msg: self._app.queue_msg(("backup_ask", msg)),
            on_stats=lambda *a: self._app.queue_msg(("backup_stats",) + a),
            force_full=force_full,
            include_settings=include_settings,
            global_config=self._global_config_dict() if include_settings else None,
            global_cloud=self._app.global_cloud_config(),
        )
        t = threading.Thread(target=self._run_engine_queued, args=(profile,), daemon=True)
        t.start()

    def _run_engine_queued(self, profile):
        result = self._engine.run()
        result["_profile_name"] = profile.get("name", "")
        self._app.queue_msg(("backup_done_queued", result))

    def _cancelled(self) -> bool:
        return self._engine and self._engine._cancelled

    def _finish_all(self):
        self._stop_clock()
        ok    = sum(1 for r in self._all_results if r.get("success"))
        total = len(self._all_results)
        elapsed = (time.monotonic() - self._start_time) if self._start_time else 0
        self._time_lbl.configure(text=f"Elapsed: {self._fmt_time(elapsed)}")
        self._log.append(f"\n{'─' * 40}")
        self._log.append(f"All blogs done: {ok}/{total} succeeded.")
        self._busy = False
        self._start_btn.configure(state="normal")
        self._all_btn.configure(state="normal")
        self._cancel_btn.configure(state="disabled")
        self._engine = None

    def _run_engine(self):
        result = self._engine.run()
        self._app.queue_msg(("backup_done", result))

    def _start_scheduled(self, profile: dict) -> None:
        """Called by the scheduler — runs a silent differential backup for profile."""
        if self._busy:
            return  # backup already running, skip this tick
        self._busy = True
        self._start_btn.configure(state="disabled")
        self._all_btn.configure(state="disabled")
        self._cancel_btn.configure(state="normal")
        self._prog_bar.reset()
        self._log.clear()
        self._log.append(f"Scheduled backup starting: {profile.get('name', '')}…")

        self._engine = BackupEngine(
            profile,
            on_progress=lambda s, m, p: self._app.queue_msg(("backup_progress", m, p)),
            on_log=lambda m: self._app.queue_msg(("backup_log", m)),
            force_full=False,
            include_settings=True,
            global_config=None,
            global_cloud=self._app.global_cloud_config(),
        )
        import threading
        threading.Thread(target=self._run_engine, daemon=True).start()

    def _cancel(self):
        if self._engine:
            self._engine.cancel()
        self._cancel_btn.configure(state="disabled")

    @staticmethod
    def _fmt_bytes(n: int) -> str:
        if n >= 1_073_741_824:
            return f"{n / 1_073_741_824:.1f} GB"
        if n >= 1_048_576:
            return f"{n / 1_048_576:.1f} MB"
        if n >= 1024:
            return f"{n / 1024:.0f} KB"
        return f"{n} B"

    @staticmethod
    def _fmt_time(secs: float) -> str:
        secs = int(secs)
        h, rem = divmod(secs, 3600)
        m, s   = divmod(rem, 60)
        if h:
            return f"{h}:{m:02d}:{s:02d}"
        return f"{m}:{s:02d}"

    def _start_clock(self):
        self._start_time = time.monotonic()
        self._tick()

    def _stop_clock(self):
        if self._tick_job:
            self.after_cancel(self._tick_job)
            self._tick_job = None

    def _tick(self):
        if not self._busy or self._start_time is None:
            return
        elapsed = time.monotonic() - self._start_time
        pct = self._last_pct
        if pct > 0.01:
            eta = elapsed / pct * (1.0 - pct)
            self._time_lbl.configure(
                text=f"Elapsed: {self._fmt_time(elapsed)}   ETA: {self._fmt_time(eta)}"
            )
        else:
            self._time_lbl.configure(text=f"Elapsed: {self._fmt_time(elapsed)}")
        self._tick_job = self.after(1000, self._tick)

    def on_stats(self, files_done: int, files_total: int, files_failed: int,
                 bytes_done: int, bytes_total: int, bytes_failed: int) -> None:
        files_ok = files_done - files_failed
        self._files_lbl.configure(
            text=f"Files: {files_done} / {files_total}   ✓ {files_ok}   ✗ {files_failed}"
        )
        if bytes_total > 0:
            self._bytes_lbl.configure(
                text=f"{self._fmt_bytes(bytes_done)} / {self._fmt_bytes(bytes_total)}"
            )

    def on_progress(self, msg: str, pct: float) -> None:
        self._last_pct = pct
        self._prog_bar.set(pct)
        self._prog_lbl.configure(text=msg, fg=FG_WARN if pct < 1.0 else FG_OK)

    def on_log(self, msg: str) -> None:
        self._log.append(msg)

    def on_done(self, result: dict) -> None:
        self._stop_clock()
        self._busy = False
        self._start_btn.configure(state="normal")
        self._all_btn.configure(state="normal")
        self._cancel_btn.configure(state="disabled")
        self._engine = None
        elapsed = (time.monotonic() - self._start_time) if self._start_time else 0
        self._time_lbl.configure(text=f"Elapsed: {self._fmt_time(elapsed)}")
        if result.get("cancelled"):
            self._prog_lbl.configure(text="Backup cancelled.", fg=FG_WARN)
            self._log.append(
                f"↩ Cancelled — {result['files_downloaded']} downloaded, "
                f"{result['files_skipped']} skipped, {result['files_failed']} failed."
            )
        elif result["success"]:
            self._prog_bar.set(1.0)
            self._prog_lbl.configure(text="Backup complete.", fg=FG_OK)
            self._log.append(
                f"✓ Done — {result['files_downloaded']} downloaded, "
                f"{result['files_skipped']} skipped, {result['files_failed']} failed."
            )
        else:
            self._prog_lbl.configure(text="Backup failed.", fg=FG_ERR)
            for err in result.get("errors", []):
                self._log.append(f"✗ {err}")

    def on_done_queued(self, result: dict) -> None:
        """Handle completion of one blog in a multi-blog run."""
        name = result.get("_profile_name", "?")
        self._all_results.append(result)
        if result["success"]:
            self._log.append(
                f"✓ {name} — {result['files_downloaded']} downloaded, "
                f"{result['files_skipped']} skipped."
            )
        else:
            self._log.append(f"✗ {name} — failed: {'; '.join(result.get('errors', []))}")

        # Kick off the next blog or finish
        if self._all_queue:
            self._run_next_in_queue()
        else:
            self._finish_all()


# ---------------------------------------------------------------------------
# Restore tab
# ---------------------------------------------------------------------------

class RestoreTab(tk.Frame):
    def __init__(self, parent, app, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._app    = app
        self._busy   = False
        self._engine: Optional[RestoreEngine] = None
        self._build()

    def _build(self):
        src_card = tk.Frame(self, bg=BG_MID, padx=16, pady=12)
        src_card.pack(fill="x", padx=16, pady=(16, 8))

        tk.Label(src_card, text="RESTORE SOURCE", bg=BG_MID, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w", pady=(0, 8))

        self._source_var = tk.StringVar(value="local")
        for val, label in [("local", "Local backup package (.zip)"),
                            ("cloud", "Browse cloud"),
                            ("manual", "Recovery kit + media folder")]:
            tk.Radiobutton(
                src_card, text=label, variable=self._source_var, value=val,
                bg=BG_MID, fg=FG_MAIN, selectcolor=BG_INPUT,
                activebackground=BG_MID, font=FONT_BODY,
                command=self._on_source_change,
            ).pack(anchor="w")

        # Local ZIP picker
        self._local_frame = tk.Frame(self, bg=BG_DEEP)
        self._local_frame.pack(fill="x", padx=16, pady=4)
        self._zip_var = tk.StringVar()
        tk.Entry(self._local_frame, textvariable=self._zip_var, bg=BG_INPUT,
                 fg=FG_MAIN, insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO).pack(side="left", fill="x", expand=True)
        tk.Button(self._local_frame, text="Browse…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, command=self._browse_zip).pack(side="left", padx=(6, 0))

        # Cloud browser frame
        self._cloud_frame = tk.Frame(self, bg=BG_DEEP)
        tk.Button(self._cloud_frame, text="Browse Cloud…", bg=BG_CARD,
                  fg=FG_MAIN, relief="flat", font=FONT_BODY,
                  command=self._browse_cloud).pack(side="left", padx=16, pady=4)
        self._cloud_sel_lbl = tk.Label(self._cloud_frame, text="No package selected",
                                        bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL)
        self._cloud_sel_lbl.pack(side="left")
        self._cloud_file_id  = ""
        self._cloud_file_name= ""

        # Manual kit + folder
        self._manual_frame = tk.Frame(self, bg=BG_DEEP, padx=16)
        self._kit_var    = tk.StringVar()
        self._mdir_var   = tk.StringVar()
        for label, var, cmd in [
            ("Recovery kit (.tar.gz)", self._kit_var,  self._browse_kit),
            ("Media folder",           self._mdir_var, self._browse_media_dir),
        ]:
            row = tk.Frame(self._manual_frame, bg=BG_DEEP)
            row.pack(fill="x", pady=2)
            tk.Label(row, text=label, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_SMALL, width=22, anchor="w").pack(side="left")
            tk.Entry(row, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                     insertbackground=ACCENT, relief="flat",
                     font=FONT_MONO).pack(side="left", fill="x", expand=True)
            tk.Button(row, text="…", bg=BG_CARD, fg=FG_MAIN,
                      relief="flat", font=FONT_BODY,
                      command=cmd).pack(side="left", padx=(4, 0))

        # Progress + log
        self._prog_bar = ProgressBar(self)
        self._prog_bar.pack(fill="x", padx=16, pady=(8, 2))
        self._prog_lbl = tk.Label(self, text="", bg=BG_DEEP, fg=FG_DIM,
                                   font=FONT_SMALL, anchor="w")
        self._prog_lbl.pack(fill="x", padx=16)
        self._log = LogPane(self)
        self._log.pack(fill="both", expand=True, padx=16, pady=8)

        # Start button
        btn_row = tk.Frame(self, bg=BG_DEEP)
        btn_row.pack(fill="x", padx=16, pady=(0, 16))
        self._start_btn = tk.Button(
            btn_row, text="▶  START RESTORE",
            bg=ACCENT, fg=BG_DEEP, font=FONT_HEAD, relief="flat",
            padx=20, pady=8, cursor="hand2", command=self._start,
        )
        self._start_btn.pack(side="left")
        self._cancel_btn = tk.Button(
            btn_row, text="Cancel", bg=BG_CARD, fg=FG_DIM,
            font=FONT_BODY, relief="flat", padx=12, pady=8,
            state="disabled", command=self._cancel,
        )
        self._cancel_btn.pack(side="left", padx=(8, 0))

        self._on_source_change()

    def _on_source_change(self):
        src = self._source_var.get()
        self._local_frame.pack_forget()
        self._cloud_frame.pack_forget()
        self._manual_frame.pack_forget()
        if src == "local":
            self._local_frame.pack(fill="x", padx=16, pady=4)
        elif src == "cloud":
            self._cloud_frame.pack(fill="x", pady=4)
        else:
            self._manual_frame.pack(fill="x", pady=4)

    def _browse_zip(self):
        p = _dlg_open(self,
            title="Select backup package",
            filetypes=[("ZIP backup", "*.zip"), ("All files", "*.*")],
        )
        if p:
            self._zip_var.set(p)

    def _browse_kit(self):
        p = _dlg_open(self,
            title="Select recovery kit",
            filetypes=[("Recovery kit", "*.tar.gz"), ("All files", "*.*")],
        )
        if p:
            self._kit_var.set(p)

    def _browse_media_dir(self):
        d = _dlg_dir(self, title="Select media folder")
        if d:
            self._mdir_var.set(d)

    def _browse_cloud(self):
        profile = self._app.current_profile()
        if not profile:
            messagebox.showinfo("No profile", "Select a blog profile first.")
            return
        cloud = cloud_module.get_cloud_client(profile, global_cloud=self._app.global_cloud_config())
        if not cloud:
            messagebox.showerror("No cloud", "No cloud provider configured for this profile or global settings.")
            return
        backups = cloud_manifest_module.list_available_backups(cloud)
        if not backups:
            messagebox.showinfo("No backups", "No backup ZIPs found in the configured cloud folder.")
            return
        CloudBrowserDialog(self, backups, self._on_cloud_selected)

    def _on_cloud_selected(self, file_id: str, name: str):
        self._cloud_file_id   = file_id
        self._cloud_file_name = name
        self._cloud_sel_lbl.configure(text=name, fg=FG_MAIN)

    def _start(self):
        profile = self._app.current_profile()
        if not profile:
            messagebox.showinfo("No profile", "Select a blog profile first.")
            return

        src = self._source_var.get()
        self._busy = True
        self._start_btn.configure(state="disabled")
        self._cancel_btn.configure(state="normal")
        self._prog_bar.reset()
        self._log.clear()

        self._engine = RestoreEngine(
            profile,
            on_progress=lambda s, m, p: self._app.queue_msg(("restore_progress", m, p)),
            on_log=lambda m: self._app.queue_msg(("restore_log", m)),
            global_cloud=self._app.global_cloud_config(),
        )

        if src == "local":
            zip_path = self._zip_var.get().strip()
            if not zip_path or not os.path.exists(zip_path):
                messagebox.showerror("Missing file", "Select a valid backup package ZIP.")
                self._reset_buttons()
                return
            t = threading.Thread(target=lambda: self._app.queue_msg(
                ("restore_done", self._engine.restore_from_zip(zip_path))), daemon=True)

        elif src == "cloud":
            if not self._cloud_file_id:
                messagebox.showerror("No package", "Browse cloud and select a backup package.")
                self._reset_buttons()
                return
            backup_dir = profile.get("backup_dir", "") or os.path.expanduser("~")
            t = threading.Thread(target=lambda: self._app.queue_msg(
                ("restore_done", self._engine.restore_from_cloud(
                    self._cloud_file_id, backup_dir))), daemon=True)

        else:  # manual
            kit  = self._kit_var.get().strip()
            mdir = self._mdir_var.get().strip()
            if not kit or not os.path.exists(kit):
                messagebox.showerror("Missing file", "Select a valid recovery kit (.tar.gz).")
                self._reset_buttons()
                return
            if not mdir or not os.path.isdir(mdir):
                messagebox.showerror("Missing folder", "Select a valid media folder.")
                self._reset_buttons()
                return
            t = threading.Thread(target=lambda: self._app.queue_msg(
                ("restore_done", self._engine.restore_from_kit(kit, mdir))), daemon=True)

        t.start()

    def _cancel(self):
        if self._engine:
            self._engine.cancel()
        self._cancel_btn.configure(state="disabled")

    def _reset_buttons(self):
        self._busy = False
        self._start_btn.configure(state="normal")
        self._cancel_btn.configure(state="disabled")

    def on_progress(self, msg: str, pct: float) -> None:
        self._prog_bar.set(pct)
        self._prog_lbl.configure(text=msg, fg=FG_WARN if pct < 1.0 else FG_OK)

    def on_log(self, msg: str) -> None:
        self._log.append(msg)

    def on_done(self, result: dict) -> None:
        self._reset_buttons()
        self._engine = None
        if result.get("success"):
            self._prog_bar.set(1.0)
            self._prog_lbl.configure(text="Restore complete.", fg=FG_OK)
            self._log.append(
                f"✓ Done — {result['uploaded']} uploaded, "
                f"{result['skipped']} skipped, {result['failed']} failed."
            )
        else:
            self._prog_lbl.configure(text="Restore failed.", fg=FG_ERR)
            for err in result.get("errors", [])[:10]:
                self._log.append(f"✗ {err}")


# ---------------------------------------------------------------------------
# Cloud browser dialog
# ---------------------------------------------------------------------------

class CloudBrowserDialog(tk.Toplevel):
    def __init__(self, parent, backups: list, callback):
        super().__init__(parent)
        self.title("Cloud Backups")
        self.configure(bg=BG_MID)
        self.resizable(True, False)
        self.grab_set()
        self._callback = callback
        self._backups  = backups
        self._sel_id   = ""
        self._sel_name = ""
        self._build()
        self.transient(parent)

    def _build(self):
        tk.Label(self, text="Select a backup package to restore:",
                 bg=BG_MID, fg=FG_MAIN, font=FONT_BODY).pack(anchor="w", padx=16, pady=(12, 4))

        lb_frame = tk.Frame(self, bg=BG_MID)
        lb_frame.pack(fill="both", expand=True, padx=16, pady=4)
        sb = ttk.Scrollbar(lb_frame)
        sb.pack(side="right", fill="y")
        self._lb = tk.Listbox(lb_frame, bg=BG_INPUT, fg=FG_MAIN,
                              selectbackground=BG_CARD, selectforeground=ACCENT,
                              font=FONT_MONO, relief="flat",
                              yscrollcommand=sb.set, height=12)
        sb.configure(command=self._lb.yview)
        self._lb.pack(side="left", fill="both", expand=True)

        for b in self._backups:
            size_mb = b.get("size_bytes", 0) / 1048576
            self._lb.insert("end", f"{b['name']}  ({size_mb:.1f} MB)  {b.get('date','')[:10]}")

        btn_row = tk.Frame(self, bg=BG_MID)
        btn_row.pack(fill="x", padx=16, pady=12)
        tk.Button(btn_row, text="Cancel", bg=BG_CARD, fg=FG_DIM,
                  relief="flat", font=FONT_BODY,
                  command=self.destroy).pack(side="right", padx=(8, 0))
        tk.Button(btn_row, text="Select", bg=ACCENT, fg=BG_DEEP,
                  relief="flat", font=FONT_HEAD,
                  command=self._select).pack(side="right")

    def _select(self):
        sel = self._lb.curselection()
        if not sel:
            return
        idx = sel[0]
        b   = self._backups[idx]
        self._callback(b["id"], b["name"])
        self.destroy()


# ---------------------------------------------------------------------------
# Audit tab
# ---------------------------------------------------------------------------

class AuditTab(tk.Frame):
    def __init__(self, parent, app, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._app    = app
        self._report: Optional[AuditReport] = None
        self._build()

    def _build(self):
        ctrl = tk.Frame(self, bg=BG_DEEP)
        ctrl.pack(fill="x", padx=16, pady=(16, 8))

        self._run_btn = tk.Button(
            ctrl, text="▶  RUN AUDIT",
            bg=ACCENT, fg=BG_DEEP, font=FONT_HEAD, relief="flat",
            padx=16, pady=6, cursor="hand2", command=self._run,
        )
        self._run_btn.pack(side="left")
        tk.Button(ctrl, text="Save .txt", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=10, pady=6,
                  command=lambda: self._save("txt")).pack(side="left", padx=(8, 0))
        tk.Button(ctrl, text="Save .html", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=10, pady=6,
                  command=lambda: self._save("html")).pack(side="left", padx=(4, 0))
        tk.Label(ctrl,
                 text="Compares manifest vs server filesystem vs database.  "
                      "Uses SHA-256 checksums from the manifest to detect size mismatches.",
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL).pack(
            side="left", padx=(16, 0))

        self._prog_bar = ProgressBar(self)
        self._prog_bar.pack(fill="x", padx=16, pady=(0, 2))
        self._status_lbl = tk.Label(self, text="", bg=BG_DEEP, fg=FG_DIM,
                                     font=FONT_SMALL, anchor="w")
        self._status_lbl.pack(fill="x", padx=16)

        # Results area
        self._results = tk.Text(self, bg=BG_MID, fg=FG_MAIN, font=FONT_MONO,
                                 state="disabled", relief="flat", wrap="none",
                                 padx=12, pady=8)
        sb_y = ttk.Scrollbar(self, command=self._results.yview)
        sb_x = ttk.Scrollbar(self, orient="horizontal", command=self._results.xview)
        self._results.configure(yscrollcommand=sb_y.set, xscrollcommand=sb_x.set)
        sb_y.pack(side="right", fill="y")
        sb_x.pack(side="bottom", fill="x")
        self._results.pack(fill="both", expand=True, padx=16, pady=8)

        # Tag colours
        self._results.tag_configure("ok",      foreground=FG_OK)
        self._results.tag_configure("err",     foreground=FG_ERR)
        self._results.tag_configure("warn",    foreground=FG_WARN)
        self._results.tag_configure("dim",     foreground=FG_DIM)
        self._results.tag_configure("heading", foreground=ACCENT, font=FONT_HEAD)

    def _run(self):
        profile = self._app.current_profile()
        if not profile:
            messagebox.showinfo("No profile", "Select a blog profile first.")
            return

        # Need a manifest — try to find the last recovery kit
        backup_dir = profile.get("backup_dir", "")
        kit_path   = self._find_latest_kit(backup_dir, profile.get("name", ""))
        if not kit_path:
            messagebox.showerror(
                "No manifest",
                "No recovery kit found in the backup directory.\n"
                "Run a backup first, or select a kit manually.",
            )
            return

        try:
            manifest = manifest_reader.from_tar(kit_path)
        except Exception as e:
            messagebox.showerror("Manifest error", str(e))
            return

        self._run_btn.configure(state="disabled")
        self._prog_bar.reset()
        self._clear_results()

        engine = AuditEngine(
            profile, manifest,
            on_progress=lambda s, m, p: self._app.queue_msg(("audit_progress", m, p)),
            on_log=lambda m: self._app.queue_msg(("audit_log", m)),
        )
        t = threading.Thread(
            target=lambda: self._app.queue_msg(("audit_done", engine.run())),
            daemon=True,
        )
        t.start()

    def _find_latest_kit(self, backup_dir: str, blog_name: str) -> Optional[str]:
        if not backup_dir or not os.path.isdir(backup_dir):
            return None
        kits = sorted(
            [f for f in os.listdir(backup_dir)
             if f.endswith(".tar.gz") and blog_name.replace(" ", "_") in f],
            reverse=True,
        )
        return os.path.join(backup_dir, kits[0]) if kits else None

    def on_progress(self, msg: str, pct: float) -> None:
        self._prog_bar.set(pct)
        self._status_lbl.configure(text=msg, fg=FG_WARN if pct < 1.0 else FG_OK)

    def on_done(self, report: AuditReport) -> None:
        self._report = report
        self._run_btn.configure(state="normal")
        self._prog_bar.set(1.0)
        self._status_lbl.configure(text="Audit complete.", fg=FG_OK)
        self._render_report(report)

    def _render_report(self, report: AuditReport) -> None:
        self._clear_results()
        t = self._results
        t.configure(state="normal")

        def line(text, tag=""):
            t.insert("end", text + "\n", tag)

        line(f"AUDIT REPORT  —  {report.site_name}", "heading")
        line(f"{report.site_url}  ·  {report.audit_date}", "dim")
        line("")

        from audit_engine import (HEALTHY, MISSING_FROM_SERVER, ORPHANED_ON_SERVER,
                                  ORPHANED_IN_DB, NOT_IN_DB, SIZE_MISMATCH, WRONG_LOCATION)

        for cat, label, tag in [
            (HEALTHY,             "Healthy",              "ok"),
            (MISSING_FROM_SERVER, "Missing from server",  "err"),
            (WRONG_LOCATION,      "Wrong location",       "warn"),
            (SIZE_MISMATCH,       "Size mismatch",        "warn"),
            (NOT_IN_DB,           "Not in database",      "warn"),
            (ORPHANED_IN_DB,      "Orphaned in database", "dim"),
            (ORPHANED_ON_SERVER,  "Orphaned on server",   "dim"),
        ]:
            n = report.summary.get(cat, 0)
            line(f"  {label:<30} {n:>5}", tag if n > 0 else "dim")

        line("")
        for cat, label, tag in [
            (MISSING_FROM_SERVER, "MISSING FROM SERVER", "err"),
            (WRONG_LOCATION,      "WRONG LOCATION",      "warn"),
            (SIZE_MISMATCH,       "SIZE MISMATCH",        "warn"),
            (NOT_IN_DB,           "NOT IN DATABASE",      "warn"),
            (ORPHANED_IN_DB,      "ORPHANED IN DB",       "dim"),
        ]:
            entries = report.by_category(cat)
            if not entries:
                continue
            line(f"\n── {label} ({len(entries)}) ──", "heading")
            for e in entries:
                detail = f"  {e.restores_to}"
                if e.note:
                    detail += f"  →  {e.note}"
                line(detail, tag)

        if report.orphan_server:
            line(f"\n── ORPHANED ON SERVER ({len(report.orphan_server)}) ──", "heading")
            for p in report.orphan_server[:100]:
                line(f"  {p}", "dim")
            if len(report.orphan_server) > 100:
                line(f"  … and {len(report.orphan_server) - 100} more.", "dim")

        t.configure(state="disabled")

    def _clear_results(self) -> None:
        self._results.configure(state="normal")
        self._results.delete("1.0", "end")
        self._results.configure(state="disabled")

    def _save(self, fmt: str) -> None:
        if not self._report:
            messagebox.showinfo("No report", "Run an audit first.")
            return
        filetypes = [("HTML report", "*.html")] if fmt == "html" else [("Text report", "*.txt")]
        path = _dlg_save(self,
            title="Save audit report",
            defaultextension=f".{fmt}",
            filetypes=filetypes,
        )
        if not path:
            return
        try:
            if fmt == "html":
                write_html(self._report, path)
            else:
                write_txt(self._report, path)
            messagebox.showinfo("Saved", f"Report saved to:\n{path}")
        except Exception as e:
            messagebox.showerror("Save failed", str(e))


# ---------------------------------------------------------------------------
# Settings tab
# ---------------------------------------------------------------------------

class SettingsTab(tk.Frame):
    """Scrollable two-column settings panel."""

    # Field width constants — keep compact
    _W       = 32          # Standard text entry width (characters)
    _W_SHORT = 8           # Short numeric entry
    _PAD     = 16          # Outer margin
    _CPAD    = 14          # Inner card padding
    _GAP     = 10          # Gap between cards

    def __init__(self, parent, app, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._app = app
        self._build()

    # ── Layout helpers ──────────────────────────────────────────────────
    @staticmethod
    def _card(parent):
        """Card frame — slightly raised background with a thin border."""
        return tk.Frame(parent, bg=BG_MID, padx=14, pady=12,
                        highlightbackground=BORDER, highlightthickness=1)

    @staticmethod
    def _heading(parent, text):
        tk.Label(parent, text=text, bg=BG_MID, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w", pady=(0, 8))

    @staticmethod
    def _sub(parent, text):
        tk.Label(parent, text=text, bg=BG_MID, fg=FG_DIM,
                 font=FONT_SMALL, justify="left").pack(anchor="w", pady=(0, 6))

    def _row(self, frame, row, label, key, width=None, show=""):
        """Create label + entry pair in a grid row; return the StringVar."""
        w = width or self._W
        bg = frame.cget("bg")
        tk.Label(frame, text=label, bg=bg, fg=FG_DIM,
                 font=FONT_BODY, anchor="w").grid(
            row=row, column=0, sticky="w", padx=(0, 10), pady=3)
        var = tk.StringVar()
        tk.Entry(frame, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=w, show=show).grid(
            row=row, column=1, sticky="ew", pady=3)
        self._profile_vars[key] = var
        return var

    @staticmethod
    def _browse_btn(frame, row, command):
        bg = frame.cget("bg")
        tk.Button(frame, text="Browse…", bg=BG_CARD, fg=FG_MAIN,
                  activebackground=ACCENT, activeforeground=BG_DEEP,
                  relief="flat", font=FONT_BODY, padx=10, pady=3,
                  command=command).grid(
            row=row, column=2, padx=(6, 0), pady=3, sticky="w")

    @staticmethod
    def _action_btn(parent, text, command, primary=False):
        bg = ACCENT if primary else BG_CARD
        fg = BG_DEEP if primary else FG_MAIN
        abg = BG_CARD if primary else ACCENT
        afg = FG_MAIN if primary else BG_DEEP
        return tk.Button(parent, text=text, bg=bg, fg=fg,
                         activebackground=abg, activeforeground=afg,
                         relief="flat", font=FONT_HEAD if primary else FONT_BODY,
                         padx=14, pady=6, command=command)

    # ── Main build ──────────────────────────────────────────────────────
    def _build(self):
        self._profile_vars: dict[str, tk.StringVar] = {}

        # Scrollable canvas wrapper with auto-hiding scrollbar
        canvas = tk.Canvas(self, bg=BG_DEEP, highlightthickness=0)
        sb     = ttk.Scrollbar(self, orient="vertical", command=canvas.yview)

        def _on_scroll(first, last):
            sb.set(first, last)
            if float(first) <= 0.0 and float(last) >= 1.0:
                sb.pack_forget()
            else:
                sb.pack(side="right", fill="y", before=canvas)

        canvas.configure(yscrollcommand=_on_scroll)
        canvas.pack(side="left", fill="both", expand=True)

        inner = tk.Frame(canvas, bg=BG_DEEP)
        win_id = canvas.create_window((0, 0), window=inner, anchor="nw")

        def _on_configure(_e):
            canvas.configure(scrollregion=canvas.bbox("all"))
            canvas.itemconfigure(win_id, width=canvas.winfo_width())
        inner.bind("<Configure>", _on_configure)
        canvas.bind("<Configure>", _on_configure)

        def _on_mousewheel(e):
            canvas.yview_scroll(int(-1 * (e.delta / 120)), "units")
        canvas.bind_all("<MouseWheel>", _on_mousewheel)

        P  = self._PAD
        G  = self._GAP
        W  = self._W
        WS = self._W_SHORT

        # Two-column container
        cols = tk.Frame(inner, bg=BG_DEEP)
        cols.pack(fill="both", expand=True, padx=P, pady=P)
        cols.columnconfigure(0, weight=1, uniform="half")
        cols.columnconfigure(1, weight=1, uniform="half")

        # =====================================================================
        # LEFT COLUMN — Blog Profile
        # =====================================================================
        left = tk.Frame(cols, bg=BG_DEEP)
        left.grid(row=0, column=0, sticky="nsew", padx=(0, G // 2))

        # ── Site Connection card ────────────────────────────────────────
        c = self._card(left)
        c.pack(fill="x", pady=(0, G))
        self._heading(c, "Site Connection")

        site_g = tk.Frame(c, bg=BG_MID)
        site_g.pack(fill="x")
        site_g.columnconfigure(1, weight=1)
        self._row(site_g, 0, "Blog name",      "name")
        self._row(site_g, 1, "Site URL",        "site_url")
        self._row(site_g, 2, "Admin username",  "snap_admin_user")
        self._row(site_g, 3, "Admin password",  "snap_admin_pass", show="●")

        test_row = tk.Frame(c, bg=BG_MID)
        test_row.pack(anchor="w", pady=(6, 0))
        self._action_btn(test_row, "Test Login",
                         self._test_login).pack(side="left")
        self._action_btn(test_row, "Test FTP",
                         self._test_ftp).pack(side="left", padx=(8, 0))
        self._conn_status_var = tk.StringVar(value="")
        tk.Label(test_row, textvariable=self._conn_status_var,
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL).pack(
            side="left", padx=(12, 0))

        # ── Backup Method card ──────────────────────────────────────────
        c = self._card(left)
        c.pack(fill="x", pady=(0, G))
        self._heading(c, "Backup Method")

        method_frame = tk.Frame(c, bg=BG_MID)
        method_frame.pack(anchor="w")

        self._method_var = tk.StringVar(value="ftp")
        for val, label in [
            ("ftp",   "FTP — differential sync"),
            ("cloud", "Cloud — Google Drive / OneDrive"),
            ("local", "Local only — no upload"),
        ]:
            tk.Radiobutton(
                method_frame, text=label, variable=self._method_var, value=val,
                bg=BG_MID, fg=FG_MAIN, selectcolor=BG_INPUT,
                activebackground=BG_MID, font=FONT_BODY,
                command=self._on_method_change,
            ).pack(anchor="w", pady=2)

        # FTP fields (shown when method = ftp)
        self._ftp_frame = tk.Frame(c, bg=BG_MID)
        ftp_g = tk.Frame(self._ftp_frame, bg=BG_MID)
        ftp_g.pack(fill="x")
        ftp_g.columnconfigure(1, weight=1)
        for row, (label, key, show, w) in enumerate([
            ("Host",             "ftp_host",       "", W),
            ("Port",             "ftp_port",       "", WS),
            ("Username",         "ftp_user",       "", W),
            ("Password",         "ftp_pass",       "●", W),
            ("Remote directory", "ftp_remote_dir", "", W),
        ]):
            self._row(ftp_g, row, label, key, width=w, show=show)

        checks_row = tk.Frame(self._ftp_frame, bg=BG_MID)
        checks_row.pack(anchor="w", pady=(4, 0))

        self._ftp_ssl_var = tk.BooleanVar(value=True)
        tk.Checkbutton(checks_row, text="Use FTP_TLS", variable=self._ftp_ssl_var,
                       bg=BG_MID, fg=FG_MAIN, selectcolor=BG_INPUT,
                       activebackground=BG_MID, font=FONT_BODY).pack(
            side="left", padx=(0, 16))
        self._profile_vars["ftp_ssl"] = self._ftp_ssl_var

        self._ftp_verify_var = tk.BooleanVar(value=False)
        tk.Checkbutton(checks_row, text="Verify certificate",
                       variable=self._ftp_verify_var,
                       bg=BG_MID, fg=FG_MAIN, selectcolor=BG_INPUT,
                       activebackground=BG_MID, font=FONT_BODY).pack(side="left")
        self._profile_vars["ftp_verify_cert"] = self._ftp_verify_var

        # Cloud fields (shown when method = cloud)
        self._cloud_frame = tk.Frame(c, bg=BG_MID)
        cloud_g = tk.Frame(self._cloud_frame, bg=BG_MID)
        cloud_g.pack(fill="x")
        cloud_g.columnconfigure(1, weight=1)

        tk.Label(cloud_g, text="Provider", bg=BG_MID, fg=FG_DIM,
                 font=FONT_BODY, anchor="w").grid(
            row=0, column=0, sticky="w", padx=(0, 10), pady=3)
        cloud_prov_var = tk.StringVar()
        ttk.Combobox(cloud_g, textvariable=cloud_prov_var,
                     values=["google_drive", "onedrive", "none"],
                     font=FONT_MONO, state="readonly", width=18).grid(
            row=0, column=1, sticky="w", pady=3)
        self._profile_vars["cloud_provider"] = cloud_prov_var

        self._row(cloud_g, 1, "Creds override (optional)", "cloud_credentials_file")
        self._browse_btn(cloud_g, 1, self._browse_credentials)

        # Status label + Authenticate button for per-profile OAuth creds
        self._profile_creds_status_var = tk.StringVar(value="")
        tk.Label(cloud_g, textvariable=self._profile_creds_status_var,
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL,
                 anchor="w").grid(row=2, column=1, sticky="w", pady=(0, 2))
        self._profile_auth_btn = tk.Button(
            cloud_g, text="Authenticate with Google",
            bg=BG_CARD, fg=FG_MAIN, relief="flat",
            font=FONT_BODY, padx=10, pady=4,
            command=self._authenticate_oauth_profile)
        self._profile_auth_btn.grid(row=3, column=1, sticky="w", pady=(0, 6))
        self._profile_auth_btn.grid_remove()  # hidden until an OAuth file is selected

        self._row(cloud_g, 4, "Cloud folder ID",  "cloud_folder_id")

        # Local working / backup directory (always shown)
        self._local_frame = tk.Frame(c, bg=BG_MID)
        local_g = tk.Frame(self._local_frame, bg=BG_MID)
        local_g.pack(fill="x")
        local_g.columnconfigure(1, weight=1)
        self._local_dir_label = tk.Label(local_g, text="Backup directory",
                 bg=BG_MID, fg=FG_DIM, font=FONT_BODY, anchor="w")
        self._local_dir_label.grid(row=0, column=0, sticky="w", padx=(0, 10), pady=3)
        bkdir_var = tk.StringVar()
        tk.Entry(local_g, textvariable=bkdir_var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=W).grid(row=0, column=1, sticky="ew", pady=3)
        self._profile_vars["backup_dir"] = bkdir_var
        self._browse_btn(local_g, 0, self._browse_backup_dir)

        # Schedule fields still in _profile_vars so they load/save correctly —
        # but the UI is in the dedicated Schedule tab, not here.
        for key, default in [
            ("schedule_enabled", tk.BooleanVar),
            ("schedule_type",    tk.StringVar),
            ("schedule_day",     tk.StringVar),
            ("schedule_time",    tk.StringVar),
        ]:
            if key not in self._profile_vars:
                self._profile_vars[key] = (tk.BooleanVar if key == "schedule_enabled"
                                           else tk.StringVar)()

        tk.Label(c, text="Use the Schedule tab to configure automatic backups.",
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL).pack(
            anchor="w", pady=(8, 0))

        # Profile buttons row
        prof_btns = tk.Frame(c, bg=BG_MID)
        prof_btns.pack(anchor="w", pady=(10, 0))
        self._action_btn(prof_btns, "Save Profile", self._save_profile,
                         primary=True).pack(side="left")
        self._action_btn(prof_btns, "New Profile",
                         self._new_from_settings).pack(side="left", padx=(8, 0))

        # Shows which profile is loaded
        self._profile_status_var = tk.StringVar(value="")
        tk.Label(c, textvariable=self._profile_status_var,
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w", pady=(4, 0))

        self._no_profile_lbl = tk.Label(c, text="",
                                         bg=BG_MID, fg=FG_DIM, font=FONT_SMALL)

        # Show FTP fields by default
        self._on_method_change()

        # =====================================================================
        # RIGHT COLUMN — Global Settings
        # =====================================================================
        right = tk.Frame(cols, bg=BG_DEEP)
        right.grid(row=0, column=1, sticky="nsew", padx=(G // 2, 0))

        # ── Global Defaults card ────────────────────────────────────────
        c = self._card(right)
        c.pack(fill="x", pady=(0, G))
        self._heading(c, "Global Defaults")

        gd_g = tk.Frame(c, bg=BG_MID)
        gd_g.pack(fill="x")
        gd_g.columnconfigure(1, weight=1)

        self._delay_var = tk.StringVar()
        self._batch_var = tk.StringVar()

        for row, (label, var) in enumerate([
            ("Pacing delay (sec)", self._delay_var),
            ("Batch size (0=all)", self._batch_var),
        ]):
            tk.Label(gd_g, text=label, bg=BG_MID, fg=FG_DIM,
                     font=FONT_BODY, anchor="w").grid(
                row=row, column=0, sticky="w", padx=(0, 10), pady=3)
            tk.Entry(gd_g, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                     insertbackground=ACCENT, relief="flat",
                     font=FONT_MONO, width=WS).grid(
                row=row, column=1, sticky="w", pady=3)

        # ── Global Cloud Config card ────────────────────────────────────
        c = self._card(right)
        c.pack(fill="x", pady=(0, G))
        self._heading(c, "Global Cloud Config")
        self._sub(c, "Shared by all profiles unless overridden.\n"
                     "Service account key stays local — never uploaded.")

        gc_g = tk.Frame(c, bg=BG_MID)
        gc_g.pack(fill="x")
        gc_g.columnconfigure(1, weight=1)

        tk.Label(gc_g, text="Provider", bg=BG_MID, fg=FG_DIM,
                 font=FONT_BODY, anchor="w").grid(
            row=0, column=0, sticky="w", padx=(0, 10), pady=3)
        self._gc_provider_var = tk.StringVar()
        ttk.Combobox(gc_g, textvariable=self._gc_provider_var,
                     values=["google_drive", "onedrive", "none"],
                     font=FONT_MONO, state="readonly", width=18).grid(
            row=0, column=1, sticky="w", pady=3)

        tk.Label(gc_g, text="Credentials JSON", bg=BG_MID, fg=FG_DIM,
                 font=FONT_BODY, anchor="w").grid(
            row=1, column=0, sticky="w", padx=(0, 10), pady=3)
        self._gc_creds_var = tk.StringVar()
        tk.Entry(gc_g, textvariable=self._gc_creds_var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=W).grid(row=1, column=1, sticky="ew", pady=3)
        self._browse_btn(gc_g, 1, self._browse_global_key)

        self._gc_key_status_var = tk.StringVar(value="")
        tk.Label(gc_g, textvariable=self._gc_key_status_var,
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL,
                 anchor="w").grid(row=2, column=1, sticky="w", pady=(0, 2))

        # Authenticate button — only relevant for OAuth client secret files
        self._auth_btn = tk.Button(gc_g, text="Authenticate with Google",
                                   bg=BG_CARD, fg=FG_MAIN, relief="flat",
                                   font=FONT_BODY, padx=10, pady=4,
                                   command=self._authenticate_oauth)
        self._auth_btn.grid(row=3, column=1, sticky="w", pady=(0, 6))

        tk.Label(gc_g, text="Folder ID", bg=BG_MID, fg=FG_DIM,
                 font=FONT_BODY, anchor="w").grid(
            row=4, column=0, sticky="w", padx=(0, 10), pady=3)
        self._gc_folder_var = tk.StringVar()
        tk.Entry(gc_g, textvariable=self._gc_folder_var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=W).grid(row=4, column=1, sticky="ew", pady=3)

        self._action_btn(c, "Save Defaults", self._save,
                         primary=True).pack(anchor="w", pady=(10, 0))

        # ── AI File Matching card ───────────────────────────────────────
        c = self._card(right)
        c.pack(fill="x", pady=(0, G))
        self._heading(c, "AI File Matching")

        self._ai_status_var = tk.StringVar(value="Checking…")
        tk.Label(c, textvariable=self._ai_status_var,
                 bg=BG_MID, fg=FG_DIM, font=FONT_BODY).pack(anchor="w")
        self._action_btn(c, "Install  (pip install sentence-transformers)",
                         self._install_ai).pack(anchor="w", pady=(6, 0))

        # ── Hub / Spoke Discovery card ──────────────────────────────────
        c = self._card(right)
        c.pack(fill="x", pady=(0, G))
        self._heading(c, "Hub / Spoke Discovery")
        self._sub(c, "Auto-discover spokes from a hub blog\n"
                     "and create profiles for each.")

        disc_row = tk.Frame(c, bg=BG_MID)
        disc_row.pack(anchor="w")
        self._action_btn(disc_row, "Discover from Hub…",
                         self._discover_from_hub).pack(side="left")
        self._action_btn(disc_row, "Pull Cloud Config",
                         self._pull_cloud_config).pack(side="left", padx=(8, 0))

        self._disc_status_var = tk.StringVar(value="")
        tk.Label(c, textvariable=self._disc_status_var,
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL,
                 wraplength=380, justify="left").pack(anchor="w", pady=(4, 0))

        # ── Schedule card ───────────────────────────────────────────────
        c = self._card(right)
        c.pack(fill="x", pady=(0, G))
        self._heading(c, "Automatic Backups")
        self._sub(c, "Schedule is per-profile — set it in each profile's Schedule section below.")

        app_opts = tk.Frame(c, bg=BG_MID)
        app_opts.pack(anchor="w", fill="x")

        self._tray_var = tk.BooleanVar(value=False)
        tk.Checkbutton(app_opts, text="Minimize to system tray instead of closing",
                       variable=self._tray_var,
                       bg=BG_MID, fg=FG_MAIN, selectcolor=BG_INPUT,
                       activebackground=BG_MID, font=FONT_BODY,
                       command=self._on_tray_toggle).pack(anchor="w", pady=2)

        self._startup_var = tk.BooleanVar(value=False)
        tk.Checkbutton(app_opts, text="Launch SUYB when Windows starts",
                       variable=self._startup_var,
                       bg=BG_MID, fg=FG_MAIN, selectcolor=BG_INPUT,
                       activebackground=BG_MID, font=FONT_BODY,
                       command=self._on_startup_toggle).pack(anchor="w", pady=2)

        # ── Export / Import card ────────────────────────────────────────
        c = self._card(right)
        c.pack(fill="x", pady=(0, G))
        self._heading(c, "Export / Import Settings")

        xp_row = tk.Frame(c, bg=BG_MID)
        xp_row.pack(anchor="w")
        self._action_btn(xp_row, "Export All…",
                         self._export_settings).pack(side="left")
        self._action_btn(xp_row, "Import…",
                         self._import_settings).pack(side="left", padx=(8, 0))

        # ── Version footer ──────────────────────────────────────────────
        tk.Label(inner, text=f"Smack Up Your Backup  v{BUILD_VERSION}",
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL).pack(
            anchor="e", padx=P, pady=(4, P))

    def load(self, cfg) -> None:
        self._delay_var.set(cfg.get("pacing", "transfer_delay", fallback="2"))
        self._batch_var.set(cfg.get("pacing", "batch_size",     fallback="0"))
        # Global cloud config
        self._gc_provider_var.set(cfg.get("cloud", "provider", fallback="google_drive"))
        self._gc_creds_var.set(cfg.get("cloud", "credentials_file", fallback=""))
        self._gc_folder_var.set(cfg.get("cloud", "folder_id", fallback=""))
        # App options
        self._tray_var.set(cfg.getboolean("app", "tray_enabled", fallback=False))
        self._startup_var.set(cfg.getboolean("app", "startup_enabled", fallback=False))
        self._validate_global_key()
        self._refresh_ai_status()
        self.load_profile(self._app._current_profile)

    def _on_method_change(self) -> None:
        """Show/hide FTP and Cloud field groups based on the selected method."""
        method = self._method_var.get()
        # Hide all conditional frames
        self._ftp_frame.pack_forget()
        self._cloud_frame.pack_forget()
        self._local_frame.pack_forget()

        # Re-pack in order: method-specific → local dir (always)
        if method == "ftp":
            self._ftp_frame.pack(fill="x", pady=(6, 0))
            self._local_dir_label.configure(text="Backup directory")
        elif method == "cloud":
            self._cloud_frame.pack(fill="x", pady=(6, 0))
            self._local_dir_label.configure(text="Local working folder")
        else:
            self._local_dir_label.configure(text="Backup directory")
        # Local directory is always shown — cloud uses it as staging
        self._local_frame.pack(fill="x", pady=(6, 0))

    def _test_login(self) -> None:
        """Test admin login against the blog."""
        url  = self._profile_vars["site_url"].get().strip().rstrip("/")
        user = self._profile_vars["snap_admin_user"].get().strip()
        pw   = self._profile_vars["snap_admin_pass"].get()
        if not url or not user or not pw:
            self._conn_status_var.set("Fill in URL, username, and password first")
            return
        self._conn_status_var.set("Testing login…")
        self.update_idletasks()

        import threading
        def _run():
            try:
                import requests
                r = requests.post(
                    f"{url}/login.php",
                    data={"username": user, "password": pw, "ajax": "1"},
                    timeout=15, allow_redirects=False,
                )
                if r.status_code == 200 and "success" in r.text.lower():
                    msg = "✓ Login successful"
                elif r.status_code < 400:
                    msg = f"✓ Site reachable (HTTP {r.status_code})"
                else:
                    msg = f"⚠ HTTP {r.status_code}"
            except Exception as e:
                msg = f"✗ {e}"
            self.after(0, lambda: self._conn_status_var.set(msg))
        threading.Thread(target=_run, daemon=True).start()

    def _test_ftp(self) -> None:
        """Test FTP connection with current profile fields."""
        host = self._profile_vars["ftp_host"].get().strip()
        if not host:
            self._conn_status_var.set("Fill in FTP host first")
            return
        port = int(self._profile_vars["ftp_port"].get() or 21)
        self._conn_status_var.set(f"Connecting to {host}:{port}…")
        self.update_idletasks()

        import threading
        def _run():
            try:
                from ftp_client import FTPClient
                ftp = FTPClient(
                    host=host,
                    user=self._profile_vars["ftp_user"].get(),
                    password=self._profile_vars["ftp_pass"].get(),
                    remote_dir=self._profile_vars["ftp_remote_dir"].get() or "/",
                    port=port,
                    use_tls=bool(self._profile_vars["ftp_ssl"].get()),
                )
                ftp.connect()
                ftp.disconnect()
                msg = f"✓ Connected to {host}"
            except Exception as e:
                msg = f"✗ {host}:{port} — {e}"
            self.after(0, lambda: self._conn_status_var.set(msg))
        threading.Thread(target=_run, daemon=True).start()

    def _browse_credentials(self) -> None:
        path = _dlg_open(self,
            title="Select credentials JSON",
            filetypes=[("JSON files", "*.json"), ("All files", "*.*")],
        )
        if path and "cloud_credentials_file" in self._profile_vars:
            self._profile_vars["cloud_credentials_file"].set(path)
            self._validate_profile_creds()

    def load_profile(self, profile: Optional[dict]) -> None:
        """Populate the active profile fields from a profile dict, or clear them."""
        if profile:
            # Keys that are BooleanVars — must not pass empty string
            _bool_defaults = {"ftp_ssl": True, "ftp_verify_cert": False,
                              "schedule_enabled": False}
            for key, var in self._profile_vars.items():
                if key in _bool_defaults:
                    default = _bool_defaults[key]
                    val = profile.get(key, default)
                    var.set(bool(val) if val != "" else default)
                else:
                    var.set(str(profile.get(key, "")))
            # Restore backup method — explicit key preferred over inference
            saved_method = profile.get("backup_method", "")
            if saved_method in ("ftp", "cloud", "local"):
                self._method_var.set(saved_method)
            else:
                # Legacy profiles: infer from cloud_provider / ftp_host
                cloud_prov = profile.get("cloud_provider", "none")
                if cloud_prov and cloud_prov != "none":
                    self._method_var.set("cloud")
                elif profile.get("ftp_host"):
                    self._method_var.set("ftp")
                else:
                    self._method_var.set("local")
            self._on_method_change()
            self._validate_profile_creds()
            self._no_profile_lbl.pack_forget()
            self._profile_status_var.set(f"Editing: {profile.get('name', '')}")
        else:
            _bool_defaults = {"ftp_ssl": True, "ftp_verify_cert": False,
                              "schedule_enabled": False}
            _str_defaults  = {"schedule_type": "daily", "schedule_day": "monday",
                              "schedule_time": "02:00"}
            for key, var in self._profile_vars.items():
                if key in _bool_defaults:
                    var.set(_bool_defaults[key])
                elif key in _str_defaults:
                    var.set(_str_defaults[key])
                else:
                    var.set("")
            self._method_var.set("ftp")
            self._on_method_change()
            self._profile_status_var.set("New profile — fill in details and Save")
        self._update_schedule_next()

    def _new_from_settings(self) -> None:
        """Clear the form to start a new profile."""
        self._app._current_profile = None
        self.load_profile(None)

    def _on_tray_toggle(self) -> None:
        enabled = self._tray_var.get()
        cfg = self._app._cfg
        if not cfg.has_section("app"):
            cfg.add_section("app")
        cfg.set("app", "tray_enabled", str(enabled).lower())
        cfg_module.save(cfg)
        if enabled and not self._app._tray_icon:
            self._app.after(0, self._app._start_tray)
        elif not enabled and self._app._tray_icon:
            try:
                self._app._tray_icon.stop()
                self._app._tray_icon = None
            except Exception:
                pass

    def _on_startup_toggle(self) -> None:
        import sys, os
        enabled = self._startup_var.get()
        if sys.platform == "win32":
            self._set_windows_startup(enabled)
        else:
            self._set_linux_autostart(enabled)
        cfg = self._app._cfg
        if not cfg.has_section("app"):
            cfg.add_section("app")
        cfg.set("app", "startup_enabled", str(enabled).lower())
        cfg_module.save(cfg)

    @staticmethod
    def _set_windows_startup(enabled: bool) -> None:
        import sys
        try:
            import winreg
            key_path = r"Software\Microsoft\Windows\CurrentVersion\Run"
            exe = sys.executable
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER, key_path,
                                0, winreg.KEY_SET_VALUE) as key:
                if enabled:
                    winreg.SetValueEx(key, "SmackUpYourBackup", 0,
                                      winreg.REG_SZ, f'"{exe}"')
                else:
                    try:
                        winreg.DeleteValue(key, "SmackUpYourBackup")
                    except FileNotFoundError:
                        pass
        except Exception as e:
            messagebox.showerror("Startup error", f"Could not set startup entry:\n{e}")

    @staticmethod
    def _set_linux_autostart(enabled: bool) -> None:
        import os, sys
        autostart_dir = os.path.expanduser("~/.config/autostart")
        desktop_path  = os.path.join(autostart_dir, "smackupyourbackup.desktop")
        if enabled:
            os.makedirs(autostart_dir, exist_ok=True)
            exe = sys.executable
            content = (
                "[Desktop Entry]\n"
                "Type=Application\n"
                "Name=Smack Up Your Backup\n"
                f"Exec={exe}\n"
                "Hidden=false\n"
                "NoDisplay=false\n"
                "X-GNOME-Autostart-enabled=true\n"
            )
            with open(desktop_path, "w") as f:
                f.write(content)
        else:
            try:
                os.unlink(desktop_path)
            except FileNotFoundError:
                pass

    def _update_schedule_next(self) -> None:
        try:
            from scheduler import BackupScheduler
            profile = {k: v.get() for k, v in self._profile_vars.items()}
            self._sched_next_var.set(BackupScheduler.next_run_str(profile))
        except Exception:
            pass

    def _save_profile(self) -> None:
        """Save the form.  Creates or updates based on name matching."""
        form_name = self._profile_vars["name"].get().strip()
        if not form_name:
            messagebox.showwarning("Blog name required",
                "Enter a blog name before saving.", parent=self)
            return

        current = self._app._current_profile
        # Decide: update existing or create new
        if current and current.get("name") == form_name:
            # Same name — update in place
            profile = current
        else:
            # Different name (or no profile loaded) — create new
            profile = profile_manager.new_profile_template()

        for key, var in self._profile_vars.items():
            val = var.get()
            if key in ("ftp_port", "pacing_delay", "batch_size"):
                try:
                    val = int(val)
                except ValueError:
                    pass
            elif key == "ftp_ssl":
                val = bool(val)
            profile[key] = val

        # Save the method explicitly so it round-trips correctly
        method = self._method_var.get()
        profile["backup_method"] = method
        if method == "local":
            profile["cloud_provider"] = "none"

        profile_manager.save_profile(profile)
        self._app._current_profile = profile
        self._app._refresh_profile_list(profile["name"])
        self._profile_status_var.set(f"Editing: {form_name}")
        messagebox.showinfo("Saved", f"Profile \"{form_name}\" saved.", parent=self)

    def _browse_backup_dir(self) -> None:
        d = _dlg_dir(self, title="Choose local backup folder")
        if d and "backup_dir" in self._profile_vars:
            self._profile_vars["backup_dir"].set(d)

    def _refresh_ai_status(self):
        try:
            import ai_matcher
            self._ai_status_var.set(ai_matcher.status_string())
        except Exception:
            self._ai_status_var.set("Not installed — pip install sentence-transformers")

    def _install_ai(self):
        import subprocess, sys, threading, shutil

        # In a PyInstaller build sys.executable is the compiled exe, not Python.
        # Running it with -m pip would launch a second instance of SUYB.
        # Find the real Python interpreter instead.
        if getattr(sys, 'frozen', False):
            python = shutil.which("python") or shutil.which("python3")
            if not python:
                messagebox.showinfo(
                    "Manual install required",
                    "SUYB is running as a compiled exe and can't run pip directly.\n\n"
                    "To enable AI file matching, open a terminal and run:\n\n"
                    "    pip install sentence-transformers\n\n"
                    "Then restart SUYB.",
                    parent=self.winfo_toplevel(),
                )
                return
        else:
            python = sys.executable

        self._ai_status_var.set("Installing — this may take a minute…")

        def _run():
            try:
                result = subprocess.run(
                    [python, "-m", "pip", "install", "sentence-transformers"],
                    capture_output=True, text=True, timeout=300,
                )
                if result.returncode == 0:
                    self.after(0, self._refresh_ai_status)
                else:
                    err = (result.stderr or result.stdout or "Unknown error").strip()[-200:]
                    self.after(0, lambda: self._ai_status_var.set(f"Install failed: {err}"))
            except subprocess.TimeoutExpired:
                self.after(0, lambda: self._ai_status_var.set("Install timed out — try manually"))
            except Exception as e:
                self.after(0, lambda: self._ai_status_var.set(f"Error: {e}"))

        threading.Thread(target=_run, daemon=True).start()

    def _export_settings(self):
        """Export all profiles + global config to a single JSON file."""
        import json
        path = _dlg_save(self,
            title="Export settings",
            defaultextension=".json",
            filetypes=[("JSON files", "*.json")],
            initialfile="suyb-settings-export.json",
        )
        if not path:
            return

        # Collect all profiles
        profiles = []
        for name in profile_manager.list_profiles():
            p = profile_manager.load_profile(name)
            if p:
                # Remove deobfuscated passwords from export — keep encoded versions
                export_p = dict(p)
                export_p.pop("ftp_pass", None)
                export_p.pop("snap_admin_pass", None)
                profiles.append(export_p)

        # Collect global config as dict
        cfg = self._app._cfg
        global_cfg = {}
        for section in cfg.sections():
            global_cfg[section] = dict(cfg[section])

        bundle = {
            "export_version": 1,
            "app_version":    BUILD_VERSION,
            "exported_at":    __import__("datetime").datetime.now(
                                  __import__("datetime").timezone.utc).isoformat(),
            "global_config":  global_cfg,
            "profiles":       profiles,
        }

        try:
            with open(path, "w") as f:
                json.dump(bundle, f, indent=2)
            messagebox.showinfo("Exported",
                f"Settings exported to:\n{path}\n\n"
                f"{len(profiles)} profile(s) saved.\n"
                "Passwords are base64-encoded, not plain text.",
                parent=self)
        except Exception as e:
            messagebox.showerror("Export failed", str(e), parent=self)

    def _import_settings(self):
        """Import profiles + global config from a previously exported JSON file."""
        import json
        path = _dlg_open(self,
            title="Import settings",
            filetypes=[("JSON files", "*.json"), ("All files", "*.*")],
        )
        if not path:
            return

        try:
            with open(path) as f:
                bundle = json.load(f)
        except Exception as e:
            messagebox.showerror("Import failed", f"Could not read file:\n{e}", parent=self)
            return

        if not isinstance(bundle, dict) or "profiles" not in bundle:
            messagebox.showerror("Invalid file", "This doesn't look like a SUYB settings export.", parent=self)
            return

        # Restore global config
        global_cfg = bundle.get("global_config", {})
        cfg = self._app._cfg
        for section, values in global_cfg.items():
            if not cfg.has_section(section):
                cfg.add_section(section)
            for key, val in values.items():
                cfg.set(section, key, val)
        cfg_module.save(cfg)

        # Restore profiles
        imported = 0
        for p in bundle.get("profiles", []):
            if not p.get("name"):
                continue
            # Re-create passwords from encoded versions for save_profile
            # (save_profile will re-encode them)
            p["ftp_pass"]        = profile_manager._deobfuscate(p.get("ftp_pass_enc", ""))
            p["snap_admin_pass"] = profile_manager._deobfuscate(p.get("snap_admin_pass_enc", ""))
            profile_manager.save_profile(p)
            imported += 1

        # Refresh UI
        self._app._profiles = profile_manager.list_profiles()
        self._app._refresh_profile_list()
        self.load(cfg)

        messagebox.showinfo("Imported",
            f"Imported {imported} profile(s) and global settings from:\n{path}",
            parent=self)

    # ── Hub / Spoke Discovery ───────────────────────────────────────────────

    def _discover_from_hub(self):
        """Open a dialog to enter hub credentials, then discover all spokes."""
        dlg = HubDiscoveryDialog(self, self._app)
        self.wait_window(dlg)
        if dlg.created_count > 0:
            self._disc_status_var.set(
                f"Created {dlg.created_count} profile(s) from hub discovery."
            )
            self._app._profiles = profile_manager.list_profiles()
            self._app._refresh_profile_list()

    def _pull_cloud_config(self):
        """Pull cloud config from the current profile's blog and update fields."""
        profile = self._app._current_profile
        if not profile:
            messagebox.showwarning("No profile", "Select a profile first.", parent=self)
            return

        site_url   = profile.get("site_url", "").strip()
        admin_user = profile.get("snap_admin_user", "").strip()
        admin_pass = profile.get("snap_admin_pass", "").strip()

        if not site_url or not admin_user or not admin_pass:
            messagebox.showwarning(
                "Missing credentials",
                "Fill in the site URL and admin credentials first, then save the profile.",
                parent=self.winfo_toplevel(),
            )
            return

        self._disc_status_var.set("Connecting to blog…")
        self.update_idletasks()

        def _run():
            try:
                from hub_discovery import HubDiscovery
                disc = HubDiscovery(site_url, admin_user, admin_pass)
                data = disc.fetch_suyb_data()
                disc.close()
                self.after(0, lambda: self._apply_cloud_config(data))
            except Exception as e:
                self.after(0, lambda: self._disc_status_var.set(f"Error: {e}"))

        import threading
        threading.Thread(target=_run, daemon=True).start()

    def _apply_cloud_config(self, data: dict):
        """Apply fetched cloud config to the current profile fields."""
        cloud = data.get("cloud_config", {})
        provider  = cloud.get("provider", "none")
        folder_id = cloud.get("folder_id", "")

        updated = []
        if provider and provider != "none":
            self._profile_vars["cloud_provider"].set(provider)
            self._method_var.set("cloud")
            self._on_method_change()
            updated.append(f"provider={provider}")
        if folder_id:
            self._profile_vars["cloud_folder_id"].set(folder_id)
            updated.append(f"folder={folder_id}")

        site_name = data.get("site_name", "")
        if site_name and not self._profile_vars["name"].get().strip():
            self._profile_vars["name"].set(site_name)
            updated.append(f"name={site_name}")

        if updated:
            self._disc_status_var.set(f"Pulled: {', '.join(updated)}")
        else:
            self._disc_status_var.set("No cloud configuration found on this blog.")

    def _browse_global_key(self) -> None:
        """File picker for the Google Drive OAuth client secret JSON."""
        path = _dlg_open(self,
            title="Select credentials JSON",
            filetypes=[("JSON files", "*.json"), ("All files", "*.*")],
        )
        if path:
            self._gc_creds_var.set(path)
            self._validate_global_key()

    def _validate_global_key(self) -> None:
        """Validate credentials file and show token status for OAuth files."""
        import cloud_client as cc
        path = self._gc_creds_var.get().strip()
        if not path:
            self._gc_key_status_var.set("")
            self._auth_btn.grid_remove()
            return
        if cc._is_service_account_key(path):
            self._gc_key_status_var.set("✓ Valid service account key")
            self._auth_btn.grid_remove()
        elif cc._is_oauth_client_secret(path):
            token_status = cc.get_oauth_token_status(path)
            self._gc_key_status_var.set(token_status or "OAuth client secret — click Authenticate")
            self._auth_btn.grid()   # show the button
        elif os.path.isfile(path):
            self._gc_key_status_var.set("Unrecognised format — expected an OAuth or service account JSON")
            self._auth_btn.grid_remove()
        else:
            self._gc_key_status_var.set("File not found")
            self._auth_btn.grid_remove()

    def _authenticate_oauth(self) -> None:
        """Run the Google OAuth consent flow in a background thread."""
        import cloud_client as cc
        import threading
        path = self._gc_creds_var.get().strip()
        if not path:
            return
        self._gc_key_status_var.set("Opening browser for Google login…")
        self._auth_btn.configure(state="disabled")
        self.update_idletasks()

        def _run():
            success, msg = cc.authenticate_oauth(path)
            def _done():
                color = FG_OK if success else FG_ERR
                self._gc_key_status_var.set(msg)
                # Re-check status to get "✓ Authenticated" from token file
                if success:
                    self._validate_global_key()
                self._auth_btn.configure(state="normal")
            self.after(0, _done)

        threading.Thread(target=_run, daemon=True).start()

    def _validate_profile_creds(self) -> None:
        """Validate the per-profile creds override and show auth button if OAuth."""
        import cloud_client as cc
        path = self._profile_vars.get("cloud_credentials_file", tk.StringVar()).get().strip()
        if not path:
            self._profile_creds_status_var.set("")
            self._profile_auth_btn.grid_remove()
            return
        if cc._is_service_account_key(path):
            self._profile_creds_status_var.set("✓ Valid service account key")
            self._profile_auth_btn.grid_remove()
        elif cc._is_oauth_client_secret(path):
            token_status = cc.get_oauth_token_status(path)
            self._profile_creds_status_var.set(token_status or "OAuth client secret — click Authenticate")
            self._profile_auth_btn.grid()
        elif os.path.isfile(path):
            self._profile_creds_status_var.set("Unrecognised format — expected an OAuth or service account JSON")
            self._profile_auth_btn.grid_remove()
        else:
            self._profile_creds_status_var.set("File not found")
            self._profile_auth_btn.grid_remove()

    def _authenticate_oauth_profile(self) -> None:
        """Run the Google OAuth consent flow for the per-profile credentials override."""
        import cloud_client as cc
        import threading
        path = self._profile_vars.get("cloud_credentials_file", tk.StringVar()).get().strip()
        if not path:
            return
        self._profile_creds_status_var.set("Opening browser for Google login…")
        self._profile_auth_btn.configure(state="disabled")
        self.update_idletasks()

        def _run():
            success, msg = cc.authenticate_oauth(path)
            def _done():
                self._profile_creds_status_var.set(msg)
                if success:
                    self._validate_profile_creds()
                self._profile_auth_btn.configure(state="normal")
            self.after(0, _done)

        threading.Thread(target=_run, daemon=True).start()

    def global_cloud_config(self) -> dict:
        """Return the current global cloud config as a dict for the factory."""
        return {
            "cloud_provider":         self._gc_provider_var.get() or "none",
            "cloud_credentials_file": self._gc_creds_var.get().strip(),
            "cloud_folder_id":        self._gc_folder_var.get().strip(),
        }

    def _save(self):
        cfg = self._app._cfg
        if not cfg.has_section("pacing"):
            cfg.add_section("pacing")
        cfg.set("pacing", "transfer_delay", self._delay_var.get())
        cfg.set("pacing", "batch_size",     self._batch_var.get())
        # Global cloud config
        if not cfg.has_section("cloud"):
            cfg.add_section("cloud")
        cfg.set("cloud", "provider",         self._gc_provider_var.get())
        cfg.set("cloud", "credentials_file", self._gc_creds_var.get().strip())
        cfg.set("cloud", "folder_id",        self._gc_folder_var.get().strip())
        cfg_module.save(cfg)
        messagebox.showinfo("Saved", "Global defaults saved.")


# ---------------------------------------------------------------------------
# Help tab
# ---------------------------------------------------------------------------

HELP_TOPICS = [
    ("What does SUYB do?", """
Smack Up Your Backup downloads a complete backup of your SnapSmack blog and packages it into a single dated ZIP file. Each backup run performs six stages:

1. Login — authenticates to your blog's admin panel via HTTP.
2. Recovery kit — downloads the manifest (.tar.gz) which lists every media file on your site with its path, size, and SHA-256 checksum.
3. SQL dumps — downloads a full database export and a schema-only export.
4. Media download — connects via FTP and downloads every media file. In differential mode, unchanged files (same checksum as last run) are skipped. In full mode, everything is re-downloaded.
5. Package — bundles the kit, SQL dumps, and media into a dated ZIP.
6. Cloud push — uploads the ZIP to Google Drive or OneDrive if configured.
7. Verify — checks the ZIP for CRC errors, verifies the cloud upload size, and uploads an updated backup-state.json to your server.

Every downloaded file is SHA-256 verified against the manifest. A mismatch triggers one automatic retry. If it fails again, the file is logged as failed but the rest of the backup continues.
"""),
    ("First-time setup", """
If no profiles exist, SUYB opens the Setup Wizard automatically. You can also run it by deleting the profiles/ folder next to the exe.

To set up manually:
1. Go to the Settings tab.
2. Fill in Site Connection — blog name, site URL, admin username and password. Click Test Login to confirm.
3. Choose a Backup Method — FTP (downloads media), Cloud (FTP + cloud upload), or Local (kit and SQL only, no FTP).
4. If using FTP or Cloud, fill in FTP Setup and click Test FTP.
5. Set Local working folder — where ZIP files are staged on this computer.
6. Click Save Profile.

Your profile is stored as a JSON file in the profiles/ folder next to the exe, one file per blog.
"""),
    ("Backup tab", """
Select a blog from the dropdown at the top right, then click START BACKUP.

Options:
— Differential (default): downloads only files that changed since the last backup. Fast.
— Full: re-downloads everything regardless of what changed. Use this after a major site change or if you suspect the backup state is out of date.
— Include SUYB settings: bundles your profile config into the ZIP so you can restore SUYB itself on a new machine.

BACKUP ALL BLOGS runs a differential backup for every profile in order, one at a time.

If a backup is interrupted (power cut, Windows Update reboot), the next run detects the checkpoint and offers to Resume or Start Fresh. Resuming skips every file that was already successfully downloaded and verified.
"""),
    ("Restore tab", """
Restore uploads files from a backup package back to your server via FTP.

Sources:
— Local ZIP: pick a backup package (.zip) from your computer.
— Cloud: browse your configured cloud storage and select a backup.
— Recovery kit + media folder: use a bare .tar.gz kit and a folder of media files if you have them separately.

Before uploading each file, SUYB verifies its SHA-256 checksum against the manifest. A corrupt local file is rejected — it will not overwrite a good copy on the server.

After uploading, SUYB issues a FTP SIZE command for each file to confirm the server received the correct number of bytes.
"""),
    ("Audit tab", """
Audit performs a three-way comparison between:
— The manifest (what the blog database says should exist)
— The server filesystem (what FTP can actually see)
— The database image records (what the CMS knows about)

Results are categorised:
✓ Healthy — file exists, size matches, database record present
✗ Missing from server — in the manifest but not on FTP
✗ Orphaned on server — on FTP but not in the manifest
✗ Size mismatch — file exists but wrong size
✗ Wrong location — file found by name but in a different path
✗ Not in database — on server but no database record

Save the report as HTML or plain text for reference.
"""),
    ("Settings tab", """
Site Connection — blog URL and admin credentials. Use Test Login and Test FTP to verify before running a backup.

Backup Method — FTP, Cloud, or Local. This is saved per-profile.

FTP Setup — host, port, credentials, remote directory. "Verify certificate" is off by default because shared hosting servers present certs for the server hostname, not your domain — same as clicking Trust in FileZilla.

Local working folder — where SUYB stages files during a backup. For cloud backups this is a temporary staging area; for local backups this is the final destination.

Automatic Backup Schedule — per-profile. Set frequency (daily or weekly), the day (for weekly), and the time in 24-hour format. Enable the checkbox and save the profile.

Automatic Backups (global) — enable the system tray so closing minimizes SUYB instead of quitting, and optionally launch SUYB at Windows startup so scheduled backups run without manual intervention.
"""),
    ("Cloud setup", """
SUYB supports Google Drive and OneDrive.

Google Drive — uses OAuth. Download an OAuth client secret JSON from Google Cloud Console → APIs & Services → Credentials → Create OAuth 2.0 Client ID → Desktop app. Point SUYB at it in Settings → Global Cloud Config → Credentials JSON and click Save Defaults. The first backup run opens a browser for a one-time consent click; after that the token refreshes silently in the background. Backups are stored in your own Google Drive under the folder ID you configure.

OneDrive — uses MSAL. Set your credentials JSON in the profile's Credentials JSON field.

Set the Cloud Folder ID to the Google Drive folder ID (from the URL) or OneDrive folder path where backups should be stored.

After configuring cloud, click Save Defaults (for global config) or Save Profile (for per-profile). Run a backup and check the log for "Cloud upload complete".
"""),
    ("Scheduled backups", """
Schedules are configured per profile in Settings → the profile's Schedule section.

1. Enable the "Enable scheduled backups" checkbox.
2. Set Frequency to daily or weekly.
3. For weekly, choose the day.
4. Set the Time in HH:MM 24-hour format (e.g. 02:00 for 2am).
5. Click Save Profile.

SUYB must be running for scheduled backups to fire. Enable "Minimize to system tray instead of closing" and "Launch SUYB when Windows starts" in Settings → Automatic Backups so it's always running in the background.

Scheduled backups always run in differential mode. The last scheduled run time is saved to the profile so SUYB won't double-fire if you have multiple instances (it checks the profile timestamp on disk).
"""),
    ("Crash recovery", """
SUYB writes a checkpoint file to your local working folder after every successfully downloaded and verified file. The checkpoint uses an atomic rename (write to temp, rename to final) so even a power cut during the write cannot corrupt it.

If SUYB is interrupted mid-backup:
— The recovery kit and SQL dumps already on disk are kept.
— Every media file already downloaded is recorded in the checkpoint.
— On next launch, clicking Start Backup detects the checkpoint and shows a dialog: Resume, Start Fresh, or Cancel.
— Resuming skips Stages 1-2 (kit/SQL on disk), skips every file already in the checkpoint, and continues from where it stopped.
— If the crash happened after all files were downloaded but before packaging, SUYB skips FTP entirely and just repackages what's on disk.

The checkpoint is deleted after a successful, verified backup completion.
"""),
    ("Troubleshooting", """
"Recovery kit download failed" — your admin login may have failed, or smack-disaster.php is inaccessible. Check that you can log into your blog manually, then use Test Login in Settings to confirm credentials are correct.

"FTP connection failed: getaddrinfo failed" — DNS lookup failed for the FTP hostname. Check the Host field in Settings for typos (the field may scroll and hide the last character).

"Checksum mismatch" — a downloaded file's SHA-256 didn't match the manifest. SUYB retries automatically. If it keeps failing, the source file on the server may be corrupt.

"Cloud upload skipped — no cloud provider configured" — check Settings → Global Cloud Config: Provider should be google_drive or onedrive, and the Credentials JSON path should be filled in. Click Save Defaults after making changes.

"Cloud upload skipped — provider configured but no credentials" — the provider is set but the credentials file path is empty or wrong. Check the file exists at the path shown.

Backup runs but produces no log output — if SUYB was previously interrupted, the checkpoint may be causing it to skip to packaging immediately. Check the local working folder for a file ending in _checkpoint.json and delete it, then try again.
"""),
]


# ---------------------------------------------------------------------------
# Scheduler tab
# ---------------------------------------------------------------------------

class SchedulerTab(tk.Frame):
    """Dedicated tab showing all profiles and their schedule status."""

    DAYS = ["monday", "tuesday", "wednesday", "thursday",
            "friday", "saturday", "sunday"]

    def __init__(self, parent, app, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._app   = app
        self._rows: dict[str, dict] = {}   # profile_name → {vars, widgets}
        self._build()

    # ── Layout ──────────────────────────────────────────────────────────
    def _build(self):
        PAD = 16

        # Header
        hdr = tk.Frame(self, bg=BG_MID, padx=PAD, pady=12)
        hdr.pack(fill="x", padx=PAD, pady=(PAD, 0))
        tk.Label(hdr, text="Backup Schedule", bg=BG_MID, fg=ACCENT,
                 font=FONT_TITLE).pack(side="left")
        tk.Label(hdr,
                 text="Changes save automatically. SUYB must be running for schedules to fire.",
                 bg=BG_MID, fg=FG_DIM, font=FONT_BODY).pack(side="left", padx=(14, 0))
        tk.Button(hdr, text="↻  Refresh", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=10, pady=4,
                  command=self.refresh).pack(side="right")

        # Column headings
        cols = tk.Frame(self, bg=BG_DEEP)
        cols.pack(fill="x", padx=PAD, pady=(8, 2))
        for text, width, anchor in [
            ("Blog",            22, "w"),
            ("Enabled",          7, "center"),
            ("Frequency",       10, "w"),
            ("Day",             10, "w"),
            ("Time",             8, "w"),
            ("Last run",        22, "w"),
            ("Next run",        22, "w"),
            ("",                 8, "w"),   # Run Now button
        ]:
            tk.Label(cols, text=text, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_SMALL, width=width, anchor=anchor).pack(side="left")

        tk.Frame(self, bg=BORDER, height=1).pack(fill="x", padx=PAD)

        # Scrollable profile list
        canvas = tk.Canvas(self, bg=BG_DEEP, highlightthickness=0)
        sb     = ttk.Scrollbar(self, orient="vertical", command=canvas.yview)

        def _on_scroll(first, last):
            sb.set(first, last)
            if float(first) <= 0.0 and float(last) >= 1.0:
                sb.pack_forget()
            else:
                sb.pack(side="right", fill="y", before=canvas)

        canvas.configure(yscrollcommand=_on_scroll)
        canvas.pack(side="left", fill="both", expand=True, padx=PAD, pady=4)

        self._inner = tk.Frame(canvas, bg=BG_DEEP)
        self._win_id = canvas.create_window((0, 0), window=self._inner, anchor="nw")

        def _resize(_e):
            canvas.configure(scrollregion=canvas.bbox("all"))
            canvas.itemconfigure(self._win_id, width=canvas.winfo_width())
        self._inner.bind("<Configure>", _resize)
        canvas.bind("<Configure>", _resize)
        canvas.bind_all("<MouseWheel>",
                        lambda e: canvas.yview_scroll(int(-1*(e.delta/120)), "units"))

        self.refresh()

    # ── Data ────────────────────────────────────────────────────────────
    def refresh(self) -> None:
        """Rebuild the profile rows from disk."""
        for w in self._inner.winfo_children():
            w.destroy()
        self._rows.clear()

        profiles = []
        for name in profile_manager.list_profiles():
            p = profile_manager.load_profile(name)
            if p:
                profiles.append(p)

        if not profiles:
            tk.Label(self._inner, text="No profiles configured yet.\nGo to Settings to add a blog.",
                     bg=BG_DEEP, fg=FG_DIM, font=FONT_BODY, justify="center").pack(
                pady=40)
            return

        for p in profiles:
            self._add_row(p)

    def _add_row(self, profile: dict) -> None:
        name = profile.get("name", "")
        row  = tk.Frame(self._inner, bg=BG_MID, padx=8, pady=8,
                        highlightbackground=BORDER, highlightthickness=1)
        row.pack(fill="x", pady=(0, 4))

        vars_ = {}

        # Blog name + URL — wider so names don't get clipped
        info = tk.Frame(row, bg=BG_MID, width=220)
        info.pack(side="left")
        info.pack_propagate(False)
        tk.Label(info, text=name, bg=BG_MID, fg=FG_MAIN,
                 font=FONT_HEAD, anchor="w", wraplength=210).pack(anchor="w")
        tk.Label(info, text=profile.get("site_url", ""),
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL, anchor="w").pack(anchor="w")

        # Enabled checkbox
        enabled_var = tk.BooleanVar(value=bool(profile.get("schedule_enabled", False)))
        vars_["schedule_enabled"] = enabled_var
        tk.Checkbutton(row, variable=enabled_var, bg=BG_MID,
                       selectcolor=BG_INPUT, activebackground=BG_MID,
                       command=lambda n=name, v=enabled_var: self._save_field(n, "schedule_enabled", v.get())
                       ).pack(side="left", padx=(8, 16))

        # Frequency
        freq_var = tk.StringVar(value=profile.get("schedule_type", "daily"))
        vars_["schedule_type"] = freq_var
        ttk.Combobox(row, textvariable=freq_var, values=["daily", "weekly"],
                     state="readonly", font=FONT_BODY, width=8).pack(side="left", padx=(0, 8))
        freq_var.trace_add("write", lambda *a, n=name, v=freq_var: self._save_field(n, "schedule_type", v.get()))

        # Day (weekly)
        day_var = tk.StringVar(value=profile.get("schedule_day", "monday"))
        vars_["schedule_day"] = day_var
        ttk.Combobox(row, textvariable=day_var, values=self.DAYS,
                     state="readonly", font=FONT_BODY, width=10).pack(side="left", padx=(0, 8))
        day_var.trace_add("write", lambda *a, n=name, v=day_var: self._save_field(n, "schedule_day", v.get()))

        # Time
        time_var = tk.StringVar(value=profile.get("schedule_time", "02:00"))
        vars_["schedule_time"] = time_var
        tk.Entry(row, textvariable=time_var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=6).pack(side="left", padx=(0, 8))
        time_var.trace_add("write", lambda *a, n=name, v=time_var: self._save_field(n, "schedule_time", v.get()))

        # Last run
        last = profile.get("last_scheduled_run", "") or "Never"
        if len(last) > 16:
            last = last[:16].replace("T", " ")
        tk.Label(row, text=last, bg=BG_MID, fg=FG_DIM,
                 font=FONT_SMALL, width=20, anchor="w").pack(side="left", padx=(0, 8))

        # Next run
        from scheduler import BackupScheduler
        next_lbl = tk.Label(row, text=BackupScheduler.next_run_str(profile),
                            bg=BG_MID, fg=ACCENT if profile.get("schedule_enabled") else FG_DIM,
                            font=FONT_SMALL, width=20, anchor="w")
        next_lbl.pack(side="left", padx=(0, 8))
        vars_["_next_lbl"] = next_lbl

        # Run Now button
        tk.Button(row, text="Run Now", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_SMALL, padx=8, pady=2,
                  command=lambda p=profile: self._run_now(p)).pack(side="left")

        self._rows[name] = {"vars": vars_, "profile": profile}

    # ── Actions ─────────────────────────────────────────────────────────
    def _save_field(self, name: str, key: str, value) -> None:
        """Persist a single field change to the profile JSON."""
        p = profile_manager.load_profile(name)
        if not p:
            return
        p[key] = value
        profile_manager.save_profile(p)
        # Update next-run label
        row = self._rows.get(name, {})
        lbl = row.get("vars", {}).get("_next_lbl")
        if lbl:
            from scheduler import BackupScheduler
            lbl.configure(
                text=BackupScheduler.next_run_str(p),
                fg=ACCENT if p.get("schedule_enabled") else FG_DIM,
            )

    def _run_now(self, profile: dict) -> None:
        """Immediately queue a backup for this profile."""
        self._app.queue_msg(("scheduled_backup", profile))
        self._app._switch_tab(TAB_BACKUP)


class CloudSyncTab(tk.Frame):
    """Cloud-to-Cloud sync tab: Google Drive → OneDrive differential file sync."""

    def __init__(self, parent, app, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._app    = app
        self._busy   = False
        self._engine: Optional[CloudSyncEngine] = None
        self._start_time = None
        self._tick_job   = None
        self._last_pct   = 0.0
        self._build()

    # ------------------------------------------------------------------
    # Build UI
    # ------------------------------------------------------------------

    def _build(self):
        # ── Job selector row ────────────────────────────────────────────
        sel_row = tk.Frame(self, bg=BG_MID, padx=16, pady=10)
        sel_row.pack(fill="x", padx=16, pady=(16, 4))

        tk.Label(sel_row, text="Sync Job:", bg=BG_MID, fg=FG_DIM,
                 font=FONT_BODY).pack(side="left")

        self._job_var = tk.StringVar()
        self._job_menu = ttk.Combobox(sel_row, textvariable=self._job_var,
                                       state="readonly", font=FONT_BODY, width=30)
        self._job_menu.pack(side="left", padx=(8, 16))
        self._job_menu.bind("<<ComboboxSelected>>", self._on_job_selected)

        tk.Button(sel_row, text="New", bg=BG_CARD, fg=FG_MAIN,
                  font=FONT_BODY, relief="flat", padx=10, pady=4,
                  cursor="hand2", command=self._new_job).pack(side="left", padx=(0, 4))
        tk.Button(sel_row, text="Edit", bg=BG_CARD, fg=FG_MAIN,
                  font=FONT_BODY, relief="flat", padx=10, pady=4,
                  cursor="hand2", command=self._edit_job).pack(side="left", padx=(0, 4))
        tk.Button(sel_row, text="Delete", bg=BG_CARD, fg=FG_DIM,
                  font=FONT_BODY, relief="flat", padx=10, pady=4,
                  cursor="hand2", command=self._delete_job).pack(side="left")

        # ── Source / dest status ────────────────────────────────────────
        self._src_lbl = tk.Label(self, text="Source: —", bg=BG_DEEP, fg=FG_DIM,
                                  font=FONT_SMALL, anchor="w")
        self._src_lbl.pack(fill="x", padx=16, pady=(4, 0))
        self._dst_lbl = tk.Label(self, text="Dest:   —", bg=BG_DEEP, fg=FG_DIM,
                                  font=FONT_SMALL, anchor="w")
        self._dst_lbl.pack(fill="x", padx=16, pady=(0, 4))

        # ── Progress bar ────────────────────────────────────────────────
        self._prog_bar = ProgressBar(self)
        self._prog_bar.pack(fill="x", padx=16, pady=(0, 4))

        # ── Stats rows ──────────────────────────────────────────────────
        stats_row = tk.Frame(self, bg=BG_DEEP)
        stats_row.pack(fill="x", padx=16, pady=(0, 2))

        self._prog_lbl = tk.Label(stats_row, text="", bg=BG_DEEP, fg=FG_DIM,
                                   font=FONT_SMALL, anchor="w")
        self._prog_lbl.pack(side="left", fill="x", expand=True)

        self._time_lbl = tk.Label(stats_row, text="", bg=BG_DEEP, fg=FG_DIM,
                                   font=FONT_SMALL, anchor="e")
        self._time_lbl.pack(side="right")

        counts_row = tk.Frame(self, bg=BG_DEEP)
        counts_row.pack(fill="x", padx=16, pady=(0, 4))

        self._files_lbl = tk.Label(counts_row, text="", bg=BG_DEEP, fg=FG_DIM,
                                    font=FONT_SMALL, anchor="w")
        self._files_lbl.pack(side="left")

        self._bytes_lbl = tk.Label(counts_row, text="", bg=BG_DEEP, fg=FG_DIM,
                                    font=FONT_SMALL, anchor="e")
        self._bytes_lbl.pack(side="right")

        # ── Log ─────────────────────────────────────────────────────────
        self._log = LogPane(self)
        self._log.pack(fill="both", expand=True, padx=16, pady=8)

        # ── Button row ──────────────────────────────────────────────────
        btn_row = tk.Frame(self, bg=BG_DEEP)
        btn_row.pack(fill="x", padx=16, pady=(0, 16), side="bottom")

        self._run_btn = tk.Button(
            btn_row, text="▶  RUN SYNC",
            bg=ACCENT, fg=BG_DEEP, font=FONT_HEAD, relief="flat",
            padx=20, pady=8, cursor="hand2", command=self._start,
        )
        self._run_btn.pack(side="left")

        self._cancel_btn = tk.Button(
            btn_row, text="Cancel", bg=BG_CARD, fg=FG_DIM,
            font=FONT_BODY, relief="flat", padx=12, pady=8,
            state="disabled", command=self._cancel,
        )
        self._cancel_btn.pack(side="left", padx=(8, 0))

        self._refresh_jobs()

    # ------------------------------------------------------------------
    # Job management
    # ------------------------------------------------------------------

    def _refresh_jobs(self):
        jobs = sync_manager.list_jobs()
        self._job_menu["values"] = jobs
        if jobs:
            if self._job_var.get() not in jobs:
                self._job_var.set(jobs[0])
            self._update_status_labels()
        else:
            self._job_var.set("")
            self._src_lbl.configure(text="Source: —")
            self._dst_lbl.configure(text="Dest:   —")

    def _on_job_selected(self, _event=None):
        self._update_status_labels()

    def _update_status_labels(self):
        name = self._job_var.get()
        if not name:
            return
        job = sync_manager.load_job(name)
        if not job:
            return
        src_folder = job.get("source_folder_id", "") or "—"
        dst_folder = job.get("dest_folder_path", "") or "—"
        self._src_lbl.configure(text=f"Source:  Google Drive — folder {src_folder}")
        self._dst_lbl.configure(text=f"Dest:    OneDrive — {dst_folder}")

    def _new_job(self):
        template = sync_manager.new_job_template()
        dlg = _SyncJobDialog(self, template, title="New Sync Job")
        self.wait_window(dlg)
        if dlg.result:
            sync_manager.save_job(dlg.result)
            self._refresh_jobs()
            self._job_var.set(dlg.result["name"])

    def _edit_job(self):
        name = self._job_var.get()
        if not name:
            messagebox.showinfo("No job", "Select a sync job first.")
            return
        job = sync_manager.load_job(name)
        if not job:
            return
        dlg = _SyncJobDialog(self, job, title="Edit Sync Job")
        self.wait_window(dlg)
        if dlg.result:
            # If name changed, delete old file
            if dlg.result["name"] != name:
                sync_manager.delete_job(name)
            sync_manager.save_job(dlg.result)
            self._refresh_jobs()
            self._job_var.set(dlg.result["name"])

    def _delete_job(self):
        name = self._job_var.get()
        if not name:
            return
        if messagebox.askyesno("Delete job", f"Delete sync job '{name}'?"):
            sync_manager.delete_job(name)
            self._refresh_jobs()

    # ------------------------------------------------------------------
    # Run / cancel
    # ------------------------------------------------------------------

    def _start(self):
        name = self._job_var.get()
        if not name:
            messagebox.showinfo("No job", "Select or create a sync job first.")
            return
        job = sync_manager.load_job(name)
        if not job:
            return

        # Validate required fields
        missing = []
        if not job.get("source_credentials_file"):
            missing.append("Google Drive credentials file")
        if not job.get("source_folder_id"):
            missing.append("Google Drive source folder ID")
        if not job.get("dest_credentials_file"):
            missing.append("OneDrive credentials file")
        if not job.get("dest_folder_path"):
            missing.append("OneDrive destination folder name")
        if missing:
            messagebox.showerror("Missing config",
                                 "Please configure:\n• " + "\n• ".join(missing))
            return

        self._busy = True
        self._run_btn.configure(state="disabled")
        self._cancel_btn.configure(state="normal")
        self._prog_bar.reset()
        self._files_lbl.configure(text="")
        self._bytes_lbl.configure(text="")
        self._time_lbl.configure(text="")
        self._log.clear()
        self._log.append("Starting sync…")
        self._start_clock()

        self._engine = CloudSyncEngine(
            config=job,
            on_log=lambda m: self._app.queue_msg(("sync_log", m)),
            on_progress=lambda p: self._app.queue_msg(("sync_progress", p)),
            on_stats=lambda *a: self._app.queue_msg(("sync_stats",) + a),
            on_done=lambda r: self._app.queue_msg(("sync_done", r)),
            on_ask=lambda msg: self._app.queue_msg(("sync_ask", msg)),
        )
        t = threading.Thread(target=self._engine.run, daemon=True)
        t.start()

    def _cancel(self):
        if self._engine:
            self._engine.cancel()
        self._cancel_btn.configure(state="disabled")

    # ------------------------------------------------------------------
    # Callbacks from _poll
    # ------------------------------------------------------------------

    def on_progress(self, pct: float):
        self._prog_bar.set(pct)
        self._last_pct = pct

    def on_log(self, msg: str):
        self._log.append(msg)

    def on_stats(self, done, total, skipped, failed, bytes_done, bytes_total):
        self._files_lbl.configure(
            text=f"Files: {done} / {total} synced   Skipped: {skipped}   Failed: {failed}"
        )
        self._bytes_lbl.configure(
            text=f"{self._fmt_bytes(bytes_done)} / {self._fmt_bytes(bytes_total)}"
        )

    def on_done(self, result: dict):
        self._stop_clock()
        self._busy   = False
        self._engine = None
        self._run_btn.configure(state="normal")
        self._cancel_btn.configure(state="disabled")
        self._prog_bar.set(1.0 if result.get("ok") else self._last_pct)

        if result.get("cancelled"):
            self._log.append("— Sync cancelled.")
        elif result.get("ok"):
            self._log.append(
                f"✓ Sync complete — {result['files_synced']} file(s), "
                f"{self._fmt_bytes(result['bytes_synced'])}."
            )
            # Update last sync info in job config
            name = self._job_var.get()
            if name:
                job = sync_manager.load_job(name)
                if job:
                    from datetime import datetime, timezone
                    job["last_sync_date"]    = datetime.now(timezone.utc).isoformat()
                    job["last_files_synced"] = result["files_synced"]
                    job["last_bytes_synced"] = result["bytes_synced"]
                    sync_manager.save_job(job)
        else:
            self._log.append(
                f"✗ Sync finished with {result.get('files_failed', 0)} failure(s). "
                + (result.get("error", "") or "")
            )

    # ------------------------------------------------------------------
    # Clock helpers (mirrors BackupTab)
    # ------------------------------------------------------------------

    @staticmethod
    def _fmt_bytes(n: int) -> str:
        if n >= 1_073_741_824:
            return f"{n / 1_073_741_824:.2f} GB"
        if n >= 1_048_576:
            return f"{n / 1_048_576:.1f} MB"
        if n >= 1024:
            return f"{n / 1024:.0f} KB"
        return f"{n} B"

    @staticmethod
    def _fmt_time(secs: float) -> str:
        s = int(secs)
        h, rem = divmod(s, 3600)
        m, s   = divmod(rem, 60)
        return f"{h:02d}:{m:02d}:{s:02d}" if h else f"{m:02d}:{s:02d}"

    def _start_clock(self):
        self._start_time = time.monotonic()
        self._last_pct   = 0.0
        if self._tick_job:
            self.after_cancel(self._tick_job)
        self._tick()

    def _stop_clock(self):
        if self._tick_job:
            self.after_cancel(self._tick_job)
            self._tick_job = None

    def _tick(self):
        if not self._busy or self._start_time is None:
            return
        elapsed = time.monotonic() - self._start_time
        pct     = self._last_pct
        if pct > 0.01:
            eta = elapsed / pct * (1.0 - pct)
            self._time_lbl.configure(
                text=f"Elapsed: {self._fmt_time(elapsed)}   ETA: {self._fmt_time(eta)}"
            )
        else:
            self._time_lbl.configure(text=f"Elapsed: {self._fmt_time(elapsed)}")
        self._tick_job = self.after(1000, self._tick)


# ---------------------------------------------------------------------------
# Sync job editor dialog
# ---------------------------------------------------------------------------

class _SyncJobDialog(tk.Toplevel):
    """Create / edit a cloud sync job config."""

    def __init__(self, parent, config: dict, title: str = "Sync Job"):
        super().__init__(parent)
        self.title(title)
        self.configure(bg=BG_MID)
        self.resizable(False, False)
        self.grab_set()
        self.focus_force()
        self.result = None
        self._config = dict(config)
        self._build()
        self.protocol("WM_DELETE_WINDOW", self._cancel)

    def _row(self, parent, label: str, var, width: int = 42, show: str = ""):
        row = tk.Frame(parent, bg=BG_MID)
        row.pack(fill="x", pady=3)
        tk.Label(row, text=label, bg=BG_MID, fg=FG_DIM,
                 font=FONT_BODY, width=26, anchor="w").pack(side="left")
        tk.Entry(row, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=width, show=show).pack(side="left")
        return row

    def _browse_row(self, parent, label: str, var):
        row = tk.Frame(parent, bg=BG_MID)
        row.pack(fill="x", pady=3)
        tk.Label(row, text=label, bg=BG_MID, fg=FG_DIM,
                 font=FONT_BODY, width=26, anchor="w").pack(side="left")
        tk.Entry(row, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=36).pack(side="left")
        tk.Button(row, text="…", bg=BG_CARD, fg=FG_MAIN, relief="flat",
                  font=FONT_BODY, padx=6, pady=1,
                  command=lambda: self._browse_file(var)).pack(side="left", padx=(4, 0))
        return row

    @staticmethod
    def _browse_file(var):
        path = filedialog.askopenfilename(filetypes=[("JSON files", "*.json")])
        if path:
            var.set(path)

    def _auth_row(self, parent, label: str, var, auth_fn, status_var):
        row = tk.Frame(parent, bg=BG_MID)
        row.pack(fill="x", pady=3)
        tk.Label(row, text=label, bg=BG_MID, fg=FG_DIM,
                 font=FONT_BODY, width=26, anchor="w").pack(side="left")
        tk.Button(row, text=label, bg=BG_CARD, fg=FG_MAIN, relief="flat",
                  font=FONT_BODY, padx=10, pady=3,
                  command=lambda: self._do_auth(auth_fn, status_var, var)).pack(side="left")
        tk.Label(row, textvariable=status_var, bg=BG_MID, fg=FG_DIM,
                 font=FONT_SMALL).pack(side="left", padx=(8, 0))

    def _do_auth(self, auth_fn, status_var, creds_var):
        status_var.set("Opening browser…")
        self.update()
        ok, msg = auth_fn(creds_var.get())
        status_var.set(msg)

    def _build(self):
        c = self._config
        PAD = 16

        body = tk.Frame(self, bg=BG_MID, padx=PAD, pady=PAD)
        body.pack(fill="both", expand=True)

        # Job name
        self._name_var = tk.StringVar(value=c.get("name", ""))
        self._row(body, "Job name:", self._name_var, width=36)

        tk.Label(body, text="── Google Drive Source ──────────────────",
                 bg=BG_MID, fg=ACCENT, font=FONT_SMALL).pack(anchor="w", pady=(12, 4))

        self._src_creds_var = tk.StringVar(value=c.get("source_credentials_file", ""))
        self._src_folder_var = tk.StringVar(value=c.get("source_folder_id", ""))
        self._browse_row(body, "OAuth client secret JSON:", self._src_creds_var)

        # Folder ID with help text
        folder_row = tk.Frame(body, bg=BG_MID)
        folder_row.pack(fill="x", pady=3)
        tk.Label(folder_row, text="Source folder ID:", bg=BG_MID, fg=FG_DIM,
                 font=FONT_BODY, width=26, anchor="w").pack(side="left")
        tk.Entry(folder_row, textvariable=self._src_folder_var, bg=BG_INPUT,
                 fg=FG_MAIN, insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=36).pack(side="left")

        tk.Label(body,
                 text="  (Copy from Drive URL: drive.google.com/drive/folders/FOLDER_ID_HERE)",
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w", pady=(0, 2))

        self._src_auth_status = tk.StringVar()
        self._auth_row(body, "Authenticate with Google",
                       self._src_creds_var,
                       lambda p: cloud_module.authenticate_oauth(p, readonly=True),
                       self._src_auth_status)

        tk.Label(body, text="── OneDrive Destination ─────────────────",
                 bg=BG_MID, fg=ACCENT, font=FONT_SMALL).pack(anchor="w", pady=(12, 4))

        self._dst_creds_var = tk.StringVar(value=c.get("dest_credentials_file", ""))
        self._dst_folder_var = tk.StringVar(value=c.get("dest_folder_path", ""))
        self._browse_row(body, "MS app credentials JSON:", self._dst_creds_var)
        self._row(body, "Destination folder name:", self._dst_folder_var, width=36)

        tk.Label(body,
                 text='  (Folder name in your OneDrive root, e.g. "FoundTexturesBackup")',
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w", pady=(0, 2))

        self._dst_auth_status = tk.StringVar()
        self._auth_row(body, "Authenticate with Microsoft",
                       self._dst_creds_var,
                       cloud_module.authenticate_onedrive,
                       self._dst_auth_status)

        tk.Label(body,
                 text='  Credentials JSON: {"client_id": "your-azure-app-client-id"}',
                 bg=BG_MID, fg=FG_DIM, font=FONT_SMALL).pack(anchor="w", pady=(0, 2))

        # Buttons
        btn_row = tk.Frame(body, bg=BG_MID, pady=(8))
        btn_row.pack(fill="x")
        tk.Button(btn_row, text="Save", bg=ACCENT, fg=BG_DEEP,
                  font=FONT_HEAD, relief="flat", padx=20, pady=6,
                  command=self._save).pack(side="left")
        tk.Button(btn_row, text="Cancel", bg=BG_CARD, fg=FG_DIM,
                  font=FONT_BODY, relief="flat", padx=12, pady=6,
                  command=self._cancel).pack(side="left", padx=(8, 0))

        # Show current token statuses
        src_creds = c.get("source_credentials_file", "")
        if src_creds:
            status = cloud_module.get_oauth_token_status(src_creds)
            self._src_auth_status.set(status)

        dst_creds = c.get("dest_credentials_file", "")
        if dst_creds:
            status = cloud_module.get_onedrive_token_status(dst_creds)
            self._dst_auth_status.set(status)

    def _save(self):
        name = self._name_var.get().strip()
        if not name:
            messagebox.showerror("Name required", "Enter a job name.", parent=self)
            return
        self.result = dict(self._config)
        self.result["name"]                    = name
        self.result["source_credentials_file"] = self._src_creds_var.get().strip()
        self.result["source_folder_id"]        = self._src_folder_var.get().strip()
        self.result["dest_credentials_file"]   = self._dst_creds_var.get().strip()
        self.result["dest_folder_path"]        = self._dst_folder_var.get().strip()
        self.destroy()

    def _cancel(self):
        self.destroy()


class HelpTab(tk.Frame):
    """In-app documentation — topic list on left, content on right."""

    def __init__(self, parent, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._build()

    def _build(self):
        PAD = 16

        # ── Left: topic list ────────────────────────────────────────────
        sidebar = tk.Frame(self, bg=BG_MID, width=200)
        sidebar.pack(side="left", fill="y", padx=(PAD, 0), pady=PAD)
        sidebar.pack_propagate(False)

        tk.Label(sidebar, text="Topics", bg=BG_MID, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w", padx=12, pady=(12, 6))

        self._topic_btns = []
        for i, (title, _content) in enumerate(HELP_TOPICS):
            btn = tk.Button(
                sidebar, text=title, bg=BG_MID, fg=FG_DIM,
                relief="flat", font=FONT_BODY, anchor="w", padx=12, pady=4,
                wraplength=176, justify="left",
                command=lambda idx=i: self._show(idx),
            )
            btn.pack(fill="x")
            self._topic_btns.append(btn)

        # ── Right: content area ─────────────────────────────────────────
        content_frame = tk.Frame(self, bg=BG_DEEP)
        content_frame.pack(side="left", fill="both", expand=True,
                           padx=PAD, pady=PAD)

        self._title_lbl = tk.Label(content_frame, text="", bg=BG_DEEP,
                                    fg=ACCENT, font=FONT_TITLE, anchor="w",
                                    wraplength=700, justify="left")
        self._title_lbl.pack(anchor="w", pady=(0, 10))

        tk.Frame(content_frame, bg=BORDER, height=1).pack(fill="x", pady=(0, 10))

        text_frame = tk.Frame(content_frame, bg=BG_DEEP)
        text_frame.pack(fill="both", expand=True)

        self._text = tk.Text(
            text_frame, bg=BG_DEEP, fg=FG_MAIN,
            font=("Segoe UI", 12),   # larger for readability
            relief="flat", wrap="word", state="disabled",
            padx=12, pady=8, spacing1=6, spacing3=6,
        )
        sb = ttk.Scrollbar(text_frame, command=self._text.yview)
        self._text.configure(yscrollcommand=sb.set)
        sb.pack(side="right", fill="y")
        self._text.pack(side="left", fill="both", expand=True)

        self._show(0)

    def _show(self, idx: int) -> None:
        for i, btn in enumerate(self._topic_btns):
            btn.configure(
                bg=BG_CARD if i == idx else BG_MID,
                fg=ACCENT  if i == idx else FG_DIM,
            )
        title, content = HELP_TOPICS[idx]
        self._title_lbl.configure(text=title)
        self._text.configure(state="normal")
        self._text.delete("1.0", "end")
        self._text.insert("1.0", content.strip())
        self._text.configure(state="disabled")
        self._text.yview_moveto(0)


# ---------------------------------------------------------------------------
# Main application window
# ---------------------------------------------------------------------------

class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title(f"Smack Up Your Backup  —  v{BUILD_VERSION}")
        self.configure(bg=BG_DEEP)
        self.minsize(940, 720)

        self._cfg      = cfg_module.load()
        self._profiles = profile_manager.list_profiles()
        self._current_profile: Optional[dict] = None
        self._msg_queue: queue.Queue = queue.Queue()
        self._tray_icon = None
        self._quitting  = False

        # Restore window geometry and state
        try:
            w = int(self._cfg.get("window", "width",  fallback="1100"))
            h = int(self._cfg.get("window", "height", fallback="920"))
            self.geometry(f"{w}x{h}")
            if self._cfg.get("window", "state", fallback="") == "zoomed":
                self.state("zoomed")
        except Exception:
            self.geometry("1100x920")

        self._migrate_profiles()
        self._build_ui()
        self._load_last_profile()
        self.after(100, self._poll)
        self.protocol("WM_DELETE_WINDOW", self._on_close)

        # Start scheduler
        from scheduler import BackupScheduler
        self._scheduler = BackupScheduler(on_trigger=self._on_schedule_trigger)
        self._scheduler.start(self._get_all_profiles_for_scheduler)

        # Start tray icon if enabled
        if self._cfg.getboolean("app", "tray_enabled", fallback=False):
            self.after(500, self._start_tray)

    def current_profile(self) -> Optional[dict]:
        return self._current_profile

    def global_cloud_config(self) -> dict:
        """Return the global cloud config dict for the cloud client factory.

        If the SettingsTab is available, pull live values from the UI;
        otherwise fall back to config.ini on disk."""
        try:
            return self._tab_settings.global_cloud_config()
        except Exception:
            cfg = self._cfg
            return {
                "cloud_provider":         cfg.get("cloud", "provider", fallback="none"),
                "cloud_credentials_file": cfg.get("cloud", "credentials_file", fallback=""),
                "cloud_folder_id":        cfg.get("cloud", "folder_id", fallback=""),
            }

    def queue_msg(self, msg: tuple) -> None:
        self._msg_queue.put(msg)

    # ------------------------------------------------------------------
    # UI construction
    # ------------------------------------------------------------------

    def _migrate_profiles(self) -> None:
        """One-time migrations applied to all profiles on disk at startup."""
        for name in profile_manager.list_profiles():
            p = profile_manager.load_profile(name)
            if not p:
                continue
            changed = False
            # v0.2.5: default backup method changed from ftp to cloud
            if p.get("backup_method") == "ftp":
                p["backup_method"] = "cloud"
                changed = True
            if changed:
                profile_manager.save_profile(p)

    # ------------------------------------------------------------------

    def _build_ui(self):
        self._build_header()
        self._build_tabs()

    def _build_header(self):
        header = tk.Frame(self, bg=BG_CARD, height=52)
        header.pack(fill="x")
        header.pack_propagate(False)

        # Left: title
        tk.Label(header, text="SMACK UP YOUR BACKUP",
                 bg=BG_CARD, fg=ACCENT, font=FONT_TITLE).pack(side="left", padx=16)
        tk.Label(header, text=f"v{BUILD_VERSION}",
                 bg=BG_CARD, fg=FG_DIM, font=FONT_SMALL).pack(side="left")

        # Right: profile selector
        right = tk.Frame(header, bg=BG_CARD)
        right.pack(side="right", padx=16, fill="y")

        tk.Button(right, text="+ New", bg=BG_MID, fg=FG_MAIN,
                  relief="flat", font=FONT_SMALL,
                  command=self._new_profile).pack(side="right", padx=(4, 0))
        tk.Button(right, text="Edit", bg=BG_MID, fg=FG_MAIN,
                  relief="flat", font=FONT_SMALL,
                  command=self._edit_profile).pack(side="right", padx=(4, 0))
        tk.Button(right, text="Dup", bg=BG_MID, fg=FG_MAIN,
                  relief="flat", font=FONT_SMALL,
                  command=self._dup_profile).pack(side="right", padx=(4, 0))

        self._profile_var = tk.StringVar()
        self._profile_menu = ttk.Combobox(
            right, textvariable=self._profile_var,
            values=self._profiles, state="readonly",
            font=FONT_BODY, width=28,
        )
        self._profile_menu.pack(side="right", padx=(0, 8))
        self._profile_menu.bind("<<ComboboxSelected>>", self._on_profile_selected)

    def _build_tabs(self):
        # Tab bar — taller, larger font, clear active indicator
        tab_bar = tk.Frame(self, bg=BG_CARD, height=48)
        tab_bar.pack(fill="x")
        tab_bar.pack_propagate(False)

        self._tab_btns = {}
        self._active_tab = TAB_BACKUP
        for key, label in [
            (TAB_BACKUP,     "  Backup  "),
            (TAB_RESTORE,    "  Restore  "),
            (TAB_AUDIT,      "  Audit  "),
            (TAB_SCHEDULER,  "  Schedule  "),
            (TAB_CLOUD_SYNC, "  Cloud Sync  "),
            (TAB_SETTINGS,   "  Settings  "),
            (TAB_HELP,       "  Help  "),
        ]:
            btn = tk.Button(
                tab_bar, text=label, bg=BG_CARD, fg=FG_DIM,
                relief="flat", font=FONT_HEAD, padx=4, pady=0,
                bd=0, highlightthickness=0,
                command=lambda k=key: self._switch_tab(k),
            )
            btn.pack(side="left", fill="y")
            self._tab_btns[key] = btn

        # Tab content frames
        self._tab_backup     = BackupTab(self,     self)
        self._tab_restore    = RestoreTab(self,    self)
        self._tab_audit      = AuditTab(self,      self)
        self._tab_scheduler  = SchedulerTab(self,  self)
        self._tab_cloud_sync = CloudSyncTab(self,  self)
        self._tab_settings   = SettingsTab(self,   self)
        self._tab_settings.load(self._cfg)
        self._tab_help       = HelpTab(self)

        self._switch_tab(TAB_BACKUP)

    def _switch_tab(self, key: str) -> None:
        for k, btn in self._tab_btns.items():
            active = (k == key)
            btn.configure(
                bg=BG_DEEP     if active else BG_CARD,
                fg=ACCENT      if active else FG_DIM,
                font=(*FONT_HEAD[:2], "bold") if active else FONT_HEAD,
                relief="flat",
            )
        for frame in (self._tab_backup, self._tab_restore, self._tab_audit,
                      self._tab_scheduler, self._tab_cloud_sync,
                      self._tab_settings, self._tab_help):
            frame.pack_forget()

        tab_map = {
            TAB_BACKUP:     self._tab_backup,
            TAB_RESTORE:    self._tab_restore,
            TAB_AUDIT:      self._tab_audit,
            TAB_SCHEDULER:  self._tab_scheduler,
            TAB_CLOUD_SYNC: self._tab_cloud_sync,
            TAB_SETTINGS:   self._tab_settings,
            TAB_HELP:       self._tab_help,
        }
        tab_map[key].pack(fill="both", expand=True)
        self._active_tab = key
        # Refresh scheduler view when switching to it
        if key == TAB_SCHEDULER:
            self._tab_scheduler.refresh()

    # ------------------------------------------------------------------
    # Profile management
    # ------------------------------------------------------------------

    def _load_last_profile(self) -> None:
        # First run — no profiles exist → launch setup wizard
        if not self._profiles:
            self.after(200, self._run_wizard)
            return

        last = self._cfg.get("app", "last_profile", fallback="")
        if last and last in self._profiles:
            self._profile_var.set(last)
            self._load_profile(last)
        elif self._profiles:
            self._profile_var.set(self._profiles[0])
            self._load_profile(self._profiles[0])

    def _run_wizard(self) -> None:
        dlg = SetupWizard(self)
        self.wait_window(dlg)
        if dlg.result:
            profile_manager.save_profile(dlg.result)
            self._refresh_profile_list(dlg.result["name"])

    def _load_profile(self, name: str) -> None:
        p = profile_manager.load_profile(name)
        if p:
            self._current_profile = p
            self._tab_backup.refresh(p)
            self._tab_settings.load_profile(p)
            self.title(f"Smack Up Your Backup  —  {p['name']}  (v{BUILD_VERSION})")

    def _on_profile_selected(self, _event=None) -> None:
        name = self._profile_var.get()
        if name:
            self._load_profile(name)

    def _new_profile(self) -> None:
        dlg = ProfileDialog(self, title="New Blog Profile")
        self.wait_window(dlg)
        if dlg.result:
            profile_manager.save_profile(dlg.result)
            self._refresh_profile_list(dlg.result["name"])

    def _edit_profile(self) -> None:
        if not self._current_profile:
            messagebox.showinfo("No profile", "Select a profile first.")
            return
        dlg = ProfileDialog(self, profile=self._current_profile, title="Edit Profile")
        self.wait_window(dlg)
        if dlg.result:
            profile_manager.save_profile(dlg.result)
            self._current_profile = dlg.result
            self._tab_settings.load_profile(dlg.result)
            self._refresh_profile_list(dlg.result["name"])

    def _dup_profile(self) -> None:
        if not self._current_profile:
            return
        name = self._current_profile["name"]
        new_name = f"{name} (copy)"
        profile_manager.duplicate_profile(name, new_name)
        self._refresh_profile_list(new_name)

    def _refresh_profile_list(self, select: str = "") -> None:
        self._profiles = profile_manager.list_profiles()
        self._profile_menu.configure(values=self._profiles)
        if select and select in self._profiles:
            self._profile_var.set(select)
            self._load_profile(select)

    # ------------------------------------------------------------------
    # Message pump
    # ------------------------------------------------------------------

    def _poll(self) -> None:
        try:
            while True:
                msg = self._msg_queue.get_nowait()
                kind = msg[0]

                if kind == "backup_progress":
                    self._tab_backup.on_progress(msg[1], msg[2])
                elif kind == "backup_log":
                    self._tab_backup.on_log(msg[1])
                elif kind == "backup_stats":
                    self._tab_backup.on_stats(*msg[1:])
                elif kind == "backup_ask":
                    # Engine is blocked waiting for a response — show dialog on main thread
                    engine = self._tab_backup._engine
                    if engine:
                        dlg = tk.Toplevel(self)
                        dlg.title("Download failure")
                        dlg.configure(bg=BG_MID)
                        dlg.resizable(False, False)
                        dlg.grab_set()
                        dlg.focus_force()

                        tk.Label(dlg, text=msg[1], bg=BG_MID, fg=FG_MAIN,
                                 font=FONT_BODY, justify="left",
                                 wraplength=420, padx=20, pady=16).pack()

                        btn_row = tk.Frame(dlg, bg=BG_MID, pady=12)
                        btn_row.pack()

                        result_holder = [None]

                        def _abort():
                            result_holder[0] = False
                            dlg.destroy()

                        def _continue():
                            result_holder[0] = True
                            dlg.destroy()

                        tk.Button(btn_row, text="Abort Backup", font=FONT_BODY,
                                  bg=FG_ERR, fg="white", relief="flat",
                                  padx=16, pady=6, cursor="hand2",
                                  command=_abort).pack(side="left", padx=(0, 8))
                        tk.Button(btn_row, text="Continue Anyway", font=FONT_BODY,
                                  bg=BG_CARD, fg=FG_DIM, relief="flat",
                                  padx=16, pady=6, cursor="hand2",
                                  command=_continue).pack(side="left")

                        dlg.protocol("WM_DELETE_WINDOW", _abort)
                        self.wait_window(dlg)

                        if result_holder[0]:
                            engine.prompt_continue()
                        else:
                            engine.cancel()
                elif kind == "backup_done":
                    result = msg[1]
                    self._tab_backup.on_done(result)
                    if result.get("success") and self._current_profile:
                        from datetime import datetime, timezone
                        self._current_profile["last_backup_date"] = \
                            datetime.now(timezone.utc).isoformat()
                        profile_manager.save_profile(self._current_profile)
                        self._tab_backup.refresh(self._current_profile)
                    self._tab_scheduler.refresh()

                elif kind == "backup_done_queued":
                    result = msg[1]
                    self._tab_backup.on_done_queued(result)
                    if result.get("success"):
                        pname = result.get("_profile_name", "")
                        p = profile_manager.load_profile(pname)
                        if p:
                            from datetime import datetime, timezone
                            p["last_backup_date"] = datetime.now(timezone.utc).isoformat()
                            profile_manager.save_profile(p)
                    self._tab_scheduler.refresh()

                elif kind == "restore_progress":
                    self._tab_restore.on_progress(msg[1], msg[2])
                elif kind == "restore_log":
                    self._tab_restore.on_log(msg[1])
                elif kind == "restore_done":
                    self._tab_restore.on_done(msg[1])

                elif kind == "audit_progress":
                    self._tab_audit.on_progress(msg[1], msg[2])
                elif kind == "audit_log":
                    pass   # Audit log goes to results pane via on_done
                elif kind == "audit_done":
                    self._tab_audit.on_done(msg[1])

                elif kind == "sync_progress":
                    self._tab_cloud_sync.on_progress(msg[1])
                elif kind == "sync_log":
                    self._tab_cloud_sync.on_log(msg[1])
                elif kind == "sync_stats":
                    self._tab_cloud_sync.on_stats(*msg[1:])
                elif kind == "sync_ask":
                    engine = self._tab_cloud_sync._engine
                    if engine:
                        dlg = tk.Toplevel(self)
                        dlg.title("Sync failure")
                        dlg.configure(bg=BG_MID)
                        dlg.resizable(False, False)
                        dlg.grab_set()
                        dlg.focus_force()

                        tk.Label(dlg, text=msg[1], bg=BG_MID, fg=FG_MAIN,
                                 font=FONT_BODY, justify="left",
                                 wraplength=420, padx=20, pady=16).pack()

                        btn_row = tk.Frame(dlg, bg=BG_MID, pady=12)
                        btn_row.pack()
                        result_holder = [None]

                        def _abort():
                            result_holder[0] = False
                            dlg.destroy()

                        def _continue():
                            result_holder[0] = True
                            dlg.destroy()

                        tk.Button(btn_row, text="Abort Sync", font=FONT_BODY,
                                  bg=FG_ERR, fg="white", relief="flat",
                                  padx=16, pady=6, cursor="hand2",
                                  command=_abort).pack(side="left", padx=(0, 8))
                        tk.Button(btn_row, text="Continue Anyway", font=FONT_BODY,
                                  bg=BG_CARD, fg=FG_DIM, relief="flat",
                                  padx=16, pady=6, cursor="hand2",
                                  command=_continue).pack(side="left")

                        dlg.protocol("WM_DELETE_WINDOW", _abort)
                        self.wait_window(dlg)

                        if result_holder[0]:
                            engine.prompt_continue()
                        else:
                            engine.cancel()

                elif kind == "sync_done":
                    self._tab_cloud_sync.on_done(msg[1])

                elif kind == "scheduled_backup":
                    # Triggered by scheduler thread — queue profile for backup
                    profile = msg[1]
                    self._tab_backup._start_scheduled(profile)

                elif kind == "tray_notify":
                    # Show a tray notification if available
                    if self._tray_icon:
                        try:
                            self._tray_icon.notify(msg[1], "Smack Up Your Backup")
                        except Exception:
                            pass

        except queue.Empty:
            pass
        self.after(100, self._poll)

    # ------------------------------------------------------------------
    # Scheduler
    # ------------------------------------------------------------------

    def _get_all_profiles_for_scheduler(self) -> list:
        """Return all loaded profiles for the scheduler to iterate."""
        profiles = []
        for name in profile_manager.list_profiles():
            p = profile_manager.load_profile(name)
            if p:
                profiles.append(p)
        return profiles

    def _on_schedule_trigger(self, profile: dict) -> None:
        """Called by scheduler thread when a backup is due — queues it safely."""
        self.queue_msg(("scheduled_backup", profile))

    # ------------------------------------------------------------------
    # System tray
    # ------------------------------------------------------------------

    def _make_tray_image(self):
        """Create a simple tray icon using Pillow."""
        from PIL import Image, ImageDraw
        size = 64
        img  = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        d    = ImageDraw.Draw(img)
        # Green circle background
        d.ellipse([2, 2, size - 2, size - 2], fill=(93, 234, 93))
        # Dark "S" — simplified as two arcs suggesting the letter
        d.rectangle([18, 14, 46, 26], fill=(26, 26, 34))
        d.rectangle([18, 28, 46, 40], fill=(26, 26, 34))
        d.rectangle([18, 42, 46, 54], fill=(26, 26, 34))
        return img

    def _start_tray(self) -> None:
        """Initialise the system tray icon in its own thread."""
        try:
            import pystray
        except ImportError:
            return

        import threading

        def _show(_icon=None, _item=None):
            self.after(0, self._show_window)

        def _run_now(_icon=None, _item=None):
            self.after(0, lambda: self._tab_backup._start())

        def _quit(_icon=None, _item=None):
            self._quitting = True
            if self._tray_icon:
                self._tray_icon.stop()
            self.after(0, self._do_quit)

        menu = pystray.Menu(
            pystray.MenuItem("Open SUYB", _show, default=True),
            pystray.MenuItem("Back Up Now", _run_now),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("Quit", _quit),
        )
        self._tray_icon = pystray.Icon(
            "suyb", self._make_tray_image(),
            f"Smack Up Your Backup  v{BUILD_VERSION}", menu,
        )
        threading.Thread(target=self._tray_icon.run, daemon=True).start()

    def _show_window(self) -> None:
        self.deiconify()
        self.lift()
        self.focus_force()

    def _hide_to_tray(self) -> None:
        self._save_window_state()
        self.withdraw()

    # ------------------------------------------------------------------
    # Close
    # ------------------------------------------------------------------

    def _save_window_state(self) -> None:
        name = self._profile_var.get()
        if name:
            if not self._cfg.has_section("app"):
                self._cfg.add_section("app")
            self._cfg.set("app", "last_profile", name)
        if not self._cfg.has_section("window"):
            self._cfg.add_section("window")
        win_state = self.state()
        self._cfg.set("window", "state", win_state if win_state != "withdrawn" else "normal")
        if win_state not in ("zoomed", "withdrawn"):
            self._cfg.set("window", "width",  str(self.winfo_width()))
            self._cfg.set("window", "height", str(self.winfo_height()))
        cfg_module.save(self._cfg)

    def _do_quit(self) -> None:
        self._scheduler.stop()
        self.destroy()

    def _on_close(self) -> None:
        # If tray is active and user didn't explicitly quit, minimise to tray
        if self._tray_icon and not self._quitting:
            self._hide_to_tray()
            return
        self._save_window_state()
        self._do_quit()


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def _enforce_single_instance():
    """Prevent more than one copy of SUYB running at the same time.
    On Windows: named mutex.  On Linux/Mac: lock file."""
    import sys, os

    if sys.platform == "win32":
        import ctypes
        mutex = ctypes.windll.kernel32.CreateMutexW(None, False, "SmackUpYourBackup_SingleInstance")
        if ctypes.windll.kernel32.GetLastError() == 183:  # ERROR_ALREADY_EXISTS
            import tkinter as _tk
            from tkinter import messagebox as _mb
            _r = _tk.Tk(); _r.withdraw()
            _mb.showinfo("Already running",
                         "Smack Up Your Backup is already running.\n\n"
                         "Check the system tray if you can't see the window.",
                         parent=_r)
            _r.destroy()
            sys.exit(0)
        # Keep handle alive for process lifetime
        _enforce_single_instance._mutex = mutex
    else:
        import fcntl, tempfile
        lock_path = os.path.join(tempfile.gettempdir(), "suyb.lock")
        _enforce_single_instance._lock_fh = open(lock_path, "w")
        try:
            fcntl.flock(_enforce_single_instance._lock_fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            print("Smack Up Your Backup is already running.")
            sys.exit(0)


if __name__ == "__main__":
    _enforce_single_instance()
    app = App()
    app.mainloop()
