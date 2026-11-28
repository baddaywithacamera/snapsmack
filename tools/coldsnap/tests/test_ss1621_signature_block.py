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
        for mode in ("", "nope", None, 7, [], {}):
            clean = biggie.signature_block_normalise({"mode": mode})
            self.assertEqual(clean["mode"], "inherit", repr(mode))

    def test_SURROUNDING_WHITESPACE_DOES_NOT_CHANGE_THE_ANSWER(self):
        """Muse's nit on 73fa2028, and my test had excused it.

        "OFF " fell through to inherit, so an author who said NO SIGNATURE
        silently got the blog's one. The direction is what makes it a defect
        rather than a tidiness issue. The original version of this test had an
        `if mode != "OFF "` exemption around the assertion, which is how it
        passed while the behaviour was wrong.
        """
        tab_and_newline = chr(9) + "off" + chr(10)
        for mode, expected in (("OFF ", "off"), (" off", "off"), ("  OFF  ", "off"),
                               ("Custom ", "custom"), (" inherit", "inherit"),
                               (tab_and_newline, "off")):
            self.assertEqual(biggie.signature_block_normalise({"mode": mode})["mode"],
                             expected, repr(mode))

    def test_a_padded_opt_out_really_sends_zero(self):
        # The end of the chain, not just the normaliser: " off " must produce
        # an opt-out payload, because that is what the author asked for.
        fields = biggie.signature_fields({"type": "signature", "mode": " off "})
        self.assertEqual(fields["signature_image_id"], 0)
        self.assertEqual(fields["signature_light_image_id"], 0)
        self.assertEqual(fields["signature_dark_image_id"], 0)

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

    def test_the_id_FIELDS_ARE_DEAD_UNLESS_YOU_ARE_CHOOSING(self):
        """Muse's nit on 73fa2028: a control that does nothing.

        In inherit and off modes signature_fields() ignores the id fields
        entirely, so leaving them editable invites an author to type an id that
        is then thrown away. That is the dead-control fault class this project
        has spent weeks on, so the fields follow the dropdown.

        Mutation added this: forcing them always-enabled left the suite green.
        """
        for mode, enabled in (("inherit", False), ("off", False), ("custom", True)):
            editor, _out = self._round_trip({"type": "signature", "mode": mode})
            row = editor._rows[0]
            self.assertEqual(row.sig_light.isEnabled(), enabled, mode)
            self.assertEqual(row.sig_dark.isEnabled(), enabled, mode)

    def test_the_fields_follow_the_dropdown_as_it_changes(self):
        editor, _out = self._round_trip({"type": "signature", "mode": "inherit"})
        row = editor._rows[0]
        self.assertFalse(row.sig_light.isEnabled())
        row.sig_mode.setCurrentIndex(2)                      # Choose images
        self.assertTrue(row.sig_light.isEnabled())
        self.assertTrue(row.sig_dark.isEnabled())
        row.sig_mode.setCurrentIndex(1)                      # No signature
        self.assertFalse(row.sig_light.isEnabled())
        self.assertFalse(row.sig_dark.isEnabled())

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

def test_canvas_paints_signs_moves_and_removes_signature():
    from PySide6.QtGui import QColor, QPixmap
    from PySide6.QtWidgets import QApplication
    from coldsnap_qt.canvas import BiggieCanvas

    app = QApplication.instance() or QApplication([])
    canvas = BiggieCanvas()
    canvas.resize(460, 300)
    canvas.signature_default = lambda: 413
    signature = QPixmap(85, 36)
    fixture_color = QColor("#00961c")
    signature.fill(fixture_color)
    observed = []

    def resolve(image_id, width):
        observed.append(image_id)
        return signature

    canvas.set_image_resolver(resolve)
    canvas.from_blocks([{"type": "para", "text": "Body"}])
    canvas.insert_signature()
    canvas.show()
    app.processEvents()
    image = canvas.viewport().grab().toImage()
    assert "413" in observed
    assert any(image.pixelColor(x, y) == fixture_color
               for y in range(image.height()) for x in range(image.width()))
    assert canvas.to_blocks()[1]["type"] == "signature"
    assert biggie.serialize_blocks(canvas.to_blocks()) == "Body"

    position = canvas.document().firstBlock().next().position()
    assert canvas._can_move_object(position, -1)
    canvas._move_object(position, -1)
    assert canvas.to_blocks()[0]["type"] == "signature"
    canvas._remove_object(canvas.document().firstBlock().position())
    assert all(block["type"] != "signature" for block in canvas.to_blocks())
    canvas.close()


