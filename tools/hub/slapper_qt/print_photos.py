"""Printing photographs: page layouts, and the dialog that drives a printer.

Layout is kept as plain geometry on purpose. Deciding where a print goes on a
page has nothing to do with owning a printer, so the arithmetic is testable
without one, and the printing itself is verified by printing to a PDF.
"""

import os

from PIL import Image, ImageOps
from PySide6.QtCore import Qt, QRectF
from PySide6.QtGui import QImage, QPainter, QColor, QFont
from PySide6.QtPrintSupport import QPrinter, QPrintDialog
from PySide6.QtWidgets import (
    QCheckBox, QComboBox, QDialog, QHBoxLayout, QLabel, QMessageBox,
    QPushButton, QVBoxLayout,
)

# (key, menu wording, columns, rows, exact size in inches or None)
LAYOUTS = (
    ("fit", "One per page — as large as the paper allows", 1, 1, None),
    ("4x6", "One 4 × 6 inch print per page", 1, 1, (4.0, 6.0)),
    ("5x7", "One 5 × 7 inch print per page", 1, 1, (5.0, 7.0)),
    ("8x10", "One 8 × 10 inch print per page", 1, 1, (8.0, 10.0)),
    ("2up", "Two per page", 1, 2, None),
    ("4up", "Four per page", 2, 2, None),
    ("9up", "Nine per page — contact sheet", 3, 3, None),
    ("16up", "Sixteen per page — contact sheet", 4, 4, None),
)

LAYOUT_BY_KEY = {entry[0]: entry for entry in LAYOUTS}


def cells_for(layout_key, page_rect, dots_per_inch, caption_height=0.0,
              gap=None):
    """Rectangles, in page units, for one page of ``layout_key``.

    ``page_rect`` is the printable area. An exact-size layout returns a single
    centred rectangle of that many inches, clamped to the paper so a 8x10 on
    A4 shrinks to fit instead of printing off the edge.
    """
    key, _label, columns, rows, inches = LAYOUT_BY_KEY.get(
        layout_key, LAYOUT_BY_KEY["fit"])
    if gap is None:
        gap = dots_per_inch * 0.12          # about 3mm between prints
    if inches:
        width = min(page_rect.width(), inches[0] * dots_per_inch)
        height = min(page_rect.height(), inches[1] * dots_per_inch)
        if page_rect.width() > page_rect.height() and height > width:
            # Landscape paper with a portrait print: keep the proportions,
            # just make it as large as the short edge allows.
            scale = min(page_rect.width() / width, page_rect.height() / height)
            width, height = width * scale, height * scale
        return [QRectF(page_rect.left() + (page_rect.width() - width) / 2,
                       page_rect.top() + (page_rect.height() - height) / 2,
                       width, height)]
    cell_width = (page_rect.width() - gap * (columns - 1)) / columns
    cell_height = (page_rect.height() - gap * (rows - 1)) / rows
    cells = []
    for row in range(rows):
        for column in range(columns):
            cells.append(QRectF(
                page_rect.left() + column * (cell_width + gap),
                page_rect.top() + row * (cell_height + gap),
                cell_width, max(1.0, cell_height - caption_height)))
    return cells


def pages_for(paths, per_page):
    per_page = max(1, int(per_page))
    return [paths[index:index + per_page]
            for index in range(0, len(paths), per_page)]


def _qimage(path, target_pixels):
    """Load a photograph, honour its orientation, and cap its size.

    A 61-megapixel frame does not need to reach the printer intact: anything
    beyond the cell's own pixel count is detail the paper cannot hold, and
    sending it costs seconds per print and hundreds of megabytes.
    """
    with Image.open(path) as source:
        photo = ImageOps.exif_transpose(source).convert("RGB")
    if target_pixels and photo.width * photo.height > target_pixels:
        photo.thumbnail(
            (int(target_pixels ** 0.5 * photo.width / max(1, photo.height)) or 1,
             int(target_pixels ** 0.5 * photo.height / max(1, photo.width)) or 1),
            Image.Resampling.LANCZOS)
    data = photo.tobytes("raw", "RGB")
    return QImage(data, photo.width, photo.height, photo.width * 3,
                  QImage.Format_RGB888).copy()


def fitted(image_size, cell):
    """Centre ``image_size`` inside ``cell`` without distorting it."""
    width, height = image_size
    if not width or not height:
        return QRectF(cell)
    scale = min(cell.width() / width, cell.height() / height)
    drawn_width, drawn_height = width * scale, height * scale
    return QRectF(cell.left() + (cell.width() - drawn_width) / 2,
                  cell.top() + (cell.height() - drawn_height) / 2,
                  drawn_width, drawn_height)


