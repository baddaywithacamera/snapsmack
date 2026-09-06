"""COLD SNAP Qt — small shared widget kit (cards, badges, thumbs, confirm).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
from urllib.parse import urlparse

from PySide6.QtCore import Qt, QSize
from PySide6.QtGui import QPixmap, QIntValidator
from PySide6.QtWidgets import (
    QFrame, QLabel, QLineEdit, QVBoxLayout, QHBoxLayout, QMessageBox,
    QPushButton, QSlider, QWidget,
)

from . import theme


# --- Cards -------------------------------------------------------------------

class Card(QFrame):
    """A titled box — the Qt version of the Tk ui.box()."""

    def __init__(self, title: str = "", parent=None):
        super().__init__(parent)
        self.setObjectName("Card")
        outer = QVBoxLayout(self)
        outer.setContentsMargins(14, 12, 14, 14)
        outer.setSpacing(8)
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


def load_pixmap(path: str, size: int = 64):
    """Square-fit pixmap, cached by (path, mtime, size). None when missing."""
    if not path or not os.path.isfile(path):
        return None
    try:
        key = (path, os.path.getmtime(path), size)
    except OSError:
        return None
    if key in _PIX_CACHE:
        return _PIX_CACHE[key]
    pm = QPixmap(path)
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
        name.setFixedWidth(120)
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
