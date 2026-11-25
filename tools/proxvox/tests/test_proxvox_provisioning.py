"""PROX VOX single-paste provisioning script contract.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import hashlib
import os
import re
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
            "host": "0ac1ef6c3642500976a62ebb213bab37244e6016",
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

# ===== SNAPSMACK EOF =====
