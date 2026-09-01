"""
snap_sharpen.py — mild, versioned output sharpening for the SnapSmack desktop suite.

Downsizing an image softens it; a small amount of unsharp masking on the FINAL,
already-resized pixels restores the crispness the resample removed. Two hard rules
this module exists to enforce:

  1. Sharpen ONLY after a real downsize — never on an image that wasn't resized,
     and never on an upsized one (both just add halos). The size step decides
     "did we downsize?"; this module only applies the filter.
  2. Sharpen EXACTLY ONCE — owned by whoever performs the final downsize (COLD
     SNAP for its exports; the server never sharpens). No stage sharpens twice.

Presets are VERSIONED so every client (COLD SNAP now; others later) produces the
same look from the same name. Never mutate an existing preset's numbers — add a new
version key (mild-v2, …) instead, so an old draft/derivative stays reproducible.

Pure Pillow (ImageFilter.UnsharpMask). No numpy, no other dependency.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from PIL import ImageFilter


# Versioned unsharp presets. amount = UnsharpMask "percent"; radius in px;
# threshold in levels (skip low-contrast areas so noise/skin stays clean).
# mild-v1 is the fleet default: enough to recover downsize softness, not enough
# to crunch. FROZEN — bump to a new key if the look ever needs to change.
PRESETS = {
    "mild-v1": {"amount": 80, "radius": 1.0, "threshold": 2},
}

DEFAULT_PRESET = "mild-v1"


def preset_params(preset: str = DEFAULT_PRESET) -> dict:
    """Return the {amount, radius, threshold} for a preset, falling back to the
    default for an unknown name (never raises — a bad name must not break export)."""
    return dict(PRESETS.get(preset, PRESETS[DEFAULT_PRESET]))


def sharpen(img, preset: str = DEFAULT_PRESET):
    """Apply a versioned mild unsharp mask to a PIL image and return a new image.

    Caller's responsibility: only invoke this after an actual downsize. This
    function does not check dimensions — the size step owns the downsize decision
    (see snap_sizing.size_for_export, which wires the two together correctly)."""
    p = preset_params(preset)
    return img.filter(ImageFilter.UnsharpMask(
        radius=float(p["radius"]),
        percent=int(p["amount"]),
        threshold=int(p["threshold"]),
    ))
# ===== SNAPSMACK EOF =====
