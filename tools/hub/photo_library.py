"""SNAP SLAPPER photo library: Picasa-style source rail, grid, and info rail."""

import datetime
import json
import os
import queue
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, ttk

from PIL import Image, ImageOps, ImageTk

BG, CARD, INK, DIM, ACCENT, FIELD, BORDER = (
    "#0a0a0a", "#141414", "#e6e6e6", "#8a8a8a", "#39ff14", "#1c1c1c", "#2a2a2a")
IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".webp", ".gif", ".bmp", ".tif", ".tiff"}


class PhotoLibrary(tk.Toplevel):
    def __init__(self, parent, library_root, build_version, state_path=None):
        super().__init__(parent)
        self.title(f"SNAP SLAPPER — Photo Library   (build {build_version})")
        self.configure(bg=BG)
        self.geometry("1240x780")
        self.minsize(900, 580)
        self.library_root = library_root
        self.state_path = state_path
        self.rows, self.visible, self.photos = [], [], []
        self.sources = {}
        self.selected = None
        self.render_limit = 120
        self.scan_token = 0
        self.scan_queue = queue.Queue()
        self._build()
        self.refresh_sources()

    @staticmethod
    def _button(parent, text, command):
        btn = tk.Button(parent, text=text, command=command, bg=FIELD, fg=INK,
                        activebackground=ACCENT, activeforeground=BG, relief="flat",
                        font=("Segoe UI", 9, "bold"), cursor="hand2")
        base_bg, base_fg = btn.cget("bg"), btn.cget("fg")
        btn.bind("<Enter>", lambda _e: btn.configure(bg=ACCENT, fg=BG))
        btn.bind("<Leave>", lambda _e: btn.configure(bg=base_bg, fg=base_fg))
        return btn

    def _build(self):
        top = tk.Frame(self, bg="#101010", height=54)
        top.pack(fill="x")
        top.pack_propagate(False)
        tk.Label(top, text="SNAP SLAPPER", bg="#101010", fg=ACCENT,
                 font=("Segoe UI Black", 17, "bold")).pack(side="left", padx=(16, 20))
        self.heading = tk.Label(top, text="PHOTO LIBRARY", bg="#101010", fg=INK,
                                font=("Segoe UI", 11, "bold"))
        self.heading.pack(side="left")
        self.search_var = tk.StringVar()
        search = tk.Entry(top, textvariable=self.search_var, bg=FIELD, fg=INK,
                          insertbackground=INK, relief="flat", font=("Segoe UI", 10), width=32)
        search.pack(side="right", padx=16, ipady=6)
        tk.Label(top, text="SEARCH", bg="#101010", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(side="right")
        self.search_var.trace_add("write", lambda *_: self.filter_rows())

        panes = tk.PanedWindow(self, orient="horizontal", bg=BORDER, sashwidth=4, relief="flat", bd=0)
        panes.pack(fill="both", expand=True)
        left = tk.Frame(panes, bg="#111111", width=220)
        centre = tk.Frame(panes, bg=BG)
        right = tk.Frame(panes, bg="#111111", width=285)
        panes.add(left, minsize=180)
        panes.add(centre, minsize=450, stretch="always")
        panes.add(right, minsize=240)

        tk.Label(left, text="LIBRARIES", bg="#111111", fg=ACCENT,
                 font=("Segoe UI", 9, "bold")).pack(anchor="w", padx=14, pady=(16, 8))
        style = ttk.Style(self)
        style.configure("Slapper.Treeview", background="#111111", fieldbackground="#111111",
                        foreground=INK, borderwidth=0, relief="flat", rowheight=25,
                        font=("Segoe UI", 10))
        style.map("Slapper.Treeview", background=[("selected", ACCENT)],
                  foreground=[("selected", BG)])
        self.source_tree = ttk.Treeview(left, style="Slapper.Treeview", show="tree", selectmode="browse")
        self.source_tree.pack(fill="both", expand=True, padx=8)
        self.source_tree.bind("<<TreeviewSelect>>", self._source_selected)
        self.source_tree.bind("<<TreeviewOpen>>", self._tree_opened)
        self._button(left, "+ OPEN LOCAL FOLDER", self.choose_folder).pack(
            fill="x", padx=12, pady=(12, 5), ipady=5)
        self._button(left, "REMOVE FOLDER", self.remove_folder).pack(
            fill="x", padx=12, pady=(0, 12), ipady=4)

        controls = tk.Frame(centre, bg="#101010")
        controls.pack(fill="x")
        tk.Label(controls, text="SORT", bg="#101010", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(side="left", padx=(12, 5), pady=8)
        self.sort_var = tk.StringVar(value="Date (newest)")
        sort = tk.OptionMenu(controls, self.sort_var, "Date (newest)", "Date (oldest)",
                             "Filename A–Z", "Filename Z–A", command=lambda _v: self.filter_rows())
        sort.configure(bg=FIELD, fg=INK, activebackground=ACCENT, activeforeground=BG,
                       relief="flat", highlightthickness=0, font=("Segoe UI", 9))
        sort["menu"].configure(bg=FIELD, fg=INK, activebackground=ACCENT, activeforeground=BG)
        sort.pack(side="left", pady=6)
        tk.Label(controls, text="DATE", bg="#101010", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(side="left", padx=(14, 5))
        self.date_var = tk.StringVar(value="All dates")
        self.date_menu = tk.OptionMenu(controls, self.date_var, "All dates",
                                       command=lambda _v: self.filter_rows())
        self.date_menu.configure(bg=FIELD, fg=INK, activebackground=ACCENT, activeforeground=BG,
                                 relief="flat", highlightthickness=0, font=("Segoe UI", 9))
        self.date_menu["menu"].configure(bg=FIELD, fg=INK, activebackground=ACCENT, activeforeground=BG)
        self.date_menu.pack(side="left", pady=6)
        self.scan_status = tk.Label(controls, text="", bg="#101010", fg=DIM,
                                    font=("Segoe UI", 9))
        self.scan_status.pack(side="left", padx=12)
        self.thumb_size = tk.IntVar(value=150)
        zoom = tk.Scale(controls, from_=90, to=230, variable=self.thumb_size, orient="horizontal",
                        showvalue=False, length=130, bg="#101010", fg=INK, troughcolor=FIELD,
                        activebackground=ACCENT, highlightthickness=0, bd=0)
        zoom.pack(side="right", padx=(4, 12))
        zoom.bind("<ButtonRelease-1>", lambda _e: self.render_grid())
        tk.Label(controls, text="THUMBNAILS", bg="#101010", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(side="right")

        self.canvas = tk.Canvas(centre, bg=BG, highlightthickness=0)
        scroll = tk.Scrollbar(centre, orient="vertical", command=self.canvas.yview)
        self.canvas.configure(yscrollcommand=scroll.set)
        scroll.pack(side="right", fill="y")
        self.canvas.pack(side="left", fill="both", expand=True)
        self.grid = tk.Frame(self.canvas, bg=BG)
        self.grid_id = self.canvas.create_window((0, 0), window=self.grid, anchor="nw")
        self.grid.bind("<Configure>", lambda _e: self.canvas.configure(scrollregion=self.canvas.bbox("all")))
        self.canvas.bind("<Configure>", lambda e: self.canvas.itemconfigure(self.grid_id, width=e.width))
        self.canvas.bind("<Enter>", lambda _e: self.canvas.bind_all("<MouseWheel>", self._wheel))
        self.canvas.bind("<Leave>", lambda _e: self.canvas.unbind_all("<MouseWheel>"))

        tk.Label(right, text="PHOTO INFO", bg="#111111", fg=ACCENT,
                 font=("Segoe UI", 9, "bold")).pack(anchor="w", padx=14, pady=(16, 10))
        self.preview = tk.Label(right, bg=FIELD, fg=DIM, text="Select a photo", width=28, height=14)
        self.preview.pack(fill="x", padx=14)
        self.info = tk.Label(right, text="", bg="#111111", fg=INK, justify="left",
                             anchor="nw", wraplength=250, font=("Segoe UI", 9))
        self.info.pack(fill="both", expand=True, padx=14, pady=14)
        self._button(right, "OPEN PHOTO", self.open_selected).pack(
            fill="x", padx=14, pady=(0, 5), ipady=5)
        self._button(right, "OPEN IN WINDOWS", self.open_external).pack(
            fill="x", padx=14, pady=(0, 14), ipady=4)

    def _wheel(self, event):
        self.canvas.yview_scroll(int(-event.delta / 120), "units")

    def refresh_sources(self):
        for item in self.source_tree.get_children(""):
            self.source_tree.delete(item)
        self.sources.clear()
        self.blog_group = self.source_tree.insert("", "end", text="▣  BLOG ARCHIVES", open=True)
        self.local_group = self.source_tree.insert("", "end", text="▦  LOCAL FOLDERS", open=True)
        try:
            names = sorted(os.listdir(self.library_root), key=str.lower)
        except OSError:
            names = []
        for name in names:
            folder = os.path.join(self.library_root, name)
            if os.path.isfile(os.path.join(folder, "index.json")):
                item = self.source_tree.insert(self.blog_group, "end", text=name)
                self.sources[item] = ("shared", folder)
        for folder in self._saved_folders():
            self._add_local_root(folder)

    def _add_local_root(self, folder):
        label = os.path.basename(folder) or folder
        item = self.source_tree.insert(self.local_group, "end", text=label, open=False)
        self.sources[item] = ("folder", folder)
        self._add_folder_dummy(item, folder)
        return item

    def _add_folder_dummy(self, item, folder):
        try:
            has_dirs = any(entry.is_dir() and not entry.name.startswith(".")
                           for entry in os.scandir(folder))
        except OSError:
            has_dirs = False
        if has_dirs:
            self.source_tree.insert(item, "end", text="Loading…", tags=("dummy",))

    def _tree_opened(self, _event=None):
        item = self.source_tree.focus()
        source = self.sources.get(item)
        if not source or source[0] != "folder":
            return
        children = self.source_tree.get_children(item)
        if not children or "dummy" not in self.source_tree.item(children[0], "tags"):
            return
        self.source_tree.delete(children[0])
        folder = source[1]
        try:
            dirs = sorted((entry for entry in os.scandir(folder)
                           if entry.is_dir() and not entry.name.startswith(".")),
                          key=lambda entry: entry.name.lower())
        except OSError:
            dirs = []
        for entry in dirs:
            child = self.source_tree.insert(item, "end", text=entry.name, open=False)
            self.sources[child] = ("folder", entry.path)
            self._add_folder_dummy(child, entry.path)

    def choose_folder(self):
        folder = filedialog.askdirectory(title="Choose a photo folder", parent=self)
        if not folder:
            return
        existing = next((item for item, source in self.sources.items()
                         if source == ("folder", folder) and self.source_tree.parent(item) == self.local_group), None)
        item = existing or self._add_local_root(folder)
        self._save_folders()
        self.source_tree.selection_set(item)
        self.source_tree.focus(item)
        self.source_tree.see(item)
        self.load_source("folder", folder, os.path.basename(folder) or folder)

    def remove_folder(self):
        selected = self.source_tree.selection()
        if not selected:
            return
        item = selected[0]
        while self.source_tree.parent(item) and self.source_tree.parent(item) != self.local_group:
            item = self.source_tree.parent(item)
        source = self.sources.get(item)
        if not source or source[0] != "folder":
            return
        self.scan_token += 1
        root_path = source[1]
        for key, value in list(self.sources.items()):
            if value[0] == "folder" and (value[1] == root_path or value[1].startswith(root_path + os.sep)):
                del self.sources[key]
        self.source_tree.delete(item)
        self._save_folders()
        self.rows, self.visible = [], []
        self.heading.configure(text="PHOTO LIBRARY")
        self._refresh_dates()
        self.render_grid()

    def _saved_folders(self):
        if not self.state_path:
            return []
        try:
            with open(self.state_path, "r", encoding="utf-8") as fh:
                rows = json.load(fh)
            return [os.path.abspath(p) for p in rows if isinstance(p, str) and os.path.isdir(p)]
        except (OSError, ValueError, TypeError):
            return []

    def _save_folders(self):
        if not self.state_path:
            return
        folders = sorted({path for item, (kind, path) in self.sources.items()
                          if kind == "folder" and self.source_tree.parent(item) == self.local_group}, key=str.lower)
        try:
            os.makedirs(os.path.dirname(self.state_path), exist_ok=True)
            temp = self.state_path + ".tmp"
            with open(temp, "w", encoding="utf-8") as fh:
                json.dump(folders, fh, indent=2)
            os.replace(temp, self.state_path)
        except OSError:
            pass

    def _source_selected(self, _event=None):
        selected = self.source_tree.selection()
        if selected:
            item = selected[0]
            source = self.sources.get(item)
            if source:
                self.load_source(*source, self.source_tree.item(item, "text"))

    def load_source(self, kind, folder, label):
        self.scan_token += 1          # invalidate any older background folder scan
        rows = []
        if kind == "shared":
            try:
                with open(os.path.join(folder, "index.json"), "r", encoding="utf-8") as fh:
                    images = (json.load(fh) or {}).get("images", {})
            except (OSError, ValueError, TypeError) as exc:
                messagebox.showerror("Library unavailable", str(exc), parent=self)
                return
            for image in images.values():
                rel = image.get("thumb_file") or ""
                path = os.path.normpath(os.path.join(folder, rel)) if rel else ""
                if path and os.path.isfile(path):
                    rows.append({"path": path, "title": image.get("title") or os.path.basename(path),
                                 "description": image.get("description") or "",
                                 "tags": image.get("tags") or []})
        else:
            token = self.scan_token
            self.heading.configure(text=f"{label.lstrip('▣▦ ')}  ·  scanning…")
            self.scan_status.configure(text="Scanning folders in background…", fg=ACCENT)
            self.rows, self.visible = [], []
            self.render_grid()
            threading.Thread(target=self._scan_folder, args=(folder, token, label), daemon=True).start()
            self.after(80, self._poll_scan)
            return
        self.rows = rows
        self.heading.configure(text=f"{label.lstrip('▣▦ ')}  ·  {len(rows):,} photos")
        self.scan_status.configure(text="")
        self._refresh_dates()
        self.filter_rows()

    def _scan_folder(self, folder, token, label):
        rows = []
        try:
            for base, dirs, files in os.walk(folder):
                dirs[:] = [d for d in dirs if not d.startswith(".")]
                for name in files:
                    if os.path.splitext(name)[1].lower() in IMAGE_EXTS:
                        path = os.path.join(base, name)
                        try:
                            modified = os.path.getmtime(path)
                        except OSError:
                            modified = 0
                        rows.append({"path": path, "title": os.path.splitext(name)[0],
                                     "description": "", "tags": [], "modified": modified})
            self.scan_queue.put((token, label, rows, None))
        except Exception as exc:
            self.scan_queue.put((token, label, [], str(exc)))

    def _poll_scan(self):
        try:
            token, label, rows, error = self.scan_queue.get_nowait()
        except queue.Empty:
            self.after(80, self._poll_scan)
            return
        if token != self.scan_token:
            return
        if error:
            self.scan_status.configure(text="Scan failed", fg="#ff5555")
            messagebox.showerror("Folder scan failed", error, parent=self)
            return
        self.rows = rows
        self.heading.configure(text=f"{label.lstrip('▣▦ ')}  ·  {len(rows):,} photos")
        self.scan_status.configure(text=f"{len(rows):,} photos", fg=DIM)
        self._refresh_dates()
        self.filter_rows()

    def _refresh_dates(self):
        months = set()
        for row in self.rows:
            stamp = self._row_mtime(row)
            if stamp:
                months.add(datetime.datetime.fromtimestamp(stamp).strftime("%Y-%m"))
        choices = ["All dates"] + sorted(months, reverse=True)
        menu = self.date_menu["menu"]
        menu.delete(0, "end")
        for choice in choices:
            menu.add_command(label=choice, command=lambda value=choice: (
                self.date_var.set(value), self.filter_rows()))
        if self.date_var.get() not in choices:
            self.date_var.set("All dates")

    def filter_rows(self):
        query = self.search_var.get().strip().lower()
        self.visible = [row for row in self.rows if not query or query in " ".join((
            row.get("title", ""), row.get("description", ""), os.path.basename(row["path"]),
            " ".join(map(str, row.get("tags", []))))).lower()]
        date_filter = self.date_var.get()
        if date_filter != "All dates":
            self.visible = [row for row in self.visible if self._row_mtime(row) and
                            datetime.datetime.fromtimestamp(self._row_mtime(row)).strftime("%Y-%m") == date_filter]
        order = self.sort_var.get()
        if order == "Date (newest)":
            self.visible.sort(key=self._row_mtime, reverse=True)
        elif order == "Date (oldest)":
            self.visible.sort(key=self._row_mtime)
        elif order == "Filename Z–A":
            self.visible.sort(key=lambda row: os.path.basename(row["path"]).lower(), reverse=True)
        else:
            self.visible.sort(key=lambda row: os.path.basename(row["path"]).lower())
        self.render_limit = 120
        self.render_grid()

    @staticmethod
    def _mtime(path):
        try:
            return os.path.getmtime(path)
        except OSError:
            return 0

    @classmethod
    def _row_mtime(cls, row):
        return row["modified"] if "modified" in row else cls._mtime(row["path"])

    def render_grid(self):
        for child in self.grid.winfo_children():
            child.destroy()
        self.photos = []
        rows = self.visible[:self.render_limit]
        size = self.thumb_size.get()
        columns = max(2, self.canvas.winfo_width() // (size + 22))
        for col in range(columns):
            self.grid.grid_columnconfigure(col, weight=1, uniform="photos")
        for i, row in enumerate(rows):
            card = tk.Frame(self.grid, bg=CARD, cursor="hand2")
            card.grid(row=i // columns, column=i % columns, sticky="nsew", padx=5, pady=5)
            try:
                with Image.open(row["path"]) as image:
                    thumb = ImageOps.fit(image.convert("RGB"), (size, int(size * .75)),
                                         method=Image.Resampling.LANCZOS)
                photo = ImageTk.PhotoImage(thumb)
                self.photos.append(photo)
                pic = tk.Label(card, image=photo, bg=CARD, cursor="hand2")
            except Exception:
                pic = tk.Label(card, text="PREVIEW\nUNAVAILABLE", width=20, height=7,
                               bg=FIELD, fg=DIM, cursor="hand2")
            pic.pack(fill="x", padx=5, pady=(5, 2))
            title = tk.Label(card, text=row.get("title") or "Untitled", bg=CARD, fg=INK,
                             anchor="w", font=("Segoe UI", 8), cursor="hand2")
            title.pack(fill="x", padx=6, pady=(0, 5))
            for widget in (card, pic, title):
                widget.bind("<Button-1>", lambda _e, r=row: self.select_photo(r))
                widget.bind("<Double-Button-1>", lambda _e, r=row: self.open_viewer(r))
        if not rows:
            tk.Label(self.grid, text="No photographs here yet.", bg=BG, fg=DIM,
                     font=("Segoe UI", 12)).grid(row=0, column=0, columnspan=columns, pady=80)
        elif len(self.visible) > len(rows):
            more = self._button(self.grid, f"LOAD MORE  ·  {len(rows):,} of {len(self.visible):,}", self.load_more)
            more.grid(row=(len(rows) + columns - 1) // columns, column=0,
                      columnspan=columns, sticky="ew", padx=30, pady=15, ipady=6)
        self.canvas.yview_moveto(0)

    def load_more(self):
        self.render_limit += 120
        self.render_grid()

    def select_photo(self, row):
        self.selected = row
        path = row["path"]
        try:
            with Image.open(path) as image:
                size = image.size
                exif = image.getexif()
                preview = ImageOps.contain(image.convert("RGB"), (255, 255), method=Image.Resampling.LANCZOS)
            photo = ImageTk.PhotoImage(preview)
            self.preview.configure(image=photo, text="", width=0, height=0)
            self.preview.image = photo
            text = (f"{row.get('title') or 'Untitled'}\n\n{size[0]} × {size[1]} pixels\n"
                    f"{os.path.getsize(path) / 1048576:.1f} MB\n"
                    f"Modified {datetime.datetime.fromtimestamp(os.path.getmtime(path)):%Y-%m-%d %H:%M}\n\n{path}")
            camera = " ".join(filter(None, (str(exif.get(271, "")).strip(), str(exif.get(272, "")).strip())))
            taken = str(exif.get(36867, exif.get(306, ""))).strip()
            if camera or taken:
                text += "\n\n" + "\n".join(filter(None, (
                    f"Camera: {camera}" if camera else "", f"Taken: {taken}" if taken else "")))
            if row.get("description"):
                text += f"\n\n{row['description']}"
            self.info.configure(text=text)
        except Exception as exc:
            self.preview.configure(image="", text="Preview unavailable", width=28, height=14)
            self.info.configure(text=f"{path}\n\n{exc}")

    def open_path(self, path):
        try:
            os.startfile(path)
        except Exception as exc:
            messagebox.showerror("Open failed", str(exc), parent=self)

    def open_selected(self):
        if self.selected:
            self.open_viewer(self.selected)

    def open_external(self):
        if self.selected:
            self.open_path(self.selected["path"])

    def open_viewer(self, row):
        viewer = getattr(self, "viewer", None)
        if not viewer or not viewer.winfo_exists():
            viewer = tk.Toplevel(self)
            self.viewer = viewer
            viewer.title("SNAP SLAPPER — Viewer")
            viewer.configure(bg="#050505")
            viewer.geometry("1100x760")
            viewer.minsize(640, 420)
            viewer.transient(self)
            viewer.bind("<Escape>", lambda _e: viewer.destroy())
            viewer.bind("<Left>", lambda _e: self.viewer_step(-1))
            viewer.bind("<Right>", lambda _e: self.viewer_step(1))
            viewer.bind("<space>", lambda _e: self.viewer_step(1))
            viewer.bind("<F11>", lambda _e: viewer.attributes("-fullscreen", not bool(viewer.attributes("-fullscreen"))))
            viewer.bind("<Configure>", self._viewer_resized)
            bar = tk.Frame(viewer, bg="#101010")
            bar.pack(fill="x")
            self.viewer_title = tk.Label(bar, text="", bg="#101010", fg=INK,
                                         font=("Segoe UI", 10, "bold"))
            self.viewer_title.pack(side="left", padx=14, pady=10)
            self._button(bar, "CLOSE", viewer.destroy).pack(side="right", padx=(5, 12), pady=6)
            self._button(bar, "OPEN IN WINDOWS", self.open_external).pack(side="right", pady=6)
            self.viewer_image = tk.Label(viewer, bg="#050505", fg=DIM, text="Loading…")
            self.viewer_image.pack(fill="both", expand=True, padx=12, pady=12)
            nav = tk.Frame(viewer, bg="#101010")
            nav.pack(fill="x")
            self._button(nav, "← PREVIOUS", lambda: self.viewer_step(-1)).pack(
                side="left", padx=12, pady=7, ipadx=10)
            self._button(nav, "↺", lambda: self.rotate_viewer(90)).pack(side="left", pady=7, ipadx=8)
            self._button(nav, "↻", lambda: self.rotate_viewer(-90)).pack(side="left", padx=5, pady=7, ipadx=8)
            self.viewer_count = tk.Label(nav, text="", bg="#101010", fg=DIM,
                                         font=("Segoe UI", 9))
            self.viewer_count.pack(side="left", expand=True)
            self._button(nav, "NEXT →", lambda: self.viewer_step(1)).pack(
                side="right", padx=12, pady=7, ipadx=10)
        old = getattr(self, "viewer_row", None)
        self.viewer_row = row
        if not old or old.get("path") != row.get("path"):
            self.viewer_rotation = 0
        self.selected = row
        self._render_viewer()
        viewer.deiconify()
        viewer.lift()
        viewer.focus_force()

    def _viewer_resized(self, _event=None):
        if getattr(self, "_viewer_resize_job", None):
            self.after_cancel(self._viewer_resize_job)
        self._viewer_resize_job = self.after(120, self._render_viewer)

    def _render_viewer(self):
        viewer = getattr(self, "viewer", None)
        row = getattr(self, "viewer_row", None)
        if not viewer or not viewer.winfo_exists() or not row:
            return
        path = row["path"]
        width = max(320, viewer.winfo_width() - 40)
        height = max(240, viewer.winfo_height() - 145)
        try:
            with Image.open(path) as image:
                source = image.convert("RGB")
                if getattr(self, "viewer_rotation", 0):
                    source = source.rotate(self.viewer_rotation, expand=True)
                rendered = ImageOps.contain(source, (width, height),
                                             method=Image.Resampling.LANCZOS)
            photo = ImageTk.PhotoImage(rendered)
            self.viewer_image.configure(image=photo, text="")
            self.viewer_image.image = photo
        except Exception as exc:
            self.viewer_image.configure(image="", text=f"Could not open image\n\n{exc}")
        title = row.get("title") or os.path.basename(path)
        self.viewer_title.configure(text=title)
        try:
            index = next(i for i, item in enumerate(self.visible) if item["path"] == path)
            self.viewer_count.configure(text=f"{index + 1:,} of {len(self.visible):,}   ·   ←/→ to browse   ·   Esc to close")
        except StopIteration:
            self.viewer_count.configure(text="")

    def viewer_step(self, amount):
        row = getattr(self, "viewer_row", None)
        if not row or not self.visible:
            return
        try:
            index = next(i for i, item in enumerate(self.visible) if item["path"] == row["path"])
        except StopIteration:
            index = 0
        index = (index + amount) % len(self.visible)
        self.viewer_row = self.visible[index]
        self.viewer_rotation = 0
        self.selected = self.viewer_row
        self.select_photo(self.viewer_row)
        self._render_viewer()

    def rotate_viewer(self, degrees):
        self.viewer_rotation = (getattr(self, "viewer_rotation", 0) + degrees) % 360
        self._render_viewer()
