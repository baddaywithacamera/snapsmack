"""GET YOUR SHIT SORTED — native Qt, local-first photo organizer."""
import json, os, sys, threading, time, urllib.parse, uuid
from datetime import datetime, timezone
import requests
from PySide6.QtCore import QObject, Qt, Signal, QSize, QUrl
from PySide6.QtGui import QAction, QDesktopServices, QIcon, QKeySequence, QPixmap
from PySide6.QtWidgets import (QApplication,QAbstractItemView,QCheckBox,QComboBox,QFormLayout,QFrame,QHBoxLayout,QLabel,QLineEdit,QListWidget,QListWidgetItem,QMainWindow,QMessageBox,QProgressBar,QPushButton,QScrollArea,QSpinBox,QStackedWidget,QTextEdit,QVBoxLayout,QWidget)

HERE=os.path.dirname(os.path.abspath(__file__)); SHARED=os.path.abspath(os.path.join(HERE,"..","_shared")); sys.path.insert(0,SHARED)
import snap_connections, snap_creds, snap_home, snap_profiles
try: import snap_native_creds
except Exception: snap_native_creds=None
try: import snap_library
except Exception: snap_library=None
from _version import BUILD_VERSION

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
        self.base=p["site_url"].rstrip("/"); self.s=requests.Session(); self.s.headers.update({"Authorization":"Bearer "+p.get("api_key",""),"Accept":"application/json"})
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

