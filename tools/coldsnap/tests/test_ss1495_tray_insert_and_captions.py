"""Tests for SS-1495 (COLD TAKE tray insert buttons and image captions):
1. One photo selected in the tray: shows INSERT INTO POST button; clicking it
   places the photo at the cursor (same result as dragging); photo leaves the
   tray and returns if removed.
2. Two or more selected in the tray: shows INSERT MOSAIC button; clicking it
   inserts them as a mosaic block reusing the existing [mosaic] marker pattern;
   placed photos leave the tray and return if removed.
3. Image captions: "Show caption" toggle on each placed image in BIGGIE (and
   [img:...|caption=...] shortcode equivalent in TWIGGY), defaulting to the
   photo's existing caption/title, rendered in BIGGIE preview with skin caption
   typography, and resolved for API publishing.
"""

import os
import sys

from PIL import Image
from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

_SHARED = os.path.join(os.path.dirname(_ROOT), "_shared")
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

from coldsnap_qt import biggie, theme
from coldsnap_qt.canvas import _LocalImageDialog
from coldsnap_qt.mode_take import TakeMode
from sumna_post import SmacktalkPoster


def _app():
    return QApplication.instance() or QApplication([])


def _make_photo(path, size=(600, 400), color=(60, 120, 180)):
    im = Image.new("RGB", size, color)
    im.save(str(path), "JPEG")
    return str(path)