def paint_page(painter, cells, paths, *, captions=False, caption_height=0.0,
               font=None, pixel_budget=0, progress=None):
    """Draw one sheet. Separated from the printer so it can be checked on an
    image: the geometry is the part that can be wrong."""
    for cell, path in zip(cells, paths):
        try:
            image = _qimage(path, pixel_budget)
        except Exception:   # noqa: BLE001 — one bad file, not a lost job
            continue
        painter.drawImage(fitted((image.width(), image.height()), cell), image)
        if captions and font is not None:
            painter.setFont(font)
            painter.setPen(QColor(40, 40, 40))
            painter.drawText(
                QRectF(cell.left(), cell.bottom(), cell.width(), caption_height),
                Qt.AlignHCenter | Qt.AlignTop, os.path.basename(path))
        if progress is not None:
            progress(path)


def print_photos(printer, paths, layout_key="fit", captions=False,
                 progress=None):
    """Paint ``paths`` onto ``printer``. Returns the number of pages printed."""
    painter = QPainter()
    if not painter.begin(printer):
        raise RuntimeError("The printer could not be opened for drawing.")
    try:
        dots_per_inch = printer.resolution() or 300
        page_rect = QRectF(printer.pageRect(QPrinter.DevicePixel))
        caption_height = dots_per_inch * 0.22 if captions else 0.0
        font = QFont()
        font.setPixelSize(max(8, int(dots_per_inch * 0.12)))
        cells = cells_for(layout_key, page_rect, dots_per_inch, caption_height)
        pages = pages_for(list(paths), len(cells))
        # No cell needs more pixels than it can print; a 61-megapixel frame
        # sent whole costs seconds and hundreds of megabytes for detail the
        # paper cannot hold.
        budget = int(max(1.0, cells[0].width() * cells[0].height()))
        for index, page in enumerate(pages):
            if index and not printer.newPage():
                raise RuntimeError("The printer refused a new page.")
            paint_page(painter, cells, page, captions=captions,
                       caption_height=caption_height, font=font,
                       pixel_budget=budget, progress=progress)
        return len(pages)
    finally:
        painter.end()


class PrintPhotosDialog(QDialog):
    """Choose a layout, then hand off to the system print dialog."""

    def __init__(self, parent, paths, title="Print Photographs"):
        super().__init__(parent)
        self.paths = [path for path in paths if path]
        self.setWindowTitle(title)
        self.resize(520, 260)
        layout = QVBoxLayout(self)
        heading = QLabel("PRINT")
        heading.setObjectName("SectionTitle")
        layout.addWidget(heading)
        self.summary = QLabel()
        self.summary.setWordWrap(True)
        layout.addWidget(self.summary)
        row = QHBoxLayout()
        row.addWidget(QLabel("Layout"))
        self.layout_choice = QComboBox()
        for key, label, _columns, _rows, _inches in LAYOUTS:
            self.layout_choice.addItem(label, key)
        self.layout_choice.currentIndexChanged.connect(self._refresh)
        row.addWidget(self.layout_choice, 1)
        layout.addLayout(row)
        self.captions = QCheckBox("Print the file name under each photograph")
        layout.addWidget(self.captions)
        note = QLabel(
            "Paper size, orientation, borderless printing and which printer to "
            "use are chosen in the next window — your printer's own settings.")
        note.setWordWrap(True)
        layout.addWidget(note)
        actions = QHBoxLayout()
        actions.addStretch(1)
        cancel = QPushButton("CANCEL")
        cancel.clicked.connect(self.reject)
        actions.addWidget(cancel)
        self.go = QPushButton("CHOOSE PRINTER…")
        self.go.setObjectName("LayerAddBtn")
        self.go.clicked.connect(self._print)
        actions.addWidget(self.go)
        layout.addLayout(actions)
        self._refresh()

    def _per_page(self):
        key = self.layout_choice.currentData()
        _key, _label, columns, rows, _inches = LAYOUT_BY_KEY[key]
        return columns * rows

    def _refresh(self):
        per_page = self._per_page()
        pages = len(pages_for(self.paths, per_page))
        self.summary.setText(
            f"{len(self.paths):,} photograph(s) — {pages:,} sheet(s) of paper "
            f"at {per_page} per sheet.")
        self.go.setEnabled(bool(self.paths))

    def _print(self):
        if not self.paths:
            return
        printer = QPrinter(QPrinter.HighResolution)
        dialog = QPrintDialog(printer, self)
        dialog.setWindowTitle("Print")
        if dialog.exec() != QDialog.Accepted:
            return
        try:
            pages = print_photos(
                printer, self.paths, self.layout_choice.currentData(),
                self.captions.isChecked())
        except Exception as error:  # noqa: BLE001
            QMessageBox.warning(self, "Printing failed", str(error))
            return
        QMessageBox.information(
            self, "Sent to the printer",
            f"{len(self.paths):,} photograph(s) on {pages:,} sheet(s) were sent "
            "to the printer. Nothing on disk was changed.")
        self.accept()


# ===== SNAPSMACK EOF =====
