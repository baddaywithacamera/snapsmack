"""Connection status must come from authenticated reads of the actual endpoints.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys
from pathlib import Path
from unittest.mock import Mock

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import pytest
import requests

import connection_status as status


def test_site_checks_use_distinct_authenticated_endpoints(monkeypatch):
    session = requests.Session()
    get = Mock(side_effect=[
        MockResponse(200, "application/json", payload={"ok": True}),
        MockResponse(200, "application/sql", chunks=[b"-- Export\nCRE", b"ATE TABLE snap_images"]),
    ])
    session.get = get
    connect = Mock(return_value=Mock(session=session))
    monkeypatch.setattr(status.backup_engine, "SnapSmackSession", connect)
    site = "https://example.com"
    rows = status.check_connections({"site_url": site}, {}, [], "fixture-key")
    assert [row.state for row in rows] == [status.CONNECTED, status.CONNECTED]
    assert [call.args[0] for call in get.call_args_list] == [
        site + "/suyb-data.php", site + "/suyb-export.php?type=schema",
    ]
    assert all(call.kwargs["allow_redirects"] is False for call in get.call_args_list)
    assert all(call.kwargs["api_key"] == "fixture-key" for call in connect.call_args_list)


@pytest.mark.parametrize("code, content_type, expected", [
    (401, "application/json", status.SIGN_IN),
    (403, "application/json", status.SIGN_IN),
    (500, "application/json", status.UNREACHABLE),
    (200, "text/html", status.UNREACHABLE),
])
def test_unverified_responses_never_show_connected(monkeypatch, code, content_type, expected):
    session = requests.Session()
    session.get = Mock(return_value=MockResponse(code, content_type))
    monkeypatch.setattr(status.backup_engine, "SnapSmackSession", Mock(return_value=Mock(session=session)))
    rows = status.check_connections({"site_url": "https://example.com"}, {}, [], "fixture-key")
    assert [row.state for row in rows] == [expected, expected]


def test_cloud_destinations_report_separate_results(monkeypatch):
    def check_cloud(provider, job):
        if provider == "google_drive":
            raise status.cloud_client.DriveAuthorizationExpired("expired")
    monkeypatch.setattr(status, "_sync_read", check_cloud)
    jobs = [
        ("Photos", {"dest_provider": "google_drive", "dest_credentials_file": "client.json"}),
        ("Backup archive", {"dest_provider": "backblaze_b2"}),
    ]
    results = status.check_connections({"site_url": "https://example.com"}, {}, jobs, "")
    assert [row.state for row in results] == [status.SIGN_IN] * 3 + [status.CONNECTED]
    assert results[2].fix == ("drive", 6, "client.json", "Photos")


def test_ui_ignores_stale_profile_results_and_distinguishes_active_job(monkeypatch, tmp_path):
    from PySide6.QtWidgets import QApplication, QLabel
    import suyb_qt

    app = QApplication.instance() or QApplication([])
    app.setOrganizationName("SUYB test")
    monkeypatch.setattr(suyb_qt.InAppScheduler, "start", lambda *_: None)
    monkeypatch.setattr(suyb_qt.SuybWindow, "_build_tray", lambda self: None)
    monkeypatch.setattr(suyb_qt.SuybWindow, "_remember_last_profile", staticmethod(lambda _: None))
    monkeypatch.setattr(suyb_qt.profile_manager, "load_profile", lambda name: {
        "name": name, "site_url": "https://example.com", "backup_dir": str(tmp_path),
    })
    window = suyb_qt.SuybWindow()
    window._profile_changed("Found Textures")
    assert window.connection.text() == "Not checked"
    window._show_active_backup("craptasti.ca")
    checked = status.ConnectionResult("Google Drive", status.SIGN_IN, "19:30", ("settings", 9))
    window._test_done(window._connection_generation - 1, [checked])
    assert window.connection_rows.count() == 0
    window._test_done(window._connection_generation, [checked])
    assert window.connection.text() == "Needs sign-in"
    assert window.connection_selection.text() == "Selected site · Found Textures"
    assert window.active_backup_label.text() == "Active backup · craptasti.ca"
    row = window.connection_rows.itemAt(0).widget()
    assert row.findChild(QLabel).text() == "Google Drive · Needs sign-in · Checked 19:30"
    window.deleteLater()
    app.processEvents()


class MockResponse(requests.Response):
    def __init__(self, code, content_type, *, payload=None, chunks=()):
        super().__init__()
        self.status_code = code
        self.headers["Content-Type"] = content_type
        self._content_consumed = True
        self._payload = payload
        self._chunks = chunks

    def json(self):
        return self._payload

    def iter_content(self, _size):
        return iter(self._chunks)


# ===== SNAPSMACK EOF =====
