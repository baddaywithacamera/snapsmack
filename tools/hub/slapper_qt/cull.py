"""Picking the keeper out of a burst, by measurement rather than opinion.

"Which is the best photograph" is a judgement and SNAP SLAPPER does not have
one. "Which of these five frames of the same thing is sharpest, and which is
badly exposed" is arithmetic, and that is the question this answers: group the
frames taken within a few seconds of each other, measure each, and nominate
one per group.

When People is installed the sharpness is measured ON THE FACE, because a
portrait with a crisp background and a soft face is the frame to throw away
and a whole-frame number cannot tell the difference.

Nothing here moves, renames, rewrites or deletes a photograph. It nominates;
the photographer decides.
"""

import os

from PIL import Image, ImageOps

import photo_manager
from . import organizer_ops

BURST_SECONDS = 3.0

# Measured, on projects/snapsmack-ca/img/sean-paddleboard.jpg, blurring exactly
# the face the detector found: measuring the detector's box plus 25% context
# separated the sharp frame from the soft-faced one by only 1.8x, because the
# crisp hair and background in that margin drowned the face. Measuring the box
# itself separated them by 385x. photo_manager.sharpness already trims 3% off
# each edge, so the box needs no further padding.
FACE_MARGIN = 0.0

# Exposure. Mean brightness is a poor test and was nearly a harmful one: the
# real photograph above has a mean of 28.3, so a floor of 28 would have called
# a correctly exposed photograph too dark to keep. What a photographer means by
# blown or crushed is how much of the frame has NO detail left, so that is what
# is measured. Measured values: that photograph 0.04% white / 0.42% black;
# brightened 2.5x, 2% white; a synthetic frame brightened until it is obviously
# gone, 82% white; a near-black frame, 18% black with a mean of 5.7.
HIGHLIGHT_CLIP_VETO = 0.10      # a tenth of the frame pure white
SHADOW_CLIP_VETO = 0.30
DARK_MEAN_VETO = 12.0


def burst_groups(paths, seconds=BURST_SECONDS):
    """Group paths taken within ``seconds`` of each other, in time order.

    Capture time comes from EXIF, falling back to the file's own timestamp,
    which is what organizer_ops.capture_date already does for importing.
    """
    stamped = []
    for path in paths:
        try:
            stamped.append((organizer_ops.capture_date(path).timestamp(), path))
        except (OSError, ValueError):
            continue
    stamped.sort()
    groups = []
    for when, path in stamped:
        if groups and when - groups[-1][-1][0] <= seconds:
            groups[-1].append((when, path))
        else:
            groups.append([(when, path)])
    return [[path for _when, path in group] for group in groups]


def face_boxes(store, path):
    """Face boxes for one photograph from the People store, or an empty list."""
    if store is None:
        return []
    try:
        entry = store.photos.get(store.key(path)) or {}
    except Exception:  # noqa: BLE001
        return []
    return [row.get("box") for row in entry.get("faces", []) if row.get("box")]


def clipping(photo):
    """What fraction of the frame has no highlight, and no shadow, detail."""
    import numpy as np
    grey = np.asarray(ImageOps.grayscale(photo), dtype=np.uint8)
    if not grey.size:
        return 0.0, 0.0
    return (float((grey >= 250).mean()), float((grey <= 5).mean()))


def measure(path, boxes=()):
    """Sharpness, exposure, and where the sharpness was measured.

    Returns ``{"path", "sharpness", "brightness", "highlights", "shadows",
    "on_faces", "faces"}``.
    """
    with Image.open(path) as source:
        photo = ImageOps.exif_transpose(source).convert("RGB")
        highlights, shadows = clipping(photo)
        brightness = photo_manager.quality_measures([path])
        bright = brightness[0]["brightness"] if brightness else 0.0
        common = {"path": path, "brightness": bright,
                  "highlights": round(highlights, 4),
                  "shadows": round(shadows, 4)}
        if boxes:
            scores = []
            for box in boxes:
                x, y, width, height = (int(round(value)) for value in box[:4])
                if width < 8 or height < 8:
                    continue
                pad_x, pad_y = int(width * FACE_MARGIN), int(height * FACE_MARGIN)
                crop = photo.crop((max(0, x - pad_x), max(0, y - pad_y),
                                   min(photo.width, x + width + pad_x),
                                   min(photo.height, y + height + pad_y)))
                scores.append(photo_manager.sharpness(crop))
            if scores:
                # The sharpest face in the frame: in a group shot one person
                # turning away should not condemn the whole frame.
                return dict(common, sharpness=max(scores), on_faces=True,
                            faces=len(scores))
        return dict(common, sharpness=photo_manager.sharpness(photo),
                    on_faces=False, faces=0)


def _usable(row):
    """Whether the frame is worth nominating on exposure grounds."""
    return (row.get("highlights", 0.0) <= HIGHLIGHT_CLIP_VETO and
            row.get("shadows", 0.0) <= SHADOW_CLIP_VETO and
            row["brightness"] >= DARK_MEAN_VETO)


def rank(measurements):
    """Best first. More faces beats fewer; then sharpness; exposure can veto.

    A frame that is too dark or blown out is ranked below every usable frame
    rather than discarded, so a burst where every frame is dark still has a
    nomination instead of none.
    """
    return sorted(
        measurements,
        key=lambda row: (_usable(row), row["faces"], row["sharpness"]),
        reverse=True)


def reason_for(best, group_size):
    """One plain sentence about why this frame was nominated."""
    where = "measured on the face" if best["on_faces"] else "measured overall"
    if group_size == 1:
        return f"the only frame in its group, {where}"
    text = f"sharpest of {group_size}, {where}"
    if not _usable(best):
        text += " — but every frame in this group is badly exposed"
    return text


def pick_bursts(paths, store=None, seconds=BURST_SECONDS, progress=None):
    """Nominate one frame per burst.

    Returns a list of ``{"picked", "others", "reason", "measurements"}``, one
    per group, in the order the groups were taken. Reads only.
    """
    results = []
    groups = burst_groups(paths, seconds)
    total = sum(len(group) for group in groups)
    done = 0
    for group in groups:
        measurements = []
        for path in group:
            try:
                measurements.append(measure(path, face_boxes(store, path)))
            except Exception:  # noqa: BLE001 — an unreadable frame is skipped
                pass
            done += 1
            if progress is not None and not progress(done, total, path):
                return results
        if not measurements:
            continue
        ordered = rank(measurements)
        results.append({
            "picked": ordered[0]["path"],
            "others": [row["path"] for row in ordered[1:]],
            "reason": reason_for(ordered[0], len(measurements)),
            "measurements": ordered,
        })
    return results


# ===== SNAPSMACK EOF =====
