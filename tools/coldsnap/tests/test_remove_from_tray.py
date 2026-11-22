"""Regression tests for removing photos from the COLD TAKE photos tray."""

import os
import sys
from pathlib import Path

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtCore import Qt
from PySide6.QtGui import QImage
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from sumna_offline import DraftImage
from coldsnap_qt.mode_take import TakeMode
from coldsnap_qt.photo_tray import PhotoThumbTile


def _app():
    return QApplication.instance() or QApplication([])


def _make_take(tmp_path: Path, count: int = 3):
    _app()
    take = TakeMode(lambda: {})
    take.photos_tray.set_open(True)
    paths = []
    for i in range(count):
        p = tmp_path / f"photo_{i + 1}.jpg"
        img = QImage(32, 24, QImage.Format_RGB32)
        img.fill(Qt.darkGreen)
        assert img.save(str(p), "JPEG")
        paths.append(p)
        take._bucket.append(DraftImage(
            local_path=str(p),
            filename=p.name,
            asset_uuid=f"uuid-{i + 1}",
            is_cover=(i == 0),
        ))
    take._cover_idx = 0
    take._refresh_bucket()
    return take, paths


def _tiles(take: TakeMode) -> list[PhotoThumbTile]:
    tiles = []
    for i in range(take.bucket_col.count()):
        w = take.bucket_col.itemAt(i).widget()
        if isinstance(w, PhotoThumbTile):
            tiles.append(w)
    return tiles


def test_single_remove_via_tile_signal_keeps_file_and_updates_count(tmp_path):
    take, paths = _make_take(tmp_path, count=3)
    assert take.photos_sec.header.text() == "THE PHOTOS — 3 left"
    tiles = _tiles(take)
    assert len(tiles) == 3

    # Remove middle tile via tile.removeRequested (right-click "Remove from tray")
    tiles[1].removeRequested.emit(1)

    assert [im.filename for im in take._bucket] == ["photo_1.jpg", "photo_3.jpg"]
    assert take.photos_sec.header.text() == "THE PHOTOS — 2 left"
    assert [t._index for t in _tiles(take)] == [0, 1]
    assert paths[1].exists()  # Never delete from disk


def test_multiselect_delete_key_removes_ticked_only_when_tray_focused(tmp_path):
    take, paths = _make_take(tmp_path, count=4)

    # Select index 1, then Ctrl/tick-select index 3
    take._select_photo(1, toggle=False)
    take._select_photo(3, toggle=True)
    assert sorted(take._selected_photo_indices) == [1, 3]

    # Focus in title_edit: Delete must NOT remove tray items
    take.title_edit.setText("abc")
    take.title_edit.setCursorPosition(0)
    take.title_edit.setFocus()
    QTest.keyClick(take.title_edit, Qt.Key_Delete)
    assert len(take._bucket) == 4

    # Focus in tray: Delete removes both ticked items
    take.photos_tray.setFocus()
    QTest.keyClick(take.photos_tray, Qt.Key_Delete)
    assert [im.filename for im in take._bucket] == ["photo_1.jpg", "photo_3.jpg"]
    assert take.photos_sec.header.text() == "THE PHOTOS — 2 left"
    assert paths[1].exists() and paths[3].exists()


def test_removing_featured_photo_clears_featured_flag(tmp_path):
    take, _ = _make_take(tmp_path, count=3)
    assert take._cover_idx == 0
    assert any(t._is_cover for t in _tiles(take))

    assert take._remove_from_tray([0]) is True

    assert take._cover_idx == -1
    assert not any(t._is_cover for t in _tiles(take))
    assert len(take._bucket) == 2


def test_removing_in_post_photo_prompts_and_respects_choices(tmp_path):
    take, _ = _make_take(tmp_path, count=3)
    take.body.set_state(
        "",
        '[{"type":"image","asset_uuid":"uuid-1","caption":""},'
        '{"type":"mosaic","layout":"2up","order":["uuid-2","uuid-3"]}]',
    )
    assert take._used_photo_indices() == {0, 1, 2}

    # 1. Cancel leaves everything intact
    assert take._remove_from_tray([0], in_post_fn=lambda _: "cancel") is False
    assert len(take._bucket) == 3

    # 2. Leave in post skips used items (and removes unused if mixed)
    assert take._remove_from_tray([0], in_post_fn=lambda _: "leave_in_post") is False
    assert len(take._bucket) == 3

    # 3. Remove from post too strips image + mosaic ref and removes from tray
    assert take._remove_from_tray([0, 1], in_post_fn=lambda _: "remove_both") is True
    assert [im.asset_uuid for im in take._bucket] == ["uuid-3"]
    blocks = take.body.authoring_blocks()
    assert [b.get("type") for b in blocks] == ["mosaic"]
    assert blocks[0]["order"] == ["uuid-3"]
