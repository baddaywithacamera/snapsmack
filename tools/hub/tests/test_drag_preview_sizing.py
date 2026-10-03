# SNAPSMACK_EOF_HEADER
"""The live drag preview must be sized by the photograph, not by the worst case.

The proxy used during a slider drag was pinned at 480 px so that the heaviest
imaginable document would still make its frame budget. Every edit paid for that.
Measured on a fitted preview, one exposure slider costs 9 ms at 480 px and 33 ms
at 800 px, so a light edit was shown at a quarter of the detail it had time for,
and the photographer was looking at a worse picture than the machine could draw.

The window now measures each drag frame and sizes the next one from that, inside
the old floor and the window itself.
"""

import os
import sys

import pytest

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, HUB)
sys.path.insert(0, os.path.join(os.path.dirname(HUB), "_shared"))

from slapper_qt import editor_window as editor  # noqa: E402

FLOOR = (480, 320)
CEILING = (1300, 867)          # roughly a 1920x1080 screen's canvas
FLOOR_MEGAPIXELS = FLOOR[0] * FLOOR[1] / 1e6


class _View:
    def viewport_target(self, interactive=False):
        return FLOOR if interactive else CEILING


class _Job:
    def __init__(self, elapsed_ms, megapixels):
        self.elapsed_ms = elapsed_ms
        self.rendered_megapixels = megapixels


@pytest.fixture
def window():
    # The real methods, without building any Qt widgets.
    instance = editor.EditorWindow.__new__(editor.EditorWindow)
    instance.view = _View()
    instance._drag_cost_per_megapixel = None
    return instance


def _after_frame(window, ms_at_floor):
    window._note_drag_cost(_Job(ms_at_floor, FLOOR_MEGAPIXELS))
    return window._interactive_target()


def test_nothing_measured_yet_uses_the_safe_floor(window):
    assert window._interactive_target() == FLOOR


def test_a_light_edit_gets_a_much_bigger_preview(window):
    width, _height = _after_frame(window, 9.0)      # one exposure slider
    assert width > 1000, (
        f"a 9 ms edit should afford most of the window, got {width} px")


def test_a_moderate_edit_gets_a_middling_preview(window):
    width, _height = _after_frame(window, 15.0)
    assert FLOOR[0] < width < CEILING[0]


def test_a_heavy_edit_falls_back_to_the_floor(window):
    width, height = _after_frame(window, 220.0)
    assert (width, height) == FLOOR


def test_the_preview_never_exceeds_what_the_window_shows(window):
    width, height = _after_frame(window, 0.01)      # absurdly cheap
    assert width <= CEILING[0] and height <= CEILING[1]


def test_every_choice_stays_inside_the_frame_budget(window):
    """Whatever it picks, the frame it picked must still fit the budget."""
    for ms_at_floor in (3.0, 9.0, 15.0, 49.0, 120.0, 400.0):
        window._drag_cost_per_megapixel = None
        width, height = _after_frame(window, ms_at_floor)
        cost_per_megapixel = ms_at_floor / FLOOR_MEGAPIXELS
        predicted = cost_per_megapixel * (width * height / 1e6)
        if (width, height) != FLOOR:
            assert predicted <= editor.DRAG_FRAME_BUDGET_MS * 1.05, (
                f"{ms_at_floor} ms/frame chose {width}x{height}, "
                f"which would cost {predicted:.0f} ms")


def test_it_settles_instead_of_oscillating(window):
    """Feed back the true cost of each size it picks; it must hold steady."""
    cost_per_megapixel = 9.0 / FLOOR_MEGAPIXELS
    _after_frame(window, 9.0)
    sizes = []
    for _ in range(6):
        width, height = window._interactive_target()
        sizes.append((width, height))
        actual = cost_per_megapixel * (width * height / 1e6)
        window._note_drag_cost(_Job(actual, width * height / 1e6))
    assert len(set(sizes)) == 1, f"preview size oscillated: {sizes}"


def test_a_document_getting_heavier_drops_resolution_at_once(window):
    """Switching Clarity on mid-drag must not leave one slow frame repeating."""
    light = _after_frame(window, 9.0)
    assert light[0] > 1000
    window._note_drag_cost(_Job(400.0, light[0] * light[1] / 1e6))
    assert window._interactive_target() == FLOOR


def test_a_failed_or_untimed_frame_does_not_poison_the_estimate(window):
    _after_frame(window, 9.0)
    before = window._drag_cost_per_megapixel
    window._note_drag_cost(_Job(0.0, 0.0))
    assert window._drag_cost_per_megapixel == before


# ===== SNAPSMACK EOF =====
