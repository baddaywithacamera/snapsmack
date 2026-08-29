"""Modal dialogs: connection settings, new board, new post, blogs & roles."""

from PySide6.QtCore import Qt
from PySide6.QtWidgets import (
    QDialog, QVBoxLayout, QHBoxLayout, QFormLayout, QLabel, QLineEdit, QComboBox,
    QSpinBox, QPlainTextEdit, QPushButton, QDialogButtonBox, QTableWidget,
    QTableWidgetItem, QHeaderView, QMessageBox, QWidget,
)

from . import api
from .config import Config, DEFAULT_BASE
from .worker import run_async


class SettingsDialog(QDialog):
    """Forum base URL + operator key, with a live Test button."""

    def __init__(self, config: Config, parent=None):
        super().__init__(parent)
        self.config = config
        self.setWindowTitle("BOARD LORD — Connection")
        self.setMinimumWidth(460)

        outer = QVBoxLayout(self)
        outer.setContentsMargins(18, 18, 18, 16)
        outer.setSpacing(12)

        intro = QLabel("Point BOARD LORD at the forum and paste your operator key "
                       "(the God key for full power, or an admin blog's key).")
        intro.setWordWrap(True)
        intro.setObjectName("DetailMeta")
        outer.addWidget(intro)

        form = QFormLayout()
        form.setSpacing(9)
        self.base_edit = QLineEdit(config.base_url or DEFAULT_BASE)
        self.key_edit = QLineEdit(config.key)
        self.key_edit.setEchoMode(QLineEdit.Password)
        self.key_edit.setPlaceholderText("Bearer key — never leaves this machine")
        form.addRow("Forum URL", self.base_edit)
        form.addRow("Operator key", self.key_edit)
        outer.addLayout(form)

        self.status = QLabel("")
        self.status.setObjectName("DetailMeta")
        self.status.setWordWrap(True)
        outer.addWidget(self.status)

        row = QHBoxLayout()
        self.test_btn = QPushButton("Test connection")
        self.test_btn.setObjectName("Ghost")
        self.test_btn.clicked.connect(self._test)
        row.addWidget(self.test_btn)
        row.addStretch(1)
        buttons = QDialogButtonBox(QDialogButtonBox.Save | QDialogButtonBox.Cancel)
        buttons.button(QDialogButtonBox.Save).setObjectName("Primary")
        buttons.accepted.connect(self._save)
        buttons.rejected.connect(self.reject)
        row.addWidget(buttons)
        outer.addLayout(row)

    def _client(self):
        return api.ForumClient(self.base_edit.text().strip(), self.key_edit.text().strip())

    def _test(self):
        self.status.setText("Testing…")
        self.test_btn.setEnabled(False)
        client = self._client()

        def ok(_):
            self.status.setText("✓ Connected — key accepted.")
            self.test_btn.setEnabled(True)

        def err(msg):
            self.status.setText("✗ " + msg)
            self.test_btn.setEnabled(True)

        run_async(client.ping, ok, err)

    def _save(self):
        self.config.base_url = self.base_edit.text().strip()
        self.config.key = self.key_edit.text().strip()
        self.accept()


class NewBoardDialog(QDialog):
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("New Board")
        self.setMinimumWidth(440)
        outer = QVBoxLayout(self)
        outer.setContentsMargins(18, 18, 18, 16)
        outer.setSpacing(10)

        form = QFormLayout()
        self.name = QLineEdit()
        self.name.setPlaceholderText("e.g. Show & Tell")
        self.desc = QLineEdit()
        self.desc.setPlaceholderText("One-line description (optional)")
        self.sort = QSpinBox()
        self.sort.setRange(0, 999)
        form.addRow("Name", self.name)
        form.addRow("Description", self.desc)
        form.addRow("Sort order", self.sort)
        outer.addLayout(form)

        buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
        buttons.button(QDialogButtonBox.Ok).setText("Create board")
        buttons.button(QDialogButtonBox.Ok).setObjectName("Primary")
        buttons.accepted.connect(self._accept)
        buttons.rejected.connect(self.reject)
        outer.addWidget(buttons)

    def _accept(self):
        if not self.name.text().strip():
            QMessageBox.warning(self, "Name required", "A board needs a name.")
            return
        self.accept()

    def values(self):
        return {"name": self.name.text().strip(),
                "description": self.desc.text().strip(),
                "sort_order": self.sort.value()}


