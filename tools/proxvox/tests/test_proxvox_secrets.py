"""Sean's two secret-handling rulings, tested. (SS-1660, SS-1661)

  2026-11-28 14:56  "blank" — secret VALUES are blanked out of a command
                    before it is written to the audit log, and the sha256 of
                    the ORIGINAL command is kept so the entry is still
                    provable.

  2026-11-28 15:02  "i agree" — the COMPLETE four-entry read blocklist. A
                    blocked read is LOGGED, not silently dropped.

He asked for a test per entry and a mutant that disables each protection; the
mutants are run separately and recorded in the commit, since a test cannot
mutate the code it is testing and still be trusted about the result.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import hashlib
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from proxvox.secrets import (                                  # noqa: E402
    BLOCKED_READ_PATHS, BLOCKED_READ_PREFIXES, REDACTED,
    blocked_read_reason, redact_command,
)


class RedactionTests(unittest.TestCase):
    """Real-looking commands, as Sean asked for. Not toy strings."""

    def assert_blanked(self, command: str, secret: str, keep: str):
        out = redact_command(command)
        self.assertNotIn(secret, out, "the secret survived: " + out)
        self.assertIn(REDACTED, out, "nothing was blanked: " + out)
        # The KEY has to survive, or the log cannot tell you a password was
        # even supplied -- which is half of why the text is logged at all.
        self.assertIn(keep, out, "the key was lost: " + out)
        return out

    def test_mysql_short_p_with_no_space(self):
        # The form that actually gets typed.
        self.assert_blanked(
            "mysql -u root -psup3rs3cret -e 'UPDATE snap_posts SET x=1'",
            "sup3rs3cret", "-p")

    def test_mysql_long_password_flag(self):
        self.assert_blanked('mysql --password=hunter2 -e "SELECT 1"',
                            "hunter2", "--password=")
        self.assert_blanked('mysql --password hunter2 -e "SELECT 1"',
                            "hunter2", "--password")

    def test_password_passwd_pwd_keys(self):
        for key in ("password", "passwd", "pwd", "PASSWORD"):
            self.assert_blanked("echo %s=letmein >> /tmp/x" % key,
                                "letmein", key)
        self.assert_blanked("app --config pass: s3cret", "s3cret", "pass")

    def test_sql_identified_by(self):
        out = self.assert_blanked(
            """mysql -e "CREATE USER 'a'@'%' IDENTIFIED BY 'pw-here'" """,
            "pw-here", "IDENTIFIED BY")
        # The rest of the statement must still be readable -- that is the
        # point of logging the text.
        self.assertIn("CREATE USER", out)

    def test_authorization_header_keeps_the_scheme(self):
        out = self.assert_blanked(
            'curl -H "Authorization: Bearer abc123def" https://x/y',
            "abc123def", "Bearer")
        # AND THE COMMAND STAYS WELL FORMED. The first version of the
        # redaction matched the already-redacted text with a second pattern
        # and swallowed the closing quote.
        self.assertIn('https://x/y', out)
        self.assertEqual(out.count('"'), 2, out)

    def test_authorization_basic_blanks_the_credential_not_the_scheme(self):
        # A single-token rule would have blanked the word "Basic" and left the
        # credential in the log. Measured; that is why the scheme is its own
        # group.
        out = self.assert_blanked(
            'curl -H "Authorization: Basic dXNlcjpwYXNz" https://x/y',
            "dXNlcjpwYXNz", "Basic")
        self.assertIn("Basic " + REDACTED, out)

    def test_proxmox_tokens_and_cookies(self):
        self.assert_blanked("export PVEAPIToken=root@pam!t=9f8e7d",
                            "9f8e7d", "PVEAPIToken=")
        self.assert_blanked("curl -b PVEAuthCookie=PVE:u:1234::sig https://x",
                            "1234", "PVEAuthCookie=")

    def test_a_quoted_value_keeps_its_quotes(self):
        """Muse's nit 3 (room 9414), and it is the same bug twice.

        A greedy non-space match swallowed BOTH
        logged command came out as password=[REDACTED] with its quoting
        broken. I had fixed exactly this on the Authorization header an hour
        earlier and did not go looking for its siblings.
        """
        out = redact_command('echo password="hunter2" >> /tmp/x')
        self.assertNotIn("hunter2", out)
        self.assertIn('password="%s"' % REDACTED, out)
        self.assertEqual(out.count('"'), 2, out)

        single = redact_command("app --config pass:'s3cret' --go")
        self.assertNotIn("s3cret", single)
        self.assertIn("pass:'%s'" % REDACTED, single)
        self.assertEqual(single.count("'"), 2, single)

        # And an UNQUOTED value still works, which is the case that used to be
        # the only one tested.
        bare = redact_command("echo password=letmein >> /tmp/x")
        self.assertNotIn("letmein", bare)
        self.assertIn("password=" + REDACTED, bare)
        self.assertNotIn('"', bare)

    def test_an_innocent_command_is_untouched(self):
        for safe in ("pvesh get /version",
                     "systemctl restart apache2",
                     "mysql -e 'SELECT COUNT(*) FROM snap_images'",
                     "ls -la /var/www"):
            self.assertEqual(redact_command(safe), safe)

    def test_the_digest_is_of_the_original_not_the_redaction(self):
        """Otherwise redacting a log would destroy its own proof."""
        original = "mysql -u root -psup3rs3cret -e 'SELECT 1'"
        redacted = redact_command(original)
        self.assertNotEqual(redacted, original)
        # THE TAUTOLOGY THAT WAS HERE IS GONE. It asserted
        # sha256(original) == sha256(original), which is true of any string
        # and proves nothing, and it sat next to the real check looking like
        # evidence. Muse flagged it (room 9414). What matters -- that
        # write_client records the digest of the ORIGINAL and not of the
        # redaction -- is asserted against the real audit row in
        # test_proxvox_p3.test_a_logged_command_is_redacted_but_its_digest_is_of_the_original.
        # All that belongs here is that the two digests DIFFER, so hashing the
        # redaction could never be mistaken for hashing the original.
        self.assertNotEqual(hashlib.sha256(redacted.encode()).hexdigest(),
                            hashlib.sha256(original.encode()).hexdigest())

    def test_empty_and_odd_input_does_not_explode(self):
        for odd in ("", None, "   ", "-p", "password=", "Bearer"):
            redact_command(odd)                       # must not raise


class BlocklistTests(unittest.TestCase):
    """One test per entry, as Sean asked."""

    def test_tfa_config_is_blocked(self):
        self.assertNotEqual(blocked_read_reason("/etc/pve/priv/tfa.cfg"), "")

    def test_authkey_is_blocked(self):
        self.assertNotEqual(blocked_read_reason("/etc/pve/priv/authkey.key"), "")

    def test_token_config_is_blocked(self):
        self.assertNotEqual(blocked_read_reason("/etc/pve/priv/token.cfg"), "")

    def test_prox_vox_own_state_is_blocked(self):
        """Entry 4: the tool must not be able to read its own window state."""
        for path in ("/var/lib/proxvox/session.json",
                     "/var/lib/proxvox/grants/abc",
                     "/var/lib/proxvox",
                     "/run/proxvox/current"):
            self.assertNotEqual(blocked_read_reason(path), "", path)

    def test_traversal_and_sloppy_paths_cannot_walk_around_it(self):
        for path in ("/etc/pve/priv/../priv/tfa.cfg",
                     "/etc//pve/priv/tfa.cfg",
                     "/etc/pve/priv/./tfa.cfg",
                     "/etc/pve/x/../priv/authkey.key",
                     "\\etc\\pve\\priv\\token.cfg",
                     "/var/lib/proxvox/../proxvox/session.json"):
            self.assertNotEqual(blocked_read_reason(path), "", path)

    def test_what_sean_deliberately_left_READABLE(self):
        """His 23 Nov ruling, and it is deliberate, not an oversight.

        Password hashes and private keys stay readable inside the window. The
        list blocks only what would let something authorize AS him or forge a
        Proxmox ticket. A test says so, so nobody "tightens" it later without
        going back to him.
        """
        for path in ("/root/.ssh/id_rsa",
                     "/etc/shadow",
                     "/etc/pve/priv/other.cfg",
                     "/var/www/snapsmack.ca/core/db.php",
                     "/var/lib/mysql/ibdata1"):
            self.assertEqual(blocked_read_reason(path), "", path)

    def test_the_list_is_exactly_what_he_approved(self):
        """He said COMPLETE. This fails if anything is added quietly."""
        self.assertEqual(set(BLOCKED_READ_PATHS), {
            "/etc/pve/priv/tfa.cfg",
            "/etc/pve/priv/authkey.key",
            "/etc/pve/priv/token.cfg",
        })
        self.assertEqual(len(BLOCKED_READ_PATHS), 3)
        self.assertEqual(set(BLOCKED_READ_PREFIXES),
                         {"/var/lib/proxvox/", "/run/proxvox/"})

    def test_a_blocked_read_explains_itself(self):
        """The refusal has to say why, and cite the ruling."""
        reason = blocked_read_reason("/etc/pve/priv/tfa.cfg")
        self.assertIn("2026-11-28", reason)
        self.assertIn("tfa.cfg", reason)

    def test_an_empty_path_is_not_blocked(self):
        self.assertEqual(blocked_read_reason(""), "")
        self.assertEqual(blocked_read_reason(None), "")


if __name__ == "__main__":                                      # pragma: no cover
    unittest.main()

# ===== SNAPSMACK EOF =====
