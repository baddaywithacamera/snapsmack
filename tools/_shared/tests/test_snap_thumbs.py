"""Tests for SS-1422 / SS-1451: snap_thumbs generates 400 (t_), 600 (m_), 900 (a_)."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import os
import sys

from PIL import Image

SHARED_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if SHARED_DIR not in sys.path:
    sys.path.insert(0, SHARED_DIR)

import snap_thumbs  # noqa: E402


def test_snap_thumbs_generates_t_m_a_with_cms_sizes(tmp_path):
    src = tmp_path / "shot.jpg"
    Image.new("RGB", (2400, 1600), (40, 90, 140)).save(src)

    res = snap_thumbs.generate_thumbs(str(src))
    assert res is not None
    assert res["width"] == 2400
    assert res["height"] == 1600
    assert os.path.basename(res["sq_path"]) == "t_shot.jpg"
    assert os.path.basename(res["med_path"]) == "m_shot.jpg"
    assert os.path.basename(res["asp_path"]) == "a_shot.jpg"

    with Image.open(res["sq_path"]) as im:
        assert im.size == (400, 400)
    with Image.open(res["med_path"]) as im:
        assert im.size == (600, 400)
    with Image.open(res["asp_path"]) as im:
        assert im.size == (900, 600)


def test_snap_thumbs_never_upscales_small_source(tmp_path):
    src = tmp_path / "tiny.jpg"
    Image.new("RGB", (320, 240), (90, 90, 90)).save(src)

    res = snap_thumbs.generate_thumbs(str(src))
    assert res is not None
    with Image.open(res["sq_path"]) as im:
        assert im.size == (400, 400)
    with Image.open(res["med_path"]) as im:
        assert im.size == (320, 240)
    with Image.open(res["asp_path"]) as im:
        assert im.size == (320, 240)

# ===== SNAPSMACK EOF =====
