"""GYSS empty-state and label rendering on an isolated full window.
SNAPSMACK_EOF_HEADER: preserve final marker.
"""
import os,sys
from pathlib import Path
from unittest.mock import patch
os.environ.setdefault('QT_QPA_PLATFORM','offscreen')
TOOL=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(TOOL));sys.path.insert(0,str(TOOL.parent/'_shared'))
from PySide6.QtCore import QSettings,Qt,QPoint
from PySide6.QtWidgets import QApplication,QListWidgetItem,QLabel
from PySide6.QtGui import QPalette,QFontDatabase
import gyss_qt

def test_empty_state_and_rendered_labels(tmp_path):
    app=QApplication.instance() or QApplication([])
    for font in ('segoeui.ttf','segoeuib.ttf'):
        path=Path(os.environ.get('WINDIR','C:/Windows'))/'Fonts'/font
        if path.exists():QFontDatabase.addApplicationFont(str(path))
    app.setStyleSheet(gyss_qt.STYLE)
    settings=QSettings(str(tmp_path/'isolated.ini'),QSettings.IniFormat)
    with patch.object(gyss_qt,'QSettings',return_value=settings),patch.object(gyss_qt.Window,'load_profiles',lambda self:None):
        win=gyss_qt.Window();win.resize(1420,900);win.show();win.show_page(1);app.processEvents()
        assert win.sort_empty.isVisible()
        assert not win.photo_list.isVisible() and not win.sort_edit_panel.isVisible()
        assert win.grab().save(str(tmp_path/'sort-empty.png'))
        win.sort_empty_button.click();assert win.pages.currentIndex()==0
        for ident in range(1,6):
            item=QListWidgetItem('Photograph '+str(ident));item.setData(Qt.UserRole,{'id':ident});win.photo_list.addItem(item)
        win.show_page(1);app.processEvents()
        assert not win.sort_empty.isVisible()
        assert win.photo_list.isVisible() and win.sort_edit_panel.isVisible()
        heading=next(label for label in win.sort_edit_panel.findChildren(QLabel) if label.text()=='Selected photograph')
        app.processEvents()
        point=heading.mapTo(win,QPoint(0,0))
        assert win.grab().toImage().pixelColor(point).name()=='#18231c'
        # Render proof only; all profile loading and settings are isolated.
        for number,name in [(0,'library'),(1,'sort'),(3,'grid'),(4,'images'),(5,'sites')]:
            win.show_page(number);app.processEvents()
            assert win.grab().save(str(tmp_path/(name+'.png')))
            for label in win.pages.widget(number).findChildren(QLabel):
                if label.objectName()!='Good':assert not label.autoFillBackground()
        win.close()

# ===== SNAPSMACK EOF =====
