from __future__ import annotations
# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.

import asyncio
import json
import os
import re
import sys
import tempfile
import time
import unittest
from unittest import mock
from pathlib import Path

from mcp.server.auth.provider import AuthorizationParams
from mcp.shared.auth import OAuthClientInformationFull
from pydantic import AnyUrl

from troll_booth.oauth import BoothOAuthProvider
from troll_booth.history import ConversationStore
from troll_booth.store import BoothStore
from troll_booth.worker import CapabilityRegistry


class StoreTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.store = BoothStore(Path(self.temp.name) / "booth.sqlite3")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_pair_code_is_single_use(self) -> None:
        code = self.store.issue_pair_code("claude", ["booth:read"], ttl=60)
        self.assertEqual(self.store.consume_pair_code(code)["actor"], "claude")
        self.assertIsNone(self.store.consume_pair_code(code))

    def test_job_claim_is_single_consumer(self) -> None:
        job_id = self.store.enqueue("claude", "apps.list", {}, "test", None)
        claimed = self.store.claim()
        self.assertEqual(claimed["id"], job_id)
        self.assertIsNone(self.store.claim())
        self.assertTrue(self.store.finish(job_id, {"apps": []}))
        self.assertEqual(self.store.job(job_id)["status"], "complete")
        self.assertEqual(self.store.take_job(job_id)["result"], {"apps": []})
        self.assertIsNone(self.store.job(job_id))

    def test_agent_presence_tracks_recent_authenticated_activity(self) -> None:
        self.store.agent_heartbeat("claude-code")
        agents = self.store.status()["agents"]
        self.assertEqual(agents[0]["actor"], "claude-code")
        self.assertTrue(agents[0]["online"])
        self.assertLessEqual(agents[0]["age_seconds"], 1)


