"""
Unzucker — SECAUDIT 042 security regressions.

Three findings, and one meta-check that matters more than any of them.

SECAUDIT 040 fixed plaintext-HTTP credential transport in the shared helper
`tools/_shared/snap_stepup.py` and recorded that it closed the same gap in
"Unzucker, GYSS, SUYB, SYBU and Oh Snap at the same time". For Unzucker that was
not true: Unzucker never imported the helper, so nothing about it changed. The
audit was right about the fix and wrong about the blast radius, and nothing in
the tree could tell the difference.

So the load-bearing test here is `test_the_guard_is_actually_wired_in` — it
asserts the import AND the call site, because a shared safety helper that a tool
does not call is indistinguishable from no helper at all, and the failure mode is
a green audit.

Run: python -m unittest discover -s tests

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import importlib.util
import os
import sys
import types
import unittest
from pathlib import Path

TOOL = Path(__file__).resolve().parents[1]
SHARED = TOOL.parent / '_shared'
sys.path.insert(0, str(TOOL))


def _load_stepup():
    """Import the shared guard without dragging in requests."""
    sys.modules.setdefault('requests', types.ModuleType('requests'))
    spec = importlib.util.spec_from_file_location('snap_stepup', SHARED / 'snap_stepup.py')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


class TransportGuardTests(unittest.TestCase):
    """SECAUDIT 042 finding A — the Bearer key crossed plaintext http://."""

    def test_the_guard_is_actually_wired_in(self):
        """The one that would have caught 040's false closure.

        A shared helper nobody calls protects nobody, and it protects nobody
        *while looking protected in the audit trail*."""
        src = (TOOL / 'main.py').read_text(encoding='utf-8')
        self.assertIn('from snap_stepup import confirm_insecure_transport', src,
                      'Unzucker no longer imports the shared transport guard')
        self.assertIn('confirm_insecure_transport(self, url', src,
                      'the transport guard is imported but never called')

    def test_the_guard_runs_before_the_client_is_built(self):
        """Constructing UnzuckerClient puts the key in a session header and the
        next line sends it, so the check has to come first."""
        src = (TOOL / 'main.py').read_text(encoding='utf-8')
        start = src.index('def _on_connect')
        body = src[start:start + 2000]
        guard = body.index('confirm_insecure_transport')
        build = body.index('UnzuckerClient(url, api_key)')
        self.assertLess(guard, build,
                        'the key-carrying client is built before the transport check')

    def test_a_missing_helper_fails_closed(self):
        """An old build without _shared/ must refuse, not silently continue."""
        src = (TOOL / 'main.py').read_text(encoding='utf-8')
        fallback = src[src.index('except Exception:'):src.index('def ', src.index('except Exception:')) + 1200]
        self.assertIn('return False', fallback,
                      'the fallback guard does not refuse when it cannot verify the URL')

    def test_the_shared_guard_classifies_urls_correctly(self):
        m = _load_stepup()
        for url in ('https://site.com', 'http://localhost:8080', 'http://127.0.0.1',
                    'http://[::1]', 'http://127.0.0.5'):
            self.assertEqual(m._insecure_reason(url), '', f'{url} should be allowed')
        for url in ('http://site.com', 'site.com', 'ftp://site.com', 'http://evil.example'):
            self.assertNotEqual(m._insecure_reason(url), '', f'{url} should be blocked')


class KeyStorageTests(unittest.TestCase):
    """SECAUDIT 042 findings B and C — the config file."""

    def setUp(self):
        import config
        self.config = config

    def test_a_failed_keyring_write_does_not_destroy_the_key(self):
        """finding B: _kr_set()'s return value was ignored, so a keyring that
        refused the secret still got the ini wiped — and the key was gone."""
        src = (TOOL / 'config.py').read_text(encoding='utf-8')
        self.assertIn('if _HAS_KEYRING and _kr_set(', src,
                      'save() ignores whether the keyring actually stored the key')

    def test_the_ini_is_written_owner_only(self):
        """finding C: with no keyring the ini holds the key as base64, which is
        an encoding, not encryption. SECAUDIT 040 applied this floor to FLKR
        FCKR; it never reached Unzucker."""
        src = (TOOL / 'config.py').read_text(encoding='utf-8')
        self.assertIn('os.chmod(path, 0o600)', src,
                      'unzucker.ini is not written owner-only')

    def test_keyring_failure_round_trips_through_the_ini(self):
        """Behavioural, not textual: with the keyring refusing every write, a
        saved key must still come back on load."""
        cfg = self.config
        real_set, real_get, real_has = cfg._kr_set, cfg._kr_get, cfg._HAS_KEYRING
        real_path = cfg._config_path
        import tempfile
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        cfg._config_path = lambda: os.path.join(tmp.name, 'unzucker.ini')
        cfg._HAS_KEYRING = True
        cfg._kr_set = lambda account, secret: False      # keyring refuses
        cfg._kr_get = lambda account: ''
        try:
            cfg.save({'url': 'https://site.com', 'api_key': 'k' * 64})
            self.assertEqual(cfg.load()['api_key'], 'k' * 64,
                             'the API key was lost when the keyring refused the write')
        finally:
            cfg._kr_set, cfg._kr_get = real_set, real_get
            cfg._HAS_KEYRING, cfg._config_path = real_has, real_path


class ExportParsingTests(unittest.TestCase):
    """The untrusted side: an Instagram export is a third party's archive.
    Already hardened before this audit — pinned so it stays that way."""

    def test_media_uri_cannot_escape_the_export_folder(self):
        src = (TOOL / 'ig_parser.py').read_text(encoding='utf-8')
        self.assertIn('abs_path.startswith(os.path.normpath(export_root) + os.sep)', src,
                      'the export media path containment check is gone')
        self.assertIn('path traversal', src,
                      'the traversal refusal no longer explains itself')


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
