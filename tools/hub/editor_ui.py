"""Integrated non-destructive editing workspace for SNAP SLAPPER.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import copy
import json
import os
import tkinter as tk
from tkinter import colorchooser, filedialog, messagebox, simpledialog, ttk

from PIL import Image, ImageDraw, ImageOps, ImageTk

import editor_engine
import photo_manager


BG, PANEL, FIELD, BORDER = "#080808", "#111111", "#1c1c1c", "#292929"
INK, DIM, ACCENT = "#e6e6e6", "#8a8a8a", "#39ff14"
BLEND_MODES = ("normal", "multiply", "screen", "overlay", "soft_light", "hard_light",
               "darken", "lighten", "color", "luminosity", "difference")


class EditorWindow(tk.Toplevel):
    recipe_clipboard = None

    def __init__(self, parent, row, rows, on_select=None, on_refresh=None,
                 copyright_text=None):
        super().__init__(parent)
        self.row = row
        self.rows = rows
        self.on_select = on_select
        self.on_refresh = on_refresh
        self.copyright_text = copyright_text or (lambda: "")
        self.document = editor_engine.EditorDocument(row["path"])
        self.documents = {os.path.abspath(row["path"]): self.document}
        self.zoom = 0.0
        self.pan_x = self.pan_y = 0
        self.display_box = (0, 0, 1, 1)
        self.compare_mode = "edited"
        self.tool = "pan"
        self.drag_start = None
        self.crop_rect = None
        self.mask_brush_size = tk.IntVar(value=40)
        self.mask_brush_value = tk.IntVar(value=0)
        self.spot_radius = tk.IntVar(value=25)
        self._render_job = None
        self._adjust_start = None
        self._mask_dirty = False
        self._layer_setting_before = None
        self.title("SNAP SLAPPER — Editor")
        self.configure(bg=BG)
        self.geometry("1500x900")
        self.minsize(1000, 650)
        self.resizable(True, True)
        self.protocol("WM_DELETE_WINDOW", self.destroy)
        self._build()
        self.set_tool("pan")
        self._load_document_controls()
        self.after(100, self.fit_image)

    @staticmethod
    def _button(parent, text, command, accent=False):
        button = tk.Button(parent, text=text, command=command,
                           bg=ACCENT if accent else FIELD, fg=BG if accent else INK,
                           activebackground=INK if accent else ACCENT,
                           activeforeground=BG, relief="flat", bd=0,
                           font=("Segoe UI", 8, "bold"), cursor="hand2")
        return button

    def _build(self):
        top = tk.Frame(self, bg="#101010")
        top.pack(fill="x")
        self.title_label = tk.Label(top, text="", bg="#101010", fg=INK,
                                    font=("Segoe UI", 10, "bold"))
        self.title_label.pack(side="left", padx=12, pady=9)
        for text, command in (("OPEN PROJECT", self.open_project), ("SAVE PROJECT", self.save_project), ("EXPORT", self.export),
                              ("UNDO", self.undo), ("REDO", self.redo),
                              ("COMPARE", self.cycle_compare), ("FIT", self.fit_image),
                              ("100%", self.actual_size)):
            self._button(top, text, command, accent=text == "EXPORT").pack(side="right", padx=(0, 5), pady=5, ipadx=5, ipady=3)
        self._button(top, "←", lambda: self.open_relative(-1)).pack(side="left", padx=(10, 2), pady=5, ipadx=6, ipady=3)
        self._button(top, "→", lambda: self.open_relative(1)).pack(side="left", pady=5, ipadx=6, ipady=3)

        body = tk.PanedWindow(self, orient="horizontal", bg=BORDER, sashwidth=4,
                              relief="flat", bd=0)
        body.pack(fill="both", expand=True)
        centre = tk.Frame(body, bg=BG)
        side = tk.Frame(body, bg=PANEL, width=340)
        body.add(centre, minsize=600, stretch="always")
        body.add(side, minsize=300)

        tool_bar = tk.Frame(centre, bg="#101010")
        tool_bar.pack(fill="x")
        for text, tool in (("PAN", "pan"), ("CROP", "crop"), ("SPOT", "spot"),
                           ("RED EYE", "red_eye"), ("MASK BRUSH", "mask")):
            self._button(tool_bar, text, lambda name=tool: self.set_tool(name)).pack(
                side="left", padx=(6, 0), pady=5, ipadx=5, ipady=3)
        self._button(tool_bar, "STRAIGHTEN…", self.straighten).pack(side="left", padx=6, pady=5, ipadx=5, ipady=3)
        self._button(tool_bar, "APPLY CROP", self.apply_crop).pack(side="left", pady=5, ipadx=5, ipady=3)
        tk.Label(tool_bar, text="Spot", bg="#101010", fg=DIM).pack(side="left", padx=(14, 3))
        tk.Scale(tool_bar, from_=5, to=100, variable=self.spot_radius, orient="horizontal",
                 length=90, showvalue=False, bg="#101010", troughcolor=FIELD,
                 activebackground=ACCENT, highlightthickness=0, bd=0).pack(side="left")

        self.canvas = tk.Canvas(centre, bg="#050505", highlightthickness=0, cursor="fleur")
        self.canvas.pack(fill="both", expand=True)
        self.canvas.bind("<Configure>", lambda _event: self.schedule_render())
        self.canvas.bind("<MouseWheel>", self.mouse_zoom)
        self.canvas.bind("<ButtonPress-1>", self.canvas_press)
        self.canvas.bind("<B1-Motion>", self.canvas_drag)
        self.canvas.bind("<ButtonRelease-1>", self.canvas_release)
        self.canvas.bind("<Motion>", self.canvas_motion)
        self.canvas.bind("<Leave>", lambda _event: self.canvas.delete("tool-preview"))
        self.canvas.bind("<Double-Button-1>", self.canvas_double_click)
        self.canvas.bind("<ButtonPress-3>", lambda _event: self.set_tool("pan"))

        film = tk.Frame(centre, bg="#101010", height=98)
        film.pack(fill="x")
        film.pack_propagate(False)
        self.film_canvas = tk.Canvas(film, bg="#101010", highlightthickness=0, height=94)
        self.film_canvas.pack(fill="both", expand=True)
        self.film_inner = tk.Frame(self.film_canvas, bg="#101010")
        self.film_canvas.create_window((0, 0), window=self.film_inner, anchor="nw")
        self.film_photos = []
        self._render_filmstrip()

        tk.Label(side, text="EDIT PHOTO", bg=PANEL, fg=ACCENT,
                 font=("Segoe UI", 10, "bold")).pack(anchor="w", padx=10, pady=(10, 6))
        side_canvas = tk.Canvas(side, bg=PANEL, highlightthickness=0)
        style = ttk.Style(self)
        style.configure("Slapper.Vertical.TScrollbar", background="#303030",
                        troughcolor=BG, bordercolor=BG, arrowcolor=DIM,
                        darkcolor="#303030", lightcolor="#303030",
                        relief="flat", borderwidth=0)
        style.map("Slapper.Vertical.TScrollbar",
                  background=[("active", "#454545"), ("pressed", ACCENT)],
                  arrowcolor=[("active", INK)])
        side_scroll = ttk.Scrollbar(side, orient="vertical", command=side_canvas.yview,
                                    style="Slapper.Vertical.TScrollbar")
        side_canvas.configure(yscrollcommand=side_scroll.set)
        side_scroll.pack(side="right", fill="y")
        side_canvas.pack(side="left", fill="both", expand=True)
        self.side_inner = tk.Frame(side_canvas, bg=PANEL)
        side_id = side_canvas.create_window((0, 0), window=self.side_inner, anchor="nw")
        self.side_inner.bind("<Configure>", lambda _event: side_canvas.configure(scrollregion=side_canvas.bbox("all")))
        side_canvas.bind("<Configure>", lambda event: side_canvas.itemconfigure(side_id, width=event.width))
        self._build_adjustments()
        self._build_curve_histogram()
        self._build_layers()
        self._build_presets()
        self._build_history()

        status = tk.Frame(self, bg="#101010")
        status.pack(fill="x")
        self.status_label = tk.Label(status, text="", bg="#101010", fg=DIM,
                                     font=("Segoe UI", 8))
        self.status_label.pack(side="left", padx=10, pady=5)
        self.zoom_label = tk.Label(status, text="", bg="#101010", fg=DIM,
                                   font=("Segoe UI", 8))
        self.zoom_label.pack(side="right", padx=10)
        self.bind("<Control-z>", lambda _event: self.undo())
        self.bind("<Control-y>", lambda _event: self.redo())
        self.bind("<Control-s>", lambda _event: self.save_project())
        self.bind("<Escape>", lambda _event: self.set_tool("pan"))
        self.bind("<Return>", lambda _event: self.apply_crop() if self.tool == "crop" else None)
        self.bind("<Left>", lambda _event: self.open_relative(-1))
        self.bind("<Right>", lambda _event: self.open_relative(1))

    def accordion(self, title, opened=False):
        section = tk.Frame(self.side_inner, bg=PANEL)
        section.pack(fill="x", padx=5, pady=(0, 2))
        content = tk.Frame(section, bg=PANEL)
        state = {"open": opened}
        button = self._button(section, ("▾  " if opened else "▸  ") + title, lambda: None)
        button.configure(anchor="w", bg="#181818")
        button.pack(fill="x", ipady=4)
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

    def _build_adjustments(self):
        self.adjustment_vars = {}
        groups = [
            ("LIGHT", ("exposure", "brightness", "contrast", "highlights", "shadows", "whites", "blacks")),
            ("COLOUR", ("temperature", "tint", "saturation", "vibrance")),
            ("PRESENCE", ("clarity", "texture", "dehaze", "sharpen")),
            ("EFFECTS", ("vignette", "grain")),
        ]
        for group, names in groups:
            panel = self.accordion(group, group == "LIGHT")
            for name in names:
                start, end, resolution = (-3, 3, .05) if name == "exposure" else (-100, 100, 1)
                variable = tk.DoubleVar(value=0)
                self.adjustment_vars[name] = variable
                row = tk.Frame(panel, bg=PANEL)
                row.pack(fill="x", padx=7, pady=1)
                tk.Label(row, text=name.replace("_", " ").title(), width=11, anchor="w",
                         bg=PANEL, fg=DIM, font=("Segoe UI", 8)).pack(side="left")
                scale = tk.Scale(row, from_=start, to=end, resolution=resolution, variable=variable,
                                 orient="horizontal", length=170, bg=PANEL, fg=INK,
                                 troughcolor=FIELD, activebackground=ACCENT, highlightthickness=0,
                                 bd=0, command=lambda _value, key=name: self.adjust_changed(key))
                scale.pack(side="left", fill="x", expand=True)
                scale.bind("<ButtonPress-1>", lambda _event, key=name: self.adjust_begin(key))
                scale.bind("<ButtonRelease-1>", lambda _event, key=name: self.adjust_end(key))
        levels = self.accordion("LEVELS", False)
        for name, start, end, resolution, default in (
                ("level_black", 0, 254, 1, 0), ("level_gamma", .1, 3, .05, 1),
                ("level_white", 1, 255, 1, 255)):
            variable = tk.DoubleVar(value=default)
            self.adjustment_vars[name] = variable
            row = tk.Frame(levels, bg=PANEL); row.pack(fill="x", padx=7, pady=1)
            tk.Label(row, text=name.replace("level_", "").title(), width=11, anchor="w",
                     bg=PANEL, fg=DIM, font=("Segoe UI", 8)).pack(side="left")
            scale = tk.Scale(row, from_=start, to=end, resolution=resolution, variable=variable,
                             orient="horizontal", length=170, bg=PANEL, fg=INK,
                             troughcolor=FIELD, activebackground=ACCENT, highlightthickness=0,
                             bd=0, command=lambda _value, key=name: self.adjust_changed(key))
            scale.pack(side="left", fill="x", expand=True)
            scale.bind("<ButtonPress-1>", lambda _event, key=name: self.adjust_begin(key))
            scale.bind("<ButtonRelease-1>", lambda _event, key=name: self.adjust_end(key))
        bw_panel = self.accordion("BLACK + WHITE", False)
        self.black_white_var = tk.BooleanVar(value=False)
        tk.Checkbutton(bw_panel, text="Convert to black and white", variable=self.black_white_var,
                       command=self.toggle_black_white, bg=PANEL, fg=INK, selectcolor=FIELD,
                       activebackground=PANEL, activeforeground=INK).pack(anchor="w", padx=8, pady=7)
        self._button(bw_panel, "RESET ALL ADJUSTMENTS", self.reset_adjustments).pack(
            fill="x", padx=8, pady=(0, 8), ipady=3)

    def _build_curve_histogram(self):
        panel = self.accordion("CURVE + HISTOGRAM", False)
        self.hist_canvas = tk.Canvas(panel, height=110, bg="#070707", highlightthickness=1,
                                     highlightbackground=BORDER)
        self.hist_canvas.pack(fill="x", padx=8, pady=(6, 3))
        self.curve_canvas = tk.Canvas(panel, height=150, bg="#0b0b0b", highlightthickness=1,
                                      highlightbackground=BORDER, cursor="crosshair")
        self.curve_canvas.pack(fill="x", padx=8, pady=3)
        self.curve_canvas.bind("<Button-1>", self.curve_click)
        self._button(panel, "RESET CURVE", self.reset_curve).pack(fill="x", padx=8, pady=(3, 8), ipady=3)

    def _build_layers(self):
        panel = self.accordion("LAYERS", True)
        self.layer_list = tk.Listbox(panel, height=7, bg="#0b0b0b", fg=INK,
                                     selectbackground=ACCENT, selectforeground=BG,
                                     highlightthickness=0, relief="flat")
        self.layer_list.pack(fill="x", padx=8, pady=(6, 3))
        self.layer_list.bind("<<ListboxSelect>>", lambda _event: self.layer_selected())
        buttons = tk.Frame(panel, bg=PANEL)
        buttons.pack(fill="x", padx=8)
        for text, command in (("+ ADJUST", self.add_adjustment_layer), ("+ IMAGE", self.add_image_layer),
                              ("−", self.remove_layer), ("↑", lambda: self.move_layer(1)),
                              ("↓", lambda: self.move_layer(-1))):
            self._button(buttons, text, command).pack(side="left", fill="x", expand=True, padx=(0, 2))
        settings = tk.Frame(panel, bg=PANEL)
        settings.pack(fill="x", padx=8, pady=5)
        self.layer_visible_var = tk.BooleanVar(value=True)
        tk.Checkbutton(settings, text="Visible", variable=self.layer_visible_var,
                       command=self.layer_setting_changed, bg=PANEL, fg=INK, selectcolor=FIELD,
                       activebackground=PANEL).pack(side="left")
        self.layer_blend_var = tk.StringVar(value="normal")
        blend = tk.OptionMenu(settings, self.layer_blend_var, *BLEND_MODES,
                              command=lambda _value: self.layer_setting_changed())
        blend.configure(bg=FIELD, fg=INK, activebackground=ACCENT, relief="flat", highlightthickness=0)
        blend["menu"].configure(bg=FIELD, fg=INK, activebackground=ACCENT)
        blend.pack(side="right", fill="x", expand=True)
        self.layer_opacity_var = tk.DoubleVar(value=1.0)
        opacity_scale = tk.Scale(panel, from_=0, to=1, resolution=.05, variable=self.layer_opacity_var,
                 orient="horizontal", label="Opacity", bg=PANEL, fg=DIM, troughcolor=FIELD,
                 activebackground=ACCENT, highlightthickness=0, bd=0,
                 command=lambda _value: self.layer_setting_changed())
        opacity_scale.pack(fill="x", padx=8)
        opacity_scale.bind("<ButtonPress-1>", self.layer_setting_begin)
        opacity_scale.bind("<ButtonRelease-1>", self.layer_setting_end)
        masks = tk.Frame(panel, bg=PANEL)
        masks.pack(fill="x", padx=8, pady=4)
        for text, command in (("WHITE MASK", lambda: self.create_mask(255)),
                              ("BLACK MASK", lambda: self.create_mask(0)),
                              ("INVERT", self.invert_mask), ("GRADIENT", self.gradient_mask)):
            self._button(masks, text, command).pack(side="left", fill="x", expand=True, padx=(0, 2))
        brush = tk.Frame(panel, bg=PANEL)
        brush.pack(fill="x", padx=8, pady=(0, 4))
        tk.Label(brush, text="Mask brush", bg=PANEL, fg=DIM).pack(side="left")
        tk.Radiobutton(brush, text="Hide", value=0, variable=self.mask_brush_value,
                       bg=PANEL, fg=INK, selectcolor=FIELD, activebackground=PANEL).pack(side="left", padx=4)
        tk.Radiobutton(brush, text="Reveal", value=255, variable=self.mask_brush_value,
                       bg=PANEL, fg=INK, selectcolor=FIELD, activebackground=PANEL).pack(side="left")
        tk.Scale(brush, from_=5, to=120, variable=self.mask_brush_size, orient="horizontal",
                 showvalue=False, length=90, bg=PANEL, troughcolor=FIELD,
                 activebackground=ACCENT, highlightthickness=0, bd=0).pack(side="right")
        self._button(panel, "LAYER STYLES…", self.layer_styles).pack(fill="x", padx=8, pady=(0, 8), ipady=3)

    def _build_presets(self):
        panel = self.accordion("PRESETS + BATCH", False)
        for text, command in (("COPY ADJUSTMENTS", self.copy_adjustments),
                              ("PASTE ADJUSTMENTS", self.paste_adjustments),
                              ("SAVE PRESET…", self.save_preset), ("LOAD PRESET…", self.load_preset),
                              ("BATCH APPLY TO SELECTION…", self.batch_apply)):
            self._button(panel, text, command).pack(fill="x", padx=8, pady=(4, 0), ipady=3)
        tk.Label(panel, text="Batch export always creates new JPEG copies.", bg=PANEL, fg=DIM,
                 wraplength=270, justify="left", font=("Segoe UI", 8)).pack(anchor="w", padx=8, pady=8)

    def _build_history(self):
        panel = self.accordion("HISTORY", False)
        self.history_list = tk.Listbox(panel, height=6, bg="#0b0b0b", fg=INK,
                                       selectbackground=ACCENT, selectforeground=BG,
                                       highlightthickness=0, relief="flat")
        self.history_list.pack(fill="x", padx=8, pady=7)

    def _load_document_controls(self):
        self.title_label.configure(text=os.path.basename(self.document.source_path))
        target = self.adjustment_target()
        for name, variable in self.adjustment_vars.items():
            variable.set(target.get(name, 0))
        self.black_white_var.set(bool(target.get("black_white")))
        self.refresh_layers()
        self.refresh_history()
        self.schedule_render()

    def schedule_render(self):
        if self._render_job:
            self.after_cancel(self._render_job)
        self._render_job = self.after(70, self.render)

    def render(self):
        self._render_job = None
        width, height = max(200, self.canvas.winfo_width()), max(160, self.canvas.winfo_height())
        if self.zoom <= 0:
            max_size = (max(100, width - 40), max(100, height - 40))
        else:
            with Image.open(self.document.source_path) as source:
                max_size = (max(1, int(source.width * self.zoom)), max(1, int(source.height * self.zoom)))
        try:
            edited = self.document.render(max_size)
            if self.compare_mode == "original":
                with Image.open(self.document.source_path) as source:
                    shown = ImageOps.exif_transpose(source).convert("RGB")
                    shown.thumbnail(max_size, Image.Resampling.LANCZOS)
            elif self.compare_mode == "split":
                with Image.open(self.document.source_path) as source:
                    original = ImageOps.exif_transpose(source).convert("RGB")
                    original.thumbnail(edited.size, Image.Resampling.LANCZOS)
                shown = edited.copy()
                split = shown.width // 2
                shown.paste(original.crop((0, 0, split, min(original.height, shown.height))), (0, 0))
                ImageDraw.Draw(shown).line((split, 0, split, shown.height), fill=(57, 255, 20), width=2)
            elif self.compare_mode == "side_by_side":
                with Image.open(self.document.source_path) as source:
                    original = ImageOps.exif_transpose(source).convert("RGB")
                half = max(100, max_size[0] // 2 - 5)
                original.thumbnail((half, max_size[1]), Image.Resampling.LANCZOS)
                edited.thumbnail((half, max_size[1]), Image.Resampling.LANCZOS)
                shown = Image.new("RGB", (original.width + edited.width + 10,
                                           max(original.height, edited.height)), (8, 8, 8))
                shown.paste(original, (0, (shown.height - original.height) // 2))
                shown.paste(edited, (original.width + 10, (shown.height - edited.height) // 2))
            else:
                shown = edited
            photo = ImageTk.PhotoImage(shown)
            self.canvas.delete("image")
            x = width // 2 + self.pan_x
            y = height // 2 + self.pan_y
            self.canvas.create_image(x, y, image=photo, tags="image")
            self.canvas.tag_lower("image")
            self.canvas.image = photo
            self.display_box = (x - shown.width // 2, y - shown.height // 2,
                                x + shown.width // 2, y + shown.height // 2)
            self.zoom_label.configure(text="FIT" if self.zoom <= 0 else f"{self.zoom * 100:.0f}%")
            self.draw_histogram()
            self.draw_curve()
        except Exception as exc:
            self.canvas.delete("all")
            self.canvas.create_text(width // 2, height // 2, text=str(exc), fill="#ff5555")

    def fit_image(self):
        self.zoom = 0
        self.pan_x = self.pan_y = 0
        self.schedule_render()

    def actual_size(self):
        self.zoom = 1.0
        self.pan_x = self.pan_y = 0
        self.schedule_render()

    def mouse_zoom(self, event):
        current = self.zoom if self.zoom > 0 else .5
        self.zoom = max(.05, min(8.0, current * (1.15 if event.delta > 0 else 1 / 1.15)))
        self.schedule_render()

    def set_tool(self, name):
        self.tool = name
        if name != "crop":
            self.crop_rect = None
            self.canvas.delete("crop")
        self.canvas.configure(cursor="crosshair" if name in {"crop", "spot", "red_eye", "mask"} else "fleur")
        instructions = {
            "pan": "PAN — drag the photograph; mouse wheel zooms",
            "crop": "CROP — drag a rectangle, then press Enter or APPLY CROP",
            "spot": "SPOT — adjust Spot size, then click a blemish; Ctrl+Z undoes",
            "red_eye": "RED EYE — size the circle over one pupil and click",
            "mask": "MASK BRUSH — select a layer and mask, then paint Hide or Reveal",
        }
        self.status_label.configure(text=instructions.get(name, name.upper()))

    def canvas_motion(self, event):
        self.canvas.delete("tool-preview")
        if self.tool not in {"spot", "red_eye", "mask"}:
            return
        point = self.canvas_to_normalized(event.x, event.y)
        if not point:
            return
        radius = self.mask_brush_size.get() if self.tool == "mask" else self.spot_radius.get()
        colour = "#ff5555" if self.tool == "red_eye" else ACCENT
        self.canvas.create_oval(event.x - radius, event.y - radius,
                                event.x + radius, event.y + radius,
                                outline=colour, width=2, tags="tool-preview")

    def canvas_double_click(self, _event):
        if self.tool == "crop" and self.crop_rect:
            self.apply_crop()

    def canvas_press(self, event):
        self.drag_start = (event.x, event.y)
        if self.tool in {"spot", "red_eye"}:
            point = self.canvas_to_normalized(event.x, event.y)
            if point:
                self.document.retouched.append({"type": self.tool, "x": point[0], "y": point[1],
                                                 "radius": self.spot_radius.get() / max(1, min(
                                                     self.display_box[2] - self.display_box[0],
                                                     self.display_box[3] - self.display_box[1]))})
                self.document.record("Red-eye correction" if self.tool == "red_eye" else "Spot removal")
                self.refresh_history()
                self.status_label.configure(text=("Red-eye correction applied — Ctrl+Z to undo" if self.tool == "red_eye"
                                                  else "Spot softened — Ctrl+Z to undo"))
                self.schedule_render()
        elif self.tool == "mask":
            self.paint_mask(event.x, event.y)

    def canvas_drag(self, event):
        if not self.drag_start:
            return
        if self.tool == "pan":
            self.pan_x += event.x - self.drag_start[0]
            self.pan_y += event.y - self.drag_start[1]
            self.drag_start = (event.x, event.y)
            self.schedule_render()
        elif self.tool == "crop":
            self.canvas.delete("crop")
            left, top, right, bottom = self.display_box
            x0 = max(left, min(right, self.drag_start[0]))
            y0 = max(top, min(bottom, self.drag_start[1]))
            x1 = max(left, min(right, event.x))
            y1 = max(top, min(bottom, event.y))
            self.canvas.create_rectangle(x0, y0, x1, y1, outline=ACCENT,
                                         width=3, dash=(8, 4), tags="crop")
            self.canvas.tag_raise("crop")
            self.crop_rect = (x0, y0, x1, y1)
            self.status_label.configure(text="CROP READY — press Enter, double-click, or APPLY CROP")
        elif self.tool == "mask":
            self.paint_mask(event.x, event.y)

    def canvas_release(self, _event):
        if self.tool == "mask" and self._mask_dirty:
            self.document.record("Paint layer mask")
            self.refresh_history()
            self._mask_dirty = False
        self.drag_start = None

    def canvas_to_normalized(self, x, y):
        left, top, right, bottom = self.display_box
        if not (left <= x <= right and top <= y <= bottom):
            return None
        return ((x - left) / max(1, right - left), (y - top) / max(1, bottom - top))

    def apply_crop(self):
        if not self.crop_rect:
            self.status_label.configure(text="CROP — drag a rectangle over the part you want to keep")
            return
        x0, y0, x1, y1 = self.crop_rect
        first, second = self.canvas_to_normalized(x0, y0), self.canvas_to_normalized(x1, y1)
        if not first or not second:
            messagebox.showinfo("Crop", "Keep the crop rectangle inside the photograph.", parent=self)
            return
        left, right = sorted((first[0], second[0]))
        top, bottom = sorted((first[1], second[1]))
        if right - left < .01 or bottom - top < .01:
            return
        self.document.geometry["crop"] = [left, top, right, bottom]
        self.document.record("Crop")
        self.crop_rect = None
        self.canvas.delete("crop")
        self.fit_image()
        self.refresh_history()
        self.status_label.configure(text="Crop applied — Ctrl+Z to undo")

    def straighten(self):
        value = simpledialog.askfloat("Straighten", "Angle in degrees (-45 to 45):",
                                      initialvalue=self.document.geometry.get("rotation", 0),
                                      minvalue=-45, maxvalue=45, parent=self)
        if value is not None:
            self.document.geometry["rotation"] = value
            self.document.record("Straighten")
            self.refresh_history()
            self.fit_image()

    def adjust_begin(self, key):
        target = self.adjustment_target()
        self._adjust_start = (key, target.get(key, 0))

    def adjust_changed(self, key):
        self.adjustment_target()[key] = self.adjustment_vars[key].get()
        self.schedule_render()

    def adjust_end(self, key):
        if self._adjust_start and self._adjust_start[0] == key and self._adjust_start[1] != self.adjustment_vars[key].get():
            self.document.record(key.replace("_", " ").title())
            self.refresh_history()
        self._adjust_start = None

    def toggle_black_white(self):
        self.adjustment_target()["black_white"] = self.black_white_var.get()
        self.document.record("Black and white")
        self.refresh_history()
        self.schedule_render()

    def reset_adjustments(self):
        target = self.adjustment_target()
        target.clear()
        target.update(copy.deepcopy(editor_engine.DEFAULT_ADJUSTMENTS))
        self.document.record("Reset adjustments")
        self._load_document_controls()

    def curve_click(self, event):
        width, height = max(1, self.curve_canvas.winfo_width()), max(1, self.curve_canvas.winfo_height())
        point = [int(255 * event.x / width), int(255 * (1 - event.y / height))]
        target = self.adjustment_target()
        points = [item for item in target.get("curve", []) if abs(item[0] - point[0]) > 10]
        points.append(point)
        target["curve"] = sorted(points)
        self.document.record("Tone curve")
        self.refresh_history()
        self.schedule_render()

    def reset_curve(self):
        self.adjustment_target()["curve"] = [[0, 0], [255, 255]]
        self.document.record("Reset curve")
        self.refresh_history()
        self.schedule_render()

    def draw_curve(self):
        canvas = self.curve_canvas
        canvas.delete("all")
        width, height = max(1, canvas.winfo_width()), max(1, canvas.winfo_height())
        for fraction in (.25, .5, .75):
            canvas.create_line(width * fraction, 0, width * fraction, height, fill="#222222")
            canvas.create_line(0, height * fraction, width, height * fraction, fill="#222222")
        points = self.adjustment_target().get("curve", [[0, 0], [255, 255]])
        coords = [(x / 255 * width, height - y / 255 * height) for x, y in points]
        if len(coords) > 1:
            canvas.create_line(*sum(([x, y] for x, y in coords), []), fill=ACCENT, width=2)
        for x, y in coords:
            canvas.create_oval(x - 3, y - 3, x + 3, y + 3, fill=ACCENT, outline="")

    def draw_histogram(self):
        canvas = self.hist_canvas
        canvas.delete("all")
        try:
            values = self.document.histogram((300, 200))
        except Exception:
            return
        width, height = max(1, canvas.winfo_width()), max(1, canvas.winfo_height())
        maximum = max(max(values[channel]) for channel in ("red", "green", "blue")) or 1
        for channel, color in (("red", "#ff5555"), ("green", "#55ff55"), ("blue", "#5599ff")):
            points = []
            for index, count in enumerate(values[channel]):
                points.extend((index / 255 * width, height - count / maximum * height))
            canvas.create_line(*points, fill=color, width=1)

    def current_layer(self):
        selection = self.layer_list.curselection()
        if not selection:
            return None
        index = len(self.document.layers) - 1 - selection[0]
        return self.document.layers[index] if 0 <= index < len(self.document.layers) else None

    def adjustment_target(self):
        layer = self.current_layer() if hasattr(self, "layer_list") else None
        if layer and layer.get("type") == "adjustment":
            return layer.setdefault("adjustments", copy.deepcopy(editor_engine.DEFAULT_ADJUSTMENTS))
        return self.document.adjustments

    def refresh_layers(self):
        self.layer_list.delete(0, "end")
        for layer in reversed(self.document.layers):
            self.layer_list.insert("end", ("● " if layer.get("visible", True) else "○ ") + layer.get("name", "Layer"))
        self.layer_list.insert("end", "▣ BASE ADJUSTMENTS")

    def layer_selected(self):
        layer = self.current_layer()
        if layer:
            self.layer_visible_var.set(bool(layer.get("visible", True)))
            self.layer_blend_var.set(layer.get("blend", "normal"))
            self.layer_opacity_var.set(float(layer.get("opacity", 1.0)))
        target = self.adjustment_target()
        for name, variable in self.adjustment_vars.items():
            variable.set(target.get(name, 0))
        self.black_white_var.set(bool(target.get("black_white")))
        self.draw_curve()

    def add_adjustment_layer(self):
        name = simpledialog.askstring("Adjustment layer", "Layer name:", initialvalue="Adjustment", parent=self)
        self.document.add_adjustment_layer(name or "Adjustment")
        self.refresh_layers(); self.refresh_history(); self.schedule_render()

    def add_image_layer(self):
        path = filedialog.askopenfilename(title="Add image layer", parent=self,
                                          filetypes=[("Images", "*.jpg *.jpeg *.png *.webp *.tif *.tiff *.bmp"), ("All files", "*.*")])
        if path:
            self.document.add_image_layer(path)
            self.refresh_layers(); self.refresh_history(); self.schedule_render()

    def remove_layer(self):
        layer = self.current_layer()
        if layer:
            self.document.layers.remove(layer)
            self.document.record("Remove layer")
            self.refresh_layers(); self.refresh_history(); self.schedule_render()

    def move_layer(self, amount):
        layer = self.current_layer()
        if not layer:
            return
        index = self.document.layers.index(layer)
        target = max(0, min(len(self.document.layers) - 1, index + amount))
        self.document.layers.insert(target, self.document.layers.pop(index))
        self.document.record("Move layer")
        self.refresh_layers(); self.refresh_history(); self.schedule_render()

    def layer_setting_changed(self):
        layer = self.current_layer()
        if layer:
            layer["visible"] = self.layer_visible_var.get()
            layer["blend"] = self.layer_blend_var.get()
            layer["opacity"] = self.layer_opacity_var.get()
            self.schedule_render()

    def layer_setting_begin(self, _event=None):
        layer = self.current_layer()
        self._layer_setting_before = copy.deepcopy(layer) if layer else None

    def layer_setting_end(self, _event=None):
        layer = self.current_layer()
        if layer and self._layer_setting_before != layer:
            self.document.record("Layer settings")
            self.refresh_history()
        self._layer_setting_before = None

    def create_mask(self, value):
        layer = self.current_layer()
        if not layer:
            return
        with Image.open(self.document.source_path) as source:
            mask = Image.new("L", source.size, value)
        stream = __import__("io").BytesIO(); mask.save(stream, "PNG")
        layer["mask"] = __import__("base64").b64encode(stream.getvalue()).decode("ascii")
        self.document.record("Create layer mask")
        self.refresh_history(); self.schedule_render()

    def invert_mask(self):
        layer = self.current_layer()
        if not layer or not layer.get("mask"):
            return
        import base64, io
        mask = Image.open(io.BytesIO(base64.b64decode(layer["mask"]))).convert("L")
        stream = io.BytesIO(); ImageOps.invert(mask).save(stream, "PNG")
        layer["mask"] = base64.b64encode(stream.getvalue()).decode("ascii")
        self.document.record("Invert mask")
        self.refresh_history(); self.schedule_render()

    def gradient_mask(self):
        layer = self.current_layer()
        if not layer:
            return
        import base64, io
        with Image.open(self.document.source_path) as source:
            mask = Image.linear_gradient("L").resize(source.size)
        stream = io.BytesIO(); mask.save(stream, "PNG")
        layer["mask"] = base64.b64encode(stream.getvalue()).decode("ascii")
        self.document.record("Gradient mask")
        self.refresh_history(); self.schedule_render()

    def paint_mask(self, canvas_x, canvas_y):
        layer = self.current_layer()
        point = self.canvas_to_normalized(canvas_x, canvas_y)
        if not layer or not point:
            return
        import base64, io
        if layer.get("mask"):
            mask = Image.open(io.BytesIO(base64.b64decode(layer["mask"]))).convert("L")
        else:
            with Image.open(self.document.source_path) as source:
                mask = Image.new("L", source.size, 255)
        radius = max(2, int(self.mask_brush_size.get() / max(1, self.display_box[2] - self.display_box[0]) * mask.width))
        x, y = int(point[0] * mask.width), int(point[1] * mask.height)
        ImageDraw.Draw(mask).ellipse((x - radius, y - radius, x + radius, y + radius),
                                     fill=self.mask_brush_value.get())
        stream = io.BytesIO(); mask.save(stream, "PNG")
        layer["mask"] = base64.b64encode(stream.getvalue()).decode("ascii")
        self._mask_dirty = True
        self.schedule_render()

    def layer_styles(self):
        layer = self.current_layer()
        if not layer:
            messagebox.showinfo("Layer styles", "Select a layer first.", parent=self); return
        window = tk.Toplevel(self); window.title("Layer Styles"); window.configure(bg=PANEL); window.transient(self)
        styles = layer.setdefault("styles", {})
        shadow = tk.BooleanVar(value=bool(styles.get("shadow")))
        inner_shadow = tk.BooleanVar(value=bool(styles.get("inner_shadow")))
        overlay = tk.BooleanVar(value=bool(styles.get("color_overlay")))
        stroke = tk.IntVar(value=int(styles.get("stroke", 0)))
        glow = tk.IntVar(value=int(styles.get("glow", 0)))
        tk.Checkbutton(window, text="Drop shadow", variable=shadow, bg=PANEL, fg=INK, selectcolor=FIELD).pack(anchor="w", padx=12, pady=8)
        tk.Checkbutton(window, text="Inner shadow", variable=inner_shadow, bg=PANEL, fg=INK, selectcolor=FIELD).pack(anchor="w", padx=12)
        tk.Checkbutton(window, text="Colour overlay", variable=overlay, bg=PANEL, fg=INK, selectcolor=FIELD).pack(anchor="w", padx=12, pady=(4, 8))
        for label, variable in (("Stroke", stroke), ("Glow", glow)):
            tk.Scale(window, label=label, from_=0, to=30, variable=variable, orient="horizontal",
                     bg=PANEL, fg=INK, troughcolor=FIELD, highlightthickness=0).pack(fill="x", padx=12)
        def apply():
            styles.update(shadow=shadow.get(), inner_shadow=inner_shadow.get(),
                          color_overlay=overlay.get(), stroke=stroke.get(), glow=glow.get())
            self.document.record("Layer styles"); self.refresh_history(); self.schedule_render(); window.destroy()
        self._button(window, "APPLY", apply, True).pack(fill="x", padx=12, pady=12, ipady=4)

    def copy_adjustments(self):
        EditorWindow.recipe_clipboard = self.document.recipe()
        self.status_label.configure(text="Adjustments copied")

    def paste_adjustments(self):
        if EditorWindow.recipe_clipboard:
            self.document.apply_recipe(EditorWindow.recipe_clipboard)
            self._load_document_controls()

    def save_preset(self):
        path = filedialog.asksaveasfilename(title="Save preset", parent=self, defaultextension=".slaprecipe",
                                            filetypes=[("SNAP SLAPPER recipe", "*.slaprecipe")])
        if path:
            editor_engine.save_recipe(path, self.document.recipe())

    def load_preset(self):
        path = filedialog.askopenfilename(title="Load preset", parent=self,
                                          filetypes=[("SNAP SLAPPER recipe", "*.slaprecipe"), ("All files", "*.*")])
        if path:
            self.document.apply_recipe(editor_engine.load_recipe(path)); self._load_document_controls()

    def batch_apply(self):
        paths = [row["path"] for row in self.rows if os.path.isfile(row.get("path", ""))]
        if not paths:
            return
        destination = filedialog.askdirectory(title="Batch export folder", parent=self)
        if destination and messagebox.askyesno("Batch apply", f"Create edited JPEG copies for {len(paths):,} visible photo(s)?", parent=self):
            try:
                outputs = editor_engine.batch_apply(paths, self.document.recipe(), destination,
                                                    copyright_text=self.copyright_text())
                messagebox.showinfo("Batch complete", f"Created {len(outputs):,} edited copies.", parent=self)
            except Exception as exc:
                messagebox.showerror("Batch failed", str(exc), parent=self)

    def cycle_compare(self):
        modes = ("edited", "split", "side_by_side", "original")
        self.compare_mode = modes[(modes.index(self.compare_mode) + 1) % len(modes)]
        self.status_label.configure(text=f"Comparison: {self.compare_mode.replace('_', ' ').title()}")
        self.schedule_render()

    def undo(self):
        if self.document.undo(): self._load_document_controls()

    def redo(self):
        if self.document.redo(): self._load_document_controls()

    def refresh_history(self):
        self.history_list.delete(0, "end")
        for index, item in enumerate(self.document.history):
            prefix = "▶ " if index == self.document.history_index else "  "
            self.history_list.insert("end", prefix + item["label"])
        if self.document.history:
            self.history_list.see(self.document.history_index)

    def save_project(self):
        path = self.document.project_path or filedialog.asksaveasfilename(
            title="Save SNAP SLAPPER project", parent=self, defaultextension=".slapper",
            filetypes=[("SNAP SLAPPER project", "*.slapper")])
        if path:
            self.document.save_project(path); self.status_label.configure(text=f"Saved {path}")

    def open_project(self):
        path = filedialog.askopenfilename(title="Open SNAP SLAPPER project", parent=self,
                                          filetypes=[("SNAP SLAPPER project", "*.slapper"),
                                                     ("All files", "*.*")])
        if not path:
            return
        try:
            self.document = editor_engine.EditorDocument.load_project(path)
            self.documents[os.path.abspath(self.document.source_path)] = self.document
            self.row = {"path": self.document.source_path,
                        "title": os.path.splitext(os.path.basename(self.document.source_path))[0],
                        "description": "", "tags": []}
            self.fit_image(); self._load_document_controls(); self.refresh_history()
        except Exception as exc:
            messagebox.showerror("Project could not be opened", str(exc), parent=self)

    def export(self):
        source = self.document.source_path
        path = filedialog.asksaveasfilename(title="Export edited photograph", parent=self,
                                            initialfile=os.path.splitext(os.path.basename(source))[0] + "_edited.jpg",
                                            defaultextension=".jpg",
                                            filetypes=[("JPEG", "*.jpg"), ("PNG", "*.png"), ("TIFF", "*.tif")])
        if path:
            try:
                self.document.export(path, copyright_text=self.copyright_text())
                messagebox.showinfo("Export complete", path, parent=self)
                if self.on_refresh: self.on_refresh()
            except Exception as exc:
                messagebox.showerror("Export failed", str(exc), parent=self)

    def _render_filmstrip(self):
        for child in self.film_inner.winfo_children(): child.destroy()
        self.film_photos = []
        try:
            current = next(index for index, item in enumerate(self.rows) if item["path"] == self.row["path"])
        except StopIteration:
            current = 0
        start, end = max(0, current - 8), min(len(self.rows), current + 9)
        for item in self.rows[start:end]:
            try:
                with Image.open(item["path"]) as source:
                    thumb = ImageOps.fit(ImageOps.exif_transpose(source).convert("RGB"), (100, 72), Image.Resampling.LANCZOS)
                photo = ImageTk.PhotoImage(thumb); self.film_photos.append(photo)
                label = tk.Label(self.film_inner, image=photo, bg=ACCENT if item["path"] == self.row["path"] else PANEL,
                                 bd=2, relief="flat", cursor="hand2")
                label.pack(side="left", padx=3, pady=8)
                label.bind("<Button-1>", lambda _event, selected=item: self.open_row(selected))
            except Exception:
                pass

    def open_row(self, row):
        self.row = row
        key = os.path.abspath(row["path"])
        if key not in self.documents:
            self.documents[key] = editor_engine.EditorDocument(key)
        self.document = self.documents[key]
        self.fit_image(); self._render_filmstrip(); self._load_document_controls()
        if self.on_select: self.on_select(row)

    def open_relative(self, amount):
        if not self.rows:
            return
        try:
            index = next(index for index, item in enumerate(self.rows)
                         if item["path"] == self.row["path"])
        except StopIteration:
            index = 0
        self.open_row(self.rows[(index + amount) % len(self.rows)])

# ===== SNAPSMACK EOF =====
