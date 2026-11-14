"""What the model is shown: how much of the photograph, and in whose colours.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

THREE FAULTS, MEASURED.

1. CONTEXT. The tile's margin was a fraction of the SELECTION with a 96-pixel
   floor. Paint a 40-pixel blemish on a 6000-pixel frame and the margin was
   max(96, 30) = 96: a 232-pixel tile, under 4% of the picture. The model was
   asked to invent content matching a photograph it had been shown almost none
   of. The floor is now a fraction of the IMAGE's long edge.

2. THE TRADE THAT CAME WITH IT. The tile is resized to the provider's working
   resolution, so a wider tile spends fewer of those pixels on the selection.
   Applied unconditionally, the 20% floor took that 40-pixel blemish from 88
   model pixels down to EIGHT at the local model's 512 cap -- a worse tool, not
   a better one. context_floor_ratio() gives back only as much context as the
   selection can afford.

3. COLOUR. Every image model answers in sRGB and never asks what it was given.
   FloatImage.display_proxy() builds its Pillow image from the array and sets
   no profile, so a Display P3 document reached the AI tools as unlabelled
   numbers, was read as sRGB, and the sRGB answer was pasted back into a P3
   document. The error happened twice, in opposite directions.
"""

import io
import pathlib
import sys

import pytest
from PIL import Image, ImageCms

HUB = pathlib.Path(__file__).resolve().parents[1]
SHARED = HUB.parent / "_shared"
for folder in (HUB, SHARED):
    if str(folder) not in sys.path:
        sys.path.insert(0, str(folder))

import gemini_image_edit as g                                  # noqa: E402
from slapper_qt import local_fill                              # noqa: E402


def _centre(size, mask_px):
    cx, cy = size[0] // 2, size[1] // 2
    half = mask_px // 2
    return (cx - half, cy - half, cx + half, cy + half)


def _side(box):
    return box[2] - box[0]


def _selection_pixels(mask_px, box, work):
    """How many pixels of the selection survive the resize to ``work``."""
    side = _side(box)
    return round(mask_px * min(work / float(side), 4.0))


# ── 1. the context floor exists and is tied to the IMAGE ───────────────────

def test_a_small_blemish_on_a_big_frame_no_longer_gets_four_percent():
    size, mask_px = (6000, 4000), 40
    bounds = _centre(size, mask_px)
    before = g.square_tile_box(size, bounds, floor_ratio=0.0)
    after = g.square_tile_box(size, bounds)
    assert _side(before) == 232, "the old behaviour changed; this test's premise is stale"
    assert _side(after) > _side(before) * 5
    assert _side(after) >= int(max(size) * g.CONTEXT_MARGIN_OF_LONG_EDGE)


def test_the_floor_scales_with_the_photograph_not_the_selection():
    """The same selection on a bigger frame must get MORE context, not the same."""
    bounds = _centre((6000, 4000), 100)
    small = g.square_tile_box((1200, 800), _centre((1200, 800), 100))
    large = g.square_tile_box((6000, 4000), bounds)
    assert _side(large) > _side(small) * 3


def test_passing_zero_restores_the_old_behaviour_exactly():
    for size in [(6000, 4000), (3000, 2000), (1200, 800)]:
        for mask_px in (40, 200, 800):
            bounds = _centre(size, mask_px)
            margin = max(96, int(mask_px * 0.75))
            side = min(mask_px + margin * 2, *size)
            assert _side(g.square_tile_box(size, bounds, floor_ratio=0.0)) == side


def test_the_constant_is_the_knob_and_turning_it_moves_the_tile():
    size, bounds = (6000, 4000), _centre((6000, 4000), 200)
    widths = [_side(g.square_tile_box(size, bounds, floor_ratio=r))
              for r in (0.0, 0.05, 0.10, 0.20)]
    assert widths == sorted(widths)
    assert len(set(widths)) == 4, "the floor is not actually reaching the tile: %r" % widths


def test_a_negative_or_silly_floor_cannot_shrink_the_tile():
    # NOTE, from the mutation run: removing the max(0.0, ...) guard inside
    # square_tile_box does not fail this test, and should not. The three-way
    # max() already floors the margin at minimum_margin (96), so a negative
    # ratio changes nothing. The guard is kept as a statement of intent, not
    # as load-bearing code, and that mutant is equivalent rather than missed.
    size, bounds = (6000, 4000), _centre((6000, 4000), 200)
    baseline = _side(g.square_tile_box(size, bounds, floor_ratio=0.0))
    assert _side(g.square_tile_box(size, bounds, floor_ratio=-5.0)) == baseline
    huge = g.square_tile_box(size, bounds, floor_ratio=50.0)
    assert _side(huge) == min(size), "the tile escaped the frame"


# ── 2. the selection keeps enough pixels to generate into ──────────────────

