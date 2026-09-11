"""IMG in BIGGIE is a picture picker from the site's cached Media Gallery,
and the chosen image paints in the canvas. Headless (offscreen Qt, sandbox).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys
import tempfile

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="gallery-test-")
_TOOL = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, _TOOL)
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(_TOOL)), "tools", "_shared"))

from PySide6.QtWidgets import QApplication  # noqa: E402
from PySide6.QtGui import QImage, QColor  # noqa: E402
from PySide6.QtCore import Qt  # noqa: E402

app = QApplication.instance() or QApplication([])

import snap_library  # noqa: E402
from coldsnap_qt.gallery_picker import GalleryPicker, gallery_images, gallery_thumb_path  # noqa: E402
from coldsnap_qt.canvas import BiggieCanvas  # noqa: E402

SITE = "https://example.test"


def _seed():
    d = tempfile.mkdtemp()
    out = []
    for n, (img_id, colour, title) in enumerate([("42", "#ff0000", "Toast"), ("43", "#00ff00", "Kettle")]):
        img = QImage(300, 200, QImage.Format_RGB32)
        img.fill(QColor(colour))
        p = os.path.join(d, f"p{n}.png")
        img.save(p)
        stored = snap_library.store_media(SITE, p, orig_name=f"p{n}.png", ext=".png")
        snap_library.upsert_asset(SITE, {**stored, "title": title, "source_ref": f"img:{img_id}"})
        out.append(img_id)
    # an asset with no site id yet must NOT appear as pickable
    img = QImage(10, 10, QImage.Format_RGB32)
    p = os.path.join(d, "nosite.png")
    img.save(p)
    stored = snap_library.store_media(SITE, p, orig_name="nosite.png", ext=".png")
    snap_library.upsert_asset(SITE, {**stored, "title": "Unlinked", "source_ref": ""})
    return out


def _checks():
    n = 0
    ids = _seed()
    imgs = gallery_images(SITE)
    assert sorted(i["img_id"] for i in imgs) == sorted(ids), imgs
    assert all(os.path.isfile(i["path"]) for i in imgs)
    n += 2
    assert os.path.isfile(gallery_thumb_path(SITE, "42"))
    assert gallery_thumb_path(SITE, "999") == ""
    n += 2

    dlg = GalleryPicker(None, SITE, {"img_id": "43", "size": "wall", "align": "left"})
    assert dlg.grid.count() == 2, dlg.grid.count()
    assert all(not dlg.grid.item(i).icon().isNull() for i in range(dlg.grid.count())), "thumbnails missing"
    assert dlg.img_id.text() == "43" and dlg.grid.currentItem().data(Qt.UserRole) == "43"
    n += 3
    dlg.search.setText("toa")
    assert dlg.grid.count() == 1 and dlg.grid.item(0).data(Qt.UserRole) == "42"
    dlg.grid.setCurrentRow(0)
    assert dlg.values() == {"img_id": "42", "size": "wall", "align": "left"}
    n += 2

    # the canvas paints the real picture for a known id
    cv = BiggieCanvas()
    cv.set_site_provider(lambda: SITE)
    cv.resize(900, 600)
    cv.show()
    cv.insert_image("42", "full", "center")
    shot = cv.grab().toImage()
    assert shot.pixelColor(450, 120).red() > 200, "inline image did not paint"
    n += 1
    # this post's own photos come first and paint from the bucket
    bucket = [os.path.join(tempfile.mkdtemp(), "b1.png")]
    bimg = QImage(300, 200, QImage.Format_RGB32)
    bimg.fill(QColor("#0000ff"))
    bimg.save(bucket[0])
    dlg2 = GalleryPicker(None, SITE, {}, bucket=bucket)
    assert dlg2.grid.count() == 3 and dlg2.grid.item(0).data(Qt.UserRole) == "bucket:1", dlg2.grid.count()
    assert not dlg2.grid.item(0).icon().isNull()
    dlg2.grid.setCurrentRow(0)
    assert dlg2.values()["img_id"] == "bucket:1"
    cv.set_bucket(bucket)
    cv.clear()
    cv.insert_image("bucket:1", "full", "center")
    shot2 = cv.grab().toImage()
    assert shot2.pixelColor(450, 120).blue() > 200, "bucket photo did not paint"
    n += 4
    # not synced: gallery hidden, bucket still offered
    dlg3 = GalleryPicker(None, "https://never-synced.test", {}, bucket=bucket)
    assert dlg3.grid.count() == 1 and dlg3.grid.item(0).data(Qt.UserRole) == "bucket:1"
    n += 1
    # unknown id: labelled frame, no crash
    cv.insert_image("999", "full", "center")
    cv.grab()
    n += 1
    return n


if __name__ == "__main__":
    print(f"OK - {_checks()} checks passed")
# ===== SNAPSMACK EOF =====
