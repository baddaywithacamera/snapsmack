"""
SUMNABATCH — sumna_solo.py
BATCH SLAPPED — the SMACKONEOUT (solo) mode panel. Single-image post drafting
for solo photoblog installs, offline-first. Slap a post up, it waits as a draft,
you sync it when a connection shows. The fields mirror the web admin solo poster
(smack-post-solo.php); a draft is a DB row waiting to be inserted.

Mounts as a tk.Frame inside main.py via build_solo_mode(parent, app).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
import threading
import tkinter as tk
from tkinter import filedialog, messagebox
from typing import Optional

import sumna_ui as ui
import sumna_offline as O
from sumna_post import SumnaConnection, SoloPoster


class SoloMode(tk.Frame):
    """BATCH SLAPPED — solo single-image drafting + store-and-forward sync."""

    SUITE_MODE = O.MODE_SOLO

    def __init__(self, parent, app):
        super().__init__(parent, bg=ui.BG_DEEP)
        self.app = app
        self.store = O.SessionStore()
        self.session: Optional[O.Session] = None
        self._editing_id: Optional[str] = None
        self._image_path = tk.StringVar()
        self._title = tk.StringVar()
        self._tags = tk.StringVar()
        self._category = tk.StringVar()
        self._album = tk.StringVar()
        self._orientation = tk.StringVar(value="auto")
        self._download_url = tk.StringVar()
        self._allow_dl = tk.BooleanVar(value=False)
        self._status = tk.StringVar(value="published")
        self._session_var = tk.StringVar()
        self._build()
        self._refresh_sessions()

    # -- layout -------------------------------------------------------------
    def _build(self):
        header = tk.Frame(self, bg=ui.BG_DEEP)
        header.pack(fill="x", padx=8, pady=(8, 0))
        tk.Label(header, text="BATCH SLAPPED", bg=ui.BG_DEEP, fg=ui.ACCENT,
                 font=ui.FONT_TITLE).pack(side="left")
        tk.Label(header, text="  solo · slap one up, sync when you feel like it",
                 bg=ui.BG_DEEP, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(side="left")

        # Session bar
        sb = ui.box(self, "SESSION")
        row = tk.Frame(sb, bg=ui.BG_CARD); row.pack(fill="x")
        ui.combo(row, self._session_var, [], width=32).pack(side="left", padx=(0, 6))
        self._session_combo = row.winfo_children()[-1]
        self._session_combo.bind("<<ComboboxSelected>>", lambda e: self._on_select_session())
        ui.button(row, "New", self._new_session).pack(side="left", padx=2)
        ui.button(row, "Export to USB…", self._export_session).pack(side="left", padx=2)
        ui.button(row, "Import…", self._import_session).pack(side="left", padx=2)

        # Two columns: draft list | editor
        cols = tk.Frame(self, bg=ui.BG_DEEP); cols.pack(fill="both", expand=True, padx=8, pady=6)
        left = tk.Frame(cols, bg=ui.BG_DEEP, width=320); left.pack(side="left", fill="y")
        left.pack_propagate(False)
        right = tk.Frame(cols, bg=ui.BG_DEEP); right.pack(side="left", fill="both", expand=True)

        tk.Label(left, text="DRAFTS", bg=ui.BG_DEEP, fg=ui.FG_DIM,
                 font=ui.FONT_BOLD).pack(anchor="w")
        self._list_frame = tk.Frame(left, bg=ui.BG_DEEP)
        self._list_frame.pack(fill="both", expand=True)

        sync = tk.Frame(left, bg=ui.BG_DEEP); sync.pack(fill="x", pady=6)
        ui.button(sync, "⇪  SYNC WITH LIVE", self._sync, kind="primary").pack(fill="x")
        self._sync_status = tk.Label(left, text="", bg=ui.BG_DEEP, fg=ui.FG_DIM,
                                     font=ui.FONT_SMALL, wraplength=300, justify="left")
        self._sync_status.pack(fill="x")

        # Editor
        ebody = ui.box(right, "COMPOSE")
        prow = tk.Frame(ebody, bg=ui.BG_CARD); prow.pack(fill="x", pady=(4, 0))
        self._preview = tk.Label(prow, bg=ui.BG_MID, width=12, height=6)
        self._preview.pack(side="left", padx=(0, 8))
        pbtns = tk.Frame(prow, bg=ui.BG_CARD); pbtns.pack(side="left", fill="x")
        ui.button(pbtns, "Choose image…", self._choose_image).pack(anchor="w")
        tk.Label(pbtns, textvariable=self._image_path, bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL, wraplength=320, justify="left").pack(anchor="w", pady=4)

        ui.field(ebody, "Title", self._title)
        ui.field(ebody, "Tags (space-separated #hashtags)", self._tags)
        self._caption = ui.textarea(ebody, "Caption / description", height=3)

        meta = tk.Frame(ebody, bg=ui.BG_CARD); meta.pack(fill="x")
        c1 = tk.Frame(meta, bg=ui.BG_CARD); c1.pack(side="left", fill="x", expand=True, padx=(0, 4))
        c2 = tk.Frame(meta, bg=ui.BG_CARD); c2.pack(side="left", fill="x", expand=True, padx=(4, 0))
        ui.field(c1, "Category", self._category)
        ui.field(c2, "Album", self._album)

        orow = tk.Frame(ebody, bg=ui.BG_CARD); orow.pack(fill="x", pady=(6, 0))
        tk.Label(orow, text="Orientation", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="left")
        ui.combo(orow, self._orientation, ["auto", "landscape", "portrait", "square"],
                 width=12).pack(side="left", padx=6)
        tk.Label(orow, text="Status", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="left", padx=(12, 0))
        ui.combo(orow, self._status, ["published", "draft"], width=10).pack(side="left", padx=6)

        drow = tk.Frame(ebody, bg=ui.BG_CARD); drow.pack(fill="x", pady=(6, 0))
        tk.Checkbutton(drow, text="Allow download", variable=self._allow_dl,
                       bg=ui.BG_CARD, fg=ui.FG_MAIN, selectcolor=ui.BG_MID,
                       activebackground=ui.BG_CARD, font=ui.FONT_SMALL).pack(side="left")
        ui.field(ebody, "Download URL (if allowed)", self._download_url)

        act = tk.Frame(ebody, bg=ui.BG_CARD); act.pack(fill="x", pady=(10, 2))
        ui.button(act, "Save draft", self._save_draft).pack(side="left", padx=(0, 6))
        ui.button(act, "✓ OFFLINE POST", lambda: self._save_draft(ready=True),
                  kind="primary").pack(side="left", padx=6)
        ui.button(act, "Clear", self._clear_editor).pack(side="left", padx=6)

    # -- sessions -----------------------------------------------------------
    def _sessions(self):
        return [s for s in self.store.list() if s.mode == self.SUITE_MODE]

    def _refresh_sessions(self):
        sessions = self._sessions()
        names = [f"{s.name}  ·  {len(s.list_drafts())} drafts" for s in sessions]
        self._session_combo["values"] = names
        self._session_objs = sessions
        if sessions and self.session is None:
            self.session = sessions[0]
            self._session_var.set(names[0])
        elif not sessions:
            self.session = None
            self._session_var.set("")
        self._refresh_drafts()

    def _on_select_session(self):
        idx = self._session_combo.current()
        if 0 <= idx < len(self._session_objs):
            self.session = self._session_objs[idx]
            self._refresh_drafts()

    def _new_session(self):
        from tkinter import simpledialog
        name = simpledialog.askstring("New session", "Session name:", parent=self)
        if name is None:
            return
        self.session = self.store.create(name, self.SUITE_MODE)
        self._refresh_sessions()

    def _export_session(self):
        if not self.session:
            return
        dest = filedialog.askdirectory(title="Export session to (thumb drive / folder)")
        if not dest:
            return
        out = O.export_session(self.session, dest)
        messagebox.showinfo("Exported", f"Session exported to:\n{out}")

    def _import_session(self):
        src = filedialog.askdirectory(title="Choose an exported session folder")
        if not src:
            return
        try:
            self.session = O.import_session(src, self.store)
        except Exception as e:
            messagebox.showerror("Import failed", str(e)); return
        self._refresh_sessions()

    # -- drafts -------------------------------------------------------------
    def _refresh_drafts(self):
        for w in self._list_frame.winfo_children():
            w.destroy()
        if not self.session:
            tk.Label(self._list_frame, text="No session — create one above.",
                     bg=ui.BG_DEEP, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(anchor="w", pady=8)
            return
        for d in self.session.list_drafts():
            self._draft_row(d)

    def _draft_row(self, draft: O.Draft):
        row = tk.Frame(self._list_frame, bg=ui.BG_CARD, highlightbackground="#2A2A2A",
                       highlightthickness=1)
        row.pack(fill="x", pady=2)
        cover = draft.cover()
        thumb = ui.load_thumb(cover.thumb_square if cover else "", 48)
        lbl = tk.Label(row, bg=ui.BG_MID, width=6, height=3)
        if thumb:
            lbl.configure(image=thumb, width=48, height=48); lbl.image = thumb
        lbl.pack(side="left", padx=4, pady=4)
        info = tk.Frame(row, bg=ui.BG_CARD); info.pack(side="left", fill="x", expand=True)
        tk.Label(info, text=draft.title or "(untitled)", bg=ui.BG_CARD, fg=ui.FG_MAIN,
                 font=ui.FONT_BOLD, anchor="w").pack(anchor="w")
        ui.status_badge(info, draft.status).pack(anchor="w")
        if draft.error:
            tk.Label(info, text=draft.error, bg=ui.BG_CARD, fg=ui.FG_ERR,
                     font=ui.FONT_SMALL, wraplength=200, justify="left").pack(anchor="w")
        btns = tk.Frame(row, bg=ui.BG_CARD); btns.pack(side="right", padx=4)
        ui.button(btns, "Edit", lambda d=draft: self._edit(d)).pack(pady=1)
        ui.button(btns, "Del", lambda d=draft: self._delete(d), kind="danger").pack(pady=1)

    def _edit(self, draft: O.Draft):
        self._editing_id = draft.draft_id
        cover = draft.cover()
        self._image_path.set(cover.local_path if cover else "")
        self._title.set(draft.title)
        self._tags.set(draft.tags)
        self._category.set(draft.category)
        self._album.set(draft.album)
        self._orientation.set(draft.orientation or "auto")
        self._status.set(draft.img_status)
        self._allow_dl.set(draft.allow_download)
        self._download_url.set(draft.download_url)
        self._caption.delete("1.0", "end"); self._caption.insert("1.0", draft.caption)
        self._show_preview(cover.thumb_square if cover else (cover.local_path if cover else ""))

    def _delete(self, draft: O.Draft):
        if self.session and messagebox.askyesno("Delete draft", "Delete this draft?"):
            self.session.delete_draft(draft.draft_id)
            if self._editing_id == draft.draft_id:
                self._clear_editor()
            self._refresh_drafts()

    def _choose_image(self):
        p = filedialog.askopenfilename(
            title="Choose image",
            filetypes=[("Images", "*.jpg *.jpeg *.png *.webp"), ("All", "*.*")])
        if p:
            self._image_path.set(p)
            if not self._title.get():
                self._title.set(os.path.splitext(os.path.basename(p))[0])
            self._show_preview(p)

    def _show_preview(self, path):
        thumb = ui.load_thumb(path, 96)
        if thumb:
            self._preview.configure(image=thumb, width=96, height=96)
            self._preview.image = thumb

    def _clear_editor(self):
        self._editing_id = None
        for v in (self._image_path, self._title, self._tags, self._category,
                  self._album, self._download_url):
            v.set("")
        self._orientation.set("auto"); self._status.set("published")
        self._allow_dl.set(False)
        self._caption.delete("1.0", "end")
        self._preview.configure(image="", width=12, height=6); self._preview.image = None

    def _save_draft(self, ready: bool = False):
        if not self.session:
            self._new_session()
            if not self.session:
                return
        img = self._image_path.get().strip()
        if not img or not os.path.isfile(img):
            messagebox.showwarning("No image", "Choose an image first."); return

        if self._editing_id:
            draft = self.session.load_draft(self._editing_id) or self._blank_draft()
        else:
            draft = self._blank_draft()
        draft.title = self._title.get().strip()
        draft.tags = self._tags.get().strip()
        draft.caption = self._caption.get("1.0", "end").strip()
        draft.category = self._category.get().strip()
        draft.album = self._album.get().strip()
        draft.orientation = self._orientation.get()
        draft.img_status = self._status.get()
        draft.allow_download = bool(self._allow_dl.get())
        draft.download_url = self._download_url.get().strip()
        draft.images = [O.DraftImage(local_path=img, filename=os.path.basename(img),
                                     is_cover=True)]
        O.generate_draft_thumbs(draft)
        problems = draft.validate()
        if ready and problems:
            messagebox.showwarning("Not ready", "\n".join(problems));
        draft.status = O.ST_READY if (ready and not problems) else O.ST_DRAFT
        self.session.add_draft(draft)
        if self.session.over_soft_limit():
            messagebox.showinfo(
                "Big batch",
                f"This batch now holds {self.session.image_count()} images "
                f"(soft limit ~{O.SOFT_BATCH_IMAGE_LIMIT}). It'll still sync fine — "
                "but consider starting a new batch to stay friendly to the shared host.")
        self._clear_editor()
        self._refresh_sessions()

    def _blank_draft(self) -> O.Draft:
        return O.Draft(draft_id=O._new_id(), kind=O.KIND_SOLO, mode=self.SUITE_MODE)

    # -- sync ---------------------------------------------------------------
    def _connection(self) -> Optional[SumnaConnection]:
        cfg = getattr(self.app, "_config", {}) or {}
        url = (cfg.get("url") or "").strip()
        key = (cfg.get("api_key") or "").strip()
        if not url or not key:
            messagebox.showwarning("Not connected",
                                   "Set the site URL + API key on the POST tab first.")
            return None
        return SumnaConnection(url, key)

    def _sync(self):
        if not self.session:
            return
        conn = self._connection()
        if conn is None:
            return
        ready = [d for d in self.session.list_drafts() if d.status == O.ST_READY]
        if not ready:
            self._sync_status.configure(text="Nothing marked OFFLINE POST yet — compose and commit first.")
            return
        self._sync_status.configure(text=f"Syncing {len(ready)} draft(s)…", fg=ui.FG_WARN)
        poster = SoloPoster(conn, site_data=getattr(self.app, "_site_data", None))

        def worker():
            def on_event(phase, draft, msg):
                self.after(0, lambda: self._refresh_drafts())
            engine = O.SyncEngine(self.session, poster, on_event=on_event)
            results = engine.sync_all(ready)
            ok = sum(1 for r in results.values() if r.ok)
            self.after(0, lambda: self._sync_done(ok, len(results)))

        threading.Thread(target=worker, daemon=True).start()

    def _sync_done(self, ok, total):
        color = ui.FG_OK if ok == total else ui.FG_ERR
        self._sync_status.configure(text=f"Synced {ok}/{total}. See badges for any failures.",
                                    fg=color)
        self._refresh_sessions()


def build_solo_mode(parent, app) -> SoloMode:
    """Factory used by main.py to mount the BATCH SLAPPED panel."""
    return SoloMode(parent, app)
# ===== SNAPSMACK EOF =====
