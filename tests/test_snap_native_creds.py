import os
import sys
import unittest

SHARED = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'tools', '_shared'))
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

import snap_native_creds


@unittest.skipUnless(os.name == 'nt', 'Windows Credential Manager contract')
class NativeCredentialTests(unittest.TestCase):
    def test_round_trip_without_plaintext_file(self):
        site, kind, secret = 'https://native-bridge.invalid', 'test', 'secret-value'
        try:
            self.assertTrue(snap_native_creds.set_site(site, kind, secret))
            self.assertEqual(secret, snap_native_creds.get_site(site, kind))
        finally:
            snap_native_creds.delete_site(site, kind)


if __name__ == '__main__':
    unittest.main()