class ConversationTests(unittest.TestCase):
    def test_history_is_searchable_and_hash_chained(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            store = ConversationStore(Path(directory) / "history.sqlite3")
            first = store.append("ops", "sean", "Check the Pixelfed queue", ticket_id="TW-12")
            second = store.append("ops", "claude", "Pixelfed queue is clear", ticket_id="TW-12")
            messages = store.messages("ops")
            self.assertEqual([message["id"] for message in messages], [first, second])
            self.assertEqual(messages[1]["previous_hash"], messages[0]["message_hash"])
            self.assertEqual(store.search("Pixelfed")[0]["room_id"], "ops")

    def test_worker_reads_local_history_only_through_declared_capabilities(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            config = root / "apps.json"
            config.write_text('{"apps": []}', encoding="utf-8")
            history = root / "history.sqlite3"
            messages = ConversationStore(history)
            messages.append("build", "codex", "TROLL BOOTH is healthy")
            registry = CapabilityRegistry(config, history)
            result = registry.execute("conversation.search", {"query": "healthy"})
            self.assertEqual(result["messages"][0]["sender"], "codex")

    def test_authenticated_actor_posts_and_scope_conflicts_are_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            config = root / "apps.json"
            config.write_text('{"apps": []}', encoding="utf-8")
            history = root / "history.sqlite3"
            registry = CapabilityRegistry(config, history)

            posted = registry.execute(
                "conversation.post",
                {"room_id": "build", "body": "I am changing the bridge"},
                actor="claude",
            )
            self.assertTrue(posted["posted"])
            self.assertEqual(ConversationStore(history).messages("build")[0]["sender"], "claude")

            claimed = registry.execute(
                "scope.claim", {"scope": "tools/troll-booth", "task_id": "TB-2"}, actor="claude"
            )
            self.assertTrue(claimed["claimed"])
            conflict = registry.execute(
                "scope.claim", {"scope": "tools/troll-booth", "task_id": "TB-3"}, actor="codex"
            )
            self.assertFalse(conflict["claimed"])
            self.assertEqual(conflict["conflict"]["actor"], "claude")
            self.assertFalse(
                registry.execute("scope.release", {"scope": "tools/troll-booth"}, actor="codex")["released"]
            )
            self.assertTrue(
                registry.execute("scope.release", {"scope": "tools/troll-booth"}, actor="claude")["released"]
            )


class OAuthTests(unittest.TestCase):
    def test_owner_code_approves_one_request(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            store = BoothStore(Path(directory) / "booth.sqlite3")
            provider = BoothOAuthProvider(store, "https://booth.example")
            client = OAuthClientInformationFull(
                client_id="claude-test",
                redirect_uris=[AnyUrl("https://claude.ai/api/mcp/auth_callback")],
                token_endpoint_auth_method="none",
                grant_types=["authorization_code", "refresh_token"],
                response_types=["code"],
                scope="booth:read",
            )
            params = AuthorizationParams(
                state="state",
                scopes=["booth:read"],
                code_challenge="challenge",
                redirect_uri=AnyUrl("https://claude.ai/api/mcp/auth_callback"),
                redirect_uri_provided_explicitly=True,
                resource="https://booth.example/mcp",
            )
            approval_url = asyncio.run(provider.authorize(client, params))
            request_id = approval_url.rsplit("=", 1)[1]
            code = store.issue_pair_code("claude", ["booth:read"], ttl=60)
            redirect = provider.approve(request_id, code)
            self.assertIn("https://claude.ai/api/mcp/auth_callback", redirect)
            self.assertIsNone(provider.approve(request_id, code))

    def test_write_scope_is_granted_only_when_requested_and_owner_approved(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            store = BoothStore(Path(directory) / "booth.sqlite3")
            provider = BoothOAuthProvider(store, "https://booth.example")
            client = OAuthClientInformationFull(
                client_id="claude-write-test",
                redirect_uris=[AnyUrl("https://claude.ai/api/mcp/auth_callback")],
                token_endpoint_auth_method="none",
                grant_types=["authorization_code", "refresh_token"],
                response_types=["code"],
                scope="booth:read booth:write",
            )
            params = AuthorizationParams(
                state="state",
                scopes=["booth:read", "booth:write"],
                code_challenge="challenge",
                redirect_uri=AnyUrl("https://claude.ai/api/mcp/auth_callback"),
                redirect_uri_provided_explicitly=True,
                resource="https://booth.example/mcp",
            )
            approval_url = asyncio.run(provider.authorize(client, params))
            request_id = approval_url.rsplit("=", 1)[1]
            code = store.issue_pair_code("claude", ["booth:read", "booth:write"], ttl=60)
            provider.approve(request_id, code)
            issued = next(iter(provider.codes.values()))
            self.assertEqual(issued.scopes, ["booth:read", "booth:write"])

    def test_owner_write_code_steps_up_a_client_that_only_requests_read(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            store = BoothStore(Path(directory) / "booth.sqlite3")
            provider = BoothOAuthProvider(store, "https://booth.example")
            client = OAuthClientInformationFull(
                client_id="claude-read-registration",
                redirect_uris=[AnyUrl("https://claude.ai/api/mcp/auth_callback")],
                token_endpoint_auth_method="none",
                grant_types=["authorization_code", "refresh_token"],
                response_types=["code"],
                scope="booth:read",
            )
            params = AuthorizationParams(
                state="state",
                scopes=["booth:read"],
                code_challenge="challenge",
                redirect_uri=AnyUrl("https://claude.ai/api/mcp/auth_callback"),
                redirect_uri_provided_explicitly=True,
                resource="https://booth.example/mcp",
            )
            approval_url = asyncio.run(provider.authorize(client, params))
            request_id = approval_url.rsplit("=", 1)[1]
            code = store.issue_pair_code("claude", ["booth:read", "booth:write"], ttl=60)
            provider.approve(request_id, code)
            issued = next(iter(provider.codes.values()))
            self.assertEqual(issued.scopes, ["booth:read", "booth:write"])

    def test_paired_actor_survives_token_exchange(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            store = BoothStore(Path(directory) / "booth.sqlite3")
            provider = BoothOAuthProvider(store, "https://booth.example")
            client = OAuthClientInformationFull(
                client_id="codex-test", redirect_uris=[AnyUrl("http://127.0.0.1/callback")],
                token_endpoint_auth_method="none", grant_types=["authorization_code", "refresh_token"],
                response_types=["code"], scope="booth:read booth:write",
            )
            params = AuthorizationParams(
                state="state", scopes=["booth:read", "booth:write"], code_challenge="challenge",
                redirect_uri=AnyUrl("http://127.0.0.1/callback"), redirect_uri_provided_explicitly=True,
                resource="https://booth.example/mcp",
            )
            request_id = asyncio.run(provider.authorize(client, params)).rsplit("=", 1)[1]
            pair_code = store.issue_pair_code("codex", ["booth:read", "booth:write"], ttl=60)
            provider.approve(request_id, pair_code)
            authorization_code = next(iter(provider.codes.values()))
            token = asyncio.run(provider.exchange_authorization_code(client, authorization_code))
            self.assertEqual(asyncio.run(provider.load_access_token(token.access_token)).subject, "codex")


    def test_connector_grants_do_not_expire_but_remain_revocable(self) -> None:
        from mcp.server.auth.provider import AuthorizationCode
        with tempfile.TemporaryDirectory() as directory:
            provider = BoothOAuthProvider(BoothStore(Path(directory) / "booth.sqlite3"), "https://booth.example")
            client = OAuthClientInformationFull(client_id="durable", redirect_uris=[AnyUrl("http://127.0.0.1/callback")])
            code = AuthorizationCode(code="test-code", client_id="durable", scopes=["conversation:read"],
                expires_at=time.time() + 60, code_challenge="challenge",
                redirect_uri=AnyUrl("http://127.0.0.1/callback"), redirect_uri_provided_explicitly=True)
            result = asyncio.run(provider.exchange_authorization_code(client, code))
            self.assertIsNone(result.expires_in)
            with mock.patch("troll_booth.oauth.time.time", return_value=time.time() + 10 * 365 * 86400):
                access = asyncio.run(provider.load_access_token(result.access_token))
                refresh = asyncio.run(provider.load_refresh_token(client, result.refresh_token))
                self.assertIsNotNone(access)
                self.assertIsNotNone(refresh)
                renewed = asyncio.run(provider.exchange_refresh_token(client, refresh, ["conversation:read", "booth:write"]))
                self.assertIsNone(renewed.expires_in)
                self.assertEqual(renewed.scope, "conversation:read")
                asyncio.run(provider.revoke_token(access))
                asyncio.run(provider.revoke_token(refresh))
                self.assertIsNone(asyncio.run(provider.load_access_token(result.access_token)))
                self.assertIsNone(asyncio.run(provider.load_refresh_token(client, result.refresh_token)))


class WorkerTests(unittest.TestCase):
    def test_only_declared_capability_command_runs(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            helper = root / "caps.py"
            helper.write_text('import json; print(json.dumps({"schema_version": 1, "capabilities": []}))', encoding="utf-8")
            config = root / "apps.json"
            config.write_text(
                json.dumps(
                    {
                        "apps": [
                            {
                                "slug": "test-app",
                                "name": "Test App",
                                "capabilities_command": [sys.executable, str(helper)],
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            registry = CapabilityRegistry(config, root / "test-history.sqlite3")
            self.assertEqual(registry.execute("apps.list", {})["apps"][0]["slug"], "test-app")
            self.assertEqual(registry.execute("apps.capabilities", {"app": "test-app"})["schema_version"], 1)
            with self.assertRaises(ValueError):
                registry.execute("shell", {"command": "whoami"})

    def test_declared_read_action_runs_but_undeclared_action_does_not(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            helper = root / "app.py"
            helper.write_text(
                "import json,sys\nprint(json.dumps({'argv': sys.argv[1:]}))\n",
                encoding="utf-8",
            )
            config = root / "apps.json"
            config.write_text(
                json.dumps(
                    {
                        "apps": [
                            {
                                "slug": "test-app",
                                "name": "Test App",
                                "capabilities_command": [sys.executable, str(helper), "agent", "--json"],
                                "actions": {
                                    "status": {
                                        "access": "read",
                                        "argv": ["status", "--json"],
                                        "options": {"--limit": "value"},
                                    }
                                },
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            registry = CapabilityRegistry(config, root / "test-history.sqlite3")
            result = registry.execute(
                "apps.run", {"app": "test-app", "action": "status", "argv": ["--limit", "2"]}
            )
            self.assertEqual(result["result"]["argv"], ["status", "--json", "--limit", "2"])
            with self.assertRaises(ValueError):
                registry.execute("apps.run", {"app": "test-app", "action": "delete", "argv": []})
            with self.assertRaises(ValueError):
                registry.execute(
                    "apps.run", {"app": "test-app", "action": "status", "argv": ["--delete"]}
                )

    def test_declared_write_action_requires_explicit_confirmation(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            helper = root / "app.py"
            helper.write_text("print('{}')\n", encoding="utf-8")
            config = root / "apps.json"
            config.write_text(
                json.dumps(
                    {
                        "apps": [
                            {
                                "slug": "test-app",
                                "name": "Test App",
                                "capabilities_command": [sys.executable, str(helper), "agent", "--json"],
                                "actions": {"update": {"access": "write", "argv": ["update", "--json"]}},
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            registry = CapabilityRegistry(config, root / "test-history.sqlite3")
            with self.assertRaises(PermissionError):
                registry.execute("apps.run", {"app": "test-app", "action": "update"})
            result = registry.execute(
                "apps.run", {"app": "test-app", "action": "update", "allow_write": True}
            )
            self.assertEqual(result["access"], "write")

    def test_action_receives_only_declared_environment_variables(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            helper = root / "app.py"
            helper.write_text(
                "import json,os\nprint(json.dumps({'allowed': os.getenv('TEST_ALLOWED'), 'secret': os.getenv('TEST_SECRET')}))\n",
                encoding="utf-8",
            )
            config = root / "apps.json"
            config.write_text(
                json.dumps({"apps": [{
                    "slug": "test-app", "name": "Test App",
                    "capabilities_command": [sys.executable, str(helper), "agent", "--json"],
                    "env": ["TEST_ALLOWED", "lowercase-rejected"],
                    "actions": {"status": {"access": "read", "argv": ["status", "--json"]}},
                }]}),
                encoding="utf-8",
            )
            with mock.patch.dict(
                os.environ, {"TEST_ALLOWED": "yes", "TEST_SECRET": "must-not-cross"}
            ):
                result = CapabilityRegistry(config, root / "test-history.sqlite3").execute(
                    "apps.run", {"app": "test-app", "action": "status", "argv": []}
                )
            self.assertEqual(result["result"], {"allowed": "yes", "secret": None})



class ApproveRefusalAuditTests(unittest.TestCase):
    """Every refusal to pair must say WHY, to the audit log and nowhere else.

    A real pairing failed on 29 October with a 403 three minutes after the
    code was issued. Five different refusals in BoothOAuthProvider.approve()
    all returned None, the route answered one deliberately vague sentence for
    every one of them, and the audit log recorded only successes -- so nobody
    could say which had fired. These tests pin each refusal to a named reason
    and, just as importantly, pin what must NEVER be written down.
    """

    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.store = BoothStore(Path(self.temp.name) / "booth.sqlite3")
        self.provider = BoothOAuthProvider(self.store, "https://booth.example")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def _client(self, client_id: str = "claude-test", scope: str = "booth:read"):
        return OAuthClientInformationFull(
            client_id=client_id,
            redirect_uris=[AnyUrl("https://claude.ai/api/mcp/auth_callback")],
            token_endpoint_auth_method="none",
            grant_types=["authorization_code", "refresh_token"],
            response_types=["code"],
            scope=scope,
        )

    def _params(self, scopes):
        return AuthorizationParams(
            state="state",
            scopes=list(scopes),
            code_challenge="challenge",
            redirect_uri=AnyUrl("https://claude.ai/api/mcp/auth_callback"),
            redirect_uri_provided_explicitly=True,
            resource="https://booth.example/mcp",
        )

    def _authorize(self, client=None, scopes=("booth:read",)) -> str:
        url = asyncio.run(self.provider.authorize(client or self._client(), self._params(scopes)))
        return url.rsplit("=", 1)[1]

    def _audit(self):
        with self.store._connect() as db:
            return [
                (row["actor"], row["event"], json.loads(row["detail_json"]))
                for row in db.execute("SELECT * FROM audit ORDER BY id").fetchall()
            ]

    def _reasons(self):
        return [d.get("reason") for _, event, d in self._audit() if event == "oauth.approve_denied"]

    def test_an_unknown_request_is_recorded_as_such(self) -> None:
        # The in-memory pending map is lost on restart, which lands here.
        self.assertIsNone(self.provider.approve("never-issued", "AAAA-BBBB-CCCC"))
        self.assertEqual(self.reasons_tail(), "pending_unknown")

    def test_an_expired_approval_window_is_distinguished_from_an_unknown_one(self) -> None:
        request_id = self._authorize()
        self.provider.pending[request_id].expires_at = time.time() - 1
        code = self.store.issue_pair_code("claude", ["booth:read"], ttl=60)
        self.assertIsNone(self.provider.approve(request_id, code))
        self.assertEqual(self.reasons_tail(), "pending_expired")

    def test_a_rejected_pair_code_is_named_and_the_request_survives(self) -> None:
        request_id = self._authorize()
        self.assertIsNone(self.provider.approve(request_id, "WRONG-WRONG-WRONG"))
        self.assertEqual(self.reasons_tail(), "pair_code_rejected")
        # The pending request is put back, so the owner may try again with the
        # right code. That behaviour predates this change; it is pinned here
        # because the audit line sits beside it.
        good = self.store.issue_pair_code("claude", ["booth:read"], ttl=60)
        self.assertIsNotNone(self.provider.approve(request_id, good))

    def test_a_second_code_does_NOT_kill_a_live_first_code(self) -> None:
        # I told the room the opposite and I was wrong; this test is what
        # caught it. issue_pair_code runs
        #     DELETE FROM pair_codes WHERE expires_at < ? OR used_at IS NOT NULL
        # which removes codes already USED or already EXPIRED. That is
        # housekeeping. A live, unused, unexpired code is not touched, so
        # asking for a second code does not sabotage an attempt in flight.
        request_id = self._authorize()
        first = self.store.issue_pair_code("claude", ["booth:read"], ttl=60)
        self.store.issue_pair_code("claude", ["booth:read"], ttl=60)
        self.assertIsNotNone(self.provider.approve(request_id, first))
        self.assertEqual(self._reasons(), [])

    def test_a_used_code_is_swept_and_refused_by_name(self) -> None:
        # What the DELETE really governs: once a code has been used, a later
        # issue sweeps it away, and presenting it again is refused.
        request_id = self._authorize()
        code = self.store.issue_pair_code("claude", ["booth:read"], ttl=60)
        self.assertIsNotNone(self.provider.approve(request_id, code))
        second_request = self._authorize()
        self.store.issue_pair_code("claude", ["booth:read"], ttl=60)
        self.assertIsNone(self.provider.approve(second_request, code))
        self.assertEqual(self.reasons_tail(), "pair_code_rejected")

    def test_a_scope_mismatch_names_both_lists_and_the_actor(self) -> None:
        request_id = self._authorize(self._client(scope="booth:write"), scopes=("booth:write",))
        code = self.store.issue_pair_code("claude", ["booth:write"], ttl=60)
        self.assertIsNone(self.provider.approve(request_id, code))
        actor, event, detail = [row for row in self._audit() if row[1] == "oauth.approve_denied"][-1]
        self.assertEqual(detail["reason"], "no_usable_scope")
        self.assertEqual(actor, "claude")
        self.assertEqual(detail["requested"], ["booth:write"])
        self.assertEqual(detail["granted"], ["booth:write"])

    def test_muse_scopes_are_usable_and_do_not_land_in_a_refusal(self) -> None:
        # Muse pairs with conversation:read and conversation:post. The code
        # reads as correct and this pins it, so that branch can be ruled out
        # of the live investigation rather than argued about.
        client = self._client(client_id="muse-test", scope="conversation:read")
        request_id = self._authorize(client, scopes=("conversation:read",))
        code = self.store.issue_pair_code("muse", ["conversation:read", "conversation:post"], ttl=60)
        self.assertIsNotNone(self.provider.approve(request_id, code))
        self.assertEqual(self._reasons(), [])

    def test_no_refusal_ever_writes_a_code_a_token_or_a_request_id(self) -> None:
        secret_code = self.store.issue_pair_code("claude", ["booth:read"], ttl=60)
        request_id = self._authorize()
        self.provider.approve("never-issued", secret_code)
        self.provider.approve(request_id, "WRONG-WRONG-WRONG")
        expired = self._authorize()
        self.provider.pending[expired].expires_at = time.time() - 1
        self.provider.approve(expired, secret_code)
        written = json.dumps(self._audit())
        self.assertNotIn(secret_code, written)
        self.assertNotIn("WRONG-WRONG-WRONG", written)
        self.assertNotIn(request_id, written)
        self.assertNotIn(expired, written)
        self.assertNotIn("challenge", written)
        # And something WAS written, or the test above proves nothing.
        self.assertTrue(self._reasons())

    def test_every_refusal_has_a_distinct_name(self) -> None:
        # If two branches ever share a reason string the log stops answering
        # the question it was added for.
        source = Path(__file__).resolve().parents[1] / "troll_booth" / "oauth.py"
        text = source.read_text(encoding="utf-8")
        names = re.findall(r'"reason": "([a-z_]+)"', text)
        self.assertEqual(len(names), 5, names)
        self.assertEqual(len(set(names)), 5, names)

    def reasons_tail(self) -> str:
        reasons = self._reasons()
        return reasons[-1] if reasons else ""


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
