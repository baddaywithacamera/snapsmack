"""Import and rename must use the date the photograph was TAKEN.

DateTimeOriginal lives in the Exif sub-IFD (0x8769). Pillow's ``getexif()``
does not expose that, so reading tag 36867 from the base IFD returns None for
every ordinary camera JPEG and the code fell through to the file's modified
time. "Import, group into YYYY/MM folders" therefore filed photographs under
the month they were copied, and {date} in Batch Rename wrote that date into
the filename.
"""

import datetime
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

import piexif
from PIL import Image

from slapper_qt import organizer_ops

TAKEN = "2024:07:04 11:22:33"


def _camera_jpeg(path, taken=TAKEN):
    """A JPEG with DateTimeOriginal where a camera actually writes it."""
    exif = {"0th": {}, "Exif": {piexif.ExifIFD.DateTimeOriginal:
                                taken.encode("ascii")},
            "GPS": {}, "1st": {}, "thumbnail": None}
    Image.new("RGB", (120, 90), (70, 110, 150)).save(
        path, "JPEG", quality=90, exif=piexif.dump(exif))
    return str(path)


def test_the_capture_date_comes_from_the_exif_sub_ifd(tmp_path):
    path = _camera_jpeg(tmp_path / "shot.jpg")
    assert organizer_ops.capture_date(path) == datetime.datetime(
        2024, 7, 4, 11, 22, 33)
    # Proof the old reading could not have worked: the base IFD has no such tag.
    with Image.open(path) as image:
        assert image.getexif().get(36867) is None
        assert image.getexif().get(306) is None


def test_a_base_ifd_datetime_is_still_honoured(tmp_path):
    path = str(tmp_path / "scanner.jpg")
    exif = {"0th": {piexif.ImageIFD.DateTime: b"2019:01:02 03:04:05"},
            "Exif": {}, "GPS": {}, "1st": {}, "thumbnail": None}
    Image.new("RGB", (60, 40), "grey").save(path, "JPEG", exif=piexif.dump(exif))
    assert organizer_ops.capture_date(path) == datetime.datetime(
        2019, 1, 2, 3, 4, 5)


def test_a_file_with_no_exif_falls_back_to_its_own_timestamp(tmp_path):
    path = str(tmp_path / "plain.png")
    Image.new("RGB", (40, 30), "white").save(path)
    expected = datetime.datetime.fromtimestamp(os.path.getmtime(path))
    assert organizer_ops.capture_date(path) == expected


def test_damaged_exif_does_not_raise(tmp_path):
    path = str(tmp_path / "broken.jpg")
    exif = {"0th": {}, "Exif": {piexif.ExifIFD.DateTimeOriginal: b"not a date"},
            "GPS": {}, "1st": {}, "thumbnail": None}
    Image.new("RGB", (40, 30), "white").save(path, "JPEG", exif=piexif.dump(exif))
    assert organizer_ops.capture_date(path) == \
        datetime.datetime.fromtimestamp(os.path.getmtime(path))


def test_import_files_into_the_year_and_month_it_was_taken(tmp_path):
    source = _camera_jpeg(tmp_path / "DSCF1234.jpg")
    destination = tmp_path / "library"
    outputs, skipped = organizer_ops.import_photos(
        [source], str(destination), date_folders=True)
    assert not skipped
    relative = os.path.relpath(outputs[0], destination)
    assert relative == os.path.join("2024", "07", "DSCF1234.jpg")
    # The original is still where it was: import copies, it does not move.
    assert os.path.isfile(source)


def test_batch_rename_writes_the_capture_date(tmp_path):
    source = _camera_jpeg(tmp_path / "DSCF9999.jpg")
    completed = organizer_ops.batch_rename([source], "{date}-{n}")
    assert len(completed) == 1
    assert os.path.basename(completed[0][1]) == "2024-07-04-1.jpg"


# ===== SNAPSMACK EOF =====
