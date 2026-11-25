# SNAPSMACK_EOF_HEADER: preserve canonical EOF marker below.
import os
import sys
from pathlib import Path
os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

def test_empty_queue_drive_status_is_visible_and_invalidated(monkeypatch):
    from PySide6.QtWidgets import QApplication, QMessageBox
    import sybu_qt
    app = QApplication.instance() or QApplication([])
    w = sybu_qt.Window()
    monkeypatch.setattr(QMessageBox, 'information', lambda *a: None)
    monkeypatch.setattr(QMessageBox, 'warning', lambda *a: None)
    w.drive.setChecked(True)
    assert w.table.rowCount() == 0
    assert 'Enabled' in w.drive_status_line.text()
    w._drive_check_done({'ok':True,'account':'fixture@example.invalid','name':'Test folder','free_bytes':1000})
    assert 'Connected as fixture@example.invalid' in w.drive_status_line.text()
    assert 'Last verified' in w.drive_status_line.text()
    w.drive.setChecked(False)
    assert 'Disabled' in w.drive_status_line.text()
    assert 'Last verified' not in w.drive_status_line.text()
    w._drive_check_done({'ok':False,'reason':'not_writable','detail':'fixture denial'})
    assert 'cannot upload' in w.drive_status_line.text()
    assert 'fixture denial' in w.drive_status_line.text()
    w.close()
def test_check_drive_uses_visible_checkbox_without_saving(monkeypatch):
    from PySide6.QtWidgets import QApplication
    import sybu_qt
    app = QApplication.instance() or QApplication([])
    w = sybu_qt.Window()
    w.engine.config['drive_enabled'] = False
    w.drive.setChecked(True)
    called = []
    monkeypatch.setattr(w, '_async', lambda name, operation: called.append(name))
    w._drive_check()
    assert w.engine.config['drive_enabled'] is True
    assert called == ['drive_check']
    assert w.table.rowCount() == 0
    w.close()

def test_separate_header_connection_indicators():
    from PySide6.QtWidgets import QApplication
    import sybu_qt
    app = QApplication.instance() or QApplication([])
    w = sybu_qt.Window()
    w._set_link_indicator(w.status, 'CMS', True)
    w.engine.drive_service = None
    w._drive_status_changed()
    assert w.status.text() == '● CMS Connected'
    assert w.status.objectName() == 'LinkOn'
    assert w.drive_indicator.text() == '● Drive Disconnected'
    assert w.drive_indicator.objectName() == 'LinkOff'
    w.engine.drive_service = object()
    w.drive.setChecked(True); w._drive_status_changed()
    assert w.drive_indicator.text() == '● Drive Connected'
    assert w.drive_indicator.objectName() == 'LinkOn'
    w.drive.setChecked(False)
    assert w.drive_indicator.objectName() == 'LinkOff'
    assert w.status.objectName() == 'LinkOn'
    w.close()


def test_status_pills_one_click_reconnect_only_when_disconnected(monkeypatch):
    from PySide6.QtCore import QPointF, Qt
    from PySide6.QtGui import QMouseEvent
    from PySide6.QtWidgets import QApplication
    import sybu_qt

    app = QApplication.instance() or QApplication([])
    w = sybu_qt.Window()
    auth_calls = []
    connect_calls = []
    monkeypatch.setattr(w, '_auth_drive', lambda: auth_calls.append('auth_drive'))
    monkeypatch.setattr(w, '_connect', lambda: connect_calls.append('connect'))

    def click(widget):
        ev = QMouseEvent(
            QMouseEvent.Type.MouseButtonPress,
            QPointF(5, 5),
            QPointF(5, 5),
            Qt.MouseButton.LeftButton,
            Qt.MouseButton.LeftButton,
            Qt.KeyboardModifier.NoModifier,
        )
        widget.mousePressEvent(ev)

    # Disconnected pills trigger reconnect directly
    w._set_link_indicator(w.drive_indicator, 'Drive', False)
    w._set_link_indicator(w.status, 'CMS', False)
    w.drive.setChecked(False)
    click(w.drive_indicator)
    click(w.status)
    assert auth_calls == ['auth_drive']
    assert w.drive.isChecked() is True
    assert connect_calls == ['connect']

    # Connected pills do nothing on click (not a disconnect toggle)
    w._set_link_indicator(w.drive_indicator, 'Drive', True)
    w._set_link_indicator(w.status, 'CMS', True)
    click(w.drive_indicator)
    click(w.status)
    assert auth_calls == ['auth_drive']
    assert connect_calls == ['connect']
    w.close()

# ===== SNAPSMACK EOF =====
