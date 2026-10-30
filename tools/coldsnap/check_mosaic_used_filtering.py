"""SS-1049: photographs already in a mosaic must not be offered for the next one.

    python tools/coldsnap/check_mosaic_used_filtering.py

The rule is derived, never stored: the used set is read out of the post body
every time, so nothing is marked, nothing is written, and nothing leaves the
bucket or the archive.

TakeMode._mosaic_used_indices touches only self._bucket and self.body, so it is
exercised here against fabricated bodies. The cases that earn their place are
the two editor faces storing membership differently, the BARE [mosaic] that
means every photograph, and a mosaic nested inside a columns block.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys

_HERE = os.path.dirname(os.path.abspath(__file__))
for _p in (_HERE, os.path.abspath(os.path.join(_HERE, '..', '_shared'))):
    if _p not in sys.path:
        sys.path.insert(0, _p)

os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')

from PySide6.QtWidgets import QApplication    # noqa: E402

QApplication([])

from coldsnap_qt.mode_take import TakeMode    # noqa: E402


class FakeImage:
    def __init__(self, index):
        self.asset_uuid = 'uuid-%02d' % index


class FakeBody:
    def __init__(self, blocks):
        self._blocks = blocks

    def authoring_blocks(self):
        return self._blocks


class Stub:
    """Only the two attributes the method actually reads."""
    def __init__(self, bucket_size, blocks):
        self._bucket = [FakeImage(i) for i in range(bucket_size)]
        self.body = FakeBody(blocks)


def used(bucket_size, blocks, keep=()):
    return TakeMode._mosaic_used_indices(Stub(bucket_size, blocks), keep)


passed = 0
failed = 0


def check(label, got, want):
    global passed, failed
    if got == want:
        passed += 1
        print('  ok    %-58s %s' % (label, sorted(got)))
    else:
        failed += 1
        print('  FAIL  %-58s got %s want %s' % (label, sorted(got), sorted(want)))


print('TWIGGY face: [mosaic=...] markers carry 1-BASED bucket positions')
check('one mosaic using photos 1,4,7 marks indices 0,3,6',
      used(10, [{'type': 'mosaic', 'order': [1, 4, 7], 'layout': 'rows'}]),
      {0, 3, 6})
check('two mosaics union their members',
      used(10, [{'type': 'mosaic', 'order': [1, 2], 'layout': 'rows'},
                {'type': 'mosaic', 'order': [5], 'layout': 'rows'}]),
      {0, 1, 4})
check('an out-of-range position is ignored rather than crashing',
      used(3, [{'type': 'mosaic', 'order': [1, 99], 'layout': 'rows'}]),
      {0})

print('\nBIGGIE face: canvas mosaics carry asset UUIDs instead')
check('uuids resolve to their bucket positions',
      used(5, [{'type': 'mosaic', 'order': ['uuid-01', 'uuid-03'], 'layout': 'rows'}]),
      {1, 3})
check('a uuid that is not in this bucket is ignored',
      used(5, [{'type': 'mosaic', 'order': ['uuid-99'], 'layout': 'rows'}]),
      set())
check('the two faces can coexist in one body',
      used(8, [{'type': 'mosaic', 'order': ['uuid-00'], 'layout': 'rows'},
               {'type': 'mosaic', 'order': [4], 'layout': 'rows'}]),
      {0, 3})

print('\nTHE MOSAIC BEING EDITED keeps its own photographs available')
check('its members are excluded, so the dialog can still show them ticked',
      used(10, [{'type': 'mosaic', 'order': [1, 2, 3], 'layout': 'rows'},
                {'type': 'mosaic', 'order': [8], 'layout': 'rows'}],
           keep=[0, 1, 2]),
      {7})
check('a photograph shared by two mosaics is not stranded while editing one',
      used(10, [{'type': 'mosaic', 'order': [1], 'layout': 'rows'},
                {'type': 'mosaic', 'order': [1, 5], 'layout': 'rows'}],
           keep=[0]),
      {4})

print('\nA BARE [mosaic] means EVERY photograph, so it claims nothing')
# biggie.py:46. Counting it as "all used" would hand him an empty picker.
check('bare mosaic alone leaves the whole pool offered',
      used(6, [{'type': 'mosaic'}]),
      set())
check('bare mosaic does not wipe out a real mosaic recorded beside it',
      used(6, [{'type': 'mosaic'}, {'type': 'mosaic', 'order': [2], 'layout': 'rows'}]),
      {1})

print('\nNesting and junk')
check('a mosaic inside a columns block is still found',
      used(6, [{'type': 'columns', 'ratio': 'equal',
                'cols': [[{'type': 'para', 'text': 'hello'}],
                         [{'type': 'mosaic', 'order': [3], 'layout': 'rows'}]]}]),
      {2})
check('a body with no mosaics marks nothing',
      used(6, [{'type': 'para', 'text': 'just words'}]),
      set())
check('an empty body marks nothing',
      used(6, []),
      set())
check('a body that cannot be read marks nothing rather than blocking him',
      TakeMode._mosaic_used_indices(
          type('Broken', (), {'_bucket': [], 'body': None})(), []),
      set())

print('\n  %d passed, %d failed' % (passed, failed))
if failed:
    print('Mosaic used-image filtering: FAIL (%d).' % failed)
    raise SystemExit(1)
print('Mosaic used-image filtering: PASS (%d assertions).' % passed)
# ===== SNAPSMACK EOF =====
