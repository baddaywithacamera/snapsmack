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

BUILD_VERSION = "0.1.12"

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
    import snap_prompts
    import snap_prompt_sync
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
    ("SMACK YOUR BATCH UP", "batch poster",         [os.path.join(_R, "sybu", "sybu.exe")]),
    ("GET YOUR SHIT SORTED", "offline sorter",      [os.path.join(_R, "gyss", "GET YOUR SHIT SORTED.exe")]),
    ("COLD SNAP",           "offline poster",       [os.path.join(_R, "coldsnap", "coldsnap.exe")]),
    ("SMACK UP YOUR BACKUP", "backup",              [os.path.join(_R, "suyb", "suyb.exe")]),
    ("OH SNAP",             "skin designer",        [os.path.join(_R, "ohsnap", "oh-snap.exe")]),
    ("SMACK YOUR MOUTH",    "comments: mod + reply", [os.path.join(_R, "smack-your-mouth", "smackmouth.exe")]),
    ("SHOTS FIRED",         "schedule board",       [os.path.join(_R, "shots-fired", "shots-fired.exe")]),
    ("CRONOMETER",          "fleet cron health",    [os.path.join(_R, "cronometer", "cronometer.exe")]),
]


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
        self._gyss_keys = {}          # site_url -> minted gyss key (cached per run)
        self._build_launcher(body)
        self._build_setup(body)
        self._build_profiles(body)
        self._build_prompts(body)
        self._load_creds()
        self._refresh_profiles()
        self._refresh_prompt_sites()

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
            self._hoverize(btn)
            tk.Label(cell, text=(sub if exe else "not installed"),
                     bg=CARD, fg=DIM, font=("Segoe UI", 8)).pack(anchor="w", pady=(2, 0))

    def _on_launch(self, path, name):
        ok, err = _launch(path)
        if not ok:
            messagebox.showerror("Launch failed", f"{name}\n\n{err}", parent=self)

    # ── shared setup ─────────────────────────────────────────────────────────
    def _field(self, parent, label, key, show=None, browse=False, test=None):
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
        card = self._card(parent, "HUB SETUP  ·  set once, every tool has it")
        self._field(card, "HUB SITE URL",   "hub_url")
        self._field(card, "HUB API KEY",    "hub_key", show="•", test=self._test_hub)
        self._field(card, "GEMINI API KEY", "gemini_api_key", show="•", test=self._test_gemini)
        self._field(card, "GOOGLE DRIVE CREDENTIALS (json)", "google_credentials", browse=True, test=self._test_drive)
        self._field(card, "BACKUP FOLDER ID", "drive_folder_id")
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

    def _load_creds(self):
        for key, var in self._creds_vars.items():
            try:
                var.set(snap_creds.get(key, ""))
            except Exception:
                pass

    def _save_typed_creds(self):
        """Persist locally-typed secrets to the shared vault. Returns the count."""
        n = 0
        for key, var in self._creds_vars.items():
            val = var.get().strip()
            if val:
                snap_creds.set(key, val); n += 1
        return n

    def _on_save_creds(self):
        try:
            n = self._save_typed_creds()
            self._setup_status.configure(text=f"✓ {n} credential(s) saved to shared vault", fg=ACCENT)
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
        except Exception:
            pass
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
        self._setup_status.configure(text=f"✓ saved + {n} site(s) into the shared store", fg=ACCENT)

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
        from the full hub->spoke key (extras.api_key_local), cached per run."""
        site = (profile.get("site_url") or "").rstrip("/")
        if self._gyss_keys.get(site):
            return self._gyss_keys[site]
        akl = ((profile.get("extras") or {}).get("api_key_local") or "").strip()
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
                                  "User-Agent": "SnapSmackHub/1.0"}, timeout=25)
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
                                   "User-Agent": "SnapSmackHub/1.0"}, timeout=25)
        if r.status_code != 200:
            return False
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
    Hub().mainloop()
# ===== SNAPSMACK EOF =====
