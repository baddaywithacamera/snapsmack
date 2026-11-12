# -*- coding: utf-8 -*-
"""The editor's mosaic layout must stay identical to the site's.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

WHAT THIS GUARDS. coldsnap_qt/mosaic_site_layout.py is a PORT of
assets/js/ss-engine-mosaic.js. Two implementations of one algorithm can drift
the day somebody edits one of them, and a preview that has quietly stopped
matching the site is worse than no preview: Sean would only find out after the
post was published.

So this holds a digest of the port's geometry over 147 cases, captured from
the REAL engine running in a browser. Any disagreement -- a changed constant,
a reordered tie-break, a different rounding -- moves the digest and fails here.

THE GOLDEN VALUE IS NOT A GUESS. It was produced by loading
assets/js/ss-engine-mosaic.js in a browser and calling the engine's own public
entry point, window.SnapMosaic.computeLayout(), over:

    10 aspect-ratio sets  1, 2, 3, 4, 5, 6, 7, 9 and 13 photographs
    5 container widths    900, 1400, 480, 1100 and 1250
    7 mode combinations   natural / balanced / portrait / landscape, and the
                          one-left, one-top and three-across templates

350 rows, 96,826 characters of exact tile geometry to six decimal places.
The port matched the first seven sets on its first run; the last three were
added after a mutation run showed the sample was too narrow to pin four
constants, and it matched those first time too.

The widths are chosen, not arbitrary. 480 is below MOBILE_BREAKPOINT, so it
exercises the one-per-row branch. 1400 and 1250 are at or above 1200, so they
exercise enforceUsefulSize. 1100 sits just below that line, which is the only
way to catch the threshold itself moving. A test at one width would pass while
lying about the others.

IF THIS FAILS, READ THE MESSAGE. It names the JS file on purpose.
"""

import hashlib
import math
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from coldsnap_qt.mosaic_site_layout import (  # noqa: E402
    compute_layout, image_ar, _block_score)

#: SHA-256 of the canonical geometry, captured from assets/js/ss-engine-mosaic.js
#: running in a browser on 2026-11-11. Regenerate ONLY by re-running the engine,
#: never by pasting whatever the port currently produces -- that would turn the
#: drift detector into a rubber stamp.
GOLDEN_SHA256 = '8bc63af2bc5a21a9b8f525be968e1f5af16401024f87a7be4d34dc5cf9bf6608'
GOLDEN_CASES = 350
GOLDEN_CHARS = 96826

ASPECT_SETS = {
    'a': [1.5, 0.75, 1.5],
    'b': [1.5, 1.5, 0.667, 1.333, 1.0],
    'c': [1.5, 0.667, 1.333, 1.0, 1.5, 0.75, 1.777, 0.562, 1.25],
    'd': [0.8, 1.2],
    'e': [1.0],
    'f': [0.9, 1.6, 0.7, 1.4, 1.1, 0.6, 1.8],
    'g': [1.5] * 13,
    # The three sets below exist because a mutation run proved the first
    # seven did not distinguish four real constants. 'h' leads with 1.05,
    # which sits BETWEEN the 1.0 and 1.15 portrait thresholds and so pins
    # the composition branch; 'i' has a 7.5:1 area spread that straddles the
    # 6:1 balance limit; 'j' is six near-square photographs, where the
    # scoring weights decide the winner instead of the shapes doing it.
    'h': [1.05, 1.5, 0.8, 1.2],
    'i': [3.0, 0.4, 1.0, 1.0, 1.0],
    'j': [1.08, 1.12, 1.02, 1.1, 1.06, 1.14],
}
#: 480 is below MOBILE_BREAKPOINT; 1400 and 1250 are at or above the 1200
#: enforce_useful_size line; 1100 sits just below it, which is what pins
#: that threshold. 900 is the editor's own column.
WIDTHS = (900, 1400, 480, 1100, 1250)
MODES = (('natural', ''), ('natural', 'one-left'), ('natural', 'one-top'),
         ('natural', 'three-across'), ('balanced', ''),
         ('portrait', ''), ('landscape', ''))

