# -*- coding: utf-8 -*-
"""widgets.load_pixmap shows a photograph the way up it was taken.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

WHAT THIS GUARDS. load_pixmap is the shared thumbnail loader: eighteen call
sites across cold_storage, drafts_panel, gallery_picker, mode_solo, mode_stack,
mode_take and the BIGGIE canvas's single-image objects all go through it. It was
QPixmap(path), which IGNORES the EXIF orientation tag -- measured, not assumed:

    1500x1000 JPEG tagged Orientation=6
      QPixmap(path)                  1500x1000
      QImageReader + autoTransform   1000x1500   <- what a viewer shows

So a photograph the camera recorded sideways was drawn sideways everywhere in
COLD SNAP. coldsnap_qt/photo_loader.py already passed setAutoTransform(True)
and canvas._pixmap now does too, which is how this surfaced: the same
photograph would have been upright inside a mosaic and sideways as a single
image block in the same editor window.

Nothing could see it before, because every fixture in the suite is a PIL JPEG
with no EXIF. These carry the tag.
"""

import os
import sys
import tempfile
import time

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

_fails = 0


def check(label, ok):
    global _fails
    print(('PASS ' if ok else 'FAIL ') + label)
    if not ok:
        _fails += 1


try:
    from PySide6.QtGui import QGuiApplication
    _app = QGuiApplication.instance() or QGuiApplication([])
    from PIL import Image
except Exception as exc:                                   # pragma: no cover
    print('SKIP — no Qt/PIL in this environment: %s' % exc)
    raise SystemExit(0)

from coldsnap_qt.widgets import load_pixmap, thumb_decode_size        # noqa: E402


def _write(directory, name, width, height, orientation=None, colour=(70, 80, 90)):
    path = os.path.join(directory, name)
    img = Image.new('RGB', (width, height), colour)
    if orientation is None:
        img.save(path, 'JPEG', quality=60)
    else:
        exif = img.getexif()
        exif[0x0112] = orientation
        img.save(path, 'JPEG', quality=60, exif=exif)
    return path