class NewPostDialog(QDialog):
    def __init__(self, categories, parent=None, default_cat=0, default_flag="none"):
        super().__init__(parent)
        self.setWindowTitle("New Post")
        self.setMinimumWidth(520)
        outer = QVBoxLayout(self)
        outer.setContentsMargins(18, 18, 18, 16)
        outer.setSpacing(10)

        form = QFormLayout()
        self.cat = QComboBox()
        for c in categories:
            self.cat.addItem(c.get("name", "?"), int(c.get("id")))
            if int(c.get("id")) == default_cat:
                self.cat.setCurrentIndex(self.cat.count() - 1)
        self.flag = QComboBox()
        for f in ["none", "chat", "support", "question", "brag"]:
            self.flag.addItem(f.capitalize() if f != "none" else "No flag", f)
        idx = self.flag.findData(default_flag)
        if idx >= 0:
            self.flag.setCurrentIndex(idx)
        self.title = QLineEdit()
        form.addRow("Board", self.cat)
        form.addRow("Flag", self.flag)
        form.addRow("Title", self.title)
        outer.addLayout(form)

        outer.addWidget(QLabel("Body"))
        self.body = QPlainTextEdit()
        self.body.setMinimumHeight(160)
        outer.addWidget(self.body)

        buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
        buttons.button(QDialogButtonBox.Ok).setText("Post")
        buttons.button(QDialogButtonBox.Ok).setObjectName("Primary")
        buttons.accepted.connect(self._accept)
        buttons.rejected.connect(self.reject)
        outer.addWidget(buttons)

    def _accept(self):
        if not self.title.text().strip() or not self.body.toPlainText().strip():
            QMessageBox.warning(self, "Missing fields", "A post needs a title and a body.")
            return
        if self.cat.currentData() is None:
            QMessageBox.warning(self, "No board", "Create a board first.")
            return
        self.accept()

    def values(self):
        return {"category_id": int(self.cat.currentData()),
                "title": self.title.text().strip(),
                "body": self.body.toPlainText().strip(),
                "flag": self.flag.currentData()}


class RolesDialog(QDialog):
    """Blogs & Roles — promote/demote every registered blog. Needs God/admin key."""

    def __init__(self, client: api.ForumClient, parent=None):
        super().__init__(parent)
        self.client = client
        self.setWindowTitle("Blogs & Roles")
        self.resize(720, 460)
        outer = QVBoxLayout(self)
        outer.setContentsMargins(16, 16, 16, 14)
        outer.setSpacing(10)

        hint = QLabel("Set each blog's rank. You can only assign a rank below your own; "
                      "the God key (Smack Central) can make a blog Admin.")
        hint.setObjectName("DetailMeta")
        hint.setWordWrap(True)
        outer.addWidget(hint)

        self.table = QTableWidget(0, 4)
        self.table.setHorizontalHeaderLabels(["Blog", "Domain", "Last seen", "Rank"])
        self.table.verticalHeader().setVisible(False)
        self.table.setEditTriggers(QTableWidget.NoEditTriggers)
        self.table.setSelectionBehavior(QTableWidget.SelectRows)
        head = self.table.horizontalHeader()
        head.setSectionResizeMode(0, QHeaderView.Stretch)
        head.setSectionResizeMode(1, QHeaderView.Stretch)
        head.setSectionResizeMode(2, QHeaderView.ResizeToContents)
        head.setSectionResizeMode(3, QHeaderView.ResizeToContents)
        outer.addWidget(self.table, 1)

        self.status = QLabel("Loading blogs…")
        self.status.setObjectName("DetailMeta")
        outer.addWidget(self.status)

        close = QPushButton("Done")
        close.setObjectName("Ghost")
        close.clicked.connect(self.accept)
        bottom = QHBoxLayout()
        bottom.addStretch(1)
        bottom.addWidget(close)
        outer.addLayout(bottom)

        self._reload()

    def _reload(self):
        self.status.setText("Loading blogs…")
        run_async(self.client.installs, self._fill, lambda m: self.status.setText("✗ " + m))

    def _fill(self, data):
        rows = data.get("installs", [])
        self.table.setRowCount(0)
        for inst in rows:
            r = self.table.rowCount()
            self.table.insertRow(r)
            self.table.setItem(r, 0, QTableWidgetItem(inst.get("display_name") or "—"))
            self.table.setItem(r, 1, QTableWidgetItem(inst.get("domain") or "—"))
            self.table.setItem(r, 2, QTableWidgetItem(str(inst.get("last_seen_at") or "")))
            combo = QComboBox()
            for value, label in sorted(api.ROLES.items()):
                combo.addItem(label, value)
            cur = int(inst.get("role", 0))
            ci = combo.findData(cur)
            if ci >= 0:
                combo.setCurrentIndex(ci)
            iid = int(inst.get("id"))
            combo.currentIndexChanged.connect(
                lambda _idx, c=combo, i=iid: self._set_role(i, c))
            self.table.setCellWidget(r, 3, combo)
        self.status.setText(f"{len(rows)} blog(s).")

    def _set_role(self, install_id, combo):
        role = int(combo.currentData())
        combo.setEnabled(False)
        self.status.setText("Updating rank…")

        def ok(_):
            combo.setEnabled(True)
            self.status.setText("✓ Rank updated.")

        def err(msg):
            combo.setEnabled(True)
            self.status.setText("✗ " + msg)
            self._reload()  # revert to server truth

        run_async(lambda: self.client.set_role(install_id, role), ok, err)

# ===== SNAPSMACK EOF =====
