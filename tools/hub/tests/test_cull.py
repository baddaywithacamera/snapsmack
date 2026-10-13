"""Picking the keeper out of a burst: measured, and never destructive."""

import os
import shutil
import sys
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

import piexif
import pytest
from PIL import Image, ImageDraw, ImageFilter

from slapper_qt import cull

HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHOTO = os.path.join(os.path.dirname(os.path.dirname(HUB)),
                     "projects", "snapsmack-ca", "img", "sean-paddleboard.jpg")


def _detailed(size=(1200, 800)):
    photo = Image.new("RGB", size, (140, 150, 160))
    draw = ImageDraw.Draw(photo)
    for x in range(0, size[0], 11):
        draw.line((x, 0, x, size[1]), fill=(15, 15, 15), width=2)
    for y in range(0, size[1], 13):
        draw.line((0, y, size[0], y), fill=(40, 30, 20), width=2)
    return photo


def _write(path, photo, when):
    """Write a JPEG whose EXIF capture time is ``when`` (a struct of 6)."""
    stamp = time.strftime("%Y:%m:%d %H:%M:%S", when)
    exif = {"0th": {}, "Exif": {piexif.ExifIFD.DateTimeOriginal:
                                stamp.encode("ascii")},
            "GPS": {}, "1st": {}, "thumbnail": None}
    photo.save(path, "JPEG", quality=92, exif=piexif.dump(exif))


def _burst(folder, count, start=0, blur=None, brightness=None, gap=1):
    paths = []
    base = time.localtime(1760000000 + start * 600)
    for index in range(count):
        when = time.localtime(time.mktime(base) + index * gap)
        photo = _detailed()
        if blur and index in blur:
            photo = photo.filter(ImageFilter.GaussianBlur(blur[index]))
        if brightness and index in brightness:
            photo = Image.eval(photo, lambda v, b=brightness[index]: min(255, int(v * b)))
        path = os.path.join(folder, f"s{start}-f{index}.jpg")
        _write(path, photo, when)
        paths.append(path)
    return paths


def test_frames_seconds_apart_are_one_burst_and_minutes_apart_are_not(tmp_path):
    first = _burst(str(tmp_path), 3, start=0, gap=1)
    second = _burst(str(tmp_path), 2, start=1, gap=1)      # ten minutes later
    groups = cull.burst_groups(first + second)
    assert [len(group) for group in groups] == [3, 2]
    assert set(groups[0]) == set(first)
    assert set(groups[1]) == set(second)


def test_a_long_gap_inside_the_window_still_chains(tmp_path):
    # Frames 2s apart chain into one burst even across ten of them.
    paths = _burst(str(tmp_path), 10, gap=2)
    assert [len(group) for group in cull.burst_groups(paths, seconds=3.0)] == [10]
    # Tightening the window splits them.
    assert len(cull.burst_groups(paths, seconds=1.0)) == 10


def test_the_sharp_frame_is_nominated(tmp_path):
    paths = _burst(str(tmp_path), 4, blur={0: 6, 2: 3, 3: 8})
    picks = cull.pick_bursts(paths)
    assert len(picks) == 1
    assert os.path.basename(picks[0]["picked"]) == "s0-f1.jpg"
    assert "sharpest of 4" in picks[0]["reason"]
    assert set(picks[0]["others"]) == set(paths) - {picks[0]["picked"]}
    # The ordering is by measurement, worst last.
    scores = [row["sharpness"] for row in picks[0]["measurements"]]
    assert scores == sorted(scores, reverse=True)


def test_a_blown_out_frame_cannot_win_on_sharpness_alone(tmp_path):
    # Frame 0 is sharper but burnt out; frame 1 is slightly soft and usable.
    paths = _burst(str(tmp_path), 2, brightness={0: 4.0}, blur={1: 1})
    exposures = [cull.measure(path) for path in paths]
    # Measured, not assumed: most of frame 0 has no highlight detail left.
    assert exposures[0]["highlights"] > cull.HIGHLIGHT_CLIP_VETO
    assert exposures[1]["highlights"] <= cull.HIGHLIGHT_CLIP_VETO
    assert exposures[0]["sharpness"] > 0
    picks = cull.pick_bursts(paths)
    assert os.path.basename(picks[0]["picked"]) == "s0-f1.jpg"


