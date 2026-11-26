"""Reconnect an owner-approved seat after adapter restart.

SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
"""

import os
from pathlib import Path
import tempfile
import unittest
from urllib.parse import parse_qs, urlsplit

from cryptography.fernet import Fernet
from mcp.server.auth.provider import AuthorizationParams, TokenError
from mcp.shared.auth import OAuthClientInformationFull
from pydantic import AnyUrl

from troll_booth.oauth import BoothOAuthProvider
from troll_booth.oauth_storage import OAuthGrantStore
from troll_booth.store import BoothStore


class GrantRestartTests(unittest.IsolatedAsyncioTestCase):
    async def _restart_roundtrip(self, mariadb_url=None):
        with tempfile.TemporaryDirectory() as directory:
            store = BoothStore(Path(directory) / "adapter.sqlite")
            key = Fernet.generate_key()
            client = OAuthClientInformationFull(
                client_id="muse-test-" + Path(directory).name,
                client_secret="disposable-test-client-secret",
                token_endpoint_auth_method="client_secret_basic",
                redirect_uris=[AnyUrl("https://booth.example/callback")],
                grant_types=["authorization_code", "refresh_token"],
                response_types=["code"],
            )

            def start_adapter(issuer="https://booth.example"):
                grants = (OAuthGrantStore.for_mariadb(mariadb_url, key, issuer) if mariadb_url else
                          OAuthGrantStore(store._connect, key, issuer, mariadb=False))
                return BoothOAuthProvider(store, issuer, grants)

            first = start_adapter()
            await first.register_client(client)
            with first.grants._transaction() as execute:
                stored = execute(
                    "SELECT encrypted_client FROM troll_oauth_clients WHERE client_hash=%s",
                    (first.grants._verifier(client.client_id),),
                ).fetchone()
            self.assertNotIn(client.client_secret.encode(), stored["encrypted_client"])
            pair = store.issue_pair_code("muse", ["conversation:read", "conversation:post"])
            approval = await first.authorize(client, AuthorizationParams(
                state="test", scopes=["booth:write", "conversation:read"],
                code_challenge="fixture", redirect_uri=client.redirect_uris[0],
                redirect_uri_provided_explicitly=True, resource="https://booth.example/mcp",
            ))
            request_id = parse_qs(urlsplit(approval).query)["request"][0]
            redirect = first.approve(request_id, pair)
            code_string = parse_qs(urlsplit(redirect).query)["code"][0]
            code = await first.load_authorization_code(client, code_string)
            creds = await first.exchange_authorization_code(client, code)
            with self.assertRaises(TokenError):
                await first.exchange_authorization_code(client, code)
            self.assertIsNone(store.consume_pair_code(pair))
            access = await first.load_access_token(creds.access_token)
            self.assertEqual(access.subject, "muse")
            self.assertEqual(set(access.scopes), {"conversation:read", "conversation:post"})

            other_issuer = start_adapter("https://other.example")
            self.assertIsNone(await other_issuer.load_access_token(creds.access_token))
            self.assertIsNone(await other_issuer.get_client(client.client_id))

            second = start_adapter()
            restored = await second.get_client(client.client_id)
            self.assertEqual(restored.client_secret, client.client_secret)
            self.assertEqual(await second.load_access_token(creds.access_token), access)
            refresh = await second.load_refresh_token(restored, creds.refresh_token)
            self.assertEqual(refresh.resource, "https://booth.example/mcp")
            child = await second.exchange_refresh_token(
                restored, refresh, ["booth:write", "conversation:read"],
            )
            child_access = await second.load_access_token(child.access_token)
            self.assertEqual(child_access.scopes, ["conversation:read"])
            self.assertEqual(child_access.subject, "muse")
            await second.revoke_token(child_access)

            third = start_adapter()
            self.assertIsNone(await third.load_access_token(creds.access_token))
            self.assertIsNone(await third.load_access_token(child.access_token))
            self.assertIsNone(await third.load_refresh_token(restored, creds.refresh_token))
            with self.assertRaises(TokenError):
                await third.exchange_refresh_token(restored, refresh, ["conversation:read"])

    async def test_local_adapter_restart(self):
        await self._restart_roundtrip()

    @unittest.skipUnless(os.environ.get("TEST_TROLL_BOOTH_MARIADB_URL"), "requires disposable local MariaDB")
    async def test_mariadb_adapter_restart(self):
        url = os.environ["TEST_TROLL_BOOTH_MARIADB_URL"]
        parsed = urlsplit(url)
        self.assertIn(parsed.hostname, {"127.0.0.1", "localhost", "::1"})
        self.assertTrue(parsed.path.startswith("/trollbooth_test_"))
        await self._restart_roundtrip(url)


# ===== SNAPSMACK EOF =====
