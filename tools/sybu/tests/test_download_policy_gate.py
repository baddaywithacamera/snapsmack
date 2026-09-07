"""Regression coverage for CMS-owned required-download publishing policy."""

import os
import sys
import tempfile
import unittest
from unittest import mock
from PIL import Image

TOOL = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, TOOL)

import poster


class DownloadPolicyGateTests(unittest.TestCase):
    def test_site_data_reads_server_policy(self):
        client = poster.SnapSmackClient("https://example.test", "key")
        client.session.post = mock.Mock()
        response = mock.Mock(status_code=200)
        response.json.return_value = {
            "categories": [], "albums": [], "tags": [], "titles": [],
            "site_mode": "photoblog",
            "publishing_policy": {
                "download_link_required": True,
                "download_default_mode": "all_posts",
            },
        }
        client.session.get = mock.Mock(return_value=response)
        data = client.fetch_site_data()
        self.assertTrue(data.download_link_required)
        self.assertEqual("all_posts", data.download_default_mode)

    def test_required_drive_failure_never_reaches_post_endpoint(self):
        client = poster.SnapSmackClient("https://example.test", "key")
        client.session.post = mock.Mock()
        entry = mock.Mock(file="source.jpg", title="A title", tags="", colors="",
                          category="", album="", orientation="auto")
        with tempfile.TemporaryDirectory() as root:
            Image.new("RGB", (8, 8), "red").save(os.path.join(root, "source.jpg"))
            result = client.post_image(
                entry, root, poster.SiteData(),
                download_link_required=True)
        self.assertFalse(result.success)
        self.assertIn("requires a Drive download link", result.message)
        self.assertFalse(client.session.post.called)

    def test_required_gram_without_drive_never_uploads_to_site(self):
        conn = mock.Mock()
        entry = mock.Mock(file="gram.jpg")
        with tempfile.TemporaryDirectory() as root:
            Image.new("RGB", (8, 8), "blue").save(os.path.join(root, "gram.jpg"))
            result = poster.post_gram(
                conn, entry, root, download_link_required=True)
        self.assertFalse(result.success)
        self.assertIn("requires a Drive download link", result.message)
        self.assertFalse(conn.session.post.called)


if __name__ == "__main__":
    unittest.main()
