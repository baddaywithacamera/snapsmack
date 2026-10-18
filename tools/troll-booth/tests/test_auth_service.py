# SNAPSMACK_EOF_HEADER
"""Authentication and loopback-service tests."""

import json
import sys
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(HERE))

from troll_booth.auth import AuthError, AuthManager, generate_totp_secret, totp
from troll_booth.service import BoothService


class TestAuthentication(unittest.TestCase):
    def test_password_totp_and_emergency_lock(self):
        with tempfile.TemporaryDirectory() as directory:
            manager = AuthManager(Path(directory) / "credentials.json", session_seconds=60)
            secret = generate_totp_secret()
            manager.configure("correct horse battery", secret)
            token, _expires = manager.unlock("codex", "correct horse battery", totp(secret))
            self.assertEqual(manager.authenticate(token).actor, "codex")
            manager.lock()
            with self.assertRaises(AuthError):
                manager.authenticate(token)

    def test_wrong_totp_is_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            manager = AuthManager(Path(directory) / "credentials.json")
            manager.configure("correct horse battery", generate_totp_secret())
            with self.assertRaises(AuthError):
                manager.unlock("sean", "correct horse battery", "000000", now=0)

    def test_pairing_is_actor_scoped_and_single_use(self):
        with tempfile.TemporaryDirectory() as directory:
            manager = AuthManager(Path(directory) / "credentials.json")
            secret = manager.configure("correct horse battery", generate_totp_secret())
            owner, _ = manager.unlock("sean", "correct horse battery", totp(secret))
            code, _ = manager.create_pairing(owner, "claude")
            token, session = manager.consume_pairing(code)
            self.assertEqual(session.actor, "claude")
            self.assertEqual(manager.authenticate(token).actor, "claude")
            with self.assertRaises(AuthError):
                manager.consume_pairing(code)


class TestLoopbackService(unittest.TestCase):
    def test_service_is_loopback_locked_and_can_be_emergency_locked(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            service = BoothService(HERE, port=0)
            service.auth = AuthManager(root / "credentials.json")
            secret = service.auth.configure("correct horse battery", generate_totp_secret())
            thread = threading.Thread(target=service.serve, daemon=True)
            thread.start()
            base = f"http://{service.address[0]}:{service.address[1]}"
            try:
                self.assertEqual(service.address[0], "127.0.0.1")
                with urllib.request.urlopen(base + "/v1/health", timeout=2) as response:
                    self.assertTrue(json.load(response)["locked"])
                with self.assertRaises(urllib.error.HTTPError) as refused:
                    urllib.request.urlopen(base + "/v1/apps", timeout=2)
                self.assertEqual(refused.exception.code, 401)
                token, _ = service.auth.unlock(
                    "codex", "correct horse battery", totp(secret))
                request = urllib.request.Request(
                    base + "/v1/apps", headers={"Authorization": "Bearer " + token})
                with urllib.request.urlopen(request, timeout=5) as response:
                    self.assertTrue(json.load(response)["ok"])
                lock = urllib.request.Request(
                    base + "/v1/lock", data=b"{}", method="POST",
                    headers={"Authorization": "Bearer " + token,
                             "Content-Type": "application/json"})
                with urllib.request.urlopen(lock, timeout=2) as response:
                    self.assertTrue(json.load(response)["locked"])
                with self.assertRaises(AuthError):
                    service.auth.authenticate(token)
            finally:
                service.server.shutdown()
                service.close()
                thread.join(timeout=2)

    def test_http_pairing_issues_scoped_session(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            service = BoothService(HERE, port=0)
            service.auth = AuthManager(root / "credentials.json")
            secret = service.auth.configure("correct horse battery", generate_totp_secret())
            owner, _ = service.auth.unlock("sean", "correct horse battery", totp(secret))
            code, _ = service.auth.create_pairing(owner, "codex")
            thread = threading.Thread(target=service.serve, daemon=True)
            thread.start()
            base = f"http://{service.address[0]}:{service.address[1]}"
            try:
                request = urllib.request.Request(
                    base + "/v1/pair", data=json.dumps({"code": code}).encode(),
                    method="POST", headers={"Content-Type": "application/json"})
                with urllib.request.urlopen(request, timeout=2) as response:
                    paired = json.load(response)
                self.assertEqual(paired["actor"], "codex")
                self.assertEqual(service.auth.authenticate(paired["token"]).actor, "codex")
                with self.assertRaises(urllib.error.HTTPError) as reused:
                    urllib.request.urlopen(request, timeout=2)
                self.assertEqual(reused.exception.code, 401)
            finally:
                service.server.shutdown()
                service.close()
                thread.join(timeout=2)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