def test_canvas_previews_only_the_current_surface_signature():
    from PySide6.QtWidgets import QApplication
    from coldsnap_qt.canvas import BiggieCanvas, site_surface_palette

    app = QApplication.instance() or QApplication([])
    canvas = BiggieCanvas()
    canvas.resize(460, 300)
    observed = []
    canvas.set_image_resolver(lambda image_id, _width: observed.append(image_id))
    canvas.from_blocks([{"type": "signature", "mode": "custom", "dark_image_id": 413}])
    canvas.show()
    try:
        app.processEvents()
        canvas.viewport().grab()
        assert observed == []

        canvas.signature_default = lambda: 829
        canvas.viewport().grab()
        assert observed and set(observed) == {"829"}

        observed.clear()
        canvas.apply_surface_palette(site_surface_palette("#111111"))
        app.processEvents()
        canvas.viewport().grab()
        assert observed and set(observed) == {"413"}
    finally:
        canvas.close()


def test_new_signature_survives_text_edit_and_removal_persists(monkeypatch, tmp_path):
    from test_ss1514_open_published_preserves_draft import _FakePoster, _setup_mode

    _, mode = _setup_mode(monkeypatch, tmp_path, _FakePoster([], {}))
    try:
        assert mode.body.authoring_blocks()[-1]["type"] == "signature"
        assert not mode._has_open_draft_content()
        mode.body._set_biggie(False)
        mode.body.editor.setPlainText("One.\n\nTwo.")
        mode.body._set_biggie(True)
        assert mode.body.authoring_blocks()[-1]["type"] == "signature"
        assert mode.body.toPlainText() == "One.\n\nTwo."

        canvas = mode.body.canvas
        block = canvas.document().firstBlock()
        while block.isValid() and "\ufffc" not in block.text():
            block = block.next()
        assert block.isValid()
        canvas._remove_object(block.position())
        draft = mode._build_draft(mode.rail.ensure_session())
        fields = biggie.signature_fields_for_blocks(biggie.blocks_from_json(draft.body_blocks))
        assert len(fields) == 3 and all(value == 0 for value in fields.values())
        mode._edit(draft)
        assert all(block.get("type") != "signature" for block in mode.body.authoring_blocks())
    finally:
        mode.close()


def test_open_published_signature_is_visible_and_text_edit_preserves_it(monkeypatch, tmp_path):
    from PySide6.QtGui import QTextCursor
    from sumna_post import SmacktalkPoster
    from test_ss1514_open_published_preserves_draft import _FakePoster, _setup_mode

    data = {"id": 4, "title": "Start your engines...", "content": "<p>Before edit.</p>",
            "status": "published", "signature_image_id": 413,
            "signature_light_image_id": 413, "signature_dark_image_id": 0}
    app, mode = _setup_mode(monkeypatch, tmp_path, _FakePoster([], {}))
    try:
        draft = mode._draft_from_remote(data)
        session = mode.rail.ensure_session()
        session.add_draft(draft, copy_images=False)
        mode._edit(draft)
        mode.body._set_biggie(True)
        canvas = mode.body.canvas
        blocks = canvas.to_blocks()
        assert blocks[-1]["type"] == "signature"
        assert blocks[-1]["published_signature"] == {
            "signature_image_id": 413, "signature_light_image_id": 413,
            "signature_dark_image_id": 0,
        }

        cursor = canvas.textCursor()
        cursor.movePosition(QTextCursor.Start)
        cursor.insertText("Word. ")
        canvas.setTextCursor(cursor)
        app.processEvents()
        edited = mode._build_draft(session)
        payload = SmacktalkPoster("https://example.com", "fixture", signature_image_id=99).build_payload(
            edited, [], None, SmacktalkPoster._body_text_for_sync(edited))
        assert "Word. Before edit." in payload["content"]
        assert payload["post_id"] == 4
        assert not set(biggie.PUBLISHED_SIGNATURE_KEYS).intersection(payload)
    finally:
        mode.close()


# ===== SNAPSMACK EOF =====
