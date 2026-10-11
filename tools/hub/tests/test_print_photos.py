"""Printing photographs, verified by printing to a PDF.

A printer is not needed to prove the job is right: QPrinter will write the
same output to a PDF file, so the page count, the layout arithmetic and the
"one unreadable file does not lose the job" behaviour are all checkable here.
"""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

from PIL import Image
from PySide6.QtCore import QRectF
from PySide6.QtPrintSupport import QPrinter
from PySide6.QtWidgets import QApplication

from slapper_qt import print_photos


APP = QApplication.instance() or QApplication([])
DPI = 300.0
A4 = QRectF(0, 0, 8.0 * DPI, 11.0 * DPI)      # printable area, not paper edge


def _photos(folder, count, size=(1200, 800)):
    paths = []
    for index in range(count):
        path = folder / f"photo{index}.jpg"
        Image.new("RGB", size, (30 + index * 20, 90, 140)).save(path, quality=90)
        paths.append(str(path))
    return paths


def test_one_per_page_uses_the_whole_printable_area():
    cells = print_photos.cells_for("fit", A4, DPI)
    assert len(cells) == 1
    assert cells[0].width() == A4.width()
    assert cells[0].height() == A4.height()


def test_an_exact_print_size_is_that_many_inches_and_centred():
    cells = print_photos.cells_for("5x7", A4, DPI)
    assert len(cells) == 1
    cell = cells[0]
    assert abs(cell.width() - 5 * DPI) < 1
    assert abs(cell.height() - 7 * DPI) < 1
    # Centred: the margins left and right are equal.
    assert abs((cell.left() - A4.left()) -
               (A4.right() - cell.right())) < 1


def test_an_exact_size_larger_than_the_paper_is_brought_down_to_fit():
    small = QRectF(0, 0, 4.0 * DPI, 6.0 * DPI)
    cell = print_photos.cells_for("8x10", small, DPI)[0]
    assert cell.width() <= small.width() + 1
    assert cell.height() <= small.height() + 1


def test_several_to_a_sheet_tiles_without_overlapping():
    cells = print_photos.cells_for("4up", A4, DPI)
    assert len(cells) == 4
    for first in range(len(cells)):
        for second in range(first + 1, len(cells)):
            assert not cells[first].intersects(cells[second])
    for cell in cells:
        assert A4.contains(cell)


def test_photos_are_fitted_not_stretched():
    cell = QRectF(0, 0, 1000, 1000)
    target = print_photos.fitted((2000, 1000), cell)
    assert abs(target.width() / target.height() - 2.0) < 0.01
    assert target.width() <= cell.width() + 0.01
    # Centred vertically inside the square cell.
    assert abs(target.top() - 250) < 1


def test_pagination_matches_the_layout():
    assert print_photos.pages_for(list(range(9)), 4) == [
        [0, 1, 2, 3], [4, 5, 6, 7], [8]]
    assert print_photos.pages_for([], 4) == []


def test_printing_nine_photos_four_up_writes_three_sheets(tmp_path):
    paths = _photos(tmp_path, 9)
    output = tmp_path / "job.pdf"
    printer = QPrinter(QPrinter.HighResolution)
    printer.setOutputFormat(QPrinter.PdfFormat)
    printer.setOutputFileName(str(output))
    pages = print_photos.print_photos(printer, paths, "4up", captions=True)
    assert pages == 3
    assert output.exists() and output.stat().st_size > 2000
    assert b"/Count 3" in output.read_bytes()


def test_one_unreadable_file_does_not_lose_the_job(tmp_path):
    paths = _photos(tmp_path, 2)
    broken = tmp_path / "broken.jpg"
    broken.write_bytes(b"this is not a photograph")
    output = tmp_path / "job.pdf"
    printer = QPrinter(QPrinter.HighResolution)
    printer.setOutputFormat(QPrinter.PdfFormat)
    printer.setOutputFileName(str(output))
    printed = []
    pages = print_photos.print_photos(
        printer, paths + [str(broken)], "fit", progress=printed.append)
    assert pages == 3                       # the sheet is still fed
    assert len(printed) == 2                # only the readable ones are drawn
    assert output.exists()


def test_the_dialog_counts_sheets_before_anything_is_printed(tmp_path):
    paths = _photos(tmp_path, 5)
    dialog = print_photos.PrintPhotosDialog(None, paths)
    try:
        index = dialog.layout_choice.findData("4up")
        dialog.layout_choice.setCurrentIndex(index)
        assert "2 sheet" in dialog.summary.text()
        index = dialog.layout_choice.findData("fit")
        dialog.layout_choice.setCurrentIndex(index)
        assert "5 sheet" in dialog.summary.text()
    finally:
        dialog.deleteLater()


def test_the_frozen_build_includes_the_printing_module():
    # QtPrintSupport is a separate PySide module with its own Qt DLL: without
    # naming it, the frozen application imports fine and then cannot print.
    spec = open(os.path.join(os.path.dirname(os.path.dirname(__file__)),
                             "snap_slapper.spec"), encoding="utf-8").read()
    assert "PySide6.QtPrintSupport" in spec


def test_four_up_puts_one_photo_in_each_quadrant(tmp_path):
    """Measured on a real painted page, not reasoned about from the maths."""
    from PySide6.QtGui import QImage, QPainter, QColor
    colours = ((220, 20, 20), (20, 200, 20), (20, 20, 220), (230, 220, 30))
    paths = []
    for index, colour in enumerate(colours):
        path = tmp_path / f"c{index}.png"
        Image.new("RGB", (400, 400), colour).save(path)
        paths.append(str(path))
    page = QImage(800, 1000, QImage.Format_RGB888)
    page.fill(QColor(255, 255, 255))
    sheet = QRectF(0, 0, page.width(), page.height())
    cells = print_photos.cells_for("4up", sheet, 100.0)
    painter = QPainter(page)
    print_photos.paint_page(painter, cells, paths, pixel_budget=400 * 400)
    painter.end()

    def dominant(rect):
        counts = {}
        for x in range(int(rect.left()) + 20, int(rect.right()) - 20, 7):
            for y in range(int(rect.top()) + 20, int(rect.bottom()) - 20, 7):
                pixel = page.pixelColor(x, y)
                key = (pixel.red() // 40, pixel.green() // 40, pixel.blue() // 40)
                counts[key] = counts.get(key, 0) + 1
        return max(counts, key=counts.get)

    seen = [dominant(print_photos.fitted((400, 400), cell)) for cell in cells]
    assert len(set(seen)) == 4, seen          # four different photographs
    assert seen[0] == (5, 0, 0)               # red, top left
    assert seen[3] == (5, 5, 0)               # yellow, bottom right


# ===== SNAPSMACK EOF =====
