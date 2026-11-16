from __future__ import annotations
# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
"""A gemini-scoped seat can actually list work scopes, not merely be admitted.

Gemini reported list_work_scopes failing in production, and the PM's first
guess was my scope:read routing in 777e464f. THE GAP THAT MADE THAT GUESS
REASONABLE WAS MINE: test_gemini_seat_scopes.py mocks store.enqueue to raise,
so it proves the GATE admits the capability and nothing after it. "Admitted"
and "works" are two claims, and I had only tested the first.

This runs the whole local path on temporary files: the server's gate, a real
enqueue, the worker's dispatch, and the claim coming back. No network, no
production, no credentials.

WHAT IT CANNOT TELL YOU, said plainly: it exercises THIS revision's worker. If
the deployed worker predates scope.* handling it will answer "capability is
not available to this TROLL BOOTH worker" no matter what the server does, and
that is invisible from here. Whoever holds service access can settle it by
asking the same capability as claude -- if claude fails too, the worker is the
problem and my routing is not.
"""

import asyncio
import tempfile
import unittest
import unittest.mock as mock
from pathlib import Path

from troll_booth import server
from troll_booth.history import ConversationStore
from troll_booth.worker import CapabilityRegistry


GEMINI_SCOPES = ["conversation:read", "conversation:post",
                 "scope:read", "scope:write"]


class _Token:
    def __init__(self, scopes, subject="gemini"):
        self.scopes = list(scopes)
        self.subject = subject


class WorkScopesEndToEndTests(unittest.TestCase):

    def _registry(self, directory):
        config = Path(directory) / "apps.json"
        config.write_text("{}", encoding="utf-8")
        return CapabilityRegistry(config, Path(directory) / "history.db")

    def test_scope_list_returns_claims_for_a_gemini_seat(self):
        """The gate, the queue and the worker, in one pass."""
        with tempfile.TemporaryDirectory() as directory:
            registry = self._registry(directory)
            history = ConversationStore(Path(directory) / "history.db")
            history.claim_scope("core/public-runtime.php", "codex", ttl=600)

            # 1. the server gate admits it for a seat holding only scope:read
            with mock.patch.object(server, "get_access_token",
                                   return_value=_Token(GEMINI_SCOPES)):
                with mock.patch.object(server.store, "enqueue",
                                       side_effect=RuntimeError("admitted")):
                    with self.assertRaises(RuntimeError) as caught:
                        asyncio.run(server.list_work_scopes())
                    self.assertEqual(str(caught.exception), "admitted")

            # 2. and the worker actually answers it
            result = registry.execute("scope.list", {}, actor="gemini")
            scopes = [claim["scope"] for claim in result["claims"]]
            self.assertIn("core/public-runtime.php", scopes,
                          "the worker admitted the capability but returned no "
                          "claims, so a seat would see an empty list and "
                          "believe nothing is claimed -- worse than an error")

    def test_a_gemini_seat_can_claim_and_release_for_real(self):
        with tempfile.TemporaryDirectory() as directory:
            registry = self._registry(directory)
            registry.execute("scope.claim", {"scope": "core/x.php"},
                             actor="gemini")
            held = [c["scope"] for c in
                    registry.execute("scope.list", {}, actor="gemini")["claims"]]
            self.assertIn("core/x.php", held)

            registry.execute("scope.release", {"scope": "core/x.php"},
                             actor="gemini")
            held = [c["scope"] for c in
                    registry.execute("scope.list", {}, actor="gemini")["claims"]]
            self.assertNotIn("core/x.php", held)

    def test_the_worker_on_this_revision_knows_the_scope_capabilities(self):
        """The thing a production failure would most likely mean.

        An older worker raises ValueError('capability is not available to this
        TROLL BOOTH worker') for anything it does not know. If that is what
        gemini hit, the server routing is innocent and the worker is stale --
        which is the fourth source-skew of the day, not a code fault.
        """
        with tempfile.TemporaryDirectory() as directory:
            registry = self._registry(directory)
            for capability in ("scope.list", "scope.claim", "scope.release"):
                try:
                    registry.execute(capability, {"scope": "core/x.php"},
                                     actor="gemini")
                except ValueError as exc:  # pragma: no cover - the failure case
                    self.fail(f"{capability}: {exc}")

    def test_an_unknown_capability_still_refuses(self):
        """So the test above means something."""
        with tempfile.TemporaryDirectory() as directory:
            registry = self._registry(directory)
            with self.assertRaises(ValueError):
                registry.execute("scope.obliterate", {}, actor="gemini")


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
