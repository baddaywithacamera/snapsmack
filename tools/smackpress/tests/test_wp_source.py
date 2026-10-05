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
    "date": "2024-05-06T08:22:00", "date_gmt": "2024-05-06T14:22:00",
    "link": "https://old-blog.example/rust-and-chrome/", "tags": ["Used Car Parts", "V8"],
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
        self.assertIn("[img:gbucket:2]", body)
        self.assertIn("[img:bucket:3]", body)
        self.assertIn("[img:bucket:4]", body)
        self.assertNotIn("[img:bucket:1]", body, "the cover is the featured image, not inline")
        # tokens sit on their own lines so the server's shortcode pass sees them
        for tok in ("[img:gbucket:2]", "[img:bucket:3]", "[img:bucket:4]"):
            self.assertRegex(body, r"(^|\n)" + re.escape(tok) + r"(\n|$)")
        self.assertIn("<p>Opening words.</p>", body)
        self.assertIn("<p>Closing words.</p>", body)

    def test_media_linked_picture_keeps_lightbox_intent(self):
        content = (
            f'<figure><a href="{WP}/grille.png">'
            f'<img src="{WP}/grille-1024x768.png" alt="grille"></a></figure>'
        )
        body, _ = wp_source.rewrite_body(content, POST["images"])
        self.assertEqual(body, "[img:gbucket:1]")

    def test_unlinked_picture_stays_plain(self):
        content = f'<figure><img src="{WP}/grille.png" alt="grille"></figure>'
        body, _ = wp_source.rewrite_body(content, POST["images"])
        self.assertEqual(body, "[img:bucket:1]")

    def test_hotlinked_picture_gets_its_alt(self):
        _, ordered = wp_source.rewrite_body(POST["content_expanded"], POST["images"])
        hot = [o for o in ordered if "elsewhere" in o["url"]][0]
        self.assertEqual(hot["alt"], "borrowed")

    def test_source_site_links_are_unwrapped_but_their_words_survive(self):
        content = ('<p><a href="https://old-blog.example/old-page/">kept words</a> '
                   '<a href="https://example.net/reference">external reference</a></p>')
        body, _ = wp_source.rewrite_body(content, [])
        body = wp_source._discard_source_links(body, POST["link"])
        self.assertIn("kept words", body)
        self.assertNotIn("old-blog.example", body)
        self.assertIn("https://example.net/reference", body)

    def test_gutenberg_scaffolding_is_not_imported_as_content(self):
        content = (
            '<!-- wp:paragraph {"fontSize":"large"} -->\n'
            '<p class="has-large-font-size">Words worth keeping.</p>\n'
            '<!-- /wp:paragraph -->\n'
            '<!-- wp:columns --><div class="wp-block-columns">'
            '<!-- wp:column --><div class="wp-block-column">'
            f'<figure class="wp-block-image"><img src="{WP}/grille.png"></figure>'
            '</div><!-- /wp:column --></div><!-- /wp:columns -->\n'
            '<!-- wp:spacer --><div style="height:40px" class="wp-block-spacer"></div><!-- /wp:spacer -->'
        )
        body, _ = wp_source.rewrite_body(content, POST["images"])
        self.assertNotIn("<!--", body)
        self.assertNotIn("wp-block", body)
        self.assertNotIn("<div", body)
        self.assertIn('<p class="has-large-font-size">Words worth keeping.</p>', body)
        self.assertIn("[img:bucket:1]", body)

    def test_full_image_plus_two_columns_becomes_three_image_mosaic(self):
        content = (
            '<!-- wp:image --><figure><img src="%s/grille.png"></figure><!-- /wp:image -->'
            '<!-- wp:columns --><div class="wp-block-columns">'
            '<!-- wp:column --><div class="wp-block-column">'
            '<!-- wp:image --><figure><img src="%s/badge.png"></figure><!-- /wp:image -->'
            '</div><!-- /wp:column -->'
            '<!-- wp:column --><div class="wp-block-column">'
            '<!-- wp:image --><figure><img src="%s/third.png"></figure><!-- /wp:image -->'
            '</div><!-- /wp:column --></div><!-- /wp:columns -->'
        ) % (WP, WP, WP)
        body, ordered = wp_source.rewrite_body(content, POST["images"])
        self.assertIn("[mosaic=1,2,3 layout=one-top]", body)
        self.assertNotIn("[img:bucket:", body)
        self.assertEqual(ordered[-1]["url"], f"{WP}/third.png")

    def test_wordpress_derivatives_collapse_to_largest_variant(self):
        images = [
            {"url": f"{WP}/lake-1024x683.jpg", "width": 1024, "height": 683},
            {"url": f"{WP}/lake-scaled.jpg", "width": 2560, "height": 1707},
            {"url": f"{WP}/lake.jpg", "width": 6000, "height": 4000},
        ]
        body, ordered = wp_source.rewrite_body(
            f'<img src="{WP}/lake-1024x683.jpg">', images)
        self.assertEqual(len(ordered), 1)
        self.assertEqual(ordered[0]["url"], f"{WP}/lake.jpg")
        self.assertIn("[img:bucket:1]", body)

    def test_two_columns_plus_following_image_wins_over_preceding_image(self):
        content = (
            '<!-- wp:image --><figure><img src="%s/before.png"></figure><!-- /wp:image -->'
            '<!-- wp:columns --><div class="wp-block-columns">'
            '<!-- wp:column --><div class="wp-block-column">'
            '<!-- wp:image --><figure><img src="%s/grille.png"></figure><!-- /wp:image -->'
            '</div><!-- /wp:column -->'
            '<!-- wp:column --><div class="wp-block-column">'
            '<!-- wp:image --><figure><img src="%s/badge.png"></figure><!-- /wp:image -->'
            '</div><!-- /wp:column --></div><!-- /wp:columns -->'
            '<!-- wp:image --><figure><img src="%s/after.png"></figure><!-- /wp:image -->'
        ) % (WP, WP, WP, WP)
        body, _ = wp_source.rewrite_body(content, POST["images"])
        self.assertIn("[img:bucket:3]", body)
        self.assertIn("[mosaic=1,2,4 layout=one-top]", body)


class DraftTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def test_draft_keeps_creative_work_and_discards_wordpress_structure(self):
        d = wp_source.draft_from_wp(POST, self.tmp.name, fetch=fake_fetch)
        self.assertEqual(d.kind, "smacktalk")
        self.assertEqual(d.title, "Rust & Chrome")
        self.assertEqual(d.slug, "rust-and-chrome")
        self.assertEqual(d.post_date, "2024-05-06T14:22:00Z")
        self.assertEqual(d.tags, "")
        self.assertEqual(d.category, "")
        self.assertEqual(d.img_status, "draft")
        self.assertEqual(len(d.images), 4)
        self.assertTrue(all(os.path.isfile(im.local_path) for im in d.images))
        self.assertTrue(all(im.local_path.startswith(self.tmp.name) for im in d.images))
        self.assertTrue(all(im.original_path == im.local_path for im in d.images))
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

    def test_wordpress_page_keeps_its_identity_and_is_not_a_post(self):
        page = dict(POST)
        page.update({"type": "page", "status": "publish", "slug": "the-idea"})
        d = wp_source.draft_from_wp(page, self.tmp.name, fetch=fake_fetch)
        self.assertEqual(d.destination_type, "page")
        self.assertEqual(d.slug, "the-idea")
        self.assertEqual(d.img_status, "published")
        self.assertEqual(d.colophon, "")

    def test_poster_payload_keeps_date_but_not_wordpress_structure(self):
        sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "coldsnap"))
        import sumna_post
        d = wp_source.draft_from_wp(POST, self.tmp.name, fetch=fake_fetch)
        poster = sumna_post.SmacktalkPoster.__new__(sumna_post.SmacktalkPoster)
        payload = poster.build_payload(d, [101, 102, 103, 104], 101)
        self.assertEqual(payload["slug"], "rust-and-chrome")
        self.assertEqual(payload["featured_image_id"], 101)
        self.assertEqual(payload["date"], "2024-05-06T14:22:00Z")
        self.assertEqual(payload["tags"], "")
        self.assertEqual(payload["cat_ids"], [])
        self.assertEqual(payload["album_ids"], [])
        self.assertNotIn("wp-content", repr(payload))
        self.assertNotIn("old-blog.example", repr(payload))
        self.assertEqual(payload["status"], "draft")

    def test_wordpress_ephemera_becomes_explicit_post_data(self):
        sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "coldsnap"))
        import sumna_post
        post = dict(POST)
        post["images"] = POST["images"] + [{
            "id": 12, "url": f"{WP}/sean-mccormick-black-low-res.png",
            "filename": "sean-mccormick-black-low-res.png", "alt": "Sean's signature",
        }]
        post["content_expanded"] = (
            "<p>The essay ends here.</p>"
            f'<figure><img src="{WP}/sean-mccormick-black-low-res.png" alt="Sean\'s signature"></figure>'
            "<p>Images made with a Canon EOS R5, a Helios lens, and a DJI drone.</p>"
        )
        d = wp_source.draft_from_wp(
            post, self.tmp.name, fetch=fake_fetch,
            profile={"signature_markers": ["sean-mccormick-black-low-res"]},
        )
        self.assertEqual(d.caption, "<p>The essay ends here.</p>")
        self.assertIn("Canon EOS R5", d.colophon)
        self.assertEqual(sum(1 for image in d.images if image.is_signature), 1)
        poster = sumna_post.SmacktalkPoster.__new__(sumna_post.SmacktalkPoster)
        payload = poster.build_payload(d, list(range(101, 101 + len(d.images))), 101)
        signature_position = next(i for i, image in enumerate(d.images) if image.is_signature)
        self.assertEqual(payload["signature_image_id"], 101 + signature_position)
        self.assertIn("Canon EOS R5", payload["colophon"])

    def test_source_profile_can_describe_another_sites_recurring_chrome(self):
        post = dict(POST)
        post["images"] = POST["images"] + [{
            "id": 13, "url": f"{WP}/closing-flourish.png",
            "filename": "closing-flourish.png", "alt": "the author's mark",
        }]
        post["content_expanded"] = (
            "<p>The authored story.</p>"
            f'<img src="{WP}/closing-flourish.png" alt="the author\'s mark">'
            "<p>Made on the road with the travelling kit.</p>"
        )
        profile = {
            "signature_markers": ["closing-flourish"],
            "colophon_markers": ["travelling kit"],
        }
        d = wp_source.draft_from_wp(post, self.tmp.name, fetch=fake_fetch, profile=profile)
        self.assertEqual(d.caption, "<p>The authored story.</p>")
        self.assertIn("travelling kit", d.colophon)
        self.assertEqual(sum(1 for image in d.images if image.is_signature), 1)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
