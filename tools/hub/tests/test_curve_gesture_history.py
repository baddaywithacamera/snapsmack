"""A curve drag is one undo gesture, including click-add and right-delete.
SNAPSMACK_EOF_HEADER: keep the canonical EOF marker last.
"""
import os
import sys
from pathlib import Path
from types import SimpleNamespace
os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "_shared"))
from PySide6.QtCore import Qt, QPointF, QEvent
from PySide6.QtGui import QMouseEvent
from PySide6.QtWidgets import QApplication
from slapper_qt.curve_editor import CurveEditor
from slapper_qt.editor_window import EditorWindow
APP = QApplication.instance() or QApplication([])


def event(kind, x, y, button=Qt.LeftButton):
    return QMouseEvent(kind, QPointF(x, y), QPointF(x, y), button, button, Qt.NoModifier)


def test_drag_changes_many_times_but_commits_once():
    widget = CurveEditor()
    changed, committed = [], []
    target = {}
    window = SimpleNamespace(active_adjustments=lambda: target,
                             _schedule_render=lambda: None, _interactive_render=False)
    widget.changed.connect(lambda key, points: changed.append(points))
    widget.changed.connect(lambda key, points: EditorWindow._on_curve_changed(window, key, points))
    widget.committed.connect(committed.append)
    canvas = widget.canvas
    canvas.mousePressEvent(event(QEvent.MouseButtonPress, 100, 100))
    for x in range(101, 130):
        canvas.mouseMoveEvent(event(QEvent.MouseMove, x, 80))
    assert len(changed) == 30
    assert committed == []
    assert window._interactive_render
    assert target["curve"] == changed[-1]
    canvas.mouseReleaseEvent(event(QEvent.MouseButtonRelease, 129, 80))
    assert committed == ["curve"]
    # Deleting the interior point is a complete gesture without a drag.
    position = canvas._to_px(canvas._points()[1])
    canvas.mousePressEvent(event(QEvent.MouseButtonPress, position.x(), position.y(), Qt.RightButton))
    assert committed == ["curve", "curve"]
    assert len(canvas._points()) == 2
    widget.close()

# ===== SNAPSMACK EOF =====
