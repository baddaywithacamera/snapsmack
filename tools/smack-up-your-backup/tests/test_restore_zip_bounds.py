"""SECAUDIT 058 B — restore never expands a backup ZIP it has not bounded."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import io
import os
import sys
import tempfile
import unittest
import zipfile
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import restore_engine  # noqa: E402


def _zip(members):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        for name, data in members:
            zf.writestr(name, data)
    buf.seek(0)
    return zipfile.ZipFile(buf, "r")


class RestoreZipBoundsTests(unittest.TestCase):
    def test_honest_package_extracts_and_reports_total(self):
        zf = _zip([("kit.tar.gz", b"x" * 100), ("img_uploads/a.jpg", b"jpeg")])
        self.assertEqual(restore_engine.inventory_zip(zf), 104)
        with tempfile.TemporaryDirectory() as d:
            restore_engine.extract_zip_bounded(zf, d)
            self.assertTrue(os.path.isfile(os.path.join(d, "img_uploads", "a.jpg")))

    def test_traversal_name_refused_before_any_write(self):
        zf = _zip([("../../escape.txt", b"x")])
        with self.assertRaises(ValueError):
            restore_engine.inventory_zip(zf)

    def test_absolute_name_refused(self):
        zf = _zip([("/etc/passwd", b"x")])
        with self.assertRaises(ValueError):
            restore_engine.inventory_zip(zf)

    def test_symlink_member_refused(self):
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w") as zf:
            info = zipfile.ZipInfo("link")
            info.external_attr = (0o120777 << 16)
            zf.writestr(info, "target")
        buf.seek(0)
        with self.assertRaises(ValueError):
            restore_engine.inventory_zip(zipfile.ZipFile(buf, "r"))

    def test_decompression_bomb_refused(self):
        zf = _zip([("bomb.bin", b"\0" * (4 * 1024 * 1024))])   # ~4000:1
        with self.assertRaises(ValueError):
            restore_engine.inventory_zip(zf)

    def test_too_many_members_refused(self):
        zf = _zip([("kit.tar.gz", b"x")])
        with mock.patch.object(restore_engine, "ZIP_MAX_MEMBERS", 0), \
                self.assertRaises(ValueError):
            restore_engine.inventory_zip(zf)

    def test_oversize_member_refused(self):
        zf = _zip([("big", b"y" * 10)])
        with mock.patch.object(restore_engine, "ZIP_MAX_MEMBER_BYTES", 5), \
                self.assertRaises(ValueError):
            restore_engine.inventory_zip(zf)

    def test_insufficient_free_space_refused_before_extraction(self):
        zf = _zip([("kit.tar.gz", b"x" * 10)])
        usage = type("U", (), {"free": 10})()
        with tempfile.TemporaryDirectory() as d, \
                mock.patch("shutil.disk_usage", return_value=usage), \
                self.assertRaises(ValueError):
            restore_engine.extract_zip_bounded(zf, d)
        # nothing was written
        with tempfile.TemporaryDirectory() as d:
            with mock.patch("shutil.disk_usage", return_value=usage):
                try:
                    restore_engine.extract_zip_bounded(zf, d)
                except ValueError:
                    pass
            self.assertEqual(os.listdir(d), [])

    def test_member_lying_about_size_aborts(self):
        zf = _zip([("kit.tar.gz", b"x" * 50)])
        info = zf.getinfo("kit.tar.gz")
        info.file_size = 10          # declared smaller than reality
        # zipfile itself stops at the declared size and fails the CRC; our
        # byte counter is the belt behind that. Either way: refused, not written.
        with tempfile.TemporaryDirectory() as d,                 self.assertRaises((ValueError, zipfile.BadZipFile)):
            restore_engine.extract_zip_bounded(zf, d)

    def test_staging_dir_removed_on_failure_and_success(self):
        made = []
        real_mkdtemp = tempfile.mkdtemp

        def spy_mkdtemp(**kw):
            path = real_mkdtemp(**kw)
            made.append(path)
            return path

        engine = restore_engine.RestoreEngine.__new__(restore_engine.RestoreEngine)
        engine.on_progress = lambda *a: None
        engine.on_log = lambda *a: None
        engine._cancelled = False
        # failure path: no kit inside
        with tempfile.TemporaryDirectory() as d:
            zpath = os.path.join(d, "b.zip")
            with zipfile.ZipFile(zpath, "w") as zf:
                zf.writestr("notakit.txt", "x")
            with mock.patch("tempfile.mkdtemp", spy_mkdtemp):
                result = engine.restore_from_zip(zpath)
            self.assertFalse(result["success"])
            self.assertFalse(os.path.exists(made[-1]))
        # success-ish path: kit found, restore_from_kit stubbed
        with tempfile.TemporaryDirectory() as d:
            zpath = os.path.join(d, "b.zip")
            with zipfile.ZipFile(zpath, "w") as zf:
                zf.writestr("kit.tar.gz", "x")
            with mock.patch("tempfile.mkdtemp", spy_mkdtemp), \
                    mock.patch.object(engine, "restore_from_kit",
                                      lambda kit, media: {"success": True}):
                result = engine.restore_from_zip(zpath)
            self.assertTrue(result["success"])
            self.assertFalse(os.path.exists(made[-1]))


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
