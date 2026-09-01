"""Bundled, searchable offline help for the Qt SNAP SLAPPER.

Self-contained (no network); F1 opens it. Topics describe the Qt app's actual
features. Framework-agnostic topic text so it can be shared later if useful.
"""

from PySide6.QtCore import Qt
from PySide6.QtWidgets import (
    QDialog, QHBoxLayout, QVBoxLayout, QLineEdit, QListWidget, QListWidgetItem,
    QTextEdit, QLabel,
)

from . import theme

TOPICS = [
    ("Quick start",
     "Choose Folder in the Library, then double-click a photograph to open the "
     "editor. Every adjustment is non-destructive — your original file is never "
     "changed. Save a .slapper project to keep the editing steps, and Export to "
     "write a new image file."),
    ("Your originals are safe",
     "SNAP SLAPPER never overwrites the photograph you opened. Editing, cropping, "
     "rotating, applying LEWKS or textures, and exporting all create new results "
     "in memory or new files. Export preserves EXIF, ICC profile, and existing "
     "copyright; you choose where the new file goes."),
    ("Adjustments",
     "The right rail holds Light, Colour, Presence, Effects, Levels, Geometry, "
     "Retouch, and Black & White. Drag a slider to change the photo live; "
     "double-click a slider to reset just that control; use Reset to clear them "
     "all. A live Luma/RGB histogram sits at the top, and Before/After shows the "
     "untouched original."),
    ("White balance",
     "Open COLOUR and click Pick Neutral Colour, then click a grey card or a "
     "neutral grey/white object in the photograph. SNAP SLAPPER samples a small "
     "patch and corrects both Temperature and Tint without changing the original "
     "JPEG. Choose an area with visible detail; blown white and crushed black "
     "cannot provide reliable colour and will be rejected."),
    ("PANOMERGE",
     "In the Library, select two or more overlapping photographs in shooting "
     "order and choose PANOMERGE. Reorder or add photos in the PANOMERGE window, "
     "choose an output file, then merge. The separately installed XPANO engine "
     "performs automatic alignment, stitching, and blending without changing "
     "the originals; the completed panorama opens directly in the editor. "
     "PANOMERGE supports Windows and Linux only."),
    ("Black & white colour mixer",
     "Turn on Convert to black and white, then use the eight colour sliders "
     "(Red…Magenta) to control how each colour becomes grey — brighten a blue "
     "sky or darken foliage independently. With every slider at zero you get a "
     "plain neutral black-and-white conversion."),
    ("Sharpening",
     "The Sharpen slider (PRESENCE) crisps edges with an unsharp mask. Under it, "
     "Sharpen detail adds Smart-Sharpen-style controls: Radius sets the edge "
     "width (small = fine detail), Reduce noise keeps flat areas like sky and "
     "skin from being sharpened, and the edge model chooses Lens Blur (confines "
     "sharpening to real edges — finer detail with far fewer haloes, like "
     "Photoshop's Smart Sharpen) or Gaussian (the plain unsharp mask). Amount 0 "
     "means no sharpening. Sharpen last, after your other edits, and mask the "
     "layer to sharpen just the subject."),
    ("Photo Filter",
     "In Advanced mode the PHOTO FILTER panel lays a coloured 'gel' over the "
     "photo, like the classic camera filters. Pick a preset — the warming and "
     "cooling filters (85, LBA, 81, 80, LBB, 82), the colour filters (Red, "
     "Orange, Yellow, Green, Cyan, Blue, Violet, Magenta), Sepia, the deep filters "
     "(Deep Red/Blue/Emerald/Yellow), Underwater, or a few faux-infrared washes — "
     "or click Filter colour to set your own. Density sets the strength. Keep "
     "'Preserve brightness' on to change only the colour, not the exposure (the "
     "standard photo-filter behaviour). Because the filter lives on a layer, you "
     "can add a mask and paint it onto just the sky or a face. Note: the faux-"
     "infrared entries give the infrared colour cast, not the full white-foliage "
     "conversion."),
    ("Tone curve, split tone, colour mix, and glow",
     "Advanced mode adds deeper colour control. TONE CURVE bends brightness with "
     "a master curve plus independent Red / Green / Blue curves — where colour "
     "casts and cross-process looks come from. SPLIT TONE colours the shadows, "
     "midtones, and highlights separately (the teal-and-orange film look). COLOUR "
     "MIX sets saturation and luminance per hue across eight bands. GLOW places a "
     "soft coloured bloom — a centre spotlight or a corner light leak. Each is "
     "non-destructive and works on the base photo or on any adjustment layer."),
    ("Layers",
     "Add adjustment, image, or text layers. Click a layer to make it the edit "
     "target — the sliders then edit that layer, never the base photo. Set each "
     "layer's visibility, opacity, and blend mode, reorder or delete it. Text "
     "layers have their own content/size/colour panel."),
    ("Masks",
     "With a layer selected, open MASK to limit that layer to part of the photo: "
     "a Radial mask (centre, size, softness) or a Graduated mask (direction, "
     "line, softness), with Invert and Clear. This is how you make a local "
     "adjustment — darken only a sky, brighten only a face."),
    ("LEWKS",
     "The LEWKS button (Ctrl+K) opens a gallery of built-in looks, each previewed "
     "on your own photograph at an adjustable Strength. Applying a LEWK adds it as "
     "a non-destructive layer on top of your edits — it never flattens the work "
     "you've already done; lower the layer's opacity later to ease it back.\n\n"
     "Looks are grouped: Clean + Corrective, Landscape + Weather, Night + Neon, "
     "Film + Print, Black + White, Portrait, and Experimental. Every LEWK is a "
     "glass-box recipe built from the same controls you use by hand — tone curves, "
     "colour mix, split tone, photo filter, glow, grain — not a baked colour "
     "table. Select a LEWK and choose TEACH ME to see its real instructions in "
     "order, read the result in ordinary photographer language, use SHOW SETTINGS "
     "only when you want the exact values, switch individual lessons on and "
     "off, hold BEFORE THIS STEP for a direct comparison, and MAKE EDITABLE COPY "
     "to experiment with the actual controls. All names are original to SNAP "
     "SLAPPER."),
    ("Found Textures",
     "The Textures button searches foundtextures.ca and adds a texture as a "
     "layer. Choose how it fits (cover, contain, stretch, tile, original) and a "
     "blend mode (Overlay suits most textures). The browser defaults to CLEAR "
     "RIGHTS textures. UNCLEAR RIGHTS and RIGHTS UNKNOWN remain available but "
     "are visibly marked and require confirmation before import. Imported "
     "layers remember the texture's id, source, rights status, licence, and "
     "date. Texture files live once in the shared asset library; LEWKS and "
     ".slapper projects store recoverable references, not duplicate image bytes. "
     "If a FOUND TEXTURES asset is missing, SNAP SLAPPER asks before downloading "
     "the stored high-resolution link. It never silently restores an asset, and "
     "it cannot automatically restore a missing third-party texture. The site "
     "connection uses the key stored in THE HUB."),
    ("Blur tools",
     "FILTERS includes three editable, non-destructive blur layers. Gaussian "
     "Blur gives an even softening with adjustable Radius. Motion Blur uses "
     "Length and Angle to drag detail in a straight direction. Radial Blur can "
     "Spin around a movable centre or Zoom outward from it; Strength controls "
     "the movement. Amount blends any blur back toward the original. Because "
     "these are filter layers, you can change them later, lower opacity, change "
     "blend mode, mask the effect to part of the photograph, save it in a "
     ".slapper project or recipe, and use it in batch processing."),
    ("Watermarks and transparency",
     "Add Image Layer accepts transparent PNG files and SVG watermarks. PNG alpha "
     "is preserved. SVG remains a referenced vector file and is rendered sharply "
     "at the photograph's preview or export size, so a small logo does not become "
     "a permanently blurry bitmap. Use the image layer's transform, opacity, blend "
     "mode, and mask controls to place it. PNG, TIFF, and PSD can preserve transparent "
     "output; JPEG is always flattened because JPEG has no transparency."),
    ("Crop, geometry, and retouch",
     "Crop shows the full frame — drag a rectangle to crop; toggle Crop off "
     "without drawing to cancel. GEOMETRY rotates/straightens and flips. Its "
     "Vertical and Horizontal perspective sliders correct converging lines. "
     "Free Corners displays a 3×3 grid: drag any red corner to fan or narrow "
     "the image while straight lines remain straight. Auto Crop removes empty "
     "edges; Transparent Edges preserves the full canvas for PNG, TIFF, or PSD. "
     "Perspective is saved in projects and recipes. RETOUCH "
     "has Heal and Red-Eye: turn one on and click blemishes; adjust Spot size or "
     "Clear all."),
    ("Projects, recipes, and export",
     "Save Project writes a .slapper file with all your editing steps. External "
     "textures are recorded by name, source, rights status, and restore link; "
     "their image bytes remain in the shared asset library. Save Recipe / "
     "Apply Recipe reuse a set of adjustments across photos. JPEG, PNG, and TIFF "
     "write flattened finished copies. Layered PSD writes a guaranteed visible "
     "full-resolution composite plus named raster checkpoints for the base and "
     "every SNAP SLAPPER layer. Custom filters and adjustments are checkpoints, "
     "not falsely labelled as native Photoshop adjustments. The original remains "
     "unchanged."),
    ("Keyboard",
     "Standard: Ctrl+O open · Ctrl+S export · Ctrl+Z undo · Ctrl+Y redo · "
     "F1 this help.\n\n"
     "Tools & view: Ctrl+U auto-enhance · Ctrl+0 fit to window · Ctrl+1 100% "
     "(actual pixels) · Ctrl+\\ before/after · Ctrl+Shift+C crop · "
     "Ctrl+Shift+H heal · Ctrl+Shift+E red-eye · Ctrl+Shift+F filmstrip · "
     "Ctrl+Shift+R reset all.\n\n"
     "Panels & modes: Ctrl+K LEWKS · Ctrl+T textures · Ctrl+Shift+S save "
     "project · Ctrl+Shift+A switch Normal / Advanced.\n\n"
     "Every shortcut uses a modifier key, so none of them fire while you're "
     "typing in a text layer. The title bar shows a ● when there are unsaved "
     "edits, and each toolbar button's tooltip shows its shortcut."),
    ("If something goes wrong",
     "SNAP SLAPPER writes a log of each run to C:\\snapsmack\\logs\\ "
     "(snap_slapper_run_<date>.log). If the app misbehaves or an error appears, "
     "that file records what happened — keep it to report the problem."),
]


