"""The publish gate: SYBU must not hand back a download link it has not proven.

Item A of the PM's 22:39 ruling. upload() used to build the URL as a string and
return it unfetched, so a grant that silently did not take, Drive's large-file
confirmation page and the download-quota page (both HTML with status 200) all
produced a link that looked fine and published.

What makes these tests worth anything: the fake Drive service RAISES on any call
the gate is not supposed to make, and the end-to-end case asserts that
session.post was never called — so a regression shows up as a published post,
which is the thing that actually matters.
"""

import hashlib
import os
import sys
import tempfile
import unittest
from unittest import mock

TOOL = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, TOOL)

import drive

JPEG = b"\xff\xd8\xff" + b"\x00" * 509          # passes check_bytes, 512 bytes
HTML = b"<!DOCTYPE html><html><body>Too many users have viewed...</body></html>"


class FakeResponse:
    def __init__(self, status=200, ctype="image/jpeg", body=JPEG):
        self.status_code = status
        self.headers = {"Content-Type": ctype}
        self._body = body

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def iter_content(self, n):
        for i in range(0, len(self._body), n):
            yield self._body[i:i + n]


class FakeDrive:
    """A Drive service that records what was asked of it.

    perm_exc / delete_exc make the failure paths real rather than mocked away.
    """

    def __init__(self, *, perms=(("anyone", "reader"),),
                 perm_exc=None, delete_exc=None, list_exc=None):
        self._perms = list(perms)
        self._perm_exc = perm_exc
        self._delete_exc = delete_exc
        self._list_exc = list_exc
        self.deleted = []
        self.perm_retries = []

    # -- files() ----------------------------------------------------------
    def files(self):
        return self

    def create(self, **kw):
        if "body" in kw and kw["body"].get("type"):
            return self._PermCreate(self)
        return self._Exec({"id": "FILEID"})

    def delete(self, fileId=None):
        return self._Delete(self, fileId)

    def update(self, **kw):
        raise AssertionError("the gate must not rename or update the file")

    # -- permissions() ----------------------------------------------------
    def permissions(self):
        return self._Perms(self)

    class _Perms:
        def __init__(self, outer):
            self.outer = outer

        def create(self, fileId=None, body=None):
            return FakeDrive._PermCreate(self.outer)

        def list(self, fileId=None, fields=None):
            return FakeDrive._PermList(self.outer)

    class _Exec:
        def __init__(self, result):
            self.result = result

        def execute(self, num_retries=0):
            return self.result

    class _PermCreate:
        def __init__(self, outer):
            self.outer = outer

        def execute(self, num_retries=0):
            self.outer.perm_retries.append(num_retries)
            if self.outer._perm_exc:
                raise self.outer._perm_exc
            return {"id": "PERMID"}

    class _PermList:
        def __init__(self, outer):
            self.outer = outer

        def execute(self, num_retries=0):
            if self.outer._list_exc:
                raise self.outer._list_exc
            return {"permissions": [{"type": t, "role": r}
                                    for t, r in self.outer._perms]}

    class _Delete:
        def __init__(self, outer, file_id):
            self.outer = outer
            self.file_id = file_id

        def execute(self, num_retries=0):
            if self.outer._delete_exc:
                raise self.outer._delete_exc
            self.outer.deleted.append(self.file_id)
            return {}


