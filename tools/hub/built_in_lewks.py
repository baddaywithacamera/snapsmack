"""Curated first-party LEWKS shipped with SNAP SLAPPER."""

# SNAPSMACK_EOF_HEADER

import copy
import time

from editor_engine import DEFAULT_ADJUSTMENTS, PROJECT_VERSION


LEWKS = (
    {"id": "clean-slate", "name": "CLEAN SLATE", "category": "Clean + Corrective",
     "description": "Quiet contrast, open shadows, restrained colour. A clean starting point.",
     "adjustments": {"exposure": .10, "contrast": 8, "highlights": -18, "shadows": 16,
                     "whites": 6, "blacks": -5, "vibrance": 8, "clarity": 5, "sharpen": 12}},
    {"id": "golden-hourglass", "name": "GOLDEN HOURGLASS", "category": "Landscape + Weather",
     "description": "Warm late light, protected highlights, and a little prairie glow.",
     "adjustments": {"temperature": 24, "tint": 3, "highlights": -30, "shadows": 12,
                     "vibrance": 18, "clarity": 7, "vignette": -10,
                     "curve": [[0, 8], [55, 48], [132, 142], [210, 226], [255, 250]]}},
    {"id": "frost-warning", "name": "FROST WARNING", "category": "Landscape + Weather",
     "description": "Cold air, pale colour, crystalline edges, and winter restraint.",
     "adjustments": {"temperature": -26, "tint": -3, "saturation": -14, "vibrance": 8,
                     "highlights": -12, "whites": 15, "texture": 14, "sharpen": 16}},
    {"id": "parking-lot-disco", "name": "PARKING LOT DISCO", "category": "Night + Neon",
     "description": "Electric colour, deep blacks, neon punch, and after-midnight grain.",
     "adjustments": {"contrast": 22, "highlights": -20, "shadows": 10, "blacks": -20,
                     "saturation": 10, "vibrance": 34, "clarity": 12, "dehaze": 9,
                     "grain": 16, "vignette": -18}},
    {"id": "cheap-motel-cyan", "name": "CHEAP MOTEL CYAN", "category": "Night + Neon",
     "description": "Cool fluorescent colour, hard contrast, and beautifully questionable decisions.",
     "adjustments": {"temperature": -18, "tint": -12, "contrast": 28, "highlights": -25,
                     "shadows": -8, "saturation": -4, "vibrance": 24, "grain": 20,
                     "curve": [[0, 5], [48, 35], [128, 130], [205, 225], [255, 252]]}},
    {"id": "prairie-gothic", "name": "PRAIRIE GOTHIC", "category": "Film + Print",
     "description": "Storm-heavy skies, muted land, lifted black point, and rural menace.",
     "adjustments": {"exposure": -.08, "temperature": -6, "contrast": 14, "highlights": -38,
                     "shadows": -6, "saturation": -20, "clarity": 18, "dehaze": 16,
                     "grain": 18, "vignette": -22, "curve": [[0, 16], [64, 54], [150, 145], [255, 244]]}},
    {"id": "expired-in-alberta", "name": "EXPIRED IN ALBERTA", "category": "Film + Print",
     "description": "Faded drugstore colour, warm dust, soft contrast, and honest grain.",
     "adjustments": {"temperature": 13, "tint": 7, "contrast": -14, "highlights": -12,
                     "shadows": 20, "saturation": -12, "vibrance": 6, "texture": -5,
                     "grain": 26, "curve": [[0, 21], [64, 68], [150, 152], [255, 239]]}},
    {"id": "high-noon-hangover", "name": "HIGH NOON HANGOVER", "category": "Film + Print",
     "description": "Bleached sun, warm whites, blunt contrast, and yesterday's grain.",
     "adjustments": {"exposure": .12, "temperature": 17, "contrast": 18, "highlights": -10,
                     "shadows": -14, "whites": 22, "saturation": -10, "grain": 22,
                     "vignette": -8}},
    {"id": "silver-teeth", "name": "SILVER TEETH", "category": "Black + White",
     "description": "Bright metallic monochrome with crisp texture and disciplined blacks.",
     "adjustments": {"black_white": True, "contrast": 24, "highlights": -20, "shadows": 12,
                     "whites": 18, "blacks": -16, "clarity": 18, "texture": 12,
                     "sharpen": 15, "grain": 8}},
    {"id": "noir-means-noir", "name": "NOIR MEANS NOIR", "category": "Black + White",
     "description": "Deep cinematic monochrome, crushed edges, smoke, and consequence.",
     "adjustments": {"black_white": True, "exposure": -.18, "contrast": 38,
                     "highlights": -34, "shadows": -24, "blacks": -30, "clarity": 10,
                     "grain": 20, "vignette": -30}},
    {"id": "good-skin-bad-alibi", "name": "GOOD SKIN, BAD ALIBI", "category": "Portrait",
     "description": "Gentle skin, open shadows, warm colour, and eyes that still have something to hide.",
     "adjustments": {"temperature": 9, "tint": 5, "contrast": -6, "highlights": -22,
                     "shadows": 22, "vibrance": 9, "texture": -12, "clarity": -5,
                     "sharpen": 7, "vignette": -8}},
    {"id": "gasoline-rainbow", "name": "GASOLINE RAINBOW", "category": "Experimental",
     "description": "Aggressive colour separation, oily saturation, and unapologetic contrast.",
     "adjustments": {"tint": 18, "contrast": 30, "highlights": -28, "shadows": 8,
                     "saturation": 22, "vibrance": 38, "clarity": 16, "dehaze": 12,
                     "vignette": -14, "curve": [[0, 4], [45, 28], [120, 135], [210, 235], [255, 255]]}},
)


def all_lewks():
    return copy.deepcopy(list(LEWKS))


def get(lewk_id):
    return next((copy.deepcopy(item) for item in LEWKS if item["id"] == lewk_id), None)


def recipe(lewk_id, strength=100):
    """Return a v1 recipe with the complete LEWK blended as one adjustment layer."""
    lewk = get(lewk_id)
    if lewk is None:
        raise KeyError(f"Unknown built-in LEWK: {lewk_id}")
    amount = max(0.0, min(1.0, float(strength) / 100.0))
    values = copy.deepcopy(DEFAULT_ADJUSTMENTS)
    values.update(copy.deepcopy(lewk["adjustments"]))
    provenance = {"id": lewk["id"], "name": lewk["name"],
                  "strength": int(amount * 100), "provenance": "built-in"}
    layer = {"id": str(time.time_ns()), "name": "LEWK · " + lewk["name"],
             "type": "adjustment", "visible": True, "opacity": amount,
             "blend": "normal", "adjustments": values, "mask": "", "styles": {},
             "lewk": copy.deepcopy(provenance)}
    return {"version": PROJECT_VERSION,
            "adjustments": copy.deepcopy(DEFAULT_ADJUSTMENTS),
            "layers": [layer], "lewk": provenance}

# ===== SNAPSMACK EOF =====
