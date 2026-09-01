"""Selection-based presentation and contact-sheet output tools."""

import math
import os

from PIL import Image, ImageDraw, ImageFont, ImageOps
from PySide6.QtCore import Qt, QTimer
from PySide6.QtGui import QImage, QPixmap, QKeyEvent
from PySide6.QtWidgets import QDialog, QLabel, QVBoxLayout


def create_contact_sheet(paths, output_path, columns=4, thumb_size=(320, 220),
                         labels=True, margin=24):
    """Write a JPEG contact sheet. Bad/unreadable inputs are skipped."""
    photos = []
    for path in paths:
        try:
            with Image.open(path) as source:
                photo = ImageOps.exif_transpose(source).convert("RGB")
            photos.append((path, photo))
        except (OSError, ValueError):
            continue
    if not photos:
        raise ValueError("None of the selected photographs could be read.")
    columns = max(1, min(12, int(columns)))
    thumb_w, thumb_h = thumb_size
    label_h = 30 if labels else 0
    cell_w, cell_h = thumb_w + margin, thumb_h + label_h + margin
    rows = math.ceil(len(photos) / columns)
    sheet = Image.new("RGB", (columns * cell_w + margin,
                              rows * cell_h + margin), (24, 24, 24))
    draw = ImageDraw.Draw(sheet)
    font = ImageFont.load_default(size=16)
    for index, (path, photo) in enumerate(photos):
        col, row = index % columns, index // columns
        x, y = margin + col * cell_w, margin + row * cell_h
        fitted = ImageOps.contain(photo, (thumb_w, thumb_h),
                                  Image.Resampling.LANCZOS)
        px = x + (thumb_w - fitted.width) // 2
        py = y + (thumb_h - fitted.height) // 2
        sheet.paste(fitted, (px, py))
        if labels:
            name = os.path.basename(path)
            if len(name) > 38:
                name = name[:35] + "…"
            draw.text((x, y + thumb_h + 6), name, fill=(235, 235, 235), font=font)
    if os.path.splitext(output_path)[1].lower() not in {".jpg", ".jpeg"}:
        output_path += ".jpg"
    sheet.save(output_path, "JPEG", quality=92, optimize=True)
    return output_path


class SlideshowDialog(QDialog):
    """A full-window slideshow with obvious keyboard controls."""

    def __init__(self, paths, parent=None, interval_ms=3000):
        super().__init__(parent)
        self.paths = [path for path in paths if os.path.isfile(path)]
        self.index = 0
        self._paused = False
        self.setWindowTitle("SNAP SLAPPER Slideshow")
        self.setMinimumSize(800, 560)
        self.setStyleSheet("background:#080808;color:white;")
        self.label = QLabel(alignment=Qt.AlignCenter)
        self.label.setText("No photographs selected")
        self.label.setWordWrap(True)
        layout = QVBoxLayout(self)
        layout.setContentsMargins(8, 8, 8, 8)
        layout.addWidget(self.label)
        self.timer = QTimer(self)
        self.timer.setInterval(interval_ms)
        self.timer.timeout.connect(self.next_photo)
        if self.paths:
            self._show_current()
            self.timer.start()

    def _show_current(self):
        image = QImage(self.paths[self.index])
        if image.isNull():
            self.label.setText(f"Could not open {os.path.basename(self.paths[self.index])}")
            return
        target = self.label.size()
        pixmap = QPixmap.fromImage(image).scaled(
            target, Qt.KeepAspectRatio, Qt.SmoothTransformation)
        self.label.setPixmap(pixmap)
        self.setWindowTitle(
            f"SNAP SLAPPER Slideshow — {self.index + 1}/{len(self.paths)} — "
            f"{os.path.basename(self.paths[self.index])}")

    def next_photo(self):
        if self.paths:
            self.index = (self.index + 1) % len(self.paths)
            self._show_current()

    def previous_photo(self):
        if self.paths:
            self.index = (self.index - 1) % len(self.paths)
            self._show_current()

    def resizeEvent(self, event):  # noqa: N802
        super().resizeEvent(event)
        if self.paths:
            self._show_current()

    def keyPressEvent(self, event: QKeyEvent):  # noqa: N802
        if event.key() in (Qt.Key_Right, Qt.Key_Down, Qt.Key_PageDown):
            self.next_photo()
        elif event.key() in (Qt.Key_Left, Qt.Key_Up, Qt.Key_PageUp):
            self.previous_photo()
        elif event.key() == Qt.Key_Space:
            self._paused = not self._paused
            self.timer.stop() if self._paused else self.timer.start()
        elif event.key() in (Qt.Key_Escape, Qt.Key_Q):
            self.close()
        else:
            super().keyPressEvent(event)