class GateTests(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.src = os.path.join(self.dir, "texture.jpg")
        with open(self.src, "wb") as fh:
            fh.write(JPEG)
        # The upload itself is not under test; MediaFileUpload wants a real file.
        self.media = mock.patch.object(drive, "MediaFileUpload", mock.Mock())
        self.media.start()
        self.sleep = mock.patch("time.sleep", lambda *_: None)
        self.sleep.start()

    def tearDown(self):
        self.media.stop()
        self.sleep.stop()

    def _upload(self, svc, response):
        with mock.patch("requests.get", return_value=response):
            return drive.upload(service=svc, file_path=self.src,
                                filename="texture.jpg")

    # ---------------------------------------------------------------- pass
    def test_a_proven_link_is_returned_and_nothing_is_discarded(self):
        svc = FakeDrive()
        url = self._upload(svc, FakeResponse())
        self.assertIn("FILEID", url)
        self.assertEqual([], svc.deleted, "a passing upload must keep the file")

    # ---------------------------------------------------------------- traps
    def test_the_quota_page_is_refused_even_though_it_is_a_200(self):
        svc = FakeDrive()
        with self.assertRaises(RuntimeError) as cm:
            self._upload(svc, FakeResponse(ctype="text/html", body=HTML))
        msg = str(cm.exception)
        self.assertIn("texture.jpg", msg, "the failure must name the file")
        self.assertIn("FAILED", msg)
        self.assertEqual(["FILEID"], svc.deleted, "the orphan must be discarded")

    def test_a_non_200_is_refused(self):
        svc = FakeDrive()
        with self.assertRaises(RuntimeError) as cm:
            self._upload(svc, FakeResponse(status=403))
        self.assertIn("texture.jpg", str(cm.exception))
        self.assertEqual(["FILEID"], svc.deleted)

    def test_bytes_that_are_not_an_image_are_refused(self):
        svc = FakeDrive()
        with self.assertRaises(RuntimeError) as cm:
            self._upload(svc, FakeResponse(body=b"this is not an image at all"))
        self.assertIn("texture.jpg", str(cm.exception))

    def test_a_different_file_is_refused_on_size(self):
        svc = FakeDrive()
        with self.assertRaises(RuntimeError) as cm:
            self._upload(svc, FakeResponse(body=JPEG + b"\x00" * 64))
        self.assertIn("texture.jpg", str(cm.exception))
        self.assertEqual(["FILEID"], svc.deleted)

    # ---------------------------------------------------------- permissions
    def test_an_unshared_file_is_refused_even_when_the_bytes_are_right(self):
        svc = FakeDrive(perms=())          # create succeeded, grant is absent
        with self.assertRaises(RuntimeError) as cm:
            self._upload(svc, FakeResponse())
        self.assertIn("texture.jpg", str(cm.exception))
        self.assertEqual(["FILEID"], svc.deleted)

    def test_an_unreadable_permission_list_is_not_a_pass(self):
        svc = FakeDrive(list_exc=RuntimeError("scope missing"))
        with self.assertRaises(RuntimeError):
            self._upload(svc, FakeResponse())

    def test_a_permission_failure_discards_the_orphan(self):
        svc = FakeDrive(perm_exc=RuntimeError("rate limited"))
        with self.assertRaises(RuntimeError) as cm:
            self._upload(svc, FakeResponse())
        self.assertEqual(["FILEID"], svc.deleted)
        self.assertIn("nothing was published", str(cm.exception))

    def test_when_the_orphan_cannot_be_removed_the_owner_is_told(self):
        svc = FakeDrive(perm_exc=RuntimeError("rate limited"),
                        delete_exc=RuntimeError("no delete scope"))
        with self.assertRaises(RuntimeError) as cm:
            self._upload(svc, FakeResponse())
        msg = str(cm.exception)
        self.assertIn("still in Drive", msg)
        self.assertIn("texture.jpg", msg)
        self.assertEqual([], svc.deleted)

    def test_the_permission_call_retries_a_bounded_number_of_times(self):
        self.assertNotEqual(0, drive._PERM_RETRIES,
                            "a transient hiccup must not silently lose the grant")
        self.assertLessEqual(drive._PERM_RETRIES, 3,
                             "an unbounded retry hangs the GUI for minutes")
        svc = FakeDrive()
        self._upload(svc, FakeResponse())
        self.assertEqual([drive._PERM_RETRIES], svc.perm_retries)

    # -------------------------------------------- one guard at a time
    # The cases above are caught by several guards at once, so removing any one
    # of them still fails the test and proves nothing about that guard. These
    # three strip the overlap: each leaves exactly one check able to fire.

    def test_an_html_content_type_is_refused_on_its_own(self):
        """Content-type alone must reject, with image bytes behind it.

        Both the content-type guard and check_bytes catch a real quota page, so
        this hands the probe a body that WOULD pass validation and leaves only
        the content-type able to object.
        """
        with mock.patch("requests.get",
                        return_value=FakeResponse(ctype="text/html", body=JPEG)):
            out = drive.probe_public_download("https://x.test/f", self.src)
        self.assertFalse(out["ok"])
        self.assertEqual("html", out["reason"])

    def test_a_size_mismatch_is_reported_as_a_size_mismatch(self):
        """The size check exists for its message; md5 would catch this anyway.

        Asserting the REASON, not just the refusal, is what makes the guard
        observable: without it this comes back as md5_mismatch.
        """
        with mock.patch("requests.get",
                        return_value=FakeResponse(body=JPEG + b"\x00" * 64)):
            out = drive.probe_public_download("https://x.test/f", self.src)
        self.assertFalse(out["ok"])
        self.assertEqual("size_mismatch", out["reason"])

    def test_junk_bytes_are_refused_when_there_is_nothing_to_compare(self):
        """No local file and no expected hash, so only check_bytes can refuse.

        This is the case that matters for an existing post being re-probed,
        where the original may not be to hand.
        """
        with mock.patch("requests.get",
                        return_value=FakeResponse(body=b"x" * 512)):
            out = drive.probe_public_download("https://x.test/f", "")
        self.assertFalse(out["ok"])
        self.assertEqual("not_image", out["reason"])

    # ------------------------------------------------------------ fail closed
    def test_a_missing_imgsafe_module_refuses_rather_than_passes(self):
        with mock.patch.object(drive, "_imgsafe", lambda: None):
            out = drive.probe_public_download("https://x.test/f", self.src)
        self.assertFalse(out["ok"])
        self.assertEqual("no_imgsafe", out["reason"])

    def test_an_empty_url_is_refused(self):
        out = drive.probe_public_download("", self.src)
        self.assertFalse(out["ok"])
        self.assertEqual("no_url", out["reason"])

    def test_an_md5_mismatch_is_caught_when_drive_supplies_the_hash(self):
        with mock.patch("requests.get", return_value=FakeResponse()):
            out = drive.probe_public_download(
                "https://x.test/f", self.src,
                expected_md5=hashlib.md5(b"something else").hexdigest())
        self.assertFalse(out["ok"])
        self.assertEqual("md5_mismatch", out["reason"])

    def test_the_probe_sends_no_credentials(self):
        """A link that only works because the owner is signed in is not shared."""
        seen = {}

        def fake_get(url, **kw):
            seen.update(kw)
            return FakeResponse()

        with mock.patch("requests.get", fake_get):
            drive.probe_public_download("https://x.test/f", self.src)
        self.assertEqual({}, seen.get("cookies"),
                         "the probe must not carry cookies")
        self.assertNotIn("headers", seen,
                         "the probe must not carry an auth header")


class NothingIsPublishedTests(unittest.TestCase):
    """The gate only matters if a refusal stops the post. This proves it does."""

    def test_a_failed_probe_means_the_site_is_never_posted_to(self):
        import poster

        client = poster.SnapSmackClient("https://example.test", "key")
        client.session.post = mock.Mock()

        with mock.patch.object(
                poster, "drive_module",
                mock.Mock(upload=mock.Mock(side_effect=RuntimeError(
                    '"texture.jpg" FAILED the anonymous download check'))),
                create=True):
            result = poster.post_gram(
                conn=mock.Mock(session=client.session,
                               site_url="https://example.test"),
                entry=mock.Mock(file="texture.jpg", title="t", tags=""),
                image_folder=tempfile.mkdtemp(),
                drive_service=mock.Mock(),
                drive_folder_id="",
                download_link_required=True,
            )

        self.assertFalse(getattr(result, "success", True))
        self.assertFalse(client.session.post.called,
                         "a post was published despite a failed download check")


if __name__ == "__main__":
    unittest.main()
