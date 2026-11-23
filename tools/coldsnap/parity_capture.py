"""Built-executable parity capture for the COLD SNAP release gate.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import json
import os
from pathlib import Path
import tempfile


def run(output_dir: str) -> int:
    from PIL import Image
    from PySide6.QtCore import QTimer, Qt
    from PySide6.QtWidgets import QApplication, QDialog, QPushButton

    import sumna_offline as O
    from coldsnap_qt.mode_take import MosaicPreview, TakeMode
    from coldsnap_qt.photo_tray import PhotoThumbTile

    out = Path(output_dir).resolve()
    out.mkdir(parents=True, exist_ok=True)
    home = out / "sandbox-home"
    home.mkdir(exist_ok=True)
    os.environ["SNAPSMACK_HOME"] = str(home)

    app = QApplication.instance() or QApplication([])
    mode = TakeMode(lambda: {})
    mode.resize(1440, 900)
    mode.show()
    app.processEvents()

    fixture_dir = Path(tempfile.mkdtemp(prefix="coldsnap-parity-"))
    sizes = [(1200, 800), (800, 1200), (900, 900), (1400, 800), (800, 1100), (1100, 760)]
    paths = []
    for index, size in enumerate(sizes):
        path = fixture_dir / f"parity-{index + 1}.jpg"
        Image.new("RGB", size, (35 + index * 28, 80 + index * 17, 145 - index * 12)).save(path)
        paths.append(str(path))

    mode._add_photo_paths(paths, insert=False)
    for image, size in zip(mode._bucket, sizes):
        image.width, image.height = size
    mode._cover_idx = 1
    mode._selected_photo_idx = 1
    mode._refresh_bucket()
    mode._populate_photo_tools()
    mode.body._set_biggie(True)
    identities = [str(image.asset_uuid) for image in mode._bucket]
    mode.body.canvas.from_blocks([
        {"type": "para", "text": "The first paragraph proves the editor keeps readable space between blocks."},
        {"type": "image", "asset_uuid": identities[0], "img_id": "asset:" + identities[0], "width_ratio": 0.72},
        {"type": "para", "text": "The second paragraph remains separate below the photograph."},
        {"type": "mosaic", "order": identities[1:5], "layout": "asymmetric"},
    ])
    mode.title_edit.setText("COLD SNAP 0.7.57 parity gate")
    app.processEvents()
    mode.grab().save(str(out / "01-editor-parity.png"), "PNG")

    tiles = mode.findChildren(PhotoThumbTile)
    tile_badges = [
        {"index": tile._index, "orientation": tile._orientation, "ratio": tile._ratio_text}
        for tile in tiles
    ]
    buttons = [button.text() for button in mode.findChildren(QPushButton)]
    checks = {
        "ratio_badges": len({tile._index for tile in tiles}) >= 6 and all(
            tile._orientation in {"P", "L", "SQ"}
            for tile in tiles
        ),
        "featured_photo_control": "★ Featured photo (landing tile)" in buttons and mode._cover_idx == 1,
        "flip_controls": "⇆ Flip H" in buttons and "⇅ Flip V" in buttons,
        "block_count": len(mode.body.authoring_blocks()) == 4,
    }

    captured = {}

    def capture_mosaic():
        dialog = app.activeModalWidget()
        if not isinstance(dialog, QDialog):
            return
        dialog.grab().save(str(out / "02-mosaic-parity.png"), "PNG")
        dialog_buttons = [button.text() for button in dialog.findChildren(QPushButton)]
        dialog_tiles = dialog.findChildren(PhotoThumbTile)
        captured.update({
            "mosaic_maximizable": bool(dialog.windowFlags() & Qt.WindowMaximizeButtonHint),
            "mosaic_wysiwyg": bool(dialog.findChildren(MosaicPreview)),
            "mosaic_photo_thumbnails": len({tile._index for tile in dialog_tiles}) >= 4,
            "mosaic_thumbnail_count": len({tile._index for tile in dialog_tiles}),
            "mosaic_move_controls": "Move earlier" in dialog_buttons and "Move later" in dialog_buttons,
        })
        dialog.reject()

    QTimer.singleShot(250, capture_mosaic)
    mode._mosaic_dialog([], "asymmetric", preselected_order=[0, 1, 2, 3])
    checks.update(captured)

    mode._remote_post_id = 42
    mode._remote_slug = "parity-gate"
    saved = mode._autosave_now()
    checks["autosave_without_errno22"] = bool(saved) and "could not save" not in mode.save_state.text().lower()
    checks["source_files_preserved"] = all(Path(path).is_file() for path in paths)

    payload = {
        "build": __import__("_version").BUILD_VERSION,
        "checks": checks,
        "tile_badges": tile_badges,
        "pass": all(value for key, value in checks.items() if key != "mosaic_thumbnail_count"),
    }
    (out / "parity.json").write_text(json.dumps(payload, indent=2), encoding="utf-8")
    mode.close()
    app.processEvents()
    return 0 if payload["pass"] else 1


# ===== SNAPSMACK EOF =====
