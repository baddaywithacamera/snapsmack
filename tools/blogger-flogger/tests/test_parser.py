"""Parser and hostile-archive regression tests."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import io
import tempfile
import unittest
import zipfile
from pathlib import Path

from blogger_flogger.archive_reader import ArchiveRefused, discover_archives, read_candidate
from blogger_flogger.atom_parser import FeedRefused, parse_feed
from blogger_flogger.media_resolver import original_quality_url


FEED = b'''<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"
 xmlns:app="http://purl.org/atom/app#"
 xmlns:thr="http://purl.org/syndication/thread/1.0">
 <id>tag:blogger.com,1999:blog-42</id><title>Old Light</title>
 <link rel="alternate" href="https://old-light.blogspot.com/"/>
 <entry><id>tag:blogger.com,1999:blog-42.post-7</id>
  <category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/>
  <category scheme="http://www.blogger.com/atom/ns#" term="night photography"/>
  <title>Blue hour</title><published>2018-01-02T03:04:05Z</published><updated>2018-01-03T03:04:05Z</updated>
  <author><name>Ada</name><email>ada@example.test</email></author>
  <content type="html">&lt;p&gt;Hello&lt;/p&gt;&lt;img src="https://blogger.googleusercontent.com/a.jpg" alt="Moon" /&gt;</content>
  <link rel="alternate" href="https://old-light.blogspot.com/2018/01/blue-hour.html"/>
 </entry>
 <entry><id>tag:blogger.com,1999:blog-42.page-8</id>
  <category term="http://schemas.google.com/blogger/2008/kind#page"/><title>About</title>
  <app:control><app:draft>yes</app:draft></app:control><content type="html">About us</content>
 </entry>
 <entry><id>tag:blogger.com,1999:blog-42.comment-9</id>
  <category term="http://schemas.google.com/blogger/2008/kind#comment"/><content type="html">Lovely.</content>
  <thr:in-reply-to ref="tag:blogger.com,1999:blog-42.post-7"/>
 </entry>
</feed>'''


class ParserTests(unittest.TestCase):
    def test_normalizes_posts_pages_comments_labels_and_media(self):
        blog = parse_feed(FEED)
        self.assertEqual(blog.title, "Old Light")
        self.assertEqual(blog.counts(), {"post": 1, "page": 1, "comment": 1, "unknown": 0})
        post, page, comment = blog.entries
        self.assertEqual(post.labels, ("night photography",))
        self.assertEqual(post.media[0].alt, "Moon")
        self.assertEqual(post.author_name, "Ada")
        self.assertEqual(page.status, "draft")
        self.assertEqual(comment.parent_id, post.source_id)
        self.assertEqual(len(post.source_sha256), 64)

    def test_refuses_entity_declarations(self):
        with self.assertRaises(FeedRefused):
            parse_feed(b'<!DOCTYPE feed [<!ENTITY x "boom">]><feed>&x;</feed>')

    def test_refuses_non_feed_xml(self):
        with self.assertRaises(FeedRefused):
            parse_feed(b'<rss/>')


class ArchiveTests(unittest.TestCase):
    def test_reads_feed_directly_and_inside_takeout_zip(self):
        with tempfile.TemporaryDirectory() as td:
            direct = Path(td, "feed.atom"); direct.write_bytes(FEED)
            found = discover_archives(direct)
            self.assertEqual(read_candidate(found[0]), FEED)
            package = Path(td, "takeout.zip")
            with zipfile.ZipFile(package, "w") as zf:
                zf.writestr("Takeout/Blogger/Blogs/Old Light/feed.atom", FEED)
            found = discover_archives(package)
            self.assertEqual(len(found), 1)
            self.assertEqual(parse_feed(read_candidate(found[0])).title, "Old Light")

    def test_refuses_zip_traversal(self):
        with tempfile.TemporaryDirectory() as td:
            package = Path(td, "bad.zip")
            with zipfile.ZipFile(package, "w") as zf:
                zf.writestr("../feed.atom", FEED)
            with self.assertRaises(ArchiveRefused):
                discover_archives(package)


class MediaTests(unittest.TestCase):
    def test_requests_original_blogger_image_instead_of_feed_resize(self):
        self.assertEqual(
            original_quality_url("https://blogger.googleusercontent.com/img/a/AVvXs/test/w640-h360/photo.jpg"),
            "https://blogger.googleusercontent.com/img/a/AVvXs/test/s0/photo.jpg")
        self.assertEqual(
            original_quality_url("https://1.bp.blogspot.com/-x/y/s320-c/photo.jpg"),
            "https://1.bp.blogspot.com/-x/y/s0/photo.jpg")

    def test_leaves_non_blogger_cdn_urls_untouched(self):
        url = "https://images.example.test/w640/photo.jpg"
        self.assertEqual(original_quality_url(url), url)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
