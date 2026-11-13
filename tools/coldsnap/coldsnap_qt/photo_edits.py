"""Non-destructive COLD TAKE photo-edit derivatives.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import hashlib
import os
import tempfile

from PIL import Image, ImageOps


def edit_state(image) -> tuple:
    """The stable, serializable edit instructions carried by a DraftImage."""
    return (
        bool(getattr(image, "edit_flip_h", False)),
        bool(getattr(image, "edit_flip_v", False)),
        int(getattr(image, "edit_crop_left", 0) or 0),
        int(getattr(image, "edit_crop_top", 0) or 0),
        int(getattr(image, "edit_crop_right", 0) or 0),
        int(getattr(image, "edit_crop_bottom", 0) or 0),
    )


def has_edits(image) -> bool:
    return any(edit_state(image))


def source_path(image) -> str:
    edited = os.path.abspath(str(getattr(image, "slapper_edited_path", "") or ""))
    if (bool(getattr(image, "slapper_use_edited", False)) and edited
            and os.path.isfile(edited)):
        return edited
    original = os.path.abspath(str(getattr(image, "original_path", "") or ""))
    if original and os.path.isfile(original):
        return original
    return os.path.abspath(str(getattr(image, "local_path", "") or ""))


def _crop_box(width: int, height: int, state: tuple) -> tuple:
    _, _, left, top, right, bottom = state
    left = max(0, min(90, left))
    top = max(0, min(90, top))
    right = max(0, min(90, right))
    bottom = max(0, min(90, bottom))
    # Leave at least five percent in each direction, even if a hand-typed
    # draft contains values the dialog itself would refuse.
    if left + right > 95:
        right = max(0, 95 - left)
    if top + bottom > 95:
        bottom = max(0, 95 - top)
    x1 = int(round(width * left / 100.0))
    y1 = int(round(height * top / 100.0))
    x2 = max(x1 + 1, int(round(width * (100 - right) / 100.0)))
    y2 = max(y1 + 1, int(round(height * (100 - bottom) / 100.0)))
    return x1, y1, min(width, x2), min(height, y2)


def render_non_destructive(image) -> str:
    """Render edit instructions to a disposable derivative and return its path.

    The source path and its bytes are never changed.  When all instructions are
    cleared, local_path returns to the source rather than producing another file.
    """
    source = source_path(image)
    if not source or not os.path.isfile(source):
        raise FileNotFoundError(source or "No source photograph")
    if not getattr(image, "original_path", ""):
        image.original_path = source
    state = edit_state(image)
    if not any(state):
        image.local_path = source
        return source

    stamp = os.stat(source).st_mtime_ns
    token = hashlib.sha256(
        repr((source, stamp, state)).encode("utf-8")).hexdigest()[:16]
    folder = os.path.join(tempfile.gettempdir(), "coldsnap-photo-edits")
    os.makedirs(folder, exist_ok=True)
    target = os.path.join(folder, f"{getattr(image, 'asset_uuid', 'photo')}-{token}.jpg")

    if not os.path.isfile(target):
        with Image.open(source) as opened:
            result = ImageOps.exif_transpose(opened).convert("RGB")
            if state[0]:
                result = ImageOps.mirror(result)
            if state[1]:
                result = ImageOps.flip(result)
            result = result.crop(_crop_box(result.width, result.height, state))
            result.save(target, "JPEG", quality=95, optimize=True)

    with Image.open(target) as rendered:
        image.width, image.height = rendered.size
    image.local_path = target
    image.thumb_square = ""
    image.thumb_aspect = ""
    return target


# ===== SNAPSMACK EOF =====
