"""Measure the desktop mosaic preview with the shared browser engine.

    python tools/coldsnap/measure_mosaic_repaint.py [photo-count]

This is a timing probe, not a test threshold. Generated photographs are
simpler to decode than real images.
"""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python EOF marker below.

import os
import sys
import time

_HERE = os.path.dirname(os.path.abspath(__file__))
for _path in (_HERE, os.path.join(_HERE, '..', '_shared')):
    _path = os.path.abspath(_path)
    if _path not in sys.path:
        sys.path.insert(0, _path)

os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')
os.environ.setdefault('QTWEBENGINE_CHROMIUM_FLAGS', '--disable-gpu --disable-gpu-compositing')

from PySide6.QtWidgets import QApplication            # noqa: E402
from PySide6.QtTest import QTest                       # noqa: E402
from PySide6.QtCore import Qt                          # noqa: E402
from PySide6.QtGui import QImage, QPainter, QColor   # noqa: E402

FIXTURE_DIR = os.path.join(os.environ.get('TEMP', '/tmp'), 'snapsmack-mosaic-fixtures')
FIXTURE_W, FIXTURE_H = 4000, 3000


def fixtures(count):
    """Photographs at the pixel size SNAP SLAPPER exports."""
    os.makedirs(FIXTURE_DIR, exist_ok=True)
    paths = []
    for index in range(count):
        path = os.path.join(FIXTURE_DIR, 'photo-%03d.jpg' % index)
        if not os.path.isfile(path):
            image = QImage(FIXTURE_W, FIXTURE_H, QImage.Format_RGB32)
            image.fill(QColor((index * 11) % 255, (index * 37) % 255, 90))
            painter = QPainter(image)
            painter.fillRect(100, 100, FIXTURE_W // 2, FIXTURE_H // 2,
                             QColor(255, 255, 255))
            painter.end()
            image.save(path, 'JPG', 92)
        paths.append(path)
    return paths


def main(argv):
    count = int(argv[1]) if len(argv) > 1 else 20
    app = QApplication([])
    from coldsnap_qt.mode_take import MosaicPreview

    tiles = []
    for index, path in enumerate(fixtures(count)):
        thumb = path.replace('.jpg', '-preview.jpg')
        if not os.path.isfile(thumb):
            QImage(path).scaled(400, 400, Qt.KeepAspectRatio, Qt.SmoothTransformation).save(thumb)
        tiles.append((index, path, thumb))

    preview = MosaicPreview()
    preview.show()
    timings = []
    for weights in ({}, {3: 1} if count >= 3 else {}):
        start = time.perf_counter()
        preview.set_mosaic(tiles, 'asymmetric', weights=weights)
        while len(preview.computed_cells()['items']) != count and time.perf_counter() - start < 30:
            QTest.qWait(10)
        elapsed = time.perf_counter() - start
        if len(preview.computed_cells()['items']) != count:
            print(preview._error.text() or 'Mosaic geometry did not finish.')
            return 1
        timings.append(elapsed)
    print(f'{count} photographs, browser geometry in {timings[0]:.3f}s; resize in {timings[1]:.3f}s')
    preview.close()
    app.processEvents()
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv))


# ===== SNAPSMACK EOF =====
