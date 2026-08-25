"""SNAP SLAPPER photo library: Picasa-style source rail, grid, and info rail."""

import datetime
import glob
import hashlib
import json
import os
import queue
import shutil
import subprocess
import threading
import tkinter as tk
from tkinter import filedialog, messagebox, simpledialog, ttk

from PIL import Image, ImageEnhance, ImageFilter, ImageOps, ImageTk

import photo_manager
from editor_ui import EditorWindow

BG, CARD, INK, DIM, ACCENT, FIELD, BORDER = (
    "#0a0a0a", "#141414", "#e6e6e6", "#8a8a8a", "#39ff14", "#1c1c1c", "#2a2a2a")
IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".webp", ".gif", ".bmp", ".tif", ".tiff",
              ".dng", ".nef", ".cr2", ".cr3", ".arw", ".orf", ".rw2", ".raf"}


class PhotoLibrary(tk.Toplevel):
    def __init__(self, parent, library_root, build_version, state_path=None):
        super().__init__(parent)
        self.title(f"SNAP SLAPPER — Photo Library   (build {build_version})")
        self.configure(bg=BG)
        self.geometry("1240x780")
        self.minsize(900, 580)
        self.library_root = library_root
        self.state_path = state_path
        self.tools_path = os.path.join(os.path.dirname(state_path), "external_tools.json") if state_path else None
        self.metadata_path = os.path.join(os.path.dirname(state_path), "photo_metadata.json") if state_path else None
        state_dir = os.path.dirname(state_path) if state_path else ""
        self.export_settings_path = os.path.join(state_dir, "export_settings.json") if state_dir else None
        self.albums_path = os.path.join(state_dir, "albums.json") if state_dir else None
        self.trash_path = os.path.join(state_dir, "trash_manifest.json") if state_dir else None
        self.trash_root = os.path.join(state_dir, "trash") if state_dir else None
        self.thumb_cache = os.path.join(state_dir, "thumbnail_cache") if state_dir else None
        self.rows, self.visible, self.photos = [], [], []
        self.selected_paths = set()
        self.sources = {}
        self.selected = None
        self.current_source = None
        self.current_signature = None
        self.external_tools = self._load_external_tools()
        self.metadata = self._load_metadata()
        export_settings = photo_manager.load_json(self.export_settings_path, {}) if self.export_settings_path else {}
        self.add_copyright_var = tk.BooleanVar(value=bool(export_settings.get("add_copyright", False)))
        self.copyright_var = tk.StringVar(value=str(export_settings.get("copyright", "")))
        self.render_limit = 120
        self._single_click_job = None
        self._grid_generation = 0
        self.card_widgets = {}
        self.scan_token = 0
        self.scan_queue = queue.Queue()
        self._build()
        self.refresh_sources()
        self.after(8000, self._watch_source)

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
        try:
            style.theme_use("clam")
        except tk.TclError:
            pass
        style.configure("Slapper.Treeview", background="#111111", fieldbackground="#111111",
                        foreground=INK, borderwidth=0, relief="flat", rowheight=25,
                        font=("Segoe UI", 10))
        style.map("Slapper.Treeview", background=[("selected", ACCENT)],
                  foreground=[("selected", BG)])
        style.configure("Slapper.Vertical.TScrollbar", background="#303030",
                        troughcolor="#0a0a0a", bordercolor="#0a0a0a",
                        arrowcolor="#8a8a8a", darkcolor="#303030",
                        lightcolor="#303030", relief="flat", borderwidth=0)
        style.map("Slapper.Vertical.TScrollbar",
                  background=[("active", "#454545"), ("pressed", ACCENT)],
                  arrowcolor=[("active", INK)])
        self.source_tree = ttk.Treeview(left, style="Slapper.Treeview", show="tree", selectmode="browse")
        self.source_tree.pack(fill="both", expand=True, padx=8)
        self.source_tree.bind("<<TreeviewSelect>>", self._source_selected)
        self.source_tree.bind("<<TreeviewOpen>>", self._tree_opened)
        self._button(left, "+ OPEN LOCAL FOLDER", self.choose_folder).pack(
            fill="x", padx=12, pady=(12, 5), ipady=5)
        self._button(left, "REMOVE FOLDER", self.remove_folder).pack(
            fill="x", padx=12, pady=(0, 5), ipady=4)
        self._button(left, "MANAGE PHOTOS", self.open_manage_menu).pack(
            fill="x", padx=12, pady=(0, 5), ipady=4)
        self.auto_refresh_var = tk.BooleanVar(value=True)
        tk.Checkbutton(left, text="AUTO REFRESH", variable=self.auto_refresh_var,
                       bg="#111111", fg=DIM, selectcolor=FIELD, activebackground="#111111",
                       activeforeground=INK, font=("Segoe UI", 8, "bold")).pack(
                           anchor="w", padx=12, pady=(0, 12))

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
        tk.Label(controls, text="FILTER", bg="#101010", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(side="left", padx=(14, 5))
        self.show_var = tk.StringVar(value="All photos")
        show = tk.OptionMenu(controls, self.show_var, "All photos", "Favorites", "Rated", "Unrated",
                             "1 star", "2 stars", "3 stars", "4 stars", "5 stars",
                             "1+ stars", "2+ stars", "3+ stars", "4+ stars",
                             command=lambda _v: self.filter_rows())
        show.configure(bg=FIELD, fg=INK, activebackground=ACCENT, activeforeground=BG,
                       relief="flat", highlightthickness=0, font=("Segoe UI", 9))
        show["menu"].configure(bg=FIELD, fg=INK, activebackground=ACCENT, activeforeground=BG)
        show.pack(side="left", pady=6)
        tk.Label(controls, text="TAG", bg="#101010", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(side="left", padx=(12, 4))
        self.tag_filter_var = tk.StringVar()
        tag_filter = tk.Entry(controls, textvariable=self.tag_filter_var, width=12,
                              bg=FIELD, fg=INK, insertbackground=INK, relief="flat",
                              font=("Segoe UI", 9))
        tag_filter.pack(side="left", pady=6, ipady=3)
        self.tag_filter_var.trace_add("write", lambda *_: self.filter_rows())
        self._button(controls, "RESET", self.reset_filters).pack(side="left", padx=8, pady=6, ipady=2)

        view_controls = tk.Frame(centre, bg="#0d0d0d")
        view_controls.pack(fill="x")
        self.include_subfolders_var = tk.BooleanVar(value=True)
        tk.Checkbutton(view_controls, text="INCLUDE SUBFOLDERS",
                       variable=self.include_subfolders_var,
                       command=self.filter_rows, bg="#0d0d0d", fg=INK,
                       selectcolor=FIELD, activebackground="#0d0d0d",
                       activeforeground=INK, font=("Segoe UI", 8, "bold")).pack(
                           side="left", padx=(12, 4), pady=5)
        self.scan_status = tk.Label(view_controls, text="", bg="#0d0d0d", fg=DIM,
                                    font=("Segoe UI", 9))
        self.scan_status.pack(side="left", padx=8, pady=5)
        self.thumb_size = tk.IntVar(value=150)
        self.thumb_value = tk.Label(view_controls, text="150 px", bg="#0d0d0d", fg=INK,
                                    width=6, font=("Segoe UI", 8, "bold"))
        self.thumb_value.pack(side="right", padx=(0, 10))
        tk.Label(view_controls, text="LARGER +", bg="#0d0d0d", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(side="right")
        zoom = tk.Scale(view_controls, from_=90, to=230, variable=self.thumb_size, orient="horizontal",
                        showvalue=False, length=150, bg="#0d0d0d", fg=INK, troughcolor=FIELD,
                        activebackground=ACCENT, highlightthickness=0, bd=0)
        zoom.configure(command=lambda value: self.thumb_value.configure(text=f"{int(float(value))} px"))
        zoom.pack(side="right", padx=6)
        zoom.bind("<ButtonRelease-1>", lambda _e: self.render_grid(incremental=True))
        tk.Label(view_controls, text="− SMALLER   THUMBNAIL SIZE", bg="#0d0d0d", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(side="right")

        self.canvas = tk.Canvas(centre, bg=BG, highlightthickness=0)
        scroll = ttk.Scrollbar(centre, orient="vertical", command=self.canvas.yview,
                               style="Slapper.Vertical.TScrollbar")
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
        details = tk.Frame(right, bg="#111111")
        details.pack(fill="x", padx=14, pady=(10, 0))
        self.favorite_var = tk.BooleanVar(value=False)
        tk.Checkbutton(details, text="♥ FAVORITE", variable=self.favorite_var, bg="#111111", fg=INK,
                       selectcolor=FIELD, activebackground="#111111", activeforeground=ACCENT,
                       command=self.save_photo_details).pack(anchor="w")
        rating_row = tk.Frame(details, bg="#111111")
        rating_row.pack(fill="x", pady=(4, 5))
        tk.Label(rating_row, text="RATING", bg="#111111", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(side="left")
        self.rating_var = tk.IntVar(value=0)
        for value in range(1, 6):
            tk.Radiobutton(rating_row, text=str(value), value=value, variable=self.rating_var,
                           command=self.save_photo_details, indicatoron=False, width=2,
                           bg=FIELD, fg=INK, selectcolor=ACCENT, activebackground=ACCENT,
                           activeforeground=BG, relief="flat").pack(side="left", padx=(5, 0))
        self._button(rating_row, "×", self.clear_rating).pack(side="left", padx=(5, 0))
        tk.Label(details, text="TAGS  ·  comma separated", bg="#111111", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(anchor="w")
        self.tags_var = tk.StringVar()
        tags = tk.Entry(details, textvariable=self.tags_var, bg=FIELD, fg=INK,
                        insertbackground=INK, relief="flat", font=("Segoe UI", 9))
        tags.pack(fill="x", pady=(4, 5), ipady=4)
        tags.bind("<Return>", lambda _e: self.save_photo_details())
        copyright_row = tk.Frame(details, bg="#111111")
        copyright_row.pack(fill="x", pady=(1, 5))
        tk.Checkbutton(copyright_row, text="Add copyright if EXIF field is missing",
                       variable=self.add_copyright_var, command=self._save_export_settings,
                       bg="#111111", fg=INK, selectcolor=FIELD,
                       activebackground="#111111", activeforeground=INK,
                       font=("Segoe UI", 8)).pack(anchor="w")
        copyright_entry = tk.Entry(copyright_row, textvariable=self.copyright_var,
                                   bg=FIELD, fg=INK, insertbackground=INK,
                                   relief="flat", font=("Segoe UI", 9))
        copyright_entry.pack(fill="x", pady=(3, 0), ipady=3)
        copyright_entry.bind("<FocusOut>", lambda _event: self._save_export_settings())
        tag_buttons = tk.Frame(details, bg="#111111")
        tag_buttons.pack(fill="x")
        self._button(tag_buttons, "SAVE DETAILS", self.save_photo_details).pack(
            side="left", fill="x", expand=True, ipady=3)
        self._button(tag_buttons, "DELETE TAG…", self.delete_tag).pack(
            side="left", padx=(5, 0), ipady=3)
        self.info = tk.Label(right, text="", bg="#111111", fg=INK, justify="left",
                             anchor="nw", wraplength=250, font=("Segoe UI", 9))
        self.info.pack(fill="both", expand=True, padx=14, pady=14)
        self._button(right, "OPEN PHOTO", self.open_selected).pack(
            fill="x", padx=14, pady=(0, 5), ipady=5)
        self._button(right, "OPEN IN WINDOWS", self.open_external).pack(
            fill="x", padx=14, pady=(0, 5), ipady=4)
        self._button(right, "OPEN WITH / EDIT COPY", self.open_with_menu).pack(
            fill="x", padx=14, pady=(0, 14), ipady=4)

    def _wheel(self, event):
        self.canvas.yview_scroll(int(-event.delta / 120), "units")

    def refresh_sources(self):
        for item in self.source_tree.get_children(""):
            self.source_tree.delete(item)
        self.sources.clear()
        self.blog_group = self.source_tree.insert("", "end", text="▣  BLOG ARCHIVES", open=True)
        self.local_group = self.source_tree.insert("", "end", text="▦  LOCAL FOLDERS", open=True)
        self.album_group = self.source_tree.insert("", "end", text="★  ALBUMS", open=True)
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
        for name, paths in sorted(self._load_albums().items(), key=lambda item: item[0].lower()):
            item = self.source_tree.insert(self.album_group, "end", text=name)
            self.sources[item] = ("album", name)

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

    def _load_albums(self):
        if not self.albums_path:
            return {}
        value = photo_manager.load_json(self.albums_path, {})
        if not isinstance(value, dict):
            return {}
        return {str(name): [str(path) for path in paths if isinstance(path, str)]
                for name, paths in value.items() if isinstance(paths, list)}

    def _save_albums(self, albums):
        if self.albums_path:
            photo_manager.atomic_json(self.albums_path, albums)

    def _source_signature(self):
        source = self.current_source
        if not source or source[0] != "folder":
            return None
        folder = source[1]
        count, latest = 0, 0
        try:
            for base, dirs, files in os.walk(folder):
                dirs[:] = [name for name in dirs if not name.startswith(".")]
                for name in files:
                    if os.path.splitext(name)[1].lower() in IMAGE_EXTS:
                        count += 1
                        latest = max(latest, self._mtime(os.path.join(base, name)))
        except OSError:
            return None
        return count, latest

    def _watch_source(self):
        try:
            if self.auto_refresh_var.get() and self.current_source and self.current_source[0] == "folder":
                signature = self._source_signature()
                if self.current_signature is not None and signature != self.current_signature:
                    self.load_source(*self.current_source)
                else:
                    self.current_signature = signature
        finally:
            if self.winfo_exists():
                self.after(8000, self._watch_source)

    def refresh_current(self):
        if self.current_source:
            self.load_source(*self.current_source)
        else:
            self.refresh_sources()

    def _source_selected(self, _event=None):
        selected = self.source_tree.selection()
        if selected:
            item = selected[0]
            source = self.sources.get(item)
            if source:
                self.load_source(*source, self.source_tree.item(item, "text"))

    def load_source(self, kind, folder, label):
        self.scan_token += 1          # invalidate any older background folder scan
        self.current_source = (kind, folder, label)
        self.selected_paths.clear()
        rows = []
        if kind == "album":
            paths = self._load_albums().get(folder, [])
            rows = [{"path": path, "title": os.path.splitext(os.path.basename(path))[0],
                     "description": "", "tags": [], "modified": self._mtime(path)}
                    for path in paths if os.path.isfile(path)]
        elif kind == "shared":
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
        self.current_signature = self._source_signature()
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
        self.current_signature = self._source_signature()
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
        candidates = self.rows
        if (not self.include_subfolders_var.get() and self.current_source and
                self.current_source[0] not in {"album", "shared"}):
            selected_folder = os.path.normcase(os.path.abspath(self.current_source[1]))
            candidates = [row for row in candidates if os.path.normcase(os.path.abspath(
                os.path.dirname(row["path"]))) == selected_folder]
        self.visible = [row for row in candidates if not query or query in " ".join((
            row.get("title", ""), row.get("description", ""), os.path.basename(row["path"]),
            " ".join(map(str, row.get("tags", []))),
            str(self._photo_meta(row).get("tags", "")))).lower()]
        date_filter = self.date_var.get()
        if date_filter != "All dates":
            self.visible = [row for row in self.visible if self._row_mtime(row) and
                            datetime.datetime.fromtimestamp(self._row_mtime(row)).strftime("%Y-%m") == date_filter]
        show = self.show_var.get()
        if show == "Favorites":
            self.visible = [row for row in self.visible if self._photo_meta(row).get("favorite")]
        elif show == "Rated":
            self.visible = [row for row in self.visible if self._photo_meta(row).get("rating", 0) > 0]
        elif show == "Unrated":
            self.visible = [row for row in self.visible if self._photo_meta(row).get("rating", 0) == 0]
        elif show.endswith("+ stars"):
            minimum = int(show[0])
            self.visible = [row for row in self.visible if self._photo_meta(row).get("rating", 0) >= minimum]
        elif show.endswith(" star") or show.endswith(" stars"):
            exact = int(show[0])
            self.visible = [row for row in self.visible if self._photo_meta(row).get("rating", 0) == exact]
        tag_filter = self.tag_filter_var.get().strip().lower()
        if tag_filter:
            self.visible = [row for row in self.visible if tag_filter in str(
                self._photo_meta(row).get("tags", "")).lower()]
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

    def reset_filters(self):
        self.search_var.set("")
        self.date_var.set("All dates")
        self.show_var.set("All photos")
        self.tag_filter_var.set("")
        self.filter_rows()

    @staticmethod
    def _mtime(path):
        try:
            return os.path.getmtime(path)
        except OSError:
            return 0

    @classmethod
    def _row_mtime(cls, row):
        return row["modified"] if "modified" in row else cls._mtime(row["path"])

    @staticmethod
    def _metadata_key(path):
        return os.path.normcase(os.path.abspath(path))

    def _load_metadata(self):
        if not self.metadata_path:
            return {}
        try:
            with open(self.metadata_path, "r", encoding="utf-8") as fh:
                data = json.load(fh)
            return data if isinstance(data, dict) else {}
        except (OSError, ValueError, TypeError):
            return {}

    def _save_metadata(self):
        if not self.metadata_path:
            return
        try:
            os.makedirs(os.path.dirname(self.metadata_path), exist_ok=True)
            temp = self.metadata_path + ".tmp"
            with open(temp, "w", encoding="utf-8") as fh:
                json.dump(self.metadata, fh, indent=2, sort_keys=True)
            os.replace(temp, self.metadata_path)
        except OSError as exc:
            messagebox.showerror("Could not save photo details", str(exc), parent=self)

    def _photo_meta(self, row_or_path):
        path = row_or_path["path"] if isinstance(row_or_path, dict) else row_or_path
        value = self.metadata.get(self._metadata_key(path), {})
        if not isinstance(value, dict):
            return {}
        try:
            rating = max(0, min(5, int(value.get("rating", 0))))
        except (TypeError, ValueError):
            rating = 0
        tags = value.get("tags", "")
        if isinstance(tags, list):
            tags = ", ".join(map(str, tags))
        return {"favorite": bool(value.get("favorite", False)),
                "rating": rating, "tags": str(tags or "")}

    def save_photo_details(self):
        if not self.selected:
            return
        key = self._metadata_key(self.selected["path"])
        details = {
            "favorite": bool(self.favorite_var.get()),
            "rating": max(0, min(5, int(self.rating_var.get()))),
            "tags": self.tags_var.get().strip(),
        }
        if details["favorite"] or details["rating"] or details["tags"]:
            self.metadata[key] = details
        else:
            self.metadata.pop(key, None)
        self._save_metadata()
        self.filter_rows()
        self._render_viewer()

    def _save_export_settings(self):
        if self.export_settings_path:
            photo_manager.atomic_json(self.export_settings_path, {
                "add_copyright": bool(self.add_copyright_var.get()),
                "copyright": self.copyright_var.get().strip(),
            })

    def _copyright_text(self):
        return self.copyright_var.get().strip() if self.add_copyright_var.get() else ""

    def delete_tag(self):
        rows = self._chosen_rows()
        if not rows:
            return
        available = sorted({tag.strip() for row in rows
                            for tag in str(self._photo_meta(row).get("tags", "")).split(",")
                            if tag.strip()}, key=str.lower)
        if not available:
            messagebox.showinfo("Delete tag", "The selected photo(s) have no tags.", parent=self)
            return
        value = simpledialog.askstring("Delete tag",
                                       "Tag to remove from the selected photo(s):\n\n" + ", ".join(available),
                                       parent=self)
        if not value:
            return
        wanted = value.strip().lower()
        changed = 0
        for row in rows:
            key = self._metadata_key(row["path"])
            details = self._photo_meta(row)
            tags = [tag.strip() for tag in str(details.get("tags", "")).split(",") if tag.strip()]
            kept = [tag for tag in tags if tag.lower() != wanted]
            if kept == tags:
                continue
            changed += 1
            details["tags"] = ", ".join(kept)
            if details.get("favorite") or details.get("rating") or details.get("tags"):
                self.metadata[key] = details
            else:
                self.metadata.pop(key, None)
        self._save_metadata()
        if self.selected:
            self.select_photo(self.selected)
        self.filter_rows()
        if not changed:
            messagebox.showinfo("Delete tag", f'No exact tag named "{value.strip()}" was found.', parent=self)

    def clear_rating(self):
        self.rating_var.set(0)
        self.save_photo_details()

    def set_rating(self, value):
        if not self.selected:
            return
        self.rating_var.set(max(0, min(5, int(value))))
        self.save_photo_details()

    def toggle_favorite(self):
        if not self.selected:
            return
        self.favorite_var.set(not self.favorite_var.get())
        self.save_photo_details()

    def open_manage_menu(self):
        menu = tk.Menu(self, tearoff=False, bg=FIELD, fg=INK,
                       activebackground=ACCENT, activeforeground=BG)
        menu.add_command(label="Refresh now", command=self.refresh_current)
        menu.add_command(label="Import photos…", command=self.import_photos)
        menu.add_separator()
        menu.add_command(label="Add selection to album…", command=self.add_to_album)
        menu.add_command(label="Bulk tags…", command=self.bulk_tags)
        menu.add_command(label="Bulk rating…", command=self.bulk_rating)
        menu.add_command(label="Toggle favorite on selection", command=self.bulk_favorite)
        menu.add_separator()
        menu.add_command(label="Copy selection…", command=lambda: self.transfer_selection(False))
        menu.add_command(label="Move selection…", command=lambda: self.transfer_selection(True))
        menu.add_command(label="Rename selected photo…", command=self.rename_selected)
        menu.add_command(label="Export selection…", command=self.export_selection)
        menu.add_command(label="Rotate selection left", command=lambda: self.rotate_selection(90))
        menu.add_command(label="Rotate selection right", command=lambda: self.rotate_selection(-90))
        menu.add_separator()
        menu.add_command(label="Find exact duplicates", command=self.find_duplicates)
        menu.add_command(label="Find blurry / dark photos", command=self.find_quality_issues)
        menu.add_command(label="Start slideshow", command=self.start_slideshow)
        menu.add_separator()
        menu.add_command(label="Move selection to SNAP SLAPPER Trash…", command=self.trash_selection)
        menu.add_command(label="Restore last trashed photo", command=self.restore_trash)
        menu.add_command(label="Back up organizer data…", command=self.backup_organizer)
        try:
            menu.tk_popup(self.winfo_pointerx(), self.winfo_pointery())
        finally:
            menu.grab_release()

    def import_photos(self):
        paths = filedialog.askopenfilenames(title="Choose photos to import", parent=self,
                                            filetypes=[("Photos", "*.jpg *.jpeg *.png *.webp *.gif *.bmp *.tif *.tiff *.dng *.nef *.cr2 *.cr3 *.arw *.orf *.rw2 *.raf"),
                                                       ("All files", "*.*")])
        if not paths:
            return
        destination = filedialog.askdirectory(title="Import into folder", parent=self)
        if not destination:
            return
        try:
            outputs = photo_manager.copy_files(paths, destination)
            messagebox.showinfo("Import complete", f"Imported {len(outputs):,} photo(s).", parent=self)
            self.refresh_current()
        except OSError as exc:
            messagebox.showerror("Import failed", str(exc), parent=self)

    def add_to_album(self):
        paths = self._chosen_paths()
        if not paths:
            return
        name = simpledialog.askstring("Album", "Album name:", parent=self)
        if not name or not name.strip():
            return
        name = name.strip()
        albums = self._load_albums()
        albums[name] = list(dict.fromkeys(albums.get(name, []) + paths))
        self._save_albums(albums)
        self.refresh_sources()
        messagebox.showinfo("Album updated", f"{len(paths):,} photo(s) added to {name}.", parent=self)

    def _update_bulk_metadata(self, update):
        rows = self._chosen_rows()
        if not rows:
            return
        for row in rows:
            key = self._metadata_key(row["path"])
            details = self._photo_meta(row)
            update(details)
            if details.get("favorite") or details.get("rating") or details.get("tags"):
                self.metadata[key] = details
            else:
                self.metadata.pop(key, None)
        self._save_metadata()
        if self.selected:
            self.select_photo(self.selected)
        self.filter_rows()

    def bulk_tags(self):
        value = simpledialog.askstring("Bulk tags", "Comma-separated tags to add:", parent=self)
        if value is None:
            return
        additions = [tag.strip() for tag in value.split(",") if tag.strip()]
        def update(details):
            existing = [tag.strip() for tag in details.get("tags", "").split(",") if tag.strip()]
            details["tags"] = ", ".join(dict.fromkeys(existing + additions))
        self._update_bulk_metadata(update)

    def bulk_rating(self):
        value = simpledialog.askinteger("Bulk rating", "Rating from 0 to 5:", parent=self,
                                        minvalue=0, maxvalue=5)
        if value is not None:
            self._update_bulk_metadata(lambda details: details.update(rating=value))

    def bulk_favorite(self):
        self._update_bulk_metadata(lambda details: details.update(
            favorite=not bool(details.get("favorite", False))))

    def transfer_selection(self, move):
        paths = self._chosen_paths()
        if not paths:
            return
        destination = filedialog.askdirectory(title="Move into folder" if move else "Copy into folder", parent=self)
        if not destination:
            return
        try:
            outputs = (photo_manager.move_files if move else photo_manager.copy_files)(paths, destination)
            self._remap_paths(paths, outputs, remove_old=move)
            messagebox.showinfo("Move complete" if move else "Copy complete",
                                f"{len(outputs):,} photo(s) processed.", parent=self)
            self.refresh_current()
        except OSError as exc:
            messagebox.showerror("File operation failed", str(exc), parent=self)

    def rename_selected(self):
        paths = self._chosen_paths()
        if len(paths) != 1:
            messagebox.showinfo("Rename", "Select exactly one photo to rename.", parent=self)
            return
        source = paths[0]
        name = simpledialog.askstring("Rename photo", "New filename:",
                                      initialvalue=os.path.basename(source), parent=self)
        if not name:
            return
        name = os.path.basename(name.strip())
        if not os.path.splitext(name)[1]:
            name += os.path.splitext(source)[1]
        target = os.path.join(os.path.dirname(source), name)
        if os.path.exists(target):
            messagebox.showerror("Rename failed", "A file with that name already exists.", parent=self)
            return
        try:
            os.replace(source, target)
            old_key = self._metadata_key(source)
            if old_key in self.metadata:
                self.metadata[self._metadata_key(target)] = self.metadata.pop(old_key)
                self._save_metadata()
            self.refresh_current()
        except OSError as exc:
            messagebox.showerror("Rename failed", str(exc), parent=self)

    def export_selection(self):
        paths = self._chosen_paths()
        if not paths:
            return
        destination = filedialog.askdirectory(title="Export into folder", parent=self)
        if not destination:
            return
        size = simpledialog.askinteger("Export size", "Maximum width/height in pixels (0 keeps full size):",
                                       initialvalue=2048, minvalue=0, maxvalue=30000, parent=self)
        if size is None:
            return
        quality = simpledialog.askinteger("JPEG quality", "Quality from 40 to 100:",
                                          initialvalue=90, minvalue=40, maxvalue=100, parent=self)
        if quality is None:
            return
        sharpen = messagebox.askyesno("Export sharpening", "Apply gentle output sharpening?", parent=self)
        try:
            self._save_export_settings()
            outputs = photo_manager.export_files(paths, destination, size, quality, sharpen,
                                                  self._copyright_text())
            messagebox.showinfo("Export complete", f"Exported {len(outputs):,} photo(s).", parent=self)
        except Exception as exc:
            messagebox.showerror("Export failed", str(exc), parent=self)

    def rotate_selection(self, degrees):
        paths = self._chosen_paths()
        if not paths or not messagebox.askyesno(
                "Rotate originals?", f"Rotate {len(paths):,} original photo(s) in place?", parent=self):
            return
        try:
            photo_manager.rotate_files(paths, degrees)
            self.refresh_current()
        except Exception as exc:
            messagebox.showerror("Rotation failed", str(exc), parent=self)

    def trash_selection(self):
        paths = self._chosen_paths()
        if not paths or not self.trash_root or not messagebox.askyesno(
                "Move to SNAP SLAPPER Trash?",
                f"Move {len(paths):,} photo(s) to recoverable SNAP SLAPPER Trash?", parent=self):
            return
        try:
            photo_manager.trash_files(paths, self.trash_root, self.trash_path)
            self.selected_paths.clear()
            self.selected = None
            self.refresh_current()
        except OSError as exc:
            messagebox.showerror("Trash failed", str(exc), parent=self)

    def restore_trash(self):
        if not self.trash_path:
            return
        try:
            restored = photo_manager.restore_last_trash(self.trash_path)
            messagebox.showinfo("Trash", "Restored:\n" + restored[0] if restored else "Trash is empty.", parent=self)
            self.refresh_current()
        except OSError as exc:
            messagebox.showerror("Restore failed", str(exc), parent=self)

    def find_duplicates(self):
        groups = photo_manager.duplicate_groups([row["path"] for row in self.rows])
        paths = [path for group in groups for path in group]
        self.selected_paths = {self._metadata_key(path) for path in paths}
        self.render_grid()
        messagebox.showinfo("Exact duplicates", f"Found {len(groups):,} duplicate group(s), {len(paths):,} files selected.", parent=self)

    def find_quality_issues(self):
        results = photo_manager.quality_flags([row["path"] for row in self.rows])
        self.selected_paths = {self._metadata_key(item["path"]) for item in results}
        self.render_grid()
        messagebox.showinfo("Quality suggestions", f"Flagged {len(results):,} possibly blurry or very dark photo(s).\nNothing was changed.", parent=self)

    def start_slideshow(self):
        rows = self._chosen_rows() or self.visible
        if not rows:
            return
        self.open_viewer(rows[0])
        self.slideshow_running = True
        self._slideshow_step()

    def _slideshow_step(self):
        if not getattr(self, "slideshow_running", False):
            return
        viewer = getattr(self, "viewer", None)
        if not viewer or not viewer.winfo_exists():
            self.slideshow_running = False
            return
        self.viewer_step(1)
        self.after(3000, self._slideshow_step)

    def backup_organizer(self):
        destination = filedialog.asksaveasfilename(title="Back up organizer data", parent=self,
                                                   defaultextension=".json",
                                                   initialfile="snap-slapper-organizer-backup.json",
                                                   filetypes=[("JSON", "*.json")])
        if not destination:
            return
        try:
            photo_manager.atomic_json(destination, {"version": 1, "metadata": self.metadata,
                                                     "albums": self._load_albums(),
                                                     "folders": self._saved_folders()})
            messagebox.showinfo("Backup complete", destination, parent=self)
        except OSError as exc:
            messagebox.showerror("Backup failed", str(exc), parent=self)

    def render_grid(self, incremental=True):
        self._grid_generation += 1
        generation = self._grid_generation
        for child in self.grid.winfo_children():
            child.destroy()
        self.photos = []
        self.card_widgets = {}
        rows = self.visible[:self.render_limit]
        size = self.thumb_size.get()
        columns = max(2, self.canvas.winfo_width() // (size + 22))
        for col in range(20):
            self.grid.grid_columnconfigure(col, weight=0, uniform="")
        for col in range(columns):
            self.grid.grid_columnconfigure(col, weight=1, uniform="photos")
        self.canvas.yview_moveto(0)

        def add_card(i):
            if generation != self._grid_generation:
                return
            row = rows[i]
            chosen = self._metadata_key(row["path"]) in self.selected_paths
            card_bg = "#24421f" if chosen else CARD
            card = tk.Frame(self.grid, bg=card_bg, cursor="hand2",
                            highlightthickness=2 if chosen else 0, highlightbackground=ACCENT)
            card.grid(row=i // columns, column=i % columns, sticky="nsew", padx=5, pady=5)
            try:
                thumb = self._thumbnail(row["path"], size)
                photo = ImageTk.PhotoImage(thumb)
                self.photos.append(photo)
                pic = tk.Label(card, image=photo, bg=card_bg, cursor="hand2")
            except Exception:
                pic = tk.Label(card, text="PREVIEW\nUNAVAILABLE", width=20, height=7,
                               bg=FIELD, fg=DIM, cursor="hand2")
            pic.pack(fill="x", padx=5, pady=(5, 2))
            title = tk.Label(card, text=row.get("title") or "Untitled", bg=card_bg, fg=INK,
                             anchor="w", font=("Segoe UI", 8), cursor="hand2")
            title.pack(fill="x", padx=6, pady=(0, 5))
            meta = self._photo_meta(row)
            badges = ("♥  " if meta.get("favorite") else "") + ("★" * int(meta.get("rating", 0)))
            badge = tk.Label(card, text=badges, bg=card_bg, fg=ACCENT, anchor="w",
                             font=("Segoe UI Symbol", 8), cursor="hand2")
            if badges:
                badge.pack(fill="x", padx=6, pady=(0, 5))
            self.card_widgets[self._metadata_key(row["path"])] = (card, pic, title, badge)
            for widget in (card, pic, title, badge):
                widget.bind("<Button-1>", lambda e, r=row: self.photo_clicked(e, r))
                widget.bind("<Double-Button-1>", lambda e, r=row: self.photo_double_clicked(e, r))
        def add_chunk(start=0):
            if generation != self._grid_generation:
                return
            end = min(len(rows), start + (10 if incremental else len(rows)))
            for index in range(start, end):
                add_card(index)
            if end < len(rows):
                self.after(1, lambda: add_chunk(end))
            elif len(self.visible) > len(rows):
                more = self._button(self.grid, f"LOAD MORE  ·  {len(rows):,} of {len(self.visible):,}", self.load_more)
                more.grid(row=(len(rows) + columns - 1) // columns, column=0,
                          columnspan=columns, sticky="ew", padx=30, pady=15, ipady=6)
        if not rows:
            tk.Label(self.grid, text="No photographs here yet.", bg=BG, fg=DIM,
                     font=("Segoe UI", 12)).grid(row=0, column=0, columnspan=columns, pady=80)
        else:
            add_chunk()

    def load_more(self):
        self.render_limit += 120
        self.render_grid()

    def photo_clicked(self, event, row):
        if self._single_click_job:
            self.after_cancel(self._single_click_job)
        state = event.state
        self._single_click_job = self.after(220, lambda: self._finish_photo_click(state, row))

    def _finish_photo_click(self, state, row):
        self._single_click_job = None
        key = self._metadata_key(row["path"])
        if state & 0x0004:
            if key in self.selected_paths:
                self.selected_paths.remove(key)
            else:
                self.selected_paths.add(key)
        else:
            self.selected_paths = {key}
        self.select_photo(row)
        self._refresh_card_selection()

    def _refresh_card_selection(self):
        for key, widgets in self.card_widgets.items():
            chosen = key in self.selected_paths
            colour = "#24421f" if chosen else CARD
            card = widgets[0]
            card.configure(bg=colour, highlightthickness=2 if chosen else 0)
            for widget in widgets[1:]:
                widget.configure(bg=colour)

    def photo_double_clicked(self, _event, row):
        if self._single_click_job:
            self.after_cancel(self._single_click_job)
            self._single_click_job = None
        key = self._metadata_key(row["path"])
        self.selected_paths = {key}
        self.select_photo(row)
        self.open_viewer(row)
        return "break"

    def _chosen_rows(self):
        if self.selected_paths:
            chosen = [row for row in self.rows if self._metadata_key(row["path"]) in self.selected_paths]
            if chosen:
                return chosen
        return [self.selected] if self.selected else []

    def _chosen_paths(self):
        return [row["path"] for row in self._chosen_rows() if os.path.isfile(row["path"])]

    def _remap_paths(self, sources, outputs, remove_old=False):
        mapping = dict(zip(sources, outputs))
        for source, target in mapping.items():
            old_key = self._metadata_key(source)
            if old_key in self.metadata:
                self.metadata[self._metadata_key(target)] = dict(self.metadata[old_key])
                if remove_old:
                    self.metadata.pop(old_key, None)
        albums = self._load_albums()
        changed = False
        for name, paths in albums.items():
            replacement = []
            for path in paths:
                replacement.append(mapping.get(path, path))
                changed = changed or path in mapping
            albums[name] = list(dict.fromkeys(replacement))
        self._save_metadata()
        if changed:
            self._save_albums(albums)

    def _thumbnail(self, path, size):
        cache_path = None
        if self.thumb_cache:
            identity = f"{self._metadata_key(path)}|{self._mtime(path)}|{size}".encode("utf-8")
            cache_path = os.path.join(self.thumb_cache, hashlib.sha1(identity).hexdigest() + ".jpg")
            try:
                if os.path.isfile(cache_path):
                    with Image.open(cache_path) as cached:
                        return cached.convert("RGB")
            except Exception:
                pass
        with Image.open(path) as image:
            thumb = ImageOps.fit(ImageOps.exif_transpose(image).convert("RGB"),
                                 (size, int(size * .75)), method=Image.Resampling.LANCZOS)
        if cache_path:
            try:
                os.makedirs(self.thumb_cache, exist_ok=True)
                thumb.save(cache_path, "JPEG", quality=82, optimize=True)
            except OSError:
                pass
        return thumb

    def select_photo(self, row):
        self.selected = row
        meta = self._photo_meta(row)
        self.favorite_var.set(bool(meta.get("favorite", False)))
        self.rating_var.set(max(0, min(5, int(meta.get("rating", 0)))))
        self.tags_var.set(str(meta.get("tags", "")))
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

    def _load_external_tools(self):
        custom = []
        if self.tools_path:
            try:
                with open(self.tools_path, "r", encoding="utf-8") as fh:
                    custom = (json.load(fh) or {}).get("custom", [])
            except (OSError, ValueError, TypeError):
                pass
        tools = []
        roots = [os.environ.get("ProgramFiles", r"C:\Program Files"),
                 os.environ.get("ProgramFiles(x86)", r"C:\Program Files (x86)"),
                 os.environ.get("LOCALAPPDATA", "")]
        patterns = [
            ("Adobe Photoshop", "Adobe/Adobe Photoshop */Photoshop.exe"),
            ("Affinity Photo", "Affinity/Photo */Photo.exe"),
            ("GIMP", "GIMP */bin/gimp*.exe"),
            ("darktable", "darktable/bin/darktable.exe"),
            ("RawTherapee", "RawTherapee/*/rawtherapee.exe"),
        ]
        for name, pattern in patterns:
            matches = []
            for root in roots:
                if root:
                    matches.extend(glob.glob(os.path.join(root, *pattern.split("/"))))
            if matches:
                tools.append({"name": name, "path": sorted(matches)[-1]})
        for item in custom:
            if isinstance(item, dict) and os.path.isfile(item.get("path", "")):
                tools.append({"name": item.get("name") or os.path.basename(item["path"]),
                              "path": item["path"], "custom": True})
        unique = {}
        for tool in tools:
            unique[os.path.normcase(tool["path"])] = tool
        return list(unique.values())

    def _save_external_tools(self):
        if not self.tools_path:
            return
        custom = [tool for tool in self.external_tools if tool.get("custom")]
        try:
            os.makedirs(os.path.dirname(self.tools_path), exist_ok=True)
            temp = self.tools_path + ".tmp"
            with open(temp, "w", encoding="utf-8") as fh:
                json.dump({"custom": custom}, fh, indent=2)
            os.replace(temp, self.tools_path)
        except OSError:
            pass

    def add_external_tool(self):
        path = filedialog.askopenfilename(title="Choose a photo editor", parent=self,
                                          filetypes=[("Applications", "*.exe"), ("All files", "*.*")])
        if not path:
            return
        name = os.path.splitext(os.path.basename(path))[0]
        self.external_tools.append({"name": name, "path": path, "custom": True})
        self._save_external_tools()

    def open_with_menu(self):
        if not self.selected:
            return
        menu = tk.Menu(self, tearoff=False, bg=FIELD, fg=INK,
                       activebackground=ACCENT, activeforeground=BG)
        for tool in self.external_tools:
            sub = tk.Menu(menu, tearoff=False, bg=FIELD, fg=INK,
                          activebackground=ACCENT, activeforeground=BG)
            sub.add_command(label="Edit a copy (recommended)",
                            command=lambda t=tool: self.launch_external(t, copy_first=True))
            sub.add_command(label="Open original…",
                            command=lambda t=tool: self.launch_external(t, copy_first=False))
            menu.add_cascade(label=tool["name"], menu=sub)
        if self.external_tools:
            menu.add_separator()
        menu.add_command(label="Add an editor…", command=self.add_external_tool)
        try:
            menu.tk_popup(self.winfo_pointerx(), self.winfo_pointery())
        finally:
            menu.grab_release()

    def launch_external(self, tool, copy_first=True):
        if not self.selected:
            return
        source = self.selected["path"]
        target = source
        if copy_first:
            stem, ext = os.path.splitext(source)
            target = stem + "_edit" + ext
            counter = 2
            while os.path.exists(target):
                target = f"{stem}_edit_{counter}{ext}"
                counter += 1
            try:
                shutil.copy2(source, target)
            except OSError as exc:
                messagebox.showerror("Could not create edit copy", str(exc), parent=self)
                return
        elif not messagebox.askyesno(
                "Open original?", "Changes made in the external editor may overwrite the original photo.\n\nContinue?",
                parent=self):
            return
        try:
            subprocess.Popen([tool["path"], target], cwd=os.path.dirname(tool["path"]))
        except OSError as exc:
            messagebox.showerror("Editor failed to open", str(exc), parent=self)

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

    def _viewer_accordion(self, parent, title, opened=False):
        section = tk.Frame(parent, bg="#111111")
        section.pack(fill="x")
        content = tk.Frame(section, bg="#111111")
        state = {"open": opened}
        button = self._button(section, ("▾  " if opened else "▸  ") + title, lambda: None)
        button.configure(anchor="w", bg="#171717")
        button.pack(fill="x", padx=6, pady=(0, 2), ipady=3)
        def toggle():
            state["open"] = not state["open"]
            button.configure(text=("▾  " if state["open"] else "▸  ") + title)
            if state["open"]:
                content.pack(fill="x")
            else:
                content.pack_forget()
        button.configure(command=toggle)
        if opened:
            content.pack(fill="x")
        return content

    def _viewer_adjust_scale(self, parent, label, variable, start, end, resolution):
        row = tk.Frame(parent, bg="#111111")
        row.pack(fill="x", padx=8, pady=2)
        tk.Label(row, text=label, width=9, anchor="w", bg="#111111", fg=DIM,
                 font=("Segoe UI", 8)).pack(side="left")
        scale = tk.Scale(row, from_=start, to=end, resolution=resolution, variable=variable,
                         orient="horizontal", showvalue=True, length=145, bg="#111111", fg=INK,
                         troughcolor=FIELD, activebackground=ACCENT, highlightthickness=0, bd=0,
                         command=lambda _value: self._viewer_adjust_changed())
        scale.pack(side="left", fill="x", expand=True)

    def _viewer_adjust_changed(self):
        self.viewer_before = False
        if getattr(self, "_viewer_adjust_job", None):
            self.after_cancel(self._viewer_adjust_job)
        self._viewer_adjust_job = self.after(70, self._render_viewer)

    def reset_viewer_adjustments(self, render=True):
        self.viewer_brightness_var.set(1.0)
        self.viewer_contrast_var.set(1.0)
        self.viewer_colour_var.set(1.0)
        self.viewer_sharp_var.set(0)
        self.viewer_before = False
        if render:
            self._render_viewer()

    def _apply_viewer_adjustments(self, image):
        if getattr(self, "viewer_before", False):
            return image
        image = ImageEnhance.Brightness(image).enhance(float(self.viewer_brightness_var.get()))
        image = ImageEnhance.Contrast(image).enhance(float(self.viewer_contrast_var.get()))
        image = ImageEnhance.Color(image).enhance(float(self.viewer_colour_var.get()))
        sharp = int(self.viewer_sharp_var.get())
        if sharp:
            image = image.filter(ImageFilter.UnsharpMask(radius=1.2, percent=sharp, threshold=3))
        if getattr(self, "sharpen_enabled", False):
            image = image.filter(ImageFilter.UnsharpMask(
                radius=float(self.sharpen_radius), percent=int(self.sharpen_amount),
                threshold=int(self.sharpen_threshold)))
        return image

    def export_viewer_copy(self):
        row = getattr(self, "viewer_row", None)
        if not row:
            return
        source = row["path"]
        stem, extension = os.path.splitext(source)
        target = photo_manager.unique_path(stem + "_edited" + extension)
        try:
            with Image.open(source) as image:
                output = ImageOps.exif_transpose(image).convert("RGB")
                if getattr(self, "viewer_rotation", 0):
                    output = output.rotate(self.viewer_rotation, expand=True)
                output = self._apply_viewer_adjustments(output)
                options = {"quality": 95, "optimize": True} if extension.lower() in {
                    ".jpg", ".jpeg", ".webp"} else {}
                photo_manager.save_with_metadata(output, target, source,
                                                 self._copyright_text(), **options)
            messagebox.showinfo("Edited copy exported", target, parent=self.viewer)
            self.refresh_current()
        except Exception as exc:
            messagebox.showerror("Export failed", str(exc), parent=self.viewer)

    def open_viewer(self, row):
        editor = getattr(self, "editor_window", None)
        if editor and editor.winfo_exists():
            editor.open_row(row)
            editor.deiconify()
            editor.lift()
            editor.focus_force()
        else:
            chosen = self._chosen_rows()
            editor_rows = chosen if len(chosen) > 1 else (self.visible or self.rows)
            self.editor_window = EditorWindow(
                self, row, editor_rows,
                on_select=self.select_photo, on_refresh=self.refresh_current,
                copyright_text=self._copyright_text, batch_rows=chosen)
        return
        viewer = getattr(self, "viewer", None)
        if not viewer or not viewer.winfo_exists():
            viewer = tk.Toplevel(self)
            self.viewer = viewer
            viewer.title("SNAP SLAPPER — Viewer")
            viewer.configure(bg="#050505")
            viewer.geometry("1100x760")
            viewer.minsize(640, 420)
            viewer.resizable(True, True)
            viewer.bind("<Escape>", lambda _e: viewer.destroy())
            viewer.bind("<Left>", lambda _e: self.viewer_step(-1))
            viewer.bind("<Right>", lambda _e: self.viewer_step(1))
            viewer.bind("<space>", lambda _e: self.viewer_step(1))
            viewer.bind("b", lambda _e: self.toggle_before())
            viewer.bind("B", lambda _e: self.toggle_before())
            viewer.bind("f", lambda _e: self.toggle_favorite())
            viewer.bind("F", lambda _e: self.toggle_favorite())
            for value in range(6):
                viewer.bind(str(value), lambda _e, rating=value: self.set_rating(rating))
            viewer.bind("<F11>", lambda _e: viewer.attributes("-fullscreen", not bool(viewer.attributes("-fullscreen"))))
            viewer.bind("<Configure>", self._viewer_resized)
            bar = tk.Frame(viewer, bg="#101010")
            bar.pack(fill="x")
            self.viewer_title = tk.Label(bar, text="", bg="#101010", fg=INK,
                                         font=("Segoe UI", 10, "bold"))
            self.viewer_title.pack(side="left", padx=14, pady=10)
            self._button(bar, "CLOSE", viewer.destroy).pack(side="right", padx=(5, 12), pady=6)
            self._button(bar, "OPEN IN WINDOWS", self.open_external).pack(side="right", pady=6)
            body = tk.Frame(viewer, bg="#050505")
            body.pack(fill="both", expand=True)
            self.viewer_image_area = tk.Frame(body, bg="#050505")
            self.viewer_image_area.pack(side="left", fill="both", expand=True)
            self.viewer_image = tk.Label(self.viewer_image_area, bg="#050505", fg=DIM, text="Loading…")
            self.viewer_image.pack(fill="both", expand=True, padx=12, pady=12)
            tools = tk.Frame(body, bg="#111111", width=270)
            tools.pack(side="right", fill="y")
            tools.pack_propagate(False)
            tk.Label(tools, text="EDIT PHOTO", bg="#111111", fg=ACCENT,
                     font=("Segoe UI", 9, "bold")).pack(anchor="w", padx=12, pady=(12, 8))
            self.viewer_brightness_var = tk.DoubleVar(value=1.0)
            self.viewer_contrast_var = tk.DoubleVar(value=1.0)
            self.viewer_colour_var = tk.DoubleVar(value=1.0)
            self.viewer_sharp_var = tk.IntVar(value=0)
            adjust = self._viewer_accordion(tools, "ADJUST", True)
            self._viewer_adjust_scale(adjust, "Brightness", self.viewer_brightness_var, 0.25, 2.0, .05)
            self._viewer_adjust_scale(adjust, "Contrast", self.viewer_contrast_var, 0.25, 2.0, .05)
            self._viewer_adjust_scale(adjust, "Colour", self.viewer_colour_var, 0.0, 2.0, .05)
            self._viewer_adjust_scale(adjust, "Sharpen", self.viewer_sharp_var, 0, 250, 5)
            adjust_buttons = tk.Frame(adjust, bg="#111111")
            adjust_buttons.pack(fill="x", padx=8, pady=(4, 8))
            self._button(adjust_buttons, "RESET", self.reset_viewer_adjustments).pack(side="left", fill="x", expand=True)
            self._button(adjust_buttons, "BEFORE / AFTER", self.toggle_before).pack(
                side="left", fill="x", expand=True, padx=(5, 0))
            geometry = self._viewer_accordion(tools, "ROTATE", False)
            self._button(geometry, "↺ LEFT", lambda: self.rotate_viewer(90)).pack(
                fill="x", padx=8, pady=(4, 3), ipady=3)
            self._button(geometry, "↻ RIGHT", lambda: self.rotate_viewer(-90)).pack(
                fill="x", padx=8, pady=(0, 8), ipady=3)
            organize = self._viewer_accordion(tools, "ORGANIZE", False)
            self._button(organize, "TOGGLE FAVORITE  [F]", self.toggle_favorite).pack(
                fill="x", padx=8, pady=(4, 3), ipady=3)
            rating = tk.Frame(organize, bg="#111111")
            rating.pack(fill="x", padx=8, pady=(0, 8))
            for value in range(6):
                self._button(rating, str(value), lambda number=value: self.set_rating(number)).pack(
                    side="left", fill="x", expand=True, padx=(0 if value == 0 else 2, 0))
            output = self._viewer_accordion(tools, "OUTPUT", False)
            self._button(output, "EXPORT EDITED COPY", self.export_viewer_copy).pack(
                fill="x", padx=8, pady=(4, 3), ipady=3)
            self._button(output, "OPEN WITH / EDIT COPY", self.open_with_menu).pack(
                fill="x", padx=8, pady=(0, 8), ipady=3)
            info_panel = self._viewer_accordion(tools, "INFO", False)
            self.viewer_details = tk.Label(info_panel, text="", bg="#111111", fg=INK,
                                           justify="left", anchor="nw", wraplength=235,
                                           font=("Segoe UI", 8))
            self.viewer_details.pack(fill="x", padx=8, pady=(4, 8))
            nav = tk.Frame(viewer, bg="#101010")
            nav.pack(fill="x")
            self._button(nav, "← PREVIOUS", lambda: self.viewer_step(-1)).pack(
                side="left", padx=12, pady=7, ipadx=10)
            self._button(nav, "↺", lambda: self.rotate_viewer(90)).pack(side="left", pady=7, ipadx=8)
            self._button(nav, "↻", lambda: self.rotate_viewer(-90)).pack(side="left", padx=5, pady=7, ipadx=8)
            self._button(nav, "SHARPEN", self.open_sharpen).pack(side="left", padx=(8, 3), pady=7, ipadx=8)
            self._button(nav, "BEFORE / AFTER  [B]", self.toggle_before).pack(side="left", pady=7, ipadx=6)
            self.viewer_count = tk.Label(nav, text="", bg="#101010", fg=DIM,
                                         font=("Segoe UI", 9))
            self.viewer_count.pack(side="left", expand=True)
            self._button(nav, "NEXT →", lambda: self.viewer_step(1)).pack(
                side="right", padx=12, pady=7, ipadx=10)
        old = getattr(self, "viewer_row", None)
        self.viewer_row = row
        if not old or old.get("path") != row.get("path"):
            self.viewer_rotation = 0
            self.viewer_before = False
            self.sharpen_enabled = False
            self.sharpen_amount, self.sharpen_radius, self.sharpen_threshold = 100, 1.2, 3
            if hasattr(self, "viewer_brightness_var"):
                self.reset_viewer_adjustments(render=False)
        self.selected = row
        self.select_photo(row)
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
        width = max(320, viewer.winfo_width() - 310)
        height = max(240, viewer.winfo_height() - 145)
        try:
            with Image.open(path) as image:
                source = ImageOps.exif_transpose(image).convert("RGB")
                if getattr(self, "viewer_rotation", 0):
                    source = source.rotate(self.viewer_rotation, expand=True)
                rendered = ImageOps.contain(source, (width, height),
                                             method=Image.Resampling.LANCZOS)
                rendered = self._apply_viewer_adjustments(rendered)
            photo = ImageTk.PhotoImage(rendered)
            self.viewer_image.configure(image=photo, text="")
            self.viewer_image.image = photo
        except Exception as exc:
            self.viewer_image.configure(image="", text=f"Could not open image\n\n{exc}")
        title = row.get("title") or os.path.basename(path)
        meta = self._photo_meta(row)
        badges = ("♥ " if meta.get("favorite") else "") + ("★" * int(meta.get("rating", 0)))
        self.viewer_title.configure(text=f"{badges}  {title}" if badges else title)
        if hasattr(self, "viewer_details"):
            try:
                with Image.open(path) as source_info:
                    dimensions = f"{source_info.width:,} × {source_info.height:,}"
            except Exception:
                dimensions = "Dimensions unavailable"
            self.viewer_details.configure(
                text=f"{dimensions}\n{os.path.getsize(path) / 1048576:.1f} MB\n\n{path}")
        try:
            index = next(i for i, item in enumerate(self.visible) if item["path"] == path)
            self.viewer_count.configure(text=f"{index + 1:,} of {len(self.visible):,}   ·   0–5 rate   ·   F favorite   ·   ←/→ browse")
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
        self.viewer_before = False
        self.sharpen_enabled = False
        self.reset_viewer_adjustments(render=False)
        self.selected = self.viewer_row
        self.select_photo(self.viewer_row)
        self._render_viewer()

    def rotate_viewer(self, degrees):
        self.viewer_rotation = (getattr(self, "viewer_rotation", 0) + degrees) % 360
        self._render_viewer()

    def toggle_before(self):
        self.viewer_before = not getattr(self, "viewer_before", False)
        self._render_viewer()

    def open_sharpen(self):
        panel = getattr(self, "sharpen_panel", None)
        if panel and panel.winfo_exists():
            panel.lift()
            return
        panel = tk.Toplevel(self.viewer)
        self.sharpen_panel = panel
        panel.title("SNAP SLAPPER — Sharpen")
        panel.configure(bg=CARD)
        panel.geometry("360x360")
        panel.resizable(False, False)
        panel.transient(self.viewer)
        tk.Label(panel, text="NON-DESTRUCTIVE SHARPENING", bg=CARD, fg=ACCENT,
                 font=("Segoe UI", 11, "bold")).pack(anchor="w", padx=16, pady=(16, 5))
        tk.Label(panel, text="Preview only until you export a new copy.", bg=CARD, fg=DIM,
                 font=("Segoe UI", 9)).pack(anchor="w", padx=16, pady=(0, 10))
        self.sharpen_enabled_var = tk.BooleanVar(value=True)
        self.sharpen_enabled = True
        tk.Checkbutton(panel, text="Enable sharpening", variable=self.sharpen_enabled_var,
                       command=self._sharpen_changed, bg=CARD, fg=INK, selectcolor=FIELD,
                       activebackground=CARD, activeforeground=INK).pack(anchor="w", padx=12)
        self.sharpen_amount_var = tk.IntVar(value=int(self.sharpen_amount))
        self.sharpen_radius_var = tk.DoubleVar(value=float(self.sharpen_radius))
        self.sharpen_threshold_var = tk.IntVar(value=int(self.sharpen_threshold))
        self._sharpen_scale(panel, "AMOUNT", self.sharpen_amount_var, 0, 300, 5)
        self._sharpen_scale(panel, "RADIUS", self.sharpen_radius_var, 0.1, 5.0, 0.1)
        self._sharpen_scale(panel, "THRESHOLD", self.sharpen_threshold_var, 0, 20, 1)
        actions = tk.Frame(panel, bg=CARD)
        actions.pack(fill="x", padx=14, pady=12)
        self._button(actions, "RESET", self.reset_sharpen).pack(side="left", ipadx=8, ipady=4)
        export = self._button(actions, "EXPORT NEW COPY", self.export_sharpened)
        export.pack(side="right", ipadx=8, ipady=4)
        self._render_viewer()

    def _sharpen_scale(self, parent, label, variable, start, end, resolution):
        row = tk.Frame(parent, bg=CARD)
        row.pack(fill="x", padx=14, pady=3)
        tk.Label(row, text=label, width=10, anchor="w", bg=CARD, fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(side="left")
        scale = tk.Scale(row, from_=start, to=end, resolution=resolution, variable=variable,
                         orient="horizontal", showvalue=True, bg=CARD, fg=INK, troughcolor=FIELD,
                         activebackground=ACCENT, highlightthickness=0, bd=0,
                         command=lambda _v: self._sharpen_changed())
        scale.pack(side="left", fill="x", expand=True)

    def _sharpen_changed(self):
        self.sharpen_enabled = bool(self.sharpen_enabled_var.get())
        self.sharpen_amount = int(self.sharpen_amount_var.get())
        self.sharpen_radius = float(self.sharpen_radius_var.get())
        self.sharpen_threshold = int(self.sharpen_threshold_var.get())
        self.viewer_before = False
        if getattr(self, "_sharpen_job", None):
            self.after_cancel(self._sharpen_job)
        self._sharpen_job = self.after(80, self._render_viewer)

    def reset_sharpen(self):
        self.sharpen_enabled_var.set(True)
        self.sharpen_amount_var.set(100)
        self.sharpen_radius_var.set(1.2)
        self.sharpen_threshold_var.set(3)
        self._sharpen_changed()

    def export_sharpened(self):
        row = getattr(self, "viewer_row", None)
        if not row:
            return
        source_path = row["path"]
        stem, ext = os.path.splitext(source_path)
        target = stem + "_sharpened" + ext
        counter = 2
        while os.path.exists(target):
            target = f"{stem}_sharpened_{counter}{ext}"
            counter += 1
        try:
            with Image.open(source_path) as image:
                output = ImageOps.exif_transpose(image).convert("RGB")
                if getattr(self, "viewer_rotation", 0):
                    output = output.rotate(self.viewer_rotation, expand=True)
                if self.sharpen_enabled:
                    output = output.filter(ImageFilter.UnsharpMask(
                        radius=float(self.sharpen_radius), percent=int(self.sharpen_amount),
                        threshold=int(self.sharpen_threshold)))
                kwargs = {"quality": 95, "optimize": True} if ext.lower() in {".jpg", ".jpeg", ".webp"} else {}
                photo_manager.save_with_metadata(output, target, source_path,
                                                 self._copyright_text(), **kwargs)
            messagebox.showinfo("Sharpened copy exported", target, parent=self.sharpen_panel)
        except Exception as exc:
            messagebox.showerror("Export failed", str(exc), parent=self.sharpen_panel)
