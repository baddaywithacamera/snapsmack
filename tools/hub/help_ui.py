"""Bundled, searchable offline help for SNAP SLAPPER.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import tkinter as tk


TOPICS = [
    ("Quick start", "Open a local folder, select a photograph, and press Enter or double-click "
     "to open the editor. Adjustments remain non-destructive. Save a .slapper project to "
     "keep the editing instructions, then Export to create a new image file."),
    ("Original-file safety", "Editing, rotating, exporting, and opening a photograph in an "
     "external editor create new copies. SNAP SLAPPER does not overwrite an original as an "
     "editing side effect. Move and Trash are separate organizer commands and always say what "
     "will happen before they run."),
    ("Library and selection", "Open one or more folders from the left rail. Search by name or "
     "details, filter by date, rating, favorites, or tags, and use Ctrl or Shift to select "
     "multiple photographs. Ratings, tags, favorites, and albums live in SNAP SLAPPER's local "
     "organizer data; they are not written into the originals."),
    ("Editor", "Use the right rail for light, colour, detail, effects, geometry, layers, masks, "
     "and history. Undo and Redo restore document states. Compare cycles through edited, split, "
     "side-by-side, and original views. Export writes the visible composite to a new file."),
    ("Projects and presets", "A .slapper project stores the source reference and editable "
     "document state. Save before closing if the title shows unsaved work. Presets store reusable "
     "adjustments. A missing original or image layer is reported explicitly instead of silently "
     "changing the result."),
    ("Export and metadata", "Exports use collision-safe names and preserve available EXIF, ICC "
     "profile, DPI, and existing copyright metadata. The copyright preference adds a value only "
     "when the source field is empty. Remove GPS from exported copies is optional and never "
     "changes the GPS stored in the original."),
    ("RAW and unsupported files", "SNAP SLAPPER is not a RAW processor. Open recognized RAW "
     "formats with RawTherapee or darktable from the offline handoff window. The original is "
     "passed to that program untouched. Files SNAP SLAPPER cannot decode receive the same clear "
     "handoff instead of opening a broken editor."),
    ("Trash and recovery", "Trash is recoverable and records the original and trash locations "
     "in a local manifest. If that manifest cannot be saved, moved photographs are returned to "
     "their original locations. Restore last trashed photo recovers the newest valid entry."),
    ("Keyboard reference", "Library: Enter opens the selected photograph. Editor: Ctrl+S saves "
     "the project, Ctrl+Z undoes, Ctrl+Y redoes, F1 opens this help, and Escape closes transient "
     "windows. Viewer navigation uses the arrow keys where shown."),
]


class HelpWindow(tk.Toplevel):
    def __init__(self, parent, initial_topic=None, version=None):
        super().__init__(parent)
        self.title("SNAP SLAPPER — Offline Help")
        self.configure(bg="#0a0a0a")
        self.geometry("780x600")
        self.minsize(600, 420)
        if version:
            tk.Label(self, text=f"OFFLINE HELP  ·  BUILD {version}", bg="#0a0a0a",
                     fg="#39ff14", font=("Segoe UI", 9, "bold")).pack(
                         anchor="w", padx=16, pady=(14, 0))
        self.search = tk.StringVar()
        search = tk.Entry(self, textvariable=self.search, bg="#1c1c1c", fg="#e6e6e6",
                          insertbackground="#e6e6e6", relief="flat", font=("Segoe UI", 11))
        search.pack(fill="x", padx=16, pady=16, ipady=7)
        self.text = tk.Text(self, bg="#101010", fg="#e6e6e6", insertbackground="#e6e6e6",
                            relief="flat", wrap="word", padx=20, pady=16,
                            font=("Segoe UI", 10), spacing1=2, spacing3=9)
        self.text.pack(fill="both", expand=True, padx=16, pady=(0, 16))
        self.text.tag_configure("heading", foreground="#39ff14",
                                font=("Segoe UI", 13, "bold"), spacing1=12, spacing3=5)
        self.search.trace_add("write", lambda *_: self.render())
        self.bind("<Escape>", lambda _event: self.destroy())
        self.bind("<Control-f>", lambda _event: search.focus_set())
        self.render(initial_topic or "")
        search.focus_set()

    def render(self, preferred=""):
        query = self.search.get().strip().lower()
        matches = [(title, body) for title, body in TOPICS
                   if not query or query in title.lower() or query in body.lower()]
        self.text.configure(state="normal")
        self.text.delete("1.0", "end")
        if not matches:
            self.text.insert("end", "No help topics match that search.")
        for title, body in matches:
            self.text.insert("end", title + "\n", "heading")
            self.text.insert("end", body + "\n")
        self.text.configure(state="disabled")
        if preferred:
            position = self.text.search(preferred, "1.0", nocase=True, stopindex="end")
            if position:
                self.text.see(position)


def open_help(parent, topic=None, version=None):
    existing = getattr(parent, "_snap_help_window", None)
    if existing and existing.winfo_exists():
        existing.deiconify()
        existing.lift()
        return existing
    window = HelpWindow(parent, topic, version)
    parent._snap_help_window = window
    return window

# ===== SNAPSMACK EOF =====
