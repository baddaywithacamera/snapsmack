"""GET YOUR SHIT SORTED — native Qt, local-first photo organizer."""
import json, os, re, sys, threading, time, urllib.parse, uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
import requests
from PySide6.QtCore import QObject, Qt, Signal, QSize, QUrl, QSettings, QTimer
from PySide6.QtGui import QAction, QColor, QDesktopServices, QIcon, QKeySequence, QPixmap
from PySide6.QtWidgets import (QApplication,QAbstractItemView,QCheckBox,QColorDialog,QComboBox,QDialog,QFormLayout,QFrame,QHBoxLayout,QLabel,QLineEdit,QListView,QListWidget,QListWidgetItem,QMainWindow,QMessageBox,QProgressBar,QPushButton,QScrollArea,QSpinBox,QStackedWidget,QTextEdit,QVBoxLayout,QWidget)

HERE=os.path.dirname(os.path.abspath(__file__)); SHARED=os.path.abspath(os.path.join(HERE,"..","_shared")); sys.path.insert(0,SHARED)
import snap_connections, snap_creds, snap_home, snap_profiles
try: import snap_native_creds
except Exception: snap_native_creds=None
try: import snap_library
except Exception: snap_library=None
from _version import BUILD_VERSION

try:
    import snap_site_scope   # X-Snap-Site header (mutual-auth A1, SECAUDIT 054)
except Exception:  # noqa: BLE001
    # tools/_shared may not be on sys.path yet at this point in the file (each
    # tool adds it at a different spot). Find it from here; frozen exes bundle
    # it next to the entry script.
    import os as _sso, sys as _sss
    _d = _sso.path.dirname(_sso.path.abspath(__file__))
    for _up in range(4):
        _cand = _sso.path.join(_d, "_shared")
        if _sso.path.isdir(_cand):
            if _cand not in _sss.path:
                _sss.path.insert(0, _cand)
            break
        _d = _sso.path.dirname(_d)
    try:
        import snap_site_scope
    except Exception:  # noqa: BLE001
        snap_site_scope = None


def _site_scope(site_url):
    return snap_site_scope.header(site_url) if snap_site_scope else {}


GREEN,INK,DIM="#73f04b","#f4f7f2","#7e897f"; BASE,VOID,PANEL,CARD,BORDER="#0d120f","#090c0a","#121a15","#18231c","#293a30"
STYLE="""QWidget{background:#0d120f;color:#f4f7f2;font:14px 'Segoe UI'} QMainWindow{background:#090c0a} QFrame#Sidebar{background:#090c0a;border-right:1px solid #293a30} QFrame#Header{background:#121a15;border-bottom:1px solid #293a30} QFrame#Card{background:#18231c;border:1px solid #293a30;border-radius:12px} QLabel#Eyebrow{color:#73f04b;font-size:11px;font-weight:800} QLabel#Title{font-size:23px;font-weight:800} QLabel#PageTitle{font-size:24px;font-weight:750} QLabel#Muted{color:#7e897f} QLabel#Good{color:#73f04b;background:#142611;border:1px solid #315d25;border-radius:9px;padding:6px 10px;font-weight:700} QPushButton{background:#202c24;border:1px solid #34463a;border-radius:8px;padding:9px 14px;font-weight:650} QPushButton:hover{border-color:#73f04b} QPushButton:disabled{color:#596259;background:#151a16} QPushButton#Primary{color:#071006;background:#73f04b;border-color:#73f04b;font-weight:850;padding:11px 18px} QPushButton#Danger{color:#ff8b8b;background:#271313;border-color:#683131} QPushButton#Nav{text-align:left;background:transparent;border:0;color:#bac3b8;padding:12px 14px} QPushButton#Nav:checked{color:#73f04b;background:#152219;border-left:3px solid #73f04b} QLineEdit,QComboBox,QTextEdit,QListWidget,QSpinBox{background:#0b100d;border:1px solid #293a30;border-radius:7px;padding:7px;selection-background-color:#3ba525} QListWidget::item{padding:7px;border-radius:6px} QListWidget::item:selected{background:#294531} QProgressBar{background:#0b100d;border:1px solid #293a30;border-radius:7px;text-align:center} QProgressBar::chunk{background:#73f04b} QScrollArea{border:0}"""

def stamp(): return datetime.now(timezone.utc).isoformat()
def lbl(text,name=""):
    w=QLabel(text); w.setWordWrap(True)
    if name:w.setObjectName(name)
    return w
def card(title,detail=""):
    f=QFrame(); f.setObjectName("Card"); l=QVBoxLayout(f); l.setContentsMargins(18,16,18,16); l.setSpacing(9); l.addWidget(lbl(title,"Title"))
    if detail:l.addWidget(lbl(detail,"Muted"))
    return f,l
def icon_path(): return os.path.join(getattr(sys,"_MEIPASS",HERE),"icon.ico")
def repaired(v):
    if isinstance(v,str):return v.replace("/uploads/img_uploads/","/img_uploads/")
    if isinstance(v,list):return [repaired(x) for x in v]
    if isinstance(v,dict):return {k:repaired(x) for k,x in v.items()}
    return v

def merge_enrichment_record(record,result):
    """Mirror a complete server enrichment result into one local image row."""
    row=dict(record or {}); bundle=dict((result or {}).get("metadata") or {})
    row["enrichment_bundle"]=bundle
    row["enrichment_cache"]=dict((result or {}).get("cache") or {})
    row["enriched_at"]=stamp()
    applied=set((result or {}).get("applied") or [])
    mapping={"title":"title","caption":"description","alt":"alt","color_mode":"color_mode",
             "colors":"ai_colors","ocr":"ai_ocr","content_warning":"content_warning","tags":"ai_tags"}
    for field,target in mapping.items():
        if field in applied and bundle.get(field) not in (None,""):row[target]=bundle[field]
    return row

class API:
    def __init__(self,p):
        self.base=p["site_url"].rstrip("/"); self.s=requests.Session(); self.s.headers.update({"Authorization":"Bearer "+p.get("api_key",""),"Accept":"application/json",**_site_scope(self.base)})
    def call(self,method,endpoint,params=None,body=None,timeout=90):
        q={"route":"gyss/"+endpoint}; q.update(params or {}); r=self.s.request(method,self.base+"/api.php",params=q,json=body,timeout=timeout)
        try:data=r.json()
        except Exception:raise RuntimeError(f"{endpoint.replace('-', ' ').title()} failed: the site returned HTTP {r.status_code}, not a GYSS response.")
        if not r.ok or not data.get("ok",False):raise RuntimeError(data.get("error") or f"HTTP {r.status_code}")
        return repaired(data)
    def ping(self):return self.call("GET","ping",timeout=25)
    def meta(self):return self.call("GET","meta")
    def photos(self,f):
        try:return self.call("GET","photos",f)
        except RuntimeError as exc:
            # Older/irregular installs can lack one of the optional tables used
            # by the filtered endpoint.  The library endpoint is deliberately
            # schema-tolerant, so use it as a read-only compatibility path and
            # perform the small requested slice here.
            if "HTTP 500" not in str(exc):raise
            data=self.library(); rows=list(data.get("images",[])); cats={}; albums={}
            for image_id,value in data.get("cat_map",[]):cats.setdefault(str(image_id),[]).append(value)
            for image_id,value in data.get("album_map",[]):albums.setdefault(str(image_id),[]).append(value)
            category=f.get("category_id"); album=f.get("album_id")
            if category is not None:rows=[x for x in rows if category in cats.get(str(x.get("id")),[])]
            if album is not None:rows=[x for x in rows if album in albums.get(str(x.get("id")),[])]
            limit=max(1,min(int(f.get("limit",200)),500))
            return {"ok":True,"total":len(rows),"photos":rows[:limit],"compatibility_fallback":True}
    def library(self,since=None):return self.call("GET","library",{"since":since} if since else None,timeout=180)
    def batch(self,u):return self.call("POST","batch-update",body={"updates":u},timeout=180)
    def gram_posts(self):return self.call("GET","gram-posts",{"limit":500})
    def gram_order(self,ids):return self.call("POST","gram-reorder",body={"ids":ids})
    def gram_carousel(self,ids,cover):return self.call("POST","gram-carousel",body={"ids":ids,"cover_post_id":cover})
    def audit(self):return self.call("GET","enrichment-audit",{"limit":1000},timeout=180)
    def enrich(self,i,prompt,fields,overwrite,force):return self.call("POST","enrich-one",body={"id":i,"prompt":prompt,"fields":fields,"overwrite":overwrite,"force_refresh":force},timeout=300)

class Worker(QObject):
    done=Signal(object); failed=Signal(str); progress=Signal(str,int,int)

class OrganizerList(QListWidget):
    photosDropped=Signal(list)
    def __init__(self):
        super().__init__();self.setViewMode(QListWidget.IconMode);self.setResizeMode(QListWidget.Adjust);self.setSelectionMode(QAbstractItemView.ExtendedSelection);self.setDragEnabled(True);self.setAcceptDrops(True)
    def dragEnterEvent(self,event):
        if isinstance(event.source(),QListWidget) and event.source() is not self:event.acceptProposedAction()
        else:super().dragEnterEvent(event)
    def dragMoveEvent(self,event):
        if isinstance(event.source(),QListWidget) and event.source() is not self:event.acceptProposedAction()
        else:super().dragMoveEvent(event)
    def dropEvent(self,event):
        source=event.source()
        if isinstance(source,QListWidget) and source is not self:
            self.photosDropped.emit([item.data(Qt.UserRole)["id"] for item in source.selectedItems()]);event.acceptProposedAction();return
        super().dropEvent(event)

class GramReorderList(QListWidget):
    def __init__(self):
        super().__init__();self._reorder_press=None;self._mouse_reordering=False
    def mousePressEvent(self,event):
        super().mousePressEvent(event)
        point=event.position().toPoint()
        self._reorder_press=point if event.button()==Qt.LeftButton and self.indexAt(point).isValid() else None
        self._mouse_reordering=False
    def mouseMoveEvent(self,event):
        if self._reorder_press is not None and event.buttons()&Qt.LeftButton and (event.position().toPoint()-self._reorder_press).manhattanLength()>=QApplication.startDragDistance():
            self._mouse_reordering=True;self.viewport().setCursor(Qt.ClosedHandCursor);event.accept();return
        super().mouseMoveEvent(event)
    def mouseReleaseEvent(self,event):
        if self._mouse_reordering and event.button()==Qt.LeftButton:
            point=event.position().toPoint();index=self.indexAt(point)
            if index.isValid():
                rect=self.visualRect(index);self.move_selected_to(index.row()+(1 if point.x()>=rect.center().x() else 0))
            self.viewport().unsetCursor();self._reorder_press=None;self._mouse_reordering=False;event.accept();return
        self._reorder_press=None;self._mouse_reordering=False;super().mouseReleaseEvent(event)
    def dragEnterEvent(self,event):
        if event.source() is self:event.acceptProposedAction()
        else:super().dragEnterEvent(event)
    def dragMoveEvent(self,event):
        if event.source() is self:event.acceptProposedAction()
        else:super().dragMoveEvent(event)
    def move_selected_to(self,target_row):
        rows=sorted({self.row(item) for item in self.selectedItems()})
        if not rows:return False
        target_row=max(0,min(int(target_row),self.count()));items=[]
        for row in reversed(rows):items.append((row,self.takeItem(row)))
        items=[item for _row,item in reversed(items)];target_row-=sum(row<target_row for row in rows);target_row=max(0,min(target_row,self.count()))
        for offset,item in enumerate(items):self.insertItem(target_row+offset,item);item.setSelected(True)
        self.setCurrentItem(items[0]);return True
    def dropEvent(self,event):
        if event.source() is self:
            point=event.position().toPoint();index=self.indexAt(point);target=index.row() if index.isValid() else self.count()
            if self.move_selected_to(target):event.acceptProposedAction()
            else:event.ignore()
            return
        super().dropEvent(event)