def test_ss1495_tray_single_select_insert_into_post_and_remove_returns_to_tray(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    p1 = _make_photo(tmp_path / "one.jpg")
    p2 = _make_photo(tmp_path / "two.jpg")

    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    mode._clear()
    try:
        mode._add_photo_paths([p1, p2], insert=False)
        app.processEvents()

        # 1 photo selected by default -> INSERT INTO POST visible, INSERT MOSAIC hidden
        assert mode.selected_tray_indices() == [0]
        assert not mode.insert_into_post_btn.isHidden()
        assert mode.insert_mosaic_btn.isHidden()
        assert mode.bucket_col.count() == 2

        # Click INSERT INTO POST -> places photo 0 at cursor, photo leaves tray
        mode.insert_into_post_btn.click()
        app.processEvents()

        blocks = mode.body.canvas.to_blocks()
        img_blocks = [b for b in blocks if b.get("type") == "image"]
        assert len(img_blocks) == 1
        assert img_blocks[0]["asset_uuid"] == mode._bucket[0].asset_uuid
        assert 0 in mode._used_photo_indices()
        assert mode.bucket_col.count() == 1
        assert "1 left" in mode.photos_sec.header.text()

        # Remove placed image from canvas -> photo returns to tray
        mode.body.canvas.from_blocks([{"type": "para", "text": "Removed photo"}])
        mode._changed()
        app.processEvents()

        assert 0 not in mode._used_photo_indices()
        assert mode.bucket_col.count() == 2
        assert "2 left" in mode.photos_sec.header.text()
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()


def test_ss1495_tray_multi_select_insert_mosaic_biggie_and_twiggy(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    p1 = _make_photo(tmp_path / "m1.jpg")
    p2 = _make_photo(tmp_path / "m2.jpg")
    p3 = _make_photo(tmp_path / "m3.jpg")

    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    mode._clear()
    try:
        mode._add_photo_paths([p1, p2, p3], insert=False)
        app.processEvents()

        # Multi-select photos 0 and 2 in tray -> INSERT MOSAIC visible, INSERT INTO POST hidden
        mode._select_photo(0, toggle=False)
        mode._select_photo(2, toggle=True)
        app.processEvents()

        assert mode.selected_tray_indices() == [0, 2]
        assert mode.insert_into_post_btn.isHidden()
        assert not mode.insert_mosaic_btn.isHidden()

        # Click INSERT MOSAIC in BIGGIE -> inserts mosaic block, both photos leave tray
        mode.insert_mosaic_btn.click()
        app.processEvents()

        blocks = mode.body.canvas.to_blocks()
        mosaics = [b for b in blocks if b.get("type") == "mosaic"]
        assert len(mosaics) == 1
        assert mosaics[0]["order"] == [mode._bucket[0].asset_uuid, mode._bucket[2].asset_uuid]
        assert mode._used_photo_indices() == {0, 2}
        assert mode.bucket_col.count() == 1
        assert "1 left" in mode.photos_sec.header.text()

        # Serializes to existing [mosaic=1,3 layout=asymmetric] marker pattern
        wired = mode._wire_blocks(blocks)
        serialized = biggie.serialize_blocks(wired)
        assert "[mosaic=1,3 layout=asymmetric]" in serialized

        # Removing mosaic block restores both photos to tray
        mode.body.canvas.from_blocks([])
        mode._changed()
        app.processEvents()
        assert mode._used_photo_indices() == set()
        assert mode.bucket_col.count() == 3

        # Also verify TWIGGY mode INSERT MOSAIC emits [mosaic=1,2 layout=asymmetric]
        mode.body._set_biggie(False)
        mode.select_tray_photos([0, 1])
        assert not mode.insert_mosaic_btn.isHidden()
        mode.insert_mosaic_btn.click()
        app.processEvents()

        assert "[mosaic=1,2 layout=asymmetric]" in mode.body.editor.toPlainText()
        assert mode._used_photo_indices() == {0, 1}
        assert mode.bucket_col.count() == 1
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()


def test_ss1495_image_caption_toggle_default_preview_and_publish(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    app = _app()
    app.setStyleSheet(theme.stylesheet())

    p1 = _make_photo(tmp_path / "sunset.jpg", size=(600, 400))
    mode = TakeMode(lambda: {"url": "https://example.com"})
    mode.resize(1400, 900)
    mode.show()
    app.processEvents()
    mode._clear()
    try:
        mode._add_photo_paths([p1], insert=False)
        mode._bucket[0].title = "Sunset over the grain elevator"
        mode._refresh_bucket()

        # Insert photo into BIGGIE -> caption defaults to photo's existing title/caption
        mode.insert_into_post_btn.click()
        app.processEvents()

        canvas = mode.body.canvas
        canvas.set_skin_typography(
            body_font="Georgia",
            body_pt=12.5,
            caption_font="Georgia",
            caption_pt=11.0,
            caption_color="#555555",
            caption_italic=True,
        )
        blocks0 = canvas.to_blocks()
        img_b0 = next(b for b in blocks0 if b.get("type") == "image")
        assert img_b0["caption"] == "Sunset over the grain elevator"
        assert not img_b0.get("show_caption", False)

        # Verify _LocalImageDialog defaults to the photo's caption/title and exposes "Show caption"
        dlg = _LocalImageDialog(canvas, img_b0)
        assert dlg.caption.text() == "Sunset over the grain elevator"
        assert not dlg.show_caption.isChecked()
        dlg.close()

        # Find object position and measure height before/after toggling "Show caption"
        doc = canvas.document()
        lay = doc.documentLayout()
        b_img = doc.begin()
        while b_img.isValid() and "\ufffc" not in b_img.text():
            b_img = b_img.next()
        assert b_img.isValid()
        h_without_cap = lay.blockBoundingRect(b_img).height()

        # Enable "Show caption" and customize the caption text
        assert canvas.set_image_caption(
            b_img.position(),
            show_caption=True,
            caption="Sunset over the grain elevator — October",
        )
        app.processEvents()

        # Re-locate image block after replace_at and confirm preview reserves caption height
        b_img2 = doc.begin()
        while b_img2.isValid() and "\ufffc" not in b_img2.text():
            b_img2 = b_img2.next()
        h_with_cap = lay.blockBoundingRect(b_img2).height()
        assert h_with_cap >= h_without_cap + 20
        pm = canvas.grab()
        assert not pm.isNull()

        # Wire blocks -> serializes to TWIGGY shortcode equivalent [img:bucket:1|...|caption=...]
        wired = mode._wire_blocks(canvas.to_blocks())
        twiggy_text = biggie.serialize_blocks(wired)
        assert "[img:bucket:1|full|center|caption=Sunset over the grain elevator — October]" in twiggy_text
        # Edited caption also synced back to bucket image metadata
        assert mode._bucket[0].caption == "Sunset over the grain elevator — October"

        # Round-trip TWIGGY shortcode back into BIGGIE blocks
        parsed = biggie.parse_body("Intro paragraph\n\n" + twiggy_text)
        assert len(parsed) == 2
        assert parsed[1]["type"] == "image"
        assert parsed[1]["show_caption"] is True
        assert parsed[1]["caption"] == "Sunset over the grain elevator — October"

        # Publish resolution turns [img:bucket:1|...|caption=...] into <figure>[img:42|...]<figcaption>...</figcaption></figure>
        poster = SmacktalkPoster("https://example.com", "a" * 64)
        resolved = poster._resolve_bucket_images("Intro paragraph\n\n" + twiggy_text, [42])
        assert '<figure class="snap-image-figure">[img:42|full|center]<figcaption>Sunset over the grain elevator — October</figcaption></figure>' in resolved
        assert "<p>Intro paragraph</p>" in resolved
    finally:
        mode.close()
        mode.deleteLater()
        app.processEvents()

# ===== SNAPSMACK EOF =====
