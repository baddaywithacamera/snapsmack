"""
SON OF A BATCH — sob_gram.py
BATCH, PLEASE — the GRAMOFSMACK (3-across grid) mode panel. Offline-first:
compose single posts, carousels (up to 10 images), and trigrams with the EXACT
same per-image controls as the web gram poster (smack-post-gram.php) — fit/fill,
size %, border, background matte, shadow, focal point, zoom, and per-image
split. Client-side 400² + 400px thumbnails are generated with each image's
focal/zoom baked in.

Store-and-forward workflow:
  * compose with NO connection;
  * hit OFFLINE POST to commit a finished post to the batch (marks it ready);
  * later, on any connection, hit SYNC WITH LIVE to push the whole batch and
    verify each post against the live server.

Trigrams: slice one 3:1 (h) / 1:3 (v) cover into three chunks at once. Each
slot is either a single sliced image OR a carousel whose cover is the slice
(trigram-of-carousels). The group syncs as one unit and the server promotes it
atomically at a clean row boundary — no broken rows.

Editing applies to UNSYNCED drafts only (re-open, fix a typo / re-crop / change
layout, OFFLINE POST again). Editing already-live posts is GET YOUR SHIT
SORTED's job — this tool stays focused on composing and forwarding.

Mounts as a tk.Frame inside main.py via build_gram_mode(parent, app).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, simpledialog
from typing import List, Optional

import sob_ui as ui
import sob_offline as O
from sob_post import SobConnection, GramPoster


class GramMode(tk.Frame):
    """BATCH, PLEASE — gram single / carousel / trigram drafting + sync."""

    SUITE_MODE = O.MODE_GRAM

    def __init__(self, parent, app):
        super().__init__(parent, bg=ui.BG_DEEP)
        self.app = app
        self.store = O.SessionStore()
        self.session: Optional[O.Session] = None
        self._session_objs: List[O.Session] = []

        # Compose working state.
        self._kind = tk.StringVar(value="carousel")        # single | carousel | trigram
        self._trig_style = tk.StringVar(value="single")    # single | carousels
        self._trig_orientation = tk.StringVar(value="h")
        self._work_images: List[O.DraftImage] = []         # single/carousel images
        self._trig_slots: List[List[O.DraftImage]] = [[], [], []]  # 3 slots for trigram
        self._sel_img: Optional[O.DraftImage] = None
        self._editing_group: str = ""                      # group_key being edited (trigram)
        self._editing_id: str = ""                         # draft_id being edited (single/carousel)
        self._loading = False                              # guard control write-back

        # Post-level vars.
        self._tags = tk.StringVar()
        self._date = tk.StringVar()
        self._status = tk.StringVar(value="published")
        self._allow_comments = tk.BooleanVar(value=True)
        self._allow_dl = tk.BooleanVar(value=False)
        self._download_url = tk.StringVar()
        self._panorama_rows = tk.IntVar(value=1)
        self._session_var = tk.StringVar()

        # Per-image control vars (bound to the selected image).
        self._c_crop = tk.StringVar(value="fit")
        self._c_size = tk.IntVar(value=100)
        self._c_border = tk.IntVar(value=0)
        self._c_border_color = tk.StringVar(value="#000000")
        self._c_bg = tk.StringVar(value="#ffffff")
        self._c_shadow = tk.IntVar(value=0)
        self._c_fx = tk.IntVar(value=50)
        self._c_fy = tk.IntVar(value=50)
        self._c_zoom = tk.IntVar(value=100)
        self._c_split = tk.BooleanVar(value=False)

        self._build()
        self._wire_control_traces()
        self._refresh_sessions()

    # ======================================================================
    # Layout
    # ======================================================================
    def _build(self):
        header = tk.Frame(self, bg=ui.BG_DEEP); header.pack(fill="x", padx=8, pady=(8, 0))
        tk.Label(header, text="BATCH, PLEASE", bg=ui.BG_DEEP, fg=ui.ACCENT,
                 font=ui.FONT_TITLE).pack(side="left")
        tk.Label(header, text="  gram · offline-first · same controls as the web poster",
                 bg=ui.BG_DEEP, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(side="left")

        sb = ui.box(self, "SESSION (your offline batch)")
        row = tk.Frame(sb, bg=ui.BG_CARD); row.pack(fill="x")
        ui.combo(row, self._session_var, [], width=30).pack(side="left", padx=(0, 6))
        self._session_combo = row.winfo_children()[-1]
        self._session_combo.bind("<<ComboboxSelected>>", lambda e: self._on_select_session())
        ui.button(row, "New", self._new_session).pack(side="left", padx=2)
        ui.button(row, "Export to USB…", self._export_session).pack(side="left", padx=2)
        ui.button(row, "Import…", self._import_session).pack(side="left", padx=2)

        cols = tk.Frame(self, bg=ui.BG_DEEP); cols.pack(fill="both", expand=True, padx=8, pady=6)
        left = tk.Frame(cols, bg=ui.BG_DEEP, width=320); left.pack(side="left", fill="y")
        left.pack_propagate(False)
        right = tk.Frame(cols, bg=ui.BG_DEEP); right.pack(side="left", fill="both", expand=True)

        # ---- LEFT: the batch + sync --------------------------------------
        tk.Label(left, text="THE BATCH", bg=ui.BG_DEEP, fg=ui.FG_DIM,
                 font=ui.FONT_BOLD).pack(anchor="w")
        self._list_frame = tk.Frame(left, bg=ui.BG_DEEP); self._list_frame.pack(fill="both", expand=True)
        syncbar = tk.Frame(left, bg=ui.BG_DEEP); syncbar.pack(fill="x", pady=6)
        ui.button(syncbar, "⇪  SYNC WITH LIVE", self._sync, kind="primary").pack(fill="x")
        self._sync_status = tk.Label(left, text="Compose offline. Sync when you're connected.",
                                     bg=ui.BG_DEEP, fg=ui.FG_DIM, font=ui.FONT_SMALL,
                                     wraplength=310, justify="left")
        self._sync_status.pack(fill="x")

        # ---- RIGHT: compose ----------------------------------------------
        self._compose = tk.Frame(right, bg=ui.BG_DEEP)
        self._compose.pack(fill="both", expand=True)

        kbox = ui.box(self._compose, "COMPOSE")
        krow = tk.Frame(kbox, bg=ui.BG_CARD); krow.pack(fill="x")
        for val, label in (("single", "Single"), ("carousel", "Carousel"),
                           ("trigram", "Trigram")):
            tk.Radiobutton(krow, text=label, value=val, variable=self._kind,
                           command=self._on_kind_change, bg=ui.BG_CARD, fg=ui.FG_MAIN,
                           selectcolor=ui.BG_MID, activebackground=ui.BG_CARD,
                           font=ui.FONT_SMALL).pack(side="left", padx=4)
        # trigram sub-style
        self._trig_style_row = tk.Frame(kbox, bg=ui.BG_CARD)
        tk.Label(self._trig_style_row, text="Trigram of:", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="left")
        for val, label in (("single", "3 single slices"), ("carousels", "3 carousels (slice = cover)")):
            tk.Radiobutton(self._trig_style_row, text=label, value=val,
                           variable=self._trig_style, bg=ui.BG_CARD, fg=ui.FG_MAIN,
                           selectcolor=ui.BG_MID, activebackground=ui.BG_CARD,
                           font=ui.FONT_SMALL).pack(side="left", padx=4)
        tk.Label(self._trig_style_row, text=" orient:", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="left")
        ui.combo(self._trig_style_row, self._trig_orientation, ["h", "v"], width=3).pack(side="left")

        # image source buttons (context changes with kind)
        self._src_row = tk.Frame(kbox, bg=ui.BG_CARD); self._src_row.pack(fill="x", pady=4)
        self._build_src_buttons()

        # thumbnail strip (single/carousel) OR trigram slots
        self._strip = tk.Frame(kbox, bg=ui.BG_CARD); self._strip.pack(fill="x")

        # per-image control panel
        self._ctrl_box = ui.box(self._compose, "IMAGE CONTROLS  (select an image above)")
        self._build_controls(self._ctrl_box)

        # post-level fields
        pbox = ui.box(self._compose, "POST")
        self._caption = ui.textarea(pbox, "Caption", height=3)
        ui.field(pbox, "Tags (space-separated #hashtags)", self._tags)
        meta = tk.Frame(pbox, bg=ui.BG_CARD); meta.pack(fill="x")
        ui.field(meta, "Date (YYYY-MM-DD HH:MM:SS, blank = now)", self._date)
        opt = tk.Frame(pbox, bg=ui.BG_CARD); opt.pack(fill="x", pady=(4, 0))
        tk.Checkbutton(opt, text="Comments", variable=self._allow_comments, bg=ui.BG_CARD,
                       fg=ui.FG_MAIN, selectcolor=ui.BG_MID, activebackground=ui.BG_CARD,
                       font=ui.FONT_SMALL).pack(side="left")
        tk.Checkbutton(opt, text="Allow download", variable=self._allow_dl, bg=ui.BG_CARD,
                       fg=ui.FG_MAIN, selectcolor=ui.BG_MID, activebackground=ui.BG_CARD,
                       font=ui.FONT_SMALL).pack(side="left", padx=(8, 0))
        tk.Label(opt, text="Status", bg=ui.BG_CARD, fg=ui.FG_DIM,
                 font=ui.FONT_SMALL).pack(side="left", padx=(10, 2))
        ui.combo(opt, self._status, ["published", "draft"], width=10).pack(side="left")
        ui.field(pbox, "Download URL (if allowed)", self._download_url)

        act = tk.Frame(pbox, bg=ui.BG_CARD); act.pack(fill="x", pady=(10, 2))
        ui.button(act, "Save draft", lambda: self._commit(ready=False)).pack(side="left", padx=(0, 6))
        ui.button(act, "✓ OFFLINE POST", lambda: self._commit(ready=True),
                  kind="primary").pack(side="left", padx=6)
        ui.button(act, "Clear", self._clear_compose).pack(side="left", padx=6)

        self._on_kind_change()

    def _build_src_buttons(self):
        for w in self._src_row.winfo_children():
            w.destroy()
        if self._kind.get() == "trigram":
            ui.button(self._src_row, "Choose cover & slice", self._slice_cover).pack(side="left")
            tk.Label(self._src_row, text="  then add images to a slot for carousels",
                     bg=ui.BG_CARD, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(side="left")
        else:
            ui.button(self._src_row, "Add images…", self._add_images).pack(side="left")
            ui.button(self._src_row, "Clear images", self._clear_images).pack(side="left", padx=6)

    def _build_controls(self, parent):
        # Crop mode
        r1 = tk.Frame(parent, bg=ui.BG_CARD); r1.pack(fill="x")
        tk.Label(r1, text="Fit mode", bg=ui.BG_CARD, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(side="left")
        for val, label in (("fit", "Fit (image in tile)"), ("fill", "Fill (square crop)")):
            tk.Radiobutton(r1, text=label, value=val, variable=self._c_crop, bg=ui.BG_CARD,
                           fg=ui.FG_MAIN, selectcolor=ui.BG_MID, activebackground=ui.BG_CARD,
                           font=ui.FONT_SMALL).pack(side="left", padx=4)
        tk.Checkbutton(r1, text="Post separately (split)", variable=self._c_split, bg=ui.BG_CARD,
                       fg=ui.FG_MAIN, selectcolor=ui.BG_MID, activebackground=ui.BG_CARD,
                       font=ui.FONT_SMALL).pack(side="right")

        self._slider(parent, "Image size %", self._c_size, 10, 100)
        self._slider(parent, "Focal X %", self._c_fx, 0, 100, recrop=True)
        self._slider(parent, "Focal Y %", self._c_fy, 0, 100, recrop=True)
        self._slider(parent, "Zoom %", self._c_zoom, 100, 300, recrop=True)
        self._slider(parent, "Border px", self._c_border, 0, 50)
        self._slider(parent, "Shadow", self._c_shadow, 0, 3)
        crow = tk.Frame(parent, bg=ui.BG_CARD); crow.pack(fill="x", pady=(4, 0))
        c1 = tk.Frame(crow, bg=ui.BG_CARD); c1.pack(side="left", fill="x", expand=True, padx=(0, 4))
        c2 = tk.Frame(crow, bg=ui.BG_CARD); c2.pack(side="left", fill="x", expand=True, padx=(4, 0))
        ui.field(c1, "Border color (#RRGGBB)", self._c_border_color)
        ui.field(c2, "Background matte (#RRGGBB)", self._c_bg)
        prow = tk.Frame(parent, bg=ui.BG_CARD); prow.pack(fill="x", pady=(6, 0))
        self._sel_preview = tk.Label(prow, bg=ui.BG_MID, width=12, height=6)
        self._sel_preview.pack(side="left")
        ui.button(prow, "Update crop preview", self._recrop_selected).pack(side="left", padx=8)

    def _slider(self, parent, label, var, lo, hi, recrop=False):
        row = tk.Frame(parent, bg=ui.BG_CARD); row.pack(fill="x")
        tk.Label(row, text=label, bg=ui.BG_CARD, fg=ui.FG_DIM, font=ui.FONT_SMALL,
                 width=12, anchor="w").pack(side="left")
        s = tk.Scale(row, from_=lo, to=hi, orient="horizontal", variable=var,
                     bg=ui.BG_CARD, fg=ui.FG_MAIN, troughcolor=ui.BG_MID, highlightthickness=0,
                     font=ui.FONT_SMALL, length=220)
        s.pack(side="left", fill="x", expand=True)
        if recrop:
            s.bind("<ButtonRelease-1>", lambda e: self._recrop_selected())

    def _wire_control_traces(self):
        for var in (self._c_crop, self._c_size, self._c_border, self._c_border_color,
                    self._c_bg, self._c_shadow, self._c_fx, self._c_fy, self._c_zoom,
                    self._c_split):
            var.trace_add("write", lambda *a: self._write_controls_to_selected())

    # ======================================================================
    # Kind switching
    # ======================================================================
    def _on_kind_change(self):
        if self._kind.get() == "trigram":
            self._trig_style_row.pack(fill="x", after=self._src_row.master.winfo_children()[1])
        else:
            self._trig_style_row.pack_forget()
        self._build_src_buttons()
        self._render_strip()

    # ======================================================================
    # Sessions
    # ======================================================================
    def _sessions(self):
        return [s for s in self.store.list() if s.mode == self.SUITE_MODE]

    def _refresh_sessions(self):
        sessions = self._sessions()
        names = [f"{s.name}  ·  {len(s.list_drafts())} items" for s in sessions]
        self._session_combo["values"] = names
        self._session_objs = sessions
        if sessions and self.session is None:
            self.session = sessions[0]; self._session_var.set(names[0])
        elif not sessions:
            self.session = None; self._session_var.set("")
        self._refresh_drafts()

    def _on_select_session(self):
        idx = self._session_combo.current()
        if 0 <= idx < len(self._session_objs):
            self.session = self._session_objs[idx]; self._refresh_drafts()

    def _new_session(self):
        name = simpledialog.askstring("New batch", "Batch name:", parent=self)
        if name is None:
            return
        self.session = self.store.create(name, self.SUITE_MODE)
        self._refresh_sessions()

    def _export_session(self):
        if not self.session:
            return
        dest = filedialog.askdirectory(title="Export batch to (thumb drive / folder)")
        if dest:
            out = O.export_session(self.session, dest)
            messagebox.showinfo("Exported", f"Batch exported to:\n{out}")

    def _import_session(self):
        src = filedialog.askdirectory(title="Choose an exported batch folder")
        if not src:
            return
        try:
            self.session = O.import_session(src, self.store)
        except Exception as e:
            messagebox.showerror("Import failed", str(e)); return
        self._refresh_sessions()

    def _ensure_session(self) -> bool:
        if self.session:
            return True
        self._new_session()
        return self.session is not None

    # ======================================================================
    # Image sources
    # ======================================================================
    def _new_image(self, path: str, cover: bool = False, pos: int = 0) -> O.DraftImage:
        return O.DraftImage(local_path=path, filename=os.path.basename(path),
                            is_cover=cover, sort_position=pos)

    def _add_images(self):
        paths = filedialog.askopenfilenames(
            title="Add images",
            filetypes=[("Images", "*.jpg *.jpeg *.png *.webp"), ("All", "*.*")])
        for p in paths:
            if len(self._work_images) >= O.CAROUSEL_MAX_IMAGES:
                messagebox.showinfo("Max images", f"Up to {O.CAROUSEL_MAX_IMAGES} images per post.")
                break
            self._work_images.append(self._new_image(p, cover=not self._work_images,
                                                     pos=len(self._work_images)))
        self._render_strip()

    def _clear_images(self):
        self._work_images = []
        self._sel_img = None
        self._render_strip()

    def _slice_cover(self):
        cover = filedialog.askopenfilename(
            title="Choose a 3:1 (h) or 1:3 (v) cover to slice",
            filetypes=[("Images", "*.jpg *.jpeg *.png *.webp"), ("All", "*.*")])
        if not cover:
            return
        if not self._ensure_session():
            return
        # Slice into three cover chunks (each becomes a slot's cover).
        chunks = O.slice_trigram_cover(cover, self.session.images_dir,
                                       orientation=self._trig_orientation.get(),
                                       mode=self.SUITE_MODE)
        self._trig_slots = [[c.images[0]] for c in chunks]  # cover = is_cover already
        # carry a shared group key so save can re-group
        self._trig_group_key = chunks[0].group_key
        self._sel_img = self._trig_slots[0][0]
        self._render_strip()

    def _add_to_slot(self, slot_idx: int):
        if self._trig_style.get() != "carousels":
            messagebox.showinfo("Single slices",
                                "Switch 'Trigram of' to '3 carousels' to add images to a slot.")
            return
        paths = filedialog.askopenfilenames(
            title=f"Add images to slot {slot_idx + 1}",
            filetypes=[("Images", "*.jpg *.jpeg *.png *.webp"), ("All", "*.*")])
        slot = self._trig_slots[slot_idx]
        for p in paths:
            if len(slot) >= O.CAROUSEL_MAX_IMAGES:
                break
            slot.append(self._new_image(p, cover=False, pos=len(slot)))
        self._render_strip()

    # ======================================================================
    # Thumbnail strip / slot rendering
    # ======================================================================
    def _render_strip(self):
        for w in self._strip.winfo_children():
            w.destroy()
        if self._kind.get() == "trigram":
            self._render_trig_slots()
        else:
            self._render_image_row(self._strip, self._work_images, slot_idx=None)

    def _render_trig_slots(self):
        if not any(self._trig_slots):
            tk.Label(self._strip, text="Choose a cover and slice it into three.",
                     bg=ui.BG_CARD, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(anchor="w")
            return
        labels = ("L/T", "M", "R/B")
        for i, slot in enumerate(self._trig_slots):
            row = tk.Frame(self._strip, bg=ui.BG_CARD); row.pack(fill="x", pady=2)
            tk.Label(row, text=labels[i], bg=ui.BG_CARD, fg=ui.ACCENT, font=ui.FONT_BOLD,
                     width=4).pack(side="left")
            self._render_image_row(row, slot, slot_idx=i)
            ui.button(row, "+ imgs", lambda i=i: self._add_to_slot(i)).pack(side="right")

    def _render_image_row(self, parent, images: List[O.DraftImage], slot_idx):
        if not images:
            tk.Label(parent, text="(no images)", bg=ui.BG_CARD, fg=ui.FG_DIM,
                     font=ui.FONT_SMALL).pack(side="left")
            return
        for i, im in enumerate(images):
            cell = tk.Frame(parent, bg=ui.BG_CARD); cell.pack(side="left", padx=2, pady=2)
            border = ui.ACCENT if im is self._sel_img else "#2A2A2A"
            holder = tk.Frame(cell, bg=ui.BG_CARD, highlightbackground=border,
                              highlightthickness=2)
            holder.pack()
            thumb = ui.load_thumb(im.thumb_square or im.local_path, 52)
            lbl = tk.Label(holder, bg=ui.BG_MID, width=7, height=4, cursor="hand2")
            if thumb:
                lbl.configure(image=thumb, width=52, height=52); lbl.image = thumb
            lbl.pack()
            lbl.bind("<Button-1>", lambda e, im=im: self._select_image(im))
            mv = tk.Frame(cell, bg=ui.BG_CARD); mv.pack()
            tag = "★" if im.is_cover else f"{i + 1}"
            tk.Label(mv, text=tag, bg=ui.BG_CARD, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(side="left")
            ui.button(mv, "◀", lambda im=im, s=slot_idx: self._move(im, -1, s)).pack(side="left")
            ui.button(mv, "▶", lambda im=im, s=slot_idx: self._move(im, 1, s)).pack(side="left")
            ui.button(mv, "✕", lambda im=im, s=slot_idx: self._remove(im, s), kind="danger").pack(side="left")

    def _list_for_slot(self, slot_idx) -> List[O.DraftImage]:
        return self._work_images if slot_idx is None else self._trig_slots[slot_idx]

    def _move(self, im, delta, slot_idx):
        lst = self._list_for_slot(slot_idx)
        if im not in lst:
            return
        i = lst.index(im); j = i + delta
        if 0 <= j < len(lst):
            lst[i], lst[j] = lst[j], lst[i]
            for k, x in enumerate(lst):
                x.sort_position = k
                x.is_cover = (k == 0) if slot_idx is None or self._kind.get() != "trigram" else x.is_cover
            self._render_strip()

    def _remove(self, im, slot_idx):
        lst = self._list_for_slot(slot_idx)
        if im in lst:
            # Don't allow removing a trigram slot's cover slice.
            if self._kind.get() == "trigram" and im.is_cover:
                messagebox.showinfo("Cover slice", "The slice is the slot's cover; it can't be removed.")
                return
            lst.remove(im)
            for k, x in enumerate(lst):
                x.sort_position = k
            if self._sel_img is im:
                self._sel_img = None
            self._render_strip()

    # ======================================================================
    # Per-image controls
    # ======================================================================
    def _select_image(self, im: O.DraftImage):
        self._sel_img = im
        self._loading = True
        self._c_crop.set(im.crop_mode)
        self._c_size.set(im.size_pct)
        self._c_border.set(im.border_px)
        self._c_border_color.set(im.border_color)
        self._c_bg.set(im.bg_color)
        self._c_shadow.set(im.shadow)
        self._c_fx.set(im.focus_x)
        self._c_fy.set(im.focus_y)
        self._c_zoom.set(im.zoom)
        self._c_split.set(im.split)
        self._loading = False
        self._show_sel_preview()
        self._render_strip()

    def _write_controls_to_selected(self):
        if self._loading or self._sel_img is None:
            return
        im = self._sel_img
        im.crop_mode = self._c_crop.get()
        try:
            im.size_pct = int(self._c_size.get())
            im.border_px = int(self._c_border.get())
            im.shadow = int(self._c_shadow.get())
            im.focus_x = int(self._c_fx.get())
            im.focus_y = int(self._c_fy.get())
            im.zoom = int(self._c_zoom.get())
        except (tk.TclError, ValueError):
            pass
        im.border_color = self._c_border_color.get()
        im.bg_color = self._c_bg.get()
        im.split = bool(self._c_split.get())

    def _recrop_selected(self):
        """Regenerate the selected image's square thumb with its focal/zoom."""
        if self._sel_img is None:
            return
        self._write_controls_to_selected()
        im = self._sel_img
        res = None
        if im.local_path and os.path.isfile(im.local_path):
            import snap_thumbs
            res = snap_thumbs.generate_thumbs(im.local_path, sq_size=400, asp_max=400,
                                              focus_x=im.focus_x, focus_y=im.focus_y, zoom=im.zoom)
        if res:
            im.thumb_square = res["sq_path"]; im.thumb_aspect = res["asp_path"]
            im.width = res["width"]; im.height = res["height"]
        self._show_sel_preview()
        self._render_strip()

    def _show_sel_preview(self):
        im = self._sel_img
        path = (im.thumb_square or im.local_path) if im else ""
        thumb = ui.load_thumb(path, 96)
        if thumb:
            self._sel_preview.configure(image=thumb, width=96, height=96); self._sel_preview.image = thumb
        else:
            self._sel_preview.configure(image="", width=12, height=6); self._sel_preview.image = None

    # ======================================================================
    # Commit (Save draft / OFFLINE POST)
    # ======================================================================
    def _commit(self, ready: bool):
        if not self._ensure_session():
            return
        caption = self._caption.get("1.0", "end").strip()
        tags = self._tags.get().strip()
        kind = self._kind.get()

        # Replace any draft(s) being edited.
        if self._editing_id:
            self.session.delete_draft(self._editing_id)
        if self._editing_group:
            for d in self.session.group_drafts(self._editing_group):
                self.session.delete_draft(d.draft_id)

        if kind == "trigram":
            if not all(self._trig_slots) or len(self._trig_slots) != 3:
                messagebox.showwarning("Slice first", "Choose a cover and slice it into three.")
                return
            group_key = getattr(self, "_trig_group_key", "") or O._new_id()
            for slot_idx, slot in enumerate(self._trig_slots, start=1):
                d = O.Draft(draft_id=O._new_id(), kind=O.KIND_GRAM_TRIGRAM, mode=self.SUITE_MODE,
                            caption=caption, tags=tags, group_key=group_key,
                            trigram_slot=slot_idx, trigram_orientation=self._trig_orientation.get())
                self._apply_post_fields(d)
                d.images = list(slot)
                O.generate_draft_thumbs(d)
                probs = d.validate()
                if ready and probs:
                    messagebox.showwarning("Not ready", "\n".join(probs)); return
                d.status = O.ST_READY if ready else O.ST_DRAFT
                self.session.add_draft(d)
        else:
            imgs = list(self._work_images)
            if not imgs:
                messagebox.showwarning("No images", "Add at least one image."); return
            dkind = O.KIND_GRAM_CAROUSEL if len(imgs) > 1 else O.KIND_GRAM_SINGLE
            d = O.Draft(draft_id=O._new_id(), kind=dkind, mode=self.SUITE_MODE,
                        caption=caption, tags=tags)
            self._apply_post_fields(d)
            d.images = imgs
            O.generate_draft_thumbs(d)
            probs = d.validate()
            if ready and probs:
                messagebox.showwarning("Not ready", "\n".join(probs)); return
            d.status = O.ST_READY if ready else O.ST_DRAFT
            self.session.add_draft(d)

        if self.session.over_soft_limit():
            messagebox.showinfo(
                "Big batch",
                f"This batch now holds {self.session.image_count()} images "
                f"(soft limit ~{O.SOFT_BATCH_IMAGE_LIMIT}). It'll still sync fine — "
                "but consider starting a new batch to stay friendly to the shared host.")
        self._clear_compose()
        self._refresh_sessions()

    def _apply_post_fields(self, d: O.Draft):
        d.img_status = self._status.get()
        d.post_date = self._date.get().strip()
        d.allow_comments = bool(self._allow_comments.get())
        d.allow_download = bool(self._allow_dl.get())
        d.download_url = self._download_url.get().strip()
        d.panorama_rows = int(self._panorama_rows.get())

    def _clear_compose(self):
        self._work_images = []
        self._trig_slots = [[], [], []]
        self._trig_group_key = ""
        self._sel_img = None
        self._editing_id = ""
        self._editing_group = ""
        self._tags.set(""); self._date.set(""); self._download_url.set("")
        self._status.set("published"); self._allow_comments.set(True); self._allow_dl.set(False)
        self._caption.delete("1.0", "end")
        self._render_strip()
        self._show_sel_preview()

    # ======================================================================
    # The batch list
    # ======================================================================
    def _refresh_drafts(self):
        for w in self._list_frame.winfo_children():
            w.destroy()
        if not self.session:
            tk.Label(self._list_frame, text="No batch — create one above.",
                     bg=ui.BG_DEEP, fg=ui.FG_DIM, font=ui.FONT_SMALL).pack(anchor="w", pady=8)
            return
        drafts = self.session.list_drafts()
        shown = set()
        for d in drafts:
            if d.kind == O.KIND_GRAM_TRIGRAM:
                if d.group_key in shown:
                    continue
                shown.add(d.group_key)
                self._trigram_row(drafts, d.group_key)
            else:
                self._single_row(d)

    def _single_row(self, draft: O.Draft):
        row = tk.Frame(self._list_frame, bg=ui.BG_CARD, highlightbackground="#2A2A2A",
                       highlightthickness=1); row.pack(fill="x", pady=2)
        cover = draft.cover()
        thumb = ui.load_thumb(cover.thumb_square if cover else "", 44)
        lbl = tk.Label(row, bg=ui.BG_MID, width=6, height=3)
        if thumb:
            lbl.configure(image=thumb, width=44, height=44); lbl.image = thumb
        lbl.pack(side="left", padx=4, pady=4)
        info = tk.Frame(row, bg=ui.BG_CARD); info.pack(side="left", fill="x", expand=True)
        kind_label = "Carousel" if draft.kind == O.KIND_GRAM_CAROUSEL else "Single"
        tk.Label(info, text=f"{kind_label} · {len(draft.images)} img", bg=ui.BG_CARD,
                 fg=ui.FG_MAIN, font=ui.FONT_BOLD).pack(anchor="w")
        ui.status_badge(info, draft.status).pack(anchor="w")
        if draft.error:
            tk.Label(info, text=draft.error, bg=ui.BG_CARD, fg=ui.FG_ERR, font=ui.FONT_SMALL,
                     wraplength=180, justify="left").pack(anchor="w")
        btns = tk.Frame(row, bg=ui.BG_CARD); btns.pack(side="right", padx=4)
        if draft.status != O.ST_SYNCED:
            ui.button(btns, "Edit", lambda d=draft: self._edit_single(d)).pack(pady=1)
        ui.button(btns, "Del", lambda d=draft: self._delete(d), kind="danger").pack(pady=1)

    def _trigram_row(self, drafts, group_key):
        members = sorted([d for d in drafts if d.group_key == group_key],
                         key=lambda d: d.trigram_slot)
        ready_n = O.trigram_ready_count(drafts, group_key)
        row = tk.Frame(self._list_frame, bg=ui.BG_CARD, highlightbackground=ui.FG_WARN,
                       highlightthickness=1); row.pack(fill="x", pady=2)
        strip = tk.Frame(row, bg=ui.BG_CARD); strip.pack(side="left", padx=4, pady=4)
        for m in members:
            cov = m.cover()
            thumb = ui.load_thumb(cov.thumb_square if cov else "", 32)
            lbl = tk.Label(strip, bg=ui.BG_MID, width=4, height=2)
            if thumb:
                lbl.configure(image=thumb, width=32, height=32); lbl.image = thumb
            lbl.pack(side="left", padx=1)
        info = tk.Frame(row, bg=ui.BG_CARD); info.pack(side="left", fill="x", expand=True)
        orient = members[0].trigram_orientation if members else "h"
        is_caro = any(len(m.images) > 1 for m in members)
        tk.Label(info, text=f"Trigram ({orient}{'·carousels' if is_caro else ''}) · {ready_n}/3",
                 bg=ui.BG_CARD, fg=ui.FG_MAIN, font=ui.FONT_BOLD).pack(anchor="w")
        statuses = {m.status for m in members}
        if ready_n < 3:
            tk.Label(info, text=f"queued — waiting for {3 - ready_n} more",
                     bg=ui.BG_CARD, fg=ui.FG_WARN, font=ui.FONT_SMALL).pack(anchor="w")
        else:
            st = "synced" if statuses == {O.ST_SYNCED} else (
                "failed" if O.ST_FAILED in statuses else (
                    "ready" if O.ST_READY in statuses else "draft"))
            ui.status_badge(info, st).pack(anchor="w")
        err = next((m.error for m in members if m.error), "")
        if err:
            tk.Label(info, text=err, bg=ui.BG_CARD, fg=ui.FG_ERR, font=ui.FONT_SMALL,
                     wraplength=180, justify="left").pack(anchor="w")
        btns = tk.Frame(row, bg=ui.BG_CARD); btns.pack(side="right", padx=4)
        if statuses != {O.ST_SYNCED}:
            ui.button(btns, "Edit", lambda gk=group_key: self._edit_trigram(gk)).pack(pady=1)
        ui.button(btns, "Del", lambda ms=members: self._delete_group(ms), kind="danger").pack(pady=1)

    # ======================================================================
    # Edit (UNSYNCED drafts only — reload into the composer)
    # ======================================================================
    def _edit_single(self, draft: O.Draft):
        self._clear_compose()
        self._editing_id = draft.draft_id
        self._kind.set(O.KIND_GRAM_CAROUSEL == draft.kind and "carousel" or "single")
        self._work_images = [O.DraftImage.from_dict(im.to_dict()) for im in draft.images]
        self._load_post_fields(draft)
        self._on_kind_change()
        if self._work_images:
            self._select_image(self._work_images[0])

    def _edit_trigram(self, group_key: str):
        self._clear_compose()
        members = sorted(self.session.group_drafts(group_key), key=lambda d: d.trigram_slot)
        if not members:
            return
        self._editing_group = group_key
        self._trig_group_key = group_key
        self._kind.set("trigram")
        self._trig_orientation.set(members[0].trigram_orientation)
        self._trig_style.set("carousels" if any(len(m.images) > 1 for m in members) else "single")
        self._trig_slots = [[O.DraftImage.from_dict(im.to_dict()) for im in m.images] for m in members]
        self._load_post_fields(members[0])
        self._on_kind_change()
        if self._trig_slots and self._trig_slots[0]:
            self._select_image(self._trig_slots[0][0])

    def _load_post_fields(self, draft: O.Draft):
        self._caption.delete("1.0", "end"); self._caption.insert("1.0", draft.caption)
        self._tags.set(draft.tags); self._date.set(draft.post_date)
        self._status.set(draft.img_status)
        self._allow_comments.set(draft.allow_comments)
        self._allow_dl.set(draft.allow_download)
        self._download_url.set(draft.download_url)
        self._panorama_rows.set(draft.panorama_rows)

    def _delete(self, draft):
        if messagebox.askyesno("Delete", "Delete this item from the batch?"):
            self.session.delete_draft(draft.draft_id); self._refresh_sessions()

    def _delete_group(self, members):
        if messagebox.askyesno("Delete trigram", "Delete all three chunks of this trigram?"):
            for m in members:
                self.session.delete_draft(m.draft_id)
            self._refresh_sessions()

    # ======================================================================
    # SYNC WITH LIVE
    # ======================================================================
    def _connection(self) -> Optional[SobConnection]:
        cfg = getattr(self.app, "_config", {}) or {}
        url = (cfg.get("url") or "").strip()
        key = (cfg.get("api_key") or "").strip()
        if not url or not key:
            messagebox.showwarning("Not connected",
                                   "Set the site URL + API key on the POST tab first.")
            return None
        return SobConnection(url, key)

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
        self._sync_status.configure(text=f"Syncing {len(ready)} item(s)…", fg=ui.FG_WARN)
        poster = GramPoster(conn)

        def worker():
            def on_event(phase, draft, msg):
                self.after(0, self._refresh_drafts)
            engine = O.SyncEngine(self.session, poster, on_event=on_event)
            results = engine.sync_all(ready)
            ok = sum(1 for r in results.values() if r.ok)
            self.after(0, lambda: self._sync_done(ok, len(results)))

        threading.Thread(target=worker, daemon=True).start()

    def _sync_done(self, ok, total):
        color = ui.FG_OK if ok == total else ui.FG_ERR
        self._sync_status.configure(
            text=f"Synced {ok}/{total} & verified. Trigrams promote atomically once all three land.",
            fg=color)
        self._refresh_sessions()


def build_gram_mode(parent, app) -> GramMode:
    """Factory used by main.py to mount the BATCH, PLEASE panel."""
    return GramMode(parent, app)
# ===== SNAPSMACK EOF =====
