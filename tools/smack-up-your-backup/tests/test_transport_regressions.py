"""
SUYB — SECAUDIT 042 transport regressions.

SECAUDIT 037 deferred one item: "refusing (or hard-warning on) plaintext-http
admin login in hub_discovery.py". SECAUDIT 040 then recorded that item as
resolved by the shared helper. SECAUDIT 042 found that neither `hub_discovery.py`
nor `backup_engine.py` had ever imported that helper — so the item was open the
whole time, in two files, and both post an ACCOUNT PASSWORD.

That is why these tests assert the wiring and the ordering rather than just the
helper's behaviour. The helper was always correct. What was missing was anything
connecting it to the code that sends the password, and no test in the tree could
tell the difference between "covered" and "never called".

Password paths REFUSE (raise). That is deliberate and differs from the
warn-and-confirm the scoped-key tools get: a scoped key can be revoked, an
operator's account password cannot be without locking them out of their own site.

Run: python -m unittest discover -s tests

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import importlib.util
import sys
import types
import unittest
from pathlib import Path

TOOL = Path(__file__).resolve().parents[1]
SHARED = TOOL.parent / '_shared'


def _load_stepup():
    sys.modules.setdefault('requests', types.ModuleType('requests'))
    spec = importlib.util.spec_from_file_location('snap_stepup', SHARED / 'snap_stepup.py')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


class SharedGuardTests(unittest.TestCase):

    def test_a_gui_free_entry_point_exists(self):
        """A transport layer inside a library cannot pop a dialog, and should not
        import tkinter to find out whether a URL is safe."""
        m = _load_stepup()
        self.assertTrue(hasattr(m, 'insecure_transport_reason'),
                        'the GUI-free scheme check is gone from the shared helper')
        self.assertEqual(m.insecure_transport_reason('https://site.com'), '')
        self.assertNotEqual(m.insecure_transport_reason('http://site.com'), '')

    def test_loopback_is_exempt(self):
        m = _load_stepup()
        for url in ('http://localhost:8080', 'http://127.0.0.1', 'http://[::1]'):
            self.assertEqual(m.insecure_transport_reason(url), '',
                             f'{url} has no network path to intercept and must be allowed')


class PasswordPathTests(unittest.TestCase):
    """Both files that POST {username, password}."""

    def test_backup_engine_login_is_wired_and_refuses(self):
        src = (TOOL / 'backup_engine.py').read_text(encoding='utf-8')
        self.assertIn('insecure_transport_reason', src,
                      'backup_engine.py does not check the scheme before sending a password')
        self.assertIn('raise RuntimeError(_reason)', src,
                      'backup_engine.py warns instead of refusing on a password path')

    def test_backup_engine_checks_before_the_password_is_assigned(self):
        src = (TOOL / 'backup_engine.py').read_text(encoding='utf-8')
        body = src[src.index('def login('):]
        body = body[:body.index('def ', 10)]
        self.assertLess(body.index('_insecure_transport_reason'), body.index('self._password = password'),
                        'the scheme check runs after the password has been taken')
        self.assertLess(body.index('_insecure_transport_reason'), body.index('self.session.post'),
                        'the scheme check runs after the password has been sent')

    def test_hub_discovery_is_wired_and_refuses(self):
        """The exact file SECAUDIT 037 named and 040 claimed to have fixed."""
        src = (TOOL / 'hub_discovery.py').read_text(encoding='utf-8')
        self.assertIn('insecure_transport_reason', src,
                      'hub_discovery.py still has no scheme check — the 037 item is open again')
        self.assertIn('raise DiscoveryError(_reason)', src,
                      'hub_discovery.py does not refuse on an insecure URL')

    def test_hub_discovery_checks_before_posting(self):
        src = (TOOL / 'hub_discovery.py').read_text(encoding='utf-8')
        guard = src.index('_reason = insecure_transport_reason')
        post = src.index('resp = s.post(login_url')
        self.assertLess(guard, post, 'the check runs after the password is posted')

    def test_a_missing_helper_fails_closed(self):
        """An old build without _shared/ must refuse, not assume the URL is fine."""
        for name in ('backup_engine.py', 'hub_discovery.py'):
            src = (TOOL / name).read_text(encoding='utf-8')
            # Anchored on the guard's own import rather than the first `except`
            # in the file, which may belong to something else entirely.
            anchor = src.index('from snap_stepup import')
            fallback = src[anchor:anchor + 1600]
            self.assertIn("startswith('https://')", fallback,
                          f'{name} fallback does not require https')
            self.assertIn('sent across the network in the clear', fallback,
                          f'{name} fallback does not explain the refusal')

    def test_the_bearer_key_path_is_not_gated_as_a_password(self):
        """A Bearer-key profile returns before the login POST and is deliberately
        not subject to the hard refusal — key and password are different risks."""
        src = (TOOL / 'backup_engine.py').read_text(encoding='utf-8')
        body = src[src.index('def login('):]
        body = body[:body.index('def ', 10)]
        self.assertLess(body.index('if self._api_key:'), body.index('_insecure_transport_reason'),
                        'the API-key early return no longer precedes the password guard')


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
