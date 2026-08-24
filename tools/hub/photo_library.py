"""SNAP SLAPPER photo library: Picasa-style source rail, grid, and info rail."""

import datetime
import json
import os
import tkinter as tk
from tkinter import filedialog, messagebox

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
        self.source_list = tk.Listbox(left, bg="#111111", fg=INK, selectbackground=ACCENT,
            selectforeground=BG, relief="flat", highlightthickness=0, font=("Segoe UI", 10),
            activestyle="none", exportselection=False)
        self.source_list.pack(fill="both", expand=True, padx=8)
        self.source_list.bind("<<ListboxSelect>>", self._source_selected)
        self._button(left, "+ OPEN LOCAL FOLDER", self.choose_folder).pack(
            fill="x", padx=12, pady=12, ipady=5)

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
        self._button(right, "OPEN IN DEFAULT VIEWER", self.open_selected).pack(
            fill="x", padx=14, pady=(0, 14), ipady=5)

    def _wheel(self, event):
        self.canvas.yview_scroll(int(-event.delta / 120), "units")

    def refresh_sources(self):
        self.source_list.delete(0, "end")
        self.sources.clear()
        try:
            names = sorted(os.listdir(self.library_root), key=str.lower)
        except OSError:
            names = []
        for name in names:
            folder = os.path.join(self.library_root, name)
            if os.path.isfile(os.path.join(folder, "index.json")):
                label = f"▣  {name}"
                self.source_list.insert("end", label)
                self.sources[label] = ("shared", folder)
        for folder in self._saved_folders():
            label = f"▦  {os.path.basename(folder) or folder}"
            self.source_list.insert("end", label)
            self.sources[label] = ("folder", folder)
        if not self.sources:
            self.source_list.insert("end", "No synced blog libraries yet")

    def choose_folder(self):
        folder = filedialog.askdirectory(title="Choose a photo folder", parent=self)
        if not folder:
            return
        label = f"▦  {os.path.basename(folder) or folder}"
        if label not in self.sources:
            self.source_list.insert("end", label)
        self.sources[label] = ("folder", folder)
        self._save_folders()
        idx = list(self.source_list.get(0, "end")).index(label)
        self.source_list.selection_clear(0, "end")
        self.source_list.selection_set(idx)
        self.load_source("folder", folder, label)

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
        folders = sorted({path for kind, path in self.sources.values() if kind == "folder"}, key=str.lower)
        try:
            os.makedirs(os.path.dirname(self.state_path), exist_ok=True)
            temp = self.state_path + ".tmp"
            with open(temp, "w", encoding="utf-8") as fh:
                json.dump(folders, fh, indent=2)
            os.replace(temp, self.state_path)
        except OSError:
            pass

    def _source_selected(self, _event=None):
        sel = self.source_list.curselection()
        if sel:
            label = self.source_list.get(sel[0])
            if label in self.sources:
                self.load_source(*self.sources[label], label)

    def load_source(self, kind, folder, label):
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
            for base, dirs, files in os.walk(folder):
                dirs[:] = [d for d in dirs if not d.startswith(".")]
                for name in files:
                    if os.path.splitext(name)[1].lower() in IMAGE_EXTS:
                        rows.append({"path": os.path.join(base, name),
                                     "title": os.path.splitext(name)[0], "description": "", "tags": []})
            rows.sort(key=lambda row: row["path"].lower())
        self.rows = rows
        self.heading.configure(text=f"{label.lstrip('▣▦ ')}  ·  {len(rows):,} photos")
        self.filter_rows()

    def filter_rows(self):
        query = self.search_var.get().strip().lower()
        self.visible = [row for row in self.rows if not query or query in " ".join((
            row.get("title", ""), row.get("description", ""), os.path.basename(row["path"]),
            " ".join(map(str, row.get("tags", []))))).lower()]
        self.render_grid()

    def render_grid(self):
        for child in self.grid.winfo_children():
            child.destroy()
        self.photos = []
        rows = self.visible[:1000]
        for col in range(5):
            self.grid.grid_columnconfigure(col, weight=1, uniform="photos")
        for i, row in enumerate(rows):
            card = tk.Frame(self.grid, bg=CARD, cursor="hand2")
            card.grid(row=i // 5, column=i % 5, sticky="nsew", padx=5, pady=5)
            try:
                with Image.open(row["path"]) as image:
                    thumb = ImageOps.fit(image.convert("RGB"), (150, 112), method=Image.Resampling.LANCZOS)
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
                widget.bind("<Double-Button-1>", lambda _e, r=row: self.open_path(r["path"]))
        if not rows:
            tk.Label(self.grid, text="No photographs here yet.", bg=BG, fg=DIM,
                     font=("Segoe UI", 12)).grid(row=0, column=0, columnspan=5, pady=80)
        elif len(self.visible) > len(rows):
            tk.Label(self.grid, text=f"Showing first {len(rows):,} of {len(self.visible):,}",
                     bg=BG, fg=DIM).grid(row=(len(rows) + 4) // 5, column=0, columnspan=5, pady=15)
        self.canvas.yview_moveto(0)

    def select_photo(self, row):
        self.selected = row
        path = row["path"]
        try:
            with Image.open(path) as image:
                size = image.size
                preview = ImageOps.contain(image.convert("RGB"), (255, 255), method=Image.Resampling.LANCZOS)
            photo = ImageTk.PhotoImage(preview)
            self.preview.configure(image=photo, text="", width=0, height=0)
            self.preview.image = photo
            text = (f"{row.get('title') or 'Untitled'}\n\n{size[0]} × {size[1]} pixels\n"
                    f"{os.path.getsize(path) / 1048576:.1f} MB\n"
                    f"Modified {datetime.datetime.fromtimestamp(os.path.getmtime(path)):%Y-%m-%d %H:%M}\n\n{path}")
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
            self.open_path(self.selected["path"])
