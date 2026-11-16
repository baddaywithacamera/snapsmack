from __future__ import annotations
# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
"""The Antigravity seat gets exactly what Sean approved, and no existing seat loses anything.

Sean approved a Brain Trust seat for Google Antigravity (actor ``gemini``):
read room history, post messages, and read/claim/release work scopes. NOT
deployment, service control, arbitrary app write, build/package, or host
control.

THE SERVER COULD NOT EXPRESS THAT BEFORE. ``scope.list`` fell through to
``booth:read`` and claim/release required ``booth:write`` -- and ``booth:write``
also permits confirmed app-write actions. So issuing the approved seat with the
scopes that existed would have granted MORE than was approved: the narrow
reading of an approval is the one that has to be enforceable, not the one in
the message. ``scope:read`` and ``scope:write`` are that boundary.

The second half of this file matters as much as the first. Every existing seat
holds ``booth:write`` and none holds ``scope:write``, so a careless version of
this change takes claim/release away from claude, codex and the owner console
the moment it deploys -- a regression nobody would see until an agent could not
claim a file and two of them edited it at once, which is the specific failure
that cost Sean a month.
"""

import unittest

from troll_booth import server


OWNER_PAIR_SCOPES = {
    # actor -> exactly the scopes /owner/pair-code issues for it
    "muse": {"conversation:read", "conversation:post"},
    "gemini": {"conversation:read", "conversation:post",
               "scope:read", "scope:write"},
}

FORBIDDEN_FOR_GEMINI = {"booth:write", "booth:read"}


def _issued_scopes(actor: str, write: bool = True) -> set[str]:
    """What /owner/pair-code hands an actor. Calls the real decision.

    The first version of this parsed server.py's source and read straight past
    one actor's block into the next, so it reported muse holding gemini's
    scopes. That is a test lying about an access-control decision, which is
    worse than no test -- so the decision moved into pair_scopes(), a pure
    function, and this calls it.
    """
    return set(server.pair_scopes(actor, write))


class GeminiSeatTests(unittest.TestCase):

    def test_gemini_is_an_accepted_actor(self):
        self.assertIn("gemini", server.PAIR_ACTORS,
                      "gemini cannot be issued a pair code, so Antigravity "
                      "stays blocked")

    def test_an_unknown_actor_is_still_refused(self):
        """The whitelist is the control. It refused antigravity and gemini
        before today, which is why Codex was blocked -- it was working."""
        for unknown in ("antigravity", "", "root", "sean"):
            self.assertNotIn(unknown, server.PAIR_ACTORS)

    def test_gemini_gets_exactly_the_approved_scopes(self):
        self.assertEqual(_issued_scopes("gemini"), OWNER_PAIR_SCOPES["gemini"])

    def test_gemini_gets_no_booth_write(self):
        """The whole point of the new scopes.

        booth:write also permits confirmed app-write actions, which Sean did
        not approve for this seat.
        """
        issued = _issued_scopes("gemini")
        self.assertFalse(issued & FORBIDDEN_FOR_GEMINI,
                         f"gemini was issued {issued & FORBIDDEN_FOR_GEMINI}, "
                         "which is more than was approved")

    def test_muse_is_unchanged(self):
        self.assertEqual(_issued_scopes("muse"), OWNER_PAIR_SCOPES["muse"])

    def test_the_new_scopes_are_valid_oauth_scopes(self):
        """An unregistered scope cannot be requested, so the seat would fail."""
        valid = set(server.auth.client_registration_options.valid_scopes)
        for scope in ("scope:read", "scope:write"):
            self.assertIn(scope, valid)

    def test_default_scopes_did_not_quietly_widen(self):
        """A new scope in the DEFAULTS would hand it to every future client."""
        self.assertEqual(
            set(server.auth.client_registration_options.default_scopes),
            {"booth:read", "booth:write"})


