"""SS-1171 — SYBU can answer "does Google Drive work" with an empty queue.

The owner's report, 0.7.73: Validate refuses an empty queue. It did, and worse,
it was the wrong button for the question. Engine.validate() raises
"Connect first." without a blog and "Load or scan images first." without
entries, and then only checks the MANIFEST -- it never asks Drive anything. The
only Drive signal was drive_status(), which returned
{'connected': drive_service is not None}: true the moment authenticate()
returned an object, so a dead token and a deleted folder both read as connected
and the owner found out at publish time.

drive_check() is deliberately independent of the blog, the scanned folder and
the queue, because "will my originals upload" is not a question about the
queue. It is read-only: one files().get on the folder, no upload, no write.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys
import unittest

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import sybu_core
import drive as drive_module


class _Resp:
    def __init__(self, status):
        self.status = status


class _ApiError(Exception):
    """Shaped like googleapiclient.errors.HttpError: carries resp.status."""

    def __init__(self, status, message):
        super().__init__(message)
        self.resp = _Resp(status)


class _Get:
    def __init__(self, outcome):
        self._outcome = outcome

    def execute(self, num_retries=0):
        if isinstance(self._outcome, Exception):
            raise self._outcome
        return self._outcome


class _Files:
    def __init__(self, outcome):
        self._outcome = outcome
        self.asked = []

    def get(self, fileId=None, fields=None):
        self.asked.append({'fileId': fileId, 'fields': fields})
        return _Get(self._outcome)

    # A probe must never reach these. If it does, the test fails loudly.
    def create(self, *a, **k):
        raise AssertionError('probe_folder attempted a write (files().create)')

    def update(self, *a, **k):
        raise AssertionError('probe_folder attempted a write (files().update)')


class _Service:
    def __init__(self, outcome):
        if isinstance(outcome, dict) and outcome.get('mimeType') == 'application/vnd.google-apps.folder':
            outcome = dict(outcome)
            outcome.setdefault('capabilities', {'canAddChildren': True})
        self._files = _Files(outcome)

    def about(self):
        class About:
            def get(self, fields=None):
                return _Get({'user': {'emailAddress': 'owner@example.test'},
                             'storageQuota': {'limit': '1000', 'usage': '200'}})
        return About()

    def files(self):
        return self._files


FOLDER = 'application/vnd.google-apps.folder'


def _engine(service, *, enabled=True, folder='folder-123'):
    eng = sybu_core.Engine()
    eng.drive_service = service
    eng.config['drive_enabled'] = enabled
    eng.config['drive_folder_id'] = folder
    # The point of the ticket: NOTHING below is set.
    eng.entries = []
    eng.client = None
    eng.site_data = None
    return eng


class DriveCheckIsQueueIndependent(unittest.TestCase):

    def test_the_old_button_really_does_refuse_an_empty_queue(self):
        """The reported defect, pinned so a 'fix' cannot quietly re-break it."""
        eng = _engine(_Service({'id': 'folder-123', 'name': 'Originals',
                                'mimeType': FOLDER}))
        with self.assertRaises(RuntimeError) as caught:
            eng.validate('', '')
        # Without a blog it stops at the connection; that is the message the
        # owner saw instead of anything about Drive.
        self.assertIn('Connect', str(caught.exception))

    def test_drive_check_works_with_no_queue_no_blog_no_scan(self):
        eng = _engine(_Service({'id': 'folder-123', 'name': 'Originals',
                                'mimeType': FOLDER}))
        out = eng.drive_check()
        self.assertTrue(out['ok'], out)
        self.assertEqual(out['name'], 'Originals')
        self.assertEqual(out['folder_id'], 'folder-123')
        self.assertEqual(out['reason'], '')

    def test_it_asks_drive_read_only_and_about_the_folder(self):
        svc = _Service({'id': 'f', 'name': 'n', 'mimeType': FOLDER})
        _engine(svc).drive_check('f')
        self.assertEqual(len(svc.files().asked), 1)
        self.assertEqual(svc.files().asked[0]['fileId'], 'f')

    def test_a_dead_token_is_reported_as_auth_not_as_connected(self):
        eng = _engine(_Service(_ApiError(401, 'Invalid Credentials')))
        out = eng.drive_check()
        self.assertFalse(out['ok'])
        self.assertEqual(out['reason'], 'auth')

    def test_a_refused_token_403_is_also_auth(self):
        eng = _engine(_Service(_ApiError(403, 'insufficient permissions')))
        self.assertEqual(eng.drive_check()['reason'], 'auth')

    def test_a_missing_folder_is_reported_as_folder_not_as_auth(self):
        eng = _engine(_Service(_ApiError(404, 'File not found: folder-123')))
        out = eng.drive_check()
        self.assertFalse(out['ok'])
        self.assertEqual(out['reason'], 'folder')

    def test_a_trashed_folder_is_not_ok(self):
        eng = _engine(_Service({'id': 'f', 'name': 'Old', 'mimeType': FOLDER,
                                'trashed': True}))
        self.assertEqual(eng.drive_check()['reason'], 'folder')

    def test_a_file_id_is_not_a_folder(self):
        eng = _engine(_Service({'id': 'f', 'name': 'photo.jpg',
                                'mimeType': 'image/jpeg'}))
        self.assertEqual(eng.drive_check()['reason'], 'not_a_folder')

    def test_no_service_is_reported_before_any_api_call(self):
        eng = _engine(None)
        self.assertEqual(eng.drive_check()['reason'], 'no_service')

    def test_no_folder_id_anywhere_is_its_own_answer(self):
        eng = _engine(_Service({'id': 'f', 'name': 'n', 'mimeType': FOLDER}),
                      folder='')
        self.assertEqual(eng.drive_check('')['reason'], 'no_folder')

    def test_drive_switched_off_is_said_plainly(self):
        eng = _engine(_Service({'id': 'f', 'name': 'n', 'mimeType': FOLDER}),
                      enabled=False)
        self.assertEqual(eng.drive_check()['reason'], 'disabled')

    def test_it_falls_back_to_the_configured_folder(self):
        """A publish uses the configured id, so the check must too."""
        svc = _Service({'id': 'cfg', 'name': 'Configured', 'mimeType': FOLDER})
        out = _engine(svc, folder='cfg').drive_check('')
        self.assertEqual(out['folder_id'], 'cfg')
        self.assertEqual(svc.files().asked[0]['fileId'], 'cfg')

    def test_drive_status_alone_cannot_tell_a_dead_token_apart(self):
        """Why drive_check exists: the old signal says connected either way."""
        dead = _engine(_Service(_ApiError(401, 'Invalid Credentials')))
        self.assertTrue(dead.drive_status()['connected'])
        self.assertFalse(dead.drive_check()['ok'])


class TheUiOffersItWhereTheQuestionIsAsked(unittest.TestCase):

    def setUp(self):
        self.src = open(os.path.join(HERE, 'sybu_qt.py'),
                        encoding='utf-8').read()

    def test_a_check_drive_button_exists_and_calls_the_engine(self):
        self.assertIn('"Check Drive"', self.src)
        self.assertIn('self._drive_check', self.src)
        self.assertIn('self.engine.drive_check(', self.src)

    def test_it_sits_with_the_drive_checkbox_not_with_post(self):
        row = self.src.partition('Attach Google Drive originals')[2]
        self.assertLess(row.index('Check Drive'), row.index('POST'))

    def test_the_queue_button_says_which_thing_it_validates(self):
        self.assertIn('"Validate queue"', self.src)
        self.assertNotIn('QPushButton("Validate")', self.src)

    def test_the_publish_preflight_is_untouched(self):
        self.assertIn('self.engine.post_preflight(', self.src)


if __name__ == '__main__':
    unittest.main()

# ===== SNAPSMACK EOF =====
