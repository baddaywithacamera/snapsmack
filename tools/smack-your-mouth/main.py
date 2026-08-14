"""
SMACK YOUR MOUTH — offline fleet comment moderation + replies.

The inbound twin of COLD SNAP. Where COLD SNAP composes posts offline and pushes
them OUT to the fleet, SMACK YOUR MOUTH pulls each site's comments IN, lets the
operator moderate (approve / delete / mark-spam) and write replies with NO
network, then syncs the decisions + replies back on the next connection — with
positive verification, so a decision is only "done" once the server confirms it.

This decouples moderation from short connection windows: pull in one 10-minute
burst, work the queue offline for as long as you like, sync in the next burst.

This file is the desktop shell: a CONNECTION / FLEET panel (fleet from the shared
profiles THE HUB discovered, plus an optional single-site connection), a comment
queue with per-comment moderate + reply controls, and a SYNC button. The engine
(moderation_offline) and transport (moderation_api) do the real work.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


BUILD_VERSION = "0.1.0"

# ---------------------------------------------------------------------------
# Debug log — redirect stdout/stderr to the shared log before any other import,
# so library warnings are captured too. Copied from COLD SNAP's _setup_log.
# ---------------------------------------------------------------------------
import os
import sys


def _setup_log() -> str:
    """Open the SMACK YOUR MOUTH debug log. Prefers the shared
    C:\\snapsmack\\logs\\smackmouth_debug_<ts>.log; falls back to a next-to-exe file
    when the shared home isn't reachable. Returns the path."""
    if getattr(sys, "frozen", False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    log_path = os.path.join(base, "smackmouth-debug.log")
    try:
        _sd = os.path.join(base, "..", "_shared")
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
        import snap_home
        log_path = snap_home.log_path("smackmouth", "debug")
    except Exception:
        pass
    try:
        _lf = open(log_path, "a", encoding="utf-8", buffering=1)
        import datetime
        _lf.write(f"\n{'='*60}\n"
                  f"  SMACK YOUR MOUTH {BUILD_VERSION}  —  "
                  f"{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n"
                  f"{'='*60}\n")
        sys.stdout = _lf
        sys.stderr = _lf
    except Exception:
        pass
    return log_path


LOG_PATH = _setup_log()

import threading
import tkinter as tk
from tkinter import filedialog, messagebox
from typing import Dict, List, Optional

import config as cfg_module
import ui

# The engine + transport + fleet are guarded so a missing optional dep (requests,
# a shared module) can never stop the shell from launching — it just reports why.
try:
    import fleet as fleet_module
    import moderation_offline as mo
    from moderation_api import MouthConnection, MouthPoster
    _ENGINE_OK = True
    _ENGINE_ERR = ""
except Exception as _eng_err:  # pragma: no cover - import shim
    _ENGINE_OK = False
    _ENGINE_ERR = str(_eng_err)


WIN_W, WIN_H = 1120, 780


class App(tk.Tk):

    def __init__(self):
        super().__init__()
        self.title(f"SMACK YOUR MOUTH  —  build {BUILD_VERSION}")
        self.geometry(f"{WIN_W}x{WIN_H}")
        self.minsize(960, 640)
        self.configure(bg=ui.BG_DEEP)

        self._install_clipboard_bindings()
        self._set_icon()

        # State.
        self._config = cfg_module.load()
        self._url_var       = tk.StringVar(value=self._config.get("url", ""))
        self._api_key_var   = tk.StringVar(value=self._config.get("api_key", ""))
        self._author_var    = tk.StringVar(value=self._config.get("reply_author", "SnapSmack"))
        self._session_var   = tk.StringVar()
        self._status_var    = tk.StringVar(value="Ready.")

        self._fleet: List["fleet_module.SiteEntry"] = []
        self._store = mo.SessionStore() if _ENGINE_OK else None
        self._session = None
        # Per-row widget refs, keyed by item_id: {"reply": Text, "status": Label}.
        self._row_widgets: Dict[str, dict] = {}
        self._busy = False

        self._build_ui()
        if _ENGINE_OK:
            self._boot_session()
            self._refresh_fleet()
        else:
            self._set_status(f"Engine failed to import: {_ENGINE_ERR}", ui.FG_ERR)

    # ------------------------------------------------------------------
    # Chrome: clipboard + icon (copied from COLD SNAP)
    # ------------------------------------------------------------------

    def _install_clipboard_bindings(self):
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

    def _set_icon(self):
        try:
            if getattr(sys, "frozen", False):
                _ico = os.path.join(sys._MEIPASS, "assets", "smackmouth.ico")
            else:
                _ico = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                    "assets", "smackmouth.ico")
            if os.path.exists(_ico):
                self.iconbitmap(_ico)
        except Exception:
            pass

    # ------------------------------------------------------------------
    # UI construction
    # ------------------------------------------------------------------

    def _build_ui(self):
        # ── CONNECTION / FLEET panel ──────────────────────────────────
        top = tk.Frame(self, bg=ui.BG_DEEP)
        top.pack(fill="x", side="top")
        body = ui.box(top, "CONNECTION  ·  FLEET")

        # Session picker row.
        srow = tk.Frame(body, bg=ui.BG_CARD)
        srow.pack(fill="x", pady=(4, 2))
        tk.Label(srow, text="SESSION", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="left", padx=(0, 6))
        self._session_cb = ui.combo(srow, self._session_var, [], width=34)
        self._session_cb.pack(side="left")
        self._session_cb.bind("<<ComboboxSelected>>", self._on_session_pick)
        ui.button(srow, "NEW SESSION", self._on_new_session).pack(side="left", padx=(6, 0))
        ui.button(srow, "IMPORT…", self._on_import, ).pack(side="right")
        ui.button(srow, "EXPORT…", self._on_export).pack(side="right", padx=(0, 6))

        # Reply author + single-site connection row.
        arow = tk.Frame(body, bg=ui.BG_CARD)
        arow.pack(fill="x", pady=(4, 2))
        tk.Label(arow, text="REPLY AS", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="left", padx=(0, 6))
        tk.Entry(arow, textvariable=self._author_var, bg=ui.BG_MID, fg=ui.FG_MAIN,
                 insertbackground=ui.ACCENT, relief="flat", font=ui.FONT_UI,
                 width=22).pack(side="left")
        tk.Label(arow, text="  ONE-OFF SITE URL", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="left", padx=(12, 6))
        tk.Entry(arow, textvariable=self._url_var, bg=ui.BG_MID, fg=ui.FG_MAIN,
                 insertbackground=ui.ACCENT, relief="flat", font=ui.FONT_UI,
                 width=24).pack(side="left")
        tk.Entry(arow, textvariable=self._api_key_var, bg=ui.BG_MID, fg=ui.FG_MAIN,
                 insertbackground=ui.ACCENT, relief="flat", font=ui.FONT_UI,
                 width=18, show="•").pack(side="left", padx=(6, 0))
        ui.button(arow, "PULL THIS SITE", self._on_pull_one).pack(side="left", padx=(6, 0))

        # Fleet action row.
        frow = tk.Frame(body, bg=ui.BG_CARD)
        frow.pack(fill="x", pady=(6, 2))
        ui.button(frow, "REFRESH FLEET", self._refresh_fleet).pack(side="left")
        ui.button(frow, "PROBE (LIVE)", self._on_probe).pack(side="left", padx=(6, 0))
        ui.button(frow, "PULL ALL PENDING", self._on_pull_all, kind="primary").pack(side="left", padx=(6, 0))

        # Fleet list.
        flist_wrap = tk.Frame(body, bg=ui.BG_CARD)
        flist_wrap.pack(fill="x", pady=(4, 2))
        self._fleet_list = tk.Listbox(
            flist_wrap, bg=ui.BG_MID, fg=ui.FG_MAIN, height=5,
            relief="flat", font=ui.FONT_UI, selectbackground=ui.BG_HOVER,
            selectforeground=ui.FG_MAIN, activestyle="none",
            highlightthickness=0, bd=0)
        self._fleet_list.pack(side="left", fill="x", expand=True)
        fsb = tk.Scrollbar(flist_wrap, command=self._fleet_list.yview)
        fsb.pack(side="right", fill="y")
        self._fleet_list.config(yscrollcommand=fsb.set)

        # ── QUEUE ─────────────────────────────────────────────────────
        qhead = tk.Frame(self, bg=ui.BG_MID)
        qhead.pack(fill="x")
        tk.Label(qhead, text="COMMENT QUEUE", bg=ui.BG_MID, fg=ui.ACCENT,
                 font=ui.FONT_BOLD, padx=14, pady=8).pack(side="left")
        self._queue_count = tk.Label(qhead, text="", bg=ui.BG_MID, fg=ui.FG_DIM,
                                     font=ui.FONT_SMALL)
        self._queue_count.pack(side="left")

        # Scrollable queue canvas.
        qwrap = tk.Frame(self, bg=ui.BG_DEEP)
        qwrap.pack(fill="both", expand=True)
        self._canvas = tk.Canvas(qwrap, bg=ui.BG_DEEP, highlightthickness=0)
        self._canvas.pack(side="left", fill="both", expand=True)
        qsb = tk.Scrollbar(qwrap, command=self._canvas.yview)
        qsb.pack(side="right", fill="y")
        self._canvas.configure(yscrollcommand=qsb.set)
        self._queue_frame = tk.Frame(self._canvas, bg=ui.BG_DEEP)
        self._canvas_window = self._canvas.create_window(
            (0, 0), window=self._queue_frame, anchor="nw")
        self._queue_frame.bind(
            "<Configure>",
            lambda e: self._canvas.configure(scrollregion=self._canvas.bbox("all")))
        self._canvas.bind(
            "<Configure>",
            lambda e: self._canvas.itemconfig(self._canvas_window, width=e.width))
        self._canvas.bind_all("<MouseWheel>", self._on_mousewheel)

        # ── Bottom bar ────────────────────────────────────────────────
        bar = tk.Frame(self, bg=ui.BG_MID)
        bar.pack(fill="x", side="bottom")
        self._sync_btn = ui.button(bar, "SYNC DECISIONS + REPLIES", self._on_sync, kind="primary")
        self._sync_btn.pack(side="right", padx=10, pady=8)
        tk.Label(bar, textvariable=self._status_var, bg=ui.BG_MID, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL, anchor="w").pack(side="left", padx=12)

    def _on_mousewheel(self, event):
        try:
            self._canvas.yview_scroll(int(-1 * (event.delta / 120)), "units")
        except Exception:
            pass

    # ------------------------------------------------------------------
    # Session lifecycle
    # ------------------------------------------------------------------

    def _boot_session(self):
        """Resume the last session, or create a fresh one."""
        sessions = self._store.list()
        last_id = self._config.get("last_session", "")
        chosen = None
        for s in sessions:
            if s.session_id == last_id:
                chosen = s
                break
        if chosen is None:
            chosen = sessions[0] if sessions else self._store.create()
        self._session = chosen
        self._refresh_session_picker()
        self._render_queue()

    def _refresh_session_picker(self):
        sessions = self._store.list()
        labels = [self._session_label(s) for s in sessions]
        self._session_cb["values"] = labels
        if self._session:
            self._session_var.set(self._session_label(self._session))

    def _session_label(self, s) -> str:
        c = s.counts()
        return f"{s.name}  ·  {c['total']} comments  ({c['ready']} ready)"

    def _on_session_pick(self, _evt=None):
        label = self._session_var.get()
        for s in self._store.list():
            if self._session_label(s) == label:
                self._session = s
                self._persist_last_session()
                self._render_queue()
                return

    def _on_new_session(self):
        self._session = self._store.create()
        self._persist_last_session()
        self._refresh_session_picker()
        self._render_queue()
        self._set_status("New session created.", ui.FG_OK)

    def _persist_last_session(self):
        try:
            self._config["last_session"] = self._session.session_id
            self._config["reply_author"] = self._author_var.get().strip() or "SnapSmack"
            cfg_module.save(self._config)
        except Exception:
            pass

    # ------------------------------------------------------------------
    # Fleet
    # ------------------------------------------------------------------

    def _refresh_fleet(self):
        if not _ENGINE_OK:
            return
        try:
            self._fleet = fleet_module.load_fleet()
        except Exception as e:
            self._fleet = []
            self._set_status(f"Fleet load failed: {e}", ui.FG_ERR)
        self._render_fleet()
        if not self._fleet:
            self._set_status("No fleet profiles found. Use a one-off site, "
                             "or run THE HUB → Discover Fleet.", ui.FG_WARN)

    def _render_fleet(self):
        self._fleet_list.delete(0, tk.END)
        for e in self._fleet:
            if not e.api_key:
                tag = "no key"
            elif e.note:
                tag = e.note
            elif e.reachable:
                tag = f"{e.pending_count} pending"
            else:
                tag = "not probed"
            self._fleet_list.insert(tk.END, f"{e.name}   [{tag}]   {e.site_url}")

    def _on_probe(self):
        if self._busy or not self._fleet:
            return
        self._set_status("Probing fleet…", ui.FG_WARN)

        def work():
            try:
                fleet_module.probe_fleet(self._fleet)
                self.after(0, lambda: (self._render_fleet(),
                                       self._set_status("Fleet probed.", ui.FG_OK)))
            except Exception as e:
                self.after(0, lambda: self._set_status(f"Probe failed: {e}", ui.FG_ERR))
        self._run_bg(work)

    # ------------------------------------------------------------------
    # Pull (network → local session)
    # ------------------------------------------------------------------

    def _on_pull_all(self):
        if self._busy:
            return
        if not self._session:
            self._on_new_session()
        targets = [e for e in self._fleet if e.api_key]
        if not targets:
            self._set_status("No fleet sites with a key to pull.", ui.FG_WARN)
            return
        self._set_status(f"Pulling from {len(targets)} site(s)…", ui.FG_WARN)

        def work():
            added_total, errors = 0, []
            for e in targets:
                try:
                    conn = MouthConnection(e.site_url, e.api_key)
                    rows = conn.pull_pending()
                    added = self._session.ingest_pull(e.as_ingest_site(), rows)
                    added_total += added
                except Exception as ex:
                    errors.append(f"{e.name}: {ex}")
            msg = f"Pulled {added_total} new comment(s)."
            if errors:
                msg += f"  {len(errors)} site(s) failed."
            self.after(0, lambda: self._after_pull(msg, errors))
        self._run_bg(work)

    def _on_pull_one(self):
        if self._busy:
            return
        url = self._url_var.get().strip()
        key = self._api_key_var.get().strip()
        if not url or not key:
            messagebox.showwarning("Missing details",
                                   "Both the one-off SITE URL and API KEY are required.")
            return
        if not self._session:
            self._on_new_session()
        # Persist the one-off connection for next time.
        self._config["url"] = url
        self._config["api_key"] = key
        try:
            cfg_module.save(self._config)
        except Exception:
            pass
        self._set_status("Pulling one site…", ui.FG_WARN)

        def work():
            try:
                snap_home = None
                try:
                    import snap_home as _sh
                    snap_home = _sh
                except Exception:
                    pass
                skey = snap_home.site_key(url) if snap_home else url
                site = {"site_url": url, "site_name": url, "site_key": skey, "node_id": 0}
                conn = MouthConnection(url, key)
                rows = conn.pull_pending()
                added = self._session.ingest_pull(site, rows)
                self.after(0, lambda: self._after_pull(
                    f"Pulled {added} new comment(s) from this site.", []))
            except Exception as ex:
                self.after(0, lambda: self._set_status(f"Pull failed: {ex}", ui.FG_ERR))
        self._run_bg(work)

    def _after_pull(self, msg: str, errors: List[str]):
        self._render_queue()
        self._refresh_session_picker()
        self._set_status(msg, ui.FG_ERR if errors else ui.FG_OK)
        if errors:
            print("PULL ERRORS:\n" + "\n".join(errors))

    # ------------------------------------------------------------------
    # Queue rendering + moderation controls
    # ------------------------------------------------------------------

    def _render_queue(self):
        for w in self._queue_frame.winfo_children():
            w.destroy()
        self._row_widgets.clear()
        if not self._session:
            return
        items = self._session.list_items()
        c = self._session.counts()
        self._queue_count.config(
            text=f"   {c['total']} total · {c['ready']} ready · "
                 f"{c['synced']} synced · {c['failed']} failed")
        if not items:
            tk.Label(self._queue_frame,
                     text="No comments pulled yet. Use PULL ALL PENDING "
                          "(or PULL THIS SITE) when you have a connection.",
                     bg=ui.BG_DEEP, fg=ui.FG_DIM, font=ui.FONT_BODY,
                     justify="left").pack(anchor="w", padx=16, pady=16)
            return
        for item in items:
            self._render_item(item)

    def _render_item(self, item: "mo.ModItem"):
        card = tk.Frame(self._queue_frame, bg=ui.BG_CARD,
                        highlightbackground="#2A2A2A", highlightthickness=1)
        card.pack(fill="x", padx=8, pady=5)
        pad = tk.Frame(card, bg=ui.BG_CARD)
        pad.pack(fill="x", padx=12, pady=8)

        # Header: source + status.
        head = tk.Frame(pad, bg=ui.BG_CARD)
        head.pack(fill="x")
        tk.Label(head, text=f"▶ {item.site_name or item.site_url}", bg=ui.BG_CARD,
                 fg=ui.ACCENT, font=ui.FONT_SMALL).pack(side="left")
        status_lbl = tk.Label(head, text=item.status.upper(), bg=ui.BG_CARD,
                              fg=ui.STATUS_COLORS.get(item.status, ui.FG_DIM),
                              font=ui.FONT_SMALL)
        status_lbl.pack(side="right")

        # Author + on-image.
        who = item.comment.comment_author or "Anonymous"
        if item.comment.comment_email:
            who += f"  [{item.comment.comment_email}]"
        tk.Label(pad, text=who, bg=ui.BG_CARD, fg=ui.FG_MAIN,
                 font=ui.FONT_BOLD).pack(anchor="w", pady=(4, 0))
        meta = (f"ON: {item.comment.img_title or 'unknown'}   ·   "
                f"IP: {item.comment.comment_ip or '—'}   ·   "
                f"{item.comment.comment_date}")
        tk.Label(pad, text=meta, bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(anchor="w")

        # Comment body.
        tk.Label(pad, text=item.comment.comment_text, bg=ui.BG_CARD, fg=ui.FG_MAIN,
                 font=ui.FONT_BODY, justify="left", wraplength=940).pack(
            anchor="w", pady=(6, 6))

        # Decision buttons (big targets; the current choice is highlighted).
        drow = tk.Frame(pad, bg=ui.BG_CARD)
        drow.pack(fill="x")
        btns = {}
        for act, label, kind in ((mo.ACT_APPROVE, "APPROVE", "ok"),
                                 (mo.ACT_DELETE, "DELETE", "danger"),
                                 (mo.ACT_SPAM, "SPAM", "warn")):
            b = ui.button(drow, label,
                          lambda a=act, iid=item.item_id: self._on_decision(iid, a),
                          kind=kind if item.action == act else "normal")
            b.pack(side="left", padx=(0, 6))
            btns[act] = b
        tk.Label(drow, text="", bg=ui.BG_CARD).pack(side="left", expand=True)
        tk.Button(drow, text="CLEAR", relief="flat", bd=0, cursor="hand2",
                  bg=ui.BG_HOVER, fg=ui.FG_DIM, font=ui.FONT_SMALL, padx=8, pady=3,
                  command=lambda iid=item.item_id: self._on_decision(iid, mo.ACT_NONE)
                  ).pack(side="right")

        # Reply.
        rlbl = tk.Frame(pad, bg=ui.BG_CARD)
        rlbl.pack(fill="x", pady=(8, 0))
        tk.Label(rlbl, text="REPLY", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="left")
        ui.button(rlbl, "SAVE REPLY",
                  lambda iid=item.item_id: self._on_save_reply(iid)).pack(side="right")
        reply = tk.Text(pad, height=2, bg=ui.BG_MID, fg=ui.FG_MAIN,
                        insertbackground=ui.ACCENT, relief="flat", font=ui.FONT_UI,
                        wrap="word")
        reply.pack(fill="x", pady=(2, 0))
        if item.reply_text:
            reply.insert("1.0", item.reply_text)

        if item.error:
            tk.Label(pad, text=f"⚠ {item.error}", bg=ui.BG_CARD, fg=ui.FG_ERR,
                     font=ui.FONT_SMALL, justify="left", wraplength=940).pack(
                anchor="w", pady=(4, 0))

        self._row_widgets[item.item_id] = {"reply": reply, "status": status_lbl,
                                           "buttons": btns}

    def _on_decision(self, item_id: str, action: str):
        if not self._session:
            return
        item = self._session.load_item(item_id)
        if not item:
            return
        # Save any typed-but-unsaved reply first so a decision click never loses it.
        w = self._row_widgets.get(item_id)
        if w:
            item.reply_text = w["reply"].get("1.0", "end-1c").strip()
        item.set_action(action)
        self._session.save_item(item)
        self._refresh_row(item)
        self._refresh_session_picker()

    def _on_save_reply(self, item_id: str):
        if not self._session:
            return
        item = self._session.load_item(item_id)
        if not item:
            return
        w = self._row_widgets.get(item_id)
        if not w:
            return
        item.set_reply(w["reply"].get("1.0", "end-1c").strip())
        item.reply_author = self._author_var.get().strip() or "SnapSmack"
        self._session.save_item(item)
        self._refresh_row(item)
        self._refresh_session_picker()
        self._set_status("Reply saved.", ui.FG_OK)

    def _refresh_row(self, item: "mo.ModItem"):
        """Repaint one row's status + decision highlight without a full rebuild."""
        w = self._row_widgets.get(item.item_id)
        if not w:
            return
        w["status"].config(text=item.status.upper(),
                           fg=ui.STATUS_COLORS.get(item.status, ui.FG_DIM))
        for act, btn in w["buttons"].items():
            chosen = (item.action == act)
            kind = {"approve": "ok", "delete": "danger", "spam": "warn"}[act]
            fg = ui.BG_DEEP if chosen else ui.FG_MAIN
            bg = {"ok": ui.FG_OK, "danger": ui.FG_ERR, "warn": ui.FG_WARN}[kind] \
                if chosen else ui.BG_HOVER
            btn.config(bg=bg, fg=fg, activebackground=bg, activeforeground=fg)

    # ------------------------------------------------------------------
    # Sync (local session → network, with positive verification)
    # ------------------------------------------------------------------

    def _on_sync(self):
        if self._busy or not self._session:
            return
        # Flush any unsaved reply text into its item before syncing.
        self._flush_unsaved_replies()
        todo = [it for it in self._session.list_items()
                if it.has_work() and it.status != mo.ST_SYNCED]
        if not todo:
            self._set_status("Nothing to sync — decide or reply on a comment first.",
                             ui.FG_WARN)
            return
        self._set_status(f"Syncing {len(todo)} item(s)…", ui.FG_WARN)

        # Group by site so each site gets one authenticated connection.
        by_site: Dict[str, List["mo.ModItem"]] = {}
        for it in todo:
            by_site.setdefault(it.site_key, []).append(it)

        author = self._author_var.get().strip() or "SnapSmack"

        def work():
            ok = fail = 0
            for site_key, items in by_site.items():
                entry = fleet_module.find_entry(self._fleet, site_key)
                url = items[0].site_url
                key = entry.api_key if entry else ""
                if not key:
                    # Fall back to the one-off connection if it matches.
                    if self._api_key_var.get().strip() and self._url_var.get().strip():
                        key = self._api_key_var.get().strip()
                if not key:
                    for it in items:
                        it.status = mo.ST_FAILED
                        it.error = "no API key for this site (refresh the fleet)"
                        self._session.save_item(it)
                        fail += 1
                    continue
                conn = MouthConnection(url, key)
                poster = MouthPoster(conn, hub_author=author)
                engine = mo.SyncEngine(self._session, poster,
                                       on_event=self._on_sync_event)
                results = engine.sync_all(items)
                for r in results.values():
                    if r.ok:
                        ok += 1
                    else:
                        fail += 1
            self.after(0, lambda: self._after_sync(ok, fail))
        self._run_bg(work)

    def _on_sync_event(self, phase: str, item: "mo.ModItem", msg: str):
        # Marshalled onto the UI thread — repaint the affected row.
        self.after(0, lambda: self._refresh_row_by_id(item.item_id))

    def _refresh_row_by_id(self, item_id: str):
        if not self._session:
            return
        item = self._session.load_item(item_id)
        if item:
            self._refresh_row(item)

    def _after_sync(self, ok: int, fail: int):
        self._render_queue()
        self._refresh_session_picker()
        color = ui.FG_OK if fail == 0 else (ui.FG_WARN if ok else ui.FG_ERR)
        self._set_status(f"Sync complete: {ok} confirmed, {fail} failed.", color)

    def _flush_unsaved_replies(self):
        if not self._session:
            return
        for item_id, w in list(self._row_widgets.items()):
            try:
                text = w["reply"].get("1.0", "end-1c").strip()
            except Exception:
                continue
            item = self._session.load_item(item_id)
            if item and text != (item.reply_text or "").strip():
                item.set_reply(text)
                item.reply_author = self._author_var.get().strip() or "SnapSmack"
                self._session.save_item(item)

    # ------------------------------------------------------------------
    # Export / import
    # ------------------------------------------------------------------

    def _on_export(self):
        if not self._session:
            return
        dest = filedialog.askdirectory(title="Export moderation batch to…")
        if not dest:
            return
        try:
            out = mo.export_session(self._session, dest)
            self._set_status(f"Exported to {out}", ui.FG_OK)
        except Exception as e:
            self._set_status(f"Export failed: {e}", ui.FG_ERR)

    def _on_import(self):
        src = filedialog.askdirectory(title="Import a moderation batch folder…")
        if not src:
            return
        try:
            s = mo.import_session(src, self._store)
            self._session = s
            self._persist_last_session()
            self._refresh_session_picker()
            self._render_queue()
            self._set_status("Batch imported.", ui.FG_OK)
        except Exception as e:
            self._set_status(f"Import failed: {e}", ui.FG_ERR)

    # ------------------------------------------------------------------
    # Small helpers
    # ------------------------------------------------------------------

    def _set_status(self, text: str, color: str = ui.FG_DIM):
        self._status_var.set(text)
        print(f"[status] {text}")

    def _run_bg(self, fn):
        """Run fn on a worker thread, guarding the busy flag + Sync button."""
        self._busy = True
        self._sync_btn.config(state="disabled")

        def wrapper():
            try:
                fn()
            finally:
                self.after(0, self._bg_done)
        threading.Thread(target=wrapper, daemon=True).start()

    def _bg_done(self):
        self._busy = False
        try:
            self._sync_btn.config(state="normal")
        except Exception:
            pass


def main():
    app = App()
    app.mainloop()


if __name__ == "__main__":
    main()
# ===== SNAPSMACK EOF =====
