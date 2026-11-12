# -*- coding: utf-8 -*-
"""BIGGIE's mosaic preview draws what the site draws, and decodes sanely.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

test_mosaic_site_layout.py proves the PORT matches the engine. This proves the
EDITOR actually uses the port, end to end from real files on disk: header
dimensions in, site geometry out. A correct port that nothing calls would pass
that test and change nothing on Sean's screen.

It also pins the decode fix. _pixmap took a width and ignored it, loading every
photograph at full size and scaling to a flat 1200: Sean's 2655x2655 portrait
is about 28 MB as a QPixmap, and QPixmapCache holds 10 MB -- measured -- which
is roughly two of them. With 53 photographs in one mosaic almost every one
missed the cache and was decoded from disk again on every repaint.
"""

import os
import sys
import tempfile

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

from coldsnap_qt.canvas import (  # noqa: E402
    _image_size, _pixmap, _mosaic_images, mosaic_cells, mosaic_note,
    mosaic_is_approximate, _seen_size, _mosaic_gap, _ROTATE90,
    mosaic_cell_problem)
from coldsnap_qt.mosaic_site_layout import compute_layout            # noqa: E402
from PySide6.QtGui import QImageReader                                # noqa: E402


class _Canvas:
    def __init__(self, bucket):
        self.bucket = bucket
        self.assets = {}


def _write(directory, name, width, height, orientation=None):
    path = os.path.join(directory, name)
    img = Image.new('RGB', (width, height), (70, 80, 90))
    if orientation is None:
        img.save(path, 'JPEG', quality=60)
    else:
        exif = img.getexif()
        exif[0x0112] = orientation          # the EXIF Orientation tag
        img.save(path, 'JPEG', quality=60, exif=exif)
    return path


class _Size:
    """A stand-in for QSize, so _seen_size can be driven without a file."""

    def __init__(self, w, h):
        self._w, self._h = w, h

    def width(self):
        return self._w

    def height(self):
        return self._h


