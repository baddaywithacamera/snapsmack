"""
TAKE YOUR SHIT WITH YOU — transport and verification tests.

The client's job is to refuse things. These tests are mostly about the refusals,
because a verification layer that accepts everything looks identical to one that
works right up until the day it matters.

The canonical-hash test is the load-bearing one: it pins the Python
serialisation to a hash computed by PHP's `tyswy_canonical()`. If either side
drifts, every record on every export starts failing verification, and the test
that catches it has to be one that does not simply ask the same code twice.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))

import tyswy_client as tc          # noqa: E402
from fake_server import FakeSite   # noqa: E402

# Computed by PHP:
#   $row = ["b"=>"café","a"=>1,"c"=>null,"d"=>"x/y","e"=>"tab\there"];
#   ksort($row);
#   hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES
#        | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
PHP_CANONICAL_ROW = {'b': 'café', 'a': 1, 'c': None, 'd': 'x/y', 'e': 'tab\there'}
PHP_CANONICAL_SHA = '66e3bf9cd1270c668666b01f21bb218c347c0359c33fcdb88c34c3cae50a8b28'


def client_for(site, **kw):
    kw.setdefault('max_retries', 0)
    return tc.TyswyClient('https://fauxlaroid.fyi', 'k' * 64, session=site, **kw)


class CanonicalTests(unittest.TestCase):

    def test_matches_the_php_hash_exactly(self):
        """The one test that would catch a silent drift between the two halves."""
        self.assertEqual(tc.record_digest(PHP_CANONICAL_ROW), PHP_CANONICAL_SHA)

    def test_key_order_does_not_change_the_hash(self):
        a = {'z': 1, 'a': 2, 'm': 3}
        b = {'m': 3, 'a': 2, 'z': 1}
        self.assertEqual(tc.record_digest(a), tc.record_digest(b))

    def test_no_spaces_and_slashes_stay_bare(self):
        self.assertEqual(tc.canonical({'a': 'x/y', 'b': 1}), '{"a":"x/y","b":1}')

    def test_line_separator_is_escaped_like_php_does(self):
        """PHP escapes U+2028 even with JSON_UNESCAPED_UNICODE. A caption pasted
        out of a word processor really can contain one, and an unescaped one here
        would fail verification for a reason nobody would ever guess."""
        out = tc.canonical({'x': 'a b'})
        self.assertIn('\\u2028', out)
        self.assertNotIn(' ', out)


class UrlTests(unittest.TestCase):

    def test_plain_http_is_refused_before_the_key_travels(self):
        with self.assertRaises(tc.TyswyError) as cm:
            tc.TyswyClient('http://example.com', 'k' * 64)
        self.assertEqual(cm.exception.code, 'https_required')

    def test_loopback_is_exempt(self):
        c = tc.TyswyClient('http://localhost:8080', 'k' * 64)
        self.assertTrue(c.site_url.startswith('http://localhost'))

    def test_bare_hostname_becomes_https(self):
        c = tc.TyswyClient('fauxlaroid.fyi', 'k' * 64)
        self.assertEqual(c.site_url, 'https://fauxlaroid.fyi')


class StreamTests(unittest.TestCase):

    def setUp(self):
        self.rows = [{'id': i, 'img_title': f'photo {i}', 'img_file': f'img_uploads/{i}.jpg'}
                     for i in range(1, 26)]
        self.site = FakeSite({'images': self.rows})
        self.client = client_for(self.site)

    def test_a_clean_chunk_verifies(self):
        chunk = self.client.stream_chunk('images', limit=10)
        self.assertTrue(chunk.supported)
        self.assertEqual(chunk.rows, 10)
        self.assertEqual(chunk.last_id, 10)
        self.assertTrue(chunk.has_more)
        self.assertEqual(chunk.source_count, 25)

    def test_a_truncated_chunk_is_discarded_whole(self):
        """No footer means the connection was cut. A short-but-plausible chunk
        must never be mistaken for a complete one."""
        self.site.drop_footer_for.add('images')
        with self.assertRaises(tc.VerificationError) as cm:
            self.client.stream_chunk('images', limit=10)
        self.assertEqual(cm.exception.code, 'truncated_stream')

    def test_a_lying_record_hash_is_caught(self):
        self.site.corrupt_digest_for.add('images')
        with self.assertRaises(tc.VerificationError) as cm:
            self.client.stream_chunk('images', limit=5)
        self.assertEqual(cm.exception.code, 'record_digest_mismatch')

    def test_verification_failure_is_never_retryable(self):
        self.site.corrupt_digest_for.add('images')
        try:
            self.client.stream_chunk('images', limit=5)
        except tc.VerificationError as e:
            self.assertFalse(e.retryable)

    def test_walking_every_chunk_gets_every_row_exactly_once(self):
        seen = []
        rows, last_id, snap, source = self.client.stream_all(
            'images', limit=7, on_record=lambda rec, sid, dig: seen.append(sid))
        self.assertEqual(rows, 25)
        self.assertEqual(sorted(seen), list(range(1, 26)))
        self.assertEqual(len(seen), len(set(seen)))
        self.assertEqual(last_id, 25)
        self.assertEqual(source, 25)

    def test_resume_starts_after_the_last_verified_id(self):
        first = self.client.stream_chunk('images', limit=10)
        second = self.client.stream_chunk('images', after_id=first.last_id, limit=10)
        ids = [r['id'] for r in second.records]
        self.assertEqual(ids[0], 11)
        self.assertNotIn(10, ids)

    def test_an_absent_table_is_not_an_error(self):
        chunk = self.client.stream_chunk('mosaics')
        self.assertFalse(chunk.supported)
        self.assertEqual(chunk.rows, 0)

    def test_the_snapshot_bounds_the_export(self):
        """Rows created after the boundary are out of scope, not missing."""
        chunk = self.client.stream_chunk('images', limit=100, snapshot=12)
        self.assertEqual(chunk.rows, 12)
        self.assertEqual(chunk.last_id, 12)

    def test_identity_change_mid_export_stops_everything(self):
        self.client.preflight()
        self.site.site_uuid = 'a-completely-different-site'
        with self.assertRaises(tc.VerificationError) as cm:
            self.client.verify()
        self.assertEqual(cm.exception.code, 'site_identity_changed')


class PreflightTests(unittest.TestCase):

    def test_counts_and_exclusions_come_through(self):
        site = FakeSite({'images': [{'id': 1}], 'posts': [{'id': 1}, {'id': 2}]})
        pre = client_for(site).preflight()
        self.assertEqual(pre['types']['posts']['count'], 2)
        self.assertIn('password hashes', pre['excluded_classes'])

    def test_a_future_stream_format_is_refused_rather_than_guessed_at(self):
        site = FakeSite({'images': []})
        original = site._a_preflight

        def newer(q, headers):
            r = original(q, headers)
            r._json['stream_format'] = 99
            return r
        site._a_preflight = newer
        with self.assertRaises(tc.TyswyError) as cm:
            client_for(site).preflight()
        self.assertEqual(cm.exception.code, 'stream_format_mismatch')


class MediaTests(unittest.TestCase):

    def setUp(self):
        self.blob = bytes(range(256)) * 40          # 10,240 bytes
        self.site = FakeSite({'images': [{'id': 1}]},
                             media={('image', 1, 'original'): self.blob})
        self.client = client_for(self.site)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def test_a_download_is_hashed_and_completed_atomically(self):
        dest = os.path.join(self.tmp.name, 'a', 'photo.jpg')
        res = self.client.download_media(1, dest)
        self.assertEqual(res.bytes_written, len(self.blob))
        self.assertTrue(os.path.exists(dest))
        self.assertFalse(os.path.exists(dest + '.part'))
        import hashlib
        self.assertEqual(res.sha256, hashlib.sha256(self.blob).hexdigest())

    def test_a_partial_file_resumes_rather_than_restarting(self):
        dest = os.path.join(self.tmp.name, 'photo.jpg')
        with open(dest + '.part', 'wb') as f:
            f.write(self.blob[:4000])
        res = self.client.download_media(1, dest)
        self.assertTrue(res.resumed)
        self.assertEqual(res.bytes_written, len(self.blob))
        with open(dest, 'rb') as f:
            self.assertEqual(f.read(), self.blob)

    def test_a_short_response_keeps_the_partial_and_refuses_to_finish(self):
        dest = os.path.join(self.tmp.name, 'photo.jpg')
        original = self.site._a_media

        def truncated(q, headers):
            r = original(q, headers)
            r._body = r._body[:100]          # claims full length, sends less
            return r
        self.site._a_media = truncated
        with self.assertRaises(tc.VerificationError) as cm:
            self.client.download_media(1, dest)
        self.assertEqual(cm.exception.code, 'short_media')
        self.assertFalse(os.path.exists(dest))
        self.assertTrue(os.path.exists(dest + '.part'))

    def test_a_missing_file_reports_the_server_error_verbatim(self):
        dest = os.path.join(self.tmp.name, 'nope.jpg')
        with self.assertRaises(tc.TyswyError) as cm:
            self.client.download_media(99, dest)
        self.assertEqual(cm.exception.code, 'not_found')


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
