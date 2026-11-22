"""PROX VOX P1 — the refusals are driven, not described. (SS-1507)

Everything here EXECUTES. A real HTTP server stands in for Proxmox, the real
broker makes real requests to it, and the assertions read what the broker did
-- including what it refused to do, and what it wrote down about it.

That matters more here than in most places. Every property being tested is a
refusal, and a refusal is exactly the kind of thing that passes a reading and
fails in use: a guard can be present in the source, correctly spelled, and
never reached.

Run: python tools/proxvox/tests/test_proxvox_p1.py
"""

import json
import os
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from proxvox import host_guard                      # noqa: E402
from proxvox.audit import AuditBuffer               # noqa: E402
from proxvox.broker import ReadOnlyBroker, Refused  # noqa: E402
from proxvox import credentials                   # noqa: E402

PINNED = "11111111-2222-3333-4444-555555555555"
OTHER = "99999999-8888-7777-6666-555555555555"
SECRET = "super-secret-token-value-do-not-log"


# ── a stand-in Proxmox that records what it was actually sent ───────────────
class _Fake(BaseHTTPRequestHandler):
    seen = []

    def _handle(self):
        _Fake.seen.append({
            "method": self.command,
            "path": self.path,
            "auth": self.headers.get("Authorization", ""),
        })
        body = json.dumps({"data": [{"node": "pve", "status": "online"}]}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    do_GET = _handle
    do_POST = _handle
    do_DELETE = _handle

    def log_message(self, *a):
        pass


class ProxVoxP1(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = HTTPServer(("127.0.0.1", 0), _Fake)
        cls.port = cls.server.server_address[1]
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.base = "http://127.0.0.1:%d" % cls.port

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()

    def setUp(self):
        _Fake.seen = []
        self.tmp = tempfile.mkdtemp()
        self.audit = AuditBuffer(os.path.join(self.tmp, "audit.jsonl"))

    def broker(self, guid=PINNED):
        return ReadOnlyBroker(self.base, "proxvox@pve!readonly", SECRET,
                              self.audit, PINNED, actual_guid=guid)

    # ── the host pin ────────────────────────────────────────────────────
    def test_refuses_to_start_on_another_machine(self):
        with self.assertRaises(host_guard.WrongHost):
            self.broker(guid=OTHER)

    def test_refuses_to_start_with_no_pin_at_all(self):
        # An unpinned broker would run anywhere, which is the condition the
        # ruling exists to prevent -- so it is refused as loudly as a wrong pin.
        #
        # The MESSAGE is asserted, not just the refusal. Without that, deleting
        # this branch is an equivalent mutant: the final comparison still
        # refuses ("" != a real guid), so the broker is safe either way and
        # only the diagnosis is lost. "No host is pinned" and "wrong machine"
        # are different problems at two in the morning, and the person reading
        # the message is the one who has to tell them apart.
        with self.assertRaises(host_guard.WrongHost) as caught:
            ReadOnlyBroker(self.base, "t", SECRET, self.audit, "", actual_guid=PINNED)
        self.assertIn("No host is pinned", str(caught.exception))

    def test_refuses_when_it_cannot_tell_where_it_is(self):
        with self.assertRaises(host_guard.WrongHost) as caught:
            ReadOnlyBroker(self.base, "t", SECRET, self.audit, PINNED, actual_guid="")
        self.assertIn("could not read", str(caught.exception).lower())

    def test_the_pin_is_not_overridable_by_the_environment(self):
        # The fault this guard was written against: a device id taken from an
        # environment variable, which the attacker also controls.
        os.environ["SNAPSMACK_DEVICE_ID"] = PINNED
        try:
            with self.assertRaises(host_guard.WrongHost):
                self.broker(guid=OTHER)
        finally:
            del os.environ["SNAPSMACK_DEVICE_ID"]

    # ── read-only, for real ─────────────────────────────────────────────
    def test_a_granted_read_reaches_the_server(self):
        out = self.broker().get("/nodes")
        self.assertEqual(out["data"][0]["node"], "pve")
        self.assertEqual(len(_Fake.seen), 1)
        self.assertEqual(_Fake.seen[0]["method"], "GET")

    def test_a_write_never_leaves_the_machine(self):
        with self.assertRaises(Refused):
            self.broker().call("POST", "/nodes/pve/lxc/102/status/stop")
        # The point: not "the server said no" but "nothing was ever sent".
        self.assertEqual(_Fake.seen, [], "a write reached the server in P1")

    def test_delete_is_refused_too(self):
        with self.assertRaises(Refused):
            self.broker().call("DELETE", "/nodes/pve/lxc/102")
        self.assertEqual(_Fake.seen, [])

    def test_an_ungranted_path_is_never_requested(self):
        for path in ("/access/users", "/cluster/config", "/access"):
            with self.assertRaises(Refused):
                self.broker().get(path)
        self.assertEqual(_Fake.seen, [], "an ungranted path was requested")

    def test_a_path_that_merely_starts_like_a_scope_is_refused(self):
        # /nodes-secret is not under /nodes, and a prefix check written
        # carelessly would let it through.
        with self.assertRaises(Refused):
            self.broker().get("/nodes-secret/thing")
        self.assertEqual(_Fake.seen, [])

    # ── the secret ──────────────────────────────────────────────────────
    def test_the_secret_travels_in_the_header_and_not_the_url(self):
        self.broker().get("/nodes")
        sent = _Fake.seen[0]
        self.assertIn(SECRET, sent["auth"])
        self.assertNotIn(SECRET, sent["path"],
                         "the token was in the URL, where it lands in access logs")

    def test_the_secret_is_never_written_to_the_audit_log(self):
        self.broker().get("/nodes")
        try:
            self.broker().call("POST", "/nodes")
        except Refused:
            pass
        with open(self.audit.path, encoding="utf-8") as fh:
            written = fh.read()
        self.assertNotIn(SECRET, written)

    def test_the_secret_is_not_in_the_repr(self):
        # A broker printed into a traceback or a debug line must not leak it.
        text = repr(self.broker())
        self.assertNotIn(SECRET, text)
        self.assertIn("secret=hidden", text)

    # ── the audit trail ─────────────────────────────────────────────────
    def test_every_call_is_written_down(self):
        self.broker().get("/nodes")
        actions = [r["action"] for r in self.audit.records()]
        self.assertEqual(actions, ["call", "result"])

    def test_a_refusal_is_written_down_too(self):
        # A log that records only what succeeded makes a blocked attack look
        # like a quiet afternoon.
        try:
            self.broker().call("POST", "/nodes")
        except Refused:
            pass
        records = self.audit.records()
        self.assertEqual([r["action"] for r in records], ["refused"])
        self.assertEqual(records[0]["detail"]["why"], "P1 is read-only")

    def test_the_call_is_recorded_before_it_is_made(self):
        # So a crash mid-request still leaves evidence it was attempted.
        class Boom(Exception):
            pass

        def explode(*a, **k):
            raise Boom()

        b = ReadOnlyBroker(self.base, "t", SECRET, self.audit, PINNED,
                           actual_guid=PINNED, opener=explode)
        with self.assertRaises(Boom):
            b.get("/nodes")
        actions = [r["action"] for r in self.audit.records()]
        self.assertEqual(actions, ["call", "result"])
        self.assertEqual(self.audit.records()[1]["detail"]["error"], "Boom")

    # ── never dropping ──────────────────────────────────────────────────
    def test_a_failed_flush_keeps_every_record(self):
        self.broker().get("/nodes")
        before = self.audit.unflushed()
        self.assertEqual(self.audit.flush_to(lambda rows: False), 0)
        self.assertEqual(self.audit.unflushed(), before, "records lost on a failed flush")

    def test_a_flush_that_throws_keeps_every_record(self):
        self.broker().get("/nodes")
        before = self.audit.unflushed()

        def angry(rows):
            raise IOError("the radio box is off")

        self.assertEqual(self.audit.flush_to(angry), 0)
        self.assertEqual(self.audit.unflushed(), before)

    def test_a_successful_flush_empties_the_buffer_and_hands_over_everything(self):
        self.broker().get("/nodes")
        self.broker().get("/storage")
        handed = []
        moved = self.audit.flush_to(lambda rows: (handed.extend(rows), True)[1])
        self.assertEqual(moved, 4)
        self.assertEqual(len(handed), 4)
        self.assertEqual(self.audit.unflushed(), 0)

    def test_a_half_written_line_from_a_crash_is_kept_not_discarded(self):
        # The buffer claims a crash mid-write leaves its evidence behind.
        # Nothing proved that until a mutation deleted the branch and no test
        # noticed, so the claim is driven now: a truncated final line.
        self.broker().get("/nodes")
        with open(self.audit.path, "a", encoding="utf-8") as fh:
            fh.write('{"at":"2026-11-22T00:00:00Z","action":"call","det')
        records = self.audit.records()
        self.assertEqual(records[-1]["action"], "unparsable")
        self.assertIn("det", records[-1]["detail"]["raw"])
        self.assertEqual(self.audit.unflushed(), 3,
                         "a damaged record vanished from the count")

    def test_p1_logs_are_local_only_and_the_count_says_so(self):
        # P1 ships before the off-host store exists. The honest statement of
        # that is a number the sign-off can quote, not a silence.
        self.broker().get("/nodes")
        self.assertEqual(self.audit.unflushed(), 2)


class ProxVoxToken(unittest.TestCase):
    """Where the token comes from, and the absence of easier routes in."""

    def test_the_token_comes_from_the_keystore(self):
        got = credentials.load_token(reader=lambda site, kind: "the-token")
        self.assertEqual(got, "the-token")

    def test_an_empty_keystore_is_an_error_not_a_fallback(self):
        with self.assertRaises(credentials.NoToken):
            credentials.load_token(reader=lambda site, kind: "")

    def test_it_does_not_fall_back_to_the_environment(self):
        # The whole objection: a secret reachable by an easier route is not
        # protected by the harder one.
        os.environ["PROXVOX_TOKEN"] = "token-from-the-environment"
        os.environ["PROXVOX_API_TOKEN"] = "token-from-the-environment"
        try:
            with self.assertRaises(credentials.NoToken):
                credentials.load_token(reader=lambda site, kind: "")
        finally:
            del os.environ["PROXVOX_TOKEN"]
            del os.environ["PROXVOX_API_TOKEN"]

    def test_it_asks_the_keystore_for_one_named_credential(self):
        asked = []
        credentials.load_token(reader=lambda site, kind: (asked.append((site, kind)), "t")[1])
        self.assertEqual(asked, [(credentials.CRED_SITE, credentials.CRED_KIND)])

    def test_the_error_says_where_to_put_it_and_does_not_leak(self):
        with self.assertRaises(credentials.NoToken) as caught:
            credentials.load_token(reader=lambda site, kind: "   ")
        message = str(caught.exception)
        self.assertIn("Credential Manager", message)
        self.assertIn("does not read tokens from", message)


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