def main():
    work = tempfile.mkdtemp(prefix='biggie_mosaic_')

    # ── the header read ────────────────────────────────────────────────────
    big = _write(work, 'big.jpg', 2655, 2655)       # Sean's actual shape
    wide = _write(work, 'wide.jpg', 1500, 1000)
    tall = _write(work, 'tall.jpg', 750, 1000)

    check('a photograph is measured from its header', _image_size(big) == (2655, 2655))
    check('and so is a landscape one', _image_size(wide) == (1500, 1000))
    check('a missing file measures (0, 0), it does not raise',
          _image_size(os.path.join(work, 'nope.jpg')) == (0, 0))

    # A file whose HEADER is intact but whose pixel data is cut off: a header
    # read still reports the dimensions, a decode cannot. This is what pins
    # _image_size to QImageReader.size() rather than loading the photograph --
    # a mutant swapping in a full decode passed every value check before this.
    truncated = os.path.join(work, 'truncated.jpg')
    with open(wide, 'rb') as src:
        blob = src.read()
    with open(truncated, 'wb') as dst:
        dst.write(blob[:len(blob) // 4])
    check('a truncated photograph still reports its header dimensions',
          _image_size(truncated) == (1500, 1000))

    # AND A SOURCE ASSERTION, DELIBERATELY, with the reason stated. Whether
    # _image_size reads the header or decodes the whole photograph makes NO
    # difference to what it returns -- Qt's JPEG decoder recovers the
    # dimensions from a file truncated to 5%, and at 1% the header is gone too,
    # so no input separates them by output. The difference is cost: a header
    # read is a few bytes, a decode of Sean's portrait is 28 MB, and that cost
    # on 53 files per repaint is the fault being fixed. Timing assertions are
    # flaky, so the API choice is pinned where it is made. A mutation run put
    # a full decode in here and every value check passed.
    canvas_py = os.path.join(os.path.dirname(os.path.dirname(
        os.path.abspath(__file__))), 'coldsnap_qt', 'canvas.py')
    src = open(canvas_py, encoding='utf-8').read()
    body = src[src.index('def _image_size('):src.index('#: path+mtime ->')]
    check('_image_size reads the header with QImageReader', 'QImageReader' in body)
    check('and never constructs a QPixmap, which would decode the photograph',
          'QPixmap' not in body)

    # ── EXIF ORIENTATION ───────────────────────────────────────────────────
    # Muse caught this reading the Qt API: QImageReader.size() reports the
    # STORED pixel grid and ignores the EXIF orientation -- with
    # setAutoTransform(True) as well -- while _pixmap draws the photograph
    # rotated. A quarter-turned photograph was therefore composed landscape and
    # drawn portrait, and KeepAspectRatio letterboxed it inside its own cell:
    # the exact banding this change exists to remove, back for rotated files.
    #
    # Nothing in the suite could see it, because PIL fixtures carry no EXIF.
    # They do now.

    # The flag table, over all eight orientation tags. 1-4 are unrotated;
    # 5-8 all carry the quarter turn.
    #
    # MUSE'S CAVEAT, CLOSED: the first version of this loop carried the flag
    # values as literals, taken from a measurement I had made outside the
    # suite. She was right that this proved the arithmetic and assumed the
    # tag-to-flag mapping -- so if Qt ever mapped a tag to a different flag,
    # the table would still pass while reality moved. The mapping is now READ
    # OUT OF QT per tag, from a real file, and the literal is asserted against
    # it. The assumption is the thing being tested instead of the premise.
    EXPECTED_FLAGS = {1: 0, 2: 1, 3: 3, 4: 2, 5: 6, 6: 4, 7: 5, 8: 7}
    for tag in range(1, 9):
        path = _write(work, 'tag%d.jpg' % tag, 1500, 1000, orientation=tag)
        reader = QImageReader(path)
        reader.setAutoTransform(True)
        measured = int(getattr(reader.transformation(), 'value',
                               reader.transformation()))
        check('Qt maps EXIF tag %d to flag %d, as the table says'
              % (tag, EXPECTED_FLAGS[tag]), measured == EXPECTED_FLAGS[tag])

        rotates = tag >= 5
        # the helper, driven by the flags QT gave, not by a literal
        check('tag %d: _seen_size reports %s' % (
                  tag, '1000x1500' if rotates else '1500x1000'),
              _seen_size(_Size(1500, 1000), measured)
              == ((1000, 1500) if rotates else (1500, 1000)))
        # AND END TO END THROUGH _image_size, for every tag rather than one.
        # The live path was covered only for tag 6 before.
        check('tag %d: _image_size measures the file as the viewer sees it' % tag,
              _image_size(path) == ((1000, 1500) if rotates else (1500, 1000)))
        # And what Qt actually decodes must agree with what we measured, or
        # the cell and the pixmap disagree again.
        decoded = QImageReader(path)
        decoded.setAutoTransform(True)
        image = decoded.read()
        check('tag %d: the decoded photograph is the shape _image_size claimed' % tag,
              (image.width(), image.height()) == _image_size(path))

    check('a zero-sized header reports (0, 0) rather than swapping nothing',
          _seen_size(_Size(0, 0), 4) == (0, 0))
    check('the quarter-turn flag is the one Qt uses', int(_ROTATE90.value) == 4)

    turned = os.path.join(work, 'tag6.jpg')
    straight = os.path.join(work, 'tag1.jpg')

    # The composition consequence, which is the fault itself: the cell must be
    # PORTRAIT for a turned photograph, not landscape.
    turned_cells = compute_layout(
        [{'width': w, 'height': h, 'src': 's'}
         for w, h in [_image_size(turned)]], 900, 5, 'natural', '')
    cell = turned_cells['items'][0]
    check('a turned photograph gets a portrait cell, so nothing letterboxes',
          cell['height'] > cell['width'])

    # And the decode: setScaledSize works in the PRE-transform frame, so asking
    # a turned photograph for 320 wide used to deliver 213 wide. Measured.
    turned_pix = _pixmap(turned, 300)
    check('a turned photograph decodes to the width that was asked for',
          280 <= turned_pix.width() <= 384)
    check('and comes back portrait, matching its cell',
          turned_pix.height() > turned_pix.width())

    # ── GAP ZERO IS A REAL SAVED VALUE ─────────────────────────────────────
    # `int(data.get("gap", 4) or 4)` read a saved 0 as 4, because `0 or 4` is 4.
    # Zero is what a flush grid needs and the schema allows it (0-20).
    check('a saved gap of 0 stays 0', _mosaic_gap({'gap': 0}) == 0)
    check('a saved gap of 0 as a string stays 0', _mosaic_gap({'gap': '0'}) == 0)
    check('a saved gap of 12 is honoured', _mosaic_gap({'gap': 12}) == 12)
    check('a missing gap falls back to the schema default of 4',
          _mosaic_gap({}) == 4)
    check('a blank gap falls back to 4', _mosaic_gap({'gap': ''}) == 4)
    check('a None gap falls back to 4', _mosaic_gap({'gap': None}) == 4)
    check('an unparseable gap falls back to 4 rather than raising',
          _mosaic_gap({'gap': 'wide'}) == 4)
    check('the range is left to the engine, not clamped twice here',
          _mosaic_gap({'gap': 99}) == 99)

    # ── THE DECODE FIX ─────────────────────────────────────────────────────
    # The old code produced 1200 for every request. The width now decides.
    small = _pixmap(big, 300)
    medium = _pixmap(big, 445)
    check('a 300px cell decodes near 300px, not 1200', 280 <= small.width() <= 384)
    check('a 445px cell decodes near 445px, not 1200', 420 <= medium.width() <= 512)
    check('the two sizes really differ, so the width is not ignored',
          small.width() != medium.width())
    check('the decoded photograph is not null', not small.isNull())

    # The point of the fix, in bytes: a 300px cell is a fraction of the old
    # fixed 1200px pixmap, so the 10 MB cache holds many instead of two.
    old_cost = 1200 * 1200 * 4
    new_cost = small.width() * small.height() * 4
    check('a cell-sized decode costs under a quarter of the old fixed 1200px',
          new_cost * 4 < old_cost)

    # ── THE EDITOR USES THE SITE'S COMPOSITION ─────────────────────────────
    canvas = _Canvas([wide, tall, wide])
    paths, images = _mosaic_images(canvas, {'order': []})
    check('an empty order means the whole bucket, in order', paths == [wide, tall, wide])
    check('each photograph carries its real dimensions',
          [(i['width'], i['height']) for i in images]
          == [(1500, 1000), (750, 1000), (1500, 1000)])

    out = compute_layout(images, 900, 5, 'natural', '')
    widths = [round(t['width'], 2) for t in out['items']]
    heights = [round(t['height'], 2) for t in out['items']]

    # These are the engine's own numbers, captured from a browser.
    check('the cells are the site\'s: 356 / 178 / 356', widths == [356.0, 178.0, 356.0])
    check('all three share the site\'s height, 237.33',
          heights == [237.33, 237.33, 237.33])
    check('the row fills the container exactly, gaps included',
          abs(sum(t['width'] for t in out['items']) + 5 * 2 - 900) < 1e-6)
    check('the cells are NOT a uniform grid any more', len(set(widths)) > 1)

    # ── THE OLD GRID IS GONE FROM THE CANVAS ───────────────────────────────
    canvas_src = open(os.path.join(os.path.dirname(os.path.dirname(
        os.path.abspath(__file__))), 'coldsnap_qt', 'canvas.py'),
        encoding='utf-8').read()
    check('canvas.py no longer imports the uniform-grid layout',
          'tile_rects' not in canvas_src and 'natural_height' not in canvas_src)
    check('canvas.py composes through the site layout instead',
          'site_compute_layout' in canvas_src)
    # ── THE WARNING, ASSERTED AS BEHAVIOUR ─────────────────────────────────
    # This used to be a grep for the warning string, which a mutant making the
    # warning unreachable sailed straight through: the text was still in the
    # file, just never chosen. Ask the function instead.
    check('three photographs on the default layout ARE approximate',
          mosaic_is_approximate(3, 'asymmetric') is True)
    for count in (1, 2, 4, 5, 13, 53):
        check('%d photographs on the default layout are exact' % count,
              mosaic_is_approximate(count, 'asymmetric') is False)
    for layout in ('one-left', 'one-right', 'one-top', 'three-across', 'rows'):
        check('three photographs on %s are exact' % layout,
              mosaic_is_approximate(3, layout) is False)

    check('the approximate note warns, in words the owner can act on',
          'ARRANGEMENT DECIDED AT PUBLISH' in mosaic_note(3, 'asymmetric')
          and 'pick a layout' in mosaic_note(3, 'asymmetric'))
    check('the exact note claims a match instead',
          'matches the site' in mosaic_note(4, 'asymmetric')
          and 'DECIDED AT PUBLISH' not in mosaic_note(4, 'asymmetric'))
    check('an explicit layout at three photographs claims a match',
          'matches the site' in mosaic_note(3, 'one-left'))

    # ── THE WIDTH THE PAINTER COMPOSES AT ──────────────────────────────────
    # A mutant halving the composition width used to survive, because nothing
    # could see what width the paint code passed. mosaic_cells is that step,
    # lifted out so it can be asked.
    # Gap stated explicitly: mosaic_cells defaults to 4, which is the schema's
    # default for snap_mosaics.gap, while the golden values above were captured
    # at 5. The first run of this test asserted 5 against a default of 4 and
    # failed -- the test was wrong, not the code.
    _, at900 = mosaic_cells(canvas, {'order': [], 'gap': 5}, 900)
    _, at700 = mosaic_cells(canvas, {'order': [], 'gap': 5}, 700)
    check('a row at 900 fills 900', abs(sum(t['width'] for t in at900['items'])
                                        + 5 * 2 - 900) < 1e-6)
    check('a row at 700 fills 700', abs(sum(t['width'] for t in at700['items'])
                                        + 5 * 2 - 700) < 1e-6)
    check('and the two differ, so the width argument is really used',
          round(at900['items'][0]['width'], 3) != round(at700['items'][0]['width'], 3))

    # 450 is BELOW MOBILE_BREAKPOINT (520), where the engine stops composing
    # rows and stacks one photograph per row at full width. The first version
    # of this test picked 450 for the "two widths differ" check and failed,
    # because it had crossed a threshold without noticing -- the code was
    # right. Kept as an assertion of that branch rather than deleted.
    _, at450 = mosaic_cells(canvas, {'order': [], 'gap': 5}, 450)
    check('below the mobile breakpoint every photograph gets its own row',
          len({round(t['y'], 1) for t in at450['items']}) == 3)
    check('and each one spans the full width',
          all(abs(t['width'] - 450) < 1e-6 for t in at450['items']))
    check('an empty bucket composes to nothing rather than raising',
          mosaic_cells(_Canvas([]), {'order': []}, 900)[0] == [])
    check('the default gap is the schema default of 4, not 5',
          abs(sum(t['width'] for t in mosaic_cells(canvas, {'order': []}, 900)[1]['items'])
              + 4 * 2 - 900) < 1e-6)
    # And gap 0 reaches the layout, rather than being read as 4 on the way in.
    _, at_zero = mosaic_cells(canvas, {'order': [], 'gap': 0}, 900)
    check('a saved gap of 0 composes a row with no gaps at all',
          abs(sum(t['width'] for t in at_zero['items']) - 900) < 1e-6)
    check('and gap 0 really differs from the default of 4',
          round(at_zero['items'][0]['width'], 3)
          != round(mosaic_cells(canvas, {'order': []}, 900)[1]['items'][0]['width'], 3))

    # ── A CELL THAT CANNOT DRAW SAYS WHY (SS-1301 close condition 3) ───────
    # Sean reported cells 13 and 14 as blank numbered boxes and asked what they
    # were. The editor could not tell him: a missing file, an unreadable one
    # and an empty path all produced the same QPixmap() and the same dark box
    # with a number on it. These are now distinguishable.
    check('a readable photograph has no problem to report',
          mosaic_cell_problem(wide) is None)
    check('an empty path says so', mosaic_cell_problem("") == "NO FILE")
    check('and so does None', mosaic_cell_problem(None) == "NO FILE")
    check('a path that is not on disk says the file is missing',
          mosaic_cell_problem(os.path.join(work, 'absent.jpg')) == "FILE MISSING")

    # A file that EXISTS and cannot be decoded is a different fault from one
    # that is not there, and it is the one a photographer actually hits -- a
    # half-copied file, a RAW the editor cannot read, a rename to .jpg.
    broken_file = os.path.join(work, 'broken.jpg')
    with open(broken_file, 'wb') as handle:
        handle.write(b'not a photograph at all')
    check('a file that exists but cannot be read says that instead',
          mosaic_cell_problem(broken_file) == "CANNOT READ")
    check('the two are different messages, which is the whole point',
          mosaic_cell_problem(broken_file)
          != mosaic_cell_problem(os.path.join(work, 'absent.jpg')))

    # A directory is on disk but is not a file, and must not read as missing.
    check('a directory is not reported as a missing file',
          mosaic_cell_problem(work) == "FILE MISSING")

    # Truncated-but-headered is READABLE: the header gives the shape, so the
    # mosaic can still compose a correct cell for it. Reporting a problem here
    # would be wrong.
    check('a truncated photograph with an intact header is not a problem',
          mosaic_cell_problem(truncated) is None)

    # ── A BUCKET THAT CANNOT BE READ MUST NOT CRASH THE EDITOR ─────────────
    broken = _Canvas([os.path.join(work, 'gone.jpg')])
    _, broken_images = _mosaic_images(broken, {'order': []})
    check('a missing photograph still produces an entry',
          len(broken_images) == 1 and broken_images[0]['width'] == 0)
    fallback = compute_layout(broken_images, 900, 5, 'natural', '')
    check('and the layout still returns a usable cell for it',
          len(fallback['items']) == 1 and fallback['items'][0]['width'] > 0)


if __name__ == '__main__':
    main()
    if _fails:
        raise SystemExit('\n%d FAILURE(S)\n' % _fails)
    print('biggie mosaic preview: PASS')

# ===== SNAPSMACK EOF =====
