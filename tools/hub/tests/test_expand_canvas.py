import sys
from pathlib import Path


HUB = Path(__file__).resolve().parents[1]
if str(HUB) not in sys.path:
    sys.path.insert(0, str(HUB))

from slapper_qt.expand_canvas import clamp_edges


def test_drag_stops_at_budget_instead_of_entering_invalid_state():
    start = {"left": 0.0, "top": 0.0, "right": 0.0, "bottom": 0.0}
    candidate = {"left": 12.0, "top": 0.0, "right": 10.0, "bottom": 10.0}
    allowed = lambda edges: sum(edges.values()) <= 20.0
    result = clamp_edges(start, candidate, allowed)
    assert sum(result.values()) <= 20.0
    assert abs(sum(result.values()) - 20.0) < 0.0001
    assert result["left"] > result["right"]
    assert result["right"] == result["bottom"]


def test_legal_inward_drag_is_not_changed():
    start = {"left": 10.0, "top": 0.0, "right": 8.0, "bottom": 0.0}
    candidate = {"left": 4.0, "top": 0.0, "right": 8.0, "bottom": 0.0}
    assert clamp_edges(start, candidate, lambda edges: sum(edges.values()) <= 20.0) == candidate


# ===== SNAPSMACK EOF =====
