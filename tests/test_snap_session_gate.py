import json
import os
import sys
import tempfile
import unittest
from unittest import mock

SHARED = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "tools", "_shared"))
sys.path.insert(0, SHARED)

import snap_session_gate as gate
import snap_stepup


class SessionGateTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = os.path.join(self.tmp.name, "session.json")
        self.patch = mock.patch.object(gate, "_state_path", return_value=self.path)
        self.patch.start()
        self.addCleanup(self.patch.stop)

    def test_fresh_session_allows_online(self):
        gate.save(gate.SessionState(login_at=100, totp_at=100, totp_window_days=30))
        decision = gate.evaluate(online=True, now=200)
        self.assertTrue(decision.allow_online)

    def test_login_expires_after_24_hours(self):
        gate.save(gate.SessionState(login_at=100, totp_at=100, totp_window_days=30))
        decision = gate.evaluate(online=True, now=100 + gate.LOGIN_SECONDS)
        self.assertTrue(decision.require_login)
        self.assertFalse(decision.allow_online)

    def test_expired_totp_offline_sets_reconnect_latch(self):
        gate.save(gate.SessionState(login_at=4_000_000, totp_at=1,
                                    totp_window_days=30))
        offline = gate.evaluate(online=False, now=4_000_100)
        self.assertTrue(offline.allow_local)
        self.assertFalse(offline.allow_online)
        self.assertTrue(gate.load().totp_required_on_reconnect)
        online = gate.evaluate(online=True, now=4_000_101)
        self.assertTrue(online.require_totp)
        self.assertFalse(online.allow_online)

    def test_authorize_persists_no_password_or_totp(self):
        result = snap_stepup.AuthResult(True, "ok", username="sean",
                                        totp_window_days=14)
        requester = mock.Mock(return_value=result)
        gate.authorize("https://hub.test", "desktop/authorize", "scoped",
                       "sean", "do-not-store", "123456", now=500,
                       requester=requester)
        with open(self.path, encoding="utf-8") as handle:
            raw = handle.read()
        self.assertNotIn("do-not-store", raw)
        self.assertNotIn("123456", raw)
        self.assertEqual(gate.load().totp_window_days, 14)
        self.assertFalse(gate.load().totp_required_on_reconnect)


if __name__ == "__main__":
    unittest.main()
