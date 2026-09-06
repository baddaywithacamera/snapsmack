import os
import sys
import tempfile
import unittest
from types import SimpleNamespace

HERE = os.path.dirname(os.path.dirname(__file__))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

from poster import PostResult, _archive_success


class CompletedMoveTests(unittest.TestCase):
    def test_success_moves_without_overwriting(self):
        with tempfile.TemporaryDirectory() as root:
            upload = os.path.join(root, 'upload')
            completed = os.path.join(root, 'completed')
            os.makedirs(upload)
            os.makedirs(completed)
            open(os.path.join(completed, 'photo.jpg'), 'wb').write(b'old')
            open(os.path.join(upload, 'photo.jpg'), 'wb').write(b'new')
            result = PostResult(SimpleNamespace(file='photo.jpg'), True, 'Posted')
            target = _archive_success(result, upload, completed)
            self.assertEqual(os.path.basename(target), 'photo (2).jpg')
            self.assertFalse(os.path.exists(os.path.join(upload, 'photo.jpg')))
            self.assertIn('moved to completed', result.message)

    def test_failed_post_stays_in_upload(self):
        with tempfile.TemporaryDirectory() as root:
            upload = os.path.join(root, 'upload')
            completed = os.path.join(root, 'completed')
            os.makedirs(upload)
            source = os.path.join(upload, 'photo.jpg')
            open(source, 'wb').write(b'new')
            result = PostResult(SimpleNamespace(file='photo.jpg'), False, 'Failed')
            self.assertEqual(_archive_success(result, upload, completed), '')
            self.assertTrue(os.path.exists(source))


if __name__ == '__main__':
    unittest.main()