def test_a_burst_where_everything_is_dark_still_gets_a_nomination(tmp_path):
    paths = _burst(str(tmp_path), 3, brightness={0: .04, 1: .04, 2: .04},
                   blur={0: 5, 2: 5})
    picks = cull.pick_bursts(paths)
    assert len(picks) == 1
    assert picks[0]["picked"] in paths
    assert "badly exposed" in picks[0]["reason"]


def test_nothing_is_moved_renamed_or_rewritten(tmp_path):
    paths = _burst(str(tmp_path), 3, blur={0: 5})
    before = {path: os.stat(path).st_mtime_ns for path in paths}
    cull.pick_bursts(paths)
    assert {path: os.stat(path).st_mtime_ns for path in paths} == before
    assert sorted(os.listdir(tmp_path)) == sorted(
        os.path.basename(path) for path in paths)


def test_progress_can_stop_the_run(tmp_path):
    paths = _burst(str(tmp_path), 3, start=0) + _burst(str(tmp_path), 3, start=1)
    seen = []

    def progress(done, total, path):
        seen.append(path)
        return done < 3          # stop after the first burst

    picks = cull.pick_bursts(paths, progress=progress)
    assert len(seen) == 3
    assert len(picks) <= 1


MODELS = os.environ.get("SLAPPER_FACE_MODELS", "")
HAS_CV2 = __import__("importlib").util.find_spec("cv2") is not None


@pytest.mark.skipif(not (HAS_CV2 and MODELS and os.path.isfile(PHOTO)),
                    reason="needs OpenCV, SLAPPER_FACE_MODELS and the photograph")
def test_sharpness_is_measured_on_the_face_when_people_knows_where_it_is(tmp_path):
    """A soft face in front of a crisp background is the frame to drop.

    Whole-frame sharpness cannot see that. The face box can, so this uses the
    REAL detector to find the face, blurs exactly that, and checks both
    numbers: the whole-frame measure barely moves while the face measure
    collapses.
    """
    import slapper_faces
    engine = slapper_faces.FaceEngine.load(os.path.dirname(MODELS))
    assert engine is not None
    with Image.open(PHOTO) as source:
        original = source.convert("RGB")
    detected = engine.detect(PHOTO)
    assert len(detected) == 1
    box = detected[0].box
    x, y, width, height = box
    soft_face = original.copy()
    soft_face.paste(
        soft_face.crop((x, y, x + width, y + height)).filter(
            ImageFilter.GaussianBlur(max(3, width // 8))), (x, y))

    sharp_path = str(tmp_path / "a-sharp-face.jpg")
    soft_path = str(tmp_path / "b-soft-face.jpg")
    when = time.localtime(1760000000)
    _write(sharp_path, original, when)
    _write(soft_path, soft_face, time.localtime(time.mktime(when) + 1))

    class Store:
        photos = {}

        @staticmethod
        def key(path):
            return os.path.normcase(os.path.abspath(path))

    Store.photos = {Store.key(path): {"faces": [{"box": box}]}
                    for path in (sharp_path, soft_path)}

    whole = [cull.measure(path)["sharpness"] for path in (sharp_path, soft_path)]
    # A correctly exposed real photograph must never be vetoed on exposure.
    assert cull._usable(cull.measure(sharp_path))
    faces = [cull.measure(path, cull.face_boxes(Store, path))
             for path in (sharp_path, soft_path)]
    assert all(row["on_faces"] for row in faces)
    # Blurring one face barely registers across the whole frame...
    assert whole[1] > whole[0] * 0.75
    # ...and is unmistakable on the face itself.
    assert faces[0]["sharpness"] > faces[1]["sharpness"] * 3

    picks = cull.pick_bursts([sharp_path, soft_path], store=Store)
    assert picks[0]["picked"] == sharp_path
    assert "measured on the face" in picks[0]["reason"]


# ===== SNAPSMACK EOF =====
