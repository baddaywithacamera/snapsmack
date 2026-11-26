"""PROX VOX single-paste provisioning script contract.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import hashlib
import os
import re
import subprocess
import tempfile
import unittest


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SERVER = os.path.join(ROOT, "server")


def _source(name):
    with open(os.path.join(SERVER, name), encoding="utf-8") as stream:
        return stream.read()


class ProvisioningScriptTests(unittest.TestCase):
    def test_each_target_has_one_fail_closed_password_only_installer(self):
        for target in ("ct102", "ct106", "ct110", "host"):
            with self.subTest(target=target):
                source = _source("provision-%s.sh" % target)
                self.assertTrue(source.startswith("#!/bin/bash\n"))
                self.assertIn("set -eu", source)
                self.assertIn("read -r -s password", source)
                self.assertIn("password cannot be empty", source)
                self.assertNotIn("TYPE-A-FRESH-PASSWORD", source)
                self.assertTrue(source.rstrip().endswith("# ===== SNAPSMACK EOF ====="))

    def test_container_scripts_create_the_read_only_sftp_boundary(self):
        for target in ("ct102", "ct106"):
            with self.subTest(target=target):
                source = _source("provision-%s.sh" % target)
                self.assertIn("useradd --system --no-create-home --shell /usr/sbin/nologin proxvox-ro", source)
                self.assertIn("setfacl -R -m u:proxvox-ro:rX", source)
                self.assertIn("ForceCommand internal-sftp -R", source)
                self.assertIn("AllowTcpForwarding local", source)
                self.assertIn("PermitOpen 192.168.1.20:3306", source)
                self.assertIn('"$sshd_path" -t', source)
                self.assertIn("systemctl reload ssh", source)

    def test_each_container_uses_its_real_web_root(self):
        ct102 = _source("provision-ct102.sh")
        ct106 = _source("provision-ct106.sh")
        self.assertIn("for path in /var/www/sites /var/log/apache2", ct102)
        self.assertIn("for path in /var/www /var/log/apache2", ct106)
        self.assertNotIn("for path in /var/www/sites /var/log/apache2", ct106)

    def test_database_script_creates_only_source_bound_readers(self):
        source = _source("provision-ct110.sh")
        for address in ("192.168.1.12", "192.168.1.16"):
            self.assertIn("'proxvox_ro'@'%s'" % address, source)
        self.assertIn("GRANT SELECT, SHOW VIEW ON *.*", source)
        for privilege in ("INSERT", "UPDATE", "DELETE", "CREATE", "DROP", "GRANT OPTION"):
            self.assertNotRegex(source, r"GRANT[^\n]*\b%s\b" % privilege)

    def test_host_installer_pins_and_verifies_the_reviewed_wrapper(self):
        source = _source("provision-host.sh")
        wrapper = os.path.join(SERVER, "proxvox_readonly.py")
        with open(wrapper, "rb") as stream:
            digest = hashlib.sha256(stream.read()).hexdigest()
        declared = re.search(r"^WRAPPER_SHA256=([0-9a-f]{64})$", source, re.MULTILINE)
        self.assertIsNotNone(declared)
        self.assertEqual(declared.group(1), digest)
        self.assertRegex(source, re.compile(r"^WRAPPER_COMMIT=[0-9a-f]{40}$", re.MULTILINE))
        self.assertIn("raw.githubusercontent.com/baddaywithacamera/snapsmack", source)
        self.assertIn("sha256sum --check --status", source)
        self.assertIn("visudo -cf", source)
        self.assertIn("ForceCommand sudo -n /usr/local/sbin/proxvox-readonly", source)
        self.assertIn("AllowTcpForwarding no", source)

    def test_setup_sheet_is_four_verified_pastes_and_codex_runs_proofs(self):
        with open(os.path.join(ROOT, "PROVISIONING.md"), encoding="utf-8") as stream:
            sheet = stream.read()
        commits = {
            "ct102": "055966363c8bf298cda749a09b4f83126e34166d",
            "ct106": "055966363c8bf298cda749a09b4f83126e34166d",
            "ct110": "8fd93087f260b0c44ced5a213a76ba7050bf392f",
            "host": "cd71c3c8a3648ede0a019c6c9da2cfc7e474a291",
        }
        commands = re.findall(r"```sh\n([^\n]+)\n```", sheet)
        self.assertEqual(len(commands), 4)
        targets = ("ct102", "ct106", "ct110", "host")
        for target, command in zip(targets, commands):
            with self.subTest(target=target):
                script_name = "provision-%s.sh" % target
                script_path = os.path.join(SERVER, script_name)
                with open(script_path, "rb") as stream:
                    digest = hashlib.sha256(stream.read()).hexdigest()
                self.assertIn("/%s/tools/proxvox/server/%s" % (commits[target], script_name), command)
                self.assertIn(digest, command)
                self.assertIn("sha256sum -c -", command)
        self.assertIn("Sean's setup work ends there.", sheet)
        self.assertIn("Codex runs and records all proofs", sheet)
        self.assertNotIn("TYPE-A-FRESH-PASSWORD", sheet)
        self.assertTrue(sheet.rstrip().endswith("<!-- ===== SNAPSMACK EOF ===== -->"))


if __name__ == "__main__":
    unittest.main(verbosity=2)


class WriterProvisioner(unittest.TestCase):
    """The scoped writer's installer, and the drift check SS-1574 asked for."""

    def setUp(self):
        self.source = _source("provision-host-writer.sh")

    def test_the_writer_gets_its_own_account_not_the_read_one(self):
        """proxvox-root-ro's name is a promise.

        Adding a write capability to it would mean every existing read key
        could write, with nothing about the account saying so. Revoking write
        access should be deleting one account, not editing a protocol.
        """
        self.assertIn("proxvox-root-rw", self.source)
        self.assertIn("Match User ${WRITER_USER}", self.source)
        self.assertIn("ForceCommand sudo -n ${WRITER_PATH}", self.source)
        # It must not touch the read-only account in any COMMAND. The header
        # names it in prose, explaining why there is a second account, which is
        # the opposite of a problem -- so the check reads the executable lines
        # rather than the whole file.
        commands = [line for line in self.source.splitlines()
                    if line.strip() and not line.strip().startswith("#")]
        for line in commands:
            self.assertNotIn("proxvox-root-ro", line, line)

    def test_the_forced_command_allows_no_tty_and_no_forwarding(self):
        for directive in ("PermitTTY no", "X11Forwarding no",
                          "AllowAgentForwarding no", "AllowTcpForwarding no"):
            self.assertIn(directive, self.source, directive)

    def test_writer_provisioner_uses_the_same_key_only_transport_as_the_client(self):
        for directive in ("AuthenticationMethods publickey", "PasswordAuthentication no",
                          "KbdInteractiveAuthentication no", "DisableForwarding yes",
                          "AuthorizedKeysFile /etc/ssh/authorized_keys/%u"):
            self.assertIn(directive, self.source, directive)
        self.assertNotIn("| chpasswd", self.source)
        self.assertLess(self.source.index('-t -f "$candidate"'),
                        self.source.index('mv -f "$candidate" "$config"'))
        self.assertIn('cp -p "$backup" "$config"', self.source)

    def test_it_pins_a_commit_and_verifies_the_hash_before_installing(self):
        self.assertIn("WRITER_COMMIT=", self.source)
        self.assertIn("WRITER_SHA256=", self.source)
        self.assertIn("sha256sum --check --status", self.source)
        # And it checks again AFTER installing, as the reader does.
        self.assertIn('[ "$installed_sha" = "$WRITER_SHA256" ]', self.source)

    def test_an_unpinned_installer_refuses_to_run(self):
        """An installer with no reviewed revision would fetch whatever is
        newest, which is how an unreviewed wrapper reaches a live host.

        THIS NOW RUNS AGAINST A COPY, because the real file IS pinned. The
        first version ran the real script and asserted it refused, so it
        started failing the moment the pin landed -- and the tempting fix was
        to delete the test, which would have removed the guarantee at exactly
        the moment it began to matter. The behaviour is still protected: the
        placeholders are put back in a copy and the refusal is still executed.
        """
        self.assertIn("no reviewed commit pinned yet", self.source)
        unpinned = re.sub(r"^WRITER_COMMIT=.*$", "WRITER_COMMIT=REPLACE_WITH_REVIEWED_COMMIT",
                          self.source, count=1, flags=re.MULTILINE)
        unpinned = re.sub(r"^WRITER_SHA256=.*$", "WRITER_SHA256=REPLACE_WITH_REVIEWED_SHA256",
                          unpinned, count=1, flags=re.MULTILINE)
        with tempfile.TemporaryDirectory() as work:
            copy = os.path.join(work, "provision-host-writer.sh")
            with open(copy, "w", encoding="utf-8", newline="\n") as stream:
                stream.write(unpinned)
            result = subprocess.run(["bash", copy], capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("no reviewed commit pinned", result.stderr)

    def test_the_pinned_hash_IS_the_writer_at_the_pinned_commit(self):
        """A pin that does not match what it names is worse than no pin.

        It would install bytes nobody reviewed and then report success,
        because the installer compares the fetched file against WRITER_SHA256
        and both came from the same wrong place. So the pinned digest is
        checked against the writer AS COMMITTED at WRITER_COMMIT -- the bytes
        raw.githubusercontent.com serves, which is what lands on the host.

        Committed bytes, not the working tree: git hands a Windows checkout
        CRLF while it stores LF, and that difference has already cost this
        project a release night.
        """
        commit = re.search(r"^WRITER_COMMIT=([0-9a-f]{40})$", self.source, re.MULTILINE)
        digest = re.search(r"^WRITER_SHA256=([0-9a-f]{64})$", self.source, re.MULTILINE)
        self.assertIsNotNone(commit, "WRITER_COMMIT must be a full 40-character sha")
        self.assertIsNotNone(digest, "WRITER_SHA256 must be a 64-character sha256")

        shown = subprocess.run(
            ["git", "show", "%s:tools/proxvox/server/proxvox_writer.py" % commit.group(1)],
            capture_output=True, cwd=os.path.dirname(ROOT))
        if shown.returncode != 0:
            self.skipTest(
                "the pinned commit %s is not in this checkout, so the digest "
                "cannot be verified here. NOT a pass: fetch it and re-run."
                % commit.group(1)[:12])
        measured = hashlib.sha256(shown.stdout).hexdigest()
        self.assertEqual(
            measured, digest.group(1),
            "WRITER_SHA256 does not match proxvox_writer.py at WRITER_COMMIT. "
            "The installer would fetch bytes nobody reviewed and call it a match.")

    def test_verify_refuses_rather_than_comparing_against_a_placeholder(self):
        """A verify that always reports drift is one people learn to ignore,
        which would leave it as useless as the check it replaces.

        Also runs against a placeholder COPY now, for the same reason as the
        install test above.
        """
        unpinned = re.sub(r"^WRITER_SHA256=.*$", "WRITER_SHA256=REPLACE_WITH_REVIEWED_SHA256",
                          self.source, count=1, flags=re.MULTILINE)
        with tempfile.TemporaryDirectory() as work:
            copy = os.path.join(work, "provision-host-writer.sh")
            with open(copy, "w", encoding="utf-8", newline="\n") as stream:
                stream.write(unpinned)
            result = subprocess.run(["bash", copy, "verify"], capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("nothing to verify against", result.stderr)

    def test_verify_mode_exists_and_changes_nothing(self):
        """THE POINT OF THIS FILE, and it comes straight out of SS-1574.

        The read-only provisioner checks the installed checksum AT INSTALL TIME
        and never again, so the live host ran a wrapper whose find format held
        a real NUL byte, every directory listing failed, and it was found by
        accident weeks later. verify compares installed against pinned at any
        time and says so.
        """
        self.assertIn('if [ "${1-}" = "verify" ]', self.source)
        self.assertIn("PROX VOX WRITER DRIFT", self.source)
        # No install verb may appear before the verify branch returns.
        head = self.source.split('if [ "${1-}" = "verify" ]')[1].split("fi")[0]
        for verb in ("install -o", "useradd", "chpasswd", "visudo", "curl"):
            self.assertNotIn(verb, head, "verify must not " + verb)

    def test_the_installer_is_valid_shell(self):
        result = subprocess.run(["bash", "-n", os.path.join(SERVER, "provision-host-writer.sh")],
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)

# ===== SNAPSMACK EOF =====
