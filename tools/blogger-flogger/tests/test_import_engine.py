"""Importer reconciliation and resume tests."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import tempfile
import unittest
from pathlib import Path

from blogger_flogger.import_engine import ImportEngine
from blogger_flogger.job_store import JobStore
from blogger_flogger.source_model import SourceBlog, SourceEntry


class FakeClient:
    def __init__(self):
        self.maps = {}; self.created = []

    def preflight(self):
        return {"compatible": True, "import_authorized": True}

    def lookup(self, site, kind, source_id):
        found = self.maps.get((site, kind, source_id))
        return {"found": bool(found), "mapping": found}

    def _create(self, body, kind, key):
        new_id = len(self.created) + 100
        self.created.append((kind, body))
        self.maps[(body["source_site_id"], body["source_type"], body["source_id"])] = {
            "destination_type": kind, "destination_id": new_id}
        return {key: new_id}

    def create_post(self, body): return self._create(body, "post", "post_id")
    def create_page(self, body): return self._create(body, "page", "page_id")
    def create_comment(self, body): return self._create(body, "comment", "comment_id")
    def verify(self, kind, destination_id): return {"record": {"id": destination_id}}


class EngineTests(unittest.TestCase):
    def test_posts_pages_comments_import_once_and_rerun_is_empty(self):
        post = SourceEntry("p1", "post", "Post", "Body", "2020-01-02T03:04:05Z", "",
                           "published", "https://x.blogspot.com/2020/01/post.html",
                           labels=("light",), source_sha256="a" * 64)
        page = SourceEntry("pg1", "page", "About", "About", "", "", "published", "",
                           source_sha256="b" * 64)
        comment = SourceEntry("c1", "comment", "", "Nice", "2020-01-03T00:00:00Z", "",
                              "published", "", author_name="Ada", parent_id="p1",
                              source_sha256="c" * 64)
        blog = SourceBlog("blog-1", "Blog", "https://x.blogspot.com", (post, page, comment))
        with tempfile.TemporaryDirectory() as td:
            store = JobStore(Path(td, "job.sqlite")); client = FakeClient()
            first = ImportEngine(blog, client, store, fetch_media=False).run()
            self.assertEqual(first["summary"], {"verified": 3})
            self.assertEqual([kind for kind, _ in client.created], ["post", "page", "comment"])
            self.assertEqual(client.created[0][1]["status"], "draft")
            ImportEngine(blog, client, store, fetch_media=False).run()
            self.assertEqual(len(client.created), 3)
            store.close()

    def test_incompatible_destination_fails_before_writes(self):
        blog = SourceBlog("b", "B", "", ())
        client = FakeClient(); client.preflight = lambda: {"compatible": False, "import_authorized": True}
        with tempfile.TemporaryDirectory() as td:
            store = JobStore(Path(td, "j.sqlite"))
            with self.assertRaises(RuntimeError):
                ImportEngine(blog, client, store).run()
            self.assertEqual(client.created, []); store.close()


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
