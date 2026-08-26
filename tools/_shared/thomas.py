"""Thomas the Bear — the SNAP SLAPPER Easter egg. For Noah.

Ctrl+Shift+Y summons Thomas, exactly as he worked in Picasa; press it again and
he multiplies across the screen in varying sizes. Ctrl+Shift+Z tells the story
of Noah Grey and Thomas. X, Escape, or clicking a bear clears them.

Thomas is a teddy bear. He first appeared as a hidden Easter egg in Picasa,
Google's photo software, hidden there in appreciation for Noah Grey —
photoblogging pioneer, creator of Greymatter, and one of the people who built
what the web was supposed to be. When Picasa ended, Thomas disappeared. He lives
here now. He is not going anywhere. See licenses/THE-THOMAS-CLAUSE.txt — carrying
Thomas is a licence obligation, and the right thing to do.

Pure tkinter/Pillow, no network, no state written anywhere. Faithful to the
web engine in assets/js/ss-engine-thomas.js.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import os
import random
import sys
import tkinter as tk

from PIL import Image, ImageTk


_IMAGE_NAME = "thomas-transparent.png"
_TRANSPARENT = "#ff00fe"  # a colour Thomas never contains; keyed out where supported

# The story shown by Ctrl+Shift+Z. Carries the Thomas Clause attribution: Noah
# Grey by name, Greymatter, Picasa, and "he lives here now".
STORY = (
    "In November 2000, Noah Grey released Greymatter — the original open-source "
    "blogging software, written in Perl, needing nothing but a webserver. He "
    "pioneered personal blogging and photoblogging before either word was "
    "common. To many people in the early days of the web, Noah Grey was "
    "photography on the web.\n\n"
    "In 2004 he worked with the Google Picasa team, helping shape what photo "
    "software could feel like in the hands of someone who cared about "
    "photographs. The Picasa developers hid Thomas — a small stuffed bear an old "
    "friend had given Noah, his companion for over fifteen years — inside their "
    "software as a gift to him. Press Ctrl+Shift+Y in Picasa and Thomas "
    "appeared; press it again and he multiplied across the screen. Millions of "
    "photographers found him and smiled, most never knowing whose bear he was.\n\n"
    "Google discontinued Picasa in 2016. Thomas went with it — no ceremony, no "
    "acknowledgment. Just gone.\n\n"
    "SNAP SLAPPER carries the shortcut forward because photo software should "
    "know what it is built on. Ctrl+Shift+Y still summons Thomas here. He "
    "belongs wherever photographs are made.\n\n"
    "Thomas is a teddy bear. He first appeared as a hidden Easter egg in Picasa, "
    "hidden there in appreciation for Noah Grey — photoblogging pioneer, creator "
    "of Greymatter, and one of the people who built what the web was supposed to "
    "be. When Picasa ended, Thomas disappeared. He lives here now. He is not "
    "going anywhere."
)


def _asset_dir():
    if getattr(sys, "frozen", False):
        return getattr(sys, "_MEIPASS", os.path.dirname(sys.executable))
    return os.path.dirname(os.path.abspath(__file__))


def image_path():
    return os.path.join(_asset_dir(), _IMAGE_NAME)


class Thomas:
    """Per-window Easter-egg controller. Create one and call install(window)."""

    def __init__(self, window):
        self.window = window
        self.bears = []
        self._photos = {}          # size -> PhotoImage, kept alive
        self._base = None          # lazily loaded PIL image
        self.dedication_shown = False
        self.story = None
        self.dedication = None

    # --- image ---
    def _base_image(self):
        if self._base is None:
            self._base = Image.open(image_path()).convert("RGBA")
        return self._base

    def _photo(self, size):
        if size not in self._photos:
            base = self._base_image()
            height = round(size * base.height / base.width)
            self._photos[size] = ImageTk.PhotoImage(
                base.resize((size, height), Image.LANCZOS))
        return self._photos[size]

    # --- bears ---
    def _build_bear(self):
        size = random.randint(90, 220)
        try:
            photo = self._photo(size)
        except Exception:
            return  # no image available: dedication/story still carry Thomas
        bear = tk.Toplevel(self.window)
        bear.overrideredirect(True)
        try:
            bear.attributes("-topmost", True)
        except Exception:
            pass
        transparent = False
        try:
            bear.wm_attributes("-transparentcolor", _TRANSPARENT)
            transparent = True
        except Exception:
            pass
        background = _TRANSPARENT if transparent else "#111111"
        bear.configure(bg=background)
        label = tk.Label(bear, image=photo, bg=background, bd=0, cursor="hand2")
        label.image = photo
        label.pack()
        label.bind("<Button-1>", lambda _event: self.clear())

        self.window.update_idletasks()
        win_x = self.window.winfo_rootx()
        win_y = self.window.winfo_rooty()
        span_x = max(1, self.window.winfo_width() - size - 20)
        span_y = max(1, self.window.winfo_height() - round(size * 1.6) - 20)
        x = win_x + random.randint(10, 10 + span_x)
        y = win_y + random.randint(10, 10 + span_y)
        bear.geometry("+%d+%d" % (x, y))
        self.bears.append(bear)

    def clear(self):
        for bear in self.bears:
            try:
                bear.destroy()
            except Exception:
                pass
        self.bears = []

    # --- dedication toast ---
    def _show_dedication(self):
        try:
            toast = tk.Toplevel(self.window)
            toast.overrideredirect(True)
            toast.attributes("-topmost", True)
            tk.Label(toast, text="  Thomas the Bear  ·  For Noah Grey. We remember.  ",
                     bg="#111111", fg="#39ff14", font=("Segoe UI", 11, "bold"),
                     padx=10, pady=8).pack()
            self.window.update_idletasks()
            x = self.window.winfo_rootx() + max(20, (self.window.winfo_width() - 360) // 2)
            y = self.window.winfo_rooty() + self.window.winfo_height() - 90
            toast.geometry("+%d+%d" % (x, y))
            self.dedication = toast
            toast.after(4000, lambda: self._close(toast))
        except Exception:
            pass

    def _close(self, widget):
        try:
            widget.destroy()
        except Exception:
            pass

    # --- Noah & Thomas story ---
    def show_story(self):
        if self.story is not None and self.story.winfo_exists():
            self.story.deiconify()
            self.story.lift()
            return self.story
        window = tk.Toplevel(self.window)
        window.title("Noah Grey & Thomas the Bear")
        window.configure(bg="#141414")
        window.geometry("560x600")
        window.transient(self.window)
        tk.Label(window, text="NOAH GREY  &  THOMAS THE BEAR", bg="#141414", fg="#e6e6e6",
                 font=("Segoe UI", 12, "bold")).pack(anchor="center", padx=24, pady=(22, 4))
        try:
            photo = self._photo(120)
            holder = tk.Label(window, image=photo, bg="#141414")
            holder.image = photo
            holder.pack(pady=(4, 8))
        except Exception:
            pass
        body = tk.Text(window, bg="#141414", fg="#d8d8d8", relief="flat", wrap="word",
                       padx=26, pady=6, font=("Georgia", 11), spacing1=2, spacing3=8,
                       highlightthickness=0)
        body.pack(fill="both", expand=True)
        body.insert("1.0", STORY)
        body.configure(state="disabled")
        tk.Label(window, text="—  For Noah Grey  —", bg="#141414", fg="#8a8a8a",
                 font=("Segoe UI", 10)).pack(pady=(4, 4))
        tk.Label(window, text="[ ESC ] Close", bg="#141414", fg="#6a6a6a",
                 font=("Segoe UI", 8)).pack(pady=(0, 14))
        window.bind("<Escape>", lambda _event: self._close(window))
        self.story = window
        return window

    # --- key handlers ---
    def _spawn(self, _event=None):
        if not self.dedication_shown:
            self._show_dedication()
            self.dedication_shown = True
        self._build_bear()
        return "break"

    def _story(self, _event=None):
        self.show_story()
        return "break"

    def _on_escape(self, _event=None):
        if self.bears:
            self.clear()

    def _maybe_clear_key(self, _event=None):
        if self.bears:
            self.clear()
            return "break"

    def install(self):
        """Bind Thomas's shortcuts on the window (and its children via bindtags)."""
        window = self.window
        # Shift makes the keysym upper-case Y on typical layouts; bind the
        # lower-case form too so a caps-lock or layout quirk still finds Thomas.
        for sequence in ("<Control-Shift-Y>", "<Control-Shift-y>"):
            window.bind(sequence, self._spawn, add="+")
        for sequence in ("<Control-Shift-Z>", "<Control-Shift-z>"):
            window.bind(sequence, self._story, add="+")
        window.bind("<Escape>", self._on_escape, add="+")
        window.bind("<KeyPress-x>", self._maybe_clear_key, add="+")
        window.bind("<KeyPress-X>", self._maybe_clear_key, add="+")
        return self


def install(window):
    """Attach Thomas to a Tk window. Returns the controller (or None on failure).
    Never raises — the app must open even if the Easter egg cannot."""
    try:
        return Thomas(window).install()
    except Exception:
        return None


# ===== SNAPSMACK EOF =====
