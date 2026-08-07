"""
TAKE YOUR SHIT WITH YOU — canonical archive format tests.

The archive is the authoritative artifact and the only thing an owner is left
holding if they walk away. So the properties pinned here are the ones that would
make it worthless without anyone noticing:

  * nothing from the source record is silently dropped (spec 7.2)
  * carousel order survives (spec 8 — "carousel order is sacred")
  * no credential can end up inside the manifest
  * every written file is inventoried with a real checksum

stdlib unittest only, so it runs in the packaged build environment too.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import portable_archive as pa   # noqa: E402


SITE = {
    'site_uuid': 'abc123', 'site_name': "it's a fauxlaroid, fyi",
    'site_url': 'https://fauxlaroid.fyi', 'site_mode': 'carousel',
    'site_version': '0.7.505',
}


class SidecarTests(unittest.TestCase):

    def test_image_sidecar_keeps_unmapped_fields(self):
        """Spec 7.2: unknown/mode-specific fields are namespaced, never dropped."""
        rec = {
            'id': 123, 'img_title': 'Rusty truck', 'img_slug': 'rusty-truck',
            'img_date': '2026-08-06 22:36:36', 'img_status': 'published',
            'img_width': 1600, 'img_height': 1600,
            'blurhash': 'LKO2?U%2Tw=w', 'img_checksum': 'deadbeef',
            'some_future_column': 'value nobody has written a mapper for yet',
        }
        s = pa.build_image_sidecar(rec, site=SITE)
        self.assertEqual(s['title'], 'Rusty truck')
        self.assertEqual(s['source']['id'], 123)
        # The three fields with no home in the portable shape must survive.
        self.assertEqual(s['snapsmack']['blurhash'], 'LKO2?U%2Tw=w')
        self.assertEqual(s['snapsmack']['img_checksum'], 'deadbeef')
        self.assertIn('some_future_column', s['snapsmack'])

    def test_exif_is_parsed_and_gps_preserved(self):
        rec = {'id': 1, 'img_exif': json.dumps(
            {'camera': 'NIKON D850', 'latitude': 50.0405, 'longitude': -110.6764})}
        s = pa.build_image_sidecar(rec, site=SITE)
        self.assertEqual(s['exif']['camera'], 'NIKON D850')
        self.assertEqual(s['exif']['latitude'], 50.0405)   # preserved on purpose

    def test_unparseable_exif_is_kept_not_discarded(self):
        s = pa.build_image_sidecar({'id': 1, 'img_exif': 'not json{'}, site=SITE)
        self.assertIn('_unparsed', s['exif'])

    def test_bad_dates_become_null_not_a_guess(self):
        s = pa.build_image_sidecar({'id': 1, 'img_date': '0000-00-00 00:00:00'}, site=SITE)
        self.assertIsNone(s['dates']['created'])
        s2 = pa.build_image_sidecar({'id': 1, 'img_date': 'sometime in June'}, site=SITE)
        self.assertIsNone(s2['dates']['created'])

    def test_carousel_order_survives(self):
        """Spec 8: the post owns an ordered array and the order is not re-derived."""
        rows = [
            {'image_id': 9, 'sort_position': 0, 'is_cover': 1, 'img_focus_x': 40},
            {'image_id': 4, 'sort_position': 1, 'is_cover': 0, 'img_focus_x': 60},
            {'image_id': 7, 'sort_position': 2, 'is_cover': 0, 'img_zoom': 120},
        ]
        refs = [pa.build_image_reference(r, image_sidecar_path=f"content/images/{r['image_id']}.json")
                for r in rows]
        post = pa.build_post_sidecar({'id': 1, 'title': 'Trip'}, site=SITE, images=refs)
        self.assertEqual([i['image_id'] for i in post['images']], [9, 4, 7])
        self.assertTrue(post['images'][0]['is_cover'])
        # framing detail rides along so the arrangement is reproducible
        self.assertEqual(post['images'][0]['presentation']['img_focus_x'], 40)
        self.assertEqual(post['images'][2]['presentation']['img_zoom'], 120)

    def test_sidecar_names_sort_in_source_order(self):
        names = [pa.sidecar_name(i, 'a-photo') for i in (2, 10, 1)]
        self.assertEqual(sorted(names),
                         [pa.sidecar_name(1, 'a-photo'), pa.sidecar_name(2, 'a-photo'),
                          pa.sidecar_name(10, 'a-photo')])

    def test_slug_survives_hostile_titles(self):
        n = pa.sidecar_name(5, '../../etc/passwd')
        self.assertNotIn('/', n)
        self.assertNotIn('..', n)


class ArchiveTests(unittest.TestCase):

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.a = pa.PortableArchive(self.tmp.name, SITE, started_at='2026-08-07').create()

    def test_layout_created(self):
        for d in pa.DIRS:
            self.assertTrue(os.path.isdir(os.path.join(self.tmp.name, d)), d)

    def test_every_written_file_is_inventoried_with_a_real_hash(self):
        rel = self.a.write_json('content/images/000001-x.json', {'hello': 'world'})
        self.assertIn(rel, self.a.inventory)
        entry = self.a.inventory[rel]
        on_disk = pa.sha256_file(os.path.join(self.tmp.name, rel))
        self.assertEqual(entry['sha256'], on_disk)
        self.assertGreater(entry['bytes'], 0)

    def test_a_credential_in_site_never_reaches_the_manifest(self):
        """
        Defence in depth, and the order matters. write_manifest() copies named
        fields out of `site` rather than splatting it, so a stray key cannot ride
        along even before the guard runs. The guard below is the backstop for
        paths that DO pass structures through wholesale (adapters, warnings).
        """
        self.a.site['api_key'] = 'a1b2c3'
        self.a.counts = {'images': 1}
        self.a.write_manifest()
        raw = Path(self.tmp.name, 'manifest.json').read_text(encoding='utf-8')
        self.assertNotIn('a1b2c3', raw)
        self.assertNotIn('api_key', raw)

    def test_manifest_refuses_nested_credentials(self):
        self.a.write_json('site.json', {'ok': True})
        self.a.counts = {'images': 1}
        with self.assertRaises(ValueError):
            self.a.write_manifest(adapters={'wordpress': {'auth_token': 'x'}})

    def test_manifest_lists_files_and_media_totals(self):
        self.a.write_json('site.json', {'ok': True})
        media = os.path.join(self.tmp.name, 'media/originals/p.jpg')
        with open(media, 'wb') as f:
            f.write(b'\xff\xd8' + b'0' * 500)
        self.a.register_media(media)
        self.a.counts = {'images': 1}
        self.a.write_manifest(snapshot={'images': 1}, generated_at='2026-08-07T00:00:00+00:00')
        m = json.loads(Path(self.tmp.name, 'manifest.json').read_text(encoding='utf-8'))
        self.assertEqual(m['format'], 'snapsmack-portable')
        self.assertEqual(m['media']['files'], 1)
        self.assertEqual(m['media']['bytes'], 502)
        self.assertIn('media/originals/p.jpg', m['files'])
        self.assertIn('site.json', m['files'])

    def test_verification_flags_a_short_export(self):
        self.a.counts = {'images': 9}
        self.a.write_verification(expected_counts={'images': 10})
        v = json.loads(Path(self.tmp.name, 'verification.json').read_text(encoding='utf-8'))
        self.assertFalse(v['complete'])
        self.assertEqual(v['mismatches']['images'], {'expected': 10, 'actual': 9})

    def test_verification_passes_when_counts_match(self):
        self.a.counts = {'images': 10, 'posts': 3}
        self.a.write_verification(expected_counts={'images': 10, 'posts': 3})
        v = json.loads(Path(self.tmp.name, 'verification.json').read_text(encoding='utf-8'))
        self.assertTrue(v['complete'])
        self.assertEqual(v['mismatches'], {})

    def test_readme_is_honest_about_both_directions(self):
        self.a.write_readme()
        txt = Path(self.tmp.name, 'README.txt').read_text(encoding='utf-8')
        self.assertIn('EXIF', txt)
        self.assertIn('passwords', txt)      # names what is excluded
        self.assertIn('location', txt)       # and that location IS included

    def test_json_is_readable_and_stable(self):
        self.a.write_json('indexes/albums.json', {'b': 2, 'a': 1})
        raw = Path(self.tmp.name, 'indexes/albums.json').read_text(encoding='utf-8')
        self.assertTrue(raw.endswith('\n'))
        self.assertLess(raw.index('"a"'), raw.index('"b"'))   # sorted, diffable
        self.assertIn('\n', raw)                              # pretty-printed


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
