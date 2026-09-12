"""SMACK YOUR BATCH UP — responsive Qt desktop shell over the proven engine."""

from __future__ import annotations

import os
import sys
import threading

from PySide6.QtCore import QObject, Qt, QTimer, Signal
from PySide6.QtGui import QIcon
from PySide6.QtWidgets import (QApplication, QCheckBox, QComboBox, QFileDialog,
    QFrame, QHBoxLayout, QHeaderView, QLabel, QLineEdit, QMainWindow,
    QMessageBox, QProgressBar, QPushButton, QScrollArea, QSizePolicy, QStackedWidget,
    QTableWidget, QTableWidgetItem, QTextEdit, QVBoxLayout, QWidget)

import sybu_core

# Kept explicit so the Qt shell never imports the legacy Tk entry point (which
# redirects stdout/stderr and initializes Tk-only services at import time).
BUILD_VERSION = "0.7.62"

GREEN = "#73f04b"; BASE = "#0d120f"; VOID = "#090c0a"; PANEL = "#121a15"
CARD = "#18231c"; BORDER = "#28372d"; INK = "#f4f7f2"; DIM = "#829087"
STYLE = f"""
QWidget {{ background:{BASE}; color:{INK}; font-family:'Segoe UI'; font-size:14px; }}
QMainWindow {{ background:{VOID}; }}
QFrame#Sidebar {{ background:{VOID}; border-right:1px solid {BORDER}; }}
QFrame#Header {{ background:{PANEL}; border-bottom:1px solid {BORDER}; }}
QFrame#Card {{ background:{CARD}; border:1px solid {BORDER}; border-radius:12px; }}
QLabel#Eyebrow {{ color:{GREEN}; font-size:11px; font-weight:800; letter-spacing:2px; }}
QLabel#Brand {{ font-size:20px; font-weight:850; }}
QLabel#PageTitle {{ font-size:24px; font-weight:750; }}
QLabel#CardTitle {{ font-size:16px; font-weight:700; }}
QLabel#Muted {{ color:{DIM}; }}
QLabel#Good {{ color:{GREEN}; background:#142611; border:1px solid #315d25; border-radius:10px; padding:6px 11px; font-weight:700; }}
QLabel#Warn {{ color:#ffbf47; background:#271f0f; border:1px solid #5f4821; border-radius:10px; padding:6px 11px; font-weight:700; }}
QPushButton {{ background:#202c24; border:1px solid #34463a; border-radius:8px; padding:9px 14px; font-weight:650; }}
QPushButton:hover {{ border-color:{GREEN}; background:#26372b; }}
QPushButton#Primary {{ color:#071006; background:{GREEN}; border-color:{GREEN}; font-weight:850; padding:11px 18px; }}
QPushButton#Nav {{ text-align:left; background:transparent; border:0; color:#bac3b8; padding:11px 14px; }}
QPushButton#Nav:checked {{ color:{GREEN}; background:#152219; border-left:3px solid {GREEN}; }}
QLineEdit,QComboBox,QTextEdit,QTableWidget {{ background:#0c110e; border:1px solid {BORDER}; border-radius:7px; padding:7px; selection-background-color:#3ba525; }}
QLineEdit:focus,QComboBox:focus,QTextEdit:focus,QTableWidget:focus {{ border-color:{GREEN}; }}
QHeaderView::section {{ background:#121a15; color:#bac3b8; border:0; border-bottom:1px solid {BORDER}; padding:8px; font-weight:700; }}
QTableWidget {{ gridline-color:#202b24; }}
QProgressBar {{ background:#0b100d; border:1px solid {BORDER}; border-radius:7px; height:13px; text-align:center; }}
QProgressBar::chunk {{ background:{GREEN}; border-radius:6px; }} QScrollArea {{ border:0; }}
"""


def label(text, name=""):
    w = QLabel(text); w.setObjectName(name); w.setWordWrap(True); return w


def card(title, subtitle=""):
    box = QFrame(); box.setObjectName("Card")
    lay = QVBoxLayout(box); lay.setContentsMargins(18, 16, 18, 16); lay.setSpacing(9)
    lay.addWidget(label(title, "CardTitle"))
    if subtitle: lay.addWidget(label(subtitle, "Muted"))
    return box, lay


class Bridge(QObject):
    done = Signal(str, object, object)


class FitScrollArea(QScrollArea):
    """Fill the viewport until the page reaches its genuine minimum height."""
    def __init__(self):
        super().__init__(); self.setWidgetResizable(False); self.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
    def setWidget(self, widget):
        super().setWidget(widget); self._fit_widget()
    def resizeEvent(self, event):
        super().resizeEvent(event); self._fit_widget()
    def _fit_widget(self):
        widget=self.widget()
        if widget: widget.resize(self.viewport().width(),max(self.viewport().height(),widget.minimumSizeHint().height()))


