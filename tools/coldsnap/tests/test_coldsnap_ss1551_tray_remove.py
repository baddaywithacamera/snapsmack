"""SS-1551: the photo tray can safely remove an accidental photograph.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys

from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

import sumna_offline as O  # noqa: E402
from coldsnap_qt.mode_take import TakeMode  # noqa: E402


def _app():
    return QApplication.instance() or QApplication([])


def _image(name, asset_uuid):
    return O.DraftImage(
        local_path="C:/photos/" + name, filename=name, asset_uuid=asset_uuid,
    )


def test_remove_action_is_named_and_says_the_source_file_is_safe():
    _app()
    mode = TakeMode(lambda: {"url": "https://example.test"})

    assert mode.remove_photo_btn.text() == "Remove photo"
    assert "source file is not deleted" in mode.remove_photo_btn.toolTip()
    assert mode.remove_photo_btn.accessibleName() == "Remove selected photo from draft"
    mode.close()


def test_remove_cleans_uuid_and_legacy_references_and_renumbers_positions(monkeypatch):
    _app()
    mode = TakeMode(lambda: {"url": "https://example.test"})
    mode._bucket = [
        _image("one.jpg", "asset-one"),
        _image("mistake.jpg", "asset-mistake"),
        _image("three.jpg", "asset-three"),
    ]
    mode._selected_photo_idx = 1
    mode._cover_idx = 2
    mode.body.biggie.from_blocks([
        {"type": "image", "img_id": "asset:asset-mistake",
         "asset_uuid": "asset-mistake", "size": "full", "align": "center"},
        {"type": "mosaic", "order": ["asset-one", "asset-mistake", "asset-three"],
         "layout": "one-left"},
        {"type": "image", "img_id": "bucket:3", "size": "full", "align": "center"},
        {"type": "columns", "ratio": "equal", "cols": [[
            {"type": "mosaic", "order": [1, 2, 3], "layout": "asymmetric"},
        ], []]},
    ])
    changed = []
    monkeypatch.setattr(mode, "_changed", lambda: changed.append(True))

    mode._remove(1)

    assert [image.asset_uuid for image in mode._bucket] == ["asset-one", "asset-three"]
    assert mode._selected_photo_idx == 1
    assert mode._cover_idx == 1
    assert mode.body.authoring_blocks() == [
        {"type": "mosaic", "order": ["asset-one", "asset-three"],
         "layout": "one-left"},
        {"type": "image", "img_id": "bucket:2", "size": "full", "align": "center"},
        {"type": "columns", "cols": [[
            {"type": "mosaic", "order": [1, 2], "layout": "asymmetric"},
        ], []], "ratio": "equal"},
    ]
    assert changed
    mode.close()


def test_removing_only_photo_drops_empty_mosaic_but_not_the_source(tmp_path, monkeypatch):
    _app()
    source = tmp_path / "only.jpg"
    source.write_bytes(b"photograph remains here")
    mode = TakeMode(lambda: {"url": "https://example.test"})
    mode._bucket = [O.DraftImage(
        local_path=str(source), filename=source.name, asset_uuid="only-asset",
    )]
    mode._selected_photo_idx = 0
    mode.body.biggie.from_blocks([
        {"type": "mosaic", "order": ["only-asset"], "layout": "asymmetric"},
    ])
    monkeypatch.setattr(mode, "_changed", lambda: None)

    mode._remove(0)

    assert mode._bucket == []
    assert mode.body.authoring_blocks() == []
    assert source.read_bytes() == b"photograph remains here"
    mode.close()


# ===== SNAPSMACK EOF =====
