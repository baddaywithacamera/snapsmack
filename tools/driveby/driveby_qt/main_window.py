"""DRIVE BY main window — Read-only backup inventory by default (SS-1364)."""

from PySide6.QtCore import Qt
from PySide6.QtWidgets import (
    QComboBox, QFrame, QHBoxLayout, QHeaderView, QLabel, QMainWindow,
    QPushButton, QTableWidget, QTableWidgetItem, QVBoxLayout, QWidget,
)

from _version import BUILD_VERSION
from driveby_core.models import BackupRecord, format_bytes, summarize_inventory
from driveby_core.providers import FakeB2Provider, FakeDriveProvider, PROVIDER_LABELS


class MainWindow(QMainWindow):
    """Backup inventory and safe pruning shell. Starts strictly read-only."""

    def __init__(self, providers=None):
        super().__init__()
        self.setWindowTitle(f"DRIVE BY — build {BUILD_VERSION}")
        self.resize(1240, 780)
        self.setMinimumSize(960, 620)

        self._read_only = True
        self._providers = providers or [FakeDriveProvider(), FakeB2Provider()]
        self._all_records: list[BackupRecord] = []

        central = QWidget()
        root = QVBoxLayout(central)
        root.setContentsMargins(0, 0, 0, 0)
        root.setSpacing(0)

        # Header bar
        header = QFrame()
        header.setObjectName("HeaderBar")
        h_row = QHBoxLayout(header)
        h_row.setContentsMargins(16, 10, 16, 10)
        h_row.setSpacing(10)

        title = QLabel("DRIVE BY")
        title.setObjectName("AppTitle")
        h_row.addWidget(title)

        self.mode_badge = QLabel("READ-ONLY INVENTORY")
        self.mode_badge.setObjectName("ReadOnlyBadge")
        h_row.addWidget(self.mode_badge)

        self.grant_lbl = QLabel("Fixtures (offline) · Drive grant: unlinked")
        self.grant_lbl.setObjectName("Muted")
        h_row.addWidget(self.grant_lbl)
        h_row.addStretch(1)

        h_row.addWidget(QLabel("Provider:"))
        self.provider_filter = QComboBox()
        self.provider_filter.addItem("All providers", "all")
        self.provider_filter.addItem("Google Drive", "google_drive")
        self.provider_filter.addItem("Backblaze B2", "b2")
        self.provider_filter.currentIndexChanged.connect(self._apply_filters)
        h_row.addWidget(self.provider_filter)

        h_row.addWidget(QLabel("Blog:"))
        self.blog_filter = QComboBox()
        self.blog_filter.addItem("All blogs", "all")
        self.blog_filter.currentIndexChanged.connect(self._apply_filters)
        h_row.addWidget(self.blog_filter)

        self.refresh_btn = QPushButton("Refresh inventory")
        self.refresh_btn.setObjectName("Primary")
        self.refresh_btn.clicked.connect(self.refresh_inventory)
        h_row.addWidget(self.refresh_btn)
        root.addWidget(header)

        # Totals summary bar
        summary = QFrame()
        summary.setObjectName("SummaryBar")
        s_row = QHBoxLayout(summary)
        s_row.setContentsMargins(16, 8, 16, 8)
        s_row.setSpacing(18)
        self.total_lbl = QLabel("Total: 0 B")
        self.total_lbl.setObjectName("SummaryStat")
        self.drive_lbl = QLabel("Google Drive: 0 B")
        self.b2_lbl = QLabel("Backblaze B2: 0 B")
        self.blogs_lbl = QLabel("Blogs: —")
        self.blogs_lbl.setObjectName("Muted")
        s_row.addWidget(self.total_lbl)
        s_row.addWidget(self.drive_lbl)
        s_row.addWidget(self.b2_lbl)
        s_row.addWidget(self.blogs_lbl, 1)
        root.addWidget(summary)

        # Inventory table
        self.table = QTableWidget(0, 8)
        self.table.setHorizontalHeaderLabels([
            "Blog", "Provider", "Date", "Type", "Size",
            "Verified", "Replayed (SS-1363)", "Filename",
        ])
        self.table.setEditTriggers(QTableWidget.NoEditTriggers)
        self.table.setSelectionBehavior(QTableWidget.SelectRows)
        self.table.setSortingEnabled(True)
        self.table.verticalHeader().setVisible(False)
        hdr = self.table.horizontalHeader()
        hdr.setSectionResizeMode(QHeaderView.ResizeToContents)
        hdr.setSectionResizeMode(7, QHeaderView.Stretch)
        root.addWidget(self.table, 1)

        self.setCentralWidget(central)
        self.refresh_inventory()

    def is_read_only(self) -> bool:
        return self._read_only

    def refresh_inventory(self):
        records = []
        for provider in self._providers:
            records.extend(provider.list_backups())
        records.sort(key=lambda r: (r.date_iso, r.blog, r.provider), reverse=True)
        self._all_records = records

        current_blog = self.blog_filter.currentData()
        self.blog_filter.blockSignals(True)
        self.blog_filter.clear()
        self.blog_filter.addItem("All blogs", "all")
        for blog in sorted({r.blog for r in records}):
            self.blog_filter.addItem(blog, blog)
        idx = self.blog_filter.findData(current_blog)
        self.blog_filter.setCurrentIndex(idx if idx >= 0 else 0)
        self.blog_filter.blockSignals(False)

        self._update_summary(records)
        self._apply_filters()

    def _update_summary(self, records):
        s = summarize_inventory(records)
        self.total_lbl.setText(f"Total: {format_bytes(s.total_bytes)} ({s.total_count} backups)")
        self.drive_lbl.setText(
            f"Google Drive: {format_bytes(s.by_provider.get('google_drive', 0))} "
            f"({s.counts_by_provider.get('google_drive', 0)})")
        self.b2_lbl.setText(
            f"Backblaze B2: {format_bytes(s.by_provider.get('b2', 0))} "
            f"({s.counts_by_provider.get('b2', 0)})")
        blog_parts = [f"{b}: {format_bytes(sz)}" for b, sz in sorted(s.by_blog.items())]
        self.blogs_lbl.setText("Per blog: " + "  |  ".join(blog_parts) if blog_parts else "Blogs: —")

    def _apply_filters(self):
        prov = self.provider_filter.currentData() or "all"
        blog = self.blog_filter.currentData() or "all"
        filtered = [
            r for r in self._all_records
            if (prov == "all" or r.provider == prov)
            and (blog == "all" or r.blog == blog)
        ]
        self.table.setSortingEnabled(False)
        self.table.setRowCount(len(filtered))
        for row, rec in enumerate(filtered):
            vals = (
                rec.blog,
                PROVIDER_LABELS.get(rec.provider, rec.provider),
                rec.date_iso or "—",
                rec.backup_type,
                format_bytes(rec.size_bytes),
                "Yes" if rec.verified else "No",
                "Yes" if rec.replayed else "—",
                rec.filename,
            )
            for col, text in enumerate(vals):
                item = QTableWidgetItem(text)
                if col == 4:
                    item.setTextAlignment(Qt.AlignRight | Qt.AlignVCenter)
                self.table.setItem(row, col, item)
        self.table.setSortingEnabled(True)


# ===== SNAPSMACK EOF =====