class Window(QMainWindow):
    def __init__(self):
        super().__init__(); self.engine = sybu_core.Engine(); self.bridge = Bridge()
        self.bridge.done.connect(self._task_done); self.pending = {}; self.poll_seen = {}
        self.setWindowTitle(f"SMACK YOUR BATCH UP — {BUILD_VERSION}")
        icon = os.path.join(getattr(sys, "_MEIPASS", os.path.dirname(__file__)), "assets", "sybu-taskbar.ico")
        if os.path.isfile(icon): self.setWindowIcon(QIcon(icon))
        self.resize(1420, 880); self.setMinimumSize(1040, 700); self._build(); self._load()
        self.timer = QTimer(self); self.timer.timeout.connect(self._poll); self.timer.start(250)

    def _build(self):
        root = QWidget(); shell = QHBoxLayout(root); shell.setContentsMargins(0,0,0,0); shell.setSpacing(0)
        side = QFrame(); side.setObjectName("Sidebar"); side.setFixedWidth(232)
        sl = QVBoxLayout(side); sl.setContentsMargins(18,24,18,18); sl.setSpacing(4)
        sl.addWidget(label("SNAPSMACK", "Eyebrow")); sl.addWidget(label("SMACK YOUR\nBATCH UP", "Brand")); sl.addSpacing(24)
        self.pages = QStackedWidget(); self.nav=[]
        for i,(name,tip,page) in enumerate((("Post","Build and publish",self._post_page()),("Queue","Review every image",self._queue_page()),("Connection","Site and services",self._settings_page()))):
            b=QPushButton(f"{name}\n{tip}"); b.setObjectName("Nav"); b.setCheckable(True); b.clicked.connect(lambda _=False,n=i:self._show(n)); sl.addWidget(b); self.nav.append(b); self.pages.addWidget(page)
        self.nav[0].setChecked(True); sl.addStretch(1); sl.addWidget(label(f"BUILD {BUILD_VERSION}\nBatch posting without bullshit", "Muted")); shell.addWidget(side)
        main=QWidget(); ml=QVBoxLayout(main); ml.setContentsMargins(0,0,0,0); ml.setSpacing(0)
        head=QFrame(); head.setObjectName("Header"); hl=QHBoxLayout(head); hl.setContentsMargins(24,13,24,13)
        hl.addWidget(label("SITE","Eyebrow")); self.profile=QComboBox(); self.profile.setMinimumWidth(300); self.profile.currentTextChanged.connect(self._profile); hl.addWidget(self.profile); hl.addStretch(1)
        self.status=label("● Ready to connect","Warn"); self.status.setWordWrap(False); hl.addWidget(self.status)
        self.connect_btn=QPushButton("Connect"); self.connect_btn.clicked.connect(self._connect); hl.addWidget(self.connect_btn)
        ml.addWidget(head); ml.addWidget(self.pages,1); shell.addWidget(main,1); self.setCentralWidget(root)

    def _page(self,title,sub):
        s=FitScrollArea(); host=QWidget(); host.setSizePolicy(QSizePolicy.Expanding,QSizePolicy.Ignored); l=QVBoxLayout(host); l.setContentsMargins(28,25,28,28); l.setSpacing(16)
        l.addWidget(label(title,"PageTitle")); l.addWidget(label(sub,"Muted")); s.setWidget(host); return s,l

    def _post_page(self):
        page,l=self._page("Batch posting cockpit","Choose the source, enrich what needs words, then send the selected images to the connected site.")
        source,cl=card("1 · Choose your images","Scan a folder directly or pair it with an existing manifest.")
        r=QHBoxLayout(); self.folder=QLineEdit(); self.folder.setPlaceholderText("Image folder…"); r.addWidget(self.folder,1); b=QPushButton("Choose folder"); b.clicked.connect(self._choose_folder); r.addWidget(b); self.manifest=QLineEdit(); self.manifest.setPlaceholderText("Optional manifest .txt…"); r.addWidget(self.manifest,1); m=QPushButton("Choose manifest"); m.clicked.connect(self._choose_manifest); r.addWidget(m); cl.addLayout(r)
        r=QHBoxLayout(); self.cat=QComboBox(); self.cat.setEditable(True); self.cat.setPlaceholderText("Default category"); self.album=QComboBox(); self.album.setEditable(True); self.album.setPlaceholderText("Default album"); self.orient=QComboBox(); self.orient.addItems(["Auto","Landscape","Portrait","Square"]); r.addWidget(self.cat); r.addWidget(self.album); r.addWidget(self.orient); r.addStretch(1); scan=QPushButton("LOAD QUEUE"); scan.setObjectName("Primary"); scan.clicked.connect(self._scan); r.addWidget(scan); cl.addLayout(r); l.addWidget(source)
        ai,al=card("2 · Enrich","Generate titles, tags, captions and alt text for selected images. Existing work is preserved.")
        r=QHBoxLayout(); self.prompt=QLineEdit(); self.prompt.setPlaceholderText("Optional custom prompt…"); r.addWidget(self.prompt,1); enrich=QPushButton("ENRICH SELECTED"); enrich.clicked.connect(self._enrich); r.addWidget(enrich); al.addLayout(r); l.addWidget(ai)
        send,pl=card("3 · Publish","SOLO posts individual photographs. GRAM creates carousel posts. The site mode is checked before anything is sent.")
        r=QHBoxLayout(); self.drive=QCheckBox("Attach Google Drive originals"); r.addWidget(self.drive); r.addStretch(1); validate=QPushButton("Validate"); validate.clicked.connect(self._validate); r.addWidget(validate); solo=QPushButton("POST SOLO"); solo.clicked.connect(lambda:self._post(False)); r.addWidget(solo); gram=QPushButton("POST GRAM"); gram.setObjectName("Primary"); gram.clicked.connect(lambda:self._post(True)); r.addWidget(gram); pl.addLayout(r)
        self.progress=QProgressBar(); self.progress.setRange(0,100); pl.addWidget(self.progress); self.progress_text=label("Ready when you are.","Muted"); pl.addWidget(self.progress_text); l.addWidget(send)
        act,aa=card("Activity"); self.activity_card=act; self.log=QTextEdit(); self.log.setReadOnly(True); self.log.setMinimumHeight(72); aa.addWidget(self.log); l.addWidget(act,1); return page

    def _queue_page(self):
        page,l=self._page("Your posting queue","Edit the fields that matter. Selection, enrichment and posting all operate on this table.")
        tools=QHBoxLayout(); allb=QPushButton("Select all"); allb.clicked.connect(lambda:self._select_all(True)); tools.addWidget(allb); none=QPushButton("Select none"); none.clicked.connect(lambda:self._select_all(False)); tools.addWidget(none); tools.addStretch(1); self.queue_count=label("0 images","Muted"); tools.addWidget(self.queue_count); l.addLayout(tools)
        self.table=QTableWidget(0,7); self.table.setHorizontalHeaderLabels(["USE","FILE","TITLE","TAGS","CATEGORY","ALBUM","STATUS"]); self.table.verticalHeader().setVisible(False); self.table.setAlternatingRowColors(True); self.table.horizontalHeader().setSectionResizeMode(QHeaderView.ResizeToContents); self.table.horizontalHeader().setSectionResizeMode(2,QHeaderView.Stretch); self.table.horizontalHeader().setSectionResizeMode(3,QHeaderView.Stretch); self.table.setMinimumHeight(500); l.addWidget(self.table,1); return page

    def _settings_page(self):
        page,l=self._page("Connection and services","Profiles come from SNAP HQ's shared library. Secrets stay in the protected shared store.")
        site,sl=card("Selected site")
        self.url=QLineEdit(); self.url.setPlaceholderText("https://your-site.example"); self.key=QLineEdit(); self.key.setEchoMode(QLineEdit.Password); self.key.setPlaceholderText("Scoped API key"); sl.addWidget(self.url); sl.addWidget(self.key); l.addWidget(site)
        svc,vl=card("AI and Google Drive")
        self.gemini=QLineEdit(); self.gemini.setEchoMode(QLineEdit.Password); self.gemini.setPlaceholderText("Gemini API key"); self.gcreds=QLineEdit(); self.gcreds.setPlaceholderText("Google credentials JSON"); self.drive_folder=QLineEdit(); self.drive_folder.setPlaceholderText("Google Drive folder ID"); vl.addWidget(self.gemini); vl.addWidget(self.gcreds); vl.addWidget(self.drive_folder)
        row=QHBoxLayout(); choose=QPushButton("Choose credentials"); choose.clicked.connect(self._choose_creds); row.addWidget(choose)
        drive_auth=QPushButton("Connect Drive"); drive_auth.clicked.connect(self._auth_drive); row.addWidget(drive_auth)
        gem_test=QPushButton("Test Gemini"); gem_test.clicked.connect(self._test_gemini); row.addWidget(gem_test)
        row.addStretch(1); save=QPushButton("SAVE SETTINGS"); save.setObjectName("Primary"); save.clicked.connect(self._save); row.addWidget(save); vl.addLayout(row); l.addWidget(svc); l.addStretch(1); return page

    def _show(self,n):
        self.pages.setCurrentIndex(n)
        for i,b in enumerate(self.nav): b.setChecked(i==n)

    def _load(self):
        names=self.engine.profiles_list(); self.profile.blockSignals(True); self.profile.clear(); self.profile.addItems(names); self.profile.blockSignals(False)
        c=self.engine.config_fields(); self.url.setText(c['url']); self.key.setText(c['api_key']); self.folder.setText(c['last_image_folder']); self.manifest.setText(c['last_manifest_file']); self.gemini.setText(c['gemini_api_key']); self.gcreds.setText(c['google_credentials']); self.drive_folder.setText(c['drive_folder_id']); self.drive.setChecked(c['drive_enabled']); self.prompt.setText(c['gemini_last_prompt'])
        if names: self._profile(names[0])

    def _profile(self,name):
        if not name:return
        p=self.engine.profile_apply_to_post(name); self.url.setText(p['url']); self.key.setText(p['api_key']); self.gemini.setText(p['gemini_api_key']); self.gcreds.setText(p['google_credentials']); self.drive_folder.setText(p['drive_folder_id']); self.folder.setText(p['image_folder']); self.prompt.setText(p['prompt']); self.drive.setChecked(p['drive_enabled']); self._connect()

    def _async(self,name,fn):
        self.pending[name]=True
        def go():
            try:self.bridge.done.emit(name,fn(),None)
            except Exception as e:self.bridge.done.emit(name,None,e)
        threading.Thread(target=go,daemon=True).start()

    def _connect(self):
        self.status.setText("● Connecting…"); self.status.setObjectName("Warn"); self.status.style().unpolish(self.status); self.status.style().polish(self.status)
        self._async("connect",lambda:self.engine.connect(self.url.text(),self.key.text(),True))

    def _task_done(self,name,result,error):
        self.pending.pop(name,None)
        if error: self.status.setText("● Needs attention"); self._error(str(error)); return
        if name=="connect":
            if result.get('needs_insecure_ack'):
                if QMessageBox.warning(self,"Insecure connection",result['reason'],QMessageBox.Ok|QMessageBox.Cancel)==QMessageBox.Ok: self._async("connect",lambda:self.engine.connect(self.url.text(),self.key.text(),True,True))
                return
            self.status.setText("● Connected · ready to post"); self.status.setObjectName("Good"); self.status.style().unpolish(self.status); self.status.style().polish(self.status); self.cat.clear(); self.cat.addItems(result['categories']); self.cat.setEditable(True); self.album.clear(); self.album.addItems(result['albums']); self.album.setEditable(True); self._say(f"Connected to {result['base_url']} · {result['site_mode'] or 'site mode unknown'}")
        elif name in ("scan","manifest"):
            self._fill_queue(result); self._show(1); self._say(f"Loaded {result['count']} images.")

    def _choose_folder(self):
        p=QFileDialog.getExistingDirectory(self,"Choose image folder",self.folder.text());
        if p:self.folder.setText(p)
    def _choose_manifest(self):
        p,_=QFileDialog.getOpenFileName(self,"Choose manifest",self.folder.text(),"Text manifest (*.txt);;All files (*)");
        if p:self.manifest.setText(p)
    def _choose_creds(self):
        p,_=QFileDialog.getOpenFileName(self,"Choose Google credentials","","JSON (*.json)");
        if p:self.gcreds.setText(p)

    def _scan(self):
        fn=(lambda:self.engine.load_manifest(self.manifest.text(),self.folder.text())) if self.manifest.text().strip() else (lambda:self.engine.scan_folder(self.folder.text(),self.cat.currentText(),self.album.currentText(),self.orient.currentText()))
        self._async("manifest" if self.manifest.text().strip() else "scan",fn)

    def _fill_queue(self,data=None):
        data=data or self.engine.serialize_queue(); rows=data['rows']; self.table.setRowCount(len(rows))
        for r,row in enumerate(rows):
            use=QTableWidgetItem(); use.setFlags(Qt.ItemIsEnabled|Qt.ItemIsUserCheckable); use.setCheckState(Qt.Checked if row['selected'] else Qt.Unchecked); self.table.setItem(r,0,use)
            for c,k in enumerate(("file","title","tags","category","album","status"),1): self.table.setItem(r,c,QTableWidgetItem(str(row.get(k,""))))
        self.queue_count.setText(f"{data['selected']} selected · {data['count']} images")

    def _sync_queue(self):
        for r in range(self.table.rowCount()):
            self.engine.set_selected(r,self.table.item(r,0).checkState()==Qt.Checked)
            self.engine.update_entry(r,{k:self.table.item(r,c).text() for c,k in ((2,'title'),(3,'tags'),(4,'category'),(5,'album'))})

    def _select_all(self,on):
        for r in range(self.table.rowCount()):self.table.item(r,0).setCheckState(Qt.Checked if on else Qt.Unchecked)
        self.engine.set_all_selected(on); self._fill_queue()

    def _enrich(self):
        try:self._sync_queue(); self.engine.enrich_start(self.gemini.text(),self.prompt.text()); self.poll_seen['enrich']=0; self.progress_text.setText("Enriching selected images…"); self._show(0)
        except Exception as e:self._error(str(e))

    def _validate(self):
        try:
            self._sync_queue(); out=self.engine.validate(self.cat.currentText(),self.album.currentText()); QMessageBox.information(self,"Queue validation","Ready to post." if out['ok'] else "\n".join(out['issues'][:20]))
        except Exception as e:self._error(str(e))

    def _post(self,grams):
        try:
            self._sync_queue(); pf=self.engine.post_preflight(grams,self.drive.isChecked())
            if QMessageBox.question(self,"Confirm publish",f"Post {pf['count']} selected image(s) to {pf['dest']} as {'GRAM' if grams else 'SOLO'}?",QMessageBox.Yes|QMessageBox.No)!=QMessageBox.Yes:return
            out=self.engine.post_start(grams,self.cat.currentText(),self.album.currentText(),self.orient.currentText(),"",self.engine.config.get('copyright_text',''),self.drive_folder.text(),ack_no_drive=True,ack_unknown_mode=True,drive_enabled=self.drive.isChecked())
            if out.get('needs_ack'): self._error("Google Drive is not connected."); return
            self.poll_seen['post']=0; self.progress_text.setText("Publishing…")
        except Exception as e:self._error(str(e))

    def _auth_drive(self):
        try:
            self.engine.drive_toggle(True); self.engine.auth_drive(self.gcreds.text()); self.poll_seen['drive_auth']=0; self._say("Connecting Google Drive…")
        except Exception as e:self._error(str(e))

    def _test_gemini(self):
        try:self.engine.gemini_test(self.gemini.text()); self.poll_seen['gemini_test']=0; self._say("Testing Gemini…")
        except Exception as e:self._error(str(e))

    def _poll(self):
        for key in ('enrich','post','drive_auth','gemini_test'):
            if key not in self.poll_seen:continue
            try:out=self.engine.op_poll(key,self.poll_seen[key])
            except Exception:continue
            self.poll_seen[key]=out.get('total_seen',self.poll_seen[key])
            for ev in out.get('events',[]):
                cur,total=ev.get('current',0),ev.get('total',1); self.progress.setValue(int(cur*100/max(1,total))); self._say(ev.get('message') or ev.get('file') or f"{key.title()} {cur}/{total}")
            if not out.get('running',False):
                self.poll_seen.pop(key,None); self.progress.setValue(100); self.progress_text.setText(f"{key.replace('_',' ').title()} complete." if not out.get('error') else f"{key.replace('_',' ').title()} failed."); self._fill_queue()
                result=out.get('result') or {}
                if result.get('message'):self._say(result['message'])
                if key=='drive_auth' and not out.get('error'):self._say("Google Drive connected.")
                if out.get('error'):self._error(out['error'])

    def _save(self):
        self.engine.save_config({'url':self.url.text(),'api_key':self.key.text(),'last_image_folder':self.folder.text(),'last_manifest_file':self.manifest.text(),'google_credentials':self.gcreds.text(),'drive_folder_id':self.drive_folder.text(),'gemini_api_key':self.gemini.text(),'gemini_last_prompt':self.prompt.text()}); self.engine.drive_toggle(self.drive.isChecked()); self._say("Settings saved to the shared store.")
    def _say(self,text): self.log.append(str(text))
    def _error(self,text): QMessageBox.critical(self,"SYBU needs attention",text); self._say("ERROR · "+text)


def run():
    app=QApplication.instance() or QApplication(sys.argv); app.setStyle("Fusion"); app.setStyleSheet(STYLE); app.setApplicationName("SMACK YOUR BATCH UP"); w=Window(); w.show(); return app.exec()

# ===== SNAPSMACK EOF =====
