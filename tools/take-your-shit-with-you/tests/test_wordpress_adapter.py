"""
TAKE YOUR SHIT WITH YOU — WordPress courtesy adapter tests.

Two things matter here and they pull in opposite directions.

  1. The output has to be USEFUL: valid XML, the right number of posts, galleries
     in the right order, comments with their moderation state intact.
  2. The output has to be HONEST: every mapping WordPress cannot make has to be
     written down. An adapter that silently drops a MOSAIC layout and reports
     success is worse than one that refuses to run, because the owner finds out
     a year later.

And one rule that protects everything else: the adapter reads the canonical
archive and writes only under courtesy/. Running it twice must leave every
canonical byte exactly where it was.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import hashlib
import json
import os
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))

import export_engine as ee          # noqa: E402
import tyswy_client as tc           # noqa: E402
import wordpress_adapter as wa      # noqa: E402
from fake_server import FakeSite    # noqa: E402
from test_export_engine import sample_media, sample_tables   # noqa: E402

NS = {'wp': 'http://wordpress.org/export/1.2/',
      'content': 'http://purl.org/rss/1.0/modules/content/',
      'dc': 'http://purl.org/dc/elements/1.1/',
      'excerpt': 'http://wordpress.org/export/1.2/excerpt/'}


def build_archive(tmpdir, tables=None, media=None):
    site = FakeSite(tables or sample_tables(), media or sample_media())
    client = tc.TyswyClient('https://fauxlaroid.fyi', 'k' * 64,
                            session=site, max_retries=0)
    engine = ee.ExportEngine(
        client, tmpdir,
        options=ee.ExportOptions(media_concurrency=1, courtesy_wordpress=True),
        client_factory=lambda: tc.TyswyClient('https://fauxlaroid.fyi', 'k' * 64,
                                              session=site, max_retries=0))
    report = engine.run()
    return report, site


class AdapterTests(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        cls.report, cls.site = build_archive(cls.tmp.name)
        cls.root = cls.report.root
        cls.out  = os.path.join(cls.root, 'courtesy', 'wordpress')
        cls.tree = ET.parse(os.path.join(cls.out, 'snapsmack-wordpress.xml'))
        cls.channel = cls.tree.getroot().find('channel')

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def items_of_type(self, kind):
        return [i for i in self.channel.findall('item')
                if (i.find('wp:post_type', NS).text or '') == kind]

    # -- valid and complete ---------------------------------------------
    def test_the_package_exists_and_the_xml_parses(self):
        for f in ('snapsmack-wordpress.xml', 'media-map.json',
                  'conversion-report.html', 'README.txt'):
            self.assertTrue(os.path.exists(os.path.join(self.out, f)), f)
        self.assertEqual(self.tree.getroot().tag, 'rss')
        self.assertEqual(self.channel.find('wp:wxr_version', NS).text, '1.2')

    def test_every_published_thing_became_exactly_one_post(self):
        """Two carousel/longform posts, plus the one image not inside a post.
        An image already in a carousel must NOT be published twice."""
        posts = self.items_of_type('post')
        self.assertEqual(len(posts), 3)
        guids = sorted(i.find('guid').text for i in posts)
        self.assertEqual(guids, ['snapsmack-image-3', 'snapsmack-post-10',
                                 'snapsmack-post-11'])

    def test_pages_stay_pages(self):
        pages = self.items_of_type('page')
        self.assertEqual(len(pages), 1)
        self.assertEqual(pages[0].find('title').text, 'About')

    def test_every_image_became_an_attachment(self):
        self.assertEqual(len(self.items_of_type('attachment')), 3)

    def test_the_gallery_keeps_the_carousel_order(self):
        post = next(i for i in self.channel.findall('item')
                    if i.find('guid').text == 'snapsmack-post-10')
        body = post.find('content:encoded', NS).text
        mm = json.loads(Path(self.out, 'media-map.json').read_text('utf-8'))
        by_image = {m['image_id']: m['attachment_id'] for m in mm['media']}
        self.assertIn(f'[gallery ids="{by_image[1]},{by_image[2]}"', body)

    def test_the_cover_image_becomes_the_featured_image(self):
        post = next(i for i in self.channel.findall('item')
                    if i.find('guid').text == 'snapsmack-post-10')
        metas = {m.find('wp:meta_key', NS).text: m.find('wp:meta_value', NS).text
                 for m in post.findall('wp:postmeta', NS)}
        mm = json.loads(Path(self.out, 'media-map.json').read_text('utf-8'))
        by_image = {m['image_id']: str(m['attachment_id']) for m in mm['media']}
        self.assertEqual(metas['_thumbnail_id'], by_image[1])   # is_cover row

    def test_comments_carry_their_moderation_state(self):
        post = next(i for i in self.channel.findall('item')
                    if i.find('guid').text == 'snapsmack-post-10')
        approved = {c.find('wp:comment_author', NS).text:
                    c.find('wp:comment_approved', NS).text
                    for c in post.findall('wp:comment', NS)}
        self.assertEqual(approved['Ada'], '1')
        self.assertEqual(approved['Spam Bot'], '0')     # hidden, not promoted

    def test_a_deleted_comment_is_not_resurrected(self):
        tables = sample_tables()
        tables['comments'].append(
            {'id': 42, 'post_id': 10, 'user_id': None, 'guest_name': 'Gone',
             'guest_email': None, 'guest_url': None, 'comment_text': 'deleted',
             'status': 'deleted', 'created_at': '2026-06-06 12:00:00',
             'edited_at': None})
        with tempfile.TemporaryDirectory() as tmp:
            report, _ = build_archive(tmp, tables)
            xml = Path(report.root, 'courtesy/wordpress/snapsmack-wordpress.xml'
                       ).read_text('utf-8')
            self.assertNotIn('>Gone<', xml)

    def test_a_draft_stays_a_draft(self):
        item = next(i for i in self.channel.findall('item')
                    if i.find('guid').text == 'snapsmack-image-3')
        self.assertEqual(item.find('wp:status', NS).text, 'draft')

    def test_categories_and_tags_come_across(self):
        names = {c.text for c in self.channel.findall('wp:category/wp:cat_name', NS)}
        self.assertIn('Prairie', names)
        tags = {t.text for t in self.channel.findall('wp:tag/wp:tag_name', NS)}
        self.assertIn('grain', tags)
        self.assertIn('Highway 3', tags)      # album, flattened to a tag

    # -- honest about losses ---------------------------------------------
    def test_every_known_loss_is_written_down(self):
        losses = ' '.join(self.report.adapters['wordpress']['losses']).lower()
        for expected in ('album', 'collection', 'mosaic', 'exif',
                         'crop mode', 'reaction'):
            self.assertIn(expected, losses, f'loss not reported: {expected}')

    def test_the_conversion_report_names_the_collections_it_skipped(self):
        html = Path(self.out, 'conversion-report.html').read_text('utf-8')
        self.assertIn('Best of the drive', html)
        self.assertIn('courtesy', html.lower())

    def test_a_mosaic_leaves_a_visible_marker_not_a_silent_hole(self):
        post = next(i for i in self.channel.findall('item')
                    if i.find('guid').text == 'snapsmack-post-11')
        body = post.find('content:encoded', NS).text
        self.assertIn('MOSAIC 3 was here', body)
        self.assertIn('inline image 7', body)

    # -- safety -----------------------------------------------------------
    def test_hostile_text_is_escaped_not_executed(self):
        tables = sample_tables()
        tables['images'][0]['img_title'] = '<script>alert(1)</script> & "quotes"'
        tables['images'][0]['img_description'] = ']]><!--injected-->'
        with tempfile.TemporaryDirectory() as tmp:
            report, _ = build_archive(tmp, tables)
            out = Path(report.root, 'courtesy/wordpress')
            ET.parse(out / 'snapsmack-wordpress.xml')          # still parses
            xml = (out / 'snapsmack-wordpress.xml').read_text('utf-8')
            self.assertIn('&lt;script&gt;', xml)
            self.assertNotIn(']]><!--injected-->', xml)
            html = (out / 'conversion-report.html').read_text('utf-8')
            self.assertNotIn('<script>alert(1)</script>', html)

    def test_a_character_xml_cannot_hold_does_not_break_the_file(self):
        tables = sample_tables()
        tables['images'][0]['img_title'] = 'vertical' + chr(0x0B) + 'tab'
        with tempfile.TemporaryDirectory() as tmp:
            report, _ = build_archive(tmp, tables)
            ET.parse(Path(report.root, 'courtesy/wordpress/snapsmack-wordpress.xml'))

    def test_running_it_twice_does_not_touch_a_canonical_byte(self):
        canonical = {}
        for path in Path(self.root).rglob('*'):
            rel = path.relative_to(self.root).as_posix()
            if not path.is_file() or rel.startswith(('courtesy/', '.tyswy/', 'logs/')):
                continue
            canonical[rel] = hashlib.sha256(path.read_bytes()).hexdigest()

        wa.generate(self.root)

        after = {}
        for path in Path(self.root).rglob('*'):
            rel = path.relative_to(self.root).as_posix()
            if not path.is_file() or rel.startswith(('courtesy/', '.tyswy/', 'logs/')):
                continue
            after[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
        self.assertEqual(canonical, after)

    def test_media_is_in_the_package(self):
        files = os.listdir(os.path.join(self.out, 'media'))
        self.assertEqual(len(files), 3)
        mm = json.loads(Path(self.out, 'media-map.json').read_text('utf-8'))
        self.assertEqual(len(mm['media']), 3)
        for entry in mm['media']:
            self.assertTrue(os.path.exists(
                os.path.join(self.out, *entry['wordpress_file'].split('/'))))


class ContainmentTests(unittest.TestCase):

    def test_a_sidecar_cannot_point_the_adapter_outside_the_archive(self):
        """Sidecars are JSON files on disk. A traversing media path is refused
        and reported, not followed (spec 13)."""
        with tempfile.TemporaryDirectory() as tmp:
            report, _ = build_archive(tmp)
            root = Path(report.root)
            side = root / 'content' / 'images' / '000001-rusty-truck.json'
            data = json.loads(side.read_text('utf-8'))
            data['media'][0]['path'] = '../../../../etc/passwd'
            side.write_text(json.dumps(data), encoding='utf-8')

            result = wa.generate(str(root))
            losses = ' '.join(result['losses'])
            self.assertIn('outside the', losses)
            self.assertEqual(len(result['losses']), len(set(result['losses'])))
            # And the file it was pointed at was not pulled into the package.
            for name in os.listdir(root / 'courtesy' / 'wordpress' / 'media'):
                self.assertNotIn('passwd', name)


class MissingMediaTests(unittest.TestCase):

    def test_an_image_with_no_file_is_reported_rather_than_hidden(self):
        media = sample_media()
        del media[('image', 2, 'original')]
        with tempfile.TemporaryDirectory() as tmp:
            report, _ = build_archive(tmp, sample_tables(), media)
            self.assertFalse(report.complete)
            losses = ' '.join(report.adapters['wordpress']['losses'])
            self.assertIn('Image 2', losses)


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
