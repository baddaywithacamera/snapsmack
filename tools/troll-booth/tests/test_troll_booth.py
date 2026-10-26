from __future__ import annotations

import asyncio
import json
import sys
import tempfile
import unittest
from pathlib import Path

from mcp.server.auth.provider import AuthorizationParams
from mcp.shared.auth import OAuthClientInformationFull
from pydantic import AnyUrl

from troll_booth.oauth import BoothOAuthProvider
from troll_booth.history import ConversationStore, open_conversation_store
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


class ConversationTests(unittest.TestCase):
    def test_desktop_falls_back_when_configured_mariadb_is_unreachable(self) -> None:
        from unittest.mock import patch

        with tempfile.TemporaryDirectory() as directory, \
             patch("troll_booth.secret_config.load_mariadb_url", return_value="mariadb://user:pass@offline/db"), \
             patch("troll_booth.history.MariaConversationStore", side_effect=TimeoutError("offline")), \
             patch.dict("os.environ", {"TROLL_BOOTH_OFFLINE_CACHE": str(Path(directory) / "history.sqlite3")}):
            store, label = open_conversation_store()
        self.assertIsInstance(store, ConversationStore)
        self.assertEqual(label, "OFFLINE CACHE · MARIADB UNREACHABLE")

    def test_non_desktop_callers_fail_closed_when_mariadb_is_unreachable(self) -> None:
        from unittest.mock import patch

        with patch("troll_booth.secret_config.load_mariadb_url", return_value="mariadb://user:pass@offline/db"), \
             patch("troll_booth.history.MariaConversationStore", side_effect=TimeoutError("offline")):
            with self.assertRaises(TimeoutError):
                open_conversation_store(allow_offline_cache=False)

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
    @staticmethod
    def _client(client_id: str = "agent-test") -> OAuthClientInformationFull:
        return OAuthClientInformationFull(
            client_id=client_id,
            redirect_uris=[AnyUrl("https://claude.ai/api/mcp/auth_callback")],
            token_endpoint_auth_method="none",
            grant_types=["authorization_code", "refresh_token"],
            response_types=["code"],
            scope="booth:read booth:write",
        )

    @staticmethod
    def _params() -> AuthorizationParams:
        return AuthorizationParams(
            state="state",
            scopes=["booth:read", "booth:write"],
            code_challenge="challenge",
            redirect_uri=AnyUrl("https://claude.ai/api/mcp/auth_callback"),
            redirect_uri_provided_explicitly=True,
            resource="https://booth.example/mcp",
        )

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

    def test_client_and_tokens_survive_provider_restart_with_correct_actor(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            store = BoothStore(Path(directory) / "booth.sqlite3")
            provider = BoothOAuthProvider(store, "https://booth.example")
            client = self._client("codex-test")
            asyncio.run(provider.register_client(client))
            approval_url = asyncio.run(provider.authorize(client, self._params()))
            request_id = approval_url.rsplit("=", 1)[1]
            pair_code = store.issue_pair_code("codex", ["booth:read", "booth:write"], ttl=60)
            provider.approve(request_id, pair_code)
            authorization_code = next(iter(provider.codes.values()))
            token_response = asyncio.run(provider.exchange_authorization_code(client, authorization_code))
            persisted_access = store.oauth_token(token_response.access_token, "access")
            self.assertNotIn("token", persisted_access)

            restarted = BoothOAuthProvider(BoothStore(Path(directory) / "booth.sqlite3"), "https://booth.example")
            loaded_client = asyncio.run(restarted.get_client("codex-test"))
            access = asyncio.run(restarted.load_access_token(token_response.access_token))
            refresh = asyncio.run(restarted.load_refresh_token(loaded_client, token_response.refresh_token))

            self.assertEqual(loaded_client.client_id, "codex-test")
            self.assertEqual(access.subject, "codex")
            self.assertEqual(refresh.subject, "codex")
            refreshed = asyncio.run(restarted.exchange_refresh_token(loaded_client, refresh, ["booth:read"]))
            refreshed_access = asyncio.run(restarted.load_access_token(refreshed.access_token))
            self.assertEqual(refreshed_access.subject, "codex")


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
            registry = CapabilityRegistry(config)
            self.assertEqual(registry.execute("apps.list", {})["apps"][0]["slug"], "test-app")
            self.assertEqual(registry.execute("apps.capabilities", {"app": "test-app"})["schema_version"], 1)
            with self.assertRaises(ValueError):
                registry.execute("shell", {"command": "whoami"})


if __name__ == "__main__":
    unittest.main()

