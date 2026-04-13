"""
Smack Up Your Backup — main.py
Multi-blog backup & restore engine for SnapSmack.
Dark UI palette, tkinter, PyInstaller single-exe build chain.
Same visual family as Smack Your Batch Up.
"""

BUILD_VERSION = "0.2.0"

import os
import queue
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, ttk
from typing import Optional

import config as cfg_module
import profile_manager
import manifest_reader
import cloud_manifest as cloud_manifest_module
import cloud_client as cloud_module
from audit_engine import AuditEngine, AuditReport
from backup_engine import BackupEngine
from restore_engine import RestoreEngine
from report_writer import write_txt, write_html


# ---------------------------------------------------------------------------
# Palette — neon-lime-on-dark, same family as SYBU
# ---------------------------------------------------------------------------
BG_DEEP   = "#141414"
BG_MID    = "#1e1e1e"
BG_CARD   = "#242424"
BG_INPUT  = "#2a2a2a"
ACCENT    = "#39FF14"
FG_MAIN   = "#e0e0e0"
FG_DIM    = "#666666"
FG_OK     = "#39FF14"
FG_WARN   = "#f0a500"
FG_ERR    = "#e05050"
BORDER    = "#333333"

FONT_TITLE = ("Segoe UI", 13, "bold")
FONT_HEAD  = ("Segoe UI", 10, "bold")
FONT_BODY  = ("Segoe UI", 9)
FONT_SMALL = ("Segoe UI", 8)
FONT_MONO  = ("Consolas", 9)

