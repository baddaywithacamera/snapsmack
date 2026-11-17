"""DRIVE BY Slice (a) tests: filename parsing, fake providers, totals, read-only shell."""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtCore import Qt
from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from driveby_core.models import format_bytes, parse_suyb_filename, summarize_inventory
from driveby_core.providers import (
    FakeB2Provider, FakeDriveProvider, is_likely_b2_master_key,
)
from driveby_qt.main_window import MainWindow


def _app():
    return QApplication.instance() or QApplication([])


def test_parse_suyb_filename_and_totals():
    p = parse_suyb_filename("baddaywithacamera_full_2026-11-16.zip")
    assert p["blog"] == "baddaywithacamera"
    assert p["backup_type"] == "full"
    assert p["date_iso"] == "2026-11-16"

    drive = FakeDriveProvider().list_backups()
    b2 = FakeB2Provider().list_backups()
    summary = summarize_inventory(drive + b2)
    assert summary.total_count == len(drive) + len(b2)
    assert summary.counts_by_provider["google_drive"] == len(drive)
    assert summary.counts_by_provider["b2"] == len(b2)
    assert "baddaywithacamera" in summary.by_blog
    assert format_bytes(summary.total_bytes).endswith("GB")


def test_b2_master_key_detection():
    assert is_likely_b2_master_key("004abc123456")
    assert not is_likely_b2_master_key("K004abcdef1234567890")


def test_mainwindow_starts_read_only_and_populates_inventory():
    app = _app()
    win = MainWindow()
    win.setAttribute(Qt.WA_DontShowOnScreen, True)
    win.show()
    app.processEvents()

    assert win.is_read_only() is True
    assert win.mode_badge.text() == "READ-ONLY INVENTORY"
    assert win.table.rowCount() == 14

    # Filter to Backblaze B2 only
    idx = win.provider_filter.findData("b2")
    win.provider_filter.setCurrentIndex(idx)
    app.processEvents()
    assert win.table.rowCount() == 6
    win.close()


# ===== SNAPSMACK EOF =====
