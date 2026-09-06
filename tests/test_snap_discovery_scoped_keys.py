import os
import sys
import unittest
from unittest import mock
import types


SHARED = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'tools', '_shared'))
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

if 'requests' not in sys.modules:
    sys.modules['requests'] = types.SimpleNamespace()
import snap_discovery


class DiscoveryScopedKeyTests(unittest.TestCase):
    def test_provisions_every_supported_tool_key(self):
        saved = []

        def provision(site, full_key, key_type='sybu', key_value='', timeout=20):
            return key_type.upper() + '-KEY'

        with mock.patch.object(snap_discovery, '_save_cloud_to_vault', return_value=[]), \
             mock.patch.object(snap_discovery, '_provision_spoke_key', side_effect=provision) as mint, \
             mock.patch.object(snap_discovery, '_provision_hub_tool_key', side_effect=lambda s, k, t: t.upper() + '-HUB'), \
             mock.patch.object(snap_discovery.snap_native_creds, 'set_site', return_value=True), \
             mock.patch.object(snap_discovery.snap_profiles, 'save', side_effect=lambda p: saved.append(p)):
            result = snap_discovery.save_to_shared(
                {'site_url': 'https://hub.test', 'site_name': 'Hub'},
                [{'site_url': 'https://spoke.test', 'site_name': 'Spoke',
                  'api_key_local': 'FULL'}],
                hub_api_key='HUB')

        called_types = [call.args[2] for call in mint.call_args_list]
        self.assertEqual(
            ['sybu', 'gyss', 'ohsnap', 'tyswy', 'unzucker', 'flkrfckr', 'smackpress'],
            called_types)
        spoke = next(p for p in saved if p['site_url'] == 'https://spoke.test')
        for key_type in called_types:
            self.assertEqual(key_type.upper() + '-KEY',
                             spoke['extras']['api_key_' + key_type])
        self.assertEqual('SYBU-KEY', spoke['api_key'])
        hub = next(p for p in saved if p['site_url'] == 'https://hub.test')
        self.assertEqual('GYSS-HUB', hub['extras']['api_key_gyss'])
        self.assertEqual(2, result['count'])


if __name__ == '__main__':
    unittest.main()
