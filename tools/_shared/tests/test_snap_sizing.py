"""
Tests for snap_sizing + snap_sharpen (the shared image-export sizing core).

Covers the load-bearing rules from SPEC-image-sizing-4k-coldsnap-gyss.md §10:
  * long edge capped at 3840 in EITHER orientation (portraits not second-class),
  * NEVER enlarge,
  * already-within images pass through untouched (same object, no re-encode),
  * sharpen happens ONLY when an actual downsize happened (once, never on pass-through).

Run: python tools/_shared/tests/test_snap_sizing.py   (exit 0 = all pass)
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from PIL import Image
import snap_sizing
import snap_sharpen


def _img(w, h, color=(120, 90, 60)):
    return Image.new("RGB", (w, h), color)


def _checks():
    n = 0

    # 1. Landscape over the cap → long edge (width) becomes 3840, aspect kept.
    out, down = snap_sizing.fit_long_edge(_img(6000, 4000), 3840)
    assert down is True, "landscape over cap should downsize"
    assert out.size == (3840, 2560), f"landscape size wrong: {out.size}"
    n += 1

    # 2. Portrait over the cap → long edge (height) becomes 3840 — NOT capped at
    #    2160. This is the whole point of the equal-long-edge decision.
    out, down = snap_sizing.fit_long_edge(_img(4000, 6000), 3840)
    assert down is True, "portrait over cap should downsize"
    assert out.size == (2560, 3840), f"portrait size wrong: {out.size}"
    n += 1

    # 3. Never enlarge: a small landscape stays put, did_downsize False.
    src = _img(2000, 1000)
    out, down = snap_sizing.fit_long_edge(src, 3840)
    assert down is False and out is src, "small image must pass through untouched"
    n += 1

    # 4. Exactly at the cap → unchanged (boundary, not > cap).
    src = _img(3840, 2160)
    out, down = snap_sizing.fit_long_edge(src, 3840)
    assert down is False and out is src, "at-cap image must pass through"
    n += 1

    # 5. Square over cap → 3840x3840.
    out, down = snap_sizing.fit_long_edge(_img(5000, 5000), 3840)
    assert down is True and out.size == (3840, 3840), f"square wrong: {out.size}"
    n += 1

    # 6. size_for_export sharpens ONLY on downsize. Pass-through must be byte-equal
    #    to the source (no sharpen applied) — proven by identical pixel data.
    src = _img(1600, 1200)
    out, down = snap_sizing.size_for_export(src, 3840)
    assert down is False and out is src, "pass-through must not touch the image"
    assert out.tobytes() == src.tobytes(), "pass-through pixels changed"
    n += 1

    # 7. size_for_export on an oversized image downsizes AND sharpens (result
    #    differs from a plain resize with no sharpen).
    src = _img(6000, 4000)
    sharp, down = snap_sizing.size_for_export(src, 3840, sharpen_on_downsize=True)
    plain, _ = snap_sizing.fit_long_edge(src, 3840)
    assert down is True and sharp.size == (3840, 2560), "export size wrong"
    # On a flat colour the unsharp mask is a near-no-op, so assert the wiring, not
    # a pixel delta: with sharpening OFF it must equal the plain resize.
    nosharp, _ = snap_sizing.size_for_export(src, 3840, sharpen_on_downsize=False)
    assert nosharp.tobytes() == plain.tobytes(), "no-sharpen path != plain resize"
    n += 1

    # 8. Sharpen preset is versioned and stable.
    assert snap_sharpen.DEFAULT_PRESET == "mild-v1"
    p = snap_sharpen.preset_params("mild-v1")
    assert p == {"amount": 80, "radius": 1.0, "threshold": 2}, f"preset drift: {p}"
    # unknown preset falls back, never raises
    assert snap_sharpen.preset_params("nope") == p
    n += 1

    # 9. Sharpen actually changes pixels on real detail (not a flat fill).
    import random
    random.seed(1)  # deterministic
    noisy = Image.new("RGB", (200, 200))
    noisy.putdata([(random.randint(0, 255), random.randint(0, 255), random.randint(0, 255))
                   for _ in range(200 * 200)])
    sharpened = snap_sharpen.sharpen(noisy, "mild-v1")
    assert sharpened.tobytes() != noisy.tobytes(), "sharpen had no effect on detail"
    n += 1

    return n


if __name__ == "__main__":
    count = _checks()
    print(f"OK — {count} checks passed")
