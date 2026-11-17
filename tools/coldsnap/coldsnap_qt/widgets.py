"""COLD SNAP Qt — small shared widget kit (cards, badges, thumbs, confirm).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
from urllib.parse import urlparse

from PySide6.QtCore import Qt, QSize
from PySide6.QtGui import QPixmap, QIntValidator, QImageReader
from PySide6.QtWidgets import (
    QFrame, QLabel, QLineEdit, QScrollArea, QSizePolicy, QVBoxLayout,
    QHBoxLayout, QMessageBox, QPushButton, QSlider, QWidget,
)

from . import theme


# --- Cards -------------------------------------------------------------------

class Card(QFrame):
    """A titled box — the Qt version of the Tk ui.box()."""

    def __init__(self, title: str = "", parent=None):
        super().__init__(parent)
        self.setObjectName("Card")
        outer = QVBoxLayout(self)
        outer.setContentsMargins(16, 14, 16, 16)
        outer.setSpacing(9)
        if title:
            t = QLabel(title)
            t.setObjectName("CardTitle")
            outer.addWidget(t)
        self.body = outer  # callers add straight into the card's layout


def hint(text: str) -> QLabel:
    lbl = QLabel(text)
    lbl.setObjectName("Hint")
    lbl.setWordWrap(True)
    return lbl


def field_label(text: str) -> QLabel:
    lbl = QLabel(text.upper())
    lbl.setObjectName("FieldLabel")
    return lbl


# --- Status badge --------------------------------------------------------------

def status_badge(status: str) -> QLabel:
    text, colour = theme.STATUS_BADGES.get(status, (status.upper(), theme.DIM))
    lbl = QLabel(text)
    lbl.setStyleSheet(
        f"color: {colour}; font-size: 11px; font-weight: 700;"
        f"letter-spacing: 0.5px; background: transparent;")
    return lbl


# --- Thumbnails ------------------------------------------------------------------

_PIX_CACHE: dict = {}


def thumb_decode_size(source_width, source_height, size):
    """The size to DECODE a thumbnail at, or None to decode unscaled.

    Lifted out of load_pixmap so it can be asked rather than inferred. The
    final pixmap is scaled again afterwards, so a wrong decode size produces
    the right picture at the wrong cost -- a mutation run put the full decode
    back and used the width instead of the long edge, and both survived every
    assertion, because nothing could see this step. This is that step.

    The target is a square fit, so the LONG edge is what must reach `size`.
    Scaling is computed in the PRE-transform frame, which is the frame
    setScaledSize works in: Qt scales the stored grid and then rotates. One
    factor on both axes keeps that correct for a quarter-turned photograph.

    None means the photograph is already within the box, and decoding it
    unscaled is cheaper than asking Qt to scale it up.
    """
    width = int(source_width or 0)
    height = int(source_height or 0)
    size = int(size or 0)
    if width <= 0 or height <= 0 or size <= 0:
        return None
    longest = max(width, height)
    if longest <= size:
        return None
    scale = size / float(longest)
    return (max(1, int(round(width * scale))), max(1, int(round(height * scale))))


def load_pixmap(path: str, size: int = 64):
    """Square-fit pixmap, cached by (path, mtime, size). None when missing.

    THE EXIF ORIENTATION IS APPLIED, and it was not. This was QPixmap(path),
    which ignores the orientation tag -- measured: a 1500x1000 JPEG tagged
    Orientation=6 comes back 1500x1000 from QPixmap and 1000x1500 from
    QImageReader with autoTransform. So a photograph the camera recorded
    sideways was shown on its side here, by every one of the eighteen call
    sites in COLD SNAP that go through this function.

    Found while fixing the same fault in the BIGGIE mosaic preview, which Muse
    had caught there. This is the shared control the rest of the application
    draws thumbnails with: photo_loader.PhotoLoader already passed
    setAutoTransform(True) and canvas._pixmap now does, so leaving this one
    alone would have meant the same photograph upright in a mosaic and
    sideways as a single image block in the same editor window.

    It also stops decoding at full resolution. The old call read the whole
    photograph to make a 64px thumbnail; setScaledSize decodes straight to the
    size asked for. A SNAP SLAPPER original is 2655x2655.
    """
    if not path or not os.path.isfile(path):
        return None
    try:
        key = (path, os.path.getmtime(path), size)
    except OSError:
        return None
    if key in _PIX_CACHE:
        return _PIX_CACHE[key]
    reader = QImageReader(path)
    reader.setAutoTransform(True)
    source = reader.size()
    want = thumb_decode_size(source.width(), source.height(), size)         if source.isValid() else None
    if want is not None:
        reader.setScaledSize(QSize(want[0], want[1]))
    image = reader.read()
    pm = QPixmap.fromImage(image) if not image.isNull() else QPixmap()
    if pm.isNull():
        return None
    pm = pm.scaled(QSize(size, size), Qt.KeepAspectRatio, Qt.SmoothTransformation)
    if len(_PIX_CACHE) > 400:   # bounded — a long sitting can touch many files
        _PIX_CACHE.clear()
    _PIX_CACHE[key] = pm
    return pm


def thumb_label(path: str, size: int = 64) -> QLabel:
    lbl = QLabel()
    lbl.setFixedSize(size, size)
    lbl.setAlignment(Qt.AlignCenter)
    lbl.setStyleSheet(f"background: {theme.CANVAS}; border-radius: 4px;")
    pm = load_pixmap(path, size)
    if pm:
        lbl.setPixmap(pm)
    return lbl


# --- Slider row -------------------------------------------------------------------

class SliderRow(QWidget):
    """label — slider — typed value. The COLD SNAP control-row idiom.

    The number is a real editable field, not a readout: type an exact value
    instead of landing a fine drag (design-bible law — precision is optional,
    never mandatory). Arrow keys step the focused slider as Qt always does."""

    def __init__(self, label: str, lo: int, hi: int, value: int, parent=None):
        super().__init__(parent)
        row = QHBoxLayout(self)
        row.setContentsMargins(0, 0, 0, 0)
        name = QLabel(label)
        name.setObjectName("Hint")
        name.setFixedWidth(90)   # rail-width friendly; every label here fits
        self.slider = QSlider(Qt.Horizontal)
        self.slider.setRange(lo, hi)
        self.slider.setValue(value)
        self.value_edit = QLineEdit(str(value))
        self.value_edit.setFixedWidth(56)
        self.value_edit.setAlignment(Qt.AlignRight | Qt.AlignVCenter)
        self.value_edit.setValidator(QIntValidator(lo, hi, self))
        self.slider.valueChanged.connect(
            lambda v: self.value_edit.setText(str(v)))
        self.value_edit.editingFinished.connect(self._typed)
        row.addWidget(name)
        row.addWidget(self.slider, 1)
        row.addWidget(self.value_edit)

    def _typed(self):
        text = self.value_edit.text().strip()
        if text in ("", "-"):
            self.value_edit.setText(str(self.slider.value()))
            return
        self.slider.setValue(int(text))            # clamps to range
        self.value_edit.setText(str(self.slider.value()))

    def value(self) -> int:
        return int(self.slider.value())

    def set_value(self, v: int) -> None:
        self.slider.setValue(int(v))


# --- Accordion rail (same design as SNAP SLAPPER) -----------------------------

class Accordion(QWidget):
    """A collapsible titled section — SNAP SLAPPER's rail idiom, verbatim
    (tools/hub/slapper_qt/widgets.py). Click the header to open/close."""

    def __init__(self, title, expanded=False, parent=None):
        super().__init__(parent)
        outer = QVBoxLayout(self)
        outer.setContentsMargins(0, 0, 0, 0)
        outer.setSpacing(0)

        self.header = QPushButton(title)
        self.header.setObjectName("AccordionHeader")
        self.header.setCheckable(True)
        self.header.setChecked(expanded)
        self.header.setCursor(Qt.PointingHandCursor)
        self.header.toggled.connect(self._toggle)
        outer.addWidget(self.header)

        self.body = QWidget()
        self.body_layout = QVBoxLayout(self.body)
        self.body_layout.setContentsMargins(10, 6, 10, 10)
        self.body_layout.setSpacing(8)
        self.body.setVisible(expanded)
        outer.addWidget(self.body)

        self.setSizePolicy(QSizePolicy.Preferred, QSizePolicy.Fixed)

    def add(self, widget):
        self.body_layout.addWidget(widget)

    def add_layout(self, lay):
        self.body_layout.addLayout(lay)

    def set_expanded(self, on: bool):
        self.header.setChecked(bool(on))

    def _toggle(self, checked):
        self.body.setVisible(checked)


def build_rail(sections, bottom_widgets=(), width: int = 310) -> QWidget:
    """The right-hand control rail, SNAP SLAPPER-style: a fixed-width column
    where the accordion sections scroll and `bottom_widgets` (SEND, status)
    stay pinned and always visible."""
    rail = QWidget()
    rail.setObjectName("Rail")
    rail.setFixedWidth(width)
    col = QVBoxLayout(rail)
    col.setContentsMargins(0, 0, 0, 0)
    col.setSpacing(0)

    scroll = QScrollArea()
    scroll.setWidgetResizable(True)
    scroll.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
    inner = QWidget()
    inner_col = QVBoxLayout(inner)
    inner_col.setContentsMargins(0, 0, 0, 0)
    inner_col.setSpacing(0)
    for s in sections:
        inner_col.addWidget(s)
    inner_col.addStretch(1)
    scroll.setWidget(inner)
    col.addWidget(scroll, 1)

    for w in bottom_widgets:
        w.setParent(rail)
        col.addWidget(w)
        if isinstance(w, QPushButton):
            col.setContentsMargins(0, 0, 0, 0)
    return rail


# --- The publish gate ---------------------------------------------------------------

def confirm_post(parent, url: str, count: int, item: str = "post") -> bool:
    """Never publish to a live site without a confirm that NAMES the site
    (the Parkinson's-forgiving guard, ARCH-03 — same wording contract as the
    Tk shell). Default button is Cancel so a stray double-click cannot send."""
    dest = urlparse(url if "://" in url else "https://" + url).netloc or url or "your site"
    plural = "" if count == 1 else "s"
    box = QMessageBox(parent)
    box.setWindowTitle("Send to the site?")
    box.setText(f"Send {count} {item}{plural} to {dest}?")
    box.setInformativeText("They publish on the live site the moment they land.")
    send = box.addButton(f"SEND TO {dest.upper()}", QMessageBox.AcceptRole)
    box.addButton("Cancel", QMessageBox.RejectRole)
    box.setDefaultButton(box.buttons()[1])
    box.exec()
    return box.clickedButton() is send


def big_button(text: str, obj_name: str = "Primary") -> QPushButton:
    b = QPushButton(text)
    b.setObjectName(obj_name)
    b.setMinimumHeight(42)      # big active target — no fragile little hitboxes
    return b

# ===== SNAPSMACK EOF =====
