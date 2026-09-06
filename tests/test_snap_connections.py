import importlib
import os
import sys
import tempfile
import unittest
from unittest import mock


SHARED = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'tools', '_shared'))
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)


class ConnectionAdapterTests(unittest.TestCase):
    def setUp(self):
        self.profiles = mock.patch('snap_profiles.list_profiles', return_value=[
            {'name': 'One', 'site_url': 'https://one.test', 'portable': {'copyright': 'x'}},
            {'name': 'Two', 'site_url': 'https://two.test'},
        ])
        self.profiles.start()
        self.addCleanup(self.profiles.stop)

    def test_returns_missing_credentials_instead_of_hiding_site(self):
        with mock.patch('snap_creds.get_site', return_value=''):
            import snap_connections
            rows = snap_connections.list_connections('gyss')
        self.assertEqual(2, len(rows))
        self.assertFalse(rows[0]['credential_available'])

    def test_uses_only_requested_scoped_key(self):
        def get_site(site, field, default=''):
            return 'GYSS-KEY' if field == 'api_key_gyss' else ''
        with mock.patch('snap_creds.get_site', side_effect=get_site):
            import snap_connections
            row = snap_connections.resolve('https://one.test/', 'gyss')
        self.assertEqual('GYSS-KEY', row['api_key'])

    def test_does_not_guess_among_multiple_sites(self):
        with mock.patch('snap_creds.get_site', return_value='KEY'):
            import snap_connections
            self.assertIsNone(snap_connections.resolve('', 'tyswy'))


if __name__ == '__main__':
    unittest.main()