class PhotoEditDialog(QDialog):
    def __init__(self,parent=None):
        super().__init__(parent);self.dirty_check=lambda:False
    def reject(self):
        if self.dirty_check() and QMessageBox.question(self,"Discard unsaved changes?","Close without saving your edits?",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)!=QMessageBox.Yes:return
        super().reject()

class Window(QMainWindow):
    def __init__(self):
        super().__init__(); self.setWindowTitle(f"GET YOUR SHIT SORTED — {BUILD_VERSION}"); self.setWindowIcon(QIcon(icon_path())); self.resize(1420,900); self.setMinimumSize(1060,700)
        self.window_settings=QSettings("SnapSmack", "GYSS")
        geometry=self.window_settings.value("window/normal_geometry")
        if geometry:self.restoreGeometry(geometry)
        self.profiles=[]; self.profile=None; self.api=None; self.mode=""; self.meta={"categories":[],"albums":[]}; self.photos=[]; self.original={}; self.busy=False; self.cancel=False; self.gram_loaded=0; self.gram_site="";self.organizer_page_size=180;self.organizer_member_page=0;self.organizer_tray_page=0
        self.worker=Worker(); self.worker.done.connect(self.done); self.worker.failed.connect(self.failed); self.worker.progress.connect(self.on_progress)
        self.build(); self.load_profiles()
        help_action=QAction("Help",self); help_action.setShortcut(QKeySequence.HelpContents); help_action.triggered.connect(self.show_help); self.addAction(help_action)
        if self.window_settings.value("window/maximized", False, type=bool):
            QTimer.singleShot(0, self.showMaximized)
    def closeEvent(self,event):
        self.window_settings.setValue("window/maximized",self.isMaximized())
        if not self.isMaximized():self.window_settings.setValue("window/normal_geometry",self.saveGeometry())
        self.window_settings.sync()
        super().closeEvent(event)
    def build(self):
        root=QWidget(); sh=QHBoxLayout(root); sh.setContentsMargins(0,0,0,0); sh.setSpacing(0); side=QFrame(); side.setObjectName("Sidebar"); side.setFixedWidth(230); sl=QVBoxLayout(side); sl.setContentsMargins(18,24,18,18)
        sl.addWidget(lbl("SNAPSMACK","Eyebrow")); sl.addWidget(lbl("GET YOUR SHIT\nSORTED","Title")); sl.addSpacing(25); self.pages=QStackedWidget(); self.nav=[]
        specs=(("Library","Choose and sync",self.library_page),("Photos","Edit details",self.sort_page),("Organize","Categories & albums",self.organizer_page),("Grid","GRAMOFSMACK",self.grid_page),("Images","Fill missing details",self.images_page),("Sites","Connections",self.sites_page))
        for i,(a,b,f) in enumerate(specs):
            n=QPushButton(a+"\n"+b); n.setObjectName("Nav"); n.setCheckable(True); n.clicked.connect(lambda _=False,x=i:self.show_page(x)); sl.addWidget(n); self.nav.append(n); self.pages.addWidget(f())
        self.nav[0].setChecked(True); sl.addStretch(); sl.addWidget(lbl(f"BUILD {BUILD_VERSION}\nLocal-first · deliberate publishing","Muted")); sh.addWidget(side)
        main=QWidget(); ml=QVBoxLayout(main); ml.setContentsMargins(0,0,0,0); ml.setSpacing(0); head=QFrame(); head.setObjectName("Header"); hl=QHBoxLayout(head); hl.setContentsMargins(24,12,24,12); hl.addWidget(lbl("SITE","Eyebrow")); self.site=QComboBox(); self.site.setMinimumWidth(350); self.site.currentIndexChanged.connect(self.site_changed); hl.addWidget(self.site); hl.addStretch(); self.status=lbl("Choose a site","Good"); self.status.setWordWrap(False); hl.addWidget(self.status); help_btn=QPushButton("HELP · F1"); help_btn.clicked.connect(self.show_help); hl.addWidget(help_btn); test=QPushButton("Test connection"); test.clicked.connect(self.test); hl.addWidget(test); ml.addWidget(head); ml.addWidget(self.pages,1); sh.addWidget(main,1); self.setCentralWidget(root)
    def page(self,title,sub):
        s=QScrollArea(); s.setWidgetResizable(True); h=QWidget(); l=QVBoxLayout(h); l.setContentsMargins(28,24,28,28); l.setSpacing(15); l.addWidget(lbl(title,"PageTitle")); l.addWidget(lbl(sub,"Muted")); s.setWidget(h); return s,l
    def library_page(self):
        p,l=self.page("Your local photo library","Choose a site. Sync while it is online; browse and sort the saved copy whenever you like."); c,cl=card("Library status"); self.lib_status=lbl("Choose a site above.","Muted"); cl.addWidget(self.lib_status); r=QHBoxLayout(); self.sync=QPushButton("SYNC FROM SITE"); self.sync.clicked.connect(self.sync_library); self.browse=QPushButton("BROWSE LOCAL COPY"); self.browse.setObjectName("Primary"); self.browse.clicked.connect(self.browse_local); folder=QPushButton("OPEN LIBRARY FOLDER"); folder.clicked.connect(self.open_library); r.addWidget(self.sync); r.addWidget(self.browse); r.addWidget(folder); r.addStretch(); cl.addLayout(r); self.progress=QProgressBar(); self.progress.hide(); cl.addWidget(self.progress); l.addWidget(c)
        f,fl=card("Optional filter","Leave these alone to load the complete library."); form=QFormLayout(); self.cat=QComboBox(); self.alb=QComboBox(); self.limit=QSpinBox(); self.limit.setRange(1,500); self.limit.setValue(200); form.addRow("Category",self.cat); form.addRow("Album",self.alb); form.addRow("Live pull limit",self.limit); fl.addLayout(form); live=QPushButton("PULL A LIVE SESSION"); live.clicked.connect(self.pull_live); fl.addWidget(live,0,Qt.AlignRight); l.addWidget(f); l.addStretch(); return p
    def sort_page(self):
        p,l=self.page("Sort photographs","Drag to reorder. Select photographs to enrich missing details together."); self.sort_intro=l.itemAt(1).widget();sort_bar=QHBoxLayout();sort_bar.addStretch();sort_all=QPushButton("SELECT ALL");sort_all.clicked.connect(lambda:self.select_all_in(self.photo_list,True));sort_bar.addWidget(sort_all);sort_none=QPushButton("SELECT NONE");sort_none.clicked.connect(lambda:self.select_all_in(self.photo_list,False));sort_bar.addWidget(sort_none);sort_bar.addSpacing(392);l.addLayout(sort_bar); r=QHBoxLayout(); self.photo_list=QListWidget(); self.photo_list.setViewMode(QListWidget.IconMode); self.photo_list.setIconSize(QSize(150,110)); self.photo_list.setGridSize(QSize(180,165)); self.photo_list.setResizeMode(QListWidget.Adjust); self.photo_list.setDragDropMode(QAbstractItemView.InternalMove); self.photo_list.setSelectionMode(QAbstractItemView.ExtendedSelection); self.photo_list.currentItemChanged.connect(self.edit_photo); self.photo_list.itemSelectionChanged.connect(self.update_sort_enrich_label); r.addWidget(self.photo_list,1)
        e,el=card("Selected photograph"); form=QFormLayout(); self.title_edit=QLineEdit(); self.desc_edit=QTextEdit(); self.desc_edit.setMaximumHeight(90); self.alt_edit=QTextEdit(); self.alt_edit.setMaximumHeight(75); self.tags_edit=QLineEdit(); self.tags_edit.setPlaceholderText("#family #portrait"); self.colors_edit=QLineEdit(); self.colors_edit.setPlaceholderText("#RRGGBB #RRGGBB (up to 3)"); choose_color=QPushButton("CHOOSE COLOUR"); choose_color.clicked.connect(self.choose_color); self.edit_cats=QListWidget(); self.edit_cats.setSelectionMode(QAbstractItemView.MultiSelection); self.edit_cats.setMaximumHeight(92); self.edit_albums=QListWidget(); self.edit_albums.setSelectionMode(QAbstractItemView.MultiSelection); self.edit_albums.setMaximumHeight(92); self.colour=QComboBox(); self.colour.addItem("Not classified",""); self.colour.addItem("Colour","color"); self.colour.addItem("Black & white","bw"); self.orientation=QComboBox(); self.orientation.addItem("Landscape",0); self.orientation.addItem("Portrait",1); self.orientation.addItem("Square",2); form.addRow("Title",self.title_edit); form.addRow("Description",self.desc_edit); form.addRow("ALT text",self.alt_edit); form.addRow("Hashtags",self.tags_edit); form.addRow("Colours",self.colors_edit); form.addRow("",choose_color); form.addRow("Categories",self.edit_cats); form.addRow("Albums",self.edit_albums); form.addRow("Colour / B&W",self.colour); form.addRow("Orientation",self.orientation); el.addLayout(form); apply=QPushButton("APPLY DETAILS TO THIS PHOTO"); apply.clicked.connect(self.apply_edit); el.addWidget(apply); organize=QPushButton("ORGANIZE SELECTED PHOTOS"); organize.clicked.connect(self.apply_organization); el.addWidget(organize); self.sort_enrich=QPushButton("ENRICH THIS PHOTO"); self.sort_enrich.clicked.connect(self.enrich_sort_photo); el.addWidget(self.sort_enrich); self.sort_stop=QPushButton("STOP AFTER THIS IMAGE"); self.sort_stop.setObjectName("Danger"); self.sort_stop.setEnabled(False); self.sort_stop.clicked.connect(lambda:setattr(self,"cancel",True)); el.addWidget(self.sort_stop); self.sort_progress=QProgressBar(); self.sort_progress.hide(); el.addWidget(self.sort_progress); edit_scroll=QScrollArea(); edit_scroll.setWidgetResizable(True); edit_scroll.setFixedWidth(380); edit_scroll.setWidget(e); r.addWidget(edit_scroll); l.addLayout(r,1); br=QHBoxLayout(); self.save_session_button=QPushButton("SAVE SESSION"); self.save_session_button.clicked.connect(self.save_session); br.addWidget(self.save_session_button); self.detect_orientation_button=QPushButton("MATCH ORIENTATION TO PHOTO SHAPE"); self.detect_orientation_button.clicked.connect(self.match_orientations); br.addWidget(self.detect_orientation_button); br.addStretch(); push=QPushButton("PUBLISH CHANGES"); push.setObjectName("Primary"); push.clicked.connect(self.push); br.addWidget(push); l.addLayout(br); return p
    def organizer_page(self):
        p,l=self.page("Organize categories and albums","Open a category or album, then drag photographs between the library filmstrip and the large working area.")
        body=QHBoxLayout(); left,left_l=card("Categories & albums","Choose the container you are working on.");left.setFixedWidth(260);self.organizer_container_search=QLineEdit();self.organizer_container_search.setPlaceholderText("Search categories and albums");self.organizer_container_search.textChanged.connect(self.fill_organizer_containers);left_l.addWidget(self.organizer_container_search);self.organizer_containers=QListWidget();self.organizer_containers.currentItemChanged.connect(self.render_organizer);left_l.addWidget(self.organizer_containers,1);body.addWidget(left)
        work=QVBoxLayout();self.organizer_heading=lbl("Choose a category or album","Title");self.organizer_count=lbl("","Muted");work.addWidget(self.organizer_heading);member_bar=QHBoxLayout();member_bar.addWidget(self.organizer_count);member_bar.addStretch();members_all=QPushButton("SELECT ALL ON PAGE");members_all.clicked.connect(lambda:self.select_all_in(self.organizer_members,True));member_bar.addWidget(members_all);members_none=QPushButton("SELECT NONE");members_none.clicked.connect(lambda:self.select_all_in(self.organizer_members,False));member_bar.addWidget(members_none);work.addLayout(member_bar);self.organizer_members=OrganizerList();self.organizer_members.setIconSize(QSize(150,110));self.organizer_members.setGridSize(QSize(175,155));self.organizer_members.photosDropped.connect(self.organizer_add_ids);self.organizer_members.itemDoubleClicked.connect(self.open_organizer_photo);work.addWidget(self.organizer_members,1)
        actions=QHBoxLayout();self.organizer_member_prev=QPushButton("◀ PREVIOUS");self.organizer_member_prev.clicked.connect(lambda:self.organizer_turn_page("member",-1));actions.addWidget(self.organizer_member_prev);self.organizer_member_next=QPushButton("NEXT ▶");self.organizer_member_next.clicked.connect(lambda:self.organizer_turn_page("member",1));actions.addWidget(self.organizer_member_next);remove=QPushButton("REMOVE SELECTED FROM THIS CONTAINER");remove.clicked.connect(self.organizer_remove_selected);actions.addWidget(remove);actions.addStretch();self.organizer_publish_button=QPushButton("PUBLISH ORGANIZATION");self.organizer_publish_button.setObjectName("Primary");self.organizer_publish_button.clicked.connect(self.publish_organization);actions.addWidget(self.organizer_publish_button);work.addLayout(actions);body.addLayout(work,1);l.addLayout(body,1)
        tray_bar=QHBoxLayout();tray_bar.addWidget(lbl("ALL PHOTOGRAPHS · drag selected photographs up to add them","Eyebrow"));tray_bar.addStretch();tray_all=QPushButton("SELECT ALL ON PAGE");tray_all.clicked.connect(lambda:self.select_all_in(self.organizer_tray,True));tray_bar.addWidget(tray_all);tray_none=QPushButton("SELECT NONE");tray_none.clicked.connect(lambda:self.select_all_in(self.organizer_tray,False));tray_bar.addWidget(tray_none);l.addLayout(tray_bar);filters=QHBoxLayout();self.organizer_limit=QSpinBox();self.organizer_limit.setRange(0,100000);self.organizer_limit.setSpecialValueText("All");self.organizer_limit.setValue(0);self.organizer_from=QLineEdit();self.organizer_from.setPlaceholderText("From · YYYY-MM-DD");self.organizer_from.setMaximumWidth(170);self.organizer_to=QLineEdit();self.organizer_to.setPlaceholderText("To · YYYY-MM-DD");self.organizer_to.setMaximumWidth(170);apply_filters=QPushButton("APPLY WORKING SET");apply_filters.clicked.connect(self.organizer_apply_filters);filters.addWidget(lbl("Maximum","Muted"));filters.addWidget(self.organizer_limit);filters.addWidget(self.organizer_from);filters.addWidget(self.organizer_to);filters.addWidget(apply_filters);filters.addStretch();l.addLayout(filters)
        traybar=QHBoxLayout();self.organizer_search=QLineEdit();self.organizer_search.setPlaceholderText("Search this working set");self.organizer_search.textChanged.connect(self.organizer_search_changed);traybar.addWidget(self.organizer_search,1);self.organizer_tray_count=lbl("","Muted");traybar.addWidget(self.organizer_tray_count);self.organizer_tray_prev=QPushButton("◀");self.organizer_tray_prev.clicked.connect(lambda:self.organizer_turn_page("tray",-1));traybar.addWidget(self.organizer_tray_prev);self.organizer_tray_next=QPushButton("▶");self.organizer_tray_next.clicked.connect(lambda:self.organizer_turn_page("tray",1));traybar.addWidget(self.organizer_tray_next);add=QPushButton("ADD SELECTED TO THIS CONTAINER");add.clicked.connect(self.organizer_add_selected);traybar.addWidget(add);l.addLayout(traybar);self.organizer_tray=OrganizerList();self.organizer_tray.setFlow(QListWidget.LeftToRight);self.organizer_tray.setWrapping(False);self.organizer_tray.setHorizontalScrollMode(QAbstractItemView.ScrollPerPixel);self.organizer_tray.setHorizontalScrollBarPolicy(Qt.ScrollBarAsNeeded);self.organizer_tray.setVerticalScrollBarPolicy(Qt.ScrollBarAlwaysOff);self.organizer_tray.setIconSize(QSize(105,78));self.organizer_tray.setGridSize(QSize(125,118));self.organizer_tray.setFixedHeight(145);self.organizer_tray.photosDropped.connect(self.organizer_remove_ids);self.organizer_tray.itemDoubleClicked.connect(lambda _item:self.organizer_add_selected());l.addWidget(self.organizer_tray);return p
    def grid_page(self):
        p,l=self.page("GRAMOFSMACK grid","Drag posts into order. Select two or more singles to make a carousel. Nothing changes online until you confirm."); gram_bar=QHBoxLayout();gram_bar.addStretch();gram_all=QPushButton("SELECT ALL");gram_all.clicked.connect(lambda:self.select_all_in(self.gram,True));gram_bar.addWidget(gram_all);gram_none=QPushButton("SELECT NONE");gram_none.clicked.connect(lambda:self.select_all_in(self.gram,False));gram_bar.addWidget(gram_none);l.addLayout(gram_bar); self.gram=GramReorderList(); self.gram.setViewMode(QListWidget.IconMode); self.gram.setMovement(QListView.Static); self.gram.setIconSize(QSize(170,170)); self.gram.setGridSize(QSize(195,215)); self.gram.setResizeMode(QListWidget.Adjust); self.gram.setDragEnabled(True); self.gram.setAcceptDrops(True); self.gram.setDropIndicatorShown(True); self.gram.setDefaultDropAction(Qt.MoveAction); self.gram.setDragDropOverwriteMode(False); self.gram.setDragDropMode(QAbstractItemView.DragDrop); self.gram.setSelectionMode(QAbstractItemView.ExtendedSelection); l.addWidget(self.gram,1); self.gram_empty=lbl("Choose a site to load its published posts.","Muted"); l.addWidget(self.gram_empty); r=QHBoxLayout(); refresh=QPushButton("REFRESH GRID"); refresh.clicked.connect(self.load_grid); r.addWidget(refresh);  earlier=QPushButton("MOVE EARLIER");earlier.clicked.connect(lambda:self.move_gram_selected(-1));r.addWidget(earlier);later=QPushButton("MOVE LATER");later.clicked.connect(lambda:self.move_gram_selected(1));r.addWidget(later); r.addStretch(); car=QPushButton("MAKE SELECTED A CAROUSEL"); car.clicked.connect(self.carousel); r.addWidget(car); order=QPushButton("PUBLISH ORDER"); order.setObjectName("Primary"); order.clicked.connect(self.push_grid); r.addWidget(order); l.addLayout(r); return p
    def images_page(self):
        p,l=self.page("Find and fill missing details","1. Choose the missing fields to find.  2. Scan the site.  3. Check photographs.  4. Enrich the checked photographs."); o,ol=card("Show photographs missing…","Choose one or more fields. Results must be missing every field you check."); r=QHBoxLayout(); self.fields={}
        for key,text,on in (("title","Title",1),("caption","Caption",1),("alt","ALT text",1),("tags","Tags",1),("colors","AI colours",1),("color_mode","Colour/B&W",1),("ocr","OCR",0),("content_warning","Safety review",0)):
            w=QCheckBox(text); w.setChecked(on); self.fields[key]=w; r.addWidget(w)
        ol.addLayout(r); ol.addWidget(lbl("Every enrichment generates and saves the complete metadata bundle. These boxes control only which missing values are applied online now.","Muted")); preset=QPushButton("TITLE + CAPTION + HASHTAGS"); preset.clicked.connect(lambda:self.set_missing_fields({"title","caption","tags"})); ol.addWidget(preset,0,Qt.AlignLeft); self.overwrite=QCheckBox("Replace existing values"); self.force=QCheckBox("Ignore saved bundle and pay for a fresh AI result"); ol.addWidget(self.overwrite); ol.addWidget(self.force); l.addWidget(o)
        self.prompt_toggle=QPushButton("SHOW ENRICHMENT INSTRUCTIONS"); self.prompt_toggle.setCheckable(True); self.prompt_toggle.toggled.connect(self.toggle_prompt); l.addWidget(self.prompt_toggle,0,Qt.AlignLeft)
        self.prompt=QTextEdit(); self.prompt.setPlaceholderText("The site’s saved enrichment prompt appears after scanning."); self.prompt.setMaximumHeight(180); self.prompt.hide(); l.addWidget(self.prompt)
        self.audit=QListWidget(); self.audit.setViewMode(QListWidget.IconMode); self.audit.setIconSize(QSize(180,125)); self.audit.setGridSize(QSize(225,215)); self.audit.setResizeMode(QListWidget.Adjust); self.audit.setSelectionMode(QAbstractItemView.NoSelection); self.audit.itemChanged.connect(self.audit_count); self.audit.itemDoubleClicked.connect(self.open_audit_photo); l.addWidget(self.audit,1)
        self.audit_summary=lbl("Scan the site to see recognizable photographs.","Muted"); l.addWidget(self.audit_summary)
        r=QHBoxLayout(); scan=QPushButton("SCAN SITE"); scan.clicked.connect(self.scan); r.addWidget(scan); select_all=QPushButton("CHECK ALL RESULTS"); select_all.clicked.connect(lambda:self.check_audit(True)); r.addWidget(select_all); clear=QPushButton("CLEAR CHECKS"); clear.clicked.connect(lambda:self.check_audit(False)); r.addWidget(clear); self.stop=QPushButton("STOP AFTER THIS IMAGE"); self.stop.setObjectName("Danger"); self.stop.setEnabled(False); self.stop.clicked.connect(lambda:setattr(self,"cancel",True)); r.addWidget(self.stop); r.addStretch(); en=QPushButton("ENRICH CHECKED"); en.setObjectName("Primary"); en.clicked.connect(self.enrich); r.addWidget(en); l.addLayout(r); return p
    def sites_page(self):
        p,l=self.page("Site connections","Most days you should not need this page. Editing a connection affects every SnapSmack desktop tool."); c,cl=card("Selected connection"); form=QFormLayout(); self.name=QLineEdit(); self.url=QLineEdit(); self.key=QLineEdit(); self.key.setEchoMode(QLineEdit.Password); form.addRow("Friendly name",self.name); form.addRow("Site URL",self.url); form.addRow("GYSS API key",self.key); cl.addLayout(form); r=QHBoxLayout(); new=QPushButton("NEW CONNECTION"); new.clicked.connect(self.new_site); r.addWidget(new); delete=QPushButton("DELETE SELECTED CONNECTION"); delete.setObjectName("Danger"); delete.clicked.connect(self.delete_site); r.addWidget(delete); r.addStretch(); save=QPushButton("SAVE CONNECTION"); save.setObjectName("Primary"); save.clicked.connect(self.save_site); r.addWidget(save); cl.addLayout(r); l.addWidget(c); l.addStretch(); return p
    def show_page(self,n):
        self.pages.setCurrentIndex(n)
        for i,b in enumerate(self.nav):b.setChecked(i==n)
        if n==3 and self.api and self.gram_site!=self.profile["site_url"] and not self.busy:
            self.load_grid()
        # Sync saves the library on disk. Opening Sort must actually load that
        # library; an empty in-memory session is not an empty photo collection.
        if n==1 and self.profile and not self.photo_list.count():
            index,_=self.local()
            if index.get("images"):self.browse_local()
        if n==2 and self.profile:self.load_organizer()
    def show_help(self):
        QMessageBox.information(self,"GYSS help",
            "SYNC LIBRARY FROM SITE\nDownloads the complete catalogue and its thumbnails to this computer. Run it once, then again whenever the site changes.\n\n"
            "BROWSE LOCAL COPY\nOpens the saved library. It works offline and is the normal place to sort a whole archive.\n\n"
            "PULL A LIVE SESSION\nFetches only the currently filtered photographs, up to the Live pull limit. Their thumbnails are cached locally too, but this does not replace a complete library sync.\n\n"
            "Nothing changes online until you choose PUBLISH CHANGES and confirm.\n\n"
            "SELECT ALL / SELECT NONE\nPhotos and Grid: picks every photograph in the list. Organize: those lists show 180 photographs at a time, so the buttons there say SELECT ALL ON PAGE and pick only the page you are looking at — turn the page and press it again for the next 180. On the Images page the same job is done by CHECK ALL RESULTS, because that page uses tick boxes.")
    def set_missing_fields(self,wanted):
        for key,box in self.fields.items():box.setChecked(key in wanted)
    def toggle_prompt(self,shown):
        self.prompt.setVisible(shown); self.prompt_toggle.setText("HIDE ENRICHMENT INSTRUCTIONS" if shown else "SHOW ENRICHMENT INSTRUCTIONS")
    def select_all_in(self,widget,on):
        """SELECT ALL / SELECT NONE for the drag-and-drop photo lists. Sean asked
        for it (2026-09-22): every list that feeds a SELECTED action needed a
        button, not a Ctrl+A only he knows about. On the two organizer lists the
        widget holds one 180-photo page, so those buttons say ON PAGE — selecting
        what is not on screen and then removing it would be a lie."""
        widget.selectAll() if on else widget.clearSelection()

    def check_audit(self,checked):
        state=Qt.Checked if checked else Qt.Unchecked
        for i in range(self.audit.count()):self.audit.item(i).setCheckState(state)
        self.audit_count()
    def audit_count(self,*_):
        checked=sum(self.audit.item(i).checkState()==Qt.Checked for i in range(self.audit.count()))
        self.audit_summary.setText(f"{self.audit.count():,} matching photographs · {checked:,} checked · up to {checked:,} paid AI calls")
    def open_audit_photo(self,item):
        row=item.data(Qt.UserRole) or {}; url=row.get("thumb_url","")
        if url:QDesktopServices.openUrl(QUrl(url))
    def save_enrichment_local(self,image_id,result,audit_row=None):
        index,_meta=self.local(); images=index.setdefault("images",{}); key=str(image_id)
        base=dict(images.get(key) or audit_row or {"id":image_id})
        images[key]=merge_enrichment_record(base,result)
        path,_=self.paths(); os.makedirs(os.path.dirname(path),exist_ok=True); tmp=path+".tmp"
        with open(tmp,"w",encoding="utf-8") as handle:json.dump(index,handle,indent=2,ensure_ascii=False)
        os.replace(tmp,path)
    def run(self,fn,after):
        if self.busy:return
        self.busy=True; self.after=after; QApplication.setOverrideCursor(Qt.WaitCursor)
        def work():
            try:self.worker.done.emit(fn())
            except Exception as e:self.worker.failed.emit(str(e))
        threading.Thread(target=work,daemon=True).start()
    def done(self,result):
        self.busy=False; QApplication.restoreOverrideCursor(); self.progress.hide(); self.sort_progress.hide(); cb=getattr(self,"after",None); self.after=None
        if cb:cb(result)
    def failed(self,msg):
        self.busy=False; QApplication.restoreOverrideCursor(); self.progress.hide(); self.sort_progress.hide(); self.stop.setEnabled(False); self.sort_stop.setEnabled(False); self.sort_enrich.setEnabled(True); self.after=None; QMessageBox.critical(self,"GYSS could not finish",msg); self.status.setText("● Needs attention")
    def on_progress(self,text,n,total):
        bar=self.sort_progress if self.pages.currentIndex()==1 else self.progress
        bar.show(); bar.setRange(0,max(total,1)); bar.setValue(n); bar.setFormat(text+"  %p%")
    def load_profiles(self,keep=""):
        old=keep or (self.profile or {}).get("site_url",""); self.profiles=[p for p in snap_connections.list_connections("gyss") if p.get("extras",{}).get("gyss_site_mode")!="smacktalk"]; self.site.blockSignals(True); self.site.clear(); self.site.addItem("Choose a site…",None)
        for p in self.profiles:self.site.addItem(f"{p.get('name') or snap_home.site_key(p['site_url'])}  ·  {snap_home.site_key(p['site_url'])}",p)
        self.site.setCurrentIndex(next((i+1 for i,p in enumerate(self.profiles) if p.get("site_url")==old),0)); self.site.blockSignals(False); self.site_changed(self.site.currentIndex())
    def site_changed(self,i):
        old_site=(self.profile or {}).get("site_url")
        self.profile=self.site.itemData(i) if i>=0 else None; self.api=API(self.profile) if self.profile and self.profile.get("api_key") else None; p=self.profile or {}; self.name.setText(p.get("name","")); self.url.setText(p.get("site_url","")); self.key.setText(p.get("api_key","")); self.status.setText("● Site selected" if self.api else ("● Run Discover Fleet" if self.profile else "Choose a site")); self.library_status()
        if old_site!=p.get("site_url"):
            self.photos=[];self.original={};self.photo_list.clear();self.gram.clear();self.gram_site="";self.gram_loaded=0
            self.gram_empty.setText("Open Grid to load this site's published posts." if self.api else "Choose a site to load its published posts.")
            _,cached_meta=self.local() if self.profile else ({},{})
            self.mode=(p.get("extras") or {}).get("gyss_site_mode") or cached_meta.get("site_mode") or ""
            self.configure_mode()
            self.cat.setCurrentIndex(0);self.alb.setCurrentIndex(0)
            if self.pages.currentIndex()==1 and self.profile:self.show_page(1)
            if self.pages.currentIndex()==2:self.load_organizer()
            if self.pages.currentIndex()==3 and self.api:self.load_grid()
    def configure_mode(self):
        gram=self.mode=="carousel"
        self.nav[1].setText("Photos\nEdit details")
        self.nav[3].setVisible(gram or not self.mode)
        self.sort_intro.setText("Edit and enrich a bounded working set of photographs. Use Organize for categories and albums; GRAMOFSMACK orders published posts on Grid.")
        self.photo_list.setDragDropMode(QAbstractItemView.NoDragDrop)
        self.save_session_button.setVisible(False)
    def require_api(self):
        if self.api:return True
        QMessageBox.information(self,"Choose a site","Choose a site with a saved GYSS key first."); return False
    def test(self):
        if self.require_api():self.run(self.api.ping,self.connected)
    def connected(self,r):
        self.mode=r.get("site_mode","photoblog"); self.status.setText(f"● Connected · {self.mode}")
        self.configure_mode()
        if self.mode=="carousel":self.load_grid(show=True)
        elif self.mode=="photoblog":self.run(self.api.meta,lambda x:(setattr(self,"meta",x),self.fill_meta(),self.show_page(0)))
        else:self.show_page(4)
    def fill_meta(self):
        for box,rows in ((self.cat,self.meta.get("categories",[])),(self.alb,self.meta.get("albums",[]))):
            box.clear(); box.addItem("All",None)
            for x in rows:box.addItem(str(x.get("name","")),x.get("id"))
        for box,rows in ((self.edit_cats,self.meta.get("categories",[])),(self.edit_albums,self.meta.get("albums",[]))):
            box.clear()
            for x in rows:
                item=QListWidgetItem(str(x.get("name","")));item.setData(Qt.UserRole,x.get("id"));box.addItem(item)
        self.fill_organizer_containers()
    def fill_organizer_containers(self):
        if not hasattr(self,"organizer_containers"):return
        current=self.organizer_containers.currentItem();wanted=current.data(Qt.UserRole) if current else None;query=self.organizer_container_search.text().strip().lower() if hasattr(self,"organizer_container_search") else "";self.organizer_containers.clear()
        for kind,title,key in (("category","CATEGORIES","categories"),("album","ALBUMS","albums")):
            rows=[row for row in self.meta.get(key,[]) if not query or query in str(row.get("name") or "Untitled").lower()]
            if not rows:continue
            heading=QListWidgetItem(title);heading.setFlags(Qt.NoItemFlags);self.organizer_containers.addItem(heading)
            for row in rows:
                item=QListWidgetItem("    "+str(row.get("name") or "Untitled"));item.setData(Qt.UserRole,(kind,int(row.get("id"))));item.setData(Qt.UserRole+1,row);self.organizer_containers.addItem(item)
                if wanted==item.data(Qt.UserRole):self.organizer_containers.setCurrentItem(item)
    def organizer_photo_item(self,photo):
        item=QListWidgetItem(self.thumb(photo),photo.get("title") or photo.get("filename") or f"Photo {photo['id']}");item.setData(Qt.UserRole,photo);item.setToolTip(item.text());return item
    def load_organizer(self):
        index,meta=self.local();self.organizer_photos={int(row["id"]):dict(row) for row in index.get("images",{}).values()};self.organizer_member_page=0;self.organizer_tray_page=0;self.meta={"categories":meta.get("categories",[]),"albums":meta.get("albums",[])};self.fill_meta();self.render_organizer_tray();self.render_organizer()
    def open_organizer_photo(self,item):
        if not item or not self.require_api():return
        visible_ids=[int(self.organizer_members.item(i).data(Qt.UserRole)["id"]) for i in range(self.organizer_members.count())];photo_id=int(item.data(Qt.UserRole)["id"]);position=visible_ids.index(photo_id);photo=self.organizer_photos.get(photo_id,item.data(Qt.UserRole));original={};dialog=PhotoEditDialog(self);dialog.resize(780,860);layout=QVBoxLayout(dialog)
        nav=QHBoxLayout();previous=QPushButton("◀ PREVIOUS");next_photo=QPushButton("NEXT ▶");position_label=lbl("","Muted");nav.addWidget(previous);nav.addWidget(next_photo);nav.addStretch();nav.addWidget(position_label);layout.addLayout(nav)
        preview=QLabel();preview.setMinimumHeight(260);preview.setAlignment(Qt.AlignCenter);layout.addWidget(preview);info=lbl("","Muted");info.setTextInteractionFlags(Qt.TextSelectableByMouse);layout.addWidget(info);open_full=QPushButton("OPEN FULL SIZE");layout.addWidget(open_full,0,Qt.AlignCenter)
        form=QFormLayout();title=QLineEdit();description=QTextEdit();description.setMaximumHeight(90);alt=QTextEdit();alt.setMaximumHeight(75);tags=QLineEdit();tags.setPlaceholderText("#family #portrait");colors=QLineEdit();colors.setPlaceholderText("#RRGGBB #RRGGBB (up to 3)")
        categories=QListWidget();categories.setSelectionMode(QAbstractItemView.MultiSelection);categories.setMaximumHeight(90);albums=QListWidget();albums.setSelectionMode(QAbstractItemView.MultiSelection);albums.setMaximumHeight(90)
        for row,box in ((self.meta.get("categories",[]),categories),(self.meta.get("albums",[]),albums)):
            for value in row:entry=QListWidgetItem(str(value.get("name") or "Untitled"));entry.setData(Qt.UserRole,int(value["id"]));box.addItem(entry)
        colour=QComboBox();colour.addItem("Not classified","");colour.addItem("Colour","color");colour.addItem("Black & white","bw");orientation=QComboBox();orientation.addItem("Landscape",0);orientation.addItem("Portrait",1);orientation.addItem("Square",2)
        for label,field in (("Title",title),("Description",description),("ALT text",alt),("Hashtags",tags),("Colours",colors),("Categories",categories),("Albums",albums),("Colour / B&W",colour),("Orientation",orientation)):form.addRow(label,field)
        layout.addLayout(form);ai_fields={};ai_row=QHBoxLayout();ai_row.addWidget(lbl("AI fields","Muted"));
        for key,label in (("title","Title"),("caption","Description"),("alt","ALT"),("tags","Hashtags"),("colors","Colours"),("color_mode","Colour/B&W")):
            ai_fields[key]=QCheckBox(label);ai_row.addWidget(ai_fields[key])
        layout.addLayout(ai_row);replace_ai=QCheckBox("Replace existing values in the selected AI fields");layout.addWidget(replace_ai)
        buttons=QHBoxLayout();enrich=QPushButton("ENRICH THIS PHOTO");restore=QPushButton("RESTORE ORIGINAL");cancel=QPushButton("CANCEL");save=QPushButton("SAVE TO SITE");save.setObjectName("Primary");save_next=QPushButton("SAVE & NEXT");save_next.setObjectName("Primary");buttons.addWidget(enrich);buttons.addWidget(restore);buttons.addStretch();buttons.addWidget(cancel);buttons.addWidget(save);buttons.addWidget(save_next);layout.addLayout(buttons)
        def values():return {"title":title.text().strip(),"description":description.toPlainText().strip(),"alt":alt.toPlainText().strip(),"hashtags":" ".join(tags.text().replace(","," ").split()),"colors":[value.upper() for value in colors.text().replace(","," ").split()],"category_ids":self.membership_ids(categories),"album_ids":self.membership_ids(albums),"color_mode":colour.currentData(),"orientation":orientation.currentData()}
        def load_current():
            nonlocal photo,original
            photo=self.organizer_photos[visible_ids[position]];dialog.setWindowTitle(f"Edit photograph · {position+1} of {len(visible_ids)}");position_label.setText(f"{position+1} of {len(visible_ids)}");previous.setEnabled(position>0);next_photo.setEnabled(position+1<len(visible_ids));save_next.setEnabled(position+1<len(visible_ids))
            preview.setPixmap(self.thumb(photo).pixmap(QSize(420,300)));width=photo.get("width") or "?";height=photo.get("height") or "?";date=photo.get("date_taken") or photo.get("created_at") or photo.get("uploaded_at") or "Unknown date";info.setText(f"ID {photo['id']}  ·  {photo.get('filename') or 'Unknown filename'}  ·  {width} × {height}  ·  {date}")
            title.setText(photo.get("title") or "");description.setPlainText(photo.get("description") or "");alt.setPlainText(photo.get("alt") or "");tags.setText(photo.get("hashtags") or "");colors.setText(" ".join(photo.get("colors") or []));self.set_memberships(categories,photo.get("category_ids") or ([photo.get("category_id")] if photo.get("category_id") else []));self.set_memberships(albums,photo.get("album_ids") or []);colour.setCurrentIndex(max(colour.findData(photo.get("color_mode","")),0));orientation.setCurrentIndex(max(orientation.findData(photo.get("orientation",0)),0));original=values()
            missing={"title":not original["title"],"caption":not original["description"],"alt":not original["alt"],"tags":not original["hashtags"],"colors":not original["colors"],"color_mode":not original["color_mode"]}
            for key,box in ai_fields.items():box.setChecked(missing[key])
        def move(step):
            nonlocal position
            if values()!=original:QMessageBox.information(dialog,"Unsaved edits","Save or restore this photograph before moving to another one.");return
            position=max(0,min(position+step,len(visible_ids)-1));load_current()
        def open_original():
            target=photo.get("image_url") or photo.get("original_url") or photo.get("url") or photo.get("thumb_url") or photo.get("thumb_file")
            if target:QDesktopServices.openUrl(QUrl.fromLocalFile(target) if os.path.isfile(str(target)) else QUrl(str(target)))
            else:QMessageBox.information(dialog,"Original unavailable","No full-size location was returned for this photograph.")
        def refill(result):
            bundle=dict((result or {}).get("metadata") or {});applied=set((result or {}).get("applied") or [])
            if "title" in applied:title.setText(str(bundle.get("title") or ""))
            if "caption" in applied:description.setPlainText(str(bundle.get("caption") or ""))
            if "alt" in applied:alt.setPlainText(str(bundle.get("alt") or ""))
            if "tags" in applied:tags.setText(" ".join(bundle.get("tags") or []))
            if "colors" in applied:colors.setText(" ".join(bundle.get("colors") or []))
            if "color_mode" in applied:colour.setCurrentIndex(max(colour.findData(bundle.get("color_mode","")),0))
            self.save_enrichment_local(photo["id"],result,photo);photo.update(values());self.organizer_photos[int(photo["id"])]=photo;original.update(values());QMessageBox.information(dialog,"Enrichment finished","This photograph was enriched. Review the returned details; further edits still need Save to Site.")
        def enrich_one():
            if self.busy:return
            fields=[key for key,box in ai_fields.items() if box.isChecked()]
            if not fields:QMessageBox.information(dialog,"Choose AI fields","Select at least one field for AI enrichment.");return
            action="Replace its AI-supported details" if replace_ai.isChecked() else "Fill its missing details"
            if QMessageBox.question(dialog,"Enrich this photograph?",action+" with one paid AI call?",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)==QMessageBox.Yes:self.run(lambda:self.api.enrich(photo["id"],"",fields,replace_ai.isChecked(),replace_ai.isChecked()),refill)
        def save_one(advance=False):
            data=values();valid_tag=r"#(?:[A-Za-z][A-Za-z0-9_]{0,49}|[0-9][0-9A-Fa-f]{5})"
            if data["hashtags"] and any(not re.fullmatch(valid_tag,token) for token in data["hashtags"].split()):QMessageBox.warning(dialog,"Invalid hashtags","Use # before each tag, separated by spaces.");return
            if len(data["colors"])>3 or any(not re.fullmatch(r"#[0-9A-Fa-f]{6}",value) for value in data["colors"]):QMessageBox.warning(dialog,"Invalid colours","Choose up to three colours as #RRGGBB.");return
            if len(data["alt"])>500:QMessageBox.warning(dialog,"ALT too long","ALT text must be 500 characters or less.");return
            update={"id":photo["id"],"title":data["title"],"description":data["description"],"alt":data["alt"],"hashtags":data["hashtags"],"colors":data["colors"],"category_id":data["category_ids"][0] if data["category_ids"] else None,"category_ids":data["category_ids"],"album_ids":data["album_ids"],"color_mode":data["color_mode"],"orientation":data["orientation"],"expected_modified_at":photo.get("modified_at")}
            def saved(result):
                nonlocal position,original
                failures=result.get("failed",[]);conflicts=result.get("conflicts",[])
                if failures or conflicts:QMessageBox.warning(dialog,"Photograph was not saved",str((failures+conflicts)[0].get("error") or (failures+conflicts)[0]));return
                photo.update(data);photo["category_id"]=update["category_id"];self.organizer_photos[int(photo["id"])]=photo;original=dict(data);self.render_organizer();self.render_organizer_tray()
                if advance and position+1<len(visible_ids):position+=1;load_current()
                else:QMessageBox.information(dialog,"Photograph saved","The photograph was updated on the site.")
            self.run(lambda:self.api.batch([update]),saved)
        dialog.dirty_check=lambda:values()!=original;previous.clicked.connect(lambda:move(-1));next_photo.clicked.connect(lambda:move(1));open_full.clicked.connect(open_original);restore.clicked.connect(load_current);enrich.clicked.connect(enrich_one);save.clicked.connect(lambda:save_one(False));save_next.clicked.connect(lambda:save_one(True));cancel.clicked.connect(dialog.reject);load_current();dialog.exec()
    def organizer_membership(self,photo,kind):
        return photo.get("category_ids",[]) if kind=="category" else photo.get("album_ids",[])
    def render_organizer(self,*_):
        if not hasattr(self,"organizer_members"):return
        self.organizer_members.clear();current=self.organizer_containers.currentItem();data=current.data(Qt.UserRole) if current else None
        if not data:self.organizer_heading.setText("Choose a category or album");self.organizer_count.clear();return
        kind,container_id=data;row=current.data(Qt.UserRole+1) or {};name=str(row.get("name") or current.text().strip());members=sorted((photo for photo in self.organizer_photos.values() if container_id in self.organizer_membership(photo,kind)),key=lambda x:(x.get("sort_order",0),-int(x["id"])))
        pages=max(1,(len(members)+self.organizer_page_size-1)//self.organizer_page_size);self.organizer_member_page=min(self.organizer_member_page,pages-1);start=self.organizer_member_page*self.organizer_page_size
        self.organizer_heading.setText(("Category: " if kind=="category" else "Album: ")+name);self.organizer_count.setText(f"{len(members):,} photographs · page {self.organizer_member_page+1} of {pages} · drag photographs here to add them")
        self.organizer_member_prev.setEnabled(self.organizer_member_page>0);self.organizer_member_next.setEnabled(self.organizer_member_page+1<pages)
        for photo in members[start:start+self.organizer_page_size]:self.organizer_members.addItem(self.organizer_photo_item(photo))
    def render_organizer_tray(self,*_):
        if not hasattr(self,"organizer_tray"):return
        query=self.organizer_search.text().strip().lower();date_from=self.organizer_from.text().strip();date_to=self.organizer_to.text().strip();self.organizer_tray.clear();matches=[]
        for photo in sorted(self.organizer_photos.values(),key=lambda x:-int(x["id"])):
            hay=" ".join(str(photo.get(key) or "") for key in ("title","filename","description")).lower()
            posted=str(photo.get("posted_date") or "")[:10]
            if query and query not in hay:continue
            if date_from and posted<date_from:continue
            if date_to and posted>date_to:continue
            matches.append(photo)
        total_matching=len(matches);limit=self.organizer_limit.value()
        if limit:matches=matches[:limit]
        pages=max(1,(len(matches)+self.organizer_page_size-1)//self.organizer_page_size);self.organizer_tray_page=min(self.organizer_tray_page,pages-1);start=self.organizer_tray_page*self.organizer_page_size
        limited=f" of {total_matching:,} matching" if limit and total_matching>len(matches) else "";self.organizer_tray_count.setText(f"{len(matches):,}{limited} · page {self.organizer_tray_page+1}/{pages}");self.organizer_tray_prev.setEnabled(self.organizer_tray_page>0);self.organizer_tray_next.setEnabled(self.organizer_tray_page+1<pages)
        for photo in matches[start:start+self.organizer_page_size]:self.organizer_tray.addItem(self.organizer_photo_item(photo))
    def organizer_apply_filters(self):
        for field in (self.organizer_from,self.organizer_to):
            value=field.text().strip()
            if value:
                try:datetime.strptime(value,"%Y-%m-%d")
                except ValueError:QMessageBox.warning(self,"Invalid date","Use dates in YYYY-MM-DD format.");field.setFocus();return
        if self.organizer_from.text().strip() and self.organizer_to.text().strip() and self.organizer_from.text().strip()>self.organizer_to.text().strip():QMessageBox.warning(self,"Invalid date range","The From date must be before the To date.");return
        self.organizer_tray_page=0;self.render_organizer_tray()
    def organizer_search_changed(self,*_):self.organizer_tray_page=0;self.render_organizer_tray()
    def organizer_turn_page(self,which,direction):
        if which=="member":self.organizer_member_page=max(0,self.organizer_member_page+direction);self.render_organizer()
        else:self.organizer_tray_page=max(0,self.organizer_tray_page+direction);self.render_organizer_tray()
    def organizer_change_ids(self,ids,add):
        current=self.organizer_containers.currentItem();data=current.data(Qt.UserRole) if current else None
        if not data:return
        kind,container_id=data;key="category_ids" if kind=="category" else "album_ids"
        for photo_id in ids:
            photo=self.organizer_photos.get(int(photo_id));
            if not photo:continue
            values={int(value) for value in photo.get(key,[])}
            if add:values.add(container_id)
            else:values.discard(container_id)
            photo[key]=sorted(values);photo["organization_dirty"]=True
        self.render_organizer();self.render_organizer_tray()
    def organizer_add_ids(self,ids):self.organizer_change_ids(ids,True)
    def organizer_remove_ids(self,ids):self.organizer_change_ids(ids,False)
    def organizer_add_selected(self):self.organizer_add_ids([item.data(Qt.UserRole)["id"] for item in self.organizer_tray.selectedItems()])
    def organizer_remove_selected(self):self.organizer_remove_ids([item.data(Qt.UserRole)["id"] for item in self.organizer_members.selectedItems()])
    def publish_organization(self):
        if not self.require_api():return
        dirty=[photo for photo in self.organizer_photos.values() if photo.get("organization_dirty")]
        if not dirty:QMessageBox.information(self,"Nothing to publish","No category or album memberships have changed.");return
        if QMessageBox.question(self,"Publish organization?",f"Publish category and album changes for {len(dirty)} photograph(s)?",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)!=QMessageBox.Yes:return
        updates=[{"id":photo["id"],"category_ids":photo.get("category_ids",[]),"album_ids":photo.get("album_ids",[]),"expected_modified_at":photo.get("modified_at")} for photo in dirty]
        def finished(result):
            failures=result.get("failed",[]);conflicts=result.get("conflicts",[])
            if failures or conflicts:QMessageBox.warning(self,"Some changes were not published",f"Applied: {result.get('applied',0)}\nConflicts: {len(conflicts)}\nFailed: {len(failures)}")
            else:QMessageBox.information(self,"Organization published",f"Saved category and album memberships for {result.get('applied',0)} photograph(s).");self.sync_library()
        self.run(lambda:self.api.batch(updates),finished)
    def paths(self):
        root=snap_home.site_dir(self.profile["site_url"]); return os.path.join(root,"index.json"),os.path.join(root,"meta.json")
    def local(self):
        ip,mp=self.paths()
        def rd(p,d):
            try:
                with open(p,encoding="utf-8") as f:return json.load(f)
            except Exception:return d
        return rd(ip,{"images":{}}),rd(mp,{"categories":[],"albums":[],"synced_at":None})
    def library_status(self):
        if not self.profile:self.lib_status.setText("Choose a site above.");return
        index,meta=self.local(); self.lib_status.setText(f"{len(index.get('images',{}))} photographs saved locally\nLast synced: {meta.get('synced_at') or 'never'}")
        self.meta={"categories":meta.get("categories",[]),"albums":meta.get("albums",[])};self.fill_meta()
    def sync_library(self):
        if not self.require_api():return
        def work():
            index,meta=self.local(); since=meta.get("synced_at") if meta.get("gyss_detail_schema")==2 else None; resp=self.api.library(since); images=index.setdefault("images",{}); changed=resp.get("images",[]); cats={}; albs={}; tags={}
            for iid,x in resp.get("cat_map",[]):cats.setdefault(str(iid),[]).append(x)
            for iid,x in resp.get("album_map",[]):albs.setdefault(str(iid),[]).append(x)
            for iid,x in resp.get("tag_map",[]):tags.setdefault(str(iid),[]).append("#"+str(x))
            tdir=snap_home.site_thumbs_dir(self.profile["site_url"]); downloaded=0; fetches=[]
            for n,img in enumerate(changed,1):
                k=str(img["id"]); old=images.get(k,{}); previous_url=old.get("thumb_url"); previous_modified=old.get("modified_at"); previous_file=old.get("thumb_file"); old.update(img); old["category_ids"]=cats.get(k,old.get("category_ids",[])); old["album_ids"]=albs.get(k,old.get("album_ids",[])); images[k]=old; u=img.get("thumb_url","")
                if u:
                    ext=os.path.splitext(urllib.parse.urlparse(u).path)[1] or ".jpg"; target=os.path.join(tdir,k+ext)
                    cached=os.path.join(tdir,os.path.basename(previous_file)) if previous_file else ""
                    if previous_url==u and previous_modified==img.get("modified_at") and cached and os.path.isfile(cached):
                        continue
                    fetches.append((k,u,target))
            def fetch_thumb(job):
                k,u,target=job
                rr=requests.get(u,timeout=45);rr.raise_for_status()
                tmp=target+".tmp"
                with open(tmp,"wb") as f:f.write(rr.content)
                os.replace(tmp,target)
                return k,target
            with ThreadPoolExecutor(max_workers=4) as pool:
                futures=[pool.submit(fetch_thumb,job) for job in fetches]
                for n,future in enumerate(as_completed(futures),1):
                    try:
                        k,target=future.result();images[k]["thumb_file"]="thumbs/"+os.path.basename(target);downloaded+=1
                    except Exception:pass
                    self.worker.progress.emit("Saving thumbnails",n,max(len(fetches),1))
            for k,row in images.items():
                if "tag_map" in resp:row["hashtags"]=" ".join(tags.get(k,[]))
            current={str(x) for x in resp.get("current_ids",[])}
            if current:
                for k in list(images):
                    if k not in current:images.pop(k,None)
            meta.update({"site_url":self.profile["site_url"],"synced_at":resp.get("synced_at") or meta.get("synced_at"),"site_mode":resp.get("site_mode") or meta.get("site_mode"),"gyss_detail_schema":resp.get("detail_schema",0),"categories":resp.get("categories",meta.get("categories",[])),"albums":resp.get("albums",meta.get("albums",[])),"counts":{"images":len(images)}}); ip,mp=self.paths(); os.makedirs(os.path.dirname(ip),exist_ok=True)
            for path,data in ((ip,index),(mp,meta)):
                tmp=path+".tmp"
                with open(tmp,"w",encoding="utf-8") as f:json.dump(data,f,indent=2,ensure_ascii=False)
                os.replace(tmp,path)
            if snap_library:
                try:snap_library.sync_from_sybu_data(self.profile["site_url"],resp)
                except Exception:pass
            return len(images),len(changed),downloaded
        def synced(result):
            self.library_status()
            if result[0]:
                _,saved_meta=self.local()
                self.mode=saved_meta.get("site_mode") or self.mode
                self.configure_mode()
                self.cat.setCurrentIndex(0);self.alb.setCurrentIndex(0)
                self.browse_local()
                restore=getattr(self,"sort_enrich_restore_ids",None)
                self.sort_enrich_restore_ids=None
                if restore:
                    for i in range(self.photo_list.count()):
                        item=self.photo_list.item(i)
                        if item.data(Qt.UserRole).get("id") in restore:item.setSelected(True)
            else:QMessageBox.information(self,"Library is empty","The site returned no photographs to sort.")
        self.run(work,synced)
    def open_library(self):
        if self.profile:QDesktopServices.openUrl(QUrl.fromLocalFile(snap_home.site_dir(self.profile["site_url"])))
    def browse_local(self):
        if not self.profile:return
        index,_=self.local(); rows=list(index.get("images",{}).values()); cid=self.cat.currentData();aid=self.alb.currentData()
        if cid is not None:rows=[x for x in rows if cid in x.get("category_ids",[])]
        if aid is not None:rows=[x for x in rows if aid in x.get("album_ids",[])]
        if not rows:QMessageBox.information(self,"Library is empty","Sync this site first, or clear the filters.");return
        rows=sorted(rows,key=lambda x:(x.get("sort_order",0),-int(x.get("id",0))));limit=self.limit.value();self.photos=rows[:limit];self.photo_subset_total=len(rows);self.begin_sort()
    def pull_live(self):
        if not self.require_api():return
        f={"limit":self.limit.value()}
        if self.cat.currentData() is not None:f["category_id"]=self.cat.currentData()
        if self.alb.currentData() is not None:f["album_id"]=self.alb.currentData()
        def work():
            response=self.api.photos(f); photos=response.get("photos",[]); tdir=snap_home.site_thumbs_dir(self.profile["site_url"])
            for n,photo in enumerate(photos,1):
                url=photo.get("thumb_url","")
                if url:
                    ext=os.path.splitext(urllib.parse.urlparse(url).path)[1] or ".jpg"; target=os.path.join(tdir,str(photo["id"])+ext)
                    try:
                        rr=requests.get(url,timeout=45); rr.raise_for_status()
                        with open(target,"wb") as handle:handle.write(rr.content)
                        photo["thumb_file"]="thumbs/"+os.path.basename(target)
                    except Exception:pass
                self.worker.progress.emit("Saving live thumbnails",n,max(len(photos),1))
            return response
        self.run(work,lambda r:(setattr(self,"photos",r.get("photos",[])),self.begin_sort()))
    def thumb(self,p):
        rel=p.get("thumb_file"); path=os.path.join(snap_home.site_dir(self.profile["site_url"]),rel.replace("/",os.sep)) if rel else ""; pix=QPixmap(path) if path and os.path.exists(path) else QPixmap(); return QIcon(pix) if not pix.isNull() else QIcon()
    def begin_sort(self):
        self.original={int(p["id"]):dict(p) for p in self.photos};self.photo_list.clear()
        for p in self.photos:
            it=QListWidgetItem(self.thumb(p),p.get("title") or p.get("filename") or f"Photo {p['id']}");it.setData(Qt.UserRole,p);self.photo_list.addItem(it)
        mismatched=sum(self.shape_orientation(p) is not None and self.shape_orientation(p)!=p.get("orientation") for p in self.photos)
        self.configure_mode()
        if getattr(self,"photo_subset_total",len(self.photos))>len(self.photos):self.sort_intro.setText(self.sort_intro.text()+f" Showing {len(self.photos):,} of {self.photo_subset_total:,} matching photographs; change the Library limit or filters to choose a different working set.")
        if mismatched:self.sort_intro.setText(self.sort_intro.text()+f" {mismatched} saved orientation labels do not match the photo dimensions. Use MATCH ORIENTATION TO PHOTO SHAPE to review and publish a correction.")
        self.show_page(1)
    @staticmethod
    def shape_orientation(photo):
        width,height=photo.get("width"),photo.get("height")
        if not isinstance(width,int) or not isinstance(height,int) or width<=0 or height<=0:return None
        return 2 if width==height else 1 if height>width else 0
    def match_orientations(self):
        changed=0
        for i in range(self.photo_list.count()):
            item=self.photo_list.item(i);photo=item.data(Qt.UserRole);shape=self.shape_orientation(photo)
            if shape is None or shape==photo.get("orientation"):continue
            photo["orientation"]=shape;photo["dirty"]=True;item.setData(Qt.UserRole,photo);changed+=1
        if self.photo_list.currentItem():self.edit_photo(self.photo_list.currentItem(),None)
        QMessageBox.information(self,"Orientation checked",f"{changed} photograph(s) adjusted from their saved dimensions. Review the labels, then choose PUBLISH CHANGES to save them on the site.")
    def edit_photo(self,it,_):
        p=it.data(Qt.UserRole) if it else {};self.title_edit.setText(p.get("title") or "");self.desc_edit.setPlainText(p.get("description") or "");self.alt_edit.setPlainText(p.get("alt") or "");self.tags_edit.setText(p.get("hashtags") or "");self.colors_edit.setText(" ".join(p.get("colors") or []));self.set_memberships(self.edit_cats,p.get("category_ids") or ([p.get("category_id")] if p.get("category_id") else []));self.set_memberships(self.edit_albums,p.get("album_ids") or ([p.get("album_id")] if p.get("album_id") else []));self.colour.setCurrentIndex(max(self.colour.findData(p.get("color_mode","")),0));self.orientation.setCurrentIndex(max(self.orientation.findData(p.get("orientation",0)),0))
    @staticmethod
    def membership_ids(box):
        return sorted(int(item.data(Qt.UserRole)) for item in box.selectedItems())
    @staticmethod
    def set_memberships(box,ids):
        wanted={int(value) for value in (ids or []) if value is not None}
        for index in range(box.count()):
            item=box.item(index);item.setSelected(int(item.data(Qt.UserRole)) in wanted)
    def update_sort_enrich_label(self):
        count=len(self.photo_list.selectedItems())
        self.sort_enrich.setText(f"ENRICH {count} SELECTED PHOTOS" if count>1 else "ENRICH THIS PHOTO")
    def choose_color(self):
        current=(self.colors_edit.text().split() or ["#FFFFFF"])[0]
        color=QColorDialog.getColor(initial=QColor(current),parent=self,title="Choose a colour")
        if not color.isValid():return
        values=self.colors_edit.text().split();value=color.name().upper()
        if value not in values:values=(values+[value])[:3]
        self.colors_edit.setText(" ".join(values))
    def apply_edit(self):
        it=self.photo_list.currentItem()
        if not it:return
        tags=self.tags_edit.text().strip()
        valid_tag=r"#(?:[A-Za-z][A-Za-z0-9_]{0,49}|[0-9][0-9A-Fa-f]{5})"
        if tags and any(not re.fullmatch(valid_tag,token) for token in tags.replace(","," ").split()):
            QMessageBox.warning(self,"Invalid hashtags","Use # before each tag, separated by spaces.");return
        colors=self.colors_edit.text().replace(","," ").split()
        if len(colors)>3 or any(not re.fullmatch(r"#[0-9A-Fa-f]{6}",value) for value in colors):
            QMessageBox.warning(self,"Invalid colours","Choose up to three colours as #RRGGBB.");return
        alt=self.alt_edit.toPlainText().strip()
        if len(alt)>500:QMessageBox.warning(self,"ALT too long","ALT text must be 500 characters or less.");return
        p=it.data(Qt.UserRole);before={k:p.get(k) for k in ("title","description","alt","hashtags","colors","color_mode","orientation")}
        p.update(title=self.title_edit.text(),description=self.desc_edit.toPlainText(),alt=alt,hashtags=" ".join(tags.replace(","," ").split()),colors=[value.upper() for value in colors],color_mode=self.colour.currentData(),orientation=self.orientation.currentData())
        p["dirty"]=bool(p.get("dirty") or any(before[k]!=p.get(k) for k in before))
        it.setData(Qt.UserRole,p);it.setText(("• " if p["dirty"] else "")+(p.get("title") or p.get("filename") or str(p["id"])))
    def apply_organization(self):
        selected=self.photo_list.selectedItems()
        if not selected and self.photo_list.currentItem():selected=[self.photo_list.currentItem()]
        if not selected:return
        categories=self.membership_ids(self.edit_cats);albums=self.membership_ids(self.edit_albums)
        for item in selected:
            photo=item.data(Qt.UserRole);before=(sorted(photo.get("category_ids") or []),sorted(photo.get("album_ids") or []))
            photo["category_ids"]=list(categories);photo["album_ids"]=list(albums);photo["category_id"]=categories[0] if categories else None;photo["organization_dirty"]=True
            photo["dirty"]=bool(photo.get("dirty") or before!=(categories,albums));item.setData(Qt.UserRole,photo)
            item.setText(("• " if photo["dirty"] else "")+(photo.get("title") or photo.get("filename") or str(photo["id"])))
        QMessageBox.information(self,"Organization staged",f"Categories and albums were applied to {len(selected)} photograph(s). Choose PUBLISH CHANGES to save them on the site.")
    def enrich_sort_photo(self):
        if self.busy or not self.require_api():return
        current=self.photo_list.currentItem()
        if current:
            photo=current.data(Qt.UserRole)
            editor_changes=(self.title_edit.text()!=str(photo.get("title") or "") or
                self.desc_edit.toPlainText()!=str(photo.get("description") or "") or
                self.alt_edit.toPlainText()!=str(photo.get("alt") or "") or
                self.tags_edit.text()!=str(photo.get("hashtags") or "") or
                self.colors_edit.text()!=" ".join(photo.get("colors") or []) or
                self.membership_ids(self.edit_cats)!=sorted(photo.get("category_ids") or ([photo.get("category_id")] if photo.get("category_id") else [])) or
                self.membership_ids(self.edit_albums)!=sorted(photo.get("album_ids") or ([photo.get("album_id")] if photo.get("album_id") else [])) or
                self.colour.currentData()!=photo.get("color_mode","") or
                self.orientation.currentData()!=photo.get("orientation",0))
            if editor_changes:
                QMessageBox.information(self,"Unapplied edits","Apply or discard the edits in the right panel before enriching.");return
        selected=self.photo_list.selectedItems()
        if not selected and self.photo_list.currentItem():selected=[self.photo_list.currentItem()]
        if not selected:return
        current_ids=[self.photo_list.item(i).data(Qt.UserRole)["id"] for i in range(self.photo_list.count())]
        if any((self.photo_list.item(i).data(Qt.UserRole) or {}).get("dirty") for i in range(self.photo_list.count())) or current_ids!=list(self.original):
            QMessageBox.information(self,"Unpublished edits","Publish or save this arrangement before enriching, so a refresh cannot replace it.");return
        jobs=[]
        for item in selected:
            photo=item.data(Qt.UserRole)
            fields=[key for key,empty in (("title",not photo.get("title")),("caption",not photo.get("description")),("alt",not photo.get("alt")),("tags",not photo.get("hashtags")),("colors",not photo.get("colors")),("color_mode",not photo.get("color_mode"))) if empty]
            if fields:jobs.append((dict(photo),fields))
        if not jobs:QMessageBox.information(self,"Details already filled","The selected photographs have all the displayed details. Use Images for custom re-enrichment.");return
        skipped=len(selected)-len(jobs)
        if QMessageBox.question(self,"Enrich selected photographs?",f"Fill missing details for {len(jobs)} selected photograph(s)?"+(f"\n{skipped} already complete will be skipped." if skipped else "")+f"\n\nUp to {len(jobs)} paid AI calls may be made using this site's provider. Existing values will be kept. You can stop after the current image.",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)!=QMessageBox.Yes:return
        self.cancel=False;self.sort_stop.setEnabled(True);self.sort_enrich.setEnabled(False)
        restore_ids={item.data(Qt.UserRole)["id"] for item in selected}
        def work():
            completed=[];failed=[]
            for n,(photo,fields) in enumerate(jobs,1):
                if self.cancel:break
                self.worker.progress.emit("Enriching selected photographs",n-1,len(jobs))
                try:
                    result=self.api.enrich(photo["id"],"",fields,False,False)
                    self.save_enrichment_local(photo["id"],result,photo)
                    completed.append(photo["id"])
                except Exception as exc:failed.append((n,photo["id"],str(exc)))
            return completed,failed,self.cancel
        def finished(result):
            completed,failed,stopped=result;self.sort_stop.setEnabled(False);self.sort_enrich.setEnabled(True);self.progress.hide()
            summary=f"Enriched: {len(completed)}\nFailed: {len(failed)}\nSkipped as complete: {skipped}"+("\nStopped before remaining photographs." if stopped else "")
            if failed:summary+="\n\n"+"\n".join(f"Photo {n} of {len(jobs)} (ID {i}): {error}" for n,i,error in failed[:5])
            (QMessageBox.warning if failed else QMessageBox.information)(self,"Enrichment finished",summary)
            if completed:
                self.sort_enrich_restore_ids=restore_ids
                self.sync_library()
        self.run(work,finished)
    def ordered(self):
        rows=[]
        original_positions={int(photo["id"]):index for index,photo in enumerate(self.photos,1)}
        for i in range(self.photo_list.count()):
            p=self.photo_list.item(i).data(Qt.UserRole)
            if self.mode!="carousel":
                if original_positions.get(int(p["id"]))!=i+1:p["dirty"]=True
                p["sort_order"]=i+1
            rows.append(p)
        return rows
    def save_session(self):
        if not self.profile:return
        d=os.path.join(snap_home.config_dir("gyss"),"sessions");os.makedirs(d,exist_ok=True);sid=str(uuid.uuid4());data={"session_id":sid,"profile_name":self.profile.get("name"),"site_url":self.profile["site_url"],"last_saved":stamp(),"photos":self.ordered(),"unresolved_conflicts":[]}
        with open(os.path.join(d,sid+".json"),"w",encoding="utf-8") as f:json.dump(data,f,indent=2,ensure_ascii=False)
        QMessageBox.information(self,"Session saved","This working arrangement is saved on this computer.")
    def push(self):
        if not self.require_api():return
        try:
            self.mode=self.api.ping().get("site_mode",self.mode)
            self.configure_mode()
        except Exception as exc:QMessageBox.warning(self,"Cannot verify site mode",str(exc));return
        if self.mode=="carousel" and [self.photo_list.item(i).data(Qt.UserRole)["id"] for i in range(self.photo_list.count())]!=[p["id"] for p in self.photos]:
            QMessageBox.information(self,"Use Grid for feed order","GRAMOFSMACK orders published posts, not individual photographs. Open Grid to arrange the feed.")
            self.show_page(3)
            return
        dirty=[p for p in self.ordered() if p.get("dirty")]
        if not dirty:QMessageBox.information(self,"Nothing to publish","No photographs have changed.");return
        detail_changed=any(any(p.get(key)!=self.original.get(int(p["id"]),{}).get(key) for key in ("alt","hashtags","colors","orientation")) for p in dirty)
        if detail_changed:
            try:capabilities=self.api.ping().get("sort_detail_fields",[])
            except Exception as exc:QMessageBox.warning(self,"Cannot verify site support",str(exc));return
            if not all(key in capabilities for key in ("alt","hashtags","colors","orientation")):
                QMessageBox.warning(self,"Site update needed","This site has not yet received the GYSS detail-field update. Your ALT, hashtags and colour edits remain in this session; publish after the site updates.");return
        if QMessageBox.question(self,"Publish changes?",f"Publish {len(dirty)} changed photographs to\n{self.profile['site_url']}?",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)!=QMessageBox.Yes:return
        u=[]
        for p in dirty:
            original=self.original.get(int(p["id"]),{})
            row={"id":p["id"],"title":p.get("title",""),"description":p.get("description",""),"category_id":p.get("category_id"),"color_mode":p.get("color_mode",""),"expected_modified_at":original.get("modified_at")}
            if p.get("organization_dirty"):
                row["category_ids"]=p.get("category_ids",[]);row["album_ids"]=p.get("album_ids",[])
            if self.mode!="carousel":row["sort_order"]=p["sort_order"]
            for key in ("alt","hashtags","colors","orientation"):
                if p.get(key)!=original.get(key):row[key]=p.get(key)
            u.append(row)
        def published(result):
            failures=result.get("failed",[]);conflicts=result.get("conflicts",[])
            summary=f"Applied: {result.get('applied',0)}\nConflicts: {len(conflicts)}\nFailed: {len(failures)}"
            if failures or conflicts:
                detail="\n".join(str(x.get("error") or x) for x in (failures+conflicts)[:8])
                QMessageBox.warning(self,"Some edits were not published",summary+"\n\n"+detail)
            else:
                QMessageBox.information(self,"Publish finished",summary)
                self.sync_library()
        self.run(lambda:self.api.batch(u),published)
    def _gram_with_thumbs(self):
        api=self.api;site_url=self.profile["site_url"]
        response=api.gram_posts(); posts=response.get("posts",[]); tdir=snap_home.site_thumbs_dir(site_url)
        os.makedirs(tdir,exist_ok=True)
        def fetch(post):
            url=post.get("thumb_url","")
            if url:
                ext=os.path.splitext(urllib.parse.urlparse(url).path)[1] or ".jpg"; target=os.path.join(tdir,"gram-post-"+str(post["id"])+ext)
                try:
                    if not os.path.isfile(target) or os.path.getsize(target)==0:
                        rr=requests.get(url,timeout=25); rr.raise_for_status()
                        with open(target,"wb") as handle:handle.write(rr.content)
                    post["thumb_file"]=target
                except Exception:pass
        with ThreadPoolExecutor(max_workers=8) as pool:
            for n,_ in enumerate(pool.map(fetch,posts),1):
                self.worker.progress.emit("Loading grid photographs",n,max(len(posts),1))
        return response
    def load_grid(self,show=False):
        if not self.require_api():return
        site_url=self.profile["site_url"]
        self.gram_empty.setText("Loading published posts…")
        def loaded(response):
            if not self.profile or self.profile["site_url"]!=site_url:return
            self.mode="carousel";self.configure_mode();self.render_gram(response.get("posts",[]));self.gram_site=site_url
            if show:self.show_page(3)
        self.run(self._gram_with_thumbs,loaded)
    def render_gram(self,posts):
        self.gram.clear();self.gram_loaded=time.time()
        self.gram_empty.setText("No published GRAMOFSMACK posts were returned. Library photographs are separate from feed posts; publish posts before ordering them here." if not posts else "")
        self.gram_empty.setVisible(not posts)
        for p in posts:
            pix=QPixmap(p.get("thumb_file", "")); icon=QIcon(pix) if not pix.isNull() else QIcon()
            it=QListWidgetItem(icon,p.get("title") or f"Post {p['id']}");it.setData(Qt.UserRole,p);self.gram.addItem(it)
    def push_grid(self):
        ids=[self.gram.item(i).data(Qt.UserRole)["id"] for i in range(self.gram.count())]
        if not ids:QMessageBox.information(self,"No posts to order","Refresh Grid. If it remains empty, this site has no published GRAMOFSMACK posts to order.");return
        if ids and time.time()-self.gram_loaded>300:QMessageBox.warning(self,"Refresh before publishing","This grid is more than five minutes old. Refresh it so newer online changes are not overwritten.");return
        if ids and QMessageBox.question(self,"Publish grid order?",f"Write this order for {len(ids)} posts?",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)==QMessageBox.Yes:self.run(lambda:self.api.gram_order(ids),lambda _:QMessageBox.information(self,"Grid published","The GRAMOFSMACK order is updated."))
    def move_gram_selected(self,direction):
        rows=sorted({self.gram.row(item) for item in self.gram.selectedItems()})
        if not rows:return
        if direction<0:
            if rows[0]>0:self.gram.move_selected_to(rows[0]-1)
        elif rows[-1]<self.gram.count()-1:self.gram.move_selected_to(rows[-1]+2)
    def carousel(self):
        ids=[x.data(Qt.UserRole)["id"] for x in self.gram.selectedItems()]
        if len(ids)<2:QMessageBox.information(self,"Select more posts","Select at least two single posts. The first selected becomes the cover.");return
        if QMessageBox.question(self,"Make carousel?",f"Combine {len(ids)} posts? The first selected will be the cover.",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)==QMessageBox.Yes:self.run(lambda:self.api.gram_carousel(ids,ids[0]),lambda _:(QMessageBox.information(self,"Carousel made","The selected posts are now one carousel."),self.load_grid()))
    def _audit_with_thumbs(self):
        response=self.api.audit(); rows=response.get("images",response.get("items",[])); tdir=snap_home.site_thumbs_dir(self.profile["site_url"])
        for n,row in enumerate(rows,1):
            url=row.get("thumb_url","")
            if url:
                ext=os.path.splitext(urllib.parse.urlparse(url).path)[1] or ".jpg"; target=os.path.join(tdir,"audit-"+str(row["id"])+ext)
                try:
                    if not os.path.isfile(target):
                        rr=requests.get(url,timeout=45); rr.raise_for_status()
                        with open(target,"wb") as handle:handle.write(rr.content)
                    row["thumb_file"]=target
                except Exception:pass
            self.worker.progress.emit("Loading audit photographs",n,max(len(rows),1))
        return response
    def scan(self):
        if not self.require_api():return
        def after(r):
            self.prompt.setPlainText(r.get("prompt",self.prompt.toPlainText()));self.audit.clear(); wanted={k for k,w in self.fields.items() if w.isChecked()}
            rows=[x for x in r.get("images",r.get("items",[])) if not wanted or wanted.issubset(set(x.get("missing",[])))]
            for x in rows:
                identity=x.get('title') or x.get('filename') or f"Photo #{x['id']}"; date=str(x.get('posted_date') or '')[:10]
                detail=f"{identity}\nPhoto #{x['id']}"+(f" · {date}" if date else "")+f"\nMissing: {', '.join(x.get('missing',[]))}"
                pix=QPixmap(x.get("thumb_file", "")); icon=QIcon(pix) if not pix.isNull() else QIcon(); it=QListWidgetItem(icon,detail); it.setToolTip("Double-click to preview"); it.setFlags(it.flags()|Qt.ItemIsUserCheckable); it.setCheckState(Qt.Unchecked); it.setData(Qt.UserRole,x); self.audit.addItem(it)
            self.audit_count()
            if not rows:QMessageBox.information(self,"Nothing matched","No photographs are missing all of the selected fields.")
        self.run(self._audit_with_thumbs,after)
    def enrich(self):
        selected=[self.audit.item(i).data(Qt.UserRole) for i in range(self.audit.count()) if self.audit.item(i).checkState()==Qt.Checked];ids=[x["id"] for x in selected];fields=[k for k,w in self.fields.items() if w.isChecked()]
        if not ids or not fields:return
        prompt=self.prompt.toPlainText().strip()
        if not prompt:QMessageBox.information(self,"Prompt needed","Scan the site and provide an image-enrichment prompt first.");return
        if QMessageBox.question(self,"Paid AI calls",f"Process {len(ids)} images one at a time?\n\nThis makes up to {len(ids)} paid AI calls using the provider configured on this site. You can stop after the current image.",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)!=QMessageBox.Yes:return
        overwrite=self.overwrite.isChecked();force=self.force.isChecked();self.cancel=False;self.stop.setEnabled(True)
        def work():
            out=[]
            for n,i in enumerate(ids,1):
                if self.cancel:break
                self.worker.progress.emit("Enriching images",n-1,len(ids)); result=self.api.enrich(i,prompt,fields,overwrite,force); self.save_enrichment_local(i,result,next((x for x in selected if x["id"]==i),None)); out.append(result)
            return out
        self.run(work,lambda r:(self.stop.setEnabled(False),QMessageBox.information(self,"Enrichment finished",f"Processed {len(r)} images."),self.scan()))
    def new_site(self):self.site.setCurrentIndex(0);self.name.clear();self.url.clear();self.key.clear();self.show_page(5)
    def save_site(self):
        url=self.url.text().strip();key=self.key.text().strip();name=self.name.text().strip()
        if not url or not key:QMessageBox.warning(self,"Missing connection","Enter the site URL and GYSS API key.");return
        if "://" not in url:url="https://"+url
        host=urllib.parse.urlparse(url).hostname
        if url.lower().startswith("http://") and host not in ("localhost","127.0.0.1","::1") and QMessageBox.warning(self,"Insecure connection","This sends the API key without encryption. Use HTTPS if possible. Continue anyway?",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)!=QMessageBox.Yes:return
        snap_profiles.save({"name":name or snap_home.site_key(url),"site_url":url,"last_connected":None,"extras":(self.profile or {}).get("extras",{})});snap_creds.set_site(url,"api_key_gyss",key)
        if snap_native_creds:
            try:snap_native_creds.set_site(url,"gyss",key)
            except Exception:pass
        self.load_profiles(url);QMessageBox.information(self,"Connection saved","The dedicated GYSS connection is saved.")
    def delete_site(self):
        if not self.profile:return
        if QMessageBox.warning(self,"Delete shared connection?",f"Delete {self.profile.get('name')} from every SnapSmack desktop tool?\n\nThe local photo library will be kept.",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)==QMessageBox.Yes:snap_profiles.delete_by_site(self.profile["site_url"]);self.load_profiles()

def main():
    app=QApplication(sys.argv);app.setApplicationName("GET YOUR SHIT SORTED");app.setStyleSheet(STYLE);w=Window();w.show();return app.exec()
if __name__=="__main__":raise SystemExit(main())
# ===== SNAPSMACK EOF =====
