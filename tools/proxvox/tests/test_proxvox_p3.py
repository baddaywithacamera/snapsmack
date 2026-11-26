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
import urllib.request

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
        self.challenge_value = "PVE:!tfa!challenge-value"
        self.stage_one_full_ticket_mode = False
        self.stage_two_challenge_mode = False
        self.stage_two_401_mode = False
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
                    if (parsed_form.get("username") == ["proxvox@pve"]
                            and parsed_form.get("password") == ["good-pass"]
                            and "tfa-challenge" not in parsed_form
                            and "otp" not in parsed_form):
                        if server_ref.stage_one_full_ticket_mode:
                            payload = {"data": {
                                "ticket": server_ref.ticket_value,
                                "CSRFPreventionToken": server_ref.csrf_value,
                            }}
                        else:
                            payload = {"data": {"ticket": server_ref.challenge_value}}
                        body = json.dumps(payload).encode("utf-8")
                        self.send_response(200)
                        self.send_header("Content-Type", "application/json")
                        self.send_header("Content-Length", str(len(body)))
                        self.end_headers()
                        self.wfile.write(body)
                        return

                    if (parsed_form.get("username") == ["proxvox@pve"]
                            and parsed_form.get("tfa-challenge") == [server_ref.challenge_value]
                            and parsed_form.get("password") == ["totp:123456"]):
                        if server_ref.stage_two_401_mode:
                            self.send_response(401)
                            self.end_headers()
                            return
                        if server_ref.stage_two_challenge_mode:
                            payload = {"data": {
                                "ticket": server_ref.challenge_value,
                                "CSRFPreventionToken": server_ref.csrf_value,
                            }}
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

                if self.path.endswith("/termproxy"):
                    payload = {"data": {"port": 5901, "ticket": "PVEVNC:console-ticket"}}
                else:
                    payload = {"data": {"ok": True, "path": self.path}}
                body = json.dumps(payload).encode("utf-8")
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

    def _client(self, ticket_ttl=7200, expiry_timer_factory=None, host_writer=None) -> WriteClient:
        return WriteClient(
            base_url=self.server.base_url,
            audit=self.audit,
            pinned_guid=self.PIN,
            actual_guid=self.PIN,
            opener=urllib.request.urlopen,
            clock=self.clock,
            ticket_ttl=ticket_ttl,
            expiry_timer_factory=expiry_timer_factory,
            host_writer=host_writer,
        )

    def test_host_writer_receives_the_real_second_stage_and_explicit_revoke(self):
        from unittest.mock import Mock

        host = Mock()
        host.authorize_session.return_value = {
            "ticket": "PVE:proxvox@pve:fixture-host-ticket",
            "csrf": "fixture-host-csrf",
            "expires_at": 4600.0,
        }
        wc = self._client(host_writer=host)
        auth = wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertEqual(len(self.server.requests), 1)
        self.assertEqual(auth["expires_at"], 4600.0)
        host.authorize_session.assert_called_once_with(
            "proxvox@pve", self.server.challenge_value, "123456",
        )
        self.assertTrue(wc.is_authorized())
        wc.revoke()
        self.assertFalse(wc.is_authorized())
        host.revoke_session.assert_called_once_with(
            "proxvox@pve", "PVE:proxvox@pve:fixture-host-ticket",
        )

    def test_host_guard_and_separation_from_p1(self):
        with self.assertRaises(WrongHost):
            WriteClient(self.server.base_url, self.audit, self.PIN, actual_guid="other-guid")
        with self.assertRaises(WrongHost):
            WriteClient(self.server.base_url, self.audit, "", actual_guid=self.PIN)

        p1 = ReadOnlyBroker(
            self.server.base_url, "proxvox@pve!ro", "ro-secret",
            self.audit, self.PIN, actual_guid=self.PIN,
            opener=urllib.request.urlopen,
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
        self.assertEqual(actions, ["transport", "write_refused"])

    def test_authorize_requires_credentials_and_rejects_incomplete_tfa(self):
        wc = self._client()
        for bad in [("", "good-pass", "123456"), ("proxvox@pve", "", "123456"), ("proxvox@pve", "good-pass", "")]:
            with self.assertRaises(Refused):
                wc.authorize(*bad)
        self.assertEqual(len(self.server.requests), 0)

        # Isolate the stage-1 prefix guard: a full ticket plus valid CSRF is
        # still wrong here because this client requires a TFA challenge.
        self.server.stage_one_full_ticket_mode = True
        with self.assertRaises(Refused):
            wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertFalse(wc.is_authorized())
        self.server.stage_one_full_ticket_mode = False

        # Isolate the stage-2 prefix guard: include valid CSRF so only the
        # repeated PVE:!tfa! challenge makes this response incomplete.
        self.server.stage_two_challenge_mode = True
        with self.assertRaises(Refused):
            wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertFalse(wc.is_authorized())
        # Each stage is logged before its /access/ticket request reaches the server.
        self.assertIn('"action":"write_auth_attempt"', self.server.audit_snapshots[1])
        self.assertIn('"stage":1', self.server.audit_snapshots[1])
        self.assertIn('"stage":2', self.server.audit_snapshots[2])

        self.server.stage_two_challenge_mode = False
        self.server.empty_csrf_mode = True
        with self.assertRaises(Refused):
            wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertFalse(wc.is_authorized())

        # Failed HTTP 401 auth must still leave write_auth_attempt on disk
        self.server.empty_csrf_mode = False
        with self.assertRaises(urllib.error.HTTPError):
            wc.authorize("proxvox@pve", "wrong-pass", "123456")
        actions = [r["action"] for r in self.audit.records()]
        self.assertEqual(actions.count("write_auth_attempt"), 6)

    def test_stage_two_401_is_refused_logged_and_clears_session(self):
        wc = self._client()
        self.server.stage_two_401_mode = True
        with self.assertRaises(urllib.error.HTTPError) as caught:
            wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertEqual(caught.exception.code, 401)
        self.assertFalse(wc.is_authorized())
        self.assertEqual(len(self.server.requests), 2)
        self.assertIn('"stage":2', self.server.audit_snapshots[1])
        records = self.audit.records()
        self.assertEqual(records[-1]["action"], "write_auth_result")
        self.assertEqual(records[-1]["detail"]["stage"], 2)
        self.assertEqual(records[-1]["detail"]["status"], 401)

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

        # Two-stage /access/ticket + 3 writes = 5 calls, no per-action prompts.
        self.assertEqual(len(self.server.requests), 5)
        self.assertEqual(self.server.requests[2]["method"], "POST")
        self.assertEqual(self.server.requests[3]["method"], "PUT")
        self.assertEqual(self.server.requests[4]["method"], "DELETE")

        # Audit record was written BEFORE each write request reached the server
        self.assertIn('"action":"write_call"', self.server.audit_snapshots[2])

        # Secrets must never appear in repr/str or audit log
        text_repr = repr(wc) + str(wc)
        with open(self.audit_path, "r", encoding="utf-8") as fh:
            audit_raw = fh.read()
        for secret in ("good-pass", "123456", self.server.challenge_value,
                       self.server.ticket_value, self.server.csrf_value):
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

    def test_container_console_exists_only_while_totp_ticket_is_live(self):
        wc = self._client(ticket_ttl=60)
        connected = []

        class Console:
            def __init__(self):
                self.closed = False

            def run(self, command):
                self.command = command
                self.audit_before_run = self_outer.audit.records()[-1]["action"]
                return "done"

            def close(self):
                self.closed = True

        self_outer = self

        def connector(**kwargs):
            self.assertNotIn("good-pass", str(kwargs))
            self.assertEqual(kwargs["pve_ticket"], self.server.ticket_value)
            console = Console()
            connected.append(console)
            return console

        with self.assertRaises(Refused):
            wc.open_container_console("proxmox", 102, connector=connector)
        self.assertEqual(connected, [])

        wc.authorize("proxvox@pve", "good-pass", "123456")
        console = wc.open_container_console("proxmox", 102, connector=connector)
        self.assertEqual(wc.run_container_command(console, "touch /tmp/proof"), "done")
        self.assertEqual(console.audit_before_run, "container_write_call")
        self.assertEqual(console.command, "touch /tmp/proof")

        wc.revoke("proof-complete")
        self.assertTrue(console.closed)
        with self.assertRaises(Refused):
            wc.run_container_command(console, "touch /tmp/after-revoke")

    def test_ticket_expiry_closes_every_container_console(self):
        timers = []

        class Timer:
            def __init__(self, seconds, callback):
                self.seconds = seconds
                self.callback = callback
                self.cancelled = False
                timers.append(self)
            def start(self):
                pass
            def cancel(self):
                self.cancelled = True
            def fire(self):
                self.callback()

        wc = self._client(ticket_ttl=1, expiry_timer_factory=Timer)

        class Console:
            closed = False
            def close(self):
                self.closed = True

        console = Console()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        opened = wc.open_container_console(
            "proxmox", 106, connector=lambda **_: console
        )
        self.assertEqual(len(timers), 1)
        timers[0].fire()
        self.assertTrue(opened.closed)
        self.assertFalse(wc.is_authorized())
        self.assertIn("write_expired", [row["action"] for row in self.audit.records()])

    def test_failed_console_connect_is_not_logged_as_open_and_hides_ticket(self):
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")

        def connector(**_):
            raise RuntimeError("transport failed")

        with self.assertRaises(RuntimeError):
            wc.open_container_console("proxmox", 102, connector=connector)
        self.assertNotIn(
            "container_console_open", [row["action"] for row in self.audit.records()]
        )


    # ── SS-1507: the grant for the scoped FILE writer ────────────────────
    #
    # The Proxmox API cannot put a file on the host, so the scoped writer is a
    # forced SSH command, and it refuses to act on the SSH account alone. The
    # grant is what proves a live owner login. These check the thing that
    # matters most about it: that the ticket inside it never reaches anywhere
    # else, and that it authorises exactly ONE operation.

    def test_a_grant_needs_a_live_session(self):
        wc = self._client()
        with self.assertRaises(Refused):
            wc.mint_file_grant(target="host", path="/var/www/x.php",
                               sha256="a" * 64)
        self.assertIn("file_grant_refused",
                      [r["action"] for r in self.audit.records()])

    def test_a_grant_is_bound_to_target_path_and_content(self):
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        grant = json.loads(wc.mint_file_grant(
            target="host", path="/var/www/x.php", sha256="B" * 64))
        self.assertEqual(grant["target"], "host")
        self.assertEqual(grant["path"], "/var/www/x.php")
        self.assertEqual(grant["sha256"], "b" * 64)   # normalised to lower case
        self.assertEqual(grant["user"], "proxvox@pve")
        self.assertTrue(grant["ticket"])

    def test_the_grant_cannot_outlive_the_session_it_rests_on(self):
        # A session with 60 seconds left must not mint a 300-second grant: the
        # host would still accept it after the owner's authorisation lapsed.
        wc = self._client(ticket_ttl=60)
        wc.authorize("proxvox@pve", "good-pass", "123456")
        grant = json.loads(wc.mint_file_grant(
            target="host", path="/var/www/x.php", sha256="c" * 64, seconds=300))
        self.assertLessEqual(grant["expires"], float(self.clock()) + 60)

    def test_an_over_long_grant_is_refused_not_quietly_shortened(self):
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        with self.assertRaises(Refused):
            wc.mint_file_grant(target="host", path="/var/www/x.php",
                               sha256="d" * 64, seconds=3600)

    def test_a_content_hash_that_is_not_a_sha256_is_refused(self):
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        for bad in ("", "nope", "e" * 63, "g" * 64):
            with self.subTest(sha256=bad):
                with self.assertRaises(Refused):
                    wc.mint_file_grant(target="host", path="/var/www/x.php",
                                       sha256=bad)

    def test_minting_a_grant_never_writes_the_ticket_to_the_audit(self):
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        grant = json.loads(wc.mint_file_grant(
            target="host", path="/var/www/x.php", sha256="f" * 64))
        blob = json.dumps(self.audit.records(), default=str)
        self.assertNotIn(grant["ticket"], blob)
        self.assertIn("file_grant_minted", [r["action"] for r in self.audit.records()])

    def test_a_revoked_session_cannot_mint_a_grant(self):
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        wc.revoke("test")
        with self.assertRaises(Refused):
            wc.mint_file_grant(target="host", path="/var/www/x.php",
                               sha256="a" * 64)

    def test_the_client_exposes_no_other_route_to_the_ticket(self):
        # THE POINT OF mint_file_grant IS ENCAPSULATION. If some public
        # accessor also handed out the ticket, any helper could assemble its
        # own grant and the binding above would be advisory.
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        public = [name for name in dir(wc)
                  if not name.startswith("_") and "ticket" in name.lower()]
        self.assertEqual(public, [], public)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
