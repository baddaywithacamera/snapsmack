"""SMACK YOUR BATCH UP — responsive Qt desktop shell over the proven engine."""

from __future__ import annotations

import base64
import os
import sys
import threading

from PySide6.QtCore import QObject, QPoint, QRect, QSize, Qt, QTimer, Signal
from PySide6.QtGui import QIcon, QPixmap
from PySide6.QtWidgets import (QAbstractItemView, QApplication, QCheckBox, QLayout, QSpacerItem,
    QComboBox, QDialog, QFileDialog, QFrame, QHBoxLayout, QHeaderView, QLabel,
    QLineEdit, QMainWindow, QMessageBox, QProgressBar, QPushButton, QScrollArea,
    QSizePolicy, QStackedWidget, QTableWidget, QTableWidgetItem, QTextEdit,
    QPlainTextEdit, QStyledItemDelegate, QVBoxLayout, QWidget)

import sybu_core

# Kept explicit so the Qt shell never imports the legacy Tk entry point (which
# redirects stdout/stderr and initializes Tk-only services at import time).
# One version, one source: main.py carries BUILD_VERSION (build.bat + the
# desktop floor read it there). The Qt window must never carry its own copy —
# it drifted to 0.7.66 while main.py said 0.7.67.
import re as _re
with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "main.py"), encoding="utf-8") as _fh:
    BUILD_VERSION = (_re.search(r'^BUILD_VERSION\s*=\s*"([^"]+)"', _fh.read(), _re.M) or [None, "0.0.0"])[1]

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
QPushButton {{ background:#202c24; border:1px solid #34463a; border-radius:8px; padding:9px 14px; font-weight:650; min-height:18px; }}
QPushButton:hover {{ border-color:{GREEN}; background:#26372b; }}
QPushButton#Primary {{ color:#071006; background:{GREEN}; border-color:{GREEN}; font-weight:850; padding:11px 18px; }}
QPushButton#Nav {{ text-align:left; background:transparent; border:0; color:#bac3b8; padding:11px 14px; }}
QPushButton#Nav:checked {{ color:{GREEN}; background:#152219; border-left:3px solid {GREEN}; }}
QLineEdit,QComboBox,QTextEdit,QTableWidget {{ background:#0c110e; border:1px solid {BORDER}; border-radius:7px; padding:7px; selection-background-color:#3ba525; }}
QLineEdit,QComboBox {{ min-height:20px; }}
QLineEdit:focus,QComboBox:focus,QTextEdit:focus,QTableWidget:focus {{ border-color:{GREEN}; }}
QHeaderView::section {{ background:#121a15; color:#bac3b8; border:0; border-bottom:1px solid {BORDER}; padding:8px; font-weight:700; }}
QTableWidget {{ background:{BASE}; border:0; gridline-color:transparent; alternate-background-color:#111a14; outline:0; }}
QTableWidget::item {{ border-bottom:1px solid {BORDER}; padding:9px 7px; }}
QTableWidget::item:selected {{ background:#253a2b; color:{INK}; }}
QTableWidget QComboBox {{ margin:0; padding:4px 7px; min-height:24px; }}
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
        # Never hand the page less height than its controls need: a short window
        # scrolls instead of squashing buttons to half height (Sean, 2026-09-15).
        if widget: widget.resize(self.viewport().width(),max(self.viewport().height(),widget.sizeHint().height(),widget.minimumSizeHint().height()))


class FlowLayout(QLayout):
    """Buttons wrap to the next line instead of shrinking and clipping their text
    (queue toolbar at ~1400 px showed "ICH SELEC", "eview prompt")."""
    def __init__(self, parent=None, spacing=8):
        super().__init__(parent); self._items = []; self.setSpacing(spacing); self.setContentsMargins(0, 0, 0, 0)
    def addItem(self, item): self._items.append(item)
    def addStretch(self, n=1): self.addItem(QSpacerItem(0, 0, QSizePolicy.Expanding, QSizePolicy.Minimum))
    def count(self): return len(self._items)
    def itemAt(self, i): return self._items[i] if 0 <= i < len(self._items) else None
    def takeAt(self, i): return self._items.pop(i) if 0 <= i < len(self._items) else None
    def expandingDirections(self): return Qt.Orientation(0)
    def hasHeightForWidth(self): return True
    def heightForWidth(self, w): return self._arrange(QRect(0, 0, w, 0), True)
    def setGeometry(self, r): super().setGeometry(r); self._arrange(r, False)
    def sizeHint(self): return self.minimumSize()
    def minimumSize(self):
        s = QSize()
        for it in self._items:
            if not it.isEmpty(): s = s.expandedTo(it.minimumSize())
        return s
    def _arrange(self, r, test):
        sp = self.spacing()
        # hidden widgets (the POST SOLO/GRAM pair once the blog's mode is known) take no room
        items = [it for it in self._items if it.spacerItem() is not None or not it.isEmpty()]
        split = next((k for k, it in enumerate(items) if it.spacerItem() is not None), None)
        left = items if split is None else items[:split]
        right = [] if split is None else items[split + 1:]
        x, y, row_h = r.x(), r.y(), 0
        for it in left:
            w = it.sizeHint().width(); h = it.sizeHint().height()
            if x + w > r.right() + 1 and row_h > 0:
                x = r.x(); y += row_h + sp; row_h = 0
            if not test: it.setGeometry(QRect(QPoint(x, y), it.sizeHint()))
            x += w + sp; row_h = max(row_h, h)
        if right:
            need = sum(it.sizeHint().width() for it in right) + sp * (len(right) - 1)
            if x + need > r.right() + 1 and row_h > 0:
                y += row_h + sp; row_h = 0
            rx = r.right() + 1 - need
            for it in right:
                w = it.sizeHint().width(); h = it.sizeHint().height()
                if not test: it.setGeometry(QRect(QPoint(rx, y), it.sizeHint()))
                rx += w + sp; row_h = max(row_h, h)
        return y + row_h - r.y()


class WordsDelegate(QStyledItemDelegate):
    """Title / caption / alt / tags edit in a wrapping box the size of the cell, not a
    one-line strip. Enter commits; Shift+Enter makes a new line; Esc cancels."""
    def createEditor(self, parent, option, index):
        box = QPlainTextEdit(parent); box.setFrameShape(QFrame.NoFrame); box.setTabChangesFocus(True)
        box.setVerticalScrollBarPolicy(Qt.ScrollBarAsNeeded)
        box.installEventFilter(self); return box
    def setEditorData(self, editor, index):
        editor.setPlainText(str(index.data(Qt.EditRole) or "")); editor.selectAll()
    def setModelData(self, editor, model, index):
        model.setData(index, editor.toPlainText().strip(), Qt.EditRole)
    def updateEditorGeometry(self, editor, option, index):
        r = option.rect; editor.setGeometry(r.x(), r.y(), r.width(), max(r.height(), 90))
    def eventFilter(self, obj, ev):
        if isinstance(obj, QPlainTextEdit) and ev.type() == ev.Type.KeyPress and ev.key() in (Qt.Key_Return, Qt.Key_Enter) and not (ev.modifiers() & Qt.ShiftModifier):
            self.commitData.emit(obj); self.closeEditor.emit(obj, QStyledItemDelegate.EndEditHint.NoHint); return True
        return super().eventFilter(obj, ev)


class QueueTable(QTableWidget):
    """Drag a row onto another to change posting order. Qt's own InternalMove would
    scramble the cell widgets (previews, dropdowns), so the drop is reported as
    (from, to) and the engine does the move; the table is redrawn from the engine."""
    moved = Signal(int, int)

    def __init__(self, *a):
        super().__init__(*a)
        self.setDragEnabled(True); self.setAcceptDrops(True); self.viewport().setAcceptDrops(True)
        self.setDragDropMode(QAbstractItemView.InternalMove); self.setDropIndicatorShown(True)
        self.setDragDropOverwriteMode(False)

    def dropEvent(self, event):
        src = self.currentRow()
        pos = event.position().toPoint() if hasattr(event, "position") else event.pos()
        dst = self.rowAt(pos.y())
        if dst < 0: dst = self.rowCount() - 1
        event.setDropAction(Qt.IgnoreAction); event.accept()
        if src >= 0 and dst >= 0 and src != dst:
            self.moved.emit(src, dst)


class Window(QMainWindow):
    def __init__(self):
        super().__init__(); self.engine = sybu_core.Engine(); self.bridge = Bridge(); self._gemini_manually_edited = False
        self.bridge.done.connect(self._task_done); self.pending = {}; self.poll_seen = {}
        self.setWindowTitle(f"SMACK YOUR BATCH UP — {BUILD_VERSION}")
        icon = os.path.join(getattr(sys, "_MEIPASS", os.path.dirname(__file__)), "assets", "sybu-taskbar.ico")
        if os.path.isfile(icon): self.setWindowIcon(QIcon(icon))
        self.resize(1420, 880); self.setMinimumSize(1040, 700); self._build(); self._apply_site_mode(''); self._load()
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
        # Progress strip: under every page, so a post started from the queue is
        # seen from the queue. Bar + words + STOP. (Was inside the Post page only —
        # POST GRAM from the queue "did nothing" while 8 images went up.)
        strip=QFrame(); strip.setObjectName("Header"); stl=QHBoxLayout(strip); stl.setContentsMargins(24,10,24,10); stl.setSpacing(14)
        self.progress=QProgressBar(); self.progress.setRange(0,100); self.progress.setFixedWidth(260); self.progress.setTextVisible(False); stl.addWidget(self.progress)
        self.progress_text=label("Ready when you are.","Muted"); self.progress_text.setWordWrap(False); stl.addWidget(self.progress_text,1)
        self.stop_btn=QPushButton("STOP"); self.stop_btn.setToolTip("Stop the running post or enrichment after the current image."); self.stop_btn.clicked.connect(self._stop); self.stop_btn.setEnabled(False); stl.addWidget(self.stop_btn)
        ml.addWidget(head); ml.addWidget(self.pages,1); ml.addWidget(strip); shell.addWidget(main,1); self.setCentralWidget(root)

    def _page(self,title,sub):
        s=FitScrollArea(); host=QWidget(); host.setSizePolicy(QSizePolicy.Expanding,QSizePolicy.Ignored); l=QVBoxLayout(host); l.setContentsMargins(28,25,28,28); l.setSpacing(16)
        l.addWidget(label(title,"PageTitle")); l.addWidget(label(sub,"Muted")); s.setWidget(host); return s,l

    def _post_page(self):
        page,l=self._page("Batch posting cockpit","Choose the source, enrich what needs words, then send the selected images to the connected site.")
        source,cl=card("1 · Choose your images","Scan a folder directly or pair it with an existing manifest.")
        r=QHBoxLayout(); self.folder=QLineEdit(); self.folder.setPlaceholderText("Image folder…"); r.addWidget(self.folder,1); b=QPushButton("Choose folder"); b.clicked.connect(self._choose_folder); r.addWidget(b); self.manifest=QLineEdit(); self.manifest.setPlaceholderText("Optional manifest .txt…"); r.addWidget(self.manifest,1); m=QPushButton("Choose manifest"); m.clicked.connect(self._choose_manifest); r.addWidget(m); cl.addLayout(r)
        r=QHBoxLayout(); self.cat=QComboBox(); self.cat.setEditable(True); self.cat.setPlaceholderText("Default category"); self.album=QComboBox(); self.album.setEditable(True); self.album.setPlaceholderText("Default album"); self.orient=QComboBox(); self.orient.addItems(["Auto","Landscape","Portrait","Square"]); r.addWidget(self.cat); r.addWidget(self.album); r.addWidget(self.orient); r.addStretch(1); scan=QPushButton("LOAD QUEUE"); scan.setObjectName("Primary"); scan.clicked.connect(self._scan); r.addWidget(scan); cl.addLayout(r); l.addWidget(source)
        ai,al=card("2 · Enrich","Generate titles, tags, captions and alt text for selected images. Existing work is preserved.")
        r=QHBoxLayout(); self.prompt=QLineEdit(); self.prompt.setReadOnly(True); self.prompt.setPlaceholderText("Built-in enrichment prompt"); r.addWidget(self.prompt,1); review=QPushButton("REVIEW PROMPT…"); review.clicked.connect(self._review_prompt); r.addWidget(review); enrich=QPushButton("ENRICH SELECTED"); enrich.clicked.connect(self._enrich); r.addWidget(enrich); al.addLayout(r); l.addWidget(ai)
        send,pl=card("3 · Publish","SOLO posts individual photographs. GRAM creates carousel posts. The site mode is checked before anything is sent.")
        r=QHBoxLayout(); self.drive=QCheckBox("Attach Google Drive originals"); r.addWidget(self.drive); r.addStretch(1); validate=QPushButton("Validate"); validate.clicked.connect(self._validate); r.addWidget(validate); self.post_btn=QPushButton("POST"); self.post_btn.setObjectName("Primary"); self.post_btn.clicked.connect(lambda:self._post(None)); r.addWidget(self.post_btn); self.post_solo=QPushButton("POST SOLO"); self.post_solo.clicked.connect(lambda:self._post(False)); r.addWidget(self.post_solo); self.post_gram=QPushButton("POST GRAM"); self.post_gram.clicked.connect(lambda:self._post(True)); r.addWidget(self.post_gram); pl.addLayout(r)
        l.addWidget(send)
        act,aa=card("Activity"); self.activity_card=act; self.log=QTextEdit(); self.log.setReadOnly(True); self.log.setMinimumHeight(72); aa.addWidget(self.log); l.addWidget(act,1); return page

    def _queue_page(self):
        page,l=self._page("Your posting queue","Edit the fields that matter. Selection, enrichment and posting all operate on this table.")
        tools=FlowLayout(); allb=QPushButton("Select all"); allb.clicked.connect(lambda:self._select_all(True)); tools.addWidget(allb); none=QPushButton("Select none"); none.clicked.connect(lambda:self._select_all(False)); tools.addWidget(none); clear=QPushButton("Clear queue"); clear.clicked.connect(self._clear_queue); tools.addWidget(clear); up=QPushButton("▲ Move up"); up.setToolTip("Move the highlighted row up one. You can also drag a row."); up.clicked.connect(lambda:self._move_row(-1)); tools.addWidget(up); down=QPushButton("▼ Move down"); down.setToolTip("Move the highlighted row down one. You can also drag a row."); down.clicked.connect(lambda:self._move_row(1)); tools.addWidget(down); rnd=QPushButton("Randomize"); rnd.setToolTip("Shuffle the posting order."); rnd.clicked.connect(self._randomize); tools.addWidget(rnd); tools.addStretch(1); review=QPushButton("Review prompt…"); review.clicked.connect(self._review_prompt); tools.addWidget(review); enrich=QPushButton("ENRICH SELECTED"); enrich.setObjectName("Primary"); enrich.clicked.connect(self._enrich); tools.addWidget(enrich); self.qpost_btn=QPushButton("POST"); self.qpost_btn.clicked.connect(lambda:self._post(None)); tools.addWidget(self.qpost_btn); self.qpost_solo=QPushButton("POST SOLO"); self.qpost_solo.clicked.connect(lambda:self._post(False)); tools.addWidget(self.qpost_solo); self.qpost_gram=QPushButton("POST GRAM"); self.qpost_gram.clicked.connect(lambda:self._post(True)); tools.addWidget(self.qpost_gram); self.queue_count=label("0 images","Muted"); self.queue_count.setWordWrap(False); tools.addWidget(self.queue_count); l.addLayout(tools)
        self.table=QueueTable(0,12); self.table.moved.connect(self._reorder); self.table.setHorizontalHeaderLabels(["USE","PREVIEW","FILE","TITLE","CAPTION","ALT TEXT","TAGS","COLOUR / B&W","ORIENTATION","CATEGORY","ALBUM","STATUS"]); self.table.verticalHeader().setVisible(False); self.table.setAlternatingRowColors(True); self.table.setShowGrid(False); self.table.setSelectionBehavior(QAbstractItemView.SelectRows); self.table.setSelectionMode(QAbstractItemView.SingleSelection); self.table.setWordWrap(True); self.table.horizontalHeader().setHighlightSections(False); self.table.horizontalHeader().setSectionResizeMode(QHeaderView.ResizeToContents)
        # Text columns SHARE the width the window has; nothing dictates it. FILE
        # used to size to its longest filename and squeeze title/caption/alt to
        # nothing after an enrich. Rows grow to their wrapped text instead.
        for c in (2,3,4,5,6): self.table.horizontalHeader().setSectionResizeMode(c,QHeaderView.Stretch)
        # One click on a text cell opens it for editing (the fields were "only for
        # show" - editing needed a double-click nobody found). Enter commits. The
        # edited value is what ENRICH/POST read; a row's drag still starts from the
        # preview or file cell.
        self.table.setEditTriggers(QAbstractItemView.SelectedClicked|QAbstractItemView.DoubleClicked|QAbstractItemView.EditKeyPressed|QAbstractItemView.AnyKeyPressed)
        self.table.clicked.connect(lambda ix: self.table.edit(ix) if ix.column() in (3,4,5,6,9,10) else None)
        self._words=WordsDelegate(self.table)
        for c in (3,4,5,6): self.table.setItemDelegateForColumn(c,self._words)
        self.table.setTextElideMode(Qt.ElideMiddle); self.table.setMinimumHeight(500); l.addWidget(self.table,1)
        self._row_fit=QTimer(self); self._row_fit.setSingleShot(True); self._row_fit.setInterval(60); self._row_fit.timeout.connect(self._fit_rows)
        self.table.horizontalHeader().sectionResized.connect(lambda *_: self._row_fit.start())
        return page

    def _fit_rows(self):
        """Row height = wrapped text at the current column widths, never under the 118 px preview."""
        self.table.resizeRowsToContents()
        for r in range(self.table.rowCount()): self.table.setRowHeight(r,max(118,self.table.rowHeight(r)))

    def _settings_page(self):
        page,l=self._page("Connection and services","Profiles come from SNAP HQ's shared library. Secrets stay in the protected shared store.")
        site,sl=card("Selected site")
        self.url=QLineEdit(); self.url.setPlaceholderText("https://your-site.example"); self.key=QLineEdit(); self.key.setEchoMode(QLineEdit.Password); self.key.setPlaceholderText("Scoped API key"); sl.addWidget(self.url); sl.addWidget(self.key); l.addWidget(site)
        svc,vl=card("AI and Google Drive")
        self.gemini=QLineEdit(); self.gemini.setEchoMode(QLineEdit.Password); self.gemini.setPlaceholderText("Gemini API key"); self.gemini.textEdited.connect(lambda _text:setattr(self,'_gemini_manually_edited',True)); self.gcreds=QLineEdit(); self.gcreds.setPlaceholderText("Google credentials JSON"); self.drive_folder=QLineEdit(); self.drive_folder.setPlaceholderText("Google Drive folder ID"); vl.addWidget(self.gemini); vl.addWidget(self.gcreds); vl.addWidget(self.drive_folder)
        self.gemini_source=label("Gemini key source: SNAP HQ shared store", "Muted"); vl.addWidget(self.gemini_source)
        row=QHBoxLayout(); choose=QPushButton("Choose credentials"); choose.clicked.connect(self._choose_creds); row.addWidget(choose)
        drive_auth=QPushButton("Connect Drive"); drive_auth.clicked.connect(self._auth_drive); row.addWidget(drive_auth)
        gem_test=QPushButton("Test Gemini"); gem_test.clicked.connect(self._test_gemini); row.addWidget(gem_test)
        row.addStretch(1); save=QPushButton("SAVE SETTINGS"); save.setObjectName("Primary"); save.clicked.connect(self._save); row.addWidget(save); vl.addLayout(row); l.addWidget(svc); l.addStretch(1); return page

    def _show(self,n):
        self.pages.setCurrentIndex(n)
        for i,b in enumerate(self.nav): b.setChecked(i==n)
        # The queue is filled while its page is hidden (scan finishes on the Post
        # page), so the table had no real width and wrapped every caption one word
        # per line - 640 px rows. Re-fit once the page is actually on screen.
        if n==1 and hasattr(self,'_row_fit'): self._row_fit.start()

    def _load(self):
        names=self.engine.profiles_list(); self.profile.blockSignals(True); self.profile.clear(); self.profile.addItems(names); self.profile.blockSignals(False)
        c=self.engine.config_fields(); self.url.setText(c['url']); self.key.setText(c['api_key']); self.folder.setText(c['last_image_folder']); self.manifest.setText(c['last_manifest_file']); self.gemini.setText(c['gemini_api_key']); self._gemini_manually_edited=False; self.gcreds.setText(c['google_credentials']); self.drive_folder.setText(c['drive_folder_id']); self.drive.setChecked(c['drive_enabled']); self.prompt.setText(c['gemini_last_prompt'])
        if names: self._profile(names[0])

    def _profile(self,name):
        if not name:return
        p=self.engine.profile_apply_to_post(name); self.url.setText(p['url']); self.key.setText(p['api_key']); self.gemini.setText(p['gemini_api_key']); self._gemini_manually_edited=False; self.gcreds.setText(p['google_credentials']); self.drive_folder.setText(p['drive_folder_id']); self.folder.setText(p['image_folder']); self.prompt.setText(p['prompt']); self.drive.setChecked(p['drive_enabled']); self._connect()

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
            self._apply_site_mode(result.get('site_mode','')); self.status.setText("● Connected · ready to post"); self.status.setObjectName("Good"); self.status.style().unpolish(self.status); self.status.style().polish(self.status); self.cat.clear(); self.cat.addItems(result['categories']); self.cat.setEditable(True); self.album.clear(); self.album.addItems(result['albums']); self.album.setEditable(True); self._say(f"Connected to {result['base_url']} · {result['site_mode'] or 'site mode unknown'}")
        elif name in ("scan","manifest"):
            # Saved enrichment for this folder comes back on its own (the Tk window asked
            # first; the Qt window never called apply_resume at all, so a re-scan looked
            # like the work was gone). Nothing is re-sent to Gemini for restored rows.
            restored = 0
            try:
                if self.engine.recovery and self.engine.recovery.exists():
                    restored = self.engine.recovery.enriched_count_for(self.engine.entries)
                    if restored: result = self.engine.apply_resume()
            except Exception as e: self._say(f"Saved enrichment not restored: {e}")
            self._fill_queue(result); self._show(1)
            self._say(f"Loaded {result['count']} images." + (f" Restored saved enrichment for {restored} of them from the last run - no Gemini cost." if restored else ""))
            if restored: self.progress_text.setText(f"{restored} of {result['count']} images already enriched (restored from the last run).")

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
            preview=QLabel(); preview.setAlignment(Qt.AlignCenter)
            try:
                encoded=self.engine.thumb(r,108).partition(',')[2]
                pix=QPixmap(); pix.loadFromData(base64.b64decode(encoded)); preview.setPixmap(pix)
            except Exception:
                preview.setText("No preview")
            self.table.setCellWidget(r,1,preview); self.table.setRowHeight(r,118)
            for c,k in ((2,'file'),(3,'title'),(4,'caption'),(5,'alt'),(6,'tags'),(9,'category'),(10,'album'),(11,'status')):
                text=str(row.get(k,""))
                if k=='status': text={'pending':'pending','enriched':'enriched','posting':'posting…','ok':'POSTED','warning':'POSTED (no EXIF)','error':'ERROR'}.get(row.get('status',''),row.get('status',''))
                if k=='status' and row.get('status')=='error' and row.get('message'): text=f"ERROR: {row['message']}"
                item=QTableWidgetItem(text)
                if k in ('file','status'): item.setFlags(item.flags() & ~Qt.ItemIsEditable)
                self.table.setItem(r,c,item)
            colour=QComboBox(); colour.addItem("—",""); colour.addItem("Colour","color"); colour.addItem("B&W","bw"); colour.setFixedHeight(36)
            colour.setCurrentIndex(max(0,colour.findData(row.get('color_mode','')))); self.table.setCellWidget(r,7,self._centred_control(colour))
            orient=QComboBox()
            for text,value in (("Auto","auto"),("Landscape","0"),("Portrait","1"),("Square","2")): orient.addItem(text,value)
            orient.setFixedHeight(36); orient.setCurrentIndex(max(0,orient.findData(row.get('orientation','auto')))); self.table.setCellWidget(r,8,self._centred_control(orient))
        self._fit_rows(); self._row_fit.start()
        self.queue_count.setText(f"{data['selected']} selected · {data['count']} images")

    def _centred_control(self, control):
        host=QWidget(); host._control=control; host.setAttribute(Qt.WA_TranslucentBackground); host.setStyleSheet("background:transparent")
        layout=QVBoxLayout(host); layout.setContentsMargins(3,3,3,3); layout.addStretch(1); layout.addWidget(control); layout.addStretch(1)
        return host

    def _sync_queue(self):
        for r in range(self.table.rowCount()):
            self.engine.set_selected(r,self.table.item(r,0).checkState()==Qt.Checked)
            patch={k:self.table.item(r,c).text() for c,k in ((3,'title'),(4,'caption'),(5,'alt'),(6,'tags'),(9,'category'),(10,'album'))}
            patch['color_mode']=self.table.cellWidget(r,7)._control.currentData() or ''
            patch['orientation']=self.table.cellWidget(r,8)._control.currentData() or 'auto'
            self.engine.update_entry(r,patch)

    def _review_prompt(self):
        dialog=QDialog(self); dialog.setWindowTitle("Review enrichment prompt"); dialog.resize(820,620)
        layout=QVBoxLayout(dialog)
        layout.addWidget(label("REVIEW THE PROMPT", "Eyebrow"))
        layout.addWidget(label("Use for this run leaves the shared site prompt unchanged. Save to SNAP HQ deliberately updates the selected site's shared profile.","Muted"))
        editor=QTextEdit(); editor.setPlainText(self.prompt.text()); editor.setPlaceholderText("Leave blank to use SYBU's complete built-in enrichment prompt."); layout.addWidget(editor,1)
        buttons=QHBoxLayout(); cancel=QPushButton("CANCEL"); cancel.clicked.connect(dialog.reject); buttons.addWidget(cancel); buttons.addStretch(1)
        use=QPushButton("USE FOR THIS RUN"); buttons.addWidget(use)
        save=QPushButton("SAVE TO SNAP HQ"); save.setObjectName("Primary"); buttons.addWidget(save); layout.addLayout(buttons)
        def use_text(): self.prompt.setText(editor.toPlainText().strip()); dialog.accept()
        def save_text():
            try:
                result=self.engine.profile_save_prompt(self.profile.currentText(),editor.toPlainText()); self.prompt.setText(result['prompt']); self._say(f"Prompt saved to SNAP HQ for {result['name']}."); dialog.accept()
            except Exception as error: self._error(str(error))
        use.clicked.connect(use_text); save.clicked.connect(save_text); dialog.exec()

    def _select_all(self,on):
        for r in range(self.table.rowCount()):self.table.item(r,0).setCheckState(Qt.Checked if on else Qt.Unchecked)
        self.engine.set_all_selected(on); self._fill_queue()

    def _reorder(self,src,dst):
        try:
            self._sync_queue(); self._fill_queue(self.engine.reorder(src,dst)); self.table.selectRow(dst); self._say(f"Moved image {src+1} to position {dst+1}.")
        except Exception as e:self._error(str(e))

    def _move_row(self,step):
        r=self.table.currentRow()
        if r<0: self._say("Highlight a row first."); return
        dst=r+step
        if 0<=dst<self.table.rowCount(): self._reorder(r,dst)

    def _randomize(self):
        if self.table.rowCount()<2: return
        try:
            self._sync_queue(); self._fill_queue(self.engine.shuffle()); self._say("Posting order randomized.")
        except Exception as e:self._error(str(e))

    def _clear_queue(self):
        if not self.table.rowCount(): return
        if QMessageBox.question(self,"Clear posting queue","Remove the images from this posting queue?\n\nThe image files themselves will not be deleted. Failed rows are retained so an upload problem cannot be lost.",QMessageBox.Yes|QMessageBox.Cancel,QMessageBox.Cancel)!=QMessageBox.Yes:return
        self._fill_queue(self.engine.clear_queue()); self._say("Posting queue cleared.")

    def _enrich(self):
        try:
            self._sync_queue(); self.engine.enrich_start(self.gemini.text(),self.prompt.text()); self.poll_seen['enrich']=0; self.progress.setValue(0); self.stop_btn.setEnabled(True)
            # Stay on the queue: the rows themselves show progress (STATUS
            # column + counter) instead of a bar on another page.
            self._enrich_total=sum(1 for r in range(self.table.rowCount()) if self.table.item(r,0).checkState()==Qt.Checked); self._enrich_done=0
            for r in range(self.table.rowCount()):
                if self.table.item(r,0).checkState()==Qt.Checked and self.table.item(r,11): self.table.item(r,11).setText("enriching…")
            # One place for progress: the bar + its label at the bottom (Sean 2026-09-18).
            self.progress_text.setText(f"Enriching 0/{self._enrich_total}…")
        except Exception as e:self._error(str(e))

    def _validate(self):
        try:
            self._sync_queue(); out=self.engine.validate(self.cat.currentText(),self.album.currentText()); QMessageBox.information(self,"Queue validation","Ready to post." if out['ok'] else "\n".join(out['issues'][:20]))
        except Exception as e:self._error(str(e))

    def _apply_site_mode(self,mode):
        """The blog decides solo vs gram (photoblog → SOLO, carousel → GRAM). One POST
        button that does the right thing; the explicit pair only when the blog's mode
        is unknown (old build, or a mode SYBU has no posting shape for)."""
        self._site_grams={'photoblog':False,'carousel':True}.get((mode or '').strip().lower())
        known=self._site_grams is not None
        text="POST GRAM" if self._site_grams else "POST SOLO"
        for b in (self.post_btn,self.qpost_btn): b.setVisible(known); b.setText(text); b.setToolTip(f"This blog is {'GRAMOFSMACK' if self._site_grams else 'SMACKONEOUT'} — posting as {'gram' if self._site_grams else 'solo'}.")
        for b in (self.post_solo,self.post_gram,self.qpost_solo,self.qpost_gram): b.setVisible(not known)

    def _post(self,grams):
        if grams is None:
            grams=getattr(self,'_site_grams',None)
            if grams is None: self._error("Connect to the blog first — it decides solo or gram."); return
        try:
            self._sync_queue(); pf=self.engine.post_preflight(grams,self.drive.isChecked())
            if QMessageBox.question(self,"Confirm publish",f"Post {pf['count']} selected image(s) to {pf['dest']} as {'GRAM' if grams else 'SOLO'}?",QMessageBox.Yes|QMessageBox.No)!=QMessageBox.Yes:return
            out=self.engine.post_start(grams,self.cat.currentText(),self.album.currentText(),self.orient.currentText(),"",self.engine.config.get('copyright_text',''),self.drive_folder.text(),ack_no_drive=True,ack_unknown_mode=True,drive_enabled=self.drive.isChecked())
            if out.get('needs_ack'): self._error("Google Drive is not connected."); return
            self.poll_seen['post']=0; self.progress.setValue(0); self.progress_text.setText(f"Publishing {pf['count']} image(s) to {pf['dest']}…"); self.stop_btn.setEnabled(True); self._fill_queue()
        except Exception as e:self._error(str(e))

    def _auth_drive(self):
        try:
            self.engine.drive_toggle(True); self.engine.auth_drive(self.gcreds.text()); self.poll_seen['drive_auth']=0; self._say("Connecting Google Drive…")
        except Exception as e:self._error(str(e))

    def _test_gemini(self):
        try:
            services=self.engine.shared_service_fields()
            if self._gemini_manually_edited:
                key=self.gemini.text().strip(); source="typed key (not yet saved)"
            else:
                key=services['gemini_api_key']; source="current key from SNAP HQ"; self.gemini.setText(key)
            self.gcreds.setText(services['google_credentials']); self.drive_folder.setText(services['drive_folder_id'])
            self.gemini_source.setText(f"Testing the {source}…")
            self.engine.gemini_test(key); self.poll_seen['gemini_test']=0; self._say(f"Testing Gemini using the {source}…")
        except Exception as e:self._error(str(e))

    def _poll(self):
        for key in ('enrich','post','drive_auth','gemini_test'):
            if key not in self.poll_seen:continue
            try:out=self.engine.op_poll(key,self.poll_seen[key])
            except Exception:continue
            self.poll_seen[key]=out.get('total_seen',self.poll_seen[key])
            for ev in out.get('events',[]):
                cur,total=ev.get('current',0),ev.get('total',1); self.progress.setValue(int(cur*100/max(1,total))); self._say(ev.get('message') or ev.get('file') or f"{key.title()} {cur}/{total}")
                if key=='enrich' and ev.get('type')=='progress' and ev.get('index') is not None: self._enrich_row_event(ev)
                if key=='post' and ev.get('type')=='progress':
                    self.progress_text.setText(f"Posting {cur}/{total} — {ev.get('file','')}: {'sent' if ev.get('success') else 'FAILED'}"); self._post_row_event(ev)
            if not out.get('running',False):
                self.poll_seen.pop(key,None); self.progress.setValue(100); self.stop_btn.setEnabled(bool(self.poll_seen)); self._fill_queue()
                if key=='post':
                    q=self.engine.serialize_queue(); posted=sum(1 for r in q['rows'] if r.get('status') in ('ok','warning')); failed=q.get('failed',0)
                    self.progress_text.setText(f"Batch done — {posted} posted, {failed} FAILED (red rows; see the log)." if failed else f"Batch complete — {posted} posted to {self.engine.connection_state().get('base_url','the blog')}.")
                    self.queue_count.setText(f"{posted} posted · {q['count']} images")
                else:
                    self.progress_text.setText(f"{key.replace('_',' ').title()} complete." if not out.get('error') else f"{key.replace('_',' ').title()} failed.")
                result=out.get('result') or {}
                if result.get('message'):self._say(result['message'])
                if key=='gemini_test':
                    ok=bool(result.get('ok')) and not out.get('error'); message=result.get('message') or out.get('error') or "No response."
                    self.gemini_source.setText(("Gemini key accepted by Google: " if ok else "Gemini key rejected by Google: ")+message)
                if key=='drive_auth' and not out.get('error'):self._say("Google Drive connected.")
                if out.get('error'):self._error(out['error'])

    def _save(self):
        self.engine.save_config({'url':self.url.text(),'api_key':self.key.text(),'last_image_folder':self.folder.text(),'last_manifest_file':self.manifest.text(),'google_credentials':self.gcreds.text(),'drive_folder_id':self.drive_folder.text(),'gemini_api_key':self.gemini.text(),'gemini_last_prompt':self.prompt.text()}); self._gemini_manually_edited=False; self.engine.drive_toggle(self.drive.isChecked()); self.gemini_source.setText("Gemini key source: SNAP HQ shared store"); self._say("Settings saved to the shared store.")
    def _post_row_event(self,ev):
        r=ev.get('index')
        if r is None or r>=self.table.rowCount(): return
        item=self.table.item(r,11)
        if item is None: return
        item.setText("POSTED" if ev.get('success') else f"ERROR: {ev.get('message','')}")
        item.setForeground(Qt.GlobalColor.green if ev.get('success') else Qt.GlobalColor.red)

    def _stop(self):
        stopped=[]
        for key in list(self.poll_seen):
            try:
                out=self.engine.cancel_post() if key=='post' else self.engine.cancel_op(key)
                if out.get('cancelling'): stopped.append(key)
            except Exception as e:self._error(str(e))
        self.progress_text.setText("Stopping after the current image…" if stopped else "Nothing is running."); self.stop_btn.setEnabled(False)

    def _enrich_row_event(self,ev):
        r=int(ev['index'])
        if r<0 or r>=self.table.rowCount(): return
        row=ev.get('row') or {}
        if ev.get('ok'):
            for c,k in ((3,'title'),(4,'caption'),(5,'alt'),(6,'tags'),(9,'category'),(10,'album')):
                if self.table.item(r,c) is not None and row.get(k) is not None: self.table.item(r,c).setText(str(row.get(k,"")))
            if self.table.item(r,11): self.table.item(r,11).setText("enriched")
            self.table.resizeRowToContents(r); self.table.setRowHeight(r,max(118,self.table.rowHeight(r)))
        else:
            if self.table.item(r,11): self.table.item(r,11).setText(f"ERROR: {ev.get('message') or 'enrichment failed'}")
        self._enrich_done=getattr(self,'_enrich_done',0)+1
        total=max(1,getattr(self,'_enrich_total',ev.get('total',1)))
        self.progress.setValue(int(self._enrich_done*100/total))
        self.progress_text.setText(f"Enriching {self._enrich_done}/{total}…" if self._enrich_done<total else f"Enriched {total}/{total}.")

    def _say(self,text): self.log.append(str(text))
    def _error(self,text): QMessageBox.critical(self,"SYBU needs attention",text); self._say("ERROR · "+text)


def run():
    app=QApplication.instance() or QApplication(sys.argv); app.setStyle("Fusion"); app.setStyleSheet(STYLE); app.setApplicationName("SMACK YOUR BATCH UP"); w=Window(); w.show(); return app.exec()

# ===== SNAPSMACK EOF =====
