"""Slice 8 (parser-valid [img:...] round-trip) and Slice 9 (end-to-end payload equivalence)."""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sumna_offline as O
from sumna_post import SmacktalkPoster
from coldsnap_qt import biggie
from coldsnap_qt.body_editor import BodyEditor
from coldsnap_qt.mode_take import TakeMode


def _app():
    return QApplication.instance() or QApplication([])


def test_twiggy_visual_twiggy_image_options_lossless():
    """Every parser.php:937 [img:...] optional combination survives TWIGGY -> Visual -> TWIGGY."""
    _app()
    editor = BodyEditor(allow_mosaic=True)

    cases = [
        "[img:5|left]",
        "[img:5|decorative]",
        "[img:5|small|left|30|decorative]",
        "[img:g5|small|left]",
        "[img:42|full|center]",
        "[img:5]",
    ]
    for raw in cases:
        editor._set_biggie(False)
        editor.editor.setPlainText(raw)
        editor._set_biggie(True)
        editor._set_biggie(False)
        assert editor.editor.toPlainText() == raw, f"lost options on {raw!r}"


def test_slice9_end_to_end_compose_to_0733_payload_equivalence(tmp_path):
    """Mixed blocks composed in TakeMode produce the exact 0.7.33 SmacktalkPoster payload."""
    _app()
    take = TakeMode(lambda: {"url": "https://example.invalid", "smackpress_key": "k"})

    # Create a tiny valid PNG on disk for the bucket
    img_path = tmp_path / "sample.png"
    from PIL import Image
    Image.new("RGB", (64, 64), (40, 80, 40)).save(img_path)

    take._bucket = [
        O.DraftImage(local_path=str(img_path), filename="sample.png", alt="Sample alt"),
    ]
    take._cover_idx = 0
    take.title_edit.setText("Slice 9 Equivalence Essay")
    take.tags_edit.setText("#essay #coldtake")
    take.status_combo.setCurrentText("published")

    blocks = [
        {"type": "heading", "level": 2, "text": "Opening"},
        {"type": "para", "text": "Plain paragraph."},
        {"type": "para", "text": "Dropcap start.", "dropcap": True},
        {"type": "pullquote", "text": "Pull quote line."},
        {"type": "columns", "ratio": "equal", "cols": [
            [{"type": "para", "text": "Col one."}],
            [{"type": "para", "text": "Col two."}],
        ]},
        {"type": "image", "img_id": "5", "size": "small", "align": "left",
         "width": "30", "decorative": True},
        {"type": "mosaic", "order": [1], "layout": "asymmetric"},
    ]
    take.body._set_biggie(True)
    take.body.biggie.from_blocks(blocks)

    session = O.Session(str(tmp_path), {"session_id": "test", "mode": O.MODE_SMACKTALK})
    composed_draft = take._build_draft(session)

    # Equivalent 0.7.33 plain-text draft (what 0.7.33 TWIGGY authored directly)
    equivalent_0733_text = biggie.serialize_blocks(blocks)
    legacy_draft = O.Draft(
        draft_id="legacy-0733",
        mode=O.MODE_SMACKTALK,
        kind=composed_draft.kind,
        title="Slice 9 Equivalence Essay",
        caption=equivalent_0733_text,
        tags="#essay #coldtake",
        img_status="published",
        images=[O.DraftImage(local_path=str(img_path), filename="sample.png",
                             alt="Sample alt", is_cover=True)],
    )

    poster = SmacktalkPoster("https://example.invalid", "k")
    composed_payload = poster.build_payload(composed_draft, [101], 101)
    legacy_payload = poster.build_payload(legacy_draft, [101], 101)

    assert composed_payload == legacy_payload
    assert composed_payload["content"] == equivalent_0733_text
    assert "[img:5|small|left|30|decorative]" in composed_payload["content"]
    assert "[mosaic=1 layout=asymmetric]" in composed_payload["content"]
    take.close()


# ===== SNAPSMACK EOF =====
