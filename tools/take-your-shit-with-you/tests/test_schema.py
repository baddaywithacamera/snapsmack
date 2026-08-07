"""
TAKE YOUR SHIT WITH YOU — the archive validates against its own schema.

Spec section 16 says a JSON Schema ships in the archive and with the application.
A schema that ships but has drifted from what the writer actually produces is
worse than none: it tells someone reading the archive in ten years that their
file is malformed when the truth is that our documentation rotted.

So the schema is checked against real output from a real export, not against a
handwritten example that was written to pass.

Skipped, loudly, when `jsonschema` is not installed — a missing optional test
dependency should not look like a passing test.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))

import export_engine as ee          # noqa: E402
import portable_archive as pa       # noqa: E402
import tyswy_client as tc           # noqa: E402
from fake_server import FakeSite    # noqa: E402
from test_export_engine import sample_media, sample_tables   # noqa: E402

try:
    import jsonschema
    HAVE_JSONSCHEMA = True
except ImportError:
    HAVE_JSONSCHEMA = False


@unittest.skipUnless(HAVE_JSONSCHEMA, 'jsonschema is not installed')
class SchemaTests(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        site = FakeSite(sample_tables(), sample_media())
        client = tc.TyswyClient('https://fauxlaroid.fyi', 'k' * 64,
                                session=site, max_retries=0)
        engine = ee.ExportEngine(
            client, cls.tmp.name,
            options=ee.ExportOptions(media_concurrency=1),
            client_factory=lambda: tc.TyswyClient(
                'https://fauxlaroid.fyi', 'k' * 64, session=site, max_retries=0))
        cls.report = engine.run()
        cls.root = Path(cls.report.root)
        cls.schema = json.loads(
            (cls.root / 'schema' / pa.SCHEMA_FILE).read_text('utf-8'))

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def _validate(self, obj, def_name, where):
        sub = dict(self.schema)
        sub.pop('oneOf', None)
        sub['$ref'] = f'#/$defs/{def_name}'
        try:
            jsonschema.validate(obj, sub)
        except jsonschema.ValidationError as e:
            self.fail(f'{where} does not match the shipped schema '
                      f'({def_name}): {e.message} at {list(e.absolute_path)}')

    def test_the_schema_itself_is_a_valid_schema(self):
        jsonschema.Draft202012Validator.check_schema(self.schema)

    def test_every_image_sidecar_validates(self):
        files = sorted((self.root / 'content' / 'images').glob('*.json'))
        self.assertTrue(files, 'no image sidecars were written')
        for f in files:
            self._validate(json.loads(f.read_text('utf-8')), 'imageSidecar', f.name)

    def test_every_post_sidecar_validates(self):
        files = sorted((self.root / 'content' / 'posts').glob('*.json'))
        self.assertTrue(files, 'no post sidecars were written')
        for f in files:
            self._validate(json.loads(f.read_text('utf-8')), 'postSidecar', f.name)

    def test_every_page_sidecar_validates(self):
        for f in sorted((self.root / 'content' / 'pages').glob('*.json')):
            self._validate(json.loads(f.read_text('utf-8')), 'pageSidecar', f.name)

    def test_the_manifest_validates(self):
        self._validate(json.loads((self.root / 'manifest.json').read_text('utf-8')),
                       'manifest', 'manifest.json')

    def test_the_verification_file_validates(self):
        self._validate(json.loads((self.root / 'verification.json').read_text('utf-8')),
                       'verification', 'verification.json')

    def test_site_json_validates(self):
        self._validate(json.loads((self.root / 'site.json').read_text('utf-8')),
                       'site', 'site.json')

    def test_the_top_level_choice_actually_discriminates(self):
        """oneOf has to match exactly one shape. If two defs are loose enough to
        both match, validation fails for a reason that has nothing to do with the
        file being wrong — so prove each real file picks exactly one."""
        v = jsonschema.Draft202012Validator(self.schema)
        for rel in ('manifest.json', 'verification.json', 'site.json',
                    'content/images/000001-rusty-truck.json',
                    'content/posts/000010-a-drive-east.json',
                    'content/pages/000030-about.json'):
            obj = json.loads((self.root / rel).read_text('utf-8'))
            matches = [i for i, sub in enumerate(self.schema['oneOf'])
                       if jsonschema.Draft202012Validator(
                           {**{k: x for k, x in self.schema.items() if k != 'oneOf'},
                            **sub}).is_valid(obj)]
            self.assertEqual(len(matches), 1,
                             f'{rel} matched {len(matches)} schema shapes, not 1')


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
