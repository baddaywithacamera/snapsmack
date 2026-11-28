"""Check signed release receipts across separate console commands.

# SNAPSMACK_EOF_HEADER
"""

import hashlib
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from apply_release_client import apply_release, FILES


class SignedReleaseClientTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.source = Path(directory.name)
        self.hashes = {}
        for name in FILES:
            self.source.joinpath(name).write_bytes(b"reviewed action")
            self.hashes[name] = hashlib.sha256(b"reviewed action").hexdigest()
        self.client = SimpleNamespace(is_authorized=lambda: True, audit=Mock())
        self.commands = []
        self.manifest = {
            "version": "0.7.1009D",
            "download_url": "https://snapsmack.ca/releases/snapsmack-0.7.1009D.zip",
        }

    def run_release(self, receipts):
        def shell(command):
            self.commands.append(command)
            for marker in ("STAGING_READY", "CHUNK_STAGED", "SCRIPT_VERIFIED", "WORKER_STARTED"):
                if f"echo {marker}" in command:
                    return marker + "\r\n"
            if "echo RESULT_CHECKED" in command:
                return next(receipts)
            if command.startswith("rm -f --"):
                return ""
            self.fail(f"Unexpected shell command: {command}")

        return apply_release(self.client, shell, "example.test", "0.7.1007D",
                             self.manifest, self.source, self.hashes)

    @patch("apply_release_client.time.sleep")
    def test_poll_then_verify_one_persisted_receipt(self, sleep):
        result = self.run_release(iter([
            "RESULT_CHECKED\r\n",
            '{"site":"example.test","version":"0.7.1009D","http":200}\r\n'
            "PROXVOX_SIGNED_RELEASE_PASS\r\nRESULT_READY\r\nRESULT_CHECKED\r\n",
        ]))
        self.assertEqual(result["http"], 200)
        sleep.assert_called_once_with(5)
        self.assertTrue(self.commands[-1].startswith("rm -f --"))
        self.client.audit.record.assert_any_call("signed_site_update_verified", result)

    @patch("apply_release_client.time.monotonic", side_effect=(0, 1501))
    def test_missing_receipt_preserves_staging(self, _clock):
        with self.assertRaisesRegex(RuntimeError, "no completion receipt"):
            self.run_release(iter(()))
        self.assertFalse(any(command.startswith("rm -f --") for command in self.commands))
        self.assertEqual(self.client.audit.record.call_args.args[0], "signed_site_update_result_pending")


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
