"""BIGGIE must never draw a photograph the owner has already changed.

    python tools/coldsnap/check_biggie_pixmap_freshness.py

WHY. coldsnap_qt/canvas.py caches decoded photographs for the editor canvas.
It used to key that cache on the file PATH alone, so the first version drawn
was the version drawn forever: edit or rotate a photograph and the editor kept
showing the old one until COLD SNAP restarted.

That is this owner's normal workflow, not an edge case. SNAP SLAPPER is his
only image source and the mosaic dialog has Rotate left / Rotate right, so a
photograph being rewritten at the same path happens constantly. An editor that
shows the WRONG version of a photograph is worse than a slow one, because the
mistake is only discovered after the post is published.

HOW. Write a red file, draw it, replace the file with a green one at the same
path, draw it again. The second draw must be green. Exit 1 if it is red.

It is a harness rather than a suite test because the suite is PHP and this
needs a Qt application; run it by hand when touching canvas.py.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys
import time

_HERE = os.path.dirname(os.path.abspath(__file__))
for _p in (_HERE, os.path.abspath(os.path.join(_HERE, '..', '_shared'))):
    if _p not in sys.path:
        sys.path.insert(0, _p)

os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')

from PySide6.QtWidgets import QApplication        # noqa: E402
from PySide6.QtGui import QImage, QColor, QPixmapCache   # noqa: E402

RED = (220, 20, 20)


def centre(pix):
    img = pix.toImage()
    colour = img.pixelColor(img.width() // 2, img.height() // 2)
    return (colour.red(), colour.green(), colour.blue())


def near(a, b, tolerance=12):
    return all(abs(x - y) <= tolerance for x, y in zip(a, b))


def main():
    QApplication([])
    from coldsnap_qt.canvas import _pixmap

    tmp = os.path.join(os.environ.get('TEMP', '/tmp'), 'snapsmack-biggie-freshness')
    os.makedirs(tmp, exist_ok=True)
    path = os.path.join(tmp, 'photograph.jpg')

    def write(colour):
        image = QImage(1600, 1200, QImage.Format_RGB32)
        image.fill(colour)
        image.save(path, 'JPG', 95)

    QPixmapCache.clear()

    write(QColor(*RED))
    first = centre(_pixmap(path, 1200))
    if not near(first, RED):
        print('INCONCLUSIVE: the first draw was %s, not the red that was '
              'written. The harness cannot measure staleness it cannot '
              'establish a baseline for.' % (first,))
        return 2

    # The owner rotates or re-exports it; same filename, different content.
    time.sleep(1.1)   # some filesystems have coarse timestamps
    write(QColor(20, 200, 20))
    second = centre(_pixmap(path, 1200))

    print('wrote red, drew %s; replaced with green, drew %s' % (first, second))
    if near(second, first):
        print()
        print('FAIL  BIGGIE is drawing a stale photograph. The file on disk is')
        print('      green and the canvas still draws red, so the editor is')
        print('      showing a version the owner has already replaced.')
        print('      canvas.py _pixmap() must keep the modification time in its')
        print('      cache key, the way widgets.load_pixmap and')
        print('      photo_loader.PhotoLoader both do.')
        return 1

    print()
    print('PASS  the canvas redraws a photograph after it changes on disk.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
# ===== SNAPSMACK EOF =====
