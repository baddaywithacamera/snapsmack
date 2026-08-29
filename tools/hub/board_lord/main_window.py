"""BOARD LORD main window — rail (filters + boards) | thread list | detail."""

from PySide6.QtCore import Qt
from PySide6.QtGui import QAction
from PySide6.QtWidgets import (
    QMainWindow, QWidget, QSplitter, QVBoxLayout, QHBoxLayout, QLabel, QComboBox,
    QPushButton, QScrollArea, QButtonGroup, QToolBar, QMessageBox, QPlainTextEdit,
    QSizePolicy,
)

from . import api
from .config import Config
from .worker import run_async
from .widgets import ThreadRow, ReplyCard, fmt_when
from .dialogs import SettingsDialog, NewBoardDialog, NewPostDialog, RolesDialog

SORTS = [("Active", "active"), ("Newest", "new"), ("Most viewed", "views"), ("Unread first", "unread")]
FLAG_FILTERS = [("All", ""), ("Support", "support"), ("Questions", "question"),
                ("Chat", "chat"), ("Brag", "brag")]
VIEW_FILTERS = [("All threads", ""), ("Unread only", "unread"), ("Following", "following")]


class BoardLordWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("BOARD LORD — SnapSmack Forum Console")
        self.resize(1200, 760)

        self.config = Config()
        self.client = api.ForumClient(self.config.base_url, self.config.key)
        self.categories = []
        self._rows = {}
        self._selected_id = None

        self.cat = 0
        self.flag = ""
        self.filt = ""
        self.sort = "active"

        self._build_toolbar()
        self._build_body()
        self.statusBar().showMessage("Ready.")

        if not self.config.is_configured:
            self.statusBar().showMessage("Not connected — open Settings and paste your key.")
            self._open_settings()
        else:
            self.refresh_all()

    # -- chrome --------------------------------------------------------------
    def _build_toolbar(self):
        bar = QToolBar()
        bar.setMovable(False)
        self.addToolBar(bar)

        def act(text, slot):
            a = QAction(text, self)
            a.triggered.connect(slot)
            bar.addAction(a)
            return a

        act("⟳  Refresh", self.refresh_all)
        bar.addSeparator()
        act("＋ New Post", self._new_post)
        act("＋ New Board", self._new_board)
        bar.addSeparator()
        act("Blogs & Roles", self._open_roles)
        spacer = QWidget()
        spacer.setSizePolicy(QSizePolicy.Expanding, QSizePolicy.Preferred)
        bar.addWidget(spacer)
        act("⚙  Settings", self._open_settings)

    def _build_body(self):
        split = QSplitter(Qt.Horizontal)
        split.addWidget(self._build_rail())
        split.addWidget(self._build_thread_pane())
        self.detail = DetailPanel(self._get_client, self.refresh_threads)
        split.addWidget(self.detail)
        split.setStretchFactor(0, 0)
        split.setStretchFactor(1, 1)
        split.setStretchFactor(2, 2)
        split.setSizes([240, 430, 530])
        self.setCentralWidget(split)

    def _build_rail(self):
        rail = QWidget()
        rail.setObjectName("Rail")
        rail.setFixedWidth(240)
        outer = QVBoxLayout(rail)
        outer.setContentsMargins(0, 6, 0, 10)
        outer.setSpacing(0)

        self.flag_group = QButtonGroup(self)
        self.flag_group.setExclusive(True)
        outer.addWidget(self._section("TRIAGE"))
        for label, value in FLAG_FILTERS:
            outer.addWidget(self._nav(self.flag_group, label, value, checked=(value == "")))
        self.flag_group.buttonClicked.connect(self._on_flag)

        self.view_group = QButtonGroup(self)
        self.view_group.setExclusive(True)
        outer.addWidget(self._section("VIEW"))
        for label, value in VIEW_FILTERS:
            outer.addWidget(self._nav(self.view_group, label, value, checked=(value == "")))
        self.view_group.buttonClicked.connect(self._on_view)

        self.board_group = QButtonGroup(self)
        self.board_group.setExclusive(True)
        outer.addWidget(self._section("BOARDS"))
        self.board_box = QVBoxLayout()
        self.board_box.setContentsMargins(0, 0, 0, 0)
        self.board_box.setSpacing(0)
        self._all_boards_btn = self._nav(self.board_group, "All boards", 0, checked=True)
        self.board_box.addWidget(self._all_boards_btn)
        outer.addLayout(self.board_box)
        self.board_group.buttonClicked.connect(self._on_board)

        outer.addStretch(1)
        outer.addWidget(self._section("SORT"))
        self.sort_combo = QComboBox()
        for label, value in SORTS:
            self.sort_combo.addItem(label, value)
        self.sort_combo.currentIndexChanged.connect(self._on_sort)
        wrap = QWidget()
        wl = QHBoxLayout(wrap)
        wl.setContentsMargins(12, 2, 12, 4)
        wl.addWidget(self.sort_combo)
        outer.addWidget(wrap)
        return rail

    def _section(self, text):
        lbl = QLabel(text)
        lbl.setObjectName("RailSection")
        return lbl

    def _nav(self, group, text, value, checked=False):
        btn = QPushButton(text)
        btn.setObjectName("NavItem")
        btn.setCheckable(True)
        btn.setChecked(checked)
        btn.setCursor(Qt.PointingHandCursor)
        btn.setProperty("value", value)
        group.addButton(btn)
        return btn

    def _build_thread_pane(self):
        pane = QWidget()
        pane.setObjectName("ThreadPane")
        outer = QVBoxLayout(pane)
        outer.setContentsMargins(0, 0, 0, 0)
        outer.setSpacing(0)

        header = QWidget()
        hl = QHBoxLayout(header)
        hl.setContentsMargins(14, 10, 14, 10)
        self.scope_label = QLabel("Threads")
        self.scope_label.setObjectName("DetailTitle")
        hl.addWidget(self.scope_label)
        hl.addStretch(1)
        outer.addWidget(header)

        self.thread_scroll = QScrollArea()
        self.thread_scroll.setObjectName("ThreadScroll")
        self.thread_scroll.setWidgetResizable(True)
        self.thread_host = QWidget()
        self.thread_host.setObjectName("ThreadPane")
        self.thread_col = QVBoxLayout(self.thread_host)
        self.thread_col.setContentsMargins(0, 0, 0, 0)
        self.thread_col.setSpacing(0)
        self.thread_col.addStretch(1)
        self.thread_scroll.setWidget(self.thread_host)
        outer.addWidget(self.thread_scroll, 1)
        return pane

    # -- data flow -----------------------------------------------------------
    def _get_client(self):
        return self.client

    def refresh_all(self):
        self.client = api.ForumClient(self.config.base_url, self.config.key)
        self.refresh_boards()
        self.refresh_threads()

    def refresh_boards(self):
        run_async(self.client.categories, self._fill_boards, self._fail)

    def _fill_boards(self, data):
        self.categories = data.get("categories", [])
        # Rebuild board nav below the fixed "All boards" entry.
        for btn in list(self.board_group.buttons()):
            if btn is not self._all_boards_btn:
                self.board_group.removeButton(btn)
                btn.deleteLater()
        for c in self.categories:
            btn = self._nav(self.board_group, c.get("name", "?"), int(c.get("id")))
            self.board_box.addWidget(btn)

    def refresh_threads(self):
        self.statusBar().showMessage("Loading threads…")
        run_async(lambda: self.client.threads(cat=self.cat, flag=self.flag,
                                              sort=self.sort, filt=self.filt),
                  self._fill_threads, self._fail)

    def _fill_threads(self, data):
        # Clear existing rows (keep the trailing stretch).
        while self.thread_col.count() > 1:
            item = self.thread_col.takeAt(0)
            w = item.widget()
            if w:
                w.deleteLater()
        self._rows = {}

        threads = data.get("threads", [])
        for th in threads:
            row = ThreadRow(th)
            row.clicked.connect(self._select_thread)
            self.thread_col.insertWidget(self.thread_col.count() - 1, row)
            self._rows[row.thread_id] = row

        total = data.get("total_count", len(threads))
        self.scope_label.setText(self._scope_text(total))
        self.statusBar().showMessage(f"{len(threads)} shown of {total}.")

        if self._selected_id in self._rows:
            self._rows[self._selected_id].set_selected(True)
        elif not threads:
            self.detail.show_placeholder("No threads match this view.")

    def _scope_text(self, total):
        bits = []
        if self.cat:
            name = next((c.get("name") for c in self.categories if int(c.get("id")) == self.cat), None)
            bits.append(name or "Board")
        else:
            bits.append("All boards")
        if self.flag:
            bits.append(self.flag.capitalize())
        if self.filt:
            bits.append(self.filt.capitalize())
        return "  ·  ".join(bits) + f"   ({total})"

    def _select_thread(self, tid):
        if self._selected_id in self._rows:
            self._rows[self._selected_id].set_selected(False)
        self._selected_id = tid
        if tid in self._rows:
            self._rows[tid].set_selected(True)
        self.detail.load(tid)
        # Mark read, then quietly clear the unread dot on next refresh.
        run_async(lambda: self.client.mark_read(tid), lambda _="": None, lambda _m="": None)

    # -- rail handlers -------------------------------------------------------
    def _on_flag(self, btn):
        self.flag = btn.property("value")
        self.refresh_threads()

    def _on_view(self, btn):
        self.filt = btn.property("value")
        self.refresh_threads()

    def _on_board(self, btn):
        self.cat = int(btn.property("value"))
        self.refresh_threads()

    def _on_sort(self):
        self.sort = self.sort_combo.currentData()
        self.refresh_threads()

    # -- toolbar handlers ----------------------------------------------------
    def _open_settings(self):
        dlg = SettingsDialog(self.config, self)
        if dlg.exec():
            self.refresh_all()

    def _open_roles(self):
        if not self.config.is_configured:
            return self._open_settings()
        RolesDialog(self.client, self).exec()

    def _new_board(self):
        dlg = NewBoardDialog(self)
        if not dlg.exec():
            return
        vals = dlg.values()
        run_async(lambda: self.client.create_category(**vals),
                  lambda _: (self.refresh_boards(), self.statusBar().showMessage("Board created.")),
                  self._fail)

    def _new_post(self):
        if not self.categories:
            QMessageBox.information(self, "No boards", "Create a board first.")
            return
        dlg = NewPostDialog(self.categories, self, default_cat=self.cat, default_flag=self.flag or "none")
        if not dlg.exec():
            return
        vals = dlg.values()
        run_async(lambda: self.client.create_thread(**vals),
                  lambda _: (self.refresh_threads(), self.statusBar().showMessage("Posted.")),
                  self._fail)

    def _fail(self, message):
        self.statusBar().showMessage("✗ " + message)


