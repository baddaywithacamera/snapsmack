"""Emailing photographs: copies, privacy, and never sending anything.

The mail-client hand-off cannot be exercised on this machine, so it is kept
behind one function (send_with_mapi) and everything around it -- the copies,
their size, the location data, the fallback route and the draft -- is checked
here. What cannot be tested is named rather than assumed to work.
"""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

from PIL import Image
from PySide6.QtWidgets import QApplication, QMessageBox

from slapper_qt import email_photos


APP = QApplication.instance() or QApplication([])


def _photo_with_location(path, size=(3000, 2000)):
    """A JPEG carrying GPS EXIF, written the way a camera writes it."""
    import piexif
    photo = Image.new("RGB", size, (120, 90, 60))
    exif = {"0th": {}, "Exif": {}, "GPS": {
        piexif.GPSIFD.GPSLatitudeRef: b"N",
        piexif.GPSIFD.GPSLatitude: ((51, 1), (30, 1), (0, 1)),
        piexif.GPSIFD.GPSLongitudeRef: b"W",
        piexif.GPSIFD.GPSLongitude: ((0, 1), (7, 1), (0, 1)),
    }, "1st": {}, "thumbnail": None}
    photo.save(path, "JPEG", quality=92, exif=piexif.dump(exif))


def _plain_photo(path, size=(3000, 2000)):
    Image.new("RGB", size, (60, 110, 150)).save(path, "JPEG", quality=92)


def test_copies_are_made_at_the_sending_size_and_originals_untouched(tmp_path):
    source = tmp_path / "big.jpg"
    _plain_photo(source)
    before = source.stat().st_mtime_ns, source.stat().st_size
    outbox = tmp_path / "outbox"
    outputs = email_photos.prepare_copies([str(source)], str(outbox), max_size=1600)
    assert len(outputs) == 1
    with Image.open(outputs[0]) as copy:
        assert max(copy.size) == 1600
    with Image.open(source) as original:
        assert original.size == (3000, 2000)
    assert (source.stat().st_mtime_ns, source.stat().st_size) == before
    # The copies live in SNAP SLAPPER's folder, never beside the originals.
    assert os.path.dirname(outputs[0]) == str(outbox)


def test_original_size_is_offered_and_sends_full_pixels(tmp_path):
    source = tmp_path / "big.jpg"
    _plain_photo(source)
    outputs = email_photos.prepare_copies(
        [str(source)], str(tmp_path / "outbox"), max_size=0)
    with Image.open(outputs[0]) as copy:
        assert copy.size == (3000, 2000)


def test_location_is_removed_by_default_and_kept_only_on_request(tmp_path):
    piexif = __import__("piexif")
    source = tmp_path / "located.jpg"
    _photo_with_location(source)
    assert piexif.load(str(source))["GPS"], "fixture has no GPS to remove"

    stripped = email_photos.prepare_copies(
        [str(source)], str(tmp_path / "private"), max_size=1024,
        strip_location=True)
    assert not piexif.load(stripped[0])["GPS"]

    kept = email_photos.prepare_copies(
        [str(source)], str(tmp_path / "kept"), max_size=1024,
        strip_location=False)
    assert piexif.load(kept[0])["GPS"]


def test_the_draft_carries_the_subject_and_message_and_no_recipient():
    url = email_photos.mailto_url("Photographs", "Here are the ones from Sunday")
    assert url.startswith("mailto:?")
    assert "subject=Photographs" in url
    assert "body=Here%20are%20the%20ones%20from%20Sunday" in url
    # SNAP SLAPPER never decides who a photograph goes to.
    assert "to=" not in url


def test_mapi_is_asked_to_show_the_draft_never_to_send(monkeypatch, tmp_path):
    source = tmp_path / "one.jpg"
    _plain_photo(source, (600, 400))
    seen = {}

    class FakeMapi:
        def MAPISendMail(self, session, parent, message, flags, reserved):
            seen["flags"] = int(flags.value)
            seen["files"] = message._obj.nFileCount if hasattr(message, "_obj") \
                else message.nFileCount
            return email_photos.SUCCESS_SUCCESS

    monkeypatch.setattr(email_photos, "mapi_available", lambda: True)
    monkeypatch.setattr(email_photos.ctypes, "WinDLL",
                        lambda name: FakeMapi(), raising=False)
    assert email_photos.send_with_mapi([str(source)], "Subject", "Body") is True
    # MAPI_DIALOG is what shows the compose window. Without it the message
    # would be handed straight to the outbox.
    assert seen["flags"] & email_photos.MAPI_DIALOG
    assert seen["files"] == 1


def test_closing_the_draft_is_not_treated_as_a_failure(monkeypatch, tmp_path):
    source = tmp_path / "one.jpg"
    _plain_photo(source, (600, 400))

    class Aborting:
        def MAPISendMail(self, *args):
            return email_photos.MAPI_USER_ABORT

    monkeypatch.setattr(email_photos, "mapi_available", lambda: True)
    monkeypatch.setattr(email_photos.ctypes, "WinDLL",
                        lambda name: Aborting(), raising=False)
    # Closing the draft must not send the photographer round the "drag these
    # files in yourself" route as though something had broken.
    assert email_photos.send_with_mapi([str(source)], "", "") is True


def test_without_a_mail_client_the_folder_and_a_draft_are_opened(monkeypatch, tmp_path):
    source = tmp_path / "one.jpg"
    _plain_photo(source, (900, 600))
    outbox = tmp_path / "outbox"
    opened = {}
    monkeypatch.setattr(email_photos, "outbox_folder", lambda: str(outbox))
    monkeypatch.setattr(email_photos, "mapi_available", lambda: False)
    monkeypatch.setattr(email_photos, "reveal",
                        lambda folder: opened.setdefault("folder", folder))
    from PySide6.QtGui import QDesktopServices
    monkeypatch.setattr(QDesktopServices, "openUrl",
                        staticmethod(lambda url: opened.setdefault(
                            "url", url.toString())))
    monkeypatch.setattr(QMessageBox, "information",
                        lambda *args, **kwargs: QMessageBox.Ok)
    dialog = email_photos.EmailPhotosDialog(None, [str(source)])
    try:
        assert "drag the files" in dialog.route.text().lower()
        dialog.subject.setText("Sunday")
        dialog._prepare()
        assert opened["folder"] == str(outbox)
        assert "subject=Sunday" in opened["url"]
        assert dialog.outputs and os.path.isfile(dialog.outputs[0])
    finally:
        dialog.deleteLater()


def test_location_removal_is_on_when_the_dialog_opens(tmp_path):
    source = tmp_path / "one.jpg"
    _plain_photo(source, (600, 400))
    dialog = email_photos.EmailPhotosDialog(None, [str(source)])
    try:
        assert dialog.strip_location.isChecked()
    finally:
        dialog.deleteLater()


# ===== SNAPSMACK EOF =====
