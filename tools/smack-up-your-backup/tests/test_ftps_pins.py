"""FTPS certificate memory (SECAUDIT 004/037 scheduled item, built 2026-09-20).
First connection pins; same cert passes; a publicly-valid change is a renewal
and re-pins silently; any other change stops before the password is sent."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import ftps_pins  # noqa: E402


class _Sock:
    def __init__(self, der):
        self._der = der
    def getpeercert(self, binary_form=False):
        return self._der


class _Ftp:
    def __init__(self, der):
        self.sock = _Sock(der)


class FtpsPinTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.store = ftps_pins.PinStore(os.path.join(self.tmp.name, "pins.json"))
        self.log = []

    def test_first_connection_pins(self):
        fp = ftps_pins.check_connection(_Ftp(b"cert-A"), "host.example", 21, self.store, self.log.append)
        self.assertEqual(fp, ftps_pins.fingerprint(b"cert-A"))
        self.assertEqual(self.store.get("host.example", 21), fp)
        self.assertTrue(any("remembered" in l for l in self.log))

    def test_same_certificate_passes_silently(self):
        ftps_pins.check_connection(_Ftp(b"cert-A"), "host.example", 21, self.store)
        self.log.clear()
        ftps_pins.check_connection(_Ftp(b"cert-A"), "host.example", 21, self.store, self.log.append)
        self.assertEqual(self.log, [])

    def test_publicly_valid_change_is_a_renewal_and_repins(self):
        ftps_pins.check_connection(_Ftp(b"cert-A"), "host.example", 21, self.store)
        with mock.patch.object(ftps_pins, "publicly_valid", return_value=(True, "public CA, name matches")):
            fp = ftps_pins.check_connection(_Ftp(b"cert-B"), "host.example", 21, self.store, self.log.append)
        self.assertEqual(self.store.get("host.example", 21), fp)
        self.assertTrue(any("renewed" in l for l in self.log))

    def test_unverifiable_change_stops_with_both_fingerprints(self):
        ftps_pins.check_connection(_Ftp(b"cert-A"), "host.example", 21, self.store)
        old = self.store.get("host.example", 21)
        with mock.patch.object(ftps_pins, "publicly_valid", return_value=(False, "self signed certificate")), \
                self.assertRaises(ftps_pins.CertificateChanged) as cm:
            ftps_pins.check_connection(_Ftp(b"cert-B"), "host.example", 21, self.store)
        self.assertEqual(cm.exception.old_fp, old)
        self.assertEqual(cm.exception.new_fp, ftps_pins.fingerprint(b"cert-B"))
        self.assertIn(old, str(cm.exception))
        self.assertEqual(self.store.get("host.example", 21), old, "the pin must NOT move on a refused change")

    def test_operator_accept_repins(self):
        ftps_pins.check_connection(_Ftp(b"cert-A"), "host.example", 21, self.store)
        new_fp = ftps_pins.fingerprint(b"cert-B")
        ftps_pins.accept_change("host.example", 21, new_fp, self.store)
        self.assertEqual(self.store.get("host.example", 21), new_fp)

    def test_pins_are_per_host_and_port(self):
        ftps_pins.check_connection(_Ftp(b"cert-A"), "host.example", 21, self.store)
        ftps_pins.check_connection(_Ftp(b"cert-Z"), "host.example", 990, self.store)   # no alarm: different port
        ftps_pins.check_connection(_Ftp(b"cert-Q"), "other.example", 21, self.store)

    def test_client_checks_before_sending_the_password(self):
        """ftp_client.connect(): the pin check runs after auth() and BEFORE login()."""
        src = open(os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "ftp_client.py"),
                   encoding="utf-8").read()
        i_auth = src.index("ftp.auth()")
        i_check = src.index("ftps_pins.check_connection(")
        i_login = src.index("ftp.login(self.user, self.password)")
        self.assertTrue(i_auth < i_check < i_login)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
