from __future__ import annotations
# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
import asyncio
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from types import SimpleNamespace
from troll_booth.history import ConversationStore
from troll_booth.oauth import BoothOAuthProvider
from troll_booth.store import BoothStore
from mcp.server.auth.provider import AuthorizationParams
from mcp.shared.auth import OAuthClientInformationFull
from pydantic import AnyUrl


class MemberTests(unittest.TestCase):
    def test_catch_up_never_skips_same_second_or_backdated_messages(self):
        with tempfile.TemporaryDirectory() as directory:
            store = ConversationStore(Path(directory) / 'history.db')
            ids = [store.append('ops', 'codex', str(n), created_at=100) for n in range(12)]
            cursor, received = None, []
            while True:
                page = store.catch_up('ops', cursor, 3)
                received.extend(m['id'] for m in page['messages'])
                cursor = page['next_cursor']
                if not page['has_more']:
                    break
            self.assertEqual(ids, received)
            later = store.append('ops', 'muse', 'backdated', created_at=50)
            self.assertEqual([later], [m['id'] for m in store.catch_up('ops', cursor)['messages']])
            self.assertEqual(later, store.catch_up('ops', later)['next_cursor'])
            with self.assertRaises(ValueError):
                store.catch_up('different-room', later)
            with self.assertRaises(ValueError):
                store.catch_up('ops', 'unknown')

    def test_member_identity_and_grants_survive_refresh(self):
        with tempfile.TemporaryDirectory() as directory:
            store = BoothStore(Path(directory) / 'broker.db')
            provider = BoothOAuthProvider(store, 'https://example.test')
            client = OAuthClientInformationFull(client_id='muse-test', redirect_uris=[AnyUrl('http://localhost/callback')], token_endpoint_auth_method='none', grant_types=['authorization_code', 'refresh_token'], response_types=['code'])
            params = AuthorizationParams(state='test', scopes=['booth:read', 'booth:write'], code_challenge='challenge', redirect_uri=AnyUrl('http://localhost/callback'), redirect_uri_provided_explicitly=True, resource='https://example.test/mcp')
            url = asyncio.run(provider.authorize(client, params))
            pair = store.issue_pair_code('muse', ['conversation:read', 'conversation:post'])
            self.assertIsNotNone(provider.approve(url.rsplit('=', 1)[1], pair))
            code = next(iter(provider.codes.values()))
            token = asyncio.run(provider.exchange_authorization_code(client, code))
            access = asyncio.run(provider.load_access_token(token.access_token))
            self.assertEqual(access.subject, 'muse')
            self.assertEqual(set(access.scopes), {'conversation:read', 'conversation:post'})
            refresh = asyncio.run(provider.load_refresh_token(client, token.refresh_token))
            renewed = asyncio.run(provider.exchange_refresh_token(client, refresh, []))
            self.assertEqual(asyncio.run(provider.load_access_token(renewed.access_token)).subject, 'muse')

    def test_member_cannot_use_apps_claims_or_status(self):
        from troll_booth import server
        token = SimpleNamespace(subject='muse', scopes=['conversation:read', 'conversation:post'])
        with patch.object(server, 'get_access_token', return_value=token):
            for action in (server.list_apps, lambda: server.app_capabilities('test'), server.list_work_scopes, lambda: server.claim_work_scope('core/'), lambda: server.release_work_scope('core/')):
                with self.assertRaises(PermissionError):
                    asyncio.run(action())
            with self.assertRaises(PermissionError):
                server.booth_status()
            with patch.object(server.store, 'enqueue', return_value='job') as enqueue, patch.object(server.store, 'job', return_value={'status': 'complete'}), patch.object(server.store, 'take_job', return_value={'posted': True}), patch.object(server.asyncio, 'sleep', return_value=None):
                asyncio.run(server.post_message('muse-onboarding', 'hello'))
                self.assertEqual(enqueue.call_args.args[0], 'muse')
                self.assertNotIn('sender', enqueue.call_args.args[2])
        with patch.object(server, 'get_access_token', return_value=None):
            with self.assertRaises(PermissionError):
                asyncio.run(server.list_conversations())

# ===== SNAPSMACK EOF =====
