"""Tests for COLD TAKE typing lag (thumb regen skip), aspect-fit tray tiles,
and tray drag-drop / used-photo filtering / IMG button.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
from pathlib import Path
from PIL import Image

from PySide6.QtCore import Qt
from PySide6.QtGui import QPixmap
from PySide6.QtWidgets import QApplication

import sumna_offline as O
from coldsnap_qt.mode_take import TakeMode
from coldsnap_qt.photo_tray import PhotoThumbTile


def _app():
    return QApplication.instance() or QApplication([])


def _make_photo(path: Path, size=(300, 150), color=(40, 120, 200)) -> str:
    img = Image.new("RGB", size, color)
    img.save(path, "JPEG")
    return str(path)


def test_generate_draft_thumbs_skips_existing_thumbs(tmp_path, monkeypatch):
    p = _make_photo(tmp_path / "wide.jpg", (400, 200))
    draft = O.Draft(draft_id="d1", kind=O.KIND_SMACKTALK, mode=O.MODE_SMACKTALK)
    draft.images = [O.DraftImage(local_path=p, filename="wide.jpg")]

    O.generate_draft_thumbs(draft)
    assert draft.images[0].width == 400
    assert draft.images[0].height == 200
    assert os.path.isfile(draft.images[0].thumb_square)
    assert os.path.isfile(draft.images[0].thumb_aspect)

    calls = []
    monkeypatch.setattr(O.snap_thumbs, "generate_thumbs", lambda *a, **kw: calls.append(a))
    O.generate_draft_thumbs(draft)
    assert calls == [], "second autosave must not re-decode/re-encode existing thumbs"

    O.generate_draft_thumbs(draft, force=True)
    assert len(calls) == 1


def test_photo_thumb_tile_preserves_aspect_ratio():
    _app()
    wide = QPixmap(120, 60)
    wide.fill(Qt.red)
    tile = PhotoThumbTile(0, wide, tile_size=88)
    tile.resize(88, 88)
    rendered = tile.grab().toImage()
    # Top band above centered 2:1 image should remain background, not red
    top_pixel = rendered.pixelColor(44, 6)
    center_pixel = rendered.pixelColor(44, 44)
    assert center_pixel.red() > 200
    assert top_pixel.red() < 100


def test_tray_drop_hides_used_photo_and_restores_on_remove(tmp_path):
    _app()
    p1 = _make_photo(tmp_path / "one.jpg", (300, 200), (200, 40, 40))
    p2 = _make_photo(tmp_path / "two.jpg", (200, 300), (40, 200, 40))

    take = TakeMode(lambda: {"url": ""})
    take._add_photo_paths([p1, p2], insert=False)
    assert take.bucket_col.count() == 2

    # TWIGGY drop inserts [img:bucket:1|full|center] and removes photo 1 from tray
    take.body._set_biggie(False)
    im1 = take._bucket[0]
    take._drop_tray_photo_twiggy(im1.asset_uuid, 0)
    assert "[img:bucket:1|full|center]" in take.body.editor.toPlainText()
    assert take.bucket_col.count() == 1
    assert "1 left" in take.photos_sec.header.text()

    # Removing the shortcode returns photo 1 to the tray
    take.body.editor.setPlainText("")
    assert take.bucket_col.count() == 2
    assert "2 left" in take.photos_sec.header.text()

    # BIGGIE drop inserts asset:<uuid> and removes photo 2 from tray
    take.body._set_biggie(True)
    im2 = take._bucket[1]
    take._drop_tray_photo_biggie(im2.asset_uuid)
    assert take.bucket_col.count() == 1
    assert "1 left" in take.photos_sec.header.text()

    # IMG action opens photos tray instead of Media Library picker
    take.photos_tray.set_open(False)
    img_action = next(a for a in take._visual_block_actions() if a.tag == "IMG")
    img_action.callback()
    assert take.photos_tray._opened is True
# ===== SNAPSMACK EOF =====
