import os
import sys
import tempfile
import unittest

ROOT = os.path.dirname(os.path.dirname(__file__))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)

import sumna_offline as O


class FakePoster:
    pass


class CompletedMoveTests(unittest.TestCase):
    def test_verified_draft_moves_from_upload(self):
        with tempfile.TemporaryDirectory() as root:
            upload = os.path.join(root, 'upload')
            completed = os.path.join(root, 'completed')
            os.makedirs(upload)
            source = os.path.join(upload, 'cold.jpg')
            open(source, 'wb').write(b'image')
            session_path = os.path.join(root, 'session')
            os.makedirs(session_path)
            session = O.Session(session_path, {'session_id': 'test', 'mode': O.MODE_SOLO})
            draft = O.Draft(draft_id='one', kind=O.KIND_SOLO, mode=O.MODE_SOLO,
                            images=[O.DraftImage(local_path=source, filename='cold.jpg')])
            session.save_draft(draft)
            engine = O.SyncEngine(session, FakePoster(), upload_dir=upload,
                                  completed_dir=completed)
            engine._archive_completed(draft)
            self.assertFalse(os.path.exists(source))
            self.assertTrue(os.path.exists(os.path.join(completed, 'cold.jpg')))
            self.assertEqual(draft.images[0].original_path,
                             os.path.join(completed, 'cold.jpg'))


if __name__ == '__main__':
    unittest.main()