class Window(QMainWindow):
    def __init__(self):
        super().__init__(); self.setWindowTitle(f"GET YOUR SHIT SORTED — {BUILD_VERSION}"); self.setWindowIcon(QIcon(icon_path())); self.resize(1420,900); self.setMinimumSize(1060,700)
        self.profiles=[]; self.profile=None; self.api=None; self.mode=""; self.meta={"categories":[],"albums":[]}; self.photos=[]; self.original={}; self.busy=False; self.cancel=False; self.gram_loaded=0
        self.worker=Worker(); self.worker.done.connect(self.done); self.worker.failed.connect(self.failed); self.worker.progress.connect(self.on_progress)
        self.build(); self.load_profiles()
        help_action=QAction("Help",self); help_action.setShortcut(QKeySequence.HelpContents); help_action.triggered.connect(self.show_help); self.addAction(help_action)
    def build(self):
        root=QWidget(); sh=QHBoxLayout(root); sh.setContentsMargins(0,0,0,0); sh.setSpacing(0); side=QFrame(); side.setObjectName("Sidebar"); side.setFixedWidth(230); sl=QVBoxLayout(side); sl.setContentsMargins(18,24,18,18)
        sl.addWidget(lbl("SNAPSMACK","Eyebrow")); sl.addWidget(lbl("GET YOUR SHIT\nSORTED","Title")); sl.addSpacing(25); self.pages=QStackedWidget(); self.nav=[]
        specs=(("Library","Choose and sync",self.library_page),("Sort","Arrange photographs",self.sort_page),("Grid","GRAMOFSMACK",self.grid_page),("Images","Fill missing details",self.images_page),("Sites","Connections",self.sites_page))
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
        p,l=self.page("Sort photographs","Drag to reorder. Select a photograph to edit its details or colour classification."); r=QHBoxLayout(); self.photo_list=QListWidget(); self.photo_list.setViewMode(QListWidget.IconMode); self.photo_list.setIconSize(QSize(150,110)); self.photo_list.setGridSize(QSize(180,165)); self.photo_list.setResizeMode(QListWidget.Adjust); self.photo_list.setDragDropMode(QAbstractItemView.InternalMove); self.photo_list.setSelectionMode(QAbstractItemView.ExtendedSelection); self.photo_list.currentItemChanged.connect(self.edit_photo); r.addWidget(self.photo_list,1)
        e,el=card("Selected photograph"); e.setFixedWidth(330); form=QFormLayout(); self.title_edit=QLineEdit(); self.desc_edit=QTextEdit(); self.desc_edit.setMaximumHeight(130); self.edit_cat=QComboBox(); self.colour=QComboBox(); self.colour.addItem("Not classified",""); self.colour.addItem("Colour","color"); self.colour.addItem("Black & white","bw"); form.addRow("Title",self.title_edit); form.addRow("Description",self.desc_edit); form.addRow("Category",self.edit_cat); form.addRow("Classification",self.colour); el.addLayout(form); apply=QPushButton("APPLY TO SESSION"); apply.clicked.connect(self.apply_edit); el.addWidget(apply); r.addWidget(e); l.addLayout(r,1); br=QHBoxLayout(); save=QPushButton("SAVE SESSION"); save.clicked.connect(self.save_session); br.addWidget(save); br.addStretch(); push=QPushButton("PUBLISH CHANGES"); push.setObjectName("Primary"); push.clicked.connect(self.push); br.addWidget(push); l.addLayout(br); return p
    def grid_page(self):
        p,l=self.page("GRAMOFSMACK grid","Drag posts into order. Select two or more singles to make a carousel. Nothing changes online until you confirm."); self.gram=QListWidget(); self.gram.setViewMode(QListWidget.IconMode); self.gram.setIconSize(QSize(170,170)); self.gram.setGridSize(QSize(195,215)); self.gram.setResizeMode(QListWidget.Adjust); self.gram.setDragDropMode(QAbstractItemView.InternalMove); self.gram.setSelectionMode(QAbstractItemView.ExtendedSelection); l.addWidget(self.gram,1); r=QHBoxLayout(); refresh=QPushButton("REFRESH GRID"); refresh.clicked.connect(self.load_grid); r.addWidget(refresh); r.addStretch(); car=QPushButton("MAKE SELECTED A CAROUSEL"); car.clicked.connect(self.carousel); r.addWidget(car); order=QPushButton("PUBLISH ORDER"); order.setObjectName("Primary"); order.clicked.connect(self.push_grid); r.addWidget(order); l.addLayout(r); return p
    def images_page(self):
        p,l=self.page("Find and fill missing details","1. Choose the missing fields to find.  2. Scan the site.  3. Select photographs.  4. Enrich the selected photographs."); o,ol=card("Show photographs missing…","Choose one or more fields. The scan results below will be filtered to match."); r=QHBoxLayout(); self.fields={}
        for key,text,on in (("title","Title",1),("caption","Caption",1),("alt","ALT text",1),("tags","Tags",1),("colors","AI colours",1),("color_mode","Colour/B&W",1),("ocr","OCR",0),("content_warning","Safety review",0)):
            w=QCheckBox(text); w.setChecked(on); self.fields[key]=w; r.addWidget(w)
        ol.addLayout(r); ol.addWidget(lbl("Every enrichment generates and saves the complete metadata bundle. These boxes control only which missing values are applied online now.","Muted")); preset=QPushButton("TITLE + CAPTION + HASHTAGS"); preset.clicked.connect(lambda:self.set_missing_fields({"title","caption","tags"})); ol.addWidget(preset,0,Qt.AlignLeft); self.overwrite=QCheckBox("Replace existing values"); self.force=QCheckBox("Ignore saved bundle and pay for a fresh AI result"); ol.addWidget(self.overwrite); ol.addWidget(self.force); l.addWidget(o); self.prompt=QTextEdit(); self.prompt.setPlaceholderText("The site’s saved enrichment prompt appears after scanning."); self.prompt.setMaximumHeight(180); l.addWidget(self.prompt); self.audit=QListWidget(); self.audit.setSelectionMode(QAbstractItemView.ExtendedSelection); l.addWidget(self.audit,1); r=QHBoxLayout(); scan=QPushButton("SCAN SITE"); scan.clicked.connect(self.scan); r.addWidget(scan); select_all=QPushButton("SELECT ALL RESULTS"); select_all.clicked.connect(self.audit.selectAll); r.addWidget(select_all); self.stop=QPushButton("STOP AFTER THIS IMAGE"); self.stop.setObjectName("Danger"); self.stop.setEnabled(False); self.stop.clicked.connect(lambda:setattr(self,"cancel",True)); r.addWidget(self.stop); r.addStretch(); en=QPushButton("ENRICH SELECTED"); en.setObjectName("Primary"); en.clicked.connect(self.enrich); r.addWidget(en); l.addLayout(r); return p
    def sites_page(self):
        p,l=self.page("Site connections","Most days you should not need this page. Editing a connection affects every SnapSmack desktop tool."); c,cl=card("Selected connection"); form=QFormLayout(); self.name=QLineEdit(); self.url=QLineEdit(); self.key=QLineEdit(); self.key.setEchoMode(QLineEdit.Password); form.addRow("Friendly name",self.name); form.addRow("Site URL",self.url); form.addRow("GYSS API key",self.key); cl.addLayout(form); r=QHBoxLayout(); new=QPushButton("NEW CONNECTION"); new.clicked.connect(self.new_site); r.addWidget(new); delete=QPushButton("DELETE SELECTED CONNECTION"); delete.setObjectName("Danger"); delete.clicked.connect(self.delete_site); r.addWidget(delete); r.addStretch(); save=QPushButton("SAVE CONNECTION"); save.setObjectName("Primary"); save.clicked.connect(self.save_site); r.addWidget(save); cl.addLayout(r); l.addWidget(c); l.addStretch(); return p
    def show_page(self,n):
        self.pages.setCurrentIndex(n)
        for i,b in enumerate(self.nav):b.setChecked(i==n)
    def show_help(self):
        QMessageBox.information(self,"GYSS help",
            "SYNC LIBRARY FROM SITE\nDownloads the complete catalogue and its thumbnails to this computer. Run it once, then again whenever the site changes.\n\n"
            "BROWSE LOCAL COPY\nOpens the saved library. It works offline and is the normal place to sort a whole archive.\n\n"
            "PULL A LIVE SESSION\nFetches only the currently filtered photographs, up to the Live pull limit. Their thumbnails are cached locally too, but this does not replace a complete library sync.\n\n"
            "Nothing changes online until you choose PUBLISH CHANGES and confirm.")
    def set_missing_fields(self,wanted):
        for key,box in self.fields.items():box.setChecked(key in wanted)
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
        self.busy=False; QApplication.restoreOverrideCursor(); self.progress.hide(); cb=getattr(self,"after",None); self.after=None
        if cb:cb(result)
    def failed(self,msg):
        self.busy=False; QApplication.restoreOverrideCursor(); self.progress.hide(); self.stop.setEnabled(False); self.after=None; QMessageBox.critical(self,"GYSS could not finish",msg); self.status.setText("● Needs attention")
    def on_progress(self,text,n,total):self.progress.show(); self.progress.setRange(0,max(total,1)); self.progress.setValue(n); self.progress.setFormat(text+"  %p%")
    def load_profiles(self,keep=""):
        old=keep or (self.profile or {}).get("site_url",""); self.profiles=[p for p in snap_connections.list_connections("gyss") if p.get("extras",{}).get("gyss_site_mode")!="smacktalk"]; self.site.blockSignals(True); self.site.clear(); self.site.addItem("Choose a site…",None)
        for p in self.profiles:self.site.addItem(f"{p.get('name') or snap_home.site_key(p['site_url'])}  ·  {snap_home.site_key(p['site_url'])}",p)
        self.site.setCurrentIndex(next((i+1 for i,p in enumerate(self.profiles) if p.get("site_url")==old),0)); self.site.blockSignals(False); self.site_changed(self.site.currentIndex())
    def site_changed(self,i):
        self.profile=self.site.itemData(i) if i>=0 else None; self.api=API(self.profile) if self.profile and self.profile.get("api_key") else None; p=self.profile or {}; self.name.setText(p.get("name","")); self.url.setText(p.get("site_url","")); self.key.setText(p.get("api_key","")); self.status.setText("● Ready to verify" if self.api else ("● Run Discover Fleet" if self.profile else "Choose a site")); self.library_status()
    def require_api(self):
        if self.api:return True
        QMessageBox.information(self,"Choose a site","Choose a site with a saved GYSS key first."); return False
    def test(self):
        if self.require_api():self.run(self.api.ping,self.connected)
    def connected(self,r):
        self.mode=r.get("site_mode","photoblog"); self.status.setText(f"● Connected · {self.mode}")
        if self.mode=="carousel":self.load_grid(show=True)
        elif self.mode=="photoblog":self.run(self.api.meta,lambda x:(setattr(self,"meta",x),self.fill_meta(),self.show_page(0)))
        else:self.show_page(3)
    def fill_meta(self):
        for box,rows in ((self.cat,self.meta.get("categories",[])),(self.alb,self.meta.get("albums",[])),(self.edit_cat,self.meta.get("categories",[]))):
            box.clear(); box.addItem("All" if box is not self.edit_cat else "No category",None)
            for x in rows:box.addItem(str(x.get("name","")),x.get("id"))
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
        if meta.get("categories") or meta.get("albums"):self.meta=meta;self.fill_meta()
    def sync_library(self):
        if not self.require_api():return
        def work():
            index,meta=self.local(); resp=self.api.library(meta.get("synced_at")); images=index.setdefault("images",{}); changed=resp.get("images",[]); cats={}; albs={}
            for iid,x in resp.get("cat_map",[]):cats.setdefault(str(iid),[]).append(x)
            for iid,x in resp.get("album_map",[]):albs.setdefault(str(iid),[]).append(x)
            tdir=snap_home.site_thumbs_dir(self.profile["site_url"]); downloaded=0
            for n,img in enumerate(changed,1):
                k=str(img["id"]); old=images.get(k,{}); old.update(img); old["category_ids"]=cats.get(k,old.get("category_ids",[])); old["album_ids"]=albs.get(k,old.get("album_ids",[])); images[k]=old; u=img.get("thumb_url","")
                if u:
                    ext=os.path.splitext(urllib.parse.urlparse(u).path)[1] or ".jpg"; target=os.path.join(tdir,k+ext)
                    try:
                        rr=requests.get(u,timeout=45);rr.raise_for_status()
                        with open(target,"wb") as f:f.write(rr.content)
                        old["thumb_file"]="thumbs/"+os.path.basename(target);downloaded+=1
                    except Exception:pass
                self.worker.progress.emit("Saving thumbnails",n,max(len(changed),1))
            current={str(x) for x in resp.get("current_ids",[])}
            if current:
                for k in list(images):
                    if k not in current:images.pop(k,None)
            meta.update({"site_url":self.profile["site_url"],"synced_at":resp.get("synced_at") or meta.get("synced_at"),"site_mode":resp.get("site_mode") or meta.get("site_mode"),"categories":resp.get("categories",meta.get("categories",[])),"albums":resp.get("albums",meta.get("albums",[])),"counts":{"images":len(images)}}); ip,mp=self.paths(); os.makedirs(os.path.dirname(ip),exist_ok=True)
            for path,data in ((ip,index),(mp,meta)):
                tmp=path+".tmp"
                with open(tmp,"w",encoding="utf-8") as f:json.dump(data,f,indent=2,ensure_ascii=False)
                os.replace(tmp,path)
            if snap_library:
                try:snap_library.sync_from_sybu_data(self.profile["site_url"],resp)
                except Exception:pass
            return len(images),len(changed),downloaded
        self.run(work,lambda r:(self.library_status(),QMessageBox.information(self,"Library ready",f"{r[0]} photographs are ready locally.\n{r[1]} records changed; {r[2]} thumbnails downloaded.")))
    def open_library(self):
        if self.profile:QDesktopServices.openUrl(QUrl.fromLocalFile(snap_home.site_dir(self.profile["site_url"])))
    def browse_local(self):
        if not self.profile:return
        index,_=self.local(); rows=list(index.get("images",{}).values()); cid=self.cat.currentData();aid=self.alb.currentData()
        if cid is not None:rows=[x for x in rows if cid in x.get("category_ids",[])]
        if aid is not None:rows=[x for x in rows if aid in x.get("album_ids",[])]
        if not rows:QMessageBox.information(self,"Library is empty","Sync this site first, or clear the filters.");return
        self.photos=sorted(rows,key=lambda x:(x.get("sort_order",0),-int(x.get("id",0))));self.begin_sort()
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
        self.show_page(1)
    def edit_photo(self,it,_):
        p=it.data(Qt.UserRole) if it else {};self.title_edit.setText(p.get("title",""));self.desc_edit.setPlainText(p.get("description",""));self.edit_cat.setCurrentIndex(max(self.edit_cat.findData(p.get("category_id")),0));self.colour.setCurrentIndex(max(self.colour.findData(p.get("color_mode","")),0))
    def apply_edit(self):
        it=self.photo_list.currentItem()
        if not it:return
        p=it.data(Qt.UserRole);p.update(title=self.title_edit.text(),description=self.desc_edit.toPlainText(),category_id=self.edit_cat.currentData(),color_mode=self.colour.currentData(),dirty=True);it.setData(Qt.UserRole,p);it.setText("• "+(p.get("title") or p.get("filename") or str(p["id"])))
    def ordered(self):
        rows=[]
        for i in range(self.photo_list.count()):
            p=self.photo_list.item(i).data(Qt.UserRole);p["sort_order"]=i+1;p["dirty"]=p.get("dirty") or self.original.get(int(p["id"]),{}).get("sort_order")!=i+1;rows.append(p)
        return rows
    def save_session(self):
        if not self.profile:return
        d=os.path.join(snap_home.config_dir("gyss"),"sessions");os.makedirs(d,exist_ok=True);sid=str(uuid.uuid4());data={"session_id":sid,"profile_name":self.profile.get("name"),"site_url":self.profile["site_url"],"last_saved":stamp(),"photos":self.ordered(),"unresolved_conflicts":[]}
        with open(os.path.join(d,sid+".json"),"w",encoding="utf-8") as f:json.dump(data,f,indent=2,ensure_ascii=False)
        QMessageBox.information(self,"Session saved","This working arrangement is saved on this computer.")
    def push(self):
        if not self.require_api():return
        dirty=[p for p in self.ordered() if p.get("dirty")]
        if not dirty:QMessageBox.information(self,"Nothing to publish","No photographs have changed.");return
        if QMessageBox.question(self,"Publish changes?",f"Publish {len(dirty)} changed photographs to\n{self.profile['site_url']}?",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)!=QMessageBox.Yes:return
        u=[{"id":p["id"],"sort_order":p["sort_order"],"title":p.get("title",""),"description":p.get("description",""),"category_id":p.get("category_id"),"color_mode":p.get("color_mode",""),"expected_modified_at":self.original.get(int(p["id"]),{}).get("modified_at")} for p in dirty]
        self.run(lambda:self.api.batch(u),lambda r:QMessageBox.information(self,"Publish finished",f"Applied: {r.get('applied',0)}\nConflicts: {len(r.get('conflicts',[]))}\nFailed: {len(r.get('failed',[]))}"))
    def _gram_with_thumbs(self):
        response=self.api.gram_posts(); posts=response.get("posts",[]); tdir=snap_home.site_thumbs_dir(self.profile["site_url"])
        for n,post in enumerate(posts,1):
            url=post.get("thumb_url","")
            if url:
                ext=os.path.splitext(urllib.parse.urlparse(url).path)[1] or ".jpg"; target=os.path.join(tdir,"gram-post-"+str(post["id"])+ext)
                try:
                    rr=requests.get(url,timeout=45); rr.raise_for_status()
                    with open(target,"wb") as handle:handle.write(rr.content)
                    post["thumb_file"]=target
                except Exception:pass
            self.worker.progress.emit("Loading grid photographs",n,max(len(posts),1))
        return response
    def load_grid(self,show=False):
        if self.require_api():self.run(self._gram_with_thumbs,lambda r:(self.render_gram(r.get("posts",[])),self.show_page(2) if show else None))
    def render_gram(self,posts):
        self.gram.clear();self.gram_loaded=time.time()
        for p in posts:
            pix=QPixmap(p.get("thumb_file", "")); icon=QIcon(pix) if not pix.isNull() else QIcon()
            it=QListWidgetItem(icon,p.get("title") or f"Post {p['id']}");it.setData(Qt.UserRole,p);self.gram.addItem(it)
    def push_grid(self):
        ids=[self.gram.item(i).data(Qt.UserRole)["id"] for i in range(self.gram.count())]
        if ids and time.time()-self.gram_loaded>300:QMessageBox.warning(self,"Refresh before publishing","This grid is more than five minutes old. Refresh it so newer online changes are not overwritten.");return
        if ids and QMessageBox.question(self,"Publish grid order?",f"Write this order for {len(ids)} posts?",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)==QMessageBox.Yes:self.run(lambda:self.api.gram_order(ids),lambda _:QMessageBox.information(self,"Grid published","The GRAMOFSMACK order is updated."))
    def carousel(self):
        ids=[x.data(Qt.UserRole)["id"] for x in self.gram.selectedItems()]
        if len(ids)<2:QMessageBox.information(self,"Select more posts","Select at least two single posts. The first selected becomes the cover.");return
        if QMessageBox.question(self,"Make carousel?",f"Combine {len(ids)} posts? The first selected will be the cover.",QMessageBox.Yes|QMessageBox.No,QMessageBox.No)==QMessageBox.Yes:self.run(lambda:self.api.gram_carousel(ids,ids[0]),lambda _:(QMessageBox.information(self,"Carousel made","The selected posts are now one carousel."),self.load_grid()))
    def scan(self):
        if not self.require_api():return
        def after(r):
            self.prompt.setPlainText(r.get("prompt",self.prompt.toPlainText()));self.audit.clear(); wanted={k for k,w in self.fields.items() if w.isChecked()}
            rows=[x for x in r.get("images",r.get("items",[])) if not wanted or wanted.issubset(set(x.get("missing",[])))]
            for x in rows:
                it=QListWidgetItem(f"{x.get('title') or x.get('filename') or 'Untitled'}  ·  missing: {', '.join(x.get('missing',[]))}");it.setData(Qt.UserRole,x);self.audit.addItem(it)
            if not rows:QMessageBox.information(self,"Nothing matched","No photographs are missing all of the selected fields.")
        self.run(self.api.audit,after)
    def enrich(self):
        selected=[x.data(Qt.UserRole) for x in self.audit.selectedItems()];ids=[x["id"] for x in selected];fields=[k for k,w in self.fields.items() if w.isChecked()]
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
    def new_site(self):self.site.setCurrentIndex(0);self.name.clear();self.url.clear();self.key.clear();self.show_page(4)
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
