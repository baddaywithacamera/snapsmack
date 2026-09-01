"""
Tests for sumna_resize.export_path — COLD SNAP's destination-aware export
derivative (SPEC-image-sizing-4k-coldsnap-gyss.md §9d/§10).

Proves: oversized images are capped to the long edge; the ORIGINAL is never
modified; EXIF survives onto the derivative; within-policy images pass through
untouched; derivatives are cached; a bad/zero policy is a safe no-op.

Run: python tools/coldsnap/tests/test_sumna_resize.py   (exit 0 = all pass)
"""

import hashlib
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(
    os.path.abspath(__file__)))), "_shared"))

from PIL import Image
import sumna_resize


def _hash(p):
    return hashlib.sha256(open(p, "rb").read()).hexdigest()


def _make_jpg(path, w, h, with_exif=True):
    img = Image.new("RGB", (w, h), (100, 140, 90))
    kwargs = {"quality": 92}
    if with_exif:
        ex = Image.Exif()
        ex[271] = "SnapSmackTest"       # Make
        ex[272] = "COLD SNAP"           # Model
        ex[305] = "sumna_resize test"   # Software
        kwargs["exif"] = ex.tobytes()
    img.save(path, "JPEG", **kwargs)


def _checks():
    n = 0
    tmp = tempfile.mkdtemp(prefix="csresize_")
    cache = os.path.join(tmp, "cache")

    # 1. Oversized → capped to 3840 long edge; ORIGINAL untouched; EXIF preserved.
    src = os.path.join(tmp, "big.jpg")
    _make_jpg(src, 6000, 4000, with_exif=True)
    before = _hash(src)
    out = sumna_resize.export_path(src, 3840, cache_dir=cache)
    assert out != src, "oversized image should produce a derivative"
    assert os.path.isfile(out), "derivative not written"
    with Image.open(out) as d:
        assert d.size == (3840, 2560), f"derivative size wrong: {d.size}"
        ex = d.getexif()
        assert ex.get(271) == "SnapSmackTest", "EXIF Make lost"
        assert ex.get(305) == "sumna_resize test", "EXIF Software lost"
    assert _hash(src) == before, "ORIGINAL was modified — invariant broken"
    n += 1

    # 2. Within policy → returns the original path (no derivative, no re-encode).
    small = os.path.join(tmp, "small.jpg")
    _make_jpg(small, 2000, 1500, with_exif=True)
    out = sumna_resize.export_path(small, 3840, cache_dir=cache)
    assert out == small, "within-policy image must upload the original"
    n += 1

    # 3. Caching → second call returns the same derivative path, still valid.
    out1 = sumna_resize.export_path(src, 3840, cache_dir=cache)
    out2 = sumna_resize.export_path(src, 3840, cache_dir=cache)
    assert out1 == out2 and os.path.isfile(out2), "derivative not cached/reused"
    n += 1

    # 4. Different policy (portrait cap 3840 on a portrait) → long edge = height.
    port = os.path.join(tmp, "port.jpg")
    _make_jpg(port, 4000, 6000, with_exif=False)
    out = sumna_resize.export_path(port, 3840, cache_dir=cache)
    with Image.open(out) as d:
        assert d.size == (2560, 3840), f"portrait derivative wrong: {d.size}"
    n += 1

    # 5. Zero / invalid policy → safe no-op (upload original).
    out = sumna_resize.export_path(src, 0, cache_dir=cache)
    assert out == src, "max_long_edge<=0 must be a no-op"
    n += 1

    # 6. Missing file → returns the path unchanged, never raises.
    ghost = os.path.join(tmp, "nope.jpg")
    assert sumna_resize.export_path(ghost, 3840, cache_dir=cache) == ghost
    n += 1

    return n


if __name__ == "__main__":
    print(f"OK — {_checks()} checks passed")