TAB_BACKUP  = "backup"
TAB_RESTORE = "restore"
TAB_AUDIT   = "audit"
TAB_SETTINGS= "settings"


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
                 font=FONT_HEAD).grid(row=13, column=0, columnspan=2, sticky="w", pady=(12, 4))
        self._field(f, 14, "Provider (google_drive / onedrive / none)", "cloud_provider")
        self._field(f, 15, "Credentials JSON",     "cloud_credentials_file")
        self._field(f, 16, "Cloud folder ID",      "cloud_folder_id")

        tk.Label(f, text="Backup", bg=BG_MID, fg=ACCENT,
                 font=FONT_HEAD).grid(row=17, column=0, columnspan=2, sticky="w", pady=(12, 4))
        self._field(f, 18, "Local backup directory", "backup_dir")
        self._field(f, 19, "Pacing delay (sec)",    "pacing_delay")
        self._field(f, 20, "Batch size (0=unlimited)", "batch_size")

        # Buttons
        btn_frame = tk.Frame(self, bg=BG_MID)
        btn_frame.pack(fill="x", padx=20, pady=(0, 16))

        tk.Button(btn_frame, text="Browse…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY,
                  command=self._browse_backup_dir).pack(side="left")
        tk.Button(btn_frame, text="Cancel", bg=BG_CARD, fg=FG_DIM,
                  relief="flat", font=FONT_BODY,
                  command=self.destroy).pack(side="right", padx=(8, 0))
        tk.Button(btn_frame, text="Save", bg=ACCENT, fg=BG_DEEP,
                  relief="flat", font=FONT_HEAD,
                  command=self._save).pack(side="right")

    def _browse_backup_dir(self):
        d = filedialog.askdirectory(title="Choose local backup folder")
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
        d = filedialog.askdirectory(title="Choose backup base directory")
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
                parent=self,
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
        sb = ttk.Scrollbar(self, command=self._text.yview)
        self._text.configure(yscrollcommand=sb.set)
        sb.pack(side="right", fill="y")
        self._text.pack(side="left", fill="both", expand=True)

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
        super().__init__(parent, bg=BG_MID, height=4, **kwargs)
        self._fill = tk.Frame(self, bg=ACCENT, height=4)
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
        self._app  = app
        self._busy = False
        self._engine: Optional[BackupEngine] = None
        self._all_queue: list   = []
        self._all_results: list = []
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

        # Progress
        self._prog_bar = ProgressBar(self)
        self._prog_bar.pack(fill="x", padx=16, pady=(0, 2))
        self._prog_lbl = tk.Label(self, text="", bg=BG_DEEP, fg=FG_DIM,
                                   font=FONT_SMALL, anchor="w")
        self._prog_lbl.pack(fill="x", padx=16)

        # Log
        self._log = LogPane(self)
        self._log.pack(fill="both", expand=True, padx=16, pady=8)

        # Options row: backup mode + include settings
        opts_row = tk.Frame(self, bg=BG_DEEP)
        opts_row.pack(fill="x", padx=16, pady=(4, 4))

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

        # Bottom buttons
        btn_row = tk.Frame(self, bg=BG_DEEP)
        btn_row.pack(fill="x", padx=16, pady=(0, 16))
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

        self._busy = True
        self._start_btn.configure(state="disabled")
        self._all_btn.configure(state="disabled")
        self._cancel_btn.configure(state="normal")
        self._prog_bar.reset()
        self._log.clear()
        self._log.append("Starting backup…")

        force_full       = self._backup_mode_var.get() == "full"
        include_settings = self._include_settings_var.get()
        self._engine = BackupEngine(
            profile,
            on_progress=lambda s, m, p: self._app.queue_msg(("backup_progress", m, p)),
            on_log=lambda m: self._app.queue_msg(("backup_log", m)),
            force_full=force_full,
            include_settings=include_settings,
            global_config=self._global_config_dict() if include_settings else None,
            global_cloud=self._app.global_cloud_config(),
        )
        self._all_queue = []  # clear any multi-blog queue
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
        self._log.clear()
        self._log.append(f"Backing up {len(loaded)} blog(s)…")

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
        ok    = sum(1 for r in self._all_results if r.get("success"))
        total = len(self._all_results)
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

    def _cancel(self):
        if self._engine:
            self._engine.cancel()
        self._cancel_btn.configure(state="disabled")

    def on_progress(self, msg: str, pct: float) -> None:
        self._prog_bar.set(pct)
        self._prog_lbl.configure(text=msg, fg=FG_WARN if pct < 1.0 else FG_OK)

    def on_log(self, msg: str) -> None:
        self._log.append(msg)

    def on_done(self, result: dict) -> None:
        self._busy = False
        self._start_btn.configure(state="normal")
        self._all_btn.configure(state="normal")
        self._cancel_btn.configure(state="disabled")
        self._engine = None
        if result["success"]:
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
        p = filedialog.askopenfilename(
            title="Select backup package",
            filetypes=[("ZIP backup", "*.zip"), ("All files", "*.*")],
        )
        if p:
            self._zip_var.set(p)

    def _browse_kit(self):
        p = filedialog.askopenfilename(
            title="Select recovery kit",
            filetypes=[("Recovery kit", "*.tar.gz"), ("All files", "*.*")],
        )
        if p:
            self._kit_var.set(p)

    def _browse_media_dir(self):
        d = filedialog.askdirectory(title="Select media folder")
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
        path = filedialog.asksaveasfilename(
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
    def __init__(self, parent, app, **kwargs):
        super().__init__(parent, bg=BG_DEEP, **kwargs)
        self._app = app
        self._build()

    def _build(self):
        pad = {"padx": 24, "pady": 6}
        self._profile_vars: dict[str, tk.StringVar] = {}

        # ── Site Connection ──────────────────────────────────────────────────
        tk.Label(self, text="Site Connection", bg=BG_DEEP, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w", padx=24, pady=(20, 4))

        site_frame = tk.Frame(self, bg=BG_DEEP)
        site_frame.pack(fill="x", **pad)
        site_frame.columnconfigure(1, weight=1)
        for row, (label, key, show) in enumerate([
            ("Blog name",       "name",            ""),
            ("Site URL",        "site_url",        ""),
            ("Admin username",  "snap_admin_user", ""),
            ("Admin password",  "snap_admin_pass", "●"),
        ]):
            tk.Label(site_frame, text=label, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_SMALL, anchor="w").grid(row=row, column=0, sticky="w", padx=(0, 12), pady=3)
            var = tk.StringVar()
            tk.Entry(site_frame, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                     insertbackground=ACCENT, relief="flat",
                     font=FONT_MONO, width=38, show=show).grid(row=row, column=1, sticky="ew", pady=3)
            self._profile_vars[key] = var

        # ── Backup Method ────────────────────────────────────────────────────
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x", padx=24, pady=(12, 8))
        tk.Label(self, text="Backup Method", bg=BG_DEEP, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w", padx=24, pady=(4, 6))

        method_frame = tk.Frame(self, bg=BG_DEEP)
        method_frame.pack(fill="x", padx=24)

        self._method_var = tk.StringVar(value="ftp")
        for val, label in [
            ("ftp",   "FTP — differential sync from server"),
            ("cloud", "Cloud — push backup to Google Drive / OneDrive"),
            ("local", "Local only — save to disk, no upload"),
        ]:
            tk.Radiobutton(
                method_frame, text=label, variable=self._method_var, value=val,
                bg=BG_DEEP, fg=FG_MAIN, selectcolor=BG_INPUT,
                activebackground=BG_DEEP, font=FONT_BODY,
                command=self._on_method_change,
            ).pack(anchor="w", pady=1)

        # ── FTP fields (shown when method = ftp) ────────────────────────────
        self._ftp_frame = tk.Frame(self, bg=BG_DEEP)
        self._ftp_frame.columnconfigure(1, weight=1)
        for row, (label, key, show) in enumerate([
            ("FTP host",       "ftp_host",       ""),
            ("FTP port",       "ftp_port",       ""),
            ("FTP username",   "ftp_user",       ""),
            ("FTP password",   "ftp_pass",       "●"),
            ("Remote directory","ftp_remote_dir", ""),
        ]):
            tk.Label(self._ftp_frame, text=label, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_SMALL, anchor="w").grid(row=row, column=0, sticky="w", padx=(0, 12), pady=3)
            var = tk.StringVar()
            tk.Entry(self._ftp_frame, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                     insertbackground=ACCENT, relief="flat",
                     font=FONT_MONO, width=38, show=show).grid(row=row, column=1, sticky="ew", pady=3)
            self._profile_vars[key] = var

        # FTP TLS checkbox
        self._ftp_ssl_var = tk.BooleanVar(value=True)
        tk.Checkbutton(self._ftp_frame, text="Use FTP_TLS", variable=self._ftp_ssl_var,
                       bg=BG_DEEP, fg=FG_MAIN, selectcolor=BG_INPUT,
                       activebackground=BG_DEEP, font=FONT_BODY).grid(
            row=5, column=0, columnspan=2, sticky="w", pady=3)
        self._profile_vars["ftp_ssl"] = self._ftp_ssl_var

        # ── Cloud fields (shown when method = cloud) ─────────────────────────
        self._cloud_frame = tk.Frame(self, bg=BG_DEEP)
        self._cloud_frame.columnconfigure(1, weight=1)

        # Cloud provider dropdown
        tk.Label(self._cloud_frame, text="Cloud provider", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_SMALL, anchor="w").grid(row=0, column=0, sticky="w", padx=(0, 12), pady=3)
        cloud_prov_var = tk.StringVar()
        cloud_cb = ttk.Combobox(self._cloud_frame, textvariable=cloud_prov_var,
                                values=["google_drive", "onedrive", "none"],
                                font=FONT_MONO, state="readonly", width=20)
        cloud_cb.grid(row=0, column=1, sticky="w", pady=3)
        self._profile_vars["cloud_provider"] = cloud_prov_var

        for row, (label, key) in enumerate([
            ("Credentials JSON", "cloud_credentials_file"),
            ("Cloud folder ID",  "cloud_folder_id"),
        ], start=1):
            tk.Label(self._cloud_frame, text=label, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_SMALL, anchor="w").grid(row=row, column=0, sticky="w", padx=(0, 12), pady=3)
            var = tk.StringVar()
            tk.Entry(self._cloud_frame, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                     insertbackground=ACCENT, relief="flat",
                     font=FONT_MONO, width=38).grid(row=row, column=1, sticky="ew", pady=3)
            self._profile_vars[key] = var

        cred_btn_row = tk.Frame(self._cloud_frame, bg=BG_DEEP)
        cred_btn_row.grid(row=3, column=0, columnspan=2, sticky="w", pady=4)
        tk.Button(cred_btn_row, text="Browse credentials…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=10, pady=4,
                  command=self._browse_credentials).pack(side="left")

        # ── Local backup directory (always shown) ────────────────────────────
        self._local_frame = tk.Frame(self, bg=BG_DEEP)
        self._local_frame.columnconfigure(1, weight=1)
        tk.Label(self._local_frame, text="Backup directory", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_SMALL, anchor="w").grid(row=0, column=0, sticky="w", padx=(0, 12), pady=3)
        bkdir_var = tk.StringVar()
        tk.Entry(self._local_frame, textvariable=bkdir_var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=38).grid(row=0, column=1, sticky="ew", pady=3)
        self._profile_vars["backup_dir"] = bkdir_var
        tk.Button(self._local_frame, text="Browse…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=10, pady=4,
                  command=self._browse_backup_dir).grid(row=0, column=2, padx=(6, 0), pady=3)

        # ── Save button ─────────────────────────────────────────────────────
        prof_btn_row = tk.Frame(self, bg=BG_DEEP)
        prof_btn_row.pack(anchor="w", padx=24, pady=(8, 4))
        tk.Button(prof_btn_row, text="Save Profile", bg=ACCENT, fg=BG_DEEP,
                  relief="flat", font=FONT_HEAD, padx=14, pady=6,
                  command=self._save_profile).pack(side="left")

        self._no_profile_lbl = tk.Label(self, text="No profile selected — use the dropdown at top-right.",
                                         bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL)

        # Show FTP fields by default
        self._on_method_change()

        # ── Separator ────────────────────────────────────────────────────────
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x", padx=24, pady=(8, 12))

        # ── Global Defaults ──────────────────────────────────────────────────
        tk.Label(self, text="Global Defaults", bg=BG_DEEP, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w", padx=24, pady=(4, 4))

        frame = tk.Frame(self, bg=BG_DEEP)
        frame.pack(fill="x", **pad)
        frame.columnconfigure(1, weight=1)

        self._delay_var = tk.StringVar()
        self._batch_var = tk.StringVar()

        for row, (label, var) in enumerate([
            ("Default pacing delay (sec)", self._delay_var),
            ("Default batch size (0=unlimited)", self._batch_var),
        ]):
            tk.Label(frame, text=label, bg=BG_DEEP, fg=FG_DIM,
                     font=FONT_SMALL, anchor="w").grid(row=row, column=0, sticky="w", padx=(0, 12), pady=3)
            tk.Entry(frame, textvariable=var, bg=BG_INPUT, fg=FG_MAIN,
                     insertbackground=ACCENT, relief="flat",
                     font=FONT_MONO, width=10).grid(row=row, column=1, sticky="w", pady=3)

        # ── Global Cloud Config ─────────────────────────────────────────────
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x", padx=24, pady=(8, 8))
        tk.Label(self, text="Global Cloud Config", bg=BG_DEEP, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w", padx=24, pady=(4, 4))
        tk.Label(self, text="Shared cloud settings used by all profiles unless\n"
                            "overridden per-profile above. Service account JSON\n"
                            "key stays on this machine — never uploaded.",
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                 justify="left").pack(anchor="w", padx=24, pady=(0, 6))

        gc_frame = tk.Frame(self, bg=BG_DEEP)
        gc_frame.pack(fill="x", **pad)
        gc_frame.columnconfigure(1, weight=1)

        # Provider dropdown
        tk.Label(gc_frame, text="Cloud provider", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_SMALL, anchor="w").grid(row=0, column=0, sticky="w", padx=(0, 12), pady=3)
        self._gc_provider_var = tk.StringVar()
        gc_prov_cb = ttk.Combobox(gc_frame, textvariable=self._gc_provider_var,
                                  values=["google_drive", "onedrive", "none"],
                                  font=FONT_MONO, state="readonly", width=20)
        gc_prov_cb.grid(row=0, column=1, sticky="w", pady=3)

        # Service account key file
        tk.Label(gc_frame, text="Service account key", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_SMALL, anchor="w").grid(row=1, column=0, sticky="w", padx=(0, 12), pady=3)
        self._gc_creds_var = tk.StringVar()
        tk.Entry(gc_frame, textvariable=self._gc_creds_var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=38).grid(row=1, column=1, sticky="ew", pady=3)
        tk.Button(gc_frame, text="Browse…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_SMALL, padx=8, pady=2,
                  command=self._browse_global_key).grid(row=1, column=2, padx=(6, 0), pady=3)

        # Key status label
        self._gc_key_status_var = tk.StringVar(value="")
        tk.Label(gc_frame, textvariable=self._gc_key_status_var,
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                 anchor="w").grid(row=2, column=1, sticky="w", pady=(0, 3))

        # Folder ID
        tk.Label(gc_frame, text="Drive folder ID", bg=BG_DEEP, fg=FG_DIM,
                 font=FONT_SMALL, anchor="w").grid(row=3, column=0, sticky="w", padx=(0, 12), pady=3)
        self._gc_folder_var = tk.StringVar()
        tk.Entry(gc_frame, textvariable=self._gc_folder_var, bg=BG_INPUT, fg=FG_MAIN,
                 insertbackground=ACCENT, relief="flat",
                 font=FONT_MONO, width=38).grid(row=3, column=1, sticky="ew", pady=3)

        tk.Button(self, text="Save Defaults", bg=ACCENT, fg=BG_DEEP,
                  relief="flat", font=FONT_HEAD, padx=14, pady=6,
                  command=self._save).pack(anchor="w", padx=24, pady=12)

        # ── AI Matcher ───────────────────────────────────────────────────────
        tk.Label(self, text="AI File Matching", bg=BG_DEEP, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w", padx=24, pady=(16, 4))

        ai_frame = tk.Frame(self, bg=BG_DEEP)
        ai_frame.pack(fill="x", padx=24, pady=4)

        self._ai_status_var = tk.StringVar(value="Checking…")
        tk.Label(ai_frame, textvariable=self._ai_status_var,
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                 wraplength=480, justify="left").pack(anchor="w")

        tk.Label(ai_frame,
                 text="When installed, ambiguous file matches are resolved using\n"
                      "semantic path similarity (all-MiniLM-L6-v2, ~90 MB, CPU only).",
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                 justify="left").pack(anchor="w", pady=(4, 8))

        tk.Button(ai_frame, text="Install  (pip install sentence-transformers)",
                  bg=BG_INPUT, fg=FG_MAIN, relief="flat", font=FONT_SMALL,
                  padx=10, pady=4,
                  command=self._install_ai).pack(anchor="w")

        # ── Hub / Spoke Discovery ────────────────────────────────────────────
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x", padx=24, pady=(16, 8))
        tk.Label(self, text="Hub / Spoke Discovery", bg=BG_DEEP, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w", padx=24, pady=(4, 4))

        tk.Label(self, text="Connect to a hub blog to auto-discover all spokes and\n"
                            "create profiles for the entire network. Cloud config is\n"
                            "pulled from each spoke automatically when available.",
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                 justify="left").pack(anchor="w", padx=24, pady=(0, 8))

        disc_row = tk.Frame(self, bg=BG_DEEP)
        disc_row.pack(anchor="w", padx=24, pady=(0, 4))
        tk.Button(disc_row, text="Discover from Hub…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=12, pady=6,
                  command=self._discover_from_hub).pack(side="left")
        tk.Button(disc_row, text="Pull Cloud Config", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=12, pady=6,
                  command=self._pull_cloud_config).pack(side="left", padx=(10, 0))

        self._disc_status_var = tk.StringVar(value="")
        tk.Label(self, textvariable=self._disc_status_var,
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                 wraplength=480, justify="left").pack(anchor="w", padx=24, pady=(0, 8))

        # ── Export / Import ──────────────────────────────────────────────────
        tk.Frame(self, bg=BORDER, height=1).pack(fill="x", padx=24, pady=(16, 8))
        tk.Label(self, text="Export / Import Settings", bg=BG_DEEP, fg=ACCENT,
                 font=FONT_HEAD).pack(anchor="w", padx=24, pady=(4, 4))

        tk.Label(self, text="Export all profiles and global settings to a single JSON file,\n"
                            "or restore from a previously exported file.",
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL,
                 justify="left").pack(anchor="w", padx=24, pady=(0, 8))

        xp_row = tk.Frame(self, bg=BG_DEEP)
        xp_row.pack(anchor="w", padx=24, pady=(0, 12))
        tk.Button(xp_row, text="Export All Settings…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=12, pady=6,
                  command=self._export_settings).pack(side="left")
        tk.Button(xp_row, text="Import Settings…", bg=BG_CARD, fg=FG_MAIN,
                  relief="flat", font=FONT_BODY, padx=12, pady=6,
                  command=self._import_settings).pack(side="left", padx=(10, 0))

        # Version
        tk.Label(self, text=f"Smack Up Your Backup  v{BUILD_VERSION}",
                 bg=BG_DEEP, fg=FG_DIM, font=FONT_SMALL).pack(
            side="bottom", anchor="e", padx=24, pady=12)

    def load(self, cfg) -> None:
        self._delay_var.set(cfg.get("pacing", "transfer_delay", fallback="2"))
        self._batch_var.set(cfg.get("pacing", "batch_size",     fallback="0"))
        # Global cloud config
        self._gc_provider_var.set(cfg.get("cloud", "provider", fallback="google_drive"))
        self._gc_creds_var.set(cfg.get("cloud", "credentials_file", fallback=""))
        self._gc_folder_var.set(cfg.get("cloud", "folder_id", fallback=""))
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
            self._ftp_frame.pack(fill="x", padx=24, pady=(8, 0))
        elif method == "cloud":
            self._cloud_frame.pack(fill="x", padx=24, pady=(8, 0))
        # Local backup directory is always shown
        self._local_frame.pack(fill="x", padx=24, pady=(8, 0))

    def _browse_credentials(self) -> None:
        path = filedialog.askopenfilename(
            title="Select credentials JSON",
            filetypes=[("JSON files", "*.json"), ("All files", "*.*")],
        )
        if path and "cloud_credentials_file" in self._profile_vars:
            self._profile_vars["cloud_credentials_file"].set(path)

    def load_profile(self, profile: Optional[dict]) -> None:
        """Populate the active profile fields from a profile dict, or clear them."""
        if profile:
            for key, var in self._profile_vars.items():
                if key == "ftp_ssl":
                    var.set(bool(profile.get(key, True)))
                else:
                    var.set(str(profile.get(key, "")))
            # Set method radio based on what's filled in
            cloud_prov = profile.get("cloud_provider", "none")
            if cloud_prov and cloud_prov != "none":
                self._method_var.set("cloud")
            elif profile.get("ftp_host"):
                self._method_var.set("ftp")
            else:
                self._method_var.set("local")
            self._on_method_change()
            self._no_profile_lbl.pack_forget()
        else:
            for key, var in self._profile_vars.items():
                if key == "ftp_ssl":
                    var.set(True)
                else:
                    var.set("")
            self._method_var.set("ftp")
            self._on_method_change()

    def _save_profile(self) -> None:
        """Write the profile fields back to disk."""
        profile = self._app._current_profile
        if not profile:
            messagebox.showwarning("No profile", "Select a profile first.", parent=self)
            return
        for key, var in self._profile_vars.items():
            val = var.get()
            # Preserve int types for port/delay/batch
            if key in ("ftp_port", "pacing_delay", "batch_size"):
                try:
                    val = int(val)
                except ValueError:
                    pass
            elif key == "ftp_ssl":
                val = bool(val)
            profile[key] = val

        # If method is "local", clear cloud provider so engine skips cloud push
        method = self._method_var.get()
        if method == "local":
            profile["cloud_provider"] = "none"

        profile_manager.save_profile(profile)
        messagebox.showinfo("Saved", f"Profile \"{profile['name']}\" saved.", parent=self)

    def _browse_backup_dir(self) -> None:
        d = filedialog.askdirectory(title="Choose local backup folder")
        if d and "backup_dir" in self._profile_vars:
            self._profile_vars["backup_dir"].set(d)

    def _refresh_ai_status(self):
        import ai_matcher
        self._ai_status_var.set(ai_matcher.status_string())

    def _install_ai(self):
        import subprocess, sys, threading
        self._ai_status_var.set("Installing…")
        def _run():
            try:
                subprocess.check_call(
                    [sys.executable, "-m", "pip", "install", "sentence-transformers"],
                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                )
            except Exception:
                pass
            self.after(0, self._refresh_ai_status)
        threading.Thread(target=_run, daemon=True).start()

    def _export_settings(self):
        """Export all profiles + global config to a single JSON file."""
        import json
        path = filedialog.asksaveasfilename(
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
        path = filedialog.askopenfilename(
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
                parent=self,
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
        """File picker for the global service account JSON key."""
        path = filedialog.askopenfilename(
            title="Select service account JSON key",
            filetypes=[("JSON files", "*.json"), ("All files", "*.*")],
        )
        if path:
            self._gc_creds_var.set(path)
            self._validate_global_key()

    def _validate_global_key(self) -> None:
        """Check whether the global key file is a valid service account key."""
        import cloud_client as cc
        path = self._gc_creds_var.get().strip()
        if not path:
            self._gc_key_status_var.set("")
            return
        if cc._is_service_account_key(path):
            self._gc_key_status_var.set("Valid service account key")
        elif os.path.isfile(path):
            self._gc_key_status_var.set("Not a service account key — OAuth will be used")
        else:
            self._gc_key_status_var.set("File not found")

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

        # Restore window geometry
        try:
            w = int(self._cfg.get("window", "width",  fallback="1100"))
            h = int(self._cfg.get("window", "height", fallback="920"))
            self.geometry(f"{w}x{h}")
        except Exception:
            self.geometry("1100x920")

        self._build_ui()
        self._load_last_profile()
        self.after(100, self._poll)
        self.protocol("WM_DELETE_WINDOW", self._on_close)

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
        # Tab bar
        tab_bar = tk.Frame(self, bg=BG_MID, height=38)
        tab_bar.pack(fill="x")
        tab_bar.pack_propagate(False)

        self._tab_btns = {}
        self._active_tab = TAB_BACKUP
        for key, label in [
            (TAB_BACKUP,   "Backup"),
            (TAB_RESTORE,  "Restore"),
            (TAB_AUDIT,    "Audit"),
            (TAB_SETTINGS, "Settings"),
        ]:
            btn = tk.Button(
                tab_bar, text=label, bg=BG_MID, fg=FG_DIM,
                relief="flat", font=FONT_BODY, padx=16,
                command=lambda k=key: self._switch_tab(k),
            )
            btn.pack(side="left", fill="y")
            self._tab_btns[key] = btn

        # Tab content frames
        self._tab_backup   = BackupTab(self,  self)
        self._tab_restore  = RestoreTab(self, self)
        self._tab_audit    = AuditTab(self,   self)
        self._tab_settings = SettingsTab(self, self)
        self._tab_settings.load(self._cfg)

        self._switch_tab(TAB_BACKUP)

    def _switch_tab(self, key: str) -> None:
        for k, btn in self._tab_btns.items():
            btn.configure(
                bg=BG_DEEP if k == key else BG_MID,
                fg=ACCENT   if k == key else FG_DIM,
            )
        for frame in (self._tab_backup, self._tab_restore,
                      self._tab_audit, self._tab_settings):
            frame.pack_forget()

        tab_map = {
            TAB_BACKUP:   self._tab_backup,
            TAB_RESTORE:  self._tab_restore,
            TAB_AUDIT:    self._tab_audit,
            TAB_SETTINGS: self._tab_settings,
        }
        tab_map[key].pack(fill="both", expand=True)
        self._active_tab = key

    # ------------------------------------------------------------------
    # Profile management
    # ------------------------------------------------------------------

    def _load_last_profile(self) -> None:
        last = self._cfg.get("app", "last_profile", fallback="")
        if last and last in self._profiles:
            self._profile_var.set(last)
            self._load_profile(last)
        elif self._profiles:
            self._profile_var.set(self._profiles[0])
            self._load_profile(self._profiles[0])

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
                elif kind == "backup_done":
                    result = msg[1]
                    self._tab_backup.on_done(result)
                    if result.get("success") and self._current_profile:
                        from datetime import datetime, timezone
                        self._current_profile["last_backup_date"] = \
                            datetime.now(timezone.utc).isoformat()
                        profile_manager.save_profile(self._current_profile)
                        self._tab_backup.refresh(self._current_profile)

                elif kind == "backup_done_queued":
                    result = msg[1]
                    self._tab_backup.on_done_queued(result)
                    # Update last_backup_date for this profile
                    if result.get("success"):
                        pname = result.get("_profile_name", "")
                        p = profile_manager.load_profile(pname)
                        if p:
                            from datetime import datetime, timezone
                            p["last_backup_date"] = datetime.now(timezone.utc).isoformat()
                            profile_manager.save_profile(p)

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

        except queue.Empty:
            pass
        self.after(100, self._poll)

    # ------------------------------------------------------------------
    # Close
    # ------------------------------------------------------------------

    def _on_close(self) -> None:
        name = self._profile_var.get()
        if name:
            if not self._cfg.has_section("app"):
                self._cfg.add_section("app")
            self._cfg.set("app", "last_profile", name)
        if not self._cfg.has_section("window"):
            self._cfg.add_section("window")
        self._cfg.set("window", "width",  str(self.winfo_width()))
        self._cfg.set("window", "height", str(self.winfo_height()))
        cfg_module.save(self._cfg)
        self.destroy()


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    app = App()
    app.mainloop()
