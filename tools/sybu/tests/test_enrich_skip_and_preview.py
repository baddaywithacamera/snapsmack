"""SYBU 0.7.72 — two things Sean hit on 2026-09-27.

1. ENRICH SELECTED re-enriched rows the table already showed as "enriched":
   the table used title-or-TAGS, the enricher title-or-CAPTION. One rule now,
   and already-enriched rows are skipped unless redo=True.
2. The preview went blank after posting: the photo had moved to completed/
   (sometimes renamed "name (2).jpg") and thumb() only looked in upload/.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys
import tempfile
import unittest
from types import SimpleNamespace

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import sybu_core
from manifest_parser import ManifestEntry


def _engine(entries, statuses=None):
    eng = sybu_core.Engine()
    eng.entries = entries
    eng.rowstate = [{'selected': True, 'status': (statuses or {}).get(i, 'pending'), 'message': ''}
                    for i in range(len(entries))]
    return eng


class _FakeGemini:
    def __init__(self):
        self.calls = []

    def is_available(self):
        return True

    def enrich_batch(self, **kw):
        self.calls.append(kw)


class EnrichSkipTests(unittest.TestCase):
    def setUp(self):
        self.fake = _FakeGemini()
        self._orig = sybu_core._gemini
        sybu_core._gemini = lambda: self.fake

    def tearDown(self):
        sybu_core._gemini = self._orig

    def _run(self, eng, redo=False):
        eng.ensure_recovery = lambda folder: None
        eng.categories = lambda: []
        eng.albums = lambda: []
        eng.site_data = SimpleNamespace()
        eng.image_folder = tempfile.gettempdir()
        eng.start_op = lambda name, target: target(SimpleNamespace(events=[], cancel=None)) or {}
        eng.enrich_start('key', '', redo=redo)
        return self.fake.calls[-1]

    def test_tags_only_row_counts_as_enriched_and_is_skipped(self):
        entries = [ManifestEntry(file='a.jpg', tags='#beets #red'),        # the case that was redone
                   ManifestEntry(file='b.jpg', caption='a caption'),
                   ManifestEntry(file='c.jpg'),
                   ManifestEntry(file='d.jpg')]
        eng = _engine(entries, statuses={3: 'enriched'})                   # restored from last run
        self.assertEqual(eng.selected_already_enriched(), 3)
        call = self._run(eng)
        self.assertEqual([e.file for e in call['entries']], ['c.jpg'])
        self.assertTrue(call['skip_filled'])

    def test_redo_sends_everything_ticked(self):
        entries = [ManifestEntry(file='a.jpg', tags='#x'), ManifestEntry(file='b.jpg')]
        eng = _engine(entries)
        call = self._run(eng, redo=True)
        self.assertEqual([e.file for e in call['entries']], ['a.jpg', 'b.jpg'])
        self.assertFalse(call['skip_filled'])

    def test_all_done_is_a_plain_message_not_a_paid_run(self):
        eng = _engine([ManifestEntry(file='a.jpg', caption='done')])
        with self.assertRaises(RuntimeError) as err:
            self._run(eng)
        self.assertIn('already enriched', str(err.exception))
        self.assertEqual(self.fake.calls, [])

    def test_unticked_rows_are_not_counted(self):
        eng = _engine([ManifestEntry(file='a.jpg', caption='done'), ManifestEntry(file='b.jpg')])
        eng.rowstate[0]['selected'] = False
        self.assertEqual(eng.selected_already_enriched(), 0)


class PreviewAfterPostingTests(unittest.TestCase):
    def _jpeg(self, path):
        from PIL import Image
        Image.new('RGB', (40, 30), (200, 40, 40)).save(path, 'JPEG')

    def test_preview_follows_renamed_archive(self):
        with tempfile.TemporaryDirectory() as root:
            upload = os.path.join(root, 'upload'); completed = os.path.join(root, 'completed')
            os.makedirs(upload); os.makedirs(completed)
            moved = os.path.join(completed, 'beets (2).jpeg'); self._jpeg(moved)
            eng = _engine([ManifestEntry(file='beets.jpeg')], statuses={0: 'ok'})
            eng.image_folder = upload
            eng.rowstate[0]['archived_path'] = moved
            self.assertTrue(eng.thumb(0, 60).startswith('data:image/jpeg;base64,'))

    def test_preview_finds_plain_move_without_a_recorded_path(self):
        with tempfile.TemporaryDirectory() as root:
            upload = os.path.join(root, 'upload'); completed = os.path.join(root, 'completed')
            os.makedirs(upload); os.makedirs(completed)
            self._jpeg(os.path.join(completed, 'leaves.jpeg'))
            eng = _engine([ManifestEntry(file='leaves.jpeg')], statuses={0: 'ok'})
            eng.image_folder = upload
            self.assertTrue(eng.thumb(0, 60).startswith('data:image/jpeg;base64,'))

    def test_archive_records_where_the_file_went(self):
        from poster import PostResult, _archive_success
        with tempfile.TemporaryDirectory() as root:
            upload = os.path.join(root, 'upload'); completed = os.path.join(root, 'completed')
            os.makedirs(upload); os.makedirs(completed)
            open(os.path.join(upload, 'p.jpg'), 'wb').write(b'x')
            open(os.path.join(completed, 'p.jpg'), 'wb').write(b'old')
            result = PostResult(SimpleNamespace(file='p.jpg'), True, 'Posted')
            _archive_success(result, upload, completed)
            self.assertEqual(os.path.basename(result.archived_path), 'p (2).jpg')


if __name__ == '__main__':
    unittest.main()

# ===== SNAPSMACK EOF =====
