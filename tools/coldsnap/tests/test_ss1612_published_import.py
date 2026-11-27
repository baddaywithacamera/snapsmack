"""SS-1612. Opening a published post must give real blocks, not printed markup.

THE FAULT. Sean opened Ray's post in COLD SNAP 0.7.67 Visual and the canvas
showed <p>[mosaic:752]text…</p> and <figure>[img:57|full|center]<figcaption>…
as literal characters, with no mosaic rendering. Two causes:
  - mode_take._draft_from_remote never parsed at all. It put the site's whole
    HTML body into draft.caption and left body_blocks empty.
  - biggie.parse_body reads AUTHORED text. A site body wraps prose in <p> and
    stores a mosaic INSIDE the paragraph, which matches no block pattern, and
    "mosaic" is a known shortcode so it does not fall through to RAW either.
    It became a paragraph with the tags still in its text.

THE TEST. The round trip is pinned to the REAL body of post 4, as the API
serializes it, supplied by Codex (room 8294) and verified here by SHA-256
before use. If that file is missing the fixture tests still run and the
round-trip test says NOT CHECKED rather than passing on a reconstruction.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import hashlib
import io
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from coldsnap_qt import biggie  # noqa: E402

# The exact bytes of post 4's content column, and their hash as posted.
POST4_PATH = r"C:/dev/snapsmack/.agent-scratch/codex/ss1612-post-4-body.html"
POST4_SHA = "f6ae0ac9ae7e347f8f30ca443561be8a675ffc2b1f11167508948822c3a90b0f"


def _load_post4():
    if not os.path.isfile(POST4_PATH):
        return None
    raw = io.open(POST4_PATH, "rb").read()
    if hashlib.sha256(raw).hexdigest() != POST4_SHA:
        return False          # present but NOT the reviewed bytes
    return raw.decode("utf-8")


class ByteExactRoundTrip(unittest.TestCase):
    """The invariant the whole design rests on."""

    def assert_round_trips(self, body: str, label: str = ""):
        blocks = biggie.parse_site_html(body)
        self.assertEqual(biggie.serialize_site_blocks(blocks), body,
                         "round trip changed the bytes " + label)

    def test_the_real_post_4_body_round_trips_byte_for_byte(self):
        body = _load_post4()
        if body is None:
            self.skipTest("NOT CHECKED: post 4's body file is not on this machine ("
                          + POST4_PATH + ")")
        if body is False:
            self.fail("post 4's body file does not match the reviewed SHA-256 "
                      + POST4_SHA + " -- refusing to pin a test to unverified bytes")
        self.assert_round_trips(body, "for post 4")

    def test_the_real_post_4_body_becomes_the_right_blocks(self):
        body = _load_post4()
        if body is None:
            self.skipTest("NOT CHECKED: post 4's body file is not on this machine")
        blocks = biggie.parse_site_html(body)

        # NOTHING may reach the canvas as a paragraph that still holds markup.
        for b in blocks:
            if b.get("type") == "para":
                self.assertNotIn("<p>", str(b.get("text", "")))
                self.assertNotIn("</p>", str(b.get("text", "")))
                self.assertNotIn("[mosaic:", str(b.get("text", "")))

        kinds = [b.get("type") for b in blocks]
        # Five site mosaics, as Codex measured on the live post.
        self.assertEqual(kinds.count("site_mosaic"), 5, kinds)
        self.assertEqual(
            sorted(b["mosaic_id"] for b in blocks if b.get("type") == "site_mosaic"),
            [752, 753, 754, 755, 756])
        # Three inline images, one of them a figure with a caption.
        self.assertGreaterEqual(kinds.count("image"), 3, kinds)
        captioned = [b for b in blocks
                     if b.get("type") == "image" and str(b.get("caption") or "").strip()]
        self.assertEqual(len(captioned), 1, "exactly one captioned figure")
        # Every mosaic that shared a <p> with prose is grouped with that prose.
        for b in blocks:
            if b.get("type") == "site_mosaic" and b.get("wrap"):
                group = b["wrap"]["group"]
                siblings = [x for x in blocks if (x.get("wrap") or {}).get("group") == group]
                self.assertEqual(len(siblings), 2)
                self.assertEqual(b["wrap"]["open"], "<p>")
                self.assertEqual(b["wrap"]["close"], "</p>")

    def test_every_block_is_untouched_on_import(self):
        body = _load_post4()
        if body is None:
            self.skipTest("NOT CHECKED: post 4's body file is not on this machine")
        self.assertTrue(biggie.site_blocks_are_untouched(biggie.parse_site_html(body)))


class ShapesFromTheSite(unittest.TestCase):
    """The exact shapes the site stores, each on its own."""

    def round(self, body):
        blocks = biggie.parse_site_html(body)
        self.assertEqual(biggie.serialize_site_blocks(blocks), body)
        return blocks

    def test_a_plain_paragraph(self):
        blocks = self.round("<p>Just words.</p>")
        self.assertEqual(len(blocks), 1)
        self.assertEqual(blocks[0]["type"], "para")
        self.assertEqual(blocks[0]["text"], "Just words.")

    def test_a_mosaic_sharing_a_paragraph_with_prose_splits_in_two(self):
        blocks = self.round("<p>[mosaic:752]Then some prose.</p>")
        self.assertEqual([b["type"] for b in blocks], ["site_mosaic", "para"])
        self.assertEqual(blocks[0]["mosaic_id"], 752)
        self.assertEqual(blocks[1]["text"], "Then some prose.")
        self.assertEqual(blocks[0]["wrap"]["group"], blocks[1]["wrap"]["group"])
        # The wrapper bytes ride with the halves, so the join is still exact.
        self.assertEqual(blocks[0]["src"], "<p>[mosaic:752]")
        self.assertEqual(blocks[1]["src"], "Then some prose.</p>")

    def test_a_mosaic_alone_in_a_paragraph_is_one_block(self):
        blocks = self.round("<p>[mosaic:753]</p>")
        self.assertEqual([b["type"] for b in blocks], ["site_mosaic"])
        self.assertNotIn("wrap", blocks[0])

    def test_an_image_sharing_a_paragraph_with_prose_splits_in_two(self):
        blocks = self.round("<p>[img:36|full|center]And prose.</p>")
        self.assertEqual([b["type"] for b in blocks], ["image", "para"])
        self.assertEqual(blocks[0]["img_id"], "36")
        self.assertEqual(blocks[1]["text"], "And prose.")

    def test_a_figure_with_a_caption_is_one_image_block_with_that_caption(self):
        blocks = self.round(
            "<figure>[img:57|full|center]<figcaption>A caption.</figcaption></figure>")
        self.assertEqual([b["type"] for b in blocks], ["image"])
        self.assertEqual(blocks[0]["img_id"], "57")
        self.assertEqual(blocks[0]["caption"], "A caption.")
        self.assertTrue(blocks[0]["show_caption"])

    def test_separators_are_preserved_exactly(self):
        body = "<p>One.</p>\n<p>Two.</p>\n<p>Three.</p>"
        blocks = self.round(body)
        self.assertEqual([b["sep"] for b in blocks], ["\n", "\n", ""])

    def test_a_blank_line_between_elements_survives(self):
        self.round("<p>One.</p>\n\n<p>Two.</p>")

    def test_no_trailing_newline_is_not_invented(self):
        blocks = self.round("<p>One.</p>")
        self.assertEqual(blocks[-1]["sep"], "")

    def test_a_trailing_newline_is_not_dropped(self):
        blocks = self.round("<p>One.</p>\n")
        self.assertEqual(blocks[-1]["sep"], "\n")

    def test_headings_lists_quotes_and_rules_are_read_as_themselves(self):
        for body, kind in (("<h2>A heading</h2>", "heading"),
                           ("<ul>\n  <li>One</li>\n</ul>", "list"),
                           ("<blockquote>Quoted.</blockquote>", "quote"),
                           ("<hr>", "hr")):
            blocks = self.round(body)
            self.assertEqual(blocks[0]["type"], kind, body)


class NothingUnparsedReachesTheCanvasAsText(unittest.TestCase):
    """The specific thing Sean saw must be impossible."""

    def test_an_unknown_element_is_a_labelled_raw_block_not_a_paragraph(self):
        body = '<p><table><tr><td>odd</td></tr></table></p>'
        blocks = biggie.parse_site_html(body)
        self.assertEqual(biggie.serialize_site_blocks(blocks), body)
        self.assertEqual(blocks[0]["type"], "raw")
        self.assertTrue(str(blocks[0].get("label") or "").strip())

    def test_an_unknown_shortcode_is_raw_and_labelled(self):
        body = "<p>[somethingnew:9]with prose</p>"
        blocks = biggie.parse_site_html(body)
        self.assertEqual(biggie.serialize_site_blocks(blocks), body)
        self.assertTrue(all(b["type"] != "para" or "[somethingnew" not in b.get("text", "")
                            for b in blocks))

    def test_loose_text_outside_any_element_is_kept_and_labelled(self):
        body = "stray words\n<p>Proper.</p>"
        blocks = biggie.parse_site_html(body)
        self.assertEqual(biggie.serialize_site_blocks(blocks), body)
        self.assertEqual(blocks[0]["type"], "raw")
        self.assertTrue(str(blocks[0].get("label") or "").strip())

    def test_no_block_of_any_type_carries_a_p_tag_in_its_display_text(self):
        body = "<p>[mosaic:1]a</p>\n<p>b</p>\n<figure>[img:2]<figcaption>c</figcaption></figure>"
        for b in biggie.parse_site_html(body):
            if b.get("type") in ("para", "site_mosaic"):
                self.assertNotIn("<p", str(b.get("text", "")))
                self.assertNotIn("</p", str(b.get("text", "")))


class EditingBreaksByteIdentityDeliberately(unittest.TestCase):
    """Byte-identity is for an untouched post, and must not outlive an edit."""

    def test_removing_src_hands_the_block_back_to_the_normal_serializer(self):
        blocks = biggie.parse_site_html("<p>One.</p>\n<p>Two.</p>")
        self.assertTrue(biggie.site_blocks_are_untouched(blocks))
        blocks[1].pop("src")
        blocks[1]["text"] = "Two, edited."
        self.assertFalse(biggie.site_blocks_are_untouched(blocks))
        out = biggie.serialize_site_blocks(blocks)
        self.assertIn("Two, edited.", out)
        self.assertEqual(out, "<p>One.</p>\nTwo, edited.")

    def test_an_empty_body_gives_no_blocks_and_round_trips(self):
        self.assertEqual(biggie.parse_site_html(""), [])
        self.assertEqual(biggie.serialize_site_blocks([]), "")
        self.assertFalse(biggie.site_blocks_are_untouched([]))


class FuzzTheInvariant(unittest.TestCase):
    """The round trip must hold for inputs nobody designed for."""

    def test_assorted_bodies_all_round_trip(self):
        bodies = [
            "",
            "\n",
            "   ",
            "<p></p>",
            "<p>  </p>",
            "<p>[mosaic:1]</p>",
            "<p>[mosaic:1] </p>",
            "<p>[mosaic:1]a</p><p>b</p>",
            "\n\n<p>leading blank lines</p>\n\n",
            "<p>unclosed",
            "</p>orphan close",
            "<p>one</p>junk<p>two</p>",
            "<P>UPPERCASE TAGS</P>",
            '<p class="x">attributes</p>',
            "<figure>[img:1]<figcaption></figcaption></figure>",
            "<p>&amp; entities &lt;kept&gt;</p>",
            "<p>unicode: café — naïve 日本語</p>",
            "<p>[img:1|full|center|decorative]x</p>",
            "<hr>\n<hr/>\n<hr />",
            "<p>a</p>\r\n<p>b</p>",
        ]
        for body in bodies:
            blocks = biggie.parse_site_html(body)
            self.assertEqual(biggie.serialize_site_blocks(blocks), body,
                             "round trip changed: " + repr(body))


class TheEditorMustNotDestroyTheRoundTrip(unittest.TestCase):
    """Opening a post in the editor must not, by itself, change its bytes.

    _BlockRow.to_block() rebuilds a block from its widgets, so before SS-1612
    it dropped every key no widget owned -- including the verbatim source. That
    would have broken byte-identity just by looking at a post.
    """

    @classmethod
    def setUpClass(cls):
        os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
        from PySide6.QtWidgets import QApplication
        cls.app = QApplication.instance() or QApplication([])

    def _editor_round_trip(self, body):
        from coldsnap_qt.biggie import BiggieEditor
        editor = BiggieEditor(allow_mosaic=True)
        blocks = biggie.parse_site_html(body)
        editor.from_blocks(blocks)
        return editor, editor.to_blocks()

    def test_opening_and_closing_the_editor_changes_nothing(self):
        body = _load_post4()
        if body is None:
            self.skipTest("NOT CHECKED: post 4's body file is not on this machine")
        _editor, out = self._editor_round_trip(body)
        self.assertTrue(biggie.site_blocks_are_untouched(out),
                        "a block lost its source just by being displayed")
        self.assertEqual(biggie.serialize_site_blocks(out), body)

    def test_a_site_mosaic_survives_the_editor_intact(self):
        _editor, out = self._editor_round_trip("<p>[mosaic:752]prose</p>")
        self.assertEqual(out[0]["type"], "site_mosaic")
        self.assertEqual(out[0]["mosaic_id"], 752)
        self.assertEqual(biggie.serialize_site_blocks(out), "<p>[mosaic:752]prose</p>")

    def test_editing_one_paragraph_drops_only_that_block_source(self):
        editor, _out = self._editor_round_trip("<p>One.</p>\n<p>Two.</p>")
        rows = editor._rows
        self.assertEqual(len(rows), 2)
        rows[1].text.setPlainText("Two, edited.")
        out = editor.to_blocks()
        self.assertIn("src", out[0], "the untouched block lost its source")
        self.assertNotIn("src", out[1], "the EDITED block kept its old source")
        self.assertEqual(biggie.serialize_site_blocks(out), "<p>One.</p>\nTwo, edited.")

    def test_the_grouping_survives_the_editor(self):
        _editor, out = self._editor_round_trip("<p>[mosaic:9]prose</p>")
        self.assertEqual(out[0]["wrap"]["group"], out[1]["wrap"]["group"])
        self.assertEqual(out[0]["wrap"]["open"], "<p>")


class AnImageBlockMustNotLoseWhatTheEditorCannotShow(unittest.TestCase):
    """A fault older than SS-1612, found by the round-trip test above.

    _BlockRow.to_block() for an image returned only the three fields it has
    widgets for, so caption, show_caption, gallery, width and decorative were
    DISCARDED by displaying the block. On Ray's post 4 that meant the captioned
    photograph lost its caption on the way through BIGGIE.
    """

    @classmethod
    def setUpClass(cls):
        os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
        from PySide6.QtWidgets import QApplication
        cls.app = QApplication.instance() or QApplication([])

    def _row(self, block):
        from coldsnap_qt.biggie import BiggieEditor
        editor = BiggieEditor(allow_mosaic=True)
        editor.from_blocks([block])
        return editor, editor._rows[0]

    def test_a_caption_survives_being_displayed(self):
        block = {"type": "image", "img_id": "57", "size": "full", "align": "center",
                 "caption": "Pontiac RPM, Car Show, Strathmore, AB, 2026-07-11",
                 "show_caption": True}
        _editor, row = self._row(block)
        out = row.to_block()
        self.assertEqual(out.get("caption"), block["caption"])
        self.assertTrue(out.get("show_caption"))
        self.assertIn("caption=Pontiac RPM", biggie.serialize_block(out))

    def test_gallery_width_and_decorative_survive_too(self):
        block = {"type": "image", "img_id": "5", "size": "full", "align": "left",
                 "gallery": True, "width": "60", "decorative": True}
        _editor, row = self._row(block)
        out = row.to_block()
        for key in ("gallery", "width", "decorative"):
            self.assertEqual(out.get(key), block[key], key)

    def test_changing_the_image_id_drops_the_stale_verbatim_shortcode(self):
        block = {"type": "image", "img_id": "36", "raw": "[img:36|full|center]",
                 "size": "full", "align": "center"}
        _editor, row = self._row(block)
        row.img_id.setText("99")
        out = row.to_block()
        self.assertNotIn("raw", out, "the old shortcode would have been sent instead")
        self.assertIn("99", biggie.serialize_block(out))
        self.assertNotIn("36", biggie.serialize_block(out))

    def test_an_untouched_image_keeps_its_verbatim_shortcode(self):
        block = {"type": "image", "img_id": "36", "raw": "[img:36|full|center]",
                 "size": "full", "align": "center"}
        _editor, row = self._row(block)
        self.assertEqual(biggie.serialize_block(row.to_block()), "[img:36|full|center]")


class TheImportPathMustActuallyParse(unittest.TestCase):
    """The other half of the fault: nothing called a parser at all.

    _draft_from_remote set draft.caption to the site's whole HTML body and left
    body_blocks empty, so Open published did not fail to parse -- it never
    parsed. This checks the wiring by driving the same conversion the method
    now performs, and asserts the source is the published body.
    """

    def test_the_import_produces_blocks_and_not_one_html_blob(self):
        body = _load_post4()
        if body is None:
            self.skipTest("NOT CHECKED: post 4's body file is not on this machine")
        stored = biggie.blocks_to_json(biggie.parse_site_html(body))
        blocks = biggie.blocks_from_json(stored)
        self.assertGreater(len(blocks), 1, "the body arrived as a single blob")
        self.assertEqual([b.get("type") for b in blocks].count("site_mosaic"), 5)

    def test_the_wiring_is_in_mode_take(self):
        path = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                            "coldsnap_qt", "mode_take.py")
        source = io.open(path, encoding="utf-8").read()
        self.assertIn("draft.body_blocks = biggie.blocks_to_json(", source)
        self.assertIn("biggie.parse_site_html(", source)

    def test_blocks_survive_the_json_round_trip_with_their_source(self):
        body = "<p>[mosaic:752]prose</p>\n<p>more</p>"
        blocks = biggie.blocks_from_json(biggie.blocks_to_json(biggie.parse_site_html(body)))
        self.assertTrue(biggie.site_blocks_are_untouched(blocks),
                        "the source bytes did not survive being stored as JSON")
        self.assertEqual(biggie.serialize_site_blocks(blocks), body)


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
