"""Focused checks for the COLD TAKE -> SNAP SLAPPER return watcher.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

from pathlib import Path
import sys
import tempfile
import time

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from coldsnap_qt.slapper_handoff import ReturnWatch, find_executable  # noqa: E402
import sumna_offline as O  # noqa: E402


def _image(path, colour):
    Image.new("RGB", (40, 30), colour).save(path, "JPEG")


def main():
    with tempfile.TemporaryDirectory(prefix="coldsnap-slapper-") as folder:
        root = Path(folder)
        source = root / "bucket-08.jpg"
        unrelated = root / "someone-else.jpg"
        old_edit = root / "bucket-08-old.jpg"
        _image(source, "red")
        _image(unrelated, "blue")
        _image(old_edit, "green")
        source_bytes = source.read_bytes()

        watch = ReturnWatch.start(str(source))
        assert watch.poll() == ""
        _image(unrelated, "yellow")
        assert watch.poll() == "", "an unrelated export was accepted"

        time.sleep(0.002)
        returned = root / "bucket-08-edit1.jpg"
        _image(returned, "purple")
        assert watch.poll() == "", "a file was accepted before it was stable"
        assert watch.poll() == str(returned.resolve())
        assert source.read_bytes() == source_bytes, "the original changed"

        fake = root / "SNAP SLAPPER.exe"
        fake.write_bytes(b"not launched")
        assert find_executable(str(fake)) == str(fake.resolve())

        session = O.SessionStore(str(root / "sessions")).create(
            "handoff", O.MODE_SMACKTALK)
        draft_image = O.DraftImage(
            local_path=str(returned), original_path=str(source),
            filename=returned.name, slapper_base_path=str(source),
            slapper_base_filename=source.name,
            slapper_edited_path=str(returned), slapper_use_edited=True)
        draft = O.Draft(
            draft_id="handoff-draft", kind=O.KIND_SMACKTALK,
            mode=O.MODE_SMACKTALK, title="Handoff", images=[draft_image])
        session.add_draft(draft)
        reopened = session.load_draft(draft.draft_id).images[0]
        assert Path(reopened.slapper_base_path).is_file()
        assert Path(reopened.slapper_edited_path).is_file()
        assert Path(reopened.slapper_base_path).parent.name == "versions"
        assert Path(reopened.slapper_edited_path).parent.name == "versions"
        assert reopened.slapper_use_edited is True
        assert Path(reopened.slapper_base_path).read_bytes() == source_bytes

    print("PASS - stable return detected; both versions persisted; original unchanged")


if __name__ == "__main__":
    main()


# ===== SNAPSMACK EOF =====
