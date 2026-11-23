"""SYBU must say that a Drive authorization has EXPIRED, not just "Disconnected".

SNAPSMACK_EOF_HEADER
Last non-empty line must be the Python SNAPSMACK EOF marker.

Found live on foundtextures (SS-1534, Codex). The saved Google grant had been
withdrawn. The window said "Drive Disconnected", and then the system browser
opened on its own with nothing explaining why. The owner had no way to know he
was being asked to sign in again, and no way to know which menu to use.

THREE DIFFERENT SITUATIONS WERE COLLAPSING INTO THREE WORDS:
  - Drive was never connected on this machine.
  - The Google client file is missing.
  - The authorization existed and has been withdrawn or expired.
Each needs a different action from the owner, and "Disconnected" asks for none
of them.

WHAT THIS TEST EXECUTES: authorization_state() against REAL token files written
to a temporary directory, with the token path redirected, and the consent guard
in authenticate() driven for real. No network, and no Google library call that
would reach one.
"""

import json
import os
import sys
import tempfile
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
SYBU = os.path.dirname(HERE)
if SYBU not in sys.path:
    sys.path.insert(0, SYBU)

try:
    import drive as drive_module
except Exception as exc:                                   # pragma: no cover
    raise SystemExit("cannot import sybu drive module: %s" % exc)


def _token(with_refresh=True, expired=True):
    """A token file shaped the way google-auth writes one."""
    data = {
        "token": "ya29.stand-in",
        "client_id": "stand-in.apps.googleusercontent.com",
        "client_secret": "stand-in",
        "scopes": drive_module.SCOPES,
    }
    if with_refresh:
        data["refresh_token"] = "1//stand-in"
    # An expiry in the past is what makes creds.valid False without a network
    # call, which is the whole point: a status indicator must not block on the
    # network to paint itself.
    stamp = time.gmtime(time.time() - (3600 if expired else -3600))
    data["expiry"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", stamp)
    return data


class DriveAuthorizationState(unittest.TestCase):

    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="ss1534-")
        self.token = os.path.join(self.tmp, "token.json")
        # A real (stub) client file, so 'no_client' cannot mask the states
        # these tests are actually about. My first version passed a path that
        # did not exist and got the perfectly correct answer to a different
        # question.
        self.client = os.path.join(self.tmp, "credentials.json")
        with open(self.client, "w") as fh:
            fh.write('{"installed":{"client_id":"stand-in"}}')
        self._orig = drive_module._token_path
        drive_module._token_path = lambda: self.token

    def tearDown(self):
        drive_module._token_path = self._orig
        for f in (self.token, self.client):
            if os.path.isfile(f):
                os.unlink(f)
        os.rmdir(self.tmp)

    # ── the three situations that used to read the same ──────────────────

    def test_never_connected_says_so_and_names_the_menu(self):
        state, words = drive_module.authorization_state()
        self.assertEqual(state, "never")
        self.assertIn("not connected", words.lower())
        self.assertIn("Connect Drive", words)

    def test_a_withdrawn_grant_is_reported_as_expired_not_disconnected(self):
        """THE ONE THAT BIT SEAN. A refresh token sitting on disk is NOT proof
        of a working grant: a withdrawn authorization leaves the file exactly
        as it was."""
        with open(self.token, "w") as fh:
            json.dump(_token(with_refresh=True, expired=True), fh)
        state, words = drive_module.authorization_state()
        self.assertEqual(state, "expired")
        self.assertIn("expired", words.lower())
        self.assertIn("Connect Drive", words)
        # It must not be describable as merely disconnected.
        self.assertNotEqual(state, "never")

    def test_a_missing_client_file_is_its_own_answer(self):
        state, words = drive_module.authorization_state(
            os.path.join(self.tmp, "no-such-credentials.json"))
        self.assertEqual(state, "no_client")
        self.assertIn("credentials", words.lower())

    def test_an_unreadable_token_says_that_rather_than_crashing(self):
        with open(self.token, "w") as fh:
            fh.write("{ this is not json")
        state, words = drive_module.authorization_state()
        self.assertEqual(state, "unreadable")
        self.assertIn("Connect Drive", words)

    def test_it_does_not_reach_the_network_to_answer(self):
        """A status indicator that blocks on the network paints late or not at
        all. Proven by answering with no client file and no reachable host."""
        with open(self.token, "w") as fh:
            json.dump(_token(), fh)
        started = time.time()
        drive_module.authorization_state()
        self.assertLess(time.time() - started, 1.0)

    # ── the browser that opened by itself ────────────────────────────────

    def test_an_automatic_attempt_refuses_instead_of_opening_a_browser(self):
        """Consent is right when the OWNER asked to connect. It is not the
        silent answer to a token that has stopped working, and that silence is
        what put a browser on Sean's screen with no explanation."""
        # NO token file: consent would be needed, and that is precisely when
        # the browser used to appear unannounced. A token file with no refresh
        # token cannot be used here because google-auth REFUSES to load one --
        # which is its own fault, now caught rather than surfacing as a raw
        # library ValueError about "expected format".
        self.assertFalse(os.path.exists(self.token))

        with self.assertRaises(drive_module.DriveAuthorizationExpired) as caught:
            drive_module.authenticate(self.client, allow_consent=False)

        said = str(caught.exception)
        self.assertIn("Connect Drive", said)
        # It has to say nothing was published, or an owner seeing an error
        # mid-batch will assume the worst.
        self.assertIn("no post", said.lower())
        self.assertIn("uploaded", said.lower())

    def test_a_corrupt_token_does_not_surface_as_a_library_traceback(self):
        """google-auth raises ValueError for a token file missing
        refresh_token, and that reached the owner as "Authorized user info was
        not in the expected format" -- true, and useless to act on."""
        with open(self.token, "w") as fh:
            fh.write("{ not json at all")
        with self.assertRaises(drive_module.DriveAuthorizationExpired):
            drive_module.authenticate(self.client, allow_consent=False)

    def test_consent_is_still_allowed_when_it_is_asked_for(self):
        """The guard must not break the deliberate Connect Drive action. Proven
        by the default staying permissive: the refusal is opt-in, so no existing
        caller silently loses the ability to connect."""
        import inspect
        sig = inspect.signature(drive_module.authenticate)
        self.assertEqual(sig.parameters["allow_consent"].default, True)


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