def main():
    work = tempfile.mkdtemp(prefix='thumb_orient_')

    # ── the orientation tag is honoured ────────────────────────────────────
    # Tags 5-8 all carry the quarter turn, so a 1500x1000 file must come back
    # taller than it is wide. Tags 1-4 must not move.
    for tag in (5, 6, 7, 8):
        turned = _write(work, 'turned%d.jpg' % tag, 1500, 1000, orientation=tag)
        pm = load_pixmap(turned, 120)
        check('orientation %d is drawn upright, not on its side' % tag,
              pm is not None and pm.height() > pm.width())
    for tag in (1, 2, 3, 4):
        flat = _write(work, 'flat%d.jpg' % tag, 1500, 1000, orientation=tag)
        pm = load_pixmap(flat, 120)
        check('orientation %d stays landscape' % tag,
              pm is not None and pm.width() > pm.height())

    untagged = _write(work, 'untagged.jpg', 1500, 1000)
    pm = load_pixmap(untagged, 120)
    check('a photograph with no EXIF at all is unchanged',
          pm is not None and pm.width() > pm.height())

    # ── the square fit is unchanged, which is what the 18 callers rely on ──
    # This function has always returned a pixmap whose LONG edge is the size
    # asked for; the orientation fix must not quietly change that contract.
    for size in (32, 64, 120, 180):
        wide = load_pixmap(untagged, size)
        check('a landscape photograph fits %dpx on its long edge' % size,
              wide is not None and max(wide.width(), wide.height()) == size)
        turned = load_pixmap(os.path.join(work, 'turned6.jpg'), size)
        check('and a turned one fits %dpx on ITS long edge, now the height' % size,
              turned is not None and turned.height() == size
              and turned.height() > turned.width())

    # The aspect ratio must survive, or thumbnails are squashed.
    pm = load_pixmap(untagged, 120)
    check('the landscape aspect ratio is kept, not stretched to a square',
          abs(pm.width() / float(pm.height()) - 1.5) < 0.05)
    turned = load_pixmap(os.path.join(work, 'turned6.jpg'), 120)
    check('and the turned one is the reciprocal, 1:1.5',
          abs(turned.height() / float(turned.width()) - 1.5) < 0.05)

    # A photograph SMALLER than the box IS scaled up to fill it. That is
    # pre-existing behaviour -- QPixmap.scaled with KeepAspectRatio has always
    # enlarged as well as shrunk, and the fixed-size QLabels in thumb_label
    # depend on it. The first version of this test asserted the opposite and
    # failed; the test was wrong, not the code. Kept as an assertion of what
    # the function actually promises, so a future change to it is deliberate.
    small = _write(work, 'small.jpg', 48, 32)
    pm = load_pixmap(small, 120)
    check('a photograph smaller than the box is scaled UP to fit it, as before',
          pm is not None and max(pm.width(), pm.height()) == 120)
    check('and keeps its aspect ratio while doing so',
          abs(pm.width() / float(pm.height()) - 48 / 32.0) < 0.05)

    # ── the behaviours the callers already depended on ─────────────────────
    check('a missing file returns None rather than an empty pixmap',
          load_pixmap(os.path.join(work, 'nope.jpg'), 64) is None)
    check('an empty path returns None', load_pixmap('', 64) is None)
    check('a file that is not an image returns None',
          load_pixmap(_not_an_image(work), 64) is None)

    # THE MODIFICATION TIME IS PART OF THE KEY. Rewriting a photograph at the
    # same path must give the new one back -- SNAP SLAPPER does exactly this,
    # and a stale thumbnail is how the editor shows a version that no longer
    # exists.
    churn = _write(work, 'churn.jpg', 1500, 1000, colour=(200, 20, 20))
    first = load_pixmap(churn, 64)
    time.sleep(0.01)
    _write(work, 'churn.jpg', 1000, 1500, colour=(20, 200, 20))
    second = load_pixmap(churn, 64)
    check('a photograph replaced at the same path is re-read, not cached',
          first is not None and second is not None
          and (first.width() > first.height())
          and (second.height() > second.width()))

    # ── THE DECODE SIZE, ASKED RATHER THAN INFERRED ────────────────────────
    # The final pixmap is scaled again after the decode, so a WRONG decode size
    # produces the right picture at the wrong cost. A mutation run proved that:
    # using the width instead of the long edge, and skipping the scaled decode
    # altogether, both survived every assertion above. Neither changes a single
    # pixel of output. So the decision is a function now, and this asks it.
    check('a 2655x2655 original decodes at 64, not at 2655',
          thumb_decode_size(2655, 2655, 64) == (64, 64))
    check('a landscape original decodes with its LONG edge on the box',
          thumb_decode_size(1500, 1000, 120) == (120, 80))
    check('and a portrait one does too, so the height is the bound',
          thumb_decode_size(1000, 1500, 120) == (80, 120))
    check('the long edge, not the width: 600x1800 at 120 is 40x120',
          thumb_decode_size(600, 1800, 120) == (40, 120))
    check('a photograph already inside the box is not scaled at the decode',
          thumb_decode_size(48, 32, 120) is None)
    check('exactly the box size needs no scaled decode either',
          thumb_decode_size(120, 80, 120) is None)
    check('one pixel over the box does',
          thumb_decode_size(121, 80, 120) == (120, 79))
    check('an unreadable header asks for no scaling rather than dividing by zero',
          thumb_decode_size(0, 0, 120) is None)
    check('a zero box size is refused too', thumb_decode_size(1500, 1000, 0) is None)
    check('an extreme panorama still gives at least one pixel on the short edge',
          thumb_decode_size(12000, 400, 64) == (64, 2))
    # 80 / 12000 * 64 is 0.43, which ROUNDS TO ZERO. A QSize with a zero edge
    # is invalid and Qt would decode unscaled, so the floor is not cosmetic.
    # A mutant removing max(1, ...) survived until this case existed -- the
    # panorama above was not extreme enough to reach it.
    check('and a 150:1 panorama does not round its short edge away',
          thumb_decode_size(12000, 80, 64) == (64, 1))
    check('nor does the same shape turned on its side',
          thumb_decode_size(80, 12000, 64) == (1, 64))

    # ── A SOURCE ASSERTION, WITH THE REASON STATED ─────────────────────────
    # Whether the thumbnail is decoded at full resolution and then scaled, or
    # decoded straight to size, makes NO difference to the pixmap returned --
    # both give the same pixels. The difference is cost: the old call read a
    # whole 2655x2655 SNAP SLAPPER original to make a 64px thumbnail, for all
    # eighteen call sites. Timing assertions are flaky, so the API choice is
    # pinned where it is made.
    widgets_py = os.path.join(os.path.dirname(os.path.dirname(
        os.path.abspath(__file__))), 'coldsnap_qt', 'widgets.py')
    body = open(widgets_py, encoding='utf-8').read()
    # From the END of the docstring, not the start of the function: the
    # docstring names the old QPixmap(path) call to explain the fault, and the
    # first version of this check matched its own explanation and failed.
    body = body[body.index('def load_pixmap('):body.index('def thumb_label(')]
    body = body[body.index('    if not path or not os.path.isfile(path):'):]
    check('load_pixmap reads through QImageReader', 'QImageReader' in body)
    check('and asks it for the EXIF transform', 'setAutoTransform' in body)
    check('and decodes at the size asked for, not full resolution',
          'setScaledSize' in body)
    check('and never calls QPixmap(path), which ignores the orientation',
          'QPixmap(path)' not in body)


def _not_an_image(directory):
    path = os.path.join(directory, 'notes.txt')
    with open(path, 'w', encoding='utf-8') as handle:
        handle.write('this is not a photograph')
    return path


if __name__ == '__main__':
    main()
    if _fails:
        raise SystemExit('\n%d FAILURE(S)\n' % _fails)
    print('thumbnail orientation: PASS')

# ===== SNAPSMACK EOF =====
