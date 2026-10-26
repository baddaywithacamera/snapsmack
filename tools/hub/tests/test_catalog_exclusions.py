import os
import sys
import tempfile
import unittest


HERE = os.path.dirname(__file__)
HUB = os.path.dirname(HERE)
if HUB not in sys.path:
    sys.path.insert(0, HUB)

from slapper_qt.catalog import Catalog


class CatalogExclusionTests(unittest.TestCase):
    def test_exclusion_keeps_file_but_removes_catalogue_state(self):
        with tempfile.TemporaryDirectory() as root:
            photo = os.path.join(root, "kept.jpg")
            with open(photo, "wb") as handle:
                handle.write(b"photo")
            catalog = Catalog(os.path.join(root, "catalog"))
            catalog.update_index([photo])
            catalog.set_details([photo], favorite=True, add_tags="kept")
            catalog.add_to_album("Test", [photo])

            catalog.exclude_paths([photo])

            self.assertTrue(os.path.isfile(photo))
            self.assertTrue(catalog.is_excluded(photo))
            self.assertNotIn(photo, catalog.all_paths())
            self.assertEqual([], catalog.albums["Test"])
            self.assertEqual(0, catalog.details(photo)["rating"])

            self.assertEqual(1, catalog.restore_excluded())
            self.assertFalse(catalog.is_excluded(photo))


if __name__ == "__main__":
    unittest.main()
