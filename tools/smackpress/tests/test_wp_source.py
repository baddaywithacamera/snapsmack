"""SMACKPRESS: a WordPress post becomes a COLD SNAP draft with the pictures
already ours — no <img>, no old-site URL survives in the body (2026-09-21)."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import re
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from smackpress import wp_source  # noqa: E402

# a 1x1 PNG so snap_imgsafe (if present) accepts it
PNG = (b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89"
       b"\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x01\x01\x01\x00\x18\xdd\x8d\xb4\x00\x00\x00\x00IEND\xaeB`\x82")

WP = "https://old-blog.example/wp-content/uploads/2024/05"

POST = {
    "id": 42, "title": "Rust &amp; Chrome", "slug": "rust-and-chrome",
    "date": "2024-05-06T14:22:00", "tags": ["Used Car Parts", "V8"],
    "categories": [{"id": 3, "name": "Engines", "slug": "engines"}],
    "featured_image": {"id": 9, "url": f"{WP}/cover.png", "alt": "the cover", "filename": "cover.png"},
    "images": [
        {"id": 10, "url": f"{WP}/grille.png", "alt": "grille", "filename": "grille.png", "width": 4, "height": 3},
        {"id": 11, "url": f"{WP}/badge.png", "alt": "", "filename": "badge.png"},
    ],
    "content_expanded": (
        "<p>Opening words.</p>\n"
        f'<figure class="wp-block-image"><a href="{WP}/grille.png"><img src="{WP}/grille-1024x768.png" alt="grille" class="wp-image-10"></a>'
        "<figcaption>A grille</figcaption></figure>\n"
        "<p>Middle words.</p>\n"
        f'[smackpress-image id="11" url="{WP}/badge.png"]\n'
        f'<p><img src="https://elsewhere.example/hotlinked.png" alt="borrowed"></p>\n'
        "<p>Closing words.</p>"
    ),
}


def fake_fetch(url, timeout=60):
    if "missing" in url:
        raise OSError("404")
    return PNG


class RewriteTests(unittest.TestCase):
    def test_every_picture_becomes_a_bucket_token_and_nothing_points_back(self):
        body, ordered = wp_source.rewrite_body(POST["content_expanded"], [POST["featured_image"]] + POST["images"])
        self.assertNotIn("<img", body.lower())
        self.assertNotIn("old-blog.example", body)
        self.assertNotIn("elsewhere.example", body)
        self.assertNotIn("figcaption", body.lower())
        # featured=1, grille=2, badge=3, hot-linked=4 — resized variant matched the original
        self.assertEqual([o["url"] for o in ordered],
                         [f"{WP}/cover.png", f"{WP}/grille.png", f"{WP}/badge.png",
                          "https://elsewhere.example/hotlinked.png"])
        self.assertIn("[img:bucket:2]", body)
        self.assertIn("[img:bucket:3]", body)
        self.assertIn("[img:bucket:4]", body)
        self.assertNotIn("[img:bucket:1]", body, "the cover is the featured image, not inline")
        # tokens sit on their own lines so the server's shortcode pass sees them
        for tok in ("[img:bucket:2]", "[img:bucket:3]", "[img:bucket:4]"):
            self.assertRegex(body, r"(^|\n)" + re.escape(tok) + r"(\n|$)")
        self.assertIn("<p>Opening words.</p>", body)
        self.assertIn("<p>Closing words.</p>", body)

    def test_hotlinked_picture_gets_its_alt(self):
        _, ordered = wp_source.rewrite_body(POST["content_expanded"], POST["images"])
        hot = [o for o in ordered if "elsewhere" in o["url"]][0]
        self.assertEqual(hot["alt"], "borrowed")


class DraftTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def test_draft_carries_everything_and_pictures_are_local(self):
        d = wp_source.draft_from_wp(POST, self.tmp.name, fetch=fake_fetch)
        self.assertEqual(d.kind, "smacktalk")
        self.assertEqual(d.title, "Rust & Chrome")
        self.assertEqual(d.slug, "rust-and-chrome")
        self.assertEqual(d.post_date, "2024-05-06 14:22:00")
        self.assertEqual(d.tags, "#usedcarparts #v8")
        self.assertEqual(d.category, "Engines")
        self.assertEqual(d.img_status, "draft")
        self.assertEqual(len(d.images), 4)
        self.assertTrue(all(os.path.isfile(im.local_path) for im in d.images))
        self.assertTrue(all(im.local_path.startswith(self.tmp.name) for im in d.images))
        self.assertEqual(d.images[0].filename, "cover.png", "featured image leads → becomes the cover")
        self.assertEqual(d.images[1].alt, "grille")
        self.assertNotIn("<img", d.caption.lower())
        self.assertNotIn("old-blog.example", d.caption)

    def test_a_missing_picture_fails_the_whole_post_not_half_of_it(self):
        post = dict(POST)
        post["images"] = POST["images"] + [{"id": 99, "url": f"{WP}/missing.png", "filename": "missing.png"}]
        with self.assertRaises(wp_source.ImportError_) as cm:
            wp_source.draft_from_wp(post, self.tmp.name, fetch=fake_fetch)
        self.assertIn("missing.png", str(cm.exception))

    def test_poster_payload_keeps_the_old_slug(self):
        sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "coldsnap"))
        import sumna_post
        d = wp_source.draft_from_wp(POST, self.tmp.name, fetch=fake_fetch)
        poster = sumna_post.SmacktalkPoster.__new__(sumna_post.SmacktalkPoster)
        payload = poster.build_payload(d, [101, 102, 103, 104], 101)
        self.assertEqual(payload["slug"], "rust-and-chrome")
        self.assertEqual(payload["featured_image_id"], 101)
        self.assertEqual(payload["date"], "2024-05-06 14:22:00")
        self.assertEqual(payload["tags"], "usedcarparts v8")
        self.assertEqual(payload["status"], "draft")


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