@pytest.mark.parametrize("size", [(6000, 4000), (3000, 2000), (1200, 800)])
@pytest.mark.parametrize("mask_px", [8, 40, 200, 800])
@pytest.mark.parametrize("work", [local_fill.WORK_RESOLUTION, g.WORK_RESOLUTION])
def test_the_selection_never_starves(size, mask_px, work):
    bounds = _centre(size, mask_px)
    box = g.square_tile_box(
        size, bounds, floor_ratio=g.context_floor_ratio(size, bounds, work))
    kept = _selection_pixels(mask_px, box, work)
    floor = g.MIN_SELECTION_PIXELS_AT_WORK_RESOLUTION
    # The selection may be below the floor only because it was already that
    # small at full resolution -- the tile cannot give back pixels the
    # photograph never had.
    assert kept >= min(floor, mask_px), (
        "%s mask %d at work %d: selection arrived as %d pixels"
        % (size, mask_px, work, kept))


def test_the_unbounded_floor_really_would_have_starved_it():
    """The regression this guards against, measured rather than asserted."""
    size, mask_px = (6000, 4000), 40
    bounds = _centre(size, mask_px)
    naive = g.square_tile_box(size, bounds, floor_ratio=g.CONTEXT_MARGIN_OF_LONG_EDGE)
    bounded = g.square_tile_box(
        size, bounds,
        floor_ratio=g.context_floor_ratio(size, bounds, local_fill.WORK_RESOLUTION))
    starved = _selection_pixels(mask_px, naive, local_fill.WORK_RESOLUTION)
    kept = _selection_pixels(mask_px, bounded, local_fill.WORK_RESOLUTION)
    assert starved < 16, "premise stale: the naive floor no longer starves it (%d)" % starved
    assert kept >= g.MIN_SELECTION_PIXELS_AT_WORK_RESOLUTION


def test_gemini_can_afford_more_context_than_the_local_model():
    """Different providers, different budgets. One number for both is wrong."""
    size, bounds = (6000, 4000), _centre((6000, 4000), 200)
    local = g.square_tile_box(size, bounds, floor_ratio=g.context_floor_ratio(
        size, bounds, local_fill.WORK_RESOLUTION))
    gemini = g.square_tile_box(size, bounds, floor_ratio=g.context_floor_ratio(
        size, bounds, g.WORK_RESOLUTION))
    assert _side(gemini) > _side(local)


def test_context_floor_ratio_is_safe_on_nonsense_input():
    bounds = _centre((100, 100), 10)
    assert g.context_floor_ratio((100, 100), bounds, 0) == g.CONTEXT_MARGIN_OF_LONG_EDGE
    assert g.context_floor_ratio((100, 100), bounds, 512, minimum_selection=0) == \
        g.CONTEXT_MARGIN_OF_LONG_EDGE
    assert g.context_floor_ratio((100, 100), bounds, 512) >= 0.0
    # A selection already larger than the working resolution can afford no
    # margin at all, and must say zero rather than a negative ratio.
    big = _centre((9000, 9000), 8000)
    assert g.context_floor_ratio((9000, 9000), big, 64) == 0.0


# ── the small mask at the frame EDGE, which is where tiles break ───────────

@pytest.mark.parametrize("corner", [(0, 0), (1, 0), (0, 1), (1, 1)])
@pytest.mark.parametrize("mask_px", [8, 40])
def test_a_small_mask_in_a_corner_stays_inside_the_frame(corner, mask_px):
    size = (6000, 4000)
    x = 0 if corner[0] == 0 else size[0] - mask_px
    y = 0 if corner[1] == 0 else size[1] - mask_px
    bounds = (x, y, x + mask_px, y + mask_px)
    box = g.square_tile_box(size, bounds, floor_ratio=g.context_floor_ratio(
        size, bounds, local_fill.WORK_RESOLUTION))
    assert box[0] >= 0 and box[1] >= 0
    assert box[2] <= size[0] and box[3] <= size[1]
    assert _side(box) > 0
    # The selection has to still be inside the tile, or the model is sent a
    # mask that points at nothing.
    assert box[0] <= x and box[1] <= y
    assert box[2] >= x + mask_px and box[3] >= y + mask_px


def test_an_edge_mask_gets_its_context_pushed_inward_not_cropped_away():
    size, mask_px = (6000, 4000), 40
    corner = (0, 0, mask_px, mask_px)
    centre = _centre(size, mask_px)
    ratio = g.context_floor_ratio(size, corner, local_fill.WORK_RESOLUTION)
    assert _side(g.square_tile_box(size, corner, floor_ratio=ratio)) == \
        _side(g.square_tile_box(size, centre, floor_ratio=ratio)), \
        "a selection in the corner got less context than the same one in the middle"


# ── 3. colour ──────────────────────────────────────────────────────────────

def _srgb_bytes():
    return ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB")).tobytes()


def test_a_document_with_no_profile_is_left_alone_byte_for_byte():
    image = Image.new("RGB", (32, 32), (200, 40, 40))
    assert g.to_srgb(image, b"") is image
    assert g.from_srgb(image, b"") is image
    assert g.to_srgb(image, None) is image