_fails = 0


def check(label, ok):
    global _fails
    print(('PASS ' if ok else 'FAIL ') + label)
    if not ok:
        _fails += 1


def _images(ars):
    # JS Math.round is half-up; Python's round() is banker's. Match the engine.
    return [{'width': math.floor(1000 * ar + 0.5), 'height': 1000, 'src': 'p%d' % i}
            for i, ar in enumerate(ars)]


def _canonical():
    lines = []
    for name in sorted(ASPECT_SETS):
        for width in WIDTHS:
            for emphasis, template in MODES:
                out = compute_layout(_images(ASPECT_SETS[name]), width, 5,
                                     emphasis, template)
                items = ';'.join(
                    '%d,%.6f,%.6f,%.6f,%.6f' % (t['order'], t['x'], t['y'],
                                                t['width'], t['height'])
                    for t in out['items'])
                lines.append('%s|%d|%s|%s|%.6f|%s'
                             % (name, width, emphasis, template, out['height'], items))
    return '\n'.join(lines)


def test_geometry_matches_the_site_engine():
    canon = _canonical()
    digest = hashlib.sha256(canon.encode('utf-8')).hexdigest()

    check('the case set is the one the golden digest was taken over',
          len(canon.split('\n')) == GOLDEN_CASES and len(canon) == GOLDEN_CHARS)
    check('the port reproduces assets/js/ss-engine-mosaic.js EXACTLY',
          digest == GOLDEN_SHA256)
    if digest != GOLDEN_SHA256:
        raise AssertionError(
            '\nMOSAIC DRIFT. coldsnap_qt/mosaic_site_layout.py no longer agrees '
            'with\nassets/js/ss-engine-mosaic.js.\n'
            '  expected %s\n  got      %s\n'
            'One of the two was changed without the other. The editor preview and '
            'the\npublished page now disagree, which is the whole fault this port '
            'exists to\nfix. Re-capture the golden digest from the ENGINE (load it '
            'in a browser and\ncall window.SnapMosaic.computeLayout over the cases '
            'in this file) -- do not\npaste the value this port currently '
            'produces.\n' % (GOLDEN_SHA256, digest))


def test_the_properties_a_digest_cannot_explain():
    """A digest says "same" but never says WHY, so the two facts that make this
    layout different from the editor's old uniform grid are asserted in the
    open. If the digest ever has to be re-captured, these still hold the line."""
    # Within a row: equal heights, widths proportional to aspect.
    out = compute_layout(_images([1.5, 0.75, 1.5]), 900, 5, 'natural', '')
    tiles = out['items']
    check('three photographs land in one row', len({round(t['y'], 6) for t in tiles}) == 1)
    check('every tile in that row shares a height',
          len({round(t['height'], 6) for t in tiles}) == 1)
    check('the 1.5 is exactly twice the width of the 0.75',
          abs(tiles[0]['width'] - 2 * tiles[1]['width']) < 1e-6)
    check('the row fills the container exactly, gaps included',
          abs(sum(t['width'] for t in tiles) + 5 * 2 - 900) < 1e-6)

    # The editor's OLD behaviour, asserted as the thing we must not do again:
    # a uniform grid would give all three the same width.
    check('the tiles are NOT a uniform grid',
          len({round(t['width'], 6) for t in tiles}) > 1)

    # No tile may exceed the engine's derivative ceiling.
    tall = compute_layout(_images([1.0] * 6), 1400, 5, 'natural', '')
    check('no tile exceeds MAX_SECTION_HEIGHT',
          all(t['height'] <= 900 + 0.01 for t in tall['items']))

    # Aspect of a broken image falls back to 1.5, as the engine does.
    check('a zero-sized image falls back to 1.5',
          image_ar({'width': 0, 'height': 0}) == 1.5)
    check('a missing-size image falls back to 1.5', image_ar({}) == 1.5)

    # Every photograph handed in comes back out. The engine rejects any
    # composition that drops one; so must the port.
    for name, ars in ASPECT_SETS.items():
        for width in WIDTHS:
            out = compute_layout(_images(ars), width, 5, 'natural', '')
            check('set %s at %d draws all %d photographs'
                  % (name, width, len(ars)), len(out['items']) == len(ars))


