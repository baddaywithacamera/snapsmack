"""Measure what one repaint of the mosaic preview costs.

    python tools/coldsnap/measure_mosaic_repaint.py [photo-count]

WHY THIS EXISTS. SS-1033 / SS-1019: the owner could not finish a post for a
month because the mosaic step stopped responding. The cause was one line in
coldsnap_qt/mode_take.py -- MosaicPreview.paintEvent decoded every tile's
full-resolution original from disk, uncached, on every paint event. paintEvent
runs on every repaint: ticking a photograph, resizing the dialog, dragging a
tile, the window regaining focus. With 53 photographs that was seconds of
blocked GUI thread per repaint.

A claim about responsiveness is a geometry-and-timing claim, and this project's
rule is to measure those rather than assert them. So this harness exists to be
re-run rather than believed, and it reports the BEFORE number as well as the
after, by swapping the decode back to the old expression.

It is a measurement harness, NOT a suite test. It is not wired into
tests/ because a wall-clock threshold on a shared machine is a flaky gate, and
a flaky gate teaches people to ignore gates. Run it by hand when touching the
preview.

LIMIT, stated rather than discovered later: the fixtures are flat-colour JPEGs,
which decode FASTER than real photographs of the same pixel dimensions. Every
BEFORE number here is therefore a floor, not a ceiling -- real originals are
worse than this says.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys
import time

_HERE = os.path.dirname(os.path.abspath(__file__))
for _path in (_HERE, os.path.join(_HERE, '..', '_shared')):
    _path = os.path.abspath(_path)
    if _path not in sys.path:
        sys.path.insert(0, _path)

os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')

from PySide6.QtWidgets import QApplication            # noqa: E402
from PySide6.QtGui import QImage, QPainter, QColor, QPixmap   # noqa: E402

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


def time_paints(preview, times):
    out = []
    for _ in range(times):
        target = QImage(preview.size(), QImage.Format_RGB32)
        start = time.perf_counter()
        preview.render(target)
        out.append(time.perf_counter() - start)
    return out


def main(argv):
    count = int(argv[1]) if len(argv) > 1 else 20
    QApplication([])

    import coldsnap_qt.mode_take as mode_take
    from coldsnap_qt.mode_take import MosaicPreview

    paths = fixtures(count)
    tiles = list(enumerate(paths))
    print('%d photographs at %dx%d' % (count, FIXTURE_W, FIXTURE_H))

    now = MosaicPreview()
    now.resize(900, 600)
    now.set_mosaic(tiles, 'rows')
    first = time_paints(now, 1)[0]
    after = time_paints(now, 5)
    after_mean = sum(after) / len(after)

    # The ONLY thing that changed in the repair is the decode and its cache, so
    # putting the old expression back measures the old behaviour through the
    # same paint path. This is the line that used to be in paintEvent.
    original = mode_take.load_pixmap
    mode_take.load_pixmap = lambda path, size: QPixmap(path)
    try:
        before = MosaicPreview()
        before.resize(900, 600)
        before.set_mosaic(tiles, 'rows')
        before_runs = time_paints(before, 4)
    finally:
        mode_take.load_pixmap = original
    before_mean = sum(before_runs) / len(before_runs)

    print()
    print('BEFORE  raw QPixmap(path), full resolution, no cache')
    print('  every paint   %s' % ', '.join('%.3fs' % r for r in before_runs))
    print('  mean          %.3fs   (%.1f ms per photograph)'
          % (before_mean, before_mean * 1000 / count))
    print('AFTER   load_pixmap, cached by (path, mtime, size), size quantised')
    print('  first paint   %.3fs   (the decode still happens once)' % first)
    print('  repaints      %s' % ', '.join('%.3fs' % r for r in after))
    print('  mean          %.3fs   (%.1f ms per photograph)'
          % (after_mean, after_mean * 1000 / count))
    print()
    print('  repaint of a 53-photograph mosaic: %.1fs -> %.3fs'
          % (before_mean / count * 53, after_mean / count * 53))

    # A repaint that still costs like a fresh decode means the cache is not
    # being hit -- most likely the size is no longer quantised, so every
    # repaint asks for a size it has never seen.
    if after_mean < before_mean / 10:
        print()
        print('PASS  repaints are served from cache')
        return 0
    print()
    print('FAIL  a repaint still costs like a fresh decode')
    return 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv))
# ===== SNAPSMACK EOF =====
