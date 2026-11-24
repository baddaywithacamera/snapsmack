"""INSTANT REPLAY main window — desktop-side test-restore & verification (SS-1363)."""

from PySide6.QtCore import Qt
from PySide6.QtGui import QFontDatabase, QKeySequence
from PySide6.QtWidgets import (
    QComboBox, QFrame, QHBoxLayout, QHeaderView, QLabel, QLineEdit,
    QMainWindow, QPushButton, QTableWidget, QTableWidgetItem, QTextEdit,
    QVBoxLayout, QWidget,
)

from _version import BUILD_VERSION
from replay_core.models import BackupPackage, ReplayChallenge, TargetTestBlog, format_bytes
from replay_core.providers import FakeBackupCatalog, FakeTestBlogTarget
from replay_core.verifier import verify_restored_blog


class NoPasteLineEdit(QLineEdit):
    """Challenge input that blocks paste so the operator must read and type."""

    def __init__(self, parent=None):
        super().__init__(parent)
        self.setContextMenuPolicy(Qt.NoContextMenu)

    def paste(self):  # noqa: D401
        """Blocked by design (SS-1363 intent check)."""
        return

    def keyPressEvent(self, event):
        if event.matches(QKeySequence.Paste) or (
            (event.modifiers() & Qt.ShiftModifier) and event.key() == Qt.Key_Insert
        ):
            event.ignore()
            return
        super().keyPressEvent(event)


