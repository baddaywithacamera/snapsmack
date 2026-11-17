"""Tests for KEYS PLEASE (SS-1398 & SS-1400)."""

import json
import os
import pathlib
import sys
import tempfile

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from keysplease_core.providers import FakeKeysProvider, vault_entry_name
from keysplease_qt.main_window import MainWindow


def _app():
    return QApplication.instance() or QApplication([])


def test_status_flags_ss1400_delete_guard_and_revoke_then_delete():
    _app()
    with tempfile.TemporaryDirectory(dir=os.path.dirname(__file__)) as td:
        log_file = pathlib.Path(td) / "actions.jsonl"
        vault = {}
        prov = FakeKeysProvider(vault_store=vault)
        win = MainWindow(provider=prov, log_path=str(log_file))

        # Status view loaded without password/TOTP and computed attention flags
        flags_col = [win.table.item(r, 8).text() for r in range(win.table.rowCount())]
        assert any("EXPIRING SOON" in f for f in flags_col)
        assert any("REVOKED BUT STILL IN VAULT" in f for f in flags_col)

        # Select active row 0 -> Delete button MUST be disabled (SS-1400)
        win.table.selectRow(0)
        assert win.preview_revoke_btn.isEnabled()
        assert not win.preview_delete_btn.isEnabled()

        # Revoke row 0 with session auth + typed confirmation
        win.pw_input.setText("admin-pass")
        win.totp_input.setText("123456")
        win.preview_batch("revoke")
        win.confirm_input.setText("REVOKE 1 KEYS")
        assert win.execute_btn.isEnabled()
        assert win.execute_batch() == 1

        # Now select revoked key (row 2 or whichever is REVOKED) -> Delete enabled
        revoked_rows = [r for r in range(win.table.rowCount()) if win.table.item(r, 7).text() == "REVOKED"]
        assert revoked_rows
        win.table.selectRow(revoked_rows[0])
        assert win.preview_delete_btn.isEnabled()
        win.preview_batch("delete")
        win.confirm_input.setText("DELETE 1 KEYS")
        assert win.execute_batch() == 1

        # Verify append-only log contains actions and NO secrets
        text = log_file.read_text(encoding="utf-8")
        lines = [json.loads(line) for line in text.strip().splitlines()]
        assert [l["action"] for l in lines] == ["revoke", "delete"]
        assert "admin-pass" not in text and "123456" not in text


def test_create_key_writes_straight_to_vault_and_never_displays_raw_key():
    _app()
    with tempfile.TemporaryDirectory(dir=os.path.dirname(__file__)) as td:
        log_file = pathlib.Path(td) / "actions.jsonl"
        vault = {}
        prov = FakeKeysProvider(vault_store=vault)
        win = MainWindow(provider=prov, log_path=str(log_file))

        win.pw_input.setText("admin-pass")
        win.totp_input.setText("654321")
        win.create_site_edit.setText("https://newblog.example")
        win.create_type_combo.setCurrentText("suyb")
        prefix = win.create_key_to_vault()
        assert len(prefix) == 8

        raw_key = vault[vault_entry_name("suyb", "https://newblog.example")]
        assert len(raw_key) == 64
        # Raw key must NEVER appear on screen or in the log
        assert raw_key not in win.status_lbl.text()
        assert raw_key not in log_file.read_text(encoding="utf-8")

        # Closing clears session auth
        win.close()
        assert win.pw_input.text() == ""
        assert win.totp_input.text() == ""


# ===== SNAPSMACK EOF =====
