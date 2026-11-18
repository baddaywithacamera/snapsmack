"""SS-1422 first-slice regression tests:
- snap_thumbs.generate_thumbs() defaults to 400 square / 900 aspect (fails on 300/600)
- FLKR FCKR reads thumb sizes from the site when available, falling back to 400/900
- COLD SNAP explicit 400/400 recrop path stays 400/400
"""

import os
import sys

from PIL import Image

_FLKR_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
_SHARED = os.path.join(os.path.dirname(_FLKR_ROOT), "_shared")
for p in (_FLKR_ROOT, _SHARED):
    if p not in sys.path:
        sys.path.insert(0, p)

import image_prep
import poster
import snap_thumbs


def _make_landscape_jpg(path, w=2400, h=1600):
    Image.new("RGB", (w, h), (90, 120, 150)).save(path, "JPEG", quality=92)
    return str(path)


def test_snap_thumbs_defaults_are_400_and_900(tmp_path):
    src = _make_landscape_jpg(tmp_path / "sample.jpg")
    res = snap_thumbs.generate_thumbs(src, thumb_dir=str(tmp_path / "thumbs"))
    assert res is not None
    with Image.open(res["sq_path"]) as sq:
        assert sq.size == (400, 400)
    with Image.open(res["asp_path"]) as asp:
        assert max(asp.size) == 900
        assert asp.size == (900, 600)


def test_flkr_fckr_prepare_defaults_to_400_and_900(tmp_path):
    src = _make_landscape_jpg(tmp_path / "flickr_src.jpg")
    out_dir = tmp_path / "staged"
    prepped = image_prep.prepare(src, str(out_dir))
    with Image.open(prepped.thumb_square_path) as sq:
        assert sq.size == (400, 400)
    with Image.open(prepped.thumb_aspect_path) as asp:
        assert max(asp.size) == 900
        assert asp.size == (900, 600)


def test_flkr_fckr_reads_site_thumb_sizes_with_fallback(monkeypatch):
    client = poster.FlkrDckrClient("https://site.example", "k")

    # 1. Site reports custom sizes
    monkeypatch.setattr(
        client, "check_auth",
        lambda: {"ok": True, "thumb_sizes": {"square": 500, "aspect": 1200}},
    )
    assert client.fetch_thumb_sizes() == (500, 1200)

    # 2. Site omits sizes -> falls back to 400/900
    client2 = poster.FlkrDckrClient("https://site2.example", "k")
    monkeypatch.setattr(client2, "check_auth", lambda: {"ok": True})
    assert client2.fetch_thumb_sizes() == (400, 900)

    # 3. Site unreachable / auth failed -> falls back to 400/900 without caching failure
    client3 = poster.FlkrDckrClient("https://site3.example", "k")
    monkeypatch.setattr(client3, "check_auth", lambda: {"ok": False, "message": "offline"})
    assert client3.fetch_thumb_sizes() == (400, 900)


def test_coldsnap_explicit_400_400_recrop_preserved(tmp_path):
    src = _make_landscape_jpg(tmp_path / "recrop.jpg")
    res = snap_thumbs.generate_thumbs(
        src, sq_size=400, asp_max=400, thumb_dir=str(tmp_path / "thumbs"),
    )
    assert res is not None
    with Image.open(res["sq_path"]) as sq:
        assert sq.size == (400, 400)
    with Image.open(res["asp_path"]) as asp:
        assert max(asp.size) == 400
        assert asp.size == (400, 267)

# ===== SNAPSMACK EOF =====
