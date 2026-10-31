"""COLD STORAGE: the photograph fills its tile, and the tile has a size choice.

Sean, 30 October 2026, on build 0.7.30 with craptasti.ca open:
    "thumbs display weird. no choices for different sizes and they are all
     square so why compressed."

He was right twice over, and nothing was wrong with the photographs.

WHAT WAS ACTUALLY HAPPENING. The grid had setUniformItemSizes(True), and
_populate() created every row as QListWidgetItem(label) -- TEXT ONLY, because
the photographs are decoded off-thread and the icons arrive later. Qt fixed the
uniform cell size from that text-only state and never recomputed it, so a
96x96 photograph was squashed into a cell sized for one line of text, inside a
tile as wide as the filename.

MEASURED, offscreen, same widget configuration and the same construction order:
    setUniformItemSizes(True)      234x12 before an icon  ->  234x12 after
    setUniformItemSizes(False)     234x12 before an icon  ->  234x111 after
    uniform + item.setSizeHint()   112x128 before         ->  112x128 after

THE LAST ONE IS WHAT SHIPPED, and the reason is not performance: the cell is
correct BEFORE any photograph loads, so a 3,642-image shelf does not visibly
reflow as the decodes land. Non-uniform reaches the same end state by twitching
its way there. (It is also faster -- 0.024s populate and 0.009s relayout at
3,642 rows against 0.053s and 0.031s -- but 31ms was never a reason to choose
anything, and an earlier version of this argument leaned on it as though it
were.)

AND setGridSize IS NOT THE MECHANISM. It was tried first and recommended in
writing. It fixes the tile WIDTH and leaves the height at 12, so the
photographs stay crushed. The item's own size hint is what does it.

THE FUNCTIONS UNDER TEST ARE STATIC AND TAKE PLAIN NUMBERS, so the arithmetic
can be checked without constructing a Qt widget, the way the search and sort
helpers already are. The widget wiring around them is NOT CHECKED here.

Run:  .python-build/python.exe -m pytest tools/coldsnap/tests -q

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)
SHARED = os.path.join(os.path.dirname(BASE), "_shared")
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

import pytest  # noqa: E402

from coldsnap_qt.cold_storage import StorageMode  # noqa: E402


# -- the size choice -------------------------------------------------------

def test_three_sizes_are_offered():
    """He asked for "choices for different sizes". Three is a choice; two is a
    toggle and five is a menu."""
    assert len(StorageMode.THUMB_SIZES) == 3


def test_the_sizes_are_the_measured_ones():
    """Not guessed. 96 is what the grid shipped with, so his current view
    becomes the SMALLEST option rather than the only one; 160 and 240 are the
    two steps above it that were measured to produce usable cells."""
    assert [px for _label, px in StorageMode.THUMB_SIZES] == [96, 160, 240]


def test_the_smallest_is_what_he_has_today():
    """So nothing he is used to disappears -- it just stops being the ceiling."""
    assert StorageMode.THUMB_SIZES[0][1] == 96


def test_the_default_is_the_middle_one():
    """Ruled by the coordinator: default is M. 96 was the complaint, so
    defaulting to it again would ship the complaint."""
    assert StorageMode.DEFAULT_THUMB == 160
    assert StorageMode.DEFAULT_THUMB in [px for _l, px in StorageMode.THUMB_SIZES]


def test_every_size_has_a_plain_label():
    for label, _px in StorageMode.THUMB_SIZES:
        assert label and label[0].isupper()
        assert "px" not in label.lower()      # he picks a size, not a number


# -- the cell arithmetic, which is the actual fix --------------------------

def test_the_cell_is_taller_than_the_photograph():
    """The whole fault was a cell shorter than its photograph. The cell must
    leave room for the icon AND the label line under it."""
    for _label, px in StorageMode.THUMB_SIZES:
        w, h = StorageMode.cell_for_icon(px, 16)
        assert h > px, "cell height %d does not fit a %dpx photograph" % (h, px)


def test_the_cell_is_wider_than_the_photograph():
    for _label, px in StorageMode.THUMB_SIZES:
        w, h = StorageMode.cell_for_icon(px, 16)
        assert w > px


def test_the_cell_leaves_room_for_the_label_line():
    """Two label heights, same icon: the taller font must get the taller cell,
    or the filename is clipped at a larger system font size."""
    small = StorageMode.cell_for_icon(160, 12)
    large = StorageMode.cell_for_icon(160, 30)
    assert large[1] > small[1]
    assert large[1] - small[1] == 18          # exactly the font difference


def test_the_cell_width_does_not_depend_on_the_label():
    """THE TILE WIDTH MUST COME OFF THE FILENAME. That is what made the tiles
    234px wide and mostly empty: Qt sized them to '20260624_200003.jpg'."""
    assert StorageMode.cell_for_icon(160, 12)[0] == StorageMode.cell_for_icon(160, 30)[0]


def test_a_bigger_photograph_gets_a_bigger_cell():
    sizes = [StorageMode.cell_for_icon(px, 16) for _l, px in StorageMode.THUMB_SIZES]
    assert [w for w, _h in sizes] == sorted(w for w, _h in sizes)
    assert [h for _w, h in sizes] == sorted(h for _w, h in sizes)
    assert len(set(sizes)) == 3               # three distinct cells, not three names


def test_the_measured_cells_are_what_shipped():
    """The numbers from the harness run, pinned so a later tweak has to be
    deliberate rather than accidental."""
    assert StorageMode.cell_for_icon(96, 12) == (112, 128)
    assert StorageMode.cell_for_icon(64, 12) == (80, 96)
    assert StorageMode.cell_for_icon(160, 12) == (176, 192)


def test_a_nonsense_label_height_does_not_produce_a_broken_cell():
    """Qt has handed back a zero font height on a headless run before now."""
    w, h = StorageMode.cell_for_icon(96, 0)
    assert w > 96 and h > 96


# -- remembering the choice ------------------------------------------------

def test_the_choice_is_remembered_in_the_same_store_as_the_window():
    """It is a VIEW preference, like the window geometry, so it belongs with
    it -- not in the application config, which holds the site credentials and
    would be rewritten wholesale to save a thumbnail size."""
    assert StorageMode.SETTINGS_ORG == "SnapSmack"
    assert StorageMode.SETTINGS_APP == "COLD SNAP"
    assert StorageMode.THUMB_SETTING_KEY.startswith("cold_storage/")


@pytest.mark.parametrize("stored,expected", [
    (96, 96), (160, 160), (240, 240),
    (None, StorageMode.DEFAULT_THUMB),        # never chosen
    (0, StorageMode.DEFAULT_THUMB),           # cleared
    (37, StorageMode.DEFAULT_THUMB),          # not one of ours
    ("160", 160),                             # QSettings hands back strings
    ("rubbish", StorageMode.DEFAULT_THUMB),
])
def test_a_stored_value_is_validated_before_it_is_trusted(stored, expected):
    """A settings file is editable by hand and survives upgrades. A size that
    is not one of ours must fall back, not be used."""
    assert StorageMode.valid_thumb_size(stored) == expected

# ===== SNAPSMACK EOF =====
