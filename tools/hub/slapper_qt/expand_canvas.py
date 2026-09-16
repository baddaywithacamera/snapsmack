"""Shared cursor rules for the bounded canvas-edge expansion control."""

from PySide6.QtCore import Qt


class ExpandCanvas:
    @staticmethod
    def _cursor_for_edges(edges):
        pair = frozenset(edges)
        if pair in (frozenset(("left", "top")),
                    frozenset(("right", "bottom"))):
            return Qt.SizeFDiagCursor
        if pair in (frozenset(("right", "top")),
                    frozenset(("left", "bottom"))):
            return Qt.SizeBDiagCursor
        if "left" in pair or "right" in pair:
            return Qt.SizeHorCursor
        if "top" in pair or "bottom" in pair:
            return Qt.SizeVerCursor
        return Qt.ArrowCursor


# ===== SNAPSMACK EOF =====
