"""SECAUDIT 054 item 8 — every backup package is signed; restore verifies first."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import json
import os
import sys
import tempfile
import unittest
import zipfile
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import backup_signing  # noqa: E402
import restore_engine  # noqa: E402


class _KeyInTemp(unittest.TestCase):
    """Every test gets its own signing key in a temp folder — never the real one."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.key_path = os.path.join(self.tmp.name, "backup-signing.key")
        p = mock.patch.object(backup_signing, "_key_path", lambda: self.key_path)
        p.start()
        self.addCleanup(p.stop)
        v = mock.patch.object(backup_signing, "_vault", lambda: None)
        v.start()
        self.addCleanup(v.stop)

    def _make_zip(self, name="b.zip", members=(("kit.tar.gz", b"kit"), ("img_uploads/a.jpg", b"jpeg"))):
        path = os.path.join(self.tmp.name, name)
        with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as zf:
            for arc, data in members:
                zf.writestr(arc, data)
        return path


class SigningTests(_KeyInTemp):
    def test_key_created_once_and_reused(self):
        self.assertFalse(os.path.exists(self.key_path))
        k1 = backup_signing.signing_key()
        self.assertEqual(len(k1), 32)
        self.assertTrue(os.path.exists(self.key_path))
        self.assertEqual(backup_signing.signing_key(), k1)

    def test_sign_then_verify(self):
        z = self._make_zip()
        members = backup_signing.sign_zip(z)
        self.assertEqual(set(members), {"kit.tar.gz", "img_uploads/a.jpg"})
        with zipfile.ZipFile(z) as zf:
            self.assertIn(backup_signing.SIGNATURE_MEMBER, zf.namelist())
            status, detail = backup_signing.verify_zip(zf)
        self.assertEqual(status, "verified")

    def test_unsigned_package_reported_not_refused(self):
        z = self._make_zip()
        with zipfile.ZipFile(z) as zf:
            status, _ = backup_signing.verify_zip(zf)
        self.assertEqual(status, "unsigned")

    def test_altered_member_refused(self):
        z = self._make_zip()
        backup_signing.sign_zip(z)
        # rebuild the zip with one member changed, signature carried over
        with zipfile.ZipFile(z) as zf:
            sig = zf.read(backup_signing.SIGNATURE_MEMBER)
        z2 = self._make_zip("b2.zip", (("kit.tar.gz", b"kit"), ("img_uploads/a.jpg", b"TAMPERED")))
        with zipfile.ZipFile(z2, "a") as zf:
            zf.writestr(backup_signing.SIGNATURE_MEMBER, sig)
        with zipfile.ZipFile(z2) as zf, self.assertRaises(backup_signing.SignatureError) as cm:
            backup_signing.verify_zip(zf)
        self.assertIn("changed", str(cm.exception))

    def test_added_member_refused(self):
        z = self._make_zip()
        backup_signing.sign_zip(z)
        with zipfile.ZipFile(z, "a") as zf:
            zf.writestr("exit/dropper.php", b"<?php")
        with zipfile.ZipFile(z) as zf, self.assertRaises(backup_signing.SignatureError):
            backup_signing.verify_zip(zf)

    def test_forged_signature_with_other_key_refused(self):
        z = self._make_zip()
        backup_signing.sign_zip(z)
        # now "another machine": a different key
        os.remove(self.key_path)
        backup_signing.signing_key()   # fresh, different key
        with zipfile.ZipFile(z) as zf, self.assertRaises(backup_signing.SignatureError):
            backup_signing.verify_zip(zf)

    def test_signed_package_but_no_local_key_refused_with_plain_reason(self):
        z = self._make_zip()
        backup_signing.sign_zip(z)
        os.remove(self.key_path)
        with zipfile.ZipFile(z) as zf, self.assertRaises(backup_signing.SignatureError) as cm:
            backup_signing.verify_zip(zf)
        self.assertIn("different SUYB installation", str(cm.exception))

    def test_hmac_tampered_refused(self):
        z = self._make_zip()
        backup_signing.sign_zip(z)
        with zipfile.ZipFile(z) as zf:
            sig = json.loads(zf.read(backup_signing.SIGNATURE_MEMBER))
        sig["hmac"] = "0" * 64
        z2 = self._make_zip("b3.zip")
        with zipfile.ZipFile(z2, "a") as zf:
            zf.writestr(backup_signing.SIGNATURE_MEMBER, json.dumps(sig))
        with zipfile.ZipFile(z2) as zf, self.assertRaises(backup_signing.SignatureError):
            backup_signing.verify_zip(zf)


class RestoreGateTests(_KeyInTemp):
    def _engine(self, on_ask=None):
        e = restore_engine.RestoreEngine.__new__(restore_engine.RestoreEngine)
        e.on_progress = lambda *a: None
        e.on_log = lambda *a: None
        e._cancelled = False
        e.on_ask = on_ask
        import threading
        e._prompt_event = threading.Event()
        e._prompt_continue = False
        return e

    def test_bad_signature_refused_before_extraction(self):
        z = self._make_zip()
        backup_signing.sign_zip(z)
        with zipfile.ZipFile(z, "a") as zf:
            zf.writestr("extra.bin", b"x")
        e = self._engine(on_ask=lambda m: True)
        with mock.patch.object(restore_engine, "extract_zip_bounded") as ex:
            result = e.restore_from_zip(z)
        self.assertFalse(result["success"])
        self.assertTrue(any("REFUSED" in err for err in result["errors"]))
        ex.assert_not_called()

    def test_unsigned_refused_headless(self):
        z = self._make_zip()
        e = self._engine(on_ask=None)
        with mock.patch.object(restore_engine, "extract_zip_bounded") as ex:
            result = e.restore_from_zip(z)
        self.assertFalse(result["success"])
        ex.assert_not_called()

    def test_unsigned_proceeds_when_operator_says_yes(self):
        z = self._make_zip()
        e = self._engine(on_ask=lambda m: True)
        with mock.patch.object(restore_engine, "extract_zip_bounded") as ex, \
                mock.patch.object(e, "restore_from_kit", lambda k, m: {"success": True}):
            ex.side_effect = lambda zf, d: open(os.path.join(d, "kit.tar.gz"), "wb").close()
            result = e.restore_from_zip(z)
        self.assertTrue(result["success"])
        ex.assert_called_once()

    def test_signed_and_verified_proceeds(self):
        z = self._make_zip()
        backup_signing.sign_zip(z)
        e = self._engine(on_ask=None)   # no question needed
        with mock.patch.object(restore_engine, "extract_zip_bounded") as ex, \
                mock.patch.object(e, "restore_from_kit", lambda k, m: {"success": True}):
            ex.side_effect = lambda zf, d: open(os.path.join(d, "kit.tar.gz"), "wb").close()
            result = e.restore_from_zip(z)
        self.assertTrue(result["success"])


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
