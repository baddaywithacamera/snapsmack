"""SS-1389: an ordinary TIFF thumbnail must not launch a process.

Sean reported SNAP SLAPPER 0.7.59 taking about a minute per preview with the
fan pinned, on a folder of 42 files. Measured on that exact folder, read-only:
12 of them are TIFFs averaging 17.6 MB, and every one went through
``highbit_image.read()``, which spawns a whole process per call. In the frozen
build ``_worker_command`` relaunches THE EXE ITSELF, so each TIFF thumbnail
paid a full application bootstrap -- and the library's TIFF pool is
deliberately serial (``_tiff_pool.setMaxThreadCount(1)``), so those twelve
bootstraps happen one after another while the grid shows gaps. That is the
minute.

For contrast, an 81 MB CR3 costs 0.03s through its embedded preview. The
expensive files were never the raws.

THE WORKER IS NOT THE BUG AND IS NOT REMOVED. It exists because camera, HDR
and external-editor TIFFs use compressions and sample depths the bundled
Pillow cannot display, and that is still true. It becomes the FALLBACK: an
ordinary TIFF is read in-process, and anything Pillow refuses still goes to
OpenImageIO and still paints. The editor's full-precision path is untouched --
``display_thumbnail`` only ever produced a disposable 8-bit proxy for the UI.

These tests use a TIFF this file writes itself, so they need no camera file,
no OpenImageIO and none of Sean's photographs.
"""

import os
import sys

import pytest
from PIL import Image

HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
for path in (HUB, os.path.join(HUB, "slapper_qt"),
             os.path.abspath(os.path.join(HUB, "..", "_shared"))):
    if path not in sys.path:
        sys.path.insert(0, path)

engine_bridge = pytest.importorskip("slapper_qt.engine_bridge")


@pytest.fixture
def ordinary_tiff(tmp_path):
    """A plain 8-bit RGB TIFF, the kind a phone or a scanner produces."""
    path = tmp_path / "ordinary.tif"
    Image.new("RGB", (1200, 800), (90, 120, 150)).save(path)
    return str(path)


def test_ordinary_tiff_never_reaches_the_worker(ordinary_tiff, monkeypatch):
    """The whole point: no subprocess for a TIFF Pillow can read."""
    import highbit_image

    def explode(*args, **kwargs):
        raise AssertionError(
            "highbit_image.read() was called for an ordinary TIFF. Every call "
            "is a process launch, and in the frozen build it relaunches the "
            "exe -- twelve of those, serialised, is the minute Sean reported."
        )

    monkeypatch.setattr(highbit_image, "read", explode)
    image = engine_bridge.display_thumbnail(ordinary_tiff, (512, 512))

    assert image.mode == "RGBA", "Qt is handed a mode it cannot blit"
    assert max(image.size) <= 512, f"thumbnail not bounded: {image.size}"
    # The aspect ratio has to survive, or tiles letterbox.
    assert image.size == (512, 341), f"expected (512, 341), got {image.size}"


def test_a_tiff_pillow_refuses_still_paints(ordinary_tiff, monkeypatch):
    """The fallback is the reason the worker stays.

    A camera or HDR TIFF that Pillow cannot decode must still fill a tile. If
    this ever fails, the fix traded Sean's odd TIFFs for speed, which is not a
    trade he agreed to.
    """
    import highbit_image

    calls = []

    class Proxy:
        @staticmethod
        def display_proxy():
            return Image.new("RGBA", (400, 300), (10, 20, 30, 255))

    def refusing_open(*args, **kwargs):
        raise OSError("cannot identify image file")

    monkeypatch.setattr(engine_bridge.Image, "open", refusing_open)
    monkeypatch.setattr(highbit_image, "read",
                        lambda *a, **k: calls.append(1) or Proxy())

    image = engine_bridge.display_thumbnail(ordinary_tiff, (512, 512))

    assert calls, "Pillow refused the file and the worker was never tried"
    assert image.size == (400, 300)


def test_non_tiff_formats_are_untouched(tmp_path, monkeypatch):
    """A JPEG never went near the worker and still must not."""
    import highbit_image

    path = tmp_path / "photo.jpg"
    Image.new("RGB", (900, 600), (200, 180, 120)).save(path)

    monkeypatch.setattr(highbit_image, "read", lambda *a, **k: pytest.fail(
        "a JPEG was sent to the OpenImageIO worker"))
    image = engine_bridge.display_thumbnail(str(path), (512, 512))
    assert image.size == (512, 341)


def test_the_tiff_branch_tries_pillow_before_importing_the_worker():
    """Source-level, and labelled as such.

    The two tests above prove the behaviour with the worker monkeypatched. This
    one pins the ORDER in the source, because a future edit that imports
    highbit_image at the top of the TIFF branch would still pass them while
    quietly restoring the cost on the frozen build, where the import itself is
    part of what is slow.
    """
    source = open(engine_bridge.__file__, encoding="utf-8").read()
    branch = source[source.index('{".tif", ".tiff"}'):]
    branch = branch[:branch.index("with Image.open(source_path) as source:",
                                  branch.index("except Exception"))]
    assert branch.index("Image.open") < branch.index("import highbit_image"), (
        "the TIFF branch reaches for highbit_image before trying Pillow"
    )


def test_raw_extensions_do_not_include_tiff():
    """A guard on the diagnosis, not on this fix.

    If .tif were ever added to RAW_EXTENSIONS the grid would send TIFFs to
    raw_preview.embedded_preview instead, which returns None for them, and the
    fall-through is a FULL raw render -- slower than the worker this replaced.
    """
    import photo_manager

    assert ".tif" not in photo_manager.RAW_EXTENSIONS
    assert ".tiff" not in photo_manager.RAW_EXTENSIONS