def test_the_scoring_weights_directly():
    """Pin every constant in _block_score as a unit.

    A mutation run showed the end-to-end digest could NOT catch the lead-area
    weight changing from 0.35 to 0.5, and the reason is arithmetic rather than
    sampling: the score is dominated by the height penalty, which is
    abs(height - 850) * containerWidth * 0.12 -- tens of thousands, against a
    lead term in the thousands. For that weight to flip a winner you would need
    two candidates with near-identical heights whose other area terms almost
    cancel. Hunting that input by sampling is unreliable, and widening the
    golden set further would not reliably reach it.

    So it is pinned where it lives instead. The expected value below is worked
    out longhand from the engine's own formula, not copied from the port:

        tile 0  order 0  100x100  aspect 1.00  -> portrait (below 1.15)
        tile 1  order 1  200x100  aspect 2.00  -> landscape
        height 100, container 1000, natural, variant 0

        variant_order  = 0 % min(2, 3)            = 0
        variant_area   = 10000  (the order-0 tile)
        lead_area      = 10000
        portrait_max   = 10000     landscape_max  = 20000
        smallest       = 10000

        10000*4 + 10000*0.35 + 20000 + 10000      =  73500
        + 10000*0.2                               =  75500
        - abs(100 - 850) * 1000 * 0.12            = -90000
                                                  = -14500
    """
    items = [
        {'order': 0, 'width': 100.0, 'height': 100.0,
         'image': {'width': 1000, 'height': 1000}},
        {'order': 1, 'width': 200.0, 'height': 100.0,
         'image': {'width': 2000, 'height': 1000}},
    ]
    score = _block_score(items, 100.0, 1000.0, 'natural', 0)
    check('the natural score is exactly -14500 (pins 4, 0.35, 0.2, 0.12, 850)',
          abs(score - (-14500.0)) < 1e-9)

    # The other three emphasis formulas, same items, same longhand method.
    #   portrait  : portrait_max*20 + landscape_max  = 200000 + 20000
    #   landscape : landscape_max*20 + portrait_max  = 400000 + 10000
    #   balanced  : min*5 + max                      =  50000 + 20000
    # each then + smallest*0.2 (2000) - 90000.
    check('the portrait score is exactly 132000',
          abs(_block_score(items, 100.0, 1000.0, 'portrait', 0) - 132000.0) < 1e-9)
    check('the landscape score is exactly 322000',
          abs(_block_score(items, 100.0, 1000.0, 'landscape', 0) - 322000.0) < 1e-9)
    check('the balanced score is exactly -18000',
          abs(_block_score(items, 100.0, 1000.0, 'balanced', 0) - (-18000.0)) < 1e-9)

    # variant 1 moves the x4 weight off the lead and onto the second tile:
    #   variant_area = 20000 -> 80000 + 3500 + 30000 + 2000 - 90000 = 25500
    check('variant 1 scores exactly 25500, so the x4 weight follows the variant',
          abs(_block_score(items, 100.0, 1000.0, 'natural', 1) - 25500.0) < 1e-9)


if __name__ == '__main__':
    test_geometry_matches_the_site_engine()
    test_the_properties_a_digest_cannot_explain()
    test_the_scoring_weights_directly()
    if _fails:
        raise SystemExit('\n%d FAILURE(S)\n' % _fails)
    print('mosaic site layout: PASS')

# ===== SNAPSMACK EOF =====
