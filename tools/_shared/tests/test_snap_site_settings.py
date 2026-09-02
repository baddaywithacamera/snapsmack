"""
Tests for snap_site_settings.validate_portable — the canonical max_long_edge size
field and its schema-1 -> schema-2 migration/compat (SPEC image-sizing-4k §9b;
Sean's ruling 2026-09-01: 3840 symmetric).

Proves:
  * canonical max_long_edge is authoritative and derives a symmetric legacy pair,
  * a legacy (pair-only) store migrates to max_long_edge = max(pair) (promote, never
    shrink) and the pair becomes symmetric,
  * normalization is idempotent (validate(validate(x)) == validate(x)),
  * bounds and unknown-key guards still hold,
  * defaults give the fleet standard 3840.

Run: python tools/_shared/tests/test_snap_site_settings.py   (exit 0 = all pass)

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import snap_site_settings as S


def _checks():
    n = 0

    # 1. Empty -> fleet default 3840, symmetric pair.
    v = S.validate_portable({})
    assert v["max_long_edge"] == 3840, v
    assert v["max_width_landscape"] == 3840 and v["max_height_portrait"] == 3840, v
    n += 1

    # 2. Canonical present is authoritative; pair derived symmetric.
    v = S.validate_portable({"max_long_edge": 3000})
    assert v["max_long_edge"] == 3000, v
    assert v["max_width_landscape"] == 3000 and v["max_height_portrait"] == 3000, v
    n += 1

    # 3. Legacy pair only (schema-1 store) -> migrate to the LARGER edge, promote.
    v = S.validate_portable({"max_width_landscape": 2500, "max_height_portrait": 1850})
    assert v["max_long_edge"] == 2500, v  # max(2500, 1850) — portrait promoted, not shrunk
    assert v["max_width_landscape"] == 2500 and v["max_height_portrait"] == 2500, v
    n += 1

    # 3b. Portrait-taller legacy store still promotes to the larger edge.
    v = S.validate_portable({"max_width_landscape": 1600, "max_height_portrait": 2400})
    assert v["max_long_edge"] == 2400, v
    n += 1

    # 4. Canonical wins even when a stale pair is also supplied.
    v = S.validate_portable({"max_long_edge": 3840, "max_width_landscape": 1000,
                             "max_height_portrait": 1000})
    assert v["max_long_edge"] == 3840, v
    assert v["max_width_landscape"] == 3840 and v["max_height_portrait"] == 3840, v
    n += 1

    # 5. Idempotence: validating a validated dict is a fixed point (critical for the
    #    sync round-trip — a stored+re-read value must not drift).
    once = S.validate_portable({"max_width_landscape": 2500, "max_height_portrait": 1850})
    twice = S.validate_portable(once)
    assert once == twice, (once, twice)
    n += 1

    # 6. Bounds on the canonical field.
    for bad in (100, 99999, "nope"):
        try:
            S.validate_portable({"max_long_edge": bad})
            raise AssertionError(f"expected ValueError for max_long_edge={bad!r}")
        except ValueError:
            pass
    n += 1

    # 7. Unknown key still rejected.
    try:
        S.validate_portable({"totally_made_up": 1})
        raise AssertionError("expected ValueError for unknown key")
    except ValueError:
        pass
    n += 1

    # 8. Other fields untouched by the size change.
    v = S.validate_portable({"max_long_edge": 3840, "jpeg_quality": 92,
                             "export_sharpen": "OFF", "image_resize_enabled": False})
    assert v["jpeg_quality"] == 92 and v["export_sharpen"] == "off", v
    assert v["image_resize_enabled"] is False, v
    n += 1

    # 9. Schema bumped to 2.
    assert S.SCHEMA == 2, S.SCHEMA
    assert S.DEFAULT_MAX_LONG_EDGE == 3840, S.DEFAULT_MAX_LONG_EDGE
    n += 1

    return n


if __name__ == "__main__":
    passed = _checks()
    print(f"OK — {passed} checks passed")
# ===== SNAPSMACK EOF =====
