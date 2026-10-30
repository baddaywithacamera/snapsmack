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

    # HOW LONG UNTIL EVERY TILE IS ACTUALLY THERE.
    #
    # Decoding is off-thread now, so a fast first paint is not the whole story:
    # the tiles arrive over the following moments and the widget repaints as
    # they do. This pumps the event loop until nothing is outstanding, which is
    # the number that corresponds to what the operator sees settle.
    # The widget must be REPAINTED for the next wave to be requested: the
    # loader holds four decodes at a time and each paint asks for what is
    # still missing. A visible widget gets that from update(); an offscreen
    # one never repaints at all, so the harness has to drive it. Without this
    # the measurement stalls at four tiles for ever and reports a 30-second
    # settle that says nothing about the product -- which is exactly what the
    # first version of this did.
    settle_start = time.perf_counter()
    deadline = settle_start + 60.0
    waves = 0
    while time.perf_counter() < deadline:
        QApplication.processEvents()
        decoded = sum(1 for v in now._ready.values() if v is not None)
        if decoded >= count:
            break
        target = QImage(now.size(), QImage.Format_RGB32)
        now.render(target)
        waves += 1
        time.sleep(0.01)
    settled = time.perf_counter() - settle_start
    arrived = sum(1 for v in now._ready.values() if v is not None)

    after = time_paints(now, 5)
    after_mean = sum(after) / len(after)

    # THE ORIGINAL LINE, measured through the same paint path. The only thing
    # that changed is how a tile is obtained, so replacing _tile_pixmap with
    # the expression that used to be inline -- a raw full-resolution QPixmap,
    # uncached -- measures the behaviour that froze the mosaic step.
    original = MosaicPreview._tile_pixmap
    MosaicPreview._tile_pixmap = lambda self, path, edge: QPixmap(path)
    try:
        before = MosaicPreview()
        before.resize(900, 600)
        before.set_mosaic(tiles, 'rows')
        before_runs = time_paints(before, 4)
    finally:
        MosaicPreview._tile_pixmap = original
    before_mean = sum(before_runs) / len(before_runs)

    print()
    print('BEFORE  raw QPixmap(path), full resolution, uncached, on the GUI thread')
    print('  every paint   %s' % ', '.join('%.3fs' % r for r in before_runs))
    print('  mean          %.3fs   (%.1f ms per photograph)'
          % (before_mean, before_mean * 1000 / count))
    print('AFTER   PhotoLoader: off-thread, decoded at reduced size')
    print('  FIRST paint   %.3fs   (it no longer waits for any decode)' % first)
    print('  all %2d tiles  %.3fs   to arrive, %d of %d decoded, %d repaints'
          % (count, settled, arrived, count, waves))
    print('  repaints      %s' % ', '.join('%.3fs' % r for r in after))
    print('  mean          %.3fs   (%.1f ms per photograph)'
          % (after_mean, after_mean * 1000 / count))
    print()
    print('  repaint of a 53-photograph mosaic: %.1fs -> %.3fs'
          % (before_mean / count * 53, after_mean / count * 53))

    # The first paint is the one that used to block the GUI thread for seconds
    # on a large mosaic. If it is still expensive, the decode is back on the
    # paint path.
    if first < before_mean / 5 and after_mean < before_mean / 10:
        print('PASS  neither the first paint nor a repaint decodes on the GUI thread')
        return 0
    print('FAIL  painting still costs like a fresh decode')
    return 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv))
# ===== SNAPSMACK EOF =====