def test_a_real_profile_goes_through_the_colour_engine():
    """The early return must not be swallowing every conversion.

    to_srgb returns the SAME object when it has nothing to do or when the
    conversion failed, and a NEW one when ImageCms actually ran. That is the
    difference between the fix working and the fix being a no-op that looks
    like one.
    """
    profile = _srgb_bytes()
    image = Image.new("RGB", (16, 16), (220, 30, 30))
    sent = g.to_srgb(image, profile)
    back = g.from_srgb(sent, profile)
    assert sent is not image, "to_srgb never reached ImageCms"
    assert back is not sent, "from_srgb never reached ImageCms"
    assert sent.size == image.size and sent.mode == "RGB"


def test_a_document_already_in_srgb_comes_out_unchanged():
    """Converting sRGB to sRGB must not drift the colours of the one case
    that is already correct, which is most photographs."""
    profile = _srgb_bytes()
    image = Image.new("RGB", (16, 16), (220, 30, 30))
    assert g.to_srgb(image, profile).getpixel((8, 8)) == (220, 30, 30)
    assert g.from_srgb(image, profile).getpixel((8, 8)) == (220, 30, 30)


# A REAL DISPLAY P3 ROUND TRIP IS **NOT CHECKED** HERE.
#
# Pillow's ImageCms can only build profiles it has names for -- sRGB, LAB,
# XYZ -- and no P3 profile ships with this repository, so this suite has no
# wide-gamut profile to convert from. Changing the colour temperature of an
# sRGB profile does not help: the primaries are the same, so littlecms
# correctly returns the pixels unchanged (measured: 5000K, 7500K and 9300K all
# come back identical).
#
# What is proved above: the conversion is wired in both directions, it reaches
# the colour engine rather than silently returning the input, it is a no-op
# for sRGB, and a missing or broken profile costs the colour fix and not the
# edit. What is NOT proved is the size of the shift on an actual P3 phone
# photograph. That needs a P3 file and somebody's eyes.


def test_a_broken_profile_costs_the_person_the_colour_fix_not_the_edit():
    image = Image.new("RGB", (16, 16), (10, 120, 200))
    assert g.to_srgb(image, b"this is not an ICC profile").tobytes() == image.tobytes()
    assert g.from_srgb(image, b"this is not an ICC profile").tobytes() == image.tobytes()


def test_the_render_that_feeds_the_ai_tools_carries_the_profile():
    """A source assertion, with the reason stated.

    display_proxy() returns a plain Pillow image and cannot be given a profile
    without changing every caller of it, so render_document_image() attaches
    one. Nothing downstream can convert colours it was not told about, and a
    missing attachment is silent -- the fill just comes back wrong. Driving the
    editor window needs a document and a Qt app; pinning the line does not.
    """
    source = (HUB / "slapper_qt" / "editor_window.py").read_text(encoding="utf-8")
    body = source[source.index("def render_document_image("):]
    body = body[:body.index("def apply_ai_generation(")]
    assert "icc_profile" in body, "the render stopped carrying the colour profile"
    assert "render_float()" in body


def test_the_dialog_converts_in_both_directions():
    source = (HUB / "slapper_qt" / "ai_heal_dialog.py").read_text(encoding="utf-8")
    work = source[source.index("def work("):source.index("def _begin_run(")]
    assert "to_srgb(" in work, "nothing converts the tile before it is sent"
    assert work.count("from_srgb(") == 2, (
        "both providers' patches must be converted back, found %d"
        % work.count("from_srgb("))


# ── the blank prompt ───────────────────────────────────────────────────────

def test_a_blank_box_asks_for_the_surrounding_material():
    from slapper_qt import ai_heal_dialog as d
    for blank in ("", "   ", None):
        assert d.prompt_for(blank, True) == d.BLANK_FILL_HINT
        assert d.prompt_for(blank, False) == d.BLANK_HEAL_HINT
    assert "texture" in d.BLANK_FILL_HINT and "grain" in d.BLANK_FILL_HINT
    assert "remove" in d.BLANK_HEAL_HINT


def test_typed_words_always_win():
    from slapper_qt import ai_heal_dialog as d
    assert d.prompt_for("a red barn", True) == "a red barn"
    assert d.prompt_for("  a red barn  ", False) == "a red barn"


def test_both_providers_are_given_the_same_blank_instruction():
    """They used to fall back to their own separate built-in wording."""
    from slapper_qt import ai_heal_dialog as d
    source = (HUB / "slapper_qt" / "ai_heal_dialog.py").read_text(encoding="utf-8")
    work = source[source.index("def work("):source.index("def _begin_run(")]
    assert work.count("prompt_for(self.prompt.text()") == 2
    assert "self.prompt.text()," not in work.replace(
        "prompt_for(self.prompt.text(),", ""), "a provider still gets the raw box"
    assert d.prompt_for("", True) != ""


if __name__ == "__main__":
    raise SystemExit(pytest.main([__file__, "-q"]))

# ===== SNAPSMACK EOF =====
