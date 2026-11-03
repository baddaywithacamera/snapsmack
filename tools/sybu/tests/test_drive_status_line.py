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
# ===== SNAPSMACK EOF =====
