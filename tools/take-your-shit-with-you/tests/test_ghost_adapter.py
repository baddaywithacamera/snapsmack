"""
Ghost courtesy adapter: the package exists, parses, imports cleanly in shape,
and says out loud what Ghost cannot hold. Built from the same fake site the
WordPress adapter test uses, so the two courtesy packages describe one archive.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
Last non-empty line of this file MUST match the line above.
"""
import json
import os
import re
import sys
import tempfile
import unittest
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
for p in (HERE, ROOT, os.path.join(ROOT, '..', '_shared')):
    if p not in sys.path:
        sys.path.insert(0, p)

from test_wordpress_adapter import build_archive   # noqa: E402  (same fake site)

HEX24 = re.compile(r'^[0-9a-f]{24}$')
GDATE = re.compile(r'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$')


class GhostAdapterTests(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        cls.report, cls.site = build_archive(cls.tmp.name)
        cls.root = cls.report.root
        cls.out  = os.path.join(cls.root, 'courtesy', 'ghost')
        with open(os.path.join(cls.out, 'ghost-import.json'), encoding='utf-8') as f:
            cls.doc = json.load(f)
        cls.data = cls.doc['db'][0]['data']
        with open(os.path.join(cls.root, 'courtesy', 'adapters.json'), encoding='utf-8') as f:
            cls.adapters = json.load(f)['adapters']

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def posts_of_type(self, kind):
        return [p for p in self.data['posts'] if p['type'] == kind]

    # -- shape ------------------------------------------------------------
    def test_the_package_exists_and_has_ghosts_shape(self):
        self.assertIn('meta', self.doc['db'][0])
        for key in ('posts', 'tags', 'posts_tags', 'users', 'posts_authors'):
            self.assertIsInstance(self.data[key], list, key)
        self.assertTrue(os.path.isfile(os.path.join(self.out, 'CONVERSION-REPORT.html')))
        self.assertTrue(os.path.isfile(os.path.join(self.out, 'README.txt')))

    def test_ids_and_dates_are_what_ghost_expects(self):
        for p in self.data['posts']:
            self.assertRegex(p['id'], HEX24)
            for k in ('published_at', 'created_at', 'updated_at'):
                self.assertRegex(p[k], GDATE, k)
            self.assertIn(p['status'], ('published', 'draft'))
        for t in self.data['tags']:
            self.assertRegex(t['id'], HEX24)

    def test_ids_are_stable_across_runs(self):
        import ghost_adapter as ga
        first = {p['id'] for p in self.data['posts']}
        ga.generate(self.root)
        with open(os.path.join(self.out, 'ghost-import.json'), encoding='utf-8') as f:
            second = {p['id'] for p in json.load(f)['db'][0]['data']['posts']}
        self.assertEqual(first, second, 'a re-run must yield the same ids so a re-import updates, not duplicates')

    # -- content ----------------------------------------------------------
    def test_wordpress_and_ghost_agree_on_the_post_count(self):
        wp = self.adapters['wordpress']['items']
        gh = self.adapters['ghost']['items']
        self.assertEqual(wp.get('posts'), gh['posts'])
        self.assertEqual(wp.get('pages'), gh['pages'])

    def test_pages_stay_pages(self):
        self.assertEqual(len(self.posts_of_type('page')), self.adapters['ghost']['items']['pages'])

    def test_every_post_has_an_author_and_a_feature_image_where_it_had_a_photo(self):
        authored = {a['post_id'] for a in self.data['posts_authors']}
        for p in self.data['posts']:
            self.assertIn(p['id'], authored)
            if p['feature_image']:
                self.assertTrue(p['feature_image'].startswith('__GHOST_URL__/content/images/'), p['feature_image'])

    def test_a_carousel_becomes_a_gallery_in_order(self):
        galleries = [p for p in self.data['posts'] if 'kg-gallery-card' in p['html']]
        self.assertTrue(galleries, 'the fake site has a carousel; Ghost should get a gallery')
        srcs = re.findall(r'<img src="([^"]+)"', galleries[0]['html'])
        self.assertGreater(len(srcs), 1)
        self.assertEqual(galleries[0]['feature_image'], srcs[0], 'first carousel image is the feature image')

    def test_the_zip_carries_json_and_images(self):
        with zipfile.ZipFile(os.path.join(self.out, 'ghost-import.zip')) as z:
            names = z.namelist()
        self.assertIn('ghost-import.json', names)
        self.assertTrue(any(n.startswith('content/images/snapsmack/') for n in names))

    # -- honesty ----------------------------------------------------------
    def test_comments_are_declared_lost_not_silently_dropped(self):
        losses = ' '.join(self.adapters['ghost']['losses'])
        self.assertIn('comment', losses.lower())
        self.assertGreater(self.adapters['ghost']['items']['comments_not_imported'], 0)

    def test_the_adapter_admits_it_has_not_been_watched_on_a_real_ghost(self):
        self.assertFalse(self.adapters['ghost']['verified_against_real_ghost'])


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