class MainWindow(QMainWindow):
    """Pick a backup, replay onto a designated TEST BLOG, and emit PASS/FAIL report."""

    def __init__(self, catalog=None, target_provider=None):
        super().__init__()
        self.setWindowTitle(f"INSTANT REPLAY — build {BUILD_VERSION}")
        self.resize(1120, 780)
        self.setMinimumSize(900, 620)

        self._catalog = catalog or FakeBackupCatalog()
        self._target_provider = target_provider or FakeTestBlogTarget()
        self._backups: list[BackupPackage] = []
        self._targets: list[TargetTestBlog] = []
        self._challenge: ReplayChallenge | None = None

        central = QWidget()
        root = QVBoxLayout(central)
        root.setContentsMargins(0, 0, 0, 0)
        root.setSpacing(0)

        # Header
        header = QFrame()
        header.setObjectName("HeaderBar")
        h_row = QHBoxLayout(header)
        h_row.setContentsMargins(16, 10, 16, 10)
        h_row.setSpacing(12)
        title = QLabel("INSTANT REPLAY")
        title.setObjectName("AppTitle")
        h_row.addWidget(title)
        reminder = QLabel("Reminder: periodically test-restore a backup onto a designated TEST BLOG.")
        reminder.setObjectName("Muted")
        h_row.addWidget(reminder, 1)
        h_row.addWidget(QLabel("Target:"))
        self.target_combo = QComboBox()
        self.target_combo.currentIndexChanged.connect(self._sync_summary)
        h_row.addWidget(self.target_combo)
        self.help_btn = QPushButton("Help (F1)")
        h_row.addWidget(self.help_btn)
        root.addWidget(header)

        shared_dir = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "..", "_shared"))
        if os.path.isdir(shared_dir) and shared_dir not in sys.path:
            sys.path.insert(0, shared_dir)
        import snap_help
        self._help_shortcut, _open_help = snap_help.bind_f1_qt(
            self, snap_help.TOOL_HELP_TOPICS["instantreplay"], title="INSTANT REPLAY — Help"
        )
        self.help_btn.clicked.connect(_open_help)

        # Backup picker table
        self.backup_table = QTableWidget(0, 6)
        self.backup_table.setHorizontalHeaderLabels([
            "Backup ID", "Source Blog", "Date", "Size", "Posts / Photos", "Skin",
        ])
        self.backup_table.setEditTriggers(QTableWidget.NoEditTriggers)
        self.backup_table.setSelectionBehavior(QTableWidget.SelectRows)
        self.backup_table.setSelectionMode(QTableWidget.SingleSelection)
        self.backup_table.verticalHeader().setVisible(False)
        hdr = self.backup_table.horizontalHeader()
        hdr.setSectionResizeMode(QHeaderView.ResizeToContents)
        hdr.setSectionResizeMode(0, QHeaderView.Stretch)
        self.backup_table.itemSelectionChanged.connect(self._sync_summary)
        root.addWidget(self.backup_table, 1)

        # Confirmation + Challenge panel
        confirm = QFrame()
        confirm.setObjectName("SummaryBar")
        c_col = QVBoxLayout(confirm)
        c_col.setContentsMargins(16, 12, 16, 12)
        c_col.setSpacing(8)

        self.blast_lbl = QLabel("Select a backup and a designated TEST BLOG target.")
        self.blast_lbl.setWordWrap(True)
        c_col.addWidget(self.blast_lbl)

        ch_row = QHBoxLayout()
        self.req_challenge_btn = QPushButton("Request Challenge")
        self.req_challenge_btn.setObjectName("Primary")
        self.req_challenge_btn.clicked.connect(self.request_challenge)
        ch_row.addWidget(self.req_challenge_btn)

        self.challenge_lbl = QLabel("— no active challenge —")
        self.challenge_lbl.setObjectName("ChallengeText")
        mono = QFontDatabase.systemFont(QFontDatabase.FixedFont)
        mono.setPointSize(16)
        mono.setBold(True)
        self.challenge_lbl.setFont(mono)
        ch_row.addWidget(self.challenge_lbl, 1)
        c_col.addLayout(ch_row)

        act_row = QHBoxLayout()
        act_row.addWidget(QLabel("Type challenge (paste blocked):"))
        self.challenge_input = NoPasteLineEdit()
        self.challenge_input.setFont(mono)
        self.challenge_input.setPlaceholderText("Exact case-sensitive challenge")
        act_row.addWidget(self.challenge_input, 1)

        act_row.addWidget(QLabel("TOTP:"))
        self.totp_input = QLineEdit()
        self.totp_input.setMaxLength(6)
        self.totp_input.setMaximumWidth(100)
        self.totp_input.setPlaceholderText("123456")
        act_row.addWidget(self.totp_input)

        self.execute_btn = QPushButton("Execute Instant Replay")
        self.execute_btn.setObjectName("Danger")
        self.execute_btn.clicked.connect(self.execute_replay)
        act_row.addWidget(self.execute_btn)
        c_col.addLayout(act_row)

        root.addWidget(confirm)

        # PASS/FAIL report box
        self.report_box = QTextEdit()
        self.report_box.setReadOnly(True)
        self.report_box.setMaximumHeight(180)
        self.report_box.setPlaceholderText("Verification PASS/FAIL report will appear here.")
        root.addWidget(self.report_box)

        self.setCentralWidget(central)
        self.refresh_catalog()

    def refresh_catalog(self):
        self._backups = self._catalog.list_backups()
        self.backup_table.setRowCount(len(self._backups))
        for row, b in enumerate(self._backups):
            vals = (
                b.backup_id,
                b.blog_name,
                b.date_iso,
                format_bytes(b.size_bytes),
                f"{b.posts_count} / {b.photos_count}",
                b.skin_name,
            )
            for col, text in enumerate(vals):
                self.backup_table.setItem(row, col, QTableWidgetItem(text))
        if self._backups:
            self.backup_table.selectRow(0)

        self._targets = self._target_provider.list_targets()
        self.target_combo.blockSignals(True)
        self.target_combo.clear()
        for idx, t in enumerate(self._targets):
            tag = "[TEST BLOG]" if t.is_test_blog else "[PRODUCTION — REFUSED]"
            self.target_combo.addItem(f"{t.blog_name} ({t.url}) {tag}", idx)
        self.target_combo.blockSignals(False)
        self._sync_summary()

    def selected_backup(self) -> BackupPackage | None:
        row = self.backup_table.currentRow()
        if 0 <= row < len(self._backups):
            return self._backups[row]
        return None

    def selected_target(self) -> TargetTestBlog | None:
        idx = self.target_combo.currentData()
        if idx is not None and 0 <= int(idx) < len(self._targets):
            return self._targets[int(idx)]
        return None

    def _sync_summary(self):
        self._challenge = None
        self.challenge_lbl.setText("— no active challenge —")
        b = self.selected_backup()
        t = self.selected_target()
        if b is None or t is None:
            self.blast_lbl.setText("Select a backup and a designated TEST BLOG target.")
            return
        self.blast_lbl.setText(
            f"Target: {t.blog_name} ({t.url}) · Currently holds {t.current_posts} posts / "
            f"{t.current_images} images · Will be overwritten with {b.backup_id} "
            f"({b.date_iso}, {format_bytes(b.size_bytes)})."
        )

    def request_challenge(self) -> ReplayChallenge | None:
        b = self.selected_backup()
        t = self.selected_target()
        if b is None or t is None:
            return None
        try:
            self._challenge = self._target_provider.request_challenge(t, b)
            self.challenge_lbl.setText(self._challenge.phrase)
            self.challenge_input.clear()
            return self._challenge
        except Exception as exc:  # noqa: BLE001
            self._challenge = None
            self.challenge_lbl.setText(f"REFUSED: {exc}")
            self.report_box.setPlainText(f"FAIL — {exc}")
            return None

    def execute_replay(self) -> str:
        b = self.selected_backup()
        t = self.selected_target()
        if b is None or t is None or self._challenge is None:
            self.report_box.setPlainText("FAIL — Request a challenge for a TEST BLOG target first.")
            return "FAIL"
        try:
            snapshot = self._target_provider.execute_replay(
                t,
                b,
                self._challenge.request_id,
                self.challenge_input.text(),
                self.totp_input.text(),
            )
        except Exception as exc:  # noqa: BLE001
            self.report_box.setPlainText(f"FAIL — Replay refused: {exc}")
            return "FAIL"

        res = verify_restored_blog(b, snapshot)
        lines = [res.summary, ""]
        for chk in res.checks:
            mark = "PASS" if chk.passed else "FAIL"
            lines.append(f"[{mark}] {chk.name}: expected {chk.expected}, got {chk.actual}")
        self.report_box.setPlainText("\n".join(lines))
        return res.verdict


# ===== SNAPSMACK EOF =====
