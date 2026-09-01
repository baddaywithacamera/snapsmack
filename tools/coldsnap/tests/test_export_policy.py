"""
Tests for COLD SNAP's export-policy resolution (sumna_post._export_policy /
_portable_from_site_data) — the seam that turns a destination's canonical
per-site settings into the (max_long_edge, jpeg_quality, sharpen) COLD SNAP
exports at (SPEC image-sizing-4k §9d/§9g).

Proves:
  * no settings -> fleet default (3840 / q85 / sharpen on),
  * RESIZE OFF disables sizing (long edge 0 -> export_path uploads the original),
  * export_sharpen "off" disables the mild sharpen; other values keep it,
  * an explicit per-site max_long_edge / quality flows through,
  * site-data extraction ignores unknown keys (never trips the contract guard).

Run: python tools/coldsnap/tests/test_export_policy.py   (exit 0 = all pass)

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(
    os.path.abspath(__file__)))), "_shared"))

import sumna_post as P


def _checks():
    n = 0

    # 1. Fleet default when nothing is mirrored.
    assert P._export_policy() == {"max_long_edge": 3840, "jpeg_quality": 85, "sharpen": True}
    assert P._export_policy(None) == P._export_policy({})
    n += 1

    # 2. RESIZE OFF -> long edge 0 (export_path treats 0 as "upload original untouched").
    pol = P._export_policy({"image_resize_enabled": False})
    assert pol["max_long_edge"] == 0, pol
    n += 1

    # 3. export_sharpen off disables sharpen; a non-off value keeps it.
    assert P._export_policy({"export_sharpen": "off"})["sharpen"] is False
    assert P._export_policy({"export_sharpen": "medium"})["sharpen"] is True
    n += 1

    # 4. Explicit per-site override flows through (canonical field + quality).
    pol = P._export_policy({"max_long_edge": 2560, "jpeg_quality": 90})
    assert pol == {"max_long_edge": 2560, "jpeg_quality": 90, "sharpen": True}, pol
    n += 1

    # 5. site-data extraction keeps only contract keys; a nested "portable" wins.
    assert P._portable_from_site_data({"max_long_edge": 1920, "junk": "x"}) == {"max_long_edge": 1920}
    assert P._portable_from_site_data({"portable": {"max_long_edge": 1600}}) == {"max_long_edge": 1600}
    assert P._portable_from_site_data(None) == {}
    assert P._portable_from_site_data("nonsense") == {}
    n += 1

    # 6. End-to-end: a portrait-taller site-data still resolves symmetric via the contract.
    pol = P._export_policy(P._portable_from_site_data({"max_width_landscape": 1600,
                                                       "max_height_portrait": 2400}))
    assert pol["max_long_edge"] == 2400, pol  # promoted to the larger edge
    n += 1

    return n


if __name__ == "__main__":
    passed = _checks()
    print(f"OK - {passed} checks passed")
# ===== SNAPSMACK EOF =====
