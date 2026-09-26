"""False-colour LEWKS relocate hues and vignettes follow the frame."""

from PIL import Image

import built_in_lewks
import editor_engine


def test_hue_mix_relocates_green_without_recolouring_blue():
    image = Image.new("RGB", (2, 1))
    image.putdata([(0, 255, 0), (0, 0, 255)])
    settings = dict(editor_engine.DEFAULT_ADJUSTMENTS)
    settings["col_hue_green"] = -105
    changed = editor_engine.apply_adjustments(image, settings)
    green, blue = list(changed.getdata())
    assert green[0] > green[1] and green[0] > green[2]  # green became red/coral
    assert blue[2] > blue[0] and blue[2] > blue[1]


def test_false_colour_family_is_substantial_and_editable():
    family = [item for item in built_in_lewks.all_lewks()
              if item["category"] == "False Colour + IR"]
    assert len(family) >= 5
    assert all(any(key.startswith("col_hue_") for key in item["adjustments"])
               for item in family)


def test_vignette_follows_frame_more_than_an_oval():
    image = Image.new("RGB", (301, 201), "white")
    settings = dict(editor_engine.DEFAULT_ADJUSTMENTS)
    settings.update({"vignette": -100, "vignette_size": 45,
                     "vignette_feather": 25})
    changed = editor_engine.apply_adjustments(image, settings)
    # The middle of the top and side edges should receive comparable treatment;
    # the old ellipse made the short edge announce itself much sooner.
    top = changed.getpixel((150, 0))[0]
    side = changed.getpixel((0, 100))[0]
    assert abs(top - side) <= 2
    assert changed.getpixel((150, 100))[0] == 255
