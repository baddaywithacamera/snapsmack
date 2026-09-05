"""
COLD SNAP — sumna_smacktalk.py
COLD TAKE — the SMACKTALK (longform) mode panel. Compose a longform post with a
TITLE, BODY, and an image BUCKET (a photo essay's worth of images), offline-first;
sync it to a site_mode='smacktalk' install when a connection shows. Mirrors the web
SMACKTALK post + bucket (snap_bucket_items via smack-post-long.php); a draft is the
post + its ordered bucket waiting to be created.

Posts through SmacktalkPoster, which needs the site's SMACKTALK (smackpress) API key
— separate from the normal API key (smackpress/* reject the ordinary 'sybu' key by
design). Set it on the CONNECTION panel.

Mounts as a tk.Frame inside coldsnap.py via build_smacktalk_mode(parent, app).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""


import os
import threading
import tkinter as tk
from tkinter import filedialog, messagebox
from typing import List, Optional

import sumna_ui as ui
import sumna_offline as O
from sumna_post import SmacktalkPoster


class SmacktalkMode(tk.Frame):
    """COLD TAKE — SMACKTALK longform + image-bucket drafting, store-and-forward sync."""

    SUITE_MODE = O.MODE_SMACKTALK

    def __init__(self, parent, app):
        super().__init__(parent, bg=ui.BG_DEEP)
        self.app = app
        self.store = O.SessionStore()
        self.session: Optional[O.Session] = None
        self._editing_id: Optional[str] = None
        # The working bucket: a list of DraftImage, order = display order, exactly one cover.
        self._bucket: List[O.DraftImage] = []
        self._cover_idx = 0
        self._title = tk.StringVar()
        self._tags = tk.StringVar()
        self._status = tk.StringVar(value="published")
        self._session_var = tk.StringVar()
        self._build()
        self._refresh_sessions()

    # -- layout -------------------------------------------------------------
    def _build(self):
        header = tk.Frame(self, bg=ui.BG_DEEP)
        header.pack(fill="x", padx=8, pady=(8, 0))
        tk.Label(header, text="COLD TAKE", bg=ui.BG_DEEP, fg=ui.ACCENT,
                 font=ui.FONT_TITLE).pack(side="left")
        tk.Label(header, text="  smacktalk · a title, a story, and a bucket of photos",
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
        ui.field(ebody, "Title", self._title)
        self._body = ui.textarea(ebody, "Body (the write-up)", height=5)
        mrow = tk.Frame(ebody, bg=ui.BG_CARD); mrow.pack(fill="x")
        ui.button(mrow, "Insert MOSAIC gallery", self._insert_mosaic).pack(side="left")
        tk.Label(mrow, text="drops a [mosaic] — the bucket photos become a tiled gallery right here in the post",
                 bg=ui.BG_CARD, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(side="left", padx=8)
        ui.field(ebody, "Tags (space-separated #hashtags)", self._tags)

        srow = tk.Frame(ebody, bg=ui.BG_CARD); srow.pack(fill="x", pady=(6, 0))
        tk.Label(srow, text="Status", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="left")
        ui.combo(srow, self._status, ["published", "draft"], width=10).pack(side="left", padx=6)

        # Image bucket
        brow = tk.Frame(ebody, bg=ui.BG_CARD); brow.pack(fill="x", pady=(10, 0))
        tk.Label(brow, text="IMAGE BUCKET", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_BOLD).pack(side="left")
        self._bucket_count = tk.Label(brow, text="0 photos", bg=ui.BG_CARD, fg=ui.FG_DIM,
                                      font=ui.FONT_SMALL)
        self._bucket_count.pack(side="left", padx=8)
        ui.button(brow, "Add photos…", self._add_photos).pack(side="right")
        tk.Label(ebody, text="The cover (★) leads the post. Order here is the order in the post.",
                 bg=ui.BG_CARD, fg=ui.FG_DIM, font=ui.FONT_SMALL, anchor="w").pack(fill="x")
        self._bucket_frame = tk.Frame(ebody, bg=ui.BG_CARD)
        self._bucket_frame.pack(fill="x", pady=(4, 0))

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
            try:
                import snap_errors
                snap_errors.show_error("Import failed", e)
            except Exception:
                messagebox.showerror("Import failed", str(e))
            return
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
        tk.Label(info, text=f"{len(draft.images)} photo(s) in bucket", bg=ui.BG_CARD,
                 fg=ui.FG_DIM, font=ui.FONT_SMALL, anchor="w").pack(anchor="w")
        ui.status_badge(info, draft.status).pack(anchor="w")
        if draft.error:
            tk.Label(info, text=draft.error, bg=ui.BG_CARD, fg=ui.FG_ERR,
                     font=ui.FONT_SMALL, wraplength=200, justify="left").pack(anchor="w")
        btns = tk.Frame(row, bg=ui.BG_CARD); btns.pack(side="right", padx=4)
        ui.button(btns, "Edit", lambda d=draft: self._edit(d)).pack(pady=1)
        ui.button(btns, "Del", lambda d=draft: self._delete(d), kind="danger").pack(pady=1)

    def _edit(self, draft: O.Draft):
        self._editing_id = draft.draft_id
        self._title.set(draft.title)
        self._tags.set(draft.tags)
        self._status.set(draft.img_status)
        self._body.delete("1.0", "end"); self._body.insert("1.0", draft.caption)
        # Rebuild the working bucket from the draft, preserving order + cover.
        self._bucket = [O.DraftImage(local_path=im.local_path, filename=im.filename,
                                     thumb_square=im.thumb_square, is_cover=im.is_cover)
                        for im in draft.images]
        self._cover_idx = next((i for i, im in enumerate(self._bucket) if im.is_cover), 0)
        self._refresh_bucket()

    def _delete(self, draft: O.Draft):
        if self.session and messagebox.askyesno("Delete draft", "Delete this draft?"):
            self.session.delete_draft(draft.draft_id)
            if self._editing_id == draft.draft_id:
                self._clear_editor()
            self._refresh_drafts()

    # -- bucket -------------------------------------------------------------
    def _insert_mosaic(self):
        """Drop a [mosaic] token at the cursor. On post, COLD SNAP turns this essay's
        bucket photos into a real justified [mosaic:ID] gallery right at this spot —
        no id to look up, it's created from the images you already added."""
        try:
            self._body.insert("insert", "[mosaic]")
            self._body.focus_set()
        except Exception:
            pass

    def _add_photos(self):
        paths = filedialog.askopenfilenames(
            title="Add photos to the bucket",
            filetypes=[("Images", "*.jpg *.jpeg *.png *.webp"), ("All", "*.*")])
        for p in paths:
            if len(self._bucket) >= O.SMACKTALK_BUCKET_MAX:
                messagebox.showinfo("Bucket full",
                                    f"A SMACKTALK bucket holds up to {O.SMACKTALK_BUCKET_MAX} photos.")
                break
            self._bucket.append(O.DraftImage(local_path=p, filename=os.path.basename(p)))
        if paths and not self._title.get().strip():
            self._title.set(os.path.splitext(os.path.basename(paths[0]))[0])
        self._refresh_bucket()

    def _move(self, idx: int, delta: int):
        j = idx + delta
        if 0 <= j < len(self._bucket):
            self._bucket[idx], self._bucket[j] = self._bucket[j], self._bucket[idx]
            if self._cover_idx == idx:
                self._cover_idx = j
            elif self._cover_idx == j:
                self._cover_idx = idx
            self._refresh_bucket()

    def _remove_from_bucket(self, idx: int):
        del self._bucket[idx]
        if self._cover_idx >= len(self._bucket):
            self._cover_idx = max(0, len(self._bucket) - 1)
        self._refresh_bucket()

    def _set_cover(self, idx: int):
        self._cover_idx = idx
        self._refresh_bucket()

    def _refresh_bucket(self):
        for w in self._bucket_frame.winfo_children():
            w.destroy()
        self._bucket_count.configure(text=f"{len(self._bucket)} photo(s)")
        if not self._bucket:
            tk.Label(self._bucket_frame, text="No photos yet — click “Add photos…”.",
                     bg=ui.BG_CARD, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(anchor="w", pady=6)
            return
        for i, im in enumerate(self._bucket):
            is_cover = (i == self._cover_idx)
            r = tk.Frame(self._bucket_frame, bg=ui.BG_MID if is_cover else ui.BG_CARD,
                         highlightbackground="#2A2A2A", highlightthickness=1)
            r.pack(fill="x", pady=1)
            thumb = ui.load_thumb(im.thumb_square or im.local_path, 40)
            tl = tk.Label(r, bg=ui.BG_MID, width=5, height=2)
            if thumb:
                tl.configure(image=thumb, width=40, height=40); tl.image = thumb
            tl.pack(side="left", padx=3, pady=3)
            tk.Label(r, text=("★ " if is_cover else "") + (im.filename or os.path.basename(im.local_path)),
                     bg=r["bg"], fg=ui.FG_OK if is_cover else ui.FG_MAIN,
                     font=ui.FONT_SMALL, anchor="w").pack(side="left", fill="x", expand=True)
            b = tk.Frame(r, bg=r["bg"]); b.pack(side="right", padx=2)
            ui.button(b, "▲", lambda i=i: self._move(i, -1)).pack(side="left", padx=1)
            ui.button(b, "▼", lambda i=i: self._move(i, 1)).pack(side="left", padx=1)
            if not is_cover:
                ui.button(b, "★ Cover", lambda i=i: self._set_cover(i)).pack(side="left", padx=1)
            ui.button(b, "✕", lambda i=i: self._remove_from_bucket(i), kind="danger").pack(side="left", padx=1)

    def _clear_editor(self):
        self._editing_id = None
        self._title.set(""); self._tags.set(""); self._status.set("published")
        self._body.delete("1.0", "end")
        self._bucket = []
        self._cover_idx = 0
        self._refresh_bucket()

    # -- save ---------------------------------------------------------------
    def _save_draft(self, ready: bool = False):
        if not self.session:
            self._new_session()
            if not self.session:
                return
        if not self._bucket:
            messagebox.showwarning("No photos", "Add at least one photo to the bucket first."); return

        if self._editing_id:
            draft = self.session.load_draft(self._editing_id) or self._blank_draft()
        else:
            draft = self._blank_draft()
        draft.title = self._title.get().strip()
        draft.tags = self._tags.get().strip()
        draft.caption = self._body.get("1.0", "end").strip()   # caption = the longform body
        draft.img_status = self._status.get()

        images = []
        for i, im in enumerate(self._bucket):
            images.append(O.DraftImage(local_path=im.local_path,
                                       filename=im.filename or os.path.basename(im.local_path),
                                       sort_position=i, is_cover=(i == self._cover_idx)))
        draft.images = images
        O.generate_draft_thumbs(draft)
        problems = draft.validate()
        if ready and problems:
            messagebox.showwarning("Not ready", "\n".join(problems))
        draft.status = O.ST_READY if (ready and not problems) else O.ST_DRAFT
        self.session.add_draft(draft)
        self._clear_editor()
        self._refresh_sessions()

    def _blank_draft(self) -> O.Draft:
        return O.Draft(draft_id=O._new_id(), kind=O.KIND_SMACKTALK, mode=self.SUITE_MODE)

    # -- sync ---------------------------------------------------------------
    def _poster(self) -> Optional[SmacktalkPoster]:
        cfg = getattr(self.app, "_config", {}) or {}
        url = (cfg.get("url") or "").strip()
        key = (cfg.get("smackpress_key") or "").strip()
        if not url:
            messagebox.showwarning("Not connected", "Set the site URL on the CONNECTION panel first.")
            return None
        if not key:
            messagebox.showwarning(
                "No SMACKTALK key",
                "SMACKTALK posting needs this site's SMACKTALK API key (a 'smackpress' key "
                "from SnapSmack Admin → API Access). Enter it as SMACKTALK KEY on the "
                "CONNECTION panel.")
            return None
        return SmacktalkPoster(url, key, site_data=getattr(self.app, "_site_data", None))

    def _sync(self):
        if not self.session:
            return
        poster = self._poster()
        if poster is None:
            return
        ready = [d for d in self.session.list_drafts() if d.status == O.ST_READY]
        if not ready:
            self._sync_status.configure(text="Nothing marked OFFLINE POST yet — compose and commit first.")
            return
        # Parkinson's-forgiving guard: never publish to a LIVE site without a confirm
        # that NAMES the site (shared helper, inline fallback so it can't disappear).
        _url = (getattr(self.app, "_config", {}) or {}).get("url", "")
        try:
            import snap_confirm
            _ok = snap_confirm.confirm_post(_url, len(ready), item="post",
                                            action="Publish", parent=self)
        except Exception:
            from urllib.parse import urlparse
            _dest = urlparse(_url if "://" in _url else "https://" + _url).netloc or _url or "your site"
            _ok = messagebox.askyesno("Confirm publish",
                                      f"Publish {len(ready)} SMACKTALK post(s) to {_dest}?")
        if not _ok:
            self._sync_status.configure(text="Publish cancelled.")
            return
        self._sync_status.configure(text=f"Syncing {len(ready)} post(s)…", fg=ui.FG_WARN)

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


def build_smacktalk_mode(parent, app) -> SmacktalkMode:
    """Factory used by coldsnap.py to mount the COLD TAKE panel."""
    return SmacktalkMode(parent, app)
# ===== SNAPSMACK EOF =====
