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

import base64
import io
import os
import random
import sys
import tkinter as tk

from PIL import Image, ImageTk


_IMAGE_NAME = "thomas-transparent.png"
_TRANSPARENT = "#ff00fe"  # a colour Thomas never contains; keyed out where supported

# Thomas travels inside this module — the bear image is embedded so any tool
# that bundles thomas.py carries him, with no separate asset to lose.
_IMAGE_B64 = (
    "iVBORw0KGgoAAAANSUhEUgAAALkAAAEsCAMAAACor+IqAAADAFBMVEUAAAD///97KitIBAdrHiGbMD"
    "jOHS+tGSfhJDfxLkPJM0P3O1L2V2xmYnhmXo5fXmo1OUQkNDBFTUoUJRk1PjY0OywOEQopLiEtMyMX"
    "GhIlKhwpLhozOCUuLysuMh4kJxUpKiAuLhoqKhouLh4kJBwsKxU5NiI9OiVBPipBPy8yLx4yLho5Ni"
    "Y1MiQ9OisuLCIuKho1Mys4Mh4uKh4yLiL96a0xLiY2Lho+NiFGPikjHxXMxbMzKha/tqIyKho2Lh46"
    "MiI+NiZCOipGPi6onYY6LxpCNiFFOiVKPilRRjL82p9MQjDz5MksJRpVSji2qJAsIxU+MiFWRzD90p"
    "RkVDwyKh42LiI6MiZCOi4/Lxk2Kho6Lh5OPilCNiZGOipKPi5SRjagjnWMf245IwnZoFsgGA7jq2VG"
    "NiLtt3TerXD7yYvou4JdSzU8NCpEKQpWOBZKMRQ8KhXNlFNHMx1KNyFMOib3wIDHmmdRQjIsJh9TOB"
    "24g0szJBWecUI6Kho+Lh5CMiFZQy1SPipGNiZaRjI2Kh5KOipOPi46LiI+MiZWRjaZfWFCNipGOi4y"
    "KiI2LiY8NS5GPzg9ODMsGgmHWi9ONyFTOyVWPylsUzxSPy6Nb1SDcF5NQjhaLwpnPhxxRSF2TSxdPy"
    "U/KhlrSSxDLh1fRjCCYERkSzUzJhuAZlBlRCtyUDZGMiJ5VjxKNiY6Kh5OOio+LiJCMiZWQjJaRjZG"
    "Nip2W0dKOi5dSjxSQjZ3YE9MPjNFOzMvKiZcOSA7JRVONiZrSzVSOipWPi5aQjJeRjZxVUJkTDw2Ki"
    "I6LiZCNi52ZVlNRD40LipPMh9TNiRfQjBrVEZURDprWk9cTkVKMSJYOik/Kh5cPixDLiJkRjRGMiZs"
    "TTxOOi5WQjZKMiZLNis6KiJaQjY+LiZTPjNCMipcRjtGNi5lT0RVR0A7JRtrRTRSOi9LOjJbKhZXOi"
    "5eQjZjRjpGMipOMSZUNSo/KiIzJiI2KiY7LipDNjI6KiY6JiJHMi5CMS5OHx3///9JcJpOAAABAHRS"
    "TlP///////////////////////////////////////////////////////////////////////////"
    "//////////////////////////////////////////////////////////////////////////////"
    "//////////////////////////////////////////////////////////////////////////////"
    "//////////////////////////////////////////////////////////////////////////////"
    "//////////////////////////////8AU/cHJQAAAAlwSFlzAAAbrwAAG68BXhqRHAAAh+ZJREFUeJ"
    "zU/QV4XNe1/w/vc4aZeTTSiJnBYjPbcsyOMXbAgYbbpm2SUkphRofsOHbMzJYti5lGDKNhZqZz3kdO"
    "b5t7f4XETu//vUePpEfSnD2fs/Tda6+9NkEo+D96weD/6gWD/6sXDP6vXjD4v3rB4P/qhf0PlFlza8"
    "+nVamZIyjIPHPr9i+SP/vx3wX6EbxiDbjNd/uqSrX7lsga4xlUnczVBua0AqCJK3c1WZvA/x+S70Mf"
    "WHT1kyNxEArKgcstwAOI7oIA6mKiwCXDuimY6bwzed9cBf8fk1dxT4FFVwFYc+rbH5uqPuQ1xHSyOK"
    "eklS31Wr1zOyEucACgBgyWRJMY15dvGQZuJxi2/6iGh34g+SLKCg4J60I2VpW/cvsXa6pGHo67cfxp"
    "TZZeFR8FwAMKQG/673fHgUECKfVE3KwLYEW1rZmAQfW6Ll/9/4K85hZYs4YOEIk313stYwTjvI2xZh"
    "PGvcoEdFgxiJlEOrqGlWj2JlJNMK9PhOEifmBuZko8Lo6lchi4AHCBbyvt/yb5mnvdKIsCPtsCKqcB"
    "RY0ZAmB48hbYs8RFxdCw0ThEjXMxCqYorTwasIlZEMKzGaLqSmCwuhM94alVQ+6F9dJjGYm0w5/+r5"
    "KvsT/mkgYQCmChTgCkUJsTgMsbWzNlrKgzQRNzJ+ah3nFmksXNrc/lj6fyLIa3XhAMeAANRJNt1PEs"
    "0SVHBt4BjoEMWewq9On/HvmrwhvrL6wGiUHuNKDoEKzGKbucARgAJMwg80P8aYdEYFUDxkiUVzkwnT"
    "FCZ1LSTD7IM85mgkIlkCtBcsBkKRwLRdUsF0QF6Gdx9lP/O+SH5vewyLDMhxnVAfdy90XAYCT2MgDL"
    "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"
)

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
            data = base64.b64decode(_IMAGE_B64)
            self._base = Image.open(io.BytesIO(data)).convert("RGBA")
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
        # SnapSmack's midnight-lime: near-black ground, neon-lime accent.
        midnight, lime, dim = "#0b0b0b", "#39ff14", "#8a8a8a"
        window = tk.Toplevel(self.window)
        window.title("Noah Grey & Thomas the Bear")
        window.configure(bg=midnight)
        window.geometry("560x600")
        window.transient(self.window)
        tk.Label(window, text="NOAH GREY  &  THOMAS THE BEAR", bg=midnight, fg=lime,
                 font=("Segoe UI", 12, "bold")).pack(anchor="center", padx=24, pady=(22, 6))
        tk.Frame(window, bg=lime, height=2).pack(fill="x", padx=150, pady=(0, 6))
        try:
            photo = self._photo(120)
            holder = tk.Label(window, image=photo, bg=midnight)
            holder.image = photo
            holder.pack(pady=(4, 8))
        except Exception:
            pass
        body = tk.Text(window, bg=midnight, fg="#d8d8d8", relief="flat", wrap="word",
                       padx=26, pady=6, font=("Georgia", 11), spacing1=2, spacing3=8,
                       highlightthickness=0)
        body.pack(fill="both", expand=True)
        body.insert("1.0", STORY)
        body.configure(state="disabled")
        tk.Label(window, text="—  For Noah Grey  —", bg=midnight, fg=lime,
                 font=("Segoe UI", 10)).pack(pady=(4, 4))
        tk.Label(window, text="[ ESC ] Close", bg=midnight, fg=dim,
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
