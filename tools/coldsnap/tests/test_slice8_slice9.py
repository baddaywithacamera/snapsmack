"""Slice 8 (parser-valid [img:...] round-trip) and Slice 9 (frozen 0.7.33 payload equivalence)."""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sumna_offline as O
from sumna_post import SmacktalkPoster
from coldsnap_qt.body_editor import BodyEditor
from coldsnap_qt.mode_take import TakeMode


# Exact outgoing smackpress/posts JSON payload recorded from frozen 0.7.33 (38c519d1)
# for the mixed-block post below. Do not compute this with current biggie.py.
FROZEN_0733_PAYLOAD = {
    "title": "Slice 9 Equivalence Essay",
    "content": (
        "<h2>Opening</h2>\n\n"
        "Plain paragraph.\n\n"
        "[dropcap]D[/dropcap]ropcap start.\n\n"
        "[pullquote]Pull quote line.[/pullquote]\n\n"
        "[columns=2]\nCol one.\n\n[col]\n\nCol two.\n[/columns]\n\n"
        "[img:42|full|center]\n\n"
        "[mosaic=1 layout=asymmetric]"
    ),
    "status": "published",
    "featured_image_id": 101,
    "bucket_image_ids": [101],
    "tags": "essay coldtake",
    "cat_ids": [],
    "album_ids": [],
    "colophon": "",
    "signature_image_id": 0,
}


def _app():
    return QApplication.instance() or QApplication([])


def test_twiggy_visual_twiggy_image_options_lossless():
    """Every parser.php:937 [img:...] form (including whitespace/case/%) survives TWIGGY -> Visual -> TWIGGY."""
    _app()
    editor = BodyEditor(allow_mosaic=True)

    cases = [
        "[img:5|left]",
        "[img:5|decorative]",
        "[img:5|small|left|30|decorative]",
        "[img:g5|small|left]",
        "[img:42|full|center]",
        "[img:5]",
        "[img: g 5 | small | left | 30% | decorative ]",
        "[IMG:G5|SMALL|LEFT|30|DECORATIVE]",
        "[img:5|30%]",
        "[img:5|left|30%]",
    ]
    for raw in cases:
        editor._set_biggie(False)
        editor.editor.setPlainText(raw)
        editor._set_biggie(True)
        editor._set_biggie(False)
        assert editor.editor.toPlainText() == raw, f"lost spelling/options on {raw!r}"


def test_slice9_end_to_end_compose_to_frozen_0733_payload(tmp_path):
    """Mixed blocks composed in TakeMode produce the exact frozen 0.7.33 (38c519d1) payload."""
    _app()
    take = TakeMode(lambda: {"url": "https://example.invalid", "smackpress_key": "k"})

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
        {"type": "image", "img_id": "42", "size": "full", "align": "center"},
        {"type": "mosaic", "order": [1], "layout": "asymmetric"},
    ]
    take.body._set_biggie(True)
    take.body.biggie.from_blocks(blocks)

    session = O.Session(str(tmp_path), {"session_id": "test", "mode": O.MODE_SMACKTALK})
    composed_draft = take._build_draft(session)

    poster = SmacktalkPoster("https://example.invalid", "k")
    composed_payload = poster.build_payload(composed_draft, [101], 101)

    assert composed_payload == FROZEN_0733_PAYLOAD
    take.close()


# ===== SNAPSMACK EOF =====
