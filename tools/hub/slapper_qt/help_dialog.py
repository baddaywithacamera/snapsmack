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
    ("Black & white colour mixer",
     "Turn on Convert to black and white, then use the eight colour sliders "
     "(Red…Magenta) to control how each colour becomes grey — brighten a blue "
     "sky or darken foliage independently. With every slider at zero you get a "
     "plain neutral black-and-white conversion."),
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
     "The LEWKS button opens a gallery of built-in looks, each previewed on your "
     "own photograph at an adjustable Strength. Applying a LEWK adds it as a "
     "non-destructive layer on top of your edits — it never flattens the work "
     "you've already done. Lower the layer's opacity later to ease it back."),
    ("Found Textures",
     "The Textures button searches foundtextures.ca and adds a texture as a "
     "layer. Choose how it fits (cover, contain, stretch, tile, original) and a "
     "blend mode (Overlay suits most textures). Imported layers remember the "
     "texture's id, source, and date. The site connection uses the key stored in "
     "The Hub."),
    ("Crop, geometry, and retouch",
     "Crop shows the full frame — drag a rectangle to crop; toggle Crop off "
     "without drawing to cancel. GEOMETRY rotates/straightens and flips. RETOUCH "
     "has Heal and Red-Eye: turn one on and click blemishes; adjust Spot size or "
     "Clear all."),
    ("Projects, recipes, and export",
     "Save Project writes a .slapper file with all your editing steps (the "
     "original is referenced, not copied inside). Save Recipe / Apply Recipe "
     "reuse a set of adjustments across photos. Export writes the finished image "
     "to a new file with metadata preserved."),
    ("Keyboard",
     "Ctrl+O open a photo · Ctrl+S export · Ctrl+Z undo · Ctrl+Y redo · "
     "F1 open this help. The title bar shows a ● when there are unsaved edits."),
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
