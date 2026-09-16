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


class EngineCompletedDirTests(unittest.TestCase):
    """SYBU 0.7.68: the Qt engine must hand the poster the completed folder the Tk window did."""

    def test_upload_folder_maps_to_sibling_completed(self):
        import sybu_core
        with tempfile.TemporaryDirectory() as root:
            upload = os.path.join(root, 'site', 'upload')
            os.makedirs(upload)
            self.assertEqual(sybu_core.Engine.completed_dir_for(upload), os.path.join(root, 'site', 'completed'))
            self.assertTrue(os.path.isdir(os.path.join(root, 'site', 'completed')))
            self.assertEqual(sybu_core.Engine.completed_dir_for(os.path.join(root, 'site', 'other')), '')
            self.assertEqual(sybu_core.Engine.completed_dir_for(''), '')

    def test_post_start_passes_completed_dir_to_both_posters(self):
        src = open(os.path.join(HERE, 'sybu_core.py'), encoding='utf-8').read()
        body = src[src.index('def post_start('):src.index('def cancel_post(')]
        self.assertEqual(body.count('completed_dir=completed_dir,'), 2)


if __name__ == '__main__':
    unittest.main()
