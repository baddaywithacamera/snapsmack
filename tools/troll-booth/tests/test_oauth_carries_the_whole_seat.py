from __future__ import annotations
# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
"""An approved pairing's access token carries every scope the owner granted.

THE BUG THIS PINS, found by Codex in the production audit. pair-code.issued
and pair-code.consumed recorded gemini's four scopes -- conversation:read,
conversation:post, scope:read, scope:write -- and oauth.approved recorded only
the two conversation ones. BoothOAuthProvider.approve() force-carried a NAMED
list (booth:write, then the two conversation scopes) and dropped anything
else in silence, so every scope.* call failed before it reached the queue and
the only symptom was a tool erroring with nothing in the logs.

A named list is the wrong shape, not merely incomplete: the next scope anyone
adds breaks identically. So the identity decides, in full, bounded by what the
pairing code granted.

These tests drive the REAL provider through authorize -> approve -> exchange
-> load_access_token on a temporary database, so they assert what a paired
session would actually hold rather than reading the source.
"""

import asyncio
import tempfile
import unittest
from pathlib import Path

from mcp.server.auth.provider import AuthorizationParams
from mcp.shared.auth import OAuthClientInformationFull
from pydantic import AnyUrl

from troll_booth.oauth import BoothOAuthProvider
from troll_booth.store import BoothStore


GEMINI = ["conversation:read", "conversation:post", "scope:read", "scope:write"]


class ScopeCarryTests(unittest.TestCase):

    def _paired_scopes(self, actor, granted, requested):
        """What a session ends up holding, through the whole real flow."""
        with tempfile.TemporaryDirectory() as directory:
            store = BoothStore(Path(directory) / "broker.db")
            provider = BoothOAuthProvider(store, "https://example.test")
            client = OAuthClientInformationFull(
                client_id=f"{actor}-test",
                redirect_uris=[AnyUrl("http://localhost/callback")],
                token_endpoint_auth_method="none",
                grant_types=["authorization_code", "refresh_token"],
                response_types=["code"])
            params = AuthorizationParams(
                state="test", scopes=list(requested), code_challenge="challenge",
                redirect_uri=AnyUrl("http://localhost/callback"),
                redirect_uri_provided_explicitly=True,
                resource="https://example.test/mcp")
            url = asyncio.run(provider.authorize(client, params))
            pair = store.issue_pair_code(actor, list(granted))
            if provider.approve(url.rsplit("=", 1)[1], pair) is None:
                return None
            code = next(iter(provider.codes.values()))
            token = asyncio.run(provider.exchange_authorization_code(client, code))
            access = asyncio.run(provider.load_access_token(token.access_token))
            return set(access.scopes)

    def test_a_gemini_pairing_yields_all_four_scopes(self):
        """The reported failure, end to end.

        Antigravity requests only its original read scope, which is why the
        carry exists at all.
        """
        held = self._paired_scopes("gemini", GEMINI, ["booth:read"])
        self.assertEqual(held, set(GEMINI))

    def test_a_gemini_pairing_yields_no_booth_scopes(self):
        """The other half: carrying everything must not carry extras.

        booth:write permits confirmed app-write actions, which Sean did not
        approve for this seat.
        """
        held = self._paired_scopes("gemini", GEMINI, ["booth:read", "booth:write"])
        self.assertEqual(held, set(GEMINI))
        self.assertFalse(held & {"booth:read", "booth:write"})

    def test_the_grant_is_bounded_by_the_pairing_code(self):
        """A client asking for the world still gets only the seat.

        This is the property that makes carrying the identity whole safe.
        """
        held = self._paired_scopes(
            "gemini", GEMINI,
            ["booth:read", "booth:write", "scope:read", "scope:write",
             "conversation:read", "conversation:post", "invented:scope"])
        self.assertEqual(held, set(GEMINI))

    def test_muse_is_unchanged(self):
        held = self._paired_scopes(
            "muse", ["conversation:read", "conversation:post"],
            ["booth:read", "booth:write"])
        self.assertEqual(held, {"conversation:read", "conversation:post"})

    def test_an_ordinary_seat_is_unchanged(self):
        held = self._paired_scopes(
            "claude", ["booth:read", "booth:write"], ["booth:read"])
        self.assertEqual(held, {"booth:read", "booth:write"})

    def test_a_read_only_seat_is_unchanged(self):
        held = self._paired_scopes("claude", ["booth:read"], ["booth:read"])
        self.assertEqual(held, {"booth:read"})

    def test_a_future_scope_is_carried_without_touching_this_file(self):
        """The regression guard on the SHAPE.

        The old code named the scopes to carry, so each new one had to be
        remembered here or it vanished silently. A scope nobody has written
        down yet must survive the flow.
        """
        granted = ["conversation:read", "some:future-scope"]
        held = self._paired_scopes("gemini", granted, ["booth:read"])
        self.assertEqual(held, set(granted))


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
