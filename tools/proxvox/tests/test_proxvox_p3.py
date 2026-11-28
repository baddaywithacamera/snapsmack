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
        # SS-1657: real Proxmox accepts a VALID TICKET in place of the password
        # on /access/ticket and hands back a fresh one. The fake server models
        # that, with a DIFFERENT ticket value so a test can prove the ticket
        # actually rotated rather than just that the call returned 200.
        self.renewed_ticket_value = "PVE:proxvox@pve:5678BEEF::sig"
        self.renewed_csrf_value = "ABCD2345:csrf-renewed-value"
        self.renew_401_mode = False
        self.renew_empty_mode = False
        self.renew_requests = 0
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
                    # SS-1657 renewal: the password IS a live ticket.
                    if (parsed_form.get("username") == ["proxvox@pve"]
                            and parsed_form.get("password") in (
                                [server_ref.ticket_value],
                                [server_ref.renewed_ticket_value])):
                        server_ref.renew_requests += 1
                        if server_ref.renew_401_mode:
                            self.send_response(401)
                            self.end_headers()
                            return
                        if server_ref.renew_empty_mode:
                            # 200, well-formed JSON, NO ticket. A different
                            # failure path from the 401 and it has its own
                            # clear_session() call, which a mutant proved was
                            # unexercised.
                            payload = {"data": {"ticket": "", "CSRFPreventionToken": ""}}
                        else:
                            payload = {"data": {
                                "ticket": server_ref.renewed_ticket_value,
                                "CSRFPreventionToken": server_ref.renewed_csrf_value,
                            }}
                        body = json.dumps(payload).encode("utf-8")
                        self.send_response(200)
                        self.send_header("Content-Type", "application/json")
                        self.send_header("Content-Length", str(len(body)))
                        self.end_headers()
                        self.wfile.write(body)
                        return

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

    def _client(self, ticket_ttl=7200, expiry_timer_factory=None,
                window_seconds=None, renew_margin=None) -> WriteClient:
        # SS-1657 added the 24-hour window. The defaults here stay as they
        # were so every test written before it still describes the same client.
        extra = {}
        if window_seconds is not None:
            extra["window_seconds"] = window_seconds
        if renew_margin is not None:
            extra["renew_margin"] = renew_margin
        return WriteClient(
            base_url=self.server.base_url,
            audit=self.audit,
            pinned_guid=self.PIN,
            actual_guid=self.PIN,
            opener=urllib.request.urlopen,
            clock=self.clock,
            ticket_ttl=ticket_ttl,
            expiry_timer_factory=expiry_timer_factory,
            **extra,
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

    # ── SS-1658: the host shell, and a log somebody can actually read ───────
    def test_the_command_text_is_audited_not_only_its_hash(self):
        """Under a 24-hour unattended window the log is the only record.

        This used to store a sha256 and nothing else. A hash cannot be read --
        it can only CONFIRM a command somebody already guessed -- so a day of
        unattended work was unreviewable. The digest stays beside the text as
        the proof the text was not edited afterwards.
        """
        class Console:
            def __init__(self):
                self.ran = []
            def run(self, text):
                self.ran.append(text)
                return "ok"
            def close(self):
                pass

        console = Console()
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        opened = wc.open_container_console("proxmox", 106, connector=lambda **_: console)
        wc.run_container_command(opened, "mysql -e 'UPDATE snap_posts SET x=1'")

        rows = [r for r in self.audit.records() if r["action"] == "container_write_call"]
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["detail"]["command"],
                         "mysql -e 'UPDATE snap_posts SET x=1'")
        # The digest is still there and still matches the text.
        import hashlib as _h
        self.assertEqual(
            rows[0]["detail"]["command_sha256"],
            _h.sha256("mysql -e 'UPDATE snap_posts SET x=1'".encode()).hexdigest())

        # AND THE CREDENTIALS ARE STILL ABSENT. Logging the command must not
        # have widened what else ends up in the log.
        raw = str(self.audit.records())
        for secret in ("good-pass", "123456", self.server.ticket_value,
                       self.server.csrf_value):
            self.assertNotIn(secret, raw)

    def test_host_shell_uses_the_node_path_not_a_container_path(self):
        """'and on the host' needed a path that did not exist."""
        class Console:
            def run(self, text):
                return "ok"
            def close(self):
                self.closed = True

        console = Console()
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        seen = {}
        opened = wc.open_host_console(
            "proxmox",
            connector=lambda **kw: (seen.update(kw) or console),
        )
        self.assertIs(opened, console)

        # The POST went to the NODE's termproxy, with no lxc segment anywhere.
        paths = [r["path"] for r in self.server.requests if r.get("path")]
        self.assertIn("/api2/json/nodes/proxmox/termproxy", paths)
        self.assertFalse([p for p in paths if "/lxc/" in p])

        # vmid 0 marks it as the host rather than a guest.
        self.assertEqual(seen["vmid"], 0)
        actions = [r["action"] for r in self.audit.records()]
        self.assertIn("host_console_open", actions)

        # A command runs on it through the same audited path as a container.
        wc.run_container_command(opened, "pvesh get /version")
        rows = [r for r in self.audit.records() if r["action"] == "container_write_call"]
        self.assertEqual(rows[-1]["detail"]["command"], "pvesh get /version")

    def test_revoke_closes_a_host_shell_too(self):
        """A host shell must die with the session, like a container one."""
        class Console:
            closed = False
            def close(self):
                self.closed = True

        console = Console()
        wc = self._client()
        wc.authorize("proxvox@pve", "good-pass", "123456")
        wc.open_host_console("proxmox", connector=lambda **_: console)
        wc.revoke(reason="sean clicked stop")
        self.assertTrue(console.closed)
        self.assertFalse(wc.is_authorized())

    def test_a_host_shell_is_refused_without_a_ticket_and_on_a_bad_node(self):
        wc = self._client()
        before = len(self.server.requests)
        with self.assertRaises(Refused):
            wc.open_host_console("proxmox")          # never authorised
        self.assertEqual(len(self.server.requests), before)

        wc.authorize("proxvox@pve", "good-pass", "123456")
        before = len(self.server.requests)
        for bad in ("prox mox", "../etc", "prox;mox", ""):
            with self.assertRaises(Refused):
                wc.open_host_console(bad)
        # Refused BEFORE the network, every time.
        self.assertEqual(len(self.server.requests), before)

    # ── SS-1657: one TOTP, a 24-hour window, renewal that never extends it ──
    def _timer_factory(self, timers):
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
        return Timer

    def test_renewal_keeps_the_session_and_rotates_the_ticket(self):
        """One TOTP, then the ticket renews itself with no second prompt."""
        timers = []
        wc = self._client(ticket_ttl=7200, expiry_timer_factory=self._timer_factory(timers),
                          window_seconds=86400, renew_margin=300)
        opened = wc.authorize("proxvox@pve", "good-pass", "123456")
        window_at_authorise = opened["window_ends_at"]
        self.assertTrue(wc.is_authorized())
        self.assertEqual(len(timers), 1)

        # THE CLOCK MUST MOVE OR THIS TEST PROVES NOTHING. A mutant that set
        # _window_ends_at = now + window_seconds on every renewal SURVIVED,
        # because with a frozen clock that expression equals the window
        # authorise already computed. Advancing makes extension visible.
        self.clock.advance(6900)

        # It wakes BEFORE the ticket dies, not when it dies.
        self.assertEqual(timers[0].seconds, 7200 - 300)

        timers[0].fire()

        # Still authorised, no second TOTP asked for, and the server really
        # was asked to renew.
        self.assertTrue(wc.is_authorized())
        self.assertEqual(self.server.renew_requests, 1)
        actions = [row["action"] for row in self.audit.records()]
        self.assertIn("write_renewed", actions)
        self.assertNotIn("write_expired", actions)

        # THE TICKET ROTATED. A renewal that kept the old ticket would look
        # like a success and be useless.
        self.assertEqual(wc._ticket, self.server.renewed_ticket_value)
        self.assertEqual(wc._csrf_token, self.server.renewed_csrf_value)

        # AND THE WINDOW DID NOT MOVE. This is the safety property: renewing
        # extends the TICKET, never the authorisation. If this ever fails, one
        # TOTP lasts for ever.
        self.assertEqual(wc._window_ends_at, window_at_authorise)

    def test_renewal_never_extends_the_window_however_many_times_it_runs(self):
        """Ten renewals, and the window is still the one the TOTP opened."""
        timers = []
        wc = self._client(ticket_ttl=7200, expiry_timer_factory=self._timer_factory(timers),
                          window_seconds=86400, renew_margin=300)
        opened = wc.authorize("proxvox@pve", "good-pass", "123456")
        window_at_authorise = opened["window_ends_at"]
        for _ in range(10):
            # Same reason as above: time has to pass between renewals or an
            # extended window is indistinguishable from an unchanged one.
            self.clock.advance(6900)
            timers[-1].fire()
            self.assertTrue(wc.is_authorized())
            self.assertEqual(wc._window_ends_at, window_at_authorise)
        self.assertEqual(self.server.renew_requests, 10)
        self.assertEqual(wc._renewals, 10)

    def test_a_failed_renewal_fails_closed(self):
        """A renewal that cannot happen ends the session, it does not limp on."""
        timers = []
        wc = self._client(ticket_ttl=7200, expiry_timer_factory=self._timer_factory(timers),
                          window_seconds=86400, renew_margin=300)
        wc.authorize("proxvox@pve", "good-pass", "123456")
        self.server.renew_401_mode = True

        timers[0].fire()

        self.assertFalse(wc.is_authorized())
        self.assertEqual(wc._ticket, "")
        actions = [row["action"] for row in self.audit.records()]
        self.assertIn("write_renew_failed", actions)
        self.assertNotIn("write_renewed", actions)

    def test_revoke_beats_a_later_renewal(self):
        """After a manual revoke, a timer that fires must not revive anything."""
        timers = []
        wc = self._client(ticket_ttl=7200, expiry_timer_factory=self._timer_factory(timers),
                          window_seconds=86400, renew_margin=300)
        wc.authorize("proxvox@pve", "good-pass", "123456")
        wc.revoke(reason="sean clicked stop")
        self.assertFalse(wc.is_authorized())

        before = self.server.renew_requests
        timers[0].fire()

        # The revoked session must not come back, and the server must not even
        # be asked: a revoke that only stops the NEXT write is not a revoke.
        self.assertFalse(wc.is_authorized())
        self.assertEqual(self.server.renew_requests, before)

    def test_a_renewal_that_returns_no_ticket_also_fails_closed(self):
        """The second failure path. A mutant proved this one was unexercised.

        The 401 test below covers the transport failure. This covers a 200
        with well-formed JSON and NO ticket in it, which is a different branch
        with its own clear_session() -- and removing that call left every test
        passing until this existed.
        """
        timers = []
        wc = self._client(ticket_ttl=7200, expiry_timer_factory=self._timer_factory(timers),
                          window_seconds=86400, renew_margin=300)
        wc.authorize("proxvox@pve", "good-pass", "123456")
        self.server.renew_empty_mode = True
        self.clock.advance(6900)

        timers[0].fire()

        self.assertFalse(wc.is_authorized())
        self.assertEqual(wc._ticket, "")
        actions = [row["action"] for row in self.audit.records()]
        self.assertIn("write_renew_failed", actions)
        self.assertNotIn("write_renewed", actions)

    def test_the_window_bound_alone_ends_the_session(self):
        """Past the window, the session is dead even with a live ticket.

        is_authorized() checks BOTH bounds. A mutant that dropped the window
        check survived every other test here, because with a frozen clock the
        ticket bound was always the one that mattered. This advances past the
        window while the TICKET is still well inside its own lifetime, so only
        the window check can end it.
        """
        wc = self._client(ticket_ttl=7200, window_seconds=600)
        wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertTrue(wc.is_authorized())

        # 601s: past the 600s window, far inside the 7200s ticket.
        self.clock.advance(601)
        self.assertFalse(wc.is_authorized())

    def test_the_window_closes_even_though_the_ticket_renews(self):
        """The outer bound still ends the session. 24 hours is a box, not a lease."""
        timers = []
        # A window shorter than one wake, so the FIRST wake is the window end.
        wc = self._client(ticket_ttl=7200, expiry_timer_factory=self._timer_factory(timers),
                          window_seconds=60, renew_margin=300)
        wc.authorize("proxvox@pve", "good-pass", "123456")
        self.assertTrue(wc.is_authorized())

        timers[0].fire()

        self.assertFalse(wc.is_authorized())
        actions = [row["action"] for row in self.audit.records()]
        self.assertIn("write_expired", actions)
        self.assertNotIn("write_renewed", actions)
        self.assertEqual(self.server.renew_requests, 0)

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

        # window_seconds=1 so the WINDOW is closed when the timer fires. Before
        # SS-1657 the ticket dying was the same event as the window closing;
        # now they are different, and this test is named for the window one.
        wc = self._client(ticket_ttl=1, expiry_timer_factory=Timer,
                          window_seconds=1, renew_margin=1)

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


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
