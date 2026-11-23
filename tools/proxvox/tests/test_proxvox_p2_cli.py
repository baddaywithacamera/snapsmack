"""Real-HTTP and filesystem execution tests for PROX VOX P2 sink and operator CLI.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import http.server
import io
import json
import os
import shutil
import sys
import threading
import unittest
import urllib.parse
from contextlib import redirect_stdout

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from proxvox import cli
from proxvox.audit import AuditBuffer
from proxvox.broker import Refused
from proxvox.sink import OffHostFileSink, flush_audit


class _CLIMockServer:
    def __init__(self):
        self.requests = []
        self.ticket = "PVE:proxvox@pve:9999::sig"
        self.csrf = "9999:csrf-token"
        self.ro_secret = "ro-token-secret-xyz"
        server_ref = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, fmt, *args):
                pass

            def _handle(self):
                length = int(self.headers.get("Content-Length") or 0)
                raw = self.rfile.read(length).decode("utf-8", "replace") if length else ""
                form = urllib.parse.parse_qs(raw) if raw else {}
                server_ref.requests.append({
                    "method": self.command,
                    "path": self.path,
                    "auth": self.headers.get("Authorization", ""),
                    "cookie": self.headers.get("Cookie", ""),
                    "csrf": self.headers.get("CSRFPreventionToken", ""),
                    "form": form,
                })
                if self.command == "POST" and self.path == "/api2/json/access/ticket":
                    if "tfa-challenge" in form:
                        body = json.dumps({
                            "data": {"ticket": server_ref.ticket, "CSRFPreventionToken": server_ref.csrf}
                        }).encode("utf-8")
                    else:
                        body = json.dumps({
                            "data": {"ticket": "PVE:!tfa!challenge-ticket"}
                        }).encode("utf-8")
                    self._send(200, body)
                    return
                if self.command == "GET" and self.headers.get("Authorization", "").endswith("=" + server_ref.ro_secret):
                    self._send(200, json.dumps({"data": [{"node": "pve"}]}).encode("utf-8"))
                    return
                if (self.headers.get("Cookie") == "PVEAuthCookie=" + server_ref.ticket
                        and self.headers.get("CSRFPreventionToken") == server_ref.csrf):
                    self._send(200, json.dumps({"data": {"ok": True, "path": self.path}}).encode("utf-8"))
                    return
                self._send(401, b"{}")

            def _send(self, code, body):
                self.send_response(code)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            do_GET = _handle
            do_POST = _handle
            do_PUT = _handle
            do_DELETE = _handle

        self._httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self._thread = threading.Thread(target=self._httpd.serve_forever, daemon=True)
        self._thread.start()
        h, p = self._httpd.server_address
        self.base_url = f"http://{h}:{p}"

    def close(self):
        self._httpd.shutdown()
        self._httpd.server_close()


class ProxVoxP2AndCLITests(unittest.TestCase):
    PIN = "11111111-2222-3333-4444-555555555555"

    def setUp(self):
        self.tmp_dir = os.path.join(
            os.path.dirname(__file__), "_tmp_p2_" + self._testMethodName
        )
        shutil.rmtree(self.tmp_dir, ignore_errors=True)
        os.makedirs(self.tmp_dir, exist_ok=True)
        self.audit_path = os.path.join(self.tmp_dir, "audit.jsonl")
        self.sink_dir = os.path.join(self.tmp_dir, "offhost_share")
        self.cfg_path = os.path.join(self.tmp_dir, "config.json")
        self.server = _CLIMockServer()
        self._write_cfg({})

    def tearDown(self):
        self.server.close()
        shutil.rmtree(self.tmp_dir, ignore_errors=True)

    def _write_cfg(self, extra: dict):
        cfg = {
            "base_url": self.server.base_url,
            "token_id": "proxvox@pve!ro",
            "pinned_guid": self.PIN,
            "audit_path": self.audit_path,
            "offhost_sink_dir": self.sink_dir,
            "offhost_sink_enabled": False,
        }
        cfg.update(extra)
        with open(self.cfg_path, "w", encoding="utf-8") as fh:
            json.dump(cfg, fh)

    def test_p2_sink_disabled_by_default_and_never_drops_when_share_down(self):
        audit = AuditBuffer(self.audit_path)
        audit.record("call", {"method": "GET", "path": "/nodes"})
        self.assertEqual(audit.unflushed(), 1)

        # Disabled by default (waiting on OWNER RIGHTS): keeps everything local
        res = flush_audit(audit, {"offhost_sink_dir": self.sink_dir})
        self.assertEqual(res["flushed"], 0)
        self.assertEqual(res["unflushed"], 1)
        self.assertFalse(res["sink_enabled"])

        # Enabled, but share dir does not exist yet: keeps everything local
        res2 = flush_audit(audit, {"offhost_sink_dir": self.sink_dir, "offhost_sink_enabled": True})
        self.assertEqual(res2["flushed"], 0)
        self.assertEqual(res2["unflushed"], 1)

        # Share dir exists and enabled: flushes all records cleanly
        os.makedirs(self.sink_dir, exist_ok=True)
        res3 = flush_audit(audit, {"offhost_sink_dir": self.sink_dir, "offhost_sink_enabled": True})
        self.assertEqual(res3["flushed"], 1)
        self.assertEqual(res3["unflushed"], 0)
        offhost_file = OffHostFileSink(self.sink_dir, enabled=True).target_path
        self.assertTrue(os.path.exists(offhost_file))
        with open(offhost_file, "r", encoding="utf-8") as fh:
            lines = [json.loads(line) for line in fh if line.strip()]
        self.assertEqual(len(lines), 1)
        self.assertEqual(lines[0]["action"], "call")

    def test_config_rejects_secret_keys(self):
        self._write_cfg({"password": "oops"})
        with self.assertRaises(cli.ConfigError):
            cli.load_config(self.cfg_path)

    def test_cli_read_and_audit_status_and_flush(self):
        os.makedirs(self.sink_dir, exist_ok=True)
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = cli.main(
                ["--config", self.cfg_path, "read", "/nodes"],
                token_reader=lambda site, kind: self.server.ro_secret,
                actual_guid=self.PIN,
            )
        self.assertEqual(rc, 0)
        self.assertEqual(json.loads(buf.getvalue())["data"][0]["node"], "pve")
        self.assertNotIn(self.server.ro_secret, buf.getvalue())

        # Local audit holds records while offhost_sink_enabled is False
        st_buf = io.StringIO()
        with redirect_stdout(st_buf):
            cli.main(["--config", self.cfg_path, "audit-status"], actual_guid=self.PIN)
        st = json.loads(st_buf.getvalue())
        self.assertGreaterEqual(st["unflushed"], 2)
        self.assertFalse(st["offhost_sink_enabled"])

        # Enable sink and flush via CLI
        self._write_cfg({"offhost_sink_enabled": True})
        fl_buf = io.StringIO()
        with redirect_stdout(fl_buf):
            cli.main(["--config", self.cfg_path, "flush"], actual_guid=self.PIN)
        fl = json.loads(fl_buf.getvalue())
        self.assertGreaterEqual(fl["flushed"], 2)
        self.assertEqual(fl["unflushed"], 0)

    def test_cli_write_requires_live_prompt_and_revokes_on_exit(self):
        parser = cli.build_parser()
        # No --password or --otp flags exist on CLI parser
        write_opts = parser._subparsers._group_actions[0].choices["write"]._option_string_actions
        self.assertNotIn("--password", write_opts)
        self.assertNotIn("--otp", write_opts)

        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = cli.main(
                ["--config", self.cfg_path, "write", "PUT", "/nodes/pve/lxc/106/config", "--data", "tags=proof"],
                prompt_fn=lambda u: ("proxvox@pve", "live-pw", "123456"),
                actual_guid=self.PIN,
            )
        self.assertEqual(rc, 0)
        self.assertTrue(json.loads(buf.getvalue())["data"]["ok"])
        actions = [r["action"] for r in AuditBuffer(self.audit_path).records()]
        self.assertIn("write_auth_attempt", actions)
        self.assertIn("write_call", actions)
        self.assertIn("write_revoked", actions)

    def test_cli_session_authorizes_once_and_revokes_mid_session(self):
        commands = iter([
            "post /nodes/pve/lxc/106/status/start",
            "put /nodes/pve/lxc/106/config tags=test",
            "revoke",
            "delete /nodes/pve/lxc/106",
            "exit",
        ])
        prompts = []

        def _prompt(u):
            prompts.append(u)
            return ("proxvox@pve", "live-pw", "123456")

        buf = io.StringIO()
        with redirect_stdout(buf):
            with self.assertRaises(Refused):
                cli.main(
                    ["--config", self.cfg_path, "session"],
                    prompt_fn=_prompt,
                    input_fn=lambda _: next(commands),
                    actual_guid=self.PIN,
                )
        # Only prompted ONCE for the whole session
        self.assertEqual(len(prompts), 1)
        # Server received 2-stage auth + POST + PUT (4 requests), NOT the post-revoke DELETE
        self.assertEqual(len(self.server.requests), 4)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