class DetailPanel(QWidget):
    """Right pane: the selected thread, its replies, and operator actions."""

    def __init__(self, get_client, on_changed, parent=None):
        super().__init__(parent)
        self.setObjectName("Detail")
        self._get_client = get_client
        self._on_changed = on_changed
        self._thread = None

        outer = QVBoxLayout(self)
        outer.setContentsMargins(0, 0, 0, 0)
        outer.setSpacing(0)

        self.scroll = QScrollArea()
        self.scroll.setObjectName("DetailScroll")
        self.scroll.setWidgetResizable(True)
        self.host = QWidget()
        self.host.setObjectName("Detail")
        self.col = QVBoxLayout(self.host)
        self.col.setContentsMargins(18, 16, 18, 16)
        self.col.setSpacing(10)
        self.scroll.setWidget(self.host)
        outer.addWidget(self.scroll, 1)

        # Reply composer, hidden until a thread is open.
        self.composer = QWidget()
        cl = QVBoxLayout(self.composer)
        cl.setContentsMargins(14, 8, 14, 12)
        cl.setSpacing(6)
        self.reply_edit = QPlainTextEdit()
        self.reply_edit.setPlaceholderText("Reply as operator…")
        self.reply_edit.setFixedHeight(72)
        cl.addWidget(self.reply_edit)
        send_row = QHBoxLayout()
        send_row.addStretch(1)
        self.send_btn = QPushButton("Send reply")
        self.send_btn.setObjectName("Primary")
        self.send_btn.clicked.connect(self._send_reply)
        send_row.addWidget(self.send_btn)
        cl.addLayout(send_row)
        outer.addWidget(self.composer)
        self.composer.setVisible(False)

        self.show_placeholder("Select a thread to work it.")

    def _client(self):
        return self._get_client()

    def _clear(self):
        while self.col.count():
            item = self.col.takeAt(0)
            w = item.widget()
            if w:
                w.deleteLater()

    def show_placeholder(self, text):
        self._clear()
        self.composer.setVisible(False)
        hint = QLabel(text)
        hint.setObjectName("EmptyHint")
        hint.setAlignment(Qt.AlignCenter)
        self.col.addWidget(hint)
        self.col.addStretch(1)

    def load(self, tid):
        self.show_placeholder("Loading…")
        run_async(lambda: self._client().thread(tid), self._render,
                  lambda m: self.show_placeholder("✗ " + m))

    def _render(self, data):
        self._thread = data.get("thread", {})
        replies = data.get("replies", [])
        t = self._thread
        self._clear()

        title = QLabel(t.get("title", "(untitled)"))
        title.setObjectName("DetailTitle")
        title.setWordWrap(True)
        self.col.addWidget(title)

        author = t.get("display_name") or t.get("author_domain") or "unknown"
        meta = QLabel(f"{author}   ·   {t.get('category_name', '')}   ·   "
                      f"{t.get('view_count', 0)} views   ·   {fmt_when(t.get('created_at'))}")
        meta.setObjectName("DetailMeta")
        self.col.addWidget(meta)

        self.col.addWidget(self._action_row(t))

        body = QLabel(t.get("body", ""))
        body.setObjectName("PostBody")
        body.setWordWrap(True)
        body.setTextInteractionFlags(Qt.TextSelectableByMouse)
        self.col.addWidget(body)

        rlabel = QLabel(f"{len(replies)} REPL{'Y' if len(replies) == 1 else 'IES'}")
        rlabel.setObjectName("RailSection")
        self.col.addWidget(rlabel)

        solved_id = t.get("solved_reply_id")
        for rep in replies:
            self.col.addWidget(self._reply_block(rep, solved_id))

        self.col.addStretch(1)
        self.composer.setVisible(not t.get("is_locked"))
        self.reply_edit.clear()

    def _action_row(self, t):
        wrap = QWidget()
        row = QHBoxLayout(wrap)
        row.setContentsMargins(0, 2, 0, 2)
        row.setSpacing(6)
        tid = int(t.get("id"))

        # Flag chooser
        flag_combo = QComboBox()
        for f in ["none", "chat", "support", "question", "brag"]:
            flag_combo.addItem(f.capitalize() if f != "none" else "No flag", f)
        idx = flag_combo.findData(t.get("flag", "none"))
        if idx >= 0:
            flag_combo.setCurrentIndex(idx)
        flag_combo.currentIndexChanged.connect(
            lambda _i, c=flag_combo, i=tid: self._act(lambda: self._client().patch_thread(i, flag=c.currentData())))
        row.addWidget(QLabel("Flag:"))
        row.addWidget(flag_combo)

        pin = self._btn("Unpin" if t.get("is_pinned") else "Pin", "Ghost",
                        lambda i=tid, v=not t.get("is_pinned"): self._act(lambda: self._client().patch_thread(i, is_pinned=v)))
        lock = self._btn("Unlock" if t.get("is_locked") else "Lock", "Ghost",
                         lambda i=tid, v=not t.get("is_locked"): self._act(lambda: self._client().patch_thread(i, is_locked=v)))
        follow = self._btn("Unfollow" if t.get("is_following") else "Follow", "Ghost",
                           lambda i=tid, f=t.get("is_following"): self._act(
                               (lambda: self._client().unfollow(i)) if f else (lambda: self._client().follow(i))))
        row.addWidget(pin)
        row.addWidget(lock)
        row.addWidget(follow)
        row.addStretch(1)
        delete = self._btn("Delete", "Danger", lambda i=tid: self._confirm_delete_thread(i))
        row.addWidget(delete)
        return wrap

    def _reply_block(self, rep, solved_id):
        wrap = QWidget()
        box = QVBoxLayout(wrap)
        box.setContentsMargins(0, 0, 0, 0)
        box.setSpacing(3)
        box.addWidget(ReplyCard(rep, solved_id))

        row = QHBoxLayout()
        row.setContentsMargins(6, 0, 2, 0)
        row.addStretch(1)
        rid = int(rep.get("id"))
        is_accepted = solved_id is not None and int(rep.get("id", 0)) == int(solved_id)
        accept = self._btn("Un-accept" if is_accepted else "Accept answer", "Ghost",
                           lambda i=rid, a=is_accepted: self._act(
                               (lambda: self._client().unsolve(i)) if a else (lambda: self._client().solve(i))))
        row.addWidget(accept)
        row.addWidget(self._btn("Delete", "Danger", lambda i=rid: self._act(lambda: self._client().delete_reply(i))))
        box.addLayout(row)
        return wrap

    def _btn(self, text, kind, slot):
        b = QPushButton(text)
        b.setObjectName(kind)
        b.setCursor(Qt.PointingHandCursor)
        b.clicked.connect(slot)
        return b

    def _act(self, call):
        """Run a mutating call, then reload this thread and the list."""
        tid = int(self._thread.get("id")) if self._thread else None

        def ok(_):
            if tid is not None:
                self.load(tid)
            self._on_changed()

        run_async(call, ok, lambda m: self.show_error(m))

    def show_error(self, message):
        QMessageBox.warning(self, "Action failed", message)
        if self._thread:
            self.load(int(self._thread.get("id")))

    def _confirm_delete_thread(self, tid):
        if QMessageBox.question(self, "Delete thread",
                                "Hide this thread from the forum? (Reversible in the DB.)") \
                != QMessageBox.Yes:
            return
        def ok(_):
            self.show_placeholder("Thread deleted.")
            self._on_changed()
        run_async(lambda: self._client().delete_thread(tid), ok, lambda m: self.show_error(m))

    def _send_reply(self):
        text = self.reply_edit.toPlainText().strip()
        if not text or not self._thread:
            return
        tid = int(self._thread.get("id"))
        self.send_btn.setEnabled(False)

        def ok(_):
            self.send_btn.setEnabled(True)
            self.load(tid)
            self._on_changed()

        def err(m):
            self.send_btn.setEnabled(True)
            self.show_error(m)

        run_async(lambda: self._client().reply(tid, text), ok, err)

# ===== SNAPSMACK EOF =====
