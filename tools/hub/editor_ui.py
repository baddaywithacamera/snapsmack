"""Integrated non-destructive editing workspace for SNAP SLAPPER.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import copy
import json
import math
import os
import tkinter as tk
import tkinter.font as tkfont
from tkinter import colorchooser, filedialog, messagebox, simpledialog, ttk

from PIL import Image, ImageChops, ImageDraw, ImageFilter, ImageOps, ImageTk

import editor_engine
import photo_manager
import help_ui
import built_in_lewks


BG, PANEL, FIELD, BORDER = "#080808", "#111111", "#1c1c1c", "#292929"
INK, DIM, ACCENT = "#e6e6e6", "#8a8a8a", "#39ff14"
BLEND_MODES = ("normal", "multiply", "screen", "overlay", "soft_light", "hard_light",
               "darken", "lighten", "color", "luminosity", "difference")


class EditorWindow(tk.Toplevel):
    recipe_clipboard = None

    def __init__(self, parent, row, rows, on_select=None, on_refresh=None,
                 copyright_text=None, strip_gps=None, recovery_dir=None, build_version=None,
                 batch_rows=None, saved_images_dir=None, projects_dir=None):
        super().__init__(parent)
        self.row = row
        self.rows = rows
        self.on_select = on_select
        self.on_refresh = on_refresh
        self.copyright_text = copyright_text or (lambda: "")
        self.strip_gps = strip_gps or (lambda: False)
        self.recovery_dir = recovery_dir
        self.build_version = build_version
        self.saved_images_dir = saved_images_dir or (lambda: "")
        self.projects_dir = projects_dir or (lambda: "")
        self._recovery_jobs = {}
        self.bind("<F1>", lambda _event: help_ui.open_help(
            self, "Editor", self.build_version))
        self.batch_rows = list(batch_rows or [row])
        self.document = self._recover_or_create_document(row["path"])
        self.documents = {os.path.abspath(row["path"]): self.document}
        self.zoom = 0.0
        self.fit_zoom = 1.0
        self.pan_x = self.pan_y = 0
        self.display_box = (0, 0, 1, 1)
        self.compare_mode = "edited"
        self.tool = "pan"
        self.drag_start = None
        self.crop_rect = None
        self.mask_brush_size = tk.IntVar(value=40)
        self.mask_brush_value = tk.IntVar(value=0)
        self.mask_overlay_var = tk.BooleanVar(value=False)
        self.mask_grayscale_var = tk.BooleanVar(value=False)
        self.color_range_tolerance = tk.IntVar(value=40)
        self.mask_feather = tk.IntVar(value=20)
        self.mask_reverse = tk.BooleanVar(value=False)
        self.mask_combine_mode = tk.StringVar(value="replace")
        self.outline_points = []
        self.selected_layer_id = None
        self.selected_layer_target = "content"
        self._layer_drag_id = None
        self._layer_drag_start_y = None
        self.layer_thumbnail_images = []
        self.spot_radius = tk.IntVar(value=25)
        self._render_job = None
        self._adjust_start = None
        self._mask_dirty = False
        self._layer_setting_before = None
        self._layer_transform_before = None
        self._layer_move_origin = None
        self._layer_drag_mode = None
        self.transform_vars = {
            "x": tk.DoubleVar(value=50), "y": tk.DoubleVar(value=50),
            "scale_x": tk.DoubleVar(value=100), "scale_y": tk.DoubleVar(value=100),
            "rotation": tk.DoubleVar(value=0),
        }
        self.transform_proportional = tk.BooleanVar(value=True)
        self.title("SNAP SLAPPER — Editor")
        self.configure(bg=BG)
        self.geometry("1500x900")
        self.minsize(1000, 650)
        self.resizable(True, True)
        self.protocol("WM_DELETE_WINDOW", self.close_editor)
        self._build()
        self.set_tool("pan")
        self._load_document_controls()
        self.after(100, self.fit_image)

    def _recovery_path(self, source_path):
        if not self.recovery_dir:
            return None
        return photo_manager.recovery_path(self.recovery_dir, source_path)

    def _attach_recovery(self, document):
        document.on_change = self._schedule_recovery
        return document

    def _recover_or_create_document(self, source_path):
        recovery = self._recovery_path(source_path)
        if recovery and os.path.isfile(recovery):
            try:
                recovered = editor_engine.EditorDocument.load_project(recovery)
                if not photo_manager.same_file(recovered.source_path, source_path):
                    raise ValueError("Recovery file belongs to a different photograph")
            except Exception as exc:
                quarantined = photo_manager.unique_path(recovery + ".broken")
                try:
                    os.replace(recovery, quarantined)
                    location = f"It was preserved for inspection at:\n{quarantined}"
                except OSError:
                    location = f"It remains at:\n{recovery}"
                messagebox.showerror(
                    "Recovery file could not be opened",
                    f"{exc}\n\n{location}", parent=self)
            else:
                if messagebox.askyesno(
                        "Recover unsaved edits?",
                        "SNAP SLAPPER found editing work that was not saved before the last "
                        "session ended. Recover it?",
                        parent=self, icon="warning"):
                    return self._attach_recovery(recovered)
                try:
                    os.remove(recovery)
                except OSError as exc:
                    messagebox.showerror("Recovery file could not be discarded", str(exc),
                                         parent=self)
        return self._attach_recovery(editor_engine.EditorDocument(source_path))

    def _schedule_recovery(self, document):
        recovery = self._recovery_path(document.source_path)
        if not recovery:
            return
        old_job = self._recovery_jobs.pop(recovery, None)
        if old_job:
            self.after_cancel(old_job)
        self._recovery_jobs[recovery] = self.after(
            300, lambda d=document, p=recovery: self._write_recovery(d, p))

    def _write_recovery(self, document, recovery):
        self._recovery_jobs.pop(recovery, None)
        try:
            if document.is_dirty():
                document.save_recovery(recovery)
            elif os.path.isfile(recovery):
                os.remove(recovery)
        except Exception as exc:
            self.status_label.configure(text=f"Recovery save failed: {exc}")

    def _discard_recovery(self, document):
        recovery = self._recovery_path(document.source_path)
        if not recovery:
            return
        job = self._recovery_jobs.pop(recovery, None)
        if job:
            self.after_cancel(job)
        try:
            if os.path.isfile(recovery):
                os.remove(recovery)
        except OSError:
            pass

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
                              ("100%", self.actual_size),
                              ("HELP", lambda: help_ui.open_help(
                                  self, "Editor", self.build_version))):
            self._button(top, text, command, accent=text == "EXPORT").pack(side="right", padx=(0, 5), pady=5, ipadx=5, ipady=3)
        self._button(top, "←", lambda: self.open_relative(-1)).pack(side="left", padx=(10, 2), pady=5, ipadx=6, ipady=3)
        self._button(top, "→", lambda: self.open_relative(1)).pack(side="left", pady=5, ipadx=6, ipady=3)
        self._button(top, "−", self.zoom_out).pack(side="left", padx=(12, 2), pady=5, ipadx=7, ipady=3)
        self.zoom_top_label = tk.Label(top, text="100%", width=9, bg="#101010", fg=INK,
                                       font=("Segoe UI", 9, "bold"))
        self.zoom_top_label.pack(side="left", pady=5)
        self._button(top, "+", self.zoom_in).pack(side="left", padx=(2, 0), pady=5, ipadx=7, ipady=3)

        body = tk.PanedWindow(self, orient="horizontal", bg=BORDER, sashwidth=4,
                              relief="flat", bd=0)
        body.pack(fill="both", expand=True)
        centre = tk.Frame(body, bg=BG)
        side = tk.Frame(body, bg=PANEL, width=340)
        body.add(centre, minsize=600, stretch="always")
        body.add(side, minsize=300)

        tool_bar = tk.Frame(centre, bg="#101010")
        tool_bar.pack(fill="x")
        for text, tool in (("PAN", "pan"), ("MOVE LAYER", "move_layer"),
                           ("CROP", "crop"), ("SPOT", "spot"),
                           ("RED EYE", "red_eye"), ("MASK BRUSH", "mask")):
            self._button(tool_bar, text, lambda name=tool: self.set_tool(name)).pack(
                side="left", padx=(6, 0), pady=5, ipadx=5, ipady=3)
        self._button(tool_bar, "LOCAL ADJUST", self.start_local_adjustment, True).pack(
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
        self.canvas.bind("<Control-MouseWheel>", self.mouse_zoom)
        self.canvas.bind("<Button-4>", lambda _event: self.zoom_by(1.15))
        self.canvas.bind("<Button-5>", lambda _event: self.zoom_by(1 / 1.15))
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
        side_canvas.bind("<MouseWheel>", lambda event: side_canvas.yview_scroll(
            -1 if event.delta > 0 else 1, "units"))
        side_canvas.bind("<Button-4>", lambda _event: side_canvas.yview_scroll(-1, "units"))
        side_canvas.bind("<Button-5>", lambda _event: side_canvas.yview_scroll(1, "units"))
        self._build_layers()
        self._build_adjustments()
        self._build_curve_histogram()
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
        for sequence in ("<plus>", "<KP_Add>", "<Control-plus>", "<Control-KP_Add>"):
            self.bind(sequence, lambda _event: self.zoom_in())
        for sequence in ("<minus>", "<KP_Subtract>", "<Control-minus>", "<Control-KP_Subtract>"):
            self.bind(sequence, lambda _event: self.zoom_out())

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
        target = tk.Frame(self.side_inner, bg="#181818", highlightthickness=1,
                          highlightbackground=ACCENT)
        target.pack(fill="x", padx=5, pady=(0, 5))
        tk.Label(target, text="ADJUSTING", bg="#181818", fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(anchor="w", padx=8, pady=(5, 0))
        self.adjustment_target_label = tk.Label(
            target, text="BASE PHOTO", bg="#181818", fg=ACCENT,
            font=("Segoe UI", 10, "bold"), anchor="w")
        self.adjustment_target_label.pack(fill="x", padx=8)
        self.adjustment_target_help = tk.Label(
            target, text="The controls below affect the original photograph.",
            bg="#181818", fg=DIM, font=("Segoe UI", 8), anchor="w", justify="left")
        self.adjustment_target_help.pack(fill="x", padx=8, pady=(0, 5))
        groups = [
            ("LIGHT", ("exposure", "brightness", "contrast", "highlights", "shadows", "whites", "blacks")),
            ("COLOUR", ("temperature", "tint", "saturation", "vibrance")),
            ("PRESENCE", ("clarity", "texture", "dehaze", "sharpen")),
            ("EFFECTS", ("vignette", "grain")),
        ]
        for group, names in groups:
            panel = self.accordion(group, group == "LIGHT")
            if group == "LIGHT":
                histogram_header = tk.Frame(panel, bg=PANEL)
                histogram_header.pack(fill="x", padx=7, pady=(6, 2))
                tk.Label(histogram_header, text="LIVE HISTOGRAM", bg=PANEL, fg=DIM,
                         font=("Segoe UI", 8, "bold")).pack(side="left")
                self.histogram_mode = tk.StringVar(value="luma")
                for text, value in (("LUMA", "luma"), ("RGB", "rgb")):
                    tk.Radiobutton(histogram_header, text=text, value=value,
                                   variable=self.histogram_mode,
                                   command=self.schedule_render, indicatoron=False,
                                   bg=FIELD, fg=INK, selectcolor=ACCENT,
                                   activebackground=ACCENT, activeforeground=BG,
                                   font=("Segoe UI", 8, "bold"), relief="flat",
                                   width=6).pack(side="right", padx=(3, 0))
                self.hist_canvas = tk.Canvas(panel, height=105, bg="#070707",
                                             highlightthickness=1,
                                             highlightbackground=BORDER)
                self.hist_canvas.pack(fill="x", padx=7, pady=(0, 6))
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
        panel = self.accordion("TONE CURVE", False)
        self.curve_canvas = tk.Canvas(panel, height=150, bg="#0b0b0b", highlightthickness=1,
                                      highlightbackground=BORDER, cursor="crosshair")
        self.curve_canvas.pack(fill="x", padx=8, pady=3)
        self.curve_canvas.bind("<Button-1>", self.curve_click)
        self._button(panel, "RESET CURVE", self.reset_curve).pack(fill="x", padx=8, pady=(3, 8), ipady=3)

    def _build_layers(self):
        panel = self.accordion("LAYERS", True)
        layer_area = tk.Frame(panel, bg="#0b0b0b", height=250)
        layer_area.pack(fill="x", padx=8, pady=(6, 3))
        layer_area.pack_propagate(False)
        self.layer_canvas = tk.Canvas(layer_area, bg="#0b0b0b", highlightthickness=0)
        layer_scroll = ttk.Scrollbar(layer_area, orient="vertical", command=self.layer_canvas.yview,
                                     style="Slapper.Vertical.TScrollbar")
        self.layer_canvas.configure(yscrollcommand=layer_scroll.set)
        layer_scroll.pack(side="right", fill="y")
        self.layer_canvas.pack(side="left", fill="both", expand=True)
        self.layer_rows = tk.Frame(self.layer_canvas, bg="#0b0b0b")
        self.layer_rows_window = self.layer_canvas.create_window((0, 0), window=self.layer_rows, anchor="nw")
        self.layer_rows.bind("<Configure>", lambda _event: self.layer_canvas.configure(
            scrollregion=self.layer_canvas.bbox("all")))
        self.layer_canvas.bind("<Configure>", lambda event: self.layer_canvas.itemconfigure(
            self.layer_rows_window, width=event.width))
        self.layer_canvas.bind("<MouseWheel>", lambda event: self.layer_canvas.yview_scroll(
            -1 if event.delta > 0 else 1, "units"))
        self.layer_canvas.bind("<Button-4>", lambda _event: self.layer_canvas.yview_scroll(-1, "units"))
        self.layer_canvas.bind("<Button-5>", lambda _event: self.layer_canvas.yview_scroll(1, "units"))
        buttons = tk.Frame(panel, bg=PANEL)
        buttons.pack(fill="x", padx=8)
        for text, command in (("+ ADJUSTMENT", self.add_adjustment_layer), ("+ IMAGE", self.add_image_layer),
                              ("+ TEXT", self.add_text_layer),
                              ("−", self.remove_layer), ("↑", lambda: self.move_layer(1)),
                              ("↓", lambda: self.move_layer(-1))):
            self._button(buttons, text, command).pack(side="left", fill="x", expand=True, padx=(0, 2))
        settings = tk.Frame(panel, bg=PANEL)
        settings.pack(fill="x", padx=8, pady=5)
        self.layer_visible_var = tk.BooleanVar(value=True)
        tk.Checkbutton(settings, text="Visible", variable=self.layer_visible_var,
                       command=lambda: self.commit_layer_setting("Layer visibility"),
                       bg=PANEL, fg=INK, selectcolor=FIELD,
                       activebackground=PANEL).pack(side="left")
        self.layer_blend_var = tk.StringVar(value="normal")
        blend = tk.OptionMenu(settings, self.layer_blend_var, *BLEND_MODES,
                              command=lambda _value: self.commit_layer_setting("Layer blend mode"))
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
        transform = tk.LabelFrame(panel, text="IMAGE LAYER TRANSFORM", bg=PANEL, fg=ACCENT,
                                  bd=1, relief="solid", font=("Segoe UI", 7, "bold"))
        transform.pack(fill="x", padx=8, pady=(2, 5))
        self.transform_controls = []
        grid = tk.Frame(transform, bg=PANEL)
        grid.pack(fill="x", padx=5, pady=4)
        for column, (label, key) in enumerate((("X %", "x"), ("Y %", "y"),
                                               ("W %", "scale_x"), ("H %", "scale_y"),
                                               ("ANGLE", "rotation"))):
            cell = tk.Frame(grid, bg=PANEL)
            cell.grid(row=0, column=column, sticky="ew", padx=2)
            grid.grid_columnconfigure(column, weight=1)
            tk.Label(cell, text=label, bg=PANEL, fg=DIM,
                     font=("Segoe UI", 7)).pack(anchor="w")
            entry = tk.Entry(cell, textvariable=self.transform_vars[key], bg=FIELD, fg=INK,
                             insertbackground=INK, relief="flat", width=6)
            entry.pack(fill="x", ipady=3)
            entry.bind("<FocusIn>", self.layer_transform_begin)
            entry.bind("<Return>", lambda _event, changed=key: self.commit_layer_transform(changed))
            entry.bind("<FocusOut>", lambda _event, changed=key: self.commit_layer_transform(changed))
            self.transform_controls.append(entry)
        actions = tk.Frame(transform, bg=PANEL)
        actions.pack(fill="x", padx=5, pady=(0, 5))
        tk.Checkbutton(actions, text="KEEP PROPORTIONS", variable=self.transform_proportional,
                       bg=PANEL, fg=INK, selectcolor=FIELD, activebackground=PANEL,
                       activeforeground=INK, font=("Segoe UI", 7, "bold")).pack(side="left")
        for text, command in (("FLIP ↔", lambda: self.flip_layer("flip_x")),
                              ("FLIP ↕", lambda: self.flip_layer("flip_y")),
                              ("RESET", self.reset_layer_transform)):
            button = self._button(actions, text, command)
            button.pack(side="right", padx=(2, 0), ipadx=2)
            self.transform_controls.append(button)
        masks = tk.Frame(panel, bg=PANEL)
        masks.pack(fill="x", padx=8, pady=4)
        for text, command in (("WHITE MASK", lambda: self.create_mask(255)),
                              ("BLACK MASK", lambda: self.create_mask(0)),
                              ("INVERT", self.invert_mask),
                              ("SHOW MASK", self.toggle_mask_overlay)):
            self._button(masks, text, command).pack(side="left", fill="x", expand=True, padx=(0, 2))
        self.mask_link_button = self._button(panel, "🔗 MASK MOVES WITH LAYER", self.toggle_mask_link)
        self.mask_link_button.pack(fill="x", padx=8, pady=(0, 4), ipady=3)
        selections = tk.Frame(panel, bg=PANEL)
        selections.pack(fill="x", padx=8, pady=(0, 4))
        for text, command in (("LINEAR", lambda: self.set_tool("gradient_mask")),
                              ("RADIAL", lambda: self.set_tool("radial_mask")),
                              ("COLOUR RANGE", lambda: self.set_tool("color_range")),
                              ("OUTLINE", lambda: self.set_tool("outline_mask"))):
            self._button(selections, text, command).pack(side="left", fill="x", expand=True, padx=(0, 2))
        selection_options = tk.Frame(panel, bg=PANEL)
        selection_options.pack(fill="x", padx=8, pady=(0, 4))
        combine = tk.OptionMenu(selection_options, self.mask_combine_mode,
                                "replace", "add", "subtract", "intersect")
        combine.configure(bg=FIELD, fg=INK, activebackground=ACCENT, relief="flat",
                          highlightthickness=0)
        combine["menu"].configure(bg=FIELD, fg=INK, activebackground=ACCENT)
        combine.pack(side="left", fill="x", expand=True)
        tk.Checkbutton(selection_options, text="REVERSE", variable=self.mask_reverse,
                       bg=PANEL, fg=INK, selectcolor=FIELD,
                       activebackground=PANEL).pack(side="right", padx=(6, 0))
        tolerance = tk.Frame(panel, bg=PANEL)
        tolerance.pack(fill="x", padx=8, pady=(0, 4))
        tk.Label(tolerance, text="Colour tolerance", bg=PANEL, fg=DIM).pack(side="left")
        tk.Scale(tolerance, from_=0, to=255, variable=self.color_range_tolerance,
                 orient="horizontal", showvalue=True, length=150, bg=PANEL,
                 fg=INK, troughcolor=FIELD, activebackground=ACCENT,
                 highlightthickness=0, bd=0).pack(side="right")
        feather = tk.Frame(panel, bg=PANEL); feather.pack(fill="x", padx=8, pady=(0, 4))
        tk.Label(feather, text="Selection feather", bg=PANEL, fg=DIM).pack(side="left")
        tk.Scale(feather, from_=0, to=100, variable=self.mask_feather, orient="horizontal",
                 showvalue=True, length=150, bg=PANEL, fg=INK, troughcolor=FIELD,
                 activebackground=ACCENT, highlightthickness=0, bd=0).pack(side="right")
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
        brush_detail = tk.Frame(panel, bg=PANEL)
        brush_detail.pack(fill="x", padx=8, pady=(0, 4))
        self.mask_brush_hardness = tk.IntVar(value=75)
        self.mask_brush_opacity = tk.IntVar(value=100)
        self.mask_brush_flow = tk.IntVar(value=100)
        for label, variable in (("Hardness", self.mask_brush_hardness),
                                ("Opacity", self.mask_brush_opacity),
                                ("Flow", self.mask_brush_flow)):
            cell = tk.Frame(brush_detail, bg=PANEL); cell.pack(side="left", fill="x", expand=True)
            tk.Label(cell, text=label, bg=PANEL, fg=DIM, font=("Segoe UI", 7)).pack(anchor="w")
            tk.Scale(cell, from_=1, to=100, variable=variable, orient="horizontal",
                     showvalue=True, length=100, bg=PANEL, fg=INK, troughcolor=FIELD,
                     activebackground=ACCENT, highlightthickness=0, bd=0).pack(fill="x")
        mask_actions = tk.Frame(panel, bg=PANEL); mask_actions.pack(fill="x", padx=8, pady=(0, 4))
        for text, command in (("VIEW MASK", self.view_mask_grayscale),
                              ("DISABLE", self.toggle_mask_enabled),
                              ("DELETE", self.delete_mask)):
            self._button(mask_actions, text, command).pack(side="left", fill="x", expand=True, padx=(0, 2))
        self._button(panel, "LAYER STYLES…", self.layer_styles).pack(fill="x", padx=8, pady=(0, 8), ipady=3)

    def _build_presets(self):
        panel = self.accordion("LEWKS + BATCH", False)
        self._button(panel, "BROWSE STOCK LEWKS…", self.browse_stock_lewks, True).pack(
            fill="x", padx=8, pady=(5, 2), ipady=4)
        for text, command in (("COPY ADJUSTMENTS", self.copy_adjustments),
                              ("PASTE ADJUSTMENTS", self.paste_adjustments),
                              ("SAVE PRESET…", self.save_preset), ("LOAD PRESET…", self.load_preset),
                              ("BATCH APPLY TO SELECTION…", self.batch_apply)):
            self._button(panel, text, command).pack(fill="x", padx=8, pady=(4, 0), ipady=3)
        tk.Label(panel, text="Batch export always creates new JPEG copies.", bg=PANEL, fg=DIM,
                 wraplength=270, justify="left", font=("Segoe UI", 8)).pack(anchor="w", padx=8, pady=8)

    def browse_stock_lewks(self):
        window = tk.Toplevel(self)
        window.title("SNAP SLAPPER — Stock LEWKS")
        window.configure(bg=BG)
        window.geometry("720x520")
        window.transient(self)
        window.grab_set()
        lewks = built_in_lewks.all_lewks()
        left = tk.Frame(window, bg=PANEL, width=300)
        left.pack(side="left", fill="both", padx=(12, 6), pady=12)
        right = tk.Frame(window, bg=PANEL)
        right.pack(side="right", fill="both", expand=True, padx=(6, 12), pady=12)
        listing = tk.Listbox(left, bg="#0b0b0b", fg=INK, selectbackground=ACCENT,
                             selectforeground=BG, relief="flat", font=("Segoe UI", 10), width=30)
        listing.pack(fill="both", expand=True, padx=8, pady=8)
        for item in lewks:
            listing.insert("end", item["name"])
        name_var, category_var, description_var = tk.StringVar(), tk.StringVar(), tk.StringVar()
        tk.Label(right, textvariable=name_var, bg=PANEL, fg=ACCENT,
                 font=("Segoe UI Black", 18, "bold"), wraplength=360,
                 justify="left").pack(anchor="w", padx=16, pady=(22, 4))
        tk.Label(right, textvariable=category_var, bg=PANEL, fg=DIM,
                 font=("Segoe UI", 9, "bold")).pack(anchor="w", padx=16)
        tk.Label(right, textvariable=description_var, bg=PANEL, fg=INK,
                 font=("Segoe UI", 11), wraplength=360, justify="left").pack(
                     anchor="w", padx=16, pady=(20, 24))
        strength = tk.IntVar(value=100)
        tk.Label(right, text="OVERALL STRENGTH", bg=PANEL, fg=DIM,
                 font=("Segoe UI", 8, "bold")).pack(anchor="w", padx=16)
        tk.Scale(right, from_=0, to=100, variable=strength, orient="horizontal",
                 bg=PANEL, fg=INK, troughcolor=FIELD, activebackground=ACCENT,
                 highlightthickness=0).pack(fill="x", padx=12)

        def selected():
            choice = listing.curselection()
            return lewks[choice[0]] if choice else None

        def describe(_event=None):
            item = selected()
            if item:
                name_var.set(item["name"])
                category_var.set(item["category"].upper())
                description_var.set(item["description"])

        def apply():
            item = selected()
            if not item:
                return
            self.document.apply_recipe(built_in_lewks.recipe(item["id"], strength.get()))
            self._load_document_controls()
            self.status_label.configure(text=f"LEWK applied: {item['name']} · {strength.get()}%")
            window.destroy()

        listing.bind("<<ListboxSelect>>", describe)
        listing.selection_set(0)
        describe()
        buttons = tk.Frame(right, bg=PANEL)
        buttons.pack(side="bottom", fill="x", padx=16, pady=16)
        self._button(buttons, "CANCEL", window.destroy).pack(side="left", fill="x", expand=True)
        self._button(buttons, "APPLY LEWK", apply, True).pack(
            side="right", fill="x", expand=True, padx=(8, 0))

    def _build_history(self):
        panel = self.accordion("HISTORY", False)
        self.history_list = tk.Listbox(panel, height=6, bg="#0b0b0b", fg=INK,
                                       selectbackground=ACCENT, selectforeground=BG,
                                       highlightthickness=0, relief="flat")
        self.history_list.pack(fill="x", padx=8, pady=7)
        self.history_list.bind("<Double-Button-1>", self.restore_history)

    def _load_document_controls(self):
        self.update_document_title()
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
            if self.mask_grayscale_var.get() and self.compare_mode == "edited":
                layer = self.current_layer()
                if layer and layer.get("mask"):
                    mask = self.displayed_layer_mask(layer, shown.size)
                    shown = Image.merge("RGB", (mask, mask, mask))
            elif self.mask_overlay_var.get() and self.compare_mode == "edited":
                layer = self.current_layer()
                if layer and layer.get("mask"):
                    mask = self.displayed_layer_mask(layer, shown.size)
                    hidden = ImageOps.invert(mask).point(lambda value: int(value * .55))
                    red = Image.new("RGB", shown.size, (255, 32, 32))
                    shown = Image.composite(red, shown, hidden)
            photo = ImageTk.PhotoImage(shown)
            self.canvas.delete("image")
            x = width // 2 + self.pan_x
            y = height // 2 + self.pan_y
            self.canvas.create_image(x, y, image=photo, tags="image")
            self.canvas.tag_lower("image")
            self.canvas.image = photo
            self.display_box = (x - shown.width // 2, y - shown.height // 2,
                                x + shown.width // 2, y + shown.height // 2)
            self.draw_selected_layer_bounds(shown.size)
            if self.zoom <= 0:
                with Image.open(self.document.source_path) as source:
                    source_size = ImageOps.exif_transpose(source).size
                self.fit_zoom = min(shown.width / max(1, source_size[0]),
                                    shown.height / max(1, source_size[1]))
                zoom_text = f"{self.fit_zoom * 100:.0f}% (FIT)"
            else:
                zoom_text = f"{self.zoom * 100:.0f}%"
            self.zoom_label.configure(text=zoom_text)
            self.zoom_top_label.configure(text=zoom_text)
            self.draw_histogram(edited)
            self.draw_curve()
        except Exception as exc:
            self.canvas.delete("all")
            self.canvas.create_text(width // 2, height // 2, text=str(exc), fill="#ff5555")

    def displayed_layer_mask(self, layer, shown_size):
        import base64, io
        mask = Image.open(io.BytesIO(base64.b64decode(layer["mask"]))).convert("L")
        if not layer.get("mask_linked", True):
            return self.document._canvas_mask(mask, shown_size,
                                              layer.get("mask_transform", {}))
        try:
            if layer.get("type") == "image":
                with Image.open(layer.get("path", "")) as source_layer:
                    layer_size = ImageOps.exif_transpose(source_layer).size
                source = Image.new("RGBA", layer_size, (255, 255, 255, 255))
                source.thumbnail(shown_size, Image.Resampling.LANCZOS)
            elif layer.get("type") == "text":
                source = Image.new("RGBA", self.document._text_layer_image(layer).size,
                                   (255, 255, 255, 255))
            else:
                return mask.resize(shown_size, Image.Resampling.LANCZOS)
            transformed = self.document._image_layer_canvas(
                source, shown_size, layer.get("transform", {}), mask).getchannel("A")
            coverage = self.document._image_layer_canvas(
                source, shown_size, layer.get("transform", {})).getchannel("A")
            displayed = Image.new("L", shown_size, 255)
            displayed.paste(transformed, (0, 0), coverage)
            return displayed
        except Exception:
            return mask.resize(shown_size, Image.Resampling.LANCZOS)

    def fit_image(self):
        self.zoom = 0
        self.pan_x = self.pan_y = 0
        self.schedule_render()

    def actual_size(self):
        self.zoom = 1.0
        self.pan_x = self.pan_y = 0
        self.schedule_render()

    def mouse_zoom(self, event):
        self.zoom_by(1.15 if event.delta > 0 else 1 / 1.15)
        return "break"

    def zoom_by(self, factor):
        current = self.zoom if self.zoom > 0 else self.fit_zoom
        self.zoom = max(.05, min(8.0, current * factor))
        self.schedule_render()

    def zoom_in(self):
        self.zoom_by(1.25)

    def zoom_out(self):
        self.zoom_by(1 / 1.25)

    def set_tool(self, name):
        if name == "mask" and not self.current_layer():
            self.start_local_adjustment()
            return
        if name in {"move_layer", "crop", "spot", "red_eye", "mask", "gradient_mask", "radial_mask", "color_range", "outline_mask"} and self.compare_mode != "edited":
            self.compare_mode = "edited"
            self.schedule_render()
        self.tool = name
        if name in {"mask", "gradient_mask", "radial_mask", "color_range", "outline_mask"} and self.current_layer():
            self.selected_layer_target = "mask"
            self.refresh_layers()
        if name != "crop":
            self.crop_rect = None
            self.canvas.delete("crop")
        if name != "outline_mask":
            self.outline_points = []
            self.canvas.delete("mask-outline")
        self.canvas.configure(cursor="crosshair" if name in {"crop", "spot", "red_eye", "mask", "gradient_mask", "radial_mask", "color_range", "outline_mask"} else "fleur")
        instructions = {
            "pan": "PAN — drag the photograph; mouse wheel zooms",
            "move_layer": "MOVE LAYER — select an image layer, then drag it on the canvas",
            "crop": "CROP — drag a rectangle, then press Enter or APPLY CROP",
            "spot": "SPOT — adjust Spot size, then click a blemish; Ctrl+Z undoes",
            "red_eye": "RED EYE — size the circle over one pupil and click",
            "mask": "MASK BRUSH — select a layer and mask, then paint Hide or Reveal",
            "gradient_mask": "GRADIENT MASK — drag across the photograph; start is hidden, end is revealed",
            "radial_mask": "RADIAL MASK — drag from the protected centre to the feathered edge",
            "color_range": "COLOUR RANGE — set tolerance, then click a colour in the photograph",
            "outline_mask": "OUTLINE MASK — click around the subject; double-click to close the selection",
        }
        self.status_label.configure(text=instructions.get(name, name.upper()))

    def start_local_adjustment(self):
        layer = self.document.add_adjustment_layer("Local Adjustment")
        self.select_new_top_layer()
        self.create_mask(0)
        self.mask_brush_value.set(255)
        self.tool = "mask"
        self.canvas.configure(cursor="crosshair")
        self.status_label.configure(
            text="LOCAL ADJUST — choose an adjustment, then paint where it should appear")
        self.refresh_history()
        self.schedule_render()
        return layer

    def canvas_motion(self, event):
        self.canvas.delete("tool-preview")
        if self.tool not in {"spot", "red_eye", "mask", "color_range"}:
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
        elif self.tool == "outline_mask" and len(self.outline_points) >= 3:
            self.finish_outline_mask()

    def canvas_press(self, event):
        self.drag_start = (event.x, event.y)
        if self.tool == "move_layer":
            layer = self.current_layer()
            mask_target = bool(layer and self.selected_layer_target == "mask"
                               and not layer.get("mask_linked", True))
            if layer and (layer.get("type") in {"image", "text"} or mask_target):
                self._layer_transform_before = copy.deepcopy(layer)
                field = "mask_transform" if mask_target else "transform"
                transform = layer.setdefault(field, {})
                self._layer_move_origin = (float(transform.get("x", .5)),
                                           float(transform.get("y", .5)))
                handles = getattr(self, "layer_transform_handles", {})
                if handles.get("rotate") and math.dist((event.x, event.y), handles["rotate"]) <= 12:
                    self._layer_drag_mode = "rotate"
                elif any(math.dist((event.x, event.y), point) <= 12
                         for point in handles.get("corners", ())):
                    self._layer_drag_mode = "scale"
                else:
                    self._layer_drag_mode = "move"
            else:
                self._layer_move_origin = None
                self._layer_drag_mode = None
                self.status_label.configure(text="MOVE LAYER — select an image layer first")
        elif self.tool in {"spot", "red_eye"}:
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
        elif self.tool == "color_range":
            self.create_color_range_mask(event.x, event.y)
        elif self.tool == "outline_mask":
            point = self.canvas_to_normalized(event.x, event.y)
            if point:
                self.outline_points.append(point)
                self.draw_outline_preview()

    def canvas_drag(self, event):
        if not self.drag_start:
            return
        if self.tool == "pan":
            self.pan_x += event.x - self.drag_start[0]
            self.pan_y += event.y - self.drag_start[1]
            self.drag_start = (event.x, event.y)
            self.schedule_render()
        elif self.tool == "move_layer" and self._layer_move_origin:
            left, top, right, bottom = self.display_box
            layer = self.current_layer()
            if layer:
                field = ("mask_transform" if self.selected_layer_target == "mask"
                         and not layer.get("mask_linked", True) else "transform")
                transform = layer.setdefault(field, {})
                centre = getattr(self, "layer_transform_handles", {}).get("centre")
                if self._layer_drag_mode == "rotate" and centre:
                    transform["rotation"] = (math.degrees(math.atan2(
                        event.y - centre[1], event.x - centre[0])) + 90) % 360
                elif self._layer_drag_mode == "scale" and centre:
                    start_distance = max(1, math.dist(self.drag_start, centre))
                    factor = max(.01, math.dist((event.x, event.y), centre) / start_distance)
                    original = self._layer_transform_before.get(field, {})
                    transform["scale_x"] = max(.01, min(20, float(
                        original.get("scale_x", 1.0)) * factor))
                    transform["scale_y"] = max(.01, min(20, float(
                        original.get("scale_y", 1.0)) * factor))
                else:
                    transform["x"] = self._layer_move_origin[0] + (
                        event.x - self.drag_start[0]) / max(1, right - left)
                    transform["y"] = self._layer_move_origin[1] + (
                        event.y - self.drag_start[1]) / max(1, bottom - top)
                self.load_layer_transform(layer)
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
        elif self.tool in {"gradient_mask", "radial_mask"}:
            self.canvas.delete("tool-preview")
            self.canvas.create_line(self.drag_start[0], self.drag_start[1], event.x, event.y,
                                    fill=ACCENT, width=3, arrow="last", tags="tool-preview")

    def canvas_release(self, event):
        if self.tool == "mask" and self._mask_dirty:
            self.document.record("Paint layer mask")
            self.refresh_layers()
            self.refresh_history()
            self._mask_dirty = False
        elif self.tool in {"gradient_mask", "radial_mask"} and self.drag_start:
            if self.tool == "radial_mask":
                self.apply_radial_mask(self.drag_start[0], self.drag_start[1], event.x, event.y)
            else:
                self.apply_gradient_mask(self.drag_start[0], self.drag_start[1], event.x, event.y)
        elif self.tool == "move_layer" and self._layer_move_origin:
            layer = self.current_layer()
            if layer and self._layer_transform_before != layer:
                self.document.record("Move image layer")
                self.refresh_history()
            self._layer_transform_before = None
            self._layer_move_origin = None
            self._layer_drag_mode = None
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
        previous = self.document.geometry.get("crop")
        if previous:
            old_left, old_top, old_right, old_bottom = previous
            width, height = old_right - old_left, old_bottom - old_top
            left, right = old_left + left * width, old_left + right * width
            top, bottom = old_top + top * height, old_top + bottom * height
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

    def draw_histogram(self, image=None):
        canvas = self.hist_canvas
        canvas.delete("all")
        try:
            if image is None:
                values = self.document.histogram((300, 200))
            else:
                sample = image.copy()
                sample.thumbnail((300, 200), Image.Resampling.BILINEAR)
                red, green, blue = sample.convert("RGB").split()
                values = {"red": red.histogram(), "green": green.histogram(),
                          "blue": blue.histogram()}
        except Exception:
            return
        width, height = max(1, canvas.winfo_width()), max(1, canvas.winfo_height())
        mode = self.histogram_mode.get() if hasattr(self, "histogram_mode") else "rgb"
        if mode == "luma":
            if image is not None:
                values["luma"] = image.convert("L").histogram()
            else:
                values["luma"] = values.get("luminance", [0] * 256)
            channels = (("luma", "#d8d8d8"),)
        else:
            channels = (("red", "#ff5555"), ("green", "#55ff55"), ("blue", "#5599ff"))
        maximum = self._histogram_ceiling(values, channels)
        for channel, color in channels:
            points = []
            for index, count in enumerate(values[channel]):
                displayed = min(count, maximum)
                points.extend((index / 255 * width, height - displayed / maximum * height))
            canvas.create_line(*points, fill=color, width=1)

    @staticmethod
    def _histogram_ceiling(values, channels):
        # A clipped black or white bin can dwarf every useful tonal bin. Scale from
        # the 98th percentile of interior bins, then clip exceptional spikes to the
        # top edge so clipping remains visible without flattening the whole graph.
        interior = sorted(count for channel, _colour in channels
                          for count in values[channel][1:255] if count > 0)
        if not interior:
            return max((max(values[channel]) for channel, _colour in channels), default=1) or 1
        index = min(len(interior) - 1, int((len(interior) - 1) * .98))
        return max(1, interior[index])

    def current_layer(self):
        if not self.selected_layer_id:
            return None
        return next((layer for layer in self.document.layers
                     if layer.get("id") == self.selected_layer_id), None)

    def adjustment_target(self):
        layer = self.current_layer() if hasattr(self, "layer_rows") else None
        if layer and layer.get("type") in {"adjustment", "image", "text"}:
            return layer.setdefault("adjustments", copy.deepcopy(editor_engine.DEFAULT_ADJUSTMENTS))
        return self.document.adjustments

    def refresh_layers(self):
        if self.selected_layer_id and not any(
                layer.get("id") == self.selected_layer_id for layer in self.document.layers):
            self.selected_layer_id = None
            self.selected_layer_target = "content"
        for child in self.layer_rows.winfo_children():
            child.destroy()
        self.layer_thumbnail_images = []
        for layer in reversed(self.document.layers):
            self._build_layer_row(layer)
        self._build_base_layer_row()

    def _thumbnail_photo(self, image):
        thumb = ImageOps.fit(image.convert("RGB"), (42, 42), method=Image.Resampling.LANCZOS)
        photo = ImageTk.PhotoImage(thumb)
        self.layer_thumbnail_images.append(photo)
        return photo

    def _content_thumbnail(self, layer=None):
        try:
            if layer is None:
                with Image.open(self.document.source_path) as source:
                    return self._thumbnail_photo(ImageOps.exif_transpose(source))
            if layer.get("type") == "image" and os.path.isfile(layer.get("path", "")):
                with Image.open(layer["path"]) as source:
                    return self._thumbnail_photo(ImageOps.exif_transpose(source))
            if layer.get("type") == "text":
                return self._thumbnail_photo(self.document._text_layer_image(layer))
        except Exception:
            pass
        image = Image.new("RGB", (42, 42), (24, 24, 24))
        draw = ImageDraw.Draw(image)
        draw.rectangle((5, 5, 36, 36), outline=(57, 255, 20), width=2)
        draw.line((8, 31, 17, 20, 24, 26, 34, 10), fill=(230, 230, 230), width=2)
        return self._thumbnail_photo(image)

    def _mask_thumbnail(self, layer):
        if layer.get("mask"):
            try:
                import base64, io
                mask = Image.open(io.BytesIO(base64.b64decode(layer["mask"]))).convert("L")
                return self._thumbnail_photo(Image.merge("RGB", (mask, mask, mask)))
            except Exception:
                pass
        image = Image.new("RGB", (42, 42), (18, 18, 18))
        draw = ImageDraw.Draw(image)
        draw.rectangle((1, 1, 40, 40), outline=(70, 70, 70))
        draw.line((8, 8, 34, 34), fill=(85, 85, 85), width=2)
        draw.line((34, 8, 8, 34), fill=(85, 85, 85), width=2)
        return self._thumbnail_photo(image)

    def _target_thumbnail(self, parent, photo, layer_id, target, description):
        selected = self.selected_layer_id == layer_id and self.selected_layer_target == target
        label = tk.Label(parent, image=photo, bg="#0b0b0b", cursor="hand2",
                         highlightthickness=3 if selected else 1,
                         highlightbackground=ACCENT if selected else BORDER,
                         highlightcolor=ACCENT, takefocus=True)
        label.bind("<Button-1>", lambda _event: self.select_layer(layer_id, target))
        label.bind("<Return>", lambda _event: self.select_layer(layer_id, target))
        label.bind("<space>", lambda _event: self.select_layer(layer_id, target))
        label.bind("<Enter>", lambda _event: self.status_label.configure(text=description))
        return label

    def _build_layer_row(self, layer):
        layer_id = layer.get("id")
        row = tk.Frame(self.layer_rows, bg="#121212", height=58,
                       highlightthickness=1, highlightbackground="#202020")
        row.pack(fill="x", pady=(0, 2))
        row.pack_propagate(False)
        visible = self._button(row, "●" if layer.get("visible", True) else "○",
                               lambda lid=layer_id: self.toggle_layer_visibility(lid))
        visible.pack(side="left", padx=(3, 2), ipadx=2, ipady=5)
        drag = tk.Label(row, text="≡", bg="#121212", fg=DIM, cursor="sb_v_double_arrow",
                        font=("Segoe UI", 12, "bold"))
        drag.pack(side="left", padx=(0, 3), fill="y")
        drag.bind("<ButtonPress-1>", lambda event, lid=layer_id: self.layer_drag_begin(event, lid))
        drag.bind("<B1-Motion>", self.layer_drag_motion)
        drag.bind("<ButtonRelease-1>", self.layer_drag_end)
        content = self._target_thumbnail(
            row, self._content_thumbnail(layer), layer_id, "content",
            f"Layer pixels: {layer.get('name', 'Layer')}")
        content.pack(side="left", padx=(0, 5), pady=5)
        mask = self._target_thumbnail(
            row, self._mask_thumbnail(layer), layer_id, "mask",
            f"Layer mask: {layer.get('name', 'Layer')}")
        mask.pack(side="left", padx=(0, 7), pady=5)
        linked = layer.get("mask_linked", True)
        link = tk.Label(row, text="🔗" if linked else "⛓", bg="#121212",
                        fg=ACCENT if linked else DIM, cursor="hand2",
                        font=("Segoe UI Symbol", 9))
        link.pack(side="right", padx=4)
        link.bind("<Button-1>", lambda _event, lid=layer_id: self.toggle_mask_link(lid))
        if layer.get("type") == "text":
            path = layer.get("font_path", "")
            family = layer.get("font_family", "Default")
            missing = (bool(path) and not os.path.isfile(path)) or (
                not path and family not in {"", "Default"}
                and not self.document._font_reference(layer))
            if missing:
                warning = tk.Label(row, text="⚠", bg="#121212", fg="#ffb000",
                                   font=("Segoe UI Symbol", 10, "bold"))
                warning.pack(side="right", padx=2)
                warning.bind("<Enter>", lambda _event: self.status_label.configure(
                    text="MISSING FONT — double-click the text layer to locate or substitute it"))
        kind = {"adjustment": "ADJUSTMENT", "image": "IMAGE", "text": "TEXT"}.get(
            layer.get("type"), "UNKNOWN")
        text = tk.Frame(row, bg="#121212")
        text.pack(side="left", fill="both", expand=True, pady=6)
        name = tk.Label(text, text=layer.get("name", "Layer"), bg="#121212", fg=INK,
                        anchor="w", font=("Segoe UI", 9, "bold"), cursor="hand2")
        name.pack(fill="x")
        tk.Label(text, text=kind, bg="#121212", fg=DIM, anchor="w",
                 font=("Segoe UI", 7)).pack(fill="x")
        name.bind("<Button-1>", lambda _event, lid=layer_id: self.select_layer(lid, "content"))
        if layer.get("type") == "text":
            name.bind("<Double-Button-1>", lambda _event, lid=layer_id: self.edit_text_layer(lid))

    def _build_base_layer_row(self):
        selected = self.selected_layer_id is None
        row = tk.Frame(self.layer_rows, bg="#121212", height=58,
                       highlightthickness=1, highlightbackground="#202020")
        row.pack(fill="x", pady=(0, 2))
        row.pack_propagate(False)
        tk.Label(row, text="◆", bg="#121212", fg=DIM, width=4).pack(side="left")
        thumb = self._target_thumbnail(row, self._content_thumbnail(), None, "content",
                                      "Base photograph adjustments")
        if selected:
            thumb.configure(highlightthickness=3, highlightbackground=ACCENT)
        thumb.pack(side="left", padx=(0, 7), pady=5)
        text = tk.Frame(row, bg="#121212")
        text.pack(side="left", fill="both", expand=True, pady=6)
        name = tk.Label(text, text="BASE PHOTO", bg="#121212", fg=INK, anchor="w",
                        font=("Segoe UI", 9, "bold"), cursor="hand2")
        name.pack(fill="x")
        tk.Label(text, text="BASE ADJUSTMENTS", bg="#121212", fg=DIM, anchor="w",
                 font=("Segoe UI", 7)).pack(fill="x")
        name.bind("<Button-1>", lambda _event: self.select_layer(None, "content"))

    def select_layer(self, layer_id, target="content"):
        self.selected_layer_id = layer_id
        self.selected_layer_target = target
        self.refresh_layers()
        self.layer_selected()

    def select_new_top_layer(self):
        if not self.document.layers:
            self.select_layer(None)
            return
        self.select_layer(self.document.layers[-1].get("id"), "content")

    def toggle_layer_visibility(self, layer_id):
        layer = next((item for item in self.document.layers if item.get("id") == layer_id), None)
        if not layer:
            return
        layer["visible"] = not layer.get("visible", True)
        self.document.record("Layer visibility")
        self.refresh_layers()
        self.refresh_history()
        self.schedule_render()

    def layer_drag_begin(self, event, layer_id):
        self._layer_drag_id = layer_id
        self._layer_drag_start_y = event.y_root
        self.selected_layer_id = layer_id
        self.selected_layer_target = "content"
        self.layer_selected()

    def layer_drag_motion(self, event):
        if self._layer_drag_id:
            self.status_label.configure(text="MOVE LAYER — release at the new stack position")

    def layer_drag_end(self, event):
        layer_id = self._layer_drag_id
        self._layer_drag_id = None
        if not layer_id:
            return
        displayed = list(reversed(self.document.layers))
        target_display = len(displayed) - 1
        for index, layer in enumerate(displayed):
            widgets = [child for child in self.layer_rows.winfo_children()
                       if child.winfo_class() == "Frame"]
            if index < len(widgets) and event.y_root < widgets[index].winfo_rooty() + widgets[index].winfo_height() // 2:
                target_display = index
                break
        layer = next((item for item in self.document.layers if item.get("id") == layer_id), None)
        if not layer:
            return
        old = self.document.layers.index(layer)
        self.document.layers.pop(old)
        target = max(0, min(len(self.document.layers), len(self.document.layers) - target_display))
        self.document.layers.insert(target, layer)
        if old != target:
            self.document.record("Move layer")
            self.refresh_history()
        self.refresh_layers()
        self.schedule_render()

    def layer_selected(self):
        layer = self.current_layer()
        if layer:
            self.layer_visible_var.set(bool(layer.get("visible", True)))
            self.layer_blend_var.set(layer.get("blend", "normal"))
            self.layer_opacity_var.set(float(layer.get("opacity", 1.0)))
        self.load_layer_transform(layer)
        linked = True if not layer else layer.get("mask_linked", True)
        self.mask_link_button.configure(
            text="🔗 MASK MOVES WITH LAYER" if linked else "⛓ MASK STAYS ON CANVAS")
        if layer and self.selected_layer_target == "mask":
            self.adjustment_target_label.configure(text=(layer.get("name", "LAYER") + " — MASK").upper())
            self.adjustment_target_help.configure(
                text="Mask tools affect this mask. Red overlay marks hidden areas.")
            self.status_label.configure(text=f"EDITING MASK — {layer.get('name', 'Layer')}")
        elif layer and layer.get("type") == "adjustment":
            self.adjustment_target_label.configure(text=layer.get("name", "ADJUSTMENT").upper())
            self.adjustment_target_help.configure(
                text="Light, Colour, Presence, Levels and Tone Curve below edit this layer.")
            self.status_label.configure(
                text=f"ADJUSTMENT LAYER — editing {layer.get('name', 'Adjustment')}")
        elif layer:
            self.adjustment_target_label.configure(text=layer.get("name", "IMAGE").upper())
            self.adjustment_target_help.configure(
                text=("Double-click the layer name to edit text and font properties."
                      if layer.get("type") == "text"
                      else "The standard controls below affect this image layer only."))
            self.status_label.configure(
                text=f"IMAGE LAYER — editing {layer.get('name', 'Image')}")
        else:
            self.adjustment_target_label.configure(text="BASE PHOTO")
            self.adjustment_target_help.configure(
                text="The controls below affect the original photograph.")
        target = self.adjustment_target()
        for name, variable in self.adjustment_vars.items():
            variable.set(target.get(name, 0))
        self.black_white_var.set(bool(target.get("black_white")))
        self.draw_curve()

    @staticmethod
    def _default_transform():
        return {"x": .5, "y": .5, "scale_x": 1.0, "scale_y": 1.0,
                "rotation": 0.0, "flip_x": False, "flip_y": False}

    def load_layer_transform(self, layer=None):
        layer = layer if layer is not None else self.current_layer()
        mask_target = bool(layer and self.selected_layer_target == "mask"
                           and not layer.get("mask_linked", True))
        enabled = bool(layer and (layer.get("type") in {"image", "text"} or mask_target))
        transform = self._default_transform()
        if enabled:
            field = "mask_transform" if mask_target else "transform"
            transform.update(layer.setdefault(field, self._default_transform()))
        for key, variable in self.transform_vars.items():
            value = float(transform.get(key, 0))
            variable.set(value if key == "rotation" else value * 100)
        for control in self.transform_controls:
            control.configure(state="normal" if enabled else "disabled")

    def layer_transform_begin(self, _event=None):
        layer = self.current_layer()
        self._layer_transform_before = copy.deepcopy(layer) if layer else None

    def commit_layer_transform(self, changed=None):
        layer = self.current_layer()
        mask_target = self.selected_layer_target == "mask" and not layer.get("mask_linked", True) if layer else False
        if not layer or (layer.get("type") not in {"image", "text"} and not mask_target):
            return
        field = "mask_transform" if mask_target else "transform"
        transform = layer.setdefault(field, self._default_transform())
        try:
            values = {key: float(variable.get()) for key, variable in self.transform_vars.items()}
        except (tk.TclError, ValueError):
            self.load_layer_transform(layer)
            return
        transform["x"] = values["x"] / 100
        transform["y"] = values["y"] / 100
        if changed in {"scale_x", "scale_y"} and self.transform_proportional.get():
            other = "scale_y" if changed == "scale_x" else "scale_x"
            values[other] = values[changed]
            self.transform_vars[other].set(values[other])
        transform["scale_x"] = max(.01, min(20.0, values["scale_x"] / 100))
        transform["scale_y"] = max(.01, min(20.0, values["scale_y"] / 100))
        transform["rotation"] = values["rotation"] % 360
        if self._layer_transform_before != layer:
            self.document.record("Transform image layer")
            self.refresh_history()
        self._layer_transform_before = copy.deepcopy(layer)
        self.schedule_render()

    def flip_layer(self, key):
        layer = self.current_layer()
        if not layer or layer.get("type") not in {"image", "text"}:
            return
        transform = layer.setdefault("transform", self._default_transform())
        transform[key] = not bool(transform.get(key, False))
        self.document.record("Flip image layer")
        self.refresh_history(); self.schedule_render()

    def reset_layer_transform(self):
        layer = self.current_layer()
        if not layer or layer.get("type") not in {"image", "text"}:
            return
        field = ("mask_transform" if self.selected_layer_target == "mask"
                 and not layer.get("mask_linked", True) else "transform")
        layer[field] = self._default_transform()
        self.document.record("Reset image layer transform")
        self.load_layer_transform(layer)
        self.refresh_history(); self.schedule_render()

    def toggle_mask_link(self, layer_id=None):
        layer = (next((item for item in self.document.layers if item.get("id") == layer_id), None)
                 if layer_id else self.current_layer())
        if not layer:
            return
        layer["mask_linked"] = not layer.get("mask_linked", True)
        self.document.record("Link layer mask" if layer["mask_linked"] else "Unlink layer mask")
        self.refresh_layers(); self.layer_selected(); self.refresh_history(); self.schedule_render()

    def draw_selected_layer_bounds(self, shown_size):
        self.canvas.delete("layer-transform")
        self.layer_transform_handles = {}
        layer = self.current_layer()
        if self.compare_mode != "edited" or not layer:
            return
        try:
            if self.selected_layer_target == "mask" and not layer.get("mask_linked", True):
                width, height = shown_size
                transform_field = "mask_transform"
            elif layer.get("type") == "image" and os.path.isfile(layer.get("path", "")):
                with Image.open(layer["path"]) as source:
                    width, height = ImageOps.exif_transpose(source).size
                transform_field = "transform"
            elif layer.get("type") == "text":
                width, height = self.document._text_layer_image(layer).size
                transform_field = "transform"
            else:
                return
        except Exception:
            return
        fit = min(1.0, shown_size[0] / max(1, width), shown_size[1] / max(1, height))
        transform = self._default_transform(); transform.update(layer.get(transform_field, {}))
        width *= fit * max(.01, float(transform["scale_x"]))
        height *= fit * max(.01, float(transform["scale_y"]))
        angle = math.radians(float(transform["rotation"]))
        bound_w = abs(width * math.cos(angle)) + abs(height * math.sin(angle))
        bound_h = abs(width * math.sin(angle)) + abs(height * math.cos(angle))
        left, top, right, bottom = self.display_box
        centre_x = left + float(transform["x"]) * (right - left)
        centre_y = top + float(transform["y"]) * (bottom - top)
        self.canvas.create_rectangle(centre_x - bound_w / 2, centre_y - bound_h / 2,
                                     centre_x + bound_w / 2, centre_y + bound_h / 2,
                                     outline=ACCENT, width=2, dash=(5, 3),
                                     tags="layer-transform")
        radius = 5
        corners = ((centre_x - bound_w / 2, centre_y - bound_h / 2),
                   (centre_x + bound_w / 2, centre_y - bound_h / 2),
                   (centre_x - bound_w / 2, centre_y + bound_h / 2),
                   (centre_x + bound_w / 2, centre_y + bound_h / 2))
        for x, y in corners:
            self.canvas.create_rectangle(x - radius, y - radius, x + radius, y + radius,
                                         fill=BG, outline=ACCENT, width=2,
                                         tags="layer-transform")
        rotate = (centre_x, centre_y - bound_h / 2 - 24)
        self.canvas.create_line(centre_x, centre_y - bound_h / 2, rotate[0], rotate[1],
                                fill=ACCENT, width=2, tags="layer-transform")
        self.canvas.create_oval(rotate[0] - radius, rotate[1] - radius,
                                rotate[0] + radius, rotate[1] + radius,
                                fill=BG, outline=ACCENT, width=2, tags="layer-transform")
        self.layer_transform_handles = {"centre": (centre_x, centre_y),
                                        "corners": corners, "rotate": rotate}
        self.canvas.tag_raise("layer-transform")

    def add_adjustment_layer(self):
        name = simpledialog.askstring("Adjustment layer", "Layer name:", initialvalue="Adjustment", parent=self)
        self.document.add_adjustment_layer(name or "Adjustment")
        self.select_new_top_layer()
        self.refresh_history(); self.schedule_render()

    def add_image_layer(self):
        path = filedialog.askopenfilename(title="Add image layer", parent=self,
                                          filetypes=[("Images", "*.jpg *.jpeg *.png *.webp *.tif *.tiff *.bmp"), ("All files", "*.*")])
        if path:
            self.document.add_image_layer(path)
            self.select_new_top_layer()
            self.refresh_history(); self.schedule_render()

    def add_text_layer(self):
        layer = self.document.add_text_layer("Text", "Text")
        self.select_new_top_layer()
        self.edit_text_layer(layer.get("id"), creating=True)

    def edit_text_layer(self, layer_id=None, creating=False):
        layer = (next((item for item in self.document.layers if item.get("id") == layer_id), None)
                 if layer_id else self.current_layer())
        if not layer or layer.get("type") != "text":
            return
        before = copy.deepcopy(layer)
        window = tk.Toplevel(self); window.title("SNAP SLAPPER — Text Layer")
        window.configure(bg=PANEL); window.transient(self); window.grab_set(); window.geometry("520x470")
        tk.Label(window, text="TEXT", bg=PANEL, fg=ACCENT,
                 font=("Segoe UI", 10, "bold")).pack(anchor="w", padx=14, pady=(14, 4))
        text_box = tk.Text(window, height=7, bg=FIELD, fg=INK, insertbackground=INK,
                           relief="flat", wrap="word")
        text_box.pack(fill="both", expand=True, padx=14, pady=(0, 10)); text_box.insert("1.0", layer.get("text", ""))
        font_row = tk.Frame(window, bg=PANEL); font_row.pack(fill="x", padx=14, pady=4)
        font_label = tk.StringVar(value=layer.get("font_family", "Default"))
        installed_fonts = sorted(set(tkfont.families(self)))
        family_picker = ttk.Combobox(font_row, textvariable=font_label,
                                     values=["Default"] + installed_fonts, state="readonly")
        family_picker.pack(side="left", fill="x", expand=True, ipady=4)
        font_path = tk.StringVar(value=layer.get("font_path", ""))
        family_picker.bind("<<ComboboxSelected>>", lambda _event: font_path.set(""))
        def choose_font():
            path = filedialog.askopenfilename(title="Choose a local font", parent=window,
                                              filetypes=[("Font files", "*.ttf *.otf"), ("All files", "*.*")])
            if path:
                font_path.set(path); font_label.set(os.path.splitext(os.path.basename(path))[0])
        self._button(font_row, "CHOOSE FONT…", choose_font).pack(side="right", padx=(6, 0), ipady=4)
        values = tk.Frame(window, bg=PANEL); values.pack(fill="x", padx=14, pady=6)
        size = tk.IntVar(value=int(layer.get("font_size", 72)))
        spacing = tk.IntVar(value=int(layer.get("line_spacing", 4)))
        character_spacing = tk.IntVar(value=int(layer.get("character_spacing", 0)))
        text_box_width = tk.IntVar(value=int(layer.get("text_box_width", 0)))
        stroke = tk.IntVar(value=int(layer.get("stroke_width", 0)))
        align = tk.StringVar(value=layer.get("align", "left"))
        for column, (label, variable) in enumerate((("SIZE", size), ("LINE SPACE", spacing),
                                                    ("LETTER SPACE", character_spacing),
                                                    ("STROKE", stroke))):
            cell = tk.Frame(values, bg=PANEL); cell.grid(row=0, column=column, sticky="ew", padx=(0, 6))
            values.grid_columnconfigure(column, weight=1)
            tk.Label(cell, text=label, bg=PANEL, fg=DIM).pack(anchor="w")
            tk.Entry(cell, textvariable=variable, bg=FIELD, fg=INK, insertbackground=INK,
                     relief="flat").pack(fill="x", ipady=5)
        align_menu = tk.OptionMenu(values, align, "left", "center", "right")
        align_menu.configure(bg=FIELD, fg=INK, relief="flat", highlightthickness=0)
        align_menu.grid(row=1, column=0, columnspan=4, sticky="ew", pady=(6, 0))
        width_row = tk.Frame(window, bg=PANEL); width_row.pack(fill="x", padx=14, pady=4)
        tk.Label(width_row, text="TEXT BOX WIDTH (0 = NO WRAP)", bg=PANEL, fg=DIM).pack(side="left")
        tk.Entry(width_row, textvariable=text_box_width, bg=FIELD, fg=INK,
                 insertbackground=INK, relief="flat", width=10).pack(side="right", ipady=4)
        fill = list(layer.get("fill", [255, 255, 255, 255])); stroke_fill = list(layer.get("stroke_fill", [0, 0, 0, 255]))
        background_fill = list(layer.get("background_fill", [0, 0, 0, 180]))
        colours = tk.Frame(window, bg=PANEL); colours.pack(fill="x", padx=14, pady=6)
        def choose_colour(target, button):
            chosen = colorchooser.askcolor(tuple(target[:3]), parent=window)[0]
            if chosen:
                target[:3] = [int(value) for value in chosen]
                button.configure(bg="#%02x%02x%02x" % tuple(target[:3]))
        fill_button = self._button(colours, "TEXT COLOUR", lambda: choose_colour(fill, fill_button))
        fill_button.configure(bg="#%02x%02x%02x" % tuple(fill[:3])); fill_button.pack(side="left", fill="x", expand=True)
        stroke_button = self._button(colours, "STROKE COLOUR", lambda: choose_colour(stroke_fill, stroke_button))
        stroke_button.configure(bg="#%02x%02x%02x" % tuple(stroke_fill[:3])); stroke_button.pack(side="left", fill="x", expand=True, padx=(6, 0))
        options = tk.Frame(window, bg=PANEL); options.pack(fill="x", padx=14, pady=6)
        background = tk.BooleanVar(value=bool(layer.get("background")))
        shadow = tk.BooleanVar(value=bool(layer.get("styles", {}).get("shadow")))
        tk.Checkbutton(options, text="BACKGROUND", variable=background, bg=PANEL, fg=INK,
                       selectcolor=FIELD, activebackground=PANEL).pack(side="left")
        background_button = self._button(
            options, "BACKGROUND COLOUR", lambda: choose_colour(background_fill, background_button))
        background_button.configure(bg="#%02x%02x%02x" % tuple(background_fill[:3]))
        background_button.pack(side="left", padx=6)
        tk.Checkbutton(options, text="SHADOW", variable=shadow, bg=PANEL, fg=INK,
                       selectcolor=FIELD, activebackground=PANEL).pack(side="left")
        buttons = tk.Frame(window, bg=PANEL); buttons.pack(fill="x", padx=14, pady=14)
        def cancel():
            if creating:
                self.document.layers = [item for item in self.document.layers if item is not layer]
                self.document.record("Cancel text layer")
            window.destroy(); self.refresh_layers(); self.schedule_render()
        def apply():
            layer.update(text=text_box.get("1.0", "end-1c"), font_path=font_path.get(),
                         font_family=font_label.get(), font_size=max(1, min(2000, size.get())),
                         line_spacing=max(0, spacing.get()), stroke_width=max(0, stroke.get()),
                         character_spacing=character_spacing.get(), align=align.get(),
                         text_box_width=max(0, text_box_width.get()),
                         fill=fill, stroke_fill=stroke_fill, background=background.get(),
                         background_fill=background_fill)
            layer.setdefault("styles", {})["shadow"] = shadow.get()
            if before != layer:
                self.document.record("Edit text layer")
            window.destroy(); self.refresh_layers(); self.refresh_history(); self.schedule_render()
        self._button(buttons, "CANCEL", cancel).pack(side="left", fill="x", expand=True)
        self._button(buttons, "APPLY TEXT", apply, True).pack(side="right", fill="x", expand=True, padx=(8, 0))
        window.protocol("WM_DELETE_WINDOW", cancel)

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

    def commit_layer_setting(self, label="Layer settings"):
        self.layer_setting_changed()
        if self.current_layer():
            self.document.record(label)
            self.refresh_history()

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
        self.refresh_layers(); self.refresh_history(); self.schedule_render()

    def invert_mask(self):
        layer = self.current_layer()
        if not layer or not layer.get("mask"):
            return
        import base64, io
        mask = Image.open(io.BytesIO(base64.b64decode(layer["mask"]))).convert("L")
        stream = io.BytesIO(); ImageOps.invert(mask).save(stream, "PNG")
        layer["mask"] = base64.b64encode(stream.getvalue()).decode("ascii")
        self.document.record("Invert mask")
        self.refresh_layers(); self.refresh_history(); self.schedule_render()

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

    def toggle_mask_overlay(self):
        layer = self.current_layer()
        if not layer or not layer.get("mask"):
            self.mask_overlay_var.set(False)
            messagebox.showinfo("Show mask", "Select a layer with a mask first.", parent=self)
            return
        self.mask_overlay_var.set(not self.mask_overlay_var.get())
        self.status_label.configure(text="MASK OVERLAY — red areas are hidden" if self.mask_overlay_var.get()
                                    else "Mask overlay hidden")
        self.schedule_render()

    def view_mask_grayscale(self):
        layer = self.current_layer()
        if not layer or not layer.get("mask"):
            messagebox.showinfo("View mask", "Select a layer with a mask first.", parent=self)
            return
        self.mask_grayscale_var.set(not self.mask_grayscale_var.get())
        if self.mask_grayscale_var.get():
            self.mask_overlay_var.set(False)
        self.status_label.configure(text="VIEWING MASK — white reveals, black hides"
                                    if self.mask_grayscale_var.get() else "Mask view hidden")
        self.schedule_render()

    def toggle_mask_enabled(self):
        layer = self.current_layer()
        if not layer or not layer.get("mask"):
            return
        layer["mask_enabled"] = not layer.get("mask_enabled", True)
        self.document.record("Enable layer mask" if layer["mask_enabled"] else "Disable layer mask")
        self.refresh_layers(); self.refresh_history(); self.schedule_render()

    def delete_mask(self):
        layer = self.current_layer()
        if not layer or not layer.get("mask"):
            return
        if not messagebox.askyesno("Delete mask", "Delete this layer mask? The layer will remain.", parent=self):
            return
        layer["mask"] = ""
        layer["mask_enabled"] = True
        self.mask_overlay_var.set(False); self.mask_grayscale_var.set(False)
        self.document.record("Delete layer mask")
        self.refresh_layers(); self.refresh_history(); self.schedule_render()

    @staticmethod
    def _encoded_mask(mask):
        import base64, io
        stream = io.BytesIO()
        mask.convert("L").save(stream, "PNG")
        return base64.b64encode(stream.getvalue()).decode("ascii")

    def store_selection_mask(self, layer, proposed, label):
        import base64, io
        if self.mask_reverse.get():
            proposed = ImageOps.invert(proposed)
        current = None
        if layer.get("mask") and self.mask_combine_mode.get() != "replace":
            current = Image.open(io.BytesIO(base64.b64decode(layer["mask"]))).convert("L")
            if current.size != proposed.size:
                current = current.resize(proposed.size, Image.Resampling.LANCZOS)
        mode = self.mask_combine_mode.get()
        if current is not None and mode == "add":
            proposed = ImageChops.lighter(current, proposed)
        elif current is not None and mode == "subtract":
            proposed = ImageChops.subtract(current, proposed)
        elif current is not None and mode == "intersect":
            proposed = ImageChops.darker(current, proposed)
        layer["mask"] = self._encoded_mask(proposed)
        layer["mask_enabled"] = True
        self.document.record(label)
        self.refresh_layers(); self.refresh_history(); self.mask_overlay_var.set(True)
        self.schedule_render()

    def apply_gradient_mask(self, x0, y0, x1, y1):
        layer = self.current_layer()
        first = self.canvas_to_normalized(x0, y0)
        second = self.canvas_to_normalized(x1, y1)
        if not layer or not first or not second:
            return
        dx, dy = second[0] - first[0], second[1] - first[1]
        length2 = dx * dx + dy * dy
        if length2 < .0001:
            return
        with Image.open(self.document.source_path) as source:
            width, height = source.size
        scale = min(1.0, 1024.0 / max(width, height))
        mw, mh = max(2, int(width * scale)), max(2, int(height * scale))
        mask = Image.new("L", (mw, mh), 0)
        pixels = mask.load()
        for py in range(mh):
            ny = py / max(1, mh - 1)
            for px in range(mw):
                nx = px / max(1, mw - 1)
                amount = ((nx - first[0]) * dx + (ny - first[1]) * dy) / length2
                pixels[px, py] = max(0, min(255, int(amount * 255)))
        if mask.size != (width, height):
            mask = mask.resize((width, height), Image.Resampling.BICUBIC)
        feather = max(0.0, min(.49, self.mask_feather.get() / 200.0))
        if feather:
            mask = mask.point(lambda value: int(max(0, min(255,
                (value / 255.0 - feather) / max(.01, 1 - feather * 2) * 255))))
        self.store_selection_mask(layer, mask, "Directional gradient mask")

    def apply_radial_mask(self, x0, y0, x1, y1):
        layer = self.current_layer()
        centre = self.canvas_to_normalized(x0, y0)
        edge = self.canvas_to_normalized(x1, y1)
        if not layer or not centre or not edge:
            return
        radius = math.dist(centre, edge)
        if radius < .005:
            return
        with Image.open(self.document.source_path) as source:
            width, height = ImageOps.exif_transpose(source).size
        scale = min(1.0, 1024.0 / max(width, height))
        mw, mh = max(2, int(width * scale)), max(2, int(height * scale))
        mask = Image.new("L", (mw, mh), 0); pixels = mask.load()
        aspect = width / max(1, height)
        for py in range(mh):
            ny = py / max(1, mh - 1)
            for px in range(mw):
                nx = px / max(1, mw - 1)
                distance = math.sqrt(((nx - centre[0]) * aspect) ** 2 +
                                     (ny - centre[1]) ** 2)
                amount = max(0.0, min(1.0, 1.0 - distance / radius))
                pixels[px, py] = int(amount * 255)
        if mask.size != (width, height):
            mask = mask.resize((width, height), Image.Resampling.BICUBIC)
        blur = int(min(width, height) * self.mask_feather.get() / 1000)
        if blur > 0:
            mask = mask.filter(ImageFilter.GaussianBlur(blur))
        self.store_selection_mask(layer, mask, "Radial gradient mask")

    def create_color_range_mask(self, canvas_x, canvas_y):
        layer = self.current_layer()
        point = self.canvas_to_normalized(canvas_x, canvas_y)
        if not layer or not point:
            return
        with Image.open(self.document.source_path) as source:
            image = ImageOps.exif_transpose(source).convert("RGB")
        sx = max(0, min(image.width - 1, int(point[0] * image.width)))
        sy = max(0, min(image.height - 1, int(point[1] * image.height)))
        sample = image.getpixel((sx, sy))
        solid = Image.new("RGB", image.size, sample)
        red, green, blue = ImageChops.difference(image, solid).split()
        distance = ImageChops.lighter(ImageChops.lighter(red, green), blue)
        tolerance = self.color_range_tolerance.get()
        feather = max(1, self.mask_feather.get())
        mask = distance.point(lambda value: max(0, min(255,
            int((tolerance + feather - value) * 255 / feather))))
        self.store_selection_mask(layer, mask, "Colour range mask")
        self.status_label.configure(text=f"COLOUR RANGE — sampled RGB {sample}; tolerance {tolerance}")

    def draw_outline_preview(self):
        self.canvas.delete("mask-outline")
        if not self.outline_points:
            return
        left, top, right, bottom = self.display_box
        points = []
        for x, y in self.outline_points:
            points.extend((left + x * (right - left), top + y * (bottom - top)))
        if len(points) >= 4:
            self.canvas.create_line(*points, fill=ACCENT, width=2,
                                    tags="mask-outline")

    def finish_outline_mask(self):
        layer = self.current_layer()
        if not layer or len(self.outline_points) < 3:
            return
        with Image.open(self.document.source_path) as source:
            size = source.size
        points = [(int(x * size[0]), int(y * size[1])) for x, y in self.outline_points]
        mask = Image.new("L", size, 0)
        ImageDraw.Draw(mask).polygon(points, fill=255)
        feather = int(min(size) * self.mask_feather.get() / 1000)
        if feather > 0:
            mask = mask.filter(ImageFilter.GaussianBlur(feather))
        self.store_selection_mask(layer, mask, "Outline mask")
        self.outline_points = []
        self.canvas.delete("mask-outline")

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
        diameter = radius * 2 + 1
        dab = Image.new("L", (diameter, diameter), 0)
        ImageDraw.Draw(dab).ellipse((0, 0, diameter - 1, diameter - 1), fill=255)
        hardness = max(.01, min(1.0, self.mask_brush_hardness.get() / 100.0))
        blur = radius * (1.0 - hardness)
        if blur > .5:
            dab = dab.filter(ImageFilter.GaussianBlur(blur))
        opacity = max(0.01, min(1.0, self.mask_brush_opacity.get() / 100.0))
        opacity *= max(0.01, min(1.0, self.mask_brush_flow.get() / 100.0))
        dab = dab.point(lambda value: int(value * opacity))
        target = Image.new("L", mask.size, self.mask_brush_value.get())
        brush_mask = Image.new("L", mask.size, 0)
        brush_mask.paste(dab, (x - radius, y - radius))
        mask = Image.composite(target, mask, brush_mask)
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
            try:
                editor_engine.save_recipe(path, self.document.recipe())
                self.status_label.configure(text=f"Preset saved: {path}")
            except Exception as exc:
                messagebox.showerror("Preset could not be saved", str(exc), parent=self)

    def load_preset(self):
        path = filedialog.askopenfilename(title="Load preset", parent=self,
                                          filetypes=[("SNAP SLAPPER recipe", "*.slaprecipe"), ("All files", "*.*")])
        if path:
            try:
                self.document.apply_recipe(editor_engine.load_recipe(path))
                self._load_document_controls()
            except Exception as exc:
                messagebox.showerror("Preset could not be opened", str(exc), parent=self)

    def batch_apply(self):
        paths = [row["path"] for row in self.batch_rows if os.path.isfile(row.get("path", ""))]
        if not paths:
            return
        destination = filedialog.askdirectory(title="Batch export folder", parent=self)
        if destination and messagebox.askyesno("Batch apply", f"Create edited JPEG copies for {len(paths):,} visible photo(s)?", parent=self):
            try:
                outputs = editor_engine.batch_apply(paths, self.document.recipe(), destination,
                                                    copyright_text=self.copyright_text(),
                                                    strip_gps=self.strip_gps())
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
        self.update_document_title()

    def update_document_title(self):
        dirty = "  •  UNSAVED" if self.document.is_dirty() else ""
        self.title_label.configure(text=os.path.basename(self.document.source_path) + dirty)

    def close_editor(self):
        dirty = [document for document in self.documents.values() if document.is_dirty()]
        if dirty and not messagebox.askyesno(
                "Discard unsaved edits?",
                f"{len(dirty):,} photo(s) have unsaved non-destructive edits.\n\n"
                "Choose No, then save each editable project. Choose Yes only to discard them.",
                parent=self, icon="warning"):
            return
        for document in self.documents.values():
            self._discard_recovery(document)
        self.destroy()

    def restore_history(self, _event=None):
        selection = self.history_list.curselection()
        if not selection:
            return
        index = selection[0]
        if not 0 <= index < len(self.document.history):
            return
        self.document.history_index = index
        self.document.restore(self.document.history[index]["state"])
        self.document.notify_change()
        self._load_document_controls()

    def save_project(self):
        path = self.document.project_path or filedialog.asksaveasfilename(
            title="Save SNAP SLAPPER project", parent=self, defaultextension=".slapper",
            initialdir=self.projects_dir() or None,
            filetypes=[("SNAP SLAPPER project", "*.slapper")])
        if path:
            try:
                self.document.save_project(path)
                self._discard_recovery(self.document)
                self.update_document_title()
                self.status_label.configure(text=f"Saved {path}")
            except Exception as exc:
                messagebox.showerror("Project could not be saved", str(exc), parent=self)

    def open_project(self):
        path = filedialog.askopenfilename(title="Open SNAP SLAPPER project", parent=self,
                                          filetypes=[("SNAP SLAPPER project", "*.slapper"),
                                                     ("All files", "*.*")])
        if not path:
            return
        try:
            self.document = editor_engine.EditorDocument.load_project(path)
            self._attach_recovery(self.document)
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
                                            initialdir=self.saved_images_dir() or None,
                                            initialfile=os.path.splitext(os.path.basename(source))[0] + "_edited.jpg",
                                            defaultextension=".jpg",
                                            filetypes=[("JPEG", "*.jpg"), ("PNG", "*.png"), ("TIFF", "*.tif")])
        if path:
            try:
                self.document.export(path, copyright_text=self.copyright_text(),
                                     strip_gps=self.strip_gps())
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
            self.documents[key] = self._recover_or_create_document(key)
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
