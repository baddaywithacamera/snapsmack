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
        assert not win.sort_selection_bar.isVisible() and not win.sort_action_bar.isVisible()
        assert not win.photo_list.isVisible() and not win.sort_edit_panel.isVisible()
        assert win.grab().save(str(tmp_path/'sort-empty.png'))
        win.sort_empty_button.click();assert win.pages.currentIndex()==0
        for ident in range(1,6):
            item=QListWidgetItem('Photograph '+str(ident));item.setData(Qt.UserRole,{'id':ident});win.photo_list.addItem(item)
        win.show_page(1);app.processEvents()
        assert not win.sort_empty.isVisible()
        assert win.sort_selection_bar.isVisible() and win.sort_action_bar.isVisible()
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

def test_full_window_complete_and_partial_drag(tmp_path):
    from PySide6.QtCore import QSize
    from test_sort_reorder import drag,ids
    app=QApplication.instance() or QApplication([])
    settings=QSettings(str(tmp_path/'isolated.ini'),QSettings.IniFormat)
    with patch.object(gyss_qt,'QSettings',return_value=settings),patch.object(gyss_qt.Window,'load_profiles',lambda self:None):
        win=gyss_qt.Window();win.show();win.mode='photoblog'
        win.photos=[{'id':i,'sort_order':i} for i in range(1,6)]
        win.sort_library_ids=[1,2,3,4,5]
        for photo in win.photos:
            item=QListWidgetItem(str(photo['id']));item.setData(Qt.UserRole,photo);item.setSizeHint(QSize(150,140));win.photo_list.addItem(item)
        win.show_page(1);win.configure_mode();app.processEvents()
        assert win.can_reorder_photos() and win.photo_list.dragEnabled()
        win.grab().save(str(tmp_path/'complete-before-drag.png'))
        drag(win.photo_list,0,4,after=True)
        win.grab().save(str(tmp_path/'complete-after-drag.png'))
        assert ids(win.photo_list)==[2,3,4,5,1]
        assert sorted(p['id'] for p in win.ordered())==[1,2,3,4,5]
        # A mismatched server snapshot must disable the custom pointer path too.
        win.sort_library_ids=[1,2,3,4,5,6];win.configure_mode()
        before=ids(win.photo_list);drag(win.photo_list,0,4,after=True)
        assert not win.photo_list.dragEnabled() and ids(win.photo_list)==before
        win.grab().save(str(tmp_path/'partial-drag-disabled.png'))
        assert 'Reordering is off' in win.sort_intro.text()
        # Exercise the real Publish payload builder with a fake API only.
        class FakeAPI:
            def __init__(self):self.updates=[]
            def ping(self):return {'site_mode':'photoblog'}
            def batch(self,rows):self.updates=rows;return {'applied':len(rows)}
        win.api=FakeAPI();win.profile={'site_url':'https://example.test'}
        win.original={p['id']:dict(p) for p in win.photos}
        item=win.photo_list.item(0);row=dict(item.data(Qt.UserRole));row['title']='Edited title';row['dirty']=True;item.setData(Qt.UserRole,row)
        win.run=lambda work,after:work()
        with patch.object(gyss_qt.QMessageBox,'question',return_value=gyss_qt.QMessageBox.Yes):win.push()
        assert win.api.updates and all('sort_order' not in row for row in win.api.updates)
        assert all('delete' not in row for row in win.api.updates)

        import json
        index_path=tmp_path/'index.json';meta_path=tmp_path/'meta.json'
        win.api.library=lambda since:{'images':[{'id':i,'sort_order':i} for i in range(1,6)],'current_ids':[1,2,3,4,5],'site_mode':'photoblog','detail_schema':2,'synced_at':'2026-11-09T17:00:00Z'}
        win.local=lambda:({'images':{}},{})
        win.paths=lambda:(str(index_path),str(meta_path))
        with patch.object(gyss_qt.snap_home,'site_thumbs_dir',return_value=str(tmp_path)),patch.object(gyss_qt,'snap_library',None):win.sync_library()
        stored=json.loads(meta_path.read_text(encoding='utf-8'))
        assert stored['sort_server_total']==5 and stored['sort_library_ids']==[1,2,3,4,5]
        win.local=lambda:(json.loads(index_path.read_text(encoding='utf-8')),json.loads(meta_path.read_text(encoding='utf-8')))
        win.browse_local();assert win.can_reorder_photos()
        win.limit.setValue(2);win.browse_local();assert not win.can_reorder_photos()
        win.mode='carousel';win.sort_library_ids=[1,2,3,4,5];win.configure_mode()
        assert not win.can_reorder_photos() and not win.photo_list.dragEnabled()
        win.close()

# ===== SNAPSMACK EOF =====
