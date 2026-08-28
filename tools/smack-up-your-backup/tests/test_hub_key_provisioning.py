"""Regression coverage for the hub-only SUYB shared-key path."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

from __future__ import annotations

import sys
import types
import unittest
from pathlib import Path
from unittest import mock

SHARED_DIR = Path(__file__).resolve().parents[2] / "_shared"
if str(SHARED_DIR) not in sys.path:
    sys.path.insert(0, str(SHARED_DIR))

# Keep this pure unit test runnable in the repository's dependency-light test
# environment. The real packaged application supplies requests and the shared
# stores; every network/store interaction below is mocked explicitly.
if "requests" not in sys.modules:
    requests_stub = types.ModuleType("requests")
    requests_stub.post = None
    requests_stub.RequestException = Exception
    sys.modules["requests"] = requests_stub
if "snap_creds" not in sys.modules:
    creds_stub = types.ModuleType("snap_creds")
    creds_stub.get = lambda key, default="": default
    creds_stub.set = lambda key, value: None
    sys.modules["snap_creds"] = creds_stub
if "snap_profiles" not in sys.modules:
    profiles_stub = types.ModuleType("snap_profiles")
    profiles_stub.save = lambda profile: None
    sys.modules["snap_profiles"] = profiles_stub

import snap_discovery


class _Response:
    def __init__(self, status=200, payload=None):
        self.status_code = status
        self._payload = payload or {"ok": True}

    def json(self):
        return self._payload


class HubProvisioningTests(unittest.TestCase):
    def test_hub_uses_local_endpoint_not_spoke_route(self):
        with mock.patch.object(snap_discovery.requests, "post", return_value=_Response()) as post:
            result = snap_discovery._provision_hub_backup_key(
                "https://hub.example/", "h" * 64, "a" * 64)
        self.assertEqual(result, "a" * 64)
        self.assertEqual(post.call_args.args[0], "https://hub.example/suyb-data.php")
        self.assertEqual(post.call_args.kwargs["json"]["action"], "provision-backup-key")
        self.assertNotIn("multisite/provision-key", post.call_args.args[0])

    def test_shared_rollout_separates_hub_from_spokes(self):
        hub = {"site_url": "https://hub.example", "site_name": "Hub"}
        spokes = [{"role": "spoke", "site_url": "https://spoke.example",
                   "api_key_local": "s" * 64}]
        shared = "b" * 64
        with mock.patch.object(snap_discovery, "discover", return_value=(hub, spokes)), \
             mock.patch.object(snap_discovery, "_provision_hub_backup_key", return_value=shared) as hp, \
             mock.patch.object(snap_discovery, "_provision_spoke_key", return_value=shared) as sp, \
             mock.patch.object(snap_discovery.snap_creds, "get", return_value=""), \
             mock.patch.object(snap_discovery.snap_creds, "set") as store:
            result = snap_discovery.provision_shared_backup_key(
                "https://hub.example", "h" * 64, key_value=shared)
        hp.assert_called_once_with("https://hub.example", "h" * 64, shared, timeout=30)
        sp.assert_called_once_with("https://spoke.example", "s" * 64,
                                   key_type="suyb", key_value=shared, timeout=30)
        store.assert_called_once_with("backup_hub_key", shared)
        self.assertEqual(result["sites_failed"], [])

    def test_partial_rollout_does_not_publish_new_key(self):
        hub = {"site_url": "https://hub.example", "site_name": "Hub"}
        with mock.patch.object(snap_discovery, "discover", return_value=(hub, [])), \
             mock.patch.object(snap_discovery, "_provision_hub_backup_key", return_value=""), \
             mock.patch.object(snap_discovery.snap_creds, "get", return_value=""), \
             mock.patch.object(snap_discovery.snap_creds, "set") as store:
            result = snap_discovery.provision_shared_backup_key(
                "https://hub.example", "h" * 64, key_value="c" * 64)
        store.assert_not_called()
        self.assertEqual(result["sites_failed"], ["https://hub.example"])


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
