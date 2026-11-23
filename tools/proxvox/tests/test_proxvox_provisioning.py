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


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
