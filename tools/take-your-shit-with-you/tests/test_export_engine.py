"""
TAKE YOUR SHIT WITH YOU — export engine tests.

These run a whole export end to end against the fake site: collect, assemble,
media, verify, adapters, manifest. The properties worth pinning are the ones
that would make an archive quietly worthless:

  * carousel order survives, and is taken from the source rather than re-derived
  * both comment tables land, so a photoblog does not lose its conversation
  * EXIF and GPS are preserved, because that is a settled decision
  * an interrupted run resumes instead of starting over, and keeps what it had
  * a missing file makes the export NOT complete — silence would be the bug
  * running the courtesy adapter twice does not touch a canonical byte

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
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))

import export_engine as ee        # noqa: E402
import tyswy_client as tc         # noqa: E402
from fake_server import FakeSite  # noqa: E402

JPEG = b'\xff\xd8\xff\xe0' + b'photo-bytes' * 40


def _blob(seed):
    return JPEG + bytes([seed]) * 300


EXIF = json.dumps({'camera': 'NIKON D850', 'lens': '35mm',
                   'latitude': 50.0405, 'longitude': -110.6764})


def sample_tables():
    return {
        'images': [
            {'id': 1, 'img_title': 'Rusty truck', 'img_slug': 'rusty-truck',
             'img_description': 'Out past the elevator', 'img_date': '2026-06-01 08:00:00',
             'img_file': 'img_uploads/one.jpg', 'img_status': 'published',
             'img_width': 1600, 'img_height': 1067, 'img_exif': EXIF,
             'modified_at': '2026-06-01 08:00:00', 'blurhash': 'LKO2?U%2Tw=w',
             'some_future_column': 'nobody has written a mapper for this'},
            {'id': 2, 'img_title': 'Grain bins', 'img_slug': 'grain-bins',
             'img_description': None, 'img_date': '2026-06-02 08:00:00',
             'img_file': 'img_uploads/two.jpg', 'img_status': 'published',
             'img_width': 1600, 'img_height': 1600, 'img_exif': None,
             'modified_at': '2026-06-02 08:00:00'},
            {'id': 3, 'img_title': 'Coulee', 'img_slug': 'coulee',
             'img_description': 'Emoji in a caption 📷 and a "quote"',
             'img_date': '2026-06-03 08:00:00', 'img_file': 'img_uploads/three.jpg',
             'img_status': 'draft', 'img_width': 2000, 'img_height': 1333,
             'img_exif': 'not json{', 'modified_at': '2026-06-03 08:00:00'},
        ],
        'posts': [
            {'id': 10, 'title': 'A drive east', 'slug': 'a-drive-east',
             'description': 'Three hours of nothing', 'content': None,
             'post_type': 'carousel', 'status': 'published',
             'created_at': '2026-06-04 09:00:00', 'updated_at': '2026-06-04 09:00:00',
             'featured_image_id': 2},
            {'id': 11, 'title': 'On leaving', 'slug': 'on-leaving',
             'description': None,
             'content': 'First paragraph.\n\nAnd here [img:7] sits in the middle.\n\n'
                        'Then [mosaic:3] closes it out.',
             'post_type': 'longform', 'status': 'published',
             'created_at': '2026-06-05 09:00:00', 'updated_at': '2026-06-05 09:00:00'},
        ],
        # Deliberately NOT in display order: the export must sort by
        # sort_position, and must not accidentally preserve insertion order and
        # look correct by luck.
        'post_images': [
            {'id': 101, 'post_id': 10, 'image_id': 2, 'sort_position': 1,
             'is_cover': 0, 'img_focus_x': 60, 'img_zoom': 120},
            {'id': 100, 'post_id': 10, 'image_id': 1, 'sort_position': 0,
             'is_cover': 1, 'img_focus_x': 40, 'img_crop_mode': 'fill'},
        ],
        'categories': [{'id': 5, 'cat_name': 'Prairie', 'cat_slug': 'prairie',
                        'cat_description': 'Flat and enormous'}],
        'albums': [{'id': 7, 'album_name': 'Highway 3', 'album_description': None}],
        'tags': [{'id': 20, 'tag': 'grain', 'slug': 'grain', 'use_count': 2}],
        'image_tags': [{'id': 1, 'image_id': 1, 'tag_id': 20}],
        'image_category_memberships': [{'image_id': 1, 'cat_id': 5},
                                       {'image_id': 2, 'cat_id': 5}],
        'image_album_memberships': [{'image_id': 1, 'album_id': 7}],
        'post_category_memberships': [{'post_id': 10, 'cat_id': 5}],
        'post_album_memberships': [],
        'pages': [{'id': 30, 'slug': 'about', 'title': 'About',
                   'content': 'Who I am.', 'is_active': 1, 'menu_order': 1,
                   'created_at': '2026-01-01 00:00:00'}],
        'comments': [
            {'id': 40, 'post_id': 10, 'user_id': None, 'guest_name': 'Ada',
             'guest_email': 'ada@example.com', 'guest_url': 'https://example.com',
             'comment_text': 'That truck has seen things.', 'status': 'visible',
             'created_at': '2026-06-06 10:00:00', 'edited_at': None},
            {'id': 41, 'post_id': 10, 'user_id': None, 'guest_name': 'Spam Bot',
             'guest_email': None, 'guest_url': None, 'comment_text': 'buy pills',
             'status': 'hidden', 'created_at': '2026-06-06 11:00:00', 'edited_at': None},
        ],
        'image_comments': [
            {'id': 50, 'img_id': 1, 'post_id': None, 'comment_author': 'Grace',
             'comment_url': None, 'comment_email': 'grace@example.com',
             'comment_text': 'Where is this?', 'comment_date': '2026-06-07 12:00:00',
             'is_approved': 1, 'ap_source': 'local'},
            {'id': 51, 'img_id': 1, 'post_id': None, 'comment_author': 'Pending Pete',
             'comment_url': None, 'comment_email': None,
             'comment_text': 'not yet approved', 'comment_date': '2026-06-07 13:00:00',
             'is_approved': 0, 'ap_source': 'local'},
        ],
        'reactions': [{'id': 60, 'post_id': 10, 'user_id': 1,
                       'created_at': '2026-06-08 00:00:00'}],
        'emoji_reactions': [],
        'collections': [{'id': 70, 'title': 'Best of the drive', 'slug': 'best',
                         'description': None, 'published': 1,
                         'created_at': '2026-06-01 00:00:00',
                         'updated_at': '2026-06-01 00:00:00'}],
        'collection_memberships': [{'id': 1, 'collection_id': 70, 'item_type': 'image',
                                    'item_id': 1, 'sort_order': 0, 'caption': None}],
        'blogroll': [{'id': 80, 'peer_name': 'A friend', 'peer_url': 'https://friend.example',
                      'cat_id': 1, 'peer_rss': None, 'peer_desc': None, 'sort_order': 0}],
        'blogroll_categories': [{'id': 1, 'cat_name': 'People'}],
        'assets': [{'id': 7, 'asset_name': 'diagram.png',
                    'asset_path': 'media_assets/1234_abc.png',
                    'asset_checksum': None, 'created_at': '2026-05-01 00:00:00'}],
        'mosaics': [{'id': 3, 'title': 'Three up', 'asset_ids': '[7]',
                     'focus_positions': None, 'gap': 4,
                     'created_at': '2026-05-01 00:00:00',
                     'updated_at': '2026-05-01 00:00:00'}],
        'trigrams': [],
        'stats_summary': [{'id': 1, 'stat_date': '2026-06-01', 'total_views': 412,
                           'unique_visitors': 88, 'bot_views': 30,
                           'top_image_id': 1, 'top_referrer': 'mastodon.social'}],
        'follows': [], 'following': [],
    }


def sample_media():
    return {
        ('image', 1, 'original'): _blob(1),
        ('image', 2, 'original'): _blob(2),
        ('image', 3, 'original'): _blob(3),
        ('asset', 7, 'original'): _blob(7),
    }


class EngineHarness(unittest.TestCase):

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.site = FakeSite(sample_tables(), sample_media())

    def make_client(self):
        return tc.TyswyClient('https://fauxlaroid.fyi', 'k' * 64,
                              session=self.site, max_retries=0)

    def run_export(self, **opts):
        opts.setdefault('media_concurrency', 1)
        engine = ee.ExportEngine(
            self.make_client(), self.tmp.name,
            options=ee.ExportOptions(**opts),
            client_factory=self.make_client)
        return engine.run(), engine

    def read(self, root, rel):
        with open(os.path.join(root, *rel.split('/')), encoding='utf-8') as f:
            return json.load(f)


class FullExportTests(EngineHarness):

    def setUp(self):
        super().setUp()
        self.report, self.engine = self.run_export()
        self.root = self.report.root

    # -- shape ----------------------------------------------------------
    def test_the_archive_is_a_folder_with_the_documented_layout(self):
        for d in ('content/posts', 'content/images', 'content/pages',
                  'content/comments', 'content/relationships',
                  'media/originals', 'indexes', 'courtesy', 'logs', 'schema'):
            self.assertTrue(os.path.isdir(os.path.join(self.root, *d.split('/'))), d)
        for f in ('README.txt', 'manifest.json', 'verification.json', 'site.json'):
            self.assertTrue(os.path.exists(os.path.join(self.root, f)), f)

    def test_the_export_is_complete(self):
        self.assertTrue(self.report.complete, self.report.mismatches)
        v = self.read(self.root, 'verification.json')
        self.assertTrue(v['complete'])
        self.assertEqual(v['mismatches'], {})

    def test_every_table_matches_the_source_count(self):
        v = self.read(self.root, 'verification.json')
        for t, expected in v['expected_counts'].items():
            self.assertEqual(v['actual_counts'].get(t, 0), expected, t)

    # -- content --------------------------------------------------------
    def test_carousel_order_comes_from_sort_position_not_insertion_order(self):
        post = self.read(self.root, 'content/posts/000010-a-drive-east.json')
        self.assertEqual([i['image_id'] for i in post['images']], [1, 2])
        self.assertTrue(post['images'][0]['is_cover'])
        self.assertEqual(post['images'][0]['presentation']['img_focus_x'], 40)
        self.assertEqual(post['images'][1]['presentation']['img_zoom'], 120)

    def test_exif_and_gps_are_preserved(self):
        img = self.read(self.root, 'content/images/000001-rusty-truck.json')
        self.assertEqual(img['exif']['camera'], 'NIKON D850')
        self.assertEqual(img['exif']['latitude'], 50.0405)

    def test_unparseable_exif_is_kept_rather_than_dropped(self):
        img = self.read(self.root, 'content/images/000003-coulee.json')
        self.assertIn('_unparsed', img['exif'])

    def test_an_unmapped_column_survives_in_the_namespace(self):
        img = self.read(self.root, 'content/images/000001-rusty-truck.json')
        self.assertIn('some_future_column', img['snapsmack'])
        self.assertEqual(img['snapsmack']['blurhash'], 'LKO2?U%2Tw=w')

    def test_memberships_land_as_names_a_person_can_read(self):
        img = self.read(self.root, 'content/images/000001-rusty-truck.json')
        self.assertEqual(img['categories'], ['Prairie'])
        self.assertEqual(img['albums'], ['Highway 3'])
        self.assertEqual(img['tags'], ['grain'])
        self.assertEqual(img['collections'][0]['title'], 'Best of the drive')

    def test_both_comment_tables_arrive(self):
        """The photoblog table hangs off images, the community table off posts.
        Exporting one and not the other loses a whole site's conversation and
        nothing about the archive would look wrong."""
        img = self.read(self.root, 'content/images/000001-rusty-truck.json')
        self.assertEqual(len(img['comments']), 2)
        post = self.read(self.root, 'content/posts/000010-a-drive-east.json')
        self.assertEqual(len(post['comments']), 2)

    def test_moderation_state_travels_with_the_comment(self):
        img = self.read(self.root, 'content/images/000001-rusty-truck.json')
        states = {c['author']: c['moderation'] for c in img['comments']}
        self.assertEqual(states['Grace'], 'approved')
        self.assertEqual(states['Pending Pete'], 'pending')
        post = self.read(self.root, 'content/posts/000010-a-drive-east.json')
        self.assertIn('hidden', [c['moderation'] for c in post['comments']])

    def test_comments_are_also_grouped_into_threads(self):
        thread = self.read(self.root, 'content/comments/image-000001.json')
        self.assertEqual(thread['parent'], {'type': 'image', 'id': 1})
        self.assertEqual(len(thread['comments']), 2)

    def test_reactions_are_counts_not_identities(self):
        post = self.read(self.root, 'content/posts/000010-a-drive-east.json')
        self.assertEqual(post['reactions']['likes'], 1)
        raw = Path(self.root, 'content/posts/000010-a-drive-east.json').read_text('utf-8')
        self.assertNotIn('user_id', raw.split('"snapsmack"')[0])

    def test_longform_inline_references_are_listed_explicitly(self):
        post = self.read(self.root, 'content/posts/000011-on-leaving.json')
        kinds = {(r['kind'], r['id']) for r in post['inline_media']}
        self.assertIn(('img', 7), kinds)
        self.assertIn(('mosaic', 3), kinds)

    def test_draft_status_is_not_quietly_published(self):
        img = self.read(self.root, 'content/images/000003-coulee.json')
        self.assertEqual(img['status'], 'draft')

    def test_unicode_and_quotes_survive_intact(self):
        img = self.read(self.root, 'content/images/000003-coulee.json')
        self.assertIn('📷', img['description'])
        self.assertIn('"quote"', img['description'])

    # -- media ----------------------------------------------------------
    def test_media_arrives_and_is_hashed(self):
        self.assertEqual(self.report.media_files, 4)     # 3 images + 1 asset
        manifest = self.read(self.root, 'manifest.json')
        rel = 'media/originals/000001-rusty-truck.jpg'
        self.assertIn(rel, manifest['files'])
        on_disk = hashlib.sha256(
            Path(self.root, *rel.split('/')).read_bytes()).hexdigest()
        self.assertEqual(manifest['files'][rel]['sha256'], on_disk)

    def test_longform_assets_are_downloaded_too(self):
        """A SMACKTALK body that says [img:7] points at snap_assets, not
        snap_images. Missing them leaves the writing referring to nothing."""
        found = [p for p in os.listdir(
            os.path.join(self.root, 'media', 'originals', 'assets'))]
        self.assertEqual(len(found), 1)

    def test_no_part_files_are_left_behind(self):
        leftovers = [str(p) for p in Path(self.root).rglob('*.part')]
        self.assertEqual(leftovers, [])

    # -- manifest / indexes ---------------------------------------------
    def test_the_manifest_names_what_was_excluded(self):
        m = self.read(self.root, 'manifest.json')
        self.assertIn('password hashes', m['exclusions'])
        self.assertTrue(m['completed'])

    def test_the_manifest_holds_no_credential(self):
        """Field NAMES, not prose. `exclusions` legitimately says the words
        "password hashes" — that sentence is the point of the list. What must
        never appear is a credential-shaped key, or the key we connected with."""
        m = self.read(self.root, 'manifest.json')

        def walk(node, path='manifest'):
            if isinstance(node, dict):
                for k, v in node.items():
                    self.assertNotRegex(
                        k, r'(?i)(password|api[_-]?key|secret|token|totp|bearer)',
                        f'credential-shaped field at {path}.{k}')
                    walk(v, f'{path}.{k}')
            elif isinstance(node, list):
                for i, v in enumerate(node):
                    walk(v, f'{path}[{i}]')
        walk(m)
        self.assertNotIn('k' * 64, Path(self.root, 'manifest.json').read_text('utf-8'))

    def test_the_content_map_lists_everything_once(self):
        cm = self.read(self.root, 'indexes/content-map.json')
        kinds = {}
        for item in cm['items']:
            kinds[item['type']] = kinds.get(item['type'], 0) + 1
        self.assertEqual(kinds['image'], 3)
        self.assertEqual(kinds['post'], 2)
        self.assertEqual(kinds['page'], 1)

    def test_statistics_are_aggregate_only(self):
        stats = self.read(self.root, 'indexes/stats.json')
        raw = json.dumps(stats)
        self.assertIn('total_views', raw)
        for leak in ('ip_hash', 'user_agent', 'referrer_host'):
            self.assertNotIn(leak, raw)

    def test_the_schema_ships_inside_the_archive(self):
        p = Path(self.root, 'schema', 'snapsmack-portable-v1.schema.json')
        self.assertTrue(p.exists())
        self.assertEqual(json.loads(p.read_text('utf-8'))['$schema'],
                         'https://json-schema.org/draft/2020-12/schema')

    def test_the_log_records_the_run(self):
        log = Path(self.root, 'logs', 'export.log').read_text('utf-8')
        self.assertIn('EXPORT COMPLETE', log)


class ResumeTests(EngineHarness):

    def test_an_interrupted_run_resumes_instead_of_starting_over(self):
        # First attempt dies part-way: the images stream arrives with no footer,
        # which is what a dropped connection looks like.
        self.site.drop_footer_for.add('images')
        with self.assertRaises(tc.VerificationError):
            self.run_export()

        before = len(self.site.requests)
        self.site.drop_footer_for.clear()
        report, _ = self.run_export()

        self.assertTrue(report.complete, report.mismatches)
        # The types collected before the failure were not fetched again.
        after = self.site.requests[before:]
        streamed = [r[1].get('type') for r in after if r[0] == 'stream']
        self.assertIn('images', streamed)
        self.assertNotIn('categories', streamed)

    def test_resuming_into_a_different_site_is_refused(self):
        # An UNFINISHED export, so the resume path is the one under test rather
        # than the never-overwrite-a-finished-one path.
        self.site.fail_media_once.add(('image', 2))
        first, _ = self.run_export()
        self.assertFalse(first.complete)

        self.site.site_uuid = 'a-different-install-entirely'
        with self.assertRaises(Exception) as cm:
            self.run_export()
        self.assertIn('DIFFERENT', str(cm.exception).upper())

    def test_an_incomplete_export_stays_resumable(self):
        """The completion report tells the owner to run it again against this
        folder. Marking an incomplete run finished would make that a lie."""
        self.site.fail_media_once.add(('image', 2))
        report, engine = self.run_export()
        self.assertFalse(report.complete)
        self.assertFalse(engine.state.data['completed'])

    def test_a_completed_export_is_never_overwritten(self):
        self.run_export()
        with self.assertRaises(Exception) as cm:
            self.run_export()
        self.assertIn('COMPLETED', str(cm.exception).upper())

    def test_a_missing_file_stops_the_export_being_called_complete(self):
        self.site.fail_media_once.add(('image', 2))
        report, _ = self.run_export()
        self.assertFalse(report.complete)
        self.assertEqual(len(report.media_missing), 1)
        self.assertEqual(report.media_missing[0]['id'], 2)
        # And the warning names it rather than hiding behind a total.
        self.assertTrue(any('image 2' in w for w in report.warnings), report.warnings)

    def test_a_tampered_resume_ledger_cannot_reach_outside_the_archive(self):
        """The ledger is a file on disk that anyone can open in a text editor.
        Its paths are contained rather than trusted — an archive is data, and
        data stays untrusted even when we wrote it."""
        self.site.fail_media_once.add(('image', 2))
        first, engine = self.run_export()
        ledger = Path(first.root, '.tyswy', 'media-ledger.ndjson')
        lines = ledger.read_text('utf-8').splitlines()
        evil = json.loads(lines[0])
        evil['key'] = 'image:999:original'
        evil['path'] = '../../../somewhere-else.jpg'
        ledger.write_text('\n'.join(lines + [json.dumps(evil)]) + '\n', encoding='utf-8')

        second, _ = self.run_export()
        self.assertTrue(any('outside the archive' in w for w in second.warnings),
                        second.warnings)
        self.assertFalse(Path(self.tmp.name, 'somewhere-else.jpg').exists())

    def test_a_second_run_collects_the_file_that_failed(self):
        self.site.fail_media_once.add(('image', 2))
        first, _ = self.run_export()
        self.assertFalse(first.complete)
        second, _ = self.run_export()
        self.assertTrue(second.complete, second.mismatches)
        self.assertEqual(second.media_missing, [])


class FilenameTests(unittest.TestCase):

    def test_hostile_titles_cannot_escape_the_folder(self):
        self.assertNotIn('/', ee.safe_filename('../../etc/passwd'))
        self.assertNotIn('\\', ee.safe_filename('..\\..\\windows\\system32'))

    def test_windows_device_names_are_defused(self):
        for name in ('CON', 'PRN.jpg', 'LPT1', 'nul'):
            out = ee.safe_filename(name)
            self.assertTrue(out.startswith('_'), f'{name} -> {out}')

    def test_a_trailing_dot_or_space_is_trimmed(self):
        self.assertFalse(ee.safe_filename('photo. ').endswith(('.', ' ')))


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