class ScopeGateTests(unittest.TestCase):
    """These call the real gate, with a fake token."""

    def _with_scopes(self, scopes):
        class _Token:
            def __init__(self, s):
                self.scopes = list(s)
                self.subject = "test"
        return _Token(scopes)

    def _require_any(self, scopes, accepted):
        import unittest.mock as mock
        with mock.patch.object(server, "get_access_token",
                               return_value=self._with_scopes(scopes)):
            server._require_any_scope(*accepted)

    def test_scope_write_can_claim(self):
        self._require_any({"scope:write"}, ("scope:write", "booth:write"))

    def test_booth_write_can_still_claim(self):
        """The regression guard.

        Every existing seat holds booth:write and none holds scope:write. If
        claim/release ever required scope:write alone, claude and codex lose
        the ability to claim a file the moment this deploys -- and two agents
        editing the same file is the exact failure this room exists to stop.
        """
        self._require_any({"booth:read", "booth:write"},
                          ("scope:write", "booth:write"))

    def _call_tool(self, coro_fn, scopes, **kwargs):
        """Call the real tool function with a fake token, and report whether
        it reached the queue."""
        import asyncio
        import unittest.mock as mock

        class _Token:
            def __init__(self, s):
                self.scopes = list(s)
                self.subject = "test"

        with mock.patch.object(server, "get_access_token",
                               return_value=_Token(scopes)),              mock.patch.object(server.store, "enqueue",
                               side_effect=RuntimeError("admitted")):
            try:
                asyncio.run(coro_fn(**kwargs))
            except RuntimeError as exc:
                return str(exc) == "admitted"
            except PermissionError:
                return False
        return False

    def test_an_existing_seat_can_still_claim_and_release(self):
        """AT THE CALL SITE, not just the helper.

        The first version of this file tested _require_any_scope() directly,
        so changing claim_work_scope to demand scope:write alone passed --
        found by mutation. Every existing seat holds booth:write and none
        holds scope:write, so that change would have taken claiming away from
        claude and codex the moment it deployed, and two agents editing one
        file is the failure this room exists to prevent.
        """
        existing = {"booth:read", "booth:write"}
        self.assertTrue(self._call_tool(server.claim_work_scope, existing,
                                        scope="core/x.php"))
        self.assertTrue(self._call_tool(server.release_work_scope, existing,
                                        scope="core/x.php"))

    def test_the_gemini_seat_can_claim_and_release(self):
        seat = set(server.pair_scopes("gemini"))
        self.assertTrue(self._call_tool(server.claim_work_scope, seat,
                                        scope="core/x.php"))
        self.assertTrue(self._call_tool(server.release_work_scope, seat,
                                        scope="core/x.php"))

    def test_a_conversation_only_seat_cannot_claim_or_release(self):
        """BOTH, because they are two call sites.

        Testing only claim let a mutation that removed the gate from release
        pass. A seat that cannot claim must not be able to release either --
        otherwise it can drop another agent's claim on a file and the next two
        writers collide.
        """
        chat_only = {"conversation:read", "conversation:post"}
        self.assertFalse(self._call_tool(
            server.claim_work_scope, chat_only, scope="core/x.php"))
        self.assertFalse(self._call_tool(
            server.release_work_scope, chat_only, scope="core/x.php"))

    def test_a_read_only_work_scope_seat_cannot_claim_or_release(self):
        """scope:read WITHOUT scope:write -- the seat shape that exposes the
        gate on each tool.

        A conversation-only seat is refused earlier, by _queued, which MASKED
        a mutation that deleted the gate from release_work_scope entirely:
        the test passed because the seat never got that far. This seat does
        get that far. It may see who holds what, and must not be able to drop
        another agent's claim -- which would let the next two writers collide
        on one file.
        """
        read_only = {"scope:read"}
        self.assertTrue(self._call_tool(
            server.list_work_scopes, read_only))
        self.assertFalse(self._call_tool(
            server.claim_work_scope, read_only, scope="core/x.php"))
        self.assertFalse(self._call_tool(
            server.release_work_scope, read_only, scope="core/x.php"))

    def test_a_seat_with_neither_is_refused(self):
        with self.assertRaises(PermissionError):
            self._require_any({"conversation:read", "conversation:post"},
                              ("scope:write", "booth:write"))

    def test_no_token_is_refused(self):
        import unittest.mock as mock
        with mock.patch.object(server, "get_access_token", return_value=None):
            with self.assertRaises(PermissionError):
                server._require_any_scope("scope:write", "booth:write")


class QueuedCapabilityRoutingTests(unittest.TestCase):
    """Which scope each capability family demands, read from the gate itself."""

    def _required_for(self, capability, scopes):
        """True when _queued would admit this capability for these scopes."""
        import asyncio
        import unittest.mock as mock

        class _Token:
            def __init__(self, s):
                self.scopes = list(s)
                self.subject = "test"

        with mock.patch.object(server, "get_access_token",
                               return_value=_Token(scopes)), \
             mock.patch.object(server.store, "enqueue",
                               side_effect=RuntimeError("admitted")):
            try:
                asyncio.run(server._queued(capability, {}, "test"))
            except RuntimeError as exc:
                return str(exc) == "admitted"
            except PermissionError:
                return False
        return False

    def test_scope_list_needs_only_scope_read(self):
        """It fell through to booth:read before, which the gemini seat lacks."""
        self.assertTrue(self._required_for("scope.list", {"scope:read"}))

    def test_scope_list_still_works_for_booth_read(self):
        self.assertTrue(self._required_for("scope.list", {"booth:read"}))

    def test_conversation_read_is_unchanged(self):
        self.assertTrue(self._required_for("conversation.messages",
                                           {"conversation:read"}))

    def test_a_conversation_only_seat_cannot_reach_work_scopes(self):
        self.assertFalse(self._required_for("scope.list",
                                            {"conversation:read"}))

    def test_a_scope_only_seat_cannot_read_conversations(self):
        self.assertFalse(self._required_for("conversation.messages",
                                            {"scope:read"}))

    def test_an_app_capability_still_needs_booth_read(self):
        self.assertFalse(self._required_for("app.capabilities",
                                            {"scope:read"}))
        self.assertTrue(self._required_for("app.capabilities",
                                           {"booth:read"}))


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
