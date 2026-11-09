"""GYSS Sort pointer reorder regression; isolated Qt widgets, no profiles/network.
SNAPSMACK_EOF_HEADER: preserve final marker.
"""
import ast
import os
from pathlib import Path
os.environ.setdefault('QT_QPA_PLATFORM','offscreen')
from PySide6.QtCore import Qt, QPoint, QSize, QEvent
from PySide6.QtGui import QMouseEvent
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication, QListWidget, QListWidgetItem, QListView, QAbstractItemView

source=Path(__file__).resolve().parents[1]/'gyss_qt.py'
tree=ast.parse(source.read_text(encoding='utf-8'))
cls=next(n for n in tree.body if isinstance(n,ast.ClassDef) and n.name=='GramReorderList')
exec(compile(ast.Module(body=[cls],type_ignores=[]),str(source),'exec'))
app=QApplication.instance() or QApplication([])

def make_grid():
    grid=GramReorderList()
    grid.setViewMode(QListWidget.IconMode)
    grid.setMovement(QListView.Static)
    grid.setGridSize(QSize(120,100))
    grid.setSelectionMode(QAbstractItemView.ExtendedSelection)
    grid.setDragDropMode(QAbstractItemView.DragDrop)
    grid.setDragDropOverwriteMode(False)
    grid.resize(640,320)
    for ident in range(1,6):
        item=QListWidgetItem(str(ident));item.setSizeHint(QSize(100,90));item.setData(Qt.UserRole,{'id':ident});grid.addItem(item)
    grid.show();app.processEvents()
    return grid

def ids(grid):return [grid.item(i).data(Qt.UserRole)['id'] for i in range(grid.count())]

def drag(grid,src,dest,after=False,outside=False):
    start=grid.visualItemRect(grid.item(src)).center()
    end=QPoint(-30,-30) if outside else grid.visualItemRect(grid.item(dest)).center()+QPoint(20 if after else -20,0)
    QTest.mousePress(grid.viewport(),Qt.LeftButton,Qt.NoModifier,start)
    for point in (start+QPoint(20,0),end):
        event=QMouseEvent(QEvent.MouseMove,point,grid.viewport().mapToGlobal(point),Qt.NoButton,Qt.LeftButton,Qt.NoModifier)
        QApplication.sendEvent(grid.viewport(),event)
    QTest.mouseRelease(grid.viewport(),Qt.LeftButton,Qt.NoModifier,end)
    app.processEvents()


def test_scripted_pointer_drops():
    for label,src,dst,after,outside,expected in [
        ('forward',0,2,False,False,[2,1,3,4,5]),
        ('backward',3,0,False,False,[4,1,2,3,5]),
        ('self',2,2,False,False,[1,2,3,4,5]),
        ('outside',1,0,False,True,[1,2,3,4,5]),
        ('last',0,4,True,False,[2,3,4,5,1]),
        ('first',4,0,False,False,[5,1,2,3,4]),
    ]:
        grid=make_grid();drag(grid,src,dst,after,outside)
        assert ids(grid)==expected,(label,ids(grid))
        assert grid.count()==5 and sorted(ids(grid))==[1,2,3,4,5]
        grid.close()

def test_multi_selection_preserves_all_ids():
    grid=make_grid();grid.item(1).setSelected(True);grid.item(3).setSelected(True)
    assert grid.move_selected_to(5)
    assert ids(grid)==[1,3,5,2,4]
    assert sorted(ids(grid))==[1,2,3,4,5]
    grid.close()

# ===== SNAPSMACK EOF =====
