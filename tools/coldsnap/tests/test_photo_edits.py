"""COLD TAKE non-destructive selected-photo edit checks.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import hashlib
import os
import sys
import tempfile
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import sumna_offline as O  # noqa: E402
from coldsnap_qt.photo_edits import render_non_destructive  # noqa: E402


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def main():
    with tempfile.TemporaryDirectory() as folder:
        source = os.path.join(folder, "source.png")
        # Red left half, blue right half: a horizontal flip is measurable.
        image = Image.new("RGB", (100, 80), "red")
        for x in range(50, 100):
            for y in range(80):
                image.putpixel((x, y), (0, 0, 255))
        image.save(source)
        before = digest(source)

        draft_image = O.DraftImage(
            local_path=source, original_path=source, filename="source.png",
            edit_flip_h=True, edit_crop_left=10, edit_crop_right=20,
            edit_crop_top=25, edit_crop_bottom=0)
        derived = render_non_destructive(draft_image)
        assert derived != source
        assert os.path.isfile(derived)
        assert digest(source) == before, "source photograph changed"
        with Image.open(derived) as result:
            assert result.size == (70, 60), result.size
            # After mirroring, the left side is blue (allow JPEG tolerance).
            r, _g, b = result.getpixel((2, 2))
            assert b > r, (r, b)

        store = O.SessionStore(os.path.join(folder, "sessions"))
        session = store.create("edit proof", O.MODE_SMACKTALK)
        draft = O.Draft(
            draft_id=O._new_id(), kind=O.KIND_SMACKTALK,
            mode=O.MODE_SMACKTALK, title="Proof", images=[draft_image])
        session.add_draft(draft)
        assert Path(draft_image.local_path).parent == Path(session.images_dir)
        assert Path(draft_image.original_path).parent.name == "originals"
        assert digest(draft_image.original_path) == before

        draft_image.edit_flip_h = False
        draft_image.edit_crop_left = 0
        draft_image.edit_crop_right = 0
        draft_image.edit_crop_top = 0
        reset = render_non_destructive(draft_image)
        assert reset == os.path.abspath(draft_image.original_path)
        assert draft_image.local_path == os.path.abspath(draft_image.original_path)
        assert digest(source) == before

        moved = O.DraftImage(
            local_path=source, original_path=os.path.join(folder, "old-machine", "gone.jpg"),
            filename="source.png", edit_flip_v=True)
        moved_result = render_non_destructive(moved)
        assert os.path.isfile(moved_result)
        assert digest(source) == before
    print("OK - source unchanged; flip, crop, reset passed")


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
