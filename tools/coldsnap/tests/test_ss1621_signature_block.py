"""SS-1621. The SIG block in BIGGIE, and the post fields it writes.

THE CONTRACT, from Gemini in room 8475 and approved by the PM in 8442. Three
post fields, each a nullable image id with THREE meanings:
    NULL   inherit the blog default
    0      explicit opt-out, this post carries no signature
    an id  use that image
and a field OMITTED on an edit means PRESERVE what is stored, which is a
fourth state. Four states is why this is not a checkbox, and collapsing them
is how a post loses a signature its author never touched.

WHY THE SERIALIZATION MATTERS MOST. The signature is not body text: the CMS
carries it as post fields. If this block put a shortcode in the body, thirty
skins that know nothing about it would print the characters on the page --
which is exactly the fault SS-1612 was opened for.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from coldsnap_qt import biggie  # noqa: E402


class TheBlockPutsNothingInTheBody(unittest.TestCase):
    """The part that would reach every skin if it were wrong."""

    def test_a_signature_block_serializes_to_nothing(self):
        self.assertEqual(biggie.serialize_block({"type": "signature", "mode": "inherit"}), "")
        self.assertEqual(biggie.serialize_block({"type": "signature", "mode": "off"}), "")
        self.assertEqual(
            biggie.serialize_block({"type": "signature", "mode": "custom",
                                    "light_image_id": 7, "dark_image_id": 8}), "")

    def test_it_leaves_the_body_exactly_as_it_was(self):
        blocks = [{"type": "para", "text": "One."},
                  {"type": "signature", "mode": "custom", "light_image_id": 7},
                  {"type": "para", "text": "Two."}]
        self.assertEqual(biggie.serialize_blocks(blocks), "One.\n\nTwo.")

    def test_a_body_of_nothing_but_a_signature_is_empty(self):
        self.assertEqual(biggie.serialize_blocks([{"type": "signature", "mode": "off"}]), "")

    def test_no_shortcode_leaks_into_the_body_in_any_mode(self):
        for mode in biggie.SIGNATURE_MODES:
            body = biggie.serialize_blocks([
                {"type": "para", "text": "words"},
                {"type": "signature", "mode": mode, "light_image_id": 5, "dark_image_id": 6},
            ])
            self.assertNotIn("sig", body.lower(), mode)
            self.assertNotIn("[", body, mode)


class TheFourStates(unittest.TestCase):
    """inherit, opt out, choose, and preserve."""

    def test_inherit_sends_null_so_the_blog_default_applies(self):
        self.assertEqual(biggie.signature_fields({"type": "signature", "mode": "inherit"}), {
            "signature_image_id": None,
            "signature_light_image_id": None,
            "signature_dark_image_id": None,
        })

    def test_off_sends_zero_as_an_explicit_opt_out(self):
        self.assertEqual(biggie.signature_fields({"type": "signature", "mode": "off"}), {
            "signature_image_id": 0,
            "signature_light_image_id": 0,
            "signature_dark_image_id": 0,
        })

    def test_custom_sends_the_chosen_ids(self):
        self.assertEqual(biggie.signature_fields(
            {"type": "signature", "mode": "custom", "light_image_id": 7, "dark_image_id": 8}), {
            "signature_image_id": 7,
            "signature_light_image_id": 7,
            "signature_dark_image_id": 8,
        })

    def test_an_unchosen_surface_INHERITS_rather_than_switching_itself_off(self):
        # The difference between "I did not choose" and "I chose none". Sending
        # 0 here would silently remove the dark signature.
        fields = biggie.signature_fields(
            {"type": "signature", "mode": "custom", "light_image_id": 7})
        self.assertEqual(fields["signature_light_image_id"], 7)
        self.assertIsNone(fields["signature_dark_image_id"])

    def test_a_dark_only_choice_still_fills_the_back_compat_field(self):
        fields = biggie.signature_fields(
            {"type": "signature", "mode": "custom", "dark_image_id": 8})
        self.assertEqual(fields["signature_image_id"], 8)
        self.assertIsNone(fields["signature_light_image_id"])

    def test_NO_signature_block_means_omit_the_fields_entirely(self):
        # The fourth state. A draft that never mentioned signatures must not
        # clear one, so the sender gets nothing to send.
        self.assertEqual(biggie.signature_fields_for_blocks(
            [{"type": "para", "text": "words"}]), {})
        self.assertEqual(biggie.signature_fields_for_blocks([]), {})
        self.assertEqual(biggie.signature_fields_for_blocks(None), {})

    def test_a_signature_block_present_means_send_the_fields(self):
        self.assertNotEqual(biggie.signature_fields_for_blocks(
            [{"type": "signature", "mode": "off"}]), {})

    def test_the_last_signature_block_wins(self):
        fields = biggie.signature_fields_for_blocks([
            {"type": "signature", "mode": "off"},
            {"type": "signature", "mode": "custom", "light_image_id": 9},
        ])
        self.assertEqual(fields["signature_light_image_id"], 9)


class BadInputCannotBecomeAnOptOut(unittest.TestCase):
    """A typo must not read as "no signature"."""

    def test_an_unknown_mode_falls_back_to_inherit(self):
        for mode in ("", "nope", None, "OFF ", 7):
            clean = biggie.signature_block_normalise({"mode": mode})
            self.assertIn(clean["mode"], biggie.SIGNATURE_MODES)
            if mode != "OFF ":
                self.assertEqual(clean["mode"], "inherit", repr(mode))

    def test_a_mode_is_case_insensitive(self):
        self.assertEqual(biggie.signature_block_normalise({"mode": "OFF"})["mode"], "off")
        self.assertEqual(biggie.signature_block_normalise({"mode": "Custom"})["mode"], "custom")

    def test_rubbish_in_an_id_field_becomes_unchosen_not_zero(self):
        # Zero is an opt-out. A field somebody typed "seven" into must NOT
        # become one.
        for value in ("", "   ", "seven", None, "-3", "0", 0, -1, "1.5"):
            clean = biggie.signature_block_normalise({"mode": "custom", "light_image_id": value})
            self.assertIsNone(clean["light_image_id"], repr(value))
        fields = biggie.signature_fields({"mode": "custom", "light_image_id": "seven"})
        self.assertIsNone(fields["signature_light_image_id"])
        self.assertNotEqual(fields["signature_light_image_id"], 0)

    def test_an_id_given_as_a_string_is_accepted(self):
        clean = biggie.signature_block_normalise({"mode": "custom", "light_image_id": " 12 "})
        self.assertEqual(clean["light_image_id"], 12)

    def test_normalise_always_returns_the_block_type(self):
        self.assertEqual(biggie.signature_block_normalise({})["type"], "signature")


class TheBlockIsOfferedAndSurvivesTheEditor(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
        from PySide6.QtWidgets import QApplication
        cls.app = QApplication.instance() or QApplication([])

    def test_it_is_in_the_blocks_tray(self):
        self.assertIn("signature", [key for key, _label in biggie._TYPE_LABELS])

    def _round_trip(self, block):
        editor = biggie.BiggieEditor(allow_mosaic=True)
        editor.from_blocks([block])
        return editor, editor.to_blocks()[0]

    def test_a_signature_block_survives_being_displayed(self):
        for block in ({"type": "signature", "mode": "inherit"},
                      {"type": "signature", "mode": "off"},
                      {"type": "signature", "mode": "custom",
                       "light_image_id": 7, "dark_image_id": 8}):
            _editor, out = self._round_trip(block)
            self.assertEqual(biggie.signature_block_normalise(block),
                             biggie.signature_block_normalise(out))

    def test_changing_the_mode_in_the_editor_changes_the_fields(self):
        editor, _out = self._round_trip({"type": "signature", "mode": "inherit"})
        row = editor._rows[0]
        row.sig_mode.setCurrentIndex(1)          # No signature on this post
        self.assertEqual(biggie.signature_fields(row.to_block())["signature_image_id"], 0)
        row.sig_mode.setCurrentIndex(2)          # Choose images
        row.sig_light.setText("42")
        fields = biggie.signature_fields(row.to_block())
        self.assertEqual(fields["signature_light_image_id"], 42)
        self.assertEqual(fields["signature_image_id"], 42)

    def test_a_blank_id_in_the_editor_is_unchosen_not_zero(self):
        editor, _out = self._round_trip({"type": "signature", "mode": "custom",
                                         "light_image_id": 7, "dark_image_id": 8})
        row = editor._rows[0]
        row.sig_dark.setText("")
        fields = biggie.signature_fields(row.to_block())
        self.assertEqual(fields["signature_light_image_id"], 7)
        self.assertIsNone(fields["signature_dark_image_id"])

    def test_adding_one_from_the_tray_gives_a_usable_block(self):
        editor = biggie.BiggieEditor(allow_mosaic=True)
        editor.add_block({"type": "signature"})
        out = editor.to_blocks()
        self.assertEqual(len(out), 1)
        self.assertEqual(out[0]["type"], "signature")
        self.assertEqual(out[0]["mode"], "inherit")
        self.assertEqual(biggie.serialize_blocks(out), "")


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
