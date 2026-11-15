"""SS-1347 and SS-1349: the library stops paying for the same work twice.

SS-1347. LibraryWindow._icons is memory only, so every launch re-decoded every
tile from the source photograph, RAW development included. A disk cache
already existed in the Tk library (photo_library.py:1217) and the rule here is
to borrow the working one, so that cache now sits ahead of the Qt window's
decode path.

SS-1349. closeEvent() cleared two of its four thread pools. Queued TIFF and
ordinary decodes kept running after the window was gone.

These tests DRIVE the real code. The cache test counts actual decodes by
watching the source file open, so it fails if the cache is bypassed; the pool
test reads the real closeEvent, not a list of names in a comment.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

from PIL import Image

from slapper_qt import library_window


class _Signals:
    """Stands in for _ThumbSignals; records what the worker delivered."""

    def __init__(self):
        self.ready = self
        self.failed = self
        self.results = []
        self.failures = []
        self._is_ready = True

    def emit(self, *args):
        self.results.append(args)


class _FailSignals(_Signals):
    def emit(self, *args):
        self.failures.append(args)


def _photo(path):
    image = Image.new("RGB", (900, 600), (90, 120, 150))
    for x in range(0, 900, 30):            # texture, so JPEG is not degenerate
        for y in range(0, 600, 30):
            image.putpixel((x, y), (220, 40, 40))
    image.save(path, "JPEG", quality=92)
    return path


def _run_once(path, cache_dir, monkeypatch):
    monkeypatch.setattr(library_window, "_thumb_cache_dir", lambda: cache_dir)
    signals = _Signals()
    library_window._ThumbTask(path, signals).run()
    return signals


def test_a_second_launch_does_not_decode_the_photograph_again(tmp_path, monkeypatch):
    """The point of the ticket: the source is opened once, not once per run."""
    photo = _photo(str(tmp_path / "shot.jpg"))
    cache = str(tmp_path / "cache")
    os.makedirs(cache, exist_ok=True)

    opened = []
    real_open = Image.open

    def counting_open(fp, *a, **kw):
        if isinstance(fp, str) and os.path.normcase(fp) == os.path.normcase(photo):
            opened.append(fp)
        return real_open(fp, *a, **kw)

    monkeypatch.setattr(Image, "open", counting_open)

    first = _run_once(photo, cache, monkeypatch)
    assert first.results, "the first run produced no thumbnail at all"
    decodes_after_first = len(opened)
    assert decodes_after_first >= 1, "the first run never read the photograph"

    second = _run_once(photo, cache, monkeypatch)
    assert second.results, "the second run produced no thumbnail"
    assert len(opened) == decodes_after_first, (
        "the second run decoded the photograph again: the cache was not used")


def test_the_cached_thumbnail_is_the_same_picture_and_keeps_its_date(tmp_path, monkeypatch):
    """A cache that returns something else, or loses the sort key, is worse
    than no cache. The capture stamp rides in the JPEG comment because it is
    not derivable without the decode being avoided."""
    photo = _photo(str(tmp_path / "shot.jpg"))
    cache = str(tmp_path / "cache")
    os.makedirs(cache, exist_ok=True)

    fresh = _run_once(photo, cache, monkeypatch).results[0]
    cached = _run_once(photo, cache, monkeypatch).results[0]

    assert cached[0] == fresh[0] == photo
    assert (cached[1].width(), cached[1].height()) ==            (fresh[1].width(), fresh[1].height()), "the cached tile is a different size"

    # And it is the same PICTURE, not merely the same shape. JPEG at q82 is
    # lossy, so compare the mean of each channel rather than demanding
    # identical bytes; a cache serving the wrong photograph, or a flattened
    # one, moves these by far more than the tolerance.
    def _means(qimage):
        bits = qimage.constBits().tobytes()
        return tuple(sum(bits[c::4]) / (len(bits) / 4) for c in range(3))

    for got, want in zip(_means(cached[1]), _means(fresh[1])):
        assert abs(got - want) < 4.0,             f"the cached tile is not the same picture: {got:.1f} vs {want:.1f}"

    assert cached[2] == fresh[2], "the cached tile lost its capture time"
    assert cached[2] > 0, "the capture stamp is not usable for sorting"


def test_editing_the_photograph_invalidates_its_cached_tile(tmp_path, monkeypatch):
    """A stale tile is the failure mode that makes a cache worse than none."""
    photo = str(tmp_path / "shot.jpg")
    _photo(photo)
    cache = str(tmp_path / "cache")
    os.makedirs(cache, exist_ok=True)

    monkeypatch.setattr(library_window, "_thumb_cache_dir", lambda: cache)
    before = library_window._thumb_cache_path(photo, "")
    _run_once(photo, cache, monkeypatch)
    assert os.path.isfile(before)

    Image.new("RGB", (900, 600), (10, 200, 10)).save(photo, "JPEG", quality=92)
    after = library_window._thumb_cache_path(photo, "")
    assert after != before, (
        "the cache key ignored the edit, so the old tile would be served")


def test_a_transparent_image_is_not_cached_rather_than_cached_wrongly(tmp_path, monkeypatch):
    """JPEG has no alpha. Flattening onto an invented background would change
    what the tile looks like, so those are left uncached on purpose."""
    path = str(tmp_path / "logo.png")
    Image.new("RGBA", (400, 400), (255, 0, 0, 0)).save(path)
    cache = str(tmp_path / "cache")
    os.makedirs(cache, exist_ok=True)

    monkeypatch.setattr(library_window, "_thumb_cache_dir", lambda: cache)
    result = _run_once(path, cache, monkeypatch)
    assert result.results, "a transparent image produced no thumbnail"
    assert not os.path.isfile(library_window._thumb_cache_path(path, "")), \
        "a transparent image was cached as JPEG, losing its alpha"


def test_a_broken_cache_entry_is_a_miss_not_a_crash(tmp_path, monkeypatch):
    photo = _photo(str(tmp_path / "shot.jpg"))
    cache = str(tmp_path / "cache")
    os.makedirs(cache, exist_ok=True)
    monkeypatch.setattr(library_window, "_thumb_cache_dir", lambda: cache)

    with open(library_window._thumb_cache_path(photo, ""), "wb") as handle:
        handle.write(b"this is not a JPEG")

    result = _run_once(photo, cache, monkeypatch)
    assert result.results, "a corrupt cache entry stopped the thumbnail"


class _RecordingPool:
    def __init__(self, log, name):
        self._log, self._name = log, name

    def activeThreadCount(self):  # noqa: N802 - Qt spelling
        return 0

    def clear(self):
        self._log.append(self._name)


def test_close_clears_every_pool():
    """SS-1349. _tiff_pool and _pool were left running after the window closed.

    Behavioural: _clear_all_pools() is called on a stand-in holding four
    recording pools, and we watch which ones it actually clears. A source
    check for four strings would be the same kind of evidence that let two
    pools go missing in the first place.
    """
    cleared = []

    class _StandIn:
        pass

    window = _StandIn()
    for attr, name in (("_scan_pool", "scan"), ("_raw_pool", "raw"),
                       ("_tiff_pool", "tiff"), ("_pool", "general")):
        setattr(window, attr, _RecordingPool(cleared, name))

    library_window.LibraryWindow._clear_all_pools(window)

    assert sorted(cleared) == ["general", "raw", "scan", "tiff"],         f"closing the library left pools running: {sorted(cleared)}"


def test_closeevent_actually_calls_it():
    """The method is only worth anything if close still reaches it."""
    import inspect
    assert "self._clear_all_pools()" in         inspect.getsource(library_window.LibraryWindow.closeEvent)


def test_the_library_does_not_share_the_global_thread_pool():
    """Clearing the global pool on library close would cancel
    textures_dialog's queued work as a side effect of closing this window."""
    import inspect
    init = inspect.getsource(library_window.LibraryWindow.__init__)
    assert "self._pool = QThreadPool.globalInstance()" not in init
    assert "self._pool = QThreadPool(self)" in init
# ===== SNAPSMACK EOF =====
