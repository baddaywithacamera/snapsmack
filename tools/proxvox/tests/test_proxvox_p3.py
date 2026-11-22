"""Real-HTTP execution tests for PROX VOX P3 (WriteClient) and P5 (manual revoke).

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import http.server
import json
import os
import sys
import threading
import unittest
import urllib.error
import urllib.parse

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from proxvox.audit import AuditBuffer
from proxvox.broker import ReadOnlyBroker, Refused
from proxvox.host_guard import WrongHost
from proxvox.write_client import WriteClient


class _FakeClock:
    def __init__(self, start: float = 1000.0):
        self.now = float(start)

    def __call__(self) -> float:
        return self.now

    def advance(self, seconds: float):
        self.now += float(seconds)


class _ProxmoxMockServer:
    """In-process HTTP server standing in for Proxmox /api2/json."""

    def __init__(self, audit_path: str):
        self.requests = []
        self.audit_snapshots = []
        self.ticket_value = "PVE:proxvox@pve:1234ABCD::sig"
        self.csrf_value = "6789EF01:csrf-secret-value"
        self.tfa_challenge_mode = False
        self.empty_csrf_mode = False
        self.force_401 = False
        server_ref = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, fmt, *args):
                pass

            def _handle(self):
                length = int(self.headers.get("Content-Length") or 0)
                raw_body = self.rfile.read(length).decode("utf-8", "replace") if length else ""
                parsed_form = urllib.parse.parse_qs(raw_body) if raw_body else {}
                rec = {
                    "method": self.command,
                    "path": self.path,
                    "cookie": self.headers.get("Cookie", ""),
                    "csrf": self.headers.get("CSRFPreventionToken", ""),
                    "form": parsed_form,
                }
                server_ref.requests.append(rec)
                if os.path.exists(audit_path):
                    with open(audit_path, "r", encoding="utf-8") as fh:
                        server_ref.audit_snapshots.append(fh.read())

                if self.command == "POST" and self.path == "/api2/json/access/ticket":
                    if (parsed_form.get("password") == ["good-pass"]
                            and parsed_form.get("otp") == ["123456"]):
                        if server_ref.tfa_challenge_mode:
                            payload = {"data": {"ticket": "PVE:!tfa!challenge", "CSRFPreventionToken": server_ref.csrf_value}}
                        elif server_ref.empty_csrf_mode:
                            payload = {"data": {"ticket": server_ref.ticket_value, "CSRFPreventionToken": ""}}
                        else:
                            payload = {
                                "data": {
                                    "ticket": server_ref.ticket_value,
                                    "CSRFPreventionToken": server_ref.csrf_value,
                                    "username": parsed_form.get("username", [""])[0],
                                }
                            }
                        body = json.dumps(payload).encode("utf-8")
                        self.send_response(200)
                        self.send_header("Content-Type", "application/json")
                        self.send_header("Content-Length", str(len(body)))
                        self.end_headers()
                        self.wfile.write(body)
                        return
                    self.send_response(401)
                    self.end_headers()
                    return

                if server_ref.force_401:
                    self.send_response(401)
                    self.end_headers()
                    return

                expected_cookie = "PVEAuthCookie=" + server_ref.ticket_value
                if (rec["cookie"] != expected_cookie
                        or rec["csrf"] != server_ref.csrf_value):
                    self.send_response(401)
                    self.end_headers()
                    return

                body = json.dumps({"data": {"ok": True, "path": self.path}}).encode("utf-8")
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            do_GET = _handle
            do_POST = _handle
            do_PUT = _handle
            do_DELETE = _handle
            do_PATCH = _handle

        self._httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self._thread = threading.Thread(target=self._httpd.serve_forever, daemon=True)
        self._thread.start()
        host, port = self._httpd.server_address
        self.base_url = f"http://{host}:{port}"

    def close(self):
        self._httpd.shutdown()
        self._httpd.server_close()


class ProxVoxP3Tests(unittest.TestCase):
    PIN = "11111111-2222-3333-4444-555555555555"

    def setUp(self):
        self.tmp_dir = os.path.join(
            os.path.dirname(__file__), "_tmp_p3_" + self._testMethodName
        )
        os.makedirs(self.tmp_dir, exist_ok=True)
        self.audit_path = os.path.join(self.tmp_dir, "audit.jsonl")
        if os.path.exists(self.audit_path):
            os.remove(self.audit_path)
        self.audit = AuditBuffer(self.audit_path)
        self.server = _ProxmoxMockServer(self.audit_path)
        self.clock = _FakeClock(1000.0)

    def tearDown(self):
        self.server.close()
        if os.path.exists(self.audit_path):
            os.remove(self.audit_path)
        if os.path.isdir(self.tmp_dir):
            os.rmdir(self.tmp_dir)

    def _client(self, ticket_ttl=7200) -> WriteClient:
        return WriteClient(
            base_url=self.server.base_url,
            audit=self.audit,
            pinned_guid=self.PIN,
            actual_guid=self.PIN,
            clock=self.clock,
            ticket_ttl=ticket_ttl,
        )

    def test_host_guard_and_separation_from_p1(self):
        with self.assertRaises(WrongHost):
            WriteClient(self.server.base_url, self.audit, self.PIN, actual_guid="other-guid")
        with self.assertRaises(WrongHost):
            WriteClient(self.server.base_url, self.audit, "", actual_guid=self.PIN)

        p1 = ReadOnlyBroker(
            self.server.base_url, "proxvox@pve!ro", "ro-secret",
            self.audit, self.PIN, actual_guid=self.PIN,
        )
        with self.assertRaises(Refused):
            p1.call("POST", "/nodes/pve/lxc/101/status/start")
        self.assertEqual(len(self.server.requests), 0)

    def test_unauthenticated_write_is_refused_and_logged(self):
        wc = self._client()
        self.assertFalse(wc.is_authorized())
        with self.assertRaises(Refused):
            wc.post("/nodes/pve/lxc/101/status/start")
        self.assertEqual(len(self.server.requests), 0)
        actions = [r["action"] for r in self.audit.records()]
        self.assertEqual(actions, ["write_refused"])

    def test_authorize_requires_all_live_credentials_and_rejects_tfa_challenge(self):
        wc = self._client()
        for bad in [("", "good-pass", "123456"), ("proxvox@pve", "", "123456"), ("proxvox@pve", "good-pass", "")]:
            with self.assertRaises(Refused):
                wc.authorize(*bad)
        self.assertEqual(len(self.server.requests), 0)

        self.server.tfa_challenge_mode = True
        with self.assertRaises(Refused):
            wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertFalse(wc.is_authorized())
        # Pre-request write_auth_attempt must be logged before /access/ticket responds
        self.assertIn('"action":"write_auth_attempt"', self.server.audit_snapshots[0])

        self.server.tfa_challenge_mode = False
        self.server.empty_csrf_mode = True
        with self.assertRaises(Refused):
            wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertFalse(wc.is_authorized())

        # Failed HTTP 401 auth must still leave write_auth_attempt on disk
        self.server.empty_csrf_mode = False
        with self.assertRaises(urllib.error.HTTPError):
            wc.authorize("proxvox@pve", "wrong-pass", "123456")
        actions = [r["action"] for r in self.audit.records()]
        self.assertEqual(actions.count("write_auth_attempt"), 3)

    def test_authorized_session_allows_full_writes_until_expiry_and_hides_secrets(self):
        wc = self._client(ticket_ttl=7200)
        wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertTrue(wc.is_authorized())

        res1 = wc.post("/nodes/pve/lxc/101/status/start")
        res2 = wc.put("/cluster/firewall/options", data={"enable": "1"})
        res3 = wc.delete("/nodes/pve/lxc/101/firewall/rules/0")
        self.assertTrue(res1["data"]["ok"])
        self.assertTrue(res2["data"]["ok"])
        self.assertTrue(res3["data"]["ok"])

        # POST /access/ticket + 3 write calls = 4 HTTP calls, no per-action prompts
        self.assertEqual(len(self.server.requests), 4)
        self.assertEqual(self.server.requests[1]["method"], "POST")
        self.assertEqual(self.server.requests[2]["method"], "PUT")
        self.assertEqual(self.server.requests[3]["method"], "DELETE")

        # Audit record was written BEFORE each write request reached the server
        self.assertIn('"action":"write_call"', self.server.audit_snapshots[1])

        # Secrets must never appear in repr/str or audit log
        text_repr = repr(wc) + str(wc)
        with open(self.audit_path, "r", encoding="utf-8") as fh:
            audit_raw = fh.read()
        for secret in ("good-pass", "123456", self.server.ticket_value, self.server.csrf_value):
            self.assertNotIn(secret, text_repr)
            self.assertNotIn(secret, audit_raw)

    def test_unsafe_paths_and_methods_refused_before_network(self):
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        before_count = len(self.server.requests)

        for bad_path in ("", "../etc/passwd", "//evil.example/x", "https://evil.example/x"):
            with self.assertRaises(Refused):
                wc.post(bad_path)
        with self.assertRaises(Refused):
            wc.call("PATCH", "/nodes/pve/lxc/101/config")

        self.assertEqual(len(self.server.requests), before_count)
        refusals = [r for r in self.audit.records() if r["action"] == "write_refused"]
        self.assertEqual(len(refusals), 5)

    def test_ticket_expiry_and_manual_revoke_p5(self):
        wc = self._client(ticket_ttl=60)
        wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertTrue(wc.is_authorized())

        # Advance past TTL -> refused locally without hitting server
        self.clock.advance(61)
        self.assertFalse(wc.is_authorized())
        before_count = len(self.server.requests)
        with self.assertRaises(Refused):
            wc.post("/nodes/pve/lxc/101/status/stop")
        self.assertEqual(len(self.server.requests), before_count)

        # Re-authorise, then P5 manual revoke -> immediately blocked
        wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertTrue(wc.is_authorized())
        wc.revoke(reason="incident-complete")
        self.assertFalse(wc.is_authorized())
        before_count = len(self.server.requests)
        with self.assertRaises(Refused):
            wc.delete("/nodes/pve/lxc/101")
        self.assertEqual(len(self.server.requests), before_count)

        actions = [r["action"] for r in self.audit.records()]
        self.assertIn("write_revoked", actions)

    def test_server_401_clears_session(self):
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        self.server.force_401 = True
        with self.assertRaises(urllib.error.HTTPError):
            wc.post("/nodes/pve/lxc/101/status/stop")
        self.assertFalse(wc.is_authorized())


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