class HelpDialog(QDialog):
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("SNAP SLAPPER — Help")
        self.resize(760, 560)
        self.setStyleSheet(theme.stylesheet())

        root = QVBoxLayout(self)
        root.setContentsMargins(12, 12, 12, 12)
        root.setSpacing(8)

        self.search = QLineEdit()
        self.search.setPlaceholderText("Search help…")
        self.search.textChanged.connect(self._filter)
        root.addWidget(self.search)

        body = QHBoxLayout()
        body.setSpacing(10)
        self.list = QListWidget()
        self.list.setFixedWidth(220)
        self.list.currentRowChanged.connect(self._show_current)
        body.addWidget(self.list)

        self.body = QTextEdit()
        self.body.setReadOnly(True)
        body.addWidget(self.body, 1)
        root.addLayout(body, 1)

        for title, _text in TOPICS:
            self.list.addItem(QListWidgetItem(title))
        if TOPICS:
            self.list.setCurrentRow(0)

    def _show_current(self, row):
        if 0 <= row < len(self._visible_topics()):
            title, text = self._visible_topics()[row]
            self.body.setPlainText(f"{title}\n\n{text}")

    def _visible_topics(self):
        query = self.search.text().strip().lower()
        if not query:
            return TOPICS
        return [(t, x) for t, x in TOPICS if query in t.lower() or query in x.lower()]

    def _filter(self):
        visible = self._visible_topics()
        self.list.blockSignals(True)
        self.list.clear()
        for title, _text in visible:
            self.list.addItem(QListWidgetItem(title))
        self.list.blockSignals(False)
        if visible:
            self.list.setCurrentRow(0)
        else:
            self.body.setPlainText("No help topics match that search.")

# ===== SNAPSMACK EOF =====
