"""Slice 8 (132-case [img:...] round-trip) and Slice 9 (frozen 0.7.33 JSON fixture equivalence)."""

import itertools
import json
import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import sumna_offline as O
from sumna_post import SmacktalkPoster
from coldsnap_qt.body_editor import BodyEditor
from coldsnap_qt.mode_take import TakeMode


_FIXTURE_PATH = os.path.join(
    os.path.dirname(__file__), "fixtures", "frozen_0733_payload.json"
)


def _app():
    return QApplication.instance() or QApplication([])


def _canonical_image_matrix():
    """Generate all 128 canonical [img:...] combinations accepted by core/parser.php:937."""
    cases = []
    for gallery, size, align, width, deco in itertools.product(
        ("", "g"),
        (None, "small", "wall", "full"),
        (None, "left", "center", "right"),
        (None, "30"),
        (None, "decorative"),
    ):
        parts = [f"{gallery}5"]
        for opt in (size, align, width, deco):
            if opt is not None:
                parts.append(opt)
        cases.append("[img:" + "|".join(parts) + "]")
    return cases


def test_twiggy_visual_twiggy_image_options_lossless():
    """All 128 canonical + 4 non-canonical parser.php:937 [img:...] forms survive TWIGGY -> Visual -> TWIGGY."""
    _app()
    editor = BodyEditor(allow_mosaic=True)

    canonical = _canonical_image_matrix()
    assert len(canonical) == 128

    noncanonical = [
        "[img: g 5 | small | left | 30% | decorative ]",
        "[IMG:G5|SMALL|LEFT|30|DECORATIVE]",
        "[img:5|30%]",
        "[img:5|left|30%]",
    ]
    cases = canonical + noncanonical
    assert len(cases) == 132

    for raw in cases:
        editor._set_biggie(False)
        editor.editor.setPlainText(raw)
        editor._set_biggie(True)
        editor._set_biggie(False)
        assert editor.editor.toPlainText() == raw, f"lost spelling/options on {raw!r}"


def test_slice9_end_to_end_compose_to_frozen_0733_payload(tmp_path):
    """Mixed blocks composed in TakeMode produce the exact frozen 0.7.33 (38c519d1) JSON fixture payload."""
    _app()
    with open(_FIXTURE_PATH, encoding="utf-8") as fh:
        fixture = json.load(fh)
    assert fixture["_meta"]["source_commit"] == "38c519d1"
    expected_payload = fixture["payload"]

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

    assert composed_payload == expected_payload
    take.close()


# ===== SNAPSMACK EOF =====
